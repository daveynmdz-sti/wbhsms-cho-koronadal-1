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

// Date filtering logic
$filter_type = $_GET['filter_type'] ?? 'date_range';
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // Default to start of current month
$date_to = $_GET['date_to'] ?? date('Y-m-t'); // Default to end of current month
$month_from = $_GET['month_from'] ?? date('Y-m');
$month_to = $_GET['month_to'] ?? date('Y-m');
$year_from = $_GET['year_from'] ?? date('Y');
$year_to = $_GET['year_to'] ?? date('Y');

// Build WHERE clause based on filter type
$where_clause = "";
$params = [];

switch ($filter_type) {
    case 'month_range':
        $where_clause = "DATE(r.referral_date) >= ? AND DATE(r.referral_date) <= ?";
        $params = [$month_from . '-01', date('Y-m-t', strtotime($month_to . '-01'))];
        break;
    case 'year_range':
        $where_clause = "YEAR(r.referral_date) >= ? AND YEAR(r.referral_date) <= ?";
        $params = [$year_from, $year_to];
        break;
    case 'date_range':
    default:
        $where_clause = "DATE(r.referral_date) >= ? AND DATE(r.referral_date) <= ?";
        $params = [$date_from, $date_to];
        break;
}

try {
    // 1. Key Metrics Summary
    $metrics_query = "
        SELECT 
            COUNT(*) as total_referrals,
            SUM(CASE WHEN r.status = 'accepted' THEN 1 ELSE 0 END) as accepted_referrals,
            SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_referrals,
            SUM(CASE WHEN r.destination_type = 'external' AND r.status = 'issued' THEN 1 ELSE 0 END) as external_issued,
            SUM(CASE WHEN r.status = 'active' THEN 1 ELSE 0 END) as active_referrals
        FROM referrals r 
        WHERE $where_clause
    ";

    $metrics_stmt = $pdo->prepare($metrics_query);
    $metrics_stmt->execute($params);
    $metrics = $metrics_stmt->fetch(PDO::FETCH_ASSOC);

    // Calculate completion rate
    $completion_rate = $metrics['total_referrals'] > 0 ?
        round(($metrics['accepted_referrals'] / $metrics['total_referrals']) * 100, 2) : 0;

    // 2. Facility-to-Facility Transfers
    $facility_query = "
        SELECT 
            rf.name as referring_facility,
            COALESCE(tf.name, r.external_facility_name, 'External Facility') as referred_to_facility,
            COUNT(*) as total_referrals,
            SUM(CASE WHEN r.status = 'accepted' THEN 1 ELSE 0 END) as accepted,
            SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN r.status = 'issued' THEN 1 ELSE 0 END) as issued
        FROM referrals r
        LEFT JOIN facilities rf ON r.referring_facility_id = rf.facility_id
        LEFT JOIN facilities tf ON r.referred_to_facility_id = tf.facility_id
        WHERE $where_clause
        GROUP BY r.referring_facility_id, rf.name, r.referred_to_facility_id, tf.name, r.external_facility_name, 
                 COALESCE(tf.name, r.external_facility_name, 'External Facility')
        ORDER BY total_referrals DESC
    ";

    $facility_stmt = $pdo->prepare($facility_query);
    $facility_stmt->execute($params);
    $facility_transfers = $facility_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Destination Type Distribution
    $destination_query = "
        SELECT 
            destination_type,
            count,
            ROUND((count * 100.0 / total_count), 2) as percentage
        FROM (
            SELECT 
                r.destination_type,
                COUNT(*) as count,
                (SELECT COUNT(*) FROM referrals r2 WHERE $where_clause) as total_count
            FROM referrals r
            WHERE $where_clause
            GROUP BY r.destination_type
        ) dest_summary
        ORDER BY count DESC
    ";

    $destination_stmt = $pdo->prepare($destination_query);
    $destination_stmt->execute(array_merge($params, $params)); // Double params for subquery
    $destination_types = $destination_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Referral Reasons Breakdown
    $reasons_query = "
        SELECT 
            CASE 
                WHEN LOWER(r.referral_reason) LIKE '%consultation%' THEN 'Consultation'
                WHEN LOWER(r.referral_reason) LIKE '%check%up%' OR LOWER(r.referral_reason) LIKE '%checkup%' THEN 'Check-up'
                WHEN LOWER(r.referral_reason) LIKE '%lab%' OR LOWER(r.referral_reason) LIKE '%laboratory%' THEN 'Laboratory'
                WHEN LOWER(r.referral_reason) LIKE '%mri%' OR LOWER(r.referral_reason) LIKE '%ct%scan%' THEN 'Imaging'
                WHEN LOWER(r.referral_reason) LIKE '%emergency%' OR LOWER(r.referral_reason) LIKE '%urgent%' THEN 'Emergency'
                ELSE 'Other'
            END as reason_category,
            COUNT(*) as count
        FROM referrals r
        WHERE $where_clause AND r.referral_reason IS NOT NULL
        GROUP BY CASE 
                WHEN LOWER(r.referral_reason) LIKE '%consultation%' THEN 'Consultation'
                WHEN LOWER(r.referral_reason) LIKE '%check%up%' OR LOWER(r.referral_reason) LIKE '%checkup%' THEN 'Check-up'
                WHEN LOWER(r.referral_reason) LIKE '%lab%' OR LOWER(r.referral_reason) LIKE '%laboratory%' THEN 'Laboratory'
                WHEN LOWER(r.referral_reason) LIKE '%mri%' OR LOWER(r.referral_reason) LIKE '%ct%scan%' THEN 'Imaging'
                WHEN LOWER(r.referral_reason) LIKE '%emergency%' OR LOWER(r.referral_reason) LIKE '%urgent%' THEN 'Emergency'
                ELSE 'Other'
            END
        ORDER BY count DESC
        LIMIT 5
    ";

    $reasons_stmt = $pdo->prepare($reasons_query);
    $reasons_stmt->execute($params);
    $referral_reasons = $reasons_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Referral Timeline Trends
    $timeline_query = "
        SELECT 
            DATE(r.referral_date) as referral_day,
            COUNT(*) as daily_count,
            SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count
        FROM referrals r
        WHERE $where_clause
        GROUP BY DATE(r.referral_date)
        ORDER BY referral_day ASC
    ";

    $timeline_stmt = $pdo->prepare($timeline_query);
    $timeline_stmt->execute($params);
    $timeline_data = $timeline_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Referral Logs Activity
    $logs_query = "
        SELECT 
            rl.log_id,
            r.referral_num,
            e.first_name,
            e.last_name,
            rl.action,
            rl.reason,
            rl.previous_status,
            rl.new_status,
            rl.timestamp
        FROM referral_logs rl
        JOIN referrals r ON rl.referral_id = r.referral_id
        JOIN employees e ON rl.employee_id = e.employee_id
        WHERE $where_clause
        ORDER BY rl.timestamp DESC
        LIMIT 20
    ";

    $logs_stmt = $pdo->prepare($logs_query);
    $logs_stmt->execute($params);
    $referral_logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. Inter-Facility Coordination Statistics
    $coordination_query = "
        SELECT 
            AVG(TIMESTAMPDIFF(DAY, r.referral_date, r.updated_at)) as avg_response_time,
            (SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) as cancellation_rate,
            (SUM(CASE WHEN r.destination_type = 'external' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) as external_ratio
        FROM referrals r
        WHERE $where_clause
    ";

    $coordination_stmt = $pdo->prepare($coordination_query);
    $coordination_stmt->execute($params);
    $coordination_stats = $coordination_stmt->fetch(PDO::FETCH_ASSOC);

    // Most active facilities
    $active_referring_query = "
        SELECT f.name, COUNT(*) as referral_count
        FROM referrals r
        JOIN facilities f ON r.referring_facility_id = f.facility_id
        WHERE $where_clause
        GROUP BY r.referring_facility_id, f.name
        ORDER BY referral_count DESC
        LIMIT 1
    ";

    $active_referring_stmt = $pdo->prepare($active_referring_query);
    $active_referring_stmt->execute($params);
    $most_active_referring = $active_referring_stmt->fetch(PDO::FETCH_ASSOC);

    $active_receiving_query = "
        SELECT COALESCE(f.name, 'External Facilities') as name, COUNT(*) as referral_count
        FROM referrals r
        LEFT JOIN facilities f ON r.referred_to_facility_id = f.facility_id
        WHERE $where_clause
        GROUP BY r.referred_to_facility_id, f.name, COALESCE(f.name, 'External Facilities')
        ORDER BY referral_count DESC
        LIMIT 1
    ";

    $active_receiving_stmt = $pdo->prepare($active_receiving_query);
    $active_receiving_stmt->execute($params);
    $most_active_receiving = $active_receiving_stmt->fetch(PDO::FETCH_ASSOC);

    // 8. Detailed Referral Table
    $detailed_query = "
        SELECT 
            r.referral_num,
            r.patient_id,
            rf.name as referring_facility,
            COALESCE(tf.name, r.external_facility_name) as referred_to_facility,
            r.destination_type,
            r.referral_reason,
            r.referral_date,
            r.status,
            r.updated_at
        FROM referrals r
        LEFT JOIN facilities rf ON r.referring_facility_id = rf.facility_id
        LEFT JOIN facilities tf ON r.referred_to_facility_id = tf.facility_id
        WHERE $where_clause
        ORDER BY r.referral_date DESC
        LIMIT 50
    ";

    $detailed_stmt = $pdo->prepare($detailed_query);
    $detailed_stmt->execute($params);
    $detailed_referrals = $detailed_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Error loading referral data: " . $e->getMessage();
    // Initialize empty arrays to prevent PHP errors
    $metrics = ['total_referrals' => 0, 'accepted_referrals' => 0, 'cancelled_referrals' => 0, 'external_issued' => 0, 'active_referrals' => 0];
    $completion_rate = 0;
    $facility_transfers = [];
    $destination_types = [];
    $referral_reasons = [];
    $timeline_data = [];
    $referral_logs = [];
    $coordination_stats = ['avg_response_time' => 0, 'cancellation_rate' => 0, 'external_ratio' => 0];
    $most_active_referring = ['name' => 'N/A', 'referral_count' => 0];
    $most_active_receiving = ['name' => 'N/A', 'referral_count' => 0];
    $detailed_referrals = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Summary Report - CHO Koronadal</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">

    <!-- CSS Assets -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/sidebar.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

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

        /* Referral content section */
        .referral-content {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .referral-content h3 {
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .referral-content h3 i {
            color: var(--primary-color);
        }

        /* Statistics tiles */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-tile {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 25px;
            border-radius: 12px;
            text-align: left;
            box-shadow: var(--shadow-light);
            transition: transform 0.3s ease;
        }

        .stat-tile:hover {
            transform: translateY(-5px);
        }

        .stat-tile h3 {
            font-size: 2.2rem;
            margin: 0 0 8px 0;
            font-weight: 700;
        }

        .stat-tile p {
            margin: 0;
            font-size: 14px;
            opacity: 0.9;
        }

        .stat-tile.completion-rate {
            background: linear-gradient(135deg, var(--success-color), #48c78e);
        }

        .stat-tile.warning {
            background: linear-gradient(135deg, var(--warning-color), #ffed4e);
            color: var(--text-dark);
        }

        .stat-tile.danger {
            background: linear-gradient(135deg, var(--danger-color), #ff6b9d);
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
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text-dark);
            font-size: 14px;
        }

        .form-group input,
        .form-group select {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-warning {
            background: var(--warning-color);
            color: var(--text-dark);
        }

        .btn-secondary {
            background: var(--text-light);
            color: white;
        }

        /* Export buttons */
        .export-section {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        /* Data tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--shadow-light);
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .data-table th {
            background: var(--primary-color);
            color: white;
            font-weight: 600;
            font-size: 14px;
        }

        .data-table td {
            font-size: 14px;
            color: var(--text-dark);
        }

        .data-table tbody tr:hover {
            background: #f8f9fa;
        }

        /* Status badges */
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-accepted {
            background: #d4edda;
            color: #155724;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .status-active {
            background: #d1ecf1;
            color: #055160;
        }

        .status-issued {
            background: #fff3cd;
            color: #664d03;
        }

        /* Charts container */
        .charts-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .chart-container {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .chart-container h4 {
            color: var(--primary-dark);
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-container canvas {
            max-height: 300px;
        }

        /* Coordination stats */
        .coordination-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .coord-stat {
            background: var(--background-light);
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
            text-align: center;
        }

        .coord-stat h4 {
            color: var(--primary-dark);
            margin: 0 0 10px 0;
            font-size: 16px;
        }

        .coord-stat .value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .coord-stat .unit {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
        }

        /* Print styles */
        @media print {

            .filter-section,
            .export-section,
            .breadcrumb,
            .sidebar {
                display: none !important;
            }

            .homepage {
                margin-left: 0 !important;
            }

            .content-wrapper {
                padding: 1rem !important;
            }

            .charts-container {
                grid-template-columns: 1fr !important;
            }
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: 1fr;
            }

            .charts-container {
                grid-template-columns: 1fr;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .export-section {
                justify-content: center;
            }

            .data-table {
                font-size: 12px;
            }

            .data-table th,
            .data-table td {
                padding: 8px;
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
                <span> Referral Summary</span>
            </div>

            <div class="page-header">
                <h1><i class="fas fa-share"></i> Referral Summary Report</h1>
                <p>Comprehensive analysis of patient referrals, trends, and facility coordination</p>
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
                <p>This report provides detailed insights into referral patterns including facility-to-facility transfers, referral reasons, completion rates, and inter-facility coordination statistics. Generated from wbhsms_database with real-time data analysis.</p>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3><i class="fas fa-filter"></i> Date Filter</h3>
                <form method="GET" class="filter-form" id="filterForm">
                    <div class="form-group">
                        <label for="filter_type">Filter Type</label>
                        <select name="filter_type" id="filter_type" onchange="toggleFilterInputs()">
                            <option value="date_range" <?php echo $filter_type === 'date_range' ? 'selected' : ''; ?>>Date Range</option>
                            <option value="month_range" <?php echo $filter_type === 'month_range' ? 'selected' : ''; ?>>Month Range</option>
                            <option value="year_range" <?php echo $filter_type === 'year_range' ? 'selected' : ''; ?>>Year Range</option>
                        </select>
                    </div>

                    <!-- Date Range Inputs -->
                    <div class="form-group" id="date_from_group">
                        <label for="date_from">From Date</label>
                        <input type="date" name="date_from" id="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="form-group" id="date_to_group">
                        <label for="date_to">To Date</label>
                        <input type="date" name="date_to" id="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>

                    <!-- Month Range Inputs -->
                    <div class="form-group" id="month_from_group" style="display: none;">
                        <label for="month_from">From Month</label>
                        <input type="month" name="month_from" id="month_from" value="<?php echo htmlspecialchars($month_from); ?>">
                    </div>
                    <div class="form-group" id="month_to_group" style="display: none;">
                        <label for="month_to">To Month</label>
                        <input type="month" name="month_to" id="month_to" value="<?php echo htmlspecialchars($month_to); ?>">
                    </div>

                    <!-- Year Range Inputs -->
                    <div class="form-group" id="year_from_group" style="display: none;">
                        <label for="year_from">From Year</label>
                        <input type="number" name="year_from" id="year_from" min="2020" max="2030" value="<?php echo htmlspecialchars($year_from); ?>">
                    </div>
                    <div class="form-group" id="year_to_group" style="display: none;">
                        <label for="year_to">To Year</label>
                        <input type="number" name="year_to" id="year_to" min="2020" max="2030" value="<?php echo htmlspecialchars($year_to); ?>">
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Apply Filter
                        </button>
                    </div>
                </form>
            </div>
            <div class="referral-content">

                <!-- Export Buttons -->
                <div class="export-section">
                    <!--<form method="POST" action="export_referral_summary_robust.php" style="display: inline;">
                        <input type="hidden" name="filter_type" value="<?php echo htmlspecialchars($filter_type); ?>">
                        <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        <input type="hidden" name="month_from" value="<?php echo htmlspecialchars($month_from); ?>">
                        <input type="hidden" name="month_to" value="<?php echo htmlspecialchars($month_to); ?>">
                        <input type="hidden" name="year_from" value="<?php echo htmlspecialchars($year_from); ?>">
                        <input type="hidden" name="year_to" value="<?php echo htmlspecialchars($year_to); ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-download"></i> Export PDF Report
                        </button>
                    </form>-->
                    <form method="POST" action="export_referral_summary_pdf.php" style="display: inline;">
                        <input type="hidden" name="filter_type" value="<?php echo htmlspecialchars($filter_type); ?>">
                        <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        <input type="hidden" name="month_from" value="<?php echo htmlspecialchars($month_from); ?>">
                        <input type="hidden" name="month_to" value="<?php echo htmlspecialchars($month_to); ?>">
                        <input type="hidden" name="year_from" value="<?php echo htmlspecialchars($year_from); ?>">
                        <input type="hidden" name="year_to" value="<?php echo htmlspecialchars($year_to); ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-file-pdf"></i> Download PDF Report
                        </button>
                    </form>
                    <!--<form method="GET" action="export_referral_summary_html.php" style="display: inline;">
                        <input type="hidden" name="filter_type" value="<?php echo htmlspecialchars($filter_type); ?>">
                        <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        <input type="hidden" name="month_from" value="<?php echo htmlspecialchars($month_from); ?>">
                        <input type="hidden" name="month_to" value="<?php echo htmlspecialchars($month_to); ?>">
                        <input type="hidden" name="year_from" value="<?php echo htmlspecialchars($year_from); ?>">
                        <input type="hidden" name="year_to" value="<?php echo htmlspecialchars($year_to); ?>">
                        <button type="submit" class="btn btn-info">
                            <i class="fas fa-print"></i> Print PDF Report
                        </button>
                    </form>
                    <form method="GET" action="export_referral_summary_simple.php" style="display: inline;">
                        <input type="hidden" name="filter_type" value="<?php echo htmlspecialchars($filter_type); ?>">
                        <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                        <input type="hidden" name="month_from" value="<?php echo htmlspecialchars($month_from); ?>">
                        <input type="hidden" name="month_to" value="<?php echo htmlspecialchars($month_to); ?>">
                        <input type="hidden" name="year_from" value="<?php echo htmlspecialchars($year_from); ?>">
                        <input type="hidden" name="year_to" value="<?php echo htmlspecialchars($year_to); ?>">
                        <button type="submit" class="btn btn-secondary">
                            <i class="fas fa-file-alt"></i> Text Report
                        </button>
                    </form>
                    <button onclick="exportToExcel()" class="btn btn-success">
                        <i class="fas fa-file-excel"></i> Export Excel
                    </button>
                    <button onclick="window.print()" class="btn btn-secondary">
                        <i class="fas fa-print"></i> Print Report
                    </button>-->
                </div>
                <h3><i class="fas fa-chart-pie"></i> Key Statistics</h3>
                <!-- Key Metrics Summary -->
                <div class="stats-container">

                    <div class="stat-tile">
                        <h3><?php echo number_format($metrics['total_referrals']); ?></h3>
                        <p>Total Referrals</p>
                    </div>
                    <div class="stat-tile">
                        <h3><?php echo number_format($metrics['accepted_referrals']); ?></h3>
                        <p>Accepted Referrals</p>
                    </div>
                    <div class="stat-tile danger">
                        <h3><?php echo number_format($metrics['cancelled_referrals']); ?></h3>
                        <p>Cancelled Referrals</p>
                    </div>
                    <div class="stat-tile warning">
                        <h3><?php echo number_format($metrics['external_issued']); ?></h3>
                        <p>External Issued</p>
                    </div>
                    <div class="stat-tile">
                        <h3><?php echo number_format($metrics['active_referrals']); ?></h3>
                        <p>Active Referrals</p>
                    </div>
                    <div class="stat-tile completion-rate">
                        <h3><?php echo $completion_rate; ?>%</h3>
                        <p>Completion Rate</p>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="charts-container">
                    <div class="chart-container">
                        <h4><i class="fas fa-chart-pie"></i> Destination Type Distribution</h4>
                        <canvas id="destinationChart"></canvas>
                    </div>
                    <div class="chart-container">
                        <h4><i class="fas fa-chart-line"></i> Referral Timeline</h4>
                        <canvas id="timelineChart"></canvas>
                    </div>
                </div>

                <!-- Inter-Facility Coordination Statistics -->
                <div class="coordination-stats">
                    <div class="coord-stat">
                        <h4>Average Response Time</h4>
                        <div class="value"><?php echo round($coordination_stats['avg_response_time'] ?? 0, 1); ?></div>
                        <div class="unit">Days</div>
                    </div>
                    <div class="coord-stat">
                        <h4>Cancellation Rate</h4>
                        <div class="value"><?php echo round($coordination_stats['cancellation_rate'] ?? 0, 1); ?>%</div>
                        <div class="unit">Percentage</div>
                    </div>
                    <div class="coord-stat">
                        <h4>External Referral Ratio</h4>
                        <div class="value"><?php echo round($coordination_stats['external_ratio'] ?? 0, 1); ?>%</div>
                        <div class="unit">Percentage</div>
                    </div>
                    <div class="coord-stat">
                        <h4>Most Active Referring</h4>
                        <div class="value" style="font-size: 1.2rem;"><?php echo htmlspecialchars($most_active_referring['name'] ?? 'N/A'); ?></div>
                        <div class="unit"><?php echo $most_active_referring['referral_count'] ?? 0; ?> referrals</div>
                    </div>
                    <div class="coord-stat">
                        <h4>Most Active Receiving</h4>
                        <div class="value" style="font-size: 1.2rem;"><?php echo htmlspecialchars($most_active_receiving['name'] ?? 'N/A'); ?></div>
                        <div class="unit"><?php echo $most_active_receiving['referral_count'] ?? 0; ?> referrals</div>
                    </div>
                </div>

                <!-- Facility-to-Facility Transfers -->
                <div class="referral-content">
                    <h3><i class="fas fa-exchange-alt"></i> Facility-to-Facility Transfers</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Referring Facility</th>
                                <th>Referred To Facility</th>
                                <th>Total Referrals</th>
                                <th>Accepted</th>
                                <th>Cancelled</th>
                                <th>Issued</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($facility_transfers)): ?>
                                <tr>
                                    <td colspan="6" class="text-center">No facility transfer data found for the selected period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($facility_transfers as $transfer): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($transfer['referring_facility']); ?></td>
                                        <td><?php echo htmlspecialchars($transfer['referred_to_facility']); ?></td>
                                        <td><?php echo number_format($transfer['total_referrals']); ?></td>
                                        <td><?php echo number_format($transfer['accepted']); ?></td>
                                        <td><?php echo number_format($transfer['cancelled']); ?></td>
                                        <td><?php echo number_format($transfer['issued']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Referral Reasons Breakdown -->
                <div class="referral-content">
                    <h3><i class="fas fa-list-ul"></i> Top Referral Reasons</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Reason Category</th>
                                <th>Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($referral_reasons)): ?>
                                <tr>
                                    <td colspan="2" class="text-center">No referral reason data found for the selected period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($referral_reasons as $reason): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($reason['reason_category']); ?></td>
                                        <td><?php echo number_format($reason['count']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Referral Logs Activity -->
                <div class="referral-content">
                    <h3><i class="fas fa-history"></i> Recent Referral Activity</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Referral #</th>
                                <th>Employee</th>
                                <th>Action</th>
                                <th>Previous Status</th>
                                <th>New Status</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($referral_logs)): ?>
                                <tr>
                                    <td colspan="6" class="text-center">No activity logs found for the selected period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($referral_logs as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['referral_num']); ?></td>
                                        <td><?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($log['action']); ?></td>
                                        <td><span class="status-badge status-<?php echo htmlspecialchars($log['previous_status']); ?>"><?php echo htmlspecialchars($log['previous_status'] ?? 'N/A'); ?></span></td>
                                        <td><span class="status-badge status-<?php echo htmlspecialchars($log['new_status']); ?>"><?php echo htmlspecialchars($log['new_status'] ?? 'N/A'); ?></span></td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($log['timestamp'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Detailed Referral Table -->
                <div class="referral-content">
                    <h3><i class="fas fa-table"></i> Detailed Referral Records (Latest 50)</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Referral #</th>
                                <th>Patient ID</th>
                                <th>Referring Facility</th>
                                <th>Referred To</th>
                                <th>Destination Type</th>
                                <th>Reason</th>
                                <th>Referral Date</th>
                                <th>Status</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($detailed_referrals)): ?>
                                <tr>
                                    <td colspan="9" class="text-center">No detailed referral data found for the selected period.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($detailed_referrals as $referral): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($referral['referral_num']); ?></td>
                                        <td><?php echo htmlspecialchars($referral['patient_id']); ?></td>
                                        <td><?php echo htmlspecialchars($referral['referring_facility']); ?></td>
                                        <td><?php echo htmlspecialchars($referral['referred_to_facility']); ?></td>
                                        <td><?php echo ucwords(str_replace('_', ' ', $referral['destination_type'])); ?></td>
                                        <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($referral['referral_reason'] ?? ''); ?>">
                                            <?php
                                            $reason = $referral['referral_reason'] ?? '';
                                            echo htmlspecialchars(substr($reason, 0, 50)) . (strlen($reason) > 50 ? '...' : '');
                                            ?>
                                        </td>
                                        <td><?php echo date('M j, Y', strtotime($referral['referral_date'])); ?></td>
                                        <td><span class="status-badge status-<?php echo htmlspecialchars($referral['status']); ?>"><?php echo htmlspecialchars($referral['status']); ?></span></td>
                                        <td><?php echo date('M j, Y g:i A', strtotime($referral['updated_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <script>
        // Ensure all functions are defined in the global scope
        window.toggleFilterInputs = function() {
            const filterType = document.getElementById('filter_type').value;

            // Hide all input groups
            document.getElementById('date_from_group').style.display = 'none';
            document.getElementById('date_to_group').style.display = 'none';
            document.getElementById('month_from_group').style.display = 'none';
            document.getElementById('month_to_group').style.display = 'none';
            document.getElementById('year_from_group').style.display = 'none';
            document.getElementById('year_to_group').style.display = 'none';

            // Show relevant input groups
            switch (filterType) {
                case 'date_range':
                    document.getElementById('date_from_group').style.display = 'flex';
                    document.getElementById('date_to_group').style.display = 'flex';
                    break;
                case 'month_range':
                    document.getElementById('month_from_group').style.display = 'flex';
                    document.getElementById('month_to_group').style.display = 'flex';
                    break;
                case 'year_range':
                    document.getElementById('year_from_group').style.display = 'flex';
                    document.getElementById('year_to_group').style.display = 'flex';
                    break;
            }
        };

        // Alias for backward compatibility
        function toggleFilterInputs() {
            window.toggleFilterInputs();
        }

        // Initialize filter inputs on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleFilterInputs();
            initializeCharts();
        });

        // Chart initialization with error handling
        function initializeCharts() {
            // Check if Chart.js is loaded
            if (typeof Chart === 'undefined') {
                console.warn('Chart.js is not loaded. Charts will not be displayed.');
                return;
            }

            try {
                // Destination Type Distribution Chart
                const destinationData = <?php echo json_encode($destination_types ?: []); ?>;
                if (destinationData && destinationData.length > 0) {
                    const destinationCtx = document.getElementById('destinationChart');
                    if (destinationCtx) {
                        new Chart(destinationCtx.getContext('2d'), {
                            type: 'pie',
                            data: {
                                labels: destinationData.map(item => item.destination_type.replace('_', ' ').toUpperCase()),
                                datasets: [{
                                    data: destinationData.map(item => item.count),
                                    backgroundColor: [
                                        '#0077b6',
                                        '#00b4d8',
                                        '#90e0ef',
                                        '#06d6a0',
                                        '#ffd60a'
                                    ],
                                    borderWidth: 2,
                                    borderColor: '#fff'
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        position: 'bottom'
                                    },
                                    tooltip: {
                                        callbacks: {
                                            label: function(context) {
                                                const dataIndex = context.dataIndex;
                                                const percentage = destinationData[dataIndex] && destinationData[dataIndex].percentage ? destinationData[dataIndex].percentage : 0;
                                                return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }
                }

                // Timeline Chart
                const timelineData = <?php echo json_encode($timeline_data ?: []); ?>;
                if (timelineData && timelineData.length > 0) {
                    const timelineCtx = document.getElementById('timelineChart');
                    if (timelineCtx) {
                        new Chart(timelineCtx.getContext('2d'), {
                            type: 'line',
                            data: {
                                labels: timelineData.map(item => new Date(item.referral_day).toLocaleDateString()),
                                datasets: [{
                                    label: 'Total Referrals',
                                    data: timelineData.map(item => item.daily_count),
                                    borderColor: '#0077b6',
                                    backgroundColor: 'rgba(0, 119, 182, 0.1)',
                                    tension: 0.4,
                                    fill: true
                                }, {
                                    label: 'Cancelled',
                                    data: timelineData.map(item => item.cancelled_count),
                                    borderColor: '#f72585',
                                    backgroundColor: 'rgba(247, 37, 133, 0.1)',
                                    tension: 0.4,
                                    fill: false
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        position: 'bottom'
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        ticks: {
                                            stepSize: 1
                                        }
                                    }
                                }
                            }
                        });
                    }
                }
            } catch (error) {
                console.error('Error initializing charts:', error);
            }
        }

        // Export to Excel function with error handling
        function exportToExcel() {
            try {
                // Check if XLSX is loaded
                if (typeof XLSX === 'undefined') {
                    alert('Excel export library is not loaded. Please refresh the page and try again.');
                    return;
                }

                const wb = XLSX.utils.book_new();

                // Create sheets for different data
                const facilityData = <?php echo json_encode($facility_transfers ?: []); ?>;
                const detailedData = <?php echo json_encode($detailed_referrals ?: []); ?>;
                const reasonsData = <?php echo json_encode($referral_reasons ?: []); ?>;

                if (facilityData && facilityData.length > 0) {
                    const facilityWs = XLSX.utils.json_to_sheet(facilityData);
                    XLSX.utils.book_append_sheet(wb, facilityWs, 'Facility Transfers');
                }

                if (detailedData && detailedData.length > 0) {
                    const detailedWs = XLSX.utils.json_to_sheet(detailedData);
                    XLSX.utils.book_append_sheet(wb, detailedWs, 'Detailed Referrals');
                }

                if (reasonsData && reasonsData.length > 0) {
                    const reasonsWs = XLSX.utils.json_to_sheet(reasonsData);
                    XLSX.utils.book_append_sheet(wb, reasonsWs, 'Referral Reasons');
                }

                // Add metrics sheet
                const metricsData = [{
                        Metric: 'Total Referrals',
                        Value: <?php echo isset($metrics['total_referrals']) ? intval($metrics['total_referrals']) : 0; ?>
                    },
                    {
                        Metric: 'Accepted Referrals',
                        Value: <?php echo isset($metrics['accepted_referrals']) ? intval($metrics['accepted_referrals']) : 0; ?>
                    },
                    {
                        Metric: 'Cancelled Referrals',
                        Value: <?php echo isset($metrics['cancelled_referrals']) ? intval($metrics['cancelled_referrals']) : 0; ?>
                    },
                    {
                        Metric: 'External Issued',
                        Value: <?php echo isset($metrics['external_issued']) ? intval($metrics['external_issued']) : 0; ?>
                    },
                    {
                        Metric: 'Active Referrals',
                        Value: <?php echo isset($metrics['active_referrals']) ? intval($metrics['active_referrals']) : 0; ?>
                    },
                    {
                        Metric: 'Completion Rate (%)',
                        Value: <?php echo isset($completion_rate) ? floatval($completion_rate) : 0; ?>
                    }
                ];
                const metricsWs = XLSX.utils.json_to_sheet(metricsData);
                XLSX.utils.book_append_sheet(wb, metricsWs, 'Key Metrics');

                // Save the Excel file
                XLSX.writeFile(wb, 'referral-summary-report.xlsx');
            } catch (error) {
                console.error('Error exporting to Excel:', error);
                alert('Error exporting to Excel. Please try again.');
            }
        }

        // Auto-dismiss alerts
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