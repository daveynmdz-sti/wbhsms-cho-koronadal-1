<?php
// Include session and database
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/patient_session.php';
require_once $root_path . '/config/db.php';

try {
    // Check if patient is logged in
    if (!isset($_SESSION['patient_id'])) {
        header('Location: ../pages/patient/auth/patient_login.php');
        exit();
    }

    $patient_id = $_SESSION['patient_id'];
    $appointment_id = $_GET['appointment_id'] ?? '';

    if (empty($appointment_id) || !is_numeric($appointment_id)) {
        header('HTTP/1.1 400 Bad Request');
        echo 'Invalid appointment ID';
        exit();
    }

    // Verify the appointment belongs to this patient
    $stmt = $conn->prepare("SELECT appointment_id FROM appointments WHERE appointment_id = ? AND patient_id = ?");
    $stmt->bind_param("ii", $appointment_id, $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$result->fetch_assoc()) {
        header('HTTP/1.1 404 Not Found');
        echo 'Appointment not found';
        exit();
    }
    $stmt->close();

    // Set appropriate headers for download
    $filename = 'appointment_qr_code_' . str_pad($appointment_id, 8, '0', STR_PAD_LEFT) . '.png';
    
    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    
    // Create a simple QR code image (for demonstration)
    // In production, use a proper QR code library
    $width = 200;
    $height = 200;
    
    $image = imagecreate($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    
    // Fill background
    imagefill($image, 0, 0, $white);
    
    // Create a simple pattern (this is just for demonstration)
    // Real QR codes require proper encoding algorithms
    for ($i = 0; $i < $width; $i += 10) {
        for ($j = 0; $j < $height; $j += 10) {
            if (($i + $j + $appointment_id) % 20 == 0) {
                imagefilledrectangle($image, $i, $j, $i + 8, $j + 8, $black);
            }
        }
    }
    
    // Add text
    $text = 'APT-' . str_pad($appointment_id, 8, '0', STR_PAD_LEFT);
    imagestring($image, 3, 50, $height - 25, $text, $black);
    
    // Output image
    imagepng($image);
    imagedestroy($image);

} catch (Exception $e) {
    error_log("Error downloading QR code: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Error generating QR code';
}
?>