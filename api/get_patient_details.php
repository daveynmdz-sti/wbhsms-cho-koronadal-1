<?php
// Include database connection and session management
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in
if (!is_employee_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Set content type to JSON
header('Content-Type: application/json');

try {
    // Get patient ID from request
    $patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
    
    if (!$patient_id) {
        throw new Exception('Patient ID is required');
    }
    
    // Get patient basic information including date_of_birth and sex
    $patient_query = "
        SELECT 
            p.patient_id,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.date_of_birth,
            p.sex,
            p.contact_number,
            b.barangay_name as barangay
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.patient_id = ? AND p.status = 'active'
    ";
    
    $patient_stmt = $conn->prepare($patient_query);
    $patient_stmt->bind_param("i", $patient_id);
    $patient_stmt->execute();
    $patient_result = $patient_stmt->get_result();
    
    if ($patient_result->num_rows === 0) {
        throw new Exception('Patient not found or inactive');
    }
    
    $patient = $patient_result->fetch_assoc();
    
    // Calculate age from date_of_birth
    $age = 'Unknown';
    if ($patient['date_of_birth']) {
        $birthDate = new DateTime($patient['date_of_birth']);
        $currentDate = new DateTime();
        $age = $currentDate->diff($birthDate)->y . ' years';
    }
    
    // Get most recent vitals (height and weight)
    $vitals_query = "
        SELECT 
            height,
            weight,
            recorded_at
        FROM vitals 
        WHERE patient_id = ? 
        AND (height IS NOT NULL OR weight IS NOT NULL)
        ORDER BY recorded_at DESC 
        LIMIT 1
    ";
    
    $vitals_stmt = $conn->prepare($vitals_query);
    $vitals_stmt->bind_param("i", $patient_id);
    $vitals_stmt->execute();
    $vitals_result = $vitals_stmt->get_result();
    
    $height = 'No data';
    $weight = 'No data';
    $vitals_date = null;
    
    if ($vitals_result->num_rows > 0) {
        $vitals = $vitals_result->fetch_assoc();
        
        if ($vitals['height']) {
            $height = $vitals['height'] . ' cm';
        }
        
        if ($vitals['weight']) {
            $weight = $vitals['weight'] . ' kg';
        }
        
        $vitals_date = $vitals['recorded_at'];
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'patient' => [
            'id' => $patient['patient_id'],
            'name' => trim($patient['first_name'] . ' ' . ($patient['middle_name'] ? $patient['middle_name'] . ' ' : '') . $patient['last_name']),
            'age' => $age,
            'sex' => ucfirst($patient['sex']),
            'height' => $height,
            'weight' => $weight,
            'contact' => $patient['contact_number'] ?? 'N/A',
            'barangay' => $patient['barangay'] ?? 'Not specified',
            'vitals_date' => $vitals_date
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>