<?php
require_once __DIR__ . '/../pms_core/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Starting patch...<br><br>";

    // Fix 1: Audit Logs ENUM issue
    try {
        $db->exec("ALTER TABLE audit_logs MODIFY COLUMN entity_type VARCHAR(50) NOT NULL");
        echo "<span style='color:green;'>SUCCESS: audit_logs entity_type is now VARCHAR.</span><br>";
    } catch (Exception $e) {
        echo "<span style='color:orange;'>NOTICE: audit_logs fix skipped (might already be fixed) - " . $e->getMessage() . "</span><br>";
    }

    // Fix 2: Finance Category issue
    try {
        $stmtCategory = $db->query("SHOW COLUMNS FROM finance_transactions LIKE 'category'");
        if ($stmtCategory->rowCount() == 0) {
            $db->exec("ALTER TABLE finance_transactions ADD COLUMN category VARCHAR(50) DEFAULT 'general'");
            echo "<span style='color:green;'>SUCCESS: Added category to finance_transactions.</span><br>";
        } else {
            echo "<span style='color:green;'>SUCCESS: finance_transactions already has category column.</span><br>";
        }
    } catch (Exception $e) {
        echo "<span style='color:orange;'>NOTICE: finance_transactions fix skipped - " . $e->getMessage() . "</span><br>";
    }

    // Fix 3: Staff Users Enum issue (SaaS Admin Staff Add)
    try {
        $db->exec("ALTER TABLE staff_users MODIFY COLUMN access_level ENUM('superadmin','owner','admin','manager','receptionist','housekeeping','front_desk','fb_cashier','night_auditor') NOT NULL DEFAULT 'manager'");
        echo "<span style='color:green;'>SUCCESS: staff_users access_level ENUM updated.</span><br>";
    } catch (Exception $e) {
        echo "<span style='color:orange;'>NOTICE: staff_users fix skipped - " . $e->getMessage() . "</span><br>";
    }
    
    // Fix 4: Table level unique constraints for multi-property isolation
    try {
        $db->exec("ALTER TABLE room_categories DROP INDEX name, ADD UNIQUE KEY idx_property_name (property_id, name)");
        echo "<span style='color:green;'>SUCCESS: room_categories property isolation added.</span><br>";
    } catch (Exception $e) {
        echo "<span style='color:orange;'>NOTICE: room_categories fix skipped - " . $e->getMessage() . "</span><br>";
    }
    
    try {
        $db->exec("ALTER TABLE rooms DROP INDEX room_number, ADD UNIQUE KEY idx_property_room (property_id, room_number)");
        echo "<span style='color:green;'>SUCCESS: rooms property isolation added.</span><br>";
    } catch (Exception $e) {
        echo "<span style='color:orange;'>NOTICE: rooms fix skipped - " . $e->getMessage() . "</span><br>";
    }
    
    // Fix 5: Add category to folio_ledger for split payments
    try {
        $db->exec("ALTER TABLE folio_ledger ADD COLUMN IF NOT EXISTS category VARCHAR(50) DEFAULT 'booking'");
        echo "<span style='color:green;'>SUCCESS: folio_ledger category column added.</span><br>";
    } catch (Exception $e) {
        echo "<span style='color:orange;'>NOTICE: folio_ledger fix skipped - " . $e->getMessage() . "</span><br>";
    }

    echo "<br><strong style='color: blue;'>Patch applied successfully. Please refresh your POS page.</strong>";

} catch (Exception $e) {
    echo "<strong style='color: red;'>Fatal Error:</strong> " . $e->getMessage() . "<br>";
}
