<?php
/**
 * Database Connection Test Script
 * Run this to test your database configuration
 */

echo "<h3>Database Connection Test</h3>";
echo "<strong>Environment Detection:</strong><br>";

// Environment detection
$is_local = ($_SERVER['SERVER_NAME'] === 'localhost' || $_SERVER['SERVER_NAME'] === '127.0.0.1' || strpos($_SERVER['SERVER_NAME'], 'localhost') !== false);
echo "Detected environment: " . ($is_local ? 'Local' : 'Production') . "<br>";
echo "Server name: " . $_SERVER['SERVER_NAME'] . "<br><br>";

// Load environment variables
$root_dir = __DIR__;

function loadEnvFile($envPath) {
    if (!file_exists($envPath)) return false;
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (!strpos($line, '=')) continue;
        list($name, $value) = array_map('trim', explode('=', $line, 2));
        putenv("$name=$value");
    }
    return true;
}

echo "<strong>Environment Files:</strong><br>";
if (file_exists($root_dir . '/.env')) {
    echo "✓ .env file found<br>";
    loadEnvFile($root_dir . '/.env');
} else {
    echo "✗ .env file not found<br>";
}

if (file_exists($root_dir . '/.env.local')) {
    echo "✓ .env.local file found<br>";
    loadEnvFile($root_dir . '/.env.local');
} else {
    echo "✗ .env.local file not found<br>";
}

echo "<br><strong>Database Configuration:</strong><br>";

// Get database settings
if ($is_local) {
    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '3306';
    $db   = getenv('DB_DATABASE') ?: 'wbhsms_database';
    $user = getenv('DB_USERNAME') ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: '';
} else {
    $host = getenv('DB_HOST');
    $port = getenv('DB_PORT') ?: '3306';
    $db   = getenv('DB_DATABASE');
    $user = getenv('DB_USERNAME');
    $pass = getenv('DB_PASSWORD');
}

echo "Host: " . ($host ?: 'NOT SET') . "<br>";
echo "Port: " . ($port ?: 'NOT SET') . "<br>";
echo "Database: " . ($db ?: 'NOT SET') . "<br>";
echo "Username: " . ($user ?: 'NOT SET') . "<br>";
echo "Password: " . (empty($pass) ? 'EMPTY' : 'SET (length: ' . strlen($pass) . ')') . "<br>";

$connection_host = ($host === 'localhost') ? '127.0.0.1' : $host;
echo "Connection Host: " . $connection_host . "<br><br>";

// Test MySQLi connection
echo "<strong>MySQLi Connection Test:</strong><br>";
try {
    $conn = new mysqli($connection_host, $user, $pass, $db, $port);
    
    if ($conn->connect_error) {
        throw new Exception('MySQLi connection failed: ' . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    echo "✓ MySQLi connection successful!<br>";
    
    // Test a simple query
    $result = $conn->query("SELECT VERSION() as mysql_version");
    if ($result) {
        $version = $result->fetch_assoc();
        echo "✓ MySQL version: " . $version['mysql_version'] . "<br>";
    }
    
    $conn->close();
} catch (Exception $e) {
    echo "✗ MySQLi connection failed: " . $e->getMessage() . "<br>";
}

echo "<br><strong>PDO Connection Test:</strong><br>";
try {
    $dsn = "mysql:host=$connection_host;port=$port;dbname=$db;charset=utf8mb4";
    echo "DSN: " . $dsn . "<br>";
    
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    
    echo "✓ PDO connection successful!<br>";
    
    // Test a simple query
    $stmt = $pdo->query("SELECT VERSION() as mysql_version");
    $version = $stmt->fetch();
    echo "✓ MySQL version: " . $version['mysql_version'] . "<br>";
    
} catch (PDOException $e) {
    echo "✗ PDO connection failed: " . $e->getMessage() . "<br>";
    echo "Error code: " . $e->getCode() . "<br>";
}

echo "<br><strong>Recommendations:</strong><br>";
if ($is_local) {
    echo "For local development:<br>";
    echo "1. Make sure XAMPP MySQL is running<br>";
    echo "2. Create .env.local file with local database settings<br>";
    echo "3. Use localhost or 127.0.0.1 as DB_HOST<br>";
    echo "4. Default XAMPP user is 'root' with empty password<br>";
} else {
    echo "For production:<br>";
    echo "1. Ensure all environment variables are set<br>";
    echo "2. Check firewall and network connectivity<br>";
    echo "3. Verify database server is accessible<br>";
}
?>