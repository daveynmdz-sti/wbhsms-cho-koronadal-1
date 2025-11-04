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
    $role = strtolower($userRole);
    header("Location: ../management/$role/dashboard.php");
    exit();
}

// Get filter parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Today
$payment_status_filter = $_GET['payment_status_filter'] ?? '';
$payment_method_filter = $_GET['payment_method_filter'] ?? '';
$cashier_filter = $_GET['cashier_filter'] ?? '';

// Enhanced Financial Analytics Queries (same as main report)
// Summary Statistics Query
$summary_query = "
    SELECT 
        COUNT(DISTINCT b.billing_id) as total_invoices,
        COUNT(bi.billing_item_id) as total_billing_items,
        COUNT(DISTINCT p.payment_id) as total_payments,
        COALESCE(SUM(b.net_amount), 0) as total_net,
        COALESCE(SUM(b.paid_amount), 0) as total_collections,
        COALESCE(AVG(b.net_amount), 0) as avg_invoice_amount
    FROM billing b 
    LEFT JOIN billing_items bi ON b.billing_id = bi.billing_id 
    LEFT JOIN payments p ON b.billing_id = p.billing_id 
    WHERE b.billing_date BETWEEN :start_date AND :end_date
";

$where_conditions = [];
$params = [
    'start_date' => $start_date,
    'end_date' => $end_date
];

if (!empty($payment_status_filter) && in_array($payment_status_filter, ['unpaid', 'partial', 'paid', 'exempted', 'cancelled'])) {
    $where_conditions[] = "b.payment_status = :payment_status_filter";
    $params['payment_status_filter'] = $payment_status_filter;
}

if (!empty($payment_method_filter) && in_array($payment_method_filter, ['cash', 'card', 'online', 'check'])) {
    $where_conditions[] = "p.payment_method = :payment_method_filter";
    $params['payment_method_filter'] = $payment_method_filter;
}

if (!empty($cashier_filter)) {
    $where_conditions[] = "p.cashier_id = :cashier_filter";
    $params['cashier_filter'] = $cashier_filter;
}

if (!empty($where_conditions)) {
    $summary_query .= " AND " . implode(" AND ", $where_conditions);
}

$summary_stmt = $pdo->prepare($summary_query);
$summary_stmt->execute($params);
$summary_stats = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// Payment Status Distribution Query
$status_query = "
    SELECT 
        payment_status,
        COUNT(*) as status_count,
        COALESCE(SUM(net_amount), 0) as status_amount
    FROM billing b
    WHERE billing_date BETWEEN :start_date AND :end_date
";

if (!empty($where_conditions)) {
    $status_query .= " AND " . implode(" AND ", array_filter($where_conditions, function($condition) {
        return !str_contains($condition, 'p.');
    }));
}

$status_query .= " GROUP BY payment_status ORDER BY status_count DESC";

$status_stmt = $pdo->prepare($status_query);
$status_params = array_filter($params, function($key) {
    return !in_array($key, ['payment_method_filter', 'cashier_filter']);
}, ARRAY_FILTER_USE_KEY);
$status_stmt->execute($status_params);
$payment_status_distribution = $status_stmt->fetchAll(PDO::FETCH_ASSOC);

// Daily Collections Query (Top 15)
$daily_collections_query = "
    SELECT 
        DATE(b.billing_date) as collection_date,
        COUNT(DISTINCT b.billing_id) as daily_invoices,
        COUNT(bi.billing_item_id) as daily_items,
        COALESCE(SUM(b.net_amount), 0) as daily_net,
        COALESCE(SUM(b.paid_amount), 0) as daily_collections,
        COUNT(DISTINCT p.payment_id) as daily_payments
    FROM billing b 
    LEFT JOIN billing_items bi ON b.billing_id = bi.billing_id 
    LEFT JOIN payments p ON b.billing_id = p.billing_id 
    WHERE b.billing_date BETWEEN :start_date AND :end_date
";

if (!empty($where_conditions)) {
    $daily_collections_query .= " AND " . implode(" AND ", $where_conditions);
}

$daily_collections_query .= " GROUP BY DATE(b.billing_date) ORDER BY collection_date DESC LIMIT 15";

$daily_stmt = $pdo->prepare($daily_collections_query);
$daily_stmt->execute($params);
$daily_collections = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);

// Top Service Items Query (Top 10)
$top_service_items_query = "
    SELECT 
        si.item_name,
        COUNT(bi.billing_item_id) as times_billed,
        SUM(bi.quantity) as total_quantity,
        si.price_php,
        AVG(bi.item_price) as avg_price,
        SUM(bi.subtotal) as total_revenue
    FROM billing_items bi 
    JOIN service_items si ON bi.service_item_id = si.item_id 
    JOIN billing b ON bi.billing_id = b.billing_id 
    WHERE b.billing_date BETWEEN :start_date AND :end_date
";

if (!empty($where_conditions)) {
    $top_service_items_query .= " AND " . implode(" AND ", $where_conditions);
}

$top_service_items_query .= " GROUP BY si.item_id ORDER BY times_billed DESC, total_revenue DESC LIMIT 10";

$top_service_stmt = $pdo->prepare($top_service_items_query);
$top_service_stmt->execute($params);
$top_service_items = $top_service_stmt->fetchAll(PDO::FETCH_ASSOC);

// Weekly Collections Query
$weekly_collections_query = "
    SELECT 
        YEAR(b.billing_date) as year,
        WEEK(b.billing_date, 1) as week_number,
        DATE(DATE_SUB(b.billing_date, INTERVAL WEEKDAY(b.billing_date) DAY)) as week_start,
        COUNT(DISTINCT b.billing_id) as weekly_invoices,
        COUNT(bi.billing_item_id) as weekly_items,
        COALESCE(SUM(b.net_amount), 0) as weekly_net,
        COALESCE(SUM(b.paid_amount), 0) as weekly_collections
    FROM billing b 
    LEFT JOIN billing_items bi ON b.billing_id = bi.billing_id 
    LEFT JOIN payments p ON b.billing_id = p.billing_id 
    WHERE b.billing_date BETWEEN :start_date AND :end_date
";

if (!empty($where_conditions)) {
    $weekly_collections_query .= " AND " . implode(" AND ", $where_conditions);
}

$weekly_collections_query .= " GROUP BY YEAR(b.billing_date), WEEK(b.billing_date, 1) ORDER BY year DESC, week_number DESC LIMIT 12";

$weekly_stmt = $pdo->prepare($weekly_collections_query);
$weekly_stmt->execute($params);
$weekly_collections = $weekly_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate weekly average
$weekly_avg = 0;
if (!empty($weekly_collections)) {
    $weekly_avg = array_sum(array_column($weekly_collections, 'weekly_collections')) / count($weekly_collections);
}

// Monthly Collections Query
$monthly_collections_query = "
    SELECT 
        YEAR(b.billing_date) as year,
        MONTH(b.billing_date) as month,
        MONTHNAME(b.billing_date) as month_name,
        COUNT(DISTINCT b.billing_id) as monthly_invoices,
        COUNT(bi.billing_item_id) as monthly_items,
        COALESCE(SUM(b.net_amount), 0) as monthly_net,
        COALESCE(SUM(b.paid_amount), 0) as monthly_collections
    FROM billing b 
    LEFT JOIN billing_items bi ON b.billing_id = bi.billing_id 
    LEFT JOIN payments p ON b.billing_id = p.billing_id 
    WHERE b.billing_date BETWEEN :start_date AND :end_date
";

if (!empty($where_conditions)) {
    $monthly_collections_query .= " AND " . implode(" AND ", $where_conditions);
}

$monthly_collections_query .= " GROUP BY YEAR(b.billing_date), MONTH(b.billing_date) ORDER BY year DESC, month DESC LIMIT 12";

$monthly_stmt = $pdo->prepare($monthly_collections_query);
$monthly_stmt->execute($params);
$monthly_collections = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate monthly average
$monthly_avg = 0;
if (!empty($monthly_collections)) {
    $monthly_avg = array_sum(array_column($monthly_collections, 'monthly_collections')) / count($monthly_collections);
}

// Calculate daily average
$daily_avg = 0;
if (!empty($daily_collections)) {
    $daily_avg = array_sum(array_column($daily_collections, 'daily_collections')) / count($daily_collections);
}

// Payment Methods Analysis Query
$payment_methods_query = "
    SELECT 
        payment_method,
        COUNT(*) as payment_count,
        SUM(amount_paid) as total_collected,
        AVG(amount_paid) as avg_payment
    FROM payments p
    JOIN billing b ON p.billing_id = b.billing_id
    WHERE b.billing_date BETWEEN :start_date AND :end_date
";

if (!empty($where_conditions)) {
    $payment_methods_query .= " AND " . implode(" AND ", $where_conditions);
}

$payment_methods_query .= " GROUP BY payment_method ORDER BY payment_count DESC";

$methods_stmt = $pdo->prepare($payment_methods_query);
$methods_stmt->execute($params);
$payment_methods = $methods_stmt->fetchAll(PDO::FETCH_ASSOC);

// Cashier Performance Query
$cashier_performance_query = "
    SELECT 
        e.employee_id,
        CONCAT(e.first_name, ' ', e.last_name) as cashier_name,
        COUNT(DISTINCT p.payment_id) as payments_processed,
        SUM(p.amount_paid) as total_collected,
        AVG(p.amount_paid) as avg_payment,
        COUNT(DISTINCT b.billing_id) as invoices_handled
    FROM payments p
    JOIN employees e ON p.cashier_id = e.employee_id
    JOIN billing b ON p.billing_id = b.billing_id
    WHERE b.billing_date BETWEEN :start_date AND :end_date
";

if (!empty($where_conditions)) {
    $cashier_performance_query .= " AND " . implode(" AND ", $where_conditions);
}

$cashier_performance_query .= " GROUP BY e.employee_id ORDER BY total_collected DESC LIMIT 10";

$cashier_stmt = $pdo->prepare($cashier_performance_query);
$cashier_stmt->execute($params);
$cashier_performance = $cashier_stmt->fetchAll(PDO::FETCH_ASSOC);

// Start output buffering to capture HTML
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Financial Performance Report</title>
    <style>
        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            margin: 0.6in;
            font-size: 10px;
            line-height: 1.3;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #1a365d;
            padding-bottom: 15px;
        }
        
        .header h1 {
            margin: 0;
            color: #1a365d;
            font-size: 16px;
            font-weight: bold;
        }
        
        .header h2 {
            margin: 5px 0;
            color: #666;
            font-size: 12px;
            font-weight: normal;
        }
        
        .report-meta {
            margin: 15px 0;
            text-align: center;
            font-size: 9px;
            color: #666;
        }
        
        .section {
            margin: 15px 0;
            page-break-inside: avoid;
        }
        
        .section-title {
            background: #1a365d;
            color: white;
            padding: 6px 10px;
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 8px;
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
            width: 50%;
            padding: 4px 8px;
            border: 1px solid #ddd;
            vertical-align: middle;
        }
        
        .summary-cell.label {
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .summary-cell.value {
            text-align: right;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 8px;
        }
        
        .data-table th {
            background: #f1f3f4;
            padding: 5px 4px;
            border: 1px solid #ddd;
            font-weight: bold;
            text-align: center;
        }
        
        .data-table td {
            padding: 4px;
            border: 1px solid #ddd;
            text-align: left;
        }
        
        .data-table td.number {
            text-align: right;
        }
        
        .data-table td.center {
            text-align: center;
        }
        
        .data-table tr:nth-child(even) {
            background: #f9f9f9;
        }
        
        .compact-section {
            margin: 10px 0;
        }
        
        .two-column {
            display: table;
            width: 100%;
        }
        
        .column {
            display: table-cell;
            width: 50%;
            padding: 0 10px;
            vertical-align: top;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 8px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        
        .page-break {
            page-break-after: always;
        }
        
        @page {
            margin: 0.6in;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>CITY HEALTH OFFICE OF KORONADAL</h1>
        <h2>Zone 1, Koronadal City, South Cotabato | Contact: (083) 228-8000</h2>
        <h1 style="margin-top: 15px;">FINANCIAL PERFORMANCE REPORT</h1>
    </div>
    
    <div class="report-meta">
        <strong>Report Period:</strong> <?php echo date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date)); ?><br>
        <strong>Generated:</strong> <?php echo date('F j, Y g:i A'); ?>
    </div>

    <!-- Financial Summary -->
    <div class="section compact-section">
        <div class="section-title">FINANCIAL SUMMARY</div>
        <div class="summary-grid">
            <div class="summary-row">
                <div class="summary-cell label">Total Invoices</div>
                <div class="summary-cell value"><?php echo number_format($summary_stats['total_invoices']); ?></div>
            </div>
            <div class="summary-row">
                <div class="summary-cell label">Total Billing Items</div>
                <div class="summary-cell value"><?php echo number_format($summary_stats['total_billing_items']); ?></div>
            </div>
            <div class="summary-row">
                <div class="summary-cell label">Total Payments Processed</div>
                <div class="summary-cell value"><?php echo number_format($summary_stats['total_payments']); ?></div>
            </div>
            <div class="summary-row">
                <div class="summary-cell label">Net Amount</div>
                <div class="summary-cell value">₱<?php echo number_format($summary_stats['total_net'], 2); ?></div>
            </div>
            <div class="summary-row">
                <div class="summary-cell label">Total Collections</div>
                <div class="summary-cell value"><strong>₱<?php echo number_format($summary_stats['total_collections'], 2); ?></strong></div>
            </div>
            <div class="summary-row">
                <div class="summary-cell label">Average Invoice Amount</div>
                <div class="summary-cell value">₱<?php echo number_format($summary_stats['avg_invoice_amount'], 2); ?></div>
            </div>
        </div>
    </div>

    <!-- Payment Status Distribution -->
    <div class="section compact-section">
        <div class="section-title">PAYMENT STATUS DISTRIBUTION</div>
        <?php if (!empty($payment_status_distribution)): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Payment Status</th>
                        <th>Count</th>
                        <th>Amount</th>
                        <th>Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_count = array_sum(array_column($payment_status_distribution, 'status_count'));
                    foreach ($payment_status_distribution as $status): 
                        $percentage = $total_count > 0 ? round(($status['status_count'] / $total_count) * 100, 1) : 0;
                    ?>
                        <tr>
                            <td><?php echo ucfirst($status['payment_status']); ?></td>
                            <td class="number"><?php echo number_format($status['status_count']); ?></td>
                            <td class="number">₱<?php echo number_format($status['status_amount'], 2); ?></td>
                            <td class="number"><?php echo $percentage; ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align: center; font-style: italic;">No payment status data available for the selected period.</p>
        <?php endif; ?>
    </div>

    <!-- Daily Collections Analysis (Top 15) -->
    <div class="section compact-section">
        <div class="section-title">DAILY COLLECTIONS ANALYSIS (Top 15 Days)</div>
        <?php if (!empty($daily_collections)): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Invoices</th>
                        <th>Items</th>
                        <th>Net Amount</th>
                        <th>Collections</th>
                        <th>Payments</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($daily_collections, 0, 15) as $daily): ?>
                        <tr>
                            <td class="center"><?php echo date('M j, Y', strtotime($daily['collection_date'])); ?></td>
                            <td class="number"><?php echo number_format($daily['daily_invoices']); ?></td>
                            <td class="number"><?php echo number_format($daily['daily_items']); ?></td>
                            <td class="number">₱<?php echo number_format($daily['daily_net'], 2); ?></td>
                            <td class="number"><strong>₱<?php echo number_format($daily['daily_collections'], 2); ?></strong></td>
                            <td class="number"><?php echo number_format($daily['daily_payments']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top: 10px; text-align: center; font-weight: bold; color: #1a365d;">
                Daily Average: ₱<?php echo number_format($daily_avg, 2); ?>
            </div>
        <?php else: ?>
            <p style="text-align: center; font-style: italic;">No daily collections data available for the selected period.</p>
        <?php endif; ?>
    </div>

    <!-- Weekly Collections Analysis -->
    <div class="section compact-section">
        <div class="section-title">WEEKLY COLLECTIONS ANALYSIS (Last 12 Weeks)</div>
        <?php if (!empty($weekly_collections)): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Week Period</th>
                        <th>Year</th>
                        <th>Week #</th>
                        <th>Invoices</th>
                        <th>Items</th>
                        <th>Net Amount</th>
                        <th>Collections</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($weekly_collections, 0, 12) as $weekly): ?>
                        <tr>
                            <td class="center"><?php echo date('M j', strtotime($weekly['week_start'])) . ' - ' . date('M j, Y', strtotime($weekly['week_start'] . ' +6 days')); ?></td>
                            <td class="center"><?php echo $weekly['year']; ?></td>
                            <td class="center"><?php echo $weekly['week_number']; ?></td>
                            <td class="number"><?php echo number_format($weekly['weekly_invoices']); ?></td>
                            <td class="number"><?php echo number_format($weekly['weekly_items']); ?></td>
                            <td class="number">₱<?php echo number_format($weekly['weekly_net'], 2); ?></td>
                            <td class="number"><strong>₱<?php echo number_format($weekly['weekly_collections'], 2); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top: 10px; text-align: center; font-weight: bold; color: #1a365d;">
                Weekly Average: ₱<?php echo number_format($weekly_avg, 2); ?>
            </div>
        <?php else: ?>
            <p style="text-align: center; font-style: italic;">No weekly collections data available for the selected period.</p>
        <?php endif; ?>
    </div>

    <!-- Monthly Collections Analysis -->
    <div class="section compact-section">
        <div class="section-title">MONTHLY COLLECTIONS ANALYSIS (Last 12 Months)</div>
        <?php if (!empty($monthly_collections)): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Year</th>
                        <th>Invoices</th>
                        <th>Items</th>
                        <th>Net Amount</th>
                        <th>Collections</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($monthly_collections, 0, 12) as $monthly): ?>
                        <tr>
                            <td><?php echo $monthly['month_name']; ?></td>
                            <td class="center"><?php echo $monthly['year']; ?></td>
                            <td class="number"><?php echo number_format($monthly['monthly_invoices']); ?></td>
                            <td class="number"><?php echo number_format($monthly['monthly_items']); ?></td>
                            <td class="number">₱<?php echo number_format($monthly['monthly_net'], 2); ?></td>
                            <td class="number"><strong>₱<?php echo number_format($monthly['monthly_collections'], 2); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top: 10px; text-align: center; font-weight: bold; color: #1a365d;">
                Monthly Average: ₱<?php echo number_format($monthly_avg, 2); ?>
            </div>
        <?php else: ?>
            <p style="text-align: center; font-style: italic;">No monthly collections data available for the selected period.</p>
        <?php endif; ?>
    </div>

    <!-- Top Service Items -->
    <div class="section compact-section">
        <div class="section-title">TOP SERVICE ITEMS (Most Frequently Billed)</div>
        <?php if (!empty($top_service_items)): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Service Item</th>
                        <th>Times Billed</th>
                        <th>Quantity</th>
                        <th>Avg Price</th>
                        <th>Total Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($top_service_items, 0, 15) as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td class="number"><?php echo number_format($item['times_billed']); ?></td>
                            <td class="number"><?php echo number_format($item['total_quantity']); ?></td>
                            <td class="number">₱<?php echo number_format($item['avg_price'], 2); ?></td>
                            <td class="number"><strong>₱<?php echo number_format($item['total_revenue'], 2); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align: center; font-style: italic;">No service items data available for the selected period.</p>
        <?php endif; ?>
    </div>

    <!-- Payment Methods Analysis -->
    <div class="section compact-section">
        <div class="section-title">PAYMENT METHODS ANALYSIS</div>
        <?php if (!empty($payment_methods)): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Payment Method</th>
                        <th>Count</th>
                        <th>Total Collected</th>
                        <th>Average Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payment_methods as $method): ?>
                        <tr>
                            <td><?php echo ucfirst($method['payment_method']); ?></td>
                            <td class="number"><?php echo number_format($method['payment_count']); ?></td>
                            <td class="number">₱<?php echo number_format($method['total_collected'], 2); ?></td>
                            <td class="number">₱<?php echo number_format($method['avg_payment'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align: center; font-style: italic;">No payment methods data available for the selected period.</p>
        <?php endif; ?>
    </div>

    <!-- Cashier Performance Analysis -->
    <div class="section compact-section">
        <div class="section-title">CASHIER PERFORMANCE ANALYSIS (Top 10)</div>
        <?php if (!empty($cashier_performance)): ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Cashier Name</th>
                        <th>Payments Processed</th>
                        <th>Invoices Handled</th>
                        <th>Total Collected</th>
                        <th>Average Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($cashier_performance, 0, 10) as $cashier): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($cashier['cashier_name']); ?></td>
                            <td class="number"><?php echo number_format($cashier['payments_processed']); ?></td>
                            <td class="number"><?php echo number_format($cashier['invoices_handled']); ?></td>
                            <td class="number"><strong>₱<?php echo number_format($cashier['total_collected'], 2); ?></strong></td>
                            <td class="number">₱<?php echo number_format($cashier['avg_payment'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align: center; font-style: italic;">No cashier performance data available for the selected period.</p>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p><strong>Comprehensive Financial Report Notes:</strong></p>
        <p>• <strong>Daily/Weekly/Monthly Analysis:</strong> Shows collection trends with averages for period planning<br>
        • <strong>Collections vs Invoices:</strong> Actual payments received vs total amounts billed<br>
        • <strong>Payment Status Distribution:</strong> Tracks unpaid, partial, and fully paid invoices<br>
        • <strong>Service Item Popularity:</strong> Most frequently billed services for demand analysis<br>
        • <strong>Payment Methods:</strong> Cash, card, and other payment channel performance<br>
        • <strong>Cashier Performance:</strong> Individual staff productivity and collection efficiency<br>
        • <strong>Average Calculations:</strong> Exclude zero/null values for accurate metrics<br>
        • <strong>Data Currency:</strong> Financial data is current as of report generation time</p>
        
        <p style="margin-top: 15px;">
            <strong>WBHSMS - City Health Office of Koronadal | Comprehensive Financial Performance Analysis</strong><br>
            Generated: <?php echo date('F j, Y g:i:s A'); ?> | Control: FIN-COMP-<?php echo date('Ymd-His'); ?><br>
            <em>Report includes: Daily/Weekly/Monthly collections, Payment status breakdown, Service analysis, Cashier performance</em>
        </p>
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
$filename = 'Financial_Report_' . date('Y-m-d', strtotime($start_date)) . '_to_' . date('Y-m-d', strtotime($end_date)) . '_Generated_' . date('Ymd_His') . '.pdf';

// Stream the PDF
$dompdf->stream($filename, array('Attachment' => true));
exit;
?>