<?php
/**
 * Test Print Invoice API - Database Query Only
 * Temporary test without authentication to isolate database issues
 */

// Root path for includes
$root_path = __DIR__;

require_once $root_path . '/config/db.php';

try {
    // Get parameters
    $billing_id = $_GET['billing_id'] ?? 4;
    $format = $_GET['format'] ?? 'json';
    
    $billing_id = intval($billing_id);
    
    echo "<h2>Testing Database Query for billing_id: $billing_id</h2>\n";
    
    // Test each table individually first
    echo "<h3>1. Testing Billing Table</h3>\n";
    $billing_test = "SELECT billing_id, total_amount, paid_amount, discount_amount, discount_type, net_amount, billing_date, payment_status, notes, visit_id, patient_id, created_by FROM billing WHERE billing_id = ?";
    $stmt = $pdo->prepare($billing_test);
    $stmt->execute([$billing_id]);
    $billing_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($billing_result) {
        echo "✅ Billing table query successful<br>\n";
        echo "Patient ID: " . $billing_result['patient_id'] . "<br>\n";
        echo "Visit ID: " . $billing_result['visit_id'] . "<br>\n";
        echo "Created by: " . $billing_result['created_by'] . "<br>\n";
    } else {
        echo "❌ Billing record not found<br>\n";
    }
    
    echo "<h3>2. Testing Patient Table</h3>\n";
    $patient_test = "SELECT patient_id, first_name, last_name, username, contact_number, date_of_birth, sex, email, barangay_id FROM patients WHERE patient_id = ?";
    $stmt = $pdo->prepare($patient_test);
    $stmt->execute([$billing_result['patient_id']]);
    $patient_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($patient_result) {
        echo "✅ Patient table query successful<br>\n";
        echo "Name: " . $patient_result['first_name'] . " " . $patient_result['last_name'] . "<br>\n";
        echo "Barangay ID: " . $patient_result['barangay_id'] . "<br>\n";
    } else {
        echo "❌ Patient record not found<br>\n";
    }
    
    echo "<h3>3. Testing Barangay Table</h3>\n";
    $barangay_test = "SELECT barangay_id, barangay_name, city, province, zip_code FROM barangay WHERE barangay_id = ?";
    $stmt = $pdo->prepare($barangay_test);
    $stmt->execute([$patient_result['barangay_id']]);
    $barangay_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($barangay_result) {
        echo "✅ Barangay table query successful<br>\n";
        echo "Address: " . $barangay_result['barangay_name'] . ", " . $barangay_result['city'] . ", " . $barangay_result['province'] . " " . $barangay_result['zip_code'] . "<br>\n";
    } else {
        echo "❌ Barangay record not found<br>\n";
    }
    
    echo "<h3>4. Testing Visit Table</h3>\n";
    $visit_test = "SELECT visit_id, visit_date, remarks FROM visits WHERE visit_id = ?";
    $stmt = $pdo->prepare($visit_test);
    $stmt->execute([$billing_result['visit_id']]);
    $visit_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($visit_result) {
        echo "✅ Visit table query successful<br>\n";
        echo "Visit Date: " . $visit_result['visit_date'] . "<br>\n";
        echo "Remarks: " . $visit_result['remarks'] . "<br>\n";
    } else {
        echo "❌ Visit record not found<br>\n";
    }
    
    echo "<h3>5. Testing Employee Table</h3>\n";
    $employee_test = "SELECT employee_id, first_name, last_name, employee_number FROM employees WHERE employee_id = ?";
    $stmt = $pdo->prepare($employee_test);
    $stmt->execute([$billing_result['created_by']]);
    $employee_result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($employee_result) {
        echo "✅ Employee table query successful<br>\n";
        echo "Created by: " . $employee_result['first_name'] . " " . $employee_result['last_name'] . "<br>\n";
        echo "Employee Number: " . $employee_result['employee_number'] . "<br>\n";
    } else {
        echo "❌ Employee record not found<br>\n";
    }
    
    echo "<h3>6. Testing Full JOIN Query</h3>\n";
    
    // Get invoice data - exact same query from print_invoice.php
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
    
    if ($invoice) {
        echo "✅ <strong>FULL JOIN QUERY SUCCESSFUL!</strong><br>\n";
        echo "<pre>" . print_r($invoice, true) . "</pre>\n";
        
        if ($format === 'json') {
            echo "<h3>JSON Output Test:</h3>\n";
            echo "<pre>" . json_encode($invoice, JSON_PRETTY_PRINT) . "</pre>\n";
        }
    } else {
        echo "❌ <strong>FULL JOIN QUERY FAILED - Invoice not found</strong><br>\n";
    }
    
} catch (PDOException $e) {
    echo "❌ <strong>DATABASE ERROR:</strong> " . $e->getMessage() . "<br>\n";
    echo "Error Code: " . $e->getCode() . "<br>\n";
    echo "SQL State: " . $e->errorInfo[0] . "<br>\n";
    
} catch (Exception $e) {
    echo "❌ <strong>GENERAL ERROR:</strong> " . $e->getMessage() . "<br>\n";
}
?>