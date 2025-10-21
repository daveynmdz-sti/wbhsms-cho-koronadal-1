<?php
// Set error reporting for production
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Disabled for production security
ini_set('log_errors', '1'); // Always log errors for debugging

// Include patient session configuration FIRST
$root_path = dirname(dirname(dirname(__DIR__)));

try {
    require_once $root_path . '/config/session/patient_session.php';
} catch (Exception $e) {
    error_log("Failed to include session: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Session configuration failed: ' . $e->getMessage()]);
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['patient_id'])) {
    error_log("get_prescription_details.php: Unauthorized access attempt");
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Database connection
try {
    require_once $root_path . '/config/db.php';
} catch (Exception $e) {
    error_log("Failed to include database config: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database configuration failed: ' . $e->getMessage()]);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

// Check if database connection exists
if (!isset($conn)) {
    error_log("Database connection not available");
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    exit();
}

$patient_id = $_SESSION['patient_id'];
$prescription_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

error_log("get_prescription_details.php: Request for prescription ID: $prescription_id, patient ID: $patient_id");

if (!$prescription_id) {
    error_log("get_prescription_details.php: Invalid prescription ID");
    echo json_encode(['success' => false, 'message' => 'Invalid prescription ID']);
    exit();
}

try {
    // Fetch prescription details - standalone (no appointment/visit dependency)
    $stmt = $conn->prepare("
        SELECT p.prescription_id,
               p.patient_id,
               p.consultation_id,
               p.appointment_id,
               p.visit_id,
               p.prescription_date,
               p.status,
               p.remarks,
               p.created_at,
               p.updated_at,
               CONCAT(e.first_name, ' ', e.last_name) as doctor_name,
               e.license_number,
               e.role_id,
               CASE 
                   WHEN p.appointment_id IS NOT NULL THEN a.scheduled_date
                   ELSE NULL
               END as appointment_date,
               CASE 
                   WHEN p.appointment_id IS NOT NULL THEN a.scheduled_time
                   ELSE NULL
               END as appointment_time,
               a.status as appointment_status,
               CASE 
                   WHEN p.consultation_id IS NOT NULL THEN c.consultation_date
                   ELSE NULL
               END as consultation_date,
               c.chief_complaint, 
               c.diagnosis,
               c.consultation_status,
               -- Source information for display
               CASE 
                   WHEN p.consultation_id IS NOT NULL THEN 'consultation'
                   WHEN p.appointment_id IS NOT NULL THEN 'appointment'
                   ELSE 'standalone'
               END as prescription_source
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
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => "Prescription #$prescription_id not found or you don't have permission to access it."]);
        exit();
    }

    error_log("get_prescription_details.php: Prescription found, fetching medications");

    // Fetch prescribed medications with explicit column selection for clarity
    $stmt = $conn->prepare("
        SELECT prescribed_medication_id,
               prescription_id,
               medication_name,
               dosage,
               frequency,
               duration,
               instructions,
               status,
               created_at,
               updated_at
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