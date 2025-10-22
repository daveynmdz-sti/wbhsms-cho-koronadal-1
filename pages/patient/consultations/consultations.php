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
    
    if ($patient_info) {
        // Calculate priority level
        $patient_info['priority_level'] = ($patient_info['isPWD'] || $patient_info['isSenior']) ? 1 : 2;
        $patient_info['priority_description'] = ($patient_info['priority_level'] == 1) ? 'Priority Patient' : 'Regular Patient';
    }
    
} catch (Exception $e) {
    logConsultationError("Failed to fetch patient info: " . $e->getMessage(), [
        'patient_id' => $patient_id,
        'sql_error' => $e->getMessage(),
        'file' => __FILE__,
        'line' => __LINE__
    ]);
    $patient_info = null;
}

// Fetch consultations
$consultations = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.consultation_id, c.patient_id, c.visit_id, c.chief_complaint,
               c.diagnosis, c.treatment_plan, c.consultation_status,
               c.consultation_date, c.updated_at,
               v.visit_date, v.time_in as visit_time,
               CONCAT(e.first_name, ' ', e.last_name) as doctor_name,
               e.license_number as doctor_license,
               p.first_name as patient_first_name, 
               p.last_name as patient_last_name,
               p.username as patient_number
        FROM consultations c
        LEFT JOIN visits v ON c.visit_id = v.visit_id
        LEFT JOIN patients p ON c.patient_id = p.patient_id
        LEFT JOIN employees e ON c.attending_employee_id = e.employee_id
        WHERE c.patient_id = ?
        ORDER BY c.consultation_date DESC, c.consultation_id DESC
    ");
    
    $stmt->execute([$patient_id]);
    $consultations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    logConsultationError("Failed to fetch consultations: " . $e->getMessage(), [
        'patient_id' => $patient_id,
        'sql_error' => $e->getMessage(),
        'file' => __FILE__,
        'line' => __LINE__
    ]);
}

// Calculate age helper function
function calculateAge($birthdate) {
    if (empty($birthdate)) return 'N/A';
    return date_diff(date_create($birthdate), date_create('now'))->y;
}

// Format date helper function
function formatDate($date, $format = 'M j, Y') {
    if (empty($date)) return 'N/A';
    return date($format, strtotime($date));
}

// Format time helper function  
function formatTime($time, $format = 'g:i A') {
    if (empty($time)) return 'N/A';
    return date($format, strtotime($time));
}

ob_end_flush(); // End output buffering
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Consultations - WBHSMS Patient Portal</title>
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="../../../assets/css/sidebar.css">
    <link rel="stylesheet" href="../../../assets/css/consultation-details.css">
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
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            box-shadow: 0 4px 15px rgba(0, 119, 182, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #023e8a, #001d3d);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 119, 182, 0.4);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
        }

        /* Filters and Search */
        .filters-section {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 0.75rem 1.25rem;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .filter-tab.active {
            background: #0077b6;
            color: white;
            border-color: #0077b6;
        }

        .filter-tab:hover:not(.active) {
            background: #e9ecef;
        }

        /* Consultation Cards */
        .consultations-grid {
            display: grid;
            gap: 1.5rem;
        }

        .consultation-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .consultation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.15);
        }

        .consultation-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .consultation-info h3 {
            margin: 0 0 0.5rem 0;
            color: #2c3e50;
            font-size: 1.1rem;
        }

        .consultation-meta {
            font-size: 0.9rem;
            color: #6c757d;
        }

        .consultation-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
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
            background: #d1ecf1;
            color: #0c5460;
        }

        .consultation-content {
            margin-bottom: 1rem;
        }

        .complaint {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
        }

        .diagnosis {
            color: #2c3e50;
            font-size: 0.95rem;
        }

        .consultation-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
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
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background-color: #fff;
            margin: 2% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
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
            padding: 1.5rem;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            border-radius: 12px 12px 0 0;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.25rem;
        }

        .close {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: white;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.3s ease;
        }

        .close:hover {
            background-color: rgba(255, 255, 255, 0.2);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e9ecef;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: #495057;
        }

        .empty-state p {
            max-width: 500px;
            margin: 0 auto;
            line-height: 1.6;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .consultation-header {
                flex-direction: column;
                gap: 0.5rem;
            }

            .consultation-actions {
                margin-top: 1rem;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
            }

            .filter-tabs {
                flex-direction: column;
            }

            .filter-tab {
                text-align: center;
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
            <a href="../dashboard.php"><i class="fas fa-home"></i> Dashboard</a> &raquo; 
            <span>My Consultations</span>
        </div>

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1><i class="fas fa-stethoscope"></i> My Consultations</h1>
                <p style="margin: 0; color: #6c757d;">View your medical consultation history and details</p>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
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
        </div>

        <!-- Consultations Grid -->
        <div class="consultations-grid">
            <?php if (empty($consultations)): ?>
                <div class="empty-state">
                    <i class="fas fa-stethoscope"></i>
                    <h3>No Consultations Yet</h3>
                    <p>You don't have any consultation records yet. Consultations will appear here after you visit the CHO for medical care.</p>
                </div>
            <?php else: ?>
                <?php foreach ($consultations as $consultation): ?>
                    <div class="consultation-card" data-status="<?php echo htmlspecialchars($consultation['consultation_status']); ?>">
                        <div class="consultation-header">
                            <div class="consultation-info">
                                <h3>Consultation #<?php echo htmlspecialchars($consultation['consultation_id']); ?></h3>
                                <div class="consultation-meta">
                                    <i class="fas fa-calendar"></i> <?php echo formatDate($consultation['consultation_date']); ?>
                                    <?php if (!empty($consultation['visit_time'])): ?>
                                        <i class="fas fa-clock" style="margin-left: 1rem;"></i> <?php echo formatTime($consultation['visit_time']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($consultation['doctor_name'])): ?>
                                        <br><i class="fas fa-user-md"></i> Dr. <?php echo htmlspecialchars($consultation['doctor_name']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="consultation-status status-<?php echo str_replace(' ', '-', strtolower($consultation['consultation_status'])); ?>">
                                <?php echo htmlspecialchars($consultation['consultation_status']); ?>
                            </span>
                        </div>
                        
                        <div class="consultation-content">
                            <div class="complaint">
                                <i class="fas fa-comment-medical"></i> Chief Complaint:
                                <?php echo htmlspecialchars($consultation['chief_complaint'] ?: 'Not specified'); ?>
                            </div>
                            <?php if (!empty($consultation['diagnosis'])): ?>
                                <div class="diagnosis">
                                    <i class="fas fa-diagnoses"></i> Diagnosis: 
                                    <?php echo htmlspecialchars($consultation['diagnosis']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="consultation-actions">
                            <button class="btn btn-primary btn-sm" onclick="viewConsultationDetails(<?php echo $consultation['consultation_id']; ?>)">
                                <i class="fas fa-eye"></i> View Details
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="printConsultation(<?php echo $consultation['consultation_id']; ?>)">
                                <i class="fas fa-print"></i> Print
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- Consultation Details Modal -->
    <div id="consultationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-file-medical"></i> Consultation Details</h2>
                <button class="close" onclick="closeModal('consultationModal')">&times;</button>
            </div>
            <div class="modal-body" id="consultationModalBody">
                <!-- Content will be loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button id="printConsultationBtn" class="btn btn-secondary" onclick="printCurrentConsultation()" style="display: none;">
                    <i class="fas fa-print"></i> Print Consultation
                </button>
                <button class="btn btn-primary" onclick="closeModal('consultationModal')">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentConsultationId = null;

        // Filter consultations
        function filterConsultations(status, element) {
            // Update active tab
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            element.classList.add('active');
            
            // Show/hide consultation cards
            const cards = document.querySelectorAll('.consultation-card');
            cards.forEach(card => {
                const cardStatus = card.dataset.status.toLowerCase();
                if (status === 'all' || cardStatus === status || cardStatus.includes(status)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
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
                    // Ensure consultation details CSS is loaded
                    if (!document.querySelector('link[href*="consultation-details.css"]')) {
                        const cssLink = document.createElement('link');
                        cssLink.rel = 'stylesheet';
                        cssLink.href = '../../../assets/css/consultation-details.css';
                        document.head.appendChild(cssLink);
                    }
                    
                    document.getElementById('consultationModalBody').innerHTML = html;
                    document.getElementById('printConsultationBtn').style.display = 'inline-block';
                })
                .catch(error => {
                    document.getElementById('consultationModalBody').innerHTML = '<div class="alert alert-error">Error loading consultation details.</div>';
                });
        }

        // Print consultation
        function printConsultation(consultationId) {
            window.open(`../../../api/consultations/print_consultation.php?consultation_id=${consultationId}&type=patient`, '_blank');
        }

        // Print current consultation in modal
        function printCurrentConsultation() {
            if (currentConsultationId) {
                printConsultation(currentConsultationId);
            }
        }

        // Close modal
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('consultationModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }

        // ESC key support for closing modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal('consultationModal');
            }
        });
    </script>
</body>

</html>