<?php
// pages/queueing/station_dashboard.php
// Station-specific dashboard for monitoring queue and operations

// Start output buffering to prevent header issues
ob_start();

// Include path resolution and security configuration first (includes CSP headers)
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/production_security.php';
require_once $root_path . '/config/session/employee_session.php';

// If user is not logged in, bounce to login
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    // Clean output buffer if it exists
    if (ob_get_level()) {
        ob_end_clean();
    }
    error_log('Redirecting to employee_login from ' . __FILE__ . ' URI=' . ($_SERVER['REQUEST_URI'] ?? ''));
    header('Location: /pages/management/auth/employee_login.php');
    exit();
}

require_once $root_path . '/config/db.php';
require_once $root_path . '/config/auth_helpers.php';
require_once $root_path . '/utils/queue_management_service.php';

// Simple input sanitization function
if (!function_exists('sanitize_input')) {
    function sanitize_input($input) {
        if (is_string($input)) {
            return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
        }
        return $input;
    }
}

// Initialize queue management service with error handling
try {
    $queueService = new QueueManagementService($pdo);
} catch (Exception $e) {
    error_log('Queue service initialization error in station_dashboard.php: ' . $e->getMessage());
    // Clean output buffer if it exists
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Location: /pages/management/auth/employee_login.php?error=service_unavailable');
    exit();
}

// Get and validate station_id parameter
$station_id = intval($_GET['station_id'] ?? 0);
if ($station_id <= 0) {
    // Clean output buffer if it exists
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Location: /pages/management/admin/staff-management/staff_assignments.php?error=invalid_station');
    exit();
}

// Get station details with current assignment
$station = $queueService->getStationDetails($station_id);
if (!$station) {
    // Clean output buffer if it exists
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Location: /pages/management/admin/staff-management/staff_assignments.php?error=station_not_found');
    exit();
}

// Get current queue for this station
$currentQueue = $queueService->getStationQueue($station_id);

// Get station statistics for today
$today = date('Y-m-d');
$stationStats = $queueService->getStationStatistics($station_id, $today);

// Set active page for sidebar highlighting based on user role
$user_role = strtolower($_SESSION['role']);
if ($user_role === 'admin') {
    $activePage = 'staff_assignments';
} else {
    $activePage = 'queue_management';
}

// Determine which sidebar to include based on role
$sidebar_file = '';
switch ($user_role) {
    case 'admin':
        $sidebar_file = 'sidebar_admin.php';
        break;
    case 'doctor':
        $sidebar_file = 'sidebar_doctor.php';
        break;
    case 'nurse':
        $sidebar_file = 'sidebar_nurse.php';
        break;
    case 'laboratory_tech':
        $sidebar_file = 'sidebar_laboratory_tech.php';
        break;
    case 'pharmacist':
        $sidebar_file = 'sidebar_pharmacist.php';
        break;
    case 'cashier':
        $sidebar_file = 'sidebar_cashier.php';
        break;
    case 'records_officer':
        $sidebar_file = 'sidebar_records_officer.php';
        break;
    default:
        $sidebar_file = 'sidebar_admin.php';
        break;
}
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
    <title><?php echo htmlspecialchars($station['station_name']); ?> Dashboard | CHO Koronadal</title>
    <!-- CSS Files -->
    <link rel="stylesheet" href="../../assets/css/sidebar.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* Station Dashboard Specific Styles */
        :root {
            --primary: #0077b6;
            --primary-dark: #03045e;
            --secondary: #6c757d;
            --success: #2d6a4f;
            --info: #17a2b8;
            --warning: #ffc107;
            --danger: #d00000;
            --light: #f8f9fa;
            --border: #dee2e6;
            --shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            --shadow-lg: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            --border-radius: 0.5rem;
            --border-radius-lg: 1rem;
            --transition: all 0.3s ease;
        }

        .content-wrapper {
            min-height: calc(100vh - 60px);
        }

        /* Breadcrumb styling */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: #666;
            background: none;
            padding: 0;
        }

        .breadcrumb a {
            color: #0077b6;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
            color: #03045e;
        }

        .breadcrumb i {
            color: #999;
            font-size: 0.8rem;
        }

        /* Page header styling */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            background: linear-gradient(135deg, #0077b6, #03045e);
            color: white;
            padding: 20px 25px;
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-lg);
        }

        .page-header h1 {
            color: white;
            margin: 0;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-header h1 i {
            color: #48cae4;
            font-size: 1.6rem;
        }

        .station-info {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.9rem;
            margin-top: 5px;
        }

        .station-status {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .status-badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            gap: 8px;
            min-height: 40px;
        }

        .status-badge.active {
            background: linear-gradient(135deg, #52b788, #2d6a4f);
        }

        .status-badge.inactive {
            background: linear-gradient(135deg, #ef476f, #d00000);
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 20px;
            box-shadow: var(--shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            border-left: 4px solid var(--primary);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card .icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            margin-bottom: 15px;
        }

        .stat-card.primary .icon {
            background: linear-gradient(135deg, #48cae4, #0096c7);
        }

        .stat-card.success .icon {
            background: linear-gradient(135deg, #52b788, #2d6a4f);
        }

        .stat-card.warning .icon {
            background: linear-gradient(135deg, #ffba08, #faa307);
        }

        .stat-card.danger .icon {
            background: linear-gradient(135deg, #ef476f, #d00000);
        }

        .stat-card h3 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin: 0 0 5px 0;
        }

        .stat-card p {
            color: #666;
            font-size: 0.9rem;
            margin: 0;
            font-weight: 500;
        }

        /* Queue Section */
        .queue-section {
            background: white;
            border-radius: var(--border-radius-lg);
            padding: 25px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }

        .section-header h2 {
            color: var(--primary-dark);
            margin: 0;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-header h2 i {
            color: var(--primary);
        }

        .refresh-btn {
            background: linear-gradient(135deg, #48cae4, #0096c7);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .refresh-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 119, 182, 0.3);
        }

        /* Queue Table */
        .queue-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .queue-table th {
            background: linear-gradient(135deg, #0077b6, #03045e);
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 500;
            border: none;
        }

        .queue-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .queue-table tr:hover {
            background-color: rgba(240, 247, 255, 0.6);
        }

        .queue-number {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--primary-dark);
        }

        .priority-badge {
            padding: 4px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-normal {
            background-color: #e3f2fd;
            color: #1976d2;
        }

        .priority-urgent {
            background-color: #fff3e0;
            color: #f57c00;
        }

        .priority-emergency {
            background-color: #ffebee;
            color: #d32f2f;
        }

        .status-badge-queue {
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-waiting {
            background-color: #fff3e0;
            color: #f57c00;
        }

        .status-in-progress {
            background-color: #e3f2fd;
            color: #1976d2;
        }

        .status-completed {
            background-color: #e8f5e8;
            color: #388e3c;
        }

        .status-skipped {
            background-color: #f3e5f5;
            color: #7b1fa2;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 15px;
        }

        .empty-state h3 {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: #999;
        }

        .empty-state p {
            font-size: 0.9rem;
            color: #aaa;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                padding: 15px 20px;
            }

            .station-status {
                width: 100%;
                justify-content: flex-start;
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .status-badge {
                font-size: 0.75rem;
                padding: 6px 12px;
                min-height: auto;
            }

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .content-wrapper {
                padding: 15px;
            }

            .queue-table {
                font-size: 0.9rem;
            }

            .queue-table th,
            .queue-table td {
                padding: 10px 8px;
            }
        }

        @media (max-width: 480px) {
            .breadcrumb {
                font-size: 0.8rem;
            }

            .page-header h1 {
                font-size: 1.4rem;
            }

            .stat-card {
                padding: 15px;
            }

            .queue-section {
                padding: 15px;
            }

            .status-badge {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
                font-size: 0.7rem;
                padding: 8px 10px;
            }

            .status-badge i {
                align-self: center;
            }
        }
    </style>
</head>
<body>
    <!-- Include appropriate sidebar based on user role -->
    <?php include '../../includes/' . $sidebar_file; ?>
    
    <div class="homepage">
        <div class="content-wrapper">
            <!-- Breadcrumb Navigation -->
            <div class="breadcrumb" style="margin-top: 50px;">
                <a href="../management/admin/dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <a href="../management/admin/staff-management/staff_assignments.php">Station Assignments</a>
                <i class="fas fa-chevron-right"></i>
                <span><?php echo htmlspecialchars($station['station_name']); ?> Dashboard</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <div>
                    <h1>
                        <i class="fas fa-tachometer-alt"></i>
                        <?php echo htmlspecialchars($station['station_name']); ?> Dashboard
                    </h1>
                    <div class="station-info">
                        <i class="fas fa-map-marker-alt"></i> 
                        <?php echo ucfirst($station['station_type']); ?> Station
                        <?php if ($station['station_number'] > 1): ?>
                            #<?php echo $station['station_number']; ?>
                        <?php endif; ?>
                        | Service: <?php echo htmlspecialchars($station['service_name']); ?>
                    </div>
                </div>
                <div class="station-status">
                    <span class="status-badge <?php echo $station['is_active'] ? 'active' : 'inactive'; ?>">
                        <i class="fas <?php echo $station['is_active'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                        <?php echo $station['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                    <?php if ($station['employee_name']): ?>
                        <span class="status-badge">
                            <i class="fas fa-user"></i>
                            <div style="display: flex; flex-direction: column; align-items: flex-start; gap: 2px;">
                                <div style="font-weight: 600;">
                                    <?php echo htmlspecialchars($station['employee_name']); ?>
                                </div>
                                <div style="font-size: 0.75rem; opacity: 0.9;">
                                    <?php echo htmlspecialchars($station['employee_number']); ?> • 
                                    <?php echo htmlspecialchars(format_role_name($station['employee_role'])); ?>
                                </div>
                            </div>
                        </span>
                    <?php else: ?>
                        <span class="status-badge inactive">
                            <i class="fas fa-user-times"></i>
                            Unassigned
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card primary">
                    <div class="icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3><?php echo $stationStats['total_served'] ?? 0; ?></h3>
                    <p>Total Patients Served Today</p>
                </div>
                <div class="stat-card warning">
                    <div class="icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3><?php echo count($currentQueue); ?></h3>
                    <p>Patients in Queue</p>
                </div>
                <div class="stat-card success">
                    <div class="icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3><?php echo $stationStats['completed_today'] ?? 0; ?></h3>
                    <p>Completed Today</p>
                </div>
                <div class="stat-card danger">
                    <div class="icon">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                    <h3><?php echo $stationStats['avg_wait_time'] ?? 0; ?> min</h3>
                    <p>Average Wait Time</p>
                </div>
            </div>

            <!-- Current Queue Section -->
            <div class="queue-section">
                <div class="section-header">
                    <h2>
                        <i class="fas fa-list"></i>
                        Current Queue
                    </h2>
                    <button class="refresh-btn" onclick="location.reload()">
                        <i class="fas fa-sync-alt"></i>
                        Refresh
                    </button>
                </div>

                <?php if (!empty($currentQueue)): ?>
                    <div style="overflow-x: auto;">
                        <table class="queue-table">
                            <thead>
                                <tr>
                                    <th>Queue #</th>
                                    <th>Patient Name</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Wait Time</th>
                                    <th>Visit Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($currentQueue as $patient): ?>
                                    <tr>
                                        <td>
                                            <div class="queue-number"><?php echo htmlspecialchars($patient['queue_number']); ?></div>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($patient['patient_name']); ?></strong>
                                            <br>
                                            <small style="color: #666;">ID: <?php echo htmlspecialchars($patient['patient_id']); ?></small>
                                        </td>
                                        <td>
                                            <span class="priority-badge priority-<?php echo strtolower($patient['priority_level'] ?? 'normal'); ?>">
                                                <?php echo ucfirst($patient['priority_level'] ?? 'Normal'); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge-queue status-<?php echo str_replace(' ', '-', strtolower($patient['status'])); ?>">
                                                <?php echo htmlspecialchars($patient['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            if (isset($patient['wait_time_minutes'])) {
                                                echo $patient['wait_time_minutes'] . ' min';
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($patient['visit_type'] ?? 'Regular'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h3>No Patients in Queue</h3>
                        <p>There are currently no patients waiting at this station.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Auto-refresh the page every 30 seconds to keep queue data current
        setInterval(function() {
            location.reload();
        }, 30000);

        // Add smooth scrolling for better UX
        document.addEventListener('DOMContentLoaded', function() {
            // Smooth scroll to queue section if there are patients
            const queueSection = document.querySelector('.queue-section');
            if (queueSection && <?php echo count($currentQueue); ?> > 0) {
                // Optional: Add visual indicators for queue changes
                console.log('Station dashboard loaded with <?php echo count($currentQueue); ?> patients in queue');
            }
        });
    </script>
</body>
</html>
<?php
// Flush output buffer at the end
if (ob_get_level()) {
    ob_end_flush();
}
?>
