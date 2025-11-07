<?php
/**
 * Station Dashboard Template
 * 
 * This template provides the base structure for individual station dashboards
 * with queue management functionality and patient flow control.
 * 
 * Features:
 * - Real-time queue display
 * - Patient flow management buttons
 * - Station-specific customization
 * - Role-based access control
 * - Responsive design
 * 
 * To create a station-specific dashboard:
 * 1. Copy this template
 * 2. Rename to {station_type}_dashboard.php (e.g., triage_dashboard.php)
 * 3. Update STATION_CONFIGURATION section
 * 4. Customize station-specific functionality
 * 5. Add station-specific CSS if needed
 */

// Start output buffering to prevent header issues
ob_start();

// Include path resolution and security configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/production_security.php';
require_once $root_path . '/config/session/employee_session.php';

// Authentication check
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    error_log('Redirecting to employee_login from station dashboard');
    header('Location: ../management/auth/employee_login.php');
    exit();
}

require_once $root_path . '/config/db.php';
require_once $root_path . '/config/auth_helpers.php';
require_once $root_path . '/utils/queue_management_service.php';

// =====================================================================
// STATION CONFIGURATION SECTION
// =====================================================================
// Customize this section for each specific station

// Station Configuration - CUSTOMIZE FOR EACH STATION
$STATION_CONFIG = [
    'station_id' => 1,                    // Change for each station (1-15)
    'station_name' => 'Triage Station 1', // Display name
    'station_type' => 'triage',           // Type: triage, consultation, lab, pharmacy, billing, document
    'icon' => 'fa-stethoscope',           // FontAwesome icon
    'color_scheme' => [
        'primary' => '#2563eb',           // Primary color
        'secondary' => '#1e40af',         // Secondary color
        'accent' => '#60a5fa'             // Accent color
    ],
    'allowed_roles' => ['admin', 'nurse', 'doctor'], // Roles that can access this station
    'next_stations' => [                  // Possible next stations for patient flow
        'consultation' => ['station_id' => 5, 'name' => 'Primary Care 1', 'icon' => 'fa-user-md'],
        'lab' => ['station_id' => 13, 'name' => 'Laboratory', 'icon' => 'fa-flask'],
        'billing' => ['station_id' => 4, 'name' => 'Billing', 'icon' => 'fa-money-bill']
    ],
    'can_complete_visit' => false,        // Whether this station can complete patient visits
    'special_functions' => [              // Station-specific functions
        'record_vitals' => true,
        'view_history' => true,
        'priority_override' => true
    ]
];

// Override station config if station_id is provided in URL
if (isset($_GET['station_id'])) {
    $STATION_CONFIG['station_id'] = intval($_GET['station_id']);
}

// =====================================================================
// CORE FUNCTIONALITY
// =====================================================================

// Input sanitization function
if (!function_exists('sanitize_input')) {
    function sanitize_input($input) {
        if (is_string($input)) {
            return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
        }
        return $input;
    }
}

// Initialize queue management service
try {
    $queueService = new QueueManagementService($pdo);
} catch (Exception $e) {
    error_log('Queue service initialization error: ' . $e->getMessage());
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Location: ../management/auth/employee_login.php?error=service_unavailable');
    exit();
}

// Role-based access control
$user_role = strtolower($_SESSION['role']);
if (!in_array($user_role, $STATION_CONFIG['allowed_roles'])) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    $dashboard_url = '../management/' . $user_role . '/dashboard.php';
    header('Location: ' . $dashboard_url . '?error=access_denied');
    exit();
}

// Get station details
$station_id = $STATION_CONFIG['station_id'];
$station = $queueService->getStationDetails($station_id);

if (!$station) {
    error_log('Station not found: ' . $station_id);
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Location: ../management/admin/staff-management/staff_assignments.php?error=station_not_found');
    exit();
}

// Get current queue and statistics
$currentQueue = $queueService->getStationQueue($station_id);
$today = date('Y-m-d');
$stationStats = $queueService->getStationStatistics($station_id, $today);

// Get current patient (first in queue with status 'in_progress' or first 'waiting')
$currentPatient = null;
$waitingQueue = [];
$inProgressQueue = [];

foreach ($currentQueue as $patient) {
    if ($patient['status'] === 'in_progress') {
        $inProgressQueue[] = $patient;
        if (!$currentPatient) {
            $currentPatient = $patient;
        }
    } elseif ($patient['status'] === 'waiting') {
        $waitingQueue[] = $patient;
    }
}

// Process queue actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $patient_id = intval($_POST['patient_id'] ?? 0);
    
    try {
        switch ($action) {
            case 'call_next':
                // Find first waiting patient and update status to in_progress
                $waiting_patients = array_filter($currentQueue, function($p) { return $p['status'] === 'waiting'; });
                if (!empty($waiting_patients)) {
                    $next_patient = array_shift($waiting_patients);
                    $result = $queueService->updateQueueStatus(
                        $next_patient['queue_entry_id'], 
                        'in_progress', 
                        $_SESSION['employee_id'],
                        'Patient called to station'
                    );
                    if ($result['success']) {
                        $message = 'Patient called successfully.';
                    } else {
                        $error = $result['message'] ?? 'Failed to call next patient.';
                    }
                } else {
                    $error = 'No patients waiting in queue.';
                }
                break;
                
            case 'complete_current':
                if ($currentPatient) {
                    $result = $queueService->updateQueueStatus(
                        $currentPatient['queue_entry_id'], 
                        'completed', 
                        $_SESSION['employee_id'],
                        'Patient service completed'
                    );
                    if ($result['success']) {
                        $message = 'Patient completed successfully.';
                    } else {
                        $error = $result['message'] ?? 'Failed to complete patient.';
                    }
                }
                break;
                
            case 'skip_patient':
                if ($currentPatient) {
                    $result = $queueService->updateQueueStatus(
                        $currentPatient['queue_entry_id'], 
                        'skipped', 
                        $_SESSION['employee_id'],
                        'Patient skipped by staff'
                    );
                    if ($result['success']) {
                        $message = 'Patient skipped successfully.';
                    } else {
                        $error = $result['message'] ?? 'Failed to skip patient.';
                    }
                }
                break;
                
            case 'transfer_to_station':
                $next_station_id = intval($_POST['next_station_id'] ?? 0);
                if ($currentPatient && $next_station_id) {
                    // First complete current queue entry
                    $complete_result = $queueService->updateQueueStatus(
                        $currentPatient['queue_entry_id'], 
                        'completed', 
                        $_SESSION['employee_id'],
                        'Patient transferred to next station'
                    );
                    
                    if ($complete_result['success']) {
                        // Create new queue entry at next station
                        $transfer_result = $queueService->createQueueEntry(
                            $currentPatient['visit_id'],
                            $currentPatient['appointment_id'],
                            $currentPatient['patient_id'],
                            $currentPatient['service_id'],
                            $STATION_CONFIG['station_type'], // Current station type
                            $next_station_id,
                            $currentPatient['priority_level'] ?? 'normal'
                        );
                        
                        if ($transfer_result['success']) {
                            $message = 'Patient transferred successfully.';
                        } else {
                            $error = 'Failed to create queue entry at next station.';
                        }
                    } else {
                        $error = 'Failed to complete current patient before transfer.';
                    }
                }
                break;
                
            case 'recall_patient':
                // Find first skipped patient and return to waiting
                $skipped_patients = array_filter($currentQueue, function($p) { return $p['status'] === 'skipped'; });
                if (!empty($skipped_patients)) {
                    $recalled_patient = array_shift($skipped_patients);
                    $result = $queueService->updateQueueStatus(
                        $recalled_patient['queue_entry_id'], 
                        'waiting', 
                        $_SESSION['employee_id'],
                        'Patient recalled from skipped'
                    );
                    if ($result['success']) {
                        $message = 'Patient recalled successfully.';
                    } else {
                        $error = $result['message'] ?? 'Failed to recall patient.';
                    }
                } else {
                    $error = 'No skipped patients to recall.';
                }
                break;
                
            case 'complete_visit':
                if ($currentPatient && $STATION_CONFIG['can_complete_visit']) {
                    // Complete the queue entry
                    $queue_result = $queueService->updateQueueStatus(
                        $currentPatient['queue_entry_id'], 
                        'completed', 
                        $_SESSION['employee_id'],
                        'Visit completed'
                    );
                    
                    if ($queue_result['success']) {
                        // Update visit status to completed
                        try {
                            $visit_stmt = $pdo->prepare("
                                UPDATE visits 
                                SET visit_status = 'completed', updated_at = NOW() 
                                WHERE visit_id = ?
                            ");
                            $visit_stmt->execute([$currentPatient['visit_id']]);
                            
                            // Update appointment status if applicable
                            if ($currentPatient['appointment_id']) {
                                $appt_stmt = $pdo->prepare("
                                    UPDATE appointments 
                                    SET status = 'completed', updated_at = NOW() 
                                    WHERE appointment_id = ?
                                ");
                                $appt_stmt->execute([$currentPatient['appointment_id']]);
                            }
                            
                            $message = 'Patient visit completed successfully.';
                        } catch (Exception $e) {
                            $error = 'Failed to update visit status.';
                        }
                    } else {
                        $error = $queue_result['message'] ?? 'Failed to complete visit.';
                    }
                }
                break;
        }
        
        // Refresh data after action
        $currentQueue = $queueService->getStationQueue($station_id);
        $stationStats = $queueService->getStationStatistics($station_id, $today);
        
    } catch (Exception $e) {
        error_log('Station dashboard action error: ' . $e->getMessage());
        $error = 'An error occurred while processing the request.';
    }
}

// Determine sidebar file based on role
$sidebar_files = [
    'admin' => 'sidebar_admin.php',
    'doctor' => 'sidebar_doctor.php',
    'nurse' => 'sidebar_nurse.php',
    'laboratory_tech' => 'sidebar_laboratory_tech.php',
    'pharmacist' => 'sidebar_pharmacist.php',
    'cashier' => 'sidebar_cashier.php',
    'records_officer' => 'sidebar_records_officer.php'
];

$sidebar_file = $sidebar_files[$user_role] ?? 'sidebar_admin.php';
$activePage = 'queue_management';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($STATION_CONFIG['station_name']); ?> Dashboard | CHO Koronadal WBHSMS</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="../../assets/css/sidebar.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    
    <style>
        /* Station Dashboard Styles */
        :root {
            --station-primary: <?php echo $STATION_CONFIG['color_scheme']['primary']; ?>;
            --station-secondary: <?php echo $STATION_CONFIG['color_scheme']['secondary']; ?>;
            --station-accent: <?php echo $STATION_CONFIG['color_scheme']['accent']; ?>;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --light: #f8fafc;
            --border: #e2e8f0;
            --shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
            --border-radius: 0.5rem;
            --transition: all 0.3s ease;
        }

        .content-wrapper {
            margin-left: 280px;
            padding: 2rem;
            min-height: 100vh;
            background: var(--light);
        }

        @media (max-width: 960px) {
            .content-wrapper {
                margin-left: 0;
                padding: 1rem;
                margin-top: 70px;
            }
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: #64748b;
        }

        .breadcrumb a {
            color: var(--station-primary);
            text-decoration: none;
            transition: color 0.2s;
        }

        .breadcrumb a:hover {
            color: var(--station-secondary);
            text-decoration: underline;
        }

        /* Station Header */
        .station-header {
            background: linear-gradient(135deg, var(--station-primary), var(--station-secondary));
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
        }

        .station-header h1 {
            margin: 0;
            font-size: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .station-header .station-info {
            margin-top: 0.5rem;
            opacity: 0.9;
            font-size: 1rem;
        }

        .station-status {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .status-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Alert Messages */
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: #d1fae5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }

        .alert-error {
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        /* Statistics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            border-left: 4px solid var(--station-primary);
        }

        .stat-card .icon {
            font-size: 2rem;
            color: var(--station-primary);
            margin-bottom: 1rem;
        }

        .stat-card h3 {
            font-size: 2rem;
            font-weight: bold;
            margin: 0.5rem 0;
            color: #1f2937;
        }

        .stat-card p {
            color: #6b7280;
            margin: 0;
            font-size: 0.9rem;
        }

        /* Current Patient Section */
        .current-patient {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .current-patient-header {
            background: var(--station-primary);
            color: white;
            padding: 1rem 1.5rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .current-patient-content {
            padding: 1.5rem;
        }

        .patient-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .patient-detail {
            padding: 1rem;
            background: var(--light);
            border-radius: var(--border-radius);
        }

        .patient-detail strong {
            color: var(--station-primary);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .patient-detail div {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1f2937;
            margin-top: 0.25rem;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: var(--border-radius);
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: var(--transition);
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--station-primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--station-secondary);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        /* Queue Display */
        .queue-section {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .queue-header {
            background: var(--station-primary);
            color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: between;
            align-items: center;
        }

        .queue-content {
            padding: 1.5rem;
        }

        .queue-table {
            width: 100%;
            border-collapse: collapse;
        }

        .queue-table th {
            background: var(--light);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--station-primary);
            border-bottom: 2px solid var(--border);
        }

        .queue-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }

        .queue-table tr:hover {
            background: var(--light);
        }

        .queue-number {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--station-primary);
        }

        .priority-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .priority-normal { background: #e5e7eb; color: #374151; }
        .priority-priority { background: #fef3c7; color: #92400e; }
        .priority-emergency { background: #fee2e2; color: #991b1b; }

        .status-badge-queue {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .status-waiting { background: #dbeafe; color: #1e40af; }
        .status-in-progress { background: #d1fae5; color: #065f46; }
        .status-skipped { background: #fed7d7; color: #c53030; }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Transfer Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 500px;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            margin-bottom: 1.5rem;
        }

        .modal-header h3 {
            margin: 0;
            color: var(--station-primary);
        }

        .close {
            float: right;
            font-size: 2rem;
            font-weight: bold;
            cursor: pointer;
            color: #6b7280;
        }

        .close:hover {
            color: var(--danger);
        }

        .next-station-option {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border: 2px solid var(--border);
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            cursor: pointer;
            transition: var(--transition);
        }

        .next-station-option:hover {
            border-color: var(--station-primary);
            background: var(--light);
        }

        .next-station-option input[type="radio"] {
            margin: 0;
        }

        .next-station-icon {
            font-size: 2rem;
            color: var(--station-primary);
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .patient-info {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Include Role-Based Sidebar -->
    <?php include $root_path . '/includes/' . $sidebar_file; ?>
    
    <div class="content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb">
            <a href="../management/<?php echo $user_role; ?>/dashboard.php">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <i class="fas fa-chevron-right"></i>
            <a href="../management/admin/staff-management/staff_assignments.php">Station Assignments</a>
            <i class="fas fa-chevron-right"></i>
            <span><?php echo htmlspecialchars($STATION_CONFIG['station_name']); ?></span>
        </div>

        <!-- Station Header -->
        <div class="station-header">
            <h1>
                <i class="fas <?php echo $STATION_CONFIG['icon']; ?>"></i>
                <?php echo htmlspecialchars($STATION_CONFIG['station_name']); ?>
            </h1>
            <div class="station-info">
                <?php echo ucfirst($STATION_CONFIG['station_type']); ?> Station 
                | Staff: <?php echo htmlspecialchars($_SESSION['employee_name'] ?? 'Unknown'); ?>
                | Role: <?php echo htmlspecialchars(ucfirst($user_role)); ?>
            </div>
            <div class="station-status">
                <div class="status-badge">
                    <i class="fas fa-circle" style="color: #10b981;"></i>
                    Active
                </div>
                <div class="status-badge">
                    <i class="fas fa-clock"></i>
                    <?php echo date('H:i A'); ?>
                </div>
                <?php if ($station['employee_name']): ?>
                    <div class="status-badge">
                        <i class="fas fa-user"></i>
                        <?php echo htmlspecialchars($station['employee_name']); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="icon">
                    <i class="fas fa-users"></i>
                </div>
                <h3><?php echo $stationStats['total_served'] ?? 0; ?></h3>
                <p>Total Served Today</p>
            </div>
            <div class="stat-card">
                <div class="icon">
                    <i class="fas fa-clock"></i>
                </div>
                <h3><?php echo count($waitingQueue); ?></h3>
                <p>Patients Waiting</p>
            </div>
            <div class="stat-card">
                <div class="icon">
                    <i class="fas fa-user-check"></i>
                </div>
                <h3><?php echo count($inProgressQueue); ?></h3>
                <p>In Progress</p>
            </div>
            <div class="stat-card">
                <div class="icon">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <h3><?php echo $stationStats['avg_wait_time'] ?? 0; ?> min</h3>
                <p>Avg Wait Time</p>
            </div>
        </div>

        <!-- Current Patient Section -->
        <div class="current-patient">
            <div class="current-patient-header">
                <div>
                    <i class="fas fa-user-clock"></i>
                    Current Patient
                </div>
                <button class="btn btn-secondary" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i>
                    Refresh
                </button>
            </div>
            <div class="current-patient-content">
                <?php if ($currentPatient): ?>
                    <div class="patient-info">
                        <div class="patient-detail">
                            <strong>Queue Number</strong>
                            <div class="queue-number"><?php echo htmlspecialchars($currentPatient['queue_number']); ?></div>
                        </div>
                        <div class="patient-detail">
                            <strong>Patient Name</strong>
                            <div><?php echo htmlspecialchars($currentPatient['patient_name']); ?></div>
                        </div>
                        <div class="patient-detail">
                            <strong>Priority Level</strong>
                            <div>
                                <span class="priority-badge priority-<?php echo strtolower($currentPatient['priority_level'] ?? 'normal'); ?>">
                                    <?php echo ucfirst($currentPatient['priority_level'] ?? 'Normal'); ?>
                                </span>
                            </div>
                        </div>
                        <div class="patient-detail">
                            <strong>Wait Time</strong>
                            <div><?php echo $currentPatient['wait_time_minutes'] ?? 0; ?> minutes</div>
                        </div>
                    </div>

                    <!-- Patient Action Buttons -->
                    <div class="action-buttons">
                        <!-- Complete Current Patient -->
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="complete_current">
                            <input type="hidden" name="patient_id" value="<?php echo $currentPatient['patient_id']; ?>">
                            <button type="submit" class="btn btn-success" 
                                    onclick="return confirm('Complete current patient?')">
                                <i class="fas fa-check"></i>
                                Complete
                            </button>
                        </form>

                        <!-- Skip Patient -->
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="skip_patient">
                            <input type="hidden" name="patient_id" value="<?php echo $currentPatient['patient_id']; ?>">
                            <button type="submit" class="btn btn-warning" 
                                    onclick="return confirm('Skip this patient?')">
                                <i class="fas fa-forward"></i>
                                Skip
                            </button>
                        </form>

                        <!-- Transfer to Next Station -->
                        <?php if (!empty($STATION_CONFIG['next_stations'])): ?>
                            <button type="button" class="btn btn-primary" onclick="showTransferModal()">
                                <i class="fas fa-arrow-right"></i>
                                Transfer to Next Station
                            </button>
                        <?php endif; ?>

                        <!-- Complete Visit (if applicable) -->
                        <?php if ($STATION_CONFIG['can_complete_visit']): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="complete_visit">
                                <input type="hidden" name="patient_id" value="<?php echo $currentPatient['patient_id']; ?>">
                                <button type="submit" class="btn btn-danger" 
                                        onclick="return confirm('Complete patient visit and end appointment?')">
                                    <i class="fas fa-check-double"></i>
                                    Complete Visit
                                </button>
                            </form>
                        <?php endif; ?>

                        <!-- Station-Specific Actions -->
                        <?php if ($STATION_CONFIG['special_functions']['record_vitals']): ?>
                            <button type="button" class="btn btn-secondary" onclick="openVitalsModal()">
                                <i class="fas fa-heartbeat"></i>
                                Record Vitals
                            </button>
                        <?php endif; ?>

                        <?php if ($STATION_CONFIG['special_functions']['view_history']): ?>
                            <button type="button" class="btn btn-secondary" onclick="viewPatientHistory(<?php echo $currentPatient['patient_id']; ?>)">
                                <i class="fas fa-history"></i>
                                View History
                            </button>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-clock"></i>
                        <h3>No Current Patient</h3>
                        <p>Call the next patient to begin service.</p>
                        <form method="POST" style="margin-top: 1rem;">
                            <input type="hidden" name="action" value="call_next">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-bell"></i>
                                Call Next Patient
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Queue Display -->
        <div class="queue-section">
            <div class="queue-header">
                <h2>
                    <i class="fas fa-list"></i>
                    Waiting Queue (<?php echo count($waitingQueue); ?> patients)
                </h2>
            </div>
            <div class="queue-content">
                <?php if (!empty($waitingQueue)): ?>
                    <div style="overflow-x: auto;">
                        <table class="queue-table">
                            <thead>
                                <tr>
                                    <th>Queue #</th>
                                    <th>Patient Name</th>
                                    <th>Priority</th>
                                    <th>Wait Time</th>
                                    <th>Visit Type</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($waitingQueue as $index => $patient): ?>
                                    <tr>
                                        <td>
                                            <div class="queue-number">
                                                <?php echo htmlspecialchars($patient['queue_number']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($patient['patient_name']); ?></strong>
                                            <br>
                                            <small style="color: #666;">
                                                ID: <?php echo htmlspecialchars($patient['patient_id']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="priority-badge priority-<?php echo strtolower($patient['priority_level'] ?? 'normal'); ?>">
                                                <?php echo ucfirst($patient['priority_level'] ?? 'Normal'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $patient['wait_time_minutes'] ?? 0; ?> min
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($patient['visit_type'] ?? 'Regular'); ?>
                                        </td>
                                        <td>
                                            <?php if ($index === 0 && !$currentPatient): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="call_next">
                                                    <button type="submit" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-bell"></i>
                                                        Call
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h3>No Patients Waiting</h3>
                        <p>The queue is currently empty.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Transfer Modal -->
    <?php if (!empty($STATION_CONFIG['next_stations']) && $currentPatient): ?>
        <div id="transferModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <span class="close" onclick="hideTransferModal()">&times;</span>
                    <h3>
                        <i class="fas fa-arrow-right"></i>
                        Transfer Patient to Next Station
                    </h3>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="transfer_to_station">
                    <input type="hidden" name="patient_id" value="<?php echo $currentPatient['patient_id'] ?? ''; ?>">
                    
                    <p>Select the next station for <strong><?php echo htmlspecialchars($currentPatient['patient_name'] ?? ''); ?></strong>:</p>
                    
                    <?php foreach ($STATION_CONFIG['next_stations'] as $key => $station): ?>
                        <label class="next-station-option">
                            <input type="radio" name="next_station_id" value="<?php echo $station['station_id']; ?>" required>
                            <div class="next-station-icon">
                                <i class="fas <?php echo $station['icon']; ?>"></i>
                            </div>
                            <div>
                                <strong><?php echo htmlspecialchars($station['name']); ?></strong>
                                <br>
                                <small style="color: #666;"><?php echo ucfirst($key); ?> Services</small>
                            </div>
                        </label>
                    <?php endforeach; ?>
                    
                    <div style="margin-top: 1.5rem; text-align: right;">
                        <button type="button" class="btn btn-secondary" onclick="hideTransferModal()">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-arrow-right"></i>
                            Transfer Patient
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- JavaScript -->
    <script>
        // Transfer Modal Functions
        function showTransferModal() {
            document.getElementById('transferModal').style.display = 'block';
        }

        function hideTransferModal() {
            document.getElementById('transferModal').style.display = 'none';
        }

        // Click outside modal to close
        window.onclick = function(event) {
            const modal = document.getElementById('transferModal');
            if (event.target === modal) {
                hideTransferModal();
            }
        }

        // Station-specific functions (customize for each station)
        function openVitalsModal() {
            // Implement vitals recording modal
            alert('Vitals recording feature - customize for your needs');
        }

        function viewPatientHistory(patientId) {
            // Implement patient history view
            window.open(`../medical-records/patient_profile.php?patient_id=${patientId}`, '_blank');
        }

        // Auto-refresh every 30 seconds
        setInterval(function() {
            // Only refresh if no modal is open
            if (document.getElementById('transferModal').style.display !== 'block') {
                location.reload();
            }
        }, 30000);

        // Keyboard shortcuts
        document.addEventListener('keydown', function(event) {
            // Alt + N: Call Next Patient
            if (event.altKey && event.key === 'n') {
                const callNextForm = document.querySelector('form input[value="call_next"]');
                if (callNextForm) {
                    callNextForm.closest('form').submit();
                }
            }
            
            // Alt + C: Complete Current Patient
            if (event.altKey && event.key === 'c') {
                const completeForm = document.querySelector('form input[value="complete_current"]');
                if (completeForm) {
                    if (confirm('Complete current patient?')) {
                        completeForm.closest('form').submit();
                    }
                }
            }
            
            // Alt + S: Skip Current Patient
            if (event.altKey && event.key === 's') {
                const skipForm = document.querySelector('form input[value="skip_patient"]');
                if (skipForm) {
                    if (confirm('Skip current patient?')) {
                        skipForm.closest('form').submit();
                    }
                }
            }
            
            // Alt + T: Transfer Patient
            if (event.altKey && event.key === 't') {
                showTransferModal();
            }
            
            // Escape: Close Modal
            if (event.key === 'Escape') {
                hideTransferModal();
            }
        });

        // Show success/error messages for 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 300);
                }, 5000);
            });
        });
    </script>
</body>
</html>