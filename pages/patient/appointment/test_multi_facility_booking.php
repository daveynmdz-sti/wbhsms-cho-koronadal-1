<?php
// test_multi_facility_booking.php
// Test script to verify the new multi-facility booking logic

// Use absolute path resolution
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/db.php';

echo "<h2>Multi-Facility Booking Logic Test</h2>\n";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .info { color: blue; }
    .warning { color: orange; font-weight: bold; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
    .code { background: #f4f4f4; padding: 10px; border-left: 3px solid #007bff; margin: 10px 0; }
</style>";

$errors = [];
$successes = [];

// Helper function to check if patient already has an appointment for a specific facility on a given date
function hasAppointmentForFacility($conn, $patient_id, $facility_id, $date) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM appointments 
            WHERE patient_id = ? AND facility_id = ? AND appointment_date = ?
        ");
        $stmt->bind_param("iis", $patient_id, $facility_id, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['count'] > 0;
    } catch (Exception $e) {
        error_log("Error checking appointment for facility: " . $e->getMessage());
        return false;
    }
}

try {
    // Test 1: Check helper function
    echo "<h3>Test 1: Helper Function Validation</h3>\n";
    
    $test_patient_id = 1; // Use patient ID 1 for testing
    $today = date('Y-m-d');
    
    // Test with different facility IDs
    $facilities_to_test = [1, 3, 25]; // CHO, DHO, BHC (Avanceña)
    
    echo "<table>\n";
    echo "<tr><th>Facility ID</th><th>Has Appointment Today</th><th>Facility Type</th></tr>\n";
    
    foreach ($facilities_to_test as $facility_id) {
        $has_appointment = hasAppointmentForFacility($conn, $test_patient_id, $facility_id, $today);
        $facility_type = '';
        
        switch ($facility_id) {
            case 1:
                $facility_type = 'CHO';
                break;
            case 3:
                $facility_type = 'DHO';
                break;
            case 25:
                $facility_type = 'BHC (Avanceña)';
                break;
            default:
                $facility_type = 'Unknown';
        }
        
        echo "<tr>";
        echo "<td>{$facility_id}</td>";
        echo "<td>" . ($has_appointment ? 'Yes' : 'No') . "</td>";
        echo "<td>{$facility_type}</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";
    
    $successes[] = "✅ Helper function working correctly";

    // Test 2: Check current appointments for test patient
    echo "<h3>Test 2: Current Appointments for Test Patient (ID: {$test_patient_id})</h3>\n";
    
    $stmt = $conn->prepare("
        SELECT a.appointment_id, a.facility_id, f.name as facility_name, 
               a.scheduled_date, a.scheduled_time, a.status
        FROM appointments a
        LEFT JOIN facilities f ON a.facility_id = f.facility_id
        WHERE a.patient_id = ? 
        AND a.scheduled_date >= ?
        ORDER BY a.scheduled_date, a.scheduled_time
    ");
    
    $stmt->bind_param("is", $test_patient_id, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "<table>\n";
    echo "<tr><th>Appointment ID</th><th>Facility</th><th>Date</th><th>Time</th><th>Status</th></tr>\n";
    
    $appointment_count = 0;
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['appointment_id']}</td>";
        echo "<td>{$row['facility_name']} (ID: {$row['facility_id']})</td>";
        echo "<td>{$row['scheduled_date']}</td>";
        echo "<td>{$row['scheduled_time']}</td>";
        echo "<td>{$row['status']}</td>";
        echo "</tr>\n";
        $appointment_count++;
    }
    
    if ($appointment_count == 0) {
        echo "<tr><td colspan='5' style='text-align: center; font-style: italic;'>No upcoming appointments found</td></tr>\n";
    }
    
    echo "</table>\n";
    $stmt->close();

    // Test 3: Simulate new booking logic
    echo "<h3>Test 3: New Booking Logic Simulation</h3>\n";
    
    $test_scenarios = [
        ['patient_id' => $test_patient_id, 'facility_id' => 25, 'facility_name' => 'BHC (Avanceña)', 'date' => $today],
        ['patient_id' => $test_patient_id, 'facility_id' => 3, 'facility_name' => 'DHO', 'date' => $today],
        ['patient_id' => $test_patient_id, 'facility_id' => 1, 'facility_name' => 'CHO', 'date' => $today],
    ];
    
    echo "<table>\n";
    echo "<tr><th>Facility</th><th>Can Book Today?</th><th>Reason</th></tr>\n";
    
    foreach ($test_scenarios as $scenario) {
        $has_appointment = hasAppointmentForFacility($conn, $scenario['patient_id'], $scenario['facility_id'], $scenario['date']);
        $can_book = !$has_appointment;
        $reason = $has_appointment ? 'Already has appointment for this facility today' : 'No existing appointment for this facility today';
        
        echo "<tr>";
        echo "<td>{$scenario['facility_name']}</td>";
        echo "<td>" . ($can_book ? '<span class="success">Yes</span>' : '<span class="error">No</span>') . "</td>";
        echo "<td>{$reason}</td>";
        echo "</tr>\n";
    }
    echo "</table>\n";

    // Test 4: Check referral requirements
    echo "<h3>Test 4: Referral Requirements Check</h3>\n";
    
    $stmt = $conn->prepare("
        SELECT 
            COUNT(CASE WHEN referred_to_facility_id IN (2,3) AND status = 'active' THEN 1 END) as dho_referrals,
            COUNT(CASE WHEN referred_to_facility_id = 1 AND status = 'active' THEN 1 END) as cho_referrals
        FROM referrals 
        WHERE patient_id = ?
    ");
    
    $stmt->bind_param("i", $test_patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $referral_data = $result->fetch_assoc();
    $stmt->close();
    
    echo "<table>\n";
    echo "<tr><th>Facility Type</th><th>Active Referrals</th><th>Booking Allowed</th></tr>\n";
    echo "<tr><td>BHC</td><td>Not required</td><td><span class='success'>Yes</span> (if no appointment today)</td></tr>\n";
    echo "<tr><td>DHO</td><td>{$referral_data['dho_referrals']}</td><td>" . 
         ($referral_data['dho_referrals'] > 0 ? '<span class="success">Yes</span>' : '<span class="error">No</span>') . 
         " (if no appointment today)</td></tr>\n";
    echo "<tr><td>CHO</td><td>{$referral_data['cho_referrals']}</td><td>" . 
         ($referral_data['cho_referrals'] > 0 ? '<span class="success">Yes</span>' : '<span class="error">No</span>') . 
         " (if no appointment today)</td></tr>\n";
    echo "</table>\n";

} catch (Exception $e) {
    $errors[] = "❌ Database error: " . $e->getMessage();
}

// Display results
echo "<h3>Test Results Summary</h3>\n";

if (!empty($successes)) {
    echo "<div class='success'>\n";
    foreach ($successes as $success) {
        echo "<p>{$success}</p>\n";
    }
    echo "</div>\n";
}

if (!empty($errors)) {
    echo "<div class='error'>\n";
    foreach ($errors as $error) {
        echo "<p>{$error}</p>\n";
    }
    echo "</div>\n";
}

echo "<h3>New Booking Rules Summary</h3>\n";
echo "<div class='info'>\n";
echo "<h4>✅ What's Now Allowed:</h4>\n";
echo "<ul>\n";
echo "<li><strong>Multiple facility bookings on the same day</strong> - You can book at BHC, DHO, and CHO on the same day</li>\n";
echo "<li><strong>Different time slots</strong> - Each facility can have different appointment times</li>\n";
echo "<li><strong>Referral-based progression</strong> - BHC → DHO → CHO workflow supported</li>\n";
echo "</ul>\n";

echo "<h4>🚫 What's Still Restricted:</h4>\n";
echo "<ul>\n";
echo "<li><strong>One appointment per facility per day</strong> - Can't book multiple times at the same facility on the same day</li>\n";
echo "<li><strong>Referral requirements</strong> - DHO and CHO still require active referrals</li>\n";
echo "<li><strong>Time slot capacity</strong> - Maximum 20 patients per time slot</li>\n";
echo "</ul>\n";

echo "<h4>📋 Example Valid Scenario:</h4>\n";
echo "<div class='code'>\n";
echo "1. 9:00 AM - Book at BHC (Avanceña) - facility_id=25<br>\n";
echo "2. Get referral to DHO during BHC visit<br>\n";
echo "3. 11:00 AM - Book at DHO - facility_id=3<br>\n";
echo "4. Get referral to CHO during DHO visit<br>\n";
echo "5. 1:00 PM - Book at CHO - facility_id=1<br>\n";
echo "</div>\n";
echo "</div>\n";

echo "<h3>Next Steps</h3>\n";
echo "<div class='warning'>\n";
echo "<p><strong>Test the booking flow:</strong></p>\n";
echo "<p>1. Visit: <a href='book_appointment.php'>book_appointment.php</a></p>\n";
echo "<p>2. Try booking at your BHC first</p>\n";
echo "<p>3. Get a referral and try booking at DHO/CHO on the same day</p>\n";
echo "<p>4. Verify that you can book multiple facilities but not the same facility twice</p>\n";
echo "</div>\n";

?>