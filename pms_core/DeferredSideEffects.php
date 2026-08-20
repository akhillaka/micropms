<?php
declare(strict_types=1);

/**
 * Runs side effects only after a successful DB commit.
 * Prevents Telegram / Web Push / other HTTP from holding open transactions
 * and freezing the rest of the app under InnoDB row locks.
 */
class DeferredSideEffects {
    /** @var list<callable> */
    private static array $queue = [];

    public static function afterCommit(callable $fn): void {
        try {
            $db = Database::getInstance()->getConnection();
            if ($db->inTransaction()) {
                self::$queue[] = $fn;
                return;
            }
        } catch (\Throwable $e) {
            // Fall through and run immediately if DB is unavailable for the check.
        }
        self::runOne($fn);
    }

    public static function discard(): void {
        self::$queue = [];
    }

    public static function flush(): void {
        if (self::$queue === []) {
            return;
        }
        $fns = self::$queue;
        self::$queue = [];
        foreach ($fns as $fn) {
            self::runOne($fn);
        }
    }

    /** Flush deferred work, then briefly drain telegram/web_push jobs. */
    public static function flushAndDrain(int $maxJobs = 4, int $budgetMs = 800): void {
        self::flush();
        try {
            require_once __DIR__ . '/services/QueueService.php';
            QueueService::drainNotifyQueues($maxJobs, $budgetMs);
        } catch (\Throwable $e) {
            error_log('DeferredSideEffects drain skipped: ' . $e->getMessage());
        }
    }

    private static function runOne(callable $fn): void {
        try {
            $fn();
        } catch (\Throwable $e) {
            error_log('DeferredSideEffects failed: ' . $e->getMessage());
        }
    }
}
