<?php
/**
 * API Endpoint: Search Invoices
 * Purpose: Search and filter billing records
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
    $search_term = $_GET['search'] ?? '';
    $payment_status = $_GET['payment_status'] ?? '';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);

    // Build base query
    $conditions = [];
    $params = [];

    // Search by patient name or ID
    if (!empty($search_term)) {
        $conditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR p.username LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)";
        $search_param = '%' . $search_term . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    // Filter by payment status
    if (!empty($payment_status)) {
        $conditions[] = "b.payment_status = ?";
        $params[] = $payment_status;
    }

    // Filter by date range
    if (!empty($date_from)) {
        $conditions[] = "DATE(b.billing_date) >= ?";
        $params[] = $date_from;
    }
    if (!empty($date_to)) {
        $conditions[] = "DATE(b.billing_date) <= ?";
        $params[] = $date_to;
    }

    $where_clause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // Main query
    $query = "SELECT b.*, p.first_name, p.last_name, p.username as patient_username, p.barangay,
                     e.first_name as created_by_first_name, e.last_name as created_by_last_name,
                     v.visit_date,
                     (SELECT COUNT(*) FROM billing_items bi WHERE bi.billing_id = b.billing_id) as items_count
              FROM billing b
              JOIN patients p ON b.patient_id = p.patient_id
              JOIN employees e ON b.created_by = e.employee_id
              LEFT JOIN visits v ON b.visit_id = v.visit_id
              $where_clause
              ORDER BY b.billing_date DESC
              LIMIT ? OFFSET ?";

    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $count_query = "SELECT COUNT(*) as total 
                    FROM billing b
                    JOIN patients p ON b.patient_id = p.patient_id
                    JOIN employees e ON b.created_by = e.employee_id
                    LEFT JOIN visits v ON b.visit_id = v.visit_id
                    $where_clause";

    // Remove limit and offset from params for count query
    $count_params = array_slice($params, 0, -2);
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($count_params);
    $total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    echo json_encode([
        'success' => true,
        'data' => [
            'invoices' => $invoices,
            'pagination' => [
                'total_records' => intval($total_records),
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $total_records
            ]
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error searching invoices: ' . $e->getMessage()]);
}
?>