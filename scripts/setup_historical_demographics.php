<?php
/**
 * Setup Script for Historical Demographics Tables
 * Run this once to create the necessary database tables for snapshot functionality
 */

require_once dirname(__DIR__) . '/config/db.php';

$queries = [
    "CREATE TABLE IF NOT EXISTS report_snapshots (
        snapshot_id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_date DATE NOT NULL,
        snapshot_type ENUM('quarterly', 'semi_annual', 'annual', 'manual') NOT NULL,
        generated_by INT NOT NULL,
        total_patients INT NOT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (generated_by) REFERENCES employees(employee_id),
        UNIQUE KEY unique_snapshot (snapshot_date, snapshot_type)
    )",
    
    "CREATE TABLE IF NOT EXISTS snapshot_age_distribution (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_id INT NOT NULL,
        age_group VARCHAR(50) NOT NULL,
        count INT NOT NULL,
        percentage DECIMAL(5,2) NOT NULL,
        FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE IF NOT EXISTS snapshot_gender_distribution (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_id INT NOT NULL,
        gender VARCHAR(10) NOT NULL,
        count INT NOT NULL,
        percentage DECIMAL(5,2) NOT NULL,
        FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE IF NOT EXISTS snapshot_district_distribution (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_id INT NOT NULL,
        district_name VARCHAR(100) NOT NULL,
        count INT NOT NULL,
        percentage DECIMAL(5,2) NOT NULL,
        FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE IF NOT EXISTS snapshot_barangay_distribution (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_id INT NOT NULL,
        barangay_name VARCHAR(100) NOT NULL,
        count INT NOT NULL,
        percentage DECIMAL(5,2) NOT NULL,
        FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE IF NOT EXISTS snapshot_philhealth_distribution (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_id INT NOT NULL,
        membership_type VARCHAR(50) NOT NULL,
        count INT NOT NULL,
        percentage DECIMAL(5,2) NOT NULL,
        FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE IF NOT EXISTS snapshot_pwd_statistics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_id INT NOT NULL,
        pwd_count INT NOT NULL,
        pwd_percentage DECIMAL(5,2) NOT NULL,
        FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
    )",
    
    // NEW COMPREHENSIVE CROSS-TABULATION TABLES
    "CREATE TABLE IF NOT EXISTS snapshot_age_by_district (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_id INT NOT NULL,
        district_name VARCHAR(100) NOT NULL,
        age_group VARCHAR(50) NOT NULL,
        count INT NOT NULL,
        FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE IF NOT EXISTS snapshot_age_by_barangay (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_id INT NOT NULL,
        barangay_name VARCHAR(100) NOT NULL,
        age_group VARCHAR(50) NOT NULL,
        count INT NOT NULL,
        FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE IF NOT EXISTS snapshot_gender_by_district (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_id INT NOT NULL,
        district_name VARCHAR(100) NOT NULL,
        gender VARCHAR(10) NOT NULL,
        count INT NOT NULL,
        FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE IF NOT EXISTS snapshot_gender_by_barangay (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_id INT NOT NULL,
        barangay_name VARCHAR(100) NOT NULL,
        gender VARCHAR(10) NOT NULL,
        count INT NOT NULL,
        FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE IF NOT EXISTS snapshot_philhealth_by_district (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_id INT NOT NULL,
        district_name VARCHAR(100) NOT NULL,
        philhealth_type VARCHAR(50) NOT NULL,
        count INT NOT NULL,
        FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
    )",
    
    "CREATE TABLE IF NOT EXISTS snapshot_philhealth_by_barangay (
        id INT AUTO_INCREMENT PRIMARY KEY,
        snapshot_id INT NOT NULL,
        barangay_name VARCHAR(100) NOT NULL,
        philhealth_type VARCHAR(50) NOT NULL,
        count INT NOT NULL,
        FOREIGN KEY (snapshot_id) REFERENCES report_snapshots(snapshot_id) ON DELETE CASCADE
    )"
];

$indexes = [
    "CREATE INDEX IF NOT EXISTS idx_snapshot_date ON report_snapshots(snapshot_date)",
    "CREATE INDEX IF NOT EXISTS idx_snapshot_type ON report_snapshots(snapshot_type)",
    "CREATE INDEX IF NOT EXISTS idx_age_snapshot ON snapshot_age_distribution(snapshot_id, age_group)",
    "CREATE INDEX IF NOT EXISTS idx_gender_snapshot ON snapshot_gender_distribution(snapshot_id, gender)",
    "CREATE INDEX IF NOT EXISTS idx_district_snapshot ON snapshot_district_distribution(snapshot_id, district_name)",
    "CREATE INDEX IF NOT EXISTS idx_barangay_snapshot ON snapshot_barangay_distribution(snapshot_id, barangay_name)",
    "CREATE INDEX IF NOT EXISTS idx_philhealth_snapshot ON snapshot_philhealth_distribution(snapshot_id, membership_type)",
    // NEW CROSS-TABULATION INDEXES
    "CREATE INDEX IF NOT EXISTS idx_age_by_district ON snapshot_age_by_district(snapshot_id, district_name, age_group)",
    "CREATE INDEX IF NOT EXISTS idx_age_by_barangay ON snapshot_age_by_barangay(snapshot_id, barangay_name, age_group)",
    "CREATE INDEX IF NOT EXISTS idx_gender_by_district ON snapshot_gender_by_district(snapshot_id, district_name, gender)",
    "CREATE INDEX IF NOT EXISTS idx_gender_by_barangay ON snapshot_gender_by_barangay(snapshot_id, barangay_name, gender)",
    "CREATE INDEX IF NOT EXISTS idx_philhealth_by_district ON snapshot_philhealth_by_district(snapshot_id, district_name, philhealth_type)",
    "CREATE INDEX IF NOT EXISTS idx_philhealth_by_barangay ON snapshot_philhealth_by_barangay(snapshot_id, barangay_name, philhealth_type)"
];

echo "<!DOCTYPE html>";
echo "<html><head><title>Historical Demographics Setup</title>";
echo "<style>body{font-family:Arial,sans-serif;margin:40px;background:#f8f9fa;}";
echo ".container{max-width:800px;margin:0 auto;background:white;padding:30px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);}";
echo ".success{color:#06d6a0;background:#d1e7dd;padding:10px;border-radius:5px;margin:10px 0;}";
echo ".error{color:#f72585;background:#f8d7da;padding:10px;border-radius:5px;margin:10px 0;}";
echo ".info{color:#0077b6;background:#d1ecf1;padding:10px;border-radius:5px;margin:10px 0;}";
echo "h1{color:#0077b6;text-align:center;}";
echo "</style></head><body>";

echo "<div class='container'>";
echo "<h1>Historical Demographics Setup</h1>";

try {
    echo "<div class='info'>Setting up database tables for historical demographics tracking...</div>";
    
    // Create tables
    foreach ($queries as $i => $query) {
        $tableName = '';
        if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/', $query, $matches)) {
            $tableName = $matches[1];
        }
        
        if ($conn->query($query) === TRUE) {
            echo "<div class='success'>✓ Table '$tableName' created successfully</div>";
        } else {
            echo "<div class='error'>✗ Error creating table '$tableName': " . $conn->error . "</div>";
        }
    }
    
    // Create indexes
    echo "<br><div class='info'>Creating database indexes for better performance...</div>";
    foreach ($indexes as $index) {
        if ($conn->query($index) === TRUE) {
            echo "<div class='success'>✓ Index created successfully</div>";
        } else {
            echo "<div class='error'>✗ Error creating index: " . $conn->error . "</div>";
        }
    }
    
    echo "<br><div class='success'>";
    echo "<strong>Setup Complete!</strong><br>";
    echo "The historical demographics system is now ready to use.<br><br>";
    echo "<strong>Next Steps:</strong><br>";
    echo "1. Go to the Demographics Report page<br>";
    echo "2. Click on 'Historical Data' to access the new functionality<br>";
    echo "3. Generate your first snapshot to start tracking trends<br>";
    echo "</div>";
    
    echo "<div style='text-align:center;margin-top:30px;'>";
    echo "<a href='../pages/reports/patient_demographics.php' style='background:#0077b6;color:white;padding:12px 24px;text-decoration:none;border-radius:6px;'>Go to Demographics Report</a> ";
    echo "<a href='../pages/reports/historical_demographics.php' style='background:#00b4d8;color:white;padding:12px 24px;text-decoration:none;border-radius:6px;margin-left:10px;'>Go to Historical Data</a>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<strong>Setup Failed!</strong><br>";
    echo "Error: " . $e->getMessage();
    echo "</div>";
}

echo "</div>";
echo "</body></html>";

$conn->close();
?>