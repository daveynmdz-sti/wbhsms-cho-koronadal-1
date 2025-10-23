<?php
// Simple session setup and redirect
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/patient_session.php';

// Set up patient session
$_SESSION['patient_id'] = 7;
$_SESSION['patient_logged_in'] = true;
$_SESSION['login_time'] = time();

// Redirect to referrals page
header('Location: pages/patient/referrals/referrals.php');
exit();
?>