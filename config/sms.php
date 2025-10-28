<?php
/**
 * SMS Configuration for WBHSMS CHO Koronadal
 * Handles Semaphore API settings and SMS service configuration
 * Author: GitHub Copilot
 */

// Ensure environment variables are loaded
if (empty($_ENV['SEMAPHORE_API_KEY']) && empty(getenv('SEMAPHORE_API_KEY'))) {
    // Load environment variables
    require_once __DIR__ . '/env.php';
}

/**
 * SMS Configuration Class
 * Provides centralized SMS service configuration and constants
 */
class SmsConfig {
    
    // SMS Service Constants
    const API_BASE_URL = 'https://api.semaphore.co/api/v4';
    const DEFAULT_SENDER_NAME = 'CHO-Koronadal';
    const MAX_MESSAGE_LENGTH = 1000;
    const DEFAULT_TIMEOUT = 30;
    const OTP_DEFAULT_LENGTH = 6;
    const OTP_DEFAULT_EXPIRY = 10; // minutes
    
    /**
     * Get SMS API configuration
     * @return array SMS configuration settings
     */
    public static function getSmsConfig() {
        // Get environment-specific settings
        $api_key = self::getApiKey();
        $sender_name = self::getSenderName();
        
        return [
            'api_key' => $api_key,
            'sender_name' => $sender_name,
            'base_url' => self::API_BASE_URL,
            'timeout' => self::DEFAULT_TIMEOUT,
            'max_length' => self::MAX_MESSAGE_LENGTH,
            'debug_mode' => self::isDebugMode(),
            'is_configured' => !empty($api_key) && $api_key !== 'your_semaphore_api_key_here'
        ];
    }
    
    /**
     * Get Semaphore API Key from environment
     * @return string API key or empty string if not configured
     */
    public static function getApiKey() {
        $api_key = getenv('SEMAPHORE_API_KEY') ?: $_ENV['SEMAPHORE_API_KEY'] ?? '';
        
        // Validate API key format and prevent placeholder values
        if (empty($api_key) || 
            in_array($api_key, [
                'your_semaphore_api_key_here',
                'your_test_api_key_here',
                'your_production_api_key_here'
            ]) ||
            strlen($api_key) < 10) {
            
            // Log warning if debug mode is enabled
            if (self::isDebugMode()) {
                error_log("SmsConfig: Invalid or missing SEMAPHORE_API_KEY");
            }
            
            return '';
        }
        
        return $api_key;
    }
    
    /**
     * Get SMS sender name from environment
     * @return string Sender name
     */
    public static function getSenderName() {
        return getenv('SEMAPHORE_SENDER_NAME') ?: 
               $_ENV['SEMAPHORE_SENDER_NAME'] ?? 
               self::DEFAULT_SENDER_NAME;
    }
    
    /**
     * Check if SMS service is properly configured
     * @return bool True if configured, false otherwise
     */
    public static function isConfigured() {
        $api_key = self::getApiKey();
        return !empty($api_key) && $api_key !== 'your_semaphore_api_key_here';
    }
    
    /**
     * Check if debug mode is enabled
     * @return bool True if debug mode is on
     */
    public static function isDebugMode() {
        return (getenv('APP_DEBUG') === '1') || (($_ENV['APP_DEBUG'] ?? false) === '1');
    }
    
    /**
     * Get environment-specific configuration
     * @return array Environment info and settings
     */
    public static function getEnvironmentInfo() {
        // Auto-detect environment like other config files
        $is_local = ($_SERVER['SERVER_NAME'] === 'localhost' || 
                    $_SERVER['SERVER_NAME'] === '127.0.0.1' || 
                    strpos($_SERVER['SERVER_NAME'], 'localhost') !== false ||
                    $_SERVER['HTTP_HOST'] === 'localhost' ||
                    (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false));

        $is_production = (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] === '31.97.106.60') ||
                        (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], '31.97.106.60') !== false) ||
                        (getenv('ENVIRONMENT') === 'production');
        
        return [
            'is_local' => $is_local,
            'is_production' => $is_production,
            'app_env' => getenv('APP_ENV') ?: $_ENV['APP_ENV'] ?? 'development',
            'debug_mode' => self::isDebugMode()
        ];
    }
    
    /**
     * Get OTP configuration settings
     * @return array OTP configuration
     */
    public static function getOtpConfig() {
        return [
            'default_length' => self::OTP_DEFAULT_LENGTH,
            'default_expiry_minutes' => self::OTP_DEFAULT_EXPIRY,
            'min_length' => 4,
            'max_length' => 8,
            'allowed_chars' => '0123456789' // Only numeric OTP
        ];
    }
    
    /**
     * Get system information for SMS templates
     * @return array System information
     */
    public static function getSystemInfo() {
        return [
            'system_name' => 'CHO Koronadal Health Management System',
            'system_url' => getenv('SYSTEM_URL') ?: $_ENV['SYSTEM_URL'] ?? 'http://localhost/wbhsms-cho-koronadal',
            'contact_phone' => getenv('CONTACT_PHONE') ?: $_ENV['CONTACT_PHONE'] ?? '(083) 228-8042',
            'contact_email' => getenv('CONTACT_EMAIL') ?: $_ENV['CONTACT_EMAIL'] ?? 'info@chokoronadal.gov.ph',
            'facility_address' => getenv('FACILITY_ADDRESS') ?: $_ENV['FACILITY_ADDRESS'] ?? 'Koronadal City, South Cotabato',
            'facility_name' => 'City Health Office of Koronadal'
        ];
    }
    
    /**
     * Get pre-defined SMS templates
     * @return array SMS message templates
     */
    public static function getMessageTemplates() {
        $system_info = self::getSystemInfo();
        
        return [
            'appointment_confirmation' => [
                'template' => 'Mabuhay from the City Health Office of Koronadal! Here is your REMINDER for the following: Your appointment at {facility_name} is confirmed for {date} at {time}. Appointment ID: {appointment_id}. Please arrive 15 minutes early. Contact: {contact_phone}',
                'required_vars' => ['facility_name', 'date', 'time', 'appointment_id', 'contact_phone']
            ],
            'appointment_reminder' => [
                'template' => 'Mabuhay from the City Health Office of Koronadal! Here is your REMINDER for the following: You have an appointment tomorrow at {facility_name} at {time}. Appointment ID: {appointment_id}. Please bring valid ID and arrive early. Contact: {contact_phone}',
                'required_vars' => ['facility_name', 'time', 'appointment_id', 'contact_phone']
            ],
            'otp_verification' => [
                'template' => 'Your {service_name} verification code is: {otp_code}. This code will expire in {expiry_minutes} minutes. Please do not share this code with anyone. - City Health Office of Koronadal',
                'required_vars' => ['service_name', 'otp_code', 'expiry_minutes']
            ],
            'appointment_cancelled' => [
                'template' => 'Mabuhay from the City Health Office of Koronadal! Here is your REMINDER for the following: Your appointment (ID: {appointment_id}) at {facility_name} scheduled for {date} has been cancelled. Please contact us to reschedule: {contact_phone}',
                'required_vars' => ['appointment_id', 'facility_name', 'date', 'contact_phone']
            ],
            'queue_notification' => [
                'template' => 'Mabuhay from the City Health Office of Koronadal! Here is your REMINDER for the following: Your queue number {queue_number} is now being served at {station_name}. Please proceed to the station. {facility_name}',
                'required_vars' => ['queue_number', 'station_name', 'facility_name']
            ],
            'general_reminder' => [
                'template' => 'Mabuhay from the City Health Office of Koronadal! Here is your REMINDER for the following: {message_content}',
                'required_vars' => ['message_content']
            ]
        ];
    }
    
    /**
     * Validate phone number format for Philippine mobile numbers
     * @param string $phone_number Phone number to validate
     * @return array Validation result with status and formatted number
     */
    public static function validatePhoneNumber($phone_number) {
        // Remove all non-numeric characters
        $cleaned = preg_replace('/[^0-9]/', '', $phone_number);
        
        // Check if it's a valid Philippine mobile number
        $patterns = [
            '/^(639\d{9})$/',     // 639XXXXXXXXX (12 digits)
            '/^(09\d{9})$/',      // 09XXXXXXXXX (11 digits) 
            '/^(9\d{9})$/'        // 9XXXXXXXXX (10 digits)
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $cleaned)) {
                // Format to +639XXXXXXXXX
                if (strlen($cleaned) === 11 && substr($cleaned, 0, 1) === '0') {
                    $formatted = '+63' . substr($cleaned, 1);
                } elseif (strlen($cleaned) === 10) {
                    $formatted = '+63' . $cleaned;
                } elseif (strlen($cleaned) === 12 && substr($cleaned, 0, 2) === '63') {
                    $formatted = '+' . $cleaned;
                } else {
                    $formatted = $phone_number; // Keep original if already formatted
                }
                
                return [
                    'valid' => true,
                    'formatted' => $formatted,
                    'message' => 'Valid Philippine mobile number'
                ];
            }
        }
        
        return [
            'valid' => false,
            'formatted' => $phone_number,
            'message' => 'Invalid Philippine mobile number format. Use 09XXXXXXXXX or +639XXXXXXXXX'
        ];
    }
    
    /**
     * Check SMS service status and configuration
     * @return array Service status information
     */
    public static function getServiceStatus() {
        $config = self::getSmsConfig();
        $env_info = self::getEnvironmentInfo();
        
        $status = [
            'configured' => $config['is_configured'],
            'environment' => $env_info['app_env'],
            'debug_mode' => $config['debug_mode'],
            'api_base_url' => $config['base_url'],
            'sender_name' => $config['sender_name'],
            'api_key_status' => $config['is_configured'] ? 'Valid' : 'Missing or Invalid',
            'ready_to_send' => $config['is_configured'],
            'issues' => []
        ];
        
        // Check for common configuration issues
        if (!$config['is_configured']) {
            $status['issues'][] = 'SEMAPHORE_API_KEY not configured or invalid';
        }
        
        if (empty($config['sender_name']) || $config['sender_name'] === 'CHO-Dev') {
            $status['issues'][] = 'Sender name not configured or using development default';
        }
        
        if (!function_exists('curl_init')) {
            $status['issues'][] = 'cURL extension not available';
            $status['ready_to_send'] = false;
        }
        
        return $status;
    }
}

// Define global SMS configuration constants
if (!defined('SMS_API_KEY')) {
    define('SMS_API_KEY', SmsConfig::getApiKey());
}

if (!defined('SMS_SENDER_NAME')) {
    define('SMS_SENDER_NAME', SmsConfig::getSenderName());
}

if (!defined('SMS_BASE_URL')) {
    define('SMS_BASE_URL', SmsConfig::API_BASE_URL);
}

if (!defined('SMS_CONFIGURED')) {
    define('SMS_CONFIGURED', SmsConfig::isConfigured());
}

// Global helper functions for SMS operations
/**
 * Get SMS configuration as array
 * @return array SMS configuration
 */
function getSmsConfig() {
    return SmsConfig::getSmsConfig();
}

/**
 * Check if SMS service is configured and ready
 * @return bool True if ready to send SMS
 */
function isSmsConfigured() {
    return SmsConfig::isConfigured();
}

/**
 * Get formatted phone number for SMS sending
 * @param string $phone_number Input phone number
 * @return string Formatted phone number or original if invalid
 */
function formatPhoneForSms($phone_number) {
    $validation = SmsConfig::validatePhoneNumber($phone_number);
    return $validation['formatted'];
}

/**
 * Validate phone number for SMS
 * @param string $phone_number Phone number to validate
 * @return bool True if valid Philippine mobile number
 */
function isValidSmsPhone($phone_number) {
    $validation = SmsConfig::validatePhoneNumber($phone_number);
    return $validation['valid'];
}

/**
 * Get SMS template by name
 * @param string $template_name Template name
 * @param array $variables Variables to replace in template
 * @return string|false Formatted message or false if template not found
 */
function getSmsTemplate($template_name, $variables = []) {
    $templates = SmsConfig::getMessageTemplates();
    
    if (!isset($templates[$template_name])) {
        return false;
    }
    
    $template = $templates[$template_name]['template'];
    $required_vars = $templates[$template_name]['required_vars'];
    
    // Check if all required variables are provided
    foreach ($required_vars as $var) {
        if (!isset($variables[$var])) {
            error_log("SMS Template Error: Missing required variable '{$var}' for template '{$template_name}'");
            return false;
        }
    }
    
    // Replace variables in template
    foreach ($variables as $key => $value) {
        $template = str_replace('{' . $key . '}', $value, $template);
    }
    
    return $template;
}

// Log SMS configuration status if debug mode is enabled
if (SmsConfig::isDebugMode()) {
    $status = SmsConfig::getServiceStatus();
    error_log("SMS Service Status: " . ($status['ready_to_send'] ? 'Ready' : 'Not Ready') . 
              " (Environment: {$status['environment']}, API Key: {$status['api_key_status']})");
    
    if (!empty($status['issues'])) {
        error_log("SMS Configuration Issues: " . implode(', ', $status['issues']));
    }
}

?>