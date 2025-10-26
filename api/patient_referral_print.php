<?php
/**
 * Patient Referral Print View
 * Simple HTML print view for referrals - no PDF dependencies required
 */

// Include dependencies
$root_path = dirname(__DIR__);

// Determine session type based on referer or query parameter
$is_employee_context = false;
$referer = $_SERVER['HTTP_REFERER'] ?? '';

// Check if called from management portal
if (strpos($referer, '/management/') !== false || 
    strpos($referer, '/admin/') !== false || 
    strpos($referer, '/dho/') !== false || 
    strpos($referer, '/bhw/') !== false ||
    isset($_GET['employee_access'])) {
    $is_employee_context = true;
}

// Load appropriate session
if ($is_employee_context) {
    require_once $root_path . '/config/session/employee_session.php';
} else {
    require_once $root_path . '/config/session/patient_session.php';
}

require_once $root_path . '/config/db.php';

// Check authorization based on session type
if ($is_employee_context) {
    // Employee access - check for employee login and permissions
    if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
        http_response_code(401);
        echo '<h1>Unauthorized Access</h1><p>Please log in as an employee to view this referral.</p>';
        exit();
    }
    
    // Check if role is authorized for referrals
    $authorized_roles = ['doctor', 'nurse', 'admin', 'records_officer', 'bhw', 'dho'];
    if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
        http_response_code(403);
        echo '<h1>Access Denied</h1><p>You do not have permission to view referrals.</p>';
        exit();
    }
} else {
    // Patient access - check for patient login
    if (!isset($_SESSION['patient_id'])) {
        http_response_code(401);
        echo '<h1>Unauthorized Access</h1><p>Please log in as a patient to view this referral.</p>';
        exit();
    }
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    echo '<h1>Database Error</h1><p>Unable to connect to database.</p>';
    exit();
}

// Get patient ID based on session type
$patient_id = null;
if ($is_employee_context) {
    // For employee access, patient ID will be determined from the referral
    $patient_id = null; // Will be set after fetching referral
} else {
    // For patient access, use session patient ID
    $patient_id = $_SESSION['patient_id'];
}

// Get referral ID from GET or POST data
$referral_id = 0;
if (isset($_GET['referral_id']) && is_numeric($_GET['referral_id'])) {
    $referral_id = (int)$_GET['referral_id'];
} elseif (isset($_POST['referral_id']) && is_numeric($_POST['referral_id'])) {
    $referral_id = (int)$_POST['referral_id'];
}

// Check for info message (e.g., fallback from PDF generation)
$info_message = $_GET['info_message'] ?? '';
$display_mode = $_GET['display'] ?? 'inline';

if (!$referral_id || $referral_id <= 0) {
    http_response_code(400);
    echo '<h1>Invalid Request</h1><p>Invalid referral ID provided.</p>';
    exit();
}

try {
    // Prepare query based on access context
    if ($is_employee_context) {
        // Employee can access any referral - no patient restriction
        $referral_stmt = $conn->prepare("
            SELECT r.referral_id, r.referral_num, r.patient_id, r.referral_reason, 
                   r.destination_type, r.referred_to_facility_id, r.external_facility_name,
                   r.referral_date, r.status, r.referred_by, r.service_id,
                   p.first_name, p.middle_name, p.last_name, p.username as patient_number,
                   p.date_of_birth, p.sex, p.contact_number,
                   b.barangay_name as barangay,
                   e.first_name as issuer_first_name, e.last_name as issuer_last_name, 
                   rol.role_name as issuer_position,
                   f.name as facility_name, f.type as facility_type,
                   s.name as service_name
            FROM referrals r
            LEFT JOIN patients p ON r.patient_id = p.patient_id
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            LEFT JOIN employees e ON r.referred_by = e.employee_id
            LEFT JOIN roles rol ON e.role_id = rol.role_id
            LEFT JOIN facilities f ON r.referred_to_facility_id = f.facility_id
            LEFT JOIN services s ON r.service_id = s.service_id
            WHERE r.referral_id = ?
        ");
        
        if (!$referral_stmt) {
            throw new Exception('Database prepare failed: ' . $conn->error);
        }
        
        $referral_stmt->bind_param("i", $referral_id);
    } else {
        // Patient can only access their own referrals
        $referral_stmt = $conn->prepare("
            SELECT r.referral_id, r.referral_num, r.patient_id, r.referral_reason, 
                   r.destination_type, r.referred_to_facility_id, r.external_facility_name,
                   r.referral_date, r.status, r.referred_by, r.service_id,
                   p.first_name, p.middle_name, p.last_name, p.username as patient_number,
                   p.date_of_birth, p.sex, p.contact_number,
                   b.barangay_name as barangay,
                   e.first_name as issuer_first_name, e.last_name as issuer_last_name, 
                   rol.role_name as issuer_position,
                   f.name as facility_name, f.type as facility_type,
                   s.name as service_name
            FROM referrals r
            LEFT JOIN patients p ON r.patient_id = p.patient_id
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            LEFT JOIN employees e ON r.referred_by = e.employee_id
            LEFT JOIN roles rol ON e.role_id = rol.role_id
            LEFT JOIN facilities f ON r.referred_to_facility_id = f.facility_id
            LEFT JOIN services s ON r.service_id = s.service_id
            WHERE r.referral_id = ? AND r.patient_id = ?
        ");
        
        if (!$referral_stmt) {
            throw new Exception('Database prepare failed: ' . $conn->error);
        }
        
        $referral_stmt->bind_param("ii", $referral_id, $patient_id);
    }
    $referral_stmt->execute();
    $referral_result = $referral_stmt->get_result();

    if ($referral_result->num_rows === 0) {
        http_response_code(404);
        echo '<h1>Not Found</h1><p>Referral not found or access denied.</p>';
        exit();
    }

    $referral = $referral_result->fetch_assoc();
    $referral_stmt->close();

    // Get latest vitals for this patient
    $vitals = null;
    if ($referral['patient_id']) {
        $vitals_stmt = $conn->prepare("
            SELECT systolic_bp, diastolic_bp, heart_rate, respiratory_rate, 
                   temperature, weight, height, recorded_at,
                   CONCAT(systolic_bp, '/', diastolic_bp) as blood_pressure
            FROM vitals 
            WHERE patient_id = ? 
            ORDER BY recorded_at DESC 
            LIMIT 1
        ");
        
        if ($vitals_stmt) {
            $vitals_stmt->bind_param("i", $referral['patient_id']);
            $vitals_stmt->execute();
            $vitals_result = $vitals_stmt->get_result();
            if ($vitals_result->num_rows > 0) {
                $vitals = $vitals_result->fetch_assoc();
            }
            $vitals_stmt->close();
        }
    }

    // Calculate age if date of birth is available
    if ($referral['date_of_birth']) {
        $dob = new DateTime($referral['date_of_birth']);
        $now = new DateTime();
        $age = $dob->diff($now)->y;
        $referral['age'] = $age;
    }

    // Format patient name
    $patient_name = trim($referral['first_name'] . ' ' . ($referral['middle_name'] ? $referral['middle_name'] . ' ' : '') . $referral['last_name']);
    $issuer_name = trim($referral['issuer_first_name'] . ' ' . $referral['issuer_last_name']);

    // Determine destination
    $destination = '';
    if ($referral['destination_type'] === 'external') {
        $destination = $referral['external_facility_name'] ?: 'External Facility';
    } else {
        $destination = $referral['facility_name'] ?: 'Internal Facility';
    }

    // Format dates
    $referral_date = date('F d, Y g:i A', strtotime($referral['referral_date']));
    $current_date = date('F d, Y g:i A');

} catch (Exception $e) {
    error_log("Patient Referral Print Error: " . $e->getMessage());
    http_response_code(500);
    echo '<h1>Error</h1><p>Error loading referral: ' . htmlspecialchars($e->getMessage()) . '</p>';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Referral - <?php echo htmlspecialchars($referral['referral_num']); ?></title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none !important; }
            .page-break { page-break-after: always; }
        }
        
        body {
            font-family: "Times New Roman", serif;
            font-size: 12pt;
            line-height: 1.4;
            color: #000;
            margin: 20px;
            background: white;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 3px solid #0077b6;
            padding-bottom: 15px;
        }
        
        .header h1 {
            color: #0077b6;
            font-size: 24pt;
            font-weight: bold;
            margin: 0 0 5px 0;
        }
        
        .header h2 {
            color: #023e8a;
            font-size: 18pt;
            font-weight: normal;
            margin: 0 0 10px 0;
        }
        
        .header .subtitle {
            font-size: 10pt;
            color: #666;
            margin-top: 8px;
        }
        
        .document-title {
            text-align: center;
            font-size: 18pt;
            font-weight: bold;
            color: #0077b6;
            margin: 20px 0 15px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .referral-number {
            text-align: center;
            font-size: 14pt;
            font-weight: bold;
            margin-bottom: 20px;
            background: #f8f9fa;
            padding: 10px;
            border: 2px solid #dee2e6;
            border-radius: 5px;
        }
        
        .info-section {
            margin-bottom: 15px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .section-header {
            background: #0077b6;
            color: white;
            padding: 8px 12px;
            font-weight: bold;
            font-size: 13pt;
        }
        
        .section-content {
            padding: 12px;
            background: #fff;
        }
        
        .info-grid {
            display: table;
            width: 100%;
            margin-bottom: 10px;
        }
        
        .info-row {
            display: table-row;
        }
        
        .info-label {
            display: table-cell;
            font-weight: bold;
            padding: 3px 12px 3px 0;
            width: 30%;
            vertical-align: top;
        }
        
        .info-value {
            display: table-cell;
            padding: 3px 0;
            vertical-align: top;
        }
        
        .reason-box {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 10px;
            margin: 10px 0;
            font-style: italic;
            min-height: 40px;
        }
        
        .vitals-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        
        .vitals-table th,
        .vitals-table td {
            border: 1px solid #dee2e6;
            padding: 6px 8px;
            text-align: left;
        }
        
        .vitals-table th {
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 10pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-active { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-completed { background: #d1ecf1; color: #0c5460; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        
        .footer {
            margin-top: 25px;
            padding-top: 12px;
            border-top: 1px solid #dee2e6;
            font-size: 10pt;
            color: #666;
        }
        
        .signatures {
            margin-top: 25px;
            display: table;
            width: 100%;
        }
        
        .signature-block {
            display: table-cell;
            width: 50%;
            text-align: center;
            padding: 12px;
            vertical-align: top;
        }
        
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 40px;
            padding-top: 5px;
            font-weight: bold;
        }
        
        .signature-title {
            font-size: 10pt;
            color: #666;
            margin-top: 5px;
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #0077b6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            font-size: 14pt;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
        }
        
        .print-button:hover {
            background: #023e8a;
        }
    </style>
    <script>
        // Auto-print when page loads (optional)
        window.onload = function() {
            // Uncomment the line below to auto-print
            // window.print();
        }
        
        function printPage() {
            window.print();
        }
    </script>
</head>
<body>
    <?php if (!empty($info_message)): ?>
    <div class="info-banner no-print" style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; padding: 12px; margin-bottom: 15px; color: #856404; font-size: 14px;">
        <strong>📄 Notice:</strong> <?php echo htmlspecialchars(urldecode($info_message)); ?>
    </div>
    <?php endif; ?>

    <button class="print-button no-print" onclick="printPage()">
        <i class="fas fa-print"></i> Print
    </button>

    <div class="header">
        <h1>City Health Office</h1>
        <h2>Koronadal City, South Cotabato</h2>
        <div class="subtitle">
            "Committed to Health, Dedicated to Care"<br>
            Tel: (083) 228-6012 | Email: cho.koronadal@gmail.com
        </div>
    </div>
    
    <div class="document-title">Medical Referral</div>
    
    <div class="referral-number">
        Referral No: <?php echo htmlspecialchars($referral['referral_num']); ?>
    </div>
    
    <div class="info-section">
        <div class="section-header">Patient Information</div>
        <div class="section-content">
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Patient Name:</div>
                    <div class="info-value"><?php echo htmlspecialchars($patient_name); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Patient ID:</div>
                    <div class="info-value"><?php echo htmlspecialchars($referral['patient_number']); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Date of Birth:</div>
                    <div class="info-value"><?php echo $referral['date_of_birth'] ? date('F d, Y', strtotime($referral['date_of_birth'])) : 'Not specified'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Age:</div>
                    <div class="info-value"><?php echo isset($referral['age']) ? $referral['age'] . ' years old' : 'Not specified'; ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Sex:</div>
                    <div class="info-value"><?php echo htmlspecialchars($referral['sex'] ?: 'Not specified'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Address:</div>
                    <div class="info-value"><?php echo htmlspecialchars($referral['barangay'] ?: 'Not specified'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Contact Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($referral['contact_number'] ?: 'Not specified'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($vitals && !empty($vitals['blood_pressure'])): ?>
    <div class="info-section">
        <div class="section-header">Latest Vital Signs</div>
        <div class="section-content">
            <table class="vitals-table">
                <thead>
                    <tr>
                        <th>Blood Pressure</th>
                        <th>Heart Rate</th>
                        <th>Respiratory Rate</th>
                        <th>Temperature</th>
                        <th>Weight</th>
                        <th>Height</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php echo htmlspecialchars($vitals['blood_pressure'] ?: 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($vitals['heart_rate'] ? $vitals['heart_rate'] . ' bpm' : 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($vitals['respiratory_rate'] ? $vitals['respiratory_rate'] . '/min' : 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($vitals['temperature'] ? $vitals['temperature'] . '°C' : 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($vitals['weight'] ? $vitals['weight'] . ' kg' : 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($vitals['height'] ? $vitals['height'] . ' cm' : 'N/A'); ?></td>
                    </tr>
                </tbody>
            </table>
            <p style="font-size: 10pt; color: #666; margin: 8px 0 0 0;">
                Recorded: <?php echo $vitals['recorded_at'] ? date('M d, Y g:i A', strtotime($vitals['recorded_at'])) : 'Not recorded'; ?>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <div class="info-section">
        <div class="section-header">Referral Details</div>
        <div class="section-content">
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">Referred To:</div>
                    <div class="info-value"><?php echo htmlspecialchars($destination); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Service:</div>
                    <div class="info-value"><?php echo htmlspecialchars($referral['service_name'] ?: 'General Consultation'); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Referral Date:</div>
                    <div class="info-value"><?php echo date('M d, Y g:i A', strtotime($referral['referral_date'])); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label">Status:</div>
                    <div class="info-value">
                        <span class="status-badge status-<?php echo strtolower($referral['status']); ?>"><?php echo ucfirst($referral['status']); ?></span>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-label">Referred By:</div>
                    <div class="info-value"><?php echo htmlspecialchars($issuer_name); ?> (<?php echo htmlspecialchars($referral['issuer_position'] ?: 'Healthcare Provider'); ?>)</div>
                </div>
            </div>
            
            <div style="margin-top: 15px;">
                <strong>Reason for Referral:</strong>
                <div class="reason-box">
                    <?php echo nl2br(htmlspecialchars($referral['referral_reason'] ?: 'No specific reason provided.')); ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="signatures">
        <div class="signature-block">
            <div class="signature-line"><?php echo htmlspecialchars($issuer_name); ?></div>
            <div class="signature-title">Referring Healthcare Provider</div>
            <div class="signature-title">Date: <?php echo date('M d, Y', strtotime($referral['referral_date'])); ?></div>
        </div>
        <div class="signature-block">
            <div class="signature-line">_________________________</div>
            <div class="signature-title">Receiving Healthcare Provider</div>
            <div class="signature-title">Date: _______________</div>
        </div>
    </div>
    
    <div class="footer">
        <p style="margin: 0 0 5px 0;"><strong>Note:</strong> This is an official medical referral document. Please present this to the receiving healthcare facility.</p>
        <p style="margin: 0;">Generated: <?php echo date('M d, Y g:i A'); ?> | CHO Koronadal WBHSMS</p>
    </div>
</body>
</html>