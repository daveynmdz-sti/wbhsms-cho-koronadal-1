<?php
// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';
require_once $root_path . '/vendor/autoload.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header("Location: ../management/auth/employee_login.php");
    exit();
}

// Define role-based permissions
$allowedRoles = ['admin', 'dho', 'cashier', 'records_officer'];
$userRole = $_SESSION['role'] ?? '';

if (!in_array($userRole, $allowedRoles)) {
    http_response_code(403);
    exit('Access denied');
}

use Dompdf\Dompdf;
use Dompdf\Options;

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$status_filter = $_GET['status_filter'] ?? '';
$medication_filter = $_GET['medication_filter'] ?? '';

try {
    // Build where clauses for filtering
    $where_conditions = ["DATE(pm.updated_at) BETWEEN :start_date AND :end_date"];
    $params = [
        'start_date' => $start_date,
        'end_date' => $end_date
    ];

    if (!empty($status_filter) && in_array($status_filter, ['dispensed', 'unavailable'])) {
        $where_conditions[] = "pm.status = :status_filter";
        $params['status_filter'] = $status_filter;
    }

    if (!empty($medication_filter)) {
        $where_conditions[] = "pm.medication_name LIKE :medication_filter";
        $params['medication_filter'] = '%' . $medication_filter . '%';
    }

    $where_clause = implode(' AND ', $where_conditions);

    // 1. DISPENSING SUMMARY STATISTICS
    $summary_query = "
        SELECT 
            COUNT(DISTINCT pm.prescribed_medication_id) as total_medications,
            COUNT(DISTINCT p.prescription_id) as total_prescriptions,
            SUM(CASE WHEN pm.status = 'dispensed' THEN 1 ELSE 0 END) as dispensed_count,
            SUM(CASE WHEN pm.status = 'unavailable' THEN 1 ELSE 0 END) as unavailable_count,
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
            COUNT(*) as count
        FROM prescribed_medications pm
        JOIN prescriptions p ON pm.prescription_id = p.prescription_id
        WHERE $where_clause AND pm.status IN ('dispensed', 'unavailable')
        GROUP BY pm.status
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

    // 3. TOP DISPENSED MEDICATIONS
    $top_medications_query = "
        SELECT 
            pm.medication_name,
            COUNT(*) as prescription_count,
            SUM(CASE WHEN pm.status = 'dispensed' THEN 1 ELSE 0 END) as dispensed_count,
            SUM(CASE WHEN pm.status = 'unavailable' THEN 1 ELSE 0 END) as unavailable_count,
            ROUND((SUM(CASE WHEN pm.status = 'dispensed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as fulfillment_rate
        FROM prescribed_medications pm
        JOIN prescriptions p ON pm.prescription_id = p.prescription_id
        WHERE $where_clause AND pm.status IN ('dispensed', 'unavailable')
        GROUP BY pm.medication_name
        ORDER BY prescription_count DESC
        LIMIT 20
    ";
    
    $top_medications_stmt = $pdo->prepare($top_medications_query);
    $top_medications_stmt->execute($params);
    $top_medications = $top_medications_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. DAILY DISPENSING TRENDS
    $daily_trends_query = "
        SELECT 
            DATE(pm.updated_at) as dispensing_date,
            COUNT(*) as total_actions,
            SUM(CASE WHEN pm.status = 'dispensed' THEN 1 ELSE 0 END) as dispensed_count,
            SUM(CASE WHEN pm.status = 'unavailable' THEN 1 ELSE 0 END) as unavailable_count
        FROM prescribed_medications pm
        JOIN prescriptions p ON pm.prescription_id = p.prescription_id
        WHERE $where_clause AND pm.status IN ('dispensed', 'unavailable')
        GROUP BY DATE(pm.updated_at)
        ORDER BY dispensing_date DESC
        LIMIT 30
    ";
    
    $daily_trends_stmt = $pdo->prepare($daily_trends_query);
    $daily_trends_stmt->execute($params);
    $daily_trends = $daily_trends_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. RECENT DISPENSING ACTIVITIES
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
        WHERE $where_clause AND pm.status IN ('dispensed', 'unavailable')
        ORDER BY pm.updated_at DESC
        LIMIT 50
    ";
    
    $recent_activities_stmt = $pdo->prepare($recent_activities_query);
    $recent_activities_stmt->execute($params);
    $recent_activities = $recent_activities_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. UNAVAILABLE MEDICATIONS ANALYSIS
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

    // Handle zero division and ensure data consistency
    if (!$summary_stats || !$summary_stats['total_medications']) {
        $summary_stats = [
            'total_medications' => 0,
            'total_prescriptions' => 0,
            'dispensed_count' => 0,
            'unavailable_count' => 0,
            'dispensing_rate' => 0
        ];
    }

} catch (Exception $e) {
    error_log("Dispensed PDF Export Error: " . $e->getMessage());
    http_response_code(500);
    exit('Error generating report');
}

// Get current user info for report attribution
$employee_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$employee_role = ucfirst($_SESSION['role']);

// Generate PDF content
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Dispensed Logs Report - CHO Koronadal</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #0077b6;
            padding-bottom: 15px;
        }
        
        .header h1 {
            font-size: 18px;
            color: #0077b6;
            margin: 0 0 5px 0;
            font-weight: bold;
        }
        
        .header h2 {
            font-size: 14px;
            color: #03045e;
            margin: 0 0 10px 0;
            font-weight: normal;
        }
        
        .header .report-info {
            font-size: 10px;
            color: #666;
            margin-top: 10px;
        }
        
        .section {
            margin-bottom: 25px;
            break-inside: avoid;
        }
        
        .section-title {
            font-size: 13px;
            font-weight: bold;
            color: #03045e;
            margin-bottom: 10px;
            padding: 8px 0;
            border-bottom: 1px solid #ddd;
        }
        
        .summary-grid {
            display: table;
            width: 100%;
            margin-bottom: 15px;
        }
        
        .summary-row {
            display: table-row;
        }
        
        .summary-cell {
            display: table-cell;
            padding: 8px;
            border: 1px solid #ddd;
            text-align: center;
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .summary-cell.label {
            background: #0077b6;
            color: white;
            font-size: 10px;
        }
        
        .summary-cell.value {
            font-size: 14px;
            color: #03045e;
        }
        
        .narrative {
            background: #f8f9fa;
            padding: 15px;
            border-left: 4px solid #0077b6;
            margin: 15px 0;
            font-size: 10px;
            line-height: 1.6;
        }
        
        .narrative h4 {
            margin: 0 0 8px 0;
            color: #03045e;
            font-size: 11px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 9px;
        }
        
        table th {
            background: #0077b6;
            color: white;
            padding: 6px 4px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #ddd;
        }
        
        table td {
            padding: 5px 4px;
            border: 1px solid #ddd;
            text-align: left;
        }
        
        table tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 8px;
            font-weight: bold;
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
        
        .highlight {
            background: #fff3cd;
            color: #664d03;
            padding: 2px 4px;
            border-radius: 3px;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 9px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        
        .page-break {
            page-break-before: always;
        }
        
        .two-column {
            display: table;
            width: 100%;
        }
        
        .column {
            display: table-cell;
            width: 48%;
            vertical-align: top;
            padding-right: 15px;
        }
        
        .column:last-child {
            padding-right: 0;
            padding-left: 15px;
        }
        
        .metric {
            text-align: center;
            margin: 5px 0;
        }
        
        .metric-value {
            font-size: 16px;
            font-weight: bold;
            color: #0077b6;
        }
        
        .metric-label {
            font-size: 9px;
            color: #666;
            text-transform: uppercase;
        }
    </style>
</head>
<body>

<div class="header">
    <h1>City Health Office - Koronadal</h1>
    <h2>Medication Dispensing Logs Report</h2>
    <div class="report-info">
        Report Period: ' . date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date)) . '<br>
        Generated on: ' . date('F j, Y \a\t g:i A') . '<br>
        Generated by: ' . htmlspecialchars($employee_name) . ' (' . htmlspecialchars($employee_role) . ')
    </div>
</div>

<div class="section">
    <div class="section-title">Executive Summary</div>
    <div class="narrative">
        <h4>Report Overview</h4>
        <p>This comprehensive dispensing logs report provides detailed insights into pharmaceutical operations at the City Health Office - Koronadal for the period of <strong>' . date('F j, Y', strtotime($start_date)) . '</strong> to <strong>' . date('F j, Y', strtotime($end_date)) . '</strong>. The report analyzes medication dispensing activities, prescription fulfillment rates, inventory availability, and pharmaceutical service delivery performance.</p>
        
        <p><strong>Key Performance Indicators:</strong> During this reporting period, the pharmacy processed <strong>' . number_format($summary_stats['total_prescriptions']) . ' prescriptions</strong> involving <strong>' . number_format($summary_stats['total_medications']) . ' individual medications</strong>. The overall dispensing success rate achieved was <strong>' . number_format($summary_stats['dispensing_rate'], 1) . '%</strong>, with <strong>' . number_format($summary_stats['dispensed_count']) . ' medications successfully dispensed</strong> and <strong>' . number_format($summary_stats['unavailable_count']) . ' medications marked as unavailable</strong> due to stock limitations.</p>
        
        <p><strong>Operational Impact:</strong> This data reflects the pharmacy\'s capacity to meet patient medication needs and identifies opportunities for inventory management improvements. High unavailability rates for specific medications may indicate supply chain challenges or increased demand that requires attention from healthcare administrators.</p>
    </div>
</div>

<div class="section">
    <div class="section-title">Performance Metrics Dashboard</div>
    <div class="summary-grid">
        <div class="summary-row">
            <div class="summary-cell label">Total Medications</div>
            <div class="summary-cell label">Total Prescriptions</div>
            <div class="summary-cell label">Successfully Dispensed</div>
            <div class="summary-cell label">Unavailable Items</div>
            <div class="summary-cell label">Success Rate</div>
        </div>
        <div class="summary-row">
            <div class="summary-cell value">' . number_format($summary_stats['total_medications']) . '</div>
            <div class="summary-cell value">' . number_format($summary_stats['total_prescriptions']) . '</div>
            <div class="summary-cell value">' . number_format($summary_stats['dispensed_count']) . '</div>
            <div class="summary-cell value">' . number_format($summary_stats['unavailable_count']) . '</div>
            <div class="summary-cell value">' . number_format($summary_stats['dispensing_rate'], 1) . '%</div>
        </div>
    </div>
</div>

<div class="section">
    <div class="section-title">Dispensing Status Analysis</div>
    <div class="narrative">
        <h4>Status Distribution Overview</h4>
        <p>The following analysis breaks down medication dispensing outcomes by status categories. This distribution helps identify overall pharmaceutical service effectiveness and highlights areas requiring inventory management attention.</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th width="30%">Dispensing Status</th>
                <th width="20%">Count</th>
                <th width="20%">Percentage</th>
                <th width="30%">Clinical Impact</th>
            </tr>
        </thead>
        <tbody>';

// Add status breakdown data
$all_statuses = ['dispensed', 'unavailable'];
$existing_status_data = [];

if (!empty($status_breakdown)) {
    foreach ($status_breakdown as $status) {
        $existing_status_data[$status['status']] = $status;
    }
}

foreach ($all_statuses as $status_name) {
    if (isset($existing_status_data[$status_name])) {
        $status_data = $existing_status_data[$status_name];
        $badge_class = 'status-' . $status_name;
        $impact = $status_name == 'dispensed' ? 'Successful patient treatment support' : 'Potential treatment delays, requires restocking';
        
        $html .= '
            <tr>
                <td><span class="status-badge ' . $badge_class . '">' . ucfirst($status_data['status']) . '</span></td>
                <td><strong>' . number_format($status_data['count']) . '</strong></td>
                <td><strong>' . $status_data['percentage'] . '%</strong></td>
                <td>' . $impact . '</td>
            </tr>';
    } else {
        $badge_class = 'status-' . $status_name;
        $impact = $status_name == 'dispensed' ? 'Successful patient treatment support' : 'Potential treatment delays, requires restocking';
        
        $html .= '
            <tr style="opacity: 0.6;">
                <td><span class="status-badge ' . $badge_class . '">' . ucfirst($status_name) . '</span></td>
                <td>0</td>
                <td>0.00%</td>
                <td>' . $impact . '</td>
            </tr>';
    }
}

$html .= '
        </tbody>
    </table>
</div>

<div class="section">
    <div class="section-title">Top Prescribed Medications Analysis</div>
    <div class="narrative">
        <h4>Medication Demand Patterns</h4>
        <p>This section identifies the most frequently prescribed medications and their fulfillment rates. Understanding these patterns helps optimize inventory planning and ensures adequate stock levels for high-demand pharmaceuticals. Medications with low fulfillment rates require immediate attention to prevent patient care disruptions.</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th width="35%">Medication Name</th>
                <th width="15%">Total Prescribed</th>
                <th width="15%">Successfully Dispensed</th>
                <th width="15%">Unavailable Count</th>
                <th width="20%">Fulfillment Rate</th>
            </tr>
        </thead>
        <tbody>';

if (!empty($top_medications)) {
    foreach (array_slice($top_medications, 0, 15) as $medication) {
        $fulfillment_class = $medication['fulfillment_rate'] >= 90 ? 'highlight' : '';
        $html .= '
            <tr>
                <td><strong>' . htmlspecialchars($medication['medication_name']) . '</strong></td>
                <td>' . number_format($medication['prescription_count']) . '</td>
                <td>' . number_format($medication['dispensed_count']) . '</td>
                <td>' . number_format($medication['unavailable_count']) . '</td>
                <td><span class="' . $fulfillment_class . '">' . $medication['fulfillment_rate'] . '%</span></td>
            </tr>';
    }
    
    // Fill remaining rows to show comprehensive view
    for ($i = count($top_medications); $i < 15; $i++) {
        $html .= '
            <tr style="opacity: 0.4;">
                <td style="font-style: italic;">No additional medication data</td>
                <td>0</td>
                <td>0</td>
                <td>0</td>
                <td>0.00%</td>
            </tr>';
    }
} else {
    // Show empty rows when no data
    for ($i = 1; $i <= 15; $i++) {
        $html .= '
            <tr style="opacity: 0.4;">
                <td style="font-style: italic;">No medication data available</td>
                <td>0</td>
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

<div class="page-break"></div>

<div class="section">
    <div class="section-title">Daily Dispensing Trends Analysis</div>
    <div class="narrative">
        <h4>Temporal Distribution Insights</h4>
        <p>Daily dispensing trends reveal patterns in pharmaceutical service delivery and help identify peak demand periods. This analysis supports staffing decisions, inventory planning, and operational efficiency improvements. Days with high unavailability rates may indicate supply chain disruptions or unexpected demand surges.</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th width="25%">Date</th>
                <th width="20%">Total Actions</th>
                <th width="20%">Dispensed</th>
                <th width="20%">Unavailable</th>
                <th width="15%">Success Rate</th>
            </tr>
        </thead>
        <tbody>';

if (!empty($daily_trends)) {
    foreach (array_slice($daily_trends, 0, 20) as $trend) {
        $success_rate = $trend['total_actions'] > 0 ? round(($trend['dispensed_count'] / $trend['total_actions']) * 100, 2) : 0;
        $rate_class = $success_rate >= 90 ? 'highlight' : '';
        
        $html .= '
            <tr>
                <td>' . date('M j, Y (D)', strtotime($trend['dispensing_date'])) . '</td>
                <td>' . number_format($trend['total_actions']) . '</td>
                <td>' . number_format($trend['dispensed_count']) . '</td>
                <td>' . number_format($trend['unavailable_count']) . '</td>
                <td><span class="' . $rate_class . '">' . $success_rate . '%</span></td>
            </tr>';
    }
    
    // Fill remaining rows
    for ($i = count($daily_trends); $i < 20; $i++) {
        $html .= '
            <tr style="opacity: 0.4;">
                <td style="font-style: italic;">No data available</td>
                <td>0</td>
                <td>0</td>
                <td>0</td>
                <td>0.00%</td>
            </tr>';
    }
} else {
    for ($i = 1; $i <= 20; $i++) {
        $html .= '
            <tr style="opacity: 0.4;">
                <td style="font-style: italic;">No trend data available</td>
                <td>0</td>
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

<div class="section">
    <div class="section-title">Unavailable Medications Critical Analysis</div>
    <div class="narrative">
        <h4>Inventory Management Priorities</h4>
        <p>This critical analysis identifies medications frequently marked as unavailable, representing immediate inventory management priorities. These items pose the highest risk for patient care disruptions and require urgent procurement attention. The frequency and timing of unavailability incidents help determine ordering patterns and safety stock levels.</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th width="30%">Medication Name</th>
                <th width="20%">Dosage/Strength</th>
                <th width="15%">Times Unavailable</th>
                <th width="17.5%">First Occurrence</th>
                <th width="17.5%">Last Occurrence</th>
            </tr>
        </thead>
        <tbody>';

if (!empty($unavailable_analysis)) {
    foreach (array_slice($unavailable_analysis, 0, 15) as $unavailable) {
        $html .= '
            <tr>
                <td><strong>' . htmlspecialchars($unavailable['medication_name']) . '</strong></td>
                <td>' . htmlspecialchars($unavailable['dosage']) . '</td>
                <td><span class="highlight">' . number_format($unavailable['unavailable_count']) . '</span></td>
                <td>' . date('M j, Y', strtotime($unavailable['first_unavailable'])) . '</td>
                <td>' . date('M j, Y', strtotime($unavailable['last_unavailable'])) . '</td>
            </tr>';
    }
    
    // Fill remaining rows
    for ($i = count($unavailable_analysis); $i < 15; $i++) {
        $html .= '
            <tr style="opacity: 0.4;">
                <td style="font-style: italic;">No additional data</td>
                <td>-</td>
                <td>0</td>
                <td>-</td>
                <td>-</td>
            </tr>';
    }
} else {
    for ($i = 1; $i <= 15; $i++) {
        $html .= '
            <tr style="opacity: 0.4;">
                <td style="font-style: italic;">No unavailable medications data</td>
                <td>-</td>
                <td>0</td>
                <td>-</td>
                <td>-</td>
            </tr>';
    }
}

$html .= '
        </tbody>
    </table>
</div>

<div class="page-break"></div>

<div class="section">
    <div class="section-title">Recent Dispensing Activities Log</div>
    <div class="narrative">
        <h4>Detailed Transaction History</h4>
        <p>This comprehensive log provides a detailed view of recent medication dispensing activities, including patient information, prescribing healthcare providers, and medication details. This audit trail supports quality assurance, regulatory compliance, and clinical decision-making processes.</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th width="15%">Date/Time</th>
                <th width="20%">Patient Name</th>
                <th width="25%">Medication</th>
                <th width="15%">Dosage</th>
                <th width="10%">Status</th>
                <th width="15%">Prescribed By</th>
            </tr>
        </thead>
        <tbody>';

if (!empty($recent_activities)) {
    foreach (array_slice($recent_activities, 0, 25) as $activity) {
        $badge_class = 'status-' . $activity['status'];
        
        $html .= '
            <tr>
                <td>' . date('M j, g:i A', strtotime($activity['updated_at'])) . '</td>
                <td>' . htmlspecialchars($activity['patient_name']) . '</td>
                <td>' . htmlspecialchars($activity['medication_name']) . '</td>
                <td>' . htmlspecialchars($activity['dosage']) . '</td>
                <td><span class="status-badge ' . $badge_class . '">' . ucfirst($activity['status']) . '</span></td>
                <td>' . htmlspecialchars($activity['prescribed_by']) . '</td>
            </tr>';
    }
    
    // Fill remaining rows
    for ($i = count($recent_activities); $i < 25; $i++) {
        $html .= '
            <tr style="opacity: 0.4;">
                <td style="font-style: italic;">No data</td>
                <td>-</td>
                <td>-</td>
                <td>-</td>
                <td><span class="status-badge status-unavailable">No Status</span></td>
                <td>-</td>
            </tr>';
    }
} else {
    for ($i = 1; $i <= 25; $i++) {
        $html .= '
            <tr style="opacity: 0.4;">
                <td style="font-style: italic;">No activity data</td>
                <td>-</td>
                <td>-</td>
                <td>-</td>
                <td><span class="status-badge status-unavailable">No Status</span></td>
                <td>-</td>
            </tr>';
    }
}

$html .= '
        </tbody>
    </table>
</div>

<div class="section">
    <div class="section-title">Recommendations and Action Items</div>
    <div class="narrative">
        <h4>Strategic Recommendations</h4>
        <p><strong>Inventory Management:</strong> Based on the analysis, medications with fulfillment rates below 85% require immediate inventory review and procurement planning. Establish minimum stock levels for high-demand medications to prevent patient care disruptions.</p>
        
        <p><strong>Supply Chain Optimization:</strong> Implement predictive inventory management based on historical dispensing patterns. Consider establishing relationships with multiple suppliers for critical medications to ensure consistent availability.</p>
        
        <p><strong>Quality Assurance:</strong> Continue monitoring dispensing rates and unavailability trends. Implement regular inventory audits and staff training on proper medication handling and documentation procedures.</p>
        
        <p><strong>Patient Care Continuity:</strong> For medications frequently marked as unavailable, consider developing therapeutic alternatives protocols or patient notification systems to minimize treatment interruptions.</p>
        
        <h4>Performance Monitoring</h4>
        <p>The current dispensing success rate of <strong>' . number_format($summary_stats['dispensing_rate'], 1) . '%</strong> indicates ' . 
        ($summary_stats['dispensing_rate'] >= 90 ? 'excellent pharmaceutical service delivery. Continue current practices while monitoring for any declining trends.' : 
        ($summary_stats['dispensing_rate'] >= 75 ? 'acceptable performance with room for improvement. Focus on reducing unavailability incidents through better inventory management.' : 
        'concerning performance requiring immediate intervention. Conduct comprehensive inventory review and implement emergency procurement procedures.')) . '</p>
    </div>
</div>

<div class="footer">
    <p><strong>City Health Office - Koronadal | Medication Dispensing Logs Report</strong></p>
    <p>This report is generated for internal use and contains confidential patient and operational information.</p>
    <p>Report generated on ' . date('F j, Y \a\t g:i A') . ' | Page 1 of 1</p>
</div>

</body>
</html>';

// Configure Dompdf
$options = new Options();
$options->set('chroot', realpath(''));
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Arial');
$options->set('dpi', 96);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Set filename
$filename = 'Dispensed_Logs_Report_' . date('Y-m-d', strtotime($start_date)) . '_to_' . date('Y-m-d', strtotime($end_date)) . '.pdf';

// Output PDF
$dompdf->stream($filename, array('Attachment' => 1));
?>