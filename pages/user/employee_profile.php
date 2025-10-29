<?php
// pages/user/employee_profile.php
// Unified Employee Profile - Works for all roles
// Author: GitHub Copilot

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Authentication check - use session management function
if (!is_employee_logged_in()) {
    redirect_to_employee_login();
}

// Get employee ID - can view own profile or admin can view any profile
$employee_id = intval($_GET['id'] ?? $_SESSION['employee_id']);

// Check permissions - only admin can view other profiles
if ($_SESSION['role'] !== 'admin' && $employee_id != $_SESSION['employee_id']) {
    header('Location: employee_profile.php?id=' . $_SESSION['employee_id'] . '&error=permission_denied');
    exit();
}

// Set active page for sidebar highlighting
$activePage = 'profile';

// Initialize variables
$employee = null;
$activity_logs = [];
$station_assignments = [];
$role_specific_stats = [];

// Fetch comprehensive employee data
try {
    $stmt = $conn->prepare("
        SELECT e.*, r.role_name, r.description as role_description, 
               f.name as facility_name, f.type as facility_type,
               TIMESTAMPDIFF(YEAR, e.birth_date, CURDATE()) as age,
               DATE_FORMAT(e.created_at, '%M %d, %Y at %h:%i %p') as date_hired,
               DATE_FORMAT(e.last_login, '%M %d, %Y at %h:%i %p') as last_login_formatted,
               CASE 
                   WHEN e.last_login IS NULL THEN 'Never logged in'
                   WHEN e.last_login < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 'Inactive (30+ days)'
                   WHEN e.last_login < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 'Less active (7+ days)'
                   WHEN e.last_login < DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 'Recently active'
                   ELSE 'Currently active'
               END as activity_status,
               CASE 
                   WHEN e.last_login IS NULL THEN 'danger'
                   WHEN e.last_login < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 'warning'
                   WHEN e.last_login < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 'info'
                   ELSE 'success'
               END as activity_badge
        FROM employees e 
        LEFT JOIN roles r ON e.role_id = r.role_id 
        LEFT JOIN facilities f ON e.facility_id = f.facility_id 
        WHERE e.employee_id = ?
    ");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        header('Location: employee_profile.php?id=' . $_SESSION['employee_id'] . '&error=employee_not_found');
        exit();
    }

    $employee = $result->fetch_assoc();
} catch (Exception $e) {
    header('Location: employee_profile.php?id=' . $_SESSION['employee_id'] . '&error=fetch_failed');
    exit();
}

// Get role-specific statistics
try {
    switch (strtolower($employee['role_name'])) {
        case 'doctor':
            $stats_stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total_consultations,
                    COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_consultations,
                    COUNT(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as week_consultations,
                    COUNT(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as month_consultations
                FROM consultations 
                WHERE doctor_id = ?
            ");
            $stats_stmt->bind_param("i", $employee_id);
            $stats_stmt->execute();
            $role_specific_stats = $stats_stmt->get_result()->fetch_assoc();
            $role_specific_stats['role_type'] = 'doctor';
            break;

        case 'nurse':
            $stats_stmt = $conn->prepare("
                SELECT 
                    COUNT(DISTINCT qe.queue_id) as patients_assisted,
                    COUNT(CASE WHEN DATE(qe.created_at) = CURDATE() THEN 1 END) as today_patients,
                    COUNT(CASE WHEN DATE(qe.created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as week_patients
                FROM queue_entries qe
                JOIN station_assignments sa ON qe.station_id = sa.station_id
                WHERE sa.employee_id = ? AND qe.status = 'completed'
            ");
            $stats_stmt->bind_param("i", $employee_id);
            $stats_stmt->execute();
            $role_specific_stats = $stats_stmt->get_result()->fetch_assoc();
            $role_specific_stats['role_type'] = 'nurse';
            break;

        case 'laboratory tech':
        case 'laboratory_tech':
            $stats_stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total_lab_orders,
                    COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_orders,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_orders,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_orders
                FROM lab_orders 
                WHERE technician_id = ?
            ");
            $stats_stmt->bind_param("i", $employee_id);
            $stats_stmt->execute();
            $role_specific_stats = $stats_stmt->get_result()->fetch_assoc();
            $role_specific_stats['role_type'] = 'lab_tech';
            break;

        case 'pharmacist':
            $stats_stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total_prescriptions,
                    COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_prescriptions,
                    COUNT(CASE WHEN status = 'dispensed' THEN 1 END) as dispensed_prescriptions,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_prescriptions
                FROM prescriptions 
                WHERE pharmacist_id = ?
            ");
            $stats_stmt->bind_param("i", $employee_id);
            $stats_stmt->execute();
            $role_specific_stats = $stats_stmt->get_result()->fetch_assoc();
            $role_specific_stats['role_type'] = 'pharmacist';
            break;

        default:
            $role_specific_stats = ['role_type' => 'general'];
            break;
    }
} catch (Exception $e) {
    $role_specific_stats = ['role_type' => 'general'];
}

// Fetch recent activity logs (if admin or viewing own profile)
if ($_SESSION['role'] === 'admin' || $employee_id == $_SESSION['employee_id']) {
    try {
        $activity_stmt = $conn->prepare("
            SELECT ual.*, 
                   DATE_FORMAT(ual.created_at, '%M %d, %Y at %h:%i %p') as formatted_date,
                   CONCAT(admin.first_name, ' ', admin.last_name) as admin_name
            FROM user_activity_logs ual
            LEFT JOIN employees admin ON ual.admin_id = admin.employee_id
            WHERE ual.employee_id = ?
            ORDER BY ual.created_at DESC
            LIMIT 10
        ");
        $activity_stmt->bind_param("i", $employee_id);
        $activity_stmt->execute();
        $activity_logs = $activity_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        $activity_logs = [];
    }
}

// Fetch current station assignments
try {
    $assignment_stmt = $conn->prepare("
        SELECT sa.*, s.station_name, s.station_type, s.description as station_description,
               DATE_FORMAT(sa.assigned_date, '%M %d, %Y') as assigned_date_formatted,
               CASE 
                   WHEN sa.assigned_date = CURDATE() THEN 'Today'
                   WHEN sa.assigned_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 'Yesterday'
                   ELSE DATE_FORMAT(sa.assigned_date, '%M %d, %Y')
               END as assignment_display
        FROM station_assignments sa
        JOIN stations s ON sa.station_id = s.station_id
        WHERE sa.employee_id = ? AND sa.assigned_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY sa.assigned_date DESC, sa.start_time DESC
        LIMIT 15
    ");
    $assignment_stmt->bind_param("i", $employee_id);
    $assignment_stmt->execute();
    $station_assignments = $assignment_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    $station_assignments = [];
}

// Check if viewing own profile
$is_own_profile = ($employee_id == $_SESSION['employee_id']);
$page_title = $is_own_profile ? 'My Profile' : 'Employee Profile';

// Determine which sidebar to include based on role
$current_user_role = $_SESSION['role'];
$sidebar_file = match($current_user_role) {
    'admin' => 'sidebar_admin.php',
    'doctor' => 'sidebar_doctor.php',
    'nurse' => 'sidebar_nurse.php',
    'dho' => 'sidebar_dho.php',
    'bhw' => 'sidebar_bhw.php',
    'laboratory_tech' => 'sidebar_laboratory_tech.php',
    'pharmacist' => 'sidebar_pharmacist.php',
    'cashier' => 'sidebar_cashier.php',
    'records_officer' => 'sidebar_records_officer.php',
    default => 'sidebar_admin.php'
};
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
    <title><?= $page_title ?> - <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></title>

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/css/sidebar.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">

    <style>
        /* Clean Profile Layout */
        .profile-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        /* Profile Header */
        .profile-header {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            color: white;
            padding: 40px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(37, 99, 235, 0.15);
        }

        .profile-header-content {
            display: flex;
            align-items: center;
            gap: 30px;
        }

        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
            border: 4px solid rgba(255, 255, 255, 0.2);
            flex-shrink: 0;
            overflow: hidden;
            position: relative;
        }

        .profile-avatar .profile-photo-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
        }

        .profile-avatar .profile-photo-fallback {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
        }

        .profile-info {
            flex: 1;
        }

        .profile-name {
            font-size: 2.2rem;
            font-weight: 700;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }

        .profile-role {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 15px;
        }

        .profile-meta {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            font-size: 0.9rem;
        }

        .profile-meta span {
            background: rgba(255, 255, 255, 0.15);
            padding: 6px 12px;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .profile-status {
            text-align: right;
        }

        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .status-indicator.active { background: #22c55e; }
        .status-indicator.inactive { background: #ef4444; }
        .status-indicator.warning { background: #f59e0b; }

        /* Navigation Tabs */
        .profile-nav {
            display: flex;
            background: white;
            border-radius: 12px;
            padding: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow-x: auto;
        }

        .nav-tab {
            flex: 1;
            min-width: 120px;
            padding: 12px 20px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: #6b7280;
            text-decoration: none;
            border: none;
            background: none;
        }

        .nav-tab:hover {
            background: #f3f4f6;
            color: #374151;
        }

        .nav-tab.active {
            background: linear-gradient(135deg, #2563eb, #1e40af);
            color: white;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        }

        /* Content Sections */
        .tab-content {
            display: none;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .tab-content.active {
            display: block;
        }

        .content-section {
            padding: 30px;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #2563eb;
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .info-card {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .info-card:hover {
            border-color: #2563eb;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
        }

        .info-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #6b7280;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: #1f2937;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.15);
            border-color: #2563eb;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #2563eb;
            margin-bottom: 8px;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.875rem;
            font-weight: 500;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* List Items */
        .list-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 16px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .list-item:last-child {
            border-bottom: none;
        }

        .list-icon {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            background: linear-gradient(135deg, #2563eb, #1e40af);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .list-content {
            flex: 1;
        }

        .list-title {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 4px;
        }

        .list-subtitle {
            font-size: 0.875rem;
            color: #6b7280;
        }

        .list-meta {
            font-size: 0.75rem;
            color: #9ca3af;
            text-align: right;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }

        .action-left {
            display: flex;
            gap: 12px;
        }

        .action-right {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #2563eb, #1e40af);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e3a8a);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
            color: #334155;
        }

        .btn-outline {
            background: transparent;
            color: #2563eb;
            border: 2px solid #2563eb;
        }

        .btn-outline:hover {
            background: #2563eb;
            color: white;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 16px;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 1.2rem;
            margin-bottom: 8px;
            color: #374151;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            .profile-container {
                padding: 15px;
            }

            .profile-header {
                padding: 25px;
            }

            .profile-header-content {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }

            .profile-status {
                text-align: center;
            }

            .profile-nav {
                flex-direction: column;
                gap: 8px;
            }

            .nav-tab {
                min-width: auto;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .action-buttons {
                flex-direction: column;
                align-items: stretch;
                gap: 15px;
            }

            .action-left,
            .action-right {
                width: 100%;
                justify-content: center;
            }

            .action-right {
                order: -1; /* Put action buttons above back button on mobile */
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .profile-name {
                font-size: 1.8rem;
            }

            .profile-avatar {
                width: 80px;
                height: 80px;
                font-size: 2.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="homepage">
        <!-- Dynamic Sidebar based on current user role -->
        <?php include $root_path . '/includes/' . $sidebar_file; ?>

        <!-- Main Content -->
        <main class="main-content">
            <div class="profile-container">
                <!-- Action Buttons -->
                <div class="action-buttons">
                    <!-- Left side: Back button -->
                    <div class="action-left">
                        <a href="javascript:history.back()" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Go Back
                        </a>
                    </div>
                    
                    <!-- Right side: Profile actions -->
                    <div class="action-right">
                        <?php if ($_SESSION['role'] === 'admin' && !$is_own_profile): ?>
                            <a href="../management/admin/user-management/edit_employee.php?id=<?= $employee_id ?>" class="btn btn-primary">
                                <i class="fas fa-edit"></i> Edit Employee
                            </a>
                            <a href="../management/admin/user-management/user_activity_logs.php?employee_id=<?= $employee_id ?>" class="btn btn-outline">
                                <i class="fas fa-history"></i> Full Activity Log
                            </a>
                        <?php endif; ?>
                        <?php if ($is_own_profile): ?>
                            <a href="user_settings.php" class="btn btn-primary">
                                <i class="fas fa-cog"></i> Account Settings
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Profile Header -->
                <div class="profile-header">
                    <div class="profile-header-content">
                        <div class="profile-avatar">
                            <img src="employee_photo.php?id=<?= urlencode($employee_id) ?>" 
                                 alt="<?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?> Profile Photo" 
                                 class="profile-photo-img"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="profile-photo-fallback" style="display: none;">
                                <i class="fas fa-user"></i>
                            </div>
                        </div>
                        <div class="profile-info">
                            <h1 class="profile-name"><?= htmlspecialchars($employee['first_name'] . ' ' . ($employee['middle_name'] ? $employee['middle_name'] . ' ' : '') . $employee['last_name']) ?></h1>
                            <div class="profile-role"><?= htmlspecialchars($employee['role_name']) ?> • <?= htmlspecialchars($employee['facility_name']) ?></div>
                            <div class="profile-meta">
                                <span><i class="fas fa-id-badge"></i> <?= htmlspecialchars($employee['employee_number']) ?></span>
                                <span><i class="fas fa-calendar"></i> <?= $employee['age'] ?> years old</span>
                                <span><i class="fas fa-briefcase"></i> Hired <?= date('M Y', strtotime($employee['created_at'])) ?></span>
                            </div>
                        </div>
                        <div class="profile-status">
                            <div style="font-size: 1.1rem; margin-bottom: 8px;">
                                <span class="status-indicator <?= $employee['status'] === 'active' ? 'active' : ($employee['status'] === 'inactive' ? 'inactive' : 'warning') ?>"></span>
                                <?= ucfirst(str_replace('_', ' ', $employee['status'])) ?>
                            </div>
                            <div style="font-size: 0.9rem; opacity: 0.8;">
                                <?= $employee['activity_status'] ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation Tabs -->
                <div class="profile-nav">
                    <button class="nav-tab active" onclick="showTab('overview')">
                        <i class="fas fa-chart-line"></i> Overview
                    </button>
                    <button class="nav-tab" onclick="showTab('personal')">
                        <i class="fas fa-user"></i> Personal Info
                    </button>
                    <button class="nav-tab" onclick="showTab('employment')">
                        <i class="fas fa-briefcase"></i> Employment
                    </button>
                    <button class="nav-tab" onclick="showTab('assignments')">
                        <i class="fas fa-map-marker-alt"></i> Assignments
                    </button>
                    <?php if (!empty($activity_logs)): ?>
                    <button class="nav-tab" onclick="showTab('activity')">
                        <i class="fas fa-history"></i> Activity
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Tab Content: Overview -->
                <div id="overview" class="tab-content active">
                    <div class="content-section">
                        <?php if ($role_specific_stats['role_type'] !== 'general' && !empty(array_filter($role_specific_stats, function($v, $k) { return $k !== 'role_type' && $v > 0; }, ARRAY_FILTER_USE_BOTH))): ?>
                        <h2 class="section-title">
                            <i class="fas fa-chart-bar"></i> Performance Overview
                        </h2>
                        <div class="stats-grid">
                            <?php if ($role_specific_stats['role_type'] === 'doctor'): ?>
                                <div class="stat-card">
                                    <div class="stat-number"><?= number_format($role_specific_stats['total_consultations'] ?? 0) ?></div>
                                    <div class="stat-label">Total Consultations</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?= number_format($role_specific_stats['today_consultations'] ?? 0) ?></div>
                                    <div class="stat-label">Today</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?= number_format($role_specific_stats['week_consultations'] ?? 0) ?></div>
                                    <div class="stat-label">This Week</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?= number_format($role_specific_stats['month_consultations'] ?? 0) ?></div>
                                    <div class="stat-label">This Month</div>
                                </div>
                            <?php elseif ($role_specific_stats['role_type'] === 'nurse'): ?>
                                <div class="stat-card">
                                    <div class="stat-number"><?= number_format($role_specific_stats['patients_assisted'] ?? 0) ?></div>
                                    <div class="stat-label">Patients Assisted</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?= number_format($role_specific_stats['today_patients'] ?? 0) ?></div>
                                    <div class="stat-label">Today</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?= number_format($role_specific_stats['week_patients'] ?? 0) ?></div>
                                    <div class="stat-label">This Week</div>
                                </div>
                            <?php elseif ($role_specific_stats['role_type'] === 'lab_tech'): ?>
                                <div class="stat-card">
                                    <div class="stat-number"><?= number_format($role_specific_stats['total_lab_orders'] ?? 0) ?></div>
                                    <div class="stat-label">Lab Orders</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?= number_format($role_specific_stats['completed_orders'] ?? 0) ?></div>
                                    <div class="stat-label">Completed</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?= number_format($role_specific_stats['pending_orders'] ?? 0) ?></div>
                                    <div class="stat-label">Pending</div>
                                </div>
                            <?php elseif ($role_specific_stats['role_type'] === 'pharmacist'): ?>
                                <div class="stat-card">
                                    <div class="stat-number"><?= number_format($role_specific_stats['total_prescriptions'] ?? 0) ?></div>
                                    <div class="stat-label">Prescriptions</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?= number_format($role_specific_stats['dispensed_prescriptions'] ?? 0) ?></div>
                                    <div class="stat-label">Dispensed</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-number"><?= number_format($role_specific_stats['pending_prescriptions'] ?? 0) ?></div>
                                    <div class="stat-label">Pending</div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-line"></i>
                            <h3>Welcome to Your Profile</h3>
                            <p>Your performance metrics will appear here as you begin working.</p>
                        </div>
                        <?php endif; ?>

                        <h2 class="section-title">
                            <i class="fas fa-info-circle"></i> Quick Summary
                        </h2>
                        <div class="info-grid">
                            <div class="info-card">
                                <div class="info-label">Last Login</div>
                                <div class="info-value"><?= $employee['last_login_formatted'] ?: 'Never logged in' ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Employment Status</div>
                                <div class="info-value"><?= ucfirst(str_replace('_', ' ', $employee['status'])) ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Role Description</div>
                                <div class="info-value"><?= htmlspecialchars($employee['role_description'] ?? 'No description available') ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Personal Information -->
                <div id="personal" class="tab-content">
                    <div class="content-section">
                        <h2 class="section-title">
                            <i class="fas fa-user"></i> Personal Information
                        </h2>
                        <div class="info-grid">
                            <div class="info-card">
                                <div class="info-label">Full Name</div>
                                <div class="info-value"><?= htmlspecialchars($employee['first_name'] . ' ' . ($employee['middle_name'] ? $employee['middle_name'] . ' ' : '') . $employee['last_name']) ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Email Address</div>
                                <div class="info-value"><?= htmlspecialchars($employee['email']) ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Contact Number</div>
                                <div class="info-value"><?= htmlspecialchars($employee['contact_num'] ?? 'Not provided') ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Birth Date</div>
                                <div class="info-value"><?= $employee['birth_date'] ? date('F d, Y', strtotime($employee['birth_date'])) : 'Not provided' ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Age</div>
                                <div class="info-value"><?= $employee['age'] ?> years old</div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Gender</div>
                                <div class="info-value"><?= ucfirst($employee['gender'] ?? 'Not specified') ?></div>
                            </div>
                            <?php if (!empty($employee['license_number'])): ?>
                            <div class="info-card">
                                <div class="info-label">License Number</div>
                                <div class="info-value"><?= htmlspecialchars($employee['license_number']) ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Employment Information -->
                <div id="employment" class="tab-content">
                    <div class="content-section">
                        <h2 class="section-title">
                            <i class="fas fa-briefcase"></i> Employment Information
                        </h2>
                        <div class="info-grid">
                            <div class="info-card">
                                <div class="info-label">Employee Number</div>
                                <div class="info-value"><?= htmlspecialchars($employee['employee_number']) ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Role</div>
                                <div class="info-value"><?= htmlspecialchars($employee['role_name']) ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Department/Facility</div>
                                <div class="info-value"><?= htmlspecialchars($employee['facility_name']) ?> (<?= htmlspecialchars($employee['facility_type']) ?>)</div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Date Hired</div>
                                <div class="info-value"><?= $employee['date_hired'] ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Employment Status</div>
                                <div class="info-value"><?= ucfirst(str_replace('_', ' ', $employee['status'])) ?></div>
                            </div>
                            <div class="info-card">
                                <div class="info-label">Last Login</div>
                                <div class="info-value"><?= $employee['last_login_formatted'] ?: 'Never logged in' ?></div>
                            </div>
                        </div>
                        <?php if (!empty($employee['role_description'])): ?>
                        <h2 class="section-title">
                            <i class="fas fa-info"></i> Role Description
                        </h2>
                        <div class="info-card">
                            <div class="info-value" style="line-height: 1.6;"><?= nl2br(htmlspecialchars($employee['role_description'])) ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tab Content: Station Assignments -->
                <div id="assignments" class="tab-content">
                    <div class="content-section">
                        <h2 class="section-title">
                            <i class="fas fa-map-marker-alt"></i> Recent Station Assignments (Last 30 Days)
                        </h2>
                        <?php if (!empty($station_assignments)): ?>
                            <?php foreach ($station_assignments as $assignment): ?>
                            <div class="list-item">
                                <div class="list-icon">
                                    <i class="fas fa-hospital"></i>
                                </div>
                                <div class="list-content">
                                    <div class="list-title"><?= htmlspecialchars($assignment['station_name']) ?></div>
                                    <div class="list-subtitle">
                                        <?= $assignment['assignment_display'] ?>
                                        <?php if ($assignment['start_time'] && $assignment['end_time']): ?>
                                            • <?= date('g:i A', strtotime($assignment['start_time'])) ?> - <?= date('g:i A', strtotime($assignment['end_time'])) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($assignment['station_description'])): ?>
                                    <div class="list-subtitle"><?= htmlspecialchars($assignment['station_description']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="list-meta">
                                    <?= ucfirst($assignment['station_type']) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-map-marker-alt"></i>
                                <h3>No Recent Assignments</h3>
                                <p>No station assignments found in the last 30 days.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tab Content: Activity Log -->
                <?php if (!empty($activity_logs)): ?>
                <div id="activity" class="tab-content">
                    <div class="content-section">
                        <h2 class="section-title">
                            <i class="fas fa-history"></i> Recent Activity Log
                        </h2>
                        <?php foreach ($activity_logs as $log): ?>
                        <div class="list-item">
                            <div class="list-icon">
                                <?php
                                $icon = match(strtolower($log['action_type'])) {
                                    'create' => 'fas fa-plus',
                                    'update' => 'fas fa-edit',
                                    'delete' => 'fas fa-trash',
                                    'login' => 'fas fa-sign-in-alt',
                                    default => 'fas fa-info'
                                };
                                ?>
                                <i class="<?= $icon ?>"></i>
                            </div>
                            <div class="list-content">
                                <div class="list-title"><?= ucfirst($log['action_type']) ?> Action</div>
                                <div class="list-subtitle"><?= htmlspecialchars($log['description']) ?></div>
                            </div>
                            <div class="list-meta">
                                <?= $log['formatted_date'] ?>
                                <?php if ($log['admin_name'] && $log['admin_id'] != $employee_id): ?>
                                    <br>by <?= htmlspecialchars($log['admin_name']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                        <div style="text-align: center; margin-top: 30px;">
                            <a href="../management/admin/user-management/user_activity_logs.php?employee_id=<?= $employee_id ?>" class="btn btn-outline">
                                <i class="fas fa-history"></i> View Full Activity Log
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Tab Navigation Functionality
        function showTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all nav tabs
            const navTabs = document.querySelectorAll('.nav-tab');
            navTabs.forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            const selectedTab = document.getElementById(tabName);
            if (selectedTab) {
                selectedTab.classList.add('active');
            }
            
            // Mark the clicked nav tab as active
            event.target.classList.add('active');
            
            // Update URL hash for bookmarking
            history.replaceState(null, null, '#' + tabName);
        }

        // Initialize tab from URL hash or default to overview
        document.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash.substring(1);
            const validTabs = ['overview', 'personal', 'employment', 'assignments', 'activity'];
            const initialTab = validTabs.includes(hash) ? hash : 'overview';
            
            // Show the initial tab
            const tabContents = document.querySelectorAll('.tab-content');
            const navTabs = document.querySelectorAll('.nav-tab');
            
            tabContents.forEach(content => content.classList.remove('active'));
            navTabs.forEach(tab => tab.classList.remove('active'));
            
            const initialContent = document.getElementById(initialTab);
            const initialNavTab = document.querySelector(`[onclick="showTab('${initialTab}')"]`);
            
            if (initialContent) initialContent.classList.add('active');
            if (initialNavTab) initialNavTab.classList.add('active');
        });

        // Handle browser back/forward navigation
        window.addEventListener('popstate', function() {
            const hash = window.location.hash.substring(1);
            const validTabs = ['overview', 'personal', 'employment', 'assignments', 'activity'];
            const targetTab = validTabs.includes(hash) ? hash : 'overview';
            
            const tabContents = document.querySelectorAll('.tab-content');
            const navTabs = document.querySelectorAll('.nav-tab');
            
            tabContents.forEach(content => content.classList.remove('active'));
            navTabs.forEach(tab => tab.classList.remove('active'));
            
            const targetContent = document.getElementById(targetTab);
            const targetNavTab = document.querySelector(`[onclick="showTab('${targetTab}')"]`);
            
            if (targetContent) targetContent.classList.add('active');
            if (targetNavTab) targetNavTab.classList.add('active');
        });

        // Smooth scrolling for internal links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Add loading state for buttons
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('click', function() {
                if (this.href && !this.href.includes('#') && !this.onclick) {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                    this.style.pointerEvents = 'none';
                    
                    // Restore after navigation (fallback)
                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.style.pointerEvents = 'auto';
                    }, 3000);
                }
            });
        });

        // Enhanced hover effects for stat cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-4px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(-2px)';
            });
        });

        // Auto-refresh activity status every 5 minutes (only if visible)
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                const currentUrl = window.location.href;
                if (currentUrl.includes('employee_profile.php')) {
                    // Could implement a subtle AJAX refresh here
                    // For now, we'll keep it simple to avoid server load
                }
            }
        }, 300000); // 5 minutes

        // Keyboard navigation for tabs
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                const tabNumbers = ['1', '2', '3', '4', '5'];
                const tabNames = ['overview', 'personal', 'employment', 'assignments', 'activity'];
                const keyIndex = tabNumbers.indexOf(e.key);
                
                if (keyIndex !== -1 && keyIndex < tabNames.length) {
                    e.preventDefault();
                    const targetTab = document.querySelector(`[onclick="showTab('${tabNames[keyIndex]}')"]`);
                    if (targetTab) {
                        targetTab.click();
                    }
                }
            }
        });

        // Add accessibility improvements
        document.querySelectorAll('.nav-tab').forEach((tab, index) => {
            tab.setAttribute('role', 'tab');
            tab.setAttribute('tabindex', index === 0 ? '0' : '-1');
            
            tab.addEventListener('keydown', function(e) {
                const tabs = Array.from(document.querySelectorAll('.nav-tab'));
                const currentIndex = tabs.indexOf(this);
                
                switch(e.key) {
                    case 'ArrowLeft':
                    case 'ArrowUp':
                        e.preventDefault();
                        const prevIndex = currentIndex > 0 ? currentIndex - 1 : tabs.length - 1;
                        tabs[prevIndex].focus();
                        tabs[prevIndex].click();
                        break;
                    case 'ArrowRight':
                    case 'ArrowDown':
                        e.preventDefault();
                        const nextIndex = currentIndex < tabs.length - 1 ? currentIndex + 1 : 0;
                        tabs[nextIndex].focus();
                        tabs[nextIndex].click();
                        break;
                    case 'Home':
                        e.preventDefault();
                        tabs[0].focus();
                        tabs[0].click();
                        break;
                    case 'End':
                        e.preventDefault();
                        tabs[tabs.length - 1].focus();
                        tabs[tabs.length - 1].click();
                        break;
                    case 'Enter':
                    case ' ':
                        e.preventDefault();
                        this.click();
                        break;
                }
            });
        });
    </script>
</body>

</html>