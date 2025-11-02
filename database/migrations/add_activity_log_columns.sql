-- Migration: Add user_type, ip_address, and device_info columns to user_activity_logs table
-- Author: GitHub Copilot
-- Date: November 3, 2025

-- Add user_type column
ALTER TABLE `user_activity_logs` 
ADD COLUMN `user_type` ENUM('employee', 'admin') NOT NULL DEFAULT 'employee' 
AFTER `employee_id`;

-- Add ip_address column
ALTER TABLE `user_activity_logs` 
ADD COLUMN `ip_address` VARCHAR(45) NULL 
AFTER `description`;

-- Add device_info column
ALTER TABLE `user_activity_logs` 
ADD COLUMN `device_info` TEXT NULL 
AFTER `ip_address`;

-- Update action_type enum to include new activity types
ALTER TABLE `user_activity_logs` 
MODIFY COLUMN `action_type` ENUM(
    'create',
    'update', 
    'deactivate',
    'password_reset',
    'role_change',
    'login',
    'login_failed',
    'logout',
    'password_change',
    'session_start',
    'session_end',
    'session_timeout',
    'account_lock',
    'account_unlock'
) NOT NULL;

-- Add indexes for better performance
CREATE INDEX `idx_user_activity_logs_user_type` ON `user_activity_logs` (`user_type`);
CREATE INDEX `idx_user_activity_logs_action_type` ON `user_activity_logs` (`action_type`);
CREATE INDEX `idx_user_activity_logs_created_at` ON `user_activity_logs` (`created_at`);
CREATE INDEX `idx_user_activity_logs_ip_address` ON `user_activity_logs` (`ip_address`);

-- Update existing records to have proper user_type based on whether they have admin_id
UPDATE `user_activity_logs` 
SET `user_type` = 'admin' 
WHERE `admin_id` IS NOT NULL AND `admin_id` > 0;

UPDATE `user_activity_logs` 
SET `user_type` = 'employee' 
WHERE (`admin_id` IS NULL OR `admin_id` = 0) AND `employee_id` IS NOT NULL;