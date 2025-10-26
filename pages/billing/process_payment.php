<?php

/**
 * Process Payment Page
 * Purpose: Cashier interface for receiving payments on invoices
 * UI Pattern: Topbar only (form page), follows create_referrals.php structure
 * 
 * Dual Receipt Architecture:
 * - payments table: Tracks payment transactions and financial processing
 * - receipts table: Tracks formal receipt generation for audit trail
 * Both tables share the same receipt_number for cross-reference
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Dynamic asset path detection for production compatibility
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];

// Extract base path from script location - go up 3 levels from /pages/billing/file.php to root
$base_path = dirname(dirname(dirname($script_name)));
if ($base_path === '/' || $base_path === '.') {
    $base_path = '';
}

// Construct full URLs for production compatibility
$assets_path = $protocol . '://' . $host . $base_path . '/assets';

// Check if user is logged in and authorized
if (!is_employee_logged_in()) {
    header('Location: ../management/auth/employee_login.php');
    exit();
}

$employee_id = get_employee_session('employee_id');
$employee_role = get_employee_session('role');

// Check if role is authorized for billing operations (case-insensitive)
$allowed_roles = ['cashier', 'admin'];
if (!in_array(strtolower($employee_role), $allowed_roles)) {
    header('Location: ../management/' . strtolower($employee_role) . '/dashboard.php');
    exit();
}

// Dynamic back URL detection based on referrer
$back_url = 'billing_management.php'; // Default fallback
$referrer = $_SERVER['HTTP_REFERER'] ?? '';

if (!empty($referrer)) {
    // Parse the referrer URL to determine the source page
    $parsed_url = parse_url($referrer);
    $referrer_path = $parsed_url['path'] ?? '';
    
    // Check different possible sources
    if (strpos($referrer_path, 'billing_station.php') !== false) {
        // Coming from billing station - go back to billing station
        $back_url = '../queueing/billing_station.php';
    } elseif (strpos($referrer_path, 'cashier/dashboard.php') !== false) {
        // Coming from cashier dashboard - go back to dashboard
        $back_url = '../management/cashier/dashboard.php';
    } elseif (strpos($referrer_path, 'billing_management.php') !== false) {
        // Coming from billing management - stay with default
        $back_url = 'billing_management.php';
    } elseif (strpos($referrer_path, 'admin/dashboard.php') !== false) {
        // Coming from admin dashboard
        $back_url = '../management/admin/dashboard.php';
    } elseif (strpos($referrer_path, 'create_invoice.php') !== false) {
        // Coming from create invoice - go back to create invoice with preserved params
        $back_url = 'create_invoice.php';
    }
}

// Also check for explicit back_from parameter in URL
if (isset($_GET['back_from'])) {
    switch ($_GET['back_from']) {
        case 'billing_station':
            $back_url = '../queueing/billing_station.php';
            break;
        case 'cashier_dashboard':
            $back_url = '../management/cashier/dashboard.php';
            break;
        case 'admin_dashboard':
            $back_url = '../management/admin/dashboard.php';
            break;
        case 'create_invoice':
            $back_url = 'create_invoice.php';
            break;
        case 'billing_management':
        default:
            $back_url = 'billing_management.php';
            break;
    }
}

// Include reusable topbar component
require_once $root_path . '/includes/topbar.php';

// Get billing_id from URL parameter or search
$billing_id = intval($_GET['billing_id'] ?? 0);
$invoice_data = null;
$invoice_items = [];

// Handle patient search with billing info
$search_results = [];
$search_query = $_GET['search'] ?? '';
$search_performed = !empty($search_query);

// Enhanced search to handle multiple fields like create_invoice.php
$first_name_search = $_GET['first_name'] ?? '';
$last_name_search = $_GET['last_name'] ?? '';
$barangay_search = $_GET['barangay'] ?? '';

// Also show search results if we have a billing_id (to maintain search context)
$show_search_results = $search_performed || $first_name_search || $last_name_search || $barangay_search || $billing_id;

if ($show_search_results) {
    try {
        $where_conditions = [];
        $params = [];

        // Build search conditions
        if (!empty($search_query)) {
            $where_conditions[] = "(p.username LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)";
            $search_term = '%' . $search_query . '%';
            $params = array_merge($params, [$search_term, $search_term, $search_term]);
        }

        if (!empty($first_name_search)) {
            $where_conditions[] = "p.first_name LIKE ?";
            $params[] = '%' . $first_name_search . '%';
        }

        if (!empty($last_name_search)) {
            $where_conditions[] = "p.last_name LIKE ?";
            $params[] = '%' . $last_name_search . '%';
        }

        if (!empty($barangay_search)) {
            $where_conditions[] = "bg.barangay_name LIKE ?";
            $params[] = '%' . $barangay_search . '%';
        }

        $where_clause = !empty($where_conditions)
            ? 'AND (' . implode(' AND ', $where_conditions) . ')'
            : '';

        $stmt = $pdo->prepare("
            SELECT DISTINCT p.patient_id, p.username, p.first_name, p.last_name, 
                   bg.barangay_name, b.billing_id, b.billing_date, b.net_amount, 
                   b.paid_amount, b.payment_status, b.created_by,
                   e.first_name as created_by_first_name, e.last_name as created_by_last_name
            FROM patients p 
            LEFT JOIN barangay bg ON p.barangay_id = bg.barangay_id
            INNER JOIN billing b ON p.patient_id = b.patient_id 
            INNER JOIN employees e ON b.created_by = e.employee_id
            WHERE b.payment_status IN ('unpaid', 'partial')
            $where_clause
            ORDER BY b.billing_date DESC, p.last_name, p.first_name
            LIMIT 20
        ");
        $stmt->execute($params);
        $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $search_performed = true;
    } catch (Exception $e) {
        $error_message = 'Error searching patients: ' . $e->getMessage();
    }
}

// Get barangays for filter dropdown
$barangays = [];
try {
    $stmt = $pdo->prepare("SELECT barangay_name FROM barangay WHERE status = 'active' ORDER BY barangay_name ASC");
    $stmt->execute();
    $barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore errors for barangays
}

if ($billing_id) {
    try {
        // Get invoice details
        $stmt = $pdo->prepare("SELECT b.*, p.first_name, p.last_name, p.username as patient_username, 
                                      v.visit_date, e.first_name as created_by_first_name, e.last_name as created_by_last_name
                               FROM billing b
                               JOIN patients p ON b.patient_id = p.patient_id  
                               JOIN employees e ON b.created_by = e.employee_id
                               LEFT JOIN visits v ON b.visit_id = v.visit_id
                               WHERE b.billing_id = ? AND b.payment_status IN ('unpaid', 'partial')");
        $stmt->execute([$billing_id]);
        $invoice_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($invoice_data) {
            // Get invoice items
            $stmt = $pdo->prepare("SELECT bi.*, si.item_name 
                                   FROM billing_items bi
                                   JOIN service_items si ON bi.service_item_id = si.item_id
                                   WHERE bi.billing_id = ?
                                   ORDER BY si.item_name");
            $stmt->execute([$billing_id]);
            $invoice_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $error_message = 'Error retrieving invoice details: ' . $e->getMessage();
    }
}

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug: Log POST data
    error_log("Payment POST received: " . print_r($_POST, true));
    
    $action = $_POST['action'] ?? '';
    error_log("Payment action: " . $action);

    if ($action === 'process_payment') {
        try {
            $pdo->beginTransaction();

            // Get form data
            $billing_id = intval($_POST['billing_id'] ?? 0);
            $amount_paid = floatval($_POST['amount_paid'] ?? 0);
            $payment_method = $_POST['payment_method'] ?? 'cash';
            $notes = trim($_POST['notes'] ?? '');

            // Validation
            if (!$billing_id) {
                throw new Exception('Invalid invoice ID.');
            }
            if ($amount_paid <= 0) {
                throw new Exception('Payment amount must be greater than zero.');
            }

            // Get billing details for validation
            $stmt = $pdo->prepare("SELECT * FROM billing WHERE billing_id = ? AND payment_status IN ('unpaid', 'partial')");
            $stmt->execute([$billing_id]);
            $billing = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$billing) {
                throw new Exception('Invoice not found or already paid.');
            }

            // Calculate remaining balance and change
            $remaining_amount = $billing['net_amount'] - $billing['paid_amount'];
            
            // Allow overpayments and calculate change (real-world scenario)
            $change_amount = 0;
            $actual_payment_amount = $amount_paid;
            
            if ($amount_paid > $remaining_amount) {
                // Customer overpaid - calculate change
                $change_amount = $amount_paid - $remaining_amount;
                $actual_payment_amount = $remaining_amount; // Only apply the remaining balance
            }

            // Calculate new totals
            $new_paid_amount = $billing['paid_amount'] + $actual_payment_amount;

            // Determine new payment status
            $new_status = 'paid';
            if ($new_paid_amount < $billing['net_amount'] - 0.01) {
                $new_status = 'partial';
            }

            // Generate receipt number
            $receipt_number = 'RCP-' . date('Ymd') . '-' . str_pad($billing_id, 6, '0', STR_PAD_LEFT);

            // Insert payment record (including change amount) - for payment processing
            $stmt = $pdo->prepare("INSERT INTO payments (billing_id, amount_paid, change_amount, payment_method, cashier_id, receipt_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$billing_id, $amount_paid, $change_amount, $payment_method, $employee_id, $receipt_number, $notes]);

            $payment_id = $pdo->lastInsertId();

            // Insert receipt record for audit trail - for formal receipt tracking
            $stmt = $pdo->prepare("INSERT INTO receipts (billing_id, receipt_number, amount_paid, change_amount, payment_method, received_by_employee_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$billing_id, $receipt_number, $amount_paid, $change_amount, $payment_method, $employee_id, $notes]);

            $receipt_id = $pdo->lastInsertId();
            error_log("Receipt record created - Receipt ID: $receipt_id");

            // Update billing record
            $stmt = $pdo->prepare("UPDATE billing SET paid_amount = ?, payment_status = ? WHERE billing_id = ?");
            $stmt->execute([$new_paid_amount, $new_status, $billing_id]);

            $pdo->commit();
            error_log("Payment processed successfully - Payment ID: $payment_id, Receipt ID: $receipt_id, Receipt: $receipt_number");
            error_log("Dual receipt architecture: payments table (ID: $payment_id) + receipts table (ID: $receipt_id) both linked by receipt_number: $receipt_number");

            // Set success data for receipt display
            $_SESSION['payment_success'] = [
                'payment_id' => $payment_id,
                'receipt_id' => $receipt_id,
                'billing_id' => $billing_id,
                'receipt_number' => $receipt_number,
                'amount_paid' => $amount_paid,
                'change_amount' => $change_amount,
                'payment_status' => $new_status,
                'patient_name' => $billing['first_name'] . ' ' . $billing['last_name'],
                'patient_id' => $billing['patient_username'] ?? 'N/A',
                'net_amount' => $billing['net_amount'],
                'total_paid' => $new_paid_amount
            ];

            $success_message = 'Payment processed successfully!';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollback();
            }
            error_log("Payment processing error: " . $e->getMessage());
            error_log("Error trace: " . $e->getTraceAsString());
            $error_message = 'Error processing payment: ' . $e->getMessage();
        }
    }
}

// Check for payment success from session
$payment_success_data = $_SESSION['payment_success'] ?? null;
if ($payment_success_data) {
    unset($_SESSION['payment_success']); // Clear after use
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Process Payment | CHO Koronadal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="<?= $assets_path ?>/css/topbar.css" />
    <link rel="stylesheet" href="<?= $assets_path ?>/css/profile-edit-responsive.css" />
    <link rel="stylesheet" href="<?= $assets_path ?>/css/profile-edit.css" />
    <link rel="stylesheet" href="<?= $assets_path ?>/css/edit.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        .search-container {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
            margin-bottom: 1rem;
        }

        .patient-table {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .patient-table table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            min-width: 600px;
        }

        .patient-table th,
        .patient-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .patient-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #0077b6;
        }

        .patient-table tbody tr:hover {
            background: #f8f9fa;
            cursor: pointer;
        }

        .selected-patient {
            background: #d4edda !important;
            border-left: 4px solid #28a745;
        }

        .form-section {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .invoice-header {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e9ecef;
        }

        .invoice-info h4 {
            color: #2196F3;
            margin-bottom: 0.5rem;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin: 0.25rem 0;
        }

        .info-label {
            font-weight: 600;
            color: #6c757d;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 1rem 0;
        }

        .items-table th,
        .items-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .items-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }

        .items-table .text-right {
            text-align: right;
        }

        .payment-summary {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin: 0.5rem 0;
        }

        .summary-row.total {
            font-size: 1.2em;
            font-weight: 600;
            border-top: 2px solid #ddd;
            padding-top: 0.5rem;
        }

        .summary-row.outstanding {
            color: #dc3545;
            font-weight: 600;
        }

        .payment-form {
            display: grid;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        /* Enhanced Payment Form Styles */
        .payment-info-card {
            background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            color: white;
            box-shadow: 0 4px 15px rgba(0, 119, 182, 0.2);
        }

        .payment-highlight {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 1.2rem;
        }

        .payment-highlight i {
            font-size: 1.5rem;
            color: #90e0ef;
        }

        .payment-form-grid {
            display: grid;
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .amount-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            border-left: 4px solid #28a745;
        }

        .payment-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 0.75rem;
            font-size: 1rem;
        }

        .payment-label i {
            color: #0077b6;
            font-size: 1.1rem;
        }

        .amount-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .currency-symbol {
            position: absolute;
            left: 1rem;
            font-size: 1.4rem;
            font-weight: 600;
            color: #28a745;
            z-index: 2;
        }

        .amount-input-enhanced {
            width: 100%;
            font-size: 1.5rem;
            padding: 1rem 1rem 1rem 2.5rem;
            border: 3px solid #e0e0e0;
            border-radius: 8px;
            text-align: right;
            font-weight: 600;
            background: white;
            transition: all 0.3s ease;
        }

        .amount-input-enhanced:focus {
            border-color: #28a745;
            outline: none;
            box-shadow: 0 0 0 4px rgba(40, 167, 69, 0.1);
            background: #f8fff8;
        }

        .input-help {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 0.5rem;
            font-style: italic;
        }

        .change-section {
            margin-top: 1rem;
        }

        .change-card {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border-radius: 12px;
            padding: 1.5rem;
            color: white;
            text-align: center;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.2);
            animation: slideInUp 0.3s ease-out;
        }

        .change-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .change-amount {
            font-size: 2rem;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .notes-section {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border-left: 4px solid #6c757d;
        }

        .notes-input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.95rem;
            font-family: inherit;
            resize: vertical;
            transition: border-color 0.3s ease;
        }

        .notes-input:focus {
            border-color: #0077b6;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
        }

        .payment-actions {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1rem;
            align-items: center;
            padding-top: 1.5rem;
            border-top: 2px solid #e9ecef;
        }

        .btn-large {
            padding: 1rem 2rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-large:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-large:not(:disabled):hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Enhanced Invoice Details Styles */
        .patient-banner {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            color: white;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.2);
        }

        .patient-banner-content {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .patient-banner-icon {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .patient-banner-icon i {
            font-size: 1.5rem;
            color: white;
        }

        .patient-banner-title {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 0.25rem;
        }

        .patient-banner-name {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .patient-banner-id {
            font-size: 0.95rem;
            opacity: 0.8;
        }

        .invoice-cards-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .invoice-card {
            background: white;
            border-radius: 12px;
            border: 1px solid #e9ecef;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .invoice-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.12);
        }

        .invoice-card-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 600;
            color: #495057;
        }

        .invoice-card-header i {
            color: #0077b6;
            font-size: 1.1rem;
        }

        .invoice-card-content {
            padding: 1.5rem;
        }

        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #f8f9fa;
        }

        .info-item:last-child {
            margin-bottom: 0;
            border-bottom: none;
        }

        .info-label {
            font-weight: 500;
            color: #6c757d;
            font-size: 0.9rem;
        }

        .info-value {
            font-weight: 600;
            color: #2c3e50;
            text-align: right;
        }

        .invoice-number {
            font-family: 'Courier New', monospace;
            background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .status-badge-enhanced {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-badge-enhanced i {
            font-size: 0.6rem;
        }

        .status-badge-enhanced.status-unpaid {
            background: #f8d7da;
            color: #721c24;
        }

        .status-badge-enhanced.status-partial {
            background: #fff3cd;
            color: #664d03;
        }

        .status-badge-enhanced.status-paid {
            background: #d1e7dd;
            color: #0f5132;
        }

        .services-section {
            margin-bottom: 2rem;
        }

        .services-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            font-weight: 600;
            font-size: 1.1rem;
            color: #2c3e50;
        }

        .services-header i {
            color: #0077b6;
            font-size: 1.2rem;
        }

        .services-table-wrapper {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .services-table {
            width: 100%;
            border-collapse: collapse;
        }

        .services-table thead th {
            background: linear-gradient(135deg, #495057 0%, #343a40 100%);
            color: white;
            padding: 1rem;
            font-weight: 600;
            text-align: left;
            font-size: 0.9rem;
        }

        .services-table thead th i {
            margin-right: 0.5rem;
            opacity: 0.8;
        }

        .services-table tbody td {
            padding: 1rem;
            border-bottom: 1px solid #f8f9fa;
        }

        .services-table tbody tr:last-child td {
            border-bottom: none;
        }

        .services-table tbody tr:hover {
            background: #f8f9fa;
        }

        .service-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .service-item i {
            color: #28a745;
            font-size: 0.8rem;
        }

        .price-cell, .quantity-cell, .subtotal-cell {
            font-weight: 600;
            color: #495057;
        }

        .subtotal-cell {
            color: #0077b6;
            font-weight: 700;
        }

        .financial-summary {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #dee2e6;
        }

        .summary-header {
            background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
            color: white;
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .summary-content {
            padding: 1.5rem;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
            padding: 0.75rem;
            border-radius: 8px;
            background: white;
            border: 1px solid #f1f3f4;
        }

        .summary-item:last-child {
            margin-bottom: 0;
        }

        .summary-label {
            font-weight: 500;
            color: #495057;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .summary-value {
            font-weight: 700;
            font-size: 1.1rem;
        }

        .discount-item {
            background: #fff3cd;
            border-color: #ffeaa7;
        }

        .discount-value {
            color: #d68910;
        }

        .total-item {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            border-color: #90caf9;
            font-size: 1.1rem;
        }

        .total-value {
            color: #1565c0;
            font-size: 1.3rem;
        }

        .paid-item {
            background: #d1e7dd;
            border-color: #a3d9a4;
        }

        .paid-value {
            color: #155724;
        }

        .outstanding-item {
            background: linear-gradient(135deg, #f8d7da 0%, #f1aeb5 100%);
            border-color: #ea868f;
            border-width: 2px;
            font-size: 1.1rem;
        }

        .outstanding-value {
            color: #721c24;
            font-size: 1.3rem;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .summary-divider {
            height: 2px;
            background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
            margin: 1rem 0;
            border-radius: 2px;
        }

        @media (max-width: 768px) {
            .payment-actions {
                grid-template-columns: 1fr;
            }
            
            .amount-input-enhanced {
                font-size: 1.2rem;
            }
            
            .change-amount {
                font-size: 1.5rem;
            }

            .invoice-cards-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .patient-banner-content {
                flex-direction: column;
                text-align: center;
            }

            .services-table {
                font-size: 0.8rem;
            }

            .services-table thead th,
            .services-table tbody td {
                padding: 0.75rem 0.5rem;
            }

            .summary-item {
                flex-direction: column;
                gap: 0.5rem;
                text-align: center;
            }
        }

        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }

        .alert-success {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-error {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .btn-close {
            float: right;
            background: none;
            border: none;
            font-size: 1.2em;
            cursor: pointer;
            opacity: 0.7;
        }

        .btn-close:hover {
            opacity: 1;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
        }

        .modal-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }

        .receipt-content {
            background: white;
            padding: 2rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }

        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 1rem;
            margin-bottom: 1rem;
        }

        .receipt-row {
            display: flex;
            justify-content: space-between;
            margin: 0.25rem 0;
        }

        .receipt-total {
            border-top: 1px solid #000;
            padding-top: 0.5rem;
            font-weight: bold;
        }

        .status-paid {
            color: #28a745;
            font-weight: bold;
        }

        .status-partial {
            color: #ffc107;
            font-weight: bold;
        }

        .status-unpaid {
            color: #dc3545;
            font-weight: bold;
        }

        /* Standardized Alert Styles */
        .alert {
            padding: 1rem 1.25rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
            border-radius: 0.375rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            animation: slideIn 0.3s ease-out;
        }

        .alert-success {
            color: #0f5132;
            background-color: #d1e7dd;
            border-color: #badbcc;
        }

        .alert-error {
            color: #842029;
            background-color: #f8d7da;
            border-color: #f5c2c7;
        }

        .alert .btn-close {
            position: absolute;
            top: 50%;
            right: 1rem;
            transform: translateY(-50%);
            background: none;
            border: none;
            font-size: 1.25rem;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .alert .btn-close:hover {
            opacity: 1;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }


        .form-section h3 {
            margin-bottom: 1.5rem;
            color: #2c3e50;
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-section h3 i {
            color: #3498db;
        }

        /* Patient Search Styles */
        .search-form {
            margin-bottom: 1rem;
        }

        .search-input-group {
            display: flex;
            gap: 0.75rem;
            align-items: stretch;
        }

        .search-input {
            flex: 1;
            padding: 0.75rem;
            border: 2px solid #ddd;
            border-radius: 0.375rem;
            font-size: 1rem;
            transition: border-color 0.2s;
        }

        .search-input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }

        .search-results {
            margin-top: 1.5rem;
        }

        .patients-table-container {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #e9ecef;
            margin-top: 1rem;
        }

        .patients-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .patients-table th {
            background-color: #f8f9fa;
            color: #495057;
            font-weight: 600;
            padding: 1rem;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .patients-table td {
            padding: 1rem;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }

        .patient-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .patient-row:hover {
            background-color: #f8f9fa;
        }

        .invoice-info {
            min-width: 120px;
        }

        .invoice-info strong {
            color: #2c3e50;
            font-size: 1em;
        }

        .patient-info strong {
            color: #2c3e50;
            display: block;
            margin-bottom: 0.25rem;
        }

        .amount-info {
            text-align: right;
            min-width: 130px;
        }

        .amount-info strong {
            color: #2c3e50;
        }

        .outstanding {
            color: #dc3545 !important;
            font-weight: 600;
        }

        .status-info {
            text-align: center;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-paid {
            background-color: #d1e7dd;
            color: #0f5132;
        }

        .status-partial {
            background-color: #fff3cd;
            color: #664d03;
        }

        .status-unpaid {
            background-color: #f8d7da;
            color: #721c24;
        }

        .action-info {
            text-align: center;
            width: 100px;
        }

        .btn-sm {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            border-radius: 0.25rem;
        }

        .text-muted {
            color: #6c757d;
            font-size: 0.875rem;
        }

        .alert-info {
            color: #055160;
            background-color: #cff4fc;
            border-color: #b6effb;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #0077b6;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
        }

        .empty-search {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }

        /* Mobile patient cards */
        .patient-card {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin: 0.5rem 0;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
            display: none;
        }

        .patient-card:hover {
            border-color: #0077b6;
            box-shadow: 0 2px 8px rgba(0, 119, 182, 0.1);
        }

        .patient-card.selected {
            border-color: #28a745;
            background: #d4edda;
        }

        .patient-card-checkbox {
            margin-right: 0.5rem;
        }

        .patient-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .patient-card-name {
            font-weight: 600;
            color: #0077b6;
        }

        .patient-card-id {
            font-size: 0.9rem;
            color: #6c757d;
            background: #f8f9fa;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }

        .patient-card-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
        }

        .patient-card-detail {
            display: flex;
            flex-direction: column;
        }

        .patient-card-label {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 500;
        }

        .patient-card-value {
            font-size: 0.9rem;
            color: #333;
        }

        /* Responsive behavior */
        @media (max-width: 768px) {
            .patient-table-container {
                display: none;
            }

            .patient-card {
                display: block;
            }

            .patient-card-details {
                grid-template-columns: 1fr;
            }

            .search-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 769px) {
            .patient-card {
                display: none;
            }

            .patient-table-container {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>

<body>
    <?php renderTopbar([
        'title' => 'Process Payment',
        'back_url' => $back_url,
        'user_type' => 'employee'
    ]); ?>

    <section class="homepage">
        <?php
        // Render back button with modal
        renderBackButton([
            'back_url' => $back_url,
            'button_text' => '← Back / Cancel',
            'modal_title' => 'Cancel Payment Processing?',
            'modal_message' => 'Are you sure you want to go back/cancel? Any unsaved changes will be lost.',
            'confirm_text' => 'Yes, Cancel',
            'stay_text' => 'Stay'
        ]);
        ?>

        <div class="profile-wrapper" style="padding: 1.5rem;">
            <!-- Reminders Box -->
            <div class="reminders-box">
                <strong>Reminders:</strong>
                <ul>
                    <li>Search and select a patient with unpaid invoices from the list below before processing payment.</li>
                    <li>You can search by patient ID, name, or barangay.</li>
                    <li>Verify the invoice details and outstanding balance before processing payment.</li>
                    <li>Enter the exact amount given by the patient (can be more than the balance for change calculation).</li>
                    <li>All payment information should be accurate and complete.</li>
                </ul>
            </div>

            <!-- Alert Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <!-- Patient Search Section -->
            <div class="search-container">
                <h3 style="margin-bottom: 1rem;"><i class="fas fa-search"></i> Search Patient for Payment Processing</h3>
                <form method="GET" class="search-grid">
                    <div class="form-group">
                        <label for="search">Patient ID</label>
                        <input type="text" id="search" name="search" value="<?= htmlspecialchars($search_query) ?>"
                            placeholder="Patient ID (e.g. P000001)">
                    </div>
                    <div class="form-group">
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($first_name_search) ?>"
                            placeholder="Enter first name...">
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($last_name_search) ?>"
                            placeholder="Enter last name...">
                    </div>
                    <div class="form-group">
                        <label for="barangay">Barangay</label>
                        <select id="barangay" name="barangay">
                            <option value="">All Barangays</option>
                            <?php foreach ($barangays as $brgy): ?>
                                <option value="<?= htmlspecialchars($brgy['barangay_name']) ?>"
                                    <?= $barangay_search === $brgy['barangay_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($brgy['barangay_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </form>
            </div>

            <!-- Patient Results Table -->
            <div class="patient-table">
                <h3><i class="fas fa-users"></i> Patient Search Results</h3>
                <?php if ($show_search_results && empty($search_results)): ?>
                    <div class="empty-search">
                        <i class="fas fa-user-times fa-2x"></i>
                        <p>No patients found with unpaid invoices matching your search criteria.</p>
                        <p>Try adjusting your search terms or check the spelling.</p>
                    </div>
                <?php elseif (!empty($search_results)): ?>
                    <p>Found <?= count($search_results) ?> patient(s) with unpaid invoices. Select one to process payment:</p>

                    <!-- Desktop Table View -->
                    <div class="patient-table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Select</th>
                                    <th>Invoice #</th>
                                    <th>Patient Info</th>
                                    <th>Invoice Date</th>
                                    <th>Amount Due</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($search_results as $result): ?>
                                    <tr onclick="selectPatientRow(this, <?= $result['billing_id'] ?>)" data-billing-id="<?= $result['billing_id'] ?>">
                                        <td>
                                            <input type="radio" name="selected_patient" value="<?= $result['billing_id'] ?>"
                                                class="patient-checkbox" data-patient-name="<?= htmlspecialchars($result['first_name'] . ' ' . $result['last_name']) ?>">
                                        </td>
                                        <td><?= htmlspecialchars($result['billing_id']) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($result['first_name'] . ' ' . $result['last_name']) ?></strong><br>
                                            <small>ID: <?= htmlspecialchars($result['username'] ?? 'N/A') ?></small>
                                        </td>
                                        <td><?= date('M d, Y', strtotime($result['billing_date'])) ?></td>
                                        <td>
                                            <strong>₱<?= number_format($result['net_amount'] - $result['paid_amount'], 2) ?></strong><br>
                                            <small>Total: ₱<?= number_format($result['net_amount'], 2) ?></small>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= $result['payment_status'] ?>">
                                                <?= ucfirst($result['payment_status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Card View -->
                    <?php foreach ($search_results as $result): ?>
                        <div class="patient-card" data-billing-id="<?= $result['billing_id'] ?>" onclick="selectPatientCard(this)">
                            <input type="radio" name="selected_patient_mobile" value="<?= $result['billing_id'] ?>"
                                class="patient-card-checkbox patient-checkbox" data-patient-name="<?= htmlspecialchars($result['first_name'] . ' ' . $result['last_name']) ?>">

                            <div class="patient-card-header">
                                <div class="patient-card-name">
                                    <?= htmlspecialchars($result['first_name'] . ' ' . $result['last_name']) ?>
                                </div>
                                <div class="patient-card-id">Invoice #<?= $result['billing_id'] ?></div>
                            </div>

                            <div class="patient-card-details">
                                <div class="patient-card-detail">
                                    <div class="patient-card-label">Patient ID</div>
                                    <div class="patient-card-value"><?= htmlspecialchars($result['username'] ?? 'N/A') ?></div>
                                </div>
                                <div class="patient-card-detail">
                                    <div class="patient-card-label">Invoice Date</div>
                                    <div class="patient-card-value"><?= date('M d, Y', strtotime($result['billing_date'])) ?></div>
                                </div>
                                <div class="patient-card-detail">
                                    <div class="patient-card-label">Amount Due</div>
                                    <div class="patient-card-value">₱<?= number_format($result['net_amount'] - $result['paid_amount'], 2) ?></div>
                                </div>
                                <div class="patient-card-detail">
                                    <div class="patient-card-label">Barangay</div>
                                    <div class="patient-card-value"><?= htmlspecialchars($result['barangay_name'] ?? 'N/A') ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-search">
                        <i class="fas fa-search fa-2x"></i>
                        <p>Use the search form above to find patients with unpaid invoices.</p>
                        <p>Search results will appear here (maximum 20 results).</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Payment Processing Form Section (Hidden until patient selected) -->
            <?php if ($billing_id): ?>
            <div class="invoice-form-container">
                <div class="invoice-form enabled" id="payment-form-section">
                    <?php if (!$invoice_data): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i> Invoice not found or already fully paid.
                    </div>
                <?php else: ?>

                    <!-- Invoice Details Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-file-invoice-dollar"></i> Invoice Summary & Details</h3>
                        
                        <!-- Selected Patient Banner -->
                        <div class="patient-banner">
                            <div class="patient-banner-content">
                                <div class="patient-banner-icon">
                                    <i class="fas fa-user-check"></i>
                                </div>
                                <div class="patient-banner-info">
                                    <div class="patient-banner-title">Selected Patient</div>
                                    <div class="patient-banner-name"><?= htmlspecialchars($invoice_data['first_name'] . ' ' . $invoice_data['last_name']) ?></div>
                                    <div class="patient-banner-id">ID: <?= htmlspecialchars($invoice_data['patient_username'] ?? 'N/A') ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Invoice Information Cards -->
                        <div class="invoice-cards-grid">
                            <div class="invoice-card invoice-primary">
                                <div class="invoice-card-header">
                                    <i class="fas fa-receipt"></i>
                                    <span>Invoice Information</span>
                                </div>
                                <div class="invoice-card-content">
                                    <div class="info-item">
                                        <span class="info-label">Invoice ID</span>
                                        <span class="info-value invoice-number">#<?= $billing_id ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Date Issued</span>
                                        <span class="info-value"><?= date('M d, Y', strtotime($invoice_data['billing_date'])) ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Created By</span>
                                        <span class="info-value"><?= htmlspecialchars($invoice_data['created_by_first_name'] . ' ' . $invoice_data['created_by_last_name']) ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Payment Status</span>
                                        <span class="info-value">
                                            <span class="status-badge-enhanced status-<?= $invoice_data['payment_status'] ?>">
                                                <i class="fas fa-circle"></i>
                                                <?= ucfirst($invoice_data['payment_status']) ?>
                                            </span>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="invoice-card patient-card-details">
                                <div class="invoice-card-header">
                                    <i class="fas fa-user"></i>
                                    <span>Patient Details</span>
                                </div>
                                <div class="invoice-card-content">
                                    <div class="info-item">
                                        <span class="info-label">Patient ID</span>
                                        <span class="info-value"><?= htmlspecialchars($invoice_data['patient_username'] ?? 'N/A') ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Full Name</span>
                                        <span class="info-value"><?= htmlspecialchars($invoice_data['first_name'] . ' ' . $invoice_data['last_name']) ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Visit Date</span>
                                        <span class="info-value"><?= $invoice_data['visit_date'] ? date('M d, Y', strtotime($invoice_data['visit_date'])) : 'N/A' ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Services Breakdown -->
                        <div class="services-section">
                            <div class="services-header">
                                <i class="fas fa-stethoscope"></i>
                                <span>Services & Items Breakdown</span>
                            </div>
                            <div class="services-table-wrapper">
                                <table class="services-table">
                                    <thead>
                                        <tr>
                                            <th><i class="fas fa-medical-kit"></i> Service/Item</th>
                                            <th class="text-center"><i class="fas fa-tag"></i> Unit Price</th>
                                            <th class="text-center"><i class="fas fa-sort-numeric-up"></i> Qty</th>
                                            <th class="text-center"><i class="fas fa-calculator"></i> Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($invoice_items as $item): ?>
                                            <tr>
                                                <td class="service-name">
                                                    <div class="service-item">
                                                        <i class="fas fa-dot-circle"></i>
                                                        <?= htmlspecialchars($item['item_name']) ?>
                                                    </div>
                                                </td>
                                                <td class="text-center price-cell">₱<?= number_format($item['item_price'], 2) ?></td>
                                                <td class="text-center quantity-cell"><?= $item['quantity'] ?></td>
                                                <td class="text-center subtotal-cell">₱<?= number_format($item['subtotal'], 2) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Financial Summary -->
                        <div class="financial-summary">
                            <div class="summary-header">
                                <i class="fas fa-calculator"></i>
                                <span>Financial Summary</span>
                            </div>
                            <div class="summary-content">
                                <div class="summary-item">
                                    <span class="summary-label">Total Amount</span>
                                    <span class="summary-value">₱<?= number_format($invoice_data['total_amount'], 2) ?></span>
                                </div>
                                <?php if ($invoice_data['discount_amount'] > 0): ?>
                                    <div class="summary-item discount-item">
                                        <span class="summary-label">
                                            <i class="fas fa-percent"></i>
                                            Discount (<?= ucfirst($invoice_data['discount_type']) ?>)
                                        </span>
                                        <span class="summary-value discount-value">-₱<?= number_format($invoice_data['discount_amount'], 2) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="summary-divider"></div>
                                <div class="summary-item total-item">
                                    <span class="summary-label">Net Amount</span>
                                    <span class="summary-value total-value">₱<?= number_format($invoice_data['net_amount'], 2) ?></span>
                                </div>
                                <div class="summary-item paid-item">
                                    <span class="summary-label">
                                        <i class="fas fa-check-circle"></i>
                                        Amount Paid
                                    </span>
                                    <span class="summary-value paid-value">₱<?= number_format($invoice_data['paid_amount'], 2) ?></span>
                                </div>
                                <div class="summary-item outstanding-item">
                                    <span class="summary-label">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        Outstanding Balance
                                    </span>
                                    <span class="summary-value outstanding-value">₱<?= number_format($invoice_data['net_amount'] - $invoice_data['paid_amount'], 2) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Payment Form Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-cash-register"></i> Cash Payment Processing</h3>
                        <div class="payment-info-card">
                            <div class="payment-highlight">
                                <i class="fas fa-money-bill-wave"></i>
                                <span>Outstanding Balance: <strong>₱<?= number_format($invoice_data['net_amount'] - $invoice_data['paid_amount'], 2) ?></strong></span>
                            </div>
                        </div>

                        <form method="POST" id="payment-form">
                            <input type="hidden" name="action" value="process_payment">
                            <input type="hidden" name="billing_id" value="<?= $billing_id ?>">
                            <input type="hidden" id="payment_method" name="payment_method" value="cash">

                            <div class="payment-form-grid">
                                <div class="amount-section">
                                    <label for="amount_paid" class="payment-label">
                                        <i class="fas fa-hand-holding-usd"></i> Cash Amount Received *
                                    </label>
                                    <div class="amount-input-wrapper">
                                        <span class="currency-symbol">₱</span>
                                        <input type="number"
                                            class="amount-input-enhanced"
                                            id="amount_paid"
                                            name="amount_paid"
                                            step="0.01"
                                            min="0"
                                            max="<?= $invoice_data['net_amount'] - $invoice_data['paid_amount'] + 10000 ?>"
                                            placeholder="0.00"
                                            required
                                            oninput="calculateChange()"
                                            autocomplete="off">
                                    </div>
                                    <div class="input-help">Enter the exact cash amount received from the patient</div>
                                </div>
                                </div>

                                <div class="change-section" id="change-display" style="display: none;">
                                    <div class="change-card">
                                        <div class="change-header">
                                            <i class="fas fa-exchange-alt"></i>
                                            <span>Change to Return</span>
                                        </div>
                                        <div class="change-amount" id="change-amount">₱0.00</div>
                                    </div>
                                </div>
                            </div>

                            <div class="notes-section">
                                <label for="notes" class="payment-label">
                                    <i class="fas fa-sticky-note"></i> Payment Notes (Optional)
                                </label>
                                <textarea id="notes" name="notes" rows="3" 
                                    class="notes-input" 
                                    placeholder="Add any additional notes about this cash payment..."></textarea>
                            </div>

                            <div class="payment-actions">
                                <button type="button" class="btn btn-success btn-large" id="confirm-payment-btn" onclick="confirmPayment()" disabled>
                                    <i class="fas fa-check-circle"></i>
                                    <span>Process Cash Payment</span>
                                </button>
                                <button type="submit" class="btn btn-warning btn-large" onclick="return confirm('Process payment directly without confirmation?')">
                                    <i class="fas fa-bolt"></i>
                                    <span>Direct Submit (Debug)</span>
                                </button>
                                <button type="button" class="btn btn-secondary btn-large" onclick="window.location.href='<?= htmlspecialchars($back_url) ?>';">
                                    <i class="fas fa-arrow-left"></i>
                                    <span>Cancel & Go Back</span>
                                </button>
                            </div>
                        </form>
                    </div>

                <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </section>

    <!-- Payment Confirmation Modal -->
    <div id="payment-confirmation-modal" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-question-circle"></i> Confirm Payment</h3>
            <p>Please confirm the payment details:</p>
            <div id="payment-confirmation-details"></div>
            <div class="modal-buttons">
                <button type="button" class="btn btn-secondary" onclick="closePaymentConfirmation()">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitPayment()">
                    <i class="fas fa-check"></i> Process Payment
                </button>
            </div>
        </div>
    </div>

    <!-- Loading Modal -->
    <div id="loading-modal" class="modal">
        <div class="modal-content">
            <i class="fas fa-spinner fa-spin fa-3x"></i>
            <p>Processing payment, please wait...</p>
        </div>
    </div>

    <!-- Receipt Modal -->
    <?php if ($payment_success_data): ?>
        <div id="receipt-modal" class="modal" style="display: block;">
            <div class="modal-content">
                <h3><i class="fas fa-receipt"></i> Payment Receipt</h3>

                <div class="receipt-content">
                    <div class="receipt-header">
                        <h3>CHO KORONADAL</h3>
                        <p>Official Receipt</p>
                        <p>Receipt #: <?= $payment_success_data['receipt_number'] ?></p>
                        <p>Date: <?= date('M d, Y g:i A') ?></p>
                    </div>

                    <div class="receipt-row">
                        <span>Patient:</span>
                        <span><?= htmlspecialchars($payment_success_data['patient_name'] ?? '') ?></span>
                    </div>
                    <div class="receipt-row">
                        <span>Patient ID:</span>
                        <span><?= htmlspecialchars($payment_success_data['patient_id'] ?? 'N/A') ?></span>
                    </div>
                    <div class="receipt-row">
                        <span>Invoice ID:</span>
                        <span><?= $payment_success_data['billing_id'] ?></span>
                    </div>

                    <br>

                    <div class="receipt-row">
                        <span>Net Amount:</span>
                        <span>₱<?= number_format($payment_success_data['net_amount'], 2) ?></span>
                    </div>
                    <div class="receipt-row">
                        <span>Amount Paid:</span>
                        <span>₱<?= number_format($payment_success_data['amount_paid'], 2) ?></span>
                    </div>
                    <?php if ($payment_success_data['change_amount'] > 0): ?>
                        <div class="receipt-row">
                            <span>Change:</span>
                            <span>₱<?= number_format($payment_success_data['change_amount'], 2) ?></span>
                        </div>
                    <?php endif; ?>

                    <br>

                    <div class="receipt-row receipt-total">
                        <span>Payment Status:</span>
                        <span><?= strtoupper($payment_success_data['payment_status']) ?></span>
                    </div>

                    <div style="text-align: center; margin-top: 1rem; font-size: 0.9em;">
                        <p>Thank you for your payment!</p>
                    </div>
                </div>

                <div class="modal-buttons">
                    <button type="button" class="btn btn-success" onclick="processNewPayment()">
                        <i class="fas fa-plus"></i> Process New Payment
                    </button>
                    <button type="button" class="btn btn-primary" onclick="printReceipt()">
                        <i class="fas fa-print"></i> Print Receipt
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="goToDashboard()">
                        <i class="fas fa-tachometer-alt"></i> Back to Dashboard
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script>
        const outstandingBalance = <?= $invoice_data ? ($invoice_data['net_amount'] - $invoice_data['paid_amount']) : 0 ?>;
        const currentBillingId = <?= $billing_id ? $billing_id : 'null' ?>;

        // On page load, mark the selected patient row as selected
        document.addEventListener('DOMContentLoaded', function() {
            if (currentBillingId) {
                // Find and highlight the row with matching billing_id
                const selectedRow = document.querySelector(`tr[data-billing-id="${currentBillingId}"]`);
                if (selectedRow) {
                    selectedRow.classList.add('selected-patient');
                    const checkbox = selectedRow.querySelector('.patient-checkbox');
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                }

                // Find and highlight mobile card with matching billing_id
                const selectedCard = document.querySelector(`.patient-card[data-billing-id="${currentBillingId}"]`);
                if (selectedCard) {
                    selectedCard.classList.add('selected');
                    const checkbox = selectedCard.querySelector('.patient-checkbox');
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                }
            }
        });

        // Helper function to preserve search parameters
        function buildUrlWithSearchParams(billingId) {
            const urlParams = new URLSearchParams(window.location.search);
            const newParams = new URLSearchParams();
            
            // Preserve search parameters
            if (urlParams.get('search')) newParams.set('search', urlParams.get('search'));
            if (urlParams.get('first_name')) newParams.set('first_name', urlParams.get('first_name'));
            if (urlParams.get('last_name')) newParams.set('last_name', urlParams.get('last_name'));
            if (urlParams.get('barangay')) newParams.set('barangay', urlParams.get('barangay'));
            
            // Add billing_id
            newParams.set('billing_id', billingId);
            
            return 'process_payment.php?' + newParams.toString();
        }

        // Select patient invoice for processing
        function selectPatientInvoice(billingId) {
            window.location.href = 'process_payment.php?billing_id=' + billingId;
        }

        // Select patient row (for desktop table)
        function selectPatientRow(row, billingId) {
            // Remove previous selection
            document.querySelectorAll('tr').forEach(tr => tr.classList.remove('selected-patient'));
            document.querySelectorAll('.patient-checkbox').forEach(cb => cb.checked = false);

            // Select current row
            row.classList.add('selected-patient');
            row.querySelector('.patient-checkbox').checked = true;

            // Enable form and redirect with preserved search parameters
            setTimeout(() => {
                window.location.href = buildUrlWithSearchParams(billingId);
            }, 300);
        }

        // Select patient card (for mobile)
        function selectPatientCard(card) {
            const billingId = card.getAttribute('data-billing-id');

            // Remove previous selection
            document.querySelectorAll('.patient-card').forEach(c => c.classList.remove('selected'));
            document.querySelectorAll('.patient-checkbox').forEach(cb => cb.checked = false);

            // Select current card
            card.classList.add('selected');
            card.querySelector('.patient-checkbox').checked = true;

            // Enable form and redirect with preserved search parameters
            setTimeout(() => {
                window.location.href = buildUrlWithSearchParams(billingId);
            }, 300);
        }

        // Calculate change amount
        function calculateChange() {
            const amountPaidElement = document.getElementById('amount_paid');
            const confirmBtn = document.getElementById('confirm-payment-btn');
            const changeDisplay = document.getElementById('change-display');
            const changeAmount = document.getElementById('change-amount');

            // Check if elements exist (form might be hidden)
            if (!amountPaidElement || !confirmBtn || !changeDisplay || !changeAmount) {
                return;
            }

            const amountPaid = parseFloat(amountPaidElement.value) || 0;
            const change = amountPaid - outstandingBalance;

            // Enable button for any positive payment amount
            if (amountPaid > 0) {
                confirmBtn.disabled = false;
                
                if (amountPaid >= outstandingBalance) {
                    // Full payment or overpayment - show change
                    changeAmount.textContent = '₱' + Math.max(0, change).toFixed(2);
                    changeDisplay.style.display = 'block';
                } else {
                    // Partial payment - hide change display
                    changeDisplay.style.display = 'none';
                }
            } else {
                // No payment or negative amount
                changeDisplay.style.display = 'none';
                confirmBtn.disabled = true;
            }
        }

        // Confirm payment details
        function confirmPayment() {
            console.log('confirmPayment called');
            const amountPaidElement = document.getElementById('amount_paid');
            const paymentMethodElement = document.getElementById('payment_method');

            console.log('amountPaidElement:', amountPaidElement);
            console.log('paymentMethodElement:', paymentMethodElement);

            // Check if elements exist (form might be hidden)
            if (!amountPaidElement || !paymentMethodElement) {
                console.error('Form elements not found');
                alert('Payment form is not available. Please select a patient first.');
                return;
            }

            const amountPaid = parseFloat(amountPaidElement.value) || 0;
            const paymentMethod = paymentMethodElement.value;
            const change = Math.max(0, amountPaid - outstandingBalance);
            const remainingBalance = Math.max(0, outstandingBalance - amountPaid);

            console.log('Payment values:');
            console.log('- Amount Paid:', amountPaid);
            console.log('- Payment Method:', paymentMethod);
            console.log('- Outstanding Balance:', outstandingBalance);
            console.log('- Change:', change);
            console.log('- Remaining Balance:', remainingBalance);

            // Validate minimum payment amount
            if (amountPaid <= 0) {
                console.error('Invalid payment amount:', amountPaid);
                alert('Payment amount must be greater than zero.');
                return;
            }

            // Determine payment type and create appropriate confirmation message
            let paymentTypeMessage = '';
            let paymentStatusClass = '';
            
            if (amountPaid >= outstandingBalance) {
                paymentTypeMessage = '<strong style="color: #28a745;">✓ FULL PAYMENT</strong>';
                paymentStatusClass = 'status-paid';
            } else {
                paymentTypeMessage = '<strong style="color: #ffc107;">⚠ PARTIAL PAYMENT</strong>';
                paymentStatusClass = 'status-partial';
            }

            let detailsHTML = `
                <div class="payment-summary">
                    <div class="summary-row" style="background: #f8f9fa; padding: 0.75rem; border-radius: 4px; margin-bottom: 1rem;">
                        <span>Payment Type:</span>
                        <span>${paymentTypeMessage}</span>
                    </div>
                    <div class="summary-row">
                        <span>Outstanding Balance:</span>
                        <span>₱${outstandingBalance.toFixed(2)}</span>
                    </div>
                    <div class="summary-row">
                        <span>Amount Given:</span>
                        <span style="font-weight: bold; color: #0077b6;">₱${amountPaid.toFixed(2)}</span>
                    </div>
                    <div class="summary-row">
                        <span>Payment Method:</span>
                        <span>${paymentMethod.toUpperCase()}</span>
                    </div>
                    ${change > 0 ? `<div class="summary-row" style="background: #d1e7dd; padding: 0.5rem; border-radius: 4px;">
                        <span><strong>Change:</strong></span>
                        <span style="font-weight: bold; color: #28a745;">₱${change.toFixed(2)}</span>
                    </div>` : ''}
                    ${remainingBalance > 0 ? `<div class="summary-row" style="background: #fff3cd; padding: 0.5rem; border-radius: 4px;">
                        <span><strong>Remaining Balance:</strong></span>
                        <span style="font-weight: bold; color: #664d03;">₱${remainingBalance.toFixed(2)}</span>
                    </div>` : ''}
                </div>
            `;

            document.getElementById('payment-confirmation-details').innerHTML = detailsHTML;
            console.log('Showing payment confirmation modal');
            document.getElementById('payment-confirmation-modal').style.display = 'block';
        }

        // Close payment confirmation modal
        function closePaymentConfirmation() {
            document.getElementById('payment-confirmation-modal').style.display = 'none';
        }

        // Submit payment form
        function submitPayment() {
            console.log('submitPayment called');
            const form = document.getElementById('payment-form');
            if (form) {
                console.log('Form found, submitting...');
                console.log('Form data:', new FormData(form));
                document.getElementById('payment-confirmation-modal').style.display = 'none';
                document.getElementById('loading-modal').style.display = 'block';
                form.submit();
            } else {
                console.error('Payment form not found!');
                alert('Payment form not found. Please refresh and try again.');
            }
        }

        // Go to dashboard
        function goToDashboard() {
            window.location.href = '<?= htmlspecialchars($back_url) ?>';
        }

        // Process new payment
        function processNewPayment() {
            window.location.href = 'process_payment.php';
        }

        // Print receipt
        function printReceipt() {
            const receiptContent = document.querySelector('.receipt-content').innerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Receipt - ${<?= json_encode($payment_success_data['receipt_number'] ?? '') ?>}</title>
                    <style>
                        body { font-family: 'Courier New', monospace; padding: 20px; }
                        .receipt-header { text-align: center; border-bottom: 2px solid #000; padding-bottom: 1rem; margin-bottom: 1rem; }
                        .receipt-row { display: flex; justify-content: space-between; margin: 0.25rem 0; }
                        .receipt-total { border-top: 1px solid #000; padding-top: 0.5rem; font-weight: bold; }
                    </style>
                </head>
                <body>${receiptContent}</body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        // Auto-dismiss alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    if (alert.parentElement) {
                        alert.style.opacity = '0';
                        alert.style.transform = 'translateY(-10px)';
                        setTimeout(function() {
                            if (alert.parentElement) {
                                alert.remove();
                            }
                        }, 300);
                    }
                }, 5000);
            });
        });

        // Auto-focus on amount input
        <?php if ($invoice_data): ?>
            document.getElementById('amount_paid').focus();
        <?php endif; ?>
    </script>
</body>

</html>