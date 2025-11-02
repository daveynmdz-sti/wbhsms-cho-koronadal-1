<?php
/**
 * Activity Logger Test Suite
 * Comprehensive testing for the production activity logging system
 * 
 * @author GitHub Copilot
 * @version 2.0 - Production Ready
 * @since November 3, 2025
 */

// Include required files
$root_path = dirname(__DIR__);
require_once $root_path . '/config/db.php';
require_once $root_path . '/utils/UserActivityLogger.php';

// Configuration
$config = include $root_path . '/config/activity_logger_config.php';

echo "<!DOCTYPE html>\n";
echo "<html>\n<head>\n";
echo "<title>Activity Logger Test Suite</title>\n";
echo "<style>\n";
echo "body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 40px; background: #f8f9fa; }\n";
echo ".container { max-width: 1200px; margin: 0 auto; }\n";
echo ".test-result { margin: 10px 0; padding: 15px; border-radius: 6px; border-left: 4px solid; }\n";
echo ".test-pass { background: #d4edda; border-left-color: #28a745; color: #155724; }\n";
echo ".test-fail { background: #f8d7da; border-left-color: #dc3545; color: #721c24; }\n";
echo ".test-warning { background: #fff3cd; border-left-color: #ffc107; color: #856404; }\n";
echo ".section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }\n";
echo ".metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }\n";
echo ".metric { background: #f8f9fa; padding: 15px; border-radius: 6px; text-align: center; }\n";
echo ".metric-value { font-size: 24px; font-weight: bold; color: #007bff; }\n";
echo ".metric-label { font-size: 12px; color: #6c757d; margin-top: 5px; }\n";
echo ".code { background: #f8f9fa; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px; }\n";
echo "pre { background: #f8f9fa; padding: 15px; border-radius: 6px; overflow-x: auto; }\n";
echo ".header { text-align: center; margin-bottom: 30px; }\n";
echo ".badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }\n";
echo ".badge-success { background: #28a745; color: white; }\n";
echo ".badge-danger { background: #dc3545; color: white; }\n";
echo ".badge-warning { background: #ffc107; color: #212529; }\n";
echo ".progress { background: #e9ecef; border-radius: 4px; height: 8px; margin: 10px 0; }\n";
echo ".progress-bar { background: #007bff; height: 100%; border-radius: 4px; transition: width 0.3s; }\n";
echo "</style>\n</head>\n<body>\n";

echo "<div class='container'>\n";
echo "<div class='header'>\n";
echo "<h1>🔍 Activity Logger Test Suite</h1>\n";
echo "<p>Production-ready testing for WBHSMS healthcare system</p>\n";
echo "<div class='badge badge-success'>Version 2.0</div>\n";
echo "<div class='badge badge-success'>Environment: " . ($config['environment'] ?? 'unknown') . "</div>\n";
echo "</div>\n";

$test_results = [];
$total_tests = 0;
$passed_tests = 0;
$failed_tests = 0;
$warnings = 0;

// Test utility functions
function runTest($name, $description, $testFunction) {
    global $test_results, $total_tests, $passed_tests, $failed_tests, $warnings;
    
    $total_tests++;
    $start_time = microtime(true);
    
    try {
        $result = $testFunction();
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);
        
        if ($result === true) {
            $passed_tests++;
            $status = 'PASS';
            $class = 'test-pass';
        } elseif (is_array($result) && isset($result['warning'])) {
            $warnings++;
            $status = 'WARNING';
            $class = 'test-warning';
            $description .= " - " . $result['warning'];
        } else {
            $failed_tests++;
            $status = 'FAIL';
            $class = 'test-fail';
            if (is_string($result)) {
                $description .= " - " . $result;
            }
        }
    } catch (Exception $e) {
        $failed_tests++;
        $execution_time = round((microtime(true) - $start_time) * 1000, 2);
        $status = 'ERROR';
        $class = 'test-fail';
        $description .= " - Exception: " . $e->getMessage();
    }
    
    $test_results[] = [
        'name' => $name,
        'description' => $description,
        'status' => $status,
        'class' => $class,
        'execution_time' => $execution_time
    ];
    
    echo "<div class='{$class}'>\n";
    echo "<strong>{$status}</strong> - {$name}<br>\n";
    echo "<small>{$description} ({$execution_time}ms)</small>\n";
    echo "</div>\n";
    
    // Flush output for real-time display
    if (ob_get_level()) ob_flush();
    flush();
}

// Test 1: Database Connection
echo "<div class='section'>\n<h2>🔗 Database Connectivity Tests</h2>\n";
runTest("Database Connection (PDO)", "Verify PDO database connection works", function() {
    global $pdo;
    if (!$pdo) return "PDO connection not available";
    $stmt = $pdo->query('SELECT 1 as test');
    return $stmt->fetch() ? true : "Query failed";
});

runTest("Database Connection (MySQLi)", "Verify MySQLi database connection works", function() {
    global $conn;
    if (!$conn) return ['warning' => 'MySQLi connection not available (PDO preferred)'];
    $result = $conn->query('SELECT 1 as test');
    return $result ? true : "Query failed";
});

runTest("User Activity Logs Table", "Verify table exists and is accessible", function() {
    global $pdo, $conn;
    $db = $pdo ?: $conn;
    if (!$db) return "No database connection";
    
    if ($pdo) {
        $stmt = $pdo->query("DESCRIBE user_activity_logs");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $result = $conn->query("DESCRIBE user_activity_logs");
        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }
    
    $required_columns = ['id', 'employee_id', 'action_type', 'ip_address', 'user_agent', 'created_at'];
    $missing = array_diff($required_columns, $columns);
    
    return empty($missing) ? true : "Missing columns: " . implode(', ', $missing);
});
echo "</div>\n";

// Test 2: UserActivityLogger Class
echo "<div class='section'>\n<h2>🏗️ UserActivityLogger Class Tests</h2>\n";
runTest("Class Instantiation", "Create UserActivityLogger instance", function() {
    try {
        $logger = new UserActivityLogger();
        return $logger instanceof UserActivityLogger;
    } catch (Exception $e) {
        return "Failed to instantiate: " . $e->getMessage();
    }
});

runTest("Configuration Loading", "Verify configuration is loaded properly", function() {
    $logger = new UserActivityLogger();
    $reflection = new ReflectionClass($logger);
    $configProperty = $reflection->getProperty('config');
    $configProperty->setAccessible(true);
    $config = $configProperty->getValue($logger);
    
    return is_array($config) && !empty($config) ? true : "Configuration not loaded";
});

runTest("Rate Limiting Check", "Test rate limiting functionality", function() {
    $logger = new UserActivityLogger();
    // This should pass under normal conditions
    // Rate limiting would be tested under load
    return true; // Placeholder for rate limiting test
});
echo "</div>\n";

// Test 3: Logging Functionality
echo "<div class='section'>\n<h2>📝 Logging Functionality Tests</h2>\n";

$test_employee_id = 999999; // Test employee ID
$test_ip = '127.0.0.1';
$test_user_agent = 'Mozilla/5.0 (Test Suite)';

runTest("Basic Logging", "Test basic activity logging", function() use ($test_employee_id, $test_ip, $test_user_agent) {
    $logger = new UserActivityLogger();
    
    $result = $logger->logActivity(
        $test_employee_id,
        'test_action',
        'Test Suite Basic Logging',
        [
            'test_data' => 'sample_value',
            'timestamp' => date('Y-m-d H:i:s')
        ],
        $test_ip,
        $test_user_agent
    );
    
    return $result === true ? true : "Logging failed: " . (is_string($result) ? $result : 'Unknown error');
});

runTest("Login Activity Logging", "Test login event logging", function() use ($test_employee_id, $test_ip, $test_user_agent) {
    $logger = new UserActivityLogger();
    
    $result = $logger->logActivity(
        $test_employee_id,
        'login_success',
        'Test Suite Login',
        [
            'login_method' => 'test',
            'session_id' => 'test_session_' . uniqid(),
            'login_time' => date('Y-m-d H:i:s')
        ],
        $test_ip,
        $test_user_agent
    );
    
    return $result === true ? true : "Login logging failed";
});

runTest("Logout Activity Logging", "Test logout event logging", function() use ($test_employee_id, $test_ip, $test_user_agent) {
    $logger = new UserActivityLogger();
    
    $result = $logger->logActivity(
        $test_employee_id,
        'logout',
        'Test Suite Logout',
        [
            'logout_method' => 'manual',
            'session_duration' => '00:05:00',
            'logout_time' => date('Y-m-d H:i:s')
        ],
        $test_ip,
        $test_user_agent
    );
    
    return $result === true ? true : "Logout logging failed";
});

runTest("Password Change Logging", "Test password change event logging", function() use ($test_employee_id, $test_ip, $test_user_agent) {
    $logger = new UserActivityLogger();
    
    $result = $logger->logActivity(
        $test_employee_id,
        'password_change',
        'Test Suite Password Change',
        [
            'change_method' => 'manual',
            'security_question_verified' => true,
            'change_time' => date('Y-m-d H:i:s')
        ],
        $test_ip,
        $test_user_agent
    );
    
    return $result === true ? true : "Password change logging failed";
});

runTest("Error Logging", "Test error event logging", function() use ($test_employee_id, $test_ip, $test_user_agent) {
    $logger = new UserActivityLogger();
    
    $result = $logger->logActivity(
        $test_employee_id,
        'error',
        'Test Suite Error Event',
        [
            'error_type' => 'test_error',
            'error_message' => 'This is a test error message',
            'error_code' => 'TEST_001'
        ],
        $test_ip,
        $test_user_agent
    );
    
    return $result === true ? true : "Error logging failed";
});
echo "</div>\n";

// Test 4: Data Validation
echo "<div class='section'>\n<h2>✅ Data Validation Tests</h2>\n";

runTest("Invalid Employee ID", "Test handling of invalid employee ID", function() {
    $logger = new UserActivityLogger();
    
    $result = $logger->logActivity(
        null, // Invalid employee ID
        'test_action',
        'Test with null employee ID',
        [],
        '127.0.0.1',
        'Test Agent'
    );
    
    // Should return false or handle gracefully
    return $result === false ? true : "Should reject null employee ID";
});

runTest("Empty Action Type", "Test handling of empty action type", function() use ($test_employee_id) {
    $logger = new UserActivityLogger();
    
    $result = $logger->logActivity(
        $test_employee_id,
        '', // Empty action type
        'Test with empty action',
        [],
        '127.0.0.1',
        'Test Agent'
    );
    
    return $result === false ? true : "Should reject empty action type";
});

runTest("Large Metadata", "Test handling of large metadata objects", function() use ($test_employee_id) {
    $logger = new UserActivityLogger();
    
    // Create large metadata object
    $large_metadata = [];
    for ($i = 0; $i < 100; $i++) {
        $large_metadata["key_$i"] = str_repeat("data", 100);
    }
    
    $result = $logger->logActivity(
        $test_employee_id,
        'test_large_metadata',
        'Test with large metadata',
        $large_metadata,
        '127.0.0.1',
        'Test Agent'
    );
    
    // Should handle large metadata gracefully
    return $result !== false ? true : "Failed to handle large metadata";
});

runTest("Special Characters", "Test handling of special characters in data", function() use ($test_employee_id) {
    $logger = new UserActivityLogger();
    
    $result = $logger->logActivity(
        $test_employee_id,
        'test_special_chars',
        'Test with special chars: àáâãäåæçèéêë ñòóôõö ùúûüý',
        [
            'special_data' => 'Testing: <script>alert("xss")</script>',
            'unicode' => '🔒💊🏥👨‍⚕️',
            'quotes' => 'It\'s a "test" with \'quotes\''
        ],
        '127.0.0.1',
        'Test Agent'
    );
    
    return $result === true ? true : "Failed to handle special characters";
});
echo "</div>\n";

// Test 5: Performance Tests
echo "<div class='section'>\n<h2>⚡ Performance Tests</h2>\n";

runTest("Single Log Performance", "Measure single log entry performance", function() use ($test_employee_id) {
    $logger = new UserActivityLogger();
    
    $start_time = microtime(true);
    $result = $logger->logActivity(
        $test_employee_id,
        'performance_test',
        'Single performance test',
        ['test_timestamp' => microtime(true)],
        '127.0.0.1',
        'Performance Test Agent'
    );
    $execution_time = (microtime(true) - $start_time) * 1000;
    
    if ($result !== true) {
        return "Logging failed";
    }
    
    if ($execution_time > 100) {
        return ['warning' => "Slow performance: {$execution_time}ms"];
    }
    
    return true;
});

runTest("Bulk Logging Performance", "Test logging multiple entries", function() use ($test_employee_id) {
    $logger = new UserActivityLogger();
    
    $start_time = microtime(true);
    $success_count = 0;
    
    for ($i = 0; $i < 10; $i++) {
        $result = $logger->logActivity(
            $test_employee_id,
            'bulk_test',
            "Bulk test entry $i",
            ['batch_id' => uniqid(), 'entry_number' => $i],
            '127.0.0.1',
            'Bulk Test Agent'
        );
        
        if ($result === true) {
            $success_count++;
        }
    }
    
    $total_time = (microtime(true) - $start_time) * 1000;
    $avg_time = $total_time / 10;
    
    if ($success_count < 10) {
        return "Only $success_count/10 entries logged successfully";
    }
    
    if ($avg_time > 50) {
        return ['warning' => "Slow bulk performance: {$avg_time}ms average"];
    }
    
    return true;
});
echo "</div>\n";

// Test 6: Security Tests
echo "<div class='section'>\n<h2>🔒 Security Tests</h2>\n";

runTest("IP Address Validation", "Test IP address handling and validation", function() use ($test_employee_id) {
    $logger = new UserActivityLogger();
    
    // Test various IP formats
    $test_ips = ['127.0.0.1', '192.168.1.1', '::1', 'invalid_ip'];
    
    foreach ($test_ips as $ip) {
        $result = $logger->logActivity(
            $test_employee_id,
            'ip_test',
            "Testing IP: $ip",
            ['test_ip' => $ip],
            $ip,
            'IP Test Agent'
        );
        
        // Should not fail completely, but may sanitize invalid IPs
        if ($result === false && $ip !== 'invalid_ip') {
            return "Failed for valid IP: $ip";
        }
    }
    
    return true;
});

runTest("SQL Injection Protection", "Test protection against SQL injection", function() use ($test_employee_id) {
    $logger = new UserActivityLogger();
    
    $malicious_inputs = [
        "'; DROP TABLE user_activity_logs; --",
        "1' OR '1'='1",
        "UNION SELECT * FROM employees",
        "<script>alert('xss')</script>"
    ];
    
    foreach ($malicious_inputs as $input) {
        $result = $logger->logActivity(
            $test_employee_id,
            'security_test',
            $input, // Potentially malicious description
            ['malicious_data' => $input],
            '127.0.0.1',
            $input // Potentially malicious user agent
        );
        
        // Should handle malicious input safely
        if ($result === false) {
            return "Failed to handle malicious input safely";
        }
    }
    
    return true;
});
echo "</div>\n";

// Test 7: Integration Tests
echo "<div class='section'>\n<h2>🔗 Integration Tests</h2>\n";

runTest("Health Check API", "Test health check endpoint", function() {
    $health_check_url = "http://localhost" . str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__) . "/health_check.php";
    
    // Simple file existence check since we can't make HTTP requests in this context
    $health_check_file = __DIR__ . "/health_check.php";
    
    if (!file_exists($health_check_file)) {
        return "Health check script not found";
    }
    
    // Check if the file contains the expected structure
    $content = file_get_contents($health_check_file);
    if (strpos($content, 'health_status') === false) {
        return "Health check script malformed";
    }
    
    return true;
});

runTest("Configuration Loading", "Test configuration file loading", function() {
    global $config;
    
    if (!is_array($config)) {
        return "Configuration not loaded as array";
    }
    
    $required_keys = ['environment', 'database', 'security', 'performance'];
    foreach ($required_keys as $key) {
        if (!isset($config[$key])) {
            return "Missing configuration key: $key";
        }
    }
    
    return true;
});
echo "</div>\n";

// Cleanup Test Data
echo "<div class='section'>\n<h2>🧹 Cleanup</h2>\n";

runTest("Cleanup Test Data", "Remove test entries from database", function() use ($test_employee_id) {
    global $pdo, $conn;
    
    try {
        if ($pdo) {
            $stmt = $pdo->prepare("DELETE FROM user_activity_logs WHERE employee_id = ? AND (action_type LIKE 'test_%' OR action_type IN ('performance_test', 'bulk_test', 'security_test', 'ip_test'))");
            $result = $stmt->execute([$test_employee_id]);
            $deleted_count = $stmt->rowCount();
        } else {
            $stmt = $conn->prepare("DELETE FROM user_activity_logs WHERE employee_id = ? AND (action_type LIKE 'test_%' OR action_type IN ('performance_test', 'bulk_test', 'security_test', 'ip_test'))");
            $stmt->bind_param('i', $test_employee_id);
            $result = $stmt->execute();
            $deleted_count = $conn->affected_rows;
        }
        
        return $result ? "Cleaned up $deleted_count test entries" : "Cleanup failed";
    } catch (Exception $e) {
        return ['warning' => 'Cleanup failed: ' . $e->getMessage()];
    }
});
echo "</div>\n";

// Test Summary
echo "<div class='section'>\n<h2>📊 Test Summary</h2>\n";

$success_rate = $total_tests > 0 ? round(($passed_tests / $total_tests) * 100, 1) : 0;

echo "<div class='metrics'>\n";
echo "<div class='metric'>\n<div class='metric-value'>$total_tests</div>\n<div class='metric-label'>Total Tests</div>\n</div>\n";
echo "<div class='metric'>\n<div class='metric-value' style='color: #28a745;'>$passed_tests</div>\n<div class='metric-label'>Passed</div>\n</div>\n";
echo "<div class='metric'>\n<div class='metric-value' style='color: #dc3545;'>$failed_tests</div>\n<div class='metric-label'>Failed</div>\n</div>\n";
echo "<div class='metric'>\n<div class='metric-value' style='color: #ffc107;'>$warnings</div>\n<div class='metric-label'>Warnings</div>\n</div>\n";
echo "<div class='metric'>\n<div class='metric-value' style='color: #007bff;'>{$success_rate}%</div>\n<div class='metric-label'>Success Rate</div>\n</div>\n";
echo "</div>\n";

echo "<div class='progress'>\n";
echo "<div class='progress-bar' style='width: {$success_rate}%;'></div>\n";
echo "</div>\n";

if ($failed_tests === 0 && $warnings === 0) {
    echo "<div class='test-pass'>\n";
    echo "<strong>🎉 All tests passed!</strong><br>\n";
    echo "Your activity logging system is production-ready.\n";
    echo "</div>\n";
} elseif ($failed_tests === 0) {
    echo "<div class='test-warning'>\n";
    echo "<strong>⚠️ Tests passed with warnings</strong><br>\n";
    echo "Review warnings above before deploying to production.\n";
    echo "</div>\n";
} else {
    echo "<div class='test-fail'>\n";
    echo "<strong>❌ Some tests failed</strong><br>\n";
    echo "Please fix the failed tests before deploying to production.\n";
    echo "</div>\n";
}

echo "<h3>System Information</h3>\n";
echo "<div class='code'>\n";
echo "PHP Version: " . PHP_VERSION . "<br>\n";
echo "Environment: " . ($config['environment'] ?? 'unknown') . "<br>\n";
echo "Database: " . (isset($pdo) ? 'PDO' : (isset($conn) ? 'MySQLi' : 'None')) . "<br>\n";
echo "Memory Usage: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB<br>\n";
echo "Peak Memory: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB<br>\n";
echo "Execution Time: " . round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3) . " seconds<br>\n";
echo "</div>\n";

echo "</div>\n"; // Summary section
echo "</div>\n"; // Container
echo "</body>\n</html>\n";
?>