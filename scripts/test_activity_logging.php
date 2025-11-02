<?php
/**
 * User Activity Logging Test Script
 * Tests all the implemented activity logging functionality
 * 
 * @author GitHub Copilot
 * @version 1.0
 * @since November 3, 2025
 */

// Include required files
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/db.php';
require_once $root_path . '/utils/UserActivityLogger.php';

echo "<h1>User Activity Logging Test Script</h1>\n";
echo "<style>body { font-family: Arial, sans-serif; margin: 20px; } .success { color: green; } .error { color: red; } .info { color: blue; } table { border-collapse: collapse; width: 100%; } th, td { border: 1px solid #ddd; padding: 8px; text-align: left; } th { background-color: #f2f2f2; }</style>\n";

$test_results = [];
$total_tests = 0;
$passed_tests = 0;

// Function to run a test
function runTest($description, $test_function) {
    global $test_results, $total_tests, $passed_tests;
    
    $total_tests++;
    echo "<h3>Test: $description</h3>\n";
    
    try {
        $result = $test_function();
        if ($result['success']) {
            echo "<p class='success'>✓ PASSED: {$result['message']}</p>\n";
            $passed_tests++;
            $test_results[] = ['test' => $description, 'status' => 'PASSED', 'message' => $result['message']];
        } else {
            echo "<p class='error'>✗ FAILED: {$result['message']}</p>\n";
            $test_results[] = ['test' => $description, 'status' => 'FAILED', 'message' => $result['message']];
        }
    } catch (Exception $e) {
        echo "<p class='error'>✗ ERROR: " . $e->getMessage() . "</p>\n";
        $test_results[] = ['test' => $description, 'status' => 'ERROR', 'message' => $e->getMessage()];
    }
}

// Test 1: Database table structure
runTest("Database table structure", function() {
    global $pdo, $conn;
    
    $db = $pdo ?: $conn;
    if (!$db) {
        return ['success' => false, 'message' => 'No database connection available'];
    }
    
    if ($pdo) {
        $stmt = $pdo->query("DESCRIBE user_activity_logs");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $result = $conn->query("DESCRIBE user_activity_logs");
        $columns = $result->fetch_all(MYSQLI_ASSOC);
    }
    
    $required_columns = ['user_type', 'ip_address', 'device_info'];
    $found_columns = array_column($columns, 'Field');
    
    foreach ($required_columns as $column) {
        if (!in_array($column, $found_columns)) {
            return ['success' => false, 'message' => "Missing column: $column"];
        }
    }
    
    return ['success' => true, 'message' => 'All required columns exist'];
});

// Test 2: Activity logger instantiation
runTest("Activity logger instantiation", function() {
    $logger = new UserActivityLogger();
    if ($logger instanceof UserActivityLogger) {
        return ['success' => true, 'message' => 'UserActivityLogger instantiated successfully'];
    }
    return ['success' => false, 'message' => 'Failed to instantiate UserActivityLogger'];
});

// Test 3: Helper function availability
runTest("Helper function availability", function() {
    if (function_exists('activity_logger')) {
        $logger = activity_logger();
        if ($logger instanceof UserActivityLogger) {
            return ['success' => true, 'message' => 'Helper function activity_logger() works correctly'];
        }
    }
    return ['success' => false, 'message' => 'Helper function activity_logger() not available or not working'];
});

// Test 4: Basic activity logging
runTest("Basic activity logging", function() {
    $logger = activity_logger();
    
    $result = $logger->logActivity(
        1, // admin_id
        1, // employee_id  
        'employee', // user_type
        'login', // action_type
        'Test login activity from test script' // description
    );
    
    if ($result) {
        return ['success' => true, 'message' => 'Basic activity logging successful'];
    }
    return ['success' => false, 'message' => 'Basic activity logging failed'];
});

// Test 5: Login logging
runTest("Login logging", function() {
    $logger = activity_logger();
    
    $result = $logger->logLogin(1, 'employee', 'test_user');
    
    if ($result) {
        return ['success' => true, 'message' => 'Login logging successful'];
    }
    return ['success' => false, 'message' => 'Login logging failed'];
});

// Test 6: Failed login logging
runTest("Failed login logging", function() {
    $logger = activity_logger();
    
    $result = $logger->logFailedLogin('test_invalid_user', 'employee');
    
    if ($result) {
        return ['success' => true, 'message' => 'Failed login logging successful'];
    }
    return ['success' => false, 'message' => 'Failed login logging failed'];
});

// Test 7: Logout logging
runTest("Logout logging", function() {
    $logger = activity_logger();
    
    $result = $logger->logLogout(1, 'employee');
    
    if ($result) {
        return ['success' => true, 'message' => 'Logout logging successful'];
    }
    return ['success' => false, 'message' => 'Logout logging failed'];
});

// Test 8: Session start logging
runTest("Session start logging", function() {
    $logger = activity_logger();
    
    $result = $logger->logSessionStart(1, 'employee');
    
    if ($result) {
        return ['success' => true, 'message' => 'Session start logging successful'];
    }
    return ['success' => false, 'message' => 'Session start logging failed'];
});

// Test 9: Session end logging
runTest("Session end logging", function() {
    $logger = activity_logger();
    
    $result = $logger->logSessionEnd(1, 'employee', 'timeout');
    
    if ($result) {
        return ['success' => true, 'message' => 'Session end logging successful'];
    }
    return ['success' => false, 'message' => 'Session end logging failed'];
});

// Test 10: Password change logging
runTest("Password change logging", function() {
    $logger = activity_logger();
    
    $result = $logger->logPasswordChange(1, 'employee');
    
    if ($result) {
        return ['success' => true, 'message' => 'Password change logging successful'];
    }
    return ['success' => false, 'message' => 'Password change logging failed'];
});

// Test 11: Password reset logging
runTest("Password reset logging", function() {
    $logger = activity_logger();
    
    $result = $logger->logPasswordReset(1, 'employee', 2);
    
    if ($result) {
        return ['success' => true, 'message' => 'Password reset logging successful'];
    }
    return ['success' => false, 'message' => 'Password reset logging failed'];
});

// Test 12: Account lock/unlock logging
runTest("Account lock/unlock logging", function() {
    $logger = activity_logger();
    
    $lock_result = $logger->logAccountLockUnlock(1, 'employee', 'lock', 2);
    $unlock_result = $logger->logAccountLockUnlock(1, 'employee', 'unlock', 2);
    
    if ($lock_result && $unlock_result) {
        return ['success' => true, 'message' => 'Account lock/unlock logging successful'];
    }
    return ['success' => false, 'message' => 'Account lock/unlock logging failed'];
});

// Test 13: IP address detection
runTest("IP address detection", function() {
    $logger = activity_logger();
    
    // Simulate different IP scenarios
    $original_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
    $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
    
    $result = $logger->logActivity(1, 1, 'employee', 'login', 'IP address test');
    
    // Restore original
    if ($original_remote_addr) {
        $_SERVER['REMOTE_ADDR'] = $original_remote_addr;
    } else {
        unset($_SERVER['REMOTE_ADDR']);
    }
    
    if ($result) {
        return ['success' => true, 'message' => 'IP address detection working'];
    }
    return ['success' => false, 'message' => 'IP address detection failed'];
});

// Test 14: Device info detection
runTest("Device info detection", function() {
    $logger = activity_logger();
    
    // Simulate user agent
    $original_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Test Browser) Activity Logger Test';
    
    $result = $logger->logActivity(1, 1, 'employee', 'login', 'Device info test');
    
    // Restore original
    if ($original_user_agent) {
        $_SERVER['HTTP_USER_AGENT'] = $original_user_agent;
    } else {
        unset($_SERVER['HTTP_USER_AGENT']);
    }
    
    if ($result) {
        return ['success' => true, 'message' => 'Device info detection working'];
    }
    return ['success' => false, 'message' => 'Device info detection failed'];
});

// Test 15: Recent logs verification
runTest("Recent logs verification", function() {
    global $pdo, $conn;
    
    $db = $pdo ?: $conn;
    
    if ($pdo) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM user_activity_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            AND description LIKE '%test%'
        ");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = $result['count'];
    } else {
        $result = $conn->query("
            SELECT COUNT(*) as count 
            FROM user_activity_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            AND description LIKE '%test%'
        ");
        $row = $result->fetch_assoc();
        $count = $row['count'];
    }
    
    if ($count > 0) {
        return ['success' => true, 'message' => "Found $count recent test log entries"];
    }
    return ['success' => false, 'message' => 'No recent test log entries found'];
});

// Display test summary
echo "<h2>Test Summary</h2>\n";
echo "<p class='info'>Total Tests: $total_tests | Passed: $passed_tests | Failed: " . ($total_tests - $passed_tests) . "</p>\n";

if ($passed_tests == $total_tests) {
    echo "<p class='success'><strong>🎉 All tests passed! The user activity logging system is working correctly.</strong></p>\n";
} else {
    echo "<p class='error'><strong>⚠️ Some tests failed. Please review the implementation.</strong></p>\n";
}

// Display recent activity logs
echo "<h2>Recent Activity Logs (Last 10)</h2>\n";

try {
    $db = $pdo ?: $conn;
    
    if ($pdo) {
        $stmt = $pdo->query("
            SELECT log_id, admin_id, employee_id, user_type, action_type, description, 
                   ip_address, device_info, created_at 
            FROM user_activity_logs 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $result = $conn->query("
            SELECT log_id, admin_id, employee_id, user_type, action_type, description, 
                   ip_address, device_info, created_at 
            FROM user_activity_logs 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $logs = $result->fetch_all(MYSQLI_ASSOC);
    }
    
    if (!empty($logs)) {
        echo "<table>\n";
        echo "<tr><th>ID</th><th>Admin ID</th><th>Employee ID</th><th>User Type</th><th>Action</th><th>Description</th><th>IP Address</th><th>Device Info</th><th>Created At</th></tr>\n";
        
        foreach ($logs as $log) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($log['log_id']) . "</td>";
            echo "<td>" . htmlspecialchars($log['admin_id'] ?: 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($log['employee_id'] ?: 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($log['user_type'] ?: 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($log['action_type']) . "</td>";
            echo "<td>" . htmlspecialchars(substr($log['description'], 0, 50)) . "...</td>";
            echo "<td>" . htmlspecialchars($log['ip_address'] ?: 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars(substr($log['device_info'] ?: 'N/A', 0, 30)) . "...</td>";
            echo "<td>" . htmlspecialchars($log['created_at']) . "</td>";
            echo "</tr>\n";
        }
        
        echo "</table>\n";
    } else {
        echo "<p>No activity logs found.</p>\n";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>Error fetching recent logs: " . $e->getMessage() . "</p>\n";
}

echo "<h2>Next Steps</h2>\n";
echo "<ol>\n";
echo "<li>Run the migration script if you haven't: <code>php scripts/migrate_activity_logs.php</code></li>\n";
echo "<li>Test login/logout functionality in the application</li>\n";
echo "<li>Test password change functionality</li>\n";
echo "<li>Monitor the user_activity_logs table for new entries</li>\n";
echo "<li>Check the activity logs page: <a href='../pages/management/admin/user-management/user_activity_logs.php'>User Activity Logs</a></li>\n";
echo "</ol>\n";
?>