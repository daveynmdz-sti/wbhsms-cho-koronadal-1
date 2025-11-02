<?php
/**
 * Lab Result File API
 * 
 * Retrieves and displays the PDF result file for a specific lab order item.
 * The result_file is stored as LONGBLOB in the lab_order_items table.
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include required files
$root_path = dirname(__DIR__);
require_once $root_path . '/config/db.php';
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/auth_helpers.php';

try {
    // Employee authentication required
    $authorized_roles = ['admin', 'dho', 'bhw', 'doctor', 'nurse', 'records_officer', 'laboratory_tech'];
    require_employee_auth($authorized_roles);
    
    // Get and validate parameters
    $item_id = isset($_GET['item_id']) ? intval($_GET['item_id']) : 0;
    
    if ($item_id <= 0) {
        throw new Exception('Valid item_id is required');
    }
    
    // Validate database connection
    if (!isset($pdo) || !$pdo) {
        throw new Exception('Database connection not available');
    }
    
    error_log("Lab Result File API: Processing request for item_id: $item_id");
    
    // Get employee role for access control
    $employee_role = strtolower($_SESSION['role'] ?? '');
    $employee_id = $_SESSION['employee_id'] ?? 0;
    
    // Query to get the result file and related information
    $sql = "
        SELECT 
            loi.item_id,
            loi.lab_order_id,
            loi.test_type,
            loi.result_file,
            loi.result_date,
            loi.status,
            
            -- Lab order information for access control
            lo.patient_id,
            lo.ordered_by_employee_id,
            
            -- Patient information for access control
            p.barangay_id
            
        FROM lab_order_items loi
        INNER JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
        INNER JOIN patients p ON lo.patient_id = p.patient_id
        
        WHERE loi.item_id = :item_id
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':item_id' => $item_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        throw new Exception('Lab result file not found');
    }
    
    // Check if result file exists
    if (empty($result['result_file'])) {
        throw new Exception('No result file available for this test');
    }
    
    error_log("Lab Result File API: Found result file for test: " . $result['test_type']);
    
    // Role-based access control (similar to main laboratory API)
    $access_denied = false;
    
    switch ($employee_role) {
        case 'bhw':
            // BHW: Only patients from their facility's barangay
            $facility_check_sql = "
                SELECT COUNT(*) as count
                FROM employees e 
                JOIN facilities f ON e.facility_id = f.facility_id 
                WHERE e.employee_id = :employee_id 
                AND f.barangay_id = :patient_barangay_id
            ";
            $facility_stmt = $pdo->prepare($facility_check_sql);
            $facility_stmt->execute([
                ':employee_id' => $employee_id,
                ':patient_barangay_id' => $result['barangay_id']
            ]);
            $facility_result = $facility_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($facility_result['count'] == 0) {
                $access_denied = true;
            }
            break;
            
        case 'dho':
            // DHO: District-wide access (implemented in main API)
            break;
            
        case 'admin':
        case 'doctor':
        case 'nurse':
        case 'laboratory_tech':
        case 'records_officer':
            // Full access for these roles
            break;
            
        default:
            $access_denied = true;
    }
    
    if ($access_denied) {
        error_log("Lab Result File API: Access denied for role: $employee_role");
        throw new Exception('Access denied: You do not have permission to view this result file');
    }
    
    // Set appropriate headers for PDF display
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="lab_result_' . $result['test_type'] . '_' . $item_id . '.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    // Output the PDF file
    echo $result['result_file'];
    
    error_log("Lab Result File API: Successfully served result file for item_id: $item_id");

} catch (Exception $e) {
    error_log("Lab Result File API Error: " . $e->getMessage());
    
    // Return error page instead of JSON for file display
    http_response_code(404);
    header('Content-Type: text/html');
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Lab Result File Error</title>
        <style>
            body { font-family: Arial, sans-serif; text-align: center; padding: 50px; }
            .error-container { max-width: 500px; margin: 0 auto; }
            .error-icon { font-size: 48px; color: #dc3545; margin-bottom: 20px; }
            .error-title { font-size: 24px; margin-bottom: 15px; color: #333; }
            .error-message { color: #666; margin-bottom: 30px; }
            .btn { background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h1 class="error-title">Result File Not Available</h1>
            <p class="error-message">' . htmlspecialchars($e->getMessage()) . '</p>
            <a href="javascript:window.close()" class="btn">Close Window</a>
        </div>
    </body>
    </html>';
}
?>