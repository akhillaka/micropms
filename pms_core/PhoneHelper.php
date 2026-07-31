<?php
declare(strict_types=1);

/**
 * PhoneHelper — Single source of truth for phone number normalisation.
 *
 * Storage format (canonical):
 *   ─ guests.phone          → 10-digit domestic only  e.g. "7702233496"
 *                             (stored short so it's human-readable in UI & reports)
 *   ─ wa_conversations.phone_number → E.164 without +  e.g. "917702233496"
 *                             (WhatsApp Cloud API requires country code)
 *
 * All code must pass phone numbers through this class before writing to DB
 * or sending to any external API.
 */
class PhoneHelper
{
    /**
     * Return a clean 10-digit domestic phone number for storage in `guests.phone`.
     *
     * Accepts:
     *   "7702233496"      → "7702233496"
     *   "+917702233496"   → "7702233496"
     *   "917702233496"    → "7702233496"
     *   "07702233496"     → "7702233496"
     *   " 77 022 33496 "  → "7702233496"
     *
     * Returns null if the number can't be reduced to exactly 10 digits.
     */
    public static function toLocal(string $raw, string $countryCode = 'IN'): ?string
    {
        $digits = preg_replace('/[^0-9]/', '', $raw);
        
        // India
        if ($countryCode === 'IN') {
            if (strlen($digits) === 10) return $digits;
            if (strlen($digits) === 12 && str_starts_with($digits, '91')) return substr($digits, 2);
            if (strlen($digits) === 11 && str_starts_with($digits, '0')) return substr($digits, 1);
            return null;
        }
        
        // US/Canada
        if ($countryCode === 'US' || $countryCode === 'CA') {
            if (strlen($digits) === 10) return $digits;
            if (strlen($digits) === 11 && str_starts_with($digits, '1')) return substr($digits, 1);
            return null;
        }
        
        // Default / Rest of World - store as is if it looks somewhat valid (7 to 15 digits)
        if (strlen($digits) >= 7 && strlen($digits) <= 15) {
            return $digits;
        }

        return null; // Unrecognised format
    }

    /**
     * Return the E.164-style number (no "+") suitable for WhatsApp Cloud API
     * and for storage in `wa_conversations.phone_number`.
     *
     * Examples:
     *   "7702233496"    → "917702233496"
     *   "917702233496"  → "917702233496"
     *   "+917702233496" → "917702233496"
     */
    public static function toE164(string $raw, string $countryCode = 'IN'): ?string
    {
        $local = self::toLocal($raw, $countryCode);
        if ($local === null) {
            // If toLocal fails, try stripping non-digits and using as-is
            // (for non-Indian numbers already with country code)
            $digits = preg_replace('/[^0-9]/', '', $raw);
            return (strlen($digits) >= 7 && strlen($digits) <= 15) ? $digits : null;
        }
        if ($countryCode === 'IN') return '91' . $local;
        if ($countryCode === 'US' || $countryCode === 'CA') return '1' . $local;
        return $local; // Already stored with country code maybe?
    }

    /**
     * Validate that $raw looks like a valid number based on region.
     */
    public static function isValidNumber(?string $raw, string $countryCode = 'IN'): bool
    {
        if ($raw === null) return false;
        $local = self::toLocal($raw, $countryCode);
        if ($local === null) return false;
        if ($countryCode === 'IN') {
            return (bool)preg_match('/^[6-9]\d{9}$/', $local);
        }
        if ($countryCode === 'US' || $countryCode === 'CA') {
            return (bool)preg_match('/^[2-9]\d{9}$/', $local);
        }
        return true; // Weak validation for other countries
    }

    public static function isValidIndian(?string $raw): bool
    {
        if ($raw === null) return false;
        return self::isValidNumber($raw, 'IN');
    }

    /**
     * Format for display in UI (e.g. "+91 77022 33496").
     */
    public static function display(string $raw): string
    {
        $e164 = self::toE164($raw);
        if ($e164 === null) return $raw;
        // Format: +91 XXXXX XXXXX
        if (str_starts_with($e164, '91') && strlen($e164) === 12) {
            $local = substr($e164, 2);
            return '+91 ' . substr($local, 0, 5) . ' ' . substr($local, 5);
        }
        return '+' . $e164;
    }
}
