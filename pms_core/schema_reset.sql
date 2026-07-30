-- MicroPMS Reset Script
-- WARNING: This will delete ALL transactional data.
-- It keeps properties, staff, system settings, and pos/inventory configuration intact.

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE `audit_logs`;
TRUNCATE TABLE `folio_ledger`;
TRUNCATE TABLE `finance_transactions`;
TRUNCATE TABLE `housekeeping_logs`;
TRUNCATE TABLE `pos_order_items`;
TRUNCATE TABLE `pos_orders`;
TRUNCATE TABLE `bookings`;
TRUNCATE TABLE `guests`;

-- Optionally, if you also want to delete all properties and start completely fresh:
-- DELETE FROM `properties` WHERE `id` > 1; -- Keep default property
-- DELETE FROM `staff_users` WHERE `access_level` != 'superadmin';

SET FOREIGN_KEY_CHECKS = 1;
