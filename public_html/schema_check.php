<?php
require_once __DIR__ . '/../pms_core/Database.php';
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'micropms' AND COLUMN_NAME = 'property_id'");
    $tablesWithPropId = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'TABLE_NAME');
    
    $stmt = $db->query("SHOW TABLES");
    $allTables = array_column($stmt->fetchAll(PDO::FETCH_NUM), 0);
    
    $missing = array_diff($allTables, $tablesWithPropId);
    echo "WITH:\n" . implode("\n", $tablesWithPropId) . "\n\n";
    echo "MISSING:\n" . implode("\n", $missing) . "\n";
} catch (Exception $e) {
    echo $e->getMessage();
}
