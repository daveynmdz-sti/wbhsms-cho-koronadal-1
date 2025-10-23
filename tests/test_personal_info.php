<?php
require_once 'C:/xampp/htdocs/wbhsms-cho-koronadal-1/config/db.php';
try {
    echo "Personal information table:\n";
    $result = $pdo->query('DESCRIBE personal_information');
    while ($row = $result->fetch()) {
        echo "- " . $row['Field'] . "\n";
    }
    echo "\nSample personal info:\n";
    $stmt = $pdo->query('SELECT patient_id, profile_photo FROM personal_information WHERE profile_photo IS NOT NULL LIMIT 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "Patient ID: " . $row['patient_id'] . ", Has Photo: " . (!empty($row['profile_photo']) ? 'YES' : 'NO') . "\n";
    } else {
        echo "No photos in personal_information table\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>