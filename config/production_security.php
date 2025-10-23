<?php
/**
 * Production Security Configuration
 * Include this file at the top of sensitive pages for enhanced security
 */

// Prevent direct access to this configuration file
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access denied');
}

// Debug mode to disable CSP for testing (remove in production)
$disable_csp = isset($_GET['debug_csp']) && $_GET['debug_csp'] === 'off';

// Set secure session parameters for production
if (!headers_sent()) {
    // Security headers
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    
    // Content Security Policy (enhanced for healthcare system) - Allow external CDNs and resources
    if (!$disable_csp) {
        $csp = "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline' 'unsafe-eval'; " .
               "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; " .
               "img-src 'self' data: https://ik.imagekit.io blob:; " .
               "font-src 'self' https://cdnjs.cloudflare.com data:; " .
               "connect-src 'self'";
        header("Content-Security-Policy: " . $csp);
    }
    
    // Session security - only set if no session is active
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_samesite', 'Strict');
    }
}

// Set production-safe error reporting
$is_production = !in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1']);

// Check environment variables for production detection
$env_is_production = (getenv('ENVIRONMENT') === 'production') || 
                    (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] === '31.97.106.60') ||
                    (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], '31.97.106.60') !== false);

if ($is_production || $env_is_production) {
    // Production: Log errors but don't display them, suppress deprecation warnings
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_NOTICE);
} else {
    // Development: Show errors for debugging
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);
}

// Input sanitization helpers
if (!function_exists('sanitize_string')) {
    /**
     * Sanitize string input for database storage
     * @param mixed $input The input to sanitize
     * @param int $max_length Maximum allowed length
     * @return string Sanitized string
     */
    function sanitize_string($input, $max_length = 1000) {
        if (is_null($input)) return '';
        // Replace deprecated FILTER_SANITIZE_STRING with htmlspecialchars
        $sanitized = htmlspecialchars(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return substr($sanitized, 0, $max_length);
    }
}

if (!function_exists('validate_int')) {
    /**
     * Validate and sanitize integer input
     * @param mixed $input The input to validate
     * @param int $min Minimum value
     * @param int $max Maximum value
     * @return int|null Validated integer or null if invalid
     */
    function validate_int($input, $min = PHP_INT_MIN, $max = PHP_INT_MAX) {
        return filter_var($input, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max]
        ]);
    }
}

if (!function_exists('validate_float')) {
    /**
     * Validate and sanitize float input
     * @param mixed $input The input to validate
     * @param float $min Minimum value
     * @param float $max Maximum value
     * @return float|null Validated float or null if invalid
     */
    function validate_float($input, $min = -PHP_FLOAT_MAX, $max = PHP_FLOAT_MAX) {
        $result = filter_var($input, FILTER_VALIDATE_FLOAT);
        if ($result === false || $result < $min || $result > $max) {
            return null;
        }
        return $result;
    }
}

if (!function_exists('sanitize_input')) {
    /**
     * Modern replacement for deprecated FILTER_SANITIZE_STRING
     * @param mixed $input The input to sanitize
     * @param int $max_length Maximum allowed length
     * @return string Sanitized string
     */
    function sanitize_input($input, $max_length = 1000) {
        if (is_null($input)) return '';
        // Trim whitespace and convert to string
        $input = trim((string) $input);
        // Remove or encode potentially harmful characters
        $sanitized = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return substr($sanitized, 0, $max_length);
    }
}

if (!function_exists('validate_date')) {
    /**
     * Validate date format
     * @param string $date Date string to validate
     * @param string $format Expected date format
     * @return bool True if valid, false otherwise
     */
    function validate_date($date, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}

if (!function_exists('escape_output')) {
    /**
     * Escape output for HTML display to prevent XSS
     * @param mixed $value Value to escape
     * @return string Escaped string
     */
    function escape_output($value) {
        if (is_null($value)) return '';
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('log_security_event')) {
    /**
     * Log security-related events
     * @param string $event Event description
     * @param array $context Additional context
     */
    function log_security_event($event, $context = []) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'session_id' => session_id(),
            'employee_id' => $_SESSION['employee_id'] ?? 'not_logged_in',
            'context' => $context
        ];
        
        error_log('SECURITY_EVENT: ' . json_encode($logEntry));
    }
}

// Rate limiting helpers
if (!function_exists('check_rate_limit')) {
    /**
     * Simple rate limiting check
     * @param string $action Action being performed
     * @param int $max_attempts Maximum attempts allowed
     * @param int $time_window Time window in seconds
     * @return bool True if within limits, false if exceeded
     */
    function check_rate_limit($action, $max_attempts = 10, $time_window = 300) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = "rate_limit_{$action}_{$ip}";
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 0, 'reset_time' => time() + $time_window];
        }
        
        $rate_data = $_SESSION[$key];
        
        // Reset if time window has passed
        if (time() > $rate_data['reset_time']) {
            $rate_data = ['count' => 0, 'reset_time' => time() + $time_window];
        }
        
        $rate_data['count']++;
        $_SESSION[$key] = $rate_data;
        
        if ($rate_data['count'] > $max_attempts) {
            log_security_event("Rate limit exceeded for action: $action", [
                'attempts' => $rate_data['count'],
                'ip' => $ip
            ]);
            return false;
        }
        
        return true;
    }
}

// Database connection validation
if (!function_exists('validate_db_connection')) {
    /**
     * Validate database connection is secure and working
     * @param mysqli $conn Database connection
     * @return bool True if valid, false otherwise
     */
    function validate_db_connection($conn) {
        if (!$conn || $conn->connect_error) {
            log_security_event("Database connection failed", [
                'error' => $conn->connect_error ?? 'Connection object is null'
            ]);
            return false;
        }
        return true;
    }
}