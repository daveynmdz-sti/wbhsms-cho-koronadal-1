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

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Start output buffering to catch any unexpected output
ob_start();

// Include session and database
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in and authorized
if (!is_employee_logged_in()) {
    if (ob_get_level()) { ob_clean(); }
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$employee_id = get_employee_session('employee_id');
$role = get_employee_session('role');

if (!$employee_id || !$role) {
    if (ob_get_level()) { ob_clean(); }
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session data incomplete']);
    exit();
}

$authorized_roles = ['admin', 'dho', 'bhw', 'doctor', 'nurse', 'records_officer'];
if (!in_array(strtolower($role), $authorized_roles)) {
    if (ob_get_level()) { ob_clean(); }
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    if (ob_get_level()) { ob_clean(); }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$appointment_id = $_GET['appointment_id'] ?? '';

if (empty($appointment_id) || !is_numeric($appointment_id)) {
    if (ob_get_level()) { ob_clean(); }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid appointment ID']);
    exit();
}

try {
    // Add debug logging
    error_log("Fetching appointment details for ID: " . $appointment_id);
    
    $sql = "
        SELECT a.appointment_id, a.scheduled_date, a.scheduled_time, a.status, a.service_id,
               a.cancellation_reason, a.created_at, a.updated_at, a.qr_code_path,
               p.first_name, p.last_name, p.middle_name, p.username as patient_id,
               p.contact_number, p.date_of_birth, p.sex,
               f.name as facility_name, f.district as facility_district,
               b.barangay_name,
               s.name as service_name,
               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.patient_id
        LEFT JOIN facilities f ON a.facility_id = f.facility_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN services s ON a.service_id = s.service_id
        WHERE a.appointment_id = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Failed to prepare main query: " . $conn->error);
    }
    
    $stmt->bind_param("i", $appointment_id);
    if (!$stmt->execute()) {
        throw new Exception("Failed to execute main query: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $appointment = $result->fetch_assoc();
    $stmt->close();

    if (!$appointment) {
        if (ob_get_level()) { ob_clean(); }
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        exit();
    }

    error_log("Found appointment with status: " . ($appointment['status'] ?? 'null'));

    // Format the data with safe null handling
    $appointment['patient_name'] = trim(($appointment['last_name'] ?? '') . ', ' . ($appointment['first_name'] ?? '') . ' ' . ($appointment['middle_name'] ?? ''));
    $appointment['appointment_date'] = $appointment['scheduled_date'] ? date('F j, Y', strtotime($appointment['scheduled_date'])) : 'N/A';
    $appointment['time_slot'] = $appointment['scheduled_time'] ? date('g:i A', strtotime($appointment['scheduled_time'])) : 'N/A';
    $appointment['status'] = ucfirst($appointment['status'] ?? 'unknown');
    
    // Ensure service_name is available, provide fallback
    if (empty($appointment['service_name'])) {
        $appointment['service_name'] = 'General Consultation';
    }
    
    // Handle QR code blob data - convert to base64 if it exists
    if (!empty($appointment['qr_code_path'])) {
        try {
            // Check if QR code data is too large (more than 1MB)
            $qr_size = strlen($appointment['qr_code_path']);
            error_log("QR code size for appointment ID " . $appointment_id . ": " . $qr_size . " bytes");
            
            if ($qr_size > 1048576) { // 1MB limit
                error_log("QR code too large for appointment ID: " . $appointment_id . " (" . $qr_size . " bytes)");
                $appointment['qr_code_path'] = null;
            } else {
                // If it's binary data, encode it to base64
                $qr_base64 = base64_encode($appointment['qr_code_path']);
                if ($qr_base64 === false) {
                    error_log("Failed to encode QR code data for appointment ID: " . $appointment_id);
                    $appointment['qr_code_path'] = null;
                } else {
                    $appointment['qr_code_path'] = $qr_base64;
                    error_log("Successfully encoded QR code for appointment ID: " . $appointment_id . " (base64 size: " . strlen($qr_base64) . " chars)");
                }
            }
        } catch (Exception $qr_error) {
            error_log("Error processing QR code for appointment ID " . $appointment_id . ": " . $qr_error->getMessage());
            $appointment['qr_code_path'] = null;
        }
    } else {
        $appointment['qr_code_path'] = null;
    }
    
    // Format cancellation details if applicable
    if (!empty($appointment['cancellation_reason'])) {
        $appointment['cancel_reason'] = $appointment['cancellation_reason'];
        $appointment['cancelled_at'] = $appointment['updated_at'] ? date('M j, Y g:i A', strtotime($appointment['updated_at'])) : 'N/A';
    }

    // Fetch visit details if appointment is completed or cancelled
    $status_lower = strtolower($appointment['status'] ?? '');
    error_log("Status for visit check: '$status_lower'");
    
    if ($status_lower === 'completed' || $status_lower === 'cancelled') {
        error_log("Fetching visit details for appointment ID: " . $appointment_id);
        
        // First check if visits table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'visits'");
        if ($table_check->num_rows === 0) {
            error_log("ERROR: visits table does not exist");
            $appointment['visit_details'] = null;
            $appointment['vitals'] = null;
        } else {
            // Get visit details
            $visit_sql = "
                SELECT v.visit_id, v.time_in, v.time_out, v.attendance_status, v.remarks,
                       e.first_name as attending_first_name, e.last_name as attending_last_name
                FROM visits v
                LEFT JOIN employees e ON v.attending_employee_id = e.employee_id
                WHERE v.appointment_id = ?
                ORDER BY v.visit_id DESC
                LIMIT 1
            ";
            
            $visit_stmt = $conn->prepare($visit_sql);
            if (!$visit_stmt) {
                throw new Exception("Failed to prepare visit query: " . $conn->error);
            }
            
            $visit_stmt->bind_param("i", $appointment_id);
            if (!$visit_stmt->execute()) {
                throw new Exception("Failed to execute visit query: " . $visit_stmt->error);
            }
            
            $visit_result = $visit_stmt->get_result();
            $visit_data = $visit_result->fetch_assoc();
            $visit_stmt->close();
            
            error_log("Visit details found: " . ($visit_data ? "Yes (visit_id: " . $visit_data['visit_id'] . ")" : "No"));
            
            if ($visit_data) {
                $appointment['visit_details'] = [
                    'time_in' => $visit_data['time_in'] ? date('M j, Y g:i A', strtotime($visit_data['time_in'])) : 'N/A',
                    'time_out' => $visit_data['time_out'] ? date('M j, Y g:i A', strtotime($visit_data['time_out'])) : null,
                    'attendance_status' => $visit_data['attendance_status'],
                    'remarks' => $visit_data['remarks'],
                    'attending_employee_name' => $visit_data['attending_first_name'] && $visit_data['attending_last_name'] 
                        ? trim($visit_data['attending_first_name'] . ' ' . $visit_data['attending_last_name'])
                        : 'N/A'
                ];
                
                // Get vitals data if visit exists
                $vitals_table_check = $conn->query("SHOW TABLES LIKE 'vitals'");
                if ($vitals_table_check->num_rows === 0) {
                    error_log("ERROR: vitals table does not exist");
                    $appointment['vitals'] = null;
                } else {
                    $vitals_sql = "
                        SELECT vt.systolic_bp, vt.diastolic_bp, vt.heart_rate, vt.respiratory_rate, 
                               vt.temperature, vt.weight, vt.height, vt.remarks, vt.recorded_at
                        FROM vitals vt
                        JOIN visits v ON vt.vitals_id = v.vitals_id
                        WHERE v.appointment_id = ?
                        ORDER BY vt.recorded_at DESC
                        LIMIT 1
                    ";
                    
                    $vitals_stmt = $conn->prepare($vitals_sql);
                    if (!$vitals_stmt) {
                        throw new Exception("Failed to prepare vitals query: " . $conn->error);
                    }
                    
                    $vitals_stmt->bind_param("i", $appointment_id);
                    if (!$vitals_stmt->execute()) {
                        throw new Exception("Failed to execute vitals query: " . $vitals_stmt->error);
                    }
                    
                    $vitals_result = $vitals_stmt->get_result();
                    $vitals_data = $vitals_result->fetch_assoc();
                    $vitals_stmt->close();
                    
                    error_log("Vitals found: " . ($vitals_data ? "Yes" : "No"));
                    
                    if ($vitals_data) {
                        $appointment['vitals'] = [
                            'systolic_bp' => $vitals_data['systolic_bp'],
                            'diastolic_bp' => $vitals_data['diastolic_bp'],
                            'heart_rate' => $vitals_data['heart_rate'],
                            'respiratory_rate' => $vitals_data['respiratory_rate'],
                            'temperature' => $vitals_data['temperature'],
                            'weight' => $vitals_data['weight'],
                            'height' => $vitals_data['height'],
                            'remarks' => $vitals_data['remarks'],
                            'recorded_at' => $vitals_data['recorded_at'] ? date('M j, Y g:i A', strtotime($vitals_data['recorded_at'])) : 'N/A'
                        ];
                    } else {
                        $appointment['vitals'] = null;
                    }
                }
            } else {
                $appointment['visit_details'] = null;
                $appointment['vitals'] = null;
            }
        }
    } else {
        error_log("Status doesn't match completed/cancelled, skipping visit details");
        $appointment['visit_details'] = null;
        $appointment['vitals'] = null;
    }

    // Clean output buffer and send success response
    if (ob_get_level()) { ob_clean(); }
    
    // Debug: Log the final appointment data structure (but remove large QR data for log)
    $debug_appointment = $appointment;
    if (!empty($debug_appointment['qr_code_path'])) {
        $debug_appointment['qr_code_path'] = '[QR_DATA_' . strlen($appointment['qr_code_path']) . '_BYTES]';
    }
    error_log("Final appointment data structure: " . json_encode($debug_appointment, JSON_PRETTY_PRINT));
    
    // Test JSON encoding before sending
    $json_response = json_encode(['success' => true, 'appointment' => $appointment]);
    if ($json_response === false) {
        $json_error = json_last_error_msg();
        error_log("JSON encoding failed: " . $json_error);
        
        // Remove QR code and try again
        $appointment['qr_code_path'] = null;
        $json_response = json_encode(['success' => true, 'appointment' => $appointment]);
        
        if ($json_response === false) {
            // Still failing, send error
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'JSON encoding error: ' . $json_error]);
            exit();
        }
    }
    
    echo $json_response;

} catch (Exception $e) {
    // Clean output buffer and send error response
    if (ob_get_level()) { ob_clean(); }
    http_response_code(500);
    
    // Log detailed error for debugging
    error_log("Database error in get_appointment_details.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    if (getenv('APP_DEBUG') === '1') {
        echo json_encode([
            'success' => false, 
            'message' => 'Database error: ' . $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Internal server error']);
    }
} catch (Error $e) {
    // Clean output buffer and send error response for fatal errors
    if (ob_get_level()) { ob_clean(); }
    http_response_code(500);
    
    // Log detailed error for debugging
    error_log("Fatal error in get_appointment_details.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    if (getenv('APP_DEBUG') === '1') {
        echo json_encode([
            'success' => false, 
            'message' => 'Fatal error: ' . $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'System error occurred']);
    }
}
?>
