<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include employee session configuration
$root_path = dirname(dirname(dirname(__DIR__)));

try {
    require_once $root_path . '/config/session/employee_session.php';
    include $root_path . '/config/db.php';
} catch (Exception $e) {
    error_log("Configuration error in view_lab_result.php: " . $e->getMessage());
    http_response_code(500);
    exit('Configuration error: ' . $e->getMessage());
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    error_log("Database connection failed in view_lab_result.php");
    http_response_code(500);
    exit('Database connection failed');
}

// Check if user is logged in and has appropriate role
$authorizedRoleIds = [1, 2, 3, 9]; // admin, doctor, nurse, laboratory_tech
if (!isset($_SESSION['employee_id']) || !in_array($_SESSION['role_id'], $authorizedRoleIds)) {
    error_log("Unauthorized access attempt in view_lab_result.php - Employee ID: " . ($_SESSION['employee_id'] ?? 'none') . ", Role ID: " . ($_SESSION['role_id'] ?? 'none'));
    http_response_code(403);
    exit('Not authorized');
}

$item_id = $_GET['item_id'] ?? null;

if (!$item_id) {
    http_response_code(400);
    exit('Lab order item ID is required');
}

try {
    // Fetch the file data from database with patient info for audit logging
    $sql = "SELECT loi.result_file, loi.test_type, loi.result_date,
                   p.first_name, p.last_name, p.username as patient_id_display,
                   lo.lab_order_id
            FROM lab_order_items loi
            LEFT JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id  
            LEFT JOIN patients p ON lo.patient_id = p.patient_id
            WHERE loi.item_id = ? AND loi.result_file IS NOT NULL";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare statement: " . $conn->error);
    }
    
    $stmt->bind_param("i", $item_id);
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    if (!$result) {
        throw new Exception("Failed to get result: " . $stmt->error);
    }
    
    $data = $result->fetch_assoc();
    
    if (!$data || !$data['result_file']) {
        http_response_code(404);
        exit('File not found');
    }
    
    // Log the access for audit trail (try to create table if it doesn't exist)
    try {
        // Check if audit table exists, create if not
        $table_check = $conn->query("SHOW TABLES LIKE 'lab_result_view_logs'");
        if ($table_check->num_rows === 0) {
            // Create the table if it doesn't exist
            $create_table_sql = "CREATE TABLE IF NOT EXISTS `lab_result_view_logs` (
              `log_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
              `lab_item_id` int UNSIGNED NOT NULL,
              `employee_id` int UNSIGNED NOT NULL,
              `patient_name` varchar(101) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
              `viewed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
              `user_agent` text COLLATE utf8mb4_unicode_ci,
              PRIMARY KEY (`log_id`),
              KEY `idx_lab_item_id` (`lab_item_id`),
              KEY `idx_employee_id` (`employee_id`),
              KEY `idx_viewed_at` (`viewed_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for lab result file access'";
            
            $conn->query($create_table_sql);
        }
        
        $audit_sql = "INSERT INTO lab_result_view_logs (lab_item_id, employee_id, viewed_at, patient_name, ip_address, user_agent) 
                      VALUES (?, ?, NOW(), ?, ?, ?)";
        $audit_stmt = $conn->prepare($audit_sql);
        
        if ($audit_stmt) {
            $patient_name = trim($data['first_name'] . ' ' . $data['last_name']);
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            $audit_stmt->bind_param("iisss", $item_id, $_SESSION['employee_id'], $patient_name, $ip_address, $user_agent);
            $audit_stmt->execute();
        }
    } catch (Exception $audit_error) {
        // Don't fail the main operation if audit logging fails
        error_log("Audit logging failed for item_id $item_id: " . $audit_error->getMessage());
    }
    
    // Determine content type based on file content
    $fileData = $data['result_file'];
    
    if (empty($fileData)) {
        throw new Exception("File data is empty for item_id: $item_id");
    }
    
    $contentType = 'application/octet-stream'; // default
    
    // Check file signature to determine type
    $fileStart = substr($fileData, 0, 10); // Get first 10 bytes for analysis
    
    if (substr($fileData, 0, 4) === '%PDF') {
        $contentType = 'application/pdf';
    } elseif (substr($fileData, 0, 2) === 'PK') {
        // Excel files start with PK (ZIP signature)
        $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    } elseif (strpos($fileData, ',') !== false || ctype_print($fileStart)) {
        // CSV files are typically printable text with commas
        $contentType = 'text/csv';
    }
    
    error_log("Processing file for item_id $item_id: Content type determined as $contentType, file size: " . strlen($fileData) . " bytes");
    
    // For PDF viewer, we want inline display, not download
    if ($contentType === 'application/pdf') {
        // Set headers for inline PDF viewing
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Lab_Result_Item' . $item_id . '.pdf"');
        header('Content-Length: ' . strlen($fileData));
        header('Cache-Control: private, max-age=300'); // Cache for 5 minutes
        header('Pragma: private');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN'); // Security header
        
        // Output the PDF data for inline viewing
        echo $fileData;
    } else {
        // For non-PDF files, redirect to download endpoint
        header("Location: download_lab_result.php?item_id=" . urlencode($item_id));
        exit();
    }
    
} catch (Exception $e) {
    error_log("PDF viewing error for item_id $item_id: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    
    // In development, show the actual error
    if (isset($_GET['debug']) || ($_SERVER['SERVER_NAME'] ?? '') === 'localhost') {
        exit('Error loading file: ' . $e->getMessage());
    } else {
        exit('Error loading file');
    }
}
?>