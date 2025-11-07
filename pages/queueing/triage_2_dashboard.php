<?php
/**
 * Triage Station 2 Dashboard
 * 
 * Specialized dashboard for Triage Station 2 operations
 * Features: Patient queue management, vitals recording, priority assessment
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
    error_log('Redirecting to employee_login from triage 2 dashboard');
    header('Location: ../management/auth/employee_login.php');
    exit();
}

require_once $root_path . '/config/db.php';
require_once $root_path . '/config/auth_helpers.php';

// Station 2 Queue Management Functions
function getStation2Queue($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT q.*, 
                   CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                   p.username as patient_username,
                   TIMESTAMPDIFF(MINUTE, q.time_in, NOW()) as wait_time_minutes,
                   TIMESTAMPDIFF(MINUTE, q.time_started, NOW()) as service_time_minutes
            FROM station_2_queue q
            JOIN patients p ON q.patient_id = p.patient_id
            WHERE DATE(q.time_in) = CURDATE()
            ORDER BY q.priority_level DESC, q.time_in ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('getStation2Queue Error: ' . $e->getMessage());
        return [];
    }
}

function updateStation2QueueStatus($pdo, $queue_entry_id, $status, $employee_id = null, $remarks = null) {
    try {
        $time_field = '';
        switch ($status) {
            case 'in_progress':
                $time_field = ', time_started = NOW()';
                break;
            case 'done':
            case 'completed':
                $time_field = ', time_completed = NOW()';
                break;
        }

        $stmt = $pdo->prepare("
            UPDATE station_2_queue 
            SET status = ?, remarks = ?, updated_at = NOW() $time_field
            WHERE queue_entry_id = ?
        ");
        
        $result = $stmt->execute([$status, $remarks, $queue_entry_id]);
        return ['success' => $result, 'message' => $result ? 'Status updated successfully' : 'Failed to update status'];
        
    } catch (Exception $e) {
        error_log('updateStation2QueueStatus Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Database error occurred'];
    }
}

function addToStationQueue($pdo, $station_id, $patient_id, $username, $visit_id, $appointment_id, $service_id, $queue_type, $priority_level = 'normal') {
    try {
        $table_name = "station_{$station_id}_queue";
        
        $stmt = $pdo->prepare("
            INSERT INTO $table_name (
                patient_id, username, visit_id, appointment_id, service_id,
                queue_type, station_id, priority_level, status, time_in
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'waiting', NOW())
        ");
        
        $result = $stmt->execute([
            $patient_id, $username, $visit_id, $appointment_id, $service_id,
            $queue_type, $station_id, $priority_level
        ]);
        
        return ['success' => $result, 'queue_entry_id' => $pdo->lastInsertId()];
        
    } catch (Exception $e) {
        error_log('addToStationQueue Error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to add patient to station queue'];
    }
}

function getStation2Statistics($pdo, $date = null) {
    try {
        if ($date === null) {
            $date = date('Y-m-d');
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_served,
                COUNT(CASE WHEN status = 'done' THEN 1 END) as completed,
                COUNT(CASE WHEN status = 'waiting' THEN 1 END) as waiting,
                COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
                AVG(CASE WHEN status = 'done' AND turnaround_time IS NOT NULL 
                    THEN turnaround_time END) as avg_wait_time
            FROM station_2_queue 
            WHERE DATE(time_in) = ?
        ");
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log('getStation2Statistics Error: ' . $e->getMessage());
        return ['total_served' => 0, 'completed' => 0, 'waiting' => 0, 'in_progress' => 0, 'avg_wait_time' => 0];
    }
}

// =====================================================================
// TRIAGE STATION 2 CONFIGURATION
// =====================================================================

// Triage Station 2 Configuration
$STATION_CONFIG = [
    'station_id' => 2,                    
    'station_name' => 'Triage Station 2', 
    'station_type' => 'triage',           
    'icon' => 'fa-stethoscope',           
    'color_scheme' => [
        'primary' => '#0ea5e9',           // Sky blue (different from Station 1)
        'secondary' => '#0284c7',         
        'accent' => '#7dd3fc'             
    ],
    'allowed_roles' => ['admin', 'nurse'], // Nurses primarily handle triage
    'next_stations' => [                  
        'consultation' => ['station_id' => 5, 'name' => 'Primary Care 1', 'icon' => 'fa-user-md'],
        'consultation2' => ['station_id' => 6, 'name' => 'Primary Care 2', 'icon' => 'fa-user-md']
    ],
    'can_complete_visit' => false,        // Triage doesn't complete visits
    'special_functions' => [              
        'record_vitals' => true,          // Essential for triage
        'view_history' => true,
        'priority_override' => true       // Nurses can adjust priority
    ]
];

// Input sanitization function
if (!function_exists('sanitize_input')) {
    function sanitize_input($input) {
        if (is_string($input)) {
            return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
        }
        return $input;
    }
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

// Get station details directly - Station 2 is always Triage 2
$station_id = $STATION_CONFIG['station_id'];
$station = [
    'id' => 2,
    'name' => 'Triage Station 2',
    'type' => 'triage',
    'description' => 'Secondary triage and patient assessment'
];

if (!$station) {
    error_log('Station not found: ' . $station_id);
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Location: ../management/admin/staff-management/staff_assignments.php?error=station_not_found');
    exit();
}

// Get current queue and statistics based on station_2_queue table
$currentQueue = getStation2Queue($pdo);
$today = date('Y-m-d');
$stationStats = getStation2Statistics($pdo, $today);

// Organize queue by status
$waitingQueue = [];
$inProgressQueue = [];
$completedQueue = [];
$skippedQueue = [];
$transferredQueue = [];

foreach ($currentQueue as $patient) {
    switch ($patient['status']) {
        case 'waiting':
            $waitingQueue[] = $patient;
            break;
        case 'in_progress':
            $inProgressQueue[] = $patient;
            break;
        case 'completed':
            $completedQueue[] = $patient;
            break;
        case 'skipped':
            $skippedQueue[] = $patient;
            break;
        case 'transferred':
            $transferredQueue[] = $patient;
            break;
    }
}

// Get current patient (first in progress, or null if none)
$currentPatient = !empty($inProgressQueue) ? $inProgressQueue[0] : null;

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
                    $result = updateStation2QueueStatus(
                        $pdo,
                        $next_patient['queue_entry_id'], 
                        'in_progress', 
                        $_SESSION['employee_id'],
                        'Patient called to triage station 2'
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
                if ($patient_id) {
                    // Find the patient's queue entry
                    $patient_queue = null;
                    foreach ($currentQueue as $p) {
                        if ($p['patient_id'] == $patient_id && $p['status'] == 'in_progress') {
                            $patient_queue = $p;
                            break;
                        }
                    }
                    
                    if ($patient_queue) {
                        $result = updateStation2QueueStatus(
                            $pdo,
                            $patient_queue['queue_entry_id'], 
                            'completed', 
                            $_SESSION['employee_id'],
                            'Triage assessment completed at station 2'
                        );
                        if ($result['success']) {
                            $message = 'Patient triage completed successfully.';
                        } else {
                            $error = $result['message'] ?? 'Failed to complete patient.';
                        }
                    } else {
                        $error = 'Patient not found in active queue.';
                    }
                }
                break;
                
            case 'skip_patient':
                if ($patient_id) {
                    // Find the patient's queue entry
                    $patient_queue = null;
                    foreach ($currentQueue as $p) {
                        if ($p['patient_id'] == $patient_id && $p['status'] == 'in_progress') {
                            $patient_queue = $p;
                            break;
                        }
                    }
                    
                    if ($patient_queue) {
                        $result = updateStation2QueueStatus(
                            $pdo,
                            $patient_queue['queue_entry_id'], 
                            'skipped', 
                            $_SESSION['employee_id'],
                            'Patient skipped at triage station 2'
                        );
                        if ($result['success']) {
                            $message = 'Patient skipped successfully.';
                        } else {
                            $error = $result['message'] ?? 'Failed to skip patient.';
                        }
                    } else {
                        $error = 'Patient not found in active queue.';
                    }
                }
                break;
                
            case 'transfer_to_consultation':
            case 'transfer_to_consultation2':
                $target_station = $action === 'transfer_to_consultation2' ? 6 : 5;
                $target_name = $action === 'transfer_to_consultation2' ? 'Primary Care 2' : 'Primary Care 1';
                
                if ($patient_id) {
                    // Find the patient's queue entry
                    $patient_queue = null;
                    foreach ($currentQueue as $p) {
                        if ($p['patient_id'] == $patient_id && $p['status'] == 'in_progress') {
                            $patient_queue = $p;
                            break;
                        }
                    }
                    
                    if ($patient_queue) {
                        // Complete current queue entry
                        $complete_result = updateStation2QueueStatus(
                            $pdo,
                            $patient_queue['queue_entry_id'], 
                            'completed', 
                            $_SESSION['employee_id'],
                            'Transferred to ' . $target_name . ' from triage station 2'
                        );
                        
                        if ($complete_result['success']) {
                            // Create new queue entry at consultation station
                            $transfer_result = addToStationQueue(
                                $pdo,
                                $target_station,
                                $patient_queue['patient_id'],
                                $patient_queue['username'],
                                $patient_queue['visit_id'],
                                $patient_queue['appointment_id'],
                                $patient_queue['service_id'],
                                'consultation', // Queue type for consultation
                                $patient_queue['priority_level'] ?? 'normal'
                            );
                            
                            if ($transfer_result['success']) {
                                $message = 'Patient transferred to ' . $target_name . ' successfully.';
                            } else {
                                $error = 'Failed to create queue entry at ' . $target_name . '.';
                            }
                        } else {
                            $error = 'Failed to complete current patient before transfer.';
                        }
                    } else {
                        $error = 'Patient not found in active queue.';
                    }
                }
                break;
                
            case 'recall_patient':
                // Find first skipped patient and return to waiting
                $skipped_patients = array_filter($currentQueue, function($p) { return $p['status'] === 'skipped'; });
                if (!empty($skipped_patients)) {
                    $recalled_patient = array_shift($skipped_patients);
                    $result = updateStation2QueueStatus(
                        $pdo,
                        $recalled_patient['queue_entry_id'], 
                        'waiting', 
                        $_SESSION['employee_id'],
                        'Patient recalled to triage station 2'
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
                
            case 'recall_specific_patient':
                $queue_entry_id = intval($_POST['queue_entry_id'] ?? 0);
                if ($queue_entry_id) {
                    $result = updateStation2QueueStatus(
                        $pdo,
                        $queue_entry_id, 
                        'waiting', 
                        $_SESSION['employee_id'],
                        'Patient recalled from skipped status at station 2'
                    );
                    if ($result['success']) {
                        $message = 'Patient recalled successfully.';
                    } else {
                        $error = $result['message'] ?? 'Failed to recall patient.';
                    }
                } else {
                    $error = 'Invalid queue entry ID.';
                }
                break;
        }
        
        // Refresh data after action using station_2_queue
        $currentQueue = getStation2Queue($pdo);
        $stationStats = getStation2Statistics($pdo, $today);
        
        // Recalculate queue organization
        $waitingQueue = [];
        $inProgressQueue = [];
        $completedQueue = [];
        $skippedQueue = [];
        $transferredQueue = [];

        foreach ($currentQueue as $patient) {
            switch ($patient['status']) {
                case 'waiting':
                    $waitingQueue[] = $patient;
                    break;
                case 'in_progress':
                    $inProgressQueue[] = $patient;
                    break;
                case 'completed':
                    $completedQueue[] = $patient;
                    break;
                case 'skipped':
                    $skippedQueue[] = $patient;
                    break;
                case 'transferred':
                    $transferredQueue[] = $patient;
                    break;
            }
        }

        // Get current patient
        $currentPatient = !empty($inProgressQueue) ? $inProgressQueue[0] : null;
        
    } catch (Exception $e) {
        error_log('Triage station 2 dashboard action error: ' . $e->getMessage());
        $error = 'An error occurred while processing the request.';
    }
}

// Determine sidebar file based on role
$sidebar_files = [
    'admin' => 'sidebar_admin.php',
    'nurse' => 'sidebar_nurse.php',
    'doctor' => 'sidebar_doctor.php'
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
        /* Triage Station 2 Styles - Sky Blue Theme */
        :root {
            --station-primary: #0ea5e9;
            --station-secondary: #0284c7;
            --station-accent: #7dd3fc;
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
            margin-bottom: 1.5rem;
        }

        .queue-header {
            color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .queue-content {
            padding: 1.5rem;
        }

        /* Queue Management Grid */
        .queue-management-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        @media (max-width: 1200px) {
            .queue-management-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Patient Cards */
        .patient-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }

        .patient-card {
            background: var(--light);
            border-radius: var(--border-radius);
            padding: 1rem;
            box-shadow: var(--shadow);
            transition: var(--transition);
            border-left: 4px solid #ddd;
        }

        .patient-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .patient-card.waiting {
            border-left-color: var(--info);
        }

        .patient-card.in-progress {
            border-left-color: var(--success);
            background: #f0fdf4;
        }

        .patient-card.completed {
            border-left-color: #6b7280;
            background: #f9fafb;
        }

        .patient-card.skipped {
            border-left-color: var(--warning);
            background: #fffbeb;
        }

        .patient-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .patient-card-content h4 {
            margin: 0 0 0.5rem 0;
            font-size: 1.1rem;
            color: #1f2937;
        }

        .patient-card-content p {
            margin: 0.25rem 0;
            font-size: 0.9rem;
            color: #6b7280;
        }

        .patient-card-actions {
            margin-top: 1rem;
        }

        .action-group {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            flex-wrap: wrap;
        }

        .special-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* Transfer Dropdown */
        .transfer-dropdown {
            position: relative;
            display: inline-block;
        }

        .transfer-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            z-index: 1000;
            background: white;
            border: 1px solid var(--border);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-lg);
            min-width: 200px;
        }

        .transfer-menu.active {
            display: block;
        }

        .transfer-option {
            display: block;
            width: 100%;
            padding: 0.75rem 1rem;
            border: none;
            background: none;
            text-align: left;
            cursor: pointer;
            transition: var(--transition);
            border-bottom: 1px solid #f3f4f6;
        }

        .transfer-option:last-child {
            border-bottom: none;
        }

        .transfer-option:hover {
            background: var(--light);
            color: var(--station-primary);
        }

        .transfer-option i {
            margin-right: 0.5rem;
            color: var(--station-primary);
        }

        /* Button Sizes */
        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
        }

        .dropdown-toggle::after {
            content: ' ▼';
            font-size: 0.7rem;
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
        .priority-emergency { 
            background: #fee2e2; 
            color: #991b1b; 
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        .status-badge-queue {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            font-weight: bold;
        }

        .status-waiting { background: #dbeafe; color: #1e40af; }
        .status-in-progress { background: #d1fae5; color: #065f46; }
        .status-skipped { background: #fed7d7; color: #c53030; }

        /* Queue Type Specific Headers */
        .waiting-queue .queue-header {
            background: var(--info);
        }

        .in-progress-queue .queue-header {
            background: var(--success);
        }

        .completed-queue .queue-header {
            background: #6b7280;
        }

        .skipped-queue .queue-header {
            background: var(--warning);
        }

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
                Triage Assessment & Patient Routing (Station 2)
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
                <p>Total Triaged Today</p>
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
                <p>In Assessment</p>
            </div>
            <div class="stat-card">
                <div class="icon">
                    <i class="fas fa-hourglass-half"></i>
                </div>
                <h3><?php echo $stationStats['avg_wait_time'] ?? 0; ?> min</h3>
                <p>Avg Triage Time</p>
            </div>
        </div>

        <!-- Current Patient Section -->
        <div class="current-patient">
            <div class="current-patient-header">
                <div>
                    <i class="fas fa-user-clock"></i>
                    Current Patient - Triage Assessment (Station 2)
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
                        <!-- Complete Triage Assessment -->
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="complete_current">
                            <input type="hidden" name="patient_id" value="<?php echo $currentPatient['patient_id']; ?>">
                            <button type="submit" class="btn btn-success" 
                                    onclick="return confirm('Complete triage assessment?')">
                                <i class="fas fa-check"></i>
                                Complete Triage
                            </button>
                        </form>

                        <!-- Transfer Dropdown -->
                        <div class="transfer-dropdown">
                            <button type="button" class="btn btn-primary dropdown-toggle" onclick="toggleTransferMenu(this)">
                                <i class="fas fa-arrow-right"></i> Transfer Patient
                            </button>
                            <div class="transfer-menu">
                                <?php foreach ($STATION_CONFIG['next_stations'] as $key => $station_info): ?>
                                    <form method="POST" style="display: block;">
                                        <input type="hidden" name="action" value="transfer_to_<?php echo $key; ?>">
                                        <input type="hidden" name="patient_id" value="<?php echo $currentPatient['patient_id']; ?>">
                                        <button type="submit" class="transfer-option" 
                                                onclick="return confirm('Transfer to <?php echo $station_info['name']; ?>?')">
                                            <i class="fas <?php echo $station_info['icon']; ?>"></i>
                                            <?php echo $station_info['name']; ?>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        </div>

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

                        <!-- Record Vitals -->
                        <button type="button" class="btn btn-secondary" onclick="openVitalsModal(<?php echo $currentPatient['patient_id']; ?>)">
                            <i class="fas fa-heartbeat"></i>
                            Record Vitals
                        </button>

                        <!-- View History -->
                        <button type="button" class="btn btn-secondary" onclick="viewPatientHistory(<?php echo $currentPatient['patient_id']; ?>)">
                            <i class="fas fa-history"></i>
                            View History
                        </button>
                    </div>

                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-user-clock"></i>
                        <h3>No Current Patient</h3>
                        <p>Call the next patient for triage assessment at Station 2.</p>
                        <div style="margin-top: 1rem; display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="call_next">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-bell"></i>
                                    Call Next Patient
                                </button>
                            </form>
                            
                            <!-- Recall Skipped Patient -->
                            <?php 
                            $skipped_count = count(array_filter($currentQueue, function($p) { return $p['status'] === 'skipped'; }));
                            if ($skipped_count > 0): 
                            ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="recall_patient">
                                    <button type="submit" class="btn btn-secondary">
                                        <i class="fas fa-undo"></i>
                                        Recall Skipped (<?php echo $skipped_count; ?>)
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Comprehensive Queue Management Sections -->
        <div class="queue-management-grid">
            
            <!-- Waiting Queue Section -->
            <div class="queue-section waiting-queue">
                <div class="queue-header">
                    <h3>
                        <i class="fas fa-clock"></i>
                        Waiting Queue (<?php echo count($waitingQueue); ?> patients)
                    </h3>
                    <div class="queue-actions">
                        <?php if (!empty($waitingQueue) && !$currentPatient): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="call_next">
                                <button type="submit" class="btn btn-primary btn-sm">
                                    <i class="fas fa-bell"></i>
                                    Call Next
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="queue-content">
                    <?php if (!empty($waitingQueue)): ?>
                        <div class="patient-cards">
                            <?php foreach ($waitingQueue as $index => $patient): ?>
                                <div class="patient-card waiting" data-patient-id="<?php echo $patient['patient_id']; ?>">
                                    <div class="patient-card-header">
                                        <div class="queue-number"><?php echo htmlspecialchars($patient['queue_number']); ?></div>
                                        <span class="priority-badge priority-<?php echo strtolower($patient['priority_level'] ?? 'normal'); ?>">
                                            <?php echo ucfirst($patient['priority_level'] ?? 'Normal'); ?>
                                        </span>
                                    </div>
                                    <div class="patient-card-content">
                                        <h4><?php echo htmlspecialchars($patient['patient_name']); ?></h4>
                                        <p>Wait: <?php echo $patient['wait_time_minutes'] ?? 0; ?> min</p>
                                        <p>Type: <?php echo htmlspecialchars($patient['visit_type'] ?? 'Regular'); ?></p>
                                    </div>
                                    <?php if ($index === 0 && !$currentPatient): ?>
                                        <div class="patient-card-actions">
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="call_next">
                                                <button type="submit" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-bell"></i> Call Now
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-list"></i>
                            <p>No patients waiting</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- In Progress Queue Section -->
            <div class="queue-section in-progress-queue">
                <div class="queue-header">
                    <h3>
                        <i class="fas fa-user-check"></i>
                        In Progress (<?php echo count($inProgressQueue); ?> patients)
                    </h3>
                </div>
                <div class="queue-content">
                    <?php if (!empty($inProgressQueue)): ?>
                        <div class="patient-cards">
                            <?php foreach ($inProgressQueue as $patient): ?>
                                <div class="patient-card in-progress" data-patient-id="<?php echo $patient['patient_id']; ?>">
                                    <div class="patient-card-header">
                                        <div class="queue-number"><?php echo htmlspecialchars($patient['queue_number']); ?></div>
                                        <span class="status-badge-queue status-in-progress">Active</span>
                                    </div>
                                    <div class="patient-card-content">
                                        <h4><?php echo htmlspecialchars($patient['patient_name']); ?></h4>
                                        <p>Service Time: <?php echo $patient['service_time_minutes'] ?? 0; ?> min</p>
                                    </div>
                                    <div class="patient-card-actions">
                                        <div class="action-group">
                                            <!-- Complete Triage -->
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="complete_current">
                                                <input type="hidden" name="patient_id" value="<?php echo $patient['patient_id']; ?>">
                                                <button type="submit" class="btn btn-success btn-sm" 
                                                        onclick="return confirm('Complete triage?')">
                                                    <i class="fas fa-check"></i> Complete
                                                </button>
                                            </form>
                                            
                                            <!-- Transfer Dropdown -->
                                            <div class="transfer-dropdown">
                                                <button type="button" class="btn btn-primary btn-sm dropdown-toggle" 
                                                        onclick="toggleTransferMenu(this)">
                                                    <i class="fas fa-arrow-right"></i> Transfer
                                                </button>
                                                <div class="transfer-menu">
                                                    <?php foreach ($STATION_CONFIG['next_stations'] as $key => $station_info): ?>
                                                        <form method="POST" style="display: block;">
                                                            <input type="hidden" name="action" value="transfer_to_<?php echo $key; ?>">
                                                            <input type="hidden" name="patient_id" value="<?php echo $patient['patient_id']; ?>">
                                                            <button type="submit" class="transfer-option" 
                                                                    onclick="return confirm('Transfer to <?php echo $station_info['name']; ?>?')">
                                                                <i class="fas <?php echo $station_info['icon']; ?>"></i>
                                                                <?php echo $station_info['name']; ?>
                                                            </button>
                                                        </form>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            
                                            <!-- Skip Patient -->
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="skip_patient">
                                                <input type="hidden" name="patient_id" value="<?php echo $patient['patient_id']; ?>">
                                                <button type="submit" class="btn btn-warning btn-sm" 
                                                        onclick="return confirm('Skip this patient?')">
                                                    <i class="fas fa-forward"></i> Skip
                                                </button>
                                            </form>
                                        </div>
                                        
                                        <!-- Special Triage Actions -->
                                        <div class="special-actions">
                                            <button type="button" class="btn btn-info btn-sm" 
                                                    onclick="openVitalsModal(<?php echo $patient['patient_id']; ?>)">
                                                <i class="fas fa-heartbeat"></i> Vitals
                                            </button>
                                            <button type="button" class="btn btn-secondary btn-sm" 
                                                    onclick="viewPatientHistory(<?php echo $patient['patient_id']; ?>)">
                                                <i class="fas fa-history"></i> History
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-user-clock"></i>
                            <p>No patients in assessment</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Completed Queue Section -->
            <div class="queue-section completed-queue">
                <div class="queue-header">
                    <h3>
                        <i class="fas fa-check-circle"></i>
                        Completed Today (<?php echo count($completedQueue); ?> patients)
                    </h3>
                </div>
                <div class="queue-content">
                    <?php if (!empty($completedQueue)): ?>
                        <div class="patient-cards">
                            <?php foreach (array_slice($completedQueue, -10) as $patient): // Show last 10 completed ?>
                                <div class="patient-card completed" data-patient-id="<?php echo $patient['patient_id']; ?>">
                                    <div class="patient-card-header">
                                        <div class="queue-number"><?php echo htmlspecialchars($patient['queue_number']); ?></div>
                                        <span class="status-badge-queue status-completed">Completed</span>
                                    </div>
                                    <div class="patient-card-content">
                                        <h4><?php echo htmlspecialchars($patient['patient_name']); ?></h4>
                                        <p>Completed: <?php echo date('H:i', strtotime($patient['completed_at'] ?? 'now')); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No patients completed yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Skipped Queue Section -->
            <div class="queue-section skipped-queue">
                <div class="queue-header">
                    <h3>
                        <i class="fas fa-forward"></i>
                        Skipped Patients (<?php echo count($skippedQueue); ?> patients)
                    </h3>
                    <div class="queue-actions">
                        <?php if (!empty($skippedQueue)): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="recall_patient">
                                <button type="submit" class="btn btn-secondary btn-sm">
                                    <i class="fas fa-undo"></i>
                                    Recall First
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="queue-content">
                    <?php if (!empty($skippedQueue)): ?>
                        <div class="patient-cards">
                            <?php foreach ($skippedQueue as $patient): ?>
                                <div class="patient-card skipped" data-patient-id="<?php echo $patient['patient_id']; ?>">
                                    <div class="patient-card-header">
                                        <div class="queue-number"><?php echo htmlspecialchars($patient['queue_number']); ?></div>
                                        <span class="status-badge-queue status-skipped">Skipped</span>
                                    </div>
                                    <div class="patient-card-content">
                                        <h4><?php echo htmlspecialchars($patient['patient_name']); ?></h4>
                                        <p>Skipped: <?php echo date('H:i', strtotime($patient['skipped_at'] ?? 'now')); ?></p>
                                    </div>
                                    <div class="patient-card-actions">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="recall_specific_patient">
                                            <input type="hidden" name="patient_id" value="<?php echo $patient['patient_id']; ?>">
                                            <input type="hidden" name="queue_entry_id" value="<?php echo $patient['queue_entry_id']; ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm" 
                                                    onclick="return confirm('Recall this patient?')">
                                                <i class="fas fa-undo"></i> Recall
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-forward"></i>
                            <p>No skipped patients</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    </div>

    <!-- JavaScript -->
    <script>
        // Transfer dropdown functionality
        function toggleTransferMenu(button) {
            const menu = button.nextElementSibling;
            const isActive = menu.classList.contains('active');
            
            // Close all open menus
            document.querySelectorAll('.transfer-menu').forEach(m => m.classList.remove('active'));
            
            // Toggle current menu
            if (!isActive) {
                menu.classList.add('active');
            }
        }

        // Close transfer menus when clicking outside
        document.addEventListener('click', function(event) {
            if (!event.target.closest('.transfer-dropdown')) {
                document.querySelectorAll('.transfer-menu').forEach(menu => {
                    menu.classList.remove('active');
                });
            }
        });

        // Triage-specific functions
        function openVitalsModal(patientId) {
            if (patientId) {
                window.open(`../clinical/vital_signs.php?patient_id=${patientId}`, '_blank');
            } else {
                alert('No patient ID provided.');
            }
        }

        function viewPatientHistory(patientId) {
            window.open(`../medical-records/patient_profile.php?patient_id=${patientId}`, '_blank');
        }

        // Emergency patient alert system
        document.addEventListener('DOMContentLoaded', function() {
            const emergencyPatients = document.querySelectorAll('.priority-emergency');
            if (emergencyPatients.length > 0) {
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-error';
                alertDiv.innerHTML = `
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Emergency Patient Alert:</strong> ${emergencyPatients.length} emergency priority patient(s) in queue.
                `;
                document.querySelector('.content-wrapper').insertBefore(alertDiv, document.querySelector('.station-header').nextSibling);
            }
        });
        
        // Auto-refresh every 30 seconds (but pause if transfer menu is open)
        setInterval(function() {
            const hasOpenMenus = document.querySelector('.transfer-menu.active');
            if (!hasOpenMenus) {
                location.reload();
            }
        }, 30000);

        // Keyboard shortcuts for efficiency
        document.addEventListener('keydown', function(event) {
            // Only activate shortcuts if no input is focused
            if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') {
                return;
            }
            
            // Alt + N: Call Next Patient
            if (event.altKey && event.key === 'n') {
                const callNextForm = document.querySelector('form input[value="call_next"]');
                if (callNextForm) {
                    callNextForm.closest('form').submit();
                }
            }
            
            // Alt + R: Recall First Skipped Patient
            if (event.altKey && event.key === 'r') {
                const recallForm = document.querySelector('form input[value="recall_patient"]');
                if (recallForm) {
                    recallForm.closest('form').submit();
                }
            }
        });

        // Show success/error messages for 5 seconds with fade animation
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.3s ease';
                    alert.style.opacity = '0';
                    setTimeout(function() {
                        alert.remove();
                    }, 300);
                }, 5000);
            });
        });

        // Patient card hover effects
        document.addEventListener('DOMContentLoaded', function() {
            const patientCards = document.querySelectorAll('.patient-card');
            patientCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-3px)';
                    this.style.boxShadow = '0 10px 25px rgba(0, 0, 0, 0.15)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = '0 1px 3px rgba(0, 0, 0, 0.1)';
                });
            });
        });
    </script>
</body>
</html>