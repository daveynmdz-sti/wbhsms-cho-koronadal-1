<?php
// Include patient session configuration
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    http_response_code(401);
    exit('Unauthorized');
}

// Database connection
require_once $root_path . '/config/db.php';

$patient_id = $_SESSION['patient_id'];
$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'details':
            handleLabOrderDetails();
            break;
        case 'results':
            handleLabOrderResults();
            break;
        case 'list':
        default:
            handleLabOrdersList();
            break;
    }
} catch (Exception $e) {
    error_log("Lab orders API error: " . $e->getMessage());
    echo '<div class="alert alert-error">Error loading data. Please try again.</div>';
}

function handleLabOrderDetails() {
    global $pdo, $patient_id;
    
    $lab_order_id = $_GET['lab_order_id'] ?? null;
    if (!$lab_order_id || !is_numeric($lab_order_id)) {
        echo '<div class="alert alert-error">Invalid lab order ID.</div>';
        return;
    }
    
    // Fetch lab order details with patient verification
    $stmt = $pdo->prepare("
        SELECT lo.lab_order_id,
               lo.order_date,
               lo.status as order_status,
               lo.remarks,
               e.first_name as doctor_first_name, 
               e.last_name as doctor_last_name,
               e.license_number as doctor_license
        FROM lab_orders lo
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        WHERE lo.lab_order_id = :lab_order_id AND lo.patient_id = :patient_id
    ");
    $stmt->bindParam(':lab_order_id', $lab_order_id, PDO::PARAM_INT);
    $stmt->bindParam(':patient_id', $patient_id, PDO::PARAM_INT);
    $stmt->execute();
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo '<div class="alert alert-error">Lab order not found or access denied.</div>';
        return;
    }
    
    // Fetch lab order items
    $stmt = $pdo->prepare("
        SELECT item_id,
               test_type,
               status,
               result_date,
               remarks,
               CASE WHEN result_file IS NOT NULL THEN 1 ELSE 0 END as has_result
        FROM lab_order_items
        WHERE lab_order_id = :lab_order_id
        ORDER BY test_type ASC
    ");
    $stmt->bindParam(':lab_order_id', $lab_order_id, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Display the details
    ?>
    <div class="lab-order-details">
        <div class="detail-section">
            <h3><i class="fas fa-info-circle"></i> Order Information</h3>
            <div class="detail-grid">
                <div class="detail-item">
                    <label>Order Date:</label>
                    <span><?php echo date('F j, Y g:i A', strtotime($order['order_date'])); ?></span>
                </div>
                <div class="detail-item">
                    <label>Ordered By:</label>
                    <span>
                        <?php if (!empty($order['doctor_first_name'])): ?>
                            Dr. <?php echo htmlspecialchars($order['doctor_first_name'] . ' ' . $order['doctor_last_name']); ?>
                            <?php if (!empty($order['doctor_license'])): ?>
                                (License: <?php echo htmlspecialchars($order['doctor_license']); ?>)
                            <?php endif; ?>
                        <?php else: ?>
                            System Generated
                        <?php endif; ?>
                    </span>
                </div>
                <div class="detail-item">
                    <label>Overall Status:</label>
                    <span class="status-badge status-<?php echo $order['order_status']; ?>">
                        <?php echo strtoupper($order['order_status']); ?>
                    </span>
                </div>
                <?php if (!empty($order['remarks'])): ?>
                <div class="detail-item full-width">
                    <label>Remarks:</label>
                    <span><?php echo htmlspecialchars($order['remarks']); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="detail-section">
            <h3><i class="fas fa-list"></i> Test Items (<?php echo count($items); ?>)</h3>
            <div class="items-list">
                <?php foreach ($items as $item): ?>
                    <div class="item-card">
                        <div class="item-header">
                            <h4><?php echo htmlspecialchars($item['test_type']); ?></h4>
                            <span class="status-badge status-<?php echo $item['status']; ?>">
                                <?php echo strtoupper($item['status']); ?>
                            </span>
                        </div>
                        <div class="item-details">
                            <?php if ($item['result_date']): ?>
                                <p><i class="fas fa-calendar-check"></i> Result Date: <?php echo date('F j, Y g:i A', strtotime($item['result_date'])); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($item['remarks'])): ?>
                                <p><i class="fas fa-comment"></i> <?php echo htmlspecialchars($item['remarks']); ?></p>
                            <?php endif; ?>
                            <?php if ($item['has_result'] && $item['status'] === 'completed'): ?>
                                <div class="item-actions">
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="viewLabResult(<?php echo $item['item_id']; ?>)">
                                        <i class="fas fa-eye"></i> View Result
                                    </button>
                                    <button type="button" class="btn btn-outline-success btn-sm" onclick="downloadLabResult(<?php echo $item['item_id']; ?>)">
                                        <i class="fas fa-download"></i> Download
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="printLabResult(<?php echo $item['item_id']; ?>)">
                                        <i class="fas fa-print"></i> Print
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <style>
        .lab-order-details {
            font-family: inherit;
        }

        .detail-section {
            margin-bottom: 2rem;
        }

        .detail-section h3 {
            color: #0077b6;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f0f0f0;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-item.full-width {
            grid-column: 1 / -1;
        }

        .detail-item label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
        }

        .detail-item span {
            color: #212529;
        }

        .items-list {
            display: grid;
            gap: 1rem;
        }

        .item-card {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            background: #f8f9fa;
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .item-header h4 {
            margin: 0;
            color: #0077b6;
        }

        .item-details {
            color: #6c757d;
        }

        .item-details p {
            margin: 0.25rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .item-actions {
            margin-top: 1rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .detail-grid {
                grid-template-columns: 1fr;
            }

            .item-actions {
                flex-direction: column;
            }
        }
    </style>
    <?php
}

function handleLabOrderResults() {
    global $pdo, $patient_id;
    
    $lab_order_id = $_GET['lab_order_id'] ?? null;
    if (!$lab_order_id || !is_numeric($lab_order_id)) {
        echo '<div class="alert alert-error">Invalid lab order ID.</div>';
        return;
    }
    
    // Fetch completed lab order items with results for this patient
    $stmt = $pdo->prepare("
        SELECT loi.item_id,
               loi.test_type,
               loi.status,
               loi.result_date,
               loi.remarks,
               CASE WHEN loi.result_file IS NOT NULL THEN 1 ELSE 0 END as has_result
        FROM lab_order_items loi
        INNER JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
        WHERE lo.lab_order_id = :lab_order_id
        AND lo.patient_id = :patient_id
        AND loi.status = 'completed' 
        AND loi.result_file IS NOT NULL
        ORDER BY loi.result_date DESC
    ");
    $stmt->bindParam(':lab_order_id', $lab_order_id, PDO::PARAM_INT);
    $stmt->bindParam(':patient_id', $patient_id, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($results)) {
        echo '<div class="alert alert-error">No completed results available for this order.</div>';
        return;
    }
    
    // Display the results
    ?>
    <div class="lab-results-container">
        <div class="results-header">
            <h3><i class="fas fa-file-medical"></i> Available Results (<?php echo count($results); ?>)</h3>
            <p>Click on any test result to view, download, or print.</p>
        </div>

        <div class="results-list">
            <?php foreach ($results as $result): ?>
                <div class="result-card" onclick="viewLabResult(<?php echo $result['item_id']; ?>)">
                    <div class="result-header">
                        <h4><?php echo htmlspecialchars($result['test_type']); ?></h4>
                        <span class="result-date">
                            <i class="fas fa-calendar"></i> 
                            <?php echo date('M j, Y g:i A', strtotime($result['result_date'])); ?>
                        </span>
                    </div>
                    <div class="result-actions">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="event.stopPropagation(); viewLabResult(<?php echo $result['item_id']; ?>)">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button type="button" class="btn btn-outline-success btn-sm" onclick="event.stopPropagation(); downloadLabResult(<?php echo $result['item_id']; ?>)">
                            <i class="fas fa-download"></i> Download
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="event.stopPropagation(); printLabResult(<?php echo $result['item_id']; ?>)">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                    <?php if (!empty($result['remarks'])): ?>
                        <div class="result-remarks">
                            <i class="fas fa-comment"></i> <?php echo htmlspecialchars($result['remarks']); ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <style>
        .lab-results-container {
            font-family: inherit;
        }

        .results-header {
            margin-bottom: 1.5rem;
        }

        .results-header h3 {
            color: #0077b6;
            margin-bottom: 0.5rem;
        }

        .results-header p {
            color: #6c757d;
            margin: 0;
        }

        .results-list {
            display: grid;
            gap: 1rem;
        }

        .result-card {
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1.25rem;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .result-card:hover {
            background: #e9ecef;
            border-color: #0077b6;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 119, 182, 0.1);
        }

        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .result-header h4 {
            margin: 0;
            color: #0077b6;
        }

        .result-date {
            color: #6c757d;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .result-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .result-remarks {
            margin-top: 0.75rem;
            padding-top: 0.75rem;
            border-top: 1px solid #dee2e6;
            color: #6c757d;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        @media (max-width: 768px) {
            .result-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .result-actions {
                flex-direction: column;
            }
        }
    </style>
    <?php
}

function handleLabOrdersList() {
    global $pdo, $patient_id;
    
    // This would be used for AJAX refresh of the main list
    // For now, just return a simple success message
    echo json_encode(['success' => true, 'message' => 'Lab orders list endpoint ready']);
}
?>