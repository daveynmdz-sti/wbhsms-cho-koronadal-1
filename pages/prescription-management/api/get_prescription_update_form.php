<?php
// Include employee session configuration
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    http_response_code(401);
    echo '<div class="alert alert-error">Unauthorized access</div>';
    exit();
}

// Check permissions - Only Admin (1) and Pharmacist (4) can update medication statuses
$canUpdateMedications = isset($_SESSION['role_id']) && in_array($_SESSION['role_id'], [1, 4]); // admin, pharmacist

$prescription_id = isset($_GET['prescription_id']) ? intval($_GET['prescription_id']) : 0;

if (!$prescription_id) {
    echo '<div class="alert alert-error">Invalid prescription ID</div>';
    exit();
}

try {
    // Get prescription details with patient and consultation info
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
        echo '<div class="alert alert-error">Prescription not found</div>';
        exit();
    }

    // Get prescribed medications
    $medicationsQuery = "
        SELECT prescribed_medication_id, prescription_id, medication_name, dosage, frequency, duration, instructions, status
        FROM prescribed_medications 
        WHERE prescription_id = ?
        ORDER BY created_at";

    $medStmt = $conn->prepare($medicationsQuery);
    if (!$medStmt) {
        throw new Exception('Failed to prepare medications query: ' . $conn->error);
    }
    $medStmt->bind_param("i", $prescription_id);
    $medStmt->execute();
    $medications = $medStmt->get_result();

    // Debug: Log what medications are found for this prescription
    $medCount = $medications->num_rows;
    error_log("Found $medCount medications for prescription $prescription_id");

    // Store medications in array for reuse
    $medicationsArray = [];
    while ($med = $medications->fetch_assoc()) {
        $medicationsArray[] = $med;
        error_log("Medication found: ID {$med['prescribed_medication_id']} - {$med['medication_name']} - Status: {$med['status']}");
    }

    $patientName = trim($prescription['first_name'] . ' ' . $prescription['middle_name'] . ' ' . $prescription['last_name']);
    $doctorName = trim($prescription['doctor_first_name'] . ' ' . $prescription['doctor_last_name']);
} catch (Exception $e) {
    echo '<div class="alert alert-error">Error loading prescription data: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit();
}
?>

<style>
    .dispensed-form {
        padding: 0;
        max-height: none;
        overflow-y: visible;
    }

    .section-card {
        background: white;
        border-radius: 12px;
        margin-bottom: 20px;
        overflow: hidden;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .section-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
    }

    .patient-summary {
        background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
        border-left: 4px solid #2196f3;
        box-shadow: 0 3px 10px rgba(33, 150, 243, 0.1);
    }

    .consultation-info {
        background: linear-gradient(135deg, #e8f5e8 0%, #f0f8ff 100%);
        border-left: 4px solid #17a2b8;
        box-shadow: 0 3px 10px rgba(23, 162, 184, 0.1);
    }

    .prescription-summary {
        background: linear-gradient(135deg, #e8f5e8 0%, #f0fff0 100%);
        border-left: 4px solid #28a745;
        box-shadow: 0 3px 10px rgba(40, 167, 69, 0.1);
    }

    .section-header {
        background: rgba(255, 255, 255, 0.9);
        padding: 15px 20px;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        margin: 15px -15px 15px -15px;
    }

    .section-header h4 {
        margin: 0;
        color: #2c3e50;
        font-size: 18px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .section-header h4 i {
        font-size: 20px;
        color: #3498db;
        padding: 8px;
        background: rgba(52, 152, 219, 0.1);
        border-radius: 50%;
    }

    .medications-section {
        background: linear-gradient(135deg, #e8f5e8 0%, #f0fff0 100%);
        border-radius: 12px;
        padding: 20px;
        margin: 20px;
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
        border-left: 4px solid #28a745;

    }

    .medications-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
    }

    .medications-table th {
        background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
        color: white;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-size: 12px;
        padding: 15px 12px;
        border: none;
    }

    .medications-table td {
        padding: 15px 12px;
        text-align: left;
        border: none;
        border-bottom: 1px solid #ecf0f1;
        font-size: 14px;
        background: white;
        transition: background-color 0.2s ease;
    }

    .medications-table tr:hover td {
        background: #f8f9fa;
    }

    .medications-table tr:last-child td {
        border-bottom: none;
    }

    .status-dispensed {
        color: #28a745;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .status-dispensed::before {
        content: "✓";
        font-weight: bold;
        color: #28a745;
    }

    .status-unavailable {
        color: #dc3545;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .status-unavailable::before {
        content: "✗";
        font-weight: bold;
        color: #dc3545;
    }

    .status-pending {
        color: #ffc107;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .status-pending::before {
        content: "⏳";
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
        padding: 18px;
    }

    .info-item {
        background: rgba(255, 255, 255, 0.7);
        padding: 12px;
        border-radius: 8px;
        border: 1px solid rgba(0, 0, 0, 0.05);
        transition: all 0.2s ease;
    }

    .info-item:hover {
        background: rgba(255, 255, 255, 0.9);
        transform: translateY(-1px);
    }

    .info-label {
        font-weight: 600;
        color: #495057;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 5px;
    }

    .info-value {
        color: #212529;
        font-size: 14px;
        font-weight: 500;
    }

    .dispensed-badge {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
    }

    .completion-notice {
        margin: 20px;
        padding: 20px;
        background: linear-gradient(135deg, #d4edda 0%, #e8f5e8 100%);
        border-radius: 12px;
        border-left: 4px solid #28a745;
        box-shadow: 0 3px 10px rgba(40, 167, 69, 0.1);
    }

    .completion-notice p {
        margin: 0;
        color: #155724;
        font-size: 14px;
        line-height: 1.5;
    }

    .completion-notice i {
        color: #28a745;
        margin-right: 8px;
    }

    .medication-name-cell {
        font-weight: 600;
        color: #2c3e50;
    }

    .medication-generic {
        font-size: 12px;
        color: #6c757d;
        font-weight: normal;
        font-style: italic;
        margin-top: 3px;
    }

    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #6c757d;
    }

    .empty-state i {
        font-size: 48px;
        color: #dee2e6;
        margin-bottom: 15px;
    }

    .empty-state h3 {
        color: #495057;
        margin-bottom: 10px;
    }

    /* Toggle Switch Styling */
    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 24px;
    }

    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
        border-radius: 24px;
    }

    .slider:before {
        position: absolute;
        content: "";
        height: 18px;
        width: 18px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }

    input:checked+.slider {
        background-color: #28a745;
    }

    input:checked+.slider:before {
        transform: translateX(26px);
    }

    input:disabled+.slider {
        background-color: #e9ecef;
        cursor: not-allowed;
    }

    input:disabled+.slider:before {
        background-color: #6c757d;
    }

    input:disabled:checked+.slider {
        background-color: #e9ecef;
        opacity: 0.6;
    }

    input:disabled:checked+.slider:before {
        background-color: #6c757d;
    }

    .toggle-switch.disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .toggle-switch.disabled .slider {
        cursor: not-allowed;
    }

    .toggle-switch.disabled .slider.disabled {
        background-color: #e9ecef !important;
    }

    .toggle-switch.disabled input:checked+.slider.disabled {
        background-color: #d6d8db !important;
    }

    .update-actions {
        margin-top: 20px;
        text-align: center;
        padding-top: 20px;
        border-top: 1px solid #ddd;
    }

    /* Mobile Responsiveness */
    @media (max-width: 768px) {
        .info-grid {
            grid-template-columns: 1fr;
        }

        .medications-table {
            font-size: 12px;
        }

        .medications-table th,
        .medications-table td {
            padding: 10px 8px;
        }

        .section-header h4 {
            font-size: 16px;
        }

        .section-header h4 i {
            font-size: 18px;
            padding: 6px;
        }
    }

    .toggle-switch.disabled .slider.disabled {
        background-color: #e9ecef !important;
    }

    .toggle-switch.disabled input:checked+.slider.disabled {
        background-color: #d6d8db !important;
    }

    .status-dispensed {
        color: #28a745;
        font-weight: bold;
    }

    .status-unavailable {
        color: #dc3545;
        font-weight: bold;
    }

    .status-pending {
        color: #ffc107;
        font-weight: bold;
    }

    .update-actions {
        margin-top: 20px;
        text-align: center;
        padding-top: 20px;
        border-top: 1px solid #ddd;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
    }

    .info-item {
        display: flex;
        flex-direction: column;
    }

    .info-label {
        font-weight: bold;
        color: #495057;
        font-size: 0.85em;
        margin-bottom: 3px;
    }

    .info-value {
        color: #212529;
        font-size: 0.9em;
    }
</style>

<div class="dispensed-form">
    <!-- Patient Summary -->
    <div class="patient-summary section-card">
        <div class="section-header">
            <h4><i class="fas fa-user"></i> Patient Information</h4>
        </div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Full Name</div>
                <div class="info-value"><?= htmlspecialchars($patientName) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Patient ID</div>
                <div class="info-value"><?= htmlspecialchars($prescription['patient_id_display']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Date of Birth</div>
                <div class="info-value"><?= $prescription['date_of_birth'] ? date('M d, Y', strtotime($prescription['date_of_birth'])) : 'Not specified' ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Sex</div>
                <div class="info-value"><?= htmlspecialchars($prescription['sex'] ?? 'Not specified') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Address</div>
                <div class="info-value"><?= htmlspecialchars($prescription['address'] ?? 'Not specified') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Barangay</div>
                <div class="info-value"><?= htmlspecialchars($prescription['barangay'] ?? 'Not specified') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Contact Number</div>
                <div class="info-value"><?= htmlspecialchars($prescription['contact_number'] ?? 'Not specified') ?></div>
            </div>
        </div>
    </div>

    <!-- Doctor & Consultation Details -->
    <div class="consultation-info section-card">
        <div class="section-header">
            <h4><i class="fas fa-user-md"></i> Consultation Details</h4>
        </div>
        <div class="info-grid">
            <?php if ($prescription['consultation_id']): ?>
                <div class="info-item">
                    <div class="info-label">Consultation Date</div>
                    <div class="info-value"><?= date('M d, Y', strtotime($prescription['consultation_date'])) ?></div>
                </div>
            <?php endif; ?>
            <div class="info-item">
                <div class="info-label">Prescribing Doctor</div>
                <div class="info-value"><?= htmlspecialchars($doctorName) ?></div>
            </div>
            <?php if (!empty($prescription['chief_complaint'])): ?>
                <div class="info-item">
                    <div class="info-label">Chief Complaint</div>
                    <div class="info-value"><?= htmlspecialchars($prescription['chief_complaint']) ?></div>
                </div>
            <?php endif; ?>
            <?php if (!empty($prescription['diagnosis'])): ?>
                <div class="info-item">
                    <div class="info-label">Diagnosis</div>
                    <div class="info-value"><?= htmlspecialchars($prescription['diagnosis']) ?></div>
                </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($prescription['treatment_plan'])): ?>
            <div class="info-item" style="margin-top: 10px;">
                <div class="info-label">Treatment Plan</div>
                <div class="info-value"><?= htmlspecialchars($prescription['treatment_plan']) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Prescription Summary -->
    <div class="prescription-summary section-card">
        <div class="section-header">
            <h4><i class="fas fa-prescription"></i> Prescription Summary</h4>
        </div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Prescription ID</div>
                <div class="info-value">RX-<?= sprintf('%06d', $prescription['prescription_id']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Date Prescribed</div>
                <div class="info-value"><?= date('M d, Y', strtotime($prescription['prescription_date'])) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Status</div>
                <div class="info-value">
                    <span class="dispensed-badge">
                        <i class="fas fa-edit"></i> <?= ucfirst(str_replace('_', ' ', $prescription['status'])) ?>
                    </span>
                </div>
            </div>
        </div>
        <?php if (!empty($prescription['instructions'])): ?>
            <div class="info-item" style="margin-top: 15px; grid-column: 1 / -1;">
                <div class="info-label">Special Instructions</div>
                <div class="info-value"><?= htmlspecialchars($prescription['instructions']) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Prescribed Medications -->
    <div class="medications-section">
        <div class="section-header">
            <h4><i class="fas fa-pills"></i> Prescribed Medications</h4>
        </div>

        <?php if ($canUpdateMedications): ?>
            <div class="completion-notice" style="background: linear-gradient(135deg, #fff3cd 0%, #fef9e7 100%); border-left: 4px solid #ffc107;">
                <p style="color: #856404;">
                    <i class="fas fa-info-circle"></i>
                    <strong>Important:</strong>
                    Once a medication status is set to "Dispensed" or "Unavailable", it cannot be changed again for audit integrity.
                    Only medications with "Pending" status can be updated.
                </p>
            </div>
        <?php endif; ?>

        <form id="updateMedicationsForm" onsubmit="updateMedicationStatuses(event)">
            <input type="hidden" name="prescription_id" value="<?= $prescription_id ?>">

            <?php if (!empty($medicationsArray)): ?>
                <table class="medications-table">
                    <thead>
                        <tr>
                            <th>Medication</th>
                            <th>Dosage</th>
                            <th>Frequency</th>
                            <th>Duration</th>
                            <th>Instructions</th>
                            <th>Status</th>
                            <?php if ($canUpdateMedications): ?>
                                <th>Dispensed</th>
                                <th>Unavailable</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($medicationsArray as $med): ?>
                            <tr>
                                <td class="medication-name-cell">
                                    <?= htmlspecialchars($med['medication_name'] ?? 'Custom Medication') ?>
                                    <?php if (!empty($med['generic_name'])): ?>
                                        <div class="medication-generic">Generic: <?= htmlspecialchars($med['generic_name']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($med['dosage'] ?? 'As prescribed') ?></td>
                                <td><?= htmlspecialchars($med['frequency'] ?? 'As needed') ?></td>
                                <td><?= htmlspecialchars($med['duration'] ?? 'Complete course') ?></td>
                                <td><?= htmlspecialchars($med['instructions'] ?? 'Take as directed') ?></td>
                                <td>
                                    <?php
                                    $status = $med['status'] ?? 'pending';
                                    if ($status === 'dispensed'): ?>
                                        <span class="status-dispensed" id="status_<?= $med['prescribed_medication_id'] ?>">Dispensed</span>
                                    <?php elseif ($status === 'unavailable'): ?>
                                        <span class="status-unavailable" id="status_<?= $med['prescribed_medication_id'] ?>">Unavailable</span>
                                    <?php else: ?>
                                        <span class="status-pending" id="status_<?= $med['prescribed_medication_id'] ?>">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <?php if ($canUpdateMedications): ?>
                                    <?php
                                    // Check if medication is already processed (dispensed or unavailable)
                                    $isProcessed = in_array($status, ['dispensed', 'unavailable']);
                                    $disabledAttr = $isProcessed ? 'disabled title="This medication has already been processed and cannot be changed"' : '';
                                    $lockIcon = $isProcessed ? '<i class="fas fa-lock" style="color: #6c757d; margin-left: 5px;" title="Locked - already processed"></i>' : '';
                                    ?>
                                    <td>
                                        <label class="toggle-switch <?= $isProcessed ? 'disabled' : '' ?>">
                                            <input type="checkbox"
                                                id="dispensed_<?= $med['prescribed_medication_id'] ?>"
                                                name="dispensed[]"
                                                value="<?= $med['prescribed_medication_id'] ?>"
                                                <?= $status === 'dispensed' ? 'checked' : '' ?>
                                                <?= $disabledAttr ?>
                                                onchange="updateMedicationStatus(<?= $med['prescribed_medication_id'] ?>, 'dispensed', this.checked)">
                                            <span class="slider <?= $isProcessed ? 'disabled' : '' ?>"></span>
                                        </label>
                                        <?= $lockIcon ?>
                                    </td>
                                    <td>
                                        <label class="toggle-switch <?= $isProcessed ? 'disabled' : '' ?>">
                                            <input type="checkbox"
                                                id="unavailable_<?= $med['prescribed_medication_id'] ?>"
                                                name="unavailable[]"
                                                value="<?= $med['prescribed_medication_id'] ?>"
                                                <?= $status === 'unavailable' ? 'checked' : '' ?>
                                                <?= $disabledAttr ?>
                                                onchange="updateMedicationStatus(<?= $med['prescribed_medication_id'] ?>, 'unavailable', this.checked)">
                                            <span class="slider <?= $isProcessed ? 'disabled' : '' ?>"></span>
                                        </label>
                                        <?= $lockIcon ?>
                                    </td>
                                <?php else: ?>
                                    <?php
                                    // For non-pharmacists, still show if medication is processed
                                    $isProcessed = in_array($status, ['dispensed', 'unavailable']);
                                    ?>
                                    <td colspan="2">
                                        <?php if ($isProcessed): ?>
                                            <small class="text-muted">
                                                <i class="fas fa-lock"></i> Already processed - no further changes allowed
                                            </small>
                                        <?php else: ?>
                                            <small class="text-muted">Only pharmacists can update medication status</small>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($canUpdateMedications): ?>
                    <div class="completion-notice">
                        <p style="text-align: center; margin: 0;">
                            <button type="submit" class="btn btn-primary" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; margin: 0 10px; cursor: pointer;">
                                <i class="fas fa-save"></i> Update Medication Statuses
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="closeModal('viewUpdatePrescriptionModal')" style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%); color: white; padding: 12px 24px; border: none; border-radius: 8px; font-weight: 600; margin: 0 10px; cursor: pointer;">
                                <i class="fas fa-times"></i> Close
                            </button>
                        </p>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-pills"></i>
                    <h3>No Medications Prescribed</h3>
                    <p>No medications have been prescribed for this consultation.</p>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<script>
    window.updateMedicationStatus = function(medicationId, statusType, isChecked) {
        // Prevent both dispensed and unavailable from being checked at the same time
        if (isChecked) {
            const otherType = statusType === 'dispensed' ? 'unavailable' : 'dispensed';
            const otherCheckbox = document.getElementById(otherType + '_' + medicationId);
            if (otherCheckbox.checked) {
                otherCheckbox.checked = false;
            }
        }

        // Update the status display
        const statusElement = document.getElementById('status_' + medicationId);
        if (isChecked) {
            statusElement.textContent = statusType === 'dispensed' ? 'Dispensed' : 'Unavailable';
            statusElement.className = 'status-' + statusType;
        } else {
            // Check if the other status is checked
            const otherType = statusType === 'dispensed' ? 'unavailable' : 'dispensed';
            const otherCheckbox = document.getElementById(otherType + '_' + medicationId);
            if (otherCheckbox.checked) {
                statusElement.textContent = otherType === 'dispensed' ? 'Dispensed' : 'Unavailable';
                statusElement.className = 'status-' + otherType;
            } else {
                statusElement.textContent = 'Pending';
                statusElement.className = 'status-pending';
            }
        }
    };

    window.updateMedicationStatuses = function(event) {
        event.preventDefault();

        const formData = new FormData(event.target);
        const prescriptionId = formData.get('prescription_id');

        // Collect all medication statuses
        const medicationStatuses = [];
        const checkboxes = document.querySelectorAll('input[type="checkbox"]');

        checkboxes.forEach(checkbox => {
            const parts = checkbox.id.split('_');
            const statusType = parts[0];
            const prescribedMedicationId = parts[1];

            if (checkbox.checked) {
                medicationStatuses.push({
                    prescribed_medication_id: prescribedMedicationId,
                    status: statusType
                });
            }
        });

        // Send update request
        fetch('../api/update_prescription_medications.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    prescription_id: prescriptionId,
                    medication_statuses: medicationStatuses
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('Medication statuses updated successfully!', 'success');

                    // If all medications are dispensed, the prescription status should be updated
                    if (data.prescription_status_updated) {
                        showAlert('Prescription status updated to ' + data.new_status + '!', 'info');

                        // Refresh the main page after a delay
                        setTimeout(() => {
                            closeModal('viewUpdatePrescriptionModal');
                            window.location.reload();
                        }, 2000);
                    }
                } else {
                    showAlert('Error updating medication statuses: ' + data.message, 'error');
                }
            })
            .catch(error => {
                showAlert('Error updating medication statuses: ' + error.message, 'error');
            });
    };
</script>