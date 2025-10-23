# Patient Registration Diagnostic Tools

This directory contains diagnostic tools for troubleshooting the patient registration system.

## 🔧 Available Tools

### 1. `system_check.php`
**Comprehensive System Health Check**
- Database connectivity and table structure validation
- Environment variable verification
- File permissions and SMTP connectivity tests
- Password validation and age calculation tests
- Security configuration checks
- Overall system health summary

**Usage:** Navigate to `http://localhost/wbhsms-cho-koronadal/pages/registration/tools/system_check.php`

### 2. `debug_registration.php`
**Quick Registration Debug**
- Database connection test
- Environment variables display
- Barangay loading test
- SMTP connection test
- Recent mail error logs

**Usage:** Navigate to `http://localhost/wbhsms-cho-koronadal/pages/registration/tools/debug_registration.php`

### 3. `test_registration_no_email.php`
**Direct Registration Test (No Email)**
- Test patient registration without OTP email verification
- Useful for testing database insertion logic
- Bypasses email requirements for development

**Usage:** Navigate to `http://localhost/wbhsms-cho-koronadal/pages/registration/tools/test_registration_no_email.php`

### 4. `test_registration.php`
**Database Structure and Registration System Overview**
- View patients table structure
- Check available barangays
- Display recent registrations
- Quick links to registration and login forms
- Form field requirements summary

**Usage:** Navigate to `http://localhost/wbhsms-cho-koronadal/pages/registration/tools/test_registration.php`

### 5. `debug_password_hashing.php`
**Password Hashing Debug Tool**
- Analyzes the password hashing flow during registration
- Identifies double-hashing issues
- Tests password verification against database hashes
- Checks recent patient registrations for authentication problems

**Usage:** Navigate to `http://localhost/wbhsms-cho-koronadal-1/pages/patient/registration/tools/debug_password_hashing.php`

### 6. `fix_double_hashed_passwords.php`
**Password Double-Hashing Fix**
- Identifies patients affected by the double-hashing bug
- Provides analysis of which patients can/cannot login
- Offers options to fix affected patient passwords
- Shows summary of database password issues

**Usage:** Navigate to `http://localhost/wbhsms-cho-koronadal-1/pages/patient/registration/tools/fix_double_hashed_passwords.php`

### 7. `test_password_fix.php`
**Password Fix Verification**
- Simulates the corrected registration and login flow
- Demonstrates the difference between old (broken) and new (fixed) password handling
- Verifies that the fix prevents double-hashing

**Usage:** Navigate to `http://localhost/wbhsms-cho-koronadal-1/pages/patient/registration/tools/test_password_fix.php`

### 8. `mail_error.log`
**Email Error Logging**
- Contains SMTP authentication errors and email sending failures
- Automatically populated by registration system when email errors occur
- Useful for troubleshooting email delivery issues

**Location:** `mail_error.log` in this tools directory

## � Password Double-Hashing Fix (October 2025)

### Issue Identified
A critical bug was discovered where patient passwords were being hashed twice during registration:
1. First hash in `register_patient.php` (correct)
2. Second hash in `registration_otp.php` (incorrect - double hashing)

This caused patients to be unable to login after successful registration.

### Fix Applied
**File:** `pages/patient/registration/registration_otp.php`
**Line 85 changed from:**
```php
$hashedPassword = password_hash($regData['password'], PASSWORD_DEFAULT);
```
**To:**
```php
$hashedPassword = $regData['password']; // Already hashed in register_patient.php
```

### Tools for This Fix
- `debug_password_hashing.php` - Diagnose the double-hashing issue
- `fix_double_hashed_passwords.php` - Identify and fix affected patients
- `test_password_fix.php` - Verify the fix works correctly

## �📝 When to Use These Tools

### During Development:
- Use `system_check.php` to verify all components are working
- Use `debug_registration.php` for quick troubleshooting
- Use `test_registration_no_email.php` to test database logic
- Use `test_registration.php` to view system overview and structure
- Use `debug_password_hashing.php` to verify password handling
- Check `mail_error.log` for email delivery issues

### After Password Fix:
- Run `fix_double_hashed_passwords.php` to check for affected patients
- Use `test_password_fix.php` to verify new registrations work
- Test login functionality with newly registered patients

### Before Deployment:
- Run `system_check.php` to ensure production readiness
- Verify all tests pass before going live

### Troubleshooting Issues:
- Check `debug_registration.php` for immediate diagnostics
- Review system health with `system_check.php`
- Check `mail_error.log` for email authentication problems
- Test specific components individually

## 🔒 Security Note

These tools are for **development and testing only**. They should not be accessible in production environments as they may expose sensitive configuration information.

## 📁 File Structure

```
tools/
├── README.md                        # This file
├── system_check.php                 # Comprehensive health check
├── debug_registration.php           # Quick debug tool
├── test_registration_no_email.php   # Direct registration test
├── test_registration.php            # Database overview tool
└── mail_error.log                   # Email error logging
```

## 🔗 Related Files

Main registration system files (in parent directory):
- `../patient_registration.php` - Main registration form
- `../register_patient.php` - Backend processing
- `../registration_otp.php` - OTP verification
- `../registration_success.php` - Success page
- `../resend_registration_otp.php` - Resend OTP functionality

---
*Created for CHO Koronadal Web-Based Health Management System*