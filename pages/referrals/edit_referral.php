<?php
// edit_referral.php - DEPRECATED: Edit functionality has been removed
// Users should cancel existing referrals and create new ones for modifications

session_start();

// Redirect to referrals management with message
if (isset($_SESSION['employee_id'])) {
    header("Location: referrals_management.php?notice=edit_deprecated");
    exit();
} else {
    header("Location: ../../login.php");
    exit();
}
?>