<?php
require_once 'config/db.php';

try {
    echo "<h3>Patients Table Structure</h3>";
    $stmt = $pdo->query("DESCRIBE patients");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='margin-bottom: 20px;'>";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>{$col['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Look for phone/contact columns
    echo "<h3>Phone/Contact Columns</h3>";
    foreach ($columns as $col) {
        if (stripos($col['Field'], 'phone') !== false || 
            stripos($col['Field'], 'contact') !== false || 
            stripos($col['Field'], 'mobile') !== false) {
            echo "<p><strong>Found:</strong> {$col['Field']} ({$col['Type']})</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "Error: " . $e->getMessage();
    echo "</div>";
}
?>