<?php
// Simple test script to check if the get_consultation_details.php is working
// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Start a test session for debugging
if (!isset($_SESSION['employee_id'])) {
    $_SESSION['employee_id'] = 1; // Use a test employee ID
    $_SESSION['role'] = 'admin'; // Use admin role for testing
}

echo "<h2>Modal Test Debug</h2>";

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    echo "<p style='color: red;'>❌ Database connection failed: " . ($conn->connect_error ?? 'Connection not set') . "</p>";
} else {
    echo "<p style='color: green;'>✅ Database connection successful</p>";
}

// Check if any consultations exist
try {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM consultations");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total_consultations = $row['total'];
    
    echo "<p>📊 Total consultations in database: <strong>{$total_consultations}</strong></p>";
    
    if ($total_consultations > 0) {
        // Get a sample consultation ID
        $stmt = $conn->prepare("SELECT consultation_id FROM consultations LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $sample_id = $row['consultation_id'];
        
        echo "<p>🔍 Sample consultation ID: <strong>{$sample_id}</strong></p>";
        
        // Test the API endpoint
        $test_url = "get_consultation_details.php?id=" . $sample_id;
        echo "<p>🌐 Testing API endpoint: <a href='{$test_url}' target='_blank'>{$test_url}</a></p>";
        
        // Test modal functionality
        echo "<h3>Modal Test</h3>";
        echo "<button onclick='openConsultationModal({$sample_id})' class='btn btn-primary'>Test Modal with ID {$sample_id}</button>";
        
        // Include the modal HTML and JavaScript
        include 'modal_test_content.html';
    } else {
        echo "<p style='color: orange;'>⚠️ No consultations found in database</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database query error: " . $e->getMessage() . "</p>";
}
?>

<style>
body {
    font-family: Arial, sans-serif;
    margin: 20px;
    background: #f5f5f5;
}

.btn {
    padding: 10px 20px;
    background: #0077b6;
    color: white;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn:hover {
    background: #023e8a;
}
</style>

<script>
function openConsultationModal(consultationId) {
    console.log('Testing modal with consultation ID:', consultationId);
    
    fetch(`get_consultation_details.php?id=${consultationId}`)
        .then(response => {
            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers);
            return response.text(); // Use text() first to see the raw response
        })
        .then(text => {
            console.log('Raw response:', text);
            try {
                const data = JSON.parse(text);
                console.log('Parsed JSON:', data);
                if (data.success) {
                    alert('✅ API call successful! Check console for details.');
                } else {
                    alert('❌ API call failed: ' + (data.message || 'Unknown error'));
                }
            } catch (e) {
                console.error('JSON parse error:', e);
                alert('❌ Invalid JSON response. Check console for raw response.');
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            alert('❌ Network error: ' + error.message);
        });
}
</script>