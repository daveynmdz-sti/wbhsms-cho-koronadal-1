# SQL Queries for QR Verification System

## Database Schema Changes

### Add Verification Columns to Appointments Table

```sql
-- Add verification_code column for QR token storage
ALTER TABLE appointments 
ADD COLUMN verification_code VARCHAR(255) NULL 
COMMENT 'QR verification token for appointment security';

-- Add last_qr_verification column for tracking QR scan attempts
ALTER TABLE appointments 
ADD COLUMN last_qr_verification DATETIME NULL 
COMMENT 'Last successful QR code verification';

-- Add last_manual_verification column for tracking manual verification attempts  
ALTER TABLE appointments 
ADD COLUMN last_manual_verification DATETIME NULL 
COMMENT 'Last successful manual verification';

-- Add manual_verification_by column for audit trail
ALTER TABLE appointments 
ADD COLUMN manual_verification_by INT NULL 
COMMENT 'Employee who performed manual verification';
```

### Generate Verification Codes for Existing Appointments

```sql
-- Generate verification codes for all existing confirmed appointments without codes
UPDATE appointments 
SET verification_code = UPPER(SUBSTRING(MD5(CONCAT(appointment_id, patient_id, created_at, RAND())), 1, 8))
WHERE verification_code IS NULL AND status = 'confirmed';
```

### Verify the Changes

```sql
-- Check the updated table structure
DESCRIBE appointments;

-- View appointments with verification codes
SELECT appointment_id, patient_id, scheduled_date, scheduled_time, 
       verification_code, last_qr_verification, last_manual_verification
FROM appointments 
WHERE verification_code IS NOT NULL 
LIMIT 10;

-- Count appointments with verification codes
SELECT 
    COUNT(*) as total_appointments,
    COUNT(verification_code) as appointments_with_codes,
    COUNT(*) - COUNT(verification_code) as appointments_without_codes
FROM appointments 
WHERE status = 'confirmed';
```

## Usage Examples

### Insert New Appointment with Verification Code

```sql
-- Example: Create new appointment with verification code
INSERT INTO appointments (
    patient_id, facility_id, service_id, scheduled_date, scheduled_time,
    status, verification_code, created_at, updated_at
) VALUES (
    123, 1, 1, '2025-11-07', '10:00:00',
    'confirmed', 'A1B2C3D4', NOW(), NOW()
);
```

### Update Verification Tracking

```sql
-- Log successful QR verification
UPDATE appointments 
SET last_qr_verification = NOW() 
WHERE appointment_id = 123;

-- Log successful manual verification  
UPDATE appointments 
SET last_manual_verification = NOW(),
    manual_verification_by = 456
WHERE appointment_id = 123;
```

### Security Queries

```sql
-- Find appointments with recent verification attempts
SELECT a.appointment_id, p.first_name, p.last_name, a.scheduled_date,
       a.last_qr_verification, a.last_manual_verification,
       e.first_name as verified_by_first_name, e.last_name as verified_by_last_name
FROM appointments a
JOIN patients p ON a.patient_id = p.patient_id
LEFT JOIN employees e ON a.manual_verification_by = e.employee_id
WHERE a.last_qr_verification >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
   OR a.last_manual_verification >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY GREATEST(
    COALESCE(a.last_qr_verification, '1900-01-01'),
    COALESCE(a.last_manual_verification, '1900-01-01')
) DESC;

-- Find appointments without verification codes (potential security gaps)
SELECT appointment_id, patient_id, scheduled_date, status
FROM appointments 
WHERE status = 'confirmed' 
AND verification_code IS NULL;
```

## Cleanup and Maintenance

```sql
-- Remove verification codes for cancelled/completed appointments (optional security measure)
UPDATE appointments 
SET verification_code = NULL,
    last_qr_verification = NULL,
    last_manual_verification = NULL,
    manual_verification_by = NULL
WHERE status IN ('cancelled', 'completed') 
AND scheduled_date < DATE_SUB(NOW(), INTERVAL 30 DAY);

-- Archive old verification data (for compliance)
CREATE TABLE appointment_verification_archive AS
SELECT appointment_id, verification_code, last_qr_verification, 
       last_manual_verification, manual_verification_by, created_at
FROM appointments 
WHERE scheduled_date < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

## Performance Indexes (Optional)

```sql
-- Add index for verification code lookups
CREATE INDEX idx_appointments_verification_code 
ON appointments(verification_code);

-- Add index for verification tracking queries
CREATE INDEX idx_appointments_verification_tracking 
ON appointments(last_qr_verification, last_manual_verification);

-- Add composite index for security queries
CREATE INDEX idx_appointments_security 
ON appointments(status, verification_code, scheduled_date);
```

## Rollback Queries (If Needed)

```sql
-- Remove verification columns (use with caution!)
ALTER TABLE appointments DROP COLUMN verification_code;
ALTER TABLE appointments DROP COLUMN last_qr_verification;
ALTER TABLE appointments DROP COLUMN last_manual_verification;
ALTER TABLE appointments DROP COLUMN manual_verification_by;

-- Drop indexes (if created)
DROP INDEX idx_appointments_verification_code ON appointments;
DROP INDEX idx_appointments_verification_tracking ON appointments;
DROP INDEX idx_appointments_security ON appointments;
```