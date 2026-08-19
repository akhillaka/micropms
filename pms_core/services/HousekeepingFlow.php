<?php
declare(strict_types=1);

/**
 * Shared housekeeping side-effects: stayover tickets, DND, calendar 1-click clean.
 */
class HousekeepingFlow {
    /**
     * After a room is marked clean (calendar, dashboard, assistant, or ticket complete).
     */
    public static function afterRoomClean(\PDO $db, int $propertyId, int $roomId, bool $deepClean = false): void {
        if ($roomId <= 0 || $propertyId <= 0) {
            return;
        }
        try {
            if ($deepClean) {
                $db->prepare("UPDATE rooms SET state = 'clean', dnd = 0, last_deep_clean = CURRENT_TIMESTAMP WHERE id = ? AND property_id = ?")
                    ->execute([$roomId, $propertyId]);
            } else {
                $db->prepare("UPDATE rooms SET dnd = 0 WHERE id = ? AND property_id = ?")->execute([$roomId, $propertyId]);
            }
        } catch (\PDOException $e) {
        }

        try {
            $db->prepare("
                UPDATE guest_service_requests
                SET status = 'completed', resolved_at = NOW()
                WHERE property_id = ?
                  AND status IN ('pending', 'in_progress')
                  AND service_type IN ('Stayover Clean', 'Housekeeping', 'Do Not Disturb')
                  AND (
                    room_id = ?
                    OR booking_id IN (
                        SELECT id FROM bookings
                        WHERE room_id = ? AND property_id = ? AND booking_status = 'checked_in'
                    )
                  )
            ")->execute([$propertyId, $roomId, $roomId, $propertyId]);
        } catch (\PDOException $e) {
        }
    }

    public static function setDoNotDisturb(\PDO $db, int $propertyId, int $roomId, bool $on): void {
        if ($roomId <= 0) {
            return;
        }
        try {
            $db->prepare("UPDATE rooms SET dnd = ? WHERE id = ? AND property_id = ?")
                ->execute([$on ? 1 : 0, $roomId, $propertyId]);
        } catch (\PDOException $e) {
        }
    }
}
