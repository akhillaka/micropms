CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `staff_id` (`staff_id`),
  KEY `idx_audit_property_created` (`property_id`,`created_at`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_logs_ibfk_2` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_audit_logs_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `booking_notes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `note` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_booking_notes` (`booking_id`),
  KEY `fk_booking_notes_staff` (`staff_id`),
  CONSTRAINT `fk_booking_notes_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_booking_notes_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bookings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `display_id` varchar(50) DEFAULT NULL,
  `offline_folio_id` varchar(50) DEFAULT NULL,
  `room_id` int(11) NOT NULL,
  `guest_id` int(11) DEFAULT NULL,
  `check_in` datetime NOT NULL,
  `check_out` datetime NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `payment_status` enum('pending_hold','completed_paid','cancelled') DEFAULT 'pending_hold',
  `booking_status` enum('booked','checked_in','checked_out','cancelled') NOT NULL DEFAULT 'booked',
  `razorpay_order_id` varchar(50) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `tax_preference` enum('exclusive','inclusive','exempt') DEFAULT 'exclusive',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `checkout_warning_sent` tinyint(1) DEFAULT 0,
  `rate_plan_name` varchar(100) DEFAULT NULL,
  `booking_source` varchar(50) DEFAULT 'Walk-in',
  `price_override` decimal(10,2) DEFAULT NULL,
  `property_id` int(11) NOT NULL,
  `adults` int(11) NOT NULL DEFAULT 2,
  `children` int(11) NOT NULL DEFAULT 0,
  `extra_bed` tinyint(1) NOT NULL DEFAULT 0,
  `import_ref` varchar(80) DEFAULT NULL,
  `actual_checkout` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `guest_id` (`guest_id`),
  KEY `idx_collision_guard` (`room_id`,`check_in`,`check_out`,`payment_status`),
  KEY `company_id` (`company_id`),
  KEY `idx_bookings_dates` (`check_in`,`check_out`),
  KEY `idx_bookings_room_status` (`room_id`,`booking_status`),
  KEY `idx_bookings_property` (`property_id`),
  KEY `idx_bookings_razorpay_order` (`razorpay_order_id`),
  KEY `idx_bookings_combined_status` (`booking_status`,`payment_status`),
  KEY `idx_bookings_property_check_in` (`property_id`,`check_in`),
  KEY `idx_bookings_property_check_out` (`property_id`,`check_out`),
  KEY `idx_bookings_property_status` (`property_id`,`booking_status`),
  CONSTRAINT `bookings_company_fk` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bookings_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
  CONSTRAINT `bookings_ibfk_2` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`),
  CONSTRAINT `bookings_ibfk_3` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bookings_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `city_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `type` enum('charge','payment') NOT NULL,
  `status` enum('pending','paid') DEFAULT 'pending',
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `company_id` (`company_id`),
  KEY `booking_id` (`booking_id`),
  CONSTRAINT `city_ledger_company_fk` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `city_ledger_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_city_ledger_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact_details` varchar(255) DEFAULT NULL,
  `credit_limit` decimal(10,2) DEFAULT 0.00,
  `balance` decimal(10,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_id` (`property_id`),
  CONSTRAINT `companies_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_companies_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `error_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `severity` enum('info','warning','error','critical') NOT NULL DEFAULT 'error',
  `category` varchar(50) NOT NULL COMMENT 'payment|whatsapp|database|auth|booking|system',
  `message` text NOT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'booking_id, guest, amount, api_response, uri, etc.' CHECK (json_valid(`context`)),
  `staff_id` int(10) unsigned DEFAULT NULL,
  `request_uri` varchar(500) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `resolved` tinyint(1) NOT NULL DEFAULT 0,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_severity_cat` (`severity`,`category`),
  KEY `idx_unresolved` (`resolved`,`created_at`),
  KEY `idx_category` (`category`,`created_at`),
  KEY `property_id` (`property_id`),
  CONSTRAINT `error_logs_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_error_logs_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `finance_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('income','expense') NOT NULL,
  `category` varchar(50) NOT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` varchar(50) DEFAULT NULL,
  `property_id` int(11) NOT NULL,
  `display_id` varchar(50) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `staff_id` (`staff_id`),
  KEY `idx_finance_dates` (`recorded_at`),
  KEY `idx_finance_date` (`recorded_at`),
  KEY `idx_finance_property` (`property_id`),
  KEY `idx_finance_display_id` (`display_id`),
  KEY `booking_id` (`booking_id`),
  CONSTRAINT `finance_transactions_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `staff_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `finance_transactions_ibfk_2` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `finance_transactions_ibfk_3` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_finance_transactions_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_finance_tx_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `folio_ledger` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `transaction_type` enum('online','cash','card','upi','bank_transfer','payment','ROOM_CHARGE','INCIDENTAL','pos_order','pos_refund','TAX') NOT NULL DEFAULT 'payment',
  `amount` decimal(10,2) NOT NULL,
  `transaction_ref` varchar(100) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` varchar(255) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `folio_bucket` enum('main','incidentals') DEFAULT 'main',
  `is_refund` tinyint(1) DEFAULT 0,
  `display_id` varchar(50) DEFAULT NULL,
  `property_id` int(11) NOT NULL,
  `cgst_amount` decimal(10,2) DEFAULT 0.00,
  `sgst_amount` decimal(10,2) DEFAULT 0.00,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `booking_id` (`booking_id`),
  KEY `idx_folio_booking_type` (`booking_id`,`transaction_type`),
  KEY `idx_folio_recorded_at` (`recorded_at`),
  KEY `idx_folio_booking` (`booking_id`,`recorded_at`),
  KEY `idx_folio_display_id` (`display_id`),
  KEY `idx_folio_property_id` (`property_id`),
  UNIQUE KEY `uq_folio_booking_ref` (`booking_id`,`transaction_ref`),
  CONSTRAINT `fk_folio_ledger_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_folio_ledger_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folio_ledger_ibfk_1` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folio_ledger_ibfk_2` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `folio_ledger_ibfk_3` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `guests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'India',
  `pincode` varchar(10) DEFAULT NULL,
  `id_proof_front` varchar(255) DEFAULT NULL,
  `id_proof_back` varchar(255) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `id_number` varchar(50) DEFAULT NULL,
  `id_type` varchar(30) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `digital_signature` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL COMMENT 'Internal staff notes about guest preferences',
  `preferences` text DEFAULT NULL COMMENT 'JSON: dietary, room type, pillow, etc.',
  `tags` varchar(255) DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `display_id` varchar(50) DEFAULT NULL,
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guest_prop_phone` (`property_id`,`phone`),
  KEY `idx_guests_name` (`name`),
  KEY `idx_guests_phone_lookup` (`phone`),
  KEY `idx_guests_display_id` (`display_id`),
  CONSTRAINT `fk_guests_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `guests_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `guest_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guest_id` int(11) NOT NULL,
  `property_id` int(11) DEFAULT NULL,
  `document_type` varchar(50) NOT NULL DEFAULT 'id_proof',
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_guest_docs_guest` (`guest_id`,`document_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `guest_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `property_id` int(11) NOT NULL,
  `rating` tinyint(1) NOT NULL DEFAULT 5,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_reviews_booking` (`booking_id`),
  KEY `idx_reviews_property` (`property_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `email_report_config` (
  `property_id` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `daily_audit_emails` text DEFAULT NULL,
  `weekly_revenue_emails` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`property_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `housekeeping_checklist_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) DEFAULT NULL,
  `item_text` varchar(150) NOT NULL,
  `is_mandatory` tinyint(1) DEFAULT 1,
  `display_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `property_id` (`property_id`),
  CONSTRAINT `fk_housekeeping_checklist_items_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `housekeeping_checklist_items_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `housekeeping_log_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hk_log_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `is_checked` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `hk_log_id` (`hk_log_id`),
  CONSTRAINT `hk_log_items_fk_1` FOREIGN KEY (`hk_log_id`) REFERENCES `housekeeping_logs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `housekeeping_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `cleaned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `inspector_staff_id` int(11) DEFAULT NULL,
  `inspected_at` datetime DEFAULT NULL,
  `status` enum('in_progress','cleaned','inspected_ready') DEFAULT 'cleaned',
  `photo_proof` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `room_id` (`room_id`),
  KEY `staff_id` (`staff_id`),
  KEY `idx_hk_log_property` (`property_id`),
  CONSTRAINT `fk_hk_logs_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_housekeeping_logs_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `housekeeping_logs_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `housekeeping_logs_ibfk_2` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `idempotency_keys` (
  `property_id` int(11) NOT NULL,
  `idempotency_key` varchar(255) NOT NULL,
  `response_body` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`property_id`,`idempotency_key`),
  CONSTRAINT `fk_idempotency_keys_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `idempotency_keys_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `inventory_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `outlet_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `stock_qty` int(11) NOT NULL DEFAULT 0,
  `low_stock_threshold` int(11) NOT NULL DEFAULT 5,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `image_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inventory_property` (`property_id`),
  KEY `fk_inventory_outlet` (`outlet_id`),
  CONSTRAINT `fk_inventory_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `pos_outlets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_items_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_restock_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `qty_added` int(11) NOT NULL,
  `old_stock` int(11) NOT NULL,
  `new_stock` int(11) NOT NULL,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `restocked_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_restock_prop` (`property_id`),
  KEY `fk_restock_item` (`item_id`),
  CONSTRAINT `fk_restock_item` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_restock_prop` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `jobs_queue` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `queue_name` varchar(50) DEFAULT 'default',
  `property_id` int(11) DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload_json`)),
  `status` enum('pending','processing','completed','failed') DEFAULT 'pending',
  `attempts` int(11) DEFAULT 0,
  `max_attempts` int(11) DEFAULT 3,
  `available_at` datetime DEFAULT current_timestamp(),
  `dead_letter` tinyint(1) DEFAULT 0,
  `error_log` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_status_queue` (`status`,`queue_name`,`available_at`),
  KEY `idx_jobs_queue_property` (`property_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_username_time` (`username`,`attempted_at`),
  KEY `idx_ip_time` (`ip_address`,`attempted_at`),
  KEY `property_id` (`property_id`),
  CONSTRAINT `fk_login_attempts_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `login_attempts_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `night_audit_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `audit_date` date NOT NULL,
  `run_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `run_by` varchar(50) DEFAULT 'system' COMMENT 'system (cron) or staff username',
  `total_rooms` int(10) unsigned NOT NULL DEFAULT 0,
  `occupied_rooms` int(10) unsigned NOT NULL DEFAULT 0,
  `arrivals_today` int(10) unsigned NOT NULL DEFAULT 0,
  `departures_today` int(10) unsigned NOT NULL DEFAULT 0,
  `overdue_checkouts` int(10) unsigned NOT NULL DEFAULT 0,
  `auto_checkout_count` int(10) unsigned NOT NULL DEFAULT 0,
  `rooms_marked_dirty` int(10) unsigned NOT NULL DEFAULT 0,
  `revenue_collected` decimal(12,2) NOT NULL DEFAULT 0.00,
  `revenue_pending` decimal(12,2) NOT NULL DEFAULT 0.00,
  `actions_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Detailed actions taken during audit' CHECK (json_valid(`actions_json`)),
  `status` enum('success','partial','failed') NOT NULL DEFAULT 'success',
  `error_message` text DEFAULT NULL,
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_audit_prop_date` (`property_id`,`audit_date`),
  CONSTRAINT `fk_night_audit_log_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `night_audit_log_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payment_gateway_configs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `gateway` enum('razorpay','phonepe') NOT NULL,
  `mode` enum('test','live') NOT NULL DEFAULT 'test',
  `key_id` varchar(255) DEFAULT NULL COMMENT 'Razorpay Key ID or PhonePe Merchant ID',
  `key_secret` varchar(255) DEFAULT NULL COMMENT 'Razorpay Key Secret or PhonePe Salt Key',
  `extra_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Additional config (PhonePe: salt_index, redirect_url)' CHECK (json_valid(`extra_config`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_gateway` (`property_id`,`gateway`),
  KEY `idx_pgc_property` (`property_id`),
  CONSTRAINT `fk_payment_gateway_configs_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payment_gateway_configs_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pos_inventory` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  `reorder_level` int(11) DEFAULT 0,
  `reorder_quantity` int(11) DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_id` (`property_id`),
  KEY `item_id` (`item_id`),
  CONSTRAINT `fk_pos_inventory_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pos_inventory_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `pos_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `price_per_unit` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `item_id` (`item_id`),
  CONSTRAINT `pos_order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `pos_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pos_order_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pos_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `display_id` varchar(50) DEFAULT NULL,
  `property_id` int(11) NOT NULL,
  `outlet_id` int(11) DEFAULT NULL,
  `booking_id` int(11) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','card','upi','room_charge') NOT NULL DEFAULT 'cash',
  `status` enum('paid','posted') NOT NULL DEFAULT 'paid',
  `source` enum('admin','guest_portal') NOT NULL DEFAULT 'admin',
  `delivery_status` enum('delivered','pending','cancelled') NOT NULL DEFAULT 'delivered',
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pos_orders_booking` (`booking_id`),
  KEY `fk_orders_outlet` (`outlet_id`),
  KEY `idx_pos_orders_property` (`property_id`),
  KEY `idx_pos_orders_display` (`display_id`),
  CONSTRAINT `fk_orders_outlet` FOREIGN KEY (`outlet_id`) REFERENCES `pos_outlets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pos_orders_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pos_orders_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pos_orders_ibfk_2` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pos_orders_ibfk_3` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `pos_outlets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pos_outlets_property` (`property_id`),
  CONSTRAINT `pos_outlets_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `properties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'India',
  `pincode` varchar(10) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `gstin` varchar(20) DEFAULT NULL,
  `custom_domain` varchar(255) DEFAULT NULL,
  `dns_txt_token` varchar(64) DEFAULT NULL,
  `dns_verified_at` datetime DEFAULT NULL,
  `dns_status` varchar(40) NOT NULL DEFAULT 'unverified',
  `plan` varchar(50) NOT NULL DEFAULT 'starter',
  `max_rooms` int(11) NOT NULL DEFAULT 15,
  `max_staff` int(11) NOT NULL DEFAULT 5,
  `stripe_customer_id` varchar(100) DEFAULT NULL,
  `stripe_subscription_id` varchar(100) DEFAULT NULL,
  `subscription_status` varchar(50) NOT NULL DEFAULT 'trialing',
  `valid_until` datetime DEFAULT NULL,
  `features_json` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `is_exempt_from_billing` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `whatsapp_phone_number_id` varchar(100) DEFAULT NULL,
  `razorpay_key_id` varchar(100) DEFAULT NULL,
  `razorpay_key_secret` varchar(100) DEFAULT NULL,
  `trial_days` int(11) NOT NULL DEFAULT 14,
  `notes` text DEFAULT NULL COMMENT 'Internal superadmin notes',
  `timezone` varchar(100) NOT NULL DEFAULT 'Asia/Kolkata',
  `currency` varchar(10) NOT NULL DEFAULT 'INR',
  `logo_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `custom_domain` (`custom_domain`),
  KEY `idx_prop_custom_domain` (`custom_domain`),
  KEY `idx_prop_stripe` (`stripe_customer_id`,`stripe_subscription_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1001 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`permissions`)),
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_roles_property` (`property_id`),
  CONSTRAINT `roles_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `room_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `property_name_idx` (`property_id`, `name`),
  KEY `idx_room_cat_property` (`property_id`),
  CONSTRAINT `fk_room_categories_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `room_categories_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `room_maintenance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_id` int(11) NOT NULL,
  `property_id` int(11) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `external_uid` varchar(190) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_maint_dates` (`room_id`,`start_date`,`end_date`),
  KEY `idx_maint_property` (`property_id`),
  UNIQUE KEY `uq_maint_external` (`room_id`, `external_uid`),
  CONSTRAINT `room_maintenance_ibfk_1` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `rooms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `room_number` varchar(10) NOT NULL,
  `category_id` int(11) NOT NULL,
  `state` enum('clean','dirty','out_of_order') DEFAULT 'clean',
  `last_deep_clean` timestamp NULL DEFAULT NULL,
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `property_room_idx` (`property_id`, `room_number`),
  KEY `category_id` (`category_id`),
  KEY `idx_rooms_property` (`property_id`),
  CONSTRAINT `fk_rooms_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rooms_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `room_categories` (`id`),
  CONSTRAINT `rooms_ibfk_2` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `room_holds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `token` char(64) NOT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `check_in` datetime NOT NULL,
  `check_out` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_room_holds_token` (`token`),
  KEY `idx_room_holds_overlap` (`property_id`,`room_id`,`check_in`,`check_out`,`expires_at`),
  KEY `idx_room_holds_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `saas_feature_flags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) DEFAULT NULL,
  `flag_key` varchar(100) NOT NULL,
  `flag_value` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_property_flag` (`property_id`,`flag_key`),
  CONSTRAINT `saas_feature_flags_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `saas_leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hotel_name` varchar(190) NOT NULL,
  `contact_name` varchar(190) DEFAULT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `plan` varchar(40) NOT NULL DEFAULT 'starter',
  `rooms_estimate` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'new',
  `property_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_leads_status` (`status`),
  KEY `idx_leads_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `saas_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `gateway` enum('razorpay','phonepe','manual') NOT NULL DEFAULT 'manual',
  `gateway_sub_id` varchar(255) DEFAULT NULL,
  `plan` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'INR',
  `status` enum('active','trialing','past_due','cancelled','manual') NOT NULL DEFAULT 'trialing',
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_saas_sub_gateway` (`gateway_sub_id`),
  KEY `idx_saas_sub_property` (`property_id`),
  KEY `idx_saas_sub_status` (`status`),
  CONSTRAINT `fk_saas_sub_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `saved_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `dataset` varchar(50) NOT NULL,
  `columns` text NOT NULL,
  `filters` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `property_id` (`property_id`),
  CONSTRAINT `saved_reports_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sequence_counters` (
  `module` varchar(50) NOT NULL,
  `period` varchar(10) NOT NULL,
  `current_value` int(11) NOT NULL DEFAULT 0,
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`property_id`,`module`,`period`),
  CONSTRAINT `fk_sequence_counters_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sequence_counters_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `sliding_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `hours` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `rate_plan_name` varchar(100) DEFAULT NULL,
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_plan_hours` (`category_id`,`rate_plan_name`,`hours`),
  KEY `idx_rates_property` (`property_id`),
  CONSTRAINT `fk_sliding_rates_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sliding_rates_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `room_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sliding_rates_ibfk_2` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `staff_properties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) NOT NULL,
  `property_id` int(11) NOT NULL,
  `role_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_staff_property` (`staff_id`,`property_id`),
  KEY `idx_sp_property` (`property_id`),
  KEY `idx_sp_staff` (`staff_id`),
  CONSTRAINT `fk_staff_properties_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_staff_properties_staff` FOREIGN KEY (`staff_id`) REFERENCES `staff_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `staff_properties_ibfk_1` FOREIGN KEY (`staff_id`) REFERENCES `staff_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `staff_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `access_level` enum('superadmin','owner','admin','manager','receptionist','housekeeping','front_desk') NOT NULL DEFAULT 'manager',
  `role` varchar(50) DEFAULT NULL,
  `pin_hash` varchar(255) DEFAULT NULL,
  `assistant_access` tinyint(1) NOT NULL DEFAULT 0,
  `assistant_role` varchar(40) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `login_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_staff_property` (`property_id`),
  KEY `idx_staff_property_id` (`property_id`),
  CONSTRAINT `staff_users_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `system_settings` (
  `key_name` varchar(100) NOT NULL,
  `key_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`key_name`),
  KEY `property_id` (`property_id`),
  CONSTRAINT `system_settings_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS admin_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (property_id, is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `team_invitations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'manager',
  `token` varchar(100) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `property_id` (`property_id`),
  KEY `idx_invitation_token` (`token`),
  CONSTRAINT `team_invitations_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `wa_automation_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_name` varchar(100) NOT NULL,
  `event_key` varchar(100) NOT NULL,
  `is_system` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_key` (`event_key`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `wa_automations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_key` varchar(100) NOT NULL,
  `template_id` int(11) NOT NULL,
  `variable_mapping_json` text NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_key` (`event_key`),
  KEY `template_id` (`template_id`),
  KEY `idx_wa_auto_property` (`property_id`),
  CONSTRAINT `fk_wa_automations_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wa_automations_ibfk_1` FOREIGN KEY (`template_id`) REFERENCES `wa_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wa_automations_ibfk_2` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `wa_conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `guest_id` int(11) DEFAULT NULL,
  `phone_number` varchar(20) NOT NULL,
  `last_message_at` datetime DEFAULT current_timestamp(),
  `status` enum('open','resolved','snoozed') DEFAULT 'open',
  `created_at` datetime DEFAULT current_timestamp(),
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone_number` (`phone_number`),
  KEY `guest_id` (`guest_id`),
  KEY `idx_wa_conv_last_msg` (`last_message_at`),
  KEY `idx_wa_conv_property` (`property_id`),
  CONSTRAINT `fk_wa_conversations_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wa_conversations_ibfk_1` FOREIGN KEY (`guest_id`) REFERENCES `guests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wa_conversations_ibfk_2` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `wa_delivery_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_key` varchar(100) NOT NULL,
  `template_name` varchar(100) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `message_id` varchar(100) DEFAULT NULL,
  `status` varchar(20) NOT NULL,
  `meta_status` varchar(20) DEFAULT NULL,
  `error_code` varchar(50) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `event_key` (`event_key`),
  KEY `message_id` (`message_id`),
  KEY `property_id` (`property_id`),
  CONSTRAINT `fk_wa_delivery_logs_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wa_delivery_logs_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `wa_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) DEFAULT NULL,
  `conversation_id` int(11) NOT NULL,
  `direction` enum('inbound','outbound') NOT NULL,
  `message_text` text NOT NULL,
  `status` enum('sent','delivered','read','received','failed') DEFAULT 'sent',
  `message_id` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `conversation_id` (`conversation_id`),
  UNIQUE KEY `idx_wa_messages_message_id` (`message_id`),
  KEY `idx_wa_messages_status_dir` (`direction`,`status`),
  CONSTRAINT `wa_messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `wa_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `wa_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `language` varchar(10) NOT NULL,
  `components_json` text NOT NULL,
  `status` varchar(50) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `property_id` int(11) NOT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wa_tpl_prop_name` (`property_id`,`name`,`language`),
  CONSTRAINT `fk_wa_templates_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wa_templates_ibfk_1` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `guest_service_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `service_type` varchar(50) NOT NULL,
  `status` enum('pending','in_progress','completed','rejected') NOT NULL DEFAULT 'pending',
  `resolved_at` datetime DEFAULT NULL,

  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `property_id` (`property_id`),
  KEY `booking_id` (`booking_id`),
  KEY `idx_gsr_property_status` (`property_id`,`status`),
  CONSTRAINT `fk_gsr_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_gsr_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `automation_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `event_key` varchar(100) NOT NULL,
  `is_wa_active` tinyint(1) DEFAULT 0,
  `wa_template_id` int(11) DEFAULT NULL,
  `wa_mapping_json` text DEFAULT NULL,
  `is_email_active` tinyint(1) DEFAULT 0,
  `email_subject` varchar(255) DEFAULT NULL,
  `email_body_html` text DEFAULT NULL,
  `is_telegram_active` tinyint(1) DEFAULT 0,
  `telegram_body_text` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auto_rule_prop_event` (`property_id`, `event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `telegram_bot_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(50) NOT NULL,
  `property_id` int(11) DEFAULT NULL,
  `state` varchar(80) DEFAULT NULL,
  `context_data` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tg_session_chat` (`chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `processed_webhook_events` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `provider` varchar(40) NOT NULL,
  `event_id` varchar(120) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_provider_event` (`provider`,`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `room_ical_feeds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `export_token` char(32) NOT NULL,
  `import_url` varchar(500) DEFAULT NULL,
  `last_synced_at` datetime DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ical_room` (`room_id`),
  UNIQUE KEY `uq_ical_token` (`export_token`),
  KEY `idx_ical_property` (`property_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_daily_stats` (
  `property_id` int(11) NOT NULL,
  `stat_date` date NOT NULL,
  `total_rooms` int(11) NOT NULL DEFAULT 0,
  `occupied_rooms` int(11) NOT NULL DEFAULT 0,
  `occupancy_percent` decimal(6,2) NOT NULL DEFAULT 0.00,
  `room_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `adr` decimal(12,2) NOT NULL DEFAULT 0.00,
  `revpar` decimal(12,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`property_id`, `stat_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `night_audit_actions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `property_id` int(11) NOT NULL,
  `booking_id` int(11) NOT NULL,
  `issue_type` varchar(50) NOT NULL,
  `amount` decimal(10,2) DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `property_id` (`property_id`),
  KEY `booking_id` (`booking_id`),
  CONSTRAINT `fk_night_audit_actions_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_night_audit_actions_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
