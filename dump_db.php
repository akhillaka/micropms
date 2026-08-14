<?php
require_once __DIR__ . '/pms_core/HttpScriptGuard.php';
require 'pms_core/Database.php';
$db = Database::getInstance()->getConnection();

echo "FOLIO LEDGER:\n";
$stmt = $db->query("SELECT * FROM folio_ledger ORDER BY id DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\nFINANCE TRANSACTIONS:\n";
$stmt2 = $db->query("SELECT * FROM finance_transactions ORDER BY id DESC LIMIT 5");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

echo "\nBOOKING BKG-2608-1:\n";
$stmt3 = $db->prepare("SELECT * FROM bookings WHERE display_id = 'BKG-2608-1'");
$stmt3->execute();
print_r($stmt3->fetchAll(PDO::FETCH_ASSOC));
