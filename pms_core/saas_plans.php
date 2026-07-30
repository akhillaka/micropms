<?php
declare(strict_types=1);

/**
 * SaaSPlans - Manages SaaS subscription plan definitions dynamically.
 */
class SaaSPlans {

    private const DEFAULTS = [
        'free_tier' => [
            'name' => 'Free (All Modules)',
            'price' => 0,
            'max_rooms' => 5,
            'max_staff' => 2,
            'features' => [
                'ocr_google_vision' => true,
                'whatsapp_automations' => true,
                'custom_domain_mapping' => true,
                'pos_module' => true,
                'whatsapp_module' => true,
                'housekeeping_module' => true
            ]
        ],
        'starter' => [
            'name' => 'Starter',
            'price' => 1999,
            'max_rooms' => 15,
            'max_staff' => 5,
            'features' => [
                'ocr_google_vision' => false,
                'whatsapp_automations' => false,
                'custom_domain_mapping' => false,
                'pos_module' => false,
                'whatsapp_module' => false,
                'housekeeping_module' => true
            ]
        ],
        'pro' => [
            'name' => 'Pro',
            'price' => 4999,
            'max_rooms' => 50,
            'max_staff' => 15,
            'features' => [
                'ocr_google_vision' => true,
                'whatsapp_automations' => false,
                'custom_domain_mapping' => true,
                'pos_module' => true,
                'whatsapp_module' => false,
                'housekeeping_module' => true
            ]
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'price' => 9999,
            'max_rooms' => 999,
            'max_staff' => 50,
            'features' => [
                'ocr_google_vision' => true,
                'whatsapp_automations' => true,
                'custom_domain_mapping' => true,
                'pos_module' => true,
                'whatsapp_module' => true,
                'housekeeping_module' => true
            ]
        ]
    ];

    /**
     * Retrieves the active SaaS plans configuration.
     * Checks database system settings, falling back to defaults.
     */
    public static function get(\PDO $db): array {
        try {
            $stmt = $db->prepare("SELECT key_value FROM system_settings WHERE key_name = 'SAAS_PLANS_CONFIG'");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false && !empty($val)) {
                $custom = json_decode($val, true);
                if (is_array($custom)) {
                    // Merge with defaults to ensure keys are intact
                    return array_merge(self::DEFAULTS, $custom);
                }
            }
        } catch (\Exception $e) {
            // Ignore DB errors
        }
        return self::DEFAULTS;
    }

    /**
     * Saves the SaaS plans configuration.
     */
    public static function save(\PDO $db, array $plans): bool {
        try {
            $json = json_encode($plans, JSON_THROW_ON_ERROR);
            $stmt = $db->prepare("
                INSERT INTO system_settings (key_name, key_value, updated_at) 
                VALUES ('SAAS_PLANS_CONFIG', ?, NOW()) 
                ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), updated_at = NOW()
            ");
            return $stmt->execute([$json]);
        } catch (\Exception $e) {
            return false;
        }
    }
}
