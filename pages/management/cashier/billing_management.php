<?php
require_once __DIR__ . '/../../../config/paths.php'; // Global path configuration
ob_start(); // Start output buffering to prevent header issues
session_start();

$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/db.php';
require_once $root_path . '/config/session/employee_session.php';

// Check if user is logged in and has appropriate role (roles are stored in lowercase)
if (!is_employee_logged_in() || (get_employee_session('role') !== 'cashier' && get_employee_session('role') !== 'admin')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

$activePage = 'billing';
$pageTitle = 'Billing Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Management</title>
    <link rel="stylesheet" href="../../../assets/css/sidebar.css">
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .billing-content {
            padding: 20px;
            background: #f8f9fa;
            min-height: 100vh;
        }
        .billing-header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .billing-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .billing-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #007bff;
        }
        .billing-card h3 {
            margin: 0 0 10px 0;
            color: #333;
        }
        .billing-card p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        .btn-primary {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,123,255,0.3);
        }
        .btn-success {
            background: linear-gradient(135deg, #28a745, #1e7e34);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(40,167,69,0.3);
        }
        .pending-invoices, .recent-payments {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .table-header {
            background: #f8f9fa;
            padding: 20px;
            border-bottom: 1px solid #dee2e6;
        }
        .table-header h3 {
            margin: 0;
            color: #333;
        }
        .table-container {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-paid {
            background: #d1ecf1;
            color: #0c5460;
        }
        .status-overdue {
            background: #f8d7da;
            color: #721c24;
        }
        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-view {
            background: #007bff;
            color: white;
        }
        .btn-pay {
            background: #28a745;
            color: white;
        }
        .btn-edit {
            background: #ffc107;
            color: #212529;
        }
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
            background-color: #fefefe;
            margin: 5% auto;
            padding: 0;
            border-radius: 10px;
            width: 90%;
            max-width: 800px;
            max-height: 90%;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .modal-header {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: between;
            align-items: center;
        }
        .modal-body {
            padding: 20px;
            max-height: 60vh;
            overflow-y: auto;
        }
        .close {
            color: white;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #333;
        }
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        .service-selector {
            border: 1px solid #ddd;
            border-radius: 5px;
            max-height: 300px;
            overflow-y: auto;
        }
        .service-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
        }
        .service-item:hover {
            background: #f8f9fa;
        }
        .service-item input[type="checkbox"] {
            margin-right: 10px;
        }
        .invoice-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-top: 15px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        .total-row {
            font-weight: bold;
            font-size: 16px;
            border-top: 2px solid #007bff;
            padding-top: 10px;
        }
    </style>
</head>
<body>
    <div class="homepage">
        <?php include '../../../includes/sidebar_cashier.php'; ?>
        
        <div class="main-content">
            <div class="billing-content">
                <div class="billing-header">
                    <h1><i class="fas fa-cash-register"></i> Billing Management</h1>
                    <p>Manage patient invoices, process payments, and generate receipts</p>
                </div>

                <div class="billing-cards">
                    <div class="billing-card">
                        <h3><i class="fas fa-file-invoice-dollar"></i> Create Invoice</h3>
                        <p>Generate new invoices for patient services</p>
                        <div class="action-buttons">
                            <button class="btn-primary" onclick="openCreateInvoiceModal()">
                                <i class="fas fa-plus"></i> New Invoice
                            </button>
                        </div>
                    </div>

                    <div class="billing-card">
                        <h3><i class="fas fa-credit-card"></i> Process Payments</h3>
                        <p>Handle payments and generate receipts</p>
                        <div class="action-buttons">
                            <button class="btn-success" onclick="openPaymentModal()">
                                <i class="fas fa-money-bill-wave"></i> Process Payment
                            </button>
                        </div>
                    </div>

                    <div class="billing-card">
                        <h3><i class="fas fa-chart-bar"></i> View Reports</h3>
                        <p>Access billing reports and analytics</p>
                        <div class="action-buttons">
                            <a href="billing_reports.php" class="btn-primary">
                                <i class="fas fa-chart-line"></i> View Reports
                            </a>
                        </div>
                    </div>
                </div>

                <div class="pending-invoices">
                    <div class="table-header">
                        <h3><i class="fas fa-clock"></i> Pending Invoices</h3>
                    </div>
                    <div class="table-container">
                        <table id="pendingInvoicesTable">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Patient Name</th>
                                    <th>Date Created</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="pendingInvoicesBody">
                                <!-- Dynamic content will be loaded here -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Invoice Modal -->
    <div id="createInvoiceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-file-invoice-dollar"></i> Create New Invoice</h2>
                <span class="close" onclick="closeCreateInvoiceModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="createInvoiceForm">
                    <div class="form-group">
                        <label for="patientSelect">Select Patient:</label>
                        <select class="form-control" id="patientSelect" name="patient_id" required>
                            <option value="">Choose a patient...</option>
                            <!-- Options will be loaded dynamically -->
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Select Services:</label>
                        <div class="service-selector" id="serviceSelector">
                            <!-- Services will be loaded dynamically -->
                        </div>
                    </div>

                    <div class="invoice-summary">
                        <h4>Invoice Summary</h4>
                        <div id="selectedServices">
                            <p class="text-muted">No services selected</p>
                        </div>
                        <div class="summary-row total-row">
                            <span>Total Amount:</span>
                            <span id="totalAmount">₱0.00</span>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save"></i> Create Invoice
                        </button>
                        <button type="button" class="btn-secondary" onclick="closeCreateInvoiceModal()">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Payment Processing Modal -->
    <div id="paymentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-credit-card"></i> Process Payment</h2>
                <span class="close" onclick="closePaymentModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="paymentForm">
                    <div class="form-group">
                        <label for="invoiceSelect">Select Invoice:</label>
                        <select class="form-control" id="invoiceSelect" name="invoice_id" required>
                            <option value="">Choose an invoice...</option>
                            <!-- Options will be loaded dynamically -->
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="paymentAmount">Payment Amount:</label>
                        <input type="number" class="form-control" id="paymentAmount" name="payment_amount" step="0.01" min="0" required>
                    </div>

                    <div class="form-group">
                        <label for="paymentMethod">Payment Method:</label>
                        <select class="form-control" id="paymentMethod" name="payment_method" required>
                            <option value="">Select method...</option>
                            <option value="cash">Cash</option>
                            <option value="card">Credit/Debit Card</option>
                            <option value="check">Check</option>
                            <option value="bank_transfer">Bank Transfer</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="notes">Notes (Optional):</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Add any notes about this payment..."></textarea>
                    </div>

                    <div class="action-buttons">
                        <button type="submit" class="btn-success">
                            <i class="fas fa-check"></i> Process Payment
                        </button>
                        <button type="button" class="btn-secondary" onclick="closePaymentModal()">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Initialize page when loaded
        document.addEventListener('DOMContentLoaded', function() {
            loadPendingInvoices();
            loadPatients();
            loadServices();
            loadPendingInvoicesForPayment();
        });

        // Modal Functions
        function openCreateInvoiceModal() {
            document.getElementById('createInvoiceModal').style.display = 'block';
        }

        function closeCreateInvoiceModal() {
            document.getElementById('createInvoiceModal').style.display = 'none';
            document.getElementById('createInvoiceForm').reset();
            updateInvoiceSummary();
        }

        function openPaymentModal() {
            document.getElementById('paymentModal').style.display = 'block';
        }

        function closePaymentModal() {
            document.getElementById('paymentModal').style.display = 'none';
            document.getElementById('paymentForm').reset();
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const createModal = document.getElementById('createInvoiceModal');
            const paymentModal = document.getElementById('paymentModal');
            
            if (event.target == createModal) {
                closeCreateInvoiceModal();
            }
            if (event.target == paymentModal) {
                closePaymentModal();
            }
        }

        // Load pending invoices
        function loadPendingInvoices() {
            fetch('../../../api/get_patient_invoices.php?status=pending')
                .then(response => response.json())
                .then(data => {
                    const tbody = document.getElementById('pendingInvoicesBody');
                    tbody.innerHTML = '';

                    if (data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="6" class="text-center">No pending invoices found</td></tr>';
                        return;
                    }

                    data.forEach(invoice => {
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td>#${invoice.invoice_id}</td>
                            <td>${invoice.patient_name}</td>
                            <td>${new Date(invoice.created_at).toLocaleDateString()}</td>
                            <td>₱${parseFloat(invoice.total_amount).toFixed(2)}</td>
                            <td><span class="status-badge status-${invoice.status}">${invoice.status.toUpperCase()}</span></td>
                            <td>
                                <button class="action-btn btn-view" onclick="viewInvoice(${invoice.invoice_id})">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="action-btn btn-pay" onclick="processPaymentForInvoice(${invoice.invoice_id})">
                                    <i class="fas fa-credit-card"></i> Pay
                                </button>
                            </td>
                        `;
                        tbody.appendChild(row);
                    });
                })
                .catch(error => {
                    console.error('Error loading pending invoices:', error);
                });
        }

        // Load patients for dropdown
        function loadPatients() {
            fetch('../../../api/search_patients.php')
                .then(response => response.json())
                .then(data => {
                    const select = document.getElementById('patientSelect');
                    select.innerHTML = '<option value="">Choose a patient...</option>';

                    data.forEach(patient => {
                        const option = document.createElement('option');
                        option.value = patient.patient_id;
                        option.textContent = `${patient.first_name} ${patient.last_name} (ID: ${patient.patient_id})`;
                        select.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Error loading patients:', error);
                });
        }

        // Load services for selection
        function loadServices() {
            fetch('../../../api/get_service_catalog.php')
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('serviceSelector');
                    container.innerHTML = '';

                    data.forEach(service => {
                        const serviceDiv = document.createElement('div');
                        serviceDiv.className = 'service-item';
                        serviceDiv.innerHTML = `
                            <div>
                                <input type="checkbox" id="service_${service.id}" value="${service.id}" onchange="updateInvoiceSummary()">
                                <label for="service_${service.id}">${service.service_name}</label>
                            </div>
                            <span>₱${parseFloat(service.price).toFixed(2)}</span>
                        `;
                        container.appendChild(serviceDiv);
                    });
                })
                .catch(error => {
                    console.error('Error loading services:', error);
                });
        }

        // Update invoice summary
        function updateInvoiceSummary() {
            const checkboxes = document.querySelectorAll('#serviceSelector input[type="checkbox"]:checked');
            const selectedServicesDiv = document.getElementById('selectedServices');
            const totalAmountSpan = document.getElementById('totalAmount');
            
            let total = 0;
            let servicesHtml = '';

            if (checkboxes.length === 0) {
                selectedServicesDiv.innerHTML = '<p class="text-muted">No services selected</p>';
            } else {
                checkboxes.forEach(checkbox => {
                    const serviceItem = checkbox.closest('.service-item');
                    const serviceName = serviceItem.querySelector('label').textContent;
                    const priceText = serviceItem.querySelector('span').textContent;
                    const price = parseFloat(priceText.replace('₱', ''));
                    
                    total += price;
                    servicesHtml += `
                        <div class="summary-row">
                            <span>${serviceName}</span>
                            <span>₱${price.toFixed(2)}</span>
                        </div>
                    `;
                });
                selectedServicesDiv.innerHTML = servicesHtml;
            }

            totalAmountSpan.textContent = `₱${total.toFixed(2)}`;
        }

        // Load pending invoices for payment dropdown
        function loadPendingInvoicesForPayment() {
            fetch('../../../api/get_patient_invoices.php?status=pending')
                .then(response => response.json())
                .then(data => {
                    const select = document.getElementById('invoiceSelect');
                    select.innerHTML = '<option value="">Choose an invoice...</option>';

                    data.forEach(invoice => {
                        const option = document.createElement('option');
                        option.value = invoice.invoice_id;
                        option.textContent = `#${invoice.invoice_id} - ${invoice.patient_name} (₱${parseFloat(invoice.total_amount).toFixed(2)})`;
                        select.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Error loading invoices:', error);
                });
        }

        // Form submission handlers
        document.getElementById('createInvoiceForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData();
            const patientId = document.getElementById('patientSelect').value;
            const selectedServices = Array.from(document.querySelectorAll('#serviceSelector input[type="checkbox"]:checked'))
                .map(cb => cb.value);

            if (!patientId) {
                alert('Please select a patient');
                return;
            }

            if (selectedServices.length === 0) {
                alert('Please select at least one service');
                return;
            }

            formData.append('patient_id', patientId);
            formData.append('service_ids', JSON.stringify(selectedServices));
            formData.append('cashier_id', <?php echo get_employee_session('employee_id'); ?>);

            fetch('../../../api/create_invoice.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Invoice created successfully!');
                    closeCreateInvoiceModal();
                    loadPendingInvoices();
                    loadPendingInvoicesForPayment();
                } else {
                    alert('Error creating invoice: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error creating invoice');
            });
        });

        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('cashier_id', <?php echo get_employee_session('employee_id'); ?>);

            fetch('../../../api/process_payment.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Payment processed successfully!');
                    closePaymentModal();
                    loadPendingInvoices();
                    loadPendingInvoicesForPayment();
                    
                    // Optionally print receipt
                    if (confirm('Would you like to print the receipt?')) {
                        window.open(`../../../api/generate_receipt.php?payment_id=${data.payment_id}`, '_blank');
                    }
                } else {
                    alert('Error processing payment: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error processing payment');
            });
        });

        // Action functions
        function viewInvoice(invoiceId) {
            window.open(`../../../api/view_invoice.php?id=${invoiceId}`, '_blank');
        }

        function processPaymentForInvoice(invoiceId) {
            document.getElementById('invoiceSelect').value = invoiceId;
            openPaymentModal();
        }
    </script>
</body>
</html>
<?php ob_end_flush(); // End output buffering ?>