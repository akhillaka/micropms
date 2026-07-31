<?php
require_once __DIR__ . '/pms_core/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    $sql = "
    CREATE TABLE IF NOT EXISTS jobs_queue (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(50) NOT NULL,
        payload JSON NOT NULL,
        status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
        attempts INT DEFAULT 0,
        max_attempts INT DEFAULT 3,
        error_log TEXT,
        available_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status_available (status, available_at)
    ) ENGINE=InnoDB;
    ";
    $db->exec($sql);
    echo "jobs_queue table created successfully.\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
