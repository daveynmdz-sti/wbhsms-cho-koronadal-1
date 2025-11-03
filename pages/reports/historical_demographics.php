<?php
// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';
require_once $root_path . '/utils/HistoricalDemographicsService.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header("Location: ../management/auth/employee_login.php");
    exit();
}

// Set active page for sidebar highlighting
$activePage = 'reports';

// Define role-based permissions
$allowedRoles = ['admin', 'dho', 'records_officer'];
$userRole = $_SESSION['role'] ?? '';

if (!in_array($userRole, $allowedRoles)) {
    $role = strtolower($userRole);
    header("Location: ../management/$role/dashboard.php");
    exit();
}

// Initialize service
$service = new HistoricalDemographicsService($conn, $pdo);

// Get list of snapshots - handle missing tables gracefully
$snapshots = [];
$tablesExist = true;

// Initialize variables
$message = '';
$error = '';

try {
    $snapshots = $service->getSnapshotsList(100);
} catch (Exception $e) {
    $tablesExist = false;
    $error = "Historical demographics tables are not set up yet.";
    error_log("Historical demographics error: " . $e->getMessage());
}

// Handle setup messages from URL parameters
if (isset($_GET['setup'])) {
    switch ($_GET['setup']) {
        case 'success':
            $message = 'Historical demographics tables created successfully!';
            $tablesExist = true;
            $error = ''; // Clear any previous error
            // Refresh snapshots list
            try {
                $snapshots = $service->getSnapshotsList(100);
            } catch (Exception $e) {
                // Still having issues
            }
            break;
        case 'error':
            $error = $_GET['message'] ?? 'Failed to create historical demographics tables';
            $message = ''; // Clear any previous message
            break;
        case 'exists':
            $message = 'Historical demographics tables already exist';
            $tablesExist = true;
            $error = ''; // Clear any previous error
            break;
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'generate_snapshot':
            $type = $_POST['type'] ?? 'manual';
            $notes = $_POST['notes'] ?? '';
            $result = $service->generateSnapshot($type, $notes, $_SESSION['employee_id']);

            if ($result['success']) {
                $message = $result['message'];
                // Refresh snapshots list
                $snapshots = $service->getSnapshotsList(100);
            } else {
                $error = $result['error'];
            }
            break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historical Demographics - CHO Koronadal</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">

    <!-- CSS Assets -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/sidebar.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* Page-specific styles */
        :root {
            --primary-color: #0077b6;
            --primary-dark: #03045e;
            --secondary-color: #00b4d8;
            --accent-color: #90e0ef;
            --success-color: #06d6a0;
            --warning-color: #ffd60a;
            --danger-color: #f72585;
            --text-dark: #2d3436;
            --text-light: #636e72;
            --background-light: #ffffff;
            --border-color: #ddd;
            --shadow-light: 0 2px 10px rgba(0, 0, 0, 0.1);
            --shadow-heavy: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .homepage {
            margin-left: 300px;
        }

        .content-wrapper {
            padding: 2rem;
            transition: margin-left 0.3s;
        }

        /* Breadcrumb styling */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            color: var(--text-light);
        }

        .breadcrumb a {
            color: var(--primary-color);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .breadcrumb a:hover {
            color: var(--primary-dark);
        }

        .breadcrumb i {
            font-size: 12px;
        }

        /* Page header */
        .page-header {
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--accent-color);
        }

        .page-header h1 {
            color: #0077b6;
            margin: 0;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-header h1 i {
            color: var(--primary-color);
        }

        .page-header p {
            color: var(--text-light);
            margin: 8px 0 0 0;
            font-size: 16px;
        }

        /* Alert styles */
        .alert {
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            box-shadow: var(--shadow-light);
        }

        .alert i {
            font-size: 16px;
        }

        .alert-success {
            background-color: #d1e7dd;
            color: #0f5132;
            border: 1px solid #badbcc;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c2c7;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #055160;
            border: 1px solid #b8daff;
        }

        .alert-warning {
            background-color: #fff3cd;
            color: #664d03;
            border: 1px solid #ffecb5;
        }

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        .btn-success {
            background: var(--success-color);
            color: white;
        }

        .btn-success:hover {
            background: #05b48a;
        }

        .btn-warning {
            background: var(--warning-color);
            color: #664d03;
        }

        .btn-warning:hover {
            background: #e6c200;
        }

        .btn-danger {
            background: var(--danger-color);
            color: white;
        }

        .btn-danger:hover {
            background: #d61355;
        }

        /* Snapshots table */
        .snapshots-section {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .snapshots-section h3 {
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .snapshots-section h3 i {
            color: var(--primary-color);
        }

        .snapshots-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .snapshots-table th,
        .snapshots-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .snapshots-table th {
            background: #f8f9fa;
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 14px;
        }

        .snapshots-table td {
            color: var(--text-dark);
            font-size: 14px;
        }

        .snapshots-table tr:hover {
            background: #f8f9fa;
        }

        /* Badge styles */
        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-quarterly {
            background: #e3f2fd;
            color: #1565c0;
        }

        .badge-annual {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .badge-manual {
            background: #e8f5e8;
            color: #2e7d32;
        }

        .badge-semi-annual {
            background: #fff3e0;
            color: #ef6c00;
        }

        /* Comparison section */
        .comparison-section {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .comparison-form {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 15px;
            align-items: end;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 5px;
            font-size: 14px;
        }

        .form-group select {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            background: white;
        }

        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(0, 119, 182, 0.1);
        }

        /* Comparison results */
        .comparison-results {
            display: none;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }

        .comparison-results.active {
            display: block;
        }

        .comparison-header {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .snapshot-info {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }

        .snapshot-info h4 {
            margin: 0 0 10px 0;
            color: var(--primary-dark);
            font-size: 16px;
        }

        .snapshot-info p {
            margin: 5px 0;
            font-size: 14px;
            color: var(--text-light);
        }

        /* Change indicators */
        .change-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
            font-size: 13px;
        }

        .change-indicator.increase {
            color: var(--success-color);
        }

        .change-indicator.decrease {
            color: var(--danger-color);
        }

        .change-indicator.no-change {
            color: var(--text-light);
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(3px);
        }

        .modal-content {
            background-color: var(--background-light);
            margin: 5% auto;
            padding: 0;
            border-radius: 12px;
            box-shadow: var(--shadow-heavy);
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 12px 12px 0 0;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
            opacity: 0.8;
            transition: opacity 0.3s ease;
        }

        .close:hover {
            opacity: 1;
        }

        .modal-body {
            padding: 25px;
            overflow-y: auto;
            flex: 1;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-dark);
        }

        .form-group select,
        .form-group textarea,
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-group select:focus,
        .form-group textarea:focus,
        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(0, 119, 182, 0.1);
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .homepage {
                margin-left: 0;
            }

            .content-wrapper {
                padding: 1rem;
            }

            .comparison-form {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .comparison-header {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                justify-content: center;
            }
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }

        .empty-state i {
            font-size: 48px;
            color: var(--accent-color);
            margin-bottom: 15px;
            display: block;
        }

        .empty-state h4 {
            margin: 0 0 10px 0;
            color: var(--text-dark);
        }

        .empty-state p {
            margin: 0;
            font-size: 14px;
        }
    </style>
</head>

<body>
    <!-- Include admin sidebar -->
    <?php include '../../includes/sidebar_admin.php'; ?>

    <section class="homepage">
        <div class="content-wrapper">
            <!-- Breadcrumb Navigation -->
            <div class="breadcrumb" style="margin-top: 50px;">
                <a href="../management/<?= strtolower($userRole) ?>/dashboard.php">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <i class="fas fa-chevron-right"></i>
                <a href="patient_demographics.php">
                    <i class="fas fa-chart-bar"></i> Patient Demographics
                </a>
                <i class="fas fa-chevron-right"></i>
                <span>Historical Data</span>
            </div>

            <div class="page-header">
                <h1><i class="fas fa-history"></i> Historical Demographics</h1>
                <p>Manage and compare patient demographics snapshots over time with comprehensive data analysis</p>
            </div>

            <!-- COMPREHENSIVE EXPLANATION SECTION -->
            <div class="explanation-section" style="background: #f8f9ff; border: 1px solid #e3e7ff; border-radius: 12px; padding: 25px; margin-bottom: 25px;">
                <h3 style="color: #0077b6; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-info-circle"></i> Understanding Demographic Snapshots
                </h3>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 20px;">
                    <div style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #28a745;">
                        <h4 style="color: #28a745; margin-bottom: 12px;"><i class="fas fa-calendar-week"></i> Quarterly Snapshots</h4>
                        <p style="margin-bottom: 8px; color: #555; font-size: 14px; line-height: 1.5;">
                            <strong>Purpose:</strong> Track short-term demographic changes every 3 months<br>
                            <strong>Ideal for:</strong> Monitoring seasonal population shifts, program impacts<br>
                            <strong>Data captured:</strong> All patient demographics + comprehensive cross-tabulations by district and barangay
                        </p>
                    </div>
                    
                    <div style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #ffc107;">
                        <h4 style="color: #ffc107; margin-bottom: 12px;"><i class="fas fa-calendar-alt"></i> Semi-Annual Snapshots</h4>
                        <p style="margin-bottom: 8px; color: #555; font-size: 14px; line-height: 1.5;">
                            <strong>Purpose:</strong> Capture mid-year and year-end demographic trends<br>
                            <strong>Ideal for:</strong> Budget planning, resource allocation, mid-year reviews<br>
                            <strong>Data captured:</strong> Complete demographic analysis + geographic distributions + PhilHealth trends
                        </p>
                    </div>
                    
                    <div style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #dc3545;">
                        <h4 style="color: #dc3545; margin-bottom: 12px;"><i class="fas fa-calendar"></i> Annual Snapshots</h4>
                        <p style="margin-bottom: 8px; color: #555; font-size: 14px; line-height: 1.5;">
                            <strong>Purpose:</strong> Long-term trend analysis and year-over-year comparison<br>
                            <strong>Ideal for:</strong> Annual reports, strategic planning, policy development<br>
                            <strong>Data captured:</strong> Full comprehensive demographics matching the complete Patient Demographics Report
                        </p>
                    </div>
                </div>

                <div style="background: #e8f5e8; padding: 15px; border-radius: 6px; border: 1px solid #c3e6c3;">
                    <h4 style="color: #155724; margin-bottom: 10px;"><i class="fas fa-chart-line"></i> What's Included in Every Snapshot:</h4>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 13px; color: #155724;">
                        <div>• Total patient count</div>
                        <div>• Age group distributions</div>
                        <div>• Gender distributions</div>
                        <div>• District distributions</div>
                        <div>• Barangay distributions</div>
                        <div>• PhilHealth membership</div>
                        <div>• PWD statistics</div>
                        <div>• Age by district breakdown</div>
                        <div>• Age by barangay breakdown</div>
                        <div>• Gender by district breakdown</div>
                        <div>• Gender by barangay breakdown</div>
                        <div>• PhilHealth by district/barangay</div>
                    </div>
                </div>

                <div style="margin-top: 15px; padding: 12px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px;">
                    <strong style="color: #856404;"><i class="fas fa-lightbulb"></i> Pro Tip:</strong> 
                    <span style="color: #856404; font-size: 14px;">All snapshots now capture the same comprehensive data as the full Patient Demographics Report - no more limited data! Compare any snapshot to see complete demographic trends over time.</span>
                </div>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if (!$tablesExist): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Setup Required:</strong> Historical demographics tables need to be created first.
                    <div style="margin-top: 10px;">
                        <a href="../../api/setup_historical_tables.php" class="btn btn-primary" style="margin-right: 10px;">
                            <i class="fas fa-magic"></i> Auto Setup
                        </a>
                        <a href="../../scripts/setup_historical_demographics.php" target="_blank" class="btn btn-secondary">
                            <i class="fas fa-wrench"></i> Manual Setup
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <a href="patient_demographics.php" class="btn btn-success">
                    <i class="fas fa-chevron-left"></i> Back to Patient Demographics Report
                </a>
                <button class="btn btn-primary" onclick="openGenerateModal()" <?= !$tablesExist ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' ?>>
                    <i class="fas fa-camera"></i> Generate Snapshot
                </button>
                <button class="btn btn-secondary" onclick="generateQuarterlySnapshot()" <?= !$tablesExist ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' ?>>
                    <i class="fas fa-calendar-alt"></i> Quarterly Snapshot
                </button>
                <button class="btn btn-warning" onclick="generateAnnualSnapshot()" <?= !$tablesExist ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' ?>>
                    <i class="fas fa-calendar"></i> Annual Snapshot
                </button>
            </div>

            <!-- Snapshots List -->
            <div class="snapshots-section">
                <h3><i class="fas fa-list"></i> Saved Snapshots</h3>
                
                <?php if (!$tablesExist): ?>
                    <div class="empty-state">
                        <i class="fas fa-database"></i>
                        <h4>Setup Required</h4>
                        <p>Historical demographics tables need to be created before you can use this feature.</p>
                        <div style="margin-top: 15px;">
                            <a href="../../api/setup_historical_tables.php" class="btn btn-primary" style="margin-right: 10px;">
                                <i class="fas fa-magic"></i> Auto Setup
                            </a>
                            <a href="../../scripts/setup_historical_demographics.php" target="_blank" class="btn btn-secondary">
                                <i class="fas fa-wrench"></i> Manual Setup
                            </a>
                        </div>
                    </div>
                <?php elseif (empty($snapshots)): ?>
                    <div class="empty-state">
                        <i class="fas fa-camera"></i>
                        <h4>No Snapshots Available</h4>
                        <p>Generate your first snapshot to start tracking historical demographics data</p>
                    </div>
                <?php else: ?>
                    <table class="snapshots-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Total Patients</th>
                                <th>Generated By</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($snapshots as $snapshot): ?>
                                <tr>
                                    <td><?= date('M j, Y', strtotime($snapshot['snapshot_date'])) ?></td>
                                    <td>
                                        <span class="badge badge-<?= $snapshot['snapshot_type'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $snapshot['snapshot_type'])) ?>
                                        </span>
                                    </td>
                                    <td><?= number_format($snapshot['total_patients']) ?></td>
                                    <td><?= htmlspecialchars($snapshot['generated_by_name'] ?: 'System') ?></td>
                                    <td><?= htmlspecialchars(substr($snapshot['notes'], 0, 50)) ?><?= strlen($snapshot['notes']) > 50 ? '...' : '' ?></td>
                                    <td>
                                        <button class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;"
                                                onclick="viewSnapshot(<?= $snapshot['snapshot_id'] ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;"
                                                onclick="selectForComparison(<?= $snapshot['snapshot_id'] ?>, '<?= $snapshot['snapshot_date'] ?>')">
                                            <i class="fas fa-balance-scale"></i>
                                        </button>
                                        <?php if ($userRole === 'admin'): ?>
                                            <button class="btn btn-danger" style="padding: 5px 10px; font-size: 12px;"
                                                    onclick="deleteSnapshot(<?= $snapshot['snapshot_id'] ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

                <!-- Comparison Section -->
                <?php if ($tablesExist && count($snapshots) >= 2): ?>
                    <div class="comparison-section">
                        <h3><i class="fas fa-balance-scale"></i> Compare Snapshots</h3>

                        <div class="comparison-form">
                            <div class="form-group">
                                <label for="snapshot1">First Snapshot</label>
                                <select id="snapshot1" name="snapshot1">
                                    <option value="">Select first snapshot...</option>
                                    <?php foreach ($snapshots as $snapshot): ?>
                                        <option value="<?= $snapshot['snapshot_id'] ?>">
                                            <?= date('M j, Y', strtotime($snapshot['snapshot_date'])) ?> -
                                            <?= ucfirst($snapshot['snapshot_type']) ?>
                                            (<?= number_format($snapshot['total_patients']) ?> patients)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="snapshot2">Second Snapshot</label>
                                <select id="snapshot2" name="snapshot2">
                                    <option value="">Select second snapshot...</option>
                                    <?php foreach ($snapshots as $snapshot): ?>
                                        <option value="<?= $snapshot['snapshot_id'] ?>">
                                            <?= date('M j, Y', strtotime($snapshot['snapshot_date'])) ?> -
                                            <?= ucfirst($snapshot['snapshot_type']) ?>
                                            (<?= number_format($snapshot['total_patients']) ?> patients)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <button class="btn btn-primary" onclick="compareSnapshots()">
                                <i class="fas fa-search"></i> Compare
                            </button>
                        </div>

                        <div id="comparisonResults" class="comparison-results">
                            <!-- Comparison results will be loaded here -->
                        </div>
                    </div>
                <?php endif; ?>
            </div>
    </section>

    <!-- Generate Snapshot Modal -->
    <div id="generateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-camera"></i> Generate New Snapshot</h3>
                <span class="close" onclick="closeModal('generateModal')">&times;</span>
            </div>
            <div class="modal-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="generate_snapshot">

                    <div class="form-group">
                        <label for="type">Snapshot Type</label>
                        <select id="type" name="type" required>
                            <option value="manual">Manual</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="semi_annual">Semi-Annual</option>
                            <option value="annual">Annual</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="notes">Notes (Optional)</label>
                        <textarea id="notes" name="notes" placeholder="Add any notes about this snapshot..."></textarea>
                    </div>

                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('generateModal')">
                            Cancel
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-camera"></i> Generate Snapshot
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let selectedSnapshots = [];

        // Modal functions
        function openGenerateModal() {
            document.getElementById('generateModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Generate automatic snapshots
        function generateQuarterlySnapshot() {
            showConfirmDialog(
                'Generate Quarterly Snapshot',
                'Generate quarterly snapshot for the current quarter?',
                function() {
                    fetch('../../api/historical_demographics.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'action=auto_generate_quarterly'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showAlert('Quarterly snapshot generated successfully!', 'success');
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                showAlert(data.error || 'Failed to generate quarterly snapshot', 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showAlert('An error occurred while generating the snapshot', 'error');
                        });
                }
            );
        }

        function generateAnnualSnapshot() {
            showConfirmDialog(
                'Generate Annual Snapshot',
                'Generate annual snapshot for the current year?',
                function() {
                    fetch('../../api/historical_demographics.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'action=auto_generate_annual'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showAlert('Annual snapshot generated successfully!', 'success');
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                showAlert(data.error || 'Failed to generate annual snapshot', 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showAlert('An error occurred while generating the snapshot', 'error');
                        });
                }
            );
        }

        // View snapshot function
        function viewSnapshot(snapshotId) {
            // Redirect to snapshot view page in same window
            window.location.href = `view_snapshot.php?id=${snapshotId}`;
        }

        // Select snapshot for comparison
        function selectForComparison(snapshotId, snapshotDate) {
            const snapshot1Select = document.getElementById('snapshot1');
            const snapshot2Select = document.getElementById('snapshot2');
            
            // Check if comparison elements exist (they won't exist if tables don't exist or there are fewer than 2 snapshots)
            if (!snapshot1Select || !snapshot2Select) {
                showAlert('Comparison feature is not available yet. Please ensure tables are set up and you have at least 2 snapshots.', 'warning');
                return;
            }

            if (!snapshot1Select.value) {
                snapshot1Select.value = snapshotId;
                showAlert(`Selected ${snapshotDate} as first snapshot`, 'info');
            } else if (!snapshot2Select.value && snapshot1Select.value != snapshotId) {
                snapshot2Select.value = snapshotId;
                showAlert(`Selected ${snapshotDate} as second snapshot`, 'info');
                // Auto-trigger comparison
                compareSnapshots();
            } else {
                showAlert('Please clear your current selection or choose different snapshots', 'warning');
            }
        }

        // Compare snapshots
        function compareSnapshots() {
            const snapshot1Select = document.getElementById('snapshot1');
            const snapshot2Select = document.getElementById('snapshot2');
            
            // Check if comparison elements exist
            if (!snapshot1Select || !snapshot2Select) {
                showAlert('Comparison feature is not available yet. Please ensure tables are set up and you have at least 2 snapshots.', 'warning');
                return;
            }
            
            const snapshot1Id = snapshot1Select.value;
            const snapshot2Id = snapshot2Select.value;

            if (!snapshot1Id || !snapshot2Id) {
                showAlert('Please select both snapshots to compare', 'warning');
                return;
            }

            if (snapshot1Id === snapshot2Id) {
                showAlert('Please select different snapshots to compare', 'warning');
                return;
            }

            fetch(`../../api/historical_demographics.php?action=compare_snapshots&snapshot1_id=${snapshot1Id}&snapshot2_id=${snapshot2Id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayComparisonResults(data.comparison);
                    } else {
                        showAlert(data.error || 'Failed to compare snapshots', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('An error occurred while comparing snapshots', 'error');
                });
        }

        // Display comparison results
        function displayComparisonResults(comparison) {
            const resultsDiv = document.getElementById('comparisonResults');
            const snapshot1 = comparison.snapshot1;
            const snapshot2 = comparison.snapshot2;
            const changes = comparison.comparison;

            const html = `
                <div class="comparison-header">
                    <div class="snapshot-info">
                        <h4>Snapshot 1</h4>
                        <p><strong>Date:</strong> ${new Date(snapshot1.metadata.snapshot_date).toLocaleDateString()}</p>
                        <p><strong>Type:</strong> ${snapshot1.metadata.snapshot_type.replace('_', ' ')}</p>
                        <p><strong>Total Patients:</strong> ${snapshot1.metadata.total_patients.toLocaleString()}</p>
                        <p><strong>Generated By:</strong> ${snapshot1.metadata.generated_by_name || 'System'}</p>
                    </div>
                    <div class="snapshot-info">
                        <h4>Snapshot 2</h4>
                        <p><strong>Date:</strong> ${new Date(snapshot2.metadata.snapshot_date).toLocaleDateString()}</p>
                        <p><strong>Type:</strong> ${snapshot2.metadata.snapshot_type.replace('_', ' ')}</p>
                        <p><strong>Total Patients:</strong> ${snapshot2.metadata.total_patients.toLocaleString()}</p>
                        <p><strong>Generated By:</strong> ${snapshot2.metadata.generated_by_name || 'System'}</p>
                    </div>
                </div>
                
                <div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid var(--border-color);">
                    <h4 style="margin: 0 0 15px 0; color: var(--primary-dark);">
                        <i class="fas fa-chart-line"></i> Overall Change
                    </h4>
                    <p style="margin: 0; font-size: 16px;">
                        Total Patients: 
                        <span class="change-indicator ${changes.total_patients.direction}">
                            ${changes.total_patients.change > 0 ? '+' : ''}${changes.total_patients.change.toLocaleString()}
                            (${changes.total_patients.change_percent > 0 ? '+' : ''}${changes.total_patients.change_percent.toFixed(1)}%)
                            <i class="fas fa-${changes.total_patients.direction === 'increase' ? 'arrow-up' : changes.total_patients.direction === 'decrease' ? 'arrow-down' : 'minus'}"></i>
                        </span>
                    </p>
                </div>
            `;

            resultsDiv.innerHTML = html;
            resultsDiv.classList.add('active');
        }

        // Delete snapshot
        function deleteSnapshot(snapshotId) {
            showConfirmDialog(
                'Delete Snapshot',
                'Are you sure you want to delete this snapshot? This action cannot be undone.',
                function() {
                    fetch('../../api/historical_demographics.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=delete_snapshot&snapshot_id=${snapshotId}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showAlert('Snapshot deleted successfully!', 'success');
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                showAlert(data.error || 'Failed to delete snapshot', 'error');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showAlert('An error occurred while deleting the snapshot', 'error');
                        });
                }
            );
        }

        // Utility function to show alerts
        function showAlert(message, type = 'info') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'}"></i>
                ${message}
                <button type="button" style="background: none; border: none; color: inherit; float: right; font-size: 18px; cursor: pointer;" onclick="this.parentElement.remove();">&times;</button>
            `;

            const firstAlert = document.querySelector('.alert');
            if (firstAlert) {
                firstAlert.parentNode.insertBefore(alertDiv, firstAlert);
            } else {
                document.querySelector('.content-wrapper').insertBefore(alertDiv, document.querySelector('.action-buttons'));
            }

            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // Custom confirmation dialog (no browser popup)
        function showConfirmDialog(title, message, onConfirm) {
            // Create confirmation dialog HTML
            const confirmHtml = `
                <div id="customConfirmDialog" style="
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(0, 0, 0, 0.5);
                    z-index: 2000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                ">
                    <div style="
                        background: white;
                        border-radius: 12px;
                        padding: 0;
                        max-width: 450px;
                        width: 90%;
                        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                        overflow: hidden;
                    ">
                        <div style="
                            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
                            color: white;
                            padding: 20px 25px;
                            font-size: 18px;
                            font-weight: 600;
                        ">
                            <i class="fas fa-question-circle" style="margin-right: 10px;"></i>
                            ${title}
                        </div>
                        <div style="padding: 25px;">
                            <p style="margin: 0 0 20px 0; color: var(--text-dark); line-height: 1.5;">
                                ${message}
                            </p>
                            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                                <button onclick="closeConfirmDialog()" style="
                                    padding: 10px 20px;
                                    border: none;
                                    border-radius: 8px;
                                    background: #6c757d;
                                    color: white;
                                    font-size: 14px;
                                    font-weight: 500;
                                    cursor: pointer;
                                    transition: all 0.3s ease;
                                ">
                                    Cancel
                                </button>
                                <button onclick="confirmAction()" style="
                                    padding: 10px 20px;
                                    border: none;
                                    border-radius: 8px;
                                    background: var(--primary-color);
                                    color: white;
                                    font-size: 14px;
                                    font-weight: 500;
                                    cursor: pointer;
                                    transition: all 0.3s ease;
                                ">
                                    <i class="fas fa-check" style="margin-right: 5px;"></i>
                                    Confirm
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Add to page
            document.body.insertAdjacentHTML('beforeend', confirmHtml);
            
            // Store the callback function
            window.currentConfirmCallback = onConfirm;
        }
        
        function closeConfirmDialog() {
            const dialog = document.getElementById('customConfirmDialog');
            if (dialog) {
                dialog.remove();
            }
            delete window.currentConfirmCallback;
        }
        
        function confirmAction() {
            if (window.currentConfirmCallback) {
                window.currentConfirmCallback();
            }
            closeConfirmDialog();
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
            
            // Handle custom confirm dialog
            const confirmDialog = document.getElementById('customConfirmDialog');
            if (confirmDialog && event.target === confirmDialog) {
                closeConfirmDialog();
            }
        }
    </script>
</body>

</html>