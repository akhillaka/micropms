<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ApiResponse.php';
require_once __DIR__ . '/AuthHelper.php';
require_once __DIR__ . '/CsrfToken.php';
require_once __DIR__ . '/TenantScope.php';
require_once __DIR__ . '/ErrorTracker.php';
require_once __DIR__ . '/SaaSMiddleware.php';
require_once __DIR__ . '/ApiSuccessException.php';
require_once __DIR__ . '/DeferredSideEffects.php';
require_once __DIR__ . '/services/QueueService.php';

class ApiHandler {
    
    public static function getJsonInput(): array {
        // php://input can only be read once; CsrfToken already cached it during requireValid().
        return CsrfToken::getJsonPayload();
    }

    public static function getInt(array $data, string $key, int $default = 0): int {
        return isset($data[$key]) ? (int)$data[$key] : $default;
    }

    public static function getFloat(array $data, string $key, float $default = 0.0): float {
        return isset($data[$key]) ? (float)$data[$key] : $default;
    }

    public static function getString(array $data, string $key, string $default = ''): string {
        if (!isset($data[$key])) {
            return $default;
        }
        return trim((string)$data[$key]);
    }

    public static function validateParams(array $data, array $rules): array {
        $validated = [];
        foreach ($rules as $key => $type) {
            if (!isset($data[$key])) {
                throw new ApiException("Missing required parameter: $key", 400);
            }
            switch ($type) {
                case 'int':
                    $validated[$key] = (int)$data[$key];
                    break;
                case 'float':
                    $validated[$key] = (float)$data[$key];
                    break;
                case 'string':
                    $validated[$key] = trim((string)$data[$key]);
                    break;
                case 'array':
                    if (!is_array($data[$key])) {
                        throw new ApiException("Parameter $key must be an array", 400);
                    }
                    $validated[$key] = $data[$key];
                    break;
                default:
                    $validated[$key] = $data[$key];
            }
        }
        return $validated;
    }
    /**
     * @param callable $callback  The main API logic to execute
     * @param bool $requireAdmin  Whether to require admin login
     * @param bool $requireCsrf   Whether to validate the CSRF token
     * @param bool $useTransaction Whether to wrap the callback in a DB transaction
     *
     * NOTE — Transaction commit strategy:
     * ApiResponse::success() calls exit(), which would skip a commit() placed after
     * $callback($db). To handle this safely we register a shutdown function that
     * commits any still-open transaction when PHP exits (including via exit/die).
     * On the error path the catch block calls rollBack() before exit, so the
     * shutdown function finds inTransaction()===false and is a no-op.
     */
    public static function run(
        callable $callback,
        bool $requireAdmin  = true,
        bool $requireCsrf   = true,
        bool $useTransaction = false
    ): void {
        header('Content-Type: application/json');
        if (ob_get_level() === 0) {
            ob_start();
        }

        try {
            if ($requireAdmin) {
                AuthHelper::requireLogin();
            }

            if ($requireCsrf) {
                CsrfToken::requireValid();
            }

            // Release session lock for authenticated APIs so background polls
            // do not block check-in, payments, and other staff actions.
            if ($requireAdmin) {
                AuthHelper::releaseSession();
            }

            $db = Database::getInstance()->getConnection();
            SaaSMiddleware::resolveAndGuardTenant($db);

            if ($useTransaction) {
                $db->beginTransaction();
            }

            // Execute the endpoint logic. May call ApiResponse::success() -> throws ApiSuccessException
            $callback($db);

            // Fallback commit for endpoints that return normally
            if ($useTransaction && $db->inTransaction()) {
                $db->commit();
            }
            self::afterSuccessfulRequest();

        } catch (ApiSuccessException $e) {
            if ($useTransaction && isset($db) && $db->inTransaction()) {
                $db->commit();
            }
            self::afterSuccessfulRequest();
            
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code($e->getStatusCode());
            $response = ['success' => true];
            if (!empty($e->getData())) {
                $response = array_merge($response, $e->getData());
            }
            echo json_encode($response, JSON_THROW_ON_ERROR);
            exit;
        } catch (\Throwable $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            DeferredSideEffects::discard();

            try {
                ErrorTracker::fromException($e);
            } catch (\Throwable) {
            }

            $message = $e instanceof \PDOException
                ? 'A database error occurred. Please try again later.'
                : $e->getMessage();
            $excCode = $e->getCode();
            $code  = (is_numeric($excCode) && (int)$excCode >= 400 && (int)$excCode < 600)
                ? (int)$excCode
                : 500;
            $extra = $e instanceof ApiException ? $e->getExtra() : [];

            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            ApiResponse::sendErrorResponse($message, $code, $extra);
        }
    }

    private static function afterSuccessfulRequest(): void {
        DeferredSideEffects::flushAndDrain(4, 800);
    }

}
