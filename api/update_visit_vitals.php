<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set content type
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Start output buffering to catch any unexpected output
ob_start();

try {
    // Include configuration
    $root_path = dirname(__DIR__);
    require_once $root_path . '/config/db.php';
    require_once $root_path . '/config/session/employee_session.php';

    // Check if user is logged in
    if (!is_employee_logged_in()) {
        throw new Exception('Authentication required');
    }

    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method allowed');
    }

    // Get and validate input data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST; // Fallback to form data
    }

    // Validate required fields
    $required_fields = ['appointment_id', 'vitals_id'];
    foreach ($required_fields as $field) {
        if (empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    $appointment_id = intval($input['appointment_id']);
    $vitals_id = intval($input['vitals_id']);

    // Verify appointment exists and get visit_id
    $stmt = $pdo->prepare("
        SELECT v.visit_id, v.patient_id, a.status 
        FROM visits v
        INNER JOIN appointments a ON v.appointment_id = a.appointment_id
        WHERE a.appointment_id = ?
    ");
    $stmt->execute([$appointment_id]);
    $visit = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$visit) {
        throw new Exception('Visit record not found for this appointment');
    }

    // Verify vitals record exists
    $stmt = $pdo->prepare("SELECT vitals_id FROM vitals WHERE vitals_id = ?");
    $stmt->execute([$vitals_id]);
    if (!$stmt->fetch()) {
        throw new Exception('Vitals record not found');
    }

    // Update visit record with vitals_id
    $stmt = $pdo->prepare("UPDATE visits SET vitals_id = ? WHERE visit_id = ?");
    $success = $stmt->execute([$vitals_id, $visit['visit_id']]);

    if (!$success) {
        throw new Exception('Failed to update visit record with vitals');
    }

    // Clean output buffer and send response
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => 'Visit record updated with vitals successfully',
        'data' => [
            'visit_id' => $visit['visit_id'],
            'vitals_id' => $vitals_id,
            'appointment_id' => $appointment_id
        ]
    ]);

} catch (Exception $e) {
    // Clean output buffer and send error response
    ob_clean();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (Error $e) {
    // Clean output buffer and send error response for fatal errors
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'System error: ' . $e->getMessage()
    ]);
}
?>