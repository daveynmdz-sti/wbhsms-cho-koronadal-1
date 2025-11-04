<?php
// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header("Location: ../management/auth/employee_login.php");
    exit();
}

// Set active page for sidebar highlighting
$activePage = 'reports';

// Define role-based permissions
$allowedRoles = ['admin', 'dho', 'cashier', 'records_officer'];
$userRole = $_SESSION['role'] ?? '';

if (!in_array($userRole, $allowedRoles)) {
    $role = strtolower($userRole);
    header("Location: ../management/$role/dashboard.php");
    exit();
}

// Helper function to get role-based dashboard URL
function get_role_dashboard_url($role)
{
    $role = strtolower($role);
    switch ($role) {
        case 'admin':
            return '../management/admin/dashboard.php';
        case 'dho':
            return '../management/dho/dashboard.php';
        case 'cashier':
            return '../management/cashier/dashboard.php';
        case 'records_officer':
            return '../management/records_officer/dashboard.php';
        default:
            return '../management/admin/dashboard.php';
    }
}

// Initialize variables for alerts
$message = '';
$error = '';

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Today
$status_filter = $_GET['status_filter'] ?? '';
$test_type_filter = $_GET['test_type_filter'] ?? '';
$urgency_filter = $_GET['urgency_filter'] ?? '';
$employee_filter = $_GET['employee_filter'] ?? '';

try {
    // Get filter options for dropdowns
    $employees_stmt = $pdo->query("SELECT employee_id, CONCAT(first_name, ' ', last_name) as full_name FROM employees WHERE status = 'active' ORDER BY first_name");
    $employees = $employees_stmt->fetchAll(PDO::FETCH_ASSOC);

    $test_types_stmt = $pdo->query("SELECT DISTINCT test_type FROM lab_order_items WHERE test_type IS NOT NULL AND test_type != '' ORDER BY test_type");
    $test_types = $test_types_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build where clauses for filtering
    $where_conditions = ["DATE(lo.order_date) BETWEEN :start_date AND :end_date"];
    $params = [
        'start_date' => $start_date,
        'end_date' => $end_date
    ];

    if (!empty($status_filter) && in_array($status_filter, ['completed', 'cancelled'])) {
        $where_conditions[] = "loi.status = :status_filter";
        $params['status_filter'] = $status_filter;
    }

    if (!empty($test_type_filter)) {
        $where_conditions[] = "loi.test_type LIKE :test_type_filter";
        $params['test_type_filter'] = '%' . $test_type_filter . '%';
    }

    if (!empty($urgency_filter) && in_array($urgency_filter, ['STAT', 'Routine'])) {
        $where_conditions[] = "loi.urgency = :urgency_filter";
        $params['urgency_filter'] = $urgency_filter;
    }

    if (!empty($employee_filter)) {
        $where_conditions[] = "lo.ordered_by_employee_id = :employee_filter";
        $params['employee_filter'] = $employee_filter;
    }

    $where_clause = implode(' AND ', $where_conditions);

    // 1. LAB SUMMARY STATISTICS
    $summary_query = "
        SELECT 
            COUNT(DISTINCT lo.lab_order_id) as total_orders,
            COUNT(DISTINCT loi.item_id) as total_tests,
            SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_tests,
            SUM(CASE WHEN loi.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_tests,
            ROUND((SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) * 100.0 / COUNT(loi.item_id)), 2) as completion_rate,
            ROUND(AVG(CASE 
                WHEN loi.status = 'completed' AND loi.started_at IS NOT NULL AND loi.completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.completed_at) 
                WHEN loi.status = 'completed' AND loi.started_at IS NOT NULL AND loi.completed_at IS NULL
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.updated_at)
                WHEN loi.status = 'completed' AND loi.started_at IS NULL AND loi.completed_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, loi.created_at, loi.completed_at)
                ELSE NULL 
            END), 2) as avg_turnaround_hours
        FROM lab_orders lo
        JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE $where_clause AND loi.status IN ('completed', 'cancelled')
    ";
    
    $summary_stmt = $pdo->prepare($summary_query);
    $summary_stmt->execute($params);
    $summary_stats = $summary_stmt->fetch(PDO::FETCH_ASSOC);

    // 2. LAB STATUS BREAKDOWN
    $status_breakdown_query = "
        SELECT 
            loi.status,
            COUNT(*) as count
        FROM lab_orders lo
        JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE $where_clause AND loi.status IN ('completed', 'cancelled')
        GROUP BY loi.status
        ORDER BY count DESC
    ";
    
    $status_breakdown_stmt = $pdo->prepare($status_breakdown_query);
    $status_breakdown_stmt->execute($params);
    $status_breakdown_raw = $status_breakdown_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total for percentage calculation
    $total_status_count = array_sum(array_column($status_breakdown_raw, 'count'));
    
    // Add percentage calculation
    $status_breakdown = [];
    foreach ($status_breakdown_raw as $status) {
        $status['percentage'] = $total_status_count > 0 ? round(($status['count'] / $total_status_count) * 100, 2) : 0;
        $status_breakdown[] = $status;
    }

    // 3. TOP LAB TESTS
    $top_tests_query = "
        SELECT 
            loi.test_type,
            COUNT(*) as test_count,
            SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN loi.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
            SUM(CASE WHEN loi.urgency = 'STAT' THEN 1 ELSE 0 END) as stat_count,
            ROUND((SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as completion_rate,
            ROUND(AVG(CASE 
                WHEN loi.status = 'completed' AND loi.started_at IS NOT NULL AND loi.completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.completed_at) 
                WHEN loi.status = 'completed' AND loi.started_at IS NOT NULL AND loi.completed_at IS NULL
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.updated_at)
                WHEN loi.status = 'completed' AND loi.started_at IS NULL AND loi.completed_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, loi.created_at, loi.completed_at)
                ELSE NULL 
            END), 2) as avg_turnaround_hours
        FROM lab_orders lo
        JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE $where_clause AND loi.status IN ('completed', 'cancelled')
        GROUP BY loi.test_type
        ORDER BY test_count DESC
        LIMIT 20
    ";
    
    $top_tests_stmt = $pdo->prepare($top_tests_query);
    $top_tests_stmt->execute($params);
    $top_tests = $top_tests_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. URGENCY ANALYSIS
    $urgency_analysis_query = "
        SELECT 
            loi.urgency,
            COUNT(*) as test_count,
            SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            ROUND((SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as completion_rate,
            ROUND(AVG(CASE 
                WHEN loi.status = 'completed' AND loi.started_at IS NOT NULL AND loi.completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.completed_at) 
                WHEN loi.status = 'completed' AND loi.started_at IS NOT NULL AND loi.completed_at IS NULL
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.updated_at)
                WHEN loi.status = 'completed' AND loi.started_at IS NULL AND loi.completed_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, loi.created_at, loi.completed_at)
                ELSE NULL 
            END), 2) as avg_turnaround_hours
        FROM lab_orders lo
        JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE $where_clause AND loi.status IN ('completed', 'cancelled')
        GROUP BY loi.urgency
        ORDER BY test_count DESC
    ";
    
    $urgency_analysis_stmt = $pdo->prepare($urgency_analysis_query);
    $urgency_analysis_stmt->execute($params);
    $urgency_analysis = $urgency_analysis_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. DAILY LAB TRENDS
    $daily_trends_query = "
        SELECT 
            DATE(lo.order_date) as order_date,
            COUNT(DISTINCT lo.lab_order_id) as orders_count,
            COUNT(loi.item_id) as tests_count,
            SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN loi.urgency = 'STAT' THEN 1 ELSE 0 END) as stat_count
        FROM lab_orders lo
        JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE $where_clause AND loi.status IN ('completed', 'cancelled')
        GROUP BY DATE(lo.order_date)
        ORDER BY order_date DESC
        LIMIT 30
    ";
    
    $daily_trends_stmt = $pdo->prepare($daily_trends_query);
    $daily_trends_stmt->execute($params);
    $daily_trends = $daily_trends_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. RECENT LAB ACTIVITIES
    $recent_activities_query = "
        SELECT 
            lo.lab_order_id,
            loi.test_type,
            loi.urgency,
            loi.status,
            loi.created_at,
            loi.updated_at,
            loi.started_at,
            loi.completed_at,
            lo.order_date,
            CONCAT(pt.first_name, ' ', pt.last_name) as patient_name,
            CONCAT(e.first_name, ' ', e.last_name) as ordered_by,
            CASE 
                WHEN loi.status = 'completed' AND loi.started_at IS NOT NULL AND loi.completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.completed_at) 
                WHEN loi.status = 'completed' AND loi.started_at IS NOT NULL AND loi.completed_at IS NULL
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.updated_at)
                WHEN loi.status = 'completed' AND loi.started_at IS NULL AND loi.completed_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, loi.created_at, loi.completed_at)
                ELSE NULL 
            END as turnaround_hours
        FROM lab_orders lo
        JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        JOIN patients pt ON lo.patient_id = pt.patient_id
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        WHERE $where_clause AND loi.status IN ('completed', 'cancelled')
        ORDER BY lo.order_date DESC, loi.updated_at DESC
        LIMIT 50
    ";
    
    $recent_activities_stmt = $pdo->prepare($recent_activities_query);
    $recent_activities_stmt->execute($params);
    $recent_activities = $recent_activities_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. TURNAROUND TIME ANALYSIS
    $turnaround_analysis_query = "
        SELECT 
            loi.test_type,
            COUNT(*) as completed_tests,
            ROUND(AVG(CASE 
                WHEN loi.started_at IS NOT NULL AND loi.completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.completed_at) 
                WHEN loi.started_at IS NOT NULL AND loi.completed_at IS NULL
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.updated_at)
                WHEN loi.started_at IS NULL AND loi.completed_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, loi.created_at, loi.completed_at)
                ELSE NULL 
            END), 2) as avg_hours,
            ROUND(MIN(CASE 
                WHEN loi.started_at IS NOT NULL AND loi.completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.completed_at) 
                WHEN loi.started_at IS NOT NULL AND loi.completed_at IS NULL
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.updated_at)
                WHEN loi.started_at IS NULL AND loi.completed_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, loi.created_at, loi.completed_at)
                ELSE NULL 
            END), 2) as min_hours,
            ROUND(MAX(CASE 
                WHEN loi.started_at IS NOT NULL AND loi.completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.completed_at) 
                WHEN loi.started_at IS NOT NULL AND loi.completed_at IS NULL
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.updated_at)
                WHEN loi.started_at IS NULL AND loi.completed_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, loi.created_at, loi.completed_at)
                ELSE NULL 
            END), 2) as max_hours
        FROM lab_orders lo
        JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE $where_clause 
        AND loi.status = 'completed'
        GROUP BY loi.test_type
        HAVING completed_tests > 0
        ORDER BY avg_hours DESC
        LIMIT 15
    ";
    
    $turnaround_analysis_stmt = $pdo->prepare($turnaround_analysis_query);
    $turnaround_analysis_stmt->execute($params);
    $turnaround_analysis = $turnaround_analysis_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle zero division and ensure data consistency
    if (!$summary_stats || !$summary_stats['total_tests']) {
        $summary_stats = [
            'total_orders' => 0,
            'total_tests' => 0,
            'completed_tests' => 0,
            'cancelled_tests' => 0,
            'completion_rate' => 0,
            'avg_turnaround_hours' => 0
        ];
    }

} catch (Exception $e) {
    error_log("Lab Statistics Report Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    $error = "Error generating lab statistics report: " . $e->getMessage();
    
    // Initialize empty arrays to prevent undefined variable errors
    $summary_stats = [
        'total_orders' => 0,
        'total_tests' => 0,
        'completed_tests' => 0,
        'cancelled_tests' => 0,
        'completion_rate' => 0,
        'avg_turnaround_hours' => 0
    ];
    $status_breakdown = [];
    $top_tests = [];
    $urgency_analysis = [];
    $daily_trends = [];
    $recent_activities = [];
    $turnaround_analysis = [];
    $test_types = [];
    $employees = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Statistics Report - CHO Koronadal</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">

    <!-- CSS Assets -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/sidebar.css">

    <style>
        /* Page-specific styles */
        :root {
            --primary-color: #0077b6;
            --primary-dark: #03045e;
            --secondary-color: #00b4d8;
            --accent-color: #90e0ef;
            --success-color: #06d6a0;
            --warning-color: #ffd60a;
            --danger-color: #f72585;
            --text-dark: #2d3436;
            --text-light: #636e72;
            --background-light: #ffffff;
            --border-color: #ddd;
            --shadow-light: 0 2px 10px rgba(0, 0, 0, 0.1);
            --shadow-heavy: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .homepage {
            margin-left: 300px;
        }

        .content-wrapper {
            padding: 2rem;
            transition: margin-left 0.3s;
        }

        /* Breadcrumb styling */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            color: var(--text-light);
        }

        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .breadcrumb a:hover {
            color: var(--primary-dark);
        }

        .breadcrumb i {
            font-size: 12px;
        }

        /* Page header */
        .page-header {
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--accent-color);
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
            color: var(--primary-color);
        }

        .page-header p {
            color: var(--text-light);
            margin: 8px 0 0 0;
            font-size: 16px;
        }

        /* Alert styles */
        .alert {
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            box-shadow: var(--shadow-light);
        }

        .alert i {
            font-size: 16px;
        }

        .alert-success {
            background-color: #d1e7dd;
            color: #0f5132;
            border: 1px solid #badbcc;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c2c7;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #055160;
            border: 1px solid #b8daff;
        }

        .alert-warning {
            background-color: #fff3cd;
            color: #664d03;
            border: 1px solid #ffecb5;
        }

        /* Report overview section */
        .report-overview {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .report-overview h2 {
            color: var(--primary-dark);
            font-size: 22px;
            font-weight: 600;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .report-overview h2 i {
            color: var(--primary-color);
        }

        .report-overview p {
            color: var(--text-light);
            margin: 0;
            line-height: 1.6;
        }

        /* Lab statistics content section */
        .lab-content {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .lab-content h3 {
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .lab-content h3 i {
            color: var(--primary-color);
        }

        /* Filter section */
        .filter-section {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .filter-section h3 {
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-section h3 i {
            color: var(--primary-color);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            font-weight: 500;
            color: var(--text-dark);
            font-size: 14px;
        }

        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-export {
            background: var(--success-color);
            color: white;
        }

        /* Summary cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: var(--background-light);
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
            text-align: center;
        }

        .summary-card i {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .summary-card .number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary-dark);
            margin: 0;
        }

        .summary-card .label {
            font-size: 14px;
            color: var(--text-light);
            margin-top: 5px;
        }

        .summary-card .percentage {
            font-size: 16px;
            color: var(--success-color);
            font-weight: 600;
            margin-top: 5px;
        }

        /* Analytics sections */
        .analytics-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .analytics-section {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .analytics-section h3 {
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .analytics-section h3 i {
            color: var(--primary-color);
        }

        .full-width-section {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
            margin-bottom: 30px;
        }

        .full-width-section h3 {
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .full-width-section h3 i {
            color: var(--primary-color);
        }

        .analytics-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .analytics-table th,
        .analytics-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }

        .analytics-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--text-dark);
        }

        .analytics-table tr:hover {
            background: #f8f9fa;
        }

        .analytics-table .percentage {
            color: var(--primary-color);
            font-weight: 500;
        }

        .analytics-table .count {
            font-weight: 600;
            color: var(--text-dark);
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-completed {
            background: #d1e7dd;
            color: #0f5132;
        }

        .status-in_progress {
            background: #d1ecf1;
            color: #055160;
        }

        .status-pending {
            background: #fff3cd;
            color: #664d03;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .urgency-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .urgency-stat {
            background: #f8d7da;
            color: #721c24;
        }

        .urgency-routine {
            background: #d1e7dd;
            color: #0f5132;
        }

        /* Tabs for different views */
        .tab-container {
            margin-bottom: 30px;
        }

        .tab-buttons {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }

        .tab-button {
            padding: 12px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-light);
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }

        .tab-button.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }

        .tab-button:hover {
            color: var(--primary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 24px;
            }

            .report-overview {
                padding: 20px;
            }

            .lab-content {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
    <!-- Include admin sidebar -->
    <?php include '../../includes/sidebar_admin.php'; ?>

    <section class="homepage">
        <div class="content-wrapper">
            <!-- Breadcrumb Navigation -->
            <div class="breadcrumb" style="margin-top: 50px;">
                <?php
                $user_role = strtolower($_SESSION['role']);
                $dashboard_path = get_role_dashboard_url($user_role);
                ?>
                <a href="<?php echo htmlspecialchars($dashboard_path); ?>"><i class="fas fa-home"></i> Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <a href="reports_management.php"><i class="fas fa-chart-bar"></i> Reports Management</a>
                <i class="fas fa-chevron-right"></i>
                <span> Lab Statistics</span>
            </div>

            <div class="page-header">
                <h1><i class="fas fa-flask"></i> Lab Statistics Report</h1>
                <p>Comprehensive analysis of laboratory test performance, turnaround times, and diagnostic trends</p>
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

            <!-- Report Overview -->
            <div class="report-overview">
                <h2><i class="fas fa-info-circle"></i> Report Overview</h2>
                <p>This report provides detailed insights into laboratory operations including test volumes, completion rates, turnaround times, popular diagnostic tests, and quality metrics. Use this data to optimize laboratory workflows, improve efficiency, and ensure timely delivery of diagnostic services to patients.</p>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3><i class="fas fa-filter"></i> Report Filters</h3>
                <form method="GET" action="">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label for="start_date">Start Date</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="end_date">End Date</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="status_filter">Status</label>
                            <select id="status_filter" name="status_filter">
                                <option value="">All Statuses</option>
                                <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="test_type_filter">Test Type</label>
                            <select id="test_type_filter" name="test_type_filter">
                                <option value="">All Test Types</option>
                                <?php foreach ($test_types as $test_type): ?>
                                    <option value="<?php echo htmlspecialchars($test_type['test_type']); ?>" 
                                        <?php echo $test_type_filter == $test_type['test_type'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($test_type['test_type']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="urgency_filter">Urgency</label>
                            <select id="urgency_filter" name="urgency_filter">
                                <option value="">All Urgency</option>
                                <option value="STAT" <?php echo $urgency_filter == 'STAT' ? 'selected' : ''; ?>>STAT</option>
                                <option value="Routine" <?php echo $urgency_filter == 'Routine' ? 'selected' : ''; ?>>Routine</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="employee_filter">Ordered By</label>
                            <select id="employee_filter" name="employee_filter">
                                <option value="">All Employees</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?php echo $employee['employee_id']; ?>" 
                                        <?php echo $employee_filter == $employee['employee_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($employee['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Generate Report
                        </button>
                        <a href="?" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset Filters
                        </a>
                        <a href="export_lab_statistics_pdf.php?<?php echo http_build_query($_GET); ?>" class="btn btn-export" target="_blank">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </a>
                    </div>
                </form>
            </div>

            <!-- Summary Statistics -->
            <div class="summary-cards">
                <div class="summary-card">
                    <i class="fas fa-clipboard-list"></i>
                    <div class="number"><?php echo number_format($summary_stats['total_orders'] ?? 0); ?></div>
                    <div class="label">Total Lab Orders</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-flask"></i>
                    <div class="number"><?php echo number_format($summary_stats['total_tests'] ?? 0); ?></div>
                    <div class="label">Total Tests</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-check-circle"></i>
                    <div class="number"><?php echo number_format($summary_stats['completed_tests'] ?? 0); ?></div>
                    <div class="label">Completed Tests</div>
                    <div class="percentage"><?php echo number_format($summary_stats['completion_rate'] ?? 0, 1); ?>% Rate</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-times-circle"></i>
                    <div class="number"><?php echo number_format($summary_stats['cancelled_tests'] ?? 0); ?></div>
                    <div class="label">Cancelled Tests</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-clock"></i>
                    <div class="number"><?php echo number_format($summary_stats['avg_turnaround_hours'] ?? 0, 1); ?></div>
                    <div class="label">Avg Turnaround (Hours)</div>
                </div>
            </div>

            <!-- Main Analytics Grid -->
            <div class="analytics-grid">
                <!-- Status Breakdown -->
                <div class="analytics-section">
                    <h3><i class="fas fa-chart-pie"></i> Status Distribution</h3>
                    <p style="font-size: 14px; color: var(--text-light); margin-bottom: 15px;">
                        Laboratory test status breakdown (Period: <?php echo $start_date; ?> to <?php echo $end_date; ?>)
                    </p>
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Count</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $all_statuses = ['completed', 'cancelled'];
                            $existing_status_data = [];
                            
                            if (!empty($status_breakdown)) {
                                foreach ($status_breakdown as $status) {
                                    $existing_status_data[$status['status']] = $status;
                                }
                            }
                            
                            foreach ($all_statuses as $status_name): 
                                if (isset($existing_status_data[$status_name])) {
                                    $status_data = $existing_status_data[$status_name];
                                    $badge_class = 'status-' . str_replace(' ', '_', $status_name);
                                    echo '
                                    <tr>
                                        <td><span class="status-badge ' . $badge_class . '">' . ucfirst(str_replace('_', ' ', $status_data['status'])) . '</span></td>
                                        <td class="count">' . number_format($status_data['count']) . '</td>
                                        <td class="percentage">' . $status_data['percentage'] . '%</td>
                                    </tr>';
                                } else {
                                    $badge_class = 'status-' . str_replace(' ', '_', $status_name);
                                    echo '
                                    <tr style="opacity: 0.5;">
                                        <td><span class="status-badge ' . $badge_class . '">' . ucfirst(str_replace('_', ' ', $status_name)) . '</span></td>
                                        <td class="count">0</td>
                                        <td class="percentage">0.00%</td>
                                    </tr>';
                                }
                            endforeach; 
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- Urgency Analysis -->
                <div class="analytics-section">
                    <h3><i class="fas fa-exclamation-triangle"></i> Urgency Analysis</h3>
                    <p style="font-size: 14px; color: var(--text-light); margin-bottom: 15px;">
                        Test urgency breakdown and performance
                    </p>
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Urgency</th>
                                <th>Count</th>
                                <th>Completed</th>
                                <th>Avg TAT</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($urgency_analysis)): ?>
                                <?php foreach ($urgency_analysis as $urgency): ?>
                                    <?php $badge_class = 'urgency-' . strtolower($urgency['urgency']); ?>
                                    <tr>
                                        <td><span class="urgency-badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($urgency['urgency']); ?></span></td>
                                        <td class="count"><?php echo number_format($urgency['test_count']); ?></td>
                                        <td class="count"><?php echo number_format($urgency['completed_count']); ?></td>
                                        <td class="percentage"><?php echo $urgency['avg_turnaround_hours'] ?? 'N/A'; ?>h</td>
                                    </tr>
                                <?php endforeach; ?>
                                <!-- Fill remaining rows if less than 4 -->
                                <?php for ($i = count($urgency_analysis); $i < 4; $i++): ?>
                                    <tr style="opacity: 0.5;">
                                        <td style="font-style: italic; color: var(--text-light);">No data</td>
                                        <td class="count">0</td>
                                        <td class="count">0</td>
                                        <td class="percentage">0h</td>
                                    </tr>
                                <?php endfor; ?>
                            <?php else: ?>
                                <!-- Show 4 empty rows when no data -->
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                    <tr style="opacity: 0.5;">
                                        <td style="font-style: italic; color: var(--text-light);">No urgency data</td>
                                        <td class="count">0</td>
                                        <td class="count">0</td>
                                        <td class="percentage">0h</td>
                                    </tr>
                                <?php endfor; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tabbed Analytics -->
            <div class="tab-container">
                <div class="tab-buttons">
                    <button class="tab-button active" onclick="showTab('top-tests')">
                        <i class="fas fa-list-ol"></i> Top Tests
                    </button>
                    <button class="tab-button" onclick="showTab('daily-trends')">
                        <i class="fas fa-chart-line"></i> Daily Trends
                    </button>
                    <button class="tab-button" onclick="showTab('turnaround-analysis')">
                        <i class="fas fa-stopwatch"></i> Turnaround Analysis
                    </button>
                    <button class="tab-button" onclick="showTab('recent-activities')">
                        <i class="fas fa-clock"></i> Recent Activities
                    </button>
                </div>

                <!-- Top Tests Tab -->
                <div id="top-tests" class="tab-content active">
                    <div class="full-width-section">
                        <h3><i class="fas fa-list-ol"></i> Most Ordered Laboratory Tests</h3>
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Test Type</th>
                                    <th>Total Count</th>
                                    <th>Completed</th>
                                    <th>Cancelled</th>
                                    <th>STAT Orders</th>
                                    <th>Completion Rate</th>
                                    <th>Avg TAT</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($top_tests)): ?>
                                    <?php foreach (array_slice($top_tests, 0, 15) as $test): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($test['test_type']); ?></strong></td>
                                            <td class="count"><?php echo number_format($test['test_count']); ?></td>
                                            <td class="count"><?php echo number_format($test['completed_count']); ?></td>
                                            <td class="count"><?php echo number_format($test['cancelled_count']); ?></td>
                                            <td class="count"><?php echo number_format($test['stat_count']); ?></td>
                                            <td class="percentage"><?php echo $test['completion_rate']; ?>%</td>
                                            <td class="percentage"><?php echo $test['avg_turnaround_hours'] ?? 'N/A'; ?>h</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- Fill remaining rows if less than 15 -->
                                    <?php for ($i = count($top_tests); $i < 15; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No data</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="percentage">0.00%</td>
                                            <td class="percentage">0h</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <!-- Show 15 empty rows when no data -->
                                    <?php for ($i = 1; $i <= 15; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No test data</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="percentage">0.00%</td>
                                            <td class="percentage">0h</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Daily Trends Tab -->
                <div id="daily-trends" class="tab-content">
                    <div class="full-width-section">
                        <h3><i class="fas fa-chart-line"></i> Daily Laboratory Activity Trends</h3>
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Orders</th>
                                    <th>Tests</th>
                                    <th>Completed</th>
                                    <th>STAT Tests</th>
                                    <th>Completion Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($daily_trends)): ?>
                                    <?php foreach (array_slice($daily_trends, 0, 20) as $trend): ?>
                                        <?php $completion_rate = $trend['tests_count'] > 0 ? round(($trend['completed_count'] / $trend['tests_count']) * 100, 2) : 0; ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($trend['order_date'])); ?></td>
                                            <td class="count"><?php echo number_format($trend['orders_count']); ?></td>
                                            <td class="count"><?php echo number_format($trend['tests_count']); ?></td>
                                            <td class="count"><?php echo number_format($trend['completed_count']); ?></td>
                                            <td class="count"><?php echo number_format($trend['stat_count']); ?></td>
                                            <td class="percentage"><?php echo $completion_rate; ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- Fill remaining rows if less than 20 -->
                                    <?php for ($i = count($daily_trends); $i < 20; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No data</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="percentage">0.00%</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <!-- Show 20 empty rows when no data -->
                                    <?php for ($i = 1; $i <= 20; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No trend data</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="percentage">0.00%</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Turnaround Analysis Tab -->
                <div id="turnaround-analysis" class="tab-content">
                    <div class="full-width-section">
                        <h3><i class="fas fa-stopwatch"></i> Test Turnaround Time Analysis</h3>
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Test Type</th>
                                    <th>Completed Tests</th>
                                    <th>Avg TAT (Hours)</th>
                                    <th>Min TAT (Hours)</th>
                                    <th>Max TAT (Hours)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($turnaround_analysis)): ?>
                                    <?php foreach (array_slice($turnaround_analysis, 0, 15) as $turnaround): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($turnaround['test_type']); ?></strong></td>
                                            <td class="count"><?php echo number_format($turnaround['completed_tests']); ?></td>
                                            <td class="percentage"><?php echo $turnaround['avg_hours'] ?? 'N/A'; ?></td>
                                            <td class="percentage"><?php echo $turnaround['min_hours'] ?? 'N/A'; ?></td>
                                            <td class="percentage"><?php echo $turnaround['max_hours'] ?? 'N/A'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- Fill remaining rows if less than 15 -->
                                    <?php for ($i = count($turnaround_analysis); $i < 15; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No data</td>
                                            <td class="count">0</td>
                                            <td class="percentage">0</td>
                                            <td class="percentage">0</td>
                                            <td class="percentage">0</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <!-- Show 15 empty rows when no data -->
                                    <?php for ($i = 1; $i <= 15; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No turnaround data</td>
                                            <td class="count">0</td>
                                            <td class="percentage">0</td>
                                            <td class="percentage">0</td>
                                            <td class="percentage">0</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Activities Tab -->
                <div id="recent-activities" class="tab-content">
                    <div class="full-width-section">
                        <h3><i class="fas fa-clock"></i> Recent Laboratory Activities</h3>
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Order Date</th>
                                    <th>Patient</th>
                                    <th>Test Type</th>
                                    <th>Urgency</th>
                                    <th>Status</th>
                                    <th>TAT</th>
                                    <th>Ordered By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_activities)): ?>
                                    <?php foreach (array_slice($recent_activities, 0, 25) as $activity): ?>
                                        <?php 
                                        $status_badge_class = 'status-' . str_replace(' ', '_', $activity['status']);
                                        $urgency_badge_class = 'urgency-' . strtolower($activity['urgency']);
                                        ?>
                                        <tr>
                                            <td><?php echo date('M j, Y g:i A', strtotime($activity['order_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($activity['patient_name']); ?></td>
                                            <td><?php echo htmlspecialchars($activity['test_type']); ?></td>
                                            <td><span class="urgency-badge <?php echo $urgency_badge_class; ?>"><?php echo htmlspecialchars($activity['urgency']); ?></span></td>
                                            <td><span class="status-badge <?php echo $status_badge_class; ?>"><?php echo ucfirst(str_replace('_', ' ', $activity['status'])); ?></span></td>
                                            <td><?php echo $activity['turnaround_hours'] ? $activity['turnaround_hours'] . 'h' : 'N/A'; ?></td>
                                            <td><?php echo htmlspecialchars($activity['ordered_by'] ?? 'N/A'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- Fill remaining rows if less than 25 -->
                                    <?php for ($i = count($recent_activities); $i < 25; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No data</td>
                                            <td>-</td>
                                            <td>-</td>
                                            <td><span class="urgency-badge urgency-routine">Routine</span></td>
                                            <td><span class="status-badge status-pending">Pending</span></td>
                                            <td>N/A</td>
                                            <td>-</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <!-- Show 25 empty rows when no data -->
                                    <?php for ($i = 1; $i <= 25; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No activity data</td>
                                            <td>-</td>
                                            <td>-</td>
                                            <td><span class="urgency-badge urgency-routine">Routine</span></td>
                                            <td><span class="status-badge status-pending">Pending</span></td>
                                            <td>N/A</td>
                                            <td>-</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <script>
        // Tab functionality
        function showTab(tabId) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });

            // Remove active class from all buttons
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                button.classList.remove('active');
            });

            // Show selected tab content
            document.getElementById(tabId).classList.add('active');

            // Add active class to clicked button
            event.target.classList.add('active');
        }

        // Enhanced functionality
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Lab Statistics Report loaded successfully');
            
            // Add loading state to form submission
            const filterForm = document.querySelector('form');
            if (filterForm) {
                filterForm.addEventListener('submit', function() {
                    const submitButton = filterForm.querySelector('button[type="submit"]');
                    if (submitButton) {
                        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating...';
                        submitButton.disabled = true;
                    }
                });
            }

            // Add row highlighting to all analytics tables
            const analyticsRows = document.querySelectorAll('.analytics-table tbody tr');
            analyticsRows.forEach(row => {
                row.addEventListener('mouseenter', () => {
                    row.style.backgroundColor = 'var(--accent-color)';
                    row.style.transition = 'background-color 0.2s';
                });
                row.addEventListener('mouseleave', () => {
                    row.style.backgroundColor = '';
                });
            });

            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey || e.metaKey) {
                    switch(e.key) {
                        case 'r':
                            e.preventDefault();
                            window.location.href = '?';
                            break;
                    }
                }
            });
        });
    </script>
</body>

</html>