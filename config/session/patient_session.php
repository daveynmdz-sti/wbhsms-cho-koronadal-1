<?php
/**
 * Patient Session Configuration
 * 
 * This file configures the session for patient users.
 * It ensures patient sessions are separate from employee sessions.
 */

// Only proceed if session is not already active
if (session_status() === PHP_SESSION_NONE) {
    // Check if headers have already been sent
    if (!headers_sent($file, $line)) {
        // Headers not sent yet, we can configure session settings
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? '1' : '0');
        
        // Set unique session name for patients
        session_name('PATIENT_SESSID');
        
        // Set cookie path to restrict to patient areas
        session_set_cookie_params([
            'lifetime' => 0, // 0 = until browser is closed
            'path' => '/', // Root path
            'domain' => '', // Current domain
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        // Start the session normally
        session_start();
    } else {
        // Headers already sent - try to start session without configuration
        // This will use default session settings
        try {
            session_start();
        } catch (Exception $e) {
            // If session start fails, log the error but continue
            error_log("Patient session start failed: " . $e->getMessage());
        }
    }
}

/**
 * Check if a patient is logged in
 *
 * @return bool True if patient is logged in, false otherwise
 */
function is_patient_logged_in() {
    return !empty($_SESSION['patient_id']);
}

/**
 * Get patient session value
 *
 * @param string $key The session key to retrieve
 * @param mixed $default Default value if key doesn't exist
 * @return mixed The session value or default
 */
function get_patient_session($key, $default = null) {
    return $_SESSION[$key] ?? $default;
}

/**
 * Set patient session value
 *
 * @param string $key The session key to set
 * @param mixed $value The value to store
 */
function set_patient_session($key, $value) {
    $_SESSION[$key] = $value;
}

/**
 * Clear the patient session
 */
function clear_patient_session() {
    $_SESSION = [];
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
}

/**
 * Redirect to login if not authenticated
 * @param string $login_url
 */
function require_patient_login($login_url = null) {
    if (!is_patient_logged_in()) {
        redirect_to_patient_login($login_url);
    }
}

/**
 * Redirect to patient login page with proper path resolution
 * @param string $login_url Optional custom login URL
 * @param string $reason Optional reason for redirect (timeout, unauthorized, etc.)
 */
function redirect_to_patient_login($login_url = null, $reason = 'auth') {
    // Don't redirect if headers already sent or if this IS the login page
    if (headers_sent() || strpos($_SERVER['REQUEST_URI'] ?? '', 'patient_login.php') !== false) {
        return;
    }
    
    if ($login_url === null) {
        // Calculate proper path to login page
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $pathParts = explode('/', trim($scriptPath, '/'));
        
        // Remove the filename
        array_pop($pathParts);
        
        // Find the project index or determine depth to project root
        $projectIndex = -1;
        for ($i = 0; $i < count($pathParts); $i++) {
            if ($pathParts[$i] === 'wbhsms-cho-koronadal-1') {
                $projectIndex = $i;
                break;
            }
        }
        
        if ($projectIndex >= 0) {
            // XAMPP localhost: calculate relative path to project root
            $depth = count($pathParts) - $projectIndex - 1;
        } else {
            // Production: assume we're already at project root level
            $depth = count($pathParts);
        }
        
        $root_path = ($depth <= 0) ? './' : str_repeat('../', $depth);
        $login_url = $root_path . 'pages/patient/auth/patient_login.php';
        
        // Add reason parameter if specified
        if ($reason) {
            $separator = strpos($login_url, '?') === false ? '?' : '&';
            $login_url .= $separator . 'reason=' . urlencode($reason);
        }
    }
    
    // Clear any output buffer before redirect
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    error_log('[Patient Session] Redirecting to login (' . $reason . ') from: ' . ($_SERVER['REQUEST_URI'] ?? 'unknown') . ' to: ' . $login_url);
    header('Location: ' . $login_url);
    exit();
}

/**
 * Update last activity timestamp
 */
function update_patient_activity() {
    if (is_patient_logged_in()) {
        set_patient_session('last_activity', time());
    }
}

/**
 * Check session timeout
 * @param int $timeout_minutes
 * @return bool
 */
function check_patient_timeout($timeout_minutes = 30) {
    if (!is_patient_logged_in()) {
        return true; // Session expired
    }
    
    $last_activity = get_patient_session('last_activity');
    if ($last_activity && (time() - $last_activity) > ($timeout_minutes * 60)) {
        clear_patient_session();
        return true; // Session expired
    }
    
    update_patient_activity();
    return false; // Session active
}

// Auto-update activity on each request
if (is_patient_logged_in()) {
    update_patient_activity();
    
    // Check for timeout (default 30 minutes)
    if (check_patient_timeout()) {
        clear_patient_session();
        
        // Auto-redirect to login if session expired (not for AJAX or login page)
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                   strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $is_login_page = strpos($_SERVER['REQUEST_URI'] ?? '', 'patient_login.php') !== false;
        
        if (!$is_ajax && !$is_login_page && !headers_sent()) {
            redirect_to_patient_login(null, 'timeout');
        }
    }
}