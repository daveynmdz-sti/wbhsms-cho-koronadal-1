<?php
// simple_reinstate_test.php - Bare minimum reinstate API for testing
session_start();

// Set up test session if not exists
if (!isset($_SESSION['employee_id'])) {
    $_SESSION['employee_id'] = 1;
    $_SESSION['role'] = 'admin';
}

header('Content-Type: application/json');

require_once 'config/db.php';

try {
    $referral_id = $_POST['referral_id'] ?? '';
    
    if (empty($referral_id) || !is_numeric($referral_id)) {
        throw new Exception('Invalid referral ID');
    }
    
    // Check if referral exists and get status
    $check = $conn->prepare("SELECT status FROM referrals WHERE referral_id = ?");
    $check->bind_param('i', $referral_id);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Referral not found');
    }
    
    $data = $result->fetch_assoc();
    $current_status = $data['status'];
    $check->close();
    
    // Simple update - no permission checks for testing
    $update = $conn->prepare("UPDATE referrals SET status = 'active', updated_at = NOW() WHERE referral_id = ?");
    $update->bind_param('i', $referral_id);
    
    if (!$update->execute()) {
        throw new Exception('Update failed: ' . $update->error);
    }
    
    $affected = $update->affected_rows;
    $update->close();
    
    if ($affected === 0) {
        throw new Exception('No rows updated');
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Referral $referral_id updated from '$current_status' to 'active'",
        'affected_rows' => $affected
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>