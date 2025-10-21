<?php
// Get the root path
$root_path = dirname(dirname(dirname(__DIR__)));

// Include patient session configuration FIRST
require_once $root_path . '/config/session/patient_session.php';

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    http_response_code(401);
    die('Unauthorized access');
}

// Include database connection
require_once $root_path . '/config/db.php';

// Get item ID from request (changed from file path to item ID)
$item_id = filter_input(INPUT_GET, 'item_id', FILTER_SANITIZE_NUMBER_INT);

if (!$item_id) {
    http_response_code(400);
    die('Invalid parameters - item ID required');
}

// Get patient information from session - patient_id is the numeric ID
$patient_id = $_SESSION['patient_id']; // This is the numeric patient ID from login
$patient_username = $_SESSION['patient_username'] ?? ''; // This is the username like "P000007"

// Validate that we have a valid numeric patient_id
if (!$patient_id || !is_numeric($patient_id)) {
    http_response_code(400);
    die('Invalid session data');
}

try {
    // Verify that this file belongs to this patient's lab result - using correct schema
    $stmt = $conn->prepare("
        SELECT loi.result_file, loi.test_type, loi.result_date
        FROM lab_order_items loi
        JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
        WHERE loi.item_id = ? 
        AND lo.patient_id = ?
        AND lo.status = 'completed'
        AND loi.result_file IS NOT NULL
    ");
    
    $stmt->bind_param("ii", $item_id, $patient_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    if (!$result || !$result['result_file']) {
        http_response_code(404);
        die('File not found or access denied');
    }
    
    // Get file data
    $fileData = $result['result_file'];
    $contentType = 'application/octet-stream'; // default
    $extension = 'bin'; // default
    
    // Check file signature to determine type (same as management side)
    if (substr($fileData, 0, 4) === '%PDF') {
        $contentType = 'application/pdf';
        $extension = 'pdf';
    } elseif (substr($fileData, 0, 2) === 'PK') {
        // ZIP-based formats (DOCX, XLSX, etc.)
        $contentType = 'application/zip';
        $extension = 'zip';
    } elseif (substr($fileData, 0, 8) === "\x89PNG\r\n\x1a\n") {
        $contentType = 'image/png';
        $extension = 'png';
    } elseif (substr($fileData, 0, 3) === "\xFF\xD8\xFF") {
        $contentType = 'image/jpeg';
        $extension = 'jpg';
    } elseif (substr($fileData, 0, 6) === 'GIF87a' || substr($fileData, 0, 6) === 'GIF89a') {
        $contentType = 'image/gif';
        $extension = 'gif';
    }
    
    // Generate download filename
    $test_type = preg_replace('/[^a-zA-Z0-9]/', '_', $result['test_type']);
    $result_date = date('Y-m-d', strtotime($result['result_date']));
    $download_filename = 'lab_result_' . $test_type . '_' . $result_date . '.' . $extension;
    
    // Set download headers
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . $download_filename . '"');
    header('Content-Length: ' . strlen($fileData));
    header('Cache-Control: private, must-revalidate');
    header('Pragma: private');
    header('Expires: 0');
    
    // Clear output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Output file data
    echo $fileData;
    
} catch (Exception $e) {
    error_log("Database error in download_lab_file.php: " . $e->getMessage());
    http_response_code(500);
    die('Database error occurred');
} catch (Exception $e) {
    error_log("Error in download_lab_file.php: " . $e->getMessage());
    http_response_code(500);
    die('An error occurred');
}

exit;
?>