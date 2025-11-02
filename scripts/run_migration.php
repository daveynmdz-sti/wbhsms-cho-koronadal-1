<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Activity Logging - Database Migration</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: #27ae60; background: #d5f4e6; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .error { color: #e74c3c; background: #fdf2f2; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .warning { color: #f39c12; background: #fef9e7; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .info { color: #3498db; background: #ebf3fd; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .code { background: #f8f9fa; padding: 15px; border-left: 4px solid #007bff; margin: 10px 0; font-family: monospace; }
        .step { margin: 15px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; }
        h1 { color: #2c3e50; }
        h2 { color: #34495e; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; margin: 5px; }
        .btn:hover { background: #0056b3; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #1e7e34; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔐 User Activity Logging - Database Migration</h1>
        
        <?php
        // Include database configuration
        $root_path = dirname(__DIR__);
        require_once $root_path . '/config/db.php';
        
        $migration_complete = false;
        $errors = [];
        $successes = [];
        
        // Check if migration should run
        if (isset($_GET['run_migration']) && $_GET['run_migration'] === 'true') {
            echo "<h2>🚀 Running Migration...</h2>";
            
            try {
                // Check database connection
                if (!$conn && !$pdo) {
                    throw new Exception("No database connection available. Please check your database configuration.");
                }
                
                $db = $pdo ?: $conn;
                
                // Step 1: Add user_type column
                echo "<div class='step'><h3>Step 1: Adding user_type column</h3>";
                try {
                    $sql = "ALTER TABLE `user_activity_logs` 
                            ADD COLUMN `user_type` ENUM('employee', 'admin') NOT NULL DEFAULT 'employee' 
                            AFTER `employee_id`";
                    
                    if ($pdo) {
                        $pdo->exec($sql);
                    } else {
                        $conn->query($sql);
                    }
                    
                    echo "<div class='success'>✓ user_type column added successfully</div>";
                    $successes[] = "Added user_type column";
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                        echo "<div class='warning'>⚠ user_type column already exists</div>";
                    } else {
                        echo "<div class='error'>✗ Error: " . $e->getMessage() . "</div>";
                        $errors[] = "user_type column: " . $e->getMessage();
                    }
                }
                echo "</div>";
                
                // Step 2: Add ip_address column
                echo "<div class='step'><h3>Step 2: Adding ip_address column</h3>";
                try {
                    $sql = "ALTER TABLE `user_activity_logs` 
                            ADD COLUMN `ip_address` VARCHAR(45) NULL 
                            AFTER `description`";
                    
                    if ($pdo) {
                        $pdo->exec($sql);
                    } else {
                        $conn->query($sql);
                    }
                    
                    echo "<div class='success'>✓ ip_address column added successfully</div>";
                    $successes[] = "Added ip_address column";
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                        echo "<div class='warning'>⚠ ip_address column already exists</div>";
                    } else {
                        echo "<div class='error'>✗ Error: " . $e->getMessage() . "</div>";
                        $errors[] = "ip_address column: " . $e->getMessage();
                    }
                }
                echo "</div>";
                
                // Step 3: Add device_info column
                echo "<div class='step'><h3>Step 3: Adding device_info column</h3>";
                try {
                    $sql = "ALTER TABLE `user_activity_logs` 
                            ADD COLUMN `device_info` TEXT NULL 
                            AFTER `ip_address`";
                    
                    if ($pdo) {
                        $pdo->exec($sql);
                    } else {
                        $conn->query($sql);
                    }
                    
                    echo "<div class='success'>✓ device_info column added successfully</div>";
                    $successes[] = "Added device_info column";
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                        echo "<div class='warning'>⚠ device_info column already exists</div>";
                    } else {
                        echo "<div class='error'>✗ Error: " . $e->getMessage() . "</div>";
                        $errors[] = "device_info column: " . $e->getMessage();
                    }
                }
                echo "</div>";
                
                // Step 4: Update action_type enum
                echo "<div class='step'><h3>Step 4: Updating action_type enum</h3>";
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
                    
                    echo "<div class='success'>✓ action_type enum updated successfully</div>";
                    $successes[] = "Updated action_type enum";
                } catch (Exception $e) {
                    echo "<div class='error'>✗ Error: " . $e->getMessage() . "</div>";
                    $errors[] = "action_type enum: " . $e->getMessage();
                }
                echo "</div>";
                
                // Step 5: Add indexes
                echo "<div class='step'><h3>Step 5: Adding performance indexes</h3>";
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
                        echo "<div class='success'>✓ Index $index_name created</div>";
                    } catch (Exception $e) {
                        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                            echo "<div class='warning'>⚠ Index $index_name already exists</div>";
                        } else {
                            echo "<div class='error'>✗ Index $index_name failed: " . $e->getMessage() . "</div>";
                        }
                    }
                }
                echo "</div>";
                
                // Step 6: Update existing records
                echo "<div class='step'><h3>Step 6: Updating existing records</h3>";
                try {
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
                    
                    echo "<div class='success'>✓ Updated $admin_count records with user_type = 'admin'</div>";
                    echo "<div class='success'>✓ Updated $employee_count records with user_type = 'employee'</div>";
                    $successes[] = "Updated existing records";
                } catch (Exception $e) {
                    echo "<div class='error'>✗ Error updating records: " . $e->getMessage() . "</div>";
                    $errors[] = "Update records: " . $e->getMessage();
                }
                echo "</div>";
                
                $migration_complete = true;
                
            } catch (Exception $e) {
                echo "<div class='error'><strong>Fatal Error:</strong> " . $e->getMessage() . "</div>";
                $errors[] = "Fatal: " . $e->getMessage();
            }
            
            // Show summary
            echo "<h2>📊 Migration Summary</h2>";
            
            if (!empty($successes)) {
                echo "<div class='success'>";
                echo "<strong>✓ Successful Operations:</strong><ul>";
                foreach ($successes as $success) {
                    echo "<li>$success</li>";
                }
                echo "</ul></div>";
            }
            
            if (!empty($errors)) {
                echo "<div class='error'>";
                echo "<strong>✗ Errors Encountered:</strong><ul>";
                foreach ($errors as $error) {
                    echo "<li>$error</li>";
                }
                echo "</ul></div>";
            }
            
            if (empty($errors)) {
                echo "<div class='success'><strong>🎉 Migration completed successfully!</strong></div>";
            }
        }
        
        // Check current table structure
        echo "<h2>📋 Current Table Structure</h2>";
        try {
            if ($conn || $pdo) {
                $db = $pdo ?: $conn;
                
                if ($pdo) {
                    $stmt = $pdo->query("DESCRIBE user_activity_logs");
                    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $result = $conn->query("DESCRIBE user_activity_logs");
                    $columns = $result->fetch_all(MYSQLI_ASSOC);
                }
                
                $required_columns = ['user_type', 'ip_address', 'device_info'];
                $found_columns = array_column($columns, 'Field');
                
                echo "<div class='code'>";
                echo "<strong>Required Columns Status:</strong><br>";
                foreach ($required_columns as $column) {
                    if (in_array($column, $found_columns)) {
                        echo "<span style='color: green;'>✓ $column - Present</span><br>";
                    } else {
                        echo "<span style='color: red;'>✗ $column - Missing</span><br>";
                    }
                }
                echo "</div>";
                
                $all_required_present = empty(array_diff($required_columns, $found_columns));
                
                if ($all_required_present) {
                    echo "<div class='success'>✅ All required columns are present! The database is ready for user activity logging.</div>";
                } else {
                    echo "<div class='warning'>⚠️ Some required columns are missing. Please run the migration.</div>";
                }
                
            } else {
                echo "<div class='error'>❌ Cannot connect to database. Please check your configuration.</div>";
            }
        } catch (Exception $e) {
            echo "<div class='error'>❌ Error checking table structure: " . $e->getMessage() . "</div>";
        }
        
        if (!$migration_complete && !isset($_GET['run_migration'])) {
            echo "<h2>🔧 Ready to Run Migration?</h2>";
            echo "<div class='info'>This migration will add the following columns to the user_activity_logs table:";
            echo "<ul>";
            echo "<li><strong>user_type</strong> - ENUM('employee', 'admin') - Type of user performing the action</li>";
            echo "<li><strong>ip_address</strong> - VARCHAR(45) - Client IP address</li>";
            echo "<li><strong>device_info</strong> - TEXT - User agent/device information</li>";
            echo "</ul>";
            echo "<p>It will also update the action_type enum to include new session-related actions like 'login', 'logout', 'session_start', etc.</p>";
            echo "</div>";
            
            echo "<a href='?run_migration=true' class='btn'>🚀 Run Migration Now</a>";
        }
        
        if ($migration_complete) {
            echo "<h2>🎯 Next Steps</h2>";
            echo "<div class='info'>";
            echo "<ol>";
            echo "<li><a href='test_activity_logging.php' class='btn btn-success'>Run Test Suite</a> - Verify everything is working</li>";
            echo "<li><a href='../pages/management/auth/employee_login.php' class='btn'>Test Login</a> - Try logging in to test activity logging</li>";
            echo "<li><a href='../pages/management/admin/user-management/user_activity_logs.php' class='btn'>View Activity Logs</a> - Check the logs page</li>";
            echo "</ol>";
            echo "</div>";
        }
        ?>
    </div>
</body>
</html>