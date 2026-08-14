<?php
require_once __DIR__ . '/pms_core/HttpScriptGuard.php';
require 'pms_core/Database.php';
$db = Database::getInstance()->getConnection();

// Update any finance_transactions missing property_id but having booking_id
$stmt = $db->query("
    UPDATE finance_transactions ft
    JOIN bookings b ON ft.booking_id = b.id
    SET ft.property_id = b.property_id
    WHERE ft.property_id IS NULL OR ft.property_id = 0
");
echo "Updated " . $stmt->rowCount() . " rows in finance_transactions.\n";

// Update any folio_ledger missing property_id but having booking_id
$stmt2 = $db->query("
    UPDATE folio_ledger fl
    JOIN bookings b ON fl.booking_id = b.id
    SET fl.property_id = b.property_id
    WHERE fl.property_id IS NULL OR fl.property_id = 0
");
echo "Updated " . $stmt2->rowCount() . " rows in folio_ledger.\n";
