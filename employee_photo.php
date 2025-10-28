<?php
// employee_photo.php - Root level Employee Profile Photo Controller
// Production-friendly photo serving for all employee pages
// Author: GitHub Copilot

// Clean all output buffers to prevent header issues
while (ob_get_level()) {
    ob_end_clean();
}

// Include session and database configuration
$root_path = __DIR__;
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Get employee ID from query parameter - support both id and employee_id for compatibility
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
    if (isset($conn)) {
        $stmt = $conn->prepare("SELECT profile_photo FROM employees WHERE employee_id = ?");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            
            if (!empty($row['profile_photo'])) {
                $photo_data = $row['profile_photo'];
                
                // Detect image type and set appropriate header
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime_type = $finfo->buffer($photo_data);
                
                // Set appropriate headers
                header('Content-Type: ' . $mime_type);
                header('Content-Length: ' . strlen($photo_data));
                header('Cache-Control: public, max-age=3600');
                header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
                
                // Output the image data directly
                echo $photo_data;
                exit;
            }
        }
    } else if (isset($pdo)) {
        // Use PDO if available
        $stmt = $pdo->prepare("SELECT profile_photo FROM employees WHERE employee_id = ?");
        $stmt->execute([$employee_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && !empty($row['profile_photo'])) {
            $photo_data = $row['profile_photo'];
            
            // Detect image type and set appropriate header
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime_type = $finfo->buffer($photo_data);
            
            // Set appropriate headers
            header('Content-Type: ' . $mime_type);
            header('Content-Length: ' . strlen($photo_data));
            header('Cache-Control: public, max-age=3600');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
            
            // Output the image data directly
            echo $photo_data;
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