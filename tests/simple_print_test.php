<?php
/**
 * Simple Print Invoice Test - No Authentication Required
 * Tests the exact database query and output logic
 */

// Root path for includes  
$root_path = __DIR__;
require_once $root_path . '/config/db.php';

// Force output headers
header('Content-Type: text/html; charset=UTF-8');

try {
    $billing_id = $_GET['billing_id'] ?? 4;
    $format = $_GET['format'] ?? 'html';
    
    $billing_id = intval($billing_id);
    
    if (!$billing_id) {
        throw new Exception('billing_id is required');
    }
    
    // Exact same query from print_invoice.php
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
            b.visit_id,
            p.patient_id,
            p.first_name,
            p.last_name,
            p.username as patient_number,
            p.contact_number,
            p.date_of_birth,
            p.sex,
            p.email,
            bg.barangay_name,
            bg.city,
            bg.province,
            bg.zip_code,
            v.visit_date,
            v.remarks as visit_purpose,
            e.first_name as created_by_first_name,
            e.last_name as created_by_last_name,
            e.employee_number,
            TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
        FROM billing b
        JOIN patients p ON b.patient_id = p.patient_id
        LEFT JOIN barangay bg ON p.barangay_id = bg.barangay_id
        LEFT JOIN visits v ON b.visit_id = v.visit_id
        JOIN employees e ON b.created_by = e.employee_id
        WHERE b.billing_id = ?
    ";
    
    $stmt = $pdo->prepare($invoice_sql);
    $stmt->execute([$billing_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception('Invoice not found for billing_id: ' . $billing_id);
    }
    
    // Get invoice items (fixed service categories)
    $items_sql = "
        SELECT 
            bi.billing_item_id,
            bi.service_item_id,
            bi.item_price,
            bi.quantity,
            bi.subtotal,
            si.item_name,
            si.price_php as item_description,
            si.unit as unit_of_measure,
            s.name as category_name
        FROM billing_items bi
        JOIN service_items si ON bi.service_item_id = si.item_id
        LEFT JOIN services s ON si.service_id = s.service_id
        WHERE bi.billing_id = ?
        ORDER BY s.name, si.item_name
    ";
    
    $stmt = $pdo->prepare($items_sql);
    $stmt->execute([$billing_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Success - show results
    echo "<h2>✅ DATABASE QUERIES SUCCESSFUL!</h2>\n";
    echo "<p><strong>Billing ID:</strong> " . $billing_id . "</p>\n";
    echo "<p><strong>Patient:</strong> " . $invoice['first_name'] . " " . $invoice['last_name'] . "</p>\n";
    echo "<p><strong>Address:</strong> " . $invoice['barangay_name'] . ", " . $invoice['city'] . ", " . $invoice['province'] . " " . $invoice['zip_code'] . "</p>\n";
    echo "<p><strong>Visit Purpose:</strong> " . $invoice['visit_purpose'] . "</p>\n";
    echo "<p><strong>Created By:</strong> " . $invoice['created_by_first_name'] . " " . $invoice['created_by_last_name'] . "</p>\n";
    echo "<p><strong>Items Count:</strong> " . count($items) . "</p>\n";
    
    if ($format === 'json') {
        echo "<h3>JSON Format Test:</h3>\n";
        echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px;'>";
        echo json_encode([
            'success' => true,
            'invoice' => $invoice,
            'items' => $items
        ], JSON_PRETTY_PRINT);
        echo "</pre>\n";
    } else {
        echo "<h3>HTML Format Test:</h3>\n";
        echo "<div style='border: 1px solid #ccc; padding: 20px; margin: 10px 0; background: white;'>\n";
        echo "<h4>Invoice #" . str_pad($invoice['billing_id'], 8, '0', STR_PAD_LEFT) . "</h4>\n";
        echo "<p><strong>Patient:</strong> " . $invoice['first_name'] . " " . $invoice['last_name'] . "</p>\n";
        echo "<p><strong>Date:</strong> " . $invoice['billing_date'] . "</p>\n";
        echo "<p><strong>Total:</strong> ₱" . number_format($invoice['net_amount'], 2) . "</p>\n";
        echo "<p><strong>Status:</strong> " . strtoupper($invoice['payment_status']) . "</p>\n";
        echo "</div>\n";
    }
    
    echo "<p style='color: green; font-weight: bold;'>✅ All database operations completed successfully!</p>\n";
    echo "<p><strong>Conclusion:</strong> The database schema and queries are working correctly.</p>\n";
    echo "<p><strong>If you're still seeing errors:</strong> The issue is likely with session authentication, not the database.</p>\n";
    
} catch (PDOException $e) {
    echo "<h2 style='color: red;'>❌ DATABASE ERROR</h2>\n";
    echo "<p><strong>Error:</strong> " . $e->getMessage() . "</p>\n";
    echo "<p><strong>Error Code:</strong> " . $e->getCode() . "</p>\n";
    echo "<p><strong>SQL State:</strong> " . $e->errorInfo[0] . "</p>\n";
    
    // Return JSON error like the original API
    if ($_GET['format'] === 'json') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Database error occurred',
            'debug' => $e->getMessage()
        ]);
    }
    
} catch (Exception $e) {
    echo "<h2 style='color: red;'>❌ ERROR</h2>\n";
    echo "<p><strong>Message:</strong> " . $e->getMessage() . "</p>\n";
    
    if ($_GET['format'] === 'json') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
?>