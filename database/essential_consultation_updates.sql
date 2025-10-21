-- Essential Consultation System Updates
-- Run these queries on both localhost (127.0.0.1) and production (31.97.106.60:3307)

-- 1. Update consultations table structure - make visit_id optional and add missing fields
ALTER TABLE consultations 
MODIFY COLUMN visit_id INT(10) UNSIGNED NULL COMMENT 'Optional - for appointment-based consultations',
ADD COLUMN IF NOT EXISTS vitals_id INT(10) UNSIGNED NULL COMMENT 'Link to vitals record',
ADD COLUMN IF NOT EXISTS history_present_illness TEXT NULL COMMENT 'History of present illness',
ADD COLUMN IF NOT EXISTS physical_examination TEXT NULL COMMENT 'Physical examination findings',
ADD COLUMN IF NOT EXISTS assessment_diagnosis TEXT NULL COMMENT 'Clinical assessment and working diagnosis',
ADD COLUMN IF NOT EXISTS consultation_notes TEXT NULL COMMENT 'Additional consultation notes',
ADD COLUMN IF NOT EXISTS consulted_by INT(10) UNSIGNED NULL COMMENT 'Employee who conducted consultation';

-- 2. Update consultation status enum to include new statuses
ALTER TABLE consultations 
MODIFY COLUMN consultation_status ENUM('draft','in_progress','ongoing','completed','awaiting_lab_results','awaiting_followup','follow_up_required','cancelled') DEFAULT 'draft';

-- 3. Add foreign key constraints for data integrity (ONE-WAY LINKING ONLY)
ALTER TABLE consultations
ADD CONSTRAINT fk_consultations_vitals 
FOREIGN KEY (vitals_id) REFERENCES vitals(vitals_id) ON DELETE SET NULL ON UPDATE CASCADE,
ADD CONSTRAINT fk_consultations_consulted_by 
FOREIGN KEY (consulted_by) REFERENCES employees(employee_id) ON DELETE SET NULL ON UPDATE CASCADE;

-- 4. DO NOT add consultation_id to vitals table - vitals should be reusable
-- Vitals can be used by consultations, referrals, lab orders, etc.
-- consultations.vitals_id provides the link when needed

-- 5. Add performance indexes
ALTER TABLE consultations 
ADD INDEX IF NOT EXISTS idx_consultations_vitals (vitals_id),
ADD INDEX IF NOT EXISTS idx_consultations_status (consultation_status),
ADD INDEX IF NOT EXISTS idx_consultations_date (consultation_date),
ADD INDEX IF NOT EXISTS idx_consultations_consulted_by (consulted_by);

ALTER TABLE vitals
ADD INDEX IF NOT EXISTS idx_vitals_recorded_at (recorded_at),
ADD INDEX IF NOT EXISTS idx_vitals_patient_date (patient_id, recorded_at);

-- 6. Verify the changes (run these to check)
-- DESCRIBE consultations;
-- DESCRIBE vitals;
-- SHOW INDEX FROM consultations;
-- SHOW INDEX FROM vitals;