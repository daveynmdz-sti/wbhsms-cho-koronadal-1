<?php
// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

// Server-side role enforcement - allow roles 1,2,3,9 (admin, doctor, nurse, laboratory_tech)
$authorizedRoleIds = [1, 2, 3, 9];
if (!isset($_SESSION['employee_id']) || !in_array($_SESSION['role_id'], $authorizedRoleIds)) {
    http_response_code(403);
    exit('Not authorized');
}

// Handle AJAX search requests
if (isset($_GET['action'])) {
    // Enable error reporting for debugging
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
    
    header('Content-Type: application/json');
    
    if ($_GET['action'] === 'search_patients') {
        try {
            // Validate database connection
            if (!$conn || $conn->connect_error) {
                throw new Exception('Database connection failed: ' . ($conn ? $conn->connect_error : 'No connection object'));
            }
            
            $search = $_GET['search'] ?? '';
            $searchParam = "%{$search}%";
            
            $sql = "SELECT p.patient_id, p.first_name, p.last_name, p.middle_name, p.username, 
                           p.date_of_birth, p.sex, p.contact_number, b.barangay_name
                    FROM patients p 
                    LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
                    WHERE p.status = 'active' AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.username LIKE ? 
                           OR p.contact_number LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?
                           OR b.barangay_name LIKE ?)
                    ORDER BY p.last_name, p.first_name 
                    LIMIT 20";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare SQL statement: ' . $conn->error);
            }
            
            $stmt->bind_param("ssssss", $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to execute query: ' . $stmt->error);
            }
            
            $result = $stmt->get_result();
            if (!$result) {
                throw new Exception('Failed to get result: ' . $stmt->error);
            }
            
            $patients = [];
            while ($row = $result->fetch_assoc()) {
                $patients[] = $row;
            }
            
            echo json_encode($patients);
            exit();
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => true,
                'message' => 'Search failed: ' . $e->getMessage(),
                'debug' => [
                    'file' => basename(__FILE__),
                    'line' => __LINE__,
                    'search_term' => $search ?? 'undefined'
                ]
            ]);
            exit();
        }
    }
    
    if ($_GET['action'] === 'search_patients_direct') {
        try {
            // Validate database connection
            if (!$conn || $conn->connect_error) {
                throw new Exception('Database connection failed: ' . ($conn ? $conn->connect_error : 'No connection object'));
            }
            
            $search = $_GET['search'] ?? '';
            $barangay_filter = $_GET['barangay'] ?? '';
            
            $sql = "SELECT p.patient_id, p.first_name, p.last_name, p.middle_name, p.username, 
                           p.date_of_birth, p.sex, p.contact_number, b.barangay_name
                    FROM patients p 
                    LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
                    WHERE p.status = 'active'";
            
            $params = [];
            $types = "";
            
            if (!empty($search)) {
                $sql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.username LIKE ? 
                           OR p.contact_number LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)";
                $searchParam = "%{$search}%";
                array_push($params, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
                $types .= "sssss";
            }
            
            if (!empty($barangay_filter)) {
                $sql .= " AND b.barangay_name LIKE ?";
                $barangayParam = "%{$barangay_filter}%";
                array_push($params, $barangayParam);
                $types .= "s";
            }
            
            $sql .= " ORDER BY p.last_name, p.first_name LIMIT 50";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare SQL statement: ' . $conn->error);
            }
            
            if (!empty($types)) {
                $stmt->bind_param($types, ...$params);
            }
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to execute query: ' . $stmt->error);
            }
            
            $result = $stmt->get_result();
            if (!$result) {
                throw new Exception('Failed to get result: ' . $stmt->error);
            }
            
            $patients = [];
            while ($row = $result->fetch_assoc()) {
                $patients[] = $row;
            }
            
            echo json_encode($patients);
            exit();
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => true,
                'message' => 'Search failed: ' . $e->getMessage(),
                'debug' => [
                    'file' => basename(__FILE__),
                    'line' => __LINE__,
                    'search_term' => $search ?? 'undefined',
                    'barangay_filter' => $barangay_filter ?? 'undefined'
                ]
            ]);
            exit();
        }
    }
    
    if ($_GET['action'] === 'search_visits') {
        try {
            // Validate database connection
            if (!$conn || $conn->connect_error) {
                throw new Exception('Database connection failed: ' . ($conn ? $conn->connect_error : 'No connection object'));
            }
            
            $search = $_GET['search'] ?? '';
            $searchParam = "%{$search}%";
            
            $sql = "SELECT v.visit_id, v.patient_id, v.appointment_id, v.visit_date, v.visit_status,
                           p.first_name, p.last_name, p.username,
                           a.scheduled_date, a.scheduled_time
                    FROM visits v
                    LEFT JOIN patients p ON v.patient_id = p.patient_id
                    LEFT JOIN appointments a ON v.appointment_id = a.appointment_id
                    WHERE (p.first_name LIKE ? OR p.last_name LIKE ? OR p.username LIKE ? 
                           OR v.visit_id LIKE ? OR a.appointment_id LIKE ?)
                    ORDER BY v.visit_date DESC 
                    LIMIT 20";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare SQL statement: ' . $conn->error);
            }
            
            $stmt->bind_param("sssss", $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to execute query: ' . $stmt->error);
            }
            
            $result = $stmt->get_result();
            if (!$result) {
                throw new Exception('Failed to get result: ' . $stmt->error);
            }
            
            $visits = [];
            while ($row = $result->fetch_assoc()) {
                $visits[] = $row;
            }
            
            echo json_encode($visits);
            exit();
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => true,
                'message' => 'Search visits failed: ' . $e->getMessage(),
                'debug' => [
                    'file' => basename(__FILE__),
                    'line' => __LINE__,
                    'search_term' => $search ?? 'undefined'
                ]
            ]);
            exit();
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug: Log form submission data
    error_log("=== LAB ORDER FORM SUBMISSION ===");
    error_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
    error_log("POST Data: " . print_r($_POST, true));
    error_log("Session Employee ID: " . ($_SESSION['employee_id'] ?? 'NOT SET'));
    error_log("================================");
    
    $patient_id = $_POST['patient_id'] ?? null;
    $selected_tests = $_POST['selected_tests'] ?? [];
    $others_test = $_POST['others_test'] ?? '';
    $remarks = $_POST['remarks'] ?? '';
    $appointment_id = $_POST['appointment_id'] ?? null;
    $visit_id = $_POST['visit_id'] ?? null;

    // Convert empty strings to NULL for database insertion
    $appointment_id = (!empty($appointment_id) && $appointment_id !== '') ? intval($appointment_id) : null;
    $visit_id = (!empty($visit_id) && $visit_id !== '') ? intval($visit_id) : null;

    // Debug: Log extracted values
    error_log("Extracted Values:");
    error_log("Patient ID: " . ($patient_id ?? 'NULL'));
    error_log("Selected Tests: " . print_r($selected_tests, true));
    error_log("Others Test: " . ($others_test ?? 'EMPTY'));
    error_log("Remarks: " . ($remarks ?? 'EMPTY'));
    error_log("Appointment ID: " . ($appointment_id === null ? 'NULL' : $appointment_id));
    error_log("Visit ID: " . ($visit_id === null ? 'NULL' : $visit_id));

    if (!$patient_id || (empty($selected_tests) && empty($others_test))) {
        error_log("VALIDATION FAILED: Patient ID=" . ($patient_id ?? 'NULL') . ", Tests count=" . count($selected_tests) . ", Others test=" . ($others_test ?? 'EMPTY'));
        $_SESSION['lab_message'] = 'Please select a patient and at least one lab test.';
        $_SESSION['lab_message_type'] = 'error';
        header('Location: lab_management.php');
        exit();
    }

    // Validate patient is active before allowing lab order creation
    $patient_status_stmt = $conn->prepare("SELECT status FROM patients WHERE patient_id = ?");
    $patient_status_stmt->bind_param("i", $patient_id);
    $patient_status_stmt->execute();
    $patient_status_result = $patient_status_stmt->get_result();
    $patient_status_data = $patient_status_result->fetch_assoc();
    
    if (!$patient_status_data || $patient_status_data['status'] !== 'active') {
        error_log("VALIDATION FAILED: Patient status is not active for patient ID: " . $patient_id);
        $_SESSION['lab_message'] = 'Lab orders can only be created for active patients.';
        $_SESSION['lab_message_type'] = 'error';
        header('Location: lab_management.php');
        exit();
    }

    try {
        error_log("Starting database transaction...");
        $conn->begin_transaction();

        // Create lab order (appointment_id and visit_id can be NULL if no appointment selected)
        $insertOrderSql = "INSERT INTO lab_orders (patient_id, appointment_id, visit_id, ordered_by_employee_id, remarks, status) VALUES (?, ?, ?, ?, ?, 'pending')";
        error_log("Preparing lab order SQL: " . $insertOrderSql);
        $orderStmt = $conn->prepare($insertOrderSql);
        
        if (!$orderStmt) {
            throw new Exception("Failed to prepare lab order statement: " . $conn->error);
        }
        
        error_log("Binding parameters: patient_id=$patient_id, appointment_id=" . ($appointment_id === null ? 'NULL' : $appointment_id) . ", visit_id=" . ($visit_id === null ? 'NULL' : $visit_id) . ", employee_id=" . $_SESSION['employee_id'] . ", remarks=$remarks");
        
        $orderStmt->bind_param("iiiis", $patient_id, $appointment_id, $visit_id, $_SESSION['employee_id'], $remarks);
        
        if (!$orderStmt->execute()) {
            throw new Exception("Failed to execute lab order statement: " . $orderStmt->error);
        }
        
        $lab_order_id = $conn->insert_id;
        error_log("Lab order created with ID: " . $lab_order_id);

        // Create lab order items for each selected test (using existing schema)
        $insertItemSql = "INSERT INTO lab_order_items (lab_order_id, test_type, status) VALUES (?, ?, 'pending')";
        error_log("Preparing lab order items SQL: " . $insertItemSql);
        $itemStmt = $conn->prepare($insertItemSql);
        
        if (!$itemStmt) {
            throw new Exception("Failed to prepare lab order items statement: " . $conn->error);
        }

        // Add "Others" test if specified
        if (!empty($others_test)) {
            $selected_tests[] = "Others: " . $others_test;
            error_log("Added Others test: " . $others_test);
        }

        error_log("Inserting " . count($selected_tests) . " test items...");
        foreach ($selected_tests as $test_type) {
            error_log("Inserting test: " . $test_type);
            $itemStmt->bind_param("is", $lab_order_id, $test_type);
            if (!$itemStmt->execute()) {
                throw new Exception("Failed to execute lab order item statement for test '$test_type': " . $itemStmt->error);
            }
        }

        $conn->commit();
        error_log("Transaction committed successfully!");
        
        $_SESSION['lab_message'] = 'Lab order created successfully.';
        $_SESSION['lab_message_type'] = 'success';
        
        // Return JSON response for AJAX requests
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Lab order created successfully.']);
            exit();
        }
        
        header('Location: lab_management.php');
        exit();

    } catch (Exception $e) {
        error_log("EXCEPTION in lab order creation: " . $e->getMessage());
        error_log("Exception trace: " . $e->getTraceAsString());
        $conn->rollback();
        $_SESSION['lab_message'] = 'Error creating lab order: ' . $e->getMessage();
        $_SESSION['lab_message_type'] = 'error';
        
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Error creating lab order: ' . $e->getMessage()]);
            exit();
        }
        
        header('Location: lab_management.php');
        exit();
    }
}

// Fetch available barangays for the search filter
$barangays = [];
try {
    $barangayQuery = "SELECT barangay_id, barangay_name FROM barangay WHERE status = 'active' ORDER BY barangay_name";
    $barangayResult = $conn->query($barangayQuery);
    if ($barangayResult) {
        while ($row = $barangayResult->fetch_assoc()) {
            $barangays[] = $row;
        }
    }
} catch (Exception $e) {
    // Log error but don't stop the page from loading
    error_log("Error fetching barangays: " . $e->getMessage());
}

// Available lab tests based on requirements
$available_tests = [
    'Complete Blood Count (CBC)',
    'Platelet Count',
    'Blood Typing',
    'Clotting Time and Bleeding Time',
    'Urinalysis',
    'Pregnancy Test',
    'Fecalysis',
    'Serum Potassium',
    'Thyroid Function Tests: TSH',
    'Thyroid Function Tests: FT3',
    'Thyroid Function Tests: FT4',
    'CXR – PA',
    'Drug Test',
    'ECG w/ reading',
    'FBS',
    'Creatinine',
    'SGPT',
    'Uric Acid',
    'Lipid Profile',
    'Serum Na K'
];

// Include reusable topbar component
require_once $root_path . '/includes/topbar.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
        <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <title>Create Lab Order | CHO Koronadal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="../../assets/css/topbar.css" />
    <link rel="stylesheet" href="../../assets/css/profile-edit-responsive.css" />
    <link rel="stylesheet" href="../../assets/css/profile-edit.css" />
    <link rel="stylesheet" href="../../assets/css/edit.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        .search-container {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .form-section {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
            margin-bottom: 1rem;
        }

        .patient-table {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .patient-table table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            min-width: 600px;
        }

        .patient-table th,
        .patient-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .patient-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #0077b6;
        }

        .patient-table tbody tr:hover {
            background: #f8f9fa;
        }

        .patient-checkbox {
            width: 18px;
            height: 18px;
            margin-right: 0.5rem;
        }

        .lab-order-form {
            opacity: 0.5;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .lab-order-form.enabled {
            opacity: 1;
            pointer-events: auto;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #0077b6;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
        }

        .selected-patient {
            background: #d4edda !important;
            border-left: 4px solid #28a745;
        }

        .empty-search {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #023e8a, #001d3d);
            transform: translateY(-2px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .tests-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }

        .test-checkbox {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .test-checkbox:hover {
            background-color: #f0f8ff;
            border-color: #007bff;
        }

        .test-checkbox input[type="checkbox"] {
            margin-right: 8px;
            transform: scale(1.2);
        }

        .test-checkbox input[type="checkbox"]:checked + label {
            color: #007bff;
            font-weight: bold;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f1b2b7;
        }

        /* Loading Screen Styles */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(5px);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #0077b6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 1rem;
        }

        .loading-text {
            font-size: 1.2rem;
            color: #0077b6;
            font-weight: 600;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .search-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .tests-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <!-- Loading Screen -->
    <div id="loadingOverlay" class="loading-overlay">
        <div class="loading-spinner"></div>
        <div class="loading-text">Creating Lab Order...</div>
    </div>

    <?php 
    // Render snackbar notification system
    renderSnackbar();
    
    // Render topbar
    renderTopbar([
        'title' => 'Create New Lab Order',
        'back_url' => 'lab_management.php',
        'user_type' => 'employee',
        'vendor_path' => '../../vendor/'
    ]);
    ?>

    <section class="homepage">
        <?php 
        // Render back button with modal
        renderBackButton([
            'back_url' => 'lab_management.php',
            'button_text' => '← Back / Cancel',
            'modal_title' => 'Cancel Creating Lab Order?',
            'modal_message' => 'Are you sure you want to go back/cancel? Unsaved changes will be lost.',
            'confirm_text' => 'Yes, Cancel',
            'stay_text' => 'Stay'
        ]);
        ?>

        <div class="profile-wrapper">
            <!-- Lab Order Creation Form -->
            <form method="POST" action="" id="createOrderForm">
            
            <!-- Reminders Box -->
            <div class="reminders-box">
                <strong>Reminders:</strong>
                <ul>
                    <li>Search and select a patient from the list below before creating a lab order.</li>
                    <li>You can search by patient ID, name, appointment, or visit details.</li>
                    <li>Direct patient search allows creating orders without appointments (for authorized roles).</li>
                    <li>Select at least one lab test from the available options.</li>
                    <li>Fields marked with * are required.</li>
                </ul>
            </div>

            <!-- Patient Search Section -->
            <div class="form-section">
                <h3 style="color: #0077b6; margin-bottom: 1rem;">
                    <i class="fas fa-search"></i> Patient Search Method
                </h3>
                
                <div style="display: flex; gap: 20px; margin-bottom: 20px; justify-content: center;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="radio" name="searchMode" value="appointment" id="appointmentMode" checked onchange="toggleSearchMode()">
                        <span>Search with Appointment/Visit</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="radio" name="searchMode" value="direct" id="directMode" onchange="toggleSearchMode()">
                        <span>Direct Patient Search (No Appointment Required)</span>
                    </label>
                </div>
            </div>

            <!-- Appointment-based Search Section -->
            <div class="search-container" id="appointmentSearchSection">
                <h4 style="color: #0077b6; margin-bottom: 1rem;">Search by Appointment/Visit Details</h4>
                <div class="search-grid">
                    <div class="form-group">
                        <label for="patientIdFilter">Patient ID</label>
                        <input type="text" id="patientIdFilter" placeholder="Enter Patient ID">
                    </div>
                    <div class="form-group">
                        <label for="appointmentIdFilter">Appointment ID</label>
                        <input type="text" id="appointmentIdFilter" placeholder="Enter Appointment ID">
                    </div>
                    <div class="form-group">
                        <label for="visitIdFilter">Visit ID</label>
                        <input type="text" id="visitIdFilter" placeholder="Enter Visit ID">
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1rem;">
                    <button type="button" class="btn btn-primary" onclick="searchPatients()">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <button type="button" class="btn" onclick="clearSearch()" style="background: #6c757d; color: white;">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
            </div>

            <!-- Direct Patient Search Section -->
            <div class="search-container" id="directSearchSection" style="display: none;">
                <h4 style="color: #0077b6; margin-bottom: 1rem;">Direct Patient Search</h4>
                <div class="search-grid">
                    <div class="form-group">
                        <label for="patientNameFilter">General Search</label>
                        <input type="text" id="patientNameFilter" placeholder="Patient ID, Name">
                    </div>
                    <div class="form-group">
                        <label for="patientFirstName">First Name</label>
                        <input type="text" id="patientFirstName" placeholder="Enter first name">
                    </div>
                    <div class="form-group">
                        <label for="patientLastName">Last Name</label>
                        <input type="text" id="patientLastName" placeholder="Enter last name">
                    </div>
                    <div class="form-group">
                        <label for="barangayFilter">Barangay</label>
                        <select id="barangayFilter">
                            <option value="">All Barangays</option>
                            <?php foreach ($barangays as $barangay): ?>
                                <option value="<?= htmlspecialchars($barangay['barangay_name']) ?>">
                                    <?= htmlspecialchars($barangay['barangay_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1rem;">
                    <button type="button" class="btn btn-primary" onclick="searchPatientsDirect()">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <button type="button" class="btn" onclick="clearDirectSearch()" style="background: #6c757d; color: white;">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
            </div>

            <!-- Search Results Section -->
            <div class="patient-table" id="searchResultsContainer" style="display: none;">
                <h4 style="color: #0077b6; margin-bottom: 1rem;">Patient Search Results</h4>
                <div style="overflow-x: auto;">
                    <table id="searchResultsTable" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th>Select</th>
                                <th>Patient ID</th>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Age</th>
                                <th>Sex</th>
                                <th>Barangay</th>
                            </tr>
                        </thead>
                        <tbody id="searchResultsBody">
                            <!-- Search results will be populated here -->
                        </tbody>
                    </table>
                </div>
            </div>
        
            <!-- Selected Patient Info -->
            <div id="selectedInfo" class="search-container" style="display: none; background: #d4edda; border-left: 4px solid #28a745;">
                <h4 style="color: #28a745; margin-bottom: 1rem;">
                    <i class="fas fa-user-check"></i> Selected Patient
                </h4>
                <div id="selectedDetails">
                    <!-- Selected patient details will appear here -->
                </div>
            </div>

        <!-- Lab Tests Section -->
        <div class="form-section" id="labTestsSection">
            <h3 style="color: #0077b6; margin-bottom: 1rem;">
                <i class="fas fa-flask"></i> Laboratory Tests *
            </h3>
            
            <div id="testsDisabledMessage" class="empty-search">
                <i class="fas fa-info-circle" style="font-size: 2em; color: #6c757d; margin-bottom: 1rem;"></i>
                <p>Please select a patient first to enable lab test selection.</p>
            </div>
            
            <div id="testsGrid" class="tests-grid" style="display: none;">
                <?php foreach ($available_tests as $test): ?>
                    <div class="test-checkbox">
                        <input type="checkbox" name="selected_tests[]" value="<?= htmlspecialchars($test) ?>" id="test_<?= md5($test) ?>">
                        <label for="test_<?= md5($test) ?>"><?= htmlspecialchars($test) ?></label>
                    </div>
                <?php endforeach; ?>
                
                <!-- Others option -->
                <div class="test-checkbox">
                    <input type="checkbox" name="others_checkbox" id="others_checkbox" onchange="toggleOthersInput()">
                    <label for="others_checkbox">Others (Specify)</label>
                </div>
            </div>
            
            <div class="form-group" style="margin-top: 1rem; display: none;" id="othersInputGroup">
                <label for="others_test">Specify Other Test *</label>
                <input type="text" name="others_test" id="others_test" placeholder="Enter specific test name">
            </div>
        </div>

        <!-- Additional Information -->
        <div class="form-section">
            <h3 style="color: #0077b6; margin-bottom: 1rem;">
                <i class="fas fa-clipboard"></i> Additional Information
            </h3>
            
            <div class="form-group">
                <label for="remarks">Remarks / Notes</label>
                <textarea name="remarks" id="remarks" rows="4" placeholder="Any additional notes or special instructions..."></textarea>
            </div>
        </div>

        <!-- Form Actions -->
        <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
            <button type="button" class="btn" onclick="window.location.href='lab_management.php'" style="background: #6c757d; color: white;">
                <i class="fas fa-times"></i> Cancel
            </button>
            <button type="submit" class="btn btn-primary" id="submitButton" disabled>
                <i class="fas fa-plus"></i> Create Lab Order
            </button>
        </div>

                <!-- Hidden Form Fields -->
                <input type="hidden" name="patient_id" id="patientIdField">
                <input type="hidden" name="visit_id" id="hiddenVisitId">
                <input type="hidden" name="appointment_id" id="hiddenAppointmentId">
            </form>
        </div>
    </section>

<script>
// Debug: Log that this script is loading
console.log('create_lab_order.php script is loading...');

// Define functions in multiple scopes to ensure they're accessible
function searchPatients() {
    console.log('searchPatients function called');
    
    try {
        // Debug: Check if elements exist
        const patientIdEl = document.getElementById('patientIdFilter');
        const appointmentIdEl = document.getElementById('appointmentIdFilter');
        const visitIdEl = document.getElementById('visitIdFilter');
        
        if (!patientIdEl || !appointmentIdEl || !visitIdEl) {
            console.error('Search input elements not found:', {
                patientIdEl: !!patientIdEl,
                appointmentIdEl: !!appointmentIdEl,
                visitIdEl: !!visitIdEl
            });
            alert('Error: Search input elements not found. Please check if the form is properly loaded.');
            return;
        }
        
        const patientId = patientIdEl.value;
        const appointmentId = appointmentIdEl.value;
        const visitId = visitIdEl.value;
        
        console.log('Search values:', { patientId, appointmentId, visitId });
        
        // Prepare search parameters
        let searchParams = [];
        if (patientId) searchParams.push(`patient_id=${encodeURIComponent(patientId)}`);
        if (appointmentId) searchParams.push(`appointment_id=${encodeURIComponent(appointmentId)}`);
        if (visitId) searchParams.push(`visit_id=${encodeURIComponent(visitId)}`);
        
        if (searchParams.length === 0) {
            showSearchMessage('Please enter at least one search criteria (Patient ID, Appointment ID, or Visit ID) to search for patients.', 'warning');
            return;
        }
        
        // Debug: Check if results container exists
        const resultsContainer = document.getElementById('searchResultsContainer');
        if (!resultsContainer) {
            console.error('Results container not found');
            alert('Error: Results container not found. Please check if the form is properly loaded.');
            return;
        }
        
        // Show the results container
        resultsContainer.style.display = 'block';
        
        // Make AJAX request using the existing search endpoint
        const searchQuery = patientId || appointmentId || visitId;
        const requestUrl = `create_lab_order.php?action=search_patients&search=${encodeURIComponent(searchQuery)}`;
        
        console.log('Making request to:', requestUrl);
        
        fetch(requestUrl)
            .then(response => {
                console.log('Response received:', response.status, response.headers.get('content-type'));
                
                // Check if response is actually JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    console.warn('Response is not JSON, probably an error page');
                    return response.text().then(text => {
                        console.error('Non-JSON response:', text);
                        throw new Error('Server returned an error page instead of search results. This may indicate a database connection issue or PHP error.');
                    });
                }
                
                // For JSON responses, try to parse even if there's an error status
                return response.json().then(data => {
                    if (!response.ok) {
                        // If it's a JSON error response, use the server's error message
                        if (data.error && data.message) {
                            throw new Error(data.message);
                        } else {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                    }
                    return data;
                });
            })
            .then(data => {
                console.log('Search results:', data);
                
                const resultsTable = document.getElementById('searchResultsTable');
                const tableBody = document.getElementById('searchResultsBody');
                
                if (!resultsTable || !tableBody) {
                    console.error('Results table elements not found');
                    showSearchMessage('Error: Results table not found.', 'error');
                    return;
                }
                
                // Clear previous results
                tableBody.innerHTML = '';
                
                if (data && Array.isArray(data) && data.length > 0) {
                    data.forEach(patient => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>
                                <input type="radio" name="selectedPatient" value="${patient.patient_id}" 
                                       data-patient-id="${patient.patient_id}" 
                                       data-patient-name="${patient.first_name} ${patient.last_name}"
                                       data-username="${patient.username}"
                                       onchange="selectPatient(this)">
                            </td>
                            <td>${patient.first_name} ${patient.middle_name || ''} ${patient.last_name}</td>
                            <td>${patient.patient_id || 'N/A'}</td>
                            <td>N/A</td>
                            <td>N/A</td>
                            <td>${patient.date_of_birth || 'N/A'}</td>
                            <td>${patient.sex || 'N/A'}</td>
                        `;
                        tableBody.appendChild(row);
                    });
                    resultsTable.style.display = 'table';
                    console.log('Results populated successfully');
                } else {
                    // Show specific message based on search criteria used
                    let searchedFor = [];
                    if (patientId) searchedFor.push(`Patient ID "${patientId}"`);
                    if (appointmentId) searchedFor.push(`Appointment ID "${appointmentId}"`);
                    if (visitId) searchedFor.push(`Visit ID "${visitId}"`);
                    
                    const searchText = searchedFor.join(', ');
                    showSearchMessage(`No patients found with ${searchText}. Please verify the information and ensure the patient exists in the system.`, 'info');
                    console.log('No results found for:', searchText);
                }
            })
            .catch(error => {
                console.error('Search error:', error);
                
                // Show user-friendly error messages based on error type
                if (error.message.includes('JSON')) {
                    showSearchMessage('Server error: Unable to process search request. Please check if the patient data exists in the system.', 'error');
                } else if (error.message.includes('network') || error.message.includes('fetch')) {
                    showSearchMessage('Network error: Unable to connect to server. Please check your connection and try again.', 'error');
                } else {
                    showSearchMessage(`Search failed: ${error.message}`, 'error');
                }
            });
    } catch (error) {
        console.error('Unexpected error in searchPatients:', error);
        alert(`Unexpected error: ${error.message}. Please check the console for details.`);
    }
}

// Also assign to window object for backup access
window.searchPatients = searchPatients;

function showSearchMessage(message, type = 'info') {
    const resultsTable = document.getElementById('searchResultsTable');
    const tableBody = document.getElementById('searchResultsBody');
    
    if (!tableBody) return;
    
    // Clear previous results
    tableBody.innerHTML = '';
    
    // Create message row with appropriate styling
    const row = document.createElement('tr');
    const messageClass = `search-message-${type}`;
    
    const iconClass = type === 'error' ? 'fas fa-exclamation-triangle' : 
                     type === 'warning' ? 'fas fa-exclamation-circle' : 
                     'fas fa-info-circle';
    
    row.innerHTML = `
        <td colspan="7" style="text-align: center; padding: 20px;" class="${messageClass}">
            <i class="${iconClass}"></i> ${message}
        </td>
    `;
    
    tableBody.appendChild(row);
    resultsTable.style.display = 'table';
    
    // Also show results container to make message visible
    const resultsContainer = document.getElementById('searchResultsContainer');
    if (resultsContainer) {
        resultsContainer.style.display = 'block';
    }
    
    console.log(`Search message (${type}):`, message);
}

function clearSearch() {
    console.log('clearSearch function called');
    
    try {
        // Clear input fields
        const patientIdEl = document.getElementById('patientIdFilter');
        const appointmentIdEl = document.getElementById('appointmentIdFilter');
        const visitIdEl = document.getElementById('visitIdFilter');
        
        if (patientIdEl) patientIdEl.value = '';
        if (appointmentIdEl) appointmentIdEl.value = '';
        if (visitIdEl) visitIdEl.value = '';
        
        // Clear results table
        const resultsContainer = document.getElementById('searchResultsContainer');
        const tableBody = document.getElementById('searchResultsBody');
        
        if (tableBody) tableBody.innerHTML = '';
        if (resultsContainer) resultsContainer.style.display = 'none';
        
        // Reset lab tests section
        disableLabTests();
        
        console.log('Search cleared successfully');
    } catch (error) {
        console.error('Error in clearSearch:', error);
        alert(`Error clearing search: ${error.message}`);
    }
}

// Also assign to window object for backup access
window.clearSearch = clearSearch;

function selectPatient(radio) {
    console.log('selectPatient function called', radio);
    
    try {
        const patientId = radio.getAttribute('data-patient-id');
        const patientName = radio.getAttribute('data-patient-name');
        const username = radio.getAttribute('data-username');
        
        console.log('Patient selected:', { patientId, patientName, username });
        
        // Set the single patient ID field
        const patientIdField = document.getElementById('patientIdField');
        
        if (patientIdField) {
            patientIdField.value = patientId;
            console.log('Patient ID field set to:', patientId);
        } else {
            console.error('Patient ID field not found!');
        }
        
        // Show selected patient info
        const selectedInfo = document.getElementById('selectedInfo');
        const selectedDetails = document.getElementById('selectedDetails');
        
        if (selectedDetails) {
            selectedDetails.textContent = `${patientName} (ID: ${username})`;
        }
        if (selectedInfo) {
            selectedInfo.style.display = 'block';
        }
        
        console.log('Patient selection completed:', {
            patientId: patientId,
            patientName: patientName,
            fieldValue: patientIdField?.value
        });
        
        // Enable lab tests section
        enableLabTests();
        
        console.log('Patient selected successfully');
    } catch (error) {
        console.error('Error in selectPatient:', error);
        alert(`Error selecting patient: ${error.message}`);
    }
}

// Also assign to window object for backup access
window.selectPatient = selectPatient;

function enableLabTests() {
    console.log('enableLabTests function called');
    
    try {
        // Hide disabled message
        const testsDisabledMessage = document.getElementById('testsDisabledMessage');
        if (testsDisabledMessage) {
            testsDisabledMessage.style.display = 'none';
        }
        
        // Show tests grid
        const testsGrid = document.getElementById('testsGrid');
        if (testsGrid) {
            testsGrid.style.display = 'block';
        }
        
        // Enable all test checkboxes
        const checkboxes = document.querySelectorAll('#testsGrid input[type="checkbox"]');
        checkboxes.forEach(checkbox => {
            checkbox.disabled = false;
        });
        
        // Enable submit button
        const submitButton = document.getElementById('submitButton');
        if (submitButton) {
            submitButton.disabled = false;
        }
        
        console.log('Lab tests enabled successfully');
    } catch (error) {
        console.error('Error in enableLabTests:', error);
        alert(`Error enabling lab tests: ${error.message}`);
    }
}

// Also assign to window object for backup access
window.enableLabTests = enableLabTests;

function disableLabTests() {
    console.log('disableLabTests function called');
    
    try {
        // Show disabled message
        const testsDisabledMessage = document.getElementById('testsDisabledMessage');
        if (testsDisabledMessage) {
            testsDisabledMessage.style.display = 'block';
        }
        
        // Hide tests grid
        const testsGrid = document.getElementById('testsGrid');
        if (testsGrid) {
            testsGrid.style.display = 'none';
        }
        
        // Disable all test checkboxes and clear selections
        const checkboxes = document.querySelectorAll('#testsGrid input[type="checkbox"]');
        checkboxes.forEach(checkbox => {
            checkbox.disabled = true;
            checkbox.checked = false;
        });
        
        // Clear hidden form fields
        const patientIdField = document.getElementById('patientIdField');
        const hiddenVisitId = document.getElementById('hiddenVisitId');
        
        if (patientIdField) patientIdField.value = '';
        if (hiddenVisitId) hiddenVisitId.value = '';
        
        // Disable submit button
        const submitButton = document.getElementById('submitButton');
        if (submitButton) {
            submitButton.disabled = true;
        }
        
        console.log('Lab tests disabled successfully');
    } catch (error) {
        console.error('Error in disableLabTests:', error);
        alert(`Error disabling lab tests: ${error.message}`);
    }
}

// Also assign to window object for backup access
window.disableLabTests = disableLabTests;

// Form submission handler
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, setting up form submission handler');
    
    const form = document.getElementById('createOrderForm');
    if (!form) {
        console.error('Form not found!');
        return;
    }
    
    form.addEventListener('submit', function(e) {
        console.log('Form submission attempted');
        
        try {
            // Validate required fields
            const patientIdField = document.getElementById('patientIdField');
            const patientId = patientIdField?.value;
            
            console.log('Form submission validation:', {
                patientIdFieldExists: !!patientIdField,
                patientIdValue: patientId
            });
            
            if (!patientId) {
                e.preventDefault();
                alert('Please select a patient from the search results first.');
                return;
            }
            
            const selectedTests = document.querySelectorAll('input[name="selected_tests[]"]:checked');
            const othersCheckbox = document.getElementById('others_checkbox');
            const othersInput = document.getElementById('others_test');
            
            console.log('Selected tests count:', selectedTests.length);
            console.log('Others checkbox checked:', othersCheckbox?.checked);
            console.log('Others input value:', othersInput?.value);
            
            // Check if at least one test is selected (including Others)
            const hasSelectedTests = selectedTests.length > 0;
            const hasOthersTest = othersCheckbox?.checked && othersInput?.value.trim();
            
            if (!hasSelectedTests && !hasOthersTest) {
                e.preventDefault();
                alert('Please select at least one lab test or specify an "Others" test.');
                return;
            }
            
            // If Others is checked but no value is provided
            if (othersCheckbox?.checked && !othersInput?.value.trim()) {
                e.preventDefault();
                alert('Please specify the "Others" test name.');
                othersInput?.focus();
                return;
            }
            
            console.log('Form validation passed. Allowing form submission with patient ID:', patientId);
            
            // Show loading screen
            const loadingOverlay = document.getElementById('loadingOverlay');
            if (loadingOverlay) {
                loadingOverlay.style.display = 'flex';
            }
            
            // Let the form submit naturally - don't prevent default if validation passes
            console.log('Form will submit naturally to:', this.action || window.location.href);
            
        } catch (error) {
            e.preventDefault();
            console.error('Error during form submission:', error);
            alert(`Error during form submission: ${error.message}. Check console for details.`);
            
            // Hide loading screen on error
            const loadingOverlay = document.getElementById('loadingOverlay');
            if (loadingOverlay) {
                loadingOverlay.style.display = 'none';
            }
        }
    });
    
    console.log('Form submission handler set up successfully');
});

// New functions for search mode toggle and direct patient search

function toggleSearchMode() {
    const appointmentMode = document.getElementById('appointmentMode');
    const directMode = document.getElementById('directMode');
    const appointmentSection = document.getElementById('appointmentSearchSection');
    const directSection = document.getElementById('directSearchSection');
    
    if (directMode.checked) {
        appointmentSection.style.display = 'none';
        directSection.style.display = 'block';
        clearSearch(); // Clear any previous search
    } else {
        appointmentSection.style.display = 'block';
        directSection.style.display = 'none';
        clearDirectSearch(); // Clear any previous direct search
    }
}

function searchPatientsDirect() {
    console.log('searchPatientsDirect function called');
    
    try {
        const patientNameEl = document.getElementById('patientNameFilter');
        const patientFirstNameEl = document.getElementById('patientFirstName');
        const patientLastNameEl = document.getElementById('patientLastName');
        const barangayEl = document.getElementById('barangayFilter');
        
        const patientName = patientNameEl ? patientNameEl.value.trim() : '';
        const patientFirstName = patientFirstNameEl ? patientFirstNameEl.value.trim() : '';
        const patientLastName = patientLastNameEl ? patientLastNameEl.value.trim() : '';
        const barangay = barangayEl ? barangayEl.value : '';
        
        console.log('Direct search values:', { patientName, patientFirstName, patientLastName, barangay });
        
        if (!patientName && !patientFirstName && !patientLastName && !barangay) {
            showSearchMessage('Please enter at least one search criteria (Patient Name, First Name, Last Name, or Barangay) to search for patients.', 'warning');
            return;
        }
        
        // Show the results container
        const resultsContainer = document.getElementById('searchResultsContainer');
        if (resultsContainer) {
            resultsContainer.style.display = 'block';
        }
        
        // Use the primary search term (name, first name, or last name)
        const searchTerm = patientName || `${patientFirstName} ${patientLastName}`.trim() || '';
        const requestUrl = `create_lab_order.php?action=search_patients_direct&search=${encodeURIComponent(searchTerm)}&barangay=${encodeURIComponent(barangay)}`;
        
        console.log('Making direct search request to:', requestUrl);
        
        fetch(requestUrl)
            .then(response => {
                console.log('Direct search response received:', response.status);
                
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        console.error('Non-JSON response:', text);
                        throw new Error('Server returned an error page instead of search results.');
                    });
                }
                
                return response.json().then(data => {
                    if (!response.ok) {
                        if (data.error && data.message) {
                            throw new Error(data.message);
                        } else {
                            throw new Error(`HTTP error! status: ${response.status}`);
                        }
                    }
                    return data;
                });
            })
            .then(data => {
                console.log('Direct search results:', data);
                
                const resultsTable = document.getElementById('searchResultsTable');
                const tableBody = document.getElementById('searchResultsBody');
                
                if (!resultsTable || !tableBody) {
                    console.error('Results table elements not found');
                    showSearchMessage('Error: Results table not found.', 'error');
                    return;
                }
                
                // Clear previous results
                tableBody.innerHTML = '';
                
                if (data && Array.isArray(data) && data.length > 0) {
                    data.forEach(patient => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>
                                <input type="radio" name="selectedPatient" value="${patient.patient_id}" 
                                       data-patient-id="${patient.patient_id}" 
                                       data-patient-name="${patient.first_name} ${patient.last_name}"
                                       data-username="${patient.username}"
                                       data-barangay="${patient.barangay_name || 'N/A'}"
                                       onchange="selectPatient(this)">
                            </td>
                            <td>${patient.first_name} ${patient.middle_name || ''} ${patient.last_name}</td>
                            <td>${patient.username || patient.patient_id || 'N/A'}</td>
                            <td colspan="2">Direct Search - No Appointment Required</td>
                            <td>${patient.date_of_birth || 'N/A'}</td>
                            <td>${patient.barangay_name || 'N/A'}</td>
                        `;
                        tableBody.appendChild(row);
                    });
                    resultsTable.style.display = 'table';
                    console.log('Direct search results populated successfully');
                } else {
                    let searchedFor = [];
                    if (patientName) searchedFor.push(`Name "${patientName}"`);
                    if (patientFirstName) searchedFor.push(`First Name "${patientFirstName}"`);
                    if (patientLastName) searchedFor.push(`Last Name "${patientLastName}"`);
                    if (barangay) searchedFor.push(`Barangay "${barangay}"`);
                    
                    const searchText = searchedFor.join(', ');
                    showSearchMessage(`No patients found with ${searchText}. Please verify the information and try different search criteria.`, 'info');
                    console.log('No direct search results found for:', searchText);
                }
            })
            .catch(error => {
                console.error('Direct search error:', error);
                showSearchMessage(`Direct search failed: ${error.message}`, 'error');
            });
    } catch (error) {
        console.error('Unexpected error in searchPatientsDirect:', error);
        alert(`Unexpected error: ${error.message}`);
    }
}

function clearDirectSearch() {
    console.log('clearDirectSearch function called');
    
    try {
        // Clear direct search input fields
        const patientNameEl = document.getElementById('patientNameFilter');
        const patientFirstNameEl = document.getElementById('patientFirstName');
        const patientLastNameEl = document.getElementById('patientLastName');
        const barangayEl = document.getElementById('barangayFilter');
        
        if (patientNameEl) patientNameEl.value = '';
        if (patientFirstNameEl) patientFirstNameEl.value = '';
        if (patientLastNameEl) patientLastNameEl.value = '';
        if (barangayEl) barangayEl.value = '';
        
        // Clear results table
        const resultsContainer = document.getElementById('searchResultsContainer');
        const tableBody = document.getElementById('searchResultsBody');
        
        if (tableBody) tableBody.innerHTML = '';
        if (resultsContainer) resultsContainer.style.display = 'none';
        
        // Reset lab tests section
        disableLabTests();
        
        console.log('Direct search cleared successfully');
    } catch (error) {
        console.error('Error in clearDirectSearch:', error);
        alert(`Error clearing direct search: ${error.message}`);
    }
}

// Assign functions to window object for global access
window.toggleSearchMode = toggleSearchMode;
window.searchPatientsDirect = searchPatientsDirect;
window.clearDirectSearch = clearDirectSearch;

// Toggle Others input field
function toggleOthersInput() {
    const othersCheckbox = document.getElementById('others_checkbox');
    const othersInputGroup = document.getElementById('othersInputGroup');
    const othersInput = document.getElementById('others_test');
    
    if (othersCheckbox && othersInputGroup && othersInput) {
        if (othersCheckbox.checked) {
            othersInputGroup.style.display = 'block';
            othersInput.required = true;
        } else {
            othersInputGroup.style.display = 'none';
            othersInput.required = false;
            othersInput.value = '';
        }
    }
}

window.toggleOthersInput = toggleOthersInput;

// Debug: Log when script finishes loading
console.log('create_lab_order.php script loaded completely');
</script>

</body>
</html>