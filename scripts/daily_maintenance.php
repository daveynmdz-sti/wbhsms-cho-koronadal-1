<?php
/**
 * Daily Maintenance Script for Activity Logger
 * Automated cleanup, optimization, and health checks
 * 
 * @author GitHub Copilot
 * @version 2.0 - Production Ready
 * @since November 3, 2025
 */

// Set script execution context
ini_set('max_execution_time', 300); // 5 minutes max
ini_set('memory_limit', '256M');

// Include required files
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/db.php';

// Load production configuration
$config = include $root_path . '/config/activity_logger_config.php';
require_once $root_path . '/utils/UserActivityLogger.php';

// Initialize logger with production config
$logger = new UserActivityLogger($config);

// Log the maintenance start
error_log("[MAINTENANCE] Daily maintenance script started at " . date('Y-m-d H:i:s'));

$maintenance_report = [
    'start_time' => microtime(true),
    'tasks_completed' => [],
    'errors' => [],
    'statistics' => []
];

try {
    // Task 1: Database Optimization
    echo "1. Performing database optimization...\n";
    
    if ($pdo) {
        // Optimize tables
        $tables = ['user_activity_logs', 'user_activity_logs_archive'];
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("OPTIMIZE TABLE `$table`");
                $maintenance_report['tasks_completed'][] = "Optimized table: $table";
                echo "   ✓ Optimized table: $table\n";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), "doesn't exist") === false) {
                    $maintenance_report['errors'][] = "Failed to optimize $table: " . $e->getMessage();
                    echo "   ✗ Failed to optimize $table: " . $e->getMessage() . "\n";
                }
            }
        }
        
        // Update table statistics
        $pdo->query("ANALYZE TABLE user_activity_logs");
        $maintenance_report['tasks_completed'][] = "Updated table statistics";
        echo "   ✓ Updated table statistics\n";
    }
    
    // Task 2: Log Archival and Cleanup
    echo "\n2. Performing log archival and cleanup...\n";
    
    $retention_days = $config['log_retention_days'];
    $archive_threshold = $config['archive_threshold_days'] ?? 30;
    
    // Archive old logs
    $archive_date = date('Y-m-d', strtotime("-{$archive_threshold} days"));
    $retention_date = date('Y-m-d', strtotime("-{$retention_days} days"));
    
    if ($pdo) {
        // Create archive table if it doesn't exist
        $pdo->query("CREATE TABLE IF NOT EXISTS user_activity_logs_archive LIKE user_activity_logs");
        
        // Move logs to archive
        $stmt = $pdo->prepare("
            INSERT INTO user_activity_logs_archive 
            SELECT * FROM user_activity_logs 
            WHERE DATE(created_at) < ? AND DATE(created_at) >= ?
        ");
        $stmt->execute([$archive_date, $retention_date]);
        $archived_count = $stmt->rowCount();
        
        if ($archived_count > 0) {
            // Remove archived records from main table
            $stmt = $pdo->prepare("
                DELETE FROM user_activity_logs 
                WHERE DATE(created_at) < ? AND DATE(created_at) >= ?
            ");
            $stmt->execute([$archive_date, $retention_date]);
            
            $maintenance_report['tasks_completed'][] = "Archived $archived_count log records";
            echo "   ✓ Archived $archived_count log records\n";
        } else {
            echo "   ✓ No records to archive\n";
        }
        
        // Delete very old records (beyond retention period)
        $stmt = $pdo->prepare("DELETE FROM user_activity_logs_archive WHERE DATE(created_at) < ?");
        $stmt->execute([$retention_date]);
        $deleted_count = $stmt->rowCount();
        
        if ($deleted_count > 0) {
            $maintenance_report['tasks_completed'][] = "Deleted $deleted_count expired archive records";
            echo "   ✓ Deleted $deleted_count expired archive records\n";
        }
    }
    
    // Task 3: Log File Rotation and Cleanup
    echo "\n3. Cleaning up log files...\n";
    
    $log_files = [
        $config['log_files']['error_log'] ?? $root_path . '/logs/activity_logger_errors.log',
        $config['log_files']['access_log'] ?? $root_path . '/logs/activity_logger_access.log',
        $config['log_files']['performance_log'] ?? $root_path . '/logs/activity_logger_performance.log'
    ];
    
    foreach ($log_files as $log_file) {
        if (file_exists($log_file)) {
            $file_size = filesize($log_file);
            $max_size = ($config['max_log_file_size_mb'] ?? 100) * 1024 * 1024;
            
            if ($file_size > $max_size) {
                // Rotate log file
                $backup_file = $log_file . '.' . date('Y-m-d-H-i-s');
                if (rename($log_file, $backup_file)) {
                    // Compress backup
                    if ($config['compress_old_logs'] ?? true) {
                        exec("gzip '$backup_file'");
                    }
                    
                    $maintenance_report['tasks_completed'][] = "Rotated log file: " . basename($log_file);
                    echo "   ✓ Rotated log file: " . basename($log_file) . "\n";
                }
            }
            
            // Clean old rotated logs
            $log_dir = dirname($log_file);
            $log_name = basename($log_file);
            $pattern = $log_dir . '/' . $log_name . '.*';
            
            foreach (glob($pattern) as $old_log) {
                if (filemtime($old_log) < strtotime('-30 days')) {
                    unlink($old_log);
                    echo "   ✓ Cleaned old log: " . basename($old_log) . "\n";
                }
            }
        }
    }
    
    // Task 4: Performance Statistics Collection
    echo "\n4. Collecting performance statistics...\n";
    
    if ($pdo) {
        // Get current statistics
        $stats = [
            'total_logs' => 0,
            'today_logs' => 0,
            'last_7_days' => 0,
            'archive_count' => 0,
            'table_size_mb' => 0
        ];
        
        // Total logs
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM user_activity_logs");
        $stats['total_logs'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Today's logs
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM user_activity_logs WHERE DATE(created_at) = CURDATE()");
        $stats['today_logs'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Last 7 days
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM user_activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stats['last_7_days'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Archive count
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM user_activity_logs_archive");
            $stats['archive_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        } catch (Exception $e) {
            $stats['archive_count'] = 0;
        }
        
        // Table size
        $stmt = $pdo->query("
            SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() AND table_name = 'user_activity_logs'
        ");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['table_size_mb'] = $result['size_mb'] ?? 0;
        
        $maintenance_report['statistics'] = $stats;
        
        echo "   ✓ Total logs: " . number_format($stats['total_logs']) . "\n";
        echo "   ✓ Today's logs: " . number_format($stats['today_logs']) . "\n";
        echo "   ✓ Last 7 days: " . number_format($stats['last_7_days']) . "\n";
        echo "   ✓ Archive count: " . number_format($stats['archive_count']) . "\n";
        echo "   ✓ Table size: " . $stats['table_size_mb'] . " MB\n";
    }
    
    // Task 5: System Health Checks
    echo "\n5. Performing system health checks...\n";
    
    $health_checks = [
        'database_connection' => false,
        'log_directory_writable' => false,
        'cache_directory_writable' => false,
        'disk_space_ok' => false,
        'memory_usage_ok' => false
    ];
    
    // Database connection
    try {
        if ($pdo) {
            $pdo->query('SELECT 1');
            $health_checks['database_connection'] = true;
            echo "   ✓ Database connection: OK\n";
        }
    } catch (Exception $e) {
        $maintenance_report['errors'][] = "Database connection failed: " . $e->getMessage();
        echo "   ✗ Database connection: FAILED\n";
    }
    
    // Log directory writable
    $log_dir = dirname($config['log_files']['error_log']);
    if (is_writable($log_dir)) {
        $health_checks['log_directory_writable'] = true;
        echo "   ✓ Log directory writable: OK\n";
    } else {
        $maintenance_report['errors'][] = "Log directory not writable: $log_dir";
        echo "   ✗ Log directory writable: FAILED\n";
    }
    
    // Cache directory writable
    $cache_dir = $config['cache']['path'] ?? $root_path . '/cache';
    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0750, true);
    }
    if (is_writable($cache_dir)) {
        $health_checks['cache_directory_writable'] = true;
        echo "   ✓ Cache directory writable: OK\n";
    } else {
        $maintenance_report['errors'][] = "Cache directory not writable: $cache_dir";
        echo "   ✗ Cache directory writable: FAILED\n";
    }
    
    // Disk space check
    $disk_usage = disk_free_space($root_path) / disk_total_space($root_path) * 100;
    if ($disk_usage > 10) { // At least 10% free space
        $health_checks['disk_space_ok'] = true;
        echo "   ✓ Disk space: OK (" . round(100 - $disk_usage, 1) . "% used)\n";
    } else {
        $maintenance_report['errors'][] = "Low disk space: " . round(100 - $disk_usage, 1) . "% used";
        echo "   ✗ Disk space: LOW (" . round(100 - $disk_usage, 1) . "% used)\n";
    }
    
    // Memory usage check
    $memory_usage = memory_get_usage(true) / 1024 / 1024; // MB
    $memory_limit = ini_get('memory_limit');
    $memory_limit_mb = intval($memory_limit);
    if ($memory_usage < $memory_limit_mb * 0.8) {
        $health_checks['memory_usage_ok'] = true;
        echo "   ✓ Memory usage: OK (" . round($memory_usage, 1) . " MB)\n";
    } else {
        $maintenance_report['errors'][] = "High memory usage: " . round($memory_usage, 1) . " MB";
        echo "   ✗ Memory usage: HIGH (" . round($memory_usage, 1) . " MB)\n";
    }
    
    $maintenance_report['health_checks'] = $health_checks;
    
    // Task 6: Alert Generation
    echo "\n6. Checking alert conditions...\n";
    
    $alerts = [];
    
    // Check for high error rate
    if ($pdo) {
        $stmt = $pdo->query("
            SELECT COUNT(*) as error_count 
            FROM user_activity_logs 
            WHERE action_type LIKE '%failed%' 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $error_count = $stmt->fetch(PDO::FETCH_ASSOC)['error_count'];
        
        if ($error_count > 100) { // More than 100 failed actions in 24h
            $alerts[] = "High error rate detected: $error_count failed actions in last 24 hours";
        }
        
        // Check for unusual activity patterns
        $stmt = $pdo->query("
            SELECT COUNT(DISTINCT ip_address) as unique_ips 
            FROM user_activity_logs 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $unique_ips = $stmt->fetch(PDO::FETCH_ASSOC)['unique_ips'];
        
        if ($unique_ips > 50) { // More than 50 unique IPs in 1 hour
            $alerts[] = "Unusual activity pattern: $unique_ips unique IP addresses in last hour";
        }
    }
    
    // System health alerts
    foreach ($health_checks as $check => $status) {
        if (!$status) {
            $alerts[] = "Health check failed: $check";
        }
    }
    
    if (!empty($alerts)) {
        $maintenance_report['alerts'] = $alerts;
        echo "   ⚠ Generated " . count($alerts) . " alerts\n";
        
        // Send alerts if configured
        if ($config['alert_on_failures'] ?? false) {
            foreach ($alerts as $alert) {
                error_log("[ALERT] $alert");
            }
        }
    } else {
        echo "   ✓ No alerts generated\n";
    }
    
} catch (Exception $e) {
    $maintenance_report['errors'][] = "Maintenance script error: " . $e->getMessage();
    echo "\n✗ Maintenance script error: " . $e->getMessage() . "\n";
}

// Generate final report
$maintenance_report['end_time'] = microtime(true);
$maintenance_report['duration_seconds'] = round($maintenance_report['end_time'] - $maintenance_report['start_time'], 2);

echo "\n" . str_repeat("=", 60) . "\n";
echo "MAINTENANCE REPORT\n";
echo str_repeat("=", 60) . "\n";
echo "Duration: " . $maintenance_report['duration_seconds'] . " seconds\n";
echo "Tasks completed: " . count($maintenance_report['tasks_completed']) . "\n";
echo "Errors: " . count($maintenance_report['errors']) . "\n";
echo "Alerts: " . count($maintenance_report['alerts'] ?? []) . "\n";

if (!empty($maintenance_report['tasks_completed'])) {
    echo "\nCompleted tasks:\n";
    foreach ($maintenance_report['tasks_completed'] as $task) {
        echo "  ✓ $task\n";
    }
}

if (!empty($maintenance_report['errors'])) {
    echo "\nErrors:\n";
    foreach ($maintenance_report['errors'] as $error) {
        echo "  ✗ $error\n";
    }
}

// Save report to file
$report_file = $root_path . '/logs/maintenance_reports/daily_' . date('Y-m-d') . '.json';
$report_dir = dirname($report_file);

if (!is_dir($report_dir)) {
    mkdir($report_dir, 0750, true);
}

file_put_contents($report_file, json_encode($maintenance_report, JSON_PRETTY_PRINT));

// Log completion
error_log("[MAINTENANCE] Daily maintenance script completed at " . date('Y-m-d H:i:s') . 
          " - Duration: " . $maintenance_report['duration_seconds'] . "s, " .
          "Tasks: " . count($maintenance_report['tasks_completed']) . ", " .
          "Errors: " . count($maintenance_report['errors']));

echo "\nMaintenance completed! Report saved to: $report_file\n";

// Exit with appropriate code
exit(empty($maintenance_report['errors']) ? 0 : 1);
?>