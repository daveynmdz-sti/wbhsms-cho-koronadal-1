<?php
require_once 'C:/xampp/htdocs/wbhsms-cho-koronadal-1/config/db.php';
echo "Service items table:\n";
$result = $pdo->query('DESCRIBE service_items');
while ($row = $result->fetch()) {
    echo "- " . $row['Field'] . "\n";
}
echo "\nSample service items:\n";
$stmt = $pdo->query('SELECT * FROM service_items LIMIT 3');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
?>