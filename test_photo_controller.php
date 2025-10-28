<?php
// test_photo_controller.php - Debug script for photo controller issues
// This script helps diagnose photo controller 404 issues

echo "<h1>Photo Controller Diagnostic</h1>";

// Test 1: Check if files exist
echo "<h2>1. File Existence Check</h2>";
$files_to_check = [
    'vendor/employee_photo_controller.php',
    'employee_photo.php',
    'pages/user/employee_photo.php',
    'pages/management/employee_photo.php'
];

foreach ($files_to_check as $file) {
    $exists = file_exists($file);
    echo "<p><strong>$file:</strong> " . ($exists ? "✅ EXISTS" : "❌ NOT FOUND") . "</p>";
}

// Test 2: Check session
echo "<h2>2. Session Check</h2>";
session_start();
echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
echo "<p><strong>Employee ID in session:</strong> " . (isset($_SESSION['employee_id']) ? $_SESSION['employee_id'] : 'NOT SET') . "</p>";
echo "<p><strong>All session data:</strong></p>";
echo "<pre>" . print_r($_SESSION, true) . "</pre>";

// Test 3: Test database connection
echo "<h2>3. Database Connection Check</h2>";
try {
    require_once 'config/db.php';
    
    if (isset($conn)) {
        echo "<p><strong>MySQLi Connection:</strong> ✅ CONNECTED</p>";
        
        // Test employee query
        if (isset($_SESSION['employee_id'])) {
            $stmt = $conn->prepare("SELECT employee_id, first_name, last_name, LENGTH(profile_photo) as photo_size FROM employees WHERE employee_id = ?");
            $stmt->bind_param("i", $_SESSION['employee_id']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $employee = $result->fetch_assoc();
                echo "<p><strong>Employee Record:</strong> ✅ FOUND</p>";
                echo "<pre>" . print_r($employee, true) . "</pre>";
            } else {
                echo "<p><strong>Employee Record:</strong> ❌ NOT FOUND</p>";
            }
        }
    } else {
        echo "<p><strong>MySQLi Connection:</strong> ❌ FAILED</p>";
    }
} catch (Exception $e) {
    echo "<p><strong>Database Error:</strong> " . $e->getMessage() . "</p>";
}

// Test 4: Direct access to photo controller
echo "<h2>4. Photo Controller Direct Test</h2>";
if (isset($_SESSION['employee_id'])) {
    $employee_id = $_SESSION['employee_id'];
    echo "<p><strong>Test URL:</strong> <a href='vendor/employee_photo_controller.php?employee_id=$employee_id' target='_blank'>vendor/employee_photo_controller.php?employee_id=$employee_id</a></p>";
    echo "<p><strong>Alternative URL:</strong> <a href='employee_photo.php?id=$employee_id' target='_blank'>employee_photo.php?id=$employee_id</a></p>";
} else {
    echo "<p>❌ No employee ID in session to test</p>";
}

// Test 5: Check current working directory and paths
echo "<h2>5. Path Information</h2>";
echo "<p><strong>Current Working Directory:</strong> " . getcwd() . "</p>";
echo "<p><strong>Script Directory:</strong> " . __DIR__ . "</p>";
echo "<p><strong>Document Root:</strong> " . $_SERVER['DOCUMENT_ROOT'] . "</p>";
echo "<p><strong>Request URI:</strong> " . $_SERVER['REQUEST_URI'] . "</p>";
echo "<p><strong>HTTP Host:</strong> " . $_SERVER['HTTP_HOST'] . "</p>";
?>