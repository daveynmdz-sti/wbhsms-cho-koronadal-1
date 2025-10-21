<?php
// Prevent direct access
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    header('Location: ../auth/patient_login.php');
    exit();
}

// Get the root path
$root_path = dirname(dirname(dirname(__DIR__)));

// Include database connection
require_once $root_path . '/config/db.php';

// Get patient information from session - patient_id is the numeric ID
$patient_id = $_SESSION['patient_id']; // This is the numeric patient ID from login
$patient_username = $_SESSION['patient_username'] ?? ''; // This is the username like "P000007"

// Validate that we have a valid numeric patient_id
if (!$patient_id || !is_numeric($patient_id)) {
    die('Invalid session data');
}

try {
    // Fetch all lab orders and results for this patient using correct schema
    $stmt = $conn->prepare("
        SELECT 
            lo.lab_order_id,
            lo.order_date,
            lo.status,
            lo.remarks,
            CONCAT(e.first_name, ' ', e.last_name) as doctor_name,
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            GROUP_CONCAT(DISTINCT loi.test_type SEPARATOR ', ') as test_types,
            MAX(loi.result_date) as latest_result_date,
            COUNT(loi.item_id) as test_count
        FROM lab_orders lo
        LEFT JOIN consultations c ON lo.consultation_id = c.consultation_id
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        LEFT JOIN patients p ON lo.patient_id = p.patient_id
        LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE lo.patient_id = ?
        GROUP BY lo.lab_order_id
        ORDER BY lo.order_date DESC
    ");
    
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $lab_history = $result->fetch_all(MYSQLI_ASSOC);
    
    if (empty($lab_history)) {
        die('No lab history found');
    }
    
    // Get patient info
    $patient_stmt = $conn->prepare("
        SELECT first_name, last_name, date_of_birth, sex as gender, contact_number as phone_number 
        FROM patients 
        WHERE patient_id = ?
    ");
    $patient_stmt->bind_param("i", $patient_id);
    $patient_stmt->execute();
    $patient_result = $patient_stmt->get_result();
    $patient_info = $patient_result->fetch_assoc();
    $patient_stmt->close();
    
} catch (Exception $e) {
    error_log("Database error in download_lab_history.php: " . $e->getMessage());
    die('Database error occurred');
}

// Set headers for CSV download
$filename = 'lab_history_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for proper Excel encoding
fputs($output, "\xEF\xBB\xBF");

// CSV Headers
fputcsv($output, [
    'Patient Name',
    'Date of Birth',
    'Gender',
    'Phone Number',
    'Generated Date'
]);

// Patient info row
fputcsv($output, [
    $patient_info['first_name'] . ' ' . $patient_info['last_name'],
    date('F j, Y', strtotime($patient_info['date_of_birth'])),
    ucfirst($patient_info['gender']),
    $patient_info['phone_number'] ?: 'N/A',
    date('F j, Y g:i A')
]);

// Empty row
fputcsv($output, []);

// Lab history headers
fputcsv($output, [
    'Order ID',
    'Test Types',
    'Test Count',
    'Order Date',
    'Latest Result Date',
    'Status',
    'Ordered By',
    'Remarks'
]);

// Lab history data
foreach ($lab_history as $record) {
    fputcsv($output, [
        $record['lab_order_id'],
        $record['test_types'] ?: 'No tests specified',
        $record['test_count'] ?: '0',
        date('F j, Y', strtotime($record['order_date'])),
        $record['latest_result_date'] ? date('F j, Y', strtotime($record['latest_result_date'])) : 'Pending',
        ucfirst(str_replace('_', ' ', $record['status'])),
        $record['doctor_name'] ? 'Dr. ' . $record['doctor_name'] : 'Lab Direct',
        $record['remarks'] ?: 'N/A'
    ]);
}

fclose($output);
exit;
?>