<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../../../pms_core/services/FolioService.php';

ApiHandler::run(function(\PDO $db) {
    $data       = json_decode(file_get_contents('php://input'), true) ?? [];
    $action     = $data['action'] ?? $_GET['action'] ?? '';
    $propertyId = AuthHelper::getPropertyId();

    // ── POS: Get menu (inventory items grouped by outlet) ────────────────────
    if ($action === 'menu') {
        // Fetch outlets for this property
        $oStmt = $db->prepare("SELECT id, name FROM pos_outlets WHERE property_id = :pid ORDER BY name ASC");
        $oStmt->execute(['pid' => $propertyId]);
        $outlets = $oStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all items with stock > 0 (or no inventory tracking) grouped by outlet
        $iStmt = $db->prepare("
            SELECT ii.id, ii.name, ii.selling_price, ii.image_url, ii.outlet_id,
                   COALESCE(pi.stock_quantity, ii.stock_qty, 0) as stock
            FROM inventory_items ii
            LEFT JOIN pos_inventory pi ON pi.item_id = ii.id AND pi.property_id = :pid2 AND pi.deleted_at IS NULL
            WHERE ii.property_id = :pid
              AND ii.selling_price > 0
            ORDER BY ii.outlet_id ASC, ii.name ASC
        ");
        $iStmt->execute(['pid' => $propertyId, 'pid2' => $propertyId]);
        $items = $iStmt->fetchAll(PDO::FETCH_ASSOC);

        // Build outlet → items map
        $outletMap = [];
        foreach ($outlets as $o) {
            $outletMap[$o['id']] = [
                'id'    => (int)$o['id'],
                'name'  => $o['name'],
                'items' => []
            ];
        }
        // Uncategorised fallback
        $outletMap[0] = ['id' => 0, 'name' => 'General', 'items' => []];

        foreach ($items as $item) {
            $oid = (int)($item['outlet_id'] ?? 0);
            $bucket = isset($outletMap[$oid]) ? $oid : 0;
            $outletMap[$bucket]['items'][] = [
                'id'            => (int)$item['id'],
                'name'          => $item['name'],
                'price'         => (float)$item['selling_price'],
                'image_url'     => $item['image_url'],
                'in_stock'      => (int)$item['stock'] > 0
            ];
        }

        // Drop empty outlets
        $menu = array_values(array_filter($outletMap, fn($o) => count($o['items']) > 0));

        ApiResponse::success(['menu' => $menu]);
    }

    // ── POS: Get currently checked-in rooms ──────────────────────────────────
    elseif ($action === 'active_rooms') {
        $stmt = $db->prepare("
            SELECT b.id as booking_id, r.room_number, g.name as guest_name, b.check_out
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            LEFT JOIN guests g ON b.guest_id = g.id
            WHERE b.booking_status = 'checked_in'
              AND b.payment_status != 'cancelled'
              AND b.property_id = :pid
            ORDER BY r.room_number ASC
        ");
        $stmt->execute(['pid' => $propertyId]);
        ApiResponse::success(['rooms' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ── POS: Post order and charge to room ───────────────────────────────────
    elseif ($action === 'post_order') {
        AuthHelper::requirePermission('manage_pos');
        // Permission check
        $perms = $_SESSION['assistant_permissions'] ?? [];
        if (empty($perms['pos_access'])) {
            http_response_code(403);
            ApiResponse::error('Permission denied. POS access required.');
        }

        $bookingId = (int)($data['booking_id'] ?? 0);
        $items     = $data['items'] ?? [];   // [{item_id, quantity, price_per_unit, name}]
        $outletId  = !empty($data['outlet_id']) ? (int)$data['outlet_id'] : null;

        if (!$bookingId)     ApiResponse::error('Booking ID required');
        if (empty($items))   ApiResponse::error('No items in order');

        // Validate booking is checked in
        $bStmt = $db->prepare("SELECT id, booking_status, property_id FROM bookings WHERE id = :id AND property_id = :pid");
        $bStmt->execute(['id' => $bookingId, 'pid' => $propertyId]);
        $booking = $bStmt->fetch();
        if (!$booking)                               ApiResponse::error('Booking not found');
        if ($booking['booking_status'] !== 'checked_in') ApiResponse::error('Room is not currently checked in');

        $db->beginTransaction();
        try {
            usort($items, function ($a, $b) {
                return ((int)($a['item_id'] ?? $a['id'] ?? 0)) <=> ((int)($b['item_id'] ?? $b['id'] ?? 0));
            });

            $total = 0.0;
            $lineItems = [];
            $lockStmt = $db->prepare("
                SELECT ii.id, ii.name, ii.selling_price, ii.stock_qty, pi.id AS pi_id, pi.stock_quantity
                FROM inventory_items ii
                LEFT JOIN pos_inventory pi ON pi.item_id = ii.id AND pi.property_id = ii.property_id AND pi.deleted_at IS NULL
                WHERE ii.id = ? AND ii.property_id = ?
                FOR UPDATE
            ");
            foreach ($items as $item) {
                $itemId = (int)($item['item_id'] ?? $item['id'] ?? 0);
                $qty = (int)($item['quantity'] ?? 0);
                if ($itemId <= 0 || $qty <= 0) {
                    throw new \Exception('Invalid item or quantity');
                }
                $lockStmt->execute([$itemId, $propertyId]);
                $product = $lockStmt->fetch(PDO::FETCH_ASSOC);
                if (!$product) {
                    throw new \Exception('Product not found');
                }
                $available = $product['pi_id'] !== null ? (int)$product['stock_quantity'] : (int)$product['stock_qty'];
                if ($available < $qty) {
                    throw new \Exception("Product '{$product['name']}' only has {$available} units left in stock.");
                }
                $price = (float)$product['selling_price'];
                if ($price <= 0) {
                    throw new \Exception("Product '{$product['name']}' is not available for sale.");
                }
                $total += $qty * $price;
                $lineItems[] = [
                    'item_id' => (int)$product['id'],
                    'quantity' => $qty,
                    'price_per_unit' => $price,
                    'name' => $product['name'],
                    'pi_id' => $product['pi_id']
                ];
            }
            if (empty($lineItems)) {
                throw new \Exception('No valid items with price found');
            }

            // Create POS order record
            $oStmt = $db->prepare("
                INSERT INTO pos_orders (property_id, outlet_id, booking_id, total_amount, payment_method, status, source)
                VALUES (:pid, :oid, :bid, :total, 'room_charge', 'posted', 'admin')
            ");
            $oStmt->execute([
                'pid'   => $propertyId,
                'oid'   => $outletId,
                'bid'   => $bookingId,
                'total' => $total
            ]);
            $orderId = (int)$db->lastInsertId();
            SequenceGenerator::assignDisplayId($db, 'pos_orders', $orderId, 'SEQ_POS_ORDER_FORMAT', 'display_id');

            // Fetch display_id
            $dispStmt = $db->prepare("SELECT display_id FROM pos_orders WHERE id = ?");
            $dispStmt->execute([$orderId]);
            $orderDisplayId = $dispStmt->fetchColumn() ?: 'POS-' . $orderId;

            // Insert order items
            $itmStmt = $db->prepare("
                INSERT INTO pos_order_items (order_id, item_id, quantity, price_per_unit)
                VALUES (:oid, :iid, :qty, :price)
            ");
            $deductInv = $db->prepare("UPDATE inventory_items SET stock_qty = stock_qty - ? WHERE id = ? AND property_id = ?");
            $deductPos = $db->prepare("UPDATE pos_inventory SET stock_quantity = stock_quantity - ? WHERE id = ?");
            foreach ($lineItems as $li) {
                $itmStmt->execute([
                    'oid'   => $orderId,
                    'iid'   => $li['item_id'],
                    'qty'   => $li['quantity'],
                    'price' => $li['price_per_unit']
                ]);
                if (!empty($li['pi_id'])) {
                    $deductPos->execute([$li['quantity'], $li['pi_id']]);
                } else {
                    $deductInv->execute([$li['quantity'], $li['item_id'], $propertyId]);
                }
            }

            // Build description for folio
            $itemNames = implode(', ', array_map(fn($i) => "{$i['name']} x{$i['quantity']}", $lineItems));
            $folioDesc = "POS Charge ({$orderDisplayId}): " . mb_substr($itemNames, 0, 180);

            // Post to folio ledger via FolioService
            FolioService::postCharge($db, $bookingId, $total, $folioDesc, 'pos_order');

            AuditLogger::log($_SESSION['user_id'], 'POS_POST_TO_ROOM', 'BOOKING', $bookingId, [
                'order_id'      => $orderId,
                'order_display' => $orderDisplayId,
                'total'         => $total,
                'items_count'   => count($lineItems),
                'source'        => 'assistant'
            ]);

            $db->commit();
            ApiResponse::success([
                'message'        => "Order {$orderDisplayId} posted to room",
                'order_id'       => $orderId,
                'order_display_id' => $orderDisplayId,
                'total'          => $total
            ]);

        } catch (\Exception $ex) {
            $db->rollBack();
            ApiResponse::error('Failed to post order: ' . $ex->getMessage());
        }
    }

    else {
        ApiResponse::error('Invalid action. Use: menu, active_rooms, post_order');
    }

}, true, true, false);
