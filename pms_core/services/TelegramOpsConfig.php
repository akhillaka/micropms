<?php
declare(strict_types=1);

require_once __DIR__ . '/../ModuleHost.php';

class TelegramOpsConfig
{
    public static function setting(\PDO $db, string $key, int $propertyId = 0): string
    {
        $placeholder = ['your_telegram_webhook_secret', 'your_bot_token'];
        $env = trim((string)(getenv($key) ?: ($_ENV[$key] ?? '')));
        if ($env !== '' && !in_array($env, $placeholder, true)) {
            return $env;
        }
        if (defined($key)) {
            $constant = trim((string)constant($key));
            if ($constant !== '' && !in_array($constant, $placeholder, true)) {
                return $constant;
            }
        }
        if ($propertyId > 0) {
            $stmt = $db->prepare('SELECT key_value FROM system_settings WHERE key_name = ? AND property_id = ? LIMIT 1');
            $stmt->execute([$key, $propertyId]);
            $val = trim((string)($stmt->fetchColumn() ?: ''));
            if ($val !== '' && !in_array($val, $placeholder, true)) {
                return $val;
            }
        }
        $stmt = $db->prepare("SELECT key_value FROM system_settings WHERE key_name = ? AND TRIM(key_value) != '' ORDER BY property_id ASC");
        $stmt->execute([$key]);
        $val = trim((string)($stmt->fetchColumn() ?: ''));
        if (in_array($val, $placeholder, true)) {
            return '';
        }
        return $val;
    }

    /**
     * @return array{token:string,chat_ids:list<string>,property_id:int,source:string}
     */
    public static function resolveBot(\PDO $db, int $propertyId = 0): array
    {
        $byProperty = [];
        $stmt = $db->query("SELECT property_id, key_name, key_value FROM system_settings WHERE key_name IN (
            'TELEGRAM_OPERATIONS_BOT_TOKEN', 'TELEGRAM_OPERATIONS_CHAT_IDS', 'TELEGRAM_BOT_TOKEN', 'TELEGRAM_CHAT_ID'
        )");
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $pid = (int)$row['property_id'];
            $byProperty[$pid][$row['key_name']] = (string)$row['key_value'];
        }

        $order = array_keys($byProperty);
        if ($propertyId > 0) {
            array_unshift($order, $propertyId);
            $order = array_values(array_unique($order));
        }

        foreach ($order as $pid) {
            $row = $byProperty[$pid] ?? [];
            $opsToken = trim((string)($row['TELEGRAM_OPERATIONS_BOT_TOKEN'] ?? ''));
            $opsIds = self::splitIds((string)($row['TELEGRAM_OPERATIONS_CHAT_IDS'] ?? ''));
            $notifyToken = trim((string)($row['TELEGRAM_BOT_TOKEN'] ?? ''));
            $notifyIds = self::splitIds((string)($row['TELEGRAM_CHAT_ID'] ?? ''));
            if ($opsToken === '') {
                $opsToken = self::setting($db, 'TELEGRAM_OPERATIONS_BOT_TOKEN', $pid);
            }
            if ($notifyToken === '') {
                $notifyToken = self::setting($db, 'TELEGRAM_BOT_TOKEN', $pid);
            }
            if ($opsIds === []) {
                $opsIds = self::splitIds(self::setting($db, 'TELEGRAM_OPERATIONS_CHAT_IDS', $pid));
            }
            if ($notifyIds === []) {
                $notifyIds = self::splitIds(self::setting($db, 'TELEGRAM_CHAT_ID', $pid));
            }
            if ($opsToken !== '') {
                $ids = $opsIds !== [] ? $opsIds : $notifyIds;
                if ($ids !== []) {
                    return ['token' => $opsToken, 'chat_ids' => $ids, 'property_id' => $pid, 'source' => 'operations'];
                }
            }
            if ($notifyToken !== '') {
                $ids = $notifyIds !== [] ? $notifyIds : $opsIds;
                if ($ids !== []) {
                    return ['token' => $notifyToken, 'chat_ids' => $ids, 'property_id' => $pid, 'source' => 'notifier'];
                }
            }
        }

        $opsToken = self::setting($db, 'TELEGRAM_OPERATIONS_BOT_TOKEN', $propertyId);
        $opsIds = self::splitIds(self::setting($db, 'TELEGRAM_OPERATIONS_CHAT_IDS', $propertyId));
        $notifyToken = self::setting($db, 'TELEGRAM_BOT_TOKEN', $propertyId);
        $notifyIds = self::splitIds(self::setting($db, 'TELEGRAM_CHAT_ID', $propertyId));
        if ($opsToken !== '' && ($opsIds !== [] || $notifyIds !== [])) {
            return ['token' => $opsToken, 'chat_ids' => $opsIds !== [] ? $opsIds : $notifyIds, 'property_id' => $propertyId, 'source' => 'operations'];
        }
        if ($notifyToken !== '' && ($notifyIds !== [] || $opsIds !== [])) {
            return ['token' => $notifyToken, 'chat_ids' => $notifyIds !== [] ? $notifyIds : $opsIds, 'property_id' => $propertyId, 'source' => 'notifier'];
        }

        return ['token' => '', 'chat_ids' => [], 'property_id' => $propertyId, 'source' => 'none'];
    }

    public static function webhookSecret(\PDO $db, int $propertyId = 0): string
    {
        return self::setting($db, 'TELEGRAM_WEBHOOK_SECRET', $propertyId);
    }

    public static function webhookUrlFromRequest(): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $fwd = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $https = $fwd === 'https'
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
        $scheme = $https ? 'https' : 'http';
        return $scheme . '://' . $host . '/api/telegram_webhook';
    }

    public static function isPublicHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'https' || $host === '') {
            return false;
        }
        return !ModuleHost::isLoopbackHost($host);
    }

    /** @return list<string> */
    public static function splitIds(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $id = trim($part);
            if ($id !== '') {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }
}
