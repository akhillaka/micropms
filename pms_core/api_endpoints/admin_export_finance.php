<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/AuthHelper.php';

AuthHelper::requirePermission('export_finance');

require_once __DIR__ . '/../../pms_core/Database.php';
$db = Database::getInstance()->getConnection();

$start = $_GET['start'] ?? date('Y-m-d 00:00:00');
$end = $_GET['end'] ?? date('Y-m-d 23:59:59');

$query = "
    SELECT 
        recorded_at AS `Date`,
        'Room Charge' AS `Type`,
        'Room Booking' AS `Category`,
        CONCAT('Folio #', booking_id, ' — ', COALESCE(description, '')) AS `Description`,
        COALESCE(transaction_ref, booking_id) AS `Reference ID`,
        ABS(amount) AS `Amount (INR)`
    FROM folio_ledger
    WHERE amount > 0
      AND recorded_at BETWEEN :start1 AND :end1
    
    UNION ALL
    
    SELECT 
        recorded_at AS `Date`,
        type AS `Type`,
        CASE 
            WHEN category = 'booking' OR booking_id IS NOT NULL OR description LIKE '%Receipt%' OR description LIKE '%Payment%' THEN 'Room Received Payment'
            ELSE category 
        END AS `Category`,
        description AS `Description`,
        id AS `Reference ID`,
        amount AS `Amount (INR)`
    FROM finance_transactions
    WHERE recorded_at BETWEEN :start2 AND :end2
    
    ORDER BY `Date` DESC
";

$stmt = $db->prepare($query);
$stmt->execute([
    'start1' => $start, 'end1' => $end,
    'start2' => $start, 'end2' => $end
]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = "MicroPMS_Finance_" . date('Y-m-d', strtotime($start)) . "_to_" . date('Y-m-d', strtotime($end)) . ".csv";

header("Content-Type: text/csv");
header("Content-Disposition: attachment; filename=\"$filename\"");

$output = fopen("php://output", "w");

// Write headers
if (count($transactions) > 0) {
    fputcsv($output, array_keys($transactions[0]));
    
    foreach ($transactions as $row) {
        // Excel often gets confused by mixed data types. Let's ensure amounts are pure numbers.
        if ($row['Type'] === 'expense') {
            $row['Amount (INR)'] = '-' . $row['Amount (INR)'];
        }
        fputcsv($output, $row);
    }
} else {
    fputcsv($output, ["No transactions found for this date range."]);
}

fclose($output);
exit;
