<?php
/**
 * Edit Consultation Interface
 * Allows editing of existing consultations with same UI as new consultation form
 * Role-based access: Doctors and Admins can edit consultations
 */

// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

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

// Allow admin, doctor roles for editing
$authorized_roles = ['admin', 'doctor'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    header('Location: ../management/' . strtolower($_SESSION['role']) . '/dashboard.php');
    exit();
}

$employee_id = $_SESSION['employee_id'];
$employee_name = $_SESSION['employee_name'] ?? 'User';
$employee_role = strtolower($_SESSION['role']);

// Get consultation ID from URL with validation
$consultation_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$consultation_id || $consultation_id <= 0) {
    header('Location: index.php?error=invalid_consultation');
    exit();
}

// Initialize variables
$consultation_data = null;
$patient_data = null;
$vitals_data = null;
$success_message = '';
$error_message = '';

// Fetch available services for dropdown
$services = [];
try {
    $stmt = $conn->prepare("SELECT service_id, name FROM services WHERE service_id IN (1,2,3,4,5,6,7) ORDER BY service_id");
    $stmt->execute();
    $services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching services: " . $e->getMessage());
    // Continue without services - form will still work
}

// Load consultation data
try {
    // Get consultation with patient and vitals information
    $consultation_stmt = $conn->prepare("
        SELECT c.*, p.first_name, p.last_name, p.username as patient_code, p.date_of_birth, p.sex, p.contact_number,
               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age,
               COALESCE(b.barangay_name, 'Not Specified') as barangay,
               doc.first_name as doctor_first_name, doc.last_name as doctor_last_name,
               s.name as service_name,
               v.vitals_id, v.recorded_at as vitals_recorded_at, v.systolic_bp, v.diastolic_bp, 
               v.heart_rate, v.temperature, v.respiratory_rate, v.weight, v.height, v.bmi, v.remarks as vitals_remarks,
               emp_vitals.first_name as vitals_taken_by_first_name, emp_vitals.last_name as vitals_taken_by_last_name
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN employees doc ON (c.consulted_by = doc.employee_id OR c.attending_employee_id = doc.employee_id)
        LEFT JOIN services s ON c.service_id = s.service_id
        LEFT JOIN vitals v ON c.vitals_id = v.vitals_id
        LEFT JOIN employees emp_vitals ON v.recorded_by = emp_vitals.employee_id
        WHERE c.consultation_id = ?
    ");
    if ($consultation_stmt) {
        $consultation_stmt->bind_param("i", $consultation_id);
        if (!$consultation_stmt->execute()) {
            error_log("Failed to execute consultation query: " . $consultation_stmt->error);
            $error_message = "Database error occurred while loading consultation.";
        } else {
            $result = $consultation_stmt->get_result();
            $consultation_data = $result->fetch_assoc();
        }
    } else {
        error_log("Failed to prepare consultation statement: " . $conn->error);
        $error_message = "Database error occurred while preparing consultation query.";
    }
    
    if (!$consultation_data) {
        // Log the consultation ID that wasn't found for debugging
        error_log("Consultation not found for ID: " . $consultation_id);
        header('Location: index.php?error=consultation_not_found');
        exit();
    }
    
    // Doctors and admins can edit any consultation
    // Only restrict access for non-doctor/admin roles if needed
    if (!in_array($employee_role, ['doctor', 'admin'])) {
        // For future implementation: Add role-specific restrictions for nurses, etc.
        // Currently allowing all authorized roles to edit consultations
    }
    
    $patient_data = $consultation_data;
    $vitals_data = $consultation_data;
    
    // Format full name
    $patient_data['full_name'] = trim($patient_data['first_name'] . ' ' . 
        (!empty($patient_data['middle_name']) ? $patient_data['middle_name'] . ' ' : '') . 
        $patient_data['last_name']);
    
} catch (Exception $e) {
    $error_message = "Error loading consultation data: " . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_vitals') {
        try {
            $conn->begin_transaction();
            
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
            if (!$systolic_bp && !$diastolic_bp && !$heart_rate && !$temperature && !$weight && !$height) {
                throw new Exception('Please enter at least one vital sign measurement.');
            }
            
            // Update vitals if they exist, otherwise create new ones
            if ($vitals_data['vitals_id']) {
                $stmt = $conn->prepare("
                    UPDATE vitals SET
                        systolic_bp = ?, diastolic_bp = ?, heart_rate = ?, respiratory_rate = ?,
                        temperature = ?, weight = ?, height = ?, bmi = ?, remarks = ?, 
                        recorded_by = ?, recorded_at = NOW()
                    WHERE vitals_id = ?
                ");
                $stmt->bind_param(
                    'iiiiidddsii',
                    $systolic_bp, $diastolic_bp, $heart_rate, $respiratory_rate,
                    $temperature, $weight, $height, $bmi, $vitals_remarks, $employee_id,
                    $vitals_data['vitals_id']
                );
                $stmt->execute();
                $vitals_id = $vitals_data['vitals_id'];
            } else {
                // Create new vitals
                $stmt = $conn->prepare("
                    INSERT INTO vitals (
                        patient_id, systolic_bp, diastolic_bp, heart_rate, respiratory_rate,
                        temperature, weight, height, bmi, remarks, recorded_by, recorded_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->bind_param(
                    'iiiiidddsi',
                    $consultation_data['patient_id'], $systolic_bp, $diastolic_bp, $heart_rate, $respiratory_rate,
                    $temperature, $weight, $height, $bmi, $vitals_remarks, $employee_id
                );
                $stmt->execute();
                $vitals_id = $conn->insert_id;
                
                // Link vitals to consultation
                $stmt = $conn->prepare("UPDATE consultations SET vitals_id = ? WHERE consultation_id = ?");
                $stmt->bind_param('ii', $vitals_id, $consultation_id);
                $stmt->execute();
            }
            
            $conn->commit();
            $success_message = "Patient vitals updated successfully! Vitals ID: " . $vitals_id;
            
            // Reload data
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $consultation_id . '&success=vitals_updated');
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
    
    elseif ($action === 'update_consultation') {
        try {
            $conn->begin_transaction();
            
            // Consultation data
            $service_id = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
            $chief_complaint = trim($_POST['chief_complaint'] ?? '');
            $diagnosis = trim($_POST['diagnosis'] ?? '');
            $treatment_plan = trim($_POST['treatment_plan'] ?? '');
            $follow_up_date = !empty($_POST['follow_up_date']) ? $_POST['follow_up_date'] : null;
            $remarks = trim($_POST['remarks'] ?? '');
            $consultation_status = $_POST['consultation_status'] ?? 'ongoing';
            
            // Validation
            if (empty($chief_complaint)) {
                throw new Exception('Chief complaint is required.');
            }
            
            // Update consultation
            $stmt = $conn->prepare("
                UPDATE consultations SET
                    service_id = ?, chief_complaint = ?, diagnosis = ?, 
                    treatment_plan = ?, follow_up_date = ?, remarks = ?,
                    consultation_status = ?, updated_at = NOW()
                WHERE consultation_id = ?
            ");
            $stmt->bind_param(
                'issssssi',
                $service_id, $chief_complaint, $diagnosis, $treatment_plan,
                $follow_up_date, $remarks, $consultation_status, $consultation_id
            );
            $stmt->execute();
            
            $conn->commit();
            
            // Enhanced success message
            $success_message = "Consultation updated successfully! Consultation ID: " . $consultation_id;
            
            // Redirect to index page with success message
            $success_param = urlencode("Consultation updated successfully by " . $employee_name);
            header("Location: index.php?success=consultation_updated&message=" . $success_param);
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
}

// Check for success messages from URL
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'vitals_updated') {
        $success_message = "Patient vitals updated successfully!";
    }
}

// Include reusable topbar component
require_once $root_path . '/includes/topbar.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Edit Consultation | CHO Koronadal</title>
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

        .patient-results {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #28a745;
        }

        .form-section {
            opacity: 1;
            pointer-events: auto;
            transition: all 0.3s ease;
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

        /* Checkbox styling for better integration */
        .form-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin: 0;
            cursor: pointer;
            accent-color: #28a745;
        }

        .form-group label:has(input[type="checkbox"]) {
            font-weight: 600;
            color: #28a745;
            margin-bottom: 0.5rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group label:has(input[type="checkbox"]) span {
            user-select: none;
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

        .selected-patient-info {
            background: #f8fff8;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            border-left: 4px solid #28a745;
            display: block;
        }

        .edit-notice {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        @media (max-width: 768px) {
            .vitals-grid {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            }
        }

        /* Alert Styles */
        .alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            font-size: 0.95rem;
            border-left: 4px solid;
            transition: all 0.3s ease;
            position: relative;
        }

        .alert-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.7;
            color: inherit;
            padding: 0;
            margin-left: auto;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .alert-close:hover {
            opacity: 1;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .modal-overlay.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.7) translateY(-20px);
            transition: all 0.3s ease;
        }

        .modal-overlay.show .modal-content {
            transform: scale(1) translateY(0);
        }

        .modal-header {
            padding: 1.5rem 1.5rem 0;
            border-bottom: 1px solid #e9ecef;
        }

        .modal-header h3 {
            margin: 0;
            color: #28a745;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-body p {
            margin: 0;
            color: #6c757d;
            line-height: 1.5;
        }

        .modal-footer {
            padding: 0 1.5rem 1.5rem;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 1rem;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                justify-content: center;
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
        'title' => 'Edit Consultation',
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
            'modal_title' => 'Cancel Editing?',
            'modal_message' => 'Are you sure you want to go back? Unsaved changes will be lost.',
            'confirm_text' => 'Yes, Go Back',
            'stay_text' => 'Stay'
        ]);
        ?>

        <div class="profile-wrapper">
            <!-- Role Information -->
            <div class="role-info">
                <strong>Editing Mode - <?= htmlspecialchars($employee_name) ?> (<?= ucfirst($employee_role) ?>)</strong>
                <ul style="margin: 0.5rem 0 0 0; padding-left: 1.5rem;">
                    <li>You are editing an existing consultation record.</li>
                    <li>Changes will be logged and timestamped.</li>
                    <?php if ($employee_role === 'doctor'): ?>
                        <li>You can only edit consultations you are assigned to.</li>
                    <?php else: ?>
                        <li>As an admin, you have full editing access.</li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="edit-notice">
                <i class="fas fa-edit"></i>
                <strong>Editing Consultation ID: <?= $consultation_id ?></strong> - 
                Created on <?= date('M j, Y g:i A', strtotime($consultation_data['consultation_date'])) ?>
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

            <?php if ($consultation_data): ?>

                <!-- Selected Patient Info (Read-Only) -->
                <div class="selected-patient-info">
                    <strong>Patient:</strong> <?= htmlspecialchars($patient_data['full_name']) ?>
                    <?php if ($vitals_data['vitals_id']): ?>
                        <span style="color: #28a745;"> (Vitals linked: ID <?= $vitals_data['vitals_id'] ?>)</span>
                    <?php else: ?>
                        <span style="color: #856404;"> (No vitals linked)</span>
                    <?php endif; ?><br>
                    <small>ID: <?= htmlspecialchars($patient_data['patient_code']) ?> | 
                    Age/Sex: <?= htmlspecialchars(($patient_data['age'] ?? '-') . '/' . $patient_data['sex']) ?> | 
                    Barangay: <?= htmlspecialchars($patient_data['barangay']) ?></small>
                </div>

                <!-- Vitals Form Section -->
                <div class="form-section" id="vitalsSection">
                    <form class="profile-card" id="vitalsForm" method="post">
                        <input type="hidden" name="action" value="update_vitals">
                        
                        <h3><i class="fas fa-heartbeat"></i> Patient Vital Signs</h3>
                        <p class="text-muted">Update patient's vital signs measurements</p>
                        
                        <?php if ($vitals_data['vitals_id']): ?>
                        <div style="background: #d4edda; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border-left: 4px solid #28a745;">
                            <strong><i class="fas fa-check-circle"></i> Current Vitals (ID: <?= $vitals_data['vitals_id'] ?>)</strong><br>
                            <small>Recorded by: <?= htmlspecialchars(($vitals_data['vitals_taken_by_first_name'] ?? '') . ' ' . ($vitals_data['vitals_taken_by_last_name'] ?? '')) ?> 
                            at <?= date('g:i A, M j, Y', strtotime($vitals_data['vitals_recorded_at'])) ?></small>
                        </div>
                        <?php else: ?>
                        <div style="background: #fff3cd; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border-left: 4px solid #ffc107;">
                            <strong><i class="fas fa-exclamation-triangle"></i> No Vitals Linked</strong><br>
                            <small>You can add vitals for this consultation</small>
                        </div>
                        <?php endif; ?>
                        
                        <div class="vitals-grid">
                            <div class="form-group">
                                <label>Systolic BP (mmHg)</label>
                                <input type="number" name="systolic_bp" class="form-control" placeholder="120" min="60" max="300"
                                       value="<?= $vitals_data ? htmlspecialchars($vitals_data['systolic_bp']) : '' ?>">
                            </div>
                            <div class="form-group">
                                <label>Diastolic BP (mmHg)</label>
                                <input type="number" name="diastolic_bp" class="form-control" placeholder="80" min="40" max="200"
                                       value="<?= $vitals_data ? htmlspecialchars($vitals_data['diastolic_bp']) : '' ?>">
                            </div>
                            <div class="form-group">
                                <label>Heart Rate (bpm)</label>
                                <input type="number" name="heart_rate" class="form-control" placeholder="72" min="40" max="200"
                                       value="<?= $vitals_data ? htmlspecialchars($vitals_data['heart_rate']) : '' ?>">
                            </div>
                            <div class="form-group">
                                <label>Respiratory Rate (/min)</label>
                                <input type="number" name="respiratory_rate" class="form-control" placeholder="16" min="8" max="60"
                                       value="<?= $vitals_data ? htmlspecialchars($vitals_data['respiratory_rate']) : '' ?>">
                            </div>
                            <div class="form-group">
                                <label>Temperature (°C)</label>
                                <input type="number" name="temperature" step="0.1" class="form-control" placeholder="36.5" min="35" max="42"
                                       value="<?= $vitals_data ? htmlspecialchars($vitals_data['temperature']) : '' ?>">
                            </div>
                            <div class="form-group">
                                <label>Weight (kg)</label>
                                <input type="number" name="weight" step="0.1" class="form-control" placeholder="70.0" min="1" max="300"
                                       value="<?= $vitals_data ? htmlspecialchars($vitals_data['weight']) : '' ?>">
                            </div>
                            <div class="form-group">
                                <label>Height (cm)</label>
                                <input type="number" name="height" step="0.1" class="form-control" placeholder="170" min="50" max="250"
                                       value="<?= $vitals_data ? htmlspecialchars($vitals_data['height']) : '' ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label>Vitals Remarks</label>
                            <textarea name="vitals_remarks" class="form-control" placeholder="Additional notes about vital signs..." rows="2"><?= $vitals_data ? htmlspecialchars($vitals_data['vitals_remarks']) : '' ?></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> <?= $vitals_data['vitals_id'] ? 'Update Vital Signs' : 'Add Vital Signs' ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Consultation Form Section -->
                <div class="form-section" id="consultationSection">
                    <form class="profile-card" id="consultationForm" method="post">
                        <input type="hidden" name="action" value="update_consultation">
                        
                        <h3><i class="fas fa-stethoscope"></i> Clinical Consultation</h3>
                        <p class="text-muted">Update consultation with clinical findings and diagnosis</p>
                        
                        <?php if ($vitals_data['vitals_id']): ?>
                        <div style="background: #e8f5e8; padding: 1rem; border-radius: 8px; margin: 0.5rem 0; border-left: 4px solid #28a745;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-link" style="color: #28a745;"></i>
                                <strong style="color: #1e7e34;">Vitals Linked</strong>
                            </div>
                            <div style="font-size: 0.9rem; color: #155724; margin-top: 0.5rem;">
                                <i class="fas fa-check-circle"></i> Vitals (ID: <?= $vitals_data['vitals_id'] ?>) are linked to this consultation.<br>
                                <small>Recorded at <?= date('g:i A, M j, Y', strtotime($vitals_data['vitals_recorded_at'])) ?></small>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="consultation-grid">
                            
                            <div class="form-group">
                                <label>Service Type *</label>
                                <select name="service_id" class="form-control" required>
                                    <option value="">Select Service Type</option>
                                    <?php foreach ($services as $service): ?>
                                        <option value="<?= htmlspecialchars($service['service_id']) ?>" 
                                                <?= ($consultation_data['service_id'] == $service['service_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($service['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Chief Complaint *</label>
                                <textarea name="chief_complaint" class="form-control" placeholder="Patient's main concern or reason for visit..." required rows="3"><?= htmlspecialchars($consultation_data['chief_complaint'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Diagnosis</label>
                                <textarea name="diagnosis" class="form-control" placeholder="Clinical diagnosis or assessment..." rows="3"><?= htmlspecialchars($consultation_data['diagnosis'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Treatment Plan</label>
                                <textarea name="treatment_plan" class="form-control" placeholder="Treatment plan and recommendations..." rows="4"><?= htmlspecialchars($consultation_data['treatment_plan'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; margin-bottom: 0.5rem;">
                                    <input type="checkbox" id="requireFollowUp" onchange="toggleFollowUpDate()" style="width: 18px; height: 18px; cursor: pointer;" 
                                           <?= !empty($consultation_data['follow_up_date']) ? 'checked' : '' ?>>
                                    <span>Schedule Follow-up Date</span>
                                </label>
                                <input type="date" name="follow_up_date" id="followUpDateInput" class="form-control" min="<?= date('Y-m-d') ?>" 
                                       style="<?= empty($consultation_data['follow_up_date']) ? 'display: none;' : '' ?>" 
                                       value="<?= htmlspecialchars($consultation_data['follow_up_date'] ?? '') ?>"
                                       <?= empty($consultation_data['follow_up_date']) ? 'disabled' : '' ?>>
                            </div>
                            
                            <div class="form-group">
                                <label>Remarks</label>
                                <textarea name="remarks" class="form-control" placeholder="Additional notes or observations..." rows="3"><?= htmlspecialchars($consultation_data['remarks'] ?? '') ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label>Consultation Status</label>
                                <select name="consultation_status" class="form-control">
                                    <option value="ongoing" <?= ($consultation_data['consultation_status'] ?? '') === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                                    <option value="completed" <?= ($consultation_data['consultation_status'] ?? '') === 'completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="awaiting_lab_results" <?= ($consultation_data['consultation_status'] ?? '') === 'awaiting_lab_results' ? 'selected' : '' ?>>Awaiting Lab Results</option>
                                    <option value="awaiting_followup" <?= ($consultation_data['consultation_status'] ?? '') === 'awaiting_followup' ? 'selected' : '' ?>>Awaiting Follow-up</option>
                                    <option value="cancelled" <?= ($consultation_data['consultation_status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-actions">
                            <a href="index.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-check-circle"></i> Update Consultation
                            </button>
                        </div>
                    </form>
                </div>

            <?php else: ?>
                <div class="search-container">
                    <div style="text-align: center; padding: 2rem;">
                        <i class="fas fa-file-medical fa-3x" style="color: #6c757d; margin-bottom: 1rem;"></i>
                        <h3>Consultation Not Found</h3>
                        <p>The requested consultation could not be found or you don't have permission to edit it.</p>
                        <a href="index.php" class="btn btn-primary">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-dismiss alerts
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });

            // Form validation for consultation
            document.getElementById('consultationForm').addEventListener('submit', function(e) {
                const chiefComplaint = document.querySelector('[name="chief_complaint"]').value.trim();
                const serviceId = document.querySelector('[name="service_id"]').value;
                
                if (!chiefComplaint) {
                    e.preventDefault();
                    showError('Chief Complaint is required.');
                    document.querySelector('[name="chief_complaint"]').focus();
                    return false;
                }
                
                if (!serviceId) {
                    e.preventDefault();
                    showError('Please select a service type.');
                    document.querySelector('[name="service_id"]').focus();
                    return false;
                }
                
                // Show confirmation modal
                e.preventDefault();
                showConfirmationModal(
                    'Update Consultation?',
                    'Are you sure you want to update this consultation? This action will save all changes.',
                    () => {
                        // User confirmed - submit the form
                        document.getElementById('consultationForm').removeEventListener('submit', arguments.callee);
                        document.getElementById('consultationForm').submit();
                    }
                );
            });

            // Form validation for vitals
            document.getElementById('vitalsForm').addEventListener('submit', function(e) {
                const vitalsInputs = ['systolic_bp', 'diastolic_bp', 'heart_rate', 'temperature', 'weight', 'height'];
                let hasValue = false;
                
                vitalsInputs.forEach(input => {
                    if (document.querySelector(`[name="${input}"]`).value.trim()) {
                        hasValue = true;
                    }
                });
                
                if (!hasValue) {
                    e.preventDefault();
                    showError('Please enter at least one vital sign measurement.');
                    return false;
                }
                
                // Show confirmation modal
                e.preventDefault();
                showConfirmationModal(
                    'Update Vitals?',
                    'Are you sure you want to update the patient vitals? This action will save all changes.',
                    () => {
                        // User confirmed - submit the form
                        document.getElementById('vitalsForm').removeEventListener('submit', arguments.callee);
                        document.getElementById('vitalsForm').submit();
                    }
                );
            });
        });

        // Follow-up date toggle function
        function toggleFollowUpDate() {
            const checkbox = document.getElementById('requireFollowUp');
            const dateInput = document.getElementById('followUpDateInput');
            
            if (checkbox.checked) {
                // Show date input with smooth transition
                dateInput.style.display = 'block';
                dateInput.disabled = false;
                
                // Small delay to ensure display is set before focusing
                setTimeout(() => {
                    dateInput.focus();
                }, 50);
                
                // Add visual feedback
                dateInput.style.opacity = '0';
                setTimeout(() => {
                    dateInput.style.transition = 'opacity 0.3s ease';
                    dateInput.style.opacity = '1';
                }, 10);
            } else {
                // Hide date input with smooth transition
                dateInput.style.transition = 'opacity 0.3s ease';
                dateInput.style.opacity = '0';
                
                setTimeout(() => {
                    dateInput.style.display = 'none';
                    dateInput.disabled = true;
                    dateInput.value = '';
                    dateInput.style.transition = '';
                }, 300);
            }
        }

        // Error and success notification functions
        function showError(message) {
            const existingAlert = document.querySelector('.alert-error');
            if (existingAlert) {
                existingAlert.remove();
            }
            
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-error';
            alertDiv.innerHTML = `
                <i class="fas fa-exclamation-circle"></i> 
                <span>${message}</span>
                <button type="button" class="alert-close" onclick="this.parentElement.remove();">&times;</button>
            `;
            
            const profileWrapper = document.querySelector('.profile-wrapper');
            profileWrapper.insertBefore(alertDiv, profileWrapper.firstChild);
            
            // Auto-dismiss after 8 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.style.opacity = '0';
                    setTimeout(() => alertDiv.remove(), 300);
                }
            }, 8000);
        }

        function showSuccess(message) {
            const existingAlert = document.querySelector('.alert-success');
            if (existingAlert) {
                existingAlert.remove();
            }
            
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-success';
            alertDiv.innerHTML = `
                <i class="fas fa-check-circle"></i> 
                <span>${message}</span>
                <button type="button" class="alert-close" onclick="this.parentElement.remove();">&times;</button>
            `;
            
            const profileWrapper = document.querySelector('.profile-wrapper');
            profileWrapper.insertBefore(alertDiv, profileWrapper.firstChild);
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.style.opacity = '0';
                    setTimeout(() => alertDiv.remove(), 300);
                }
            }, 5000);
        }

        // Confirmation modal function
        function showConfirmationModal(title, message, onConfirm, onCancel = null) {
            // Remove existing modal if any
            const existingModal = document.getElementById('confirmationModal');
            if (existingModal) {
                existingModal.remove();
            }
            
            // Create modal HTML
            const modalHTML = `
                <div class="modal-overlay" id="confirmationModal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h3><i class="fas fa-question-circle"></i> ${title}</h3>
                        </div>
                        <div class="modal-body">
                            <p>${message}</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeConfirmationModal()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button type="button" class="btn btn-primary" onclick="confirmAction()">
                                <i class="fas fa-check"></i> Confirm
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            // Add modal to page
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            
            // Store callback functions
            window.confirmationModalCallbacks = {
                onConfirm: onConfirm,
                onCancel: onCancel
            };
            
            // Show modal with animation
            setTimeout(() => {
                document.getElementById('confirmationModal').classList.add('show');
            }, 10);
        }

        function closeConfirmationModal() {
            const modal = document.getElementById('confirmationModal');
            if (modal) {
                modal.classList.remove('show');
                setTimeout(() => {
                    modal.remove();
                    if (window.confirmationModalCallbacks && window.confirmationModalCallbacks.onCancel) {
                        window.confirmationModalCallbacks.onCancel();
                    }
                    window.confirmationModalCallbacks = null;
                }, 300);
            }
        }

        function confirmAction() {
            const modal = document.getElementById('confirmationModal');
            if (modal) {
                modal.classList.remove('show');
                setTimeout(() => {
                    modal.remove();
                    if (window.confirmationModalCallbacks && window.confirmationModalCallbacks.onConfirm) {
                        window.confirmationModalCallbacks.onConfirm();
                    }
                    window.confirmationModalCallbacks = null;
                }, 300);
            }
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-overlay')) {
                closeConfirmationModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeConfirmationModal();
            }
        });
    </script>
</body>

</html>