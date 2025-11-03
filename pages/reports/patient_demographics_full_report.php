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

// Define role-based permissions
$allowedRoles = ['admin', 'dho', 'cashier', 'records_officer'];
$userRole = $_SESSION['role'] ?? '';

if (!in_array($userRole, $allowedRoles)) {
    $role = strtolower($userRole);
    header("Location: ../management/$role/dashboard.php");
    exit();
}

// Initialize all data arrays
$total_patients = 0;
$age_distribution = [];
$gender_distribution = [];
$barangay_distribution = [];
$district_distribution = [];
$philhealth_distribution = [];
$philhealth_overall = [];
$age_by_district = [];
$age_by_barangay = [];
$gender_by_district = [];
$gender_by_barangay = [];
$philhealth_by_district = [];
$philhealth_by_barangay = [];
$pwd_count = 0;
$pwd_percentage = 0;

try {
    // Get total active patients
    $total_query = "SELECT COUNT(*) as total FROM patients WHERE status = 'active'";
    $total_result = $conn->query($total_query);
    $total_patients = $total_result->fetch_assoc()['total'];

    // Get PWD count
    $pwd_query = "SELECT COUNT(*) as count FROM patients WHERE status = 'active' AND isPWD = 1";
    $pwd_result = $conn->query($pwd_query);
    $pwd_count = $pwd_result->fetch_assoc()['count'];
    $pwd_percentage = $total_patients > 0 ? ($pwd_count / $total_patients * 100) : 0;

    // Age distribution
    $age_query = "
        SELECT 
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) <= 1 THEN 'Infants (0-1)'
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 2 AND 4 THEN 'Toddlers (1-4)'
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
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) <= 1 THEN 1
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 2 AND 4 THEN 2
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 3
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 4
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 5
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 6
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) >= 60 THEN 7
                ELSE 8
            END
    ";
    $age_result = $conn->query($age_query);
    while ($row = $age_result->fetch_assoc()) {
        $age_distribution[] = $row;
    }

    // Gender distribution
    $gender_query = "
        SELECT 
            CASE WHEN sex = 'M' THEN 'Male' WHEN sex = 'F' THEN 'Female' ELSE sex END as gender,
            COUNT(*) as count
        FROM patients 
        WHERE status = 'active'
        GROUP BY sex
    ";
    $gender_result = $conn->query($gender_query);
    while ($row = $gender_result->fetch_assoc()) {
        $gender_distribution[] = $row;
    }

    // ALL Barangay distribution
    $barangay_query = "
        SELECT 
            COALESCE(b.barangay_name, 'Unknown Barangay') as barangay_name,
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

    // ALL District distribution
    $district_query = "
        SELECT 
            COALESCE(d.district_name, 'Unknown District') as district_name,
            COUNT(p.patient_id) as count
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN districts d ON b.district_id = d.district_id
        WHERE p.status = 'active'
        GROUP BY d.district_id, d.district_name
        ORDER BY count DESC, d.district_name ASC
    ";
    $district_result = $conn->query($district_query);
    while ($row = $district_result->fetch_assoc()) {
        $district_distribution[] = $row;
    }

    // PhilHealth membership distribution
    $philhealth_overall_query = "
        SELECT 
            CASE WHEN isPhilHealth = 1 THEN 'PhilHealth Member' ELSE 'Non-Member' END as membership_status,
            COUNT(*) as count
        FROM patients 
        WHERE status = 'active'
        GROUP BY isPhilHealth
    ";
    $philhealth_overall_result = $conn->query($philhealth_overall_query);
    while ($row = $philhealth_overall_result->fetch_assoc()) {
        $philhealth_overall[] = $row;
    }

    // PhilHealth types distribution
    $philhealth_query = "
        SELECT 
            COALESCE(pt.type_name, 'Unknown Type') as philhealth_type,
            COUNT(p.patient_id) as count
        FROM patients p
        LEFT JOIN philhealth_types pt ON p.philhealth_type_id = pt.id
        WHERE p.status = 'active' AND p.isPhilHealth = 1
        GROUP BY p.philhealth_type_id, pt.type_name
        ORDER BY count DESC
    ";
    $philhealth_result = $conn->query($philhealth_query);
    while ($row = $philhealth_result->fetch_assoc()) {
        $philhealth_distribution[] = $row;
    }

    // Age distribution by ALL districts
    $age_by_district_query = "
        SELECT 
            COALESCE(d.district_name, 'Unknown District') as district_name,
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) <= 1 THEN 'Infants (0-1)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 2 AND 4 THEN 'Toddlers (1-4)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 'Children (5-12)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 'Teens (13-17)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 'Young Adults (18-35)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 'Adults (36-59)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) >= 60 THEN 'Seniors (60+)'
                ELSE 'Unknown'
            END as age_group,
            COUNT(*) as count
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN districts d ON b.district_id = d.district_id
        WHERE p.status = 'active' AND p.date_of_birth IS NOT NULL
        GROUP BY d.district_id, d.district_name, age_group
        ORDER BY d.district_name, 
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) <= 1 THEN 1
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 2 AND 4 THEN 2
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 3
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 4
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 5
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 6
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) >= 60 THEN 7
                ELSE 8
            END
    ";
    $age_by_district_result = $conn->query($age_by_district_query);
    while ($row = $age_by_district_result->fetch_assoc()) {
        $age_by_district[] = $row;
    }

    // Age distribution by ALL barangays
    $age_by_barangay_query = "
        SELECT 
            COALESCE(b.barangay_name, 'Unknown Barangay') as barangay_name,
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) <= 1 THEN 'Infants (0-1)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 2 AND 4 THEN 'Toddlers (1-4)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 'Children (5-12)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 'Teens (13-17)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 'Young Adults (18-35)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 'Adults (36-59)'
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) >= 60 THEN 'Seniors (60+)'
                ELSE 'Unknown'
            END as age_group,
            COUNT(*) as count
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.status = 'active' AND p.date_of_birth IS NOT NULL
        GROUP BY b.barangay_id, b.barangay_name, age_group
        ORDER BY b.barangay_name, 
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) <= 1 THEN 1
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 2 AND 4 THEN 2
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 3
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 4
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 5
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 6
                WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) >= 60 THEN 7
                ELSE 8
            END
    ";
    $age_by_barangay_result = $conn->query($age_by_barangay_query);
    while ($row = $age_by_barangay_result->fetch_assoc()) {
        $age_by_barangay[] = $row;
    }

    // Gender distribution by ALL districts
    $gender_by_district_query = "
        SELECT 
            COALESCE(d.district_name, 'Unknown District') as district_name,
            CASE WHEN p.sex = 'M' THEN 'Male' WHEN p.sex = 'F' THEN 'Female' ELSE p.sex END as gender,
            COUNT(*) as count
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN districts d ON b.district_id = d.district_id
        WHERE p.status = 'active'
        GROUP BY d.district_id, d.district_name, p.sex
        ORDER BY d.district_name, p.sex
    ";
    $gender_by_district_result = $conn->query($gender_by_district_query);
    while ($row = $gender_by_district_result->fetch_assoc()) {
        $gender_by_district[] = $row;
    }

    // Gender distribution by ALL barangays
    $gender_by_barangay_query = "
        SELECT 
            COALESCE(b.barangay_name, 'Unknown Barangay') as barangay_name,
            CASE WHEN p.sex = 'M' THEN 'Male' WHEN p.sex = 'F' THEN 'Female' ELSE p.sex END as gender,
            COUNT(*) as count
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.status = 'active'
        GROUP BY b.barangay_id, b.barangay_name, p.sex
        ORDER BY b.barangay_name, p.sex
    ";
    $gender_by_barangay_result = $conn->query($gender_by_barangay_query);
    while ($row = $gender_by_barangay_result->fetch_assoc()) {
        $gender_by_barangay[] = $row;
    }

    // PhilHealth distribution by ALL districts
    $philhealth_by_district_query = "
        SELECT 
            COALESCE(d.district_name, 'Unknown District') as district_name,
            CASE WHEN p.isPhilHealth = 1 THEN 'PhilHealth Member' ELSE 'Non-Member' END as philhealth_type,
            COUNT(p.patient_id) as count
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN districts d ON b.district_id = d.district_id
        WHERE p.status = 'active'
        GROUP BY d.district_id, d.district_name, p.isPhilHealth
        ORDER BY d.district_name, p.isPhilHealth DESC
    ";
    $philhealth_by_district_result = $conn->query($philhealth_by_district_query);
    while ($row = $philhealth_by_district_result->fetch_assoc()) {
        $philhealth_by_district[] = $row;
    }

    // PhilHealth distribution by ALL barangays
    $philhealth_by_barangay_query = "
        SELECT 
            COALESCE(b.barangay_name, 'Unknown Barangay') as barangay_name,
            CASE WHEN p.isPhilHealth = 1 THEN 'PhilHealth Member' ELSE 'Non-Member' END as philhealth_type,
            COUNT(p.patient_id) as count
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.status = 'active'
        GROUP BY b.barangay_id, b.barangay_name, p.isPhilHealth
        ORDER BY b.barangay_name, p.isPhilHealth DESC
    ";
    $philhealth_by_barangay_result = $conn->query($philhealth_by_barangay_query);
    while ($row = $philhealth_by_barangay_result->fetch_assoc()) {
        $philhealth_by_barangay[] = $row;
    }
} catch (Exception $e) {
    $error = "Error fetching demographics data: " . $e->getMessage();
    error_log($error);
}

// Calculate totals for percentages
$philhealth_members = 0;
foreach ($philhealth_overall as $item) {
    if ($item['membership_status'] === 'PhilHealth Member') {
        $philhealth_members = $item['count'];
        break;
    }
}
$philhealth_percentage = $total_patients > 0 ? ($philhealth_members / $total_patients * 100) : 0;

$age_total = array_sum(array_column($age_distribution, 'count'));
$gender_total = array_sum(array_column($gender_distribution, 'count'));
$district_total = array_sum(array_column($district_distribution, 'count'));
$barangay_total = array_sum(array_column($barangay_distribution, 'count'));

// Calculate gender ratio
$male_count = 0;
$female_count = 0;
foreach ($gender_distribution as $gender) {
    if ($gender['gender'] === 'Male') {
        $male_count = $gender['count'];
    } elseif ($gender['gender'] === 'Female') {
        $female_count = $gender['count'];
    }
}
$gender_ratio = $female_count > 0 ? ($male_count / $female_count) : 0;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Demographics Full Report - CHO Koronadal</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">

    <style>
        @page {
            size: legal;
            margin: 0.5in;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Times New Roman', serif;
            font-size: 11px;
            line-height: 1.4;
            color: #000;
            background: white;
        }

        .report-container {
            max-width: 8.5in;
            margin: 0 auto;
            padding: 0.5in;
            background: white;
        }

        .report-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 4px solid #1e3a8a;
            padding-bottom: 20px;
        }

        .report-header h1 {
            font-size: 20px;
            font-weight: bold;
            margin: 10px 0;
            color: #1e3a8a;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .report-header h2 {
            font-size: 16px;
            font-weight: normal;
            margin: 5px 0;
            color: #374151;
        }

        .generation-info {
            font-size: 10px;
            margin: 5px 0;
            color: #6b7280;
            font-style: italic;
        }

        .section-title {
            font-size: 16px;
            font-weight: bold;
            color: #1e3a8a;
            border-bottom: 2px solid #1e3a8a;
            padding-bottom: 5px;
            margin: 25px 0 15px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .subsection-title {
            font-size: 13px;
            font-weight: bold;
            color: #374151;
            margin: 20px 0 10px 0;
            border-left: 4px solid #3b82f6;
            padding-left: 10px;
        }

        .definition-box {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 15px;
            font-size: 10px;
        }

        .definition-box p {
            margin: 3px 0;
            line-height: 1.4;
        }

        .definition-box ul {
            margin: 5px 0;
            padding-left: 15px;
        }

        .definition-box li {
            margin: 2px 0;
        }

        .narrative-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 20px;
            margin-bottom: 25px;
            font-size: 11px;
            line-height: 1.6;
        }

        .narrative-section h3 {
            font-size: 13px;
            font-weight: bold;
            color: #1e3a8a;
            margin: 15px 0 10px 0;
            border-bottom: 1px solid #cbd5e1;
            padding-bottom: 5px;
        }

        .narrative-section h3:first-child {
            margin-top: 0;
        }

        .narrative-section p {
            margin: 8px 0;
            text-align: justify;
        }

        .narrative-section ul {
            margin: 8px 0;
            padding-left: 20px;
        }

        .narrative-section li {
            margin: 5px 0;
            text-align: justify;
        }

        .count-display {
            text-align: center;
            padding: 15px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .big-number {
            font-size: 24px;
            font-weight: bold;
            color: white;
            margin: 0;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
            margin-bottom: 15px;
            border: 1px solid #d1d5db;
        }

        .data-table th {
            background: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
            color: white;
            padding: 8px 6px;
            text-align: left;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-size: 8px;
        }

        .data-table td {
            padding: 6px;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }

        .data-table tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .breakdown-section {
            margin-bottom: 20px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            overflow: hidden;
        }

        .breakdown-header {
            background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
            color: white;
            padding: 8px 10px;
            font-size: 11px;
            font-weight: bold;
        }

        .breakdown-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8px;
        }

        .breakdown-table th {
            background-color: #6b7280;
            color: white;
            padding: 6px 4px;
            text-align: left;
            font-weight: bold;
            font-size: 7px;
        }

        .breakdown-table td {
            padding: 4px;
            border-bottom: 1px solid #e5e7eb;
        }

        .breakdown-table tr:nth-child(even) {
            background-color: #fafafa;
        }

        .page-break {
            page-break-before: always;
        }

        .report-footer {
            text-align: center;
            margin-top: 30px;
            padding: 15px 0;
            border-top: 2px solid #1e3a8a;
            font-size: 9px;
            color: #6b7280;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }

        .no-print {
            position: fixed;
            top: 10px;
            right: 10px;
            z-index: 1000;
        }

        .btn {
            padding: 8px 15px;
            margin: 0 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #3b82f6;
            color: white;
        }

        .btn-secondary {
            background: #6b7280;
            color: white;
        }

        .btn:hover {
            opacity: 0.9;
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                font-size: 10px;
            }

            .report-container {
                max-width: none;
                padding: 0;
                margin: 0;
            }

            .page-break {
                page-break-before: always;
            }
        }
    </style>
</head>

<body>
    <div class="no-print">
        <button class="btn btn-primary" onclick="window.print()">🖨️ Print Report</button>
        <a href="patient_demographics.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>

    <div class="report-container">
        <!-- Report Header -->
        <div class="report-header">
            <h2>Republic of the Philippines</h2>
            <h2>City Health Office</h2>
            <h2>Koronadal City</h2>
            <h1>COMPREHENSIVE PATIENT DEMOGRAPHICS REPORT</h1>
            <p class="generation-info">Generated: <?= date('F j, Y') ?> at <?= date('g:i A') ?></p>
            <p class="generation-info">Generated by: <?= htmlspecialchars($_SESSION['employee_name'] ?? ($_SESSION['employee_first_name'] . ' ' . $_SESSION['employee_last_name']) ?? 'System User') ?> (<?= htmlspecialchars(ucfirst($_SESSION['role'])) ?>)</p>
        </div>

        <!-- I. KEY STATISTICS -->
        <div class="section-title">I. KEY STATISTICS</div>

        <table class="data-table" style="margin-bottom: 25px;">
            <thead>
                <tr>
                    <th>Statistic</th>
                    <th>Count</th>
                    <th>Percentage</th>
                    <th>Definition</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Total Active Patients</strong></td>
                    <td><?= number_format($total_patients) ?></td>
                    <td>100.0%</td>
                    <td>All patients with status = 'active' (denominator for calculations)</td>
                </tr>
                <tr>
                    <td><strong>PhilHealth Members</strong></td>
                    <td><?= number_format($philhealth_members) ?></td>
                    <td><?= number_format($philhealth_percentage, 1) ?>%</td>
                    <td>Active patients with isPhilHealth = 1 (coverage rate)</td>
                </tr>
                <tr>
                    <td><strong>PWD Patients</strong></td>
                    <td><?= number_format($pwd_count) ?></td>
                    <td><?= number_format($pwd_percentage, 1) ?>%</td>
                    <td>Active patients with isPWD = 1 (disability proportion)</td>
                </tr>
            </tbody>
        </table>

        <!-- NARRATIVE REPORT -->
        <div class="section-title">NARRATIVE REPORT</div>

        <div class="narrative-section">
            <h3>Executive Summary</h3>
            <p>This comprehensive Patient Demographics Report provides detailed analysis of <strong><?= number_format($total_patients) ?> active patients</strong> served by the City Health Office of Koronadal City. The report encompasses five major sections examining patient distribution patterns across various demographic and geographic parameters.</p>

            <h3>Key Findings</h3>
            <ul>
                <li><strong>PhilHealth Coverage:</strong> <?= number_format($philhealth_percentage, 1) ?>% (<?= number_format($philhealth_members) ?> patients) have PhilHealth membership, indicating <?= $philhealth_percentage >= 50 ? 'good' : 'limited' ?> healthcare insurance coverage among the active patient population.</li>
                <li><strong>PWD Population:</strong> <?= number_format($pwd_percentage, 1) ?>% (<?= number_format($pwd_count) ?> patients) are persons with disabilities, representing a <?= $pwd_percentage >= 10 ? 'significant' : 'notable' ?> portion requiring specialized healthcare services.</li>
                <li><strong>Geographic Coverage:</strong> Patients are distributed across <?= count($district_distribution) ?> districts and <?= count($barangay_distribution) ?> barangays, demonstrating the health office's comprehensive community reach.</li>
                <li><strong>Sex Distribution:</strong> The patient population shows a sex ratio of <?= number_format($gender_ratio, 2) ?>:1 (Male:Female), indicating <?= $gender_ratio > 1 ? 'more males than females' : 'more females than males' ?> in the active patient registry.</li>
            </ul>

            <h3>Report Contents</h3>
            <p>This report contains the following detailed analyses:</p>
            <ul>
                <li><strong>Section II - Geographic Distribution:</strong> Complete breakdown by all <?= count($district_distribution) ?> districts and <?= count($barangay_distribution) ?> barangays with rankings and percentage distributions.</li>
                <li><strong>Section III - Age Distribution:</strong> Seven age group categories (Infants to Seniors) cross-tabulated with all districts and barangays for comprehensive demographic profiling.</li>
                <li><strong>Section IV - Sex Distribution:</strong> Sex analysis across all geographic areas with ratio calculations and percentage breakdowns.</li>
                <li><strong>Section V - PhilHealth Distribution:</strong> Membership status and type analysis across all districts and barangays to assess healthcare coverage patterns.</li>
            </ul>

            <h3>Data Scope and Methodology</h3>
            <p>All data represents active patients as of <?= date('F j, Y') ?>. Cross-tabulations provide both within-category percentages (e.g., percentage within each district) and overall percentages (percentage of total patient population). This dual-percentage approach enables comprehensive analysis of both local concentrations and system-wide distributions.</p>
        </div>

        <div class="page-break"></div>

        <!-- II. GEOGRAPHIC DISTRIBUTION -->
        <div class="section-title">II. GEOGRAPHIC DISTRIBUTION</div>

        <div class="subsection-title">District Distribution</div>
        <div class="definition-box" style="padding: 8px 12px; margin-bottom: 10px;">
            <p style="margin: 0 0 3px 0;"><strong>Categories:</strong> All districts (via barangay mapping)</p>
            <p style="margin: 0;"><strong>Metrics:</strong> Patient count and percentage per district</p>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>District Name</th>
                    <th>Patient Count</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($district_distribution as $index => $district): ?>
                    <?php $percentage = $district_total > 0 ? ($district['count'] / $district_total * 100) : 0; ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($district['district_name']) ?></td>
                        <td><?= number_format($district['count']) ?></td>
                        <td><?= number_format($percentage, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="subsection-title">Barangay Distribution (ALL BARANGAYS)</div>
        <div class="definition-box" style="padding: 8px 12px; margin-bottom: 10px;">
            <p style="margin: 0 0 3px 0;"><strong>Categories:</strong> All barangays in the system</p>
            <p style="margin: 0;"><strong>Metrics:</strong> Patient count and percentage per barangay</p>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Rank</th>
                    <th>Barangay Name</th>
                    <th>Patient Count</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($barangay_distribution as $index => $barangay): ?>
                    <?php $percentage = $barangay_total > 0 ? ($barangay['count'] / $barangay_total * 100) : 0; ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= htmlspecialchars($barangay['barangay_name']) ?></td>
                        <td><?= number_format($barangay['count']) ?></td>
                        <td><?= number_format($percentage, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="page-break"></div>

        <!-- III. AGE DISTRIBUTION -->
        <div class="section-title">III. AGE DISTRIBUTION</div>

        <div class="subsection-title">Age Distribution</div>
        <div class="definition-box" style="padding: 8px 12px; margin-bottom: 10px;">
            <p style="margin: 0 0 5px 0;"><strong>Categories:</strong> Infants (0–1), Toddlers (1–4), Children (5–12), Teens (13–17), Young Adults (18–35), Adults (36–59), Seniors (60+)</p>
            <p style="margin: 0;"><strong>Metrics:</strong> Count and percentage per age group</p>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Age Group</th>
                    <th>Count</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $all_age_groups = ['Infants (0-1)', 'Toddlers (1-4)', 'Children (5-12)', 'Teens (13-17)', 'Young Adults (18-35)', 'Adults (36-59)', 'Seniors (60+)'];
                $age_data_map = [];
                foreach ($age_distribution as $age) {
                    $age_data_map[$age['age_group']] = $age['count'];
                }
                ?>
                <?php foreach ($all_age_groups as $age_group): ?>
                    <?php
                    $count = isset($age_data_map[$age_group]) ? $age_data_map[$age_group] : 0;
                    $percentage = $age_total > 0 ? ($count / $age_total * 100) : 0;
                    ?>
                    <tr>
                        <td><strong><?= $age_group ?></strong></td>
                        <td><?= number_format($count) ?></td>
                        <td><?= number_format($percentage, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="subsection-title">Age Distribution by District (ALL DISTRICTS)</div>
        <div class="definition-box" style="padding: 8px 12px; margin-bottom: 10px;">
            <p style="margin: 0 0 3px 0;"><strong>Categories:</strong> Age groups per district</p>
            <p style="margin: 0;"><strong>Metrics:</strong> Count and percentage per age group within each district</p>
        </div>

        <?php
        // Group age by district data
        $age_by_district_grouped = [];
        foreach ($age_by_district as $item) {
            $district = $item['district_name'];
            $age_group = $item['age_group'];
            $count = $item['count'];

            if (!isset($age_by_district_grouped[$district])) {
                $age_by_district_grouped[$district] = [];
            }
            $age_by_district_grouped[$district][$age_group] = $count;
        }

        foreach ($age_by_district_grouped as $district => $age_data):
            $district_age_total = array_sum($age_data);
        ?>
            <div class="breakdown-section">
                <div class="breakdown-header"><?= htmlspecialchars($district) ?> District (Total: <?= number_format($district_age_total) ?> patients)</div>
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
                        <?php foreach ($all_age_groups as $age_group): ?>
                            <?php
                            $count = isset($age_data[$age_group]) ? $age_data[$age_group] : 0;
                            $district_percentage = $district_age_total > 0 ? ($count / $district_age_total * 100) : 0;
                            $total_percentage = $total_patients > 0 ? ($count / $total_patients * 100) : 0;
                            ?>
                            <tr>
                                <td><?= $age_group ?></td>
                                <td><?= number_format($count) ?></td>
                                <td><?= number_format($district_percentage, 1) ?>%</td>
                                <td><?= number_format($total_percentage, 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <div class="page-break"></div>

        <div class="subsection-title">Age Distribution by Barangay (ALL BARANGAYS)</div>
        <div class="definition-box" style="padding: 8px 12px; margin-bottom: 10px;">
            <p style="margin: 0 0 3px 0;"><strong>Categories:</strong> Age groups per barangay</p>
            <p style="margin: 0;"><strong>Metrics:</strong> Count and percentage per age group within each barangay</p>
        </div>

        <?php
        // Group age by barangay data
        $age_by_barangay_grouped = [];
        foreach ($age_by_barangay as $item) {
            $barangay = $item['barangay_name'];
            $age_group = $item['age_group'];
            $count = $item['count'];

            if (!isset($age_by_barangay_grouped[$barangay])) {
                $age_by_barangay_grouped[$barangay] = [];
            }
            $age_by_barangay_grouped[$barangay][$age_group] = $count;
        }

        // Sort barangays by total patients (descending)
        $barangay_totals = [];
        foreach ($age_by_barangay_grouped as $barangay => $age_data) {
            $barangay_totals[$barangay] = array_sum($age_data);
        }
        arsort($barangay_totals);

        foreach ($barangay_totals as $barangay => $barangay_age_total):
            $age_data = $age_by_barangay_grouped[$barangay];
        ?>
            <div class="breakdown-section">
                <div class="breakdown-header">Barangay <?= htmlspecialchars($barangay) ?> (Total: <?= number_format($barangay_age_total) ?> patients)</div>
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
                        <?php foreach ($all_age_groups as $age_group): ?>
                            <?php
                            $count = isset($age_data[$age_group]) ? $age_data[$age_group] : 0;
                            $barangay_percentage = $barangay_age_total > 0 ? ($count / $barangay_age_total * 100) : 0;
                            $total_percentage = $total_patients > 0 ? ($count / $total_patients * 100) : 0;
                            ?>
                            <tr>
                                <td><?= $age_group ?></td>
                                <td><?= number_format($count) ?></td>
                                <td><?= number_format($barangay_percentage, 1) ?>%</td>
                                <td><?= number_format($total_percentage, 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <div class="page-break"></div>

        <!-- IV. SEX DISTRIBUTION -->
        <div class="section-title">IV. SEX DISTRIBUTION</div>

        <div class="subsection-title">Sex Distribution</div>
        <div class="definition-box" style="padding: 8px 12px; margin-bottom: 10px;">
            <p style="margin: 0 0 3px 0;"><strong>Categories:</strong> Male, Female</p>
            <p style="margin: 0;"><strong>Metrics:</strong> Count, percentage, and sex ratio</p>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Sex</th>
                    <th>Count</th>
                    <th>Percentage</th>
                    <th>Ratio</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($gender_distribution as $gender): ?>
                    <?php
                    $percentage = $gender_total > 0 ? ($gender['count'] / $gender_total * 100) : 0;
                    $ratio_text = '';
                    if ($gender['gender'] === 'Male' && $gender_ratio > 0) {
                        $ratio_text = number_format($gender_ratio, 2) . ':1 (M:F)';
                    } elseif ($gender['gender'] === 'Female' && $gender_ratio > 0) {
                        $ratio_text = '1:' . number_format($gender_ratio, 2) . ' (M:F)';
                    }
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($gender['gender']) ?></strong></td>
                        <td><?= number_format($gender['count']) ?></td>
                        <td><?= number_format($percentage, 1) ?>%</td>
                        <td><?= $ratio_text ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="subsection-title">Sex Distribution by District (ALL DISTRICTS)</div>
        <div class="definition-box" style="padding: 8px 12px; margin-bottom: 10px;">
            <p style="margin: 0 0 3px 0;"><strong>Categories:</strong> Sex per district</p>
            <p style="margin: 0;"><strong>Metrics:</strong> Count and percentage per sex within each district</p>
        </div>

        <?php
        // Group gender by district data
        $gender_by_district_grouped = [];
        foreach ($gender_by_district as $item) {
            $district = $item['district_name'];
            $gender = $item['gender'];
            $count = $item['count'];

            if (!isset($gender_by_district_grouped[$district])) {
                $gender_by_district_grouped[$district] = [];
            }
            $gender_by_district_grouped[$district][$gender] = $count;
        }

        foreach ($gender_by_district_grouped as $district => $gender_data):
            $district_gender_total = array_sum($gender_data);
        ?>
            <div class="breakdown-section">
                <div class="breakdown-header"><?= htmlspecialchars($district) ?> District (Total: <?= number_format($district_gender_total) ?> patients)</div>
                <table class="breakdown-table">
                    <thead>
                        <tr>
                            <th>Sex</th>
                            <th>Count</th>
                            <th>% of District</th>
                            <th>% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (['Male', 'Female'] as $gender): ?>
                            <?php
                            $count = isset($gender_data[$gender]) ? $gender_data[$gender] : 0;
                            $district_percentage = $district_gender_total > 0 ? ($count / $district_gender_total * 100) : 0;
                            $total_percentage = $total_patients > 0 ? ($count / $total_patients * 100) : 0;
                            ?>
                            <tr>
                                <td><strong><?= $gender ?></strong></td>
                                <td><?= number_format($count) ?></td>
                                <td><?= number_format($district_percentage, 1) ?>%</td>
                                <td><?= number_format($total_percentage, 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <div class="subsection-title">Sex Distribution by Barangay (ALL BARANGAYS)</div>
        <div class="definition-box" style="padding: 8px 12px; margin-bottom: 10px;">
            <p style="margin: 0 0 3px 0;"><strong>Categories:</strong> Sex per barangay</p>
            <p style="margin: 0;"><strong>Metrics:</strong> Count and percentage per sex within each barangay</p>
        </div>

        <?php
        // Group gender by barangay data
        $gender_by_barangay_grouped = [];
        foreach ($gender_by_barangay as $item) {
            $barangay = $item['barangay_name'];
            $gender = $item['gender'];
            $count = $item['count'];

            if (!isset($gender_by_barangay_grouped[$barangay])) {
                $gender_by_barangay_grouped[$barangay] = [];
            }
            $gender_by_barangay_grouped[$barangay][$gender] = $count;
        }

        // Sort barangays by total patients (descending)
        $barangay_gender_totals = [];
        foreach ($gender_by_barangay_grouped as $barangay => $gender_data) {
            $barangay_gender_totals[$barangay] = array_sum($gender_data);
        }
        arsort($barangay_gender_totals);

        foreach ($barangay_gender_totals as $barangay => $barangay_gender_total):
            $gender_data = $gender_by_barangay_grouped[$barangay];
        ?>
            <div class="breakdown-section">
                <div class="breakdown-header">Barangay <?= htmlspecialchars($barangay) ?> (Total: <?= number_format($barangay_gender_total) ?> patients)</div>
                <table class="breakdown-table">
                    <thead>
                        <tr>
                            <th>Sex</th>
                            <th>Count</th>
                            <th>% of Barangay</th>
                            <th>% of Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (['Male', 'Female'] as $gender): ?>
                            <?php
                            $count = isset($gender_data[$gender]) ? $gender_data[$gender] : 0;
                            $barangay_percentage = $barangay_gender_total > 0 ? ($count / $barangay_gender_total * 100) : 0;
                            $total_percentage = $total_patients > 0 ? ($count / $total_patients * 100) : 0;
                            ?>
                            <tr>
                                <td><strong><?= $gender ?></strong></td>
                                <td><?= number_format($count) ?></td>
                                <td><?= number_format($barangay_percentage, 1) ?>%</td>
                                <td><?= number_format($total_percentage, 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <div class="page-break"></div>

        <!-- V. PHILHEALTH MEMBER DISTRIBUTION -->
        <div class="section-title">V. PHILHEALTH MEMBER DISTRIBUTION</div>

        <div class="subsection-title">PhilHealth Membership</div>
        <div class="definition-box" style="padding: 8px 12px; margin-bottom: 10px;">
            <p style="margin: 0 0 3px 0;"><strong>Categories:</strong> PhilHealth Member (isPhilHealth = 1), Non-Member (isPhilHealth = 0)</p>
            <p style="margin: 0;"><strong>Metrics:</strong> Count and percentage of PhilHealth members</p>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Membership Status</th>
                    <th>Count</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($philhealth_overall as $status): ?>
                    <?php
                    $philhealth_overall_total = array_sum(array_column($philhealth_overall, 'count'));
                    $percentage = $philhealth_overall_total > 0 ? ($status['count'] / $philhealth_overall_total * 100) : 0;
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($status['membership_status']) ?></strong></td>
                        <td><?= number_format($status['count']) ?></td>
                        <td><?= number_format($percentage, 1) ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (!empty($philhealth_distribution)): ?>
            <div class="subsection-title">PhilHealth Membership Type Breakdown</div>
            <div class="definition-box" style="padding: 8px 12px; margin-bottom: 10px;">
                <p style="margin: 0 0 3px 0;"><strong>Categories:</strong> Membership types (e.g., Indigent, Professional, Senior Citizen, PWD, etc.)</p>
                <p style="margin: 0;"><strong>Metrics:</strong> Count and percentage per membership type (only for members)</p>
            </div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>PhilHealth Type</th>
                        <th>Count</th>
                        <th>% of Members</th>
                        <th>% of Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $philhealth_member_total = array_sum(array_column($philhealth_distribution, 'count'));
                    foreach ($philhealth_distribution as $type):
                        $member_percentage = $philhealth_member_total > 0 ? ($type['count'] / $philhealth_member_total * 100) : 0;
                        $total_percentage = $total_patients > 0 ? ($type['count'] / $total_patients * 100) : 0;
                    ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($type['philhealth_type']) ?></strong></td>
                            <td><?= number_format($type['count']) ?></td>
                            <td><?= number_format($member_percentage, 1) ?>%</td>
                            <td><?= number_format($total_percentage, 1) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="subsection-title">PhilHealth Distribution by District (ALL DISTRICTS)</div>
        <div class="definition-box" style="padding: 8px 12px; margin-bottom: 10px;">
            <p style="margin: 0 0 3px 0;"><strong>Categories:</strong> PhilHealth Member / Non-Member per district</p>
            <p style="margin: 0;"><strong>Metrics:</strong> Count and percentage per membership status within each district</p>
        </div>

        <?php
        // Group PhilHealth by district data
        $philhealth_by_district_grouped = [];
        foreach ($philhealth_by_district as $item) {
            $district = $item['district_name'];
            $philhealth_type = $item['philhealth_type'];
            $count = $item['count'];

            if (!isset($philhealth_by_district_grouped[$district])) {
                $philhealth_by_district_grouped[$district] = [];
            }
            $philhealth_by_district_grouped[$district][$philhealth_type] = $count;
        }

        foreach ($philhealth_by_district_grouped as $district => $philhealth_data):
            $district_philhealth_total = array_sum($philhealth_data);
        ?>
            <div class="breakdown-section">
                <div class="breakdown-header"><?= htmlspecialchars($district) ?> District (Total: <?= number_format($district_philhealth_total) ?> patients)</div>
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
                        <?php foreach (['PhilHealth Member', 'Non-Member'] as $status): ?>
                            <?php
                            $count = isset($philhealth_data[$status]) ? $philhealth_data[$status] : 0;
                            $district_percentage = $district_philhealth_total > 0 ? ($count / $district_philhealth_total * 100) : 0;
                            $total_percentage = $total_patients > 0 ? ($count / $total_patients * 100) : 0;
                            ?>
                            <tr>
                                <td><strong><?= $status ?></strong></td>
                                <td><?= number_format($count) ?></td>
                                <td><?= number_format($district_percentage, 1) ?>%</td>
                                <td><?= number_format($total_percentage, 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <div class="subsection-title">PhilHealth Distribution by Barangay (ALL BARANGAYS)</div>
        <div class="definition-box" style="padding: 8px 12px; margin-bottom: 10px;">
            <p style="margin: 0 0 3px 0;"><strong>Categories:</strong> PhilHealth Member / Non-Member per barangay</p>
            <p style="margin: 0;"><strong>Metrics:</strong> Count and percentage per membership status within each barangay</p>
        </div>

        <?php
        // Group PhilHealth by barangay data
        $philhealth_by_barangay_grouped = [];
        foreach ($philhealth_by_barangay as $item) {
            $barangay = $item['barangay_name'];
            $philhealth_type = $item['philhealth_type'];
            $count = $item['count'];

            if (!isset($philhealth_by_barangay_grouped[$barangay])) {
                $philhealth_by_barangay_grouped[$barangay] = [];
            }
            $philhealth_by_barangay_grouped[$barangay][$philhealth_type] = $count;
        }

        // Sort barangays by total patients (descending)
        $barangay_philhealth_totals = [];
        foreach ($philhealth_by_barangay_grouped as $barangay => $philhealth_data) {
            $barangay_philhealth_totals[$barangay] = array_sum($philhealth_data);
        }
        arsort($barangay_philhealth_totals);

        foreach ($barangay_philhealth_totals as $barangay => $barangay_philhealth_total):
            $philhealth_data = $philhealth_by_barangay_grouped[$barangay];
        ?>
            <div class="breakdown-section">
                <div class="breakdown-header">Barangay <?= htmlspecialchars($barangay) ?> (Total: <?= number_format($barangay_philhealth_total) ?> patients)</div>
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
                        <?php foreach (['PhilHealth Member', 'Non-Member'] as $status): ?>
                            <?php
                            $count = isset($philhealth_data[$status]) ? $philhealth_data[$status] : 0;
                            $barangay_percentage = $barangay_philhealth_total > 0 ? ($count / $barangay_philhealth_total * 100) : 0;
                            $total_percentage = $total_patients > 0 ? ($count / $total_patients * 100) : 0;
                            ?>
                            <tr>
                                <td><strong><?= $status ?></strong></td>
                                <td><?= number_format($count) ?></td>
                                <td><?= number_format($barangay_percentage, 1) ?>%</td>
                                <td><?= number_format($total_percentage, 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <!-- Report Footer -->
        <div class="report-footer">
            <p>Patient Demographics Comprehensive Report - City Health Office, Koronadal City | Generated: <?= date('F j, Y g:i A') ?></p>
            <p>Total Active Patients: <?= number_format($total_patients) ?> | Report includes ALL <?= count($district_distribution) ?> Districts and ALL <?= count($barangay_distribution) ?> Barangays</p>
        </div>
    </div>
</body>

</html>