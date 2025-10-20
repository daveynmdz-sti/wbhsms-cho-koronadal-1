<?php
// Get lab order items for quick upload
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

// Check authorization
$authorizedRoleIds = [1, 2, 3, 9]; // admin, doctor, nurse, laboratory_tech
if (!isset($_SESSION['employee_id']) || !in_array($_SESSION['role_id'], $authorizedRoleIds)) {
    http_response_code(403);
    exit('Not authorized');
}

$lab_order_id = $_GET['lab_order_id'] ?? null;
if (!$lab_order_id) {
    http_response_code(400);
    exit('Lab order ID required');
}

$canUploadResults = in_array($_SESSION['role_id'], [1, 9]); // admin, laboratory_tech

// Get lab order details
$orderSql = "SELECT lo.lab_order_id, 
                    p.first_name, p.last_name, p.middle_name, p.username as patient_id_display
             FROM lab_orders lo
             LEFT JOIN patients p ON lo.patient_id = p.patient_id
             WHERE lo.lab_order_id = ?";

$orderStmt = $conn->prepare($orderSql);
$orderStmt->bind_param("i", $lab_order_id);
$orderStmt->execute();
$order = $orderStmt->get_result()->fetch_assoc();

if (!$order) {
    http_response_code(404);
    exit('Lab order not found');
}

// Get lab order items
$itemsSql = "SELECT item_id, test_type, status, result_date, 
                    (result_file IS NOT NULL) as has_result_file
             FROM lab_order_items 
             WHERE lab_order_id = ?
             ORDER BY test_type";

$itemsStmt = $conn->prepare($itemsSql);
$itemsStmt->bind_param("i", $lab_order_id);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();

$patientName = trim($order['first_name'] . ' ' . $order['middle_name'] . ' ' . $order['last_name']);
?>

<style>
    .quick-upload-container {
        padding: 20px;
        max-width: 800px;
        margin: 0 auto;
    }

    .patient-header {
        background-color: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
        text-align: center;
    }

    .patient-header h4 {
        margin: 0;
        color: #03045e;
    }

    .items-grid {
        display: grid;
        gap: 15px;
        margin-bottom: 20px;
    }

    .item-card {
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 15px;
        background: white;
        transition: all 0.3s ease;
    }

    .item-card:hover {
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .item-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .test-name {
        font-weight: bold;
        color: #03045e;
        font-size: 1.1em;
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

    .item-actions {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .btn {
        padding: 8px 15px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.9em;
        transition: all 0.3s;
    }

    .btn-upload {
        background-color: #28a745;
        color: white;
    }

    .btn-upload:hover {
        background-color: #218838;
    }

    .btn-view {
        background-color: #17a2b8;
        color: white;
    }

    .btn-view:hover {
        background-color: #138496;
    }

    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .result-info {
        font-size: 0.9em;
        color: #666;
        margin-top: 5px;
    }

    .no-items {
        text-align: center;
        padding: 40px;
        color: #666;
    }

    .modal-footer {
        text-align: center;
        padding-top: 20px;
        border-top: 1px solid #ddd;
    }
</style>

<div class="quick-upload-container">
    <div class="patient-header">
        <h4>Lab Results Upload</h4>
        <div>
            <strong>Patient:</strong> <?= htmlspecialchars($patientName) ?> 
            (ID: <?= htmlspecialchars($order['patient_id_display']) ?>)
        </div>
    </div>

    <div class="items-grid">
        <?php if ($itemsResult->num_rows > 0): ?>
            <?php while ($item = $itemsResult->fetch_assoc()): ?>
                <div class="item-card">
                    <div class="item-header">
                        <div class="test-name"><?= htmlspecialchars($item['test_type']) ?></div>
                        <span class="status-badge status-<?= $item['status'] ?>">
                            <?= ucfirst(str_replace('_', ' ', $item['status'])) ?>
                        </span>
                    </div>
                    
                    <div class="item-actions">
                        <?php if ($canUploadResults && $item['status'] !== 'completed'): ?>
                            <button class="btn btn-upload" 
                                    onclick="uploadSingleResult(<?= $item['item_id'] ?>)">
                                <i class="fas fa-upload"></i> Upload Result
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($item['has_result_file']): ?>
                            <button class="btn btn-view" 
                                    onclick="viewResult(<?= $item['item_id'] ?>)">
                                <i class="fas fa-eye"></i> View Result
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($item['status'] === 'completed'): ?>
                            <span class="result-info">
                                <i class="fas fa-check-circle"></i> 
                                Completed on <?= date('M d, Y', strtotime($item['result_date'])) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="no-items">
                <i class="fas fa-flask" style="font-size: 3em; color: #ddd; margin-bottom: 15px;"></i>
                <p>No lab tests found for this order.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="modal-footer">
        <button class="btn" style="background-color: #6c757d; color: white;" 
                onclick="closeModal('quickUploadModal')">
            Close
        </button>
    </div>
</div>

<script>
    function uploadSingleResult(itemId) {
        console.log('Upload single result for item:', itemId);
        
        // Close quick upload modal
        if (window.parent && window.parent.closeModal) {
            window.parent.closeModal('quickUploadModal');
        }
        
        // Call the main upload function
        setTimeout(() => {
            if (window.parent && typeof window.parent.uploadResult === 'function') {
                window.parent.uploadResult(itemId);
            } else {
                alert('Upload function not available. Please refresh the page and try again.');
            }
        }, 100);
    }

    function viewResult(itemId) {
        window.open(`../api/download_lab_result.php?item_id=${itemId}`, '_blank');
    }

    function closeModal(modalId) {
        if (window.parent && window.parent.closeModal) {
            window.parent.closeModal(modalId);
        }
    }
</script>