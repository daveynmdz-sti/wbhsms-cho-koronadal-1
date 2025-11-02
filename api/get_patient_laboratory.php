<?php
// api/get_patient_laboratory.php - Get laboratory history for a specific patient
ob_start();

// Load environment configuration
$root_path = dirname(__DIR__);
require_once $root_path . '/config/env.php';

// Set error reporting based on environment
if (getenv('APP_DEBUG') === '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// Security headers
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

// Authentication and session
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/auth_helpers.php';

// Check authentication
$authorized_roles = ['admin', 'dho', 'bhw', 'doctor', 'nurse', 'records_officer', 'laboratory_tech'];
require_employee_auth($authorized_roles);

// Database connection
require_once $root_path . '/config/db.php';

// Check if patient_id is provided
if (!isset($_GET['patient_id']) || empty($_GET['patient_id'])) {
    error_log("ERROR: No patient_id provided in request");
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Patient ID is required'
    ]);
    exit();
}
// Validate and sanitize patient_id
$patient_id = intval($_GET['patient_id']);
if ($patient_id <= 0) {
    error_log("ERROR: Invalid patient_id provided: " . $_GET['patient_id']);
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid patient ID provided'
    ]);
    exit();
}

// Debug logging
error_log("Laboratory API: Processing request for patient_id: $patient_id");
error_log("Laboratory API: User role: " . ($_SESSION['role'] ?? 'none'));
error_log("Laboratory API: Employee ID: " . ($_SESSION['employee_id'] ?? 'none'));

try {
    // Get employee role and facility for access control
    $employee_role = strtolower($_SESSION['role'] ?? '');
    $employee_facility_id = $_SESSION['facility_id'] ?? null;
    
    // Test database connection first
    if (!$pdo) {
        throw new Exception('Database connection not available');
    }
    
    // Simple test query to see if lab_orders table exists
    $test_query = "SELECT COUNT(*) as total FROM lab_orders WHERE patient_id = :patient_id";
    $test_stmt = $pdo->prepare($test_query);
    $test_stmt->execute([':patient_id' => $patient_id]);
    $test_result = $test_stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("Laboratory API: Found " . $test_result['total'] . " lab orders for patient $patient_id");
    
    // Build access control conditions
    $access_conditions = [];
    $access_params = [':patient_id' => $patient_id];
    
    // Role-based access control
    switch ($employee_role) {
        case 'bhw':
            // BHW: Only patients from their facility's barangay
            // Get the facility's barangay and limit to patients from that barangay
            $facility_check = "
                SELECT f.barangay_id 
                FROM employees e 
                JOIN facilities f ON e.facility_id = f.facility_id 
                WHERE e.employee_id = :employee_id
            ";
            $access_conditions[] = "p.barangay_id IN ($facility_check)";
            $access_params[':employee_id'] = $_SESSION['employee_id'];
            break;
            
        case 'dho':
        case 'admin':
        case 'doctor':
        case 'nurse':
        case 'laboratory_tech':
        case 'records_officer':
            // Full access - no additional restrictions
            break;
            
        default:
            // Default facility restriction for other roles
            if ($employee_facility_id) {
                $access_conditions[] = "(v.facility_id = :facility_id OR c.facility_id = :facility_id)";
                $access_params[':facility_id'] = $employee_facility_id;
            }
            break;
    }
    
    // Construct WHERE clause for access control
    $access_where = '';
    if (!empty($access_conditions)) {
        $access_where = 'AND (' . implode(' OR ', $access_conditions) . ')';
    }
    
    // Main query to get laboratory orders with comprehensive details
    $sql = "
        SELECT 
            lo.lab_order_id,
            lo.patient_id,
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
            CONCAT('PAT-', LPAD(p.patient_id, 8, '0')) as patient_id_display,
            lo.appointment_id,
            lo.consultation_id,
            lo.visit_id,
            lo.order_date,
            lo.status as order_status,
            lo.overall_status,
            lo.remarks as order_remarks,
            lo.ordered_by_employee_id,
            CONCAT(e.first_name, ' ', e.last_name) as ordered_by_name,
            r.role_name as ordered_by_role,
            f.name as facility_name,
            
            -- Count and aggregate lab order items
            COUNT(loi.item_id) as total_items,
            COUNT(CASE WHEN loi.status = 'completed' THEN 1 END) as completed_items,
            COUNT(CASE WHEN loi.status = 'pending' THEN 1 END) as pending_items,
            COUNT(CASE WHEN loi.status = 'in_progress' THEN 1 END) as in_progress_items,
            COUNT(CASE WHEN loi.status = 'cancelled' THEN 1 END) as cancelled_items,
            
            -- Aggregate test types
            GROUP_CONCAT(
                DISTINCT loi.test_type 
                ORDER BY loi.test_type ASC 
                SEPARATOR '; '
            ) as test_types,
            
            -- Aggregate individual test statuses
            GROUP_CONCAT(
                DISTINCT CONCAT(loi.test_type, ' (', loi.status, ')')
                ORDER BY loi.test_type ASC
                SEPARATOR '; '
            ) as test_status_details,
            
            -- Results information
            COUNT(CASE WHEN loi.result_file IS NOT NULL THEN 1 END) as items_with_results,
            GROUP_CONCAT(
                CASE WHEN loi.result_date IS NOT NULL 
                THEN CONCAT(loi.test_type, ': ', DATE_FORMAT(loi.result_date, '%M %d, %Y'))
                END
                ORDER BY loi.result_date DESC
                SEPARATOR '; '
            ) as result_dates,
            
            -- Latest activity
            GREATEST(
                COALESCE(MAX(loi.completed_at), '1970-01-01'),
                COALESCE(MAX(loi.started_at), '1970-01-01'),
                lo.order_date
            ) as latest_activity,
            
            lo.created_at,
            lo.updated_at
            
        FROM lab_orders lo
        INNER JOIN patients p ON lo.patient_id = p.patient_id
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        LEFT JOIN roles r ON e.role_id = r.role_id
        LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        LEFT JOIN visits v ON lo.visit_id = v.visit_id
        LEFT JOIN consultations c ON lo.consultation_id = c.consultation_id
        LEFT JOIN facilities f ON v.facility_id = f.facility_id
        
        WHERE lo.patient_id = :patient_id
        $access_where
        
        GROUP BY 
            lo.lab_order_id, lo.patient_id, lo.appointment_id, lo.consultation_id, 
            lo.visit_id, lo.order_date, lo.status, lo.overall_status, lo.remarks,
            lo.ordered_by_employee_id, lo.created_at, lo.updated_at,
            p.first_name, p.middle_name, p.last_name, p.patient_id,
            e.first_name, e.last_name, r.role_name, f.name
            
        ORDER BY lo.order_date DESC, lo.lab_order_id DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($access_params);
    $laboratory_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the laboratory data for frontend consumption
    $formatted_orders = [];
    foreach ($laboratory_orders as $order) {
        // Format dates
        $order_date = new DateTime($order['order_date']);
        $latest_activity = new DateTime($order['latest_activity']);
        
        // Calculate progress percentage
        $total = (int)$order['total_items'];
        $completed = (int)$order['completed_items'];
        $progress_percentage = $total > 0 ? round(($completed / $total) * 100) : 0;
        
        // Determine progress status
        $progress_status = 'Not Started';
        $progress_class = 'badge-secondary';
        
        if ($completed === $total && $total > 0) {
            $progress_status = 'Completed';
            $progress_class = 'badge-success';
        } elseif ($completed > 0) {
            $progress_status = 'In Progress';
            $progress_class = 'badge-warning';
        } elseif ($order['in_progress_items'] > 0) {
            $progress_status = 'Processing';
            $progress_class = 'badge-info';
        }
        
        // Format overall status
        $status_class = 'badge-secondary';
        switch (strtolower($order['overall_status'])) {
            case 'completed':
                $status_class = 'badge-success';
                break;
            case 'in_progress':
            case 'pending':
                $status_class = 'badge-warning';
                break;
            case 'cancelled':
                $status_class = 'badge-danger';
                break;
        }
        
        $formatted_orders[] = [
            'lab_order_id' => $order['lab_order_id'],
            'patient_id' => $order['patient_id'],
            'patient_name' => $order['patient_name'],
            'patient_id_display' => $order['patient_id_display'],
            'appointment_id' => $order['appointment_id'],
            'consultation_id' => $order['consultation_id'],
            'visit_id' => $order['visit_id'],
            'order_date' => $order['order_date'],
            'formatted_order_date' => $order_date->format('M d, Y'),
            'formatted_order_time' => $order_date->format('h:i A'),
            'order_status' => $order['order_status'],
            'overall_status' => $order['overall_status'],
            'formatted_overall_status' => ucfirst($order['overall_status']),
            'status_class' => $status_class,
            'order_remarks' => $order['order_remarks'],
            'ordered_by_employee_id' => $order['ordered_by_employee_id'],
            'ordered_by_name' => $order['ordered_by_name'] ?: 'N/A',
            'ordered_by_role' => $order['ordered_by_role'] ?: 'N/A',
            'facility_name' => $order['facility_name'] ?: 'N/A',
            'total_items' => $total,
            'completed_items' => $completed,
            'pending_items' => (int)$order['pending_items'],
            'in_progress_items' => (int)$order['in_progress_items'],
            'cancelled_items' => (int)$order['cancelled_items'],
            'test_types' => $order['test_types'] ?: 'No tests recorded',
            'test_status_details' => $order['test_status_details'] ?: 'No status available',
            'items_with_results' => (int)$order['items_with_results'],
            'result_dates' => $order['result_dates'] ?: 'No results available',
            'progress_percentage' => $progress_percentage,
            'progress_status' => $progress_status,
            'progress_class' => $progress_class,
            'latest_activity' => $order['latest_activity'],
            'formatted_latest_activity' => $latest_activity->format('M d, Y h:i A'),
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at']
        ];
    }
    
    // Return successful response
    echo json_encode([
        'success' => true,
        'laboratory_orders' => $formatted_orders,
        'total' => count($formatted_orders),
        'patient_id' => $patient_id,
        'access_level' => $employee_role
    ]);
       
} catch (Exception $e) {
    // Log error for debugging
    error_log("Laboratory API Error: " . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load laboratory data: ' . $e->getMessage(),
        'error_code' => 'LAB_LOAD_ERROR'
    ]);
    exit();
}
?>