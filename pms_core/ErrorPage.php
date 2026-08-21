<?php
declare(strict_types=1);

/**
 * Render a styled error page with back button.
 * Used instead of die() for proper UI consistency.
 *
 * @param string $title   Short error title (e.g. "Booking Not Found")
 * @param string $message Detailed error message
 * @param int    $code    HTTP status code (400, 404, 500)
 */
function render_error_page(string $title, string $message, int $code = 400): void {
    http_response_code($code);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title><?= htmlspecialchars($title) ?> | MicroPMS</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
        <link href="/css/mobile-input-zoom.css" rel="stylesheet">
        <script src="https://unpkg.com/@phosphor-icons/web"></script>
        <style>
            body { font-family: 'Inter', sans-serif; }
            h1, .font-display { font-family: 'Plus Jakarta Sans', sans-serif; }
        </style>
    </head>
    <body class="bg-slate-50 flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-2xl border border-slate-100 shadow-sm p-8 max-w-md w-full text-center">
            <div class="w-16 h-16 rounded-2xl bg-red-50 flex items-center justify-center mx-auto mb-4">
                <i class="ph ph-warning-circle text-3xl text-red-500"></i>
            </div>
            <h1 class="text-xl font-bold text-slate-900 mb-2 font-display"><?= htmlspecialchars($title) ?></h1>
            <p class="text-sm text-slate-500 mb-6"><?= htmlspecialchars($message) ?></p>
            <a href="index.php" class="inline-flex items-center gap-2 bg-slate-900 text-white px-6 py-3 rounded-xl text-sm font-bold hover:bg-slate-800 transition-colors">
                <i class="ph ph-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}
