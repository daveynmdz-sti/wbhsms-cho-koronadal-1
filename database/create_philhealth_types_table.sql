-- Create philhealth_types lookup table
-- This replaces the enum constraint on patients.philhealth_type column

CREATE TABLE IF NOT EXISTS `philhealth_types` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `type_name` varchar(100) NOT NULL,
    `description` text,
    `category` enum('Direct','Indirect') NOT NULL DEFAULT 'Direct',
    `status` enum('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_type_name` (`type_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert the comprehensive PhilHealth types
INSERT INTO `philhealth_types` (`type_name`, `description`, `category`) VALUES
-- Direct Contributors (Members who directly pay premiums)
('Employees', 'Private sector employees with regular employment', 'Direct'),
('Kasambahay', 'Domestic workers and household service workers', 'Direct'),
('Self-earning', 'Self-employed individuals and professionals', 'Direct'),
('OFW', 'Overseas Filipino Workers', 'Direct'),
('Filipinos abroad', 'Filipinos residing abroad (non-OFW)', 'Direct'),
('Lifetime members', 'Members who have completed premium payments', 'Direct'),

-- Indirect Beneficiaries (Sponsored or covered by others)
('Indigents', 'Identified poor families and individuals', 'Indirect'),
('4Ps beneficiaries', 'Pantawid Pamilyang Pilipino Program beneficiaries', 'Indirect'),
('Senior citizens', 'Senior citizens aged 60 and above', 'Indirect'),
('PWD', 'Persons with Disabilities', 'Indirect'),
('SK officials', 'Sangguniang Kabataan officials', 'Indirect'),
('LGU sponsored', 'Local Government Unit sponsored members', 'Indirect'),
('No capacity to pay', 'Individuals with no financial capacity', 'Indirect'),
('Solo parent', 'Solo parent beneficiaries', 'Indirect');

-- Add foreign key constraint to patients table (if not exists)
-- Note: Run this after backing up your data and ensuring philhealth_type_id column exists

-- First, add the new column if it doesn't exist
ALTER TABLE `patients` 
ADD COLUMN IF NOT EXISTS `philhealth_type_id` int(11) NULL AFTER `isPhilHealth`;

-- Add foreign key constraint
ALTER TABLE `patients` 
ADD CONSTRAINT `fk_patients_philhealth_type` 
FOREIGN KEY (`philhealth_type_id`) 
REFERENCES `philhealth_types`(`id`) 
ON DELETE SET NULL 
ON UPDATE CASCADE;

-- Optional: Drop the old philhealth_type enum column after data migration
-- ALTER TABLE `patients` DROP COLUMN `philhealth_type`;

-- Verify the structure
SELECT 'philhealth_types table created successfully' as status;
DESCRIBE `philhealth_types`;