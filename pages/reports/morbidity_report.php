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
$start_date = $_GET['start_date'] ?? '2025-10-01'; // Set to start of October where data exists
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Today
$district_filter = $_GET['district_filter'] ?? '';
$barangay_filter = $_GET['barangay_filter'] ?? '';
$age_group_filter = $_GET['age_group_filter'] ?? '';
$sex_filter = $_GET['sex_filter'] ?? '';

try {
    // Get filter options for dropdowns
    $districts_stmt = $pdo->query("SELECT district_id, district_name FROM districts WHERE status = 'active' ORDER BY district_name");
    $districts = $districts_stmt->fetchAll(PDO::FETCH_ASSOC);

    $barangays_stmt = $pdo->query("SELECT barangay_id, barangay_name, district_id FROM barangay WHERE status = 'active' ORDER BY barangay_name");
    $barangays = $barangays_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build where clauses for filtering
    $where_conditions = ["c.consultation_date BETWEEN :start_date AND :end_date"];
    $params = [
        'start_date' => $start_date,
        'end_date' => $end_date . ' 23:59:59'
    ];

    if (!empty($district_filter)) {
        $where_conditions[] = "d.district_id = :district_filter";
        $params['district_filter'] = $district_filter;
    }

    if (!empty($barangay_filter)) {
        $where_conditions[] = "p.barangay_id = :barangay_filter";
        $params['barangay_filter'] = $barangay_filter;
    }

    if (!empty($sex_filter)) {
        $where_conditions[] = "p.sex = :sex_filter";
        $params['sex_filter'] = $sex_filter;
    }

    // Age group filtering
    if (!empty($age_group_filter)) {
        switch ($age_group_filter) {
            case '0-5':
                $where_conditions[] = "TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 0 AND 5";
                break;
            case '6-12':
                $where_conditions[] = "TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 6 AND 12";
                break;
            case '13-19':
                $where_conditions[] = "TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 19";
                break;
            case '20-39':
                $where_conditions[] = "TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 20 AND 39";
                break;
            case '40-59':
                $where_conditions[] = "TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 40 AND 59";
                break;
            case '60+':
                $where_conditions[] = "TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) >= 60";
                break;
        }
    }

    $where_clause = implode(' AND ', $where_conditions);

    // 1. DISEASE PREVALENCE - Top diagnoses
    $disease_prevalence_query = "
        SELECT 
            c.diagnosis,
            COUNT(*) as case_count,
            COUNT(DISTINCT p.patient_id) as patient_count,
            ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM consultations c2 
                JOIN patients p2 ON c2.patient_id = p2.patient_id 
                JOIN barangay b2 ON p2.barangay_id = b2.barangay_id 
                JOIN districts d2 ON b2.district_id = d2.district_id 
                WHERE c2.consultation_date BETWEEN :start_date_sub AND :end_date_sub)), 2) as prevalence_rate
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        JOIN barangay b ON p.barangay_id = b.barangay_id
        JOIN districts d ON b.district_id = d.district_id
        WHERE $where_clause 
            AND c.diagnosis IS NOT NULL 
            AND c.diagnosis != '' 
            AND c.diagnosis NOT LIKE '%test%'
        GROUP BY c.diagnosis
        ORDER BY case_count DESC
        LIMIT 20
    ";
    
    // Add parameters for subquery
    $disease_params = $params;
    $disease_params['start_date_sub'] = $start_date;
    $disease_params['end_date_sub'] = $end_date . ' 23:59:59';
    
    $disease_prevalence_stmt = $pdo->prepare($disease_prevalence_query);
    $disease_prevalence_stmt->execute($disease_params);
    $disease_prevalence = $disease_prevalence_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. CHRONIC ILLNESSES DISTRIBUTION
    $chronic_illnesses_query = "
        SELECT 
            ci.illness,
            COUNT(*) as patient_count,
            AVG(YEAR(CURDATE()) - ci.year_diagnosed) as avg_years_diagnosed,
            ROUND((COUNT(*) * 100.0 / (SELECT COUNT(DISTINCT patient_id) FROM chronic_illnesses WHERE illness NOT LIKE '%Not Applicable%')), 2) as prevalence_rate
        FROM chronic_illnesses ci
        JOIN patients p ON ci.patient_id = p.patient_id
        JOIN barangay b ON p.barangay_id = b.barangay_id
        JOIN districts d ON b.district_id = d.district_id
        WHERE ci.illness NOT LIKE '%Not Applicable%'
            AND ci.illness IS NOT NULL
    ";
    
    $chronic_conditions = [];
    if (!empty($district_filter)) {
        $chronic_illnesses_query .= " AND d.district_id = :district_filter";
        $chronic_conditions['district_filter'] = $district_filter;
    }
    if (!empty($barangay_filter)) {
        $chronic_illnesses_query .= " AND p.barangay_id = :barangay_filter";
        $chronic_conditions['barangay_filter'] = $barangay_filter;
    }
    if (!empty($sex_filter)) {
        $chronic_illnesses_query .= " AND p.sex = :sex_filter";
        $chronic_conditions['sex_filter'] = $sex_filter;
    }
    
    $chronic_illnesses_query .= " GROUP BY ci.illness ORDER BY patient_count DESC LIMIT 15";
    
    $chronic_illnesses_stmt = $pdo->prepare($chronic_illnesses_query);
    $chronic_illnesses_stmt->execute($chronic_conditions);
    $chronic_illnesses = $chronic_illnesses_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. AGE GROUP DISTRIBUTION OF DISEASES
    $age_group_query = "
        SELECT 
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 0 AND 5 THEN '0-5 years'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 6 AND 12 THEN '6-12 years'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 19 THEN '13-19 years'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 20 AND 39 THEN '20-39 years'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 40 AND 59 THEN '40-59 years'
                ELSE '60+ years'
            END as age_group,
            c.diagnosis,
            COUNT(*) as case_count
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        JOIN barangay b ON p.barangay_id = b.barangay_id
        JOIN districts d ON b.district_id = d.district_id
        WHERE $where_clause 
            AND c.diagnosis IS NOT NULL 
            AND c.diagnosis != ''
        GROUP BY age_group, c.diagnosis
        HAVING case_count >= 2
        ORDER BY age_group, case_count DESC
    ";
    
    $age_group_stmt = $pdo->prepare($age_group_query);
    $age_group_stmt->execute($params);
    $age_group_diseases = $age_group_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. GEOGRAPHIC DISTRIBUTION (BY DISTRICT)
    $district_distribution_query = "
        SELECT 
            d.district_name,
            c.diagnosis,
            COUNT(*) as case_count,
            COUNT(DISTINCT p.patient_id) as patient_count
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        JOIN barangay b ON p.barangay_id = b.barangay_id
        JOIN districts d ON b.district_id = d.district_id
        WHERE $where_clause 
            AND c.diagnosis IS NOT NULL 
            AND c.diagnosis != ''
        GROUP BY d.district_name, c.diagnosis
        HAVING case_count >= 2
        ORDER BY d.district_name, case_count DESC
    ";
    
    $district_distribution_stmt = $pdo->prepare($district_distribution_query);
    $district_distribution_stmt->execute($params);
    $district_distribution = $district_distribution_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. BARANGAY DISTRIBUTION (TOP BARANGAYS)
    $barangay_distribution_query = "
        SELECT 
            b.barangay_name,
            d.district_name,
            COUNT(*) as total_cases,
            COUNT(DISTINCT c.diagnosis) as unique_diseases,
            COUNT(DISTINCT p.patient_id) as unique_patients
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        JOIN barangay b ON p.barangay_id = b.barangay_id
        JOIN districts d ON b.district_id = d.district_id
        WHERE $where_clause 
            AND c.diagnosis IS NOT NULL 
            AND c.diagnosis != ''
        GROUP BY b.barangay_name, d.district_name
        ORDER BY total_cases DESC
        LIMIT 15
    ";
    
    $barangay_distribution_stmt = $pdo->prepare($barangay_distribution_query);
    $barangay_distribution_stmt->execute($params);
    $barangay_distribution = $barangay_distribution_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. GENDER DISTRIBUTION
    $gender_distribution_query = "
        SELECT 
            p.sex,
            c.diagnosis,
            COUNT(*) as case_count,
            COUNT(DISTINCT p.patient_id) as patient_count
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        JOIN barangay b ON p.barangay_id = b.barangay_id
        JOIN districts d ON b.district_id = d.district_id
        WHERE $where_clause 
            AND c.diagnosis IS NOT NULL 
            AND c.diagnosis != ''
        GROUP BY p.sex, c.diagnosis
        HAVING case_count >= 2
        ORDER BY p.sex, case_count DESC
    ";
    
    $gender_distribution_stmt = $pdo->prepare($gender_distribution_query);
    $gender_distribution_stmt->execute($params);
    $gender_distribution = $gender_distribution_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. SEASONAL TRENDS (BY MONTH)
    $seasonal_trends_query = "
        SELECT 
            MONTH(c.consultation_date) as month_num,
            MONTHNAME(c.consultation_date) as month_name,
            c.diagnosis,
            COUNT(*) as case_count
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        JOIN barangay b ON p.barangay_id = b.barangay_id
        JOIN districts d ON b.district_id = d.district_id
        WHERE $where_clause 
            AND c.diagnosis IS NOT NULL 
            AND c.diagnosis != ''
        GROUP BY month_num, month_name, c.diagnosis
        HAVING case_count >= 2
        ORDER BY month_num, case_count DESC
    ";
    
    $seasonal_trends_stmt = $pdo->prepare($seasonal_trends_query);
    $seasonal_trends_stmt->execute($params);
    $seasonal_trends = $seasonal_trends_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 8. SUMMARY STATISTICS
    $summary_query = "
        SELECT 
            COUNT(DISTINCT c.consultation_id) as total_consultations,
            COUNT(DISTINCT p.patient_id) as total_patients,
            COUNT(DISTINCT c.diagnosis) as unique_diagnoses,
            COUNT(DISTINCT b.barangay_id) as affected_barangays
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        JOIN barangay b ON p.barangay_id = b.barangay_id
        JOIN districts d ON b.district_id = d.district_id
        WHERE $where_clause 
            AND c.diagnosis IS NOT NULL 
            AND c.diagnosis != ''
    ";
    
    $summary_stmt = $pdo->prepare($summary_query);
    $summary_stmt->execute($params);
    $summary_stats = $summary_stmt->fetch(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Morbidity Report Error: " . $e->getMessage());
    $error = "Error generating morbidity report. Please try again.";
    
    // Initialize empty arrays to prevent errors in the display
    $disease_prevalence = [];
    $chronic_illnesses = [];
    $age_group_diseases = [];
    $district_distribution = [];
    $barangay_distribution = [];
    $gender_distribution = [];
    $seasonal_trends = [];
    $summary_stats = ['total_consultations' => 0, 'total_patients' => 0, 'unique_diagnoses' => 0, 'affected_barangays' => 0];
    $districts = [];
    $barangays = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Morbidity Report - CHO Koronadal</title>

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

        .morbidity-report-content {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
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

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: 5px;
        }

        .filter-group select,
        .filter-group input {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            background: white;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
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

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Full width sections */
        .full-width-section {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .disease-rank {
            display: inline-block;
            background: var(--primary-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            margin-right: 8px;
        }

        .age-group-tag {
            display: inline-block;
            background: var(--accent-color);
            color: var(--primary-dark);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            margin-right: 5px;
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .homepage {
                margin-left: 0;
            }
            
            .analytics-grid {
                grid-template-columns: 1fr;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .summary-cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .tab-buttons {
                flex-wrap: wrap;
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
                <span> Morbidity Report</span>
            </div>

            <div class="page-header">
                <h1><i class="fas fa-heartbeat"></i> Morbidity Report</h1>
                <p>Comprehensive analysis of disease patterns, health conditions, and epidemiological trends</p>
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
                            <label for="district_filter">District</label>
                            <select id="district_filter" name="district_filter">
                                <option value="">All Districts</option>
                                <?php foreach ($districts as $district): ?>
                                    <option value="<?php echo $district['district_id']; ?>" 
                                        <?php echo $district_filter == $district['district_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($district['district_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="barangay_filter">Barangay</label>
                            <select id="barangay_filter" name="barangay_filter">
                                <option value="">All Barangays</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo $barangay['barangay_id']; ?>" 
                                        data-district="<?php echo $barangay['district_id']; ?>"
                                        <?php echo $barangay_filter == $barangay['barangay_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay['barangay_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="age_group_filter">Age Group</label>
                            <select id="age_group_filter" name="age_group_filter">
                                <option value="">All Ages</option>
                                <option value="0-5" <?php echo $age_group_filter == '0-5' ? 'selected' : ''; ?>>0-5 years</option>
                                <option value="6-12" <?php echo $age_group_filter == '6-12' ? 'selected' : ''; ?>>6-12 years</option>
                                <option value="13-19" <?php echo $age_group_filter == '13-19' ? 'selected' : ''; ?>>13-19 years</option>
                                <option value="20-39" <?php echo $age_group_filter == '20-39' ? 'selected' : ''; ?>>20-39 years</option>
                                <option value="40-59" <?php echo $age_group_filter == '40-59' ? 'selected' : ''; ?>>40-59 years</option>
                                <option value="60+" <?php echo $age_group_filter == '60+' ? 'selected' : ''; ?>>60+ years</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="sex_filter">Sex</label>
                            <select id="sex_filter" name="sex_filter">
                                <option value="">All</option>
                                <option value="Male" <?php echo $sex_filter == 'Male' ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo $sex_filter == 'Female' ? 'selected' : ''; ?>>Female</option>
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
                        <a href="export_morbidity_pdf.php?<?php echo http_build_query($_GET); ?>" class="btn btn-export" target="_blank">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </a>
                    </div>
                </form>
            </div>

            <div class="morbidity-report-content">
            <!-- Comprehensive Information Panel -->
            <div class="info-panel" style="background: #f8f9fa; border-radius: 12px; padding: 20px; margin-bottom: 30px; border: 1px solid var(--border-color);">
                <h3 style="color: var(--primary-color); margin: 0 0 15px 0; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-info-circle"></i> Report Information
                </h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; font-size: 14px;">
                    <div>
                        <strong>Report Period:</strong><br>
                        <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?>
                    </div>
                    <div>
                        <strong>Generated:</strong><br>
                        <?php echo date('F j, Y g:i A'); ?>
                    </div>
                    <div>
                        <strong>Generated By:</strong><br>
                        <?php echo htmlspecialchars(($_SESSION['employee_first_name'] ?? '') . ' ' . ($_SESSION['employee_last_name'] ?? '')); ?> (<?php echo ucfirst($_SESSION['role']); ?>)
                    </div>
                    <div>
                        <strong>Facility:</strong><br>
                        City Health Office - Koronadal
                    </div>
                    <div>
                        <strong>Filter Applied:</strong><br>
                        <?php 
                        $filters = [];
                        if (!empty($district_filter)) {
                            $district_name = '';
                            foreach ($districts as $d) {
                                if ($d['district_id'] == $district_filter) {
                                    $district_name = $d['district_name'];
                                    break;
                                }
                            }
                            $filters[] = "District: " . $district_name;
                        }
                        if (!empty($barangay_filter)) {
                            $barangay_name = '';
                            foreach ($barangays as $b) {
                                if ($b['barangay_id'] == $barangay_filter) {
                                    $barangay_name = $b['barangay_name'];
                                    break;
                                }
                            }
                            $filters[] = "Barangay: " . $barangay_name;
                        }
                        if (!empty($age_group_filter)) $filters[] = "Age: " . $age_group_filter;
                        if (!empty($sex_filter)) $filters[] = "Gender: " . $sex_filter;
                        
                        echo !empty($filters) ? implode(', ', $filters) : 'None (All data included)';
                        ?>
                    </div>
                    <div>
                        <strong>Status:</strong><br>
                        <span style="color: var(--success-color); font-weight: 600;">
                            <i class="fas fa-check-circle"></i> Complete
                        </span>
                    </div>
                </div>
            </div>

            <!-- Summary Statistics -->
            <div class="summary-cards">
                <div class="summary-card">
                    <i class="fas fa-stethoscope"></i>
                    <div class="number"><?php echo number_format($summary_stats['total_consultations'] ?? 0); ?></div>
                    <div class="label">Total Consultations</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-users"></i>
                    <div class="number"><?php echo number_format($summary_stats['total_patients'] ?? 0); ?></div>
                    <div class="label">Unique Patients</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-virus"></i>
                    <div class="number"><?php echo number_format($summary_stats['unique_diagnoses'] ?? 0); ?></div>
                    <div class="label">Unique Diagnoses</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-map-marker-alt"></i>
                    <div class="number"><?php echo number_format($summary_stats['affected_barangays'] ?? 0); ?></div>
                    <div class="label">Affected Barangays</div>
                </div>
            </div>

            <!-- Main Analytics Grid -->
            <div class="analytics-grid">
                <!-- Disease Prevalence -->
                <div class="analytics-section">
                    <h3><i class="fas fa-chart-line"></i> Disease Prevalence</h3>
                    <p style="font-size: 14px; color: var(--text-light); margin-bottom: 15px;">
                        Top diseases by frequency in consultations (Period: <?php echo $start_date; ?> to <?php echo $end_date; ?>)
                    </p>
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Diagnosis</th>
                                <th>Cases</th>
                                <th>Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($disease_prevalence)): ?>
                                <?php foreach (array_slice($disease_prevalence, 0, 10) as $index => $disease): ?>
                                    <tr>
                                        <td><span class="disease-rank"><?php echo $index + 1; ?></span></td>
                                        <td><?php echo htmlspecialchars($disease['diagnosis']); ?></td>
                                        <td class="count"><?php echo number_format($disease['case_count']); ?></td>
                                        <td class="percentage"><?php echo $disease['prevalence_rate']; ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                <!-- Fill remaining rows if less than 10 -->
                                <?php for ($i = count($disease_prevalence); $i < 10; $i++): ?>
                                    <tr style="opacity: 0.5;">
                                        <td><?php echo $i + 1; ?></td>
                                        <td style="font-style: italic; color: var(--text-light);">No data</td>
                                        <td class="count">0</td>
                                        <td class="percentage">0.00%</td>
                                    </tr>
                                <?php endfor; ?>
                            <?php else: ?>
                                <!-- Show 10 empty rows when no data -->
                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                    <tr style="opacity: 0.5;">
                                        <td><?php echo $i; ?></td>
                                        <td style="font-style: italic; color: var(--text-light);">No data available</td>
                                        <td class="count">0</td>
                                        <td class="percentage">0.00%</td>
                                    </tr>
                                <?php endfor; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Chronic Illnesses -->
                <div class="analytics-section">
                    <h3><i class="fas fa-heartbeat"></i> Chronic Illnesses</h3>
                    <p style="font-size: 14px; color: var(--text-light); margin-bottom: 15px;">
                        Chronic conditions by patient prevalence
                    </p>
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Illness</th>
                                <th>Patients</th>
                                <th>Avg Years</th>
                                <th>Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($chronic_illnesses)): ?>
                                <?php foreach (array_slice($chronic_illnesses, 0, 8) as $illness): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($illness['illness']); ?></td>
                                        <td class="count"><?php echo number_format($illness['patient_count']); ?></td>
                                        <td><?php echo round($illness['avg_years_diagnosed'], 1); ?>y</td>
                                        <td class="percentage"><?php echo $illness['prevalence_rate']; ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                                <!-- Fill remaining rows if less than 8 -->
                                <?php for ($i = count($chronic_illnesses); $i < 8; $i++): ?>
                                    <tr style="opacity: 0.5;">
                                        <td style="font-style: italic; color: var(--text-light);">No data</td>
                                        <td class="count">0</td>
                                        <td>0.0y</td>
                                        <td class="percentage">0.00%</td>
                                    </tr>
                                <?php endfor; ?>
                            <?php else: ?>
                                <!-- Show 8 empty rows when no data -->
                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                    <tr style="opacity: 0.5;">
                                        <td style="font-style: italic; color: var(--text-light);">No chronic illness data</td>
                                        <td class="count">0</td>
                                        <td>0.0y</td>
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
                    <button class="tab-button active" onclick="showTab('age-analysis')">
                        <i class="fas fa-users"></i> Age Analysis
                    </button>
                    <button class="tab-button" onclick="showTab('geographic')">
                        <i class="fas fa-map-marked-alt"></i> Geographic Distribution
                    </button>
                    <button class="tab-button" onclick="showTab('gender')">
                        <i class="fas fa-venus-mars"></i> Gender Analysis
                    </button>
                    <button class="tab-button" onclick="showTab('seasonal')">
                        <i class="fas fa-calendar-alt"></i> Seasonal Trends
                    </button>
                </div>

                <!-- Age Analysis Tab -->
                <div id="age-analysis" class="tab-content active">
                    <div class="full-width-section">
                        <h3><i class="fas fa-users"></i> Disease Distribution by Age Groups</h3>
                        <?php 
                        // Define all age groups to ensure complete display
                        $all_age_groups = ['0-5 years', '6-12 years', '13-19 years', '20-39 years', '40-59 years', '60+ years'];
                        $grouped_by_age = [];
                        
                        if (!empty($age_group_diseases)) {
                            foreach ($age_group_diseases as $disease) {
                                $grouped_by_age[$disease['age_group']][] = $disease;
                            }
                        }
                        ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
                            <?php foreach ($all_age_groups as $age_group): ?>
                                <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 15px;">
                                    <h4 style="margin: 0 0 15px 0; color: var(--primary-color);">
                                        <span class="age-group-tag"><?php echo htmlspecialchars($age_group); ?></span>
                                    </h4>
                                    <table class="analytics-table">
                                        <thead>
                                            <tr>
                                                <th>Disease</th>
                                                <th>Cases</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (isset($grouped_by_age[$age_group]) && !empty($grouped_by_age[$age_group])): ?>
                                                <?php foreach (array_slice($grouped_by_age[$age_group], 0, 5) as $disease): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($disease['diagnosis']); ?></td>
                                                        <td class="count"><?php echo number_format($disease['case_count']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <!-- Fill remaining rows if less than 5 -->
                                                <?php for ($i = count($grouped_by_age[$age_group]); $i < 5; $i++): ?>
                                                    <tr style="opacity: 0.5;">
                                                        <td style="font-style: italic; color: var(--text-light);">No data</td>
                                                        <td class="count">0</td>
                                                    </tr>
                                                <?php endfor; ?>
                                            <?php else: ?>
                                                <!-- Show 5 empty rows when no data for this age group -->
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <tr style="opacity: 0.5;">
                                                        <td style="font-style: italic; color: var(--text-light);">No data available</td>
                                                        <td class="count">0</td>
                                                    </tr>
                                                <?php endfor; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Geographic Distribution Tab -->
                <div id="geographic" class="tab-content">
                    <div class="analytics-grid">
                        <!-- District Distribution -->
                        <div class="analytics-section">
                            <h3><i class="fas fa-map-marked-alt"></i> District Distribution</h3>
                            <?php if (!empty($district_distribution)): ?>
                                <?php 
                                $grouped_by_district = [];
                                foreach ($district_distribution as $item) {
                                    $grouped_by_district[$item['district_name']][] = $item;
                                }
                                ?>
                                <?php foreach ($grouped_by_district as $district => $diseases): ?>
                                    <h4 style="color: var(--primary-color); margin: 15px 0 10px 0;"><?php echo htmlspecialchars($district); ?></h4>
                                    <table class="analytics-table" style="margin-bottom: 20px;">
                                        <thead>
                                            <tr>
                                                <th>Disease</th>
                                                <th>Cases</th>
                                                <th>Patients</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($diseases, 0, 3) as $disease): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($disease['diagnosis']); ?></td>
                                                    <td class="count"><?php echo number_format($disease['case_count']); ?></td>
                                                    <td><?php echo number_format($disease['patient_count']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <!-- Fill remaining rows if less than 3 -->
                                            <?php for ($i = count($diseases); $i < 3; $i++): ?>
                                                <tr style="opacity: 0.5;">
                                                    <td style="font-style: italic; color: var(--text-light);">No additional data</td>
                                                    <td class="count">0</td>
                                                    <td>0</td>
                                                </tr>
                                            <?php endfor; ?>
                                        </tbody>
                                    </table>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="text-align: center; padding: 20px;">
                                    <h4 style="color: var(--primary-color); margin: 15px 0 10px 0;">No District Selected</h4>
                                    <table class="analytics-table">
                                        <thead>
                                            <tr>
                                                <th>Disease</th>
                                                <th>Cases</th>
                                                <th>Patients</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php for ($i = 1; $i <= 3; $i++): ?>
                                                <tr style="opacity: 0.5;">
                                                    <td style="font-style: italic; color: var(--text-light);">No data available</td>
                                                    <td class="count">0</td>
                                                    <td>0</td>
                                                </tr>
                                            <?php endfor; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Barangay Distribution -->
                        <div class="analytics-section">
                            <h3><i class="fas fa-map-marker-alt"></i> Top Affected Barangays</h3>
                            <table class="analytics-table">
                                <thead>
                                    <tr>
                                        <th>Barangay</th>
                                        <th>District</th>
                                        <th>Cases</th>
                                        <th>Diseases</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($barangay_distribution)): ?>
                                        <?php foreach (array_slice($barangay_distribution, 0, 10) as $barangay): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($barangay['barangay_name']); ?></td>
                                                <td><?php echo htmlspecialchars($barangay['district_name']); ?></td>
                                                <td class="count"><?php echo number_format($barangay['total_cases']); ?></td>
                                                <td><?php echo number_format($barangay['unique_diseases']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <!-- Fill remaining rows if less than 10 -->
                                        <?php for ($i = count($barangay_distribution); $i < 10; $i++): ?>
                                            <tr style="opacity: 0.5;">
                                                <td style="font-style: italic; color: var(--text-light);">No data</td>
                                                <td style="font-style: italic; color: var(--text-light);">-</td>
                                                <td class="count">0</td>
                                                <td>0</td>
                                            </tr>
                                        <?php endfor; ?>
                                    <?php else: ?>
                                        <!-- Show 10 empty rows when no data -->
                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                            <tr style="opacity: 0.5;">
                                                <td style="font-style: italic; color: var(--text-light);">No barangay data</td>
                                                <td style="font-style: italic; color: var(--text-light);">-</td>
                                                <td class="count">0</td>
                                                <td>0</td>
                                            </tr>
                                        <?php endfor; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Gender Analysis Tab -->
                <div id="gender" class="tab-content">
                    <div class="full-width-section">
                        <h3><i class="fas fa-venus-mars"></i> Disease Distribution by Gender</h3>
                        <?php 
                        $genders = ['Male', 'Female'];
                        $grouped_by_gender = [];
                        
                        if (!empty($gender_distribution)) {
                            foreach ($gender_distribution as $disease) {
                                $grouped_by_gender[$disease['sex']][] = $disease;
                            }
                        }
                        ?>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px;">
                            <?php foreach ($genders as $gender): ?>
                                <div>
                                    <h4 style="color: var(--primary-color); margin-bottom: 15px;">
                                        <i class="fas fa-<?php echo $gender == 'Male' ? 'mars' : 'venus'; ?>"></i>
                                        <?php echo htmlspecialchars($gender); ?>
                                    </h4>
                                    <table class="analytics-table">
                                        <thead>
                                            <tr>
                                                <th>Disease</th>
                                                <th>Cases</th>
                                                <th>Patients</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (isset($grouped_by_gender[$gender]) && !empty($grouped_by_gender[$gender])): ?>
                                                <?php foreach (array_slice($grouped_by_gender[$gender], 0, 8) as $disease): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($disease['diagnosis']); ?></td>
                                                        <td class="count"><?php echo number_format($disease['case_count']); ?></td>
                                                        <td><?php echo number_format($disease['patient_count']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <!-- Fill remaining rows if less than 8 -->
                                                <?php for ($i = count($grouped_by_gender[$gender]); $i < 8; $i++): ?>
                                                    <tr style="opacity: 0.5;">
                                                        <td style="font-style: italic; color: var(--text-light);">No data</td>
                                                        <td class="count">0</td>
                                                        <td>0</td>
                                                    </tr>
                                                <?php endfor; ?>
                                            <?php else: ?>
                                                <!-- Show 8 empty rows when no data for this gender -->
                                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                                    <tr style="opacity: 0.5;">
                                                        <td style="font-style: italic; color: var(--text-light);">No data available</td>
                                                        <td class="count">0</td>
                                                        <td>0</td>
                                                    </tr>
                                                <?php endfor; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Seasonal Trends Tab -->
                <div id="seasonal" class="tab-content">
                    <div class="full-width-section">
                        <h3><i class="fas fa-calendar-alt"></i> Seasonal Disease Trends</h3>
                        <?php 
                        // Define all months to ensure complete display
                        $all_months = ['January', 'February', 'March', 'April', 'May', 'June', 
                                      'July', 'August', 'September', 'October', 'November', 'December'];
                        $grouped_by_month = [];
                        
                        if (!empty($seasonal_trends)) {
                            foreach ($seasonal_trends as $trend) {
                                $grouped_by_month[$trend['month_name']][] = $trend;
                            }
                        }
                        ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                            <?php foreach ($all_months as $month): ?>
                                <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 15px;">
                                    <h4 style="margin: 0 0 15px 0; color: var(--primary-color);">
                                        <i class="fas fa-calendar"></i> <?php echo htmlspecialchars($month); ?>
                                    </h4>
                                    <table class="analytics-table">
                                        <thead>
                                            <tr>
                                                <th>Disease</th>
                                                <th>Cases</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (isset($grouped_by_month[$month]) && !empty($grouped_by_month[$month])): ?>
                                                <?php foreach (array_slice($grouped_by_month[$month], 0, 5) as $disease): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($disease['diagnosis']); ?></td>
                                                        <td class="count"><?php echo number_format($disease['case_count']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <!-- Fill remaining rows if less than 5 -->
                                                <?php for ($i = count($grouped_by_month[$month]); $i < 5; $i++): ?>
                                                    <tr style="opacity: 0.5;">
                                                        <td style="font-style: italic; color: var(--text-light);">No data</td>
                                                        <td class="count">0</td>
                                                    </tr>
                                                <?php endfor; ?>
                                            <?php else: ?>
                                                <!-- Show 5 empty rows when no data for this month -->
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <tr style="opacity: 0.5;">
                                                        <td style="font-style: italic; color: var(--text-light);">No data available</td>
                                                        <td class="count">0</td>
                                                    </tr>
                                                <?php endfor; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        </div>
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

        // District/Barangay filter dependency
        document.addEventListener('DOMContentLoaded', function() {
            const districtFilter = document.getElementById('district_filter');
            const barangayFilter = document.getElementById('barangay_filter');
            
            function updateBarangayOptions() {
                const selectedDistrict = districtFilter.value;
                const barangayOptions = barangayFilter.querySelectorAll('option');
                
                barangayOptions.forEach(option => {
                    if (option.value === '') {
                        option.style.display = 'block'; // Show "All Barangays" option
                        return;
                    }
                    
                    const optionDistrict = option.getAttribute('data-district');
                    if (selectedDistrict === '' || optionDistrict === selectedDistrict) {
                        option.style.display = 'block';
                    } else {
                        option.style.display = 'none';
                    }
                });
                
                // Reset barangay selection if current selection is not visible
                const currentBarangay = barangayFilter.value;
                if (currentBarangay) {
                    const currentOption = barangayFilter.querySelector(`option[value="${currentBarangay}"]`);
                    if (currentOption && currentOption.style.display === 'none') {
                        barangayFilter.value = '';
                    }
                }
            }
            
            // Initialize on page load
            updateBarangayOptions();
            
            // Update when district changes
            districtFilter.addEventListener('change', updateBarangayOptions);

            console.log('Morbidity Report loaded successfully');
            
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
            
            // Auto-refresh data every 5 minutes if no filters are applied
            const urlParams = new URLSearchParams(window.location.search);
            const hasFilters = urlParams.toString().length > 0;
            
            if (!hasFilters) {
                setTimeout(() => {
                    window.location.reload();
                }, 300000); // 5 minutes
            }
        });

        // Enhanced analytics functionality
        function highlightRow(element) {
            element.style.backgroundColor = 'var(--accent-color)';
            element.style.transition = 'background-color 0.2s';
        }

        function unhighlightRow(element) {
            element.style.backgroundColor = '';
        }

        // Add row highlighting to all analytics tables
        document.addEventListener('DOMContentLoaded', function() {
            const analyticsRows = document.querySelectorAll('.analytics-table tbody tr');
            analyticsRows.forEach(row => {
                row.addEventListener('mouseenter', () => highlightRow(row));
                row.addEventListener('mouseleave', () => unhighlightRow(row));
            });
        });

        // Print functionality
        function printReport() {
            window.print();
        }

        // Export functionality helper
        function exportToCSV() {
            // This would typically make an AJAX call to generate CSV
            const params = new URLSearchParams(window.location.search);
            window.open('export_morbidity_csv.php?' + params.toString(), '_blank');
        }

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'p':
                        e.preventDefault();
                        printReport();
                        break;
                    case 'r':
                        e.preventDefault();
                        window.location.href = '?';
                        break;
                }
            }
        });
    </script>
</body>

</html>