<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';

ApiHandler::run(function(\PDO $db) {
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) {
        ApiResponse::success(['guests' => []]);
    }

    // Escape LIKE special characters to prevent wildcard abuse
    $escapedQ = str_replace(['%', '_'], ['\\%', '\\_'], $q);
    $searchTerm = "%{$escapedQ}%";
    
    $sql = "SELECT g.id, g.name as guest_name, g.phone as guest_phone 
            FROM guests g 
            WHERE g.phone LIKE :q1 OR LOWER(g.name) LIKE LOWER(:q2) 
            ORDER BY g.created_at DESC 
            LIMIT 5";
    
    $stmt = $db->prepare($sql);
    $stmt->execute(['q1' => $searchTerm, 'q2' => $searchTerm]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ApiResponse::success(['guests' => $results]);

}, true, false, true);
