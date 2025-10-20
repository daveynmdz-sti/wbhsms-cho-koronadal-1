-- Fix for lab_orders table to allow NULL appointment_id
-- This allows lab orders to be created without requiring an appointment

ALTER TABLE lab_orders 
MODIFY COLUMN appointment_id int(10) UNSIGNED DEFAULT NULL;

-- Verify the change
DESCRIBE lab_orders;