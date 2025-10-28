<?php
// Get test type from URL parameter, default to 'local'
$test = isset($_GET['test']) && in_array($_GET['test'], ['local', 'remote']) ? $_GET['test'] : 'local';

// Include the root config files with correct paths for both local and production
$root_path = __DIR__;

// Normalize path separators for cross-platform compatibility
$config_path = $root_path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'env.php';
require_once $config_path;

// Helper function to generate proper URLs for both local and production
function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['SCRIPT_NAME']);
    return $protocol . '://' . $host . $path;
}

$base_url = getBaseUrl();

// Environment detection for better compatibility
$is_windows = (DIRECTORY_SEPARATOR === '\\');
$is_local = (
    $_SERVER['SERVER_NAME'] === 'localhost' || 
    $_SERVER['SERVER_NAME'] === '127.0.0.1' || 
    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
    $is_windows // Assume Windows means local XAMPP
);

$connection_status = '';
$connection_type = '';
$connection_details = array();

if ($test === 'local') {
    $connection_type = 'LOCAL (XAMPP)';
    // Force local environment variables for testing
    putenv('DB_HOST=localhost');
    putenv('DB_PORT=3306');
    putenv('DB_DATABASE=wbhsms_database');
    putenv('DB_USERNAME=root');
    putenv('DB_PASSWORD=');
    
    // Add environment info for debugging
    $connection_details['Environment'] = $is_windows ? 'Windows XAMPP' : 'Local Development';
    $connection_details['OS'] = $is_windows ? 'Windows' : 'Unix/Linux';
} else {
    $connection_type = 'REMOTE (PRODUCTION)';
    // Load production settings from .env with cross-platform path
    $env_file = $root_path . DIRECTORY_SEPARATOR . '.env';
    if (file_exists($env_file)) {
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (!strpos($line, '=')) continue;
            list($name, $value) = array_map('trim', explode('=', $line, 2));
            putenv("$name=$value");
        }
        
        // Add environment info
        $connection_details['Environment'] = 'Production Server';
        $connection_details['OS'] = $is_windows ? 'Windows' : 'Unix/Linux';
        $connection_details['Config Source'] = '.env file';
    } else {
        $connection_status = 'error';
        $error_message = '.env file not found for production testing at: ' . $env_file;
    }
}

if (empty($connection_status)) {
    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '3306';
    $db   = getenv('DB_DATABASE') ?: 'wbhsms_database';
    $user = getenv('DB_USERNAME') ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: '';
    $charset = 'utf8mb4';

    $connection_details = array(
        'Host' => $host,
        'Port' => $port,
        'Database' => $db,
        'Username' => $user,
        'Password' => $pass ? str_repeat('*', strlen($pass)) : '(empty)',
        'Charset' => $charset,
        'DSN' => "mysql:host=$host;port=$port;dbname=$db;charset=$charset"
    );

    // Add environment-specific details if they were set earlier
    if (isset($connection_details['Environment'])) {
        $temp_details = $connection_details;
        $connection_details = array();
        $connection_details['Environment'] = $temp_details['Environment'];
        $connection_details['OS'] = $temp_details['OS'];
        if (isset($temp_details['Config Source'])) {
            $connection_details['Config Source'] = $temp_details['Config Source'];
        }
        $connection_details = array_merge($connection_details, $temp_details);
    }

    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

    try {
        // For local environments, try 127.0.0.1 instead of localhost to avoid socket issues
        $connection_host = ($host === 'localhost' && $is_local) ? '127.0.0.1' : $host;
        $dsn = "mysql:host=$connection_host;port=$port;dbname=$db;charset=$charset";
        
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10
        ]);
        
        $connection_status = 'success';
        
        // Update connection details with actual host used
        $connection_details['Actual Host'] = $connection_host;
        
        // Get additional database info
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        $connection_details['MySQL Version'] = $version;
        
        // Test a simple query
        $tables = $pdo->query("SELECT COUNT(*) as table_count FROM information_schema.tables WHERE table_schema = '$db'")->fetchColumn();
        $connection_details['Tables Found'] = $tables;
        
    } catch (PDOException $e) {
        $connection_status = 'error';
        $error_message = 'Database connection failed: ' . $e->getMessage();
        
        // Add debugging information for local development
        if ($is_local || $test === 'local') {
            $error_message .= "\n\nDebugging Info:";
            $error_message .= "\n- DSN: " . $dsn;
            $error_message .= "\n- User: " . $user;
            $error_message .= "\n- Error Code: " . $e->getCode();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Connection Test - CHO Koronadal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.1);
            padding: 40px;
            max-width: 600px;
            width: 100%;
            text-align: center;
        }

        .header {
            margin-bottom: 30px;
        }

        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: #f8f9fa;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #2563eb;
        }

        h1 {
            color: #1f2937;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #6b7280;
            font-size: 1rem;
            margin-bottom: 30px;
        }

        .status-card {
            background: #f9fafb;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            border: 2px solid #e5e7eb;
        }

        .status-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }

        .status-success {
            border-color: #10b981;
            background: #ecfdf5;
        }

        .status-success .status-icon {
            color: #10b981;
        }

        .status-error {
            border-color: #ef4444;
            background: #fef2f2;
        }

        .status-error .status-icon {
            color: #ef4444;
        }

        .status-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .status-success .status-title {
            color: #065f46;
        }

        .status-error .status-title {
            color: #991b1b;
        }

        .status-message {
            font-size: 1rem;
            line-height: 1.5;
            white-space: pre-line; /* Allow line breaks in error messages */
        }

        .status-success .status-message {
            color: #047857;
        }

        .status-error .status-message {
            color: #dc2626;
        }

        .connection-details {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-top: 20px;
            text-align: left;
        }

        .details-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 15px;
            text-align: center;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 500;
            color: #6b7280;
        }

        .detail-value {
            font-family: 'Courier New', monospace;
            background: #f3f4f6;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.9rem;
            color: #374151;
        }

        .actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #2563eb;
            color: white;
            border: 2px solid #2563eb;
        }

        .btn-primary:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: white;
            color: #6b7280;
            border: 2px solid #e5e7eb;
        }

        .btn-secondary:hover {
            background: #f9fafb;
            border-color: #d1d5db;
            transform: translateY(-2px);
        }

        .btn-refresh {
            background: #059669;
            color: white;
            border: 2px solid #059669;
        }

        .btn-refresh:hover {
            background: #047857;
            border-color: #047857;
            transform: translateY(-2px);
        }

        .connection-type {
            display: inline-block;
            background: #3b82f6;
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 30px 20px;
                margin: 10px;
            }

            h1 {
                font-size: 1.8rem;
            }

            .actions {
                flex-direction: column;
                align-items: center;
            }

            .btn {
                width: 100%;
                justify-content: center;
                max-width: 250px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">
                <i class="fas fa-database"></i>
            </div>
            <h1>Database Connection Test</h1>
            <p class="subtitle">CHO Koronadal System</p>
        </div>

        <div class="connection-type">
            Testing <?php echo $connection_type; ?> Connection
        </div>

        <!-- Test Switch Controls -->
        <div style="background: #f8f9fa; padding: 20px; border-radius: 12px; margin-bottom: 20px;">
            <div style="font-weight: 600; margin-bottom: 15px; color: #374151;">Switch Test Environment:</div>
            <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                <a href="?test=local" class="btn <?php echo $test === 'local' ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size: 0.9rem; padding: 10px 20px;">
                    <i class="fas fa-home"></i> Local (XAMPP)
                </a>
                <a href="?test=remote" class="btn <?php echo $test === 'remote' ? 'btn-primary' : 'btn-secondary'; ?>" style="font-size: 0.9rem; padding: 10px 20px;">
                    <i class="fas fa-cloud"></i> Remote (Production)
                </a>
            </div>
        </div>

        <div class="status-card <?php echo $connection_status === 'success' ? 'status-success' : 'status-error'; ?>">
            <div class="status-icon">
                <?php if ($connection_status === 'success'): ?>
                    <i class="fas fa-check-circle"></i>
                <?php else: ?>
                    <i class="fas fa-times-circle"></i>
                <?php endif; ?>
            </div>
            
            <div class="status-title">
                <?php if ($connection_status === 'success'): ?>
                    Connection Successful!
                <?php else: ?>
                    Connection Failed
                <?php endif; ?>
            </div>
            
            <div class="status-message">
                <?php if ($connection_status === 'success'): ?>
                    Database connection established successfully. All systems are operational.
                <?php else: ?>
                    <?php echo isset($error_message) ? htmlspecialchars($error_message) : 'Unknown error occurred'; ?>
                <?php endif; ?>
            </div>

            <?php if ($connection_status === 'success' && !empty($connection_details)): ?>
                <div class="connection-details">
                    <div class="details-title">Connection Details</div>
                    <?php foreach ($connection_details as $label => $value): ?>
                        <div class="detail-row">
                            <span class="detail-label"><?php echo htmlspecialchars($label); ?>:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($value); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="actions">
            <a href="<?php echo $base_url; ?>/index.php" class="btn btn-primary">
                <i class="fas fa-home"></i> Back to Home
            </a>
            <a href="?<?php echo http_build_query(['test' => $test]); ?>" class="btn btn-refresh">
                <i class="fas fa-sync-alt"></i> Refresh Test
            </a>
            <a href="<?php echo $base_url; ?>/pages/patient/auth/patient_login.php" class="btn btn-secondary">
                <i class="fas fa-user"></i> Patient Portal
            </a>
        </div>
    </div>
</body>
</html>