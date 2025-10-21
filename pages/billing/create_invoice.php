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

// Check if user is logged in and authorized
if (!is_employee_logged_in()) {
    header('Location: ../management/auth/employee_login.php');
    exit();
}

$employee_id = get_employee_session('employee_id');
$employee_role = get_employee_session('role');

// Check if role is authorized for billing operations
$allowed_roles = ['Cashier', 'Admin'];
if (!in_array($employee_role, $allowed_roles)) {
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
            
            // Clear form data after successful creation
            $_POST = [];
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollback();
            }
            $error_message = 'Error creating invoice: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Invoice - CHO Koronadal</title>
    <link rel="stylesheet" href="<?= $root_path ?>/assets/css/topbar.css">
    <link rel="stylesheet" href="<?= $root_path ?>/assets/css/profile-edit.css">
    <link rel="stylesheet" href="<?= $root_path ?>/assets/css/edit.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .form-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin: 1rem 0;
            padding: 1.5rem;
        }
        
        .form-section.disabled {
            background: #f8f9fa;
            opacity: 0.6;
            pointer-events: none;
        }
        
        .search-controls {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            align-items: end;
        }
        
        .search-controls > div {
            flex: 1;
        }
        
        .search-controls button {
            height: 38px;
            padding: 0 1rem;
        }
        
        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .results-table th,
        .results-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .results-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        
        .results-table tbody tr:hover {
            background-color: #f5f5f5;
            cursor: pointer;
        }
        
        .results-table tbody tr.selected {
            background-color: #e3f2fd;
        }
        
        .service-selection {
            display: grid;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .service-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #f9f9f9;
        }
        
        .service-item.selected {
            background: #e3f2fd;
            border-color: #2196F3;
        }
        
        .service-item input[type="checkbox"] {
            margin: 0;
        }
        
        .service-item .service-name {
            flex: 1;
            font-weight: 500;
        }
        
        .service-item .price {
            font-weight: 600;
            color: #2196F3;
            min-width: 100px;
            text-align: right;
        }
        
        .service-item .quantity-input {
            width: 60px;
            text-align: center;
        }
        
        .service-item .subtotal {
            font-weight: 600;
            min-width: 100px;
            text-align: right;
        }
        
        .totals-section {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            margin-top: 1rem;
        }
        
        .totals-row {
            display: flex;
            justify-content: space-between;
            margin: 0.5rem 0;
        }
        
        .totals-row.total {
            font-size: 1.2em;
            font-weight: 600;
            border-top: 2px solid #ddd;
            padding-top: 0.5rem;
        }
        
        .discount-section {
            margin: 1rem 0;
        }
        
        .discount-options {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }
        
        .discount-options label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
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
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 10% auto;
            padding: 2rem;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            text-align: center;
        }
        
        .modal-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php renderTopbar([
            'title' => 'Create Invoice',
            'back_url' => 'billing_management.php',
            'user_type' => 'employee'
        ]); ?>

        <section class="homepage">
            <div class="main-content">
                <!-- Alert Messages -->
                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                        <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                        <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
                    </div>
                <?php endif; ?>

                <!-- Patient Search Section -->
                <div class="form-section" id="patient-search-section">
                    <h3><i class="fas fa-search"></i> Search Patient</h3>
                    
                    <div class="search-controls">
                        <div>
                            <label for="patient_id_search">Patient ID</label>
                            <input type="text" id="patient_id_search" placeholder="Enter Patient ID">
                        </div>
                        <div>
                            <label for="first_name_search">First Name</label>
                            <input type="text" id="first_name_search" placeholder="Enter First Name">
                        </div>
                        <div>
                            <label for="last_name_search">Last Name</label>
                            <input type="text" id="last_name_search" placeholder="Enter Last Name">
                        </div>
                        <div>
                            <label for="barangay_search">Barangay</label>
                            <input type="text" id="barangay_search" placeholder="Enter Barangay">
                        </div>
                        <div>
                            <button type="button" class="btn btn-primary" onclick="searchPatients()">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                        <div>
                            <button type="button" class="btn btn-secondary" onclick="clearSearch()">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>
                    </div>

                    <div id="patient-results" style="display: none;">
                        <table class="results-table">
                            <thead>
                                <tr>
                                    <th>Patient ID</th>
                                    <th>Full Name</th>
                                    <th>Barangay</th>
                                    <th>Age</th>
                                    <th>Contact</th>
                                    <th>Today's Visits</th>
                                </tr>
                            </thead>
                            <tbody id="patient-results-body">
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Service Selection Section -->
                <div class="form-section disabled" id="service-selection-section">
                    <h3><i class="fas fa-medical"></i> Service Selection</h3>
                    
                    <div class="selected-patient-info" id="selected-patient-info" style="display: none;">
                        <p><strong>Selected Patient:</strong> <span id="selected-patient-name"></span></p>
                    </div>

                    <div class="service-selection" id="service-items-container">
                        <!-- Service items will be loaded here -->
                    </div>

                    <div class="discount-section">
                        <h4>Discount Options</h4>
                        <div class="discount-options">
                            <label>
                                <input type="radio" name="discount_type" value="none" checked>
                                No Discount
                            </label>
                            <label>
                                <input type="radio" name="discount_type" value="senior">
                                Senior Citizen (20%)
                            </label>
                            <label>
                                <input type="radio" name="discount_type" value="pwd">
                                PWD (20%)
                            </label>
                        </div>
                    </div>

                    <div class="totals-section">
                        <div class="totals-row">
                            <span>Net Amount:</span>
                            <span id="net-amount">₱0.00</span>
                        </div>
                        <div class="totals-row" id="discount-row" style="display: none;">
                            <span>Discount (20%):</span>
                            <span id="discount-amount">-₱0.00</span>
                        </div>
                        <div class="totals-row total">
                            <span>Total Amount:</span>
                            <span id="total-amount">₱0.00</span>
                        </div>
                    </div>

                    <div style="margin-top: 1rem;">
                        <label for="invoice-notes">Notes (Optional)</label>
                        <textarea id="invoice-notes" rows="3" placeholder="Additional notes for this invoice..."></textarea>
                    </div>

                    <div style="margin-top: 1.5rem;">
                        <button type="button" class="btn btn-success" id="create-invoice-btn" onclick="confirmCreateInvoice()" disabled>
                            <i class="fas fa-plus"></i> Create Invoice
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmation-modal" class="modal">
        <div class="modal-content">
            <h3><i class="fas fa-question-circle"></i> Confirm Invoice Creation</h3>
            <p>Are you sure you want to create an invoice with the following details?</p>
            <div id="confirmation-details"></div>
            <div class="modal-buttons">
                <button type="button" class="btn btn-secondary" onclick="closeConfirmationModal()">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitInvoice()">
                    <i class="fas fa-check"></i> Confirm
                </button>
            </div>
        </div>
    </div>

    <!-- Loading Modal -->
    <div id="loading-modal" class="modal">
        <div class="modal-content">
            <i class="fas fa-spinner fa-spin fa-3x"></i>
            <p>Creating invoice, please wait...</p>
        </div>
    </div>

    <script>
        let selectedPatient = null;
        let selectedServices = [];
        let serviceItems = [];

        // Load service items on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadServiceItems();
        });

        // Search patients
        function searchPatients() {
            const searchData = {
                patient_id: document.getElementById('patient_id_search').value.trim(),
                first_name: document.getElementById('first_name_search').value.trim(),
                last_name: document.getElementById('last_name_search').value.trim(),
                barangay: document.getElementById('barangay_search').value.trim()
            };

            // At least one search field must be filled
            if (!Object.values(searchData).some(val => val !== '')) {
                showAlert('Please enter at least one search criteria.', 'warning');
                return;
            }

            const searchTerm = Object.values(searchData).filter(val => val !== '').join(' ');

            fetch(`../../../api/search_patients_simple.php?query=${encodeURIComponent(searchTerm)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayPatientResults(data.patients);
                    } else {
                        showAlert('Error searching patients: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('An error occurred while searching patients.', 'error');
                });
        }

        // Display patient search results
        function displayPatientResults(patients) {
            const tbody = document.getElementById('patient-results-body');
            tbody.innerHTML = '';

            if (patients.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No patients found</td></tr>';
            } else {
                patients.forEach(patient => {
                    const row = document.createElement('tr');
                    row.onclick = () => selectPatient(patient, row);
                    row.innerHTML = `
                        <td>${patient.username}</td>
                        <td>${patient.full_name}</td>
                        <td>${patient.barangay || 'N/A'}</td>
                        <td>${patient.age || 'N/A'}</td>
                        <td>${patient.contact_number || 'N/A'}</td>
                        <td>${patient.today_visits > 0 ? 
                            '<span style="color: green;">✓ ' + patient.today_visits + '</span>' : 
                            '<span style="color: red;">No visits today</span>'}</td>
                    `;
                    tbody.appendChild(row);
                });
            }

            document.getElementById('patient-results').style.display = 'block';
        }

        // Select patient from search results
        function selectPatient(patient, row) {
            // Remove previous selections
            document.querySelectorAll('.results-table tbody tr').forEach(tr => tr.classList.remove('selected'));
            row.classList.add('selected');

            selectedPatient = patient;
            
            // Show selected patient info
            document.getElementById('selected-patient-name').textContent = patient.full_name + ' (' + patient.username + ')';
            document.getElementById('selected-patient-info').style.display = 'block';

            // Enable service selection section
            const serviceSection = document.getElementById('service-selection-section');
            serviceSection.classList.remove('disabled');

            // Clear previous service selections
            selectedServices = [];
            updateTotals();
        }

        // Load service items
        function loadServiceItems() {
            fetch('../../../api/get_service_catalog.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        serviceItems = data.services;
                        displayServiceItems();
                    } else {
                        console.error('Error loading service items:', data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
        }

        // Display service items
        function displayServiceItems() {
            const container = document.getElementById('service-items-container');
            container.innerHTML = '';

            serviceItems.forEach(service => {
                // Create service group header
                const groupHeader = document.createElement('h4');
                groupHeader.textContent = service.service_name;
                groupHeader.style.marginTop = '1.5rem';
                groupHeader.style.marginBottom = '0.5rem';
                groupHeader.style.color = '#2196F3';
                container.appendChild(groupHeader);

                // Create service items
                service.items.forEach(item => {
                    const serviceDiv = document.createElement('div');
                    serviceDiv.className = 'service-item';
                    serviceDiv.innerHTML = `
                        <input type="checkbox" id="service-${item.item_id}" onchange="toggleService(${item.item_id})">
                        <div class="service-name">${item.item_name}</div>
                        <div class="price" id="price-${item.item_id}">₱${item.price_php.toFixed(2)}</div>
                        <input type="number" class="quantity-input" id="qty-${item.item_id}" 
                               value="1" min="1" max="99" onchange="updateQuantity(${item.item_id})" disabled>
                        <div class="subtotal" id="subtotal-${item.item_id}">₱0.00</div>
                    `;
                    container.appendChild(serviceDiv);
                });
            });
        }

        // Toggle service selection
        function toggleService(itemId) {
            const checkbox = document.getElementById(`service-${itemId}`);
            const qtyInput = document.getElementById(`qty-${itemId}`);
            const serviceDiv = checkbox.closest('.service-item');

            if (checkbox.checked) {
                qtyInput.disabled = false;
                serviceDiv.classList.add('selected');
                updateQuantity(itemId);
            } else {
                qtyInput.disabled = true;
                serviceDiv.classList.remove('selected');
                selectedServices = selectedServices.filter(s => s.item_id !== itemId);
                document.getElementById(`subtotal-${itemId}`).textContent = '₱0.00';
                updateTotals();
            }
        }

        // Update quantity and subtotal
        function updateQuantity(itemId) {
            const quantity = parseInt(document.getElementById(`qty-${itemId}`).value) || 1;
            
            // Find the service item price
            let price = 0;
            serviceItems.forEach(service => {
                service.items.forEach(item => {
                    if (item.item_id === itemId) {
                        price = item.price_php;
                    }
                });
            });

            const subtotal = price * quantity;
            document.getElementById(`subtotal-${itemId}`).textContent = '₱' + subtotal.toFixed(2);

            // Update selected services array
            const existingIndex = selectedServices.findIndex(s => s.item_id === itemId);
            if (existingIndex >= 0) {
                selectedServices[existingIndex].quantity = quantity;
                selectedServices[existingIndex].subtotal = subtotal;
            } else {
                selectedServices.push({
                    item_id: itemId,
                    quantity: quantity,
                    price: price,
                    subtotal: subtotal
                });
            }

            updateTotals();
        }

        // Update totals calculation
        function updateTotals() {
            const netAmount = selectedServices.reduce((sum, service) => sum + service.subtotal, 0);
            
            // Get selected discount type
            const discountType = document.querySelector('input[name="discount_type"]:checked').value;
            let discountAmount = 0;
            
            if (discountType === 'senior' || discountType === 'pwd') {
                discountAmount = netAmount * 0.20;
            }

            const totalAmount = netAmount - discountAmount;

            // Update display
            document.getElementById('net-amount').textContent = '₱' + netAmount.toFixed(2);
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
            createBtn.disabled = !selectedPatient || selectedServices.length === 0;
        }

        // Add event listeners for discount options
        document.querySelectorAll('input[name="discount_type"]').forEach(radio => {
            radio.addEventListener('change', updateTotals);
        });

        // Clear search form
        function clearSearch() {
            document.getElementById('patient_id_search').value = '';
            document.getElementById('first_name_search').value = '';
            document.getElementById('last_name_search').value = '';
            document.getElementById('barangay_search').value = '';
            document.getElementById('patient-results').style.display = 'none';
            
            // Reset patient selection
            selectedPatient = null;
            document.getElementById('selected-patient-info').style.display = 'none';
            document.getElementById('service-selection-section').classList.add('disabled');
        }

        // Confirm invoice creation
        function confirmCreateInvoice() {
            if (!selectedPatient || selectedServices.length === 0) {
                showAlert('Please select a patient and at least one service.', 'warning');
                return;
            }

            const netAmount = selectedServices.reduce((sum, service) => sum + service.subtotal, 0);
            const discountType = document.querySelector('input[name="discount_type"]:checked').value;
            let discountAmount = 0;
            
            if (discountType === 'senior' || discountType === 'pwd') {
                discountAmount = netAmount * 0.20;
            }

            const totalAmount = netAmount - discountAmount;

            // Show confirmation details
            let detailsHTML = `
                <p><strong>Patient:</strong> ${selectedPatient.full_name} (${selectedPatient.username})</p>
                <p><strong>Services:</strong> ${selectedServices.length} item(s)</p>
                <p><strong>Net Amount:</strong> ₱${netAmount.toFixed(2)}</p>
            `;
            
            if (discountAmount > 0) {
                detailsHTML += `<p><strong>Discount:</strong> -₱${discountAmount.toFixed(2)} (${discountType.toUpperCase()})</p>`;
            }
            
            detailsHTML += `<p><strong>Total Amount:</strong> ₱${totalAmount.toFixed(2)}</p>`;

            document.getElementById('confirmation-details').innerHTML = detailsHTML;
            document.getElementById('confirmation-modal').style.display = 'block';
        }

        // Close confirmation modal
        function closeConfirmationModal() {
            document.getElementById('confirmation-modal').style.display = 'none';
        }

        // Submit invoice
        function submitInvoice() {
            document.getElementById('confirmation-modal').style.display = 'none';
            document.getElementById('loading-modal').style.display = 'block';

            const formData = new FormData();
            formData.append('action', 'create_invoice');
            formData.append('patient_id', selectedPatient.patient_id);
            formData.append('visit_id', selectedPatient.latest_visit_id || 0);
            formData.append('service_items', JSON.stringify(selectedServices.map(s => ({
                service_item_id: s.item_id,
                quantity: s.quantity
            }))));
            formData.append('discount_type', document.querySelector('input[name="discount_type"]:checked').value);
            formData.append('notes', document.getElementById('invoice-notes').value);

            // Convert FormData to JSON for the API
            const invoiceData = {
                patient_id: selectedPatient.patient_id,
                services: selectedServices.map(service => ({
                    service_item_id: service.service_item_id,
                    quantity: service.quantity
                })),
                discount_type: document.querySelector('input[name="discount_type"]:checked').value,
                discount_percentage: document.querySelector('input[name="discount_type"]:checked').value !== 'none' ? 20 : 0,
                notes: document.getElementById('invoice-notes').value,
                cashier_id: 1 // Default for testing
            };

            fetch('../../../api/create_invoice_clean.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(invoiceData)
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('loading-modal').style.display = 'none';
                
                if (data.success) {
                    showAlert('Invoice created successfully! Invoice ID: ' + data.data.billing_id, 'success');
                    setTimeout(() => {
                        window.location.href = 'billing_management.php';
                    }, 2000);
                } else {
                    showAlert('Error creating invoice: ' + data.message, 'error');
                }
            })
            .catch(error => {
                document.getElementById('loading-modal').style.display = 'none';
                console.error('Error:', error);
                showAlert('An error occurred while creating the invoice.', 'error');
            });
        }

        // Enter key search functionality
        document.querySelectorAll('#patient_id_search, #first_name_search, #last_name_search, #barangay_search').forEach(input => {
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    searchPatients();
                }
            });
        });
    </script>
</body>
</html>