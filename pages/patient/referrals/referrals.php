<?php
// Start output buffering at the very beginning
ob_start();

// Set error reporting for debugging but don't display errors in production
error_reporting(E_ALL);
ini_set('display_errors', '0');  // Never show errors to users in production
ini_set('log_errors', '1');      // Log errors for debugging

// Include patient session configuration FIRST - before any output
$root_path = dirname(dirname(dirname(__DIR__)));

// Load configuration first
require_once $root_path . '/config/env.php';

// Then load session management
require_once $root_path . '/config/session/patient_session.php';

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If user is not logged in, redirect to login
if (!isset($_SESSION['patient_id'])) {
    ob_clean(); // Clear output buffer before redirect
    header('Location: ../auth/patient_login.php');
    exit();
}

// Database connection
require_once $root_path . '/config/db.php';

// Include automatic status updater
require_once $root_path . '/utils/automatic_status_updater.php';

$patient_id = $_SESSION['patient_id'];
$message = '';
$error = '';

// Run automatic status updates when page loads
try {
    $status_updater = new AutomaticStatusUpdater($conn);
    $update_result = $status_updater->runAllUpdates();
    
    // Optional: Show update message to user (you can remove this if you don't want to show it)
    if ($update_result['success'] && $update_result['total_updates'] > 0) {
        $message = "Status updates applied: " . $update_result['total_updates'] . " records updated automatically.";
    }
} catch (Exception $e) {
    // Log error but don't show to user to avoid confusion
    error_log("Failed to run automatic status updates: " . $e->getMessage());
}

// Fetch patient information
$patient_info = null;
try {
    $stmt = $conn->prepare("
        SELECT p.*, b.barangay_name
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.patient_id = ?
    ");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $patient_info = $result->fetch_assoc();

    // Calculate priority level
    if ($patient_info) {
        $patient_info['priority_level'] = ($patient_info['isPWD'] || $patient_info['isSenior']) ? 1 : 2;
        $patient_info['priority_description'] = ($patient_info['priority_level'] == 1) ? 'Priority Patient' : 'Regular Patient';
    }

    $stmt->close();
} catch (Exception $e) {
    $error = "Failed to fetch patient information: " . $e->getMessage();
}

// Fetch referrals (limit to recent 30 for better overview)
$referrals = [];
try {
    $stmt = $conn->prepare("
        SELECT r.*, 
               f.name as facility_name, f.type as facility_type,
               e.first_name as doctor_first_name, e.last_name as doctor_last_name,
               s.name as service_name, s.description as service_description
        FROM referrals r
        LEFT JOIN facilities f ON r.referred_to_facility_id = f.facility_id
        LEFT JOIN employees e ON r.referred_by = e.employee_id
        LEFT JOIN services s ON r.service_id = s.service_id
        WHERE r.patient_id = ?
        ORDER BY r.referral_date DESC
        LIMIT 30
    ");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $referrals = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    $error = "Failed to fetch referrals: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Medical Referrals - CHO Koronadal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../../assets/css/sidebar.css">
    <style>
        .content-wrapper {
            margin-left: 300px;
            padding: 2rem;
            transition: margin-left 0.3s;
        }

        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
                padding: 1rem;
            }
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e9ecef;
        }

        .page-header h1 {
            margin: 0;
            font-size: 2.2rem;
            color: #0077b6;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .breadcrumb {
            background: none;
            padding: 0;
            margin: 0 0 1rem 0;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .breadcrumb a {
            color: #6c757d;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            color: #0077b6;
        }

        .action-buttons {
            display: flex;
            gap: 0.7rem;
            flex-wrap: wrap;
        }

        .btn {
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background: #007BFF;
            border: none;
            border-radius: 8px;
            color: #fff;
            font-size: 16px;
            font-weight: 600;
            padding: 12px 28px;
            text-align: center;
            display: inline-block;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }

        .btn-primary i {
            margin-right: 8px;
            font-size: 18px;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #16a085, #0f6b5c);
            color: white;
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #0f6b5c, #0a4f44);
            transform: translateY(-2px);
        }

        .section-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f8f9fa;
            justify-content: flex-start;
        }

        .section-icon {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .section-title {
            margin: 0;
            font-size: 1.5rem;
            color: #0077b6;
            font-weight: 600;
        }

        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 0.5rem 1rem;
            border: 2px solid #e9ecef;
            background: #f8f9fa;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .filter-tab.active {
            background: #0077b6;
            color: white;
            border-color: #023e8a;
        }

        .filter-tab:hover {
            background: #e3f2fd;
            border-color: #0077b6;
        }

        .filter-tab.active:hover {
            background: #023e8a;
        }

        .search-filters {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e9ecef;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .filter-group input,
        .filter-group select {
            padding: 0.5rem;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 2px rgba(0, 119, 182, 0.1);
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-filter {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-filter-primary {
            background: #0077b6;
            color: white;
        }

        .btn-filter-primary:hover {
            background: #023e8a;
        }

        .btn-filter-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-filter-secondary:hover {
            background: #5a6268;
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.5rem;
        }

        .referral-card {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 15px;
            padding: 1.8rem;
            transition: all 0.3s ease;
            position: relative;
            min-height: 320px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            box-sizing: content-box;
        }

        .referral-card:hover {
            border-color: #0077b6;
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(0, 119, 182, 0.2);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.2rem;
            padding-bottom: 0.8rem;
            border-bottom: 1px solid #f1f3f4;
        }

        .card-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #0077b6;
            margin: 0;
            line-height: 1.3;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-expired {
            background: #f8d7da;
            color: #721c24;
        }

        .status-accepted {
            background: #cce7ff;
            color: #004085;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-issued {
            background: #e2e3e5;
            color: #383d41;
        }

        .card-info {
            flex-grow: 1;
            margin-bottom: 1.2rem;
        }

        .info-row {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 0.6rem;
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .info-row i {
            color: #6c757d;
            width: 20px;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .info-row strong {
            color: #0077b6;
            font-weight: 600;
            min-width: 80px;
        }

        .info-row .value {
            color: #495057;
            font-weight: 500;
        }

        .card-actions {
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
            margin-top: auto;
            padding-top: 1rem;
            border-top: 1px solid #f1f3f4;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            border-radius: 6px;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid;
        }

        .btn-outline-primary {
            border-color: #0077b6;
            color: #0077b6;
        }

        .btn-outline-primary:hover {
            background: #0077b6;
            color: white;
        }

        .btn-outline-success {
            border-color: #28a745;
            color: #28a745;
        }

        .btn-outline-success:hover {
            background: #28a745;
            color: white;
        }

        .btn-outline-secondary {
            border-color: #6c757d;
            color: #6c757d;
        }

        .btn-outline-secondary:hover {
            background: #6c757d;
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .no-results-message {
            grid-column: 1 / -1;
        }

        .no-results-message .empty-state {
            background: #f8f9fa;
            border-radius: 12px;
            border: 2px dashed #dee2e6;
            margin: 1rem 0;
        }

        .no-results-message .empty-state i {
            color: #6c757d;
        }

        .no-results-message .empty-state h3 {
            color: #495057;
            margin-bottom: 0.5rem;
        }

        .no-results-message .empty-state p {
            color: #6c757d;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: stretch;
            }

            .action-buttons {
                justify-content: center;
            }

            .card-grid {
                grid-template-columns: 1fr;
            }

            .btn .hide-on-mobile {
                display: none;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                justify-content: stretch;
            }

            .btn-filter {
                flex: 1;
            }
        }
    </style>
</head>

<body>
    <?php
    // Tell the sidebar which menu item to highlight
    $activePage = 'referrals';
    include '../../../includes/sidebar_patient.php';
    ?>

    <section class="content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb" style="margin-top: 50px;">
            <a href="../dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <span> / </span>
            <span style="color: #0077b6; font-weight: 600;">Medical Referrals</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-file-medical" style="margin-right: 0.5rem;"></i>Medical Referrals</h1>
            <div class="action-buttons">
                <a href="../appointment/appointments.php" class="btn btn-secondary">
                    <i class="fas fa-calendar-check"></i>
                    <span class="hide-on-mobile">View Appointments</span>
                </a>
                <button class="btn btn-primary" onclick="downloadReferralHistory()">
                    <i class="fas fa-file-export"></i>
                    <span class="hide-on-mobile">Export All</span>
                </button>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Referrals Section -->
        <div class="section-container">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-file-medical"></i>
                </div>
                <h2 class="section-title">Referral History</h2>
                <div style="margin-left: auto; color: #6c757d; font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i> Showing recent 30 referrals
                </div>
            </div>

            <!-- Search and Filter Section -->
            <div class="search-filters">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="referral-search">Search Referrals</label>
                        <input type="text" id="referral-search" placeholder="Search by facility, service, or referral ID..." 
                               onkeypress="handleSearchKeyPress(event, 'referral')">
                    </div>
                    <div class="filter-group">
                        <label for="referral-date-from">Date From</label>
                        <input type="date" id="referral-date-from">
                    </div>
                    <div class="filter-group">
                        <label for="referral-date-to">Date To</label>
                        <input type="date" id="referral-date-to">
                    </div>
                    <div class="filter-group">
                        <label for="referral-status-filter">Status</label>
                        <select id="referral-status-filter">
                            <option value="">All Statuses</option>
                            <option value="active">Active</option>
                            <option value="accepted">Used</option>
                            <option value="issued">Issued</option>
                            <option value="expired">Expired</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <div class="filter-actions">
                            <button type="button" class="btn-filter btn-filter-primary" onclick="filterReferralsBySearch()">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <button type="button" class="btn-filter btn-filter-secondary" onclick="clearReferralFilters()">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <div class="filter-tab active" onclick="filterReferrals('all', this)">
                    <i class="fas fa-list"></i> All Referrals
                </div>
                <div class="filter-tab" onclick="filterReferrals('active', this)">
                    <i class="fas fa-check-circle"></i> Active
                </div>
                <div class="filter-tab" onclick="filterReferrals('accepted', this)">
                    <i class="fas fa-calendar-check"></i> Used
                </div>
                <div class="filter-tab" onclick="filterReferrals('expired', this)">
                    <i class="fas fa-times-circle"></i> Expired
                </div>
            </div>

            <!-- Referrals Grid -->
            <div class="card-grid" id="referrals-grid">
                <?php if (empty($referrals)): ?>
                    <div class="empty-state" style="grid-column: 1 / -1;">
                        <i class="fas fa-file-medical"></i>
                        <h3>No Medical Referrals Found</h3>
                        <p>You don't have any medical referrals yet. Referrals will appear here when doctors refer you to other facilities or services.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($referrals as $referral): ?>
                        <div class="referral-card" data-status="<?php echo htmlspecialchars($referral['status']); ?>" data-referral-date="<?php echo htmlspecialchars($referral['referral_date']); ?>">
                            <div class="card-header">
                                <h4 class="card-title"><?php echo htmlspecialchars($referral['referral_num']); ?></h4>
                                <span class="status-badge status-<?php echo htmlspecialchars($referral['status']); ?>">
                                    <?php echo htmlspecialchars(strtoupper($referral['status'])); ?>
                                </span>
                            </div>
                            
                            <div class="card-info">
                                <div class="info-row">
                                    <i class="fas fa-hospital"></i>
                                    <strong>Facility:</strong>
                                    <span class="value">
                                        <?php 
                                        if (!empty($referral['facility_name'])) {
                                            echo htmlspecialchars($referral['facility_name']) . ' (' . htmlspecialchars($referral['facility_type']) . ')';
                                        } else {
                                            echo htmlspecialchars($referral['external_facility_name'] ?? 'External Facility');
                                        }
                                        ?>
                                    </span>
                                </div>
                                
                                <?php if (!empty($referral['service_name'])): ?>
                                <div class="info-row">
                                    <i class="fas fa-stethoscope"></i>
                                    <strong>Service:</strong>
                                    <span class="value"><?php echo htmlspecialchars($referral['service_name']); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <div class="info-row">
                                    <i class="fas fa-calendar"></i>
                                    <strong>Date:</strong>
                                    <span class="value"><?php echo date('M j, Y', strtotime($referral['referral_date'])); ?></span>
                                </div>
                                
                                <?php if (!empty($referral['doctor_first_name'])): ?>
                                <div class="info-row">
                                    <i class="fas fa-user-md"></i>
                                    <strong>Doctor:</strong>
                                    <span class="value">Dr. <?php echo htmlspecialchars($referral['doctor_first_name'] . ' ' . $referral['doctor_last_name']); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($referral['referral_reason'])): ?>
                                <div class="info-row">
                                    <i class="fas fa-clipboard"></i>
                                    <strong>Reason:</strong>
                                    <span class="value"><?php echo htmlspecialchars($referral['referral_reason']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="card-actions">
                                <button type="button" class="btn btn-outline btn-outline-primary btn-sm" onclick="viewReferralDetails(<?php echo $referral['referral_id']; ?>)">
                                    <i class="fas fa-eye"></i> View Details
                                </button>
                                
                                <?php if ($referral['status'] === 'active'): ?>
                                    <button type="button" class="btn btn-outline btn-outline-success btn-sm" onclick="bookFromReferral(<?php echo $referral['referral_id']; ?>)">
                                        <i class="fas fa-calendar-plus"></i> Book Appointment
                                    </button>
                                <?php endif; ?>
                                
                                <button type="button" class="btn btn-outline btn-outline-secondary btn-sm" onclick="printReferral(<?php echo $referral['referral_id']; ?>)">
                                    <i class="fas fa-print"></i> Print
                                </button>
                                
                                <!-- <button type="button" class="btn btn-outline btn-outline-success btn-sm" onclick="downloadReferral(<?php /*echo $referral['referral_id']; */?>)">
                                    <i class="fas fa-download"></i> Download
                                </button> -->
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- View Referral Modal (Copied from Admin) -->
    <div id="viewReferralModal" class="referral-confirmation-modal">
        <div class="referral-modal-content">
            <div class="referral-modal-header">
                <button type="button" class="referral-modal-close" onclick="closeReferralModal()">&times;</button>
                <div class="icon">
                    <i class="fas fa-eye"></i>
                </div>
                <h3>Referral Details</h3>
                <p class="modal-description">Complete information about this referral</p>
            </div>

            <div class="referral-modal-body">
                <div id="referralDetailsContent">
                    <!-- Content will be loaded via JavaScript -->
                    <div class="modal-loading">
                        <i class="fas fa-spinner fa-spin fa-2x"></i>
                        <p>Loading referral details...</p>
                    </div>
                </div>
            </div>

            <div class="referral-modal-actions">
                <!-- Print Button - Always available -->
                <button type="button" class="modal-btn modal-btn-primary" onclick="printReferral(currentReferralId)" id="printReferralBtn">
                    <i class="fas fa-print"></i> Print
                </button>

                <!-- Download Button - Always available -->
                <button type="button" class="modal-btn modal-btn-success" onclick="downloadReferral(currentReferralId)" id="downloadReferralBtn">
                    <i class="fas fa-download"></i> Download PDF
                </button>
            </div>
        </div>
    </div>

    <script>
        // Filter functionality for referrals
        function filterReferrals(status, clickedElement) {
            // Remove active class from all tabs
            const referralTabs = document.querySelectorAll('.filter-tab');
            referralTabs.forEach(tab => {
                tab.classList.remove('active');
            });

            // Add active class to clicked tab
            if (clickedElement) {
                clickedElement.classList.add('active');
            }

            // Remove any existing no-results messages
            const referralsGrid = document.getElementById('referrals-grid');
            const existingNoResults = referralsGrid.querySelector('.no-results-message');
            if (existingNoResults) {
                existingNoResults.remove();
            }

            // Show/hide referral cards based on status
            const referrals = document.querySelectorAll('#referrals-grid .referral-card');
            let visibleCount = 0;

            referrals.forEach(card => {
                if (status === 'all' || card.dataset.status === status) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            // Show no results message if no referrals match the filter
            if (visibleCount === 0 && referrals.length > 0) {
                const noResultsDiv = document.createElement('div');
                noResultsDiv.className = 'no-results-message';
                noResultsDiv.style.gridColumn = '1 / -1';
                noResultsDiv.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-filter"></i>
                        <h3>No matching referrals</h3>
                        <p>No referrals match the selected filter. Try selecting a different status.</p>
                        <button type="button" class="btn btn-outline-secondary" onclick="filterReferrals('all', document.querySelector('.filter-tab'))">
                            <i class="fas fa-list"></i> Show All Referrals
                        </button>
                    </div>
                `;
                referralsGrid.appendChild(noResultsDiv);
            }
        }

        // Handle Enter key press in search fields
        function handleSearchKeyPress(event, type) {
            if (event.key === 'Enter') {
                if (type === 'referral') {
                    filterReferralsBySearch();
                }
            }
        }

        // Advanced filter functionality for referrals
        function filterReferralsBySearch() {
            const searchTerm = document.getElementById('referral-search').value.toLowerCase();
            const dateFrom = document.getElementById('referral-date-from').value;
            const dateTo = document.getElementById('referral-date-to').value;
            const statusFilter = document.getElementById('referral-status-filter').value;

            const referrals = document.querySelectorAll('#referrals-grid .referral-card');
            const referralsGrid = document.getElementById('referrals-grid');
            let visibleCount = 0;

            // Remove existing no-results message
            const existingNoResults = referralsGrid.querySelector('.no-results-message');
            if (existingNoResults) {
                existingNoResults.remove();
            }

            referrals.forEach(card => {
                let shouldShow = true;

                // Text search
                if (searchTerm) {
                    const cardText = card.textContent.toLowerCase();
                    if (!cardText.includes(searchTerm)) {
                        shouldShow = false;
                    }
                }

                // Date range filter
                if (dateFrom || dateTo) {
                    const cardDateElement = card.querySelector('[data-referral-date]');
                    if (cardDateElement) {
                        const cardDate = cardDateElement.getAttribute('data-referral-date');
                        if (dateFrom && cardDate < dateFrom) shouldShow = false;
                        if (dateTo && cardDate > dateTo) shouldShow = false;
                    }
                }

                // Status filter
                if (statusFilter && card.dataset.status !== statusFilter) {
                    shouldShow = false;
                }

                card.style.display = shouldShow ? 'block' : 'none';
                if (shouldShow) visibleCount++;
            });

            // Show no results message if no referrals match the filter
            if (visibleCount === 0 && referrals.length > 0) {
                const noResultsDiv = document.createElement('div');
                noResultsDiv.className = 'no-results-message';
                noResultsDiv.style.gridColumn = '1 / -1';
                noResultsDiv.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <h3>No matching referrals found</h3>
                        <p>No referrals match your search criteria. Try adjusting your filters.</p>
                        <button type="button" class="btn btn-outline-secondary" onclick="clearReferralFilters()">
                            <i class="fas fa-times"></i> Clear Filters
                        </button>
                    </div>
                `;
                referralsGrid.appendChild(noResultsDiv);
            }

            // Clear tab selections when using search
            if (searchTerm || dateFrom || dateTo || statusFilter) {
                const referralTabs = document.querySelectorAll('.filter-tab');
                referralTabs.forEach(tab => {
                    tab.classList.remove('active');
                });
            }
        }

        // Clear referral filters
        function clearReferralFilters() {
            document.getElementById('referral-search').value = '';
            document.getElementById('referral-date-from').value = '';
            document.getElementById('referral-date-to').value = '';
            document.getElementById('referral-status-filter').value = '';

            // Remove no-results message if it exists
            const referralsGrid = document.getElementById('referrals-grid');
            const existingNoResults = referralsGrid.querySelector('.no-results-message');
            if (existingNoResults) {
                existingNoResults.remove();
            }

            // Show all referrals
            const referrals = document.querySelectorAll('#referrals-grid .referral-card');
            referrals.forEach(card => {
                card.style.display = 'block';
            });

            // Reset to "All Referrals" tab
            const referralTabs = document.querySelectorAll('.filter-tab');
            referralTabs.forEach((tab, index) => {
                if (index === 0) {
                    tab.classList.add('active');
                } else {
                    tab.classList.remove('active');
                }
            });
        }

        // View Referral Function (Copied from Admin) 
        let currentReferralId = null;

        async function viewReferralDetails(referralId) {
            console.log('=== VIEW REFERRAL DEBUG START ===');
            console.log('Referral ID:', referralId);

            try {
                const response = await fetch(`../../../api/patient_referral_details.php?referral_id=${referralId}`);
                console.log('Response status:', response.status);
                console.log('Response headers:', Object.fromEntries(response.headers.entries()));

                const text = await response.text();
                console.log('Raw response:', text);

                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    console.error('JSON parse error:', parseError);
                    showNotification('Invalid response format from server', 'error');
                    return;
                }

                console.log('Parsed data:', data);

                if (data.success && data.referral) {
                    console.log('Success! Referral data received:', data.referral);
                    currentReferralId = referralId; // Set current referral ID for modal actions
                    populateReferralModal(data.referral);
                    openModal('viewReferralModal');
                } else {
                    console.error('API Error:', data.error || 'Unknown error');
                    showNotification(data.error || 'Failed to load referral details', 'error');
                }
            } catch (error) {
                console.error('Fetch error:', error);
                showNotification('Network error: ' + error.message, 'error');
            }

            console.log('=== VIEW REFERRAL DEBUG END ===');
        }

        // Book appointment from referral
        function bookFromReferral(referralId) {
            // Check if booking page exists, otherwise show message
            if (confirm('Navigate to appointment booking with this referral?')) {
                const bookingUrl = '../appointment/book_appointment.php?referral_id=' + referralId;
                window.location.href = bookingUrl;
            }
        }

        // Print Referral Function (Using HTML Print View)
        function printReferral(referralId) {
            console.log('Print referral:', referralId);

            if (!referralId) {
                showNotification('Invalid referral ID', 'error');
                return;
            }

            // Open print view in popup window
            const printUrl = `../../../api/patient_referral_print.php?referral_id=${referralId}`;
            const popup = window.open(
                printUrl,
                'referralPrint',
                'width=800,height=900,scrollbars=yes,resizable=yes,toolbar=no,menubar=no,location=no'
            );

            if (popup) {
                popup.focus();
                showNotification('Print view opened. Use Ctrl+P to print or click the Print button.', 'success');
            } else {
                showNotification('Please allow popups to view print preview', 'error');
            }
        }

        // Download individual referral as PDF
        function downloadReferral(referralId) {
            console.log('Download referral:', referralId);

            if (!referralId) {
                showNotification('Invalid referral ID', 'error');
                return;
            }

            try {
                // Open print view in popup window (users can save as PDF from browser)
                const printUrl = `../../../api/patient_referral_print.php?referral_id=${referralId}`;
                const popup = window.open(
                    printUrl,
                    'referralDownload',
                    'width=800,height=900,scrollbars=yes,resizable=yes,toolbar=no,menubar=no,location=no'
                );

                if (popup) {
                    popup.focus();
                    showNotification('Print view opened. Use Ctrl+P to print or save as PDF from browser.', 'success');
                } else {
                    showNotification('Please allow popups to view print preview', 'error');
                }
            } catch (error) {
                console.error('Download error:', error);
                showNotification('Download failed: ' + error.message, 'error');
            }
        }

        // Export all referrals as CSV
        function downloadReferralHistory() {
            console.log('Exporting all referrals as CSV...');

            try {
                // Create download link for CSV format
                const downloadUrl = '../../../api/download_referral_history.php?format=csv';
                const downloadLink = document.createElement('a');
                downloadLink.href = downloadUrl;
                downloadLink.download = 'referral_history.csv';
                downloadLink.style.display = 'none';
                
                document.body.appendChild(downloadLink);
                downloadLink.click();
                document.body.removeChild(downloadLink);

                showNotification('CSV export started', 'success');
            } catch (error) {
                console.error('Download error:', error);
                showNotification('Download failed: ' + error.message, 'error');
            }
        }

        // Modal Management Functions (Copied from Admin)
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';

                // Add show class for referral confirmation modal
                if (modalId === 'viewReferralModal') {
                    modal.classList.add('show');
                }
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';

                // Remove show class for referral confirmation modal
                if (modalId === 'viewReferralModal') {
                    modal.classList.remove('show');
                }

                // Reset form if exists
                const form = modal.querySelector('form');
                if (form) form.reset();
            }
        }

        // Alias for referral modal close
        function closeReferralModal() {
            closeModal('viewReferralModal');
        }

        // Populate Referral Modal (Copied from Admin)
        function populateReferralModal(referral) {
            console.log('Populating modal with referral:', referral);

            const modalBody = document.getElementById('referralDetailsContent');
            if (!modalBody) {
                console.error('Modal body not found');
                return;
            }

            let vitalsSection = '';
            if (referral.vitals && Object.keys(referral.vitals).length > 0) {
                const vitals = referral.vitals;
                vitalsSection = `
                    <div class="summary-section">
                        <div class="summary-title">
                            <i class="fas fa-heartbeat"></i>
                            Vital Signs
                        </div>
                        <div class="vitals-summary">
                            ${vitals.blood_pressure ? `<div class="vital-item"><div class="vital-value">${vitals.blood_pressure}</div><div class="vital-label">Blood Pressure</div></div>` : ''}
                            ${vitals.temperature ? `<div class="vital-item"><div class="vital-value">${vitals.temperature}°C</div><div class="vital-label">Temperature</div></div>` : ''}
                            ${vitals.heart_rate ? `<div class="vital-item"><div class="vital-value">${vitals.heart_rate} bpm</div><div class="vital-label">Heart Rate</div></div>` : ''}
                            ${vitals.respiratory_rate ? `<div class="vital-item"><div class="vital-value">${vitals.respiratory_rate}/min</div><div class="vital-label">Respiratory Rate</div></div>` : ''}
                            ${vitals.weight ? `<div class="vital-item"><div class="vital-value">${vitals.weight} kg</div><div class="vital-label">Weight</div></div>` : ''}
                            ${vitals.height ? `<div class="vital-item"><div class="vital-value">${vitals.height} cm</div><div class="vital-label">Height</div></div>` : ''}
                        </div>
                    </div>
                `;
            }

            const modalContent = `
                <div class="referral-summary-card">
                    <div class="summary-section">
                        <div class="summary-title">
                            <i class="fas fa-user"></i>
                            Patient Information
                        </div>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="summary-label">Full Name</div>
                                <div class="summary-value highlight">${referral.patient_name || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Patient ID</div>
                                <div class="summary-value">${referral.patient_number || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Age</div>
                                <div class="summary-value">${referral.age || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Gender</div>
                                <div class="summary-value">${referral.gender || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Barangay</div>
                                <div class="summary-value">${referral.barangay || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Contact Number</div>
                                <div class="summary-value">${referral.contact_number || 'N/A'}</div>
                            </div>
                        </div>
                    </div>

                    <div class="summary-section">
                        <div class="summary-title">
                            <i class="fas fa-share"></i>
                            Referral Details
                        </div>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="summary-label">Referral Number</div>
                                <div class="summary-value highlight">${referral.referral_num || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Status</div>
                                <div class="summary-value">${referral.status || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Referred To</div>
                                <div class="summary-value">${referral.facility_name || referral.external_facility_name || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Date Issued</div>
                                <div class="summary-value">${formatDate(referral.referral_date) || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Issued By</div>
                                <div class="summary-value">${referral.issuer_name || 'N/A'}</div>
                            </div>
                        </div>
                        <div class="summary-item" style="margin-top: 1rem;">
                            <div class="summary-label">Reason for Referral</div>
                            <div class="summary-value reason">${referral.referral_reason || 'N/A'}</div>
                        </div>
                    </div>

                    ${vitalsSection}
                </div>
            `;

            modalBody.innerHTML = modalContent;
        }

        // Utility function for date formatting
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleString('en-PH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // Custom notification system
        function showNotification(message, type = 'info') {
            // Remove existing notifications
            document.querySelectorAll('.notification-snackbar').forEach(n => n.remove());

            const notification = document.createElement('div');
            notification.className = `notification-snackbar notification-${type}`;

            const icon = type === 'success' ? 'fas fa-check-circle' :
                type === 'error' ? 'fas fa-exclamation-circle' :
                type === 'warning' ? 'fas fa-exclamation-triangle' : 'fas fa-info-circle';

            notification.innerHTML = `
                <i class="${icon}"></i>
                <span>${message}</span>
                <button type="button" class="notification-close" onclick="this.parentElement.remove();">
                    <i class="fas fa-times"></i>
                </button>
            `;

            // Style the notification
            Object.assign(notification.style, {
                position: 'fixed',
                top: '20px',
                right: '20px',
                padding: '1rem 1.5rem',
                borderRadius: '8px',
                color: 'white',
                fontWeight: '500',
                zIndex: '9999',
                display: 'flex',
                alignItems: 'center',
                gap: '0.75rem',
                minWidth: '300px',
                maxWidth: '500px',
                boxShadow: '0 4px 12px rgba(0, 0, 0, 0.2)',
                animation: 'slideInRight 0.3s ease'
            });

            // Set background color
            const colors = {
                success: '#28a745',
                error: '#dc3545',
                warning: '#ffc107',
                info: '#17a2b8'
            };
            notification.style.background = colors[type] || colors.info;

            document.body.appendChild(notification);

            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.style.animation = 'slideOutRight 0.3s ease';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Close modals when clicking outside (Admin style)
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal, .referral-confirmation-modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    const modalId = modal.id;
                    closeModal(modalId);
                }
            });
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Set default active filter
            const referralTabs = document.querySelectorAll('.filter-tab');
            if (referralTabs.length > 0) {
                referralTabs[0].classList.add('active');
            }

            // Add smooth transitions
            document.querySelectorAll('.referral-card').forEach(card => {
                card.style.transition = 'all 0.3s ease';
            });
        });
    </script>

    <!-- Alert Styles -->
    <style>
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background-color: #d1f2eb;
            color: #0d5e3d;
            border: 1px solid #7fb069;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f1b2b7;
        }

        .alert i {
            font-size: 1.1rem;
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-content-small {
            background: white;
            border-radius: 12px;
            max-width: 400px;
            width: 100%;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        .modal-content-large {
            background: white;
            border-radius: 12px;
            max-width: 800px;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.2rem;
            transition: background 0.3s;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .modal-body {
            padding: 2rem;
            flex: 1;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 1rem 2rem;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .referral-summary {
            font-size: 0.95rem;
        }

        .summary-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f8f9fa;
        }

        .summary-header h4 {
            margin: 0;
            color: #0077b6;
            font-size: 1.4rem;
        }

        .details-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .details-section h4 {
            color: #0077b6;
            margin: 0 0 1rem 0;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .details-section h4 i {
            background: #e3f2fd;
            padding: 0.4rem;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 0.75rem;
        }

        .detail-grid > div {
            padding: 0.5rem 0;
        }

        .vitals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.75rem;
        }

        .vital-item {
            background: white;
            padding: 0.75rem;
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }

        /* Notification Styles */
        .notification-snackbar {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 300px;
            max-width: 500px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .notification-success { background: #28a745; }
        .notification-error { background: #dc3545; }
        .notification-warning { background: #ffc107; color: #212529; }
        .notification-info { background: #17a2b8; }

        .notification-close {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            padding: 0.25rem;
            margin-left: auto;
            border-radius: 3px;
        }

        .notification-close:hover {
            opacity: 0.8;
        }

        /* Admin Modal Styles (Copied from referrals_management.php) */
        .referral-confirmation-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .referral-confirmation-modal.show {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        .referral-modal-content {
            background: white;
            border-radius: 20px;
            max-width: 800px;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            animation: slideInUp 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .referral-modal-header {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 2rem;
            text-align: center;
            position: relative;
        }

        .referral-modal-close {
            position: absolute;
            top: 15px;
            right: 20px;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.5rem;
            transition: background 0.3s;
        }

        .referral-modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .referral-modal-header .icon {
            background: rgba(255, 255, 255, 0.2);
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 2rem;
        }

        .referral-modal-header h3 {
            margin: 0 0 0.5rem 0;
            font-size: 1.8rem;
            font-weight: 600;
        }

        .modal-description {
            margin: 0;
            opacity: 0.9;
            font-size: 1rem;
        }

        .referral-modal-body {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
        }

        .referral-summary-card {
            background: #f8f9fa;
            border-radius: 15px;
            overflow: hidden;
        }

        .summary-section {
            background: white;
            margin-bottom: 1.5rem;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .summary-title {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 1rem 1.5rem;
            font-weight: 600;
            color: #0077b6;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .summary-title i {
            background: #e3f2fd;
            padding: 0.5rem;
            border-radius: 8px;
            font-size: 1.1rem;
        }

        .summary-grid {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .summary-item {
            padding: 0.75rem 0;
        }

        .summary-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .summary-value {
            color: #212529;
            font-size: 1rem;
            word-wrap: break-word;
        }

        .summary-value.highlight {
            color: #0077b6;
            font-weight: 600;
        }

        .summary-value.reason {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #0077b6;
            font-style: italic;
            margin-top: 0.5rem;
        }

        .vitals-summary {
            padding: 1.5rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .vital-item {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 10px;
            text-align: center;
            border: 2px solid #e9ecef;
        }

        .vital-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0077b6;
            margin-bottom: 0.25rem;
        }

        .vital-label {
            font-size: 0.85rem;
            color: #6c757d;
            font-weight: 500;
        }

        .referral-modal-actions {
            padding: 1.5rem 2rem;
            background: #f8f9fa;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .modal-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .modal-btn-secondary {
            background: #6c757d;
            color: white;
        }

        .modal-btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        .modal-btn-primary {
            background: #0077b6;
            color: white;
        }

        .modal-btn-primary:hover {
            background: #005f91;
            transform: translateY(-2px);
        }

        .modal-btn-success {
            background: #28a745;
            color: white;
        }

        .modal-btn-success:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .modal-loading {
            text-align: center;
            padding: 3rem;
        }

        /* Animations */
        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(100%); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes slideOutRight {
            from { opacity: 1; transform: translateX(0); }
            to { opacity: 0; transform: translateX(100%); }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .modal-content-large {
                margin: 0.5rem;
                max-height: 95vh;
            }

            .modal-header {
                padding: 1rem 1.5rem;
            }

            .modal-body {
                padding: 1.5rem;
            }

            .modal-footer {
                padding: 1rem 1.5rem;
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .vitals-grid {
                grid-template-columns: 1fr;
            }

            .notification-snackbar {
                left: 1rem;
                right: 1rem;
                min-width: auto;
            }
        }
    </style>

</body>

</html>