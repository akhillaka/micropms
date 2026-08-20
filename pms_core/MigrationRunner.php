<?php
declare(strict_types=1);

/**
 * MigrationRunner — Tracks and executes database migrations in order.
 * 
 * Uses a `schema_migrations` table to track which migrations have been applied.
 * Migrations are SQL files in the `db_migrations/` directory, named `NNN_description.sql`.
 * 
 * Usage:
 *   $runner = new MigrationRunner($pdo);
 *   $runner->migrate(); // Run all pending migrations
 *   $runner->getStatus(); // Get migration status
 */
class MigrationRunner {
    private \PDO $db;
    private string $migrationDir;

    public function __construct(\PDO $db) {
        $this->db = $db;
        $this->migrationDir = realpath(__DIR__ . '/../db_migrations');
        $this->ensureTrackingTable();
    }

    /**
     * Create the schema_migrations tracking table if it doesn't exist.
     */
    private function ensureTrackingTable(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `version` VARCHAR(50) NOT NULL PRIMARY KEY,
                `filename` VARCHAR(255) NOT NULL,
                `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `execution_time_ms` INT UNSIGNED DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Get list of all migration files in the directory.
     * @return array<string, string> Keyed by version => full path
     */
    private function getMigrationFiles(): array {
        $files = [];
        if (!is_dir($this->migrationDir)) {
            return $files;
        }

        $iterator = new \DirectoryIterator($this->migrationDir);
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'sql') {
                $filename = $file->getBasename();
                // Extract version from filename: 001_description.sql => 001
                if (preg_match('/^(\d+)_/', $filename, $matches)) {
                    $version = $matches[1];
                    $files[$version] = $file->getPathname();
                }
            }
        }
        ksort($files);
        return $files;
    }

    /**
     * Get list of already-applied migration versions.
     * @return array<string>
     */
    private function getAppliedVersions(): array {
        try {
            $stmt = $this->db->query("SELECT version FROM schema_migrations ORDER BY version");
            return $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            return [];
        }
    }

    /**
     * Run all pending migrations.
     * @return array ['applied' => [...], 'skipped' => [...], 'errors' => [...]]
     */
    public function migrate(): array {
        $allFiles = $this->getMigrationFiles();
        $applied = $this->getAppliedVersions();
        
        $results = [
            'applied' => [],
            'skipped' => [],
            'errors' => [],
        ];

        foreach ($allFiles as $version => $filepath) {
            if (in_array($version, $applied, true)) {
                $results['skipped'][] = $version;
                continue;
            }

            $filename = basename($filepath);
            $startTime = microtime(true);

            try {
                $sql = file_get_contents($filepath);
                if ($sql === false) {
                    throw new \RuntimeException("Could not read migration file: $filename");
                }

                foreach ($this->splitStatements($sql) as $statement) {
                    $this->db->exec($statement);
                }

                $elapsed = (int)((microtime(true) - $startTime) * 1000);

                // Record the migration
                $stmt = $this->db->prepare("
                    INSERT INTO schema_migrations (version, filename, execution_time_ms) 
                    VALUES (:version, :filename, :time)
                ");
                $stmt->execute([
                    'version' => $version,
                    'filename' => $filename,
                    'time' => $elapsed,
                ]);

                $results['applied'][] = [
                    'version' => $version,
                    'filename' => $filename,
                    'time_ms' => $elapsed,
                ];
            } catch (\Throwable $e) {
                $results['errors'][] = [
                    'version' => $version,
                    'filename' => $filename,
                    'error' => $e->getMessage(),
                ];
                // Stop on first error to prevent cascading failures
                break;
            }
        }

        return $results;
    }

    /**
     * Get migration status for display.
     * @return array ['total' => int, 'applied' => int, 'pending' => int, 'migrations' => [...]]
     */
    public function getStatus(): array {
        $allFiles = $this->getMigrationFiles();
        $applied = $this->getAppliedVersions();
        
        $migrations = [];
        foreach ($allFiles as $version => $filepath) {
            $filename = basename($filepath);
            $isApplied = in_array($version, $applied, true);
            
            $appliedAt = null;
            $executionTime = null;
            if ($isApplied) {
                $stmt = $this->db->prepare("SELECT applied_at, execution_time_ms FROM schema_migrations WHERE version = ?");
                $stmt->execute([$version]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $appliedAt = $row['applied_at'];
                    $executionTime = (int)$row['execution_time_ms'];
                }
            }

            $migrations[] = [
                'version' => $version,
                'filename' => $filename,
                'status' => $isApplied ? 'applied' : 'pending',
                'applied_at' => $appliedAt,
                'execution_time_ms' => $executionTime,
            ];
        }

        return [
            'total' => count($allFiles),
            'applied' => count($applied),
            'pending' => count($allFiles) - count($applied),
            'migrations' => $migrations,
        ];
    }

    /**
     * Split a migration file into executable statements.
     * Line comments (-- and #) are removed first so a semicolon in a comment
     * cannot glue leftover words onto the next ALTER/CREATE.
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array {
        $kept = [];
        foreach (preg_split("/\r\n|\n|\r/", $sql) ?: [] as $line) {
            $trim = ltrim($line);
            if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) {
                continue;
            }
            $kept[] = $line;
        }
        $blob = implode("\n", $kept);
        $parts = preg_split('/;\s*(?=(?:[^\'"]*[\'"][^\'"]*[\'"])*[^\'"]*$)/', $blob) ?: [];
        $out = [];
        foreach ($parts as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $out[] = $statement;
        }
        return $out;
    }
}
