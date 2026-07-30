<?php
declare(strict_types=1);

require_once __DIR__ . '/../PricingEngine.php';

/**
 * RoomService - Shared room logic for both Admin API and Assistant PWA.
 */
class RoomService {

    /**
     * Get available rooms for a date range, grouped by category with rate plans.
     */
    public static function getAvailable(\PDO $db, string $checkIn, string $checkOut): array {
        $sql = "SELECT r.*, c.name as category_name
                FROM rooms r
                JOIN room_categories c ON r.category_id = c.id
                WHERE r.state != 'out_of_order'
                  AND r.id NOT IN (
                      SELECT b.room_id FROM bookings b
                      WHERE b.check_in < :check_out 
                        AND b.check_out > :check_in
                        AND b.payment_status != 'cancelled'
                  )
                ORDER BY c.name, r.room_number";
        
        $stmt = $db->prepare($sql);
        $stmt->execute(['check_in' => $checkIn, 'check_out' => $checkOut]);
        $rooms = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Group by category with rate plans
        $categories = [];
        foreach ($rooms as $room) {
            $catId = $room['category_id'];
            if (!isset($categories[$catId])) {
                // Get rate plans
                $rpStmt = $db->prepare("SELECT DISTINCT rate_plan_name FROM sliding_rates WHERE category_id = :cid");
                $rpStmt->execute(['cid' => $catId]);
                $plans = $rpStmt->fetchAll(\PDO::FETCH_COLUMN);

                $ratePlans = [];
                foreach ($plans as $planName) {
                    if (empty($planName)) continue;
                    try {
                        $totalCost = PricingEngine::calculateTotalCost($catId, $checkIn, $checkOut, $planName);
                        $ratePlans[] = ['name' => $planName, 'total_cost' => $totalCost];
                    } catch (\Exception $e) {
                        // Skip invalid rate plans
                    }
                }

                if (empty($ratePlans)) {
                    try {
                        $totalCost = PricingEngine::calculateTotalCost($catId, $checkIn, $checkOut, null);
                        $ratePlans[] = ['name' => 'Base Rate', 'total_cost' => $totalCost];
                    } catch (\Exception $e) {
                        $ratePlans[] = ['name' => 'Base Rate', 'total_cost' => 0];
                    }
                }

                $categories[$catId] = [
                    'category_id' => $catId,
                    'name'        => $room['category_name'],
                    'rate_plans'  => $ratePlans,
                    'rooms'       => [],
                ];
            }
            $categories[$catId]['rooms'][] = [
                'id'          => $room['id'],
                'room_number' => $room['room_number'],
                'state'       => $room['state'],
            ];
        }

        return array_values($categories);
    }

    /**
     * Get all rooms with current status (for housekeeping view).
     */
    public static function getAllWithStatus(\PDO $db): array {
        $stmt = $db->query("
            SELECT r.*, c.name as category_name,
                   CASE 
                       WHEN EXISTS (
                           SELECT 1 FROM bookings b 
                           WHERE b.room_id = r.id 
                             AND b.booking_status = 'checked_in'
                             AND b.payment_status != 'cancelled'
                       ) THEN 'occupied'
                       ELSE r.state
                   END as effective_state
            FROM rooms r 
            JOIN room_categories c ON r.category_id = c.id 
            ORDER BY c.name, r.room_number
        ");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Mark a room as clean.
     */
    public static function markClean(\PDO $db, int $roomId): bool {
        $stmt = $db->prepare("UPDATE rooms SET state = 'clean' WHERE id = :id AND state = 'dirty'");
        $stmt->execute(['id' => $roomId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark a room as dirty.
     */
    public static function markDirty(\PDO $db, int $roomId): bool {
        $stmt = $db->prepare("UPDATE rooms SET state = 'dirty' WHERE id = :id");
        $stmt->execute(['id' => $roomId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Get room details by ID.
     */
    public static function getById(\PDO $db, int $roomId): ?array {
        $stmt = $db->prepare("
            SELECT r.*, c.name as category_name 
            FROM rooms r 
            JOIN room_categories c ON r.category_id = c.id 
            WHERE r.id = ?
        ");
        $stmt->execute([$roomId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
