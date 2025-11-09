<?php
/**
 * Check current appointments with QR codes
 */

require_once 'config/db.php';

echo "<h2>Appointments with QR Codes</h2>";

// Check recent appointments
$stmt = $conn->prepare("
    SELECT appointment_id, patient_id, appointment_date, appointment_time, 
           verification_code,
           CASE 
               WHEN qr_code_path IS NULL THEN 'No QR Code'
               WHEN LENGTH(qr_code_path) = 0 THEN 'Empty QR Code'
               ELSE CONCAT('QR Code Present (', LENGTH(qr_code_path), ' bytes)')
           END as qr_status,
           created_at
    FROM appointments 
    ORDER BY created_at DESC 
    LIMIT 10
");

$stmt->execute();
$result = $stmt->get_result();

echo "<table border='1' style='border-collapse: collapse; margin: 10px;'>";
echo "<tr><th>ID</th><th>Patient ID</th><th>Date</th><th>Time</th><th>Verification Code</th><th>QR Status</th><th>Created</th></tr>";

while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['appointment_id'] . "</td>";
    echo "<td>" . $row['patient_id'] . "</td>";
    echo "<td>" . $row['appointment_date'] . "</td>";
    echo "<td>" . $row['appointment_time'] . "</td>";
    echo "<td>" . $row['verification_code'] . "</td>";
    echo "<td>" . $row['qr_status'] . "</td>";
    echo "<td>" . $row['created_at'] . "</td>";
    echo "</tr>";
}

echo "</table>";

$stmt->close();

echo "<h3>Check Database Structure</h3>";
$columns = $conn->query("SHOW COLUMNS FROM appointments LIKE '%qr%'");
if ($columns->num_rows > 0) {
    while ($col = $columns->fetch_assoc()) {
        echo "Column: " . $col['Field'] . " | Type: " . $col['Type'] . "<br>";
    }
} else {
    echo "No QR code related columns found in appointments table.";
}

?>