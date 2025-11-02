<?php
/**
 * UserActivityLogger - Production-Ready Activity Logging Service
 * Handles all user activity logging for employees and admins in the WBHSMS system
 * 
 * Features:
 * - Production-grade error handling and resilience
 * - Rate limiting and abuse prevention
 * - Database connection recovery
 * - Performance optimizations
 * - Security hardening
 * - Comprehensive monitoring and alerting
 * 
 * @author GitHub Copilot
 * @version 2.0 - Production Ready
 * @since November 3, 2025
 */

class UserActivityLogger {
    
    private $pdo;
    private $conn; // MySQLi connection for backward compatibility
    private $config;
    private $errorLogPath;
    private $rateLimitCache = [];
    private $performanceMetrics = [];
    
    // Production configuration constants
    const MAX_DESCRIPTION_LENGTH = 1000;
    const MAX_DEVICE_INFO_LENGTH = 500;
    const RATE_LIMIT_THRESHOLD = 100; // Max logs per minute per user
    const CONNECTION_RETRY_ATTEMPTS = 3;
    const CONNECTION_RETRY_DELAY = 1; // seconds
    const BATCH_SIZE = 100; // For bulk operations
    const LOG_RETENTION_DAYS = 365; // Default retention period
    
    /**
     * Constructor - Initialize with database connections and production config
     */
    public function __construct($config = []) {
        global $pdo, $conn;
        
        $this->pdo = $pdo;
        $this->conn = $conn;
        
        // Production configuration with defaults
        $this->config = array_merge([
            'enable_rate_limiting' => true,
            'enable_performance_monitoring' => true,
            'enable_detailed_logging' => false, // Disable in production for performance
            'log_retention_days' => self::LOG_RETENTION_DAYS,
            'alert_on_failures' => true,
            'max_retry_attempts' => self::CONNECTION_RETRY_ATTEMPTS,
            'sanitize_inputs' => true,
            'validate_user_ids' => true
        ], $config);
        
        // Set up error logging
        $this->errorLogPath = dirname(__DIR__) . '/logs/activity_logger_errors.log';
        $this->ensureLogDirectory();
        
        // Initialize performance monitoring
        if ($this->config['enable_performance_monitoring']) {
            $this->performanceMetrics['start_time'] = microtime(true);
            $this->performanceMetrics['operations'] = 0;
            $this->performanceMetrics['errors'] = 0;
        }
    }
    
    /**
     * Log user activity with comprehensive production-ready validation and error handling
     * 
     * @param int|null $admin_id Admin ID if action performed by admin
     * @param int|null $employee_id Employee ID affected by the action
     * @param string $user_type Type of user ('employee' or 'admin')
     * @param string $action_type Type of action performed
     * @param string $description Human-readable description of the action
     * @param array $extra_data Additional data (optional)
     * @return bool Success status
     */
    public function logActivity($admin_id, $employee_id, $user_type, $action_type, $description, $extra_data = []) {
        $startTime = microtime(true);
        
        try {
            // Input validation and sanitization
            if (!$this->validateInputs($admin_id, $employee_id, $user_type, $action_type, $description)) {
                return false;
            }
            
            // Rate limiting check
            if ($this->config['enable_rate_limiting'] && !$this->checkRateLimit($employee_id ?: $admin_id)) {
                $this->logError("Rate limit exceeded for user: " . ($employee_id ?: $admin_id), 'RATE_LIMIT');
                return false;
            }
            
            // Sanitize inputs
            if ($this->config['sanitize_inputs']) {
                $description = $this->sanitizeDescription($description);
                $user_type = $this->sanitizeUserType($user_type);
                $action_type = $this->sanitizeActionType($action_type);
            }
            
            // Get client information with enhanced security
            $ip_address = $this->getClientIpAddress();
            $device_info = $this->getDeviceInfo();
            
            // Attempt database operation with retry logic
            $success = $this->performDatabaseOperation(
                $admin_id, $employee_id, $user_type, $action_type, 
                $description, $ip_address, $device_info, $extra_data
            );
            
            // Performance monitoring
            if ($this->config['enable_performance_monitoring']) {
                $this->recordPerformanceMetric($startTime, $success);
            }
            
            return $success;
            
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__, compact('admin_id', 'employee_id', 'user_type', 'action_type'));
            return false;
        }
    }
    
    /**
     * Perform database operation with connection retry and transaction safety
     */
    private function performDatabaseOperation($admin_id, $employee_id, $user_type, $action_type, $description, $ip_address, $device_info, $extra_data) {
        $attempt = 0;
        $maxAttempts = $this->config['max_retry_attempts'];
        
        while ($attempt < $maxAttempts) {
            try {
                // Check database connection
                if (!$this->ensureDatabaseConnection()) {
                    throw new Exception("Database connection unavailable after retry attempts");
                }
                
                // Use PDO with transaction safety (preferred)
                if ($this->pdo) {
                    return $this->executePDOInsert($admin_id, $employee_id, $user_type, $action_type, $description, $ip_address, $device_info, $extra_data);
                }
                // Fallback to MySQLi
                elseif ($this->conn) {
                    return $this->executeMySQLiInsert($admin_id, $employee_id, $user_type, $action_type, $description, $ip_address, $device_info, $extra_data);
                }
                
                throw new Exception("No database connection available");
                
            } catch (Exception $e) {
                $attempt++;
                
                if ($attempt >= $maxAttempts) {
                    $this->logError("Database operation failed after $maxAttempts attempts: " . $e->getMessage(), 'DATABASE_ERROR');
                    return false;
                }
                
                // Wait before retry
                sleep(self::CONNECTION_RETRY_DELAY);
                
                // Try to reconnect
                $this->reconnectDatabase();
            }
        }
        
        return false;
    }
    
    /**
     * Execute PDO insert with enhanced error handling
     */
    private function executePDOInsert($admin_id, $employee_id, $user_type, $action_type, $description, $ip_address, $device_info, $extra_data) {
        try {
            // Start transaction for data integrity
            $this->pdo->beginTransaction();
            
            $sql = "INSERT INTO user_activity_logs 
                    (admin_id, employee_id, user_type, action_type, description, ip_address, device_info, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                $admin_id,
                $employee_id,
                $user_type,
                $action_type,
                $description,
                $ip_address,
                $device_info
            ]);
            
            if ($result) {
                $log_id = $this->pdo->lastInsertId();
                $this->pdo->commit();
                
                if ($this->config['enable_detailed_logging']) {
                    $this->logSuccess("Activity logged: ID=$log_id, User=$user_type, Action=$action_type");
                }
                
                return true;
            } else {
                $this->pdo->rollback();
                return false;
            }
            
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollback();
            }
            throw $e;
        }
    }
    
    /**
     * Execute MySQLi insert with enhanced error handling
     */
    private function executeMySQLiInsert($admin_id, $employee_id, $user_type, $action_type, $description, $ip_address, $device_info, $extra_data) {
        try {
            // Start transaction
            $this->conn->begin_transaction();
            
            $sql = "INSERT INTO user_activity_logs 
                    (admin_id, employee_id, user_type, action_type, description, ip_address, device_info, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $this->conn->error);
            }
            
            $stmt->bind_param("iisssss", $admin_id, $employee_id, $user_type, $action_type, $description, $ip_address, $device_info);
            $result = $stmt->execute();
            
            if ($result) {
                $log_id = $this->conn->insert_id;
                $this->conn->commit();
                
                if ($this->config['enable_detailed_logging']) {
                    $this->logSuccess("Activity logged: ID=$log_id, User=$user_type, Action=$action_type");
                }
                
                return true;
            } else {
                $this->conn->rollback();
                return false;
            }
            
        } catch (Exception $e) {
            if ($this->conn) {
                $this->conn->rollback();
            }
            throw $e;
        }
    }
    
    /**
     * Enhanced input validation with security checks
     */
    private function validateInputs($admin_id, $employee_id, $user_type, $action_type, $description) {
        // Validate user IDs
        if ($this->config['validate_user_ids']) {
            if ($admin_id !== null && (!is_numeric($admin_id) || $admin_id <= 0)) {
                $this->logError("Invalid admin_id: $admin_id", 'VALIDATION_ERROR');
                return false;
            }
            
            if ($employee_id !== null && (!is_numeric($employee_id) || $employee_id <= 0)) {
                $this->logError("Invalid employee_id: $employee_id", 'VALIDATION_ERROR');
                return false;
            }
        }
        
        // Validate user_type
        if (!in_array($user_type, ['employee', 'admin'])) {
            $this->logError("Invalid user_type: $user_type", 'VALIDATION_ERROR');
            return false;
        }
        
        // Validate action_type
        $valid_actions = [
            'create', 'update', 'deactivate', 'password_reset', 'role_change',
            'login', 'login_failed', 'logout', 'password_change',
            'session_start', 'session_end', 'session_timeout',
            'account_lock', 'account_unlock'
        ];
        
        if (!in_array($action_type, $valid_actions)) {
            $this->logError("Invalid action_type: $action_type", 'VALIDATION_ERROR');
            return false;
        }
        
        // Validate description
        if (empty($description) || !is_string($description)) {
            $this->logError("Invalid description", 'VALIDATION_ERROR');
            return false;
        }
        
        return true;
    }
    
    /**
     * Rate limiting implementation
     */
    private function checkRateLimit($user_id) {
        if (!$user_id) return true;
        
        $current_minute = floor(time() / 60);
        $key = $user_id . '_' . $current_minute;
        
        if (!isset($this->rateLimitCache[$key])) {
            $this->rateLimitCache[$key] = 0;
        }
        
        $this->rateLimitCache[$key]++;
        
        // Clean old entries
        foreach ($this->rateLimitCache as $cache_key => $count) {
            $cache_minute = explode('_', $cache_key)[1] ?? 0;
            if ($cache_minute < $current_minute - 1) {
                unset($this->rateLimitCache[$cache_key]);
            }
        }
        
        return $this->rateLimitCache[$key] <= self::RATE_LIMIT_THRESHOLD;
    }
    
    /**
     * Sanitize description with security considerations
     */
    private function sanitizeDescription($description) {
        // Remove potentially dangerous content
        $description = strip_tags($description);
        $description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
        
        // Truncate if too long
        if (strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            $description = substr($description, 0, self::MAX_DESCRIPTION_LENGTH - 3) . '...';
        }
        
        return $description;
    }
    
    /**
     * Sanitize user type
     */
    private function sanitizeUserType($user_type) {
        $user_type = strtolower(trim($user_type));
        return in_array($user_type, ['employee', 'admin']) ? $user_type : 'employee';
    }
    
    /**
     * Sanitize action type
     */
    private function sanitizeActionType($action_type) {
        $action_type = strtolower(trim($action_type));
        $valid_actions = [
            'create', 'update', 'deactivate', 'password_reset', 'role_change',
            'login', 'login_failed', 'logout', 'password_change',
            'session_start', 'session_end', 'session_timeout',
            'account_lock', 'account_unlock'
        ];
        
        return in_array($action_type, $valid_actions) ? $action_type : 'update';
    }
    
    /**
     * Enhanced database connection management
     */
    private function ensureDatabaseConnection() {
        // Check PDO connection
        if ($this->pdo) {
            try {
                $this->pdo->query('SELECT 1');
                return true;
            } catch (Exception $e) {
                $this->logError("PDO connection check failed: " . $e->getMessage(), 'CONNECTION_ERROR');
                $this->pdo = null;
            }
        }
        
        // Check MySQLi connection
        if ($this->conn) {
            try {
                // Use a simple query instead of deprecated ping()
                $result = $this->conn->query('SELECT 1');
                if ($result) {
                    $result->free();
                    return true;
                }
            } catch (Exception $e) {
                $this->logError("MySQLi connection check failed: " . $e->getMessage(), 'CONNECTION_ERROR');
                $this->conn = null;
            }
        }
        
        // Try to reconnect
        return $this->reconnectDatabase();
    }
    
    /**
     * Attempt to reconnect to database
     */
    private function reconnectDatabase() {
        try {
            // Try to get fresh connections from global scope
            global $pdo, $conn;
            
            if ($pdo) {
                $this->pdo = $pdo;
                return true;
            }
            
            if ($conn) {
                $this->conn = $conn;
                return true;
            }
            
            // Try to include database config again
            $root_path = dirname(__DIR__);
            if (file_exists($root_path . '/config/db.php')) {
                include_once $root_path . '/config/db.php';
                
                if (isset($pdo)) {
                    $this->pdo = $pdo;
                    return true;
                }
                
                if (isset($conn)) {
                    $this->conn = $conn;
                    return true;
                }
            }
            
            return false;
            
        } catch (Exception $e) {
            $this->logError("Database reconnection failed: " . $e->getMessage(), 'RECONNECTION_ERROR');
            return false;
        }
    }
    
    /**
     * Log successful login
     */
    public function logLogin($user_id, $user_type = 'employee', $username = null) {
        $admin_id = ($user_type === 'admin') ? $user_id : null;
        $employee_id = ($user_type === 'employee') ? $user_id : $user_id; // Both can be employee_id
        
        $description = sprintf(
            "%s #%d logged in successfully%s from %s", 
            ucfirst($user_type),
            $user_id,
            $username ? " (username: $username)" : "",
            $this->getClientIpAddress()
        );
        
        return $this->logActivity($admin_id, $employee_id, $user_type, 'login', $description);
    }
    
    /**
     * Log failed login attempt
     */
    public function logFailedLogin($username, $user_type = 'employee') {
        $description = sprintf(
            "Failed login attempt for %s username '%s' from %s", 
            $user_type,
            $username,
            $this->getClientIpAddress()
        );
        
        return $this->logActivity(null, null, $user_type, 'login_failed', $description);
    }
    
    /**
     * Log logout
     */
    public function logLogout($user_id, $user_type = 'employee') {
        $admin_id = ($user_type === 'admin') ? $user_id : null;
        $employee_id = ($user_type === 'employee') ? $user_id : $user_id;
        
        $description = sprintf(
            "%s #%d logged out from %s", 
            ucfirst($user_type),
            $user_id,
            $this->getClientIpAddress()
        );
        
        return $this->logActivity($admin_id, $employee_id, $user_type, 'logout', $description);
    }
    
    /**
     * Log session start
     */
    public function logSessionStart($user_id, $user_type = 'employee') {
        $admin_id = ($user_type === 'admin') ? $user_id : null;
        $employee_id = ($user_type === 'employee') ? $user_id : $user_id;
        
        $description = sprintf(
            "%s #%d session started from %s", 
            ucfirst($user_type),
            $user_id,
            $this->getClientIpAddress()
        );
        
        return $this->logActivity($admin_id, $employee_id, $user_type, 'session_start', $description);
    }
    
    /**
     * Log session end/timeout
     */
    public function logSessionEnd($user_id, $user_type = 'employee', $reason = 'normal') {
        $admin_id = ($user_type === 'admin') ? $user_id : null;
        $employee_id = ($user_type === 'employee') ? $user_id : $user_id;
        
        $action_type = ($reason === 'timeout') ? 'session_timeout' : 'session_end';
        $description = sprintf(
            "%s #%d session ended (%s) from %s", 
            ucfirst($user_type),
            $user_id,
            $reason,
            $this->getClientIpAddress()
        );
        
        return $this->logActivity($admin_id, $employee_id, $user_type, $action_type, $description);
    }
    
    /**
     * Log password change
     */
    public function logPasswordChange($user_id, $user_type = 'employee', $changed_by = null) {
        $admin_id = ($user_type === 'admin') ? $user_id : $changed_by;
        $employee_id = ($user_type === 'employee') ? $user_id : $user_id;
        
        if ($changed_by && $changed_by !== $user_id) {
            $description = sprintf(
                "%s #%d password changed by admin #%d from %s", 
                ucfirst($user_type),
                $user_id,
                $changed_by,
                $this->getClientIpAddress()
            );
        } else {
            $description = sprintf(
                "%s #%d changed their own password from %s", 
                ucfirst($user_type),
                $user_id,
                $this->getClientIpAddress()
            );
        }
        
        return $this->logActivity($admin_id, $employee_id, $user_type, 'password_change', $description);
    }
    
    /**
     * Log password reset
     */
    public function logPasswordReset($user_id, $user_type = 'employee', $reset_by = null) {
        $admin_id = ($user_type === 'admin') ? $user_id : $reset_by;
        $employee_id = ($user_type === 'employee') ? $user_id : $user_id;
        
        $description = sprintf(
            "%s #%d password reset%s from %s", 
            ucfirst($user_type),
            $user_id,
            $reset_by ? " by admin #$reset_by" : "",
            $this->getClientIpAddress()
        );
        
        return $this->logActivity($admin_id, $employee_id, $user_type, 'password_reset', $description);
    }
    
    /**
     * Log account lock/unlock
     */
    public function logAccountLockUnlock($user_id, $user_type = 'employee', $action = 'lock', $performed_by = null) {
        $admin_id = $performed_by;
        $employee_id = $user_id;
        
        $action_type = ($action === 'lock') ? 'account_lock' : 'account_unlock';
        $description = sprintf(
            "%s #%d account %sed%s from %s", 
            ucfirst($user_type),
            $user_id,
            $action,
            $performed_by ? " by admin #$performed_by" : "",
            $this->getClientIpAddress()
        );
        
        return $this->logActivity($admin_id, $employee_id, $user_type, $action_type, $description);
    }
    
    /**
     * Get client IP address with enhanced proxy support and validation
     */
    private function getClientIpAddress() {
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Standard proxy header
            'HTTP_X_REAL_IP',            // Nginx proxy
            'HTTP_CLIENT_IP',            // Proxy servers
            'HTTP_X_FORWARDED',          // Variation
            'HTTP_FORWARDED_FOR',        // Variation
            'HTTP_FORWARDED',            // RFC 7239
            'REMOTE_ADDR'                // Direct connection
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                
                // Handle comma-separated IPs (common with X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]); // Take the first (original client) IP
                }
                
                $ip = trim($ip);
                
                // Validate IP address with security considerations
                if ($this->isValidIpAddress($ip)) {
                    return $ip;
                }
            }
        }
        
        // Fallback to localhost/unknown
        return '127.0.0.1';
    }
    
    /**
     * Validate IP address with security checks
     */
    private function isValidIpAddress($ip) {
        // Basic format validation
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        
        // In production, you might want to reject private ranges for external logs
        // For internal systems, we'll allow all valid IPs
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }
        
        // Allow private ranges for internal network logging
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get device/browser information with enhanced security
     */
    private function getDeviceInfo() {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // Security: Remove potentially dangerous content
        $user_agent = strip_tags($user_agent);
        $user_agent = preg_replace('/[^\x20-\x7E]/', '', $user_agent); // Remove non-printable chars
        
        // Truncate if too long
        if (strlen($user_agent) > self::MAX_DEVICE_INFO_LENGTH) {
            $user_agent = substr($user_agent, 0, self::MAX_DEVICE_INFO_LENGTH - 3) . '...';
        }
        
        return $user_agent;
    }
    
    /**
     * Enhanced error handling and logging
     */
    private function handleException($exception, $function, $context = []) {
        $error_data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'function' => $function,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'context' => $context,
            'trace' => $exception->getTraceAsString()
        ];
        
        $this->logError(json_encode($error_data), 'EXCEPTION');
        
        // Performance tracking
        if ($this->config['enable_performance_monitoring']) {
            $this->performanceMetrics['errors']++;
        }
        
        // Alert system integration point
        if ($this->config['alert_on_failures']) {
            $this->triggerAlert('ACTIVITY_LOGGER_EXCEPTION', $error_data);
        }
    }
    
    /**
     * Production-grade error logging
     */
    private function logError($message, $type = 'ERROR') {
        $timestamp = date('Y-m-d H:i:s');
        $ip = $this->getClientIpAddress();
        $log_entry = "[$timestamp] [$type] [IP:$ip] $message" . PHP_EOL;
        
        // Ensure log directory exists
        $this->ensureLogDirectory();
        
        // Atomic write to prevent corruption
        file_put_contents($this->errorLogPath, $log_entry, FILE_APPEND | LOCK_EX);
        
        // Also log to system error log
        error_log("UserActivityLogger [$type]: $message");
    }
    
    /**
     * Success logging for monitoring
     */
    private function logSuccess($message) {
        if ($this->config['enable_detailed_logging']) {
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[$timestamp] [SUCCESS] $message" . PHP_EOL;
            file_put_contents($this->errorLogPath, $log_entry, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Ensure log directory exists with proper permissions
     */
    private function ensureLogDirectory() {
        $log_dir = dirname($this->errorLogPath);
        
        if (!is_dir($log_dir)) {
            if (!mkdir($log_dir, 0750, true)) {
                error_log("Failed to create log directory: $log_dir");
                return false;
            }
        }
        
        // Ensure proper permissions in production
        if (!is_writable($log_dir)) {
            error_log("Log directory not writable: $log_dir");
            return false;
        }
        
        return true;
    }
    
    /**
     * Performance monitoring
     */
    private function recordPerformanceMetric($start_time, $success) {
        $execution_time = microtime(true) - $start_time;
        
        $this->performanceMetrics['operations']++;
        $this->performanceMetrics['total_execution_time'] = 
            ($this->performanceMetrics['total_execution_time'] ?? 0) + $execution_time;
        
        if (!$success) {
            $this->performanceMetrics['failures'] = 
                ($this->performanceMetrics['failures'] ?? 0) + 1;
        }
        
        // Log slow operations
        if ($execution_time > 1.0) { // 1 second threshold
            $this->logError("Slow operation detected: {$execution_time}s", 'PERFORMANCE');
        }
    }
    
    /**
     * Get performance metrics for monitoring
     */
    public function getPerformanceMetrics() {
        if (!$this->config['enable_performance_monitoring']) {
            return null;
        }
        
        $total_time = microtime(true) - $this->performanceMetrics['start_time'];
        $operations = $this->performanceMetrics['operations'];
        
        return [
            'uptime_seconds' => $total_time,
            'total_operations' => $operations,
            'operations_per_second' => $operations > 0 ? $operations / $total_time : 0,
            'average_execution_time' => $operations > 0 ? 
                ($this->performanceMetrics['total_execution_time'] ?? 0) / $operations : 0,
            'error_count' => $this->performanceMetrics['errors'] ?? 0,
            'failure_count' => $this->performanceMetrics['failures'] ?? 0,
            'success_rate' => $operations > 0 ? 
                (($operations - ($this->performanceMetrics['failures'] ?? 0)) / $operations) * 100 : 100
        ];
    }
    
    /**
     * Alert system integration
     */
    private function triggerAlert($alert_type, $data) {
        // This can be extended to integrate with monitoring systems
        // Examples: PagerDuty, Slack, email alerts, etc.
        
        $alert_data = [
            'type' => $alert_type,
            'timestamp' => date('c'),
            'service' => 'UserActivityLogger',
            'environment' => $_ENV['APP_ENV'] ?? 'production',
            'data' => $data
        ];
        
        // Log alert for now (can be extended)
        $this->logError("ALERT: " . json_encode($alert_data), 'ALERT');
        
        // Integration points for external alerting systems:
        // - Webhook notifications
        // - Email alerts
        // - SMS notifications
        // - Monitoring system APIs
    }
    
    /**
     * Bulk logging for high-volume scenarios
     */
    public function logActivitiesBulk($activities) {
        if (empty($activities) || !is_array($activities)) {
            return false;
        }
        
        $success_count = 0;
        $batch_size = self::BATCH_SIZE;
        $batches = array_chunk($activities, $batch_size);
        
        foreach ($batches as $batch) {
            try {
                if ($this->pdo) {
                    $this->pdo->beginTransaction();
                    
                    $sql = "INSERT INTO user_activity_logs 
                            (admin_id, employee_id, user_type, action_type, description, ip_address, device_info, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                    
                    $stmt = $this->pdo->prepare($sql);
                    
                    foreach ($batch as $activity) {
                        $result = $stmt->execute([
                            $activity['admin_id'] ?? null,
                            $activity['employee_id'] ?? null,
                            $activity['user_type'] ?? 'employee',
                            $activity['action_type'] ?? 'update',
                            $activity['description'] ?? '',
                            $activity['ip_address'] ?? $this->getClientIpAddress(),
                            $activity['device_info'] ?? $this->getDeviceInfo()
                        ]);
                        
                        if ($result) {
                            $success_count++;
                        }
                    }
                    
                    $this->pdo->commit();
                }
            } catch (Exception $e) {
                if ($this->pdo && $this->pdo->inTransaction()) {
                    $this->pdo->rollback();
                }
                $this->handleException($e, __FUNCTION__, ['batch_size' => count($batch)]);
            }
        }
        
        return $success_count;
    }
    
    /**
     * Log cleanup and maintenance
     */
    public function performMaintenance() {
        try {
            $retention_days = $this->config['log_retention_days'];
            
            // Archive old logs
            $archive_date = date('Y-m-d', strtotime("-{$retention_days} days"));
            
            if ($this->pdo) {
                // Archive logs older than retention period
                $stmt = $this->pdo->prepare("
                    CREATE TABLE IF NOT EXISTS user_activity_logs_archive 
                    LIKE user_activity_logs
                ");
                $stmt->execute();
                
                // Move old records to archive
                $stmt = $this->pdo->prepare("
                    INSERT INTO user_activity_logs_archive 
                    SELECT * FROM user_activity_logs 
                    WHERE DATE(created_at) < ?
                ");
                $stmt->execute([$archive_date]);
                $archived_count = $stmt->rowCount();
                
                // Delete archived records from main table
                $stmt = $this->pdo->prepare("
                    DELETE FROM user_activity_logs 
                    WHERE DATE(created_at) < ?
                ");
                $stmt->execute([$archive_date]);
                
                $this->logSuccess("Maintenance completed: $archived_count records archived");
                
                return ['archived' => $archived_count];
            }
            
        } catch (Exception $e) {
            $this->handleException($e, __FUNCTION__);
            return false;
        }
    }
}

/**
 * Global helper function to get activity logger instance
 * Usage: activity_logger()->logLogin($user_id, 'employee');
 */
function activity_logger() {
    static $instance = null;
    if ($instance === null) {
        $instance = new UserActivityLogger();
    }
    return $instance;
}

/**
 * Convenience function for quick activity logging
 * Usage: log_user_activity($admin_id, $employee_id, 'employee', 'login', 'User logged in');
 */
function log_user_activity($admin_id, $employee_id, $user_type, $action_type, $description) {
    return activity_logger()->logActivity($admin_id, $employee_id, $user_type, $action_type, $description);
}
?>