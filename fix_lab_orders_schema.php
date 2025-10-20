<?php
// Fix lab_orders appointment_id constraint
require_once __DIR__ . '/config/db.php';

try {
    echo "<h2>Lab Orders Schema Fix</h2>";
    
    // Show current structure
    echo "<h3>Current lab_orders structure:</h3>";
    $result = $conn->query("DESCRIBE lab_orders");
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if appointment_id allows NULL
    $result = $conn->query("SHOW COLUMNS FROM lab_orders LIKE 'appointment_id'");
    $column_info = $result->fetch_assoc();
    
    if ($column_info['Null'] === 'NO') {
        echo "<p><strong>appointment_id currently does NOT allow NULL values. Fixing...</strong></p>";
        
        // Fix the constraint
        $conn->query("ALTER TABLE lab_orders MODIFY COLUMN appointment_id INT NULL");
        
        echo "<p>✅ Fixed! appointment_id now allows NULL values.</p>";
        
        // Show updated structure
        echo "<h3>Updated lab_orders structure:</h3>";
        $result = $conn->query("DESCRIBE lab_orders");
        echo "<table border='1'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>✅ appointment_id already allows NULL values.</p>";
    }
    
    // Show statistics
    echo "<h3>Lab Orders Statistics:</h3>";
    $result = $conn->query("SELECT COUNT(*) as total FROM lab_orders");
    $total = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT COUNT(*) as with_appointments FROM lab_orders WHERE appointment_id IS NOT NULL");
    $with_appointments = $result->fetch_assoc()['with_appointments'];
    
    $result = $conn->query("SELECT COUNT(*) as without_appointments FROM lab_orders WHERE appointment_id IS NULL");
    $without_appointments = $result->fetch_assoc()['without_appointments'];
    
    echo "<ul>";
    echo "<li>Total lab orders: $total</li>";
    echo "<li>With appointments: $with_appointments</li>";
    echo "<li>Without appointments: $without_appointments</li>";
    echo "</ul>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px; text-align: left; }
th { background-color: #f2f2f2; }
</style>