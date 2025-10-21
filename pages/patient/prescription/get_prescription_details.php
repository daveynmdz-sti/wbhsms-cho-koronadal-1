<?php
// Set error reporting for debugging
error_reporting(E_ALL);
ini_set('log_errors', '1');

// Include patient session configuration FIRST
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';

// Check if user is logged in
if (!isset($_SESSION['patient_id'])) {
    error_log("get_prescription_details.php: Unauthorized access attempt");
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Database connection
require_once $root_path . '/config/db.php';

// Set content type to JSON
header('Content-Type: application/json');

$patient_id = $_SESSION['patient_id'];
$prescription_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

error_log("get_prescription_details.php: Request for prescription ID: $prescription_id, patient ID: $patient_id");

if (!$prescription_id) {
    error_log("get_prescription_details.php: Invalid prescription ID");
    echo json_encode(['success' => false, 'message' => 'Invalid prescription ID']);
    exit();
}

try {
    // Fetch prescription details
    $stmt = $conn->prepare("
        SELECT p.*,
               CONCAT(e.first_name, ' ', e.last_name) as doctor_name,
               e.position, e.license_number,
               a.appointment_date, a.appointment_time,
               c.consultation_id, c.consultation_date, c.chief_complaint, c.diagnosis
        FROM prescriptions p
        LEFT JOIN employees e ON p.prescribed_by_employee_id = e.employee_id
        LEFT JOIN appointments a ON p.appointment_id = a.appointment_id
        LEFT JOIN consultations c ON p.consultation_id = c.consultation_id
        WHERE p.prescription_id = ? AND p.patient_id = ?
    ");
    $stmt->bind_param("ii", $prescription_id, $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $prescription = $result->fetch_assoc();
    $stmt->close();

    if (!$prescription) {
        error_log("get_prescription_details.php: Prescription not found for ID: $prescription_id, patient: $patient_id");
        echo json_encode(['success' => false, 'message' => 'Prescription not found']);
        exit();
    }

    error_log("get_prescription_details.php: Prescription found, fetching medications");

    // Fetch prescribed medications
    $stmt = $conn->prepare("
        SELECT *
        FROM prescribed_medications
        WHERE prescription_id = ?
        ORDER BY created_at ASC
    ");
    $stmt->bind_param("i", $prescription_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $medications = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    error_log("get_prescription_details.php: Found " . count($medications) . " medications");

    echo json_encode([
        'success' => true,
        'prescription' => $prescription,
        'medications' => $medications
    ]);

} catch (Exception $e) {
    error_log("get_prescription_details.php: Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>