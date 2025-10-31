<?php
// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

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
$canViewLab = in_array($_SESSION['role_id'], [1, 2, 3, 7, 9]); // admin, doctor, nurse, records_officer, laboratory_tech
$canUploadResults = in_array($_SESSION['role_id'], [9]); // laboratory_tech only
$canCreateOrders = in_array($_SESSION['role_id'], [1, 2, 3, 9]); // admin, doctor, nurse, laboratory_tech (records officers cannot create)

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
$recordsPerPage = 5; // Changed from 15 to 5 for better pagination
$offset = ($page - 1) * $recordsPerPage;

// Handle recent records search and filter
$recentSearchQuery = isset($_GET['recent_search']) ? $_GET['recent_search'] : '';
$recentStatusFilter = isset($_GET['recent_status']) ? $_GET['recent_status'] : '';
$recentDateFilter = isset($_GET['recent_date']) ? $_GET['recent_date'] : '';
$recentPage = isset($_GET['recent_page']) ? intval($_GET['recent_page']) : 1;
$recentRecordsPerPage = 5; // 5 records per page for recent records
$recentOffset = ($recentPage - 1) * $recentRecordsPerPage;

// Check if overall_status column exists, if not use regular status
$checkColumnSql = "SHOW COLUMNS FROM lab_orders LIKE 'overall_status'";
$columnResult = $conn->query($checkColumnSql);
$hasOverallStatus = $columnResult->num_rows > 0;

// Fetch lab orders with patient information - Show ALL orders, not just today's or pending
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
              WHERE 1=1"; // Show all orders by default

$params = [];
$types = "";

if (!empty($searchQuery)) {
    $ordersSql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.middle_name LIKE ? OR p.username LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR CONCAT(p.first_name, ' ', p.middle_name, ' ', p.last_name) LIKE ?)";
    $searchParam = "%{$searchQuery}%";
    array_push($params, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    $types .= "ssssss";
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

// Get total count for pagination
$countSql = "SELECT COUNT(DISTINCT lo.lab_order_id) as total_count
             FROM lab_orders lo
             LEFT JOIN patients p ON lo.patient_id = p.patient_id
             WHERE 1=1";

$countParams = [];
$countTypes = "";

if (!empty($searchQuery)) {
    $countSql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.middle_name LIKE ? OR p.username LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR CONCAT(p.first_name, ' ', p.middle_name, ' ', p.last_name) LIKE ?)";
    $searchParam = "%{$searchQuery}%";
    array_push($countParams, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    $countTypes .= "ssssss";
}

if (!empty($statusFilter)) {
    $countSql .= " AND $statusColumn = ?";
    array_push($countParams, $statusFilter);
    $countTypes .= "s";
}

if (!empty($dateFilter)) {
    $countSql .= " AND DATE(lo.order_date) = ?";
    array_push($countParams, $dateFilter);
    $countTypes .= "s";
}

// Execute count query
$countStmt = $conn->prepare($countSql);
if (!empty($countTypes)) {
    $countStmt->bind_param($countTypes, ...$countParams);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRecords = $countResult->fetch_assoc()['total_count'];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Execute main query
$ordersStmt = $conn->prepare($ordersSql);
if (!empty($types)) {
    $ordersStmt->bind_param($types, ...$params);
}
$ordersStmt->execute();
$ordersResult = $ordersStmt->get_result();

// Fetch recent lab records for the right panel (using existing schema)
$recentSql = "SELECT loi.item_id as lab_order_item_id, loi.lab_order_id, loi.test_type, loi.status,
                     loi.result_date, loi.result_file, loi.updated_at,
                     p.first_name, p.last_name, p.username as patient_id_display,
                     'System' as uploaded_by_first_name, '' as uploaded_by_last_name
              FROM lab_order_items loi
              LEFT JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
              LEFT JOIN patients p ON lo.patient_id = p.patient_id
              WHERE loi.status IN ('completed', 'in_progress', 'pending')";

$recentParams = [];
$recentTypes = "";

if (!empty($recentSearchQuery)) {
    $recentSql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR loi.test_type LIKE ? OR p.username LIKE ?)";
    $recentSearchParam = "%{$recentSearchQuery}%";
    array_push($recentParams, $recentSearchParam, $recentSearchParam, $recentSearchParam, $recentSearchParam, $recentSearchParam);
    $recentTypes .= "sssss";
}

if (!empty($recentStatusFilter)) {
    $recentSql .= " AND loi.status = ?";
    array_push($recentParams, $recentStatusFilter);
    $recentTypes .= "s";
}

if (!empty($recentDateFilter)) {
    $recentSql .= " AND DATE(loi.updated_at) = ?";
    array_push($recentParams, $recentDateFilter);
    $recentTypes .= "s";
}

$recentSql .= " ORDER BY loi.updated_at DESC 
                LIMIT ? OFFSET ?";
array_push($recentParams, $recentRecordsPerPage, $recentOffset);
$recentTypes .= "ii";

// Get total count for recent records pagination
$recentCountSql = "SELECT COUNT(*) as total_count
                   FROM lab_order_items loi
                   LEFT JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
                   LEFT JOIN patients p ON lo.patient_id = p.patient_id
                   WHERE loi.status IN ('completed', 'in_progress', 'pending')";

$recentCountParams = [];
$recentCountTypes = "";

if (!empty($recentSearchQuery)) {
    $recentCountSql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR loi.test_type LIKE ? OR p.username LIKE ?)";
    $recentSearchParam = "%{$recentSearchQuery}%";
    array_push($recentCountParams, $recentSearchParam, $recentSearchParam, $recentSearchParam, $recentSearchParam, $recentSearchParam);
    $recentCountTypes .= "sssss";
}

if (!empty($recentStatusFilter)) {
    $recentCountSql .= " AND loi.status = ?";
    array_push($recentCountParams, $recentStatusFilter);
    $recentCountTypes .= "s";
}

if (!empty($recentDateFilter)) {
    $recentCountSql .= " AND DATE(loi.updated_at) = ?";
    array_push($recentCountParams, $recentDateFilter);
    $recentCountTypes .= "s";
}

// Execute recent count query
$recentCountStmt = $conn->prepare($recentCountSql);
if (!empty($recentCountTypes)) {
    $recentCountStmt->bind_param($recentCountTypes, ...$recentCountParams);
}
$recentCountStmt->execute();
$recentCountResult = $recentCountStmt->get_result();
$recentTotalRecords = $recentCountResult->fetch_assoc()['total_count'];
$recentTotalPages = ceil($recentTotalRecords / $recentRecordsPerPage);

// Execute recent records query
$recentStmt = $conn->prepare($recentSql);
if (!empty($recentTypes)) {
    $recentStmt->bind_param($recentTypes, ...$recentParams);
}
$recentStmt->execute();
$recentResult = $recentStmt->get_result();

// Calculate statistics for dashboard cards (lab order items - individual tests)
$lab_stats = [];
try {
    // Get statistics based on lab_order_items for tests and lab_orders for cancelled orders
    $statsSql = "SELECT 
                    (SELECT COUNT(*) FROM lab_order_items loi 
                     LEFT JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id 
                     WHERE lo.order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as total,
                    (SELECT COUNT(*) FROM lab_order_items loi 
                     LEFT JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id 
                     WHERE loi.status = 'pending' AND lo.order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as pending,
                    (SELECT COUNT(*) FROM lab_order_items loi 
                     LEFT JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id 
                     WHERE loi.status = 'completed' AND lo.order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as completed,
                    (SELECT COUNT(*) FROM lab_orders 
                     WHERE overall_status = 'cancelled' AND order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as cancelled";
    
    $statsResult = $conn->query($statsSql);
    if ($statsResult) {
        $lab_stats = $statsResult->fetch_assoc();
        // Debug logging
        error_log("Lab stats query result: " . print_r($lab_stats, true));
    } else {
        error_log("Lab stats query failed: " . $conn->error);
        // Default values if query fails
        $lab_stats = [
            'total' => 0,
            'pending' => 0,
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
        'completed' => 0,
        'cancelled' => 0
    ];
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
            padding: 12px 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            font-size: 0.85em;
            vertical-align: middle;
        }

        .lab-table th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #03045e;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .lab-table tr:hover {
            background-color: #f5f5f5;
        }

        /* Improved action buttons container */
        .actions-container {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            justify-content: flex-start;
            align-items: center;
            min-width: 120px;
        }

        /* Enhanced action button styles */
        .action-btn {
            padding: 6px 8px;
            margin: 1px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.75em;
            font-weight: 500;
            transition: all 0.2s ease;
            white-space: nowrap;
            min-width: 32px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        .action-btn i {
            font-size: 0.9em;
        }

        /* Responsive action button sizing */
        @media (max-width: 1200px) {
            .action-btn {
                padding: 5px 6px;
                font-size: 0.7em;
                min-width: 28px;
                height: 26px;
            }
        }

        @media (max-width: 768px) {
            .actions-container {
                flex-direction: column;
                gap: 2px;
                min-width: 80px;
            }
            
            .action-btn {
                width: 100%;
                padding: 4px 6px;
                font-size: 0.65em;
                height: 24px;
            }
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

        .btn-view:hover {
            background-color: #0056b3;
        }

        .btn-upload {
            background-color: #28a745;
            color: white;
        }

        .btn-upload:hover {
            background-color: #1e7e34;
        }

        .btn-download {
            background-color: #17a2b8;
            color: white;
        }

        .btn-download:hover {
            background-color: #117a8b;
        }

        .btn-cancel {
            background-color: #dc3545;
            color: white;
        }

        .btn-cancel:hover {
            background-color: #c82333;
        }

        .btn-fix {
            background-color: #ffc107;
            color: #212529;
            font-weight: bold;
        }

        .btn-fix:hover {
            background-color: #e0a800;
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
            width: 100%;
            height: 8px;
            background-color: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 4px;
        }

        .progress-fill {
            height: 100%;
            background-color: #007bff;
            transition: width 0.3s;
        }

        .progress-info {
            min-width: 60px;
        }

        .progress-text {
            display: block;
            font-size: 0.75em;
            color: #666;
            margin-bottom: 2px;
        }

        .patient-info {
            max-width: 200px;
        }

        .text-muted {
            color: #6c757d !important;
        }

        .text-center {
            text-align: center;
        }

        /* Enhanced responsive design */
        @media (max-width: 1024px) {
            .lab-table th:nth-child(1),
            .lab-table td:nth-child(1) {
                width: 10%;
            }
            
            .lab-table th:nth-child(2),
            .lab-table td:nth-child(2) {
                width: 25%;
            }
            
            .lab-table th:nth-child(7),
            .lab-table td:nth-child(7) {
                width: 20%;
            }
        }

        @media (max-width: 768px) {
            .lab-table {
                font-size: 0.8em;
            }
            
            .lab-table th,
            .lab-table td {
                padding: 8px 4px;
            }
            
            .patient-info {
                max-width: 150px;
            }
            
            .progress-info {
                min-width: 50px;
            }
            
            .status-badge {
                font-size: 0.7em;
                padding: 2px 6px;
            }
        }

        @media (max-width: 480px) {
            .content-wrapper {
                padding: 10px 5px;
            }
            
            .lab-management-container {
                gap: 10px;
            }
            
            .lab-table {
                font-size: 0.75em;
            }
            
            .actions-container {
                min-width: 70px;
            }
            
            .action-btn {
                font-size: 0.6em;
                padding: 3px 5px;
                height: 22px;
                min-width: 22px;
            }
        }

        /* Pagination Styles */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding: 15px 0;
            border-top: 1px solid #ddd;
        }

        .pagination-info {
            font-size: 0.9em;
            color: #666;
        }

        .pagination {
            display: flex;
            list-style: none;
            margin: 0;
            padding: 0;
            gap: 5px;
        }

        .pagination li {
            display: inline;
        }

        .pagination a,
        .pagination span {
            display: inline-block;
            padding: 8px 12px;
            text-decoration: none;
            border: 1px solid #ddd;
            border-radius: 4px;
            color: #0077b6;
            background-color: white;
            transition: all 0.3s;
        }

        .pagination a:hover {
            background-color: #0077b6;
            color: white;
            border-color: #0077b6;
        }

        .pagination .current {
            background-color: #0077b6;
            color: white;
            border-color: #0077b6;
        }

        .pagination .disabled {
            color: #999;
            cursor: not-allowed;
            background-color: #f8f9fa;
        }

        .pagination .disabled:hover {
            background-color: #f8f9fa;
            color: #999;
            border-color: #ddd;
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
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(-30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 10px;
            max-width: 80%;
            max-height: 80%;
            overflow-y: auto;
            animation: slideIn 0.3s ease;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
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
            transition: color 0.3s;
        }

        .close-btn:hover {
            color: #000;
        }

        /* Confirmation Modal Styles */
        .confirmation-modal .modal-content {
            max-width: 400px;
            text-align: center;
        }

        .confirmation-modal .modal-icon {
            font-size: 3em;
            color: #dc3545;
            margin-bottom: 15px;
        }

        .confirmation-modal .modal-message {
            font-size: 1.1em;
            margin-bottom: 25px;
            color: #333;
            line-height: 1.5;
        }

        .confirmation-modal .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .modal-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9em;
            font-weight: 500;
            transition: all 0.3s;
            min-width: 80px;
        }

        .modal-btn-primary {
            background-color: #dc3545;
            color: white;
        }

        .modal-btn-primary:hover {
            background-color: #c82333;
        }

        .modal-btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .modal-btn-secondary:hover {
            background-color: #545b62;
        }

        /* Notification Modal Styles */
        .notification-modal .modal-content {
            max-width: 350px;
            text-align: center;
            padding: 30px 20px;
        }

        .notification-modal .modal-icon {
            font-size: 3.5em;
            margin-bottom: 15px;
        }

        .notification-modal .modal-icon.success {
            color: #28a745;
        }

        .notification-modal .modal-icon.error {
            color: #dc3545;
        }

        .notification-modal .modal-icon.info {
            color: #17a2b8;
        }

        .notification-modal .modal-message {
            font-size: 1.1em;
            margin-bottom: 20px;
            color: #333;
            line-height: 1.5;
        }

        .notification-modal .modal-btn {
            background-color: #0077b6;
            color: white;
        }

        .notification-modal .modal-btn:hover {
            background-color: #005577;
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

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
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

        /* PDF Viewer Modal Styles */
        .pdf-viewer-modal .modal-content {
            width: 90%;
            max-width: 1200px;
            height: 80vh;
            max-height: 800px;
        }

        .pdf-modal-content {
            display: flex;
            flex-direction: column;
        }

        .pdf-modal-content .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #ddd;
            flex-shrink: 0;
        }

        .pdf-toolbar {
            display: flex;
            gap: 8px;
        }

        .pdf-viewer-body {
            flex: 1;
            position: relative;
            min-height: 0;
        }

        .pdf-iframe {
            width: 100%;
            height: 100%;
            border: none;
            border-radius: 0 0 8px 8px;
        }

        .pdf-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            font-size: 1.1em;
            color: #666;
        }

        .pdf-loading i {
            font-size: 2em;
            margin-bottom: 10px;
            color: #0077b6;
        }

        .pdf-error {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            text-align: center;
            color: #dc3545;
        }

        .pdf-error i {
            font-size: 3em;
            margin-bottom: 15px;
        }

        .pdf-error p {
            margin-bottom: 20px;
            font-size: 1.1em;
        }

        /* Fullscreen styles */
        .pdf-viewer-modal.fullscreen {
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            z-index: 10000;
            background: rgba(0, 0, 0, 0.95);
        }

        .pdf-viewer-modal.fullscreen .modal-content {
            width: 100%;
            height: 100%;
            max-width: none;
            max-height: none;
            margin: 0;
            border-radius: 0;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .pdf-viewer-modal .modal-content {
                width: 95%;
                height: 70vh;
            }

            .pdf-toolbar {
                flex-direction: column;
                gap: 5px;
            }

            .pdf-toolbar .action-btn {
                padding: 6px 10px;
                font-size: 0.9em;
            }
        }

        /* Enhanced Order Details Modal Styles */
        .order-details-modal .modal-content {
            max-width: 1000px;
            width: 95%;
            max-height: 90vh;
            margin: 2.5% auto;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            border: 1px solid #e0e0e0;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }

        .order-details-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            background: linear-gradient(135deg, #03045e 0%, #0077b6 100%);
            color: white;
            border-radius: 12px 12px 0 0;
            margin: -20px -20px 0 -20px;
            border-bottom: none;
        }

        .modal-title-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .modal-icon {
            background: rgba(255, 255, 255, 0.2);
            padding: 12px;
            border-radius: 10px;
            font-size: 1.5em;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
        }

        .modal-title-text h3 {
            margin: 0;
            font-size: 1.4em;
            font-weight: 600;
            color: white;
        }

        .modal-subtitle {
            font-size: 0.9em;
            opacity: 0.9;
            margin-top: 2px;
            display: block;
            color: rgba(255, 255, 255, 0.8);
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn-refresh {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .btn-refresh:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(180deg);
        }

        .order-details-header .close-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            font-size: 1.2em;
        }

        .order-details-header .close-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }

        .order-details-body {
            padding: 0;
            max-height: calc(90vh - 100px);
            overflow-y: auto;
        }

        .order-content-container {
            min-height: 400px;
            position: relative;
        }

        .loading-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 400px;
            background: linear-gradient(45deg, #f8fafc 25%, transparent 25%), 
                        linear-gradient(-45deg, #f8fafc 25%, transparent 25%), 
                        linear-gradient(45deg, transparent 75%, #f8fafc 75%), 
                        linear-gradient(-45deg, transparent 75%, #f8fafc 75%);
            background-size: 20px 20px;
            background-position: 0 0, 0 10px, 10px -10px, -10px 0px;
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% { background-position: 0 0, 0 10px, 10px -10px, -10px 0px; }
            100% { background-position: 20px 20px, 20px 30px, 30px 10px, 10px 20px; }
        }

        .loading-spinner {
            background: white;
            padding: 20px;
            border-radius: 50%;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 15px;
        }

        .loading-spinner i {
            font-size: 2em;
            color: #0077b6;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .loading-text {
            color: #666;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
            text-align: center;
        }

        /* Enhanced scrollbar for order details */
        .order-details-body::-webkit-scrollbar {
            width: 8px;
        }

        .order-details-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .order-details-body::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #03045e, #0077b6);
            border-radius: 4px;
        }

        .order-details-body::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #02034a, #005577);
        }

        /* Responsive design for order details modal */
        @media (max-width: 768px) {
            .order-details-modal .modal-content {
                width: 98%;
                max-height: 95vh;
                margin: 2.5% auto;
                border-radius: 8px;
            }

            .order-details-header {
                padding: 15px 20px;
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .modal-title-section {
                flex-direction: column;
                gap: 10px;
            }

            .modal-icon {
                padding: 8px;
                font-size: 1.2em;
            }

            .modal-title-text h3 {
                font-size: 1.2em;
            }

            .modal-subtitle {
                font-size: 0.8em;
            }

            .modal-actions {
                gap: 8px;
            }

            .btn-refresh,
            .order-details-header .close-btn {
                padding: 8px;
                font-size: 1em;
            }
        }

        /* Dark mode support for order details */
        @media (prefers-color-scheme: dark) {
            .order-details-modal .modal-content {
                background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
                border-color: #404040;
                color: #e0e0e0;
            }

            .loading-container {
                background: linear-gradient(45deg, #2d2d2d 25%, transparent 25%), 
                            linear-gradient(-45deg, #2d2d2d 25%, transparent 25%), 
                            linear-gradient(45deg, transparent 75%, #2d2d2d 75%), 
                            linear-gradient(-45deg, transparent 75%, #2d2d2d 75%);
                background-size: 20px 20px;
            }

            .loading-spinner {
                background: #333;
            }

            .loading-text {
                color: #ccc;
            }
        }

        /* Enhanced Upload Result Modal Styles */
        .upload-result-modal .modal-content {
            max-width: 900px;
            width: 90%;
            max-height: 85vh;
            margin: 7.5% auto;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            border: 1px solid #e0e0e0;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }

        .upload-result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: white;
            border-radius: 12px 12px 0 0;
            margin: -20px -20px 0 -20px;
            border-bottom: none;
        }

        .upload-result-header .modal-icon {
            background: rgba(255, 255, 255, 0.2);
            padding: 12px;
            border-radius: 10px;
            font-size: 1.5em;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
        }

        .btn-help {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .btn-help:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }

        .upload-result-header .close-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            font-size: 1.2em;
        }

        .upload-result-header .close-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }

        .upload-result-body {
            padding: 0;
            max-height: calc(85vh - 100px);
            overflow-y: auto;
        }

        .upload-content-container {
            min-height: 400px;
            position: relative;
        }

        /* Enhanced Quick Upload Modal Styles */
        .quick-upload-modal .modal-content {
            max-width: 1100px;
            width: 95%;
            max-height: 90vh;
            margin: 2.5% auto;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            border: 1px solid #e0e0e0;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }

        .quick-upload-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
            color: white;
            border-radius: 12px 12px 0 0;
            margin: -20px -20px 0 -20px;
            border-bottom: none;
        }

        .quick-upload-header .modal-icon {
            background: rgba(255, 255, 255, 0.2);
            padding: 12px;
            border-radius: 10px;
            font-size: 1.5em;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(10px);
        }

        .btn-expand {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .btn-expand:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }

        .quick-upload-header .close-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            font-size: 1.2em;
        }

        .quick-upload-header .close-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1);
        }

        .quick-upload-body {
            padding: 0;
            max-height: calc(90vh - 100px);
            overflow-y: auto;
        }

        .quick-upload-content-container {
            min-height: 500px;
            position: relative;
        }

        /* Expanded state for quick upload modal */
        .quick-upload-modal.expanded .modal-content {
            max-width: 95vw;
            width: 95vw;
            max-height: 95vh;
            margin: 2.5vh auto;
        }

        .quick-upload-modal.expanded .quick-upload-body {
            max-height: calc(95vh - 100px);
        }

        .quick-upload-modal.expanded .quick-upload-content-container {
            min-height: calc(95vh - 150px);
        }

        /* Enhanced scrollbar for upload modals */
        .upload-result-body::-webkit-scrollbar,
        .quick-upload-body::-webkit-scrollbar {
            width: 8px;
        }

        .upload-result-body::-webkit-scrollbar-track,
        .quick-upload-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .upload-result-body::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #059669, #10b981);
            border-radius: 4px;
        }

        .upload-result-body::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #047857, #059669);
        }

        .quick-upload-body::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #f59e0b, #f97316);
            border-radius: 4px;
        }

        .quick-upload-body::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #d97706, #ea580c);
        }

        /* Responsive design for upload modals */
        @media (max-width: 768px) {
            .upload-result-modal .modal-content,
            .quick-upload-modal .modal-content {
                width: 98%;
                max-height: 95vh;
                margin: 2.5% auto;
                border-radius: 8px;
            }

            .upload-result-header,
            .quick-upload-header {
                padding: 15px 20px;
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .modal-title-section {
                flex-direction: column;
                gap: 10px;
            }

            .modal-icon {
                padding: 8px;
                font-size: 1.2em;
            }

            .modal-title-text h3 {
                font-size: 1.2em;
            }

            .modal-subtitle {
                font-size: 0.8em;
            }

            .modal-actions {
                gap: 8px;
            }

            .btn-help,
            .btn-expand,
            .upload-result-header .close-btn,
            .quick-upload-header .close-btn {
                padding: 8px;
                font-size: 1em;
            }

            .upload-content-container,
            .quick-upload-content-container {
                min-height: 300px;
            }

            .quick-upload-modal.expanded .modal-content {
                width: 100vw;
                max-width: 100vw;
                height: 100vh;
                max-height: 100vh;
                margin: 0;
                border-radius: 0;
            }
        }

        /* Dark mode support for upload modals */
        @media (prefers-color-scheme: dark) {
            .upload-result-modal .modal-content,
            .quick-upload-modal .modal-content {
                background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
                border-color: #404040;
                color: #e0e0e0;
            }
        }

        /* Upload help tooltip styles */
        .upload-help-tooltip {
            position: absolute;
            top: 100%;
            right: 0;
            background: rgba(0, 0, 0, 0.9);
            color: white;
            padding: 15px;
            border-radius: 8px;
            font-size: 0.9em;
            max-width: 300px;
            z-index: 10001;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            pointer-events: none;
        }

        .upload-help-tooltip.show {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        .upload-help-tooltip::before {
            content: '';
            position: absolute;
            top: -8px;
            right: 20px;
            width: 0;
            height: 0;
            border-left: 8px solid transparent;
            border-right: 8px solid transparent;
            border-bottom: 8px solid rgba(0, 0, 0, 0.9);
        }
    </style>
</head>

<body>
    <!-- Include Role-based Sidebar -->
    <?php 
    // Get current user's role for sidebar inclusion
    $role_id = $_SESSION['role_id'];
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
    $role = $roleMap[$role_id] ?? 'admin';
    include $root_path . '/includes/sidebar_' . $role . '.php'; 
    ?>

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
            // Laboratory statistics are calculated above in the main PHP section
            // Debug info is available with ?debug=1 parameter
            ?>

            <!-- Debug info - remove after testing -->
            <?php if (isset($_GET['debug'])): ?>
                <div style="background: #f0f0f0; padding: 10px; margin-bottom: 20px; border-radius: 5px;">
                    <strong>Debug Stats:</strong><br>
                    Total: <?= $lab_stats['total'] ?? 'NULL' ?><br>
                    Pending: <?= $lab_stats['pending'] ?? 'NULL' ?><br>
                    Completed: <?= $lab_stats['completed'] ?? 'NULL' ?><br>
                    Cancelled: <?= $lab_stats['cancelled'] ?? 'NULL' ?><br>
                    <small>Query executed at: <?= date('Y-m-d H:i:s') ?></small>
                </div>
            <?php endif; ?>

            <div class="stat-card total">
                <div class="stat-number"><?= number_format($lab_stats['total'] ?? 0) ?></div>
                <div class="stat-label">Total Tests (30 days)</div>
            </div>

            <div class="stat-card pending">
                <div class="stat-number"><?= number_format($lab_stats['pending'] ?? 0) ?></div>
                <div class="stat-label">Pending Tests</div>
            </div>

            <div class="stat-card completed">
                <div class="stat-number"><?= number_format($lab_stats['completed'] ?? 0) ?></div>
                <div class="stat-label">Completed Tests</div>
            </div>

            <div class="stat-card voided">
                <div class="stat-number"><?= number_format($lab_stats['cancelled'] ?? 0) ?></div>
                <div class="stat-label">Cancelled Orders</div>
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
                    <input type="text" class="filter-input" id="searchOrders" placeholder="Search by patient name, ID, or full name..." value="<?= htmlspecialchars($searchQuery) ?>">
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
                            <th style="width: 12%;">Order ID</th>
                            <th style="width: 22%;">Patient</th>
                            <th style="width: 14%;">Order Date</th>
                            <th style="width: 10%;">Tests</th>
                            <th style="width: 12%;">Progress</th>
                            <th style="width: 12%;">Status</th>
                            <th style="width: 18%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($order = $ordersResult->fetch_assoc()): ?>
                            <?php
                            $patientName = trim($order['first_name'] . ' ' . $order['middle_name'] . ' ' . $order['last_name']);
                            $progressPercent = $order['total_tests'] > 0 ? round(($order['completed_tests'] / $order['total_tests']) * 100) : 0;
                            $isInconsistent = ($order['completed_tests'] == $order['total_tests'] && $order['total_tests'] > 0 && $order['overall_status'] !== 'completed');
                            ?>
                            <tr data-lab-order-id="<?= $order['lab_order_id'] ?>" data-completed="<?= $order['completed_tests'] ?>" data-total="<?= $order['total_tests'] ?>" data-current-status="<?= $order['overall_status'] ?>">
                                <td><strong>#<?= $order['lab_order_id'] ?></strong></td>
                                <td>
                                    <div class="patient-info">
                                        <strong><?= htmlspecialchars($patientName) ?></strong><br>
                                        <small class="text-muted">ID: <?= htmlspecialchars($order['patient_id_display']) ?></small>
                                    </div>
                                </td>
                                <td><?= date('M d, Y', strtotime($order['order_date'])) ?></td>
                                <td class="text-center"><?= $order['total_tests'] ?></td>
                                <td>
                                    <div class="progress-info">
                                        <small class="progress-text"><?= $order['completed_tests'] ?>/<?= $order['total_tests'] ?></small>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?= $progressPercent ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $order['overall_status'] ?>">
                                        <?= ucfirst(str_replace('_', ' ', $order['overall_status'])) ?>
                                    </span>
                                    <?php if ($isInconsistent): ?>
                                        <br><button class="btn-fix action-btn" onclick="fixLabOrderStatus(<?= $order['lab_order_id'] ?>, this)" title="Fix Status">
                                            <i class="fas fa-wrench"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="actions-container">
                                        <button class="action-btn btn-view" onclick="viewOrderDetails(<?= $order['lab_order_id'] ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if ($canUploadResults && $order['overall_status'] !== 'completed' && $order['overall_status'] !== 'cancelled'): ?>
                                            <button class="action-btn btn-upload" onclick="showQuickUpload(<?= $order['lab_order_id'] ?>)" title="Quick Upload">
                                                <i class="fas fa-upload"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($canUploadResults && $order['overall_status'] !== 'completed' && $order['overall_status'] !== 'cancelled'): ?>
                                            <button class="action-btn btn-cancel" onclick="showCancelConfirmation(<?= $order['lab_order_id'] ?>)" title="Cancel Order">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination-info">
                        Showing <?= (($page - 1) * $recordsPerPage + 1) ?> to <?= min($page * $recordsPerPage, $totalRecords) ?> of <?= $totalRecords ?> entries
                    </div>
                    <ul class="pagination">
                        <!-- Previous button -->
                        <li>
                            <?php if ($page > 1): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            <?php else: ?>
                                <span class="disabled">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </span>
                            <?php endif; ?>
                        </li>

                        <?php
                        // Calculate page range to show
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        // Show first page if not in range
                        if ($startPage > 1): ?>
                            <li><a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a></li>
                            <?php if ($startPage > 2): ?>
                                <li><span class="disabled">...</span></li>
                            <?php endif;
                        endif;

                        // Show page range
                        for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li>
                                <?php if ($i == $page): ?>
                                    <span class="current"><?= $i ?></span>
                                <?php else: ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                                <?php endif; ?>
                            </li>
                        <?php endfor;

                        // Show last page if not in range
                        if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li><span class="disabled">...</span></li>
                            <?php endif; ?>
                            <li><a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>"><?= $totalPages ?></a></li>
                        <?php endif; ?>

                        <!-- Next button -->
                        <li>
                            <?php if ($page < $totalPages): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="disabled">
                                    Next <i class="fas fa-chevron-right"></i>
                                </span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Panel: Recent Lab Records -->
            <div class="lab-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <i class="fas fa-history"></i> Recent Lab Records
                    </div>
                </div>

                <!-- Search and Filter Controls for Recent Records -->
                <div class="search-filters">
                    <input type="text" class="filter-input" id="searchRecentRecords" placeholder="Search by patient name, test type, or ID..." value="<?= htmlspecialchars($recentSearchQuery) ?>">
                    <select class="filter-input" id="recentStatusFilter">
                        <option value="">All Statuses</option>
                        <option value="pending" <?= $recentStatusFilter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="in_progress" <?= $recentStatusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                        <option value="completed" <?= $recentStatusFilter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                    <input type="date" class="filter-input" id="recentDateFilter" value="<?= htmlspecialchars($recentDateFilter) ?>">
                </div>
                <div class="search-filters" style="justify-content: flex-end; margin-top: -10px;">
                    <button type="button" class="filter-btn search-btn" id="searchRecentBtn" onclick="applyRecentFilters()">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <button type="button" class="filter-btn clear-btn" id="clearRecentBtn" onclick="clearRecentFilters()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>

                <!-- Recent Records Table -->
                <table class="lab-table">
                    <thead>
                        <tr>
                            <th style="width: 25%;">Patient</th>
                            <th style="width: 25%;">Test Type</th>
                            <th style="width: 15%;">Status</th>
                            <th style="width: 15%;">Result Date</th>
                            <th style="width: 20%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentResult->num_rows > 0): ?>
                            <?php while ($record = $recentResult->fetch_assoc()): ?>
                                <?php
                                $patientName = trim($record['first_name'] . ' ' . $record['last_name']);
                                ?>
                                <tr>
                                    <td>
                                        <div class="patient-info">
                                            <strong><?= htmlspecialchars($patientName) ?></strong><br>
                                            <small class="text-muted">ID: <?= htmlspecialchars($record['patient_id_display']) ?></small>
                                        </div>
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
                                        <div class="actions-container">
                                            <?php if ($record['result_file']): ?>
                                                <button class="action-btn btn-view" onclick="viewResult(<?= $record['lab_order_item_id'] ?>)" title="View Result">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button class="action-btn btn-download" onclick="downloadResult(<?= $record['lab_order_item_id'] ?>)" title="Download Result">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                            <?php elseif ($canUploadResults && $record['status'] !== 'completed'): ?>
                                                <button class="action-btn btn-upload" onclick="uploadResult(<?= $record['lab_order_item_id'] ?>)" title="Upload Result">
                                                    <i class="fas fa-upload"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 30px; color: #666;">
                                    <i class="fas fa-flask" style="font-size: 2em; margin-bottom: 10px; display: block;"></i>
                                    No lab records found
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <!-- Recent Records Pagination -->
                <?php if ($recentTotalPages > 1): ?>
                <div class="pagination-container">
                    <div class="pagination-info">
                        Showing <?= (($recentPage - 1) * $recentRecordsPerPage + 1) ?> to <?= min($recentPage * $recentRecordsPerPage, $recentTotalRecords) ?> of <?= $recentTotalRecords ?> records
                    </div>
                    <ul class="pagination">
                        <!-- Previous button -->
                        <li>
                            <?php if ($recentPage > 1): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['recent_page' => $recentPage - 1])) ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            <?php else: ?>
                                <span class="disabled">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </span>
                            <?php endif; ?>
                        </li>

                        <?php
                        // Calculate page range to show
                        $recentStartPage = max(1, $recentPage - 2);
                        $recentEndPage = min($recentTotalPages, $recentPage + 2);
                        
                        // Show first page if not in range
                        if ($recentStartPage > 1): ?>
                            <li><a href="?<?= http_build_query(array_merge($_GET, ['recent_page' => 1])) ?>">1</a></li>
                            <?php if ($recentStartPage > 2): ?>
                                <li><span class="disabled">...</span></li>
                            <?php endif;
                        endif;

                        // Show page range
                        for ($i = $recentStartPage; $i <= $recentEndPage; $i++): ?>
                            <li>
                                <?php if ($i == $recentPage): ?>
                                    <span class="current"><?= $i ?></span>
                                <?php else: ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['recent_page' => $i])) ?>"><?= $i ?></a>
                                <?php endif; ?>
                            </li>
                        <?php endfor;

                        // Show last page if not in range
                        if ($recentEndPage < $recentTotalPages): ?>
                            <?php if ($recentEndPage < $recentTotalPages - 1): ?>
                                <li><span class="disabled">...</span></li>
                            <?php endif; ?>
                            <li><a href="?<?= http_build_query(array_merge($_GET, ['recent_page' => $recentTotalPages])) ?>"><?= $recentTotalPages ?></a></li>
                        <?php endif; ?>

                        <!-- Next button -->
                        <li>
                            <?php if ($recentPage < $recentTotalPages): ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['recent_page' => $recentPage + 1])) ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="disabled">
                                    Next <i class="fas fa-chevron-right"></i>
                                </span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>


    <!-- Order Details Modal -->
    <div id="orderDetailsModal" class="modal order-details-modal">
        <div class="modal-content order-details-content">
            <div class="modal-header order-details-header">
                <div class="modal-title-section">
                    <div class="modal-icon">
                        <i class="fas fa-flask"></i>
                    </div>
                    <div class="modal-title-text">
                        <h3 id="modalTitle">Lab Order Details</h3>
                        <span class="modal-subtitle">Comprehensive order information and test results</span>
                    </div>
                </div>
                <div class="modal-actions">
                    <button class="action-btn btn-refresh" onclick="refreshOrderDetails()" title="Refresh Details">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <button class="close-btn" onclick="closeModal('orderDetailsModal')" title="Close Modal">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="modal-body order-details-body">
                <div id="modalBody" class="order-content-container">
                    <div class="loading-container">
                        <div class="loading-spinner">
                            <i class="fas fa-spinner fa-spin"></i>
                        </div>
                        <p class="loading-text">Loading order details...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Result Modal -->
    <div id="uploadResultModal" class="modal upload-result-modal">
        <div class="modal-content upload-result-content">
            <div class="modal-header upload-result-header">
                <div class="modal-title-section">
                    <div class="modal-icon">
                        <i class="fas fa-upload"></i>
                    </div>
                    <div class="modal-title-text">
                        <h3 id="uploadModalTitle">Upload Lab Result</h3>
                        <span class="modal-subtitle">Upload test results and manage lab data</span>
                    </div>
                </div>
                <div class="modal-actions">
                    <button class="action-btn btn-help" onclick="showUploadHelp()" title="Upload Guidelines">
                        <i class="fas fa-question-circle"></i>
                    </button>
                    <button class="close-btn" onclick="closeModal('uploadResultModal')" title="Close Modal">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="modal-body upload-result-body">
                <div id="uploadResultBody" class="upload-content-container">
                    <div class="loading-container">
                        <div class="loading-spinner">
                            <i class="fas fa-spinner fa-spin"></i>
                        </div>
                        <p class="loading-text">Loading upload interface...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Upload Modal -->
    <div id="quickUploadModal" class="modal quick-upload-modal">
        <div class="modal-content quick-upload-content">
            <div class="modal-header quick-upload-header">
                <div class="modal-title-section">
                    <div class="modal-icon">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <div class="modal-title-text">
                        <h3 id="quickUploadModalTitle">Quick Upload Lab Results</h3>
                        <span class="modal-subtitle">Upload multiple test results efficiently</span>
                    </div>
                </div>
                <div class="modal-actions">
                    <button class="action-btn btn-expand" onclick="toggleQuickUploadExpanded()" title="Expand View">
                        <i class="fas fa-expand-alt"></i>
                    </button>
                    <button class="close-btn" onclick="closeModal('quickUploadModal')" title="Close Modal">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="modal-body quick-upload-body">
                <div id="quickUploadBody" class="quick-upload-content-container">
                    <div class="loading-container">
                        <div class="loading-spinner">
                            <i class="fas fa-spinner fa-spin"></i>
                        </div>
                        <p class="loading-text">Loading test items...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- PDF Viewer Modal -->
    <div id="pdfViewerModal" class="modal pdf-viewer-modal">
        <div class="modal-content pdf-modal-content">
            <div class="modal-header">
                <h3 id="pdfViewerTitle">Lab Result Viewer</h3>
                <div class="pdf-toolbar">
                    <button class="action-btn btn-download" onclick="downloadCurrentPdf()" title="Download PDF">
                        <i class="fas fa-download"></i> Download
                    </button>
                    <button class="action-btn btn-fullscreen" onclick="togglePdfFullscreen()" title="Toggle Fullscreen">
                        <i class="fas fa-expand"></i> Fullscreen
                    </button>
                </div>
                <button class="close-btn" onclick="closeModal('pdfViewerModal')">&times;</button>
            </div>
            <div class="pdf-viewer-body">
                <div id="pdfLoadingSpinner" class="pdf-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading PDF...</p>
                </div>
                <iframe id="pdfViewerFrame" class="pdf-iframe" style="display: none;"></iframe>
                <div id="pdfErrorMessage" class="pdf-error" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Unable to load PDF. The file may be corrupted or not a valid PDF.</p>
                    <button class="action-btn btn-download" onclick="downloadCurrentPdf()">
                        <i class="fas fa-download"></i> Try Download Instead
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmationModal" class="modal confirmation-modal">
        <div class="modal-content">
            <div class="modal-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div id="confirmationMessage" class="modal-message">
                Are you sure you want to perform this action?
            </div>
            <div class="modal-buttons">
                <button id="confirmBtn" class="modal-btn modal-btn-primary">Confirm</button>
                <button id="cancelBtn" class="modal-btn modal-btn-secondary" onclick="closeModal('confirmationModal')">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Notification Modal -->
    <div id="notificationModal" class="modal notification-modal">
        <div class="modal-content">
            <div id="notificationIcon" class="modal-icon success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div id="notificationMessage" class="modal-message">
                Operation completed successfully!
            </div>
            <button class="modal-btn" onclick="closeModal('notificationModal')">OK</button>
        </div>
    </div>

    <script>
        // Laboratory Management JavaScript Functions

        // Search and filter functionality
        document.getElementById('statusFilter').addEventListener('change', applyFilters);
        document.getElementById('dateFilter').addEventListener('change', applyFilters);

        // Recent records search and filter functionality
        document.getElementById('recentStatusFilter').addEventListener('change', applyRecentFilters);
        document.getElementById('recentDateFilter').addEventListener('change', applyRecentFilters);

        // Allow Enter key to trigger search for both tables
        document.getElementById('searchOrders').addEventListener('keypress', function(event) {
            if (event.key === 'Enter') {
                applyFilters();
            }
        });

        document.getElementById('searchRecentRecords').addEventListener('keypress', function(event) {
            if (event.key === 'Enter') {
                applyRecentFilters();
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
            // Reset to page 1 when applying new filters
            params.set('page', '1');

            window.location.href = '?' + params.toString();
        }

        function clearFilters() {
            document.getElementById('searchOrders').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('dateFilter').value = '';

            // Redirect to page without any filters
            window.location.href = window.location.pathname;
        }

        // Recent records search and filter functions
        function applyRecentFilters() {
            const search = document.getElementById('searchRecentRecords').value.trim();
            const status = document.getElementById('recentStatusFilter').value;
            const date = document.getElementById('recentDateFilter').value;

            const params = new URLSearchParams(window.location.search);
            
            // Clear old recent filters and set new ones
            params.delete('recent_search');
            params.delete('recent_status');
            params.delete('recent_date');
            params.delete('recent_page');
            
            if (search) params.set('recent_search', search);
            if (status) params.set('recent_status', status);
            if (date) params.set('recent_date', date);
            // Reset to page 1 when applying new filters
            params.set('recent_page', '1');

            window.location.href = '?' + params.toString();
        }

        function clearRecentFilters() {
            const params = new URLSearchParams(window.location.search);
            
            // Remove recent filter parameters
            params.delete('recent_search');
            params.delete('recent_status');
            params.delete('recent_date');
            params.delete('recent_page');

            // Redirect with remaining parameters
            window.location.href = window.location.search ? ('?' + params.toString()) : window.location.pathname;
        }

        // Automatic Status Update Functions
        function checkAndUpdateLabOrderStatuses() {
            console.log('=== Starting Lab Order Status Check ===');
            
            // Get all lab order rows with data attributes
            const labOrderRows = document.querySelectorAll('tbody tr[data-lab-order-id]');
            console.log(`Found ${labOrderRows.length} lab order rows`);
            
            labOrderRows.forEach((row, index) => {
                const labOrderId = row.getAttribute('data-lab-order-id');
                const completed = parseInt(row.getAttribute('data-completed'));
                const total = parseInt(row.getAttribute('data-total'));
                const currentStatus = row.getAttribute('data-current-status');
                
                console.log(`Row ${index + 1}:`, {
                    labOrderId,
                    completed,
                    total,
                    currentStatus,
                    dataAttributes: {
                        'data-lab-order-id': row.getAttribute('data-lab-order-id'),
                        'data-completed': row.getAttribute('data-completed'),
                        'data-total': row.getAttribute('data-total'),
                        'data-current-status': row.getAttribute('data-current-status')
                    }
                });
                
                if (!labOrderId || isNaN(completed) || isNaN(total)) {
                    console.log(`Skipping row ${index + 1} - missing or invalid data`);
                    return;
                }
                
                let shouldUpdateTo = null;
                
                // Core logic: Only update to completed when ALL items are completed
                if (completed === total && total > 0 && currentStatus !== 'completed') {
                    shouldUpdateTo = 'completed';
                    console.log(`Row ${index + 1}: Should update to completed (${completed}/${total} completed, current: ${currentStatus})`);
                }
                // If some completed but not all, update to in_progress only from pending
                else if (completed > 0 && completed < total && currentStatus === 'pending') {
                    shouldUpdateTo = 'in_progress';
                    console.log(`Row ${index + 1}: Should update to in_progress (${completed}/${total} completed, current: ${currentStatus})`);
                }
                // If no items completed, should remain pending (no update needed)
                else {
                    console.log(`Row ${index + 1}: No update needed (${completed}/${total} completed, current: ${currentStatus})`);
                }
                
                // Update status if needed
                if (shouldUpdateTo) {
                    console.log(`Auto-updating lab order ${labOrderId} from ${currentStatus} to ${shouldUpdateTo}`);
                    updateLabOrderStatusAutomatically(labOrderId, shouldUpdateTo, row);
                } else {
                    console.log(`No update required for lab order ${labOrderId}`);
                }
            });
            
            console.log('=== Lab Order Status Check Complete ===');
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
                    showNotificationModal('success', `Lab Order #${labOrderId} status automatically updated to ${newStatus.replace('_', ' ')}`);
                    
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
            
            // Check for expired orders that need to be cancelled
            setTimeout(checkAndCancelExpiredOrders, 2000);
        });

        // Re-run status check after modal operations (uploads, etc.)
        function refreshStatusChecks() {
            setTimeout(checkAndUpdateLabOrderStatuses, 500);
        }

        // Manual fix function for specific lab orders
        function fixLabOrderStatus(labOrderId, buttonElement) {
            console.log(`Manual fix triggered for lab order ${labOrderId}`);
            
            // Disable button and show loading
            buttonElement.disabled = true;
            buttonElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Fixing...';
            
            // Get the row element
            const rowElement = buttonElement.closest('tr');
            
            fetch('api/update_lab_order_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    lab_order_id: labOrderId,
                    overall_status: 'completed',
                    remarks: 'Manually fixed - all items completed',
                    auto_update: true
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update the status badge in the UI immediately
                    const statusBadge = rowElement.querySelector('.status-badge');
                    if (statusBadge) {
                        statusBadge.className = 'status-badge status-completed';
                        statusBadge.textContent = 'Completed';
                    }
                    
                    // Update data attribute
                    rowElement.setAttribute('data-current-status', 'completed');
                    
                    // Remove the fix button since it's no longer needed
                    buttonElement.remove();
                    
                    // Show success notification
                    showNotificationModal('success', `Lab Order #${labOrderId} status fixed successfully!`);
                    
                    // Update statistics if needed
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                    
                } else {
                    // Re-enable button on error
                    buttonElement.disabled = false;
                    buttonElement.innerHTML = '<i class="fas fa-wrench"></i> Fix';
                    showNotificationModal('error', 'Failed to fix lab order status: ' + data.message);
                }
            })
            .catch(error => {
                // Re-enable button on error
                buttonElement.disabled = false;
                buttonElement.innerHTML = '<i class="fas fa-wrench"></i> Fix';
                showNotificationModal('error', 'Error fixing lab order status: ' + error.message);
                console.error('Error fixing lab order status:', error);
            });
        }

        // Manual cancellation function with custom modal
        function showCancelConfirmation(labOrderId) {
            showConfirmationModal(
                'Cancel Lab Order',
                'Are you sure you want to cancel this lab order? This action cannot be undone and will cancel all associated tests.',
                () => cancelLabOrder(labOrderId)
            );
        }

        function cancelLabOrder(labOrderId) {
            // Get the row element for UI updates
            const rowElement = document.querySelector(`tr[data-lab-order-id="${labOrderId}"]`);
            
            fetch('api/cancel_lab_order.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    lab_order_id: labOrderId,
                    cancellation_reason: 'Manual cancellation by user',
                    cancelled_by: 'employee'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update the status badge in the UI immediately
                    const statusBadge = rowElement.querySelector('.status-badge');
                    if (statusBadge) {
                        statusBadge.className = 'status-badge status-cancelled';
                        statusBadge.textContent = 'Cancelled';
                    }
                    
                    // Update data attribute
                    rowElement.setAttribute('data-current-status', 'cancelled');
                    
                    // Hide upload and cancel buttons
                    const uploadBtn = rowElement.querySelector('.btn-upload');
                    const cancelBtn = rowElement.querySelector('.btn-cancel');
                    if (uploadBtn) uploadBtn.style.display = 'none';
                    if (cancelBtn) cancelBtn.style.display = 'none';
                    
                    // Show success notification
                    showNotificationModal('success', `Lab Order #${labOrderId} has been cancelled successfully!`);
                    
                    // Refresh page after delay to update statistics
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                    
                } else {
                    showNotificationModal('error', 'Failed to cancel lab order: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error cancelling lab order:', error);
                showNotificationModal('error', 'Error cancelling lab order: ' + error.message);
            });
        }

        // Automatic cancellation check function
        function checkAndCancelExpiredOrders() {
            console.log('=== Starting Expired Orders Check ===');
            
            fetch('api/auto_cancel_expired_orders.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.cancelled_count > 0) {
                    console.log(`Auto-cancelled ${data.cancelled_count} expired lab orders`);
                    showNotificationModal('info', `${data.cancelled_count} expired lab orders have been automatically cancelled.`);
                    
                    // Refresh page to show updated data
                    setTimeout(() => {
                        window.location.reload();
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Error checking expired orders:', error);
            });
        }

        // Modal utility functions
        function showConfirmationModal(title, message, onConfirm) {
            document.getElementById('confirmationMessage').innerHTML = message;
            
            // Remove existing event listeners
            const confirmBtn = document.getElementById('confirmBtn');
            const newConfirmBtn = confirmBtn.cloneNode(true);
            confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
            
            // Add new event listener
            newConfirmBtn.addEventListener('click', function() {
                closeModal('confirmationModal');
                if (onConfirm) onConfirm();
            });
            
            document.getElementById('confirmationModal').style.display = 'block';
        }

        function showNotificationModal(type, message) {
            const modal = document.getElementById('notificationModal');
            const icon = document.getElementById('notificationIcon');
            const messageEl = document.getElementById('notificationMessage');
            
            // Set icon based on type
            icon.className = `modal-icon ${type}`;
            switch(type) {
                case 'success':
                    icon.innerHTML = '<i class="fas fa-check-circle"></i>';
                    break;
                case 'error':
                    icon.innerHTML = '<i class="fas fa-exclamation-circle"></i>';
                    break;
                case 'info':
                    icon.innerHTML = '<i class="fas fa-info-circle"></i>';
                    break;
                case 'warning':
                    icon.innerHTML = '<i class="fas fa-exclamation-triangle"></i>';
                    break;
                default:
                    icon.innerHTML = '<i class="fas fa-info-circle"></i>';
            }
            
            messageEl.innerHTML = message;
            modal.style.display = 'block';
            
            // Auto-close after 5 seconds for success messages
            if (type === 'success') {
                setTimeout(() => {
                    if (modal.style.display === 'block') {
                        closeModal('notificationModal');
                    }
                }, 5000);
            }
        }

        // Modal functions
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            
            // Special handling for PDF viewer modal
            if (modalId === 'pdfViewerModal') {
                // Reset PDF viewer state
                const iframe = document.getElementById('pdfViewerFrame');
                iframe.src = 'about:blank';
                document.getElementById('pdfLoadingSpinner').style.display = 'flex';
                document.getElementById('pdfViewerFrame').style.display = 'none';
                document.getElementById('pdfErrorMessage').style.display = 'none';
                
                // Remove fullscreen if active
                const modal = document.getElementById('pdfViewerModal');
                if (modal.classList.contains('fullscreen')) {
                    modal.classList.remove('fullscreen');
                    const fullscreenBtn = document.querySelector('.btn-fullscreen i');
                    fullscreenBtn.className = 'fas fa-expand';
                    document.querySelector('.btn-fullscreen').title = 'Enter Fullscreen';
                }
                
                currentPdfItemId = null;
            }
            
            // Special handling for upload result modal
            if (modalId === 'uploadResultModal') {
                // Reset upload modal state
                document.getElementById('uploadResultBody').innerHTML = `
                    <div class="loading-container">
                        <div class="loading-spinner">
                            <i class="fas fa-spinner fa-spin"></i>
                        </div>
                        <p class="loading-text">Loading upload interface...</p>
                    </div>
                `;
                
                // Remove any help tooltips
                const tooltip = document.querySelector('.upload-help-tooltip');
                if (tooltip) {
                    tooltip.remove();
                }
            }
            
            // Special handling for quick upload modal
            if (modalId === 'quickUploadModal') {
                // Reset quick upload modal state
                document.getElementById('quickUploadBody').innerHTML = `
                    <div class="loading-container">
                        <div class="loading-spinner">
                            <i class="fas fa-spinner fa-spin"></i>
                        </div>
                        <p class="loading-text">Loading test items...</p>
                    </div>
                `;
                
                // Reset expanded state
                const modal = document.getElementById('quickUploadModal');
                if (modal.classList.contains('expanded')) {
                    modal.classList.remove('expanded');
                    const expandBtn = document.querySelector('.btn-expand i');
                    if (expandBtn) {
                        expandBtn.className = 'fas fa-expand-alt';
                        document.querySelector('.btn-expand').title = 'Expand View';
                    }
                }
            }
            
            // Special handling for order details modal
            if (modalId === 'orderDetailsModal') {
                currentOrderId = null;
            }
        }

        // Global variable to store current order ID for refresh functionality
        let currentOrderId = null;

        function viewOrderDetails(labOrderId) {
            currentOrderId = labOrderId;
            
            // Update modal title with order ID
            document.getElementById('modalTitle').innerHTML = `Lab Order #${labOrderId} Details`;
            
            // Show modal with enhanced loading state
            document.getElementById('orderDetailsModal').style.display = 'block';
            
            // Show the enhanced loading container
            document.getElementById('modalBody').innerHTML = `
                <div class="loading-container">
                    <div class="loading-spinner">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                    <p class="loading-text">Loading comprehensive order details...</p>
                </div>
            `;

            // Fetch order details with error handling
            fetch(`api/get_lab_order_details.php?lab_order_id=${labOrderId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.text();
                })
                .then(html => {
                    // Add a small delay for smooth transition
                    setTimeout(() => {
                        document.getElementById('modalBody').innerHTML = html;
                        
                        // Add fade-in animation to content
                        const content = document.getElementById('modalBody');
                        content.style.opacity = '0';
                        content.style.transition = 'opacity 0.3s ease';
                        setTimeout(() => {
                            content.style.opacity = '1';
                        }, 50);
                    }, 300);
                })
                .catch(error => {
                    console.error('Error loading order details:', error);
                    document.getElementById('modalBody').innerHTML = `
                        <div class="alert alert-error" style="margin: 20px; text-align: center;">
                            <i class="fas fa-exclamation-triangle" style="margin-right: 10px;"></i>
                            <strong>Error loading order details</strong><br>
                            <small>${error.message || 'Please try again later'}</small>
                            <div style="margin-top: 15px;">
                                <button class="btn-primary" onclick="refreshOrderDetails()" style="margin-right: 10px;">
                                    <i class="fas fa-sync-alt"></i> Retry
                                </button>
                                <button class="btn-secondary" onclick="closeModal('orderDetailsModal')">
                                    <i class="fas fa-times"></i> Close
                                </button>
                            </div>
                        </div>
                    `;
                });
        }

        function refreshOrderDetails() {
            if (currentOrderId) {
                // Add rotation animation to refresh button
                const refreshBtn = document.querySelector('.btn-refresh i');
                if (refreshBtn) {
                    refreshBtn.style.animation = 'spin 1s linear infinite';
                }
                
                // Refresh the details
                viewOrderDetails(currentOrderId);
                
                // Reset animation after delay
                setTimeout(() => {
                    if (refreshBtn) {
                        refreshBtn.style.animation = '';
                    }
                }, 1000);
            }
        }

        // Upload modal enhancement functions
        function showUploadHelp() {
            // Remove existing tooltip
            const existingTooltip = document.querySelector('.upload-help-tooltip');
            if (existingTooltip) {
                existingTooltip.remove();
                return;
            }

            // Create and show help tooltip
            const tooltip = document.createElement('div');
            tooltip.className = 'upload-help-tooltip';
            tooltip.innerHTML = `
                <h4 style="margin: 0 0 10px 0; color: #10b981;">Upload Guidelines</h4>
                <ul style="margin: 0; padding-left: 15px; list-style-type: disc;">
                    <li>Supported formats: PDF, JPG, PNG</li>
                    <li>Maximum file size: 10MB</li>
                    <li>Ensure file is clear and readable</li>
                    <li>Include patient name and test date</li>
                    <li>Click anywhere to close this help</li>
                </ul>
            `;

            // Position relative to help button
            const helpBtn = document.querySelector('.btn-help');
            const modalActions = helpBtn.parentElement;
            modalActions.style.position = 'relative';
            modalActions.appendChild(tooltip);

            // Show tooltip with animation
            setTimeout(() => {
                tooltip.classList.add('show');
            }, 50);

            // Auto-hide after 10 seconds
            setTimeout(() => {
                if (tooltip.parentElement) {
                    tooltip.classList.remove('show');
                    setTimeout(() => {
                        if (tooltip.parentElement) {
                            tooltip.remove();
                        }
                    }, 300);
                }
            }, 10000);

            // Hide on click anywhere
            document.addEventListener('click', function hideTooltip(e) {
                if (!tooltip.contains(e.target) && !helpBtn.contains(e.target)) {
                    tooltip.classList.remove('show');
                    setTimeout(() => {
                        if (tooltip.parentElement) {
                            tooltip.remove();
                        }
                    }, 300);
                    document.removeEventListener('click', hideTooltip);
                }
            });
        }

        function toggleQuickUploadExpanded() {
            const modal = document.getElementById('quickUploadModal');
            const expandBtn = document.querySelector('.btn-expand i');
            
            if (modal.classList.contains('expanded')) {
                modal.classList.remove('expanded');
                expandBtn.className = 'fas fa-expand-alt';
                document.querySelector('.btn-expand').title = 'Expand View';
            } else {
                modal.classList.add('expanded');
                expandBtn.className = 'fas fa-compress-alt';
                document.querySelector('.btn-expand').title = 'Collapse View';
            }
        }

        function showQuickUpload(labOrderId) {
            console.log('Quick upload called for lab order:', labOrderId);
            
            // Update modal title
            document.getElementById('quickUploadModalTitle').innerHTML = `Quick Upload - Order #${labOrderId}`;
            
            // Show modal with enhanced loading state
            document.getElementById('quickUploadModal').style.display = 'block';
            
            // Show the enhanced loading container
            document.getElementById('quickUploadBody').innerHTML = `
                <div class="loading-container">
                    <div class="loading-spinner">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                    <p class="loading-text">Loading test items for quick upload...</p>
                </div>
            `;

            fetch(`api/get_lab_order_items.php?lab_order_id=${labOrderId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.text();
                })
                .then(html => {
                    // Add a small delay for smooth transition
                    setTimeout(() => {
                        document.getElementById('quickUploadBody').innerHTML = html;
                        
                        // Add fade-in animation to content
                        const content = document.getElementById('quickUploadBody');
                        content.style.opacity = '0';
                        content.style.transition = 'opacity 0.3s ease';
                        setTimeout(() => {
                            content.style.opacity = '1';
                        }, 50);
                    }, 300);
                })
                .catch(error => {
                    console.error('Error loading lab items:', error);
                    document.getElementById('quickUploadBody').innerHTML = `
                        <div class="alert alert-error" style="margin: 20px; text-align: center;">
                            <i class="fas fa-exclamation-triangle" style="margin-right: 10px;"></i>
                            <strong>Error loading test items</strong><br>
                            <small>${error.message || 'Please try again later'}</small>
                            <div style="margin-top: 15px;">
                                <button class="btn-primary" onclick="showQuickUpload(${labOrderId})" style="margin-right: 10px;">
                                    <i class="fas fa-sync-alt"></i> Retry
                                </button>
                                <button class="btn-secondary" onclick="closeModal('quickUploadModal')">
                                    <i class="fas fa-times"></i> Close
                                </button>
                            </div>
                        </div>
                    `;
                });
        }

        function uploadResult(labOrderItemId) {
            <?php if ($canUploadResults): ?>
                console.log('Upload function called with item ID:', labOrderItemId);
                
                // Update modal title
                document.getElementById('uploadModalTitle').innerHTML = `Upload Result - Item #${labOrderItemId}`;
                
                // Close any open order details modal first
                closeModal('orderDetailsModal');
                
                // Show modal with enhanced loading state
                document.getElementById('uploadResultModal').style.display = 'block';
                
                // Show the enhanced loading container
                document.getElementById('uploadResultBody').innerHTML = `
                    <div class="loading-container">
                        <div class="loading-spinner">
                            <i class="fas fa-spinner fa-spin"></i>
                        </div>
                        <p class="loading-text">Loading upload interface...</p>
                    </div>
                `;
                
                // Add a small delay for smooth transition
                setTimeout(() => {
                    // Use iframe approach for better isolation
                    const iframe = document.createElement('iframe');
                    iframe.src = `upload_lab_result_modal.php?item_id=${labOrderItemId}`;
                    iframe.style.cssText = `
                        width: 100%; 
                        height: 600px; 
                        border: none; 
                        border-radius: 8px;
                        opacity: 0;
                        transition: opacity 0.3s ease;
                    `;
                    
                    iframe.onload = function() {
                        // Fade in the iframe when loaded
                        setTimeout(() => {
                            iframe.style.opacity = '1';
                        }, 100);
                    };
                    
                    const uploadBody = document.getElementById('uploadResultBody');
                    uploadBody.innerHTML = ''; // Clear existing content
                    uploadBody.appendChild(iframe);
                }, 300);
                
            <?php else: ?>
                showNotificationModal('error', 'You are not authorized to upload lab results.');
            <?php endif; ?>
        }

        function downloadResult(itemId) {
            window.open(`api/download_lab_result.php?item_id=${itemId}`, '_blank');
        }

        // PDF Viewer Functions
        let currentPdfItemId = null;

        function viewResult(itemId) {
            currentPdfItemId = itemId;
            
            // Show modal and loading state
            document.getElementById('pdfViewerModal').style.display = 'block';
            document.getElementById('pdfLoadingSpinner').style.display = 'flex';
            document.getElementById('pdfViewerFrame').style.display = 'none';
            document.getElementById('pdfErrorMessage').style.display = 'none';
            
            // Update modal title
            document.getElementById('pdfViewerTitle').textContent = `Lab Result Viewer - Item #${itemId}`;
            
            // Set iframe source to the view endpoint
            const iframe = document.getElementById('pdfViewerFrame');
            iframe.onload = function() {
                // Hide loading spinner and show iframe when loaded
                document.getElementById('pdfLoadingSpinner').style.display = 'none';
                document.getElementById('pdfViewerFrame').style.display = 'block';
            };
            
            iframe.onerror = function() {
                // Show error message if loading fails
                document.getElementById('pdfLoadingSpinner').style.display = 'none';
                document.getElementById('pdfErrorMessage').style.display = 'flex';
            };
            
            // Add timestamp to prevent caching issues
            iframe.src = `api/view_lab_result.php?item_id=${itemId}&t=${Date.now()}`;
            
            // Set a timeout to show error if PDF doesn't load within 10 seconds
            setTimeout(() => {
                if (document.getElementById('pdfLoadingSpinner').style.display !== 'none') {
                    document.getElementById('pdfLoadingSpinner').style.display = 'none';
                    document.getElementById('pdfErrorMessage').style.display = 'flex';
                }
            }, 10000);
        }

        function downloadCurrentPdf() {
            if (currentPdfItemId) {
                downloadResult(currentPdfItemId);
            }
        }

        function togglePdfFullscreen() {
            const modal = document.getElementById('pdfViewerModal');
            const fullscreenBtn = document.querySelector('.btn-fullscreen i');
            
            if (modal.classList.contains('fullscreen')) {
                modal.classList.remove('fullscreen');
                fullscreenBtn.className = 'fas fa-expand';
                document.querySelector('.btn-fullscreen').title = 'Enter Fullscreen';
            } else {
                modal.classList.add('fullscreen');
                fullscreenBtn.className = 'fas fa-compress';
                document.querySelector('.btn-fullscreen').title = 'Exit Fullscreen';
            }
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const modals = ['orderDetailsModal', 'uploadResultModal', 'quickUploadModal', 'pdfViewerModal', 'confirmationModal', 'notificationModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    closeModal(modalId);
                }
            });
        });

        // Close modals with ESC key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                // Close any open modals
                const modals = ['orderDetailsModal', 'uploadResultModal', 'quickUploadModal', 'pdfViewerModal', 'confirmationModal', 'notificationModal'];
                modals.forEach(modalId => {
                    const modal = document.getElementById(modalId);
                    if (modal && modal.style.display === 'block') {
                        // Special handling for PDF fullscreen
                        if (modalId === 'pdfViewerModal' && modal.classList.contains('fullscreen')) {
                            togglePdfFullscreen();
                        } else if (modalId === 'quickUploadModal' && modal.classList.contains('expanded')) {
                            // First ESC: collapse expanded view, second ESC: close modal
                            toggleQuickUploadExpanded();
                        } else {
                            closeModal(modalId);
                        }
                    }
                });
                
                // Also close any help tooltips
                const tooltip = document.querySelector('.upload-help-tooltip');
                if (tooltip) {
                    tooltip.classList.remove('show');
                    setTimeout(() => {
                        if (tooltip.parentElement) {
                            tooltip.remove();
                        }
                    }, 300);
                }
            }
        });

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
            showNotificationModal('success', 'Lab result uploaded successfully!');
            // Run status check before full reload
            refreshStatusChecks();
            // Refresh page after delay
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        };
        
        // Make upload function globally accessible
        window.uploadResult = uploadResult;
        window.uploadSingleResult = uploadSingleResult;
        window.downloadResult = downloadResult;

        // Lab report printing function
        function printLabReport(labOrderId) {
            // Open print report in new window
            window.open(`print_lab_report.php?lab_order_id=${labOrderId}`, '_blank', 'width=800,height=600,scrollbars=yes');
        }
        
        // Order status update function
        function updateOrderStatus(labOrderId, currentStatus) {
            const statuses = ['pending', 'in_progress', 'completed', 'cancelled', 'partial'];
            const currentIndex = statuses.indexOf(currentStatus);
            
            let options = '';
            statuses.forEach(status => {
                const selected = status === currentStatus ? 'selected' : '';
                options += `<option value="${status}" ${selected}>${status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' ')}</option>`;
            });

            const modalContent = `
                <div style="padding: 20px;">
                    <h4>Update Order Status</h4>
                    <form onsubmit="submitOrderStatusUpdate(event, ${labOrderId})">
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Overall Status:</label>
                            <select id="newOrderStatus" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                ${options}
                            </select>
                        </div>
                        <div style="margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Remarks (Optional):</label>
                            <textarea id="orderStatusRemarks" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" rows="3"></textarea>
                        </div>
                        <div style="text-align: right;">
                            <button type="button" class="btn-secondary" onclick="closeModal('orderStatusUpdateModal')" style="margin-right: 10px;">Cancel</button>
                            <button type="submit" class="btn-primary">Update Status</button>
                        </div>
                    </form>
                </div>
            `;

            // Create and show order status update modal
            let orderStatusModal = document.getElementById('orderStatusUpdateModal');
            if (!orderStatusModal) {
                orderStatusModal = document.createElement('div');
                orderStatusModal.id = 'orderStatusUpdateModal';
                orderStatusModal.className = 'modal';
                orderStatusModal.innerHTML = `
                    <div class="modal-content" style="max-width: 500px;">
                        ${modalContent}
                    </div>
                `;
                document.body.appendChild(orderStatusModal);
            } else {
                orderStatusModal.querySelector('.modal-content').innerHTML = modalContent;
            }
            
            orderStatusModal.style.display = 'block';
        }

        function submitOrderStatusUpdate(event, labOrderId) {
            event.preventDefault();
            const newStatus = document.getElementById('newOrderStatus').value;
            const remarks = document.getElementById('orderStatusRemarks').value;

            fetch('api/update_lab_order_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    lab_order_id: labOrderId,
                    overall_status: newStatus,
                    remarks: remarks
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeModal('orderStatusUpdateModal');
                    // Refresh the order details if modal is open
                    const orderModal = document.getElementById('orderDetailsModal');
                    if (orderModal && orderModal.style.display === 'block') {
                        viewOrderDetails(labOrderId);
                    }
                    showNotificationModal('success', 'Order status updated successfully!');
                    // Refresh status checks and reload page
                    refreshStatusChecks();
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    showNotificationModal('error', 'Failed to update order status: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotificationModal('error', 'Error updating order status: ' + error.message);
            });
        }
        
        // Make print function globally accessible
        window.printLabReport = printLabReport;
        window.updateOrderStatus = updateOrderStatus;
        window.submitOrderStatusUpdate = submitOrderStatusUpdate;
        window.uploadItemResult = uploadItemResult;
        window.cancelLabOrder = cancelLabOrder;

        // Functions are now handled by inline events in modal content

        function showAlert(message, type = 'success') {
            // Redirect to notification modal for consistency
            showNotificationModal(type, message);
        }

        // Legacy support - redirect old showAlert calls to new modal system
        window.showAlert = showAlert;

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
            const modals = ['orderDetailsModal', 'uploadResultModal', 'quickUploadModal', 'pdfViewerModal'];
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