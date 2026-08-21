<?php
declare(strict_types=1);

/**
 * CityLedgerService - Handles B2B invoicing, corporate accounts, credit limits,
 * and transferring guest folio balances to the City Ledger.
 */
class CityLedgerService {

    /**
     * Get all companies registered for a specific property.
     */
    public static function getCompanies(\PDO $db, int $propertyId): array {
        $stmt = $db->prepare("SELECT * FROM companies WHERE property_id = ? AND deleted_at IS NULL ORDER BY name ASC");
        $stmt->execute([$propertyId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Register a new corporate account with credit limit and balance tracking.
     */
    public static function createCompany(\PDO $db, int $propertyId, array $data): int {
        $name = trim($data['name'] ?? '');
        $contact = trim($data['contact_details'] ?? '');
        $creditLimit = (float)($data['credit_limit'] ?? 0.00);

        if (empty($name)) {
            throw new \InvalidArgumentException("Company name is required.");
        }

        $stmt = $db->prepare("
            INSERT INTO companies (property_id, name, contact_details, credit_limit, balance)
            VALUES (?, ?, ?, ?, 0.00)
        ");
        $stmt->execute([$propertyId, $name, $contact, $creditLimit]);
        return (int)$db->lastInsertId();
    }

    public static function updateCompany(\PDO $db, int $propertyId, int $companyId, array $data): void {
        $name = trim($data['name'] ?? '');
        $contact = trim($data['contact_details'] ?? '');
        $creditLimit = (float)($data['credit_limit'] ?? 0.00);
        if ($name === '') {
            throw new \InvalidArgumentException('Company name is required.');
        }
        $stmt = $db->prepare("
            UPDATE companies
            SET name = ?, contact_details = ?, credit_limit = ?
            WHERE id = ? AND property_id = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$name, $contact, $creditLimit, $companyId, $propertyId]);
        if ($stmt->rowCount() === 0) {
            // May be no-op update; verify row exists
            $check = $db->prepare('SELECT id FROM companies WHERE id = ? AND property_id = ? AND deleted_at IS NULL');
            $check->execute([$companyId, $propertyId]);
            if (!$check->fetchColumn()) {
                throw new \Exception('Company not found.');
            }
        }
    }

    public static function softDeleteCompany(\PDO $db, int $propertyId, int $companyId): void {
        $co = self::getCompany($db, $propertyId, $companyId);
        if (!$co) {
            throw new \Exception('Company not found.');
        }
        if ((float)($co['balance'] ?? 0) > 0.05) {
            throw new \Exception('Cannot archive company with outstanding balance. Settle AR first.');
        }
        $stmt = $db->prepare("UPDATE companies SET deleted_at = NOW() WHERE id = ? AND property_id = ? AND deleted_at IS NULL");
        $stmt->execute([$companyId, $propertyId]);
        if ($stmt->rowCount() === 0) {
            throw new \Exception('Company not found.');
        }
    }

    public static function getCompany(\PDO $db, int $propertyId, int $companyId): ?array {
        $stmt = $db->prepare('SELECT * FROM companies WHERE id = ? AND property_id = ? AND deleted_at IS NULL');
        $stmt->execute([$companyId, $propertyId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function getLedgerLines(\PDO $db, int $propertyId, int $companyId): array {
        $stmt = $db->prepare("
            SELECT cl.*, b.display_id AS booking_display_id
            FROM city_ledger cl
            LEFT JOIN bookings b ON b.id = cl.booking_id
            WHERE cl.company_id = ? AND cl.property_id = ?
              AND (cl.deleted_at IS NULL)
            ORDER BY cl.recorded_at DESC, cl.id DESC
        ");
        $stmt->execute([$companyId, $propertyId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function linkBookingCompany(\PDO $db, int $propertyId, int $bookingId, int $companyId): void {
        $co = self::getCompany($db, $propertyId, $companyId);
        if (!$co) {
            throw new \Exception('Company not found.');
        }
        $stmt = $db->prepare('UPDATE bookings SET company_id = ? WHERE id = ? AND property_id = ?');
        $stmt->execute([$companyId, $bookingId, $propertyId]);
        if ($stmt->rowCount() === 0) {
            $check = $db->prepare('SELECT id FROM bookings WHERE id = ? AND property_id = ?');
            $check->execute([$bookingId, $propertyId]);
            if (!$check->fetchColumn()) {
                throw new \Exception('Booking not found.');
            }
        }
    }

    /**
     * Transfer a booking's pending folio balance to a company's city ledger account.
     */
    public static function transferBookingToCityLedger(\PDO $db, int $bookingId, int $companyId, ?int $expectedPropertyId = null): array {
        $shouldCommit = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            // 1. Fetch booking and confirm property matches company property
            $bStmt = $db->prepare("SELECT id, property_id, total_amount, guest_id FROM bookings WHERE id = ? FOR UPDATE");
            $bStmt->execute([$bookingId]);
            $booking = $bStmt->fetch();

            if (!$booking) {
                throw new \Exception("Booking not found.");
            }

            $propertyId = (int)$booking['property_id'];
            if ($expectedPropertyId !== null && $expectedPropertyId > 0 && $propertyId !== $expectedPropertyId) {
                throw new \Exception("Booking belongs to another property.");
            }

            // 2. Fetch company and check credit limit
            $cStmt = $db->prepare("SELECT * FROM companies WHERE id = ? AND property_id = ? AND deleted_at IS NULL FOR UPDATE");
            $cStmt->execute([$companyId, $propertyId]);
            $company = $cStmt->fetch();

            if (!$company) {
                throw new \Exception("Company not found or belongs to another property.");
            }

            // Calculate current outstanding guest balance
            $ledgerStmt = $db->prepare("SELECT SUM(amount) as balance FROM folio_ledger WHERE booking_id = ? AND property_id = ?");
            $ledgerStmt->execute([$bookingId, $propertyId]);
            $guestBalance = (float)$ledgerStmt->fetchColumn();

            if ($guestBalance <= 0.05) {
                throw new \Exception("Booking has no pending balance to transfer.");
            }

            // Check credit limit breach
            $newCompanyBalance = (float)$company['balance'] + $guestBalance;
            $creditLimit = (float)$company['credit_limit'];
            if ($creditLimit > 0 && $newCompanyBalance > $creditLimit) {
                throw new \Exception("Transfer rejected: Company credit limit would be exceeded (Limit: ₹{$creditLimit}, Projected Balance: ₹{$newCompanyBalance}).");
            }

            // 3. Post a payment (negative entry) to the guest's folio to zero it out
            // Unique transaction_id per transfer (uq_folio_booking_txn)
            $txnId = 'CITY_LEDGER-' . $bookingId . '-' . bin2hex(random_bytes(4));
            $desc = "Balance transferred to City Ledger - Company: " . $company['name'];
            $insertFolio = $db->prepare("
                INSERT INTO folio_ledger (property_id, booking_id, entry_kind, amount, transaction_id, description, payment_method)
                VALUES (?, ?, 'payment', ?, ?, ?, 'CITY_LEDGER')
            ");
            $insertFolio->execute([$propertyId, $bookingId, -$guestBalance, $txnId, $desc]);
            $entryId = (int)$db->lastInsertId();

            // Assign receipt sequence ID
            if (class_exists('SequenceGenerator')) {
                SequenceGenerator::assignDisplayId($db, 'folio_ledger', $entryId, 'SEQ_RECEIPT_FORMAT');
            }

            // 4. Record a charge entry in the city_ledger table
            $insertCL = $db->prepare("
                INSERT INTO city_ledger (property_id, company_id, booking_id, amount, type, status)
                VALUES (?, ?, ?, ?, 'charge', 'pending')
            ");
            $insertCL->execute([$propertyId, $companyId, $bookingId, $guestBalance]);

            // 5. Update company outstanding balance
            $updCompany = $db->prepare("UPDATE companies SET balance = ? WHERE id = ? AND property_id = ?");
            $updCompany->execute([$newCompanyBalance, $companyId, $propertyId]);

            // Link company_id to booking for billing reference
            $linkBooking = $db->prepare("UPDATE bookings SET company_id = ? WHERE id = ? AND property_id = ?");
            $linkBooking->execute([$companyId, $bookingId, $propertyId]);

            if ($shouldCommit) {
                $db->commit();
            }

            return [
                'success' => true,
                'transferred_amount' => $guestBalance,
                'company_balance' => $newCompanyBalance
            ];
        } catch (\Throwable $e) {
            if ($shouldCommit && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Record a payment against the company's outstanding city ledger balance.
     */
    public static function recordCompanyPayment(\PDO $db, int $propertyId, int $companyId, float $amount, string $reference = 'MANUAL', string $description = 'Company Payment Settle'): array {
        if ($amount <= 0) {
            throw new \InvalidArgumentException("Payment amount must be greater than zero.");
        }

        $shouldCommit = false;
        if (!$db->inTransaction()) {
            $db->beginTransaction();
            $shouldCommit = true;
        }

        try {
            $cStmt = $db->prepare("SELECT * FROM companies WHERE id = ? AND property_id = ? AND deleted_at IS NULL FOR UPDATE");
            $cStmt->execute([$companyId, $propertyId]);
            $company = $cStmt->fetch();

            if (!$company) {
                throw new \Exception("Company not found.");
            }

            // 1. Insert payment entry in city_ledger
            $insertCL = $db->prepare("
                INSERT INTO city_ledger (property_id, company_id, amount, type, status)
                VALUES (?, ?, ?, 'payment', 'paid')
            ");
            $insertCL->execute([$propertyId, $companyId, $amount]);

            // 2. Reduce company balance
            $newBalance = max(0.00, (float)$company['balance'] - $amount);
            $updCompany = $db->prepare("UPDATE companies SET balance = ? WHERE id = ? AND property_id = ?");
            $updCompany->execute([$newBalance, $companyId, $propertyId]);

            // 3. Mark matching pending charges as paid (FIFO)
            $pendingStmt = $db->prepare("SELECT cl.* FROM city_ledger cl WHERE cl.company_id = ? AND cl.property_id = ? AND cl.type = 'charge' AND cl.status = 'pending' ORDER BY cl.recorded_at ASC");
            $pendingStmt->execute([$companyId, $propertyId]);
            $pendingCharges = $pendingStmt->fetchAll();

            $remainingPayment = $amount;
            $updateChargeStatus = $db->prepare("UPDATE city_ledger SET status = 'paid' WHERE id = ? AND company_id = ? AND property_id = ?");

            foreach ($pendingCharges as $charge) {
                $chargeAmt = (float)$charge['amount'];
                if ($remainingPayment >= $chargeAmt) {
                    $updateChargeStatus->execute([$charge['id'], $companyId, $propertyId]);
                    $remainingPayment -= $chargeAmt;
                } else {
                    break; // Partial payment tracking not implemented; FIFO settles fully paid items
                }
            }

            if ($shouldCommit) {
                $db->commit();
            }

            return [
                'success' => true,
                'new_balance' => $newBalance
            ];
        } catch (\Throwable $e) {
            if ($shouldCommit && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
