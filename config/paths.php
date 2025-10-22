<?php
/**
 * Global Path Configuration for WBHSMS
 * Provides dynamic path detection for both localhost and production environments
 */

// Prevent direct access
if (!defined('PHP_VERSION')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// Get project root path dynamically
function getProjectRoot() {
    // Start from current file location
    $current = __DIR__;
    
    // Look for index.php or composer.json to identify root
    while ($current !== dirname($current)) {
        if (file_exists($current . '/index.php') || file_exists($current . '/composer.json')) {
            return $current;
        }
        $current = dirname($current);
    }
    
    // Fallback to config directory parent
    return dirname(__DIR__);
}

// Detect if we're running on localhost XAMPP or production
function isLocalhost() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (
        strpos($host, 'localhost') !== false ||
        strpos($host, '127.0.0.1') !== false ||
        strpos($host, '::1') !== false
    );
}

// Get base URL for the application
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Extract project folder from script path
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    
    if (preg_match('#^(.*?)/pages/#', $script_name, $matches)) {
        $base_path = $matches[1];
    } elseif (preg_match('#^(.*?)/api/#', $script_name, $matches)) {
        $base_path = $matches[1];
    } elseif (preg_match('#^(.*?)/includes/#', $script_name, $matches)) {
        $base_path = $matches[1];
    } else {
        // Try to extract from REQUEST_URI
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $uri_parts = explode('/', trim($request_uri, '/'));
        
        if (count($uri_parts) > 0 && $uri_parts[0] && 
            !in_array($uri_parts[0], ['pages', 'api', 'includes', 'assets'])) {
            $base_path = '/' . $uri_parts[0];
        } else {
            $base_path = '';
        }
    }
    
    return $protocol . $host . $base_path;
}

// Get asset URL with proper path
function asset($path) {
    $base_url = getBaseUrl();
    $path = ltrim($path, '/');
    return $base_url . '/assets/' . $path;
}

// Get API URL with proper path
function api($path) {
    $base_url = getBaseUrl();
    $path = ltrim($path, '/');
    return $base_url . '/api/' . $path;
}

// Get JavaScript paths configuration
function getJavaScriptPaths() {
    $base_url = getBaseUrl();
    
    return "
    // WBHSMS Dynamic Paths Configuration
    const WBHSMS_PATHS = {
        base: '" . addslashes($base_url) . "',
        api: '" . addslashes($base_url . '/api') . "',
        assets: '" . addslashes($base_url . '/assets') . "',
        pages: '" . addslashes($base_url . '/pages') . "'
    };
    ";
}

// Define global constants if not already defined
if (!defined('WBHSMS_BASE_URL')) {
    define('WBHSMS_BASE_URL', getBaseUrl());
}

if (!defined('WBHSMS_ROOT_PATH')) {
    define('WBHSMS_ROOT_PATH', getProjectRoot());
}

// Initialize paths for legacy compatibility
$GLOBALS['base_url'] = getBaseUrl();
$GLOBALS['root_path'] = getProjectRoot();
?>