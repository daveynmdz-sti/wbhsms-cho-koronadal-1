<?php
// get_referral_details.php - API to fetch complete referral details for view modal
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json');

// Include employee session configuration - Use absolute path resolution
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';

// If user is not logged in, return error
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

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

// Validate referral_id parameter
if (!isset($_GET['referral_id']) || !is_numeric($_GET['referral_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid or missing referral ID']);
    exit();
}

$referral_id = intval($_GET['referral_id']);

try {
    // Fetch complete referral details with patient and issuer information
    $sql = "
        SELECT r.referral_id, r.referral_num, r.patient_id, r.referral_reason, 
               r.destination_type, r.referred_to_facility_id, r.external_facility_name, 
               r.referral_date, r.status, r.referred_by, r.service_id,
               p.first_name, p.middle_name, p.last_name, p.username as patient_number, 
               p.date_of_birth, p.sex, p.contact_number,
               b.barangay_name as barangay,
               e.first_name as issuer_first_name, e.last_name as issuer_last_name,
               ro.role_name as issuer_position,
               f.name as referred_facility_name,
               s.name as service_name
        FROM referrals r
        LEFT JOIN patients p ON r.patient_id = p.patient_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN employees e ON r.referred_by = e.employee_id
        LEFT JOIN roles ro ON e.role_id = ro.role_id
        LEFT JOIN facilities f ON r.referred_to_facility_id = f.facility_id
        LEFT JOIN services s ON r.service_id = s.service_id
        WHERE r.referral_id = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'Failed to prepare statement: ' . $conn->error]);
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

    // Fetch patient vitals if available (most recent vitals)
    $vitals = null;
    if ($referral['patient_id']) {
        $vitals_sql = "
            SELECT systolic_bp, diastolic_bp, 
                   CONCAT(systolic_bp, '/', diastolic_bp) as blood_pressure,
                   heart_rate, respiratory_rate, temperature, 
                   weight, height, recorded_at, remarks
            FROM vitals 
            WHERE patient_id = ? 
            ORDER BY recorded_at DESC 
            LIMIT 1
        ";
        
        $vitals_stmt = $conn->prepare($vitals_sql);
        if ($vitals_stmt) {
            $vitals_stmt->bind_param("i", $referral['patient_id']);
            $vitals_stmt->execute();
            $vitals_result = $vitals_stmt->get_result();
            if ($vitals_result->num_rows > 0) {
                $vitals = $vitals_result->fetch_assoc();
            }
            $vitals_stmt->close();
        }
    }

    // Calculate patient age
    $age = 'N/A';
    if ($referral['date_of_birth']) {
        $dob = new DateTime($referral['date_of_birth']);
        $today = new DateTime();
        $years = $today->diff($dob)->y;
        $age = $years . ' years old';
    }

    // Format patient name
    $patient_name = trim($referral['first_name'] . ' ' . 
                        ($referral['middle_name'] ? $referral['middle_name'] . ' ' : '') . 
                        $referral['last_name']);

    // Format issuer name
    $issuer_name = trim(($referral['issuer_first_name'] ?? '') . ' ' . ($referral['issuer_last_name'] ?? ''));

    // Determine facility name based on destination type
    $facility_name = '';
    if ($referral['destination_type'] === 'external') {
        $facility_name = $referral['external_facility_name'] ?: 'External Facility';
    } else {
        $facility_name = $referral['referred_facility_name'] ?: 'Internal Facility';
    }

    // Prepare response data
    $referral_data = [
        'referral_id' => $referral['referral_id'],
        'referral_num' => $referral['referral_num'],
        'patient_name' => $patient_name,
        'patient_number' => $referral['patient_number'],
        'age' => $age,
        'gender' => $referral['sex'] ?? 'N/A',
        'barangay' => $referral['barangay'] ?? 'N/A',
        'contact_number' => $referral['contact_number'] ?? 'N/A',
        'referral_reason' => $referral['referral_reason'],
        'status' => $referral['status'],
        'facility_name' => $facility_name,
        'external_facility_name' => $referral['external_facility_name'],
        'referral_date' => $referral['referral_date'],
        'issuer_name' => $issuer_name,
        'issuer_position' => $referral['issuer_position'] ?? 'N/A',
        'service_name' => $referral['service_name'] ?? 'N/A',
        'vitals' => $vitals
    ];

    $response = [
        'success' => true,
        'referral' => $referral_data
    ];

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Referral Details API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
}
?>