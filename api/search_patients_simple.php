<?php
// Search Patients API - Simplified for Billing
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Root path for includes
$root_path = dirname(__DIR__);

// Include database connection
require_once $root_path . '/config/db.php';

// Set content type
header('Content-Type: application/json');

// Check if query parameter is provided
if (!isset($_GET['query']) || empty(trim($_GET['query']))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Search query is required']);
    exit();
}

$query = trim($_GET['query']);

try {
    // Search patients by ID, name, or barangay using correct table structure
    $search_sql = "
        SELECT 
            p.patient_id,
            p.username,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.date_of_birth,
            p.sex as gender,
            p.contact_number as phone_number,
            p.email,
            b.barangay_name as barangay,
            d.district_name as district,
            pi.street as purok_sitio
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN districts d ON b.district_id = d.district_id
        LEFT JOIN personal_information pi ON p.patient_id = pi.patient_id
        WHERE p.status = 'active' 
        AND (
            p.patient_id LIKE ? OR
            p.username LIKE ? OR
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) LIKE ? OR
            p.first_name LIKE ? OR
            p.last_name LIKE ? OR
            b.barangay_name LIKE ?
        )
        ORDER BY p.last_name, p.first_name
        LIMIT 20
    ";
    
    $search_pattern = "%$query%";
    $stmt = $pdo->prepare($search_sql);
    $stmt->execute([
        $search_pattern, // patient_id
        $search_pattern, // username
        $search_pattern, // full name
        $search_pattern, // first_name
        $search_pattern, // last_name
        $search_pattern  // barangay_name
    ]);
    
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response
    $formatted_patients = [];
    foreach ($patients as $patient) {
        $full_name = trim($patient['first_name'] . ' ' . 
                    ($patient['middle_name'] ? $patient['middle_name'] . ' ' : '') . 
                    $patient['last_name']);
        
        $address = '';
        if ($patient['purok_sitio']) {
            $address .= $patient['purok_sitio'] . ', ';
        }
        if ($patient['barangay']) {
            $address .= $patient['barangay'];
        }
        if ($patient['district']) {
            $address .= ', ' . $patient['district'];
        }
        
        $formatted_patients[] = [
            'patient_id' => $patient['patient_id'],
            'full_name' => $full_name,
            'first_name' => $patient['first_name'],
            'middle_name' => $patient['middle_name'],
            'last_name' => $patient['last_name'],
            'date_of_birth' => $patient['date_of_birth'],
            'age' => $patient['date_of_birth'] ? floor((time() - strtotime($patient['date_of_birth'])) / (365.25 * 24 * 3600)) : null,
            'gender' => $patient['gender'],
            'phone_number' => $patient['phone_number'],
            'email' => $patient['email'],
            'address' => trim($address),
            'barangay' => $patient['barangay']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'patients' => $formatted_patients,
        'count' => count($formatted_patients),
        'query' => $query
    ]);
    
} catch (PDOException $e) {
    error_log("Patient Search Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Database error occurred while searching patients'
    ]);
} catch (Exception $e) {
    error_log("Patient Search Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'An unexpected error occurred'
    ]);
}
?>