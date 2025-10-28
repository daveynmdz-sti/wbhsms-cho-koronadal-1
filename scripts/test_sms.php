<?php
/**
 * SMS Service Test Script
 * Test SMS functionality for CHO Koronadal WBHSMS
 * 
 * Usage: http://localhost/wbhsms-cho-koronadal/scripts/test_sms.php
 */

// Load environment configuration
require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/utils/SmsService.php';

// HTML output for better display
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SMS Service Test - CHO Koronadal</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .info { background: #d1ecf1; color: #0c5460; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .code { background: #f8f9fa; padding: 10px; border-radius: 4px; font-family: monospace; white-space: pre-wrap; }
        .test-section { border: 1px solid #ddd; padding: 15px; margin: 15px 0; border-radius: 4px; }
        .form-group { margin: 10px 0; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
    </style>
</head>
<body>
    <h1>🏥 SMS Service Test - CHO Koronadal</h1>
    
    <?php
    
    // Test 1: Configuration Check
    echo "<div class='test-section'>";
    echo "<h2>📋 Configuration Check</h2>";
    
    $sms = new SmsService();
    
    // Check environment variables
    $api_key = $_ENV['SEMAPHORE_API_KEY'] ?? 'Not configured';
    $sender_name = $_ENV['SEMAPHORE_SENDER_NAME'] ?? 'Not configured';
    
    echo "<div class='info'>";
    echo "<strong>Environment Variables:</strong><br>";
    echo "API Key: " . (strlen($api_key) > 10 ? substr($api_key, 0, 10) . "..." : $api_key) . "<br>";
    echo "Sender Name: " . htmlspecialchars($sender_name) . "<br>";
    echo "Debug Mode: " . (getenv('APP_DEBUG') === '1' ? 'Enabled' : 'Disabled');
    echo "</div>";
    
    // Test 2: Account Balance Check
    echo "<h3>💰 Account Balance Check</h3>";
    try {
        $balance_result = $sms->getAccountBalance();
        
        if ($balance_result['success']) {
            echo "<div class='success'>";
            echo "<strong>✅ Account Balance Retrieved Successfully!</strong><br>";
            echo "Balance: " . ($balance_result['balance'] ?? 'Unknown') . "<br>";
            echo "Status: " . ($balance_result['status'] ?? 'Unknown');
            echo "</div>";
        } else {
            echo "<div class='error'>";
            echo "<strong>❌ Failed to get account balance:</strong><br>";
            echo htmlspecialchars($balance_result['message']);
            echo "</div>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>";
        echo "<strong>❌ Error checking balance:</strong><br>";
        echo htmlspecialchars($e->getMessage());
        echo "</div>";
    }
    
    echo "</div>";
    
    // Test 3: Phone Number Validation
    echo "<div class='test-section'>";
    echo "<h2>📱 Phone Number Format Testing</h2>";
    
    $test_numbers = [
        '09123456789',
        '+639123456789', 
        '639123456789',
        '9123456789',
        '123456789', // Invalid
        'invalid' // Invalid
    ];
    
    foreach ($test_numbers as $test_number) {
        $reflection = new ReflectionClass('SmsService');
        $method = $reflection->getMethod('formatPhoneNumber');
        $method->setAccessible(true);
        
        $formatted = $method->invoke($sms, $test_number);
        $is_valid = preg_match('/^\+639\d{9}$/', $formatted);
        
        $status_class = $is_valid ? 'success' : 'error';
        $status_icon = $is_valid ? '✅' : '❌';
        
        echo "<div class='$status_class'>";
        echo "$status_icon Input: $test_number → Output: $formatted";
        echo "</div>";
    }
    
    echo "</div>";
    
    // Test 4: OTP Generation
    echo "<div class='test-section'>";
    echo "<h2>🔐 OTP Generation Testing</h2>";
    
    for ($length = 4; $length <= 8; $length++) {
        $otp = SmsService::generateOtp($length);
        $is_valid = SmsService::validateOtpFormat($otp, $length);
        
        $status_class = $is_valid ? 'success' : 'error';
        $status_icon = $is_valid ? '✅' : '❌';
        
        echo "<div class='$status_class'>";
        echo "$status_icon Generated {$length}-digit OTP: $otp (Valid: " . ($is_valid ? 'Yes' : 'No') . ")";
        echo "</div>";
    }
    
    echo "</div>";
    
    // Test 5: Live SMS Testing Form (if POST data)
    echo "<div class='test-section'>";
    echo "<h2>📤 Live SMS Testing</h2>";
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $test_phone = $_POST['test_phone'] ?? '';
        $test_message = $_POST['test_message'] ?? '';
        $test_type = $_POST['test_type'] ?? 'message';
        
        if (!empty($test_phone)) {
            echo "<h3>Sending Test SMS...</h3>";
            
            try {
                if ($test_type === 'otp') {
                    $otp = SmsService::generateOtp(6);
                    $result = $sms->sendOtp($test_phone, $otp, [
                        'expiry_minutes' => 10,
                        'service_name' => 'CHO Koronadal Test'
                    ]);
                    
                    echo "<div class='info'>Generated OTP: <strong>$otp</strong></div>";
                } else {
                    $message = !empty($test_message) ? $test_message : 'Test message from CHO Koronadal WBHSMS';
                    $result = $sms->sendMessage($test_phone, $message);
                }
                
                if ($result['success']) {
                    echo "<div class='success'>";
                    echo "<strong>✅ SMS Sent Successfully!</strong><br>";
                    echo "Phone: " . htmlspecialchars($result['phone_number']) . "<br>";
                    if (isset($result['message_id'])) {
                        echo "Message ID: " . htmlspecialchars($result['message_id']) . "<br>";
                    }
                    if (isset($result['credits_used'])) {
                        echo "Credits Used: " . htmlspecialchars($result['credits_used']);
                    }
                    echo "</div>";
                } else {
                    echo "<div class='error'>";
                    echo "<strong>❌ SMS Failed:</strong><br>";
                    echo htmlspecialchars($result['message']);
                    echo "</div>";
                }
                
                echo "<div class='code'>";
                echo "API Response:\n" . json_encode($result, JSON_PRETTY_PRINT);
                echo "</div>";
                
            } catch (Exception $e) {
                echo "<div class='error'>";
                echo "<strong>❌ Error sending SMS:</strong><br>";
                echo htmlspecialchars($e->getMessage());
                echo "</div>";
            }
        }
    }
    
    // SMS Testing Form
    ?>
    
    <form method="POST" style="margin-top: 20px;">
        <div class="form-group">
            <label for="test_phone">Phone Number (format: +639XXXXXXXXX or 09XXXXXXXXX):</label>
            <input type="text" id="test_phone" name="test_phone" placeholder="+639123456789" value="<?php echo htmlspecialchars($_POST['test_phone'] ?? ''); ?>">
        </div>
        
        <div class="form-group">
            <label for="test_type">Test Type:</label>
            <select id="test_type" name="test_type" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <option value="message" <?php echo ($_POST['test_type'] ?? '') === 'message' ? 'selected' : ''; ?>>Regular Message</option>
                <option value="otp" <?php echo ($_POST['test_type'] ?? '') === 'otp' ? 'selected' : ''; ?>>OTP Message</option>
            </select>
        </div>
        
        <div class="form-group" id="message-group">
            <label for="test_message">Message (leave empty for default test message):</label>
            <textarea id="test_message" name="test_message" rows="3" placeholder="Your test message here..."><?php echo htmlspecialchars($_POST['test_message'] ?? ''); ?></textarea>
        </div>
        
        <button type="submit">Send Test SMS</button>
    </form>
    
    <script>
        // Hide message field for OTP test
        document.getElementById('test_type').addEventListener('change', function() {
            const messageGroup = document.getElementById('message-group');
            if (this.value === 'otp') {
                messageGroup.style.display = 'none';
            } else {
                messageGroup.style.display = 'block';
            }
        });
        
        // Initial state
        if (document.getElementById('test_type').value === 'otp') {
            document.getElementById('message-group').style.display = 'none';
        }
    </script>
    
    </div>
    
    <div class="test-section">
        <h2>📝 Usage Examples</h2>
        
        <h3>Basic SMS:</h3>
        <div class="code"><?php echo htmlspecialchars('<?php
require_once \'utils/SmsService.php\';

$sms = new SmsService();
$result = $sms->sendMessage(\'+639123456789\', \'Your appointment is confirmed.\');

if ($result[\'success\']) {
    echo "SMS sent successfully!";
} else {
    echo "Error: " . $result[\'message\'];
}
?>'); ?></div>
        
        <h3>OTP SMS:</h3>
        <div class="code"><?php echo htmlspecialchars('<?php
$otp = SmsService::generateOtp(6);
$result = SmsService::sendQuickOtp(\'+639123456789\', $otp, [
    \'expiry_minutes\' => 15,
    \'service_name\' => \'CHO Koronadal\'
]);
?>'); ?></div>
    </div>
    
    <div class="info">
        <strong>💡 Tips:</strong><br>
        • Make sure your Semaphore account has sufficient credits<br>
        • Use Philippine mobile numbers (+639XXXXXXXXX format)<br>
        • Check logs for detailed error information<br>
        • Test with your own phone number first
    </div>
    
</body>
</html>