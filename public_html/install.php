<?php
require_once __DIR__ . '/../pms_core/HttpScriptGuard.php';
require_once __DIR__ . '/../pms_core/Database.php';

echo "<h1>Database Setup</h1>";

try {
    $db = Database::getInstance()->getConnection();
    echo "<p>Connected to database successfully.</p>";
    
    // Read the schema file
    $schemaPath = __DIR__ . '/../pms_core/schema_master.sql';
    if (!file_exists($schemaPath)) {
        throw new Exception("Schema file not found at: $schemaPath");
    }
    
    $sql = "SET FOREIGN_KEY_CHECKS=0;\nDROP DATABASE IF EXISTS pms_db;\nCREATE DATABASE pms_db;\nUSE pms_db;\n" . file_get_contents($schemaPath) . "\nSET FOREIGN_KEY_CHECKS=1;";
    
    // Execute the SQL queries
    $db->exec($sql);
    
    echo "<p style='color:green;'><b>Schema imported successfully! The `staff_users` table and other required tables have been created.</b></p>";
    echo "<p>You can now go to the <a href='admin/login.php'>Login Page</a>.</p>";
    
    // Remove this file after installation for security
    // unlink(__FILE__);
} catch (Exception $e) {
    echo "<p style='color:red;'>Error during setup: " . htmlspecialchars((string)($e->getMessage())) . "</p>";
}
