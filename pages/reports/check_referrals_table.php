<?php
// Check referrals table structure
$root_path = dirname(__DIR__, 2);
require_once $root_path . '/config/db.php';

try {
    $stmt = $pdo->query("DESCRIBE referrals");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>Referrals Table Structure:</h2>";
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Default']) . "</td>";
        echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Also show a sample row to see the data
    echo "<h3>Sample Data:</h3>";
    $sample = $pdo->query("SELECT * FROM referrals LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($sample) {
        echo "<pre>";
        print_r($sample);
        echo "</pre>";
    } else {
        echo "No data found in referrals table.";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>