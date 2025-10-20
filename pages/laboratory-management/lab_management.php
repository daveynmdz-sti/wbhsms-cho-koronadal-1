<?php
// Include employee session configuration
// Use absolute path resolution
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

// Use relative path for assets - more reliable than absolute URLs
$assets_path = '../../assets';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header("Location: ../auth/employee_login.php");
    exit();
}

// Set active page for sidebar highlighting
$activePage = 'laboratory_management';

// Define role-based permissions using role_id
$canViewLab = in_array($_SESSION['role_id'], [1, 2, 3, 9]); // admin, doctor, nurse, laboratory_tech
$canUploadResults = in_array($_SESSION['role_id'], [1, 9]); // admin, laboratory_tech
$canCreateOrders = in_array($_SESSION['role_id'], [1, 2, 3, 9]); // admin, doctor, nurse, laboratory_tech

if (!$canViewLab) {
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
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$dateFilter = isset($_GET['date']) ? $_GET['date'] : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$recordsPerPage = 15;
$offset = ($page - 1) * $recordsPerPage;

// Check if overall_status column exists, if not use regular status
$checkColumnSql = "SHOW COLUMNS FROM lab_orders LIKE 'overall_status'";
$columnResult = $conn->query($checkColumnSql);
$hasOverallStatus = $columnResult->num_rows > 0;

// Fetch lab orders with patient information
$statusColumn = $hasOverallStatus ? 'lo.overall_status' : 'lo.status';
$ordersSql = "SELECT lo.lab_order_id, lo.patient_id, lo.order_date, lo.status, 
                     $statusColumn as overall_status,
                     lo.ordered_by_employee_id, lo.remarks,
                     p.first_name, p.last_name, p.middle_name, p.username as patient_id_display,
                     e.first_name as ordered_by_first_name, e.last_name as ordered_by_last_name,
                     COUNT(loi.item_id) as total_tests,
                     SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_tests
              FROM lab_orders lo
              LEFT JOIN patients p ON lo.patient_id = p.patient_id
              LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
              LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
              WHERE (DATE(lo.order_date) = CURDATE() OR $statusColumn = 'pending')";

$params = [];
$types = "";

if (!empty($searchQuery)) {
    $ordersSql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.username LIKE ?)";
    $searchParam = "%{$searchQuery}%";
    array_push($params, $searchParam, $searchParam, $searchParam);
    $types .= "sss";
}

if (!empty($statusFilter)) {
    $ordersSql .= " AND $statusColumn = ?";
    array_push($params, $statusFilter);
    $types .= "s";
}

if (!empty($dateFilter)) {
    $ordersSql .= " AND DATE(lo.order_date) = ?";
    array_push($params, $dateFilter);
    $types .= "s";
}

$ordersSql .= " GROUP BY lo.lab_order_id 
                ORDER BY lo.order_date DESC 
                LIMIT ? OFFSET ?";
array_push($params, $recordsPerPage, $offset);
$types .= "ii";

$ordersStmt = $conn->prepare($ordersSql);
if (!empty($types)) {
    $ordersStmt->bind_param($types, ...$params);
}
$ordersStmt->execute();
$ordersResult = $ordersStmt->get_result();

// Fetch recent lab records for the right panel (using existing schema)
$recentSql = "SELECT loi.item_id as lab_order_item_id, loi.lab_order_id, loi.test_type, loi.status,
                     loi.result_date, loi.result_file,
                     p.first_name, p.last_name, p.username as patient_id_display,
                     'System' as uploaded_by_first_name, '' as uploaded_by_last_name
              FROM lab_order_items loi
              LEFT JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
              LEFT JOIN patients p ON lo.patient_id = p.patient_id
              WHERE loi.status IN ('completed', 'in_progress')
              ORDER BY loi.updated_at DESC
              LIMIT 20";

$recentStmt = $conn->prepare($recentSql);
$recentStmt->execute();
$recentResult = $recentStmt->get_result();

// Calculate statistics for dashboard cards
$lab_stats = [];
try {
    // Get statistics based on available status column
    $statsColumn = $hasOverallStatus ? 'overall_status' : 'status';
    
    $statsSql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN $statsColumn = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN $statsColumn = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN $statsColumn = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN $statsColumn = 'cancelled' THEN 1 ELSE 0 END) as cancelled
                 FROM lab_orders 
                 WHERE order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    
    $statsResult = $conn->query($statsSql);
    if ($statsResult) {
        $lab_stats = $statsResult->fetch_assoc();
    } else {
        // Default values if query fails
        $lab_stats = [
            'total' => 0,
            'pending' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'cancelled' => 0
        ];
    }
} catch (Exception $e) {
    // Default values if error occurs
    error_log("Lab stats calculation error: " . $e->getMessage());
    $lab_stats = [
        'total' => 0,
        'pending' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'cancelled' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laboratory Management - WBHSMS</title>
    <link rel="stylesheet" href="<?= $assets_path ?>/css/sidebar.css">
    <link rel="stylesheet" href="<?= $assets_path ?>/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Content wrapper and page layout */
        .content-wrapper {
            margin-left: 300px;
            padding: 20px;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
                padding: 10px;
            }
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .page-header h1 {
            color: #0077b6;
            margin: 0;
            font-size: 1.8rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card.total {
            border-left: 5px solid #6c757d;
        }

        .stat-card.pending {
            border-left: 5px solid #ffc107;
        }

        .stat-card.active {
            border-left: 5px solid #17a2b8;
        }

        .stat-card.completed {
            border-left: 5px solid #28a745;
        }

        .stat-card.voided {
            border-left: 5px solid #dc3545;
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
            background-color: #0077b6;
            color: white;
        }

        .btn-primary:hover {
            background-color: #005577;
        }

        /* Laboratory Management Specific Styles */
        .lab-management-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }

        .lab-panel {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #03045e;
        }

        .panel-title {
            font-size: 1.3em;
            font-weight: bold;
            color: #03045e;
        }

        .create-order-btn {
            background: #03045e;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            transition: background-color 0.3s;
        }

        .create-order-btn:hover {
            background: #0218A7;
        }

        .lab-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .lab-table th,
        .lab-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            font-size: 0.9em;
        }

        .lab-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #03045e;
        }

        .lab-table tr:hover {
            background-color: #f5f5f5;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .status-in_progress {
            background-color: #d1ecf1;
            color: #0c5460;
        }

        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }

        .status-cancelled {
            background-color: #f8d7da;
            color: #721c24;
        }

        .status-partial {
            background-color: #e2e3e5;
            color: #383d41;
        }

        .action-btn {
            padding: 5px 10px;
            margin: 2px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.8em;
            transition: all 0.3s;
        }

        .btn-view {
            background-color: #007bff;
            color: white;
        }

        .btn-upload {
            background-color: #28a745;
            color: white;
        }

        .btn-download {
            background-color: #17a2b8;
            color: white;
        }

        .action-btn:hover {
            opacity: 0.8;
            transform: translateY(-1px);
        }

        .search-filters {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
            align-items: stretch;
        }

        .filter-input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9em;
            flex: 1;
            min-width: 150px;
        }

        .filter-btn {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9em;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .search-btn {
            background-color: #0077b6;
            color: white;
        }

        .search-btn:hover {
            background-color: #005577;
        }

        .clear-btn {
            background-color: #6c757d;
            color: white;
        }

        .clear-btn:hover {
            background-color: #545b62;
        }

        .progress-bar {
            width: 60px;
            height: 8px;
            background-color: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background-color: #007bff;
            transition: width 0.3s;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 10px;
            max-width: 80%;
            max-height: 80%;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }

        .close-btn {
            font-size: 1.5em;
            cursor: pointer;
            color: #aaa;
            border: none;
            background: none;
        }

        .close-btn:hover {
            color: #000;
        }

        @media (max-width: 768px) {
            .lab-management-container {
                grid-template-columns: 1fr;
            }

            .search-filters {
                flex-direction: column;
            }

            .filter-input {
                min-width: 100%;
            }
        }

        /* Alert Styles */
        .alert {
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
    </style>
</head>

<body>
    <!-- Include Admin Sidebar -->
    <?php include $root_path . '/includes/sidebar_admin.php'; ?>

    <section class="content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb" style="margin-top: 50px;">
            <a href="../management/admin/dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <span>Laboratory Management</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-flask"></i> Laboratory Management</h1>
            <?php if ($canCreateOrders): ?>
                <a href="create_lab_order.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create Lab Order
                </a>
            <?php endif; ?>
        </div>

        <!-- Success/Error Messages -->
        <div id="alertContainer"></div>

        <!-- Laboratory Statistics -->
        <div class="stats-grid">
            <?php
            // Get laboratory statistics
            $lab_stats = [
                'total' => 0,
                'pending' => 0,
                'in_progress' => 0,
                'completed' => 0,
                'cancelled' => 0
            ];

            try {
                $stats_sql = "SELECT 
                                    COUNT(*) as total,
                                    COALESCE(SUM(CASE WHEN " . ($hasOverallStatus ? 'overall_status' : 'status') . " = 'pending' THEN 1 ELSE 0 END), 0) as pending,
                                    COALESCE(SUM(CASE WHEN " . ($hasOverallStatus ? 'overall_status' : 'status') . " = 'in_progress' THEN 1 ELSE 0 END), 0) as in_progress,
                                    COALESCE(SUM(CASE WHEN " . ($hasOverallStatus ? 'overall_status' : 'status') . " = 'completed' THEN 1 ELSE 0 END), 0) as completed,
                                    COALESCE(SUM(CASE WHEN " . ($hasOverallStatus ? 'overall_status' : 'status') . " = 'cancelled' THEN 1 ELSE 0 END), 0) as cancelled
                                  FROM lab_orders WHERE DATE(order_date) >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)";

                $stats_result = $conn->query($stats_sql);
                if ($stats_result && $row = $stats_result->fetch_assoc()) {
                    // Ensure all values are integers, converting NULL to 0
                    $lab_stats = [
                        'total' => intval($row['total'] ?? 0),
                        'pending' => intval($row['pending'] ?? 0),
                        'in_progress' => intval($row['in_progress'] ?? 0),
                        'completed' => intval($row['completed'] ?? 0),
                        'cancelled' => intval($row['cancelled'] ?? 0)
                    ];
                }
            } catch (Exception $e) {
                // Use default values if query fails
                error_log("Laboratory statistics query failed: " . $e->getMessage());
            }
            ?>

            <div class="stat-card total">
                <div class="stat-number"><?= number_format($lab_stats['total'] ?? 0) ?></div>
                <div class="stat-label">Total Orders (30 days)</div>
            </div>

            <div class="stat-card pending">
                <div class="stat-number"><?= number_format($lab_stats['pending'] ?? 0) ?></div>
                <div class="stat-label">Pending Orders</div>
            </div>

            <div class="stat-card active">
                <div class="stat-number"><?= number_format($lab_stats['in_progress'] ?? 0) ?></div>
                <div class="stat-label">In Progress</div>
            </div>

            <div class="stat-card completed">
                <div class="stat-number"><?= number_format($lab_stats['completed'] ?? 0) ?></div>
                <div class="stat-label">Completed</div>
            </div>

            <div class="stat-card voided">
                <div class="stat-number"><?= number_format($lab_stats['cancelled'] ?? 0) ?></div>
                <div class="stat-label">Cancelled</div>
            </div>
        </div>

        <div class="lab-management-container">
            <!-- Left Panel: Lab Orders -->
            <div class="lab-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-list-alt"></i> Lab Orders
                    </div>
                </div>

                <!-- Search and Filter Controls -->
                <div class="search-filters">
                    <input type="text" class="filter-input" id="searchOrders" placeholder="Search patient name or ID..." value="<?= htmlspecialchars($searchQuery) ?>">
                    <select class="filter-input" id="statusFilter">
                        <option value="">All Statuses</option>
                        <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="completed" <?= $statusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="partial" <?= $statusFilter === 'partial' ? 'selected' : '' ?>>Partial</option>
                        <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                    <input type="date" class="filter-input" id="dateFilter" value="<?= htmlspecialchars($dateFilter) ?>">
                </div>
                <div class="search-filters" style="justify-content: flex-end; margin-top: -10px;">
                    <button type="button" class="filter-btn search-btn" id="searchBtn" onclick="applyFilters()">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <button type="button" class="filter-btn clear-btn" id="clearBtn" onclick="clearFilters()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>

                <!-- Lab Orders Table -->
                <table class="lab-table">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Order Date</th>
                            <th>Tests</th>
                            <th>Progress</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($order = $ordersResult->fetch_assoc()): ?>
                            <?php
                            $patientName = trim($order['first_name'] . ' ' . $order['middle_name'] . ' ' . $order['last_name']);
                            $progressPercent = $order['total_tests'] > 0 ? round(($order['completed_tests'] / $order['total_tests']) * 100) : 0;
                            ?>
                            <tr data-lab-order-id="<?= $order['lab_order_id'] ?>" data-completed="<?= $order['completed_tests'] ?>" data-total="<?= $order['total_tests'] ?>" data-current-status="<?= $order['overall_status'] ?>">
                                <td>
                                    <strong><?= htmlspecialchars($patientName) ?></strong><br>
                                    <small>ID: <?= htmlspecialchars($order['patient_id_display']) ?></small>
                                </td>
                                <td><?= date('M d, Y', strtotime($order['order_date'])) ?></td>
                                <td><?= $order['total_tests'] ?> test(s)</td>
                                <td>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?= $progressPercent ?>%"></div>
                                    </div>
                                    <small class="progress-text"><?= $order['completed_tests'] ?>/<?= $order['total_tests'] ?></small>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $order['overall_status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $order['overall_status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="action-btn btn-view" onclick="viewOrderDetails(<?= $order['lab_order_id'] ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                    <?php if ($canUploadResults && $order['overall_status'] !== 'completed'): ?>
                                        <button class="action-btn btn-upload" onclick="showQuickUpload(<?= $order['lab_order_id'] ?>)" title="Quick Upload">
                                            <i class="fas fa-upload"></i> Upload
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Right Panel: Recent Lab Records -->
            <div class="lab-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-history"></i> Recent Lab Records
                    </div>
                </div>

                <!-- Recent Records Table -->
                <table class="lab-table">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Test Type</th>
                            <th>Status</th>
                            <th>Result Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($record = $recentResult->fetch_assoc()): ?>
                            <?php
                            $patientName = trim($record['first_name'] . ' ' . $record['last_name']);
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($patientName) ?></strong><br>
                                    <small>ID: <?= htmlspecialchars($record['patient_id_display']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($record['test_type']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= $record['status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $record['status'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= $record['result_date'] ? date('M d, Y', strtotime($record['result_date'])) : 'N/A' ?>
                                </td>
                                <td>
                                    <?php if ($record['result_file']): ?>
                                        <button class="action-btn btn-download" onclick="downloadResult(<?= $record['lab_order_item_id'] ?>)">
                                            <i class="fas fa-download"></i>
                                        </button>
                                    <?php elseif ($canUploadResults && $record['status'] !== 'completed'): ?>
                                        <button class="action-btn btn-upload" onclick="uploadResult(<?= $record['lab_order_item_id'] ?>)">
                                            <i class="fas fa-upload"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>


    <!-- Order Details Modal -->
    <div id="orderDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Lab Order Details</h3>
                <button class="close-btn" onclick="closeModal('orderDetailsModal')">&times;</button>
            </div>
            <div id="modalBody">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Upload Result Modal -->
    <div id="uploadResultModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Upload Lab Result</h3>
                <button class="close-btn" onclick="closeModal('uploadResultModal')">&times;</button>
            </div>
            <div id="uploadResultBody">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Quick Upload Modal -->
    <div id="quickUploadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Upload Lab Results</h3>
                <button class="close-btn" onclick="closeModal('quickUploadModal')">&times;</button>
            </div>
            <div id="quickUploadBody">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>

    <script>
        // Laboratory Management JavaScript Functions

        // Search and filter functionality
        document.getElementById('statusFilter').addEventListener('change', applyFilters);
        document.getElementById('dateFilter').addEventListener('change', applyFilters);

        // Allow Enter key to trigger search
        document.getElementById('searchOrders').addEventListener('keypress', function(event) {
            if (event.key === 'Enter') {
                applyFilters();
            }
        });

        function applyFilters() {
            const search = document.getElementById('searchOrders').value.trim();
            const status = document.getElementById('statusFilter').value;
            const date = document.getElementById('dateFilter').value;

            const params = new URLSearchParams();
            if (search) params.set('search', search);
            if (status) params.set('status', status);
            if (date) params.set('date', date);

            window.location.href = '?' + params.toString();
        }

        function clearFilters() {
            document.getElementById('searchOrders').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('dateFilter').value = '';

            // Redirect to page without any filters
            window.location.href = window.location.pathname;
        }

        // Automatic Status Update Functions
        function checkAndUpdateLabOrderStatuses() {
            console.log('Checking lab order statuses for automatic updates...');
            
            // Get all lab order rows with data attributes
            const labOrderRows = document.querySelectorAll('tbody tr[data-lab-order-id]');
            
            labOrderRows.forEach(row => {
                const labOrderId = row.getAttribute('data-lab-order-id');
                const completed = parseInt(row.getAttribute('data-completed'));
                const total = parseInt(row.getAttribute('data-total'));
                const currentStatus = row.getAttribute('data-current-status');
                
                if (!labOrderId || isNaN(completed) || isNaN(total)) return;
                
                let shouldUpdateTo = null;
                
                // Check if status needs updating based on completion
                if (completed === total && total > 0 && currentStatus === 'pending') {
                    shouldUpdateTo = 'completed';
                } else if (completed > 0 && completed < total && currentStatus === 'pending') {
                    shouldUpdateTo = 'in_progress';
                }
                
                // Update status if needed
                if (shouldUpdateTo) {
                    console.log(`Auto-updating lab order ${labOrderId} from ${currentStatus} to ${shouldUpdateTo}`);
                    updateLabOrderStatusAutomatically(labOrderId, shouldUpdateTo, row);
                }
            });
        }

        // Function to automatically update lab order status
        function updateLabOrderStatusAutomatically(labOrderId, newStatus, rowElement) {
            fetch('api/update_lab_order_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    lab_order_id: labOrderId,
                    overall_status: newStatus,
                    remarks: 'Auto-updated based on item completion status',
                    auto_update: true
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update the status badge in the UI immediately
                    const statusBadge = rowElement.querySelector('.status-badge');
                    if (statusBadge) {
                        statusBadge.className = `status-badge status-${newStatus}`;
                        statusBadge.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1).replace('_', ' ');
                    }
                    
                    // Update data attribute
                    rowElement.setAttribute('data-current-status', newStatus);
                    
                    // Show success notification
                    console.log(`Lab Order #${labOrderId} status automatically updated to ${newStatus}`);
                    
                    // Optionally show a subtle notification
                    showAlert(`Lab Order #${labOrderId} status automatically updated to ${newStatus.replace('_', ' ')}`, 'success');
                    
                } else {
                    console.error('Failed to update lab order status:', data.message);
                }
            })
            .catch(error => {
                console.error('Error updating lab order status:', error);
            });
        }

        // Run automatic status check on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Run after a short delay to ensure page is fully loaded
            setTimeout(checkAndUpdateLabOrderStatuses, 1000);
        });

        // Re-run status check after modal operations (uploads, etc.)
        function refreshStatusChecks() {
            setTimeout(checkAndUpdateLabOrderStatuses, 500);
        }

        // Modal functions
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function viewOrderDetails(labOrderId) {
            document.getElementById('orderDetailsModal').style.display = 'block';
            document.getElementById('modalBody').innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

            fetch(`api/get_lab_order_details.php?lab_order_id=${labOrderId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('modalBody').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('modalBody').innerHTML = '<div class="alert alert-error">Error loading order details.</div>';
                });
        }

        function showQuickUpload(labOrderId) {
            console.log('Quick upload called for lab order:', labOrderId);
            
            document.getElementById('quickUploadModal').style.display = 'block';
            document.getElementById('quickUploadBody').innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

            fetch(`api/get_lab_order_items.php?lab_order_id=${labOrderId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.text();
                })
                .then(html => {
                    document.getElementById('quickUploadBody').innerHTML = html;
                })
                .catch(error => {
                    console.error('Error loading lab items:', error);
                    document.getElementById('quickUploadBody').innerHTML = '<div class="alert alert-error">Error loading lab items: ' + error.message + '</div>';
                });
        }

        function uploadResult(labOrderItemId) {
            <?php if ($canUploadResults): ?>
                console.log('Upload function called with item ID:', labOrderItemId);
                
                // Close any open order details modal first
                closeModal('orderDetailsModal');
                
                document.getElementById('uploadResultModal').style.display = 'block';
                
                // Use iframe approach for better isolation
                const iframe = document.createElement('iframe');
                iframe.src = `upload_lab_result_modal.php?item_id=${labOrderItemId}`;
                iframe.style.cssText = `
                    width: 100%; 
                    height: 600px; 
                    border: none; 
                    border-radius: 8px;
                `;
                
                const uploadBody = document.getElementById('uploadResultBody');
                uploadBody.innerHTML = ''; // Clear existing content
                uploadBody.appendChild(iframe);
                
            <?php else: ?>
                showAlert('You are not authorized to upload lab results.', 'error');
            <?php endif; ?>
        }

        function downloadResult(itemId) {
            window.open(`api/download_lab_result.php?item_id=${itemId}`, '_blank');
        }

        function uploadItemResult(labOrderItemId) {
            // This function handles upload from the modal details view
            uploadResult(labOrderItemId);
        }
        
        function uploadSingleResult(labOrderItemId) {
            // This function handles upload from the quick upload modal
            console.log('uploadSingleResult called with item ID:', labOrderItemId);
            closeModal('quickUploadModal');
            setTimeout(() => {
                uploadResult(labOrderItemId);
            }, 100);
        }

        function refreshOrderDetails(labOrderId) {
            // Refresh the order details modal if it's open
            const orderModal = document.getElementById('orderDetailsModal');
            if (orderModal && orderModal.style.display === 'block') {
                viewOrderDetails(labOrderId);
            }
            // Run status check and refresh if needed
            refreshStatusChecks();
            // Also refresh the main page
            window.location.reload();
        }
        
        // Add global refresh function for upload success
        window.refreshAfterUpload = function() {
            // Close upload modal
            closeModal('uploadResultModal');
            // Show success message
            showAlert('Lab result uploaded successfully!', 'success');
            // Run status check before full reload
            refreshStatusChecks();
            // Refresh page after delay
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        };
        
        // Make upload function globally accessible
        window.uploadResult = uploadResult;
        window.uploadSingleResult = uploadSingleResult;
        window.downloadResult = downloadResult;

        // Functions are now handled by inline events in modal content

        function showAlert(message, type = 'success') {
            const alertContainer = document.getElementById('alertContainer');
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `
                <span><i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}</span>
                <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
            `;
            alertContainer.appendChild(alertDiv);

            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentElement) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // File handling functions for upload modal
        function triggerFileSelect() {
            console.log('triggerFileSelect called from main page');
            const fileInput = document.getElementById('result_file');
            if (fileInput) {
                fileInput.click();
                console.log('File input clicked');
            } else {
                console.error('File input not found');
            }
        }

        function handleFileSelect(event) {
            console.log('handleFileSelect called from main page', event);
            const file = event.target.files[0];
            console.log('Selected file:', file);
            if (file) {
                updateFileDisplay(file);
            }
        }

        function handleDrop(event) {
            event.preventDefault();
            event.stopPropagation();
            const file = event.dataTransfer.files[0];
            if (file) {
                document.getElementById('result_file').files = event.dataTransfer.files;
                updateFileDisplay(file);
            }
            event.target.classList.remove('drag-over');
        }

        function handleDragOver(event) {
            event.preventDefault();
            event.target.classList.add('drag-over');
        }

        function handleDragLeave(event) {
            event.target.classList.remove('drag-over');
        }

        function updateFileDisplay(file) {
            console.log('updateFileDisplay called with file:', file);
            
            // Try to update the selected file div
            const selectedFileDiv = document.getElementById('selectedFile');
            if (selectedFileDiv) {
                selectedFileDiv.innerHTML = `
                    <div class="file-details">
                        <i class="fas fa-file"></i>
                        <strong>Selected:</strong> ${file.name}<br>
                        <strong>Size:</strong> ${(file.size / 1024 / 1024).toFixed(2)} MB<br>
                        <strong>Type:</strong> ${file.type || 'Unknown'}
                    </div>
                `;
                selectedFileDiv.style.display = 'block';
                console.log('Updated selectedFile div');
            }
            
            // Try to update the upload text
            const uploadText = document.querySelector('.upload-text');
            if (uploadText) {
                uploadText.textContent = 'File selected successfully!';
                console.log('Updated upload text');
            }
            
            // Also try the older file-info selector for compatibility
            const fileInfo = document.querySelector('.file-info');
            if (fileInfo && !selectedFileDiv) {
                fileInfo.textContent = `Selected: ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)`;
                console.log('Updated file info text');
            }
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const modals = ['orderDetailsModal', 'uploadResultModal', 'quickUploadModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    closeModal(modalId);
                }
            });
        });

        // Handle server-side messages
        <?php if (isset($_SESSION['lab_message'])): ?>
            showAlert('<?= addslashes($_SESSION['lab_message']) ?>', '<?= $_SESSION['lab_message_type'] ?? 'success' ?>');
        <?php
            unset($_SESSION['lab_message']);
            unset($_SESSION['lab_message_type']);
        endif; ?>
    </script>
</body>

</html>