<?php
/**
 * Authentication Helper Functions for WBHSMS
 * Provides production-ready redirect handling
 */

// Prevent direct access
if (!defined('PHP_VERSION')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * Check if running in production environment
 * @return bool
 */
function is_production() {
    return (
        getenv('APP_ENV') === 'production' ||
        getenv('ENVIRONMENT') === 'production' ||
        (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] === '31.97.106.60') ||
        (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], '31.97.106.60') !== false) ||
        !is_localhost()
    );
}

/**
 * Check if running on localhost
 * @return bool
 */
function is_localhost() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (
        strpos($host, 'localhost') !== false ||
        strpos($host, '127.0.0.1') !== false ||
        strpos($host, '::1') !== false
    );
}

/**
 * Get production-safe login URL
 * @param string $message Optional message parameter
 * @return string
 */
function get_employee_login_url($message = '') {
    $base_url = defined('WBHSMS_BASE_URL') ? WBHSMS_BASE_URL : '';
    $login_url = $base_url . '/pages/management/auth/employee_login.php';
    
    if ($message) {
        $login_url .= '?' . http_build_query(['message' => $message]);
    }
    
    return $login_url;
}

/**
 * Get production-safe dashboard URL for role
 * @param string $role Employee role
 * @return string
 */
function get_role_dashboard_url($role) {
    $base_url = defined('WBHSMS_BASE_URL') ? WBHSMS_BASE_URL : '';
    $role = strtolower($role);
    
    switch ($role) {
        case 'dho':
            return $base_url . '/pages/management/dho/dashboard.php';
        case 'bhw':
            return $base_url . '/pages/management/bhw/dashboard.php';
        case 'doctor':
            return $base_url . '/pages/management/doctor/dashboard.php';
        case 'nurse':
            return $base_url . '/pages/management/nurse/dashboard.php';
        case 'records_officer':
            return $base_url . '/pages/management/records_officer/dashboard.php';
        case 'laboratory_tech':
            return $base_url . '/pages/management/laboratory_tech/dashboard.php';
        case 'pharmacist':
            return $base_url . '/pages/management/pharmacist/dashboard.php';
        case 'cashier':
            return $base_url . '/pages/management/cashier/dashboard.php';
        case 'admin':
        default:
            return $base_url . '/pages/management/admin/dashboard.php';
    }
}

/**
 * Redirect to login with proper error handling
 * @param string $message Optional message
 * @param bool $session_timeout Whether this is a timeout redirect
 */
function redirect_to_employee_login($message = '', $session_timeout = false) {
    // Clear output buffer before redirect
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    $params = [];
    if ($message) {
        $params['message'] = $message;
    }
    if ($session_timeout) {
        $params['timeout'] = '1';
    }
    
    $login_url = get_employee_login_url();
    if ($params) {
        $login_url .= '?' . http_build_query($params);
    }
    
    // In production, use absolute URLs to ensure proper redirect
    if (is_production() && !parse_url($login_url, PHP_URL_HOST)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $login_url = $protocol . $host . $login_url;
    }
    
    // Log redirect for debugging in production
    error_log('[Auth] Redirecting to login from: ' . ($_SERVER['REQUEST_URI'] ?? 'unknown') . 
              ' - Reason: ' . ($session_timeout ? 'Session timeout' : ($message ?: 'Not authenticated')));
    
    header('Location: ' . $login_url);
    exit();
}

/**
 * Redirect to role-specific dashboard
 * @param string $role Employee role
 */
function redirect_to_role_dashboard($role) {
    // Clear output buffer before redirect
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    $dashboard_url = get_role_dashboard_url($role);
    
    // Log redirect for debugging
    error_log('[Auth] Redirecting role ' . $role . ' to dashboard: ' . $dashboard_url);
    
    header('Location: ' . $dashboard_url);
    exit();
}

/**
 * Check if user is authenticated and authorized for a page
 * @param array $allowed_roles Array of allowed roles
 * @param bool $redirect Whether to redirect on failure
 * @return bool
 */
function check_employee_auth($allowed_roles = [], $redirect = true) {
    // Check if user is logged in
    if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
        if ($redirect) {
            redirect_to_employee_login('Please log in to access this page');
        }
        return false;
    }
    
    // Check role authorization if specified
    if (!empty($allowed_roles)) {
        $user_role = strtolower($_SESSION['role']);
        $allowed_roles = array_map('strtolower', $allowed_roles);
        
        if (!in_array($user_role, $allowed_roles)) {
            if ($redirect) {
                redirect_to_role_dashboard($_SESSION['role']);
            }
            return false;
        }
    }
    
    return true;
}

/**
 * Require authentication with specific roles
 * @param array $allowed_roles Array of allowed roles
 */
function require_employee_auth($allowed_roles = []) {
    check_employee_auth($allowed_roles, true);
}

/**
 * Check for session restart/invalidation and handle accordingly
 * This should be called at the beginning of protected pages
 */
function handle_session_security() {
    // Check for session hijacking prevention
    $current_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $current_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    if (is_employee_logged_in()) {
        $stored_ip = get_employee_session('ip_address');
        $stored_user_agent = get_employee_session('user_agent');
        
        // If IP or user agent changed significantly, invalidate session
        if ($stored_ip && $stored_ip !== $current_ip) {
            error_log('[Security] IP address changed for employee session: ' . get_employee_session('employee_id'));
            clear_employee_session();
            redirect_to_employee_login('Session invalidated for security reasons');
        }
        
        if ($stored_user_agent && $stored_user_agent !== $current_user_agent) {
            error_log('[Security] User agent changed for employee session: ' . get_employee_session('employee_id'));
            clear_employee_session();
            redirect_to_employee_login('Session invalidated for security reasons');
        }
        
        // Update session security info
        set_employee_session('ip_address', $current_ip);
        set_employee_session('user_agent', $current_user_agent);
    }
}

/**
 * Set employee session variable
 * @param string $key Session key
 * @param mixed $value Session value
 */
function set_employee_session($key, $value) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['employee'][$key] = $value;
}

/**
 * Enhanced session timeout check with production-safe redirects
 */
function check_session_timeout() {
    if (!is_employee_logged_in()) {
        redirect_to_employee_login('Session expired. Please login again.');
        return;
    }
    
    $last_activity = get_employee_session('last_activity');
    $timeout = 3600; // 1 hour in seconds
    
    if ($last_activity && (time() - $last_activity > $timeout)) {
        clear_employee_session();
        redirect_to_employee_login('Session expired due to inactivity.');
    } else {
        // Update last activity
        set_employee_session('last_activity', time());
    }
}
?>