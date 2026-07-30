<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

class PricingEngine {
    
    public static function calculateTotalCost(int $categoryId, string $checkInStr, string $checkOutStr, ?string $ratePlanName = null): float {
        // The daily rate is now strictly defined by the 24-hour sliding rate tier
        $baseDailyRate = self::getRateForHours($categoryId, 24, $ratePlanName);
        
        if ($baseDailyRate <= 0.0) {
            $fallback = self::getRateDataForHours($categoryId, 1, $ratePlanName);
            $baseDailyRate = $fallback['price'];
            if ($baseDailyRate <= 0.0) {
                return 0.0;
            }
        }

        try {
            $checkIn = new \DateTime($checkInStr);
            $checkOut = new \DateTime($checkOutStr);
        } catch (\Exception $e) {
            throw new \Exception("Invalid date format provided for check-in or check-out.");
        }
        
        if ($checkOut <= $checkIn) {
            throw new \Exception("Check-out time must be after check-in time.");
        }

        $interval = $checkIn->diff($checkOut);
        $totalHours = ($interval->days * 24) + $interval->h;
        if ($interval->i > 0) {
            $totalHours++; // round up partial hour
        }

        $fullDays = (int)floor($totalHours / 24);
        $residualHours = $totalHours % 24;

        $costForDays = $fullDays * $baseDailyRate;
        $costForHours = 0.0;

        if ($residualHours > 0) {
            $costForHours = self::getRateForHours($categoryId, $residualHours, $ratePlanName);
        }

        return $costForDays + $costForHours;
    }

    /**
     * @return array{price: float, name: string}
     */
    private static function getRateDataForHours(int $categoryId, int $hours, ?string $ratePlanName = null): array {
        $db = Database::getInstance()->getConnection();
        
        if ($ratePlanName !== null) {
            $stmt = $db->prepare("SELECT price, rate_plan_name FROM sliding_rates WHERE category_id = :category_id AND rate_plan_name = :rate_name AND hours >= :hours ORDER BY hours ASC LIMIT 1");
            $stmt->execute(['category_id' => $categoryId, 'rate_name' => $ratePlanName, 'hours' => $hours]);
        } else {
            $stmt = $db->prepare("SELECT price, rate_plan_name FROM sliding_rates WHERE category_id = :category_id AND hours >= :hours ORDER BY hours ASC LIMIT 1");
            $stmt->execute(['category_id' => $categoryId, 'hours' => $hours]);
        }
        
        $row = $stmt->fetch();
        
        if ($row !== false) {
            return ['price' => (float)$row['price'], 'name' => (string)$row['rate_plan_name']];
        }
        
        if ($ratePlanName !== null) {
            $stmt = $db->prepare("SELECT price, rate_plan_name FROM sliding_rates WHERE category_id = :category_id AND rate_plan_name = :rate_name ORDER BY hours DESC LIMIT 1");
            $stmt->execute(['category_id' => $categoryId, 'rate_name' => $ratePlanName]);
        } else {
            $stmt = $db->prepare("SELECT price, rate_plan_name FROM sliding_rates WHERE category_id = :category_id ORDER BY hours DESC LIMIT 1");
            $stmt->execute(['category_id' => $categoryId]);
        }
        
        $row = $stmt->fetch();
        return $row !== false 
            ? ['price' => (float)$row['price'], 'name' => (string)$row['rate_plan_name']] 
            : ['price' => 0.0, 'name' => ''];
    }

    private static function getRateForHours(int $categoryId, int $hours, ?string $ratePlanName = null): float {
        $data = self::getRateDataForHours($categoryId, $hours, $ratePlanName);
        return $data['price'];
    }

    /**
     * @return array<int, array{day: int, duration: string, cost: float, name: string}>
     */
    public static function getCostBreakdown(int $categoryId, string $checkInStr, string $checkOutStr, ?string $ratePlanName = null): array {
        $baseRateData = self::getRateDataForHours($categoryId, 24, $ratePlanName);
        $baseDailyRate = $baseRateData['price'];
        
        try {
            $checkIn = new \DateTime($checkInStr);
            $checkOut = new \DateTime($checkOutStr);
        } catch (\Exception $e) {
            throw new \Exception("Invalid date format provided for check-in or check-out in breakdown.");
        }

        if ($checkOut <= $checkIn) {
            throw new \Exception("Check-out time must be after check-in time.");
        }

        $interval = $checkIn->diff($checkOut);
        $totalHours = ($interval->days * 24) + $interval->h;
        if ($interval->i > 0) {
            $totalHours++;
        }

        $fullDays = (int)floor($totalHours / 24);
        $residualHours = $totalHours % 24;

        $breakdown = [];
        $dayNum = 1;
        
        for ($i = 0; $i < $fullDays; $i++) {
            $breakdown[] = [
                'day' => $dayNum,
                'duration' => '1 Day',
                'cost' => $baseDailyRate,
                'name' => $baseRateData['name'] !== '' ? $baseRateData['name'] : '24H Rate'
            ];
            $dayNum++;
        }
        
        if ($residualHours > 0) {
            $resData = self::getRateDataForHours($categoryId, $residualHours, $ratePlanName);
            $breakdown[] = [
                'day' => $dayNum,
                'duration' => $residualHours . ' Hour' . ($residualHours > 1 ? 's' : ''),
                'cost' => $resData['price'],
                'name' => $resData['name'] !== '' ? $resData['name'] : $residualHours . 'H Rate'
            ];
        }
        
        return $breakdown;
    }
}
