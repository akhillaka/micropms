<?php
declare(strict_types=1);

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../pms_core/Database.php';
require_once __DIR__ . '/../../pms_core/AuthHelper.php';
require_once __DIR__ . '/../../pms_core/CsrfToken.php';
require_once __DIR__ . '/../../pms_core/services/FolioService.php';
require_once __DIR__ . '/../../pms_core/AuditLogger.php';

// Auth checks
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$headers = getallheaders();
$csrfToken = $headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? '';
if (!CsrfToken::verify($csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'CSRF verification failed. Please refresh the page.']);
    exit;
}

$db = Database::getInstance()->getConnection();
$propertyId = AuthHelper::getPropertyId();

if ($propertyId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid property context.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';

if (empty($action)) {
    echo json_encode(['success' => false, 'message' => 'Missing action parameter.']);
    exit;
}

try {
    if ($action === 'add_inventory_item') {
        if (!AuthHelper::can('manage_settings')) {
            throw new Exception("Unauthorized to add products.");
        }

        $name = trim($data['name'] ?? '');
        $sku = trim($data['sku'] ?? '');
        $outletId = isset($data['outlet_id']) ? (int)$data['outlet_id'] : null;
        $stockQty = (int)($data['stock_qty'] ?? 0);
        $costPrice = floatval($data['cost_price'] ?? 0);
        $sellingPrice = floatval($data['selling_price'] ?? 0);
        $threshold = (int)($data['low_stock_threshold'] ?? 5);
        $imageUrl = trim($data['image_url'] ?? '');

        if (empty($name) || $sellingPrice <= 0) {
            throw new Exception("Product name and selling price are required.");
        }

        $ins = $db->prepare("
            INSERT INTO inventory_items (property_id, outlet_id, name, sku, stock_qty, low_stock_threshold, cost_price, selling_price, image_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$propertyId, $outletId, $name, $sku, $stockQty, $threshold, $costPrice, $sellingPrice, $imageUrl]);

        AuditLogger::log((int)$_SESSION['user_id'], 'POS_ADD_INVENTORY', 'INVENTORY', $db->lastInsertId(), [
            'name' => $name,
            'qty' => $stockQty
        ], $propertyId);

        echo json_encode(['success' => true, 'message' => 'Product added to stock.']);
        exit;

    } elseif ($action === 'save_pos_settings') {
        if (!AuthHelper::can('manage_settings')) {
            throw new Exception("Unauthorized to modify settings.");
        }
        $configs = [
            'POS_DEFAULT_TAX' => $data['POS_DEFAULT_TAX'] ?? '0',
            'POS_LOW_STOCK_DEFAULT' => $data['POS_LOW_STOCK_DEFAULT'] ?? '5',
            'POS_AUTO_POST_ROOM' => $data['POS_AUTO_POST_ROOM'] ?? 'true'
        ];
        
        $stmt = $db->prepare("
            INSERT INTO system_settings (key_name, key_value) 
            VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)
        ");
        foreach($configs as $key => $val) {
            $stmt->execute([$key, (string)$val]);
        }
        echo json_encode(['success' => true, 'message' => 'POS configurations saved successfully.']);
        exit;

    } elseif ($action === 'add_outlet') {
        $name = trim($data['name'] ?? '');
        if (empty($name)) {
            throw new Exception("Outlet name is required.");
        }
        $ins = $db->prepare("INSERT INTO pos_outlets (property_id, name) VALUES (?, ?)");
        $ins->execute([$propertyId, $name]);
        echo json_encode(['success' => true, 'message' => 'Shop outlet created.']);
        exit;

    } elseif ($action === 'edit_inventory_item') {
        $itemId = (int)($data['item_id'] ?? 0);
        if ($itemId <= 0) throw new Exception("Invalid item ID.");
        
        $name = trim($data['name'] ?? '');
        if (empty($name)) throw new Exception("Product name is required.");
        
        $sku = trim($data['sku'] ?? '');
        $outletId = !empty($data['outlet_id']) ? (int)$data['outlet_id'] : null;
        $stockQty = (int)($data['stock_qty'] ?? 0);
        $costPrice = (float)($data['cost_price'] ?? 0);
        $sellingPrice = (float)($data['selling_price'] ?? 0);
        $lowStock = (int)($data['low_stock_threshold'] ?? 5);
        $imageUrl = trim($data['image_url'] ?? '');

        $up = $db->prepare("
            UPDATE inventory_items 
            SET name = ?, sku = ?, outlet_id = ?, stock_qty = ?, cost_price = ?, selling_price = ?, low_stock_threshold = ?, image_url = ?
            WHERE id = ? AND property_id = ?
        ");
        $up->execute([$name, $sku, $outletId, $stockQty, $costPrice, $sellingPrice, $lowStock, $imageUrl, $itemId, $propertyId]);
        
        echo json_encode(['success' => true, 'message' => 'Product updated successfully.']);
        exit;

    } elseif ($action === 'delete_outlet') {
        if (!AuthHelper::can('manage_settings')) {
            throw new Exception("Unauthorized to delete outlets.");
        }
        $outletId = isset($data['outlet_id']) ? (int)$data['outlet_id'] : 0;
        if ($outletId <= 0) {
            throw new Exception("Invalid outlet selection.");
        }
        // Safely detach products by settings their outlet_id to null instead of deleting them, preventing orphan integrity errors.
        $db->prepare("UPDATE inventory_items SET outlet_id = NULL WHERE outlet_id = ?")->execute([$outletId]);
        
        $del = $db->prepare("DELETE FROM pos_outlets WHERE id = ? AND property_id = ?");
        $del->execute([$outletId, $propertyId]);
        echo json_encode(['success' => true, 'message' => 'Outlet shop deleted successfully.']);
        exit;

    } elseif ($action === 'update_order_status') {
        $orderId = (int)($data['order_id'] ?? 0);
        $status = $data['status'] ?? ''; // delivered, cancelled

        if ($orderId <= 0 || !in_array($status, ['delivered', 'cancelled', 'pending'])) {
            throw new Exception("Invalid order status update.");
        }

        $up = $db->prepare("UPDATE pos_orders SET delivery_status = ? WHERE id = ? AND property_id = ?");
        $up->execute([$status, $orderId, $propertyId]);

        AuditLogger::log((int)$_SESSION['user_id'], 'POS_ORDER_STATUS_UPDATE', 'POS_ORDER', $orderId, [
            'delivery_status' => $status
        ], $propertyId);

        echo json_encode(['success' => true, 'message' => "Order marked as {$status}."]);
        exit;

    } elseif ($action === 'delete_order') {
        if (!AuthHelper::can('manage_finance')) throw new Exception("Unauthorized to modify financial records.");
        $orderId = (int)($data['order_id'] ?? 0);
        if ($orderId <= 0) throw new Exception("Invalid order ID.");

        $db->beginTransaction();

        $stmt = $db->prepare("SELECT * FROM pos_orders WHERE id = ? AND property_id = ?");
        $stmt->execute([$orderId, $propertyId]);
        $oldOrder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$oldOrder) throw new Exception("Order not found or permission denied.");

        $oldMethod = $oldOrder['payment_method'];
        $oldTotal = (float)$oldOrder['total_amount'];
        $bookingId = $oldOrder['booking_id'];

        // 1. Restock items
        $stmtItems = $db->prepare("SELECT item_id, quantity FROM pos_order_items WHERE order_id = ?");
        $stmtItems->execute([$orderId]);
        $oldItems = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $addStock = $db->prepare("UPDATE inventory_items SET stock_qty = stock_qty + ? WHERE id = ?");
        foreach ($oldItems as $oi) {
            $addStock->execute([$oi['quantity'], $oi['item_id']]);
        }

        // 2. Locate and Delete financial records
        $oldFolioId = null;
        $oldFinanceId = null;

        if ($oldMethod === 'room_charge' && $bookingId) {
            $stmt = $db->prepare("SELECT id FROM folio_ledger WHERE booking_id = ? AND amount = ? AND (description LIKE '%POS Sales charge%' OR description LIKE '%Order #{$orderId}%') ORDER BY id DESC LIMIT 1");
            $stmt->execute([$bookingId, $oldTotal]);
            $row = $stmt->fetch();
            if ($row) $oldFolioId = $row['id'];
        } else {
            $stmt = $db->prepare("SELECT id FROM finance_transactions WHERE description LIKE ? AND amount = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute(["%Order #{$orderId}%", $oldTotal]);
            $row = $stmt->fetch();
            if ($row) $oldFinanceId = $row['id'];
        }

        if ($oldFolioId) {
            $db->prepare("DELETE FROM folio_ledger WHERE id = ?")->execute([$oldFolioId]);
        }
        if ($oldFinanceId) {
            $db->prepare("DELETE FROM finance_transactions WHERE id = ?")->execute([$oldFinanceId]);
        }

        // 3. Delete order
        $db->prepare("DELETE FROM pos_order_items WHERE order_id = ?")->execute([$orderId]);
        $db->prepare("DELETE FROM pos_orders WHERE id = ?")->execute([$orderId]);

        AuditLogger::log((int)$_SESSION['user_id'], 'POS_ORDER_DELETE', 'POS_ORDER', $orderId, ['deleted_total' => $oldTotal], $propertyId);

        $db->commit();
        echo json_encode(['success' => true, 'message' => "Order #{$orderId} deleted successfully."]);
        exit;

    } elseif ($action === 'get_order_full') {
        $orderId = (int)($data['order_id'] ?? 0);
        if ($orderId <= 0) throw new Exception("Invalid order ID.");

        $stmt = $db->prepare("SELECT * FROM pos_orders WHERE id = ? AND property_id = ?");
        $stmt->execute([$orderId, $propertyId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) throw new Exception("Order not found.");

        $stmtItems = $db->prepare("
            SELECT oi.*, i.name as product_name, i.stock_qty as current_stock, i.selling_price
            FROM pos_order_items oi
            JOIN inventory_items i ON oi.item_id = i.id
            WHERE oi.order_id = ?
        ");
        $stmtItems->execute([$orderId]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'order' => $order, 'items' => $items]);
        exit;

    } elseif ($action === 'edit_order_full') {
        if (!AuthHelper::can('manage_finance')) throw new Exception("Unauthorized to modify financial records.");
        $orderId = (int)($data['order_id'] ?? 0);
        $method = $data['payment_method'] ?? 'cash';
        $status = $data['delivery_status'] ?? 'delivered';
        $items = $data['items'] ?? [];

        if ($orderId <= 0 || empty($items)) {
            throw new Exception("Invalid order data or empty cart.");
        }

        $db->beginTransaction();

        // 1. Fetch old order
        $stmt = $db->prepare("SELECT * FROM pos_orders WHERE id = ? AND property_id = ? FOR UPDATE");
        $stmt->execute([$orderId, $propertyId]);
        $oldOrder = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$oldOrder) throw new Exception("Order not found.");

        $oldMethod = $oldOrder['payment_method'];
        $oldTotal = (float)$oldOrder['total_amount'];
        $bookingId = $oldOrder['booking_id'];

        // 2. Revert old stock
        $stmtOldItems = $db->prepare("SELECT item_id, quantity FROM pos_order_items WHERE order_id = ?");
        $stmtOldItems->execute([$orderId]);
        $oldItems = $stmtOldItems->fetchAll(PDO::FETCH_ASSOC);

        $addStock = $db->prepare("UPDATE inventory_items SET stock_qty = stock_qty + ? WHERE id = ?");
        foreach ($oldItems as $oi) {
            $addStock->execute([$oi['quantity'], $oi['item_id']]);
        }

        // Delete old items
        $db->prepare("DELETE FROM pos_order_items WHERE order_id = ?")->execute([$orderId]);

        // 3. Locate old financial records
        $oldFolioId = null;
        $oldFinanceId = null;

        if ($oldMethod === 'room_charge' && $bookingId) {
            $stmt = $db->prepare("SELECT id FROM folio_ledger WHERE booking_id = ? AND amount = ? AND (description LIKE '%POS Sales charge%' OR description LIKE '%Order #{$orderId}%') ORDER BY id DESC LIMIT 1");
            $stmt->execute([$bookingId, $oldTotal]);
            $row = $stmt->fetch();
            if ($row) $oldFolioId = $row['id'];
        } else {
            $stmt = $db->prepare("SELECT id FROM finance_transactions WHERE description LIKE ? AND amount = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute(["%Order #{$orderId}%", $oldTotal]);
            $row = $stmt->fetch();
            if ($row) $oldFinanceId = $row['id'];
        }

        // 4. Validate new stock limits and calculate new total
        $totalAmount = 0.0;
        $validatedItems = [];
        $deductStock = $db->prepare("UPDATE inventory_items SET stock_qty = stock_qty - ? WHERE id = ?");
        $insLine = $db->prepare("INSERT INTO pos_order_items (order_id, item_id, quantity, price_per_unit) VALUES (?, ?, ?, ?)");

        foreach ($items as $cartItem) {
            $itemId = (int)$cartItem['id'];
            $qty = (int)$cartItem['quantity'];

            $st = $db->prepare("SELECT * FROM inventory_items WHERE id = ? AND property_id = ? FOR UPDATE");
            $st->execute([$itemId, $propertyId]);
            $product = $st->fetch(PDO::FETCH_ASSOC);

            if (!$product) throw new Exception("Product ID {$itemId} not found.");
            
            if ($product['stock_qty'] < $qty) {
                throw new Exception("Product '{$product['name']}' only has {$product['stock_qty']} units left.");
            }

            $pricePerUnit = (float)$product['selling_price'];
            $totalAmount += $pricePerUnit * $qty;

            $validatedItems[] = ['id' => $itemId, 'name' => $product['name'], 'qty' => $qty];

            $insLine->execute([$orderId, $itemId, $qty, $pricePerUnit]);
            $deductStock->execute([$qty, $itemId]);
        }

        // 5. Update pos_order
        $db->prepare("UPDATE pos_orders SET total_amount = ?, payment_method = ?, delivery_status = ? WHERE id = ?")->execute([$totalAmount, $method, $status, $orderId]);

        // 6. Update or Create financial records
        $itemSummaries = [];
        foreach ($validatedItems as $vi) {
            $itemSummaries[] = "{$vi['name']} x{$vi['qty']}";
        }
        $newDescFolio = "POS Sales charge (Order #{$orderId}): " . implode(', ', $itemSummaries);
        $newDescFinance = "POS Direct Sale - Order #{$orderId}";

        if ($method === $oldMethod) {
            // Update existing record
            if ($method === 'room_charge' && $oldFolioId) {
                $db->prepare("UPDATE folio_ledger SET amount = ?, description = ? WHERE id = ?")
                   ->execute([$totalAmount, $newDescFolio, $oldFolioId]);
            } elseif ($method !== 'room_charge' && $oldFinanceId) {
                $db->prepare("UPDATE finance_transactions SET amount = ?, payment_method = ?, description = ? WHERE id = ?")
                   ->execute([$totalAmount, $method, $newDescFinance, $oldFinanceId]);
            } else {
                // Fallback if not found
                if ($method === 'room_charge') {
                    if (!$bookingId) throw new Exception("Room charge selected but booking was not chosen.");
                    FolioService::postCharge($db, $bookingId, $totalAmount, $newDescFolio, 'pos_order');
                } else {
                    $insFinance = $db->prepare("INSERT INTO finance_transactions (type, category, amount, description, payment_method, staff_id) VALUES ('income', 'pos', ?, ?, ?, ?)");
                    $insFinance->execute([$totalAmount, $newDescFinance, $method, (int)$_SESSION['user_id']]);
                }
            }
        } else {
            // Delete old, insert new
            if ($oldFolioId) {
                $db->prepare("DELETE FROM folio_ledger WHERE id = ?")->execute([$oldFolioId]);
            }
            if ($oldFinanceId) {
                $db->prepare("DELETE FROM finance_transactions WHERE id = ?")->execute([$oldFinanceId]);
            }
            
            if ($method === 'room_charge') {
                if (!$bookingId) throw new Exception("Room charge selected but booking was not chosen.");
                FolioService::postCharge($db, $bookingId, $totalAmount, $newDescFolio, 'pos_order');
            } else {
                $insFinance = $db->prepare("INSERT INTO finance_transactions (type, category, amount, description, payment_method, staff_id) VALUES ('income', 'pos', ?, ?, ?, ?)");
                $insFinance->execute([$totalAmount, $newDescFinance, $method, (int)$_SESSION['user_id']]);
            }
        }

        AuditLogger::log((int)$_SESSION['user_id'], 'POS_ORDER_EDIT_FULL', 'POS_ORDER', $orderId, ['new_total' => $totalAmount], $propertyId);

        $db->commit();
        echo json_encode(['success' => true, 'message' => "Order #{$orderId} updated successfully."]);
        exit;

    } elseif ($action === 'restock_item') {
        $itemId = (int)($data['item_id'] ?? 0);
        $qty = (int)($data['quantity'] ?? 0);

        if ($itemId <= 0 || $qty <= 0) {
            throw new Exception("Invalid item or stock quantity.");
        }

        $up = $db->prepare("UPDATE inventory_items SET stock_qty = stock_qty + ? WHERE id = ? AND property_id = ?");
        $up->execute([$qty, $itemId, $propertyId]);

        AuditLogger::log((int)$_SESSION['user_id'], 'POS_RESTOCK_INVENTORY', 'INVENTORY', $itemId, [
            'restock_qty' => $qty
        ], $propertyId);

        echo json_encode(['success' => true, 'message' => 'Stock updated.']);
        exit;

    } elseif ($action === 'create_pos_order') {
        if (!AuthHelper::can('manage_finance')) throw new Exception("Unauthorized to modify financial records.");
        $method = $data['method'] ?? 'cash';
        $outletId = isset($data['outlet_id']) ? (int)$data['outlet_id'] : null;
        $bookingId = isset($data['booking_id']) ? (int)$data['booking_id'] : null;
        $items = $data['items'] ?? [];

        if (empty($items)) {
            throw new Exception("No products in order.");
        }

        $db->beginTransaction();

        // 1. Validate stock limits and calculate order total
        $totalAmount = 0.0;
        $validatedItems = [];

        foreach ($items as $cartItem) {
            $itemId = (int)$cartItem['id'];
            $qty = (int)$cartItem['quantity'];

            $stmt = $db->prepare("SELECT * FROM inventory_items WHERE id = ? AND property_id = ? FOR UPDATE");
            $stmt->execute([$itemId, $propertyId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$product) {
                throw new Exception("Product ID {$itemId} not found.");
            }

            if ($product['stock_qty'] < $qty) {
                throw new Exception("Product '{$product['name']}' only has {$product['stock_qty']} units left in stock.");
            }

            $pricePerUnit = (float)$product['selling_price'];
            $totalAmount += $pricePerUnit * $qty;

            $validatedItems[] = [
                'id' => $itemId,
                'name' => $product['name'],
                'qty' => $qty,
                'price_per_unit' => $pricePerUnit
            ];
        }

        // 2. Insert POS Order record
        $status = ($method === 'room_charge') ? 'posted' : 'paid';
        $insOrder = $db->prepare("
            INSERT INTO pos_orders (property_id, outlet_id, booking_id, total_amount, payment_method, status, source, delivery_status)
            VALUES (?, ?, ?, ?, ?, ?, 'admin', 'delivered')
        ");
        $insOrder->execute([$propertyId, $outletId, $bookingId, $totalAmount, $method, $status]);
        $orderId = (int)$db->lastInsertId();

        // 3. Deduct stock levels and record order line items
        $insLine = $db->prepare("
            INSERT INTO pos_order_items (order_id, item_id, quantity, price_per_unit)
            VALUES (?, ?, ?, ?)
        ");
        $deductStock = $db->prepare("UPDATE inventory_items SET stock_qty = stock_qty - ? WHERE id = ?");

        foreach ($validatedItems as $vi) {
            $insLine->execute([$orderId, $vi['id'], $vi['qty'], $vi['price_per_unit']]);
            $deductStock->execute([$vi['qty'], $vi['id']]);
        }

        // 4. Charge Room Folio if selected
        if ($method === 'room_charge') {
            if (!$bookingId) {
                throw new Exception("Room charge selected but booking was not chosen.");
            }

            $itemSummaries = [];
            foreach ($validatedItems as $vi) {
                $itemSummaries[] = "{$vi['name']} x{$vi['qty']}";
            }
            $description = "POS Sales charge (Order #{$orderId}): " . implode(', ', $itemSummaries);
            FolioService::postCharge($db, $bookingId, $totalAmount, $description, 'pos_order');
        } else {
            $insFinance = $db->prepare("
                INSERT INTO finance_transactions (type, category, amount, description, payment_method, staff_id)
                VALUES ('income', 'pos', ?, ?, ?, ?)
            ");
            $desc = "POS Direct Sale - Order #{$orderId}";
            $insFinance->execute([$totalAmount, $desc, $method, (int)$_SESSION['user_id']]);
        }

        AuditLogger::log((int)$_SESSION['user_id'], 'POS_CREATE_ORDER', 'POS_ORDER', $orderId, [
            'method' => $method,
            'total' => $totalAmount,
            'property_id' => $propertyId
        ]);

        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Order placed successfully!']);
        exit;
    }

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
