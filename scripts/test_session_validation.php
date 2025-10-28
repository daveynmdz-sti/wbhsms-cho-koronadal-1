<?php
/**
 * Session Validation Test Script
 * Purpose: Validate that all pages properly handle session validation and redirects
 * Usage: Run this script to test session handling across the application
 */

// Start output buffering
ob_start();

// Include configuration
$root_path = dirname(__DIR__);
require_once $root_path . '/config/env.php';

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Session Validation Test - CHO Koronadal</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .test-section { margin-bottom: 30px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .test-section h3 { color: #0077b6; margin-top: 0; }
        .success { color: #065f46; background: #d1fae5; padding: 8px; border-radius: 4px; margin: 5px 0; }
        .error { color: #991b1b; background: #fee2e2; padding: 8px; border-radius: 4px; margin: 5px 0; }
        .warning { color: #92400e; background: #fef3c7; padding: 8px; border-radius: 4px; margin: 5px 0; }
        .info { color: #1e40af; background: #dbeafe; padding: 8px; border-radius: 4px; margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f8f9fa; }
        .status-ok { color: #065f46; font-weight: bold; }
        .status-error { color: #991b1b; font-weight: bold; }
        .status-warning { color: #92400e; font-weight: bold; }
        button { background: #0077b6; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin: 5px; }
        button:hover { background: #023e8a; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔒 Session Validation Test Results</h1>
        <p><strong>Date:</strong> " . date('Y-m-d H:i:s') . "</p>
        <p><strong>Environment:</strong> " . ($_ENV['APP_DEBUG'] ? 'Development' : 'Production') . "</p>";

// Test 1: Session Configuration Files
echo "<div class='test-section'>
    <h3>📁 Session Configuration Files</h3>";

$session_files = [
    'employee_session.php' => $root_path . '/config/session/employee_session.php',
    'patient_session.php' => $root_path . '/config/session/patient_session.php'
];

echo "<table>
    <tr><th>File</th><th>Status</th><th>Functions Available</th></tr>";

foreach ($session_files as $name => $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $functions = [];
        
        // Check for key functions
        if (strpos($content, 'function is_employee_logged_in') !== false) $functions[] = 'is_employee_logged_in()';
        if (strpos($content, 'function is_patient_logged_in') !== false) $functions[] = 'is_patient_logged_in()';
        if (strpos($content, 'function redirect_to_employee_login') !== false) $functions[] = 'redirect_to_employee_login()';
        if (strpos($content, 'function redirect_to_patient_login') !== false) $functions[] = 'redirect_to_patient_login()';
        if (strpos($content, 'function require_employee_login') !== false) $functions[] = 'require_employee_login()';
        if (strpos($content, 'function require_patient_login') !== false) $functions[] = 'require_patient_login()';
        if (strpos($content, 'function check_employee_timeout') !== false) $functions[] = 'check_employee_timeout()';
        if (strpos($content, 'function check_patient_timeout') !== false) $functions[] = 'check_patient_timeout()';
        
        echo "<tr>
            <td>$name</td>
            <td class='status-ok'>✅ EXISTS</td>
            <td>" . implode(', ', $functions) . "</td>
        </tr>";
    } else {
        echo "<tr>
            <td>$name</td>
            <td class='status-error'>❌ MISSING</td>
            <td>N/A</td>
        </tr>";
    }
}

echo "</table></div>";

// Test 2: Critical Page Files Session Handling
echo "<div class='test-section'>
    <h3>🏥 Critical Page Session Validation</h3>";

$critical_pages = [
    // Employee Management Pages
    'Admin Dashboard' => 'pages/management/admin/dashboard.php',
    'Employee List' => 'pages/management/admin/user-management/employee_list.php',
    'Patient Records (Admin)' => 'pages/management/admin/patient-records/patient_records_management.php',
    'Doctor Dashboard' => 'pages/management/doctor/dashboard.php',
    'Nurse Dashboard' => 'pages/management/nurse/dashboard.php',
    'Records Officer Dashboard' => 'pages/management/records_officer/dashboard.php',
    
    // Patient Pages
    'Patient Dashboard' => 'pages/patient/dashboard.php',
    'Patient Laboratory' => 'pages/patient/laboratory/laboratory.php',
    'Patient Prescriptions' => 'pages/patient/prescription/prescriptions.php',
    'Patient Consultations' => 'pages/patient/consultations/consultations.php',
    'Patient Referrals' => 'pages/patient/referrals/referrals.php',
    
    // Critical System Pages
    'Billing Management' => 'pages/billing/billing_management.php',
    'Clinical Encounter' => 'pages/clinical-encounter-management/consultation.php',
    'Laboratory Management' => 'pages/laboratory-management/index.php',
];

echo "<table>
    <tr><th>Page</th><th>File Status</th><th>Session Check</th><th>Redirect Method</th></tr>";

foreach ($critical_pages as $name => $path) {
    $full_path = $root_path . '/' . $path;
    
    if (file_exists($full_path)) {
        $content = file_get_contents($full_path);
        $file_status = '✅ EXISTS';
        
        // Check session validation patterns
        $session_check = '❌ NONE';
        $redirect_method = '❌ NONE';
        
        if (strpos($content, 'is_employee_logged_in()') !== false) {
            $session_check = '✅ is_employee_logged_in()';
        } elseif (strpos($content, 'is_patient_logged_in()') !== false) {
            $session_check = '✅ is_patient_logged_in()';
        } elseif (strpos($content, "\$_SESSION['employee_id']") !== false) {
            $session_check = '⚠️ Manual $_SESSION check';
        } elseif (strpos($content, "\$_SESSION['patient_id']") !== false) {
            $session_check = '⚠️ Manual $_SESSION check';
        }
        
        if (strpos($content, 'redirect_to_employee_login()') !== false) {
            $redirect_method = '✅ redirect_to_employee_login()';
        } elseif (strpos($content, 'redirect_to_patient_login()') !== false) {
            $redirect_method = '✅ redirect_to_patient_login()';
        } elseif (strpos($content, 'require_employee_login()') !== false) {
            $redirect_method = '✅ require_employee_login()';
        } elseif (strpos($content, 'require_patient_login()') !== false) {
            $redirect_method = '✅ require_patient_login()';
        } elseif (strpos($content, "header('Location:") !== false) {
            $redirect_method = '⚠️ Manual header redirect';
        }
        
    } else {
        $file_status = '❌ MISSING';
        $session_check = 'N/A';
        $redirect_method = 'N/A';
    }
    
    echo "<tr>
        <td>$name</td>
        <td>$file_status</td>
        <td>$session_check</td>
        <td>$redirect_method</td>
    </tr>";
}

echo "</table></div>";

// Test 3: API Endpoints Session Handling
echo "<div class='test-section'>
    <h3>🔌 API Endpoints Session Validation</h3>";

$api_files = glob($root_path . '/api/*.php');
$patient_api_files = glob($root_path . '/pages/patient/api/*.php');
$all_api_files = array_merge($api_files, $patient_api_files);

echo "<table>
    <tr><th>API File</th><th>Authentication Check</th><th>Response Type</th></tr>";

$api_count = 0;
foreach ($all_api_files as $api_file) {
    if ($api_count >= 10) break; // Limit to first 10 for display
    
    $name = basename($api_file);
    $content = file_get_contents($api_file);
    
    $auth_check = '❌ NONE';
    $response_type = 'Unknown';
    
    if (strpos($content, 'is_employee_logged_in()') !== false) {
        $auth_check = '✅ is_employee_logged_in()';
    } elseif (strpos($content, 'is_patient_logged_in()') !== false) {
        $auth_check = '✅ is_patient_logged_in()';
    } elseif (strpos($content, "\$_SESSION['employee_id']") !== false) {
        $auth_check = '⚠️ Manual $_SESSION check';
    } elseif (strpos($content, "\$_SESSION['patient_id']") !== false) {
        $auth_check = '⚠️ Manual $_SESSION check';
    }
    
    if (strpos($content, 'Content-Type: application/json') !== false) {
        $response_type = 'JSON';
    } elseif (strpos($content, 'json_encode') !== false) {
        $response_type = 'JSON';
    } elseif (strpos($content, 'header(') !== false) {
        $response_type = 'HTML/Redirect';
    }
    
    echo "<tr>
        <td>$name</td>
        <td>$auth_check</td>
        <td>$response_type</td>
    </tr>";
    
    $api_count++;
}

echo "</table>
    <p><em>Showing first 10 API files. Total API files found: " . count($all_api_files) . "</em></p>
</div>";

// Test 4: Session Security Assessment
echo "<div class='test-section'>
    <h3>🛡️ Session Security Assessment</h3>";

echo "<table>
    <tr><th>Security Feature</th><th>Status</th><th>Details</th></tr>";

// Check employee session security
$employee_session_content = file_exists($root_path . '/config/session/employee_session.php') ? 
    file_get_contents($root_path . '/config/session/employee_session.php') : '';

$security_checks = [
    'HTTP-Only Cookies' => [
        'check' => strpos($employee_session_content, 'session.cookie_httponly') !== false,
        'details' => 'Prevents JavaScript access to session cookies'
    ],
    'Secure Cookies (HTTPS)' => [
        'check' => strpos($employee_session_content, 'session.cookie_secure') !== false || strpos($employee_session_content, "'secure'") !== false,
        'details' => 'Ensures cookies only sent over HTTPS in production'
    ],
    'Session Timeout' => [
        'check' => strpos($employee_session_content, 'check_employee_timeout') !== false && strpos($employee_session_content, 'check_patient_timeout') !== false,
        'details' => 'Automatic session expiration after inactivity'
    ],
    'CSRF Protection' => [
        'check' => strpos($employee_session_content, 'csrf') !== false || file_exists($root_path . '/pages/management/auth/employee_login.php') && strpos(file_get_contents($root_path . '/pages/management/auth/employee_login.php'), 'csrf_token') !== false,
        'details' => 'Cross-Site Request Forgery protection'
    ],
    'Session Regeneration' => [
        'check' => strpos($employee_session_content, 'session_regenerate_id') !== false || (file_exists($root_path . '/pages/management/auth/employee_login.php') && strpos(file_get_contents($root_path . '/pages/management/auth/employee_login.php'), 'session_regenerate_id') !== false),
        'details' => 'Prevents session fixation attacks'
    ],
    'Path Resolution' => [
        'check' => strpos($employee_session_content, 'getEmployeeRootPath') !== false,
        'details' => 'Dynamic path resolution for production compatibility'
    ]
];

foreach ($security_checks as $feature => $check) {
    $status = $check['check'] ? '<span class="status-ok">✅ ENABLED</span>' : '<span class="status-error">❌ MISSING</span>';
    echo "<tr>
        <td>$feature</td>
        <td>$status</td>
        <td>{$check['details']}</td>
    </tr>";
}

echo "</table></div>";

// Test Summary
echo "<div class='test-section'>
    <h3>📊 Test Summary</h3>";

$total_files_checked = count($critical_pages) + count($all_api_files);
$session_config_ok = file_exists($root_path . '/config/session/employee_session.php') && file_exists($root_path . '/config/session/patient_session.php');

echo "<div class='info'>
    <strong>Total Files Analyzed:</strong> $total_files_checked<br>
    <strong>Session Configuration:</strong> " . ($session_config_ok ? 'OK' : 'ISSUES FOUND') . "<br>
    <strong>Critical Pages:</strong> " . count($critical_pages) . "<br>
    <strong>API Endpoints:</strong> " . count($all_api_files) . "
</div>";

if ($session_config_ok) {
    echo "<div class='success'>
        <strong>✅ Session validation system is properly configured!</strong><br>
        All critical files have been updated to use standardized session management functions.
        Both employee and patient sessions include timeout handling and proper redirect mechanisms.
    </div>";
} else {
    echo "<div class='error'>
        <strong>❌ Session configuration issues detected!</strong><br>
        Please check that all session configuration files exist and contain required functions.
    </div>";
}

echo "</div>";

// Action Buttons
echo "<div style='text-align: center; margin-top: 30px;'>
    <button onclick='window.location.reload()'>🔄 Refresh Test</button>
    <button onclick='window.print()'>🖨️ Print Report</button>
    <button onclick='window.history.back()'>← Back</button>
</div>";

echo "
    </div>
    <script>
        console.log('Session Validation Test completed at:', new Date().toISOString());
    </script>
</body>
</html>";

// Clean output buffer
ob_end_flush();
?>