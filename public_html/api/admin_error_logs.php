<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/ErrorTracker.php';

ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('view_error_logs');

    // For GET requests (list errors)
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? 'list';
        
        if ($action === 'list') {
            $severity = $_GET['severity'] ?? '';
            $category = $_GET['category'] ?? '';
            $resolved = isset($_GET['resolved']) ? (int)$_GET['resolved'] : null;
            $page     = max(1, (int)($_GET['page'] ?? 1));
            $limit    = 25;
            $offset   = ($page - 1) * $limit;

            $where = [];
            $params = [];

            if (!empty($severity)) {
                $where[] = "severity = :severity";
                $params['severity'] = $severity;
            }
            if (!empty($category)) {
                $where[] = "category = :category";
                $params['category'] = $category;
            }
            if ($resolved !== null) {
                $where[] = "resolved = :resolved";
                $params['resolved'] = $resolved;
            }

            $whereSql = '';
            if (!empty($where)) {
                $whereSql = "WHERE " . implode(" AND ", $where);
            }

            // Total count for pagination
            $countStmt = $db->prepare("SELECT COUNT(*) FROM error_logs $whereSql");
            $countStmt->execute($params);
            $totalCount = (int)$countStmt->fetchColumn();

            // Fetch logs with staff username if resolved
            $sql = "
                SELECT e.*, s.username as resolved_by_username
                FROM error_logs e
                LEFT JOIN staff_users s ON e.resolved_by = s.id
                $whereSql
                ORDER BY e.created_at DESC
                LIMIT :limit OFFSET :offset
            ";
            
            $stmt = $db->prepare($sql);
            
            // Bind value types properly for LIMIT/OFFSET
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $logs = $stmt->fetchAll();

            // Decode context JSON for response
            foreach ($logs as &$log) {
                $log['context'] = $log['context'] ? json_decode((string)$log['context'], true) : null;
            }

            ApiResponse::success([
                'logs' => $logs,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages'  => ceil($totalCount / $limit),
                    'total_records' => $totalCount,
                    'limit'        => $limit
                ]
            ]);
        } else {
            throw new Exception("Invalid action");
        }
    } 
    // For POST requests (resolve / bulk resolve)
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        AuthHelper::requirePermission('resolve_error_logs');
        
        $data = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? null;
        $staffId = (int)$_SESSION['user_id'];

        if ($action === 'resolve') {
            $errorId = (int)($data['id'] ?? 0);
            if (!$errorId) {
                throw new Exception("Missing error ID");
            }
            
            $success = ErrorTracker::resolve($errorId, $staffId);
            if ($success) {
                ApiResponse::success(['message' => 'Error marked as resolved']);
            } else {
                throw new Exception("Failed to resolve error or it is already resolved");
            }
        } elseif ($action === 'bulk_resolve') {
            $category = trim($data['category'] ?? '');
            if (empty($category)) {
                throw new Exception("Missing category for bulk resolve");
            }
            
            $count = ErrorTracker::bulkResolve($category, $staffId);
            ApiResponse::success([
                'message' => 'Bulk resolution completed',
                'resolved_count' => $count
            ]);
        } else {
            throw new Exception("Invalid action");
        }
    } else {
        throw new Exception("Method not allowed");
    }

}, false, true, false);
