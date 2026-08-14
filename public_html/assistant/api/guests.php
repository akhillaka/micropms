<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/PhoneHelper.php';
require_once __DIR__ . '/../../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../../pms_core/SequenceGenerator.php';

ApiHandler::run(function(\PDO $db) {
    // Session is checked by ApiHandler

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $data['action'] ?? $_GET['action'] ?? '';

    // Action: Search Guests by Phone or Name
    if ($action === 'search') {
        $q = trim((string)($data['q'] ?? $_GET['q'] ?? ''));
        
        if (strlen($q) < 2) {
            ApiResponse::success(['guests' => []]);
        }

        // Escape LIKE special characters to prevent wildcard abuse
        $escapedQ = str_replace(['%', '_'], ['\\%', '\\_'], $q);
        $searchTerm = "%{$escapedQ}%";
        
        $sql = "SELECT id, name, phone, email, age, city, state, country, pincode, photo, id_proof_front, id_proof_back 
                FROM guests 
                WHERE name LIKE :q_name OR phone LIKE :q_phone 
                ORDER BY created_at DESC 
                LIMIT 5";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            'q_name' => $searchTerm,
            'q_phone' => $searchTerm
        ]);
        $guests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $enhancedGuests = [];
        foreach ($guests as $g) {
            $guestId = (int)$g['id'];

            // 1. Calculate stay count
            $stayStmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE guest_id = :gid AND payment_status != 'cancelled'");
            $stayStmt->execute(['gid' => $guestId]);
            $stayCount = (int)$stayStmt->fetchColumn();

            // 2. Get last stay check-in date
            $lastStayStmt = $db->prepare("SELECT MAX(check_in) FROM bookings WHERE guest_id = :gid AND payment_status != 'cancelled'");
            $lastStayStmt->execute(['gid' => $guestId]);
            $lastStay = $lastStayStmt->fetchColumn();
            $lastStayDate = $lastStay ? date('Y-m-d', strtotime($lastStay)) : 'None';

            // 3. Get preferred room category name
            $prefRoomStmt = $db->prepare("
                SELECT c.name, COUNT(*) as cnt 
                FROM bookings b 
                JOIN rooms r ON b.room_id = r.id 
                JOIN room_categories c ON r.category_id = c.id 
                WHERE b.guest_id = :gid AND b.payment_status != 'cancelled'
                GROUP BY c.id 
                ORDER BY cnt DESC 
                LIMIT 1
            ");
            $prefRoomStmt->execute(['gid' => $guestId]);
            $prefCategory = $prefRoomStmt->fetchColumn() ?: 'None';

            // 4. Calculate outstanding balance
            $balStmt = $db->prepare("
                SELECT COALESCE(SUM(fl.amount), 0) as balance 
                FROM bookings b 
                JOIN folio_ledger fl ON b.id = fl.booking_id 
                WHERE b.guest_id = :gid AND b.booking_status IN ('booked', 'checked_in') AND b.payment_status != 'cancelled'
            ");
            $balStmt->execute(['gid' => $guestId]);
            $balance = (float)$balStmt->fetchColumn();

            $enhancedGuests[] = [
                'id' => $g['id'],
                'name' => $g['name'],
                'phone' => $g['phone'],
                'display_phone' => PhoneHelper::display($g['phone']),
                'email' => $g['email'],
                'age' => $g['age'],
                'city' => $g['city'],
                'state' => $g['state'],
                'country' => $g['country'],
                'photo' => $g['photo'],
                'id_proof_front' => $g['id_proof_front'],
                'id_proof_back' => $g['id_proof_back'],
                'has_id_proof' => (!empty($g['id_proof_front']) || !empty($g['id_proof_back'])),
                'stay_count' => $stayCount,
                'last_stay' => $lastStayDate,
                'preferred_room' => $prefCategory,
                'outstanding_balance' => $balance
            ];
        }

        ApiResponse::success(['guests' => $enhancedGuests]);
    }

    // Action: Create/Insert a new guest profile
    elseif ($action === 'create') {
        $phoneRaw = trim($data['phone'] ?? '');
        $name = trim($data['name'] ?? '');
        $age = isset($data['age']) ? (int)$data['age'] : null;
        $city = trim($data['city'] ?? 'Unknown');
        $state = trim($data['state'] ?? 'Unknown');
        $email = trim($data['email'] ?? '');
        
        $phone = PhoneHelper::toLocal($phoneRaw);
        if ($phone === null) {
            ApiResponse::error('Invalid phone number. Please enter a 10-digit mobile number.');
        }

        if (empty($name)) {
            ApiResponse::error('Guest name is required');
        }

        $propertyId = AuthHelper::getPropertyId();

        // Check if guest with same mobile number already exists FOR THIS PROPERTY
        $checkStmt = $db->prepare("SELECT id FROM guests WHERE phone = :phone AND property_id = :pid");
        $checkStmt->execute(['phone' => $phone, 'pid' => $propertyId]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            // Self-repair: update details if blank, but return existing guest
            $guestId = (int)$existing['id'];
            $updateStmt = $db->prepare("
                UPDATE guests 
                SET name = :name, 
                    age = COALESCE(:age, age), 
                    city = COALESCE(NULLIF(:city, 'Unknown'), city), 
                    state = COALESCE(NULLIF(:state, 'Unknown'), state),
                    email = COALESCE(NULLIF(:email, ''), email)
                WHERE id = :id AND property_id = :pid
            ");
            $updateStmt->execute([
                'name' => $name,
                'age' => $age,
                'city' => $city,
                'state' => $state,
                'email' => $email,
                'id' => $guestId,
                'pid' => $propertyId
            ]);
        } else {
            // Create new guest
            $stmt = $db->prepare("
                INSERT INTO guests (property_id, phone, name, age, city, state, email) 
                VALUES (:pid, :phone, :name, :age, :city, :state, :email)
            ");
            $stmt->execute([
                'pid' => $propertyId,
                'phone' => $phone,
                'name' => $name,
                'age' => $age,
                'city' => $city,
                'state' => $state,
                'email' => $email
            ]);
            $guestId = (int)$db->lastInsertId();
            SequenceGenerator::assignDisplayId($db, 'guests', $guestId, 'SEQ_GUEST_FORMAT');
            
            // Audit log for new guest creation
            AuditLogger::log($_SESSION['user_id'] ?? null, 'CREATE_GUEST', 'GUEST', $guestId, [
                'name' => $name,
                'phone' => $phone,
                'source' => 'assistant'
            ]);
        }

        ApiResponse::success([
            'message' => 'Guest profile processed successfully',
            'guest_id' => $guestId,
            'guest' => [
                'id' => $guestId,
                'name' => $name,
                'phone' => $phone,
                'display_phone' => PhoneHelper::display($phone),
                'city' => $city,
                'state' => $state
            ]
        ]);
    }

    // Action: Update Guest Profile with OCR & detailed info
    elseif ($action === 'update_profile') {
        $guestId = (int)($data['guest_id'] ?? 0);
        if (!$guestId) {
            ApiResponse::error('Guest ID is required');
        }

        $fieldsToUpdate = [];
        $params = ['id' => $guestId];

        if (!empty($data['name'])) {
            $fieldsToUpdate[] = "name = :name";
            $params['name'] = trim($data['name']);
        }
        if (!empty($data['phone'])) {
            $phone = PhoneHelper::toLocal(trim($data['phone']));
            if ($phone) {
                $fieldsToUpdate[] = "phone = :phone";
                $params['phone'] = $phone;
            }
        }
        if (!empty($data['address'])) {
            $fieldsToUpdate[] = "city = COALESCE(NULLIF(:city, ''), city)";
            $params['city'] = trim($data['address']);
        }
        if (!empty($data['pincode'])) {
            $fieldsToUpdate[] = "pincode = :pincode";
            $params['pincode'] = trim($data['pincode']);
        }

        if (empty($fieldsToUpdate)) {
            ApiResponse::success(['message' => 'No changes required']);
        }

        $sql = "UPDATE guests SET " . implode(', ', $fieldsToUpdate) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        ApiResponse::success(['message' => 'Guest profile updated successfully', 'guest_id' => $guestId]);
    }

    else {
        ApiResponse::error('Invalid action');
    }

}, true, true, false);
