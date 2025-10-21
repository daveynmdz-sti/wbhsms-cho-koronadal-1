<?php
require_once __DIR__ . '/../config/db.php';

echo "Checking for vitals table:\n";
$result = mysqli_query($conn, "SHOW TABLES LIKE '%vital%'");

if ($result) {
    while ($row = mysqli_fetch_array($result)) {
        echo "- " . $row[0] . "\n";
    }
    
    // Check if vitals table exists and get its structure
    $vitals_result = mysqli_query($conn, "DESCRIBE vitals");
    if ($vitals_result) {
        echo "\nVitals table structure:\n";
        while ($row = mysqli_fetch_assoc($vitals_result)) {
            echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
        }
    } else {
        echo "Vitals table does not exist, checking for patient_vitals...\n";
        $patient_vitals_result = mysqli_query($conn, "DESCRIBE patient_vitals");
        if ($patient_vitals_result) {
            echo "\nPatient_vitals table structure:\n";
            while ($row = mysqli_fetch_assoc($patient_vitals_result)) {
                echo "- " . $row['Field'] . " (" . $row['Type'] . ")\n";
            }
        } else {
            echo "Neither vitals nor patient_vitals table exists.\n";
        }
    }
} else {
    echo "Error: " . mysqli_error($conn) . "\n";
}
?>