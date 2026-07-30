<?php
$dir = new DirectoryIterator("public_html/api");
foreach ($dir as $fileinfo) {
    if (!$fileinfo->isDot() && $fileinfo->getExtension() == "php") {
        $path = $fileinfo->getPathname();
        $content = file_get_contents($path);
        
        $search = '$e->getMessage()';
        $replace = '($e instanceof PDOException ? "A database error occurred. Please try again later." : $e->getMessage())';
        
        $newContent = str_replace($search, $replace, $content);
        if ($newContent !== $content) {
            file_put_contents($path, $newContent);
            echo "Updated " . $path . "\n";
        }
    }
}
