<?php
include 'config/db.php';

// Check if there's a relationship between appointment slots and referrals
echo "Checking appointment slots and referral relationships:\n\n";

// First, check if appointment slots table has referral_id
echo "Doctor Schedule Slots table structure:\n";
$result = $conn->query('DESCRIBE doctor_schedule_slots');
while($row = $result->fetch_assoc()) {
    echo $row['Field'] . ' - ' . $row['Type'] . "\n";
}

echo "\n\nCheck slots that have referral_id:\n";
$result = $conn->query("SELECT slot_id, date, time_slot, doctor_id, referral_id FROM doctor_schedule_slots WHERE referral_id IS NOT NULL LIMIT 5");
while($row = $result->fetch_assoc()) {
    print_r($row);
}

echo "\n\nCheck corresponding referrals:\n";
$result = $conn->query("
    SELECT r.referral_id, r.scheduled_date, r.scheduled_time, r.assigned_doctor_id,
           dss.slot_id, dss.date, dss.time_slot
    FROM referrals r 
    LEFT JOIN doctor_schedule_slots dss ON dss.referral_id = r.referral_id
    WHERE r.scheduled_date IS NOT NULL 
    LIMIT 5
");
while($row = $result->fetch_assoc()) {
    print_r($row);
}
?>