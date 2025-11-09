<?php
// pages/management/admin/staff-management/staff_assignments.php
// Admin interface for assigning staff to stations and managing station status

// Start output buffering to prevent header issues
ob_start();

// Include path resolution and security configuration first (includes CSP headers)
$root_path = dirname(dirname(dirname(dirname(__DIR__))));
require_once $root_path . '/config/production_security.php';
require_once $root_path . '/config/session/employee_session.php';

// Add cache-busting headers to ensure changes reflect immediately
if (!headers_sent()) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    // Disable Cloudflare's analytics tracking to prevent beacon errors
    header('CF-Analytics: false');
}

// If user is not logged in, bounce to login
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    // Clean output buffer if it exists
    if (ob_get_level()) {
        ob_end_clean();
    }
    error_log('Redirecting to employee_login (absolute path) from ' . __FILE__ . ' URI=' . ($_SERVER['REQUEST_URI'] ?? ''));
    header('Location: /pages/management/auth/employee_login.php');
    exit();
}

// Check if role is authorized for admin functions
if (strtolower($_SESSION['role']) !== 'admin') {
    // Clean output buffer if it exists
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Location: ../dashboard.php');
    exit();
}

require_once $root_path . '/config/db.php';
require_once $root_path . '/config/auth_helpers.php';
require_once $root_path . '/utils/queue_management_service.php';

// Simple input sanitization function
if (!function_exists('sanitize_input')) {
    function sanitize_input($input) {
        if (is_string($input)) {
            return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
        }
        return $input;
    }
}

// Initialize queue management service with error handling
try {
    $queueService = new QueueManagementService($pdo);
} catch (Exception $e) {
    error_log('Queue service initialization error in staff_assignments.php: ' . $e->getMessage());
    // Clean output buffer if it exists
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Location: ../dashboard.php?error=service_unavailable');
    exit();
}

// Validate and sanitize date input
$date = sanitize_input($_GET['date'] ?? '') ?: date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}
$message = '';
$error = '';

// Handle assignment form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['assign_employee'])) {
        $employee_id = intval($_POST['employee_id']);
        $station_id = intval($_POST['station_id']);
        $start_date = $_POST['start_date'] ?? ($_POST['assigned_date'] ?? $date);
        $assignment_type = $_POST['assignment_type'] ?? 'permanent';
        $end_date = $_POST['end_date'] ?? null;
        $shift_start = $_POST['shift_start'] ?? '08:00:00';
        $shift_end = $_POST['shift_end'] ?? '17:00:00';
        $assigned_by = $_SESSION['employee_id'] ?? null;
        
        // Validate required fields
        if ($employee_id <= 0) {
            $error = "Please select an employee to assign.";
        } elseif ($station_id <= 0) {
            $error = "Invalid station selected.";
        } elseif (empty($start_date)) {
            $error = "Start date is required.";
        } elseif (!$assigned_by) {
            $error = "Session error: Not properly logged in.";
        } else {
            // Use assignment method
            $result = $queueService->assignEmployeeToStation(
                $employee_id, $station_id, $start_date, $assignment_type, 
                $shift_start, $shift_end, $assigned_by, $end_date
            );
            
            if ($result['success']) {
                $message = $result['message'];
            } else {
                $error = $result['error'];
            }
        }

    } elseif (isset($_POST['remove_assignment'])) {
        $station_id = intval($_POST['station_id']);
        $removal_date = $_POST['removal_date'] ?? ($_POST['assigned_date'] ?? $date);
        $removal_type = $_POST['removal_type'] ?? 'end_assignment';
        $performed_by = $_SESSION['employee_id'];
        
        $result = $queueService->removeEmployeeAssignment($station_id, $removal_date, $removal_type, $performed_by);
        
        if ($result['success']) {
            $message = $result['message'];
        } else {
            $error = $result['error'];
        }
        
    } elseif (isset($_POST['reassign_employee'])) {
        $station_id = intval($_POST['station_id']);
        $new_employee_id = intval($_POST['new_employee_id']);
        $reassign_date = $_POST['reassign_date'] ?? ($_POST['assigned_date'] ?? $date);
        $assigned_by = $_SESSION['employee_id'];
        
        $result = $queueService->reassignStation($station_id, $new_employee_id, $reassign_date, $assigned_by);
        
        if ($result['success']) {
            $message = $result['message'];
        } else {
            $error = $result['error'];
        }
        
    } elseif (isset($_POST['toggle_station'])) {
        $station_id = intval($_POST['station_id']);
        $is_active = intval($_POST['is_active']);
        
        if ($queueService->toggleStationStatus($station_id, $is_active)) {
            $message = $is_active ? 'Station activated successfully.' : 'Station deactivated successfully.';
        } else {
            $error = 'Failed to update station status.';
        }
    }
    
    // Redirect to prevent form resubmission only if there's a success message
    if ($message && empty($error)) {
        // Clean output buffer if it exists
        if (ob_get_level()) {
            ob_end_clean();
        }
        header('Location: staff_assignments.php?date=' . urlencode($date));
        exit();
    }
}

// Get stations with assignments
$stations = $queueService->getAllStationsWithAssignments($date);

// Debug information (remove in production)
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    echo "<div style='background-color: #e3f2fd; padding: 15px; margin: 20px 0; border-radius: 8px; border: 1px solid #2196f3;'>";
    echo "<h4>🐛 Debug Information</h4>";
    echo "<p><strong>Retrieved stations:</strong> " . count($stations) . " for date: $date</p>";
    
    if (empty($stations)) {
        echo "<p style='color: #d32f2f;'><strong>Issue:</strong> No stations found. Testing alternative queries...</p>";
        
        // Test 1: Simple station count
        try {
            $stationCount = $pdo->query("SELECT COUNT(*) FROM stations")->fetchColumn();
            echo "<p><strong>Total stations in database:</strong> $stationCount</p>";
            
            if ($stationCount > 0) {
                // Test 2: Check facility_id
                $facilityCheck = $pdo->query("SHOW COLUMNS FROM stations LIKE 'facility_id'")->fetch();
                if ($facilityCheck) {
                    $facilityValues = $pdo->query("SELECT DISTINCT facility_id FROM stations")->fetchAll(PDO::FETCH_COLUMN);
                    echo "<p><strong>Available facility_ids:</strong> " . implode(', ', $facilityValues) . "</p>";
                } else {
                    echo "<p><strong>facility_id column:</strong> Not found</p>";
                }
                
                // Test 3: Simple stations without conditions
                $simpleStations = $pdo->query("SELECT station_id, station_name, station_type FROM stations LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
                echo "<p><strong>Sample stations:</strong></p>";
                echo "<ul>";
                foreach ($simpleStations as $station) {
                    echo "<li>ID: {$station['station_id']}, Name: {$station['station_name']}, Type: {$station['station_type']}</li>";
                }
                echo "</ul>";
            }
        } catch (Exception $e) {
            echo "<p style='color: #d32f2f;'><strong>Debug error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    } else {
        echo "<p style='color: #2e7d32;'><strong>Success:</strong> Stations loaded correctly!</p>";
        echo "<p><strong>First few stations:</strong></p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 4px; font-size: 12px;'>";
        print_r(array_slice($stations, 0, 3));
        echo "</pre>";
    }
    echo "</div>";
}

// Fallback: If no stations found, try a simplified query
if (empty($stations)) {
    try {
        // Simple fallback query without date conditions
        $fallbackStmt = $pdo->query("
            SELECT 
                s.station_id,
                s.station_name,
                s.station_type,
                COALESCE(s.station_number, 1) as station_number,
                COALESCE(s.is_active, 1) as is_active,
                COALESCE(s.is_open, 1) as is_open,
                srv.name as service_name,
                srv.service_id,
                asch.schedule_id,
                asch.employee_id,
                asch.start_date,
                asch.end_date,
                asch.assignment_type,
                asch.shift_start_time,
                asch.shift_end_time,
                asch.is_active as assignment_status,
                e.first_name,
                e.last_name,
                e.employee_number,
                CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                r.role_name as employee_role
            FROM stations s
            LEFT JOIN services srv ON s.service_id = srv.service_id
            LEFT JOIN assignment_schedules asch ON s.station_id = asch.station_id AND asch.is_active = 1
            LEFT JOIN employees e ON asch.employee_id = e.employee_id
            LEFT JOIN roles r ON e.role_id = r.role_id
            WHERE s.is_active = 1
            ORDER BY s.station_id
        ");
        $stations = $fallbackStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($stations)) {
            $message = "Using fallback query - found " . count($stations) . " stations. Date filtering may have been the issue.";
        }
    } catch (Exception $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// Get all employees for assignment dropdown
$employees = $queueService->getActiveEmployees();

// Handle AJAX request for assignment history
if (isset($_GET['ajax_history']) && $_GET['ajax_history'] == '1') {
    try {
        // Log the request for debugging
        error_log("Assignment History Request: User ID " . ($_SESSION['employee_id'] ?? 'unknown') . " from IP " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        
        // Verify PDO connection is available
        if (!$pdo) {
            throw new Exception('Database connection not available');
        }
        
        // Test basic connectivity
        $testStmt = $pdo->query("SELECT 1");
        if (!$testStmt) {
            throw new Exception('Database connection test failed');
        }
        
        $historyStmt = $pdo->prepare("
            SELECT 
                al.*, 
                CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                s.station_name,
                CONCAT(p.first_name, ' ', p.last_name) as performed_by_name
            FROM assignment_logs al
            LEFT JOIN employees e ON al.employee_id = e.employee_id
            LEFT JOIN stations s ON al.station_id = s.station_id
            LEFT JOIN employees p ON al.performed_by = p.employee_id
            ORDER BY al.created_at DESC 
            LIMIT 20
        ");
        $historyStmt->execute();
        $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Log the result count
        error_log("Assignment History Query Result: Found " . count($history) . " records");
        
        if (!empty($history)) {
            echo '<div style="max-height: 400px; overflow-y: auto;">';
            echo '<table style="width: 100%; border-collapse: collapse; font-size: 14px;">';
            echo '<thead style="position: sticky; top: 0; background-color: #f8f9fa; z-index: 10;">';
            echo '<tr>';
            echo '<th style="padding: 10px; border: 1px solid #dee2e6; background-color: #e9ecef;">Date & Time</th>';
            echo '<th style="padding: 10px; border: 1px solid #dee2e6; background-color: #e9ecef;">Action</th>';
            echo '<th style="padding: 10px; border: 1px solid #dee2e6; background-color: #e9ecef;">Station</th>';
            echo '<th style="padding: 10px; border: 1px solid #dee2e6; background-color: #e9ecef;">Employee</th>';
            echo '<th style="padding: 10px; border: 1px solid #dee2e6; background-color: #e9ecef;">Performed By</th>';
            echo '<th style="padding: 10px; border: 1px solid #dee2e6; background-color: #e9ecef;">Notes</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach ($history as $log) {
                $actionColor = match($log['action_type']) {
                    'created' => '#28a745',
                    'reassigned' => '#ffc107', 
                    'ended' => '#dc3545',
                    'cleanup' => '#6f42c1',
                    default => '#6c757d'
                };
                
                echo '<tr>';
                echo '<td style="padding: 8px; border: 1px solid #dee2e6; font-size: 12px;">' . date('M j, Y g:i A', strtotime($log['created_at'])) . '</td>';
                echo '<td style="padding: 8px; border: 1px solid #dee2e6;">';
                echo '<span class="badge" style="background-color: ' . $actionColor . '; color: white; padding: 4px 8px; border-radius: 4px; font-size: 11px;">';
                echo ucfirst($log['action_type']);
                echo '</span>';
                echo '</td>';
                echo '<td style="padding: 8px; border: 1px solid #dee2e6;">' . htmlspecialchars($log['station_name'] ?: 'N/A') . '</td>';
                echo '<td style="padding: 8px; border: 1px solid #dee2e6;">' . htmlspecialchars($log['employee_name'] ?: 'N/A') . '</td>';
                echo '<td style="padding: 8px; border: 1px solid #dee2e6;">' . htmlspecialchars($log['performed_by_name'] ?: 'System') . '</td>';
                echo '<td style="padding: 8px; border: 1px solid #dee2e6; font-size: 12px; max-width: 200px; word-wrap: break-word;">' . htmlspecialchars($log['notes'] ?: 'No additional notes') . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
            echo '</div>';
            echo '<div style="margin-top: 15px; padding: 10px; background-color: #e3f2fd; border-radius: 5px; font-size: 12px; color: #1976d2;">';
            echo '<i class="fas fa-info-circle"></i> Showing last 20 assignment changes. ';
            echo 'Total records found: ' . count($history);
            echo '</div>';
        } else {
            echo '<div class="alert" style="background-color: #fff3cd; color: #856404; border-left: 4px solid #ffc107; padding: 15px;">';
            echo '<i class="fas fa-info-circle"></i> No assignment history found for this facility.';
            echo '<br><small>This may be because no staff assignments have been made yet or the assignment_logs table is empty.</small>';
            echo '</div>';
        }
    } catch (Exception $e) {
        // Log the detailed error
        error_log('Assignment History Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());
        
        echo '<div class="alert alert-danger">';
        echo '<i class="fas fa-exclamation-triangle"></i> Error loading assignment history: ' . htmlspecialchars($e->getMessage());
        echo '<br><small>Please check the error logs or contact the system administrator.</small>';
        echo '</div>';
    }
    exit(); // Stop execution for AJAX request
}

// Create role-to-employees mapping for JavaScript
$employeesByRole = [];
foreach ($employees as $emp) {
    $role = strtolower($emp['role_name']);
    if (!isset($employeesByRole[$role])) {
        $employeesByRole[$role] = [];
    }
    $employeesByRole[$role][] = $emp;
}

// Set active page for sidebar highlighting
$activePage = 'staff_assignments';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- Prevent caching to ensure changes reflect immediately -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
            <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Station Staff Assignments | CHO Koronadal</title>
    <!-- CSS Files -->
    <link rel="stylesheet" href="../../../../assets/css/sidebar.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../../../../assets/css/dashboard.css?v=<?php echo time(); ?>">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* Additional styles for staff assignments management - MATCHING PATIENT RECORDS TEMPLATE */
        :root {
            --primary: #0077b6;
            --primary-dark: #03045e;
            --secondary: #6c757d;
            --success: #2d6a4f;
            --info: #17a2b8;
            --warning: #ffc107;
            --danger: #d00000;
            --light: #f8f9fa;
            --border: #dee2e6;
            --shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            --shadow-lg: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            --border-radius: 0.5rem;
            --border-radius-lg: 1rem;
            --transition: all 0.3s ease;
        }
        
        .loader {
            border: 5px solid rgba(240, 240, 240, 0.5);
            border-radius: 50%;
            border-top: 5px solid var(--primary);
            width: 30px;
            height: 30px;
            animation: spin 1.5s linear infinite;
            margin: 0 auto;
            display: none;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .table-responsive {
            overflow-x: auto;
            border-radius: var(--border-radius);
            margin-top: 10px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            box-shadow: var(--shadow);
        }
        
        table th {
            background: linear-gradient(135deg, #0077b6, #03045e);
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 500;
            position: sticky;
            top: 0;
            z-index: 10;
            cursor: pointer;
            user-select: none;
        }
        
        table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        
        table tr:hover {
            background-color: rgba(240, 247, 255, 0.6);
            transition: background-color 0.2s;
        }
        
        table tr:last-child td {
            border-bottom: none;
        }
        
        .action-btn {
            margin-right: 5px;
            padding: 8px 15px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            color: white;
            font-size: 14px;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.15);
        }
        
        .btn-info {
            background: linear-gradient(135deg, #0096c7, #0077b6);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #48cae4, #0096c7);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #ffba08, #faa307);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #adb5bd, #6c757d);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #52b788, #2d6a4f);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ef476f, #d00000);
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            color: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .bg-success {
            background: linear-gradient(135deg, #52b788, #2d6a4f);
        }
        
        .bg-danger {
            background: linear-gradient(135deg, #ef476f, #d00000);
        }
        
        .bg-warning {
            background: linear-gradient(135deg, #ffba08, #faa307);
        }
        
        .bg-secondary {
            background: linear-gradient(135deg, #adb5bd, #6c757d);
        }
        
        .bg-primary {
            background: linear-gradient(135deg, #48cae4, #0096c7);
        }

        /* Content header styling */
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .page-title {
            color: var(--primary-dark);
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        
        .modal.show {
            display: block !important;
        }
        
        .modal-dialog {
            max-width: 450px;
            margin: 50px auto;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
        }
        
        .modal.show .modal-dialog {
            transform: translateY(0);
        }
        
        .modal-content {
            background-color: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }
        
        .modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: var(--primary-dark);
            color: white;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .btn-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: white;
            transition: color 0.2s ease;
        }
        
        .btn-close:hover {
            color: var(--light);
        }

        .station-type-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .station-type-checkin { background-color: #e3f2fd; color: #1976d2; }
        .station-type-triage { background-color: #fff3e0; color: #f57c00; }
        .station-type-consultation { background-color: #f3e5f5; color: #7b1fa2; }
        .station-type-lab { background-color: #e8f5e8; color: #388e3c; }
        .station-type-pharmacy { background-color: #fce4ec; color: #c2185b; }
        .station-type-billing { background-color: #f1f8e9; color: #689f38; }

        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left-width: 4px;
            border-left-style: solid;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
            border-left-color: #28a745;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
            border-left-color: #dc3545;
        }
        
        .alert i {
            margin-right: 5px;
        }

        /* Form inputs */
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e2e8f0;
            border-radius: var(--border-radius);
            margin-bottom: 12px;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            font-size: 14px;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.2);
            outline: none;
        }
        
        .form-select {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e2e8f0;
            border-radius: var(--border-radius);
            margin-bottom: 12px;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
            font-size: 14px;
        }
        
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.2);
            outline: none;
        }
        
        .input-group {
            display: flex;
            position: relative;
        }
        
        .input-group-text {
            padding: 10px 15px;
            background-color: #f8f9fa;
            border: 1px solid #e2e8f0;
            border-right: none;
            border-radius: var(--border-radius) 0 0 var(--border-radius);
            display: flex;
            align-items: center;
            color: #64748b;
        }
        
        .input-group .form-control {
            border-radius: 0 var(--border-radius) var(--border-radius) 0;
            margin-bottom: 0;
            flex: 1;
        }

        /* Breadcrumb styling */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #666;
        }

        .breadcrumb a {
            color: #0077b6;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* Page header styling */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .page-header h1 {
            color: #0077b6;
            margin: 0;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-header h1 i {
            color: #0077b6;
        }

        /* Total count badges styling */
        .total-count {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-start;
        }

        .total-count .badge {
            min-width: 120px;
            padding: 8px 16px;
            font-size: 0.9rem;
            font-weight: 600;
            text-align: center;
            white-space: nowrap;
            border-radius: 25px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .total-count .badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* Section header styling */
        .section-header {
            padding: 0 0 15px 0;
            margin-bottom: 15px;
            border-bottom: 1px solid rgba(0, 119, 182, 0.2);
        }
        
        .section-header h4 {
            margin: 0;
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
        }
        
        .section-header h4 i {
            color: var(--primary);
            margin-right: 8px;
        }

        /* Responsive grid */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin-right: -15px;
            margin-left: -15px;
        }
        
        .col-12 {
            flex: 0 0 100%;
            max-width: 100%;
            padding: 0 15px;
        }
        
        .col-md-4 {
            flex: 0 0 33.333333%;
            max-width: 33.333333%;
            padding: 0 15px;
        }
        
        .col-md-3 {
            flex: 0 0 25%;
            max-width: 25%;
            padding: 0 15px;
        }
        
        .col-md-2 {
            flex: 0 0 16.666667%;
            max-width: 16.666667%;
            padding: 0 15px;
        }
        
        @media (max-width: 768px) {
            .col-md-4, .col-md-3, .col-md-2 {
                flex: 0 0 100%;
                max-width: 100%;
            }
        }

        /* Utility classes */
        .d-flex {
            display: flex;
        }
        
        .me-2 {
            margin-right: 10px;
        }
        
        .mb-2 {
            margin-bottom: 10px;
        }
        
        .mt-4 {
            margin-top: 20px;
        }
        
        .justify-content-center {
            justify-content: center;
        }
        
        .text-center {
            text-align: center;
        }
        
        .text-muted {
            color: #6c757d;
            font-style: italic;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            align-items: center;
            flex-wrap: wrap;
        }

        .action-buttons .text-muted {
            white-space: nowrap;
            font-style: italic;
            padding: 4px 8px;
            border-radius: 4px;
            background-color: #f8f9fa;
        }

        /* Mobile responsive styling */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .total-count {
                width: 100%;
                justify-content: flex-start;
                gap: 0.75rem;
            }

            .total-count .badge {
                min-width: 100px;
                font-size: 0.8rem;
                padding: 6px 12px;
            }
        }

        @media (max-width: 480px) {
            .total-count {
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }

            .total-count .badge {
                width: 100%;
                min-width: auto;
                text-align: center;
            }
        }

        /* Inactive station styling */
        .inactive-station {
            opacity: 0.6;
            background-color: #f8f9fa !important;
        }

        .inactive-station .station-name {
            color: #6c757d;
        }

        .inactive-label {
            background-color: #dc3545;
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
            margin-left: 8px;
        }

        /* Station Toggle Confirmation Modal Styling */
        #toggleConfirmModal .modal-dialog {
            max-width: 400px;
        }

        #toggleConfirmModal .modal-body {
            text-align: center;
            padding: 30px 20px;
        }

        #toggleConfirmModal .modal-body i {
            font-size: 48px;
            margin-bottom: 15px;
            display: block;
        }

        #toggleConfirmModal .modal-body p {
            font-size: 16px;
            margin-bottom: 10px;
            line-height: 1.5;
        }

        #toggleConfirmModal .modal-footer {
            justify-content: center;
            gap: 10px;
            border-top: 1px solid #dee2e6;
            padding: 20px;
        }

        #toggleConfirmModal .action-btn {
            min-width: 120px;
            padding: 10px 20px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <!-- Include sidebar -->
    <?php include '../../../../includes/sidebar_admin.php'; ?>
    
    <div class="homepage">
        <div class="main-content">
            <!-- Breadcrumb Navigation -->
            <div class="breadcrumb" style="margin-top: 50px;">
                <a href="../dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <span>Station Assignments</span>
            </div>

            <div class="page-header">
                <h1><i class="fas fa-users-cog"></i> Station Staff Assignments</h1>
            </div>
            
            <!-- Date Selection Section -->
            <div class="card-container">
                <div class="section-header">
                    <h4><i class="fas fa-calendar-alt"></i> Date Selection</h4>
                </div>
                <div class="row">
                    <div class="col-12">
                        <div class="alert" style="background-color: #e3f2fd; color: #1976d2; border-left-color: #2196f3; margin-bottom: 15px;">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Simple Assignment:</strong> Use "Only this Day" for temporary assignments or "Permanent Assignment" for ongoing daily assignments. Permanent assignments will continue every day until you remove them.
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <form method="get" class="d-flex">
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                                <input type="date" name="date" id="date" value="<?php echo htmlspecialchars($date); ?>" class="form-control">
                            </div>
                            <button type="submit" class="action-btn btn-primary" style="margin-left: 10px;">
                                <i class="fas fa-search"></i> View
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success" style="background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); border: 1px solid #28a745; border-radius: 8px; padding: 20px; margin-bottom: 25px; box-shadow: 0 4px 12px rgba(40, 167, 69, 0.2); animation: slideInFromTop 0.5s ease-out;">
                    <div style="display: flex; align-items: center;">
                        <i class="fas fa-check-circle" style="color: #28a745; font-size: 24px; margin-right: 15px;"></i>
                        <div>
                            <strong style="color: #155724; font-size: 16px;">Success!</strong>
                            <div style="color: #155724; margin-top: 5px;"><?php echo htmlspecialchars($message); ?></div>
                        </div>
                    </div>
                </div>
                <script>
                    // Auto-hide success message after 5 seconds
                    setTimeout(function() {
                        const successAlert = document.querySelector('.alert-success');
                        if (successAlert) {
                            successAlert.style.transition = 'opacity 0.5s ease-out';
                            successAlert.style.opacity = '0';
                            setTimeout(() => successAlert.remove(), 500);
                        }
                    }, 5000);
                </script>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger" style="border-left: 4px solid #dc3545; padding: 15px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(220, 53, 69, 0.2);">
                    <div style="display: flex; align-items: center; margin-bottom: 10px;">
                        <i class="fas fa-exclamation-triangle" style="color: #dc3545; font-size: 20px; margin-right: 10px;"></i>
                        <strong style="color: #721c24;">Assignment Error!</strong>
                    </div>
                    <div style="color: #721c24; line-height: 1.5;">
                        <?php echo nl2br(htmlspecialchars($error)); ?>
                    </div>
                    <?php if (strpos($error, 'already assigned') !== false): ?>
                        <div style="margin-top: 15px; padding: 10px; background-color: #fff3cd; border-radius: 5px; border-left: 4px solid #ffc107;">
                            <strong style="color: #856404;">� Tip:</strong><br>
                            <span style="color: #856404;">This error occurs when trying to assign an employee who is already assigned to another station. Use the "Reassign" button instead, or remove their current assignment first.</span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Assignment Summary -->
            <div class="card-container" style="margin-bottom: 20px;">
                <div class="section-header">
                    <h4><i class="fas fa-chart-bar"></i> Assignment Summary for <?php echo date('F j, Y', strtotime($date)); ?></h4>
                </div>
                <div class="row">
                    <?php
                    $totalStations = count($stations);
                    $assignedStations = count(array_filter($stations, function($s) { return !empty($s['employee_id']); }));
                    $unassignedStations = $totalStations - $assignedStations;
                    $activeStations = count(array_filter($stations, function($s) { return $s['is_active']; }));
                    ?>
                    <div class="col-md-3">
                        <div style="background: linear-gradient(135deg, #48cae4, #0096c7); color: white; padding: 20px; border-radius: 10px; text-align: center; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                            <i class="fas fa-building" style="font-size: 24px; margin-bottom: 10px;"></i>
                            <h3 style="margin: 0; font-size: 28px;"><?php echo $totalStations; ?></h3>
                            <p style="margin: 0; opacity: 0.9;">Total Stations</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div style="background: linear-gradient(135deg, #52b788, #2d6a4f); color: white; padding: 20px; border-radius: 10px; text-align: center; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                            <i class="fas fa-user-check" style="font-size: 24px; margin-bottom: 10px;"></i>
                            <h3 style="margin: 0; font-size: 28px;"><?php echo $assignedStations; ?></h3>
                            <p style="margin: 0; opacity: 0.9;">Assigned</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div style="background: linear-gradient(135deg, #ffba08, #faa307); color: white; padding: 20px; border-radius: 10px; text-align: center; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                            <i class="fas fa-user-times" style="font-size: 24px; margin-bottom: 10px;"></i>
                            <h3 style="margin: 0; font-size: 28px;"><?php echo $unassignedStations; ?></h3>
                            <p style="margin: 0; opacity: 0.9;">Unassigned</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div style="background: linear-gradient(135deg, #0077b6, #03045e); color: white; padding: 20px; border-radius: 10px; text-align: center; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                            <i class="fas fa-power-off" style="font-size: 24px; margin-bottom: 10px;"></i>
                            <h3 style="margin: 0; font-size: 28px;"><?php echo $activeStations; ?></h3>
                            <p style="margin: 0; opacity: 0.9;">Active</p>
                        </div>
                    </div>
                </div>
                
                <?php if ($unassignedStations > 0): ?>
                    <div style="margin-top: 15px; padding: 12px; background-color: #fff3cd; border-left: 4px solid #ffc107; border-radius: 5px;">
                        <i class="fas fa-exclamation-triangle" style="color: #856404; margin-right: 8px;"></i>
                        <strong style="color: #856404;">Attention:</strong>
                        <span style="color: #856404;"><?php echo $unassignedStations; ?> station(s) need employee assignments.</span>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Station Assignments Table -->
            <div class="card-container">
                <div class="section-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <h4><i class="fas fa-list"></i> Station Assignments for <?php echo date('F j, Y', strtotime($date)); ?></h4>
                    <button type="button" class="action-btn btn-info" onclick="openHistoryModal()" style="font-size: 14px;">
                        <i class="fas fa-history"></i> Show Assignment History
                    </button>
                </div>
                
                <div class="table-responsive">
                    <table id="stationTable">
                        <thead>
                            <tr>
                                <th>Station</th>
                                <th>Type</th>
                                <th>Employee ID</th>
                                <th>Assigned Employee</th>
                                <th>Role</th>
                                <th>Shift</th>
                                <th>Status</th>
                                <th style="width: 160px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($stations)): ?>
                                <?php foreach ($stations as $station): ?>
                                    <tr class="<?php echo !$station['is_active'] ? 'station-inactive' : ''; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($station['station_name']); ?></strong>
                                            <?php if ($station['station_number'] > 1): ?>
                                                <br><small class="text-muted">#<?php echo $station['station_number']; ?></small>
                                            <?php endif; ?>
                                            <?php if (!$station['is_active']): ?>
                                                <br><small class="text-danger"><i class="fas fa-ban"></i> INACTIVE</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="station-type-badge station-type-<?php echo $station['station_type']; ?>">
                                                <?php echo ucfirst($station['station_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($station['employee_number']): ?>
                                                <span class="badge bg-primary"><?php echo htmlspecialchars($station['employee_number']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($station['employee_name']): ?>
                                                <?php echo htmlspecialchars($station['employee_name']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($station['employee_role']): ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars(format_role_name($station['employee_role'])); ?></span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($station['shift_start_time'] && $station['shift_end_time']): ?>
                                                <?php echo date('g:i A', strtotime($station['shift_start_time'])); ?> - 
                                                <?php echo date('g:i A', strtotime($station['shift_end_time'])); ?>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($station['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                                <?php if (!empty($station['employee_id']) && isset($station['assignment_status']) && !$station['assignment_status']): ?>
                                                    <br><small class="text-muted">
                                                        Assignment: Inactive
                                                    </small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <!-- View Station Dashboard button (always available) -->
                                                <button type="button" class="action-btn btn-info" onclick="window.open('../../../../pages/queueing/station_dashboard.php?station_id=<?php echo $station['station_id']; ?>', '_blank')" title="View Station Dashboard">
                                                    <i class="fas fa-tachometer-alt"></i>
                                                </button>
                                                
                                                <?php if ($station['is_active']): ?>
                                                    <!-- Station is active -->
                                                    <?php if ($station['employee_id']): ?>
                                                        <!-- Station has assigned employee -->
                                                        <button type="button" class="action-btn btn-warning" onclick="openReassignModal(<?php echo $station['station_id']; ?>, '<?php echo htmlspecialchars($station['station_name']); ?>', <?php echo $station['employee_id']; ?>, '<?php echo htmlspecialchars($station['station_type']); ?>')" title="Reassign Employee">
                                                            <i class="fas fa-exchange-alt"></i>
                                                        </button>
                                                        <button type="button" class="action-btn btn-danger" onclick="openRemoveModal(<?php echo $station['station_id']; ?>, '<?php echo htmlspecialchars($station['station_name']); ?>', '<?php echo htmlspecialchars($station['employee_name']); ?>')" title="Remove Assignment">
                                                            <i class="fas fa-user-minus"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <!-- Station has no assigned employee -->
                                                        <button type="button" class="action-btn btn-primary" onclick="openAssignModal(<?php echo $station['station_id']; ?>, '<?php echo htmlspecialchars($station['station_name']); ?>', '<?php echo htmlspecialchars($station['station_type']); ?>')" title="Assign Employee">
                                                            <i class="fas fa-user-plus"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Deactivate station button -->
                                                    <button type="button" onclick="openToggleConfirmModal(<?php echo $station['station_id']; ?>, '<?php echo htmlspecialchars($station['station_name']); ?>', true)" class="action-btn btn-secondary" title="Deactivate Station">
                                                        <i class="fas fa-power-off"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <!-- Station is inactive -->
                                                    <span class="text-muted" style="font-size: 0.8rem; padding: 8px 12px;">
                                                        <i class="fas fa-ban"></i> Station Inactive
                                                    </span>
                                                    
                                                    <!-- Activate station button -->
                                                    <button type="button" onclick="openToggleConfirmModal(<?php echo $station['station_id']; ?>, '<?php echo htmlspecialchars($station['station_name']); ?>', false)" class="action-btn btn-success" title="Activate Station">
                                                        <i class="fas fa-play"></i>
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <!-- Hidden form for toggling (keeping for backward compatibility) -->
                                                <form method="post" style="display: none;" id="toggleForm_<?php echo $station['station_id']; ?>">
                                                    <input type="hidden" name="station_id" value="<?php echo $station['station_id']; ?>">
                                                    <input type="hidden" name="is_active" value="<?php echo $station['is_active'] ? 0 : 1; ?>">
                                                    <input type="hidden" name="assigned_date" value="<?php echo htmlspecialchars($date); ?>">
                                                    <input type="hidden" name="toggle_station" value="1">
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center">
                                        <div style="padding: 30px 0;">
                                            <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #f39c12; margin-bottom: 15px;"></i>
                                            <h5 style="color: #666; margin-bottom: 10px;">No Station Assignments Found</h5>
                                            <p style="color: #999; margin-bottom: 20px;">No station assignments found for <?= $date ?>.</p>
                                            <?php if (!empty($message)): ?>
                                                <div style="background: #e3f2fd; padding: 15px; margin: 10px auto; border-radius: 8px; max-width: 500px; border: 1px solid #2196f3;">
                                                    <i class="fas fa-info-circle" style="color: #2196f3;"></i>
                                                    <span style="color: #1976d2;"><?= htmlspecialchars($message) ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <button onclick="window.location.reload()" class="btn btn-primary" style="margin-right: 10px;">
                                                    <i class="fas fa-sync-alt"></i> Refresh
                                                </button>
                                                <a href="?debug=1" class="btn btn-outline-info">
                                                    <i class="fas fa-bug"></i> Debug Info
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Assign Modal -->
    <div class="modal" id="assignModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h5>Assign Employee to Station</h5>
                        <button type="button" class="btn-close" onclick="closeModal('assignModal')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="station_id" id="assign_station_id">
                        
                        <div class="mb-2">
                            <label>Station:</label>
                            <input type="text" id="assign_station_name" class="form-control" readonly>
                        </div>
                        
                        <div class="mb-2">
                            <label>Employee:</label>
                            <select name="employee_id" class="form-select" required>
                                <option value="">Select Employee...</option>
                                <!-- Options populated dynamically by JavaScript based on station type -->
                            </select>
                        </div>
                        
                        <div class="mb-2">
                            <label>Assignment Type:</label>
                            <select name="assignment_type" class="form-select" onchange="toggleAssignmentFields()">
                                <option value="permanent">Permanent Assignment (Ongoing)</option>
                                <option value="temporary">Temporary Assignment (Fixed Duration)</option>
                            </select>
                        </div>
                        
                        <div class="mb-2">
                            <label>Start Date:</label>
                            <input type="date" name="start_date" value="<?php echo htmlspecialchars($date); ?>" class="form-control" required>
                        </div>
                        
                        <div class="mb-2" id="end_date_field" style="display: none;">
                            <label>End Date (Optional for temporary):</label>
                            <input type="date" name="end_date" class="form-control">
                        </div>
                        
                        <div class="alert" style="background-color: #e3f2fd; color: #1976d2; border-left-color: #2196f3; margin-bottom: 15px; font-size: 13px;">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Efficient System:</strong> One assignment record covers the entire period. Permanent assignments continue indefinitely (no end date). Temporary assignments have a specific end date.
                        </div>
                        
                        <div class="d-flex">
                            <div style="flex: 1; margin-right: 10px;">
                                <label>Shift Start:</label>
                                <input type="time" name="shift_start" value="08:00" class="form-control">
                            </div>
                            <div style="flex: 1;">
                                <label>Shift End:</label>
                                <input type="time" name="shift_end" value="17:00" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="action-btn btn-secondary" onclick="closeModal('assignModal')">Cancel</button>
                        <button type="submit" name="assign_employee" class="action-btn btn-primary">Assign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reassign Modal -->
    <div class="modal" id="reassignModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h5>Reassign Employee to Station</h5>
                        <button type="button" class="btn-close" onclick="closeModal('reassignModal')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="station_id" id="reassign_station_id">
                        
                        <div class="mb-2">
                            <label>Station:</label>
                            <input type="text" id="reassign_station_name" class="form-control" readonly>
                        </div>
                        
                        <div class="mb-2">
                            <label>New Employee:</label>
                            <select name="new_employee_id" class="form-select" required>
                                <option value="">Select Employee...</option>
                                <!-- Options populated dynamically by JavaScript based on station type -->
                            </select>
                        </div>
                        
                        <div class="mb-2">
                            <label>Reassignment Date:</label>
                            <input type="date" name="reassign_date" value="<?php echo htmlspecialchars($date); ?>" class="form-control" required>
                        </div>
                        
                        <div class="alert" style="background-color: #fff3cd; color: #856404; border-left-color: #ffc107; margin-bottom: 15px; font-size: 13px;">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Note:</strong> The current assignment will end the day before the reassignment date, and the new assignment will start from the reassignment date.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="action-btn btn-secondary" onclick="closeModal('reassignModal')">Cancel</button>
                        <button type="submit" name="reassign_employee" class="action-btn btn-warning">Reassign</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Remove Assignment Modal -->
    <div class="modal" id="removeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h5>Remove Employee Assignment</h5>
                        <button type="button" class="btn-close" onclick="closeModal('removeModal')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="station_id" id="remove_station_id">
                        
                        <div class="mb-2">
                            <label>Station:</label>
                            <input type="text" id="remove_station_name" class="form-control" readonly>
                        </div>
                        
                        <div class="mb-2">
                            <label>Current Employee:</label>
                            <input type="text" id="remove_employee_name" class="form-control" readonly>
                        </div>
                        
                        <div class="mb-2">
                            <label>Removal Date:</label>
                            <input type="date" name="removal_date" value="<?php echo htmlspecialchars($date); ?>" class="form-control" required>
                        </div>
                        
                        <div class="mb-2">
                            <label>Removal Type:</label>
                            <select name="removal_type" class="form-select" required>
                                <option value="end_assignment">End Assignment (Set end date)</option>
                                <option value="deactivate">Deactivate Assignment (Keep record but inactive)</option>
                            </select>
                        </div>
                        
                        <div class="alert alert-warning" style="font-size: 13px;">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Note:</strong> "End Assignment" will set the assignment end date to the day before removal date. "Deactivate" will keep the record but mark it inactive.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="action-btn btn-secondary" onclick="closeModal('removeModal')">Cancel</button>
                        <button type="submit" name="remove_assignment" class="action-btn btn-danger">Remove Assignment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Employee data organized by role (with error handling)
        let employeesByRole = {};
        try {
            employeesByRole = <?php echo json_encode($employeesByRole, JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
        } catch (e) {
            console.error('Error parsing employeesByRole:', e);
            employeesByRole = {};
        }
        
        // Format role names to proper title case (JavaScript equivalent of PHP function)
        function formatRoleName(role) {
            if (!role) return '';
            
            // Replace underscores with spaces and convert to title case
            let formatted = role.replace(/_/g, ' ');
            formatted = formatted.toLowerCase().replace(/\b\w/g, l => l.toUpperCase());
            
            // Handle special cases
            const specialCases = {
                'Dho': 'DHO',
                'Bhw': 'BHW'
            };
            
            for (const [search, replace] of Object.entries(specialCases)) {
                formatted = formatted.replace(search, replace);
            }
            
            return formatted;
        }
        
        // Station type to allowed roles mapping (matching stations table)
        const stationRoles = {
            'checkin': ['records_officer', 'nurse'],
            'triage': ['nurse'],
            'billing': ['cashier'],
            'consultation': ['doctor'],
            'lab': ['laboratory_tech'],
            'pharmacy': ['pharmacist'],
            'document': ['records_officer']
        };
        
        function populateEmployeeDropdown(selectElement, stationType, excludeEmployeeId = null) {
            // Debug logging
            console.log('Populating dropdown for station type:', stationType);
            console.log('Available employees by role:', employeesByRole);
            
            // Clear existing options except the first one
            selectElement.innerHTML = '<option value="">Select Employee...</option>';
            
            // Get allowed roles for this station type
            const allowedRoles = stationRoles[stationType] || [];
            console.log('Allowed roles for', stationType, ':', allowedRoles);
            
            let addedCount = 0;
            
            // Add employees from allowed roles
            allowedRoles.forEach(role => {
                if (employeesByRole[role]) {
                    employeesByRole[role].forEach(emp => {
                        // Skip excluded employee (for reassign modal)
                        if (excludeEmployeeId && emp.employee_id == excludeEmployeeId) {
                            return;
                        }
                        
                        const option = document.createElement('option');
                        option.value = emp.employee_id;
                        option.textContent = emp.full_name + ' (' + formatRoleName(emp.role_name) + ')';
                        selectElement.appendChild(option);
                        addedCount++;
                    });
                }
            });
            
            console.log('Added', addedCount, 'employees to dropdown');
            
            if (addedCount === 0) {
                const noOption = document.createElement('option');
                noOption.value = '';
                noOption.textContent = 'No available employees for this station type';
                noOption.disabled = true;
                selectElement.appendChild(noOption);
            }
        }
        
        function openAssignModal(stationId, stationName, stationType) {
            document.getElementById('assign_station_id').value = stationId;
            document.getElementById('assign_station_name').value = stationName;
            
            // Populate employee dropdown with role-specific employees
            const select = document.querySelector('#assignModal select[name="employee_id"]');
            populateEmployeeDropdown(select, stationType);
            
            document.getElementById('assignModal').classList.add('show');
        }
        
        function openReassignModal(stationId, stationName, currentEmployeeId, stationType) {
            document.getElementById('reassign_station_id').value = stationId;
            document.getElementById('reassign_station_name').value = stationName;
            
            // Populate employee dropdown with role-specific employees, excluding current employee
            const select = document.querySelector('#reassignModal select[name="new_employee_id"]');
            populateEmployeeDropdown(select, stationType, currentEmployeeId);
            
            // Add change listener to check conflicts when employee is selected for reassignment
            select.onchange = function() {
                const selectedEmployeeId = this.value;
                if (selectedEmployeeId) {
                    const selectedOption = this.options[this.selectedIndex];
                    const employeeName = selectedOption.textContent;
                    
                    // Check for conflicts (same function, but for reassignment)
                    if (checkEmployeeConflicts(selectedEmployeeId, stationName, '<?php echo $date; ?>', '08:00', '17:00')) {
                        // Show reassignment-specific warning
                        showReassignmentWarning(
                            employeeName,
                            stationName
                        );
                        this.value = ''; // Reset selection
                        return false;
                    }
                }
            };
            
            document.getElementById('reassignModal').classList.add('show');
        }
        
        function openRemoveModal(stationId, stationName, employeeName) {
            document.getElementById('remove_station_id').value = stationId;
            document.getElementById('remove_station_name').value = stationName;
            document.getElementById('remove_employee_name').value = employeeName;
            
            document.getElementById('removeModal').classList.add('show');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        }
        
        // Toggle end date field based on assignment type
        function toggleAssignmentFields() {
            const assignmentType = document.querySelector('#assignModal select[name="assignment_type"]').value;
            const endDateField = document.getElementById('end_date_field');
            
            if (assignmentType === 'temporary') {
                endDateField.style.display = 'block';
            } else {
                endDateField.style.display = 'none';
            }
        }
        
        // Enhanced assignment system using efficient date ranges
        
        // Check for assignment conflicts before showing assign modal
        function checkEmployeeConflicts(employeeId, stationName, startDate, shiftStart, shiftEnd) {
            // Get all current assignments to check for conflicts
            const allAssignments = <?php echo json_encode($stations); ?>;
            
            for (let i = 0; i < allAssignments.length; i++) {
                const assignment = allAssignments[i];
                // Only check for ACTIVE assignments with actual employee IDs
                if (assignment.employee_id == employeeId && 
                    assignment.employee_id != null && 
                    assignment.assignment_status == 1) {  // Check if assignment is active
                    // Found a conflict
                    showAssignmentWarning(
                        assignment.employee_name,
                        assignment.station_name,
                        stationName,
                        assignment.shift_start_time + ' - ' + assignment.shift_end_time
                    );
                    return true; // Conflict found
                }
            }
            return false; // No conflict
        }
        
        // Show assignment conflict warning
        function showAssignmentWarning(employeeName, currentStation, newStation, shiftTime) {
            document.getElementById('warning_employee_name').textContent = employeeName;
            document.getElementById('warning_current_station').textContent = currentStation;
            document.getElementById('warning_new_station').textContent = newStation;
            document.getElementById('warning_shift_time').textContent = shiftTime;
            document.getElementById('assignmentWarningModal').classList.add('show');
        }
        
        // Show reassignment conflict warning
        function showReassignmentWarning(employeeName, targetStation) {
            // Find the employee's current assignment
            const allAssignments = <?php echo json_encode($stations); ?>;
            let currentAssignment = null;
            
            for (let i = 0; i < allAssignments.length; i++) {
                if (allAssignments[i].employee_name === employeeName && allAssignments[i].employee_id != null) {
                    currentAssignment = allAssignments[i];
                    break;
                }
            }
            
            if (currentAssignment) {
                document.getElementById('reassign_warning_employee_name').textContent = employeeName;
                document.getElementById('reassign_warning_current_station').textContent = currentAssignment.station_name;
                document.getElementById('reassign_warning_target_station').textContent = targetStation;
                document.getElementById('reassign_warning_shift_time').textContent = currentAssignment.shift_start_time + ' - ' + currentAssignment.shift_end_time;
                document.getElementById('reassignmentWarningModal').classList.add('show');
            }
        }
        
        // Enhanced assign modal with conflict checking
        function openAssignModalWithCheck(stationId, stationName, stationType) {
            document.getElementById('assign_station_id').value = stationId;
            document.getElementById('assign_station_name').value = stationName;
            
            // Populate employee dropdown with role-specific employees
            const select = document.querySelector('#assignModal select[name="employee_id"]');
            populateEmployeeDropdown(select, stationType);
            
            // Add change listener to check conflicts when employee is selected
            select.onchange = function() {
                const selectedEmployeeId = this.value;
                if (selectedEmployeeId) {
                    const selectedOption = this.options[this.selectedIndex];
                    const employeeName = selectedOption.textContent;
                    
                    // Check for conflicts
                    if (checkEmployeeConflicts(selectedEmployeeId, stationName, '<?php echo $date; ?>', '08:00', '17:00')) {
                        // Conflict found - warning modal will show
                        this.value = ''; // Reset selection
                        return false;
                    }
                }
            };
            
            document.getElementById('assignModal').classList.add('show');
        }
        
        // Update existing function to use new conflict checking
        function openAssignModal(stationId, stationName, stationType) {
            openAssignModalWithCheck(stationId, stationName, stationType);
        }
        
        // Open assignment history modal
        function openHistoryModal() {
            const modal = document.getElementById('historyModal');
            const modalContent = document.getElementById('historyModalContent');
            
            if (!modal || !modalContent) {
                console.error('History modal elements not found');
                return;
            }
            
            modal.classList.add('show');
            
            // Reset modal content with loading state
            modalContent.innerHTML = `
                <div class="text-center">
                    <div class="loader" style="display: block; margin: 20px auto;"></div>
                    <p>Loading assignment history...</p>
                </div>
            `;
            
            // Load history via AJAX
            const currentUrl = new URL(window.location.href);
            currentUrl.searchParams.set('ajax_history', '1');
            
            fetch(currentUrl.toString())
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.text();
                })
                .then(data => {
                    modalContent.innerHTML = data;
                })
                .catch(error => {
                    console.error('Assignment history fetch error:', error);
                    modalContent.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle"></i>
                            Error loading assignment history: ${error.message}
                        </div>
                    `;
                });
        }

        // Station toggle confirmation functions
        let toggleStationData = {};

        function openToggleConfirmModal(stationId, stationName, isActive) {
            // Store the toggle data for later use
            toggleStationData = {
                stationId: stationId,
                stationName: stationName,
                isActive: isActive
            };

            const modal = document.getElementById('toggleConfirmModal');
            const title = document.getElementById('toggleConfirmTitle');
            const message = document.getElementById('toggleConfirmMessage');
            const icon = document.getElementById('toggleConfirmIcon');
            const confirmBtn = document.getElementById('confirmToggleBtn');
            const confirmBtnText = document.getElementById('confirmToggleBtnText');

            // Set modal content based on current station status
            if (isActive) {
                // Station is active, asking to deactivate
                title.textContent = 'Deactivate Station';
                message.innerHTML = `Are you sure you want to <strong>deactivate</strong> the <strong>${stationName}</strong> station?`;
                icon.className = 'fas fa-power-off';
                icon.style.color = '#dc3545';
                confirmBtn.className = 'action-btn btn-danger';
                confirmBtnText.textContent = 'Deactivate';
            } else {
                // Station is inactive, asking to activate
                title.textContent = 'Activate Station';
                message.innerHTML = `Are you sure you want to <strong>activate</strong> the <strong>${stationName}</strong> station?`;
                icon.className = 'fas fa-play';
                icon.style.color = '#28a745';
                confirmBtn.className = 'action-btn btn-success';
                confirmBtnText.textContent = 'Activate';
            }

            modal.classList.add('show');
        }

        function confirmStationToggle() {
            if (!toggleStationData.stationId) {
                console.error('No station data available for toggle');
                return;
            }

            // Submit the form
            const form = document.getElementById(`toggleForm_${toggleStationData.stationId}`);
            if (form) {
                form.submit();
            } else {
                console.error(`Form not found for station ${toggleStationData.stationId}`);
            }

            // Close the modal
            closeModal('toggleConfirmModal');
        }
    </script>
    
    <!-- Assignment Conflict Warning Modal -->
    <div class="modal" id="assignmentWarningModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #d32f2f;">
                    <h5 style="color: white;"><i class="fas fa-exclamation-triangle"></i> Assignment Conflict Warning</h5>
                    <button type="button" class="btn-close" onclick="closeModal('assignmentWarningModal')" style="color: white;">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger" style="margin-bottom: 15px;">
                        <i class="fas fa-exclamation-circle"></i>
                        <strong>Cannot Assign Employee!</strong>
                    </div>
                    
                    <p><strong><span id="warning_employee_name"></span></strong> is already assigned to <strong><span id="warning_current_station"></span></strong>.</p>
                    
                    <div style="background-color: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 15px 0;">
                        <h6 style="color: #856404; margin: 0 0 10px 0;"><i class="fas fa-info-circle"></i> Current Assignment Details:</h6>
                        <ul style="margin: 0; color: #856404;">
                            <li><strong>Station:</strong> <span id="warning_current_station"></span></li>
                            <li><strong>Shift Hours:</strong> <span id="warning_shift_time"></span></li>
                            <li><strong>Status:</strong> Active</li>
                        </ul>
                    </div>
                    
                    <div style="background-color: #f8d7da; padding: 15px; border-radius: 8px; border-left: 4px solid #dc3545; margin: 15px 0;">
                        <h6 style="color: #721c24; margin: 0 0 10px 0;"><i class="fas fa-ban"></i> Why This Is Not Allowed:</h6>
                        <ul style="margin: 0; color: #721c24;">
                            <li>An employee cannot be in two places at the same time</li>
                            <li>Overlapping shift schedules create conflicts</li>
                            <li>This violates scheduling business rules</li>
                        </ul>
                    </div>
                    
                    <div style="background-color: #d1ecf1; padding: 15px; border-radius: 8px; border-left: 4px solid #17a2b8; margin: 15px 0;">
                        <h6 style="color: #0c5460; margin: 0 0 10px 0;"><i class="fas fa-lightbulb"></i> What You Can Do:</h6>
                        <ul style="margin: 0; color: #0c5460;">
                            <li><strong>Reassign:</strong> Move the employee from their current station to <strong><span id="warning_new_station"></span></strong></li>
                            <li><strong>Choose Different Employee:</strong> Select someone who is not currently assigned</li>
                            <li><strong>Remove Current Assignment:</strong> End their current assignment first</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="action-btn btn-primary" onclick="closeModal('assignmentWarningModal')">
                        <i class="fas fa-check"></i> Understood
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Reassignment Conflict Warning Modal -->
    <div class="modal" id="reassignmentWarningModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #d32f2f;">
                    <h5 style="color: white;"><i class="fas fa-exclamation-triangle"></i> Reassignment Conflict Warning</h5>
                    <button type="button" class="btn-close" onclick="closeModal('reassignmentWarningModal')" style="color: white;">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger" style="margin-bottom: 15px;">
                        <i class="fas fa-exclamation-circle"></i>
                        <strong>Cannot Reassign Employee!</strong>
                    </div>
                    
                    <p><strong><span id="reassign_warning_employee_name"></span></strong> is already assigned to <strong><span id="reassign_warning_current_station"></span></strong> and cannot be reassigned to <strong><span id="reassign_warning_target_station"></span></strong> without proper handling.</p>
                    
                    <div style="background-color: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 15px 0;">
                        <h6 style="color: #856404; margin: 0 0 10px 0;"><i class="fas fa-info-circle"></i> Current Assignment Details:</h6>
                        <ul style="margin: 0; color: #856404;">
                            <li><strong>Currently At:</strong> <span id="reassign_warning_current_station"></span></li>
                            <li><strong>Shift Hours:</strong> <span id="reassign_warning_shift_time"></span></li>
                            <li><strong>Status:</strong> Active Assignment</li>
                        </ul>
                    </div>
                    
                    <div style="background-color: #f8d7da; padding: 15px; border-radius: 8px; border-left: 4px solid #dc3545; margin: 15px 0;">
                        <h6 style="color: #721c24; margin: 0 0 10px 0;"><i class="fas fa-ban"></i> Why Reassignment Is Blocked:</h6>
                        <ul style="margin: 0; color: #721c24;">
                            <li>Employee is currently assigned to another station</li>
                            <li>Cannot be in two stations simultaneously</li>
                            <li>System prevents scheduling conflicts automatically</li>
                        </ul>
                    </div>
                    
                    <div style="background-color: #d1ecf1; padding: 15px; border-radius: 8px; border-left: 4px solid #17a2b8; margin: 15px 0;">
                        <h6 style="color: #0c5460; margin: 0 0 10px 0;"><i class="fas fa-clipboard-list"></i> Required Steps for Reassignment:</h6>
                        <ol style="margin: 0; color: #0c5460; padding-left: 20px;">
                            <li><strong>Remove Current Assignment:</strong> First, remove <span id="reassign_warning_employee_name"></span> from <span id="reassign_warning_current_station"></span></li>
                            <li><strong>Confirm Removal:</strong> Ensure the employee is no longer assigned to any station</li>
                            <li><strong>Then Reassign:</strong> Assign them to <span id="reassign_warning_target_station"></span></li>
                        </ol>
                        
                        <div style="background-color: #bee5eb; padding: 10px; border-radius: 4px; margin-top: 10px;">
                            <strong>💡 Alternative:</strong> Choose a different employee who is not currently assigned to any station.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="action-btn btn-secondary" onclick="closeModal('reassignmentWarningModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="action-btn btn-primary" onclick="closeModal('reassignmentWarningModal')">
                        <i class="fas fa-check"></i> I Understand
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Assignment History Modal -->
    <div class="modal" id="historyModal" tabindex="-1">
        <div class="modal-dialog" style="max-width: 900px;">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #0077b6;">
                    <h5 style="color: white;"><i class="fas fa-history"></i> Assignment History</h5>
                    <button type="button" class="btn-close" onclick="closeModal('historyModal')" style="color: white;">&times;</button>
                </div>
                <div class="modal-body" id="historyModalContent">
                    <div class="text-center">
                        <div class="loader" id="historyLoader"></div>
                        <p id="historyLoading">Loading assignment history...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="action-btn btn-secondary" onclick="closeModal('historyModal')">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Station Toggle Confirmation Modal -->
    <div class="modal" id="toggleConfirmModal" tabindex="-1">
        <div class="modal-dialog" style="max-width: 400px;">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 id="toggleConfirmTitle">Confirm Station Status Change</h5>
                    <button type="button" class="btn-close" onclick="closeModal('toggleConfirmModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div style="text-align: center; margin: 20px 0;">
                        <i id="toggleConfirmIcon" class="fas fa-power-off" style="font-size: 48px; margin-bottom: 15px;"></i>
                        <p id="toggleConfirmMessage" style="font-size: 16px; margin-bottom: 10px;"></p>
                        <p style="color: #6c757d; font-size: 14px;">This action will change the station status immediately.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="action-btn btn-secondary" onclick="closeModal('toggleConfirmModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="action-btn" id="confirmToggleBtn" onclick="confirmStationToggle()">
                        <i class="fas fa-check"></i> <span id="confirmToggleBtnText">Confirm</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
// Flush output buffer at the end
if (ob_get_level()) {
    ob_end_flush();
}
?>