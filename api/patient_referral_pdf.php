<?php
/**
 * Patient Referral PDF Generator
 * Generates PDF for patients with proper security
 */

// Include dependencies
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';
require_once $root_path . '/vendor/autoload.php';

// Use Dompdf
use Dompdf\Dompdf;
use Dompdf\Options;

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$patient_id = $_SESSION['patient_id'];

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
    // Get referral with complete patient information - ONLY for logged-in patient
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
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database prepare failed: ' . $conn->error]);
        exit();
    }

    $referral_stmt->bind_param("ii", $referral_id, $patient_id);
    $referral_stmt->execute();
    $referral_result = $referral_stmt->get_result();

    if ($referral_result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Referral not found or access denied']);
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

    // Generate HTML content for PDF - Same optimized A4 layout as admin version
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <style>
            @page {
                margin: 15mm;
                size: A4 portrait;
            }
            
            body {
                font-family: "Times New Roman", serif;
                font-size: 10pt;
                line-height: 1.3;
                color: #000;
                margin: 0;
                padding: 0;
            }
            
            .header {
                text-align: center;
                margin-bottom: 15px;
                border-bottom: 2px solid #0077b6;
                padding-bottom: 10px;
            }
            
            .header h1 {
                color: #0077b6;
                font-size: 18pt;
                font-weight: bold;
                margin: 0 0 3px 0;
            }
            
            .header h2 {
                color: #023e8a;
                font-size: 14pt;
                font-weight: normal;
                margin: 0 0 5px 0;
            }
            
            .header .subtitle {
                font-size: 8pt;
                color: #666;
                margin-top: 5px;
            }
            
            .document-title {
                text-align: center;
                font-size: 14pt;
                font-weight: bold;
                color: #0077b6;
                margin: 15px 0 10px 0;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .referral-number {
                text-align: center;
                font-size: 11pt;
                font-weight: bold;
                margin-bottom: 15px;
                background: #f8f9fa;
                padding: 8px;
                border: 1px solid #dee2e6;
            }
            
            .info-section {
                margin-bottom: 12px;
                border: 1px solid #dee2e6;
                border-radius: 3px;
                overflow: hidden;
                page-break-inside: avoid;
            }
            
            .section-header {
                background: #0077b6;
                color: white;
                padding: 6px 10px;
                font-weight: bold;
                font-size: 11pt;
            }
            
            .section-content {
                padding: 10px;
                background: #fff;
            }
            
            .info-grid {
                display: table;
                width: 100%;
                margin-bottom: 8px;
            }
            
            .info-row {
                display: table-row;
            }
            
            .info-label {
                display: table-cell;
                font-weight: bold;
                padding: 2px 10px 2px 0;
                width: 28%;
                vertical-align: top;
                font-size: 9pt;
            }
            
            .info-value {
                display: table-cell;
                padding: 2px 0;
                vertical-align: top;
                font-size: 9pt;
            }
            
            .reason-box {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 3px;
                padding: 8px;
                margin: 8px 0;
                font-style: italic;
                min-height: 30px;
                font-size: 9pt;
            }
            
            .vitals-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 5px;
                font-size: 8pt;
            }
            
            .vitals-table th,
            .vitals-table td {
                border: 1px solid #dee2e6;
                padding: 4px 6px;
                text-align: left;
            }
            
            .vitals-table th {
                background: #f8f9fa;
                font-weight: bold;
                font-size: 8pt;
            }
            
            .status-badge {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 10px;
                font-weight: bold;
                font-size: 8pt;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
            
            .status-active { background: #d4edda; color: #155724; }
            .status-pending { background: #fff3cd; color: #856404; }
            .status-completed { background: #d1ecf1; color: #0c5460; }
            .status-cancelled { background: #f8d7da; color: #721c24; }
            
            .footer {
                margin-top: 20px;
                padding-top: 10px;
                border-top: 1px solid #dee2e6;
                font-size: 8pt;
                color: #666;
                page-break-inside: avoid;
            }
            
            .signatures {
                margin-top: 20px;
                display: table;
                width: 100%;
                page-break-inside: avoid;
            }
            
            .signature-block {
                display: table-cell;
                width: 50%;
                text-align: center;
                padding: 10px;
                vertical-align: top;
            }
            
            .signature-line {
                border-top: 1px solid #000;
                margin-top: 30px;
                padding-top: 3px;
                font-weight: bold;
                font-size: 9pt;
            }
            
            .signature-title {
                font-size: 8pt;
                color: #666;
                margin-top: 3px;
            }
            
            .page-break-avoid {
                page-break-inside: avoid;
            }
        </style>
    </head>
    <body>
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
            Referral No: ' . htmlspecialchars($referral['referral_num']) . '
        </div>
        
        <div class="info-section">
            <div class="section-header">Patient Information</div>
            <div class="section-content">
                <div class="info-grid">
                    <div class="info-row">
                        <div class="info-label">Patient Name:</div>
                        <div class="info-value">' . htmlspecialchars($patient_name) . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Patient ID:</div>
                        <div class="info-value">' . htmlspecialchars($referral['patient_number']) . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Date of Birth:</div>
                        <div class="info-value">' . ($referral['date_of_birth'] ? date('F d, Y', strtotime($referral['date_of_birth'])) : 'Not specified') . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Age:</div>
                        <div class="info-value">' . ($referral['age'] ? $referral['age'] . ' years old' : 'Not specified') . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Sex:</div>
                        <div class="info-value">' . htmlspecialchars($referral['sex'] ?: 'Not specified') . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Address:</div>
                        <div class="info-value">' . htmlspecialchars($referral['barangay'] ?: 'Not specified') . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Contact Number:</div>
                        <div class="info-value">' . htmlspecialchars($referral['contact_number'] ?: 'Not specified') . '</div>
                    </div>
                </div>
            </div>
        </div>';

    // Add vitals section if available
    if ($vitals && !empty($vitals['blood_pressure'])) {
        $html .= '
        <div class="info-section page-break-avoid">
            <div class="section-header">Latest Vital Signs</div>
            <div class="section-content">
                <table class="vitals-table">
                    <thead>
                        <tr>
                            <th>BP</th>
                            <th>HR</th>
                            <th>RR</th>
                            <th>Temp</th>
                            <th>Wt</th>
                            <th>Ht</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>' . htmlspecialchars($vitals['blood_pressure'] ?: 'N/A') . '</td>
                            <td>' . htmlspecialchars($vitals['heart_rate'] ? $vitals['heart_rate'] . ' bpm' : 'N/A') . '</td>
                            <td>' . htmlspecialchars($vitals['respiratory_rate'] ? $vitals['respiratory_rate'] . '/min' : 'N/A') . '</td>
                            <td>' . htmlspecialchars($vitals['temperature'] ? $vitals['temperature'] . '°C' : 'N/A') . '</td>
                            <td>' . htmlspecialchars($vitals['weight'] ? $vitals['weight'] . ' kg' : 'N/A') . '</td>
                            <td>' . htmlspecialchars($vitals['height'] ? $vitals['height'] . ' cm' : 'N/A') . '</td>
                        </tr>
                    </tbody>
                </table>
                <p style="font-size: 8pt; color: #666; margin: 5px 0 0 0;">
                    Recorded: ' . ($vitals['recorded_at'] ? date('M d, Y g:i A', strtotime($vitals['recorded_at'])) : 'Not recorded') . '
                </p>
            </div>
        </div>';
    }

    $html .= '
        <div class="info-section page-break-avoid">
            <div class="section-header">Referral Details</div>
            <div class="section-content">
                <div class="info-grid">
                    <div class="info-row">
                        <div class="info-label">Referred To:</div>
                        <div class="info-value">' . htmlspecialchars($destination) . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Service:</div>
                        <div class="info-value">' . htmlspecialchars($referral['service_name'] ?: 'General Consultation') . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Referral Date:</div>
                        <div class="info-value">' . date('M d, Y g:i A', strtotime($referral['referral_date'])) . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Status:</div>
                        <div class="info-value">
                            <span class="status-badge status-' . strtolower($referral['status']) . '">' . ucfirst($referral['status']) . '</span>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Referred By:</div>
                        <div class="info-value">' . htmlspecialchars($issuer_name) . ' (' . htmlspecialchars($referral['issuer_position'] ?: 'Healthcare Provider') . ')</div>
                    </div>
                </div>
                
                <div style="margin-top: 12px;">
                    <strong style="font-size: 9pt;">Reason for Referral:</strong>
                    <div class="reason-box">
                        ' . nl2br(htmlspecialchars($referral['referral_reason'] ?: 'No specific reason provided.')) . '
                    </div>
                </div>
            </div>
        </div>
        
        <div class="signatures page-break-avoid">
            <div class="signature-block">
                <div class="signature-line">' . htmlspecialchars($issuer_name) . '</div>
                <div class="signature-title">Referring Healthcare Provider</div>
                <div class="signature-title">Date: ' . date('M d, Y', strtotime($referral['referral_date'])) . '</div>
            </div>
            <div class="signature-block">
                <div class="signature-line">_________________________</div>
                <div class="signature-title">Receiving Healthcare Provider</div>
                <div class="signature-title">Date: _______________</div>
            </div>
        </div>
        
        <div class="footer page-break-avoid">
            <p style="margin: 0 0 3px 0;"><strong>Note:</strong> This is an official medical referral document. Please present this to the receiving healthcare facility.</p>
            <p style="margin: 0;">Generated: ' . date('M d, Y g:i A') . ' | CHO Koronadal WBHSMS</p>
        </div>
    </body>
    </html>';

    // Configure Dompdf
    $options = new Options();
    $options->set('defaultFont', 'Times New Roman');
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);

    // Create Dompdf instance
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

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
    error_log("Patient Referral PDF Generation Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error generating PDF: ' . $e->getMessage()]);
}
?>