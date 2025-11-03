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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Management - CHO Koronadal</title>

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

        /* Reports overview section */
        .reports-overview {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .reports-overview h2 {
            color: var(--primary-dark);
            font-size: 22px;
            font-weight: 600;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .reports-overview h2 i {
            color: var(--primary-color);
        }

        .reports-overview p {
            color: var(--text-light);
            margin: 0;
            line-height: 1.6;
        }

        /* Reports grid */
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .report-card {
            background: var(--background-light);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }

        .report-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-heavy);
            border-color: var(--primary-color);
        }

        .report-card:hover::before {
            transform: scaleX(1);
        }

        .report-card.disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background: #f8f9fa;
        }

        .report-card.disabled:hover {
            transform: none;
            box-shadow: var(--shadow-light);
        }

        .report-icon {
            font-size: 48px;
            color: var(--primary-color);
            margin-bottom: 15px;
            display: block;
        }

        .report-card.disabled .report-icon {
            color: #6c757d;
        }

        .report-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-dark);
            margin: 0 0 8px 0;
        }

        .report-card.disabled .report-title {
            color: #6c757d;
        }

        .report-description {
            font-size: 14px;
            color: var(--text-light);
            margin: 0 0 15px 0;
            line-height: 1.4;
        }

        .report-status {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 500;
            display: inline-block;
        }

        .status-available {
            background-color: #d1e7dd;
            color: #0f5132;
        }

        .status-coming-soon {
            background-color: #fff3cd;
            color: #664d03;
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 24px;
            }

            .reports-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .reports-overview {
                padding: 20px;
            }

            .report-card {
                padding: 15px;
            }

            .report-icon {
                font-size: 40px;
            }

            .report-title {
                font-size: 16px;
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
                <span> Reports Management</span>
            </div>

            <div class="page-header">
                <h1><i class="fas fa-chart-bar"></i> Reports Management</h1>
                <p>Generate and view comprehensive reports for healthcare operations and analytics</p>
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

            <!-- Reports Overview -->
            <div class="reports-overview">
                <h2><i class="fas fa-info-circle"></i> Reports Overview</h2>
                <p>Access comprehensive reports for financial analysis, clinical operations, patient analytics, and system monitoring. Each report can be customized with date ranges, filters, and export options to meet your specific requirements.</p>
            </div>

            <!-- Reports Grid -->
            <div class="reports-grid">
                <!-- Financial Reports -->
                <div class="report-card" onclick="handleReportClick('financial')">
                    <i class="fas fa-file-invoice-dollar report-icon"></i>
                    <h3 class="report-title">Financial Reports</h3>
                    <p class="report-description">
                        Generate comprehensive financial reports including revenue, expenses, billing performance, and financial health indicators.
                    </p>
                    <span class="report-status status-available">Available</span>
                </div>

                <!-- Patient Demographics -->
                <div class="report-card" onclick="handleReportClick('patient_demographics')">
                    <i class="fas fa-users report-icon"></i>
                    <h3 class="report-title">Patient Demographics</h3>
                    <p class="report-description">
                        Comprehensive analysis of patient population demographics including age distribution, gender breakdown, and barangay representation.
                    </p>
                    <span class="report-status status-available">Available</span>
                </div>

                <!-- Referral Summary -->
                <div class="report-card" onclick="handleReportClick('referral_summary')">
                    <i class="fas fa-share report-icon"></i>
                    <h3 class="report-title">Referral Summary</h3>
                    <p class="report-description">
                        Comprehensive analysis of patient referrals, trends, and facility coordination statistics.
                    </p>
                    <span class="report-status status-available">Available</span>
                </div>

                <!-- Morbidity Report -->
                <div class="report-card" onclick="handleReportClick('morbidity_report')">
                    <i class="fas fa-heartbeat report-icon"></i>
                    <h3 class="report-title">Morbidity Report</h3>
                    <p class="report-description">
                        Comprehensive analysis of disease patterns, health conditions, and epidemiological trends.
                    </p>
                    <span class="report-status status-available">Available</span>
                </div>

                <!-- Patient Visits -->
                <div class="report-card" onclick="handleReportClick('patient_visits')">
                    <i class="fas fa-calendar-check report-icon"></i>
                    <h3 class="report-title">Patient Visits</h3>
                    <p class="report-description">
                        Comprehensive analysis of patient visit patterns, frequency, and healthcare utilization trends.
                    </p>
                    <span class="report-status status-available">Available</span>
                </div>

                <!-- Dispensed Logs -->
                <div class="report-card" onclick="handleReportClick('dispensed_logs')">
                    <i class="fas fa-prescription-bottle-alt report-icon"></i>
                    <h3 class="report-title">Dispensed Logs</h3>
                    <p class="report-description">
                        Comprehensive tracking of medication dispensing activities and pharmaceutical inventory management.
                    </p>
                    <span class="report-status status-available">Available</span>
                </div>

                <!-- Lab Statistics -->
                <div class="report-card" onclick="handleReportClick('lab_statistics')">
                    <i class="fas fa-flask report-icon"></i>
                    <h3 class="report-title">Lab Statistics</h3>
                    <p class="report-description">
                        Comprehensive analysis of laboratory test performance, turnaround times, and diagnostic trends.
                    </p>
                    <span class="report-status status-available">Available</span>
                </div>

                <!-- Service Utilization -->
                <div class="report-card" onclick="handleReportClick('service_utilization')">
                    <i class="fas fa-stethoscope report-icon"></i>
                    <h3 class="report-title">Service Utilization</h3>
                    <p class="report-description">
                        Comprehensive analysis of healthcare service usage patterns, demand trends, and resource allocation.
                    </p>
                    <span class="report-status status-available">Available</span>
                </div>
            </div>
        </div>
    </section>

    <script>
        function handleReportClick(reportType) {
            // Navigate to the specific report pages
            switch(reportType) {
                case 'financial':
                    window.location.href = 'financial.php';
                    break;
                case 'patient_demographics':
                    window.location.href = 'patient_demographics.php';
                    break;
                case 'referral_summary':
                    window.location.href = 'referral_summary.php';
                    break;
                case 'morbidity_report':
                    window.location.href = 'morbidity_report.php';
                    break;
                case 'patient_visits':
                    window.location.href = 'patient_visits.php';
                    break;
                case 'dispensed_logs':
                    window.location.href = 'dispensed_logs.php';
                    break;
                case 'lab_statistics':
                    window.location.href = 'lab_statistics.php';
                    break;
                case 'service_utilization':
                    window.location.href = 'service_utilization.php';
                    break;
                default:
                    console.log('Unknown report type:', reportType);
            }
        }
    </script>
</body>

</html>