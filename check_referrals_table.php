<?php
include 'config/db.php';

echo "Referrals table structure:\n";
$result = $conn->query('DESCRIBE referrals');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . ' (' . ($row['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . ')' . "\n";
}

echo "\n\nDoctor Schedule Slots table structure:\n";
$result = $conn->query('DESCRIBE doctor_schedule_slots');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . ' (' . ($row['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . ')' . "\n";
}

// Check a sample referral with appointment data
echo "\n\nSample referral with appointment data:\n";
$result = $conn->query("SELECT referral_id, scheduled_date, scheduled_time, assigned_doctor_id FROM referrals WHERE scheduled_date IS NOT NULL AND assigned_doctor_id IS NOT NULL LIMIT 1");
if ($row = $result->fetch_assoc()) {
    print_r($row);
} else {
    echo "No referrals with appointment data found\n";
}
?>