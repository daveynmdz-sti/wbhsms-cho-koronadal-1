# 🏥 Web-Based Healthcare Services Management System
## CHO Koronadal

A comprehensive healthcare management system for the City Health Office of Koronadal, designed for easy deployment with XAMPP.

## � Repository Structure

```
wbhsms-cho-koronadal/
├── index.php                    # Main homepage
├── api/                         # REST API endpoints and backend controllers
├── assets/                      # CSS, JS, images
├── config/                      # Database and environment configuration
├── includes/                    # Shared navigation and headers
├── pages/                       # Application pages (patient, management, queueing)
├── scripts/                     # Setup, maintenance, and utility scripts
│   ├── setup/                   # Installation and testing tools
│   ├── maintenance/             # Database maintenance scripts
│   └── cron/                    # Scheduled task scripts
├── tests/                       # Testing and debugging tools
├── docs/                        # Documentation and guides
├── utils/                       # Utility functions and templates
└── vendor/                      # Third-party libraries
```

## �🚀 Quick Setup

### Prerequisites
- [XAMPP](https://www.apachefriends.org/download.html) (includes PHP, MySQL, Apache)
- Web browser

### Installation Steps

1. **Download & Install XAMPP**
   - Download XAMPP from the official website
   - Install with default settings
   - Start Apache and MySQL services

2. **Clone/Download this Project**
   ```bash
   # Option 1: Clone with Git
   git clone https://github.com/daveynmdz/wbhsms-cho-koronadal.git
   
   # Option 2: Download ZIP and extract
   ```

3. **Place in XAMPP Directory**
   - Copy the project folder to: `C:\xampp\htdocs\` (Windows) or `/Applications/XAMPP/htdocs/` (Mac)
   - Your path should be: `htdocs/wbhsms-cho-koronadal/`

4. **Setup Database**
   - Open phpMyAdmin: http://localhost/phpmyadmin
   - Create a new database named: `wbhsms_cho`
   - Import the database file: `database/wbhsms_cho.sql`

5. **Configure Environment**
   ```bash
   # Copy the example environment file
   cp .env.example .env
   ```
   - Edit `.env` file if needed (default XAMPP settings should work)

6. **Test the Installation**
   - Visit: http://localhost/wbhsms-cho-koronadal/scripts/setup/testdb.php
   - You should see "Database Connection Successful!"
   - Visit: http://localhost/wbhsms-cho-koronadal/scripts/setup/setup_check.php
   - Verify all components are working properly

7. **Access the System**
   - Homepage: http://localhost/wbhsms-cho-koronadal/
   - Patient Login: http://localhost/wbhsms-cho-koronadal/pages/patient/auth/patient_login.php

## 📁 Project Structure

```
wbhsms-cho-koronadal/
├── 📄 index.php           # Main homepage
├── 📄 testdb.php          # Database connection test
├── 📄 .env.example        # Environment configuration template
├── 📁 assets/             # CSS, JavaScript, images
│   ├── css/              # Stylesheets
│   ├── js/               # JavaScript files
│   └── images/           # Images and icons
├── 📁 config/             # Configuration files
│   ├── env.php           # Environment loader
│   ├── db.php            # Database connection
│   └── session/          # Session management
├── 📁 database/           # Database schema
│   └── wbhsms_cho.sql    # Main database file
├── 📁 includes/           # Shared components
│   ├── sidebar_admin.php  # Admin navigation
│   └── sidebar_patient.php # Patient navigation
├── 📁 pages/              # Application pages
│   ├── patient/          # Patient portal
│   │   ├── auth/         # Login/registration
│   │   ├── dashboard.php # Patient dashboard
│   │   └── profile/      # Profile management
│   └── management/       # Staff/admin portal
└── 📁 docs/              # Documentation
```

## 🔧 Configuration

### Default XAMPP Settings (.env file)
```bash
DB_HOST=localhost
DB_PORT=3306
DB_NAME=wbhsms_cho
DB_USER=root
DB_PASS=                   # Empty for XAMPP default
APP_DEBUG=1                # Enable for development
```

### Email Configuration (Optional)
For email features like password reset:
```bash
SMTP_HOST=smtp.gmail.com
SMTP_USER=your-email@gmail.com
SMTP_PASS=your-app-password
SMTP_PORT=587
```

## 🏥 System Features

- **Patient Portal**: Registration, appointments, medical records
- **Staff Portal**: Patient management, appointments, reports
- **Authentication**: Secure login with OTP verification
- **Dashboard**: Comprehensive overview of healthcare services
- **Responsive Design**: Works on desktop and mobile devices

## 🔍 Troubleshooting

### Common Issues

**Database Connection Failed?**
1. Make sure XAMPP MySQL is running
2. Verify database `wbhsms_cho` exists in phpMyAdmin
3. Check if database file was imported correctly
4. Test connection: http://localhost/wbhsms-cho-koronadal/testdb.php

**Page Not Found?**
1. Verify project is in `htdocs/wbhsms-cho-koronadal/`
2. Make sure Apache is running in XAMPP
3. Check URL spelling

**PHP Errors?**
1. Enable error display in `.env`: `APP_DEBUG=1`
2. Check XAMPP error logs
3. Verify PHP extensions are enabled

## 🧪 Testing

- **Database Test**: `/testdb.php` - Tests database connectivity
- **System Check**: Navigate through login and dashboard pages
- **Patient Registration**: Test the registration process

## 📚 For Developers

### Advanced Setup (Production)
- See `docs/DEPLOYMENT.md` for production deployment
- Use Composer for dependency management: `composer install`
- Configure proper environment variables for production

### File Structure
- **Essential Files**: All files in this repository are required for functionality
- **Core Components**: `index.php`, `config/`, `pages/patient/`, `assets/`
- **Database**: Import `database/wbhsms_cho.sql` for full functionality

## 🆘 Support

For issues or questions:
1. Check the troubleshooting section above
2. Test database connection using `/testdb.php`
3. Review XAMPP logs for errors
4. Create an issue in the GitHub repository

## 📄 License

This project is developed for the City Health Office of Koronadal.

---

**City Health Office of Koronadal** - Improving healthcare through technology