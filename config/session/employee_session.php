<?php
ob_start(); // Start output buffering to prevent header issues

/**
 * Get application base URL for consistent redirects
 * @return string
 */
if (!function_exists('getAppBase')) {
    function getAppBase() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        
        // Determine base path prefix up to (but not including) /pages/
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $pagesPos = strpos($requestUri, '/pages/');
        $basePath = ($pagesPos !== false) ? substr($requestUri, 0, $pagesPos) : '';
        
        return $protocol . $host . $basePath . '/';
    }
}

// Employee session configuration
session_name('EMPLOYEE_SESSID');

// Configure session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');

// Set session cookie parameters
$cookieParams = [
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => false, // Set to true in production with HTTPS
    'httponly' => true,
    'samesite' => 'Lax'
];

session_set_cookie_params($cookieParams);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if employee is logged in
 * @return bool
 */
function is_employee_logged_in() {
    return isset($_SESSION['employee_id']) && 
           isset($_SESSION['role']) && 
           isset($_SESSION['login_time']);
}

/**
 * Get employee session data
 * @param string $key
 * @return mixed
 */
function get_employee_session($key = null) {
    if ($key === null) {
        return $_SESSION;
    }
    return $_SESSION[$key] ?? null;
}

/**
 * Set employee session data
 * @param string $key
 * @param mixed $value
 */
function set_employee_session($key, $value) {
    $_SESSION[$key] = $value;
}

/**
 * Clear employee session
 */
function clear_employee_session() {
    session_unset();
    session_destroy();
    
    // Clear the session cookie
    if (isset($_COOKIE['EMPLOYEE_SESSID'])) {
        setcookie('EMPLOYEE_SESSID', '', time() - 3600, '/');
    }
}

/**
 * Redirect to login if not authenticated
 * @param string $login_url
 */
function require_employee_login($login_url = null) {
    if (!is_employee_logged_in()) {
        if ($login_url === null) {
            $login_url = getAppBase() . 'pages/management/auth/employee_login.php';
        }
        ob_end_clean();
        error_log('Redirecting to employee_login (via getAppBase) from ' . __FILE__ . ' URI=' . ($_SERVER['REQUEST_URI'] ?? ''));
        header('Location: ' . $login_url);
        exit();
    }
}

/**
 * Check if employee has required role
 * @param array $allowed_roles
 * @return bool
 */
function check_employee_role($allowed_roles) {
    if (!is_employee_logged_in()) {
        return false;
    }
    
    $user_role = get_employee_session('role');
    return in_array($user_role, $allowed_roles);
}

/**
 * Require specific role or redirect
 * @param array $allowed_roles
 * @param string $access_denied_url
 */
function require_employee_role($allowed_roles, $access_denied_url = '/wbhsms-cho-koronadal-1/pages/management/access_denied.php') {
    if (!check_employee_role($allowed_roles)) {
        header('Location: ' . $access_denied_url);
        exit();
    }
}

/**
 * Update last activity timestamp
 */
function update_employee_activity() {
    if (is_employee_logged_in()) {
        set_employee_session('last_activity', time());
    }
}

/**
 * Check session timeout
 * @param int $timeout_minutes
 * @return bool
 */
function check_employee_timeout($timeout_minutes = 30) {
    if (!is_employee_logged_in()) {
        return true; // Session expired
    }
    
    $last_activity = get_employee_session('last_activity');
    if ($last_activity && (time() - $last_activity) > ($timeout_minutes * 60)) {
        clear_employee_session();
        return true; // Session expired
    }
    
    update_employee_activity();
    return false; // Session active
}

// Auto-update activity on each request
if (is_employee_logged_in()) {
    update_employee_activity();
    
    // Check for timeout (default 30 minutes)
    if (check_employee_timeout()) {
        clear_employee_session();
    }
}

// Prevent caching of sensitive pages
if (is_employee_logged_in()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
}

ob_end_flush(); // End output buffering
?>