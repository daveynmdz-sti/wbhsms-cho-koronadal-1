-- Report Snapshots Schema for Historical Data Tracking
-- This allows periodic snapshots of demographics data for comparison

-- Table to store report snapshots metadata
CREATE TABLE report_snapshots (
    snapshot_id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_date DATE NOT NULL,
    snapshot_type ENUM('quarterly', 'semi_annual', 'annual', 'manual') NOT NULL,
    generated_by INT NOT NULL, -- employee_id who generated the snapshot
    total_patients INT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (generated_by) REFERENCES employees(employee_id),
    UNIQUE KEY unique_snapshot (snapshot_date, snapshot_type)
);

-- Table to store age distribution snapshots
CREATE TABLE snapshot_age_distribution (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT NOT NULL,
    age_group VARCHAR(50) NOT NULL,
    count INT NOT NULL,
    percentage DECIMAL(5,2) NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
);

-- Table to store gender distribution snapshots
CREATE TABLE snapshot_gender_distribution (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT NOT NULL,
    gender VARCHAR(10) NOT NULL,
    count INT NOT NULL,
    percentage DECIMAL(5,2) NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
);

-- Table to store district distribution snapshots
CREATE TABLE snapshot_district_distribution (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT NOT NULL,
    district_name VARCHAR(100) NOT NULL,
    count INT NOT NULL,
    percentage DECIMAL(5,2) NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
);

-- Table to store barangay distribution snapshots
CREATE TABLE snapshot_barangay_distribution (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT NOT NULL,
    barangay_name VARCHAR(100) NOT NULL,
    count INT NOT NULL,
    percentage DECIMAL(5,2) NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
);

-- Table to store PhilHealth distribution snapshots
CREATE TABLE snapshot_philhealth_distribution (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT NOT NULL,
    membership_type VARCHAR(50) NOT NULL,
    count INT NOT NULL,
    percentage DECIMAL(5,2) NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
);

-- Table to store PWD statistics snapshots
CREATE TABLE snapshot_pwd_statistics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    snapshot_id INT NOT NULL,
    pwd_count INT NOT NULL,
    pwd_percentage DECIMAL(5,2) NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
);

-- Indexes for better query performance
CREATE INDEX idx_snapshot_date ON report_snapshots(snapshot_date);
CREATE INDEX idx_snapshot_type ON report_snapshots(snapshot_type);
CREATE INDEX idx_age_snapshot ON snapshot_age_distribution(snapshot_id, age_group);
CREATE INDEX idx_gender_snapshot ON snapshot_gender_distribution(snapshot_id, gender);
CREATE INDEX idx_district_snapshot ON snapshot_district_distribution(snapshot_id, district_name);
CREATE INDEX idx_barangay_snapshot ON snapshot_barangay_distribution(snapshot_id, barangay_name);
CREATE INDEX idx_philhealth_snapshot ON snapshot_philhealth_distribution(snapshot_id, membership_type);