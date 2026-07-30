<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';

class ApiResponse {
    public static function success(array $data = [], int $statusCode = 200): void {
        http_response_code($statusCode);
        $response = ['success' => true];
        if (!empty($data)) {
            $response = array_merge($response, $data);
        }
        echo json_encode($response, JSON_THROW_ON_ERROR);
        exit;
    }

    public static function error(string $message, int $statusCode = 400, array $extra = []): void {
        throw new ApiException($message, $statusCode, $extra);
    }

    public static function sendErrorResponse(string $message, int $statusCode = 400, array $extra = []): void {
        http_response_code($statusCode);
        $response = ['success' => false, 'message' => $message];
        if (!empty($extra)) {
            $response = array_merge($response, $extra);
        }
        echo json_encode($response, JSON_THROW_ON_ERROR);
        exit;
    }
}
