<?php
// doctor_profile.php - Doctor Profile Page
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';

// Authentication check - use session management function
if (!is_employee_logged_in()) {
    redirect_to_employee_login();
}

// Check role access - allow doctors and admins
require_employee_role(['doctor', 'admin']);

require_once $root_path . '/config/db.php';

// Fetch employee information
$employee_data = [];
try {
    $stmt = $conn->prepare("SELECT * FROM employees WHERE employee_id = ?");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $employee_data = $result->fetch_assoc();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    error_log("Error fetching employee data: " . $e->getMessage());
    $employee_data = [];
}

// Get doctor-specific information if available
$doctor_stats = [];
try {
    // Get consultation count
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_consultations,
            COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_consultations,
            COUNT(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as week_consultations
        FROM consultations 
        WHERE doctor_id = ?
    ");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $doctor_stats = $result->fetch_assoc();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    error_log("Error fetching doctor stats: " . $e->getMessage());
    $doctor_stats = ['total_consultations' => 0, 'today_consultations' => 0, 'week_consultations' => 0];
}

// Default values
$full_name = '';
if ($employee_data) {
    $name_parts = [];
    if (!empty($employee_data['first_name'])) $name_parts[] = $employee_data['first_name'];
    if (!empty($employee_data['middle_name'])) $name_parts[] = $employee_data['middle_name'];
    if (!empty($employee_data['last_name'])) $name_parts[] = $employee_data['last_name'];
    $full_name = implode(' ', $name_parts);
}

$activePage = 'profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Profile - CHO Koronadal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/sidebar.css">
    <link rel="stylesheet" href="../../assets/css/topbar.css">
    <link rel="stylesheet" href="../../assets/css/profile-edit.css">
    <style>
        .profile-container {
            max-width: 900px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            gap: 2rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .profile-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #0077b6;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .profile-info h1 {
            margin: 0;
            color: #0077b6;
            font-size: 1.8rem;
        }
        
        .profile-info p {
            margin: 0.5rem 0;
            color: #6b7280;
        }

        .doctor-badge {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            margin-top: 0.5rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 1.5rem;
            border-radius: 10px;
            border-left: 4px solid #0077b6;
            text-align: center;
        }

        .stat-card h3 {
            margin: 0;
            font-size: 2rem;
            color: #0077b6;
            font-weight: bold;
        }

        .stat-card p {
            margin: 0.5rem 0 0 0;
            color: #6b7280;
            font-weight: 500;
        }
        
        .profile-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 2rem;
        }
        
        .detail-group {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            border-left: 4px solid #0077b6;
        }
        
        .detail-group h3 {
            margin: 0 0 1rem 0;
            color: #333;
            font-size: 1.2rem;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.8rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .detail-label {
            font-weight: 600;
            color: #4b5563;
        }
        
        .detail-value {
            color: #1f2937;
            text-align: right;
        }
        
        .actions {
            margin-top: 2rem;
            display: flex;
            gap: 1rem;
            justify-content: center;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background-color: #0077b6;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #023e8a;
            color: white;
            text-decoration: none;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #495057;
            color: white;
            text-decoration: none;
        }

        .text-success {
            color: #28a745 !important;
        }

        .text-muted {
            color: #6c757d !important;
        }
    </style>
</head>
<body>
    <?php
    // Include appropriate sidebar based on role
    if ($employee_role === 'admin') {
        include '../../includes/sidebar_admin.php';
    } else {
        include '../../includes/sidebar_doctor.php';
    }
    ?>

    <main class="content-wrapper">
        <div class="profile-container">
            <div class="profile-header">
                <img src="../../vendor/photo_controller.php?employee_id=<?= $employee_id ?>" 
                     alt="Profile Photo" 
                     class="profile-photo"
                     onerror="this.src='../../assets/images/user-default.png'">
                <div class="profile-info">
                    <h1><?= htmlspecialchars($full_name ?: 'Doctor Profile') ?></h1>
                    <div class="doctor-badge">
                        <i class="fas fa-user-md"></i> 
                        <?= htmlspecialchars(ucfirst($employee_role)) ?>
                    </div>
                    <p><i class="fas fa-id-badge"></i> Employee ID: <strong><?= htmlspecialchars($employee_data['employee_number'] ?? 'N/A') ?></strong></p>
                    <p><i class="fas fa-calendar-alt"></i> Member Since: <strong><?= htmlspecialchars($employee_data['created_at'] ? date('F Y', strtotime($employee_data['created_at'])) : 'N/A') ?></strong></p>
                </div>
            </div>

            <!-- Doctor Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><?= number_format($doctor_stats['total_consultations'] ?? 0) ?></h3>
                    <p><i class="fas fa-stethoscope"></i> Total Consultations</p>
                </div>
                <div class="stat-card">
                    <h3><?= number_format($doctor_stats['today_consultations'] ?? 0) ?></h3>
                    <p><i class="fas fa-calendar-day"></i> Today's Consultations</p>
                </div>
                <div class="stat-card">
                    <h3><?= number_format($doctor_stats['week_consultations'] ?? 0) ?></h3>
                    <p><i class="fas fa-calendar-week"></i> This Week</p>
                </div>
            </div>

            <div class="profile-details">
                <div class="detail-group">
                    <h3><i class="fas fa-user"></i> Personal Information</h3>
                    <div class="detail-item">
                        <span class="detail-label">First Name:</span>
                        <span class="detail-value"><?= htmlspecialchars($employee_data['first_name'] ?? 'N/A') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Middle Name:</span>
                        <span class="detail-value"><?= htmlspecialchars($employee_data['middle_name'] ?? 'N/A') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Last Name:</span>
                        <span class="detail-value"><?= htmlspecialchars($employee_data['last_name'] ?? 'N/A') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Email:</span>
                        <span class="detail-value"><?= htmlspecialchars($employee_data['email'] ?? 'N/A') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Phone:</span>
                        <span class="detail-value"><?= htmlspecialchars($employee_data['phone'] ?? 'N/A') ?></span>
                    </div>
                </div>

                <div class="detail-group">
                    <h3><i class="fas fa-briefcase"></i> Professional Information</h3>
                    <div class="detail-item">
                        <span class="detail-label">Position:</span>
                        <span class="detail-value"><?= htmlspecialchars($employee_data['position'] ?? ucfirst($employee_role)) ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Department:</span>
                        <span class="detail-value"><?= htmlspecialchars($employee_data['department'] ?? 'Medical Department') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Specialization:</span>
                        <span class="detail-value"><?= htmlspecialchars($employee_data['specialization'] ?? 'General Practice') ?></span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value">
                            <?php if (isset($employee_data['is_active']) && $employee_data['is_active']): ?>
                                <i class="fas fa-circle text-success"></i> Active
                            <?php else: ?>
                                <i class="fas fa-circle text-muted"></i> Inactive
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Last Login:</span>
                        <span class="detail-value"><?= htmlspecialchars($employee_data['last_login'] ? date('M d, Y H:i', strtotime($employee_data['last_login'])) : 'N/A') ?></span>
                    </div>
                </div>
            </div>

            <div class="actions">
                <a href="doctor_settings.php" class="btn btn-primary">
                    <i class="fas fa-cog"></i> Account Settings
                </a>
                <a href="javascript:history.back()" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Go Back
                </a>
            </div>
        </div>
    </main>

    <script>
        // Add any profile-specific JavaScript here
    </script>
</body>
</html>