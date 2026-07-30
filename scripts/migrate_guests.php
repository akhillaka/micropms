<?php
require_once __DIR__ . '/../pms_core/Database.php';
$db = Database::getInstance()->getConnection();

try {
    // 1. Create guests table
    $db->exec("
        CREATE TABLE IF NOT EXISTS guests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            phone VARCHAR(20) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            age INT,
            city VARCHAR(100),
            state VARCHAR(100),
            id_proof_front VARCHAR(255),
            id_proof_back VARCHAR(255),
            photo VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    ");

    // 2. Add guest_id to bookings if not exists
    $stmt = $db->query("SHOW COLUMNS FROM bookings LIKE 'guest_id'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE bookings ADD COLUMN guest_id INT AFTER room_id");
        $db->exec("ALTER TABLE bookings ADD FOREIGN KEY (guest_id) REFERENCES guests(id) ON DELETE RESTRICT");
    }

    // 3. Migrate data
    $bookings = $db->query("SELECT * FROM bookings")->fetchAll(PDO::FETCH_ASSOC);
    
    $insertGuest = $db->prepare("INSERT IGNORE INTO guests (phone, name, id_proof_front, id_proof_back, photo) VALUES (:phone, :name, :idf, :idb, :photo)");
    $getGuest = $db->prepare("SELECT id FROM guests WHERE phone = :phone");
    $updateBooking = $db->prepare("UPDATE bookings SET guest_id = :guest_id WHERE id = :booking_id");

    foreach ($bookings as $b) {
        // Insert guest if doesn't exist
        $insertGuest->execute([
            'phone' => $b['guest_phone'],
            'name' => $b['guest_name'],
            'idf' => $b['id_proof_front'] ?? null,
            'idb' => $b['id_proof_back'] ?? null,
            'photo' => $b['guest_photo'] ?? null
        ]);
        
        // Get guest_id
        $getGuest->execute(['phone' => $b['guest_phone']]);
        $guestId = $getGuest->fetchColumn();
        
        // Update booking
        $updateBooking->execute([
            'guest_id' => $guestId,
            'booking_id' => $b['id']
        ]);
    }

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
