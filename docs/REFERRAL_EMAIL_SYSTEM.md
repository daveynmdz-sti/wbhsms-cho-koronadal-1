# Referral Email System

## Overview

The referral email system automatically sends confirmation emails to patients when medical referrals are created. This feature enhances patient communication and provides important referral information directly to the patient's inbox.

## Features

- **Automatic Email Sending**: Emails are sent automatically when referrals are created
- **Rich HTML Content**: Professional email templates with patient and referral details
- **QR Code Embedding**: QR codes are embedded in emails for easy verification at destination facilities
- **Plain Text Fallback**: Includes plain text version for email clients that don't support HTML
- **Error Handling**: Graceful error handling - referral creation continues even if email fails
- **Development Mode**: Can be disabled during development/testing

## Email Content Includes

### Patient Information
- Patient name
- Referral number (e.g., REF-20241111-0001)
- Referring facility details

### Referral Details
- Destination facility name
- Referral reason
- Service type (if specified)
- Assigned doctor (if applicable)
- Scheduled appointment date/time (if applicable)

### Documentation Requirements
- List of required documents to bring
- Valid government-issued ID
- PhilHealth card
- Previous medical records
- Current medications list

### QR Code (if generated)
- Embedded QR code image for quick facility check-in
- Verification code as backup

### Contact Information
- CHO Koronadal contact details
- Email and phone numbers
- Important notices and reminders

## Technical Implementation

### Files Involved

1. **`utils/referral_email.php`**
   - Main email functionality
   - `sendReferralConfirmationEmail()` function
   - HTML template generation
   - PHPMailer integration

2. **`pages/referrals/create_referrals.php`**
   - Integration with referral creation process
   - Patient email retrieval
   - Email sending after successful referral creation

3. **`config/email.php`**
   - SMTP configuration
   - Email settings and templates

### Email Flow

1. **Referral Creation**: When a referral is successfully created in `create_referrals.php`
2. **Patient Data Retrieval**: System fetches patient information including email address
3. **Referral Details Compilation**: Gathers all referral information for email content
4. **QR Code Integration**: Includes QR code data if generated successfully
5. **Email Composition**: Creates both HTML and plain text versions
6. **Email Sending**: Attempts to send via configured SMTP
7. **Error Handling**: Logs results without failing referral creation

### Database Requirements

The patient must have a valid email address in the `patients.email` column for emails to be sent.

```sql
-- Patient email column
ALTER TABLE patients ADD COLUMN email VARCHAR(100) DEFAULT NULL;
```

## Configuration

### SMTP Settings

Configure these environment variables or add to your `.env` file:

```bash
# SMTP Configuration
SMTP_HOST=smtp.gmail.com
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-password
SMTP_PORT=587
SMTP_FROM=noreply@chokoronadal.gov.ph
SMTP_FROM_NAME="City Health Office of Koronadal"

# Development settings
APP_DEBUG=0
```

### Development Mode

To disable email sending during development:
```bash
SMTP_PASS=disabled
```

This will log email attempts without actually sending them.

## Testing

### Test Script

Use the included test script to verify email functionality:

```bash
# Navigate to your web directory
http://localhost/wbhsms-cho-koronadal-1/test_referral_email.php
```

### Manual Testing Steps

1. **Configure SMTP**: Ensure proper SMTP settings
2. **Test Email**: Update test script with a real email address
3. **Run Test**: Execute test script and check email delivery
4. **Create Test Referral**: Create a referral for a patient with a valid email
5. **Verify Email**: Check that confirmation email is received

## Error Handling

### Common Issues

1. **"Email service not configured"**
   - Check SMTP_PASS environment variable
   - Verify SMTP credentials

2. **"SMTP connection failed"**
   - Verify SMTP_HOST and SMTP_PORT settings
   - Check network connectivity
   - Verify firewall settings

3. **"Email rejected by server"**
   - Check sender email address configuration
   - Verify recipient email address format
   - Check for spam/blacklist issues

4. **"Invalid patient email address"**
   - Ensure patient has valid email in database
   - Verify email format validation

### Logging

All email attempts are logged regardless of success/failure:

```php
// Success log
error_log("Referral confirmation email sent successfully for referral $referral_num to {$patient_email}");

// Failure log  
error_log("Failed to send referral confirmation email for referral $referral_num: " . $error_message);
```

Check your PHP error log or Apache error log for email-related messages.

## Security Considerations

1. **Environment Variables**: Store SMTP credentials securely
2. **Input Validation**: All email content is properly escaped
3. **Rate Limiting**: Consider implementing rate limiting for production
4. **Privacy**: Email contains medical information - ensure secure transmission

## Future Enhancements

1. **Email Templates**: Customizable email templates
2. **Multi-language Support**: Templates in different languages
3. **Email Preferences**: Allow patients to opt-out of email notifications
4. **Delivery Tracking**: Track email delivery status
5. **Reminder Emails**: Send appointment reminder emails
6. **Email Queue**: Queue emails for better performance

## Troubleshooting

### Email Not Received

1. Check spam/junk folder
2. Verify email address in patient record
3. Check email logs for error messages
4. Test SMTP configuration with test script
5. Verify email server reputation

### Development Issues

1. Enable debug mode: `APP_DEBUG=1`
2. Check PHP error logs
3. Use test script for isolated testing
4. Verify all required dependencies are installed

### Production Deployment

1. Use production SMTP service
2. Configure proper sender domain
3. Set up email monitoring
4. Test thoroughly before deployment
5. Monitor delivery rates and bounce rates

For additional support or questions, contact the development team.