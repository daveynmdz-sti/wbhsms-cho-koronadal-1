<?php
// Clinical Encounter Management Dashboard - Updated for Standalone Consultations
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(dirname(dirname(__FILE__)));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed. Please contact administrator.");
}

// If user is not logged in, bounce to login
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    header('Location: ../management/auth/employee_login.php');
    exit();
}

// Check if role is authorized for clinical encounters
$authorized_roles = ['doctor', 'nurse', 'admin', 'records_officer', 'bhw', 'dho', 'pharmacist'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    header('Location: ../management/dashboard.php');
    exit();
}

$employee_id = $_SESSION['employee_id'];
$employee_role = strtolower($_SESSION['role']);
$activePage = 'clinical_encounters';

// Get recent consultations
$recent_consultations = [];
try {
    $sql = "SELECT 
                c.consultation_id,
                c.patient_id,
                c.consultation_date,
                c.chief_complaint,
                c.consultation_status,
                c.consulted_by,
                c.created_at,
                -- Patient details
                p.username AS patient_code,
                p.first_name,
                p.last_name,
                p.middle_name,
                CONCAT(p.first_name, ' ', COALESCE(CONCAT(p.middle_name, ' '), ''), p.last_name) AS full_name,
                p.date_of_birth,
                p.sex,
                b.barangay_name,
                -- Employee details
                e.first_name AS consulted_by_name,
                e.last_name AS consulted_by_surname,
                CONCAT(e.first_name, ' ', e.last_name) AS consulted_by_full_name,
                -- Vitals info
                v.vitals_id,
                v.systolic_bp,
                v.diastolic_bp,
                v.temperature,
                v.recorded_at AS vitals_date
            FROM consultations c
            LEFT JOIN patients p ON c.patient_id = p.patient_id
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            LEFT JOIN employees e ON c.consulted_by = e.employee_id
            LEFT JOIN vitals v ON c.vitals_id = v.vitals_id
            WHERE 1=1";
    
    // Add role-based filtering if needed
    if ($employee_role === 'doctor' || $employee_role === 'nurse') {
        $sql .= " AND c.consulted_by = ?";
        $params = [$employee_id];
        $param_types = "i";
    } else {
        $params = [];
        $param_types = "";
    }
    
    $sql .= " ORDER BY c.consultation_date DESC, c.created_at DESC LIMIT 20";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($param_types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            // Calculate age
            if ($row['date_of_birth']) {
                $dob = new DateTime($row['date_of_birth']);
                $now = new DateTime();
                $row['age'] = $now->diff($dob)->y;
            } else {
                $row['age'] = null;
            }
            
            $recent_consultations[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching consultations: " . $e->getMessage());
}

// Include sidebar based on role
$sidebar_file = '';
switch ($employee_role) {
    case 'admin':
        $sidebar_file = $root_path . '/includes/sidebar_admin.php';
        break;
    case 'doctor':
        $sidebar_file = $root_path . '/includes/sidebar_doctor.php';
        break;
    case 'nurse':
        $sidebar_file = $root_path . '/includes/sidebar_nurse.php';
        break;
    default:
        $sidebar_file = $root_path . '/includes/sidebar_employee.php';
        break;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Clinical Encounter Management | CHO Koronadal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="../../assets/css/sidebar.css" />
    <link rel="stylesheet" href="../../assets/css/dashboard.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        .consultation-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #28a745;
            transition: all 0.3s ease;
        }
        
        .consultation-card:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            transform: translateY(-2px);
        }
        
        .consultation-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        
        .consultation-id {
            background: #e3f2fd;
            color: #1976d2;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: bold;
            font-size: 0.9rem;
        }
        
        .consultation-status {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-draft {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-in_progress {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .status-completed {
            background: #d4edda;
            color: #155724;
        }
        
        .status-follow_up_required {
            background: #f8d7da;
            color: #721c24;
        }
        
        .patient-info {
            margin-bottom: 1rem;
        }
        
        .patient-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }
        
        .patient-details {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .consultation-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            font-size: 0.9rem;
            color: #333;
        }
        
        .consultation-actions {
            margin-top: 1rem;
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background: #0056b3;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #1e7e34;
        }
        
        .btn-info {
            background: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background: #117a8b;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .stat-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #333;
        }
        
        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #dee2e6;
        }
        
        @media (max-width: 768px) {
            .consultation-info {
                grid-template-columns: 1fr;
            }
            
            .consultation-header {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="homepage">
        <?php if (file_exists($sidebar_file)) include $sidebar_file; ?>

        <section class="main-content">
            <div class="page-header">
                <h1><i class="fas fa-stethoscope"></i> Clinical Encounter Management</h1>
                <p>Manage patient consultations, vitals, and clinical encounters</p>
            </div>

            <!-- Stats Overview -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon" style="color: #28a745;">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-number"><?= count($recent_consultations) ?></div>
                    <div class="stat-label">Recent Consultations</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="color: #007bff;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-number">
                        <?= count(array_unique(array_column($recent_consultations, 'patient_id'))) ?>
                    </div>
                    <div class="stat-label">Patients Seen</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="color: #ffc107;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-number">
                        <?= count(array_filter($recent_consultations, function($c) { return $c['consultation_status'] === 'in_progress'; })) ?>
                    </div>
                    <div class="stat-label">In Progress</div>
                </div>
                
                <div class="stat-card">
                    <div class="stat-icon" style="color: #17a2b8;">
                        <i class="fas fa-heartbeat"></i>
                    </div>
                    <div class="stat-number">
                        <?= count(array_filter($recent_consultations, function($c) { return !empty($c['vitals_id']); })) ?>
                    </div>
                    <div class="stat-label">With Vitals</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="section-card">
                <h3><i class="fas fa-plus-circle"></i> Quick Actions</h3>
                <div style="display: flex; gap: 1rem; flex-wrap: wrap; margin-top: 1rem;">
                    <a href="new_consultation_standalone.php" class="btn btn-success">
                        <i class="fas fa-plus"></i> New Consultation
                    </a>
                    
                    <?php if (in_array($employee_role, ['admin', 'doctor'])): ?>
                    <a href="consultation.php" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search Consultations
                    </a>
                    <?php endif; ?>
                    
                    <?php if (in_array($employee_role, ['admin', 'nurse', 'doctor'])): ?>
                    <button class="btn btn-info" onclick="refreshConsultations()">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Consultations -->
            <div class="section-card">
                <h3><i class="fas fa-history"></i> Recent Consultations</h3>
                
                <?php if (empty($recent_consultations)): ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h4>No consultations found</h4>
                        <p>No recent consultations to display. Start by creating a new consultation.</p>
                        <a href="new_consultation_standalone.php" class="btn btn-success" style="margin-top: 1rem;">
                            <i class="fas fa-plus"></i> Create First Consultation
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_consultations as $consultation): ?>
                        <div class="consultation-card">
                            <div class="consultation-header">
                                <div class="consultation-id">
                                    ID: <?= htmlspecialchars($consultation['consultation_id']) ?>
                                </div>
                                <div class="consultation-status status-<?= $consultation['consultation_status'] ?>">
                                    <?= ucwords(str_replace('_', ' ', $consultation['consultation_status'])) ?>
                                </div>
                            </div>
                            
                            <div class="patient-info">
                                <div class="patient-name">
                                    <?= htmlspecialchars($consultation['full_name']) ?>
                                </div>
                                <div class="patient-details">
                                    ID: <?= htmlspecialchars($consultation['patient_code']) ?> |
                                    <?= $consultation['age'] ? $consultation['age'] . ' years' : 'Age unknown' ?> |
                                    <?= htmlspecialchars($consultation['sex']) ?> |
                                    <?= htmlspecialchars($consultation['barangay_name'] ?? 'Unknown barangay') ?>
                                </div>
                            </div>
                            
                            <div class="consultation-info">
                                <div class="info-item">
                                    <div class="info-label">Chief Complaint</div>
                                    <div class="info-value">
                                        <?= htmlspecialchars(
                                            $consultation['chief_complaint'] 
                                                ? (strlen($consultation['chief_complaint']) > 100 
                                                    ? substr($consultation['chief_complaint'], 0, 100) . '...' 
                                                    : $consultation['chief_complaint'])
                                                : 'Not recorded'
                                        ) ?>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Consultation Date</div>
                                    <div class="info-value">
                                        <?= date('M j, Y g:i A', strtotime($consultation['consultation_date'])) ?>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Consulted By</div>
                                    <div class="info-value">
                                        <?= htmlspecialchars($consultation['consulted_by_full_name'] ?? 'Not assigned') ?>
                                    </div>
                                </div>
                                
                                <div class="info-item">
                                    <div class="info-label">Vitals Status</div>
                                    <div class="info-value">
                                        <?php if ($consultation['vitals_id']): ?>
                                            <span style="color: #28a745;">
                                                <i class="fas fa-check"></i> 
                                                Recorded (<?= date('M j', strtotime($consultation['vitals_date'])) ?>)
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #dc3545;">
                                                <i class="fas fa-times"></i> Not recorded
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="consultation-actions">
                                <a href="view_consultation.php?id=<?= $consultation['consultation_id'] ?>" class="btn-sm btn-info">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                
                                <?php if ($consultation['consultation_status'] !== 'completed' && 
                                         (in_array($employee_role, ['admin']) || $consultation['consulted_by'] == $employee_id)): ?>
                                    <a href="edit_consultation.php?id=<?= $consultation['consultation_id'] ?>" class="btn-sm btn-primary">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($consultation['consultation_status'] === 'completed' && in_array($employee_role, ['admin', 'doctor'])): ?>
                                    <a href="consultation_actions/issue_prescription.php?consultation_id=<?= $consultation['consultation_id'] ?>" class="btn-sm btn-success">
                                        <i class="fas fa-prescription-bottle"></i> Prescribe
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <script>
        function refreshConsultations() {
            location.reload();
        }
        
        // Auto-refresh every 5 minutes
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                refreshConsultations();
            }
        }, 300000);
    </script>
</body>

</html>