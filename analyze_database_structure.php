<?php
// Database analysis script for appointments and referrals tables
require_once 'config/db.php';

echo "<h1>Database Structure Analysis - Appointments and Referrals</h1>\n";

try {
    // Get appointments table structure
    echo "<h2>1. APPOINTMENTS Table Structure</h2>\n";
    $stmt = $pdo->query("DESCRIBE appointments");
    $appointments_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>\n";
    foreach ($appointments_columns as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "<td>{$column['Extra']}</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";

    // Get referrals table structure
    echo "<h2>2. REFERRALS Table Structure</h2>\n";
    $stmt = $pdo->query("DESCRIBE referrals");
    $referrals_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>\n";
    foreach ($referrals_columns as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "<td>{$column['Extra']}</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";

    // Find all tables that reference appointments
    echo "<h2>3. Tables with Foreign Keys to APPOINTMENTS</h2>\n";
    $stmt = $pdo->query("
        SELECT 
            TABLE_NAME,
            COLUMN_NAME,
            CONSTRAINT_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM 
            information_schema.KEY_COLUMN_USAGE 
        WHERE 
            REFERENCED_TABLE_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_NAME = 'appointments'
        ORDER BY TABLE_NAME, COLUMN_NAME
    ");
    $appointment_fks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($appointment_fks)) {
        echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
        echo "<tr><th>Table</th><th>Column</th><th>Constraint</th><th>References</th></tr>\n";
        foreach ($appointment_fks as $fk) {
            echo "<tr>";
            echo "<td>{$fk['TABLE_NAME']}</td>";
            echo "<td>{$fk['COLUMN_NAME']}</td>";
            echo "<td>{$fk['CONSTRAINT_NAME']}</td>";
            echo "<td>{$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    } else {
        echo "<p>No formal foreign key constraints found for appointments table.</p>\n";
    }

    // Find all tables that reference referrals
    echo "<h2>4. Tables with Foreign Keys to REFERRALS</h2>\n";
    $stmt = $pdo->query("
        SELECT 
            TABLE_NAME,
            COLUMN_NAME,
            CONSTRAINT_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM 
            information_schema.KEY_COLUMN_USAGE 
        WHERE 
            REFERENCED_TABLE_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_NAME = 'referrals'
        ORDER BY TABLE_NAME, COLUMN_NAME
    ");
    $referral_fks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($referral_fks)) {
        echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
        echo "<tr><th>Table</th><th>Column</th><th>Constraint</th><th>References</th></tr>\n";
        foreach ($referral_fks as $fk) {
            echo "<tr>";
            echo "<td>{$fk['TABLE_NAME']}</td>";
            echo "<td>{$fk['COLUMN_NAME']}</td>";
            echo "<td>{$fk['CONSTRAINT_NAME']}</td>";
            echo "<td>{$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    } else {
        echo "<p>No formal foreign key constraints found for referrals table.</p>\n";
    }

    // Look for columns that might reference appointments or referrals (naming convention)
    echo "<h2>5. Tables with Potential Appointment/Referral References (by naming)</h2>\n";
    $stmt = $pdo->query("
        SELECT 
            TABLE_NAME,
            COLUMN_NAME,
            COLUMN_TYPE,
            IS_NULLABLE,
            COLUMN_KEY
        FROM 
            information_schema.COLUMNS 
        WHERE 
            TABLE_SCHEMA = DATABASE()
            AND (
                COLUMN_NAME LIKE '%appointment%' 
                OR COLUMN_NAME LIKE '%referral%'
                OR COLUMN_NAME = 'appointment_id'
                OR COLUMN_NAME = 'referral_id'
            )
        ORDER BY TABLE_NAME, COLUMN_NAME
    ");
    $potential_refs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($potential_refs)) {
        echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
        echo "<tr><th>Table</th><th>Column</th><th>Type</th><th>Nullable</th><th>Key</th></tr>\n";
        foreach ($potential_refs as $ref) {
            echo "<tr>";
            echo "<td>{$ref['TABLE_NAME']}</td>";
            echo "<td>{$ref['COLUMN_NAME']}</td>";
            echo "<td>{$ref['COLUMN_TYPE']}</td>";
            echo "<td>{$ref['IS_NULLABLE']}</td>";
            echo "<td>{$ref['COLUMN_KEY']}</td>";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }

    // Get sample data from appointments
    echo "<h2>6. Sample Data from APPOINTMENTS (first 5 records)</h2>\n";
    $stmt = $pdo->query("SELECT * FROM appointments LIMIT 5");
    $sample_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($sample_appointments)) {
        echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
        echo "<tr>";
        foreach (array_keys($sample_appointments[0]) as $header) {
            echo "<th>{$header}</th>";
        }
        echo "</tr>\n";
        foreach ($sample_appointments as $row) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
            }
            echo "</tr>\n";
        }
        echo "</table>\n";
    }

    // Get sample data from referrals
    echo "<h2>7. Sample Data from REFERRALS (first 5 records)</h2>\n";
    $stmt = $pdo->query("SELECT * FROM referrals LIMIT 5");
    $sample_referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($sample_referrals)) {
        echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
        echo "<tr>";
        foreach (array_keys($sample_referrals[0]) as $header) {
            echo "<th>{$header}</th>";
        }
        echo "</tr>\n";
        foreach ($sample_referrals as $row) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
            }
            echo "</tr>\n";
        }
        echo "</table>\n";
    }

    // Get all table names for context
    echo "<h2>8. All Tables in Database</h2>\n";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<ul>\n";
    foreach ($tables as $table) {
        echo "<li>{$table}</li>\n";
    }
    echo "</ul>\n";

} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>\n";
}
?>