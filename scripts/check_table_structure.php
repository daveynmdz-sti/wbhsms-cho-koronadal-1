<?php
require_once 'config/db.php';

try {
    $result = $conn->query('DESCRIBE prescription_status_logs');
    if ($result) {
        echo "prescription_status_logs table structure:\n";
        while ($row = $result->fetch_assoc()) {
            echo "  " . $row['Field'] . " - " . $row['Type'] . " (" . $row['Null'] . ", " . $row['Key'] . ")\n";
        }
    } else {
        echo "Error describing table: " . $conn->error . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>