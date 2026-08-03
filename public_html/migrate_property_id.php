<?php
require_once __DIR__ . '/../pms_core/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    $tables = [
        'audit_logs',
        'bookings',
        'booking_notes',
        'city_ledger',
        'companies',
        'error_logs',
        'finance_transactions',
        'folio_ledger',
        'guests',
        'housekeeping_checklist_items',
        'housekeeping_log_items',
        'housekeeping_logs',
        'inventory_items',
        'login_attempts',
        'night_audit_log',
        'pos_order_items',
        'pos_orders',
        'pos_outlets',
        'room_categories',
        'room_maintenance',
        'rooms',
        'sequence_counters',
        'sliding_rates',
        'staff_users',
        'system_settings',
        'wa_automation_events',
        'wa_automations',
        'wa_conversations',
        'wa_delivery_logs',
        'wa_messages',
        'wa_templates',
        'payment_gateway_configs'
    ];

    echo "<pre>";
    echo "Starting Property ID Migration...\n\n";

    foreach ($tables as $table) {
        try {
            // First check if table exists
            $stmt = $db->query("SHOW TABLES LIKE '{$table}'");
            if ($stmt->rowCount() > 0) {
                if ($table === 'system_settings') {
                    // Check if PK is just key_name
                    $pkStmt = $db->query("SHOW KEYS FROM `system_settings` WHERE Key_name = 'PRIMARY'");
                    $pkColumns = array_column($pkStmt->fetchAll(PDO::FETCH_ASSOC), 'Column_name');
                    if (count($pkColumns) === 1 && $pkColumns[0] === 'key_name') {
                        // Drop primary key and create composite PK
                        $db->exec("ALTER TABLE `system_settings` DROP PRIMARY KEY");
                        $db->exec("ALTER TABLE `system_settings` ADD COLUMN IF NOT EXISTS `property_id` INT NOT NULL DEFAULT 1");
                        $db->exec("ALTER TABLE `system_settings` ADD PRIMARY KEY (`property_id`, `key_name`)");
                        echo "[UPDATED] Replaced PK on system_settings with composite (property_id, key_name)\n";
                    } else {
                        // Just make sure column exists
                        $colStmt = $db->query("SHOW COLUMNS FROM `system_settings` LIKE 'property_id'");
                        if ($colStmt->rowCount() == 0) {
                            $db->exec("ALTER TABLE `system_settings` ADD COLUMN `property_id` INT NOT NULL DEFAULT 1");
                            echo "[ADDED] Added property_id column to system_settings\n";
                        } else {
                            echo "[OK] Column property_id already exists in system_settings\n";
                        }
                    }
                } else {
                    // Check if property_id column exists
                    $colStmt = $db->query("SHOW COLUMNS FROM `{$table}` LIKE 'property_id'");
                    if ($colStmt->rowCount() == 0) {
                        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `property_id` INT DEFAULT 1");
                        echo "[ADDED] Added property_id column to `{$table}`\n";
                    } else {
                        echo "[OK] Column property_id already exists in `{$table}`\n";
                    }
                }
            } else {
                echo "[SKIP] Table `{$table}` does not exist.\n";
            }
        } catch (Exception $e) {
            echo "[ERROR] Error updating `{$table}`: " . $e->getMessage() . "\n";
        }
    }
    echo "\nMigration complete!</pre>";
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage();
}
