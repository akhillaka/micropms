<?php
declare(strict_types=1);
require_once __DIR__ . '/Database.php';

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if 'Double Room' exists
    $stmt = $db->prepare("SELECT id FROM room_categories WHERE name LIKE '%Double Room%' LIMIT 1");
    $stmt->execute();
    $catId = $stmt->fetchColumn();
    
    if (!$catId) {
        // Create Double Room category
        $db->exec("INSERT INTO room_categories (name, base_daily_rate) VALUES ('Double Room', 2000)");
        $catId = $db->lastInsertId();
        echo "Created 'Double Room' category with ID $catId.\n";
    }
    
    // Base rate for 24 hours = 2000. Let's make an exponential curve or just simple math.
    // E.g., 1 hr = 300, 2 hr = 400, ... 24 hr = 2000.
    
    $insertStmt = $db->prepare("INSERT INTO sliding_rates (category_id, hours, price) VALUES (:cat_id, :hours, :price) ON DUPLICATE KEY UPDATE price = :update_price");
    
    $basePrice = 300;
    $increment = (2000 - 300) / 23; // 24 hours - 1 hour
    
    for ($i = 1; $i <= 24; $i++) {
        $price = round($basePrice + ($increment * ($i - 1)));
        $insertStmt->execute([
            'cat_id' => $catId,
            'hours' => $i,
            'price' => $price,
            'update_price' => $price
        ]);
    }
    
    echo "Successfully injected 1 to 24 hour rates for Double Room (Category ID: $catId).\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
