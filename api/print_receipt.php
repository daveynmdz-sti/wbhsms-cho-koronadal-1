<?php
/**
 * Print Receipt API
 * Purpose: Generate printable receipt format
 * Method: GET
 * Parameters: receipt_number, format (html/pdf)
 */

// Include configuration
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in
if (!is_employee_logged_in()) {
    header('Location: ../pages/management/auth/employee_login.php');
    exit();
}

// Check if receipt_number is provided
if (!isset($_GET['receipt_number']) || empty($_GET['receipt_number'])) {
    http_response_code(400);
    echo '<h3>Error: Receipt number is required.</h3>';
    exit();
}

$receipt_number = trim($_GET['receipt_number']);
$format = $_GET['format'] ?? 'html';

try {
    // Query to get receipt details
    $sql = "SELECT 
                r.receipt_id,
                r.billing_id,
                r.receipt_number,
                r.payment_date,
                r.amount_paid,
                r.change_amount,
                r.payment_method,
                r.notes,
                p.first_name,
                p.last_name,
                p.username as patient_id,
                e.first_name as cashier_first_name,
                e.last_name as cashier_last_name,
                b.net_amount,
                b.total_amount,
                b.discount_amount
            FROM receipts r
            LEFT JOIN billing b ON r.billing_id = b.billing_id
            LEFT JOIN patients p ON b.patient_id = p.patient_id
            LEFT JOIN employees e ON r.received_by_employee_id = e.employee_id
            WHERE r.receipt_number = ?
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$receipt_number]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$receipt) {
        http_response_code(404);
        echo '<h3>Error: Receipt not found.</h3>';
        exit();
    }

    // Format receipt data
    $patient_name = trim(($receipt['first_name'] ?? '') . ' ' . ($receipt['last_name'] ?? ''));
    $cashier_name = trim(($receipt['cashier_first_name'] ?? '') . ' ' . ($receipt['cashier_last_name'] ?? ''));
    $payment_date = new DateTime($receipt['payment_date']);
    
    // Set content type based on format
    if ($format === 'html') {
        header('Content-Type: text/html');
        
        echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Receipt #' . htmlspecialchars($receipt['receipt_number']) . '</title>
    <style>
        body {
            font-family: "Courier New", monospace;
            margin: 0;
            padding: 20px;
            background: white;
            color: #000;
        }
        .receipt-container {
            max-width: 400px;
            margin: 0 auto;
            border: 1px solid #000;
            padding: 20px;
        }
        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .receipt-header h1 {
            margin: 0 0 5px 0;
            font-size: 18px;
        }
        .receipt-header h2 {
            margin: 0 0 10px 0;
            font-size: 16px;
            font-weight: normal;
        }
        .receipt-header p {
            margin: 2px 0;
            font-size: 12px;
        }
        .receipt-title {
            font-size: 14px;
            font-weight: bold;
            margin: 10px 0 5px 0;
        }
        .receipt-number {
            font-size: 14px;
            font-weight: bold;
        }
        .receipt-row {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
            font-size: 12px;
        }
        .receipt-section {
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 10px 0;
            margin: 15px 0;
        }
        .receipt-footer {
            text-align: center;
            border-top: 1px solid #000;
            padding-top: 15px;
            margin-top: 20px;
            font-size: 11px;
        }
        .receipt-total {
            font-weight: bold;
            font-size: 14px;
        }
        @media print {
            body { margin: 0; padding: 10px; }
            .receipt-container { border: none; }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <div class="receipt-header">
            <h1>CHO KORONADAL</h1>
            <h2>City Health Office</h2>
            <p>Koronadal City, South Cotabato</p>
            <p>Phone: (083) 228-xxxx</p>
            <div class="receipt-title">OFFICIAL RECEIPT</div>
            <div class="receipt-number">Receipt #: ' . htmlspecialchars($receipt['receipt_number']) . '</div>
        </div>

        <div class="receipt-row">
            <span>Date:</span>
            <span>' . $payment_date->format('M d, Y') . '</span>
        </div>
        <div class="receipt-row">
            <span>Time:</span>
            <span>' . $payment_date->format('g:i A') . '</span>
        </div>
        <div class="receipt-row">
            <span>Patient:</span>
            <span>' . htmlspecialchars($patient_name ?: 'N/A') . '</span>
        </div>
        <div class="receipt-row">
            <span>Patient ID:</span>
            <span>' . htmlspecialchars($receipt['patient_id'] ?: 'N/A') . '</span>
        </div>
        <div class="receipt-row">
            <span>Invoice #:</span>
            <span>' . htmlspecialchars($receipt['billing_id']) . '</span>
        </div>

        <div class="receipt-section">
            <div class="receipt-row">
                <span>Payment Method:</span>
                <span>' . strtoupper(htmlspecialchars($receipt['payment_method'] ?: 'CASH')) . '</span>
            </div>
            <div class="receipt-row receipt-total">
                <span>Amount Paid:</span>
                <span>₱' . number_format($receipt['amount_paid'], 2) . '</span>
            </div>';
            
        if ($receipt['change_amount'] > 0) {
            echo '<div class="receipt-row receipt-total">
                <span>Change:</span>
                <span>₱' . number_format($receipt['change_amount'], 2) . '</span>
            </div>';
        }
        
        echo '</div>

        <div class="receipt-row">
            <span>Received by:</span>
            <span>' . htmlspecialchars($cashier_name ?: 'N/A') . '</span>
        </div>';
        
        if (!empty($receipt['notes'])) {
            echo '<div style="margin-top: 15px;">
                <div><strong>Notes:</strong></div>
                <div style="font-style: italic; margin-top: 5px;">' . htmlspecialchars($receipt['notes']) . '</div>
            </div>';
        }
        
        echo '<div class="receipt-footer">
            <p>Thank you for your payment!</p>
            <p>This is an official receipt.</p>
            <p style="margin-top: 10px; font-size: 10px;">Printed on: ' . date('M d, Y g:i A') . '</p>
        </div>
    </div>

    <script>
        // Auto-print when loaded
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>';
    }
    
} catch (Exception $e) {
    error_log("Print Receipt API Error: " . $e->getMessage());
    error_log("Print Receipt API Trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo '<h3>Error: Unable to generate receipt. Please try again later.</h3>';
}
?>