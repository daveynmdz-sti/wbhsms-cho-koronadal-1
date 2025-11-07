-- =====================================================================
-- STATION QUEUE TABLES - Individual queue tables for each station
-- Generated for WBHSMS CHO Koronadal
-- Date: November 7, 2025
-- =====================================================================

-- Station 1: Triage 1
CREATE TABLE `station_1_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for Triage 1';

-- Station 2: Triage 2
CREATE TABLE `station_2_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 2,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for Triage 2';

-- Station 3: Triage 3
CREATE TABLE `station_3_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 3,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for Triage 3';

-- Station 4: Billing
CREATE TABLE `station_4_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 4,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for Billing';

-- Station 5: Primary Care 1
CREATE TABLE `station_5_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 5,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for Primary Care 1';

-- Station 6: Primary Care 2
CREATE TABLE `station_6_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 6,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for Primary Care 2';

-- Station 7: Dental
CREATE TABLE `station_7_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 7,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for Dental';

-- Station 8: TB DOTS
CREATE TABLE `station_8_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 8,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for TB DOTS';

-- Station 9: Vaccination
CREATE TABLE `station_9_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 9,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for Vaccination';

-- Station 10: Family Planning
CREATE TABLE `station_10_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 10,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for Family Planning';

-- Station 11: Animal Bite Treatment
CREATE TABLE `station_11_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 11,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for Animal Bite Treatment';

-- Station 12: Medical Document Requests
CREATE TABLE `station_12_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 12,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for Medical Document Requests';

-- Station 13: Laboratory
CREATE TABLE `station_13_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 13,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for Laboratory';

-- Station 14: Dispensing 1
CREATE TABLE `station_14_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 14,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for Dispensing 1';

-- Station 15: Dispensing 2
CREATE TABLE `station_15_queue` (
    `queue_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `username` VARCHAR(20) NOT NULL COMMENT 'Patient username e.g. P000007',
    `visit_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL,
    `service_id` INT UNSIGNED NOT NULL,
    `queue_type` ENUM('triage', 'consultation', 'lab', 'prescription', 'billing', 'document') NOT NULL,
    `station_id` INT UNSIGNED NOT NULL DEFAULT 15,
    `priority_level` ENUM('normal', 'priority', 'emergency') NOT NULL DEFAULT 'normal',
    `status` ENUM('waiting', 'in_progress', 'skipped', 'done', 'cancelled', 'no_show') NOT NULL DEFAULT 'waiting',
    `time_in` DATETIME NOT NULL,
    `time_started` DATETIME NULL,
    `time_completed` DATETIME NULL,
    `waiting_time` INT UNSIGNED NULL COMMENT 'Waiting time in minutes',
    `turnaround_time` INT UNSIGNED NULL COMMENT 'Total time in minutes',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`queue_entry_id`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_visit_id` (`visit_id`),
    INDEX `idx_appointment_id` (`appointment_id`),
    INDEX `idx_service_id` (`service_id`),
    INDEX `idx_status_time` (`status`, `time_in`),
    INDEX `idx_queue_type` (`queue_type`),
    INDEX `idx_priority_status` (`priority_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queue table for Dispensing 2';

-- =====================================================================
-- FOREIGN KEY CONSTRAINTS
-- =====================================================================

-- Station 1 Queue Foreign Keys
ALTER TABLE `station_1_queue` 
ADD CONSTRAINT `fk_station_1_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_1_queue` 
ADD CONSTRAINT `fk_station_1_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_1_queue` 
ADD CONSTRAINT `fk_station_1_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_1_queue` 
ADD CONSTRAINT `fk_station_1_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_1_queue` 
ADD CONSTRAINT `fk_station_1_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Station 2 Queue Foreign Keys
ALTER TABLE `station_2_queue` 
ADD CONSTRAINT `fk_station_2_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_2_queue` 
ADD CONSTRAINT `fk_station_2_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_2_queue` 
ADD CONSTRAINT `fk_station_2_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_2_queue` 
ADD CONSTRAINT `fk_station_2_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_2_queue` 
ADD CONSTRAINT `fk_station_2_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Station 3 Queue Foreign Keys
ALTER TABLE `station_3_queue` 
ADD CONSTRAINT `fk_station_3_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_3_queue` 
ADD CONSTRAINT `fk_station_3_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_3_queue` 
ADD CONSTRAINT `fk_station_3_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_3_queue` 
ADD CONSTRAINT `fk_station_3_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_3_queue` 
ADD CONSTRAINT `fk_station_3_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Station 4 Queue Foreign Keys
ALTER TABLE `station_4_queue` 
ADD CONSTRAINT `fk_station_4_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_4_queue` 
ADD CONSTRAINT `fk_station_4_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_4_queue` 
ADD CONSTRAINT `fk_station_4_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_4_queue` 
ADD CONSTRAINT `fk_station_4_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_4_queue` 
ADD CONSTRAINT `fk_station_4_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Station 5 Queue Foreign Keys
ALTER TABLE `station_5_queue` 
ADD CONSTRAINT `fk_station_5_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_5_queue` 
ADD CONSTRAINT `fk_station_5_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_5_queue` 
ADD CONSTRAINT `fk_station_5_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_5_queue` 
ADD CONSTRAINT `fk_station_5_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_5_queue` 
ADD CONSTRAINT `fk_station_5_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Station 6 Queue Foreign Keys
ALTER TABLE `station_6_queue` 
ADD CONSTRAINT `fk_station_6_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_6_queue` 
ADD CONSTRAINT `fk_station_6_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_6_queue` 
ADD CONSTRAINT `fk_station_6_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_6_queue` 
ADD CONSTRAINT `fk_station_6_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_6_queue` 
ADD CONSTRAINT `fk_station_6_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Station 7 Queue Foreign Keys
ALTER TABLE `station_7_queue` 
ADD CONSTRAINT `fk_station_7_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_7_queue` 
ADD CONSTRAINT `fk_station_7_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_7_queue` 
ADD CONSTRAINT `fk_station_7_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_7_queue` 
ADD CONSTRAINT `fk_station_7_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_7_queue` 
ADD CONSTRAINT `fk_station_7_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Station 8 Queue Foreign Keys
ALTER TABLE `station_8_queue` 
ADD CONSTRAINT `fk_station_8_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_8_queue` 
ADD CONSTRAINT `fk_station_8_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_8_queue` 
ADD CONSTRAINT `fk_station_8_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_8_queue` 
ADD CONSTRAINT `fk_station_8_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_8_queue` 
ADD CONSTRAINT `fk_station_8_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Station 9 Queue Foreign Keys
ALTER TABLE `station_9_queue` 
ADD CONSTRAINT `fk_station_9_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_9_queue` 
ADD CONSTRAINT `fk_station_9_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_9_queue` 
ADD CONSTRAINT `fk_station_9_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_9_queue` 
ADD CONSTRAINT `fk_station_9_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_9_queue` 
ADD CONSTRAINT `fk_station_9_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Station 10 Queue Foreign Keys
ALTER TABLE `station_10_queue` 
ADD CONSTRAINT `fk_station_10_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_10_queue` 
ADD CONSTRAINT `fk_station_10_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_10_queue` 
ADD CONSTRAINT `fk_station_10_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_10_queue` 
ADD CONSTRAINT `fk_station_10_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_10_queue` 
ADD CONSTRAINT `fk_station_10_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Station 11 Queue Foreign Keys
ALTER TABLE `station_11_queue` 
ADD CONSTRAINT `fk_station_11_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_11_queue` 
ADD CONSTRAINT `fk_station_11_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_11_queue` 
ADD CONSTRAINT `fk_station_11_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_11_queue` 
ADD CONSTRAINT `fk_station_11_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_11_queue` 
ADD CONSTRAINT `fk_station_11_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Station 12 Queue Foreign Keys
ALTER TABLE `station_12_queue` 
ADD CONSTRAINT `fk_station_12_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_12_queue` 
ADD CONSTRAINT `fk_station_12_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_12_queue` 
ADD CONSTRAINT `fk_station_12_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_12_queue` 
ADD CONSTRAINT `fk_station_12_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_12_queue` 
ADD CONSTRAINT `fk_station_12_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Station 13 Queue Foreign Keys
ALTER TABLE `station_13_queue` 
ADD CONSTRAINT `fk_station_13_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_13_queue` 
ADD CONSTRAINT `fk_station_13_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_13_queue` 
ADD CONSTRAINT `fk_station_13_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_13_queue` 
ADD CONSTRAINT `fk_station_13_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_13_queue` 
ADD CONSTRAINT `fk_station_13_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Station 14 Queue Foreign Keys
ALTER TABLE `station_14_queue` 
ADD CONSTRAINT `fk_station_14_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_14_queue` 
ADD CONSTRAINT `fk_station_14_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_14_queue` 
ADD CONSTRAINT `fk_station_14_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_14_queue` 
ADD CONSTRAINT `fk_station_14_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_14_queue` 
ADD CONSTRAINT `fk_station_14_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- Station 15 Queue Foreign Keys
ALTER TABLE `station_15_queue` 
ADD CONSTRAINT `fk_station_15_queue_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_15_queue` 
ADD CONSTRAINT `fk_station_15_queue_visit` 
    FOREIGN KEY (`visit_id`) REFERENCES `visits` (`visit_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `station_15_queue` 
ADD CONSTRAINT `fk_station_15_queue_appointment` 
    FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) 
    ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `station_15_queue` 
ADD CONSTRAINT `fk_station_15_queue_service` 
    FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `station_15_queue` 
ADD CONSTRAINT `fk_station_15_queue_station` 
    FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) 
    ON DELETE RESTRICT ON UPDATE CASCADE;

-- =====================================================================
-- END OF STATION QUEUE TABLES
-- =====================================================================