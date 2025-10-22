<?php
require_once 'config/db.php';

echo "<h2>Billing Data Test</h2>";

try {
    // Check if billing table exists and has data
    $sql = "SELECT COUNT(*) as total FROM billing";
    $result = $pdo->query($sql);
    $count = $result->fetchColumn();
    
    echo "<p><strong>Total invoices in database:</strong> " . $count . "</p>";
    
    if ($count > 0) {
        // Show sample data
        $sql = "SELECT b.billing_id, b.patient_id, b.billing_date, b.payment_status, 
                       b.total_amount, b.paid_amount, b.net_amount,
                       p.first_name, p.last_name 
                FROM billing b 
                LEFT JOIN patients p ON b.patient_id = p.patient_id 
                ORDER BY b.billing_date DESC 
                LIMIT 5";
        
        $result = $pdo->query($sql);
        $invoices = $result->fetchAll();
        
        echo "<h3>Recent Invoices:</h3>";
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>ID</th><th>Patient</th><th>Date</th><th>Status</th><th>Total</th><th>Paid</th><th>Net</th></tr>";
        
        foreach ($invoices as $invoice) {
            echo "<tr>";
            echo "<td>" . $invoice['billing_id'] . "</td>";
            echo "<td>" . htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']) . "</td>";
            echo "<td>" . $invoice['billing_date'] . "</td>";
            echo "<td>" . $invoice['payment_status'] . "</td>";
            echo "<td>₱" . number_format($invoice['total_amount'], 2) . "</td>";
            echo "<td>₱" . number_format($invoice['paid_amount'], 2) . "</td>";
            echo "<td>₱" . number_format($invoice['net_amount'], 2) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Test the exact query from billing_management.php
        echo "<h3>Testing Billing Management Query:</h3>";
        $testSql = "SELECT b.billing_id, b.patient_id, 
                     b.billing_date, 
                     b.payment_status, 
                     b.total_amount,
                     b.paid_amount,
                     b.net_amount,
                     b.discount_amount,
                     b.created_by, 
                     b.notes,
                     p.first_name, p.last_name, p.middle_name, 
                     COALESCE(p.username, p.patient_id) as patient_id_display, 
                     bg.barangay_name as barangay,
                     e.first_name as created_by_first_name, e.last_name as created_by_last_name,
                     (SELECT COUNT(*) FROM billing_items bi WHERE bi.billing_id = b.billing_id) as items_count
              FROM billing b
              LEFT JOIN patients p ON b.patient_id = p.patient_id
              LEFT JOIN barangay bg ON p.barangay_id = bg.barangay_id
              LEFT JOIN employees e ON b.created_by = e.employee_id
              WHERE 1=1
              ORDER BY b.billing_date DESC
              LIMIT 10";
        
        $testResult = $pdo->query($testSql);
        $testInvoices = $testResult->fetchAll();
        
        echo "<p><strong>Query returned:</strong> " . count($testInvoices) . " invoices</p>";
        
        if (count($testInvoices) > 0) {
            echo "<table border='1' cellpadding='5' cellspacing='0'>";
            echo "<tr><th>ID</th><th>Patient Name</th><th>Patient ID Display</th><th>Date</th><th>Status</th><th>Items Count</th></tr>";
            
            foreach ($testInvoices as $invoice) {
                echo "<tr>";
                echo "<td>" . $invoice['billing_id'] . "</td>";
                echo "<td>" . htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']) . "</td>";
                echo "<td>" . htmlspecialchars($invoice['patient_id_display']) . "</td>";
                echo "<td>" . $invoice['billing_date'] . "</td>";
                echo "<td>" . $invoice['payment_status'] . "</td>";
                echo "<td>" . $invoice['items_count'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>Error:</strong> " . $e->getMessage() . "</p>";
}
?>