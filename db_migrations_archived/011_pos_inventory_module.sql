-- Migration 011: Integrated Micro POS and Inventory Management Module

CREATE TABLE IF NOT EXISTS `inventory_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `property_id` INT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `sku` VARCHAR(100) NULL,
    `stock_qty` INT NOT NULL DEFAULT 0,
    `low_stock_threshold` INT NOT NULL DEFAULT 5,
    `cost_price` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `selling_price` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `property_id` INT NOT NULL,
    `booking_id` INT NULL, -- NULL if walk-in direct purchase
    `total_amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `payment_method` ENUM('cash', 'card', 'upi', 'room_charge') NOT NULL DEFAULT 'cash',
    `status` ENUM('paid', 'posted') NOT NULL DEFAULT 'paid', -- posted if added to folio
    `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pos_order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `item_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `price_per_unit` DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (`order_id`) REFERENCES `pos_orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Indexing for quick lookups
CREATE INDEX `idx_inventory_property` ON `inventory_items`(`property_id`);
CREATE INDEX `idx_pos_orders_booking` ON `pos_orders`(`booking_id`);

-- Duplicate index guard (idempotent re-runs)
-- idx_inventory_property and idx_pos_orders_booking already created above
