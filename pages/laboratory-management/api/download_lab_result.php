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
    // Fetch the file data from database with patient info for better filename
    $sql = "SELECT loi.result_file, loi.test_type, loi.result_date,
                   p.first_name, p.last_name, p.username as patient_id_display
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
    
    // Determine content type based on file content
    $fileData = $data['result_file'];
    $contentType = 'application/octet-stream'; // default
    $extension = 'bin'; // default
    
    // Check file signature to determine type
    if (substr($fileData, 0, 4) === '%PDF') {
        $contentType = 'application/pdf';
        $extension = 'pdf';
    } elseif (substr($fileData, 0, 2) === 'PK') {
        // Excel files start with PK (ZIP signature)
        $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $extension = 'xlsx';
    } elseif (strpos($fileData, ',') !== false || ctype_print(substr($fileData, 0, 100))) {
        // CSV files are typically printable text with commas
        $contentType = 'text/csv';
        $extension = 'csv';
    }
    
    // Generate meaningful filename with patient info
    $patientName = '';
    if ($data['first_name'] && $data['last_name']) {
        $patientName = trim($data['first_name'] . '_' . $data['last_name']);
        $patientName = preg_replace('/[^a-zA-Z0-9_-]/', '', $patientName);
        $patientName = '_' . $patientName;
    }
    $sanitizedTestType = preg_replace('/[^a-zA-Z0-9_-]/', '_', $data['test_type']);
    $date = $data['result_date'] ? date('Y-m-d', strtotime($data['result_date'])) : date('Y-m-d');
    $filename = "Lab_Result{$patientName}_{$sanitizedTestType}_{$date}_Item{$item_id}.{$extension}";
    
    // Set headers for file download
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($fileData));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output the file data
    echo $fileData;
    
} catch (Exception $e) {
    error_log("File download error: " . $e->getMessage());
    http_response_code(500);
    exit('Error downloading file');
}
?>
    $authSql = "SELECT COUNT(*) as count 
                FROM lab_order_items loi
                LEFT JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
                WHERE loi.result_file = ? AND lo.patient_id = ?";
    
    $authStmt = $conn->prepare($authSql);
    $authStmt->bind_param("si", $filename, $patient_id);
    $authStmt->execute();
    $authResult = $authStmt->get_result();
    $authData = $authResult->fetch_assoc();
    
    if ($authData['count'] == 0) {
        http_response_code(403);
        exit('Access denied to this file');
    }
} else {
    // For healthcare staff, verify the file exists in the database
    $authSql = "SELECT COUNT(*) as count FROM lab_order_items WHERE result_file = ?";
    $authStmt = $conn->prepare($authSql);
    $authStmt->bind_param("s", $filename);
    $authStmt->execute();
    $authResult = $authStmt->get_result();
    $authData = $authResult->fetch_assoc();
    
    if ($authData['count'] == 0) {
        http_response_code(404);
        exit('File not found in database');
    }
}

// Get file info
$fileSize = filesize($filePath);
$displayName = 'lab_result_' . date('Y-m-d') . '.pdf';

// Set appropriate headers for PDF download
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $displayName . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: private, must-revalidate');
header('Pragma: private');
header('Expires: 0');

// Prevent any output before file content
ob_clean();
flush();

// Output file content
readfile($filePath);
exit();