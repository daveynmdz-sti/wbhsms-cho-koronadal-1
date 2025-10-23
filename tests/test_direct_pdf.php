<?php
/**
 * Direct test of invoice PDF generation without authentication
 * This will help us isolate any PDF generation issues
 */

// Set up paths and includes
$root_path = __DIR__;
require_once $root_path . '/vendor/autoload.php';
include $root_path . '/config/db.php';

use Dompdf\Dompdf;
use Dompdf\Options;

try {
    // Get a sample billing record directly
    $billing_id = 1;
    
    echo "<h2>Testing Direct PDF Generation for Billing ID: {$billing_id}</h2>";
    
    // Get invoice data directly (simplified version)
    $invoice_sql = "
        SELECT 
            b.billing_id,
            b.total_amount,
            b.paid_amount,
            b.discount_amount,
            b.discount_type,
            b.net_amount,
            b.billing_date,
            b.payment_status,
            b.notes,
            p.patient_id,
            p.first_name,
            p.last_name,
            p.username as patient_number,
            p.contact_number,
            p.date_of_birth,
            p.sex,
            TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
        FROM billing b
        JOIN patients p ON b.patient_id = p.patient_id
        WHERE b.billing_id = ?
    ";
    
    $stmt = $pdo->prepare($invoice_sql);
    $stmt->execute([$billing_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        die("Invoice not found with ID: {$billing_id}");
    }
    
    echo "<p>✓ Invoice data retrieved successfully</p>";
    
    // Get invoice items
    $items_sql = "
        SELECT 
            bi.billing_item_id,
            bi.service_item_id,
            bi.quantity,
            bi.item_price,
            bi.subtotal,
            si.item_name,
            si.item_description,
            sc.category_name,
            si.unit_of_measure
        FROM billing_items bi
        LEFT JOIN service_items si ON bi.service_item_id = si.service_item_id
        LEFT JOIN service_categories sc ON si.category_id = sc.category_id
        WHERE bi.billing_id = ?
        ORDER BY si.item_name
    ";
    
    $stmt = $pdo->prepare($items_sql);
    $stmt->execute([$billing_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>✓ Invoice items retrieved: " . count($items) . " items</p>";
    
    // Configure PDF options
    $options = new Options();
    $options->set('defaultFont', 'Arial');
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', false);
    
    // Create PDF generator
    $dompdf = new Dompdf($options);
    
    // Generate simple HTML for testing
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Invoice #' . htmlspecialchars($invoice['billing_id']) . '</title>
        <style>
            body { 
                font-family: Arial, sans-serif; 
                margin: 20px; 
                line-height: 1.4;
            }
            .header { 
                text-align: center; 
                color: #0077b6; 
                font-size: 24px; 
                margin-bottom: 20px; 
                border-bottom: 2px solid #0077b6;
                padding-bottom: 10px;
            }
            .info { 
                border: 1px solid #ccc; 
                padding: 15px; 
                margin: 10px 0; 
                background: #f9f9f9;
            }
            .patient-info {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin: 20px 0;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 15px 0;
            }
            th, td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
            }
            th {
                background-color: #0077b6;
                color: white;
            }
            .total-row {
                font-weight: bold;
                background-color: #f0f8ff;
            }
        </style>
    </head>
    <body>
        <div class="header">
            CITY HEALTH OFFICE KORONADAL<br>
            <small>INVOICE #' . htmlspecialchars($invoice['billing_id']) . '</small>
        </div>
        
        <div class="patient-info">
            <div class="info">
                <strong>Patient Information</strong><br>
                Name: ' . htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']) . '<br>
                Patient ID: ' . htmlspecialchars($invoice['patient_number']) . '<br>
                Age: ' . htmlspecialchars($invoice['age']) . ' years<br>
                Sex: ' . htmlspecialchars($invoice['sex']) . '<br>
                Contact: ' . htmlspecialchars($invoice['contact_number'] ?: 'N/A') . '
            </div>
            <div class="info">
                <strong>Invoice Details</strong><br>
                Date: ' . date('F j, Y', strtotime($invoice['billing_date'])) . '<br>
                Status: ' . htmlspecialchars(ucfirst($invoice['payment_status'])) . '<br>
                Total Amount: ₱' . number_format($invoice['net_amount'], 2) . '<br>
                Paid Amount: ₱' . number_format($invoice['paid_amount'], 2) . '<br>
                Balance: ₱' . number_format($invoice['net_amount'] - $invoice['paid_amount'], 2) . '
            </div>
        </div>';
    
    if (!empty($items)) {
        $html .= '
        <h3>Services & Items</h3>
        <table>
            <thead>
                <tr>
                    <th>Service/Item</th>
                    <th>Category</th>
                    <th>Qty</th>
                    <th>Unit Price</th>
                    <th>Subtotal</th>
                </tr>
            </thead>
            <tbody>';
        
        foreach ($items as $item) {
            $html .= '<tr>
                <td>' . htmlspecialchars($item['item_name'] ?: 'Unknown Item') . '</td>
                <td>' . htmlspecialchars($item['category_name'] ?: 'General') . '</td>
                <td>' . htmlspecialchars($item['quantity']) . '</td>
                <td>₱' . number_format($item['item_price'], 2) . '</td>
                <td>₱' . number_format($item['subtotal'], 2) . '</td>
            </tr>';
        }
        
        $html .= '
                <tr class="total-row">
                    <td colspan="4"><strong>Total Amount</strong></td>
                    <td><strong>₱' . number_format($invoice['net_amount'], 2) . '</strong></td>
                </tr>
            </tbody>
        </table>';
    }
    
    $html .= '
        <div class="info">
            <p><strong>Thank you for choosing City Health Office Koronadal!</strong></p>
            <p><em>This is a computer-generated invoice.</em></p>
        </div>
    </body>
    </html>';
    
    // Load HTML into DomPDF
    $dompdf->loadHtml($html);
    
    // Set paper size and orientation
    $dompdf->setPaper('A4', 'portrait');
    
    // Render PDF
    $dompdf->render();
    
    echo "<p>✓ PDF generation completed successfully</p>";
    
    // Set headers for PDF download
    $filename = 'test_invoice_' . $billing_id . '_' . date('Ymd_His') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($dompdf->output()));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Output PDF
    echo $dompdf->output();
    
} catch (Exception $e) {
    echo "<p>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Stack trace:</p><pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>