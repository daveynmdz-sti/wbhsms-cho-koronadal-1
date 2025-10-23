<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

try {
    // Get root path
    $root_path = dirname(dirname(__DIR__));
    
    // Include database connection
    require_once $root_path . '/config/db.php';
    
    // Include session management for authentication
    require_once $root_path . '/config/session/patient_session.php';
    
    // Check if user is logged in (either patient or employee)
    $is_patient_logged_in = is_patient_logged_in();
    $is_employee_logged_in = false;
    
    // Check for employee session if patient is not logged in
    if (!$is_patient_logged_in) {
        require_once $root_path . '/config/session/employee_session.php';
        $is_employee_logged_in = is_employee_logged_in();
    }
    
    if (!$is_patient_logged_in && !$is_employee_logged_in) {
        throw new Exception('Authentication required');
    }
    
    // Get lab order item ID from query parameter
    $lab_order_item_id = $_GET['lab_order_item_id'] ?? null;
    
    if (!$lab_order_item_id) {
        throw new Exception('Lab order item ID is required');
    }
    
    // Prepare query to get lab result details with correct table relationships
    $stmt = $pdo->prepare("
        SELECT 
            loi.item_id as lab_order_item_id,
            loi.lab_order_id,
            lo.patient_id,
            loi.test_type as test_name,
            loi.test_type,
            'blood' as sample_type,
            '' as normal_range,
            '' as result_value,
            '' as result_unit,
            loi.status as result_status,
            loi.result_date,
            loi.remarks,
            loi.created_at,
            loi.updated_at,
            lo.order_date,
            lo.status as order_status,
            lo.overall_status,
            p.first_name as patient_first_name,
            p.last_name as patient_last_name,
            p.patient_id as patient_number,
            e.first_name as ordered_by_first_name,
            e.last_name as ordered_by_last_name,
            e.position as ordered_by_position
        FROM lab_order_items loi
        INNER JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
        LEFT JOIN patients p ON lo.patient_id = p.patient_id
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        WHERE loi.item_id = ?
    ");
    
    $stmt->execute([$lab_order_item_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        throw new Exception('Lab result not found');
    }
    
    // Check authorization - patients can only view their own results
    if ($is_patient_logged_in) {
        $session_patient_id = get_patient_session('patient_id');
        if ($result['patient_id'] != $session_patient_id) {
            throw new Exception('Access denied - you can only view your own lab results');
        }
    }
    // Employees can view all results (already authenticated above)
    
    echo json_encode([
        'success' => true,
        'result' => $result
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    error_log("Lab result API error: " . $e->getMessage());
}
?>