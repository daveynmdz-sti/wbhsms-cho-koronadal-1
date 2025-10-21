<?php
/**
 * Database Environment Diagnostic Tool
 * Use this to verify database connectivity and environment detection
 */

echo "<h1>Database Environment Diagnostic</h1>";

// Show server information
echo "<h2>1. Server Information</h2>";
echo "<table border='1' style='border-collapse: collapse;'>";
echo "<tr><th>Variable</th><th>Value</th></tr>";
echo "<tr><td>SERVER_NAME</td><td>" . ($_SERVER['SERVER_NAME'] ?? 'not set') . "</td></tr>";
echo "<tr><td>SERVER_ADDR</td><td>" . ($_SERVER['SERVER_ADDR'] ?? 'not set') . "</td></tr>";
echo "<tr><td>HTTP_HOST</td><td>" . ($_SERVER['HTTP_HOST'] ?? 'not set') . "</td></tr>";
echo "<tr><td>REQUEST_URI</td><td>" . ($_SERVER['REQUEST_URI'] ?? 'not set') . "</td></tr>";
echo "<tr><td>PHP_SELF</td><td>" . ($_SERVER['PHP_SELF'] ?? 'not set') . "</td></tr>";
echo "</table>";

// Environment detection
echo "<h2>2. Environment Detection</h2>";

$is_local = ($_SERVER['SERVER_NAME'] === 'localhost' || 
            $_SERVER['SERVER_NAME'] === '127.0.0.1' || 
            strpos($_SERVER['SERVER_NAME'], 'localhost') !== false ||
            $_SERVER['HTTP_HOST'] === 'localhost' ||
            (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false));

$is_production = (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] === '31.97.106.60') ||
                 (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], '31.97.106.60') !== false) ||
                 (getenv('ENVIRONMENT') === 'production');

echo "<p><strong>Is Local:</strong> " . ($is_local ? 'YES' : 'NO') . "</p>";
echo "<p><strong>Is Production:</strong> " . ($is_production ? 'YES' : 'NO') . "</p>";

if ($is_production) {
    echo "<p style='color: green;'><strong>✅ PRODUCTION ENVIRONMENT DETECTED</strong></p>";
    $expected_host = '31.97.106.60';
    $expected_port = '3307';
} elseif ($is_local) {
    echo "<p style='color: blue;'><strong>🏠 LOCAL ENVIRONMENT DETECTED</strong></p>";
    $expected_host = 'localhost';
    $expected_port = '3306';
} else {
    echo "<p style='color: orange;'><strong>❓ UNKNOWN ENVIRONMENT</strong></p>";
    $expected_host = 'unknown';
    $expected_port = 'unknown';
}

// Load database configuration
echo "<h2>3. Database Configuration Loading</h2>";

try {
    require_once 'config/db.php';
    
    echo "<p style='color: green;'>✅ Database configuration loaded successfully</p>";
    
    // Test database connection
    echo "<h2>4. Database Connection Test</h2>";
    
    if (isset($conn) && $conn instanceof mysqli) {
        if ($conn->connect_error) {
            echo "<p style='color: red;'>❌ MySQLi connection failed: " . $conn->connect_error . "</p>";
        } else {
            echo "<p style='color: green;'>✅ MySQLi connection successful</p>";
            
            // Show connection details
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>Setting</th><th>Expected</th><th>Actual</th><th>Status</th></tr>";
            echo "<tr><td>Host</td><td>{$expected_host}</td><td>{$host}</td><td>" . ($host === $expected_host ? '✅' : '❌') . "</td></tr>";
            echo "<tr><td>Port</td><td>{$expected_port}</td><td>{$port}</td><td>" . ($port == $expected_port ? '✅' : '❌') . "</td></tr>";
            echo "<tr><td>Database</td><td>wbhsms_database</td><td>{$db}</td><td>" . ($db === 'wbhsms_database' ? '✅' : '❌') . "</td></tr>";
            echo "<tr><td>Username</td><td>root</td><td>{$user}</td><td>" . ($user === 'root' ? '✅' : '❌') . "</td></tr>";
            echo "</table>";
            
            // Test actual database connectivity
            echo "<h2>5. Database Query Test</h2>";
            
            $test_query = "SELECT COUNT(*) as patient_count FROM patients";
            $result = $conn->query($test_query);
            
            if ($result) {
                $row = $result->fetch_assoc();
                echo "<p style='color: green;'>✅ Database query successful</p>";
                echo "<p><strong>Total patients in database:</strong> " . $row['patient_count'] . "</p>";
                
                // Test lab orders
                $lab_query = "SELECT COUNT(*) as lab_count FROM lab_orders";
                $lab_result = $conn->query($lab_query);
                if ($lab_result) {
                    $lab_row = $lab_result->fetch_assoc();
                    echo "<p><strong>Total lab orders in database:</strong> " . $lab_row['lab_count'] . "</p>";
                }
                
                // Show sample patients with lab orders
                echo "<h3>Sample Patients with Lab Orders:</h3>";
                $sample_query = "SELECT DISTINCT lo.patient_id, p.first_name, p.last_name, p.username, COUNT(lo.lab_order_id) as order_count 
                               FROM lab_orders lo 
                               LEFT JOIN patients p ON lo.patient_id = p.patient_id 
                               GROUP BY lo.patient_id 
                               ORDER BY order_count DESC 
                               LIMIT 10";
                $sample_result = $conn->query($sample_query);
                
                if ($sample_result && $sample_result->num_rows > 0) {
                    echo "<table border='1' style='border-collapse: collapse;'>";
                    echo "<tr><th>Patient ID</th><th>Name</th><th>Username</th><th>Lab Orders</th></tr>";
                    while ($patient = $sample_result->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td>" . $patient['patient_id'] . "</td>";
                        echo "<td>" . $patient['first_name'] . " " . $patient['last_name'] . "</td>";
                        echo "<td>" . $patient['username'] . "</td>";
                        echo "<td>" . $patient['order_count'] . "</td>";
                        echo "</tr>";
                    }
                    echo "</table>";
                } else {
                    echo "<p style='color: orange;'>No patients with lab orders found</p>";
                }
                
            } else {
                echo "<p style='color: red;'>❌ Database query failed: " . $conn->error . "</p>";
            }
        }
    } else {
        echo "<p style='color: red;'>❌ MySQLi connection object not created</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error loading database configuration: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<p><strong>Recommendation:</strong></p>";
if ($is_production) {
    echo "<p>✅ Production environment properly detected. Database should connect to 31.97.106.60:3307</p>";
} elseif ($is_local) {
    echo "<p>✅ Local environment detected. Database should connect to localhost:3306</p>";
} else {
    echo "<p>⚠️ Environment detection may need adjustment. Check server variables above.</p>";
}

echo "<p><a href='pages/patient/laboratory/lab_test.php'>→ Test Patient Lab Interface</a></p>";
?>