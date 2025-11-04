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
    $role = strtolower($userRole);
    header("Location: ../management/$role/dashboard.php");
    exit();
}

use Dompdf\Dompdf;
use Dompdf\Options;

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$status_filter = $_GET['status_filter'] ?? '';
$test_type_filter = $_GET['test_type_filter'] ?? '';
$urgency_filter = $_GET['urgency_filter'] ?? '';
$employee_filter = $_GET['employee_filter'] ?? '';

try {
    // Get filter options for dropdowns
    $employees_stmt = $pdo->query("SELECT employee_id, CONCAT(first_name, ' ', last_name) as full_name FROM employees WHERE status = 'active' ORDER BY first_name");
    $employees = $employees_stmt->fetchAll(PDO::FETCH_ASSOC);

    $test_types_stmt = $pdo->query("SELECT DISTINCT test_type FROM lab_order_items WHERE test_type IS NOT NULL AND test_type != '' ORDER BY test_type");
    $test_types = $test_types_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build where clauses for filtering
    $where_conditions = ["DATE(lo.order_date) BETWEEN :start_date AND :end_date"];
    $params = [
        'start_date' => $start_date,
        'end_date' => $end_date
    ];

    if (!empty($status_filter) && in_array($status_filter, ['completed', 'cancelled'])) {
        $where_conditions[] = "loi.status = :status_filter";
        $params['status_filter'] = $status_filter;
    }

    if (!empty($test_type_filter)) {
        $where_conditions[] = "loi.test_type LIKE :test_type_filter";
        $params['test_type_filter'] = '%' . $test_type_filter . '%';
    }

    if (!empty($urgency_filter) && in_array($urgency_filter, ['STAT', 'Routine'])) {
        $where_conditions[] = "loi.urgency = :urgency_filter";
        $params['urgency_filter'] = $urgency_filter;
    }

    if (!empty($employee_filter)) {
        $where_conditions[] = "lo.ordered_by_employee_id = :employee_filter";
        $params['employee_filter'] = $employee_filter;
    }

    $where_clause = implode(' AND ', $where_conditions);

    // 1. LAB SUMMARY STATISTICS
    $summary_query = "
        SELECT 
            COUNT(DISTINCT lo.lab_order_id) as total_orders,
            COUNT(DISTINCT loi.item_id) as total_tests,
            SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_tests,
            SUM(CASE WHEN loi.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_tests,
            ROUND((SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) * 100.0 / COUNT(loi.item_id)), 2) as completion_rate,
            ROUND(AVG(CASE 
                WHEN loi.status = 'completed' AND loi.started_at IS NOT NULL AND loi.completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.completed_at) 
                WHEN loi.status = 'completed' AND loi.started_at IS NOT NULL AND loi.completed_at IS NULL
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.updated_at)
                WHEN loi.status = 'completed' AND loi.started_at IS NULL AND loi.completed_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, loi.created_at, loi.completed_at)
                ELSE NULL 
            END), 2) as avg_turnaround_hours
        FROM lab_orders lo
        JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE $where_clause AND loi.status IN ('completed', 'cancelled')
    ";
    
    $summary_stmt = $pdo->prepare($summary_query);
    $summary_stmt->execute($params);
    $summary_stats = $summary_stmt->fetch(PDO::FETCH_ASSOC);

    // 2. LAB STATUS BREAKDOWN
    $status_breakdown_query = "
        SELECT 
            loi.status,
            COUNT(*) as count
        FROM lab_orders lo
        JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE $where_clause AND loi.status IN ('completed', 'cancelled')
        GROUP BY loi.status
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

    // 3. TOP LAB TESTS
    $top_tests_query = "
        SELECT 
            loi.test_type,
            COUNT(*) as test_count,
            SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN loi.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
            SUM(CASE WHEN loi.urgency = 'STAT' THEN 1 ELSE 0 END) as stat_count,
            ROUND((SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as completion_rate,
            ROUND(AVG(CASE 
                WHEN loi.status = 'completed' AND loi.started_at IS NOT NULL AND loi.completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.completed_at) 
                WHEN loi.status = 'completed' AND loi.started_at IS NOT NULL AND loi.completed_at IS NULL
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.updated_at)
                WHEN loi.status = 'completed' AND loi.started_at IS NULL AND loi.completed_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, loi.created_at, loi.completed_at)
                ELSE NULL 
            END), 2) as avg_turnaround_hours
        FROM lab_orders lo
        JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE $where_clause AND loi.status IN ('completed', 'cancelled')
        GROUP BY loi.test_type
        ORDER BY test_count DESC
        LIMIT 15
    ";
    
    $top_tests_stmt = $pdo->prepare($top_tests_query);
    $top_tests_stmt->execute($params);
    $top_tests = $top_tests_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. URGENCY ANALYSIS
    $urgency_analysis_query = "
        SELECT 
            loi.urgency,
            COUNT(*) as test_count,
            SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            ROUND((SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*)), 2) as completion_rate,
            ROUND(AVG(CASE 
                WHEN loi.status = 'completed' AND loi.started_at IS NOT NULL AND loi.completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.completed_at) 
                WHEN loi.status = 'completed' AND loi.started_at IS NOT NULL AND loi.completed_at IS NULL
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.updated_at)
                WHEN loi.status = 'completed' AND loi.started_at IS NULL AND loi.completed_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, loi.created_at, loi.completed_at)
                ELSE NULL 
            END), 2) as avg_turnaround_hours
        FROM lab_orders lo
        JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE $where_clause AND loi.status IN ('completed', 'cancelled')
        GROUP BY loi.urgency
        ORDER BY test_count DESC
    ";
    
    $urgency_analysis_stmt = $pdo->prepare($urgency_analysis_query);
    $urgency_analysis_stmt->execute($params);
    $urgency_analysis = $urgency_analysis_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5. DAILY LAB TRENDS
    $daily_trends_query = "
        SELECT 
            DATE(lo.order_date) as order_date,
            COUNT(DISTINCT lo.lab_order_id) as orders_count,
            COUNT(loi.item_id) as tests_count,
            SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
            SUM(CASE WHEN loi.urgency = 'STAT' THEN 1 ELSE 0 END) as stat_count
        FROM lab_orders lo
        JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE $where_clause AND loi.status IN ('completed', 'cancelled')
        GROUP BY DATE(lo.order_date)
        ORDER BY order_date DESC
        LIMIT 10
    ";
    
    $daily_trends_stmt = $pdo->prepare($daily_trends_query);
    $daily_trends_stmt->execute($params);
    $daily_trends = $daily_trends_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. TURNAROUND TIME ANALYSIS
    $turnaround_analysis_query = "
        SELECT 
            loi.test_type,
            COUNT(*) as completed_tests,
            ROUND(AVG(CASE 
                WHEN loi.started_at IS NOT NULL AND loi.completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.completed_at) 
                WHEN loi.started_at IS NOT NULL AND loi.completed_at IS NULL
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.updated_at)
                WHEN loi.started_at IS NULL AND loi.completed_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, loi.created_at, loi.completed_at)
                ELSE NULL 
            END), 2) as avg_hours,
            ROUND(MIN(CASE 
                WHEN loi.started_at IS NOT NULL AND loi.completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.completed_at) 
                WHEN loi.started_at IS NOT NULL AND loi.completed_at IS NULL
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.updated_at)
                WHEN loi.started_at IS NULL AND loi.completed_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, loi.created_at, loi.completed_at)
                ELSE NULL 
            END), 2) as min_hours,
            ROUND(MAX(CASE 
                WHEN loi.started_at IS NOT NULL AND loi.completed_at IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.completed_at) 
                WHEN loi.started_at IS NOT NULL AND loi.completed_at IS NULL
                THEN TIMESTAMPDIFF(HOUR, loi.started_at, loi.updated_at)
                WHEN loi.started_at IS NULL AND loi.completed_at IS NOT NULL
                THEN TIMESTAMPDIFF(HOUR, loi.created_at, loi.completed_at)
                ELSE NULL 
            END), 2) as max_hours
        FROM lab_orders lo
        JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE $where_clause 
        AND loi.status = 'completed'
        GROUP BY loi.test_type
        HAVING completed_tests > 0
        ORDER BY avg_hours DESC
        LIMIT 10
    ";
    
    $turnaround_analysis_stmt = $pdo->prepare($turnaround_analysis_query);
    $turnaround_analysis_stmt->execute($params);
    $turnaround_analysis = $turnaround_analysis_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle zero division and ensure data consistency
    if (!$summary_stats || !$summary_stats['total_tests']) {
        $summary_stats = [
            'total_orders' => 0,
            'total_tests' => 0,
            'completed_tests' => 0,
            'cancelled_tests' => 0,
            'completion_rate' => 0,
            'avg_turnaround_hours' => 0
        ];
    }

    // Get employee name for report
    $employee_name = ($_SESSION['employee_first_name'] ?? '') . ' ' . ($_SESSION['employee_last_name'] ?? '');
    $employee_role = ucfirst($_SESSION['role']);

    // Generate narrative analysis
    $narrative_analysis = generateNarrativeAnalysis($summary_stats, $top_tests, $urgency_analysis, $daily_trends, $turnaround_analysis);

} catch (Exception $e) {
    error_log("Lab Statistics Export Error: " . $e->getMessage());
    die("Error generating report: " . $e->getMessage());
}

// Function to generate narrative analysis
function generateNarrativeAnalysis($summary, $top_tests, $urgency, $trends, $turnaround) {
    $analysis = [];
    
    // Overall Performance Analysis
    if ($summary['total_tests'] > 0) {
        $performance_level = $summary['completion_rate'] >= 95 ? 'excellent' : 
                           ($summary['completion_rate'] >= 85 ? 'good' : 
                           ($summary['completion_rate'] >= 75 ? 'satisfactory' : 'needs improvement'));
        
        $analysis['performance'] = "During the reporting period, the laboratory processed a total of " . number_format($summary['total_tests']) . 
                                 " laboratory tests from " . number_format($summary['total_orders']) . " lab orders. " .
                                 "The overall completion rate of " . $summary['completion_rate'] . "% indicates " . $performance_level . 
                                 " laboratory performance. ";
        
        if ($summary['avg_turnaround_hours'] > 0) {
            $tat_assessment = $summary['avg_turnaround_hours'] <= 2 ? 'excellent turnaround time' :
                            ($summary['avg_turnaround_hours'] <= 6 ? 'good turnaround time' :
                            ($summary['avg_turnaround_hours'] <= 12 ? 'acceptable turnaround time' : 'lengthy processing time'));
            
            $analysis['performance'] .= "The average turnaround time of " . $summary['avg_turnaround_hours'] . 
                                      " hours demonstrates " . $tat_assessment . " in test processing efficiency.";
        }
    } else {
        $analysis['performance'] = "No laboratory test data was recorded during the specified reporting period. This may indicate low patient volume, system maintenance, or data collection issues that require investigation.";
    }
    
    // Test Volume Analysis
    if (!empty($top_tests)) {
        $most_ordered = $top_tests[0];
        $analysis['volume'] = "The most frequently ordered laboratory test was '" . $most_ordered['test_type'] . 
                            "' with " . number_format($most_ordered['test_count']) . " orders, representing the primary diagnostic focus. ";
        
        if (count($top_tests) >= 3) {
            $second_most = $top_tests[1];
            $third_most = $top_tests[2];
            $analysis['volume'] .= "This was followed by '" . $second_most['test_type'] . "' (" . 
                                 number_format($second_most['test_count']) . " orders) and '" . 
                                 $third_most['test_type'] . "' (" . number_format($third_most['test_count']) . " orders). ";
        }
        
        $high_completion_tests = array_filter($top_tests, function($test) { return $test['completion_rate'] >= 95; });
        if (count($high_completion_tests) > 0) {
            $analysis['volume'] .= "Notably, " . count($high_completion_tests) . " of the top tests achieved completion rates above 95%, indicating reliable test processing capabilities.";
        }
    } else {
        $analysis['volume'] = "No specific test volume patterns were identified during this period, suggesting either low testing activity or the need for data verification.";
    }
    
    // Urgency Analysis
    if (!empty($urgency)) {
        $stat_tests = array_filter($urgency, function($u) { return strtoupper($u['urgency']) == 'STAT'; });
        $routine_tests = array_filter($urgency, function($u) { return strtoupper($u['urgency']) == 'ROUTINE'; });
        
        if (!empty($stat_tests)) {
            $stat_data = reset($stat_tests);
            $analysis['urgency'] = "STAT (urgent) laboratory tests comprised " . number_format($stat_data['test_count']) . 
                                 " tests with a " . $stat_data['completion_rate'] . "% completion rate. ";
            
            if ($stat_data['avg_turnaround_hours'] > 0) {
                $stat_tat_assessment = $stat_data['avg_turnaround_hours'] <= 1 ? 'exceptional emergency response time' :
                                     ($stat_data['avg_turnaround_hours'] <= 3 ? 'good emergency response time' : 'concerning delay in urgent test processing');
                
                $analysis['urgency'] .= "The average turnaround time of " . $stat_data['avg_turnaround_hours'] . 
                                      " hours for STAT tests indicates " . $stat_tat_assessment . ".";
            }
        } elseif (!empty($routine_tests)) {
            $routine_data = reset($routine_tests);
            $analysis['urgency'] = "Laboratory operations were primarily routine-based with " . number_format($routine_data['test_count']) . 
                                 " routine tests and a " . $routine_data['completion_rate'] . "% completion rate, suggesting stable, non-emergency diagnostic services.";
        } else {
            $analysis['urgency'] = "Urgency classification data is limited, which may indicate the need for improved test prioritization documentation.";
        }
    } else {
        $analysis['urgency'] = "No urgency-based analysis could be performed due to insufficient data classification.";
    }
    
    // Trend Analysis
    if (!empty($trends)) {
        $total_days = count($trends);
        $avg_daily_tests = array_sum(array_column($trends, 'tests_count')) / $total_days;
        $max_daily_tests = max(array_column($trends, 'tests_count'));
        $min_daily_tests = min(array_column($trends, 'tests_count'));
        
        $analysis['trends'] = "Over the " . $total_days . "-day period analyzed, the laboratory averaged " . 
                            round($avg_daily_tests, 1) . " tests per day. ";
        
        if ($max_daily_tests > $avg_daily_tests * 1.5) {
            $analysis['trends'] .= "Peak testing volume reached " . $max_daily_tests . " tests on " . 
                                 date('M j, Y', strtotime($trends[array_search($max_daily_tests, array_column($trends, 'tests_count'))]['order_date'])) . 
                                 ", indicating periods of high diagnostic demand. ";
        }
        
        $variability = ($max_daily_tests - $min_daily_tests) / $avg_daily_tests * 100;
        if ($variability > 50) {
            $analysis['trends'] .= "The " . round($variability, 1) . "% variability in daily test volumes suggests fluctuating patient demand that may require capacity planning adjustments.";
        } else {
            $analysis['trends'] .= "Daily test volumes showed relatively stable patterns with " . round($variability, 1) . "% variability, indicating consistent laboratory utilization.";
        }
    } else {
        $analysis['trends'] = "Insufficient data points are available for meaningful trend analysis during this reporting period.";
    }
    
    // Efficiency Analysis
    if (!empty($turnaround)) {
        $fastest_test = min(array_column($turnaround, 'avg_hours'));
        $slowest_test = max(array_column($turnaround, 'avg_hours'));
        
        $analysis['efficiency'] = "Turnaround time analysis reveals processing efficiency variations across different test types. ";
        
        $fast_tests = array_filter($turnaround, function($t) { return $t['avg_hours'] <= 2; });
        $slow_tests = array_filter($turnaround, function($t) { return $t['avg_hours'] > 6; });
        
        if (!empty($fast_tests)) {
            $analysis['efficiency'] .= count($fast_tests) . " test types achieved processing times under 2 hours, demonstrating rapid diagnostic capabilities. ";
        }
        
        if (!empty($slow_tests)) {
            $analysis['efficiency'] .= count($slow_tests) . " test types required over 6 hours for completion, which may indicate complex processing requirements or workflow bottlenecks that warrant investigation.";
        } else {
            $analysis['efficiency'] .= "All analyzed test types maintained reasonable processing times, indicating efficient laboratory workflow management.";
        }
    } else {
        $analysis['efficiency'] = "Turnaround time analysis could not be performed due to insufficient processing timestamp data.";
    }
    
    return $analysis;
}

// Create PDF content
ob_start();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Laboratory Statistics Report</title>
    <style>
        @page {
            margin: 0.6in;
            @bottom-center {
                content: "Page " counter(page) " of " counter(pages);
                font-size: 9px;
                color: #666;
            }
        }
        
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 10px;
            line-height: 1.3;
            color: #333;
            margin: 0;
            padding: 0;
        }
        
        .header {
            text-align: center;
            border-bottom: 2px solid #0077b6;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }
        
        .header h1 {
            color: #0077b6;
            font-size: 20px;
            margin: 0 0 6px 0;
            font-weight: bold;
        }
        
        .header h2 {
            color: #03045e;
            font-size: 14px;
            margin: 0 0 8px 0;
            font-weight: normal;
        }
        
        .header .report-info {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 3px;
            margin: 8px 0;
            font-size: 9px;
        }
        
        .report-meta {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            font-size: 9px;
        }
        
        .report-meta div {
            flex: 1;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin-bottom: 15px;
        }
        
        .summary-card {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 3px;
            padding: 8px;
            text-align: center;
        }
        
        .summary-card .number {
            font-size: 14px;
            font-weight: bold;
            color: #0077b6;
            margin-bottom: 3px;
        }
        
        .summary-card .label {
            font-size: 8px;
            color: #666;
            text-transform: uppercase;
            line-height: 1.2;
        }
        
        .section {
            margin-bottom: 15px;
            page-break-inside: avoid;
        }
        
        .section-title {
            background: #0077b6;
            color: white;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        
        .narrative {
            background: #f8f9fa;
            border-left: 3px solid #0077b6;
            padding: 8px;
            margin-bottom: 10px;
            font-style: italic;
            line-height: 1.4;
            font-size: 9px;
        }
        
        .narrative h4 {
            color: #03045e;
            margin: 0 0 5px 0;
            font-size: 10px;
            font-style: normal;
            font-weight: bold;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
            font-size: 8px;
        }
        
        .data-table th {
            background: #e9ecef;
            border: 1px solid #dee2e6;
            padding: 4px 3px;
            text-align: left;
            font-weight: bold;
            color: #495057;
            line-height: 1.2;
        }
        
        .data-table td {
            border: 1px solid #dee2e6;
            padding: 3px 3px;
            line-height: 1.2;
        }
        
        .data-table tr:nth-child(even) {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 1px 4px;
            border-radius: 8px;
            font-size: 7px;
            font-weight: bold;
            text-transform: uppercase;
        }
        
        .status-completed { background: #d1e7dd; color: #0f5132; }
        .status-cancelled { background: #f8d7da; color: #721c24; }
        .urgency-stat { background: #f8d7da; color: #721c24; }
        .urgency-routine { background: #d1e7dd; color: #0f5132; }
        
        .key-findings {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 3px;
            padding: 8px;
            margin-bottom: 10px;
        }
        
        .key-findings h4 {
            color: #856404;
            margin: 0 0 5px 0;
            font-size: 10px;
        }
        
        .key-findings ul {
            margin: 0;
            padding-left: 15px;
        }
        
        .key-findings li {
            margin-bottom: 3px;
            font-size: 8px;
            line-height: 1.3;
        }
        
        .recommendations {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 3px;
            padding: 8px;
            margin-bottom: 10px;
        }
        
        .recommendations h4 {
            color: #0c5460;
            margin: 0 0 5px 0;
            font-size: 10px;
        }
        
        .recommendations ul {
            margin: 0;
            padding-left: 15px;
        }
        
        .recommendations li {
            margin-bottom: 3px;
            font-size: 8px;
            line-height: 1.3;
        }
        
        .footer {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px solid #dee2e6;
            font-size: 8px;
            color: #666;
        }
        
        .signature-section {
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
        }
        
        .signature-box {
            text-align: center;
            width: 150px;
        }
        
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 25px;
            padding-top: 3px;
            font-size: 8px;
        }
        
        .page-break {
            page-break-before: always;
        }
        
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: bold; }
        .text-primary { color: #0077b6; }
        .text-success { color: #28a745; }
        .text-warning { color: #ffc107; }
        .text-danger { color: #dc3545; }
        
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        
        .full-width {
            grid-column: 1 / -1;
        }
        
        .compact-section {
            margin-bottom: 8px;
        }
        
        .two-column-table {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .table-wrapper {
            overflow: hidden;
        }
    </style>
</head>
<body>
    <!-- Header Section -->
    <div class="header">
        <h1>LABORATORY STATISTICS REPORT</h1>
        <h2>City Health Office - Koronadal</h2>
        <div class="report-info">
            <strong>Report Period:</strong> <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?><br>
            <strong>Generated:</strong> <?php echo date('F j, Y g:i A'); ?><br>
            <strong>Generated by:</strong> <?php echo htmlspecialchars($employee_name); ?> (<?php echo htmlspecialchars($employee_role); ?>)
        </div>
    </div>

    <!-- Report Metadata -->
    <div class="report-meta">
        <div>
            <strong>Report Filters Applied:</strong><br>
            <?php if (!empty($status_filter)): ?>
            Status: <?php echo ucfirst($status_filter); ?><br>
            <?php endif; ?>
            <?php if (!empty($test_type_filter)): ?>
            Test Type: <?php echo htmlspecialchars($test_type_filter); ?><br>
            <?php endif; ?>
            <?php if (!empty($urgency_filter)): ?>
            Urgency: <?php echo htmlspecialchars($urgency_filter); ?><br>
            <?php endif; ?>
        </div>
        <div class="text-right">
            <strong>Report Classification:</strong> Internal Use<br>
            <strong>Data Source:</strong> Laboratory Information System<br>
            <strong>Report Type:</strong> Statistical Analysis
        </div>
    </div>

    <!-- Executive Summary -->
    <div class="section compact-section">
        <div class="section-title">EXECUTIVE SUMMARY</div>
        
        <div class="summary-grid">
            <div class="summary-card">
                <div class="number"><?php echo number_format($summary_stats['total_orders']); ?></div>
                <div class="label">Lab Orders</div>
            </div>
            <div class="summary-card">
                <div class="number"><?php echo number_format($summary_stats['total_tests']); ?></div>
                <div class="label">Total Tests</div>
            </div>
            <div class="summary-card">
                <div class="number"><?php echo $summary_stats['completion_rate']; ?>%</div>
                <div class="label">Completion Rate</div>
            </div>
            <div class="summary-card">
                <div class="number"><?php echo number_format($summary_stats['cancelled_tests']); ?></div>
                <div class="label">Cancelled</div>
            </div>
            <div class="summary-card">
                <div class="number"><?php echo $summary_stats['avg_turnaround_hours']; ?>h</div>
                <div class="label">Avg TAT</div>
            </div>
        </div>

        <div class="narrative">
            <h4>Performance Overview</h4>
            <?php echo $narrative_analysis['performance']; ?>
        </div>
    </div>

    <!-- Combined Analysis Section -->
    <div class="section compact-section">
        <div class="section-title">LABORATORY PERFORMANCE ANALYSIS</div>
        
        <div class="two-column-table">
            <!-- Status Distribution -->
            <div class="table-wrapper">
                <h4 style="margin: 0 0 5px 0; font-size: 10px; color: #0077b6;">Status Distribution</h4>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Count</th>
                            <th>%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $all_statuses = ['completed', 'cancelled'];
                        $existing_status_data = [];
                        
                        if (!empty($status_breakdown)) {
                            foreach ($status_breakdown as $status) {
                                $existing_status_data[$status['status']] = $status;
                            }
                        }
                        
                        foreach ($all_statuses as $status_name): 
                            if (isset($existing_status_data[$status_name])) {
                                $status_data = $existing_status_data[$status_name];
                                $badge_class = 'status-' . str_replace(' ', '_', $status_name);
                                echo '<tr>
                                        <td><span class="status-badge ' . $badge_class . '">' . ucfirst($status_data['status']) . '</span></td>
                                        <td class="text-center font-bold">' . number_format($status_data['count']) . '</td>
                                        <td class="text-center">' . $status_data['percentage'] . '%</td>
                                      </tr>';
                            } else {
                                $badge_class = 'status-' . str_replace(' ', '_', $status_name);
                                echo '<tr style="opacity: 0.5;">
                                        <td><span class="status-badge ' . $badge_class . '">' . ucfirst($status_name) . '</span></td>
                                        <td class="text-center">0</td>
                                        <td class="text-center">0%</td>
                                      </tr>';
                            }
                        endforeach; 
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Urgency Analysis -->
            <div class="table-wrapper">
                <h4 style="margin: 0 0 5px 0; font-size: 10px; color: #0077b6;">Urgency Analysis</h4>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Urgency</th>
                            <th>Count</th>
                            <th>TAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($urgency_analysis)): ?>
                            <?php foreach (array_slice($urgency_analysis, 0, 3) as $urgency): ?>
                                <?php $badge_class = 'urgency-' . strtolower($urgency['urgency']); ?>
                                <tr>
                                    <td><span class="urgency-badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($urgency['urgency']); ?></span></td>
                                    <td class="text-center"><?php echo number_format($urgency['test_count']); ?></td>
                                    <td class="text-center"><?php echo $urgency['avg_turnaround_hours'] ?? 'N/A'; ?>h</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="text-center" style="font-style: italic;">No urgency data</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="narrative">
            <h4>Test Volume Insights</h4>
            <?php echo $narrative_analysis['volume']; ?>
        </div>
    </div>

    <!-- Test Volume Analysis -->
    <div class="section compact-section">
        <div class="section-title">TOP LABORATORY TESTS & TRENDS</div>
        
        <div class="two-column-table">
            <!-- Top Tests -->
            <div class="table-wrapper">
                <h4 style="margin: 0 0 5px 0; font-size: 10px; color: #0077b6;">Most Ordered Tests</h4>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Test Type</th>
                            <th>Count</th>
                            <th>Rate%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($top_tests)): ?>
                            <?php foreach (array_slice($top_tests, 0, 8) as $test): ?>
                                <tr>
                                    <td class="font-bold"><?php echo htmlspecialchars(substr($test['test_type'], 0, 20)); ?></td>
                                    <td class="text-center"><?php echo number_format($test['test_count']); ?></td>
                                    <td class="text-center"><?php echo $test['completion_rate']; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="text-center" style="font-style: italic;">No test data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Daily Trends -->
            <div class="table-wrapper">
                <h4 style="margin: 0 0 5px 0; font-size: 10px; color: #0077b6;">Recent Daily Activity</h4>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Tests</th>
                            <th>Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($daily_trends)): ?>
                            <?php foreach (array_slice($daily_trends, 0, 8) as $trend): ?>
                                <tr>
                                    <td><?php echo date('M j', strtotime($trend['order_date'])); ?></td>
                                    <td class="text-center"><?php echo number_format($trend['tests_count']); ?></td>
                                    <td class="text-center"><?php echo number_format($trend['completed_count']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="text-center" style="font-style: italic;">No trend data available</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="narrative">
            <h4>Operational Trends & Efficiency</h4>
            <?php echo $narrative_analysis['trends']; ?> <?php echo $narrative_analysis['efficiency']; ?>
        </div>
    </div>

    <!-- Turnaround Time Analysis -->
    <div class="section compact-section">
        <div class="section-title">TURNAROUND TIME ANALYSIS</div>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th>Test Type</th>
                    <th>Completed</th>
                    <th>Avg TAT</th>
                    <th>Min TAT</th>
                    <th>Max TAT</th>
                    <th>Efficiency</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($turnaround_analysis)): ?>
                    <?php foreach (array_slice($turnaround_analysis, 0, 10) as $turnaround): ?>
                        <?php 
                        $efficiency = $turnaround['avg_hours'] <= 2 ? 'Excellent' : 
                                    ($turnaround['avg_hours'] <= 6 ? 'Good' : 
                                    ($turnaround['avg_hours'] <= 12 ? 'Acceptable' : 'Poor'));
                        ?>
                        <tr>
                            <td class="font-bold"><?php echo htmlspecialchars(substr($turnaround['test_type'], 0, 25)); ?></td>
                            <td class="text-center"><?php echo number_format($turnaround['completed_tests']); ?></td>
                            <td class="text-center font-bold"><?php echo $turnaround['avg_hours'] ?? 'N/A'; ?>h</td>
                            <td class="text-center"><?php echo $turnaround['min_hours'] ?? 'N/A'; ?>h</td>
                            <td class="text-center"><?php echo $turnaround['max_hours'] ?? 'N/A'; ?>h</td>
                            <td class="text-center"><?php echo $efficiency; ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="text-center" style="font-style: italic;">No turnaround data available</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Recommendations and Action Items -->
    <div class="section compact-section">
        <div class="section-title">RECOMMENDATIONS & KEY FINDINGS</div>
        
        <div class="grid-2">
            <div class="recommendations">
                <h4>Strategic Recommendations</h4>
                <ul>
                    <?php if ($summary_stats['completion_rate'] < 90): ?>
                    <li><strong>Quality:</strong> Address completion rate below 90%</li>
                    <?php endif; ?>
                    
                    <?php if ($summary_stats['avg_turnaround_hours'] > 6): ?>
                    <li><strong>Efficiency:</strong> Investigate workflow bottlenecks</li>
                    <?php endif; ?>
                    
                    <?php if (!empty($top_tests) && count($top_tests) >= 3): ?>
                    <li><strong>Resources:</strong> Optimize for top 3 tests</li>
                    <?php endif; ?>
                    
                    <li><strong>Data Quality:</strong> Ensure consistent timestamp recording</li>
                    <li><strong>Monitoring:</strong> Establish regular reporting cycles</li>
                </ul>
            </div>
            
            <div class="key-findings">
                <h4>Quality Assurance Focus</h4>
                <ul>
                    <li>Maintain completion rates above 95%</li>
                    <li>Implement TAT management SOPs</li>
                    <li>Establish performance benchmarks</li>
                    <li>Review workflow processes regularly</li>
                    <li>Ensure adequate peak staffing</li>
                </ul>
            </div>
        </div>
        
        <div class="narrative">
            <h4>Urgency Assessment</h4>
            <?php echo $narrative_analysis['urgency']; ?>
        </div>
    </div>

    <!-- Footer and Signatures -->
    <div class="footer">
        <div class="signature-section">
            <div class="signature-box">
                <div class="signature-line">
                    Laboratory Manager<br>
                    Date: _______________
                </div>
            </div>
            <div class="signature-box">
                <div class="signature-line">
                    Quality Officer<br>
                    Date: _______________
                </div>
            </div>
            <div class="signature-box">
                <div class="signature-line">
                    City Health Officer<br>
                    Date: _______________
                </div>
            </div>
        </div>
        
        <div style="margin-top: 15px; text-align: center; font-size: 7px; color: #999;">
            <p>WBHSMS - CHO Koronadal | Laboratory Performance Analysis<br>
            Generated: <?php echo date('F j, Y g:i:s A'); ?> | Control: LAB-STAT-<?php echo date('Ymd-His'); ?></p>
        </div>
    </div>
</body>
</html>

<?php
$html = ob_get_clean();

// Configure Dompdf
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Generate filename
$filename = 'Lab_Statistics_Report_' . date('Y-m-d', strtotime($start_date)) . '_to_' . date('Y-m-d', strtotime($end_date)) . '_Generated_' . date('Ymd_His') . '.pdf';

// Stream the PDF
$dompdf->stream($filename, array('Attachment' => false));
?>