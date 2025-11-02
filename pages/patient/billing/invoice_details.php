<?php
/**
 * Patient Invoice Details - View detailed bill information
 */

// Start output buffering immediately to prevent any header issues
ob_start();

// Clean any potential output that might have been sent
if (ob_get_length()) {
    ob_clean();
}

// Set error reporting for debugging but don't display errors in production
error_reporting(E_ALL);
ini_set('display_errors', '0');  // Never show errors to users in production
ini_set('log_errors', '1');      // Log errors for debugging

// Include patient session configuration
$root_path = dirname(dirname(dirname(__DIR__)));

// Load configuration first
require_once $root_path . '/config/env.php';

// Load database connection
require_once $root_path . '/config/db.php';

// Then load session management
require_once $root_path . '/config/session/patient_session.php';

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Authentication check
if (!is_patient_logged_in()) {
    // Clear any output buffer content
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Check if headers can still be sent
    if (!headers_sent()) {
        header("Location: ../auth/patient_login.php");
        exit();
    } else {
        // Fallback if headers already sent
        echo '<script>window.location.href = "../auth/patient_login.php";</script>';
        exit();
    }
}

// Get billing ID from URL
$billing_id = isset($_GET['billing_id']) ? intval($_GET['billing_id']) : 0;

if ($billing_id <= 0) {
    ob_clean(); // Clear output buffer before redirect
    header("Location: billing.php?error=" . urlencode("Invalid bill ID"));
    exit();
}

// Get patient information
$patient_id = get_patient_session('patient_id');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
        <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Details - CHO Koronadal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/topbar.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f8f9fa;
            margin: 0;
            padding: 0;
            line-height: 1.6;
            color: #495057;
        }

        .invoice-container {
            max-width: 800px;
            margin: 2rem auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .invoice-header {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .invoice-header h1 {
            margin: 0;
            font-size: 2rem;
            font-weight: 600;
        }

        .invoice-header p {
            margin: 0.5rem 0 0 0;
            opacity: 0.9;
        }

        .invoice-details {
            padding: 2rem;
        }

        .detail-section {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e9ecef;
        }

        .detail-section:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .detail-section h3 {
            margin: 0 0 1rem 0;
            color: #28a745;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 0.75rem 2rem;
            align-items: center;
        }

        .detail-label {
            font-weight: 600;
            color: #6c757d;
        }

        .detail-value {
            color: #495057;
        }

        .amount-highlight {
            font-size: 1.2rem;
            font-weight: 700;
            color: #28a745;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-paid {
            background: #d4edda;
            color: #155724;
        }

        .status-unpaid {
            background: #f8d7da;
            color: #721c24;
        }

        .status-partial {
            background: #fff3cd;
            color: #856404;
        }

        .services-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .services-table th,
        .services-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .services-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .services-table td {
            color: #6c757d;
        }

        .services-table .amount-cell {
            text-align: right;
            font-weight: 600;
            color: #495057;
        }

        .total-row {
            background: #f8f9fa;
            font-weight: 700;
        }

        .total-row td {
            color: #28a745;
            font-size: 1.1rem;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid #e9ecef;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: #28a745;
            color: white;
        }

        .btn-primary:hover {
            background: #1e7e34;
            transform: translateY(-2px);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid #6c757d;
            color: #6c757d;
        }

        .btn-outline:hover {
            background: #6c757d;
            color: white;
        }

        .btn-group {
            position: relative;
            display: inline-block;
            z-index: 100;
        }

        .dropdown-toggle::after {
            content: '▼';
            font-size: 0.7rem;
            margin-left: 0.5rem;
            opacity: 0.7;
        }

        .receipt-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
            z-index: 9999;
            min-width: 220px;
            margin-top: 4px;
            max-height: 200px;
            overflow-y: auto;
            white-space: nowrap;
        }

        .receipt-item {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .receipt-item:last-child {
            border-bottom: none;
        }

        .receipt-item:hover {
            background: #f8f9fa;
        }

        .receipt-item div:first-child {
            font-weight: 600;
            color: #28a745;
            font-size: 0.85rem;
            margin-bottom: 2px;
        }

        .receipt-item div:last-child {
            font-size: 0.75rem;
            color: #6c757d;
            line-height: 1.3;
        }

        .loading-state {
            text-align: center;
            padding: 3rem;
        }

        .loading-state i {
            font-size: 3rem;
            color: #28a745;
            margin-bottom: 1rem;
        }

        .error-state {
            text-align: center;
            padding: 3rem;
            color: #dc3545;
        }

        .error-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .invoice-container {
                margin: 1rem;
                border-radius: 10px;
            }

            .invoice-details {
                padding: 1.5rem;
            }

            .detail-grid {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .services-table {
                font-size: 0.9rem;
            }

            .services-table th,
            .services-table td {
                padding: 0.5rem;
            }
        }
    </style>
</head>

<body>
    <?php
    include '../../../includes/topbar.php';
    echo renderTopbar([
        'title' => 'Invoice Details',
        'back_url' => 'billing.php',
        'user_type' => 'patient'
    ]);
    ?>

    <section class="homepage">
        <div class="invoice-container" id="invoiceContainer">
            <!-- Loading state initially -->
            <div class="loading-state">
                <i class="fas fa-spinner fa-spin"></i>
                <h3>Loading Invoice Details...</h3>
                <p>Please wait while we fetch your bill information.</p>
            </div>
        </div>
    </section>

    <script>
        // Load invoice details when page loads
        document.addEventListener('DOMContentLoaded', function() {
            loadInvoiceDetails();
        });

        async function loadInvoiceDetails() {
            try {
                const billingId = <?php echo $billing_id; ?>;
                console.log('Loading invoice details for billing ID:', billingId);
                
                // Use universal path function for API calls
                const basePath = getApiBasePath();
                const apiUrl = `${basePath}/api/billing/patient/get_invoice_details.php?billing_id=${billingId}`;
                console.log('API URL:', apiUrl);
                
                const response = await fetch(apiUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Cache-Control': 'no-cache',
                        'Content-Type': 'application/json'
                    }
                });

                console.log('Response status:', response.status);
                console.log('Response headers:', Object.fromEntries(response.headers.entries()));

                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Response error text:', errorText);
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const responseText = await response.text();
                console.log('Raw response:', responseText);
                
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('JSON parse error:', parseError);
                    console.error('Response was not valid JSON:', responseText);
                    throw new Error('Invalid JSON response from server');
                }

                if (result.success) {
                    console.log('Invoice data loaded successfully:', result.data);
                    displayInvoiceDetails(result.data);
                } else {
                    console.error('API returned error:', result.message);
                    showError(result.message || 'Unable to load invoice details');
                }
            } catch (error) {
                console.error('Error loading invoice details:', error);
                
                // Show detailed error information for debugging
                if (error.message.includes('HTTP 404')) {
                    showError('API endpoint not found. Please check the server configuration.');
                } else if (error.message.includes('HTTP 401')) {
                    showError('Authentication required. Please log in again.');
                } else if (error.message.includes('HTTP 500')) {
                    showError('Server error occurred. Please try again later.');
                } else if (error.message.includes('Failed to fetch')) {
                    showError('Network error. Please check your connection and try again.');
                } else {
                    showError(`Error: ${error.message}`);
                }
            }
        }

        function displayInvoiceDetails(invoice) {
            const container = document.getElementById('invoiceContainer');
            
            container.innerHTML = `
                <div class="invoice-header">
                    <h1><i class="fas fa-file-invoice"></i> Invoice #${String(invoice.billing_id).padStart(6, '0')}</h1>
                    <p>City Health Office - Koronadal</p>
                </div>

                <div class="invoice-details">
                    <!-- Invoice Information -->
                    <div class="detail-section">
                        <h3><i class="fas fa-info-circle"></i> Invoice Information</h3>
                        <div class="detail-grid">
                            <span class="detail-label">Invoice Date:</span>
                            <span class="detail-value">${formatDate(invoice.billing_date)}</span>
                            
                            <span class="detail-label">Due Date:</span>
                            <span class="detail-value">${invoice.due_date ? formatDate(invoice.due_date) : 'Not specified'}</span>
                            
                            <span class="detail-label">Status:</span>
                            <span class="detail-value">
                                <span class="status-badge status-${invoice.payment_status.toLowerCase()}">
                                    ${invoice.payment_status.toUpperCase()}
                                </span>
                            </span>
                            
                            <span class="detail-label">Total Amount:</span>
                            <span class="detail-value amount-highlight">₱${Number(invoice.total_amount).toLocaleString('en-PH', {minimumFractionDigits: 2})}</span>
                            
                            ${invoice.balance_due > 0 ? `
                                <span class="detail-label">Balance Due:</span>
                                <span class="detail-value amount-highlight" style="color: #dc3545;">₱${Number(invoice.balance_due).toLocaleString('en-PH', {minimumFractionDigits: 2})}</span>
                            ` : ''}
                        </div>
                    </div>

                    <!-- Patient Information -->
                    <div class="detail-section">
                        <h3><i class="fas fa-user"></i> Patient Information</h3>
                        <div class="detail-grid">
                            <span class="detail-label">Name:</span>
                            <span class="detail-value">${invoice.patient_name}</span>
                            
                            <span class="detail-label">Patient ID:</span>
                            <span class="detail-value">#${String(invoice.patient_id).padStart(6, '0')}</span>
                        </div>
                    </div>

                    <!-- Services -->
                    <div class="detail-section">
                        <h3><i class="fas fa-list"></i> Services Provided</h3>
                        <table class="services-table">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th>Description</th>
                                    <th style="text-align: right;">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${invoice.items.map(item => `
                                    <tr>
                                        <td>${item.service_name}</td>
                                        <td>${item.description || 'Standard service'}</td>
                                        <td class="amount-cell">₱${Number(item.amount).toLocaleString('en-PH', {minimumFractionDigits: 2})}</td>
                                    </tr>
                                `).join('')}
                                <tr class="total-row">
                                    <td colspan="2"><strong>Total Amount</strong></td>
                                    <td class="amount-cell"><strong>₱${Number(invoice.total_amount).toLocaleString('en-PH', {minimumFractionDigits: 2})}</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    ${invoice.payment_history && invoice.payment_history.length > 0 ? `
                        <!-- Payment History -->
                        <div class="detail-section">
                            <h3><i class="fas fa-history"></i> Payment History</h3>
                            <table class="services-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Method</th>
                                        <th style="text-align: right;">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${invoice.payment_history.map(payment => `
                                        <tr>
                                            <td>${formatDate(payment.payment_date)}</td>
                                            <td>${payment.payment_method}</td>
                                            <td class="amount-cell">₱${Number(payment.amount).toLocaleString('en-PH', {minimumFractionDigits: 2})}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    ` : ''}

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <button class="btn btn-outline" onclick="window.close()">
                            <i class="fas fa-times"></i> Close
                        </button>
                        
                        ${invoice.receipts && invoice.receipts.length > 0 ? `
                            <div class="btn-group" style="position: relative;">
                                <button class="btn btn-primary dropdown-toggle" onclick="toggleReceiptDropdown(${invoice.billing_id})">
                                    <i class="fas fa-receipt"></i> View Receipts
                                </button>
                                <div id="receiptDropdown${invoice.billing_id}" class="receipt-dropdown" style="display: none;">
                                    ${invoice.receipts.map(receipt => `
                                        <div class="receipt-item" onclick="viewReceipt(${receipt.receipt_id})">
                                            <div>Receipt #${String(receipt.receipt_id).padStart(6, '0')}</div>
                                            <div>₱${Number(receipt.amount_paid).toLocaleString('en-PH', {minimumFractionDigits: 2})} - ${formatDate(receipt.payment_date)}</div>
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                        ` : ''}
                        
                        <button class="btn btn-outline" onclick="printInvoice()">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <!--<button class="btn btn-info" onclick="downloadInvoicePDF()">
                            <i class="fas fa-download"></i> Download PDF
                        </button>
                        <button class="btn btn-secondary" onclick="downloadInvoiceHTML()">
                            <i class="fas fa-file-code"></i> Download HTML
                        </button>-->
                        
                        ${invoice.payment_status === 'unpaid' ? `
                            <button class="btn btn-primary" onclick="showPaymentInfo(${invoice.billing_id})">
                                <i class="fas fa-credit-card"></i> Payment Info
                            </button>
                        ` : ''}
                    </div>
                </div>
            `;
        }

        function showError(message) {
            const container = document.getElementById('invoiceContainer');
            container.innerHTML = `
                <div class="error-state">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h3>Error Loading Invoice</h3>
                    <p>${message}</p>
                    <button class="btn btn-primary" onclick="loadInvoiceDetails()" style="margin-top: 1rem;">
                        <i class="fas fa-refresh"></i> Try Again
                    </button>
                </div>
            `;
        }

        // Universal path function for API calls (works in both local and production)
        function getApiBasePath() {
            const currentPath = window.location.pathname;
            const pathParts = currentPath.split('/');
            
            // Find the project root by looking for the pages directory
            let projectRoot = '';
            for (let i = 0; i < pathParts.length; i++) {
                if (pathParts[i] === 'pages') {
                    projectRoot = pathParts.slice(0, i).join('/') || '/';
                    break;
                }
            }
            
            // If no pages directory found, try to find wbhsms-cho-koronadal-1
            if (!projectRoot && currentPath.includes('wbhsms-cho-koronadal-1')) {
                const index = currentPath.indexOf('wbhsms-cho-koronadal-1');
                projectRoot = currentPath.substring(0, index + 'wbhsms-cho-koronadal-1'.length);
            } else if (!projectRoot) {
                // Fallback for production environments
                projectRoot = '';
            }
            
            return projectRoot;
        }

        function downloadReceipt(billingId) {
            // Legacy function - redirect to individual receipts
            console.log('Redirecting to individual receipts for billing ID:', billingId);
            const dropdown = document.getElementById(`receiptDropdown${billingId}`);
            if (dropdown) {
                toggleReceiptDropdown(billingId);
            }
        }

        // Toggle receipt dropdown for individual receipts
        function toggleReceiptDropdown(billingId) {
            const dropdown = document.getElementById(`receiptDropdown${billingId}`);
            if (dropdown) {
                const isVisible = dropdown.style.display === 'block';
                
                // Hide all other open dropdowns
                document.querySelectorAll('.receipt-dropdown').forEach(d => {
                    d.style.display = 'none';
                });
                
                // Toggle current dropdown
                dropdown.style.display = isVisible ? 'none' : 'block';
                
                // Close dropdown when clicking outside
                if (!isVisible) {
                    document.addEventListener('click', function closeDropdown(e) {
                        if (!e.target.closest('.btn-group')) {
                            dropdown.style.display = 'none';
                            document.removeEventListener('click', closeDropdown);
                        }
                    });
                }
            }
        }

        // View individual receipt in popup window
        function viewReceipt(receiptId) {
            console.log('Opening receipt in popup window:', receiptId);
            const basePath = getApiBasePath();
            const receiptUrl = `${basePath}/api/billing/patient/view_receipt.php?receipt_id=${receiptId}`;
            
            // Open in popup window optimized for receipts
            const popup = window.open(
                receiptUrl, 
                'receipt_' + receiptId,
                'width=800,height=900,scrollbars=yes,resizable=yes,menubar=no,toolbar=no,location=no,status=no'
            );
            
            if (!popup) {
                alert('Popup blocked! Please enable popups for this site to view receipts.');
                return;
            }
            
            // Focus the popup window
            popup.focus();
        }

        function printInvoice() {
            // Use dedicated invoice API for better formatting
            const billingId = new URLSearchParams(window.location.search).get('billing_id');
            if (billingId) {
                const basePath = getApiBasePath();
                const printUrl = `${basePath}/api/billing/patient/view_invoice.php?billing_id=${billingId}&format=html`;
                console.log('Print URL:', printUrl);
                window.open(printUrl, '_blank', 'width=800,height=900,scrollbars=yes,resizable=yes');
            } else {
                // Fallback to page print if no billing ID
                window.print();
            }
        }

        function downloadInvoicePDF() {
            const billingId = new URLSearchParams(window.location.search).get('billing_id');
            if (billingId) {
                const basePath = getApiBasePath();
                const downloadUrl = `${basePath}/api/billing/patient/download_invoice.php?billing_id=${billingId}&format=pdf`;
                console.log('Download URL:', downloadUrl);
                
                // Create a temporary link element to trigger download
                const link = document.createElement('a');
                link.href = downloadUrl;
                link.download = '';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        function downloadInvoiceHTML() {
            const billingId = new URLSearchParams(window.location.search).get('billing_id');
            if (billingId) {
                const basePath = getApiBasePath();
                const downloadUrl = `${basePath}/api/billing/patient/download_invoice.php?billing_id=${billingId}&format=html`;
                console.log('Download HTML URL:', downloadUrl);
                
                // Create a temporary link element to trigger download
                const link = document.createElement('a');
                link.href = downloadUrl;
                link.download = '';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }

        function showPaymentInfo(billingId) {
            const message = `Payment Information:

Visit CHO Koronadal to pay your bill:

📍 Location: City Health Office, Koronadal City
🕐 Hours: Monday-Friday, 8:00 AM - 5:00 PM
📞 Phone: (083) 228-8045

Payment Methods:
• Cash (at cashier window)
• Check (with valid ID)

Bill Reference: #${String(billingId).padStart(6, '0')}

Please bring this reference number when paying.`;

            alert(message);
        }

        function formatDate(dateString) {
            return new Date(dateString).toLocaleDateString('en-PH', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }
    </script>
</body>
</html>
<?php
// Flush output buffer
ob_end_flush();
?>