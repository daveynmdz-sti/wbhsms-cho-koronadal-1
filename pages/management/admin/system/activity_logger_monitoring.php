<?php
/**
 * Production Activity Logger Monitoring Dashboard
 * Real-time monitoring and management interface for activity logging system
 * 
 * @author GitHub Copilot
 * @version 2.0 - Production Ready
 * @since November 3, 2025
 */

// Include authentication and configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check admin access
if (!isset($_SESSION['employee_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /pages/management/auth/employee_login.php');
    exit();
}

// Include activity logger with production config
$config = include $root_path . '/config/activity_logger_config.php';
require_once $root_path . '/utils/UserActivityLogger.php';

$logger = new UserActivityLogger($config);
$activePage = 'system_monitoring';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'get_metrics':
            echo json_encode($logger->getPerformanceMetrics());
            exit();
            
        case 'run_maintenance':
            $result = $logger->performMaintenance();
            echo json_encode(['success' => $result !== false, 'data' => $result]);
            exit();
            
        case 'get_recent_logs':
            $limit = intval($_POST['limit'] ?? 50);
            try {
                if ($pdo) {
                    $stmt = $pdo->prepare("
                        SELECT log_id, admin_id, employee_id, user_type, action_type, 
                               description, ip_address, device_info, created_at
                        FROM user_activity_logs 
                        ORDER BY created_at DESC 
                        LIMIT ?
                    ");
                    $stmt->execute([$limit]);
                    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $stmt = $conn->prepare("
                        SELECT log_id, admin_id, employee_id, user_type, action_type, 
                               description, ip_address, device_info, created_at
                        FROM user_activity_logs 
                        ORDER BY created_at DESC 
                        LIMIT ?
                    ");
                    $stmt->bind_param("i", $limit);
                    $stmt->execute();
                    $logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                }
                echo json_encode(['success' => true, 'logs' => $logs]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            exit();
    }
}

// Get dashboard statistics
try {
    // Total logs count
    $total_logs_query = "SELECT COUNT(*) as total FROM user_activity_logs";
    if ($pdo) {
        $stmt = $pdo->query($total_logs_query);
        $total_logs = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    } else {
        $result = $conn->query($total_logs_query);
        $total_logs = $result->fetch_assoc()['total'];
    }
    
    // Today's logs
    $today_logs_query = "SELECT COUNT(*) as today FROM user_activity_logs WHERE DATE(created_at) = CURDATE()";
    if ($pdo) {
        $stmt = $pdo->query($today_logs_query);
        $today_logs = $stmt->fetch(PDO::FETCH_ASSOC)['today'];
    } else {
        $result = $conn->query($today_logs_query);
        $today_logs = $result->fetch_assoc()['today'];
    }
    
    // Activity by type (last 24 hours)
    $activity_stats_query = "
        SELECT action_type, COUNT(*) as count 
        FROM user_activity_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY action_type 
        ORDER BY count DESC
    ";
    if ($pdo) {
        $stmt = $pdo->query($activity_stats_query);
        $activity_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $result = $conn->query($activity_stats_query);
        $activity_stats = $result->fetch_all(MYSQLI_ASSOC);
    }
    
    // Top IP addresses (last 24 hours)
    $ip_stats_query = "
        SELECT ip_address, COUNT(*) as count 
        FROM user_activity_logs 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        AND ip_address IS NOT NULL
        GROUP BY ip_address 
        ORDER BY count DESC 
        LIMIT 10
    ";
    if ($pdo) {
        $stmt = $pdo->query($ip_stats_query);
        $ip_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $result = $conn->query($ip_stats_query);
        $ip_stats = $result->fetch_all(MYSQLI_ASSOC);
    }
    
    // Error indicators
    $error_indicators = [
        'recent_failures' => 0,
        'slow_queries' => 0,
        'rate_limit_hits' => 0
    ];
    
} catch (Exception $e) {
    $error_message = "Dashboard error: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Logger Monitoring - WBHSMS</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f5f6fa; }
        .dashboard { max-width: 1200px; margin: 0 auto; }
        .header { background: #2c3e50; color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .stat-number { font-size: 2em; font-weight: bold; color: #3498db; }
        .stat-label { color: #7f8c8d; margin-top: 5px; }
        .chart-container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .logs-container { background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
        .logs-header { background: #34495e; color: white; padding: 15px; }
        .logs-table { width: 100%; border-collapse: collapse; }
        .logs-table th, .logs-table td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        .logs-table th { background: #f8f9fa; font-weight: bold; }
        .logs-table tr:hover { background: #f8f9fa; }
        .btn { background: #3498db; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #2980b9; }
        .btn-success { background: #27ae60; }
        .btn-success:hover { background: #219a52; }
        .btn-warning { background: #f39c12; }
        .btn-warning:hover { background: #e67e22; }
        .btn-danger { background: #e74c3c; }
        .btn-danger:hover { background: #c0392b; }
        .alert { padding: 15px; margin: 10px 0; border-radius: 4px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .control-panel { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .status-indicator { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 8px; }
        .status-online { background: #27ae60; }
        .status-warning { background: #f39c12; }
        .status-offline { background: #e74c3c; }
        .loading { display: none; text-align: center; padding: 20px; }
        .progress-bar { width: 100%; height: 20px; background: #ecf0f1; border-radius: 10px; overflow: hidden; margin: 10px 0; }
        .progress-fill { height: 100%; background: #3498db; transition: width 0.3s ease; }
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="header">
            <h1><span class="status-indicator status-online"></span>Activity Logger Monitoring Dashboard</h1>
            <p>Real-time monitoring and management for user activity logging system</p>
        </div>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-error">
                <strong>Error:</strong> <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>
        
        <!-- System Status & Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= number_format($total_logs ?? 0) ?></div>
                <div class="stat-label">Total Activity Logs</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= number_format($today_logs ?? 0) ?></div>
                <div class="stat-label">Today's Activities</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="active-sessions">-</div>
                <div class="stat-label">Active Sessions</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" id="system-health">
                    <span class="status-indicator status-online"></span>Healthy
                </div>
                <div class="stat-label">System Status</div>
            </div>
        </div>
        
        <!-- Control Panel -->
        <div class="control-panel">
            <h3>System Controls</h3>
            <button class="btn btn-success" onclick="refreshMetrics()">Refresh Metrics</button>
            <button class="btn btn-warning" onclick="runMaintenance()">Run Maintenance</button>
            <button class="btn" onclick="exportLogs()">Export Logs</button>
            <button class="btn" onclick="toggleAutoRefresh()">Auto Refresh: <span id="auto-refresh-status">Off</span></button>
            
            <div id="maintenance-progress" class="progress-bar" style="display: none;">
                <div class="progress-fill" style="width: 0%;"></div>
            </div>
        </div>
        
        <!-- Performance Metrics -->
        <div class="chart-container">
            <h3>Performance Metrics</h3>
            <div class="metrics-grid" id="performance-metrics">
                <div class="stat-card">
                    <div class="stat-number" id="operations-per-second">-</div>
                    <div class="stat-label">Operations/Second</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="average-response-time">-</div>
                    <div class="stat-label">Avg Response Time (ms)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="success-rate">-</div>
                    <div class="stat-label">Success Rate (%)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number" id="error-count">-</div>
                    <div class="stat-label">Errors (24h)</div>
                </div>
            </div>
        </div>
        
        <!-- Activity Statistics -->
        <div class="chart-container">
            <h3>Activity Breakdown (Last 24 Hours)</h3>
            <div class="metrics-grid">
                <?php foreach ($activity_stats as $stat): ?>
                    <div class="stat-card">
                        <div class="stat-number"><?= number_format($stat['count']) ?></div>
                        <div class="stat-label"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $stat['action_type']))) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Recent Activity Logs -->
        <div class="logs-container">
            <div class="logs-header">
                <h3>Recent Activity Logs</h3>
                <button class="btn" onclick="refreshLogs()" style="float: right;">Refresh</button>
            </div>
            <div class="loading" id="logs-loading">Loading logs...</div>
            <div style="overflow-x: auto;">
                <table class="logs-table" id="logs-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>User Type</th>
                            <th>Action</th>
                            <th>Description</th>
                            <th>IP Address</th>
                            <th>Device</th>
                        </tr>
                    </thead>
                    <tbody id="logs-tbody">
                        <!-- Logs will be loaded via JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        let autoRefreshInterval = null;
        let isAutoRefresh = false;
        
        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function() {
            refreshMetrics();
            refreshLogs();
        });
        
        // Refresh performance metrics
        function refreshMetrics() {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_metrics'
            })
            .then(response => response.json())
            .then(data => {
                if (data) {
                    document.getElementById('operations-per-second').textContent = 
                        data.operations_per_second ? data.operations_per_second.toFixed(2) : '0';
                    document.getElementById('average-response-time').textContent = 
                        data.average_execution_time ? (data.average_execution_time * 1000).toFixed(2) : '0';
                    document.getElementById('success-rate').textContent = 
                        data.success_rate ? data.success_rate.toFixed(1) : '100';
                    document.getElementById('error-count').textContent = 
                        data.error_count || '0';
                }
            })
            .catch(error => console.error('Error refreshing metrics:', error));
        }
        
        // Refresh recent logs
        function refreshLogs() {
            const loading = document.getElementById('logs-loading');
            const tbody = document.getElementById('logs-tbody');
            
            loading.style.display = 'block';
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=get_recent_logs&limit=50'
            })
            .then(response => response.json())
            .then(data => {
                loading.style.display = 'none';
                
                if (data.success) {
                    tbody.innerHTML = '';
                    data.logs.forEach(log => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>${new Date(log.created_at).toLocaleString()}</td>
                            <td><span class="status-indicator status-${log.user_type === 'admin' ? 'warning' : 'online'}"></span>${log.user_type || 'N/A'}</td>
                            <td>${log.action_type}</td>
                            <td title="${log.description}">${log.description.substring(0, 50)}${log.description.length > 50 ? '...' : ''}</td>
                            <td>${log.ip_address || 'N/A'}</td>
                            <td title="${log.device_info || 'N/A'}">${(log.device_info || 'N/A').substring(0, 30)}${(log.device_info || '').length > 30 ? '...' : ''}</td>
                        `;
                        tbody.appendChild(row);
                    });
                } else {
                    tbody.innerHTML = '<tr><td colspan="6">Error loading logs: ' + (data.error || 'Unknown error') + '</td></tr>';
                }
            })
            .catch(error => {
                loading.style.display = 'none';
                tbody.innerHTML = '<tr><td colspan="6">Error loading logs: ' + error.message + '</td></tr>';
            });
        }
        
        // Run maintenance
        function runMaintenance() {
            if (!confirm('This will archive old logs and optimize the database. Continue?')) {
                return;
            }
            
            const progressBar = document.getElementById('maintenance-progress');
            const progressFill = progressBar.querySelector('.progress-fill');
            
            progressBar.style.display = 'block';
            progressFill.style.width = '10%';
            
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=run_maintenance'
            })
            .then(response => response.json())
            .then(data => {
                progressFill.style.width = '100%';
                
                setTimeout(() => {
                    progressBar.style.display = 'none';
                    progressFill.style.width = '0%';
                    
                    if (data.success) {
                        alert('Maintenance completed successfully! ' + 
                              (data.data.archived ? data.data.archived + ' records archived.' : ''));
                        refreshMetrics();
                    } else {
                        alert('Maintenance failed. Check error logs.');
                    }
                }, 1000);
            })
            .catch(error => {
                progressBar.style.display = 'none';
                alert('Maintenance error: ' + error.message);
            });
        }
        
        // Toggle auto refresh
        function toggleAutoRefresh() {
            const status = document.getElementById('auto-refresh-status');
            
            if (isAutoRefresh) {
                clearInterval(autoRefreshInterval);
                isAutoRefresh = false;
                status.textContent = 'Off';
            } else {
                autoRefreshInterval = setInterval(() => {
                    refreshMetrics();
                    refreshLogs();
                }, 30000); // Refresh every 30 seconds
                isAutoRefresh = true;
                status.textContent = 'On';
            }
        }
        
        // Export logs (placeholder)
        function exportLogs() {
            window.open('export_activity_logs.php', '_blank');
        }
    </script>
</body>
</html>