# Medical Record Printing System - Access Guide

## Overview
The Medical Record Printing System is now fully integrated into the CHO Koronadal healthcare management system. This guide shows all the ways to access the medical printing functionality.

## Access Methods

### 1. Patient Records Management (Primary Access)
**Path:** `pages/management/admin/patient-records/patient_records_management.php`

- **Location:** Admin sidebar → Patient Records
- **How to Access:**
  1. Login as Admin, Doctor, Nurse, or Records Officer
  2. Navigate to "Patient Records" from the sidebar
  3. Find the patient in the table
  4. Click the green **Print** button (🖨️) in the Actions column
  5. This opens the medical print interface with the patient pre-selected

### 2. Direct Medical Printing Access
**Path:** `public/medical_record_print.php`

- **Location:** Admin sidebar → Medical Record Printing
- **How to Access:**
  1. Login as authorized user (Admin, Doctor, Nurse, Records Officer)
  2. Click "Medical Record Printing" from the sidebar navigation
  3. Search and select patient using the interface
  4. Configure print options and generate reports

### 3. Patient Profile Integration
**Path:** `pages/management/admin/patient-records/view_patient_profile.php`

- **Location:** Patient Profile View → Action Buttons
- **How to Access:**
  1. Navigate to Patient Records → View Patient (👁️ button)
  2. In the patient profile header, click "Medical Records Print"
  3. Opens the medical print interface with the patient pre-selected

### 4. Direct URL Access
**Pattern:** `http://localhost/wbhsms-cho-koronadal-1/public/medical_record_print.php?patient_id=X`

- **Usage:** Replace `X` with the actual patient ID
- **Example:** `http://localhost/wbhsms-cho-koronadal-1/public/medical_record_print.php?patient_id=123`
- **Security:** Requires valid employee session and appropriate permissions

## Role-Based Access Control

### Authorized Roles:
- ✅ **Admin** - Full access to all features
- ✅ **Doctor** - Can print records for patients under their care
- ✅ **Nurse** - Can print records for assigned patients  
- ✅ **Records Officer** - Can print for documentation purposes
- ✅ **DHO** - Read-only access for district oversight patients
- ✅ **BHW** - Limited access for assigned barangay patients

### Enhanced Integration:
- 🎯 **Sidebar Navigation** - All authorized roles now have "Medical Record Printing" in their sidebar
- 🎯 **Patient Table Actions** - Print buttons added to all role-specific patient records tables
- 🎯 **Patient Profile Integration** - Medical print buttons available in patient profile views

### Access Restrictions:
- ❌ **Laboratory Technician** - Lab-specific access only
- ❌ **Pharmacist** - Prescription-specific access only
- ❌ **Cashier** - Billing-specific access only
- ❌ **Patients** - No access to medical record printing

## Features Available

### Print Sections (Selectable):
- ✅ **Patient Information** - Basic demographics and contact details
- ✅ **Medical History** - Past medical conditions and family history
- ✅ **Consultations** - All consultation records with notes
- ✅ **Prescriptions** - Medication history and current prescriptions
- ✅ **Laboratory Results** - All lab tests and results
- ✅ **Immunizations** - Vaccination records and schedules
- ✅ **Referrals** - Referral history and status
- ✅ **Appointments** - Appointment history and upcoming visits

### Export Options:
- 🖨️ **Print to Browser** - Direct browser printing
- 📄 **PDF Download** - Generate PDF for saving/sharing
- 📋 **Print Preview** - Review before printing

### Date Range Filtering:
- 📅 **Custom Date Range** - Filter records by specific periods
- 🗓️ **Preset Ranges** - Last 30 days, 6 months, 1 year, all records

## Security Features

### Authentication & Authorization:
- 🔐 **Employee Session Required** - Must be logged in as authorized staff
- 🎭 **Role-Based Access** - Different permissions based on user role
- 👤 **Patient Ownership Validation** - Ensures appropriate access to records

### Data Protection:
- 🛡️ **CSRF Protection** - Prevents cross-site request forgery
- ⏱️ **Rate Limiting** - Prevents abuse (10 prints/hour, 50/day per user)
- 📝 **Audit Logging** - All print activities are logged with timestamps

### Privacy Compliance:
- 🏥 **HIPAA-Style Controls** - Medical record access restrictions
- 🔍 **Audit Trail** - Complete logging of who accessed what records when
- 🚫 **Access Denial Logging** - Failed access attempts are recorded

## Troubleshooting

### Common Issues:

1. **"Access Denied" Error**
   - Ensure you're logged in with appropriate role
   - Check if you have permission to view the specific patient
   - Verify session hasn't expired

2. **"Rate Limit Exceeded"**
   - Wait for the rate limit to reset (hourly/daily)
   - Contact admin if you need higher limits for legitimate use

3. **PDF Generation Fails**
   - Check server PHP extensions (Dompdf, mPDF, or TCPDF)
   - Verify write permissions on `/tmp/` directory
   - Check error logs for specific PDF library errors

4. **No Records Found**
   - Verify patient has medical records in the system
   - Check date range filters aren't too restrictive
   - Ensure patient ID is valid and active

### Support Contacts:
- **Technical Issues:** Check `/logs/medical_print_errors.log`
- **Access Problems:** Contact system administrator
- **Feature Requests:** Submit through proper channels

## Quick Start

### For Admins:
1. Go to Patient Records → Find patient → Click Print button
2. Or use sidebar "Medical Record Printing" for advanced options

### For Doctors/Nurses:
1. Access through Patient Records table or Patient Profile view
2. Select relevant sections for your clinical needs
3. Use PDF export for patient sharing or documentation

### For Records Officers:
1. Use "Medical Record Printing" from sidebar for batch operations
2. Generate comprehensive reports for archival purposes
3. Use date range filtering for specific documentation needs

---
*Last Updated: Medical Record Printing System v1.0*
*System Location: `/public/medical_record_print.php`*