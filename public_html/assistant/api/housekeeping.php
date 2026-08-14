<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? 'list';

    // Auto-create checklist tables if missing (migration guard)
    $db->exec("
        CREATE TABLE IF NOT EXISTS `housekeeping_checklist_items` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `category_id` int(11) DEFAULT NULL,
          `item_text` varchar(150) NOT NULL,
          `is_mandatory` tinyint(1) DEFAULT 1,
          `display_order` int(11) DEFAULT 0,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `category_id` (`category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `housekeeping_logs` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `room_id` int(11) NOT NULL,
          `staff_id` int(11) NOT NULL,
          `cleaned_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `inspector_staff_id` int(11) DEFAULT NULL,
          `inspected_at` datetime DEFAULT NULL,
          `status` enum('in_progress','cleaned','inspected_ready') DEFAULT 'cleaned',
          `photo_proof` varchar(255) DEFAULT NULL,
          `notes` text DEFAULT NULL,
          PRIMARY KEY (`id`),
          KEY `room_id` (`room_id`),
          KEY `staff_id` (`staff_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `housekeeping_log_items` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `hk_log_id` int(11) NOT NULL,
          `item_id` int(11) NOT NULL,
          `is_checked` tinyint(1) DEFAULT 1,
          PRIMARY KEY (`id`),
          KEY `hk_log_id` (`hk_log_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Seed defaults if checklist is empty
    $chkCnt = (int)$db->query('SELECT COUNT(*) FROM housekeeping_checklist_items')->fetchColumn();
    if ($chkCnt === 0) {
        $stmtSeed = $db->prepare('INSERT INTO housekeeping_checklist_items (item_text, is_mandatory, display_order) VALUES (?, ?, ?)');
        $defaults = [
            ['Replace Bed Linen & Pillow Covers', 1, 1],
            ['Sanitize & Scrub Bathroom / Toilet', 1, 2],
            ['Replenish Towels & Toiletries', 1, 3],
            ['Sweep & Mop Floor', 1, 4],
            ['Restock Drinking Water Bottles', 0, 5],
            ['Sanitize TV & AC Remote Controls', 0, 6],
            ['Empty Trash Cans & Insert Liners', 1, 7]
        ];
        foreach ($defaults as $row) {
            $stmtSeed->execute($row);
        }
    }

    if ($action === 'mark_clean' || $action === 'add_checklist_item' || $action === 'delete_checklist_item') {
        AuthHelper::requirePermission('housekeeping');
    }

    if ($action === 'list') {
        // Fetch dirty and clean rooms
        $stmt = $db->query("
            SELECT r.id, r.room_number, r.state, r.category_id, c.name as category_name 
            FROM rooms r
            JOIN room_categories c ON r.category_id = c.id
            WHERE r.state IN ('dirty', 'clean')
            ORDER BY r.state DESC, r.room_number ASC
        ");
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch master checklist items
        $cStmt = $db->query("
            SELECT id, category_id, item_text, is_mandatory, display_order 
            FROM housekeeping_checklist_items 
            ORDER BY display_order ASC, id ASC
        ");
        $checklistItems = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        $dirty = [];
        $clean = [];
        foreach ($rooms as $r) {
            if ($r['state'] === 'dirty') {
                // Check if there is an upcoming booking for this room today
                $nextStmt = $db->prepare("
                    SELECT check_in 
                    FROM bookings 
                    WHERE room_id = :rid 
                    AND booking_status = 'booked' 
                    AND check_in >= NOW() 
                    ORDER BY check_in ASC 
                    LIMIT 1
                ");
                $nextStmt->execute(['rid' => $r['id']]);
                $nextArrival = $nextStmt->fetchColumn();

                $priority = 'normal';
                $priorityCode = 3;
                $nextArrivalFormatted = null;

                if ($nextArrival) {
                    $nextTs = strtotime($nextArrival);
                    $diffHours = ($nextTs - time()) / 3600.0;
                    $nextArrivalFormatted = date('h:i A', $nextTs);

                    if ($diffHours <= 2.0) {
                        $priority = 'urgent';
                        $priorityCode = 1;
                    } else {
                        $priority = 'high';
                        $priorityCode = 2;
                    }
                }

                $r['priority'] = $priority;
                $r['priority_code'] = $priorityCode;
                $r['next_arrival'] = $nextArrivalFormatted;
                $dirty[] = $r;
            } else {
                $clean[] = $r;
            }
        }

        // Sort dirty rooms by priority_code (Urgent first) then room_number
        usort($dirty, fn($a, $b) => $a['priority_code'] === $b['priority_code'] 
            ? strnatcmp($a['room_number'], $b['room_number']) 
            : $a['priority_code'] <=> $b['priority_code']);

        ApiResponse::success([
            'dirty' => $dirty,
            'clean' => $clean,
            'checklist_items' => $checklistItems
        ]);

    } elseif ($action === 'mark_clean') {
        $roomId = (int)($data['room_id'] ?? 0);
        if (!$roomId) ApiResponse::error('Room ID is required');

        $completedItemIds = is_array($data['completed_items'] ?? null) ? $data['completed_items'] : [];
        $notes = trim((string)($data['notes'] ?? ''));

        // Update room state to clean
        $stmt = $db->prepare("UPDATE rooms SET state = 'clean' WHERE id = :id AND state = 'dirty'");
        $stmt->execute(['id' => $roomId]);

        if ($stmt->rowCount() > 0) {
            $staffId = (int)($_SESSION['user_id'] ?? 0);
            
            // Log housekeeping record
            $logStmt = $db->prepare("
                INSERT INTO housekeeping_logs (room_id, staff_id, status, notes)
                VALUES (:room_id, :staff_id, 'cleaned', :notes)
            ");
            $logStmt->execute([
                'room_id' => $roomId,
                'staff_id' => $staffId,
                'notes' => $notes
            ]);
            $hkLogId = (int)$db->lastInsertId();

            // Insert checked items
            if ($hkLogId > 0 && !empty($completedItemIds)) {
                $itemInsert = $db->prepare("INSERT INTO housekeeping_log_items (hk_log_id, item_id, is_checked) VALUES (?, ?, 1)");
                foreach ($completedItemIds as $itemId) {
                    $itemInsert->execute([$hkLogId, (int)$itemId]);
                }
            }

            AuditLogger::log($staffId, 'MARKED_ROOM_CLEAN', 'ROOM', $roomId, [
                'source' => 'assistant',
                'checklist_completed_count' => count($completedItemIds)
            ]);

            ApiResponse::success(['message' => 'Room marked clean with checklist logged']);
        } else {
            ApiResponse::error('Room is not dirty or not found');
        }

    } elseif ($action === 'add_checklist_item') {
        $itemText = trim((string)($data['item_text'] ?? ''));
        $isMandatory = !empty($data['is_mandatory']) ? 1 : 0;
        $categoryId = !empty($data['category_id']) ? (int)$data['category_id'] : null;

        if ($itemText === '') ApiResponse::error('Item text is required');

        $stmt = $db->prepare("INSERT INTO housekeeping_checklist_items (category_id, item_text, is_mandatory) VALUES (:cid, :txt, :mand)");
        $stmt->execute(['cid' => $categoryId, 'txt' => $itemText, 'mand' => $isMandatory]);

        ApiResponse::success(['message' => 'Checklist item added', 'id' => (int)$db->lastInsertId()]);

    } elseif ($action === 'delete_checklist_item') {
        $itemId = (int)($data['item_id'] ?? 0);
        if (!$itemId) ApiResponse::error('Item ID required');

        $stmt = $db->prepare("DELETE FROM housekeeping_checklist_items WHERE id = :id");
        $stmt->execute(['id' => $itemId]);

        ApiResponse::success(['message' => 'Checklist item deleted']);

    } else {
        ApiResponse::error('Invalid action');
    }
}, true, true, false);
