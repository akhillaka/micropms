<?php
declare(strict_types=1);

require_once __DIR__ . '/../SequenceGenerator.php';
require_once __DIR__ . '/../AuditLogger.php';

/**
 * FolioService - Shared folio/ledger logic for both Admin API and Assistant PWA.
 */
class FolioService {

    public static function uniqueRef(string $prefix = 'LED'): string {
        return strtoupper($prefix) . '-' . bin2hex(random_bytes(6));
    }

    /**
     * Get folio balance for a booking.
     * Positive = guest owes money, Negative = guest has credit.
     */
    public static function getBalance(\PDO $db, int $bookingId): float {
        $stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM folio_ledger WHERE booking_id = ?");
        $stmt->execute([$bookingId]);
        return (float)$stmt->fetchColumn();
    }

    /**
     * Get net paid amount (total payments minus any refunds issued).
     */
    public static function getPaidAmount(\PDO $db, int $bookingId): float {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(
                CASE 
                    WHEN is_refund = 1 OR transaction_type = 'REFUND' OR description LIKE 'Refund%' THEN -ABS(amount)
                    WHEN amount < 0 THEN ABS(amount)
                    ELSE 0 
                END
            ), 0) 
            FROM folio_ledger 
            WHERE booking_id = ?
        ");
        $stmt->execute([$bookingId]);
        return max(0.0, (float)$stmt->fetchColumn());
    }

    /**
     * Get all ledger entries for a booking.
     */
    public static function getEntries(\PDO $db, int $bookingId): array {
        $stmt = $db->prepare("SELECT * FROM folio_ledger WHERE booking_id = ? ORDER BY recorded_at ASC");
        $stmt->execute([$bookingId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get folio breakdown categorized for checkout display.
     */
    public static function getBreakdown(\PDO $db, int $bookingId): array {
        $entries = self::getEntries($db, $bookingId);
        
        $breakdown = [
            'room_charges'  => 0.0,
            'extra_bed'     => 0.0,
            'incidental'    => 0.0,
            'taxes'         => 0.0,
            'restaurant'    => 0.0,
            'laundry'       => 0.0,
            'discounts'     => 0.0,
            'other'         => 0.0,
            'payments'      => 0.0,
            'refunds'       => 0.0,
        ];

        foreach ($entries as $entry) {
            $amount = (float)$entry['amount'];
            $desc   = strtolower($entry['description'] ?? '');
            $type   = strtoupper($entry['transaction_type']);

            // Refunds stored as POSITIVE amounts (they reduce balance just like payments)
            $isRefund = ((int)($entry['is_refund'] ?? 0) === 1) || $type === 'REFUND' || str_contains($desc, 'refund') || $type === 'REBATE';
            if ($isRefund) {
                $breakdown['refunds'] += abs($amount);
            } elseif (strtolower($type) === 'payment') {
                // True payments
                $breakdown['payments'] += abs($amount);
            } elseif ($amount < 0) {
                // Negative amounts that are not payments are discounts
                $breakdown['discounts'] += abs($amount);
            } elseif ($type === 'TAX' || str_contains($desc, 'tax')) {
                $breakdown['taxes'] += $amount;
            } elseif ($type === 'ROOM_CHARGE') {
                if (str_contains($desc, 'extra bed')) {
                    $breakdown['extra_bed'] += $amount;
                } else {
                    $breakdown['room_charges'] += $amount;
                }
            } elseif (str_contains($desc, 'restaurant') || str_contains($desc, 'food') || str_contains($desc, 'meal')) {
                $breakdown['restaurant'] += $amount;
            } elseif (str_contains($desc, 'laundry')) {
                $breakdown['laundry'] += $amount;
            } elseif ($type === 'INCIDENTAL') {
                $breakdown['incidental'] += $amount;
            } else {
                $breakdown['other'] += $amount;
            }
        }

        return $breakdown;
    }

    /**
     * Post an incidental charge to a booking folio.
     */
    public static function postCharge(\PDO $db, int $bookingId, float $amount, string $description, string $category = 'other'): int {
        if ($amount <= 0 && !str_contains(strtolower($description), 'discount') && !str_contains(strtolower($description), 'rebate')) {
            throw new \InvalidArgumentException("Charge amount must be positive unless it is a discount.");
        }

        $shouldCommit = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            $cleanDesc = trim(strip_tags($description));
            
            // Lock the booking to prevent race conditions across folio entries
            $pStmt = $db->prepare("SELECT property_id FROM bookings WHERE id = ? FOR UPDATE");
            $pStmt->execute([$bookingId]);
            $propertyId = (int)$pStmt->fetchColumn() ?: 1;

            $bucket = ($category === 'pos_order' || $category === 'incidentals' || strtolower($category) === 'f&b') ? 'incidentals' : 'main';
            $ref = 'CHG-' . bin2hex(random_bytes(5));
            $stmt = $db->prepare("
                INSERT INTO folio_ledger (property_id, booking_id, transaction_type, amount, payment_method, transaction_ref, description, folio_bucket, category, is_refund, recorded_at) 
                VALUES (:pid, :bid, :ttype, :amount, :pmethod, :ref, :desc, :bucket, :category, :is_ref, :rec_at)
            ");
            $stmt->execute(['pid' => $propertyId, 'bid' => $bookingId, 'ttype' => 'INCIDENTAL', 'amount' => $amount, 'pmethod' => null, 'ref' => $ref, 'desc' => $cleanDesc, 'bucket' => $bucket, 'category' => $category, 'is_ref' => 0, 'rec_at' => date('Y-m-d H:i:s')]);
            $entryId = (int)$db->lastInsertId();
            SequenceGenerator::assignDisplayId($db, 'folio_ledger', $entryId, 'SEQ_RECEIPT_FORMAT');

            // Audit
            $staffId = $_SESSION['user_id'] ?? null;
            AuditLogger::log($staffId, 'POST_CHARGE', 'FOLIO', $bookingId, ['amount' => $amount, 'desc' => $cleanDesc, 'category' => $category]);

            if ($shouldCommit) {
                $db->commit();
            }
            return $entryId;
        } catch (\Throwable $e) {
            if ($shouldCommit && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Record a payment against a booking folio.
     * Standardized description format regardless of source (admin/assistant/API).
     */
    public static function recordPayment(\PDO $db, int $bookingId, float $amount, string $method, string $ref = 'MANUAL', string $source = 'admin', string $category = 'booking', ?string $recordedAt = null, bool $isSplit = false, bool $skipGoogleSheets = false): int {
        if ($amount == 0) {
            throw new \InvalidArgumentException("Payment amount cannot be zero.");
        }
        if ($ref === '' || strcasecmp($ref, 'MANUAL') === 0) {
            $ref = self::uniqueRef('PAY');
        }

        $shouldCommit = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            // Lock the booking to serialize ledger updates and prevent refund race conditions
            $pStmt = $db->prepare("SELECT property_id FROM bookings WHERE id = ? FOR UPDATE");
            $pStmt->execute([$bookingId]);
            $propertyId = (int)$pStmt->fetchColumn() ?: 1;

            // Validation: If refund (negative amount), ensure it does not exceed total paid amount
            if ($amount < 0) {
                $currentPaid = self::getPaidAmount($db, $bookingId);
                if (abs($amount) > $currentPaid) {
                    throw new \InvalidArgumentException("Refund amount (₹" . number_format(abs($amount), 2) . ") exceeds total payments collected (₹" . number_format($currentPaid, 2) . ").");
                }
            }

            // Standardized description format: "Payment - METHOD" or "Split Payment METHOD - CATEGORY"
            $isRefund = $amount < 0;
            $absAmount = abs($amount);
            
            $catLabel = $category;
            if ($category === 'booking') {
                $catLabel = 'Room Rent';
            } elseif ($category === 'F&B') {
                $catLabel = 'F&B';
            }

            if ($isSplit) {
                $description = ($isRefund ? 'Split Refund ' : 'Split Payment ') . strtoupper($method) . ' - ' . $catLabel;
            } else {
                $description = ($isRefund ? 'Refund - ' : 'Payment - ') . ucfirst(strtolower($method));
            }
            
            // Ledger entry for payment must be negative, refund must be positive
            $ledgerAmount = $isRefund ? $absAmount : -$absAmount;

            // Ledger entry for payment must be negative, refund must be positive
            $ledgerAmount = $isRefund ? $absAmount : -$absAmount;

            // Map category to folio_bucket enum ('main' or 'incidentals')
            $bucket = ($category === 'F&B' || $category === 'incidentals') ? 'incidentals' : 'main';

            $sql = "INSERT INTO folio_ledger (property_id, booking_id, transaction_type, amount, transaction_ref, description, payment_method, folio_bucket, category, is_refund";
            $params = [
                'pid'      => $propertyId,
                'bid'      => $bookingId, 
                'amount'   => $ledgerAmount, 
                'ref'      => $ref, 
                'desc'     => $description,
                'method'   => strtolower($method),
                'bucket'   => $bucket,
                'category' => $category,
                'is_ref'   => $isRefund ? 1 : 0
            ];
            
            if ($recordedAt !== null) {
                $sql .= ", recorded_at) VALUES (:pid, :bid, 'payment', :amount, :ref, :desc, :method, :bucket, :category, :is_ref, :recorded_at)";
                $params['recorded_at'] = $recordedAt;
            } else {
                $sql .= ") VALUES (:pid, :bid, 'payment', :amount, :ref, :desc, :method, :bucket, :category, :is_ref)";
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $entryId = (int)$db->lastInsertId();
            SequenceGenerator::assignDisplayId($db, 'folio_ledger', $entryId, 'SEQ_RECEIPT_FORMAT');
            


            // Enhanced audit log with source tracking
            $staffId = $_SESSION['user_id'] ?? null;
            $staffName = $_SESSION['username'] ?? 'system';
            AuditLogger::log($staffId, 'RECORD_PAYMENT', 'FOLIO', $bookingId, [
                'amount' => $amount,
                'method' => $method,
                'ref' => $ref,
                'source' => $source,
                'staff' => $staffName,
                'description' => $description
            ]);

            if ($shouldCommit) {
                $db->commit();
            }

            if (!$skipGoogleSheets && !$isRefund && $ledgerAmount < 0) {
                try {
                    require_once __DIR__ . '/../GoogleSheetService.php';
                    GoogleSheetService::syncPayment($db, $entryId);
                } catch (\Throwable $t) {
                    error_log('Google Sheets payment sync failed: ' . $t->getMessage());
                }
            }

            return $entryId;
        } catch (\Throwable $e) {
            if ($shouldCommit && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Delete a ledger entry (owner only).
     */
    public static function deleteEntry(\PDO $db, int $entryId): bool {
        $shouldCommit = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            // Retrieve entry data first for audit log detail
            $infoStmt = $db->prepare("SELECT booking_id, amount, description, transaction_type, property_id FROM folio_ledger WHERE id = ?");
            $infoStmt->execute([$entryId]);
            $entry = $infoStmt->fetch();

            if (!$entry) {
                throw new \InvalidArgumentException('Ledger entry not found');
            }

            if (class_exists('AuthHelper')) {
                $activeProp = AuthHelper::getPropertyId();
                if ((int)$entry['property_id'] !== $activeProp) {
                    throw new \Exception('Access denied: folio entry belongs to a different property');
                }
            }

            $stmt = $db->prepare("DELETE FROM folio_ledger WHERE id = ? AND property_id = ?");
            $res = $stmt->execute([$entryId, (int)$entry['property_id']]);
            
            // Delete orphaned finance ledger entry if it was a payment
            if ($res && strtolower($entry['transaction_type'] ?? '') === 'payment') {
                $dStmt = $db->prepare("DELETE FROM finance_transactions WHERE description LIKE ?");
                $dStmt->execute(["%Folio #$entryId%"]);
            }

            if ($res && $entry) {
                AuditLogger::log(
                    $_SESSION['user_id'] ?? null,
                    'DELETE_FOLIO_ENTRY',
                    'FOLIO',
                    (int)$entry['booking_id'],
                    [
                        'entry_id'    => $entryId,
                        'amount'      => $entry['amount'],
                        'description' => $entry['description']
                    ]
                );
            }

            if ($shouldCommit) {
                $db->commit();
            }
            return $res;
        } catch (\Throwable $e) {
            if ($shouldCommit && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Edit a ledger entry amount and description.
     */
    public static function editEntry(\PDO $db, int $entryId, float $amount, string $description, ?string $paymentMethod = null): bool {
        $shouldCommit = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            // Retrieve old values for audit diff
            $infoStmt = $db->prepare("SELECT booking_id, amount, description, payment_method FROM folio_ledger WHERE id = ?");
            $infoStmt->execute([$entryId]);
            $oldEntry = $infoStmt->fetch();

            $sql = "UPDATE folio_ledger SET amount = :amount, description = :desc";
            $params = ['id' => $entryId, 'amount' => $amount, 'desc' => $description];
            
            if ($paymentMethod !== null) {
                $sql .= ", payment_method = :method";
                $params['method'] = $paymentMethod;
            }
            
            $sql .= " WHERE id = :id";
            $stmt = $db->prepare($sql);
            $res = $stmt->execute($params);
            
            if ($res && $oldEntry) {
                AuditLogger::log(
                    $_SESSION['user_id'] ?? null,
                    'EDIT_FOLIO_ENTRY',
                    'FOLIO',
                    (int)$oldEntry['booking_id'],
                    [
                        'entry_id'   => $entryId,
                        'old_values' => [
                            'amount'         => $oldEntry['amount'],
                            'description'    => $oldEntry['description'],
                            'payment_method' => $oldEntry['payment_method']
                        ],
                        'new_values' => [
                            'amount'         => $amount,
                            'description'    => $description,
                            'payment_method' => $paymentMethod
                        ]
                    ]
                );
            }

            if ($shouldCommit) {
                $db->commit();
            }
            return $res;
        } catch (\Throwable $e) {
            if ($shouldCommit && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get configurable quick charge presets from system_settings.
     */
    public static function getQuickChargePresets(\PDO $db, int $propertyId = 1): array {
        if (!function_exists('get_folio_quick_charges')) {
            require_once __DIR__ . '/../config.php';
        }
        return get_folio_quick_charges($db, $propertyId);
    }
}
