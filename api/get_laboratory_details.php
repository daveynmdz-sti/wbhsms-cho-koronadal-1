<?php
/**
 * Laboratory Order Details API
 * 
 * Fetches detailed information for a specific laboratory order including:
 * - Lab order details
 * - All connected lab_order_items (without result_file LONGBLOB)
 * - Patient information
 * - Ordering physician details
 * - Facility information
 */

// Error reporting and CORS
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

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
    $lab_order_id = isset($_GET['lab_order_id']) ? intval($_GET['lab_order_id']) : 0;
    
    if ($lab_order_id <= 0) {
        throw new Exception('Valid lab_order_id is required');
    }
    
    // Validate database connection
    if (!isset($pdo) || !$pdo) {
        throw new Exception('Database connection not available');
    }
    
    error_log("Laboratory Details API: Processing request for lab_order_id: $lab_order_id");
    
    // Get employee role for access control
    $employee_role = strtolower($_SESSION['role'] ?? '');
    error_log("Laboratory Details API: User role: $employee_role");
    
    // Main query to get laboratory order details with all related information
    $sql = "
        SELECT 
            -- Lab order information
            lo.lab_order_id,
            lo.patient_id,
            lo.appointment_id,
            lo.consultation_id,
            lo.visit_id,
            lo.order_date,
            lo.status as order_status,
            lo.overall_status,
            lo.remarks as order_remarks,
            lo.ordered_by_employee_id,
            lo.created_at,
            lo.updated_at,
            
            -- Patient information
            CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as patient_name,
            CONCAT('PAT-', LPAD(p.patient_id, 8, '0')) as patient_id_display,
            p.date_of_birth,
            p.sex,
            p.contact_number,
            TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as patient_age,
            
            -- Barangay information
            b.barangay_name,
            
            -- Ordering physician information
            CONCAT(e.first_name, ' ', e.last_name) as ordered_by_name,
            r.role_name as ordered_by_role,
            e.license_number as physician_license,
            
            -- Facility information
            f.name as facility_name,
            f.type as facility_type,
            f.district as facility_district
            
        FROM lab_orders lo
        INNER JOIN patients p ON lo.patient_id = p.patient_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        LEFT JOIN roles r ON e.role_id = r.role_id
        LEFT JOIN visits v ON lo.visit_id = v.visit_id
        LEFT JOIN facilities f ON v.facility_id = f.facility_id
        
        WHERE lo.lab_order_id = :lab_order_id
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':lab_order_id' => $lab_order_id]);
    $lab_order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lab_order) {
        throw new Exception('Laboratory order not found');
    }
    
    error_log("Laboratory Details API: Found lab order for patient: " . $lab_order['patient_name']);
    
    // Get all lab order items for this order (without result_file LONGBLOB)
    $items_sql = "
        SELECT 
            loi.item_id,
            loi.lab_order_id,
            loi.test_type,
            loi.urgency,
            loi.status as item_status,
            loi.remarks as item_remarks,
            loi.employee_id,
            loi.started_at,
            loi.completed_at,
            loi.result_date,
            loi.created_at as item_created_at,
            loi.updated_at as item_updated_at,
            
            -- Check if result file exists (without fetching the LONGBLOB)
            CASE WHEN loi.result_file IS NOT NULL THEN 1 ELSE 0 END as has_result_file,
            
            -- Get technician information if employee_id is available
            CONCAT(e.first_name, ' ', e.last_name) as technician_name,
            r.role_name as technician_role
            
        FROM lab_order_items loi
        LEFT JOIN employees e ON loi.employee_id = e.employee_id
        LEFT JOIN roles r ON e.role_id = r.role_id
        
        WHERE loi.lab_order_id = :lab_order_id
        ORDER BY loi.urgency DESC, loi.test_type ASC, loi.item_id ASC
    ";
    
    error_log("Laboratory Details API: Executing items query: " . $items_sql);
    $items_stmt = $pdo->prepare($items_sql);
    $items_stmt->execute([':lab_order_id' => $lab_order_id]);
    $lab_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Laboratory Details API: Found " . count($lab_items) . " lab items");
    
    // Format the response data
    $formatted_order = [
        // Lab order details
        'lab_order_id' => $lab_order['lab_order_id'],
        'order_id_display' => 'LAB-' . str_pad($lab_order['lab_order_id'], 8, '0', STR_PAD_LEFT),
        'patient_id' => $lab_order['patient_id'],
        'appointment_id' => $lab_order['appointment_id'],
        'consultation_id' => $lab_order['consultation_id'],
        'visit_id' => $lab_order['visit_id'],
        'order_status' => $lab_order['order_status'],
        'overall_status' => $lab_order['overall_status'],
        'order_remarks' => $lab_order['order_remarks'],
        
        // Formatted dates
        'order_date' => $lab_order['order_date'],
        'formatted_order_date' => date('F j, Y', strtotime($lab_order['order_date'])),
        'formatted_order_time' => date('g:i A', strtotime($lab_order['order_date'])),
        'formatted_order_datetime' => date('F j, Y \a\t g:i A', strtotime($lab_order['order_date'])),
        
        // Patient information
        'patient_name' => $lab_order['patient_name'],
        'patient_id_display' => $lab_order['patient_id_display'],
        'patient_age' => $lab_order['patient_age'],
        'patient_gender' => $lab_order['sex'],
        'patient_contact' => $lab_order['contact_number'],
        'patient_dob' => $lab_order['date_of_birth'],
        'patient_barangay' => $lab_order['barangay_name'],
        'formatted_patient_dob' => date('F j, Y', strtotime($lab_order['date_of_birth'])),
        
        // Ordering physician
        'ordered_by_name' => $lab_order['ordered_by_name'],
        'ordered_by_role' => $lab_order['ordered_by_role'],
        'physician_license' => $lab_order['physician_license'],
        
        // Facility information
        'facility_name' => $lab_order['facility_name'],
        'facility_type' => $lab_order['facility_type'],
        'facility_district' => $lab_order['facility_district'],
        
        // Timestamps
        'created_at' => $lab_order['created_at'],
        'updated_at' => $lab_order['updated_at']
    ];
    
    // Format lab items
    $formatted_items = [];
    $total_items = count($lab_items);
    $completed_items = 0;
    $pending_items = 0;
    $in_progress_items = 0;
    $cancelled_items = 0;
    
    foreach ($lab_items as $item) {
        // Count item statuses
        switch (strtolower($item['item_status'])) {
            case 'completed':
                $completed_items++;
                break;
            case 'pending':
                $pending_items++;
                break;
            case 'in_progress':
                $in_progress_items++;
                break;
            case 'cancelled':
                $cancelled_items++;
                break;
        }
        
        $formatted_item = [
            'item_id' => $item['item_id'],
            'test_type' => $item['test_type'],
            'urgency' => $item['urgency'],
            'status' => $item['item_status'],
            'remarks' => $item['item_remarks'],
            'has_result_file' => (bool)$item['has_result_file'],
            
            // Technician information
            'technician_name' => $item['technician_name'],
            'technician_role' => $item['technician_role'],
            'employee_id' => $item['employee_id'],
            
            // Dates
            'started_at' => $item['started_at'],
            'completed_at' => $item['completed_at'],
            'result_date' => $item['result_date'],
            
            'formatted_started_at' => $item['started_at'] ? date('M j, Y g:i A', strtotime($item['started_at'])) : null,
            'formatted_completed_at' => $item['completed_at'] ? date('M j, Y g:i A', strtotime($item['completed_at'])) : null,
            'formatted_result_date' => $item['result_date'] ? date('M j, Y g:i A', strtotime($item['result_date'])) : null,
            
            // Status styling
            'status_class' => match(strtolower($item['item_status'])) {
                'completed' => 'badge-success',
                'in_progress' => 'badge-warning',
                'pending' => 'badge-secondary',
                'cancelled' => 'badge-danger',
                default => 'badge-light'
            },
            
            // Urgency styling
            'urgency_class' => match(strtoupper($item['urgency'])) {
                'STAT' => 'badge-danger',
                'Routine' => 'badge-info',
                default => 'badge-secondary'
            }
        ];
        
        $formatted_items[] = $formatted_item;
    }
    
    // Calculate progress
    $progress_percentage = $total_items > 0 ? round(($completed_items / $total_items) * 100) : 0;
    
    // Add summary information
    $formatted_order['lab_items'] = $formatted_items;
    $formatted_order['total_items'] = $total_items;
    $formatted_order['completed_items'] = $completed_items;
    $formatted_order['pending_items'] = $pending_items;
    $formatted_order['in_progress_items'] = $in_progress_items;
    $formatted_order['cancelled_items'] = $cancelled_items;
    $formatted_order['progress_percentage'] = $progress_percentage;
    
    // Success response
    echo json_encode([
        'success' => true,
        'laboratory_order' => $formatted_order,
        'access_level' => $employee_role
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("Laboratory Details API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>