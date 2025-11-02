<?php
/**
 * Database Migration Script
 * Adds user_type, ip_address, and device_info columns to user_activity_logs table
 * Updates action_type enum to include new session-related activities
 * 
 * @author GitHub Copilot
 * @version 1.0
 * @since November 3, 2025
 */

// Include database configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/db.php';

$migrations_run = [];
$errors = [];

echo "Starting database migration for user activity logging...\n\n";

try {
    // Check if we have a database connection
    if (!$conn && !$pdo) {
        throw new Exception("No database connection available");
    }
    
    $db = $pdo ?: $conn;
    
    // Migration 1: Add user_type column
    echo "1. Adding user_type column...\n";
    try {
        $sql = "ALTER TABLE `user_activity_logs` 
                ADD COLUMN `user_type` ENUM('employee', 'admin') NOT NULL DEFAULT 'employee' 
                AFTER `employee_id`";
        
        if ($pdo) {
            $pdo->exec($sql);
        } else {
            $conn->query($sql);
        }
        
        $migrations_run[] = "Added user_type column";
        echo "✓ user_type column added successfully\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ user_type column already exists\n";
        } else {
            $errors[] = "Failed to add user_type column: " . $e->getMessage();
            echo "✗ Failed to add user_type column: " . $e->getMessage() . "\n";
        }
    }
    
    // Migration 2: Add ip_address column
    echo "\n2. Adding ip_address column...\n";
    try {
        $sql = "ALTER TABLE `user_activity_logs` 
                ADD COLUMN `ip_address` VARCHAR(45) NULL 
                AFTER `description`";
        
        if ($pdo) {
            $pdo->exec($sql);
        } else {
            $conn->query($sql);
        }
        
        $migrations_run[] = "Added ip_address column";
        echo "✓ ip_address column added successfully\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ ip_address column already exists\n";
        } else {
            $errors[] = "Failed to add ip_address column: " . $e->getMessage();
            echo "✗ Failed to add ip_address column: " . $e->getMessage() . "\n";
        }
    }
    
    // Migration 3: Add device_info column
    echo "\n3. Adding device_info column...\n";
    try {
        $sql = "ALTER TABLE `user_activity_logs` 
                ADD COLUMN `device_info` TEXT NULL 
                AFTER `ip_address`";
        
        if ($pdo) {
            $pdo->exec($sql);
        } else {
            $conn->query($sql);
        }
        
        $migrations_run[] = "Added device_info column";
        echo "✓ device_info column added successfully\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ device_info column already exists\n";
        } else {
            $errors[] = "Failed to add device_info column: " . $e->getMessage();
            echo "✗ Failed to add device_info column: " . $e->getMessage() . "\n";
        }
    }
    
    // Migration 4: Update action_type enum
    echo "\n4. Updating action_type enum...\n";
    try {
        $sql = "ALTER TABLE `user_activity_logs` 
                MODIFY COLUMN `action_type` ENUM(
                    'create',
                    'update', 
                    'deactivate',
                    'password_reset',
                    'role_change',
                    'login',
                    'login_failed',
                    'logout',
                    'password_change',
                    'session_start',
                    'session_end',
                    'session_timeout',
                    'account_lock',
                    'account_unlock',
                    'unlock'
                ) NOT NULL";
        
        if ($pdo) {
            $pdo->exec($sql);
        } else {
            $conn->query($sql);
        }
        
        $migrations_run[] = "Updated action_type enum";
        echo "✓ action_type enum updated successfully\n";
    } catch (Exception $e) {
        $errors[] = "Failed to update action_type enum: " . $e->getMessage();
        echo "✗ Failed to update action_type enum: " . $e->getMessage() . "\n";
    }
    
    // Migration 5: Add indexes for performance
    echo "\n5. Adding performance indexes...\n";
    
    $indexes = [
        'idx_user_activity_logs_user_type' => "CREATE INDEX `idx_user_activity_logs_user_type` ON `user_activity_logs` (`user_type`)",
        'idx_user_activity_logs_action_type' => "CREATE INDEX `idx_user_activity_logs_action_type` ON `user_activity_logs` (`action_type`)",
        'idx_user_activity_logs_created_at' => "CREATE INDEX `idx_user_activity_logs_created_at` ON `user_activity_logs` (`created_at`)",
        'idx_user_activity_logs_ip_address' => "CREATE INDEX `idx_user_activity_logs_ip_address` ON `user_activity_logs` (`ip_address`)"
    ];
    
    foreach ($indexes as $index_name => $sql) {
        try {
            if ($pdo) {
                $pdo->exec($sql);
            } else {
                $conn->query($sql);
            }
            echo "✓ Index $index_name created successfully\n";
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                echo "✓ Index $index_name already exists\n";
            } else {
                echo "⚠ Failed to create index $index_name: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // Migration 6: Update existing records
    echo "\n6. Updating existing records...\n";
    try {
        // Update user_type for existing records based on admin_id
        if ($pdo) {
            $stmt = $pdo->prepare("UPDATE `user_activity_logs` SET `user_type` = 'admin' WHERE `admin_id` IS NOT NULL AND `admin_id` > 0");
            $stmt->execute();
            $admin_count = $stmt->rowCount();
            
            $stmt = $pdo->prepare("UPDATE `user_activity_logs` SET `user_type` = 'employee' WHERE (`admin_id` IS NULL OR `admin_id` = 0) AND `employee_id` IS NOT NULL");
            $stmt->execute();
            $employee_count = $stmt->rowCount();
        } else {
            $result = $conn->query("UPDATE `user_activity_logs` SET `user_type` = 'admin' WHERE `admin_id` IS NOT NULL AND `admin_id` > 0");
            $admin_count = $conn->affected_rows;
            
            $result = $conn->query("UPDATE `user_activity_logs` SET `user_type` = 'employee' WHERE (`admin_id` IS NULL OR `admin_id` = 0) AND `employee_id` IS NOT NULL");
            $employee_count = $conn->affected_rows;
        }
        
        echo "✓ Updated $admin_count records with user_type = 'admin'\n";
        echo "✓ Updated $employee_count records with user_type = 'employee'\n";
        
    } catch (Exception $e) {
        $errors[] = "Failed to update existing records: " . $e->getMessage();
        echo "✗ Failed to update existing records: " . $e->getMessage() . "\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "MIGRATION SUMMARY\n";
    echo str_repeat("=", 60) . "\n";
    
    if (!empty($migrations_run)) {
        echo "Successfully completed migrations:\n";
        foreach ($migrations_run as $migration) {
            echo "✓ $migration\n";
        }
    }
    
    if (!empty($errors)) {
        echo "\nErrors encountered:\n";
        foreach ($errors as $error) {
            echo "✗ $error\n";
        }
    }
    
    echo "\nMigration completed!\n";
    echo "You can now use the comprehensive user activity logging system.\n\n";
    
    // Test the new structure
    echo "Testing new structure...\n";
    if ($pdo) {
        $stmt = $pdo->query("DESCRIBE user_activity_logs");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $result = $conn->query("DESCRIBE user_activity_logs");
        $columns = $result->fetch_all(MYSQLI_ASSOC);
    }
    
    $required_columns = ['user_type', 'ip_address', 'device_info'];
    $found_columns = array_column($columns, 'Field');
    
    foreach ($required_columns as $column) {
        if (in_array($column, $found_columns)) {
            echo "✓ Column '$column' exists\n";
        } else {
            echo "✗ Column '$column' missing\n";
        }
    }
    
} catch (Exception $e) {
    echo "✗ Fatal error during migration: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nMigration script completed successfully!\n";
?>