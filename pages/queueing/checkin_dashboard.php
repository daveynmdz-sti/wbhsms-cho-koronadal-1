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
        try {
            $stmt = $pdo->prepare("UPDATE appointments SET status = 'checked_in', updated_at = NOW() WHERE appointment_id = ? AND status = 'confirmed'");
            if ($stmt->execute([$appointment_id])) {
                $message = "Patient successfully checked in!";
            } else {
                $error = "Failed to check in patient.";
            }
        } catch (Exception $e) {
            error_log('Check-in error: ' . $e->getMessage());
            $error = "Error checking in patient.";
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
            margin: 50px auto;
            border-radius: var(--border-radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            max-width: 600px;
            width: 90%;
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
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
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
                                                    <button onclick="checkInAppointment(<?php echo $appointment['appointment_id']; ?>)"
                                                        class="btn btn-sm btn-success" title="Check In Patient">
                                                        <i class="fas fa-sign-in-alt"></i>
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

    <!-- Check In Modal -->
    <div id="checkInModal" class="modal">
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
            </div>
        </div>
    </div>

    <script>
        let currentAppointmentId = null;

        function checkInAppointment(appointmentId) {
            currentAppointmentId = appointmentId;
            document.getElementById('checkInAppointmentId').value = appointmentId;
            document.getElementById('checkInModal').style.display = 'block';
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
                        const appointment = data.appointment;
                        document.getElementById('appointmentDetailsContent').innerHTML = `
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                <div>
                                    <h5>Patient Information</h5>
                                    <p><strong>Name:</strong> ${appointment.patient_name}</p>
                                    <p><strong>Patient ID:</strong> ${appointment.patient_id_display}</p>
                                    <p><strong>Contact:</strong> ${appointment.contact_number || 'N/A'}</p>
                                </div>
                                <div>
                                    <h5>Appointment Information</h5>
                                    <p><strong>Appointment ID:</strong> APT-${appointment.appointment_id.toString().padStart(8, '0')}</p>
                                    <p><strong>Date:</strong> ${new Date(appointment.scheduled_date).toLocaleDateString()}</p>
                                    <p><strong>Time:</strong> ${new Date('2000-01-01 ' + appointment.scheduled_time).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</p>
                                    <p><strong>Status:</strong> <span class="badge badge-${getBadgeClass(appointment.status)}">${appointment.status.charAt(0).toUpperCase() + appointment.status.slice(1)}</span></p>
                                </div>
                            </div>
                        `;
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

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Auto-refresh every 30 seconds to keep data current
        setInterval(function() {
            if (!document.querySelector('.modal').style.display || document.querySelector('.modal').style.display === 'none') {
                location.reload();
            }
        }, 30000);
    </script>
</body>
</html>
<?php
// Flush output buffer at the end
if (ob_get_level()) {
    ob_end_flush();
}
?>
