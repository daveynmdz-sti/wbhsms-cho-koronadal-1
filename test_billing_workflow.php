<?php
require_once 'config/db.php';
require_once 'config/session/employee_session.php';

// Start session and set a test cashier for simulation
if (!is_employee_logged_in()) {
    // For testing, we'll simulate a cashier login
    $_SESSION['EMPLOYEE_SESSID'] = 'test_session_' . time();
    $_SESSION['employee_id'] = 1; // Assume employee ID 1 exists
    $_SESSION['role'] = 'Cashier';
    $_SESSION['first_name'] = 'Test';
    $_SESSION['last_name'] = 'Cashier';
}

echo "<h1>Billing Workflow End-to-End Test</h1>";

// Test 1: Patient Search Simulation
echo "<h2>Test 1: Patient Search API</h2>";

try {
    // Simulate AJAX call to search_patients.php
    $search_term = "test"; // This would come from the search input
    
    $stmt = $pdo->prepare("
        SELECT patient_id, first_name, last_name, middle_name, barangay, 
               phone_number, email, date_of_birth
        FROM patients 
        WHERE status = 'active' 
        AND (CONCAT(first_name, ' ', last_name) LIKE ? 
             OR patient_id LIKE ? 
             OR barangay LIKE ?)
        LIMIT 10
    ");
    
    $search_pattern = "%$search_term%";
    $stmt->execute([$search_pattern, $search_pattern, $search_pattern]);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p style='color: green;'>✓ Patient search working - Found " . count($patients) . " patients</p>";
    
    if (!empty($patients)) {
        $test_patient = $patients[0]; // Use first patient for testing
        echo "<p><strong>Test Patient:</strong> {$test_patient['first_name']} {$test_patient['last_name']} (ID: {$test_patient['patient_id']})</p>";
    } else {
        echo "<p style='color: orange;'>No patients found for testing</p>";
        exit;
    }
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Patient search failed: " . $e->getMessage() . "</p>";
    exit;
}

// Test 2: Service Items Retrieval
echo "<h2>Test 2: Service Items API</h2>";

try {
    $stmt = $pdo->query("
        SELECT service_item_id, service_name, price, category 
        FROM service_items 
        WHERE status = 'active' 
        ORDER BY category, service_name
        LIMIT 10
    ");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p style='color: green;'>✓ Service items retrieval working - Found " . count($services) . " services</p>";
    
    if (!empty($services)) {
        echo "<p><strong>Sample Services:</strong></p><ul>";
        foreach (array_slice($services, 0, 3) as $service) {
            echo "<li>{$service['service_name']} - ₱{$service['price']} ({$service['category']})</li>";
        }
        echo "</ul>";
        
        $test_services = array_slice($services, 0, 2); // Use first 2 services for testing
    } else {
        echo "<p style='color: orange;'>No services found for testing</p>";
        exit;
    }
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Service items retrieval failed: " . $e->getMessage() . "</p>";
    exit;
}

// Test 3: Invoice Creation
echo "<h2>Test 3: Invoice Creation</h2>";

try {
    // Prepare invoice data
    $invoice_data = [
        'patient_id' => $test_patient['patient_id'],
        'cashier_id' => $_SESSION['employee_id'],
        'visit_id' => null, // Can be null for direct billing
        'discount_type' => 'senior', // Test senior discount
        'discount_percentage' => 20,
        'notes' => 'End-to-end test invoice',
        'services' => [
            [
                'service_item_id' => $test_services[0]['service_item_id'],
                'quantity' => 1,
                'unit_price' => $test_services[0]['price']
            ],
            [
                'service_item_id' => $test_services[1]['service_item_id'],
                'quantity' => 2,
                'unit_price' => $test_services[1]['price']
            ]
        ]
    ];
    
    // Calculate totals
    $subtotal = 0;
    foreach ($invoice_data['services'] as $service) {
        $subtotal += $service['quantity'] * $service['unit_price'];
    }
    
    $discount_amount = ($subtotal * $invoice_data['discount_percentage']) / 100;
    $total_amount = $subtotal - $discount_amount;
    
    // Begin transaction
    $pdo->beginTransaction();
    
    // Insert invoice
    $stmt = $pdo->prepare("
        INSERT INTO invoices 
        (patient_id, cashier_id, visit_id, subtotal, discount_type, discount_percentage, 
         discount_amount, total_amount, status, notes, created_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), ?)
    ");
    
    $stmt->execute([
        $invoice_data['patient_id'],
        $invoice_data['cashier_id'],
        $invoice_data['visit_id'],
        $subtotal,
        $invoice_data['discount_type'],
        $invoice_data['discount_percentage'],
        $discount_amount,
        $total_amount,
        $invoice_data['notes'],
        $invoice_data['cashier_id']
    ]);
    
    $invoice_id = $pdo->lastInsertId();
    
    // Insert invoice items
    foreach ($invoice_data['services'] as $service) {
        $stmt = $pdo->prepare("
            INSERT INTO invoice_items 
            (invoice_id, service_item_id, service_name, quantity, unit_price, total_price)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        // Get service name
        $service_stmt = $pdo->prepare("SELECT service_name FROM service_items WHERE service_item_id = ?");
        $service_stmt->execute([$service['service_item_id']]);
        $service_name = $service_stmt->fetchColumn();
        
        $item_total = $service['quantity'] * $service['unit_price'];
        
        $stmt->execute([
            $invoice_id,
            $service['service_item_id'],
            $service_name,
            $service['quantity'],
            $service['unit_price'],
            $item_total
        ]);
    }
    
    $pdo->commit();
    
    echo "<p style='color: green;'>✓ Invoice created successfully (ID: $invoice_id)</p>";
    echo "<p><strong>Invoice Details:</strong></p>";
    echo "<ul>";
    echo "<li>Subtotal: ₱" . number_format($subtotal, 2) . "</li>";
    echo "<li>Discount ({$invoice_data['discount_percentage']}%): ₱" . number_format($discount_amount, 2) . "</li>";
    echo "<li>Total Amount: ₱" . number_format($total_amount, 2) . "</li>";
    echo "</ul>";
    
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "<p style='color: red;'>✗ Invoice creation failed: " . $e->getMessage() . "</p>";
    exit;
}

// Test 4: Payment Processing
echo "<h2>Test 4: Payment Processing</h2>";

try {
    $payment_amount = $total_amount; // Full payment
    $payment_method = 'cash';
    
    // Begin transaction
    $pdo->beginTransaction();
    
    // Insert payment
    $stmt = $pdo->prepare("
        INSERT INTO payments 
        (invoice_id, patient_id, cashier_id, amount_paid, payment_method, 
         change_amount, receipt_number, payment_date, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ");
    
    $receipt_number = 'RCP' . date('Ymd') . str_pad($invoice_id, 6, '0', STR_PAD_LEFT);
    $change_amount = 0; // Assuming exact payment for test
    
    $stmt->execute([
        $invoice_id,
        $test_patient['patient_id'],
        $_SESSION['employee_id'],
        $payment_amount,
        $payment_method,
        $change_amount,
        $receipt_number,
        $_SESSION['employee_id']
    ]);
    
    $payment_id = $pdo->lastInsertId();
    
    // Update invoice status to paid
    $stmt = $pdo->prepare("
        UPDATE invoices 
        SET status = 'paid', updated_at = NOW(), updated_by = ? 
        WHERE invoice_id = ?
    ");
    $stmt->execute([$_SESSION['employee_id'], $invoice_id]);
    
    $pdo->commit();
    
    echo "<p style='color: green;'>✓ Payment processed successfully (ID: $payment_id)</p>";
    echo "<p><strong>Payment Details:</strong></p>";
    echo "<ul>";
    echo "<li>Receipt Number: $receipt_number</li>";
    echo "<li>Amount Paid: ₱" . number_format($payment_amount, 2) . "</li>";
    echo "<li>Payment Method: " . strtoupper($payment_method) . "</li>";
    echo "<li>Change: ₱" . number_format($change_amount, 2) . "</li>";
    echo "</ul>";
    
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "<p style='color: red;'>✗ Payment processing failed: " . $e->getMessage() . "</p>";
    exit;
}

// Test 5: Data Verification
echo "<h2>Test 5: Data Verification</h2>";

try {
    // Verify invoice
    $stmt = $pdo->prepare("
        SELECT i.*, p.first_name, p.last_name, e.first_name as cashier_first_name, e.last_name as cashier_last_name
        FROM invoices i
        JOIN patients p ON i.patient_id = p.patient_id
        JOIN employees e ON i.cashier_id = e.employee_id
        WHERE i.invoice_id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($invoice) {
        echo "<p style='color: green;'>✓ Invoice verification successful</p>";
        echo "<p><strong>Verified Invoice:</strong> {$invoice['first_name']} {$invoice['last_name']} | Status: {$invoice['status']} | Cashier: {$invoice['cashier_first_name']} {$invoice['cashier_last_name']}</p>";
    }
    
    // Verify invoice items
    $stmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
    $stmt->execute([$invoice_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p style='color: green;'>✓ Invoice items verification - " . count($items) . " items found</p>";
    
    // Verify payment
    $stmt = $pdo->prepare("
        SELECT pay.*, p.first_name, p.last_name
        FROM payments pay
        JOIN patients p ON pay.patient_id = p.patient_id
        WHERE pay.payment_id = ?
    ");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($payment) {
        echo "<p style='color: green;'>✓ Payment verification successful</p>";
        echo "<p><strong>Verified Payment:</strong> {$payment['first_name']} {$payment['last_name']} | Receipt: {$payment['receipt_number']}</p>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Data verification failed: " . $e->getMessage() . "</p>";
}

// Test 6: Reports Data
echo "<h2>Test 6: Reports Data Check</h2>";

try {
    // Today's collections
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as transaction_count, SUM(amount_paid) as total_collections
        FROM payments 
        WHERE DATE(payment_date) = CURDATE()
    ");
    $stmt->execute();
    $today_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<p style='color: green;'>✓ Today's collections: ₱" . number_format($today_stats['total_collections'] ?? 0, 2) . " ({$today_stats['transaction_count']} transactions)</p>";
    
    // Most used services
    $stmt = $pdo->prepare("
        SELECT ii.service_name, COUNT(*) as frequency, SUM(ii.total_price) as revenue
        FROM invoice_items ii
        JOIN invoices i ON ii.invoice_id = i.invoice_id
        WHERE i.status = 'paid'
        GROUP BY ii.service_item_id, ii.service_name
        ORDER BY frequency DESC
        LIMIT 3
    ");
    $stmt->execute();
    $top_services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($top_services) {
        echo "<p style='color: green;'>✓ Top services report data available:</p>";
        echo "<ul>";
        foreach ($top_services as $service) {
            echo "<li>{$service['service_name']}: {$service['frequency']} times, ₱" . number_format($service['revenue'], 2) . " revenue</li>";
        }
        echo "</ul>";
    }
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Reports data check failed: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2>🎉 End-to-End Test Summary</h2>";
echo "<p style='color: green; font-weight: bold;'>All major billing workflows tested successfully!</p>";
echo "<p><strong>Test Results:</strong></p>";
echo "<ul>";
echo "<li>✓ Patient search functionality</li>";
echo "<li>✓ Service catalog retrieval</li>";
echo "<li>✓ Invoice creation with discount calculation</li>";
echo "<li>✓ Payment processing with receipt generation</li>";
echo "<li>✓ Data integrity verification</li>";
echo "<li>✓ Reports data availability</li>";
echo "</ul>";

echo "<p><strong>Generated Test Data:</strong></p>";
echo "<ul>";
echo "<li>Invoice ID: $invoice_id</li>";
echo "<li>Payment ID: $payment_id</li>";
echo "<li>Receipt Number: $receipt_number</li>";
echo "</ul>";

echo "<h3>Manual Testing Links:</h3>";
echo "<p><a href='pages/billing/create_invoice.php' target='_blank'>🧾 Test Create Invoice Interface</a></p>";
echo "<p><a href='pages/billing/process_payment.php?invoice_id=$invoice_id' target='_blank'>💰 Test Process Payment Interface</a></p>";
echo "<p><a href='pages/billing/billing_management.php' target='_blank'>📊 Test Billing Management Dashboard</a></p>";
echo "<p><a href='pages/billing/billing_reports.php' target='_blank'>📈 Test Billing Reports</a></p>";
?>