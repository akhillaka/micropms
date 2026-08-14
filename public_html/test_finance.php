<?php
require_once __DIR__ . '/../pms_core/HttpScriptGuard.php';
require_once __DIR__ . '/../pms_core/Database.php';
$db = Database::getInstance()->getConnection();

$count = $db->query("SELECT COUNT(*) FROM folio_ledger")->fetchColumn();
echo "Total folio_ledger: " . $count . "\n";

$count2 = $db->query("SELECT COUNT(*) FROM finance_transactions")->fetchColumn();
echo "Total finance_transactions: " . $count2 . "\n";

$props = $db->query("SELECT id, name FROM properties")->fetchAll(PDO::FETCH_ASSOC);
print_r($props);

$dates = $db->query("SELECT MIN(recorded_at), MAX(recorded_at) FROM folio_ledger")->fetch();
print_r($dates);
