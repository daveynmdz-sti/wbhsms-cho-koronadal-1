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

// Get filter parameters from POST or GET
$filter_type = $_REQUEST['filter_type'] ?? 'date_range';
$date_from = $_REQUEST['date_from'] ?? date('Y-m-01');
$date_to = $_REQUEST['date_to'] ?? date('Y-m-t');
$month_from = $_REQUEST['month_from'] ?? date('Y-m');
$month_to = $_REQUEST['month_to'] ?? date('Y-m');
$year_from = $_REQUEST['year_from'] ?? date('Y');
$year_to = $_REQUEST['year_to'] ?? date('Y');

// Build WHERE clause based on filter type
$where_clause = "";
$params = [];
$date_range_text = "";

switch ($filter_type) {
    case 'month_range':
        $where_clause = "DATE(r.referral_date) >= ? AND DATE(r.referral_date) <= ?";
        $params = [$month_from . '-01', date('Y-m-t', strtotime($month_to . '-01'))];
        $date_range_text = "Month Range: " . date('F Y', strtotime($month_from)) . " to " . date('F Y', strtotime($month_to));
        break;
    case 'year_range':
        $where_clause = "YEAR(r.referral_date) >= ? AND YEAR(r.referral_date) <= ?";
        $params = [$year_from, $year_to];
        $date_range_text = "Year Range: $year_from to $year_to";
        break;
    case 'date_range':
    default:
        $where_clause = "DATE(r.referral_date) >= ? AND DATE(r.referral_date) <= ?";
        $params = [$date_from, $date_to];
        $date_range_text = "Date Range: " . date('F j, Y', strtotime($date_from)) . " to " . date('F j, Y', strtotime($date_to));
        break;
}

// Initialize variables
$metrics = ['total_referrals' => 0, 'accepted_referrals' => 0, 'cancelled_referrals' => 0, 'external_issued' => 0, 'active_referrals' => 0];
$completion_rate = 0;
$facility_transfers = [];
$destination_types = [];
$referral_reasons = [];
$timeline_data = [];
$referral_logs = [];
$coordination_stats = ['avg_response_time' => 0, 'cancellation_rate' => 0, 'external_ratio' => 0];
$most_active_referring = ['name' => 'N/A', 'referral_count' => 0];
$most_active_receiving = ['name' => 'N/A', 'referral_count' => 0];
$detailed_referrals = [];

try {
    // 1. Key Metrics Summary
    $metrics_query = "
        SELECT 
            COUNT(*) as total_referrals,
            SUM(CASE WHEN r.status = 'accepted' THEN 1 ELSE 0 END) as accepted_referrals,
            SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_referrals,
            SUM(CASE WHEN r.destination_type = 'external' AND r.status = 'issued' THEN 1 ELSE 0 END) as external_issued,
            SUM(CASE WHEN r.status = 'active' THEN 1 ELSE 0 END) as active_referrals
        FROM referrals r 
        WHERE $where_clause
    ";
    
    $metrics_stmt = $pdo->prepare($metrics_query);
    $metrics_stmt->execute($params);
    $result = $metrics_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Ensure we have proper default values
    if ($result) {
        $metrics = [
            'total_referrals' => (int)($result['total_referrals'] ?? 0),
            'accepted_referrals' => (int)($result['accepted_referrals'] ?? 0),
            'cancelled_referrals' => (int)($result['cancelled_referrals'] ?? 0),
            'external_issued' => (int)($result['external_issued'] ?? 0),
            'active_referrals' => (int)($result['active_referrals'] ?? 0)
        ];
    }
    
    // Calculate completion rate
    $completion_rate = $metrics['total_referrals'] > 0 ? 
        round(($metrics['accepted_referrals'] / $metrics['total_referrals']) * 100, 2) : 0;

    // 2. Facility-to-Facility Transfers
    $facility_query = "
        SELECT 
            rf.name as referring_facility,
            COALESCE(tf.name, r.external_facility_name, 'External Facility') as referred_to_facility,
            COUNT(*) as total_referrals,
            SUM(CASE WHEN r.status = 'accepted' THEN 1 ELSE 0 END) as accepted,
            SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN r.status = 'issued' THEN 1 ELSE 0 END) as issued
        FROM referrals r
        LEFT JOIN facilities rf ON r.referring_facility_id = rf.facility_id
        LEFT JOIN facilities tf ON r.referred_to_facility_id = tf.facility_id
        WHERE $where_clause
        GROUP BY r.referring_facility_id, rf.name, r.referred_to_facility_id, tf.name, r.external_facility_name, 
                 COALESCE(tf.name, r.external_facility_name, 'External Facility')
        ORDER BY total_referrals DESC
        LIMIT 20
    ";
    
    $facility_stmt = $pdo->prepare($facility_query);
    $facility_stmt->execute($params);
    $facility_transfers = $facility_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Destination Type Distribution
    // First get the total count
    $total_count_query = "SELECT COUNT(*) as total FROM referrals r WHERE $where_clause";
    $total_stmt = $pdo->prepare($total_count_query);
    $total_stmt->execute($params);
    $total_result = $total_stmt->fetch(PDO::FETCH_ASSOC);
    $total_count = $total_result['total'];
    
    $destination_query = "
        SELECT 
            r.destination_type,
            COUNT(*) as count,
            ROUND((COUNT(*) * 100.0 / ?), 2) as percentage
        FROM referrals r
        WHERE $where_clause
        GROUP BY r.destination_type
        ORDER BY count DESC
    ";
    
    $destination_stmt = $pdo->prepare($destination_query);
    $destination_stmt->execute(array_merge([$total_count], $params)); // Total count first, then filter params
    $destination_types = $destination_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Referral Reasons Breakdown
    $reasons_query = "
        SELECT 
            CASE 
                WHEN LOWER(r.referral_reason) LIKE '%consultation%' THEN 'Consultation'
                WHEN LOWER(r.referral_reason) LIKE '%check%up%' OR LOWER(r.referral_reason) LIKE '%checkup%' THEN 'Check-up'
                WHEN LOWER(r.referral_reason) LIKE '%lab%' OR LOWER(r.referral_reason) LIKE '%laboratory%' THEN 'Laboratory'
                WHEN LOWER(r.referral_reason) LIKE '%mri%' OR LOWER(r.referral_reason) LIKE '%ct%scan%' THEN 'Imaging'
                WHEN LOWER(r.referral_reason) LIKE '%emergency%' OR LOWER(r.referral_reason) LIKE '%urgent%' THEN 'Emergency'
                ELSE 'Other'
            END as reason_category,
            COUNT(*) as count
        FROM referrals r
        WHERE $where_clause AND r.referral_reason IS NOT NULL
        GROUP BY CASE 
                WHEN LOWER(r.referral_reason) LIKE '%consultation%' THEN 'Consultation'
                WHEN LOWER(r.referral_reason) LIKE '%check%up%' OR LOWER(r.referral_reason) LIKE '%checkup%' THEN 'Check-up'
                WHEN LOWER(r.referral_reason) LIKE '%lab%' OR LOWER(r.referral_reason) LIKE '%laboratory%' THEN 'Laboratory'
                WHEN LOWER(r.referral_reason) LIKE '%mri%' OR LOWER(r.referral_reason) LIKE '%ct%scan%' THEN 'Imaging'
                WHEN LOWER(r.referral_reason) LIKE '%emergency%' OR LOWER(r.referral_reason) LIKE '%urgent%' THEN 'Emergency'
                ELSE 'Other'
            END
        ORDER BY count DESC
        LIMIT 10
    ";
    
    $reasons_stmt = $pdo->prepare($reasons_query);
    $reasons_stmt->execute($params);
    $referral_reasons = $reasons_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. Referral Timeline Trends
    $timeline_query = "
        SELECT 
            DATE(r.referral_date) as referral_day,
            COUNT(*) as daily_count,
            SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count
        FROM referrals r
        WHERE $where_clause
        GROUP BY DATE(r.referral_date)
        ORDER BY referral_day ASC
    ";

    $timeline_stmt = $pdo->prepare($timeline_query);
    $timeline_stmt->execute($params);
    $timeline_data = $timeline_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Inter-Facility Coordination Statistics
    $coordination_query = "
        SELECT 
            AVG(TIMESTAMPDIFF(DAY, r.referral_date, r.updated_at)) as avg_response_time,
            (SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) as cancellation_rate,
            (SUM(CASE WHEN r.destination_type = 'external' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)) as external_ratio
        FROM referrals r
        WHERE $where_clause
    ";
    
    $coordination_stmt = $pdo->prepare($coordination_query);
    $coordination_stmt->execute($params);
    $coordination_stats = $coordination_stmt->fetch(PDO::FETCH_ASSOC);

    // Most active facilities
    $active_referring_query = "
        SELECT f.name, COUNT(*) as referral_count
        FROM referrals r
        JOIN facilities f ON r.referring_facility_id = f.facility_id
        WHERE $where_clause
        GROUP BY r.referring_facility_id, f.name
        ORDER BY referral_count DESC
        LIMIT 1
    ";
    
    $active_referring_stmt = $pdo->prepare($active_referring_query);
    $active_referring_stmt->execute($params);
    $most_active_referring = $active_referring_stmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'N/A', 'referral_count' => 0];

    $active_receiving_query = "
        SELECT COALESCE(f.name, 'External Facilities') as name, COUNT(*) as referral_count
        FROM referrals r
        LEFT JOIN facilities f ON r.referred_to_facility_id = f.facility_id
        WHERE $where_clause
        GROUP BY r.referred_to_facility_id, f.name, COALESCE(f.name, 'External Facilities')
        ORDER BY referral_count DESC
        LIMIT 1
    ";
    
    $active_receiving_stmt = $pdo->prepare($active_receiving_query);
    $active_receiving_stmt->execute($params);
    $most_active_receiving = $active_receiving_stmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => 'N/A', 'referral_count' => 0];

    // 7. Detailed Referral Table (for summary)
    $detailed_query = "
        SELECT 
            r.referral_num,
            r.patient_id,
            rf.name as referring_facility,
            COALESCE(tf.name, r.external_facility_name) as referred_to_facility,
            r.destination_type,
            r.referral_reason,
            r.referral_date,
            r.status,
            r.updated_at
        FROM referrals r
        LEFT JOIN facilities rf ON r.referring_facility_id = rf.facility_id
        LEFT JOIN facilities tf ON r.referred_to_facility_id = tf.facility_id
        WHERE $where_clause
        ORDER BY r.referral_date DESC
        LIMIT 30
    ";
    
    $detailed_stmt = $pdo->prepare($detailed_query);
    $detailed_stmt->execute($params);
    $detailed_referrals = $detailed_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 8. Recent Activity Logs
    $logs_query = "
        SELECT 
            rl.log_id,
            r.referral_num,
            e.first_name,
            e.last_name,
            rl.action,
            rl.reason,
            rl.previous_status,
            rl.new_status,
            rl.timestamp
        FROM referral_logs rl
        JOIN referrals r ON rl.referral_id = r.referral_id
        JOIN employees e ON rl.employee_id = e.employee_id
        WHERE $where_clause
        ORDER BY rl.timestamp DESC
        LIMIT 15
    ";
    
    $logs_stmt = $pdo->prepare($logs_query);
    $logs_stmt->execute($params);
    $referral_logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Keep default empty values
    error_log("PDF Export Error: " . $e->getMessage());
}

// Generate the PDF content with ultra-simple HTML to avoid CSS parsing issues
$html = '<html>
<head>
    <meta charset="UTF-8">
    <title>Referral Summary Report</title>
    <style>
        body { font-family: Arial; font-size: 10px; margin: 20px; }
        .header { text-align: center; border-bottom: 2px solid black; padding-bottom: 10px; margin-bottom: 20px; }
        .header h1 { font-size: 16px; margin: 0; }
        .header h2 { font-size: 14px; margin: 5px 0; }
        .header p { font-size: 9px; margin: 2px 0; }
        .section { margin-bottom: 15px; }
        .section h2 { font-size: 12px; margin: 10px 0 5px 0; border-bottom: 1px solid black; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 8px; }
        table th, table td { border: 1px solid black; padding: 3px; text-align: left; }
        table th { background-color: lightgray; font-weight: bold; }
        .stat-box { border: 1px solid black; padding: 8px; margin: 5px 0; }
        .stat-value { font-size: 14px; font-weight: bold; }
        .stat-label { font-size: 8px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>REFERRAL SUMMARY REPORT</h1>
        <h2>Republic of the Philippines</h2>
        <h2>City Health Office</h2>
        <h2>Koronadal City</h2>
        <p><strong>' . htmlspecialchars($date_range_text) . '</strong></p>
        <p>Generated: ' . date('F j, Y \a\t g:i A') . '</p>
        <p>Generated by: ' . htmlspecialchars(($_SESSION['employee_first_name'] ?? 'Unknown') . ' ' . ($_SESSION['employee_last_name'] ?? 'User')) . ' (' . htmlspecialchars($_SESSION['role'] ?? 'Unknown') . ')</p>
    </div>

    <div class="section">
        <h2>EXECUTIVE SUMMARY</h2>
        <p><strong>Report Purpose:</strong> This comprehensive referral summary report analyzes patient referral patterns, inter-facility coordination effectiveness, and healthcare service utilization within the CHO Koronadal network. The report provides critical insights for healthcare administrators, policy makers, and clinical staff to improve patient care coordination and resource allocation.</p>
        <p><strong>Scope:</strong> The analysis covers all referral activities during the specified period, including internal facility transfers, external referrals, referral outcomes, and coordination statistics. Data is sourced from the Web-Based Healthcare Services Management System (WBHSMS) database.</p>
        <p><strong>Key Benefits:</strong> This report supports strategic planning, quality improvement initiatives, resource optimization, and performance monitoring across the healthcare network.</p>
    </div>

    <div class="section">
        <h2>I. KEY METRICS SUMMARY</h2>
        <p><strong>Overview:</strong> This section provides a high-level snapshot of referral activity during the selected period. These metrics help administrators understand the overall volume and outcomes of patient referrals within the healthcare network.</p>
        <div class="stat-box">
            <div class="stat-value">Total Referrals: ' . number_format($metrics['total_referrals']) . '</div>
        </div>
        <div class="stat-box">
            <div class="stat-value">Accepted Referrals: ' . number_format($metrics['accepted_referrals']) . '</div>
        </div>
        <div class="stat-box">
            <div class="stat-value">Cancelled Referrals: ' . number_format($metrics['cancelled_referrals']) . '</div>
        </div>
        <div class="stat-box">
            <div class="stat-value">External Issued: ' . number_format($metrics['external_issued']) . '</div>
        </div>
        <div class="stat-box">
            <div class="stat-value">Active Referrals: ' . number_format($metrics['active_referrals']) . '</div>
        </div>
        <div class="stat-box">
            <div class="stat-value">Completion Rate: ' . $completion_rate . '%</div>
        </div>
        <p><strong>Analysis:</strong> The completion rate indicates the percentage of referrals that were successfully accepted out of the total referrals made. A higher completion rate suggests effective inter-facility coordination and patient care continuity.</p>
    </div>';

// Add facility transfers if available
if (!empty($facility_transfers)) {
    $html .= '
    <div class="section">
        <h2>II. FACILITY-TO-FACILITY TRANSFERS</h2>
        <p><strong>Purpose:</strong> This section analyzes the flow of patient referrals between different healthcare facilities. It shows which facilities are most active in referring patients and which are receiving the most referrals, helping identify collaboration patterns and potential bottlenecks in the referral network.</p>
        <table>
            <tr>
                <th>Referring Facility</th>
                <th>Referred To Facility</th>
                <th>Total</th>
                <th>Accepted</th>
                <th>Cancelled</th>
                <th>Issued</th>
            </tr>';
    
    foreach ($facility_transfers as $transfer) {
        $html .= '<tr>
            <td>' . htmlspecialchars($transfer['referring_facility']) . '</td>
            <td>' . htmlspecialchars($transfer['referred_to_facility']) . '</td>
            <td>' . number_format($transfer['total_referrals']) . '</td>
            <td>' . number_format($transfer['accepted']) . '</td>
            <td>' . number_format($transfer['cancelled']) . '</td>
            <td>' . number_format($transfer['issued']) . '</td>
        </tr>';
    }
    
    $html .= '</table>
        <p><strong>Insights:</strong> Facilities with high referral volumes may need additional resources or capacity planning. High cancellation rates between specific facility pairs may indicate communication issues or capacity constraints that require attention.</p>
    </div>';
}

// Add destination types if available
if (!empty($destination_types)) {
    $html .= '
    <div class="section">
        <h2>III. DESTINATION TYPE DISTRIBUTION</h2>
        <p><strong>Definition:</strong> This section categorizes referrals by their destination type - whether they are internal (within the CHO network) or external (to facilities outside the network). This helps understand referral patterns and resource utilization across the healthcare system.</p>
        <table>
            <tr>
                <th>Destination Type</th>
                <th>Count</th>
                <th>Percentage</th>
            </tr>';
    
    foreach ($destination_types as $dest) {
        $html .= '<tr>
            <td>' . ucwords(str_replace('_', ' ', htmlspecialchars($dest['destination_type']))) . '</td>
            <td>' . number_format($dest['count']) . '</td>
            <td>' . number_format($dest['percentage'], 1) . '%</td>
        </tr>';
    }
    
    $html .= '</table>
        <p><strong>Strategic Value:</strong> A high percentage of external referrals may indicate gaps in local healthcare capacity that need to be addressed through service expansion or specialist recruitment.</p>
    </div>';
}

// Add referral reasons if available
if (!empty($referral_reasons)) {
    $html .= '
    <div class="section">
        <h2>IV. TOP REFERRAL REASONS</h2>
        <p><strong>Clinical Context:</strong> This analysis categorizes referrals by their primary reason, providing insights into patient care needs and service demands. Understanding referral patterns helps in resource allocation and service planning.</p>
        <table>
            <tr>
                <th>Reason Category</th>
                <th>Count</th>
            </tr>';
    
    foreach ($referral_reasons as $reason) {
        $html .= '<tr>
            <td>' . htmlspecialchars($reason['reason_category']) . '</td>
            <td>' . number_format($reason['count']) . '</td>
        </tr>';
    }
    
    $html .= '</table>
        <p><strong>Healthcare Planning:</strong> High volumes in specific categories may indicate areas where additional specialist services, equipment, or training are needed to better serve patient needs locally.</p>
    </div>';
}

// Add timeline trends if available  
if (!empty($timeline_data)) {
    $html .= '
    <div class="section">
        <h2>V. REFERRAL TIMELINE TRENDS</h2>
        <p><strong>Temporal Analysis:</strong> This section shows the daily distribution of referrals over the selected period, providing insights into referral patterns, peak periods, and cancellation trends. This information is valuable for resource planning and workload management.</p>
        <table>
            <tr>
                <th>Date</th>
                <th>Total Referrals</th>
                <th>Cancelled</th>
                <th>Success Rate</th>
            </tr>';
    
    foreach ($timeline_data as $day) {
        $success_rate = $day['daily_count'] > 0 ? round((($day['daily_count'] - $day['cancelled_count']) / $day['daily_count']) * 100, 1) : 0;
        $html .= '<tr>
            <td>' . date('M j, Y', strtotime($day['referral_day'])) . '</td>
            <td>' . number_format($day['daily_count']) . '</td>
            <td>' . number_format($day['cancelled_count']) . '</td>
            <td>' . $success_rate . '%</td>
        </tr>';
    }
    
    $html .= '</table>
        <p><strong>Trend Analysis:</strong> Regular monitoring of daily patterns helps identify peak demand periods and allocate resources accordingly. High cancellation rates on specific days may indicate systemic issues that require attention.</p>
    </div>';
}

// Add coordination statistics
$html .= '
    <div class="section">
        <h2>VI. INTER-FACILITY COORDINATION STATISTICS</h2>
        <p><strong>Performance Metrics:</strong> These statistics measure the efficiency and effectiveness of inter-facility coordination. They provide key performance indicators for the referral system and help identify areas for process improvement.</p>
        <div class="stat-box">
            <div class="stat-value">Average Response Time: ' . round($coordination_stats['avg_response_time'] ?? 0, 1) . ' days</div>
        </div>
        <div class="stat-box">
            <div class="stat-value">Cancellation Rate: ' . round($coordination_stats['cancellation_rate'] ?? 0, 1) . '%</div>
        </div>
        <div class="stat-box">
            <div class="stat-value">External Referral Ratio: ' . round($coordination_stats['external_ratio'] ?? 0, 1) . '%</div>
        </div>
        <div class="stat-box">
            <div class="stat-value">Most Active Referring: ' . htmlspecialchars($most_active_referring['name']) . ' (' . $most_active_referring['referral_count'] . ' referrals)</div>
        </div>
        <div class="stat-box">
            <div class="stat-value">Most Active Receiving: ' . htmlspecialchars($most_active_receiving['name']) . ' (' . $most_active_receiving['referral_count'] . ' referrals)</div>
        </div>
        <p><strong>Quality Indicators:</strong> Lower response times indicate efficient communication between facilities. High cancellation rates may suggest coordination challenges that need addressing. The most active facilities may require additional support or recognition for their role in the referral network.</p>
    </div>';

// Add recent activity logs if available
if (!empty($referral_logs)) {
    $html .= '
    <div class="section">
        <h2>VII. RECENT REFERRAL ACTIVITY (LATEST 15)</h2>
        <p><strong>Activity Tracking:</strong> This section provides an audit trail of recent referral actions, showing who performed specific actions and when. This transparency helps with accountability and process monitoring in the referral system.</p>
        <table>
            <tr>
                <th>Referral #</th>
                <th>Employee</th>
                <th>Action</th>
                <th>Previous Status</th>
                <th>New Status</th>
                <th>Timestamp</th>
            </tr>';
    
    foreach ($referral_logs as $log) {
        $html .= '<tr>
            <td>' . htmlspecialchars($log['referral_num']) . '</td>
            <td>' . htmlspecialchars($log['first_name'] . ' ' . $log['last_name']) . '</td>
            <td>' . htmlspecialchars($log['action']) . '</td>
            <td>' . htmlspecialchars($log['previous_status'] ?? 'N/A') . '</td>
            <td>' . htmlspecialchars($log['new_status'] ?? 'N/A') . '</td>
            <td>' . date('M j, Y g:i A', strtotime($log['timestamp'])) . '</td>
        </tr>';
    }
    
    $html .= '</table>
        <p><strong>Process Monitoring:</strong> Regular review of activity logs helps identify workflow patterns, employee performance, and potential system issues that may require intervention or process improvements.</p>
    </div>';
}

// Add detailed referrals if available
if (!empty($detailed_referrals)) {
    $html .= '
    <div class="section">
        <h2>VIII. DETAILED REFERRAL RECORDS (LATEST 30)</h2>
        <p><strong>Comprehensive Listing:</strong> This section provides a detailed view of individual referral cases, including patient information, facility details, and referral outcomes. This granular data supports case-by-case analysis and helps identify specific patterns or issues.</p>
        <table>
            <tr>
                <th>Referral #</th>
                <th>Patient ID</th>
                <th>Referring Facility</th>
                <th>Referred To</th>
                <th>Type</th>
                <th>Reason</th>
                <th>Date</th>
                <th>Status</th>
            </tr>';
    
    foreach ($detailed_referrals as $referral) {
        $reason = $referral['referral_reason'] ?? '';
        $short_reason = strlen($reason) > 30 ? substr($reason, 0, 30) . '...' : $reason;
        
        $html .= '<tr>
            <td>' . htmlspecialchars($referral['referral_num']) . '</td>
            <td>' . htmlspecialchars($referral['patient_id']) . '</td>
            <td>' . htmlspecialchars($referral['referring_facility']) . '</td>
            <td>' . htmlspecialchars($referral['referred_to_facility']) . '</td>
            <td>' . ucwords(str_replace('_', ' ', $referral['destination_type'])) . '</td>
            <td>' . htmlspecialchars($short_reason) . '</td>
            <td>' . date('M j, Y', strtotime($referral['referral_date'])) . '</td>
            <td>' . ucfirst($referral['status']) . '</td>
        </tr>';
    }
    
    $html .= '</table>
        <p><strong>Data Utility:</strong> This detailed information can be used for quality assurance, follow-up activities, and identifying specific cases that may require additional attention or intervention.</p>
    </div>';
}

$html .= '
    <div class="section">
        <!--<h2>CONCLUSIONS AND RECOMMENDATIONS</h2>
        <p><strong>Data-Driven Insights:</strong> This comprehensive report contains all referral data for the selected period including facility transfers, coordination statistics, activity logs, and detailed records. The information presented supports evidence-based decision making for healthcare administration and policy development.</p>
        <p><strong>Continuous Improvement:</strong> Regular analysis of referral patterns helps identify opportunities for service enhancement, resource optimization, and improved patient care coordination. Key areas for ongoing monitoring include completion rates, response times, and facility capacity utilization.</p>
        <p><strong>Stakeholder Engagement:</strong> This report should be shared with relevant stakeholders including facility managers, clinical staff, and administrative personnel to ensure coordinated efforts in improving the referral system.</p>
        <p><strong>Next Steps:</strong> Consider implementing targeted interventions for facilities with high cancellation rates, developing specialized services for high-volume referral categories, and establishing regular review cycles for referral system performance.</p>-->
        <hr>
        <p><strong>Report Information:</strong></p>
        <p>Generated on ' . date('F j, Y \a\t g:i A') . ' by the City Health Office, Koronadal City.</p>
        <p>Data Source: Web-Based Healthcare Services Management System (WBHSMS)</p>
        <p>For questions about this report, please contact the City Health Office at CHO Koronadal.</p>
        <p><strong>Confidentiality Notice:</strong> This report contains sensitive healthcare information and should be handled according to applicable privacy regulations and organizational policies.</p>
    </div>
</body>
</html>';

// Create PDF
try {
    // Initialize Dompdf with ultra-minimal settings to avoid type errors
    $options = new Options();
    $options->set('isHtml5ParserEnabled', false);
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', false);
    $options->set('isJavascriptEnabled', false);
    $options->set('isCssFloatEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('tempDir', sys_get_temp_dir());
    $options->set('logOutputFile', null); // Disable logging to avoid file issues
    
    $dompdf = new Dompdf($options);
    
    // Use HTML directly - no processing
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Generate filename
    $filename = 'referral_summary_report_' . date('Ymd_His') . '.pdf';
    
    // Output directly to browser
    $dompdf->stream($filename, array('Attachment' => true));
    
} catch (Exception $e) {
    // PDF generation failed - show error page
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h1>PDF Generation Error</h1>';
    echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . '</p>';
    echo '<p><strong>Line:</strong> ' . $e->getLine() . '</p>';
    echo '<p><a href="referral_summary.php">← Back to Report</a></p>';
    exit();
}
exit();
?>