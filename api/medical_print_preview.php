<?php
// api/medical_print_preview.php
// Accepts POST: patient_id, sections[], date_from, date_to
// Validates auth and returns JSON preview payload using includes/medical_record_model.php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'METHOD_NOT_ALLOWED',
        'message' => 'Only POST requests are allowed'
    ]);
    exit;
}

try {
    // Get root path and include required files
    $root_path = dirname(__DIR__);
    require_once $root_path . '/config/session/employee_session.php';
    require_once $root_path . '/config/db.php';
    require_once $root_path . '/includes/medical_record_model.php';
    require_once $root_path . '/includes/medical_print_security.php';

    // Initialize security manager
    $security = new MedicalPrintSecurity();
    
    // Validate request origin
    if (!$security->validateRequestOrigin()) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_ORIGIN',
            'message' => 'Request origin not allowed'
        ]);
        exit;
    }

    // Check authentication
    if (!is_employee_logged_in()) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'UNAUTHORIZED',
            'message' => 'Employee authentication required'
        ]);
        exit;
    }

    // Get employee role and ID
    $employeeId = get_employee_session('employee_id');
    $employeeRole = get_employee_session('role');

    // Check if employee has access to patient records
    $authorizedRoles = ['Admin', 'Doctor', 'Nurse', 'DHO', 'BHW', 'Records Officer'];
    if (!in_array($employeeRole, $authorizedRoles)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'INSUFFICIENT_PERMISSIONS',
            'message' => 'Your role does not have access to patient medical records'
        ]);
        exit;
    }

    // Get and validate input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_JSON',
            'message' => 'Invalid JSON input'
        ]);
        exit;
    }

    // Validate CSRF token if provided
    if (isset($input['csrf_token'])) {
        if (!$security->validateCsrfToken($input['csrf_token'])) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'INVALID_CSRF_TOKEN',
                'message' => 'Invalid or expired CSRF token'
            ]);
            exit;
        }
    }

    // Validate required fields
    if (!isset($input['patient_id']) || !is_numeric($input['patient_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_PATIENT_ID',
            'message' => 'Valid patient_id is required'
        ]);
        exit;
    }

    $patientId = (int)$input['patient_id'];
    
    // Validate patient exists and is active
    $stmt = $pdo->prepare("SELECT patient_id, first_name, last_name, is_active FROM patients WHERE patient_id = ?");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'PATIENT_NOT_FOUND',
            'message' => 'Patient not found'
        ]);
        exit;
    }
    
    if (!$patient['is_active']) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'PATIENT_INACTIVE',
            'message' => 'Patient account is inactive'
        ]);
        exit;
    }

    // Check role-based access restrictions using security manager
    if (!$security->userCanAccessPatient($employeeId, $patientId)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'ACCESS_DENIED',
            'message' => 'You do not have access to this patient\'s records'
        ]);
        exit;
    }

    // Check if user has permission to view medical records
    if (!$security->hasPermission($employeeId, 'view')) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'INSUFFICIENT_PERMISSIONS',
            'message' => 'Your role does not have permission to view medical records'
        ]);
        exit;
    }

    // Process sections parameter
    $sections = $input['sections'] ?? [];
    if (!is_array($sections)) {
        $sections = [];
    }

    // Available sections
    $availableSections = [
        'basic', 'personal_information', 'emergency_contacts', 'lifestyle_information',
        'past_medical_conditions', 'chronic_illnesses', 'immunizations', 'family_history',
        'surgical_history', 'allergies', 'current_medications', 'consultations',
        'appointments', 'referrals', 'prescriptions', 'lab_orders', 'billing'
    ];

    // Validate sections
    foreach ($sections as $section) {
        if (!in_array($section, $availableSections)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'INVALID_SECTION',
                'message' => "Invalid section: {$section}",
                'available_sections' => $availableSections
            ]);
            exit;
        }
    }

    // If no sections specified, include all
    if (empty($sections)) {
        $sections = $availableSections;
    }

    // Process date filters
    $dateFrom = $input['date_from'] ?? null;
    $dateTo = $input['date_to'] ?? null;
    $limit = $input['limit'] ?? 50;
    $offset = $input['offset'] ?? 0;

    // Validate dates if provided
    if ($dateFrom && !DateTime::createFromFormat('Y-m-d', $dateFrom)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_DATE_FROM',
            'message' => 'date_from must be in YYYY-MM-DD format'
        ]);
        exit;
    }

    if ($dateTo && !DateTime::createFromFormat('Y-m-d', $dateTo)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_DATE_TO',
            'message' => 'date_to must be in YYYY-MM-DD format'
        ]);
        exit;
    }

    // Validate limit and offset
    $limit = max(1, min(200, (int)$limit)); // Between 1 and 200
    $offset = max(0, (int)$offset);

    // Prepare filters
    $filters = [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'limit' => $limit,
        'offset' => $offset
    ];

    // Fetch medical record data
    $model = new MedicalRecordModel();
    $medicalRecord = $model->getComprehensiveMedicalRecord($patientId, $sections, $filters);

    // Generate preview HTML fragments for each section
    $previewFragments = [];
    
    foreach ($sections as $section) {
        if (!isset($medicalRecord[$section])) {
            continue;
        }
        
        $data = $medicalRecord[$section];
        $fragment = generatePreviewFragment($section, $data);
        $previewFragments[$section] = $fragment;
    }

    // Log access for audit trail using security manager
    $auditMetadata = [
        'sections' => $sections,
        'filters' => $filters,
        'output_format' => 'html',
        'request_type' => 'preview'
    ];
    
    $security->logAuditAction($employeeId, $patientId, 'preview', $auditMetadata);

    // Generate CSRF token for subsequent requests
    $csrfToken = $security->generateCsrfToken();

    // Return successful response
    echo json_encode([
        'success' => true,
        'data' => [
            'patient' => [
                'patient_id' => $patient['patient_id'],
                'name' => trim($patient['first_name'] . ' ' . $patient['last_name']),
                'first_name' => $patient['first_name'],
                'last_name' => $patient['last_name']
            ],
            'sections' => $sections,
            'filters' => $filters,
            'medical_record' => $medicalRecord,
            'preview_fragments' => $previewFragments,
            'csrf_token' => $csrfToken,
            'metadata' => [
                'total_sections' => count($sections),
                'generated_at' => date('Y-m-d H:i:s'),
                'generated_by' => [
                    'employee_id' => $employeeId,
                    'role' => $employeeRole
                ]
            ]
        ]
    ]);

} catch (PDOException $e) {
    error_log("Medical Print Preview Database Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'DATABASE_ERROR',
        'message' => 'Database connection failed'
    ]);
} catch (Exception $e) {
    error_log("Medical Print Preview Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'SERVER_ERROR',
        'message' => 'An unexpected error occurred'
    ]);
}

/**
 * Generate HTML preview fragment for a section
 * @param string $section
 * @param mixed $data
 * @return string
 */
function generatePreviewFragment($section, $data) {
    if (empty($data)) {
        return '<div class="no-data">No data available</div>';
    }
    
    switch ($section) {
        case 'basic':
            return generateBasicPreview($data);
        case 'personal_information':
            return generatePersonalInfoPreview($data);
        case 'emergency_contacts':
            return generateEmergencyContactsPreview($data);
        case 'consultations':
            return generateConsultationsPreview($data);
        case 'prescriptions':
            return generatePrescriptionsPreview($data);
        case 'lab_orders':
            return generateLabOrdersPreview($data);
        case 'allergies':
            return generateAllergiesPreview($data);
        case 'current_medications':
            return generateCurrentMedicationsPreview($data);
        default:
            return generateGenericPreview($section, $data);
    }
}

function generateBasicPreview($data) {
    $age = $data['date_of_birth'] ? date_diff(date_create($data['date_of_birth']), date_create('today'))->y : 'N/A';
    return "
        <div class='basic-info'>
            <h3>Patient Information</h3>
            <p><strong>Name:</strong> {$data['first_name']} {$data['middle_name']} {$data['last_name']}</p>
            <p><strong>Date of Birth:</strong> {$data['date_of_birth']} (Age: {$age})</p>
            <p><strong>Gender:</strong> {$data['gender']}</p>
            <p><strong>Contact:</strong> {$data['contact_number']}</p>
            <p><strong>Address:</strong> {$data['address']}, {$data['barangay']}, {$data['municipality']}</p>
        </div>
    ";
}

function generatePersonalInfoPreview($data) {
    return "
        <div class='personal-info'>
            <h3>Personal Information</h3>
            <p><strong>Civil Status:</strong> " . ($data['civil_status'] ?? 'N/A') . "</p>
            <p><strong>Occupation:</strong> " . ($data['occupation'] ?? 'N/A') . "</p>
            <p><strong>Education:</strong> " . ($data['educational_attainment'] ?? 'N/A') . "</p>
        </div>
    ";
}

function generateEmergencyContactsPreview($data) {
    if (empty($data)) return '<div class="no-data">No emergency contacts</div>';
    
    $html = '<div class="emergency-contacts"><h3>Emergency Contacts</h3>';
    foreach ($data as $contact) {
        $html .= "<p><strong>{$contact['contact_name']}</strong> ({$contact['relationship']}) - {$contact['contact_number']}</p>";
    }
    $html .= '</div>';
    return $html;
}

function generateConsultationsPreview($data) {
    if (empty($data)) return '<div class="no-data">No consultations</div>';
    
    $html = '<div class="consultations"><h3>Recent Consultations</h3>';
    $count = min(3, count($data)); // Show only first 3 for preview
    for ($i = 0; $i < $count; $i++) {
        $consultation = $data[$i];
        $date = date('M d, Y', strtotime($consultation['consultation_date']));
        $doctor = trim(($consultation['doctor_first_name'] ?? '') . ' ' . ($consultation['doctor_last_name'] ?? ''));
        $html .= "<p><strong>{$date}</strong> - {$consultation['chief_complaint']} (Dr. {$doctor})</p>";
    }
    if (count($data) > 3) {
        $html .= '<p><em>... and ' . (count($data) - 3) . ' more consultations</em></p>';
    }
    $html .= '</div>';
    return $html;
}

function generatePrescriptionsPreview($data) {
    if (empty($data)) return '<div class="no-data">No prescriptions</div>';
    
    $html = '<div class="prescriptions"><h3>Recent Prescriptions</h3>';
    $count = min(3, count($data));
    for ($i = 0; $i < $count; $i++) {
        $prescription = $data[$i];
        $date = date('M d, Y', strtotime($prescription['prescription_date']));
        $medCount = count($prescription['medications'] ?? []);
        $html .= "<p><strong>{$date}</strong> - {$medCount} medication(s) prescribed</p>";
    }
    if (count($data) > 3) {
        $html .= '<p><em>... and ' . (count($data) - 3) . ' more prescriptions</em></p>';
    }
    $html .= '</div>';
    return $html;
}

function generateLabOrdersPreview($data) {
    if (empty($data)) return '<div class="no-data">No lab orders</div>';
    
    $html = '<div class="lab-orders"><h3>Recent Lab Orders</h3>';
    $count = min(3, count($data));
    for ($i = 0; $i < $count; $i++) {
        $order = $data[$i];
        $date = date('M d, Y', strtotime($order['order_date']));
        $testCount = count($order['items'] ?? []);
        $html .= "<p><strong>{$date}</strong> - {$testCount} test(s) ordered ({$order['status']})</p>";
    }
    if (count($data) > 3) {
        $html .= '<p><em>... and ' . (count($data) - 3) . ' more lab orders</em></p>';
    }
    $html .= '</div>';
    return $html;
}

function generateAllergiesPreview($data) {
    if (empty($data)) return '<div class="no-data">No known allergies</div>';
    
    $html = '<div class="allergies"><h3>Allergies</h3>';
    foreach ($data as $allergy) {
        $html .= "<p><strong>{$allergy['allergen']}</strong> - {$allergy['reaction']} ({$allergy['severity']})</p>";
    }
    $html .= '</div>';
    return $html;
}

function generateCurrentMedicationsPreview($data) {
    if (empty($data)) return '<div class="no-data">No current medications</div>';
    
    $html = '<div class="current-medications"><h3>Current Medications</h3>';
    foreach ($data as $medication) {
        $html .= "<p><strong>{$medication['medication_name']}</strong> - {$medication['dosage']} ({$medication['frequency']})</p>";
    }
    $html .= '</div>';
    return $html;
}

function generateGenericPreview($section, $data) {
    $title = ucwords(str_replace('_', ' ', $section));
    $count = is_array($data) ? count($data) : 1;
    return "<div class='generic-preview'><h3>{$title}</h3><p>{$count} record(s) available</p></div>";
}
?>