<?php
// includes/medical_print_security.php
// Security functions for medical print system
// Functions: currentUser(), userCanAccessPatient($userId, $patientId), validateCsrfToken(), logAuditAction($userId, $patientId, $action)

/**
 * Medical Print Security Manager
 * Handles authentication, authorization, CSRF protection, rate limiting, and audit logging
 */
class MedicalPrintSecurity {
    
    private $pdo;
    private $sessionPrefix = 'EMPLOYEE_SESSID';
    
    public function __construct() {
        global $pdo;
        $this->pdo = $pdo;
    }
    
    /**
     * Get current authenticated user information
     * @return array|null User data or null if not authenticated
     */
    public function currentUser() {
        if (!is_employee_logged_in()) {
            return null;
        }
        
        $employeeId = get_employee_session('employee_id');
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    e.employee_id,
                    e.first_name,
                    e.last_name,
                    r.role_name as role,
                    e.email,
                    e.contact_num as contact_number,
                    e.status as is_active,
                    e.created_at
                FROM employees e
                JOIN roles r ON e.role_id = r.role_id
                WHERE e.employee_id = ? AND e.status = 'active'
            ");
            $stmt->execute([$employeeId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Add session metadata
                $user['session_id'] = session_id();
                $user['session_start'] = get_employee_session('login_time');
                $user['last_activity'] = get_employee_session('last_activity');
            }
            
            return $user;
            
        } catch (PDOException $e) {
            error_log("Medical Print Security - Current User Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if user can access specific patient records
     * @param int $userId Employee ID
     * @param int $patientId Patient ID
     * @return bool Access permission status
     */
    public function userCanAccessPatient($userId, $patientId) {
        try {
            // Get user role and details
            $stmt = $this->pdo->prepare("
                SELECT r.role_name, e.status 
                FROM employees e
                JOIN roles r ON e.role_id = r.role_id
                WHERE e.employee_id = ? AND e.status = 'active'
            ");
            $stmt->execute([$userId]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$employee) {
                return false;
            }
            
            $role = $employee['role_name'];
            
            // Check if patient exists and is active
            $stmt = $this->pdo->prepare("
                SELECT patient_id, is_active, barangay, municipality 
                FROM patients 
                WHERE patient_id = ?
            ");
            $stmt->execute([$patientId]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$patient || !$patient['is_active']) {
                return false;
            }
            
            // Role-based access control
            switch ($role) {
                case 'admin':
                case 'doctor':
                case 'nurse':
                case 'records_officer':
                    // Full access to all patients
                    return true;
                    
                case 'dho':
                    // DHO has access to all patients in their district
                    return true;
                    
                case 'bhw':
                    // BHW can only access patients from assigned barangays
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(*) as can_access
                        FROM employee_barangay_assignments eba
                        WHERE eba.employee_id = ? 
                        AND eba.barangay = ? 
                        AND eba.is_active = 1
                    ");
                    $stmt->execute([$userId, $patient['barangay']]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $result['can_access'] > 0;
                    
                case 'Laboratory Tech':
                case 'Pharmacist':
                case 'Cashier':
                    // Limited access - only patients they have actively served
                    $stmt = $this->pdo->prepare("
                        SELECT COUNT(*) as has_interaction
                        FROM (
                            SELECT patient_id FROM consultations WHERE doctor_id = ? AND patient_id = ?
                            UNION
                            SELECT patient_id FROM prescriptions WHERE prescribed_by = ? AND patient_id = ?
                            UNION
                            SELECT patient_id FROM lab_orders WHERE ordered_by = ? AND patient_id = ?
                            UNION
                            SELECT patient_id FROM billing WHERE processed_by = ? AND patient_id = ?
                        ) as interactions
                    ");
                    $stmt->execute([$userId, $patientId, $userId, $patientId, $userId, $patientId, $userId, $patientId]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $result['has_interaction'] > 0;
                    
                default:
                    return false;
            }
            
        } catch (PDOException $e) {
            error_log("Medical Print Security - Access Check Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate CSRF token for forms
     * @return string CSRF token
     */
    public function generateCsrfToken() {
        if (!isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
        
        $token = bin2hex(random_bytes(32));
        $timestamp = time();
        
        // Store token with timestamp (tokens expire after 1 hour)
        $_SESSION['csrf_tokens'][$token] = $timestamp;
        
        // Clean up expired tokens
        $this->cleanupExpiredTokens();
        
        return $token;
    }
    
    /**
     * Validate CSRF token
     * @param string $token Token to validate
     * @return bool Validation status
     */
    public function validateCsrfToken($token) {
        if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
            return false;
        }
        
        if (!isset($_SESSION['csrf_tokens'][$token])) {
            return false;
        }
        
        $tokenTime = $_SESSION['csrf_tokens'][$token];
        $currentTime = time();
        
        // Token expires after 1 hour (3600 seconds)
        if (($currentTime - $tokenTime) > 3600) {
            unset($_SESSION['csrf_tokens'][$token]);
            return false;
        }
        
        // Remove token after use (one-time use)
        unset($_SESSION['csrf_tokens'][$token]);
        return true;
    }
    
    /**
     * Clean up expired CSRF tokens
     */
    private function cleanupExpiredTokens() {
        if (!isset($_SESSION['csrf_tokens'])) {
            return;
        }
        
        $currentTime = time();
        foreach ($_SESSION['csrf_tokens'] as $token => $timestamp) {
            if (($currentTime - $timestamp) > 3600) {
                unset($_SESSION['csrf_tokens'][$token]);
            }
        }
    }
    
    /**
     * Check rate limiting for PDF generation
     * @param int $userId Employee ID
     * @return bool Whether request is within rate limits
     */
    public function checkRateLimit($userId) {
        try {
            $currentTime = time();
            $oneHourAgo = $currentTime - 3600;
            $oneDayAgo = $currentTime - 86400;
            
            // Check hourly limit (10 PDFs per hour)
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as hourly_count
                FROM medical_record_access_logs 
                WHERE employee_id = ? 
                AND access_type = 'generate'
                AND output_format = 'pdf'
                AND created_at >= FROM_UNIXTIME(?)
            ");
            $stmt->execute([$userId, $oneHourAgo]);
            $hourlyCount = $stmt->fetch(PDO::FETCH_ASSOC)['hourly_count'];
            
            if ($hourlyCount >= 10) {
                return false;
            }
            
            // Check daily limit (50 PDFs per day)
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as daily_count
                FROM medical_record_access_logs 
                WHERE employee_id = ? 
                AND access_type = 'generate'
                AND output_format = 'pdf'
                AND created_at >= FROM_UNIXTIME(?)
            ");
            $stmt->execute([$userId, $oneDayAgo]);
            $dailyCount = $stmt->fetch(PDO::FETCH_ASSOC)['daily_count'];
            
            if ($dailyCount >= 50) {
                return false;
            }
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Medical Print Security - Rate Limit Error: " . $e->getMessage());
            // On error, allow the request but log it
            return true;
        }
    }
    
    /**
     * Log audit action for medical record access
     * @param int $userId Employee ID
     * @param int $patientId Patient ID
     * @param string $action Action performed
     * @param array $metadata Additional metadata
     */
    public function logAuditAction($userId, $patientId, $action, $metadata = []) {
        try {
            // Get user information
            $user = $this->currentUser();
            
            // Prepare audit data
            $auditData = [
                'employee_id' => $userId,
                'patient_id' => $patientId,
                'action' => $action,
                'ip_address' => $this->getClientIpAddress(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                'session_id' => session_id(),
                'role' => $user['role'] ?? 'Unknown',
                'metadata' => json_encode($metadata),
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // Insert into audit log
            $stmt = $this->pdo->prepare("
                INSERT INTO medical_record_audit_log 
                (employee_id, patient_id, action, ip_address, user_agent, session_id, role, metadata, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $auditData['employee_id'],
                $auditData['patient_id'],
                $auditData['action'],
                $auditData['ip_address'],
                $auditData['user_agent'],
                $auditData['session_id'],
                $auditData['role'],
                $auditData['metadata'],
                $auditData['created_at']
            ]);
            
            // Also log to access logs table for backward compatibility
            if (in_array($action, ['preview', 'generate', 'download'])) {
                $outputFormat = $metadata['output_format'] ?? 'html';
                $sections = $metadata['sections'] ?? [];
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO medical_record_access_logs 
                    (patient_id, employee_id, access_type, sections_accessed, output_format, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $patientId,
                    $userId,
                    $action,
                    json_encode($sections),
                    $outputFormat
                ]);
            }
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Medical Print Security - Audit Log Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get client IP address
     * @return string Client IP address
     */
    private function getClientIpAddress() {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Validate request origin and referrer
     * @return bool Whether request is from valid origin
     */
    public function validateRequestOrigin() {
        $allowedOrigins = [
            'localhost',
            '127.0.0.1',
            $_SERVER['SERVER_NAME'] ?? ''
        ];
        
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        
        if (empty($origin) && empty($referer)) {
            return true; // Allow direct API calls
        }
        
        if (!empty($origin)) {
            $originHost = parse_url($origin, PHP_URL_HOST);
            return in_array($originHost, $allowedOrigins);
        }
        
        if (!empty($referer)) {
            $refererHost = parse_url($referer, PHP_URL_HOST);
            return in_array($refererHost, $allowedOrigins);
        }
        
        return false;
    }
    
    /**
     * Check if user has specific permission for medical records
     * @param int $userId Employee ID
     * @param string $permission Permission type (view, print, export)
     * @return bool Permission status
     */
    public function hasPermission($userId, $permission) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT r.role_name as role 
                FROM employees e
                JOIN roles r ON e.role_id = r.role_id
                WHERE e.employee_id = ? AND e.status = 'active'
            ");
            $stmt->execute([$userId]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$employee) {
                return false;
            }
            
            $role = $employee['role'];
            
            // Define permissions by role
            $permissions = [
                'admin' => ['view', 'print', 'export', 'audit'],
                'doctor' => ['view', 'print', 'export'],
                'nurse' => ['view', 'print'],
                'dho' => ['view', 'print', 'export'],
                'bhw' => ['view', 'print'],
                'records_officer' => ['view', 'print', 'export', 'audit'],
                'laboratory_tech' => ['view'],
                'pharmacist' => ['view'],
                'cashier' => ['view']
            ];
            
            return in_array($permission, $permissions[$role] ?? []);
            
        } catch (PDOException $e) {
            error_log("Medical Print Security - Permission Check Error: " . $e->getMessage());
            return false;
        }
    }
}

// Convenience functions for backward compatibility
function currentUser() {
    $security = new MedicalPrintSecurity();
    return $security->currentUser();
}

function userCanAccessPatient($userId, $patientId) {
    $security = new MedicalPrintSecurity();
    return $security->userCanAccessPatient($userId, $patientId);
}

function validateCsrfToken($token) {
    $security = new MedicalPrintSecurity();
    return $security->validateCsrfToken($token);
}

function logAuditAction($userId, $patientId, $action, $metadata = []) {
    $security = new MedicalPrintSecurity();
    return $security->logAuditAction($userId, $patientId, $action, $metadata);
}

function generateCsrfToken() {
    $security = new MedicalPrintSecurity();
    return $security->generateCsrfToken();
}

function checkMedicalPrintRateLimit($userId) {
    $security = new MedicalPrintSecurity();
    return $security->checkRateLimit($userId);
}

function hasMedicalRecordPermission($userId, $permission) {
    $security = new MedicalPrintSecurity();
    return $security->hasPermission($userId, $permission);
}
?>