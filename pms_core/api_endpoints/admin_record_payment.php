<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../../pms_core/services/FolioService.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('record_payment');
    
    $data = json_decode(file_get_contents('php://input'), true);
    $bookingId = (int)($data['booking_id'] ?? 0);
    $amount = floatval($data['amount'] ?? 0);
    // Accept both 'method' and 'payment_method' for backward compatibility
    $method = $data['payment_method'] ?? $data['method'] ?? 'cash';
    $ref = $data['payment_ref'] ?? $data['ref'] ?? '';
    
    if (!$bookingId || $amount == 0) {
        ApiResponse::error('Invalid input');
    }

    // Validate booking exists and is not cancelled
    $propertyId = AuthHelper::getPropertyId();
    $bStmt = $db->prepare("SELECT b.*, r.room_number, g.name as guest_name 
        FROM bookings b 
        JOIN rooms r ON b.room_id = r.id 
        LEFT JOIN guests g ON b.guest_id = g.id 
        WHERE b.id = :id AND b.payment_status != 'cancelled' AND b.property_id = :pid");
    $bStmt->execute(['id' => $bookingId, 'pid' => $propertyId]);
    $booking = $bStmt->fetch();
    
    if (!$booking) {
        ApiResponse::error('Booking not found or cancelled');
    }
    
    // Validate City Ledger
    if (strtoupper($method) === 'CITY_LEDGER') {
        $companyId = $booking['company_id'] ?? (int)($data['company_id'] ?? 0);
        if (!$companyId) {
            ApiResponse::error('A Corporate Company must be selected to route to City Ledger');
        }
        
        // Link it to the booking if not already linked
        if (!$booking['company_id']) {
            $db->prepare("UPDATE bookings SET company_id = ? WHERE id = ? AND property_id = ?")->execute([$companyId, $bookingId, $propertyId]);
            $booking['company_id'] = $companyId;
        }
    }

    $recordedAt = !empty($data['date']) ? $data['date'] : null;
    if ($recordedAt) {
        // Ensure valid date format, append current time if only date is provided
        if (strlen($recordedAt) === 10) {
            $recordedAt .= ' ' . date('H:i:s');
        }
    }

    // Auto-capture Razorpay payment if applicable
    if ($method === 'online' && str_starts_with($ref, 'pay_')) {
        require_once __DIR__ . '/../../pms_core/config.php';
        $keyId = defined('RAZORPAY_KEY_ID') ? RAZORPAY_KEY_ID : '';
        $keySecret = defined('RAZORPAY_KEY_SECRET') ? RAZORPAY_KEY_SECRET : '';
        
        if (!empty($keyId) && !empty($keySecret)) {
            $ch = curl_init("https://api.razorpay.com/v1/payments/{$ref}/capture");
            curl_setopt($ch, CURLOPT_USERPWD, $keyId . ':' . $keySecret);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'amount' => round($amount * 100),
                'currency' => 'INR'
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            // Log Razorpay capture attempt
            if ($httpcode < 200 || $httpcode >= 300) {
                AuditLogger::log($_SESSION['user_id'], 'RAZORPAY_CAPTURE_FAILED', 'FOLIO', $bookingId, [
                    'http_code' => $httpcode,
                    'response' => $response,
                    'error' => $curlError
                ]);
            }
        }
    }

    $splits = $data['splits'] ?? [];
    $receiptDisplayId = '';

    if (!empty($splits)) {
        // Validate total split amount matches payment amount
        $splitSum = 0;
        foreach ($splits as $split) {
            $splitSum += floatval($split['amount']);
        }
        if (abs($splitSum - $amount) > 0.01) {
            ApiResponse::error('Split amounts do not equal total payment amount');
        }

        $receipts = [];
        foreach ($splits as $split) {
            $splitAmt = floatval($split['amount']);
            $splitCat = $split['category'] ?? 'booking';
            
            // Sub-tag reference for online payment gateways / UPI
            $splitRef = $ref;
            if (in_array(strtolower($method), ['online', 'upi', 'card', 'bank_transfer']) && !empty($ref)) {
                $splitRef = $ref . '-split-' . $splitCat;
            }

            $entryId = FolioService::recordPayment($db, $bookingId, $splitAmt, $method, $splitRef, 'admin', $splitCat, $recordedAt, true);
            
            $receiptStmt = $db->prepare("SELECT display_id FROM folio_ledger WHERE id = ?");
            $receiptStmt->execute([$entryId]);
            $rId = $receiptStmt->fetchColumn() ?: 'RCPT-' . $entryId;
            $receipts[] = $rId;

            // Insert into finance
            $financeStmt = $db->prepare("INSERT INTO finance_transactions (property_id, type, category, booking_id, amount, description, payment_method, staff_id, recorded_at) VALUES (:prop_id, 'income', :cat, :bid, :amount, :desc, :method, :staff, :recorded_at)");
            
            $catLabel = $splitCat;
            if ($splitCat === 'booking') {
                $catLabel = 'Room Rent';
            } elseif ($splitCat === 'F&B') {
                $catLabel = 'F&B';
            }
            $desc = "Split Payment " . strtoupper($method) . " - " . $catLabel . " (Receipt {$rId})";
            
            $financeParams = [
                'prop_id' => $propertyId,
                'cat'     => $splitCat,
                'bid'     => $bookingId,
                'amount'  => $splitAmt,
                'desc'    => $desc,
                'method'  => strtolower($method),
                'staff'   => $_SESSION['user_id'] ?? null,
                'recorded_at' => $recordedAt ?: date('Y-m-d H:i:s')
            ];
            // BUG-1 fix: execute was missing — finance record was never saved
            $financeStmt->execute($financeParams);
            $financeId = (int)$db->lastInsertId();
            SequenceGenerator::assignDisplayId($db, 'finance_transactions', $financeId, 'SEQ_TRANSACTION_FORMAT');

            // Link generated transaction ID to the split folio entry
            $txnStmt = $db->prepare("SELECT display_id FROM finance_transactions WHERE id = ?");
            $txnStmt->execute([$financeId]);
            $txnDisplayId = $txnStmt->fetchColumn();
            if ($txnDisplayId) {
                $db->prepare("UPDATE folio_ledger SET transaction_ref = ? WHERE id = ? AND (transaction_ref LIKE 'MANUAL%' OR transaction_ref = '' OR transaction_ref IS NULL)")->execute([$txnDisplayId, $entryId]);
            }
        }
        $receiptDisplayId = implode(', ', $receipts);
    } else {
        // Standard single payment flow
        $entryId = FolioService::recordPayment($db, $bookingId, $amount, $method, $ref, 'admin', 'booking', $recordedAt);

        // Record finance transaction
        $receiptStmt = $db->prepare("SELECT display_id FROM folio_ledger WHERE id = ?");
        $receiptStmt->execute([$entryId]);
        $receiptDisplayId = $receiptStmt->fetchColumn() ?: 'RCPT-' . $entryId;
        
        if (strtoupper($method) === 'CITY_LEDGER') {
            $cityStmt = $db->prepare("INSERT INTO city_ledger (property_id, company_id, booking_id, amount, type, status, recorded_at) VALUES (:pid, :cid, :bid, :amount, 'charge', 'pending', :recorded_at)");
            $cityStmt->execute([
                'pid' => $propertyId,
                'cid' => $booking['company_id'],
                'bid' => $bookingId,
                'amount' => $amount,
                'recorded_at' => $recordedAt ?: date('Y-m-d H:i:s')
            ]);
            
            // Update company balance
            $db->prepare("UPDATE companies SET balance = balance + ? WHERE id = ? AND property_id = ?")->execute([$amount, $booking['company_id'], $propertyId]);
        } else {
            $financeStmt = $db->prepare("INSERT INTO finance_transactions (property_id, type, category, booking_id, amount, description, payment_method, staff_id, recorded_at) VALUES (:prop_id, 'income', 'booking', :bid, :amount, :desc, :method, :staff, :recorded_at)");
            $financeStmt->execute([
                'prop_id' => $propertyId,
                'bid' => $bookingId,
                'amount' => $amount,
                'desc' => "Payment - " . ucfirst($method) . " (Receipt {$receiptDisplayId})",
                'method' => strtolower($method),
                'staff' => $_SESSION['user_id'] ?? null,
                'recorded_at' => $recordedAt ?: date('Y-m-d H:i:s')
            ]);
            $financeId = (int)$db->lastInsertId();
            SequenceGenerator::assignDisplayId($db, 'finance_transactions', $financeId, 'SEQ_TRANSACTION_FORMAT');

            // Link generated transaction ID to the folio entry
            $txnStmt = $db->prepare("SELECT display_id FROM finance_transactions WHERE id = ?");
            $txnStmt->execute([$financeId]);
            $txnDisplayId = $txnStmt->fetchColumn();
            if ($txnDisplayId) {
                $db->prepare("UPDATE folio_ledger SET transaction_ref = ? WHERE id = ? AND (transaction_ref LIKE 'MANUAL%' OR transaction_ref = '' OR transaction_ref IS NULL)")->execute([$txnDisplayId, $entryId]);
            }
        }
    }

    // Telegram notification
    $tgMsg = "💰 <b>Payment Received</b>\n\nRoom: {$booking['room_number']}\nGuest: " . htmlspecialchars($booking['guest_name']) . "\nAmount: ₹" . number_format($amount, 2) . "\nMethod: " . ucfirst($method);
    $context = [
        'guest_name' => $booking['guest_name'] ?? 'N/A',
        'room_number' => $booking['room_number'],
        'amount' => number_format($amount, 2),
        'method' => ucfirst($method),
        'ref' => $ref ?: 'N/A'
    ];
    NotificationRelay::sendTelegram($tgMsg, 'payment_received', $context);
    
    ApiResponse::success(['entry_id' => $entryId, 'receipt_id' => $receiptDisplayId]);

}, true, true, true); // requireAdmin=true, requireCsrf=true, useTransaction=true
