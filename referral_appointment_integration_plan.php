<?php
// Analysis of current system and proposed changes for referral-appointment integration
require_once 'config/db.php';

echo "<h1>Referral-Appointment Integration Analysis</h1>\n";

try {
    // Current referrals table structure
    echo "<h2>1. Current REFERRALS Table Structure</h2>\n";
    $stmt = $pdo->query("DESCRIBE referrals");
    $referrals_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>\n";
    foreach ($referrals_columns as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "<td>{$column['Extra']}</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";

    // Check if doctor schedules table exists
    echo "<h2>2. Doctor Schedule Tables (Current Status)</h2>\n";
    $stmt = $pdo->query("SHOW TABLES LIKE '%schedule%'");
    $schedule_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($schedule_tables)) {
        echo "<p>Found schedule-related tables:</p>\n";
        echo "<ul>\n";
        foreach ($schedule_tables as $table) {
            echo "<li>{$table}</li>\n";
            // Show structure of each schedule table
            $stmt = $pdo->query("DESCRIBE {$table}");
            $table_structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo "<details><summary>Structure of {$table}</summary>\n";
            echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>\n";
            echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th></tr>\n";
            foreach ($table_structure as $column) {
                echo "<tr><td>{$column['Field']}</td><td>{$column['Type']}</td><td>{$column['Null']}</td><td>{$column['Key']}</td></tr>\n";
            }
            echo "</table></details>\n";
        }
        echo "</ul>\n";
    } else {
        echo "<p><strong>No doctor schedule tables found!</strong> This will need to be created.</p>\n";
    }

    // First check employees table structure
    echo "<h2>3. Employees Table Structure</h2>\n";
    $stmt = $pdo->query("DESCRIBE employees");
    $employees_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>\n";
    foreach ($employees_columns as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "<td>{$column['Extra']}</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";

    // Find the primary key column
    $pk_column = null;
    foreach ($employees_columns as $column) {
        if ($column['Key'] === 'PRI') {
            $pk_column = $column['Field'];
            break;
        }
    }
    
    if (!$pk_column) {
        // Try common primary key names
        $possible_pks = ['employee_id', 'emp_id', 'user_id', 'id'];
        foreach ($possible_pks as $pk) {
            foreach ($employees_columns as $column) {
                if ($column['Field'] === $pk) {
                    $pk_column = $pk;
                    break 2;
                }
            }
        }
    }

    // Check for doctors in system
    echo "<h2>4. Doctors in System</h2>\n";
    
    if ($pk_column) {
        // Build dynamic query based on available columns
        $available_columns = array_column($employees_columns, 'Field');
        $select_columns = [$pk_column];
        
        // Add common name columns if they exist
        if (in_array('first_name', $available_columns)) $select_columns[] = 'first_name';
        if (in_array('last_name', $available_columns)) $select_columns[] = 'last_name';
        if (in_array('name', $available_columns)) $select_columns[] = 'name';
        if (in_array('position', $available_columns)) $select_columns[] = 'position';
        if (in_array('role', $available_columns)) $select_columns[] = 'role';
        
        $select_sql = implode(', ', $select_columns);
        
        // Build WHERE clause based on available columns
        $where_conditions = [];
        if (in_array('role', $available_columns)) {
            $where_conditions[] = "role = 'Doctor'";
            $where_conditions[] = "role LIKE '%doctor%'";
        }
        if (in_array('position', $available_columns)) {
            $where_conditions[] = "position LIKE '%doctor%'";
        }
        
        if (!empty($where_conditions)) {
            $where_sql = "WHERE " . implode(' OR ', $where_conditions);
            $order_sql = in_array('last_name', $available_columns) ? "ORDER BY last_name" : "ORDER BY " . $pk_column;
            
            $query = "SELECT {$select_sql} FROM employees {$where_sql} {$order_sql}";
            $stmt = $pdo->query($query);
            $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($doctors)) {
                echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
                echo "<tr>";
                foreach ($select_columns as $col) {
                    echo "<th>" . ucwords(str_replace('_', ' ', $col)) . "</th>";
                }
                echo "</tr>\n";
                foreach ($doctors as $doctor) {
                    echo "<tr>";
                    foreach ($select_columns as $col) {
                        echo "<td>" . htmlspecialchars($doctor[$col] ?? '') . "</td>";
                    }
                    echo "</tr>\n";
                }
                echo "</table>\n";
            } else {
                echo "<p>No doctors found based on role/position columns.</p>\n";
            }
        } else {
            echo "<p>No role or position columns found to filter doctors.</p>\n";
            
            // Show sample employees to understand structure
            echo "<h3>Sample Employees (first 5 records):</h3>\n";
            $stmt = $pdo->query("SELECT * FROM employees LIMIT 5");
            $sample_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($sample_employees)) {
                echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
                echo "<tr>";
                foreach (array_keys($sample_employees[0]) as $header) {
                    echo "<th>{$header}</th>";
                }
                echo "</tr>\n";
                foreach ($sample_employees as $row) {
                    echo "<tr>";
                    foreach ($row as $value) {
                        echo "<td>" . htmlspecialchars(substr($value ?? '', 0, 30)) . "</td>";
                    }
                    echo "</tr>\n";
                }
                echo "</table>\n";
            }
        }
    } else {
        echo "<p>Could not identify primary key column in employees table.</p>\n";
    }

    // Sample referrals to understand current data
    echo "<h2>5. Current Referrals Sample</h2>\n";
    $stmt = $pdo->query("SELECT * FROM referrals ORDER BY referral_date DESC LIMIT 5");
    $sample_referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($sample_referrals)) {
        echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>\n";
        echo "<tr>";
        foreach (array_keys($sample_referrals[0]) as $header) {
            echo "<th>{$header}</th>";
        }
        echo "</tr>\n";
        foreach ($sample_referrals as $row) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>" . htmlspecialchars(substr($value ?? '', 0, 50)) . "</td>";
            }
            echo "</tr>\n";
        }
        echo "</table>\n";
    }

} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>\n";
}

echo "<hr>";
echo "<h2>6. PROPOSED DATABASE CHANGES</h2>\n";

echo "<h3>A. Enhanced REFERRALS Table (Becomes Appointment System)</h3>\n";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>\n";
echo "-- Add appointment-related columns to referrals table
ALTER TABLE referrals ADD COLUMN IF NOT EXISTS appointment_date DATE;
ALTER TABLE referrals ADD COLUMN IF NOT EXISTS appointment_time TIME;
ALTER TABLE referrals ADD COLUMN IF NOT EXISTS doctor_id INT;
ALTER TABLE referrals ADD COLUMN IF NOT EXISTS schedule_slot_id INT;
ALTER TABLE referrals ADD COLUMN IF NOT EXISTS appointment_status ENUM('scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled', 'rescheduled') DEFAULT 'scheduled';
ALTER TABLE referrals ADD COLUMN IF NOT EXISTS checked_in_at TIMESTAMP NULL;
ALTER TABLE referrals ADD COLUMN IF NOT EXISTS appointment_notes TEXT;

-- Add indexes for performance
ALTER TABLE referrals ADD INDEX idx_appointment_date (appointment_date);
ALTER TABLE referrals ADD INDEX idx_doctor_id (doctor_id);
ALTER TABLE referrals ADD INDEX idx_appointment_status (appointment_status);
";
echo "</pre>\n";

echo "<h3>B. New DOCTOR_SCHEDULES Table</h3>\n";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>\n";
echo "-- Create doctor schedules table
CREATE TABLE IF NOT EXISTS doctor_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    day_of_week ENUM('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    appointment_duration INT NOT NULL DEFAULT 30, -- minutes per appointment
    max_patients_per_slot INT NOT NULL DEFAULT 1,
    is_active BOOLEAN DEFAULT TRUE,
    effective_date DATE NOT NULL,
    end_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (doctor_id) REFERENCES employees(id),
    INDEX idx_doctor_day (doctor_id, day_of_week),
    INDEX idx_effective_dates (effective_date, end_date)
);
";
echo "</pre>\n";

echo "<h3>C. New DOCTOR_SCHEDULE_SLOTS Table (Time Slot Management)</h3>\n";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>\n";
echo "-- Create specific time slots for appointments
CREATE TABLE IF NOT EXISTS doctor_schedule_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_schedule_id INT NOT NULL,
    slot_date DATE NOT NULL,
    slot_time TIME NOT NULL,
    is_available BOOLEAN DEFAULT TRUE,
    max_patients INT NOT NULL DEFAULT 1,
    current_bookings INT NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (doctor_schedule_id) REFERENCES doctor_schedules(id),
    UNIQUE KEY unique_doctor_slot (doctor_schedule_id, slot_date, slot_time),
    INDEX idx_slot_date (slot_date),
    INDEX idx_availability (is_available, slot_date)
);
";
echo "</pre>\n";

echo "<h3>D. New DOCTOR_AVAILABILITY_EXCEPTIONS Table</h3>\n";
echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>\n";
echo "-- Handle doctor leaves, holidays, special schedules
CREATE TABLE IF NOT EXISTS doctor_availability_exceptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    exception_date DATE NOT NULL,
    exception_type ENUM('unavailable', 'modified_hours', 'special_schedule') NOT NULL,
    start_time TIME NULL, -- for modified hours
    end_time TIME NULL,   -- for modified hours
    reason VARCHAR(255) NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (doctor_id) REFERENCES employees(id),
    FOREIGN KEY (created_by) REFERENCES employees(id),
    INDEX idx_doctor_date (doctor_id, exception_date)
);
";
echo "</pre>\n";

echo "<h2>7. WORKFLOW CHANGES</h2>\n";
echo "<ol>\n";
echo "<li><strong>Referral Creation Process:</strong>\n";
echo "   <ul>\n";
echo "   <li>Staff selects doctor for referral</li>\n";
echo "   <li>System shows available time slots based on doctor's schedule</li>\n";
echo "   <li>Staff assigns specific date/time during referral creation</li>\n";
echo "   <li>Patient receives referral with appointment details included</li>\n";
echo "   </ul>\n";
echo "</li>\n";
echo "<li><strong>Patient Experience:</strong>\n";
echo "   <ul>\n";
echo "   <li>Patient receives referral with appointment date/time</li>\n";
echo "   <li>Patient arrives on scheduled date/time (no separate booking needed)</li>\n";
echo "   <li>QR code includes appointment verification</li>\n";
echo "   </ul>\n";
echo "</li>\n";
echo "<li><strong>Queue Management Integration:</strong>\n";
echo "   <ul>\n";
echo "   <li>Queue system reads appointment_date and appointment_time from referrals</li>\n";
echo "   <li>Automatic queue positioning based on appointment schedule</li>\n";
echo "   <li>Doctor can see their scheduled patients for the day</li>\n";
echo "   </ul>\n";
echo "</li>\n";
echo "</ol>\n";

echo "<h2>8. CODE CHANGES REQUIRED</h2>\n";
echo "<ul>\n";
echo "<li><strong>Remove:</strong> Patient appointment booking pages</li>\n";
echo "<li><strong>Modify:</strong> Referral creation to include doctor selection and scheduling</li>\n";
echo "<li><strong>Add:</strong> Doctor schedule management interface</li>\n";
echo "<li><strong>Update:</strong> Queue system to read from enhanced referrals table</li>\n";
echo "<li><strong>Update:</strong> Patient portal to show appointment details from referral</li>\n";
echo "</ul>\n";

?>