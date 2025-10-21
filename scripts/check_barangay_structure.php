<?php
require_once __DIR__ . '/../config/db.php';

echo "Checking barangay table structure:\n";
$result = mysqli_query($conn, 'DESCRIBE barangay');

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} else {
    echo "Error: " . mysqli_error($conn) . "\n";
}
?>