-- Add QR system columns to referrals table (similar to appointments)
-- This will enable QR code generation and verification for referrals

ALTER TABLE referrals 
ADD COLUMN qr_code_path LONGBLOB NULL AFTER notes,
ADD COLUMN verification_code VARCHAR(255) NULL AFTER qr_code_path,
ADD COLUMN last_qr_verification DATETIME NULL AFTER verification_code,
ADD COLUMN last_manual_verification DATETIME NULL AFTER last_qr_verification,
ADD COLUMN manual_verification_by INT(11) NULL AFTER last_manual_verification;

-- Add index for verification_code for faster lookups
ALTER TABLE referrals 
ADD INDEX idx_referrals_verification_code (verification_code);

-- Add index for QR verification tracking
ALTER TABLE referrals 
ADD INDEX idx_referrals_qr_verification (last_qr_verification);

-- Show updated table structure
DESCRIBE referrals;

-- Show sample of current data to ensure no data loss
SELECT referral_id, referral_num, patient_id, status, qr_code_path, verification_code 
FROM referrals 
LIMIT 5;