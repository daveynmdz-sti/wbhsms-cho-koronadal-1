<?php
/**
 * Production Deployment Fix Script
 * Run this on production server to fix header/session issues
 */

echo "<h2>Production Billing System Fix Script</h2>";
echo "<p>Checking and fixing common production issues...</p>";

$fixes_applied = [];
$errors_found = [];

// 1. Check if old billing folder exists and remove it
$old_billing_path = '/var/www/html/pages/management/billing';
if (file_exists($old_billing_path)) {
    echo "<p><strong>Found old billing folder at: $old_billing_path</strong></p>";
    if (is_dir($old_billing_path)) {
        // List files in old directory
        $old_files = scandir($old_billing_path);
        echo "<p>Files in old billing folder: " . implode(', ', array_diff($old_files, ['.', '..'])) . "</p>";
        echo "<p><span style='color: red;'>⚠️ MANUAL ACTION REQUIRED:</span> Remove old billing folder: <code>rm -rf $old_billing_path</code></p>";
        $errors_found[] = "Old billing folder exists: $old_billing_path";
    }
} else {
    echo "<p>✅ Old billing folder not found (good)</p>";
}

// 2. Check for BOM in PHP files
$cashier_files = [
    '/var/www/html/pages/management/cashier/billing_management.php',
    '/var/www/html/pages/management/cashier/create_invoice.php',
    '/var/www/html/pages/management/cashier/process_payment.php',
    '/var/www/html/pages/management/cashier/print_receipt.php',
    '/var/www/html/pages/management/cashier/invoice_search.php',
    '/var/www/html/pages/management/cashier/billing_reports.php',
    '/var/www/html/config/session/employee_session.php'
];

foreach ($cashier_files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        
        // Check for BOM
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            echo "<p><span style='color: red;'>❌ BOM found in:</span> $file</p>";
            $errors_found[] = "BOM in file: $file";
        }
        
        // Check if file starts with <?php
        if (substr(ltrim($content), 0, 5) !== '<?php') {
            echo "<p><span style='color: red;'>❌ File doesn't start with &lt;?php:</span> $file</p>";
            $errors_found[] = "Invalid PHP start in: $file";
        }
        
        // Check for output before session
        $lines = explode("\n", $content);
        $php_started = false;
        $found_session = false;
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if (strpos($line, '<?php') !== false) {
                $php_started = true;
                continue;
            }
            if ($php_started && !$found_session) {
                if (strpos($line, 'require_once') !== false && strpos($line, 'employee_session.php') !== false) {
                    $found_session = true;
                    break;
                }
                if (!empty($line) && !str_starts_with($line, '//') && !str_starts_with($line, '/*') && !str_starts_with($line, '*')) {
                    if (strpos($line, 'ob_start') === false && strpos($line, '$root_path') === false) {
                        echo "<p><span style='color: orange;'>⚠️ Potential output before session in $file, line " . ($i+1) . ":</span> " . htmlspecialchars($line) . "</p>";
                    }
                }
            }
        }
        
        echo "<p>✅ Checked: $file</p>";
    } else {
        echo "<p><span style='color: red;'>❌ Missing file:</span> $file</p>";
        $errors_found[] = "Missing file: $file";
    }
}

// 3. Check web server configuration
echo "<h3>Server Information:</h3>";
echo "<p><strong>PHP Version:</strong> " . PHP_VERSION . "</p>";
echo "<p><strong>Server Software:</strong> " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "</p>";
echo "<p><strong>Output Buffering:</strong> " . (ini_get('output_buffering') ? 'Enabled' : 'Disabled') . "</p>";
echo "<p><strong>Session Auto Start:</strong> " . (ini_get('session.auto_start') ? 'Enabled (BAD)' : 'Disabled (Good)') . "</p>";

if (ini_get('session.auto_start')) {
    $errors_found[] = "session.auto_start is enabled - this causes header issues";
}

// 4. Test session creation
echo "<h3>Session Test:</h3>";
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        echo "<p>✅ Session started successfully</p>";
        session_destroy();
    } else {
        echo "<p>✅ Session already active</p>";
    }
} catch (Exception $e) {
    echo "<p><span style='color: red;'>❌ Session error:</span> " . htmlspecialchars($e->getMessage()) . "</p>";
    $errors_found[] = "Session creation failed: " . $e->getMessage();
}

// Summary
echo "<h3>Summary:</h3>";
if (empty($errors_found)) {
    echo "<p style='color: green; font-weight: bold;'>✅ All checks passed! Billing system should work correctly.</p>";
} else {
    echo "<p style='color: red; font-weight: bold;'>❌ Issues found that need to be fixed:</p>";
    echo "<ul>";
    foreach ($errors_found as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul>";
}

echo "<h3>Manual Steps for Production:</h3>";
echo "<ol>";
echo "<li>Remove old billing folder if it exists: <code>rm -rf /var/www/html/pages/management/billing</code></li>";
echo "<li>Check file encoding - all PHP files should be UTF-8 without BOM</li>";
echo "<li>Ensure web server has proper permissions: <code>chown -R www-data:www-data /var/www/html</code></li>";
echo "<li>Restart web server: <code>sudo systemctl restart apache2</code> or <code>sudo systemctl restart nginx</code></li>";
echo "<li>Clear any PHP opcache if enabled</li>";
echo "</ol>";

echo "<p><strong>Last updated:</strong> " . date('Y-m-d H:i:s') . "</p>";
?>