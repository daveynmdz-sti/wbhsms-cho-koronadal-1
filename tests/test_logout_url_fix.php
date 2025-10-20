<?php
/**
 * Test script for employee logout URL generation
 * Tests the fixed getBaseUrl() function
 */

// Mock the logout function for testing
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $requestUri = $_SERVER['REQUEST_URI'];
    
    // Find the root of the application by looking for the first occurrence of '/pages/'
    $pagesPos = strpos($requestUri, '/pages/');
    if ($pagesPos !== false) {
        // Extract everything before '/pages/' as the base URL
        $basePath = substr($requestUri, 0, $pagesPos);
        return $protocol . $host . $basePath;
    }
    
    // Fallback: return protocol + host only
    return $protocol . $host;
}

echo "Testing Employee Logout URL Generation...\n\n";

// Test cases simulating different server environments
$test_cases = [
    // Production environment
    [
        'HTTP_HOST' => 'cityhealthofficeofkoronadal.31.97.106.60.sslip.io',
        'REQUEST_URI' => '/pages/management/auth/employee_logout.php',
        'HTTPS' => 'off',
        'expected' => 'http://cityhealthofficeofkoronadal.31.97.106.60.sslip.io'
    ],
    // Local XAMPP
    [
        'HTTP_HOST' => 'localhost',
        'REQUEST_URI' => '/wbhsms-cho-koronadal-1/pages/management/auth/employee_logout.php',
        'HTTPS' => 'off',
        'expected' => 'http://localhost/wbhsms-cho-koronadal-1'
    ],
    // Local with subfolder
    [
        'HTTP_HOST' => 'localhost',
        'REQUEST_URI' => '/project/pages/management/admin/dashboard.php',
        'HTTPS' => 'off',
        'expected' => 'http://localhost/project'
    ],
    // HTTPS production
    [
        'HTTP_HOST' => 'cityhealthofficeofkoronadal.31.97.106.60.sslip.io',
        'REQUEST_URI' => '/pages/management/auth/employee_logout.php',
        'HTTPS' => 'on',
        'expected' => 'https://cityhealthofficeofkoronadal.31.97.106.60.sslip.io'
    ]
];

$all_pass = true;

foreach ($test_cases as $i => $case) {
    $_SERVER['HTTP_HOST'] = $case['HTTP_HOST'];
    $_SERVER['REQUEST_URI'] = $case['REQUEST_URI'];
    $_SERVER['HTTPS'] = $case['HTTPS'];
    
    $result = getBaseUrl();
    $expected = $case['expected'];
    $status = ($result === $expected) ? "✅ PASS" : "❌ FAIL";
    
    echo "Test " . ($i + 1) . ": $status\n";
    echo "  Host: {$case['HTTP_HOST']}\n";
    echo "  URI: {$case['REQUEST_URI']}\n";
    echo "  Expected: $expected\n";
    echo "  Got: $result\n";
    
    if ($result !== $expected) {
        $all_pass = false;
        echo "  ❌ Mismatch!\n";
    }
    
    echo "\n";
}

// Test the specific problematic case
echo "=== SPECIFIC BUG TEST ===\n";
$_SERVER['HTTP_HOST'] = 'cityhealthofficeofkoronadal.31.97.106.60.sslip.io';
$_SERVER['REQUEST_URI'] = '/pages/management/auth/employee_logout.php';
$_SERVER['HTTPS'] = 'off';

$baseUrl = getBaseUrl();
$loginUrl = $baseUrl . '/pages/management/auth/employee_login.php?logged_out=1';

echo "Base URL: $baseUrl\n";
echo "Login URL: $loginUrl\n";

$has_double_pages = strpos($loginUrl, '/pages/pages/') !== false;
echo "Has double /pages/: " . ($has_double_pages ? "❌ YES (BUG)" : "✅ NO (FIXED)") . "\n";

echo "\n=== SUMMARY ===\n";
if ($all_pass && !$has_double_pages) {
    echo "✅ ALL TESTS PASSED\n";
    echo "The URL generation bug has been fixed.\n";
    echo "Logout should now redirect to the correct URL.\n";
} else {
    echo "❌ SOME TESTS FAILED\n";
    echo "Please review the getBaseUrl() function.\n";
}
?>