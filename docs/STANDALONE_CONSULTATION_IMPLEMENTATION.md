# Standalone Consultation System Implementation

## Overview
This system allows creating consultations without requiring appointments or visits. It features:
- **Role-based access**: Nurses can enter vitals, Doctors can complete consultations
- **Patient search**: Search by Patient ID (username), name, or barangay
- **Separate forms**: Vitals form with its own save button, consultation form for clinical notes
- **Consultation IDs**: Displayed in the index for easy tracking

## Database Changes Required

### Step 1: Run SQL Updates
Execute the following SQL script on **both databases**:
- **Localhost**: Server 127.0.0.1:3306
- **Production**: Server 31.97.106.60:3307

```sql
-- File: database/essential_consultation_updates.sql
-- This adds required columns and constraints to support standalone consultations
```

### Step 2: Key Database Structure Changes
1. **consultations table**: Added `vitals_id`, `history_present_illness`, `physical_examination`, `assessment_diagnosis`, `consultation_notes`, `consulted_by`
2. **vitals table**: Added optional `consultation_id` for bidirectional linking
3. **Foreign keys**: Added proper relationships between consultations, vitals, and employees
4. **Indexes**: Added performance indexes for common queries

## File Implementation

### New Files Created
1. **`new_consultation_standalone.php`**: Complete standalone consultation interface
2. **`index_updated.php`**: Updated index showing consultation IDs and stats
3. **`essential_consultation_updates.sql`**: Database schema updates

### Key Features Implemented

#### Patient Search Functionality
- Search by **Patient ID** (username field in patients table)
- Search by **first_name**, **last_name**, or **full name**
- Search by **barangay** name (via barangay table join)
- Real-time search with 2+ character minimum
- Shows existing vitals/consultation status for today

#### Role-Based Access Control
```php
// Nurses can enter vitals only
if ($employee_role === 'nurse'): 
    // Show vitals form only

// Doctors/Admin/Pharmacists can do full consultations  
if (in_array($employee_role, ['admin', 'doctor', 'pharmacist'])):
    // Show both vitals and consultation forms
```

#### Vitals Management
- **Separate vitals form** with its own save button
- Automatic BMI calculation
- Today's vitals update (prevents duplicates per day)
- Links vitals to consultation when both exist

#### Consultation Management  
- **Independent of appointments/visits** (visit_id is now optional)
- All clinical fields: chief complaint, history, physical exam, assessment, diagnosis, treatment plan
- Consultation status tracking: draft → in_progress → completed → follow_up_required
- **Consultation IDs prominently displayed** in index

## Usage Instructions

### For Nurses
1. Search for patient
2. Select patient from results
3. Fill vitals form and click "Save Vital Signs"
4. Consultation can be completed later by doctor

### For Doctors/Admin
1. Search for patient
2. Select patient from results  
3. Fill vitals (optional) and click "Save Vital Signs"
4. Fill consultation form and click "Save Consultation"
5. Both forms can be used independently

### Index Dashboard
- Shows **consultation IDs** prominently
- Stats: total consultations, patients seen, in-progress, with vitals
- Quick actions: New Consultation, Search, Refresh
- Recent consultations with full details and action buttons

## Testing Steps

### 1. Database Verification
```bash
php scripts/test_consultation_system.php
```
Should show ✅ for all major components after running SQL updates.

### 2. Patient Search Test
1. Open `new_consultation_standalone.php`
2. Search for patient ID (e.g., "P000007") 
3. Search by name (e.g., "David")
4. Search by barangay (e.g., "Zone")
5. Verify results show existing vitals/consultation status

### 3. Vitals Entry Test
1. Select a patient
2. Fill some vital signs
3. Click "Save Vital Signs"
4. Verify success message
5. Search same patient - should show "Vitals: Recorded"

### 4. Consultation Creation Test
1. Select patient (with or without vitals)
2. Fill chief complaint (required)
3. Fill other consultation fields
4. Click "Save Consultation"  
5. Note the consultation ID in success message

### 5. Index Display Test
1. Open `index_updated.php`
2. Verify consultation appears with prominent ID
3. Check stats are updated
4. Test "View" and "Edit" buttons

## Production Deployment Steps

### Localhost (127.0.0.1:3306)
```sql
-- Connect to localhost database
USE wbhsms_database;
-- Run essential_consultation_updates.sql content
```

### Production (31.97.106.60:3307)  
```sql
-- Connect to production database
USE wbhsms_database;  
-- Run essential_consultation_updates.sql content
```

### File Deployment
1. Replace `pages/clinical-encounter-management/new_consultation.php` with `new_consultation_standalone.php`
2. Replace `pages/clinical-encounter-management/index.php` with `index_updated.php`
3. Test thoroughly on both environments

## Security Considerations
- **Role-based access**: Only authorized roles can create consultations
- **Foreign key constraints**: Prevent orphaned records
- **SQL injection protection**: All queries use prepared statements
- **Session validation**: All forms require active employee session

## Performance Optimizations
- **Indexes added** for common query patterns
- **Limited search results** (20 patients max)
- **Today's records check** prevents duplicate entries
- **Efficient joins** for patient/barangay data

## Troubleshooting

### "Unknown column" errors
- Run the SQL updates script
- Verify column exists: `DESCRIBE consultations;`

### Search not working
- Check patient data exists: `SELECT * FROM patients WHERE status='active' LIMIT 5;`
- Verify barangay linkage: `SELECT p.*, b.barangay_name FROM patients p LEFT JOIN barangay b ON p.barangay_id = b.barangay_id LIMIT 5;`

### Consultation not saving
- Check required fields (patient_id, chief_complaint)
- Verify foreign key constraints are properly set
- Check employee has permission for the role

This system provides a complete standalone consultation workflow that meets all your specified requirements.