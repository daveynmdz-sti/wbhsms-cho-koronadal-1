<?php
// database/run_security_migration.php
// Run this script to install medical record security tables and settings

$root_path = dirname(__DIR__);
require_once $root_path . '/config/db.php';

echo "<h2>Medical Record Security Migration</h2>";

try {
    // Read the migration SQL file
    $migrationFile = __DIR__ . '/medical_record_security_migration.sql';
    
    if (!file_exists($migrationFile)) {
        throw new Exception("Migration file not found: {$migrationFile}");
    }
    
    $sql = file_get_contents($migrationFile);
    
    if ($sql === false) {
        throw new Exception("Failed to read migration file");
    }
    
    // Split SQL statements by semicolon and execute each one
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^\s*--/', $stmt);
        }
    );
    
    echo "<p>Found " . count($statements) . " SQL statements to execute.</p>";
    echo "<ul>";
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($statements as $index => $statement) {
        try {
            $pdo->exec($statement);
            
            // Try to identify what was created/modified
            $description = "Statement " . ($index + 1);
            if (preg_match('/CREATE TABLE.*?`([^`]+)`/i', $statement, $matches)) {
                $description = "Created table: " . $matches[1];
            } elseif (preg_match('/CREATE.*VIEW.*?`([^`]+)`/i', $statement, $matches)) {
                $description = "Created view: " . $matches[1];
            } elseif (preg_match('/INSERT.*INTO.*?`([^`]+)`/i', $statement, $matches)) {
                $description = "Inserted data into: " . $matches[1];
            } elseif (preg_match('/ALTER TABLE.*?`([^`]+)`/i', $statement, $matches)) {
                $description = "Modified table: " . $matches[1];
            } elseif (preg_match('/CREATE INDEX.*?ON.*?`([^`]+)`/i', $statement, $matches)) {
                $description = "Created index on: " . $matches[1];
            }
            
            echo "<li style='color: green;'>✓ {$description}</li>";
            $successCount++;
            
        } catch (PDOException $e) {
            // Some statements might fail if objects already exist - that's often OK
            $errorMessage = $e->getMessage();
            
            if (strpos($errorMessage, 'already exists') !== false || 
                strpos($errorMessage, 'Duplicate') !== false) {
                echo "<li style='color: orange;'>⚠ Statement " . ($index + 1) . " - Already exists (skipped)</li>";
            } else {
                echo "<li style='color: red;'>✗ Statement " . ($index + 1) . " - Error: " . htmlspecialchars($errorMessage) . "</li>";
                $errorCount++;
            }
        }
    }
    
    echo "</ul>";
    
    // Verify installation by checking key tables
    echo "<h3>Verification</h3>";
    echo "<ul>";
    
    $requiredTables = [
        'medical_record_audit_log',
        'medical_record_access_logs', 
        'medical_record_security_settings',
        'employee_barangay_assignments'
    ];
    
    foreach ($requiredTables as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
            if ($stmt->rowCount() > 0) {
                echo "<li style='color: green;'>✓ Table '{$table}' exists</li>";
            } else {
                echo "<li style='color: red;'>✗ Table '{$table}' not found</li>";
            }
        } catch (PDOException $e) {
            echo "<li style='color: red;'>✗ Error checking table '{$table}': " . htmlspecialchars($e->getMessage()) . "</li>";
        }
    }
    
    // Check view
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'medical_record_access_permissions'");
        if ($stmt->rowCount() > 0) {
            echo "<li style='color: green;'>✓ View 'medical_record_access_permissions' exists</li>";
        } else {
            echo "<li style='color: orange;'>⚠ View 'medical_record_access_permissions' not found</li>";
        }
    } catch (PDOException $e) {
        echo "<li style='color: red;'>✗ Error checking view: " . htmlspecialchars($e->getMessage()) . "</li>";
    }
    
    // Check security settings
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM medical_record_security_settings");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<li style='color: green;'>✓ Security settings table has {$result['count']} entries</li>";
    } catch (PDOException $e) {
        echo "<li style='color: red;'>✗ Error checking security settings: " . htmlspecialchars($e->getMessage()) . "</li>";
    }
    
    echo "</ul>";
    
    echo "<h3>Migration Summary</h3>";
    echo "<p><strong>Successful statements:</strong> {$successCount}</p>";
    echo "<p><strong>Errors:</strong> {$errorCount}</p>";
    
    if ($errorCount === 0) {
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; color: #155724;'>";
        echo "<strong>✓ Migration completed successfully!</strong><br>";
        echo "Medical record security features have been installed.";
        echo "</div>";
    } else {
        echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; color: #721c24;'>";
        echo "<strong>⚠ Migration completed with errors.</strong><br>";
        echo "Please review the errors above and fix them manually if needed.";
        echo "</div>";
    }
    
    echo "<br><h3>Next Steps</h3>";
    echo "<ol>";
    echo "<li>Test the medical record printing system with security features</li>";
    echo "<li>Review and adjust rate limiting settings in the security settings table</li>";
    echo "<li>Assign barangay access for BHW employees in the employee_barangay_assignments table</li>";
    echo "<li>Monitor the audit logs for security compliance</li>";
    echo "</ol>";
    
} catch (Exception $e) {
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; color: #721c24;'>";
    echo "<strong>Migration Failed:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}
?>