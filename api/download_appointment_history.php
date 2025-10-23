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

    // Fetch patient information
    $stmt = $conn->prepare("SELECT first_name, last_name FROM patients WHERE patient_id = ?");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $patient_info = $result->fetch_assoc();
    $stmt->close();

    $patient_name = ($patient_info['first_name'] ?? '') . ' ' . ($patient_info['last_name'] ?? '');

    // Set headers for PDF download
    $filename = 'appointment_history_' . date('Y-m-d') . '.pdf';
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    // For demonstration, create a simple text-based PDF content
    // In production, use a proper PDF library like TCPDF or FPDF
    
    $pdf_content = "%PDF-1.4
1 0 obj
<< /Type /Catalog /Pages 2 0 R >>
endobj

2 0 obj
<< /Type /Pages /Kids [3 0 R] /Count 1 >>
endobj

3 0 obj
<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792]
   /Resources << /Font << /F1 4 0 R >> >>
   /Contents 5 0 R >>
endobj

4 0 obj
<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>
endobj

5 0 obj
<< /Length 200 >>
stream
BT
/F1 12 Tf
100 700 Td
(CHO Koronadal - Appointment History) Tj
0 -30 Td
(Patient: " . $patient_name . ") Tj
0 -30 Td
(Generated: " . date('F j, Y') . ") Tj
0 -50 Td
(Please visit the CHO office to get your complete) Tj
0 -20 Td
(appointment history and medical records.) Tj
ET
endstream
endobj

xref
0 6
0000000000 65535 f 
0000000015 00000 n 
0000000060 00000 n 
0000000115 00000 n 
0000000273 00000 n 
0000000336 00000 n 
trailer
<< /Size 6 /Root 1 0 R >>
startxref
587
%%EOF";

    echo $pdf_content;

} catch (Exception $e) {
    error_log("Error generating appointment history: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Error generating appointment history';
}
?>