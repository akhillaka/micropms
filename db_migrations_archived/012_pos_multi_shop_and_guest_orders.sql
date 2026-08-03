-- Migration 012: POS Multi-Shop Outlets & Guest Portal Ordering

CREATE TABLE IF NOT EXISTS `pos_outlets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `property_id` INT NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add outlet_id and image fields to inventory_items
ALTER TABLE `inventory_items` 
ADD COLUMN `outlet_id` INT NULL AFTER `property_id`,
ADD COLUMN `image_url` VARCHAR(500) NULL AFTER `selling_price`,
ADD CONSTRAINT `fk_inventory_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `pos_outlets`(`id`) ON DELETE SET NULL;

-- Add outlet_id, delivery_status, and source to pos_orders
ALTER TABLE `pos_orders`
ADD COLUMN `outlet_id` INT NULL AFTER `property_id`,
ADD COLUMN `source` ENUM('admin', 'guest_portal') NOT NULL DEFAULT 'admin' AFTER `status`,
ADD COLUMN `delivery_status` ENUM('delivered', 'pending', 'cancelled') NOT NULL DEFAULT 'delivered' AFTER `source`,
ADD CONSTRAINT `fk_orders_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `pos_outlets`(`id`) ON DELETE SET NULL;

-- Seed default outlets for existing properties
INSERT INTO `pos_outlets` (`property_id`, `name`)
SELECT id, 'Restaurant' FROM properties
UNION ALL
SELECT id, 'Cool Drink Shop' FROM properties
UNION ALL
SELECT id, 'General Store' FROM properties;
