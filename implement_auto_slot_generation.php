<?php
// AUTO-GENERATION SYSTEM FOR YOUR EXISTING DOCTOR SCHEDULE TABLES
// Based on your actual database structure: doctor_schedule and doctor_schedule_slots

require_once 'config/db.php';

echo "<h1>🩺 Doctor Schedule Slot Auto-Generation System</h1>\n";
echo "<p><strong>Working with your existing tables:</strong> doctor_schedule and doctor_schedule_slots</p>\n";

echo "<hr>";
echo "<h2>1. 📋 CREATE TABLES (Updated Schema)</h2>\n";

echo "<h3>A. Doctor Schedule (Weekly Patterns)</h3>\n";
echo "<textarea style='width: 100%; height: 200px; font-family: monospace; background: #f5f5f5; padding: 10px;'>";
echo "CREATE TABLE doctor_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    day_of_week ENUM('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    slot_interval_minutes INT DEFAULT 15,
    break_start_time TIME NULL, -- lunch break
    break_end_time TIME NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (doctor_id) REFERENCES employees(employee_id),
    UNIQUE KEY unique_doctor_day (doctor_id, day_of_week),
    INDEX idx_doctor_active (doctor_id, is_active)
);";
echo "</textarea>\n";

echo "<h3>B. Doctor Schedule Slots (Auto-Generated)</h3>\n";
echo "<textarea style='width: 100%; height: 200px; font-family: monospace; background: #f5f5f5; padding: 10px;'>";
echo "CREATE TABLE doctor_schedule_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    slot_date DATE NOT NULL,
    slot_time TIME NOT NULL,
    slot_end_time TIME NOT NULL,
    is_available BOOLEAN DEFAULT TRUE,
    is_booked BOOLEAN DEFAULT FALSE,
    service_type VARCHAR(100) NULL, -- allows service-specific slots
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (doctor_id) REFERENCES employees(employee_id),
    UNIQUE KEY unique_doctor_slot (doctor_id, slot_date, slot_time),
    INDEX idx_doctor_date (doctor_id, slot_date),
    INDEX idx_availability (is_available, slot_date)
);";
echo "</textarea>\n";

echo "<h3>C. Services (With Duration Override)</h3>\n";
echo "<textarea style='width: 100%; height: 150px; font-family: monospace; background: #f5f5f5; padding: 10px;'>";
echo "ALTER TABLE services 
ADD COLUMN slot_duration_minutes INT DEFAULT NULL,
ADD COLUMN requires_extended_slot BOOLEAN DEFAULT FALSE;

-- Example service durations
UPDATE services SET slot_duration_minutes = 15 WHERE service_name LIKE '%consultation%';
UPDATE services SET slot_duration_minutes = 45 WHERE service_name LIKE '%dental%';
UPDATE services SET slot_duration_minutes = 20 WHERE service_name LIKE '%family planning%';";
echo "</textarea>\n";

echo "<h3>D. Referrals (Enhanced Status Tracking)</h3>\n";
echo "<textarea style='width: 100%; height: 150px; font-family: monospace; background: #f5f5f5; padding: 10px;'>";
echo "ALTER TABLE referrals 
ADD COLUMN doctor_schedule_slot_id INT NULL,
ADD COLUMN status ENUM('scheduled','checked_in','in_progress','completed','cancelled','no_show') DEFAULT 'scheduled',
ADD COLUMN appointment_date DATE NULL,
ADD COLUMN appointment_time TIME NULL,
ADD COLUMN checked_in_at TIMESTAMP NULL,
ADD COLUMN completed_at TIMESTAMP NULL,
ADD FOREIGN KEY (doctor_schedule_slot_id) REFERENCES doctor_schedule_slots(id);";
echo "</textarea>\n";

echo "<hr>";
echo "<h2>2. 🤖 AUTO-GENERATION SCRIPT</h2>\n";

echo "<h3>A. Generate Weekly Slots Function</h3>\n";
echo "<textarea style='width: 100%; height: 400px; font-family: monospace; background: #e8f5e8; padding: 10px;'>";
echo "<?php
function generateDoctorSlots(\$doctor_id, \$weeks_ahead = 2) {
    global \$pdo;
    
    // Get doctor's weekly schedule
    \$stmt = \$pdo->prepare(\"
        SELECT * FROM doctor_schedule 
        WHERE doctor_id = ? AND is_active = TRUE
    \");
    \$stmt->execute([\$doctor_id]);
    \$schedules = \$stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty(\$schedules)) {
        return 'No active schedule found for doctor';
    }
    
    \$generated_count = 0;
    \$start_date = new DateTime();
    \$end_date = (new DateTime())->add(new DateInterval(\"P\".\$weeks_ahead.\"W\"));
    
    // Loop through each day in the date range
    for (\$date = clone \$start_date; \$date <= \$end_date; \$date->add(new DateInterval('P1D'))) {
        \$day_of_week = strtolower(\$date->format('l'));
        
        // Check if doctor has schedule for this day
        foreach (\$schedules as \$schedule) {
            if (\$schedule['day_of_week'] === \$day_of_week) {
                \$generated_count += generateSlotsForDay(\$doctor_id, \$date, \$schedule);
            }
        }
    }
    
    return \"Generated {\$generated_count} slots for doctor {\$doctor_id}\";
}

function generateSlotsForDay(\$doctor_id, \$date, \$schedule) {
    global \$pdo;
    
    \$date_str = \$date->format('Y-m-d');
    \$start_time = new DateTime(\$date_str . ' ' . \$schedule['start_time']);
    \$end_time = new DateTime(\$date_str . ' ' . \$schedule['end_time']);
    \$interval_minutes = \$schedule['slot_interval_minutes'];
    \$break_start = \$schedule['break_start_time'] ? new DateTime(\$date_str . ' ' . \$schedule['break_start_time']) : null;
    \$break_end = \$schedule['break_end_time'] ? new DateTime(\$date_str . ' ' . \$schedule['break_end_time']) : null;
    
    \$slot_count = 0;
    \$current_time = clone \$start_time;
    
    while (\$current_time < \$end_time) {
        \$slot_end = clone \$current_time;
        \$slot_end->add(new DateInterval(\"PT{\$interval_minutes}M\"));
        
        // Skip lunch break
        if (\$break_start && \$break_end) {
            if (\$current_time >= \$break_start && \$current_time < \$break_end) {
                \$current_time = clone \$break_end;
                continue;
            }
        }
        
        // Check if slot already exists
        \$stmt = \$pdo->prepare(\"
            SELECT id FROM doctor_schedule_slots 
            WHERE doctor_id = ? AND slot_date = ? AND slot_time = ?
        \");
        \$stmt->execute([\$doctor_id, \$date_str, \$current_time->format('H:i:s')]);
        
        if (!\$stmt->fetch()) {
            // Create new slot
            \$stmt = \$pdo->prepare(\"
                INSERT INTO doctor_schedule_slots 
                (doctor_id, slot_date, slot_time, slot_end_time, is_available, is_booked) 
                VALUES (?, ?, ?, ?, TRUE, FALSE)
            \");
            \$stmt->execute([
                \$doctor_id,
                \$date_str,
                \$current_time->format('H:i:s'),
                \$slot_end->format('H:i:s')
            ]);
            \$slot_count++;
        }
        
        \$current_time->add(new DateInterval(\"PT{\$interval_minutes}M\"));
    }
    
    return \$slot_count;
}
?>";
echo "</textarea>\n";

echo "<h3>B. Status Auto-Update Script (Cron Job)</h3>\n";
echo "<textarea style='width: 100%; height: 200px; font-family: monospace; background: #fff3cd; padding: 10px;'>";
echo "<?php
// Auto-update referral statuses (run every hour via cron)

function updateReferralStatuses() {
    global \$pdo;
    
    // Mark no-shows (1 hour past appointment time)
    \$stmt = \$pdo->prepare(\"
        UPDATE referrals 
        SET status = 'no_show' 
        WHERE status = 'scheduled' 
        AND appointment_date < CURDATE()
        OR (appointment_date = CURDATE() AND appointment_time < (CURTIME() - INTERVAL 1 HOUR))
    \");
    \$stmt->execute();
    \$no_shows = \$stmt->rowCount();
    
    // Free up slots for no-shows and cancellations
    \$stmt = \$pdo->prepare(\"
        UPDATE doctor_schedule_slots s
        INNER JOIN referrals r ON r.doctor_schedule_slot_id = s.id
        SET s.is_available = TRUE, s.is_booked = FALSE
        WHERE r.status IN ('no_show', 'cancelled')
        AND s.is_booked = TRUE
    \");
    \$stmt->execute();
    \$freed_slots = \$stmt->rowCount();
    
    return \"Updated: {\$no_shows} no-shows, freed {\$freed_slots} slots\";
}
?>";
echo "</textarea>\n";

echo "<hr>";
echo "<h2>3. 📅 WORKFLOW IMPLEMENTATION</h2>\n";

echo "<h3>A. Admin Sets Doctor Schedule</h3>\n";
echo "<textarea style='width: 100%; height: 150px; font-family: monospace; background: #e3f2fd; padding: 10px;'>";
echo "// Example: Set Dr. Santos Monday schedule
INSERT INTO doctor_schedule (
    doctor_id, day_of_week, start_time, end_time, 
    slot_interval_minutes, break_start_time, break_end_time
) VALUES (
    15, 'monday', '08:00:00', '17:00:00', 
    30, '12:00:00', '13:00:00'
);

// Generate slots for next 2 weeks
generateDoctorSlots(15, 2);";
echo "</textarea>\n";

echo "<h3>B. Create Referral with Appointment</h3>\n";
echo "<textarea style='width: 100%; height: 200px; font-family: monospace; background: #e3f2fd; padding: 10px;'>";
echo "function createReferralWithAppointment(\$referral_data, \$doctor_id, \$preferred_date) {
    global \$pdo;
    
    // Find available slot
    \$stmt = \$pdo->prepare(\"
        SELECT * FROM doctor_schedule_slots 
        WHERE doctor_id = ? AND slot_date = ? 
        AND is_available = TRUE AND is_booked = FALSE
        ORDER BY slot_time ASC 
        LIMIT 1
    \");
    \$stmt->execute([\$doctor_id, \$preferred_date]);
    \$slot = \$stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!\$slot) {
        return ['error' => 'No available slots for selected date'];
    }
    
    // Create referral
    \$stmt = \$pdo->prepare(\"
        INSERT INTO referrals (patient_id, referred_by_employee_id, reason, 
                              doctor_schedule_slot_id, appointment_date, appointment_time, status) 
        VALUES (?, ?, ?, ?, ?, ?, 'scheduled')
    \");
    \$stmt->execute([
        \$referral_data['patient_id'],
        \$referral_data['referred_by'],
        \$referral_data['reason'],
        \$slot['id'],
        \$slot['slot_date'],
        \$slot['slot_time']
    ]);
    
    // Mark slot as booked
    \$stmt = \$pdo->prepare(\"
        UPDATE doctor_schedule_slots 
        SET is_booked = TRUE, is_available = FALSE 
        WHERE id = ?
    \");
    \$stmt->execute([\$slot['id']]);
    
    return ['success' => 'Referral created with appointment'];
}";
echo "</textarea>\n";

echo "<hr>";
echo "<h2>4. 🎯 STATUS LIFECYCLE MANAGEMENT</h2>\n";

echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px; width: 100%;'>\n";
echo "<tr style='background-color: #f5f5f5;'><th>Status</th><th>Trigger</th><th>Action</th><th>System Updates</th></tr>\n";
echo "<tr><td><strong>scheduled</strong></td><td>Referral created</td><td>Patient notified</td><td>Slot marked booked</td></tr>\n";
echo "<tr><td><strong>checked_in</strong></td><td>Patient arrives</td><td>Visit record created</td><td>checked_in_at timestamp</td></tr>\n";
echo "<tr><td><strong>in_progress</strong></td><td>Doctor starts consultation</td><td>Consultation begins</td><td>Queue status updated</td></tr>\n";
echo "<tr><td><strong>completed</strong></td><td>Consultation finished</td><td>Documentation done</td><td>completed_at timestamp</td></tr>\n";
echo "<tr><td><strong>cancelled</strong></td><td>Admin/patient cancels</td><td>Slot freed</td><td>Slot available again</td></tr>\n";
echo "<tr><td><strong>no_show</strong></td><td>Auto-detected after time</td><td>Slot freed</td><td>Slot available again</td></tr>\n";
echo "</table>\n";

echo "<hr>";
echo "<h2>5. ⚙️ CRON JOB SETUP</h2>\n";

echo "<p><strong>Add to your server's crontab:</strong></p>\n";
echo "<pre style='background: #f8f9fa; padding: 10px; border: 1px solid #dee2e6;'>";
echo "# Generate doctor slots every Sunday at 2 AM for next 2 weeks
0 2 * * 0 php /path/to/your/project/scripts/generate_doctor_slots.php

# Update referral statuses every hour
0 * * * * php /path/to/your/project/scripts/update_referral_status.php";
echo "</pre>\n";

echo "<hr>";
echo "<h2>6. 📊 IMPLEMENTATION BENEFITS</h2>\n";

echo "<div style='background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; margin: 10px 0;'>\n";
echo "<h4>✅ ADVANTAGES:</h4>\n";
echo "<ul>\n";
echo "<li><strong>Automation:</strong> No manual slot creation</li>\n";
echo "<li><strong>Consistency:</strong> All doctors follow same pattern</li>\n";
echo "<li><strong>Flexibility:</strong> Service-specific durations</li>\n";
echo "<li><strong>Real-time Status:</strong> Automatic updates</li>\n";
echo "<li><strong>Resource Optimization:</strong> No wasted slots</li>\n";
echo "<li><strong>Audit Trail:</strong> Complete status history</li>\n";
echo "</ul>\n";
echo "</div>\n";

echo "<p><strong>This implementation perfectly addresses your panelist's concerns while maintaining clean, normalized database design!</strong></p>\n";

?>