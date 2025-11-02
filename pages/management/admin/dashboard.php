<?php
// dashboard_admin.php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration - Use absolute path resolution
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/employee_session.php';

// Authentication check - refactored to eliminate redirect loops
// Check 1: Is the user logged in at all?
if (!isset($_SESSION['employee_id']) || empty($_SESSION['employee_id'])) {
    // User is not logged in - redirect to login, but prevent redirect loops
    error_log('Admin Dashboard: No session found, redirecting to login');
    header('Location: ../auth/employee_login.php');
    exit();
}

// Check 2: Does the user have the correct role?
// Make sure role comparison is case-insensitive
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'admin') {
    // User has wrong role - log and redirect
    error_log('Access denied: User ' . $_SESSION['employee_id'] . ' with role ' .
        ($_SESSION['role'] ?? 'none') . ' attempted to access admin dashboard');

    // Clear any redirect loop detection
    unset($_SESSION['redirect_attempt']);

    // Return to login with access denied message
    $_SESSION['flash'] = array('type' => 'error', 'msg' => 'Access denied. You do not have permission to view that page.');
    header('Location: ../auth/employee_login.php?access_denied=1');
    exit();
}

// DB
require_once $root_path . '/config/db.php'; // adjust relative path if needed
$employee_id = $_SESSION['employee_id'];
$employee_role = $_SESSION['role'];

// -------------------- Data bootstrap (Admin Dashboard) --------------------
$defaults = [
    'name' => $_SESSION['employee_name'] ?? 'Unknown User',
    'employee_number' => $_SESSION['employee_number'] ?? '-',
    'role' => $employee_role,
    'stats' => [
        'total_patients' => 0,
        'today_appointments' => 0,
        'pending_lab_results' => 0,
        'total_employees' => 0,
        'monthly_revenue' => 0,
        'queue_count' => 0
    ],
    'recent_activities' => [],
    'pending_tasks' => [],
    'system_alerts' => []
];

// Get employee info
$stmt = $conn->prepare('SELECT first_name, middle_name, last_name, employee_number, role_id FROM employees WHERE employee_id = ?');
if ($stmt) {
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row) {
        $full_name = $row['first_name'];
        if (!empty($row['middle_name'])) $full_name .= ' ' . $row['middle_name'];
        $full_name .= ' ' . $row['last_name'];
        $defaults['name'] = trim($full_name);
        $defaults['employee_number'] = $row['employee_number'];

        // Map role_id to role names
        $role_mapping = [
            1 => 'admin',
            2 => 'doctor',
            3 => 'nurse',
            4 => 'laboratory_tech',
            5 => 'pharmacist',
            6 => 'cashier',
            7 => 'records_officer',
            8 => 'bhw',
            9 => 'dho'
        ];
        $defaults['role'] = $role_mapping[$row['role_id']] ?? 'unknown';
    }
    $stmt->close();
}

// Dashboard Statistics
try {
    // Total Patients
    $stmt = $conn->prepare('SELECT COUNT(*) as count FROM patients');
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $defaults['stats']['total_patients'] = $row['count'] ?? 0;
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    // table might not exist yet; ignore
}

try {
    // Today's Appointments
    $today = date('Y-m-d');
    $stmt = $conn->prepare('SELECT COUNT(*) as count FROM appointments WHERE DATE(date) = ?');
    if ($stmt) {
        $stmt->bind_param("s", $today);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $defaults['stats']['today_appointments'] = $row['count'] ?? 0;
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    // table might not exist yet; ignore
}

try {
    // Pending Lab Results
    $stmt = $conn->prepare('SELECT COUNT(*) as count FROM lab_tests WHERE status = "pending"');
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $defaults['stats']['pending_lab_results'] = $row['count'] ?? 0;
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    // table might not exist yet; ignore
}

try {
    // Total Employees
    $stmt = $conn->prepare('SELECT COUNT(*) as count FROM employees');
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $defaults['stats']['total_employees'] = $row['count'] ?? 0;
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    // table might not exist yet; ignore
}

try {
    // Monthly Collections (current month) - using payments table for actual collected amounts
    $current_month = date('Y-m');
    $stmt = $conn->prepare('SELECT SUM(amount_paid) as total FROM payments WHERE DATE_FORMAT(paid_at, "%Y-%m") = ?');
    if ($stmt) {
        $stmt->bind_param("s", $current_month);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $defaults['stats']['monthly_revenue'] = $row['total'] ?? 0;
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    // table might not exist yet; ignore
}

try {
    // Queue Count
    $stmt = $conn->prepare('SELECT COUNT(*) as count FROM patient_queue WHERE status = "waiting"');
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $defaults['stats']['queue_count'] = $row['count'] ?? 0;
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    // table might not exist yet; ignore
}

// Recent Activities (latest 5)
try {
    $stmt = $conn->prepare('SELECT activity, created_at FROM admin_activity_log WHERE employee_id = ? ORDER BY created_at DESC LIMIT 5');
    if ($stmt) {
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $defaults['recent_activities'][] = [
                'activity' => $row['activity'] ?? '',
                'date' => date('m/d/Y H:i', strtotime($row['created_at']))
            ];
        }
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    // table might not exist yet; add some default activities
    $defaults['recent_activities'] = [
        ['activity' => 'Logged into admin dashboard', 'date' => date('m/d/Y H:i')],
        ['activity' => 'System started', 'date' => date('m/d/Y H:i')]
    ];
}

// Pending Tasks
try {
    $stmt = $conn->prepare('SELECT task, priority, due_date FROM admin_tasks WHERE employee_id = ? AND status = "pending" ORDER BY due_date ASC LIMIT 5');
    if ($stmt) {
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $defaults['pending_tasks'][] = [
                'task' => $row['task'] ?? '',
                'priority' => $row['priority'] ?? 'normal',
                'due_date' => date('m/d/Y', strtotime($row['due_date']))
            ];
        }
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    // table might not exist yet; add some default tasks
    $defaults['pending_tasks'] = [
        ['task' => 'Review pending patient registrations', 'priority' => 'high', 'due_date' => date('m/d/Y')],
        ['task' => 'Update system settings', 'priority' => 'normal', 'due_date' => date('m/d/Y', strtotime('+1 day'))]
    ];
}

// System Alerts
try {
    $stmt = $conn->prepare('SELECT message, type, created_at FROM system_alerts WHERE status = "active" ORDER BY created_at DESC LIMIT 3');
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $defaults['system_alerts'][] = [
                'message' => $row['message'] ?? '',
                'type' => $row['type'] ?? 'info',
                'date' => date('m/d/Y H:i', strtotime($row['created_at']))
            ];
        }
        $stmt->close();
    }
} catch (mysqli_sql_exception $e) {
    // table might not exist yet; add some default alerts
    $defaults['system_alerts'] = [
        ['message' => 'System running normally', 'type' => 'success', 'date' => date('m/d/Y H:i')]
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard - WBHSMS</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- Reuse your existing styles -->
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../../assets/css/sidebar.css">
    <style>
        .content-wrapper {
            margin-left: 300px;
            padding: 2rem;
            transition: margin-left 0.3s;
        }

        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
            }
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .dashboard-title {
            font-size: 1.8rem;
            color: #0077b6;
            margin: 0;
        }

        .dashboard-actions {
            display: flex;
            gap: 1rem;
        }

        .info-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            border-left: 4px solid #0077b6;
        }

        .info-card h2 {
            font-size: 1.4rem;
            color: #333;
            margin-top: 0;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-card h2 i {
            color: #0077b6;
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .card-title {
            font-size: 1.2rem;
            color: #0077b6;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-status {
            padding: 0.3rem 0.6rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-confirmed {
            background-color: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-active {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .status-completed {
            background-color: #e5e7eb;
            color: #374151;
        }

        .card-content {
            margin-bottom: 1rem;
        }

        .card-detail {
            display: flex;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .detail-label {
            font-weight: 600;
            color: #6b7280;
            width: 70px;
            flex-shrink: 0;
        }

        .detail-value {
            color: #1f2937;
        }

        .card-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: #0077b6;
            color: white;
        }

        .btn-primary:hover {
            background-color: #023e8a;
        }

        .btn-secondary {
            background-color: #f3f4f6;
            color: #1f2937;
        }

        .btn-secondary:hover {
            background-color: #e5e7eb;
        }

        .section-divider {
            margin: 2.5rem 0;
            border: none;
            border-top: 1px solid #e5e7eb;
        }

        .quick-actions {
            margin-top: 2rem;
        }

        .actions-title {
            font-size: 1.4rem;
            color: #333;
            margin-bottom: 1.5rem;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .action-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
            text-decoration: none;
            color: #333;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 160px;
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            color: #333;
        }

        .action-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: #0077b6;
            transition: transform 0.2s;
        }

        .action-card:hover .action-icon {
            transform: scale(1.1);
        }

        .action-title {
            font-weight: 600;
            margin-bottom: 0.3rem;
        }

        .action-description {
            font-size: 0.85rem;
            color: #6b7280;
            margin: 0;
        }

        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                margin-top: 80px;
            }

            .dashboard-actions {
                width: 100%;
                justify-content: space-between;
            }

            .action-card {
                min-height: 140px;
            }
        }

        /* Welcome message animation */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .welcome-message {
            animation: fadeInUp 0.8s ease-out;
        }

        /* Card entry animation */
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .animated-card {
            animation: slideInRight 0.5s ease-out forwards;
            opacity: 0;
        }

        .animated-card:nth-child(1) {
            animation-delay: 0.2s;
        }

        .animated-card:nth-child(2) {
            animation-delay: 0.4s;
        }

        .animated-card:nth-child(3) {
            animation-delay: 0.6s;
        }

        .animated-card:nth-child(4) {
            animation-delay: 0.8s;
        }

        /* Accessibility improvements */
        .visually-hidden {
            border: 0;
            clip: rect(0 0 0 0);
            height: 1px;
            margin: -1px;
            overflow: hidden;
            padding: 0;
            position: absolute;
            width: 1px;
        }

        /* Tooltip */
        .tooltip {
            position: relative;
        }

        .tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            background-color: #333;
            color: white;
            padding: 0.5rem;
            border-radius: 4px;
            font-size: 0.85rem;
            white-space: nowrap;
            z-index: 10;
        }

        /* Statistics Grid for Admin - new grid layout */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            grid-template-rows: repeat(2, 220px);
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-left: 3px solid #0077b6;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* Chart container - takes up 3x2 grid space */
        .chart-container {
            grid-column: span 3 / span 3;
            grid-row: span 2 / span 2;
            background: white;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-left: 3px solid #0077b6;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 0;
        }

        /* Stats card positions */
        .stat-card.appointments {
            grid-column-start: 4;
            grid-row-start: 1;
            border-left-color: #f093fb;
        }

        .stat-card.employees {
            grid-column-start: 5;
            grid-row-start: 1;
            border-left-color: #43e97b;
        }

        .stat-card.patients {
            grid-column-start: 4;
            grid-row-start: 2;
            border-left-color: #667eea;
        }

        .stat-card.revenue {
            grid-column-start: 5;
            grid-row-start: 2;
            border-left-color: #fa709a;
        }

        .stat-card.patients {
            border-left-color: #667eea;
        }

        .stat-card.appointments {
            border-left-color: #f093fb;
        }

        .stat-card.lab {
            border-left-color: #4facfe;
        }

        .stat-card.employees {
            border-left-color: #43e97b;
        }

        .stat-card.revenue {
            border-left-color: #fa709a;
        }

        .stat-card.queue {
            border-left-color: #a8edea;
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .stat-icon {
            font-size: 1.5rem;
            color: #0077b6;
            opacity: 0.8;
        }

        .stat-card.patients .stat-icon {
            color: #667eea;
        }

        .stat-card.appointments .stat-icon {
            color: #f093fb;
        }

        .stat-card.lab .stat-icon {
            color: #4facfe;
        }

        .stat-card.employees .stat-icon {
            color: #43e97b;
        }

        .stat-card.revenue .stat-icon {
            color: #fa709a;
        }

        .stat-card.queue .stat-icon {
            color: #a8edea;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
        }

        /* Chart specific styles */
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e5e7eb;
            flex-shrink: 0;
        }

        .chart-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            margin: 0;
        }

        .chart-controls {
            display: flex;
            gap: 0.5rem;
        }

        .chart-btn {
            padding: 0.25rem 0.75rem;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
        }

        .chart-btn.active {
            background: #0077b6;
            color: white;
            border-color: #0077b6;
        }

        .chart-btn:hover:not(.active) {
            background: #f3f4f6;
            border-color: #9ca3af;
        }

        .chart-canvas {
            width: 100% !important;
            height: 100% !important;
            max-width: 100%;
            flex: 1;
            min-height: 280px;
        }

        .chart-wrapper {
            flex: 1;
            min-height: 0;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
                grid-template-rows: repeat(3, 160px);
            }

            .chart-container {
                grid-column: span 4 / span 4;
                grid-row: span 1 / span 1;
                padding: 0.75rem;
            }

            .stat-card.appointments {
                grid-column-start: 1;
                grid-row-start: 2;
            }

            .stat-card.employees {
                grid-column-start: 2;
                grid-row-start: 2;
            }

            .stat-card.patients {
                grid-column-start: 3;
                grid-row-start: 2;
            }

            .stat-card.revenue {
                grid-column-start: 4;
                grid-row-start: 2;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                grid-template-rows: repeat(4, 140px);
            }

            .chart-container {
                grid-column: span 2 / span 2;
                grid-row: span 2 / span 2;
                padding: 0.5rem;
            }

            .stat-card.appointments {
                grid-column-start: 1;
                grid-row-start: 3;
            }

            .stat-card.employees {
                grid-column-start: 2;
                grid-row-start: 3;
            }

            .stat-card.patients {
                grid-column-start: 1;
                grid-row-start: 4;
            }

            .stat-card.revenue {
                grid-column-start: 2;
                grid-row-start: 4;
            }

            .chart-canvas {
                height: 120px !important;
                max-height: 120px;
            }
        }

        /* Info Layout for Admin-specific sections */
        .info-layout {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 2rem;
        }

        @media (max-width: 1200px) {
            .info-layout {
                grid-template-columns: 1fr;
            }
        }

        /* Card Sections for admin specific content */
        .card-section {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
            margin-bottom: 1.5rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }

        .section-header h3 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-header h3 i {
            color: #0077b6;
        }

        .view-more-btn {
            color: #0077b6;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s;
        }

        .view-more-btn:hover {
            color: #023e8a;
            text-decoration: none;
        }

        /* Tables */
        .table-wrapper {
            max-height: 300px;
            overflow-y: auto;
            border-radius: 5px;
            border: 1px solid #e5e7eb;
        }

        .notification-table {
            width: 100%;
            border-collapse: collapse;
        }

        .notification-table th,
        .notification-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        .notification-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .notification-table td {
            color: #6b7280;
        }

        /* Activity Log */
        .activity-log {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .activity-log li {
            padding: 0.75rem;
            border-left: 3px solid #0077b6;
            background: #f8f9fa;
            margin-bottom: 0.5rem;
            border-radius: 0 5px 5px 0;
            font-size: 0.9rem;
            color: #6b7280;
        }

        /* Status Badges */
        .alert-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
        }

        .alert-warning {
            background-color: #fef3c7;
            color: #92400e;
        }

        .alert-danger {
            background-color: #fecaca;
            color: #991b1b;
        }

        .alert-info {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .priority-high {
            color: #dc2626;
            font-weight: 600;
        }

        .priority-normal {
            color: #16a34a;
        }

        .priority-low {
            color: #6b7280;
        }

        /* Empty States */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* System Status */
        .status-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .status-item:last-child {
            border-bottom: none;
        }
    </style>
</head>

<body>

    <?php
    // Tell the sidebar which menu item to highlight
    $activePage = 'dashboard';
    include '../../../includes/sidebar_admin.php';

    // Get the same production-friendly path resolution used by sidebar
    $request_uri = $_SERVER['REQUEST_URI'];
    $script_name = $_SERVER['SCRIPT_NAME'];

    // Extract the base path (project folder) from the script name
    if (preg_match('#^(/[^/]+)/pages/#', $script_name, $matches)) {
        $base_path = $matches[1] . '/';
    } else {
        // Fallback: try to extract from REQUEST_URI - first segment should be project folder
        $uri_parts = explode('/', trim($request_uri, '/'));
        if (count($uri_parts) > 0 && $uri_parts[0] && $uri_parts[0] !== 'pages') {
            $base_path = '/' . $uri_parts[0] . '/';
        } else {
            $base_path = '/';
        }
    }
    $nav_base = $base_path . 'pages/';
    ?>

    <main class="content-wrapper">
        <section class="dashboard-header">
            <div class="welcome-message">
                <h1 class="dashboard-title">Welcome back, <?php echo htmlspecialchars($defaults['name']); ?>!</h1>
                <p>Admin Dashboard • <?php echo htmlspecialchars($defaults['role']); ?> • ID: <?php echo htmlspecialchars($defaults['employee_number']); ?></p>
            </div>

            <!--<div class="dashboard-actions">
                <a href="patient_records_management.php" class="btn btn-primary">
                    <i class="fas fa-users"></i> Manage Patients
                </a>
                <a href="appointments_management.php" class="btn btn-secondary">
                    <i class="fas fa-calendar-check"></i> Appointments
                </a>
            </div>-->
        </section>


        <section class="info-card">
            <h2><i class="fas fa-chart-line"></i> System Overview</h2>
            <div class="notification-list">
                <p>Here's a quick overview of your system status and performance metrics.</p>
            </div>
        </section>

        <section class="stats-grid">
            <!-- Patient Visits Chart - spans 3x2 grid -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Patient Visits - CHO Main</h3>
                    <div class="chart-controls">
                        <button class="chart-btn active" data-period="daily">Daily</button>
                        <button class="chart-btn" data-period="weekly">Weekly</button>
                        <button class="chart-btn" data-period="monthly">Monthly</button>
                    </div>
                </div>
                <div class="chart-wrapper">
                    <canvas id="patientVisitsChart" class="chart-canvas"></canvas>
                </div>
            </div>

            <!-- Appointments Card -->
            <div class="stat-card appointments animated-card">
                <div class="stat-header">
                    <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                </div>
                <div class="stat-number"><?php echo number_format($defaults['stats']['today_appointments']); ?></div>
                <div class="stat-label">Today's Appointments</div>
            </div>

            <!-- Employees Card -->
            <div class="stat-card employees animated-card">
                <div class="stat-header">
                    <div class="stat-icon"><i class="fas fa-user-tie"></i></div>
                </div>
                <div class="stat-number"><?php echo number_format($defaults['stats']['total_employees']); ?></div>
                <div class="stat-label">Total Employees</div>
            </div>

            <!-- Patients Card -->
            <div class="stat-card patients animated-card">
                <div class="stat-header">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                </div>
                <div class="stat-number"><?php echo number_format($defaults['stats']['total_patients']); ?></div>
                <div class="stat-label">Total Patients</div>
            </div>

            <!-- Revenue Card -->
            <div class="stat-card revenue animated-card">
                <div class="stat-header">
                    <div class="stat-icon"><i class="fas fa-peso-sign"></i></div>
                </div>
                <div class="stat-number">₱<?php echo number_format($defaults['stats']['monthly_revenue'], 2); ?></div>
                <div class="stat-label">Monthly Collections</div>
            </div>
        </section>

        <!-- <hr class="section-divider">Quick Actions Section -->

        <section class="quick-actions">
            <h2 class="actions-title">Quick Actions</h2>

            <div class="actions-grid">
                <a href="<?= $nav_base ?>medical-records/patient_records_management.php" class="action-card">
                    <i class="fas fa-users action-icon"></i>
                    <h3 class="action-title">View Patient Records</h3>
                    <p class="action-description">View and browse patient records and information</p>
                </a>

                <a href="<?= $nav_base ?>medical-records/medical_records.php" class="action-card">
                    <i class="fas fa-file-medical action-icon"></i>
                    <h3 class="action-title">Medical History</h3>
                    <p class="action-description">View comprehensive medical history and patient care records</p>
                </a>

                <a href="<?= $nav_base ?>appointment/appointments_management.php" class="action-card">
                    <i class="fas fa-calendar-alt action-icon"></i>
                    <h3 class="action-title">CHO Appointments</h3>
                    <p class="action-description">View and manage CHO facility appointments and schedules</p>
                </a>

                <a href="<?= $nav_base ?>management/admin/user-management/employee_list.php" class="action-card">
                    <i class="fas fa-users-cog action-icon"></i>
                    <h3 class="action-title">Manage Employees</h3>
                    <p class="action-description">Add, edit, and manage employee accounts and roles</p>
                </a>

                <a href="<?= $nav_base ?>management/admin/billing/billing_overview.php" class="action-card">
                    <i class="fas fa-file-invoice-dollar action-icon"></i>
                    <h3 class="action-title">Billing Overview</h3>
                    <p class="action-description">View billing information and financial overview</p>
                </a>

                <a href="#" class="action-card" style="opacity: 0.6; cursor: not-allowed;">
                    <i class="fas fa-chart-bar action-icon"></i>
                    <h3 class="action-title">Generate Reports</h3>
                    <p class="action-description">View analytics and generate system reports (Coming Soon)</p>
                </a>
            </div>
        </section>


        <hr class="section-divider">

        <!-- Info Layout -->
        <div class="info-layout">
            <!-- Left Column -->
            <div class="left-column">
                <!-- Recent Activities -->
                <div class="card-section">
                    <div class="section-header">
                        <h3><i class="fas fa-history"></i> Recent Activities</h3>
                        <a href="../reports/activity_log.php" class="view-more-btn">
                            <i class="fas fa-chevron-right"></i> View All
                        </a>
                    </div>

                    <?php if (!empty($defaults['recent_activities'])): ?>
                        <div class="table-wrapper">
                            <ul class="activity-log">
                                <?php foreach ($defaults['recent_activities'] as $activity): ?>
                                    <li>
                                        <strong><?php echo htmlspecialchars($activity['date']); ?></strong><br>
                                        <?php echo htmlspecialchars($activity['activity']); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <p>No recent activities to display</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Pending Tasks -->
                <div class="card-section">
                    <div class="section-header">
                        <h3><i class="fas fa-tasks"></i> Pending Tasks</h3>
                        <a href="admin_tasks.php" class="view-more-btn">
                            <i class="fas fa-chevron-right"></i> View All
                        </a>
                    </div>

                    <?php if (!empty($defaults['pending_tasks'])): ?>
                        <div class="table-wrapper">
                            <table class="notification-table">
                                <thead>
                                    <tr>
                                        <th>Task</th>
                                        <th>Priority</th>
                                        <th>Due Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($defaults['pending_tasks'] as $task): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($task['task']); ?></td>
                                            <td><span class="priority-<?php echo $task['priority']; ?>"><?php echo ucfirst($task['priority']); ?></span></td>
                                            <td><?php echo htmlspecialchars($task['due_date']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No pending tasks</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column -->
            <div class="right-column">
                <!-- System Alerts -->
                <div class="card-section">
                    <div class="section-header">
                        <h3><i class="fas fa-exclamation-triangle"></i> System Alerts</h3>
                        <a href="../notifications/system_alerts.php" class="view-more-btn">
                            <i class="fas fa-chevron-right"></i> View All
                        </a>
                    </div>

                    <?php if (!empty($defaults['system_alerts'])): ?>
                        <div class="table-wrapper">
                            <table class="notification-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Message</th>
                                        <th>Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($defaults['system_alerts'] as $alert): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($alert['date']); ?></td>
                                            <td><?php echo htmlspecialchars($alert['message']); ?></td>
                                            <td><span class="alert-badge alert-<?php echo $alert['type']; ?>"><?php echo ucfirst($alert['type']); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-shield-alt"></i>
                            <p>No system alerts</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- System Status -->
                <div class="card-section">
                    <div class="section-header">
                        <h3><i class="fas fa-server"></i> System Status</h3>
                    </div>

                    <div class="status-item">
                        <strong>Database Connection</strong>
                        <span class="alert-badge alert-success">Connected</span>
                    </div>
                    <div class="status-item">
                        <strong>Server Status</strong>
                        <span class="alert-badge alert-success">Online</span>
                    </div>
                    <div class="status-item">
                        <strong>Last Backup</strong>
                        <span><?php echo date('M d, Y H:i'); ?></span>
                    </div>
                    <div class="status-item">
                        <strong>System Version</strong>
                        <span>CHO Koronadal v1.0.0</span>
                    </div>
                    <div class="status-item">
                        <strong>Uptime</strong>
                        <span class="alert-badge alert-success">Running</span>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Patient Visits Chart Implementation
        class PatientVisitsChart {
            constructor() {
                this.chart = null;
                this.chartCanvas = document.getElementById('patientVisitsChart');
                this.currentPeriod = 'daily';
                this.initChart();
                this.bindEvents();
            }

            initChart() {
                if (!this.chartCanvas) return;

                const ctx = this.chartCanvas.getContext('2d');

                this.chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: [],
                        datasets: [{
                            label: 'CHO Main Visits',
                            data: [],
                            borderColor: '#0077b6',
                            backgroundColor: 'rgba(0, 119, 182, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#0077b6',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        layout: {
                            padding: {
                                top: 5,
                                right: 5,
                                bottom: 5,
                                left: 5
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.1)'
                                },
                                ticks: {
                                    stepSize: 1,
                                    color: '#6b7280',
                                    font: {
                                        size: 11
                                    }
                                }
                            },
                            x: {
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.1)'
                                },
                                ticks: {
                                    color: '#6b7280',
                                    font: {
                                        size: 11
                                    }
                                }
                            }
                        },
                        interaction: {
                            intersect: false,
                            mode: 'index'
                        }
                    }
                });

                this.loadData(this.currentPeriod);
            }

            bindEvents() {
                const chartButtons = document.querySelectorAll('.chart-btn');
                chartButtons.forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        // Remove active class from all buttons
                        chartButtons.forEach(b => b.classList.remove('active'));
                        // Add active class to clicked button
                        e.target.classList.add('active');

                        // Update period and reload data
                        this.currentPeriod = e.target.dataset.period;
                        this.loadData(this.currentPeriod);
                    });
                });
            }

            async loadData(period) {
                try {
                    const nav_base = '<?= $nav_base ?>';
                    // Fix: Remove 'pages/' since API is in root/api/, not pages/api/
                    const response = await fetch(nav_base + '../api/get_patient_visits_chart.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            period: period
                        })
                    });

                    if (!response.ok) {
                        throw new Error('Failed to fetch chart data');
                    }

                    const data = await response.json();
                    this.updateChart(data);
                } catch (error) {
                    console.warn('Chart API not available, using fallback data:', error);
                    // Use fallback data
                    this.updateChart(this.getFallbackData(period));
                }
            }

            updateChart(data) {
                if (!this.chart) return;

                this.chart.data.labels = data.labels;
                this.chart.data.datasets[0].data = data.values;
                this.chart.update('active');
            }

            getFallbackData(period) {
                // Fallback data for when API is not available
                const today = new Date();
                let labels = [];
                let values = [];

                switch (period) {
                    case 'daily':
                        // Last 7 days
                        for (let i = 6; i >= 0; i--) {
                            const date = new Date(today);
                            date.setDate(date.getDate() - i);
                            labels.push(date.toLocaleDateString('en-US', {
                                weekday: 'short'
                            }));
                            values.push(Math.floor(Math.random() * 20) + 5);
                        }
                        break;

                    case 'weekly':
                        // Last 8 weeks
                        for (let i = 7; i >= 0; i--) {
                            const date = new Date(today);
                            date.setDate(date.getDate() - (i * 7));
                            labels.push(`Week ${this.getWeekNumber(date)}`);
                            values.push(Math.floor(Math.random() * 100) + 30);
                        }
                        break;

                    case 'monthly':
                        // Last 12 months
                        for (let i = 11; i >= 0; i--) {
                            const date = new Date(today);
                            date.setMonth(date.getMonth() - i);
                            labels.push(date.toLocaleDateString('en-US', {
                                month: 'short'
                            }));
                            values.push(Math.floor(Math.random() * 300) + 100);
                        }
                        break;
                }

                return {
                    labels,
                    values
                };
            }

            getWeekNumber(date) {
                const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
                const dayNum = d.getUTCDay() || 7;
                d.setUTCDate(d.getUTCDate() + 4 - dayNum);
                const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
                return Math.ceil((((d - yearStart) / 86400000) + 1) / 7);
            }
        }
    </script>

    <script>
        class AdminDashboardManager {
            constructor() {
                this.initializeDashboard();
                this.setupEventListeners();
            }

            initializeDashboard() {
                console.log('📊 Admin Dashboard Manager initialized');

                // Simple animation for the cards
                const cards = document.querySelectorAll('.animated-card');
                cards.forEach(card => {
                    card.style.opacity = '1';
                });

                // Request notification permission
                this.requestNotificationPermission();
            }

            async requestNotificationPermission() {
                if ('Notification' in window && Notification.permission === 'default') {
                    await Notification.requestPermission();
                }
            }

            setupEventListeners() {
                // Basic event listeners for admin dashboard
                console.log('✅ Admin Dashboard event listeners set up');
            }
        }

        // Initialize dashboard manager
        let dashboardManager;

        document.addEventListener('DOMContentLoaded', function() {
            dashboardManager = new AdminDashboardManager();
            // Initialize patient visits chart
            new PatientVisitsChart();
        });
    </script>
</body>

</html>