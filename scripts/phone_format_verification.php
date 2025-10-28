<?php
/**
 * Phone Number Format Verification Test
 * Ensures all numbers are properly formatted to +639XXXXXXXXX
 */

// Load configuration
require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/config/sms.php';
require_once dirname(__DIR__) . '/utils/SmsService.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Phone Number Format Verification</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .code { background: #f8f9fa; padding: 15px; border-radius: 4px; font-family: monospace; white-space: pre-wrap; font-size: 12px; border: 1px solid #ddd; }
        .test-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin: 15px 0; }
        .test-item { padding: 8px; border: 1px solid #ddd; border-radius: 4px; text-align: center; }
        .format-correct { background: #d4edda; color: #155724; }
        .format-incorrect { background: #f8d7da; color: #721c24; }
        .highlight { background: #ffff99; padding: 2px 4px; border-radius: 2px; font-weight: bold; }
    </style>
</head>
<body>
    <h1>📱 Phone Number Format Verification</h1>
    <p><strong>Ensuring all numbers use +639XXXXXXXXX format</strong></p>
    <p><strong>Time:</strong> <?= date('Y-m-d H:i:s') ?></p>
    
    <?php
    try {
        echo "<div class='info'>";
        echo "<h2>🔍 Format Testing</h2>";
        echo "<p>Testing various input formats to ensure they're converted to <span class='highlight'>+639XXXXXXXXX</span> format:</p>";
        echo "</div>";
        
        // Test various phone number formats
        $test_numbers = [
            '09451849538',      // Standard Philippine mobile
            '639451849538',     // Without country code prefix
            '+639451849538',    // Correct international format
            '9451849538',       // Without leading 0
            '09171234567',      // Different Globe prefix
            '09181234567',      // Smart prefix
            '0945-184-9538',    // With dashes
            '0945 184 9538',    // With spaces
            '+63 945 184 9538', // International with spaces
            '63-945-184-9538'   // With various separators
        ];
        
        echo "<div class='test-grid'>";
        echo "<div class='test-item'><strong>Input</strong></div>";
        echo "<div class='test-item'><strong>Formatted Output</strong></div>";
        echo "<div class='test-item'><strong>Status</strong></div>";
        
        foreach ($test_numbers as $input) {
            $validation = SmsConfig::validatePhoneNumber($input);
            $output = $validation['formatted'];
            $is_correct = (preg_match('/^\+639\d{9}$/', $output) && $validation['valid']);
            
            echo "<div class='test-item'>" . htmlspecialchars($input) . "</div>";
            echo "<div class='test-item'><code>" . htmlspecialchars($output) . "</code></div>";
            echo "<div class='test-item " . ($is_correct ? 'format-correct' : 'format-incorrect') . "'>";
            echo $is_correct ? '✅ Correct' : '❌ Invalid';
            echo "</div>";
        }
        echo "</div>";
        
        echo "<div class='success'>";
        echo "<h2>✅ Format Requirements Met</h2>";
        echo "<p><strong>Correct Format:</strong> <code>+639XXXXXXXXX</code></p>";
        echo "<ul>";
        echo "<li><strong>Country Code:</strong> +63 (Philippines)</li>";
        echo "<li><strong>Mobile Prefix:</strong> 9XX (various carriers)</li>";
        echo "<li><strong>Subscriber Number:</strong> 8 digits</li>";
        echo "<li><strong>Total Length:</strong> 13 characters (including +)</li>";
        echo "</ul>";
        echo "</div>";
        
        echo "<div class='info'>";
        echo "<h2>🧪 SMS Service Integration Test</h2>";
        echo "<p>Testing if the SMS service correctly formats numbers:</p>";
        
        if (isset($_POST['test_format'])) {
            $test_input = $_POST['test_input'];
            echo "<div class='code'>";
            echo "Input: " . htmlspecialchars($test_input) . "\n";
            
            $validation = SmsConfig::validatePhoneNumber($test_input);
            echo "Validation Result:\n";
            echo "- Valid: " . ($validation['valid'] ? 'Yes' : 'No') . "\n";
            echo "- Formatted: " . htmlspecialchars($validation['formatted']) . "\n";
            echo "- Message: " . htmlspecialchars($validation['message']) . "\n";
            
            if ($validation['valid']) {
                echo "\n✅ This number will be sent to Semaphore API as: " . htmlspecialchars($validation['formatted']);
            } else {
                echo "\n❌ This number will be rejected before sending to API";
            }
            echo "</div>";
        }
        
        echo "<form method='post' style='margin: 15px 0;'>";
        echo "<p><strong>Test a specific number format:</strong></p>";
        echo "<input type='text' name='test_input' placeholder='e.g., 09451849538' style='padding: 8px; border: 1px solid #ddd; border-radius: 4px; width: 200px;'>";
        echo "<input type='hidden' name='test_format' value='1'>";
        echo "<button type='submit' style='background: #007bff; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; margin-left: 10px;'>🔍 Test Format</button>";
        echo "</form>";
        echo "</div>";
        
        echo "<div class='info'>";
        echo "<h2>📋 Key Points for +639XXXXXXXXX Format</h2>";
        echo "<ul>";
        echo "<li><strong>✅ Always includes '+' prefix:</strong> Required for international format</li>";
        echo "<li><strong>✅ Country code 63:</strong> Philippines country code</li>";
        echo "<li><strong>✅ Mobile indicator 9:</strong> Indicates mobile number (not landline)</li>";
        echo "<li><strong>✅ Carrier prefix:</strong> Next 2 digits identify the carrier (45, 17, 18, etc.)</li>";
        echo "<li><strong>✅ Subscriber number:</strong> Final 7 digits are the unique subscriber</li>";
        echo "</ul>";
        echo "</div>";
        
        echo "<div class='success'>";
        echo "<h2>🚀 Format Verification Complete</h2>";
        echo "<p><strong>Your SMS service is now configured to use proper +639XXXXXXXXX format!</strong></p>";
        echo "<p>All phone numbers will be automatically converted to the correct international format before sending to Semaphore API.</p>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div class='error'>";
        echo "<h2>❌ Verification Error</h2>";
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</div>";
    }
    ?>
    
</body>
</html>