<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../../pms_core/PhoneHelper.php';
require_once __DIR__ . '/../../../pms_core/AuditLogger.php';
require_once __DIR__ . '/../../../pms_core/SequenceGenerator.php';
require_once __DIR__ . '/../../../pms_core/TenantScope.php';

ApiHandler::run(function(\PDO $db) {
    // Session is checked by ApiHandler

    $data = ApiHandler::getJsonInput();
    $action = $data['action'] ?? $_GET['action'] ?? '';
    $propertyId = AuthHelper::getPropertyId();

    // Action: Search Guests by Phone or Name
    if ($action === 'search') {
        $q = trim((string)($data['q'] ?? $_GET['q'] ?? ''));
        
        if (strlen($q) < 2) {
            ApiResponse::success(['guests' => []]);
        }

        $escapedQ = str_replace(['%', '_'], ['\\%', '\\_'], $q);
        $searchTerm = "%{$escapedQ}%";
        
        $sql = "SELECT id, name, phone, email, age, city, state, country, pincode, photo, id_proof_front, id_proof_back 
                FROM guests 
                WHERE property_id = :pid AND (name LIKE :q_name OR phone LIKE :q_phone)
                ORDER BY created_at DESC 
                LIMIT 5";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            'pid' => $propertyId,
            'q_name' => $searchTerm,
            'q_phone' => $searchTerm
        ]);
        $guests = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $enhancedGuests = [];
        foreach ($guests as $g) {
            $guestId = (int)$g['id'];

            $stayStmt = $db->prepare("SELECT COUNT(*) FROM bookings WHERE guest_id = :gid AND property_id = :pid AND payment_status != 'cancelled'");
            $stayStmt->execute(['gid' => $guestId, 'pid' => $propertyId]);
            $stayCount = (int)$stayStmt->fetchColumn();

            $lastStayStmt = $db->prepare("SELECT MAX(check_in) FROM bookings WHERE guest_id = :gid AND property_id = :pid AND payment_status != 'cancelled'");
            $lastStayStmt->execute(['gid' => $guestId, 'pid' => $propertyId]);
            $lastStay = $lastStayStmt->fetchColumn();
            $lastStayDate = $lastStay ? date('Y-m-d', strtotime($lastStay)) : 'None';

            $prefRoomStmt = $db->prepare("
                SELECT c.name, COUNT(*) as cnt 
                FROM bookings b 
                JOIN rooms r ON b.room_id = r.id 
                JOIN room_categories c ON r.category_id = c.id 
                WHERE b.guest_id = :gid AND b.property_id = :pid AND b.payment_status != 'cancelled'
                GROUP BY c.id 
                ORDER BY cnt DESC 
                LIMIT 1
            ");
            $prefRoomStmt->execute(['gid' => $guestId, 'pid' => $propertyId]);
            $prefCategory = $prefRoomStmt->fetchColumn() ?: 'None';

            $balStmt = $db->prepare("
                SELECT COALESCE(SUM(fl.amount), 0) as balance 
                FROM bookings b 
                JOIN folio_ledger fl ON b.id = fl.booking_id 
                WHERE b.guest_id = :gid AND b.property_id = :pid AND b.booking_status IN ('booked', 'checked_in') AND b.payment_status != 'cancelled'
            ");
            $balStmt->execute(['gid' => $guestId, 'pid' => $propertyId]);
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
            $guestId = (int)$existing['id'];
            $currentStmt = $db->prepare("SELECT name, age, city, state, email, id_proof_front, id_proof_back, photo FROM guests WHERE id = ? AND property_id = ?");
            $currentStmt->execute([$guestId, $propertyId]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $updateCity = ($city !== '' && $city !== 'Unknown') ? $city : (string)($current['city'] ?? 'Unknown');
            $updateState = ($state !== '' && $state !== 'Unknown') ? $state : (string)($current['state'] ?? 'Unknown');
            $updateEmail = ($email !== '') ? $email : (string)($current['email'] ?? '');
            $updateAge = $age ?? ($current['age'] !== null ? (int)$current['age'] : null);

            $updateStmt = $db->prepare("
                UPDATE guests
                SET name = :name,
                    age = :age,
                    city = :city,
                    state = :state,
                    email = :email
                WHERE id = :id AND property_id = :pid
            ");
            $updateStmt->execute([
                'name' => $name,
                'age' => $updateAge,
                'city' => $updateCity,
                'state' => $updateState,
                'email' => $updateEmail !== '' ? $updateEmail : null,
                'id' => $guestId,
                'pid' => $propertyId,
            ]);

            $hasIdProof = !empty($current['id_proof_front']) || !empty($current['id_proof_back']);
            ApiResponse::success([
                'message' => 'Existing guest profile updated',
                'guest_id' => $guestId,
                'existing' => true,
                'guest' => [
                    'id' => $guestId,
                    'name' => $name,
                    'phone' => $phone,
                    'display_phone' => PhoneHelper::display($phone),
                    'city' => $updateCity,
                    'state' => $updateState,
                    'id_proof_front' => $current['id_proof_front'] ?? null,
                    'id_proof_back' => $current['id_proof_back'] ?? null,
                    'photo' => $current['photo'] ?? null,
                    'has_id_proof' => $hasIdProof,
                ],
            ]);
            return;
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
            'existing' => false,
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

        $propertyId = AuthHelper::getPropertyId();
        TenantScope::guest($db, $guestId, $propertyId);

        $fieldsToUpdate = [];
        $params = ['id' => $guestId, 'pid' => $propertyId];

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
            $fieldsToUpdate[] = "city = :city";
            $params['city'] = trim($data['address']);
        }
        if (!empty($data['pincode'])) {
            $fieldsToUpdate[] = "pincode = :pincode";
            $params['pincode'] = trim($data['pincode']);
        }
        $guestCols = [];
        try {
            $colStmt = $db->query("SHOW COLUMNS FROM guests");
            while ($col = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                $guestCols[strtolower((string)$col['Field'])] = true;
            }
        } catch (\Throwable $e) {
            $guestCols = [];
        }

        if (!empty($data['id_number']) && isset($guestCols['id_number'])) {
            $fieldsToUpdate[] = "id_number = :id_number";
            $params['id_number'] = trim((string)$data['id_number']);
        }
        if (!empty($data['id_type']) && isset($guestCols['id_type'])) {
            $fieldsToUpdate[] = "id_type = :id_type";
            $params['id_type'] = trim((string)$data['id_type']);
        }
        if (!empty($data['gender']) && isset($guestCols['gender'])) {
            $fieldsToUpdate[] = "gender = :gender";
            $params['gender'] = trim((string)$data['gender']);
        }
        if (!empty($data['age']) && (int)$data['age'] > 0) {
            $fieldsToUpdate[] = "age = :age";
            $params['age'] = (int)$data['age'];
        }
        $dobRaw = trim((string)($data['dob'] ?? $data['date_of_birth'] ?? ''));
        if ($dobRaw !== '' && isset($guestCols['date_of_birth'])) {
            $dobTs = strtotime(str_replace('/', '-', $dobRaw));
            if ($dobTs) {
                $fieldsToUpdate[] = "date_of_birth = :dob";
                $params['dob'] = date('Y-m-d', $dobTs);
            }
        }

        if (empty($fieldsToUpdate)) {
            ApiResponse::success(['message' => 'No changes required']);
        }

        $sql = "UPDATE guests SET " . implode(', ', $fieldsToUpdate) . " WHERE id = :id AND property_id = :pid";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        ApiResponse::success(['message' => 'Guest profile updated successfully', 'guest_id' => $guestId]);
    }

    else {
        ApiResponse::error('Invalid action');
    }

}, true, true, false);
