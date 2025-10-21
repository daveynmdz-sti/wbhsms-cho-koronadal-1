<?php
// Include employee session configuration
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

// Check if user is logged in and has appropriate role
$authorizedRoleIds = [1, 2, 3, 9]; // admin, doctor, nurse, laboratory_tech
if (!isset($_SESSION['employee_id']) || !in_array($_SESSION['role_id'], $authorizedRoleIds)) {
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
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    
    if (!$data || !$data['result_file']) {
        http_response_code(404);
        exit('File not found');
    }
    
    // Log the access for audit trail
    $audit_sql = "INSERT INTO lab_result_view_logs (lab_item_id, employee_id, viewed_at, patient_name, ip_address, user_agent) 
                  VALUES (?, ?, NOW(), ?, ?, ?)";
    $audit_stmt = $conn->prepare($audit_sql);
    $patient_name = trim($data['first_name'] . ' ' . $data['last_name']);
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $audit_stmt->bind_param("iisss", $item_id, $_SESSION['employee_id'], $patient_name, $ip_address, $user_agent);
    
    // Execute audit log (don't fail if audit logging fails)
    try {
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
    error_log("PDF viewing error: " . $e->getMessage());
    http_response_code(500);
    exit('Error loading file');
}
?>