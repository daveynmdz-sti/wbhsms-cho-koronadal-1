<?php
// Production-ready prescription validation script
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Include patient session configuration FIRST
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';

// Check session
echo "<h1>Prescription System Validation</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    .info { color: blue; }
    .section { margin: 20px 0; padding: 15px; border: 1px solid #ccc; border-radius: 5px; }
</style>";

if (isset($_SESSION['patient_id'])) {
    $patient_id = $_SESSION['patient_id'];
    echo "<div class='section'>";
    echo "<h2>✓ Session Status</h2>";
    echo "<p class='success'>Patient ID: $patient_id (Logged in successfully)</p>";
    echo "</div>";
    
    // Database connection
    require_once $root_path . '/config/db.php';
    
    if (isset($conn)) {
        echo "<div class='section'>";
        echo "<h2>✓ Database Connection</h2>";
        echo "<p class='success'>Database connection established successfully</p>";
        echo "</div>";
        
        try {
            // Check prescriptions with detailed info
            $stmt = $conn->prepare("
                SELECT p.prescription_id, p.patient_id, p.prescription_date, p.status, p.remarks,
                       CONCAT(e.first_name, ' ', e.last_name) as doctor_name,
                       COUNT(pm.prescribed_medication_id) as medication_count
                FROM prescriptions p
                LEFT JOIN employees e ON p.prescribed_by_employee_id = e.employee_id
                LEFT JOIN prescribed_medications pm ON p.prescription_id = pm.prescription_id
                WHERE p.patient_id = ?
                GROUP BY p.prescription_id
                ORDER BY p.prescription_date DESC
            ");
            $stmt->bind_param("i", $patient_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $prescriptions = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            
            echo "<div class='section'>";
            echo "<h2>📋 Available Prescriptions</h2>";
            if (empty($prescriptions)) {
                echo "<p class='info'>No prescriptions found for Patient ID $patient_id.</p>";
                echo "<p><em>This is normal if no prescriptions have been created yet.</em></p>";
            } else {
                echo "<p class='success'>Found " . count($prescriptions) . " prescription(s):</p>";
                echo "<table>";
                echo "<tr><th>Prescription ID</th><th>Date</th><th>Doctor</th><th>Status</th><th>Medications</th><th>Actions</th></tr>";
                foreach ($prescriptions as $prescription) {
                    $date = date('M j, Y', strtotime($prescription['prescription_date']));
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($prescription['prescription_id']) . "</td>";
                    echo "<td>" . htmlspecialchars($date) . "</td>";
                    echo "<td>" . htmlspecialchars($prescription['doctor_name'] ?: 'Unknown') . "</td>";
                    echo "<td>" . htmlspecialchars($prescription['status']) . "</td>";
                    echo "<td>" . htmlspecialchars($prescription['medication_count']) . "</td>";
                    echo "<td>
                        <a href='get_prescription_details.php?id=" . $prescription['prescription_id'] . "' target='_blank'>View JSON</a> | 
                        <a href='print_prescription.php?id=" . $prescription['prescription_id'] . "' target='_blank'>Print</a>
                    </td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
            echo "</div>";
            
            // Test medication details for existing prescriptions
            if (!empty($prescriptions)) {
                $test_prescription_id = $prescriptions[0]['prescription_id'];
                
                $stmt = $conn->prepare("
                    SELECT medication_name, dosage, frequency, duration, instructions, status
                    FROM prescribed_medications 
                    WHERE prescription_id = ?
                    ORDER BY created_at ASC
                ");
                $stmt->bind_param("i", $test_prescription_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $medications = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                
                echo "<div class='section'>";
                echo "<h2>💊 Medication Details (Prescription #$test_prescription_id)</h2>";
                if (empty($medications)) {
                    echo "<p class='info'>No medications found for this prescription.</p>";
                } else {
                    echo "<table>";
                    echo "<tr><th>Medication</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th>Instructions</th><th>Status</th></tr>";
                    foreach ($medications as $medication) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($medication['medication_name']) . "</td>";
                        echo "<td>" . htmlspecialchars($medication['dosage']) . "</td>";
                        echo "<td>" . htmlspecialchars($medication['frequency'] ?: 'N/A') . "</td>";
                        echo "<td>" . htmlspecialchars($medication['duration'] ?: 'N/A') . "</td>";
                        echo "<td>" . htmlspecialchars($medication['instructions'] ?: 'N/A') . "</td>";
                        echo "<td>" . htmlspecialchars($medication['status']) . "</td>";
                        echo "</tr>";
                    }
                    echo "</table>";
                }
                echo "</div>";
            }
            
        } catch (Exception $e) {
            echo "<div class='section'>";
            echo "<h2 class='error'>❌ Database Error</h2>";
            echo "<p class='error'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "</div>";
        }
    } else {
        echo "<div class='section'>";
        echo "<h2 class='error'>❌ Database Connection Failed</h2>";
        echo "<p class='error'>Could not establish database connection</p>";
        echo "</div>";
    }
} else {
    echo "<div class='section'>";
    echo "<h2 class='error'>❌ Session Error</h2>";
    echo "<p class='error'>User is not logged in. Please log in first.</p>";
    echo "<p><a href='../auth/patient_login.php'>Go to Login</a></p>";
    echo "</div>";
}

echo "<div class='section'>";
echo "<h2>🔗 Quick Links</h2>";
echo "<ul>";
echo "<li><a href='prescriptions.php'>Main Prescriptions Page</a></li>";
echo "<li><a href='../dashboard.php'>Patient Dashboard</a></li>";
echo "</ul>";
echo "</div>";
?>