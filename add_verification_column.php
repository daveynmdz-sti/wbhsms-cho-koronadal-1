<?php
require_once 'config/db.php';

try {
    echo "<h3>Adding verification_code Column to Appointments Table</h3>";
    
    // First check if the column already exists
    $stmt = $pdo->query("DESCRIBE appointments");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $verification_code_exists = false;
    foreach ($columns as $col) {
        if ($col['Field'] === 'verification_code') {
            $verification_code_exists = true;
            break;
        }
    }
    
    if ($verification_code_exists) {
        echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; color: #155724; margin: 10px 0;'>";
        echo "✅ verification_code column already exists in appointments table!";
        echo "</div>";
    } else {
        echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; color: #856404; margin: 10px 0;'>";
        echo "❌ verification_code column does not exist. Adding it now...";
        echo "</div>";
        
        // Add the verification_code column
        $sql = "ALTER TABLE appointments ADD COLUMN verification_code VARCHAR(255) NULL COMMENT 'QR verification token for appointment security'";
        $pdo->exec($sql);
        
        echo "<div style='background: #d4edda; padding: 15px; border-radius: 5px; color: #155724; margin: 10px 0;'>";
        echo "✅ verification_code column added successfully!";
        echo "</div>";
    }
    
    // Also check if other verification columns exist
    $verification_columns = [
        'last_qr_verification' => 'DATETIME NULL COMMENT "Last successful QR code verification"',
        'last_manual_verification' => 'DATETIME NULL COMMENT "Last successful manual verification"', 
        'manual_verification_by' => 'INT NULL COMMENT "Employee who performed manual verification"'
    ];
    
    echo "<h3>Checking Other Verification Columns</h3>";
    
    foreach ($verification_columns as $col_name => $col_definition) {
        $exists = false;
        foreach ($columns as $col) {
            if ($col['Field'] === $col_name) {
                $exists = true;
                break;
            }
        }
        
        if ($exists) {
            echo "<p>✅ {$col_name} - EXISTS</p>";
        } else {
            echo "<p>❌ {$col_name} - MISSING, adding now...</p>";
            $sql = "ALTER TABLE appointments ADD COLUMN {$col_name} {$col_definition}";
            $pdo->exec($sql);
            echo "<p style='color: #28a745;'>✅ {$col_name} - ADDED</p>";
        }
    }
    
    echo "<h3>Updated Appointments Table Structure</h3>";
    $stmt = $pdo->query("DESCRIBE appointments");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='margin: 20px 0; border-collapse: collapse;'>";
    echo "<tr style='background: #f8f9fa;'><th style='padding: 8px;'>Column</th><th style='padding: 8px;'>Type</th><th style='padding: 8px;'>Null</th><th style='padding: 8px;'>Default</th></tr>";
    foreach ($columns as $col) {
        $highlight = (strpos($col['Field'], 'verification') !== false) ? 'style="background: #d4edda;"' : '';
        echo "<tr {$highlight}>";
        echo "<td style='padding: 8px;'>{$col['Field']}</td>";
        echo "<td style='padding: 8px;'>{$col['Type']}</td>";
        echo "<td style='padding: 8px;'>{$col['Null']}</td>";
        echo "<td style='padding: 8px;'>{$col['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<div style='background: #e3f2fd; padding: 15px; border-radius: 5px; color: #1976d2; margin: 20px 0;'>";
    echo "<h4>📋 Next Steps:</h4>";
    echo "<ol>";
    echo "<li><strong>Update QR generation:</strong> Modify the QR code generation to store verification codes in the database</li>";
    echo "<li><strong>Test QR verification:</strong> Try scanning QR codes again - they should now work properly</li>";
    echo "<li><strong>Generate new QRs:</strong> New appointments will have verification codes stored in the database</li>";
    echo "</ol>";
    echo "</div>";
    
    // Show sample update query for existing appointments
    echo "<h3>Sample Code to Update Existing Appointments</h3>";
    echo "<p>If you want to generate verification codes for existing appointments, you can use this:</p>";
    echo "<pre style='background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto;'>";
    echo "-- Update existing appointments with new verification codes\n";
    echo "UPDATE appointments \n";
    echo "SET verification_code = UPPER(SUBSTRING(MD5(CONCAT(appointment_id, patient_id, created_at, RAND())), 1, 8))\n";
    echo "WHERE verification_code IS NULL AND status = 'confirmed';\n";
    echo "</pre>";
    
    echo "<button onclick=\"generateCodes()\" style='padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; margin: 10px 0;'>";
    echo "Generate Verification Codes for Existing Appointments";
    echo "</button>";
    
    echo "<script>
    function generateCodes() {
        if (confirm('This will generate verification codes for all existing confirmed appointments without codes. Continue?')) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=generate_codes'
            })
            .then(response => response.text())
            .then(data => {
                alert(data);
                location.reload();
            })
            .catch(error => {
                alert('Error: ' + error);
            });
        }
    }
    </script>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "❌ Error: " . $e->getMessage();
    echo "</div>";
}

// Handle verification code generation
if (isset($_POST['action']) && $_POST['action'] === 'generate_codes') {
    try {
        $sql = "UPDATE appointments 
                SET verification_code = UPPER(SUBSTRING(MD5(CONCAT(appointment_id, patient_id, created_at, RAND())), 1, 8))
                WHERE verification_code IS NULL AND status = 'confirmed'";
        
        $stmt = $pdo->exec($sql);
        echo "✅ Generated verification codes for {$stmt} appointments!";
    } catch (Exception $e) {
        echo "❌ Error generating codes: " . $e->getMessage();
    }
    exit;
}
?>