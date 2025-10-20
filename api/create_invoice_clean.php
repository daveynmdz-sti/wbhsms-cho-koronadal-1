<?php
ob_start(); // Start output buffering to prevent header issues
session_start();

$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/db.php';
require_once $root_path . '/config/session/employee_session.php';

header('Content-Type: application/json');

// Check if user is logged in and has appropriate role
if (!is_employee_logged_in() || (get_employee_session('role') !== 'Cashier' && get_employee_session('role') !== 'Admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

try {
    // Get POST data
    $patient_id = $_POST['patient_id'] ?? null;
    $service_ids = json_decode($_POST['service_ids'] ?? '[]', true);
    $cashier_id = $_POST['cashier_id'] ?? get_employee_session('employee_id');

    // Validate input
    if (!$patient_id || empty($service_ids)) {
        throw new Exception('Patient ID and services are required');
    }

    // Start transaction
    $pdo->beginTransaction();

    // Get service details and calculate total
    $placeholders = str_repeat('?,', count($service_ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT id, service_name, price FROM services WHERE id IN ($placeholders)");
    $stmt->execute($service_ids);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($services) !== count($service_ids)) {
        throw new Exception('Some services not found');
    }

    $total_amount = array_sum(array_column($services, 'price'));

    // Create invoice
    $stmt = $pdo->prepare("
        INSERT INTO patient_invoices (patient_id, total_amount, status, created_by, created_at, updated_at) 
        VALUES (?, ?, 'pending', ?, NOW(), NOW())
    ");
    $stmt->execute([$patient_id, $total_amount, $cashier_id]);
    $invoice_id = $pdo->lastInsertId();

    // Add invoice items
    $stmt = $pdo->prepare("
        INSERT INTO invoice_items (invoice_id, service_id, service_name, unit_price, quantity, total_price) 
        VALUES (?, ?, ?, ?, 1, ?)
    ");

    foreach ($services as $service) {
        $stmt->execute([
            $invoice_id,
            $service['id'],
            $service['service_name'],
            $service['price'],
            $service['price']
        ]);
    }

    // Log the invoice creation
    $stmt = $pdo->prepare("
        INSERT INTO billing_logs (invoice_id, action, performed_by, notes, created_at) 
        VALUES (?, 'created', ?, 'Invoice created with " . count($services) . " service(s)', NOW())
    ");
    $stmt->execute([$invoice_id, $cashier_id]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'invoice_id' => $invoice_id,
        'total_amount' => $total_amount,
        'message' => 'Invoice created successfully'
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

ob_end_flush(); // End output buffering
?>