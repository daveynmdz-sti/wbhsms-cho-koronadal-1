<?php
require_once __DIR__ . '/../config/db.php';

echo "Checking for services table:\n";
$result = mysqli_query($conn, "SHOW TABLES LIKE '%service%'");

if ($result) {
    while ($row = mysqli_fetch_array($result)) {
        echo "- " . $row[0] . "\n";
    }
    
    // Check if services table exists and get its structure
    $services_result = mysqli_query($conn, "DESCRIBE services");
    if ($services_result) {
        echo "\nServices table structure:\n";
        while ($row = mysqli_fetch_assoc($services_result)) {
            echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }
    } else {
        echo "Services table does not exist or error: " . mysqli_error($conn) . "\n";
    }
} else {
    echo "Error: " . mysqli_error($conn) . "\n";
}

// Also check appointments table structure to see how services are referenced
echo "\nChecking appointments table structure for service reference:\n";
$appointments_result = mysqli_query($conn, "DESCRIBE appointments");
if ($appointments_result) {
    while ($row = mysqli_fetch_assoc($appointments_result)) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
}
?>