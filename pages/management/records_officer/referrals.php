<?php
// Records Officer Referrals - Redirect to Central Referrals System
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(dirname(dirname(dirname(__DIR__))));
require_once $root_path . '/config/session/employee_session.php';

// If user is not logged in, bounce to login - use session management function
if (!is_employee_logged_in()) {
    ob_end_clean();
    error_log('Records Officer Referrals: No session found, redirecting to login');
    redirect_to_employee_login();
}

// Check if role is authorized for referrals
$authorized_roles = ['records_officer'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    header('Location: ../dashboard.php');
    exit();
}

// Redirect to central referrals management system using root path resolution
header('Location: ' . $root_path . '/pages/referrals/referrals_management.php');
exit();
?>