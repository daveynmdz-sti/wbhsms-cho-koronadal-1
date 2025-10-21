<?php
// Start output buffering at the very beginning
ob_start();

// Set error reporting for debugging but don't display errors in production
error_reporting(E_ALL);
ini_set('display_errors', '0');  // Never show errors to users in production
ini_set('log_errors', '1');      // Log errors for debugging

// Custom error logging function
function logConsultationError($error_message, $context = []) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] CONSULTATION ERROR: {$error_message}";
    if (!empty($context)) {
        $log_entry .= " | Context: " . json_encode($context);
    }
    $log_entry .= " | User: " . (isset($_SESSION['patient_id']) ? $_SESSION['patient_id'] : 'Unknown') . "\n";
    
    $log_file = dirname(__DIR__, 3) . '/logs/consultation_errors.log';
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    error_log($log_entry, 3, $log_file);
}

// Include patient session configuration FIRST - before any output
$root_path = dirname(dirname(dirname(__DIR__)));

// Load configuration first
require_once $root_path . '/config/env.php';

// Then load session management
require_once $root_path . '/config/session/patient_session.php';

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If user is not logged in, redirect to login
if (!isset($_SESSION['patient_id'])) {
    ob_clean(); // Clear output buffer before redirect
    header('Location: ../auth/patient_login.php');
    exit();
}

// Database connection
require_once $root_path . '/config/db.php';

$patient_id = $_SESSION['patient_id'];
$message = '';
$error = '';

// Fetch patient information
$patient_info = null;
try {
    $stmt = $pdo->prepare("
        SELECT p.*, b.barangay_name
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.patient_id = ?
    ");
    $stmt->execute([$patient_id]);
    $patient_info = $stmt->fetch(PDO::FETCH_ASSOC);

    // Calculate priority level
    if ($patient_info) {
        $patient_info['priority_level'] = ($patient_info['isPWD'] || $patient_info['isSenior']) ? 1 : 2;
        $patient_info['priority_description'] = ($patient_info['priority_level'] == 1) ? 'Priority Patient' : 'Regular Patient';
    }

} catch (Exception $e) {
    $error = "Failed to fetch patient information.";
    logConsultationError("Patient info fetch failed: " . $e->getMessage(), [
        'patient_id' => $patient_id,
        'file' => __FILE__,
        'line' => __LINE__
    ]);
}

// Fetch consultations for this patient
$consultations = [];
try {
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
               v.visit_date,
               v.time_in as visit_time,
               v.visit_status,
               v.appointment_id,
               e.first_name as doctor_first_name, 
               e.last_name as doctor_last_name,
               e.license_number as doctor_license,
               COUNT(p.prescription_id) as prescription_count
        FROM consultations c
        LEFT JOIN visits v ON c.visit_id = v.visit_id
        LEFT JOIN employees e ON c.attending_employee_id = e.employee_id
        LEFT JOIN prescriptions p ON c.consultation_id = p.consultation_id
        WHERE c.patient_id = ?
        GROUP BY c.consultation_id
        ORDER BY c.consultation_date DESC
        LIMIT 50
    ");
    $stmt->execute([$patient_id]);
    $consultations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Unable to load consultation records at this time. Please try again later.";
    logConsultationError("Consultation fetch failed: " . $e->getMessage(), [
        'patient_id' => $patient_id,
        'sql_error' => $e->getMessage(),
        'sql_code' => $e->getCode(),
        'file' => __FILE__,
        'line' => __LINE__
    ]);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Consultations - WBHSMS Patient Portal</title>
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="../../../assets/css/sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Main Content Wrapper */
        .content-wrapper {
            margin-left: 300px;
            padding: 20px;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
                padding: 15px 10px;
            }
        }

        /* Page Header */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e9ecef;
        }

        .page-header h1 {
            margin: 0;
            font-size: 2rem;
            color: #0077b6;
            font-weight: 700;
        }

        /* Breadcrumb */
        .breadcrumb {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 1rem;
        }

        .breadcrumb a {
            color: #6c757d;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            color: white;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.7rem;
            flex-wrap: wrap;
        }

        .btn {
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background: #007BFF;
            color: #fff;
            font-size: 16px;
            padding: 12px 28px;
            text-align: center;
            display: inline-block;
        }

        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #16a085, #0f6b5c);
            color: white;
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #0f6b5c, #0a4f44);
            transform: translateY(-2px);
        }

        /* Section Container */
        .section-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f8f9fa;
            justify-content: flex-start;
        }

        .section-icon {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .section-title {
            margin: 0;
            font-size: 1.5rem;
            color: #0077b6;
            font-weight: 600;
        }

        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 0.75rem 1.25rem;
            border-radius: 25px;
            background: #f8f9fa;
            border: 2px solid transparent;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-tab.active {
            background: #007BFF;
            color: white;
            border-color: #0056b3;
        }

        .filter-tab:hover {
            background: #e9ecef;
        }

        .filter-tab.active:hover {
            background: #0056b3;
        }

        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            overflow-x: auto;
        }

        .consultations-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
            min-width: 900px;
        }

        .consultations-table th {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.95rem;
            white-space: nowrap;
        }

        /* Column width distribution */
        .consultations-table th:nth-child(1) { width: 15%; } /* Date & Time */
        .consultations-table th:nth-child(2) { width: 20%; } /* Doctor */
        .consultations-table th:nth-child(3) { width: 25%; } /* Chief Complaint */
        .consultations-table th:nth-child(4) { width: 20%; } /* Diagnosis */
        .consultations-table th:nth-child(5) { width: 12%; } /* Status */
        .consultations-table th:nth-child(6) { width: 8%; } /* Actions */

        .consultations-table td {
            padding: 1.25rem 1rem;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
            line-height: 1.5;
        }

        .consultations-table tbody tr {
            transition: all 0.2s ease;
        }

        .consultations-table tbody tr:hover {
            background-color: #f8f9fa;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        /* Cell content styling */
        .date-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .date-info strong {
            font-size: 0.9rem;
            color: #2c3e50;
            font-weight: 600;
        }

        .date-info small {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 400;
        }

        .doctor-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .doctor-info strong {
            font-size: 0.9rem;
            color: #2c3e50;
            font-weight: 600;
            line-height: 1.3;
        }

        .doctor-info small {
            font-size: 0.75rem;
            color: #6c757d;
            font-weight: 400;
        }

        .complaint-info {
            font-size: 0.9rem;
            color: #2c3e50;
            line-height: 1.4;
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .diagnosis-info {
            font-size: 0.9rem;
            color: #2c3e50;
            line-height: 1.4;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-follow-up {
            background: #cce5ff;
            color: #0066cc;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        /* Action Buttons */
        .action-buttons-cell {
            display: flex;
            gap: 0.4rem;
            justify-content: flex-start;
            align-items: center;
        }

        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            border-radius: 6px;
            font-weight: 500;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            min-width: fit-content;
            transition: all 0.2s ease;
            border: 2px solid;
        }

        .btn-outline-primary {
            border-color: #007BFF;
            color: #007BFF;
            background: transparent;
        }

        .btn-outline-primary:hover {
            background: #007BFF;
            color: white;
        }

        .btn-outline-secondary {
            border-color: #6c757d;
            color: #6c757d;
            background: transparent;
        }

        .btn-outline-secondary:hover {
            background: #6c757d;
            color: white;
        }

        .btn-sm i {
            font-size: 0.75rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #dee2e6;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: #495057;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 1000px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.3rem;
        }

        .modal-body {
            padding: 2rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 1rem 2rem;
            background: #f8f9fa;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        .close {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .close:hover {
            color: white;
            background: rgba(255, 255, 255, 0.1);
        }

        /* Alert Styles */
        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 8px;
            border: 1px solid transparent;
            position: relative;
        }

        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }

        .alert-error {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }

        .btn-close {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.7;
        }

        .btn-close:hover {
            opacity: 1;
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .consultations-table {
                min-width: 800px;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .page-header h1 {
                font-size: 1.75rem;
            }

            .filter-tabs {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .filter-tab {
                padding: 0.6rem 1rem;
                font-size: 0.85rem;
            }

            .consultations-table {
                font-size: 0.85rem;
                min-width: 700px;
            }

            .consultations-table th,
            .consultations-table td {
                padding: 0.75rem 0.5rem;
            }

            .date-info strong,
            .doctor-info strong {
                font-size: 0.85rem;
            }

            .date-info small,
            .doctor-info small {
                font-size: 0.75rem;
            }

            .complaint-info,
            .diagnosis-info {
                font-size: 0.85rem;
            }

            .status-badge {
                font-size: 0.7rem;
                padding: 0.3rem 0.6rem;
            }

            .btn-sm {
                padding: 0.4rem 0.6rem;
                font-size: 0.75rem;
                gap: 0.2rem;
            }

            .btn-sm i {
                font-size: 0.7rem;
            }

            .action-buttons-cell {
                gap: 0.3rem;
            }

            .modal-content {
                width: 95%;
                margin: 2% auto;
            }
        }

        @media (max-width: 480px) {
            .consultations-table {
                min-width: 600px;
            }

            .action-buttons-cell {
                flex-direction: column;
                gap: 0.25rem;
            }

            .btn-sm {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <?php
    // Tell the sidebar which menu item to highlight
    $activePage = 'consultations';
    include '../../../includes/sidebar_patient.php';
    ?>

    <section class="content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb" style="margin-top: 50px;">
            <a href="../dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <span style="color: #0077b6;"> / </span>
            <span style="color: #0077b6; font-weight: 600;">My Consultations</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-stethoscope" style="margin-right: 0.5rem;"></i>My Consultations</h1>
            <div class="action-buttons">
                <a href="../appointment/appointments.php" class="btn btn-secondary">
                    <i class="fas fa-calendar-plus"></i> Book Appointment
                </a>
            </div>
        </div>

        <!-- Display Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Consultations Section -->
        <div class="section-container">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-stethoscope"></i>
                </div>
                <h2 class="section-title">Clinical Encounters & Consultations</h2>
            </div>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <div class="filter-tab active" onclick="filterConsultations('all', this)">
                    <i class="fas fa-list"></i> All Consultations
                </div>
                <div class="filter-tab" onclick="filterConsultations('completed', this)">
                    <i class="fas fa-check-circle"></i> Completed
                </div>
                <div class="filter-tab" onclick="filterConsultations('follow-up', this)">
                    <i class="fas fa-calendar-check"></i> Follow-up
                </div>
            </div>

            <!-- Consultations Table -->
            <div class="table-container">
                <table class="consultations-table" id="consultations-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Doctor</th>
                            <th>Chief Complaint</th>
                            <th>Diagnosis</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="consultations-tbody">
                        <?php if (empty($consultations)): ?>
                            <tr class="empty-row">
                                <td colspan="6" class="empty-state">
                                    <i class="fas fa-stethoscope"></i>
                                    <h3>No Consultations Found</h3>
                                    <p>You don't have any consultation records yet. Consultations will appear here after you visit the CHO for medical care.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($consultations as $consultation): ?>
                                <tr class="consultation-row" 
                                    data-status="<?php echo htmlspecialchars($consultation['consultation_status']); ?>" 
                                    data-consultation-date="<?php echo htmlspecialchars($consultation['consultation_date']); ?>">
                                    <td>
                                        <div class="date-info">
                                            <strong><?php echo date('M j, Y', strtotime($consultation['visit_date'] ?: $consultation['consultation_date'])); ?></strong>
                                            <small><?php echo $consultation['visit_time'] ? date('g:i A', strtotime($consultation['visit_time'])) : date('g:i A', strtotime($consultation['consultation_date'])); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="doctor-info">
                                            <?php if (!empty($consultation['doctor_first_name'])): ?>
                                                <strong>Dr. <?php echo htmlspecialchars($consultation['doctor_first_name'] . ' ' . $consultation['doctor_last_name']); ?></strong>
                                                <?php if (!empty($consultation['doctor_license'])): ?>
                                                    <small>License: <?php echo htmlspecialchars($consultation['doctor_license']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">CHO Staff</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="complaint-info">
                                            <?php echo htmlspecialchars($consultation['chief_complaint'] ?: 'No complaint recorded'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="diagnosis-info">
                                            <?php echo htmlspecialchars($consultation['diagnosis'] ?: 'Pending diagnosis'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo htmlspecialchars(str_replace(' ', '-', strtolower($consultation['consultation_status']))); ?>">
                                            <?php echo strtoupper($consultation['consultation_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons-cell">
                                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="viewConsultationDetails(<?php echo $consultation['consultation_id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Consultation Details Modal -->
    <div id="consultationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 style="color: white;"><i class="fas fa-stethoscope"></i> Consultation Details</h2>
                <span class="close" onclick="closeConsultationModal()">&times;</span>
            </div>
            <div class="modal-body" id="consultationModalBody">
                <!-- Content will be loaded dynamically -->
                <div class="loading" style="text-align: center; padding: 2rem; color: #6c757d;">
                    <i class="fas fa-spinner fa-spin" style="margin-right: 0.5rem;"></i> Loading consultation details...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="printConsultation()" id="printConsultationBtn" style="display: none;">
                    <i class="fas fa-print"></i> Print
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeConsultationModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <script>
        // Consultation Management JavaScript Functions
        
        let currentConsultationId = null;

        // Filter consultations by status
        function filterConsultations(status, element) {
            // Update active tab
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            element.classList.add('active');

            // Filter table rows
            const rows = document.querySelectorAll('.consultation-row');
            rows.forEach(row => {
                if (status === 'all' || row.dataset.status === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // View consultation details
        function viewConsultationDetails(consultationId) {
            currentConsultationId = consultationId;
            
            document.getElementById('consultationModal').style.display = 'block';
            document.getElementById('consultationModalBody').innerHTML = '<div style="text-align: center; padding: 2rem; color: #6c757d;"><i class="fas fa-spinner fa-spin" style="margin-right: 0.5rem;"></i> Loading consultation details...</div>';
            document.getElementById('printConsultationBtn').style.display = 'none';

            fetch(`get_consultation_details.php?consultation_id=${consultationId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('consultationModalBody').innerHTML = html;
                    document.getElementById('printConsultationBtn').style.display = 'inline-block';
                })
                .catch(error => {
                    document.getElementById('consultationModalBody').innerHTML = '<div class="alert alert-error">Error loading consultation details.</div>';
                });
        }

        // Print consultation in popup window
        function printConsultation() {
            if (currentConsultationId) {
                // Open popup window with specific dimensions and features
                const popupFeatures = 'width=900,height=700,scrollbars=yes,resizable=yes,toolbar=no,location=no,directories=no,status=no,menubar=no';
                window.open(`print_consultation.php?consultation_id=${currentConsultationId}`, 'printWindow', popupFeatures);
            }
        }

        // Close modal
        function closeConsultationModal() {
            document.getElementById('consultationModal').style.display = 'none';
            document.getElementById('printConsultationBtn').style.display = 'none';
            currentConsultationId = null;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('consultationModal');
            if (event.target === modal) {
                closeConsultationModal();
            }
        }

        // Auto-dismiss alerts after 8 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.style.opacity = '0';
                        alert.style.transform = 'translateY(-10px)';
                        setTimeout(() => {
                            if (alert.parentNode) {
                                alert.remove();
                            }
                        }, 300);
                    }
                }, 8000);
            });
        });
    </script>
</body>

</html>