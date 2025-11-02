<?php
/**
 * System Health Check API
 * Provides real-time health status for the activity logging system
 * 
 * @author GitHub Copilot
 * @version 2.0 - Production Ready
 * @since November 3, 2025
 */

// Set response headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Include required files
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/db.php';

// Load configuration
$config = include $root_path . '/config/activity_logger_config.php';

$health_status = [
    'timestamp' => date('c'),
    'status' => 'healthy',
    'version' => '2.0',
    'environment' => $config['environment'] ?? 'unknown',
    'checks' => [],
    'metrics' => [],
    'alerts' => []
];

$overall_healthy = true;

try {
    // Check 1: Database Connection
    $db_start = microtime(true);
    try {
        if ($pdo) {
            $stmt = $pdo->query('SELECT 1 as test');
            $result = $stmt->fetch();
            $db_time = round((microtime(true) - $db_start) * 1000, 2);
            
            $health_status['checks']['database'] = [
                'status' => 'healthy',
                'response_time_ms' => $db_time,
                'connection_type' => 'PDO'
            ];
        } elseif ($conn) {
            $result = $conn->query('SELECT 1 as test');
            $db_time = round((microtime(true) - $db_start) * 1000, 2);
            
            $health_status['checks']['database'] = [
                'status' => 'healthy',
                'response_time_ms' => $db_time,
                'connection_type' => 'MySQLi'
            ];
        } else {
            throw new Exception('No database connection available');
        }
        
        if ($db_time > 100) { // Slow database response
            $health_status['alerts'][] = [
                'type' => 'warning',
                'message' => "Slow database response: {$db_time}ms"
            ];
        }
        
    } catch (Exception $e) {
        $health_status['checks']['database'] = [
            'status' => 'unhealthy',
            'error' => $e->getMessage()
        ];
        $overall_healthy = false;
        $health_status['alerts'][] = [
            'type' => 'error',
            'message' => 'Database connection failed'
        ];
    }
    
    // Check 2: Activity Logs Table
    try {
        if ($pdo) {
            $stmt = $pdo->query('SELECT COUNT(*) as count FROM user_activity_logs LIMIT 1');
            $health_status['checks']['activity_logs_table'] = [
                'status' => 'healthy',
                'accessible' => true
            ];
        } elseif ($conn) {
            $result = $conn->query('SELECT COUNT(*) as count FROM user_activity_logs LIMIT 1');
            $health_status['checks']['activity_logs_table'] = [
                'status' => 'healthy',
                'accessible' => true
            ];
        }
    } catch (Exception $e) {
        $health_status['checks']['activity_logs_table'] = [
            'status' => 'unhealthy',
            'error' => $e->getMessage()
        ];
        $overall_healthy = false;
    }
    
    // Check 3: Log Directory
    $log_files = $config['log_files'] ?? [];
    $log_dir = dirname($log_files['error_log'] ?? $root_path . '/logs/activity_logger_errors.log');
    
    if (is_dir($log_dir) && is_writable($log_dir)) {
        $health_status['checks']['log_directory'] = [
            'status' => 'healthy',
            'path' => $log_dir,
            'writable' => true
        ];
    } else {
        $health_status['checks']['log_directory'] = [
            'status' => 'unhealthy',
            'path' => $log_dir,
            'writable' => false
        ];
        $overall_healthy = false;
        $health_status['alerts'][] = [
            'type' => 'error',
            'message' => 'Log directory not writable'
        ];
    }
    
    // Check 4: Cache Directory
    $cache_dir = $config['cache']['path'] ?? $root_path . '/cache';
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0750, true);
    }
    
    if (is_dir($cache_dir) && is_writable($cache_dir)) {
        $health_status['checks']['cache_directory'] = [
            'status' => 'healthy',
            'path' => $cache_dir,
            'writable' => true
        ];
    } else {
        $health_status['checks']['cache_directory'] = [
            'status' => 'unhealthy',
            'path' => $cache_dir,
            'writable' => false
        ];
        // Cache directory issues are warnings, not errors
        $health_status['alerts'][] = [
            'type' => 'warning',
            'message' => 'Cache directory not accessible'
        ];
    }
    
    // Check 5: Disk Space
    $free_bytes = disk_free_space($root_path);
    $total_bytes = disk_total_space($root_path);
    $free_percentage = ($free_bytes / $total_bytes) * 100;
    
    if ($free_percentage > 10) {
        $health_status['checks']['disk_space'] = [
            'status' => 'healthy',
            'free_percentage' => round($free_percentage, 1),
            'free_mb' => round($free_bytes / 1024 / 1024, 0)
        ];
    } elseif ($free_percentage > 5) {
        $health_status['checks']['disk_space'] = [
            'status' => 'warning',
            'free_percentage' => round($free_percentage, 1),
            'free_mb' => round($free_bytes / 1024 / 1024, 0)
        ];
        $health_status['alerts'][] = [
            'type' => 'warning',
            'message' => 'Low disk space: ' . round($free_percentage, 1) . '% free'
        ];
    } else {
        $health_status['checks']['disk_space'] = [
            'status' => 'critical',
            'free_percentage' => round($free_percentage, 1),
            'free_mb' => round($free_bytes / 1024 / 1024, 0)
        ];
        $overall_healthy = false;
        $health_status['alerts'][] = [
            'type' => 'error',
            'message' => 'Critical disk space: ' . round($free_percentage, 1) . '% free'
        ];
    }
    
    // Check 6: Memory Usage
    $memory_usage = memory_get_usage(true);
    $memory_limit = ini_get('memory_limit');
    $memory_limit_bytes = intval($memory_limit) * 1024 * 1024;
    $memory_percentage = ($memory_usage / $memory_limit_bytes) * 100;
    
    if ($memory_percentage < 80) {
        $health_status['checks']['memory_usage'] = [
            'status' => 'healthy',
            'usage_percentage' => round($memory_percentage, 1),
            'usage_mb' => round($memory_usage / 1024 / 1024, 1)
        ];
    } elseif ($memory_percentage < 90) {
        $health_status['checks']['memory_usage'] = [
            'status' => 'warning',
            'usage_percentage' => round($memory_percentage, 1),
            'usage_mb' => round($memory_usage / 1024 / 1024, 1)
        ];
        $health_status['alerts'][] = [
            'type' => 'warning',
            'message' => 'High memory usage: ' . round($memory_percentage, 1) . '%'
        ];
    } else {
        $health_status['checks']['memory_usage'] = [
            'status' => 'critical',
            'usage_percentage' => round($memory_percentage, 1),
            'usage_mb' => round($memory_usage / 1024 / 1024, 1)
        ];
        $overall_healthy = false;
        $health_status['alerts'][] = [
            'type' => 'error',
            'message' => 'Critical memory usage: ' . round($memory_percentage, 1) . '%'
        ];
    }
    
    // Metrics Collection
    if ($pdo || $conn) {
        try {
            // Recent activity metrics
            $db = $pdo ?: $conn;
            
            if ($pdo) {
                // Total logs
                $stmt = $pdo->query('SELECT COUNT(*) as total FROM user_activity_logs');
                $total_logs = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                
                // Today's logs
                $stmt = $pdo->query('SELECT COUNT(*) as today FROM user_activity_logs WHERE DATE(created_at) = CURDATE()');
                $today_logs = $stmt->fetch(PDO::FETCH_ASSOC)['today'];
                
                // Last hour logs
                $stmt = $pdo->query('SELECT COUNT(*) as last_hour FROM user_activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
                $last_hour_logs = $stmt->fetch(PDO::FETCH_ASSOC)['last_hour'];
                
                // Failed logins in last 24h
                $stmt = $pdo->query("SELECT COUNT(*) as failed FROM user_activity_logs WHERE action_type = 'login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                $failed_logins = $stmt->fetch(PDO::FETCH_ASSOC)['failed'];
                
                // Unique IPs in last hour
                $stmt = $pdo->query('SELECT COUNT(DISTINCT ip_address) as unique_ips FROM user_activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
                $unique_ips = $stmt->fetch(PDO::FETCH_ASSOC)['unique_ips'];
                
            } else {
                // MySQLi version
                $result = $conn->query('SELECT COUNT(*) as total FROM user_activity_logs');
                $total_logs = $result->fetch_assoc()['total'];
                
                $result = $conn->query('SELECT COUNT(*) as today FROM user_activity_logs WHERE DATE(created_at) = CURDATE()');
                $today_logs = $result->fetch_assoc()['today'];
                
                $result = $conn->query('SELECT COUNT(*) as last_hour FROM user_activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
                $last_hour_logs = $result->fetch_assoc()['last_hour'];
                
                $result = $conn->query("SELECT COUNT(*) as failed FROM user_activity_logs WHERE action_type = 'login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
                $failed_logins = $result->fetch_assoc()['failed'];
                
                $result = $conn->query('SELECT COUNT(DISTINCT ip_address) as unique_ips FROM user_activity_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
                $unique_ips = $result->fetch_assoc()['unique_ips'];
            }
            
            $health_status['metrics'] = [
                'total_logs' => intval($total_logs),
                'today_logs' => intval($today_logs),
                'last_hour_logs' => intval($last_hour_logs),
                'failed_logins_24h' => intval($failed_logins),
                'unique_ips_1h' => intval($unique_ips),
                'logs_per_hour_avg' => round($last_hour_logs, 1),
                'logs_per_day_avg' => round($today_logs, 1)
            ];
            
            // Security alerts
            if ($failed_logins > 50) {
                $health_status['alerts'][] = [
                    'type' => 'warning',
                    'message' => "High number of failed logins: $failed_logins in last 24 hours"
                ];
            }
            
            if ($unique_ips > 30) {
                $health_status['alerts'][] = [
                    'type' => 'warning',
                    'message' => "Unusual activity: $unique_ips unique IP addresses in last hour"
                ];
            }
            
        } catch (Exception $e) {
            $health_status['alerts'][] = [
                'type' => 'warning',
                'message' => 'Failed to collect metrics: ' . $e->getMessage()
            ];
        }
    }
    
    // Overall status determination
    if (!$overall_healthy) {
        $health_status['status'] = 'unhealthy';
        http_response_code(503); // Service Unavailable
    } else {
        // Check for warnings
        $warning_count = count(array_filter($health_status['alerts'], function($alert) {
            return $alert['type'] === 'warning';
        }));
        
        if ($warning_count > 0) {
            $health_status['status'] = 'degraded';
            http_response_code(200); // OK but with warnings
        } else {
            $health_status['status'] = 'healthy';
            http_response_code(200); // OK
        }
    }
    
    $health_status['summary'] = [
        'checks_total' => count($health_status['checks']),
        'checks_healthy' => count(array_filter($health_status['checks'], function($check) {
            return $check['status'] === 'healthy';
        })),
        'alerts_total' => count($health_status['alerts']),
        'alerts_errors' => count(array_filter($health_status['alerts'], function($alert) {
            return $alert['type'] === 'error';
        })),
        'alerts_warnings' => count(array_filter($health_status['alerts'], function($alert) {
            return $alert['type'] === 'warning';
        }))
    ];
    
} catch (Exception $e) {
    $health_status['status'] = 'error';
    $health_status['error'] = $e->getMessage();
    http_response_code(500); // Internal Server Error
}

// Add execution time
$health_status['execution_time_ms'] = round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2);

// Output JSON response
echo json_encode($health_status, JSON_PRETTY_PRINT);

// Log health check if there are issues
if ($health_status['status'] !== 'healthy') {
    error_log("[HEALTH_CHECK] Status: " . $health_status['status'] . 
              " - Alerts: " . count($health_status['alerts']) .
              " - Execution: " . $health_status['execution_time_ms'] . "ms");
}
?>