<?php
/**
 * Standardized Email Template Service
 * Provides consistent email design across all system emails
 * Author: GitHub Copilot
 */

class StandardEmailTemplate {
    
    /**
     * Generate standardized email template with CHO Koronadal branding
     * @param array $config Template configuration
     * @return string Complete HTML email template
     */
    public static function generateTemplate($config) {
        // Validate required fields
        $required = ['title', 'content', 'type'];
        foreach ($required as $field) {
            if (empty($config[$field])) {
                throw new Exception("Required field '{$field}' is missing in email template config");
            }
        }

        // Set defaults
        $defaults = [
            'logo_url' => self::getLogoUrl(),
            'system_name' => 'City Health Office of Koronadal',
            'subtitle' => 'Healthcare Management System',
            'footer_text' => 'This is an automated message. Please do not reply to this email.',
            'current_year' => date('Y'),
            'show_logo' => true,
            'show_header_icon' => true,
            'contact_info' => self::getContactInfo()
        ];

        $config = array_merge($defaults, $config);

        // Set type-specific defaults
        switch ($config['type']) {
            case 'welcome':
                $config['header_icon'] = $config['header_icon'] ?? '🎉';
                $config['header_color'] = $config['header_color'] ?? 'linear-gradient(135deg, #0077b6, #023e8a)';
                break;
            case 'otp':
                $config['header_icon'] = $config['header_icon'] ?? '🔐';
                $config['header_color'] = $config['header_color'] ?? 'linear-gradient(135deg, #0077b6, #023e8a)';
                break;
            case 'password_reset':
                $config['header_icon'] = $config['header_icon'] ?? '🔒';
                $config['header_color'] = $config['header_color'] ?? 'linear-gradient(135deg, #0077b6, #023e8a)';
                break;
            case 'appointment':
                $config['header_icon'] = $config['header_icon'] ?? '🏥';
                $config['header_color'] = $config['header_color'] ?? 'linear-gradient(135deg, #0077b6, #023e8a)';
                break;
            default:
                $config['header_icon'] = $config['header_icon'] ?? '📧';
                $config['header_color'] = $config['header_color'] ?? 'linear-gradient(135deg, #0077b6, #023e8a)';
        }

        return self::buildHTMLTemplate($config);
    }

    /**
     * Get logo URL for email templates
     * @return string Logo URL
     */
    private static function getLogoUrl() {
        // Return just the primary ImageKit CDN URL
        return 'https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128';
    }
    
    /**
     * Get fallback logo URL
     * @return string Fallback logo URL
     */
    private static function getFallbackLogoUrl() {
        // Backup: Use production URL for local assets
        $base_url = $_ENV['SYSTEM_URL'] ?? 'http://cityhealthofficeofkoronadal.31.97.106.60.sslip.io';
        return $base_url . '/assets/images/Nav_LogoClosed.png';
    }

    /**
     * Get contact information for emails
     * @return array Contact information
     */
    private static function getContactInfo() {
        return [
            'phone' => $_ENV['CONTACT_PHONE'] ?? '(083) 228-8042',
            'email' => $_ENV['CONTACT_EMAIL'] ?? 'info@chokoronadal.gov.ph',
            'address' => $_ENV['FACILITY_ADDRESS'] ?? 'Koronadal City, South Cotabato'
        ];
    }

    /**
     * Build the complete HTML email template
     * @param array $config Template configuration
     * @return string HTML email template
     */
    private static function buildHTMLTemplate($config) {
        $fallback_logo_url = self::getFallbackLogoUrl();
        $logo_html = $config['show_logo'] ? 
            "<img src='{$config['logo_url']}' onerror=\"this.onerror=null; this.src='{$fallback_logo_url}'\" alt='CHO Koronadal Logo' style='max-width: 80px; height: auto; background: rgba(255, 255, 255, 0.2); padding: 15px; border-radius: 50%; margin-bottom: 20px; backdrop-filter: blur(10px); border: 2px solid rgba(255, 255, 255, 0.3);'>" 
            : '';

        $header_icon = $config['show_header_icon'] ? $config['header_icon'] . ' ' : '';

        return "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>{$config['title']}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            margin: 0; 
            padding: 0; 
            font-family: 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, sans-serif; 
            line-height: 1.6; 
            color: #1f2937; 
            background-color: #f9fafb; 
        }
        
        .email-container { 
            max-width: 600px; 
            margin: 20px auto; 
            background: white; 
            border-radius: 16px; 
            overflow: hidden; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.1); 
            border: 1px solid #e5e7eb;
        }
        
        .header { 
            background: {$config['header_color']}; 
            color: white; 
            padding: 30px 20px; 
            text-align: center; 
            position: relative;
            overflow: hidden;
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: url('data:image/svg+xml,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 100 100\"><circle cx=\"50\" cy=\"50\" r=\"2\" fill=\"%23ffffff\" opacity=\"0.1\"/><circle cx=\"20\" cy=\"20\" r=\"1\" fill=\"%23ffffff\" opacity=\"0.1\"/><circle cx=\"80\" cy=\"30\" r=\"1.5\" fill=\"%23ffffff\" opacity=\"0.1\"/><circle cx=\"30\" cy=\"80\" r=\"1\" fill=\"%23ffffff\" opacity=\"0.1\"/><circle cx=\"70\" cy=\"70\" r=\"2\" fill=\"%23ffffff\" opacity=\"0.1\"/></svg>') repeat;
            animation: float 20s infinite linear;
        }
        
        @keyframes float {
            0% { transform: translate(-50%, -50%) rotate(0deg); }
            100% { transform: translate(-50%, -50%) rotate(360deg); }
        }
        
        .header-content {
            position: relative;
            z-index: 2;
        }
        
        .header h1 { 
            margin: 0 0 10px 0; 
            font-size: 28px; 
            font-weight: 700; 
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .header p { 
            margin: 0; 
            font-size: 16px; 
            opacity: 0.95; 
            font-weight: 400;
        }
        
        .content { 
            padding: 40px 30px; 
        }
        
        .content h2 {
            color: #1f2937;
            margin-bottom: 20px;
            font-size: 22px;
            font-weight: 600;
        }
        
        .content h3 {
            color: #374151;
            margin-bottom: 15px;
            font-size: 18px;
            font-weight: 600;
        }
        
        .content p {
            margin-bottom: 15px;
            color: #4b5563;
            font-size: 16px;
            line-height: 1.6;
        }
        
        .highlight-card { 
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 12px; 
            padding: 25px; 
            margin: 25px 0; 
            border: 2px solid #e2e8f0;
            position: relative;
            overflow: hidden;
        }
        
        .highlight-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #0077b6, #023e8a);
        }
        
        .info-card {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            color: #0c4a6e;
        }
        
        .warning-card {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            color: #92400e;
        }
        
        .success-card {
            background: #f0fdf4;
            border: 1px solid #22c55e;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            color: #166534;
        }
        
        .button {
            display: inline-block;
            background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
            color: white;
            padding: 14px 28px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 16px;
            box-shadow: 0 4px 12px rgba(0, 119, 182, 0.3);
            transition: all 0.3s ease;
            text-align: center;
            margin: 10px 0;
        }
        
        .button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 119, 182, 0.4);
        }
        
        .footer { 
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 30px; 
            text-align: center; 
            border-top: 1px solid #e2e8f0; 
        }
        
        .footer-logo {
            margin-bottom: 15px;
        }
        
        .footer-logo img {
            max-width: 50px;
            height: auto;
            opacity: 0.7;
        }
        
        .footer p { 
            margin: 8px 0; 
            color: #6b7280; 
            font-size: 14px; 
        }
        
        .footer strong {
            color: #374151;
        }
        
        .contact-info {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            border: 1px solid #e2e8f0;
        }
        
        .contact-item {
            margin: 8px 0;
            color: #374151;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .contact-item strong {
            color: #0077b6;
            min-width: 70px;
        }
        
        @media only screen and (max-width: 600px) {
            .email-container { 
                margin: 10px; 
                border-radius: 12px; 
            }
            
            .header { 
                padding: 25px 15px; 
            }
            
            .header h1 {
                font-size: 24px;
            }
            
            .content { 
                padding: 25px 20px; 
            }
            
            .highlight-card,
            .info-card,
            .warning-card,
            .success-card,
            .contact-info {
                padding: 15px;
                margin: 15px 0;
            }
            
            .button {
                padding: 12px 20px;
                font-size: 15px;
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class='email-container'>
        <div class='header'>
            <div class='header-content'>
                {$logo_html}
                <h1>{$header_icon}{$config['title']}</h1>
                <p>{$config['subtitle']}</p>
            </div>
        </div>
        
        <div class='content'>
            {$config['content']}
        </div>
        
        <div class='footer'>
            <div class='footer-logo'>
                <img src='{$config['logo_url']}' onerror=\"this.onerror=null; this.src='{$fallback_logo_url}'\" alt='CHO Koronadal Logo'>
            </div>
            <p><strong>{$config['system_name']}</strong></p>
            <p>{$config['footer_text']}</p>
            <p>© {$config['current_year']} City Health Office - Koronadal. All rights reserved.</p>
        </div>
    </div>
</body>
</html>";
    }

    /**
     * Generate OTP email content
     * @param array $data OTP email data
     * @return string Email content HTML
     */
    public static function generateOTPContent($data) {
        $greeting = !empty($data['first_name']) ? 
            "Hello <strong>" . htmlspecialchars($data['first_name']) . "</strong>!" : 
            "Hello!";

        $purpose = $data['purpose'] ?? 'verification';
        $expiry_minutes = $data['expiry_minutes'] ?? 5;

        return "
            <p style='font-size: 18px; margin-bottom: 20px;'>{$greeting}</p>
            
            <p>You requested a One-Time Password (OTP) for {$purpose}. Please use the code below to proceed:</p>
            
            <div class='highlight-card' style='text-align: center;'>
                <h2 style='color: #0077b6; font-size: 36px; letter-spacing: 8px; margin: 0; font-family: monospace;'>{$data['otp']}</h2>
            </div>
            
            <div class='warning-card'>
                <h3 style='margin: 0 0 10px 0; color: #92400e;'>⏰ Important Security Notice</h3>
                <ul style='margin: 10px 0; padding-left: 20px; color: #92400e;'>
                    <li><strong>This code will expire in {$expiry_minutes} minutes</strong></li>
                    <li>Never share this code with anyone</li>
                    <li>Our staff will never ask for this code</li>
                    <li>If you didn't request this code, please ignore this email</li>
                </ul>
            </div>
            
            <div class='info-card'>
                <p style='margin: 0; color: #0c4a6e;'>
                    <strong>Need Help?</strong> If you're having trouble, please contact our support team using the information below.
                </p>
            </div>
            
            <div class='contact-info'>
                <h3 style='color: #374151; margin-bottom: 15px;'>📞 Support Contact</h3>
                <div class='contact-item'>
                    <strong>Phone:</strong> {$data['contact_phone']}
                </div>
                <div class='contact-item'>
                    <strong>Email:</strong> {$data['contact_email']}
                </div>
            </div>
        ";
    }

    /**
     * Generate welcome email content for employees
     * @param array $data Employee welcome data
     * @return string Email content HTML
     */
    public static function generateWelcomeContent($data) {
        return "
            <p style='font-size: 18px; margin-bottom: 20px;'>
                Hello <strong>{$data['first_name']}</strong>! 👋
            </p>
            
            <p>Congratulations! Your employee account has been successfully created in the City Health Office of Koronadal Healthcare Services Management System. You are now part of our dedicated healthcare team committed to serving the community of Koronadal.</p>
            
            <div class='highlight-card'>
                <h3 style='color: #1e293b; margin-bottom: 20px;'>🔑 Your Login Credentials</h3>
                
                <div style='background: white; border-radius: 8px; padding: 15px; margin: 10px 0; border: 1px solid #e2e8f0;'>
                    <div style='font-weight: 600; color: #374151; font-size: 14px; margin-bottom: 8px;'>👤 Employee Number</div>
                    <div style='font-size: 18px; font-weight: 700; color: #1f2937; font-family: monospace; background: #f8fafc; padding: 10px; border-radius: 6px;'>{$data['employee_number']}</div>
                </div>
                
                <div style='background: white; border-radius: 8px; padding: 15px; margin: 10px 0; border: 1px solid #e2e8f0;'>
                    <div style='font-weight: 600; color: #374151; font-size: 14px; margin-bottom: 8px;'>🔒 Temporary Password</div>
                    <div style='font-size: 18px; font-weight: 700; color: #1f2937; font-family: monospace; background: #f8fafc; padding: 10px; border-radius: 6px;'>{$data['default_password']}</div>
                </div>
            </div>
            
            <div class='warning-card'>
                <h3 style='margin: 0 0 10px 0;'>🔐 Important Security Notice</h3>
                <p style='margin: 0;'><strong>You will be required to change this password when you first log in.</strong> Please choose a strong password that includes uppercase letters, lowercase letters, numbers, and special characters.</p>
            </div>
            
            <div class='success-card'>
                <h3 style='color: #166534; margin-bottom: 15px;'>👔 Employment Information</h3>
                <div style='display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #dcfce7;'>
                    <span style='font-weight: 600; color: #166534;'>Full Name:</span>
                    <span style='color: #15803d;'>{$data['employee_name']}</span>
                </div>
                <div style='display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #dcfce7;'>
                    <span style='font-weight: 600; color: #166534;'>Position:</span>
                    <span style='color: #15803d;'>{$data['role_name']}</span>
                </div>
                <div style='display: flex; justify-content: space-between; padding: 8px 0;'>
                    <span style='font-weight: 600; color: #166534;'>Department:</span>
                    <span style='color: #15803d;'>{$data['facility_name']}</span>
                </div>
            </div>
            
            <div style='text-align: center; margin: 30px 0;'>
                <h3 style='color: #0077b6; margin-bottom: 20px;'>🚀 Access Your Account</h3>
                <p style='margin-bottom: 20px; color: #4b5563;'>Click the button below to access the employee portal:</p>
                
                <a href='{$data['login_url']}' class='button'>
                    Login to Employee Portal
                </a>
                
                <p style='font-size: 14px; color: #6b7280; margin-top: 15px; word-break: break-all;'>
                    Direct Link: {$data['login_url']}
                </p>
            </div>
            
            <div class='info-card'>
                <h3 style='color: #0c4a6e; margin-bottom: 15px;'>🛡️ Security Best Practices</h3>
                <ul style='margin: 10px 0; padding-left: 20px; color: #0c4a6e;'>
                    <li>Always log out when finished using the system</li>
                    <li>Never share your login credentials with others</li>
                    <li>Use a strong, unique password for your account</li>
                    <li>Report any suspicious activity immediately</li>
                    <li>Access the system only from trusted devices</li>
                </ul>
            </div>
            
            <div class='contact-info'>
                <h3 style='color: #374151; margin-bottom: 15px;'>🆘 Need Help?</h3>
                <div class='contact-item'>
                    <strong>Phone:</strong> {$data['contact_phone']}
                </div>
                <div class='contact-item'>
                    <strong>Email:</strong> {$data['contact_email']}
                </div>
                <div class='contact-item'>
                    <strong>Address:</strong> {$data['contact_address']}
                </div>
                <p style='margin-top: 15px; font-size: 14px; color: #6b7280;'>
                    Our IT support team is available during business hours to assist you with any technical issues.
                </p>
            </div>
            
            <div style='margin-top: 30px; padding: 20px; background: #f0f9ff; border-radius: 12px; border: 1px solid #bae6fd; text-align: center;'>
                <p style='margin: 0; font-size: 16px; color: #0c4a6e; font-weight: 600;'>
                    Welcome to the CHO Koronadal family! 🏥<br>
                    We're excited to have you join our mission of providing quality healthcare services to our community.
                </p>
            </div>
        ";
    }

    /**
     * Generate welcome email content for patients
     * @param array $data Patient welcome data
     * @return string Email content HTML
     */
    public static function generatePatientWelcomeContent($data) {
        return "
            <p style='font-size: 18px; margin-bottom: 20px;'>
                Hello <strong>{$data['patient_name']}</strong>! 🎉
            </p>
            
            <p>Welcome to the City Health Office of Koronadal Healthcare Services Management System! Your patient account has been successfully created. We're excited to provide you with quality healthcare services and easy access to manage your health records.</p>
            
            <div class='highlight-card'>
                <h3 style='color: #1e293b; margin-bottom: 20px;'>👤 Your Patient Information</h3>
                
                <div style='background: white; border-radius: 8px; padding: 15px; margin: 10px 0; border: 1px solid #e2e8f0;'>
                    <div style='font-weight: 600; color: #374151; font-size: 14px; margin-bottom: 8px;'>🆔 Patient ID</div>
                    <div style='font-size: 18px; font-weight: 700; color: #1f2937; font-family: monospace; background: #f8fafc; padding: 10px; border-radius: 6px;'>{$data['patient_id']}</div>
                    <div style='font-size: 12px; color: #6b7280; margin-top: 5px;'>
                        <strong>Important:</strong> This is your Patient ID - please save it for future logins!
                    </div>
                </div>
            </div>
            
            <div class='warning-card'>
                <h3 style='margin: 0 0 10px 0;'>📋 Complete Your Profile</h3>
                <p style='margin: 0;'><strong>Next Step:</strong> Please log in to your patient portal and complete your medical profile. This includes updating your medical history, emergency contacts, and any allergies or medications you're currently taking. A complete profile helps us provide better healthcare services.</p>
            </div>
            
            <div class='info-card'>
                <h3 style='color: #374151; margin-bottom: 15px;'>🏥 What You Can Do</h3>
                <ul style='margin: 10px 0; padding-left: 20px; color: #4b5563;'>
                    <li><strong>Book Appointments:</strong> Schedule consultations with our healthcare providers</li>
                    <li><strong>View Medical Records:</strong> Access your consultation history and lab results</li>
                    <li><strong>Manage Referrals:</strong> Track referrals to specialist services</li>
                    <li><strong>Update Profile:</strong> Keep your contact and medical information current</li>
                    <li><strong>Download Reports:</strong> Get copies of your medical documents</li>
                </ul>
            </div>
            
            <div style='text-align: center; margin: 25px 0;'>
                <a href='{$data['login_url']}' style='display: inline-block; background: #0077b6; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;'>Login to Patient Portal</a>
            </div>
            
            <div class='contact-info'>
                <h3 style='color: #374151; margin-bottom: 15px;'>📞 Need Help?</h3>
                <div class='contact-item'>
                    <strong>Phone:</strong> {$data['contact_phone']}
                </div>
                <div class='contact-item'>
                    <strong>Email:</strong> {$data['contact_email']}
                </div>
                <p style='margin: 10px 0 0 0; font-size: 14px; color: #6c757d;'>
                    Our support team is here to help you with any questions about using the patient portal.
                </p>
            </div>
        ";
    }
}
?>