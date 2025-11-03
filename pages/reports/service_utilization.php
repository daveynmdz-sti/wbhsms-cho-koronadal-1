<?php
// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in and update activity
if (!is_employee_logged_in()) {
    // Clear any output buffer before redirect
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: ../management/auth/employee_login.php?reason=session_expired");
    exit();
}

// Update session activity to prevent timeout
update_employee_activity();

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

// Date range filters with proper validation
$start_date = isset($_GET['start_date']) && !empty($_GET['start_date'])
    ? $_GET['start_date']
    : date('Y-m-01'); // Default to current month start

$end_date = isset($_GET['end_date']) && !empty($_GET['end_date'])
    ? $_GET['end_date']
    : date('Y-m-t'); // Default to current month end

$facility_filter = isset($_GET['facility_id']) && $_GET['facility_id'] !== ''
    ? (int)$_GET['facility_id']
    : '';

$service_filter = isset($_GET['service_id']) && $_GET['service_id'] !== ''
    ? (int)$_GET['service_id']
    : '';

// Validate date range
if (strtotime($start_date) === false) {
    $start_date = date('Y-m-01');
}
if (strtotime($end_date) === false) {
    $end_date = date('Y-m-t');
}

// Ensure start date is not after end date
if (strtotime($start_date) > strtotime($end_date)) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

try {
    // Verify user is still logged in before processing
    if (!is_employee_logged_in()) {
        header("Location: ../management/auth/employee_login.php?reason=session_lost");
        exit();
    }

    // Handle export requests
    if (isset($_GET['export'])) {
        $export_type = $_GET['export'];

        if ($export_type === 'csv') {
            // Set CSV headers
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="service_utilization_report_' . date('Y-m-d') . '.csv"');

            $output = fopen('php://output', 'w');

            // Add summary data
            fputcsv($output, ['Service Utilization Report - ' . date('M j, Y', strtotime($start_date)) . ' to ' . date('M j, Y', strtotime($end_date))]);
            fputcsv($output, []);
            fputcsv($output, ['Service Demand Summary']);
            fputcsv($output, ['Service Name', 'Total Referrals', 'Total Consultations', 'Total Demand', 'Demand Share %']);
            
            // We'll add the actual data export here after we build the queries
            
            fclose($output);
            exit();
        }

        if ($export_type === 'pdf') {
            // Redirect to PDF generation script with current filters
            $pdf_params = array_merge($_GET, ['export' => null]);
            unset($pdf_params['export']); // Remove export parameter
            $pdf_url = 'export_service_utilization_pdf.php?' . http_build_query($pdf_params);
            header("Location: $pdf_url");
            exit();
        }
    }

    // Service Demand Analysis - Referrals and Consultations Combined
    $stmt = $pdo->prepare("
        SELECT 
            s.service_id,
            s.name as service_name,
            s.description as service_description,
            COALESCE(referral_count, 0) as referral_count,
            COALESCE(consultation_count, 0) as consultation_count,
            (COALESCE(referral_count, 0) + COALESCE(consultation_count, 0)) as total_demand,
            CASE 
                WHEN (SELECT SUM(COALESCE(ref_cnt, 0) + COALESCE(cons_cnt, 0)) 
                      FROM (
                          SELECT 
                              s2.service_id,
                              COUNT(DISTINCT r2.referral_id) as ref_cnt,
                              COUNT(DISTINCT c2.consultation_id) as cons_cnt
                          FROM services s2
                          LEFT JOIN referrals r2 ON s2.service_id = r2.service_id 
                              AND r2.referral_date BETWEEN ? AND ?
                              " . ($facility_filter ? " AND r2.facility_id = ?" : "") . "
                          LEFT JOIN consultations c2 ON s2.service_id = c2.service_id 
                              AND c2.consultation_date BETWEEN ? AND ?
                              " . ($facility_filter ? " AND c2.facility_id = ?" : "") . "
                          WHERE s2.name != 'General Service'
                          GROUP BY s2.service_id
                      ) total_calc
                     ) = 0 THEN 0
                ELSE ((COALESCE(referral_count, 0) + COALESCE(consultation_count, 0)) / 
                      (SELECT SUM(COALESCE(ref_cnt, 0) + COALESCE(cons_cnt, 0)) 
                       FROM (
                           SELECT 
                               s2.service_id,
                               COUNT(DISTINCT r2.referral_id) as ref_cnt,
                               COUNT(DISTINCT c2.consultation_id) as cons_cnt
                           FROM services s2
                           LEFT JOIN referrals r2 ON s2.service_id = r2.service_id 
                               AND r2.referral_date BETWEEN ? AND ?
                               " . ($facility_filter ? " AND r2.facility_id = ?" : "") . "
                           LEFT JOIN consultations c2 ON s2.service_id = c2.service_id 
                               AND c2.consultation_date BETWEEN ? AND ?
                               " . ($facility_filter ? " AND c2.facility_id = ?" : "") . "
                           WHERE s2.name != 'General Service'
                           GROUP BY s2.service_id
                       ) total_calc
                      )) * 100
            END as demand_percentage
        FROM services s
        LEFT JOIN (
            SELECT 
                ref_data.service_id, 
                COUNT(*) as referral_count 
            FROM referrals ref_data
            JOIN services s_ref ON ref_data.service_id = s_ref.service_id
            WHERE ref_data.referral_date BETWEEN ? AND ?
            AND s_ref.name != 'General Service'
            " . ($facility_filter ? " AND ref_data.facility_id = ?" : "") . "
            GROUP BY ref_data.service_id
        ) ref_data ON s.service_id = ref_data.service_id
        LEFT JOIN (
            SELECT 
                cons_data.service_id, 
                COUNT(*) as consultation_count 
            FROM consultations cons_data
            JOIN services s_cons ON cons_data.service_id = s_cons.service_id
            WHERE cons_data.consultation_date BETWEEN ? AND ?
            AND s_cons.name != 'General Service'
            " . ($facility_filter ? " AND cons_data.facility_id = ?" : "") . "
            GROUP BY cons_data.service_id
        ) cons_data ON s.service_id = cons_data.service_id
        WHERE s.name != 'General Service'
        " . ($service_filter ? " AND s.service_id = ?" : "") . "
        ORDER BY total_demand DESC, s.name ASC
    ");

    // Build parameters array for the complex query
    $params = [];
    // Parameters for percentage calculation subqueries (2 sets)
    $params[] = $start_date; $params[] = $end_date;
    if ($facility_filter) { $params[] = $facility_filter; }
    $params[] = $start_date; $params[] = $end_date;
    if ($facility_filter) { $params[] = $facility_filter; }
    
    // Parameters for second percentage calculation subqueries (2 sets)
    $params[] = $start_date; $params[] = $end_date;
    if ($facility_filter) { $params[] = $facility_filter; }
    $params[] = $start_date; $params[] = $end_date;
    if ($facility_filter) { $params[] = $facility_filter; }
    
    // Parameters for main LEFT JOIN subqueries
    $params[] = $start_date; $params[] = $end_date;
    if ($facility_filter) { $params[] = $facility_filter; }
    $params[] = $start_date; $params[] = $end_date;
    if ($facility_filter) { $params[] = $facility_filter; }
    
    if ($service_filter) { $params[] = $service_filter; }

    $stmt->execute($params);
    $service_demand = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top Referral Services
    $stmt = $pdo->prepare("
        SELECT 
            s.name as service_name,
            COUNT(r.referral_id) as referral_count,
            COUNT(DISTINCT r.patient_id) as unique_patients,
            100.0 as completion_rate
        FROM services s
        LEFT JOIN referrals r ON s.service_id = r.service_id 
            AND r.referral_date BETWEEN ? AND ?
            " . ($facility_filter ? " AND r.facility_id = ?" : "") . "
        WHERE s.name != 'General Service'
        " . ($service_filter ? " AND s.service_id = ?" : "") . "
        GROUP BY s.service_id, s.name
        ORDER BY referral_count DESC
        LIMIT 15
    ");

    $ref_params = [$start_date, $end_date];
    if ($facility_filter) $ref_params[] = $facility_filter;
    if ($service_filter) $ref_params[] = $service_filter;

    $stmt->execute($ref_params);
    $top_referral_services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top Consultation Services
    $stmt = $pdo->prepare("
        SELECT 
            s.name as service_name,
            COUNT(c.consultation_id) as consultation_count,
            COUNT(DISTINCT c.patient_id) as unique_patients,
            100.0 as completion_rate
        FROM services s
        LEFT JOIN consultations c ON s.service_id = c.service_id 
            AND c.consultation_date BETWEEN ? AND ?
            " . ($facility_filter ? " AND c.facility_id = ?" : "") . "
        WHERE s.name != 'General Service'
        " . ($service_filter ? " AND s.service_id = ?" : "") . "
        GROUP BY s.service_id, s.name
        ORDER BY consultation_count DESC
        LIMIT 15
    ");

    $stmt->execute($ref_params); // Same parameters as referrals
    $top_consultation_services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Service Trends - Monthly breakdown
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(activity_date, '%Y-%m') as month_year,
            SUM(referral_count) as monthly_referrals,
            SUM(consultation_count) as monthly_consultations,
            SUM(referral_count + consultation_count) as monthly_total
        FROM (
            SELECT 
                trend_ref.referral_date as activity_date,
                COUNT(*) as referral_count,
                0 as consultation_count
            FROM referrals trend_ref
            JOIN services s_ref ON trend_ref.service_id = s_ref.service_id
            WHERE trend_ref.referral_date BETWEEN ? AND ?
            AND s_ref.name != 'General Service'
            " . ($facility_filter ? " AND trend_ref.facility_id = ?" : "") . "
            " . ($service_filter ? " AND trend_ref.service_id = ?" : "") . "
            GROUP BY trend_ref.referral_date
            
            UNION ALL
            
            SELECT 
                trend_cons.consultation_date as activity_date,
                0 as referral_count,
                COUNT(*) as consultation_count
            FROM consultations trend_cons
            JOIN services s ON trend_cons.service_id = s.service_id
            WHERE trend_cons.consultation_date BETWEEN ? AND ?
            AND s.name != 'General Service'
            " . ($facility_filter ? " AND trend_cons.facility_id = ?" : "") . "
            " . ($service_filter ? " AND trend_cons.service_id = ?" : "") . "
            GROUP BY trend_cons.consultation_date
        ) combined_data
        GROUP BY DATE_FORMAT(activity_date, '%Y-%m')
        ORDER BY month_year DESC
        LIMIT 12
    ");

    $trend_params = [];
    $trend_params[] = $start_date; $trend_params[] = $end_date;
    if ($facility_filter) $trend_params[] = $facility_filter;
    if ($service_filter) $trend_params[] = $service_filter;
    $trend_params[] = $start_date; $trend_params[] = $end_date;
    if ($facility_filter) $trend_params[] = $facility_filter;
    if ($service_filter) $trend_params[] = $service_filter;

    $stmt->execute($trend_params);
    $service_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Service Summary Statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT s.service_id) as total_services,
            SUM(CASE WHEN ref_data.referral_count > 0 OR cons_data.consultation_count > 0 THEN 1 ELSE 0 END) as active_services,
            COALESCE(SUM(ref_data.referral_count), 0) as total_referrals,
            COALESCE(SUM(cons_data.consultation_count), 0) as total_consultations,
            COALESCE(SUM(ref_data.referral_count), 0) + COALESCE(SUM(cons_data.consultation_count), 0) as total_service_demand
        FROM services s
        LEFT JOIN (
            SELECT sum_ref.service_id, COUNT(*) as referral_count 
            FROM referrals sum_ref
            JOIN services s_ref ON sum_ref.service_id = s_ref.service_id
            WHERE sum_ref.referral_date BETWEEN ? AND ?
            AND s_ref.name != 'General Service'
            " . ($facility_filter ? " AND sum_ref.facility_id = ?" : "") . "
            GROUP BY sum_ref.service_id
        ) ref_data ON s.service_id = ref_data.service_id
        LEFT JOIN (
            SELECT sum_cons.service_id, COUNT(*) as consultation_count 
            FROM consultations sum_cons
            JOIN services s_cons ON sum_cons.service_id = s_cons.service_id
            WHERE sum_cons.consultation_date BETWEEN ? AND ?
            AND s_cons.name != 'General Service'
            " . ($facility_filter ? " AND sum_cons.facility_id = ?" : "") . "
            GROUP BY sum_cons.service_id
        ) cons_data ON s.service_id = cons_data.service_id
        WHERE s.name != 'General Service'
        " . ($service_filter ? " AND s.service_id = ?" : "") . "
    ");

    $summary_params = [$start_date, $end_date];
    if ($facility_filter) $summary_params[] = $facility_filter;
    $summary_params[] = $start_date; $summary_params[] = $end_date;
    if ($facility_filter) $summary_params[] = $facility_filter;
    if ($service_filter) $summary_params[] = $service_filter;

    $stmt->execute($summary_params);
    $service_summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get facilities for filter dropdown
    $stmt = $pdo->prepare("SELECT facility_id, name FROM facilities WHERE status = 'active' ORDER BY name");
    $stmt->execute();
    $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get services for filter dropdown
    $stmt = $pdo->prepare("SELECT service_id, name FROM services ORDER BY name");
    $stmt->execute();
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    error_log("Service Utilization Report Error: " . $e->getMessage());

    // Initialize empty arrays to prevent errors in the display
    $service_demand = [];
    $top_referral_services = [];
    $top_consultation_services = [];
    $service_trends = [];
    $service_summary = ['total_services' => 0, 'active_services' => 0, 'total_referrals' => 0, 'total_consultations' => 0, 'total_service_demand' => 0];
    $facilities = [];
    $services = [];
} catch (Exception $e) {
    $error = "An unexpected error occurred: " . $e->getMessage();
    error_log("Service Utilization Report Unexpected Error: " . $e->getMessage());

    // Initialize empty arrays to prevent errors in the display
    $service_demand = [];
    $top_referral_services = [];
    $top_consultation_services = [];
    $service_trends = [];
    $service_summary = ['total_services' => 0, 'active_services' => 0, 'total_referrals' => 0, 'total_consultations' => 0, 'total_service_demand' => 0];
    $facilities = [];
    $services = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Utilization Report - CHO Koronadal</title>

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

        /* Filter Controls */
        .filter-controls {
            background: var(--background-light);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-group label {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 14px;
        }

        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            background: white;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(0, 119, 182, 0.1);
        }

        .btn-filter {
            background: var(--primary-color);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.3s;
        }

        .btn-filter:hover {
            background: var(--primary-dark);
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--background-light);
            padding: 20px;
            border-radius: 12px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
            text-align: center;
        }

        .stat-card h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: var(--text-light);
            font-weight: 500;
        }

        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin: 5px 0;
        }

        .stat-card .stat-change {
            font-size: 12px;
            font-weight: 500;
            color: var(--text-light);
        }

        /* Charts and Tables */
        .chart-container {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-dark);
            margin: 0;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--text-dark);
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        .progress-bar {
            background: #e9ecef;
            border-radius: 4px;
            height: 8px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            background: var(--primary-color);
            height: 100%;
            transition: width 0.3s ease;
        }

        /* Export buttons */
        .export-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .btn-export {
            background: var(--secondary-color);
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-export:hover {
            background: var(--primary-color);
        }

        /* Badge styles */
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .badge-high {
            background: #d1f2eb;
            color: #0c7c59;
        }

        .badge-medium {
            background: #fff3cd;
            color: #664d03;
        }

        .badge-low {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-zero {
            background: #e2e3e5;
            color: #6c757d;
        }

        /* Charts placeholder */
        .chart-placeholder {
            height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
            border-radius: 8px;
            color: var(--text-light);
            font-style: italic;
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 24px;
            }

            .report-overview {
                padding: 20px;
            }

            .service-content {
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
                <span> Service Utilization</span>
            </div>

            <div class="page-header">
                <h1><i class="fas fa-stethoscope"></i> Service Utilization Report</h1>
                <p>Comprehensive analysis of healthcare service usage patterns, demand trends, and resource allocation</p>
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
                <p>This comprehensive Service Utilization Report analyzes healthcare service demand patterns by examining referrals and consultations data. It identifies the most sought-after services, tracks utilization trends over time, and provides insights into service popularity, capacity planning, and resource allocation. Use this data to optimize service delivery, identify high-demand areas, and make informed decisions about healthcare service expansion or restructuring.</p>
            </div>

            <!-- Filter Controls -->
            <div class="filter-controls">
                <form method="GET" action="" id="filter-form">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label for="start_date">Start Date</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" required>
                        </div>
                        <div class="filter-group">
                            <label for="end_date">End Date</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" required>
                        </div>
                        <div class="filter-group">
                            <label for="facility_id">Facility</label>
                            <select id="facility_id" name="facility_id">
                                <option value="">All Facilities</option>
                                <?php if (!empty($facilities)): ?>
                                    <?php foreach ($facilities as $facility): ?>
                                        <option value="<?php echo $facility['facility_id']; ?>"
                                            <?php echo ($facility_filter == $facility['facility_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($facility['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="service_id">Service</label>
                            <select id="service_id" name="service_id">
                                <option value="">All Services</option>
                                <?php if (!empty($services)): ?>
                                    <?php foreach ($services as $service): ?>
                                        <option value="<?php echo $service['service_id']; ?>"
                                            <?php echo ($service_filter == $service['service_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($service['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <button type="submit" class="btn-filter" id="apply-filters-btn">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                        </div>
                    </div>

                    <!-- Loading indicator -->
                    <div id="loading-indicator" style="display: none; text-align: center; margin-top: 15px; color: var(--primary-color);">
                        <i class="fas fa-spinner fa-spin"></i> Loading service utilization data...
                    </div>
                </form>
            </div>

            <!-- Service Summary Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h4>Total Services</h4>
                    <div class="stat-value"><?php echo number_format($service_summary['total_services'] ?? 0); ?></div>
                    <div class="stat-change">Available healthcare services</div>
                </div>
                <!--<div class="stat-card">
                    <h4>Active Services</h4>
                    <div class="stat-value"><?php echo number_format($service_summary['active_services'] ?? 0); ?></div>
                    <div class="stat-change">Services with activity</div>
                </div>-->
                <div class="stat-card">
                    <h4>Total Referrals</h4>
                    <div class="stat-value"><?php echo number_format($service_summary['total_referrals'] ?? 0); ?></div>
                    <div class="stat-change">Referral requests</div>
                </div>
                <div class="stat-card">
                    <h4>Total Consultations</h4>
                    <div class="stat-value"><?php echo number_format($service_summary['total_consultations'] ?? 0); ?></div>
                    <div class="stat-change">Consultation sessions</div>
                </div>
                <div class="stat-card">
                    <h4>Combined Demand</h4>
                    <div class="stat-value"><?php echo number_format($service_summary['total_service_demand'] ?? 0); ?></div>
                    <div class="stat-change">Total service requests</div>
                </div>
            </div>

            <!-- Export Controls -->
            <div class="export-controls">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>" class="btn-export">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn-export">
                    <i class="fas fa-file-csv"></i> Export CSV
                </a>
            </div>

            <!-- Combined Service Demand Analysis -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title"><i class="fas fa-chart-bar"></i> Service Demand Analysis (Combined Referrals + Consultations)</h3>
                </div>
                <p style="color: var(--text-light); margin-bottom: 15px; font-size: 14px;">
                    <i class="fas fa-info-circle" style="color: var(--primary-color); margin-right: 5px;"></i>
                    This comprehensive analysis shows total service demand by combining referral and consultation requests. Services with high demand may need additional resources or capacity expansion.
                </p>
                <?php if (!empty($service_demand)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Service Name</th>
                                <th>Referrals</th>
                                <th>Consultations</th>
                                <th>Total Demand</th>
                                <th>Demand Share</th>
                                <th>Demand Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($service_demand as $service): ?>
                                <?php
                                $demand_level = '';
                                $demand_class = '';
                                if ($service['total_demand'] == 0) {
                                    $demand_level = 'No Activity';
                                    $demand_class = 'badge-zero';
                                } elseif ($service['demand_percentage'] >= 10) {
                                    $demand_level = 'High Demand';
                                    $demand_class = 'badge-high';
                                } elseif ($service['demand_percentage'] >= 5) {
                                    $demand_level = 'Medium Demand';
                                    $demand_class = 'badge-medium';
                                } else {
                                    $demand_level = 'Low Demand';
                                    $demand_class = 'badge-low';
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($service['service_name']); ?></strong>
                                        <?php if (!empty($service['service_description'])): ?>
                                            <br><small style="color: #666;"><?php echo htmlspecialchars($service['service_description']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo number_format($service['referral_count']); ?></td>
                                    <td><?php echo number_format($service['consultation_count']); ?></td>
                                    <td><strong><?php echo number_format($service['total_demand']); ?></strong></td>
                                    <td>
                                        <?php echo round($service['demand_percentage'], 2); ?>%
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo min($service['demand_percentage'] * 2, 100); ?>%;"></div>
                                        </div>
                                    </td>
                                    <td><span class="badge <?php echo $demand_class; ?>"><?php echo $demand_level; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="chart-placeholder">
                        <div>
                            <i class="fas fa-chart-bar"></i>
                            <p>No service demand data available for the selected period.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Top Referral Services -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title"><i class="fas fa-share-square"></i> Most Requested Referral Services</h3>
                </div>
                <p style="color: var(--text-light); margin-bottom: 15px; font-size: 14px;">
                    <i class="fas fa-info-circle" style="color: var(--primary-color); margin-right: 5px;"></i>
                    Services most frequently requested through the referral system. High referral volumes may indicate specialized service needs or capacity limitations.
                </p>
                <?php if (!empty($top_referral_services)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Service Name</th>
                                <th>Total Referrals</th>
                                <th>Unique Patients</th>
                                <th>Completion Rate</th>
                                <th>Utilization</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_referral_services as $service): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($service['service_name']); ?></td>
                                    <td><?php echo number_format($service['referral_count']); ?></td>
                                    <td><?php echo number_format($service['unique_patients']); ?></td>
                                    <td><?php echo round($service['completion_rate'], 1); ?>%</td>
                                    <td>
                                        <?php
                                        $max_referrals = max(array_column($top_referral_services, 'referral_count'));
                                        $utilization_percent = $max_referrals > 0 ? ($service['referral_count'] / $max_referrals) * 100 : 0;
                                        ?>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $utilization_percent; ?>%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="chart-placeholder">
                        <div>
                            <i class="fas fa-share-square"></i>
                            <p>No referral data available for the selected period.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Top Consultation Services -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title"><i class="fas fa-user-md"></i> Most Requested Consultation Services</h3>
                </div>
                <p style="color: var(--text-light); margin-bottom: 15px; font-size: 14px;">
                    <i class="fas fa-info-circle" style="color: var(--primary-color); margin-right: 5px;"></i>
                    Services most frequently used for direct consultations. High consultation volumes indicate popular services and regular patient demand patterns.
                </p>
                <?php if (!empty($top_consultation_services)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Service Name</th>
                                <th>Total Consultations</th>
                                <th>Unique Patients</th>
                                <th>Completion Rate</th>
                                <th>Utilization</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_consultation_services as $service): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($service['service_name']); ?></td>
                                    <td><?php echo number_format($service['consultation_count']); ?></td>
                                    <td><?php echo number_format($service['unique_patients']); ?></td>
                                    <td><?php echo round($service['completion_rate'], 1); ?>%</td>
                                    <td>
                                        <?php
                                        $max_consultations = max(array_column($top_consultation_services, 'consultation_count'));
                                        $utilization_percent = $max_consultations > 0 ? ($service['consultation_count'] / $max_consultations) * 100 : 0;
                                        ?>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $utilization_percent; ?>%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="chart-placeholder">
                        <div>
                            <i class="fas fa-user-md"></i>
                            <p>No consultation data available for the selected period.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Service Utilization Trends -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title"><i class="fas fa-chart-line"></i> Service Utilization Trends</h3>
                </div>
                <p style="color: var(--text-light); margin-bottom: 15px; font-size: 14px;">
                    <i class="fas fa-info-circle" style="color: var(--primary-color); margin-right: 5px;"></i>
                    Monthly breakdown of service utilization showing trends in referrals and consultations over time. Use this data to identify seasonal patterns and plan resource allocation.
                </p>
                <?php if (!empty($service_trends)): ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Referrals</th>
                                <th>Consultations</th>
                                <th>Total Activities</th>
                                <th>Monthly Trend</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $max_monthly_total = max(array_column($service_trends, 'monthly_total'));
                            foreach ($service_trends as $trend):
                            ?>
                                <tr>
                                    <td><?php echo date('M Y', strtotime($trend['month_year'] . '-01')); ?></td>
                                    <td><?php echo number_format($trend['monthly_referrals']); ?></td>
                                    <td><?php echo number_format($trend['monthly_consultations']); ?></td>
                                    <td><strong><?php echo number_format($trend['monthly_total']); ?></strong></td>
                                    <td>
                                        <?php
                                        $trend_percent = $max_monthly_total > 0 ? ($trend['monthly_total'] / $max_monthly_total) * 100 : 0;
                                        ?>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $trend_percent; ?>%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="chart-placeholder">
                        <div>
                            <i class="fas fa-chart-line"></i>
                            <p>No trend data available for the selected period.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <script>
        // Service Utilization Report JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Service Utilization Report loaded');

            // Initialize interactive elements
            initializeInteractiveElements();

            // Setup export button interactions
            setupExportButtons();

            // Add loading states for better UX
            setupLoadingStates();

            // Animate progress bars
            animateProgressBars();
        });

        function initializeInteractiveElements() {
            // Add hover effects to stat cards
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                    this.style.boxShadow = 'var(--shadow-heavy)';
                });

                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = 'var(--shadow-light)';
                });
            });

            // Add sorting to tables
            setupTableSorting();
        }

        function setupExportButtons() {
            const exportButtons = document.querySelectorAll('.btn-export');
            exportButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
                    this.disabled = true;

                    // Re-enable after 3 seconds (assuming export completes)
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.disabled = false;
                    }, 3000);
                });
            });
        }

        function setupLoadingStates() {
            const form = document.querySelector('#filter-form');
            const loadingIndicator = document.querySelector('#loading-indicator');
            const submitButton = document.querySelector('#apply-filters-btn');

            if (form && submitButton) {
                form.addEventListener('submit', function(e) {
                    // Validate date range before submitting
                    const startDate = document.querySelector('#start_date').value;
                    const endDate = document.querySelector('#end_date').value;

                    if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
                        e.preventDefault();
                        alert('Start date cannot be after end date. Please check your date selection.');
                        return false;
                    }

                    // Show loading state
                    const originalText = submitButton.innerHTML;
                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                    submitButton.disabled = true;

                    if (loadingIndicator) {
                        loadingIndicator.style.display = 'block';
                    }

                    // Add a timeout to re-enable the button in case of issues
                    setTimeout(() => {
                        submitButton.innerHTML = originalText;
                        submitButton.disabled = false;
                        if (loadingIndicator) {
                            loadingIndicator.style.display = 'none';
                        }
                    }, 10000); // 10 second timeout
                });
            }
        }

        function setupTableSorting() {
            const tables = document.querySelectorAll('.data-table');

            tables.forEach(table => {
                const headers = table.querySelectorAll('th');
                headers.forEach((header, index) => {
                    if (index < headers.length - 1) { // Don't make progress columns sortable
                        header.style.cursor = 'pointer';
                        header.innerHTML += ' <i class="fas fa-sort" style="opacity: 0.5; margin-left: 5px;"></i>';

                        header.addEventListener('click', function() {
                            sortTable(table, index);
                        });
                    }
                });
            });
        }

        function sortTable(table, columnIndex) {
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));

            // Determine sort direction
            const header = table.querySelectorAll('th')[columnIndex];
            const isAscending = !header.classList.contains('sort-desc');

            // Clear previous sort indicators
            table.querySelectorAll('th').forEach(th => {
                th.classList.remove('sort-asc', 'sort-desc');
                const icon = th.querySelector('i');
                if (icon && icon.classList.contains('fa-sort-up', 'fa-sort-down')) {
                    icon.className = 'fas fa-sort';
                }
            });

            // Set current sort indicator
            header.classList.add(isAscending ? 'sort-asc' : 'sort-desc');
            const icon = header.querySelector('i');
            if (icon) icon.className = isAscending ? 'fas fa-sort-up' : 'fas fa-sort-down';

            // Sort rows
            rows.sort((a, b) => {
                const aValue = a.cells[columnIndex].textContent.trim();
                const bValue = b.cells[columnIndex].textContent.trim();

                // Try to parse as numbers first
                const aNum = parseFloat(aValue.replace(/[^0-9.-]/g, ''));
                const bNum = parseFloat(bValue.replace(/[^0-9.-]/g, ''));

                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return isAscending ? aNum - bNum : bNum - aNum;
                } else {
                    return isAscending ?
                        aValue.localeCompare(bValue) :
                        bValue.localeCompare(aValue);
                }
            });

            // Reorder rows in the table
            rows.forEach(row => tbody.appendChild(row));
        }

        // Add animation to progress bars
        function animateProgressBars() {
            const progressBars = document.querySelectorAll('.progress-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        }

        // Utility function to format numbers
        function formatNumber(num) {
            return new Intl.NumberFormat().format(num);
        }

        // Utility function to calculate percentage
        function calculatePercentage(part, total) {
            return total > 0 ? Math.round((part / total) * 100 * 10) / 10 : 0;
        }
    </script>
</body>

</html>