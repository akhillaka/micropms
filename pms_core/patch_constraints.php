<?php
require_once __DIR__ . '/HttpScriptGuard.php';
require_once __DIR__ . '/Database.php';
$db = Database::getInstance()->getConnection();

// Function to safely execute SQL and ignore errors if constraints don't exist
function execSql($db, $sql) {
    try {
        $db->exec($sql);
        echo "SUCCESS: $sql\n";
    } catch (PDOException $e) {
        echo "ERROR: {$e->getMessage()}\n";
    }
}

// 1. rooms table constraints
execSql($db, "ALTER TABLE `rooms` DROP INDEX `room_number`");
execSql($db, "ALTER TABLE `rooms` ADD UNIQUE KEY `property_room_idx` (`property_id`, `room_number`)");

// 2. room_categories table constraints
execSql($db, "ALTER TABLE `room_categories` DROP INDEX `name`");
execSql($db, "ALTER TABLE `room_categories` ADD UNIQUE KEY `property_name_idx` (`property_id`, `name`)");

// 3. city_ledger constraints (delete cascade on booking_id)
// It doesn't have an FK on booking_id currently. Let's add it.
execSql($db, "ALTER TABLE `city_ledger` ADD CONSTRAINT `city_ledger_booking_fk` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE");

// 4. finance_transactions constraints (delete cascade on booking_id)
// First ensure there is an index on booking_id if not already there
execSql($db, "ALTER TABLE `finance_transactions` ADD INDEX `booking_id_idx` (`booking_id`)");
execSql($db, "ALTER TABLE `finance_transactions` ADD CONSTRAINT `finance_tx_booking_fk` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE");

// 5. pos_orders constraints
// Current FK is ON DELETE SET NULL, we want ON DELETE CASCADE
// We need to drop the old constraint first. We don't know the name exactly, but standard is pos_orders_ibfk_something.
// Let's find the constraint name first.
$stmt = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_NAME = 'pos_orders' AND COLUMN_NAME = 'booking_id' AND REFERENCED_TABLE_NAME = 'bookings'");
if ($row = $stmt->fetch()) {
    $fkName = $row['CONSTRAINT_NAME'];
    execSql($db, "ALTER TABLE `pos_orders` DROP FOREIGN KEY `$fkName`");
    execSql($db, "ALTER TABLE `pos_orders` ADD CONSTRAINT `$fkName` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE");
}

// 6. night_audit_actions constraints
execSql($db, "ALTER TABLE `night_audit_actions` ADD CONSTRAINT `na_actions_booking_fk` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE");

echo "Done fixing constraints.\n";
