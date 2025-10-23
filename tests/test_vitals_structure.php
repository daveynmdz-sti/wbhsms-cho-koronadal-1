<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=wbhsms_database', 'root', '');
    
    echo "Vitals Table Structure:\n";
    echo str_repeat('=', 50) . "\n";
    
    $result = $pdo->query('DESCRIBE vitals');
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        printf("%-20s %-15s %-10s %-10s %-15s %-10s\n", 
            $row['Field'], 
            $row['Type'], 
            $row['Null'], 
            $row['Key'], 
            $row['Default'] ?? 'NULL', 
            $row['Extra']
        );
    }
    
    echo "\nSample vitals data:\n";
    echo str_repeat('=', 50) . "\n";
    
    $sample = $pdo->query('SELECT * FROM vitals LIMIT 3');
    while ($row = $sample->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
        echo "\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>