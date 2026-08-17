<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../../pms_core/NotificationRelay.php';
require_once __DIR__ . '/../../../pms_core/PhoneHelper.php';
require_once __DIR__ . '/../../../pms_core/TenantScope.php';
require_once __DIR__ . '/../../../pms_core/services/CheckoutService.php';

ApiHandler::run(function(\PDO $db) {
    // Session is checked by ApiHandler

    $data = ApiHandler::getJsonInput();
    $action = $data['action'] ?? $_GET['action'] ?? '';
    $bookingId = (int)($data['booking_id'] ?? $_GET['booking_id'] ?? 0);
    $propertyId = AuthHelper::getPropertyId();

    if (!$bookingId) {
        ApiResponse::error('Booking ID is required');
    }

    $bStmt = $db->prepare("
        SELECT b.id, b.room_id, b.guest_id, b.check_in, b.check_out, b.booking_status, b.total_amount, b.property_id,
               r.room_number, c.name as category_name, g.name as guest_name, g.phone as guest_phone, g.photo as guest_photo
        FROM bookings b
        JOIN rooms r ON b.room_id = r.id
        JOIN room_categories c ON r.category_id = c.id
        LEFT JOIN guests g ON b.guest_id = g.id
        WHERE b.id = :id AND b.property_id = :pid AND b.payment_status != 'cancelled'
    ");
    $bStmt->execute(['id' => $bookingId, 'pid' => $propertyId]);
    $booking = $bStmt->fetch();

    if (!$booking) {
        ApiResponse::error('Booking not found');
    }

    // Action: Get Folio Breakdown Details
    if ($action === 'details') {
        $lStmt = $db->prepare("SELECT * FROM folio_ledger WHERE booking_id = :id ORDER BY recorded_at ASC");
        $lStmt->execute(['id' => $bookingId]);
        $ledgerEntries = $lStmt->fetchAll(PDO::FETCH_ASSOC);

        $roomRent = 0.0;
        $restaurant = 0.0;
        $laundry = 0.0;
        $extraBed = 0.0;
        $taxes = 0.0;
        $incidentals = 0.0;
        $advancePaid = 0.0;
        $totalPaid = 0.0;

        foreach ($ledgerEntries as $entry) {
            $amount = (float)$entry['amount'];
            $desc = strtolower($entry['description'] ?? '');
            
            if ($amount > 0) {
                // Charge
                if ($entry['transaction_type'] === 'ROOM_CHARGE' || strpos($desc, 'room') !== false || strpos($desc, 'rent') !== false) {
                    $roomRent += $amount;
                } elseif (strpos($desc, 'extra bed') !== false || strpos($desc, 'bed') !== false) {
                    $extraBed += $amount;
                } elseif (strpos($desc, 'restaurant') !== false || strpos($desc, 'food') !== false || strpos($desc, 'meal') !== false || strpos($desc, 'dining') !== false) {
                    $restaurant += $amount;
                } elseif (strpos($desc, 'laundry') !== false || strpos($desc, 'dry clean') !== false) {
                    $laundry += $amount;
                } elseif (strpos($desc, 'tax') !== false || strpos($desc, 'gst') !== false || strpos($desc, 'vat') !== false) {
                    $taxes += $amount;
                } else {
                    $incidentals += $amount;
                }
            } else {
                // Payment (Stored as negative numbers in ledger)
                $absVal = abs($amount);
                $totalPaid += $absVal;
                
                if (strpos($desc, 'advance') !== false) {
                    $advancePaid += $absVal;
                }
            }
        }

        $totalCharges = $roomRent + $restaurant + $laundry + $extraBed + $taxes + $incidentals;
        $balance = $totalCharges - $totalPaid;

        ApiResponse::success([
            'booking' => [
                'id' => $booking['id'],
                'room_number' => $booking['room_number'],
                'category_name' => $booking['category_name'],
                'guest_name' => $booking['guest_name'],
                'guest_phone' => $booking['guest_phone'],
                'guest_photo' => $booking['guest_photo'],
                'booking_status' => $booking['booking_status'],
                'check_in' => date('d M Y, g:i A', strtotime($booking['check_in'])),
                'check_out' => date('d M Y, g:i A', strtotime($booking['check_out'])),
                'check_out_raw' => date('Y-m-d\TH:i', strtotime($booking['check_out']))
            ],
            'bill' => [
                'room_rent' => $roomRent,
                'restaurant' => $restaurant,
                'laundry' => $laundry,
                'extra_bed' => $extraBed,
                'taxes' => $taxes,
                'incidentals' => $incidentals,
                'total_charges' => $totalCharges,
                'advance_paid' => $advancePaid,
                'total_paid' => $totalPaid,
                'balance' => round($balance, 2)
            ],
            'ledger' => $ledgerEntries
        ]);
    }

    // Action: Execute Checkout
    elseif ($action === 'checkout') {
        CheckoutService::performCheckout($db, $bookingId, $propertyId, [
            'source' => 'assistant',
            'staff_id' => $_SESSION['user_id'] ?? null,
        ]);
        ApiResponse::success(['message' => 'Checkout processed successfully. Room marked dirty for cleaning.']);
    }

    // Action: Edit a folio charge (manager/owner only)
    elseif ($action === 'edit_charge') {
        $perms = $_SESSION['assistant_permissions'] ?? [];
        if (empty($perms['edit_charge'])) {
            http_response_code(403);
            ApiResponse::error('Permission denied. Manager or Owner role required.');
        }

        $entryId  = (int)($data['entry_id'] ?? 0);
        $newAmt   = isset($data['amount']) ? (float)$data['amount'] : null;
        $newDesc  = isset($data['description']) ? trim($data['description']) : null;

        if (!$entryId) ApiResponse::error('Folio entry ID required');
        // Fetch original entry
        $eStmt = $db->prepare("SELECT id, amount, description, transaction_type, transaction_ref FROM folio_ledger WHERE id = :id AND booking_id = :bid");
        $eStmt->execute(['id' => $entryId, 'bid' => $bookingId]);
        $entry = $eStmt->fetch();

        if (!$entry) ApiResponse::error('Charge entry not found on this booking');
        if (preg_match('/Order #(\d+)/', $entry['description'] ?? '') && strpos($entry['description'] ?? '', 'Reverse') === false) {
            ApiResponse::error('Cannot directly edit POS orders in folio. Please edit the POS order itself.');
        }
        if ($newAmt === null && $newDesc === null) ApiResponse::error('Nothing to update');
        if ($newAmt !== null && $newAmt <= 0) ApiResponse::error('Amount must be positive');

        $role = AuthHelper::getRole();
        $canEditAmount = in_array($role, ['superadmin', 'owner', 'admin'], true);
        $isPayment = (float)$entry['amount'] < 0 || $entry['transaction_type'] === 'payment';

        if ($isPayment && !$canEditAmount) {
            ApiResponse::error('Cannot edit payment records. Superadmin/Owner permission required.');
        }

        $sets = [];
        $params = ['id' => $entryId];
        if ($newAmt !== null) { 
            // Preserve the original sign of the entry (payments are negative, charges positive)
            $finalAmt = $isPayment ? -abs($newAmt) : abs($newAmt);
            
            if (!$canEditAmount && $finalAmt != (float)$entry['amount']) {
                ApiResponse::error('You do not have permission to edit amounts.');
            }
            
            $sets[] = 'amount = :amount'; 
            $params['amount'] = $finalAmt; 
        }
        if ($newDesc !== null) { $sets[] = 'description = :desc'; $params['desc'] = $newDesc; }
        $db->prepare("UPDATE folio_ledger SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
        
        if ($isPayment && $newAmt !== null) {
            // Sync finance_transactions if amount was changed
            $origRef = $entry['transaction_ref'] ?? '';
            if ($origRef && strpos($origRef, 'TXN-') === 0) {
                $db->prepare("UPDATE finance_transactions SET amount = ?, description = ? WHERE display_id = ? AND property_id = ?")->execute([abs($newAmt), $newDesc ?? $entry['description'], $origRef, $propertyId]);
            }
        }

        AuditLogger::log($_SESSION['user_id'], 'EDIT_FOLIO_CHARGE', 'FOLIO', $bookingId, [
            'entry_id' => $entryId,
            'old_amount' => $entry['amount'],
            'new_amount' => $newAmt,
            'old_desc' => $entry['description'],
            'new_desc' => $newDesc,
            'source' => 'assistant'
        ]);
        ApiResponse::success(['message' => 'Charge updated successfully']);
    }

    // Action: Delete (void) a folio charge — creates reversal, never hard deletes
    elseif ($action === 'delete_charge') {
        $perms = $_SESSION['assistant_permissions'] ?? [];
        if (empty($perms['edit_charge'])) {
            http_response_code(403);
            ApiResponse::error('Permission denied. Manager or Owner role required.');
        }

        $entryId = (int)($data['entry_id'] ?? 0);
        if (!$entryId) ApiResponse::error('Folio entry ID required');

        $eStmt = $db->prepare("SELECT id, amount, description, property_id FROM folio_ledger WHERE id = :id AND booking_id = :bid");
        $eStmt->execute(['id' => $entryId, 'bid' => $bookingId]);
        $entry = $eStmt->fetch();

        if (!$entry) ApiResponse::error('Charge entry not found on this booking');
        if (preg_match('/Order #(\d+)/', $entry['description'] ?? '') && strpos($entry['description'] ?? '', 'Reverse') === false) {
            ApiResponse::error('Cannot directly void POS orders in folio. Please edit/void the POS order itself.');
        }
        if ((float)$entry['amount'] < 0) ApiResponse::error('Cannot void a payment entry');

        $db->beginTransaction();
        try {
            // Create a negative reversal entry to cancel the charge
            $propId = (int)($entry['property_id'] ?? AuthHelper::getPropertyId());
            $db->prepare("INSERT INTO folio_ledger (property_id, booking_id, transaction_type, amount, description, transaction_ref)
                          VALUES (:pid, :bid, 'INCIDENTAL', :amount, :desc, 'VOID')")
               ->execute([
                   'pid'    => $propId,
                   'bid'    => $bookingId,
                   'amount' => -(float)$entry['amount'],
                   'desc'   => 'VOID: ' . $entry['description']
               ]);

            AuditLogger::log($_SESSION['user_id'], 'VOID_FOLIO_CHARGE', 'FOLIO', $bookingId, [
                'entry_id' => $entryId, 'amount' => $entry['amount'], 'source' => 'assistant'
            ]);
            $db->commit();
            ApiResponse::success(['message' => 'Charge voided successfully']);
        } catch (\Exception $ex) {
            $db->rollBack();
            ApiResponse::error('Failed to void charge: ' . $ex->getMessage());
        }
    }

    // Action: Update checkout date/time + auto-recalculate room rent
    elseif ($action === 'update_checkout') {
        $perms = $_SESSION['assistant_permissions'] ?? [];
        if (empty($perms['edit_checkout'])) {
            http_response_code(403);
            ApiResponse::error('Permission denied. Manager or Owner role required.');
        }

        $newCheckOut = trim($data['check_out'] ?? '');
        if (!$newCheckOut) ApiResponse::error('New checkout date/time required');

        $newCheckOutTs = strtotime($newCheckOut);
        if (!$newCheckOutTs) ApiResponse::error('Invalid checkout date/time format');

        // Fetch full booking details including current room rent
        $bFull = $db->prepare("
            SELECT b.id, b.room_id, b.check_in, b.check_out, b.category_id, b.rate_plan_name,
                   b.booking_status, b.property_id,
                   r.room_number, c.name as category_name
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            JOIN room_categories c ON r.category_id = c.id
            WHERE b.id = :id AND b.property_id = :pid
        ");
        $bFull->execute(['id' => $bookingId, 'pid' => $propertyId]);
        $bdata = $bFull->fetch();
        if (!$bdata) ApiResponse::error('Booking not found');

        $checkIn    = $bdata['check_in'];
        $oldCheckOut = $bdata['check_out'];
        $newCheckOutStr = date('Y-m-d H:i:s', $newCheckOutTs);

        if ($newCheckOutStr === $oldCheckOut) ApiResponse::error('New checkout is same as current');
        if ($newCheckOutTs <= strtotime($checkIn)) ApiResponse::error('Checkout must be after check-in');

        require_once __DIR__ . '/../../../pms_core/PricingEngine.php';

        // Calculate old and new room rent
        try {
            $oldRent = PricingEngine::calculateTotalCost(
                (int)$bdata['category_id'], $checkIn, $oldCheckOut, $bdata['rate_plan_name'] ?: null
            );
            $newRent = PricingEngine::calculateTotalCost(
                (int)$bdata['category_id'], $checkIn, $newCheckOutStr, $bdata['rate_plan_name'] ?: null
            );
        } catch (\Exception $e) {
            ApiResponse::error('Could not recalculate rent: ' . $e->getMessage());
        }

        $diff = round($newRent - $oldRent, 2);
        $isPrepone = $newCheckOutTs < strtotime($oldCheckOut);

        $db->beginTransaction();
        try {
            // Update booking checkout time
            $db->prepare("UPDATE bookings SET check_out = :co WHERE id = :id AND property_id = :pid")
               ->execute(['co' => $newCheckOutStr, 'id' => $bookingId, 'pid' => $propertyId]);

            // Post adjustment folio entry if rent changes
            if (abs($diff) > 0.01) {
                $adjDesc = $isPrepone
                    ? "Room Rent Adjustment (Early Checkout to " . date('d M, g:i A', $newCheckOutTs) . ")"
                    : "Room Rent Adjustment (Extended Stay to " . date('d M, g:i A', $newCheckOutTs) . ")";

                $propId = (int)($bdata['property_id'] ?? AuthHelper::getPropertyId());
                $db->prepare("INSERT INTO folio_ledger (property_id, booking_id, transaction_type, amount, description, transaction_ref)
                              VALUES (:pid, :bid, 'ROOM_CHARGE', :amt, :desc, 'ADJUSTMENT')")
                   ->execute([
                       'pid'  => $propId,
                       'bid'  => $bookingId,
                       'amt'  => $diff,   // negative when preponed = credit
                       'desc' => $adjDesc
                   ]);
            }

            AuditLogger::log($_SESSION['user_id'], 'UPDATE_CHECKOUT', 'BOOKING', $bookingId, [
                'old_checkout' => $oldCheckOut,
                'new_checkout' => $newCheckOutStr,
                'old_rent'     => $oldRent,
                'new_rent'     => $newRent,
                'adjustment'   => $diff,
                'source'       => 'assistant'
            ]);

            $db->commit();
            ApiResponse::success([
                'message'    => ($isPrepone ? 'Checkout preponed' : 'Stay extended') . ' successfully',
                'new_checkout' => date('d M Y, g:i A', $newCheckOutTs),
                'adjustment' => $diff,
                'new_rent'   => $newRent
            ]);
        } catch (\Exception $ex) {
            $db->rollBack();
            ApiResponse::error('Failed to update checkout: ' . $ex->getMessage());
        }
    }

    else {
        ApiResponse::error('Invalid action');
    }

}, true, true, false);
