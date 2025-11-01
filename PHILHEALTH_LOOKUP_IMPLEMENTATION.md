# PhilHealth Types Lookup Table Implementation

## Changes Made

### 1. Database Schema
- **Created**: `database/create_philhealth_types_table.sql`
- **New table**: `philhealth_types` with comprehensive PhilHealth membership categories
- **Foreign key**: `patients.philhealth_type_id` → `philhealth_types.id`
- **Categories**: Direct (6 types) and Indirect (8 types) for better organization

### 2. Form Updates (patient_registration.php)
- **Dynamic loading**: PhilHealth types now loaded from database instead of hardcoded
- **Field change**: `name="philhealth_type"` → `name="philhealth_type_id"`
- **Organized display**: Types grouped by Direct/Indirect categories with optgroups
- **Form data**: Updated `$formData` array to use `philhealth_type_id`

### 3. Backend Validation (register_patient.php)
- **Field processing**: Changed from `$_POST['philhealth_type']` to `$_POST['philhealth_type_id']`
- **Database validation**: Added check to verify `philhealth_type_id` exists in `philhealth_types` table
- **Session data**: Updated to store `philhealth_type_id` instead of `philhealth_type`
- **Error handling**: Improved error messages for invalid PhilHealth type selections

### 4. Database Insertion (registration_otp.php)
- **Removed mapping**: No longer need `mapPhilhealthType()` function
- **Direct usage**: Use `philhealth_type_id` value directly from form
- **SQL update**: Changed column in INSERT statement to `philhealth_type_id`
- **Parameter binding**: Updated to use `$philhealthTypeId` variable

### 5. JavaScript Updates (patient_registration.php)
- **Form data**: Changed `philhealth_type` → `philhealth_type_id` in AJAX data object
- **Validation**: Form validation now works with database-driven options

## Database Migration Steps

1. **Create the lookup table**:
   ```sql
   SOURCE database/create_philhealth_types_table.sql;
   ```

2. **Verify table creation**:
   ```sql
   SELECT * FROM philhealth_types ORDER BY category, type_name;
   ```

3. **Test the new registration flow**:
   - Visit patient registration page
   - Verify PhilHealth types load dynamically
   - Test registration with different PhilHealth types
   - Confirm data saves correctly with foreign key reference

## Benefits of New Approach

1. **Flexibility**: Easy to add/modify PhilHealth types without code changes
2. **Data integrity**: Foreign key constraints ensure valid references
3. **Organization**: Categories (Direct/Indirect) provide logical grouping
4. **Maintenance**: Centralized management of PhilHealth types
5. **Scalability**: Can add metadata like descriptions, status flags, etc.

## Files Modified

- ✅ `pages/patient/registration/patient_registration.php` - Form and validation
- ✅ `pages/patient/registration/register_patient.php` - Backend validation  
- ✅ `pages/patient/registration/registration_otp.php` - Database insertion
- ✅ `database/create_philhealth_types_table.sql` - Database schema

## Testing Checklist

- [ ] Run SQL script to create `philhealth_types` table
- [ ] Verify PhilHealth options load correctly on registration form
- [ ] Test registration with different PhilHealth types
- [ ] Confirm database insertion works with foreign key
- [ ] Verify email delivery still works after registration
- [ ] Test validation when invalid PhilHealth type submitted

## Next Steps

1. Execute the SQL script to create the lookup table
2. Test the complete registration flow
3. Optionally migrate existing patient data if needed
4. Remove the old `mapPhilhealthType()` function from registration_otp.php