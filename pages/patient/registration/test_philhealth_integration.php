<?php
// test_philhealth_integration.php
// Test script to verify PhilHealth types lookup table integration

// Use absolute path resolution
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/db.php';

echo "<h2>PhilHealth Types Integration Test</h2>\n";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .info { color: blue; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
</style>";

$errors = [];
$successes = [];

try {
    // Test 1: Check if philhealth_types table exists and has data
    echo "<h3>Test 1: PhilHealth Types Table</h3>\n";
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM philhealth_types WHERE is_active = 1");
    $stmt->execute();
    $count = $stmt->fetchColumn();
    
    if ($count > 0) {
        $successes[] = "✅ PhilHealth types table has {$count} active records";
        
        // Display the types
        $stmt = $pdo->prepare("
            SELECT id, type_code, type_name, category, description 
            FROM philhealth_types 
            WHERE is_active = 1 
            ORDER BY category, type_name
        ");
        $stmt->execute();
        $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table>\n";
        echo "<tr><th>ID</th><th>Code</th><th>Name</th><th>Category</th><th>Description</th></tr>\n";
        foreach ($types as $type) {
            echo "<tr>";
            echo "<td>{$type['id']}</td>";
            echo "<td>{$type['type_code']}</td>";
            echo "<td>{$type['type_name']}</td>";
            echo "<td>{$type['category']}</td>";
            echo "<td>" . ($type['description'] ?? '') . "</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
        
    } else {
        $errors[] = "❌ PhilHealth types table is empty or doesn't exist";
    }

    // Test 2: Check patients table structure
    echo "<h3>Test 2: Patients Table Structure</h3>\n";
    
    $stmt = $pdo->prepare("DESCRIBE patients");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hasPhilhealthTypeId = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'philhealth_type_id') {
            $hasPhilhealthTypeId = true;
            $successes[] = "✅ Patients table has philhealth_type_id column (Type: {$column['Type']})";
            break;
        }
    }
    
    if (!$hasPhilhealthTypeId) {
        $errors[] = "❌ Patients table missing philhealth_type_id column";
    }

    // Test 3: Check foreign key constraint
    echo "<h3>Test 3: Foreign Key Constraints</h3>\n";
    
    $stmt = $pdo->prepare("
        SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'patients' 
        AND COLUMN_NAME = 'philhealth_type_id'
        AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    $stmt->execute();
    $fk = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($fk) {
        $successes[] = "✅ Foreign key constraint exists: {$fk['CONSTRAINT_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}";
    } else {
        $errors[] = "❌ No foreign key constraint found for philhealth_type_id";
    }

    // Test 4: Test registration form data loading
    echo "<h3>Test 4: Registration Form Data Loading</h3>\n";
    
    $stmt = $pdo->prepare("
        SELECT id, type_code, type_name, category, description 
        FROM philhealth_types 
        WHERE is_active = 1 
        ORDER BY category, type_name
    ");
    $stmt->execute();
    $philhealth_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $direct_types = array_filter($philhealth_types, fn($type) => $type['category'] === 'Direct');
    $indirect_types = array_filter($philhealth_types, fn($type) => $type['category'] === 'Indirect');
    
    $directCount = count($direct_types);
    $indirectCount = count($indirect_types);
    
    $successes[] = "✅ Form would load {$directCount} Direct and {$indirectCount} Indirect PhilHealth types";

    // Test 5: Simulate form submission validation
    echo "<h3>Test 5: Form Validation Simulation</h3>\n";
    
    // Test with valid philhealth_type_id
    $testTypeId = $types[0]['id'] ?? null;
    if ($testTypeId) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM philhealth_types WHERE id = ? AND is_active = 1');
        $stmt->execute([$testTypeId]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            $successes[] = "✅ Validation would pass for philhealth_type_id = {$testTypeId}";
        } else {
            $errors[] = "❌ Validation failed for philhealth_type_id = {$testTypeId}";
        }
    }
    
    // Test with invalid philhealth_type_id
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM philhealth_types WHERE id = ? AND is_active = 1');
    $stmt->execute([99999]);
    $count = $stmt->fetchColumn();
    
    if ($count == 0) {
        $successes[] = "✅ Validation would correctly reject invalid philhealth_type_id = 99999";
    } else {
        $errors[] = "❌ Validation incorrectly accepted invalid philhealth_type_id = 99999";
    }

} catch (Exception $e) {
    $errors[] = "❌ Database error: " . $e->getMessage();
}

// Display results
echo "<h3>Test Results Summary</h3>\n";

if (!empty($successes)) {
    echo "<div class='success'>\n";
    foreach ($successes as $success) {
        echo "<p>{$success}</p>\n";
    }
    echo "</div>\n";
}

if (!empty($errors)) {
    echo "<div class='error'>\n";
    foreach ($errors as $error) {
        echo "<p>{$error}</p>\n";
    }
    echo "</div>\n";
}

if (empty($errors)) {
    echo "<div class='success'><h4>🎉 All tests passed! PhilHealth types integration is working correctly.</h4></div>\n";
    
    echo "<h3>Next Steps</h3>\n";
    echo "<div class='info'>\n";
    echo "<p>✅ Your PhilHealth types lookup table is properly integrated!</p>\n";
    echo "<p>✅ The registration form will dynamically load types from the database</p>\n";
    echo "<p>✅ Backend validation will check against the lookup table</p>\n";
    echo "<p>✅ Database insertion will use foreign key references</p>\n";
    echo "<p><strong>You can now test the complete registration flow:</strong></p>\n";
    echo "<p>1. Visit: <a href='patient_registration.php'>patient_registration.php</a></p>\n";
    echo "<p>2. Fill out the form and select a PhilHealth type</p>\n";
    echo "<p>3. Complete the OTP verification</p>\n";
    echo "<p>4. Check that data is properly saved with philhealth_type_id reference</p>\n";
    echo "</div>\n";
} else {
    echo "<div class='error'><h4>⚠️ Some tests failed. Please fix the issues above before proceeding.</h4></div>\n";
}

?>