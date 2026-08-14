<?php
declare(strict_types=1);

/**
 * Blocks HTTP access to one-off maintenance / dump scripts.
 * Run them only from the CLI on the server.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'This script is disabled over HTTP. Run it from the command line.';
    exit;
}
