<?php
/**
 * Get facilities for appointment filtering based on patient's barangay assignment
 * Returns City Health Office, District Health Office, and Barangay Health Center for the patient
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Include patient session configuration
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/patient_session.php';

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    echo json_encode(['error' => 'Patient not logged in']);
    exit;
}

require_once $root_path . '/config/db.php';

try {
    $patient_id = $_SESSION['patient_id'];
    
    // Get patient's barangay and district information
    $stmt = $conn->prepare("
        SELECT p.barangay_id, b.barangay_name, b.district_id, d.district_name 
        FROM patients p 
        JOIN barangay b ON p.barangay_id = b.barangay_id 
        JOIN districts d ON b.district_id = d.district_id
        WHERE p.patient_id = ? AND p.status = 'active'
    ");
    $stmt->bind_param('i', $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $patient_data = $result->fetch_assoc();
    
    if (!$patient_data) {
        echo json_encode(['error' => 'Patient not found or inactive']);
        exit;
    }
    
    $patient_barangay_id = $patient_data['barangay_id'];
    $patient_district_id = $patient_data['district_id'];
    
    $facilities = [];
    
    // 1. Get City Health Office Main District
    $stmt = $conn->prepare("
        SELECT facility_id, name 
        FROM facilities 
        WHERE type = 'City Health Office' 
        AND is_main = 1 
        AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $city_office = $result->fetch_assoc();
    
    if ($city_office) {
        $facilities[] = [
            'facility_id' => $city_office['facility_id'],
            'name' => $city_office['name'],
            'type' => 'City Health Office Main District'
        ];
    }
    
    // 2. Get District Health Office for patient's district
    // Special case: Main District (district_id = 1) uses City Health Office as district office
    if ($patient_district_id == 1) {
        // For Main District, City Health Office serves as both city and district office
        if ($city_office) {
            $facilities[] = [
                'facility_id' => $city_office['facility_id'],
                'name' => $city_office['name'] . ' (District Office)',
                'type' => 'District Health Office'
            ];
        }
    } else {
        // For other districts, get the specific District Health Office
        $stmt = $conn->prepare("
            SELECT facility_id, name 
            FROM facilities 
            WHERE type = 'District Health Office' 
            AND district_id = ? 
            AND status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param('i', $patient_district_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $district_office = $result->fetch_assoc();
        
        if ($district_office) {
            $facilities[] = [
                'facility_id' => $district_office['facility_id'],
                'name' => $district_office['name'],
                'type' => 'District Health Office'
            ];
        }
    }
    
    // 3. Get Barangay Health Center for patient's barangay
    $stmt = $conn->prepare("
        SELECT facility_id, name 
        FROM facilities 
        WHERE type = 'Barangay Health Center' 
        AND barangay_id = ? 
        AND status = 'active'
        LIMIT 1
    ");
    $stmt->bind_param('i', $patient_barangay_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $barangay_center = $result->fetch_assoc();
    
    if ($barangay_center) {
        $facilities[] = [
            'facility_id' => $barangay_center['facility_id'],
            'name' => $barangay_center['name'],
            'type' => 'Barangay Health Center'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'patient_info' => [
            'barangay_id' => $patient_barangay_id,
            'barangay_name' => $patient_data['barangay_name'],
            'district_id' => $patient_district_id,
            'district_name' => $patient_data['district_name']
        ],
        'facilities' => $facilities
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>