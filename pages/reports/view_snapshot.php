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

// Define role-based permissions
$allowedRoles = ['admin', 'dho', 'records_officer'];
$userRole = $_SESSION['role'] ?? '';

if (!in_array($userRole, $allowedRoles)) {
    $role = strtolower($userRole);
    header("Location: ../management/$role/dashboard.php");
    exit();
}

// Get snapshot ID
$snapshotId = $_GET['id'] ?? '';
if (empty($snapshotId)) {
    header("Location: historical_demographics.php");
    exit();
}

// Initialize service and get snapshot data
$service = new HistoricalDemographicsService($conn, $pdo);
$snapshot = $service->getSnapshotData($snapshotId);

if (!$snapshot) {
    header("Location: historical_demographics.php?error=Snapshot not found");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Snapshot View - CHO Koronadal</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">

    <!-- CSS Assets -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/topbar.css">

    <style>
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
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f8f9fa;
        }

        .homepage {
            margin-left: 0;
            min-height: 100vh;
        }

        .content-wrapper {
            padding: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        .snapshot-header {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .snapshot-header h1 {
            color: var(--primary-dark);
            margin: 0 0 15px 0;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .snapshot-header h1 i {
            color: var(--primary-color);
        }

        .snapshot-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }

        .meta-item {
            display: flex;
            flex-direction: column;
        }

        .meta-label {
            font-weight: 600;
            color: var(--text-light);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .meta-value {
            color: var(--text-dark);
            font-size: 16px;
            font-weight: 500;
        }

        .data-section {
            background: var(--background-light);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-light);
            border: 1px solid var(--border-color);
        }

        .data-section h3 {
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
            margin: 0 0 20px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .data-section h3 i {
            color: var(--primary-color);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .data-table th,
        .data-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .data-table th {
            background: #f8f9fa;
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 14px;
        }

        .data-table td {
            color: var(--text-dark);
            font-size: 14px;
        }

        .data-table tr:hover {
            background: #f8f9fa;
        }

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

        .back-actions {
            margin-bottom: 20px;
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

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        /* Print styles */
        @media print {
            body {
                background: white;
            }
            
            .back-actions {
                display: none;
            }
            
            .content-wrapper {
                padding: 0;
                max-width: none;
            }
            
            .snapshot-header,
            .data-section {
                box-shadow: none;
                border: 1px solid #ddd;
                margin-bottom: 20px;
                page-break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <!-- Include topbar -->
    <?php 
    $topbarConfig = [
        'title' => 'Snapshot View',
        'back_url' => 'historical_demographics.php',
        'user_type' => 'employee'
    ];
    include '../../includes/topbar.php'; 
    ?>

    <section class="homepage">
        <div class="content-wrapper">
            <div class="back-actions">
                <a href="historical_demographics.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Historical Data
                </a>
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Snapshot
                </button>
            </div>

            <!-- Snapshot Header -->
            <div class="snapshot-header">
                <h1>
                    <i class="fas fa-camera"></i> 
                    Demographics Snapshot - <?= date('F j, Y', strtotime($snapshot['metadata']['snapshot_date'])) ?>
                </h1>
                
                <div class="snapshot-meta">
                    <div class="meta-item">
                        <div class="meta-label">Snapshot Type</div>
                        <div class="meta-value">
                            <span class="badge badge-<?= $snapshot['metadata']['snapshot_type'] ?>">
                                <?= ucfirst(str_replace('_', ' ', $snapshot['metadata']['snapshot_type'])) ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="meta-item">
                        <div class="meta-label">Total Patients</div>
                        <div class="meta-value"><?= number_format($snapshot['metadata']['total_patients']) ?></div>
                    </div>
                    
                    <div class="meta-item">
                        <div class="meta-label">Generated By</div>
                        <div class="meta-value"><?= htmlspecialchars($snapshot['metadata']['generated_by_name'] ?: 'System') ?></div>
                    </div>
                    
                    <div class="meta-item">
                        <div class="meta-label">Generated On</div>
                        <div class="meta-value"><?= date('M j, Y g:i A', strtotime($snapshot['metadata']['created_at'])) ?></div>
                    </div>
                </div>
                
                <?php if (!empty($snapshot['metadata']['notes'])): ?>
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--border-color);">
                        <div class="meta-label">Notes</div>
                        <div class="meta-value"><?= htmlspecialchars($snapshot['metadata']['notes']) ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Age Distribution -->
            <div class="data-section">
                <h3><i class="fas fa-birthday-cake"></i> Age Distribution</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Age Group</th>
                            <th>Count</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($snapshot['age_distribution'] as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['age_group']) ?></td>
                                <td><?= number_format($item['count']) ?></td>
                                <td><?= number_format($item['percentage'], 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Sex Distribution -->
            <div class="data-section">
                <h3><i class="fas fa-venus-mars"></i> Sex Distribution</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Sex</th>
                            <th>Count</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($snapshot['gender_distribution'] as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['gender']) ?></td>
                                <td><?= number_format($item['count']) ?></td>
                                <td><?= number_format($item['percentage'], 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- District Distribution -->
            <div class="data-section">
                <h3><i class="fas fa-map-marked-alt"></i> District Distribution</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>District</th>
                            <th>Count</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($snapshot['district_distribution'] as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['district_name']) ?></td>
                                <td><?= number_format($item['count']) ?></td>
                                <td><?= number_format($item['percentage'], 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- PhilHealth Distribution -->
            <div class="data-section">
                <h3><i class="fas fa-id-card"></i> PhilHealth Membership</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Membership Type</th>
                            <th>Count</th>
                            <th>Percentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($snapshot['philhealth_distribution'] as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['membership_type']) ?></td>
                                <td><?= number_format($item['count']) ?></td>
                                <td><?= number_format($item['percentage'], 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- PWD Statistics -->
            <?php if (isset($snapshot['pwd_statistics'])): ?>
                <div class="data-section">
                    <h3><i class="fas fa-wheelchair"></i> PWD Statistics</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Count</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>PWD Patients</td>
                                <td><?= number_format($snapshot['pwd_statistics']['pwd_count']) ?></td>
                                <td><?= number_format($snapshot['pwd_statistics']['pwd_percentage'], 1) ?>%</td>
                            </tr>
                            <tr>
                                <td>Non-PWD Patients</td>
                                <td><?= number_format($snapshot['metadata']['total_patients'] - $snapshot['pwd_statistics']['pwd_count']) ?></td>
                                <td><?= number_format(100 - $snapshot['pwd_statistics']['pwd_percentage'], 1) ?>%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</body>

</html>