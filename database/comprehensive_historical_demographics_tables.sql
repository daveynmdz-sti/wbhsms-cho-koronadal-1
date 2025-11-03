-- ============================================================================
-- COMPREHENSIVE HISTORICAL DEMOGRAPHICS TABLES
-- Enhanced Cross-Tabulation Tables for Complete Data Capture
-- Run these queries to upgrade the historical demographics system
-- ============================================================================

-- Create the new comprehensive cross-tabulation tables
-- These tables will capture the same detailed data as the full Patient Demographics Report

-- 1. Age by District Cross-Tabulation Table
CREATE TABLE IF NOT EXISTS snapshot_age_by_district (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT NOT NULL,
    district_name VARCHAR(100) NOT NULL,
    age_group VARCHAR(50) NOT NULL,
    count INT NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
);

-- 2. Age by Barangay Cross-Tabulation Table
CREATE TABLE IF NOT EXISTS snapshot_age_by_barangay (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT NOT NULL,
    barangay_name VARCHAR(100) NOT NULL,
    age_group VARCHAR(50) NOT NULL,
    count INT NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
);

-- 3. Gender by District Cross-Tabulation Table
CREATE TABLE IF NOT EXISTS snapshot_gender_by_district (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT NOT NULL,
    district_name VARCHAR(100) NOT NULL,
    gender VARCHAR(10) NOT NULL,
    count INT NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
);

-- 4. Gender by Barangay Cross-Tabulation Table
CREATE TABLE IF NOT EXISTS snapshot_gender_by_barangay (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT NOT NULL,
    barangay_name VARCHAR(100) NOT NULL,
    gender VARCHAR(10) NOT NULL,
    count INT NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
);

-- 5. PhilHealth by District Cross-Tabulation Table
CREATE TABLE IF NOT EXISTS snapshot_philhealth_by_district (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT NOT NULL,
    district_name VARCHAR(100) NOT NULL,
    philhealth_type VARCHAR(50) NOT NULL,
    count INT NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
);

-- 6. PhilHealth by Barangay Cross-Tabulation Table
CREATE TABLE IF NOT EXISTS snapshot_philhealth_by_barangay (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT NOT NULL,
    barangay_name VARCHAR(100) NOT NULL,
    philhealth_type VARCHAR(50) NOT NULL,
    count INT NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
);

-- ============================================================================
-- CREATE PERFORMANCE INDEXES
-- These indexes will improve query performance for cross-tabulation data
-- ============================================================================

-- Indexes for Age by District
CREATE INDEX IF NOT EXISTS idx_age_by_district ON snapshot_age_by_district(snapshot_id, district_name, age_group);

-- Indexes for Age by Barangay
CREATE INDEX IF NOT EXISTS idx_age_by_barangay ON snapshot_age_by_barangay(snapshot_id, barangay_name, age_group);

-- Indexes for Gender by District
CREATE INDEX IF NOT EXISTS idx_gender_by_district ON snapshot_gender_by_district(snapshot_id, district_name, gender);

-- Indexes for Gender by Barangay
CREATE INDEX IF NOT EXISTS idx_gender_by_barangay ON snapshot_gender_by_barangay(snapshot_id, barangay_name, gender);

-- Indexes for PhilHealth by District
CREATE INDEX IF NOT EXISTS idx_philhealth_by_district ON snapshot_philhealth_by_district(snapshot_id, district_name, philhealth_type);

-- Indexes for PhilHealth by Barangay
CREATE INDEX IF NOT EXISTS idx_philhealth_by_barangay ON snapshot_philhealth_by_barangay(snapshot_id, barangay_name, philhealth_type);

-- ============================================================================
-- VERIFICATION QUERIES
-- Run these to verify the tables were created successfully
-- ============================================================================

-- Check if all new tables exist
SELECT 
    TABLE_NAME,
    TABLE_ROWS,
    CREATE_TIME
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'wbhsms_database' 
    AND TABLE_NAME LIKE 'snapshot_%'
ORDER BY TABLE_NAME;

-- Check if all indexes were created
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    COLUMN_NAME
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = 'wbhsms_database' 
    AND TABLE_NAME LIKE 'snapshot_%'
ORDER BY TABLE_NAME, INDEX_NAME;

-- ============================================================================
-- SAMPLE DATA VERIFICATION (Optional)
-- Use these queries after generating your first snapshot to verify data
-- ============================================================================

-- Count records in each cross-tabulation table for a specific snapshot
-- Replace 'X' with an actual snapshot_id after generating a snapshot
/*
SELECT 
    'Age by District' as table_type,
    COUNT(*) as record_count
FROM snapshot_age_by_district 
WHERE snapshot_id = X

UNION ALL

SELECT 
    'Age by Barangay' as table_type,
    COUNT(*) as record_count
FROM snapshot_age_by_barangay 
WHERE snapshot_id = X

UNION ALL

SELECT 
    'Gender by District' as table_type,
    COUNT(*) as record_count
FROM snapshot_gender_by_district 
WHERE snapshot_id = X

UNION ALL

SELECT 
    'Gender by Barangay' as table_type,
    COUNT(*) as record_count
FROM snapshot_gender_by_barangay 
WHERE snapshot_id = X

UNION ALL

SELECT 
    'PhilHealth by District' as table_type,
    COUNT(*) as record_count
FROM snapshot_philhealth_by_district 
WHERE snapshot_id = X

UNION ALL

SELECT 
    'PhilHealth by Barangay' as table_type,
    COUNT(*) as record_count
FROM snapshot_philhealth_by_barangay 
WHERE snapshot_id = X;
*/

-- ============================================================================
-- COMPLETION MESSAGE
-- ============================================================================

SELECT 'Comprehensive Historical Demographics Tables Created Successfully!' as status;