<?php
// minimal_reinstate_test.php - Minimal test to verify functionality
session_start();

// Simulate logged in admin
$_SESSION['employee_id'] = 1;
$_SESSION['role'] = 'admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_reinstate'])) {
    require_once 'config/db.php';
    
    $referral_id = $_POST['referral_id'];
    
    // Get current status
    $stmt = $conn->prepare("SELECT status FROM referrals WHERE referral_id = ?");
    $stmt->bind_param('i', $referral_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $current = $result->fetch_assoc();
    $current_status = $current['status'];
    $stmt->close();
    
    echo "<p>Before: Referral $referral_id status = $current_status</p>";
    
    // Update to active
    $stmt = $conn->prepare("UPDATE referrals SET status = 'active', updated_at = NOW() WHERE referral_id = ?");
    $stmt->bind_param('i', $referral_id);
    $success = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    if ($success && $affected > 0) {
        echo "<p style='color: green;'>✓ UPDATE successful! Affected rows: $affected</p>";
        
        // Verify
        $stmt = $conn->prepare("SELECT status, updated_at FROM referrals WHERE referral_id = ?");
        $stmt->bind_param('i', $referral_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $updated = $result->fetch_assoc();
        $stmt->close();
        
        echo "<p>After: Referral $referral_id status = {$updated['status']}, updated = {$updated['updated_at']}</p>";
    } else {
        echo "<p style='color: red;'>✗ UPDATE failed or no rows affected</p>";
    }
    
    echo "<hr>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Minimal Reinstate Test</title>
</head>
<body>
    <h1>Minimal Reinstate Test</h1>
    
    <form method="POST">
        <label>Referral ID to reinstate:</label>
        <input type="number" name="referral_id" value="3" required>
        <button type="submit" name="test_reinstate">Test Direct Database Update</button>
    </form>
    
    <h2>Test API Call</h2>
    <button onclick="testAPI()">Test API Call</button>
    <div id="api-result" style="margin-top: 10px; padding: 10px; background: #f0f0f0;"></div>
    
    <script>
        function testAPI() {
            const resultDiv = document.getElementById('api-result');
            resultDiv.innerHTML = 'Testing API...';
            
            const formData = new FormData();
            formData.append('referral_id', 3);
            
            fetch('pages/referrals/reinstate_referral.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(text => {
                resultDiv.innerHTML = '<h3>API Response:</h3><pre>' + text + '</pre>';
            })
            .catch(error => {
                resultDiv.innerHTML = '<h3>API Error:</h3><p>' + error.message + '</p>';
            });
        }
    </script>
</body>
</html>