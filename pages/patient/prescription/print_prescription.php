<?php
// Include patient session configuration FIRST
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';

// Check if user is logged in
if (!is_patient_logged_in()) {
    redirect_to_patient_login();
}

// Database connection
require_once $root_path . '/config/db.php';

$patient_id = $_SESSION['patient_id'];
$prescription_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$prescription_id) {
    echo "Invalid prescription ID";
    exit();
}

try {
    // Get prescription details with patient and consultation info - using same query as management side
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
        WHERE p.prescription_id = ? AND p.patient_id = ?";
    
    $stmt = $conn->prepare($prescriptionQuery);
    if (!$stmt) {
        throw new Exception('Failed to prepare prescription query: ' . $conn->error);
    }
    $stmt->bind_param("ii", $prescription_id, $patient_id);
    $stmt->execute();
    $prescription = $stmt->get_result()->fetch_assoc();

    if (!$prescription) {
        echo '<div class="alert alert-error">Prescription not found</div>';
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

    // Get pharmacist details from logs (optional - may not exist)
    $pharmacistQuery = "
        SELECT e.first_name, e.last_name, e.employee_id
        FROM prescription_logs pl
        LEFT JOIN employees e ON pl.changed_by_employee_id = e.employee_id
        WHERE pl.prescription_id = ? AND pl.action_type = 'medication_updated'
        ORDER BY pl.created_at DESC
        LIMIT 1";
    
    $pharmStmt = $conn->prepare($pharmacistQuery);
    if (!$pharmStmt) {
        // If prescription_logs table doesn't exist or has issues, continue without pharmacist info
        $pharmacist = null;
    } else {
        try {
            $pharmStmt->bind_param("i", $prescription_id);
            $pharmStmt->execute();
            $pharmacistResult = $pharmStmt->get_result();
            $pharmacist = $pharmacistResult->fetch_assoc();
        } catch (Exception $e) {
            // If there's an error with the logs query, continue without pharmacist info
            $pharmacist = null;
        }
    }

    $patientName = trim($prescription['first_name'] . ' ' . $prescription['middle_name'] . ' ' . $prescription['last_name']);
    $doctorName = trim($prescription['doctor_first_name'] . ' ' . $prescription['doctor_last_name']);
    $pharmacistName = $pharmacist ? trim($pharmacist['first_name'] . ' ' . $pharmacist['last_name']) : 'System';

} catch (Exception $e) {
    echo '<div class="alert alert-error">Error loading prescription: ' . $e->getMessage() . '</div>';
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription - RX-<?= sprintf('%06d', $prescription['prescription_id']) ?></title>
    <style>
        @page {
            size: A4;
            margin: 1in;
        }
        
        @media print {
            body { 
                margin: 0; 
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            .prescription-container {
                border: none;
                box-shadow: none;
                margin: 0;
                padding: 0;
            }
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            margin: 0;
            padding: 20px;
            color: #000;
            background: white;
        }
        
        .prescription-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border: 2px solid #000;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .header-section {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
        }
        
        .print-logo {
            max-height: 80px;
            margin-bottom: 10px;
        }
        
        .clinic-name {
            font-size: 24px;
            font-weight: bold;
            color: #003366;
            margin-bottom: 5px;
        }
        
        .clinic-subtitle {
            font-size: 16px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .prescription-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            font-weight: bold;
        }
        
        .patient-section, .doctor-section {
            margin-bottom: 25px;
        }
        
        .section-title {
            font-weight: bold;
            font-size: 14px;
            color: #003366;
            margin-bottom: 10px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 5px;
        }
        
        .patient-info, .doctor-info {
            margin-left: 20px;
        }
        
        .info-row {
            margin-bottom: 5px;
        }
        
        .medications-section {
            margin-bottom: 30px;
        }
        
        .medications-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .medications-table th,
        .medications-table td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
            font-size: 11px;
        }
        
        .medications-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        
        .clinical-info-print {
            margin-bottom: 25px;
            border: 1px solid #000;
            padding: 10px;
        }
        
        .signatures-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        
        .signature-block {
            text-align: center;
            width: 45%;
        }
        
        .signature-line {
            border-top: 1px solid #000;
            margin-top: 40px;
            padding-top: 5px;
            font-size: 10px;
        }
        
        .rx-number {
            font-size: 18px;
            font-weight: bold;
            color: #d32f2f;
        }
        
        .no-print {
            margin: 20px 0;
            text-align: center;
        }
        
        .download-actions {
            margin: 20px 0;
            text-align: center;
        }
        
        .btn {
            background: #007cba;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            margin: 0 5px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn:hover {
            background: #005a87;
            transform: translateY(-1px);
        }
        
        .btn[style*="background: #28a745"]:hover {
            background: #218838 !important;
        }
        
        .btn[style*="background: #6c757d"]:hover {
            background: #545b62 !important;
        }
        
        .btn[style*="background: #17a2b8"]:hover {
            background: #138496 !important;
        }
        
        /* PDF mode optimizations */
        .pdf-mode {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
    </style>
</head>
<body>
    <div class="prescription-container">
        <!-- Header -->
        <div class="header-section">
            <img src="../../../assets/images/Nav_LogoClosed.png" alt="City Health Office Logo" class="print-logo" onerror="this.style.display='none'">
            <div class="clinic-name">CITY HEALTH OFFICE OF KORONADAL</div>
            <div class="clinic-subtitle">Medical Prescription</div>
        </div>

        <!-- Prescription Info -->
        <div class="prescription-info">
            <div>
                <span class="rx-number">RX-<?= sprintf('%06d', $prescription['prescription_id']) ?></span>
            </div>
            <div>
                Date: <?= date('F d, Y', strtotime($prescription['prescription_date'])) ?>
            </div>
        </div>

        <!-- Patient Information -->
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

        <!-- Clinical Information -->
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

        <!-- Prescribed Medications -->
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

        <!-- Prescribing Doctor Information -->
        <div class="doctor-section">
            <div class="section-title">PRESCRIBING PHYSICIAN</div>
            <div class="doctor-info">
                <div class="info-row"><strong>Dr. <?= htmlspecialchars($doctorName) ?></strong></div>
                <div class="info-row">Licensed Physician</div>
                <div class="info-row">City Health Office of Koronadal</div>
            </div>
        </div>

        <!-- Signatures -->
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

    <!-- Print/Download Actions -->
    <div class="download-actions no-print">
        <div style="background: #e7f3ff; border: 1px solid #b3d9ff; padding: 15px; margin-bottom: 20px; border-radius: 5px;">
            <h4 style="margin: 0 0 10px 0; color: #0066cc;">
                <i class="fas fa-info-circle"></i> How to Save this Prescription
            </h4>
            <p style="margin: 0; font-size: 14px;">
                <strong>Option 1:</strong> Click "Download PDF" for instant PDF download (easiest)
                <br><strong>Option 2:</strong> Click "Print" then select "Save as PDF" as destination
                <br><strong>Option 3:</strong> Use keyboard shortcut Ctrl+P
                <br>• Choose your preferred settings in the print dialog
                <br>• Click "Save" to download the PDF file
            </p>
        </div>
        
        <button class="btn" onclick="window.print()" style="font-size: 16px; padding: 12px 24px;">
            <i class="fas fa-print"></i> Print
        </button>
        <button class="btn" onclick="downloadPrescription()" style="font-size: 16px; padding: 12px 24px; background: #17a2b8;">
            <i class="fas fa-file-pdf"></i> Download PDF
        </button>
        <button class="btn" onclick="window.close()" style="background: #6c757d;">
            <i class="fas fa-times"></i> Close
        </button>
    </div>

    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <script>
        // Direct download function - downloads PDF file
        function downloadPrescription() {
            try {
                const prescriptionId = <?= json_encode($prescription['prescription_id']) ?>;
                
                // Show loading message
                showDownloadStatus('Generating PDF...', 'info');
                
                // Create a temporary link to download PDF
                const downloadUrl = '../../../api/generate_prescription_pdf.php?prescription_id=' + prescriptionId;
                const a = document.createElement('a');
                a.href = downloadUrl;
                a.style.display = 'none';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                
                // Show success message after a short delay
                setTimeout(function() {
                    showDownloadStatus('PDF downloaded successfully!', 'success');
                }, 1000);
                
            } catch (error) {
                console.error('Download error:', error);
                showDownloadStatus('Download failed. Please try the Print button instead.', 'error');
            }
        }
        
        // Show download status notification
        function showDownloadStatus(message, type) {
            const notification = document.createElement('div');
            const colors = {
                'info': '#007cba',
                'success': '#28a745',
                'error': '#dc3545'
            };
            
            notification.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 10000; background: ' + colors[type] + '; color: white; padding: 15px 20px; border-radius: 5px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); font-weight: bold; max-width: 300px;';
            notification.innerHTML = '<i class="fas fa-' + (type === 'info' ? 'spinner fa-spin' : type === 'success' ? 'check-circle' : 'exclamation-circle') + '"></i> ' + message;
            document.body.appendChild(notification);
            
            // Remove after 4 seconds (or 2 seconds for info)
            const timeout = type === 'info' ? 2000 : 4000;
            setTimeout(function() {
                notification.style.opacity = '0';
                notification.style.transition = 'opacity 0.3s';
                setTimeout(function() { 
                    if (notification.parentNode) {
                        document.body.removeChild(notification); 
                    }
                }, 300);
            }, timeout);
        }

        // Auto-print on load if requested
        if (new URLSearchParams(window.location.search).get('print') === 'true') {
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            };
        }
    </script>
</body>
</html>