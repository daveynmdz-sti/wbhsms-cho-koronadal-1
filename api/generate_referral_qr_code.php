<?php
// generate_referral_qr_code.php - Generate and return QR code for referral
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json');

// Include employee session configuration
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// Check if role is authorized
$authorized_roles = ['doctor', 'nurse', 'bhw', 'dho', 'records_officer', 'admin'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit();
}

// Database connection
require_once $root_path . '/config/db.php';

// Validate referral_id parameter
if (!isset($_GET['referral_id']) || !is_numeric($_GET['referral_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid or missing referral ID']);
    exit();
}

$referral_id = intval($_GET['referral_id']);

try {
    // Fetch referral details
    $stmt = $conn->prepare("
        SELECT r.referral_id, r.patient_id, r.destination_type, r.scheduled_date, 
               r.scheduled_time, r.assigned_doctor_id, r.referred_to_facility_id,
               r.qr_code_path, r.verification_code,
               p.first_name, p.last_name, p.patient_number,
               f.name as facility_name
        FROM referrals r
        LEFT JOIN patients p ON r.patient_id = p.patient_id 
        LEFT JOIN facilities f ON r.referred_to_facility_id = f.facility_id
        WHERE r.referral_id = ?
    ");
    
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Database query preparation failed']);
        exit();
    }
    
    $stmt->bind_param("i", $referral_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Referral not found']);
        exit();
    }
    
    $referral = $result->fetch_assoc();
    $stmt->close();
    
    // Check if QR code already exists
    if (!empty($referral['qr_code_path'])) {
        // Return existing QR code
        $qr_code_url = 'data:image/png;base64,' . base64_encode($referral['qr_code_path']);
        
        echo json_encode([
            'success' => true,
            'qr_code_url' => $qr_code_url,
            'verification_code' => $referral['verification_code'],
            'referral_info' => [
                'referral_id' => $referral['referral_id'],
                'patient_name' => $referral['first_name'] . ' ' . $referral['last_name'],
                'facility_name' => $referral['facility_name'] ?: 'External Facility',
                'scheduled_date' => $referral['scheduled_date'],
                'scheduled_time' => $referral['scheduled_time']
            ]
        ]);
        exit();
    }
    
    // Generate new QR code
    require_once $root_path . '/utils/qr_code_generator.php';
    
    $qr_data = [
        'patient_id' => $referral['patient_id'],
        'destination_type' => $referral['destination_type'],
        'scheduled_date' => $referral['scheduled_date'],
        'scheduled_time' => $referral['scheduled_time'],
        'assigned_doctor_id' => $referral['assigned_doctor_id'],
        'referred_to_facility_id' => $referral['referred_to_facility_id']
    ];
    
    $qr_result = QRCodeGenerator::generateAndSaveReferralQR($referral_id, $qr_data, $conn);
    
    if (!$qr_result['success']) {
        echo json_encode([
            'success' => false, 
            'error' => 'Failed to generate QR code: ' . $qr_result['error']
        ]);
        exit();
    }
    
    // Return the generated QR code
    $qr_code_url = 'data:image/png;base64,' . base64_encode($qr_result['qr_image_data']);
    
    echo json_encode([
        'success' => true,
        'qr_code_url' => $qr_code_url,
        'verification_code' => $qr_result['verification_code'],
        'qr_size' => $qr_result['qr_size'],
        'referral_info' => [
            'referral_id' => $referral['referral_id'],
            'patient_name' => $referral['first_name'] . ' ' . $referral['last_name'],
            'facility_name' => $referral['facility_name'] ?: 'External Facility',
            'scheduled_date' => $referral['scheduled_date'],
            'scheduled_time' => $referral['scheduled_time']
        ],
        'message' => 'QR code generated successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Referral QR Code API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while generating QR code'
    ]);
}
?>