-- Indexes, missing columns, webhook idempotency, and Telegram sessions.

ALTER TABLE `folio_ledger`
  ADD COLUMN IF NOT EXISTS `category` VARCHAR(50) DEFAULT NULL AFTER `payment_method`;

ALTER TABLE `jobs_queue`
  ADD COLUMN IF NOT EXISTS `property_id` INT NULL DEFAULT NULL AFTER `queue_name`;

ALTER TABLE `jobs_queue`
  ADD INDEX IF NOT EXISTS `idx_jobs_queue_property` (`property_id`);

ALTER TABLE `guest_service_requests`
  ADD INDEX IF NOT EXISTS `idx_gsr_property_status` (`property_id`, `status`);

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
