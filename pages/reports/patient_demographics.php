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

// Patient demographics data processing will go here
// Database queries for patient demographics
$demographics_data = [];
$total_patients = 0;
$age_distribution = [];
$gender_distribution = [];
$barangay_distribution = [];
$district_distribution = [];
$philhealth_distribution = [];
$philhealth_overall = [];
$registration_trends = [];

try {
    // Get total active patients
    $total_query = "SELECT COUNT(*) as total FROM patients WHERE status = 'active'";
    $total_result = $conn->query($total_query);
    $total_patients = $total_result->fetch_assoc()['total'];

    // Debug: Log the total patients count
    error_log("DEBUG - Total active patients: " . $total_patients);

    // Age distribution - calculate age from date_of_birth
    $age_query = "
        SELECT 
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 1 THEN 'Infants (0-1)'
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 1 AND 4 THEN 'Toddlers (1-4)'
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 'Children (5-12)'
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 'Teens (13-17)'
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 'Young Adults (18-35)'
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 'Adults (36-59)'
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) >= 60 THEN 'Seniors (60+)'
                ELSE 'Unknown'
            END as age_group,
            COUNT(*) as count
        FROM patients 
        WHERE status = 'active' AND date_of_birth IS NOT NULL
        GROUP BY age_group
        ORDER BY 
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 1 THEN 1
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 1 AND 4 THEN 2
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 3
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 4
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 5
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 6
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) >= 60 THEN 7
                ELSE 8
            END
    ";
    $age_result = $conn->query($age_query);
    $age_total = 0;
    while ($row = $age_result->fetch_assoc()) {
        $age_distribution[] = $row;
        $age_total += $row['count'];
    }

    // Debug: Log the age distribution total
    error_log("DEBUG - Age distribution total: " . $age_total . " (should equal total patients if all have birth dates)");

    // Gender distribution
    $gender_query = "
        SELECT 
            sex as gender,
            COUNT(*) as count
        FROM patients 
        WHERE status = 'active'
        GROUP BY sex
    ";
    $gender_result = $conn->query($gender_query);
    while ($row = $gender_result->fetch_assoc()) {
        $gender_distribution[] = $row;
    }

    // Barangay distribution (all barangays)
    $barangay_query = "
        SELECT 
            b.barangay_name,
            COUNT(p.patient_id) as count
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.status = 'active'
        GROUP BY b.barangay_id, b.barangay_name
        ORDER BY count DESC, b.barangay_name ASC
    ";
    $barangay_result = $conn->query($barangay_query);
    while ($row = $barangay_result->fetch_assoc()) {
        $barangay_distribution[] = $row;
    }

    // District distribution (count patients by district through barangay)
    $district_query = "
        SELECT 
            d.district_name,
            COUNT(p.patient_id) as count
        FROM patients p
        INNER JOIN barangay b ON p.barangay_id = b.barangay_id
        INNER JOIN districts d ON b.district_id = d.district_id
        WHERE p.status = 'active'
        GROUP BY d.district_id, d.district_name
        ORDER BY count DESC, d.district_name ASC
    ";
    $district_result = $conn->query($district_query);
    while ($row = $district_result->fetch_assoc()) {
        $district_distribution[] = $row;
    }

    // PhilHealth membership distribution
    // First get the overall membership stats
    $philhealth_overall_query = "
        SELECT 
            CASE 
                WHEN isPhilHealth = 1 THEN 'PhilHealth Member'
                ELSE 'Non-Member'
            END as membership_status,
            COUNT(*) as count
        FROM patients 
        WHERE status = 'active'
        GROUP BY isPhilHealth
    ";
    $philhealth_overall_result = $conn->query($philhealth_overall_query);
    $philhealth_overall = [];
    while ($row = $philhealth_overall_result->fetch_assoc()) {
        $philhealth_overall[] = $row;
    }

    // Then get the distribution of PhilHealth types among members
    $philhealth_query = "
        SELECT 
            pt.type_name as philhealth_type,
            COUNT(p.patient_id) as count
        FROM patients p
        INNER JOIN philhealth_types pt ON p.philhealth_type_id = pt.id
        WHERE p.status = 'active' AND p.isPhilHealth = 1
        GROUP BY p.philhealth_type_id, pt.type_name
        ORDER BY count DESC
    ";
    $philhealth_result = $conn->query($philhealth_query);
    while ($row = $philhealth_result->fetch_assoc()) {
        $philhealth_distribution[] = $row;
    }

    // Age distribution by district
    $age_by_district_query = "
        SELECT 
            d.district_name,
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) < 1 THEN 'Infants (0-1)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 1 AND 4 THEN 'Toddlers (1-4)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 'Children (5-12)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 'Teens (13-17)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 'Young Adults (18-35)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 'Adults (36-59)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) >= 60 THEN 'Seniors (60+)'
                ELSE 'Unknown'
            END as age_group,
            COUNT(*) as count
        FROM patients p
        INNER JOIN barangay b ON p.barangay_id = b.barangay_id
        INNER JOIN districts d ON b.district_id = d.district_id
        WHERE p.status = 'active' AND p.date_of_birth IS NOT NULL
        GROUP BY d.district_id, d.district_name, age_group
        ORDER BY d.district_name, 
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) < 1 THEN 1
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 1 AND 4 THEN 2
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 3
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 4
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 5
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 6
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) >= 60 THEN 7
                ELSE 8
            END
    ";
    $age_by_district_result = $conn->query($age_by_district_query);
    $age_by_district = [];
    while ($row = $age_by_district_result->fetch_assoc()) {
        $age_by_district[] = $row;
    }

    // Age distribution by barangay
    $age_by_barangay_query = "
        SELECT 
            b.barangay_name,
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) < 1 THEN 'Infants (0-1)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 1 AND 4 THEN 'Toddlers (1-4)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 'Children (5-12)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 'Teens (13-17)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 'Young Adults (18-35)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 'Adults (36-59)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) >= 60 THEN 'Seniors (60+)'
                ELSE 'Unknown'
            END as age_group,
            COUNT(*) as count
        FROM patients p
        INNER JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.status = 'active' AND p.date_of_birth IS NOT NULL
        GROUP BY b.barangay_id, b.barangay_name, age_group
        ORDER BY b.barangay_name, 
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) < 1 THEN 1
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 1 AND 4 THEN 2
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 3
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 4
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 5
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 6
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) >= 60 THEN 7
                ELSE 8
            END
    ";
    $age_by_barangay_result = $conn->query($age_by_barangay_query);
    $age_by_barangay = [];
    while ($row = $age_by_barangay_result->fetch_assoc()) {
        $age_by_barangay[] = $row;
    }

    // Gender distribution by district
    $gender_by_district_query = "
        SELECT 
            d.district_name,
            p.sex as gender,
            COUNT(*) as count
        FROM patients p
        INNER JOIN barangay b ON p.barangay_id = b.barangay_id
        INNER JOIN districts d ON b.district_id = d.district_id
        WHERE p.status = 'active'
        GROUP BY d.district_id, d.district_name, p.sex
        ORDER BY d.district_name, p.sex
    ";
    $gender_by_district_result = $conn->query($gender_by_district_query);
    $gender_by_district = [];
    while ($row = $gender_by_district_result->fetch_assoc()) {
        $gender_by_district[] = $row;
    }

    // Gender distribution by barangay
    $gender_by_barangay_query = "
        SELECT 
            b.barangay_name,
            p.sex as gender,
            COUNT(*) as count
        FROM patients p
        INNER JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.status = 'active'
        GROUP BY b.barangay_id, b.barangay_name, p.sex
        ORDER BY b.barangay_name, p.sex
    ";
    $gender_by_barangay_result = $conn->query($gender_by_barangay_query);
    $gender_by_barangay = [];
    while ($row = $gender_by_barangay_result->fetch_assoc()) {
        $gender_by_barangay[] = $row;
    }

    // PhilHealth distribution by district
    $philhealth_by_district_query = "
        SELECT 
            d.district_name,
            CASE 
                WHEN p.isPhilHealth = 1 THEN 'PhilHealth Member'
                ELSE 'Non-Member'
            END as philhealth_type,
            COUNT(p.patient_id) as count
        FROM patients p
        INNER JOIN barangay b ON p.barangay_id = b.barangay_id
        INNER JOIN districts d ON b.district_id = d.district_id
        WHERE p.status = 'active'
        GROUP BY d.district_id, d.district_name, p.isPhilHealth
        ORDER BY d.district_name, p.isPhilHealth DESC
    ";
    $philhealth_by_district_result = $conn->query($philhealth_by_district_query);
    $philhealth_by_district = [];
    while ($row = $philhealth_by_district_result->fetch_assoc()) {
        $philhealth_by_district[] = $row;
    }

    // PhilHealth distribution by barangay
    $philhealth_by_barangay_query = "
        SELECT 
            b.barangay_name,
            CASE 
                WHEN p.isPhilHealth = 1 THEN 'PhilHealth Member'
                ELSE 'Non-Member'
            END as philhealth_type,
            COUNT(p.patient_id) as count
        FROM patients p
        INNER JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.status = 'active'
        GROUP BY b.barangay_id, b.barangay_name, p.isPhilHealth
        ORDER BY b.barangay_name, p.isPhilHealth DESC
    ";
    $philhealth_by_barangay_result = $conn->query($philhealth_by_barangay_query);
    $philhealth_by_barangay = [];
    while ($row = $philhealth_by_barangay_result->fetch_assoc()) {
        $philhealth_by_barangay[] = $row;
    }
} catch (Exception $e) {
    $error = "Error fetching demographics data: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Demographics Report - CHO Koronadal</title>

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

        /* Demographics content section */
        .demographics-content {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .demographics-content h3 {
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .demographics-content h3 i {
            color: var(--primary-color);
        }

        /* Statistics grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            box-shadow: var(--shadow-light);
        }

        .stat-card h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
            opacity: 0.9;
            font-weight: 500;
        }

        .stat-card .stat-number {
            font-size: 28px;
            font-weight: 700;
            margin: 0;
        }

        .stat-card i {
            font-size: 24px;
            margin-bottom: 10px;
            opacity: 0.8;
        }

        /* Charts grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-container {
            background: var(--background-light);
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }

        .chart-container h4 {
            color: var(--primary-dark);
            margin: 0 0 20px 0;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-container h4 i {
            color: var(--primary-color);
        }

        /* Chart header with view more button */
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-header h4 {
            margin: 0;
        }

        .btn-view-more {
            background: var(--secondary-color);
            color: white;
            font-size: 12px;
            padding: 6px 12px;
            border-radius: 6px;
        }

        .btn-view-more:hover {
            background: var(--primary-color);
        }

        .btn-view-more i {
            margin-right: 5px;
        }

        .chart-wrapper {
            position: relative;
            height: 300px;
        }

        .chart-wrapper canvas {
            max-height: 300px;
        }

        /* Data table styles */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .data-table th,
        .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table th {
            background: #f8f9fa;
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 14px;
        }

        .data-table td {
            color: var(--text-dark);
            font-size: 14px;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

        /* Progress bars for percentages */
        .progress-bar {
            background: #e9ecef;
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
            margin: 5px 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        /* Report actions */
        .report-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
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

        .btn-secondary:hover {
            background: #545b62;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #05b48a;
        }

        /* Placeholder for demographics data */
        .demographics-placeholder {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
            font-style: italic;
        }

        .demographics-placeholder i {
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

            .demographics-content {
                padding: 20px;
            }
        }

        /* Print styles for folio size paper (8.5" x 13") */
        @media print {
            @page {
                size: 8.5in 13in;
                margin: 0.75in 0.5in;
                padding: 0;
            }

            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            body {
                font-family: 'Arial', sans-serif;
                font-size: 11px;
                line-height: 1.3;
                color: #000 !important;
                background: white !important;
                margin: 0;
                padding: 0;
            }

            .homepage {
                margin-left: 0 !important;
                width: 100% !important;
            }

            .content-wrapper {
                padding: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
            }

            /* Hide elements not needed in print */
            .breadcrumb,
            .report-actions,
            .btn,
            .btn-view-more,
            .modal,
            .sidebar,
            .mobile-topbar {
                display: none !important;
            }

            /* Print header */
            .page-header {
                text-align: center;
                margin-bottom: 20px;
                padding-bottom: 10px;
                border-bottom: 2px solid #000;
                page-break-inside: avoid;
            }

            .page-header h1 {
                font-size: 18px !important;
                font-weight: bold;
                color: #000 !important;
                margin: 0 0 5px 0;
            }

            .page-header p {
                font-size: 12px !important;
                color: #333 !important;
                margin: 0;
            }

            /* Add header with facility info */
            .page-header::before {
                content: "Republic of the Philippines\00000aCity Health Office\00000aKoronadal City\00000a\00000aPatient Demographics Report";
                white-space: pre;
                display: block;
                text-align: center;
                font-size: 14px;
                font-weight: bold;
                margin-bottom: 15px;
                line-height: 1.4;
            }

            /* Report overview */
            .report-overview {
                background: none !important;
                box-shadow: none !important;
                border: 1px solid #ccc;
                padding: 15px;
                margin-bottom: 20px;
                page-break-inside: avoid;
            }

            .report-overview h2 {
                font-size: 14px !important;
                color: #000 !important;
                margin-bottom: 8px;
            }

            .report-overview p {
                font-size: 10px !important;
                color: #333 !important;
                line-height: 1.3;
            }

            /* Demographics content */
            .demographics-content {
                background: none !important;
                box-shadow: none !important;
                border: none;
                padding: 0 !important;
                margin: 0 !important;
            }

            .demographics-content h3 {
                font-size: 14px !important;
                color: #000 !important;
                margin: 20px 0 10px 0;
                border-bottom: 1px solid #000;
                padding-bottom: 3px;
                page-break-after: avoid;
            }

            /* Statistics grid for print */
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
                margin-bottom: 20px;
                page-break-inside: avoid;
            }

            .stat-card {
                background: #f5f5f5 !important;
                color: #000 !important;
                border: 1px solid #ccc;
                padding: 10px;
                text-align: center;
                border-radius: 5px;
            }

            .stat-card h4 {
                font-size: 10px !important;
                margin-bottom: 5px;
                font-weight: bold;
                color: #000 !important;
            }

            .stat-card .stat-number {
                font-size: 16px !important;
                font-weight: bold;
                color: #000 !important;
            }

            .stat-card i {
                display: none;
            }

            /* Chart containers for print */
            .charts-grid {
                display: block;
                margin-bottom: 20px;
            }

            .chart-container {
                background: none !important;
                box-shadow: none !important;
                border: 1px solid #ccc;
                padding: 10px;
                margin-bottom: 15px;
                page-break-inside: avoid;
            }

            .chart-container h4 {
                font-size: 12px !important;
                color: #000 !important;
                margin-bottom: 10px;
                font-weight: bold;
            }

            .chart-header {
                margin-bottom: 10px;
            }

            .chart-header h4 {
                margin: 0;
            }

            /* Hide charts, show only tables in print */
            .chart-wrapper {
                display: none !important;
            }

            /* Data tables for print */
            .data-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 15px;
                font-size: 9px;
                page-break-inside: auto;
            }

            .data-table th,
            .data-table td {
                border: 1px solid #000;
                padding: 4px 6px;
                text-align: left;
                vertical-align: top;
            }

            .data-table th {
                background: #e9e9e9 !important;
                font-weight: bold;
                color: #000 !important;
                font-size: 9px;
            }

            .data-table td {
                color: #000 !important;
                font-size: 9px;
            }

            .data-table tr {
                page-break-inside: avoid;
            }

            /* Progress bars for print */
            .progress-bar {
                background: #e9e9e9 !important;
                border: 1px solid #ccc;
                height: 8px;
                margin: 2px 0;
            }

            .progress-fill {
                background: #666 !important;
                height: 100%;
            }

            /* Add summary statistics after tables */
            .print-summary {
                display: block !important;
                margin-top: 20px;
                padding: 10px;
                border: 1px solid #000;
                background: #f9f9f9 !important;
                page-break-inside: avoid;
            }

            .print-summary h4 {
                font-size: 12px !important;
                margin-bottom: 8px;
                color: #000 !important;
            }

            .print-summary p {
                font-size: 10px !important;
                margin: 3px 0;
                color: #000 !important;
            }

            /* Footer */
            .print-footer {
                display: block !important;
                position: fixed;
                bottom: 0.5in;
                left: 0;
                right: 0;
                text-align: center;
                font-size: 8px;
                color: #666;
                border-top: 1px solid #ccc;
                padding-top: 5px;
            }

            /* Page breaks */
            .page-break {
                page-break-before: always;
            }

            .avoid-break {
                page-break-inside: avoid;
            }

            /* Ensure proper spacing between sections */
            .section-spacing {
                margin-bottom: 15px;
            }
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(3px);
        }

        .modal-content {
            background-color: var(--background-light);
            margin: 2% auto;
            padding: 0;
            border-radius: 12px;
            box-shadow: var(--shadow-heavy);
            width: 90%;
            max-width: 1000px;
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 12px 12px 0 0;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h3 i {
            font-size: 18px;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
            opacity: 0.8;
            transition: opacity 0.3s ease;
        }

        .close:hover,
        .close:focus {
            opacity: 1;
            text-decoration: none;
        }

        .modal-body {
            padding: 25px;
            overflow-y: auto;
            flex: 1;
        }

        .modal-chart-section {
            margin-bottom: 30px;
        }

        .modal-chart-wrapper {
            position: relative;
            height: 400px;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
        }

        .modal-chart-wrapper canvas {
            max-height: 360px;
        }

        .modal-table-section h4 {
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-table-section h4 i {
            color: var(--primary-color);
        }

        .modal-table-wrapper {
            background: var(--background-light);
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }

        .modal-data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .modal-data-table th,
        .modal-data-table td {
            padding: 15px 20px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-data-table th {
            background: #f8f9fa;
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 14px;
            border-bottom: 2px solid var(--border-color);
        }

        .modal-data-table td {
            color: var(--text-dark);
            font-size: 14px;
        }

        .modal-data-table tr:hover {
            background: #f8f9fa;
        }

        .modal-data-table tr:last-child td {
            border-bottom: none;
        }

        /* Modal tabs */
        .modal-tabs {
            display: flex;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 20px;
        }

        .modal-tab {
            background: none;
            border: none;
            padding: 12px 20px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-light);
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }

        .modal-tab:hover {
            color: var(--primary-color);
            background: #f8f9fa;
        }

        .modal-tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
            background: #f8f9fa;
        }

        .modal-tab-content {
            display: none;
        }

        .modal-tab-content.active {
            display: block;
        }

        /* District/Barangay breakdown tables */
        .breakdown-table {
            margin-bottom: 30px;
        }

        .breakdown-table h5 {
            color: var(--primary-dark);
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 15px 0;
            padding: 10px 15px;
            background: #f8f9fa;
            border-left: 4px solid var(--primary-color);
        }

        .breakdown-data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .breakdown-data-table th,
        .breakdown-data-table td {
            padding: 8px 12px;
            text-align: left;
            border: 1px solid var(--border-color);
        }

        .breakdown-data-table th {
            background: var(--primary-color);
            color: white;
            font-weight: 600;
            font-size: 12px;
        }

        .breakdown-data-table td {
            color: var(--text-dark);
            font-size: 12px;
        }

        .breakdown-data-table tr:nth-child(even) {
            background: #f8f9fa;
        }

        .breakdown-data-table tr:hover {
            background: #e3f2fd;
        }

        /* Age group colors for breakdown tables */
        .age-group-cell {
            font-weight: 600;
        }

        /* Age groups with exact CSS class matches */
        .age-group-infants---- { color: #ff6b6b; }
        .age-group-toddlers---- { color: #ffa726; }
        .age-group-children----- { color: #66bb6a; }
        .age-group-teens----- { color: #42a5f5; }
        .age-group-young-adults------- { color: #ab47bc; }
        .age-group-adults------ { color: #5c6bc0; }
        .age-group-seniors---- { color: #8d6e63; }
        .age-group-unknown { color: #757575; }

        /* Legacy age group classes (for backwards compatibility) */
        .age-group-infants { color: #ff6b6b; }
        .age-group-toddlers { color: #ffa726; }
        .age-group-children { color: #66bb6a; }
        .age-group-teens { color: #42a5f5; }
        .age-group-young-adults { color: #ab47bc; }
        .age-group-adults { color: #5c6bc0; }
        .age-group-seniors { color: #8d6e63; }

        /* Complete Full Report CSS */
        .complete-full-report {
            display: block;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: white;
            z-index: 9999;
            overflow-y: auto;
            font-family: 'Times New Roman', serif;
        }
        
        .report-page {
            max-width: 8.5in;
            margin: 0 auto;
            padding: 0.75in;
            background: white;
            min-height: 100vh;
        }
        
        .report-title-page {
            text-align: center;
            margin-bottom: 50px;
            padding: 40px 0;
            border-bottom: 4px solid #1e3a8a;
        }
        
        .report-title-page h1 {
            font-size: 22px;
            font-weight: bold;
            margin: 15px 0;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .report-title-page h2 {
            font-size: 16px;
            font-weight: normal;
            margin: 8px 0;
            color: #374151;
        }
        
        .generation-info {
            font-size: 12px;
            margin: 8px 0;
            color: #6b7280;
            font-style: italic;
        }
        
        .report-content h1 {
            font-size: 18px;
            font-weight: bold;
            color: #1e3a8a;
            border-bottom: 3px solid #1e3a8a;
            padding-bottom: 8px;
            margin: 30px 0 25px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-section, .geo-section, .age-section, .gender-section, .philhealth-section {
            margin-bottom: 35px;
        }
        
        .stat-section h2, .geo-section h2, .age-section h2, .gender-section h2, .philhealth-section h2 {
            font-size: 15px;
            font-weight: bold;
            color: #374151;
            margin-bottom: 15px;
            border-left: 4px solid #3b82f6;
            padding-left: 12px;
        }
        
        .definition-box {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .definition-box p {
            font-size: 12px;
            margin: 6px 0;
            line-height: 1.5;
        }
        
        .definition-box ul {
            margin: 8px 0;
            padding-left: 20px;
        }
        
        .definition-box li {
            font-size: 11px;
            margin: 3px 0;
            line-height: 1.4;
        }
        
        .count-display {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .big-number {
            font-size: 28px;
            font-weight: bold;
            color: white;
            margin: 0;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        .full-report-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-radius: 6px;
            overflow: hidden;
        }
        
        .full-report-table th {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: white;
            padding: 12px 10px;
            text-align: left;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 10px;
        }
        
        .full-report-table td {
            padding: 10px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }
        
        .full-report-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        
        .full-report-table tr:hover {
            background-color: #e0f2fe;
        }
        
        .breakdown-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
            margin-bottom: 15px;
            border: 1px solid #d1d5db;
        }
        
        .breakdown-table th {
            background-color: #374151;
            color: white;
            padding: 8px 6px;
            text-align: left;
            font-weight: bold;
            font-size: 9px;
        }
        
        .breakdown-table td {
            padding: 6px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .breakdown-table tr:nth-child(even) {
            background-color: #fafafa;
        }
        
        .district-table, .barangay-table {
            margin-bottom: 25px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            overflow: hidden;
            background: white;
        }
        
        .district-table h3, .barangay-table h3 {
            background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
            color: white;
            margin: 0;
            padding: 12px 15px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .report-footer {
            text-align: center;
            margin-top: 40px;
            padding: 20px 0;
            border-top: 3px solid #1e3a8a;
            font-size: 11px;
            color: #6b7280;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }
        
        .page-break {
            page-break-before: always;
        }
        
        /* Close button for the complete report */
        .complete-full-report::before {
            content: '✕ Close Report';
            position: fixed;
            top: 15px;
            right: 15px;
            background: #dc2626;
            color: white;
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            z-index: 10000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        .complete-full-report:hover::before {
            background: #b91c1c;
        }
        
        @media print {
            .complete-full-report::before {
                display: none;
            }
            
            .report-page {
                max-width: none;
                padding: 0.5in;
                margin: 0;
            }
            
            .page-break {
                page-break-before: always;
            }
            
            .complete-full-report {
                position: static;
                width: auto;
                height: auto;
                overflow: visible;
            }
        }

        @media print {
            .full-report-content {
                display: block !important;
                max-width: none;
                margin: 0;
                padding: 0.5in;
            }

            .full-report-content .page-break {
                page-break-before: always;
            }

            .full-report-content .definition {
                background: #f5f5f5 !important;
            }

            .full-report-content .data-table th {
                background: #e9e9e9 !important;
            }

            .full-report-content .report-footer {
                position: fixed;
                bottom: 0.5in;
                left: 0;
                right: 0;
            }
        }

        /* Full Report Print Styles */
        .print-full-report {
            display: none;
        }

        @media print {
            .print-full-report {
                display: block !important;
                font-family: Arial, sans-serif;
                font-size: 11px;
                line-height: 1.4;
                color: #000;
            }
            
            .print-full-report h1 {
                font-size: 16px;
                text-align: center;
                margin-bottom: 10px;
            }
            
            .print-full-report h2 {
                font-size: 14px;
                font-weight: bold;
                border-bottom: 1px solid #000;
                padding-bottom: 3px;
                margin: 15px 0 10px 0;
            }
            
            .print-full-report h3 {
                font-size: 12px;
                font-weight: bold;
                margin: 10px 0 5px 0;
            }
            
            .print-full-report .definition {
                background: #f5f5f5 !important;
                border-left: 3px solid #333;
                padding: 5px;
                margin-bottom: 10px;
            }
            
            .print-full-report table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 15px;
                font-size: 9px;
            }
            
            .print-full-report table th,
            .print-full-report table td {
                border: 1px solid #000;
                padding: 4px 6px;
                text-align: left;
            }
            
            .print-full-report table th {
                background: #e9e9e9 !important;
                font-weight: bold;
            }
        }

        /* Modal responsive design */
        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 5% auto;
                max-height: 85vh;
            }

            .modal-header {
                padding: 15px 20px;
            }

            .modal-header h3 {
                font-size: 18px;
            }

            .modal-body {
                padding: 20px;
            }

            .modal-chart-wrapper {
                height: 300px;
                padding: 15px;
            }

            .modal-chart-wrapper canvas {
                max-height: 270px;
            }

            .modal-data-table th,
            .modal-data-table td {
                padding: 10px 12px;
                font-size: 13px;
            }

            .chart-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .btn-view-more {
                align-self: flex-end;
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
                <span> Patient Demographics</span>
            </div>

            <div class="page-header">
                <h1><i class="fas fa-users"></i> Patient Demographics Report</h1>
                <p>Comprehensive analysis of patient population demographics and statistics</p>
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
                <p>This report provides detailed insights into patient demographics including age distribution, gender breakdown, and barangay representation. Use this data to understand your patient population and make informed healthcare planning decisions.</p>
            </div>

            <!-- Demographics Content -->
            <div class="demographics-content">
                <div class="report-actions">
                    <button class="btn btn-primary" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf"></i> Export PDF (Print)
                    </button>
                    <button class="btn btn-primary" onclick="exportFullReportPDF()">
                        <i class="fas fa-file-pdf"></i> Export Full Report PDF
                    </button>
                    <button class="btn btn-secondary" onclick="exportToExcel()">
                        <i class="fas fa-file-excel"></i> Export Excel (Summary)
                    </button>
                    <button class="btn btn-secondary" onclick="exportFullReportExcel()">
                        <i class="fas fa-file-excel"></i> Export Full Report Excel
                    </button>
                    <button class="btn btn-secondary" onclick="printReport()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <button class="btn btn-success" onclick="downloadServerPDF()" title="Server-generated PDF with detailed formatting">
                        <i class="fas fa-download"></i> Download PDF (Server)
                    </button>
                </div>

                <?php if ($total_patients > 0): ?>
                    <!-- Key Statistics -->
                    <h3><i class="fas fa-chart-pie"></i> Key Statistics</h3>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <i class="fas fa-users"></i>
                            <h4>Total Active Patients</h4>
                            <p class="stat-number"><?= number_format($total_patients) ?></p>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-id-card"></i>
                            <h4>PhilHealth Coverage</h4>
                            <p class="stat-number">
                                <?php
                                $philhealth_members = 0;
                                $total_checked = 0;
                                foreach ($philhealth_overall as $ph) {
                                    $total_checked += $ph['count'];
                                    if ($ph['membership_status'] === 'PhilHealth Member') {
                                        $philhealth_members = $ph['count'];
                                    }
                                }
                                $philhealth_percentage = $total_patients > 0 ? ($philhealth_members / $total_patients) * 100 : 0;

                                // Debug: Log the PhilHealth calculation
                                error_log("DEBUG - PhilHealth members: " . $philhealth_members . ", Total patients: " . $total_patients . ", Percentage: " . $philhealth_percentage);

                                echo number_format($philhealth_members) . ' (' . number_format($philhealth_percentage, 1) . '%)';
                                ?>
                            </p>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-wheelchair"></i>
                            <h4>PWD Patients</h4>
                            <p class="stat-number">
                                <?php
                                $pwd_query = "SELECT COUNT(*) as pwd_count FROM patients WHERE status = 'active' AND isPWD = 1";
                                $pwd_result = $conn->query($pwd_query);
                                $pwd_count = $pwd_result->fetch_assoc()['pwd_count'];
                                $pwd_percentage = $total_patients > 0 ? ($pwd_count / $total_patients) * 100 : 0;

                                // Debug: Log the PWD calculation
                                error_log("DEBUG - PWD count: " . $pwd_count . ", Total patients: " . $total_patients . ", Percentage: " . $pwd_percentage);

                                echo number_format($pwd_count) . ' (' . number_format($pwd_percentage, 1) . '%)';
                                ?>
                            </p>
                        </div>
                    </div>

                    <!-- Charts Section -->
                    <div class="charts-grid">
                        <!-- Age Distribution Chart -->
                        <div class="chart-container">
                            <div class="chart-header">
                                <h4><i class="fas fa-birthday-cake"></i> Age Distribution</h4>
                                <button class="btn btn-view-more" onclick="openModal('ageModal')">
                                    <i class="fas fa-expand-alt"></i> View More
                                </button>
                            </div>
                            <div class="chart-wrapper">
                                <canvas id="ageChart"></canvas>
                            </div>
                        </div>

                        <!-- Gender Distribution Chart -->
                        <div class="chart-container">
                            <div class="chart-header">
                                <h4><i class="fas fa-venus-mars"></i> Gender Distribution</h4>
                                <button class="btn btn-view-more" onclick="openModal('genderModal')">
                                    <i class="fas fa-expand-alt"></i> View More
                                </button>
                            </div>
                            <div class="chart-wrapper">
                                <canvas id="genderChart"></canvas>
                            </div>
                        </div>

                        <!-- PhilHealth Type Distribution Chart -->
                        <div class="chart-container">
                            <div class="chart-header">
                                <h4><i class="fas fa-id-card"></i> PhilHealth Membership Type Distribution</h4>
                                <button class="btn btn-view-more" onclick="openModal('philhealthModal')">
                                    <i class="fas fa-expand-alt"></i> View More
                                </button>
                            </div>
                            <div class="chart-wrapper">
                                <canvas id="philhealthChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- District Distribution Table -->
                    <div class="chart-container">
                        <h4><i class="fas fa-map-marker-alt"></i> District Distribution by Patient Population</h4>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>District</th>
                                    <th>Patient Count</th>
                                    <th>Percentage</th>
                                    <th>Distribution</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($district_distribution as $index => $district): ?>
                                    <?php $percentage = ($district['count'] / $total_patients) * 100; ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars($district['district_name'] ?? 'Unknown') ?></td>
                                        <td><?= number_format($district['count']) ?></td>
                                        <td><?= number_format($percentage, 1) ?>%</td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?= $percentage ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Barangay Distribution Table -->
                    <div class="chart-container">
                        <h4><i class="fas fa-map-marker-alt"></i> Barangay Distribution by Patient Population</h4>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Barangay</th>
                                    <th>Patient Count</th>
                                    <th>Percentage</th>
                                    <th>Distribution</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($barangay_distribution as $index => $barangay): ?>
                                    <?php $percentage = ($barangay['count'] / $total_patients) * 100; ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars($barangay['barangay_name'] ?? 'Unknown') ?></td>
                                        <td><?= number_format($barangay['count']) ?></td>
                                        <td><?= number_format($percentage, 1) ?>%</td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?= $percentage ?>%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Print Summary Section (only visible in print) -->
                    <div class="print-summary avoid-break" style="display: none;">
                        <h4>Report Summary</h4>
                        <p><strong>Total Active Patients:</strong> <?= number_format($total_patients) ?></p>
                        <p><strong>Report Generated:</strong> <?= date('F j, Y \a\t g:i A') ?></p>
                        <p><strong>Generated By:</strong> <?= htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) ?> (<?= htmlspecialchars($_SESSION['role']) ?>)</p>
                        <?php if (!empty($age_distribution)): ?>
                            <p><strong>Age Groups Represented:</strong> <?= count($age_distribution) ?> age categories</p>
                        <?php endif; ?>
                        <?php if (!empty($district_distribution)): ?>
                            <p><strong>Districts Covered:</strong> <?= count($district_distribution) ?> districts</p>
                        <?php endif; ?>
                        <?php if (!empty($barangay_distribution)): ?>
                            <p><strong>Barangays Represented:</strong> <?= count($barangay_distribution) ?> barangays</p>
                        <?php endif; ?>
                    </div>

                    <!-- Print Footer (only visible in print) -->
                    <div class="print-footer" style="display: none;">
                        Patient Demographics Report - City Health Office, Koronadal City | Generated: <?= date('F j, Y') ?>
                    </div>



                <?php else: ?>
                    <!-- No Data Placeholder -->
                    <div class="demographics-placeholder">
                        <i class="fas fa-database"></i>
                        <p>No patient data available.</p>
                        <p>Please ensure patients are registered in the system to view demographics.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Age Distribution Modal -->
    <div id="ageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-birthday-cake"></i> Age Distribution - Detailed View</h3>
                <span class="close" onclick="closeModal('ageModal')">&times;</span>
            </div>
            <div class="modal-body">
                <!-- Modal Tabs -->
                <div class="modal-tabs">
                    <button class="modal-tab active" onclick="switchTab('age', 'overview')">
                        <i class="fas fa-chart-bar"></i> Overview
                    </button>
                    <button class="modal-tab" onclick="switchTab('age', 'district')">
                        <i class="fas fa-map-marked-alt"></i> By District
                    </button>
                    <button class="modal-tab" onclick="switchTab('age', 'barangay')">
                        <i class="fas fa-map-marker-alt"></i> By Barangay
                    </button>
                </div>

                <!-- Overview Tab -->
                <div id="age-overview" class="modal-tab-content active">
                    <div class="modal-chart-section">
                        <div class="modal-chart-wrapper">
                            <canvas id="ageModalChart"></canvas>
                        </div>
                    </div>
                    <div class="modal-table-section">
                        <h4><i class="fas fa-table"></i> Detailed Age Group Statistics</h4>
                        <div class="modal-table-wrapper">
                            <table class="modal-data-table">
                                <thead>
                                    <tr>
                                        <th>Age Group</th>
                                        <th>Count</th>
                                        <th>Percentage</th>
                                        <th>Distribution</th>
                                    </tr>
                                </thead>
                                <tbody id="ageModalTableBody">
                                    <!-- Data will be populated via JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- District Tab -->
                <div id="age-district" class="modal-tab-content">
                    <div class="modal-table-section">
                        <h4><i class="fas fa-map-marked-alt"></i> Age Distribution by District</h4>
                        <div id="ageDistrictBreakdown">
                            <!-- District breakdown tables will be populated via JavaScript -->
                        </div>
                    </div>
                </div>

                <!-- Barangay Tab -->
                <div id="age-barangay" class="modal-tab-content">
                    <div class="modal-table-section">
                        <h4><i class="fas fa-map-marker-alt"></i> Age Distribution by Barangay</h4>
                        <div id="ageBarangayBreakdown">
                            <!-- Barangay breakdown tables will be populated via JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Gender Distribution Modal -->
    <div id="genderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-venus-mars"></i> Gender Distribution - Detailed View</h3>
                <span class="close" onclick="closeModal('genderModal')">&times;</span>
            </div>
            <div class="modal-body">
                <!-- Modal Tabs -->
                <div class="modal-tabs">
                    <button class="modal-tab active" onclick="switchTab('gender', 'overview')">
                        <i class="fas fa-chart-pie"></i> Overview
                    </button>
                    <button class="modal-tab" onclick="switchTab('gender', 'district')">
                        <i class="fas fa-map-marked-alt"></i> By District
                    </button>
                    <button class="modal-tab" onclick="switchTab('gender', 'barangay')">
                        <i class="fas fa-map-marker-alt"></i> By Barangay
                    </button>
                </div>

                <!-- Overview Tab -->
                <div id="gender-overview" class="modal-tab-content active">
                    <div class="modal-chart-section">
                        <div class="modal-chart-wrapper">
                            <canvas id="genderModalChart"></canvas>
                        </div>
                    </div>
                    <div class="modal-table-section">
                        <h4><i class="fas fa-table"></i> Detailed Gender Statistics</h4>
                        <div class="modal-table-wrapper">
                            <table class="modal-data-table">
                                <thead>
                                    <tr>
                                        <th>Gender</th>
                                        <th>Total Count</th>
                                        <th>% of Total Patient Population</th>
                                        <th>Ratio</th>
                                    </tr>
                                </thead>
                                <tbody id="genderModalTableBody">
                                    <!-- Data will be populated via JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- District Tab -->
                <div id="gender-district" class="modal-tab-content">
                    <div class="modal-table-section">
                        <h4><i class="fas fa-map-marked-alt"></i> Gender Distribution by District</h4>
                        <div id="genderDistrictBreakdown">
                            <!-- District breakdown tables will be populated via JavaScript -->
                        </div>
                    </div>
                </div>

                <!-- Barangay Tab -->
                <div id="gender-barangay" class="modal-tab-content">
                    <div class="modal-table-section">
                        <h4><i class="fas fa-map-marker-alt"></i> Gender Distribution by Barangay</h4>
                        <div id="genderBarangayBreakdown">
                            <!-- Barangay breakdown tables will be populated via JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- PhilHealth Type Distribution Modal -->
    <div id="philhealthModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-id-card"></i> PhilHealth Membership Type Distribution - Detailed View</h3>
                <span class="close" onclick="closeModal('philhealthModal')">&times;</span>
            </div>
            <div class="modal-body">
                <!-- Modal Tabs -->
                <div class="modal-tabs">
                    <button class="modal-tab active" onclick="switchTab('philhealth', 'overview')">
                        <i class="fas fa-chart-pie"></i> Overview
                    </button>
                    <button class="modal-tab" onclick="switchTab('philhealth', 'district')">
                        <i class="fas fa-map-marked-alt"></i> By District
                    </button>
                    <button class="modal-tab" onclick="switchTab('philhealth', 'barangay')">
                        <i class="fas fa-map-marker-alt"></i> By Barangay
                    </button>
                </div>

                <!-- Overview Tab -->
                <div id="philhealth-overview" class="modal-tab-content active">
                    <div class="modal-chart-section">
                        <div class="modal-chart-wrapper">
                            <canvas id="philhealthModalChart"></canvas>
                        </div>
                    </div>
                    <div class="modal-table-section">
                        <h4><i class="fas fa-table"></i> PhilHealth Membership Overview</h4>
                        <div class="modal-table-wrapper">
                            <table class="modal-data-table">
                                <thead>
                                    <tr>
                                        <th>Membership Status</th>
                                        <th>Total Count</th>
                                        <th>% of Total Patient Population</th>
                                    </tr>
                                </thead>
                                <tbody id="philhealthOverallTableBody">
                                    <!-- Data will be populated via JavaScript -->
                                </tbody>
                            </table>
                        </div>
                        <h4><i class="fas fa-chart-pie"></i> PhilHealth Membership Type Breakdown</h4>
                        <div class="modal-table-wrapper">
                            <table class="modal-data-table">
                                <thead>
                                    <tr>
                                        <th>PhilHealth Membership Type</th>
                                        <th>Member Patients</th>
                                        <th>% of PhilHealth Member Patients</th>
                                        <th>% of Total Patient Population</th>
                                    </tr>
                                </thead>
                                <tbody id="philhealthModalTableBody">
                                    <!-- Data will be populated via JavaScript -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- District Tab -->
                <div id="philhealth-district" class="modal-tab-content">
                    <div class="modal-table-section">
                        <h4><i class="fas fa-map-marked-alt"></i> PhilHealth Distribution by District</h4>
                        <div id="philhealthDistrictBreakdown">
                            <!-- District breakdown tables will be populated via JavaScript -->
                        </div>
                    </div>
                </div>

                <!-- Barangay Tab -->
                <div id="philhealth-barangay" class="modal-tab-content">
                    <div class="modal-table-section">
                        <h4><i class="fas fa-map-marker-alt"></i> PhilHealth Distribution by Barangay</h4>
                        <div id="philhealthBarangayBreakdown">
                            <!-- Barangay breakdown tables will be populated via JavaScript -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Patient demographics data from PHP
        const ageData = <?= json_encode($age_distribution) ?>;
        const genderData = <?= json_encode($gender_distribution) ?>;
        const philhealthData = <?= json_encode($philhealth_distribution) ?>;
        const philhealthOverallData = <?= json_encode($philhealth_overall) ?>;
        
        // District and Barangay breakdown data
        const ageByDistrict = <?= json_encode($age_by_district) ?>;
        const ageByBarangay = <?= json_encode($age_by_barangay) ?>;
        const genderByDistrict = <?= json_encode($gender_by_district) ?>;
        const genderByBarangay = <?= json_encode($gender_by_barangay) ?>;
        const philhealthByDistrict = <?= json_encode($philhealth_by_district) ?>;
        const philhealthByBarangay = <?= json_encode($philhealth_by_barangay) ?>;

        // Additional data for full reports
        const totalPatients = <?= $total_patients ?>;
        const pwdCount = <?= $pwd_count ?>;
        const pwdPercentage = <?= number_format($pwd_percentage, 1) ?>;

        // Chart colors
        const chartColors = {
            primary: '#0077b6',
            secondary: '#00b4d8',
            accent: '#90e0ef',
            success: '#06d6a0',
            warning: '#ffd60a',
            danger: '#f72585',
            colors: ['#0077b6', '#00b4d8', '#90e0ef', '#06d6a0', '#ffd60a', '#f72585', '#8b5cf6', '#f59e0b']
        };

        document.addEventListener('DOMContentLoaded', function() {
            console.log('Patient Demographics Report loaded');
            console.log('Age data:', ageData);
            console.log('Gender data:', genderData);
            console.log('PhilHealth data:', philhealthData);
            console.log('PhilHealth overall data:', philhealthOverallData);

            // Debug: Check age data totals
            if (ageData.length > 0) {
                const ageTotal = ageData.reduce((sum, item) => sum + parseInt(item.count), 0);
                console.log('Age data total:', ageTotal);
                ageData.forEach(item => {
                    const percentage = ((parseInt(item.count) / ageTotal) * 100).toFixed(1);
                    console.log(`${item.age_group}: ${item.count} (${percentage}%)`);
                });
            }

            // Initialize charts if data is available
            if (ageData.length > 0) {
                initializeCharts();
            } else {
                console.log('No age data available for charts');
            }
        });

        function initializeCharts() {
            // Age Distribution Chart
            if (document.getElementById('ageChart')) {
                new Chart(document.getElementById('ageChart'), {
                    type: 'doughnut',
                    data: {
                        labels: ageData.map(item => item.age_group),
                        datasets: [{
                            data: ageData.map(item => parseInt(item.count)),
                            backgroundColor: chartColors.colors,
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 20,
                                    usePointStyle: true
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((context.raw / total) * 100).toFixed(1);
                                        return context.label + ': ' + context.raw + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Gender Distribution Chart
            if (document.getElementById('genderChart')) {
                new Chart(document.getElementById('genderChart'), {
                    type: 'pie',
                    data: {
                        labels: genderData.map(item => item.gender),
                        datasets: [{
                            data: genderData.map(item => parseInt(item.count)),
                            backgroundColor: [chartColors.primary, chartColors.secondary, chartColors.accent],
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 20,
                                    usePointStyle: true
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((context.raw / total) * 100).toFixed(1);
                                        return context.label + ': ' + context.raw + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // PhilHealth Type Distribution Chart
            if (document.getElementById('philhealthChart') && philhealthData.length > 0) {
                new Chart(document.getElementById('philhealthChart'), {
                    type: 'doughnut',
                    data: {
                        labels: philhealthData.map(item => item.philhealth_type),
                        datasets: [{
                            data: philhealthData.map(item => parseInt(item.count)),
                            backgroundColor: chartColors.colors.slice(0, philhealthData.length),
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 20,
                                    usePointStyle: true
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((context.raw / total) * 100).toFixed(1);
                                        return context.label + ': ' + context.raw + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            } else if (document.getElementById('philhealthChart')) {
                // Show message when no PhilHealth data is available
                const ctx = document.getElementById('philhealthChart').getContext('2d');
                ctx.fillStyle = '#6c757d';
                ctx.font = '16px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('No PhilHealth type data available', ctx.canvas.width / 2, ctx.canvas.height / 2);
            }
        }

        // Export functions
        function exportToPDF() {
            // Show print elements
            showPrintElements();
            
            // Set document title for PDF
            const originalTitle = document.title;
            document.title = `Patient_Demographics_Report_${new Date().toISOString().split('T')[0]}`;
            
            // Trigger print (which will generate PDF in most browsers)
            setTimeout(() => {
                window.print();
                
                // Hide print elements after printing
                setTimeout(() => {
                    hidePrintElements();
                    document.title = originalTitle;
                }, 1000);
            }, 500);
        }

        function printReport() {
            // Show print elements
            showPrintElements();
            
            // Trigger print
            setTimeout(() => {
                window.print();
                
                // Hide print elements after printing
                setTimeout(() => {
                    hidePrintElements();
                }, 1000);
            }, 500);
        }

        function showPrintElements() {
            // Show print summary and footer
            const printSummary = document.querySelector('.print-summary');
            const printFooter = document.querySelector('.print-footer');
            
            if (printSummary) printSummary.style.display = 'block';
            if (printFooter) printFooter.style.display = 'block';
        }

        function hidePrintElements() {
            // Hide print summary and footer
            const printSummary = document.querySelector('.print-summary');
            const printFooter = document.querySelector('.print-footer');
            
            if (printSummary) printSummary.style.display = 'none';
            if (printFooter) printFooter.style.display = 'none';
        }

        function exportToExcel() {
            // Create CSV data from the demographics data
            let csvContent = "data:text/csv;charset=utf-8,";
            
            // Add header
            csvContent += "Patient Demographics Report - City Health Office Koronadal\n";
            csvContent += `Generated: ${new Date().toLocaleDateString()}\n\n`;
            
            // Add summary statistics
            csvContent += "SUMMARY STATISTICS\n";
            csvContent += `Total Active Patients,${<?= $total_patients ?>}\n\n`;
            
            // Add age distribution data
            if (ageData.length > 0) {
                csvContent += "AGE DISTRIBUTION\n";
                csvContent += "Age Group,Count,Percentage\n";
                const ageTotal = ageData.reduce((sum, item) => sum + parseInt(item.count), 0);
                ageData.forEach(item => {
                    const percentage = ((parseInt(item.count) / ageTotal) * 100).toFixed(1);
                    csvContent += `"${item.age_group}",${item.count},${percentage}%\n`;
                });
                csvContent += "\n";
            }
            
            // Add gender distribution data
            if (genderData.length > 0) {
                csvContent += "GENDER DISTRIBUTION\n";
                csvContent += "Gender,Count,Percentage\n";
                const genderTotal = genderData.reduce((sum, item) => sum + parseInt(item.count), 0);
                genderData.forEach(item => {
                    const percentage = ((parseInt(item.count) / genderTotal) * 100).toFixed(1);
                    csvContent += `"${item.gender}",${item.count},${percentage}%\n`;
                });
                csvContent += "\n";
            }
            
            // Create download link
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `Patient_Demographics_Report_${new Date().toISOString().split('T')[0]}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Full Report Export Functions
        function exportFullReportPDF() {
            // Create comprehensive PDF report with full data structure
            generateCompleteFullReport();
            
            // Set document title for PDF
            const originalTitle = document.title;
            document.title = `Patient_Demographics_Full_Report_${new Date().toISOString().split('T')[0]}`;
            
            setTimeout(() => {
                window.print();
                setTimeout(() => {
                    restoreNormalView();
                    document.title = originalTitle;
                }, 1000);
            }, 500);
        }

        function exportFullReportExcel() {
            // Generate comprehensive Excel/CSV report following the specified format
            let csvContent = "data:text/csv;charset=utf-8,";
            
            // Header
            csvContent += "PATIENT DEMOGRAPHICS REPORT - FULL DATA EXPORT\n";
            csvContent += "City Health Office, Koronadal City\n";
            csvContent += `Generated: ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}\n\n`;
            
            // I. KEY STATISTICS
            csvContent += "I. KEY STATISTICS\n\n";
            
            // Total Active Patients
            csvContent += "Total Active Patients\n";
            csvContent += `Definition: Count of all patients with status = 'active'\n`;
            csvContent += `Count: ${totalPatients}\n`;
            csvContent += `Usage: Main denominator for all percentage calculations\n\n`;
            
            // PhilHealth Members
            const philhealthMembers = philhealthOverallData.find(item => item.membership_status === 'PhilHealth Member')?.count || 0;
            const philhealthPercentage = (philhealthMembers / totalPatients * 100).toFixed(1);
            csvContent += "Total PhilHealth Members (Active Patients)\n";
            csvContent += `Definition: Count of all active patients with isPhilHealth = 1\n`;
            csvContent += `Count: ${philhealthMembers} (${philhealthPercentage}%)\n`;
            csvContent += `Usage: Used to measure PhilHealth coverage rate among active patients\n\n`;
            
            // PWD Patients
            csvContent += "Total PWD Patients (Active Patients)\n";
            csvContent += `Definition: Count of all active patients with isPWD = 1\n`;
            csvContent += `Count: ${pwdCount} (${pwdPercentage}%)\n`;
            csvContent += `Usage: Indicates proportion of PWDs among active patients\n\n`;
            
            // II. GEOGRAPHIC DISTRIBUTION
            csvContent += "II. GEOGRAPHIC DISTRIBUTION\n\n";
            
            // District Distribution
            csvContent += "District Distribution\n";
            csvContent += "Categories: All districts (via barangay mapping)\n";
            csvContent += "District Name,Patient Count,Percentage\n";
            const districtData = <?= json_encode($district_distribution) ?>;
            const districtTotal = districtData.reduce((sum, item) => sum + parseInt(item.count), 0);
            districtData.forEach(district => {
                const percentage = ((district.count / districtTotal) * 100).toFixed(1);
                csvContent += `"${district.district_name}",${district.count},${percentage}%\n`;
            });
            csvContent += "\n";
            
            // Barangay Distribution
            csvContent += "Barangay Distribution\n";
            csvContent += "Categories: All barangays in the system\n";
            csvContent += "Barangay Name,Patient Count,Percentage\n";
            const barangayData = <?= json_encode($barangay_distribution) ?>;
            const barangayTotal = barangayData.reduce((sum, item) => sum + parseInt(item.count), 0);
            barangayData.forEach(barangay => {
                const percentage = ((parseInt(barangay.count) / barangayTotal) * 100).toFixed(1);
                csvContent += `"${barangay.barangay_name}",${barangay.count},${percentage}%\n`;
            });
            csvContent += "\n";
            
            // III. AGE DISTRIBUTION
            csvContent += "III. AGE DISTRIBUTION\n\n";
            
            // Overall Age Distribution
            csvContent += "Age Distribution\n";
            csvContent += "Categories: Infants (0-1), Toddlers (1-4), Children (5-12), Teens (13-17), Young Adults (18-35), Adults (36-59), Seniors (60+)\n";
            csvContent += "Age Group,Count,Percentage\n";
            
            // Ensure all age groups are included
            const allAgeGroups = [
                'Infants (0-1)', 'Toddlers (1-4)', 'Children (5-12)', 'Teens (13-17)',
                'Young Adults (18-35)', 'Adults (36-59)', 'Seniors (60+)'
            ];
            const ageDataMap = {};
            ageData.forEach(item => ageDataMap[item.age_group] = parseInt(item.count));
            const ageTotal = ageData.reduce((sum, item) => sum + parseInt(item.count), 0);
            
            allAgeGroups.forEach(ageGroup => {
                const count = ageDataMap[ageGroup] || 0;
                const percentage = ageTotal > 0 ? ((count / ageTotal) * 100).toFixed(1) : '0.0';
                csvContent += `"${ageGroup}",${count},${percentage}%\n`;
            });
            csvContent += "\n";
            
            // Age Distribution by District
            csvContent += "Age Distribution by District\n";
            csvContent += "Categories: Age groups per district\n";
            csvContent += "District,Age Group,Count,Percentage of District,Percentage of Total\n";
            
            const ageByDistrictGrouped = {};
            ageByDistrict.forEach(item => {
                if (!ageByDistrictGrouped[item.district_name]) {
                    ageByDistrictGrouped[item.district_name] = {};
                }
                ageByDistrictGrouped[item.district_name][item.age_group] = parseInt(item.count);
            });
            
            Object.keys(ageByDistrictGrouped).sort().forEach(district => {
                const districtAgeData = ageByDistrictGrouped[district];
                const districtAgeTotal = Object.values(districtAgeData).reduce((sum, count) => sum + count, 0);
                
                allAgeGroups.forEach(ageGroup => {
                    const count = districtAgeData[ageGroup] || 0;
                    const districtPercentage = districtAgeTotal > 0 ? ((count / districtAgeTotal) * 100).toFixed(1) : '0.0';
                    const totalPercentage = totalPatients > 0 ? ((count / totalPatients) * 100).toFixed(1) : '0.0';
                    csvContent += `"${district}","${ageGroup}",${count},${districtPercentage}%,${totalPercentage}%\n`;
                });
            });
            csvContent += "\n";
            
            // IV. SEX DISTRIBUTION
            csvContent += "IV. SEX DISTRIBUTION\n\n";
            
            // Gender Distribution
            csvContent += "Gender Distribution\n";
            csvContent += "Categories: Male, Female\n";
            csvContent += "Gender,Count,Percentage,Ratio\n";
            const genderTotal = genderData.reduce((sum, item) => sum + parseInt(item.count), 0);
            const maleCount = genderData.find(item => item.gender === 'Male')?.count || 0;
            const femaleCount = genderData.find(item => item.gender === 'Female')?.count || 0;
            const ratio = femaleCount > 0 ? (maleCount / femaleCount).toFixed(2) : 'N/A';
            
            genderData.forEach(item => {
                const percentage = ((parseInt(item.count) / genderTotal) * 100).toFixed(1);
                const genderRatio = item.gender === 'Male' ? `${ratio}:1 (M:F)` : `1:${ratio} (M:F)`;
                csvContent += `"${item.gender}",${item.count},${percentage}%,"${genderRatio}"\n`;
            });
            csvContent += "\n";
            
            // V. PHILHEALTH MEMBER DISTRIBUTION
            csvContent += "V. PHILHEALTH MEMBER DISTRIBUTION\n\n";
            
            // PhilHealth Membership
            csvContent += "PhilHealth Membership\n";
            csvContent += "Categories: PhilHealth Member (isPhilHealth = 1), Non-Member (isPhilHealth = 0)\n";
            csvContent += "Membership Status,Count,Percentage\n";
            const philhealthTotal = philhealthOverallData.reduce((sum, item) => sum + parseInt(item.count), 0);
            philhealthOverallData.forEach(item => {
                const percentage = ((parseInt(item.count) / philhealthTotal) * 100).toFixed(1);
                csvContent += `"${item.membership_status}",${item.count},${percentage}%\n`;
            });
            csvContent += "\n";
            
            // PhilHealth Membership Type Breakdown
            if (philhealthData.length > 0) {
                csvContent += "PhilHealth Membership Type Breakdown\n";
                csvContent += "Categories: Membership types (e.g., Indigent, Professional, Senior Citizen, PWD, etc.)\n";
                csvContent += "PhilHealth Type,Count,Percentage of Members,Percentage of Total\n";
                const memberTotal = philhealthData.reduce((sum, item) => sum + parseInt(item.count), 0);
                philhealthData.forEach(item => {
                    const memberPercentage = ((parseInt(item.count) / memberTotal) * 100).toFixed(1);
                    const totalPercentage = ((parseInt(item.count) / philhealthTotal) * 100).toFixed(1);
                    csvContent += `"${item.philhealth_type}",${item.count},${memberPercentage}%,${totalPercentage}%\n`;
                });
            }
            csvContent += "\n";
            
            // Create download link
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `Patient_Demographics_Full_Report_${new Date().toISOString().split('T')[0]}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function downloadServerPDF() {
            // Trigger server-side PDF generation
            window.open('generate_demographics_pdf.php', '_blank');
        }

        function generateCompleteFullReport() {
            // Hide all normal page content
            document.querySelector('.homepage').style.display = 'none';
            
            // Create the complete full report following the exact format requested
            const fullReportContainer = document.createElement('div');
            fullReportContainer.id = 'completeFullReport';
            fullReportContainer.className = 'complete-full-report';
            
            // Calculate all necessary data
            const philhealthMembers = philhealthOverallData.find(item => item.membership_status === 'PhilHealth Member')?.count || 0;
            const philhealthPercentage = (philhealthMembers / totalPatients * 100).toFixed(1);
            
            const allAgeGroups = [
                'Infants (0-1)', 'Toddlers (1-4)', 'Children (5-12)', 'Teens (13-17)',
                'Young Adults (18-35)', 'Adults (36-59)', 'Seniors (60+)'
            ];
            const ageDataMap = {};
            ageData.forEach(item => ageDataMap[item.age_group] = parseInt(item.count));
            const ageTotal = ageData.reduce((sum, item) => sum + parseInt(item.count), 0);
            
            const genderTotal = genderData.reduce((sum, item) => sum + parseInt(item.count), 0);
            const maleCount = genderData.find(item => item.gender === 'Male')?.count || 0;
            const femaleCount = genderData.find(item => item.gender === 'Female')?.count || 0;
            const ratio = femaleCount > 0 ? (maleCount / femaleCount).toFixed(2) : 'N/A';
            
            const districtData = <?= json_encode($district_distribution) ?>;
            const barangayData = <?= json_encode($barangay_distribution) ?>;
            const districtTotal = districtData.reduce((sum, item) => sum + parseInt(item.count), 0);
            const barangayTotal = barangayData.reduce((sum, item) => sum + parseInt(item.count), 0);
            
            // Build the complete report HTML
            fullReportContainer.innerHTML = `
                <div class="report-page">
                    <div class="report-title-page">
                        <h1>PATIENT DEMOGRAPHICS REPORT</h1>
                        <h1>FULL DATA EXPORT</h1>
                        <br>
                        <h2>Republic of the Philippines</h2>
                        <h2>City Health Office</h2>
                        <h2>Koronadal City</h2>
                        <br>
                        <p class="generation-info">Generated: ${new Date().toLocaleDateString()} at ${new Date().toLocaleTimeString()}</p>
                        <p class="generation-info">Generated by: <?= htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) ?> (<?= htmlspecialchars($_SESSION['role']) ?>)</p>
                    </div>
                    
                    <div class="report-content">
                        <h1>I. KEY STATISTICS</h1>
                        
                        <div class="stat-section">
                            <h2>Total Active Patients</h2>
                            <div class="definition-box">
                                <p><strong>Definition:</strong> Count of all patients with status = 'active'</p>
                                <p><strong>Usage:</strong> Main denominator for all percentage calculations</p>
                            </div>
                            <div class="count-display">
                                <p class="big-number">${totalPatients.toLocaleString()}</p>
                            </div>
                        </div>
                        
                        <div class="stat-section">
                            <h2>Total PhilHealth Members (Active Patients)</h2>
                            <div class="definition-box">
                                <p><strong>Definition:</strong> Count of all active patients with isPhilHealth = 1</p>
                                <p><strong>Usage:</strong> Used to measure PhilHealth coverage rate among active patients</p>
                            </div>
                            <div class="count-display">
                                <p class="big-number">${philhealthMembers.toLocaleString()} (${philhealthPercentage}%)</p>
                            </div>
                        </div>
                        
                        <div class="stat-section">
                            <h2>Total PWD Patients (Active Patients)</h2>
                            <div class="definition-box">
                                <p><strong>Definition:</strong> Count of all active patients with isPWD = 1</p>
                                <p><strong>Usage:</strong> Indicates proportion of PWDs among active patients</p>
                            </div>
                            <div class="count-display">
                                <p class="big-number">${pwdCount.toLocaleString()} (${pwdPercentage}%)</p>
                            </div>
                        </div>
                        
                        <div class="page-break"></div>
                        
                        <h1>II. GEOGRAPHIC DISTRIBUTION</h1>
                        
                        <div class="geo-section">
                            <h2>District Distribution</h2>
                            <div class="definition-box">
                                <p><strong>Categories:</strong> All districts (via barangay mapping)</p>
                                <p><strong>Metrics:</strong> Patient count and percentage per district</p>
                            </div>
                            <table class="full-report-table">
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>District Name</th>
                                        <th>Patient Count</th>
                                        <th>Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${districtData.map((district, index) => {
                                        const percentage = ((district.count / districtTotal) * 100).toFixed(1);
                                        return `
                                            <tr>
                                                <td>${index + 1}</td>
                                                <td>${district.district_name}</td>
                                                <td>${parseInt(district.count).toLocaleString()}</td>
                                                <td>${percentage}%</td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="geo-section">
                            <h2>Barangay Distribution</h2>
                            <div class="definition-box">
                                <p><strong>Categories:</strong> All barangays in the system</p>
                                <p><strong>Metrics:</strong> Patient count and percentage per barangay</p>
                            </div>
                            <table class="full-report-table">
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>Barangay Name</th>
                                        <th>Patient Count</th>
                                        <th>Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${barangayData.map((barangay, index) => {
                                        const percentage = ((barangay.count / barangayTotal) * 100).toFixed(1);
                                        return `
                                            <tr>
                                                <td>${index + 1}</td>
                                                <td>${barangay.barangay_name}</td>
                                                <td>${parseInt(barangay.count).toLocaleString()}</td>
                                                <td>${percentage}%</td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="page-break"></div>
                        
                        <h1>III. AGE DISTRIBUTION</h1>
                        
                        <div class="age-section">
                            <h2>Age Distribution</h2>
                            <div class="definition-box">
                                <p><strong>Categories:</strong></p>
                                <ul>
                                    <li>Infants (0–1)</li>
                                    <li>Toddlers (1–4)</li>
                                    <li>Children (5–12)</li>
                                    <li>Teens (13–17)</li>
                                    <li>Young Adults (18–35)</li>
                                    <li>Adults (36–59)</li>
                                    <li>Seniors (60+)</li>
                                </ul>
                                <p><strong>Metrics:</strong> Count and percentage per age group</p>
                            </div>
                            <table class="full-report-table">
                                <thead>
                                    <tr>
                                        <th>Age Group</th>
                                        <th>Count</th>
                                        <th>Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${allAgeGroups.map(ageGroup => {
                                        const count = ageDataMap[ageGroup] || 0;
                                        const percentage = ageTotal > 0 ? ((count / ageTotal) * 100).toFixed(1) : '0.0';
                                        return `
                                            <tr>
                                                <td><strong>${ageGroup}</strong></td>
                                                <td>${count.toLocaleString()}</td>
                                                <td>${percentage}%</td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="age-section">
                            <h2>Age Distribution by District</h2>
                            <div class="definition-box">
                                <p><strong>Categories:</strong> Age groups per district</p>
                                <p><strong>Metrics:</strong> Count and percentage per age group within each district</p>
                            </div>
                            ${generateAgeByDistrictTables()}
                        </div>
                        
                        <div class="age-section">
                            <h2>Age Distribution by Barangay</h2>
                            <div class="definition-box">
                                <p><strong>Categories:</strong> Age groups per barangay</p>
                                <p><strong>Metrics:</strong> Count and percentage per age group within each barangay</p>
                            </div>
                            ${generateAgeByBarangayTables()}
                        </div>
                        
                        <div class="page-break"></div>
                        
                        <h1>IV. SEX DISTRIBUTION</h1>
                        
                        <div class="gender-section">
                            <h2>Gender Distribution</h2>
                            <div class="definition-box">
                                <p><strong>Categories:</strong> Male, Female</p>
                                <p><strong>Metrics:</strong> Count, percentage, and gender ratio</p>
                            </div>
                            <table class="full-report-table">
                                <thead>
                                    <tr>
                                        <th>Gender</th>
                                        <th>Count</th>
                                        <th>Percentage</th>
                                        <th>Ratio</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${genderData.map(gender => {
                                        const percentage = ((parseInt(gender.count) / genderTotal) * 100).toFixed(1);
                                        let genderRatio = '';
                                        if (gender.gender === 'Male') {
                                            genderRatio = ratio + ':1 (M:F)';
                                        } else if (gender.gender === 'Female') {
                                            genderRatio = '1:' + ratio + ' (M:F)';
                                        }
                                        return `
                                            <tr>
                                                <td><strong>${gender.gender}</strong></td>
                                                <td>${parseInt(gender.count).toLocaleString()}</td>
                                                <td>${percentage}%</td>
                                                <td>${genderRatio}</td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="gender-section">
                            <h2>Gender Distribution by District</h2>
                            <div class="definition-box">
                                <p><strong>Categories:</strong> Gender per district</p>
                                <p><strong>Metrics:</strong> Count and percentage per gender within each district</p>
                            </div>
                            ${generateGenderByDistrictTables()}
                        </div>
                        
                        <div class="gender-section">
                            <h2>Gender Distribution by Barangay</h2>
                            <div class="definition-box">
                                <p><strong>Categories:</strong> Gender per barangay</p>
                                <p><strong>Metrics:</strong> Count and percentage per gender within each barangay</p>
                            </div>
                            ${generateGenderByBarangayTables()}
                        </div>
                        
                        <div class="page-break"></div>
                        
                        <h1>V. PHILHEALTH MEMBER DISTRIBUTION</h1>
                        
                        <div class="philhealth-section">
                            <h2>PhilHealth Membership</h2>
                            <div class="definition-box">
                                <p><strong>Categories:</strong></p>
                                <ul>
                                    <li>PhilHealth Member (isPhilHealth = 1)</li>
                                    <li>Non-Member (isPhilHealth = 0)</li>
                                </ul>
                                <p><strong>Metrics:</strong> Count and percentage of PhilHealth members</p>
                            </div>
                            <table class="full-report-table">
                                <thead>
                                    <tr>
                                        <th>Membership Status</th>
                                        <th>Count</th>
                                        <th>Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${philhealthOverallData.map(status => {
                                        const total = philhealthOverallData.reduce((sum, item) => sum + parseInt(item.count), 0);
                                        const percentage = ((parseInt(status.count) / total) * 100).toFixed(1);
                                        return `
                                            <tr>
                                                <td><strong>${status.membership_status}</strong></td>
                                                <td>${parseInt(status.count).toLocaleString()}</td>
                                                <td>${percentage}%</td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                        
                        ${philhealthData.length > 0 ? generatePhilhealthTypesSection() : ''}
                        
                        <div class="philhealth-section">
                            <h2>PhilHealth Distribution by District</h2>
                            <div class="definition-box">
                                <p><strong>Categories:</strong> PhilHealth Member / Non-Member per district</p>
                                <p><strong>Metrics:</strong> Count and percentage per membership status within each district</p>
                            </div>
                            ${generatePhilhealthByDistrictTables()}
                        </div>
                        
                        <div class="philhealth-section">
                            <h2>PhilHealth Distribution by Barangay</h2>
                            <div class="definition-box">
                                <p><strong>Categories:</strong> PhilHealth Member / Non-Member per barangay</p>
                                <p><strong>Metrics:</strong> Count and percentage per membership status within each barangay</p>
                            </div>
                            ${generatePhilhealthByBarangayTables()}
                        </div>
                    </div>
                    
                    <div class="report-footer">
                        <p>Patient Demographics Full Report - City Health Office, Koronadal City | Generated: ${new Date().toLocaleDateString()}</p>
                    </div>
                </div>
            `;
            
            document.body.appendChild(fullReportContainer);
        }
        
        function generatePhilhealthTypesSection() {
            return `
                <div class="philhealth-section">
                    <h2>PhilHealth Membership Type Breakdown</h2>
                    <div class="definition-box">
                        <p><strong>Categories:</strong> Membership types (e.g., Indigent, Professional, Senior Citizen, PWD, etc.)</p>
                        <p><strong>Metrics:</strong> Count and percentage per membership type (only for members)</p>
                    </div>
                    <table class="full-report-table">
                        <thead>
                            <tr>
                                <th>PhilHealth Type</th>
                                <th>Count</th>
                                <th>% of Members</th>
                                <th>% of Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${philhealthData.map(type => {
                                const memberTotal = philhealthData.reduce((sum, item) => sum + parseInt(item.count), 0);
                                const overallTotal = philhealthOverallData.reduce((sum, item) => sum + parseInt(item.count), 0);
                                const memberPercentage = ((parseInt(type.count) / memberTotal) * 100).toFixed(1);
                                const totalPercentage = ((parseInt(type.count) / overallTotal) * 100).toFixed(1);
                                return `
                                    <tr>
                                        <td><strong>${type.philhealth_type}</strong></td>
                                        <td>${parseInt(type.count).toLocaleString()}</td>
                                        <td>${memberPercentage}%</td>
                                        <td>${totalPercentage}%</td>
                                    </tr>
                                `;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        }
        
        function generateAgeByDistrictTables() {
            // Generate age distribution tables for each district
            const ageByDistrictGrouped = {};
            ageByDistrict.forEach(item => {
                if (!ageByDistrictGrouped[item.district_name]) {
                    ageByDistrictGrouped[item.district_name] = {};
                }
                ageByDistrictGrouped[item.district_name][item.age_group] = parseInt(item.count);
            });
            
            const allAgeGroups = [
                'Infants (0-1)', 'Toddlers (1-4)', 'Children (5-12)', 'Teens (13-17)',
                'Young Adults (18-35)', 'Adults (36-59)', 'Seniors (60+)'
            ];
            
            let tablesHTML = '';
            Object.keys(ageByDistrictGrouped).sort().forEach(district => {
                const districtAgeData = ageByDistrictGrouped[district];
                const districtAgeTotal = Object.values(districtAgeData).reduce((sum, count) => sum + count, 0);
                
                tablesHTML += `
                    <div class="district-table">
                        <h3>${district} District (Total: ${districtAgeTotal.toLocaleString()} patients)</h3>
                        <table class="breakdown-table">
                            <thead>
                                <tr>
                                    <th>Age Group</th>
                                    <th>Count</th>
                                    <th>% of District</th>
                                    <th>% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${allAgeGroups.map(ageGroup => {
                                    const count = districtAgeData[ageGroup] || 0;
                                    const districtPercentage = districtAgeTotal > 0 ? ((count / districtAgeTotal) * 100).toFixed(1) : '0.0';
                                    const totalPercentage = totalPatients > 0 ? ((count / totalPatients) * 100).toFixed(1) : '0.0';
                                    return `
                                        <tr>
                                            <td>${ageGroup}</td>
                                            <td>${count.toLocaleString()}</td>
                                            <td>${districtPercentage}%</td>
                                            <td>${totalPercentage}%</td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            });
            
            return tablesHTML;
        }
        
        function generateAgeByBarangayTables() {
            // Generate age distribution tables for each barangay (top 10 to keep manageable)
            const ageByBarangayGrouped = {};
            ageByBarangay.forEach(item => {
                if (!ageByBarangayGrouped[item.barangay_name]) {
                    ageByBarangayGrouped[item.barangay_name] = {};
                }
                ageByBarangayGrouped[item.barangay_name][item.age_group] = parseInt(item.count);
            });
            
            const allAgeGroups = [
                'Infants (0-1)', 'Toddlers (1-4)', 'Children (5-12)', 'Teens (13-17)',
                'Young Adults (18-35)', 'Adults (36-59)', 'Seniors (60+)'
            ];
            
            // Get top 10 barangays by patient count
            const barangayTotals = Object.keys(ageByBarangayGrouped).map(barangay => ({
                name: barangay,
                total: Object.values(ageByBarangayGrouped[barangay]).reduce((sum, count) => sum + count, 0)
            })).sort((a, b) => b.total - a.total).slice(0, 10);
            
            let tablesHTML = '';
            barangayTotals.forEach(({name: barangay, total}) => {
                const barangayAgeData = ageByBarangayGrouped[barangay];
                
                tablesHTML += `
                    <div class="barangay-table">
                        <h3>Barangay ${barangay} (Total: ${total.toLocaleString()} patients)</h3>
                        <table class="breakdown-table">
                            <thead>
                                <tr>
                                    <th>Age Group</th>
                                    <th>Count</th>
                                    <th>% of Barangay</th>
                                    <th>% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${allAgeGroups.map(ageGroup => {
                                    const count = barangayAgeData[ageGroup] || 0;
                                    const barangayPercentage = total > 0 ? ((count / total) * 100).toFixed(1) : '0.0';
                                    const totalPercentage = totalPatients > 0 ? ((count / totalPatients) * 100).toFixed(1) : '0.0';
                                    return `
                                        <tr>
                                            <td>${ageGroup}</td>
                                            <td>${count.toLocaleString()}</td>
                                            <td>${barangayPercentage}%</td>
                                            <td>${totalPercentage}%</td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            });
            
            return tablesHTML;
        }
        
        function generateGenderByDistrictTables() {
            // Generate gender distribution tables for each district
            const genderByDistrictGrouped = {};
            genderByDistrict.forEach(item => {
                if (!genderByDistrictGrouped[item.district_name]) {
                    genderByDistrictGrouped[item.district_name] = {};
                }
                genderByDistrictGrouped[item.district_name][item.gender] = parseInt(item.count);
            });
            
            let tablesHTML = '';
            Object.keys(genderByDistrictGrouped).sort().forEach(district => {
                const districtGenderData = genderByDistrictGrouped[district];
                const districtGenderTotal = Object.values(districtGenderData).reduce((sum, count) => sum + count, 0);
                
                tablesHTML += `
                    <div class="district-table">
                        <h3>${district} District (Total: ${districtGenderTotal.toLocaleString()} patients)</h3>
                        <table class="breakdown-table">
                            <thead>
                                <tr>
                                    <th>Gender</th>
                                    <th>Count</th>
                                    <th>% of District</th>
                                    <th>% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${['Male', 'Female'].map(gender => {
                                    const count = districtGenderData[gender] || 0;
                                    const districtPercentage = districtGenderTotal > 0 ? ((count / districtGenderTotal) * 100).toFixed(1) : '0.0';
                                    const totalPercentage = totalPatients > 0 ? ((count / totalPatients) * 100).toFixed(1) : '0.0';
                                    return `
                                        <tr>
                                            <td><strong>${gender}</strong></td>
                                            <td>${count.toLocaleString()}</td>
                                            <td>${districtPercentage}%</td>
                                            <td>${totalPercentage}%</td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            });
            
            return tablesHTML;
        }
        
        function generateGenderByBarangayTables() {
            // Generate gender distribution tables for top 10 barangays
            const genderByBarangayGrouped = {};
            genderByBarangay.forEach(item => {
                if (!genderByBarangayGrouped[item.barangay_name]) {
                    genderByBarangayGrouped[item.barangay_name] = {};
                }
                genderByBarangayGrouped[item.barangay_name][item.gender] = parseInt(item.count);
            });
            
            // Get top 10 barangays by patient count
            const barangayTotals = Object.keys(genderByBarangayGrouped).map(barangay => ({
                name: barangay,
                total: Object.values(genderByBarangayGrouped[barangay]).reduce((sum, count) => sum + count, 0)
            })).sort((a, b) => b.total - a.total).slice(0, 10);
            
            let tablesHTML = '';
            barangayTotals.forEach(({name: barangay, total}) => {
                const barangayGenderData = genderByBarangayGrouped[barangay];
                
                tablesHTML += `
                    <div class="barangay-table">
                        <h3>Barangay ${barangay} (Total: ${total.toLocaleString()} patients)</h3>
                        <table class="breakdown-table">
                            <thead>
                                <tr>
                                    <th>Gender</th>
                                    <th>Count</th>
                                    <th>% of Barangay</th>
                                    <th>% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${['Male', 'Female'].map(gender => {
                                    const count = barangayGenderData[gender] || 0;
                                    const barangayPercentage = total > 0 ? ((count / total) * 100).toFixed(1) : '0.0';
                                    const totalPercentage = totalPatients > 0 ? ((count / totalPatients) * 100).toFixed(1) : '0.0';
                                    return `
                                        <tr>
                                            <td><strong>${gender}</strong></td>
                                            <td>${count.toLocaleString()}</td>
                                            <td>${barangayPercentage}%</td>
                                            <td>${totalPercentage}%</td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            });
            
            return tablesHTML;
        }
        
        function generatePhilhealthByDistrictTables() {
            // Generate PhilHealth distribution tables for each district
            const philhealthByDistrictGrouped = {};
            philhealthByDistrict.forEach(item => {
                if (!philhealthByDistrictGrouped[item.district_name]) {
                    philhealthByDistrictGrouped[item.district_name] = {};
                }
                philhealthByDistrictGrouped[item.district_name][item.philhealth_type] = parseInt(item.count);
            });
            
            let tablesHTML = '';
            Object.keys(philhealthByDistrictGrouped).sort().forEach(district => {
                const districtPhilhealthData = philhealthByDistrictGrouped[district];
                const districtPhilhealthTotal = Object.values(districtPhilhealthData).reduce((sum, count) => sum + count, 0);
                
                tablesHTML += `
                    <div class="district-table">
                        <h3>${district} District (Total: ${districtPhilhealthTotal.toLocaleString()} patients)</h3>
                        <table class="breakdown-table">
                            <thead>
                                <tr>
                                    <th>PhilHealth Status</th>
                                    <th>Count</th>
                                    <th>% of District</th>
                                    <th>% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${['PhilHealth Member', 'Non-Member'].map(status => {
                                    const count = districtPhilhealthData[status] || 0;
                                    const districtPercentage = districtPhilhealthTotal > 0 ? ((count / districtPhilhealthTotal) * 100).toFixed(1) : '0.0';
                                    const totalPercentage = totalPatients > 0 ? ((count / totalPatients) * 100).toFixed(1) : '0.0';
                                    return `
                                        <tr>
                                            <td><strong>${status}</strong></td>
                                            <td>${count.toLocaleString()}</td>
                                            <td>${districtPercentage}%</td>
                                            <td>${totalPercentage}%</td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            });
            
            return tablesHTML;
        }
        
        function generatePhilhealthByBarangayTables() {
            // Generate PhilHealth distribution tables for top 10 barangays
            const philhealthByBarangayGrouped = {};
            philhealthByBarangay.forEach(item => {
                if (!philhealthByBarangayGrouped[item.barangay_name]) {
                    philhealthByBarangayGrouped[item.barangay_name] = {};
                }
                philhealthByBarangayGrouped[item.barangay_name][item.philhealth_type] = parseInt(item.count);
            });
            
            // Get top 10 barangays by patient count
            const barangayTotals = Object.keys(philhealthByBarangayGrouped).map(barangay => ({
                name: barangay,
                total: Object.values(philhealthByBarangayGrouped[barangay]).reduce((sum, count) => sum + count, 0)
            })).sort((a, b) => b.total - a.total).slice(0, 10);
            
            let tablesHTML = '';
            barangayTotals.forEach(({name: barangay, total}) => {
                const barangayPhilhealthData = philhealthByBarangayGrouped[barangay];
                
                tablesHTML += `
                    <div class="barangay-table">
                        <h3>Barangay ${barangay} (Total: ${total.toLocaleString()} patients)</h3>
                        <table class="breakdown-table">
                            <thead>
                                <tr>
                                    <th>PhilHealth Status</th>
                                    <th>Count</th>
                                    <th>% of Barangay</th>
                                    <th>% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${['PhilHealth Member', 'Non-Member'].map(status => {
                                    const count = barangayPhilhealthData[status] || 0;
                                    const barangayPercentage = total > 0 ? ((count / total) * 100).toFixed(1) : '0.0';
                                    const totalPercentage = totalPatients > 0 ? ((count / totalPatients) * 100).toFixed(1) : '0.0';
                                    return `
                                        <tr>
                                            <td><strong>${status}</strong></td>
                                            <td>${count.toLocaleString()}</td>
                                            <td>${barangayPercentage}%</td>
                                            <td>${totalPercentage}%</td>
                                        </tr>
                                    `;
                                }).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            });
            
            return tablesHTML;
        }
        
        function restoreNormalView() {
            // Remove the full report and restore normal page
            const fullReport = document.getElementById('completeFullReport');
            if (fullReport) {
                fullReport.remove();
            }
            document.querySelector('.homepage').style.display = '';
        }

        function hideFullReportContent() {
            const reportContent = document.getElementById('fullReportContent');
            if (reportContent) {
                reportContent.remove();
            }
            
            // Restore normal content visibility
            document.querySelectorAll('body > *').forEach(el => {
                if (el.id !== 'fullReportContent') {
                    el.style.display = '';
                }
            });
        }

        // Modal functionality
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.style.display = 'block';

            // Populate modal content based on modal type
            switch (modalId) {
                case 'ageModal':
                    populateAgeModal();
                    break;
                case 'genderModal':
                    populateGenderModal();
                    break;
                case 'philhealthModal':
                    populatePhilhealthModal();
                    break;
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Tab switching functionality
        function switchTab(modalType, tabName) {
            // Hide all tab contents for this modal
            const tabContents = document.querySelectorAll(`#${modalType}Modal .modal-tab-content`);
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Remove active class from all tabs
            const tabs = document.querySelectorAll(`#${modalType}Modal .modal-tab`);
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Show selected tab content
            document.getElementById(`${modalType}-${tabName}`).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
            
            // Load breakdown data if switching to district or barangay tabs
            if (tabName === 'district') {
                populateDistrictBreakdown(modalType);
            } else if (tabName === 'barangay') {
                populateBarangayBreakdown(modalType);
            }
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // Populate Age Modal
        function populateAgeModal() {
            // Create larger chart
            const ctx = document.getElementById('ageModalChart').getContext('2d');

            // Destroy existing chart if it exists
            if (window.ageModalChartInstance) {
                window.ageModalChartInstance.destroy();
            }

            window.ageModalChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ageData.map(item => item.age_group),
                    datasets: [{
                        label: 'Patient Count',
                        data: ageData.map(item => parseInt(item.count)),
                        backgroundColor: chartColors.colors,
                        borderColor: chartColors.colors.map(color => color + 'CC'),
                        borderWidth: 2,
                        borderRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.raw / total) * 100).toFixed(1);
                                    return 'Count: ' + context.raw + ' (' + percentage + '%)';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: '#e9ecef'
                            },
                            ticks: {
                                color: '#6c757d'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#6c757d',
                                maxRotation: 45
                            }
                        }
                    }
                }
            });

            // Populate table with all age groups (even those with 0 count)
            const tableBody = document.getElementById('ageModalTableBody');
            const total = ageData.reduce((sum, item) => sum + parseInt(item.count), 0);
            
            // Define all possible age groups
            const allAgeGroups = [
                'Infants (0-1)',
                'Toddlers (1-4)',
                'Children (5-12)',
                'Teens (13-17)',
                'Young Adults (18-35)',
                'Adults (36-59)',
                'Seniors (60+)'
            ];
            
            // Create a map of existing data for quick lookup
            const ageDataMap = {};
            ageData.forEach(item => {
                ageDataMap[item.age_group] = parseInt(item.count);
            });

            tableBody.innerHTML = allAgeGroups.map(ageGroup => {
                const count = ageDataMap[ageGroup] || 0;
                const percentage = total > 0 ? ((count / total) * 100).toFixed(1) : '0.0';
                const rowStyle = count === 0 ? 'style="opacity: 0.6;"' : '';

                return `
                    <tr ${rowStyle}>
                        <td><strong>${ageGroup}</strong></td>
                        <td>${count.toLocaleString()}</td>
                        <td>${percentage}%</td>
                        <td>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: ${percentage}%"></div>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // Populate Gender Modal
        function populateGenderModal() {
            // Create larger chart
            const ctx = document.getElementById('genderModalChart').getContext('2d');

            // Destroy existing chart if it exists
            if (window.genderModalChartInstance) {
                window.genderModalChartInstance.destroy();
            }

            window.genderModalChartInstance = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: genderData.map(item => item.gender),
                    datasets: [{
                        data: genderData.map(item => parseInt(item.count)),
                        backgroundColor: [chartColors.primary, chartColors.secondary, chartColors.accent],
                        borderWidth: 3,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                font: {
                                    size: 14
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.raw / total) * 100).toFixed(1);
                                    return context.label + ': ' + context.raw + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });

            // Populate table
            const tableBody = document.getElementById('genderModalTableBody');
            const total = genderData.reduce((sum, item) => sum + parseInt(item.count), 0);

            tableBody.innerHTML = genderData.map(item => {
                const percentage = ((parseInt(item.count) / total) * 100).toFixed(1);
                const ratio = calculateGenderRatio(item, genderData, total);

                return `
                    <tr>
                        <td><strong>${item.gender}</strong></td>
                        <td>${parseInt(item.count).toLocaleString()}</td>
                        <td>${percentage}%</td>
                        <td>${ratio}</td>
                    </tr>
                `;
            }).join('');
        }

        // Populate PhilHealth Modal
        function populatePhilhealthModal() {
            // Check if we have PhilHealth data
            if (philhealthData.length === 0) {
                // Show message when no data is available
                const ctx = document.getElementById('philhealthModalChart').getContext('2d');
                ctx.fillStyle = '#6c757d';
                ctx.font = '16px Arial';
                ctx.textAlign = 'center';
                ctx.fillText('No PhilHealth type data available', ctx.canvas.width / 2, ctx.canvas.height / 2);

                // Clear tables
                document.getElementById('philhealthOverallTableBody').innerHTML = '<tr><td colspan="3">No data available</td></tr>';
                document.getElementById('philhealthModalTableBody').innerHTML = '<tr><td colspan="4">No PhilHealth type data available</td></tr>';
                return;
            }

            // Create larger chart for PhilHealth types
            const ctx = document.getElementById('philhealthModalChart').getContext('2d');

            // Destroy existing chart if it exists
            if (window.philhealthModalChartInstance) {
                window.philhealthModalChartInstance.destroy();
            }

            window.philhealthModalChartInstance = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: philhealthData.map(item => item.philhealth_type),
                    datasets: [{
                        data: philhealthData.map(item => parseInt(item.count)),
                        backgroundColor: chartColors.colors.slice(0, philhealthData.length),
                        borderWidth: 3,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true,
                                font: {
                                    size: 14
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((context.raw / total) * 100).toFixed(1);
                                    return context.label + ': ' + context.raw + ' (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });

            // Populate overall membership table
            const overallTableBody = document.getElementById('philhealthOverallTableBody');
            const totalOverall = philhealthOverallData.reduce((sum, item) => sum + parseInt(item.count), 0);

            overallTableBody.innerHTML = philhealthOverallData.map(item => {
                const percentage = totalOverall > 0 ? ((parseInt(item.count) / totalOverall) * 100).toFixed(1) : '0.0';

                return `
                    <tr>
                        <td><strong>${item.membership_status}</strong></td>
                        <td>${parseInt(item.count).toLocaleString()}</td>
                        <td>${percentage}%</td>
                    </tr>
                `;
            }).join('');

            // Populate PhilHealth types table
            const tableBody = document.getElementById('philhealthModalTableBody');
            const totalMembers = philhealthData.reduce((sum, item) => sum + parseInt(item.count), 0);
            const totalPatients = philhealthOverallData.reduce((sum, item) => sum + parseInt(item.count), 0);

            tableBody.innerHTML = philhealthData.map(item => {
                const percentageOfMembers = totalMembers > 0 ? ((parseInt(item.count) / totalMembers) * 100).toFixed(1) : '0.0';
                const percentageOfTotal = totalPatients > 0 ? ((parseInt(item.count) / totalPatients) * 100).toFixed(1) : '0.0';

                return `
                    <tr>
                        <td><strong>${item.philhealth_type}</strong></td>
                        <td>${parseInt(item.count).toLocaleString()}</td>
                        <td>${percentageOfMembers}%</td>
                        <td>${percentageOfTotal}%</td>
                    </tr>
                `;
            }).join('');
        }

        // Helper functions
        function getAgeRangeDescription(ageGroup) {
            const descriptions = {
                'Infants (0-1)': 'Newborns to 1 year old',
                'Toddlers (1-4)': '1 to 4 years old',
                'Children (5-12)': '5 to 12 years old',
                'Teens (13-17)': '13 to 17 years old',
                'Young Adults (18-35)': '18 to 35 years old',
                'Adults (36-59)': '36 to 59 years old',
                'Seniors (60+)': '60 years and above'
            };
            return descriptions[ageGroup] || 'Age range not specified';
        }

        function calculateGenderRatio(currentItem, allData, total) {
            if (currentItem.gender === 'Male') {
                const femaleData = allData.find(item => item.gender === 'Female');
                if (femaleData) {
                    const ratio = (parseInt(currentItem.count) / parseInt(femaleData.count)).toFixed(2);
                    return `${ratio}:1 (M:F)`;
                }
            } else if (currentItem.gender === 'Female') {
                const maleData = allData.find(item => item.gender === 'Male');
                if (maleData) {
                    const ratio = (parseInt(currentItem.count) / parseInt(maleData.count)).toFixed(2);
                    return `1:${ratio} (M:F)`;
                }
            }
            return `${((parseInt(currentItem.count) / total) * 100).toFixed(1)}% of total`;
        }

        // District breakdown population functions
        function populateDistrictBreakdown(modalType) {
            let breakdownData, containerId, groupField, locationField;
            
            switch(modalType) {
                case 'age':
                    breakdownData = ageByDistrict;
                    containerId = 'ageDistrictBreakdown';
                    groupField = 'age_group';
                    locationField = 'district_name';
                    break;
                case 'gender':
                    breakdownData = genderByDistrict;
                    containerId = 'genderDistrictBreakdown';
                    groupField = 'gender';
                    locationField = 'district_name';
                    break;
                case 'philhealth':
                    breakdownData = philhealthByDistrict;
                    containerId = 'philhealthDistrictBreakdown';
                    groupField = 'philhealth_type';
                    locationField = 'district_name';
                    break;
            }
            
            if (!breakdownData || breakdownData.length === 0) {
                document.getElementById(containerId).innerHTML = '<p style="text-align: center; color: #666; font-style: italic;">No data available for district breakdown.</p>';
                return;
            }
            
            generateBreakdownTables(breakdownData, containerId, groupField, locationField, 'District');
        }

        function populateBarangayBreakdown(modalType) {
            let breakdownData, containerId, groupField, locationField;
            
            switch(modalType) {
                case 'age':
                    breakdownData = ageByBarangay;
                    containerId = 'ageBarangayBreakdown';
                    groupField = 'age_group';
                    locationField = 'barangay_name';
                    break;
                case 'gender':
                    breakdownData = genderByBarangay;
                    containerId = 'genderBarangayBreakdown';
                    groupField = 'gender';
                    locationField = 'barangay_name';
                    break;
                case 'philhealth':
                    breakdownData = philhealthByBarangay;
                    containerId = 'philhealthBarangayBreakdown';
                    groupField = 'philhealth_type';
                    locationField = 'barangay_name';
                    break;
            }
            
            if (!breakdownData || breakdownData.length === 0) {
                document.getElementById(containerId).innerHTML = '<p style="text-align: center; color: #666; font-style: italic;">No data available for barangay breakdown.</p>';
                return;
            }
            
            generateBreakdownTables(breakdownData, containerId, groupField, locationField, 'Barangay');
        }

        function generateBreakdownTables(data, containerId, groupField, locationField, locationType) {
            // Group data by location
            const groupedData = {};
            const locationTotals = {};
            
            data.forEach(item => {
                const location = item[locationField];
                const group = item[groupField];
                const count = parseInt(item.count);
                
                if (!groupedData[location]) {
                    groupedData[location] = {};
                    locationTotals[location] = 0;
                }
                
                groupedData[location][group] = count;
                locationTotals[location] += count;
            });
            
            // Define all possible groups for each category
            let allGroups = [];
            if (groupField === 'age_group') {
                allGroups = [
                    'Infants (0-1)',
                    'Toddlers (1-4)',
                    'Children (5-12)',
                    'Teens (13-17)',
                    'Young Adults (18-35)',
                    'Adults (36-59)',
                    'Seniors (60+)'
                ];
            } else if (groupField === 'gender') {
                allGroups = ['Male', 'Female'];
            } else if (groupField === 'philhealth_type') {
                // For district/barangay PhilHealth breakdown, only show membership status
                allGroups = ['PhilHealth Member', 'Non-Member'];
            }
            
            // Get all locations (districts/barangays) that have any patients
            // If no data exists for breakdown, get locations from main distribution
            let allLocations = Object.keys(groupedData);
            if (allLocations.length === 0) {
                // No breakdown data available, show message
                document.getElementById(containerId).innerHTML = '<p style="text-align: center; color: #666; font-style: italic;">No data available for ' + locationType.toLowerCase() + ' breakdown.</p>';
                return;
            }
            
            let html = '';
            
            allLocations.sort().forEach(location => {
                const locationData = groupedData[location] || {};
                const locationTotal = locationTotals[location] || 0;
                
                html += `
                    <div class="breakdown-table">
                        <h5>${location} (Total: ${locationTotal.toLocaleString()} patients)</h5>
                        <table class="breakdown-data-table">
                            <thead>
                                <tr>
                                    <th>${groupField === 'age_group' ? 'Age Group' : groupField === 'gender' ? 'Gender' : 'PhilHealth Type'}</th>
                                    <th>Count</th>
                                    <th>% of ${location}</th>
                                    <th>% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                // Get total patients for percentage calculation
                const totalPatients = <?= $total_patients ?>;
                
                // Show ALL groups, even if count is 0
                allGroups.forEach(group => {
                    const count = locationData[group] || 0;
                    const locationPercentage = locationTotal > 0 ? ((count / locationTotal) * 100).toFixed(1) : '0.0';
                    const totalPercentage = totalPatients > 0 ? ((count / totalPatients) * 100).toFixed(1) : '0.0';
                    
                    let groupClass = '';
                    let rowClass = '';
                    if (groupField === 'age_group') {
                        groupClass = `age-group-${group.toLowerCase().replace(/[^a-z]/g, '-')}`;
                    }
                    
                    // Add a subtle styling for zero counts
                    if (count === 0) {
                        rowClass = 'style="opacity: 0.6;"';
                    }
                    
                    html += `
                        <tr ${rowClass}>
                            <td class="age-group-cell ${groupClass}">${group}</td>
                            <td>${count.toLocaleString()}</td>
                            <td>${locationPercentage}%</td>
                            <td>${totalPercentage}%</td>
                        </tr>
                    `;
                });
                
                html += `
                            </tbody>
                        </table>
                    </div>
                `;
            });
            
            document.getElementById(containerId).innerHTML = html;
        }
    </script>
</body>

</html>