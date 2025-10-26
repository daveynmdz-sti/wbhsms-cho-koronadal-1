<?php
// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Include patient session configuration
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';

// Custom error logging function
function logPrintConsultationError($error_message, $context = [])
{
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] PRINT_CONSULTATION ERROR: {$error_message}";
    if (!empty($context)) {
        $log_entry .= " | Context: " . json_encode($context);
    }
    $log_entry .= " | User: " . (isset($_SESSION['patient_id']) ? $_SESSION['patient_id'] : 'Unknown') . "\n";

    $log_file = dirname(__DIR__, 3) . '/logs/print_consultation_errors.log';
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    error_log($log_entry, 3, $log_file);
}

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    logPrintConsultationError("Unauthorized print attempt", [
        'ip' => (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown'),
        'user_agent' => (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown')
    ]);
    http_response_code(401);
    exit('Unauthorized: Please log in to print consultation details.');
}

// Database connection
require_once $root_path . '/config/db.php';

$patient_id = $_SESSION['patient_id'];
$consultation_id = isset($_GET['consultation_id']) ? $_GET['consultation_id'] : null;

if (!$consultation_id || !is_numeric($consultation_id)) {
    logPrintConsultationError("Invalid consultation ID for print", [
        'consultation_id' => $consultation_id,
        'patient_id' => $patient_id
    ]);
    http_response_code(400);
    exit('Valid consultation ID is required');
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
               v.visit_date,
               v.time_in as visit_time,
               e.first_name as doctor_first_name, 
               e.last_name as doctor_last_name,
               e.license_number as doctor_license,
               p.first_name as patient_first_name,
               p.last_name as patient_last_name,
               p.username as patient_id_display,
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
        http_response_code(404);
        exit('Consultation not found or access denied');
    }

    // Fetch vitals for this visit with flexible lookup
    $vitals = null;
    if ($consultation['visit_id']) {
        // Try fetching by visit_id first
        $vitals_stmt = $pdo->prepare("
            SELECT temperature, systolic_bp, diastolic_bp, heart_rate, respiratory_rate, 
                   weight, height, bmi
            FROM vitals 
            WHERE visit_id = ?
            LIMIT 1
        ");
        $vitals_stmt->execute([$consultation['visit_id']]);
        $vitals = $vitals_stmt->fetch(PDO::FETCH_ASSOC);

        // If no vitals by visit_id, try by consultation date and patient_id
        if (!$vitals && $consultation['consultation_date']) {
            $vitals_stmt = $pdo->prepare("
                SELECT temperature, systolic_bp, diastolic_bp, heart_rate, respiratory_rate, 
                       weight, height, bmi
                FROM vitals 
                WHERE patient_id = ? AND DATE(created_at) = DATE(?)
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $vitals_stmt->execute([$patient_id, $consultation['consultation_date']]);
            $vitals = $vitals_stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Final fallback: get most recent vitals for the patient
        if (!$vitals) {
            $vitals_stmt = $pdo->prepare("
                SELECT temperature, systolic_bp, diastolic_bp, heart_rate, respiratory_rate, 
                       weight, height, bmi
                FROM vitals 
                WHERE patient_id = ?
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $vitals_stmt->execute([$patient_id]);
            $vitals = $vitals_stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    // Calculate age
    $age = 'N/A';
    if ($consultation['date_of_birth']) {
        $age = date_diff(date_create($consultation['date_of_birth']), date_create('now'))->y;
    }

    // Log the print action for audit trail
    try {
        $log_stmt = $pdo->prepare("
            INSERT INTO consultation_print_logs (consultation_id, patient_id, printed_by, printed_at, ip_address, user_agent, session_id)
            VALUES (?, ?, 'patient', NOW(), ?, ?, ?)
        ");
        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'unknown';
        $session_id = session_id();

        $log_stmt->execute([$consultation_id, $patient_id, $ip_address, $user_agent, $session_id]);
    } catch (Exception $e) {
        logPrintConsultationError("Print audit log error: " . $e->getMessage(), [
            'consultation_id' => $consultation_id,
            'patient_id' => $patient_id,
            'sql_error' => $e->getMessage()
        ]);
    }

?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Consultation Details - <?php echo htmlspecialchars($consultation['patient_first_name'] . ' ' . $consultation['patient_last_name']); ?></title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Arial', 'Helvetica', sans-serif;
                line-height: 1.3;
                color: #333;
                background: white;
                padding: 15mm;
                font-size: 11pt;
            }

            .print-container {
                max-width: 210mm;
                margin: 0 auto;
                background: white;
                position: relative;
                min-height: 250mm;
            }

            /* Header Section */
            .header {
                text-align: center;
                margin-bottom: 15px;
                border-bottom: 2px solid #0077b6;
                padding-bottom: 10px;
            }

            .header h1 {
                color: #0077b6;
                font-size: 18pt;
                margin-bottom: 2px;
                font-weight: bold;
            }

            .header .subtitle {
                color: #666;
                font-size: 12pt;
                margin-bottom: 2px;
                font-weight: 600;
            }

            .header .generated-info {
                color: #888;
                font-size: 9pt;
            }

            /* Two-Column Layout */
            .content-wrapper {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
                margin-bottom: 12px;
            }

            .left-column,
            .right-column {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            /* Section Styling */
            .section {
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 3px;
                padding: 8px;
            }

            .section.full-width {
                grid-column: 1 / -1;
            }

            .section h3 {
                color: #0077b6;
                font-size: 11pt;
                margin-bottom: 6px;
                padding-bottom: 2px;
                border-bottom: 1px solid #ccc;
                font-weight: bold;
            }

            /* Info Rows */
            .info-row {
                display: flex;
                margin-bottom: 3px;
                align-items: flex-start;
            }

            .info-row label {
                font-weight: 600;
                color: #555;
                min-width: 65px;
                font-size: 9pt;
                flex-shrink: 0;
            }

            .info-row span {
                color: #333;
                font-size: 9pt;
                flex: 1;
                word-wrap: break-word;
            }

            /* Vitals Grid */
            .vitals-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 6px;
            }

            .vital-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 3px 0;
                border-bottom: 1px dotted #bbb;
            }

            .vital-item:last-child {
                border-bottom: none;
            }

            .vital-item label {
                font-weight: 500;
                color: #555;
                font-size: 8pt;
            }

            .vital-item span {
                font-size: 9pt;
                font-weight: 600;
                color: #333;
            }

            /* Clinical Text Areas */
            .clinical-text {
                background: white;
                padding: 6px;
                border-radius: 2px;
                border: 1px solid #ccc;
                font-size: 9pt;
                line-height: 1.3;
                min-height: 40px;
            }

            /* Signature Section */
            .signature-section {
                position: absolute;
                bottom: 30px;
                right: 0;
                text-align: center;
                width: 200px;
            }

            .signature-box {
                border: 1px solid #333;
                height: 60px;
                margin-bottom: 5px;
                position: relative;
            }

            .signature-label {
                position: absolute;
                bottom: -15px;
                left: 0;
                right: 0;
                font-size: 8pt;
                color: #666;
            }

            .signature-line {
                border-top: 1px solid #333;
                margin-top: 35px;
                padding-top: 3px;
            }

            .doctor-name {
                font-weight: bold;
                font-size: 10pt;
                color: #333;
            }

            .doctor-title {
                font-size: 8pt;
                color: #666;
                margin-top: 1px;
            }

            .doctor-license {
                font-size: 8pt;
                color: #666;
                margin-top: 1px;
            }

            /* Print Info Footer */
            .print-info {
                position: absolute;
                bottom: 10px;
                left: 0;
                font-size: 7pt;
                color: #888;
            }

            /* Print Button */
            .no-print {
                text-align: center;
                margin-bottom: 15px;
            }

            .print-btn {
                background: #0077b6;
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 3px;
                cursor: pointer;
                font-size: 11pt;
                font-weight: 600;
            }

            .print-btn:hover {
                background: #005a85;
            }

            /* Print Media Query */
            @media print {
                body {
                    padding: 0;
                    font-size: 10pt;
                }

                .print-container {
                    padding: 10mm;
                    box-shadow: none;
                    max-width: none;
                    margin: 0;
                }

                .no-print {
                    display: none !important;
                }

                .header h1 {
                    font-size: 16pt;
                }

                .section h3 {
                    font-size: 10pt;
                }

                .signature-section {
                    position: relative;
                    bottom: auto;
                    right: auto;
                    margin-top: 20px;
                    float: right;
                    clear: both;
                }

                .print-info {
                    position: relative;
                    bottom: auto;
                    left: auto;
                    margin-top: 10px;
                    text-align: left;
                }
            }

            /* Mobile Responsive */
            @media (max-width: 600px) {
                .content-wrapper {
                    grid-template-columns: 1fr;
                    gap: 10px;
                }

                .vitals-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
    </head>

    <body>
        <div class="print-container">
            <div class="header">
                <h1>City Health Office - Koronadal</h1>
                <div class="subtitle">Consultation Record</div>
                <div class="generated-info">Generated on <?php echo date('F j, Y g:i A'); ?></div>
            </div>

            <div class="content-wrapper">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Patient Information -->
                    <div class="section">
                        <h3>Patient Information</h3>
                        <div class="info-row">
                            <label>Name:</label>
                            <span><?php echo htmlspecialchars($consultation['patient_first_name'] . ' ' . $consultation['patient_last_name']); ?></span>
                        </div>
                        <div class="info-row">
                            <label>Patient ID:</label>
                            <span><?php echo htmlspecialchars($consultation['patient_id_display']); ?></span>
                        </div>
                        <div class="info-row">
                            <label>Age:</label>
                            <span><?php echo $age; ?> years old</span>
                        </div>
                        <div class="info-row">
                            <label>Gender:</label>
                            <span><?php echo htmlspecialchars(ucfirst(isset($consultation['sex']) ? $consultation['sex'] : 'Not specified')); ?></span>
                        </div>
                        <div class="info-row">
                            <label>Contact:</label>
                            <span><?php echo htmlspecialchars(isset($consultation['contact_number']) ? $consultation['contact_number'] : 'Not specified'); ?></span>
                        </div>
                        <div class="info-row">
                            <label>Address:</label>
                            <span><?php echo htmlspecialchars(isset($consultation['barangay_name']) ? $consultation['barangay_name'] : 'Not specified'); ?></span>
                        </div>
                    </div>

                    <!-- Vital Signs -->
                    <?php if ($vitals): ?>
                        <div class="section">
                            <h3>Vital Signs</h3>
                            <div class="vitals-grid">
                                <?php if (!empty($vitals['temperature'])): ?>
                                    <div class="vital-item">
                                        <label>Temperature</label>
                                        <span><?php echo htmlspecialchars($vitals['temperature']); ?>°C</span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($vitals['systolic_bp']) && !empty($vitals['diastolic_bp'])): ?>
                                    <div class="vital-item">
                                        <label>Blood Pressure</label>
                                        <span><?php echo htmlspecialchars($vitals['systolic_bp'] . '/' . $vitals['diastolic_bp']); ?></span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($vitals['heart_rate'])): ?>
                                    <div class="vital-item">
                                        <label>Heart Rate</label>
                                        <span><?php echo htmlspecialchars($vitals['heart_rate']); ?> bpm</span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($vitals['respiratory_rate'])): ?>
                                    <div class="vital-item">
                                        <label>Respiratory Rate</label>
                                        <span><?php echo htmlspecialchars($vitals['respiratory_rate']); ?>/min</span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($vitals['weight'])): ?>
                                    <div class="vital-item">
                                        <label>Weight</label>
                                        <span><?php echo htmlspecialchars($vitals['weight']); ?> kg</span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($vitals['height'])): ?>
                                    <div class="vital-item">
                                        <label>Height</label>
                                        <span><?php echo htmlspecialchars($vitals['height']); ?> cm</span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($vitals['bmi'])): ?>
                                    <div class="vital-item">
                                        <label>BMI</label>
                                        <span><?php echo htmlspecialchars($vitals['bmi']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- Visit Information -->
                    <div class="section">
                        <h3>Visit Details</h3>
                        <div class="info-row">
                            <label>Date:</label>
                            <span><?php echo $consultation['visit_date'] ? date('M j, Y', strtotime($consultation['visit_date'])) : 'Not specified'; ?></span>
                        </div>
                        <div class="info-row">
                            <label>Time:</label>
                            <span><?php echo $consultation['visit_time'] ? date('g:i A', strtotime($consultation['visit_time'])) : 'Not specified'; ?></span>
                        </div>
                        <div class="info-row">
                            <label>Doctor:</label>
                            <span>
                                <?php if (!empty($consultation['doctor_first_name'])): ?>
                                    Dr. <?php echo htmlspecialchars($consultation['doctor_first_name'] . ' ' . $consultation['doctor_last_name']); ?>
                                <?php else: ?>
                                    CHO Staff
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <label>Consult ID:</label>
                            <span><?php echo htmlspecialchars($consultation['consultation_id']); ?></span>
                        </div>
                    </div>

                    <!-- Status Information -->
                    <div class="section">
                        <h3>Status</h3>
                        <div class="info-row">
                            <label>Status:</label>
                            <span><?php echo htmlspecialchars(ucfirst($consultation['consultation_status']) ?: 'Active'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Chief Complaint (Full Width) -->
            <div class="section full-width">
                <h3>Chief Complaint</h3>
                <div class="clinical-text">
                    <?php echo htmlspecialchars($consultation['chief_complaint'] ?: 'No complaint recorded'); ?>
                </div>
            </div>

            <!-- Clinical Assessment (Full Width) -->
            <div class="section full-width">
                <h3>Diagnosis</h3>
                <div class="clinical-text">
                    <?php echo htmlspecialchars($consultation['diagnosis'] ?: 'Pending diagnosis'); ?>
                </div>
            </div>

            <?php if (!empty($consultation['treatment_plan'])): ?>
                <div class="section full-width">
                    <h3>Treatment Plan</h3>
                    <div class="clinical-text">
                        <?php echo nl2br(htmlspecialchars($consultation['treatment_plan'])); ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($consultation['remarks'])): ?>
                <div class="section full-width">
                    <h3>Doctor's Notes</h3>
                    <div class="clinical-text">
                        <?php echo nl2br(htmlspecialchars($consultation['remarks'])); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Signature Section -->
            <div class="signature-section">
                <div class="signature-line">
                    <div class="doctor-name">
                        <?php if (!empty($consultation['doctor_first_name'])): ?>
                            Dr. <?php echo htmlspecialchars($consultation['doctor_first_name'] . ' ' . $consultation['doctor_last_name']); ?>
                        <?php else: ?>
                            CHO Medical Officer
                        <?php endif; ?>
                    </div>
                    <div class="doctor-title">Attending Physician</div>
                    <?php if (!empty($consultation['doctor_license'])): ?>
                        <div class="doctor-license">License No: <?php echo htmlspecialchars($consultation['doctor_license']); ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Print Info Footer -->
            <div class="print-info">
                Official consultation record | CHO Koronadal | Printed: <?php echo date('M j, Y g:i A'); ?>
            </div>

        </div>
        <div class="no-print" style="margin-top: 15px; text-align: center;">
            <button onclick="window.print()" class="print-btn">
                Print Consultation Details
            </button>
        </div>

        <script>
            // Auto-print when page loads (optional)
            // window.onload = function() { window.print(); }
        </script>
    </body>

    </html>

<?php
} catch (Exception $e) {
    logPrintConsultationError("Failed to load consultation for printing: " . $e->getMessage(), [
        'consultation_id' => $consultation_id,
        'patient_id' => $patient_id,
        'sql_error' => $e->getMessage(),
        'sql_code' => $e->getCode(),
        'file' => __FILE__,
        'line' => __LINE__
    ]);
    http_response_code(500);
    echo '<div style="text-align: center; padding: 2rem; color: #721c24; background: #f8d7da; border-radius: 8px; margin: 2rem;">
            <h3>Error Loading Consultation</h3>
            <p>Unable to load consultation details. Please try again later.</p>
          </div>';
    error_log("Print consultation error: " . $e->getMessage());
}
?>