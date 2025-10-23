<?php
// get_referral_details.php - API to fetch complete referral details for patients
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: application/json');

// Include patient session configuration - Use absolute path resolution
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/patient_session.php';

// If patient is not logged in, return error
if (!isset($_SESSION['patient_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// Database connection
require_once $root_path . '/config/db.php';

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$patient_id = $_SESSION['patient_id'];

// Validate referral_id parameter (match admin API exactly)
if (!isset($_GET['referral_id']) || !is_numeric($_GET['referral_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid or missing referral ID']);
    exit();
}

$referral_id = intval($_GET['referral_id']);

try {
    // Fetch complete referral details - ONLY for the logged-in patient
    $stmt = $conn->prepare("
        SELECT r.referral_id, r.referral_num, r.patient_id, r.referral_reason, 
               r.destination_type, r.referred_to_facility_id, r.external_facility_name,
               r.referral_date, r.status, r.referred_by, r.service_id, r.validity_date,
               p.first_name, p.middle_name, p.last_name, p.username as patient_number,
               p.date_of_birth, p.sex, p.contact_number,
               b.barangay_name as barangay,
               e.first_name as issuer_first_name, e.last_name as issuer_last_name, 
               rol.role_name as issuer_position,
               f.name as facility_name, f.type as facility_type,
               s.name as service_name, s.description as service_description
        FROM referrals r
        LEFT JOIN patients p ON r.patient_id = p.patient_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN employees e ON r.referred_by = e.employee_id
        LEFT JOIN roles rol ON e.role_id = rol.role_id
        LEFT JOIN facilities f ON r.referred_to_facility_id = f.facility_id
        LEFT JOIN services s ON r.service_id = s.service_id
        WHERE r.referral_id = ? AND r.patient_id = ?
    ");

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database prepare failed: ' . $conn->error]);
        exit();
    }

    $stmt->bind_param("ii", $referral_id, $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'error' => 'Referral not found or access denied']);
        exit();
    }

    $referral = $result->fetch_assoc();
    $stmt->close();

    // Get latest vitals for this patient
    $vitals = null;
    if ($referral['patient_id']) {
        $vitals_stmt = $conn->prepare("
            SELECT systolic_bp, diastolic_bp, heart_rate, respiratory_rate, 
                   temperature, weight, height, recorded_at, remarks,
                   CONCAT(systolic_bp, '/', diastolic_bp) as blood_pressure
            FROM vitals 
            WHERE patient_id = ? 
            ORDER BY recorded_at DESC 
            LIMIT 1
        ");
        
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

    // Calculate age if date of birth is available
    if ($referral['date_of_birth']) {
        $dob = new DateTime($referral['date_of_birth']);
        $now = new DateTime();
        $age = $dob->diff($now)->y;
        $referral['age'] = $age;
    }

    // Format patient name
    $patient_name = trim($referral['first_name'] . ' ' . ($referral['middle_name'] ? $referral['middle_name'] . ' ' : '') . $referral['last_name']);
    $referral['patient_name'] = $patient_name;

    // Format issuer name
    $issuer_name = trim($referral['issuer_first_name'] . ' ' . $referral['issuer_last_name']);
    $referral['issuer_name'] = $issuer_name;

    // Determine destination
    if ($referral['destination_type'] === 'external') {
        $referral['destination'] = $referral['external_facility_name'] ?: 'External Facility';
    } else {
        $referral['destination'] = $referral['facility_name'] ?: 'Internal Facility';
    }

    // Check if referral is expired (48 hours rule)
    $referral_date = new DateTime($referral['referral_date']);
    $now = new DateTime();
    $hours_diff = $now->diff($referral_date)->h + ($now->diff($referral_date)->days * 24);
    $referral['is_expired'] = ($hours_diff > 48 && in_array($referral['status'], ['active', 'pending']));

    // Add vitals to response
    $referral['vitals'] = $vitals;

    echo json_encode([
        'success' => true,
        'referral' => $referral
    ]);

} catch (Exception $e) {
    error_log("Patient Referral Details Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to fetch referral details: ' . $e->getMessage()
    ]);
} catch (Error $e) {
    error_log("Patient Referral Details Fatal Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fatal error occurred: ' . $e->getMessage()
    ]);
}
?>