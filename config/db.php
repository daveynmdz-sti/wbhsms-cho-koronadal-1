<?php
/**
 * Database Configuration for WBHSMS CHO Koronadal
 * Provides both PDO and MySQLi connections for compatibility
 * Auto-detects local vs production environment
 */

// Enable error reporting based on environment
$debug = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

// Load environment-based PDO connection
require_once __DIR__ . '/env.php'; // This provides $pdo

// Auto-detect environment and set default database settings
$is_local = ($_SERVER['SERVER_NAME'] === 'localhost' || 
            $_SERVER['SERVER_NAME'] === '127.0.0.1' || 
            strpos($_SERVER['SERVER_NAME'], 'localhost') !== false ||
            $_SERVER['HTTP_HOST'] === 'localhost' ||
            (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false));

// Check if we're on production server (your specific IP)
$is_production = (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] === '31.97.106.60') ||
                 (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], '31.97.106.60') !== false) ||
                 (getenv('ENVIRONMENT') === 'production');

if ($is_production) {
    // Production environment - your specific server
    $host = getenv('DB_HOST') ?: '31.97.106.60';
    $db   = getenv('DB_DATABASE') ?: 'wbhsms_database';
    $user = getenv('DB_USERNAME') ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: '';
    $port = getenv('DB_PORT') ?: '3307';
} elseif ($is_local) {
    // Local XAMPP defaults
    $host = getenv('DB_HOST') ?: 'localhost';
    $db   = getenv('DB_DATABASE') ?: 'wbhsms_database';
    $user = getenv('DB_USERNAME') ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: '';
    $port = getenv('DB_PORT') ?: '3306';
} else {
    // Other environments - require environment variables
    $host = getenv('DB_HOST') ?: 'localhost';
    $db   = getenv('DB_DATABASE') ?: 'wbhsms_database';
    $user = getenv('DB_USERNAME') ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: '';
    $port = getenv('DB_PORT') ?: '3306';
}

// MySQLi connection (for legacy use cases)
try {
    // For localhost, force 127.0.0.1 to avoid socket connection issues
    $connection_host = ($host === 'localhost') ? '127.0.0.1' : $host;
    
    $conn = new mysqli($connection_host, $user, $pass, $db, $port);

    if ($conn->connect_error) {
        throw new Exception('MySQLi connection failed: ' . $conn->connect_error);
    }

    $conn->set_charset("utf8mb4");
    
    if ($debug) {
        $env_type = $is_production ? 'Production' : ($is_local ? 'Local' : 'Other');
        error_log("MySQLi Database connection successful to {$db} on {$connection_host}:{$port} (Environment: {$env_type})");
    }
    
} catch (Exception $e) {
    $error_msg = $e->getMessage();
    
    if ($debug) {
        $env_type = $is_production ? 'Production' : ($is_local ? 'Local' : 'Other');
        echo "Database Error: " . $error_msg . "<br>";
        echo "Connection details: Host={$host}, Database={$db}, User={$user}, Port={$port}<br>";
        echo "Environment: {$env_type}<br>";
        echo "Server Details: SERVER_ADDR=" . ($_SERVER['SERVER_ADDR'] ?? 'not set') . ", HTTP_HOST=" . ($_SERVER['HTTP_HOST'] ?? 'not set') . "<br>";
        
        if ($is_local && $host !== 'localhost' && $host !== '127.0.0.1') {
            echo "<strong>Tip:</strong> For local development, make sure your .env.local file has DB_HOST=localhost<br>";
        }
        if ($is_production) {
            echo "<strong>Production Mode:</strong> Using production database server 31.97.106.60:3307<br>";
        }
    } else {
        echo 'Database connection failed. Please check your configuration.';
    }
    die();
}
?>
