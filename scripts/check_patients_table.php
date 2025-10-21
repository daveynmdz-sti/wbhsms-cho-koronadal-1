<?php
require_once __DIR__ . '/../config/db.php';

echo "Checking patients table structure:\n";
$result = mysqli_query($conn, 'DESCRIBE patients');

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} else {
    echo "Error: " . mysqli_error($conn) . "\n";
}
?>