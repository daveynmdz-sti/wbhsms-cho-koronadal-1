<?php
/**
 * Quick Database Setup Tool
 * Creates the database and tests connection
 */

// Database connection parameters
$host = 'localhost';
$port = 3306;
$dbname = 'wbhsms_database';
$username = 'root';
$password = '';

echo "<h2>🔧 Database Setup & Connection Test</h2>";
echo "<hr>";

// Step 1: Test basic MySQL connection (without database)
echo "<h3>Step 1: Testing MySQL Connection</h3>";
try {
    $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10
    ]);
    echo "✅ MySQL connection successful!<br>";
    echo "📊 MySQL Version: " . $pdo->query('SELECT VERSION()')->fetchColumn() . "<br><br>";
} catch (PDOException $e) {
    echo "❌ MySQL connection failed: " . $e->getMessage() . "<br>";
    echo "💡 Make sure XAMPP MySQL is running<br>";
    echo "💡 Check XAMPP Control Panel - MySQL should be green/started<br><br>";
    exit;
}

// Step 2: Check if database exists
echo "<h3>Step 2: Checking Database</h3>";
try {
    $stmt = $pdo->query("SHOW DATABASES LIKE '$dbname'");
    if ($stmt->rowCount() > 0) {
        echo "✅ Database '$dbname' already exists<br>";
    } else {
        echo "⚠️ Database '$dbname' does not exist<br>";
        echo "🔨 Creating database...<br>";
        
        $pdo->exec("CREATE DATABASE `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "✅ Database '$dbname' created successfully!<br>";
    }
} catch (PDOException $e) {
    echo "❌ Database creation failed: " . $e->getMessage() . "<br>";
    exit;
}

// Step 3: Test connection to the specific database
echo "<br><h3>Step 3: Testing Database Connection</h3>";
try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10
    ]);
    
    echo "✅ Database connection successful!<br>";
    echo "📋 Connection details:<br>";
    echo "&nbsp;&nbsp;• Host: $host:$port<br>";
    echo "&nbsp;&nbsp;• Database: $dbname<br>";
    echo "&nbsp;&nbsp;• Username: $username<br>";
    echo "&nbsp;&nbsp;• Password: " . (empty($password) ? '[empty]' : '[set]') . "<br>";
    
    // Check tables
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "&nbsp;&nbsp;• Tables: " . count($tables) . "<br>";
    
} catch (PDOException $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "<br>";
    exit;
}

echo "<br><h3>🎉 Setup Complete!</h3>";
echo "<p>Your database is ready. You can now use the import tool:</p>";
echo "<a href='database_importer.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🗄️ Go to Database Importer</a>";
echo "<br><br>";
echo "<a href='testdb.php' style='background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>🧪 Test Database Connection</a>";
?>