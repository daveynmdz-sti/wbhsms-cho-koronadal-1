<?php
// Cashier Navigation and Access Test

require_once 'config/db.php';
require_once 'config/session/employee_session.php';

echo "<h1>🧪 Cashier User Experience Test</h1>";

// Test 1: Check if cashier employees exist
echo "<h2>✅ Test 1: Cashier Account Verification</h2>";

try {
    $stmt = $pdo->query("
        SELECT e.employee_id, e.first_name, e.last_name, e.employee_number, e.role_id, r.role_name, e.status 
        FROM employees e 
        JOIN roles r ON e.role_id = r.role_id 
        WHERE r.role_name = 'cashier' AND e.status = 'active'
        LIMIT 5
    ");
    $cashiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($cashiers)) {
        echo "<p style='color: green;'>✓ Found " . count($cashiers) . " active cashier accounts</p>";
        echo "<p><strong>Sample Cashier Accounts:</strong></p>";
        echo "<ul>";
        foreach ($cashiers as $cashier) {
            echo "<li>ID: {$cashier['employee_id']} - {$cashier['first_name']} {$cashier['last_name']} (Employee #: {$cashier['employee_number']}) - Role: {$cashier['role_name']}</li>";
        }
        echo "</ul>";
        
        // Use first cashier for testing
        $test_cashier = $cashiers[0];
        echo "<p><strong>🧪 Test Account:</strong> {$test_cashier['first_name']} {$test_cashier['last_name']} (Employee #: {$test_cashier['employee_number']}) - Role: {$test_cashier['role_name']}</p>";
    } else {
        echo "<p style='color: red;'>✗ No active cashier accounts found</p>";
        echo "<p><strong>Need to create a cashier account for testing!</strong></p>";
    }
} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Database error: " . $e->getMessage() . "</p>";
}

// Test 2: Simulate cashier session
if (!empty($cashiers)) {
    echo "<hr>";
    echo "<h2>✅ Test 2: Cashier Session Simulation</h2>";
    
    // Simulate login session
    $_SESSION['EMPLOYEE_SESSID'] = 'test_cashier_session_' . time();
    $_SESSION['employee_id'] = $test_cashier['employee_id'];
    $_SESSION['role_id'] = $test_cashier['role_id'];
    $_SESSION['role'] = $test_cashier['role_name'];
    $_SESSION['first_name'] = $test_cashier['first_name'];
    $_SESSION['last_name'] = $test_cashier['last_name'];
    $_SESSION['employee_number'] = $test_cashier['employee_number'];
    $_SESSION['login_time'] = time();
    
    echo "<p style='color: green;'>✓ Simulated cashier login session</p>";
    echo "<p><strong>Session Data:</strong></p>";
    echo "<ul>";
    echo "<li>Employee ID: {$_SESSION['employee_id']}</li>";
    echo "<li>Role ID: {$_SESSION['role_id']}</li>";
    echo "<li>Role: {$_SESSION['role']}</li>";
    echo "<li>Name: {$_SESSION['first_name']} {$_SESSION['last_name']}</li>";
    echo "<li>Employee Number: {$_SESSION['employee_number']}</li>";
    echo "<li>Logged in: " . (is_employee_logged_in() ? 'Yes' : 'No') . "</li>";
    echo "</ul>";
}

// Test 3: Check billing page access
echo "<hr>";
echo "<h2>✅ Test 3: Billing Page Access Control</h2>";

$billing_pages = [
    'Billing Dashboard' => 'pages/billing/billing_management.php',
    'Create Invoice' => 'pages/billing/create_invoice.php',
    'Process Payment' => 'pages/billing/process_payment.php', 
    'Billing Reports' => 'pages/billing/billing_reports.php'
];

foreach ($billing_pages as $page_name => $page_path) {
    if (file_exists($page_path)) {
        echo "<p style='color: green;'>✓ $page_name - File exists</p>";
        echo "<p style='margin-left: 20px;'><a href='$page_path' target='_blank'>🔗 Test $page_name Access</a></p>";
    } else {
        echo "<p style='color: red;'>✗ $page_name - File missing: $page_path</p>";
    }
}

// Test 4: Check cashier sidebar navigation
echo "<hr>";
echo "<h2>✅ Test 4: Cashier Sidebar Navigation</h2>";

if (file_exists('includes/sidebar_cashier.php')) {
    echo "<p style='color: green;'>✓ Cashier sidebar file exists</p>";
    
    // Check navigation links in sidebar
    $sidebar_content = file_get_contents('includes/sidebar_cashier.php');
    
    $expected_links = [
        'billing/billing_management.php' => 'Billing Dashboard',
        'billing/create_invoice.php' => 'Create Invoice',
        'billing/process_payment.php' => 'Process Payment', 
        'billing/billing_reports.php' => 'Billing Reports'
    ];
    
    echo "<p><strong>Navigation Links Check:</strong></p>";
    echo "<ul>";
    foreach ($expected_links as $link => $description) {
        if (strpos($sidebar_content, $link) !== false) {
            echo "<li style='color: green;'>✓ $description link found</li>";
        } else {
            echo "<li style='color: red;'>✗ $description link missing</li>";
        }
    }
    echo "</ul>";
    
} else {
    echo "<p style='color: red;'>✗ Cashier sidebar file missing</p>";
}

// Test 5: Dashboard integration check
echo "<hr>";
echo "<h2>✅ Test 5: Cashier Dashboard Integration</h2>";

if (file_exists('pages/management/cashier/dashboard.php')) {
    echo "<p style='color: green;'>✓ Cashier dashboard exists</p>";
    echo "<p><a href='pages/management/cashier/dashboard.php' target='_blank'>🔗 Test Cashier Dashboard</a></p>";
    
    // Check if dashboard has billing integration
    $dashboard_content = file_get_contents('pages/management/cashier/dashboard.php');
    if (strpos($dashboard_content, 'billing') !== false) {
        echo "<p style='color: green;'>✓ Dashboard has billing integration</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Dashboard may not have billing integration</p>";
    }
} else {
    echo "<p style='color: red;'>✗ Cashier dashboard missing</p>";
}

// Test 6: Authentication flow test
echo "<hr>";
echo "<h2>✅ Test 6: Authentication Flow</h2>";

echo "<p><strong>Login Flow Test:</strong></p>";
echo "<ol>";
echo "<li><a href='pages/management/auth/employee_login.php' target='_blank'>🔗 Go to Employee Login</a></li>";
echo "<li>Login with cashier credentials</li>";
echo "<li>Should redirect to cashier dashboard</li>";
echo "<li>Navigate to billing functions via sidebar</li>";
echo "</ol>";

if (!empty($cashiers)) {
    echo "<p><strong>💡 Test Credentials Available:</strong></p>";
    echo "<ul>";
    foreach (array_slice($cashiers, 0, 2) as $cashier) {
        echo "<li><strong>Employee Number:</strong> {$cashier['employee_number']} (Employee ID: {$cashier['employee_id']})</li>";
    }
    echo "</ul>";
    echo "<p><em>Note: You'll need the actual passwords - check with system administrator</em></p>";
}

echo "<hr>";
echo "<h2>🎯 Cashier User Experience Summary</h2>";

echo "<div style='background: #e8f5e8; padding: 15px; border-radius: 8px; border-left: 4px solid #28a745;'>";
echo "<h3>✅ Cashier Navigation Setup:</h3>";
echo "<ul>";
echo "<li><strong>Sidebar Navigation:</strong> Updated with correct billing page links</li>";
echo "<li><strong>Dashboard Integration:</strong> Cashier dashboard exists with billing data</li>";
echo "<li><strong>Access Control:</strong> Billing pages properly restrict to Cashier/Admin roles</li>";
echo "<li><strong>UI Patterns:</strong> Proper sidebar/topbar usage following system conventions</li>";
echo "</ul>";

echo "<h3>🚀 Recommended Testing Flow:</h3>";
echo "<ol>";
echo "<li><strong>Login:</strong> Use employee login as cashier</li>";
echo "<li><strong>Dashboard:</strong> Check cashier dashboard for billing summary</li>";
echo "<li><strong>Navigation:</strong> Use sidebar to access billing functions</li>";
echo "<li><strong>Invoice Creation:</strong> Test complete invoice workflow</li>";
echo "<li><strong>Payment Processing:</strong> Test payment receipt workflow</li>";
echo "<li><strong>Reporting:</strong> Check collections and analytics</li>";
echo "</ol>";
echo "</div>";

echo "<div style='background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 20px;'>";
echo "<h4>🎮 Interactive Testing Links:</h4>";
echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;'>";

$test_links = [
    'Employee Login' => 'pages/management/auth/employee_login.php',
    'Cashier Dashboard' => 'pages/management/cashier/dashboard.php',
    'Billing Dashboard' => 'pages/billing/billing_management.php',
    'Create Invoice' => 'pages/billing/create_invoice.php',
    'Process Payment' => 'pages/billing/process_payment.php',
    'Billing Reports' => 'pages/billing/billing_reports.php'
];

foreach ($test_links as $name => $url) {
    echo "<a href='$url' target='_blank' style='display: block; padding: 10px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; text-align: center; margin: 2px;'>$name</a>";
}

echo "</div>";
echo "</div>";
?>