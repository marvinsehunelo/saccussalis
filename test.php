<?php
// Define the base directory you want to scan.
// This path is relative to the root of your project: /opt/lampp/htdocs/prestagedSWAP
$baseDir = __DIR__ . '/../'; 
// If you place this script inside prestagedSWAP/BUSINESS_LOGIC_LAYER/services/
// then '../' moves up one level to BUSINESS_LOGIC_LAYER/, and '../../' moves up to prestagedSWAP/.

// Since the user is likely running the test file from inside /services/, 
// we will assume the full path they want to scan is:
$targetPath = '/opt/lampp/htdocs/SaccusSalisbank/';

echo "<h1>Listing contents of: {$targetPath}</h1>";
echo "<pre>";

try {
    // 1. Create a Recursive Directory Iterator for the target path
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($targetPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $fileCount = 0;

    // 2. Loop through every file and folder
    foreach ($iterator as $item) {
        $fileCount++;
        
        // Get the depth for indentation
        $indent = str_repeat(" |  ", $iterator->getDepth());

        // Get the item's relative path
        $relativePath = str_replace($targetPath, '', $item->getPathname());

        if ($item->isDir()) {
            echo "{$indent}<strong>[DIR]</strong> " . basename($relativePath) . "/\n";
        } else {
            // Display file size in a human-readable format
            $size = number_format($item->getSize() / 1024, 2) . ' KB';
            echo "{$indent}  - {$relativePath} ({$size})\n";
        }
    }

    echo "\nTotal items found: {$fileCount}\n";

} catch (Exception $e) {
    echo "ERROR: Could not read directory. Check permissions.\n";
    echo "Message: " . $e->getMessage() . "\n";
}

echo "</pre>";
?>
