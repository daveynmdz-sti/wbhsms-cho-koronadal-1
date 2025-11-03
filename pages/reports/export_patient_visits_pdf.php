<?php
// Create HTML5 stub class first to satisfy Dompdf dependency
if (!class_exists('Masterminds\HTML5')) {
    eval('namespace Masterminds { class HTML5 { 
        private $options; 
        public function __construct($options = []) { $this->options = $options; } 
        public function loadHTML($html) { 
            $dom = new \DOMDocument("1.0", "UTF-8"); 
            $dom->preserveWhiteSpace = false; 
            libxml_use_internal_errors(true); 
            $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD); 
            libxml_clear_errors(); 
            return $dom; 
        } 
        public function saveHTML($dom) { 
            return $dom instanceof \DOMDocument ? $dom->saveHTML() : ""; 
        } 
    } }');
}

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if Dompdf is available
if (!file_exists($root_path . '/vendor/autoload.php')) {
    die('Error: PDF library not found. Please install Dompdf via Composer.');
}

require_once $root_path . '/vendor/autoload.php';

// Import Dompdf classes
use Dompdf\Dompdf;
use Dompdf\Options;

// Note: GD extension is not required for this PDF export as we use text-based logo fallback

// Check if user is logged in and update activity
if (!is_employee_logged_in()) {
    header("Location: ../management/auth/employee_login.php?reason=session_expired");
    exit();
}

// Update session activity
update_employee_activity();

// Define role-based permissions
$allowedRoles = ['admin', 'dho', 'cashier', 'records_officer'];
$userRole = $_SESSION['role'] ?? '';

if (!in_array($userRole, $allowedRoles)) {
    $role = strtolower($userRole);
    header("Location: ../management/$role/dashboard.php");
    exit();
}

// Get filter parameters from URL
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

    // Get facility name if filtering
    $facility_name = 'All Facilities';
    if ($facility_filter) {
        $stmt = $pdo->prepare("SELECT name FROM facilities WHERE facility_id = ?");
        $stmt->execute([$facility_filter]);
        $facility_result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($facility_result) {
            $facility_name = $facility_result['name'];
        }
    }

} catch (Exception $e) {
    error_log("Patient Visits PDF Export Error: " . $e->getMessage());
    die("Error generating report: " . $e->getMessage());
}

// Generate the HTML content for PDF
$completion_rate = $visit_summary['total_visits'] > 0 
    ? round(($visit_summary['completed_visits'] / $visit_summary['total_visits']) * 100, 1)
    : 0;

$avg_duration = $visit_summary['avg_visit_duration'] ?? 0;
$avg_duration_display = $avg_duration > 0 ? round($avg_duration) . ' minutes' : 'N/A';

$html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Visits Report - CHO Koronadal</title>
    <style>
        body {
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 10px;
            line-height: 1.3;
            margin: 0;
            padding: 15px;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #0077b6;
            padding-bottom: 15px;
        }
        
        .logo {
            width: 60px;
            height: 60px;
            margin: 0 auto 10px;
        }
        
        .header h1 {
            color: #0077b6;
            font-size: 18px;
            margin: 8px 0;
            font-weight: bold;
        }
        
        .header h2 {
            color: #666;
            font-size: 14px;
            margin: 3px 0;
            font-weight: normal;
        }
        
        .report-info {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 3px;
            margin-bottom: 15px;
            border-left: 3px solid #0077b6;
            font-size: 9px;
        }
        
        .report-info h3 {
            color: #0077b6;
            margin: 0 0 8px 0;
            font-size: 12px;
        }
        
        .info-row {
            display: table;
            width: 100%;
            margin-bottom: 3px;
        }
        
        .info-label {
            display: table-cell;
            width: 25%;
            font-weight: bold;
            color: #555;
        }
        
        .info-value {
            display: table-cell;
            color: #333;
        }
        
        .section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        
        .section-title {
            color: #0077b6;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 8px;
            padding-bottom: 3px;
            border-bottom: 1px solid #ddd;
        }
        
        .section-narrative {
            background: #f0f8ff;
            padding: 8px;
            margin-bottom: 12px;
            border-radius: 3px;
            font-size: 9px;
            color: #444;
            line-height: 1.4;
            border-left: 3px solid #0077b6;
        }
        
        .stats-grid {
            display: table;
            width: 100%;
            margin-bottom: 15px;
        }
        
        .stats-row {
            display: table-row;
        }
        
        .stat-card {
            display: table-cell;
            width: 25%;
            background: #f8f9fa;
            border: 1px solid #ddd;
            padding: 10px;
            text-align: center;
            vertical-align: top;
        }
        
        .stat-title {
            font-size: 9px;
            color: #666;
            margin-bottom: 4px;
            font-weight: bold;
        }
        
        .stat-value {
            font-size: 16px;
            font-weight: bold;
            color: #0077b6;
            margin-bottom: 4px;
        }
        
        .stat-change {
            font-size: 8px;
            color: #666;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 9px;
        }
        
        .data-table th {
            background: #0077b6;
            color: white;
            padding: 6px 4px;
            text-align: left;
            font-weight: bold;
            border: 1px solid #ddd;
            font-size: 9px;
        }
        
        .data-table td {
            padding: 4px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        
        .data-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .compact-table {
            font-size: 8px;
        }
        
        .compact-table th,
        .compact-table td {
            padding: 3px;
        }
        
        .no-data {
            text-align: center;
            color: #666;
            padding: 15px;
            font-style: italic;
            background: #f8f9fa;
            border-radius: 3px;
            font-size: 9px;
        }
        
        .page-break {
            page-break-before: always;
        }
        
        .footer {
            position: fixed;
            bottom: 15px;
            right: 15px;
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
            padding-right: 10px;
            vertical-align: top;
        }
        
        .column:last-child {
            padding-right: 0;
        }
        
        .three-column {
            display: table;
            width: 100%;
        }
        
        .three-column .column {
            width: 33.33%;
            padding-right: 8px;
        }
        
        .insights-box {
            background: #e8f4f8;
            padding: 8px;
            margin: 8px 0;
            border-radius: 3px;
            font-size: 9px;
            border-left: 3px solid #0077b6;
        }
        
        .highlight {
            background: #fff3cd;
            padding: 2px 4px;
            border-radius: 2px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">
            <div style="width: 60px; height: 60px; border: 2px solid #0077b6; border-radius: 50%; display: flex; align-items: center; justify-content: center; background-color: #f0f8ff; color: #0077b6; font-weight: bold; font-size: 24px;">CHO</div>
        </div>
        <h1>CITY HEALTH OFFICE - KORONADAL</h1>
        <h2>Patient Visits Analytics Report</h2>
    </div>
    
    <div class="report-info">
        <h3>Report Information</h3>
        <div class="info-row">
            <div class="info-label">Report Period:</div>
            <div class="info-value">' . date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date)) . '</div>
        </div>
        <div class="info-row">
            <div class="info-label">Facility Filter:</div>
            <div class="info-value">' . htmlspecialchars($facility_name) . '</div>
        </div>
        <div class="info-row">
            <div class="info-label">Generated On:</div>
            <div class="info-value">' . date('F j, Y \a\t g:i A') . '</div>
        </div>
        <div class="info-row">
            <div class="info-label">Generated By:</div>
            <div class="info-value">' . htmlspecialchars(get_employee_session('full_name') ?: 'System Administrator') . '</div>
        </div>
    </div>
    
    <div class="section">
        <div class="section-title">Key Performance Indicators</div>
        <div class="section-narrative">
            <strong>Executive Summary:</strong> These key metrics provide an immediate overview of patient visit performance during the reporting period. 
            Total visits indicate overall healthcare demand, while completion rates reflect operational efficiency. 
            Cancelled visits may signal appointment system issues or patient accessibility challenges. 
            Average visit duration helps assess workflow efficiency and resource utilization.
        </div>
        <div class="stats-grid">
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-title">Total Visits</div>
                    <div class="stat-value">' . number_format($visit_summary['total_visits'] ?? 0) . '</div>
                    <div class="stat-change">All patient visits in period</div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Completed Visits</div>
                    <div class="stat-value">' . number_format($visit_summary['completed_visits'] ?? 0) . '</div>
                    <div class="stat-change">' . $completion_rate . '% completion rate</div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Cancelled Visits</div>
                    <div class="stat-value">' . number_format($visit_summary['cancelled_visits'] ?? 0) . '</div>
                    <div class="stat-change">Did not complete</div>
                </div>
                <div class="stat-card">
                    <div class="stat-title">Avg Duration</div>
                    <div class="stat-value">' . ($avg_duration > 0 ? round($avg_duration) . 'm' : 'N/A') . '</div>
                    <div class="stat-change">Average visit time</div>
                </div>
            </div>
        </div>';

if ($completion_rate < 80) {
    $html .= '<div class="insights-box">
        <strong>Performance Alert:</strong> Completion rate of ' . $completion_rate . '% is below optimal threshold (80%). 
        Consider reviewing appointment scheduling processes and patient flow management.
    </div>';
}

$html .= '</div>';

// Daily Visit Trends
if (!empty($daily_trends)) {
    $html .= '<div class="section">
        <div class="section-title">Daily Visit Trends Analysis</div>
        <div class="section-narrative">
            <strong>Trend Analysis:</strong> Daily visit patterns reveal operational rhythms and help identify peak demand periods. 
            Consistent completion rates across days indicate stable service delivery, while fluctuations may signal capacity or staffing challenges. 
            Use this data to optimize staff scheduling and resource allocation for high-demand periods.
        </div>
        <table class="data-table compact-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Total Visits</th>
                    <th>Completed</th>
                    <th>Completion Rate</th>
                </tr>
            </thead>
            <tbody>';
    
    $trend_count = 0;
    foreach ($daily_trends as $trend) {
        if ($trend_count >= 20) break; // Limit to 20 days for space efficiency
        
        $daily_completion_rate = $trend['total_visits'] > 0 
            ? round(($trend['completed_visits'] / $trend['total_visits']) * 100, 1)
            : 0;
        
        $html .= '<tr>
            <td>' . date('M j, Y', strtotime($trend['visit_date'])) . '</td>
            <td>' . number_format($trend['total_visits']) . '</td>
            <td>' . number_format($trend['completed_visits']) . '</td>
            <td>' . $daily_completion_rate . '%</td>
        </tr>';
        $trend_count++;
    }
    
    $html .= '</tbody></table></div>';
} else {
    $html .= '<div class="section">
        <div class="section-title">Daily Visit Trends Analysis</div>
        <div class="section-narrative">
            <strong>No Data Available:</strong> Daily trend analysis requires visit data within the selected period. 
            Consider expanding the date range or checking data collection processes.
        </div>
        <div class="no-data">No daily trend data available for the selected period.</div>
    </div>';
}

// Service Utilization
if (!empty($service_utilization)) {
    $html .= '<div class="section">
        <div class="section-title">Service Utilization Analysis</div>
        <div class="section-narrative">
            <strong>Service Performance Insights:</strong> This analysis reveals which healthcare services are most in-demand and their effectiveness. 
            High-volume services may need additional resources, while low completion rates indicate potential bottlenecks or process improvements needed. 
            Services with zero utilization may require promotion or evaluation for discontinuation.
        </div>
        <table class="data-table compact-table">
            <thead>
                <tr>
                    <th>Service Name</th>
                    <th>Total Visits</th>
                    <th>Completed</th>
                    <th>Rate %</th>
                </tr>
            </thead>
            <tbody>';
    
    $service_count = 0;
    foreach ($service_utilization as $service) {
        $html .= '<tr>
            <td>' . htmlspecialchars($service['service_name']) . '</td>
            <td>' . number_format($service['visit_count']) . '</td>
            <td>' . number_format($service['completed_count']) . '</td>
            <td>' . round($service['completion_rate'], 1) . '%</td>
        </tr>';
        $service_count++;
        
        if ($service_count >= 25) break; // Limit for space efficiency
    }
    
    if (count($service_utilization) > 25) {
        $html .= '<tr><td colspan="4" style="text-align: center; font-style: italic; color: #666;">
            ... and ' . (count($service_utilization) - 25) . ' more services (refer to web report for complete list)
        </td></tr>';
    }
    
    $html .= '</tbody></table>';
    
    // Add insights
    $zero_utilization = array_filter($service_utilization, function($s) { return $s['visit_count'] == 0; });
    if (count($zero_utilization) > 0) {
        $html .= '<div class="insights-box">
            <strong>Service Optimization Opportunity:</strong> ' . count($zero_utilization) . ' services show zero utilization. 
            Consider reviewing service availability, promotion strategies, or resource reallocation.
        </div>';
    }
    
    $html .= '</div>';
} else {
    $html .= '<div class="section">
        <div class="section-title">Service Utilization Analysis</div>
        <div class="section-narrative">
            <strong>No Service Data:</strong> Service utilization analysis requires appointment and visit data. 
            Verify that services are properly configured and linked to appointments.
        </div>
        <div class="no-data">No service utilization data available.</div>
    </div>';
}

// Geographic Distribution
if (!empty($geographic_distribution)) {
    $html .= '<div class="section page-break">
        <div class="section-title">Geographic Distribution Analysis</div>
        <div class="section-narrative">
            <strong>Catchment Area Assessment:</strong> Geographic distribution shows healthcare access patterns across districts and barangays. 
            High-visit areas indicate strong service utilization, while zero-visit barangays may signal access barriers, transportation issues, 
            or opportunities for mobile health services. This data guides outreach programs and resource allocation decisions.
        </div>
        <div class="two-column">
            <div class="column">
                <table class="data-table compact-table">
                    <thead>
                        <tr>
                            <th>Barangay</th>
                            <th>District</th>
                            <th>Visits</th>
                            <th>Patients</th>
                        </tr>
                    </thead>
                    <tbody>';
    
    $half_count = ceil(count($geographic_distribution) / 2);
    for ($i = 0; $i < $half_count; $i++) {
        if (isset($geographic_distribution[$i])) {
            $location = $geographic_distribution[$i];
            
            $district_name = '';
            switch ($location['district_id']) {
                case 1: $district_name = 'Main'; break;
                case 2: $district_name = 'GPS'; break;
                case 3: $district_name = 'Concepcion'; break;
                default: $district_name = 'Dist. ' . $location['district_id']; break;
            }
            
            $html .= '<tr>
                <td>' . htmlspecialchars($location['barangay_name']) . '</td>
                <td>' . $district_name . '</td>
                <td>' . number_format($location['visit_count']) . '</td>
                <td>' . number_format($location['unique_patients']) . '</td>
            </tr>';
        }
    }
    
    $html .= '</tbody></table>
            </div>
            <div class="column">
                <table class="data-table compact-table">
                    <thead>
                        <tr>
                            <th>Barangay</th>
                            <th>District</th>
                            <th>Visits</th>
                            <th>Patients</th>
                        </tr>
                    </thead>
                    <tbody>';
    
    for ($i = $half_count; $i < count($geographic_distribution); $i++) {
        if (isset($geographic_distribution[$i])) {
            $location = $geographic_distribution[$i];
            
            $district_name = '';
            switch ($location['district_id']) {
                case 1: $district_name = 'Main'; break;
                case 2: $district_name = 'GPS'; break;
                case 3: $district_name = 'Concepcion'; break;
                default: $district_name = 'Dist. ' . $location['district_id']; break;
            }
            
            $html .= '<tr>
                <td>' . htmlspecialchars($location['barangay_name']) . '</td>
                <td>' . $district_name . '</td>
                <td>' . number_format($location['visit_count']) . '</td>
                <td>' . number_format($location['unique_patients']) . '</td>
            </tr>';
        }
    }
    
    $html .= '</tbody></table>
            </div>
        </div>';
    
    // Add geographic insights
    $zero_visits = array_filter($geographic_distribution, function($loc) { return $loc['visit_count'] == 0; });
    if (count($zero_visits) > 0) {
        $html .= '<div class="insights-box">
            <strong>Outreach Opportunity:</strong> ' . count($zero_visits) . ' barangays show zero healthcare visits. 
            Consider implementing mobile health services or community health programs to improve access in underserved areas.
        </div>';
    }
    
    $html .= '</div>';
} else {
    $html .= '<div class="section">
        <div class="section-title">Geographic Distribution Analysis</div>
        <div class="section-narrative">
            <strong>Geographic Data Unavailable:</strong> Geographic analysis requires patient address data linked to barangay records. 
            Ensure patient registration includes complete address information.
        </div>
        <div class="no-data">No geographic distribution data available.</div>
    </div>';
}

// Appointment Compliance
if (!empty($appointment_compliance)) {
    $html .= '<div class="section">
        <div class="section-title">Appointment System Performance</div>
        <div class="section-narrative">
            <strong>Scheduling Effectiveness:</strong> Appointment compliance rates indicate the effectiveness of your scheduling system and patient communication. 
            High cancellation rates may suggest need for reminder systems, while low show-up rates could indicate access barriers or scheduling conflicts. 
            This data helps optimize appointment policies and patient engagement strategies.
        </div>
        <table class="data-table compact-table">
            <thead>
                <tr>
                    <th>Appointment Status</th>
                    <th>Count</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($appointment_compliance as $compliance) {
        $html .= '<tr>
            <td>' . ucfirst(str_replace('_', ' ', $compliance['appointment_status'])) . '</td>
            <td>' . number_format($compliance['count']) . '</td>
            <td>' . round($compliance['percentage'], 1) . '%</td>
        </tr>';
    }
    
    $html .= '</tbody></table>';
    
    // Add appointment insights
    $cancelled = array_filter($appointment_compliance, function($c) { 
        return stripos($c['appointment_status'], 'cancel') !== false; 
    });
    
    if (!empty($cancelled)) {
        $cancel_rate = $cancelled[0]['percentage'] ?? 0;
        if ($cancel_rate > 20) {
            $html .= '<div class="insights-box">
                <strong>Appointment System Alert:</strong> Cancellation rate of ' . round($cancel_rate, 1) . '% exceeds recommended threshold (20%). 
                Consider implementing appointment reminders or reviewing scheduling policies.
            </div>';
        }
    }
    
    $html .= '</div>';
} else {
    $html .= '<div class="section">
        <div class="section-title">Appointment System Performance</div>
        <div class="section-narrative">
            <strong>Appointment Data Missing:</strong> Compliance analysis requires appointment scheduling data with status tracking. 
            Ensure appointment system properly records and updates appointment statuses.
        </div>
        <div class="no-data">No appointment compliance data available.</div>
    </div>';
}

// Peak Hours Analysis
if (!empty($peak_hours)) {
    $html .= '<div class="section">
        <div class="section-title">Peak Hours & Operational Efficiency</div>
        <div class="section-narrative">
            <strong>Workflow Optimization:</strong> Peak hour analysis reveals patient flow patterns throughout the day. 
            High-traffic periods may require additional staffing, while low-activity hours present opportunities for administrative tasks or training. 
            Use this data to optimize staff schedules and reduce patient waiting times.
        </div>
        <div class="three-column">
            <div class="column">
                <table class="data-table compact-table">
                    <thead><tr><th>Hour</th><th>Visits</th></tr></thead>
                    <tbody>';
    
    $third_count = ceil(count($peak_hours) / 3);
    for ($i = 0; $i < $third_count; $i++) {
        if (isset($peak_hours[$i])) {
            $hour_data = $peak_hours[$i];
            $hour_display = date('g A', strtotime($hour_data['hour_of_day'] . ':00'));
            $html .= '<tr>
                <td>' . $hour_display . '</td>
                <td>' . number_format($hour_data['visit_count']) . '</td>
            </tr>';
        }
    }
    
    $html .= '</tbody></table>
            </div>
            <div class="column">
                <table class="data-table compact-table">
                    <thead><tr><th>Hour</th><th>Visits</th></tr></thead>
                    <tbody>';
    
    for ($i = $third_count; $i < $third_count * 2; $i++) {
        if (isset($peak_hours[$i])) {
            $hour_data = $peak_hours[$i];
            $hour_display = date('g A', strtotime($hour_data['hour_of_day'] . ':00'));
            $html .= '<tr>
                <td>' . $hour_display . '</td>
                <td>' . number_format($hour_data['visit_count']) . '</td>
            </tr>';
        }
    }
    
    $html .= '</tbody></table>
            </div>
            <div class="column">
                <table class="data-table compact-table">
                    <thead><tr><th>Hour</th><th>Visits</th></tr></thead>
                    <tbody>';
    
    for ($i = $third_count * 2; $i < count($peak_hours); $i++) {
        if (isset($peak_hours[$i])) {
            $hour_data = $peak_hours[$i];
            $hour_display = date('g A', strtotime($hour_data['hour_of_day'] . ':00'));
            $html .= '<tr>
                <td>' . $hour_display . '</td>
                <td>' . number_format($hour_data['visit_count']) . '</td>
            </tr>';
        }
    }
    
    $html .= '</tbody></table>
            </div>
        </div>';
    
    // Add peak hour insights
    if (!empty($peak_hours)) {
        $max_hour = array_reduce($peak_hours, function($carry, $item) {
            return ($carry === null || $item['visit_count'] > $carry['visit_count']) ? $item : $carry;
        });
        
        if ($max_hour) {
            $peak_time = date('g:00 A', strtotime($max_hour['hour_of_day'] . ':00'));
            $html .= '<div class="insights-box">
                <strong>Peak Activity:</strong> Highest patient volume occurs at ' . $peak_time . ' with ' . 
                number_format($max_hour['visit_count']) . ' visits. Consider additional staffing during this period.
            </div>';
        }
    }
    
    $html .= '</div>';
} else {
    $html .= '<div class="section">
        <div class="section-title">Peak Hours & Operational Efficiency</div>
        <div class="section-narrative">
            <strong>Time Data Missing:</strong> Peak hour analysis requires visit time-in data to identify busy periods. 
            Ensure patient check-in times are properly recorded for workflow optimization.
        </div>
        <div class="no-data">No peak hours data available.</div>
    </div>';
}

// Demographic Analysis
if (!empty($demographic_analysis)) {
    $html .= '<div class="section">
        <div class="section-title">Patient Demographics & Health Patterns</div>
        <div class="section-narrative">
            <strong>Population Health Insights:</strong> Demographic analysis reveals healthcare utilization patterns across age groups and genders. 
            This data informs targeted health programs, resource planning, and specialized service development. 
            High utilization in specific demographics may indicate prevalent health conditions requiring focused interventions.
        </div>
        <table class="data-table compact-table">
            <thead>
                <tr>
                    <th>Age Group</th>
                    <th>Gender</th>
                    <th>Visits</th>
                    <th>Patients</th>
                    <th>Avg/Patient</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($demographic_analysis as $demo) {
        $avg_visits_demo = $demo['unique_patients'] > 0 
            ? round($demo['visit_count'] / $demo['unique_patients'], 1)
            : 0;
        
        $html .= '<tr>
            <td>' . htmlspecialchars($demo['age_group']) . '</td>
            <td>' . htmlspecialchars($demo['sex']) . '</td>
            <td>' . number_format($demo['visit_count']) . '</td>
            <td>' . number_format($demo['unique_patients']) . '</td>
            <td>' . $avg_visits_demo . '</td>
        </tr>';
    }
    
    $html .= '</tbody></table>';
    
    // Add demographic insights
    $high_utilization = array_filter($demographic_analysis, function($d) { 
        return $d['unique_patients'] > 0 && ($d['visit_count'] / $d['unique_patients']) > 3; 
    });
    
    if (!empty($high_utilization)) {
        $html .= '<div class="insights-box">
            <strong>Health Program Opportunity:</strong> Some demographic groups show high visit frequency (>3 visits/patient). 
            Consider developing targeted health programs or chronic disease management for these populations.
        </div>';
    }
    
    $html .= '</div>';
} else {
    $html .= '<div class="section">
        <div class="section-title">Patient Demographics & Health Patterns</div>
        <div class="section-narrative">
            <strong>Demographic Data Missing:</strong> Demographics analysis requires patient birth date and gender information. 
            Ensure complete patient registration data for population health insights.
        </div>
        <div class="no-data">No demographic analysis data available.</div>
    </div>';
}

// Facility Performance
if (!empty($facility_performance)) {
    $html .= '<div class="section">
        <div class="section-title">Facility Performance & Resource Utilization</div>
        <div class="section-narrative">
            <strong>Operational Excellence Assessment:</strong> Facility performance comparison identifies high-performing locations and those needing support. 
            Completion rates and visit durations indicate operational efficiency, while visit volumes show demand patterns. 
            Use this data to share best practices and optimize resource allocation across the CHO network.
        </div>
        <table class="data-table compact-table">
            <thead>
                <tr>
                    <th>Facility Name</th>
                    <th>Type</th>
                    <th>Visits</th>
                    <th>Completed</th>
                    <th>Rate %</th>
                    <th>Avg Duration</th>
                </tr>
            </thead>
            <tbody>';
    
    $facility_count = 0;
    foreach ($facility_performance as $facility) {
        $facility_completion_rate = $facility['total_visits'] > 0 
            ? round(($facility['completed_visits'] / $facility['total_visits']) * 100, 1)
            : 0;
        $avg_duration_display = $facility['avg_duration_minutes'] 
            ? round($facility['avg_duration_minutes']) . 'm'
            : 'N/A';
        
        $html .= '<tr>
            <td>' . htmlspecialchars($facility['facility_name']) . '</td>
            <td>' . htmlspecialchars($facility['facility_type']) . '</td>
            <td>' . number_format($facility['total_visits']) . '</td>
            <td>' . number_format($facility['completed_visits']) . '</td>
            <td>' . $facility_completion_rate . '%</td>
            <td>' . $avg_duration_display . '</td>
        </tr>';
        
        $facility_count++;
        if ($facility_count >= 20) break; // Limit for space efficiency
    }
    
    if (count($facility_performance) > 20) {
        $html .= '<tr><td colspan="6" style="text-align: center; font-style: italic; color: #666;">
            ... and ' . (count($facility_performance) - 20) . ' more facilities (refer to web report for complete list)
        </td></tr>';
    }
    
    $html .= '</tbody></table>';
    
    // Add facility insights
    $zero_visits = array_filter($facility_performance, function($f) { return $f['total_visits'] == 0; });
    $low_completion = array_filter($facility_performance, function($f) { 
        return $f['total_visits'] > 0 && ($f['completed_visits'] / $f['total_visits']) < 0.7; 
    });
    
    if (count($zero_visits) > 0) {
        $html .= '<div class="insights-box">
            <strong>Facility Utilization Alert:</strong> ' . count($zero_visits) . ' facilities show zero visits. 
            Review facility status, accessibility, or service availability.
        </div>';
    }
    
    if (count($low_completion) > 0) {
        $html .= '<div class="insights-box">
            <strong>Quality Improvement Opportunity:</strong> ' . count($low_completion) . ' facilities show completion rates below 70%. 
            Consider operational assessments and staff training to improve service delivery.
        </div>';
    }
    
    $html .= '</div>';
} else {
    $html .= '<div class="section">
        <div class="section-title">Facility Performance & Resource Utilization</div>
        <div class="section-narrative">
            <strong>Facility Data Missing:</strong> Performance analysis requires visit data linked to facility records. 
            Ensure all visits are properly associated with facility locations.
        </div>
        <div class="no-data">No facility performance data available.</div>
    </div>';
}

$html .= '
    <div class="footer">
        Generated on ' . date('Y-m-d H:i:s') . ' | CHO Koronadal Patient Visits Report
    </div>
</body>
</html>';

// Create PDF with GD extension check and fallback
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', false); // Disable remote resources due to GD extension requirement
$options->set('isHtml5ParserEnabled', false);
$options->set('debugKeepTemp', false);
$options->set('isPhpEnabled', false);
$options->set('isJavascriptEnabled', false);
$options->set('tempDir', sys_get_temp_dir());
$options->set('logOutputFile', null);

$dompdf = new Dompdf($options);

try {
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Output PDF
    $filename = 'Patient_Visits_Report_' . date('Y-m-d_H-i-s') . '.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
} catch (Exception $e) {
    // Log the error
    error_log("PDF Generation Error: " . $e->getMessage());
    
    // Return user-friendly error
    http_response_code(500);
    die('Error: Unable to generate PDF report. Please try again or contact support if the problem persists.');
}
?>