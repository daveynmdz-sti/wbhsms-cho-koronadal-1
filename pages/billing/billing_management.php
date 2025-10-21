<?php
/**
 * Billing Management Dashboard
 * Purpose: Main dashboard for cashiers/admins to manage invoices and payments
 * UI Pattern: Sidebar only (list/management page)
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in and authorized
if (!is_employee_logged_in()) {
    header('Location: ../management/auth/employee_login.php');
    exit();
}

$employee_id = get_employee_session('employee_id');
$employee_role = get_employee_session('role');

// Check if role is authorized for billing operations
$allowed_roles = ['Cashier', 'Admin'];
if (!in_array($employee_role, $allowed_roles)) {
    header('Location: ../management/' . strtolower($employee_role) . '/dashboard.php');
    exit();
}

// Set active page for sidebar
$activePage = 'billing_management';

// Get current date and filters
$current_date = date('Y-m-d');
$date_filter = $_GET['date_filter'] ?? 'today';
$status_filter = $_GET['status_filter'] ?? 'all';
$search_term = $_GET['search'] ?? '';

// Calculate date range based on filter
$date_from = $current_date;
$date_to = $current_date;

switch ($date_filter) {
    case 'today':
        $date_from = $date_to = $current_date;
        break;
    case 'week':
        $date_from = date('Y-m-d', strtotime('-6 days'));
        $date_to = $current_date;
        break;
    case 'month':
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-t');
        break;
}

try {
    // Get invoices with filters
    $invoice_conditions = ['1=1'];
    $invoice_params = [];

    if ($date_filter !== 'all') {
        $invoice_conditions[] = "DATE(b.billing_date) BETWEEN ? AND ?";
        $invoice_params[] = $date_from;
        $invoice_params[] = $date_to;
    }

    if ($status_filter !== 'all') {
        $invoice_conditions[] = "b.payment_status = ?";
        $invoice_params[] = $status_filter;
    }

    if (!empty($search_term)) {
        $invoice_conditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR p.username LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)";
        $search_param = '%' . $search_term . '%';
        $invoice_params[] = $search_param;
        $invoice_params[] = $search_param;
        $invoice_params[] = $search_param;
        $invoice_params[] = $search_param;
    }

    $invoice_where = implode(' AND ', $invoice_conditions);

    $invoices_query = "SELECT b.*, p.first_name, p.last_name, p.username as patient_username,
                              e.first_name as created_by_first_name, e.last_name as created_by_last_name,
                              v.visit_date,
                              (SELECT COUNT(*) FROM billing_items bi WHERE bi.billing_id = b.billing_id) as items_count
                       FROM billing b
                       JOIN patients p ON b.patient_id = p.patient_id
                       JOIN employees e ON b.created_by = e.employee_id
                       LEFT JOIN visits v ON b.visit_id = v.visit_id
                       WHERE $invoice_where
                       ORDER BY b.billing_date DESC
                       LIMIT 50";

    $stmt = $pdo->prepare($invoices_query);
    $stmt->execute($invoice_params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent payments
    $payments_query = "SELECT p.*, b.billing_id, b.patient_id, b.net_amount as invoice_total,
                              pt.first_name, pt.last_name, pt.username as patient_username,
                              e.first_name as cashier_first_name, e.last_name as cashier_last_name
                       FROM payments p
                       JOIN billing b ON p.billing_id = b.billing_id
                       JOIN patients pt ON b.patient_id = pt.patient_id
                       JOIN employees e ON p.cashier_id = e.employee_id
                       WHERE " . ($date_filter !== 'all' ? "DATE(p.paid_at) BETWEEN '$date_from' AND '$date_to'" : '1=1') . "
                       ORDER BY p.paid_at DESC
                       LIMIT 20";

    $stmt = $pdo->prepare($payments_query);
    $stmt->execute();
    $recent_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get dashboard statistics
    $stats_query = "SELECT 
                        COUNT(CASE WHEN payment_status = 'unpaid' THEN 1 END) as unpaid_count,
                        COUNT(CASE WHEN payment_status = 'partial' THEN 1 END) as partial_count,
                        COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid_count,
                        SUM(CASE WHEN payment_status = 'unpaid' THEN net_amount ELSE 0 END) as unpaid_amount,
                        SUM(CASE WHEN payment_status = 'partial' THEN (net_amount - paid_amount) ELSE 0 END) as partial_amount,
                        SUM(paid_amount) as total_collected
                    FROM billing b
                    WHERE DATE(b.billing_date) BETWEEN ? AND ?";

    $stmt = $pdo->prepare($stats_query);
    $stmt->execute([$date_from, $date_to]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get today's payment total
    $today_payments_query = "SELECT COALESCE(SUM(amount_paid), 0) as today_total 
                             FROM payments 
                             WHERE DATE(paid_at) = CURDATE()";
    $stmt = $pdo->prepare($today_payments_query);
    $stmt->execute();
    $today_total = $stmt->fetch(PDO::FETCH_ASSOC)['today_total'];

} catch (Exception $e) {
    $error_message = 'Error loading dashboard data: ' . $e->getMessage();
    $invoices = [];
    $recent_payments = [];
    $stats = ['unpaid_count' => 0, 'partial_count' => 0, 'paid_count' => 0, 'unpaid_amount' => 0, 'partial_amount' => 0, 'total_collected' => 0];
    $today_total = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Management - CHO Koronadal</title>
    <link rel="stylesheet" href="<?= $root_path ?>/assets/css/sidebar.css">
    <link rel="stylesheet" href="<?= $root_path ?>/assets/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-card .icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .stat-card .number {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 0.25rem;
        }
        
        .stat-card .label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .stat-card.unpaid {
            border-left: 4px solid #dc3545;
        }
        
        .stat-card.partial {
            border-left: 4px solid #ffc107;
        }
        
        .stat-card.paid {
            border-left: 4px solid #28a745;
        }
        
        .stat-card.collected {
            border-left: 4px solid #007bff;
        }
        
        .dashboard-actions {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .filters-section {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
        
        .filters-row {
            display: flex;
            gap: 1rem;
            align-items: end;
            flex-wrap: wrap;
        }
        
        .filters-row > div {
            flex: 1;
            min-width: 150px;
        }
        
        .content-tabs {
            display: flex;
            background: white;
            border-radius: 8px 8px 0 0;
            overflow: hidden;
            margin-bottom: 0;
        }
        
        .tab {
            padding: 1rem 1.5rem;
            background: #f8f9fa;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
            flex: 1;
            text-align: center;
        }
        
        .tab.active {
            background: white;
            color: #007bff;
            font-weight: 600;
        }
        
        .tab-content {
            background: white;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            min-height: 400px;
        }
        
        .tab-pane {
            display: none;
            padding: 1.5rem;
        }
        
        .tab-pane.active {
            display: block;
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
        
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-unpaid {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-partial {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-paid {
            background: #d4edda;
            color: #155724;
        }
        
        .action-buttons-table {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="homepage">
        <!-- Include Sidebar -->
        <?php include $root_path . '/includes/sidebar_' . strtolower($employee_role) . '.php'; ?>
        
        <div class="main-content">
            <div class="content-header">
                <h1><i class="fas fa-file-invoice-dollar"></i> Billing Management</h1>
                <p>Manage invoices, process payments, and track billing transactions</p>
            </div>

            <!-- Dashboard Stats -->
            <div class="dashboard-stats">
                <div class="stat-card unpaid">
                    <div class="icon" style="color: #dc3545;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="number"><?= $stats['unpaid_count'] ?></div>
                    <div class="label">Unpaid Invoices</div>
                    <div class="label">₱<?= number_format($stats['unpaid_amount'], 2) ?></div>
                </div>
                
                <div class="stat-card partial">
                    <div class="icon" style="color: #ffc107;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="number"><?= $stats['partial_count'] ?></div>
                    <div class="label">Partially Paid</div>
                    <div class="label">₱<?= number_format($stats['partial_amount'], 2) ?> remaining</div>
                </div>
                
                <div class="stat-card paid">
                    <div class="icon" style="color: #28a745;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="number"><?= $stats['paid_count'] ?></div>
                    <div class="label">Paid Invoices</div>
                </div>
                
                <div class="stat-card collected">
                    <div class="icon" style="color: #007bff;">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="number">₱<?= number_format($today_total, 2) ?></div>
                    <div class="label">Today's Collections</div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="dashboard-actions">
                <h3><i class="fas fa-tools"></i> Quick Actions</h3>
                <div class="action-buttons">
                    <a href="create_invoice.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Invoice
                    </a>
                    <a href="billing_reports.php" class="btn btn-info">
                        <i class="fas fa-chart-bar"></i> Generate Reports
                    </a>
                    <button type="button" class="btn btn-success" onclick="refreshData()">
                        <i class="fas fa-sync-alt"></i> Refresh Data
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-section">
                <form method="GET" id="filters-form">
                    <div class="filters-row">
                        <div>
                            <label for="date_filter">Date Filter</label>
                            <select id="date_filter" name="date_filter" onchange="document.getElementById('filters-form').submit()">
                                <option value="today" <?= $date_filter === 'today' ? 'selected' : '' ?>>Today</option>
                                <option value="week" <?= $date_filter === 'week' ? 'selected' : '' ?>>This Week</option>
                                <option value="month" <?= $date_filter === 'month' ? 'selected' : '' ?>>This Month</option>
                                <option value="all" <?= $date_filter === 'all' ? 'selected' : '' ?>>All Time</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="status_filter">Payment Status</label>
                            <select id="status_filter" name="status_filter" onchange="document.getElementById('filters-form').submit()">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="unpaid" <?= $status_filter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                                <option value="partial" <?= $status_filter === 'partial' ? 'selected' : '' ?>>Partial</option>
                                <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="search">Search Patient</label>
                            <input type="text" id="search" name="search" value="<?= htmlspecialchars($search_term) ?>" placeholder="Name or Patient ID">
                        </div>
                        
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Filter
                            </button>
                            <a href="?" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Content Tabs -->
            <div class="content-tabs">
                <button class="tab active" onclick="showTab('invoices')">
                    <i class="fas fa-file-invoice"></i> Invoices (<?= count($invoices) ?>)
                </button>
                <button class="tab" onclick="showTab('payments')">
                    <i class="fas fa-credit-card"></i> Recent Payments (<?= count($recent_payments) ?>)
                </button>
            </div>

            <div class="tab-content">
                <!-- Invoices Tab -->
                <div id="invoices-tab" class="tab-pane active">
                    <?php if (empty($invoices)): ?>
                        <div class="empty-state">
                            <i class="fas fa-file-invoice"></i>
                            <h3>No Invoices Found</h3>
                            <p>No invoices match your current filters.</p>
                            <a href="create_invoice.php" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Create First Invoice
                            </a>
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Invoice ID</th>
                                    <th>Patient</th>
                                    <th>Date</th>
                                    <th>Items</th>
                                    <th>Total</th>
                                    <th>Paid</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td><?= $invoice['billing_id'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']) ?></strong><br>
                                        <small><?= htmlspecialchars($invoice['patient_username']) ?></small>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($invoice['billing_date'])) ?></td>
                                    <td><?= $invoice['items_count'] ?> items</td>
                                    <td>₱<?= number_format($invoice['net_amount'], 2) ?></td>
                                    <td>₱<?= number_format($invoice['paid_amount'], 2) ?></td>
                                    <td>
                                        <span class="status-badge status-<?= $invoice['payment_status'] ?>">
                                            <?= ucfirst($invoice['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons-table">
                                            <button class="btn btn-sm btn-info" onclick="viewInvoice(<?= $invoice['billing_id'] ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($invoice['payment_status'] !== 'paid'): ?>
                                            <a href="process_payment.php?billing_id=<?= $invoice['billing_id'] ?>" class="btn btn-sm btn-success">
                                                <i class="fas fa-credit-card"></i> Pay
                                            </a>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-secondary" onclick="printInvoice(<?= $invoice['billing_id'] ?>)">
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

                <!-- Payments Tab -->
                <div id="payments-tab" class="tab-pane">
                    <?php if (empty($recent_payments)): ?>
                        <div class="empty-state">
                            <i class="fas fa-credit-card"></i>
                            <h3>No Payments Found</h3>
                            <p>No payments match your current filters.</p>
                        </div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Receipt #</th>
                                    <th>Invoice ID</th>
                                    <th>Patient</th>
                                    <th>Amount Paid</th>
                                    <th>Payment Date</th>
                                    <th>Cashier</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_payments as $payment): ?>
                                <tr>
                                    <td><?= htmlspecialchars($payment['receipt_number']) ?></td>
                                    <td><?= $payment['billing_id'] ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']) ?></strong><br>
                                        <small><?= htmlspecialchars($payment['patient_username']) ?></small>
                                    </td>
                                    <td>₱<?= number_format($payment['amount_paid'], 2) ?></td>
                                    <td><?= date('M d, Y g:i A', strtotime($payment['paid_at'])) ?></td>
                                    <td><?= htmlspecialchars($payment['cashier_first_name'] . ' ' . $payment['cashier_last_name']) ?></td>
                                    <td>
                                        <div class="action-buttons-table">
                                            <button class="btn btn-sm btn-info" onclick="viewReceipt(<?= $payment['payment_id'] ?>)">
                                                <i class="fas fa-receipt"></i>
                                            </button>
                                            <button class="btn btn-sm btn-secondary" onclick="printReceipt(<?= $payment['payment_id'] ?>)">
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
        </div>
    </div>

    <script>
        // Tab switching
        function showTab(tabName) {
            // Hide all tab panes
            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab pane
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Refresh data
        function refreshData() {
            window.location.reload();
        }

        // View invoice details (placeholder - implement modal or new page)
        function viewInvoice(billingId) {
            // TODO: Implement invoice details modal or redirect to details page
            alert('View invoice details for ID: ' + billingId);
        }

        // Print invoice (placeholder - implement print functionality)
        function printInvoice(billingId) {
            // TODO: Implement invoice printing
            alert('Print invoice for ID: ' + billingId);
        }

        // View receipt (placeholder - implement modal or new page)
        function viewReceipt(paymentId) {
            // TODO: Implement receipt details modal
            alert('View receipt for payment ID: ' + paymentId);
        }

        // Print receipt (placeholder - implement print functionality)
        function printReceipt(paymentId) {
            // TODO: Implement receipt printing
            alert('Print receipt for payment ID: ' + paymentId);
        }

        // Auto-refresh every 5 minutes
        setInterval(refreshData, 300000);
    </script>
</body>
</html>