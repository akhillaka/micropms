<?php
$db = \Database::getInstance()->getConnection();
try {
    $db->exec("ALTER TABLE guests ADD COLUMN id_proof_signature VARCHAR(255) NULL AFTER id_proof_back");
} catch (\Exception $e) {
    // Column already exists, ignore
}
