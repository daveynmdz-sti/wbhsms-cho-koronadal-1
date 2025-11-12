<?php
include 'config/db.php';

echo "Checking table structures for appointments/schedules:\n\n";

// Check doctor_schedule_slots
echo "Doctor Schedule Slots table:\n";
$result = $conn->query('DESCRIBE doctor_schedule_slots');
if ($result) {
    while($row = $result->fetch_assoc()) {
        echo $row['Field'] . ' - ' . $row['Type'] . "\n";
    }
} else {
    echo "Table not found or error: " . $conn->error . "\n";
}

echo "\n\nChecking if appointment_slots table exists:\n";
$result = $conn->query('DESCRIBE appointment_slots');
if ($result) {
    while($row = $result->fetch_assoc()) {
        echo $row['Field'] . ' - ' . $row['Type'] . "\n";
    }
} else {
    echo "Table not found or error: " . $conn->error . "\n";
}

echo "\n\nShow all tables with 'slot' or 'schedule' in name:\n";
$result = $conn->query("SHOW TABLES LIKE '%slot%'");
while($row = $result->fetch_array()) {
    echo $row[0] . "\n";
}

$result = $conn->query("SHOW TABLES LIKE '%schedule%'");
while($row = $result->fetch_array()) {
    echo $row[0] . "\n";
}
?>