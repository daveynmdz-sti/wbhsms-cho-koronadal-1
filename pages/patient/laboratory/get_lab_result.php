<?php
// Include patient session configuration
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    http_response_code(401);
    exit('Unauthorized: Please log in to access lab results.');
}

// Database connection
require_once $root_path . '/config/db.php';

$patient_id = $_SESSION['patient_id'];
$item_id = $_GET['item_id'] ?? null;
$action = $_GET['action'] ?? 'view';

if (!$item_id || !is_numeric($item_id)) {
    http_response_code(400);
    exit('Valid lab result item ID is required');
}

if (!$patient_id || !is_numeric($patient_id)) {
    http_response_code(401);
    exit('Invalid patient session');
}

try {
    // Use PDO for better error handling and consistency
    if (!isset($pdo)) {
        throw new Exception("Database connection not available");
    }
    
    // Fetch the lab result with patient verification
    $sql = "SELECT loi.result_file, loi.test_type, loi.result_date,
                   p.first_name, p.last_name, p.username as patient_id_display,
                   lo.lab_order_id
            FROM lab_order_items loi
            INNER JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id  
            INNER JOIN patients p ON lo.patient_id = p.patient_id
            WHERE loi.item_id = :item_id AND lo.patient_id = :patient_id AND loi.result_file IS NOT NULL";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':item_id', $item_id, PDO::PARAM_INT);
    $stmt->bindParam(':patient_id', $patient_id, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$data || !$data['result_file']) {
        http_response_code(404);
        exit('Lab result not found or access denied');
    }
    
    // Log the access for audit trail (create table if needed)
    try {
        // Create audit table if it doesn't exist (patient-specific)
        $create_audit_sql = "CREATE TABLE IF NOT EXISTS `lab_result_patient_view_logs` (
            `log_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
            `lab_item_id` int UNSIGNED NOT NULL,
            `patient_id` int UNSIGNED NOT NULL,
            `patient_name` varchar(255) DEFAULT NULL,
            `viewed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text DEFAULT NULL,
            PRIMARY KEY (`log_id`),
            UNIQUE KEY `unique_view_log` (`lab_item_id`, `patient_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($create_audit_sql);
        
        // Insert audit log
        $audit_sql = "INSERT INTO lab_result_patient_view_logs (lab_item_id, patient_id, viewed_at, patient_name, ip_address, user_agent) 
                      VALUES (:item_id, :patient_id, NOW(), :patient_name, :ip_address, :user_agent)
                      ON DUPLICATE KEY UPDATE viewed_at = NOW()";
        $audit_stmt = $pdo->prepare($audit_sql);
        $patient_name = trim($data['first_name'] . ' ' . $data['last_name']);
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $audit_stmt->bindParam(':item_id', $item_id, PDO::PARAM_INT);
        $audit_stmt->bindParam(':patient_id', $patient_id, PDO::PARAM_INT);
        $audit_stmt->bindParam(':patient_name', $patient_name, PDO::PARAM_STR);
        $audit_stmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
        $audit_stmt->bindParam(':user_agent', $user_agent, PDO::PARAM_STR);
        $audit_stmt->execute();
    } catch (Exception $audit_error) {
        error_log("Audit logging failed: " . $audit_error->getMessage());
    }
    
    // Determine content type based on file content
    $fileData = $data['result_file'];
    $contentType = 'application/octet-stream'; // default
    
    // Check file signature to determine type
    if (substr($fileData, 0, 4) === '%PDF') {
        $contentType = 'application/pdf';
    } elseif (substr($fileData, 0, 2) === 'PK') {
        // Excel files start with PK (ZIP signature)
        $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    } elseif (strpos($fileData, ',') !== false || ctype_print(substr($fileData, 0, 100))) {
        // CSV files are typically printable text with commas
        $contentType = 'text/csv';
    }
    
    // For PDF viewer, we want inline display
    if ($contentType === 'application/pdf' && $action === 'view') {
        // Set headers for inline PDF viewing
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Lab_Result_' . urlencode($data['test_type']) . '_Item' . $item_id . '.pdf"');
        header('Content-Length: ' . strlen($fileData));
        header('Cache-Control: private, max-age=300'); // Cache for 5 minutes
        header('Pragma: private');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN'); // Security header
        
        // Output the PDF data for inline viewing
        echo $fileData;
    } else {
        // For non-PDF files or download action, redirect to download endpoint
        header("Location: download_lab_result.php?item_id=" . urlencode($item_id));
        exit();
    }
    
} catch (Exception $e) {
    error_log("Patient PDF viewing error: " . $e->getMessage());
    http_response_code(500);
    exit('Error loading lab result');
}
?>