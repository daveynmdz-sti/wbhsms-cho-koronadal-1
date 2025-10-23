<?php
/**
 * Download Patient Referral History API
 * Generates CSV/PDF export of patient's referral history
 */

// Include patient session configuration
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$patient_id = $_SESSION['patient_id'];
$format = $_GET['format'] ?? 'csv'; // Default to CSV

try {
    // Get patient information
    $patient_stmt = $conn->prepare("
        SELECT p.first_name, p.middle_name, p.last_name, p.username as patient_number,
               b.barangay_name
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.patient_id = ?
    ");
    
    if (!$patient_stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database prepare failed: ' . $conn->error]);
        exit();
    }
    
    $patient_stmt->bind_param("i", $patient_id);
    $patient_stmt->execute();
    $patient_result = $patient_stmt->get_result();
    $patient_info = $patient_result->fetch_assoc();
    $patient_stmt->close();

    if (!$patient_info) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Patient not found']);
        exit();
    }

    $patient_name = trim($patient_info['first_name'] . ' ' . 
                        ($patient_info['middle_name'] ? $patient_info['middle_name'] . ' ' : '') . 
                        $patient_info['last_name']);

    // Fetch all referrals for the patient
    $referrals_stmt = $conn->prepare("
        SELECT r.referral_num, r.referral_reason, r.destination_type,
               r.referred_to_facility_id, r.external_facility_name,
               r.referral_date, r.status, r.validity_date,
               e.first_name as doctor_first_name, e.last_name as doctor_last_name,
               f.name as facility_name, f.type as facility_type,
               s.name as service_name
        FROM referrals r
        LEFT JOIN employees e ON r.referred_by = e.employee_id
        LEFT JOIN facilities f ON r.referred_to_facility_id = f.facility_id
        LEFT JOIN services s ON r.service_id = s.service_id
        WHERE r.patient_id = ?
        ORDER BY r.referral_date DESC
    ");
    
    if (!$referrals_stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database prepare failed: ' . $conn->error]);
        exit();
    }
    
    $referrals_stmt->bind_param("i", $patient_id);
    $referrals_stmt->execute();
    $referrals_result = $referrals_stmt->get_result();
    $referrals = $referrals_result->fetch_all(MYSQLI_ASSOC);
    $referrals_stmt->close();

    if ($format === 'csv') {
        // Generate CSV
        $filename = 'referral_history_' . $patient_info['patient_number'] . '_' . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        $output = fopen('php://output', 'w');

        // Add header information
        fputcsv($output, ['Referral History Report']);
        fputcsv($output, ['Patient Name:', $patient_name]);
        fputcsv($output, ['Patient ID:', $patient_info['patient_number']]);
        fputcsv($output, ['Address:', $patient_info['barangay_name']]);
        fputcsv($output, ['Generated:', date('F d, Y g:i A')]);
        fputcsv($output, []); // Empty row

        // CSV headers
        fputcsv($output, [
            'Referral Number',
            'Date',
            'Status',
            'Facility',
            'Service',
            'Referring Doctor',
            'Reason',
            'Valid Until'
        ]);

        // Add referral data
        foreach ($referrals as $referral) {
            $facility = '';
            if ($referral['destination_type'] === 'external') {
                $facility = $referral['external_facility_name'] ?: 'External Facility';
            } else {
                $facility = $referral['facility_name'] . ' (' . $referral['facility_type'] . ')';
            }

            $doctor = '';
            if ($referral['doctor_first_name']) {
                $doctor = 'Dr. ' . $referral['doctor_first_name'] . ' ' . $referral['doctor_last_name'];
            }

            fputcsv($output, [
                $referral['referral_num'],
                date('M j, Y g:i A', strtotime($referral['referral_date'])),
                ucfirst($referral['status']),
                $facility,
                $referral['service_name'] ?: 'General Consultation',
                $doctor,
                $referral['referral_reason'],
                $referral['validity_date'] ? date('M j, Y', strtotime($referral['validity_date'])) : 'N/A'
            ]);
        }

        fclose($output);
    } else {
        // Invalid format
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid format specified']);
    }

} catch (Exception $e) {
    error_log("Download Referral History Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to generate referral history: ' . $e->getMessage()]);
}
?>