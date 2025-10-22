<?php
/**
 * Billing Management Dashboard
 * Purpose: Main dashboard for cashiers/admins to manage invoices and payments
 * UI Pattern: Sidebar only (list/management page) - Matches prescription management structure
 */

// Ensure output buffering is active (but don't create unnecessary nested buffers)
if (ob_get_level() === 0) {
    ob_start();
}

// Suppress errors to prevent interference with JavaScript
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

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
$activePage = 'billing_management';

// Define role-based permissions for billing management using role_id
$canViewBilling = in_array($_SESSION['role_id'], [1, 8]); // admin, cashier
$canProcessPayments = in_array($_SESSION['role_id'], [1, 8]); // admin, cashier
$canCreateInvoices = in_array($_SESSION['role_id'], [1, 8]); // admin, cashier
$canGenerateReports = in_array($_SESSION['role_id'], [1, 8]); // admin, cashier

if (!$canViewBilling) {
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

// Handle AJAX requests for search and filter
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';  // Show all statuses by default
$dateFilter = isset($_GET['date']) ? $_GET['date'] : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$recordsPerPage = 15;
$offset = ($page - 1) * $recordsPerPage;

// Fetch invoices with patient information and payment details
$invoicesSql = "SELECT b.billing_id, b.patient_id, 
                 b.billing_date, 
                 b.payment_status, 
                 b.total_amount,
                 b.paid_amount,
                 b.net_amount,
                 b.discount_amount,
                 b.created_by, 
                 b.notes,
                 p.first_name, p.last_name, p.middle_name, 
                 COALESCE(p.username, p.patient_id) as patient_id_display, 
                 bg.barangay_name as barangay,
                 e.first_name as created_by_first_name, e.last_name as created_by_last_name,
                 (SELECT COUNT(*) FROM billing_items bi WHERE bi.billing_id = b.billing_id) as items_count
          FROM billing b
          LEFT JOIN patients p ON b.patient_id = p.patient_id
          LEFT JOIN barangay bg ON p.barangay_id = bg.barangay_id
          LEFT JOIN employees e ON b.created_by = e.employee_id
          WHERE 1=1";

$conditions = [];
$params = [];

if (!empty($searchQuery)) {
    $conditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR p.username LIKE ?)";
    $searchParam = '%' . $searchQuery . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if (!empty($statusFilter)) {
    $conditions[] = "b.payment_status = ?";
    $params[] = $statusFilter;
}

if (!empty($dateFilter)) {
    $conditions[] = "DATE(b.billing_date) = ?";
    $params[] = $dateFilter;
}

if (!empty($conditions)) {
    $invoicesSql .= " AND " . implode(' AND ', $conditions);
}

$invoicesSql .= " ORDER BY b.billing_date DESC LIMIT $recordsPerPage OFFSET $offset";

$invoicesResult = null;
try {
    $stmt = $pdo->prepare($invoicesSql);
    $stmt->execute($params);
    $invoicesResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug information (stored for later display)
    $debug_info = null;
    if (isset($_GET['debug'])) {
        $debug_info = [
            'sql' => $invoicesSql,
            'params' => $params,
            'results_count' => count($invoicesResult),
            'status_filter' => $statusFilter,
            'search_query' => $searchQuery,
            'date_filter' => $dateFilter,
            'sample_result' => count($invoicesResult) > 0 ? $invoicesResult[0] : null
        ];
    }
} catch (Exception $e) {
    // Table doesn't exist yet or query failed - we'll show empty results
    error_log("Billing Management Error: " . $e->getMessage());
    $invoicesResult = [];
}

// Recent payments query - shows recent payment transactions
$recentPaymentsSql = "SELECT p.payment_id, 
                       p.billing_id,
                       p.amount_paid,
                       p.payment_method,
                       p.paid_at as payment_date,
                       p.receipt_number,
                       pt.first_name, pt.last_name, pt.middle_name, 
                       COALESCE(pt.username, pt.patient_id) as patient_id_display,
                       e.first_name as cashier_first_name, e.last_name as cashier_last_name
                       FROM payments p 
                       LEFT JOIN billing b ON p.billing_id = b.billing_id
                       LEFT JOIN patients pt ON b.patient_id = pt.patient_id
                       LEFT JOIN employees e ON p.cashier_id = e.employee_id
                       WHERE DATE(p.paid_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                       ORDER BY p.paid_at DESC
                       LIMIT 20";

$recentPaymentsResult = null;
try {
    $stmt = $pdo->prepare($recentPaymentsSql);
    $stmt->execute();
    $recentPaymentsResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist yet or query failed - we'll show empty results
    error_log("Recent Payments Query Error: " . $e->getMessage());
    $recentPaymentsResult = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Management - WBHSMS</title>
    <link rel="stylesheet" href="<?= $assets_path ?>/css/sidebar.css">
    <link rel="stylesheet" href="<?= $assets_path ?>/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Content wrapper and page layout */
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
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .page-header h1 {
            margin: 0;
            color: #03045e;
            font-size: 1.8em;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
        }

        .stat-card.total {
            border-left: 5px solid #03045e;
        }

        .stat-card.unpaid {
            border-left: 5px solid #dc3545;
        }

        .stat-card.partial {
            border-left: 5px solid #ffc107;
        }

        .stat-card.paid {
            border-left: 5px solid #28a745;
        }

        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            color: #03045e;
        }

        .stat-label {
            font-size: 0.9em;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
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

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }

        .billing-management-container {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 30px;
            margin-top: 20px;
        }

        @media (max-width: 1200px) {
            .billing-management-container {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }

        .billing-panel {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            padding: 10px;
        }

        .panel-header {
            background: #03045e;
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .panel-title {
            font-size: 1.2em;
            font-weight: bold;
        }

        .search-filters {
            display: flex;
            gap: 10px;
            padding: 15px 20px;
            background: #f8f9fa;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 0.9em;
            flex: 1;
            min-width: 120px;
        }

        .filter-btn {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .search-btn {
            background: #007cba;
            color: white;
        }

        .clear-btn {
            background: #6c757d;
            color: white;
        }

        .billing-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .billing-table th,
        .billing-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            font-size: 0.9em;
        }

        .billing-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #03045e;
        }

        .billing-table tr:hover {
            background-color: #f5f5f5;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
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

        .action-buttons {
            display: flex;
            gap: 5px;
            align-items: center;
        }

        .action-buttons .btn {
            padding: 5px 8px;
            font-size: 0.8em;
        }

        /* Empty state styles */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3em;
            margin-bottom: 15px;
            color: #dee2e6;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: #495057;
        }

        .empty-state p {
            margin-bottom: 0;
        }

        /* Alert styles */
        .alert {
            padding: 12px 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }

        .alert-error {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.2em;
            cursor: pointer;
            color: inherit;
            opacity: 0.7;
        }

        .btn-close:hover {
            opacity: 1;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            display: none;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background: white;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            box-sizing: content-box;
        }
        
        .modal-header {
            background: #007bff;
            color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-close:hover {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
        }
        
        .modal-body {
            padding: 0;
            max-height: calc(90vh - 120px);
            overflow-y: auto;
        }
        
        .modal-footer {
            background: #f8f9fa;
            padding: 1rem 1.5rem;
            border-top: 1px solid #dee2e6;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }
    </style>
</head>

<body>
    <!-- Set active page for sidebar highlighting -->
    <?php $activePage = 'billing_management'; ?>

    <!-- Include role-based sidebar -->
    <?php
    require_once $root_path . '/includes/dynamic_sidebar_helper.php';
    includeDynamicSidebar($activePage, $root_path);
    ?>

    <section class="content-wrapper">
        <?php if ($debug_info): ?>
        <div style='background: #f0f8ff; border: 1px solid #007bff; padding: 15px; margin: 20px; border-radius: 5px;'>
            <h4>Debug Information:</h4>
            <p><strong>SQL Query:</strong><br><code><?= htmlspecialchars($debug_info['sql']) ?></code></p>
            <p><strong>Parameters:</strong> <?= htmlspecialchars(json_encode($debug_info['params'])) ?></p>
            <p><strong>Results Count:</strong> <?= $debug_info['results_count'] ?></p>
            <p><strong>Status Filter:</strong> <?= htmlspecialchars($debug_info['status_filter']) ?></p>
            <p><strong>Search Query:</strong> <?= htmlspecialchars($debug_info['search_query']) ?></p>
            <p><strong>Date Filter:</strong> <?= htmlspecialchars($debug_info['date_filter']) ?></p>
            <?php if ($debug_info['sample_result']): ?>
            <p><strong>Sample Result:</strong><br><pre><?= htmlspecialchars(json_encode($debug_info['sample_result'], JSON_PRETTY_PRINT)) ?></pre></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb" style="margin-top: 50px;">
            <a href="<?= getRoleDashboardUrl() ?>"><i class="fas fa-home"></i> Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <span>Billing Management</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-file-invoice-dollar"></i> Billing Management</h1>
            <?php if ($canCreateInvoices): ?>
                <a href="create_invoice.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create Invoice
                </a>
            <?php endif; ?>
        </div>

        <!-- Success/Error Messages -->
        <div id="alertContainer"></div>
        
        <!-- Display server-side messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
                <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
                <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Billing Statistics -->
        <div class="stats-grid">
            <?php
            // Get billing statistics - using default values since table may not exist yet
            $billing_stats = [
                'total' => 0,
                'unpaid' => 0,
                'partial' => 0,
                'paid' => 0
            ];

            try {
                $stats_sql = "SELECT 
                                    COUNT(*) as total,
                                    SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid,
                                    SUM(CASE WHEN payment_status = 'partial' THEN 1 ELSE 0 END) as partial,
                                    SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid
                              FROM billing WHERE DATE(billing_date) >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)";
                $stats_result = $pdo->query($stats_sql);
                if ($stats_result && $row = $stats_result->fetch()) {
                    $billing_stats = [
                        'total' => intval($row['total'] ?? 0),
                        'unpaid' => intval($row['unpaid'] ?? 0),
                        'partial' => intval($row['partial'] ?? 0),
                        'paid' => intval($row['paid'] ?? 0)
                    ];
                }
            } catch (Exception $e) {
                // Use default values if query fails (table doesn't exist)
            }
            ?>

            <div class="stat-card total">
                <div class="stat-number"><?= number_format($billing_stats['total']) ?></div>
                <div class="stat-label">Total Invoices (30 days)</div>
            </div>

            <div class="stat-card unpaid">
                <div class="stat-number"><?= number_format($billing_stats['unpaid']) ?></div>
                <div class="stat-label">Unpaid</div>
            </div>

            <div class="stat-card partial">
                <div class="stat-number"><?= number_format($billing_stats['partial']) ?></div>
                <div class="stat-label">Partially Paid</div>
            </div>

            <div class="stat-card paid">
                <div class="stat-number"><?= number_format($billing_stats['paid']) ?></div>
                <div class="stat-label">Paid Invoices</div>
            </div>
        </div>

        <div class="billing-management-container">
            <!-- Left Panel: All Invoices -->
            <div class="billing-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-file-invoice"></i> Invoice Management
                    </div>
                    <!-- Quick Actions Button -->
                    <div style="margin-bottom: 15px;">
                        <?php if ($canGenerateReports): ?>
                        <button type="button" class="btn btn-primary" onclick="openReportsModal()">
                            <i class="fas fa-chart-bar"></i> Reports
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Search and Filter Controls -->
                <div class="search-filters">
                    <input type="text" class="filter-input" id="searchInvoices" placeholder="Search patient name..." value="<?= htmlspecialchars($searchQuery) ?>">
                    <select class="filter-input" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="unpaid" <?= $statusFilter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                        <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partially Paid</option>
                        <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    </select>
                    <input type="date" class="filter-input" id="dateFilter" placeholder="Invoice Date" value="<?= htmlspecialchars($dateFilter) ?>">
                </div>
                <div class="search-filters" style="justify-content: flex-end; margin-top: -10px;">
                    <button type="button" class="filter-btn search-btn" id="searchBtn" onclick="applyFilters()">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <button type="button" class="filter-btn clear-btn" id="clearBtn" onclick="clearFilters()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>

                <!-- Invoices Table -->
                <div class="panel-body" style="padding: 0;">
                    <?php if (empty($invoicesResult)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-invoice"></i>
                            <h3>No Invoices Found</h3>
                            <p>No invoices match your current search criteria.</p>
                            <?php if ($canCreateInvoices): ?>
                            <a href="create_invoice.php" class="btn btn-primary" style="margin-top: 15px;">
                                <i class="fas fa-plus"></i> Create First Invoice
                            </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <table class="billing-table">
                            <thead>
                                <tr>
                                    <th>Invoice ID</th>
                                    <th>Patient</th>
                                    <th>Date</th>
                                    <th>Items</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invoicesResult as $invoice): ?>
                                <tr>
                                    <td><strong>#<?= $invoice['billing_id'] ?></strong></td>
                                    <td>
                                        <strong><?= htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']) ?></strong><br>
                                        <small style="color: #666;"><?= htmlspecialchars($invoice['patient_id_display']) ?></small>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($invoice['billing_date'])) ?></td>
                                    <td><?= $invoice['items_count'] ?> items</td>
                                    <td>
                                        <strong>₱<?= number_format($invoice['net_amount'], 2) ?></strong><br>
                                        <?php if ($invoice['paid_amount'] > 0): ?>
                                        <small style="color: #666;">Paid: ₱<?= number_format($invoice['paid_amount'], 2) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= $invoice['payment_status'] ?>">
                                            <?= ucfirst($invoice['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-info btn-sm" onclick="viewInvoice(<?= $invoice['billing_id'] ?>)" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($invoice['payment_status'] !== 'paid' && $canProcessPayments): ?>
                                            <a href="process_payment.php?billing_id=<?= $invoice['billing_id'] ?>" class="btn btn-success btn-sm" title="Process Payment">
                                                <i class="fas fa-credit-card"></i>
                                            </a>
                                            <?php endif; ?>
                                            <button class="btn btn-secondary btn-sm" onclick="printInvoice(<?= $invoice['billing_id'] ?>)" title="Print Invoice">
                                                <i class="fas fa-print"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Panel: Recent Payments -->
            <div class="billing-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-credit-card"></i> Recent Payments
                    </div>
                </div>

                <div class="panel-body" style="padding: 20px;">
                    <?php if (empty($recentPaymentsResult)): ?>
                        <div class="empty-state">
                            <i class="fas fa-credit-card"></i>
                            <h3>No Recent Payments</h3>
                            <p>No payments have been recorded in the last 7 days.</p>
                        </div>
                    <?php else: ?>
                        <div style="max-height: 500px; overflow-y: auto;">
                            <?php foreach ($recentPaymentsResult as $payment): ?>
                            <div style="border-bottom: 1px solid #eee; padding: 15px 0;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <strong><?= htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']) ?></strong><br>
                                        <small style="color: #666;">
                                            Invoice #<?= $payment['billing_id'] ?> • 
                                            <?= ucfirst($payment['payment_method']) ?>
                                        </small>
                                    </div>
                                    <div style="text-align: right;">
                                        <strong style="color: #28a745;">₱<?= number_format($payment['amount_paid'], 2) ?></strong><br>
                                        <small style="color: #666;"><?= date('M d, g:i A', strtotime($payment['payment_date'])) ?></small>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- Invoice Preview Modal -->
    <div id="invoice-modal" class="modal-overlay" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-file-invoice"></i> Invoice Details</h3>
                <button class="modal-close" onclick="closeInvoiceModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="invoice-content">
                    <!-- Content will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeInvoiceModal()">
                    <i class="fas fa-times"></i> Close
                </button>
                <button class="btn btn-info" id="print-modal-invoice" onclick="printModalInvoice()" style="display: none;">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>

    <script>
        // Search and filter functions
        function applyFilters() {
            const searchQuery = document.getElementById('searchInvoices').value;
            const statusFilter = document.getElementById('statusFilter').value;
            const dateFilter = document.getElementById('dateFilter').value;
            
            const params = new URLSearchParams();
            if (searchQuery) params.append('search', searchQuery);
            if (statusFilter) params.append('status', statusFilter);
            if (dateFilter) params.append('date', dateFilter);
            
            window.location.href = '?' + params.toString();
        }
        
        function clearFilters() {
            document.getElementById('searchInvoices').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('dateFilter').value = '';
            window.location.href = window.location.pathname;
        }
        
        // View invoice details in modal
        function viewInvoice(billingId) {
            try {
                // Show loading state
                showInvoiceModal('<div style="padding: 3rem; text-align: center; color: #666;"><i class="fas fa-spinner fa-spin"></i><p>Loading invoice details...</p></div>');
                
                fetch(`/wbhsms-cho-koronadal-1/api/billing/management/get_invoice_details.php?billing_id=${billingId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            displayInvoiceDetails(data.invoice);
                        } else {
                            showInvoiceModal('<div style="padding: 2rem; text-align: center; color: #dc3545;"><i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 1rem;"></i><p>' + (data.message || 'Failed to load invoice details') + '</p></div>');
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching invoice:', error);
                        showInvoiceModal('<div style="padding: 2rem; text-align: center; color: #dc3545;"><i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 1rem;"></i><p>Error loading invoice details</p></div>');
                    });
            } catch (error) {
                console.error('Error in viewInvoice:', error);
                alert('Error loading invoice details. Please try again.');
            }
        }
        
        // Print invoice using dedicated API
        function printInvoice(billingId) {
            try {
                const printUrl = `/wbhsms-cho-koronadal-1/api/billing/management/print_invoice.php?billing_id=${billingId}&format=html`;
                window.open(printUrl, '_blank', 'width=800,height=900,scrollbars=yes,resizable=yes');
            } catch (error) {
                console.error('Error printing invoice:', error);
                alert('Error printing invoice. Please try again.');
            }
        }
        
        // Modal functions
        let currentInvoiceId = null;
        
        function showInvoiceModal(content) {
            document.getElementById('invoice-content').innerHTML = content;
            document.getElementById('invoice-modal').style.display = 'flex';
        }
        
        function closeInvoiceModal() {
            document.getElementById('invoice-modal').style.display = 'none';
            currentInvoiceId = null;
        }
        
        function displayInvoiceDetails(invoice) {
            currentInvoiceId = invoice.billing_id;
            
            // Build items HTML
            let itemsHtml = '';
            if (invoice.items && invoice.items.length > 0) {
                for (let i = 0; i < invoice.items.length; i++) {
                    const item = invoice.items[i];
                    itemsHtml += '<tr>' +
                        '<td>' + (item.item_name || '') + '</td>' +
                        '<td>' + (item.category_name || 'General') + '</td>' +
                        '<td style="text-align: right;">' + item.quantity + '</td>' +
                        '<td style="text-align: right;">₱' + parseFloat(item.item_price).toFixed(2) + '</td>' +
                        '<td style="text-align: right;">₱' + parseFloat(item.subtotal).toFixed(2) + '</td>' +
                        '</tr>';
                }
            } else {
                itemsHtml = '<tr><td colspan="5" style="text-align: center; color: #666;">No items found</td></tr>';
            }
            
            // Build payments HTML
            let paymentsHtml = '';
            if (invoice.payments && invoice.payments.length > 0) {
                for (let i = 0; i < invoice.payments.length; i++) {
                    const payment = invoice.payments[i];
                    const paymentDate = new Date(payment.date).toLocaleDateString();
                    paymentsHtml += '<div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background: #f8f9fa; margin-bottom: 0.5rem; border-radius: 4px;">' +
                        '<div>' +
                        '<span style="font-weight: 600; text-transform: uppercase;">' + payment.payment_method + '</span>' +
                        '<small style="display: block; color: #666;">' + paymentDate + '</small>' +
                        '</div>' +
                        '<div style="text-align: right;">' +
                        '<strong>₱' + parseFloat(payment.amount).toFixed(2) + '</strong>' +
                        '<small style="display: block; color: #666;">' + (payment.receipt_number || 'N/A') + '</small>' +
                        '</div>' +
                        '</div>';
                }
            } else {
                paymentsHtml = '<p style="text-align: center; color: #666; padding: 1rem;">No payments recorded</p>';
            }
            
            // Build discount HTML if applicable
            const discountHtml = invoice.discount_amount > 0 ? 
                '<div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;"><span>Discount (' + (invoice.discount_type || 'Standard') + '):</span><span>-₱' + parseFloat(invoice.discount_amount).toFixed(2) + '</span></div>' : '';
            
            // Calculate balance and color
            const balance = parseFloat(invoice.net_amount) - parseFloat(invoice.paid_amount);
            const balanceColor = balance > 0 ? '#dc3545' : '#28a745';
            
            // Build complete invoice HTML
            const invoiceHtml = 
                '<div style="padding: 2rem; font-family: Arial, sans-serif;">' +
                    '<div style="display: grid; grid-template-columns: 1fr auto; gap: 2rem; margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 2px solid #007bff;">' +
                        '<div>' +
                            '<h2 style="color: #007bff; margin: 0 0 0.5rem 0;">City Health Office</h2>' +
                            '<p style="margin: 0.25rem 0; color: #666;">Koronadal City, South Cotabato</p>' +
                            '<p style="margin: 0.25rem 0; color: #666;">Phone: (083) 228-xxxx</p>' +
                        '</div>' +
                        '<div style="text-align: right;">' +
                            '<h3 style="color: #333; margin: 0 0 1rem 0;">INVOICE #' + invoice.billing_id + '</h3>' +
                            '<p><strong>Date:</strong> ' + new Date(invoice.billing_date).toLocaleDateString() + '</p>' +
                            '<p><strong>Status:</strong> <span class="status-badge status-' + invoice.payment_status + '">' + invoice.payment_status.toUpperCase() + '</span></p>' +
                        '</div>' +
                    '</div>' +
                    
                    '<div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem;">' +
                        '<h4 style="margin: 0 0 1rem 0; color: #333;"><i class="fas fa-user"></i> Patient Information</h4>' +
                        '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">' +
                            '<div><span style="font-weight: 600; color: #666; font-size: 0.9rem; display: block; margin-bottom: 0.25rem;">Patient Name</span><span style="color: #333;">' + (invoice.first_name || '') + ' ' + (invoice.last_name || '') + '</span></div>' +
                            '<div><span style="font-weight: 600; color: #666; font-size: 0.9rem; display: block; margin-bottom: 0.25rem;">Patient ID</span><span style="color: #333;">' + (invoice.patient_number || 'N/A') + '</span></div>' +
                            '<div><span style="font-weight: 600; color: #666; font-size: 0.9rem; display: block; margin-bottom: 0.25rem;">Age</span><span style="color: #333;">' + (invoice.age || 'N/A') + ' years old</span></div>' +
                            '<div><span style="font-weight: 600; color: #666; font-size: 0.9rem; display: block; margin-bottom: 0.25rem;">Contact</span><span style="color: #333;">' + (invoice.contact_number || 'N/A') + '</span></div>' +
                            '<div><span style="font-weight: 600; color: #666; font-size: 0.9rem; display: block; margin-bottom: 0.25rem;">Address</span><span style="color: #333;">' + (invoice.barangay_name || 'N/A') + ', ' + (invoice.city || 'Koronadal') + '</span></div>' +
                            '<div><span style="font-weight: 600; color: #666; font-size: 0.9rem; display: block; margin-bottom: 0.25rem;">Visit Purpose</span><span style="color: #333;">' + (invoice.visit_purpose || 'General Consultation') + '</span></div>' +
                        '</div>' +
                    '</div>' +
                    
                    '<h4><i class="fas fa-list"></i> Services & Items</h4>' +
                    '<table style="width: 100%; border-collapse: collapse; margin-bottom: 2rem;">' +
                        '<thead>' +
                            '<tr>' +
                                '<th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid #dee2e6; background: #f8f9fa; font-weight: 600; color: #333;">Service/Item</th>' +
                                '<th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid #dee2e6; background: #f8f9fa; font-weight: 600; color: #333;">Category</th>' +
                                '<th style="padding: 0.75rem; text-align: right; border-bottom: 1px solid #dee2e6; background: #f8f9fa; font-weight: 600; color: #333;">Qty</th>' +
                                '<th style="padding: 0.75rem; text-align: right; border-bottom: 1px solid #dee2e6; background: #f8f9fa; font-weight: 600; color: #333;">Unit Price</th>' +
                                '<th style="padding: 0.75rem; text-align: right; border-bottom: 1px solid #dee2e6; background: #f8f9fa; font-weight: 600; color: #333;">Subtotal</th>' +
                            '</tr>' +
                        '</thead>' +
                        '<tbody>' + itemsHtml + '</tbody>' +
                    '</table>' +
                    
                    '<div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem;">' +
                        '<div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;"><span>Subtotal:</span><span>₱' + parseFloat(invoice.total_amount).toFixed(2) + '</span></div>' +
                        discountHtml +
                        '<div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem; font-weight: bold; font-size: 1.1rem; border-top: 2px solid #007bff; padding-top: 0.5rem; margin-top: 1rem;"><span>Total Amount:</span><span>₱' + parseFloat(invoice.net_amount).toFixed(2) + '</span></div>' +
                        '<div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;"><span>Amount Paid:</span><span>₱' + parseFloat(invoice.paid_amount).toFixed(2) + '</span></div>' +
                        '<div style="display: flex; justify-content: space-between; color: ' + balanceColor + ';"><span>Balance:</span><span>₱' + balance.toFixed(2) + '</span></div>' +
                    '</div>' +
                    
                    '<div>' +
                        '<h4><i class="fas fa-credit-card"></i> Payment History</h4>' +
                        paymentsHtml +
                    '</div>' +
                '</div>';
            
            showInvoiceModal(invoiceHtml);
            document.getElementById('print-modal-invoice').style.display = 'inline-block';
        }
        
        function printModalInvoice() {
            if (currentInvoiceId) {
                printInvoice(currentInvoiceId);
            }
        }
        
        // Close modal when clicking outside
        document.getElementById('invoice-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeInvoiceModal();
            }
        });
        
        // Close modal with ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('invoice-modal').style.display === 'flex') {
                closeInvoiceModal();
            }
        });
        
        // Navigate to reports page
        function openReportsModal() {
            window.location.href = 'billing_reports.php';
        }
        
        // Helper function to get role dashboard URL
        function getRoleDashboardUrl() {
            // Get role from session via a safer method
            const currentUrl = window.location.pathname;
            const pathParts = currentUrl.split('/');
            const currentRole = pathParts.includes('admin') ? 'admin' : 'cashier';
            return '../management/' + currentRole + '/dashboard.php';
        }
        
        // Ensure functions are globally accessible
        window.viewInvoice = viewInvoice;
        window.printInvoice = printInvoice;
        window.openReportsModal = openReportsModal;
        window.applyFilters = applyFilters;
        window.clearFilters = clearFilters;
        window.closeInvoiceModal = closeInvoiceModal;
        window.printModalInvoice = printModalInvoice;
    </script>

</body>
</html>