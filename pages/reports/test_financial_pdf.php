<?php
// Simple test for financial PDF export dependencies
$root_path = dirname(dirname(__DIR__));

echo "Testing financial PDF export dependencies...\n";
echo "Root path: " . $root_path . "\n";

// Test 1: Session config
echo "\n1. Testing session config...\n";
if (file_exists($root_path . '/config/session/employee_session.php')) {
    require_once $root_path . '/config/session/employee_session.php';
    echo "✓ Session config loaded\n";
    echo "Session ID exists: " . (isset($_SESSION['employee_id']) ? 'YES' : 'NO') . "\n";
} else {
    echo "✗ Session config file not found\n";
}

// Test 2: Database config
echo "\n2. Testing database config...\n";
if (file_exists($root_path . '/config/db.php')) {
    require_once $root_path . '/config/db.php';
    echo "✓ Database config loaded\n";
    echo "PDO available: " . (isset($pdo) && $pdo !== null ? 'YES' : 'NO') . "\n";
    if (isset($pdo) && $pdo !== null) {
        try {
            $test = $pdo->query("SELECT 1 as test");
            echo "✓ PDO connection working\n";
        } catch (Exception $e) {
            echo "✗ PDO connection failed: " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "✗ Database config file not found\n";
}

// Test 3: Composer autoloader
echo "\n3. Testing Composer autoloader...\n";
if (file_exists($root_path . '/vendor/autoload.php')) {
    require_once $root_path . '/vendor/autoload.php';
    echo "✓ Composer autoloader loaded\n";
    
    try {
        $options = new \Dompdf\Options();
        echo "✓ Dompdf Options class available\n";
        
        $dompdf = new \Dompdf\Dompdf($options);
        echo "✓ Dompdf class available\n";
    } catch (Exception $e) {
        echo "✗ Dompdf error: " . $e->getMessage() . "\n";
    }
} else {
    echo "✗ Composer autoloader not found\n";
}

echo "\nTest completed.\n";
?>