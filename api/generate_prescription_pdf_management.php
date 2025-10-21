<?php
// Management-side PDF generation endpoint
$root_path = dirname(__DIR__);
require_once $root_path . '/vendor/autoload.php';
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Check if user is logged in as employee
if (!isset($_SESSION['employee_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$prescription_id = isset($_GET['prescription_id']) ? intval($_GET['prescription_id']) : 0;

if (!$prescription_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid prescription ID']);
    exit();
}

try {
    // Get prescription details - no patient restriction for management users
    $prescriptionQuery = "
        SELECT p.*, 
               pt.first_name, pt.last_name, pt.middle_name, pt.username as patient_id_display, 
               pt.date_of_birth, pt.sex, pt.contact_number,
               b.barangay_name as barangay,
               e.first_name as doctor_first_name, e.last_name as doctor_last_name,
               c.consultation_id, c.consultation_date, c.chief_complaint, c.diagnosis, c.treatment_plan
        FROM prescriptions p
        LEFT JOIN patients pt ON p.patient_id = pt.patient_id  
        LEFT JOIN barangay b ON pt.barangay_id = b.barangay_id
        LEFT JOIN employees e ON p.prescribed_by_employee_id = e.employee_id
        LEFT JOIN consultations c ON p.consultation_id = c.consultation_id
        WHERE p.prescription_id = ?";
    
    $stmt = $conn->prepare($prescriptionQuery);
    if (!$stmt) {
        throw new Exception('Failed to prepare prescription query: ' . $conn->error);
    }
    
    $stmt->bind_param("i", $prescription_id);
    $stmt->execute();
    $prescription = $stmt->get_result()->fetch_assoc();

    if (!$prescription) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Prescription not found']);
        exit();
    }

    // Get prescribed medications
    $medicationsQuery = "
        SELECT pm.*
        FROM prescribed_medications pm
        WHERE pm.prescription_id = ?
        ORDER BY pm.created_at";
    
    $medStmt = $conn->prepare($medicationsQuery);
    if (!$medStmt) {
        throw new Exception('Failed to prepare medications query: ' . $conn->error);
    }
    $medStmt->bind_param("i", $prescription_id);
    $medStmt->execute();
    $medications = $medStmt->get_result();

    // Get pharmacist details from logs (optional)
    $pharmacistQuery = "
        SELECT e.first_name, e.last_name, e.employee_id
        FROM prescription_logs pl
        LEFT JOIN employees e ON pl.changed_by_employee_id = e.employee_id
        WHERE pl.prescription_id = ? AND pl.action_type = 'medication_updated'
        ORDER BY pl.created_at DESC
        LIMIT 1";
    
    $pharmStmt = $conn->prepare($pharmacistQuery);
    $pharmacist = null;
    if ($pharmStmt) {
        try {
            $pharmStmt->bind_param("i", $prescription_id);
            $pharmStmt->execute();
            $pharmacistResult = $pharmStmt->get_result();
            $pharmacist = $pharmacistResult->fetch_assoc();
        } catch (Exception $e) {
            // Continue without pharmacist info
        }
    }

    $patientName = trim($prescription['first_name'] . ' ' . $prescription['middle_name'] . ' ' . $prescription['last_name']);
    $doctorName = trim($prescription['doctor_first_name'] . ' ' . $prescription['doctor_last_name']);
    $pharmacistName = $pharmacist ? trim($pharmacist['first_name'] . ' ' . $pharmacist['last_name']) : 'System';

    // Generate HTML for PDF
    $html = generatePrescriptionHTML($prescription, $medications, $patientName, $doctorName, $pharmacistName);

    // Create PDF
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Output PDF
    $filename = 'Prescription_RX-' . sprintf('%06d', $prescription['prescription_id']) . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $patientName) . '.pdf';
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $dompdf->output();
    exit();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error generating PDF: ' . $e->getMessage()]);
    exit();
}

function generatePrescriptionHTML($prescription, $medications, $patientName, $doctorName, $pharmacistName) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Prescription - RX-<?= sprintf('%06d', $prescription['prescription_id']) ?></title>
        <style>
            @page { size: A4; margin: 1in; }
            body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; line-height: 1.4; margin: 0; padding: 0; color: #000; background: white; }
            .prescription-container { max-width: 100%; margin: 0 auto; background: white; border: 2px solid #000; padding: 20px; }
            .header-section { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #000; padding-bottom: 15px; }
            .clinic-name { font-size: 24px; font-weight: bold; color: #003366; margin-bottom: 5px; }
            .clinic-subtitle { font-size: 16px; color: #666; margin-bottom: 10px; }
            .prescription-info { display: flex; justify-content: space-between; margin-bottom: 20px; font-weight: bold; }
            .patient-section, .doctor-section { margin-bottom: 25px; }
            .section-title { font-weight: bold; font-size: 14px; color: #003366; margin-bottom: 10px; border-bottom: 1px solid #ccc; padding-bottom: 5px; }
            .patient-info, .doctor-info { margin-left: 20px; }
            .info-row { margin-bottom: 5px; }
            .medications-section { margin-bottom: 30px; }
            .medications-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            .medications-table th, .medications-table td { border: 1px solid #000; padding: 8px; text-align: left; font-size: 11px; }
            .medications-table th { background-color: #f5f5f5; font-weight: bold; }
            .clinical-info-print { margin-bottom: 25px; border: 1px solid #000; padding: 10px; }
            .signatures-section { margin-top: 40px; display: flex; justify-content: space-between; }
            .signature-block { text-align: center; width: 45%; }
            .signature-line { border-top: 1px solid #000; margin-top: 40px; padding-top: 5px; font-size: 10px; }
            .rx-number { font-size: 18px; font-weight: bold; color: #d32f2f; }
        </style>
    </head>
    <body>
        <div class="prescription-container">
            <div class="header-section">
                <div class="clinic-name">CITY HEALTH OFFICE OF KORONADAL</div>
                <div class="clinic-subtitle">Medical Prescription</div>
            </div>
            <div class="prescription-info">
                <div><span class="rx-number">RX-<?= sprintf('%06d', $prescription['prescription_id']) ?></span></div>
                <div>Date: <?= date('F d, Y', strtotime($prescription['prescription_date'])) ?></div>
            </div>
            <div class="patient-section">
                <div class="section-title">PATIENT INFORMATION</div>
                <div class="patient-info">
                    <div class="info-row"><strong>Name:</strong> <?= htmlspecialchars($patientName) ?></div>
                    <div class="info-row"><strong>Patient ID:</strong> <?= htmlspecialchars($prescription['patient_id_display']) ?></div>
                    <div class="info-row"><strong>Date of Birth:</strong> <?= date('M d, Y', strtotime($prescription['date_of_birth'])) ?> (Age: <?= floor((time() - strtotime($prescription['date_of_birth'])) / (365.25 * 24 * 3600)) ?> years)</div>
                    <div class="info-row"><strong>Sex:</strong> <?= htmlspecialchars($prescription['sex']) ?></div>
                    <?php if (!empty($prescription['barangay'])): ?>
                    <div class="info-row"><strong>Address:</strong> <?= htmlspecialchars($prescription['barangay']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($prescription['contact_number'])): ?>
                    <div class="info-row"><strong>Contact:</strong> <?= htmlspecialchars($prescription['contact_number']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($prescription['chief_complaint']) || !empty($prescription['treatment_plan'])): ?>
            <div class="clinical-info-print">
                <?php if (!empty($prescription['chief_complaint'])): ?>
                <p><strong>Chief Complaint:</strong> <?= htmlspecialchars($prescription['chief_complaint']) ?></p>
                <?php endif; ?>
                <?php if (!empty($prescription['treatment_plan'])): ?>
                <p><strong>Clinical Notes:</strong> <?= htmlspecialchars($prescription['treatment_plan']) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="medications-section">
                <div class="section-title">PRESCRIBED MEDICATIONS</div>
                <?php if ($medications && $medications->num_rows > 0): ?>
                <table class="medications-table">
                    <thead>
                        <tr>
                            <th>Medication Name</th>
                            <th>Dosage</th>
                            <th>Frequency</th>
                            <th>Duration</th>
                            <th>Instructions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($med = $medications->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($med['medication_name'] ?? 'Custom Medication') ?></strong></td>
                            <td><?= htmlspecialchars($med['dosage'] ?? 'As prescribed') ?></td>
                            <td><?= htmlspecialchars($med['frequency'] ?? 'As needed') ?></td>
                            <td><?= htmlspecialchars($med['duration'] ?? 'Complete course') ?></td>
                            <td><?= htmlspecialchars($med['instructions'] ?? 'Take as directed') ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p><em>No medications prescribed.</em></p>
                <?php endif; ?>
            </div>
            <div class="doctor-section">
                <div class="section-title">PRESCRIBING PHYSICIAN</div>
                <div class="doctor-info">
                    <div class="info-row"><strong>Dr. <?= htmlspecialchars($doctorName) ?></strong></div>
                    <div class="info-row">Licensed Physician</div>
                    <div class="info-row">City Health Office of Koronadal</div>
                </div>
            </div>
            <div class="signatures-section">
                <div class="signature-block">
                    <div class="signature-line">
                        <strong><?= htmlspecialchars($doctorName) ?></strong><br>
                        <em>Attending Physician</em><br>
                        License No: _________________
                    </div>
                </div>
                <div class="signature-block">
                    <div class="signature-line">
                        <strong><?= htmlspecialchars($pharmacistName) ?></strong><br>
                        <em>Licensed Pharmacist</em><br>
                        Date Dispensed: _______________
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}
?>