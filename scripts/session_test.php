<?php
/**
 * Session Test Script
 * Tests the cashier files to ensure no session warnings are generated
 */

echo "Testing Session Configuration for Cashier Files\n";
echo "===============================================\n\n";

$root_path = dirname(__DIR__);

// Test files
$testFiles = [
    'pages/management/cashier/create_invoice.php' => 'Create Invoice',
    'pages/management/cashier/invoice_search.php' => 'Invoice Search', 
    'pages/management/cashier/print_receipt.php' => 'Print Receipt',
    'pages/management/cashier/process_payment.php' => 'Process Payment'
];

foreach ($testFiles as $file => $name) {
    echo "Testing: {$name}\n";
    echo "File: {$file}\n";
    
    $fullPath = $root_path . DIRECTORY_SEPARATOR . $file;
    
    if (!file_exists($fullPath)) {
        echo "❌ File not found\n\n";
        continue;
    }
    
    // Check file structure
    $content = file_get_contents($fullPath);
    
    // Check for BOM
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        echo "❌ BOM detected\n";
    } else {
        echo "✅ No BOM\n";
    }
    
    // Check for output buffering
    if (strpos($content, 'ob_start()') !== false) {
        echo "✅ Output buffering present\n";
    } else {
        echo "❌ No output buffering\n";
    }
    
    // Check for clean PHP opening
    if (preg_match('/^<\?php\s*\n/', $content)) {
        echo "✅ Clean PHP opening\n";
    } else {
        echo "⚠️  Non-standard PHP opening\n";
    }
    
    // Check for session include
    if (strpos($content, 'employee_session.php') !== false) {
        echo "✅ Session file included\n";
    } else {
        echo "❌ Session file not included\n";
    }
    
    echo "\n";
}

// Test the session file itself
echo "Testing: Employee Session Configuration\n";
echo "File: config/session/employee_session.php\n";

$sessionFile = $root_path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'session' . DIRECTORY_SEPARATOR . 'employee_session.php';

if (file_exists($sessionFile)) {
    $content = file_get_contents($sessionFile);
    
    // Check for BOM
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        echo "❌ BOM detected in session file\n";
    } else {
        echo "✅ No BOM in session file\n";
    }
    
    // Check for headers_sent check
    if (strpos($content, 'headers_sent()') !== false) {
        echo "✅ Headers check present\n";
    } else {
        echo "❌ No headers check\n";
    }
    
    // Check for output buffering
    if (strpos($content, 'ob_start()') !== false) {
        echo "✅ Output buffering in session file\n";
    } else {
        echo "❌ No output buffering in session file\n";
    }
} else {
    echo "❌ Session file not found\n";
}

// Test auth files for proper buffer management
echo "Testing: Auth File Buffer Management\n";
echo "===================================\n";

$authFiles = [
    'pages/management/auth/employee_login.php' => 'Employee Login',
    'pages/management/auth/employee_logout.php' => 'Employee Logout'
];

foreach ($authFiles as $file => $name) {
    echo "Testing: {$name}\n";
    echo "File: {$file}\n";
    
    $fullPath = $root_path . DIRECTORY_SEPARATOR . $file;
    
    if (!file_exists($fullPath)) {
        echo "❌ File not found\n\n";
        continue;
    }
    
    $content = file_get_contents($fullPath);
    
    // Check for safe buffer cleanup
    if (strpos($content, 'while (ob_get_level())') !== false) {
        echo "✅ Safe buffer cleanup present\n";
    } else if (strpos($content, 'ob_end_clean()') !== false) {
        echo "⚠️  Unsafe buffer cleanup detected\n";
    } else {
        echo "ℹ️  No buffer cleanup found\n";
    }
    
    // Check for proper output buffering
    if (strpos($content, 'ob_get_level() === 0') !== false) {
        echo "✅ Smart output buffering\n";
    } else if (strpos($content, 'ob_start()') !== false) {
        echo "⚠️  Basic output buffering\n";
    } else {
        echo "❌ No output buffering\n";
    }
    
    echo "\n";
}

echo "\n";
echo "Session test completed.\n";
echo "\nRecommendations:\n";
echo "- All files should have clean PHP openings without BOM\n";
echo "- Use smart output buffering: if (ob_get_level() === 0) ob_start()\n";  
echo "- Session configuration should check for headers_sent()\n";
echo "- Use safe buffer cleanup: while (ob_get_level()) ob_end_clean()\n";
echo "- Never assume buffer levels when doing redirects\n";

?>