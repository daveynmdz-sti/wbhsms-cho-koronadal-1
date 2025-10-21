<?php
require_once __DIR__ . '/../config/db.php';

// Test with a broader search - just get any checked-in patients
$sql = "SELECT DISTINCT
            v.visit_id,
            v.patient_id,
            v.appointment_id,
            v.visit_date,
            v.visit_status,
            p.first_name,
            p.last_name,
            p.middle_name,
            p.username as patient_code,
            p.date_of_birth,
            p.sex,
            p.contact_number,
            COALESCE(b.barangay_name, 'Not Specified') as barangay,
            a.scheduled_date,
            a.scheduled_time,
            COALESCE(s.name, 'General Consultation') as service_name,
            a.status as appointment_status,
            c.consultation_id,
            c.consultation_status,
            vt.vitals_id,
            vt.systolic_bp,
            vt.diastolic_bp
        FROM visits v
        INNER JOIN patients p ON v.patient_id = p.patient_id
        INNER JOIN appointments a ON v.appointment_id = a.appointment_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN services s ON a.service_id = s.service_id
        LEFT JOIN consultations c ON v.visit_id = c.visit_id
        LEFT JOIN vitals vt ON v.vitals_id = vt.vitals_id
        WHERE a.status IN ('checked_in', 'in_progress', 'confirmed')
        AND v.visit_status IN ('checked_in', 'active', 'in_progress', 'ongoing')
        ORDER BY v.visit_date DESC, a.scheduled_time ASC
        LIMIT 5";

$result = mysqli_query($conn, $sql);

if (!$result) {
    echo "Error executing query: " . mysqli_error($conn) . "\n";
    exit;
}

$patients = [];
while ($row = mysqli_fetch_assoc($result)) {
    // Calculate age
    if ($row['date_of_birth']) {
        $dob = new DateTime($row['date_of_birth']);
        $now = new DateTime();
        $row['age'] = $now->diff($dob)->y;
    }
    
    // Format full name
    $row['full_name'] = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
    
    $patients[] = $row;
}

echo "Query executed successfully!\n";
echo "Found " . count($patients) . " recent patients with appointments\n";

if (count($patients) > 0) {
    echo "\nSample patient data:\n";
    foreach ($patients as $i => $patient) {
        echo ($i + 1) . ". " . $patient['full_name'] . " (" . $patient['patient_code'] . ")\n";
        echo "   Status: " . $patient['appointment_status'] . " / " . $patient['visit_status'] . "\n";
        echo "   Service: " . $patient['service_name'] . "\n";
        echo "   Vitals: " . ($patient['vitals_id'] ? "Recorded" : "Pending") . "\n";
        echo "   Consultation: " . ($patient['consultation_id'] ? $patient['consultation_status'] : "Not Started") . "\n";
        echo "\n";
    }
} else {
    echo "\nNo recent patients found. This might be normal if there are no active appointments.\n";
}
?>