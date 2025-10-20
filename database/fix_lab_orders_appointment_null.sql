-- Database migration to allow lab orders without appointments
-- This fixes the issue where appointment_id is NOT NULL in lab_orders table

USE wbhsms_database;

-- Modify lab_orders table to allow NULL appointment_id for direct lab orders
ALTER TABLE `lab_orders` 
MODIFY COLUMN `appointment_id` int(10) UNSIGNED DEFAULT NULL COMMENT 'Can be NULL for direct lab orders without appointments';

-- Add overall_status column if it doesn't exist (for better status tracking)
SET @column_exists = (SELECT COUNT(*) 
                      FROM INFORMATION_SCHEMA.COLUMNS 
                      WHERE TABLE_SCHEMA = 'wbhsms_database' 
                        AND TABLE_NAME = 'lab_orders' 
                        AND COLUMN_NAME = 'overall_status');

SET @sql = IF(@column_exists = 0, 
              'ALTER TABLE `lab_orders` ADD COLUMN `overall_status` enum(''pending'',''in_progress'',''completed'',''cancelled'') DEFAULT ''pending'' AFTER `status`',
              'SELECT "overall_status column already exists" AS message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Update existing records to have consistent overall_status
UPDATE `lab_orders` SET `overall_status` = `status` WHERE `overall_status` IS NULL;

-- Create index for better performance on direct lab order queries
CREATE INDEX IF NOT EXISTS `idx_lab_orders_patient_date` ON `lab_orders` (`patient_id`, `order_date`);
CREATE INDEX IF NOT EXISTS `idx_lab_orders_employee_date` ON `lab_orders` (`ordered_by_employee_id`, `order_date`);

-- Insert some test data to verify the fix
-- You can uncomment these lines if you want test data
/*
INSERT INTO `lab_orders` (`patient_id`, `appointment_id`, `visit_id`, `ordered_by_employee_id`, `status`, `overall_status`, `remarks`) 
VALUES 
(7, NULL, NULL, 1, 'pending', 'pending', 'Test direct lab order without appointment'),
(7, NULL, NULL, 2, 'pending', 'pending', 'Another direct lab order test');

INSERT INTO `lab_order_items` (`lab_order_id`, `test_type`, `status`) 
VALUES 
(LAST_INSERT_ID(), 'Complete Blood Count (CBC)', 'pending'),
(LAST_INSERT_ID(), 'Urinalysis', 'pending');
*/

SELECT 'Lab orders table migration completed successfully. appointment_id can now be NULL for direct lab orders.' AS message;