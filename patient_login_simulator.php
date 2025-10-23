<?php
// Simple patient login simulator for testing
error_reporting(E_ALL);
ini_set('display_errors', 1);

$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/patient_session.php';

// If login form submitted
if ($_POST['action'] === 'login' && !empty($_POST['patient_id'])) {
    $patient_id = intval($_POST['patient_id']);
    
    // Simulate patient login by setting session
    $_SESSION['patient_id'] = $patient_id;
    $_SESSION['patient_logged_in'] = true;
    $_SESSION['login_time'] = time();
    
    echo "<h3 style='color: green;'>Login Successful!</h3>";
    echo "<p>Patient ID $patient_id is now logged in.</p>";
    echo "<p><a href='debug_patient_api.php'>Test Patient APIs</a></p>";
    echo "<p><a href='pages/patient/referrals/referrals.php'>Go to Patient Referrals</a></p>";
} else {
?>

<h2>Patient Login Simulator</h2>
<p>This is for testing purposes only.</p>

<form method="POST">
    <input type="hidden" name="action" value="login">
    <label>Patient ID (use 7 for testing): 
        <input type="number" name="patient_id" value="7" min="1" required>
    </label>
    <br><br>
    <button type="submit">Simulate Login</button>
</form>

<h3>Current Session Status:</h3>
<p>Patient ID: <?php echo isset($_SESSION['patient_id']) ? $_SESSION['patient_id'] : 'Not logged in'; ?></p>
<p>Session ID: <?php echo session_id(); ?></p>

<?php if (isset($_SESSION['patient_id'])): ?>
    <p><a href="debug_patient_api.php">Test Patient APIs</a></p>
    <p><a href="pages/patient/referrals/referrals.php">Go to Patient Referrals</a></p>
<?php endif; ?>

<?php } ?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
input, button { padding: 8px; margin: 5px; }
label { display: block; margin: 10px 0; }
</style>