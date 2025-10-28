<?php
// test_management_photo.php - Test management employee photo paths
session_start();

echo "<h1>Management Employee Photo Path Test</h1>";

// Test production URL generation (same logic as updated management pages)
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];

echo "<h2>Server Information</h2>";
echo "<p><strong>Protocol:</strong> $protocol</p>";
echo "<p><strong>Host:</strong> $host</p>";
echo "<p><strong>Script Name:</strong> $script_name</p>";

if (preg_match('#^(/[^/]+)/#', $script_name, $matches)) {
    $base_path = $matches[1] . '/';
} else {
    $base_path = '/';
}

echo "<p><strong>Base Path:</strong> $base_path</p>";

// Test employee ID from session
$employee_id = $_SESSION['employee_id'] ?? 1; // Default to 1 for testing
echo "<p><strong>Test Employee ID:</strong> $employee_id</p>";

// Generate photo URLs
$photo_url = $protocol . '://' . $host . $base_path . 'employee_photo.php?id=' . $employee_id;
$management_photo_url = $protocol . '://' . $host . $base_path . 'pages/management/employee_photo.php?id=' . $employee_id;

echo "<h2>Generated Photo URLs</h2>";
echo "<p><strong>Root Level Controller:</strong> <a href='$photo_url' target='_blank'>$photo_url</a></p>";
echo "<p><strong>Management Controller:</strong> <a href='$management_photo_url' target='_blank'>$management_photo_url</a></p>";

// Test file existence
echo "<h2>File Existence Check</h2>";
$files = [
    'employee_photo.php' => file_exists('employee_photo.php'),
    'pages/management/employee_photo.php' => file_exists('pages/management/employee_photo.php'),
];

foreach ($files as $file => $exists) {
    echo "<p><strong>$file:</strong> " . ($exists ? "✅ EXISTS" : "❌ NOT FOUND") . "</p>";
}

// Test database connection and employee data
echo "<h2>Database Test</h2>";
try {
    require_once 'config/db.php';
    
    if (isset($conn)) {
        echo "<p><strong>Database Connection:</strong> ✅ CONNECTED</p>";
        
        $stmt = $conn->prepare("SELECT employee_id, first_name, last_name, LENGTH(profile_photo) as photo_size FROM employees WHERE employee_id = ?");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $employee = $result->fetch_assoc();
            echo "<p><strong>Employee Found:</strong> ✅ " . $employee['first_name'] . ' ' . $employee['last_name'] . "</p>";
            echo "<p><strong>Photo Size:</strong> " . ($employee['photo_size'] > 0 ? $employee['photo_size'] . ' bytes' : 'No photo') . "</p>";
        } else {
            echo "<p><strong>Employee:</strong> ❌ NOT FOUND</p>";
        }
    } else {
        echo "<p><strong>Database Connection:</strong> ❌ FAILED</p>";
    }
} catch (Exception $e) {
    echo "<p><strong>Database Error:</strong> " . $e->getMessage() . "</p>";
}

// Test direct image output
echo "<h2>Direct Image Test</h2>";
echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
echo "<p><strong>Root Level Photo Controller:</strong></p>";
echo "<img src='$photo_url' alt='Employee Photo' style='max-width: 100px; max-height: 100px;' onerror=\"this.onerror=null;this.src='https://ik.imagekit.io/wbhsmslogo/user.png?updatedAt=1750423429172';\">";
echo "</div>";

echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
echo "<p><strong>Management Photo Controller:</strong></p>";
echo "<img src='$management_photo_url' alt='Employee Photo' style='max-width: 100px; max-height: 100px;' onerror=\"this.onerror=null;this.src='https://ik.imagekit.io/wbhsmslogo/user.png?updatedAt=1750423429172';\">";
echo "</div>";
?>