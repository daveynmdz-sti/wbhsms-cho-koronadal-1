-- Database Schema Updates for Standalone Consultation System
-- Execute these queries on both localhost (127.0.0.1) and production (31.97.106.60:3307)

-- 1. Update consultations table to remove dependencies and add missing fields
ALTER TABLE consultations 
MODIFY COLUMN visit_id INT(10) UNSIGNED NULL,
ADD COLUMN IF NOT EXISTS vitals_id INT(10) UNSIGNED NULL,
ADD COLUMN IF NOT EXISTS history_present_illness TEXT NULL,
ADD COLUMN IF NOT EXISTS physical_examination TEXT NULL,
ADD COLUMN IF NOT EXISTS assessment_diagnosis TEXT NULL,
ADD COLUMN IF NOT EXISTS chief_complaint TEXT NULL,
ADD COLUMN IF NOT EXISTS consultation_notes TEXT NULL,
ADD COLUMN IF NOT EXISTS consulted_by INT(10) UNSIGNED NULL,
MODIFY COLUMN diagnosis TEXT NULL,
MODIFY COLUMN treatment_plan TEXT NULL,
MODIFY COLUMN consultation_status ENUM('draft','in_progress','completed','follow_up_required','cancelled') DEFAULT 'draft';

-- 2. Add foreign key constraints for vitals linkage
ALTER TABLE consultations
ADD CONSTRAINT fk_consultations_vitals 
FOREIGN KEY (vitals_id) REFERENCES vitals(vitals_id) ON DELETE SET NULL ON UPDATE CASCADE,
ADD CONSTRAINT fk_consultations_consulted_by 
FOREIGN KEY (consulted_by) REFERENCES employees(employee_id) ON DELETE SET NULL ON UPDATE CASCADE;

-- 3. Create consultation_vitals junction table for many-to-many relationship (if vitals can be shared)
-- Note: This is optional if you prefer direct linkage via consultations.vitals_id
CREATE TABLE IF NOT EXISTS consultation_vitals (
    id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consultation_id INT(10) UNSIGNED NOT NULL,
    vitals_id INT(10) UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_consultation_vitals (consultation_id, vitals_id),
    FOREIGN KEY (consultation_id) REFERENCES consultations(consultation_id) ON DELETE CASCADE,
    FOREIGN KEY (vitals_id) REFERENCES vitals(vitals_id) ON DELETE CASCADE
);

-- 4. Add indexes for better performance
ALTER TABLE consultations 
ADD INDEX idx_consultations_patient (patient_id),
ADD INDEX idx_consultations_vitals (vitals_id),
ADD INDEX idx_consultations_status (consultation_status),
ADD INDEX idx_consultations_date (consultation_date),
ADD INDEX idx_consultations_consulted_by (consulted_by);

-- 5. Update vitals table to add consultation reference (optional - for bidirectional linking)
ALTER TABLE vitals
ADD COLUMN IF NOT EXISTS consultation_id INT(10) UNSIGNED NULL,
ADD CONSTRAINT fk_vitals_consultation 
FOREIGN KEY (consultation_id) REFERENCES consultations(consultation_id) ON DELETE SET NULL ON UPDATE CASCADE;

-- 6. Add proper indexes to vitals table
ALTER TABLE vitals
ADD INDEX idx_vitals_patient (patient_id),
ADD INDEX idx_vitals_consultation (consultation_id),
ADD INDEX idx_vitals_recorded_at (recorded_at);

-- 7. Create consultation_status_logs table for audit trail
CREATE TABLE IF NOT EXISTS consultation_status_logs (
    log_id INT(10) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    consultation_id INT(10) UNSIGNED NOT NULL,
    old_status ENUM('draft','in_progress','completed','follow_up_required','cancelled') NULL,
    new_status ENUM('draft','in_progress','completed','follow_up_required','cancelled') NOT NULL,
    changed_by INT(10) UNSIGNED NOT NULL,
    change_reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (consultation_id) REFERENCES consultations(consultation_id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES employees(employee_id) ON DELETE RESTRICT,
    INDEX idx_consultation_logs_consultation (consultation_id),
    INDEX idx_consultation_logs_date (created_at)
);

-- 8. Insert default consultation statuses if they don't exist
-- (This ensures the ENUM values are properly set)

-- 9. Create view for easy consultation data retrieval
CREATE OR REPLACE VIEW consultation_details AS
SELECT 
    c.consultation_id,
    c.patient_id,
    c.visit_id,
    c.vitals_id,
    c.consultation_date,
    c.chief_complaint,
    c.history_present_illness,
    c.physical_examination,
    c.assessment_diagnosis,
    c.diagnosis,
    c.treatment_plan,
    c.consultation_notes,
    c.consultation_status,
    c.consulted_by,
    c.created_at,
    c.updated_at,
    -- Patient details
    p.username AS patient_code,
    p.first_name,
    p.last_name,
    p.middle_name,
    CONCAT(p.first_name, ' ', COALESCE(CONCAT(p.middle_name, ' '), ''), p.last_name) AS full_name,
    p.date_of_birth,
    p.sex,
    p.contact_number,
    b.barangay_name,
    -- Vitals details
    v.systolic_bp,
    v.diastolic_bp,
    v.heart_rate,
    v.respiratory_rate,
    v.temperature,
    v.weight,
    v.height,
    v.bmi,
    v.recorded_at AS vitals_recorded_at,
    -- Employee details
    e.first_name AS consulted_by_name,
    e.last_name AS consulted_by_surname,
    CONCAT(e.first_name, ' ', e.last_name) AS consulted_by_full_name
FROM consultations c
LEFT JOIN patients p ON c.patient_id = p.patient_id
LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
LEFT JOIN vitals v ON c.vitals_id = v.vitals_id
LEFT JOIN employees e ON c.consulted_by = e.employee_id;

-- 10. Verify the changes
-- Run these to check if everything is set up correctly:
-- DESCRIBE consultations;
-- DESCRIBE vitals;
-- DESCRIBE consultation_vitals;
-- DESCRIBE consultation_status_logs;
-- SELECT * FROM consultation_details LIMIT 5;