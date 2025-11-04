# Production Deployment Guide - User Activity Logging System

## 🚀 Production Readiness Checklist

### ✅ **Database Optimization**

#### Required Database Settings
```sql
-- Optimize user_activity_logs table for production
ALTER TABLE user_activity_logs ENGINE=InnoDB;

-- Add production indexes
CREATE INDEX idx_user_activity_logs_user_type ON user_activity_logs(user_type);
CREATE INDEX idx_user_activity_logs_action_type ON user_activity_logs(action_type);
CREATE INDEX idx_user_activity_logs_created_at ON user_activity_logs(created_at);
CREATE INDEX idx_user_activity_logs_ip_address ON user_activity_logs(ip_address);
CREATE INDEX idx_user_activity_logs_employee_date ON user_activity_logs(employee_id, created_at);

-- Partition table for large datasets (optional)
-- ALTER TABLE user_activity_logs PARTITION BY RANGE (YEAR(created_at)) (
--     PARTITION p2024 VALUES LESS THAN (2025),
--     PARTITION p2025 VALUES LESS THAN (2026),
--     PARTITION p2026 VALUES LESS THAN (2027),
--     PARTITION p_future VALUES LESS THAN MAXVALUE
-- );
```

#### Database Configuration (my.cnf)
```ini
[mysqld]
# InnoDB settings for activity logging
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT

# Query cache for read-heavy workloads
query_cache_type = 1
query_cache_size = 64M

# Connection settings
max_connections = 200
wait_timeout = 28800
interactive_timeout = 28800

# Logging
log_error = /var/log/mysql/error.log
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 2
```

### ✅ **PHP Production Configuration**

#### php.ini Settings
```ini
# Security
expose_php = Off
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/log/php/error.log

# Performance
memory_limit = 256M
max_execution_time = 30
max_input_time = 30
post_max_size = 50M
upload_max_filesize = 50M

# Session security
session.cookie_httponly = 1
session.cookie_secure = 1
session.use_strict_mode = 1
session.cookie_samesite = "Strict"
```

### ✅ **File Permissions & Security**

#### Directory Structure
```bash
# Create secure log directories
mkdir -p /var/log/wbhsms/activity_logger
chown www-data:www-data /var/log/wbhsms/activity_logger
chmod 750 /var/log/wbhsms/activity_logger

# Cache directory
mkdir -p /var/cache/wbhsms/activity_logger
chown www-data:www-data /var/cache/wbhsms/activity_logger
chmod 750 /var/cache/wbhsms/activity_logger

# Application permissions
chown -R www-data:www-data /var/www/wbhsms
chmod -R 644 /var/www/wbhsms
chmod -R 755 /var/www/wbhsms/*/
chmod 600 /var/www/wbhsms/config/*.php
```

#### .htaccess Security
```apache
# /var/www/wbhsms/logs/.htaccess
<Files "*">
    Require all denied
</Files>

# /var/www/wbhsms/config/.htaccess
<Files "*.php">
    Require all denied
</Files>
<Files "db.php">
    Require ip 127.0.0.1
    Require ip ::1
</Files>

# /var/www/wbhsms/utils/.htaccess
<Files "*.php">
    Require all denied
</Files>
```

### ✅ **Environment Configuration**

#### Production .env
```bash
# Application Environment
APP_ENV=production
APP_DEBUG=false

# Database Configuration
DB_HOST=localhost
DB_NAME=wbhsms_database
DB_USER=wbhsms_user
DB_PASS=secure_random_password_here

# Activity Logger Settings
ACTIVITY_LOGGER_RATE_LIMIT=50
ACTIVITY_LOGGER_RETENTION_DAYS=365
ACTIVITY_LOGGER_ENABLE_ALERTS=true

# Security
ENCRYPTION_KEY=32_character_random_key_here
HASH_SALT=unique_salt_for_hashing

# Monitoring
WEBHOOK_ALERT_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
EMAIL_ALERT_RECIPIENTS=admin@hospital.com,security@hospital.com

# SMTP Configuration
SMTP_HOST=smtp.hospital.com
SMTP_PORT=587
SMTP_USERNAME=alerts@hospital.com
SMTP_PASSWORD=smtp_password_here
SMTP_ENCRYPTION=tls
```

### ✅ **Web Server Configuration**

#### Apache Virtual Host
```apache
<VirtualHost *:443>
    ServerName wbhsms.hospital.com
    DocumentRoot /var/www/wbhsms
    
    # SSL Configuration
    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/wbhsms.crt
    SSLCertificateKeyFile /etc/ssl/private/wbhsms.key
    
    # Security Headers
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options DENY
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
    Header always set Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'"
    
    # Rate Limiting
    <IfModule mod_evasive24.c>
        DOSHashTableSize    2048
        DOSPageCount        10
        DOSSiteCount        100
        DOSPageInterval     1
        DOSSiteInterval     1
        DOSBlockingPeriod   300
    </IfModule>
    
    # Logging
    LogLevel warn
    CustomLog /var/log/apache2/wbhsms_access.log combined
    ErrorLog /var/log/apache2/wbhsms_error.log
    
    # Disable server signature
    ServerTokens Prod
    ServerSignature Off
</VirtualHost>
```

#### Nginx Configuration
```nginx
server {
    listen 443 ssl http2;
    server_name wbhsms.hospital.com;
    
    root /var/www/wbhsms;
    index index.php;
    
    # SSL Configuration
    ssl_certificate /etc/ssl/certs/wbhsms.crt;
    ssl_certificate_key /etc/ssl/private/wbhsms.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512;
    
    # Security Headers
    add_header X-Content-Type-Options nosniff;
    add_header X-Frame-Options DENY;
    add_header X-XSS-Protection "1; mode=block";
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains";
    
    # Rate Limiting
    limit_req_zone $binary_remote_addr zone=login:10m rate=5r/m;
    limit_req_zone $binary_remote_addr zone=api:10m rate=20r/m;
    
    # PHP Processing
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        
        # Security
        fastcgi_hide_header X-Powered-By;
    }
    
    # Rate limiting for sensitive endpoints
    location ~* /auth/ {
        limit_req zone=login burst=3 nodelay;
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~* /api/ {
        limit_req zone=api burst=10 nodelay;
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    # Deny access to sensitive files
    location ~ /\. { deny all; }
    location ~ /config/ { deny all; }
    location ~ /logs/ { deny all; }
    location ~ /utils/ { deny all; }
}
```

### ✅ **Monitoring & Alerting Setup**

#### Cron Jobs for Maintenance
```bash
# /etc/cron.d/wbhsms-activity-logger

# Daily maintenance at 2 AM
0 2 * * * www-data /usr/bin/php /var/www/wbhsms/scripts/daily_maintenance.php

# Log rotation at 3 AM
0 3 * * * root /usr/sbin/logrotate /etc/logrotate.d/wbhsms-activity-logger

# Weekly performance report
0 5 * * 0 www-data /usr/bin/php /var/www/wbhsms/scripts/weekly_report.php

# Monthly archive cleanup
0 4 1 * * www-data /usr/bin/php /var/www/wbhsms/scripts/monthly_cleanup.php
```

#### Log Rotation Configuration
```bash
# /etc/logrotate.d/wbhsms-activity-logger
/var/log/wbhsms/activity_logger/*.log {
    daily
    missingok
    rotate 30
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
    postrotate
        systemctl reload apache2 > /dev/null 2>&1 || true
    endscript
}
```

### ✅ **Performance Optimization**

#### Redis Cache Configuration (Optional)
```bash
# Install Redis for caching
apt-get install redis-server

# /etc/redis/redis.conf
maxmemory 256mb
maxmemory-policy allkeys-lru
save 900 1
save 300 10
save 60 10000
```

#### Database Connection Pooling
```php
// config/db_production.php
$config = [
    'driver' => 'mysql',
    'host' => $_ENV['DB_HOST'],
    'database' => $_ENV['DB_NAME'],
    'username' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASS'],
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => true, // Connection pooling
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]
];
```

### ✅ **Security Hardening**

#### Firewall Rules
```bash
# UFW Firewall rules
ufw default deny incoming
ufw default allow outgoing
ufw allow ssh
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow from 192.168.0.0/16 to any port 3306  # MySQL from internal network only
ufw enable
```

#### Fail2Ban Configuration
```ini
# /etc/fail2ban/jail.local
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 3

[wbhsms-auth]
enabled = true
port = http,https
filter = wbhsms-auth
logpath = /var/log/apache2/wbhsms_access.log
maxretry = 5
bantime = 1800

[wbhsms-activity]
enabled = true
port = http,https
filter = wbhsms-activity
logpath = /var/log/wbhsms/activity_logger/activity_logger_errors.log
maxretry = 10
bantime = 600
```

### ✅ **Backup Strategy**

#### Database Backup Script
```bash
#!/bin/bash
# /usr/local/bin/backup_activity_logs.sh

BACKUP_DIR="/var/backups/wbhsms"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="wbhsms_database"

# Create backup directory
mkdir -p $BACKUP_DIR

# Backup activity logs table
mysqldump --single-transaction --routines --triggers \
    --user=$DB_USER --password=$DB_PASS \
    $DB_NAME user_activity_logs > $BACKUP_DIR/activity_logs_$DATE.sql

# Compress backup
gzip $BACKUP_DIR/activity_logs_$DATE.sql

# Remove backups older than 30 days
find $BACKUP_DIR -name "activity_logs_*.sql.gz" -mtime +30 -delete

# Optional: Upload to S3 or remote storage
# aws s3 cp $BACKUP_DIR/activity_logs_$DATE.sql.gz s3://backup-bucket/wbhsms/
```

### ✅ **Health Checks**

#### System Health Monitor
```bash
#!/bin/bash
# /usr/local/bin/health_check.sh

# Check database connection
mysql -u$DB_USER -p$DB_PASS -e "SELECT 1 FROM user_activity_logs LIMIT 1;" > /dev/null 2>&1
if [ $? -ne 0 ]; then
    echo "Database connection failed" | mail -s "WBHSMS Alert" admin@hospital.com
fi

# Check log file permissions
if [ ! -w "/var/log/wbhsms/activity_logger" ]; then
    echo "Log directory not writable" | mail -s "WBHSMS Alert" admin@hospital.com
fi

# Check disk space
DISK_USAGE=$(df /var/log/wbhsms | tail -1 | awk '{print $5}' | sed 's/%//')
if [ $DISK_USAGE -gt 80 ]; then
    echo "Log directory disk usage: $DISK_USAGE%" | mail -s "WBHSMS Alert" admin@hospital.com
fi
```

### ✅ **Compliance & Auditing**

#### HIPAA Compliance Checklist
- [x] All access logged with timestamps
- [x] IP address tracking for audit trails
- [x] Secure data transmission (HTTPS)
- [x] Access controls and authentication
- [x] Data retention policies implemented
- [x] Audit log integrity protection
- [x] Incident detection and response

#### Data Privacy (GDPR)
```php
// Optional IP anonymization for GDPR compliance
private function anonymizeIpAddress($ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return substr($ip, 0, strrpos($ip, '.')) . '.0';
    } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return substr($ip, 0, strrpos($ip, ':')) . '::';
    }
    return $ip;
}
```

## 🚀 **Deployment Commands**

### Initial Deployment
```bash
# 1. Update system
apt-get update && apt-get upgrade -y

# 2. Install required packages
apt-get install -y apache2 mysql-server php8.1 php8.1-mysql php8.1-mbstring \
    php8.1-xml php8.1-curl redis-server fail2ban ufw logrotate

# 3. Configure database
mysql_secure_installation

# 4. Deploy application
rsync -av --delete /local/wbhsms/ /var/www/wbhsms/

# 5. Set permissions
chown -R www-data:www-data /var/www/wbhsms
chmod -R 644 /var/www/wbhsms
find /var/www/wbhsms -type d -exec chmod 755 {} \;

# 6. Run database migration
php /var/www/wbhsms/scripts/migrate_activity_logs.php

# 7. Configure services
systemctl enable apache2 mysql redis-server
systemctl start apache2 mysql redis-server

# 8. Setup monitoring
crontab -u www-data /var/www/wbhsms/scripts/production_crontab
```

### Zero-Downtime Updates
```bash
# 1. Create backup
mysqldump wbhsms_database > /var/backups/pre_update_$(date +%Y%m%d).sql

# 2. Deploy to staging directory
rsync -av /local/wbhsms/ /var/www/wbhsms_staging/

# 3. Run tests
php /var/www/wbhsms_staging/scripts/test_activity_logging.php

# 4. Atomic switch
mv /var/www/wbhsms /var/www/wbhsms_old
mv /var/www/wbhsms_staging /var/www/wbhsms
systemctl reload apache2

# 5. Verify deployment
curl -s https://wbhsms.hospital.com/health_check.php

# 6. Cleanup
rm -rf /var/www/wbhsms_old
```

## 📊 **Production Monitoring URLs**

- **System Status**: `https://your-domain.com/pages/management/admin/system/activity_logger_monitoring.php`
- **Health Check**: `https://your-domain.com/scripts/health_check.php`
- **Performance Metrics**: `https://your-domain.com/api/metrics.php`

This production-ready configuration ensures enterprise-grade security, performance, and reliability for your activity logging system.