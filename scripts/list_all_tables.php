<?php
require_once __DIR__ . '/../config/db.php';

echo "All tables in the database:\n";
$result = mysqli_query($conn, "SHOW TABLES");

if ($result) {
    $tables = [];
    while ($row = mysqli_fetch_array($result)) {
        $tables[] = $row[0];
    }
    
    sort($tables);
    foreach ($tables as $table) {
        echo "- " . $table . "\n";
    }
    
    echo "\nTables containing 'visit':\n";
    foreach ($tables as $table) {
        if (stripos($table, 'visit') !== false) {
            echo "- " . $table . "\n";
        }
    }
    
    echo "\nTables containing 'vital':\n";
    foreach ($tables as $table) {
        if (stripos($table, 'vital') !== false) {
            echo "- " . $table . "\n";
        }
    }
} else {
    echo "Error: " . mysqli_error($conn) . "\n";
}
?>