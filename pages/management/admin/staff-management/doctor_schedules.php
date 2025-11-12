<?php
// pages/management/admin/staff-management/doctor_schedules.php
// Admin interface for managing doctor schedules and auto-generating time slots

// Start output buffering to prevent header issues
ob_start();

// Include path resolution and security configuration first
$root_path = dirname(dirname(dirname(dirname(__DIR__))));
require_once $root_path . '/config/production_security.php';
require_once $root_path . '/config/session/employee_session.php';

// Add cache-busting headers
if (!headers_sent()) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('CF-Analytics: false');
}

// Check if user is logged in
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    error_log('Redirecting to employee_login from doctor_schedules.php');
    header('Location: /pages/management/auth/employee_login.php');
    exit();
}

// Check if role is authorized for admin functions
if (strtolower($_SESSION['role']) !== 'admin') {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Location: ../dashboard.php');
    exit();
}

require_once $root_path . '/config/db.php';

// Set active page for sidebar navigation
$activePage = 'doctor_schedules';

// Simple input sanitization function
if (!function_exists('sanitize_input')) {
    function sanitize_input($input) {
        if (is_string($input)) {
            return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
        }
        return $input;
    }
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['create_schedule'])) {
            // Create doctor schedule
            $doctor_id = (int) $_POST['doctor_id'];
            $days = $_POST['days'] ?? [];
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];
            $slot_interval = (int) $_POST['slot_interval_minutes'];
            $max_patients = (int) $_POST['max_patients_per_day'];

            foreach ($days as $day) {
                // Check if schedule already exists
                $stmt = $pdo->prepare("SELECT schedule_id FROM doctor_schedule WHERE doctor_id = ? AND day_of_week = ? AND is_active = 1");
                $stmt->execute([$doctor_id, $day]);
                
                if ($stmt->fetch()) {
                    $error = "Schedule already exists for this doctor on {$day}";
                    continue;
                }

                // Create new schedule
                $stmt = $pdo->prepare("
                    INSERT INTO doctor_schedule 
                    (doctor_id, day_of_week, start_time, end_time, slot_interval_minutes, max_patients_per_day, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$doctor_id, $day, $start_time, $end_time, $slot_interval, $max_patients]);
            }
            
            if (!$error) {
                $message = "Doctor schedule created successfully for " . count($days) . " days";
            }

        } elseif (isset($_POST['generate_slots'])) {
            // Generate time slots
            $weeks_ahead = (int) $_POST['weeks_ahead'];
            
            // Get all active schedules
            $stmt = $pdo->query("
                SELECT ds.*, e.first_name, e.last_name 
                FROM doctor_schedule ds 
                JOIN employees e ON ds.doctor_id = e.employee_id 
                WHERE ds.is_active = 1
            ");
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $total_slots_created = 0;
            
            foreach ($schedules as $schedule) {
                // Generate slots for this schedule
                $start_date = new DateTime();
                $end_date = (new DateTime())->add(new DateInterval("P{$weeks_ahead}W"));
                
                $current_date = clone $start_date;
                
                while ($current_date <= $end_date) {
                    $day_name = $current_date->format('l'); // Full day name
                    
                    if ($day_name == $schedule['day_of_week']) {
                        $date_str = $current_date->format('Y-m-d');
                        
                        // Check if slots already exist for this date
                        $stmt = $pdo->prepare("
                            SELECT COUNT(*) as existing_count 
                            FROM doctor_schedule_slots 
                            WHERE schedule_id = ? AND slot_date = ?
                        ");
                        $stmt->execute([$schedule['schedule_id'], $date_str]);
                        $existing = $stmt->fetch(PDO::FETCH_ASSOC)['existing_count'];
                        
                        if ($existing == 0) {
                            // Generate time slots
                            $start_time = new DateTime($schedule['start_time']);
                            $end_time = new DateTime($schedule['end_time']);
                            $interval = new DateInterval('PT' . $schedule['slot_interval_minutes'] . 'M');
                            
                            $current_time = $start_time;
                            
                            while ($current_time < $end_time) {
                                $time_str = $current_time->format('H:i:s');
                                
                                $stmt = $pdo->prepare("
                                    INSERT INTO doctor_schedule_slots 
                                    (schedule_id, slot_date, slot_time, is_booked) 
                                    VALUES (?, ?, ?, 0)
                                ");
                                $stmt->execute([$schedule['schedule_id'], $date_str, $time_str]);
                                
                                $current_time->add($interval);
                                $total_slots_created++;
                            }
                        }
                    }
                    
                    $current_date->add(new DateInterval('P1D'));
                }
            }
            
            $message = "Generated {$total_slots_created} time slots for the next {$weeks_ahead} weeks";

        } elseif (isset($_POST['delete_schedule'])) {
            // Deactivate schedule
            $schedule_id = (int) $_POST['schedule_id'];
            $stmt = $pdo->prepare("UPDATE doctor_schedule SET is_active = 0 WHERE schedule_id = ?");
            $stmt->execute([$schedule_id]);
            $message = "Doctor schedule deactivated successfully";
        }

    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Get doctors for dropdown
$stmt = $pdo->query("
    SELECT e.employee_id, e.first_name, e.last_name 
    FROM employees e 
    WHERE e.role_id = 2 AND e.status = 'active'
    ORDER BY e.last_name
");
$doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current schedules
$stmt = $pdo->query("
    SELECT ds.*, e.first_name, e.last_name
    FROM doctor_schedule ds
    JOIN employees e ON ds.doctor_id = e.employee_id
    WHERE ds.is_active = 1
    ORDER BY e.last_name, ds.day_of_week
");
$current_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stmt = $pdo->query("SELECT COUNT(*) as schedule_count FROM doctor_schedule WHERE is_active = 1");
$schedule_count = $stmt->fetch(PDO::FETCH_ASSOC)['schedule_count'];

$stmt = $pdo->query("SELECT COUNT(*) as slot_count FROM doctor_schedule_slots WHERE slot_date >= CURDATE()");
$future_slot_count = $stmt->fetch(PDO::FETCH_ASSOC)['slot_count'];

$stmt = $pdo->query("SELECT COUNT(*) as available_slots FROM doctor_schedule_slots WHERE slot_date >= CURDATE() AND is_booked = 0");
$available_slots = $stmt->fetch(PDO::FETCH_ASSOC)['available_slots'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Schedule Management - CHO Koronadal</title>
    <link rel="stylesheet" href="../../../../assets/css/sidebar.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        /* Main content styling */
        .main-content {
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            min-height: 100vh;
        }

        .breadcrumb {
            background: none;
            padding: 0;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .breadcrumb a {
            color: #6c757d;
            text-decoration: none;
            margin-right: 8px;
        }

        .breadcrumb a:hover {
            color: #007bff;
        }

        .breadcrumb i {
            margin: 0 8px;
            color: #6c757d;
        }

        .page-header {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 8px 25px rgba(0, 123, 255, 0.15);
        }

        .page-header h1 {
            margin: 0;
            font-size: 1.8rem;
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .page-header h1 i {
            margin-right: 15px;
            font-size: 2rem;
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-left: 4px solid #007bff;
        }

        .stat-card h3 {
            margin: 0;
            font-size: 2rem;
            color: #007bff;
            font-weight: 700;
        }

        .stat-card p {
            margin: 5px 0 0 0;
            color: #6c757d;
            font-weight: 500;
        }

        .card-container {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .section-header {
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .section-header h4 {
            margin: 0;
            color: #495057;
            font-weight: 600;
            display: flex;
            align-items: center;
        }

        .section-header h4 i {
            margin-right: 10px;
            color: #007bff;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-control:focus {
            outline: 0;
            border-color: #80bdff;
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
        }

        .checkbox-item input[type="checkbox"] {
            margin-right: 8px;
            transform: scale(1.2);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn i {
            margin-right: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, #28a745, #1e7e34);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }

        .table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: white;
        }

        .badge-success {
            background: linear-gradient(135deg, #28a745, #1e7e34);
        }

        .badge-primary {
            background: linear-gradient(135deg, #007bff, #0056b3);
        }

        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }

        .alert-success {
            background-color: #d4edda;
            border-left-color: #28a745;
            color: #155724;
        }

        .alert-danger {
            background-color: #f8d7da;
            border-left-color: #dc3545;
            color: #721c24;
        }

        .alert i {
            margin-right: 8px;
        }

        @media (max-width: 768px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .checkbox-group {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <!-- Include sidebar -->
    <?php include '../../../../includes/sidebar_admin.php'; ?>
    
    <div class="homepage">
        <div class="main-content">
            <!-- Breadcrumb Navigation -->
            <div class="breadcrumb" style="margin-top: 50px;">
                <a href="../dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <span>Doctor Schedules</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <h1><i class="fas fa-calendar-alt"></i> Doctor Schedule Management</h1>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-container">
                <div class="stat-card">
                    <h3><?php echo $schedule_count; ?></h3>
                    <p>Active Schedules</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo $future_slot_count; ?></h3>
                    <p>Future Time Slots</p>
                </div>
                <div class="stat-card">
                    <h3><?php echo $available_slots; ?></h3>
                    <p>Available Slots</p>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Create Doctor Schedule -->
            <div class="card-container">
                <div class="section-header">
                    <h4><i class="fas fa-plus-circle"></i> Create Doctor Schedule</h4>
                </div>
                
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label" for="doctor_id">Select Doctor:</label>
                        <select name="doctor_id" id="doctor_id" class="form-control" required>
                            <option value="">-- Select Doctor --</option>
                            <?php foreach ($doctors as $doctor): ?>
                                <option value="<?php echo $doctor['employee_id']; ?>">
                                    Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Working Days:</label>
                        <div class="checkbox-group">
                            <?php 
                            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                            foreach ($days as $day): 
                            ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="days[]" value="<?php echo $day; ?>" id="<?php echo $day; ?>" 
                                           <?php echo in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']) ? 'checked' : ''; ?>>
                                    <label for="<?php echo $day; ?>"><?php echo $day; ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                        <div class="form-group">
                            <label class="form-label" for="start_time">Start Time:</label>
                            <input type="time" name="start_time" id="start_time" class="form-control" value="08:00" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="end_time">End Time:</label>
                            <input type="time" name="end_time" id="end_time" class="form-control" value="17:00" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="slot_interval_minutes">Slot Interval (minutes):</label>
                            <input type="number" name="slot_interval_minutes" id="slot_interval_minutes" class="form-control" value="30" min="5" max="120" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="max_patients_per_day">Max Patients per Day:</label>
                            <input type="number" name="max_patients_per_day" id="max_patients_per_day" class="form-control" value="20" min="1" max="100" required>
                        </div>
                    </div>

                    <div class="action-buttons">
                        <button type="submit" name="create_schedule" class="btn btn-primary">
                            <i class="fas fa-save"></i> Create Schedule
                        </button>
                    </div>
                </form>
            </div>

            <!-- Generate Time Slots -->
            <div class="card-container">
                <div class="section-header">
                    <h4><i class="fas fa-cogs"></i> Generate Time Slots</h4>
                </div>
                
                <form method="POST">
                    <div class="form-group">
                        <label class="form-label" for="weeks_ahead">Generate slots for next:</label>
                        <select name="weeks_ahead" id="weeks_ahead" class="form-control" style="max-width: 200px;" required>
                            <option value="1">1 week</option>
                            <option value="2" selected>2 weeks</option>
                            <option value="4">4 weeks</option>
                            <option value="8">8 weeks</option>
                        </select>
                    </div>

                    <div class="action-buttons">
                        <button type="submit" name="generate_slots" class="btn btn-success">
                            <i class="fas fa-sync-alt"></i> Generate Slots
                        </button>
                    </div>
                </form>
            </div>

            <!-- Current Schedules -->
            <div class="card-container">
                <div class="section-header">
                    <h4><i class="fas fa-list"></i> Current Doctor Schedules</h4>
                </div>
                
                <?php if (!empty($current_schedules)): ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Doctor</th>
                                <th>Day</th>
                                <th>Hours</th>
                                <th>Slot Interval</th>
                                <th>Max Patients/Day</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($current_schedules as $schedule): ?>
                                <tr>
                                    <td>Dr. <?php echo htmlspecialchars($schedule['first_name'] . ' ' . $schedule['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($schedule['day_of_week']); ?></td>
                                    <td><?php echo date('g:i A', strtotime($schedule['start_time'])) . ' - ' . date('g:i A', strtotime($schedule['end_time'])); ?></td>
                                    <td><?php echo $schedule['slot_interval_minutes']; ?> minutes</td>
                                    <td><?php echo $schedule['max_patients_per_day']; ?></td>
                                    <td><span class="badge badge-success">Active</span></td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="schedule_id" value="<?php echo $schedule['schedule_id']; ?>">
                                            <button type="submit" name="delete_schedule" class="btn btn-danger" style="padding: 6px 12px; font-size: 12px;"
                                                    onclick="return confirm('Are you sure you want to deactivate this schedule?')">
                                                <i class="fas fa-trash"></i> Deactivate
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px 0; color: #6c757d;">
                        <i class="fas fa-calendar-times" style="font-size: 3rem; margin-bottom: 20px;"></i>
                        <h5>No Doctor Schedules Found</h5>
                        <p>Create a doctor schedule to get started with appointment management.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>