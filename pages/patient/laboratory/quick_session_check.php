<?php
// Quick Session Check
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';

echo "<h1>Current Patient Session</h1>";
echo "<p><strong>Patient ID:</strong> " . ($_SESSION['patient_id'] ?? 'NOT SET') . "</p>";
echo "<p><strong>All Session Data:</strong></p>";
echo "<pre>" . print_r($_SESSION, true) . "</pre>";

// Quick database check
require_once $root_path . '/config/db.php';

if (isset($_SESSION['patient_id'])) {
    $patient_session_id = $_SESSION['patient_id'];
    
    // Check if this patient exists - handle both old and new session formats
    if (is_numeric($patient_session_id)) {
        $stmt = $conn->prepare("SELECT * FROM patients WHERE patient_id = ?");
        $stmt->bind_param("i", $patient_session_id);
    } else {
        $stmt = $conn->prepare("SELECT * FROM patients WHERE username = ?");
        $stmt->bind_param("s", $patient_session_id);
    }
    $stmt->execute();
    $patient = $stmt->get_result()->fetch_assoc();
    
    if ($patient) {
        echo "<h2>Patient Found:</h2>";
        echo "<p><strong>Name:</strong> " . $patient['first_name'] . " " . $patient['last_name'] . "</p>";
        echo "<p><strong>Username:</strong> " . $patient['username'] . "</p>";
        
        // Get the numeric patient_id
        $patient_id = $patient['patient_id'];
        
        // Check lab orders for this patient
        $stmt2 = $conn->prepare("SELECT COUNT(*) as total FROM lab_orders WHERE patient_id = ?");
        $stmt2->bind_param("i", $patient_id);
        $stmt2->execute();
        $count = $stmt2->get_result()->fetch_assoc()['total'];
        
        echo "<p><strong>Lab Orders Count:</strong> " . $count . "</p>";
        
        if ($count == 0) {
            echo "<div style='background: #ffebee; padding: 1rem; border-left: 4px solid #f44336; margin: 1rem 0;'>";
            echo "<h3 style='color: #c62828; margin: 0 0 0.5rem 0;'>⚠️ PROBLEM FOUND!</h3>";
            echo "<p style='margin: 0; color: #d32f2f;'><strong>This patient has NO lab orders in the database.</strong></p>";
            echo "<p style='margin: 0.5rem 0 0 0; color: #666;'>The lab orders visible in the management interface belong to different patients (David Diaz, Princess Kyla Cabaya), but the currently logged-in patient has no lab data.</p>";
            echo "</div>";
        }
        
        $stmt2->close();
    } else {
        echo "<h2 style='color: red;'>❌ Patient NOT found in database!</h2>";
    }
    
    $stmt->close();
}
?>