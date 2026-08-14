-- Migration 021: Unified Automations

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
  UNIQUE KEY `uq_auto_rule_prop_event` (`property_id`, `event_key`),
  CONSTRAINT `fk_auto_rules_property` FOREIGN KEY (`property_id`) REFERENCES `properties` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_auto_rules_wa_template` FOREIGN KEY (`wa_template_id`) REFERENCES `wa_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrate existing WA automations to the new table
INSERT IGNORE INTO `automation_rules` (
    `property_id`, `event_key`, `is_wa_active`, `wa_template_id`, `wa_mapping_json`
)
SELECT 
    `property_id`, `event_key`, IF(`status` = 'active', 1, 0), `template_id`, `variable_mapping_json`
FROM `wa_automations`;
