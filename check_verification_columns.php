<?php
require_once 'config/db.php';

try {
    echo "<h3>Appointments Table Structure</h3>";
    $stmt = $pdo->query("DESCRIBE appointments");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='margin-bottom: 20px;'>";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>{$col['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check if verification columns exist
    $verification_columns = ['last_qr_verification', 'last_manual_verification', 'manual_verification_by', 'verification_code'];
    echo "<h3>Verification Column Status</h3>";
    echo "<ul>";
    foreach ($verification_columns as $col_name) {
        $exists = false;
        foreach ($columns as $col) {
            if ($col['Field'] === $col_name) {
                $exists = true;
                break;
            }
        }
        echo "<li>{$col_name}: " . ($exists ? "✅ EXISTS" : "❌ MISSING") . "</li>";
    }
    echo "</ul>";
    
    // Show needed SQL if columns are missing
    $missing_columns = [];
    foreach ($verification_columns as $col_name) {
        $exists = false;
        foreach ($columns as $col) {
            if ($col['Field'] === $col_name) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $missing_columns[] = $col_name;
        }
    }
    
    if (!empty($missing_columns)) {
        echo "<h3>Required SQL Commands</h3>";
        echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px;'>";
        echo "-- Add verification tracking columns to appointments table\n";
        
        if (in_array('last_qr_verification', $missing_columns)) {
            echo "ALTER TABLE appointments ADD COLUMN last_qr_verification DATETIME NULL COMMENT 'Last successful QR code verification';\n";
        }
        if (in_array('last_manual_verification', $missing_columns)) {
            echo "ALTER TABLE appointments ADD COLUMN last_manual_verification DATETIME NULL COMMENT 'Last successful manual verification';\n";
        }
        if (in_array('manual_verification_by', $missing_columns)) {
            echo "ALTER TABLE appointments ADD COLUMN manual_verification_by INT NULL COMMENT 'Employee who performed manual verification';\n";
        }
        if (in_array('verification_code', $missing_columns)) {
            echo "ALTER TABLE appointments ADD COLUMN verification_code VARCHAR(255) NULL COMMENT 'QR verification token';\n";
        }
        
        echo "</pre>";
        
        echo "<button onclick=\"executeSQL()\" style='padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;'>Execute SQL Commands</button>";
        
        echo "<script>
        function executeSQL() {
            if (confirm('This will add verification columns to the appointments table. Continue?')) {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=add_verification_columns'
                })
                .then(response => response.text())
                .then(data => {
                    alert('SQL executed. Refreshing page...');
                    location.reload();
                })
                .catch(error => {
                    alert('Error: ' + error);
                });
            }
        }
        </script>";
    } else {
        echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; color: #155724;'>";
        echo "✅ All verification columns exist! The enhanced security system is ready.";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "Error: " . $e->getMessage();
    echo "</div>";
}

// Handle SQL execution
if (isset($_POST['action']) && $_POST['action'] === 'add_verification_columns') {
    try {
        $pdo->exec("ALTER TABLE appointments ADD COLUMN last_qr_verification DATETIME NULL COMMENT 'Last successful QR code verification'");
        $pdo->exec("ALTER TABLE appointments ADD COLUMN last_manual_verification DATETIME NULL COMMENT 'Last successful manual verification'");
        $pdo->exec("ALTER TABLE appointments ADD COLUMN manual_verification_by INT NULL COMMENT 'Employee who performed manual verification'");
        $pdo->exec("ALTER TABLE appointments ADD COLUMN verification_code VARCHAR(255) NULL COMMENT 'QR verification token'");
        echo "Success: Verification columns added successfully!";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
    exit;
}
?>