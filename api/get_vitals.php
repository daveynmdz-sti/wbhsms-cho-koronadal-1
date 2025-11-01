<?php
// Production-ready error handling
$root_path = dirname(__DIR__);
require_once $root_path . '/config/env.php';
require_once $root_path . '/config/production_security.php';

if (getenv('APP_DEBUG') === '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// Set content type
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Start output buffering to catch any unexpected output
ob_start();

try {
    // Include configuration
    require_once $root_path . '/config/db.php';
    require_once $root_path . '/config/session/employee_session.php';

    // Check if PDO is available
    if (!isset($pdo)) {
        throw new Exception('PDO connection not available');
    }

    // Check if user is logged in
    if (!is_employee_logged_in()) {
        throw new Exception('Authentication required');
    }

    // Only allow GET requests
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Only GET method allowed');
    }

    // Get vitals ID
    $vitals_id = isset($_GET['vitals_id']) ? intval($_GET['vitals_id']) : 0;

    if (!$vitals_id) {
        throw new Exception('Vitals ID is required');
    }

    // Fetch vitals data with related information
    $stmt = $pdo->prepare("
        SELECT v.*, 
               p.first_name, p.last_name, p.username as patient_id_display,
               CONCAT(e.first_name, ' ', e.last_name) as recorded_by_name
        FROM vitals v
        LEFT JOIN patients p ON v.patient_id = p.patient_id  
        LEFT JOIN employees e ON v.recorded_by = e.employee_id
        WHERE v.vitals_id = ?
    ");
    
    $stmt->execute([$vitals_id]);
    $vitals = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vitals) {
        throw new Exception('Vitals record not found');
    }

    // Clean output buffer and send response
    if (ob_get_level()) {
        ob_clean();
    }
    echo json_encode([
        'success' => true,
        'vitals' => $vitals
    ]);

} catch (Exception $e) {
    // Clean output buffer and send error response
    if (ob_get_level()) {
        ob_clean();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (Error $e) {
    // Clean output buffer and send error response for fatal errors
    if (ob_get_level()) {
        ob_clean();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'System error: ' . $e->getMessage()
    ]);
}
?>