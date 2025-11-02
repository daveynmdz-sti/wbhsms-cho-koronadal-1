<?php
/**
 * Billing System Test Helper
 * Purpose: Quick tests to verify billing fixes are working
 */

// Include configuration
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in
if (!is_employee_logged_in()) {
    echo '<h3>Please log in as an employee to run these tests.</h3>';
    exit();
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Billing System Test Helper</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .test-section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .info { background: #d1ecf1; color: #0c5460; }
        button { padding: 10px 15px; margin: 5px; cursor: pointer; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>🔧 Billing System Test Helper</h1>
    <p>Use this page to test the billing system fixes.</p>

    <div class="test-section info">
        <h3>📋 Test Checklist</h3>
        <p><strong>Fix 1 - Receipt Viewing:</strong></p>
        <ul>
            <li>✅ Go to <a href="billing_management.php" target="_blank">Billing Management</a></li>
            <li>✅ View invoice details for any invoice with payments</li>
            <li>✅ Check if payment history shows "View" and "Print" buttons</li>
            <li>✅ Click "View" button to see receipt modal</li>
            <li>✅ Click "Print" button to open receipt print window</li>
        </ul>
        
        <p><strong>Fix 2 - Partial Payment Processing:</strong></p>
        <ul>
            <li>✅ Go to <a href="process_payment.php" target="_blank">Process Payment</a></li>
            <li>✅ Search for a patient with unpaid/partial invoices</li>
            <li>✅ Make a partial payment (less than full amount)</li>
            <li>✅ Search for same patient again - should still appear</li>
            <li>✅ Make final payment to complete the invoice</li>
            <li>✅ Verify patient no longer appears in search</li>
        </ul>
    </div>

    <div class="test-section">
        <h3>🔍 API Endpoint Tests</h3>
        <button onclick="testReceiptAPI()">Test Receipt API</button>
        <button onclick="testPrintAPI()">Test Print API</button>
        <div id="api-results"></div>
    </div>

    <div class="test-section">
        <h3>💾 Database Query Tests</h3>
        <button onclick="checkReceiptData()">Check Receipt Records</button>
        <button onclick="checkPartialPayments()">Check Partial Payments</button>
        <div id="db-results"></div>
    </div>

    <script>
        function testReceiptAPI() {
            const resultsDiv = document.getElementById('api-results');
            resultsDiv.innerHTML = '<p>Testing receipt API...</p>';
            
            // Get a sample receipt number
            fetch('api/get_receipt_details.php?receipt_number=RCP-20251022-000001')
                .then(response => response.json())
                .then(data => {
                    resultsDiv.innerHTML = `
                        <h4>Receipt API Test Results:</h4>
                        <pre>${JSON.stringify(data, null, 2)}</pre>
                    `;
                })
                .catch(error => {
                    resultsDiv.innerHTML = `
                        <h4>Receipt API Test - Error:</h4>
                        <pre class="error">${error.message}</pre>
                    `;
                });
        }

        function testPrintAPI() {
            const resultsDiv = document.getElementById('api-results');
            resultsDiv.innerHTML = '<p>Testing print API...</p>';
            
            // Open print API in new window
            const printWindow = window.open('api/print_receipt.php?receipt_number=RCP-20251022-000001&format=html', '_blank');
            
            resultsDiv.innerHTML = `
                <h4>Print API Test:</h4>
                <p>Print window should have opened. Check if receipt displays correctly.</p>
            `;
        }

        function checkReceiptData() {
            const resultsDiv = document.getElementById('db-results');
            resultsDiv.innerHTML = '<p>Checking database records...</p>';
            
            // This would need a server-side endpoint to check DB
            resultsDiv.innerHTML = `
                <h4>Database Check:</h4>
                <p>Go to your database and run these queries:</p>
                <pre>
-- Check receipt records
SELECT COUNT(*) as receipt_count FROM receipts;

-- Check payments with receipts
SELECT p.payment_id, p.receipt_number, p.amount_paid, p.change_amount 
FROM payments p 
WHERE p.receipt_number IS NOT NULL 
LIMIT 5;

-- Check partial payment invoices
SELECT b.billing_id, b.payment_status, b.net_amount, b.paid_amount,
       (b.net_amount - b.paid_amount) as remaining
FROM billing b 
WHERE b.payment_status = 'partial' 
LIMIT 5;
                </pre>
            `;
        }

        function checkPartialPayments() {
            const resultsDiv = document.getElementById('db-results');
            resultsDiv.innerHTML = `
                <h4>Partial Payment Verification:</h4>
                <p>To verify partial payments work:</p>
                <ol>
                    <li>Create a test invoice with amount ₱1000</li>
                    <li>Make a partial payment of ₱600</li>
                    <li>Check invoice status should be 'partial'</li>
                    <li>Search should still show this invoice</li>
                    <li>Make final payment of ₱400</li>
                    <li>Check invoice status should be 'paid'</li>
                    <li>Search should no longer show this invoice</li>
                </ol>
            `;
        }
    </script>
</body>
</html>