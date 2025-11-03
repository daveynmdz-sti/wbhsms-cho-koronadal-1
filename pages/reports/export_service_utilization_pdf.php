<?php
// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in
if (!is_employee_logged_in()) {
    header("Location: ../management/auth/employee_login.php?reason=session_expired");
    exit();
}

// Include Dompdf
require_once $root_path . '/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// Get filters from URL parameters
$start_date = isset($_GET['start_date']) && !empty($_GET['start_date'])
    ? $_GET['start_date']
    : date('Y-m-01');

$end_date = isset($_GET['end_date']) && !empty($_GET['end_date'])
    ? $_GET['end_date']
    : date('Y-m-t');

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

try {
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
            COUNT(DISTINCT r.patient_id) as unique_patients
        FROM services s
        LEFT JOIN referrals r ON s.service_id = r.service_id 
            AND r.referral_date BETWEEN ? AND ?
            " . ($facility_filter ? " AND r.facility_id = ?" : "") . "
        WHERE s.name != 'General Service'
        " . ($service_filter ? " AND s.service_id = ?" : "") . "
        GROUP BY s.service_id, s.name
        ORDER BY referral_count DESC
        LIMIT 10
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
            COUNT(DISTINCT c.patient_id) as unique_patients
        FROM services s
        LEFT JOIN consultations c ON s.service_id = c.service_id 
            AND c.consultation_date BETWEEN ? AND ?
            " . ($facility_filter ? " AND c.facility_id = ?" : "") . "
        WHERE s.name != 'General Service'
        " . ($service_filter ? " AND s.service_id = ?" : "") . "
        GROUP BY s.service_id, s.name
        ORDER BY consultation_count DESC
        LIMIT 10
    ");

    $stmt->execute($ref_params);
    $top_consultation_services = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    // Get facility name if filtered
    $facility_name = 'All Facilities';
    if ($facility_filter) {
        $stmt = $pdo->prepare("SELECT name FROM facilities WHERE facility_id = ?");
        $stmt->execute([$facility_filter]);
        $facility_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($facility_data) {
            $facility_name = $facility_data['name'];
        }
    }

    // Get service name if filtered
    $service_name = 'All Services';
    if ($service_filter) {
        $stmt = $pdo->prepare("SELECT name FROM services WHERE service_id = ?");
        $stmt->execute([$service_filter]);
        $service_data = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($service_data) {
            $service_name = $service_data['name'];
        }
    }

} catch (PDOException $e) {
    error_log("Service Utilization PDF Export Error: " . $e->getMessage());
    die("Error generating report. Please try again.");
}

// Generate PDF content
$html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Service Utilization Report</title>
    <style>
        @page {
            margin: 1cm;
            font-family: Arial, sans-serif;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.3;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #0077b6;
            padding-bottom: 10px;
        }
        
        .header h1 {
            font-size: 18px;
            color: #0077b6;
            margin: 0 0 5px 0;
            font-weight: bold;
        }
        
        .header h2 {
            font-size: 14px;
            color: #666;
            margin: 0;
            font-weight: normal;
        }
        
        .report-info {
            background: #f8f9fa;
            padding: 8px;
            margin-bottom: 15px;
            border-radius: 4px;
            border: 1px solid #dee2e6;
        }
        
        .report-info p {
            margin: 2px 0;
            font-size: 10px;
        }
        
        .stats-grid {
            display: table;
            width: 100%;
            margin-bottom: 15px;
        }
        
        .stat-row {
            display: table-row;
        }
        
        .stat-cell {
            display: table-cell;
            width: 20%;
            padding: 8px;
            text-align: center;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            vertical-align: middle;
        }
        
        .stat-value {
            font-size: 16px;
            font-weight: bold;
            color: #0077b6;
            margin: 0;
        }
        
        .stat-label {
            font-size: 9px;
            color: #666;
            margin: 2px 0 0 0;
        }
        
        .section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: bold;
            color: #0077b6;
            margin: 15px 0 8px 0;
            padding-bottom: 3px;
            border-bottom: 1px solid #ccc;
        }
        
        .insights-box {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 4px;
            padding: 8px;
            margin: 8px 0;
            font-size: 10px;
            color: #0056b3;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            font-size: 10px;
        }
        
        th, td {
            border: 1px solid #ddd;
            padding: 4px 6px;
            text-align: left;
            vertical-align: top;
        }
        
        th {
            background: #f8f9fa;
            font-weight: bold;
            color: #333;
            font-size: 9px;
        }
        
        .demand-high { color: #d63384; font-weight: bold; }
        .demand-medium { color: #fd7e14; font-weight: bold; }
        .demand-low { color: #6c757d; }
        .demand-zero { color: #adb5bd; font-style: italic; }
        
        .footer {
            position: fixed;
            bottom: 0cm;
            left: 0cm;
            right: 0cm;
            height: 1cm;
            font-size: 8px;
            text-align: center;
            color: #666;
        }
        
        .page-break {
            page-break-before: always;
        }
        
        .two-column {
            display: table;
            width: 100%;
            table-layout: fixed;
        }
        
        .column {
            display: table-cell;
            width: 50%;
            padding-right: 10px;
            vertical-align: top;
        }
        
        .column:last-child {
            padding-right: 0;
            padding-left: 10px;
        }
        
        .compact-table th,
        .compact-table td {
            padding: 3px 4px;
            font-size: 9px;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>Service Utilization Report</h1>
        <h2>City Health Office - Koronadal</h2>
    </div>
    
    <!-- Report Information -->
    <div class="report-info">
        <p><strong>Report Period:</strong> ' . date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date)) . '</p>
        <p><strong>Facility:</strong> ' . htmlspecialchars($facility_name) . '</p>
        <p><strong>Service Focus:</strong> ' . htmlspecialchars($service_name) . '</p>
        <p><strong>Generated:</strong> ' . date('F j, Y g:i A') . ' by ' . htmlspecialchars($_SESSION['employee_name'] ?? 'System') . '</p>
        <p><strong>Report Purpose:</strong> This comprehensive analysis examines healthcare service demand patterns by combining referral and consultation data to identify the most sought-after services and inform resource allocation decisions.</p>
    </div>
    
    <!-- Executive Summary Statistics -->
    <div class="stats-grid">
        <div class="stat-row">
            <div class="stat-cell">
                <div class="stat-value">' . number_format($service_summary['total_services'] ?? 0) . '</div>
                <div class="stat-label">Total Services</div>
            </div>
            <div class="stat-cell">
                <div class="stat-value">' . number_format($service_summary['total_referrals'] ?? 0) . '</div>
                <div class="stat-label">Total Referrals</div>
            </div>
            <div class="stat-cell">
                <div class="stat-value">' . number_format($service_summary['total_consultations'] ?? 0) . '</div>
                <div class="stat-label">Total Consultations</div>
            </div>
            <div class="stat-cell">
                <div class="stat-value">' . number_format($service_summary['total_service_demand'] ?? 0) . '</div>
                <div class="stat-label">Combined Demand</div>
            </div>
            <div class="stat-cell">
                <div class="stat-value">' . (count(array_filter($service_demand, function($s) { return $s['total_demand'] > 0; }))) . '</div>
                <div class="stat-label">Active Services</div>
            </div>
        </div>
    </div>
    
    <div class="insights-box">
        <strong>Key Insights:</strong> This report combines both referral and consultation data to provide a comprehensive view of service utilization. General Service is excluded from all analysis to focus on specific medical services. High-demand services may require capacity expansion or additional resources.
    </div>';

// Service Demand Analysis Table
if (!empty($service_demand)) {
    $html .= '
    <div class="section">
        <div class="section-title">Service Demand Analysis (Combined Referrals + Consultations)</div>
        
        <table class="compact-table">
            <thead>
                <tr>
                    <th style="width: 35%;">Service Name</th>
                    <th style="width: 12%;">Referrals</th>
                    <th style="width: 12%;">Consultations</th>
                    <th style="width: 12%;">Total Demand</th>
                    <th style="width: 12%;">Share %</th>
                    <th style="width: 17%;">Demand Level</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($service_demand as $service) {
        $demand_class = '';
        $demand_level = '';
        if ($service['total_demand'] == 0) {
            $demand_level = 'No Activity';
            $demand_class = 'demand-zero';
        } elseif ($service['demand_percentage'] >= 10) {
            $demand_level = 'High Demand';
            $demand_class = 'demand-high';
        } elseif ($service['demand_percentage'] >= 5) {
            $demand_level = 'Medium Demand';
            $demand_class = 'demand-medium';
        } else {
            $demand_level = 'Low Demand';
            $demand_class = 'demand-low';
        }
        
        $html .= '
                <tr>
                    <td><strong>' . htmlspecialchars($service['service_name']) . '</strong>';
        
        if (!empty($service['service_description'])) {
            $html .= '<br><small style="color: #666;">' . htmlspecialchars(substr($service['service_description'], 0, 80)) . '...</small>';
        }
        
        $html .= '</td>
                    <td style="text-align: center;">' . number_format($service['referral_count']) . '</td>
                    <td style="text-align: center;">' . number_format($service['consultation_count']) . '</td>
                    <td style="text-align: center;"><strong>' . number_format($service['total_demand']) . '</strong></td>
                    <td style="text-align: center;">' . round($service['demand_percentage'], 1) . '%</td>
                    <td style="text-align: center;" class="' . $demand_class . '">' . $demand_level . '</td>
                </tr>';
    }
    
    $html .= '
            </tbody>
        </table>
        
        <div class="insights-box">
            <strong>Analysis:</strong> This table shows the comprehensive service demand by combining referral and consultation requests. Services with high demand percentages (≥10%) may require additional resources or capacity expansion. Medium demand services (5-9%) should be monitored for growth trends, while low demand services may benefit from improved promotion or reassessment of necessity.
        </div>
    </div>';
}

// Two-column layout for Top Services
$html .= '
    <div class="section">
        <div class="two-column">
            <div class="column">';

// Top Referral Services
if (!empty($top_referral_services)) {
    $html .= '
                <div class="section-title">Top Referral Services</div>
                <table class="compact-table">
                    <thead>
                        <tr>
                            <th>Service Name</th>
                            <th>Referrals</th>
                            <th>Patients</th>
                        </tr>
                    </thead>
                    <tbody>';
    
    foreach (array_slice($top_referral_services, 0, 8) as $service) {
        $html .= '
                        <tr>
                            <td>' . htmlspecialchars($service['service_name']) . '</td>
                            <td style="text-align: center;">' . number_format($service['referral_count']) . '</td>
                            <td style="text-align: center;">' . number_format($service['unique_patients']) . '</td>
                        </tr>';
    }
    
    $html .= '
                    </tbody>
                </table>
                
                <div class="insights-box">
                    <strong>Referral Insights:</strong> These services are most frequently requested through the referral system, indicating specialized care needs or capacity limitations at the primary level.
                </div>';
}

$html .= '
            </div>
            <div class="column">';

// Top Consultation Services
if (!empty($top_consultation_services)) {
    $html .= '
                <div class="section-title">Top Consultation Services</div>
                <table class="compact-table">
                    <thead>
                        <tr>
                            <th>Service Name</th>
                            <th>Consultations</th>
                            <th>Patients</th>
                        </tr>
                    </thead>
                    <tbody>';
    
    foreach (array_slice($top_consultation_services, 0, 8) as $service) {
        $html .= '
                        <tr>
                            <td>' . htmlspecialchars($service['service_name']) . '</td>
                            <td style="text-align: center;">' . number_format($service['consultation_count']) . '</td>
                            <td style="text-align: center;">' . number_format($service['unique_patients']) . '</td>
                        </tr>';
    }
    
    $html .= '
                    </tbody>
                </table>
                
                <div class="insights-box">
                    <strong>Consultation Insights:</strong> These services show the highest direct consultation demand, representing core healthcare services that patients access regularly.
                </div>';
}

$html .= '
            </div>
        </div>
    </div>';

// Recommendations Section
$html .= '
    <div class="section">
        <div class="section-title">Recommendations & Strategic Insights</div>
        <div style="font-size: 10px; line-height: 1.4;">
            <p><strong>High-Demand Services:</strong> Services with demand share ≥10% require immediate attention for capacity planning and resource allocation. Consider expanding staffing, equipment, or operational hours for these services.</p>
            
            <p><strong>Resource Optimization:</strong> Medium-demand services (5-9% share) represent growth opportunities. Monitor trends and prepare scaling strategies for services showing upward utilization patterns.</p>
            
            <p><strong>Referral Patterns:</strong> High referral volumes may indicate need for specialized equipment or training at the primary care level, or capacity constraints requiring system-wide solutions.</p>
            
            <p><strong>Quality Improvement:</strong> Services with consistently low demand may benefit from community health education, improved accessibility, or service delivery method reassessment.</p>
            
            <p><strong>Data Exclusions:</strong> This analysis excludes only General Service from both referral and consultation statistics, providing a focused view of specific medical service utilization patterns.</p>
        </div>
    </div>';

$html .= '
    <!-- Footer -->
    <div class="footer">
        <p>Service Utilization Report - CHO Koronadal | Generated: ' . date('Y-m-d H:i:s') . ' | Page {PAGE_NUM} of {PAGE_COUNT}</p>
    </div>
</body>
</html>';

// Configure Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Arial');
$options->setTempDir($root_path . '/temp');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Output the PDF
$filename = 'Service_Utilization_Report_' . date('Y-m-d_H-i-s') . '.pdf';
$dompdf->stream($filename, array('Attachment' => true));
exit();
?>