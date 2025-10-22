<?php
/**
 * Global Path Configuration
 * Purpose: Provides production-safe URL and path generation for the WBHSMS system
 * Used by: Sidebar components, billing system, and other modules requiring dynamic paths
 */

/**
 * Get the base URL for the application
 * Works in both local development (XAMPP) and production environments
 * 
 * @return string The base URL (e.g., "http://localhost/wbhsms-cho-koronadal-1" or "https://domain.com")
 */
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $request_uri = $_SERVER['REQUEST_URI'];
    
    // Extract base path from REQUEST_URI for production compatibility
    $uri_parts = explode('/', trim($request_uri, '/'));
    $base_path = '';
    
    // Check if we're in a project subfolder (local development like XAMPP)
    if (count($uri_parts) > 0 && $uri_parts[0] && $uri_parts[0] !== 'pages') {
        $base_path = '/' . $uri_parts[0];
    }
    
    return $protocol . $host . $base_path;
}

/**
 * Get the base path only (without protocol and host)
 * Useful for relative URL construction
 * 
 * @return string The base path (e.g., "/wbhsms-cho-koronadal-1" or "")
 */
function getBasePath() {
    $request_uri = $_SERVER['REQUEST_URI'];
    
    // Extract base path from REQUEST_URI for production compatibility
    $uri_parts = explode('/', trim($request_uri, '/'));
    $base_path = '';
    
    // Check if we're in a project subfolder (local development like XAMPP)
    if (count($uri_parts) > 0 && $uri_parts[0] && $uri_parts[0] !== 'pages') {
        $base_path = '/' . $uri_parts[0];
    }
    
    return $base_path;
}

/**
 * Get API base URL
 * 
 * @return string The API base URL
 */
function getApiBaseUrl() {
    return getBaseUrl() . '/api';
}

/**
 * Get assets base URL
 * 
 * @return string The assets base URL
 */
function getAssetsBaseUrl() {
    return getBaseUrl() . '/assets';
}

/**
 * Generate a safe URL for the application
 * 
 * @param string $path The relative path to append
 * @return string The complete URL
 */
function url($path = '') {
    $base = getBaseUrl();
    $path = ltrim($path, '/');
    return $base . ($path ? '/' . $path : '');
}

/**
 * Generate an asset URL
 * 
 * @param string $path The asset path
 * @return string The complete asset URL
 */
function asset($path) {
    return getAssetsBaseUrl() . '/' . ltrim($path, '/');
}

/**
 * Generate an API URL
 * 
 * @param string $endpoint The API endpoint
 * @return string The complete API URL
 */
function api($endpoint) {
    return getApiBaseUrl() . '/' . ltrim($endpoint, '/');
}