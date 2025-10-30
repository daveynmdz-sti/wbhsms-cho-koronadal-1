-- Medical Record Security Tables
-- Creates tables for audit logging and security tracking

-- Audit log table for detailed medical record access tracking
CREATE TABLE IF NOT EXISTS `medical_record_audit_log` (
  `audit_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL COMMENT 'preview, generate, download, print, export',
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text,
  `session_id` varchar(128),
  `role` varchar(50) NOT NULL,
  `metadata` json DEFAULT NULL COMMENT 'Additional data like sections, filters, etc.',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_id`),
  KEY `idx_employee_id` (`employee_id`),
  KEY `idx_patient_id` (`patient_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_employee_patient` (`employee_id`, `patient_id`),
  FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enhanced medical record access logs table (if not exists)
CREATE TABLE IF NOT EXISTS `medical_record_access_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `access_type` varchar(20) NOT NULL COMMENT 'preview, generate, download',
  `sections_accessed` json DEFAULT NULL,
  `output_format` varchar(20) DEFAULT 'html' COMMENT 'html, pdf',
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_patient_id` (`patient_id`),
  KEY `idx_employee_id` (`employee_id`),
  KEY `idx_access_type` (`access_type`),
  KEY `idx_created_at` (`created_at`),
  FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add ip_address column to existing medical_record_access_logs if not exists
ALTER TABLE `medical_record_access_logs` 
ADD COLUMN IF NOT EXISTS `ip_address` varchar(45) DEFAULT NULL AFTER `output_format`;

-- Security settings table for rate limiting and configuration
CREATE TABLE IF NOT EXISTS `medical_record_security_settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL UNIQUE,
  `setting_value` text NOT NULL,
  `description` text,
  `updated_by` int(11),
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_id`),
  KEY `idx_setting_key` (`setting_key`),
  FOREIGN KEY (`updated_by`) REFERENCES `employees` (`employee_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default security settings
INSERT IGNORE INTO `medical_record_security_settings` (`setting_key`, `setting_value`, `description`) VALUES
('pdf_hourly_limit', '10', 'Maximum PDF generations per hour per user'),
('pdf_daily_limit', '50', 'Maximum PDF generations per day per user'),
('csrf_token_expiry', '3600', 'CSRF token expiry time in seconds'),
('audit_retention_days', '365', 'Number of days to retain audit logs'),
('rate_limit_enabled', '1', 'Enable/disable rate limiting (1=enabled, 0=disabled)'),
('require_csrf', '1', 'Require CSRF tokens for medical record operations'),
('max_sections_per_request', '20', 'Maximum number of sections per request'),
('allowed_output_formats', 'html,pdf', 'Comma-separated list of allowed output formats');

-- Create index for faster rate limit queries
CREATE INDEX IF NOT EXISTS `idx_rate_limit_check` ON `medical_record_access_logs` 
(`employee_id`, `access_type`, `output_format`, `created_at`);

-- Create index for audit queries
CREATE INDEX IF NOT EXISTS `idx_audit_queries` ON `medical_record_audit_log` 
(`employee_id`, `action`, `created_at`);

-- Employee barangay assignments table (if not exists) for BHW access control
CREATE TABLE IF NOT EXISTS `employee_barangay_assignments` (
  `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `barangay` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `assigned_by` int(11),
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`assignment_id`),
  KEY `idx_employee_id` (`employee_id`),
  KEY `idx_barangay` (`barangay`),
  KEY `idx_active` (`is_active`),
  UNIQUE KEY `unique_employee_barangay` (`employee_id`, `barangay`),
  FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  FOREIGN KEY (`assigned_by`) REFERENCES `employees` (`employee_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create view for quick access permission checks
CREATE OR REPLACE VIEW `medical_record_access_permissions` AS
SELECT 
    e.employee_id,
    e.role,
    e.first_name,
    e.last_name,
    e.is_active as employee_active,
    CASE 
        WHEN e.role IN ('Admin', 'Doctor', 'Nurse', 'Records Officer') THEN 'full'
        WHEN e.role = 'DHO' THEN 'district'
        WHEN e.role = 'BHW' THEN 'barangay'
        WHEN e.role IN ('Laboratory Tech', 'Pharmacist', 'Cashier') THEN 'limited'
        ELSE 'none'
    END as access_level,
    CASE 
        WHEN e.role IN ('Admin', 'Doctor', 'DHO', 'Records Officer') THEN 1
        ELSE 0
    END as can_export_pdf,
    CASE 
        WHEN e.role IN ('Admin', 'Records Officer') THEN 1
        ELSE 0
    END as can_view_audit_logs
FROM employees e
WHERE e.is_active = 1;