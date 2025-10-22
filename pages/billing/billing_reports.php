<?php
/**
 * Billing Reports Dashboard
 * Purpose: Generate comprehensive billing analytics and financial reports
 * UI Pattern: Sidebar only (list/management page) - Matches billing management structure
 */

// Ensure output buffering is active (but don't create unnecessary nested buffers)
if (ob_get_level() === 0) {
    ob_start();
}

// Include employee session configuration
// Use absolute path resolution
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

// Dynamic asset path detection for production compatibility
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];

// Extract base path from script location - go up 3 levels from /pages/billing/file.php to root
$base_path = dirname(dirname(dirname($script_name)));
if ($base_path === '/' || $base_path === '.') {
    $base_path = '';
}

// Construct full asset URL for production
$assets_path = $protocol . '://' . $host . $base_path . '/assets';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header("Location: ../auth/employee_login.php");
    exit();
}

// Set active page for sidebar highlighting
$activePage = 'billing_reports';

// Define role-based permissions for billing reports using role_id
$canViewReports = in_array($_SESSION['role_id'], [1, 8]); // admin, cashier
$canExportReports = in_array($_SESSION['role_id'], [1, 8]); // admin, cashier
$canViewCashierPerformance = in_array($_SESSION['role_id'], [1]); // admin only

if (!$canViewReports) {
    $role_id = $_SESSION['role_id'];
    // Map role_id to role name for redirect
    $roleMap = [
        1 => 'admin',
        2 => 'doctor', 
        3 => 'nurse',
        4 => 'pharmacist',
        5 => 'dho',
        6 => 'bhw',
        7 => 'records_officer',
        8 => 'cashier',
        9 => 'laboratory_tech'
    ];
    $role = $roleMap[$role_id] ?? 'employee';
    header("Location: ../management/$role/dashboard.php");
    exit();
}

// Get report parameters
$report_type = $_GET['report_type'] ?? 'monthly';
$month = $_GET['month'] ?? date('Y-m');
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');

try {
    // Monthly Collections Report
    if ($report_type === 'monthly') {
        $month_start = date('Y-m-01', strtotime($month));
        $month_end = date('Y-m-t', strtotime($month));
        
        // Daily collections summary
        $daily_collections_query = "SELECT 
                                        DATE(p.paid_at) as payment_date,
                                        COUNT(*) as payment_count,
                                        SUM(p.amount_paid) as daily_total
                                    FROM payments p
                                    WHERE DATE(p.paid_at) BETWEEN ? AND ?
                                    GROUP BY DATE(p.paid_at)
                                    ORDER BY payment_date ASC";
        
        $stmt = $pdo->prepare($daily_collections_query);
        $stmt->execute([$month_start, $month_end]);
        $daily_collections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Monthly summary
        $monthly_summary_query = "SELECT 
                                    COUNT(DISTINCT b.billing_id) as total_invoices,
                                    COUNT(p.payment_id) as total_payments,
                                    SUM(p.amount_paid) as total_collected,
                                    AVG(p.amount_paid) as average_payment,
                                    COUNT(CASE WHEN b.payment_status = 'paid' THEN 1 END) as paid_invoices,
                                    COUNT(CASE WHEN b.payment_status = 'partial' THEN 1 END) as partial_invoices,
                                    COUNT(CASE WHEN b.payment_status = 'unpaid' THEN 1 END) as unpaid_invoices
                                  FROM billing b
                                  LEFT JOIN payments p ON b.billing_id = p.billing_id AND DATE(p.paid_at) BETWEEN ? AND ?
                                  WHERE DATE(b.billing_date) BETWEEN ? AND ?";
        
        $stmt = $pdo->prepare($monthly_summary_query);
        $stmt->execute([$month_start, $month_end, $month_start, $month_end]);
        $monthly_summary = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Service Analytics Report
    $services_query = "SELECT 
                          si.item_name,
                          s.service_name,
                          COUNT(bi.billing_item_id) as frequency,
                          SUM(bi.quantity) as total_quantity,
                          SUM(bi.subtotal) as total_revenue,
                          AVG(bi.item_price) as average_price
                       FROM billing_items bi
                       JOIN service_items si ON bi.service_item_id = si.item_id
                       JOIN services s ON si.service_id = s.service_id
                       JOIN billing b ON bi.billing_id = b.billing_id
                       WHERE DATE(b.billing_date) BETWEEN ? AND ?
                       GROUP BY si.item_id, si.item_name, s.service_name
                       ORDER BY total_revenue DESC
                       LIMIT 20";
    
    $stmt = $pdo->prepare($services_query);
    $stmt->execute([$date_from, $date_to]);
    $service_analytics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Payment Method Analysis
    $payment_methods_query = "SELECT 
                                p.payment_method,
                                COUNT(*) as transaction_count,
                                SUM(p.amount_paid) as total_amount,
                                AVG(p.amount_paid) as average_amount
                              FROM payments p
                              WHERE DATE(p.paid_at) BETWEEN ? AND ?
                              GROUP BY p.payment_method
                              ORDER BY total_amount DESC";
    
    $stmt = $pdo->prepare($payment_methods_query);
    $stmt->execute([$date_from, $date_to]);
    $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Outstanding Balances
    $outstanding_query = "SELECT 
                            b.billing_id,
                            b.billing_date,
                            p.first_name,
                            p.last_name,
                            p.username as patient_username,
                            b.net_amount,
                            b.paid_amount,
                            (b.net_amount - b.paid_amount) as outstanding_amount,
                            b.payment_status
                          FROM billing b
                          JOIN patients p ON b.patient_id = p.patient_id
                          WHERE b.payment_status IN ('unpaid', 'partial')
                          ORDER BY outstanding_amount DESC
                          LIMIT 50";
    
    $stmt = $pdo->prepare($outstanding_query);
    $stmt->execute();
    $outstanding_balances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Cashier Performance (if admin)
    $cashier_performance = [];
    if ($canViewCashierPerformance) {
        $cashier_query = "SELECT 
                            e.first_name,
                            e.last_name,
                            e.role,
                            COUNT(p.payment_id) as payments_processed,
                            SUM(p.amount_paid) as total_collected,
                            AVG(p.amount_paid) as average_transaction
                          FROM employees e
                          JOIN payments p ON e.employee_id = p.cashier_id
                          WHERE DATE(p.paid_at) BETWEEN ? AND ?
                          GROUP BY e.employee_id, e.first_name, e.last_name, e.role
                          ORDER BY total_collected DESC";
        
        $stmt = $pdo->prepare($cashier_query);
        $stmt->execute([$date_from, $date_to]);
        $cashier_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {
    $error_message = 'Error generating reports: ' . $e->getMessage();
    $daily_collections = [];
    $monthly_summary = [];
    $service_analytics = [];
    $payment_methods = [];
    $outstanding_balances = [];
    $cashier_performance = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Reports - WBHSMS</title>
    <link rel="stylesheet" href="<?= $assets_path ?>/css/sidebar.css">
    <link rel="stylesheet" href="<?= $assets_path ?>/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Content wrapper and page layout - matches billing_management */
        .content-wrapper {
            margin-left: 300px;
            padding: 20px;
            min-height: 100vh;
            transition: margin-left 0.3s;
        }

        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
            }
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .page-header h1 {
            margin: 0;
            color: #03045e;
            font-size: 1.8rem;
            font-weight: 700;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #666;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.375rem;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #0066cc;
            color: white;
        }

        .btn-primary:hover {
            background: #0052a3;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        /* Form elements styling to match billing management */
        select, input[type="text"], input[type="date"], input[type="month"] {
            padding: 0.5rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
            width: 100%;
        }

        select:focus, input:focus {
            border-color: #007bff;
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        label {
            display: block;
            margin-bottom: 0.25rem;
            font-weight: 500;
            color: #495057;
            font-size: 0.9rem;
        }
        
        .report-filters {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .filters-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }
        
        .report-section {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .report-section h3 {
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
            color: #495057;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .summary-card {
            text-align: center;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 4px solid #007bff;
        }
        
        .summary-card.revenue {
            border-left-color: #28a745;
        }
        
        .summary-card.warning {
            border-left-color: #ffc107;
        }
        
        .summary-card.danger {
            border-left-color: #dc3545;
        }
        
        .summary-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #495057;
        }
        
        .summary-label {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .data-table th,
        .data-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        .data-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        .data-table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .chart-container {
            height: 300px;
            margin: 1rem 0;
            position: relative;
        }
        
        .print-section {
            margin-top: 2rem;
            text-align: center;
        }
        
        @media print {
            .sidebar, .report-filters, .print-section, .breadcrumb, .page-header .btn {
                display: none;
            }
            
            .content-wrapper {
                margin-left: 0;
                padding: 10px;
            }
            
            .report-section {
                break-inside: avoid;
                margin-bottom: 1rem;
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
        
        .tab-buttons {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .tab-btn {
            padding: 0.5rem 1rem;
            border: 1px solid #dee2e6;
            background: #f8f9fa;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.3s;
        }
        
        .tab-btn.active {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }

        /* Status badge styling to match billing management */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-unpaid {
            background-color: #f8d7da;
            color: #721c24;
        }

        .status-partial {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-paid {
            background-color: #d4edda;
            color: #155724;
        }

        .text-muted {
            color: #6c757d !important;
        }
    </style>
</head>

<body>
    <!-- Set active page for sidebar highlighting -->
    <?php $activePage = 'billing_reports'; ?>

    <!-- Include role-based sidebar -->
    <?php
    require_once $root_path . '/includes/dynamic_sidebar_helper.php';
    includeDynamicSidebar($activePage, $root_path);
    ?>

    <section class="content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb" style="margin-top: 50px;">
            <a href="<?= getRoleDashboardUrl() ?>"><i class="fas fa-home"></i> Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <a href="billing_management.php"><i class="fas fa-file-invoice-dollar"></i> Billing Management</a>
            <i class="fas fa-chevron-right"></i>
            <span>Billing Reports</span>
        </div>

        <div class="page-header">
            <div>
                <h1><i class="fas fa-chart-bar"></i> Billing Reports</h1>
                <p style="margin: 0.5rem 0 0 0; color: #666;">Comprehensive billing analytics and financial reports</p>
            </div>
            <div>
                <a href="billing_management.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Billing
                </a>
            </div>
        </div>

            <!-- Report Filters -->
            <div class="report-filters">
                <h3><i class="fas fa-filter"></i> Report Parameters</h3>
                <form method="GET" id="report-form">
                    <div class="filters-row">
                        <div>
                            <label for="report_type">Report Type</label>
                            <select id="report_type" name="report_type" onchange="toggleDateInputs()">
                                <option value="monthly" <?= $report_type === 'monthly' ? 'selected' : '' ?>>Monthly Report</option>
                                <option value="custom" <?= $report_type === 'custom' ? 'selected' : '' ?>>Custom Date Range</option>
                            </select>
                        </div>
                        
                        <div id="month-input" style="<?= $report_type === 'custom' ? 'display: none;' : '' ?>">
                            <label for="month">Month</label>
                            <input type="month" id="month" name="month" value="<?= $month ?>">
                        </div>
                        
                        <div id="date-range" style="<?= $report_type === 'monthly' ? 'display: none;' : '' ?>">
                            <label for="date_from">From Date</label>
                            <input type="date" id="date_from" name="date_from" value="<?= $date_from ?>">
                        </div>
                        
                        <div id="date-range-to" style="<?= $report_type === 'monthly' ? 'display: none;' : '' ?>">
                            <label for="date_to">To Date</label>
                            <input type="date" id="date_to" name="date_to" value="<?= $date_to ?>">
                        </div>
                        
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-chart-line"></i> Generate Report
                            </button>
                            <?php if ($canExportReports): ?>
                            <button type="button" class="btn btn-secondary" onclick="exportReport()">
                                <i class="fas fa-download"></i> Export
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($report_type === 'monthly' && !empty($monthly_summary)): ?>
            <!-- Monthly Summary -->
            <div class="report-section">
                <h3><i class="fas fa-calendar"></i> Monthly Summary - <?= date('F Y', strtotime($month)) ?></h3>
                
                <div class="summary-grid">
                    <div class="summary-card revenue">
                        <div class="summary-number">₱<?= number_format($monthly_summary['total_collected'] ?? 0, 2) ?></div>
                        <div class="summary-label">Total Collections</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-number"><?= $monthly_summary['total_invoices'] ?? 0 ?></div>
                        <div class="summary-label">Total Invoices</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-number"><?= $monthly_summary['total_payments'] ?? 0 ?></div>
                        <div class="summary-label">Total Payments</div>
                    </div>
                    <div class="summary-card">
                        <div class="summary-number">₱<?= number_format($monthly_summary['average_payment'] ?? 0, 2) ?></div>
                        <div class="summary-label">Average Payment</div>
                    </div>
                    <div class="summary-card revenue">
                        <div class="summary-number"><?= $monthly_summary['paid_invoices'] ?? 0 ?></div>
                        <div class="summary-label">Paid Invoices</div>
                    </div>
                    <div class="summary-card warning">
                        <div class="summary-number"><?= $monthly_summary['partial_invoices'] ?? 0 ?></div>
                        <div class="summary-label">Partial Payments</div>
                    </div>
                    <div class="summary-card danger">
                        <div class="summary-number"><?= $monthly_summary['unpaid_invoices'] ?? 0 ?></div>
                        <div class="summary-label">Unpaid Invoices</div>
                    </div>
                </div>

                <?php if (!empty($daily_collections)): ?>
                <h4>Daily Collections</h4>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="text-center">Payments</th>
                            <th class="text-right">Daily Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($daily_collections as $day): ?>
                        <tr>
                            <td><?= date('M d, Y (D)', strtotime($day['payment_date'])) ?></td>
                            <td class="text-center"><?= $day['payment_count'] ?></td>
                            <td class="text-right">₱<?= number_format($day['daily_total'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Service Analytics -->
            <?php if (!empty($service_analytics)): ?>
            <div class="report-section">
                <h3><i class="fas fa-chart-pie"></i> Service Analytics</h3>
                <p class="text-muted">Most availed services by revenue and frequency</p>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Service Item</th>
                            <th>Service Category</th>
                            <th class="text-center">Frequency</th>
                            <th class="text-center">Total Quantity</th>
                            <th class="text-right">Average Price</th>
                            <th class="text-right">Total Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($service_analytics as $service): ?>
                        <tr>
                            <td><?= htmlspecialchars($service['item_name']) ?></td>
                            <td><?= htmlspecialchars($service['service_name']) ?></td>
                            <td class="text-center"><?= $service['frequency'] ?></td>
                            <td class="text-center"><?= $service['total_quantity'] ?></td>
                            <td class="text-right">₱<?= number_format($service['average_price'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($service['total_revenue'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Payment Methods -->
            <?php if (!empty($payment_methods)): ?>
            <div class="report-section">
                <h3><i class="fas fa-credit-card"></i> Payment Methods Analysis</h3>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Payment Method</th>
                            <th class="text-center">Transactions</th>
                            <th class="text-right">Total Amount</th>
                            <th class="text-right">Average Transaction</th>
                            <th class="text-right">Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_amount = array_sum(array_column($payment_methods, 'total_amount'));
                        foreach ($payment_methods as $method): 
                            $percentage = $total_amount > 0 ? ($method['total_amount'] / $total_amount) * 100 : 0;
                        ?>
                        <tr>
                            <td><?= strtoupper($method['payment_method']) ?></td>
                            <td class="text-center"><?= $method['transaction_count'] ?></td>
                            <td class="text-right">₱<?= number_format($method['total_amount'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($method['average_amount'], 2) ?></td>
                            <td class="text-right"><?= number_format($percentage, 1) ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Outstanding Balances -->
            <?php if (!empty($outstanding_balances)): ?>
            <div class="report-section">
                <h3><i class="fas fa-exclamation-triangle"></i> Outstanding Balances</h3>
                <p class="text-muted">Unpaid and partially paid invoices requiring follow-up</p>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Invoice ID</th>
                            <th>Invoice Date</th>
                            <th>Patient</th>
                            <th class="text-right">Invoice Total</th>
                            <th class="text-right">Amount Paid</th>
                            <th class="text-right">Outstanding</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($outstanding_balances as $balance): ?>
                        <tr>
                            <td><?= $balance['billing_id'] ?></td>
                            <td><?= date('M d, Y', strtotime($balance['billing_date'])) ?></td>
                            <td>
                                <?= htmlspecialchars($balance['first_name'] . ' ' . $balance['last_name']) ?><br>
                                <small><?= htmlspecialchars($balance['patient_username']) ?></small>
                            </td>
                            <td class="text-right">₱<?= number_format($balance['net_amount'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($balance['paid_amount'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($balance['outstanding_amount'], 2) ?></td>
                            <td>
                                <span class="status-badge status-<?= $balance['payment_status'] ?>">
                                    <?= ucfirst($balance['payment_status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Cashier Performance (Admin only) -->
            <?php if ($canViewCashierPerformance && !empty($cashier_performance)): ?>
            <div class="report-section">
                <h3><i class="fas fa-users"></i> Cashier Performance</h3>
                <p class="text-muted">Payment processing performance by cashier</p>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Cashier</th>
                            <th>Role</th>
                            <th class="text-center">Payments Processed</th>
                            <th class="text-right">Total Collected</th>
                            <th class="text-right">Average Transaction</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cashier_performance as $cashier): ?>
                        <tr>
                            <td><?= htmlspecialchars($cashier['first_name'] . ' ' . $cashier['last_name']) ?></td>
                            <td><?= ucfirst($cashier['role']) ?></td>
                            <td class="text-center"><?= $cashier['payments_processed'] ?></td>
                            <td class="text-right">₱<?= number_format($cashier['total_collected'], 2) ?></td>
                            <td class="text-right">₱<?= number_format($cashier['average_transaction'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Print Section -->
            <div class="print-section">
                <button type="button" class="btn btn-secondary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Report
                </button>
                <a href="billing_management.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Back to Billing
                </a>
            </div>
    </section>

    <script>
        function toggleDateInputs() {
            const reportType = document.getElementById('report_type').value;
            const monthInput = document.getElementById('month-input');
            const dateRange = document.getElementById('date-range');
            const dateRangeTo = document.getElementById('date-range-to');
            
            if (reportType === 'monthly') {
                monthInput.style.display = 'block';
                dateRange.style.display = 'none';
                dateRangeTo.style.display = 'none';
            } else {
                monthInput.style.display = 'none';
                dateRange.style.display = 'block';
                dateRangeTo.style.display = 'block';
            }
        }

        function exportReport() {
            // Simple CSV export - can be enhanced for more formats
            const tables = document.querySelectorAll('.data-table');
            let csvContent = "data:text/csv;charset=utf-8,";
            
            tables.forEach((table, index) => {
                const section = table.closest('.report-section');
                const title = section.querySelector('h3').textContent;
                
                csvContent += title + "\n\n";
                
                // Headers
                const headers = Array.from(table.querySelectorAll('th')).map(th => th.textContent.trim());
                csvContent += headers.join(',') + "\n";
                
                // Rows
                const rows = table.querySelectorAll('tbody tr');
                rows.forEach(row => {
                    const cells = Array.from(row.querySelectorAll('td')).map(td => {
                        return '"' + td.textContent.trim().replace(/"/g, '""') + '"';
                    });
                    csvContent += cells.join(',') + "\n";
                });
                
                csvContent += "\n";
            });

            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `billing_report_${new Date().toISOString().split('T')[0]}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Initialize date inputs
        toggleDateInputs();
    </script>
</body>
</html>