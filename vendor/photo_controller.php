<?php
//photo_controller.php
require_once dirname(__DIR__) . '/config/db.php';

// Handle both patient and employee photos
$patient_id = isset($_GET['patient_id']) ? $_GET['patient_id'] : null;
$employee_id = isset($_GET['employee_id']) ? $_GET['employee_id'] : null;

if ($patient_id && is_numeric($patient_id)) {
    // Handle patient photo
    $stmt = $pdo->prepare('SELECT profile_photo FROM personal_information WHERE patient_id = ?');
    $stmt->execute([$patient_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($employee_id && is_numeric($employee_id)) {
    // Handle employee photo (placeholder - no employee photo table exists yet)
    $row = null; // No employee photos stored yet, use default
} else {
    // No valid ID provided
    header('Content-Type: image/png');
    readfile('https://ik.imagekit.io/wbhsmslogo/user.png?updatedAt=1750423429172');
    exit;
}

// Process the photo data
$stmt = null; // Clear statement variable
if ($row && !empty($row['profile_photo'])) {
    $img = $row['profile_photo'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($img);
    if (!$mime) $mime = 'image/jpeg';
    header('Content-Type: ' . $mime);
    echo $img;
} else {
    header('Content-Type: image/png');
    readfile('https://ik.imagekit.io/wbhsmslogo/user.png?updatedAt=1750423429172');
}
