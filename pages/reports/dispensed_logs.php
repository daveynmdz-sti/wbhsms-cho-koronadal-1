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
$medication_filter = $_GET['medication_filter'] ?? '';
$employee_filter = $_GET['employee_filter'] ?? '';

try {
    // Get filter options for dropdowns
    $employees_stmt = $pdo->query("SELECT employee_id, CONCAT(first_name, ' ', last_name) as full_name FROM employees WHERE status = 'active' ORDER BY first_name");
    $employees = $employees_stmt->fetchAll(PDO::FETCH_ASSOC);

    $medications_stmt = $pdo->query("SELECT DISTINCT medication_name FROM prescribed_medications WHERE medication_name IS NOT NULL AND medication_name != '' ORDER BY medication_name");
    $medications = $medications_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build where clauses for filtering
    $where_conditions = ["DATE(pm.updated_at) BETWEEN :start_date AND :end_date"];
    $params = [
        'start_date' => $start_date,
        'end_date' => $end_date
    ];

    if (!empty($status_filter)) {
        $where_conditions[] = "pm.status = :status_filter";
        $params['status_filter'] = $status_filter;
    }

    if (!empty($medication_filter)) {
        $where_conditions[] = "pm.medication_name LIKE :medication_filter";
        $params['medication_filter'] = '%' . $medication_filter . '%';
    }

    if (!empty($employee_filter)) {
        $where_conditions[] = "p.prescribed_by_employee_id = :employee_filter";
        $params['employee_filter'] = $employee_filter;
    }

    $where_clause = implode(' AND ', $where_conditions);

    // 1. DISPENSING SUMMARY STATISTICS
    $summary_query = "
        SELECT 
            COUNT(DISTINCT pm.prescribed_medication_id) as total_medications,
            COUNT(DISTINCT p.prescription_id) as total_prescriptions,
            COUNT(DISTINCT p.patient_id) as total_patients,
            SUM(CASE WHEN pm.status = 'dispensed' THEN 1 ELSE 0 END) as dispensed_count,
            SUM(CASE WHEN pm.status = 'unavailable' THEN 1 ELSE 0 END) as unavailable_count,
            SUM(CASE WHEN pm.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            ROUND((SUM(CASE WHEN pm.status = 'dispensed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as dispensing_rate
        FROM prescribed_medications pm
        JOIN prescriptions p ON pm.prescription_id = p.prescription_id
        WHERE $where_clause
    ";
    
    $summary_stmt = $pdo->prepare($summary_query);
    $summary_stmt->execute($params);
    $summary_stats = $summary_stmt->fetch(PDO::FETCH_ASSOC);

    // 2. MEDICATION DISPENSING STATUS BREAKDOWN
    $status_breakdown_query = "
        SELECT 
            pm.status,
            COUNT(*) as count,
            ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM prescribed_medications pm2 
                JOIN prescriptions p2 ON pm2.prescription_id = p2.prescription_id 
                WHERE $where_clause)), 2) as percentage
        FROM prescribed_medications pm
        JOIN prescriptions p ON pm.prescription_id = p.prescription_id
        WHERE $where_clause
        GROUP BY pm.status
        ORDER BY count DESC
    ";
    
    $status_breakdown_stmt = $pdo->prepare($status_breakdown_query);
    $status_breakdown_stmt->execute($params);
    $status_breakdown = $status_breakdown_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. TOP DISPENSED MEDICATIONS
    $top_medications_query = "
        SELECT 
            pm.medication_name,
            COUNT(*) as prescription_count,
            SUM(CASE WHEN pm.status = 'dispensed' THEN 1 ELSE 0 END) as dispensed_count,
            SUM(CASE WHEN pm.status = 'unavailable' THEN 1 ELSE 0 END) as unavailable_count,
            SUM(CASE WHEN pm.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            ROUND((SUM(CASE WHEN pm.status = 'dispensed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as fulfillment_rate
        FROM prescribed_medications pm
        JOIN prescriptions p ON pm.prescription_id = p.prescription_id
        WHERE $where_clause
        GROUP BY pm.medication_name
        ORDER BY prescription_count DESC
        LIMIT 20
    ";
    
    $top_medications_stmt = $pdo->prepare($top_medications_query);
    $top_medications_stmt->execute($params);
    $top_medications = $top_medications_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. DISPENSING BY PRESCRIBING EMPLOYEE
    $employee_dispensing_query = "
        SELECT 
            CONCAT(e.first_name, ' ', e.last_name) as employee_name,
            e.role,
            COUNT(DISTINCT pm.prescribed_medication_id) as total_medications,
            COUNT(DISTINCT p.prescription_id) as prescriptions_count,
            SUM(CASE WHEN pm.status = 'dispensed' THEN 1 ELSE 0 END) as dispensed_count,
            SUM(CASE WHEN pm.status = 'unavailable' THEN 1 ELSE 0 END) as unavailable_count,
            ROUND((SUM(CASE WHEN pm.status = 'dispensed' THEN 1 ELSE 0 END) * 100.0 / COUNT(pm.prescribed_medication_id)), 2) as fulfillment_rate
        FROM prescribed_medications pm
        JOIN prescriptions p ON pm.prescription_id = p.prescription_id
        JOIN employees e ON p.prescribed_by_employee_id = e.employee_id
        WHERE $where_clause
        GROUP BY e.employee_id, e.first_name, e.last_name, e.role
        ORDER BY total_medications DESC
        LIMIT 15
    ";
    
    $employee_dispensing_stmt = $pdo->prepare($employee_dispensing_query);
    $employee_dispensing_stmt->execute($params);
    $employee_dispensing = $employee_dispensing_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. DAILY DISPENSING TRENDS
    $daily_trends_query = "
        SELECT 
            DATE(pm.updated_at) as dispensing_date,
            COUNT(*) as total_actions,
            SUM(CASE WHEN pm.status = 'dispensed' THEN 1 ELSE 0 END) as dispensed_count,
            SUM(CASE WHEN pm.status = 'unavailable' THEN 1 ELSE 0 END) as unavailable_count,
            SUM(CASE WHEN pm.status = 'pending' THEN 1 ELSE 0 END) as pending_count
        FROM prescribed_medications pm
        JOIN prescriptions p ON pm.prescription_id = p.prescription_id
        WHERE $where_clause
        GROUP BY DATE(pm.updated_at)
        ORDER BY dispensing_date DESC
        LIMIT 30
    ";
    
    $daily_trends_stmt = $pdo->prepare($daily_trends_query);
    $daily_trends_stmt->execute($params);
    $daily_trends = $daily_trends_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. RECENT DISPENSING ACTIVITIES
    $recent_activities_query = "
        SELECT 
            pm.prescribed_medication_id,
            pm.medication_name,
            pm.dosage,
            pm.status,
            pm.updated_at,
            CONCAT(pt.first_name, ' ', pt.last_name) as patient_name,
            CONCAT(e.first_name, ' ', e.last_name) as prescribed_by,
            p.prescription_date
        FROM prescribed_medications pm
        JOIN prescriptions p ON pm.prescription_id = p.prescription_id
        JOIN patients pt ON p.patient_id = pt.patient_id
        JOIN employees e ON p.prescribed_by_employee_id = e.employee_id
        WHERE $where_clause AND pm.status != 'pending'
        ORDER BY pm.updated_at DESC
        LIMIT 50
    ";
    
    $recent_activities_stmt = $pdo->prepare($recent_activities_query);
    $recent_activities_stmt->execute($params);
    $recent_activities = $recent_activities_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. UNAVAILABLE MEDICATIONS ANALYSIS
    $unavailable_analysis_query = "
        SELECT 
            pm.medication_name,
            pm.dosage,
            COUNT(*) as unavailable_count,
            MIN(pm.updated_at) as first_unavailable,
            MAX(pm.updated_at) as last_unavailable
        FROM prescribed_medications pm
        JOIN prescriptions p ON pm.prescription_id = p.prescription_id
        WHERE $where_clause AND pm.status = 'unavailable'
        GROUP BY pm.medication_name, pm.dosage
        ORDER BY unavailable_count DESC
        LIMIT 15
    ";
    
    $unavailable_analysis_stmt = $pdo->prepare($unavailable_analysis_query);
    $unavailable_analysis_stmt->execute($params);
    $unavailable_analysis = $unavailable_analysis_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle zero division
    if (!$summary_stats['total_medications']) {
        $summary_stats = [
            'total_medications' => 0,
            'total_prescriptions' => 0,
            'total_patients' => 0,
            'dispensed_count' => 0,
            'unavailable_count' => 0,
            'pending_count' => 0,
            'dispensing_rate' => 0
        ];
    }

} catch (Exception $e) {
    error_log("Dispensed Logs Report Error: " . $e->getMessage());
    $error = "Error generating dispensed logs report. Please try again.";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispensed Logs Report - CHO Koronadal</title>

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

        /* Dispensed logs content section */
        .dispensed-content {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .dispensed-content h3 {
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .dispensed-content h3 i {
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

        .status-dispensed {
            background: #d1e7dd;
            color: #0f5132;
        }

        .status-unavailable {
            background: #f8d7da;
            color: #721c24;
        }

        .status-pending {
            background: #fff3cd;
            color: #664d03;
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

        /* Placeholder for dispensed logs data */
        .dispensed-placeholder {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
            font-style: italic;
        }

        .dispensed-placeholder i {
            font-size: 48px;
            color: var(--accent-color);
            margin-bottom: 15px;
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

            .dispensed-content {
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
                <span> Dispensed Logs</span>
            </div>

            <div class="page-header">
                <h1><i class="fas fa-prescription-bottle-alt"></i> Dispensed Logs Report</h1>
                <p>Comprehensive tracking of medication dispensing activities and pharmaceutical availability</p>
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
                <p>This report provides detailed insights into medication dispensing activities including dispensed quantities, prescription fulfillment rates, popular medications, and pharmaceutical availability. Use this data to monitor medication usage patterns, optimize inventory management, and ensure compliance with dispensing protocols.</p>
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
                                <option value="dispensed" <?php echo $status_filter == 'dispensed' ? 'selected' : ''; ?>>Dispensed</option>
                                <option value="unavailable" <?php echo $status_filter == 'unavailable' ? 'selected' : ''; ?>>Unavailable</option>
                                <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="medication_filter">Medication</label>
                            <select id="medication_filter" name="medication_filter">
                                <option value="">All Medications</option>
                                <?php foreach ($medications as $medication): ?>
                                    <option value="<?php echo htmlspecialchars($medication['medication_name']); ?>" 
                                        <?php echo $medication_filter == $medication['medication_name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($medication['medication_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="employee_filter">Prescribed By</label>
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
                        <a href="export_dispensed_pdf.php?<?php echo http_build_query($_GET); ?>" class="btn btn-export" target="_blank">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </a>
                    </div>
                </form>
            </div>

            <!-- Summary Statistics -->
            <div class="summary-cards">
                <div class="summary-card">
                    <i class="fas fa-pills"></i>
                    <div class="number"><?php echo number_format($summary_stats['total_medications'] ?? 0); ?></div>
                    <div class="label">Total Medications</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-prescription"></i>
                    <div class="number"><?php echo number_format($summary_stats['total_prescriptions'] ?? 0); ?></div>
                    <div class="label">Total Prescriptions</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-users"></i>
                    <div class="number"><?php echo number_format($summary_stats['total_patients'] ?? 0); ?></div>
                    <div class="label">Unique Patients</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-check-circle"></i>
                    <div class="number"><?php echo number_format($summary_stats['dispensed_count'] ?? 0); ?></div>
                    <div class="label">Dispensed</div>
                    <div class="percentage"><?php echo number_format($summary_stats['dispensing_rate'] ?? 0, 1); ?>% Rate</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-times-circle"></i>
                    <div class="number"><?php echo number_format($summary_stats['unavailable_count'] ?? 0); ?></div>
                    <div class="label">Unavailable</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-clock"></i>
                    <div class="number"><?php echo number_format($summary_stats['pending_count'] ?? 0); ?></div>
                    <div class="label">Pending</div>
                </div>
            </div>

            <!-- Main Analytics Grid -->
            <div class="analytics-grid">
                <!-- Status Breakdown -->
                <div class="analytics-section">
                    <h3><i class="fas fa-chart-pie"></i> Status Distribution</h3>
                    <p style="font-size: 14px; color: var(--text-light); margin-bottom: 15px;">
                        Medication dispensing status breakdown (Period: <?php echo $start_date; ?> to <?php echo $end_date; ?>)
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
                            $all_statuses = ['dispensed', 'unavailable', 'pending'];
                            $existing_status_data = [];
                            
                            if (!empty($status_breakdown)) {
                                foreach ($status_breakdown as $status) {
                                    $existing_status_data[$status['status']] = $status;
                                }
                            }
                            
                            foreach ($all_statuses as $status_name): 
                                if (isset($existing_status_data[$status_name])) {
                                    $status_data = $existing_status_data[$status_name];
                                    $badge_class = 'status-' . $status_name;
                                    echo '
                                    <tr>
                                        <td><span class="status-badge ' . $badge_class . '">' . ucfirst($status_data['status']) . '</span></td>
                                        <td class="count">' . number_format($status_data['count']) . '</td>
                                        <td class="percentage">' . $status_data['percentage'] . '%</td>
                                    </tr>';
                                } else {
                                    $badge_class = 'status-' . $status_name;
                                    echo '
                                    <tr style="opacity: 0.5;">
                                        <td><span class="status-badge ' . $badge_class . '">' . ucfirst($status_name) . '</span></td>
                                        <td class="count">0</td>
                                        <td class="percentage">0.00%</td>
                                    </tr>';
                                }
                            endforeach; 
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- Top Medications -->
                <div class="analytics-section">
                    <h3><i class="fas fa-list-ol"></i> Top Prescribed Medications</h3>
                    <p style="font-size: 14px; color: var(--text-light); margin-bottom: 15px;">
                        Most frequently prescribed medications
                    </p>
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Medication</th>
                                <th>Total</th>
                                <th>Dispensed</th>
                                <th>Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($top_medications)): ?>
                                <?php foreach (array_slice($top_medications, 0, 8) as $medication): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($medication['medication_name']); ?></td>
                                        <td class="count"><?php echo number_format($medication['prescription_count']); ?></td>
                                        <td class="count"><?php echo number_format($medication['dispensed_count']); ?></td>
                                        <td class="percentage"><?php echo $medication['fulfillment_rate']; ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                <!-- Fill remaining rows if less than 8 -->
                                <?php for ($i = count($top_medications); $i < 8; $i++): ?>
                                    <tr style="opacity: 0.5;">
                                        <td style="font-style: italic; color: var(--text-light);">No data</td>
                                        <td class="count">0</td>
                                        <td class="count">0</td>
                                        <td class="percentage">0.00%</td>
                                    </tr>
                                <?php endfor; ?>
                            <?php else: ?>
                                <!-- Show 8 empty rows when no data -->
                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                    <tr style="opacity: 0.5;">
                                        <td style="font-style: italic; color: var(--text-light);">No medication data</td>
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

            <!-- Tabbed Analytics -->
            <div class="tab-container">
                <div class="tab-buttons">
                    <button class="tab-button active" onclick="showTab('employee-analysis')">
                        <i class="fas fa-user-md"></i> Employee Analysis
                    </button>
                    <button class="tab-button" onclick="showTab('daily-trends')">
                        <i class="fas fa-chart-line"></i> Daily Trends
                    </button>
                    <button class="tab-button" onclick="showTab('recent-activities')">
                        <i class="fas fa-clock"></i> Recent Activities
                    </button>
                    <button class="tab-button" onclick="showTab('unavailable-analysis')">
                        <i class="fas fa-exclamation-triangle"></i> Unavailable Analysis
                    </button>
                </div>

                <!-- Employee Analysis Tab -->
                <div id="employee-analysis" class="tab-content active">
                    <div class="full-width-section">
                        <h3><i class="fas fa-user-md"></i> Dispensing by Prescribing Employee</h3>
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Role</th>
                                    <th>Medications</th>
                                    <th>Prescriptions</th>
                                    <th>Dispensed</th>
                                    <th>Unavailable</th>
                                    <th>Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($employee_dispensing)): ?>
                                    <?php foreach (array_slice($employee_dispensing, 0, 15) as $employee): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($employee['employee_name']); ?></td>
                                            <td><?php echo htmlspecialchars(ucfirst($employee['role'])); ?></td>
                                            <td class="count"><?php echo number_format($employee['total_medications']); ?></td>
                                            <td class="count"><?php echo number_format($employee['prescriptions_count']); ?></td>
                                            <td class="count"><?php echo number_format($employee['dispensed_count']); ?></td>
                                            <td class="count"><?php echo number_format($employee['unavailable_count']); ?></td>
                                            <td class="percentage"><?php echo $employee['fulfillment_rate']; ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- Fill remaining rows if less than 15 -->
                                    <?php for ($i = count($employee_dispensing); $i < 15; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No data</td>
                                            <td>-</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="percentage">0.00%</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <!-- Show 15 empty rows when no data -->
                                    <?php for ($i = 1; $i <= 15; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No employee data</td>
                                            <td>-</td>
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

                <!-- Daily Trends Tab -->
                <div id="daily-trends" class="tab-content">
                    <div class="full-width-section">
                        <h3><i class="fas fa-chart-line"></i> Daily Dispensing Trends</h3>
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Total Actions</th>
                                    <th>Dispensed</th>
                                    <th>Unavailable</th>
                                    <th>Pending</th>
                                    <th>Success Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($daily_trends)): ?>
                                    <?php foreach (array_slice($daily_trends, 0, 20) as $trend): ?>
                                        <?php $success_rate = $trend['total_actions'] > 0 ? round(($trend['dispensed_count'] / $trend['total_actions']) * 100, 2) : 0; ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($trend['dispensing_date'])); ?></td>
                                            <td class="count"><?php echo number_format($trend['total_actions']); ?></td>
                                            <td class="count"><?php echo number_format($trend['dispensed_count']); ?></td>
                                            <td class="count"><?php echo number_format($trend['unavailable_count']); ?></td>
                                            <td class="count"><?php echo number_format($trend['pending_count']); ?></td>
                                            <td class="percentage"><?php echo $success_rate; ?>%</td>
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

                <!-- Recent Activities Tab -->
                <div id="recent-activities" class="tab-content">
                    <div class="full-width-section">
                        <h3><i class="fas fa-clock"></i> Recent Dispensing Activities</h3>
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Date/Time</th>
                                    <th>Patient</th>
                                    <th>Medication</th>
                                    <th>Dosage</th>
                                    <th>Status</th>
                                    <th>Prescribed By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_activities)): ?>
                                    <?php foreach (array_slice($recent_activities, 0, 25) as $activity): ?>
                                        <?php $badge_class = 'status-' . $activity['status']; ?>
                                        <tr>
                                            <td><?php echo date('M j, Y g:i A', strtotime($activity['updated_at'])); ?></td>
                                            <td><?php echo htmlspecialchars($activity['patient_name']); ?></td>
                                            <td><?php echo htmlspecialchars($activity['medication_name']); ?></td>
                                            <td><?php echo htmlspecialchars($activity['dosage']); ?></td>
                                            <td><span class="status-badge <?php echo $badge_class; ?>"><?php echo ucfirst($activity['status']); ?></span></td>
                                            <td><?php echo htmlspecialchars($activity['prescribed_by']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- Fill remaining rows if less than 25 -->
                                    <?php for ($i = count($recent_activities); $i < 25; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No data</td>
                                            <td>-</td>
                                            <td>-</td>
                                            <td>-</td>
                                            <td><span class="status-badge status-pending">No Status</span></td>
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
                                            <td>-</td>
                                            <td><span class="status-badge status-pending">No Status</span></td>
                                            <td>-</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Unavailable Analysis Tab -->
                <div id="unavailable-analysis" class="tab-content">
                    <div class="full-width-section">
                        <h3><i class="fas fa-exclamation-triangle"></i> Unavailable Medications Analysis</h3>
                        <p style="font-size: 14px; color: var(--text-light); margin-bottom: 15px;">
                            Medications marked as unavailable during the selected period
                        </p>
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Medication</th>
                                    <th>Dosage</th>
                                    <th>Times Unavailable</th>
                                    <th>First Occurrence</th>
                                    <th>Last Occurrence</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($unavailable_analysis)): ?>
                                    <?php foreach (array_slice($unavailable_analysis, 0, 15) as $unavailable): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($unavailable['medication_name']); ?></td>
                                            <td><?php echo htmlspecialchars($unavailable['dosage']); ?></td>
                                            <td class="count"><?php echo number_format($unavailable['unavailable_count']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($unavailable['first_unavailable'])); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($unavailable['last_unavailable'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- Fill remaining rows if less than 15 -->
                                    <?php for ($i = count($unavailable_analysis); $i < 15; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No data</td>
                                            <td>-</td>
                                            <td class="count">0</td>
                                            <td>-</td>
                                            <td>-</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <!-- Show 15 empty rows when no data -->
                                    <?php for ($i = 1; $i <= 15; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No unavailable data</td>
                                            <td>-</td>
                                            <td class="count">0</td>
                                            <td>-</td>
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
            console.log('Dispensed Logs Report loaded successfully');
            
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