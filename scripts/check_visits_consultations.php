<?php
require_once __DIR__ . '/../config/db.php';

echo "Checking visits table structure:\n";
$result = mysqli_query($conn, 'DESCRIBE visits');

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} else {
    echo "Error: " . mysqli_error($conn) . "\n";
}

echo "\nChecking consultations table structure:\n";
$result = mysqli_query($conn, 'DESCRIBE consultations');

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} else {
    echo "Error: " . mysqli_error($conn) . "\n";
}
?>