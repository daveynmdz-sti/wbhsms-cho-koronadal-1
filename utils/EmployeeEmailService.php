<?php
/**
 * Employee Email Service
 * Handles email notifications for employee management
 * Author: GitHub Copilot
 */

require_once dirname(__DIR__) . '/config/email.php';
require_once __DIR__ . '/StandardEmailTemplate.php';

class EmployeeEmailService {
    
    /**
     * Send welcome email to newly created employee
     * @param array $employee_data Employee information
     * @return array Result with success status and message
     */
    public static function sendWelcomeEmail($employee_data) {
        try {
            // Validate required data
            $required_fields = ['email', 'first_name', 'last_name', 'employee_number', 'default_password'];
            foreach ($required_fields as $field) {
                if (empty($employee_data[$field])) {
                    return [
                        'success' => false, 
                        'message' => "Missing required field: {$field}"
                    ];
                }
            }

            // Prepare email data
            $email_data = [
                'employee_name' => trim($employee_data['first_name'] . ' ' . ($employee_data['middle_name'] ?? '') . ' ' . $employee_data['last_name']),
                'first_name' => $employee_data['first_name'],
                'employee_number' => $employee_data['employee_number'],
                'default_password' => $employee_data['default_password'],
                'role_name' => $employee_data['role_name'] ?? 'Staff',
                'facility_name' => $employee_data['facility_name'] ?? 'CHO Koronadal',
                'login_url' => 'http://cityhealthofficeofkoronadal.31.97.106.60.sslip.io/pages/management/auth/employee_login.php',
                'contact_num' => $employee_data['contact_num'] ?? '',
                'system_url' => 'http://cityhealthofficeofkoronadal.31.97.106.60.sslip.io'
            ];

            // Generate email template
            $template = self::getWelcomeEmailTemplate($email_data);

            // Send email
            $result = sendEmail(
                $employee_data['email'],
                $email_data['employee_name'],
                $template['subject'],
                $template['html_body'],
                $template['text_body']
            );

            if ($result['success']) {
                error_log("Welcome email sent successfully to: " . $employee_data['email'] . " for employee: " . $employee_data['employee_number']);
            } else {
                error_log("Failed to send welcome email to: " . $employee_data['email'] . " - " . $result['message']);
            }

            return $result;

        } catch (Exception $e) {
            error_log("EmployeeEmailService error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send welcome email: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate welcome email template for new employees
     * @param array $data Email template data
     * @return array Template with subject, html_body, and text_body
     */
    private static function getWelcomeEmailTemplate($data) {
        $system_info = EmailConfig::getSystemInfo();
        
        // Prepare data for standardized template
        $template_data = array_merge($data, [
            'contact_phone' => $system_info['contact_phone'],
            'contact_email' => $system_info['contact_email'],
            'contact_address' => $system_info['facility_address']
        ]);
        
        // Generate content using standardized template
        $content = StandardEmailTemplate::generateWelcomeContent($template_data);
        
        // Generate complete email using standardized template
        $html_body = StandardEmailTemplate::generateTemplate([
            'title' => 'Welcome to CHO Koronadal!',
            'subtitle' => 'Healthcare Management System',
            'content' => $content,
            'type' => 'welcome'
        ]);
        
        return [
            'subject' => 'Welcome to CHO Koronadal - Your Employee Account Details',
            'html_body' => $html_body,
            'text_body' => self::buildWelcomeTextTemplate($data, $system_info)
        ];
    }

    /**
     * Build plain text email template for employee welcome
     * @param array $data Email data
     * @param array $system_info System information
     * @return string Plain text email template
     */
    private static function buildWelcomeTextTemplate($data, $system_info) {
        $current_year = date('Y');
        
        return "WELCOME TO CHO KORONADAL - EMPLOYEE ACCOUNT CREATED

Dear {$data['first_name']},

Congratulations! Your employee account has been successfully created in the CHO Koronadal Healthcare Management System.

YOUR LOGIN CREDENTIALS:
======================
Employee Number: {$data['employee_number']}
Temporary Password: {$data['default_password']}

IMPORTANT SECURITY NOTICE:
=========================
- You MUST change this password when you first log in
- Choose a strong password with uppercase, lowercase, numbers, and special characters
- Never share your credentials with anyone
- Keep your login information secure

EMPLOYMENT INFORMATION:
======================
Full Name: {$data['employee_name']}
Position: {$data['role_name']}
Department: {$data['facility_name']}" . 
(!empty($data['contact_num']) ? "\nContact: {$data['contact_num']}" : "") . "

ACCESS YOUR ACCOUNT:
===================
Login URL: {$data['login_url']}

Please bookmark this URL for easy access to the employee portal.

SECURITY BEST PRACTICES:
=======================
- Always log out when finished using the system
- Never share your login credentials with others
- Use a strong, unique password for your account
- Report any suspicious activity immediately
- Access the system only from trusted devices

NEED HELP?
==========
Phone: {$system_info['contact_phone']}
Email: {$system_info['contact_email']}
Address: {$system_info['facility_address']}

Our IT support team is available during business hours to assist you with any technical issues or questions about using the system.

Welcome to the CHO Koronadal family! We're excited to have you join our mission of providing quality healthcare services to our community.

---
City Health Office - Koronadal
{$system_info['system_name']}
This is an automated message. Please do not reply to this email.
© {$current_year} City Health Office - Koronadal. All rights reserved.";
    }

    /**
     * Send password reset email to employee
     * @param array $employee_data Employee information
     * @return array Result with success status and message
     */
    public static function sendPasswordResetEmail($employee_data) {
        try {
            // Validate required data
            $required_fields = ['email', 'first_name', 'last_name', 'employee_number', 'reset_token'];
            foreach ($required_fields as $field) {
                if (empty($employee_data[$field])) {
                    return [
                        'success' => false, 
                        'message' => "Missing required field: {$field}"
                    ];
                }
            }

            // Prepare email data
            $email_data = [
                'employee_name' => trim($employee_data['first_name'] . ' ' . ($employee_data['middle_name'] ?? '') . ' ' . $employee_data['last_name']),
                'first_name' => $employee_data['first_name'],
                'employee_number' => $employee_data['employee_number'],
                'reset_token' => $employee_data['reset_token'],
                'reset_url' => 'http://cityhealthofficeofkoronadal.31.97.106.60.sslip.io/pages/management/auth/reset_password.php?token=' . $employee_data['reset_token'],
                'expiry_hours' => $employee_data['expiry_hours'] ?? 24,
                'system_url' => 'http://cityhealthofficeofkoronadal.31.97.106.60.sslip.io'
            ];

            // Generate email template
            $template = self::getPasswordResetEmailTemplate($email_data);

            // Send email
            $result = sendEmail(
                $employee_data['email'],
                $email_data['employee_name'],
                $template['subject'],
                $template['html_body'],
                $template['text_body']
            );

            if ($result['success']) {
                error_log("Password reset email sent successfully to: " . $employee_data['email'] . " for employee: " . $employee_data['employee_number']);
            } else {
                error_log("Failed to send password reset email to: " . $employee_data['email'] . " - " . $result['message']);
            }

            return $result;

        } catch (Exception $e) {
            error_log("EmployeeEmailService password reset error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send password reset email: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate password reset email template
     * @param array $data Email template data
     * @return array Template with subject, html_body, and text_body
     */
    private static function getPasswordResetEmailTemplate($data) {
        $system_info = EmailConfig::getSystemInfo();
        
        // Generate content using standardized template
        $content = "
            <p style='font-size: 18px; margin-bottom: 20px;'>
                Hello <strong>{$data['first_name']}</strong>,
            </p>
            
            <p>We received a request to reset the password for your employee account (<strong>{$data['employee_number']}</strong>). If you made this request, click the button below to reset your password:</p>
            
            <div class='warning-card'>
                <h3 style='margin: 0 0 15px 0;'>🔒 Password Reset Request</h3>
                <p style='margin: 0 0 15px 0;'>This link will expire in {$data['expiry_hours']} hours for security purposes.</p>
                
                <div style='text-align: center; margin: 20px 0;'>
                    <a href='{$data['reset_url']}' class='button'>
                        Reset Your Password
                    </a>
                </div>
                
                <p style='font-size: 12px; color: #92400e; margin: 15px 0 0 0; word-break: break-all;'>
                    Direct Link: {$data['reset_url']}
                </p>
            </div>
            
            <div class='info-card'>
                <h3 style='color: #0c4a6e; margin: 0 0 10px 0;'>⚠️ Important Security Notice</h3>
                <p style='color: #0c4a6e; margin: 0;'>
                    If you did not request this password reset, please ignore this email and contact our IT support immediately. Your account security is important to us.
                </p>
            </div>
            
            <div class='contact-info'>
                <h3 style='color: #374151; margin-bottom: 15px;'>🆘 Need Help?</h3>
                <div class='contact-item'>
                    <strong>Phone:</strong> {$system_info['contact_phone']}
                </div>
                <div class='contact-item'>
                    <strong>Email:</strong> {$system_info['contact_email']}
                </div>
                <p style='margin-top: 15px; font-size: 14px; color: #6b7280;'>
                    For security questions or assistance, please contact our support team.
                </p>
            </div>
        ";
        
        // Generate complete email using standardized template
        $html_body = StandardEmailTemplate::generateTemplate([
            'title' => 'Password Reset Request',
            'subtitle' => 'CHO Koronadal Employee Portal',
            'content' => $content,
            'type' => 'password_reset'
        ]);
        
        return [
            'subject' => 'Password Reset Request - CHO Koronadal Employee Portal',
            'html_body' => $html_body,
            'text_body' => self::buildPasswordResetTextTemplate($data, $system_info)
        ];
    }

    /**
     * Build plain text template for password reset email
     * @param array $data Email data
     * @param array $system_info System information
     * @return string Plain text email template
     */
    private static function buildPasswordResetTextTemplate($data, $system_info) {
        $current_year = date('Y');
        
        return "PASSWORD RESET REQUEST - CHO KORONADAL

Hello {$data['first_name']},

We received a request to reset the password for your employee account ({$data['employee_number']}).

If you made this request, use the link below to reset your password:
{$data['reset_url']}

This link will expire in {$data['expiry_hours']} hours for security purposes.

IMPORTANT SECURITY NOTICE:
If you did not request this password reset, please ignore this email and contact our IT support immediately.

NEED HELP?
Phone: {$system_info['contact_phone']}
Email: {$system_info['contact_email']}

---
City Health Office - Koronadal
© {$current_year} CHO Koronadal. All rights reserved.";
    }
}
?>