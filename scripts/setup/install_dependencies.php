<?php
/**
 * Dependencies Installation Helper
 * Helps install required PHP dependencies for WBHSMS
 */

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WBHSMS - Install Dependencies</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
        .container { max-width: 800px; margin: 0 auto; }
        .header { background: #0077b6; color: white; padding: 20px; border-radius: 10px; margin-bottom: 30px; }
        .section { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #0077b6; }
        .success { border-left-color: #28a745; }
        .warning { border-left-color: #ffc107; }
        .error { border-left-color: #dc3545; }
        .code { background: #e9ecef; padding: 10px; border-radius: 4px; font-family: monospace; }
        .step { counter-increment: step; margin-bottom: 15px; }
        .step::before { content: "Step " counter(step) ": "; font-weight: bold; color: #0077b6; }
        .steps { counter-reset: step; }
        ul { margin: 10px 0; padding-left: 30px; }
        li { margin-bottom: 8px; }
        .status-icon { display: inline-block; width: 20px; height: 20px; border-radius: 50%; text-align: center; color: white; font-weight: bold; margin-right: 10px; }
        .status-icon.success { background: #28a745; }
        .status-icon.error { background: #dc3545; }
        .status-icon.warning { background: #ffc107; color: #000; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i>🏥</i> WBHSMS Dependencies Installer</h1>
            <p>This tool helps you install required PHP dependencies for the Web-Based Healthcare Services Management System</p>
        </div>

        <?php
        $root_path = dirname(dirname(__DIR__));
        $composer_json_path = $root_path . '/composer.json';
        $vendor_path = $root_path . '/vendor';
        $autoload_path = $vendor_path . '/autoload.php';
        
        // Check system requirements
        echo '<div class="section">';
        echo '<h2>📋 System Requirements Check</h2>';
        
        // PHP Version
        $php_version = PHP_VERSION;
        $php_ok = version_compare($php_version, '7.4.0', '>=');
        echo '<div>' . ($php_ok ? '<span class="status-icon success">✓</span>' : '<span class="status-icon error">✗</span>');
        echo "PHP Version: {$php_version} " . ($php_ok ? '(OK)' : '(Requires PHP 7.4+)') . '</div>';
        
        // Composer.json exists
        $composer_exists = file_exists($composer_json_path);
        echo '<div>' . ($composer_exists ? '<span class="status-icon success">✓</span>' : '<span class="status-icon error">✗</span>');
        echo 'Composer.json file: ' . ($composer_exists ? 'Found' : 'Missing') . '</div>';
        
        // Vendor directory
        $vendor_exists = is_dir($vendor_path);
        echo '<div>' . ($vendor_exists ? '<span class="status-icon success">✓</span>' : '<span class="status-icon error">✗</span>');
        echo 'Vendor directory: ' . ($vendor_exists ? 'Found' : 'Missing') . '</div>';
        
        // Autoload file
        $autoload_exists = file_exists($autoload_path);
        echo '<div>' . ($autoload_exists ? '<span class="status-icon success">✓</span>' : '<span class="status-icon error">✗</span>');
        echo 'Autoload file: ' . ($autoload_exists ? 'Found' : 'Missing') . '</div>';
        
        // Check individual dependencies
        $dependencies_status = [];
        if ($autoload_exists) {
            require_once $autoload_path;
            
            $dependencies = [
                'dompdf' => 'Dompdf\Dompdf',
                'phpmailer' => 'PHPMailer\PHPMailer\PHPMailer',
                'psr-log' => 'Psr\Log\LoggerInterface'
            ];
            
            foreach ($dependencies as $name => $class) {
                $exists = class_exists($class);
                $dependencies_status[$name] = $exists;
                echo '<div>' . ($exists ? '<span class="status-icon success">✓</span>' : '<span class="status-icon error">✗</span>');
                echo ucfirst($name) . ': ' . ($exists ? 'Installed' : 'Missing') . '</div>';
            }
        }
        
        echo '</div>';
        
        // Installation Instructions
        $all_deps_installed = $autoload_exists && !in_array(false, $dependencies_status ?? [], true);
        
        if (!$all_deps_installed) {
            echo '<div class="section warning">';
            echo '<h2>⚙️ Installation Instructions</h2>';
            echo '<p><strong>Some dependencies are missing.</strong> Follow these steps to install them:</p>';
            
            echo '<div class="steps">';
            
            echo '<div class="step">';
            echo '<strong>Install Composer (if not installed)</strong>';
            echo '<ul>';
            echo '<li>Download Composer from: <a href="https://getcomposer.org/download/" target="_blank">https://getcomposer.org/download/</a></li>';
            echo '<li>Install Composer globally on your system</li>';
            echo '<li>Restart your command prompt/terminal after installation</li>';
            echo '</ul>';
            echo '</div>';
            
            echo '<div class="step">';
            echo '<strong>Install Dependencies via Composer</strong>';
            echo '<p>Open a command prompt/terminal and navigate to your WBHSMS root directory, then run:</p>';
            echo '<div class="code">cd ' . htmlspecialchars($root_path) . '<br>composer install</div>';
            echo '</div>';
            
            echo '<div class="step">';
            echo '<strong>Alternative: Manual Installation</strong>';
            echo '<ul>';
            echo '<li>If Composer fails, you can manually download and extract the libraries:</li>';
            echo '<li><strong>Dompdf:</strong> Download from <a href="https://github.com/dompdf/dompdf/releases" target="_blank">GitHub Releases</a></li>';
            echo '<li><strong>PHPMailer:</strong> Download from <a href="https://github.com/PHPMailer/PHPMailer/releases" target="_blank">GitHub Releases</a></li>';
            echo '<li>Extract to appropriate directories in <code>/vendor/</code></li>';
            echo '</ul>';
            echo '</div>';
            
            echo '<div class="step">';
            echo '<strong>Verify Installation</strong>';
            echo '<p>After installation, refresh this page to verify all dependencies are properly installed.</p>';
            echo '</div>';
            
            echo '</div>';
            echo '</div>';
            
            echo '<div class="section error">';
            echo '<h2>🚨 Current System Behavior</h2>';
            echo '<p><strong>PDF Generation:</strong> Currently not working due to missing Dompdf library.</p>';
            echo '<p><strong>Fallback:</strong> The system will automatically use HTML print views for referrals and documents.</p>';
            echo '<p><strong>Email Features:</strong> May not work properly due to missing PHPMailer library.</p>';
            echo '</div>';
            
        } else {
            echo '<div class="section success">';
            echo '<h2>✅ All Dependencies Installed</h2>';
            echo '<p>Great! All required dependencies are properly installed and working.</p>';
            echo '<ul>';
            echo '<li><strong>PDF Generation:</strong> Fully functional with Dompdf</li>';
            echo '<li><strong>Email Features:</strong> Fully functional with PHPMailer</li>';
            echo '<li><strong>Logging:</strong> PSR-3 compatible logging available</li>';
            echo '</ul>';
            echo '</div>';
        }
        
        // Troubleshooting section
        echo '<div class="section">';
        echo '<h2>🔧 Troubleshooting</h2>';
        echo '<h3>Common Issues:</h3>';
        echo '<ul>';
        echo '<li><strong>Composer not found:</strong> Make sure Composer is installed globally and added to your PATH</li>';
        echo '<li><strong>Permission errors:</strong> Ensure the web server has read/write access to the vendor directory</li>';
        echo '<li><strong>Memory issues:</strong> Increase PHP memory_limit in php.ini if installation fails</li>';
        echo '<li><strong>Internet connection:</strong> Composer requires internet access to download packages</li>';
        echo '</ul>';
        
        echo '<h3>Manual Composer Commands:</h3>';
        echo '<div class="code">';
        echo '# Install all dependencies<br>';
        echo 'composer install<br><br>';
        echo '# Update existing dependencies<br>';
        echo 'composer update<br><br>';
        echo '# Install specific package<br>';
        echo 'composer require dompdf/dompdf<br>';
        echo 'composer require phpmailer/phpmailer';
        echo '</div>';
        echo '</div>';
        
        echo '<div class="section">';
        echo '<h2>📁 File Locations</h2>';
        echo '<ul>';
        echo '<li><strong>Root Directory:</strong> ' . htmlspecialchars($root_path) . '</li>';
        echo '<li><strong>Composer File:</strong> ' . htmlspecialchars($composer_json_path) . '</li>';
        echo '<li><strong>Vendor Directory:</strong> ' . htmlspecialchars($vendor_path) . '</li>';
        echo '<li><strong>Autoload File:</strong> ' . htmlspecialchars($autoload_path) . '</li>';
        echo '</ul>';
        echo '</div>';
        ?>

        <div class="section">
            <h2>🔄 Actions</h2>
            <p>
                <a href="?" style="background: #0077b6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">🔄 Refresh Status</a>
                <a href="../../index.php" style="background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-left: 10px;">🏠 Return to WBHSMS</a>
            </p>
        </div>
    </div>
</body>
</html>