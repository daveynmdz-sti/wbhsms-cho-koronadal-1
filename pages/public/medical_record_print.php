<?php
// pages/public/medical_record_print.php
// Frontend UI for selecting sections and previewing the patient's medical record print.
// Loads assets/js/medical_print.js and assets/css/medical_print.css

$root_path = dirname(dirname(__DIR__)); // This should go from pages/public/ to project root
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';
require_once $root_path . '/includes/medical_print_security.php';

// Initialize security manager
$security = new MedicalPrintSecurity();

// Check authentication
if (!is_employee_logged_in()) {
    header('Location: ' . $root_path . '/pages/management/auth/employee_login.php');
    exit;
}

// Get current user and validate
$currentUser = $security->currentUser();
if (!$currentUser) {
    header('Location: ' . $root_path . '/pages/management/auth/employee_login.php');
    exit;
}

$employeeId = $currentUser['employee_id'];
$employeeRole = $currentUser['role'];

// Check if user has permission to view medical records
if (!$security->hasPermission($employeeId, 'view')) {
    header('Location: ' . $root_path . '/pages/management/dashboard.php');
    exit;
}

// Get patient ID from URL parameter
$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

if (!$patientId) {
    header('Location: ' . $root_path . '/pages/management/admin/patient-records/patient_records_management.php');
    exit;
}

// Validate patient exists and get basic info
try {
    $stmt = $pdo->prepare("SELECT patient_id, first_name, middle_name, last_name, date_of_birth, gender, is_active FROM patients WHERE patient_id = ?");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient || !$patient['is_active']) {
        header('Location: ' . $root_path . '/pages/management/admin/patient-records/patient_records_management.php');
        exit;
    }

    // Check if user can access this specific patient
    if (!$security->userCanAccessPatient($employeeId, $patientId)) {
        header('Location: ' . $root_path . '/pages/management/admin/patient-records/patient_records_management.php');
        exit;
    }

    // Generate CSRF token for API requests
    $csrfToken = $security->generateCsrfToken();

} catch (PDOException $e) {
    error_log("Patient fetch error: " . $e->getMessage());
    header('Location: ' . $root_path . '/pages/management/admin/patient-records/patient_records_management.php');
    exit;
}

$patientName = trim(($patient['first_name'] ?? '') . ' ' . ($patient['middle_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
$patientAge = $patient['date_of_birth'] ? date_diff(date_create($patient['date_of_birth']), date_create('today'))->y : 'N/A';

// Include topbar
require_once $root_path . '/includes/topbar.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Record Print - <?php echo htmlspecialchars($patientName); ?></title>
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="<?php echo $root_path; ?>/assets/css/topbar.css">
    <link rel="stylesheet" href="<?php echo $root_path; ?>/assets/css/profile-edit.css">
    <link rel="stylesheet" href="<?php echo $root_path; ?>/assets/css/edit.css">
    <link rel="stylesheet" href="<?php echo $root_path; ?>/assets/css/medical_print.css">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <!-- Topbar -->
    <?php 
    renderTopbar([
        'title' => 'Medical Record Print',
        'back_url' => $root_path . '/pages/management/admin/patient-records/patient_records_management.php',
        'user_type' => 'employee'
    ]); 
    ?>

    <section class="homepage">
        <div class="medical-print-container">
            <!-- Patient Information Header -->
            <div class="patient-header">
                <div class="patient-info">
                    <h2><i class="fas fa-file-medical"></i> Medical Record Print</h2>
                    <div class="patient-details">
                        <h3><?php echo htmlspecialchars($patientName); ?></h3>
                        <p>
                            <span><strong>Patient ID:</strong> <?php echo $patient['patient_id']; ?></span>
                            <span><strong>Age:</strong> <?php echo $patientAge; ?> years</span>
                            <span><strong>Gender:</strong> <?php echo htmlspecialchars($patient['gender']); ?></span>
                            <span><strong>DOB:</strong> <?php echo $patient['date_of_birth'] ? date('M d, Y', strtotime($patient['date_of_birth'])) : 'N/A'; ?></span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="print-content-grid">
                <!-- Left Panel - Section Selection -->
                <div class="selection-panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-list-check"></i> Select Sections</h3>
                        <div class="selection-controls">
                            <button type="button" id="selectAllBtn" class="btn btn-sm btn-outline">
                                <i class="fas fa-check-double"></i> Select All
                            </button>
                            <button type="button" id="clearAllBtn" class="btn btn-sm btn-outline">
                                <i class="fas fa-times"></i> Clear All
                            </button>
                        </div>
                    </div>

                    <!-- Date Range Filters -->
                    <div class="date-filters">
                        <h4><i class="fas fa-calendar-alt"></i> Date Range Filter</h4>
                        <div class="date-inputs">
                            <div class="form-group">
                                <label for="dateFrom">From Date:</label>
                                <input type="date" id="dateFrom" name="date_from" class="form-control">
                            </div>
                            <div class="form-group">
                                <label for="dateTo">To Date:</label>
                                <input type="date" id="dateTo" name="date_to" class="form-control">
                            </div>
                        </div>
                        <small class="text-muted">Date filters apply to consultations, appointments, prescriptions, lab orders, and billing records.</small>
                    </div>

                    <!-- Section Groups -->
                    <div class="section-groups">
                        <!-- Patient Information Group -->
                        <div class="section-group">
                            <div class="group-header">
                                <h4><i class="fas fa-user"></i> Patient Information</h4>
                                <label class="group-toggle">
                                    <input type="checkbox" class="group-checkbox" data-group="patient-info">
                                    <span class="checkmark"></span>
                                </label>
                            </div>
                            <div class="group-items">
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="basic" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Basic Information</span>
                                </label>
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="personal_information" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Personal Details</span>
                                </label>
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="emergency_contacts" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Emergency Contacts</span>
                                </label>
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="lifestyle_information" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Lifestyle Information</span>
                                </label>
                            </div>
                        </div>

                        <!-- Medical History Group -->
                        <div class="section-group">
                            <div class="group-header">
                                <h4><i class="fas fa-history"></i> Medical History</h4>
                                <label class="group-toggle">
                                    <input type="checkbox" class="group-checkbox" data-group="medical-history">
                                    <span class="checkmark"></span>
                                </label>
                            </div>
                            <div class="group-items">
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="past_medical_conditions" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Past Medical Conditions</span>
                                </label>
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="chronic_illnesses" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Chronic Illnesses</span>
                                </label>
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="family_history" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Family History</span>
                                </label>
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="surgical_history" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Surgical History</span>
                                </label>
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="immunizations" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Immunizations</span>
                                </label>
                            </div>
                        </div>

                        <!-- Current Health Group -->
                        <div class="section-group">
                            <div class="group-header">
                                <h4><i class="fas fa-heartbeat"></i> Current Health</h4>
                                <label class="group-toggle">
                                    <input type="checkbox" class="group-checkbox" data-group="current-health">
                                    <span class="checkmark"></span>
                                </label>
                            </div>
                            <div class="group-items">
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="allergies" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Allergies</span>
                                </label>
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="current_medications" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Current Medications</span>
                                </label>
                            </div>
                        </div>

                        <!-- Healthcare Records Group -->
                        <div class="section-group">
                            <div class="group-header">
                                <h4><i class="fas fa-clipboard-list"></i> Healthcare Records</h4>
                                <label class="group-toggle">
                                    <input type="checkbox" class="group-checkbox" data-group="healthcare-records">
                                    <span class="checkmark"></span>
                                </label>
                            </div>
                            <div class="group-items">
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="consultations" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Consultations</span>
                                </label>
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="appointments" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Appointments</span>
                                </label>
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="referrals" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Referrals</span>
                                </label>
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="prescriptions" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Prescriptions</span>
                                </label>
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="lab_orders" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Laboratory Orders</span>
                                </label>
                                <label class="section-item">
                                    <input type="checkbox" name="sections[]" value="billing" class="section-checkbox">
                                    <span class="checkmark"></span>
                                    <span class="label-text">Billing & Payments</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <button type="button" id="previewBtn" class="btn btn-primary">
                            <i class="fas fa-eye"></i> Preview Record
                        </button>
                        <button type="button" id="generatePdfBtn" class="btn btn-success">
                            <i class="fas fa-file-pdf"></i> Generate PDF
                        </button>
                        <button type="button" id="printBtn" class="btn btn-info">
                            <i class="fas fa-print"></i> Print Record
                        </button>
                    </div>
                </div>

                <!-- Right Panel - Preview -->
                <div class="preview-panel">
                    <div class="panel-header">
                        <h3><i class="fas fa-eye"></i> Preview</h3>
                        <div class="preview-controls">
                            <button type="button" id="refreshPreviewBtn" class="btn btn-sm btn-outline">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>

                    <div class="preview-content" id="previewContent">
                        <div class="preview-placeholder">
                            <i class="fas fa-file-medical-alt"></i>
                            <h4>Medical Record Preview</h4>
                            <p>Select sections and click "Preview Record" to see the medical record content.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div class="loading-overlay" id="loadingOverlay" style="display: none;">
            <div class="loading-content">
                <div class="spinner"></div>
                <p id="loadingText">Processing...</p>
            </div>
        </div>

        <!-- Alert Container -->
        <div class="alert-container" id="alertContainer"></div>
    </section>

    <!-- JavaScript Files -->
    <script src="<?php echo $root_path; ?>/assets/js/medical_print.js"></script>
    <script>
        // Initialize with patient data and security
        window.medicalPrint = window.medicalPrint || {};
        window.medicalPrint.patientId = <?php echo $patientId; ?>;
        window.medicalPrint.patientName = <?php echo json_encode($patientName); ?>;
        window.medicalPrint.rootPath = <?php echo json_encode($root_path); ?>;
        window.medicalPrint.csrfToken = <?php echo json_encode($csrfToken); ?>;
        window.medicalPrint.userPermissions = {
            canPrint: <?php echo $security->hasPermission($employeeId, 'print') ? 'true' : 'false'; ?>,
            canExport: <?php echo $security->hasPermission($employeeId, 'export') ? 'true' : 'false'; ?>,
            canViewAudit: <?php echo $security->hasPermission($employeeId, 'audit') ? 'true' : 'false'; ?>
        };
        
        // Initialize the medical print system
        document.addEventListener('DOMContentLoaded', function() {
            MedicalPrint.init();
        });
    </script>
</body>
</html>