<?php
ob_start(); // Start output buffering to prevent header issues
session_start();

$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/db.php';
require_once $root_path . '/config/session/employee_session.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!is_employee_logged_in()) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

try {
    $status = $_GET['status'] ?? 'all';
    $patient_id = $_GET['patient_id'] ?? null;
    
    $where_conditions = [];
    $params = [];
    
    if ($status !== 'all') {
        $where_conditions[] = "pi.status = ?";
        $params[] = $status;
    }
    
    if ($patient_id) {
        $where_conditions[] = "pi.patient_id = ?";
        $params[] = $patient_id;
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    $stmt = $pdo->prepare("
        SELECT 
            pi.*,
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.contact_number,
            CONCAT(e.first_name, ' ', e.last_name) as cashier_name,
            COUNT(ii.item_id) as item_count,
            GROUP_CONCAT(ii.service_name SEPARATOR ', ') as services
        FROM patient_invoices pi
        JOIN patients p ON pi.patient_id = p.patient_id
        LEFT JOIN employees e ON pi.created_by = e.employee_id
        LEFT JOIN invoice_items ii ON pi.invoice_id = ii.invoice_id
        $where_clause
        GROUP BY pi.invoice_id
        ORDER BY pi.created_at DESC
    ");
    
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the data
    foreach ($invoices as &$invoice) {
        $invoice['total_amount'] = number_format($invoice['total_amount'], 2);
        $invoice['created_at'] = date('M d, Y H:i', strtotime($invoice['created_at']));
        $invoice['updated_at'] = date('M d, Y H:i', strtotime($invoice['updated_at']));
    }
    
    echo json_encode($invoices);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

ob_end_flush(); // End output buffering
?>