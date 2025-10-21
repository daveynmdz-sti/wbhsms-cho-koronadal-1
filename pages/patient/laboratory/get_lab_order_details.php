<?php
// Get the root path
$root_path = dirname(dirname(dirname(__DIR__)));

// Include patient session configuration FIRST
require_once $root_path . '/config/session/patient_session.php';

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Include database connection
require_once $root_path . '/config/db.php';

header('Content-Type: application/json');

// Get order ID from request
$order_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$order_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

$patient_username = $_SESSION['patient_id']; // This is actually the username like "P000007"

// Get the actual numeric patient_id from the username
$patient_id = null;
try {
    $patientStmt = $conn->prepare("SELECT patient_id FROM patients WHERE username = ?");
    $patientStmt->bind_param("s", $patient_username);
    $patientStmt->execute();
    $patientResult = $patientStmt->get_result()->fetch_assoc();
    if (!$patientResult) {
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
        exit;
    }
    $patient_id = $patientResult['patient_id'];
    $patientStmt->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

try {
    // Fetch lab order details with correct schema and security check
    $stmt = $conn->prepare("
        SELECT 
            lo.lab_order_id,
            lo.order_date,
            lo.status,
            lo.remarks,
            lo.appointment_id,
            lo.consultation_id,
            lo.visit_id,
            CONCAT(e.first_name, ' ', e.last_name) as doctor_name,
            c.consultation_date,
            a.scheduled_date as appointment_date,
            a.scheduled_time as appointment_time,
            -- Source information for standalone support
            CASE 
                WHEN lo.appointment_id IS NOT NULL THEN 'appointment'
                WHEN lo.consultation_id IS NOT NULL THEN 'consultation'
                ELSE 'standalone'
            END as order_source,
            -- Get test details from lab_order_items
            GROUP_CONCAT(loi.test_type SEPARATOR ', ') as test_types,
            COUNT(loi.item_id) as test_count,
            COUNT(CASE WHEN loi.status = 'completed' THEN 1 END) as completed_tests,
            COUNT(CASE WHEN loi.status = 'pending' THEN 1 END) as pending_tests,
            COUNT(CASE WHEN loi.status = 'in_progress' THEN 1 END) as in_progress_tests
        FROM lab_orders lo
        LEFT JOIN consultations c ON lo.consultation_id = c.consultation_id
        LEFT JOIN appointments a ON lo.appointment_id = a.appointment_id
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE lo.lab_order_id = ? 
        AND lo.patient_id = ?
        GROUP BY lo.lab_order_id
    ");
    
    $stmt->bind_param("ii", $order_id, $patient_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    
    if (!$order) {
        echo json_encode(['success' => false, 'message' => 'Lab order not found']);
        exit;
    }
    
    // Fetch individual test items for detailed view
    $itemsStmt = $conn->prepare("
        SELECT 
            loi.item_id as lab_order_item_id,
            loi.test_type,
            loi.status,
            loi.result_file,
            loi.result_date,
            loi.remarks,
            loi.created_at,
            loi.updated_at
        FROM lab_order_items loi
        WHERE loi.lab_order_id = ?
        ORDER BY loi.created_at ASC
    ");
    
    $itemsStmt->bind_param("i", $order_id);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();
    $items = $itemsResult->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'success' => true, 
        'order' => $order,
        'items' => $items
    ]);
    
} catch (Exception $e) {
    error_log("Database error in get_lab_order_details.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>