<?php
// refactor_api_safe.php
$apiDir = __DIR__ . '/public_html/api';
$coreApiDir = __DIR__ . '/pms_core/api_endpoints';
$publicDir = __DIR__ . '/public_html';
$routerFile = __DIR__ . '/public_html/router.php';

if (!is_dir($coreApiDir)) mkdir($coreApiDir, 0777, true);

$files = glob($apiDir . '/*.php');
$routes = [];

foreach ($files as $file) {
    $base = basename($file);
    
    // Determine clean route path
    if (str_starts_with($base, 'admin_')) {
        $cleanPath = '/api/admin/' . str_replace(['admin_', '.php'], '', $base);
    } elseif (str_starts_with($base, 'guest_')) {
        $cleanPath = '/api/guest/' . str_replace(['guest_', '.php'], '', $base);
    } elseif (str_starts_with($base, 'wa_') || str_starts_with($base, 'whatsapp_')) {
        $cleanPath = '/api/whatsapp/' . str_replace(['wa_', 'whatsapp_', '.php'], '', $base);
    } else {
        $cleanPath = '/api/system/' . str_replace('.php', '', $base);
    }
    
    $routes[$base] = $cleanPath;
    
    // Move the file
    rename($file, $coreApiDir . '/' . $base);
}

// Write the route map to pms_core for the router to use
file_put_contents(__DIR__ . '/pms_core/api_routes.php', "<?php\nreturn " . var_export($routes, true) . ";\n");

// Rewrite all frontend JS files
$frontendFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($publicDir));
foreach ($frontendFiles as $file) {
    if ($file->isFile() && in_array($file->getExtension(), ['php', 'js'])) {
        // skip router.php and index.php
        if ($file->getFilename() === 'router.php' || $file->getFilename() === 'index.php') continue;
        
        $content = file_get_contents($file->getPathname());
        $changed = false;
        
        foreach ($routes as $oldFile => $newPath) {
            // Regex to find things like fetch('../api/admin_actions.php') or /api/admin_actions.php
            $pattern = '/([\'"`])(?:\.\.\/)*api\/' . preg_quote($oldFile, '/') . '([?\'"`])/';
            
            // Wait, we need to handle relative vs absolute. The new path is absolute: /api/admin/actions
            // If the original had `../api/`, it's safer to just replace it with the new absolute path `'/api/admin/actions'` or keep it relative if needed.
            // Since we parse from webroot, absolute `/api/...` is always correct for fetch().
            $replacement = '$1' . $newPath . '$2';
            
            $newContent = preg_replace($pattern, $replacement, $content, -1, $count);
            if ($count > 0) {
                $content = $newContent;
                $changed = true;
            }
        }
        
        if ($changed) {
            file_put_contents($file->getPathname(), $content);
        }
    }
}

// Remove empty public_html/api dir
@rmdir($apiDir);

echo "Safe refactoring complete. Files moved to pms_core/api_endpoints. Frontend URLs rewritten.\n";
