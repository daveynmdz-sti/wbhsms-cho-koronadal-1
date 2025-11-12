<?php
include 'config/db.php';

echo "Doctor Schedule Slots table structure:\n";
$result = $conn->query('DESCRIBE doctor_schedule_slots');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}
?>