<?php
/**
 * Simple test for billing invoice API
 */

// Set up basic environment
$_GET['billing_id'] = 1; // Test billing ID 1
session_start();
$_SESSION['patient_id'] = 7;

$root_path = 'C:/xampp/htdocs/wbhsms-cho-koronadal-1';
require_once $root_path . '/config/db.php';

try {
    echo "Testing Invoice API Fix\n";
    echo "======================\n";
    
    $billing_id = 1;
    $patient_id = 7;
    
    // Test the corrected query
    $invoice_sql = "
        SELECT 
            b.billing_id,
            b.patient_id,
            b.visit_id,
            b.total_amount,
            b.paid_amount,
            b.discount_amount,
            b.discount_type,
            b.net_amount,
            b.payment_status,
            b.billing_date,
            b.notes,
            b.created_at,
            b.updated_at,
            p.first_name,
            p.last_name,
            p.username as patient_number,
            p.contact_number as phone_number,
            p.email
        FROM billing b
        LEFT JOIN patients p ON b.patient_id = p.patient_id
        WHERE b.billing_id = ? AND b.patient_id = ?
    ";
    
    $stmt = $pdo->prepare($invoice_sql);
    $stmt->execute([$billing_id, $patient_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($invoice) {
        echo "SUCCESS: Invoice query works!\n";
        echo "Patient: " . $invoice['first_name'] . " " . $invoice['last_name'] . "\n";
        echo "Amount: $" . $invoice['total_amount'] . "\n";
        echo "Status: " . $invoice['payment_status'] . "\n\n";
    } else {
        echo "ERROR: No invoice found\n";
    }
    
    // Test billing items query
    $items_sql = "
        SELECT 
            bi.billing_item_id,
            bi.service_item_id,
            bi.quantity,
            bi.item_price as unit_price,
            bi.subtotal,
            si.item_name,
            si.unit,
            si.service_id
        FROM billing_items bi
        LEFT JOIN service_items si ON bi.service_item_id = si.item_id
        WHERE bi.billing_id = ?
        ORDER BY bi.billing_item_id
    ";
    
    $stmt = $pdo->prepare($items_sql);
    $stmt->execute([$billing_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Items found: " . count($items) . "\n";
    foreach ($items as $item) {
        echo "- " . $item['item_name'] . " (Qty: " . $item['quantity'] . ", Price: $" . $item['unit_price'] . ")\n";
    }
    
    echo "\nAPI should now work correctly!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>