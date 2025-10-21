<?php
/**
 * API Endpoint: Get Payments
 * Purpose: Retrieve payment records for management
 */

header('Content-Type: application/json');

// Include necessary files
$root_path = dirname(dirname(dirname(dirname(__FILE__))));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in and authorized
if (!is_employee_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$employee_role = get_employee_session('role');

// Check if role is authorized for billing operations
$allowed_roles = ['Cashier', 'Admin'];
if (!in_array($employee_role, $allowed_roles)) {
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

try {
    // Get query parameters
    $date_from = $_GET['date_from'] ?? date('Y-m-01'); // Default to start of current month
    $date_to = $_GET['date_to'] ?? date('Y-m-t'); // Default to end of current month
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);

    // Build query
    $query = "SELECT p.*, b.billing_id, b.patient_id, b.net_amount, b.total_amount, b.discount_amount,
                     pt.first_name, pt.last_name, pt.username as patient_username,
                     e.first_name as cashier_first_name, e.last_name as cashier_last_name
              FROM payments p
              JOIN billing b ON p.billing_id = b.billing_id
              JOIN patients pt ON b.patient_id = pt.patient_id
              JOIN employees e ON p.cashier_id = e.employee_id
              WHERE DATE(p.paid_at) BETWEEN ? AND ?
              ORDER BY p.paid_at DESC
              LIMIT ? OFFSET ?";

    $stmt = $pdo->prepare($query);
    $stmt->execute([$date_from, $date_to, $limit, $offset]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM payments p WHERE DATE(p.paid_at) BETWEEN ? AND ?";
    $stmt = $pdo->prepare($count_query);
    $stmt->execute([$date_from, $date_to]);
    $total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get summary statistics
    $summary_query = "SELECT 
                        COUNT(*) as total_payments,
                        SUM(p.amount_paid) as total_collected,
                        AVG(p.amount_paid) as average_payment
                      FROM payments p 
                      WHERE DATE(p.paid_at) BETWEEN ? AND ?";
    $stmt = $pdo->prepare($summary_query);
    $stmt->execute([$date_from, $date_to]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'payments' => $payments,
            'summary' => $summary,
            'pagination' => [
                'total_records' => intval($total_records),
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total_records
            ]
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error retrieving payments: ' . $e->getMessage()]);
}
?>