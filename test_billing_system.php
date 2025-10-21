<?php
require_once 'config/db.php';

echo "<h1>Billing System End-to-End Test</h1>";

// Test 1: Check database tables
echo "<h2>Test 1: Database Tables Check</h2>";
$tables_to_check = ['invoices', 'invoice_items', 'payments', 'billing_items', 'service_items', 'patients', 'employees'];

foreach ($tables_to_check as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "<p style='color: green;'>✓ Table '$table' exists with $count records</p>";
    } catch (PDOException $e) {
        echo "<p style='color: red;'>✗ Table '$table' error: " . $e->getMessage() . "</p>";
    }
}

// Test 2: Check foreign key constraints
echo "<h2>Test 2: Foreign Key Constraints Check</h2>";
try {
    $stmt = $pdo->query("
        SELECT 
            TABLE_NAME,
            COLUMN_NAME,
            CONSTRAINT_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE CONSTRAINT_SCHEMA = 'wbhsms_db' 
        AND REFERENCED_TABLE_NAME IS NOT NULL 
        AND TABLE_NAME IN ('invoices', 'invoice_items', 'payments')
        ORDER BY TABLE_NAME, COLUMN_NAME
    ");
    
    $constraints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($constraints) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Table</th><th>Column</th><th>References</th><th>Column</th></tr>";
        foreach ($constraints as $constraint) {
            echo "<tr>";
            echo "<td>{$constraint['TABLE_NAME']}</td>";
            echo "<td>{$constraint['COLUMN_NAME']}</td>";
            echo "<td>{$constraint['REFERENCED_TABLE_NAME']}</td>";
            echo "<td>{$constraint['REFERENCED_COLUMN_NAME']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: orange;'>No foreign key constraints found</p>";
    }
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error checking constraints: " . $e->getMessage() . "</p>";
}

// Test 3: Check sample data
echo "<h2>Test 3: Sample Data Check</h2>";

// Check patients
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM patients WHERE status = 'active'");
    $patient_count = $stmt->fetchColumn();
    echo "<p>Active patients: $patient_count</p>";
    
    if ($patient_count > 0) {
        $stmt = $pdo->query("SELECT patient_id, first_name, last_name FROM patients WHERE status = 'active' LIMIT 3");
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>Sample patients:</p><ul>";
        foreach ($patients as $patient) {
            echo "<li>ID: {$patient['patient_id']} - {$patient['first_name']} {$patient['last_name']}</li>";
        }
        echo "</ul>";
    }
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error checking patients: " . $e->getMessage() . "</p>";
}

// Check service items
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM service_items WHERE status = 'active'");
    $service_count = $stmt->fetchColumn();
    echo "<p>Active service items: $service_count</p>";
    
    if ($service_count > 0) {
        $stmt = $pdo->query("SELECT service_item_id, service_name, price FROM service_items WHERE status = 'active' LIMIT 5");
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>Sample services:</p><ul>";
        foreach ($services as $service) {
            echo "<li>ID: {$service['service_item_id']} - {$service['service_name']} (₱{$service['price']})</li>";
        }
        echo "</ul>";
    }
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error checking services: " . $e->getMessage() . "</p>";
}

// Check employees (cashiers)
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM employees WHERE role = 'Cashier' AND status = 'active'");
    $cashier_count = $stmt->fetchColumn();
    echo "<p>Active cashiers: $cashier_count</p>";
    
    if ($cashier_count > 0) {
        $stmt = $pdo->query("SELECT employee_id, first_name, last_name FROM employees WHERE role = 'Cashier' AND status = 'active' LIMIT 3");
        $cashiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<p>Sample cashiers:</p><ul>";
        foreach ($cashiers as $cashier) {
            echo "<li>ID: {$cashier['employee_id']} - {$cashier['first_name']} {$cashier['last_name']}</li>";
        }
        echo "</ul>";
    }
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error checking cashiers: " . $e->getMessage() . "</p>";
}

// Test 4: API Endpoints Check
echo "<h2>Test 4: API Endpoints Check</h2>";

$api_files = [
    'api/search_patients.php',
    'api/get_service_catalog.php',
    'api/create_invoice.php',
    'api/process_payment.php',
    'api/get_patient_invoices.php'
];

foreach ($api_files as $api_file) {
    if (file_exists($api_file)) {
        echo "<p style='color: green;'>✓ $api_file exists</p>";
    } else {
        echo "<p style='color: red;'>✗ $api_file missing</p>";
    }
}

// Test 5: Frontend Pages Check
echo "<h2>Test 5: Frontend Pages Check</h2>";

$frontend_files = [
    'pages/billing/create_invoice.php',
    'pages/billing/process_payment.php',
    'pages/billing/billing_management.php',
    'pages/billing/billing_reports.php'
];

foreach ($frontend_files as $frontend_file) {
    if (file_exists($frontend_file)) {
        echo "<p style='color: green;'>✓ $frontend_file exists</p>";
    } else {
        echo "<p style='color: red;'>✗ $frontend_file missing</p>";
    }
}

echo "<hr>";
echo "<h2>Navigation Links</h2>";
echo "<p><a href='pages/billing/create_invoice.php' target='_blank'>Test Create Invoice</a></p>";
echo "<p><a href='pages/billing/process_payment.php' target='_blank'>Test Process Payment</a></p>";
echo "<p><a href='pages/billing/billing_management.php' target='_blank'>Test Billing Management</a></p>";
echo "<p><a href='pages/billing/billing_reports.php' target='_blank'>Test Billing Reports</a></p>";
?>