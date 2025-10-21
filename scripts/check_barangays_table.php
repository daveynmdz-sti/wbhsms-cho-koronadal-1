<?php
require_once __DIR__ . '/../config/db.php';

echo "Checking for barangay-related tables:\n";
$result = mysqli_query($conn, "SHOW TABLES LIKE '%barangay%'");

if ($result) {
    while ($row = mysqli_fetch_array($result)) {
        echo "- " . $row[0] . "\n";
    }
    
    // Check if barangays table exists and get its structure
    $barangays_result = mysqli_query($conn, "DESCRIBE barangays");
    if ($barangays_result) {
        echo "\nBarangays table structure:\n";
        while ($row = mysqli_fetch_assoc($barangays_result)) {
            echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }
    }
} else {
    echo "Error: " . mysqli_error($conn) . "\n";
}
?>