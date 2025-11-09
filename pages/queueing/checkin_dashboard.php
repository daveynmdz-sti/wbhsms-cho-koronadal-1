<?php
// pages/queueing/checkin_dashboard.php
// Check-in station dashboard for patient appointments and queue management

// Start output buffering to prevent header issues
ob_start();

// Include path resolution and security configuration first (includes CSP headers)
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/production_security.php';
require_once $root_path . '/config/session/employee_session.php';

// If user is not logged in, bounce to login
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    // Clean output buffer if it exists
    if (ob_get_level()) {
        ob_end_clean();
    }
    error_log('Redirecting to employee_login from ' . __FILE__ . ' URI=' . ($_SERVER['REQUEST_URI'] ?? ''));
    header('Location: /pages/management/auth/employee_login.php');
    exit();
}

require_once $root_path . '/config/db.php';
require_once $root_path . '/config/auth_helpers.php';

// Triage Station Queue Management Functions
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

function getOptimalTriageStation($pdo, $service_id = null) {
    try {
        // Get queue counts for triage stations (1, 2, 3)
        $stations = [];
        for ($i = 1; $i <= 3; $i++) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as queue_count 
                FROM station_{$i}_queue 
                WHERE status IN ('waiting', 'in_progress') 
                AND DATE(time_in) = CURDATE()
            ");
            $stmt->execute();
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['queue_count'];
            
            $stations[$i] = ['station_id' => $i, 'queue_count' => $count];
        }
        
        // Find station with shortest queue
        uasort($stations, function($a, $b) {
            return $a['queue_count'] <=> $b['queue_count'];
        });
        
        $optimal_station = reset($stations);
        return $optimal_station['station_id'];
        
    } catch (Exception $e) {
        error_log('getOptimalTriageStation Error: ' . $e->getMessage());
        return 1; // Default to Station 1
    }
}

function generateQueueNumber($pdo, $station_id, $priority_level) {
    try {
        // Get today's queue count for this station
        $stmt = $pdo->prepare("
            SELECT COUNT(*) + 1 as next_number 
            FROM station_{$station_id}_queue 
            WHERE DATE(time_in) = CURDATE()
        ");
        $stmt->execute();
        $next_number = $stmt->fetch(PDO::FETCH_ASSOC)['next_number'];
        
        // Generate queue code based on priority and station
        $priority_prefix = [
            'emergency' => 'E',
            'priority' => 'P', 
            'normal' => 'N'
        ];
        $prefix = $priority_prefix[$priority_level] ?? 'N';
        
        return [
            'queue_number' => $next_number,
            'queue_code' => "T{$station_id}-{$prefix}{$next_number}"
        ];
        
    } catch (Exception $e) {
        error_log('generateQueueNumber Error: ' . $e->getMessage());
        return ['queue_number' => 1, 'queue_code' => "T{$station_id}-N1"];
    }
}

// Simple input sanitization function
if (!function_exists('sanitize_input')) {
    function sanitize_input($input) {
        if (is_string($input)) {
            return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
        }
        return $input;
    }
}

// Get search parameters
$search_filter = sanitize_input($_GET['search'] ?? '');
$time_slot = sanitize_input($_GET['time_slot'] ?? '');
$date_filter = sanitize_input($_GET['date'] ?? date('Y-m-d'));

// Generate time slots from 8:00 AM to 4:00 PM
$time_slots = [];
for ($hour = 8; $hour <= 16; $hour++) {
    $time_24 = sprintf('%02d:00', $hour);
    $time_12 = date('g:00 A', strtotime($time_24));
    $time_slots[] = [
        'value' => $time_24,
        'label' => $time_12
    ];
}

// Facility info
$facility_id = 1; // CHO Koronadal
$facility_name = "CHO Koronadal";

// Base query for appointments (FIFO ordering by scheduled time, then creation time)
$base_query = "
    SELECT 
        a.appointment_id,
        a.patient_id,
        a.scheduled_date,
        a.scheduled_time,
        a.status,
        a.created_at,
        p.username as patient_id_display,
        p.first_name,
        p.middle_name,
        p.last_name,
        p.contact_number,
        CONCAT(p.first_name, ' ', p.last_name) as patient_name
    FROM appointments a
    INNER JOIN patients p ON a.patient_id = p.patient_id
    WHERE a.facility_id = ? 
        AND DATE(a.scheduled_date) = ?
        AND a.status IN ('confirmed', 'checked_in')
";

$params = [$facility_id, $date_filter];

// Add search filter
if (!empty($search_filter)) {
    $base_query .= " AND (p.username LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)";
    $search_param = '%' . $search_filter . '%';
    $params = array_merge($params, [$search_param, $search_param, $search_param]);
}

// Add time slot filter
if (!empty($time_slot)) {
    $base_query .= " AND TIME(a.scheduled_time) = ?";
    $params[] = $time_slot;
}

// FIFO ordering: earliest scheduled time first, then earliest booking time for same slot
$base_query .= " ORDER BY a.scheduled_time ASC, a.created_at ASC";

// Execute query
try {
    $stmt = $pdo->prepare($base_query);
    $stmt->execute($params);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Error fetching appointments: ' . $e->getMessage());
    $appointments = [];
}

// Handle appointment actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    
    if ($action === 'checkin_appointment' && $appointment_id > 0) {
        // Legacy check-in (updated to include visit creation and triage queue)
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // First, get appointment details
            $stmt = $pdo->prepare("
                SELECT a.appointment_id, a.patient_id, a.scheduled_date, a.scheduled_time, a.service_id, a.status,
                       p.first_name, p.last_name, p.username
                FROM appointments a
                JOIN patients p ON a.patient_id = p.patient_id
                WHERE a.appointment_id = ? AND a.status = 'confirmed'
            ");
            $stmt->execute([$appointment_id]);
            $appointment_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$appointment_data) {
                throw new Exception("Appointment not found or not in confirmed status");
            }
            
            // Check if already checked in (prevent duplicate visits)
            $stmt = $pdo->prepare("SELECT visit_id FROM visits WHERE appointment_id = ?");
            $stmt->execute([$appointment_id]);
            if ($stmt->fetch()) {
                throw new Exception("This appointment has already been checked in");
            }
            
            // Update appointment status to checked_in
            $stmt = $pdo->prepare("UPDATE appointments SET status = 'checked_in', updated_at = NOW() WHERE appointment_id = ?");
            if (!$stmt->execute([$appointment_id])) {
                throw new Exception("Failed to update appointment status");
            }
            
            // Create visit record
            $stmt = $pdo->prepare("
                INSERT INTO visits (appointment_id, patient_id, time_in, attendance_status, created_at, updated_at) 
                VALUES (?, ?, NOW(), 'on_time', NOW(), NOW())
            ");
            if (!$stmt->execute([$appointment_id, $appointment_data['patient_id']])) {
                throw new Exception("Failed to create visit record");
            }
            
            $visit_id = $pdo->lastInsertId();
            
            // Ensure we have a valid service_id
            $service_id = $appointment_data['service_id'] ?: 1;
            
            // Get optimal triage station
            $station_id = getOptimalTriageStation($pdo, $service_id);
            
            // Generate queue number
            $queue_info = generateQueueNumber($pdo, $station_id, 'normal');
            
            // Add to triage station queue
            $queue_result = addToStationQueue(
                $pdo,
                $station_id,
                $appointment_data['patient_id'],
                $appointment_data['username'] ?: $appointment_data['patient_id'],
                $visit_id,
                $appointment_id,
                $service_id,
                'triage',
                'normal'
            );
            
            if (!$queue_result['success']) {
                throw new Exception("Failed to add patient to triage queue: " . ($queue_result['message'] ?? 'Unknown error'));
            }
            
            // Commit transaction
            $pdo->commit();
            
            $station_name = "Triage Station {$station_id}";
            $message = "Patient successfully checked in! Assigned to {$station_name} - Queue Number: {$queue_info['queue_number']} (Code: {$queue_info['queue_code']})";
            
        } catch (Exception $e) {
            // Rollback transaction
            $pdo->rollback();
            error_log('Legacy check-in error: ' . $e->getMessage());
            $error = "Error checking in patient: " . $e->getMessage();
        }
    } elseif ($action === 'checkin_appointment_with_priority' && $appointment_id > 0) {
        // New check-in with priority and queue management
        $priority = sanitize_input($_POST['priority'] ?? 'standard');
        $notes = sanitize_input($_POST['notes'] ?? '');
        
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // First, verify appointment exists and is in correct status
            $stmt = $pdo->prepare("
                SELECT a.appointment_id, a.patient_id, a.scheduled_date, a.scheduled_time, a.service_id, a.status,
                       p.first_name, p.last_name, s.name as service_name
                FROM appointments a
                JOIN patients p ON a.patient_id = p.patient_id
                LEFT JOIN services s ON a.service_id = s.service_id
                WHERE a.appointment_id = ?
            ");
            $stmt->execute([$appointment_id]);
            $appointment_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$appointment_data) {
                throw new Exception("Appointment not found");
            }
            
            if ($appointment_data['status'] !== 'confirmed') {
                throw new Exception("Appointment is not in confirmed status (current status: {$appointment_data['status']})");
            }
            
            // Check if already checked in (prevent duplicate visits)
            $stmt = $pdo->prepare("SELECT visit_id FROM visits WHERE appointment_id = ?");
            $stmt->execute([$appointment_id]);
            if ($stmt->fetch()) {
                throw new Exception("This appointment has already been checked in");
            }
            
            // Update appointment status to checked_in
            $stmt = $pdo->prepare("UPDATE appointments SET status = 'checked_in', updated_at = NOW() WHERE appointment_id = ?");
            if (!$stmt->execute([$appointment_id])) {
                throw new Exception("Failed to update appointment status");
            }
            
            // Create visit record
            $stmt = $pdo->prepare("
                INSERT INTO visits (appointment_id, patient_id, time_in, attendance_status, created_at, updated_at) 
                VALUES (?, ?, NOW(), 'on_time', NOW(), NOW())
            ");
            if (!$stmt->execute([$appointment_id, $appointment_data['patient_id']])) {
                throw new Exception("Failed to create visit record");
            }
            
            $visit_id = $pdo->lastInsertId();
            
            // Ensure we have a valid service_id (required by queue_entries table)
            $service_id = $appointment_data['service_id'];
            if (empty($service_id)) {
                // Default to service_id 1 (General Consultation) if no specific service
                $service_id = 1;
                error_log("No service_id found for appointment {$appointment_id}, using default service_id: 1");
            }
            
            // Map priority levels to queue format (database uses: normal, priority, emergency)
            $priority_mapping = [
                'emergency' => 'emergency',
                'urgent' => 'priority', 
                'standard' => 'normal',
                'low' => 'normal'
            ];
            $mapped_priority = $priority_mapping[$priority] ?? 'normal';
            
            // Add patient directly to station_1_queue (simplified approach)
            $username = $appointment_data['first_name'] . ' ' . $appointment_data['last_name'];
            
            $queue_stmt = $pdo->prepare("
                INSERT INTO station_1_queue (
                    patient_id, username, visit_id, appointment_id, service_id,
                    queue_type, station_id, priority_level, status, time_in
                ) VALUES (?, ?, ?, ?, ?, 'triage', 1, ?, 'waiting', NOW())
            ");
            
            $queue_result = $queue_stmt->execute([
                $appointment_data['patient_id'], 
                $username,
                $visit_id, 
                $appointment_id, 
                $service_id,
                $mapped_priority
            ]);
            
            if (!$queue_result) {
                throw new Exception("Failed to add patient to Station 1 queue");
            }
            
            $queue_entry_id = $pdo->lastInsertId();
            
            // Commit transaction
            $pdo->commit();
            
            $priority_label = ucfirst($priority);
            $station_name = "Station 1 (Triage)";
            
            $message = "Patient successfully checked in with {$priority_label} priority! Added to {$station_name} - Queue Entry ID: {$queue_entry_id}";
            
            // Log the successful check-in for debugging
            error_log("Successful check-in - Appointment ID: {$appointment_id}, Visit ID: {$visit_id}, Queue Entry ID: {$queue_entry_id}, Priority: {$mapped_priority}");
            
        } catch (Exception $e) {
            // Rollback transaction
            $pdo->rollback();
            error_log('Priority check-in error: ' . $e->getMessage());
            $error = "Error during check-in: " . $e->getMessage();
        }
    }
}

// Set active page for sidebar highlighting
$user_role = strtolower($_SESSION['role']);
$activePage = 'checkin';

// Determine which sidebar to include based on role
$sidebar_file = '';
switch ($user_role) {
    case 'admin':
        $sidebar_file = 'sidebar_admin.php';
        break;
    case 'nurse':
        $sidebar_file = 'sidebar_nurse.php';
        break;
    case 'records_officer':
        $sidebar_file = 'sidebar_records_officer.php';
        break;
    default:
        $sidebar_file = 'sidebar_admin.php';
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check-in Station | CHO Koronadal</title>
    <!-- CSS Files -->
    <link rel="stylesheet" href="../../assets/css/sidebar.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- QR Code Scanner Library with fallbacks -->
    <script>
        // Try to load from multiple CDNs
        function loadQRLibrary() {
            return new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.onload = resolve;
                script.onerror = () => {
                    // Fallback to second CDN
                    const fallbackScript = document.createElement('script');
                    fallbackScript.onload = resolve;
                    fallbackScript.onerror = reject;
                    fallbackScript.src = 'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.4/html5-qrcode.min.js';
                    document.head.appendChild(fallbackScript);
                };
                script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js';
                document.head.appendChild(script);
            });
        }
        
        // Load library when page loads
        document.addEventListener('DOMContentLoaded', () => {
            loadQRLibrary().catch(() => {
                console.warn('QR Scanner library failed to load from CDN');
                // Enable manual entry only
                document.addEventListener('DOMContentLoaded', () => {
                    const startBtn = document.getElementById('startScanBtn');
                    if (startBtn) {
                        startBtn.style.display = 'none';
                        const manualSection = document.querySelector('#qrScannerModal .manual-entry-section');
                        if (manualSection) {
                            manualSection.innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Camera scanner unavailable. Please use manual entry.</div>' + manualSection.innerHTML;
                        }
                    }
                });
            });
        });
    </script>
    <style>
        /* Check-in Station Specific Styles */
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

        .content-wrapper {
            min-height: calc(100vh - 60px);
        }

        /* Breadcrumb styling */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: #666;
            background: none;
            padding: 0;
        }

        .breadcrumb a {
            color: #0077b6;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
            color: #03045e;
        }

        .breadcrumb i {
            color: #999;
            font-size: 0.8rem;
        }

        /* Page header styling */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            background: linear-gradient(135deg, #0077b6, #03045e);
            color: white;
            padding: 20px 25px;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
        }

        .page-header h1 {
            color: white;
            margin: 0;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-header h1 i {
            color: #48cae4;
            font-size: 1.6rem;
        }

        /* Search section */
        .search-section {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        .search-form {
            display: flex;
            gap: 10px;
            align-items: end;
            flex-wrap: wrap;
        }

        .search-group {
            flex: 1;
            min-width: 250px;
        }

        .search-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--primary-dark);
        }

        .search-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: var(--border-radius);
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .search-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.2);
            outline: none;
        }

        .search-buttons {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #48cae4, #0096c7);
            color: white;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #adb5bd, #6c757d);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* Time slots tabs */
        .time-slots-section {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        .time-slots-header {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        .time-slots-header h3 {
            margin: 0;
            color: var(--primary-dark);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .time-slots-tabs {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-evenly;
            gap: 8px;
        }

        .time-slot-tab {
            padding: 10px 16px;
            border: 2px solid #e2e8f0;
            border-radius: var(--border-radius);
            background: white;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 500;
            text-decoration: none;
            color: var(--secondary);
        }

        .time-slot-tab:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(0, 119, 182, 0.05);
        }

        .time-slot-tab.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        /* Table container */
        .table-container {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 25px;
            box-shadow: var(--shadow);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .section-header h4 {
            margin: 0;
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Table styles */
        .table-wrapper {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th {
            background: linear-gradient(135deg, #0077b6, #03045e);
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 500;
            border: none;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .table td {
            padding: 15px 12px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .table tr:hover {
            background-color: rgba(240, 247, 255, 0.6);
        }

        .profile-img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }

        .time-slot {
            background: var(--primary);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-success {
            background-color: #e8f5e8;
            color: #388e3c;
        }

        .badge-warning {
            background-color: #fff3e0;
            color: #f57c00;
        }

        .badge-primary {
            background-color: #e3f2fd;
            color: #1976d2;
        }

        .badge-danger {
            background-color: #ffebee;
            color: #d32f2f;
        }

        .actions-group {
            display: flex;
            gap: 5px;
        }

        .btn-sm {
            padding: 8px 12px;
            font-size: 12px;
            min-width: 36px;
        }

        .btn-success {
            background: linear-gradient(135deg, #52b788, #2d6a4f);
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, #0096c7, #0077b6);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef476f, #d00000);
            color: white;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 15px;
        }

        .empty-state h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: #999;
        }

        .empty-state p {
            font-size: 0.9rem;
            color: #aaa;
        }

        /* Alert styles */
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left-width: 4px;
            border-left-style: solid;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border-color: #c3e6cb;
            border-left-color: #28a745;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
            border-left-color: #dc3545;
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

        .modal-content {
            background-color: white;
            margin: 20px auto;
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: 20px;
            background: var(--primary-dark);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: white;
        }

        .modal-body {
            padding: 20px;
            flex: 1;
            overflow-y: auto;
            max-height: calc(90vh - 140px);
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-shrink: 0;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .search-form {
                flex-direction: column;
            }

            .search-group {
                min-width: auto;
            }

            .search-buttons {
                width: 100%;
                justify-content: stretch;
            }

            .search-buttons .btn {
                flex: 1;
            }

            .time-slots-tabs {
                justify-content: center;
            }

            .page-header {
                padding: 15px 20px;
            }

            .content-wrapper {
                padding: 15px;
            }

            .table {
                font-size: 0.9rem;
            }

            .table th,
            .table td {
                padding: 10px 8px;
            }
        }

        /* Appointment Details Modal Styles */
        .appointment-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .details-section {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            background: #fff;
        }

        .details-section h4 {
            color: #0077b6;
            margin-bottom: 15px;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            padding: 5px 0;
            border-bottom: 1px solid #f8f9fa;
        }

        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .detail-label {
            font-weight: 600;
            color: #495057;
            min-width: 120px;
        }

        .detail-value {
            color: #212529;
            text-align: right;
            max-width: 60%;
            word-wrap: break-word;
        }

        .detail-value.highlight {
            background: #e3f2fd;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 600;
            color: #0277bd;
        }

        .vitals-group {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 12px;
            background: #f8f9fa;
        }

        .qr-code-container img {
            max-width: 150px;
            height: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .qr-code-description {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 5px;
            margin-bottom: 0;
        }

        /* Vital Signs Status Indicators */
        .vital-status {
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 11px !important;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .vital-status.normal {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .vital-status.high {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .vital-status.low {
            background: #ffeaa7;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .vital-status.critical {
            background: #dc3545;
            color: white;
            border: 1px solid #dc3545;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                opacity: 1;
            }

            50% {
                opacity: 0.7;
            }

            100% {
                opacity: 1;
            }
        }

        /* Mobile responsive for appointment details */
        @media (max-width: 768px) {
            .appointment-details-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .detail-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }

            .detail-value {
                text-align: left;
                max-width: 100%;
            }

            .detail-label {
                min-width: auto;
                font-size: 0.9rem;
            }

            .modal-content {
                margin: 10px auto;
                max-width: 95%;
                max-height: 95vh;
            }

            .modal-body {
                max-height: calc(95vh - 120px);
                padding: 15px;
            }

            .modal-header, .modal-footer {
                padding: 15px;
            }
        }

        /* QR Scanner Modal Styles */
        .scanner-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .scanner-camera-section,
        .scanner-manual-section {
            min-height: 280px;
        }

        .special-category-option:hover {
            background: #e3f2fd !important;
            border-color: #0077b6 !important;
        }

        .special-category-option input[type="checkbox"]:checked + i + span {
            color: #0077b6;
            font-weight: 600;
        }

        .priority-option {
            cursor: pointer;
            display: block;
            margin: 0;
        }

        .priority-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .priority-card {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            background: white;
            transition: all 0.2s ease;
            cursor: pointer;
            min-height: 80px;
        }

        .priority-card:hover {
            border-color: #0077b6;
            background: #f8f9fa;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 119, 182, 0.1);
        }

        .priority-option input[type="radio"]:checked + .priority-card {
            border-color: #0077b6;
            background: #e3f2fd;
            box-shadow: 0 2px 8px rgba(0, 119, 182, 0.2);
        }

        .priority-card i {
            font-size: 24px;
            min-width: 30px;
            margin-top: 2px;
        }

        .priority-card div {
            flex: 1;
        }

        .priority-card strong {
            display: block;
            font-size: 1.1rem;
            margin-bottom: 4px;
            line-height: 1.2;
        }

        .priority-card span {
            font-size: 0.85rem;
            color: #666;
            line-height: 1.3;
            display: block;
        }

        .priority-card.emergency {
            border-left: 4px solid #dc3545;
        }

        .priority-card.emergency i {
            color: #dc3545;
        }

        .priority-option input[type="radio"]:checked + .priority-card.emergency {
            background: #fff5f5;
            border-color: #dc3545;
        }

        .priority-card.urgent {
            border-left: 4px solid #ffc107;
        }

        .priority-card.urgent i {
            color: #ffc107;
        }

        .priority-option input[type="radio"]:checked + .priority-card.urgent {
            background: #fffef7;
            border-color: #ffc107;
        }

        .priority-card.standard {
            border-left: 4px solid #28a745;
        }

        .priority-card.standard i {
            color: #28a745;
        }

        .priority-option input[type="radio"]:checked + .priority-card.standard {
            background: #f8fff8;
            border-color: #28a745;
        }

        .priority-card.low {
            border-left: 4px solid #6c757d;
        }

        .priority-card.low i {
            color: #6c757d;
        }

        .priority-option input[type="radio"]:checked + .priority-card.low {
            background: #f8f9fa;
            border-color: #6c757d;
        }

        /* Station Selection Styles */
        .station-option {
            cursor: pointer;
            display: block;
            margin: 0;
        }

        .station-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .station-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 18px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            background: white;
            transition: all 0.2s ease;
            cursor: pointer;
            min-height: 90px;
        }

        .station-card:hover {
            border-color: #0077b6;
            background: #f8f9fa;
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(0, 119, 182, 0.1);
        }

        .station-option input[type="radio"]:checked + .station-card {
            border-color: #0077b6;
            background: #e3f2fd;
            box-shadow: 0 3px 10px rgba(0, 119, 182, 0.2);
        }

        .station-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            transition: all 0.2s ease;
        }

        .station-icon i {
            font-size: 22px;
            color: #6c757d;
            transition: all 0.2s ease;
        }

        .station-info {
            flex: 1;
        }

        .station-info strong {
            display: block;
            font-size: 1.05rem;
            margin-bottom: 4px;
            line-height: 1.2;
            color: #212529;
        }

        .station-info span {
            font-size: 0.85rem;
            color: #666;
            line-height: 1.3;
            display: block;
            margin-bottom: 6px;
        }

        .queue-info {
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: 12px;
            font-weight: 600;
            display: inline-block;
            margin-top: 2px;
        }

        /* Auto Assignment Station */
        .station-card.auto .station-icon {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border-color: #0077b6;
        }

        .station-card.auto .station-icon i {
            color: #0077b6;
        }

        .station-option input[type="radio"]:checked + .station-card.auto .station-icon {
            background: linear-gradient(135deg, #0077b6, #03045e);
        }

        .station-option input[type="radio"]:checked + .station-card.auto .station-icon i {
            color: white;
        }

        /* Station 1 */
        .station-card.station1 .station-icon {
            background: linear-gradient(135deg, #e8f5e8, #c8e6c9);
            border-color: #28a745;
        }

        .station-card.station1 .station-icon i {
            color: #28a745;
        }

        .station-option input[type="radio"]:checked + .station-card.station1 .station-icon {
            background: linear-gradient(135deg, #28a745, #1e7e34);
        }

        .station-option input[type="radio"]:checked + .station-card.station1 .station-icon i {
            color: white;
        }

        /* Station 2 */
        .station-card.station2 .station-icon {
            background: linear-gradient(135deg, #fff3e0, #ffcc80);
            border-color: #ff9800;
        }

        .station-card.station2 .station-icon i {
            color: #ff9800;
        }

        .station-option input[type="radio"]:checked + .station-card.station2 .station-icon {
            background: linear-gradient(135deg, #ff9800, #e65100);
        }

        .station-option input[type="radio"]:checked + .station-card.station2 .station-icon i {
            color: white;
        }

        /* Station 3 */
        .station-card.station3 .station-icon {
            background: linear-gradient(135deg, #f3e5f5, #ce93d8);
            border-color: #9c27b0;
        }

        .station-card.station3 .station-icon i {
            color: #9c27b0;
        }

        .station-option input[type="radio"]:checked + .station-card.station3 .station-icon {
            background: linear-gradient(135deg, #9c27b0, #6a1b9a);
        }

        .station-option input[type="radio"]:checked + .station-card.station3 .station-icon i {
            color: white;
        }

        /* Queue info styling */
        .queue-info.low {
            background: #d4edda;
            color: #155724;
        }

        .queue-info.medium {
            background: #fff3cd;
            color: #856404;
        }

        .queue-info.high {
            background: #f8d7da;
            color: #721c24;
        }

        .queue-info.optimal {
            background: #d1ecf1;
            color: #0c5460;
        }

        /* Scanner specific styles */
        #qrVideo {
            background: #000;
            object-fit: cover;
            display: block;
            margin: 0 auto;
        }

        /* QR Scanner container */
        #cameraContainer {
            position: relative;
            text-align: center;
            margin-bottom: 15px;
            background: #f8f9fa;
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 10px;
            min-height: 245px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #cameraContainer.active {
            border-color: #0077b6;
            background: #fff;
        }

        .scanner-status {
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            text-align: center;
        }

        .scanner-status.scanning {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .scanner-status.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .scanner-status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Mobile responsive for QR scanner modal */
        @media (max-width: 768px) {
            .scanner-grid {
                grid-template-columns: 1fr !important;
                gap: 15px;
            }

            .scanner-camera-section,
            .scanner-manual-section {
                min-height: auto;
            }

            #qr-reader {
                max-width: 350px !important;
                height: 350px !important;
            }

            .priority-card {
                min-height: auto;
                padding: 12px;
            }

            .priority-card i {
                font-size: 20px;
                min-width: 24px;
            }

            .priority-card strong {
                font-size: 1rem;
            }

            .priority-card span {
                font-size: 0.8rem;
            }

            #prioritySection .priority-grid {
                grid-template-columns: 1fr !important;
                gap: 12px;
            }

            .modal-content {
                max-width: 95% !important;
                margin: 10px auto;
            }

            .modal-body {
                padding: 10px !important;
                max-height: calc(95vh - 120px) !important;
            }
        }
    </style>
</head>
<body>
    <!-- Include appropriate sidebar based on user role -->
    <?php include '../../includes/' . $sidebar_file; ?>
    
    <div class="homepage">
        <div class="content-wrapper">
            <!-- Breadcrumb Navigation -->
            <div class="breadcrumb" style="margin-top: 50px;">
                <a href="../management/admin/dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <span>Check-in Station</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>
                        <i class="fas fa-sign-in-alt"></i>
                        Check-in Station
                    </h1>
                </div>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Patient Search Section -->
            <div class="search-section">
                <form method="GET" class="search-form">
                    <input type="hidden" name="time_slot" value="<?php echo htmlspecialchars($time_slot); ?>">
                    
                    <div class="search-group">
                        <label for="date">Appointment Date</label>
                        <input type="date" name="date" id="date" class="search-input"
                            value="<?php echo htmlspecialchars($date_filter); ?>"
                            style="min-width: 200px;">
                    </div>
                    
                    <div class="search-group">
                        <label for="search">Patient Search</label>
                        <input type="text" name="search" id="search" class="search-input"
                            value="<?php echo htmlspecialchars($search_filter); ?>"
                            placeholder="Search by Patient ID, First Name, or Last Name...">
                    </div>
                    
                    <div class="search-buttons">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>?date=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary">
                            <i class="fas fa-calendar-day"></i> Today
                        </a>
                        <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Reset
                        </a>
                    </div>
                </form>
            </div>

            <!-- Time Slots Section -->
            <div class="time-slots-section">
                <!--<div class="time-slots-header">
                    <h3>
                        <i class="fas fa-clock"></i>
                        Time Slots for <?php echo date('F j, Y', strtotime($date_filter)); ?>
                    </h3>
                </div>-->
                <div class="time-slots-tabs">
                    <a href="?date=<?php echo urlencode($date_filter); ?>&search=<?php echo urlencode($search_filter); ?>" 
                       class="time-slot-tab <?php echo empty($time_slot) ? 'active' : ''; ?>">
                        All Times
                    </a>
                    <?php foreach ($time_slots as $slot): ?>
                        <a href="?date=<?php echo urlencode($date_filter); ?>&time_slot=<?php echo urlencode($slot['value']); ?>&search=<?php echo urlencode($search_filter); ?>" 
                           class="time-slot-tab <?php echo $time_slot === $slot['value'] ? 'active' : ''; ?>">
                            <?php echo $slot['label']; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Appointments Table -->
            <div class="table-container">
                <div class="section-header">
                    <h4>
                        <i class="fas fa-sort-numeric-down"></i>
                        Check-in Queue (FIFO Order)
                        <?php if (!empty($time_slot)): ?>
                            - <?php echo date('g:i A', strtotime($time_slot)); ?>
                        <?php endif; ?>
                        <span style="background: #e3f2fd; color: #1976d2; padding: 4px 8px; border-radius: 12px; font-size: 0.8rem; margin-left: 10px;">
                            <?php echo count($appointments); ?> appointment<?php echo count($appointments) !== 1 ? 's' : ''; ?>
                        </span>
                    </h4>
                    <div style="font-size: 0.85rem; color: #666; margin-top: 5px;">
                        <i class="fas fa-info-circle"></i>
                        Sorted by scheduled time, then booking order (First In, First Out)
                    </div>
                </div>

                <?php if (empty($appointments)): ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No appointments found</h3>
                        <p>No appointments scheduled for <?php echo date('F j, Y', strtotime($date_filter)); ?> match your search criteria.</p>
                        <?php if (!empty($search_filter) || !empty($time_slot)): ?>
                            <p>
                                <a href="?date=<?php echo htmlspecialchars($date_filter); ?>" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear filters
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Queue #</th>
                                    <th>Appointment ID</th>
                                    <th>Patient ID</th>
                                    <th>Patient Name</th>
                                    <th>Scheduled Time</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $queue_position = 1;
                                foreach ($appointments as $appointment): 
                                ?>
                                    <tr data-appointment-id="<?php echo $appointment['appointment_id']; ?>">
                                        <td>
                                            <div style="background: linear-gradient(135deg, #0077b6, #03045e); color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 14px;">
                                                <?php echo $queue_position++; ?>
                                            </div>
                                        </td>
                                        <td><strong><?php echo 'APT-' . str_pad($appointment['appointment_id'], 8, '0', STR_PAD_LEFT); ?></strong></td>
                                        <td><?php echo htmlspecialchars($appointment['patient_id_display']); ?></td>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 10px;">
                                                <img src="../../assets/images/user-default.png"
                                                    alt="Profile" class="profile-img">
                                                <div>
                                                    <strong><?php echo htmlspecialchars($appointment['last_name'] . ', ' . $appointment['first_name']); ?></strong>
                                                    <?php if (!empty($appointment['middle_name'])): ?>
                                                        <br><small><?php echo htmlspecialchars($appointment['middle_name']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="time-slot"><?php echo date('g:i A', strtotime($appointment['scheduled_time'])); ?></span>
                                        </td>
                                        <td>
                                            <?php
                                            $badge_class = '';
                                            switch ($appointment['status']) {
                                                case 'confirmed':
                                                    $badge_class = 'badge-success';
                                                    break;
                                                case 'completed':
                                                    $badge_class = 'badge-primary';
                                                    break;
                                                case 'cancelled':
                                                    $badge_class = 'badge-danger';
                                                    break;
                                                case 'checked_in':
                                                    $badge_class = 'badge-warning';
                                                    break;
                                                default:
                                                    $badge_class = 'badge-info';
                                                    break;
                                            }
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo ucfirst(htmlspecialchars($appointment['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="actions-group">
                                                <button onclick="viewAppointment(<?php echo $appointment['appointment_id']; ?>)"
                                                    class="btn btn-sm btn-info" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($appointment['status'] === 'confirmed'): ?>
                                                    <!-- Original check-in button commented out for safe keeping -->
                                                    <!--
                                                    <button onclick="checkInAppointment(<?php echo $appointment['appointment_id']; ?>)"
                                                        class="btn btn-sm btn-success" title="Check In Patient">
                                                        <i class="fas fa-sign-in-alt"></i>
                                                    </button>
                                                    -->
                                                    
                                                    <!-- New QR Scanner Check-in Button -->
                                                    <button onclick="openQRScannerModal(<?php echo $appointment['appointment_id']; ?>)"
                                                        class="btn btn-sm btn-success" title="Scan QR Code to Check In">
                                                        <i class="fas fa-qrcode"></i> Scan
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- QR Scanner Check In Modal -->
    <div id="qrScannerModal" class="modal">
        <div class="modal-content" style="max-width: 900px; max-height: 95vh;">
            <div class="modal-header">
                <h3><i class="fas fa-qrcode"></i> Scan Appointment QR Code</h3>
                <button type="button" class="close" onclick="closeQRScannerModal()">&times;</button>
            </div>
            <div class="modal-body" style="max-height: calc(95vh - 140px); overflow-y: auto; padding: 15px;">
                <!-- Scanner Section -->
                <div id="scannerSection">
                    <div style="text-align: center; margin-bottom: 20px;">
                        <h4 style="color: #0077b6; margin-bottom: 8px; font-size: 1.1rem;">
                            <i class="fas fa-camera" style="margin-right: 8px;"></i>
                            Scan Patient's Appointment QR Code
                        </h4>
                        <p style="color: #666; margin: 0; font-size: 0.9rem;">Position the QR code within the camera frame to verify and check in the patient</p>
                    </div>
                    
                    <!-- Camera View Container -->
                    <div class="scanner-grid">
                        <!-- Camera Section -->
                        <div class="scanner-camera-section">
                            <h5 style="margin-bottom: 12px; color: #0077b6; text-align: center; font-size: 1rem;">
                                <i class="fas fa-video"></i> Camera Scanner
                            </h5>
                            <div id="cameraContainer" style="position: relative; text-align: center; margin-bottom: 12px;">
                                <div id="qr-reader" style="width: 400px; height: 400px; margin: 0 auto; border: 2px solid #ddd; border-radius: 8px; background: #f8f9fa;"></div>
                            </div>
                            
                            <!-- Scanner Controls -->
                            <div style="text-align: center;">
                                <button type="button" id="startScanBtn" class="btn btn-primary btn-sm" onclick="startQRScanner()">
                                    <i class="fas fa-camera"></i> Start Camera
                                </button>
                                <button type="button" id="stopScanBtn" class="btn btn-secondary btn-sm" onclick="stopQRScanner()" style="display: none;">
                                    <i class="fas fa-stop"></i> Stop Camera
                                </button>
                                <br>
                                <button type="button" class="btn btn-info btn-sm" onclick="debugCamera()" style="margin-top: 8px; font-size: 0.75rem; padding: 4px 8px;">
                                    <i class="fas fa-bug"></i> Debug
                                </button>
                            </div>
                        </div>
                        
                        <!-- Manual Input Section -->
                        <div class="scanner-manual-section">
                            <h5 style="margin-bottom: 12px; color: #0077b6; text-align: center; font-size: 1rem;">
                                <i class="fas fa-keyboard"></i> Manual Entry
                            </h5>
                            <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; height: 240px; display: flex; flex-direction: column; justify-content: center;">
                                <p style="color: #666; font-size: 0.8rem; margin-bottom: 12px; text-align: center;">
                                    If QR code is not available or camera access is denied
                                </p>
                                <div style="margin-bottom: 12px;">
                                    <label for="manualAppointmentId" style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.85rem;">
                                        Appointment ID:
                                    </label>
                                    <input type="text" id="manualAppointmentId" class="search-input" 
                                           placeholder="Enter appointment ID (e.g., APT-00000123)" 
                                           style="width: 100%; font-size: 0.85rem; padding: 8px 12px;">
                                </div>
                                <button type="button" class="btn btn-info btn-sm" onclick="verifyManualEntry()" style="width: 100%;">
                                    <i class="fas fa-check"></i> Verify Appointment
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Scan Result -->
                    <div id="scanResult" style="display: none; margin-bottom: 15px;"></div>
                </div>
                
                <!-- Priority Selection Section (hidden initially) -->
                <div id="prioritySection" style="display: none;">
                    <div style="text-align: center; margin-bottom: 15px; padding: 15px; background: #e8f5e9; border-radius: 8px; border-left: 4px solid #28a745;">
                        <h4 style="margin: 0 0 8px 0; color: #155724; font-size: 1.1rem;">
                            <i class="fas fa-check-circle" style="margin-right: 8px;"></i>Appointment Verified Successfully!
                        </h4>
                        <div id="verifiedPatientInfo" style="color: #155724; font-weight: 500; font-size: 0.9rem;"></div>
                    </div>
                    
                    <!-- Priority Selection -->
                    <div style="background: #f8f9fa; padding: 25px; border-radius: 8px; margin-bottom: 20px;">
                        <h5 style="margin-bottom: 10px; color: #0077b6; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-list-ol"></i> Select Queue Priority
                        </h5>
                        <p style="color: #666; font-size: 0.9rem; margin-bottom: 20px;">
                            Choose the appropriate priority level and special category for this patient
                        </p>
                        
                        <!-- Standard Priority Levels -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                            <label class="priority-option">
                                <input type="radio" name="queuePriority" value="emergency">
                                <div class="priority-card emergency">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <div>
                                        <strong>Emergency</strong>
                                        <span>Life-threatening conditions, immediate attention required</span>
                                    </div>
                                </div>
                            </label>
                            
                            <label class="priority-option">
                                <input type="radio" name="queuePriority" value="urgent">
                                <div class="priority-card urgent">
                                    <i class="fas fa-clock"></i>
                                    <div>
                                        <strong>Urgent</strong>
                                        <span>Serious conditions, should be seen within 30 minutes</span>
                                    </div>
                                </div>
                            </label>
                            
                            <label class="priority-option">
                                <input type="radio" name="queuePriority" value="standard" checked>
                                <div class="priority-card standard">
                                    <i class="fas fa-user"></i>
                                    <div>
                                        <strong>Standard</strong>
                                        <span>Regular consultation, normal queue order</span>
                                    </div>
                                </div>
                            </label>
                            
                            <label class="priority-option">
                                <input type="radio" name="queuePriority" value="low">
                                <div class="priority-card low">
                                    <i class="fas fa-calendar-check"></i>
                                    <div>
                                        <strong>Low Priority</strong>
                                        <span>Follow-up visits, non-urgent consultations</span>
                                    </div>
                                </div>
                            </label>
                        </div>
                        
                        <!-- Special Categories -->
                        <div style="margin-bottom: 20px;">
                            <h6 style="margin-bottom: 15px; color: #0077b6; font-size: 1rem; border-bottom: 1px solid #dee2e6; padding-bottom: 8px;">
                                <i class="fas fa-heart"></i> Special Categories (Optional)
                            </h6>
                            <div class="special-categories" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px;">
                                <label class="special-category-option" style="display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: 6px; background: white; border: 1px solid #e9ecef; transition: all 0.2s ease; cursor: pointer;">
                                    <input type="checkbox" name="specialCategory" value="senior" style="margin: 0;">
                                    <i class="fas fa-user-clock" style="color: #6c757d; font-size: 1.1rem;"></i>
                                    <span style="font-size: 0.9rem; font-weight: 500;">Senior Citizen</span>
                                </label>
                                
                                <label class="special-category-option" style="display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: 6px; background: white; border: 1px solid #e9ecef; transition: all 0.2s ease; cursor: pointer;">
                                    <input type="checkbox" name="specialCategory" value="pwd" style="margin: 0;">
                                    <i class="fas fa-wheelchair" style="color: #6c757d; font-size: 1.1rem;"></i>
                                    <span style="font-size: 0.9rem; font-weight: 500;">PWD</span>
                                </label>
                                
                                <label class="special-category-option" style="display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: 6px; background: white; border: 1px solid #e9ecef; transition: all 0.2s ease; cursor: pointer;">
                                    <input type="checkbox" name="specialCategory" value="pregnant" style="margin: 0;">
                                    <i class="fas fa-baby" style="color: #6c757d; font-size: 1.1rem;"></i>
                                    <span style="font-size: 0.9rem; font-weight: 500;">Pregnant</span>
                                </label>
                                
                                <label class="special-category-option" style="display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: 6px; background: white; border: 1px solid #e9ecef; transition: all 0.2s ease; cursor: pointer;">
                                    <input type="checkbox" name="specialCategory" value="injured" style="margin: 0;">
                                    <i class="fas fa-band-aid" style="color: #6c757d; font-size: 1.1rem;"></i>
                                    <span style="font-size: 0.9rem; font-weight: 500;">Injured</span>
                                </label>
                                
                                <label class="special-category-option" style="display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: 6px; background: white; border: 1px solid #e9ecef; transition: all 0.2s ease; cursor: pointer;">
                                    <input type="checkbox" name="specialCategory" value="special_needs" style="margin: 0;">
                                    <i class="fas fa-hands-helping" style="color: #6c757d; font-size: 1.1rem;"></i>
                                    <span style="font-size: 0.9rem; font-weight: 500;">Special Needs</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Triage Station Selection -->
                    <div style="background: #f8f9fa; padding: 25px; border-radius: 8px; margin-bottom: 20px;">
                        <h5 style="margin-bottom: 10px; color: #0077b6; display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-clinic-medical"></i> Select Triage Station
                        </h5>
                        <p style="color: #666; font-size: 0.9rem; margin-bottom: 20px;">
                            Choose a specific triage station or use automatic load balancing
                        </p>
                        
                        <!-- Station Selection Grid -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            <!-- Auto Assignment (Default) -->
                            <label class="station-option">
                                <input type="radio" name="triageStation" value="auto" checked>
                                <div class="station-card auto">
                                    <div class="station-icon">
                                        <i class="fas fa-balance-scale"></i>
                                    </div>
                                    <div class="station-info">
                                        <strong>Auto Assignment</strong>
                                        <span>System selects station with shortest queue</span>
                                        <div class="queue-info" id="autoQueueInfo">Loading...</div>
                                    </div>
                                </div>
                            </label>
                            
                            <!-- Station 1 -->
                            <label class="station-option">
                                <input type="radio" name="triageStation" value="1">
                                <div class="station-card station1">
                                    <div class="station-icon">
                                        <i class="fas fa-stethoscope"></i>
                                    </div>
                                    <div class="station-info">
                                        <strong>Triage Station 1</strong>
                                        <span>Primary triage assessment</span>
                                        <div class="queue-info" id="station1QueueInfo">Loading...</div>
                                    </div>
                                </div>
                            </label>
                            
                            <!-- Station 2 -->
                            <label class="station-option">
                                <input type="radio" name="triageStation" value="2">
                                <div class="station-card station2">
                                    <div class="station-icon">
                                        <i class="fas fa-user-md"></i>
                                    </div>
                                    <div class="station-info">
                                        <strong>Triage Station 2</strong>
                                        <span>Secondary triage assessment</span>
                                        <div class="queue-info" id="station2QueueInfo">Loading...</div>
                                    </div>
                                </div>
                            </label>
                            
                            <!-- Station 3 -->
                            <label class="station-option">
                                <input type="radio" name="triageStation" value="3">
                                <div class="station-card station3">
                                    <div class="station-icon">
                                        <i class="fas fa-heartbeat"></i>
                                    </div>
                                    <div class="station-info">
                                        <strong>Triage Station 3</strong>
                                        <span>Tertiary triage assessment</span>
                                        <div class="queue-info" id="station3QueueInfo">Loading...</div>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Additional Notes -->
                    <div style="margin-bottom: 20px;">
                        <label for="checkInNotes" style="display: block; margin-bottom: 8px; font-weight: 600; color: #0077b6;">
                            <i class="fas fa-sticky-note"></i> Additional Notes (Optional):
                        </label>
                        <textarea id="checkInNotes" class="search-input" rows="3" 
                                  placeholder="Any special instructions or observations..." style="width: 100%; resize: vertical;"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeQRScannerModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" id="confirmCheckInBtn" class="btn btn-success" 
                        onclick="confirmCheckIn()" style="display: none;">
                    <i class="fas fa-sign-in-alt"></i> Confirm Check-in
                </button>
            </div>
        </div>
    </div>

    <!-- Error Modal -->
    <div id="errorModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #dc3545, #c82333); color: white;">
                <h3><i class="fas fa-exclamation-triangle"></i> Error</h3>
                <button type="button" class="close" onclick="closeErrorModal()">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center; padding: 30px 20px;">
                <div style="font-size: 3rem; color: #dc3545; margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div id="errorMessage" style="font-size: 1rem; line-height: 1.6; color: #333;">
                    <!-- Error message will be inserted here -->
                </div>
            </div>
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn btn-danger" onclick="closeErrorModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #28a745, #20c997); color: white;">
                <h3><i class="fas fa-check-circle"></i> Check-in Successful!</h3>
                <button type="button" class="close" onclick="closeSuccessModal()">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center; padding: 30px 20px;">
                <div style="font-size: 4rem; color: #28a745; margin-bottom: 20px;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div id="successMessage" style="font-size: 1.1rem; line-height: 1.6; color: #333;">
                    <!-- Success message will be inserted here -->
                </div>
            </div>
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn btn-success" onclick="closeSuccessModal()">
                    <i class="fas fa-check"></i> Continue
                </button>
            </div>
        </div>
    </div>

    <!-- Original Check In Modal (kept for backward compatibility) -->
    <div id="checkInModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-sign-in-alt"></i> Check In Patient</h3>
                <button type="button" class="close" onclick="closeModal('checkInModal')">&times;</button>
            </div>
            <form id="checkInForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="checkin_appointment">
                    <input type="hidden" name="appointment_id" id="checkInAppointmentId">

                    <div style="text-align: center; margin-bottom: 20px;">
                        <i class="fas fa-sign-in-alt" style="font-size: 48px; color: var(--success); margin-bottom: 15px;"></i>
                        <h4>Confirm Patient Check-in</h4>
                        <p>Are you sure you want to check in this patient?</p>
                    </div>

                    <div style="background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 8px;">
                        <i class="fas fa-info-circle"></i>
                        <strong>Note:</strong> Once checked in, the patient will be moved to the queue for their appointment.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('checkInModal')">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-sign-in-alt"></i> Check In
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Appointment Modal -->
    <div id="viewAppointmentModal" class="modal">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-check"></i> Appointment Details</h3>
                <button type="button" class="close" onclick="closeModal('viewAppointmentModal')">&times;</button>
            </div>
            <div class="modal-body" id="appointmentDetailsContent">
                <!-- Content will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('viewAppointmentModal')">Close</button>
                <button type="button" class="btn btn-primary" onclick="verifyFromViewModal()">
                    <i class="fas fa-qrcode"></i> Verify Appointment
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentAppointmentId = null;
        let html5QrCode = null;
        let verifiedAppointmentData = null;

        // Original check-in function (kept for backward compatibility)
        function checkInAppointment(appointmentId) {
            currentAppointmentId = appointmentId;
            document.getElementById('checkInAppointmentId').value = appointmentId;
            document.getElementById('checkInModal').style.display = 'block';
        }

        // New QR Scanner Modal Functions
        function openQRScannerModal(appointmentId) {
            currentAppointmentId = appointmentId;
            document.getElementById('qrScannerModal').style.display = 'block';
            
            // Reset modal state
            document.getElementById('scannerSection').style.display = 'block';
            document.getElementById('prioritySection').style.display = 'none';
            document.getElementById('confirmCheckInBtn').style.display = 'none';
            document.getElementById('scanResult').style.display = 'none';
            document.getElementById('manualAppointmentId').value = '';
            
            // Reset priority selection to standard
            document.querySelector('input[name="queuePriority"][value="standard"]').checked = true;
            
            // Reset station selection to auto
            document.querySelector('input[name="triageStation"][value="auto"]').checked = true;
            
            // Load current queue information
            loadQueueInformation();
        }

        function closeQRScannerModal() {
            if (html5QrCode && html5QrCode.isScanning) {
                html5QrCode.stop().then(() => {
                    document.getElementById('qrScannerModal').style.display = 'none';
                }).catch(err => {
                    console.error('Error stopping scanner:', err);
                    document.getElementById('qrScannerModal').style.display = 'none';
                });
            } else {
                document.getElementById('qrScannerModal').style.display = 'none';
            }
            
            // Reset scanner state
            document.getElementById('startScanBtn').style.display = 'inline-block';
            document.getElementById('stopScanBtn').style.display = 'none';
            verifiedAppointmentData = null;
        }

        function startQRScanner() {
            console.log('Starting QR Scanner...');
            
            // Check if Html5Qrcode is available
            if (typeof Html5Qrcode === 'undefined') {
                console.error('Html5Qrcode library not loaded');
                showScannerError('QR Scanner library not loaded. Please use manual entry.');
                return;
            }

            // Get the QR reader container element
            const qrReaderElement = document.getElementById('qr-reader');
            if (!qrReaderElement) {
                console.error('QR reader container not found');
                showScannerError('QR reader container not found.');
                return;
            }

            // Show loading state
            const scanResult = document.getElementById('scanResult');
            scanResult.innerHTML = `
                <div class="scanner-status scanning">
                    <i class="fas fa-spinner fa-spin"></i>
                    <strong>Initializing Camera...</strong>
                    <p style="margin: 5px 0;">Please allow camera access when prompted</p>
                </div>
            `;
            scanResult.style.display = 'block';

            // Create new instance if needed
            if (!html5QrCode) {
                try {
                    html5QrCode = new Html5Qrcode("qr-reader");
                    console.log('Html5Qrcode instance created');
                } catch (error) {
                    console.error('Error creating Html5Qrcode instance:', error);
                    showScannerError('Failed to initialize scanner.');
                    return;
                }
            }

            // Camera configuration
            const config = {
                fps: 10,
                qrbox: { width: 200, height: 200 },
                aspectRatio: 1.0,
                showTorchButtonIfSupported: true,
                videoConstraints: {
                    facingMode: "environment"
                }
            };

            // Get available cameras first
            Html5Qrcode.getCameras().then(cameras => {
                console.log('Available cameras:', cameras);
                
                if (cameras && cameras.length > 0) {
                    // Use back camera if available, otherwise use first camera
                    const cameraId = cameras.length > 1 ? cameras[1].id : cameras[0].id;
                    
                    console.log('Using camera:', cameraId);
                    
                    // Start scanning
                    html5QrCode.start(
                        cameraId,
                        config,
                        (decodedText, decodedResult) => {
                            console.log('QR Code scanned:', decodedText);
                            handleQRScanResult(decodedText);
                        },
                        (errorMessage) => {
                            // Scanning errors are frequent and normal, don't log them
                        }
                    ).then(() => {
                        console.log('Scanner started successfully');
                        document.getElementById('startScanBtn').style.display = 'none';
                        document.getElementById('stopScanBtn').style.display = 'inline-block';
                        
                        // Add active class to camera container
                        document.getElementById('cameraContainer').classList.add('active');
                        
                        // Show active status
                        scanResult.innerHTML = `
                            <div class="scanner-status scanning">
                                <i class="fas fa-camera"></i>
                                <strong>Camera Active</strong>
                                <p style="margin: 5px 0;">Position the QR code within the frame to scan</p>
                            </div>
                        `;
                    }).catch(err => {
                        console.error('Error starting camera:', err);
                        showScannerError(`Camera error: ${err.message || 'Unable to access camera. Please check permissions.'}`);
                    });
                } else {
                    console.warn('No cameras found');
                    showScannerError('No cameras found on this device.');
                }
            }).catch(err => {
                console.error('Error getting cameras:', err);
                showScannerError('Unable to access camera. Please check permissions and try again.');
            });
        }

        function showScannerError(message) {
            const scanResult = document.getElementById('scanResult');
            scanResult.innerHTML = `
                <div class="scanner-status error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Camera Error</strong>
                    <p style="margin: 5px 0;">${message}</p>
                    <button type="button" class="btn btn-sm btn-info" onclick="debugCamera()" style="margin-top: 10px;">
                        <i class="fas fa-bug"></i> Debug Info
                    </button>
                </div>
            `;
            scanResult.style.display = 'block';
            
            // Hide error after 10 seconds
            setTimeout(() => {
                if (scanResult.querySelector('.scanner-status.error')) {
                    scanResult.style.display = 'none';
                }
            }, 10000);
        }

        function debugCamera() {
            console.log('=== Camera Debug Info ===');
            console.log('Html5Qrcode available:', typeof Html5Qrcode !== 'undefined');
            console.log('Navigator mediaDevices:', !!navigator.mediaDevices);
            console.log('getUserMedia available:', !!navigator.mediaDevices?.getUserMedia);
            console.log('HTTPS:', location.protocol === 'https:');
            console.log('Localhost:', location.hostname === 'localhost' || location.hostname === '127.0.0.1');
            
            // Check camera permissions
            if (navigator.permissions) {
                navigator.permissions.query({ name: 'camera' }).then(result => {
                    console.log('Camera permission:', result.state);
                }).catch(err => {
                    console.log('Camera permission check failed:', err);
                });
            }
            
            // Try to get cameras
            if (typeof Html5Qrcode !== 'undefined') {
                Html5Qrcode.getCameras().then(cameras => {
                    console.log('Available cameras:', cameras);
                }).catch(err => {
                    console.log('Error getting cameras:', err);
                });
            }
            
            alert('Debug info logged to console. Press F12 and check the Console tab.');
        }

        function stopQRScanner() {
            if (html5QrCode && html5QrCode.isScanning) {
                html5QrCode.stop().then(() => {
                    document.getElementById('startScanBtn').style.display = 'inline-block';
                    document.getElementById('stopScanBtn').style.display = 'none';
                    document.getElementById('scanResult').style.display = 'none';
                    
                    // Remove active class from camera container
                    document.getElementById('cameraContainer').classList.remove('active');
                }).catch(err => {
                    console.error('Error stopping scanner:', err);
                });
            }
        }

        function handleQRScanResult(qrData) {
            console.log('QR Code scanned:', qrData);
            
            // Stop the scanner
            stopQRScanner();
            
            // Parse QR data
            let appointmentData;
            try {
                // Try to parse as JSON first (proper QR codes)
                appointmentData = JSON.parse(qrData);
                console.log('Parsed QR data:', appointmentData);
                
                // Ensure we have the required fields for QR verification
                if (appointmentData.appointment_id && (appointmentData.qr_token || appointmentData.verification_code)) {
                    console.log('Valid QR code with token detected');
                    // Standardize the token field name
                    if (appointmentData.verification_code && !appointmentData.qr_token) {
                        appointmentData.qr_token = appointmentData.verification_code;
                    }
                } else {
                    console.log('QR code missing required verification token');
                    appointmentData.qr_token = null; // Mark as invalid QR
                }
            } catch (e) {
                // If not JSON, treat as plain appointment ID (legacy or manual)
                console.log('QR data is not JSON, treating as basic appointment ID');
                const cleanId = qrData.replace(/^APT-/i, '');
                appointmentData = { 
                    appointment_id: cleanId,
                    qr_token: null // No token = requires additional verification
                };
            }
            
            // Display scan result
            const scanResult = document.getElementById('scanResult');
            if (appointmentData.qr_token) {
                scanResult.innerHTML = `
                    <div class="alert alert-info">
                        <i class="fas fa-qrcode"></i> <strong>QR Code Detected</strong><br>
                        <small>Verifying authenticity...</small>
                    </div>
                `;
            } else {
                scanResult.innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-qrcode"></i> <strong>Basic QR Code Detected</strong><br>
                        <small>Additional verification required for security...</small>
                    </div>
                `;
            }
            scanResult.style.display = 'block';
            
            // Verify the scanned appointment
            verifyScannedAppointment(appointmentData);
        }

        function verifyScannedAppointment(appointmentData) {
            console.log('Verifying appointment:', appointmentData);
            console.log('Current appointment ID:', currentAppointmentId);
            
            // Check if this is from QR scan (has qr_token) or manual entry
            const isQRScan = appointmentData.qr_token || appointmentData.verification_code;
            const scannedAppointmentId = parseInt(appointmentData.appointment_id);
            
            console.log('Scanned appointment ID (parsed):', scannedAppointmentId);
            console.log('Is QR scan:', isQRScan);
            console.log('Comparison result:', scannedAppointmentId === currentAppointmentId);
            
            if (scannedAppointmentId === currentAppointmentId) {
                if (isQRScan) {
                    // QR scan - verify the token against database
                    verifyQRToken(appointmentData);
                } else {
                    // Manual entry - require additional verification
                    requireAdditionalVerification(appointmentData);
                }
            } else {
                // No match - show error
                console.log('Appointment mismatch detected!');
                showAppointmentMismatch(appointmentData, scannedAppointmentId);
            }
        }

        function verifyQRToken(appointmentData) {
            console.log('Verifying QR token for appointment:', appointmentData);
            
            // Show loading state
            const scanResult = document.getElementById('scanResult');
            scanResult.innerHTML = `
                <div class="scanner-status scanning">
                    <i class="fas fa-spinner fa-spin"></i>
                    <strong>Verifying QR Code...</strong>
                    <p>Checking authenticity against database</p>
                </div>
            `;
            
            // Verify token with the server
            const formData = new FormData();
            formData.append('action', 'verify_qr_token');
            formData.append('appointment_id', currentAppointmentId);
            formData.append('qr_token', appointmentData.qr_token || appointmentData.verification_code);
            
            fetch('../../api/verify_appointment_qr.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('QR token verified successfully');
                    showAppointmentMatch(appointmentData);
                } else {
                    console.log('QR token verification failed:', data.message);
                    showQRVerificationError(data.message || 'Invalid QR code - this may be forged or expired');
                }
            })
            .catch(error => {
                console.error('QR verification error:', error);
                showQRVerificationError('Unable to verify QR code. Please try manual entry.');
            });
        }

        function requireAdditionalVerification(appointmentData) {
            console.log('Requiring verification code for manual entry');
            
            const scanResult = document.getElementById('scanResult');
            scanResult.innerHTML = `
                <div class="verification-required">
                    <div class="alert alert-warning">
                        <i class="fas fa-shield-alt"></i> <strong>Verification Required</strong><br>
                        <small>Please enter the verification code from your appointment confirmation</small>
                    </div>
                    
                    <div class="verification-form">
                        <div class="form-group" style="margin: 15px 0;">
                            <label for="verificationCodeInput" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                <i class="fas fa-key"></i> Verification Code:
                            </label>
                            <input type="text" 
                                   id="verificationCodeInput" 
                                   placeholder="Enter 8-character code (e.g., A1B2C3D4)"
                                   style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; font-size: 16px; text-transform: uppercase; letter-spacing: 2px; text-align: center; font-family: monospace;"
                                   maxlength="8"
                                   pattern="[A-Z0-9]{8}">
                            <small style="display: block; margin-top: 5px; color: #666;">
                                <i class="fas fa-info-circle"></i> Find this code in your appointment confirmation or QR code
                            </small>
                        </div>
                        
                        <div style="background: #e3f2fd; padding: 12px; border-radius: 6px; margin: 15px 0; font-size: 14px;">
                            <i class="fas fa-lightbulb" style="color: #1976d2;"></i> 
                            <strong>Where to find your verification code:</strong>
                            <ul style="margin: 8px 0 0 20px; padding: 0;">
                                <li>In your appointment confirmation email/SMS</li>
                                <li>On your printed appointment slip</li>
                                <li>Ask staff if you can't locate it</li>
                            </ul>
                        </div>
                        
                        <div style="text-align: center; margin-top: 20px;">
                            <button type="button" 
                                    class="btn btn-primary" 
                                    onclick="processVerificationCode()"
                                    style="padding: 12px 25px; font-size: 16px;">
                                <i class="fas fa-check"></i> Verify Code
                            </button>
                            <button type="button" 
                                    class="btn btn-secondary" 
                                    onclick="cancelVerification()"
                                    style="padding: 12px 25px; margin-left: 10px; font-size: 16px;">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                        
                        <div style="text-align: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                            <button type="button" 
                                    class="btn btn-link" 
                                    onclick="requestStaffHelp()"
                                    style="color: #007bff; text-decoration: none; font-size: 14px;">
                                <i class="fas fa-hands-helping"></i> Need staff assistance?
                            </button>
                        </div>
                    </div>
                </div>
            `;
            
            scanResult.style.display = 'block';
            
            // Auto-format and validate input
            document.getElementById('verificationCodeInput').addEventListener('input', function(e) {
                let value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
                e.target.value = value;
                
                // Enable/disable verify button based on length
                const verifyBtn = document.querySelector('button[onclick="processVerificationCode()"]');
                if (value.length === 8) {
                    verifyBtn.style.background = '#28a745';
                    verifyBtn.disabled = false;
                } else {
                    verifyBtn.style.background = '#6c757d';
                    verifyBtn.disabled = false; // Keep enabled for partial validation
                }
            });
            
            // Allow Enter key to submit
            document.getElementById('verificationCodeInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    processVerificationCode();
                }
            });
            
            // Focus the input
            setTimeout(() => {
                document.getElementById('verificationCodeInput').focus();
            }, 100);
        }

        function processVerificationCode() {
            const verificationCode = document.getElementById('verificationCodeInput').value.trim().toUpperCase();
            
            if (!verificationCode) {
                showVerificationError('Please enter a verification code');
                return;
            }
            
            if (verificationCode.length !== 8) {
                showVerificationError('Verification code must be exactly 8 characters');
                return;
            }
            
            // Show loading state
            const scanResult = document.getElementById('scanResult');
            scanResult.innerHTML = `
                <div class="scanner-status scanning">
                    <i class="fas fa-spinner fa-spin"></i>
                    <strong>Verifying Code...</strong>
                    <p>Checking verification code against appointment records</p>
                </div>
            `;
            
            // Verify code with the server
            const formData = new FormData();
            formData.append('action', 'verify_verification_code');
            formData.append('appointment_id', currentAppointmentId);
            formData.append('verification_code', verificationCode);
            
            fetch('../../api/verify_appointment_qr.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Verification code verified successfully');
                    showAppointmentMatch({ appointment_id: currentAppointmentId, verification_method: 'verification_code' });
                } else {
                    console.log('Verification code verification failed:', data.message);
                    showCodeVerificationError(data.message || 'Invalid verification code');
                }
            })
            .catch(error => {
                console.error('Verification code error:', error);
                showCodeVerificationError('Unable to verify code. Please try again or contact staff.');
            });
        }

        function showCodeVerificationError(message) {
            const scanResult = document.getElementById('scanResult');
            scanResult.innerHTML = `
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <strong>❌ Invalid Verification Code!</strong><br>
                    <small>${message}</small>
                    <div style="margin-top: 15px;">
                        <button type="button" 
                                class="btn btn-info btn-sm" 
                                onclick="requireAdditionalVerification({ appointment_id: currentAppointmentId })"
                                style="padding: 8px 15px;">
                            <i class="fas fa-redo"></i> Try Again
                        </button>
                        <button type="button" 
                                class="btn btn-warning btn-sm" 
                                onclick="requestStaffHelp()"
                                style="padding: 8px 15px; margin-left: 10px;">
                            <i class="fas fa-hands-helping"></i> Get Help
                        </button>
                    </div>
                </div>
            `;
            
            scanResult.style.display = 'block';
        }

        function requestStaffHelp() {
            const scanResult = document.getElementById('scanResult');
            scanResult.innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> <strong>Staff Assistance Required</strong><br>
                    <p>Please ask a staff member to help locate your verification code.</p>
                    <div style="background: #e3f2fd; padding: 12px; border-radius: 4px; margin: 10px 0;">
                        <strong>For Staff:</strong> The verification code can be found in the appointment database 
                        or can be regenerated if necessary. Use admin verification if the patient cannot locate their code.
                    </div>
                    <div style="margin-top: 15px;">
                        <button type="button" 
                                class="btn btn-secondary" 
                                onclick="document.getElementById('scanResult').style.display = 'none'"
                                style="padding: 8px 15px;">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </div>
            `;
        }

        function cancelVerification() {
            document.getElementById('scanResult').style.display = 'none';
        }

        function showQRVerificationError(message) {
            const scanResult = document.getElementById('scanResult');
            scanResult.innerHTML = `
                <div class="alert alert-error" style="display: block; background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 8px; margin: 10px 0;">
                    <i class="fas fa-exclamation-triangle"></i> <strong>❌ Invalid QR Code!</strong><br>
                    <small>${message}</small>
                    <div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 4px; border: 1px solid #ffeaa7; color: #856404;">
                        <strong>Security Notice:</strong> Only scan QR codes directly from the appointment confirmation.
                        For manual entry, additional verification will be required.
                    </div>
                </div>
            `;
            
            // Make sure the scan result is visible
            scanResult.style.display = 'block';
            
            // Allow user to try again or use manual entry
            setTimeout(() => {
                const currentContent = scanResult.innerHTML;
                if (currentContent.includes('Invalid QR Code')) {
                    scanResult.innerHTML = currentContent + `
                        <div style="text-align: center; margin-top: 15px;">
                            <button type="button" 
                                    class="btn btn-info" 
                                    onclick="document.getElementById('manualTab').click()"
                                    style="padding: 10px 20px; margin-right: 10px;">
                                <i class="fas fa-keyboard"></i> Use Manual Entry
                            </button>
                            <button type="button" 
                                    class="btn btn-secondary" 
                                    onclick="document.getElementById('scanResult').style.display = 'none'"
                                    style="padding: 10px 20px;">
                                <i class="fas fa-times"></i> Close
                            </button>
                        </div>
                    `;
                }
            }, 2000);
        }

        function showPatientVerificationError(message) {
            const scanResult = document.getElementById('scanResult');
            scanResult.innerHTML = `
                <div class="alert alert-error">
                    <i class="fas fa-user-times"></i> <strong>❌ Verification Failed!</strong><br>
                    <small>${message}</small>
                    <div style="margin-top: 15px;">
                        <button type="button" 
                                class="btn btn-info btn-sm" 
                                onclick="requireAdditionalVerification({ appointment_id: currentAppointmentId })"
                                style="padding: 8px 15px;">
                            <i class="fas fa-redo"></i> Try Again
                        </button>
                        <button type="button" 
                                class="btn btn-warning btn-sm" 
                                onclick="contactStaffForHelp()"
                                style="padding: 8px 15px; margin-left: 10px;">
                            <i class="fas fa-hands-helping"></i> Get Staff Help
                        </button>
                    </div>
                </div>
            `;
            
            scanResult.style.display = 'block';
        }

        function contactStaffForHelp() {
            const scanResult = document.getElementById('scanResult');
            scanResult.innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> <strong>Staff Assistance Required</strong><br>
                    <p>Please ask a staff member to help with manual verification.</p>
                    <div style="background: #e3f2fd; padding: 10px; border-radius: 4px; margin-top: 10px;">
                        <strong>Staff Note:</strong> Use admin override function for manual verification when patient details cannot be confirmed.
                    </div>
                    <div style="margin-top: 15px;">
                        <button type="button" 
                                class="btn btn-secondary" 
                                onclick="document.getElementById('scanResult').style.display = 'none'"
                                style="padding: 8px 15px;">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </div>
            `;
        }

        function showAppointmentMatch(appointmentData) {
            // Update scan result to show success
            const scanResult = document.getElementById('scanResult');
            scanResult.innerHTML = `
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <strong>✅ Appointment Verified!</strong><br>
                    <small>QR code matches the selected appointment (ID: ${appointmentData.appointment_id})</small>
                </div>
            `;
            
            // Get appointment details for display
            fetch(`../../api/get_appointment_details.php?appointment_id=${currentAppointmentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        verifiedAppointmentData = data.appointment;
                        showEnhancedPrioritySelection();
                    } else {
                        showVerificationError('Failed to load appointment details');
                    }
                })
                .catch(error => {
                    console.error('Error fetching appointment details:', error);
                    showVerificationError('Error loading appointment details');
                });
        }

        function showAppointmentMismatch(appointmentData, scannedId) {
            console.log('Showing appointment mismatch error');
            
            // Show mismatch error
            const scanResult = document.getElementById('scanResult');
            scanResult.innerHTML = `
                <div class="alert alert-error" style="display: block; background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 8px; margin: 10px 0;">
                    <i class="fas fa-exclamation-triangle"></i> <strong>❌ Appointment Mismatch!</strong><br>
                    <small>Expected: Appointment ${currentAppointmentId}<br>
                    Scanned: Appointment ${scannedId}<br>
                    Please scan the correct QR code or use manual entry.</small>
                    
                    <div style="text-align: center; margin-top: 15px;">
                        <button type="button" 
                                class="btn btn-info" 
                                onclick="document.getElementById('manualTab').click(); document.getElementById('scanResult').style.display = 'none';"
                                style="padding: 8px 15px; margin-right: 10px; background: #17a2b8; color: white; border: none; border-radius: 4px;">
                            <i class="fas fa-keyboard"></i> Use Manual Entry
                        </button>
                        <button type="button" 
                                class="btn btn-secondary" 
                                onclick="document.getElementById('scanResult').style.display = 'none'"
                                style="padding: 8px 15px; background: #6c757d; color: white; border: none; border-radius: 4px;">
                            <i class="fas fa-times"></i> Close
                        </button>
                    </div>
                </div>
            `;
            
            // Make sure the scan result is visible
            scanResult.style.display = 'block';
            
            // Auto-hide after 15 seconds instead of 8
            setTimeout(() => {
                if (document.getElementById('scanResult').innerHTML.includes('Appointment Mismatch')) {
                    console.log('Auto-hiding mismatch error message');
                    document.getElementById('scanResult').style.display = 'none';
                }
            }, 15000);
        }

        function showEnhancedPrioritySelection() {
            // Hide scanner section
            document.getElementById('scannerSection').style.display = 'none';
            
            // Show patient info
            const patientInfo = `
                <strong>${verifiedAppointmentData.patient_name}</strong><br>
                Appointment ID: ${verifiedAppointmentData.appointment_id ? 'APT-' + String(verifiedAppointmentData.appointment_id).padStart(8, '0') : 'N/A'}<br>
                Date: ${verifiedAppointmentData.appointment_date} at ${verifiedAppointmentData.time_slot}
            `;
            document.getElementById('verifiedPatientInfo').innerHTML = patientInfo;
            
            // Show priority section with enhanced categories
            document.getElementById('prioritySection').style.display = 'block';
            document.getElementById('confirmCheckInBtn').style.display = 'inline-block';
        }

        function verifyManualEntry() {
            const appointmentId = document.getElementById('manualAppointmentId').value.trim();
            if (!appointmentId) {
                showVerificationError('Please enter an appointment ID');
                return;
            }
            
            // Remove APT- prefix if present and extract number
            const cleanId = appointmentId.replace(/^APT-/i, '');
            const appointmentData = { 
                appointment_id: cleanId,
                qr_token: null // No QR token = requires additional verification
            };
            
            // Verify the manually entered appointment
            verifyScannedAppointment(appointmentData);
        }

        function verifyAppointment(appointmentData) {
            // Show loading
            const scanResult = document.getElementById('scanResult');
            scanResult.innerHTML = `
                <div class="scanner-status scanning">
                    <i class="fas fa-spinner fa-spin"></i>
                    <strong>Verifying appointment...</strong>
                </div>
            `;
            scanResult.style.display = 'block';
            
            // Extract appointment ID from QR data (assume it contains appointment ID)
            let appointmentId = appointmentData;
            
            // If QR contains more data, extract the appointment ID
            if (appointmentData.includes('appointment_id=')) {
                const match = appointmentData.match(/appointment_id=(\d+)/);
                if (match) {
                    appointmentId = match[1];
                }
            } else if (appointmentData.startsWith('APT-')) {
                appointmentId = appointmentData.replace('APT-', '');
            }
            
            // Verify against the current appointment ID
            if (parseInt(appointmentId) === currentAppointmentId) {
                // Fetch appointment details for verification
                fetch(`../../api/get_appointment_details.php?appointment_id=${currentAppointmentId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            verifiedAppointmentData = data.appointment;
                            showPrioritySelection();
                        } else {
                            showVerificationError('Appointment not found or invalid');
                        }
                    })
                    .catch(error => {
                        console.error('Verification error:', error);
                        showVerificationError('Network error during verification');
                    });
            } else {
                showVerificationError('QR code does not match the selected appointment');
            }
        }

        function showPrioritySelection() {
            // Hide scanner section
            document.getElementById('scannerSection').style.display = 'none';
            
            // Show patient info
            const patientInfo = `
                <strong>${verifiedAppointmentData.patient_name}</strong><br>
                Appointment ID: ${verifiedAppointmentData.appointment_id ? 'APT-' + String(verifiedAppointmentData.appointment_id).padStart(8, '0') : 'N/A'}<br>
                Date: ${verifiedAppointmentData.appointment_date} at ${verifiedAppointmentData.time_slot}
            `;
            document.getElementById('verifiedPatientInfo').innerHTML = patientInfo;
            
            // Show priority section
            document.getElementById('prioritySection').style.display = 'block';
            document.getElementById('confirmCheckInBtn').style.display = 'inline-block';
        }

        function showVerificationError(message) {
            const scanResult = document.getElementById('scanResult');
            scanResult.innerHTML = `
                <div class="scanner-status error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Verification Failed</strong>
                    <p>${message}</p>
                </div>
            `;
            scanResult.style.display = 'block';
            
            // Allow user to try again
            setTimeout(() => {
                document.getElementById('scanResult').style.display = 'none';
            }, 5000);
        }

        function confirmCheckIn() {
            const selectedPriority = document.querySelector('input[name="queuePriority"]:checked').value;
            const selectedStation = document.querySelector('input[name="triageStation"]:checked').value;
            const notes = document.getElementById('checkInNotes').value.trim();
            
            // Get selected special categories
            const specialCategories = Array.from(document.querySelectorAll('input[name="specialCategory"]:checked'))
                .map(checkbox => checkbox.value);
            
            // Create enhanced notes with special categories
            let enhancedNotes = notes;
            if (specialCategories.length > 0) {
                const categoriesText = specialCategories.map(cat => {
                    switch(cat) {
                        case 'senior': return 'Senior Citizen';
                        case 'pwd': return 'PWD';
                        case 'pregnant': return 'Pregnant';
                        case 'injured': return 'Injured';
                        case 'special_needs': return 'Special Needs';
                        default: return cat;
                    }
                }).join(', ');
                
                enhancedNotes = `Special Categories: ${categoriesText}${notes ? '\n\nAdditional Notes: ' + notes : ''}`;
            }
            
            // Prepare check-in data
            const checkInData = {
                action: 'checkin_appointment_with_priority',
                appointment_id: currentAppointmentId,
                priority: selectedPriority,
                triage_station: selectedStation, // Add selected station
                special_categories: specialCategories.join(','),
                notes: enhancedNotes
            };
            
            // Show loading state
            const confirmBtn = document.getElementById('confirmCheckInBtn');
            const originalText = confirmBtn.innerHTML;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            confirmBtn.disabled = true;
            
            // Submit check-in using fetch to handle response properly
            fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(checkInData)
            })
            .then(response => {
                // Parse the response text to check for success/error messages
                return response.text();
            })
            .then(responseText => {
                // Check if the response contains success or error indicators
                if (responseText.includes('Patient successfully checked in') || 
                    responseText.includes('alert-success') ||
                    (responseText.includes('Queue Number:') && !responseText.includes('alert-error'))) {
                    
                    // Success - extract queue number if possible
                    let queueNumber = 'Unknown';
                    const queueMatch = responseText.match(/Queue Number:\s*([A-Z0-9-]+)/i);
                    if (queueMatch) {
                        queueNumber = queueMatch[1];
                    }
                    
                    // Close modal and show success
                    closeQRScannerModal();
                    
                    // Determine the station (you can update this logic later)
                    let queueStation = 'Triage Station'; // Default station
                    
                    // Enhanced station assignment logic
                    if (selectedPriority === 'emergency') {
                        queueStation = 'Emergency Station';
                    } else if (specialCategories.includes('injured')) {
                        queueStation = 'Trauma/Injury Station';
                    } else if (specialCategories.includes('pregnant')) {
                        queueStation = 'Maternal Care Station';
                    } else if (specialCategories.includes('senior') || specialCategories.includes('pwd')) {
                        queueStation = 'Priority Care Station';
                    } else {
                        queueStation = 'General Triage Station';
                    }
                    
                    // Show success modal with queue number
                    showSuccessModalWithQueue(selectedPriority, specialCategories, queueStation, queueNumber);
                    
                } else if (responseText.includes('alert-error') || 
                          responseText.includes('Error during check-in') ||
                          responseText.includes('Failed to')) {
                    
                    // Extract error message from response
                    let errorMessage = 'Unknown error occurred during check-in';
                    
                    // Try to extract specific error message
                    const errorMatch = responseText.match(/Error during check-in:\s*([^<]+)/i);
                    if (errorMatch) {
                        errorMessage = errorMatch[1].trim();
                    } else {
                        // Look for other error patterns
                        const alertMatch = responseText.match(/<div[^>]*class="[^"]*alert-error[^"]*"[^>]*>.*?<\/div>/is);
                        if (alertMatch) {
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = alertMatch[0];
                            errorMessage = tempDiv.textContent.trim().replace(/^\s*⚠️?\s*/, '');
                        }
                    }
                    
                    // Show error modal
                    showErrorModal(errorMessage);
                    
                } else {
                    // Unclear response - assume success but warn user
                    closeQRScannerModal();
                    showSuccessModalWithQueue(selectedPriority, specialCategories, 'General Station', 'Please check with staff');
                }
                
                // Reset button state
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
                
            })
            .catch(error => {
                console.error('Check-in fetch error:', error);
                
                // Show error in modal
                showErrorModal('Network error occurred during check-in. Please try again.');
                
                // Reset button state
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
            });
        }

        function viewAppointment(appointmentId) {
            currentAppointmentId = appointmentId;
            
            // Show loading
            document.getElementById('appointmentDetailsContent').innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 24px; color: var(--primary);"></i>
                    <p>Loading appointment details...</p>
                </div>
            `;
            document.getElementById('viewAppointmentModal').style.display = 'block';

            // Get appointment data
            fetch(`../../api/get_appointment_details.php?appointment_id=${appointmentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayAppointmentDetails(data.appointment);
                    } else {
                        document.getElementById('appointmentDetailsContent').innerHTML = `
                            <div style="text-align: center; padding: 20px; color: var(--danger);">
                                <i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 10px;"></i>
                                <p>Error loading appointment details: ${data.error || 'Unknown error'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('appointmentDetailsContent').innerHTML = `
                        <div style="text-align: center; padding: 20px; color: var(--danger);">
                            <i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 10px;"></i>
                            <p>Error loading appointment details. Please try again.</p>
                        </div>
                    `;
                });
        }

        // Enhanced appointment details display function
        function displayAppointmentDetails(appointment) {
            // Debug: Log the appointment data to see what we're receiving
            console.log('Appointment data received:', appointment);
            console.log('Visit details:', appointment.visit_details);
            console.log('Vitals data:', appointment.vitals);

            const content = `
                <div class="appointment-details-grid">
                    <div class="details-section">
                        <h4><i class="fas fa-user"></i> Patient Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Name:</span>
                            <span class="detail-value">${appointment.patient_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Patient ID:</span>
                            <span class="detail-value">${appointment.patient_id || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Contact:</span>
                            <span class="detail-value">${appointment.contact_number || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Age/Sex:</span>
                            <span class="detail-value">${(appointment.age || 'N/A')}/${(appointment.sex || 'N/A')}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Barangay:</span>
                            <span class="detail-value">${appointment.barangay_name || 'N/A'}</span>
                        </div>
                    </div>
                    
                    <div class="details-section">
                        <h4><i class="fas fa-calendar"></i> Appointment Details</h4>
                        <div class="detail-item">
                            <span class="detail-label">Appointment ID:</span>
                            <span class="detail-value highlight">APT-${appointment.appointment_id ? String(appointment.appointment_id).padStart(8, '0') : 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Date:</span>
                            <span class="detail-value">${appointment.appointment_date || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Time Slot:</span>
                            <span class="detail-value highlight">${appointment.time_slot || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status:</span>
                            <span class="detail-value">${appointment.status || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Facility:</span>
                            <span class="detail-value">${appointment.facility_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Service:</span>
                            <span class="detail-value">${appointment.service_name || 'General Consultation'}</span>
                        </div>
                    </div>
                </div>
                
                <div class="details-section">
                    <h4><i class="fas fa-file-medical"></i> Referral Information</h4>
                        ${appointment.referral_id ? `
                            <div class="detail-item">
                                <span class="detail-label">Referral ID:</span>
                                <span class="detail-value highlight">${appointment.referral_id}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Referral Status:</span>
                                <span class="detail-value" style="color: #28a745; font-weight: 600;">Required</span>
                            </div>
                        ` : `
                            <div class="detail-item">
                                <span class="detail-label">Referral Status:</span>
                                <span class="detail-value" style="color: #6c757d;">This appointment did not need a referral</span>
                            </div>
                        `}
                </div>
                
                <div class="details-section">
                    <h4><i class="fas fa-qrcode"></i> QR Code Information</h4>
                        ${appointment.qr_code_path ? `
                            <div class="detail-item">
                                <span class="detail-label">QR Code:</span>
                                <span class="detail-value" style="color: #28a745; font-weight: 600;">Available</span>
                            </div>
                            <div class="detail-item" style="margin-top: 10px;justify-content: center;">
                                <div class="qr-code-container" style="text-align: center;">
                                    <img src="data:image/png;base64,${appointment.qr_code_path}" 
                                         alt="Appointment QR Code">
                                    <p class="qr-code-description">Scan this QR code for quick appointment access</p>
                                </div>
                            </div>
                        ` : `
                            <div class="detail-item">
                                <span class="detail-label">QR Code:</span>
                                <span class="detail-value" style="color: #6c757d;">No QR code generated for this appointment</span>
                            </div>
                        `}
                </div>

                ${((appointment.status || '').toLowerCase() === 'completed' || (appointment.status || '').toLowerCase() === 'cancelled') && appointment.visit_details ? `
                    <div class="details-section" style="border-left: 4px solid #17a2b8; background: #f8f9fa; margin-top: 20px;">
                        <h4><i class="fas fa-clipboard-check" style="color: #17a2b8;"></i> Visit Details</h4>
                        <div class="detail-item">
                            <span class="detail-label">Check-in Time:</span>
                            <span class="detail-value">${appointment.visit_details.time_in || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Check-out Time:</span>
                            <span class="detail-value">${appointment.visit_details.time_out || 'Not completed'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Attending Employee:</span>
                            <span class="detail-value">${appointment.visit_details.attending_employee_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Attendance Status:</span>
                            <span class="detail-value">${formatAttendanceStatus(appointment.visit_details.attendance_status) || 'N/A'}</span>
                        </div>
                        ${appointment.visit_details.remarks ? `
                            <div class="detail-item">
                                <span class="detail-label">Visit Remarks:</span>
                                <span class="detail-value">${appointment.visit_details.remarks}</span>
                            </div>
                        ` : ''}
                    </div>
                ` : ''}

                ${((appointment.status || '').toLowerCase() === 'completed' || (appointment.status || '').toLowerCase() === 'cancelled') && appointment.vitals ? `
                    <div class="details-section" style="border-left: 4px solid #28a745; background: #f8fff8; margin-top: 20px;">
                        <h4><i class="fas fa-heartbeat" style="color: #28a745;"></i> Recorded Vitals</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                            ${appointment.vitals.systolic_bp || appointment.vitals.diastolic_bp ? `
                                <div class="vitals-group">
                                    <h6 style="color: #0077b6; margin-bottom: 8px; font-size: 14px;">
                                        <i class="fas fa-tint"></i> Blood Pressure
                                        ${getBloodPressureStatus(appointment.vitals.systolic_bp, appointment.vitals.diastolic_bp)}
                                    </h6>
                                    <div class="detail-item">
                                        <span class="detail-label">Systolic:</span>
                                        <span class="detail-value">${appointment.vitals.systolic_bp || 'N/A'} mmHg</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Diastolic:</span>
                                        <span class="detail-value">${appointment.vitals.diastolic_bp || 'N/A'} mmHg</span>
                                    </div>
                                </div>
                            ` : ''}
                            
                            ${appointment.vitals.heart_rate || appointment.vitals.respiratory_rate ? `
                                <div class="vitals-group">
                                    <h6 style="color: #0077b6; margin-bottom: 8px; font-size: 14px;">
                                        <i class="fas fa-heart"></i> Heart & Respiratory
                                        ${getHeartRespiratoryStatus(appointment.vitals.heart_rate, appointment.vitals.respiratory_rate)}
                                    </h6>
                                    ${appointment.vitals.heart_rate ? `
                                        <div class="detail-item">
                                            <span class="detail-label">Heart Rate:</span>
                                            <span class="detail-value">${appointment.vitals.heart_rate} bpm</span>
                                        </div>
                                    ` : ''}
                                    ${appointment.vitals.respiratory_rate ? `
                                        <div class="detail-item">
                                            <span class="detail-label">Respiratory Rate:</span>
                                            <span class="detail-value">${appointment.vitals.respiratory_rate} breaths/min</span>
                                        </div>
                                    ` : ''}
                                </div>
                            ` : ''}
                            
                            ${appointment.vitals.temperature ? `
                                <div class="vitals-group">
                                    <h6 style="color: #0077b6; margin-bottom: 8px; font-size: 14px;">
                                        <i class="fas fa-thermometer-half"></i> Temperature
                                        ${getTemperatureStatus(appointment.vitals.temperature)}
                                    </h6>
                                    <div class="detail-item">
                                        <span class="detail-label">Temperature:</span>
                                        <span class="detail-value">${appointment.vitals.temperature}°C</span>
                                    </div>
                                </div>
                            ` : ''}
                            
                            ${appointment.vitals.weight || appointment.vitals.height ? `
                                <div class="vitals-group">
                                    <h6 style="color: #0077b6; margin-bottom: 8px; font-size: 14px;">
                                        <i class="fas fa-weight"></i> Physical Measurements
                                        ${appointment.vitals.weight && appointment.vitals.height ? getBMIStatus(appointment.vitals.weight, appointment.vitals.height) : ''}
                                    </h6>
                                    ${appointment.vitals.weight ? `
                                        <div class="detail-item">
                                            <span class="detail-label">Weight:</span>
                                            <span class="detail-value">${appointment.vitals.weight} kg</span>
                                        </div>
                                    ` : ''}
                                    ${appointment.vitals.height ? `
                                        <div class="detail-item">
                                            <span class="detail-label">Height:</span>
                                            <span class="detail-value">${appointment.vitals.height} cm</span>
                                        </div>
                                    ` : ''}
                                    ${appointment.vitals.weight && appointment.vitals.height ? `
                                        <div class="detail-item">
                                            <span class="detail-label">BMI:</span>
                                            <span class="detail-value">${((appointment.vitals.weight / Math.pow(appointment.vitals.height / 100, 2)).toFixed(1))} kg/m²</span>
                                        </div>
                                    ` : ''}
                                </div>
                            ` : ''}
                        </div>
                        
                        ${appointment.vitals.remarks ? `
                            <div class="detail-item" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e9ecef;">
                                <span class="detail-label">Vitals Remarks:</span>
                                <span class="detail-value">${appointment.vitals.remarks}</span>
                            </div>
                        ` : ''}
                        
                        <div class="detail-item" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #e9ecef;">
                            <span class="detail-label">Recorded At:</span>
                            <span class="detail-value" style="color: #6c757d; font-size: 0.9em;">${appointment.vitals.recorded_at || 'N/A'}</span>
                        </div>
                    </div>
                ` : (((appointment.status || '').toLowerCase() === 'completed' || (appointment.status || '').toLowerCase() === 'cancelled')) ? `
                    <div class="details-section" style="border-left: 4px solid #ffc107; background: #fffef7; margin-top: 20px;">
                        <h4><i class="fas fa-exclamation-triangle" style="color: #ffc107;"></i> Vitals Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Vitals Status:</span>
                            <span class="detail-value" style="color: #856404;">No vitals were recorded for this visit</span>
                        </div>
                    </div>
                ` : ''}
                
                ${appointment.cancel_reason ? `
                    <div class="details-section" style="border-left: 4px solid #dc3545; background: #fff5f5; margin-top: 20px;">
                        <h4><i class="fas fa-times-circle" style="color: #dc3545;"></i> Cancellation Details</h4>
                        <div class="detail-item">
                            <span class="detail-label">Reason:</span>
                            <span class="detail-value">${appointment.cancel_reason}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Cancelled At:</span>
                            <span class="detail-value">${appointment.cancelled_at || 'N/A'}</span>
                        </div>
                    </div>
                ` : ''}
            `;

            document.getElementById('appointmentDetailsContent').innerHTML = content;
        }

        // Helper functions for vitals status indicators
        function formatAttendanceStatus(status) {
            if (!status) return null;

            const statusMap = {
                'early': 'Early Arrival',
                'on_time': 'On Time',
                'late': 'Late Arrival',
                'no_show': 'No Show',
                'left_early': 'Left Early'
            };

            return statusMap[status] || status.charAt(0).toUpperCase() + status.slice(1);
        }

        function getBloodPressureStatus(systolic, diastolic) {
            if (!systolic && !diastolic) return '';

            let status = '';
            let className = '';
            let icon = '';

            if (systolic && diastolic) {
                // Both values present - full assessment
                if (systolic >= 180 || diastolic >= 120) {
                    status = 'CRITICAL HIGH';
                    className = 'critical';
                    icon = '⚠️';
                } else if (systolic >= 140 || diastolic >= 90) {
                    status = 'HIGH';
                    className = 'high';
                    icon = '⬆️';
                } else if (systolic < 90 || diastolic < 60) {
                    status = 'LOW';
                    className = 'low';
                    icon = '⬇️';
                } else {
                    status = 'NORMAL';
                    className = 'normal';
                    icon = '✓';
                }
            } else if (systolic) {
                // Only systolic
                if (systolic >= 180) {
                    status = 'SYS CRITICAL';
                    className = 'critical';
                    icon = '⚠️';
                } else if (systolic >= 140) {
                    status = 'SYS HIGH';
                    className = 'high';
                    icon = '⬆️';
                } else if (systolic < 90) {
                    status = 'SYS LOW';
                    className = 'low';
                    icon = '⬇️';
                } else {
                    status = 'SYS NORMAL';
                    className = 'normal';
                    icon = '✓';
                }
            } else if (diastolic) {
                // Only diastolic
                if (diastolic >= 120) {
                    status = 'DIA CRITICAL';
                    className = 'critical';
                    icon = '⚠️';
                } else if (diastolic >= 90) {
                    status = 'DIA HIGH';
                    className = 'high';
                    icon = '⬆️';
                } else if (diastolic < 60) {
                    status = 'DIA LOW';
                    className = 'low';
                    icon = '⬇️';
                } else {
                    status = 'DIA NORMAL';
                    className = 'normal';
                    icon = '✓';
                }
            }

            return `<span class="vital-status ${className}" style="margin-left: 8px; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 2px;">${icon} ${status}</span>`;
        }

        function getHeartRespiratoryStatus(heartRate, respRate) {
            if (!heartRate && !respRate) return '';

            let status = '';
            let className = '';
            let icon = '';

            // Check heart rate (60-100 bpm normal for adults)
            let heartStatus = '';
            if (heartRate) {
                if (heartRate < 60) {
                    heartStatus = 'HR LOW';
                    className = 'low';
                } else if (heartRate > 100) {
                    heartStatus = 'HR HIGH';
                    className = className === 'low' ? 'high' : (className || 'high');
                } else {
                    heartStatus = 'HR NORMAL';
                    className = className || 'normal';
                }
            }

            // Check respiratory rate (12-20 breaths/min normal for adults)
            let respStatus = '';
            if (respRate) {
                if (respRate < 12) {
                    respStatus = 'RR LOW';
                    className = className === 'normal' ? 'low' : (className || 'low');
                } else if (respRate > 20) {
                    respStatus = 'RR HIGH';
                    className = 'high';
                } else {
                    respStatus = 'RR NORMAL';
                    className = className === 'low' || className === 'high' ? className : 'normal';
                }
            }

            // Combine statuses
            if (heartStatus && respStatus) {
                if (className === 'normal') {
                    status = 'NORMAL';
                    icon = '✓';
                } else if (className === 'high') {
                    status = 'ELEVATED';
                    icon = '⬆️';
                } else {
                    status = 'LOW';
                    icon = '⬇️';
                }
            } else {
                status = heartStatus || respStatus;
                icon = className === 'normal' ? '✓' : (className === 'high' ? '⬆️' : '⬇️');
            }

            return `<span class="vital-status ${className}" style="margin-left: 8px; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 2px;">${icon} ${status}</span>`;
        }

        function getTemperatureStatus(temp) {
            if (!temp) return '';

            let status = '';
            let className = '';
            let icon = '';

            // Normal temperature range: 36.1°C - 37.2°C (97°F - 99°F)
            if (temp >= 39.0) {
                status = 'HIGH FEVER';
                className = 'critical';
                icon = '🔥';
            } else if (temp >= 38.0) {
                status = 'FEVER';
                className = 'high';
                icon = '⬆️';
            } else if (temp >= 37.3) {
                status = 'ELEVATED';
                className = 'high';
                icon = '⬆️';
            } else if (temp >= 36.1) {
                status = 'NORMAL';
                className = 'normal';
                icon = '✓';
            } else if (temp >= 35.0) {
                status = 'LOW';
                className = 'low';
                icon = '⬇️';
            } else {
                status = 'HYPOTHERMIA';
                className = 'critical';
                icon = '❄️';
            }

            return `<span class="vital-status ${className}" style="margin-left: 8px; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 2px;">${icon} ${status}</span>`;
        }

        function getBMIStatus(weight, height) {
            if (!weight || !height || weight <= 0 || height <= 0) return '';

            const heightInMeters = height / 100;
            const bmi = (weight / (heightInMeters * heightInMeters)).toFixed(1);

            let status = '';
            let className = '';
            let icon = '';

            if (bmi < 18.5) {
                status = 'UNDERWEIGHT';
                className = 'low';
                icon = '⬇️';
            } else if (bmi < 25) {
                status = 'NORMAL BMI';
                className = 'normal';
                icon = '✓';
            } else if (bmi < 30) {
                status = 'OVERWEIGHT';
                className = 'high';
                icon = '⬆️';
            } else if (bmi < 35) {
                status = 'OBESE I';
                className = 'high';
                icon = '⚠️';
            } else if (bmi < 40) {
                status = 'OBESE II';
                className = 'critical';
                icon = '⚠️';
            } else {
                status = 'OBESE III';
                className = 'critical';
                icon = '🚨';
            }

            return `<span class="vital-status ${className}" style="margin-left: 8px; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 2px;">${icon} ${status}</span>`;
        }

        function getBadgeClass(status) {
            switch (status) {
                case 'confirmed': return 'success';
                case 'checked_in': return 'warning';
                case 'completed': return 'primary';
                case 'cancelled': return 'danger';
                default: return 'info';
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function showSuccessModal(priority, specialCategories, station = 'Triage') {
            const categoryText = specialCategories.length > 0 ? 
                specialCategories.map(cat => {
                    switch(cat) {
                        case 'senior': return 'Senior Citizen';
                        case 'pwd': return 'PWD';
                        case 'pregnant': return 'Pregnant';
                        case 'injured': return 'Injured';
                        case 'special_needs': return 'Special Needs';
                        default: return cat;
                    }
                }).join(', ') : '';

            const specialCategoryDisplay = categoryText ? `<br><strong>Special Categories:</strong> ${categoryText}` : '';
            
            const successMessage = `
                <h4 style="color: #28a745; margin-bottom: 15px;">Patient Successfully Checked In!</h4>
                <div style="background: #f8fff8; border: 1px solid #c3e6cb; border-radius: 8px; padding: 15px; margin: 15px 0;">
                    <strong>Priority Level:</strong> ${priority.charAt(0).toUpperCase() + priority.slice(1)}${specialCategoryDisplay}
                    <br><strong>Queue Station:</strong> ${station}
                </div>
                <p style="color: #666; font-size: 0.9rem; margin-top: 15px;">
                    The patient has been added to the queue and will be called when it's their turn.
                </p>
            `;
            
            document.getElementById('successMessage').innerHTML = successMessage;
            document.getElementById('successModal').style.display = 'block';
        }

        function showSuccessModalWithQueue(priority, specialCategories, station = 'Triage', queueNumber = 'Unknown') {
            const categoryText = specialCategories.length > 0 ? 
                specialCategories.map(cat => {
                    switch(cat) {
                        case 'senior': return 'Senior Citizen';
                        case 'pwd': return 'PWD';
                        case 'pregnant': return 'Pregnant';
                        case 'injured': return 'Injured';
                        case 'special_needs': return 'Special Needs';
                        default: return cat;
                    }
                }).join(', ') : '';

            const specialCategoryDisplay = categoryText ? `<br><strong>Special Categories:</strong> ${categoryText}` : '';
            
            const successMessage = `
                <h4 style="color: #28a745; margin-bottom: 15px;">Patient Successfully Checked In!</h4>
                <div style="background: #f8fff8; border: 1px solid #c3e6cb; border-radius: 8px; padding: 15px; margin: 15px 0;">
                    <strong>Priority Level:</strong> ${priority.charAt(0).toUpperCase() + priority.slice(1)}${specialCategoryDisplay}
                    <br><strong>Queue Station:</strong> ${station}
                    <br><strong>Queue Number:</strong> <span style="background: #0077b6; color: white; padding: 4px 8px; border-radius: 12px; font-weight: bold;">${queueNumber}</span>
                </div>
                <div style="background: #e3f2fd; border: 1px solid #90caf9; border-radius: 8px; padding: 12px; margin: 15px 0; text-align: center;">
                    <i class="fas fa-info-circle" style="color: #1976d2; margin-right: 8px;"></i>
                    <strong style="color: #1976d2;">Please keep the queue number for reference</strong>
                </div>
                <p style="color: #666; font-size: 0.9rem; margin-top: 15px;">
                    The patient has been added to the queue and will be called when it's their turn.
                </p>
            `;
            
            document.getElementById('successMessage').innerHTML = successMessage;
            document.getElementById('successModal').style.display = 'block';
        }

        function closeSuccessModal() {
            document.getElementById('successModal').style.display = 'none';
            location.reload(); // Refresh the page to show updated appointment list
        }

        function showErrorModal(message) {
            document.getElementById('errorMessage').innerHTML = message;
            document.getElementById('errorModal').style.display = 'block';
        }

        function closeErrorModal() {
            document.getElementById('errorModal').style.display = 'none';
        }

        function verifyFromViewModal() {
            // Close the view appointment modal
            closeModal('viewAppointmentModal');
            
            // Small delay to ensure smooth transition between modals
            setTimeout(() => {
                // Open the QR scanner modal with the current appointment ID
                openQRScannerModal(currentAppointmentId);
            }, 100);
        }

        // Load current queue information for station selection
        function loadQueueInformation() {
            // Create a simple AJAX request to get queue counts
            fetch('../../api/get_queue_counts.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateQueueDisplay(data.queues);
                    } else {
                        console.error('Failed to load queue information:', data.message);
                        // Set default "Loading..." text
                        document.getElementById('autoQueueInfo').textContent = 'Unable to load';
                        document.getElementById('station1QueueInfo').textContent = 'Unable to load';
                        document.getElementById('station2QueueInfo').textContent = 'Unable to load';
                        document.getElementById('station3QueueInfo').textContent = 'Unable to load';
                    }
                })
                .catch(error => {
                    console.error('Error loading queue information:', error);
                    // Set error state
                    document.getElementById('autoQueueInfo').textContent = 'Error loading';
                    document.getElementById('station1QueueInfo').textContent = 'Error loading';
                    document.getElementById('station2QueueInfo').textContent = 'Error loading';
                    document.getElementById('station3QueueInfo').textContent = 'Error loading';
                });
        }

        // Update queue display with current queue counts
        function updateQueueDisplay(queues) {
            const stations = ['1', '2', '3'];
            let optimalStation = 1;
            let minQueue = Infinity;

            stations.forEach(station => {
                const count = queues['station_' + station] || 0;
                const element = document.getElementById('station' + station + 'QueueInfo');
                
                if (element) {
                    let className = 'low';
                    let status = 'Available';
                    
                    if (count === 0) {
                        className = 'optimal';
                        status = 'No Wait';
                    } else if (count <= 2) {
                        className = 'low';
                        status = 'Short Wait';
                    } else if (count <= 5) {
                        className = 'medium';
                        status = 'Moderate Wait';
                    } else {
                        className = 'high';
                        status = 'Long Wait';
                    }
                    
                    element.className = 'queue-info ' + className;
                    element.textContent = count + ' waiting • ' + status;
                }
                
                // Find optimal station
                if (count < minQueue) {
                    minQueue = count;
                    optimalStation = parseInt(station);
                }
            });
            
            // Update auto assignment display
            const autoElement = document.getElementById('autoQueueInfo');
            if (autoElement) {
                autoElement.className = 'queue-info optimal';
                autoElement.textContent = `Will assign to Station ${optimalStation} (${minQueue} waiting)`;
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
    </script>
</body>
</html>
<?php
// Flush output buffer at the end
if (ob_get_level()) {
    ob_end_flush();
}
?>
