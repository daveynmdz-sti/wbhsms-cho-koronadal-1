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
header('Access-Control-Allow-Methods: POST');
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

    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method allowed');
    }

    // Get employee info
    $employee_id = get_employee_session('employee_id');
    if (!$employee_id) {
        throw new Exception('Invalid session - employee ID not found');
    }

    // Get and validate input data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST; // Fallback to form data
    }

    // Validate required fields
    $required_fields = ['patient_id'];
    foreach ($required_fields as $field) {
        if (empty($input[$field])) {
            throw new Exception("Missing required field: $field");
        }
    }

    $patient_id = intval($input['patient_id']);
    $appointment_id = !empty($input['appointment_id']) ? intval($input['appointment_id']) : null;
    
    // Validate vital signs data
    $systolic_bp = !empty($input['systolic_bp']) ? intval($input['systolic_bp']) : null;
    $diastolic_bp = !empty($input['diastolic_bp']) ? intval($input['diastolic_bp']) : null;
    $heart_rate = !empty($input['heart_rate']) ? intval($input['heart_rate']) : null;
    $respiratory_rate = !empty($input['respiratory_rate']) ? intval($input['respiratory_rate']) : null;
    $temperature = !empty($input['temperature']) ? floatval($input['temperature']) : null;
    $weight = !empty($input['weight']) ? floatval($input['weight']) : null;
    $height = !empty($input['height']) ? floatval($input['height']) : null;
    $remarks = !empty($input['remarks']) ? trim($input['remarks']) : null;

    // Calculate BMI if both weight and height are provided
    $bmi = null;
    if ($weight && $height) {
        $height_meters = $height / 100; // Convert cm to meters
        $bmi = round($weight / ($height_meters * $height_meters), 2);
    }

    // Validate vital signs ranges
    if ($systolic_bp && ($systolic_bp < 50 || $systolic_bp > 300)) {
        throw new Exception('Systolic BP must be between 50-300 mmHg');
    }
    if ($diastolic_bp && ($diastolic_bp < 30 || $diastolic_bp > 200)) {
        throw new Exception('Diastolic BP must be between 30-200 mmHg');
    }
    if ($heart_rate && ($heart_rate < 30 || $heart_rate > 250)) {
        throw new Exception('Heart rate must be between 30-250 bpm');
    }
    if ($respiratory_rate && ($respiratory_rate < 5 || $respiratory_rate > 60)) {
        throw new Exception('Respiratory rate must be between 5-60 breaths/min');
    }
    if ($temperature && ($temperature < 30 || $temperature > 45)) {
        throw new Exception('Temperature must be between 30-45°C');
    }
    if ($weight && ($weight < 1 || $weight > 500)) {
        throw new Exception('Weight must be between 1-500 kg');
    }
    if ($height && ($height < 30 || $height > 250)) {
        throw new Exception('Height must be between 30-250 cm');
    }

    // Check if we're updating existing vitals or creating new ones
    $vitals_id = !empty($input['vitals_id']) ? intval($input['vitals_id']) : null;
    $is_update = $vitals_id ? true : false;

    // Verify patient exists
    $stmt = $pdo->prepare("SELECT patient_id, first_name, last_name FROM patients WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        throw new Exception('Patient not found');
    }

    if ($is_update) {
        // Verify vitals record exists and belongs to this patient
        $stmt = $pdo->prepare("SELECT vitals_id FROM vitals WHERE vitals_id = ? AND patient_id = ?");
        $stmt->execute([$vitals_id, $patient_id]);
        if (!$stmt->fetch()) {
            throw new Exception('Vitals record not found or does not belong to this patient');
        }

        // Update existing vitals record
        $sql = "UPDATE vitals SET 
                systolic_bp = ?, diastolic_bp = ?, heart_rate = ?, respiratory_rate = ?, 
                temperature = ?, weight = ?, height = ?, bmi = ?, remarks = ?, updated_at = NOW()
                WHERE vitals_id = ? AND patient_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([
            $systolic_bp, $diastolic_bp, $heart_rate, $respiratory_rate,
            $temperature, $weight, $height, $bmi, $remarks, $vitals_id, $patient_id
        ]);

        if (!$success) {
            throw new Exception('Failed to update vitals record');
        }

        $action = 'updated';
    } else {
        // Insert new vitals record
        $sql = "INSERT INTO vitals (
            patient_id, systolic_bp, diastolic_bp, heart_rate, respiratory_rate, 
            temperature, weight, height, bmi, recorded_by, remarks
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([
            $patient_id, $systolic_bp, $diastolic_bp, $heart_rate, $respiratory_rate,
            $temperature, $weight, $height, $bmi, $employee_id, $remarks
        ]);

        if (!$success) {
            throw new Exception('Failed to save vitals record');
        }

        $vitals_id = $pdo->lastInsertId();
        $action = 'recorded';

        // If appointment_id is provided, update the visit record with the vitals_id
        if ($appointment_id) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE visits 
                    SET vitals_id = ? 
                    WHERE appointment_id = ? AND patient_id = ?
                ");
                $stmt->execute([$vitals_id, $appointment_id, $patient_id]);
            } catch (Exception $e) {
                // Log the error but don't fail the vitals creation
                if (getenv('APP_DEBUG') === '1') {
                    error_log("Failed to update visit with vitals_id: " . $e->getMessage());
                }
            }
        }
    }

    // Clean output buffer and send response
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => "Vitals {$action} successfully",
        'data' => [
            'vitals_id' => $vitals_id,
            'patient_name' => $patient['first_name'] . ' ' . $patient['last_name'],
            'recorded_at' => date('Y-m-d H:i:s'),
            'bmi' => $bmi,
            'action' => $action
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