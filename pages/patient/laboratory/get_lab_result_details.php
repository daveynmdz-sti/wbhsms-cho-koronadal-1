<?php
// Prevent direct access
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Get the root path
$root_path = dirname(dirname(dirname(__DIR__)));

// Include database connection
require_once $root_path . '/config/db.php';

header('Content-Type: application/json');

// Get result ID from request
$result_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$result_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid result ID']);
    exit;
}

$patient_id = $_SESSION['patient_id'];

try {
    // Fetch lab result details with correct schema and security check
    $stmt = $pdo->prepare("
        SELECT 
            lo.lab_order_id,
            lo.order_date,
            lo.status,
            lo.remarks,
            CONCAT(e.first_name, ' ', e.last_name) as doctor_name,
            c.consultation_date,
            a.appointment_date,
            -- Source information for standalone support
            CASE 
                WHEN lo.appointment_id IS NOT NULL THEN 'appointment'
                WHEN lo.consultation_id IS NOT NULL THEN 'consultation'
                ELSE 'standalone'
            END as order_source,
            -- Get test details and results from lab_order_items
            GROUP_CONCAT(loi.test_type SEPARATOR ', ') as test_types,
            COUNT(loi.item_id) as test_count,
            MAX(loi.result_date) as latest_result_date,
            COUNT(CASE WHEN loi.result_file IS NOT NULL THEN 1 END) as files_count
        FROM lab_orders lo
        LEFT JOIN consultations c ON lo.consultation_id = c.consultation_id
        LEFT JOIN appointments a ON lo.appointment_id = a.appointment_id
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE lo.lab_order_id = ? 
        AND lo.patient_id = ?
        AND lo.status = 'completed'
        GROUP BY lo.lab_order_id
    ");
    
    $stmt->execute([$result_id, $patient_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'Lab result not found']);
        exit;
    }
    
    echo json_encode([
        'success' => true, 
        'result' => $result
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in get_lab_result_details.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>