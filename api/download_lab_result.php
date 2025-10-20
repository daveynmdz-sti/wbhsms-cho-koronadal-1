<?php
// Download Lab Result API
// Include employee session configuration
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    http_response_code(401);
    echo "Unauthorized access";
    exit();
}

// Check permissions - only admin and laboratory_tech can download
$canDownload = in_array($_SESSION['role_id'], [1, 9]); // admin, laboratory_tech

if (!$canDownload) {
    http_response_code(403);
    echo "Access denied. Insufficient permissions.";
    exit();
}

// Get item ID from request
$item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;

if (!$item_id) {
    http_response_code(400);
    echo "Invalid item ID";
    exit();
}

try {
    // Fetch the lab result file with patient and test information
    $sql = "SELECT loi.result_file, loi.test_type, loi.result_text,
                   p.first_name, p.last_name, p.username as patient_id_display,
                   lo.order_date
            FROM lab_order_items loi
            JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
            JOIN patients p ON lo.patient_id = p.patient_id
            WHERE loi.item_id = ? AND loi.result_file IS NOT NULL";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $item_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        http_response_code(404);
        echo "Lab result file not found";
        exit();
    }
    
    $row = $result->fetch_assoc();
    $fileData = $row['result_file'];
    $testType = $row['test_type'];
    $patientName = $row['first_name'] . ' ' . $row['last_name'];
    $patientId = $row['patient_id_display'];
    $orderDate = date('Y-m-d', strtotime($row['order_date']));
    
    // Detect file type from binary data
    $fileType = 'application/octet-stream';
    $extension = 'bin';
    
    // Check file signature (magic numbers)
    $header = substr($fileData, 0, 10);
    
    if (substr($header, 0, 4) == '%PDF') {
        $fileType = 'application/pdf';
        $extension = 'pdf';
    } elseif (substr($header, 0, 2) == 'PK') {
        // Could be XLSX, DOCX, etc.
        $fileType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $extension = 'xlsx';
    } elseif (strpos($header, 'ÿØÿ') !== false) {
        $fileType = 'image/jpeg';
        $extension = 'jpg';
    } else {
        // Default to text/csv for unknown files
        $fileType = 'text/csv';
        $extension = 'csv';
    }
    
    // Create meaningful filename
    $filename = sprintf(
        "%s_%s_%s_Result.%s", 
        str_replace(' ', '_', $patientName),
        $patientId,
        str_replace(' ', '_', $testType),
        $extension
    );
    
    // Set headers for file download
    header('Content-Type: ' . $fileType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($fileData));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    
    // Output the file data
    echo $fileData;
    
    // Log the download action
    $logSql = "INSERT INTO audit_logs (employee_id, action, table_name, record_id, details, created_at) 
               VALUES (?, 'download_lab_result', 'lab_order_items', ?, ?, NOW())";
    $logStmt = $conn->prepare($logSql);
    $details = "Downloaded lab result file for item ID: $item_id, Patient: $patientName";
    $logStmt->bind_param("iis", $_SESSION['employee_id'], $item_id, $details);
    $logStmt->execute();
    
} catch (Exception $e) {
    error_log("Download error: " . $e->getMessage());
    http_response_code(500);
    echo "Error downloading file";
}
?>