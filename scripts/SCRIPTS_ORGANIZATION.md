# Scripts Organization - CHO Koronadal WBHSMS

## 📁 **Current Scripts Structure**

### ✅ **Active Scripts (Keep in main scripts/)**

#### **SMS Service (3 files)**
- `chokor_sender_test.php` - **PRIMARY SMS TEST** - Test CHOKor registered sender
- `phone_format_verification.php` - Phone number format validation (+639XXXXXXXXX)
- `test_sms.php` - Interactive comprehensive SMS testing

#### **Database Validation (3 files)**
- `check_barangays_table.php` - Barangay system health check
- `check_patients_table.php` - Patient records system validation
- `check_services_table.php` - Healthcare services validation

#### **System Utilities (2 files)**
- `bom_cleanup.php` - UTF-8 BOM cleanup utility (file encoding issues)
- `session_test.php` - Session debugging and validation

#### **Folders**
- `cron/` - Scheduled background tasks
- `maintenance/` - System maintenance scripts
- `setup/` - Initial system setup and installation scripts

---

### 🗑️ **Archived Scripts (Moved to scripts/no_need/)**

#### **Development/Debugging Completed (11 files moved)**

**Database Structure Analysis:**
- `check_barangay_structure.php` - Barangay table structure analysis (completed)
- `check_table_structure.php` - General table structure debugging (completed)
- `check_visits_consultations.php` - Visit-consultation linking validation (completed)
- `check_vitals_linking.php` - Vitals table linking validation (completed)
- `check_vitals_table.php` - Vitals table structure validation (completed)
- `list_all_tables.php` - Database table inventory (completed)

**Feature Testing/Development:**
- `test_broad_search.php` - Search functionality testing (completed)
- `test_consultation_search.php` - Consultation search testing (completed)
- `test_consultation_system.php` - Consultation system testing (completed)
- `test_prescription_api.php` - Prescription API testing (completed)
- `test_vitals_consultation_linking.php` - Vitals-consultation integration testing (completed)

---

## 🎯 **Script Usage Guidelines**

### **For SMS Testing (When CHOKor Approved):**
```bash
# Primary SMS test
http://localhost/wbhsms-cho-koronadal-1/scripts/chokor_sender_test.php

# Interactive testing
http://localhost/wbhsms-cho-koronadal-1/scripts/test_sms.php

# Format validation
http://localhost/wbhsms-cho-koronadal-1/scripts/phone_format_verification.php
```

### **For System Health Checks:**
```bash
# Database validation
http://localhost/wbhsms-cho-koronadal-1/scripts/check_patients_table.php
http://localhost/wbhsms-cho-koronadal-1/scripts/check_barangays_table.php
http://localhost/wbhsms-cho-koronadal-1/scripts/check_services_table.php

# Session debugging
http://localhost/wbhsms-cho-koronadal-1/scripts/session_test.php

# File encoding cleanup
http://localhost/wbhsms-cho-koronadal-1/scripts/bom_cleanup.php
```

---

## 📋 **Maintenance Notes**

### **Archived Files (scripts/no_need/)**
- These files served their purpose during development
- Completed debugging and validation tasks
- Kept for reference but not needed for production
- Can be safely ignored or deleted if storage space needed

### **Active Files**
- Essential for ongoing system operation
- SMS service testing and validation
- Database health monitoring
- System maintenance utilities

### **Folder Structure**
- `scripts/` - Active, production-relevant scripts
- `scripts/no_need/` - Archived development/debugging scripts
- `scripts/setup/` - Installation and setup utilities
- `scripts/maintenance/` - System maintenance tasks
- `scripts/cron/` - Scheduled background processes

---

## 🚀 **Quick Reference**

**Most Important Scripts:**
1. `chokor_sender_test.php` - Primary SMS testing
2. `check_patients_table.php` - Patient system validation
3. `session_test.php` - Session debugging
4. `bom_cleanup.php` - File encoding fixes

**Total Active Scripts:** 8 files + 3 folders  
**Total Archived Scripts:** 11 files moved to no_need/  

---

*Last Updated: October 28, 2025*  
*SMS Service: Awaiting CHOKor sender approval*