<?php
// Start output buffering to prevent header issues
ob_start();

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header("Location: ../management/auth/employee_login.php");
    exit();
}

// Define role-based permissions for prescription creation
$canCreatePrescriptions = in_array($_SESSION['role_id'], [1, 2, 4]); // admin, doctor, pharmacist

if (!$canCreatePrescriptions) {
    $role_id = $_SESSION['role_id'];
    // Map role_id to role name for redirect
    $roleMap = [
        1 => 'admin',
        2 => 'doctor', 
        3 => 'nurse',
        4 => 'pharmacist',
        5 => 'dho',
        6 => 'bhw',
        7 => 'records_officer',
        8 => 'cashier',
        9 => 'laboratory_tech'
    ];
    $role = $roleMap[$role_id] ?? 'employee';
    header("Location: ../management/$role/dashboard.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $patient_id = (int)($_POST['patient_id'] ?? 0);
        $medications = $_POST['medications'] ?? [];
        $remarks = trim($_POST['remarks'] ?? '');
        
        if (!$patient_id) {
            throw new Exception('Please select a patient.');
        }
        
        if (empty($medications)) {
            throw new Exception('Please add at least one medication.');
        }
        
        // Validate patient exists
        $patient_stmt = $conn->prepare("SELECT patient_id, first_name, last_name FROM patients WHERE patient_id = ?");
        $patient_stmt->bind_param("i", $patient_id);
        $patient_stmt->execute();
        $patient_result = $patient_stmt->get_result();
        
        if ($patient_result->num_rows === 0) {
            throw new Exception('Patient not found.');
        }
        
        $patient = $patient_result->fetch_assoc();
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Create prescription record without appointment_id (standalone prescription)
            // We'll exclude appointment_id from the INSERT to avoid foreign key constraint issues
            $prescription_stmt = $conn->prepare("
                INSERT INTO prescriptions (
                    patient_id, 
                    prescribed_by_employee_id, 
                    prescription_date, 
                    status, 
                    overall_status,
                    remarks,
                    created_at
                ) VALUES (?, ?, NOW(), 'issued', 'issued', ?, NOW())
            ");
            
            $prescription_stmt->bind_param("iis", $patient_id, $_SESSION['employee_id'], $remarks);
            
            if (!$prescription_stmt->execute()) {
                // If we still get foreign key constraint error, the schema needs modification
                $error_msg = $conn->error;
                if (strpos($error_msg, 'fk_prescriptions_appointment') !== false) {
                    throw new Exception('Database schema error: The prescriptions table requires database modification to support standalone prescriptions. Please contact system administrator to make appointment_id nullable or remove the foreign key constraint.');
                } else {
                    throw new Exception('Failed to create prescription: ' . $error_msg);
                }
            }
            
            $prescription_id = $conn->insert_id;
            
            // Add medications
            $medication_stmt = $conn->prepare("
                INSERT INTO prescribed_medications (
                    prescription_id,
                    medication_name,
                    dosage,
                    frequency,
                    duration,
                    instructions,
                    status,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            
            foreach ($medications as $medication) {
                if (empty(trim($medication['medication_name']))) continue;
                
                // Store trimmed values in variables for bind_param reference
                $med_name = trim($medication['medication_name']);
                $med_dosage = trim($medication['dosage'] ?? '');
                $med_frequency = trim($medication['frequency'] ?? '');
                $med_duration = trim($medication['duration'] ?? '');
                $med_instructions = trim($medication['instructions'] ?? '');
                
                $medication_stmt->bind_param("isssss",
                    $prescription_id,
                    $med_name,
                    $med_dosage,
                    $med_frequency,
                    $med_duration,
                    $med_instructions
                );
                
                if (!$medication_stmt->execute()) {
                    throw new Exception('Failed to add medication: ' . $conn->error);
                }
            }
            
            $conn->commit();
            $_SESSION['success_message'] = "Prescription created successfully for " . $patient['first_name'] . " " . $patient['last_name'];
            
            // Clean all output buffers before redirect to prevent header issues
            while (ob_get_level()) {
                ob_end_clean();
            }
            header("Location: prescription_management.php");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = $e->getMessage();
    }
}

// Patient search functionality
$search_query = $_GET['search'] ?? '';
$first_name = $_GET['first_name'] ?? '';
$last_name = $_GET['last_name'] ?? '';

$patients = [];
if ($search_query || $first_name || $last_name) {
    $where_conditions = [];
    $params = [];
    $param_types = '';
    
    if (!empty($search_query)) {
        $where_conditions[] = "(p.username LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)";
        $search_term = "%$search_query%";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
        $param_types .= 'ssss';
    }
    
    if (!empty($first_name)) {
        $where_conditions[] = "p.first_name LIKE ?";
        $params[] = "%$first_name%";
        $param_types .= 's';
    }
    
    if (!empty($last_name)) {
        $where_conditions[] = "p.last_name LIKE ?";
        $params[] = "%$last_name%";
        $param_types .= 's';
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    $sql = "
        SELECT p.patient_id, p.username, p.first_name, p.middle_name, p.last_name, 
               p.sex, p.contact_number, p.date_of_birth,
               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
        FROM patients p 
        $where_clause
        AND p.status = 'active'
        ORDER BY p.last_name, p.first_name
        LIMIT 5
    ";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $patients = $result->fetch_all(MYSQLI_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Create Prescription | CHO Koronadal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="../../assets/css/topbar.css" />
    <link rel="stylesheet" href="../../assets/css/profile-edit-responsive.css" />
    <link rel="stylesheet" href="../../assets/css/profile-edit.css" />
    <link rel="stylesheet" href="../../assets/css/edit.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        .patient-search-container {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
            margin-bottom: 1rem;
        }

        .medication-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 2fr auto;
            gap: 1rem;
            align-items: start;
            margin-bottom: 1rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .medication-row:hover {
            border-color: #0077b6;
            box-shadow: 0 2px 8px rgba(0, 119, 182, 0.1);
        }

        .medication-row input, .medication-row textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .medication-row input:focus, .medication-row textarea:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
        }

        .medication-row textarea {
            resize: vertical;
            min-height: 80px;
        }

        .remove-medication {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border: none;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .remove-medication:hover {
            background: linear-gradient(135deg, #c82333, #a71e2a);
            transform: translateY(-2px);
        }

        .add-medication {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 10px;
            cursor: pointer;
            margin: 1rem 0;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .add-medication:hover {
            background: linear-gradient(135deg, #218838, #1e7e34);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }

        .medication-headers {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 2fr auto;
            gap: 1rem;
            margin-bottom: 1rem;
            font-weight: 700;
            color: #0077b6;
            font-size: 0.95em;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-section {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
            margin-bottom: 1rem;
        }

        .patient-table table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            min-width: 600px;
        }

        .patient-table th,
        .patient-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .patient-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }

        .patient-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .patient-checkbox {
            transform: scale(1.2);
            margin: 0;
        }

        .empty-search {
            text-align: center;
            color: #6c757d;
            margin: 2rem 0;
        }

        .selected-patient {
            background-color: #e8f4fd !important;
        }

        .prescription-form {
            opacity: 0.6;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .prescription-form.enabled {
            opacity: 1;
            pointer-events: auto;
        }

        .selected-patient-info {
            background: #e8f4fd;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid #0077b6;
        }

        .patient-table-container {
            overflow-x: auto;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #0077b6;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            box-shadow: 0 4px 15px rgba(0, 119, 182, 0.3);
        }

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #023e8a, #001d3d);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 119, 182, 0.4);
        }

        .btn-secondary {
            background: #f8f9fa;
            color: #6c757d;
            border: 2px solid #dee2e6;
        }

        .btn-secondary:hover {
            background: #e9ecef;
            color: #5a6268;
            transform: translateY(-2px);
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f1b2b7;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.7;
            margin-left: auto;
            padding: 0;
            color: inherit;
        }

        .btn-close:hover {
            opacity: 1;
        }

        .button-container {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            gap: 1rem;
        }

        .button-container .btn {
            width: auto;
            min-width: fit-content;
            flex: none;
        }

        @media (max-width: 768px) {
            .search-grid {
                grid-template-columns: 1fr;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .medication-row, .medication-headers {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
            
            .medication-headers {
                display: none;
            }

            .patient-search-container {
                padding: 1rem;
            }

            .medication-row {
                padding: 1rem;
            }
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 10px;
            max-width: 80%;
            max-height: 80%;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }

        .close-btn {
            font-size: 1.5em;
            cursor: pointer;
            color: #aaa;
            border: none;
            background: none;
        }

        .close-btn:hover {
            color: #000;
        }

        /* Medication Selection Table Enhancements */
        #medicationsTable tbody tr[onclick] {
            transition: all 0.2s ease;
            cursor: pointer;
        }

        #medicationsTable tbody tr[onclick]:hover {
            background-color: #e8f4f8 !important;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        #medicationsTable tbody tr[onclick]:active {
            transform: translateY(0);
            background-color: #d1ecf1 !important;
        }

        .prescription-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .prescription-table th,
        .prescription-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            font-size: 0.9em;
        }

        .prescription-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #0077b6;
        }

        .prescription-table tr:hover {
            background-color: #f5f5f5;
        }
    </style>
</head>
<body>
    <?php 
    // Include reusable topbar component
    require_once $root_path . '/includes/topbar.php';

    // Render topbar
    renderTopbar([
        'title' => 'Create New Prescription',
        'back_url' => 'prescription_management.php',
        'user_type' => 'employee',
        'vendor_path' => '../../vendor/'
    ]);
    ?>

    <section class="homepage">
        <?php 
        // Render back button with modal
        renderBackButton([
            'back_url' => 'prescription_management.php',
            'button_text' => '← Back / Cancel',
            'modal_title' => 'Cancel Creating Prescription?',
            'modal_message' => 'Are you sure you want to go back/cancel? Unsaved changes will be lost.',
            'confirm_text' => 'Yes, Cancel',
            'stay_text' => 'Stay'
        ]);
        ?>

        <div class="profile-wrapper">
            <!-- Reminders Box -->
            <div class="reminders-box">
                <div class="reminder-item">
                    <i class="fas fa-prescription-bottle-alt"></i>
                    <span><strong>Prescription Guidelines:</strong> Always verify patient allergies and current medications before prescribing.</span>
                </div>
                <div class="reminder-item">
                    <i class="fas fa-user-check"></i>
                    <span><strong>Patient Selection:</strong> Select the patient from the dropdown below to begin creating a prescription.</span>
                </div>
                <div class="reminder-item">
                    <i class="fas fa-pills"></i>
                    <span><strong>Medication Details:</strong> Include proper dosage, frequency, duration, and special instructions for each medication.</span>
                </div>
            </div>

            <!-- Display messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <!-- Patient Search Section -->
            <div class="form-section">
                <div class="search-container">
                    <h3><i class="fas fa-search"></i> Search Patient</h3>
                    <form method="GET" class="search-grid" id="patientSearchForm">
                        <div class="form-group">
                            <label for="search">General Search</label>
                            <input type="text" id="search" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>"
                                placeholder="Patient ID, Name...">
                        </div>
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($_GET['first_name'] ?? '') ?>"
                                placeholder="Enter first name...">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($_GET['last_name'] ?? '') ?>"
                                placeholder="Enter last name...">
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Patient Results Section -->
            <div class="form-section">
                <div class="patient-table">
                    <h3><i class="fas fa-users"></i> Patient Search Results</h3>
                    <?php if (empty($patients) && ($search_query || $first_name || $last_name)): ?>
                        <div class="empty-search">
                            <i class="fas fa-user-times fa-2x"></i>
                            <p>No patients found matching your search criteria.</p>
                            <p>Try adjusting your search terms or check the spelling.</p>
                        </div>
                    <?php elseif (!empty($patients)): ?>
                        <p>Found <?= count($patients) ?> patient(s). Select one to create a prescription:</p>
                        
                        <!-- Desktop Table View -->
                        <div class="patient-table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Select</th>
                                        <th>Patient ID</th>
                                        <th>Name</th>
                                        <th>Age/Sex</th>
                                        <th>Contact</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($patients as $patient): ?>
                                        <tr class="patient-row" data-patient-id="<?= $patient['patient_id'] ?>">
                                            <td>
                                                <input type="radio" name="selected_patient" value="<?= $patient['patient_id'] ?>" 
                                                    class="patient-checkbox" data-patient-name="<?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?>">
                                            </td>
                                            <td><?= htmlspecialchars($patient['username']) ?></td>
                                            <td>
                                                <?= htmlspecialchars($patient['first_name'] . ' ' . 
                                                    ($patient['middle_name'] ? $patient['middle_name'] . ' ' : '') . 
                                                    $patient['last_name']) ?>
                                            </td>
                                            <td><?= htmlspecialchars($patient['age']) ?> / <?= htmlspecialchars($patient['sex']) ?></td>
                                            <td><?= htmlspecialchars($patient['contact_number'] ?? 'N/A') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-search">
                            <i class="fas fa-search fa-2x"></i>
                            <p>Use the search form above to find patients.</p>
                            <p>Search results will appear here. Select a patient to create a prescription.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Prescription Form -->
            <div class="form-section">
                <form class="prescription-form" id="prescriptionForm" method="post">
                    <input type="hidden" name="patient_id" id="selectedPatientId">
                    
                    <h3><i class="fas fa-prescription-bottle-alt"></i> Create Prescription</h3>
                    <div id="selectedPatientInfo" class="selected-patient-info" style="display:none;">
                        <p><strong>Selected Patient:</strong> <span id="selectedPatientName"></span></p>
                    </div>

                    <!-- Prescription Details Section -->
                    <div class="form-section">
                        <h4><i class="fas fa-pills"></i> Prescription Details</h4>
                        <p>Add medications and their detailed instructions below.</p>
                        
                        <!-- View Available Medications Button -->
                        <div style="margin-bottom: 15px;">
                            <button type="button" class="btn btn-primary" onclick="openMedicationsModal()">
                                <i class="fas fa-eye"></i> View Available Medications
                            </button>
                        </div>

                <div class="medications-container">
                    <div class="medication-headers">
                        <div>Medication Name</div>
                        <div>Dosage</div>
                        <div>Frequency</div>
                        <div>Duration</div>
                        <div>Instructions</div>
                    </div>
                    
                    <div id="medications-container">
                        <!-- Initial medication row -->
                        <div class="medication-row">
                            <div class="form-group">
                                <input type="text" name="medications[0][medication_name]" placeholder="e.g., Paracetamol" required>
                            </div>
                            <div class="form-group">
                                <input type="text" name="medications[0][dosage]" placeholder="e.g., 500mg">
                            </div>
                            <div class="form-group">
                                <input type="text" name="medications[0][frequency]" placeholder="e.g., 3x daily">
                            </div>
                            <div class="form-group">
                                <input type="text" name="medications[0][duration]" placeholder="e.g., 7 days">
                            </div>
                            <div class="form-group">
                                <textarea name="medications[0][instructions]" placeholder="Additional instructions..."></textarea>
                            </div>
                            <div class="form-group">
                                <button type="button" class="remove-medication" onclick="removeMedication(this)" style="display: none;">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" class="add-medication" onclick="addMedication()">
                        <i class="fas fa-plus"></i> Add Another Medication
                    </button>
                </div>
            </div>

            <!-- Additional Information Section -->
            <div class="form-section">
                <div class="section-header">
                    <h3><i class="fas fa-notes-medical"></i> Additional Information</h3>
                    <p>Add any special notes or instructions for this prescription.</p>
                </div>

                <div class="form-group">
                    <label for="additional_notes">Additional Notes</label>
                    <textarea name="additional_notes" id="additional_notes" rows="4" 
                            placeholder="Any special instructions, warnings, or additional information for this prescription..."></textarea>
                </div>

                <div class="form-group">
                    <div class="button-container">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-prescription-bottle-alt"></i>
                            Create Prescription
                        </button>
                    </div>
                </div>
            </div>
        </form>
        </div>
        </div>
    </section>

    <script>
        let medicationCount = 1;

        // Ensure DOM is fully loaded before executing functions
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM fully loaded');
            
            // Initialize the page
            initializePage();
            
            // Add form validation
            const prescriptionForm = document.getElementById('prescriptionForm');
            if (prescriptionForm) {
                prescriptionForm.addEventListener('submit', validateForm);
                console.log('Form validation listener added');
            } else {
                console.error('Prescription form not found!');
            }

            // Log critical elements for debugging
            console.log('=== CRITICAL ELEMENTS CHECK ===');
            console.log('medications-container:', document.getElementById('medications-container'));
            console.log('prescriptionForm:', document.getElementById('prescriptionForm'));
            console.log('selectedPatientId:', document.getElementById('selectedPatientId'));
            console.log('=== END CRITICAL ELEMENTS CHECK ===');
        });

        function initializePage() {
            try {
                // Check if required elements exist
                const container = document.getElementById('medications-container');
                if (!container) {
                    console.error('Critical Error: medications-container not found on page load');
                    return;
                }
                
                // Update remove buttons on initial load
                updateRemoveButtons();
                
                console.log('Page initialized successfully');
            } catch (error) {
                console.error('Error initializing page:', error);
            }
        }

        // Safe alert function with fallback
        function safeAlert(message, type = 'info') {
            try {
                if (typeof showAlert === 'function') {
                    showAlert(message, type);
                } else {
                    console.warn('showAlert function not available, using console.log');
                    console.log(`[${type.toUpperCase()}] ${message}`);
                    // Try native alert as last resort for critical errors
                    if (type === 'error') {
                        alert(message);
                    }
                }
            } catch (error) {
                console.error('Error showing alert:', error);
                console.log(`[${type.toUpperCase()}] ${message}`);
                // Try native alert as last resort
                try {
                    alert(message);
                } catch (alertError) {
                    console.error('Cannot show any alert:', alertError);
                }
            }
        }

        // Debug function for production troubleshooting
        window.debugPrescriptionPage = function() {
            console.log('=== PRESCRIPTION PAGE DEBUG INFO ===');
            console.log('Medication count:', medicationCount);
            
            const container = document.getElementById('medications-container');
            console.log('Medications container:', container);
            console.log('Container children count:', container ? container.children.length : 'N/A');
            
            const form = document.getElementById('prescriptionForm');
            console.log('Prescription form:', form);
            
            const medicationInputs = document.querySelectorAll('input[name*="[medication_name]"]');
            console.log('Medication name inputs found:', medicationInputs.length);
            
            const selectedPatientId = document.getElementById('selectedPatientId');
            console.log('Selected patient ID element:', selectedPatientId);
            console.log('Selected patient ID value:', selectedPatientId ? selectedPatientId.value : 'N/A');
            
            console.log('=== END DEBUG INFO ===');
            
            return {
                medicationCount,
                containerExists: !!container,
                containerChildren: container ? container.children.length : 0,
                formExists: !!form,
                medicationInputsCount: medicationInputs.length,
                selectedPatientId: selectedPatientId ? selectedPatientId.value : null
            };
        };

        function addMedication() {
            console.log('addMedication function called');
            
            try {
                const container = document.getElementById('medications-container');
                console.log('Container found:', container);
                
                if (!container) {
                    console.error('Medications container not found');
                    
                    // Use safe alert function
                    safeAlert('Error: Cannot find medications container. Please refresh the page.', 'error');
                    return;
                }
                
                console.log('Creating new medication row with count:', medicationCount);
                
                const newRow = document.createElement('div');
                newRow.className = 'medication-row';
                
                // Build the HTML string more carefully
                const medicationHTML = `
                    <div class="form-group">
                        <input type="text" name="medications[${medicationCount}][medication_name]" placeholder="e.g., Paracetamol" required>
                    </div>
                    <div class="form-group">
                        <input type="text" name="medications[${medicationCount}][dosage]" placeholder="e.g., 500mg">
                    </div>
                    <div class="form-group">
                        <input type="text" name="medications[${medicationCount}][frequency]" placeholder="e.g., 3x daily">
                    </div>
                    <div class="form-group">
                        <input type="text" name="medications[${medicationCount}][duration]" placeholder="e.g., 7 days">
                    </div>
                    <div class="form-group">
                        <textarea name="medications[${medicationCount}][instructions]" placeholder="Additional instructions..."></textarea>
                    </div>
                    <div class="form-group">
                        <button type="button" class="remove-medication" onclick="removeMedication(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                `;
                
                console.log('Setting innerHTML');
                newRow.innerHTML = medicationHTML;
                
                console.log('Appending to container');
                container.appendChild(newRow);
                
                medicationCount++;
                console.log('Medication count incremented to:', medicationCount);
                
                // Show remove buttons for all rows when we have more than one
                updateRemoveButtons();
                
                console.log('Medication added successfully');
                
                // Try to show success message
                safeAlert('Medication row added successfully!', 'success');
                
            } catch (error) {
                console.error('Error adding medication:', error);
                console.error('Error stack:', error.stack);
                
                // Use safe alert function
                safeAlert('Error adding medication: ' + error.message, 'error');
            }
        }

        function removeMedication(button) {
            try {
                const container = document.getElementById('medications-container');
                if (!container) {
                    console.error('Medications container not found');
                    showAlert('Error: Cannot find medications container', 'error');
                    return;
                }
                
                if (container.children.length > 1) {
                    const rowToRemove = button.closest('.medication-row');
                    if (rowToRemove) {
                        rowToRemove.remove();
                        updateRemoveButtons();
                        console.log('Medication removed successfully');
                    } else {
                        console.error('Could not find medication row to remove');
                    }
                } else {
                    showAlert('Cannot remove the last medication. At least one medication is required.', 'warning');
                }
            } catch (error) {
                console.error('Error removing medication:', error);
                showAlert('Error removing medication: ' + error.message, 'error');
            }
        }

        function updateRemoveButtons() {
            try {
                const container = document.getElementById('medications-container');
                if (!container) {
                    console.error('Medications container not found in updateRemoveButtons');
                    return;
                }
                
                const removeButtons = container.querySelectorAll('.remove-medication');
                
                removeButtons.forEach(button => {
                    button.style.display = container.children.length > 1 ? 'block' : 'none';
                });
                
                console.log('Remove buttons updated. Container has', container.children.length, 'children');
            } catch (error) {
                console.error('Error updating remove buttons:', error);
            }
        }

        function validateForm(e) {
            try {
                const selectedPatientId = document.getElementById('selectedPatientId').value;
                const medicationInputs = document.querySelectorAll('input[name*="[medication_name]"]');
                let hasValidMedication = false;
                
                // Check if patient is selected
                if (!selectedPatientId) {
                    e.preventDefault();
                    showAlert('Please search and select a patient first.', 'error');
                    return false;
                }
                
                // Check if at least one medication is added
                medicationInputs.forEach(input => {
                    if (input.value.trim() !== '') {
                        hasValidMedication = true;
                    }
                });
                
                if (!hasValidMedication) {
                    e.preventDefault();
                    showAlert('Please add at least one medication.', 'error');
                    return false;
                }
                
                console.log('Form validation passed');
                return true;
            } catch (error) {
                console.error('Error in form validation:', error);
                e.preventDefault();
                showAlert('Form validation error: ' + error.message, 'error');
                return false;
            }
        }

        // Initialize remove button visibility after DOM loads
        updateRemoveButtons();

        // Patient selection handling
        document.querySelectorAll('.patient-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                if (this.checked) {
                    const patientId = this.value;
                    const patientName = this.getAttribute('data-patient-name');
                    
                    // Update hidden form field
                    document.getElementById('selectedPatientId').value = patientId;
                    
                    // Show selected patient info
                    document.getElementById('selectedPatientName').textContent = patientName;
                    document.getElementById('selectedPatientInfo').style.display = 'block';
                    
                    // Highlight selected row
                    document.querySelectorAll('.patient-row').forEach(row => {
                        row.classList.remove('selected-patient');
                    });
                    this.closest('.patient-row').classList.add('selected-patient');
                    
                    // Enable the form
                    document.getElementById('prescriptionForm').classList.add('enabled');
                }
            });
        });

        // Alert system (no JavaScript alerts)
        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'error' ? 'exclamation-triangle' : 'info-circle'}"></i>
                ${message}
                <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
            `;
            
            // Insert at the top of profile-wrapper
            const profileWrapper = document.querySelector('.profile-wrapper');
            profileWrapper.insertBefore(alertDiv, profileWrapper.firstChild);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.style.opacity = '0';
                    setTimeout(() => alertDiv.remove(), 300);
                }
            }, 5000);
        }

        // Available Medications Modal Functions
        function openMedicationsModal() {
            // Set higher z-index to appear above other modals
            const medicationsModal = document.getElementById('availableMedicationsModal');
            medicationsModal.style.display = 'block';
            medicationsModal.style.zIndex = '1100'; // Higher than default modal z-index
            
            // Focus on the search input for better UX
            setTimeout(() => {
                const searchInput = document.getElementById('medicationSearch');
                if (searchInput) {
                    searchInput.focus();
                }
            }, 100);
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                // Reset z-index for medications modal
                if (modalId === 'availableMedicationsModal') {
                    modal.style.zIndex = '';
                }
            }
        }

        function filterMedications() {
            const searchTerm = document.getElementById('medicationSearch').value.toLowerCase();
            const table = document.getElementById('medicationsTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let match = false;

                for (let j = 0; j < cells.length; j++) {
                    if (cells[j].textContent.toLowerCase().includes(searchTerm)) {
                        match = true;
                        break;
                    }
                }

                rows[i].style.display = match ? '' : 'none';
            }
        }

        // Medication Selection Function
        window.selectMedication = function(drugName, dosage, formulation) {
            // Create medication selection data
            const medicationData = {
                name: drugName,
                dosage: dosage,
                formulation: formulation
            };

            // Show confirmation and copy to clipboard
            const medicationText = `${drugName} ${dosage} ${formulation}`;
            
            // Try to copy to clipboard
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(medicationText).then(() => {
                    showAlert(`Selected: ${medicationText} (copied to clipboard)`, 'success');
                }).catch(() => {
                    showAlert(`Selected: ${medicationText}`, 'success');
                });
            } else {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = medicationText;
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    showAlert(`Selected: ${medicationText} (copied to clipboard)`, 'success');
                } catch (err) {
                    showAlert(`Selected: ${medicationText}`, 'success');
                }
                document.body.removeChild(textArea);
            }

            // Try to auto-fill prescription form if it exists
            try {
                // Look for the first empty medication name field
                const medicationNameFields = document.querySelectorAll('input[name*="[medication_name]"]');
                const dosageFields = document.querySelectorAll('input[name*="[dosage]"]');
                
                let targetNameField = null;
                let targetDosageField = null;
                
                // Find the first empty medication name field
                for (let i = 0; i < medicationNameFields.length; i++) {
                    if (!medicationNameFields[i].value.trim()) {
                        targetNameField = medicationNameFields[i];
                        targetDosageField = dosageFields[i];
                        break;
                    }
                }
                
                if (targetNameField) {
                    targetNameField.value = medicationData.name;
                    targetNameField.dispatchEvent(new Event('input', { bubbles: true }));
                    targetNameField.dispatchEvent(new Event('change', { bubbles: true }));
                }
                
                if (targetDosageField) {
                    targetDosageField.value = medicationData.dosage;
                    targetDosageField.dispatchEvent(new Event('input', { bubbles: true }));
                    targetDosageField.dispatchEvent(new Event('change', { bubbles: true }));
                }

                // If fields were auto-filled, show additional message
                if (targetNameField || targetDosageField) {
                    setTimeout(() => {
                        showAlert('Medication details auto-filled in prescription form!', 'info');
                    }, 1000);
                }
            } catch (error) {
                console.log('Could not auto-fill form fields:', error);
            }

            // Close the medications modal after selection
            setTimeout(() => {
                closeModal('availableMedicationsModal');
            }, 1500);
        };

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const modals = ['availableMedicationsModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    closeModal(modalId);
                }
            });
        });
    </script>

    <!-- Available Medications Modal -->
    <div id="availableMedicationsModal" class="modal">
        <div class="modal-content" style="max-width: 90%; max-height: 90%;">
            <div class="modal-header">
                <h3><i class="fas fa-pills"></i> PhilHealth GAMOT 2025 - Available Medications</h3>
                <button class="close-btn" onclick="closeModal('availableMedicationsModal')">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <div style="background: #e7f3ff; border: 1px solid #b3d9ff; padding: 10px; margin-bottom: 15px; border-radius: 5px;">
                    <p style="margin: 0; font-size: 14px; color: #0066cc;">
                        <i class="fas fa-info-circle"></i> Click on any medication to select it and auto-fill the prescription form.
                    </p>
                </div>
                <div style="margin-bottom: 15px;">
                    <input type="text" id="medicationSearch" placeholder="Search medications..."
                           style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 5px; font-size: 14px;"
                           oninput="filterMedications()">
                </div>
                <table class="prescription-table" id="medicationsTable">
                    <thead>
                        <tr>
                            <th>Drug Name</th>
                            <th>Dosage</th>
                            <th>Formulation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- PhilHealth GAMOT 2025 Medication List -->
                        <tr onclick="selectMedication('Paracetamol', '500mg', 'Tablet')">
                            <td>Paracetamol</td>
                            <td>500mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Ibuprofen', '200mg', 'Tablet')">
                            <td>Ibuprofen</td>
                            <td>200mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Aspirin', '80mg', 'Tablet')">
                            <td>Aspirin</td>
                            <td>80mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Amoxicillin', '500mg', 'Capsule')">
                            <td>Amoxicillin</td>
                            <td>500mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('Cefalexin', '500mg', 'Capsule')">
                            <td>Cefalexin</td>
                            <td>500mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('Ciprofloxacin', '500mg', 'Tablet')">
                            <td>Ciprofloxacin</td>
                            <td>500mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Co-trimoxazole', '480mg', 'Tablet')">
                            <td>Co-trimoxazole</td>
                            <td>480mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Doxycycline', '100mg', 'Capsule')">
                            <td>Doxycycline</td>
                            <td>100mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('Metronidazole', '500mg', 'Tablet')">
                            <td>Metronidazole</td>
                            <td>500mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Amlodipine', '5mg', 'Tablet')">
                            <td>Amlodipine</td>
                            <td>5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Enalapril', '5mg', 'Tablet')">
                            <td>Enalapril</td>
                            <td>5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Losartan', '50mg', 'Tablet')">
                            <td>Losartan</td>
                            <td>50mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Propranolol', '40mg', 'Tablet')">
                            <td>Propranolol</td>
                            <td>40mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Glibenclamide', '5mg', 'Tablet')">
                            <td>Glibenclamide</td>
                            <td>5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Metformin', '500mg', 'Tablet')">
                            <td>Metformin</td>
                            <td>500mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Insulin NPH', '100IU/mL', 'Injection')">
                            <td>Insulin NPH</td>
                            <td>100IU/mL</td>
                            <td>Injection</td>
                        </tr>
                        <tr onclick="selectMedication('Salbutamol', '2mg', 'Tablet')">
                            <td>Salbutamol</td>
                            <td>2mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Salbutamol', '100mcg/dose', 'Inhaler')">
                            <td>Salbutamol</td>
                            <td>100mcg/dose</td>
                            <td>Inhaler</td>
                        </tr>
                        <tr onclick="selectMedication('Beclomethasone', '250mcg/dose', 'Inhaler')">
                            <td>Beclomethasone</td>
                            <td>250mcg/dose</td>
                            <td>Inhaler</td>
                        </tr>
                        <tr onclick="selectMedication('Omeprazole', '20mg', 'Capsule')">
                            <td>Omeprazole</td>
                            <td>20mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('Simethicone', '40mg', 'Tablet')">
                            <td>Simethicone</td>
                            <td>40mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Loperamide', '2mg', 'Capsule')">
                            <td>Loperamide</td>
                            <td>2mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('ORS', '1 sachet', 'Powder')">
                            <td>ORS</td>
                            <td>1 sachet</td>
                            <td>Powder</td>
                        </tr>
                        <tr onclick="selectMedication('Cetirizine', '10mg', 'Tablet')">
                            <td>Cetirizine</td>
                            <td>10mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Chlorphenamine', '4mg', 'Tablet')">
                            <td>Chlorphenamine</td>
                            <td>4mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Ferrous Sulfate', '325mg', 'Tablet')">
                            <td>Ferrous Sulfate</td>
                            <td>325mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Folic Acid', '5mg', 'Tablet')">
                            <td>Folic Acid</td>
                            <td>5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Calcium Carbonate', '500mg', 'Tablet')">
                            <td>Calcium Carbonate</td>
                            <td>500mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Vitamin B Complex', '1 tablet', 'Tablet')">
                            <td>Vitamin B Complex</td>
                            <td>1 tablet</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Multivitamins', '1 tablet', 'Tablet')">
                            <td>Multivitamins</td>
                            <td>1 tablet</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Diazepam', '5mg', 'Tablet')">
                            <td>Diazepam</td>
                            <td>5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Phenytoin', '100mg', 'Capsule')">
                            <td>Phenytoin</td>
                            <td>100mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('Carbamazepine', '200mg', 'Tablet')">
                            <td>Carbamazepine</td>
                            <td>200mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Betamethasone', '0.5mg', 'Tablet')">
                            <td>Betamethasone</td>
                            <td>0.5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Hydrocortisone', '10mg', 'Tablet')">
                            <td>Hydrocortisone</td>
                            <td>10mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Prednisolone', '5mg', 'Tablet')">
                            <td>Prednisolone</td>
                            <td>5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Levothyroxine', '50mcg', 'Tablet')">
                            <td>Levothyroxine</td>
                            <td>50mcg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Furosemide', '40mg', 'Tablet')">
                            <td>Furosemide</td>
                            <td>40mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Spironolactone', '25mg', 'Tablet')">
                            <td>Spironolactone</td>
                            <td>25mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Digoxin', '0.25mg', 'Tablet')">
                            <td>Digoxin</td>
                            <td>0.25mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Nifedipine', '10mg', 'Tablet')">
                            <td>Nifedipine</td>
                            <td>10mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Isosorbide Mononitrate', '20mg', 'Tablet')">
                            <td>Isosorbide Mononitrate</td>
                            <td>20mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Allopurinol', '300mg', 'Tablet')">
                            <td>Allopurinol</td>
                            <td>300mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Colchicine', '0.5mg', 'Tablet')">
                            <td>Colchicine</td>
                            <td>0.5mg</td>
                            <td>Tablet</td>
                        </tr>
                        <tr onclick="selectMedication('Tramadol', '50mg', 'Capsule')">
                            <td>Tramadol</td>
                            <td>50mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('Mefenamic Acid', '500mg', 'Capsule')">
                            <td>Mefenamic Acid</td>
                            <td>500mg</td>
                            <td>Capsule</td>
                        </tr>
                        <tr onclick="selectMedication('Sodium Chloride', '0.9%', 'Eye Drops')">
                            <td>Sodium Chloride</td>
                            <td>0.9%</td>
                            <td>Eye Drops</td>
                        </tr>
                        <tr onclick="selectMedication('Timolol', '0.5%', 'Eye Drops')">
                            <td>Timolol</td>
                            <td>0.5%</td>
                            <td>Eye Drops</td>
                        </tr>
                        <tr onclick="selectMedication('Gentamicin', '0.3%', 'Eye Drops')">
                            <td>Gentamicin</td>
                            <td>0.3%</td>
                            <td>Eye Drops</td>
                        </tr>
                        <tr onclick="selectMedication('Chloramphenicol', '0.5%', 'Eye Ointment')">
                            <td>Chloramphenicol</td>
                            <td>0.5%</td>
                            <td>Eye Ointment</td>
                        </tr>
                        <tr onclick="selectMedication('Ketoconazole', '2%', 'Cream')">
                            <td>Ketoconazole</td>
                            <td>2%</td>
                            <td>Cream</td>
                        </tr>
                        <tr onclick="selectMedication('Clotrimazole', '1%', 'Cream')">
                            <td>Clotrimazole</td>
                            <td>1%</td>
                            <td>Cream</td>
                        </tr>
                        <tr onclick="selectMedication('Hydrocortisone', '1%', 'Cream')">
                            <td>Hydrocortisone</td>
                            <td>1%</td>
                            <td>Cream</td>
                        </tr>
                        <tr onclick="selectMedication('Mupirocin', '2%', 'Ointment')">
                            <td>Mupirocin</td>
                            <td>2%</td>
                            <td>Ointment</td>
                        </tr>
                        <tr onclick="selectMedication('Silver Sulfadiazine', '1%', 'Cream')">
                            <td>Silver Sulfadiazine</td>
                            <td>1%</td>
                            <td>Cream</td>
                        </tr>
                        <tr onclick="selectMedication('Calamine', '8%', 'Lotion')">
                            <td>Calamine</td>
                            <td>8%</td>
                            <td>Lotion</td>
                        </tr>
                        <tr onclick="selectMedication('Zinc Oxide', '25%', 'Ointment')">
                            <td>Zinc Oxide</td>
                            <td>25%</td>
                            <td>Ointment</td>
                        </tr>
                        <tr onclick="selectMedication('Povidone Iodine', '10%', 'Solution')">
                            <td>Povidone Iodine</td>
                            <td>10%</td>
                            <td>Solution</td>
                        </tr>
                        <tr onclick="selectMedication('Hydrogen Peroxide', '3%', 'Solution')">
                            <td>Hydrogen Peroxide</td>
                            <td>3%</td>
                            <td>Solution</td>
                        </tr>
                        <tr onclick="selectMedication('Magnesium Hydroxide', '400mg/5mL', 'Suspension')">
                            <td>Magnesium Hydroxide</td>
                            <td>400mg/5mL</td>
                            <td>Suspension</td>
                        </tr>
                        <tr onclick="selectMedication('Aluminum Hydroxide', '320mg', 'Tablet')">
                            <td>Aluminum Hydroxide</td>
                            <td>320mg</td>
                            <td>Tablet</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>