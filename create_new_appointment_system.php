<?php
// NEW REFERRAL-APPOINTMENT SYSTEM - CREATES NEW TABLES ONLY
// This script creates new tables for the integrated referral-appointment system
// while keeping your existing tables as backup

require_once 'config/db.php';

echo "<h1>NEW Referral-Appointment Integration System</h1>\n";
echo "<p><strong>SAFE APPROACH:</strong> Creates new tables while preserving existing ones</p>\n";

try {
    // Check current employees table structure to understand FK references
    echo "<h2>1. Current System Analysis</h2>\n";
    $stmt = $pdo->query("DESCRIBE employees");
    $employees_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Find the primary key column for employees
    $employee_pk = null;
    foreach ($employees_columns as $column) {
        if ($column['Key'] === 'PRI') {
            $employee_pk = $column['Field'];
            break;
        }
    }
    
    // If no primary key found, try common names
    if (!$employee_pk) {
        $possible_pks = ['employee_id', 'emp_id', 'user_id', 'id'];
        foreach ($possible_pks as $pk) {
            foreach ($employees_columns as $column) {
                if ($column['Field'] === $pk) {
                    $employee_pk = $pk;
                    break 2;
                }
            }
        }
    }
    
    echo "<p><strong>Employee table primary key detected:</strong> <code>{$employee_pk}</code></p>\n";
    
    // Check if new tables already exist
    echo "<h3>Checking for existing new tables:</h3>\n";
    $new_tables = ['appointment_referrals', 'doctor_schedules_v2', 'doctor_schedule_slots_v2', 'doctor_availability_exceptions_v2'];
    
    foreach ($new_tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        $exists = $stmt->fetch();
        if ($exists) {
            echo "<p style='color: orange;'>⚠️ Table <strong>{$table}</strong> already exists</p>\n";
        } else {
            echo "<p style='color: green;'>✅ Table <strong>{$table}</strong> ready to create</p>\n";
        }
    }

} catch (Exception $e) {
    echo "<p style='color: red;'>Error analyzing current system: " . $e->getMessage() . "</p>\n";
    $employee_pk = 'id'; // fallback
}

echo "<hr>";
echo "<h2>2. NEW TABLE CREATION SCRIPTS</h2>\n";
echo "<p><em>Copy and run these SQL commands in your database to create the new system</em></p>\n";

// 1. Main appointment-referrals table (replaces both appointments and enhanced referrals)
echo "<h3>A. APPOINTMENT_REFERRALS Table (Main integrated table)</h3>\n";
echo "<textarea style='width: 100%; height: 400px; font-family: monospace; background: #f5f5f5; padding: 10px;'>";
echo "-- =============================================================================
-- APPOINTMENT_REFERRALS: Integrated referral-appointment system
-- Combines referral information with scheduled appointment details
-- =============================================================================
CREATE TABLE appointment_referrals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- REFERRAL INFORMATION (from old referrals table)
    referral_number VARCHAR(50) NOT NULL UNIQUE,
    patient_id INT NOT NULL,
    referred_by_employee_id INT NOT NULL,
    referring_facility_id INT,
    referred_to_facility_id INT NOT NULL,
    referral_reason TEXT NOT NULL,
    diagnosis TEXT,
    recommendations TEXT,
    urgency_level ENUM('routine', 'urgent', 'emergency') DEFAULT 'routine',
    
    -- APPOINTMENT SCHEDULING (NEW)
    assigned_doctor_id INT NOT NULL,
    appointment_date DATE NOT NULL,
    appointment_time TIME NOT NULL,
    appointment_duration INT DEFAULT 30, -- minutes
    appointment_status ENUM('scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'rescheduled', 'no_show') DEFAULT 'scheduled',
    
    -- APPOINTMENT MANAGEMENT
    schedule_slot_id INT NULL, -- links to doctor_schedule_slots_v2
    checked_in_at TIMESTAMP NULL,
    appointment_started_at TIMESTAMP NULL,
    appointment_completed_at TIMESTAMP NULL,
    rescheduled_from_id INT NULL, -- self-reference for rescheduled appointments
    cancellation_reason TEXT NULL,
    appointment_notes TEXT NULL,
    
    -- METADATA
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_employee_id INT NOT NULL,
    last_updated_by_employee_id INT,
    
    -- FOREIGN KEYS (adjust employee reference as needed)
    FOREIGN KEY (patient_id) REFERENCES patients(patient_id),
    FOREIGN KEY (referred_by_employee_id) REFERENCES employees({$employee_pk}),
    FOREIGN KEY (assigned_doctor_id) REFERENCES employees({$employee_pk}),
    FOREIGN KEY (created_by_employee_id) REFERENCES employees({$employee_pk}),
    FOREIGN KEY (last_updated_by_employee_id) REFERENCES employees({$employee_pk}),
    FOREIGN KEY (rescheduled_from_id) REFERENCES appointment_referrals(id),
    
    -- INDEXES FOR PERFORMANCE
    INDEX idx_patient_id (patient_id),
    INDEX idx_doctor_id (assigned_doctor_id),
    INDEX idx_appointment_date (appointment_date),
    INDEX idx_appointment_status (appointment_status),
    INDEX idx_referral_number (referral_number),
    INDEX idx_doctor_date (assigned_doctor_id, appointment_date),
    INDEX idx_appointment_datetime (appointment_date, appointment_time),
    
    -- UNIQUE CONSTRAINTS
    UNIQUE KEY unique_doctor_datetime (assigned_doctor_id, appointment_date, appointment_time)
);";
echo "</textarea>\n";

// 2. Doctor schedules
echo "<h3>B. DOCTOR_SCHEDULES_V2 Table (Doctor availability patterns)</h3>\n";
echo "<textarea style='width: 100%; height: 300px; font-family: monospace; background: #f5f5f5; padding: 10px;'>";
echo "-- =============================================================================
-- DOCTOR_SCHEDULES_V2: Weekly recurring schedules for doctors
-- =============================================================================
CREATE TABLE doctor_schedules_v2 (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    schedule_name VARCHAR(100) NOT NULL, -- e.g., 'Regular Schedule', 'Holiday Schedule'
    day_of_week ENUM('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    appointment_duration INT NOT NULL DEFAULT 30, -- minutes per slot
    break_start_time TIME NULL, -- lunch break start
    break_end_time TIME NULL,   -- lunch break end
    max_patients_per_slot INT NOT NULL DEFAULT 1,
    is_active BOOLEAN DEFAULT TRUE,
    effective_from DATE NOT NULL,
    effective_until DATE NULL, -- NULL means ongoing
    notes TEXT NULL,
    
    -- METADATA
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_employee_id INT NOT NULL,
    
    -- FOREIGN KEYS
    FOREIGN KEY (doctor_id) REFERENCES employees({$employee_pk}),
    FOREIGN KEY (created_by_employee_id) REFERENCES employees({$employee_pk}),
    
    -- INDEXES
    INDEX idx_doctor_id (doctor_id),
    INDEX idx_doctor_day (doctor_id, day_of_week),
    INDEX idx_effective_dates (effective_from, effective_until),
    INDEX idx_active_schedules (is_active, doctor_id),
    
    -- ENSURE NO OVERLAPPING TIMES FOR SAME DOCTOR/DAY
    UNIQUE KEY unique_doctor_day_schedule (doctor_id, day_of_week, start_time, effective_from)
);";
echo "</textarea>\n";

// 3. Specific time slots
echo "<h3>C. DOCTOR_SCHEDULE_SLOTS_V2 Table (Specific available time slots)</h3>\n";
echo "<textarea style='width: 100%; height: 250px; font-family: monospace; background: #f5f5f5; padding: 10px;'>";
echo "-- =============================================================================
-- DOCTOR_SCHEDULE_SLOTS_V2: Individual appointment slots (auto-generated from schedules)
-- =============================================================================
CREATE TABLE doctor_schedule_slots_v2 (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_schedule_id INT NOT NULL,
    doctor_id INT NOT NULL, -- denormalized for faster queries
    slot_date DATE NOT NULL,
    slot_time TIME NOT NULL,
    slot_end_time TIME NOT NULL,
    is_available BOOLEAN DEFAULT TRUE,
    is_booked BOOLEAN DEFAULT FALSE,
    max_patients INT NOT NULL DEFAULT 1,
    current_bookings INT NOT NULL DEFAULT 0,
    slot_notes TEXT NULL,
    
    -- METADATA
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- FOREIGN KEYS
    FOREIGN KEY (doctor_schedule_id) REFERENCES doctor_schedules_v2(id),
    FOREIGN KEY (doctor_id) REFERENCES employees({$employee_pk}),
    
    -- INDEXES
    INDEX idx_doctor_date (doctor_id, slot_date),
    INDEX idx_availability (is_available, slot_date),
    INDEX idx_slot_datetime (slot_date, slot_time),
    
    -- UNIQUE CONSTRAINT
    UNIQUE KEY unique_doctor_slot_time (doctor_id, slot_date, slot_time)
);";
echo "</textarea>\n";

// 4. Exceptions and special cases
echo "<h3>D. DOCTOR_AVAILABILITY_EXCEPTIONS_V2 Table (Leaves, holidays, special cases)</h3>\n";
echo "<textarea style='width: 100%; height: 250px; font-family: monospace; background: #f5f5f5; padding: 10px;'>";
echo "-- =============================================================================
-- DOCTOR_AVAILABILITY_EXCEPTIONS_V2: Handle special cases, leaves, holidays
-- =============================================================================
CREATE TABLE doctor_availability_exceptions_v2 (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    exception_date DATE NOT NULL,
    exception_type ENUM('unavailable', 'partial_day', 'modified_schedule', 'emergency_available') NOT NULL,
    start_time TIME NULL, -- for partial day exceptions
    end_time TIME NULL,
    replacement_doctor_id INT NULL, -- who covers this doctor
    reason VARCHAR(255) NOT NULL,
    is_recurring BOOLEAN DEFAULT FALSE,
    recurrence_pattern ENUM('weekly', 'monthly', 'yearly') NULL,
    status ENUM('active', 'cancelled') DEFAULT 'active',
    
    -- METADATA  
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by_employee_id INT NOT NULL,
    approved_by_employee_id INT NULL,
    approved_at TIMESTAMP NULL,
    
    -- FOREIGN KEYS
    FOREIGN KEY (doctor_id) REFERENCES employees({$employee_pk}),
    FOREIGN KEY (replacement_doctor_id) REFERENCES employees({$employee_pk}),
    FOREIGN KEY (created_by_employee_id) REFERENCES employees({$employee_pk}),
    FOREIGN KEY (approved_by_employee_id) REFERENCES employees({$employee_pk}),
    
    -- INDEXES
    INDEX idx_doctor_date (doctor_id, exception_date),
    INDEX idx_exception_date (exception_date),
    INDEX idx_replacement_doctor (replacement_doctor_id)
);";
echo "</textarea>\n";

echo "<hr>";
echo "<h2>3. DATA MIGRATION HELPER SCRIPT</h2>\n";
echo "<p>After creating the new tables, use this script to migrate existing referral data:</p>\n";

echo "<textarea style='width: 100%; height: 200px; font-family: monospace; background: #fff3cd; padding: 10px;'>";
echo "-- =============================================================================
-- MIGRATION SCRIPT: Move existing referrals to new appointment_referrals table
-- =============================================================================

-- Insert existing referrals as unscheduled appointments
INSERT INTO appointment_referrals (
    referral_number,
    patient_id, 
    referred_by_employee_id,
    referred_to_facility_id,
    referral_reason,
    diagnosis,
    recommendations,
    urgency_level,
    assigned_doctor_id, -- YOU'LL NEED TO ASSIGN DEFAULT DOCTOR
    appointment_date,   -- YOU'LL NEED TO SET DEFAULT DATE
    appointment_time,   -- YOU'LL NEED TO SET DEFAULT TIME  
    appointment_status,
    created_at,
    created_by_employee_id
)
SELECT 
    referral_code as referral_number,
    patient_id,
    referred_by_id as referred_by_employee_id,
    referred_to_facility_id,
    reason as referral_reason,
    diagnosis,
    recommendations,
    'routine' as urgency_level,
    1 as assigned_doctor_id, -- REPLACE WITH ACTUAL DOCTOR ID
    CURDATE() as appointment_date, -- REPLACE WITH LOGIC FOR SCHEDULING
    '09:00:00' as appointment_time, -- REPLACE WITH LOGIC FOR TIME SLOTS
    'scheduled' as appointment_status,
    referral_date as created_at,
    referred_by_id as created_by_employee_id
FROM referrals 
WHERE status = 'approved'; -- Only migrate approved referrals";
echo "</textarea>\n";

echo "<hr>";
echo "<h2>4. ADVANTAGES OF THIS NEW SYSTEM</h2>\n";
echo "<ol>\n";
echo "<li><strong>Backup Safety:</strong> Your old referrals and appointments tables remain untouched</li>\n";
echo "<li><strong>Integrated Workflow:</strong> Single table for referral + appointment data</li>\n";
echo "<li><strong>Doctor-Centric:</strong> All scheduling based on actual doctor availability</li>\n";
echo "<li><strong>Flexible Scheduling:</strong> Handles recurring schedules, exceptions, and special cases</li>\n";
echo "<li><strong>Queue Integration Ready:</strong> Designed to work with your existing queue system</li>\n";
echo "<li><strong>Gradual Migration:</strong> You can test the new system while keeping the old one running</li>\n";
echo "</ol>\n";

echo "<h2>5. IMPLEMENTATION STEPS</h2>\n";
echo "<ol>\n";
echo "<li><strong>Create Tables:</strong> Run the SQL scripts above</li>\n";
echo "<li><strong>Build Doctor Schedule Interface:</strong> Let admins set up doctor schedules</li>\n";
echo "<li><strong>Create New Referral Form:</strong> Include doctor selection and appointment scheduling</li>\n";
echo "<li><strong>Update Queue System:</strong> Read from appointment_referrals table</li>\n";
echo "<li><strong>Test & Validate:</strong> Run both systems in parallel</li>\n";
echo "<li><strong>Migrate Data:</strong> Move approved referrals to new system</li>\n";
echo "<li><strong>Switch Over:</strong> Point all interfaces to new tables</li>\n";
echo "</ol>\n";

echo "<p><strong>Would you like me to create the PHP interfaces for managing these new tables?</strong></p>\n";

?>