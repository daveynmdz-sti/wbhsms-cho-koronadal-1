<?php
// api/get_patient_billing.php - Get billing history for a specific patient

// Clean any previous output and start fresh
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// Load environment configuration
$root_path = dirname(__DIR__);
require_once $root_path . '/config/env.php';

// Set error reporting based on environment
if (getenv('APP_DEBUG') === '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', 0); // Never display errors in API responses
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
$authorized_roles = ['admin', 'dho', 'bhw', 'doctor', 'nurse', 'records_officer', 'cashier'];
require_employee_auth($authorized_roles);

// Database connection
require_once $root_path . '/config/db.php';

try {
    // Validate patient_id parameter
    $patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
    
    if ($patient_id <= 0) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid patient ID provided'
        ]);
        exit;
    }

    // Role-based access control - ensure employee can only access billing from their facility
    $employee_id = $_SESSION['employee_id'];
    $employee_role = strtolower($_SESSION['role']);
    
    // Get employee's facility
    $stmt = $conn->prepare("SELECT facility_id FROM employees WHERE employee_id = ?");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $employee_facility = $result->fetch_assoc();
    $stmt->close();
    
    if (!$employee_facility) {
        echo json_encode([
            'success' => false,
            'error' => 'Employee facility not found'
        ]);
        exit;
    }

    // Build the main query with comprehensive billing information
    $sql = "
        SELECT DISTINCT
            b.billing_id,
            b.visit_id,
            b.billing_date,
            b.total_amount,
            b.discount_amount,
            b.discount_type,
            b.net_amount,
            b.paid_amount,
            b.payment_status,
            b.notes as billing_notes,
            
            -- Patient information
            CONCAT(p.first_name, ' ', 
                   CASE WHEN p.middle_name IS NOT NULL AND p.middle_name != '' 
                        THEN CONCAT(p.middle_name, ' ') 
                        ELSE '' END, 
                   p.last_name) as patient_name,
            p.username as patient_id_display,
            
            -- Visit information
            v.visit_date,
            v.visit_status,
            
            -- Facility information
            f.name as facility_name,
            
            -- Created by employee information
            CONCAT(e_created.first_name, ' ', e_created.last_name) as created_by_name,
            r_created.role_name as created_by_role,
            
            -- Service items information (aggregated)
            GROUP_CONCAT(
                DISTINCT CONCAT(si.item_name, ' (₱', CAST(bi.item_price AS DECIMAL(10,2)), ' x ', bi.quantity, ')')
                ORDER BY si.item_name
                SEPARATOR '; '
            ) as service_items,
            
            -- Payment information
            GROUP_CONCAT(
                DISTINCT CONCAT(
                    'Payment: ₱', CAST(pay.amount_paid AS DECIMAL(10,2)), 
                    ' (', pay.payment_method, ') on ', 
                    DATE_FORMAT(pay.paid_at, '%Y-%m-%d %H:%i')
                )
                ORDER BY pay.paid_at
                SEPARATOR '; '
            ) as payment_details,
            
            -- Receipt information
            GROUP_CONCAT(DISTINCT r.receipt_number ORDER BY r.receipt_number SEPARATOR ', ') as receipt_numbers,
            
            -- Cashier information for receipts
            GROUP_CONCAT(
                DISTINCT CONCAT(e_cashier.first_name, ' ', e_cashier.last_name)
                ORDER BY r.payment_date
                SEPARATOR ', '
            ) as cashier_names
            
        FROM billing b
        
        -- Join patient information
        INNER JOIN patients p ON b.patient_id = p.patient_id
        
        -- Join visit information
        LEFT JOIN visits v ON b.visit_id = v.visit_id
        
        -- Join facility information
        LEFT JOIN facilities f ON v.facility_id = f.facility_id
        
        -- Join created by employee information
        LEFT JOIN employees e_created ON b.created_by = e_created.employee_id
        LEFT JOIN roles r_created ON e_created.role_id = r_created.role_id
        
        -- Join billing items and service items
        LEFT JOIN billing_items bi ON b.billing_id = bi.billing_id
        LEFT JOIN service_items si ON bi.service_item_id = si.item_id
        
        -- Join payments
        LEFT JOIN payments pay ON b.billing_id = pay.billing_id
        
        -- Join receipts and cashier information
        LEFT JOIN receipts r ON b.billing_id = r.billing_id
        LEFT JOIN employees e_cashier ON r.received_by_employee_id = e_cashier.employee_id
        
        WHERE b.patient_id = ?";

    // No facility restrictions - show all billing records for the patient
    // This allows users to see billing history across all facilities

    $sql .= "
        GROUP BY b.billing_id
        ORDER BY b.billing_date DESC, b.billing_id DESC";

    // Prepare and execute query
    $stmt = $conn->prepare($sql);
    
    // No facility restrictions - all users can see all billing records for the patient
    error_log("Billing API: No facility restrictions - binding patient_id: $patient_id");
    $stmt->bind_param("i", $patient_id);
    
    error_log("Billing API: Final SQL query: " . $sql);
    $stmt->execute();
    $result = $stmt->get_result();
    
    error_log("Billing API: Query executed, rows found: " . $result->num_rows);
    
    $billing_records = [];
    while ($row = $result->fetch_assoc()) {
        // Format dates
        $row['formatted_billing_date'] = date('M j, Y g:i A', strtotime($row['billing_date']));
        $row['formatted_visit_date'] = $row['visit_date'] ? date('M j, Y', strtotime($row['visit_date'])) : 'N/A';
        
        // Format amounts
        $row['formatted_total_amount'] = '₱' . number_format($row['total_amount'], 2);
        $row['formatted_discount_amount'] = '₱' . number_format($row['discount_amount'], 2);
        $row['formatted_net_amount'] = '₱' . number_format($row['net_amount'], 2);
        $row['formatted_paid_amount'] = '₱' . number_format($row['paid_amount'], 2);
        
        // Calculate balance
        $balance = $row['net_amount'] - $row['paid_amount'];
        $row['balance_amount'] = $balance;
        $row['formatted_balance'] = '₱' . number_format($balance, 2);
        
        // Format discount type
        $row['formatted_discount_type'] = match($row['discount_type']) {
            'senior' => 'Senior Citizen',
            'pwd' => 'PWD',
            'staff' => 'Staff Discount',
            'other' => 'Other Discount',
            default => 'No Discount'
        };
        
        // Format payment status
        $row['formatted_payment_status'] = match($row['payment_status']) {
            'unpaid' => 'Unpaid',
            'partial' => 'Partially Paid',
            'paid' => 'Fully Paid',
            'exempted' => 'Exempted',
            'cancelled' => 'Cancelled',
            default => ucfirst($row['payment_status'])
        };
        
        // Handle null service items
        if (empty($row['service_items'])) {
            $row['service_items'] = 'No items recorded';
        }
        
        // Handle null payment details
        if (empty($row['payment_details'])) {
            $row['payment_details'] = 'No payments recorded';
        }
        
        $billing_records[] = $row;
    }
    
    $stmt->close();
    
    // Return successful response
    echo json_encode([
        'success' => true,
        'data' => $billing_records,
        'total_count' => count($billing_records),
        'patient_id' => $patient_id
    ]);

} catch (Exception $e) {
    error_log("Error in get_patient_billing.php: " . $e->getMessage());
    
    // Clean any output buffer and ensure clean JSON response
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    echo json_encode([
        'success' => false,
        'error' => 'Failed to retrieve billing history. Please try again.',
        'debug_info' => getenv('APP_DEBUG') === '1' ? $e->getMessage() : null
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

// Clean output and ensure JSON only
ob_end_clean();
?>