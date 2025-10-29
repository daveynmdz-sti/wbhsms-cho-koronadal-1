<?php
// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

// Check authentication
if (!isset($_SESSION['employee_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Authentication required']);
    exit();
}

// Validate and sanitize lab_order_id
$lab_order_id = filter_input(INPUT_GET, 'lab_order_id', FILTER_VALIDATE_INT);
if (!$lab_order_id || $lab_order_id <= 0) {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Error</h1><p>Invalid lab order ID provided.</p></body></html>';
    exit();
}

// Check if timing columns exist
try {
    $timingColumnsSql = "SHOW COLUMNS FROM lab_order_items WHERE Field IN ('started_at', 'completed_at')";
    $timingResult = $conn->query($timingColumnsSql);
    $hasTimingColumns = $timingResult ? $timingResult->num_rows > 0 : false;
} catch (Exception $e) {
    error_log("Error checking timing columns in print_lab_report.php: " . $e->getMessage());
    $hasTimingColumns = false;
}

// Fetch lab order details
$orderSql = "SELECT lo.lab_order_id, lo.patient_id, lo.order_date, lo.status, lo.remarks,
                    p.first_name, p.last_name, p.middle_name, p.date_of_birth, p.sex as gender, p.username as patient_id_display,
                    e.first_name as ordered_by_first_name, e.last_name as ordered_by_last_name,
                    TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
             FROM lab_orders lo
             LEFT JOIN patients p ON lo.patient_id = p.patient_id
             LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
             WHERE lo.lab_order_id = ?";

try {
    $orderStmt = $conn->prepare($orderSql);
    if (!$orderStmt) {
        throw new Exception("Failed to prepare lab order query: " . $conn->error);
    }

    $orderStmt->bind_param("i", $lab_order_id);
    if (!$orderStmt->execute()) {
        throw new Exception("Failed to execute lab order query: " . $orderStmt->error);
    }

    $orderResult = $orderStmt->get_result();
    $order = $orderResult->fetch_assoc();

    if (!$order) {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><head><title>Not Found</title></head><body><h1>Error</h1><p>Lab order not found.</p></body></html>';
        exit();
    }
} catch (Exception $e) {
    error_log("Database error in print_lab_report.php: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Error</h1><p>Unable to load lab order. Please try again later.</p></body></html>';
    exit();
}

// Fetch completed lab order items only
$itemsSql = "SELECT loi.item_id, loi.test_type, loi.status, loi.result_file, loi.result_date, 
                    loi.remarks, loi.created_at, loi.updated_at";

if ($hasTimingColumns) {
    $itemsSql .= ", loi.started_at, loi.completed_at";
} else {
    $itemsSql .= ", NULL as started_at, NULL as completed_at";
}
$itemsSql .= ", NULL as turnaround_time, NULL as waiting_time";

$itemsSql .= " FROM lab_order_items loi
               WHERE loi.lab_order_id = ? AND loi.status = 'completed'
               ORDER BY loi.created_at ASC";

try {
    $itemsStmt = $conn->prepare($itemsSql);
    if (!$itemsStmt) {
        throw new Exception("Failed to prepare lab items query: " . $conn->error);
    }

    $itemsStmt->bind_param("i", $lab_order_id);
    if (!$itemsStmt->execute()) {
        throw new Exception("Failed to execute lab items query: " . $itemsStmt->error);
    }

    $itemsResult = $itemsStmt->get_result();
} catch (Exception $e) {
    error_log("Database error fetching lab items in print_lab_report.php: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><title>Error</title></head><body><h1>Error</h1><p>Unable to load lab items. Please try again later.</p></body></html>';
    exit();
}

// Safely build patient and ordered by names
$patientName = trim(($order['first_name'] ?? '') . ' ' . (($order['middle_name'] ?? '') ? $order['middle_name'] . ' ' : '') . ($order['last_name'] ?? ''));
$orderedBy = trim(($order['ordered_by_first_name'] ?? '') . ' ' . ($order['ordered_by_last_name'] ?? ''));

// Calculate summary statistics with error handling
$totalItems = 0;
$avgTurnaround = 0;
$items = [];

try {
    while ($item = $itemsResult->fetch_assoc()) {
        $items[] = $item;
        $totalItems++;

        // Calculate turnaround time from timestamps if available
        if (!empty($item['started_at']) && !empty($item['completed_at'])) {
            try {
                $start = new DateTime($item['started_at']);
                $end = new DateTime($item['completed_at']);
                $diff = $end->diff($start);
                $turnaround = $diff->h + ($diff->days * 24);
                $avgTurnaround += $turnaround;
            } catch (Exception $dateException) {
                // Log date parsing error but continue processing
                error_log("Date parsing error in print_lab_report.php: " . $dateException->getMessage());
            }
        }
    }
    $avgTurnaround = $totalItems > 0 ? $avgTurnaround / $totalItems : 0;
} catch (Exception $e) {
    error_log("Error processing lab items in print_lab_report.php: " . $e->getMessage());
    // Continue with empty items array to allow page to render
    $items = [];
    $totalItems = 0;
    $avgTurnaround = 0;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <title>Lab Report - Order #<?= $lab_order_id ?></title>
    <style>
        @media print {
            .no-print {
                display: none !important;
            }

            body {
                margin: 0;
                font-size: 12px;
            }

            .report-container {
                margin: 0;
                box-shadow: none;
            }

            .result-detail {
                page-break-inside: avoid;
                margin-bottom: 30px;
            }

            .pdf-embed-container {
                page-break-inside: avoid;
                min-height: 400px;
            }

            .image-result img {
                max-height: 600px;
                page-break-inside: avoid;
            }

            /* Ensure file content is visible in print */
            object[data*="application/pdf"] {
                width: 100% !important;
                height: 600px !important;
                display: block !important;
            }
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .report-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .report-header {
            text-align: center;
            border-bottom: 3px solid #03045e;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .report-title {
            color: #03045e;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .facility-info {
            color: #666;
            font-size: 14px;
        }

        .patient-info {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
        }

        .info-label {
            font-weight: bold;
            color: #03045e;
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        .results-table th,
        .results-table td {
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }

        .results-table th {
            background-color: #03045e;
            color: white;
            font-weight: bold;
        }

        .results-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: bold;
            text-transform: uppercase;
            background-color: #d4edda;
            color: #155724;
        }

        .summary-stats {
            background-color: #e3f2fd;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #03045e;
        }

        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
        }

        .report-footer {
            border-top: 2px solid #03045e;
            padding-top: 20px;
            margin-top: 30px;
            text-align: center;
            color: #666;
            font-size: 12px;
        }

        .print-controls {
            text-align: center;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #fff3cd;
            border-radius: 5px;
        }

        .btn {
            padding: 10px 20px;
            margin: 0 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background-color 0.3s;
        }

        .btn-primary {
            background-color: #03045e;
            color: white;
        }

        .btn-primary:hover {
            background-color: #0218A7;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #545b62;
        }

        .timing-info {
            font-size: 0.9em;
            color: #666;
        }

        .result-detail {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            background-color: #fafafa;
        }

        .result-file-container {
            margin-top: 15px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .pdf-embed-container {
            position: relative;
            background-color: white;
        }

        .image-result {
            background-color: white;
            border-radius: 4px;
        }

        .image-result img {
            transition: transform 0.3s ease;
        }

        .image-result img:hover {
            transform: scale(1.02);
        }

        /* Print-specific styles for better file rendering */
        @media print {
            .result-detail {
                border: 2px solid #000;
                margin-bottom: 40px;
                background-color: white;
            }

            .result-file-container {
                border: 1px solid #000;
                box-shadow: none;
            }

            .pdf-embed-container object {
                border: 1px solid #000;
            }
        }
    </style>
</head>

<body>
    <div class="print-controls no-print">
        <button class="btn btn-primary" onclick="window.print()">
            <i class="fas fa-print"></i> Print Report
        </button>
        <button class="btn btn-secondary" onclick="window.close()">
            <i class="fas fa-times"></i> Close
        </button>
    </div>

    <div class="report-container">
        <div class="report-header">
            <div class="report-title">LABORATORY REPORT</div>
            <div class="facility-info">
                Web-Based Healthcare Services Management System<br>
                City Health Office - Koronadal
            </div>
        </div>

        <div class="patient-info">
            <h3 style="margin-top: 0; color: #03045e;">Patient Information</h3>
            <div class="info-grid">
                <div>
                    <div class="info-item">
                        <span class="info-label">Name:</span>
                        <span><?= htmlspecialchars($patientName) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Patient ID:</span>
                        <span><?= htmlspecialchars($order['patient_id_display']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Age:</span>
                        <span><?= $order['age'] ?> years</span>
                    </div>
                </div>
                <div>
                    <div class="info-item">
                        <span class="info-label">Gender:</span>
                        <span><?= ucfirst($order['gender']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Date of Birth:</span>
                        <span><?= date('M d, Y', strtotime($order['date_of_birth'])) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="info-grid" style="margin-bottom: 20px;">
            <div class="info-item">
                <span class="info-label">Order #:</span>
                <span><?= $lab_order_id ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Order Date:</span>
                <span><?= date('M d, Y g:i A', strtotime($order['order_date'])) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Ordered By:</span>
                <span><?= htmlspecialchars($orderedBy) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Report Generated:</span>
                <span><?= date('M d, Y g:i A') ?></span>
            </div>
        </div>

        <?php if ($hasTimingColumns && $avgTurnaround > 0): ?>
            <div class="summary-stats">
                <h4 style="margin-top: 0; color: #03045e; text-align: center;">Lab Performance Summary</h4>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-value"><?= $totalItems ?></div>
                        <div class="stat-label">Completed Tests</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= number_format($avgTurnaround ?? 0, 1) ?> min</div>
                        <div class="stat-label">Average Turnaround Time</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= date('M d, Y', strtotime($order['order_date'])) ?></div>
                        <div class="stat-label">Test Date</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <h3 style="color: #03045e;">Laboratory Results</h3>
        <?php if (empty($items)): ?>
            <p style="text-align: center; color: #666; font-style: italic;">No completed test results available for this order.</p>
        <?php else: ?>

            <!-- Results Summary Table -->
            <table class="results-table">
                <thead>
                    <tr>
                        <th>Test Name</th>
                        <th>Status</th>
                        <th>Result Date</th>
                        <?php if ($hasTimingColumns): ?>
                            <th>Processing Time</th>
                        <?php endif; ?>
                        <th>Result File</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($item['test_type']) ?></strong></td>
                            <td>
                                <span class="status-badge">
                                    <?= ucfirst(str_replace('_', ' ', $item['status'])) ?>
                                </span>
                            </td>
                            <td><?= date('M d, Y g:i A', strtotime($item['result_date'])) ?></td>
                            <?php if ($hasTimingColumns): ?>
                                <td class="timing-info">
                                    <?php if ($item['turnaround_time']): ?>
                                        <strong><?= $item['turnaround_time'] ?> minutes</strong>
                                        <?php if ($item['waiting_time']): ?>
                                            <br><small>Wait time: <?= $item['waiting_time'] ?> minutes</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td>
                                <?php if ($item['result_file']): ?>
                                    <i class="fas fa-file-pdf" style="color: #dc3545;"></i>
                                    Result file attached (see below)
                                <?php else: ?>
                                    <em>No file attached</em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Detailed Results with File Content -->
            <div style="page-break-before: auto; margin-top: 30px;">
                <h3 style="color: #03045e; border-bottom: 2px solid #03045e; padding-bottom: 10px;">Detailed Test Results</h3>

                <?php foreach ($items as $index => $item): ?>
                    <div class="result-detail" style="margin-bottom: 40px; page-break-inside: avoid;">
                        <div style="background-color: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                            <h4 style="margin: 0 0 10px 0; color: #03045e;">
                                <?= ($index + 1) ?>. <?= htmlspecialchars($item['test_type']) ?>
                            </h4>
                            <div class="info-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                                <div class="info-item">
                                    <span class="info-label">Status:</span>
                                    <span class="status-badge"><?= ucfirst(str_replace('_', ' ', $item['status'])) ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Result Date:</span>
                                    <span><?= date('M d, Y g:i A', strtotime($item['result_date'])) ?></span>
                                </div>
                                <?php if ($item['remarks']): ?>
                                    <div class="info-item" style="grid-column: 1 / -1;">
                                        <span class="info-label">Remarks:</span>
                                        <span><?= htmlspecialchars($item['remarks']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($item['result_file']): ?>
                            <div class="result-file-container" style="border: 2px solid #03045e; border-radius: 8px; padding: 20px; background-color: white;">
                                <h5 style="margin: 0 0 15px 0; color: #03045e; text-align: center;">
                                    <i class="fas fa-file-pdf" style="color: #dc3545;"></i> Test Result Document
                                </h5>

                                <?php
                                // Convert BLOB to base64 for embedding
                                $fileData = $item['result_file'];
                                if ($fileData) {
                                    // Try to determine if it's a PDF by checking the header
                                    $isPDF = (substr($fileData, 0, 4) === '%PDF');

                                    if ($isPDF) {
                                        // For PDFs, embed as object for print
                                        $base64Data = base64_encode($fileData);
                                        echo '<div class="pdf-embed-container" style="width: 100%; min-height: 600px; border: 1px solid #ddd; border-radius: 4px; overflow: hidden;">';
                                        echo '<object data="data:application/pdf;base64,' . $base64Data . '" type="application/pdf" width="100%" height="600px" style="display: block;">';
                                        echo '<div style="padding: 20px; text-align: center; background-color: #f8f9fa;">';
                                        echo '<p><i class="fas fa-file-pdf" style="font-size: 48px; color: #dc3545; margin-bottom: 15px;"></i></p>';
                                        echo '<p><strong>PDF Result Document</strong></p>';
                                        echo '<p>This document contains the lab test results for ' . htmlspecialchars($item['test_type']) . '</p>';
                                        echo '<p><em>PDF content will be displayed when printed or viewed in a PDF-compatible browser.</em></p>';
                                        echo '</div>';
                                        echo '</object>';
                                        echo '</div>';
                                    } else {
                                        // For images, embed directly
                                        $imageData = base64_encode($fileData);
                                        // Try to determine image type from file header
                                        $imageType = 'image/png'; // default
                                        if (substr($fileData, 0, 3) === "\xFF\xD8\xFF") {
                                            $imageType = 'image/jpeg';
                                        } elseif (substr($fileData, 0, 8) === "\x89PNG\r\n\x1a\n") {
                                            $imageType = 'image/png';
                                        } elseif (substr($fileData, 0, 6) === 'GIF87a' || substr($fileData, 0, 6) === 'GIF89a') {
                                            $imageType = 'image/gif';
                                        }

                                        echo '<div class="image-result" style="text-align: center; padding: 20px;">';
                                        echo '<img src="data:' . $imageType . ';base64,' . $imageData . '" ';
                                        echo 'style="max-width: 100%; max-height: 800px; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" ';
                                        echo 'alt="Lab result for ' . htmlspecialchars($item['test_type']) . '">';
                                        echo '</div>';
                                    }
                                } else {
                                    echo '<div style="text-align: center; padding: 40px; color: #666;">';
                                    echo '<i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i>';
                                    echo '<p>Unable to load result file content.</p>';
                                    echo '</div>';
                                }
                                ?>
                            </div>
                        <?php else: ?>
                            <div style="text-align: center; padding: 40px; background-color: #f8f9fa; border-radius: 8px; color: #666;">
                                <i class="fas fa-file-times" style="font-size: 48px; margin-bottom: 15px;"></i>
                                <p>No result file attached for this test.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($index < count($items) - 1): ?>
                        <div style="page-break-after: always;"></div>
                    <?php endif; ?>

                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($order['remarks']): ?>
            <div style="margin-top: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 8px;">
                <h4 style="margin-top: 0; color: #03045e;">Remarks</h4>
                <p><?= htmlspecialchars($order['remarks']) ?></p>
            </div>
        <?php endif; ?>

        <div class="report-footer">
            <p>This is an electronically generated report from the Web-Based Healthcare Services Management System.</p>
            <p>Generated on <?= date('F d, Y \a\t g:i A') ?> | Lab Order #<?= $lab_order_id ?></p>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
</body>

</html>