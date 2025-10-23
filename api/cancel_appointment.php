<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Include session and database
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

try {
    // Check if patient is logged in
    if (!isset($_SESSION['patient_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized access - please log in']);
        exit();
    }

    $patient_id = $_SESSION['patient_id'];
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Invalid request method']);
        exit();
    }

    $appointment_id = $_POST['appointment_id'] ?? '';
    $cancellation_reason = trim($_POST['cancellation_reason'] ?? '');

    if (empty($appointment_id) || !is_numeric($appointment_id)) {
        echo json_encode(['success' => false, 'message' => 'Invalid appointment ID']);
        exit();
    }

    if (empty($cancellation_reason)) {
        echo json_encode(['success' => false, 'message' => 'Cancellation reason is required']);
        exit();
    }

    // Verify the appointment belongs to this patient and can be cancelled
    $stmt = $conn->prepare("
        SELECT appointment_id, status, scheduled_date, scheduled_time 
        FROM appointments 
        WHERE appointment_id = ? AND patient_id = ?
    ");
    $stmt->bind_param("ii", $appointment_id, $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$appointment = $result->fetch_assoc()) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found or unauthorized']);
        exit();
    }
    $stmt->close();

    // Check if appointment can be cancelled
    $current_status = strtolower($appointment['status'] ?? '');
    if (in_array($current_status, ['cancelled', 'completed', 'no-show'])) {
        echo json_encode(['success' => false, 'message' => 'This appointment cannot be cancelled']);
        exit();
    }

    // Check if appointment is not in the past
    $appointment_datetime = $appointment['scheduled_date'] . ' ' . $appointment['scheduled_time'];
    if (strtotime($appointment_datetime) < time()) {
        echo json_encode(['success' => false, 'message' => 'Cannot cancel past appointments']);
        exit();
    }

    // Update appointment status
    $stmt = $conn->prepare("
        UPDATE appointments 
        SET status = 'cancelled', 
            cancellation_reason = ?, 
            updated_at = NOW() 
        WHERE appointment_id = ? AND patient_id = ?
    ");
    $stmt->bind_param("sii", $cancellation_reason, $appointment_id, $patient_id);
    
    if ($stmt->execute()) {
        // Also update any related queue entries
        $queue_stmt = $conn->prepare("
            UPDATE queue_entries 
            SET status = 'cancelled', 
                updated_at = NOW() 
            WHERE appointment_id = ?
        ");
        $queue_stmt->bind_param("i", $appointment_id);
        $queue_stmt->execute();
        $queue_stmt->close();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Appointment cancelled successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to cancel appointment. Please try again.'
        ]);
    }
    
    $stmt->close();

} catch (Exception $e) {
    error_log("Error cancelling appointment: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred while cancelling the appointment'
    ]);
}
?>