<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/services/BookingService.php';
require_once __DIR__ . '/../../../pms_core/services/GuestService.php';
require_once __DIR__ . '/../../../pms_core/services/RoomService.php';

ApiHandler::run(function(\PDO $db) {
    $data = ApiHandler::getJsonInput();
    if (!$data) {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
    }
    $action = $data['action'] ?? $_GET['action'] ?? '';

    // Action: Calculate pricing
    if ($action === 'calculate') {
        $categoryId = (int)($data['category_id'] ?? 0);
        $checkIn = $data['check_in'] ?? '';
        $checkOut = $data['check_out'] ?? '';
        $ratePlanName = $data['rate_plan_name'] ?? null;
        $extraBed = (int)($data['extra_bed'] ?? 0) === 1;

        if (!$categoryId || !$checkIn || !$checkOut) {
            ApiResponse::error('Missing category ID, check-in, or check-out date');
        }

        try {
            $totalRoomCost = PricingEngine::calculateTotalCost($categoryId, $checkIn, $checkOut, $ratePlanName);
            $breakdown = PricingEngine::getCostBreakdown($categoryId, $checkIn, $checkOut, $ratePlanName);
            
            $extraBedCost = 0.0;
            if ($extraBed) {
                $days = BookingService::calculateDays($checkIn, $checkOut);
                $extraBedCost = $days * BookingService::extraBedNightlyRate($db, AuthHelper::getPropertyId());
            }

            ApiResponse::success([
                'room_cost' => $totalRoomCost,
                'extra_bed_cost' => $extraBedCost,
                'total_cost' => $totalRoomCost + $extraBedCost,
                'breakdown' => $breakdown
            ]);
        } catch (\Exception $e) {
            ApiResponse::error($e->getMessage());
        }
    }

    // Action: Check duplicates
    elseif ($action === 'check_duplicate') {
        $phone = $data['phone'] ?? '';
        $checkIn = $data['check_in'] ?? '';
        $checkOut = $data['check_out'] ?? '';

        $duplicate = BookingService::checkDuplicate($db, $phone, $checkIn, $checkOut);

        if ($duplicate) {
            ApiResponse::success([
                'is_duplicate' => true,
                'message' => "Warning: Guest {$duplicate['guest_name']} already has an active booking in Room {$duplicate['room_number']} from {$duplicate['check_in']} to {$duplicate['check_out']}.",
                'duplicate' => $duplicate
            ]);
        } else {
            ApiResponse::success(['is_duplicate' => false]);
        }
    }

    // Action: Create booking (supports single or multi-room)
    elseif ($action === 'create') {
        // Support multi-room via room_ids array
        $roomIds = [];
        if (isset($data['room_ids']) && is_array($data['room_ids'])) {
            $roomIds = $data['room_ids'];
        } elseif (isset($data['room_id'])) {
            $roomIds = [(int)$data['room_id']];
        } else {
            ApiResponse::error('Missing room_id or room_ids');
        }

        $db->beginTransaction();
        try {
            $bookingIds = [];
            $totalAmount = 0;

            foreach ($roomIds as $roomId) {
                $params = $data;
                $params['room_id'] = (int)$roomId;
                $result = BookingService::createBooking($db, $params);
                $bookingIds[] = $result['booking_id'];
                $totalAmount += $result['total_amount'];
            }

            $db->commit();
            ApiResponse::success([
                'message' => count($bookingIds) . ' booking(s) created successfully',
                'booking_ids' => $bookingIds,
                'booking_id' => $bookingIds[0], // Backward compatibility
                'display_id' => $result['display_id'] ?? '',
                'total_amount' => $totalAmount
            ]);
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            ApiResponse::error($e->getMessage());
        }
    }

    // Action: Sync offline drafts
    elseif ($action === 'sync') {
        $drafts = $data['drafts'] ?? [];
        if (empty($drafts)) {
            ApiResponse::success(['synced' => []]);
        }

        $syncedIds = [];
        $errors = [];

        foreach ($drafts as $draft) {
            $db->beginTransaction();
            try {
                // If guest was created offline, resolve/create them first
                if (isset($draft['guest_id']) && (strpos((string)$draft['guest_id'], 'offline_') === 0 || $draft['guest_id'] == 0)) {
                    $guestName = $draft['guest_name'] ?? 'Unknown Guest';
                    $guestPhone = $draft['guest_phone'] ?? '';
                    $guest = GuestService::findOrCreate($db, $guestName, $guestPhone);
                    $draft['guest_id'] = (int)$guest['id'];
                }

                $result = BookingService::createBooking($db, $draft);
                $db->commit();
                $syncedIds[] = $draft['offline_id'];
            } catch (\Exception $ex) {
                $db->rollBack();
                $errors[] = [
                    'offline_id' => $draft['offline_id'],
                    'message' => $ex->getMessage()
                ];
            }
        }

        ApiResponse::success(['synced' => $syncedIds, 'errors' => $errors]);
    }

    // Action: Extend stay
    elseif ($action === 'extend_stay') {
        $bookingId = (int)($data['booking_id'] ?? 0);
        $newCheckOut = $data['check_out'] ?? '';

        if (!$bookingId || empty($newCheckOut)) {
            ApiResponse::error('Missing booking ID or new checkout date');
        }

        try {
            $db->beginTransaction();
            $result = BookingService::extendStay($db, $bookingId, $newCheckOut);
            $db->commit();
            ApiResponse::success([
                'message' => 'Stay extended successfully',
                'extra_cost' => $result['extra_cost']
            ]);
        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            ApiResponse::error($e->getMessage());
        }
    }

    // Action: List bookings
    elseif ($action === 'list') {
        $filter = $data['filter'] ?? $_GET['filter'] ?? 'today';
        $search = trim((string)($data['q'] ?? $_GET['q'] ?? ''));
        
        $bookings = BookingService::listBookings($db, $filter, $search);
        ApiResponse::success(['bookings' => $bookings]);
    }

    // Action: Generate secure invoice link
    elseif ($action === 'invoice_link') {
        $bookingId = (int)($data['booking_id'] ?? $_GET['booking_id'] ?? 0);
        if (!$bookingId) {
            ApiResponse::error('Missing booking ID');
        }
        
        require_once __DIR__ . '/../../../pms_core/InvoiceLink.php';
        $link = InvoiceLink::getUrl($bookingId);
        ApiResponse::success(['invoice_link' => $link]);
    }

    // Action: Get booking balance/due
    elseif ($action === 'get_balance') {
        $bookingId = (int)($data['booking_id'] ?? $_GET['booking_id'] ?? 0);
        if (!$bookingId) {
            ApiResponse::error('Missing booking ID');
        }
        require_once __DIR__ . '/../../../pms_core/services/FolioService.php';
        $balance = FolioService::getBalance($db, $bookingId);
        ApiResponse::success(['balance' => max(0.0, $balance)]);
    }

    else {
        ApiResponse::error('Invalid action');
    }

}, true, true, false);
