<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('manage_settings');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['action'])) throw new Exception("Action required");
    
    if ($data['action'] === 'save_category') {
        if (!empty($data['cat_id'])) {
            $stmt = $db->prepare("UPDATE room_categories SET name = :name WHERE id = :id");
            $stmt->execute(['name' => $data['cat_name'], 'id' => $data['cat_id']]);
            AuditLogger::log($_SESSION['user_id'], 'EDIT_CATEGORY', 'SYSTEM', $data['cat_id'], $data);
            ApiResponse::success(['id' => $data['cat_id'], 'data' => $data]);
        } else {
            $stmt = $db->prepare("INSERT INTO room_categories (name) VALUES (:name)");
            $stmt->execute(['name' => $data['cat_name']]);
            $id = $db->lastInsertId();
            AuditLogger::log($_SESSION['user_id'], 'ADD_CATEGORY', 'SYSTEM', $id, $data);
            ApiResponse::success(['id' => $id, 'data' => $data]);
        }
        
    } elseif ($data['action'] === 'save_room') {
        require_once __DIR__ . '/../../pms_core/services/SaaSBillingEngine.php';
        $propertyId = AuthHelper::getPropertyId();

        if (!empty($data['room_id'])) {
            $stmt = $db->prepare("UPDATE rooms SET room_number = :num, category_id = :cat_id WHERE id = :id");
            $stmt->execute(['num' => $data['room_number'], 'cat_id' => $data['category_id'], 'id' => $data['room_id']]);
            $id = $data['room_id'];
            AuditLogger::log($_SESSION['user_id'], 'EDIT_ROOM', 'ROOM', $id, $data);
        } else {
            // SaaS Plan Room Limit Guard Check
            SaaSBillingEngine::checkRoomLimit($db, $propertyId);

            $stmt = $db->prepare("INSERT INTO rooms (room_number, category_id, property_id) VALUES (:num, :cat_id, :prop_id)");
            $stmt->execute(['num' => $data['room_number'], 'cat_id' => $data['category_id'], 'prop_id' => $propertyId]);
            $id = $db->lastInsertId();
            AuditLogger::log($_SESSION['user_id'], 'ADD_ROOM', 'ROOM', $id, $data);
        }
        
        $catStmt = $db->prepare("SELECT name FROM room_categories WHERE id = :id");
        $catStmt->execute(['id' => $data['category_id']]);
        $catName = $catStmt->fetchColumn();
        
        ApiResponse::success(['id' => $id, 'category_name' => $catName]);
        
    } elseif ($data['action'] === 'add_rate') {
        $rateName = $data['rate_plan_name'] ?? null;
        $categoryId = $data['category_id'] ?? null;
        $prices = $data['prices'] ?? [];
        
        if (!$categoryId || empty($prices)) {
            throw new Exception("Category ID and prices are required.");
        }

        $stmt = $db->prepare("INSERT INTO sliding_rates (category_id, hours, price, rate_plan_name) VALUES (:cat_id, :hours, :price, :rate_name) ON DUPLICATE KEY UPDATE price = :update_price, rate_plan_name = :update_rate_name");
        
        foreach ($prices as $hour => $price) {
            $priceFloat = (float)$price;
            if ($priceFloat > 0 || $price !== '') { // Only insert/update if price is provided
                $stmt->execute([
                    'cat_id' => $categoryId, 
                    'hours' => $hour, 
                    'price' => $priceFloat, 
                    'rate_name' => $rateName, 
                    'update_price' => $priceFloat, 
                    'update_rate_name' => $rateName
                ]);
            }
        }
        
        $catStmt = $db->prepare("SELECT name FROM room_categories WHERE id = :id");
        $catStmt->execute(['id' => $categoryId]);
        $catName = $catStmt->fetchColumn();
        AuditLogger::log($_SESSION['user_id'], 'ADD_RATE', 'SYSTEM', $categoryId, ['rate_plan' => $rateName, 'category' => $catName]);
        ApiResponse::success(['category_name' => $catName]);
        
    } elseif ($data['action'] === 'save_bulk_rates') {
        $categoryId = $data['category_id'] ?? null;
        $rates = $data['rates'] ?? [];
        
        if (!$categoryId) {
            throw new Exception("Category ID is required.");
        }

        // Debug logging (can be removed in production)
        error_log("[save_bulk_rates] Category: $categoryId, Plans: " . implode(', ', array_keys($rates)));
        foreach ($rates as $plan => $hours) {
            $nonEmpty = array_filter($hours, fn($v) => $v !== '' && $v !== null);
            error_log("[save_bulk_rates] Plan '$plan': " . count($nonEmpty) . " non-empty prices");
        }

        // Delete existing rates for this category first
        $del = $db->prepare("DELETE FROM sliding_rates WHERE category_id = :cat_id");
        $del->execute(['cat_id' => $categoryId]);

        $stmt = $db->prepare("INSERT INTO sliding_rates (category_id, hours, price, rate_plan_name) VALUES (:cat_id, :hours, :price, :rate_name)");
        
        $insertedCount = 0;
        foreach ($rates as $planName => $hoursData) {
            // Handle "Base Rate" as null (default plan)
            $dbPlanName = ($planName === 'Base Rate' || $planName === '') ? null : $planName;
            
            foreach ($hoursData as $hour => $price) {
                // Accept any non-empty price (including 0)
                if ($price !== '' && $price !== null) {
                    $priceFloat = (float)$price;
                    // Only insert if price is valid (>= 0)
                    if ($priceFloat >= 0) {
                        $stmt->execute([
                            'cat_id' => $categoryId,
                            'hours' => (int)$hour,
                            'price' => $priceFloat,
                            'rate_name' => $dbPlanName
                        ]);
                        $insertedCount++;
                    }
                }
            }
        }
        
        error_log("[save_bulk_rates] Inserted $insertedCount rates");
        
        // Validate at least some rates were saved
        if ($insertedCount === 0 && !empty($rates)) {
            throw new Exception("No rates were saved. Please enter at least one price.");
        }
        
        AuditLogger::log($_SESSION['user_id'], 'SAVE_BULK_RATES', 'SYSTEM', $categoryId, ['plans' => array_keys($rates), 'inserted' => $insertedCount]);
        ApiResponse::success(['inserted' => $insertedCount]);
        
    } elseif ($data['action'] === 'delete_category') {
        $id = $data['cat_id'] ?? null;
        if (!$id) throw new Exception("Category ID required");
        
        $roomCount = $db->prepare("SELECT COUNT(*) FROM rooms WHERE category_id = :id");
        $roomCount->execute(['id' => $id]);
        if ($roomCount->fetchColumn() > 0) {
            throw new Exception("Cannot delete: category has rooms assigned to it");
        }
        
        $stmt = $db->prepare("DELETE FROM room_categories WHERE id = :id");
        $stmt->execute(['id' => $id]);
        AuditLogger::log($_SESSION['user_id'], 'DELETE_CATEGORY', 'SYSTEM', $id, $data);
        ApiResponse::success();
        
    } elseif ($data['action'] === 'delete_room') {
        $id = $data['room_id'] ?? null;
        if (!$id) throw new Exception("Room ID required");
        
        $bookingCount = $db->prepare("SELECT COUNT(*) FROM bookings WHERE room_id = :id AND payment_status != 'cancelled'");
        $bookingCount->execute(['id' => $id]);
        if ($bookingCount->fetchColumn() > 0) {
            throw new Exception("Cannot delete: room has active bookings");
        }
        
        $stmt = $db->prepare("DELETE FROM rooms WHERE id = :id");
        $stmt->execute(['id' => $id]);
        AuditLogger::log($_SESSION['user_id'], 'DELETE_ROOM', 'ROOM', $id, $data);
        ApiResponse::success();
        
    } elseif ($data['action'] === 'delete_rate') {
        $catId = $data['category_id'] ?? null;
        $rateName = $data['rate_plan_name'] ?? null;
        if (!$catId) throw new Exception("Category ID required");
        
        if ($rateName !== null && $rateName !== '') {
            $stmt = $db->prepare("DELETE FROM sliding_rates WHERE category_id = :cat_id AND rate_plan_name = :rate_name");
            $stmt->execute(['cat_id' => $catId, 'rate_name' => $rateName]);
        } else {
            $stmt = $db->prepare("DELETE FROM sliding_rates WHERE category_id = :cat_id AND (rate_plan_name IS NULL OR rate_plan_name = '')");
            $stmt->execute(['cat_id' => $catId]);
        }
        AuditLogger::log($_SESSION['user_id'], 'DELETE_RATE', 'SYSTEM', $catId, $data);
        ApiResponse::success();
        
    } else {
        throw new Exception("Unknown action");
    }

}, false, true, true); // requireAdmin=false, requireCsrf=true, useTransaction=true

