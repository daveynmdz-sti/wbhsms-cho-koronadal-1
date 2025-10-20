<?php
ob_start(); // Start output buffering to prevent header issues

/**
 * Billing Station Interface
 * Purpose: Dedicated billing station interface for cashiers to manage patient billing and billing queue
 * Layout: 7-div grid system with comprehensive queue management functionality
 */

// Include employee session configuration
$root_path = dirname(dirname(dirname(__FILE__)));
require_once $root_path . '/config/session/employee_session.php';

// Check if user is logged in
if (!is_employee_logged_in()) {
    header("Location: ../management/auth/employee_login.php");
    exit();
}

// Include database connection and queue management service
require_once $root_path . '/config/db.php';
require_once $root_path . '/utils/queue_management_service.php';
require_once $root_path . '/utils/patient_flow_validator.php';
require_once $root_path . '/utils/queue_code_formatter.php';

$employee_id = get_employee_session('employee_id');
$employee_role = get_employee_session('role');
$message = '';
$error = '';

// Initialize queue management service and patient flow validator
$queueService = new QueueManagementService($pdo);
$flowValidator = new PatientFlowValidator($pdo);

// Check if role is authorized for billing operations
$allowed_roles = ['Cashier', 'Admin', 'Nurse'];
if (!in_array($employee_role, $allowed_roles)) {
    header("Location: ../management/" . strtolower($employee_role) . "/dashboard.php");
    exit();
}

// Get billing station assignment for the current employee
$current_date = date('Y-m-d');
$billing_station = null;
$can_manage_queue = false;

// Check if employee is assigned to a billing station today
$assignment_query = "SELECT sch.*, s.station_name, s.station_type 
                     FROM assignment_schedules sch 
                     JOIN stations s ON sch.station_id = s.station_id 
                     WHERE sch.employee_id = ? 
                     AND s.station_type = 'billing'
                     AND sch.schedule_date = ?";

$stmt = $pdo->prepare($assignment_query);
$stmt->execute([$employee_id, $current_date]);
$billing_station = $stmt->fetch(PDO::FETCH_ASSOC);

// If no assignment found, get available billing stations for admin role
$available_billing_stations = [];

// Get all available billing stations
$stations_query = "SELECT s.station_id, s.station_name, s.description 
                   FROM stations s 
                   WHERE s.station_type = 'billing' AND s.is_active = 1
                   ORDER BY s.station_name";

$stmt = $pdo->prepare($stations_query);
$stmt->execute();
$available_billing_stations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Admin can select any billing station, others use their assigned station
if ($employee_role === 'Admin' && !$billing_station) {
    // Admin can use any available station
    if (!empty($available_billing_stations)) {
        // Use the first available station as default for admin
        foreach ($available_billing_stations as $station) {
            if ($station['station_id']) {
                $billing_station = $station;
                $billing_station['assignment_id'] = null;
                $billing_station['employee_id'] = $employee_id;
                break;
            }
        }
    }
    
    // Allow queue management for admins
    if (!$billing_station && !empty($available_billing_stations)) {
        $billing_station = $available_billing_stations[0];
        $billing_station['assignment_id'] = null;
        $billing_station['employee_id'] = $employee_id;
    }
}

$can_manage_queue = ($billing_station !== null || $employee_role === 'Admin');

// Handle station selection (for admin users)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_station'])) {
    $selected_station_id = $_POST['station_id'] ?? null;
    
    if ($selected_station_id && $employee_role === 'Admin') {
        foreach ($available_billing_stations as $station) {
            if ($station['station_id'] == $selected_station_id) {
                $billing_station = $station;
                $billing_station['assignment_id'] = null;
                $billing_station['employee_id'] = $employee_id;
                $can_manage_queue = true;
                break;
            }
        }
    }
}

// Get current queue for the billing station
$current_queue = [];
if ($billing_station) {
    try {
        $queue_query = "SELECT q.*, 
                               CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                               p.contact_number,
                               v.visit_date,
                               s.service_name,
                               q.created_at as queue_created,
                               st.station_name
                        FROM queue q
                        JOIN patients p ON q.patient_id = p.patient_id
                        JOIN visits v ON q.visit_id = v.visit_id  
                        JOIN services s ON v.service_id = s.service_id
                        JOIN stations st ON q.station_id = st.station_id
                        WHERE q.station_id = ? 
                        AND q.status IN ('waiting', 'in_progress')
                        ORDER BY q.queue_number ASC, q.created_at ASC";
        
        $stmt = $pdo->prepare($queue_query);
        $stmt->execute([$billing_station['station_id']]);
        $current_queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $error = "Error loading queue: " . $e->getMessage();
    }
}

// Handle queue actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_manage_queue) {
    try {
        if (isset($_POST['call_next'])) {
            // Call next patient
            $result = $queueService->callNextPatient($billing_station['station_id'], $employee_id);
            if ($result['success']) {
                $message = "Called next patient: " . $result['patient_name'];
            } else {
                $error = $result['message'];
            }
        }
        
        if (isset($_POST['complete_current'])) {
            // Complete current patient
            $queue_id = $_POST['queue_id'] ?? null;
            $next_station = $_POST['next_station'] ?? null;
            
            if ($queue_id) {
                $result = $queueService->completeCurrentPatient($queue_id, $employee_id, $next_station);
                if ($result['success']) {
                    $message = "Patient completed successfully";
                } else {
                    $error = $result['message'];
                }
            }
        }
        
        if (isset($_POST['skip_patient'])) {
            // Skip current patient
            $queue_id = $_POST['queue_id'] ?? null;
            $reason = $_POST['skip_reason'] ?? 'No reason provided';
            
            if ($queue_id) {
                $result = $queueService->skipPatient($queue_id, $employee_id, $reason);
                if ($result['success']) {
                    $message = "Patient skipped: " . $reason;
                } else {
                    $error = $result['message'];
                }
            }
        }
        
        if (isset($_POST['recall_patient'])) {
            // Recall a patient
            $patient_id = $_POST['patient_id'] ?? null;
            
            if ($patient_id) {
                $result = $queueService->recallPatient($patient_id, $billing_station['station_id'], $employee_id);
                if ($result['success']) {
                    $message = "Patient recalled successfully";
                } else {
                    $error = $result['message'];
                }
            }
        }
        
        // Refresh queue after any action
        if ($billing_station) {
            $stmt = $pdo->prepare($queue_query);
            $stmt->execute([$billing_station['station_id']]);
            $current_queue = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (Exception $e) {
        $error = "Action failed: " . $e->getMessage();
    }
}

// Get next available stations for routing
$next_stations = [];
try {
    $stations_query = "SELECT station_id, station_name, station_type 
                       FROM stations 
                       WHERE is_active = 1 
                       AND station_type IN ('consultation', 'lab', 'pharmacy', 'document')
                       ORDER BY station_name";
    
    $stmt = $pdo->prepare($stations_query);
    $stmt->execute();
    $next_stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Continue without next stations if query fails
}

// Get billing station statistics
$stats = [
    'total_today' => 0,
    'completed_today' => 0,
    'waiting_count' => 0,
    'average_wait_time' => 0
];

if ($billing_station) {
    try {
        // Today's statistics
        $today_stats_query = "SELECT 
            COUNT(*) as total_today,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_today,
            COUNT(CASE WHEN status IN ('waiting', 'in_progress') THEN 1 END) as waiting_count
            FROM queue 
            WHERE station_id = ? 
            AND DATE(created_at) = CURDATE()";
        
        $stmt = $pdo->prepare($today_stats_query);
        $stmt->execute([$billing_station['station_id']]);
        $today_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($today_stats) {
            $stats = array_merge($stats, $today_stats);
        }
        
        // Calculate average wait time
        $wait_time_query = "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) as avg_wait
                           FROM queue 
                           WHERE station_id = ? 
                           AND status = 'completed'
                           AND DATE(created_at) = CURDATE()";
        
        $stmt = $pdo->prepare($wait_time_query);
        $stmt->execute([$billing_station['station_id']]);
        $wait_result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($wait_result && $wait_result['avg_wait']) {
            $stats['average_wait_time'] = round($wait_result['avg_wait'], 1);
        }
        
    } catch (PDOException $e) {
        // Continue with default stats if queries fail
    }
}
?>