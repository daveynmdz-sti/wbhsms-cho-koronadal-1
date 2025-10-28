<?php
// pages/management/employee_photo.php
// Employee Profile Photo Controller - Management pages version
// Author: GitHub Copilot

// Prevent any output before headers
ob_start();

// Include session and database configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Clear any output buffer
ob_clean();

// Set appropriate headers for image display
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');

// Get employee ID from query parameter
$employee_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : null);

// Default user image URL
$default_image_url = 'https://ik.imagekit.io/wbhsmslogo/user.png?updatedAt=1750423429172';

if (!$employee_id) {
    // Redirect to default image if no employee ID provided
    header('Location: ' . $default_image_url);
    exit;
}

try {
    // Check if user is logged in (basic security check)
    if (!is_employee_logged_in()) {
        header('Location: ' . $default_image_url);
        exit;
    }

    // Fetch profile photo from database
    $stmt = $conn->prepare("SELECT profile_photo FROM employees WHERE employee_id = ?");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        if (!empty($row['profile_photo'])) {
            // Output the image data directly
            echo $row['profile_photo'];
            exit;
        }
    }
    
    // If no photo found, redirect to default image
    header('Location: ' . $default_image_url);
    exit;
    
} catch (Exception $e) {
    // On error, redirect to default image
    error_log("Employee Photo Controller Error: " . $e->getMessage());
    header('Location: ' . $default_image_url);
    exit;
}
?>