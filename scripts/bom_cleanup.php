<?php
/**
 * BOM Detection and Removal Script
 * Scans PHP files for BOM (Byte Order Mark) and removes it to prevent header issues
 */

$root_path = dirname(__DIR__);

function scanForBOM($directory) {
    $bomFiles = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $content = file_get_contents($file->getPathname());
            
            // Check for UTF-8 BOM (EF BB BF)
            if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
                $bomFiles[] = $file->getPathname();
            }
        }
    }
    
    return $bomFiles;
}

function removeBOM($filePath) {
    $content = file_get_contents($filePath);
    
    // Remove UTF-8 BOM if present
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
        file_put_contents($filePath, $content);
        return true;
    }
    
    return false;
}

// Scan the entire project for BOM
echo "Scanning for BOM in PHP files...\n\n";

$bomFiles = scanForBOM($root_path);

if (empty($bomFiles)) {
    echo "✅ No BOM found in PHP files.\n";
} else {
    echo "❌ Found BOM in " . count($bomFiles) . " file(s):\n\n";
    
    foreach ($bomFiles as $file) {
        $relativePath = str_replace($root_path . DIRECTORY_SEPARATOR, '', $file);
        echo "  - {$relativePath}\n";
        
        if (removeBOM($file)) {
            echo "    ✅ BOM removed successfully\n";
        } else {
            echo "    ❌ Failed to remove BOM\n";
        }
    }
}

echo "\n";

// Check specific cashier files that were mentioned in the error
$cashierFiles = [
    'pages/management/cashier/create_invoice.php',
    'pages/management/cashier/invoice_search.php',
    'pages/management/cashier/print_receipt.php',
    'pages/management/cashier/process_payment.php'
];

echo "Checking specific cashier files:\n\n";

foreach ($cashierFiles as $file) {
    $fullPath = $root_path . DIRECTORY_SEPARATOR . $file;
    
    if (file_exists($fullPath)) {
        $content = file_get_contents($fullPath);
        
        // Check for BOM
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            echo "❌ {$file} - BOM found\n";
            if (removeBOM($fullPath)) {
                echo "    ✅ BOM removed\n";
            }
        } else {
            echo "✅ {$file} - Clean (no BOM)\n";
        }
        
        // Check for whitespace before <?php
        if (!preg_match('/^\s*<\?php/', $content)) {
            echo "⚠️  {$file} - Possible whitespace before <?php\n";
        }
        
        // Check output buffering setup
        if (strpos($content, 'ob_start()') === false) {
            echo "⚠️  {$file} - No output buffering detected\n";
        } else {
            echo "✅ {$file} - Output buffering present\n";
        }
    } else {
        echo "❌ {$file} - File not found\n";
    }
    
    echo "\n";
}

echo "BOM scan and cleanup completed.\n";
?>