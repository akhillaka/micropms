<?php
declare(strict_types=1);

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../AuthHelper.php';

class DashboardService {

    private \PDO $db;
    private int $propertyId;
    private string $todayStr;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->propertyId = AuthHelper::getPropertyId();
        $this->todayStr = date('Y-m-d');
    }

    public function getAllBookings(): array {
        $stmt = $this->db->prepare("
            SELECT b.*, r.room_number, c.name as category_name, g.name as guest_name, g.phone as guest_phone, g.photo as guest_photo,
                COALESCE((
                    SELECT SUM(fl.amount) FROM folio_ledger fl WHERE fl.booking_id = b.id
                ), 0) AS ledger_balance
            FROM bookings b 
            JOIN rooms r ON b.room_id = r.id 
            JOIN room_categories c ON r.category_id = c.id 
            LEFT JOIN guests g ON b.guest_id = g.id 
            WHERE b.property_id = :pid
              AND b.deleted_at IS NULL
              AND (
                  b.booking_status IN ('booked', 'checked_in')
                  OR b.check_in >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  OR b.check_out >= DATE_SUB(NOW(), INTERVAL 30 DAY)
              )
            ORDER BY b.check_in ASC
        ");
        $stmt->execute(['pid' => $this->propertyId]);
        return $stmt->fetchAll();
    }

    public function getSummaryCounts(array $allBookings): array {
        $summary = [
            'active' => 0, 'in_house' => 0, 'arrivals' => 0, 
            'departures' => 0, 'cancelled' => 0, 'on_hold' => 0
        ];

        foreach ($allBookings as $b) {
            $biDate = substr($b['check_in'], 0, 10);
            $boDate = substr($b['check_out'], 0, 10);
            $status = $b['booking_status'];
            $paymentStatus = $b['payment_status'];

            if ($status === 'booked' || $status === 'checked_in') $summary['active']++;
            if ($status === 'checked_in') $summary['in_house']++;
            if ($status === 'booked' && $biDate === $this->todayStr) $summary['arrivals']++;
            if ($status === 'checked_in' && $boDate === $this->todayStr) $summary['departures']++;
            if ($status === 'cancelled') $summary['cancelled']++;
            // Hold = unpaid reservation still awaiting confirmation, not in-house or checked-out.
            if ($paymentStatus === 'pending_hold' && $status === 'booked') $summary['on_hold']++;
        }
        return $summary;
    }

    public function getOccupancyStats(): array {
        $trStmt = $this->db->prepare("SELECT COUNT(*) FROM rooms WHERE property_id = :pid AND deleted_at IS NULL");
        $trStmt->execute(['pid' => $this->propertyId]);
        $totalRooms = (int)$trStmt->fetchColumn();

        $occStmt = $this->db->prepare("
            SELECT COUNT(DISTINCT room_id) FROM bookings 
            WHERE property_id = :pid AND deleted_at IS NULL
              AND ((booking_status = 'checked_in')
               OR (booking_status = 'booked' AND DATE(check_in) <= :today1 AND DATE(check_out) > :today2))
        ");
        $occStmt->execute(['pid' => $this->propertyId, 'today1' => $this->todayStr, 'today2' => $this->todayStr]);
        $occupied = (int)$occStmt->fetchColumn();

        return [
            'total_rooms' => $totalRooms,
            'occupied_today' => $occupied,
            'percentage' => $totalRooms > 0 ? round(($occupied / $totalRooms) * 100) : 0
        ];
    }

    public function getRevenueToday(): float {
        $revStmt = $this->db->prepare("
            SELECT COALESCE(SUM(ABS(fl.amount)),0) FROM folio_ledger fl 
            JOIN bookings b ON fl.booking_id = b.id
            WHERE fl.amount < 0 AND DATE(fl.recorded_at) = :today AND b.property_id = :pid AND b.deleted_at IS NULL
        ");
        $revStmt->execute(['today' => $this->todayStr, 'pid' => $this->propertyId]);
        return (float)$revStmt->fetchColumn();
    }

    public function getAvailabilityData(): array {
        $catStmt = $this->db->prepare("SELECT * FROM room_categories WHERE property_id = :pid AND deleted_at IS NULL ORDER BY name ASC");
        $catStmt->execute(['pid' => $this->propertyId]);
        $categories = $catStmt->fetchAll();

        $roomCounts = [];
        $rcStmt = $this->db->prepare("SELECT category_id, COUNT(*) as total FROM rooms WHERE property_id = :pid AND deleted_at IS NULL GROUP BY category_id");
        $rcStmt->execute(['pid' => $this->propertyId]);
        foreach ($rcStmt->fetchAll() as $row) {
            $roomCounts[$row['category_id']] = (int)$row['total'];
        }

        $occCounts = [];
        $occStmtBatch = $this->db->prepare("
            SELECT r.category_id, COUNT(DISTINCT b.room_id) as occupied
            FROM bookings b
            JOIN rooms r ON b.room_id = r.id
            WHERE b.property_id = :pid AND b.deleted_at IS NULL
              AND b.booking_status IN ('booked', 'checked_in')
              AND DATE(b.check_in) <= :today1 AND DATE(b.check_out) > :today2
            GROUP BY r.category_id
        ");
        $occStmtBatch->execute(['pid' => $this->propertyId, 'today1' => $this->todayStr, 'today2' => $this->todayStr]);
        foreach ($occStmtBatch->fetchAll() as $row) {
            $occCounts[$row['category_id']] = (int)$row['occupied'];
        }

        $prices = [];
        $priceStmtBatch = $this->db->prepare("SELECT category_id, price FROM sliding_rates WHERE hours = 24 AND property_id = :pid");
        $priceStmtBatch->execute(['pid' => $this->propertyId]);
        foreach ($priceStmtBatch->fetchAll() as $row) {
            $prices[$row['category_id']] = (float)$row['price'];
        }

        $data = [];
        foreach ($categories as $cat) {
            $catId = $cat['id'];
            $total = $roomCounts[$catId] ?? 0;
            $occupied = $occCounts[$catId] ?? 0;
            $avail = $total - $occupied;
            $price = $prices[$catId] ?? $cat['base_price'];
            $data[] = [
                'id' => $catId,
                'name' => $cat['name'],
                'total' => $total,
                'occupied' => $occupied,
                'available' => $avail,
                'price' => $price
            ];
        }
        return $data;
    }

    public function getPendingHousekeepingCount(): int {
        $hkStmt = $this->db->prepare("SELECT COUNT(*) FROM rooms WHERE property_id = :pid AND state = 'dirty' AND deleted_at IS NULL");
        $hkStmt->execute(['pid' => $this->propertyId]);
        return (int)$hkStmt->fetchColumn();
    }
}
