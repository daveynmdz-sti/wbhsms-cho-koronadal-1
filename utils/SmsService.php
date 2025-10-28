<?php
/**
 * SMS Service for CHO Koronadal Healthcare Management System
 * Handles SMS notifications using Semaphore API
 * Supports regular messages and OTP delivery
 * Author: GitHub Copilot
 */

class SmsService {
    
    private $api_key;
    private $sender_name;
    private $base_url;
    private $debug_mode;
    
    /**
     * Constructor - Initialize SMS service with configuration
     */
    public function __construct() {
        // Load configuration from environment variables
        $this->api_key = getenv('SEMAPHORE_API_KEY') ?: $_ENV['SEMAPHORE_API_KEY'] ?? null;
        $this->sender_name = getenv('SEMAPHORE_SENDER_NAME') ?: $_ENV['SEMAPHORE_SENDER_NAME'] ?? 'CHO-Koronadal';
        $this->base_url = 'https://api.semaphore.co/api/v4';
        $this->debug_mode = (getenv('APP_DEBUG') === '1') || (($_ENV['APP_DEBUG'] ?? false) === '1');
        
        // Validate required configuration
        if (empty($this->api_key)) {
            error_log("SmsService Warning: SEMAPHORE_API_KEY not configured");
        }
        
        // Additional API key validation
        if (!empty($this->api_key)) {
            if (strlen($this->api_key) < 10) {
                error_log("SmsService Warning: SEMAPHORE_API_KEY appears to be invalid (too short)");
                $this->api_key = null; // Invalidate to prevent API calls
            }
            
            if ($this->api_key === 'your_semaphore_api_key_here' || 
                $this->api_key === 'your_test_api_key_here' ||
                $this->api_key === 'your_production_api_key_here') {
                error_log("SmsService Warning: SEMAPHORE_API_KEY is still using placeholder value");
                $this->api_key = null; // Invalidate placeholder values
            }
        }
    }
    
    /**
     * Send regular SMS message
     * @param string $phone_number Recipient phone number (format: +639XXXXXXXXX)
     * @param string $message Message content
     * @param array $options Optional settings (sender_name, priority)
     * @return array Result with success status, message, and response data
     */
    public function sendMessage($phone_number, $message, $options = []) {
        try {
            // Validate inputs
            $validation = $this->validateInputs($phone_number, $message);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => $validation['message'],
                    'phone_number' => $phone_number
                ];
            }
            
            // Format phone number
            $formatted_phone = $this->formatPhoneNumber($phone_number);
            
            // Prepare SMS data
            $sms_data = [
                'apikey' => $this->api_key,
                'number' => $formatted_phone,
                'message' => $message,
                'sendername' => $options['sender_name'] ?? $this->sender_name
            ];
            
            // Send SMS via API
            $response = $this->sendSmsRequest('/messages', $sms_data);
            
            // Log the attempt
            $this->logSmsAttempt('regular', $formatted_phone, $message, $response);
            
            if ($response['success']) {
                return [
                    'success' => true,
                    'message' => 'SMS sent successfully',
                    'phone_number' => $formatted_phone,
                    'message_id' => $response['data']['message_id'] ?? null,
                    'credits_used' => $response['data']['credits_used'] ?? null,
                    'api_response' => $response['data']
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to send SMS: ' . $response['message'],
                    'phone_number' => $formatted_phone,
                    'error_code' => $response['error_code'] ?? null,
                    'api_response' => $response['data']
                ];
            }
            
        } catch (Exception $e) {
            error_log("SmsService sendMessage error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'SMS service error: ' . $e->getMessage(),
                'phone_number' => $phone_number ?? 'unknown'
            ];
        }
    }
    
    /**
     * Send OTP SMS message with standardized format
     * @param string $phone_number Recipient phone number
     * @param string $otp_code OTP code to send
     * @param array $options Optional settings (expiry_minutes, service_name)
     * @return array Result with success status and details
     */
    public function sendOtp($phone_number, $otp_code, $options = []) {
        try {
            // Validate inputs
            if (empty($otp_code) || !preg_match('/^\d{4,8}$/', $otp_code)) {
                return [
                    'success' => false,
                    'message' => 'Invalid OTP code format. Must be 4-8 digits.',
                    'phone_number' => $phone_number
                ];
            }
            
            // Prepare OTP message
            $service_name = $options['service_name'] ?? 'CHO Koronadal';
            $expiry_minutes = $options['expiry_minutes'] ?? 10;
            
            $otp_message = $this->buildOtpMessage($otp_code, $service_name, $expiry_minutes);
            
            // Send OTP using regular message method
            $result = $this->sendMessage($phone_number, $otp_message, [
                'sender_name' => $options['sender_name'] ?? $this->sender_name
            ]);
            
            // Log OTP specific details
            if ($result['success']) {
                $this->logOtpSent($phone_number, $otp_code, $expiry_minutes);
                
                // Add OTP-specific data to response
                $result['otp_code'] = $otp_code;
                $result['expiry_minutes'] = $expiry_minutes;
                $result['expires_at'] = date('Y-m-d H:i:s', strtotime("+{$expiry_minutes} minutes"));
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("SmsService sendOtp error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'OTP SMS service error: ' . $e->getMessage(),
                'phone_number' => $phone_number ?? 'unknown'
            ];
        }
    }
    
    /**
     * Check SMS account balance and credits
     * @return array Account balance information
     */
    public function getAccountBalance() {
        try {
            if (empty($this->api_key)) {
                return [
                    'success' => false,
                    'message' => 'API key not configured'
                ];
            }
            
            $response = $this->sendSmsRequest('/account', [
                'apikey' => $this->api_key
            ], 'GET');
            
            if ($response['success']) {
                return [
                    'success' => true,
                    'balance' => $response['data']['account_balance'] ?? 0,
                    'status' => $response['data']['status'] ?? 'unknown',
                    'data' => $response['data']
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to get account balance: ' . $response['message']
                ];
            }
            
        } catch (Exception $e) {
            error_log("SmsService getAccountBalance error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Balance check error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Send HTTP request to Semaphore API
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param string $method HTTP method (POST, GET)
     * @return array Response data
     */
    private function sendSmsRequest($endpoint, $data, $method = 'POST') {
        $url = $this->base_url . $endpoint;
        
        // Initialize cURL
        $curl = curl_init();
        
        // Configure cURL options
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_USERAGENT => 'CHO-Koronadal-SMS-Service/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded'
            ]
        ]);
        
        // Set method-specific options
        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
            curl_setopt($curl, CURLOPT_URL, $url);
        }
        
        // Execute request
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($curl);
        curl_close($curl);
        
        // Handle cURL errors
        if ($response === false || !empty($curl_error)) {
            return [
                'success' => false,
                'message' => 'HTTP request failed: ' . $curl_error,
                'error_code' => 'CURL_ERROR',
                'data' => null
            ];
        }
        
        // Parse JSON response
        $decoded_response = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'Invalid JSON response: ' . json_last_error_msg(),
                'error_code' => 'JSON_ERROR',
                'data' => $response
            ];
        }
        
        // Debug logging
        if ($this->debug_mode) {
            error_log("SMS API Request - URL: $url, Data: " . json_encode($data));
            error_log("SMS API Response - HTTP Code: $http_code, Response: " . $response);
        }
        
        // Determine success based on HTTP code and response content
        $is_success = ($http_code >= 200 && $http_code < 300) && 
                     (isset($decoded_response['status']) ? 
                      (in_array($decoded_response['status'], ['success', 'queued', 'sent'])) : 
                      true);
        
        return [
            'success' => $is_success,
            'message' => $decoded_response['message'] ?? ($is_success ? 'Request successful' : 'Request failed'),
            'error_code' => $decoded_response['code'] ?? $http_code,
            'data' => $decoded_response,
            'http_code' => $http_code
        ];
    }
    
    /**
     * Validate phone number and message inputs
     * @param string $phone_number Phone number to validate
     * @param string $message Message to validate
     * @return array Validation result
     */
    private function validateInputs($phone_number, $message) {
        // Check API key
        if (empty($this->api_key)) {
            return [
                'valid' => false,
                'message' => 'SMS service not configured (missing API key)'
            ];
        }
        
        // Check for placeholder API key values
        if (in_array($this->api_key, [
            'your_semaphore_api_key_here',
            'your_test_api_key_here', 
            'your_production_api_key_here'
        ])) {
            return [
                'valid' => false,
                'message' => 'SMS service not configured (placeholder API key detected)'
            ];
        }
        
        // Validate phone number
        if (empty($phone_number)) {
            return [
                'valid' => false,
                'message' => 'Phone number is required'
            ];
        }
        
        // Validate Philippine phone number format
        $cleaned_phone = preg_replace('/[^0-9]/', '', $phone_number);
        if (!preg_match('/^(63|0)?9\d{9}$/', $cleaned_phone)) {
            return [
                'valid' => false,
                'message' => 'Invalid Philippine phone number format. Use 09XXXXXXXXX or +639XXXXXXXXX'
            ];
        }
        
        // Validate message
        if (empty($message)) {
            return [
                'valid' => false,
                'message' => 'Message content is required'
            ];
        }
        
        // Check message length (SMS limit is typically 160 characters)
        if (strlen($message) > 1000) {
            return [
                'valid' => false,
                'message' => 'Message too long (maximum 1000 characters)'
            ];
        }
        
        // Check for suspicious content
        $suspicious_patterns = [
            '/\b(hack|hacking|password|login|credit card)\b/i',
            '/\b(click here|urgent|winner|prize)\b/i'
        ];
        
        foreach ($suspicious_patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                error_log("SmsService: Suspicious message content detected for phone: " . $phone_number);
                // Don't block, just log for monitoring
                break;
            }
        }
        
        return [
            'valid' => true,
            'message' => 'Validation passed'
        ];
    }
    
    /**
     * Format phone number to Philippine mobile format
     * @param string $phone_number Input phone number
     * @return string Formatted phone number
     */
    private function formatPhoneNumber($phone_number) {
        // Remove all non-numeric characters
        $cleaned = preg_replace('/[^0-9]/', '', $phone_number);
        
        // Handle different Philippine phone number formats
        if (strlen($cleaned) === 11 && substr($cleaned, 0, 1) === '0') {
            // Convert 09XXXXXXXXX to +639XXXXXXXXX
            return '+63' . substr($cleaned, 1);
        } elseif (strlen($cleaned) === 10) {
            // Convert 9XXXXXXXXX to +639XXXXXXXXX
            return '+63' . $cleaned;
        } elseif (strlen($cleaned) === 12 && substr($cleaned, 0, 2) === '63') {
            // Convert 639XXXXXXXXX to +639XXXXXXXXX
            return '+' . $cleaned;
        } elseif (strlen($cleaned) === 13 && substr($cleaned, 0, 3) === '639') {
            // Already in +639XXXXXXXXX format, just add +
            return '+' . $cleaned;
        }
        
        // Return as-is if already properly formatted or can't determine format
        return $phone_number;
    }
    
    /**
     * Build standardized OTP message
     * @param string $otp_code OTP code
     * @param string $service_name Service name
     * @param int $expiry_minutes Expiry time in minutes
     * @return string Formatted OTP message
     */
    private function buildOtpMessage($otp_code, $service_name, $expiry_minutes) {
        return "Your {$service_name} verification code is: {$otp_code}. " .
               "This code will expire in {$expiry_minutes} minutes. " .
               "Please do not share this code with anyone for security purposes.";
    }
    
    /**
     * Log SMS attempt for audit purposes
     * @param string $type SMS type (regular, otp)
     * @param string $phone_number Recipient phone
     * @param string $message Message content
     * @param array $response API response
     */
    private function logSmsAttempt($type, $phone_number, $message, $response) {
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => $type,
            'phone_number' => $phone_number,
            'message_length' => strlen($message),
            'success' => $response['success'],
            'message_id' => $response['data']['message_id'] ?? null,
            'error_code' => $response['error_code'] ?? null
        ];
        
        // Log to file (don't include full message content for privacy)
        error_log("SMS Sent - " . json_encode($log_entry));
        
        // Optional: Store in database for detailed audit trail
        // This would require database connection and table structure
        // $this->saveSmsLog($log_entry, $message);
    }
    
    /**
     * Log OTP specific information
     * @param string $phone_number Recipient phone
     * @param string $otp_code OTP code
     * @param int $expiry_minutes Expiry time
     */
    private function logOtpSent($phone_number, $otp_code, $expiry_minutes) {
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => 'OTP_SENT',
            'phone_number' => $phone_number,
            'otp_length' => strlen($otp_code),
            'expiry_minutes' => $expiry_minutes,
            'expires_at' => date('Y-m-d H:i:s', strtotime("+{$expiry_minutes} minutes"))
        ];
        
        error_log("OTP Generated - " . json_encode($log_entry));
    }
    
    /**
     * Static method to send quick SMS (convenience method)
     * @param string $phone_number Recipient phone number
     * @param string $message Message content
     * @param array $options Optional settings
     * @return array Result
     */
    public static function send($phone_number, $message, $options = []) {
        $sms_service = new self();
        return $sms_service->sendMessage($phone_number, $message, $options);
    }
    
    /**
     * Static method to send quick OTP (convenience method)
     * @param string $phone_number Recipient phone number
     * @param string $otp_code OTP code
     * @param array $options Optional settings
     * @return array Result
     */
    public static function sendQuickOtp($phone_number, $otp_code, $options = []) {
        $sms_service = new self();
        return $sms_service->sendOtp($phone_number, $otp_code, $options);
    }
    
    /**
     * Generate random OTP code
     * @param int $length OTP length (4-8 digits)
     * @return string Generated OTP
     */
    public static function generateOtp($length = 6) {
        if ($length < 4 || $length > 8) {
            $length = 6; // Default to 6 digits
        }
        
        $min = pow(10, $length - 1);
        $max = pow(10, $length) - 1;
        
        return str_pad(random_int($min, $max), $length, '0', STR_PAD_LEFT);
    }
    
    /**
     * Validate OTP format
     * @param string $otp OTP to validate
     * @param int $expected_length Expected OTP length
     * @return bool True if valid
     */
    public static function validateOtpFormat($otp, $expected_length = 6) {
        return preg_match('/^\d{' . $expected_length . '}$/', $otp);
    }
}
?>