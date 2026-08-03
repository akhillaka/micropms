<?php
declare(strict_types=1);

class SequenceGenerator {
    /**
     * Parses a custom sequence format and replaces placeholders.
     * Available placeholders: {ID}, {ID:X}, {YY}, {YYYY}, {MM}, {DD}
     */
    public static function generate(string $format, int $val): string {
        $now = new DateTime();
        $yy = $now->format('y');
        $yyyy = $now->format('Y');
        $mm = $now->format('m');
        $dd = $now->format('d');
        
        $result = str_replace(
            ['{YY}', '{YYYY}', '{MM}', '{DD}'],
            [$yy, $yyyy, $mm, $dd],
            $format
        );
        
        // Handle {ID} or zero-padded {ID:X} where X is the number of digits
        $result = preg_replace_callback('/\{ID(?::(\d+))?\}/', function($matches) use ($val) {
            $padding = isset($matches[1]) ? (int)$matches[1] : 1;
            return str_pad((string)$val, $padding, '0', STR_PAD_LEFT);
        }, $result);
        
        return $result;
    }

    /**
     * Assigns the generated display ID to a specific table row after insertion.
     */
    public static function assignDisplayId(\PDO $db, string $table, int $id, string $formatKey, string $targetColumn = 'display_id'): void {
        $format = defined($formatKey) ? constant($formatKey) : '';
        if (!$format) return;
        
        // Map format key to reset key (e.g., SEQ_BOOKING_FORMAT -> SEQ_BOOKING_RESET)
        $resetKey = str_replace('_FORMAT', '_RESET', $formatKey);
        $resetRule = defined($resetKey) ? constant($resetKey) : 'never';
        
        $maxKey = str_replace('_FORMAT', '_MAX', $formatKey);
        $maxLimit = defined($maxKey) ? (int)constant($maxKey) : 0;
        
        $now = new DateTime();
        $period = 'global';
        if ($resetRule === 'monthly') {
            $period = $now->format('Y-m');
        } elseif ($resetRule === 'yearly') {
            $period = $now->format('Y');
        } elseif ($resetRule === 'daily') {
            $period = $now->format('Y-m-d');
        }
        
        $module = $table . '_' . $targetColumn; // Separate counters per column (e.g. bookings_display_id vs bookings_offline_folio_id)
        $current = $id; // Default fallback to auto-increment ID
        
        $inTransaction = $db->inTransaction();
        if (!$inTransaction) {
            $db->beginTransaction();
        } else {
            // Support savepoints for nested transactions if the driver supports it, 
            // but PDO flat transactions mean we just shouldn't commit or rollback globally here.
            // A simple flag is enough since we only rollback on failure and fallback to auto-increment.
        }
        
        try {
            // Retrieve property_id directly from target row to guarantee tenant isolation
            $propId = 1;
            try {
                $checkProp = $db->prepare("SELECT property_id FROM `{$table}` WHERE id = ?");
                $checkProp->execute([$id]);
                $fetchedPropId = $checkProp->fetchColumn();
                if ($fetchedPropId !== false) {
                    $propId = (int)$fetchedPropId;
                } else {
                    $propId = class_exists('AuthHelper') ? AuthHelper::getPropertyId() : 1;
                }
            } catch (\Exception $ex) {
                $propId = class_exists('AuthHelper') ? AuthHelper::getPropertyId() : 1;
            }

            $stmt = $db->prepare("SELECT current_value FROM sequence_counters WHERE property_id = :pid AND module = :m AND period = :p FOR UPDATE");
            $stmt->execute(['pid' => $propId, 'm' => $module, 'p' => $period]);
            $val = $stmt->fetchColumn();
            
            if ($val === false) {
                $current = 1;
                $ins = $db->prepare("INSERT INTO sequence_counters (property_id, module, period, current_value) VALUES (:pid, :m, :p, 1)");
                $ins->execute(['pid' => $propId, 'm' => $module, 'p' => $period]);
            } else {
                $current = (int)$val + 1;
                if ($maxLimit > 0 && $current > $maxLimit) {
                    $current = 1;
                }
                $upd = $db->prepare("UPDATE sequence_counters SET current_value = :val WHERE property_id = :pid AND module = :m AND period = :p");
                $upd->execute(['val' => $current, 'pid' => $propId, 'm' => $module, 'p' => $period]);
            }
            
            // Only commit if WE started the transaction
            if (!$inTransaction) {
                $db->commit();
            }
        } catch (\Exception $e) {
            // Only roll back if WE started the transaction
            if (!$inTransaction) {
                $db->rollBack();
            }
            // If the transaction fails, fall back to auto-increment $id
            $current = $id;
        }
        
        $displayId = self::generate($format, $current);

        // Security: whitelist allowed table/column combinations before interpolating into SQL.
        // This prevents SQL injection if the caller ever passes user-supplied table/column names.
        $allowedTargets = [
            'bookings'             => ['display_id', 'offline_folio_id'],
            'folio_ledger'         => ['display_id'],
            'finance_transactions' => ['display_id'],
            'guests'               => ['display_id'],
            'pos_orders'           => ['display_id'],
        ];
        if (!isset($allowedTargets[$table]) || !in_array($targetColumn, $allowedTargets[$table], true)) {
            error_log("SequenceGenerator::assignDisplayId blocked unsafe table/column: {$table}.{$targetColumn}");
            return;
        }

        $stmt = $db->prepare("UPDATE `{$table}` SET `{$targetColumn}` = :did WHERE id = :id");
        $stmt->execute(['did' => $displayId, 'id' => $id]);
    }
}
