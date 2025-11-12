<?php
/**
 * Referral Email Utility Functions
 * Handles sending email notifications for referrals
 */

// Include PHPMailer and email configuration
require_once dirname(__DIR__) . '/config/email.php';
require_once dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
require_once dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/SMTP.php';
require_once dirname(__DIR__) . '/vendor/phpmailer/phpmailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/**
 * Send referral confirmation email to patient
 * 
 * @param array $patient_info Patient information including name and email
 * @param string $referral_num Referral number (e.g., REF-20241111-0001)
 * @param array $referral_details Referral details including reason, destination, etc.
 * @param array $qr_result QR code generation result (optional)
 * @return array Result with success status and message
 */
function sendReferralConfirmationEmail($patient_info, $referral_num, $referral_details, $qr_result = null) {
    try {
        // Validate required fields
        if (empty($patient_info['email'])) {
            return ['success' => false, 'message' => 'Patient email address not available'];
        }

        if (empty($referral_num) || empty($referral_details)) {
            return ['success' => false, 'message' => 'Missing required referral information'];
        }

        // Build patient name
        $patient_name = trim(
            $patient_info['first_name'] . ' ' . 
            ($patient_info['middle_name'] ?? '') . ' ' . 
            $patient_info['last_name']
        );
        $patient_name = preg_replace('/\s+/', ' ', $patient_name); // Clean up extra spaces

        // Format destination facility name
        $destination_name = '';
        if (!empty($referral_details['external_facility_name'])) {
            $destination_name = $referral_details['external_facility_name'];
        } elseif (!empty($referral_details['facility_name'])) {
            $destination_name = $referral_details['facility_name'];
        } else {
            $destination_name = 'Specified Healthcare Facility';
        }

        // Format appointment information if available
        $appointment_info = '';
        $appointment_info_html = '';
        $has_appointment = false;

        if (!empty($referral_details['scheduled_date'])) {
            $has_appointment = true;
            $formatted_date = date('F j, Y (l)', strtotime($referral_details['scheduled_date']));
            $formatted_time = 'Not specified';
            
            if (!empty($referral_details['scheduled_time'])) {
                $formatted_time = date('g:i A', strtotime($referral_details['scheduled_time']));
            }

            $appointment_info = "\nAppointment Date: {$formatted_date}";
            if ($formatted_time !== 'Not specified') {
                $appointment_info .= "\nAppointment Time: {$formatted_time}";
            }

            $appointment_info_html = '
                        <tr style="border-bottom: 1px solid #e9ecef;">
                            <td style="padding: 12px 0; font-weight: 600; color: #0077b6;">Appointment Date:</td>
                            <td style="padding: 12px 0; color: #333;">' . htmlspecialchars($formatted_date, ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>';
            
            if ($formatted_time !== 'Not specified') {
                $appointment_info_html .= '
                        <tr style="border-bottom: 1px solid #e9ecef;">
                            <td style="padding: 12px 0; font-weight: 600; color: #0077b6;">Appointment Time:</td>
                            <td style="padding: 12px 0; color: #333;">' . htmlspecialchars($formatted_time, ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>';
            }
        }

        // Format doctor information if available
        $doctor_info = '';
        $doctor_info_html = '';
        if (!empty($referral_details['doctor_name'])) {
            $doctor_info = "\nAssigned Doctor: Dr. {$referral_details['doctor_name']}";
            $doctor_info_html = '
                        <tr style="border-bottom: 1px solid #e9ecef;">
                            <td style="padding: 12px 0; font-weight: 600; color: #0077b6;">Assigned Doctor:</td>
                            <td style="padding: 12px 0; color: #333;">Dr. ' . htmlspecialchars($referral_details['doctor_name'], ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>';
        }

        // Format service information
        $service_info = '';
        $service_info_html = '';
        if (!empty($referral_details['service_name'])) {
            $service_info = "\nService Type: {$referral_details['service_name']}";
            $service_info_html = '
                        <tr style="border-bottom: 1px solid #e9ecef;">
                            <td style="padding: 12px 0; font-weight: 600; color: #0077b6;">Service Type:</td>
                            <td style="padding: 12px 0; color: #333;">' . htmlspecialchars($referral_details['service_name'], ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>';
        }

        // QR Code information
        $qr_info_html = '';
        $qr_info_text = '';
        $qr_image_cid = '';
        $qr_verification_code = '';

        if ($qr_result && $qr_result['success']) {
            $qr_verification_code = $qr_result['verification_code'] ?? 'N/A';
            
            // Create a unique CID for this email
            $qr_image_cid = 'qr_code_ref_' . uniqid();
            
            $qr_info_html = '
                        <tr style="border-bottom: 1px solid #e9ecef;">
                            <td style="padding: 12px 0; font-weight: 600; color: #0077b6;">QR Code:</td>
                            <td style="padding: 12px 0; text-align: center;">
                                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border: 2px solid #28a745;">
                                    <img src="cid:' . $qr_image_cid . '" alt="QR Code for Referral" style="max-width: 150px; height: auto; display: block; margin: 0 auto;"/>
                                    <p style="margin: 10px 0 0 0; font-size: 12px; color: #666;">Present this QR code at the destination facility</p>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td style="padding: 12px 0; font-weight: 600; color: #0077b6;">Verification Code:</td>
                            <td style="padding: 12px 0; color: #333; font-family: monospace; background: #f8f9fa; padding: 8px; border-radius: 4px;">' . htmlspecialchars($qr_verification_code, ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>';
            
            $qr_info_text = "\nQR Code: Available for quick referral processing\nVerification Code: {$qr_verification_code}";
        }

        // Check for development mode bypass
        $bypassEmail = empty(getenv('SMTP_PASS')) || getenv('SMTP_PASS') === 'disabled';
        
        if ($bypassEmail) {
            error_log("DEVELOPMENT MODE: Referral confirmation email for {$patient_info['email']} - {$referral_num}");
            return ['success' => false, 'message' => 'DEVELOPMENT MODE: Email sending disabled. Referral confirmation logged.'];
        }

        // Initialize PHPMailer
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Username = getenv('SMTP_USER') ?: 'cityhealthofficeofkoronadal@gmail.com';
        $mail->Password = getenv('SMTP_PASS') ?: '';
        $mail->Port = getenv('SMTP_PORT') ?: 587;

        $fromEmail = getenv('SMTP_FROM') ?: 'cityhealthofficeofkoronadal@gmail.com';
        $fromName = getenv('SMTP_FROM_NAME') ?: 'City Health Office of Koronadal';

        // Debug mode
        $debug = (getenv('APP_DEBUG') ?: '0') === '1';
        if ($debug) {
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = 'error_log';
        }

        // Email settings
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($patient_info['email'], $patient_name);
        $mail->isHTML(true);
        $mail->Subject = 'Medical Referral Confirmation - CHO Koronadal [' . $referral_num . ']';

        // Create HTML email body
        $mail->Body = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #ffffff;">
            <div style="background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 30px 20px; text-align: center;">
                <h1 style="margin: 0 0 10px 0; font-size: 28px;">🏥 Medical Referral Issued</h1>
                <p style="margin: 0; font-size: 16px; opacity: 0.9;">City Health Office of Koronadal</p>
            </div>
            
            <div style="padding: 30px 20px;">
                <p style="font-size: 16px; margin-bottom: 20px;">
                    Dear <strong>' . htmlspecialchars($patient_name, ENT_QUOTES, 'UTF-8') . '</strong>,
                </p>
                
                <p style="margin-bottom: 25px;">
                    A medical referral has been issued for you. Please present this referral and required documents at the destination healthcare facility.
                </p>
                
                <div style="background: #28a745; color: white; padding: 10px 15px; border-radius: 25px; display: inline-block; font-weight: bold; font-size: 16px; margin-bottom: 20px;">
                    📋 Referral ID: ' . htmlspecialchars($referral_num, ENT_QUOTES, 'UTF-8') . '
                </div>
                
                <div style="background: #f8f9fa; border-radius: 10px; padding: 20px; margin: 20px 0; border-left: 5px solid #28a745;">
                    <h3 style="margin: 0 0 15px 0; color: #28a745; font-size: 20px;">Referral Details</h3>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr style="border-bottom: 1px solid #e9ecef;">
                            <td style="padding: 12px 0; font-weight: 600; color: #28a745; width: 35%;">Patient Name:</td>
                            <td style="padding: 12px 0; color: #333;">' . htmlspecialchars($patient_name, ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e9ecef;">
                            <td style="padding: 12px 0; font-weight: 600; color: #28a745;">Referred To:</td>
                            <td style="padding: 12px 0; color: #333;">' . htmlspecialchars($destination_name, ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>
                        <tr style="border-bottom: 1px solid #e9ecef;">
                            <td style="padding: 12px 0; font-weight: 600; color: #28a745;">Referring Facility:</td>
                            <td style="padding: 12px 0; color: #333;">' . htmlspecialchars($referral_details['referring_facility'] ?? 'CHO Koronadal', ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>' .
                        $service_info_html .
                        $doctor_info_html .
                        $appointment_info_html .
                        $qr_info_html . '
                        <tr>
                            <td style="padding: 12px 0; font-weight: 600; color: #28a745;">Referral Reason:</td>
                            <td style="padding: 12px 0; color: #333; line-height: 1.5;">' . htmlspecialchars($referral_details['referral_reason'] ?? 'Medical consultation', ENT_QUOTES, 'UTF-8') . '</td>
                        </tr>
                    </table>
                </div>';

        // Add appointment reminder if scheduled
        if ($has_appointment) {
            $mail->Body .= '
                <div style="background: #fff3cd; padding: 15px; border-radius: 8px; border-left: 4px solid #ffc107; margin: 20px 0;">
                    <h4 style="margin: 0 0 10px 0; color: #856404;">⏰ Appointment Reminder</h4>
                    <p style="margin: 0; color: #856404;">
                        <strong>Please arrive 15 minutes before your scheduled appointment time.</strong><br>
                        This allows time for registration and referral processing.
                    </p>
                </div>';
        }

        $mail->Body .= '
                <div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 8px; padding: 15px; margin: 20px 0;">
                    <h4 style="margin: 0 0 10px 0; color: #155724;">📋 Required Documents</h4>
                    <ul style="margin: 0; padding-left: 20px; color: #155724;">
                        <li><strong>Valid Government-issued ID</strong></li>
                        <li><strong>This referral document</strong> (printed or on mobile)</li>
                        <li><strong>PhilHealth card</strong> (if applicable)</li>
                        <li><strong>Previous medical records</strong> (if relevant to your condition)</li>
                        <li><strong>List of current medications</strong> (if any)</li>
                    </ul>
                </div>
                
                <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin: 20px 0;">
                    <h4 style="margin: 0 0 10px 0; color: #1976d2;">📞 Contact Information</h4>
                    <p style="margin: 5px 0; color: #333;"><strong>CHO Koronadal:</strong> (083) 228-8042</p>
                    <p style="margin: 5px 0; color: #333;"><strong>Email:</strong> info@chokoronadal.gov.ph</p>
                    <p style="margin: 10px 0 0 0; font-size: 14px; color: #6c757d;">
                        For questions about this referral, please contact the issuing facility.
                    </p>
                </div>
                
                <div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 8px; padding: 15px; margin: 20px 0;">
                    <h4 style="margin: 0 0 10px 0; color: #721c24;">⚠️ Important Notice</h4>
                    <p style="margin: 0; color: #721c24; font-size: 14px;">
                        This referral is valid for medical consultation. Please present it along with required documents at the destination facility. 
                        Ensure you follow up on any additional instructions from your referring physician.
                    </p>
                </div>
                
                <p style="margin-top: 25px; font-size: 16px;">
                    We wish you good health and a speedy recovery!
                </p>
            </div>
            
            <div style="background: #f8f9fa; padding: 20px; text-align: center; border-top: 1px solid #e9ecef;">
                <p style="margin: 5px 0; color: #6c757d; font-size: 14px;"><strong>City Health Office of Koronadal</strong></p>
                <p style="margin: 5px 0; color: #6c757d; font-size: 14px;">This is an automated message. Please do not reply to this email.</p>
                <p style="margin: 5px 0; color: #6c757d; font-size: 14px;">© ' . date('Y') . ' CHO Koronadal. All rights reserved.</p>
            </div>
        </div>';

        // Create plain text version
        $mail->AltBody = "MEDICAL REFERRAL CONFIRMATION - CHO KORONADAL

Dear {$patient_name},

A medical referral has been issued for you.

REFERRAL DETAILS:
Referral ID: {$referral_num}
Patient Name: {$patient_name}
Referred To: {$destination_name}
Referring Facility: " . ($referral_details['referring_facility'] ?? 'CHO Koronadal') . 
$service_info . 
$doctor_info . 
$appointment_info . 
$qr_info_text . "
Referral Reason: " . ($referral_details['referral_reason'] ?? 'Medical consultation') . "

REQUIRED DOCUMENTS:
• Valid Government-issued ID
• This referral document
• PhilHealth card (if applicable)
• Previous medical records (if relevant)
• List of current medications (if any)

CONTACT INFORMATION:
CHO Koronadal: (083) 228-8042
Email: info@chokoronadal.gov.ph

IMPORTANT: This referral is valid for medical consultation. Please present it along with required documents at the destination facility.

We wish you good health and a speedy recovery!

City Health Office of Koronadal
This is an automated message. Please do not reply to this email.
© " . date('Y') . " CHO Koronadal. All rights reserved.";

        // Embed QR code if available
        if ($qr_result && $qr_result['success'] && !empty($qr_result['qr_image_data'])) {
            try {
                // Use the binary image data from QR generation result
                if (method_exists($mail, 'addStringEmbeddedImage')) {
                    // PHPMailer 6.2+ has addStringEmbeddedImage method for binary data
                    $mail->addStringEmbeddedImage(
                        $qr_result['qr_image_data'],
                        $qr_image_cid,
                        'qr_code.png',
                        'base64',
                        'image/png'
                    );
                } else {
                    // Fallback: create temporary file for older PHPMailer versions
                    $temp_file = sys_get_temp_dir() . '/qr_' . uniqid() . '.png';
                    if (file_put_contents($temp_file, $qr_result['qr_image_data'])) {
                        $mail->addEmbeddedImage($temp_file, $qr_image_cid, 'qr_code.png');
                        // Clean up temp file after sending
                        register_shutdown_function(function() use ($temp_file) {
                            if (file_exists($temp_file)) {
                                unlink($temp_file);
                            }
                        });
                    }
                }
                error_log("QR code embedded successfully in referral email");
            } catch (Exception $e) {
                error_log("Failed to embed QR code in referral email: " . $e->getMessage());
                // Continue without QR code - email will still be sent
            }
        }

        // Send the email
        $mail->send();
        
        error_log("Referral confirmation email sent successfully to: " . $patient_info['email']);
        return ['success' => true, 'message' => 'Referral confirmation email sent successfully'];
        
    } catch (Exception $e) {
        error_log("Referral email sending failed to " . ($patient_info['email'] ?? 'unknown') . ": " . $e->getMessage());
        
        // Return user-friendly error message
        $error_message = "Failed to send referral confirmation email";
        if (strpos($e->getMessage(), 'SMTP connect()') !== false) {
            $error_message = "Email service unavailable - please check configuration";
        } elseif (strpos($e->getMessage(), 'SMTP Error: data not accepted') !== false) {
            $error_message = "Email rejected - please verify patient email address";
        } elseif (strpos($e->getMessage(), 'Invalid address') !== false) {
            $error_message = "Invalid patient email address";
        } elseif (strpos($e->getMessage(), 'Authentication failed') !== false) {
            $error_message = "Email authentication failed";
        }
        
        return ['success' => false, 'message' => $error_message, 'technical_error' => $e->getMessage()];
    }
}

?>