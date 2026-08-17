<?php
declare(strict_types=1);

require_once __DIR__ . '/ApiException.php';

require_once __DIR__ . '/ApiSuccessException.php';

class ApiResponse {
    public static function success(array $data = [], int $statusCode = 200): void {
        throw new ApiSuccessException($data, $statusCode);
    }

    public static function error(string $message, int $statusCode = 400, array $extra = []): void {
        $code = (string)($extra['code'] ?? 'UNKNOWN');
        $retryable = (bool)($extra['retryable'] ?? ($statusCode >= 500 || $statusCode === 429));
        $fieldErrors = is_array($extra['field_errors'] ?? null) ? $extra['field_errors'] : [];
        unset($extra['code'], $extra['retryable'], $extra['field_errors']);
        $extra['error'] = [
            'code' => $code,
            'message' => $message,
            'retryable' => $retryable,
            'field_errors' => $fieldErrors,
        ];
        throw new ApiException($message, $statusCode, $extra);
    }

    public static function sendErrorResponse(string $message, int $statusCode = 400, array $extra = []): void {
        http_response_code($statusCode);
        $response = ['success' => false, 'message' => $message];
        if (!isset($extra['error']) || !is_array($extra['error'])) {
            $extra['error'] = [
                'code' => $extra['code'] ?? 'UNKNOWN',
                'message' => $message,
                'retryable' => $extra['retryable'] ?? ($statusCode >= 500 || $statusCode === 429),
                'field_errors' => $extra['field_errors'] ?? [],
            ];
        }
        if (!empty($extra)) {
            $response = array_merge($response, $extra);
        }
        echo json_encode($response, JSON_THROW_ON_ERROR);
        exit;
    }
}
