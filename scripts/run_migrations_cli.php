<?php
/**
 * CLI Migration Runner
 * Usage: php run_migrations_cli.php
 * 
 * Runs all pending database migrations in order.
 * Safe to run multiple times (idempotent).
 */

require_once __DIR__ . '/pms_core/Database.php';
require_once __DIR__ . '/pms_core/config.php';
require_once __DIR__ . '/pms_core/MigrationRunner.php';

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

echo "MicroPMS Migration Runner\n";
echo "========================\n\n";

try {
    $db = Database::getInstance()->getConnection();
    echo "Connected to database.\n\n";
    
    $runner = new MigrationRunner($db);
    
    // Show current status
    $status = $runner->getStatus();
    echo "Migrations found: {$status['total']}\n";
    echo "Already applied: {$status['applied']}\n";
    echo "Pending: {$status['pending']}\n\n";
    
    if ($status['pending'] === 0) {
        echo "All migrations are already applied. Nothing to do.\n";
        exit(0);
    }
    
    echo "Running pending migrations...\n";
    echo str_repeat('-', 50) . "\n";
    
    $results = $runner->migrate();
    
    // Report results
    if (!empty($results['applied'])) {
        echo "\nApplied:\n";
        foreach ($results['applied'] as $m) {
            echo "  [OK] {$m['filename']} ({$m['time_ms']}ms)\n";
        }
    }
    
    if (!empty($results['skipped'])) {
        echo "\nSkipped (already applied):\n";
        foreach ($results['skipped'] as $v) {
            echo "  [--] Migration {$v}\n";
        }
    }
    
    if (!empty($results['errors'])) {
        echo "\nErrors:\n";
        foreach ($results['errors'] as $e) {
            echo "  [ERR] {$e['filename']}: {$e['error']}\n";
        }
        exit(1);
    }
    
    echo "\n" . str_repeat('-', 50) . "\n";
    echo "Migration complete!\n";
    
} catch (\Throwable $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
