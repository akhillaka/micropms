<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../pms_core/SequenceGenerator.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('edit_folio');

    $data     = ApiHandler::getJsonInput();
    $ledgerId = $data['ledger_id'] ?? 0;
    $amount   = floatval($data['amount'] ?? 0);
    $desc     = trim($data['description'] ?? '');
    $method   = trim($data['payment_method'] ?? '');
    $category = trim((string)($data['category'] ?? ''));
    $recordedAtIn = trim(str_replace('T', ' ', (string)($data['recorded_at'] ?? '')));
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $recordedAtIn)) {
        $recordedAtIn .= ':00';
    }
    if ($recordedAtIn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $recordedAtIn)) {
        $recordedAtIn = '';
    }
    if (!$ledgerId || !$desc) {
        ApiResponse::error('Missing fields');
    }


    // Fetch original details to preserve sign logic
    $propertyId = AuthHelper::getPropertyId();
    $origStmt = $db->prepare("SELECT * FROM folio_ledger WHERE id = :id AND property_id = :pid");
    $origStmt->execute(['id' => $ledgerId, 'pid' => $propertyId]);
    $origEntry = $origStmt->fetch();
    
    if (!$origEntry) {
        ApiResponse::error('Ledger entry not found');
    }
    
    $origAmt = (float)$origEntry['amount'];
    $role = AuthHelper::getRole();
    $canEditAmount = in_array($role, ['superadmin', 'owner', 'admin'], true);

    if (!$canEditAmount && $amount != abs($origAmt)) {
        ApiResponse::error('You do not have permission to edit amounts. Please post a Rebate instead.', 403);
    }
    
    $splits = $data['splits'] ?? [];
    if (!empty($splits)) {
        // Validate total split amount matches amount
        $splitSum = 0;
        foreach ($splits as $split) {
            $splitSum += floatval($split['amount']);
        }
        if (abs($splitSum - $amount) > 0.01) {
            ApiResponse::error('Split amounts do not equal total amount');
        }

        $bookingId = (int)$origEntry['booking_id'];
        $displayId = $origEntry['display_id'];
        $ref = $origEntry['transaction_ref'] ?? '';
        $recordedAt = $recordedAtIn !== '' ? $recordedAtIn : $origEntry['recorded_at'];
        $propertyId = (int)$origEntry['property_id'];

        // Remove original ledger entry
        $db->prepare("DELETE FROM folio_ledger WHERE id = ? AND property_id = ?")->execute([$ledgerId, $propertyId]);

        // Remove matching finance transactions
        $receiptPattern = "Receipt " . $displayId;
        $db->prepare("DELETE FROM finance_transactions WHERE booking_id = ? AND description LIKE ? AND property_id = ?")->execute([$bookingId, "%{$receiptPattern}%", $propertyId]);

        // Insert new splits
        foreach ($splits as $split) {
            $splitAmt = floatval($split['amount']);
            $splitCat = $split['category'] ?? 'booking';
            
            // Sub-tag reference if not already tagged
            $splitRef = $ref;
            if (in_array(strtolower($method), ['online', 'upi', 'card', 'bank_transfer']) && !empty($ref)) {
                // Strip existing split tag if it exists in ref
                $cleanRef = explode('-split-', $ref)[0];
                $splitRef = $cleanRef . '-split-' . $splitCat;
            }

            // Ledger entry for payment must be negative
            $ledgerAmount = -$splitAmt;

            $catLabel = $splitCat;
            if ($splitCat === 'booking') {
                $catLabel = 'Room Rent';
            } elseif ($splitCat === 'F&B') {
                $catLabel = 'F&B';
            }

            $descFolio = ($ledgerAmount < 0 ? 'Split Payment ' : 'Split Refund ') . strtoupper($method) . ' - ' . $catLabel;

            $sql = "INSERT INTO folio_ledger (display_id, property_id, booking_id, transaction_type, amount, transaction_ref, description, payment_method, category, recorded_at) VALUES (:disp, :pid, :bid, 'payment', :amount, :ref, :desc, :method, :category, :recorded_at)";
            $db->prepare($sql)->execute([
                'disp' => $displayId,
                'pid' => $propertyId,
                'bid' => $bookingId,
                'amount' => $ledgerAmount,
                'ref' => $splitRef,
                'desc' => $descFolio,
                'method' => strtolower($method),
                'category' => $splitCat,
                'recorded_at' => $recordedAt
            ]);

            // Insert into finance_transactions
            $financeStmt = $db->prepare("INSERT INTO finance_transactions (property_id, type, category, booking_id, amount, description, payment_method, staff_id, recorded_at) VALUES (:pid, 'income', :cat, :bid, :amount, :desc, :method, :staff, :recorded_at)");
            $descText = "Split Payment " . strtoupper($method) . " - " . $catLabel . " (Receipt {$displayId})";
            $financeStmt->execute([
                'pid' => $propertyId,
                'cat' => $splitCat,
                'bid' => $bookingId,
                'amount' => $splitAmt,
                'desc' => $descText,
                'method' => strtolower($method),
                'staff' => $_SESSION['user_id'] ?? null,
                'recorded_at' => $recordedAt
            ]);
            SequenceGenerator::assignDisplayId($db, 'finance_transactions', (int)$db->lastInsertId(), 'SEQ_TRANSACTION_FORMAT');
        }
    } else {
        // Ensure we keep the same mathematical sign (payments remain negative, charges remain positive)
        $amount = abs($amount);
        if ($origAmt < 0) {
            $amount = -$amount;
        }

        $setCat = $category !== '';
        $setDate = $recordedAtIn !== '';
        if ($method !== '' && $setCat && $setDate) {
            $stmt = $db->prepare("UPDATE folio_ledger SET amount = :amt, description = :desc, payment_method = :pm, category = :cat, recorded_at = :dt WHERE id = :id AND property_id = :pid");
            $stmt->execute(['amt' => $amount, 'desc' => $desc, 'pm' => $method, 'cat' => $category, 'dt' => $recordedAtIn, 'id' => $ledgerId, 'pid' => $propertyId]);
        } elseif ($method !== '' && $setCat) {
            $stmt = $db->prepare("UPDATE folio_ledger SET amount = :amt, description = :desc, payment_method = :pm, category = :cat WHERE id = :id AND property_id = :pid");
            $stmt->execute(['amt' => $amount, 'desc' => $desc, 'pm' => $method, 'cat' => $category, 'id' => $ledgerId, 'pid' => $propertyId]);
        } elseif ($method !== '' && $setDate) {
            $stmt = $db->prepare("UPDATE folio_ledger SET amount = :amt, description = :desc, payment_method = :pm, recorded_at = :dt WHERE id = :id AND property_id = :pid");
            $stmt->execute(['amt' => $amount, 'desc' => $desc, 'pm' => $method, 'dt' => $recordedAtIn, 'id' => $ledgerId, 'pid' => $propertyId]);
        } elseif ($method !== '') {
            $stmt = $db->prepare("UPDATE folio_ledger SET amount = :amt, description = :desc, payment_method = :pm WHERE id = :id AND property_id = :pid");
            $stmt->execute(['amt' => $amount, 'desc' => $desc, 'pm' => $method, 'id' => $ledgerId, 'pid' => $propertyId]);
        } elseif ($setCat && $setDate) {
            $stmt = $db->prepare("UPDATE folio_ledger SET amount = :amt, description = :desc, category = :cat, recorded_at = :dt WHERE id = :id AND property_id = :pid");
            $stmt->execute(['amt' => $amount, 'desc' => $desc, 'cat' => $category, 'dt' => $recordedAtIn, 'id' => $ledgerId, 'pid' => $propertyId]);
        } elseif ($setCat) {
            $stmt = $db->prepare("UPDATE folio_ledger SET amount = :amt, description = :desc, category = :cat WHERE id = :id AND property_id = :pid");
            $stmt->execute(['amt' => $amount, 'desc' => $desc, 'cat' => $category, 'id' => $ledgerId, 'pid' => $propertyId]);
        } elseif ($setDate) {
            $stmt = $db->prepare("UPDATE folio_ledger SET amount = :amt, description = :desc, recorded_at = :dt WHERE id = :id AND property_id = :pid");
            $stmt->execute(['amt' => $amount, 'desc' => $desc, 'dt' => $recordedAtIn, 'id' => $ledgerId, 'pid' => $propertyId]);
        } else {
            $stmt = $db->prepare("UPDATE folio_ledger SET amount = :amt, description = :desc WHERE id = :id AND property_id = :pid");
            $stmt->execute(['amt' => $amount, 'desc' => $desc, 'id' => $ledgerId, 'pid' => $propertyId]);
        }

        if ($setDate && $origAmt < 0) {
            $displayId = (string)($origEntry['display_id'] ?? '');
            if ($displayId !== '') {
                $finDt = $db->prepare("UPDATE finance_transactions SET recorded_at = :dt WHERE booking_id = :bid AND property_id = :pid AND description LIKE :pat");
                $finDt->execute([
                    'dt' => $recordedAtIn,
                    'bid' => (int)$origEntry['booking_id'],
                    'pid' => $propertyId,
                    'pat' => '%Receipt ' . $displayId . '%'
                ]);
            }
        }

        if ($setCat && $origAmt < 0) {
            $displayId = (string)($origEntry['display_id'] ?? '');
            if ($displayId !== '') {
                $fin = $db->prepare("UPDATE finance_transactions SET category = :cat WHERE booking_id = :bid AND property_id = :pid AND description LIKE :pat");
                $fin->execute([
                    'cat' => $category,
                    'bid' => (int)$origEntry['booking_id'],
                    'pid' => $propertyId,
                    'pat' => '%Receipt ' . $displayId . '%'
                ]);
            }
        }
    }

    $tgMsg = "✏️ <b>Folio Entry Edited</b>\n\nLedger #{$ledgerId}\nDescription: " . htmlspecialchars($desc) . "\nNew Amount: ₹" . number_format($amount, 2);
    
    // Attempt to get booking_id and details
    $bIdStmt = $db->prepare("SELECT l.booking_id, r.room_number, g.name as guest_name FROM folio_ledger l JOIN bookings b ON l.booking_id = b.id JOIN rooms r ON b.room_id = r.id LEFT JOIN guests g ON b.guest_id = g.id WHERE l.id = :id AND l.property_id = :pid");
    $bIdStmt->execute(['id' => $ledgerId, 'pid' => $propertyId]);
    $info = $bIdStmt->fetch();
    
    $context = [
        'guest_name' => $info ? ($info['guest_name'] ?? 'N/A') : 'N/A',
        'room_number' => $info ? ($info['room_number'] ?? 'N/A') : 'N/A',
        'description' => "Entry Edited: " . $desc,
        'amount' => number_format($amount, 2)
    ];
    NotificationRelay::sendTelegram($tgMsg, 'folio_activity', $context);
    
    $bId = $info ? $info['booking_id'] : null;
    
    AuditLogger::log($_SESSION['user_id'] ?? null, 'EDIT_LEDGER', 'FOLIO', $bId ?: $ledgerId, [
        'ledger_id' => $ledgerId,
        'amount' => $amount,
        'description' => $desc,
        'payment_method' => $method,
        'category' => $category
    ]);
    
    ApiResponse::success();

}, true, true, false);

