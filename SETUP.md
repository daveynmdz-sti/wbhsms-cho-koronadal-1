# CHO Koronadal WBHSMS Setup Guide

## 🏥 Web-Based Healthcare Services Management System
### Complete Installation and Configuration Guide

---

## 📋 Prerequisites

- **XAMPP** (Apache, MySQL, PHP 7.4+)
- **Web Browser** (Chrome, Firefox, Edge)
- **Internet Connection** (for SMS service)
- **Email Account** (Gmail recommended for SMTP)
- **Semaphore Account** (for SMS functionality)

---

## ⚙️ Environment Configuration

### 1. Copy Environment Template
```bash
cp .env.example .env
```

### 2. Database Configuration

#### For Local XAMPP:
```env
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=wbhsms_database
DB_USERNAME=root
DB_PASSWORD=
```

#### For Production Server:
```env
DB_HOST=31.97.106.60
DB_PORT=3307
DB_DATABASE=wbhsms_database
DB_USERNAME=mysql
DB_PASSWORD=your_secure_password
```

### 3. Email Configuration (Gmail)

1. **Enable 2-Factor Authentication** on your Gmail account
2. **Generate App Password**:
   - Go to Google Account Settings
   - Security → 2-Step Verification → App passwords
   - Generate password for "Mail"
3. **Update .env**:
```env
SMTP_HOST=smtp.gmail.com
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-16-character-app-password
SMTP_PORT=587
SMTP_FROM=your-email@gmail.com
SMTP_FROM_NAME=City Health Office of Koronadal
```

### 4. SMS Configuration (Semaphore)

1. **Sign up at [Semaphore.co](https://semaphore.co)**
2. **Get API Key** from dashboard
3. **Update .env**:
```env
SEMAPHORE_API_KEY=your_actual_semaphore_api_key
SEMAPHORE_SENDER_NAME=CHO-Koronadal
```

### 5. System Information
```env
SYSTEM_URL=http://localhost/wbhsms-cho-koronadal
CONTACT_PHONE=(083) 228-8042
CONTACT_EMAIL=info@chokoronadal.gov.ph
FACILITY_ADDRESS=Koronadal City, South Cotabato
```

---

## 🗄️ Database Setup

### 1. Create Database
```sql
-- In phpMyAdmin or MySQL command line
CREATE DATABASE wbhsms_database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 2. Import Database
```bash
# Using phpMyAdmin: Import database/wbhsms_database.sql
# Or using command line:
mysql -u root -p wbhsms_database < database/wbhsms_database.sql
```

### 3. Verify Database Connection
Visit: `http://localhost/wbhsms-cho-koronadal/testdb.php`

---

## 📱 SMS Service Testing

### 1. Test SMS Configuration
```php
<?php
require_once 'config/env.php';
require_once 'utils/SmsService.php';

// Test SMS service
$sms = new SmsService();

// Check account balance
$balance = $sms->getAccountBalance();
echo "Account Balance: " . json_encode($balance, JSON_PRETTY_PRINT);

// Send test message (replace with your phone number)
$result = $sms->sendMessage('+639123456789', 'Test message from CHO Koronadal WBHSMS');
echo "SMS Result: " . json_encode($result, JSON_PRETTY_PRINT);

// Send test OTP
$otp = SmsService::generateOtp(6);
$result = $sms->sendOtp('+639123456789', $otp, [
    'expiry_minutes' => 10,
    'service_name' => 'CHO Koronadal Test'
]);
echo "OTP Result: " . json_encode($result, JSON_PRETTY_PRINT);
?>
```

### 2. Common SMS Issues

| Issue | Solution |
|-------|----------|
| API Key Error | Verify API key in Semaphore dashboard |
| Invalid Phone Format | Use +639XXXXXXXXX format |
| Insufficient Credits | Top up Semaphore account |
| Network Error | Check internet connection |

---

## 🔒 Security Implementation

### 1. Environment Files Protection

✅ **Already Protected by .gitignore:**
- `.env` (main configuration)
- `.env.local` (local overrides)
- `config/.env*` (config directory)

### 2. File Security Check
```php
<?php
// Create: scripts/security_check.php
function checkEnvironmentSecurity() {
    $sensitive_files = ['.env', '.env.local', '.env.production'];
    $issues = [];
    
    foreach ($sensitive_files as $file) {
        if (file_exists($file)) {
            $perms = fileperms($file);
            $issues[] = [
                'file' => $file,
                'permissions' => substr(sprintf('%o', $perms), -4),
                'web_accessible' => is_readable($_SERVER['DOCUMENT_ROOT'] . '/' . $file)
            ];
        }
    }
    
    return $issues;
}

header('Content-Type: application/json');
echo json_encode(checkEnvironmentSecurity(), JSON_PRETTY_PRINT);
?>
```

### 3. Production Security Checklist

- [ ] Change default passwords
- [ ] Update API keys for production
- [ ] Set `APP_DEBUG=0` in production
- [ ] Enable HTTPS (SSL certificate)
- [ ] Configure proper file permissions
- [ ] Set up database backups
- [ ] Monitor error logs

---

## 🚀 Deployment Guide

### Local Development
```bash
# 1. Start XAMPP
# 2. Navigate to project
cd c:\xampp\htdocs\wbhsms-cho-koronadal-1

# 3. Copy and configure environment
cp .env.example .env
# Edit .env with your settings

# 4. Access application
# http://localhost/wbhsms-cho-koronadal-1
```

### Production Deployment
```bash
# 1. Upload files to server
# 2. Copy production environment
cp .env.production .env

# 3. Update .env with production values:
# - Database credentials
# - Production API keys
# - Production URLs
# - Secure settings

# 4. Set file permissions
chmod 644 .env
chmod 755 pages/
chmod 755 utils/

# 5. Test deployment
curl http://your-domain.com/wbhsms-cho-koronadal/testdb.php
```

---

## 🧪 Testing Procedures

### 1. System Health Check
```bash
# Database Connection
http://localhost/wbhsms-cho-koronadal/testdb.php

# Configuration Check
http://localhost/wbhsms-cho-koronadal/scripts/setup/setup_check.php

# SMS Service Test
http://localhost/wbhsms-cho-koronadal/scripts/test_sms.php
```

### 2. Feature Testing

| Feature | Test URL | Expected Result |
|---------|----------|-----------------|
| Employee Login | `/pages/management/auth/employee_login.php` | Login form loads |
| Patient Portal | `/pages/patient/auth/patient_login.php` | Login form loads |
| Admin Dashboard | `/pages/management/admin/dashboard.php` | Dashboard (after login) |
| SMS Service | `utils/SmsService.php` | No errors in logs |

---

## 📞 Support & Troubleshooting

### Common Issues

#### Database Connection Failed
```
Error: SQLSTATE[HY000] [2002] Connection refused
```
**Solution:**
1. Check XAMPP MySQL is running
2. Verify database credentials in `.env`
3. Ensure database exists

#### SMS Service Not Working
```
Error: SMS service not configured (missing API key)
```
**Solution:**
1. Verify `SEMAPHORE_API_KEY` in `.env`
2. Check Semaphore account balance
3. Test with correct phone number format

#### Email Not Sending
```
Error: SMTP authentication failed
```
**Solution:**
1. Enable 2FA on Gmail
2. Generate new App Password
3. Update `SMTP_PASS` with App Password (not regular password)

### Debug Mode
```env
APP_DEBUG=1
DEBUG_EMAIL=true
```

### Log Files
- **Apache Error Log**: `C:\xampp\apache\logs\error.log`
- **Application Logs**: `/logs/` directory
- **PHP Error Log**: Check `error_log()` calls in code

---

## 📞 Contact Information

**Technical Support:**
- **Phone:** (083) 228-8042
- **Email:** info@chokoronadal.gov.ph
- **Address:** Koronadal City, South Cotabato

**System Administrator:**
- Check with your IT department for production deployment issues

---

## 📝 Notes

1. **Never commit `.env` files** to version control
2. **Use different API keys** for development and production
3. **Regularly update passwords** and API keys
4. **Monitor SMS credits** to avoid service interruption
5. **Backup database regularly** in production
6. **Keep logs clean** by rotating log files

---

*Last Updated: October 28, 2025*
*Version: 1.0*