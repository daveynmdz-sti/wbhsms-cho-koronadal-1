<?php
// Start output buffering to prevent header issues
ob_start();

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in - use session management function
if (!is_employee_logged_in()) {
    redirect_to_employee_login();
}

// Define role-based permissions for prescription creation
$canCreatePrescriptions = in_array($_SESSION['role_id'], [1, 2]); // admin, doctor ONLY - pharmacists cannot create prescriptions

if (!$canCreatePrescriptions) {
    $role_id = $_SESSION['role_id'];
    // Map role_id to role name for redirect
    $roleMap = [
        1 => 'admin',
        2 => 'doctor',
        3 => 'nurse',
        4 => 'pharmacist',
        5 => 'dho',
        6 => 'bhw',
        7 => 'records_officer',
        8 => 'cashier',
        9 => 'laboratory_tech'
    ];
    $role = $roleMap[$role_id] ?? 'employee';
    header("Location: ../management/$role/dashboard.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $patient_id = (int)($_POST['patient_id'] ?? 0);
        $medications = $_POST['medications'] ?? [];
        $remarks = trim($_POST['remarks'] ?? '');

        if (!$patient_id) {
            throw new Exception('Please select a patient.');
        }

        if (empty($medications)) {
            throw new Exception('Please add at least one medication.');
        }

        // Validate patient exists and is active
        $patient_stmt = $conn->prepare("SELECT patient_id, first_name, last_name, status FROM patients WHERE patient_id = ?");
        $patient_stmt->bind_param("i", $patient_id);
        $patient_stmt->execute();
        $patient_result = $patient_stmt->get_result();

        if ($patient_result->num_rows === 0) {
            throw new Exception('Patient not found.');
        }

        $patient = $patient_result->fetch_assoc();

        // Validate patient is active
        if ($patient['status'] !== 'active') {
            throw new Exception('Prescriptions can only be created for active patients.');
        }

        // Start transaction
        $conn->begin_transaction();

        try {
            // Create prescription record - standalone (consultation_id, appointment_id, visit_id can be NULL)
            $prescription_stmt = $conn->prepare("
                INSERT INTO prescriptions (
                    patient_id, 
                    consultation_id,
                    appointment_id,
                    visit_id,
                    prescribed_by_employee_id, 
                    prescription_date, 
                    status, 
                    overall_status,
                    remarks,
                    created_at
                ) VALUES (?, NULL, NULL, NULL, ?, NOW(), 'issued', 'issued', ?, NOW())
            ");

            $prescription_stmt->bind_param("iis", $patient_id, $_SESSION['employee_id'], $remarks);

            if (!$prescription_stmt->execute()) {
                throw new Exception('Failed to create prescription: ' . $conn->error);
            }

            $prescription_id = $conn->insert_id;

            // Add medications
            $medication_stmt = $conn->prepare("
                INSERT INTO prescribed_medications (
                    prescription_id,
                    medication_name,
                    dosage,
                    frequency,
                    duration,
                    instructions,
                    status,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");

            foreach ($medications as $medication) {
                if (empty(trim($medication['medication_name']))) continue;

                // Store trimmed values in variables for bind_param reference
                $med_name = trim($medication['medication_name']);
                $med_dosage = trim($medication['dosage'] ?? '');
                $med_frequency = trim($medication['frequency'] ?? '');
                $med_duration = trim($medication['duration'] ?? '');
                $med_instructions = trim($medication['instructions'] ?? '');

                $medication_stmt->bind_param(
                    "isssss",
                    $prescription_id,
                    $med_name,
                    $med_dosage,
                    $med_frequency,
                    $med_duration,
                    $med_instructions
                );

                if (!$medication_stmt->execute()) {
                    throw new Exception('Failed to add medication: ' . $conn->error);
                }
            }

            $conn->commit();
            $_SESSION['success_message'] = "Prescription created successfully for " . $patient['first_name'] . " " . $patient['last_name'];

            // Clean all output buffers before redirect to prevent header issues
            while (ob_get_level()) {
                ob_end_clean();
            }
            header("Location: prescription_management.php");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }
}

// Patient search functionality
$search_query = trim($_GET['search'] ?? '');
$barangay_filter = trim($_GET['barangay'] ?? '');

$patients = [];
// Search when either search query or barangay filter has a value, or show recent patients by default
if (!empty($search_query) || !empty($barangay_filter)) {
    $where_conditions = [];
    $params = [];
    $param_types = '';

    if (!empty($search_query)) {
        // Search in patient ID, first name, last name, and full name
        $where_conditions[] = "(p.patient_id LIKE ? OR p.username LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)";
        $search_term = "%$search_query%";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term]);
        $param_types .= 'sssss';
    }

    if (!empty($barangay_filter)) {
        $where_conditions[] = "b.barangay_name = ?";
        $params[] = $barangay_filter;
        $param_types .= 's';
    }

    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $sql = "
        SELECT p.patient_id, p.username, p.first_name, p.middle_name, p.last_name, 
               p.sex, p.contact_number, p.date_of_birth, b.barangay_name as barangay,
               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
        FROM patients p 
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        " . (!empty($where_clause) ? $where_clause . " AND p.status = 'active'" : "WHERE p.status = 'active'") . "
        ORDER BY p.last_name, p.first_name
        LIMIT 20
    ";

    try {
        if (!empty($params)) {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($param_types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $patients = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            // If no params, execute query directly
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $result = $stmt->get_result();
            $patients = $result->fetch_all(MYSQLI_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Patient search error: " . $e->getMessage());
        $_SESSION['error_message'] = "Search error: " . $e->getMessage();
        $patients = [];
    }
} else {
    // Show recent patients when no search parameters
    $sql = "
        SELECT p.patient_id, p.username, p.first_name, p.middle_name, p.last_name, 
               p.sex, p.contact_number, p.date_of_birth, b.barangay_name as barangay,
               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
        FROM patients p 
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.status = 'active'
        ORDER BY p.created_at DESC, p.last_name, p.first_name
        LIMIT 10
    ";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $result = $stmt->get_result();
        $patients = $result->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        error_log("Patient default load error: " . $e->getMessage());
        $patients = [];
    }
}
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
    <title>Create Prescription | CHO Koronadal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="../../assets/css/topbar.css" />
    <link rel="stylesheet" href="../../assets/css/profile-edit-responsive.css" />
    <link rel="stylesheet" href="../../assets/css/profile-edit.css" />
    <link rel="stylesheet" href="../../assets/css/edit.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        .patient-search-container {
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

        .medication-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 2fr auto;
            gap: 1rem;
            align-items: start;
            margin-bottom: 1rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .medication-row:hover {
            border-color: #0077b6;
            box-shadow: 0 2px 8px rgba(0, 119, 182, 0.1);
        }

        .medication-row input,
        .medication-row textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .medication-row input:focus,
        .medication-row textarea:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
        }

        .medication-row textarea {
            resize: vertical;
            min-height: 80px;
        }

        .remove-medication {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border: none;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .remove-medication:hover {
            background: linear-gradient(135deg, #c82333, #a71e2a);
            transform: translateY(-2px);
        }

        .add-medication {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 10px;
            cursor: pointer;
            margin: 1rem 0;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .add-medication:hover {
            background: linear-gradient(135deg, #218838, #1e7e34);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }

        .medication-headers {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 2fr auto;
            gap: 1rem;
            margin-bottom: 1rem;
            font-weight: 700;
            color: #0077b6;
            font-size: 0.95em;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
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
            background-color: #f8f9fa;
            font-weight: 600;
        }

        .patient-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .patient-checkbox {
            transform: scale(1.2);
            margin: 0;
        }

        .empty-search {
            text-align: center;
            color: #6c757d;
            margin: 2rem 0;
        }

        .selected-patient {
            background-color: #e8f4fd !important;
        }

        .prescription-form {
            opacity: 0.6;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .prescription-form.enabled {
            opacity: 1;
            pointer-events: auto;
        }

        .selected-patient-info {
            background: #e8f4fd;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #0077b6;
        }

        .patient-table-container {
            overflow-x: auto;
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
            box-shadow: 0 4px 15px rgba(0, 119, 182, 0.3);
        }

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #023e8a, #001d3d);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 119, 182, 0.4);
        }

        .btn-secondary {
            background: #f8f9fa;
            color: #6c757d;
            border: 2px solid #dee2e6;
        }

        .btn-secondary:hover {
            background: #e9ecef;
            color: #5a6268;
            transform: translateY(-2px);
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

        .alert-info {
            background-color: #cce7ff;
            color: #004085;
            border: 1px solid #b3d9ff;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.7;
            margin-left: auto;
            padding: 0;
            color: inherit;
        }

        .btn-close:hover {
            opacity: 1;
        }

        .button-container {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            gap: 1rem;
        }

        .button-container .btn {
            width: auto;
            min-width: fit-content;
            flex: none;
        }

        @media (max-width: 768px) {
            .search-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .medication-row,
            .medication-headers {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }

            .medication-headers {
                display: none;
            }

            .patient-search-container {
                padding: 1rem;
            }

            .medication-row {
                padding: 1rem;
            }
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 10px;
            max-width: 80%;
            max-height: 80%;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }

        .close-btn {
            font-size: 1.5em;
            cursor: pointer;
            color: #aaa;
            border: none;
            background: none;
        }

        .close-btn:hover {
            color: #000;
        }

        /* Medication Selection Table Enhancements */
        #medicationsTable tbody tr[onclick] {
            transition: all 0.2s ease;
            cursor: pointer;
        }

        #medicationsTable tbody tr[onclick]:hover {
            background-color: #e8f4f8 !important;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        #medicationsTable tbody tr[onclick]:active {
            transform: translateY(0);
            background-color: #d1ecf1 !important;
        }

        .prescription-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .prescription-table th,
        .prescription-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            font-size: 0.9em;
        }

        .prescription-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #0077b6;
        }

        .prescription-table tr:hover {
            background-color: #f5f5f5;
        }

        /* Reminders Box Styling */
        .reminders-box {
            background: linear-gradient(135deg, #e3f2fd 0%, #f8f9fa 100%);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            border-left: 5px solid #0077b6;
            box-shadow: 0 4px 15px rgba(0, 119, 182, 0.1);
        }

        .reminders-header {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(0, 119, 182, 0.1);
        }

        .reminders-header h3 {
            color: #0077b6;
            font-size: 1.5rem;
            margin: 0 0 0.5rem 0;
            font-weight: 700;
        }

        .reminders-header p {
            color: #666;
            margin: 0;
            font-size: 1rem;
        }

        .reminders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            align-items: start;
        }

        .reminder-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 119, 182, 0.1);
            transition: all 0.3s ease;
        }

        .reminder-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 119, 182, 0.15);
            border-color: #0077b6;
        }

        .reminder-icon {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
            box-shadow: 0 3px 10px rgba(0, 119, 182, 0.3);
        }

        .reminder-content h4 {
            color: #0077b6;
            font-size: 1.1rem;
            margin: 0 0 0.5rem 0;
            font-weight: 600;
        }

        .reminder-content p {
            color: #666;
            margin: 0;
            line-height: 1.5;
            font-size: 0.95rem;
        }

        @media (max-width: 768px) {
            .reminders-box {
                padding: 1.5rem;
            }

            .reminders-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .reminder-item {
                padding: 1rem;
            }

            .reminder-icon {
                width: 40px;
                height: 40px;
                font-size: 1rem;
            }
        }

        /* Professional Patient Information Card Styles - Compact Version */
        .patient-info-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fffe 100%);
            border: 1px solid #e3f2fd;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            margin: 15px 0;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .patient-info-card:hover {
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }

        .patient-info-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 12px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
        }

        .patient-info-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="15" height="15" patternUnits="userSpaceOnUse"><path d="M 15 0 L 0 0 0 15" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="1"/></pattern></defs><rect width="100" height="100" fill="url(%23grid)"/></svg>');
            opacity: 0.4;
        }

        .patient-info-title {
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
            z-index: 1;
        }

        .patient-icon {
            font-size: 1.3rem;
            color: rgba(255, 255, 255, 0.9);
        }

        .patient-info-title h4 {
            margin: 0;
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .patient-status-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, 0.2);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            position: relative;
            z-index: 1;
        }

        .patient-status-badge i {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }

        .patient-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0;
            padding: 0;
        }

        .patient-info-item {
            display: flex;
            align-items: center;
            padding: 16px;
            border-bottom: 1px solid #f0f4f8;
            border-right: 1px solid #f0f4f8;
            transition: all 0.3s ease;
            position: relative;
            background: #ffffff;
        }

        .patient-info-item:hover {
            background: linear-gradient(135deg, #f8fdff 0%, #e8f5ff 100%);
        }

        .patient-info-item.primary {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border-left: 3px solid #28a745;
        }

        .patient-info-item.primary:hover {
            background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
        }

        .info-icon-wrapper {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.25);
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .patient-info-item:hover .info-icon-wrapper {
            transform: scale(1.05);
            box-shadow: 0 3px 10px rgba(40, 167, 69, 0.35);
        }

        .info-icon-wrapper i {
            font-size: 0.95rem;
        }

        .info-content {
            flex: 1;
            min-width: 0;
        }

        .info-content label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .info-value {
            font-size: 0.95rem;
            font-weight: 600;
            color: #1f2937;
            display: block;
            word-break: break-word;
        }

        .info-value.loading {
            color: #6b7280;
            font-style: italic;
            position: relative;
        }

        .info-value.loading::after {
            content: '';
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 1.5px solid #e5e7eb;
            border-radius: 50%;
            border-top-color: #28a745;
            animation: spin 1s ease-in-out infinite;
            margin-left: 6px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive Design for Patient Info Card */
        @media (max-width: 768px) {
            .patient-info-grid {
                grid-template-columns: 1fr;
            }

            .patient-info-item {
                padding: 14px;
                border-right: none;
            }

            .patient-info-header {
                padding: 10px 16px;
                flex-direction: column;
                gap: 8px;
                text-align: center;
            }

            .patient-info-title h4 {
                font-size: 0.9rem;
            }

            .info-icon-wrapper {
                width: 32px;
                height: 32px;
                margin-right: 10px;
            }

            .info-icon-wrapper i {
                font-size: 0.85rem;
            }
        }

        @media (max-width: 480px) {
            .patient-info-card {
                border-radius: 8px;
                margin: 10px 0;
            }

            .patient-info-item {
                padding: 12px;
            }

            .info-icon-wrapper {
                width: 30px;
                height: 30px;
                margin-right: 8px;
            }

            .info-content label {
                font-size: 0.7rem;
            }

            .info-value {
                font-size: 0.85rem;
            }

            .patient-status-badge {
                padding: 4px 8px;
                font-size: 0.75rem;
            }
        }
    </style>
</head>

<body>
    <?php
    // Include reusable topbar component
    require_once $root_path . '/includes/topbar.php';

    // Render topbar
    renderTopbar([
        'title' => 'Create New Prescription',
        'back_url' => 'prescription_management.php',
        'user_type' => 'employee',
        'vendor_path' => '../../vendor/'
    ]);
    ?>

    <section class="homepage">
        <?php
        // Render back button with modal
        renderBackButton([
            'back_url' => 'prescription_management.php',
            'button_text' => '← Back / Cancel',
            'modal_title' => 'Cancel Creating Prescription?',
            'modal_message' => 'Are you sure you want to go back/cancel? Unsaved changes will be lost.',
            'confirm_text' => 'Yes, Cancel',
            'stay_text' => 'Stay'
        ]);
        ?>

        <div class="profile-wrapper" style="max-width:1300px;">
            <!-- Reminders Box -->
            <div class="reminders-box">
                <div class="reminders-header">
                    <h3><i class="fas fa-lightbulb"></i> Important Guidelines</h3>
                    <!--<p>Please review these important guidelines before creating a prescription</p>-->
                </div>
                <div class="reminders-grid">
                    <div class="reminder-item">
                        <div class="reminder-icon">
                            <i class="fas fa-prescription-bottle-alt"></i>
                        </div>
                        <div class="reminder-content">
                            <h4>Prescription Guidelines</h4>
                            <p>Always verify patient allergies and current medications before prescribing.</p>
                        </div>
                    </div>
                    <div class="reminder-item">
                        <div class="reminder-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <div class="reminder-content">
                            <h4>Patient Selection</h4>
                            <p>Select the patient from the search results below to begin creating a prescription.</p>
                        </div>
                    </div>
                    <div class="reminder-item">
                        <div class="reminder-icon">
                            <i class="fas fa-pills"></i>
                        </div>
                        <div class="reminder-content">
                            <h4>Medication Details</h4>
                            <p>Include proper dosage, frequency, duration, and special instructions for each medication.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Display messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Patient Search Section -->
            <div class="form-section">
                <div class="search-container">
                    <h3><i class="fas fa-search"></i> Search Patient</h3>
                    <form method="GET" class="search-grid" id="patientSearchForm">
                        <div class="form-group">
                            <label for="search">Patient Search</label>
                            <input type="text" id="search" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                                placeholder="Search by Patient ID, First Name, or Last Name...">
                        </div>
                        <div class="form-group">
                            <label for="barangay">Barangay</label>
                            <select id="barangay" name="barangay">
                                <option value="">All Barangays</option>
                                <?php
                                // Get unique barangays from barangay table
                                $selectedBarangay = $_GET['barangay'] ?? '';
                                try {
                                    $barangayQuery = "SELECT DISTINCT barangay_name FROM barangay WHERE status = 'active' ORDER BY barangay_name";
                                    $barangayResult = $conn->query($barangayQuery);
                                    if ($barangayResult) {
                                        while ($barangay = $barangayResult->fetch_assoc()) {
                                            $selected = $selectedBarangay === $barangay['barangay_name'] ? 'selected' : '';
                                            echo "<option value='" . htmlspecialchars($barangay['barangay_name']) . "' $selected>" . htmlspecialchars($barangay['barangay_name']) . "</option>";
                                        }
                                    }
                                } catch (Exception $e) {
                                    // If barangay table doesn't exist, show default options
                                    $defaultBarangays = [
                                        'Brgy. Assumption',
                                        'Brgy. Caloocan',
                                        'Brgy. Carpenter Hill',
                                        'Brgy. Concepcion',
                                        'Brgy. Esperanza',
                                        'Brgy. Topland',
                                        'Brgy. Zone I',
                                        'Brgy. Zone II',
                                        'Brgy. Zone III',
                                        'Brgy. Zone IV'
                                    ];
                                    foreach ($defaultBarangays as $barangay) {
                                        $selected = $selectedBarangay === $barangay ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($barangay) . "' $selected>" . htmlspecialchars($barangay) . "</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group" style="display: flex;flex-direction: row;gap:10px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="clearFilters()">
                                <i class="fas fa-times"></i> Clear Filters
                            </button>
                        </div>

                    </form>
                </div>
            </div>

            <!-- Patient Results Section -->
            <div class="form-section">
                <div class="patient-table">
                    <h3><i class="fas fa-users"></i> Patient Search Results</h3>

                    <!-- Debug info (remove in production) -->
                    <?php if (isset($_GET['debug'])): ?>
                        <div style="background: #f0f0f0; padding: 10px; margin: 10px 0; border-radius: 5px; font-family: monospace;">
                            <strong>Debug Info:</strong><br>
                            Search Query: "<?= htmlspecialchars($search_query) ?>"<br>
                            Barangay Filter: "<?= htmlspecialchars($barangay_filter) ?>"<br>
                            Patients Count: <?= count($patients) ?><br>
                            GET Parameters: <?= htmlspecialchars(print_r($_GET, true)) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($patients) && (!empty($search_query) || !empty($barangay_filter))): ?>
                        <div class="empty-search">
                            <i class="fas fa-user-times fa-2x"></i>
                            <p>No patients found matching your search criteria.</p>
                            <p>Try adjusting your search terms or check the spelling.</p>
                        </div>
                    <?php elseif (!empty($patients)): ?>
                        <?php if (!empty($search_query) || !empty($barangay_filter)): ?>
                            <p>Found <?= count($patients) ?> patient(s) matching your search. Select one to create a prescription:</p>
                        <?php else: ?>
                            <p>Showing <?= count($patients) ?> recent patients. Use the search form above to find specific patients:</p>
                        <?php endif; ?>

                        <!-- Desktop Table View -->
                        <div class="patient-table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Select</th>
                                        <th>Patient ID</th>
                                        <th>Name</th>
                                        <th>Age/Sex</th>
                                        <th>Barangay</th>
                                        <th>Contact</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($patients as $patient): ?>
                                        <tr class="patient-row" data-patient-id="<?= $patient['patient_id'] ?>">
                                            <td>
                                                <input type="radio" name="selected_patient" value="<?= $patient['patient_id'] ?>"
                                                    class="patient-checkbox" data-patient-name="<?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?>">
                                            </td>
                                            <td><?= htmlspecialchars($patient['username']) ?></td>
                                            <td>
                                                <?= htmlspecialchars($patient['first_name'] . ' ' .
                                                    ($patient['middle_name'] ? $patient['middle_name'] . ' ' : '') .
                                                    $patient['last_name']) ?>
                                            </td>
                                            <td><?= htmlspecialchars($patient['age']) ?> / <?= htmlspecialchars($patient['sex']) ?></td>
                                            <td><?= htmlspecialchars($patient['barangay'] ?? 'Not specified') ?></td>
                                            <td><?= htmlspecialchars($patient['contact_number'] ?? 'N/A') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-search">
                            <i class="fas fa-search fa-2x"></i>
                            <p>Use the search form above to find patients.</p>
                            <p>Search results will appear here. Select a patient to create a prescription.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Prescription Form -->
            <div class="form-section">
                <form class="prescription-form" id="prescriptionForm" method="post">
                    <input type="hidden" name="patient_id" id="selectedPatientId">

                    <h3 style="margin-bottom: 15px;"><i class="fas fa-prescription-bottle-alt"></i> Create Prescription</h3>

                    <div id="selectedPatientInfo" class="selected-patient-info" style="display:none;">
                        <div class="patient-info-card">
                            <div class="patient-info-header">
                                <div class="patient-info-title">
                                    <i class="fas fa-user-circle patient-icon"></i>
                                    <h4>Selected Patient Information</h4>
                                </div>
                                <div class="patient-status-badge">
                                    <i class="fas fa-check-circle"></i>
                                    <span>Selected</span>
                                </div>
                            </div>
                            
                            <div class="patient-info-grid">
                                <div class="patient-info-item primary">
                                    <div class="info-icon-wrapper">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div class="info-content">
                                        <label>Patient Name</label>
                                        <span id="selectedPatientName" class="info-value">--</span>
                                    </div>
                                </div>
                                
                                <div class="patient-info-item">
                                    <div class="info-icon-wrapper">
                                        <i class="fas fa-birthday-cake"></i>
                                    </div>
                                    <div class="info-content">
                                        <label>Age</label>
                                        <span id="selectedPatientAge" class="info-value loading">Loading...</span>
                                    </div>
                                </div>
                                
                                <div class="patient-info-item">
                                    <div class="info-icon-wrapper">
                                        <i class="fas fa-venus-mars"></i>
                                    </div>
                                    <div class="info-content">
                                        <label>Sex</label>
                                        <span id="selectedPatientSex" class="info-value loading">Loading...</span>
                                    </div>
                                </div>
                                
                                <div class="patient-info-item">
                                    <div class="info-icon-wrapper">
                                        <i class="fas fa-ruler-vertical"></i>
                                    </div>
                                    <div class="info-content">
                                        <label>Height</label>
                                        <span id="selectedPatientHeight" class="info-value loading">Loading...</span>
                                    </div>
                                </div>
                                
                                <div class="patient-info-item">
                                    <div class="info-icon-wrapper">
                                        <i class="fas fa-weight"></i>
                                    </div>
                                    <div class="info-content">
                                        <label>Weight</label>
                                        <span id="selectedPatientWeight" class="info-value loading">Loading...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Prescription Details Section -->
                    <div class="form-section">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                            <div>
                                <h4 style="margin: 0;"><i class="fas fa-pills"></i> Prescription Details</h4>
                                <p style="margin: 5px 0 0 0; color: #666;">Add medications and their detailed instructions below.</p>
                            </div>
                            <div>
                                <button type="button" class="btn btn-secondary" onclick="openMedicationsModal()">
                                    <i class="fas fa-eye"></i> View Available Medications
                                </button>
                            </div>
                        </div>

                        <div class="medications-container">
                            <div id="medications-container">                                
                                <!-- Initial medication row -->
                                <div class="medication-row">
                                    <div class="form-group">
                                        <label>Medication Name</label>
                                        <input type="text" name="medications[0][medication_name]" placeholder="e.g., Paracetamol" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Dosage</label>
                                        <select name="medications[0][dosage]" onchange="handleDosageChange(this)">
                                            <option value="">Select dosage...</option>
                                            <!-- Pain/Fever Medications -->
                                            <optgroup label="Pain & Fever">
                                                <option value="250mg">250mg</option>
                                                <option value="500mg">500mg</option>
                                                <option value="650mg">650mg</option>
                                                <option value="200mg">200mg (Ibuprofen)</option>
                                                <option value="400mg">400mg (Ibuprofen)</option>
                                                <option value="80mg">80mg (Aspirin)</option>
                                                <option value="100mg">100mg (Aspirin)</option>
                                            </optgroup>
                                            <!-- Antibiotics -->
                                            <optgroup label="Antibiotics">
                                                <option value="250mg">250mg</option>
                                                <option value="500mg">500mg</option>
                                                <option value="875mg">875mg</option>
                                                <option value="1g">1g</option>
                                                <option value="100mg">100mg</option>
                                            </optgroup>
                                            <!-- Cardiovascular -->
                                            <optgroup label="Cardiovascular">
                                                <option value="2.5mg">2.5mg</option>
                                                <option value="5mg">5mg</option>
                                                <option value="10mg">10mg</option>
                                                <option value="20mg">20mg</option>
                                                <option value="25mg">25mg</option>
                                                <option value="50mg">50mg</option>
                                                <option value="100mg">100mg</option>
                                            </optgroup>
                                            <!-- Diabetes -->
                                            <optgroup label="Diabetes">
                                                <option value="500mg">500mg (Metformin)</option>
                                                <option value="850mg">850mg (Metformin)</option>
                                                <option value="1000mg">1000mg (Metformin)</option>
                                                <option value="2.5mg">2.5mg (Glibenclamide)</option>
                                                <option value="5mg">5mg (Glibenclamide)</option>
                                                <option value="100IU/mL">100IU/mL (Insulin)</option>
                                            </optgroup>
                                            <!-- Respiratory -->
                                            <optgroup label="Respiratory">
                                                <option value="2mg">2mg</option>
                                                <option value="4mg">4mg</option>
                                                <option value="100mcg/dose">100mcg/dose (Inhaler)</option>
                                                <option value="250mcg/dose">250mcg/dose (Inhaler)</option>
                                            </optgroup>
                                            <!-- Vitamins & Supplements -->
                                            <optgroup label="Vitamins & Supplements">
                                                <option value="325mg">325mg (Iron)</option>
                                                <option value="5mg">5mg (Folic Acid)</option>
                                                <option value="500mg">500mg (Calcium)</option>
                                                <option value="1 tablet">1 tablet</option>
                                                <option value="1 capsule">1 capsule</option>
                                            </optgroup>
                                            <!-- Topical -->
                                            <optgroup label="Topical">
                                                <option value="1%">1%</option>
                                                <option value="2%">2%</option>
                                                <option value="0.5%">0.5%</option>
                                                <option value="10%">10%</option>
                                                <option value="25%">25%</option>
                                            </optgroup>
                                            <!-- Eye/Ear Drops -->
                                            <optgroup label="Drops">
                                                <option value="0.3%">0.3%</option>
                                                <option value="0.5%">0.5%</option>
                                                <option value="0.9%">0.9%</option>
                                                <option value="1 drop">1 drop</option>
                                                <option value="2 drops">2 drops</option>
                                            </optgroup>
                                            <!-- Liquids -->
                                            <optgroup label="Liquids">
                                                <option value="5mL">5mL</option>
                                                <option value="10mL">10mL</option>
                                                <option value="15mL">15mL</option>
                                                <option value="1 sachet">1 sachet</option>
                                                <option value="1 teaspoon">1 teaspoon</option>
                                                <option value="1 tablespoon">1 tablespoon</option>
                                            </optgroup>
                                            <option value="custom">Other (specify)</option>
                                        </select>
                                        <input type="text" name="medications[0][dosage_custom]" placeholder="Enter custom dosage..." style="display:none; margin-top:5px;" onblur="updateDosageFromCustom(this)">
                                    </div>
                                    <div class="form-group">
                                        <label>Frequency</label>
                                        <select name="medications[0][frequency]" required>
                                            <option value="">Select frequency...</option>
                                            <option value="Once daily">Once daily</option>
                                            <option value="Twice daily">Twice daily</option>
                                            <option value="3 times daily">3 times daily</option>
                                            <option value="4 times daily">4 times daily</option>
                                            <option value="Every 4 hours">Every 4 hours</option>
                                            <option value="Every 6 hours">Every 6 hours</option>
                                            <option value="Every 8 hours">Every 8 hours</option>
                                            <option value="Every 12 hours">Every 12 hours</option>
                                            <option value="Before meals">Before meals</option>
                                            <option value="After meals">After meals</option>
                                            <option value="With meals">With meals</option>
                                            <option value="At bedtime">At bedtime</option>
                                            <option value="As needed">As needed</option>
                                            <option value="Weekly">Weekly</option>
                                            <option value="Monthly">Monthly</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Duration</label>
                                        <select name="medications[0][duration]" required>
                                            <option value="">Select duration...</option>
                                            <option value="1 day">1 day</option>
                                            <option value="3 days">3 days</option>
                                            <option value="5 days">5 days</option>
                                            <option value="7 days">7 days</option>
                                            <option value="10 days">10 days</option>
                                            <option value="14 days">14 days</option>
                                            <option value="21 days">21 days</option>
                                            <option value="1 month">1 month</option>
                                            <option value="2 months">2 months</option>
                                            <option value="3 months">3 months</option>
                                            <option value="6 months">6 months</option>
                                            <option value="1 year">1 year</option>
                                            <option value="Until finished">Until finished</option>
                                            <option value="As needed">As needed</option>
                                            <option value="Ongoing">Ongoing</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Instructions</label>
                                        <textarea name="medications[0][instructions]" placeholder="Additional instructions..."></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label>Actions</label>
                                        <button type="button" class="remove-medication" onclick="removeMedication(this)" style="display: none;">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <button type="button" class="add-medication" onclick="addMedication()">
                                <i class="fas fa-plus"></i> Add Another Medication
                            </button>
                        </div>
                    </div>

                    <!-- Additional Information Section -->
                    <div class="form-section">
                        <div class="section-header">
                            <h3><i class="fas fa-notes-medical"></i> Additional Information</h3>
                            <p>Add any special notes or instructions for this prescription.</p>
                        </div>

                        <div class="form-group">
                            <textarea name="additional_notes" id="additional_notes" rows="4"
                                placeholder="Any special instructions, warnings, or additional information for this prescription..."></textarea>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="button-container">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-prescription-bottle-alt"></i>
                                Create Prescription
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <script>
        let medicationCount = 1;

        // Ensure DOM is fully loaded before executing functions
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM fully loaded');

            // Initialize the page
            initializePage();

            // Add form validation
            const prescriptionForm = document.getElementById('prescriptionForm');
            if (prescriptionForm) {
                prescriptionForm.addEventListener('submit', validateForm);
                console.log('Form validation listener added');
            } else {
                console.error('Prescription form not found!');
            }

            // Log critical elements for debugging
            console.log('=== CRITICAL ELEMENTS CHECK ===');
            console.log('medications-container:', document.getElementById('medications-container'));
            console.log('prescriptionForm:', document.getElementById('prescriptionForm'));
            console.log('selectedPatientId:', document.getElementById('selectedPatientId'));
            console.log('=== END CRITICAL ELEMENTS CHECK ===');
        });

        function initializePage() {
            try {
                // Check if required elements exist
                const container = document.getElementById('medications-container');
                if (!container) {
                    console.error('Critical Error: medications-container not found on page load');
                    return;
                }

                // Update remove buttons on initial load
                updateRemoveButtons();

                console.log('Page initialized successfully');
            } catch (error) {
                console.error('Error initializing page:', error);
            }
        }

        // Safe alert function with fallback
        function safeAlert(message, type = 'info') {
            try {
                if (typeof showAlert === 'function') {
                    showAlert(message, type);
                } else {
                    console.warn('showAlert function not available, using console.log');
                    console.log(`[${type.toUpperCase()}] ${message}`);
                    // Try native alert as last resort for critical errors
                    if (type === 'error') {
                        alert(message);
                    }
                }
            } catch (error) {
                console.error('Error showing alert:', error);
                console.log(`[${type.toUpperCase()}] ${message}`);
                // Try native alert as last resort
                try {
                    alert(message);
                } catch (alertError) {
                    console.error('Cannot show any alert:', alertError);
                }
            }
        }

        // Debug function for production troubleshooting
        window.debugPrescriptionPage = function() {
            console.log('=== PRESCRIPTION PAGE DEBUG INFO ===');
            console.log('Medication count:', medicationCount);

            const container = document.getElementById('medications-container');
            console.log('Medications container:', container);
            console.log('Container children count:', container ? container.children.length : 'N/A');

            const form = document.getElementById('prescriptionForm');
            console.log('Prescription form:', form);

            const medicationInputs = document.querySelectorAll('input[name*="[medication_name]"]');
            console.log('Medication name inputs found:', medicationInputs.length);

            const selectedPatientId = document.getElementById('selectedPatientId');
            console.log('Selected patient ID element:', selectedPatientId);
            console.log('Selected patient ID value:', selectedPatientId ? selectedPatientId.value : 'N/A');

            console.log('=== END DEBUG INFO ===');

            return {
                medicationCount,
                containerExists: !!container,
                containerChildren: container ? container.children.length : 0,
                formExists: !!form,
                medicationInputsCount: medicationInputs.length,
                selectedPatientId: selectedPatientId ? selectedPatientId.value : null
            };
        };

        function addMedication() {
            console.log('addMedication function called');

            try {
                const container = document.getElementById('medications-container');
                console.log('Container found:', container);

                if (!container) {
                    console.error('Medications container not found');

                    // Use safe alert function
                    safeAlert('Error: Cannot find medications container. Please refresh the page.', 'error');
                    return;
                }

                console.log('Creating new medication row with count:', medicationCount);

                const newRow = document.createElement('div');
                newRow.className = 'medication-row';

                // Build the HTML string more carefully
                const medicationHTML = `
                    <div class="form-group">
                        <label>Medication Name</label>
                        <input type="text" name="medications[${medicationCount}][medication_name]" placeholder="e.g., Paracetamol" required>
                    </div>
                    <div class="form-group">
                        <label>Dosage</label>
                        <select name="medications[${medicationCount}][dosage]" onchange="handleDosageChange(this)">
                            <option value="">Select dosage...</option>
                            <!-- Pain/Fever Medications -->
                            <optgroup label="Pain & Fever">
                                <option value="250mg">250mg</option>
                                <option value="500mg">500mg</option>
                                <option value="650mg">650mg</option>
                                <option value="200mg">200mg (Ibuprofen)</option>
                                <option value="400mg">400mg (Ibuprofen)</option>
                                <option value="80mg">80mg (Aspirin)</option>
                                <option value="100mg">100mg (Aspirin)</option>
                            </optgroup>
                            <!-- Antibiotics -->
                            <optgroup label="Antibiotics">
                                <option value="250mg">250mg</option>
                                <option value="500mg">500mg</option>
                                <option value="875mg">875mg</option>
                                <option value="1g">1g</option>
                                <option value="100mg">100mg</option>
                            </optgroup>
                            <!-- Cardiovascular -->
                            <optgroup label="Cardiovascular">
                                <option value="2.5mg">2.5mg</option>
                                <option value="5mg">5mg</option>
                                <option value="10mg">10mg</option>
                                <option value="20mg">20mg</option>
                                <option value="25mg">25mg</option>
                                <option value="50mg">50mg</option>
                                <option value="100mg">100mg</option>
                            </optgroup>
                            <!-- Diabetes -->
                            <optgroup label="Diabetes">
                                <option value="500mg">500mg (Metformin)</option>
                                <option value="850mg">850mg (Metformin)</option>
                                <option value="1000mg">1000mg (Metformin)</option>
                                <option value="2.5mg">2.5mg (Glibenclamide)</option>
                                <option value="5mg">5mg (Glibenclamide)</option>
                                <option value="100IU/mL">100IU/mL (Insulin)</option>
                            </optgroup>
                            <!-- Respiratory -->
                            <optgroup label="Respiratory">
                                <option value="2mg">2mg</option>
                                <option value="4mg">4mg</option>
                                <option value="100mcg/dose">100mcg/dose (Inhaler)</option>
                                <option value="250mcg/dose">250mcg/dose (Inhaler)</option>
                            </optgroup>
                            <!-- Vitamins & Supplements -->
                            <optgroup label="Vitamins & Supplements">
                                <option value="325mg">325mg (Iron)</option>
                                <option value="5mg">5mg (Folic Acid)</option>
                                <option value="500mg">500mg (Calcium)</option>
                                <option value="1 tablet">1 tablet</option>
                                <option value="1 capsule">1 capsule</option>
                            </optgroup>
                            <!-- Topical -->
                            <optgroup label="Topical">
                                <option value="1%">1%</option>
                                <option value="2%">2%</option>
                                <option value="0.5%">0.5%</option>
                                <option value="10%">10%</option>
                                <option value="25%">25%</option>
                            </optgroup>
                            <!-- Eye/Ear Drops -->
                            <optgroup label="Drops">
                                <option value="0.3%">0.3%</option>
                                <option value="0.5%">0.5%</option>
                                <option value="0.9%">0.9%</option>
                                <option value="1 drop">1 drop</option>
                                <option value="2 drops">2 drops</option>
                            </optgroup>
                            <!-- Liquids -->
                            <optgroup label="Liquids">
                                <option value="5mL">5mL</option>
                                <option value="10mL">10mL</option>
                                <option value="15mL">15mL</option>
                                <option value="1 sachet">1 sachet</option>
                                <option value="1 teaspoon">1 teaspoon</option>
                                <option value="1 tablespoon">1 tablespoon</option>
                            </optgroup>
                            <option value="custom">Other (specify)</option>
                        </select>
                        <input type="text" name="medications[${medicationCount}][dosage_custom]" placeholder="Enter custom dosage..." style="display:none; margin-top:5px;" onblur="updateDosageFromCustom(this)">
                    </div>
                    <div class="form-group">
                        <label>Frequency</label>
                        <select name="medications[${medicationCount}][frequency]" required>
                            <option value="">Select frequency...</option>
                            <option value="Once daily">Once daily</option>
                            <option value="Twice daily">Twice daily</option>
                            <option value="3 times daily">3 times daily</option>
                            <option value="4 times daily">4 times daily</option>
                            <option value="Every 4 hours">Every 4 hours</option>
                            <option value="Every 6 hours">Every 6 hours</option>
                            <option value="Every 8 hours">Every 8 hours</option>
                            <option value="Every 12 hours">Every 12 hours</option>
                            <option value="Before meals">Before meals</option>
                            <option value="After meals">After meals</option>
                            <option value="With meals">With meals</option>
                            <option value="At bedtime">At bedtime</option>
                            <option value="As needed">As needed</option>
                            <option value="Weekly">Weekly</option>
                            <option value="Monthly">Monthly</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Duration</label>
                        <select name="medications[${medicationCount}][duration]" required>
                            <option value="">Select duration...</option>
                            <option value="1 day">1 day</option>
                            <option value="3 days">3 days</option>
                            <option value="5 days">5 days</option>
                            <option value="7 days">7 days</option>
                            <option value="10 days">10 days</option>
                            <option value="14 days">14 days</option>
                            <option value="21 days">21 days</option>
                            <option value="1 month">1 month</option>
                            <option value="2 months">2 months</option>
                            <option value="3 months">3 months</option>
                            <option value="6 months">6 months</option>
                            <option value="1 year">1 year</option>
                            <option value="Until finished">Until finished</option>
                            <option value="As needed">As needed</option>
                            <option value="Ongoing">Ongoing</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Instructions</label>
                        <textarea name="medications[${medicationCount}][instructions]" placeholder="Additional instructions..."></textarea>
                    </div>
                    <div class="form-group">
                        <label>Actions</label>
                        <button type="button" class="remove-medication" onclick="removeMedication(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                `;

                console.log('Setting innerHTML');
                newRow.innerHTML = medicationHTML;

                console.log('Appending to container');
                container.appendChild(newRow);

                medicationCount++;
                console.log('Medication count incremented to:', medicationCount);

                // Show remove buttons for all rows when we have more than one
                updateRemoveButtons();

                console.log('Medication added successfully');

                // Try to show success message
                safeAlert('Medication row added successfully!', 'success');

            } catch (error) {
                console.error('Error adding medication:', error);
                console.error('Error stack:', error.stack);

                // Use safe alert function
                safeAlert('Error adding medication: ' + error.message, 'error');
            }
        }

        function removeMedication(button) {
            try {
                const container = document.getElementById('medications-container');
                if (!container) {
                    console.error('Medications container not found');
                    showAlert('Error: Cannot find medications container', 'error');
                    return;
                }

                if (container.children.length > 1) {
                    const rowToRemove = button.closest('.medication-row');
                    if (rowToRemove) {
                        rowToRemove.remove();
                        updateRemoveButtons();
                        console.log('Medication removed successfully');
                    } else {
                        console.error('Could not find medication row to remove');
                    }
                } else {
                    showAlert('Cannot remove the last medication. At least one medication is required.', 'warning');
                }
            } catch (error) {
                console.error('Error removing medication:', error);
                showAlert('Error removing medication: ' + error.message, 'error');
            }
        }

        function updateRemoveButtons() {
            try {
                const container = document.getElementById('medications-container');
                if (!container) {
                    console.error('Medications container not found in updateRemoveButtons');
                    return;
                }

                const removeButtons = container.querySelectorAll('.remove-medication');

                removeButtons.forEach(button => {
                    button.style.display = container.children.length > 1 ? 'block' : 'none';
                });

                console.log('Remove buttons updated. Container has', container.children.length, 'children');
            } catch (error) {
                console.error('Error updating remove buttons:', error);
            }
        }

        function validateForm(e) {
            try {
                const selectedPatientId = document.getElementById('selectedPatientId').value;
                const medicationInputs = document.querySelectorAll('input[name*="[medication_name]"]');
                let hasValidMedication = false;

                // Check if patient is selected
                if (!selectedPatientId) {
                    e.preventDefault();
                    showAlert('Please search and select a patient first.', 'error');
                    return false;
                }

                // Check if at least one medication is added
                medicationInputs.forEach(input => {
                    if (input.value.trim() !== '') {
                        hasValidMedication = true;
                    }
                });

                if (!hasValidMedication) {
                    e.preventDefault();
                    showAlert('Please add at least one medication.', 'error');
                    return false;
                }

                console.log('Form validation passed');
                return true;
            } catch (error) {
                console.error('Error in form validation:', error);
                e.preventDefault();
                showAlert('Form validation error: ' + error.message, 'error');
                return false;
            }
        }

        // Initialize remove button visibility after DOM loads
        updateRemoveButtons();

        // Patient selection handling
        document.querySelectorAll('.patient-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    const patientId = this.value;
                    const patientName = this.getAttribute('data-patient-name');

                    // Update hidden form field
                    document.getElementById('selectedPatientId').value = patientId;

                    // Show selected patient info immediately with basic info
                    document.getElementById('selectedPatientName').textContent = patientName;
                    document.getElementById('selectedPatientInfo').style.display = 'block';
                    
                    // Show loading states
                    document.getElementById('selectedPatientAge').textContent = 'Loading...';
                    document.getElementById('selectedPatientSex').textContent = 'Loading...';
                    document.getElementById('selectedPatientHeight').textContent = 'Loading...';
                    document.getElementById('selectedPatientWeight').textContent = 'Loading...';

                    // Fetch detailed patient information
                    fetchPatientDetails(patientId);

                    // Highlight selected row
                    document.querySelectorAll('.patient-row').forEach(row => {
                        row.classList.remove('selected-patient');
                    });
                    this.closest('.patient-row').classList.add('selected-patient');

                    // Enable the form
                    document.getElementById('prescriptionForm').classList.add('enabled');
                }
            });
        });

        // Function to fetch detailed patient information
        function fetchPatientDetails(patientId) {
            fetch(`../../api/get_patient_details.php?patient_id=${patientId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.patient) {
                        const patient = data.patient;
                        
                        // Update patient information
                        document.getElementById('selectedPatientAge').textContent = patient.age;
                        document.getElementById('selectedPatientSex').textContent = patient.sex;
                        document.getElementById('selectedPatientHeight').textContent = patient.height;
                        document.getElementById('selectedPatientWeight').textContent = patient.weight;
                        
                        // Show success message if vitals are available
                        if (patient.vitals_date) {
                            const vitalsDate = new Date(patient.vitals_date).toLocaleDateString();
                            console.log(`Patient vitals loaded from ${vitalsDate}`);
                        }
                    } else {
                        // Handle error - show error states
                        document.getElementById('selectedPatientAge').textContent = 'Error loading';
                        document.getElementById('selectedPatientSex').textContent = 'Error loading';
                        document.getElementById('selectedPatientHeight').textContent = 'Error loading';
                        document.getElementById('selectedPatientWeight').textContent = 'Error loading';
                        
                        console.error('Error fetching patient details:', data.error || 'Unknown error');
                        showAlert('Error loading patient details. Some information may not be available.', 'warning');
                    }
                })
                .catch(error => {
                    console.error('Network error fetching patient details:', error);
                    
                    // Show error states
                    document.getElementById('selectedPatientAge').textContent = 'Network error';
                    document.getElementById('selectedPatientSex').textContent = 'Network error';
                    document.getElementById('selectedPatientHeight').textContent = 'Network error';
                    document.getElementById('selectedPatientWeight').textContent = 'Network error';
                    
                    showAlert('Network error loading patient details. Please check your connection.', 'error');
                });
        }

        // Alert system (no JavaScript alerts)
        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'error' ? 'exclamation-triangle' : 'info-circle'}"></i>
                ${message}
                <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
            `;

            // Insert at the top of profile-wrapper
            const profileWrapper = document.querySelector('.profile-wrapper');
            profileWrapper.insertBefore(alertDiv, profileWrapper.firstChild);

            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.style.opacity = '0';
                    setTimeout(() => alertDiv.remove(), 300);
                }
            }, 5000);
        }

        // Clear filters function
        function clearFilters() {
            // Clear all form inputs
            document.getElementById('search').value = '';
            document.getElementById('barangay').value = '';

            // Redirect to the page without any parameters
            window.location.href = window.location.pathname;
        }

        // Dosage handling functions
        function handleDosageChange(selectElement) {
            const customInput = selectElement.parentNode.querySelector('input[name*="[dosage_custom]"]');
            const dosageSelect = selectElement;

            if (selectElement.value === 'custom') {
                // Show custom input field
                customInput.style.display = 'block';
                customInput.focus();
                // Clear the main dosage field temporarily
                dosageSelect.setAttribute('data-original-name', dosageSelect.name);
                dosageSelect.name = dosageSelect.name.replace('[dosage]', '[dosage_temp]');
            } else {
                // Hide custom input field
                customInput.style.display = 'none';
                customInput.value = '';
                // Restore original field name
                if (dosageSelect.hasAttribute('data-original-name')) {
                    dosageSelect.name = dosageSelect.getAttribute('data-original-name');
                    dosageSelect.removeAttribute('data-original-name');
                }
            }
        }

        function updateDosageFromCustom(customInput) {
            const customValue = customInput.value.trim();
            if (customValue) {
                const dosageSelect = customInput.parentNode.querySelector('select[name*="[dosage"]');

                // Create or update custom option
                let customOption = dosageSelect.querySelector('option[value="' + customValue + '"]');
                if (!customOption) {
                    customOption = document.createElement('option');
                    customOption.value = customValue;
                    customOption.textContent = customValue;
                    // Insert before "Other (specify)" option
                    const otherOption = dosageSelect.querySelector('option[value="custom"]');
                    dosageSelect.insertBefore(customOption, otherOption);
                }

                // Select the custom value
                dosageSelect.value = customValue;

                // Hide custom input
                customInput.style.display = 'none';

                // Restore original field name
                if (dosageSelect.hasAttribute('data-original-name')) {
                    dosageSelect.name = dosageSelect.getAttribute('data-original-name');
                    dosageSelect.removeAttribute('data-original-name');
                }
            }
        }

        // Available Medications Modal Functions
        function openMedicationsModal() {
            // Set higher z-index to appear above other modals
            const medicationsModal = document.getElementById('availableMedicationsModal');
            medicationsModal.style.display = 'block';
            medicationsModal.style.zIndex = '1100'; // Higher than default modal z-index

            // Focus on the search input for better UX
            setTimeout(() => {
                const searchInput = document.getElementById('medicationSearch');
                if (searchInput) {
                    searchInput.focus();
                }
            }, 100);
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                // Reset z-index for medications modal
                if (modalId === 'availableMedicationsModal') {
                    modal.style.zIndex = '';
                }
            }
        }

        function filterMedications() {
            const searchTerm = document.getElementById('medicationSearch').value.toLowerCase();
            const table = document.getElementById('medicationsTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let match = false;

                for (let j = 0; j < cells.length; j++) {
                    if (cells[j].textContent.toLowerCase().includes(searchTerm)) {
                        match = true;
                        break;
                    }
                }

                rows[i].style.display = match ? '' : 'none';
            }
        }

        // Medication Selection Function
        window.selectMedication = function(drugName, dosage, formulation) {
            // Create medication selection data
            const medicationData = {
                name: drugName,
                dosage: dosage,
                formulation: formulation
            };

            // Show confirmation and copy to clipboard
            const medicationText = `${drugName} ${dosage} ${formulation}`;

            // Try to copy to clipboard
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(medicationText).then(() => {
                    showAlert(`Selected: ${medicationText} (copied to clipboard)`, 'success');
                }).catch(() => {
                    showAlert(`Selected: ${medicationText}`, 'success');
                });
            } else {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = medicationText;
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    showAlert(`Selected: ${medicationText} (copied to clipboard)`, 'success');
                } catch (err) {
                    showAlert(`Selected: ${medicationText}`, 'success');
                }
                document.body.removeChild(textArea);
            }

            // Try to auto-fill prescription form if it exists
            try {
                // Look for the first empty medication name field
                const medicationNameFields = document.querySelectorAll('input[name*="[medication_name]"]');
                const dosageFields = document.querySelectorAll('input[name*="[dosage]"]');

                let targetNameField = null;
                let targetDosageField = null;

                // Find the first empty medication name field
                for (let i = 0; i < medicationNameFields.length; i++) {
                    if (!medicationNameFields[i].value.trim()) {
                        targetNameField = medicationNameFields[i];
                        targetDosageField = dosageFields[i];
                        break;
                    }
                }

                if (targetNameField) {
                    targetNameField.value = medicationData.name;
                    targetNameField.dispatchEvent(new Event('input', {
                        bubbles: true
                    }));
                    targetNameField.dispatchEvent(new Event('change', {
                        bubbles: true
                    }));
                }

                if (targetDosageField) {
                    targetDosageField.value = medicationData.dosage;
                    targetDosageField.dispatchEvent(new Event('input', {
                        bubbles: true
                    }));
                    targetDosageField.dispatchEvent(new Event('change', {
                        bubbles: true
                    }));
                }

                // If fields were auto-filled, show additional message
                if (targetNameField || targetDosageField) {
                    setTimeout(() => {
                        showAlert('Medication details auto-filled in prescription form!', 'info');
                    }, 1000);
                }
            } catch (error) {
                console.log('Could not auto-fill form fields:', error);
            }

            // Close the medications modal after selection
            setTimeout(() => {
                closeModal('availableMedicationsModal');
            }, 1500);
        };

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const modals = ['availableMedicationsModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    closeModal(modalId);
                }
            });
        });
    </script>

    <!-- Available Medications Modal -->
    <div id="availableMedicationsModal" class="modal">
        <div class="modal-content" style="max-width: 90%; max-height: 90%;">
            <div class="modal-header">
                <h3><i class="fas fa-pills"></i> PhilHealth GAMOT 2025 - Available Medications</h3>
                <button class="close-btn" onclick="closeModal('availableMedicationsModal')">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <div style="background: #e7f3ff; border: 1px solid #b3d9ff; padding: 10px; margin-bottom: 15px; border-radius: 5px;">
                    <p style="margin: 0; font-size: 14px; color: #0066cc;">
                        <i class="fas fa-info-circle"></i> Click on any medication to select it and auto-fill the prescription form.
                    </p>
                </div>
                <div style="margin-bottom: 15px;">
                    <input type="text" id="medicationSearch" placeholder="Search medications..."
                        style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 5px; font-size: 14px;"
                        oninput="filterMedications()">
                </div>
                <table class="prescription-table" id="medicationsTable">
                    <thead>
                        <tr>
                            <th>Drug Name</th>
                            <th>Dosage</th>
                            <th>Formulation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- PhilHealth GAMOT 2025 Medication List -->
                        <tr onclick="selectMedication('Paracetamol', '500mg', 'Tablet')">
                            <td>Paracetamol</td>
                            <td>500mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Ibuprofen', '200mg', 'Tablet')">
                            <td>Ibuprofen</td>
                            <td>200mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Aspirin', '80mg', 'Tablet')">
                            <td>Aspirin</td>
                            <td>80mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Amoxicillin', '500mg', 'Capsule')">
                            <td>Amoxicillin</td>
                            <td>500mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('Cefalexin', '500mg', 'Capsule')">
                            <td>Cefalexin</td>
                            <td>500mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('Ciprofloxacin', '500mg', 'Tablet')">
                            <td>Ciprofloxacin</td>
                            <td>500mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Co-trimoxazole', '480mg', 'Tablet')">
                            <td>Co-trimoxazole</td>
                            <td>480mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Doxycycline', '100mg', 'Capsule')">
                            <td>Doxycycline</td>
                            <td>100mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('Metronidazole', '500mg', 'Tablet')">
                            <td>Metronidazole</td>
                            <td>500mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Amlodipine', '5mg', 'Tablet')">
                            <td>Amlodipine</td>
                            <td>5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Enalapril', '5mg', 'Tablet')">
                            <td>Enalapril</td>
                            <td>5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Losartan', '50mg', 'Tablet')">
                            <td>Losartan</td>
                            <td>50mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Propranolol', '40mg', 'Tablet')">
                            <td>Propranolol</td>
                            <td>40mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Glibenclamide', '5mg', 'Tablet')">
                            <td>Glibenclamide</td>
                            <td>5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Metformin', '500mg', 'Tablet')">
                            <td>Metformin</td>
                            <td>500mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Insulin NPH', '100IU/mL', 'Injection')">
                            <td>Insulin NPH</td>
                            <td>100IU/mL</td>
                            <td>Injection</td>
                        </tr>
                        <tr onclick="selectMedication('Salbutamol', '2mg', 'Tablet')">
                            <td>Salbutamol</td>
                            <td>2mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Salbutamol', '100mcg/dose', 'Inhaler')">
                            <td>Salbutamol</td>
                            <td>100mcg/dose</td>
                            <td>Inhaler</td>
                        </tr>
                        <tr onclick="selectMedication('Beclomethasone', '250mcg/dose', 'Inhaler')">
                            <td>Beclomethasone</td>
                            <td>250mcg/dose</td>
                            <td>Inhaler</td>
                        </tr>
                        <tr onclick="selectMedication('Omeprazole', '20mg', 'Capsule')">
                            <td>Omeprazole</td>
                            <td>20mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('Simethicone', '40mg', 'Tablet')">
                            <td>Simethicone</td>
                            <td>40mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Loperamide', '2mg', 'Capsule')">
                            <td>Loperamide</td>
                            <td>2mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('ORS', '1 sachet', 'Powder')">
                            <td>ORS</td>
                            <td>1 sachet</td>
                            <td>Powder</td>
                        </tr>
                        <tr onclick="selectMedication('Cetirizine', '10mg', 'Tablet')">
                            <td>Cetirizine</td>
                            <td>10mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Chlorphenamine', '4mg', 'Tablet')">
                            <td>Chlorphenamine</td>
                            <td>4mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Ferrous Sulfate', '325mg', 'Tablet')">
                            <td>Ferrous Sulfate</td>
                            <td>325mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Folic Acid', '5mg', 'Tablet')">
                            <td>Folic Acid</td>
                            <td>5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Calcium Carbonate', '500mg', 'Tablet')">
                            <td>Calcium Carbonate</td>
                            <td>500mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Vitamin B Complex', '1 tablet', 'Tablet')">
                            <td>Vitamin B Complex</td>
                            <td>1 tablet</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Multivitamins', '1 tablet', 'Tablet')">
                            <td>Multivitamins</td>
                            <td>1 tablet</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Diazepam', '5mg', 'Tablet')">
                            <td>Diazepam</td>
                            <td>5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Phenytoin', '100mg', 'Capsule')">
                            <td>Phenytoin</td>
                            <td>100mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('Carbamazepine', '200mg', 'Tablet')">
                            <td>Carbamazepine</td>
                            <td>200mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Betamethasone', '0.5mg', 'Tablet')">
                            <td>Betamethasone</td>
                            <td>0.5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Hydrocortisone', '10mg', 'Tablet')">
                            <td>Hydrocortisone</td>
                            <td>10mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Prednisolone', '5mg', 'Tablet')">
                            <td>Prednisolone</td>
                            <td>5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Levothyroxine', '50mcg', 'Tablet')">
                            <td>Levothyroxine</td>
                            <td>50mcg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Furosemide', '40mg', 'Tablet')">
                            <td>Furosemide</td>
                            <td>40mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Spironolactone', '25mg', 'Tablet')">
                            <td>Spironolactone</td>
                            <td>25mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Digoxin', '0.25mg', 'Tablet')">
                            <td>Digoxin</td>
                            <td>0.25mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Nifedipine', '10mg', 'Tablet')">
                            <td>Nifedipine</td>
                            <td>10mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Isosorbide Mononitrate', '20mg', 'Tablet')">
                            <td>Isosorbide Mononitrate</td>
                            <td>20mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Allopurinol', '300mg', 'Tablet')">
                            <td>Allopurinol</td>
                            <td>300mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Colchicine', '0.5mg', 'Tablet')">
                            <td>Colchicine</td>
                            <td>0.5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Tramadol', '50mg', 'Capsule')">
                            <td>Tramadol</td>
                            <td>50mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('Mefenamic Acid', '500mg', 'Capsule')">
                            <td>Mefenamic Acid</td>
                            <td>500mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('Sodium Chloride', '0.9%', 'Eye Drops')">
                            <td>Sodium Chloride</td>
                            <td>0.9%</td>
                            <td>Eye Drops</td>
                        </tr>
                        <tr onclick="selectMedication('Timolol', '0.5%', 'Eye Drops')">
                            <td>Timolol</td>
                            <td>0.5%</td>
                            <td>Eye Drops</td>
                        </tr>
                        <tr onclick="selectMedication('Gentamicin', '0.3%', 'Eye Drops')">
                            <td>Gentamicin</td>
                            <td>0.3%</td>
                            <td>Eye Drops</td>
                        </tr>
                        <tr onclick="selectMedication('Chloramphenicol', '0.5%', 'Eye Ointment')">
                            <td>Chloramphenicol</td>
                            <td>0.5%</td>
                            <td>Eye Ointment</td>
                        </tr>
                        <tr onclick="selectMedication('Ketoconazole', '2%', 'Cream')">
                            <td>Ketoconazole</td>
                            <td>2%</td>
                            <td>Cream</td>
                        </tr>
                        <tr onclick="selectMedication('Clotrimazole', '1%', 'Cream')">
                            <td>Clotrimazole</td>
                            <td>1%</td>
                            <td>Cream</td>
                        </tr>
                        <tr onclick="selectMedication('Hydrocortisone', '1%', 'Cream')">
                            <td>Hydrocortisone</td>
                            <td>1%</td>
                            <td>Cream</td>
                        </tr>
                        <tr onclick="selectMedication('Mupirocin', '2%', 'Ointment')">
                            <td>Mupirocin</td>
                            <td>2%</td>
                            <td>Ointment</td>
                        </tr>
                        <tr onclick="selectMedication('Silver Sulfadiazine', '1%', 'Cream')">
                            <td>Silver Sulfadiazine</td>
                            <td>1%</td>
                            <td>Cream</td>
                        </tr>
                        <tr onclick="selectMedication('Calamine', '8%', 'Lotion')">
                            <td>Calamine</td>
                            <td>8%</td>
                            <td>Lotion</td>
                        </tr>
                        <tr onclick="selectMedication('Zinc Oxide', '25%', 'Ointment')">
                            <td>Zinc Oxide</td>
                            <td>25%</td>
                            <td>Ointment</td>
                        </tr>
                        <tr onclick="selectMedication('Povidone Iodine', '10%', 'Solution')">
                            <td>Povidone Iodine</td>
                            <td>10%</td>
                            <td>Solution</td>
                        </tr>
                        <tr onclick="selectMedication('Hydrogen Peroxide', '3%', 'Solution')">
                            <td>Hydrogen Peroxide</td>
                            <td>3%</td>
                            <td>Solution</td>
                        </tr>
                        <tr onclick="selectMedication('Magnesium Hydroxide', '400mg/5mL', 'Suspension')">
                            <td>Magnesium Hydroxide</td>
                            <td>400mg/5mL</td>
                            <td>Suspension</td>
                        </tr>
                        <tr onclick="selectMedication('Aluminum Hydroxide', '320mg', 'Tablet')">
                            <td>Aluminum Hydroxide</td>
                            <td>320mg</td>
                            <td>Tablet</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>

</html>