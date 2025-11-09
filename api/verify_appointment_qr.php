<?php
// Set the root path for proper includes
$root_path = dirname(dirname(__FILE__));

// Include session configuration for employee session
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

header('Content-Type: application/json');

// Check if user is logged in using employee session functions
if (!is_employee_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access - please log in'
    ]);
    exit;
}

try {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'verify_qr_token':
            error_log("QR Token Verification Request: appointment_id={$_POST['appointment_id']}, qr_token={$_POST['qr_token']}");
            verifyQRToken();
            break;
            
        case 'verify_verification_code':
            error_log("Verification Code Request: appointment_id={$_POST['appointment_id']}, verification_code={$_POST['verification_code']}");
            verifyVerificationCode();
            break;
            
        case 'verify_patient_details':
            error_log("Patient Details Verification Request: appointment_id={$_POST['appointment_id']}, patient_name={$_POST['patient_name']}");
            verifyPatientDetails();
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    error_log('QR Verification Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

function verifyQRToken() {
    global $pdo;
    
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    $qr_token = $_POST['qr_token'] ?? '';
    
    if (!$appointment_id || !$qr_token) {
        throw new Exception('Missing appointment ID or QR token');
    }
    
    // Get the appointment with QR token verification
    $stmt = $pdo->prepare("
        SELECT a.*, p.first_name, p.middle_name, p.last_name, p.contact_number,
               a.qr_code_path, a.verification_code
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        WHERE a.appointment_id = ? 
        AND a.status = 'confirmed'
    ");
    
    $stmt->execute([$appointment_id]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$appointment) {
        throw new Exception('Appointment not found or not confirmed');
    }
    
    // Verify the QR token
    $token_valid = false;
    
    // Check if it matches the stored verification code
    if ($appointment['verification_code'] && $appointment['verification_code'] === $qr_token) {
        $token_valid = true;
    }
    
    if (!$token_valid) {
        // Log suspicious activity
        error_log("Invalid QR token attempt for appointment {$appointment_id}: provided='{$qr_token}', stored='{$appointment['verification_code']}'");
        throw new Exception('Invalid or expired QR code - token does not match');
    }
    
    // Update last verification time for security tracking
    $update_stmt = $pdo->prepare("
        UPDATE appointments 
        SET last_qr_verification = NOW() 
        WHERE appointment_id = ?
    ");
    $update_stmt->execute([$appointment_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'QR token verified successfully',
        'appointment' => [
            'appointment_id' => $appointment['appointment_id'],
            'patient_name' => trim($appointment['first_name'] . ' ' . $appointment['middle_name'] . ' ' . $appointment['last_name']),
            'verification_method' => 'qr_scan'
        ]
    ]);
}

function verifyVerificationCode() {
    global $pdo;
    
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    $verification_code = trim($_POST['verification_code'] ?? '');
    
    if (!$appointment_id || !$verification_code) {
        throw new Exception('Missing appointment ID or verification code');
    }
    
    // Get the appointment with verification code check
    $stmt = $pdo->prepare("
        SELECT a.*, p.first_name, p.middle_name, p.last_name, p.contact_number
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        WHERE a.appointment_id = ? 
        AND a.status = 'confirmed'
        AND a.verification_code = ?
    ");
    
    $stmt->execute([$appointment_id, $verification_code]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$appointment) {
        // Log verification attempt for security
        error_log("Invalid verification code attempt for appointment {$appointment_id}: provided='{$verification_code}'");
        throw new Exception('Invalid verification code - code does not match appointment records');
    }
    
    // Log successful verification code verification
    $employee_id = get_employee_session('employee_id');
    error_log("Successful verification code verification for appointment {$appointment_id} by employee {$employee_id}");
    
    // Update verification tracking
    $update_stmt = $pdo->prepare("
        UPDATE appointments 
        SET last_manual_verification = NOW(),
            manual_verification_by = ?
        WHERE appointment_id = ?
    ");
    $update_stmt->execute([$employee_id, $appointment_id]);
    
    // Construct full name
    $full_name = trim($appointment['first_name'] . ' ' . $appointment['middle_name'] . ' ' . $appointment['last_name']);
    
    echo json_encode([
        'success' => true,
        'message' => 'Verification code validated successfully',
        'appointment' => [
            'appointment_id' => $appointment['appointment_id'],
            'patient_name' => $full_name,
            'verification_method' => 'verification_code'
        ]
    ]);
}

function verifyPatientDetails() {
    global $pdo;
    
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    $patient_name = trim($_POST['patient_name'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    
    if (!$appointment_id || !$patient_name || !$contact_number) {
        throw new Exception('Missing required verification details');
    }
    
    // Get the appointment with patient details
    $stmt = $pdo->prepare("
        SELECT a.*, p.first_name, p.middle_name, p.last_name, p.contact_number
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        WHERE a.appointment_id = ? 
        AND a.status = 'confirmed'
    ");
    
    $stmt->execute([$appointment_id]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$appointment) {
        throw new Exception('Appointment not found');
    }
    
    // Construct full name for comparison
    $full_name = trim($appointment['first_name'] . ' ' . $appointment['middle_name'] . ' ' . $appointment['last_name']);
    $stored_phone = preg_replace('/\D/', '', $appointment['contact_number'] ?? '');
    $provided_phone = preg_replace('/\D/', '', $contact_number);
    
    // Normalize names for comparison (remove extra spaces, case insensitive)
    $normalized_stored_name = preg_replace('/\s+/', ' ', strtolower(trim($full_name)));
    $normalized_provided_name = preg_replace('/\s+/', ' ', strtolower(trim($patient_name)));
    
    // Check name match (exact or close match)
    $name_match = false;
    if ($normalized_stored_name === $normalized_provided_name) {
        $name_match = true;
    } else {
        // Allow for minor variations (e.g., missing middle name)
        $stored_parts = explode(' ', $normalized_stored_name);
        $provided_parts = explode(' ', $normalized_provided_name);
        
        // Check if first and last names match
        if (count($stored_parts) >= 2 && count($provided_parts) >= 2) {
            $first_match = $stored_parts[0] === $provided_parts[0];
            $last_match = end($stored_parts) === end($provided_parts);
            $name_match = $first_match && $last_match;
        }
    }
    
    // Check phone number match (last 7 digits should match)
    $phone_match = false;
    if (strlen($stored_phone) >= 7 && strlen($provided_phone) >= 7) {
        $stored_last7 = substr($stored_phone, -7);
        $provided_last7 = substr($provided_phone, -7);
        $phone_match = $stored_last7 === $provided_last7;
    }
    
    if (!$name_match && !$phone_match) {
        // Log verification attempt for security
        error_log("Failed patient verification for appointment {$appointment_id}: Name '{$patient_name}' vs '{$full_name}', Phone '{$contact_number}' vs '{$appointment['contact_number']}'");
        throw new Exception('Patient name and contact number do not match our records');
    } else if (!$name_match) {
        throw new Exception('Patient name does not match our records');
    } else if (!$phone_match) {
        throw new Exception('Contact number does not match our records');
    }
    
    // Log successful manual verification
    $employee_id = get_employee_session('employee_id');
    error_log("Successful manual verification for appointment {$appointment_id} by employee {$employee_id}");
    
    // Update verification tracking
    $update_stmt = $pdo->prepare("
        UPDATE appointments 
        SET last_manual_verification = NOW(),
            manual_verification_by = ?
        WHERE appointment_id = ?
    ");
    $update_stmt->execute([$employee_id, $appointment_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Patient details verified successfully',
        'appointment' => [
            'appointment_id' => $appointment['appointment_id'],
            'patient_name' => $full_name,
            'verification_method' => 'manual_verification'
        ]
    ]);
}
?>