<?php
// API endpoint to get lab order items for quick upload
require_once '../config/session/employee_session.php';
require_once '../config/db.php';

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

// Get lab order items for the quick upload modal
$query = "SELECT loi.item_id, loi.test_type, loi.status, loi.remarks as result_text, loi.result_file as result_file_name,
                 lo.patient_id, p.first_name, p.last_name, p.username as patient_id_display
          FROM lab_order_items loi
          JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
          JOIN patients p ON lo.patient_id = p.patient_id
          WHERE loi.lab_order_id = ?
          ORDER BY loi.item_id";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $lab_order_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<div class="alert alert-error">No lab items found for this order.</div>';
    exit;
}

// Output HTML for the quick upload modal
?>
<div class="quick-upload-container">
    <h4>Quick Upload - Lab Order #<?= htmlspecialchars($lab_order_id) ?></h4>
    
    <?php 
    $first_row = true;
    $patient_info = null;
    ?>
    
    <div class="lab-items-list">
        <?php while ($item = $result->fetch_assoc()): ?>
            <?php if ($first_row): ?>
                <?php 
                $patient_info = $item;
                $first_row = false; 
                ?>
                <div class="patient-info-quick">
                    <strong>Patient:</strong> <?= htmlspecialchars($patient_info['first_name'] . ' ' . $patient_info['last_name']) ?> 
                    (ID: <?= htmlspecialchars($patient_info['patient_id_display']) ?>)
                </div>
            <?php endif; ?>
            
            <div class="lab-item-row">
                <div class="item-info">
                    <div class="item-header">
                        <strong><?= htmlspecialchars($item['test_type']) ?></strong>
                        <span class="status-badge status-<?= $item['status'] ?>">
                            <?= ucfirst($item['status']) ?>
                        </span>
                    </div>
                    
                    <?php if ($item['status'] === 'completed'): ?>
                        <div class="completed-info">
                            <i class="fas fa-check-circle text-success"></i>
                            Result uploaded
                            <?php if ($item['result_file_name']): ?>
                                <span class="file-info">(File attached)</span>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <button class="btn-quick-upload" onclick="uploadResult(<?= $item['item_id'] ?>)">
                            <i class="fas fa-upload"></i> Upload Result
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endwhile; ?>
    </div>
</div>

<style>
.quick-upload-container {
    padding: 20px;
}

.patient-info-quick {
    background: #f8f9fa;
    padding: 10px;
    border-radius: 4px;
    margin-bottom: 15px;
    border-left: 4px solid #007bff;
}

.lab-items-list {
    max-height: 400px;
    overflow-y: auto;
}

.lab-item-row {
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 10px;
    background: white;
}

.item-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.status-badge {
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
}

.status-in_progress {
    background: #d1ecf1;
    color: #0c5460;
}

.status-completed {
    background: #d4edda;
    color: #155724;
}

.completed-info {
    color: #28a745;
    font-size: 14px;
}

.completed-info i {
    margin-right: 5px;
}

.file-info {
    color: #6c757d;
    font-style: italic;
    margin-left: 5px;
}

.btn-quick-upload {
    background: #007bff;
    color: white;
    border: none;
    padding: 8px 15px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    transition: background-color 0.2s;
}

.btn-quick-upload:hover {
    background: #0056b3;
}

.btn-quick-upload i {
    margin-right: 5px;
}

.text-success {
    color: #28a745 !important;
}
</style>