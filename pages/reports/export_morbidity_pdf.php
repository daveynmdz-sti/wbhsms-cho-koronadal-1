<?php
// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';
require_once $root_path . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header("Location: ../management/auth/employee_login.php");
    exit();
}

// Define role-based permissions
$allowedRoles = ['admin', 'dho', 'cashier', 'records_officer'];
$userRole = $_SESSION['role'] ?? '';

if (!in_array($userRole, $allowedRoles)) {
    header("Location: ../management/admin/dashboard.php");
    exit();
}

// Get filter parameters (same as main report)
$start_date = $_GET['start_date'] ?? '2025-10-01'; // Set to start of October where data exists
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$district_filter = $_GET['district_filter'] ?? '';
$barangay_filter = $_GET['barangay_filter'] ?? '';
$age_group_filter = $_GET['age_group_filter'] ?? '';
$sex_filter = $_GET['sex_filter'] ?? '';

try {
    // Get filter options for display
    $district_name = '';
    if (!empty($district_filter)) {
        $district_stmt = $pdo->prepare("SELECT district_name FROM districts WHERE district_id = ?");
        $district_stmt->execute([$district_filter]);
        $district_name = $district_stmt->fetchColumn();
    }

    $barangay_name = '';
    if (!empty($barangay_filter)) {
        $barangay_stmt = $pdo->prepare("SELECT barangay_name FROM barangay WHERE barangay_id = ?");
        $barangay_stmt->execute([$barangay_filter]);
        $barangay_name = $barangay_stmt->fetchColumn();
    }

    // Build where clauses for filtering (same logic as main report)
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

    // Execute same queries as main report but limit results for PDF
    
    // 1. Disease Prevalence
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
        LIMIT 15
    ";
    
    // Add parameters for subquery
    $disease_params = $params;
    $disease_params['start_date_sub'] = $start_date;
    $disease_params['end_date_sub'] = $end_date . ' 23:59:59';
    
    $disease_prevalence_stmt = $pdo->prepare($disease_prevalence_query);
    $disease_prevalence_stmt->execute($disease_params);
    $disease_prevalence = $disease_prevalence_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. District Distribution
    $district_distribution_query = "
        SELECT 
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
        GROUP BY d.district_name
        ORDER BY total_cases DESC
    ";
    
    $district_distribution_stmt = $pdo->prepare($district_distribution_query);
    $district_distribution_stmt->execute($params);
    $district_distribution = $district_distribution_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Age Group Summary
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
            COUNT(*) as case_count,
            COUNT(DISTINCT p.patient_id) as patient_count
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        JOIN barangay b ON p.barangay_id = b.barangay_id
        JOIN districts d ON b.district_id = d.district_id
        WHERE $where_clause 
            AND c.diagnosis IS NOT NULL 
            AND c.diagnosis != ''
        GROUP BY age_group
        ORDER BY case_count DESC
    ";
    
    $age_group_stmt = $pdo->prepare($age_group_query);
    $age_group_stmt->execute($params);
    $age_group_summary = $age_group_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Gender Distribution
    $gender_distribution_query = "
        SELECT 
            p.sex,
            COUNT(*) as case_count,
            COUNT(DISTINCT p.patient_id) as patient_count,
            COUNT(DISTINCT c.diagnosis) as unique_diseases
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        JOIN barangay b ON p.barangay_id = b.barangay_id
        JOIN districts d ON b.district_id = d.district_id
        WHERE $where_clause 
            AND c.diagnosis IS NOT NULL 
            AND c.diagnosis != ''
        GROUP BY p.sex
        ORDER BY case_count DESC
    ";
    
    $gender_distribution_stmt = $pdo->prepare($gender_distribution_query);
    $gender_distribution_stmt->execute($params);
    $gender_distribution = $gender_distribution_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Summary Statistics
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

    // 6. CHRONIC ILLNESSES DISTRIBUTION (same as web version)
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

    // 7. AGE GROUP DISEASES (detailed breakdown)
    $age_group_diseases_query = "
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
        HAVING case_count >= 1
        ORDER BY age_group, case_count DESC
    ";
    
    $age_group_diseases_stmt = $pdo->prepare($age_group_diseases_query);
    $age_group_diseases_stmt->execute($params);
    $age_group_diseases = $age_group_diseases_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 8. BARANGAY DISTRIBUTION
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

    // 9. SEASONAL TRENDS
    $seasonal_trends_query = "
        SELECT 
            MONTHNAME(c.consultation_date) as month_name,
            MONTH(c.consultation_date) as month_num,
            c.diagnosis,
            COUNT(*) as case_count
        FROM consultations c
        JOIN patients p ON c.patient_id = p.patient_id
        JOIN barangay b ON p.barangay_id = b.barangay_id
        JOIN districts d ON b.district_id = d.district_id
        WHERE $where_clause 
            AND c.diagnosis IS NOT NULL 
            AND c.diagnosis != ''
        GROUP BY month_name, month_num, c.diagnosis
        HAVING case_count >= 1
        ORDER BY month_num, case_count DESC
    ";
    
    $seasonal_trends_stmt = $pdo->prepare($seasonal_trends_query);
    $seasonal_trends_stmt->execute($params);
    $seasonal_trends = $seasonal_trends_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 10. DETAILED GENDER DISTRIBUTION BY DISEASE
    $gender_diseases_query = "
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
        HAVING case_count >= 1
        ORDER BY p.sex, case_count DESC
    ";
    
    $gender_diseases_stmt = $pdo->prepare($gender_diseases_query);
    $gender_diseases_stmt->execute($params);
    $gender_diseases = $gender_diseases_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Morbidity PDF Export Error: " . $e->getMessage());
    die("Error generating PDF report. Please try again.");
}

// Generate PDF content
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Morbidity Report - CHO Koronadal</title>
    <style>
        @page {
            margin: 20mm;
            size: A4;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
            line-height: 1.4;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #0077b6;
            padding-bottom: 15px;
        }
        
        .logo {
            max-height: 60px;
            margin-bottom: 10px;
        }
        
        .header h1 {
            color: #0077b6;
            margin: 5px 0;
            font-size: 18px;
        }
        
        .header h2 {
            color: #333;
            margin: 3px 0;
            font-size: 14px;
        }
        
        .report-info {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .report-info h3 {
            margin: 0 0 10px 0;
            color: #0077b6;
            font-size: 12px;
        }
        
        .info-grid {
            display: table;
            width: 100%;
        }
        
        .info-row {
            display: table-row;
        }
        
        .info-cell {
            display: table-cell;
            padding: 3px 10px 3px 0;
            vertical-align: top;
        }
        
        .info-label {
            font-weight: bold;
            color: #333;
        }
        
        .summary-cards {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }
        
        .summary-card {
            display: table-cell;
            width: 25%;
            text-align: center;
            padding: 10px;
            border: 1px solid #ddd;
            background: #f8f9fa;
        }
        
        .summary-number {
            font-size: 20px;
            font-weight: bold;
            color: #0077b6;
            margin: 5px 0;
        }
        
        .summary-label {
            font-size: 9px;
            color: #666;
        }
        
        .section {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        
        .section h3 {
            color: #0077b6;
            font-size: 14px;
            margin: 0 0 10px 0;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        th, td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: left;
            font-size: 9px;
        }
        
        th {
            background: #f1f3f4;
            font-weight: bold;
            color: #333;
        }
        
        tr:nth-child(even) {
            background: #fafafa;
        }
        
        .rank {
            background: #0077b6;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 8px;
            font-weight: bold;
        }
        
        .percentage {
            color: #0077b6;
            font-weight: bold;
        }
        
        .footer {
            position: fixed;
            bottom: 10mm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8px;
            color: #666;
        }
        
        .two-column {
            display: table;
            width: 100%;
        }
        
        .column {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            padding-right: 10px;
        }
        
        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>CITY HEALTH OFFICE - KORONADAL</h1>
        <h2>MORBIDITY REPORT</h2>
        <p>Comprehensive Analysis of Disease Patterns and Health Trends</p>
    </div>

    <div class="report-info">
        <h3>Report Information</h3>
        <div class="info-grid">
            <div class="info-row">
                <div class="info-cell info-label">Report Period:</div>
                <div class="info-cell">' . date('F j, Y', strtotime($start_date)) . ' - ' . date('F j, Y', strtotime($end_date)) . '</div>
                <div class="info-cell info-label">Generated:</div>
                <div class="info-cell">' . date('F j, Y g:i A') . '</div>
            </div>
            <div class="info-row">
                <div class="info-cell info-label">District Filter:</div>
                <div class="info-cell">' . ($district_name ? htmlspecialchars($district_name) : 'All Districts') . '</div>
                <div class="info-cell info-label">Generated By:</div>
                <div class="info-cell">' . htmlspecialchars(($_SESSION['employee_first_name'] ?? '') . ' ' . ($_SESSION['employee_last_name'] ?? '')) . '</div>
            </div>
            <div class="info-row">
                <div class="info-cell info-label">Barangay Filter:</div>
                <div class="info-cell">' . ($barangay_name ? htmlspecialchars($barangay_name) : 'All Barangays') . '</div>
                <div class="info-cell info-label">Role:</div>
                <div class="info-cell">' . htmlspecialchars($_SESSION['role']) . '</div>
            </div>
            <div class="info-row">
                <div class="info-cell info-label">Age Group:</div>
                <div class="info-cell">' . ($age_group_filter ? htmlspecialchars($age_group_filter . ' years') : 'All Ages') . '</div>
                <div class="info-cell info-label">Sex Filter:</div>
                <div class="info-cell">' . ($sex_filter ? htmlspecialchars($sex_filter) : 'All') . '</div>
            </div>
        </div>
    </div>

    <div class="summary-cards">
        <div class="summary-card">
            <div class="summary-number">' . number_format($summary_stats['total_consultations']) . '</div>
            <div class="summary-label">Total Consultations</div>
        </div>
        <div class="summary-card">
            <div class="summary-number">' . number_format($summary_stats['total_patients']) . '</div>
            <div class="summary-label">Unique Patients</div>
        </div>
        <div class="summary-card">
            <div class="summary-number">' . number_format($summary_stats['unique_diagnoses']) . '</div>
            <div class="summary-label">Unique Diagnoses</div>
        </div>
        <div class="summary-card">
            <div class="summary-number">' . number_format($summary_stats['affected_barangays']) . '</div>
            <div class="summary-label">Affected Barangays</div>
        </div>
    </div>

    <div class="section">
        <h3>🦠 Disease Prevalence (Top 15 Diagnoses)</h3>
        <p style="margin-bottom: 10px; font-size: 9px; color: #666;">Period: ' . $start_date . ' to ' . $end_date . '</p>
        <table>
            <thead>
                <tr>
                    <th width="8%">Rank</th>
                    <th width="52%">Diagnosis</th>
                    <th width="15%">Cases</th>
                    <th width="15%">Patients</th>
                    <th width="10%">Rate (%)</th>
                </tr>
            </thead>
            <tbody>';

if (!empty($disease_prevalence)) {
    foreach (array_slice($disease_prevalence, 0, 15) as $index => $disease) {
        $html .= '
                <tr>
                    <td><span class="rank">' . ($index + 1) . '</span></td>
                    <td>' . htmlspecialchars($disease['diagnosis']) . '</td>
                    <td>' . number_format($disease['case_count']) . '</td>
                    <td>' . number_format($disease['patient_count']) . '</td>
                    <td class="percentage">' . $disease['prevalence_rate'] . '%</td>
                </tr>';
    }
    
    // Fill remaining rows if less than 15
    for ($i = count($disease_prevalence); $i < 15; $i++) {
        $html .= '
                <tr style="opacity: 0.5;">
                    <td>' . ($i + 1) . '</td>
                    <td style="font-style: italic; color: #999;">No data</td>
                    <td>0</td>
                    <td>0</td>
                    <td>0.00%</td>
                </tr>';
    }
} else {
    // Show 15 empty rows when no data
    for ($i = 1; $i <= 15; $i++) {
        $html .= '
                <tr style="opacity: 0.5;">
                    <td>' . $i . '</td>
                    <td style="font-style: italic; color: #999;">No data available</td>
                    <td>0</td>
                    <td>0</td>
                    <td>0.00%</td>
                </tr>';
    }
}

$html .= '
            </tbody>
        </table>
    </div>

    <div class="two-column">
        <div class="column">
            <div class="section">
                <h3>📊 Distribution by District</h3>
                <table>
                    <thead>
                        <tr>
                            <th>District</th>
                            <th>Cases</th>
                            <th>Diseases</th>
                            <th>Patients</th>
                        </tr>
                    </thead>
                    <tbody>';

if (!empty($district_distribution)) {
    foreach (array_slice($district_distribution, 0, 8) as $district) {
        $html .= '
                        <tr>
                            <td>' . htmlspecialchars($district['district_name']) . '</td>
                            <td>' . number_format($district['total_cases']) . '</td>
                            <td>' . number_format($district['unique_diseases']) . '</td>
                            <td>' . number_format($district['unique_patients']) . '</td>
                        </tr>';
    }
    
    // Fill remaining rows if less than 8
    for ($i = count($district_distribution); $i < 8; $i++) {
        $html .= '
                        <tr style="opacity: 0.5;">
                            <td style="font-style: italic; color: #999;">No data</td>
                            <td>0</td>
                            <td>0</td>
                            <td>0</td>
                        </tr>';
    }
} else {
    // Show 8 empty rows when no data
    for ($i = 1; $i <= 8; $i++) {
        $html .= '
                        <tr style="opacity: 0.5;">
                            <td style="font-style: italic; color: #999;">No district data</td>
                            <td>0</td>
                            <td>0</td>
                            <td>0</td>
                        </tr>';
    }
}

$html .= '
                    </tbody>
                </table>
            </div>
        </div>

        <div class="column">
            <div class="section">
                <h3>👥 Distribution by Age Group</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Age Group</th>
                            <th>Cases</th>
                            <th>Patients</th>
                        </tr>
                    </thead>
                    <tbody>';

// Define all age groups to ensure complete display
$all_age_groups = ['0-5 years', '6-12 years', '13-19 years', '20-39 years', '40-59 years', '60+ years'];
$existing_age_data = [];

if (!empty($age_group_summary)) {
    foreach ($age_group_summary as $age_group) {
        $existing_age_data[$age_group['age_group']] = $age_group;
    }
}

foreach ($all_age_groups as $age_group) {
    if (isset($existing_age_data[$age_group])) {
        $data = $existing_age_data[$age_group];
        $html .= '
                        <tr>
                            <td>' . htmlspecialchars($data['age_group']) . '</td>
                            <td>' . number_format($data['case_count']) . '</td>
                            <td>' . number_format($data['patient_count']) . '</td>
                        </tr>';
    } else {
        $html .= '
                        <tr style="opacity: 0.5;">
                            <td>' . htmlspecialchars($age_group) . '</td>
                            <td>0</td>
                            <td>0</td>
                        </tr>';
    }
}

$html .= '
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="section">
        <h3>⚥ Distribution by Gender</h3>
        <table>
            <thead>
                <tr>
                    <th width="20%">Gender</th>
                    <th width="20%">Total Cases</th>
                    <th width="20%">Unique Patients</th>
                    <th width="20%">Unique Diseases</th>
                    <th width="20%">Percentage</th>
                </tr>
            </thead>
            <tbody>';

// Define both genders to ensure complete display
$genders = ['Male', 'Female'];
$existing_gender_data = [];

if (!empty($gender_distribution)) {
    foreach ($gender_distribution as $gender) {
        $existing_gender_data[$gender['sex']] = $gender;
    }
}

$total_cases = !empty($gender_distribution) ? array_sum(array_column($gender_distribution, 'case_count')) : 0;

foreach ($genders as $gender) {
    if (isset($existing_gender_data[$gender])) {
        $data = $existing_gender_data[$gender];
        $percentage = $total_cases > 0 ? round(($data['case_count'] / $total_cases) * 100, 1) : 0;
        $html .= '
                <tr>
                    <td>' . htmlspecialchars($data['sex']) . '</td>
                    <td>' . number_format($data['case_count']) . '</td>
                    <td>' . number_format($data['patient_count']) . '</td>
                    <td>' . number_format($data['unique_diseases']) . '</td>
                    <td class="percentage">' . $percentage . '%</td>
                </tr>';
    } else {
        $html .= '
                <tr style="opacity: 0.5;">
                    <td>' . htmlspecialchars($gender) . '</td>
                    <td>0</td>
                    <td>0</td>
                    <td>0</td>
                    <td>0.0%</td>
                </tr>';
    }
}

$html .= '
            </tbody>
        </table>
    </div>

    <!-- Chronic Illnesses Section -->
    <div class="section">
        <h3>💔 Chronic Illnesses Distribution</h3>
        <p style="margin-bottom: 10px; font-size: 9px; color: #666;">Chronic conditions by patient prevalence</p>
        <table>
            <thead>
                <tr>
                    <th width="40%">Illness</th>
                    <th width="20%">Patients</th>
                    <th width="20%">Avg Years</th>
                    <th width="20%">Rate (%)</th>
                </tr>
            </thead>
            <tbody>';

if (!empty($chronic_illnesses)) {
    foreach (array_slice($chronic_illnesses, 0, 8) as $illness) {
        $html .= '
                <tr>
                    <td>' . htmlspecialchars($illness['illness']) . '</td>
                    <td>' . number_format($illness['patient_count']) . '</td>
                    <td>' . round($illness['avg_years_diagnosed'], 1) . 'y</td>
                    <td class="percentage">' . $illness['prevalence_rate'] . '%</td>
                </tr>';
    }
    
    // Fill remaining rows if less than 8
    for ($i = count($chronic_illnesses); $i < 8; $i++) {
        $html .= '
                <tr style="opacity: 0.5;">
                    <td style="font-style: italic; color: #999;">No data</td>
                    <td>0</td>
                    <td>0.0y</td>
                    <td>0.00%</td>
                </tr>';
    }
} else {
    // Show 8 empty rows when no data
    for ($i = 1; $i <= 8; $i++) {
        $html .= '
                <tr style="opacity: 0.5;">
                    <td style="font-style: italic; color: #999;">No chronic illness data</td>
                    <td>0</td>
                    <td>0.0y</td>
                    <td>0.00%</td>
                </tr>';
    }
}

$html .= '
            </tbody>
        </table>
    </div>

    <!-- Page Break for Detailed Analysis -->
    <div class="page-break"></div>
    
    <!-- Top Affected Barangays -->
    <div class="section">
        <h3>🗺️ Top Affected Barangays</h3>
        <table>
            <thead>
                <tr>
                    <th width="30%">Barangay</th>
                    <th width="25%">District</th>
                    <th width="15%">Cases</th>
                    <th width="15%">Diseases</th>
                    <th width="15%">Patients</th>
                </tr>
            </thead>
            <tbody>';

if (!empty($barangay_distribution)) {
    foreach (array_slice($barangay_distribution, 0, 10) as $barangay) {
        $html .= '
                <tr>
                    <td>' . htmlspecialchars($barangay['barangay_name']) . '</td>
                    <td>' . htmlspecialchars($barangay['district_name']) . '</td>
                    <td>' . number_format($barangay['total_cases']) . '</td>
                    <td>' . number_format($barangay['unique_diseases']) . '</td>
                    <td>' . number_format($barangay['unique_patients']) . '</td>
                </tr>';
    }
    
    // Fill remaining rows if less than 10
    for ($i = count($barangay_distribution); $i < 10; $i++) {
        $html .= '
                <tr style="opacity: 0.5;">
                    <td style="font-style: italic; color: #999;">No data</td>
                    <td style="font-style: italic; color: #999;">-</td>
                    <td>0</td>
                    <td>0</td>
                    <td>0</td>
                </tr>';
    }
} else {
    // Show 10 empty rows when no data
    for ($i = 1; $i <= 10; $i++) {
        $html .= '
                <tr style="opacity: 0.5;">
                    <td style="font-style: italic; color: #999;">No barangay data</td>
                    <td style="font-style: italic; color: #999;">-</td>
                    <td>0</td>
                    <td>0</td>
                    <td>0</td>
                </tr>';
    }
}

$html .= '
            </tbody>
        </table>
    </div>

    <!-- Age Group Disease Distribution -->
    <div class="section">
        <h3>👥 Disease Distribution by Age Groups</h3>
        <p style="margin-bottom: 10px; font-size: 9px; color: #666;">Detailed breakdown of diseases across all age categories</p>';

// Group age group diseases by age group
$all_age_groups = ['0-5 years', '6-12 years', '13-19 years', '20-39 years', '40-59 years', '60+ years'];
$grouped_by_age = [];

if (!empty($age_group_diseases)) {
    foreach ($age_group_diseases as $disease) {
        $grouped_by_age[$disease['age_group']][] = $disease;
    }
}

$age_chunks = array_chunk($all_age_groups, 2); // 2 age groups per row

foreach ($age_chunks as $age_row) {
    $html .= '<div class="two-column">';
    
    foreach ($age_row as $age_group) {
        $html .= '
            <div class="column">
                <h4 style="color: #0077b6; margin: 10px 0 5px 0; font-size: 11px; border-bottom: 1px solid #ddd; padding-bottom: 3px;">' . htmlspecialchars($age_group) . '</h4>
                <table style="margin-bottom: 10px;">
                    <thead>
                        <tr>
                            <th width="70%">Disease</th>
                            <th width="30%">Cases</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        if (isset($grouped_by_age[$age_group]) && !empty($grouped_by_age[$age_group])) {
            foreach (array_slice($grouped_by_age[$age_group], 0, 5) as $disease) {
                $html .= '
                        <tr>
                            <td>' . htmlspecialchars($disease['diagnosis']) . '</td>
                            <td>' . number_format($disease['case_count']) . '</td>
                        </tr>';
            }
            
            // Fill remaining rows if less than 5
            for ($i = count($grouped_by_age[$age_group]); $i < 5; $i++) {
                $html .= '
                        <tr style="opacity: 0.5;">
                            <td style="font-style: italic; color: #999;">No data</td>
                            <td>0</td>
                        </tr>';
            }
        } else {
            // Show 5 empty rows when no data for this age group
            for ($i = 1; $i <= 5; $i++) {
                $html .= '
                        <tr style="opacity: 0.5;">
                            <td style="font-style: italic; color: #999;">No data available</td>
                            <td>0</td>
                        </tr>';
            }
        }
        
        $html .= '
                    </tbody>
                </table>
            </div>';
    }
    
    $html .= '</div>';
}

$html .= '
    </div>

    <!-- Page Break for Gender Analysis -->
    <div class="page-break"></div>

    <!-- Detailed Gender Analysis -->
    <div class="section">
        <h3>⚥ Detailed Gender Analysis by Disease</h3>
        <p style="margin-bottom: 10px; font-size: 9px; color: #666;">Disease distribution patterns by gender</p>';

// Group gender diseases by gender
$genders = ['Male', 'Female'];
$grouped_by_gender = [];

if (!empty($gender_diseases)) {
    foreach ($gender_diseases as $disease) {
        $grouped_by_gender[$disease['sex']][] = $disease;
    }
}

$html .= '
        <div class="two-column">';

foreach ($genders as $gender) {
    $icon = $gender == 'Male' ? '♂️' : '♀️';
    $html .= '
            <div class="column">
                <h4 style="color: #0077b6; margin: 10px 0 5px 0; font-size: 12px;">' . $icon . ' ' . htmlspecialchars($gender) . '</h4>
                <table style="margin-bottom: 10px;">
                    <thead>
                        <tr>
                            <th width="60%">Disease</th>
                            <th width="20%">Cases</th>
                            <th width="20%">Patients</th>
                        </tr>
                    </thead>
                    <tbody>';
    
    if (isset($grouped_by_gender[$gender]) && !empty($grouped_by_gender[$gender])) {
        foreach (array_slice($grouped_by_gender[$gender], 0, 8) as $disease) {
            $html .= '
                        <tr>
                            <td>' . htmlspecialchars($disease['diagnosis']) . '</td>
                            <td>' . number_format($disease['case_count']) . '</td>
                            <td>' . number_format($disease['patient_count']) . '</td>
                        </tr>';
        }
        
        // Fill remaining rows if less than 8
        for ($i = count($grouped_by_gender[$gender]); $i < 8; $i++) {
            $html .= '
                        <tr style="opacity: 0.5;">
                            <td style="font-style: italic; color: #999;">No data</td>
                            <td>0</td>
                            <td>0</td>
                        </tr>';
        }
    } else {
        // Show 8 empty rows when no data for this gender
        for ($i = 1; $i <= 8; $i++) {
            $html .= '
                        <tr style="opacity: 0.5;">
                            <td style="font-style: italic; color: #999;">No data available</td>
                            <td>0</td>
                            <td>0</td>
                        </tr>';
        }
    }
    
    $html .= '
                    </tbody>
                </table>
            </div>';
}

$html .= '
        </div>
    </div>

    <!-- Seasonal Trends Analysis -->
    <div class="section">
        <h3>📅 Seasonal Disease Trends</h3>
        <p style="margin-bottom: 10px; font-size: 9px; color: #666;">Monthly disease patterns showing all 12 months</p>';

// Group seasonal trends by month
$all_months = ['January', 'February', 'March', 'April', 'May', 'June', 
              'July', 'August', 'September', 'October', 'November', 'December'];
$grouped_by_month = [];

if (!empty($seasonal_trends)) {
    foreach ($seasonal_trends as $trend) {
        $grouped_by_month[$trend['month_name']][] = $trend;
    }
}

// Display months in 3x4 grid
$month_chunks = array_chunk($all_months, 3);

foreach ($month_chunks as $chunk_index => $month_row) {
    $html .= '<div style="display: table; width: 100%; margin-bottom: 10px;">';
    
    foreach ($month_row as $month) {
        $html .= '
            <div style="display: table-cell; width: 33.33%; padding-right: 8px; vertical-align: top;">
                <h5 style="color: #0077b6; margin: 3px 0; font-size: 9px; border-bottom: 1px solid #ddd; padding-bottom: 2px;">📅 ' . htmlspecialchars($month) . '</h5>
                <table style="font-size: 8px;">
                    <thead>
                        <tr>
                            <th style="padding: 2px;">Disease</th>
                            <th style="padding: 2px;">Cases</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        if (isset($grouped_by_month[$month]) && !empty($grouped_by_month[$month])) {
            foreach (array_slice($grouped_by_month[$month], 0, 3) as $disease) {
                $disease_name = strlen($disease['diagnosis']) > 20 ? substr($disease['diagnosis'], 0, 17) . '...' : $disease['diagnosis'];
                $html .= '
                        <tr>
                            <td style="padding: 2px;">' . htmlspecialchars($disease_name) . '</td>
                            <td style="padding: 2px;">' . number_format($disease['case_count']) . '</td>
                        </tr>';
            }
            
            // Fill remaining rows if less than 3
            for ($i = count($grouped_by_month[$month]); $i < 3; $i++) {
                $html .= '
                        <tr style="opacity: 0.5;">
                            <td style="padding: 2px; font-style: italic; color: #999;">No data</td>
                            <td style="padding: 2px;">0</td>
                        </tr>';
            }
        } else {
            // Show 3 empty rows when no data for this month
            for ($i = 1; $i <= 3; $i++) {
                $html .= '
                        <tr style="opacity: 0.5;">
                            <td style="padding: 2px; font-style: italic; color: #999;">No data</td>
                            <td style="padding: 2px;">0</td>
                        </tr>';
            }
        }
        
        $html .= '
                    </tbody>
                </table>
            </div>';
    }
    
    $html .= '</div>';
    
    // Add page break after every 2 rows of months for better formatting
    if ($chunk_index == 1) {
        $html .= '<div class="page-break"></div>';
    }
}

$html .= '
    </div>

    <div class="footer">
        <p>City Health Office - Koronadal | Comprehensive Morbidity Report | Generated on ' . date('F j, Y g:i A') . ' | Page {PAGE_NUM} of {PAGE_COUNT}</p>
        <p>This report contains confidential health information. Handle according to data privacy regulations.</p>
    </div>
</body>
</html>';

// Configure Dompdf
$options = new Options();
$options->set('defaultFont', 'Arial');
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Generate filename
$filename = 'Morbidity_Report_' . date('Y-m-d_H-i-s') . '.pdf';
if (!empty($district_name)) {
    $filename = 'Morbidity_Report_' . str_replace(' ', '_', $district_name) . '_' . date('Y-m-d_H-i-s') . '.pdf';
}

// Output PDF
$dompdf->stream($filename, array('Attachment' => true));
?>