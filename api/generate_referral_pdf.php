<?php
/**
 * Generate Referral PDF  
 * Creates a downloadable PDF of referral details
 */

// Include dependencies
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Try to load Composer autoloader
$dompdf_available = false;
if (file_exists($root_path . '/vendor/autoload.php')) {
    require_once $root_path . '/vendor/autoload.php';
    
    // Check if DomPDF and its dependencies are available
    $dompdf_available = class_exists('Dompdf\Dompdf') && 
                       class_exists('Dompdf\Options') && 
                       class_exists('Masterminds\HTML5');  // Check for required HTML5 parser
}

// If dompdf is not available or missing dependencies, redirect to HTML print version
if (!$dompdf_available) {
    $referral_id = $_GET['referral_id'] ?? '';
    $display = $_GET['display'] ?? 'inline';
    
    // Redirect to HTML print version with informational message and employee access flag
    $message = urlencode("PDF generation is currently unavailable. Using print-friendly view instead.");
    header("Location: patient_referral_print.php?referral_id=" . urlencode($referral_id) . "&display=" . urlencode($display) . "&info_message=" . $message . "&employee_access=1");
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if role is authorized for referrals
$authorized_roles = ['doctor', 'nurse', 'admin', 'records_officer', 'bhw', 'dho'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

$employee_id = $_SESSION['employee_id'];
$employee_role = strtolower($_SESSION['role']);

// Get referral ID from GET or POST data
$referral_id = 0;
if (isset($_GET['referral_id']) && is_numeric($_GET['referral_id'])) {
    $referral_id = (int)$_GET['referral_id'];
} elseif (isset($_POST['referral_id']) && is_numeric($_POST['referral_id'])) {
    $referral_id = (int)$_POST['referral_id'];
}

if (!$referral_id || $referral_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid referral ID']);
    exit();
}

try {
    // Get referral with complete patient information (same query as get_referral_details.php)
    $referral_stmt = $conn->prepare("
        SELECT r.*, 
               p.first_name, p.middle_name, p.last_name, p.username as patient_number, 
               b.barangay_name as barangay, p.date_of_birth, p.sex, p.contact_number,
               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age,
               e.first_name as issuer_first_name, e.last_name as issuer_last_name,
               ro.role_name as issuer_position,
               f.name as referred_facility_name,
               s.name as service_name
        FROM referrals r
        LEFT JOIN patients p ON r.patient_id = p.patient_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN employees e ON r.referred_by = e.employee_id
        LEFT JOIN roles ro ON e.role_id = ro.role_id
        LEFT JOIN facilities f ON r.referred_to_facility_id = f.facility_id
        LEFT JOIN services s ON r.service_id = s.service_id
        WHERE r.referral_id = ?
    ");

    $referral_stmt->bind_param("i", $referral_id);
    $referral_stmt->execute();
    $referral_result = $referral_stmt->get_result();
    
    if ($referral_result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Referral not found']);
        exit();
    }
    
    $referral = $referral_result->fetch_assoc();
    $referral_stmt->close();

    // Get latest patient vitals
    $vitals_stmt = $conn->prepare("
        SELECT systolic_bp, diastolic_bp, 
               CONCAT(systolic_bp, '/', diastolic_bp) as blood_pressure,
               heart_rate, respiratory_rate, temperature, 
               weight, height, recorded_at, remarks
        FROM vitals 
        WHERE patient_id = ? 
        ORDER BY recorded_at DESC 
        LIMIT 1
    ");
    
    $vitals_stmt->bind_param("i", $referral['patient_id']);
    $vitals_stmt->execute();
    $vitals_result = $vitals_stmt->get_result();
    $vitals = $vitals_result->fetch_assoc();
    $vitals_stmt->close();

    // Format patient name
    $patient_name = trim($referral['first_name'] . ' ' . ($referral['middle_name'] ? $referral['middle_name'] . ' ' : '') . $referral['last_name']);
    $issuer_name = trim($referral['issuer_first_name'] . ' ' . $referral['issuer_last_name']);

    // Determine destination
    $destination = '';
    if ($referral['destination_type'] === 'external') {
        $destination = $referral['external_facility_name'] ?: 'External Facility';
    } else {
        $destination = $referral['referred_facility_name'] ?: 'Internal Facility';
    }

    // Format dates
    $referral_date = date('F d, Y g:i A', strtotime($referral['referral_date']));
    $current_date = date('F d, Y g:i A');

    // Generate simple, DomPDF-compatible HTML content
    $html = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style type="text/css">
            body {
                font-family: Arial, sans-serif;
                font-size: 11pt;
                line-height: 1.4;
                margin: 20px;
                color: #000;
            }
            .header {
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid #0077b6;
                padding-bottom: 10px;
            }
            .header h1 {
                font-size: 16pt;
                color: #0077b6;
                margin: 0 0 5px 0;
            }
            .header h2 {
                font-size: 14pt;
                color: #023e8a;
                margin: 0 0 5px 0;
            }
            .section {
                margin-bottom: 15px;
                border: 1px solid #ccc;
                padding: 10px;
            }
            .section-title {
                background: #0077b6;
                color: white;
                padding: 5px 10px;
                margin: -10px -10px 10px -10px;
                font-weight: bold;
            }
            .field {
                margin-bottom: 8px;
            }
            .label {
                font-weight: bold;
                display: inline-block;
                width: 150px;
            }
            .value {
                display: inline-block;
            }
            .reason-box {
                background: #f5f5f5;
                border: 1px solid #ddd;
                padding: 10px;
                margin: 10px 0;
                font-style: italic;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>City Health Office</h1>
            <h2>Koronadal City, South Cotabato</h2>
            <p>"Committed to Health, Dedicated to Care"</p>
            <p>Tel: (083) 228-6012 | Email: cho.koronadal@gmail.com</p>
            <h2 style="margin-top: 15px;">Medical Referral</h2>
            <p><strong>Referral No: ' . htmlspecialchars($referral['referral_num']) . '</strong></p>
        </div>
        
        <div class="section">
            <div class="section-title">Patient Information</div>
            <div class="field">
                <span class="label">Patient Name:</span>
                <span class="value">' . htmlspecialchars($patient_name) . '</span>
            </div>
            <div class="field">
                <span class="label">Patient ID:</span>
                <span class="value">' . htmlspecialchars($referral['patient_number']) . '</span>
            </div>
            <div class="field">
                <span class="label">Date of Birth:</span>
                <span class="value">' . ($referral['date_of_birth'] ? date('F d, Y', strtotime($referral['date_of_birth'])) : 'Not specified') . '</span>
            </div>
            <div class="field">
                <span class="label">Age:</span>
                <span class="value">' . ($referral['age'] ? $referral['age'] . ' years old' : 'Not specified') . '</span>
            </div>
            <div class="field">
                <span class="label">Sex:</span>
                <span class="value">' . htmlspecialchars($referral['sex'] ?: 'Not specified') . '</span>
            </div>
            <div class="field">
                <span class="label">Address:</span>
                <span class="value">' . htmlspecialchars($referral['barangay'] ?: 'Not specified') . '</span>
            </div>
            <div class="field">
                <span class="label">Contact Number:</span>
                <span class="value">' . htmlspecialchars($referral['contact_number'] ?: 'Not specified') . '</span>
            </div>
        </div>';

    // Add vitals section if available
    if ($vitals && !empty($vitals['blood_pressure'])) {
        $html .= '
        <div class="section">
            <div class="section-title">Latest Vital Signs</div>
            <div class="field">
                <span class="label">Blood Pressure:</span>
                <span class="value">' . htmlspecialchars($vitals['blood_pressure'] ?: 'N/A') . '</span>
            </div>
            <div class="field">
                <span class="label">Heart Rate:</span>
                <span class="value">' . htmlspecialchars($vitals['heart_rate'] ? $vitals['heart_rate'] . ' bpm' : 'N/A') . '</span>
            </div>
            <div class="field">
                <span class="label">Temperature:</span>
                <span class="value">' . htmlspecialchars($vitals['temperature'] ? $vitals['temperature'] . '°C' : 'N/A') . '</span>
            </div>
            <div class="field">
                <span class="label">Weight:</span>
                <span class="value">' . htmlspecialchars($vitals['weight'] ? $vitals['weight'] . ' kg' : 'N/A') . '</span>
            </div>
            <p style="font-size: 9pt; color: #666; margin: 10px 0 0 0;">
                Recorded: ' . ($vitals['recorded_at'] ? date('M d, Y g:i A', strtotime($vitals['recorded_at'])) : 'Not recorded') . '
            </p>
        </div>';
    }

    $html .= '
        <div class="section">
            <div class="section-title">Referral Details</div>
            <div class="field">
                <span class="label">Referred To:</span>
                <span class="value">' . htmlspecialchars($destination) . '</span>
            </div>
            <div class="field">
                <span class="label">Service:</span>
                <span class="value">' . htmlspecialchars($referral['service_name'] ?: 'General Consultation') . '</span>
            </div>
            <div class="field">
                <span class="label">Referral Date:</span>
                <span class="value">' . date('M d, Y g:i A', strtotime($referral['referral_date'])) . '</span>
            </div>
            <div class="field">
                <span class="label">Status:</span>
                <span class="value">' . ucfirst($referral['status']) . '</span>
            </div>
            <div class="field">
                <span class="label">Referred By:</span>
                <span class="value">' . htmlspecialchars($issuer_name) . ' (' . htmlspecialchars($referral['issuer_position'] ?: 'Healthcare Provider') . ')</span>
            </div>
            
            <div style="margin-top: 15px;">
                <strong>Reason for Referral:</strong>
                <div class="reason-box">
                    ' . nl2br(htmlspecialchars($referral['referral_reason'] ?: 'No specific reason provided.')) . '
                </div>
            </div>
        </div>
        
        <div style="margin-top: 30px; text-align: center;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 50%; text-align: center; padding: 20px;">
                        <div style="border-top: 1px solid #000; margin-top: 40px; padding-top: 5px;">
                            <strong>' . htmlspecialchars($issuer_name) . '</strong><br>
                            <small>Referring Healthcare Provider</small><br>
                            <small>Date: ' . date('M d, Y', strtotime($referral['referral_date'])) . '</small>
                        </div>
                    </td>
                    <td style="width: 50%; text-align: center; padding: 20px;">
                        <div style="border-top: 1px solid #000; margin-top: 40px; padding-top: 5px;">
                            <strong>_________________________</strong><br>
                            <small>Receiving Healthcare Provider</small><br>
                            <small>Date: _______________</small>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        
        <div style="margin-top: 20px; padding-top: 10px; border-top: 1px solid #ccc; font-size: 9pt; color: #666;">
            <p><strong>Note:</strong> This is an official medical referral document. Please present this to the receiving healthcare facility.</p>
            <p>Generated: ' . date('M d, Y g:i A') . ' | CHO Koronadal WBHSMS</p>
        </div>
    </body>
    </html>';

    // Configure Dompdf using fully qualified class names with strict security
    $options = new \Dompdf\Options();
    $options->set('defaultFont', 'Arial');  // Use built-in font
    $options->set('isRemoteEnabled', false);  // Disable remote content for security
    $options->set('isHtml5ParserEnabled', false);  // Disable HTML5 parser to avoid missing dependency
    $options->set('isPhpEnabled', false);  // Disable PHP for security
    $options->set('isCssFloatEnabled', false);  // Disable CSS float
    $options->set('isJavascriptEnabled', false);  // Disable JavaScript
    $options->set('debugKeepTemp', false);  // Don't keep temp files
    $options->set('debugCss', false);  // Disable CSS debugging
    $options->set('debugLayout', false);  // Disable layout debugging

    // Create Dompdf instance
    $dompdf = new \Dompdf\Dompdf($options);
    
    try {
        // Use loadHTML with XML-compatible HTML (no HTML5 features)
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
    } catch (Exception $e) {
        error_log("DomPDF Render Error: " . $e->getMessage());
        
        // Try fallback: simplified HTML without complex CSS
        $simple_html = '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; font-size: 12pt; }
                .header { text-align: center; margin-bottom: 20px; }
                .section { margin-bottom: 15px; }
                .label { font-weight: bold; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>City Health Office - Koronadal City</h1>
                <h2>Medical Referral</h2>
                <p>Referral No: ' . htmlspecialchars($referral['referral_num']) . '</p>
            </div>
            <div class="section">
                <div class="label">Patient Name:</div>
                <div>' . htmlspecialchars($patient_name) . '</div>
            </div>
            <div class="section">
                <div class="label">Referred To:</div>
                <div>' . htmlspecialchars($destination) . '</div>
            </div>
            <div class="section">
                <div class="label">Reason:</div>
                <div>' . nl2br(htmlspecialchars($referral['referral_reason'] ?: 'No specific reason provided.')) . '</div>
            </div>
            <div class="section">
                <div class="label">Date:</div>
                <div>' . date('M d, Y g:i A', strtotime($referral['referral_date'])) . '</div>
            </div>
        </body>
        </html>';
        
        $dompdf->loadHtml($simple_html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
    }

    // Generate filename
    $filename = 'Referral_' . $referral['referral_num'] . '_' . date('Y-m-d') . '.pdf';

    // Check if this should be displayed inline (for preview) or as attachment (for download)
    $display_mode = $_GET['display'] ?? 'inline'; // Default to inline for preview
    
    // Set headers
    header('Content-Type: application/pdf');
    
    if ($display_mode === 'download') {
        // Force download
        header('Content-Disposition: attachment; filename="' . $filename . '"');
    } else {
        // Display inline (for preview in popup)
        header('Content-Disposition: inline; filename="' . $filename . '"');
    }
    
    header('Content-Length: ' . strlen($dompdf->output()));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    // Output PDF
    echo $dompdf->output();

} catch (Exception $e) {
    error_log("Referral PDF Generation Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error generating PDF: ' . $e->getMessage()]);
}
?>