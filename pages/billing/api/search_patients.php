<?php
/**
 * API Endpoint: Search Patients
 * Purpose: Search patients for invoice creation
 */

header('Content-Type: application/json');

// Include necessary files
$root_path = dirname(dirname(dirname(dirname(__FILE__))));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in and authorized
if (!is_employee_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$employee_role = get_employee_session('role');

// Check if role is authorized for billing operations
$allowed_roles = ['Cashier', 'Admin'];
if (!in_array($employee_role, $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

try {
    // Get query parameters
    $search_term = $_GET['search'] ?? '';
    $limit = intval($_GET['limit'] ?? 20);

    if (empty($search_term)) {
        echo json_encode(['success' => false, 'message' => 'Search term is required']);
        exit();
    }

    // Search patients by username (patient ID), first name, last name, or barangay
    $query = "SELECT p.patient_id, p.username, p.first_name, p.last_name, p.barangay, p.contact_number,
                     p.date_of_birth, p.gender, p.address,
                     (SELECT COUNT(*) FROM visits v WHERE v.patient_id = p.patient_id AND DATE(v.visit_date) = CURDATE()) as today_visits,
                     (SELECT v.visit_id FROM visits v WHERE v.patient_id = p.patient_id ORDER BY v.visit_date DESC LIMIT 1) as latest_visit_id
              FROM patients p
              WHERE (p.username LIKE ? OR 
                     p.first_name LIKE ? OR 
                     p.last_name LIKE ? OR 
                     p.barangay LIKE ? OR
                     CONCAT(p.first_name, ' ', p.last_name) LIKE ?)
              AND p.is_active = 1
              ORDER BY p.last_name, p.first_name
              LIMIT ?";

    $search_param = '%' . $search_term . '%';
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $search_param, 
        $search_param, 
        $search_param, 
        $search_param, 
        $search_param, 
        $limit
    ]);
    
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format results
    foreach ($patients as &$patient) {
        $patient['full_name'] = $patient['first_name'] . ' ' . $patient['last_name'];
        $patient['age'] = null;
        
        // Calculate age if date of birth is available
        if ($patient['date_of_birth']) {
            $dob = new DateTime($patient['date_of_birth']);
            $now = new DateTime();
            $patient['age'] = $dob->diff($now)->y;
        }
        
        // Check if patient has today's visit for billing
        $patient['can_bill_today'] = $patient['today_visits'] > 0;
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'patients' => $patients,
            'search_term' => $search_term,
            'results_count' => count($patients)
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error searching patients: ' . $e->getMessage()]);
}
?>