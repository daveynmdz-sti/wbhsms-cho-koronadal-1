<?php
// get_patient_prescriptions.php - Get prescription history for a specific patient
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/session/employee_session.php';

// Check if user is logged in
if (!is_employee_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

try {
    // Get patient ID from request
    $patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
    
    if ($patient_id <= 0) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid patient ID'
        ]);
        exit;
    }
    
    // Query to get patient prescription history with medications
    $sql = "
        SELECT 
            p.prescription_id,
            p.patient_id,
            p.consultation_id,
            p.appointment_id,
            p.visit_id,
            p.prescribed_by_employee_id,
            p.prescription_date,
            p.status,
            p.overall_status,
            p.remarks,
            p.created_at,
            p.updated_at,
            CONCAT(pat.first_name, ' ', pat.last_name) AS patient_name,
            pat.date_of_birth,
            pat.contact_number,
            CONCAT(e.first_name, ' ', e.last_name) AS prescribed_by_doctor,
            r.role_name AS doctor_position,
            f.name AS facility_name,
            f.type AS facility_type,
            b.barangay_name,
            -- Aggregate medications for this prescription
            GROUP_CONCAT(
                CONCAT(
                    pm.medication_name, '|',
                    pm.dosage, '|',
                    IFNULL(pm.frequency, 'N/A'), '|',
                    IFNULL(pm.duration, 'N/A'), '|',
                    IFNULL(pm.instructions, 'N/A'), '|',
                    pm.status
                ) SEPARATOR '||'
            ) AS medications_data,
            COUNT(pm.prescribed_medication_id) AS medication_count
        FROM prescriptions p
        LEFT JOIN patients pat ON p.patient_id = pat.patient_id
        LEFT JOIN employees e ON p.prescribed_by_employee_id = e.employee_id
        LEFT JOIN roles r ON e.role_id = r.role_id
        LEFT JOIN facilities f ON e.facility_id = f.facility_id
        LEFT JOIN barangay b ON pat.barangay_id = b.barangay_id
        LEFT JOIN prescribed_medications pm ON p.prescription_id = pm.prescription_id
        WHERE p.patient_id = ?
        GROUP BY p.prescription_id
        ORDER BY p.prescription_date DESC, p.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$patient_id]);
    $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process the aggregated medications data
    foreach ($prescriptions as &$prescription) {
        $medications = [];
        
        if (!empty($prescription['medications_data'])) {
            $medication_entries = explode('||', $prescription['medications_data']);
            
            foreach ($medication_entries as $med_entry) {
                if (trim($med_entry) === '') continue; // Skip empty entries
                
                $med_parts = explode('|', $med_entry);
                
                // Accept entries with exactly 6 parts
                if (count($med_parts) == 6) {
                    $medications[] = [
                        'medication_name' => trim($med_parts[0]),
                        'dosage' => trim($med_parts[1]),
                        'frequency' => trim($med_parts[2]) === 'N/A' ? '' : trim($med_parts[2]),
                        'duration' => trim($med_parts[3]) === 'N/A' ? '' : trim($med_parts[3]),
                        'instructions' => trim($med_parts[4]) === 'N/A' ? '' : trim($med_parts[4]),
                        'status' => trim($med_parts[5])
                    ];
                }
            }
        }
        
        $prescription['medications'] = $medications;
        unset($prescription['medications_data']); // Remove the raw aggregated data
    }
    
    echo json_encode([
        'success' => true,
        'data' => $prescriptions,
        'total_count' => count($prescriptions)
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_patient_prescriptions.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while fetching prescription data'
    ]);
}
?>