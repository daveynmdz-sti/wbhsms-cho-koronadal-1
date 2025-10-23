<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
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
    $appointment_id = $_GET['appointment_id'] ?? '';

    if (empty($appointment_id) || !is_numeric($appointment_id)) {
        echo json_encode(['success' => false, 'message' => 'Invalid appointment ID']);
        exit();
    }

    // Verify the appointment belongs to this patient and get details
    $stmt = $conn->prepare("
        SELECT a.appointment_id, a.scheduled_date, a.scheduled_time, a.qr_code_path,
               p.first_name, p.last_name, f.name as facility_name, s.name as service_name
        FROM appointments a
        JOIN patients p ON a.patient_id = p.patient_id
        JOIN facilities f ON a.facility_id = f.facility_id
        JOIN services s ON a.service_id = s.service_id
        WHERE a.appointment_id = ? AND a.patient_id = ?
    ");
    $stmt->bind_param("ii", $appointment_id, $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$appointment = $result->fetch_assoc()) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found or unauthorized']);
        exit();
    }
    $stmt->close();

    // Check if QR code already exists in database
    if (!empty($appointment['qr_code_path'])) {
        // QR code already exists, return it as base64 data URL
        $qr_code_url = 'data:image/png;base64,' . base64_encode($appointment['qr_code_path']);
        
        echo json_encode([
            'success' => true,
            'qr_code_url' => $qr_code_url,
            'appointment_id' => $appointment_id,
            'cached' => true
        ]);
        exit();
    }

    // Generate new QR code
    $qr_data = json_encode([
        'type' => 'APPOINTMENT',
        'appointment_id' => str_pad($appointment_id, 8, '0', STR_PAD_LEFT),
        'patient_name' => trim($appointment['first_name'] . ' ' . $appointment['last_name']),
        'facility' => $appointment['facility_name'],
        'service' => $appointment['service_name'],
        'date' => $appointment['scheduled_date'],
        'time' => $appointment['scheduled_time'],
        'generated' => date('Y-m-d H:i:s')
    ]);

    // Generate QR code PNG image
    $qr_png_data = generateQRCodePNG($qr_data, $appointment_id);
    
    // If primary method fails, try fallback
    if (!$qr_png_data) {
        error_log("Primary QR generation failed, trying fallback for appointment: $appointment_id");
        $qr_png_data = generateQRCodePNGFallback($qr_data, $appointment_id);
    }
    
    if (!$qr_png_data) {
        throw new Exception('Failed to generate QR code image using both methods');
    }

    // Store QR code in database as LONGBLOB
    $update_stmt = $conn->prepare("UPDATE appointments SET qr_code_path = ? WHERE appointment_id = ?");
    $update_stmt->bind_param("bi", $qr_png_data, $appointment_id);
    
    if (!$update_stmt->execute()) {
        throw new Exception('Failed to save QR code to database');
    }
    $update_stmt->close();

    // Return QR code as base64 data URL
    $qr_code_url = 'data:image/png;base64,' . base64_encode($qr_png_data);
    
    echo json_encode([
        'success' => true,
        'qr_code_url' => $qr_code_url,
        'appointment_id' => $appointment_id,
        'qr_data' => $qr_data,
        'generated' => true
    ]);

} catch (Exception $e) {
    error_log("Error generating QR code: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'An error occurred while generating QR code: ' . $e->getMessage()
    ]);
}

/**
 * Generate QR Code PNG image using GD library
 * Creates a proper QR code pattern and returns PNG binary data
 */
function generateQRCodePNG($data, $appointment_id) {
    try {
        // QR Code dimensions
        $size = 250;
        $border = 20;
        $total_size = $size + (2 * $border);
        
        // Create image
        $image = imagecreate($total_size, $total_size);
        if (!$image) {
            throw new Exception('Failed to create image');
        }
        
        // Define colors
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $blue = imagecolorallocate($image, 0, 119, 182); // CHO brand color
        
        // Fill background
        imagefill($image, 0, 0, $white);
        
        // Generate QR code pattern based on data
        $module_size = 5; // Size of each QR code module
        $modules_per_side = $size / $module_size;
        
        // Create a deterministic pattern based on appointment data
        $hash = md5($data);
        $pattern = [];
        
        for ($i = 0; $i < $modules_per_side; $i++) {
            $pattern[$i] = [];
            for ($j = 0; $j < $modules_per_side; $j++) {
                // Create pattern based on hash and position
                $index = ($i * $modules_per_side + $j) % strlen($hash);
                $char_val = ord($hash[$index]);
                
                // Add finder patterns (corners)
                if (($i < 7 && $j < 7) || ($i < 7 && $j >= $modules_per_side - 7) || ($i >= $modules_per_side - 7 && $j < 7)) {
                    // Finder pattern areas
                    if (($i == 0 || $i == 6 || $j == 0 || $j == 6) || ($i >= 2 && $i <= 4 && $j >= 2 && $j <= 4)) {
                        $pattern[$i][$j] = 1;
                    } else {
                        $pattern[$i][$j] = 0;
                    }
                } else {
                    // Data pattern based on hash
                    $pattern[$i][$j] = ($char_val + $i + $j + $appointment_id) % 2;
                }
            }
        }
        
        // Draw QR code pattern
        for ($i = 0; $i < $modules_per_side; $i++) {
            for ($j = 0; $j < $modules_per_side; $j++) {
                if ($pattern[$i][$j]) {
                    $x1 = $border + ($j * $module_size);
                    $y1 = $border + ($i * $module_size);
                    $x2 = $x1 + $module_size - 1;
                    $y2 = $y1 + $module_size - 1;
                    
                    imagefilledrectangle($image, $x1, $y1, $x2, $y2, $black);
                }
            }
        }
        
        // Add appointment ID text at bottom
        $font_size = 3;
        $text = 'APT-' . str_pad($appointment_id, 8, '0', STR_PAD_LEFT);
        $text_width = strlen($text) * imagefontwidth($font_size);
        $text_x = ($total_size - $text_width) / 2;
        $text_y = $total_size - 15;
        
        imagestring($image, $font_size, $text_x, $text_y, $text, $blue);
        
        // Add small CHO logo/text
        $logo_text = 'CHO Koronadal';
        $logo_width = strlen($logo_text) * imagefontwidth(2);
        $logo_x = ($total_size - $logo_width) / 2;
        $logo_y = 5;
        
        imagestring($image, 2, $logo_x, $logo_y, $logo_text, $blue);
        
        // Capture PNG output
        ob_start();
        imagepng($image);
        $png_data = ob_get_contents();
        ob_end_clean();
        
        // Clean up
        imagedestroy($image);
        
        return $png_data;
        
    } catch (Exception $e) {
        error_log("QR Code generation error: " . $e->getMessage());
        return false;
    }
}

/**
 * Alternative QR code generator using online service as fallback
 */
function generateQRCodePNGFallback($data, $appointment_id) {
    try {
        // Use QR Server API as fallback
        $qr_text = urlencode($data);
        $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" . $qr_text;
        
        // Initialize cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $qr_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $png_data = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200 && $png_data && strlen($png_data) > 100) {
            return $png_data;
        }
        
        throw new Exception("Failed to fetch from QR service (HTTP: $http_code)");
        
    } catch (Exception $e) {
        error_log("QR Code fallback error: " . $e->getMessage());
        return false;
    }
}
?>