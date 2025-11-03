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
            header('Content-Disposition: attachment; filename="patient_visits_report_' . date('Y-m-d') . '.csv"');

            $output = fopen('php://output', 'w');

            // Add summary data
            fputcsv($output, ['Patient Visits Report - ' . date('M j, Y', strtotime($start_date)) . ' to ' . date('M j, Y', strtotime($end_date))]);
            fputcsv($output, []);
            fputcsv($output, ['Summary Statistics']);
            fputcsv($output, ['Total Visits', $visit_summary['total_visits'] ?? 0]);
            fputcsv($output, ['Completed Visits', $visit_summary['completed_visits'] ?? 0]);
            fputcsv($output, ['Ongoing Visits', $visit_summary['ongoing_visits'] ?? 0]);
            fputcsv($output, ['Average Duration (minutes)', round($visit_summary['avg_visit_duration'] ?? 0)]);
            fputcsv($output, []);

            // Add service utilization data
            fputcsv($output, ['Service Utilization']);
            fputcsv($output, ['Service Name', 'Visits', 'Completed', 'Completion Rate %']);
            foreach ($service_utilization as $service) {
                fputcsv($output, [
                    $service['service_name'],
                    $service['visit_count'],
                    $service['completed_count'],
                    round($service['completion_rate'], 1)
                ]);
            }
            fputcsv($output, []);

            // Add geographic distribution
            fputcsv($output, ['Geographic Distribution']);
            fputcsv($output, ['Barangay', 'District', 'Total Visits', 'Unique Patients']);
            foreach ($geographic_distribution as $location) {
                $district_name = '';
                switch ($location['district_id']) {
                    case 1:
                        $district_name = 'Main District';
                        break;
                    case 2:
                        $district_name = 'GPS District';
                        break;
                    case 3:
                        $district_name = 'Concepcion District';
                        break;
                    default:
                        $district_name = 'District ' . $location['district_id'];
                        break;
                }
                fputcsv($output, [
                    $location['barangay_name'],
                    $district_name,
                    $location['visit_count'],
                    $location['unique_patients']
                ]);
            }

            fclose($output);
            exit();
        }

        if ($export_type === 'pdf') {
            // Redirect to PDF generation script with current filters
            $pdf_params = array_merge($_GET, ['export' => null]);
            unset($pdf_params['export']); // Remove export parameter
            $pdf_url = 'export_patient_visits_pdf.php?' . http_build_query($pdf_params);
            header("Location: $pdf_url");
            exit();
        }
    }

    // Total Visits Summary
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_visits,
            COUNT(CASE WHEN visit_status = 'completed' THEN 1 END) as completed_visits,
            COUNT(CASE WHEN visit_status = 'ongoing' THEN 1 END) as ongoing_visits,
            COUNT(CASE WHEN visit_status = 'cancelled' THEN 1 END) as cancelled_visits,
            AVG(CASE 
                WHEN time_in IS NOT NULL AND time_out IS NOT NULL 
                THEN TIMESTAMPDIFF(MINUTE, time_in, time_out) 
            END) as avg_visit_duration
        FROM visits v
        WHERE v.visit_date BETWEEN ? AND ?
        " . ($facility_filter ? " AND v.facility_id = ?" : "") . "
    ");

    $params = [$start_date, $end_date];
    if ($facility_filter) $params[] = $facility_filter;

    $stmt->execute($params);
    $visit_summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // Daily Visit Trends
    $stmt = $pdo->prepare("
        SELECT 
            DATE(v.visit_date) as visit_date,
            COUNT(*) as total_visits,
            COUNT(CASE WHEN v.visit_status = 'completed' THEN 1 END) as completed_visits
        FROM visits v
        WHERE v.visit_date BETWEEN ? AND ?
        " . ($facility_filter ? " AND v.facility_id = ?" : "") . "
        GROUP BY DATE(v.visit_date)
        ORDER BY visit_date DESC
        LIMIT 30
    ");

    $stmt->execute($params);
    $daily_trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Service Utilization - Show all services
    $stmt = $pdo->prepare("
        SELECT 
            s.name as service_name,
            COUNT(v.visit_id) as visit_count,
            COUNT(CASE WHEN v.visit_status = 'completed' THEN 1 END) as completed_count,
            CASE 
                WHEN COUNT(v.visit_id) = 0 THEN 0
                ELSE (COUNT(CASE WHEN v.visit_status = 'completed' THEN 1 END) / COUNT(v.visit_id) * 100)
            END as completion_rate
        FROM services s
        LEFT JOIN appointments a ON s.service_id = a.service_id 
            AND a.scheduled_date BETWEEN ? AND ?
        LEFT JOIN visits v ON a.appointment_id = v.appointment_id
        " . ($facility_filter ? " AND v.facility_id = ?" : "") . "
        GROUP BY s.service_id, s.name
        ORDER BY visit_count DESC, s.name ASC
    ");

    $params = [$start_date, $end_date];
    if ($facility_filter) $params[] = $facility_filter;

    $stmt->execute($params);
    $service_utilization = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Geographic Distribution - Show all 27 barangays
    $stmt = $pdo->prepare("
        SELECT 
            b.barangay_name,
            b.district_id,
            COUNT(v.visit_id) as visit_count,
            COUNT(DISTINCT v.patient_id) as unique_patients
        FROM barangay b
        LEFT JOIN patients p ON b.barangay_id = p.barangay_id
        LEFT JOIN visits v ON p.patient_id = v.patient_id 
            AND v.visit_date BETWEEN ? AND ?
            " . ($facility_filter ? " AND v.facility_id = ?" : "") . "
        GROUP BY b.barangay_id, b.barangay_name, b.district_id
        ORDER BY visit_count DESC, b.barangay_name ASC
    ");

    $stmt->execute($params);
    $geographic_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Appointment Compliance
    $stmt = $pdo->prepare("
        SELECT 
            a.status as appointment_status,
            COUNT(*) as count,
            (COUNT(*) / (SELECT COUNT(*) FROM appointments WHERE scheduled_date BETWEEN ? AND ?) * 100) as percentage
        FROM appointments a
        WHERE a.scheduled_date BETWEEN ? AND ?
        " . ($facility_filter ? " AND a.facility_id = ?" : "") . "
        GROUP BY a.status
    ");

    $compliance_params = [$start_date, $end_date, $start_date, $end_date];
    if ($facility_filter) $compliance_params[] = $facility_filter;

    $stmt->execute($compliance_params);
    $appointment_compliance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Peak Hours Analysis
    $stmt = $pdo->prepare("
        SELECT 
            HOUR(v.time_in) as hour_of_day,
            COUNT(*) as visit_count
        FROM visits v
        WHERE v.visit_date BETWEEN ? AND ?
            AND v.time_in IS NOT NULL
        " . ($facility_filter ? " AND v.facility_id = ?" : "") . "
        GROUP BY HOUR(v.time_in)
        ORDER BY hour_of_day
    ");

    $stmt->execute($params);
    $peak_hours = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Demographic Analysis
    $stmt = $pdo->prepare("
        SELECT 
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) < 18 THEN 'Under 18'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN '18-35'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 36 AND 55 THEN '36-55'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 56 AND 65 THEN '56-65'
                ELSE 'Over 65'
            END as age_group,
            p.sex,
            COUNT(v.visit_id) as visit_count,
            COUNT(DISTINCT v.patient_id) as unique_patients
        FROM visits v
        JOIN patients p ON v.patient_id = p.patient_id
        WHERE v.visit_date BETWEEN ? AND ?
        " . ($facility_filter ? " AND v.facility_id = ?" : "") . "
        GROUP BY age_group, p.sex
        ORDER BY age_group, p.sex
    ");

    $stmt->execute($params);
    $demographic_analysis = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Facility Performance - Show all 30 facilities
    $stmt = $pdo->prepare("
        SELECT 
            f.name as facility_name,
            f.type as facility_type,
            COUNT(v.visit_id) as total_visits,
            COUNT(CASE WHEN v.visit_status = 'completed' THEN 1 END) as completed_visits,
            AVG(CASE 
                WHEN v.time_in IS NOT NULL AND v.time_out IS NOT NULL 
                THEN TIMESTAMPDIFF(MINUTE, v.time_in, v.time_out) 
            END) as avg_duration_minutes
        FROM facilities f
        LEFT JOIN visits v ON f.facility_id = v.facility_id 
            AND v.visit_date BETWEEN ? AND ?
        WHERE f.status = 'active'
        " . ($facility_filter ? " AND f.facility_id = ?" : "") . "
        GROUP BY f.facility_id, f.name, f.type
        ORDER BY total_visits DESC, f.name ASC
    ");

    $stmt->execute($params);
    $facility_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get facilities for filter dropdown
    $stmt = $pdo->prepare("SELECT facility_id, name FROM facilities WHERE status = 'active' ORDER BY name");
    $stmt->execute();
    $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    error_log("Patient Visits Report Error: " . $e->getMessage());

    // Initialize empty arrays to prevent errors in the display
    $visit_summary = ['total_visits' => 0, 'completed_visits' => 0, 'ongoing_visits' => 0, 'cancelled_visits' => 0, 'avg_visit_duration' => 0];
    $daily_trends = [];
    $service_utilization = [];
    $geographic_distribution = [];
    $appointment_compliance = [];
    $peak_hours = [];
    $demographic_analysis = [];
    $facility_performance = [];
    $facilities = [];
} catch (Exception $e) {
    $error = "An unexpected error occurred: " . $e->getMessage();
    error_log("Patient Visits Report Unexpected Error: " . $e->getMessage());

    // Initialize empty arrays to prevent errors in the display
    $visit_summary = ['total_visits' => 0, 'completed_visits' => 0, 'ongoing_visits' => 0, 'cancelled_visits' => 0, 'avg_visit_duration' => 0];
    $daily_trends = [];
    $service_utilization = [];
    $geographic_distribution = [];
    $appointment_compliance = [];
    $peak_hours = [];
    $demographic_analysis = [];
    $facility_performance = [];
    $facilities = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Visits Report - CHO Koronadal</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">

    <!-- CSS Assets -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/sidebar.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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

        /* Patient visits content section */
        .visits-content {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .visits-content h3 {
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .visits-content h3 i {
            color: var(--primary-color);
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
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
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
        }

        .stat-change.positive {
            color: var(--success-color);
        }

        .stat-change.negative {
            color: var(--danger-color);
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
            justify-content: between;
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

        /* Placeholder for visits data */
        .visits-placeholder {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
            font-style: italic;
        }

        .visits-placeholder i {
            font-size: 48px;
            color: var(--accent-color);
            margin-bottom: 15px;
            display: block;
        }

        /* Badge styles */
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .badge-confirmed {
            background: #d1f2eb;
            color: #0c7c59;
        }

        .badge-completed {
            background: #d1e7dd;
            color: #0f5132;
        }

        .badge-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-checked_in {
            background: #d1ecf1;
            color: #055160;
        }

        /* Mobile responsive improvements */
        @media (max-width: 768px) {
            .homepage {
                margin-left: 0;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-row {
                grid-template-columns: 1fr;
            }

            .export-controls {
                flex-direction: column;
            }

            .data-table {
                font-size: 12px;
            }

            .data-table th,
            .data-table td {
                padding: 8px 4px;
            }

            /* Hide some columns on mobile */
            .data-table th:nth-child(n+5),
            .data-table td:nth-child(n+5) {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .stat-card .stat-value {
                font-size: 1.5rem;
            }
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 24px;
            }

            .report-overview {
                padding: 20px;
            }

            .visits-content {
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
                <span> Patient Visits</span>
            </div>

            <div class="page-header">
                <h1><i class="fas fa-calendar-check"></i> Patient Visits Report</h1>
                <p>Comprehensive analysis of patient visit patterns, frequency, and healthcare utilization trends</p>
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
                <p>This comprehensive patient visits analytics dashboard provides essential insights into healthcare delivery patterns and operational performance. Track visit completion rates, service utilization trends, peak hours activity, demographic patterns, and geographic distribution to make data-driven decisions for improving patient care quality, optimizing staff scheduling, and enhancing resource allocation across CHO Koronadal facilities.</p>
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
                                            <?php echo $facility_filter == $facility['facility_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($facility['name']); ?>
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
                        <i class="fas fa-spinner fa-spin"></i> Loading report data...
                    </div>
                </form>
            </div>
            <div class="chart-container">

                <!-- Statistics Overview -->
                <div class="stats-grid">
                    <!-- Key Performance Indicators Section -->
                    <div style="grid-column: 1 / -1; margin-bottom: 15px;">
                        <h3 style="color: var(--primary-dark); margin: 0; font-size: 16px; font-weight: 600;">
                            <i class="fas fa-chart-bar" style="color: var(--primary-color); margin-right: 8px;"></i>
                            Key Performance Indicators
                        </h3>
                        <p style="color: var(--text-light); margin: 5px 0 0 0; font-size: 13px;">
                            Essential metrics providing a quick overview of visit volumes, completion rates, and operational efficiency for the selected time period.
                        </p>
                    </div>
                    <div class="stat-card">
                        <h4>Total Visits</h4>
                        <div class="stat-value"><?php echo number_format($visit_summary['total_visits'] ?? 0); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-calendar"></i> <?php echo date('M j', strtotime($start_date)); ?> - <?php echo date('M j, Y', strtotime($end_date)); ?>
                        </div>
                    </div>
                    <div class="stat-card">
                        <h4>Completed Visits</h4>
                        <div class="stat-value"><?php echo number_format($visit_summary['completed_visits'] ?? 0); ?></div>
                        <div class="stat-change">
                            <?php
                            $completion_rate = $visit_summary['total_visits'] > 0
                                ? round(($visit_summary['completed_visits'] / $visit_summary['total_visits']) * 100, 1)
                                : 0;
                            ?>
                            <span class="<?php echo $completion_rate >= 80 ? 'positive' : 'negative'; ?>">
                                <?php echo $completion_rate; ?>% completion rate
                            </span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <h4>Cancelled Visits</h4>
                        <div class="stat-value"><?php echo number_format($visit_summary['cancelled_visits'] ?? 0); ?></div>
                        <div class="stat-change">
                            <i class="fas fa-times-circle"></i> Did not complete
                        </div>
                    </div>
                    <div class="stat-card">
                        <h4>Avg Visit Duration</h4>
                        <div class="stat-value">
                            <?php
                            $avg_duration = $visit_summary['avg_visit_duration'] ?? 0;
                            echo $avg_duration > 0 ? round($avg_duration) . 'm' : 'N/A';
                            ?>
                        </div>
                        <div class="stat-change">
                            <i class="fas fa-hourglass-half"></i> Average time
                        </div>
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

                <!-- Daily Visit Trends -->
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-chart-line"></i> Daily Visit Trends</h3>
                    </div>
                    <p style="color: var(--text-light); margin-bottom: 15px; font-size: 14px;">
                        <i class="fas fa-info-circle" style="color: var(--primary-color); margin-right: 5px;"></i>
                        Shows daily patient visit patterns over the last 30 days. Use this data to identify busy days, plan staffing levels, and monitor completion rate trends to improve operational efficiency.
                    </p>
                    <?php if (!empty($daily_trends)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Total Visits</th>
                                    <th>Completed</th>
                                    <th>Completion Rate</th>
                                    <th>Progress</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($daily_trends as $trend): ?>
                                    <?php
                                    $daily_completion_rate = $trend['total_visits'] > 0
                                        ? round(($trend['completed_visits'] / $trend['total_visits']) * 100, 1)
                                        : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($trend['visit_date'])); ?></td>
                                        <td><?php echo number_format($trend['total_visits']); ?></td>
                                        <td><?php echo number_format($trend['completed_visits']); ?></td>
                                        <td><?php echo $daily_completion_rate; ?>%</td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $daily_completion_rate; ?>%"></div>
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
                                <p>No visit data available for the selected period.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Service Utilization -->
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-chart-pie"></i> Service Utilization</h3>
                    </div>
                    <p style="color: var(--text-light); margin-bottom: 15px; font-size: 14px;">
                        <i class="fas fa-info-circle" style="color: var(--primary-color); margin-right: 5px;"></i>
                        Displays which healthcare services are most utilized and their completion rates. High-volume services may need additional resources, while low completion rates indicate potential operational issues that need attention.
                    </p>
                    <?php if (!empty($service_utilization)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th>Visits</th>
                                    <th>Completed</th>
                                    <th>Completion Rate</th>
                                    <th>Utilization</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($service_utilization as $service): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($service['service_name']); ?></td>
                                        <td><?php echo number_format($service['visit_count']); ?></td>
                                        <td><?php echo number_format($service['completed_count']); ?></td>
                                        <td><?php echo round($service['completion_rate'], 1); ?>%</td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo min($service['completion_rate'], 100); ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="chart-placeholder">
                            <div>
                                <i class="fas fa-chart-pie"></i>
                                <p>No service utilization data available.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Geographic Distribution -->
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-map-marker-alt"></i> Geographic Distribution</h3>
                    </div>
                    <p style="color: var(--text-light); margin-bottom: 15px; font-size: 14px;">
                        <i class="fas fa-info-circle" style="color: var(--primary-color); margin-right: 5px;"></i>
                        Shows patient visit distribution across barangays and districts. Use this to identify underserved areas, plan mobile health services, and understand your facility's catchment area demographics.
                    </p>
                    <?php if (!empty($geographic_distribution)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Barangay</th>
                                    <th>District</th>
                                    <th>Total Visits</th>
                                    <th>Unique Patients</th>
                                    <th>Avg Visits per Patient</th>
                                    <th>Distribution</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $max_visits = max(array_column($geographic_distribution, 'visit_count'));
                                foreach ($geographic_distribution as $location):
                                    $avg_visits_per_patient = $location['unique_patients'] > 0
                                        ? round($location['visit_count'] / $location['unique_patients'], 1)
                                        : 0;
                                    $distribution_percent = $max_visits > 0
                                        ? round(($location['visit_count'] / $max_visits) * 100, 1)
                                        : 0;
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($location['barangay_name']); ?></td>
                                        <td><?php 
                                            $district_name = '';
                                            switch ($location['district_id']) {
                                                case 1:
                                                    $district_name = 'Main District';
                                                    break;
                                                case 2:
                                                    $district_name = 'GPS District';
                                                    break;
                                                case 3:
                                                    $district_name = 'Concepcion District';
                                                    break;
                                                default:
                                                    $district_name = 'District ' . $location['district_id'];
                                                    break;
                                            }
                                            echo $district_name;
                                        ?></td>
                                        <td><?php echo number_format($location['visit_count']); ?></td>
                                        <td><?php echo number_format($location['unique_patients']); ?></td>
                                        <td><?php echo $avg_visits_per_patient; ?></td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $distribution_percent; ?>%"></div>
                                            </div>
                                            <small><?php echo $distribution_percent; ?>%</small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="chart-placeholder">
                            <div>
                                <i class="fas fa-map-marker-alt"></i>
                                <p>No geographic distribution data available.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Appointment Compliance -->
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-calendar-check"></i> Appointment Compliance</h3>
                    </div>
                    <p style="color: var(--text-light); margin-bottom: 15px; font-size: 14px;">
                        <i class="fas fa-info-circle" style="color: var(--primary-color); margin-right: 5px;"></i>
                        Tracks appointment status distribution including confirmed, completed, and cancelled appointments. High cancellation rates may indicate need for better reminder systems or appointment policies.
                    </p>
                    <?php if (!empty($appointment_compliance)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Count</th>
                                    <th>Percentage</th>
                                    <th>Distribution</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appointment_compliance as $compliance): ?>
                                    <tr>
                                        <td>
                                            <span class="badge badge-<?php echo $compliance['appointment_status']; ?>">
                                                <?php echo ucfirst($compliance['appointment_status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo number_format($compliance['count']); ?></td>
                                        <td><?php echo round($compliance['percentage'], 1); ?>%</td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $compliance['percentage']; ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="chart-placeholder">
                            <div>
                                <i class="fas fa-calendar-check"></i>
                                <p>No appointment compliance data available.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Peak Hours Analysis -->
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-clock"></i> Peak Hours Analysis</h3>
                    </div>
                    <p style="color: var(--text-light); margin-bottom: 15px; font-size: 14px;">
                        <i class="fas fa-info-circle" style="color: var(--primary-color); margin-right: 5px;"></i>
                        Identifies the busiest hours of operation based on patient check-in times. Use this data to optimize staff scheduling, manage patient flow, and reduce waiting times during peak periods.
                    </p>
                    <?php if (!empty($peak_hours)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Hour</th>
                                    <th>Visit Count</th>
                                    <th>Relative Activity</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $max_hourly_visits = max(array_column($peak_hours, 'visit_count'));
                                foreach ($peak_hours as $hour_data):
                                    $activity_percent = $max_hourly_visits > 0
                                        ? round(($hour_data['visit_count'] / $max_hourly_visits) * 100, 1)
                                        : 0;
                                    $hour_display = date('g:00 A', strtotime($hour_data['hour_of_day'] . ':00'));
                                ?>
                                    <tr>
                                        <td><?php echo $hour_display; ?></td>
                                        <td><?php echo number_format($hour_data['visit_count']); ?></td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $activity_percent; ?>%"></div>
                                            </div>
                                            <small><?php echo $activity_percent; ?>% of peak</small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="chart-placeholder">
                            <div>
                                <i class="fas fa-clock"></i>
                                <p>No peak hours data available.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Demographic Analysis -->
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-users"></i> Demographic Analysis</h3>
                    </div>
                    <p style="color: var(--text-light); margin-bottom: 15px; font-size: 14px;">
                        <i class="fas fa-info-circle" style="color: var(--primary-color); margin-right: 5px;"></i>
                        Breaks down patient visits by age groups and gender. This helps identify which demographics utilize services most, enabling targeted health programs and appropriate resource allocation for different patient populations.
                    </p>
                    <?php if (!empty($demographic_analysis)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Age Group</th>
                                    <th>Gender</th>
                                    <th>Total Visits</th>
                                    <th>Unique Patients</th>
                                    <th>Avg Visits per Patient</th>
                                    <th>Activity Level</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $max_demo_visits = max(array_column($demographic_analysis, 'visit_count'));
                                foreach ($demographic_analysis as $demo):
                                    $avg_visits_demo = $demo['unique_patients'] > 0
                                        ? round($demo['visit_count'] / $demo['unique_patients'], 1)
                                        : 0;
                                    $activity_percent = $max_demo_visits > 0
                                        ? round(($demo['visit_count'] / $max_demo_visits) * 100, 1)
                                        : 0;
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($demo['age_group']); ?></td>
                                        <td><?php echo htmlspecialchars($demo['sex']); ?></td>
                                        <td><?php echo number_format($demo['visit_count']); ?></td>
                                        <td><?php echo number_format($demo['unique_patients']); ?></td>
                                        <td><?php echo $avg_visits_demo; ?></td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $activity_percent; ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="chart-placeholder">
                            <div>
                                <i class="fas fa-users"></i>
                                <p>No demographic analysis data available.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Facility Performance -->
                <div class="chart-container">
                    <div class="chart-header">
                        <h3 class="chart-title"><i class="fas fa-hospital"></i> Facility Performance</h3>
                    </div>
                    <p style="color: var(--text-light); margin-bottom: 15px; font-size: 14px;">
                        <i class="fas fa-info-circle" style="color: var(--primary-color); margin-right: 5px;"></i>
                        Compares performance metrics across different CHO facilities including visit volumes, completion rates, and average visit duration. Use this to identify best practices and facilities that may need additional support.
                    </p>
                    <?php if (!empty($facility_performance)): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Facility</th>
                                    <th>Type</th>
                                    <th>Total Visits</th>
                                    <th>Completed</th>
                                    <th>Completion Rate</th>
                                    <th>Avg Duration</th>
                                    <th>Performance</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($facility_performance as $facility):
                                    $facility_completion_rate = $facility['total_visits'] > 0
                                        ? round(($facility['completed_visits'] / $facility['total_visits']) * 100, 1)
                                        : 0;
                                    $avg_duration_display = $facility['avg_duration_minutes']
                                        ? round($facility['avg_duration_minutes']) . 'm'
                                        : 'N/A';
                                ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($facility['facility_name']); ?></td>
                                        <td><?php echo htmlspecialchars($facility['facility_type']); ?></td>
                                        <td><?php echo number_format($facility['total_visits']); ?></td>
                                        <td><?php echo number_format($facility['completed_visits']); ?></td>
                                        <td><?php echo $facility_completion_rate; ?>%</td>
                                        <td><?php echo $avg_duration_display; ?></td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $facility_completion_rate; ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="chart-placeholder">
                            <div>
                                <i class="fas fa-hospital"></i>
                                <p>No facility performance data available.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
            <!-- Debug Information (only visible in development) -->
            <?php if (isset($_GET['debug']) && is_employee_logged_in()): ?>
                <div class="chart-container" style="margin-top: 30px; background: #f8f9fa; border-left: 4px solid var(--warning-color);">
                    <h3 style="color: var(--warning-color);">Debug Information</h3>
                    <table class="data-table">
                        <tr>
                            <td><strong>Session Status:</strong></td>
                            <td><?php echo is_employee_logged_in() ? 'Active' : 'Inactive'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Employee ID:</strong></td>
                            <td><?php echo get_employee_session('employee_id') ?: 'Not set'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Role:</strong></td>
                            <td><?php echo get_employee_session('role') ?: 'Not set'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Last Activity:</strong></td>
                            <td><?php echo get_employee_session('last_activity') ? date('Y-m-d H:i:s', get_employee_session('last_activity')) : 'Not set'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Session Name:</strong></td>
                            <td><?php echo session_name(); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Session ID:</strong></td>
                            <td><?php echo session_id(); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Current URL:</strong></td>
                            <td><?php echo $_SERVER['REQUEST_URI'] ?? 'Unknown'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Start Date:</strong></td>
                            <td><?php echo htmlspecialchars($start_date); ?></td>
                        </tr>
                        <tr>
                            <td><strong>End Date:</strong></td>
                            <td><?php echo htmlspecialchars($end_date); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Facility Filter:</strong></td>
                            <td><?php echo $facility_filter ?: 'None'; ?></td>
                        </tr>
                    </table>
                    <p style="margin-top: 15px; font-size: 12px; color: var(--text-light);">
                        <strong>Note:</strong> Add <code>?debug=1</code> to the URL to see this debug information.
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <script>
        // Patient Visits Report JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Patient Visits Report loaded');

            // Initialize tooltips and interactive elements
            initializeInteractiveElements();

            // Auto-submit form when filters change (with debounce)
            setupAutoSubmit();

            // Setup export button interactions
            setupExportButtons();

            // Add loading states for better UX
            setupLoadingStates();
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

        function setupAutoSubmit() {
            // Disable auto-submit for now to prevent logout issues
            // The auto-submit was causing frequent page reloads which trigger session checks

            // Instead, we'll rely on the manual "Apply Filters" button
            // This prevents accidental logouts from rapid form submissions

            /* Original auto-submit code - disabled to prevent logout issues
            const filters = document.querySelectorAll('#start_date, #end_date, #facility_id');
            let timeout;
            
            filters.forEach(filter => {
                filter.addEventListener('change', function() {
                    clearTimeout(timeout);
                    timeout = setTimeout(() => {
                        document.querySelector('form').submit();
                    }, 500);
                });
            });
            */
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
                if (icon) icon.className = 'fas fa-sort';
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

        // Utility function to format numbers
        function formatNumber(num) {
            return new Intl.NumberFormat().format(num);
        }

        // Utility function to calculate percentage
        function calculatePercentage(part, total) {
            return total > 0 ? Math.round((part / total) * 100 * 10) / 10 : 0;
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

        // Initialize progress bar animations after page load
        setTimeout(animateProgressBars, 500);
    </script>
</body>

</html>