<?php
require_once 'config/db.php';

echo "PATIENTS TABLE STRUCTURE:\n";
$result = $conn->query('DESCRIBE patients');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}

echo "\nPHILHEALTH_TYPES TABLE STRUCTURE:\n";
$result = $conn->query('DESCRIBE philhealth_types');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}

echo "\nSAMPLE PHILHEALTH_TYPES DATA:\n";
$result = $conn->query('SELECT * FROM philhealth_types LIMIT 10');
while($row = $result->fetch_assoc()) {
    echo "ID: " . $row['philhealth_type_id'] . " - Name: " . $row['type_name'] . "\n";
}
?>