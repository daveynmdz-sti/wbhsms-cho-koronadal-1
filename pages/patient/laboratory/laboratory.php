<?php
// Start output buffering at the very beginning
ob_start();

// Set error reporting for debugging but don't display errors in production
error_reporting(E_ALL);
ini_set('display_errors', '0');  // Never show errors to users in production
ini_set('log_errors', '1');      // Log errors for debugging

// Include patient session configuration FIRST - before any output
$root_path = dirname(dirname(dirname(__DIR__)));

// Load configuration first
require_once $root_path . '/config/env.php';

// Then load session management
require_once $root_path . '/config/session/patient_session.php';

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If user is not logged in, redirect to login using session management function
if (!is_patient_logged_in()) {
    ob_clean(); // Clear output buffer before redirect
    redirect_to_patient_login();
}

// Database connection
require_once $root_path . '/config/db.php';

$patient_id = $_SESSION['patient_id'];
$message = '';
$error = '';

// Fetch patient information
$patient_info = null;
try {
    $stmt = $pdo->prepare("
        SELECT p.*, b.barangay_name
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.patient_id = ?
    ");
    $stmt->execute([$patient_id]);
    $patient_info = $stmt->fetch(PDO::FETCH_ASSOC);

    // Calculate priority level
    if ($patient_info) {
        $patient_info['priority_level'] = ($patient_info['isPWD'] || $patient_info['isSenior']) ? 1 : 2;
        $patient_info['priority_description'] = ($patient_info['priority_level'] == 1) ? 'Priority Patient' : 'Regular Patient';
    }

} catch (Exception $e) {
    $error = "Failed to fetch patient information: " . $e->getMessage();
}

// Fetch lab orders for this patient
$lab_orders = [];
try {
    $stmt = $pdo->prepare("
        SELECT lo.lab_order_id,
               lo.order_date,
               lo.status as order_status,
               lo.remarks,
               e.first_name as doctor_first_name, 
               e.last_name as doctor_last_name,
               e.license_number as doctor_license,
               GROUP_CONCAT(
                   CONCAT(loi.test_type, ':', loi.status, ':', IFNULL(loi.result_date, ''), ':', IFNULL(loi.item_id, ''))
                   SEPARATOR '|'
               ) as lab_items,
               COUNT(loi.item_id) as total_tests,
               SUM(CASE WHEN loi.status = 'completed' THEN 1 ELSE 0 END) as completed_tests
        FROM lab_orders lo
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE lo.patient_id = ?
        GROUP BY lo.lab_order_id
        ORDER BY lo.order_date DESC
        LIMIT 50
    ");
    $stmt->execute([$patient_id]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Parse lab items
        $items = [];
        if (!empty($row['lab_items'])) {
            $item_strings = explode('|', $row['lab_items']);
            foreach ($item_strings as $item_str) {
                $parts = explode(':', $item_str);
                if (count($parts) >= 4) {
                    $items[] = [
                        'test_type' => $parts[0],
                        'status' => $parts[1],
                        'result_date' => $parts[2] ?: null,
                        'item_id' => $parts[3] ?: null
                    ];
                }
            }
        }
        $row['parsed_items'] = $items;
        $lab_orders[] = $row;
    }
    
    $stmt = null; // Release PDO statement
} catch (Exception $e) {
    $error = "Failed to fetch lab orders: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Laboratory Tests - WBHSMS Patient Portal</title>
    
    <!-- CSS Files -->
    <link rel="stylesheet" href="../../../assets/css/sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Main Content Wrapper */
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

        /* Page Header */
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
            font-size: 2rem;
            color: #0077b6;
            font-weight: 700;
        }

        /* Breadcrumb */
        .breadcrumb {
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 1rem;
        }

        .breadcrumb a {
            color: #6c757d;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            color: white;
        }

        /* Action Buttons */
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
            border: none;
            border-radius: 8px;
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            padding: 12px 28px;
            text-align: center;
            display: inline-block;
            transition: all 0.3s ease;
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

        /* Section Container */
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
            justify-content: flex-start;
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

        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 0.75rem 1.25rem;
            border-radius: 25px;
            background: #f8f9fa;
            border: 2px solid transparent;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-tab.active {
            background: #007BFF;
            color: white;
            border-color: #0056b3;
        }

        .filter-tab:hover {
            background: #e9ecef;
        }

        .filter-tab.active:hover {
            background: #0056b3;
        }

        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            overflow-x: auto;
        }

        .lab-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
            min-width: 900px; /* Ensure minimum width for proper spacing */
        }

        .lab-table th {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.95rem;
            white-space: nowrap;
        }

        /* Column width distribution for better spacing */
        .lab-table th:nth-child(1) { width: 15%; } /* Order Date */
        .lab-table th:nth-child(2) { width: 20%; } /* Ordered By */
        .lab-table th:nth-child(3) { width: 25%; } /* Tests Requested */
        .lab-table th:nth-child(4) { width: 12%; } /* Status */
        .lab-table th:nth-child(5) { width: 15%; } /* Progress */
        .lab-table th:nth-child(6) { width: 13%; } /* Actions */

        .lab-table td {
            padding: 1.25rem 1rem;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
            line-height: 1.5;
        }

        .lab-table tbody tr {
            transition: all 0.2s ease;
        }

        .lab-table tbody tr:hover {
            background-color: #f8f9fa;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        /* Cell content styling */
        .date-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .date-info strong {
            font-size: 0.9rem;
            color: #2c3e50;
            font-weight: 600;
        }

        .date-info small {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 400;
        }

        .doctor-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .doctor-info strong {
            font-size: 0.9rem;
            color: #2c3e50;
            font-weight: 600;
            line-height: 1.3;
        }

        .doctor-info small {
            font-size: 0.75rem;
            color: #6c757d;
            font-weight: 400;
        }

        .tests-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .tests-info strong {
            font-size: 0.9rem;
            color: #2c3e50;
            font-weight: 600;
        }

        .test-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 0.25rem;
            line-height: 1.3;
        }

        .test-item:last-child {
            margin-bottom: 0;
        }

        .progress-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            align-items: flex-start;
        }

        .progress-text {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
            align-items: flex-start;
        }

        .progress-text strong {
            font-size: 0.95rem;
            color: #2c3e50;
            font-weight: 700;
        }

        .progress-text small {
            font-size: 0.75rem;
            color: #6c757d;
            font-weight: 400;
        }

        .progress-bar-container {
            width: 100%;
            background: #e9ecef;
            height: 6px;
            border-radius: 3px;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        /* Status Badges */
        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-in_progress {
            background: #cce5ff;
            color: #0066cc;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        /* Action Buttons */
        .action-buttons-cell {
            display: flex;
            gap: 0.4rem;
            flex-wrap: nowrap;
            justify-content: flex-start;
            align-items: center;
        }

        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.8rem;
            border-radius: 6px;
            font-weight: 500;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            min-width: fit-content;
            transition: all 0.2s ease;
        }

        .btn-sm i {
            font-size: 0.75rem;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid;
        }

        .btn-outline-primary {
            border-color: #007BFF;
            color: #007BFF;
        }

        .btn-outline-primary:hover {
            background: #007BFF;
            color: white;
        }

        .btn-outline-success {
            border-color: #28a745;
            color: #28a745;
        }

        .btn-outline-success:hover {
            background: #28a745;
            color: white;
        }

        .btn-outline-secondary {
            border-color: #6c757d;
            color: #6c757d;
        }

        .btn-outline-secondary:hover {
            background: #6c757d;
            color: white;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #dee2e6;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: #495057;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        /* Lab Results Modal should appear above other modals */
        #labResultsModal {
            z-index: 1100;
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 15px;
            width: 90%;
            max-width: 1000px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.3rem;
        }

        .modal-body {
            padding: 2rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 1rem 2rem;
            background: #f8f9fa;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        .close {
            color: rgba(255, 255, 255, 0.8);
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .close:hover {
            color: white;
            background: rgba(255, 255, 255, 0.1);
        }

        /* PDF Viewer Styles */
        .pdf-viewer {
            width: 100%;
            height: 500px;
            border: none;
            border-radius: 8px;
        }

        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            color: #6c757d;
        }

        .loading i {
            margin-right: 0.5rem;
        }

        /* Alert Styles */
        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 8px;
            border: 1px solid transparent;
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

        /* Responsive Design */
        @media (max-width: 1024px) {
            .lab-table {
                min-width: 800px;
            }
        }

        @media (max-width: 768px) {
            .content-wrapper {
                padding: 15px 10px;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .page-header h1 {
                font-size: 1.75rem;
            }

            .filter-tabs {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .filter-tab {
                padding: 0.6rem 1rem;
                font-size: 0.85rem;
            }

            .lab-table {
                font-size: 0.85rem;
                min-width: 700px;
            }

            .lab-table th,
            .lab-table td {
                padding: 0.75rem 0.5rem;
            }

            .date-info strong,
            .doctor-info strong,
            .tests-info strong {
                font-size: 0.85rem;
            }

            .date-info small,
            .doctor-info small {
                font-size: 0.75rem;
            }

            .test-item {
                font-size: 0.75rem;
            }

            .status-badge {
                font-size: 0.7rem;
                padding: 0.3rem 0.6rem;
            }

            .btn-sm {
                padding: 0.4rem 0.6rem;
                font-size: 0.75rem;
                gap: 0.2rem;
            }

            .btn-sm i {
                font-size: 0.7rem;
            }

            .action-buttons-cell {
                gap: 0.3rem;
                flex-wrap: wrap;
            }

            .progress-text strong {
                font-size: 0.85rem;
            }

            .progress-text small {
                font-size: 0.7rem;
            }

            .modal-content {
                width: 95%;
                margin: 2% auto;
            }
        }

        @media (max-width: 480px) {
            .lab-table {
                min-width: 600px;
            }

            .action-buttons-cell {
                flex-direction: column;
                gap: 0.25rem;
            }

            .btn-sm {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <?php
    // Tell the sidebar which menu item to highlight
    $activePage = 'laboratory';
    include '../../../includes/sidebar_patient.php';
    ?>

    <section class="content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb" style="margin-top: 50px;">
            <a href="../dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <span style="color: #0077b6;"> / </span>
            <span style="color: #0077b6; font-weight: 600;">My Laboratory Tests</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-flask" style="margin-right: 0.5rem;"></i>My Laboratory Tests</h1>
            <div class="action-buttons">
                <a href="../appointment/appointments.php" class="btn btn-secondary">
                    <i class="fas fa-calendar-plus"></i> Book Appointment
                </a>
            </div>
        </div>

        <!-- Display Messages -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Laboratory Tests Section -->
        <div class="section-container">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-flask"></i>
                </div>
                <h2 class="section-title">Laboratory Test Orders</h2>
            </div>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <div class="filter-tab active" onclick="filterLabOrders('all', this)">
                    <i class="fas fa-list"></i> All Tests
                </div>
                <div class="filter-tab" onclick="filterLabOrders('pending', this)">
                    <i class="fas fa-hourglass-half"></i> Pending
                </div>
                <div class="filter-tab" onclick="filterLabOrders('completed', this)">
                    <i class="fas fa-check-circle"></i> Completed
                </div>
            </div>

            <!-- Laboratory Tests Table -->
            <div class="table-container">
                <table class="lab-table" id="lab-orders-table">
                    <thead>
                        <tr>
                            <th>Order Date</th>
                            <th>Ordered By</th>
                            <th>Tests Requested</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="lab-orders-tbody">
                        <?php if (empty($lab_orders)): ?>
                            <tr class="empty-row">
                                <td colspan="6" class="empty-state">
                                    <i class="fas fa-flask"></i>
                                    <h3>No Laboratory Tests Found</h3>
                                    <p>You don't have any laboratory test orders yet. Lab tests will appear here when doctors order them for you.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($lab_orders as $order): ?>
                                <tr class="lab-order-row" data-status="<?php echo htmlspecialchars($order['order_status']); ?>" data-order-date="<?php echo htmlspecialchars($order['order_date']); ?>">
                                    <td>
                                        <div class="date-info">
                                            <strong><?php echo date('M j, Y', strtotime($order['order_date'])); ?></strong>
                                            <small><?php echo date('g:i A', strtotime($order['order_date'])); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="doctor-info">
                                            <?php if (!empty($order['doctor_first_name'])): ?>
                                                <strong>Dr. <?php echo htmlspecialchars($order['doctor_first_name'] . ' ' . $order['doctor_last_name']); ?></strong>
                                                <?php if (!empty($order['doctor_license'])): ?>
                                                    <small>License: <?php echo htmlspecialchars($order['doctor_license']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">System Generated</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="tests-info">
                                            <strong><?php echo $order['total_tests']; ?> Test(s)</strong>
                                            <?php if (!empty($order['parsed_items'])): ?>
                                                <div>
                                                    <?php foreach (array_slice($order['parsed_items'], 0, 3) as $item): ?>
                                                        <div class="test-item">
                                                            <span>• <?php echo htmlspecialchars($item['test_type']); ?></span>
                                                            <span class="status-badge status-<?php echo $item['status']; ?>">
                                                                <?php echo strtoupper($item['status']); ?>
                                                            </span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if (count($order['parsed_items']) > 3): ?>
                                                        <div class="test-item">
                                                            <span>... and <?php echo count($order['parsed_items']) - 3; ?> more</span>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo htmlspecialchars($order['order_status']); ?>">
                                            <?php echo strtoupper($order['order_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="progress-info">
                                            <div class="progress-text">
                                                <strong><?php echo $order['completed_tests']; ?>/<?php echo $order['total_tests']; ?></strong>
                                                <small>Tests Completed</small>
                                            </div>
                                            <div class="progress-bar-container">
                                                <div class="progress-bar" style="width: <?php echo $order['total_tests'] > 0 ? ($order['completed_tests'] / $order['total_tests']) * 100 : 0; ?>%;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons-cell">
                                            <button type="button" class="btn btn-outline btn-outline-primary btn-sm" onclick="viewLabOrderDetails(<?php echo $order['lab_order_id']; ?>)">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            <?php if ($order['completed_tests'] > 0): ?>
                                                <button type="button" class="btn btn-outline btn-outline-success btn-sm" onclick="viewLabResults(<?php echo $order['lab_order_id']; ?>)">
                                                    <i class="fas fa-file-medical"></i> Results
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Lab Order Details Modal -->
    <div id="labOrderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 style="color: white;"><i class="fas fa-flask"></i> Laboratory Test Details</h2>
                <span class="close" onclick="closeLabOrderModal()">&times;</span>
            </div>
            <div class="modal-body" id="labOrderModalBody">
                <!-- Content will be loaded dynamically -->
                <div class="loading">
                    <i class="fas fa-spinner fa-spin"></i> Loading test details...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeLabOrderModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Lab Results Modal -->
    <div id="labResultsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 style="color: white;"><i class="fas fa-file-medical"></i> Laboratory Results</h2>
                <span class="close" onclick="closeLabResultsModal()">&times;</span>
            </div>
            <div class="modal-body" id="labResultsModalBody">
                <!-- Content will be loaded dynamically -->
                <div class="loading">
                    <i class="fas fa-spinner fa-spin"></i> Loading results...
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-success" onclick="downloadCurrentResult()" id="downloadResultBtn" style="display: none;">
                    <i class="fas fa-download"></i> Download
                </button>
                <button type="button" class="btn btn-outline-secondary" onclick="printCurrentResult()" id="printResultBtn" style="display: none;">
                    <i class="fas fa-print"></i> Print
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeLabResultsModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <script>
        // Laboratory Management JavaScript Functions
        
        let currentLabOrderId = null;
        let currentLabItemId = null;

        // Filter lab orders by status
        function filterLabOrders(status, element) {
            // Update active tab
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            element.classList.add('active');

            // Filter table rows
            const rows = document.querySelectorAll('.lab-order-row');
            rows.forEach(row => {
                if (status === 'all' || row.dataset.status === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // View lab order details
        function viewLabOrderDetails(labOrderId) {
            currentLabOrderId = labOrderId;
            
            document.getElementById('labOrderModal').style.display = 'block';
            document.getElementById('labOrderModalBody').innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading test details...</div>';

            fetch(`get_lab_orders.php?action=details&lab_order_id=${labOrderId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('labOrderModalBody').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('labOrderModalBody').innerHTML = '<div class="alert alert-error">Error loading test details.</div>';
                });
        }

        // View lab results
        function viewLabResults(labOrderId) {
            currentLabOrderId = labOrderId;
            
            document.getElementById('labResultsModal').style.display = 'block';
            document.getElementById('labResultsModalBody').innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading results...</div>';

            fetch(`get_lab_orders.php?action=results&lab_order_id=${labOrderId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('labResultsModalBody').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('labResultsModalBody').innerHTML = '<div class="alert alert-error">Error loading results.</div>';
                });
        }

        // View individual lab result (PDF viewer)
        function viewLabResult(itemId) {
            currentLabItemId = itemId;
            
            // Close the lab order details modal first
            document.getElementById('labOrderModal').style.display = 'none';
            
            // Open the lab results modal
            document.getElementById('labResultsModal').style.display = 'block';
            document.getElementById('labResultsModalBody').innerHTML = '<div class="loading"><i class="fas fa-spinner fa-spin"></i> Loading result...</div>';
            
            // Hide action buttons initially
            document.getElementById('downloadResultBtn').style.display = 'none';
            document.getElementById('printResultBtn').style.display = 'none';
            
            // Create and load PDF in iframe
            const iframe = document.createElement('iframe');
            iframe.className = 'pdf-viewer';
            iframe.src = `get_lab_result.php?item_id=${itemId}&action=view`;
            iframe.onload = function() {
                // PDF loaded successfully - show action buttons
                console.log('PDF loaded for item:', itemId);
                document.getElementById('downloadResultBtn').style.display = 'inline-block';
                document.getElementById('printResultBtn').style.display = 'inline-block';
            };
            iframe.onerror = function() {
                // Error loading PDF
                document.getElementById('labResultsModalBody').innerHTML = '<div class="alert alert-error">Error loading PDF result.</div>';
            };
            
            const container = document.getElementById('labResultsModalBody');
            container.innerHTML = '';
            container.appendChild(iframe);
        }

        // Download lab result
        function downloadLabResult(itemId) {
            window.open(`download_lab_result.php?item_id=${itemId}`, '_blank');
        }

        // Print lab result
        function printLabResult(itemId) {
            // Open PDF in a popup window and trigger print dialog
            const printWindow = window.open(`get_lab_result.php?item_id=${itemId}&action=view`, 'printWindow', 'width=800,height=600,scrollbars=yes');
            
            if (printWindow) {
                printWindow.onload = function() {
                    // Small delay to ensure PDF is fully loaded
                    setTimeout(function() {
                        printWindow.print();
                    }, 1000);
                };
            } else {
                // Fallback if popup is blocked
                alert('Please allow popups for this site to enable printing, or use the download button instead.');
            }
        }

        // Download current result
        function downloadCurrentResult() {
            if (currentLabItemId) {
                downloadLabResult(currentLabItemId);
            }
        }

        // Print current result
        function printCurrentResult() {
            if (currentLabItemId) {
                printLabResult(currentLabItemId);
            }
        }

        // Close modals
        function closeLabOrderModal() {
            document.getElementById('labOrderModal').style.display = 'none';
            currentLabOrderId = null;
        }

        function closeLabResultsModal() {
            document.getElementById('labResultsModal').style.display = 'none';
            // Hide action buttons
            document.getElementById('downloadResultBtn').style.display = 'none';
            document.getElementById('printResultBtn').style.display = 'none';
            currentLabOrderId = null;
            currentLabItemId = null;
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const labOrderModal = document.getElementById('labOrderModal');
            const labResultsModal = document.getElementById('labResultsModal');
            
            if (event.target === labOrderModal) {
                closeLabOrderModal();
            }
            if (event.target === labResultsModal) {
                closeLabResultsModal();
            }
        }
    </script>
</body>

</html>