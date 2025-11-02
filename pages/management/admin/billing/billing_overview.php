<?php
// Admin Billing Overview - Administrative access to billing system
$root_path = dirname(dirname(dirname(dirname(__DIR__))));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in and has admin privileges
if (!is_employee_logged_in()) {
    // Clean output buffer only if one exists
    while (ob_get_level()) {
        ob_end_clean();
    }
    error_log('Redirecting to employee_login (absolute path) from ' . __FILE__ . ' URI=' . ($_SERVER['REQUEST_URI'] ?? ''));
    
    // Check if headers can still be sent
    if (!headers_sent()) {
        header("Location: /pages/management/auth/employee_login.php");
    }
    exit();
}

$employee_role = get_employee_session('role');
if (!in_array($employee_role, ['admin', 'records_officer'])) {
    header("Location: ../dashboard.php?error=Access denied");
    exit();
}

// Define role-based permissions for billing management
$canEditBilling = in_array($employee_role, ['admin']); // Only admin can edit billing
$canViewBilling = in_array($employee_role, ['admin', 'records_officer']); // Admin and records officers can view

$employee_id = get_employee_session('employee_id');
$employee_name = get_employee_session('employee_name', 'Unknown User');

// Set active page for sidebar highlighting
$activePage = 'billing';

// Include appropriate sidebar based on user role
if ($employee_role === 'admin') {
    include '../../../../includes/sidebar_admin.php';
} elseif ($employee_role === 'records_officer') {
    include '../../../../includes/sidebar_records_officer.php';
} else {
    // Fallback to admin sidebar for other roles
    include '../../../../includes/sidebar_admin.php';
}

$message = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '';
$error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';

// Handle search and filter parameters
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$dateFilter = isset($_GET['date']) ? $_GET['date'] : '';

// Get billing system statistics for admin overview
$stats = [];
try {
    // Get overall billing statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_invoices,
            COUNT(CASE WHEN payment_status = 'unpaid' THEN 1 END) as unpaid_invoices,
            COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid_invoices,
            COUNT(CASE WHEN payment_status = 'partial' THEN 1 END) as partial_invoices,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN net_amount END), 0) as total_outstanding,
            COALESCE((
                SELECT SUM(r.amount_paid - COALESCE(r.change_amount, 0))
                FROM receipts r 
                JOIN billing b2 ON r.billing_id = b2.billing_id 
                WHERE DATE(r.payment_date) = CURDATE()
            ), 0) as today_collections,
            COALESCE((
                SELECT SUM(r.amount_paid - COALESCE(r.change_amount, 0))
                FROM receipts r 
                JOIN billing b3 ON r.billing_id = b3.billing_id 
                WHERE YEAR(r.payment_date) = YEAR(CURDATE()) 
                AND MONTH(r.payment_date) = MONTH(CURDATE())
            ), 0) as month_collections
        FROM billing
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get recent activity from receipts
    $stmt = $pdo->query("
        SELECT 
            r.receipt_id,
            r.receipt_number,
            r.payment_date,
            r.amount_paid,
            r.change_amount,
            (r.amount_paid - COALESCE(r.change_amount, 0)) as actual_payment_applied,
            r.payment_method,
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            CONCAT(e.first_name, ' ', e.last_name) as cashier_name
        FROM receipts r
        JOIN billing b ON r.billing_id = b.billing_id
        JOIN patients p ON b.patient_id = p.patient_id
        LEFT JOIN employees e ON r.received_by_employee_id = e.employee_id
        ORDER BY r.payment_date DESC
        LIMIT 10
    ");
    $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Build invoice query with search and filter conditions
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
                     CONCAT(p.first_name, ' ', p.last_name) as patient_name,
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

    $invoicesSql .= " ORDER BY b.billing_date DESC LIMIT 50";

    // Execute invoice query
    $stmt = $pdo->prepare($invoicesSql);
    $stmt->execute($params);
    $invoicesResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no results but no search/filter applied, make sure we have actual data
    if (empty($invoicesResult) && empty($searchQuery) && empty($statusFilter) && empty($dateFilter)) {
        // Check if billing table has any data at all
        $checkStmt = $pdo->query("SELECT COUNT(*) as count FROM billing");
        $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if ($checkResult['count'] == 0) {
            $error = "No billing records found in the system.";
        }
    }

    // Get recent payments for right panel
    $stmt = $pdo->query("
        SELECT 
            r.receipt_id,
            r.billing_id,
            r.payment_date,
            r.amount_paid,
            r.change_amount,
            (r.amount_paid - COALESCE(r.change_amount, 0)) as actual_payment_applied,
            r.payment_method,
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.first_name,
            p.last_name
        FROM receipts r
        JOIN billing b ON r.billing_id = b.billing_id
        JOIN patients p ON b.patient_id = p.patient_id
        WHERE r.payment_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY r.payment_date DESC
        LIMIT 20
    ");
    $recentPaymentsResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Admin billing overview error: " . $e->getMessage());
    $error = "Failed to load billing statistics.";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
            <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing System Overview - CHO Koronadal Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../../../assets/css/sidebar.css">
    <style>
        .billing-overview {
            margin-left: 300px;
            padding: 2rem;
            transition: margin-left 0.3s;
        }

        @media (max-width: 768px) {
            .billing-overview {
                margin-left: 0;
                padding: 1rem;
            }
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e9ecef;
        }

        .page-header h1 {
            margin: 0;
            font-size: 2.2rem;
            color: #0077b6;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .breadcrumb {
            background: none;
            padding: 0;
            margin: 0 0 1rem 0;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .breadcrumb a {
            color: #6c757d;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            color: #0077b6;
        }

        .action-buttons {
            display: flex;
            gap: 0.7rem;
            flex-wrap: wrap;
        }

        .btn {
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background: #007BFF;
            color: #fff;
        }

        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #16a085, #0f6b5c);
            color: white;
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #0f6b5c, #0a4f44);
            transform: translateY(-2px);
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .action-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            border: 1px solid #e9ecef;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .action-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            color: inherit;
            text-decoration: none;
        }

        .action-icon {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .action-content h3 {
            margin: 0 0 0.5rem 0;
            color: #0077b6;
            font-weight: 600;
        }

        .action-content p {
            margin: 0;
            color: #6c757d;
            font-size: 0.9rem;
        }

        .action-arrow {
            margin-left: auto;
            color: #0077b6;
            font-size: 1.2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.8rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 0.8rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border-left: 3px solid;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        }

        .stat-card.outstanding {
            border-left-color: #dc3545;
        }

        .stat-card.collections {
            border-left-color: #28a745;
        }

        .stat-card.total {
            border-left-color: #007bff;
        }

        .stat-card.monthly {
            border-left-color: #ffc107;
        }

        .stat-icon {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            margin-bottom: 0.6rem;
        }

        .stat-content h3 {
            margin: 0 0 0.2rem 0;
            font-size: 0.85rem;
            color: #495057;
            font-weight: 600;
        }

        .stat-number {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0077b6;
            margin: 0.2rem 0;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.75rem;
            margin: 0;
        }

        .section-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f8f9fa;
        }

        .section-icon {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .section-title {
            margin: 0;
            font-size: 1.5rem;
            color: #0077b6;
            font-weight: 600;
        }

        .activity-table {
            width: 100%;
            border-collapse: collapse;
        }

        .activity-table th,
        .activity-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #f1f3f4;
            vertical-align: top;
        }

        .activity-table thead {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
        }

        .activity-table th {
            font-weight: 600;
            font-size: 0.9rem;
            letter-spacing: 0.5px;
            border: none;
        }

        .activity-table tbody tr {
            transition: all 0.2s ease;
        }

        .activity-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .activity-table tbody tr:last-child td {
            border-bottom: none;
        }

        .alert {
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid transparent;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }

        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }

        .alert i {
            font-size: 1.2rem;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            margin-left: auto;
            opacity: 0.7;
        }

        .btn-close:hover {
            opacity: 1;
        }

        /* Billing Management Styles */
        .billing-management-container {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .billing-panel {
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .panel-header {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 1.5rem 2rem;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .panel-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .panel-body {
            padding: 2rem;
        }

        .search-filters {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            padding: 0 2rem;
            padding-top: 1rem;
        }

        .filter-input {
            flex: 1;
            min-width: 150px;
            padding: 0.75rem;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .filter-input:focus {
            outline: none;
            border-color: #0077b6;
        }

        .filter-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .search-btn {
            background: #007BFF;
            color: white;
        }

        .search-btn:hover {
            background: #0056b3;
        }

        .clear-btn {
            background: #6c757d;
            color: white;
        }

        .clear-btn:hover {
            background: #545b62;
        }

        .billing-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .billing-table th,
        .billing-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #f1f3f4;
            vertical-align: top;
        }

        .billing-table thead {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
        }

        .billing-table th {
            font-weight: 600;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            border: none;
        }

        .billing-table tbody tr {
            transition: all 0.2s ease;
        }

        .billing-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-unpaid {
            background: #fff5f5;
            color: #dc3545;
            border: 1px solid #f5c6cb;
        }

        .status-paid {
            background: #f0fff4;
            color: #28a745;
            border: 1px solid #c3e6cb;
        }

        .status-partial {
            background: #fff8e1;
            color: #ffc107;
            border: 1px solid #ffeeba;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-info:hover {
            background: #138496;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #1e7e34;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            margin: 0 0 1rem 0;
            color: #495057;
        }

        .empty-state p {
            margin: 0 0 2rem 0;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 2rem;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            max-width: 900px;
            max-height: 90vh;
            width: 100%;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 2rem;
            cursor: pointer;
            padding: 0;
            line-height: 1;
        }

        .modal-body {
            flex: 1;
            overflow-y: auto;
            padding: 0;
        }

        .modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid #e9ecef;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .action-buttons {
                width: 100%;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions {
                grid-template-columns: 1fr;
            }

            .billing-management-container {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .search-filters {
                flex-direction: column;
            }

            .filter-input {
                min-width: auto;
            }
        }
    </style>
</head>

<body>
    <section class="billing-overview">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb" style="margin-top: 50px;">
            <?php if ($employee_role === 'records_officer'): ?>
                <a href="../../records_officer/dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <?php else: ?>
                <a href="../dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <?php endif; ?>
            <span> / </span>
            <span style="color: #0077b6; font-weight: 600;">Billing System Overview</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-file-invoice-dollar" style="margin-right: 0.5rem;"></i>Billing System Overview</h1>
            <div class="action-buttons">
                <?php if ($employee_role === 'admin'): ?>
                    <a href="../../cashier/billing_reports.php" class="btn btn-secondary">
                        <i class="fas fa-chart-bar"></i> Financial Reports
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Billing System Statistics -->
        <div class="section-container">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-chart-bar"></i>
                </div>
                <h2 class="section-title">Financial Overview</h2>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card outstanding">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #dc3545, #c82333);">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Outstanding Bills</h3>
                        <p class="stat-number">₱<?php echo number_format($stats['total_outstanding'] ?? 0, 2); ?></p>
                        <p class="stat-label"><?php echo $stats['unpaid_invoices'] ?? 0; ?> unpaid invoices</p>
                    </div>
                </div>

                <div class="stat-card collections">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #28a745, #1e7e34);">
                        <i class="fas fa-cash-register"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Today's Collections</h3>
                        <p class="stat-number">₱<?php echo number_format($stats['today_collections'] ?? 0, 2); ?></p>
                        <p class="stat-label">collected today</p>
                    </div>
                </div>

                <div class="stat-card monthly">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #ffc107, #e0a800);">
                        <i class="fas fa-calendar-month"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Monthly Collections</h3>
                        <p class="stat-number">₱<?php echo number_format($stats['month_collections'] ?? 0, 2); ?></p>
                        <p class="stat-label">this month</p>
                    </div>
                </div>

                <div class="stat-card total">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #007bff, #0056b3);">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Total Invoices</h3>
                        <p class="stat-number"><?php echo $stats['total_invoices'] ?? 0; ?></p>
                        <p class="stat-label">all time</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Billing Management Overview -->
        <div class="billing-management-container">
            <!-- Left Panel: All Invoices -->
            <div class="billing-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-file-invoice"></i> Invoice Management 
                        <?php if ($employee_role === 'admin'): ?>
                            (Admin View)
                        <?php elseif ($employee_role === 'records_officer'): ?>
                            (Records Officer View)
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
                                        <strong><?= htmlspecialchars($invoice['patient_name']) ?></strong><br>
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
                                            <button class="btn btn-secondary btn-sm" onclick="printInvoice(<?= $invoice['billing_id'] ?>)" title="Print Invoice">
                                                <i class="fas fa-print"></i>
                                            </button>
                                            <button class="btn btn-info btn-sm" onclick="downloadInvoice(<?= $invoice['billing_id'] ?>)" title="Download Invoice">
                                                <i class="fas fa-download"></i>
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
                                        <strong><?= htmlspecialchars($payment['patient_name']) ?></strong><br>
                                        <small style="color: #666;">
                                            Invoice #<?= $payment['billing_id'] ?> • 
                                            <?= ucfirst($payment['payment_method']) ?>
                                            <?php if ($payment['change_amount'] > 0): ?>
                                            <br><span style="color: #17a2b8;">Paid: ₱<?= number_format($payment['amount_paid'], 2) ?> | Change: ₱<?= number_format($payment['change_amount'], 2) ?></span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div style="text-align: right;">
                                        <strong style="color: #28a745;">₱<?= number_format($payment['actual_payment_applied'], 2) ?></strong><br>
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
        // API base path - auto-detect based on current page location
        function getApiBasePath() {
            const currentPath = window.location.pathname;
            const hostname = window.location.hostname;
            
            // Production environment detection
            if (hostname.includes('sslip.io') || hostname.includes('cityhealthoffice')) {
                // For production environments, try to detect the correct base path
                const pathParts = currentPath.split('/');
                
                // Remove the pages and subsequent directories to get to root
                let projectRoot = '';
                for (let i = 0; i < pathParts.length; i++) {
                    if (pathParts[i] === 'pages') {
                        projectRoot = pathParts.slice(0, i).join('/') || '/';
                        break;
                    }
                }
                
                // If no pages directory found, assume root
                if (!projectRoot) {
                    projectRoot = '/';
                }
                
                return window.location.protocol + '//' + window.location.host + projectRoot;
            }
            
            // Local development environment (localhost)
            const pathParts = currentPath.split('/');
            
            // Find the project root by looking for the pages directory
            let projectRoot = '';
            for (let i = 0; i < pathParts.length; i++) {
                if (pathParts[i] === 'pages') {
                    projectRoot = pathParts.slice(0, i).join('/') || '/';
                    break;
                }
            }
            
            // If we can't find pages directory, assume we're in the project root
            if (!projectRoot && currentPath.includes('wbhsms-cho-koronadal-1')) {
                projectRoot = currentPath.substring(0, currentPath.indexOf('wbhsms-cho-koronadal-1') + 'wbhsms-cho-koronadal-1'.length);
            } else if (!projectRoot) {
                projectRoot = '';
            }
            
            return window.location.protocol + '//' + window.location.host + projectRoot;
        }
        
        const API_BASE_PATH = getApiBasePath();
        console.log('API Base Path:', API_BASE_PATH);
        console.log('Current pathname:', window.location.pathname);
        
        // Debug function to help identify correct paths in production
        function debugApiPaths() {
            console.group('API Path Debug Information');
            console.log('Window location:', window.location);
            console.log('Document URL:', document.URL);
            console.log('Base URI:', document.baseURI);
            console.log('Current script location:', document.currentScript?.src || 'N/A');
            console.log('Calculated API base path:', API_BASE_PATH);
            
            // Test if common API paths exist
            const testPaths = [
                `${API_BASE_PATH}/api/billing/management/`,
                `./../../../../api/billing/management/`,
                `/api/billing/management/`,
                `../../../api/billing/management/`
            ];
            
            console.log('Testing API path accessibility...');
            testPaths.forEach((path, index) => {
                console.log(`Path ${index + 1}: ${path}`);
            });
            console.groupEnd();
        }
        
        // Call debug function on page load
        debugApiPaths();
        
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
        
        // Universal path function for API calls (works in both local and production)
        function getApiBasePath() {
            const currentPath = window.location.pathname;
            const pathParts = currentPath.split('/');
            
            // Find the project root by looking for the pages directory
            let projectRoot = '';
            for (let i = 0; i < pathParts.length; i++) {
                if (pathParts[i] === 'pages') {
                    projectRoot = pathParts.slice(0, i).join('/') || '/';
                    break;
                }
            }
            
            // If no pages directory found, try to find wbhsms-cho-koronadal-1
            if (!projectRoot && currentPath.includes('wbhsms-cho-koronadal-1')) {
                const index = currentPath.indexOf('wbhsms-cho-koronadal-1');
                projectRoot = currentPath.substring(0, index + 'wbhsms-cho-koronadal-1'.length);
            } else if (!projectRoot) {
                // Fallback for production environments
                projectRoot = '';
            }
            
            return projectRoot;
        }
        
        // View invoice details in modal with universal API path
        function viewInvoice(billingId) {
            try {
                // Show loading state
                showInvoiceModal('<div style="padding: 3rem; text-align: center; color: #666;"><i class="fas fa-spinner fa-spin"></i><p>Loading invoice details...</p></div>');
                
                // Use universal path function for API calls
                const basePath = getApiBasePath();
                const apiUrl = `${basePath}/api/billing/management/get_invoice_details.php?billing_id=${billingId}`;
                console.log('API URL:', apiUrl);
                
                fetch(apiUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => {
                    console.log('Response status:', response.status);
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Invoice data loaded:', data);
                    if (data.success) {
                        displayInvoiceDetails(data.invoice);
                    } else {
                        throw new Error(data.message || 'Failed to load invoice details');
                    }
                })
                .catch(error => {
                    console.error('Error loading invoice details:', error);
                    showInvoiceModal(`
                        <div style="padding: 2rem; text-align: center; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                            <p>Error loading invoice details:</p>
                            <p style="font-size: 0.9em; color: #666;">${error.message}</p>
                        </div>
                    `);
                });
                
            } catch (error) {
                console.error('Error in viewInvoice:', error);
                showInvoiceModal('<div style="padding: 2rem; text-align: center; color: #dc3545;"><i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 1rem;"></i><p>Error loading invoice details</p></div>');
            }
        }
        
        // Recursive function to try different API paths
        // Print invoice using universal API path
        function printInvoice(billingId) {
            try {
                const basePath = getApiBasePath();
                const printUrl = `${basePath}/api/billing/management/print_invoice.php?billing_id=${billingId}&format=html`;
                console.log('Print URL:', printUrl);
                
                // Open in new window for printing
                const printWindow = window.open(printUrl, 'PrintInvoice', 'width=800,height=600,scrollbars=yes');
                
                if (!printWindow) {
                    alert('Pop-up blocked. Please allow pop-ups for this site to print invoices.');
                }
                
            } catch (error) {
                console.error('Error opening print window:', error);
                alert('Error opening print window. Please try again.');
            }
        }

        // Download invoice as PDF using universal API path
        function downloadInvoice(billingId) {
            try {
                const basePath = getApiBasePath();
                const downloadUrl = `${basePath}/api/billing/management/print_invoice.php?billing_id=${billingId}&format=pdf`;
                console.log('Download URL:', downloadUrl);
                
                // Create temporary link for download
                const link = document.createElement('a');
                link.href = downloadUrl;
                link.download = `invoice_${billingId}.pdf`;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                
            } catch (error) {
                console.error('Error downloading PDF:', error);
                alert('Error downloading PDF. Please try again.');
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
        
        // Ensure functions are globally accessible
        window.viewInvoice = viewInvoice;
        window.printInvoice = printInvoice;
        window.downloadInvoice = downloadInvoice;
        window.applyFilters = applyFilters;
        window.clearFilters = clearFilters;
        window.closeInvoiceModal = closeInvoiceModal;
        window.printModalInvoice = printModalInvoice;

        // Auto-dismiss alerts
        document.querySelectorAll('.alert').forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });
    </script>
</body>

</html>