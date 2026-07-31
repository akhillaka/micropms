<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';

/**
 * Daily Summary Telegram Notification
 *
 * Can be called via:
 *   - CLI:  php public_html/api/admin_daily_summary.php
 *   - Cron: curl http://localhost:8000/api/admin_daily_summary.php
 *   - Manual: Visit in browser (requires auth)
 *
 * Fix #3/#20: CLI mode is detected before ApiHandler::run().
 * When run from CLI, auth and CSRF checks are skipped (no session available).
 * When run from web, owner auth and CSRF are enforced.
 */
$isCli = (php_sapi_name() === 'cli');

ApiHandler::run(function(\PDO $db) use ($isCli) {

    // Fix #3: owner check only applies to web requests
    if (!$isCli) {
        AuthHelper::requirePermission('view_finance');
    }

    $today = date('Y-m-d');

    // 1. Total Booking created today
    $createdStmt = $db->prepare("SELECT COUNT(*) as cnt FROM bookings 
        WHERE DATE(created_at) = :d AND payment_status != 'cancelled'");
    $createdStmt->execute(['d' => $today]);
    $bookingsCreated = $createdStmt->fetch()['cnt'];

    // 2. Occupied count (rooms currently checked in)
    $inHouseStmt = $db->query("SELECT COUNT(*) as cnt FROM bookings 
        WHERE booking_status = 'checked_in' AND payment_status != 'cancelled'");
    $inHouse = $inHouseStmt->fetch()['cnt'];

    // 3. Check out count (completed checkouts today)
    $coutStmt = $db->prepare("SELECT COUNT(*) as cnt FROM bookings 
        WHERE booking_status = 'checked_out' AND DATE(check_out) = :d AND payment_status != 'cancelled'");
    $coutStmt->execute(['d' => $today]);
    $checkouts = $coutStmt->fetch()['cnt'];

    // 4. UPI Amount collected today
    $upiStmt = $db->prepare("SELECT COALESCE(SUM(-amount), 0) as total FROM folio_ledger 
        WHERE transaction_type IN ('payment', 'refund') 
          AND DATE(recorded_at) = :d 
          AND LOWER(payment_method) = 'upi'");
    $upiStmt->execute(['d' => $today]);
    $upiAmount = (float)$upiStmt->fetch()['total'];

    // 5. Cash Amount collected today
    $cashStmt = $db->prepare("SELECT COALESCE(SUM(-amount), 0) as total FROM folio_ledger 
        WHERE transaction_type IN ('payment', 'refund') 
          AND DATE(recorded_at) = :d 
          AND LOWER(payment_method) = 'cash'");
    $cashStmt->execute(['d' => $today]);
    $cashAmount = (float)$cashStmt->fetch()['total'];

    // 6. Expense amount today
    $expStmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM finance_transactions 
        WHERE type = 'expense' AND DATE(recorded_at) = :d");
    $expStmt->execute(['d' => $today]);
    $expenseAmount = (float)$expStmt->fetch()['total'];

    // 7. Total Amount collected today (Revenue across all methods)
    $revStmt = $db->prepare("SELECT COALESCE(SUM(-amount), 0) as total FROM folio_ledger 
        WHERE transaction_type IN ('payment', 'refund') AND DATE(recorded_at) = :d");
    $revStmt->execute(['d' => $today]);
    $totalAmount = (float)$revStmt->fetch()['total'];

    // 8. Net Amount today (Total collected - Expenses)
    $netAmount = $totalAmount - $expenseAmount;

    // 9. Detailed occupied rooms
    $roomsQuery = $db->query("
        SELECT 
            b.id as booking_id,
            r.room_number,
            rc.name as room_type,
            g.name as guest_name,
            (SELECT COALESCE(SUM(ABS(fl.amount)), 0) FROM folio_ledger fl WHERE fl.booking_id = b.id AND fl.transaction_type = 'payment') as amount_collected,
            (SELECT COALESCE(SUM(fl.amount), 0) FROM folio_ledger fl WHERE fl.booking_id = b.id) as pending_due
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        JOIN room_categories rc ON r.category_id = rc.id
        JOIN guests g ON b.guest_id = g.id
        WHERE b.booking_status = 'checked_in' AND b.payment_status != 'cancelled'
        ORDER BY r.room_number ASC
    ");
    $occupiedList = $roomsQuery->fetchAll(\PDO::FETCH_ASSOC);

    $totalRooms = $db->query("SELECT COUNT(*) as cnt FROM rooms")->fetch()['cnt'];
    $dirtyRooms = $db->query("SELECT COUNT(*) as cnt FROM rooms WHERE state = 'dirty'")->fetch()['cnt'];
    $cleanRooms = $totalRooms - $dirtyRooms;

    // Build message
    $msg = "📊 <b>Daily Summary — " . date('d M Y') . "</b>\n";
    $msg .= "━━━━━━━━━━━━━━━━━━\n\n";
    $msg .= "📥 Bookings created today: <b>{$bookingsCreated}</b>\n";
    $msg .= "🏨 Occupied count: <b>{$inHouse}</b>\n";
    $msg .= "📤 Check-out count: <b>{$checkouts}</b>\n\n";

    $msg .= "💳 UPI Amount collected: <b>₹" . number_format($upiAmount, 2) . "</b>\n";
    $msg .= "💵 Cash Amount collected: <b>₹" . number_format($cashAmount, 2) . "</b>\n";
    $msg .= "💸 Expense amount: <b>₹" . number_format($expenseAmount, 2) . "</b>\n\n";

    $msg .= "💰 Total Amount: <b>₹" . number_format($totalAmount, 2) . "</b>\n";
    $msg .= "📈 Net Amount: <b>₹" . number_format($netAmount, 2) . "</b>\n\n";

    $msg .= "🧹 Housekeeping: Dirty: {$dirtyRooms} | Clean: {$cleanRooms}\n\n";

    $msg .= "🚪 <b>Occupied Rooms Details:</b>\n";
    if (empty($occupiedList)) {
        $msg .= "<i>No occupied rooms currently.</i>\n";
    } else {
        foreach ($occupiedList as $room) {
            $collected = (float)$room['amount_collected'];
            $due = (float)$room['pending_due'];
            $msg .= "• <b>Room {$room['room_number']}</b> ({$room['room_type']})\n";
            $msg .= "  👤 Guest: " . htmlspecialchars($room['guest_name']) . "\n";
            $msg .= "  📥 Collected: ₹" . number_format($collected, 2) . "\n";
            $msg .= "  ⏳ Pending: ₹" . number_format($due, 2) . "\n";
        }
    }

    $msg .= "\n━━━━━━━━━━━━━━━━━━\n";
    $msg .= "MicroPMS • " . date('h:i A');

    $context = [
        'occupancy_pct' => $totalRooms > 0 ? round(($inHouse / $totalRooms) * 100) : 0,
        'occupied_rooms' => $inHouse,
        'total_rooms' => $totalRooms,
        'dirty_count' => $dirtyRooms,
        'clean_count' => $cleanRooms,
        'bookings_created' => $bookingsCreated,
        'checkouts_today' => $checkouts,
        'upi_collected' => number_format($upiAmount, 2),
        'cash_collected' => number_format($cashAmount, 2),
        'expense_amount' => number_format($expenseAmount, 2),
        'total_amount' => number_format($totalAmount, 2),
        'net_amount' => number_format($netAmount, 2)
    ];
    $result = NotificationRelay::sendTelegram($msg);

    if ($isCli) {
        echo $result ? "Daily summary sent.\n" : "Failed to send (not configured or disabled).\n";
    } else {
        ApiResponse::success(['sent' => $result]);
    }

// Fix #20: skip auth+CSRF for CLI; enforce both for web
}, !$isCli, !$isCli, false);
