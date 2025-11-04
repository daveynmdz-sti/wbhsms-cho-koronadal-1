<?php
/**
 * Generate Consultation PDF
 * Creates a downloadable PDF of consultation details
 */

// Include dependencies
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';
require_once $root_path . '/vendor/autoload.php';

// Use Dompdf
use Dompdf\Dompdf;
use Dompdf\Options;

// Check if user is logged in
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if role is authorized for clinical encounters
$authorized_roles = ['doctor', 'nurse', 'admin', 'records_officer', 'bhw', 'dho', 'pharmacist'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

$employee_id = $_SESSION['employee_id'];
$employee_role = strtolower($_SESSION['role']);

// Get consultation ID from POST data
$consultation_id = isset($_POST['consultation_id']) ? (int)$_POST['consultation_id'] : 0;
if (!$consultation_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid consultation ID']);
    exit();
}

try {
    // Get consultation with patient and vitals information (same query as get_consultation_details.php)
    $consultation_stmt = $conn->prepare("
        SELECT c.*, 
               p.first_name, p.last_name, p.username as patient_code, p.date_of_birth, p.sex, p.contact_number,
               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age,
               COALESCE(b.barangay_name, 'Not Specified') as barangay,
               CONCAT(doc.first_name, ' ', doc.last_name) as doctor_name,
               s.name as service_name,
               v.vitals_id, v.recorded_at as vitals_recorded_at, v.systolic_bp, v.diastolic_bp, 
               v.heart_rate, v.temperature, v.respiratory_rate, v.weight, v.height, v.bmi, v.remarks as vitals_remarks,
               CONCAT(v.systolic_bp, '/', v.diastolic_bp) as blood_pressure,
               CONCAT(emp_vitals.first_name, ' ', emp_vitals.last_name) as vitals_taken_by
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN employees doc ON (c.consulted_by = doc.employee_id OR c.attending_employee_id = doc.employee_id)
        LEFT JOIN services s ON c.service_id = s.service_id
        LEFT JOIN vitals v ON c.vitals_id = v.vitals_id
        LEFT JOIN employees emp_vitals ON v.recorded_by = emp_vitals.employee_id
        WHERE c.consultation_id = ?
    ");
    
    if (!$consultation_stmt) {
        throw new Exception("Failed to prepare consultation query: " . $conn->error);
    }
    
    $consultation_stmt->bind_param("i", $consultation_id);
    
    if (!$consultation_stmt->execute()) {
        throw new Exception("Failed to execute consultation query: " . $consultation_stmt->error);
    }
    
    $result = $consultation_stmt->get_result();
    $consultation_data = $result->fetch_assoc();
    
    if (!$consultation_data) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Consultation not found']);
        exit();
    }
    
    // Role-based access control - same as get_consultation_details.php
    $has_access = false;
    
    switch ($employee_role) {
        case 'doctor':
        case 'nurse':
            // Doctor/Nurse: Show consultations assigned to them or where they were involved
            if ($consultation_data['consulted_by'] == $employee_id || 
                $consultation_data['attending_employee_id'] == $employee_id) {
                $has_access = true;
            }
            break;
            
        case 'bhw':
            // BHW: Limited to patients from their assigned barangay (would need employee-barangay assignment table)
            $has_access = true; // Simplified for now
            break;
            
        case 'dho':
            // DHO: Limited to patients from their assigned district (would need employee-district assignment table)
            $has_access = true; // Simplified for now
            break;
            
        case 'admin':
        case 'records_officer':
            // Admin/Records Officer: Full access
            $has_access = true;
            break;
            
        default:
            $has_access = false;
            break;
    }
    
    if (!$has_access) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied for this consultation']);
        exit();
    }
    
    // Generate HTML content for PDF
    $patient_name = htmlspecialchars($consultation_data['first_name'] . ' ' . $consultation_data['last_name']);
    $consultation_date = date('F j, Y g:i A', strtotime($consultation_data['consultation_date']));
    $current_date = date('F j, Y g:i A');
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Consultation Details - ' . $patient_name . '</title>
        <style>
            @page { margin: 2cm; }
            body { 
                font-family: Arial, sans-serif; 
                margin: 0; 
                padding: 0; 
                color: #333; 
                font-size: 12px;
                line-height: 1.4;
            }
            .header { 
                text-align: center; 
                margin-bottom: 30px; 
                border-bottom: 2px solid #0077b6; 
                padding-bottom: 20px; 
            }
            .header h1 { 
                color: #0077b6; 
                margin: 0; 
                font-size: 24px; 
                font-weight: bold;
            }
            .header h2 { 
                color: #666; 
                margin: 10px 0; 
                font-size: 18px; 
            }
            .header p { 
                margin: 5px 0; 
                color: #666; 
                font-size: 11px; 
            }
            
            .section { 
                margin-bottom: 20px; 
                page-break-inside: avoid; 
            }
            .section-title { 
                background: #f8f9fa; 
                padding: 8px 12px; 
                border-left: 4px solid #0077b6; 
                margin-bottom: 12px; 
                font-weight: bold; 
                font-size: 14px; 
                color: #0077b6; 
            }
            
            .info-grid { 
                width: 100%; 
                margin-bottom: 15px; 
            }
            .info-row { 
                margin-bottom: 8px;
                overflow: hidden;
            }
            .info-label { 
                font-weight: bold; 
                float: left;
                width: 30%; 
                margin-right: 10px;
            }
            .info-value { 
                float: left;
                width: 65%;
                border-bottom: 1px solid #eee; 
                padding-bottom: 2px;
            }
            
            .clinical-note { 
                background: #f8f9fa; 
                padding: 12px; 
                border-radius: 3px; 
                margin: 8px 0; 
                page-break-inside: avoid;
            }
            .clinical-note h4 { 
                margin: 0 0 8px 0; 
                color: #0077b6; 
                font-size: 12px; 
                font-weight: bold;
            }
            .clinical-note p { 
                margin: 0; 
                line-height: 1.4; 
                white-space: pre-line; 
            }
            
            .status-badge { 
                display: inline-block; 
                padding: 3px 8px; 
                border-radius: 10px; 
                font-size: 10px; 
                font-weight: bold; 
                text-transform: uppercase; 
            }
            .status-completed { 
                background: #d4edda; 
                color: #155724; 
            }
            .status-pending, .status-ongoing { 
                background: #fff3cd; 
                color: #856404; 
            }
            .status-awaiting-lab-results { 
                background: #d1ecf1; 
                color: #0c5460; 
            }
            
            .footer { 
                margin-top: 30px; 
                text-align: center; 
                font-size: 10px; 
                color: #666; 
                border-top: 1px solid #eee; 
                padding-top: 15px; 
            }
            
            .clearfix::after {
                content: "";
                display: table;
                clear: both;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>CHO Koronadal</h1>
            <h2>Consultation Details</h2>
            <p>Consultation ID: ' . $consultation_id . '</p>
            <p>Generated on: ' . $current_date . '</p>
        </div>

        <div class="section">
            <div class="section-title">Patient Information</div>
            <div class="info-grid">
                <div class="info-row clearfix">
                    <div class="info-label">Patient Name:</div>
                    <div class="info-value">' . $patient_name . '</div>
                </div>
                <div class="info-row clearfix">
                    <div class="info-label">Patient ID:</div>
                    <div class="info-value">' . htmlspecialchars($consultation_data['patient_code']) . '</div>
                </div>
                <div class="info-row clearfix">
                    <div class="info-label">Age / Sex:</div>
                    <div class="info-value">' . $consultation_data['age'] . ' years / ' . htmlspecialchars($consultation_data['sex']) . '</div>
                </div>
                <div class="info-row clearfix">
                    <div class="info-label">Contact Number:</div>
                    <div class="info-value">' . htmlspecialchars($consultation_data['contact_number'] ?: 'Not provided') . '</div>
                </div>
                <div class="info-row clearfix">
                    <div class="info-label">Barangay:</div>
                    <div class="info-value">' . htmlspecialchars($consultation_data['barangay']) . '</div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Consultation Information</div>
            <div class="info-grid">
                <div class="info-row clearfix">
                    <div class="info-label">Consultation Date:</div>
                    <div class="info-value">' . $consultation_date . '</div>
                </div>
                <div class="info-row clearfix">
                    <div class="info-label">Doctor:</div>
                    <div class="info-value">' . htmlspecialchars($consultation_data['doctor_name'] ?: 'Not assigned') . '</div>
                </div>
                <div class="info-row clearfix">
                    <div class="info-label">Service Type:</div>
                    <div class="info-value">' . htmlspecialchars($consultation_data['service_name'] ?: 'Not specified') . '</div>
                </div>
                <div class="info-row clearfix">
                    <div class="info-label">Status:</div>
                    <div class="info-value">
                        <span class="status-badge status-' . htmlspecialchars($consultation_data['consultation_status']) . '">
                            ' . htmlspecialchars(ucwords(str_replace('_', ' ', $consultation_data['consultation_status']))) . '
                        </span>
                    </div>
                </div>
            </div>
        </div>';

    // Add vital signs if available
    if ($consultation_data['vitals_id']) {
        $html .= '
        <div class="section">
            <div class="section-title">Vital Signs</div>
            <div class="info-grid">
                <div class="info-row clearfix">
                    <div class="info-label">Blood Pressure:</div>
                    <div class="info-value">' . htmlspecialchars($consultation_data['blood_pressure'] ?: 'Not recorded') . '</div>
                </div>
                <div class="info-row clearfix">
                    <div class="info-label">Heart Rate:</div>
                    <div class="info-value">' . ($consultation_data['heart_rate'] ? htmlspecialchars($consultation_data['heart_rate']) . ' bpm' : 'Not recorded') . '</div>
                </div>
                <div class="info-row clearfix">
                    <div class="info-label">Temperature:</div>
                    <div class="info-value">' . ($consultation_data['temperature'] ? htmlspecialchars($consultation_data['temperature']) . '°C' : 'Not recorded') . '</div>
                </div>
                <div class="info-row clearfix">
                    <div class="info-label">Respiratory Rate:</div>
                    <div class="info-value">' . ($consultation_data['respiratory_rate'] ? htmlspecialchars($consultation_data['respiratory_rate']) . ' rpm' : 'Not recorded') . '</div>
                </div>
                <div class="info-row clearfix">
                    <div class="info-label">Weight:</div>
                    <div class="info-value">' . ($consultation_data['weight'] ? htmlspecialchars($consultation_data['weight']) . ' kg' : 'Not recorded') . '</div>
                </div>
                <div class="info-row clearfix">
                    <div class="info-label">Height:</div>
                    <div class="info-value">' . ($consultation_data['height'] ? htmlspecialchars($consultation_data['height']) . ' cm' : 'Not recorded') . '</div>
                </div>
            </div>
        </div>';
    }

    // Add clinical information
    $html .= '
        <div class="section">
            <div class="section-title">Clinical Information</div>';
            
    if ($consultation_data['chief_complaint']) {
        $html .= '
            <div class="clinical-note">
                <h4>Chief Complaint</h4>
                <p>' . htmlspecialchars($consultation_data['chief_complaint']) . '</p>
            </div>';
    }
    
    if ($consultation_data['diagnosis']) {
        $html .= '
            <div class="clinical-note">
                <h4>Diagnosis</h4>
                <p>' . htmlspecialchars($consultation_data['diagnosis']) . '</p>
            </div>';
    }
    
    if ($consultation_data['treatment_plan']) {
        $html .= '
            <div class="clinical-note">
                <h4>Treatment Plan</h4>
                <p>' . htmlspecialchars($consultation_data['treatment_plan']) . '</p>
            </div>';
    }
    
    if ($consultation_data['remarks']) {
        $html .= '
            <div class="clinical-note">
                <h4>Additional Remarks</h4>
                <p>' . htmlspecialchars($consultation_data['remarks']) . '</p>
            </div>';
    }
    
    if ($consultation_data['follow_up_date']) {
        $html .= '
            <div class="info-grid">
                <div class="info-row clearfix">
                    <div class="info-label">Follow-up Date:</div>
                    <div class="info-value">' . date('F j, Y', strtotime($consultation_data['follow_up_date'])) . '</div>
                </div>
            </div>';
    }
    
    $html .= '
        </div>

        <div class="footer">
            <p>This is an electronically generated document from CHO Koronadal Healthcare Management System</p>
            <p>Generated by: ' . htmlspecialchars(($_SESSION['employee_first_name'] ?? '') . ' ' . ($_SESSION['employee_last_name'] ?? '')) . ' (' . htmlspecialchars($_SESSION['role']) . ')</p>
        </div>
    </body>
    </html>';

    // Configure dompdf options
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    $options->set('defaultFont', 'Arial');

    // Initialize dompdf
    $dompdf = new Dompdf($options);
    
    // Load HTML content
    $dompdf->loadHtml($html);
    
    // Set paper size and orientation
    $dompdf->setPaper('A4', 'portrait');
    
    // Render PDF
    $dompdf->render();
    
    // Set filename for download
    $filename = 'consultation_' . $consultation_id . '_' . date('Y-m-d') . '.pdf';
    
    // Output PDF for download
    $dompdf->stream($filename, [
        'Attachment' => true,
        'compress' => true
    ]);
    
} catch (Exception $e) {
    error_log("Error in generate_consultation_pdf.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error generating PDF: ' . $e->getMessage()
    ]);
}
?>