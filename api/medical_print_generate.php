<?php
// api/medical_print_generate.php
// Accepts POST: patient_id, sections[], date_from, date_to, output=(html|pdf)
// Generates full HTML or returns a PDF via lib/pdf_generator.php

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
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
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_JSON',
            'message' => 'Invalid JSON input'
        ]);
        exit;
    }

    // Validate CSRF token for PDF generation (more strict)
    if (!isset($input['csrf_token']) || !$security->validateCsrfToken($input['csrf_token'])) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_CSRF_TOKEN',
            'message' => 'Valid CSRF token is required for medical record generation'
        ]);
        exit;
    }

    // Validate required fields
    if (!isset($input['patient_id']) || !is_numeric($input['patient_id'])) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_PATIENT_ID',
            'message' => 'Valid patient_id is required'
        ]);
        exit;
    }

    $patientId = (int)$input['patient_id'];
    $outputType = $input['output'] ?? 'html'; // html or pdf
    
    // Validate output type
    if (!in_array($outputType, ['html', 'pdf'])) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_OUTPUT_TYPE',
            'message' => 'output must be either "html" or "pdf"'
        ]);
        exit;
    }

    // Check rate limiting for PDF generation
    if ($outputType === 'pdf' && !$security->checkRateLimit($employeeId)) {
        header('Content-Type: application/json');
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'error' => 'RATE_LIMIT_EXCEEDED',
            'message' => 'PDF generation rate limit exceeded. Please try again later.'
        ]);
        exit;
    }

    // Check if user has permission for the requested output type
    $requiredPermission = ($outputType === 'pdf') ? 'print' : 'view';
    if (!$security->hasPermission($employeeId, $requiredPermission)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'INSUFFICIENT_PERMISSIONS',
            'message' => "Your role does not have permission to {$requiredPermission} medical records"
        ]);
        exit;
    }
    
    // Validate patient exists and is active
    $stmt = $pdo->prepare("SELECT patient_id, first_name, middle_name, last_name, is_active FROM patients WHERE patient_id = ?");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'PATIENT_NOT_FOUND',
            'message' => 'Patient not found'
        ]);
        exit;
    }
    
    if (!$patient['is_active']) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'PATIENT_INACTIVE',
            'message' => 'Patient account is inactive'
        ]);
        exit;
    }

    // Check patient access permissions using security manager
    if (!$security->userCanAccessPatient($employeeId, $patientId)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'ACCESS_DENIED',
            'message' => 'You do not have access to this patient\'s records'
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
            header('Content-Type: application/json');
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
    $limit = $input['limit'] ?? 1000; // Higher limit for full generation
    $offset = $input['offset'] ?? 0;

    // Validate dates if provided
    if ($dateFrom && !DateTime::createFromFormat('Y-m-d', $dateFrom)) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_DATE_FROM',
            'message' => 'date_from must be in YYYY-MM-DD format'
        ]);
        exit;
    }

    if ($dateTo && !DateTime::createFromFormat('Y-m-d', $dateTo)) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'INVALID_DATE_TO',
            'message' => 'date_to must be in YYYY-MM-DD format'
        ]);
        exit;
    }

    // Prepare filters
    $filters = [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'limit' => $limit,
        'offset' => $offset
    ];

    // Process mode parameter
    $mode = $input['mode'] ?? 'verbose'; // compact or verbose
    
    // Validate mode
    if (!in_array($mode, ['compact', 'verbose'])) {
        $mode = 'verbose';
    }

    // Fetch medical record data
    $model = new MedicalRecordModel();
    $medicalRecord = $model->getComprehensiveMedicalRecord($patientId, $sections, $filters);

    // Generate HTML content using template
    $htmlContent = generateFullMedicalRecordHTML($patient, $medicalRecord, $sections, $filters, 'verbose');

    // Log generation for audit trail using security manager
    $auditMetadata = [
        'sections' => $sections,
        'filters' => $filters,
        'output_format' => $outputType,
        'request_type' => 'generate',
        'mode' => $mode ?? 'verbose'
    ];
    
    $security->logAuditAction($employeeId, $patientId, 'generate', $auditMetadata);

    // Handle output based on type
    if ($outputType === 'pdf') {
        // Generate PDF using the dedicated PDF generator
        try {
            $pdfGenerator = new PDFGenerator();
            
            // Configure PDF options
            $pdfOptions = [
                'paper_size' => 'A4',
                'orientation' => 'portrait',
                'margin_top' => 25,
                'margin_bottom' => 25,
                'margin_left' => 20,
                'margin_right' => 20,
                'enable_remote' => true,
                'default_font' => 'Arial'
            ];
            
            $pdfContent = $pdfGenerator->generatePdfFromHtml($htmlContent, $pdfOptions);
            
            // Set PDF headers
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="medical_record_' . $patientId . '_' . date('Y-m-d') . '.pdf"');
            header('Content-Length: ' . strlen($pdfContent));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            
            echo $pdfContent;
            
        } catch (Exception $e) {
            error_log("PDF Generation Error: " . $e->getMessage());
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'PDF_GENERATION_FAILED',
                'message' => 'Failed to generate PDF document: ' . $e->getMessage()
            ]);
        }
    } else {
        // Return HTML
        header('Content-Type: text/html; charset=utf-8');
        echo $htmlContent;
    }

} catch (PDOException $e) {
    error_log("Medical Print Generate Database Error: " . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'DATABASE_ERROR',
        'message' => 'Database connection failed'
    ]);
} catch (Exception $e) {
    error_log("Medical Print Generate Error: " . $e->getMessage());
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'SERVER_ERROR',
        'message' => 'An unexpected error occurred'
    ]);
}

/**
 * Generate full HTML content for medical record using template
 * @param array $patient
 * @param array $medicalRecord
 * @param array $sections
 * @param array $filters
 * @param string $templateMode
 * @return string
 */
function generateFullMedicalRecordHTML($patient, $medicalRecord, $sections, $filters, $templateMode = 'verbose') {
    global $root_path;
    
    // Prepare data for template
    $reportData = [
        'patient' => $patient,
        'sections' => $sections,
        'medical_record' => $medicalRecord,
        'metadata' => [
            'total_sections' => count($sections),
            'generated_at' => date('Y-m-d H:i:s'),
            'generated_by' => [
                'employee_id' => get_employee_session('employee_id'),
                'role' => get_employee_session('role')
            ]
        ],
        'filters' => $filters
    ];
    
    // Set template mode
    $mode = $templateMode;
    
    // Use output buffering to capture template output
    ob_start();
    include $root_path . '/templates/medical_print_template.php';
    $htmlContent = ob_get_clean();
    
    return $htmlContent;
}
?>