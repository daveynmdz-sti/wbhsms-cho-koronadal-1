<?php
ob_start(); // Start output buffering to prevent header issues

/**
 * Get Billing Reports API
 * Retrieve billing reports and analytics (management access only)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Root path for includes
$root_path = dirname(dirname(dirname(__DIR__)));

// Check if user is logged in as employee with proper role
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Authentication and authorization check
if (!is_employee_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit();
}

// Authorization check - Admin, Cashier, or management roles only
$user_role = get_employee_session('role');
if (!in_array($user_role, ['Admin', 'Cashier', 'Manager', 'DHO'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Management role required.']);
    exit();
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Get query parameters
    $report_type = $_GET['type'] ?? 'summary';
    $date_from = $_GET['date_from'] ?? date('Y-m-01'); // Default to start of current month
    $date_to = $_GET['date_to'] ?? date('Y-m-d'); // Default to today
    $cashier_id = $_GET['cashier_id'] ?? null;
    $status = $_GET['status'] ?? 'all';
    
    // Validate date parameters
    $from_date = DateTime::createFromFormat('Y-m-d', $date_from);
    $to_date = DateTime::createFromFormat('Y-m-d', $date_to);
    
    if (!$from_date || !$to_date) {
        throw new Exception('Invalid date format. Use YYYY-MM-DD');
    }
    
    if ($from_date > $to_date) {
        throw new Exception('Start date cannot be after end date');
    }
    
    $response = [];
    
    switch ($report_type) {
        case 'summary':
            $response = getBillingSummary($pdo, $date_from, $date_to, $cashier_id, $status);
            break;
            
        case 'detailed':
            $response = getDetailedReport($pdo, $date_from, $date_to, $cashier_id, $status);
            break;
            
        case 'daily':
            $response = getDailyReport($pdo, $date_from, $date_to, $cashier_id);
            break;
            
        case 'cashier':
            $response = getCashierReport($pdo, $date_from, $date_to);
            break;
            
        default:
            throw new Exception('Invalid report type');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $response,
        'parameters' => [
            'report_type' => $report_type,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'cashier_id' => $cashier_id,
            'status' => $status
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred'
    ]);
    
    // Log database errors for debugging
    error_log("Billing Reports API Database Error: " . $e->getMessage());
}

/**
 * Get billing summary report
 */
function getBillingSummary($pdo, $date_from, $date_to, $cashier_id = null, $status = 'all') {
    $where_clauses = ["DATE(pi.created_at) BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    
    if ($cashier_id) {
        $where_clauses[] = "pi.created_by = ?";
        $params[] = $cashier_id;
    }
    
    if ($status !== 'all') {
        $where_clauses[] = "pi.status = ?";
        $params[] = $status;
    }
    
    $where_clause = implode(' AND ', $where_clauses);
    
    // Summary statistics
    $summary_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_invoices,
            SUM(CASE WHEN pi.status = 'paid' THEN pi.total_amount ELSE 0 END) as total_revenue,
            SUM(CASE WHEN pi.status = 'pending' THEN pi.total_amount ELSE 0 END) as pending_amount,
            COUNT(CASE WHEN pi.status = 'paid' THEN 1 END) as paid_invoices,
            COUNT(CASE WHEN pi.status = 'pending' THEN 1 END) as pending_invoices,
            AVG(CASE WHEN pi.status = 'paid' THEN pi.total_amount END) as average_transaction
        FROM patient_invoices pi
        WHERE $where_clause
    ");
    
    $summary_stmt->execute($params);
    $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Payment method breakdown (for paid invoices only)
    $payment_methods_stmt = $pdo->prepare("
        SELECT 
            pp.payment_method,
            COUNT(*) as transaction_count,
            SUM(pi.total_amount) as total_amount
        FROM patient_invoices pi
        JOIN patient_payments pp ON pi.invoice_id = pp.invoice_id
        WHERE $where_clause AND pi.status = 'paid'
        GROUP BY pp.payment_method
        ORDER BY total_amount DESC
    ");
    
    $payment_methods_stmt->execute($params);
    $payment_methods = $payment_methods_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'summary' => $summary,
        'payment_methods' => $payment_methods
    ];
}

/**
 * Get detailed billing report
 */
function getDetailedReport($pdo, $date_from, $date_to, $cashier_id = null, $status = 'all') {
    $where_clauses = ["DATE(pi.created_at) BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    
    if ($cashier_id) {
        $where_clauses[] = "pi.created_by = ?";
        $params[] = $cashier_id;
    }
    
    if ($status !== 'all') {
        $where_clauses[] = "pi.status = ?";
        $params[] = $status;
    }
    
    $where_clause = implode(' AND ', $where_clauses);
    
    $detailed_stmt = $pdo->prepare("
        SELECT 
            pi.invoice_id,
            pi.invoice_number,
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            pi.total_amount,
            pi.status,
            pi.created_at,
            CONCAT(e.first_name, ' ', e.last_name) as created_by_name,
            pp.payment_method,
            pp.payment_amount,
            pp.change_amount,
            pp.receipt_number,
            pp.payment_date
        FROM patient_invoices pi
        JOIN patients p ON pi.patient_id = p.patient_id
        LEFT JOIN employees e ON pi.created_by = e.employee_id
        LEFT JOIN patient_payments pp ON pi.invoice_id = pp.invoice_id
        WHERE $where_clause
        ORDER BY pi.created_at DESC
    ");
    
    $detailed_stmt->execute($params);
    $transactions = $detailed_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'transactions' => $transactions
    ];
}

/**
 * Get daily billing report
 */
function getDailyReport($pdo, $date_from, $date_to, $cashier_id = null) {
    $where_clauses = ["DATE(pi.created_at) BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    
    if ($cashier_id) {
        $where_clauses[] = "pi.created_by = ?";
        $params[] = $cashier_id;
    }
    
    $where_clause = implode(' AND ', $where_clauses);
    
    $daily_stmt = $pdo->prepare("
        SELECT 
            DATE(pi.created_at) as report_date,
            COUNT(*) as total_invoices,
            SUM(CASE WHEN pi.status = 'paid' THEN pi.total_amount ELSE 0 END) as daily_revenue,
            COUNT(CASE WHEN pi.status = 'paid' THEN 1 END) as paid_invoices,
            COUNT(CASE WHEN pi.status = 'pending' THEN 1 END) as pending_invoices
        FROM patient_invoices pi
        WHERE $where_clause
        GROUP BY DATE(pi.created_at)
        ORDER BY report_date DESC
    ");
    
    $daily_stmt->execute($params);
    $daily_data = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'daily_breakdown' => $daily_data
    ];
}

/**
 * Get cashier performance report
 */
function getCashierReport($pdo, $date_from, $date_to) {
    $cashier_stmt = $pdo->prepare("
        SELECT 
            e.employee_id,
            CONCAT(e.first_name, ' ', e.last_name) as cashier_name,
            COUNT(pi.invoice_id) as total_invoices,
            SUM(CASE WHEN pi.status = 'paid' THEN pi.total_amount ELSE 0 END) as total_revenue,
            COUNT(CASE WHEN pi.status = 'paid' THEN 1 END) as paid_invoices,
            COUNT(CASE WHEN pi.status = 'pending' THEN 1 END) as pending_invoices,
            AVG(CASE WHEN pi.status = 'paid' THEN pi.total_amount END) as average_transaction
        FROM employees e
        LEFT JOIN patient_invoices pi ON e.employee_id = pi.created_by 
            AND DATE(pi.created_at) BETWEEN ? AND ?
        WHERE e.role IN ('Admin', 'Cashier')
        GROUP BY e.employee_id, e.first_name, e.last_name
        HAVING total_invoices > 0
        ORDER BY total_revenue DESC
    ");
    
    $cashier_stmt->execute([$date_from, $date_to]);
    $cashier_data = $cashier_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return [
        'cashier_performance' => $cashier_data
    ];
}

ob_end_flush(); // End output buffering
?>