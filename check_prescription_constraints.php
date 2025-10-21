<?php
require_once 'config/db.php';

try {
    echo "<h3>Prescriptions Table Structure:</h3>";
    $result = $conn->query("DESCRIBE prescriptions");
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['Field']}</td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
        echo "<td>{$row['Extra']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>Foreign Key Constraints:</h3>";
    $result = $conn->query("
        SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
        WHERE TABLE_NAME = 'prescriptions' 
        AND REFERENCED_TABLE_NAME IS NOT NULL 
        AND TABLE_SCHEMA = 'wbhsms_database'
    ");
    echo "<table border='1'>";
    echo "<tr><th>Constraint Name</th><th>Column</th><th>References Table</th><th>References Column</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['CONSTRAINT_NAME']}</td>";
        echo "<td>{$row['COLUMN_NAME']}</td>";
        echo "<td>{$row['REFERENCED_TABLE_NAME']}</td>";
        echo "<td>{$row['REFERENCED_COLUMN_NAME']}</td>";
        echo "</tr>";
    }
    echo "</table>";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>