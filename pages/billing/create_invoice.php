<?php

/**
 * Create Invoice Page
 * Purpose: Cashier interface for creating new invoices
 * UI Pattern: Topbar only (form page), follows create_referrals.php structure
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
$vendor_path = $protocol . '://' . $host . $base_path . '/vendor';
$api_path = $protocol . '://' . $host . $base_path . '/api';

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

// Include reusable topbar component
require_once $root_path . '/includes/topbar.php';

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_invoice') {
        try {
            $pdo->beginTransaction();

            // Get form data
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $visit_id = (int)($_POST['visit_id'] ?? 0);
            $service_items = json_decode($_POST['service_items'] ?? '[]', true);
            $discount_type = $_POST['discount_type'] ?? 'none';
            $notes = trim($_POST['notes'] ?? '');

            // Validation
            if (!$patient_id) {
                throw new Exception('Please select a patient from the search results.');
            }
            if (!$visit_id) {
                throw new Exception('Patient visit information is required.');
            }
            if (empty($service_items)) {
                throw new Exception('Please select at least one service item.');
            }

            // Calculate totals
            $total_amount = 0;
            $valid_items = [];

            // Validate service items and calculate totals
            foreach ($service_items as $item) {
                $service_item_id = intval($item['service_item_id'] ?? 0);
                $quantity = intval($item['quantity'] ?? 1);

                if (!$service_item_id || $quantity < 1) continue;

                // Get service item details
                $stmt = $pdo->prepare("SELECT item_id, item_name, price_php FROM service_items WHERE item_id = ? AND is_active = 1");
                $stmt->execute([$service_item_id]);
                $service_item = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$service_item) continue;

                $subtotal = $service_item['price_php'] * $quantity;
                $total_amount += $subtotal;

                $valid_items[] = [
                    'service_item_id' => $service_item_id,
                    'item_name' => $service_item['item_name'],
                    'item_price' => $service_item['price_php'],
                    'quantity' => $quantity,
                    'subtotal' => $subtotal
                ];
            }

            if (empty($valid_items)) {
                throw new Exception('No valid service items provided');
            }

            // Calculate discount
            $discount_amount = 0;
            if (in_array($discount_type, ['senior', 'pwd'])) {
                $discount_amount = $total_amount * 0.20; // 20% discount
            }

            $net_amount = $total_amount - $discount_amount;

            // Insert billing record
            $stmt = $pdo->prepare("INSERT INTO billing (visit_id, patient_id, total_amount, discount_amount, discount_type, net_amount, payment_status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, 'unpaid', ?, ?)");
            $stmt->execute([$visit_id, $patient_id, $total_amount, $discount_amount, $discount_type, $net_amount, $notes, $employee_id]);

            $billing_id = $pdo->lastInsertId();

            // Insert billing items
            $stmt = $pdo->prepare("INSERT INTO billing_items (billing_id, service_item_id, item_price, quantity, subtotal) VALUES (?, ?, ?, ?, ?)");

            foreach ($valid_items as $item) {
                $stmt->execute([
                    $billing_id,
                    $item['service_item_id'],
                    $item['item_price'],
                    $item['quantity'],
                    $item['subtotal']
                ]);
            }

            $pdo->commit();

            $success_message = 'Invoice created successfully! Invoice ID: ' . $billing_id;

            // Get patient name for success modal
            $stmt = $pdo->prepare("SELECT first_name, last_name FROM patients WHERE patient_id = ?");
            $stmt->execute([$patient_id]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            $patient_name = ($patient) ? $patient['first_name'] . ' ' . $patient['last_name'] : 'Unknown';

            // Clear form data after successful creation
            $_POST = [];
            
            // Trigger success modal
            $show_success_modal = true;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollback();
            }
            $error_message = 'Error creating invoice: ' . $e->getMessage();
        }
    }
}

// Patient search functionality
$search_query = $_GET['search'] ?? '';
$first_name = $_GET['first_name'] ?? '';
$last_name = $_GET['last_name'] ?? '';
$barangay_filter = $_GET['barangay'] ?? '';

$patients = [];
if ($search_query || $first_name || $last_name || $barangay_filter) {
    $where_conditions = [];
    $params = [];
    $param_types = '';

    if (!empty($search_query)) {
        $where_conditions[] = "(p.username LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)";
        $search_term = "%$search_query%";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
        $param_types .= 'ssss';
    }

    if (!empty($first_name)) {
        $where_conditions[] = "p.first_name LIKE ?";
        $params[] = "%$first_name%";
        $param_types .= 's';
    }

    if (!empty($last_name)) {
        $where_conditions[] = "p.last_name LIKE ?";
        $params[] = "%$last_name%";
        $param_types .= 's';
    }

    if (!empty($barangay_filter)) {
        $where_conditions[] = "b.barangay_name LIKE ?";
        $params[] = "%$barangay_filter%";
        $param_types .= 's';
    }

    if (!empty($where_conditions)) {
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions) . ' AND p.status = \'active\'';
    } else {
        $where_clause = 'WHERE p.status = \'active\'';
    }

    $sql = "
        SELECT p.patient_id, p.username, p.first_name, p.middle_name, p.last_name, 
               p.date_of_birth, p.sex, p.contact_number, b.barangay_name,
               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
        FROM patients p 
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id 
        $where_clause
        ORDER BY p.last_name, p.first_name
        LIMIT 5
    ";

    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $patients = $result->fetch_all(MYSQLI_ASSOC);
}

// Get barangays for filter dropdown
$barangays = [];
try {
    $stmt = $conn->prepare("SELECT barangay_name FROM barangay WHERE status = 'active' ORDER BY barangay_name ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    $barangays = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    // Ignore errors for barangays
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Create Invoice | CHO Koronadal</title>
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

        .invoice-form {
            opacity: 0.5;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .invoice-form.enabled {
            opacity: 1;
            pointer-events: auto;
        }

        .service-table-container {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
            overflow-x: auto;
        }

        .service-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            min-width: 700px;
        }

        .service-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #0077b6;
            padding: 1rem 0.75rem;
            text-align: left;
            border-bottom: 2px solid #e9ecef;
        }

        .service-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }

        .service-table tbody tr {
            transition: all 0.3s ease;
        }

        .service-table tbody tr:hover {
            background: #f8f9fa;
        }

        .service-table tbody tr.selected {
            background: #e3f2fd;
            border-left: 4px solid #0077b6;
        }

        .service-table .service-name {
            font-weight: 600;
            color: #333;
        }

        .service-table .service-price {
            font-weight: 600;
            color: #28a745;
        }

        .service-table .quantity-input {
            width: 80px;
            padding: 0.5rem;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            text-align: center;
            font-weight: 600;
        }

        .service-table .quantity-input:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 2px rgba(0, 119, 182, 0.1);
        }

        .service-table .quantity-input:disabled {
            background: #f8f9fa;
            color: #6c757d;
            cursor: not-allowed;
        }

        .service-table .subtotal {
            font-weight: 700;
            color: #0077b6;
            font-size: 1.1em;
        }

        .service-table .service-unit {
            color: #6c757d;
            font-style: italic;
        }

        .service-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
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

        .totals-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            border-left: 4px solid #28a745;
        }

        .totals-row {
            display: flex;
            justify-content: space-between;
            margin: 0.5rem 0;
            font-size: 1rem;
        }

        .totals-row.total {
            font-size: 1.3rem;
            font-weight: 700;
            border-top: 2px solid #28a745;
            padding-top: 0.75rem;
            margin-top: 1rem;
            color: #28a745;
        }

        .empty-search {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            border-left: 4px solid;
        }

        .alert-success {
            background-color: #d4edda;
            border-color: #28a745;
            color: #155724;
        }

        .alert-error {
            background-color: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
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
    <?php
    // Render topbar
    renderTopbar([
        'title' => 'Create New Invoice',
        'back_url' => 'billing_management.php',
        'user_type' => 'employee',
        'vendor_path' => $vendor_path . '/'
    ]);
    ?>

    <section class="homepage">
        <?php
        // Render back button with modal
        renderBackButton([
            'back_url' => 'billing_management.php',
            'button_text' => '← Back / Cancel',
            'modal_title' => 'Cancel Creating Invoice?',
            'modal_message' => 'Are you sure you want to go back/cancel? Unsaved changes will be lost.',
            'confirm_text' => 'Yes, Cancel',
            'stay_text' => 'Stay'
        ]);
        ?>

        <div class="profile-wrapper" style="padding: 1.5rem;">
            <!-- Reminders Box -->
            <div class="reminders-box">
                <strong>Reminders:</strong>
                <ul>
                    <li>Search and select a patient from the list below before creating an invoice.</li>
                    <li>You can search by patient ID, name, or barangay.</li>
                    <li>Select the services provided to the patient during their visit.</li>
                    <li>Apply discounts for Senior Citizens or PWD if applicable (20% discount).</li>
                    <li>All invoice information should be accurate and complete.</li>
                    <li>Fields marked with * are required.</li>
                </ul>
            </div>
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <!-- Patient Search Section -->
            <div class="form-section">
                <div class="search-container">
                    <h3><i class="fas fa-search"></i> Search Patient</h3>
                    <form method="GET" class="search-grid">
                        <div class="form-group">
                            <label for="search">General Search</label>
                            <input type="text" id="search" name="search" value="<?= htmlspecialchars($search_query) ?>"
                                placeholder="Patient ID, Name...">
                        </div>
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($first_name) ?>"
                                placeholder="Enter first name...">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($last_name) ?>"
                                placeholder="Enter last name...">
                        </div>
                        <div class="form-group">
                            <label for="barangay">Barangay</label>
                            <select id="barangay" name="barangay">
                                <option value="">All Barangays</option>
                                <?php foreach ($barangays as $brgy): ?>
                                    <option value="<?= htmlspecialchars($brgy['barangay_name']) ?>"
                                        <?= $barangay_filter === $brgy['barangay_name'] ? 'selected' : '' ?>>
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
            </div>

            <!-- Patient Results Table -->
            <div class="form-section">
                <div class="patient-table">
                    <h3><i class="fas fa-users"></i> Patient Search Results</h3>
                    <?php if (empty($patients) && ($search_query || $first_name || $last_name || $barangay_filter)): ?>
                        <div class="empty-search">
                            <i class="fas fa-user-times fa-2x"></i>
                            <p>No patients found matching your search criteria.</p>
                            <p>Try adjusting your search terms or check the spelling.</p>
                        </div>
                    <?php elseif (!empty($patients)): ?>
                        <p>Found <?= count($patients) ?> patient(s). Select one to create an invoice:</p>

                        <!-- Desktop Table View -->
                        <div class="patient-table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Select</th>
                                        <th>Patient ID</th>
                                        <th>Name</th>
                                        <th>Age/Sex</th>
                                        <th>Contact</th>
                                        <th>Barangay</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($patients as $patient): ?>
                                        <tr class="patient-row" data-patient-id="<?= $patient['patient_id'] ?>">
                                            <td>
                                                <input type="radio" name="selected_patient" value="<?= $patient['patient_id'] ?>"
                                                    class="patient-checkbox" data-patient-name="<?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?>">
                                            </td>
                                            <td><?= htmlspecialchars($patient['username']) ?></td>
                                            <td>
                                                <?= htmlspecialchars($patient['first_name'] . ' ' .
                                                    ($patient['middle_name'] ? $patient['middle_name'] . ' ' : '') .
                                                    $patient['last_name']) ?>
                                            </td>
                                            <td><?= htmlspecialchars($patient['age']) ?> / <?= htmlspecialchars($patient['sex']) ?></td>
                                            <td><?= htmlspecialchars($patient['contact_number'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($patient['barangay_name'] ?? 'N/A') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile Card View -->
                        <?php foreach ($patients as $patient): ?>
                            <div class="patient-card" data-patient-id="<?= $patient['patient_id'] ?>" onclick="selectPatientCard(this)">
                                <input type="radio" name="selected_patient_mobile" value="<?= $patient['patient_id'] ?>"
                                    class="patient-card-checkbox patient-checkbox" data-patient-name="<?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?>">

                                <div class="patient-card-header">
                                    <div class="patient-card-name">
                                        <?= htmlspecialchars($patient['first_name'] . ' ' .
                                            ($patient['middle_name'] ? $patient['middle_name'] . ' ' : '') .
                                            $patient['last_name']) ?>
                                    </div>
                                    <div class="patient-card-id"><?= htmlspecialchars($patient['username']) ?></div>
                                </div>

                                <div class="patient-card-details">
                                    <div class="patient-card-detail">
                                        <div class="patient-card-label">Age / Sex</div>
                                        <div class="patient-card-value"><?= htmlspecialchars($patient['age']) ?> / <?= htmlspecialchars($patient['sex']) ?></div>
                                    </div>
                                    <div class="patient-card-detail">
                                        <div class="patient-card-label">Contact</div>
                                        <div class="patient-card-value"><?= htmlspecialchars($patient['contact_number'] ?? 'N/A') ?></div>
                                    </div>
                                    <div class="patient-card-detail">
                                        <div class="patient-card-label">Barangay</div>
                                        <div class="patient-card-value"><?= htmlspecialchars($patient['barangay_name'] ?? 'N/A') ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-search">
                            <i class="fas fa-search fa-2x"></i>
                            <p>Use the search form above to find patients.</p>
                            <p>Search results will appear here (maximum 5 results).</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Invoice Form Section -->
            <div class="form-section">
                <form class="profile-card invoice-form" id="invoice-form" method="post">
                    <input type="hidden" name="action" value="create_invoice">
                    <input type="hidden" name="patient_id" id="selectedPatientId">
                    <input type="hidden" name="visit_id" id="selectedVisitId">

                    <h3><i class="fas fa-file-invoice"></i> Create Invoice</h3>

                    <div id="selectedPatientInfo" class="selected-patient-info" style="display:none; background: #d4edda; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid #28a745;">
                        <p style="margin: 0; color: #155724;"><strong>Selected Patient:</strong> <span id="selectedPatientName"></span></p>
                    </div>

                    <div class="form-section" style="margin: 0rem;">
                        <div id="service-loading" style="text-align: center; padding: 2rem; display: block;">
                            <i class="fas fa-spinner fa-spin"></i> Loading services...
                        </div>
                        <div class="service-table-container" id="service-items-container" style="display: none;">
                            <h4><i class="fas fa-medical"></i> Service Selection</h4>
                            <table class="service-table">
                                <thead>
                                    <tr>
                                        <th style="width: 60px;">Select</th>
                                        <th>Service Name</th>
                                        <th style="width: 120px;">Price</th>
                                        <th style="width: 100px;">Unit</th>
                                        <th style="width: 100px;">Quantity</th>
                                        <th style="width: 120px;">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody id="service-table-body">
                                    <!-- Service items will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                        <div id="service-error" style="display: none; text-align: center; padding: 2rem; color: #dc3545;">
                            <i class="fas fa-exclamation-circle"></i> Error loading services. Please try again.
                        </div>
                    </div>

                    <div class="form-section" style="margin: 0rem;">
                        <h4><i class="fas fa-percentage"></i> Discount Options</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Discount Type *</label>
                                <select name="discount_type" onchange="updateTotals()">
                                    <option value="none">No Discount</option>
                                    <option value="senior">Senior Citizen (20%)</option>
                                    <option value="pwd">PWD (20%)</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="totals-section">
                        <h4><i class="fas fa-calculator"></i> Invoice Summary</h4>
                        <div class="totals-row">
                            <span>Subtotal:</span>
                            <span id="subtotal-amount">₱0.00</span>
                        </div>
                        <div class="totals-row" id="discount-row" style="display: none;">
                            <span>Discount:</span>
                            <span id="discount-amount">-₱0.00</span>
                        </div>
                        <div class="totals-row total">
                            <span>Total Amount:</span>
                            <span id="total-amount">₱0.00</span>
                        </div>
                    </div>

                    <div class="form-section" style="margin-top: 1.5rem;">
                        <h4><i class="fas fa-sticky-note"></i> Additional Information</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="notes">Notes (Optional)</label>
                                <textarea name="notes" id="notes" rows="4" placeholder="Additional notes for this invoice..."></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="button-group" style="margin-top: 2rem;">
                        <button type="submit" class="btn btn-success" id="create-invoice-btn" disabled>
                            <i class="fas fa-plus"></i> Create Invoice
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='billing_management.php'">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
            </div>

            <!-- Hidden field for service items -->
            <input type="hidden" name="service_items" id="service_items_field" value="">
            </form>
        </div>
        </div>
    </section>



    <script>
        // Global JavaScript path configuration for production compatibility
        <?php
        require_once $root_path . '/config/paths.php';
        $js_api_base = rtrim(parse_url(getBaseUrl(), PHP_URL_PATH), '/') . '/api';
        ?>
        window.apiBase = '<?= $js_api_base ?>';
        
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile patient card selection function
            window.selectPatientCard = function(cardElement) {
                const checkbox = cardElement.querySelector('.patient-card-checkbox');
                if (checkbox && !checkbox.checked) {
                    // Uncheck all other checkboxes (both mobile and desktop)
                    document.querySelectorAll('.patient-checkbox').forEach(cb => cb.checked = false);

                    // Remove previous selections from both cards and rows
                    document.querySelectorAll('.patient-card').forEach(card => card.classList.remove('selected'));
                    document.querySelectorAll('.patient-row').forEach(row => row.classList.remove('selected-patient'));

                    // Select current card
                    checkbox.checked = true;
                    cardElement.classList.add('selected');

                    // Trigger the change event to handle form enabling
                    const event = new Event('change', {
                        bubbles: true
                    });
                    checkbox.dispatchEvent(event);
                }
            };

            // Patient selection logic
            const patientCheckboxes = document.querySelectorAll('.patient-checkbox');
            const invoiceForm = document.getElementById('invoice-form');
            const submitBtn = invoiceForm.querySelector('button[type="submit"]');
            const selectedPatientId = document.getElementById('selectedPatientId');
            const selectedPatientInfo = document.getElementById('selectedPatientInfo');
            const selectedPatientName = document.getElementById('selectedPatientName');

            patientCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    if (this.checked) {
                        // Uncheck other checkboxes
                        patientCheckboxes.forEach(cb => {
                            if (cb !== this) cb.checked = false;
                        });

                        // Remove previous selections from both desktop and mobile
                        document.querySelectorAll('.patient-row').forEach(row => {
                            row.classList.remove('selected-patient');
                        });
                        document.querySelectorAll('.patient-card').forEach(card => {
                            card.classList.remove('selected');
                        });

                        // Highlight selected row or card
                        const parentRow = this.closest('.patient-row');
                        const parentCard = this.closest('.patient-card');

                        if (parentRow) {
                            parentRow.classList.add('selected-patient');
                        } else if (parentCard) {
                            parentCard.classList.add('selected');
                        }

                        // Enable form
                        invoiceForm.classList.add('enabled');
                        if (submitBtn) submitBtn.disabled = false;
                        selectedPatientId.value = this.value;
                        selectedPatientName.textContent = this.dataset.patientName;
                        selectedPatientInfo.style.display = 'block';

                        // For billing, we need a visit ID - create a placeholder or get from patient data
                        const selectedVisitId = document.getElementById('selectedVisitId');
                        if (selectedVisitId) {
                            selectedVisitId.value = '1'; // Placeholder - should be updated to get actual visit
                        }

                    } else {
                        // Disable form if no patient selected
                        invoiceForm.classList.remove('enabled');
                        if (submitBtn) submitBtn.disabled = true;
                        selectedPatientId.value = '';
                        selectedPatientInfo.style.display = 'none';

                        // Remove selection from both desktop and mobile
                        const parentRow = this.closest('.patient-row');
                        const parentCard = this.closest('.patient-card');

                        if (parentRow) {
                            parentRow.classList.remove('selected-patient');
                        } else if (parentCard) {
                            parentCard.classList.remove('selected');
                        }
                    }
                });
            });

            // Handle form submission with confirmation
            const invoiceFormElement = document.getElementById('invoice-form');
            if (invoiceFormElement) {
                invoiceFormElement.addEventListener('submit', function(e) {
                    e.preventDefault();
                    showConfirmationModal();
                });
            }

            // Load service items on page load
            loadServiceItems();
        });

        // Variables for service management
        let selectedServices = [];
        let serviceItems = [];

        // Load service items from API
        function loadServiceItems() {
            const loadingDiv = document.getElementById('service-loading');
            const containerDiv = document.getElementById('service-items-container');
            const errorDiv = document.getElementById('service-error');

            fetch('<?= $api_path ?>/get_service_catalog.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        serviceItems = data.services || [];
                        displayServiceItems();
                        loadingDiv.style.display = 'none';
                        containerDiv.style.display = 'block';
                        errorDiv.style.display = 'none';
                    } else {
                        throw new Error(data.message || 'Failed to load services');
                    }
                })
                .catch(error => {
                    console.error('Error loading services:', error);
                    loadingDiv.style.display = 'none';
                    containerDiv.style.display = 'none';
                    errorDiv.style.display = 'block';
                });
        }

        // Display service items in the table
        function displayServiceItems() {
            const tableBody = document.getElementById('service-table-body');
            tableBody.innerHTML = '';

            if (serviceItems.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #6c757d; padding: 2rem;">No services available</td></tr>';
                return;
            }

            serviceItems.forEach(item => {
                const row = document.createElement('tr');
                row.id = `service-row-${item.service_item_id}`;
                row.innerHTML = `
                    <td style="text-align: center;">
                        <input type="checkbox" class="service-checkbox" id="service-${item.service_item_id}" 
                               onchange="toggleService(${item.service_item_id})">
                    </td>
                    <td class="service-name">${item.service_name}</td>
                    <td class="service-price">₱${parseFloat(item.price).toFixed(2)}</td>
                    <td class="service-unit">${item.unit || '-'}</td>
                    <td style="text-align: center;">
                        <input type="number" class="quantity-input" id="qty-${item.service_item_id}" 
                               value="1" min="1" max="99" onchange="updateQuantity(${item.service_item_id})" disabled>
                    </td>
                    <td class="subtotal" id="subtotal-${item.service_item_id}">₱0.00</td>
                `;
                tableBody.appendChild(row);
            });
        }

        // Toggle service selection
        function toggleService(itemId) {
            const checkbox = document.getElementById(`service-${itemId}`);
            const qtyInput = document.getElementById(`qty-${itemId}`);
            const serviceRow = document.getElementById(`service-row-${itemId}`);

            if (checkbox.checked) {
                qtyInput.disabled = false;
                serviceRow.classList.add('selected');
                updateQuantity(itemId);
            } else {
                qtyInput.disabled = true;
                serviceRow.classList.remove('selected');
                selectedServices = selectedServices.filter(s => s.service_item_id !== itemId);
                document.getElementById(`subtotal-${itemId}`).textContent = '₱0.00';
                updateTotals();
            }
        }

        // Update quantity and subtotal
        function updateQuantity(itemId) {
            const quantity = parseInt(document.getElementById(`qty-${itemId}`).value) || 1;

            // Find the service item price
            let price = 0;
            const service = serviceItems.find(item => item.service_item_id === itemId);
            if (service) {
                price = parseFloat(service.price);
            }

            const subtotal = price * quantity;
            document.getElementById(`subtotal-${itemId}`).textContent = '₱' + subtotal.toFixed(2);

            // Update selected services array
            const existingIndex = selectedServices.findIndex(s => s.service_item_id === itemId);
            if (existingIndex >= 0) {
                selectedServices[existingIndex].quantity = quantity;
                selectedServices[existingIndex].subtotal = subtotal;
            } else {
                selectedServices.push({
                    service_item_id: itemId,
                    service_name: service ? service.service_name : 'Unknown Service',
                    quantity: quantity,
                    price: price,
                    subtotal: subtotal
                });
            }

            updateTotals();
            updateServiceItemsField();
        }

        // Update totals calculation
        function updateTotals() {
            const subtotalAmount = selectedServices.reduce((sum, service) => sum + service.subtotal, 0);

            // Get selected discount type
            const discountType = document.querySelector('select[name="discount_type"]').value;
            let discountAmount = 0;

            if (discountType === 'senior' || discountType === 'pwd') {
                discountAmount = subtotalAmount * 0.20;
            }

            const totalAmount = subtotalAmount - discountAmount;

            // Update display
            document.getElementById('subtotal-amount').textContent = '₱' + subtotalAmount.toFixed(2);
            document.getElementById('discount-amount').textContent = '-₱' + discountAmount.toFixed(2);
            document.getElementById('total-amount').textContent = '₱' + totalAmount.toFixed(2);

            // Show/hide discount row
            const discountRow = document.getElementById('discount-row');
            if (discountAmount > 0) {
                discountRow.style.display = 'flex';
            } else {
                discountRow.style.display = 'none';
            }

            // Enable/disable create invoice button
            const createBtn = document.getElementById('create-invoice-btn');
            const invoiceForm = document.getElementById('invoice-form');
            const hasPatient = document.getElementById('selectedPatientId').value;

            if (createBtn && hasPatient && selectedServices.length > 0) {
                createBtn.disabled = false;
            } else if (createBtn) {
                createBtn.disabled = true;
            }
        }

        // Update service items hidden field
        function updateServiceItemsField() {
            const field = document.getElementById('service_items_field');
            if (field) {
                field.value = JSON.stringify(selectedServices);
            }
        }

        // Modal Functions
        function showConfirmationModal() {
            // Get current form data
            const patientNameElement = document.getElementById('selectedPatientName');
            const totalAmountElement = document.getElementById('total-amount');
            
            const patientName = patientNameElement ? patientNameElement.textContent : 'No patient selected';
            const totalAmount = totalAmountElement ? totalAmountElement.textContent : '₱0.00';
            const serviceCount = selectedServices.length;

            // Update confirmation modal
            document.getElementById('confirm-patient-name').textContent = patientName || 'No patient selected';
            document.getElementById('confirm-service-count').textContent = serviceCount;
            document.getElementById('confirm-total-amount').textContent = totalAmount || '₱0.00';

            // Build services list
            const servicesList = document.getElementById('confirm-services-list');
            servicesList.innerHTML = '';
            
            if (selectedServices.length > 0) {
                selectedServices.forEach(service => {
                    const serviceItem = document.createElement('div');
                    serviceItem.className = 'service-item';
                    serviceItem.innerHTML = `
                        <span>${service.service_name || 'Unknown Service'}</span>
                        <span>Qty: ${service.quantity || 1} × ₱${parseFloat(service.price || 0).toFixed(2)} = ₱${parseFloat(service.subtotal || 0).toFixed(2)}</span>
                    `;
                    servicesList.appendChild(serviceItem);
                });
            } else {
                servicesList.innerHTML = '<div style="text-align: center; color: #6c757d; padding: 1rem;">No services selected</div>';
            }

            // Show modal
            document.getElementById('confirmInvoiceModal').style.display = 'flex';
        }

        function closeConfirmModal() {
            document.getElementById('confirmInvoiceModal').style.display = 'none';
        }

        function confirmCreateInvoice() {
            // Close confirmation modal
            closeConfirmModal();
            
            // Update service items field before submission
            updateServiceItemsField();
            
            // Add action field for form processing
            const actionField = document.createElement('input');
            actionField.type = 'hidden';
            actionField.name = 'action';
            actionField.value = 'create_invoice';
            document.getElementById('invoice-form').appendChild(actionField);
            
            // Submit the form
            document.getElementById('invoice-form').submit();
        }

        function showSuccessModal(invoiceId, patientName, totalAmount) {
            // Update success modal content
            document.getElementById('success-invoice-id').textContent = invoiceId;
            document.getElementById('success-patient-name').textContent = patientName;
            document.getElementById('success-total-amount').textContent = totalAmount;
            
            // Show modal
            document.getElementById('successModal').style.display = 'flex';
        }

        function printNewInvoice() {
            const invoiceId = document.getElementById('success-invoice-id').textContent;
            const printUrl = `${window.apiBase}/billing/management/print_invoice.php?billing_id=${invoiceId}&format=html`;
            window.open(printUrl, '_blank', 'width=800,height=900,scrollbars=yes,resizable=yes');
        }

        function downloadNewInvoice(format) {
            const invoiceId = document.getElementById('success-invoice-id').textContent;
            const downloadUrl = `${window.apiBase}/billing/management/download_invoice.php?billing_id=${invoiceId}&format=${format}`;
            
            // Create a temporary link element to trigger download
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = '';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function goToProcessPayment() {
            const invoiceId = document.getElementById('success-invoice-id').textContent;
            window.location.href = `process_payment.php?invoice_id=${invoiceId}`;
        }

        function createNewInvoice() {
            window.location.href = 'create_invoice.php';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const confirmModal = document.getElementById('confirmInvoiceModal');
            const successModal = document.getElementById('successModal');
            
            if (event.target == confirmModal) {
                closeConfirmModal();
            } else if (event.target == successModal) {
                // Don't auto-close success modal - user must choose action
            }
        }
    </script>

    <?php if (isset($show_success_modal) && $show_success_modal): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Show success modal after page load
            setTimeout(function() {
                showSuccessModal(
                    '<?= $billing_id ?>',
                    '<?= addslashes($patient_name) ?>',
                    '₱<?= number_format($total_amount, 2) ?>'
                );
            }, 500);
        });
    </script>
    <?php endif; ?>

    <!-- Invoice Confirmation Modal -->
    <div id="confirmInvoiceModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-file-invoice"></i> Confirm Invoice Creation</h3>
                <button type="button" class="close-modal" onclick="closeConfirmModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="confirmation-details">
                    <div class="detail-row">
                        <strong>Patient:</strong>
                        <span id="confirm-patient-name">-</span>
                    </div>
                    <div class="detail-row">
                        <strong>Total Services:</strong>
                        <span id="confirm-service-count">0</span>
                    </div>
                    <div class="detail-row">
                        <strong>Total Amount:</strong>
                        <span id="confirm-total-amount">₱0.00</span>
                    </div>
                </div>
                <div class="service-summary" id="confirm-services-list">
                    <!-- Services will be populated here -->
                </div>
                <p class="confirmation-message">
                    <i class="fas fa-info-circle"></i>
                    Are you sure you want to create this invoice? This action cannot be undone.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeConfirmModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="confirmCreateInvoice()">
                    <i class="fas fa-check"></i> Yes, Create Invoice
                </button>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="modal" style="display: none;">
        <div class="modal-content success-modal">
            <div class="success-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="success-title">
                <h3>Invoice Created Successfully!</h3>
                <p>Your invoice has been created and is ready for processing</p>
            </div>
            
            <div class="success-details">
                <div class="success-card">
                    <div class="card-icon">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <div class="card-content">
                        <div class="card-label">Invoice ID</div>
                        <div class="card-value" id="success-invoice-id">-</div>
                    </div>
                </div>
                
                <div class="success-card">
                    <div class="card-icon">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="card-content">
                        <div class="card-label">Patient</div>
                        <div class="card-value" id="success-patient-name">-</div>
                    </div>
                </div>
                
                <div class="success-card highlight">
                    <div class="card-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="card-content">
                        <div class="card-label">Total Amount</div>
                        <div class="card-value amount" id="success-total-amount">₱0.00</div>
                    </div>
                </div>
            </div>
            
            <div class="success-actions">
                <div class="primary-actions">
                    <button type="button" class="btn btn-success btn-lg" onclick="goToProcessPayment()">
                        <i class="fas fa-credit-card"></i>
                        <span>Process Payment</span>
                    </button>
                </div>
                
                <div class="secondary-actions">
                    <button type="button" class="btn btn-outline btn-md" onclick="downloadNewInvoice('pdf')">
                        <i class="fas fa-file-pdf"></i>
                        <span>Download PDF</span>
                    </button>
                    
                    <button type="button" class="btn btn-outline btn-md" onclick="downloadNewInvoice('html')">
                        <i class="fas fa-file-code"></i>
                        <span>Download HTML</span>
                    </button>
                    
                    <button type="button" class="btn btn-outline btn-md" onclick="printNewInvoice()">
                        <i class="fas fa-print"></i>
                        <span>Print Invoice</span>
                    </button>
                </div>
                
                <div class="tertiary-actions">
                    <button type="button" class="btn btn-text" onclick="createNewInvoice()">
                        <i class="fas fa-plus"></i>
                        <span>Create New Invoice</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Modal Styles */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
        }

        .modal-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px 25px 15px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: between;
        }

        .modal-header.success {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-bottom: none;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            flex-grow: 1;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            color: #666;
            cursor: pointer;
            padding: 0;
            margin-left: 15px;
        }

        .modal-header.success .close-modal {
            color: white;
        }

        .modal-body {
            padding: 25px;
        }

        .confirmation-details, .invoice-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }

        .detail-row {
            display: flex;
            justify-content: between;
            margin-bottom: 8px;
            padding: 5px 0;
        }

        .detail-row:last-child {
            margin-bottom: 0;
            border-top: 1px solid #dee2e6;
            padding-top: 10px;
            font-weight: bold;
        }

        .service-summary {
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 15px;
            margin: 15px 0;
            max-height: 200px;
            overflow-y: auto;
        }

        .service-item {
            display: flex;
            justify-content: between;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .service-item:last-child {
            border-bottom: none;
        }

        .confirmation-message {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 12px;
            margin: 15px 0 0;
            color: #856404;
        }

        .success-message {
            text-align: center;
        }

        .success-message p {
            font-size: 1.1rem;
            margin-bottom: 20px;
            color: #28a745;
        }

        .modal-footer {
            padding: 15px 25px 25px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        .modal-footer .btn {
            min-width: 120px;
        }

        /* Enhanced Success Modal Styles */
        .success-modal {
            max-width: 520px;
            text-align: center;
            padding: 0;
            border-radius: 16px;
            overflow: hidden;
        }

        .success-icon {
            background: linear-gradient(135deg, #28a745, #20c997);
            padding: 2.5rem 2rem 1.5rem;
            color: white;
        }

        .success-icon i {
            font-size: 4rem;
            animation: bounceIn 0.6s ease-out;
        }

        @keyframes bounceIn {
            0% {
                transform: scale(0.3);
                opacity: 0;
            }
            50% {
                transform: scale(1.1);
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        .success-title {
            padding: 1rem 2rem 0;
        }

        .success-title h3 {
            margin: 0 0 0.5rem;
            font-size: 1.5rem;
            color: #2c3e50;
            font-weight: 600;
        }

        .success-title p {
            margin: 0;
            color: #6c757d;
            font-size: 0.95rem;
        }

        .success-details {
            padding: 1.5rem 2rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .success-card {
            display: flex;
            align-items: center;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            transition: all 0.2s ease;
        }

        .success-card.highlight {
            background: linear-gradient(135deg, #e8f5e8, #f0f8f0);
            border-color: #28a745;
        }

        .success-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .card-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }

        .success-card.highlight .card-icon {
            background: #28a745;
            color: white;
        }

        .card-icon i {
            font-size: 1.1rem;
            color: #6c757d;
        }

        .card-content {
            flex: 1;
            text-align: left;
        }

        .card-label {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 500;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .card-value {
            font-size: 1rem;
            color: #2c3e50;
            font-weight: 600;
        }

        .card-value.amount {
            font-size: 1.2rem;
            color: #28a745;
            font-weight: 700;
        }

        .success-actions {
            padding: 0 2rem 2rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .primary-actions .btn {
            width: 100%;
        }

        .secondary-actions {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
        }

        .tertiary-actions {
            border-top: 1px solid #e9ecef;
            padding-top: 1rem;
            margin-top: 0.5rem;
        }

        /* Enhanced Button Styles */
        .btn-lg {
            padding: 0.875rem 1.5rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }

        .btn-md {
            padding: 0.625rem 0.875rem;
            font-size: 0.875rem;
            font-weight: 500;
            border-radius: 6px;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.25rem;
            transition: all 0.2s ease;
        }

        .btn-text {
            background: none;
            border: none;
            color: #6c757d;
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            font-weight: 500;
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .btn-text:hover {
            background: #f8f9fa;
            color: #495057;
        }

        .btn-outline {
            background: white;
            border: 1.5px solid #e9ecef;
            color: #495057;
        }

        .btn-outline:hover {
            border-color: #0077b6;
            background: #f8fbff;
            color: #0077b6;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 119, 182, 0.1);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #218838, #1e7e34);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        /* Responsive modal */
        @media (max-width: 768px) {
            .modal {
                padding: 10px;
            }
            
            .modal-content {
                max-width: 100%;
                margin: 0;
            }
            
            .modal-footer {
                flex-direction: column;
            }
            
            .modal-footer .btn {
                width: 100%;
                margin: 5px 0;
            }

            /* Success Modal Responsive */
            .success-modal {
                margin: 0;
                max-height: 95vh;
                border-radius: 12px;
            }
            
            .success-details {
                padding: 1rem;
            }
            
            .success-actions {
                padding: 0 1rem 1.5rem;
            }
            
            .secondary-actions {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }
            
            .btn-md {
                flex-direction: row;
                justify-content: center;
            }

            .success-icon {
                padding: 2rem 1rem 1rem;
            }

            .success-icon i {
                font-size: 3rem;
            }
        }
    </style>
</body>

</html>