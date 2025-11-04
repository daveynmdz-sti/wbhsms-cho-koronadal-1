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
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Today
$payment_status_filter = $_GET['payment_status_filter'] ?? '';
$payment_method_filter = $_GET['payment_method_filter'] ?? '';
$cashier_filter = $_GET['cashier_filter'] ?? '';

try {
    // Get filter options for dropdowns
    $cashiers_stmt = $pdo->query("SELECT employee_id, CONCAT(first_name, ' ', last_name) as full_name FROM employees WHERE status = 'active' ORDER BY first_name");
    $cashiers = $cashiers_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build where clauses for filtering
    $where_conditions = ["DATE(b.billing_date) BETWEEN :start_date AND :end_date"];
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

    $where_clause = implode(' AND ', $where_conditions);

    // 1. FINANCIAL SUMMARY STATISTICS
    $summary_query = "
        SELECT 
            COUNT(DISTINCT b.billing_id) as total_invoices,
            COUNT(DISTINCT bi.billing_item_id) as total_billing_items,
            COUNT(DISTINCT p.payment_id) as total_payments,
            SUM(b.total_amount) as total_gross_revenue,
            SUM(b.discount_amount) as total_discounts,
            SUM(b.net_amount) as total_net_revenue,
            SUM(b.paid_amount) as total_collections,
            SUM(CASE WHEN b.payment_status = 'paid' THEN 1 ELSE 0 END) as fully_paid_invoices,
            SUM(CASE WHEN b.payment_status = 'partial' THEN 1 ELSE 0 END) as partial_paid_invoices,
            SUM(CASE WHEN b.payment_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_invoices,
            ROUND(AVG(b.net_amount), 2) as avg_invoice_amount,
            ROUND((SUM(b.paid_amount) * 100.0 / NULLIF(SUM(b.net_amount), 0)), 2) as collection_rate
        FROM billing b
        LEFT JOIN billing_items bi ON b.billing_id = bi.billing_id
        LEFT JOIN payments p ON b.billing_id = p.billing_id
        WHERE $where_clause
    ";
    
    $summary_stmt = $pdo->prepare($summary_query);
    $summary_stmt->execute($params);
    $summary_stats = $summary_stmt->fetch(PDO::FETCH_ASSOC);

    // 2. DAILY COLLECTIONS ANALYSIS
    $daily_collections_query = "
        SELECT 
            DATE(b.billing_date) as collection_date,
            COUNT(DISTINCT b.billing_id) as daily_invoices,
            COUNT(DISTINCT bi.billing_item_id) as daily_items,
            SUM(b.total_amount) as daily_gross,
            SUM(b.net_amount) as daily_net,
            SUM(b.paid_amount) as daily_collections,
            COUNT(DISTINCT p.payment_id) as daily_payments
        FROM billing b
        LEFT JOIN billing_items bi ON b.billing_id = bi.billing_id
        LEFT JOIN payments p ON b.billing_id = p.billing_id
        WHERE $where_clause
        GROUP BY DATE(b.billing_date)
        ORDER BY collection_date DESC
        LIMIT 30
    ";
    
    $daily_collections_stmt = $pdo->prepare($daily_collections_query);
    $daily_collections_stmt->execute($params);
    $daily_collections = $daily_collections_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate daily average
    $daily_avg = 0;
    if (!empty($daily_collections)) {
        $daily_avg = array_sum(array_column($daily_collections, 'daily_collections')) / count($daily_collections);
    }

    // 3. WEEKLY COLLECTIONS ANALYSIS
    $weekly_collections_query = "
        SELECT 
            YEAR(b.billing_date) as year,
            WEEK(b.billing_date, 1) as week,
            DATE(DATE_SUB(b.billing_date, INTERVAL WEEKDAY(b.billing_date) DAY)) as week_start,
            COUNT(DISTINCT b.billing_id) as weekly_invoices,
            SUM(b.net_amount) as weekly_net,
            SUM(b.paid_amount) as weekly_collections
        FROM billing b
        WHERE $where_clause
        GROUP BY YEAR(b.billing_date), WEEK(b.billing_date, 1)
        ORDER BY year DESC, week DESC
        LIMIT 12
    ";
    
    $weekly_collections_stmt = $pdo->prepare($weekly_collections_query);
    $weekly_collections_stmt->execute($params);
    $weekly_collections = $weekly_collections_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate weekly average
    $weekly_avg = 0;
    if (!empty($weekly_collections)) {
        $weekly_avg = array_sum(array_column($weekly_collections, 'weekly_collections')) / count($weekly_collections);
    }

    // 4. MONTHLY COLLECTIONS ANALYSIS
    $monthly_collections_query = "
        SELECT 
            YEAR(b.billing_date) as year,
            MONTH(b.billing_date) as month,
            MONTHNAME(b.billing_date) as month_name,
            COUNT(DISTINCT b.billing_id) as monthly_invoices,
            SUM(b.net_amount) as monthly_net,
            SUM(b.paid_amount) as monthly_collections
        FROM billing b
        WHERE $where_clause
        GROUP BY YEAR(b.billing_date), MONTH(b.billing_date)
        ORDER BY year DESC, month DESC
        LIMIT 12
    ";
    
    $monthly_collections_stmt = $pdo->prepare($monthly_collections_query);
    $monthly_collections_stmt->execute($params);
    $monthly_collections = $monthly_collections_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate monthly average
    $monthly_avg = 0;
    if (!empty($monthly_collections)) {
        $monthly_avg = array_sum(array_column($monthly_collections, 'monthly_collections')) / count($monthly_collections);
    }

    // 5. PAYMENT STATUS BREAKDOWN
    $payment_status_query = "
        SELECT 
            b.payment_status,
            COUNT(*) as count,
            SUM(b.net_amount) as total_amount,
            SUM(b.paid_amount) as paid_amount
        FROM billing b
        WHERE $where_clause
        GROUP BY b.payment_status
        ORDER BY count DESC
    ";
    
    $payment_status_stmt = $pdo->prepare($payment_status_query);
    $payment_status_stmt->execute($params);
    $payment_status_breakdown = $payment_status_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate percentages for payment status
    $total_status_count = array_sum(array_column($payment_status_breakdown, 'count'));
    foreach ($payment_status_breakdown as &$status) {
        $status['percentage'] = $total_status_count > 0 ? round(($status['count'] / $total_status_count) * 100, 2) : 0;
    }

    // 6. TOP SERVICE ITEMS ANALYSIS
    $top_service_items_query = "
        SELECT 
            si.item_name,
            si.price_php,
            COUNT(bi.billing_item_id) as times_billed,
            SUM(bi.quantity) as total_quantity,
            SUM(bi.subtotal) as total_revenue,
            ROUND(AVG(bi.item_price), 2) as avg_price
        FROM billing_items bi
        JOIN service_items si ON bi.service_item_id = si.item_id
        JOIN billing b ON bi.billing_id = b.billing_id
        WHERE $where_clause
        GROUP BY si.item_id, si.item_name, si.price_php
        ORDER BY times_billed DESC, total_revenue DESC
        LIMIT 15
    ";
    
    $top_service_items_stmt = $pdo->prepare($top_service_items_query);
    $top_service_items_stmt->execute($params);
    $top_service_items = $top_service_items_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. PAYMENT METHOD ANALYSIS
    $payment_method_query = "
        SELECT 
            p.payment_method,
            COUNT(*) as payment_count,
            SUM(p.amount_paid) as total_collected,
            ROUND(AVG(p.amount_paid), 2) as avg_payment
        FROM payments p
        JOIN billing b ON p.billing_id = b.billing_id
        WHERE $where_clause
        GROUP BY p.payment_method
        ORDER BY total_collected DESC
    ";
    
    $payment_method_stmt = $pdo->prepare($payment_method_query);
    $payment_method_stmt->execute($params);
    $payment_methods = $payment_method_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 8. CASHIER PERFORMANCE ANALYSIS
    $cashier_performance_query = "
        SELECT 
            CONCAT(e.first_name, ' ', e.last_name) as cashier_name,
            COUNT(DISTINCT p.payment_id) as transactions_processed,
            SUM(p.amount_paid) as total_collected,
            ROUND(AVG(p.amount_paid), 2) as avg_transaction,
            COUNT(DISTINCT b.billing_id) as unique_billings
        FROM payments p
        JOIN employees e ON p.cashier_id = e.employee_id
        JOIN billing b ON p.billing_id = b.billing_id
        WHERE $where_clause
        GROUP BY p.cashier_id, e.first_name, e.last_name
        ORDER BY total_collected DESC
        LIMIT 10
    ";
    
    $cashier_performance_stmt = $pdo->prepare($cashier_performance_query);
    $cashier_performance_stmt->execute($params);
    $cashier_performance = $cashier_performance_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle zero division and ensure data consistency
    if (!$summary_stats || !$summary_stats['total_invoices']) {
        $summary_stats = [
            'total_invoices' => 0,
            'total_billing_items' => 0,
            'total_payments' => 0,
            'total_gross_revenue' => 0,
            'total_discounts' => 0,
            'total_net_revenue' => 0,
            'total_collections' => 0,
            'fully_paid_invoices' => 0,
            'partial_paid_invoices' => 0,
            'unpaid_invoices' => 0,
            'avg_invoice_amount' => 0,
            'collection_rate' => 0
        ];
    }

} catch (Exception $e) {
    error_log("Financial Report Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    $error = "Error generating financial report: " . $e->getMessage();
    
    // Initialize empty arrays to prevent undefined variable errors
    $summary_stats = [
        'total_invoices' => 0,
        'total_billing_items' => 0,
        'total_payments' => 0,
        'total_gross_revenue' => 0,
        'total_discounts' => 0,
        'total_net_revenue' => 0,
        'total_collections' => 0,
        'fully_paid_invoices' => 0,
        'partial_paid_invoices' => 0,
        'unpaid_invoices' => 0,
        'avg_invoice_amount' => 0,
        'collection_rate' => 0
    ];
    $daily_collections = [];
    $weekly_collections = [];
    $monthly_collections = [];
    $payment_status_breakdown = [];
    $top_service_items = [];
    $payment_methods = [];
    $cashier_performance = [];
    $cashiers = [];
    $daily_avg = 0;
    $weekly_avg = 0;
    $monthly_avg = 0;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Report - CHO Koronadal</title>

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

        /* Report overview section */
        .report-overview {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .report-overview h2 {
            color: var(--primary-dark);
            font-size: 22px;
            font-weight: 600;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .report-overview h2 i {
            color: var(--primary-color);
        }

        .report-overview p {
            color: var(--text-light);
            margin: 0;
            line-height: 1.6;
        }

        /* Financial content section */
        .financial-content {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .financial-content h3 {
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .financial-content h3 i {
            color: var(--primary-color);
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

        .filter-section h3 i {
            color: var(--primary-color);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            font-weight: 500;
            color: var(--text-dark);
            font-size: 14px;
        }

        .filter-group input,
        .filter-group select {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
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

        .summary-card .percentage {
            font-size: 16px;
            color: var(--success-color);
            font-weight: 600;
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

        .full-width-section {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
            margin-bottom: 30px;
        }

        .full-width-section h3 {
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .full-width-section h3 i {
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

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-paid {
            background: #d1e7dd;
            color: #0f5132;
        }

        .status-partial {
            background: #fff3cd;
            color: #664d03;
        }

        .status-unpaid {
            background: #f8d7da;
            color: #721c24;
        }

        .status-exempted {
            background: #d1ecf1;
            color: #055160;
        }

        .status-cancelled {
            background: #e2e3e5;
            color: #41464b;
        }

        /* Average cards */
        .average-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 15px;
        }

        .avg-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            border: 1px solid var(--border-color);
        }

        .avg-card .avg-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--success-color);
            margin-bottom: 5px;
        }

        .avg-card .avg-label {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
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

        .tab-button:hover {
            color: var(--primary-color);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Placeholder for financial data */
        .financial-placeholder {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
            font-style: italic;
        }

        .financial-placeholder i {
            font-size: 48px;
            color: var(--accent-color);
            margin-bottom: 15px;
            display: block;
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 24px;
            }

            .report-overview {
                padding: 20px;
            }

            .financial-content {
                padding: 20px;
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
                <span> Financial Report</span>
            </div>

            <div class="page-header">
                <h1><i class="fas fa-file-invoice-dollar"></i> Financial Report</h1>
                <p>Comprehensive analysis of revenue, expenses, billing performance, and financial health indicators</p>
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

            <!-- Report Overview -->
            <div class="report-overview">
                <h2><i class="fas fa-info-circle"></i> Report Overview</h2>
                <p>This report provides detailed insights into financial performance including revenue collections, billing summaries, payment trends, and service profitability analysis. Use this data to monitor financial health, optimize pricing strategies, and make informed budgetary decisions for sustainable healthcare operations.</p>
            </div>

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
                            <label for="payment_status_filter">Payment Status</label>
                            <select id="payment_status_filter" name="payment_status_filter">
                                <option value="">All Statuses</option>
                                <option value="paid" <?php echo $payment_status_filter == 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="partial" <?php echo $payment_status_filter == 'partial' ? 'selected' : ''; ?>>Partial</option>
                                <option value="unpaid" <?php echo $payment_status_filter == 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                <option value="exempted" <?php echo $payment_status_filter == 'exempted' ? 'selected' : ''; ?>>Exempted</option>
                                <option value="cancelled" <?php echo $payment_status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="payment_method_filter">Payment Method</label>
                            <select id="payment_method_filter" name="payment_method_filter">
                                <option value="">All Methods</option>
                                <option value="cash" <?php echo $payment_method_filter == 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="card" <?php echo $payment_method_filter == 'card' ? 'selected' : ''; ?>>Card</option>
                                <option value="online" <?php echo $payment_method_filter == 'online' ? 'selected' : ''; ?>>Online</option>
                                <option value="check" <?php echo $payment_method_filter == 'check' ? 'selected' : ''; ?>>Check</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="cashier_filter">Cashier</label>
                            <select id="cashier_filter" name="cashier_filter">
                                <option value="">All Cashiers</option>
                                <?php foreach ($cashiers as $cashier): ?>
                                    <option value="<?php echo $cashier['employee_id']; ?>" 
                                        <?php echo $cashier_filter == $cashier['employee_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cashier['full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
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
                        <a href="export_financial_pdf.php?<?php echo http_build_query($_GET); ?>" class="btn btn-export" target="_blank">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </a>
                    </div>
                </form>
            </div>

            <!-- Summary Statistics -->
            <div class="summary-cards">
                <div class="summary-card">
                    <i class="fas fa-file-invoice"></i>
                    <div class="number"><?php echo number_format($summary_stats['total_invoices'] ?? 0); ?></div>
                    <div class="label">Total Invoices</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-list-ul"></i>
                    <div class="number"><?php echo number_format($summary_stats['total_billing_items'] ?? 0); ?></div>
                    <div class="label">Billing Items</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-credit-card"></i>
                    <div class="number"><?php echo number_format($summary_stats['total_payments'] ?? 0); ?></div>
                    <div class="label">Payments Processed</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-peso-sign"></i>
                    <div class="number">₱<?php echo number_format($summary_stats['total_collections'] ?? 0, 2); ?></div>
                    <div class="label">Total Collections</div>
                    <div class="percentage"><?php echo number_format($summary_stats['collection_rate'] ?? 0, 1); ?>% Rate</div>
                </div>
                <div class="summary-card">
                    <i class="fas fa-chart-line"></i>
                    <div class="number">₱<?php echo number_format($summary_stats['avg_invoice_amount'] ?? 0, 2); ?></div>
                    <div class="label">Avg Invoice Amount</div>
                </div>
            </div>

            <!-- Collections Analysis Grid -->
            <div class="analytics-grid">
                <!-- Payment Status Breakdown -->
                <div class="analytics-section">
                    <h3><i class="fas fa-chart-pie"></i> Payment Status Distribution</h3>
                    <p style="font-size: 14px; color: var(--text-light); margin-bottom: 15px;">
                        Invoice payment status breakdown (Period: <?php echo $start_date; ?> to <?php echo $end_date; ?>)
                    </p>
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Count</th>
                                <th>Amount</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $all_statuses = ['paid', 'partial', 'unpaid', 'exempted', 'cancelled'];
                            $existing_status_data = [];
                            
                            if (!empty($payment_status_breakdown)) {
                                foreach ($payment_status_breakdown as $status) {
                                    $existing_status_data[$status['payment_status']] = $status;
                                }
                            }
                            
                            foreach ($all_statuses as $status_name): 
                                if (isset($existing_status_data[$status_name])) {
                                    $status_data = $existing_status_data[$status_name];
                                    $badge_class = 'status-' . str_replace(' ', '_', $status_name);
                                    echo '
                                    <tr>
                                        <td><span class="status-badge ' . $badge_class . '">' . ucfirst(str_replace('_', ' ', $status_data['payment_status'])) . '</span></td>
                                        <td class="count">' . number_format($status_data['count']) . '</td>
                                        <td class="count">₱' . number_format($status_data['total_amount'], 2) . '</td>
                                        <td class="percentage">' . $status_data['percentage'] . '%</td>
                                    </tr>';
                                } else {
                                    $badge_class = 'status-' . str_replace(' ', '_', $status_name);
                                    echo '
                                    <tr style="opacity: 0.5;">
                                        <td><span class="status-badge ' . $badge_class . '">' . ucfirst(str_replace('_', ' ', $status_name)) . '</span></td>
                                        <td class="count">0</td>
                                        <td class="count">₱0.00</td>
                                        <td class="percentage">0.00%</td>
                                    </tr>';
                                }
                            endforeach; 
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- Collection Averages -->
                <div class="analytics-section">
                    <h3><i class="fas fa-calculator"></i> Collection Averages</h3>
                    <p style="font-size: 14px; color: var(--text-light); margin-bottom: 15px;">
                        Daily, weekly, and monthly collection averages
                    </p>
                    <div class="average-cards">
                        <div class="avg-card">
                            <div class="avg-number">₱<?php echo number_format($daily_avg, 2); ?></div>
                            <div class="avg-label">Daily Average</div>
                        </div>
                        <div class="avg-card">
                            <div class="avg-number">₱<?php echo number_format($weekly_avg, 2); ?></div>
                            <div class="avg-label">Weekly Average</div>
                        </div>
                        <div class="avg-card">
                            <div class="avg-number">₱<?php echo number_format($monthly_avg, 2); ?></div>
                            <div class="avg-label">Monthly Average</div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Tabbed Analytics -->
            <div class="tab-container">
                <div class="tab-buttons">
                    <button class="tab-button active" onclick="showTab('daily-collections')">
                        <i class="fas fa-calendar-day"></i> Daily Collections
                    </button>
                    <button class="tab-button" onclick="showTab('weekly-collections')">
                        <i class="fas fa-calendar-week"></i> Weekly Collections
                    </button>
                    <button class="tab-button" onclick="showTab('monthly-collections')">
                        <i class="fas fa-calendar-alt"></i> Monthly Collections
                    </button>
                    <button class="tab-button" onclick="showTab('service-items')">
                        <i class="fas fa-list"></i> Top Services
                    </button>
                    <button class="tab-button" onclick="showTab('payment-methods')">
                        <i class="fas fa-credit-card"></i> Payment Methods
                    </button>
                </div>

                <!-- Daily Collections Tab -->
                <div id="daily-collections" class="tab-content active">
                    <div class="full-width-section">
                        <h3><i class="fas fa-calendar-day"></i> Daily Collections Analysis</h3>
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Invoices</th>
                                    <th>Items</th>
                                    <th>Gross Amount</th>
                                    <th>Net Amount</th>
                                    <th>Collections</th>
                                    <th>Payments</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($daily_collections)): ?>
                                    <?php foreach (array_slice($daily_collections, 0, 20) as $daily): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($daily['collection_date'])); ?></td>
                                            <td class="count"><?php echo number_format($daily['daily_invoices']); ?></td>
                                            <td class="count"><?php echo number_format($daily['daily_items']); ?></td>
                                            <td class="count">₱<?php echo number_format($daily['daily_gross'], 2); ?></td>
                                            <td class="count">₱<?php echo number_format($daily['daily_net'], 2); ?></td>
                                            <td class="count" style="font-weight: bold; color: var(--success-color);">₱<?php echo number_format($daily['daily_collections'], 2); ?></td>
                                            <td class="count"><?php echo number_format($daily['daily_payments']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- Fill remaining rows if less than 20 -->
                                    <?php for ($i = count($daily_collections); $i < 20; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No data</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="count">₱0.00</td>
                                            <td class="count">₱0.00</td>
                                            <td class="count">₱0.00</td>
                                            <td class="count">0</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <!-- Show 20 empty rows when no data -->
                                    <?php for ($i = 1; $i <= 20; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No daily data</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="count">₱0.00</td>
                                            <td class="count">₱0.00</td>
                                            <td class="count">₱0.00</td>
                                            <td class="count">0</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Weekly Collections Tab -->
                <div id="weekly-collections" class="tab-content">
                    <div class="full-width-section">
                        <h3><i class="fas fa-calendar-week"></i> Weekly Collections Analysis</h3>
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Week Period</th>
                                    <th>Year</th>
                                    <th>Week #</th>
                                    <th>Invoices</th>
                                    <th>Net Amount</th>
                                    <th>Collections</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($weekly_collections)): ?>
                                    <?php foreach (array_slice($weekly_collections, 0, 12) as $weekly): ?>
                                        <tr>
                                            <td><?php echo date('M j', strtotime($weekly['week_start'])) . ' - ' . date('M j, Y', strtotime($weekly['week_start'] . ' +6 days')); ?></td>
                                            <td class="count"><?php echo $weekly['year']; ?></td>
                                            <td class="count"><?php echo $weekly['week']; ?></td>
                                            <td class="count"><?php echo number_format($weekly['weekly_invoices']); ?></td>
                                            <td class="count">₱<?php echo number_format($weekly['weekly_net'], 2); ?></td>
                                            <td class="count" style="font-weight: bold; color: var(--success-color);">₱<?php echo number_format($weekly['weekly_collections'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- Fill remaining rows if less than 12 -->
                                    <?php for ($i = count($weekly_collections); $i < 12; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No data</td>
                                            <td class="count">-</td>
                                            <td class="count">-</td>
                                            <td class="count">0</td>
                                            <td class="count">₱0.00</td>
                                            <td class="count">₱0.00</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <!-- Show 12 empty rows when no data -->
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No weekly data</td>
                                            <td class="count">-</td>
                                            <td class="count">-</td>
                                            <td class="count">0</td>
                                            <td class="count">₱0.00</td>
                                            <td class="count">₱0.00</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Monthly Collections Tab -->
                <div id="monthly-collections" class="tab-content">
                    <div class="full-width-section">
                        <h3><i class="fas fa-calendar-alt"></i> Monthly Collections Analysis</h3>
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Year</th>
                                    <th>Invoices</th>
                                    <th>Net Amount</th>
                                    <th>Collections</th>
                                    <th>Collection Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($monthly_collections)): ?>
                                    <?php foreach (array_slice($monthly_collections, 0, 12) as $monthly): ?>
                                        <?php $collection_rate = $monthly['monthly_net'] > 0 ? round(($monthly['monthly_collections'] / $monthly['monthly_net']) * 100, 2) : 0; ?>
                                        <tr>
                                            <td class="count"><strong><?php echo $monthly['month_name']; ?></strong></td>
                                            <td class="count"><?php echo $monthly['year']; ?></td>
                                            <td class="count"><?php echo number_format($monthly['monthly_invoices']); ?></td>
                                            <td class="count">₱<?php echo number_format($monthly['monthly_net'], 2); ?></td>
                                            <td class="count" style="font-weight: bold; color: var(--success-color);">₱<?php echo number_format($monthly['monthly_collections'], 2); ?></td>
                                            <td class="percentage"><?php echo $collection_rate; ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- Fill remaining rows if less than 12 -->
                                    <?php for ($i = count($monthly_collections); $i < 12; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No data</td>
                                            <td class="count">-</td>
                                            <td class="count">0</td>
                                            <td class="count">₱0.00</td>
                                            <td class="count">₱0.00</td>
                                            <td class="percentage">0.00%</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <!-- Show 12 empty rows when no data -->
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No monthly data</td>
                                            <td class="count">-</td>
                                            <td class="count">0</td>
                                            <td class="count">₱0.00</td>
                                            <td class="count">₱0.00</td>
                                            <td class="percentage">0.00%</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Top Service Items Tab -->
                <div id="service-items" class="tab-content">
                    <div class="full-width-section">
                        <h3><i class="fas fa-list"></i> Most Billed Service Items</h3>
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Service Item</th>
                                    <th>Times Billed</th>
                                    <th>Total Quantity</th>
                                    <th>Standard Price</th>
                                    <th>Avg Price</th>
                                    <th>Total Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($top_service_items)): ?>
                                    <?php foreach (array_slice($top_service_items, 0, 15) as $item): ?>
                                        <tr>
                                            <td class="count"><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                                            <td class="count"><?php echo number_format($item['times_billed']); ?></td>
                                            <td class="count"><?php echo number_format($item['total_quantity']); ?></td>
                                            <td class="count">₱<?php echo number_format($item['price_php'], 2); ?></td>
                                            <td class="count">₱<?php echo number_format($item['avg_price'], 2); ?></td>
                                            <td class="count" style="font-weight: bold; color: var(--success-color);">₱<?php echo number_format($item['total_revenue'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <!-- Fill remaining rows if less than 15 -->
                                    <?php for ($i = count($top_service_items); $i < 15; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No data</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="count">₱0.00</td>
                                            <td class="count">₱0.00</td>
                                            <td class="count">₱0.00</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <!-- Show 15 empty rows when no data -->
                                    <?php for ($i = 1; $i <= 15; $i++): ?>
                                        <tr style="opacity: 0.5;">
                                            <td style="font-style: italic; color: var(--text-light);">No service data</td>
                                            <td class="count">0</td>
                                            <td class="count">0</td>
                                            <td class="count">₱0.00</td>
                                            <td class="count">₱0.00</td>
                                            <td class="count">₱0.00</td>
                                        </tr>
                                    <?php endfor; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Payment Methods Tab -->
                <div id="payment-methods" class="tab-content">
                    <div class="full-width-section">
                        <h3><i class="fas fa-credit-card"></i> Payment Methods & Cashier Performance</h3>
                        
                        <div class="analytics-grid">
                            <!-- Payment Methods -->
                            <div class="analytics-section">
                                <h3><i class="fas fa-money-bill-wave"></i> Payment Methods</h3>
                                <table class="analytics-table">
                                    <thead>
                                        <tr>
                                            <th>Method</th>
                                            <th>Count</th>
                                            <th>Total</th>
                                            <th>Average</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($payment_methods)): ?>
                                            <?php foreach ($payment_methods as $method): ?>
                                                <tr>
                                                    <td class="count"><strong><?php echo ucfirst($method['payment_method']); ?></strong></td>
                                                    <td class="count"><?php echo number_format($method['payment_count']); ?></td>
                                                    <td class="count">₱<?php echo number_format($method['total_collected'], 2); ?></td>
                                                    <td class="count">₱<?php echo number_format($method['avg_payment'], 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="4" class="text-center" style="font-style: italic;">No payment method data</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Cashier Performance -->
                            <div class="analytics-section">
                                <h3><i class="fas fa-user-tie"></i> Cashier Performance</h3>
                                <table class="analytics-table">
                                    <thead>
                                        <tr>
                                            <th>Cashier</th>
                                            <th>Transactions</th>
                                            <th>Total Collected</th>
                                            <th>Avg Transaction</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($cashier_performance)): ?>
                                            <?php foreach (array_slice($cashier_performance, 0, 8) as $cashier): ?>
                                                <tr>
                                                    <td class="count"><strong><?php echo htmlspecialchars($cashier['cashier_name']); ?></strong></td>
                                                    <td class="count"><?php echo number_format($cashier['transactions_processed']); ?></td>
                                                    <td class="count">₱<?php echo number_format($cashier['total_collected'], 2); ?></td>
                                                    <td class="count">₱<?php echo number_format($cashier['avg_transaction'], 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr><td colspan="4" class="text-center" style="font-style: italic;">No cashier performance data</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
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

        // Enhanced functionality
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Financial Report loaded successfully');
            
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

            // Add row highlighting to all analytics tables
            const analyticsRows = document.querySelectorAll('.analytics-table tbody tr');
            analyticsRows.forEach(row => {
                row.addEventListener('mouseenter', () => {
                    row.style.backgroundColor = 'var(--accent-color)';
                    row.style.transition = 'background-color 0.2s';
                });
                row.addEventListener('mouseleave', () => {
                    row.style.backgroundColor = '';
                });
            });

            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey || e.metaKey) {
                    switch(e.key) {
                        case 'r':
                            e.preventDefault();
                            window.location.href = '?';
                            break;
                        case 'e':
                            e.preventDefault();
                            const exportBtn = document.querySelector('.btn-export');
                            if (exportBtn) exportBtn.click();
                            break;
                    }
                }
            });

            // Add percentage animation to summary cards
            const summaryCards = document.querySelectorAll('.summary-card');
            summaryCards.forEach((card, index) => {
                card.style.animationDelay = (index * 0.1) + 's';
                card.classList.add('fade-in-up');
            });

            // Add print functionality
            window.printReport = function() {
                window.print();
            };

            // Add data refresh functionality
            window.refreshData = function() {
                location.reload();
            };

            // Add export functionality with loading state
            window.exportPDF = function() {
                const exportBtn = document.querySelector('.btn-export');
                if (exportBtn) {
                    const originalText = exportBtn.innerHTML;
                    exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
                    exportBtn.disabled = true;
                    
                    setTimeout(() => {
                        exportBtn.innerHTML = originalText;
                        exportBtn.disabled = false;
                    }, 3000);
                }
            };
        });

        // Reset filters function
        function resetFilters() {
            window.location.href = window.location.pathname;
        }
    </script>
</body>

</html>