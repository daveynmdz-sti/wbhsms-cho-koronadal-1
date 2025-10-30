<?php
// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Type: application/json');

// Include employee session configuration
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

// Server-side role enforcement using role_id
$authorizedRoleIds = [1, 2, 3, 9]; // admin, doctor, nurse, laboratory_tech
if (!isset($_SESSION['employee_id']) || !in_array($_SESSION['role_id'], $authorizedRoleIds)) {
    http_response_code(403);
    echo json_encode(['error' => 'Not authorized']);
    exit();
}

// Validate and sanitize lab_order_id
$lab_order_id = filter_input(INPUT_GET, 'lab_order_id', FILTER_VALIDATE_INT);
if (!$lab_order_id || $lab_order_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Valid lab order ID is required']);
    exit();
}

// Check authorization based on role_id
$canUploadResults = in_array($_SESSION['role_id'], [1, 9]); // admin, laboratory_tech
$canUpdateStatus = $canUploadResults;

// Check if timing columns exist
$timingColumnsSql = "SHOW COLUMNS FROM lab_order_items WHERE Field IN ('started_at', 'completed_at')";
$timingResult = $conn->query($timingColumnsSql);
$hasTimingColumns = $timingResult->num_rows > 0;

// Fetch lab order details with enhanced timing info
$orderSql = "SELECT lo.lab_order_id, lo.patient_id, lo.order_date, lo.status, lo.status as overall_status,
                    lo.ordered_by_employee_id, lo.remarks, lo.appointment_id, lo.visit_id,
                    p.first_name, p.last_name, p.middle_name, p.date_of_birth, p.sex as gender, p.username as patient_id_display,
                    e.first_name as ordered_by_first_name, e.last_name as ordered_by_last_name,
                    TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age";

// Add average_tat if column exists
$avgTatCheck = $conn->query("SHOW COLUMNS FROM lab_orders LIKE 'average_tat'");
if ($avgTatCheck->num_rows > 0) {
    $orderSql .= ", lo.average_tat";
}

$orderSql .= " FROM lab_orders lo
               LEFT JOIN patients p ON lo.patient_id = p.patient_id
               LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
               WHERE lo.lab_order_id = ?";

$orderStmt = $conn->prepare($orderSql);
if (!$orderStmt) {
    error_log("SQL prepare failed: " . $conn->error);
    http_response_code(500);
    exit('Database error: Failed to prepare statement');
}
$orderStmt->bind_param("i", $lab_order_id);
$orderStmt->execute();
$orderResult = $orderStmt->get_result();
$order = $orderResult->fetch_assoc();

if (!$order) {
    http_response_code(404);
    exit('Lab order not found');
}

// Fetch lab order items with timing info
$itemsSql = "SELECT loi.item_id as lab_order_item_id, loi.test_type, loi.status, 
                    loi.result_file, loi.result_date, loi.remarks, loi.created_at, loi.updated_at";

// Add timing columns if they exist
if ($hasTimingColumns) {
    $itemsSql .= ", loi.started_at, loi.completed_at";
} else {
    $itemsSql .= ", NULL as started_at, NULL as completed_at";
}

$itemsSql .= " FROM lab_order_items loi
               WHERE loi.lab_order_id = ?
               ORDER BY loi.created_at ASC";

$itemsStmt = $conn->prepare($itemsSql);
if (!$itemsStmt) {
    error_log("SQL prepare failed for items: " . $conn->error);
    http_response_code(500);
    exit('Database error: Failed to prepare items statement');
}
$itemsStmt->bind_param("i", $lab_order_id);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();

$patientName = trim($order['first_name'] . ' ' . $order['middle_name'] . ' ' . $order['last_name']);
$orderedBy = trim($order['ordered_by_first_name'] . ' ' . $order['ordered_by_last_name']);
?>

<style>
    .order-details {
        padding: 0;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .order-summary-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 25px;
    }

    .summary-card {
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        border-radius: 12px;
        padding: 20px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .summary-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    }

    .card-header {
        display: flex;
        align-items: center;
        margin-bottom: 15px;
        color: #03045e;
        font-weight: 600;
        font-size: 1.1em;
    }

    .card-header i {
        margin-right: 10px;
        padding: 8px;
        background: linear-gradient(135deg, #03045e, #0077b6);
        color: white;
        border-radius: 8px;
        font-size: 0.9em;
    }

    .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .info-item {
        display: flex;
        flex-direction: column;
        padding: 8px 0;
        border-bottom: 1px solid rgba(3, 4, 94, 0.1);
    }

    .info-item:last-child {
        border-bottom: none;
    }

    .info-label {
        font-weight: 600;
        color: #475569;
        font-size: 0.85em;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 4px;
    }

    .info-value {
        color: #1e293b;
        font-size: 0.95em;
        font-weight: 500;
    }

    .items-section {
        background: white;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }

    .section-header {
        background: linear-gradient(135deg, #03045e 0%, #0077b6 100%);
        color: white;
        padding: 15px 20px;
        font-weight: 600;
        font-size: 1.1em;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .section-header i {
        margin-right: 10px;
    }

    .items-count {
        background: rgba(255, 255, 255, 0.2);
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.9em;
        backdrop-filter: blur(10px);
    }

    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin: 0;
        background: white;
    }

    .items-table th {
        background: #f8fafc;
        padding: 15px 12px;
        text-align: left;
        font-weight: 600;
        color: #475569;
        font-size: 0.9em;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 2px solid #e2e8f0;
    }

    .items-table td {
        padding: 15px 12px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.9em;
        vertical-align: middle;
    }

    .items-table tr:hover {
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    }

    .items-table tr:last-child td {
        border-bottom: none;
    }

    .test-name {
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 4px;
    }

    .test-id {
        font-size: 0.8em;
        color: #64748b;
        background: #f1f5f9;
        padding: 2px 8px;
        border-radius: 12px;
        display: inline-block;
    }

    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8em;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .status-badge i {
        font-size: 0.9em;
    }

    .status-pending {
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        color: #92400e;
        border: 1px solid #fbbf24;
    }

    .status-in_progress {
        background: linear-gradient(135deg, #dbeafe, #bfdbfe);
        color: #1e40af;
        border: 1px solid #3b82f6;
    }

    .status-completed {
        background: linear-gradient(135deg, #dcfce7, #bbf7d0);
        color: #166534;
        border: 1px solid #22c55e;
    }

    .status-cancelled {
        background: linear-gradient(135deg, #fee2e2, #fecaca);
        color: #dc2626;
        border: 1px solid #ef4444;
    }

    .time-info {
        font-size: 0.85em;
        color: #64748b;
        line-height: 1.4;
    }

    .time-info strong {
        color: #374151;
    }

    .action-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .action-btn {
        padding: 8px 12px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-size: 0.8em;
        font-weight: 500;
        transition: all 0.2s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        white-space: nowrap;
    }

    .action-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }

    .btn-upload {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        border: 1px solid #047857;
    }

    .btn-upload:hover {
        background: linear-gradient(135deg, #059669, #047857);
    }

    .btn-download {
        background: linear-gradient(135deg, #0ea5e9, #0284c7);
        color: white;
        border: 1px solid #0369a1;
    }

    .btn-download:hover {
        background: linear-gradient(135deg, #0284c7, #0369a1);
    }

    .btn-view {
        background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        color: white;
        border: 1px solid #6d28d9;
    }

    .btn-view:hover {
        background: linear-gradient(135deg, #7c3aed, #6d28d9);
    }

    .text-muted {
        color: #9ca3af;
        font-style: italic;
        font-size: 0.85em;
    }

    .order-actions {
        margin-top: 25px;
        padding: 20px;
        background: #f8fafc;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .action-group {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .btn-primary {
        background: linear-gradient(135deg, #03045e, #0077b6);
        color: white;
        padding: 12px 20px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
    }

    .btn-primary:hover {
        background: linear-gradient(135deg, #02034a, #005577);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(3, 4, 94, 0.3);
    }

    .btn-secondary {
        background: linear-gradient(135deg, #6b7280, #4b5563);
        color: white;
        padding: 12px 20px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s ease;
    }

    .btn-secondary:hover {
        background: linear-gradient(135deg, #4b5563, #374151);
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);
    }

    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #64748b;
    }

    .empty-state i {
        font-size: 3em;
        margin-bottom: 15px;
        color: #cbd5e1;
    }

    .empty-state h4 {
        margin: 0 0 10px 0;
        color: #475569;
    }

    .empty-state p {
        margin: 0;
        font-size: 0.9em;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .order-summary-grid {
            grid-template-columns: 1fr;
            gap: 15px;
        }

        .info-grid {
            grid-template-columns: 1fr;
            gap: 8px;
        }

        .items-table {
            font-size: 0.8em;
        }

        .items-table th,
        .items-table td {
            padding: 10px 8px;
        }

        .action-buttons {
            flex-direction: column;
            gap: 6px;
        }

        .action-btn {
            padding: 6px 10px;
            font-size: 0.75em;
        }

        .order-actions {
            flex-direction: column;
            align-items: stretch;
        }

        .action-group {
            justify-content: center;
        }
    }

    /* Animation for loading states */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .order-details {
        animation: fadeInUp 0.4s ease;
    }
</style>

<div class="order-details">
    <!-- Order Summary Cards -->
    <div class="order-summary-grid">
        <!-- Patient Information Card -->
        <div class="summary-card">
            <div class="card-header">
                <i class="fas fa-user-circle"></i>
                Patient Information
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Full Name</span>
                    <span class="info-value"><?= htmlspecialchars($patientName) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Patient ID</span>
                    <span class="info-value"><?= htmlspecialchars($order['patient_id_display']) ?></span>
                </div>
                <?php if (isset($order['age'])): ?>
                <div class="info-item">
                    <span class="info-label">Age</span>
                    <span class="info-value"><?= $order['age'] ?> years old</span>
                </div>
                <?php endif; ?>
                <?php if (isset($order['gender'])): ?>
                <div class="info-item">
                    <span class="info-label">Gender</span>
                    <span class="info-value"><?= ucfirst($order['gender']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (isset($order['date_of_birth'])): ?>
                <div class="info-item">
                    <span class="info-label">Date of Birth</span>
                    <span class="info-value"><?= date('M d, Y', strtotime($order['date_of_birth'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Order Information Card -->
        <div class="summary-card">
            <div class="card-header">
                <i class="fas fa-clipboard-list"></i>
                Order Information
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Order Date</span>
                    <span class="info-value"><?= date('M d, Y \a\t g:i A', strtotime($order['order_date'])) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Ordered By</span>
                    <span class="info-value"><?= htmlspecialchars($orderedBy ?: 'System') ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Overall Status</span>
                    <span class="info-value">
                        <span class="status-badge status-<?= $order['overall_status'] ?>">
                            <i class="fas fa-<?= $order['overall_status'] === 'completed' ? 'check-circle' : ($order['overall_status'] === 'cancelled' ? 'times-circle' : ($order['overall_status'] === 'in_progress' ? 'clock' : 'hourglass-start')) ?>"></i>
                            <?= ucfirst(str_replace('_', ' ', $order['overall_status'])) ?>
                        </span>
                    </span>
                </div>
                <?php if (isset($order['average_tat']) && $order['average_tat']): ?>
                <div class="info-item">
                    <span class="info-label">Average TAT</span>
                    <span class="info-value"><?= number_format($order['average_tat'] ?? 0, 1) ?> minutes</span>
                </div>
                <?php endif; ?>
                <?php if ($order['appointment_id']): ?>
                <div class="info-item">
                    <span class="info-label">Appointment</span>
                    <span class="info-value">#<?= $order['appointment_id'] ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($order['consultation_id'])): ?>
                <div class="info-item">
                    <span class="info-label">Consultation</span>
                    <span class="info-value">#<?= $order['consultation_id'] ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php if ($order['remarks']): ?>
            <div class="info-item" style="grid-column: 1 / -1; margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(3, 4, 94, 0.1);">
                <span class="info-label">Remarks</span>
                <span class="info-value"><?= htmlspecialchars($order['remarks']) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Lab Test Items Section -->
    <div class="items-section">
        <?php 
        // Count total items for header
        $itemsResult->data_seek(0);
        $totalItems = $itemsResult->num_rows;
        $completedItems = 0;
        $pendingItems = 0;
        $inProgressItems = 0;
        
        while ($countItem = $itemsResult->fetch_assoc()) {
            if ($countItem['status'] === 'completed') $completedItems++;
            elseif ($countItem['status'] === 'in_progress') $inProgressItems++;
            elseif ($countItem['status'] === 'pending') $pendingItems++;
        }
        $itemsResult->data_seek(0); // Reset for display
        ?>
        
        <div class="section-header">
            <div>
                <i class="fas fa-flask"></i>
                Laboratory Test Items
            </div>
            <div class="items-count">
                <?= $totalItems ?> Total Tests
                <?php if ($completedItems > 0): ?>
                    • <?= $completedItems ?> Completed
                <?php endif; ?>
                <?php if ($inProgressItems > 0): ?>
                    • <?= $inProgressItems ?> In Progress
                <?php endif; ?>
                <?php if ($pendingItems > 0): ?>
                    • <?= $pendingItems ?> Pending
                <?php endif; ?>
            </div>
        </div>

        <?php if ($totalItems > 0): ?>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25%;">Test Details</th>
                    <th style="width: 15%;">Status</th>
                    <th style="width: 20%;">Timing Information</th>
                    <th style="width: 15%;">Result Actions</th>
                    <th style="width: 25%;">Management</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($item = $itemsResult->fetch_assoc()): ?>
                <tr>
                    <td>
                        <div class="test-name"><?= htmlspecialchars($item['test_type']) ?></div>
                        <div class="test-id">ID: <?= $item['lab_order_item_id'] ?></div>
                    </td>
                    <td>
                        <span class="status-badge status-<?= $item['status'] ?>">
                            <i class="fas fa-<?= $item['status'] === 'completed' ? 'check-circle' : ($item['status'] === 'cancelled' ? 'times-circle' : ($item['status'] === 'in_progress' ? 'clock' : 'hourglass-start')) ?>"></i>
                            <?= ucfirst(str_replace('_', ' ', $item['status'])) ?>
                        </span>
                    </td>
                    <td>
                        <div class="time-info">
                            <?php if ($item['started_at']): ?>
                                <div><strong>Started:</strong><br><?= date('M d, Y \a\t g:i A', strtotime($item['started_at'])) ?></div>
                            <?php else: ?>
                                <div><strong>Started:</strong> Not started yet</div>
                            <?php endif; ?>
                            
                            <?php if ($item['completed_at']): ?>
                                <div style="margin-top: 5px;"><strong>Completed:</strong><br><?= date('M d, Y \a\t g:i A', strtotime($item['completed_at'])) ?></div>
                            <?php elseif ($item['status'] !== 'completed'): ?>
                                <div style="margin-top: 5px;"><strong>Completed:</strong> In progress</div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <?php if ($item['result_file']): ?>
                                <button class="action-btn btn-view" onclick="viewResult(<?= $item['lab_order_item_id'] ?>)" title="View Result">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="action-btn btn-download" onclick="downloadResult(<?= $item['lab_order_item_id'] ?>)" title="Download Result">
                                    <i class="fas fa-download"></i> Download
                                </button>
                            <?php else: ?>
                                <span class="text-muted">No result file uploaded</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <?php if ($canUploadResults && $item['status'] !== 'completed' && $item['status'] !== 'cancelled'): ?>
                                <button class="action-btn btn-upload" onclick="uploadItemResult(<?= $item['lab_order_item_id'] ?>)" title="Upload Result">
                                    <i class="fas fa-upload"></i> Upload Result
                                </button>
                            <?php elseif (!$canUploadResults): ?>
                                <span class="text-muted">Not authorized to upload</span>
                            <?php elseif ($item['status'] === 'completed'): ?>
                                <span class="text-muted">Test completed</span>
                            <?php else: ?>
                                <span class="text-muted">Test cancelled</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-flask"></i>
            <h4>No Lab Tests Found</h4>
            <p>This order doesn't contain any laboratory test items.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Order Actions -->
    <div class="order-actions">
        <div class="action-group">
            <button class="btn-secondary" onclick="closeModal('orderDetailsModal')">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
        
        <div class="action-group">
            <?php 
            // Count completed items for print report button
            $itemsResult->data_seek(0); // Reset result pointer
            $completedCount = 0;
            $totalCount = 0;
            while ($checkItem = $itemsResult->fetch_assoc()) {
                $totalCount++;
                if ($checkItem['status'] === 'completed') {
                    $completedCount++;
                }
            }
            $itemsResult->data_seek(0); // Reset again
            ?>
            
            <?php if ($canUpdateStatus): ?>
            <button class="btn-primary" onclick="updateOrderStatus(<?= $order['lab_order_id'] ?>, '<?= $order['overall_status'] ?>')" title="Update Overall Order Status">
                <i class="fas fa-edit"></i> Update Status
            </button>
            <?php endif; ?>
            
            <?php if ($completedCount > 0): ?>
            <button class="btn-primary" onclick="printLabReport(<?= $order['lab_order_id'] ?>)" title="Print Lab Report">
                <i class="fas fa-print"></i> Print Report (<?= $completedCount ?>/<?= $totalCount ?>)
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function downloadResult(itemId) {
        window.open(`../api/download_lab_result.php?item_id=${itemId}`, '_blank');
    }

    function viewResult(itemId) {
        // Call the parent window's viewResult function if available
        if (window.parent && typeof window.parent.viewResult === 'function') {
            window.parent.viewResult(itemId);
        } else {
            // Fallback to download if viewResult is not available
            downloadResult(itemId);
        }
    }

    function uploadItemResult(labOrderItemId) {
        console.log('uploadItemResult called with ID:', labOrderItemId);
        
        // Close the order details modal
        if (window.parent && window.parent.closeModal) {
            window.parent.closeModal('orderDetailsModal');
        }
        
        // Call the parent window's uploadResult function
        setTimeout(() => {
            if (window.parent && typeof window.parent.uploadResult === 'function') {
                console.log('Calling parent uploadResult function');
                window.parent.uploadResult(labOrderItemId);
            } else {
                console.error('Parent uploadResult function not found');
                alert('Upload function not available. Please refresh the page and try again.');
            }
        }, 100);
    }

    function printLabReport(labOrderId) {
        // Open print report in new window
        window.open(`print_lab_report.php?lab_order_id=${labOrderId}`, '_blank', 'width=800,height=600,scrollbars=yes');
    }

    function updateItemStatus(labOrderItemId, currentStatus) {
        const statuses = ['pending', 'in_progress', 'completed', 'cancelled'];
        const currentIndex = statuses.indexOf(currentStatus);
        
        let options = '';
        statuses.forEach(status => {
            const selected = status === currentStatus ? 'selected' : '';
            options += `<option value="${status}" ${selected}>${status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' ')}</option>`;
        });

        const modalContent = `
            <div style="padding: 20px;">
                <h4>Update Test Status</h4>
                <form onsubmit="submitStatusUpdate(event, ${labOrderItemId})">
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Status:</label>
                        <select id="newStatus" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                            ${options}
                        </select>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Remarks (Optional):</label>
                        <textarea id="statusRemarks" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" rows="3"></textarea>
                    </div>
                    <div style="text-align: right;">
                        <button type="button" class="btn-secondary" onclick="closeModal('statusUpdateModal')" style="margin-right: 10px;">Cancel</button>
                        <button type="submit" class="btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        `;

        // Create and show status update modal
        let statusModal = document.getElementById('statusUpdateModal');
        if (!statusModal) {
            statusModal = document.createElement('div');
            statusModal.id = 'statusUpdateModal';
            statusModal.className = 'modal';
            statusModal.innerHTML = `
                <div class="modal-content" style="max-width: 500px;">
                    ${modalContent}
                </div>
            `;
            document.body.appendChild(statusModal);
        } else {
            statusModal.querySelector('.modal-content').innerHTML = modalContent;
        }
        
        statusModal.style.display = 'block';
    }

    function submitStatusUpdate(event, labOrderItemId) {
        event.preventDefault();
        const newStatus = document.getElementById('newStatus').value;
        const remarks = document.getElementById('statusRemarks').value;

        fetch('../api/update_lab_item_status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                lab_order_item_id: labOrderItemId,
                status: newStatus,
                remarks: remarks
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                closeModal('statusUpdateModal');
                // Refresh the order details
                viewOrderDetails(<?= $order['lab_order_id'] ?>);
                if (typeof showAlert === 'function') {
                    showAlert('Test status updated successfully', 'success');
                }
            } else {
                alert('Error updating status: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error updating status');
        });
    }

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

        fetch('../api/update_lab_order_status.php', {
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
                // Refresh the order details
                viewOrderDetails(labOrderId);
                if (typeof showAlert === 'function') {
                    showAlert('Order status updated successfully', 'success');
                }
            } else {
                alert('Error updating order status: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error updating order status');
        });
    }

    function uploadItemResult(labOrderItemId) {
        // Close the current order details modal
        closeModal('orderDetailsModal');
        
        // Call the upload function from the parent page
        if (typeof uploadResult === 'function') {
            uploadResult(labOrderItemId);
        } else {
            // Fallback: redirect to upload page
            window.location.href = `../upload_lab_result.php?lab_order_item_id=${labOrderItemId}`;
        }
    }

    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'none';
        }
    }
</script>