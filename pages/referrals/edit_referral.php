<?php
// edit_referral.php - DEPRECATED: Edit functionality has been removed
// Users should cancel existing referrals and create new ones for modifications

// Include session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';

// Redirect to referrals management with message
if (is_employee_logged_in()) {
    header("Location: referrals_management.php?notice=edit_deprecated");
    exit();
} else {
    redirect_to_employee_login();
}
?>