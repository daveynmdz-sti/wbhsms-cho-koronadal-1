<?php
require_once 'config/db.php';

echo "Checking employees table structure:\n";
$result = $conn->query("DESCRIBE employees");
while ($row = $result->fetch_assoc()) {
    echo "Column: " . $row['Field'] . " | Type: " . $row['Type'] . "\n";
}

echo "\nChecking a sample employee record:\n";
$stmt = $conn->prepare("SELECT * FROM employees WHERE employee_id LIKE 'EMP%' LIMIT 1");
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();
if ($employee) {
    foreach ($employee as $key => $value) {
        echo "$key: $value\n";
    }
} else {
    echo "No employee records found\n";
}
?>