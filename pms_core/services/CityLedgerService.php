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
        $stmt = $db->prepare("SELECT * FROM companies WHERE property_id = ? ORDER BY name ASC");
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
            $cStmt = $db->prepare("SELECT * FROM companies WHERE id = ? AND property_id = ? FOR UPDATE");
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
            $desc = "Balance transferred to City Ledger - Company: " . $company['name'];
            $insertFolio = $db->prepare("
                INSERT INTO folio_ledger (property_id, booking_id, transaction_type, amount, transaction_ref, description)
                VALUES (?, ?, 'payment', ?, 'CITY_LEDGER', ?)
            ");
            $insertFolio->execute([$propertyId, $bookingId, -$guestBalance, $desc]);
            $entryId = (int)$db->lastInsertId();

            // Assign receipt sequence ID
            if (class_exists('SequenceGenerator')) {
                SequenceGenerator::assignDisplayId($db, 'folio_ledger', $entryId, 'SEQ_RECEIPT_FORMAT');
            }

            // 4. Record a charge entry in the city_ledger table
            $insertCL = $db->prepare("
                INSERT INTO city_ledger (company_id, booking_id, amount, type, status)
                VALUES (?, ?, ?, 'charge', 'pending')
            ");
            $insertCL->execute([$companyId, $bookingId, $guestBalance]);

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
            $cStmt = $db->prepare("SELECT * FROM companies WHERE id = ? AND property_id = ? FOR UPDATE");
            $cStmt->execute([$companyId, $propertyId]);
            $company = $cStmt->fetch();

            if (!$company) {
                throw new \Exception("Company not found.");
            }

            // 1. Insert payment entry in city_ledger
            $insertCL = $db->prepare("
                INSERT INTO city_ledger (company_id, amount, type, status)
                VALUES (?, ?, 'payment', 'paid')
            ");
            $insertCL->execute([$companyId, $amount]);

            // 2. Reduce company balance
            $newBalance = max(0.00, (float)$company['balance'] - $amount);
            $updCompany = $db->prepare("UPDATE companies SET balance = ? WHERE id = ? AND property_id = ?");
            $updCompany->execute([$newBalance, $companyId, $propertyId]);

            // 3. Mark matching pending charges as paid (FIFO)
            $pendingStmt = $db->prepare("SELECT cl.* FROM city_ledger cl JOIN companies c ON c.id = cl.company_id WHERE cl.company_id = ? AND c.property_id = ? AND cl.type = 'charge' AND cl.status = 'pending' ORDER BY cl.recorded_at ASC");
            $pendingStmt->execute([$companyId, $propertyId]);
            $pendingCharges = $pendingStmt->fetchAll();

            $remainingPayment = $amount;
            $updateChargeStatus = $db->prepare("UPDATE city_ledger SET status = 'paid' WHERE id = ? AND company_id = ?");

            foreach ($pendingCharges as $charge) {
                $chargeAmt = (float)$charge['amount'];
                if ($remainingPayment >= $chargeAmt) {
                    $updateChargeStatus->execute([$charge['id'], $companyId]);
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
