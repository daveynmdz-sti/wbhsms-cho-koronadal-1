<?php
/**
 * Test PDF Invoice Generation
 * Direct test of PDF functionality without authentication
 */

// Set up paths
$root_path = __DIR__;
require_once $root_path . '/vendor/autoload.php';
include $root_path . '/config/db.php';

use Dompdf\Dompdf;
use Dompdf\Options;

try {
    // Get a sample billing record
    $stmt = $pdo->query("SELECT * FROM billing LIMIT 1");
    $billing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$billing) {
        die("No billing records found for testing.");
    }
    
    echo "<h2>Testing PDF Generation for Billing ID: {$billing['billing_id']}</h2>";
    
    // Test 1: Check if DomPDF loads
    echo "<p>✓ DomPDF library loaded successfully</p>";
    
    // Test 2: Create basic PDF
    $options = new Options();
    $options->set('defaultFont', 'Arial');
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', false);
    
    $dompdf = new Dompdf($options);
    
    // Simple test HTML
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Test Invoice PDF</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            .header { text-align: center; color: #0077b6; font-size: 24px; margin-bottom: 20px; }
            .info { border: 1px solid #ccc; padding: 15px; margin: 10px 0; }
        </style>
    </head>
    <body>
        <div class="header">TEST INVOICE PDF</div>
        <div class="info">
            <strong>Billing ID:</strong> ' . htmlspecialchars($billing['billing_id']) . '<br>
            <strong>Patient ID:</strong> ' . htmlspecialchars($billing['patient_id']) . '<br>
            <strong>Amount:</strong> ₱' . number_format($billing['net_amount'], 2) . '<br>
            <strong>Status:</strong> ' . htmlspecialchars($billing['payment_status']) . '<br>
            <strong>Date:</strong> ' . htmlspecialchars($billing['billing_date']) . '
        </div>
        <div class="info">
            <p>This is a test PDF generation for the CHO Koronadal billing system.</p>
            <p>If you can see this PDF, the PDF generation functionality is working correctly.</p>
        </div>
    </body>
    </html>';
    
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    echo "<p>✓ PDF generation test completed successfully</p>";
    echo "<p><strong>Test Summary:</strong></p>";
    echo "<ul>";
    echo "<li>DomPDF Library: ✓ Working</li>";
    echo "<li>Database Connection: ✓ Working</li>";
    echo "<li>PDF Rendering: ✓ Working</li>";
    echo "<li>Sample Data: ✓ Available</li>";
    echo "</ul>";
    
    echo "<p><a href='api/billing/management/print_invoice.php?billing_id={$billing['billing_id']}&format=html' target='_blank'>Test HTML Invoice</a></p>";
    echo "<p><strong>Note:</strong> PDF download requires authentication. Test from the admin billing overview page.</p>";
    
} catch (Exception $e) {
    echo "<p>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Stack trace:</p><pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>