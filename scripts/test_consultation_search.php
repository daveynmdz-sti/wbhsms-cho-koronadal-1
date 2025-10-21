<?php
require_once __DIR__ . '/../config/db.php';

// Test the search query to make sure it works
$searchParam = '%diaz%';

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
            -- Check if consultation already exists
            c.consultation_id,
            c.consultation_status,
            c.consultation_date,
            -- Latest vitals from this visit
            vt.vitals_id,
            vt.systolic_bp,
            vt.diastolic_bp,
            vt.heart_rate,
            vt.respiratory_rate,
            vt.temperature,
            vt.weight,
            vt.height,
            vt.bmi,
            vt.recorded_by,
            vt.recorded_at as vitals_date
        FROM visits v
        INNER JOIN patients p ON v.patient_id = p.patient_id
        INNER JOIN appointments a ON v.appointment_id = a.appointment_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN services s ON a.service_id = s.service_id
        LEFT JOIN consultations c ON v.visit_id = c.visit_id
        LEFT JOIN vitals vt ON v.vitals_id = vt.vitals_id
        WHERE a.status IN ('checked_in', 'in_progress')
        AND v.visit_status IN ('checked_in', 'active', 'in_progress')
        AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.username LIKE ? 
             OR p.contact_number LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)
        ORDER BY v.visit_date DESC, a.scheduled_time ASC
        LIMIT 50";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "Error preparing query: " . $conn->error . "\n";
    exit;
}

$stmt->bind_param("sssss", $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);

if (!$stmt->execute()) {
    echo "Error executing query: " . $stmt->error . "\n";
    exit;
}

$result = $stmt->get_result();
$patients = [];
while ($row = $result->fetch_assoc()) {
    // Calculate age
    if ($row['date_of_birth']) {
        $dob = new DateTime($row['date_of_birth']);
        $now = new DateTime();
        $row['age'] = $now->diff($dob)->y;
    }
    
    // Format full name
    $row['full_name'] = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
    
    // Determine consultation status
    if ($row['consultation_id']) {
        $row['has_consultation'] = true;
        $row['consultation_display_status'] = ucfirst($row['consultation_status']);
    } else {
        $row['has_consultation'] = false;
        $row['consultation_display_status'] = 'Not Started';
    }
    
    $patients[] = $row;
}

echo "Query executed successfully!\n";
echo "Found " . count($patients) . " patients\n";

if (count($patients) > 0) {
    echo "\nSample patient data:\n";
    $sample = $patients[0];
    echo "Name: " . $sample['full_name'] . "\n";
    echo "ID: " . $sample['patient_code'] . "\n";
    echo "Barangay: " . $sample['barangay'] . "\n";
    echo "Service: " . $sample['service_name'] . "\n";
} else {
    echo "\nNo patients found with 'diaz' in their name.\n";
    echo "This might be normal if there are no patients with that name currently checked in.\n";
}
?>