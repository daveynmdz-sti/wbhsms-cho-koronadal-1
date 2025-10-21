<?php
// Debug version of get_lab_order_details.php
$root_path = dirname(dirname(dirname(__DIR__)));

// Include patient session configuration FIRST
require_once $root_path . '/config/session/patient_session.php';

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access', 'debug' => 'No patient_id in session']);
    exit;
}

// Include database connection
require_once $root_path . '/config/db.php';

header('Content-Type: application/json');

// Get order ID from request
$order_id = $_GET['id'] ?? null; // Use direct access for debugging

// Debug information
$debug_info = [
    'order_id_received' => $order_id,
    'order_id_filtered' => filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT),
    'session_patient_id' => $_SESSION['patient_id'] ?? 'NOT SET',
    'session_patient_username' => $_SESSION['patient_username'] ?? 'NOT SET',
    'get_params' => $_GET
];

if (!$order_id || !is_numeric($order_id)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid order ID',
        'debug' => $debug_info
    ]);
    exit;
}

// Get patient information from session - patient_id is the numeric ID
$patient_id = $_SESSION['patient_id']; // This is the numeric patient ID from login
$patient_username = $_SESSION['patient_username'] ?? ''; // This is the username like "P000007"

// Validate that we have a valid numeric patient_id
if (!$patient_id || !is_numeric($patient_id)) {
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid session data',
        'debug' => $debug_info
    ]);
    exit;
}

try {
    // First, check if this order belongs to this patient
    $checkStmt = $conn->prepare("SELECT patient_id FROM lab_orders WHERE lab_order_id = ?");
    $checkStmt->bind_param("i", $order_id);
    $checkStmt->execute();
    $orderOwner = $checkStmt->get_result()->fetch_assoc();
    
    if (!$orderOwner) {
        echo json_encode([
            'success' => false, 
            'message' => 'Order not found',
            'debug' => array_merge($debug_info, [
                'order_exists' => false,
                'searched_order_id' => $order_id
            ])
        ]);
        exit;
    }
    
    if ($orderOwner['patient_id'] != $patient_id) {
        echo json_encode([
            'success' => false, 
            'message' => 'Order does not belong to current patient',
            'debug' => array_merge($debug_info, [
                'order_owner_patient_id' => $orderOwner['patient_id'],
                'current_patient_id' => $patient_id,
                'order_id' => $order_id
            ])
        ]);
        exit;
    }
    
    // If we get here, the order exists and belongs to the current patient
    echo json_encode([
        'success' => true, 
        'message' => 'Order found and belongs to current patient',
        'debug' => array_merge($debug_info, [
            'order_owner_patient_id' => $orderOwner['patient_id'],
            'current_patient_id' => $patient_id,
            'order_id' => $order_id
        ])
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Database error',
        'debug' => array_merge($debug_info, [
            'error' => $e->getMessage()
        ])
    ]);
}
?>