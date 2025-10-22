<?php
// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Include patient session configuration
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';

// Custom error logging function
function logConsultationDetailsError($error_message, $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] CONSULTATION_DETAILS ERROR: {$error_message}";
    if (!empty($context)) {
        $log_entry .= " | Context: " . json_encode($context);
    }
    $log_entry .= " | User: " . (isset($_SESSION['patient_id']) ? $_SESSION['patient_id'] : 'Unknown') . "\n";
    
    $log_file = dirname(__DIR__, 3) . '/logs/consultation_details_errors.log';
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    error_log($log_entry, 3, $log_file);
}

// Safe date formatting function to prevent strtotime() deprecation warnings
function safeFormatDate($date_value, $format = 'F j, Y', $fallback = 'Not specified') {
    if (empty($date_value) || $date_value === null) {
        return $fallback;
    }
    
    $timestamp = strtotime($date_value);
    if ($timestamp === false) {
        return $fallback;
    }
    
    return date($format, $timestamp);
}

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    logConsultationDetailsError("Unauthorized access attempt", [
        'ip' => (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown'),
        'user_agent' => (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown')
    ]);
    http_response_code(401);
    exit('Unauthorized');
}

// Database connection
require_once $root_path . '/config/db.php';

$patient_id = $_SESSION['patient_id'];
$consultation_id = isset($_GET['consultation_id']) ? $_GET['consultation_id'] : null;

if (!$consultation_id || !is_numeric($consultation_id)) {
    logConsultationDetailsError("Invalid consultation ID provided", [
        'consultation_id' => $consultation_id,
        'patient_id' => $patient_id
    ]);
    echo '<div class="alert alert-error">Invalid consultation ID.</div>';
    exit;
}

try {
    // Fetch consultation details with patient verification
    $stmt = $pdo->prepare("
        SELECT c.consultation_id,
               c.visit_id,
               c.chief_complaint,
               c.diagnosis,
               c.treatment_plan,
               c.remarks,
               c.consultation_status,
               c.consultation_date,
               c.updated_at as last_updated,
               c.vitals_id,
               v.visit_date,
               v.time_in as visit_time,
               v.visit_status,
               v.appointment_id,
               e.first_name as doctor_first_name, 
               e.last_name as doctor_last_name,
               e.license_number as doctor_license,
               p.first_name as patient_first_name,
               p.last_name as patient_last_name,
               p.username as patient_id_display,
               p.patient_id,
               p.date_of_birth,
               p.sex,
               p.contact_number,
               b.barangay_name
        FROM consultations c
        LEFT JOIN visits v ON c.visit_id = v.visit_id
        INNER JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN employees e ON c.attending_employee_id = e.employee_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE c.consultation_id = ? AND c.patient_id = ?
    ");
    $stmt->execute([$consultation_id, $patient_id]);
    $consultation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$consultation) {
        echo '<div class="alert alert-error">Consultation not found or access denied.</div>';
        exit;
    }

    // Fetch vitals for this consultation (flexible - can be from visit_id or vitals_id)
    $vitals = null;
    if (!empty($consultation['visit_id'])) {
        // Try to get vitals from visit
        $vitals_stmt = $pdo->prepare("
            SELECT temperature, systolic_bp, diastolic_bp, heart_rate, respiratory_rate, 
                   weight, height, bmi, recorded_at, remarks
            FROM vitals 
            WHERE patient_id = ? AND DATE(recorded_at) = DATE(?)
            ORDER BY recorded_at DESC
            LIMIT 1
        ");
        $vitals_stmt->execute([$consultation['patient_id'], $consultation['consultation_date']]);
        $vitals = $vitals_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // If no vitals from visit date, try to get from consultation's vitals_id directly
    if (!$vitals && !empty($consultation['vitals_id'])) {
        $vitals_stmt = $pdo->prepare("
            SELECT temperature, systolic_bp, diastolic_bp, heart_rate, respiratory_rate, 
                   weight, height, bmi, recorded_at, remarks
            FROM vitals 
            WHERE vitals_id = ?
        ");
        $vitals_stmt->execute([$consultation['vitals_id']]);
        $vitals = $vitals_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // If still no vitals, get the most recent vitals for this patient (within reasonable timeframe)
    if (!$vitals) {
        $vitals_stmt = $pdo->prepare("
            SELECT temperature, systolic_bp, diastolic_bp, heart_rate, respiratory_rate, 
                   weight, height, bmi, recorded_at, remarks
            FROM vitals 
            WHERE patient_id = ? AND recorded_at >= DATE_SUB(?, INTERVAL 7 DAY)
            ORDER BY recorded_at DESC
            LIMIT 1
        ");
        $vitals_stmt->execute([$consultation['patient_id'], $consultation['consultation_date']]);
        $vitals = $vitals_stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Calculate age
    $age = 'N/A';
    if ($consultation['date_of_birth']) {
        $age = date_diff(date_create($consultation['date_of_birth']), date_create('now'))->y;
    }

    ?>

    <!-- Load consultation details CSS if not already loaded -->
    <link rel="stylesheet" href="../../../assets/css/consultation-details.css" type="text/css">
    <style>
        /* Fallback critical styles for consultation details */
        .consultation-details { font-family: inherit; max-width: 100%; margin: 0 auto; }
        .consultation-details .detail-section { margin-bottom: 2rem; background: #ffffff; padding: 1.5rem; border-radius: 10px; box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1); }
        .consultation-details .detail-section h3 { color: #0077b6; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #f0f0f0; font-size: 1.1rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 600; }
        .consultation-details .detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .consultation-details .detail-item { display: flex; flex-direction: column; background: #f8f9fa; padding: 1rem; border-radius: 8px; border-left: 3px solid #0077b6; }
        .consultation-details .detail-item label { font-weight: 600; color: #495057; margin-bottom: 0.25rem; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .consultation-details .detail-item span, .consultation-details .detail-item div { color: #2c3e50; font-size: 0.95rem; line-height: 1.4; font-weight: 500; }
        .consultation-details .no-data { text-align: center; color: #6c757d; font-style: italic; padding: 2rem; background: #f8f9fa; border-radius: 8px; border: 2px dashed #dee2e6; }
        @media (max-width: 768px) { .consultation-details .detail-grid { grid-template-columns: 1fr; } }
    </style>
    
    <div class="consultation-details">

        <!-- Patient & Visit Information -->
        <div class="detail-section">
            <h3><i class="fas fa-user"></i> Patient Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <label>Patient Name:</label>
                    <span><?php echo htmlspecialchars($consultation['patient_first_name'] . ' ' . $consultation['patient_last_name']); ?></span>
                </div>
                <div class="detail-item">
                    <label>Patient ID:</label>
                    <span><?php echo htmlspecialchars($consultation['patient_id_display']); ?></span>
                </div>
                <div class="detail-item">
                    <label>Age:</label>
                    <span><?php echo $age; ?> years old</span>
                </div>
                <div class="detail-item">
                    <label>Gender:</label>
                    <span><?php echo htmlspecialchars(ucfirst(isset($consultation['sex']) ? $consultation['sex'] : 'Not specified')); ?></span>
                </div>
                <div class="detail-item">
                    <label>Contact:</label>
                    <span><?php echo htmlspecialchars(isset($consultation['contact_number']) ? $consultation['contact_number'] : 'Not specified'); ?></span>
                </div>
                <div class="detail-item">
                    <label>Address:</label>
                    <span><?php echo htmlspecialchars(isset($consultation['barangay_name']) ? $consultation['barangay_name'] : 'Not specified'); ?></span>
                </div>
            </div>
        </div>

        <!-- Consultation Information -->
        <div class="detail-section">
            <h3><i class="fas fa-stethoscope"></i> Consultation Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <label>Visit Date:</label>
                    <span><?php 
                        // Try visit_date first, then fallback to consultation_date
                        $date_to_display = !empty($consultation['visit_date']) ? $consultation['visit_date'] : $consultation['consultation_date'];
                        echo safeFormatDate($date_to_display, 'F j, Y');
                    ?></span>
                </div>
                <div class="detail-item">
                    <label>Visit Time:</label>
                    <span><?php 
                        // Try visit_time first, then fallback to consultation_date
                        $time_to_display = !empty($consultation['visit_time']) ? $consultation['visit_time'] : $consultation['consultation_date'];
                        echo safeFormatDate($time_to_display, 'g:i A');
                    ?></span>
                </div>
                <div class="detail-item">
                    <label>Attending Doctor:</label>
                    <span>
                        <?php if (!empty($consultation['doctor_first_name'])): ?>
                            Dr. <?php echo htmlspecialchars($consultation['doctor_first_name'] . ' ' . $consultation['doctor_last_name']); ?>
                            <?php if (!empty($consultation['doctor_specialization'])): ?>
                                <br><small><?php echo htmlspecialchars($consultation['doctor_specialization']); ?></small>
                            <?php endif; ?>
                        <?php else: ?>
                            CHO Staff
                        <?php endif; ?>
                    </span>
                </div>
                <div class="detail-item">
                    <label>Status:</label>
                    <span class="status-badge status-<?php echo htmlspecialchars(str_replace(' ', '-', strtolower($consultation['consultation_status']))); ?>">
                        <?php echo strtoupper($consultation['consultation_status']); ?>
                    </span>
                </div>
                <div class="detail-item full-width">
                    <label>Chief Complaint:</label>
                    <div><?php echo htmlspecialchars($consultation['chief_complaint'] ?: 'No complaint recorded'); ?></div>
                </div>
            </div>
        </div>

        <!-- Vitals Information -->
        <?php if ($vitals): ?>
        <div class="detail-section">
            <h3><i class="fas fa-heartbeat"></i> Vital Signs</h3>
            <div class="detail-grid">
                <?php if (!empty($vitals['temperature'])): ?>
                <div class="detail-item">
                    <label>Temperature:</label>
                    <span><?php echo htmlspecialchars($vitals['temperature']); ?>°C</span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($vitals['systolic_bp']) && !empty($vitals['diastolic_bp'])): ?>
                <div class="detail-item">
                    <label>Blood Pressure:</label>
                    <span><?php echo htmlspecialchars($vitals['systolic_bp'] . '/' . $vitals['diastolic_bp']); ?> mmHg</span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($vitals['heart_rate'])): ?>
                <div class="detail-item">
                    <label>Heart Rate:</label>
                    <span><?php echo htmlspecialchars($vitals['heart_rate']); ?> bpm</span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($vitals['respiratory_rate'])): ?>
                <div class="detail-item">
                    <label>Respiratory Rate:</label>
                    <span><?php echo htmlspecialchars($vitals['respiratory_rate']); ?> rpm</span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($vitals['weight'])): ?>
                <div class="detail-item">
                    <label>Weight:</label>
                    <span><?php echo htmlspecialchars($vitals['weight']); ?> kg</span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($vitals['height'])): ?>
                <div class="detail-item">
                    <label>Height:</label>
                    <span><?php echo htmlspecialchars($vitals['height']); ?> cm</span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($vitals['bmi'])): ?>
                <div class="detail-item">
                    <label>BMI:</label>
                    <span><?php echo htmlspecialchars($vitals['bmi']); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($vitals['remarks'])): ?>
                <div class="detail-item full-width">
                    <label>Vitals Remarks:</label>
                    <span><?php echo nl2br(htmlspecialchars($vitals['remarks'])); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Clinical Assessment -->
        <div class="detail-section">
            <h3><i class="fas fa-notes-medical"></i> Clinical Assessment</h3>
            <div class="detail-grid">
                <div class="detail-item full-width">
                    <label>Diagnosis:</label>
                    <div><?php echo htmlspecialchars($consultation['diagnosis'] ?: 'Pending diagnosis'); ?></div>
                </div>
                
                <?php if (!empty($consultation['treatment_plan'])): ?>
                <div class="detail-item full-width">
                    <label>Treatment Plan:</label>
                    <div><?php echo nl2br(htmlspecialchars($consultation['treatment_plan'])); ?></div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($consultation['notes'])): ?>
                <div class="detail-item full-width">
                    <label>Doctor's Notes:</label>
                    <div><?php echo nl2br(htmlspecialchars($consultation['notes'])); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>


    </div>

    <?php

} catch (Exception $e) {
    logConsultationDetailsError("Failed to load consultation details: " . $e->getMessage(), [
        'consultation_id' => $consultation_id,
        'patient_id' => $patient_id,
        'sql_error' => $e->getMessage(),
        'sql_code' => $e->getCode(),
        'file' => __FILE__,
        'line' => __LINE__
    ]);
    echo '<div class="alert alert-error">Unable to load consultation details at this time. Please try again later.</div>';
}
?>