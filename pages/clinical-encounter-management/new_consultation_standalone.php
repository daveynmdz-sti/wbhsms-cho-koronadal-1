<?php
/**
 * Standalone New Consultation Interface
 * Allows creation of consultations without requiring appointments or visits
 * Role-based access: Nurses can enter vitals, Doctors can complete consultations
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed. Please contact administrator.");
}

// Check if user is logged in and has proper role
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    header('Location: ../management/auth/employee_login.php');
    exit();
}

// Allow admin, doctor, nurse, and pharmacist roles
$authorized_roles = ['admin', 'doctor', 'nurse', 'pharmacist'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    header('Location: ../management/' . strtolower($_SESSION['role']) . '/dashboard.php');
    exit();
}

$employee_id = $_SESSION['employee_id'];
$employee_name = $_SESSION['employee_name'] ?? 'User';
$employee_role = strtolower($_SESSION['role']);

// Handle AJAX requests FIRST - before any HTML output
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    if ($_GET['action'] === 'get_patient_vitals') {
        try {
            $patient_id = $_GET['patient_id'] ?? null;
            
            if (!$patient_id) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Patient ID is required.'
                ]);
                exit;
            }
            
            // Get today's vitals for this patient
            $stmt = $conn->prepare("
                SELECT 
                    v.vitals_id,
                    v.systolic_bp,
                    v.diastolic_bp,
                    v.heart_rate,
                    v.respiratory_rate,
                    v.temperature,
                    v.weight,
                    v.height,
                    v.bmi,
                    v.remarks,
                    v.recorded_at,
                    e.first_name as recorded_by_name,
                    e.last_name as recorded_by_lastname
                FROM vitals v
                LEFT JOIN employees e ON v.recorded_by = e.employee_id
                WHERE v.patient_id = ? AND DATE(v.recorded_at) = CURDATE()
                ORDER BY v.recorded_at DESC
                LIMIT 1
            ");
            
            $stmt->bind_param('s', $patient_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $vitals = $result->fetch_assoc();
            
            if ($vitals) {
                echo json_encode([
                    'success' => true,
                    'vitals' => $vitals
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'vitals' => null
                ]);
            }
            exit;
            
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
            exit;
        }
    }
    
    if ($_GET['action'] === 'search_patients') {
        try {
            $search_patient_id = trim($_GET['patient_id'] ?? '');
            $search_first_name = trim($_GET['first_name'] ?? '');
            $search_last_name = trim($_GET['last_name'] ?? '');
            $search_barangay = trim($_GET['barangay'] ?? '');
            
            // Build search conditions
            $search_conditions = [];
            $search_params = [];
            $param_types = '';
            
            if (!empty($search_patient_id)) {
                $search_conditions[] = "p.username LIKE ?";
                $search_params[] = "%{$search_patient_id}%";
                $param_types .= 's';
            }
            
            if (!empty($search_first_name)) {
                $search_conditions[] = "p.first_name LIKE ?";
                $search_params[] = "%{$search_first_name}%";
                $param_types .= 's';
            }
            
            if (!empty($search_last_name)) {
                $search_conditions[] = "p.last_name LIKE ?";
                $search_params[] = "%{$search_last_name}%";
                $param_types .= 's';
            }
            
            if (!empty($search_barangay)) {
                $search_conditions[] = "b.barangay_name LIKE ?";
                $search_params[] = "%{$search_barangay}%";
                $param_types .= 's';
            }
            
            if (empty($search_conditions)) {
                echo json_encode([]);
                exit();
            }
            
            $where_clause = implode(' AND ', $search_conditions);
            
            // Search patients with multiple field criteria
            $sql = "SELECT DISTINCT
                        p.patient_id,
                        p.username as patient_code,
                        p.first_name,
                        p.last_name,
                        p.middle_name,
                        p.date_of_birth,
                        p.sex,
                        p.contact_number,
                        COALESCE(b.barangay_name, 'Not Specified') as barangay,
                        -- Check for existing consultation today
                        (SELECT c.consultation_id 
                         FROM consultations c 
                         WHERE c.patient_id = p.patient_id 
                         AND DATE(c.consultation_date) = CURDATE() 
                         ORDER BY c.created_at DESC 
                         LIMIT 1) as today_consultation_id,
                        -- Check for existing vitals today
                        (SELECT v.vitals_id 
                         FROM vitals v 
                         WHERE v.patient_id = p.patient_id 
                         AND DATE(v.recorded_at) = CURDATE() 
                         ORDER BY v.recorded_at DESC 
                         LIMIT 1) as today_vitals_id
                    FROM patients p
                    LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
                    WHERE p.status = 'active' AND ({$where_clause})
                    ORDER BY p.first_name, p.last_name
                    LIMIT 50";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare SQL statement: ' . $conn->error);
            }
            
            if (!empty($search_params)) {
                $stmt->bind_param($param_types, ...$search_params);
            }
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to execute query: ' . $stmt->error);
            }
            
            $result = $stmt->get_result();
            $patients = [];
            while ($row = $result->fetch_assoc()) {
                // Calculate age
                if ($row['date_of_birth']) {
                    $dob = new DateTime($row['date_of_birth']);
                    $now = new DateTime();
                    $row['age'] = $now->diff($dob)->y;
                } else {
                    $row['age'] = null;
                }
                
                // Format full name
                $row['full_name'] = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
                
                // Consultation status
                $row['has_consultation_today'] = !empty($row['today_consultation_id']);
                $row['has_vitals_today'] = !empty($row['today_vitals_id']);
                
                $patients[] = $row;
            }
            
            echo json_encode($patients);
            exit();
            
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => true,
                'message' => 'Search failed: ' . $e->getMessage()
            ]);
            exit();
        }
    }
}

// Handle form submissions
$success_message = '';
$error_message = '';
$consultation_id = null;
$selected_patient_id = null;
$saved_vitals_id = null;
$saved_consultation_id = null;
$selected_patient_data = null;
$saved_vitals_data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_vitals') {
        try {
            $conn->begin_transaction();
            
            // Get form data
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            
            // Vitals data
            $systolic_bp = !empty($_POST['systolic_bp']) ? (int)$_POST['systolic_bp'] : null;
            $diastolic_bp = !empty($_POST['diastolic_bp']) ? (int)$_POST['diastolic_bp'] : null;
            $heart_rate = !empty($_POST['heart_rate']) ? (int)$_POST['heart_rate'] : null;
            $respiratory_rate = !empty($_POST['respiratory_rate']) ? (int)$_POST['respiratory_rate'] : null;
            $temperature = !empty($_POST['temperature']) ? (float)$_POST['temperature'] : null;
            $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
            $height = !empty($_POST['height']) ? (float)$_POST['height'] : null;
            $vitals_remarks = trim($_POST['vitals_remarks'] ?? '');
            
            // Calculate BMI if both weight and height are provided
            $bmi = null;
            if ($weight && $height) {
                $height_m = $height / 100; // Convert cm to meters
                $bmi = round($weight / ($height_m * $height_m), 2);
            }
            
            // Validation
            if (!$patient_id) {
                throw new Exception('Please select a patient.');
            }
            
            if (!$systolic_bp && !$diastolic_bp && !$heart_rate && !$temperature && !$weight && !$height) {
                throw new Exception('Please enter at least one vital sign measurement.');
            }
            
            // Check if vitals already exist for this patient today
            $stmt = $conn->prepare("
                SELECT vitals_id 
                FROM vitals 
                WHERE patient_id = ? AND DATE(recorded_at) = CURDATE() 
                ORDER BY recorded_at DESC LIMIT 1
            ");
            $stmt->bind_param('i', $patient_id);
            $stmt->execute();
            $existing_vitals = $stmt->get_result()->fetch_assoc();
            
            if ($existing_vitals) {
                // Update existing vitals
                $stmt = $conn->prepare("
                    UPDATE vitals SET
                        systolic_bp = ?, diastolic_bp = ?, heart_rate = ?, 
                        respiratory_rate = ?, temperature = ?, weight = ?, height = ?, bmi = ?, 
                        recorded_by = ?, remarks = ?
                    WHERE vitals_id = ?
                ");
                $stmt->bind_param(
                    'iiiiddddisi', 
                    $systolic_bp, $diastolic_bp, $heart_rate, 
                    $respiratory_rate, $temperature, $weight, $height, $bmi, 
                    $employee_id, $vitals_remarks, $existing_vitals['vitals_id']
                );
                $vitals_id = $existing_vitals['vitals_id'];
            } else {
                // Insert new vitals
                $stmt = $conn->prepare("
                    INSERT INTO vitals (
                        patient_id, systolic_bp, diastolic_bp, heart_rate, 
                        respiratory_rate, temperature, weight, height, bmi, 
                        recorded_by, remarks, recorded_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->bind_param(
                    'iiiiddddiss', 
                    $patient_id, $systolic_bp, $diastolic_bp, $heart_rate, 
                    $respiratory_rate, $temperature, $weight, $height, $bmi, 
                    $employee_id, $vitals_remarks
                );
                $stmt->execute();
                $vitals_id = $conn->insert_id;
            }
            
            $conn->commit();
            $success_message = "Patient vitals saved successfully! Vitals ID: " . $vitals_id;
            
            // Keep the patient selected and show saved vitals
            $selected_patient_id = $patient_id;
            $saved_vitals_id = $vitals_id;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
    
    elseif ($action === 'save_consultation') {
        try {
            $conn->begin_transaction();
            
            // Get form data
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $provided_vitals_id = !empty($_POST['vitals_id']) ? (int)$_POST['vitals_id'] : null;
            
            // Consultation data
            $chief_complaint = trim($_POST['chief_complaint'] ?? '');
            $diagnosis = trim($_POST['diagnosis'] ?? '');
            $treatment_plan = trim($_POST['treatment_plan'] ?? '');
            $follow_up_date = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;
            $remarks = trim($_POST['remarks'] ?? '');
            $consultation_status = $_POST['consultation_status'] ?? 'ongoing';
            
            // Validation
            if (!$patient_id) {
                throw new Exception('Please select a patient.');
            }
            
            if (empty($chief_complaint)) {
                throw new Exception('Chief complaint is required.');
            }
            
            // Auto-detect today's vitals for this patient if not provided
            $vitals_id = $provided_vitals_id;
            if (!$vitals_id) {
                $stmt = $conn->prepare("
                    SELECT vitals_id 
                    FROM vitals 
                    WHERE patient_id = ? AND DATE(recorded_at) = CURDATE() 
                    ORDER BY recorded_at DESC LIMIT 1
                ");
                $stmt->bind_param('i', $patient_id);
                $stmt->execute();
                $today_vitals = $stmt->get_result()->fetch_assoc();
                if ($today_vitals) {
                    $vitals_id = $today_vitals['vitals_id'];
                }
            }
            
            // Check if consultation already exists for this patient today
            $stmt = $conn->prepare("
                SELECT consultation_id 
                FROM consultations 
                WHERE patient_id = ? AND DATE(consultation_date) = CURDATE() 
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->bind_param('i', $patient_id);
            $stmt->execute();
            $existing_consultation = $stmt->get_result()->fetch_assoc();
            
            if ($existing_consultation) {
                // Update existing consultation
                $stmt = $conn->prepare("
                    UPDATE consultations SET
                        vitals_id = ?, chief_complaint = ?, diagnosis = ?, 
                        treatment_plan = ?, follow_up_date = ?, remarks = ?,
                        consultation_status = ?, consulted_by = ?, updated_at = NOW()
                    WHERE consultation_id = ?
                ");
                $stmt->bind_param(
                    'issssssi',
                    $vitals_id, $chief_complaint, $diagnosis, $treatment_plan,
                    $follow_up_date, $remarks, $consultation_status, $employee_id, 
                    $existing_consultation['consultation_id']
                );
                $stmt->execute();
                $consultation_id = $existing_consultation['consultation_id'];
            } else {
                // Insert new consultation
                $stmt = $conn->prepare("
                    INSERT INTO consultations (
                        patient_id, vitals_id, chief_complaint, diagnosis, 
                        treatment_plan, follow_up_date, remarks, consultation_status, 
                        consulted_by, attending_employee_id, consultation_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->bind_param(
                    'iissssssii',
                    $patient_id, $vitals_id, $chief_complaint, $diagnosis,
                    $treatment_plan, $follow_up_date, $remarks, $consultation_status,
                    $employee_id, $employee_id
                );
                $stmt->execute();
                $consultation_id = $conn->insert_id;
            }
            
            // Create ONE-WAY link: consultation → vitals (NO back-reference needed)
            // Vitals remain reusable for referrals, lab orders, etc.
            
            $conn->commit();
            
            // Enhanced success message with vitals linking info
            $success_message = "Consultation saved successfully! Consultation ID: " . $consultation_id;
            if ($vitals_id) {
                $success_message .= " (Linked to Vitals ID: " . $vitals_id . ")";
            } else {
                $success_message .= " (No vitals linked - none recorded today)";
            }
            
            // Keep the patient selected after saving consultation
            $selected_patient_id = $patient_id;
            $saved_consultation_id = $consultation_id;
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
}

// Include reusable topbar component
require_once $root_path . '/includes/topbar.php';

// If we have a selected patient after form submission, load their data
if ($selected_patient_id) {
    try {
        // Get patient data
        $stmt = $conn->prepare("
            SELECT p.patient_id, p.username as patient_code, p.first_name, p.last_name, 
                   p.middle_name, p.date_of_birth, p.sex, p.contact_number,
                   COALESCE(b.barangay_name, 'Not Specified') as barangay
            FROM patients p
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            WHERE p.patient_id = ?
        ");
        $stmt->bind_param('i', $selected_patient_id);
        $stmt->execute();
        $selected_patient_data = $stmt->get_result()->fetch_assoc();
        
        if ($selected_patient_data) {
            // Calculate age
            if ($selected_patient_data['date_of_birth']) {
                $dob = new DateTime($selected_patient_data['date_of_birth']);
                $now = new DateTime();
                $selected_patient_data['age'] = $now->diff($dob)->y;
            } else {
                $selected_patient_data['age'] = null;
            }
            
            // Format full name
            $selected_patient_data['full_name'] = trim($selected_patient_data['first_name'] . ' ' . 
                ($selected_patient_data['middle_name'] ? $selected_patient_data['middle_name'] . ' ' : '') . 
                $selected_patient_data['last_name']);
        }
        
        // Get today's vitals for this patient
        $stmt = $conn->prepare("
            SELECT v.*, e.first_name as recorded_by_name, e.last_name as recorded_by_lastname
            FROM vitals v
            LEFT JOIN employees e ON v.recorded_by = e.employee_id
            WHERE v.patient_id = ? AND DATE(v.recorded_at) = CURDATE()
            ORDER BY v.recorded_at DESC LIMIT 1
        ");
        $stmt->bind_param('i', $selected_patient_id);
        $stmt->execute();
        $saved_vitals_data = $stmt->get_result()->fetch_assoc();
        
    } catch (Exception $e) {
        // Continue without patient data
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>New Consultation | CHO Koronadal</title>
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
            border-left: 4px solid #28a745;
        }

        .search-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .search-fields {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .search-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .patient-results {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #28a745;
        }

        .table-container {
            overflow-x: auto;
            max-height: 60vh;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }

        .patients-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .patients-table th,
        .patients-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
            vertical-align: middle;
        }

        .patients-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
            position: sticky;
            top: 0;
            z-index: 5;
            border-top: none;
        }

        .patients-table tbody tr {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .patients-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .patients-table tbody tr.selected {
            background-color: #e8f5e8;
            border-left: 3px solid #28a745;
        }

        .patient-radio {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .patient-name {
            font-weight: 600;
            color: #28a745;
        }

        .patient-status {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-none {
            background: #f8d7da;
            color: #721c24;
        }

        .form-section {
            opacity: 0.5;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .form-section.enabled {
            opacity: 1;
            pointer-events: auto;
        }

        .vitals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .consultation-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #28a745;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
            font-family: inherit;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #28a745;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
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
            font-family: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #218838, #1e7e34);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover:not(:disabled) {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
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

        .role-info {
            background: #e3f2fd;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #2196f3;
            margin-bottom: 1.5rem;
        }

        .loading {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }

        .loading i {
            font-size: 2em;
            margin-bottom: 1rem;
        }

        .empty-search {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }

        .patient-status {
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 500;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-warning {
            background: #fff3cd;
            color: #856404;
        }

        .status-pending {
            background: #f8d7da;
            color: #721c24;
        }

        .selected-patient-info {
            background: #f8fff8;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid #28a745;
            display: none;
        }

        @media (max-width: 768px) {
            .search-fields {
                grid-template-columns: 1fr;
            }

            .search-actions {
                flex-direction: column;
            }

            .search-actions .btn {
                width: 100%;
            }

            .vitals-grid {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            }

            .patients-table {
                font-size: 0.8rem;
            }

            .patients-table th,
            .patients-table td {
                padding: 0.5rem 0.25rem;
            }

            /* Hide less important columns on mobile */
            .patients-table th:nth-child(6),
            .patients-table td:nth-child(6) {
                display: none;
            }
        }
    </style>
</head>

<body>
    <?php 
    // Render snackbar notification system
    renderSnackbar();
    
    // Render topbar
    renderTopbar([
        'title' => 'New Consultation',
        'back_url' => 'index.php',
        'user_type' => 'employee',
        'vendor_path' => '../../vendor/'
    ]);
    ?>

    <section class="homepage">
        <?php 
        // Render back button with modal
        renderBackButton([
            'back_url' => 'index.php',
            'button_text' => '← Back to Clinical Encounters',
            'modal_title' => 'Cancel Consultation?',
            'modal_message' => 'Are you sure you want to go back? Unsaved changes will be lost.',
            'confirm_text' => 'Yes, Go Back',
            'stay_text' => 'Stay'
        ]);
        ?>

        <div class="profile-wrapper" style="margin: 0 200px;">
            <!-- Role Information -->
            <div class="role-info">
                <strong>Welcome, <?= htmlspecialchars($employee_name) ?> (<?= ucfirst($employee_role) ?>)</strong>
                <ul style="margin: 0.5rem 0 0 0; padding-left: 1.5rem;">
                    <?php if ($employee_role === 'nurse'): ?>
                        <li>As a nurse, you can record patient vital signs and create draft consultations.</li>
                        <li>Doctors can then complete the consultation with clinical notes.</li>
                    <?php elseif ($employee_role === 'doctor'): ?>
                        <li>You can record vitals and complete full consultations.</li>
                        <li>Search for any patient to create or continue their consultation.</li>
                    <?php else: ?>
                        <li>You have full access to create and manage consultations.</li>
                        <li>Search for any patient to begin their consultation process.</li>
                    <?php endif; ?>
                </ul>
            </div>

            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <!-- Patient Search Section -->
            <div class="search-container">
                <h3><i class="fas fa-search"></i> Search Patient</h3>
                <p class="text-muted">Use multiple fields to search for patients</p>
                
                <form id="searchForm" class="search-form">
                    <div class="search-fields">
                        <div class="form-group">
                            <label>Patient ID</label>
                            <input type="text" 
                                   id="patientIdSearch" 
                                   name="patient_id"
                                   class="form-control" 
                                   placeholder="e.g. P000001"
                                   autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>First Name</label>
                            <input type="text" 
                                   id="firstNameSearch" 
                                   name="first_name"
                                   class="form-control" 
                                   placeholder="Enter first name"
                                   autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>Last Name</label>
                            <input type="text" 
                                   id="lastNameSearch" 
                                   name="last_name"
                                   class="form-control" 
                                   placeholder="Enter last name"
                                   autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label>Barangay</label>
                            <input type="text" 
                                   id="barangaySearch" 
                                   name="barangay"
                                   class="form-control" 
                                   placeholder="Enter barangay"
                                   autocomplete="off">
                        </div>
                    </div>
                    
                    <div class="search-actions">
                        <button type="button" class="btn btn-primary" onclick="searchPatients()">
                            <i class="fas fa-search"></i> Search Patients
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="clearSearch()">
                            <i class="fas fa-times"></i> Clear Search
                        </button>
                    </div>
                </form>
            </div>

            <!-- Patient Results Section -->
            <div class="patient-results" id="patientResults" style="display: none;">
                <h3><i class="fas fa-users"></i> Search Results</h3>
                
                <div id="loading" class="loading" style="display: none;">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Searching patients...</p>
                </div>
                
                <div id="emptySearch" class="empty-search" style="display: none;">
                    <i class="fas fa-user-search"></i>
                    <h4>No patients found</h4>
                    <p>Try adjusting your search criteria or check the spelling.</p>
                </div>
                
                <div id="searchResults" class="table-container" style="display: none;">
                    <table class="patients-table">
                        <thead>
                            <tr>
                                <th>Select</th>
                                <th>Patient ID</th>
                                <th>Full Name</th>
                                <th>Age/Sex</th>
                                <th>Barangay</th>
                                <th>Contact</th>
                                <th>Today's Status</th>
                            </tr>
                        </thead>
                        <tbody id="patientsTableBody">
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Selected Patient Info -->
            <?php if ($selected_patient_data): ?>
            <div class="selected-patient-info" style="display: block;">
                <strong>Selected Patient:</strong> <?= htmlspecialchars($selected_patient_data['full_name']) ?>
                <?php if ($saved_vitals_data): ?>
                    <span style="color: #28a745;"> (Vitals recorded today)</span>
                <?php else: ?>
                    <span style="color: #856404;"> (No vitals today)</span>
                <?php endif; ?><br>
                <small>ID: <?= htmlspecialchars($selected_patient_data['patient_code']) ?> | 
                Age/Sex: <?= htmlspecialchars(($selected_patient_data['age'] ?? '-') . '/' . $selected_patient_data['sex']) ?> | 
                Barangay: <?= htmlspecialchars($selected_patient_data['barangay']) ?></small>
            </div>
            <?php else: ?>
            <div class="selected-patient-info" id="selectedPatientInfo">
                <strong>Selected Patient:</strong> <span id="selectedPatientName"></span><br>
                <small>ID: <span id="selectedPatientId"></span> | Age/Sex: <span id="selectedPatientAge"></span> | Barangay: <span id="selectedPatientBarangay"></span></small>
            </div>
            <?php endif; ?>

            <!-- Vitals Form Section -->
            <div class="form-section <?= $selected_patient_data ? 'enabled' : '' ?>" id="vitalsSection">
                <form class="profile-card" id="vitalsForm" method="post">
                    <input type="hidden" name="action" value="save_vitals">
                    <input type="hidden" name="patient_id" id="vitalsPatientId" value="<?= $selected_patient_data ? htmlspecialchars($selected_patient_data['patient_id']) : '' ?>">
                    
                    <h3><i class="fas fa-heartbeat"></i> Patient Vital Signs</h3>
                    <p class="text-muted">Record patient's vital signs measurements</p>
                    
                    <?php if ($saved_vitals_data): ?>
                    <div style="background: #d4edda; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border-left: 4px solid #28a745;">
                        <strong><i class="fas fa-check-circle"></i> Current Vitals (Recorded Today)</strong><br>
                        <small>Recorded by: <?= htmlspecialchars(($saved_vitals_data['recorded_by_name'] ?? '') . ' ' . ($saved_vitals_data['recorded_by_lastname'] ?? '')) ?> 
                        at <?= date('g:i A', strtotime($saved_vitals_data['recorded_at'])) ?></small>
                    </div>
                    <?php endif; ?>
                    
                    <div class="vitals-grid">
                        <div class="form-group">
                            <label>Systolic BP (mmHg)</label>
                            <input type="number" name="systolic_bp" class="form-control" placeholder="120" min="60" max="300"
                                   value="<?= $saved_vitals_data ? htmlspecialchars($saved_vitals_data['systolic_bp']) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Diastolic BP (mmHg)</label>
                            <input type="number" name="diastolic_bp" class="form-control" placeholder="80" min="40" max="200"
                                   value="<?= $saved_vitals_data ? htmlspecialchars($saved_vitals_data['diastolic_bp']) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Heart Rate (bpm)</label>
                            <input type="number" name="heart_rate" class="form-control" placeholder="72" min="40" max="200"
                                   value="<?= $saved_vitals_data ? htmlspecialchars($saved_vitals_data['heart_rate']) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Respiratory Rate (/min)</label>
                            <input type="number" name="respiratory_rate" class="form-control" placeholder="16" min="8" max="60"
                                   value="<?= $saved_vitals_data ? htmlspecialchars($saved_vitals_data['respiratory_rate']) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Temperature (°C)</label>
                            <input type="number" name="temperature" step="0.1" class="form-control" placeholder="36.5" min="35" max="42"
                                   value="<?= $saved_vitals_data ? htmlspecialchars($saved_vitals_data['temperature']) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Weight (kg)</label>
                            <input type="number" name="weight" step="0.1" class="form-control" placeholder="70.0" min="1" max="300"
                                   value="<?= $saved_vitals_data ? htmlspecialchars($saved_vitals_data['weight']) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Height (cm)</label>
                            <input type="number" name="height" step="0.1" class="form-control" placeholder="170" min="50" max="250"
                                   value="<?= $saved_vitals_data ? htmlspecialchars($saved_vitals_data['height']) : '' ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Vitals Remarks</label>
                        <textarea name="vitals_remarks" class="form-control" placeholder="Additional notes about vital signs..." rows="2"><?= $saved_vitals_data ? htmlspecialchars($saved_vitals_data['remarks']) : '' ?></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> <?= $saved_vitals_data ? 'Update Vital Signs' : 'Save Vital Signs' ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Consultation Form Section -->
            <?php if (in_array($employee_role, ['admin', 'doctor', 'pharmacist'])): ?>
            <div class="form-section <?= $selected_patient_data ? 'enabled' : '' ?>" id="consultationSection">
                <form class="profile-card" id="consultationForm" method="post">
                    <input type="hidden" name="action" value="save_consultation">
                    <input type="hidden" name="patient_id" id="consultationPatientId" value="<?= $selected_patient_data ? htmlspecialchars($selected_patient_data['patient_id']) : '' ?>">
                    <input type="hidden" name="vitals_id" id="consultationVitalsId" value="<?= $saved_vitals_data ? htmlspecialchars($saved_vitals_data['vitals_id']) : '' ?>">
                    
                    <h3><i class="fas fa-stethoscope"></i> Clinical Consultation</h3>
                    <p class="text-muted">Complete the consultation with clinical findings and diagnosis</p>
                    
                    <?php if ($saved_vitals_data): ?>
                    <div style="background: #e8f5e8; padding: 1rem; border-radius: 8px; margin: 0.5rem 0; border-left: 4px solid #28a745;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-link" style="color: #28a745;"></i>
                            <strong style="color: #1e7e34;">Vitals Auto-Linking Enabled</strong>
                        </div>
                        <div style="font-size: 0.9rem; color: #155724; margin-top: 0.5rem;">
                            <i class="fas fa-check-circle"></i> Today's vitals (ID: <?= $saved_vitals_data['vitals_id'] ?>) will be automatically linked to this consultation.<br>
                            <small>Recorded at <?= date('g:i A', strtotime($saved_vitals_data['recorded_at'])) ?> by <?= htmlspecialchars(($saved_vitals_data['recorded_by_name'] ?? '') . ' ' . ($saved_vitals_data['recorded_by_lastname'] ?? '')) ?></small>
                        </div>
                    </div>
                    <?php elseif ($selected_patient_data): ?>
                    <div style="background: #fff3cd; padding: 1rem; border-radius: 8px; margin: 0.5rem 0; border-left: 4px solid #ffc107;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-exclamation-triangle" style="color: #856404;"></i>
                            <strong style="color: #856404;">No Vitals Today</strong>
                        </div>
                        <div style="font-size: 0.9rem; color: #856404; margin-top: 0.5rem;">
                            <i class="fas fa-info-circle"></i> No vitals recorded today. You can record vitals first or create consultation without vitals linking.
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="consultation-grid">
                        <div class="form-group">
                            <label>Chief Complaint *</label>
                            <textarea name="chief_complaint" class="form-control" placeholder="Patient's main concern or reason for visit..." required rows="3"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>Diagnosis</label>
                            <textarea name="diagnosis" class="form-control" placeholder="Clinical diagnosis or assessment..." rows="3"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>Treatment Plan</label>
                            <textarea name="treatment_plan" class="form-control" placeholder="Treatment plan and recommendations..." rows="4"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                <input type="checkbox" id="requireFollowUp" onchange="toggleFollowUpDate()" style="transform: scale(1.2);">
                                <label for="requireFollowUp" style="margin: 0; cursor: pointer;">Schedule Follow-up Date</label>
                            </div>
                            <input type="date" name="follow_up_date" id="followUpDateInput" class="form-control" min="<?= date('Y-m-d') ?>" style="display: none;" disabled>
                        </div>
                        
                        <div class="form-group">
                            <label>Remarks</label>
                            <textarea name="remarks" class="form-control" placeholder="Additional notes or observations..." rows="3"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label>Consultation Status</label>
                            <select name="consultation_status" class="form-control">
                                <option value="ongoing">Ongoing</option>
                                <option value="completed">Completed</option>
                                <option value="awaiting_lab_results">Awaiting Lab Results</option>
                                <option value="awaiting_followup">Awaiting Follow-up</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check-circle"></i> Save Consultation
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <script>
        let selectedPatient = null;

        document.addEventListener('DOMContentLoaded', function() {
            // Check if we have a patient selected from PHP (after form submission)
            <?php if ($selected_patient_data): ?>
            selectedPatient = {
                id: <?= $selected_patient_data['patient_id'] ?>,
                name: '<?= addslashes($selected_patient_data['full_name']) ?>',
                code: '<?= addslashes($selected_patient_data['patient_code']) ?>',
                age: '<?= $selected_patient_data['age'] ?? '-' ?>',
                sex: '<?= addslashes($selected_patient_data['sex']) ?>',
                barangay: '<?= addslashes($selected_patient_data['barangay']) ?>',
                vitalsId: <?= $saved_vitals_data ? $saved_vitals_data['vitals_id'] : 'null' ?>
            };
            
            // Load vitals for the selected patient (even if already saved)
            loadPatientVitals(selectedPatient.id);
            <?php endif; ?>
            
            // Add enter key functionality to search fields
            document.querySelectorAll('#searchForm input').forEach(input => {
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        searchPatients();
                    }
                });
            });
        });

        function searchPatients() {
            const formData = new FormData(document.getElementById('searchForm'));
            const searchParams = new URLSearchParams();
            
            // Add non-empty fields to search
            for (let [key, value] of formData.entries()) {
                if (value.trim()) {
                    searchParams.append(key, value.trim());
                }
            }
            
            // Check if at least one field is filled
            if (searchParams.toString() === '') {
                showError('Please enter at least one search criteria.');
                return;
            }
            
            const resultsContainer = document.getElementById('patientResults');
            const loadingElement = document.getElementById('loading');
            const emptySearchElement = document.getElementById('emptySearch');
            const searchResults = document.getElementById('searchResults');
            
            resultsContainer.style.display = 'block';
            loadingElement.style.display = 'block';
            emptySearchElement.style.display = 'none';
            searchResults.style.display = 'none';
            
            fetch(`?action=search_patients&${searchParams.toString()}`)
                .then(response => response.json())
                .then(data => {
                    loadingElement.style.display = 'none';
                    
                    if (data.error) {
                        showError('Error searching patients: ' + data.message);
                        return;
                    }
                    
                    if (data.length === 0) {
                        emptySearchElement.style.display = 'block';
                        searchResults.style.display = 'none';
                        return;
                    }
                    
                    displayPatientsTable(data);
                })
                .catch(error => {
                    loadingElement.style.display = 'none';
                    showError('Network error: ' + error.message);
                });
        }

        function clearSearch() {
            // Clear all form fields
            document.getElementById('searchForm').reset();
            
            // Hide results
            document.getElementById('patientResults').style.display = 'none';
            
            // Only clear selected patient if we don't have PHP-loaded data
            <?php if (!$selected_patient_data): ?>
            clearSelectedPatient();
            <?php endif; ?>
        }

        function displayPatientsTable(patients) {
            const searchResults = document.getElementById('searchResults');
            const tableBody = document.getElementById('patientsTableBody');
            
            tableBody.innerHTML = patients.map(patient => `
                <tr onclick="selectPatientFromTable(this, ${patient.patient_id}, '${patient.full_name}', '${patient.patient_code}', '${patient.age || '-'}', '${patient.sex}', '${patient.barangay}', ${patient.today_vitals_id || 'null'})">
                    <td>
                        <input type="radio" 
                               name="selected_patient" 
                               value="${patient.patient_id}" 
                               class="patient-radio"
                               onchange="selectPatientFromTable(this.closest('tr'), ${patient.patient_id}, '${patient.full_name}', '${patient.patient_code}', '${patient.age || '-'}', '${patient.sex}', '${patient.barangay}', ${patient.today_vitals_id || 'null'})">
                    </td>
                    <td><strong>${patient.patient_code}</strong></td>
                    <td class="patient-name">${patient.full_name}</td>
                    <td>${patient.age || '-'}/${patient.sex}</td>
                    <td>${patient.barangay}</td>
                    <td>${patient.contact_number || '-'}</td>
                    <td>
                        <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                            <span class="patient-status ${patient.has_vitals_today ? 'status-completed' : 'status-pending'}">
                                <i class="fas fa-heartbeat"></i> ${patient.has_vitals_today ? 'Vitals Recorded' : 'No Vitals'}
                            </span>
                            <span class="patient-status ${patient.has_consultation_today ? 'status-completed' : 'status-none'}">
                                <i class="fas fa-stethoscope"></i> ${patient.has_consultation_today ? 'Consultation Created' : 'New Consultation'}
                            </span>
                        </div>
                    </td>
                </tr>
            `).join('');
            
            searchResults.style.display = 'block';
        }

        function selectPatientFromTable(row, patientId, fullName, patientCode, age, sex, barangay, vitalsId) {
            selectedPatient = {
                id: patientId,
                name: fullName,
                code: patientCode,
                age: age,
                sex: sex,
                barangay: barangay,
                vitalsId: vitalsId
            };
            
            // Remove selection from all rows
            document.querySelectorAll('.patients-table tbody tr').forEach(tr => {
                tr.classList.remove('selected');
            });
            
            // Add selection to clicked row
            row.classList.add('selected');
            
            // Check the radio button
            const radio = row.querySelector('.patient-radio');
            if (radio) {
                radio.checked = true;
            }
            
            // Update selected patient info with vitals status
            let vitalsStatus = vitalsId ? ' (Vitals recorded today)' : ' (No vitals today)';
            document.getElementById('selectedPatientName').textContent = fullName + vitalsStatus;
            document.getElementById('selectedPatientId').textContent = patientCode;
            document.getElementById('selectedPatientAge').textContent = `${age}/${sex}`;
            document.getElementById('selectedPatientBarangay').textContent = barangay;
            document.getElementById('selectedPatientInfo').style.display = 'block';
            
            // Enable forms and populate patient IDs
            document.getElementById('vitalsSection').classList.add('enabled');
            document.getElementById('vitalsPatientId').value = patientId;
            
            <?php if (in_array($employee_role, ['admin', 'doctor', 'pharmacist'])): ?>
            document.getElementById('consultationSection').classList.add('enabled');
            document.getElementById('consultationPatientId').value = patientId;
            // Auto-link today's vitals if available
            document.getElementById('consultationVitalsId').value = vitalsId || '';
            
            // Show vitals info in consultation form if available
            if (vitalsId) {
                showVitalsInfo('Vitals from today will be automatically linked to this consultation.');
            } else {
                showVitalsInfo('No vitals recorded today. You can record vitals first or create consultation without vitals.');
            }
            <?php endif; ?>
            
            // Load today's vitals for this patient and populate the vitals form
            loadPatientVitals(patientId);
        }

        function clearSelectedPatient() {
            // Only clear if we don't have PHP-loaded patient data
            <?php if (!$selected_patient_data): ?>
            selectedPatient = null;
            
            // Hide selected patient info
            document.getElementById('selectedPatientInfo').style.display = 'none';
            
            // Disable forms
            document.getElementById('vitalsSection').classList.remove('enabled');
            
            <?php if (in_array($employee_role, ['admin', 'doctor', 'pharmacist'])): ?>
            document.getElementById('consultationSection').classList.remove('enabled');
            
            // Remove vitals info
            const vitalsInfo = document.getElementById('vitalsInfo');
            if (vitalsInfo) {
                vitalsInfo.remove();
            }
            <?php endif; ?>
            
            // Clear form fields
            document.getElementById('vitalsPatientId').value = '';
            document.getElementById('consultationPatientId').value = '';
            document.getElementById('consultationVitalsId').value = '';
            <?php endif; ?>
        }

        function showVitalsInfo(message) {
            // Create or update vitals info display
            let vitalsInfo = document.getElementById('vitalsInfo');
            if (!vitalsInfo) {
                vitalsInfo = document.createElement('div');
                vitalsInfo.id = 'vitalsInfo';
                vitalsInfo.style.cssText = 'background: #e3f2fd; padding: 0.75rem; border-radius: 6px; margin: 0.5rem 0; font-size: 0.9rem; color: #1976d2; border-left: 4px solid #2196f3;';
                document.getElementById('consultationSection').querySelector('.profile-card').insertBefore(vitalsInfo, document.getElementById('consultationSection').querySelector('.consultation-grid'));
            }
            vitalsInfo.innerHTML = `<i class="fas fa-info-circle"></i> ${message}`;
        }

        function loadPatientVitals(patientId) {
            // Fetch today's vitals for this patient
            fetch(`?action=get_patient_vitals&patient_id=${patientId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.vitals) {
                        // Populate vitals form with today's data
                        populateVitalsForm(data.vitals);
                        
                        // Update vitals status info
                        updateVitalsStatusDisplay(data.vitals);
                    } else {
                        // Clear vitals form if no data
                        clearVitalsForm();
                    }
                })
                .catch(error => {
                    console.error('Error loading patient vitals:', error);
                    clearVitalsForm();
                });
        }

        function populateVitalsForm(vitalsData) {
            // Populate form fields with vitals data
            const vitalsForm = document.getElementById('vitalsForm');
            
            if (vitalsData.systolic_bp) vitalsForm.querySelector('[name="systolic_bp"]').value = vitalsData.systolic_bp;
            if (vitalsData.diastolic_bp) vitalsForm.querySelector('[name="diastolic_bp"]').value = vitalsData.diastolic_bp;
            if (vitalsData.heart_rate) vitalsForm.querySelector('[name="heart_rate"]').value = vitalsData.heart_rate;
            if (vitalsData.respiratory_rate) vitalsForm.querySelector('[name="respiratory_rate"]').value = vitalsData.respiratory_rate;
            if (vitalsData.temperature) vitalsForm.querySelector('[name="temperature"]').value = vitalsData.temperature;
            if (vitalsData.weight) vitalsForm.querySelector('[name="weight"]').value = vitalsData.weight;
            if (vitalsData.height) vitalsForm.querySelector('[name="height"]').value = vitalsData.height;
            if (vitalsData.remarks) vitalsForm.querySelector('[name="vitals_remarks"]').value = vitalsData.remarks;
            
            // Update button text to "Update"
            const submitButton = vitalsForm.querySelector('button[type="submit"]');
            submitButton.innerHTML = '<i class="fas fa-save"></i> Update Vital Signs';
            
            // Show current vitals info
            let currentVitalsDiv = vitalsForm.querySelector('.current-vitals-info');
            if (!currentVitalsDiv) {
                currentVitalsDiv = document.createElement('div');
                currentVitalsDiv.className = 'current-vitals-info';
                currentVitalsDiv.style.cssText = 'background: #d4edda; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border-left: 4px solid #28a745;';
                
                const vitalsHeader = vitalsForm.querySelector('h3');
                vitalsHeader.insertAdjacentElement('afterend', currentVitalsDiv);
            }
            
            const recordedByName = (vitalsData.recorded_by_name || '') + ' ' + (vitalsData.recorded_by_lastname || '');
            const recordedTime = new Date(vitalsData.recorded_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            
            currentVitalsDiv.innerHTML = `
                <strong><i class="fas fa-check-circle"></i> Current Vitals (Recorded Today)</strong><br>
                <small>Recorded by: ${recordedByName.trim()} at ${recordedTime}</small>
            `;
        }

        function clearVitalsForm() {
            // Clear all vitals form fields
            const vitalsForm = document.getElementById('vitalsForm');
            vitalsForm.reset();
            
            // Reset button text to "Save"
            const submitButton = vitalsForm.querySelector('button[type="submit"]');
            submitButton.innerHTML = '<i class="fas fa-save"></i> Save Vital Signs';
            
            // Remove current vitals info if exists
            const currentVitalsDiv = vitalsForm.querySelector('.current-vitals-info');
            if (currentVitalsDiv) {
                currentVitalsDiv.remove();
            }
        }

        function updateVitalsStatusDisplay(vitalsData) {
            // Update the selected patient info to show vitals status
            const selectedPatientInfo = document.getElementById('selectedPatientInfo');
            if (selectedPatientInfo && selectedPatient) {
                const vitalsStatus = vitalsData ? ' (Vitals recorded today)' : ' (No vitals today)';
                const patientNameSpan = selectedPatientInfo.querySelector('#selectedPatientName');
                if (patientNameSpan) {
                    patientNameSpan.textContent = selectedPatient.name + vitalsStatus;
                }
                
                // Update consultation form vitals ID
                <?php if (in_array($employee_role, ['admin', 'doctor', 'pharmacist'])): ?>
                if (vitalsData && vitalsData.vitals_id) {
                    document.getElementById('consultationVitalsId').value = vitalsData.vitals_id;
                    showVitalsInfo('Vitals from today will be automatically linked to this consultation.');
                } else {
                    document.getElementById('consultationVitalsId').value = '';
                    showVitalsInfo('No vitals recorded today. You can record vitals first or create consultation without vitals.');
                }
                <?php endif; ?>
            }
        }

        function toggleFollowUpDate() {
            const checkbox = document.getElementById('requireFollowUp');
            const dateInput = document.getElementById('followUpDateInput');
            
            if (checkbox.checked) {
                // Show date input and enable it
                dateInput.style.display = 'block';
                dateInput.disabled = false;
                dateInput.focus();
            } else {
                // Hide date input, disable it, and clear value
                dateInput.style.display = 'none';
                dateInput.disabled = true;
                dateInput.value = '';
            }
        }

        function showError(message) {
            alert('Error: ' + message);
        }

        function showSuccess(message) {
            alert(message);
        }
    </script>
</body>
</html>