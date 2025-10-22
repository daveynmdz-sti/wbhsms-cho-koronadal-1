<?php
// Direct Print Invoice - Administrative access
$root_path = dirname(dirname(dirname(dirname(dirname(__DIR__)))));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Include PDF libraries at global scope
require_once $root_path . '/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// Check authentication
if (!is_employee_logged_in()) {
    echo "<script>alert('Authentication required'); window.close();</script>";
    exit();
}

$employee_role = get_employee_session('role');
if (!in_array($employee_role, ['admin', 'cashier'])) {
    echo "<script>alert('Access denied'); window.close();</script>";
    exit();
}

// Get parameters
$billing_id = $_GET['billing_id'] ?? null;
$format = $_GET['format'] ?? 'html';

if (!$billing_id || !is_numeric($billing_id)) {
    echo "<script>alert('Invalid billing ID'); window.close();</script>";
    exit();
}

$billing_id = intval($billing_id);

// Generate content using existing receipt generator
try {
    require_once $root_path . '/utils/receipt_generator.php';
    
    // Get invoice data
    $invoice_sql = "
        SELECT 
            b.*,
            p.first_name, p.last_name, p.middle_name, p.username as patient_number,
            bg.barangay_name,
            CONCAT(e.first_name, ' ', e.last_name) as created_by_name
        FROM billing b
        LEFT JOIN patients p ON b.patient_id = p.patient_id  
        LEFT JOIN barangay bg ON p.barangay_id = bg.barangay_id
        LEFT JOIN employees e ON b.created_by = e.employee_id
        WHERE b.billing_id = ?
    ";
    
    $stmt = $pdo->prepare($invoice_sql);
    $stmt->execute([$billing_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        echo "<script>alert('Invoice not found'); window.close();</script>";
        exit();
    }
    
    // PDF format
    if ($format === 'pdf') {
        // Get HTML content
        $htmlContent = generateInvoiceHTML($pdo, $billing_id, true);
        
        // Configure PDF options
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        
        // Initialize DOMPDF
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($htmlContent);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        // Set headers for PDF download
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="invoice_' . $billing_id . '_' . date('Ymd_His') . '.pdf"');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        
        // Output PDF
        echo $dompdf->output();
        exit();
    }
    
    // HTML format (for printing)
    $htmlContent = generateInvoiceHTML($pdo, $billing_id, false);
    
    // Add print styles and auto-print
    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Invoice #' . $billing_id . '</title>
    <style>
        @media print {
            @page { margin: 1cm; }
            body { margin: 0; font-family: Arial, sans-serif; }
            .no-print { display: none; }
        }
        @media screen {
            body { padding: 20px; font-family: Arial, sans-serif; }
            .print-button { 
                position: fixed; top: 10px; right: 10px; 
                background: #007bff; color: white; border: none; 
                padding: 10px 20px; border-radius: 5px; cursor: pointer;
            }
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">Print</button>
    ' . $htmlContent . '
    <script>
        // Auto-print for direct print requests
        if (window.location.search.includes("auto=1")) {
            window.onload = function() { 
                setTimeout(function() { window.print(); }, 500);
            };
        }
    </script>
</body>
</html>';

} catch (Exception $e) {
    error_log("Print invoice error: " . $e->getMessage());
    echo "<script>alert('Error generating invoice'); window.close();</script>";
}

// Helper function to generate invoice HTML
function generateInvoiceHTML($pdo, $billing_id, $isPDF = false) {
    // Get invoice details
    $invoice_sql = "
        SELECT 
            b.*,
            p.first_name, p.last_name, p.middle_name, p.username as patient_number,
            bg.barangay_name
        FROM billing b
        LEFT JOIN patients p ON b.patient_id = p.patient_id  
        LEFT JOIN barangay bg ON p.barangay_id = bg.barangay_id
        WHERE b.billing_id = ?
    ";
    
    $stmt = $pdo->prepare($invoice_sql);
    $stmt->execute([$billing_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception('Invoice not found');
    }
    
    // Get billing items
    $items_sql = "
        SELECT 
            bi.*,
            bc.category_name
        FROM billing_items bi
        LEFT JOIN billing_categories bc ON bi.category_id = bc.category_id
        WHERE bi.billing_id = ?
        ORDER BY bi.item_name
    ";
    
    $stmt = $pdo->prepare($items_sql);
    $stmt->execute([$billing_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get payments
    $payments_sql = "
        SELECT 
            r.*,
            CONCAT(e.first_name, ' ', e.last_name) as received_by
        FROM receipts r
        LEFT JOIN employees e ON r.received_by_employee_id = e.employee_id
        WHERE r.billing_id = ?
        ORDER BY r.payment_date DESC
    ";
    
    $stmt = $pdo->prepare($payments_sql);
    $stmt->execute([$billing_id]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate HTML
    $html = '
    <div style="max-width: 800px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;">
        <!-- Header -->
        <div style="text-align: center; margin-bottom: 30px; border-bottom: 2px solid #007bff; padding-bottom: 20px;">
            <h1 style="color: #007bff; margin: 0;">City Health Office</h1>
            <p style="margin: 5px 0; color: #666;">Koronadal City, South Cotabato</p>
            <p style="margin: 5px 0; color: #666;">Phone: (083) 228-xxxx</p>
            <h2 style="margin: 20px 0 0 0; color: #333;">INVOICE #' . $invoice['billing_id'] . '</h2>
        </div>
        
        <!-- Invoice Info -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
            <div>
                <h3 style="color: #007bff; margin-bottom: 15px;">Patient Information</h3>
                <p><strong>Name:</strong> ' . htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']) . '</p>
                <p><strong>Patient ID:</strong> ' . htmlspecialchars($invoice['patient_number'] ?: 'N/A') . '</p>
                <p><strong>Barangay:</strong> ' . htmlspecialchars($invoice['barangay_name'] ?: 'N/A') . '</p>
            </div>
            <div>
                <h3 style="color: #007bff; margin-bottom: 15px;">Invoice Details</h3>
                <p><strong>Date:</strong> ' . date('F j, Y', strtotime($invoice['billing_date'])) . '</p>
                <p><strong>Status:</strong> <span style="padding: 3px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; text-transform: uppercase; background: ' . 
                ($invoice['payment_status'] == 'paid' ? '#d4edda; color: #155724' : 
                 ($invoice['payment_status'] == 'partial' ? '#fff3cd; color: #856404' : '#f8d7da; color: #721c24')) . ';">' . 
                ucfirst($invoice['payment_status']) . '</span></p>
            </div>
        </div>
        
        <!-- Items Table -->
        <h3 style="color: #007bff; margin-bottom: 15px;">Services & Items</h3>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px; border: 1px solid #ddd;">
            <thead>
                <tr style="background: #f8f9fa;">
                    <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Service/Item</th>
                    <th style="padding: 12px; text-align: left; border: 1px solid #ddd;">Category</th>
                    <th style="padding: 12px; text-align: right; border: 1px solid #ddd;">Qty</th>
                    <th style="padding: 12px; text-align: right; border: 1px solid #ddd;">Price</th>
                    <th style="padding: 12px; text-align: right; border: 1px solid #ddd;">Subtotal</th>
                </tr>
            </thead>
            <tbody>';
    
    if (empty($items)) {
        $html .= '<tr><td colspan="5" style="padding: 20px; text-align: center; color: #666; border: 1px solid #ddd;">No items found</td></tr>';
    } else {
        foreach ($items as $item) {
            $html .= '<tr>
                <td style="padding: 10px; border: 1px solid #ddd;">' . htmlspecialchars($item['item_name']) . '</td>
                <td style="padding: 10px; border: 1px solid #ddd;">' . htmlspecialchars($item['category_name'] ?: 'General') . '</td>
                <td style="padding: 10px; text-align: right; border: 1px solid #ddd;">' . $item['quantity'] . '</td>
                <td style="padding: 10px; text-align: right; border: 1px solid #ddd;">₱' . number_format($item['item_price'], 2) . '</td>
                <td style="padding: 10px; text-align: right; border: 1px solid #ddd;">₱' . number_format($item['subtotal'], 2) . '</td>
            </tr>';
        }
    }
    
    $html .= '</tbody>
        </table>
        
        <!-- Totals -->
        <div style="float: right; width: 300px; border: 1px solid #ddd; padding: 15px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span>Subtotal:</span>
                <span>₱' . number_format($invoice['total_amount'], 2) . '</span>
            </div>';
    
    if ($invoice['discount_amount'] > 0) {
        $html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span>Discount:</span>
                <span>-₱' . number_format($invoice['discount_amount'], 2) . '</span>
            </div>';
    }
    
    $html .= '<div style="display: flex; justify-content: space-between; margin-bottom: 8px; font-weight: bold; border-top: 2px solid #007bff; padding-top: 8px;">
                <span>Total Amount:</span>
                <span>₱' . number_format($invoice['net_amount'], 2) . '</span>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                <span>Amount Paid:</span>
                <span>₱' . number_format($invoice['paid_amount'], 2) . '</span>
            </div>
            <div style="display: flex; justify-content: space-between; color: ' . (($invoice['net_amount'] - $invoice['paid_amount']) > 0 ? '#dc3545' : '#28a745') . ';">
                <span>Balance:</span>
                <span>₱' . number_format($invoice['net_amount'] - $invoice['paid_amount'], 2) . '</span>
            </div>
        </div>
        
        <div style="clear: both;"></div>';
    
    // Payment history
    if (!empty($payments)) {
        $html .= '<div style="margin-top: 40px;">
            <h3 style="color: #007bff; margin-bottom: 15px;">Payment History</h3>';
        
        foreach ($payments as $payment) {
            $html .= '<div style="border: 1px solid #ddd; padding: 10px; margin-bottom: 10px; display: flex; justify-content: space-between;">
                <div>
                    <strong>' . htmlspecialchars($payment['payment_method']) . '</strong><br>
                    <small>' . date('F j, Y g:i A', strtotime($payment['payment_date'])) . '</small>
                    ' . ($payment['receipt_number'] ? '<br><small>Receipt #' . htmlspecialchars($payment['receipt_number']) . '</small>' : '') . '
                </div>
                <div style="text-align: right;">
                    <strong style="color: #28a745;">₱' . number_format($payment['amount_paid'], 2) . '</strong>
                </div>
            </div>';
        }
        
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}
?>