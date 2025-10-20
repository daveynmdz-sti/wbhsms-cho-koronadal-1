-- Fix lab_orders table to allow NULL appointment_id
-- This allows lab orders to be created without requiring an appointment

USE wbhsms;

-- Check current structure
DESCRIBE lab_orders;

-- Modify appointment_id to allow NULL values
ALTER TABLE lab_orders MODIFY COLUMN appointment_id INT NULL;

-- Verify the change
DESCRIBE lab_orders;

-- Show any existing records with appointment_id constraints
SELECT COUNT(*) as total_records FROM lab_orders;
SELECT COUNT(*) as records_with_appointments FROM lab_orders WHERE appointment_id IS NOT NULL;
SELECT COUNT(*) as records_without_appointments FROM lab_orders WHERE appointment_id IS NULL;