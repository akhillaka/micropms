<?php
declare(strict_types=1);

class Database {
    private static ?self $instance = null;
    private \PDO $conn;

    private function __construct() {
        require_once __DIR__ . '/config.php';
        
        $host = DB_HOST;
        $db = DB_NAME;
        $user = DB_USER;
        $pass = DB_PASS;
        
        // FIX: socket path was hardcoded to macOS XAMPP path — now configurable via DB_SOCKET env var
        $socket = defined('DB_SOCKET') ? DB_SOCKET : (getenv('DB_SOCKET') ?: '');
        if ($socket && file_exists($socket)) {
            $dsn = "mysql:unix_socket={$socket};dbname={$db};charset=utf8mb4";
        } else {
            $port = defined('DB_PORT') ? (int)DB_PORT : 3306;
            $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        }
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_PERSISTENT         => true,
        ];
        
        try {
            $this->conn = new \PDO($dsn, $user, $pass, $options);
            // Fix #12: derive timezone offset dynamically from PHP config, not hard-coded
            $tzOffset = (new \DateTime('now', new \DateTimeZone(date_default_timezone_get())))->format('P');
            $this->conn->exec("SET time_zone = '{$tzOffset}'");
            
            // Load settings dynamically using the connection
            if (function_exists('load_db_settings')) {
                load_db_settings($this->conn);
            }
        } catch (\PDOException $e) {
            throw new \PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): \PDO {
        return $this->conn;
    }

    /**
     * Executes a query and yields the results one by one (Memory Efficient)
     * Useful for large datasets or exports.
     * 
     * @param string $sql
     * @param array $params
     * @return \Generator
     */
    public function yieldQuery(string $sql, array $params = []): \Generator {
        // Unbuffered query for maximum memory efficiency
        if (defined('\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')) {
            $this->conn->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, false);
        } else {
            @$this->conn->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }
        
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                yield $row;
            }
        } finally {
            // Restore buffered query mode for normal operations
            if (defined('\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY')) {
                $this->conn->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, true);
            } else {
                @$this->conn->setAttribute(\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
    }
}

/**
 * TenantQuery Helper
 * Provides utility methods to enforce property_id isolation in multi-tenant environments.
 */
class TenantQuery {
    /**
     * Appends a property_id filter to a WHERE clause and parameter array.
     * 
     * @param string $whereClause Existing WHERE clause (e.g., "status = 'active'")
     * @param array $params Existing parameters array
     * @param string $tableAlias Optional table alias (e.g., "b")
     * @return array [0 => newWhereClause, 1 => newParams]
     */
    public static function scope(string $whereClause, array $params = [], string $tableAlias = ''): array {
        require_once __DIR__ . '/AuthHelper.php';
        $propertyId = AuthHelper::getPropertyId();
        
        $prefix = $tableAlias ? "{$tableAlias}." : "";
        $clause = trim($whereClause);
        
        if (empty($clause)) {
            $newWhere = "{$prefix}property_id = ?";
        } else {
            $newWhere = "({$clause}) AND {$prefix}property_id = ?";
        }
        
        $params[] = $propertyId;
        
        return [$newWhere, $params];
    }
}
