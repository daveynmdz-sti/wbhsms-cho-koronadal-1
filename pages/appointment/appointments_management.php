<?php
// appointments_management.php - Admin Side
ob_start(); // Start output buffering to prevent any accidental output

// Load environment configuration for production-friendly error handling
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/env.php';
require_once $root_path . '/config/production_security.php';

// Set error reporting based on environment
if (getenv('APP_DEBUG') === '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// Security headers
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration - Use absolute path resolution
require_once $root_path . '/config/session/employee_session.php';

// If user is not logged in, bounce to login
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    if (ob_get_level()) {
        ob_end_clean(); // Clear buffer before redirect
    }
    header('Location: ../auth/employee_login.php');
    exit();
}

// Check if role is authorized
$authorized_roles = ['admin', 'dho', 'bhw', 'doctor', 'nurse', 'records_officer'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    if (ob_get_level()) {
        ob_end_clean(); // Clear buffer before redirect
    }
    // Redirect to role-specific dashboard
    $user_role = strtolower($_SESSION['role']);
    switch ($user_role) {
        case 'dho':
            $dashboard_path = '../management/dho/dashboard.php';
            break;
        case 'bhw':
            $dashboard_path = '../management/bhw/dashboard.php';
            break;
        case 'doctor':
            $dashboard_path = '../management/doctor/dashboard.php';
            break;
        case 'nurse':
            $dashboard_path = '../management/nurse/dashboard.php';
            break;
        case 'records_officer':
            $dashboard_path = '../management/records_officer/dashboard.php';
            break;
        default:
            $dashboard_path = '../dashboard.php';
            break;
    }
    header("Location: $dashboard_path");
    exit();
}

// Database connection
require_once $root_path . '/config/db.php';
// Use dynamic path resolution for assets
require_once $root_path . '/config/paths.php';
$assets_path = defined('WBHSMS_BASE_URL') ? WBHSMS_BASE_URL . '/assets' : 'assets';

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed. Please contact administrator.");
}

$employee_id = $_SESSION['employee_id'];
$employee_role = $_SESSION['role'];

// Get facility name for the logged-in employee
$facility_name = 'CHO Koronadal'; // Default fallback
try {
    $stmt = $conn->prepare("SELECT f.name FROM employees e JOIN facilities f ON e.facility_id = f.facility_id WHERE e.employee_id = ?");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row) {
        $facility_name = $row['name'];
    }
    $stmt->close();
} catch (Exception $e) {
    // Keep default facility name if query fails
}

// Define role-based permissions for appointments management
$canCancelAppointments = !in_array(strtolower($employee_role), ['records_officer']); // Records officers cannot cancel
$canEditAppointments = !in_array(strtolower($employee_role), ['records_officer']); // Records officers cannot edit
$canViewAppointments = true; // All authorized roles can view
$canAddVitals = in_array(strtolower($employee_role), ['admin', 'doctor', 'nurse', 'dho', 'bhw']); // Healthcare providers can add vitals

// Handle status updates and actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $appointment_id = $_POST['appointment_id'] ?? '';

    if ($action === 'cancel_appointment' && !empty($appointment_id) && is_numeric($appointment_id)) {
        $cancel_reason = $_POST['cancel_reason'] ?? '';
        $other_reason = $_POST['other_reason'] ?? '';
        $employee_password = $_POST['employee_password'] ?? '';

        // Handle the final reason based on selection
        $final_reason = '';
        if ($cancel_reason === 'Others') {
            if (empty($other_reason)) {
                $error = "Please specify the reason for cancellation.";
            } else {
                $final_reason = trim($other_reason);
                if (strlen($final_reason) < 5) {
                    $error = "Please provide a more detailed reason (at least 5 characters).";
                } else if (strlen($final_reason) > 60) {
                    $error = "Reason is too long (maximum 60 characters).";
                } else {
                    $final_reason = "Others: " . $final_reason;
                }
            }
        } else {
            $final_reason = $cancel_reason;
        }

        if (empty($cancel_reason) || empty($employee_password)) {
            $error = "Cancel reason and password are required.";
        } else if (empty($final_reason)) {
            // Error already set above for other reason validation
        } else {
            try {
                // Verify employee password
                $stmt = $conn->prepare("SELECT password FROM employees WHERE employee_id = ?");
                $stmt->bind_param("i", $employee_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $employee = $result->fetch_assoc();

                if (!$employee || !password_verify($employee_password, $employee['password'])) {
                    $error = "Invalid password. Please try again.";
                } else {
                    // Update appointment status to cancelled
                    $stmt = $conn->prepare("UPDATE appointments SET status = 'cancelled', cancellation_reason = ? WHERE appointment_id = ?");
                    $stmt->bind_param("si", $final_reason, $appointment_id);
                    if ($stmt->execute()) {
                        // Log the cancellation
                        $log_stmt = $conn->prepare("INSERT INTO appointment_logs (appointment_id, patient_id, action, old_status, new_status, reason, created_by_type, created_by_id) VALUES (?, (SELECT patient_id FROM appointments WHERE appointment_id = ?), 'cancelled', 'confirmed', 'cancelled', ?, 'employee', ?)");
                        $log_stmt->bind_param("iisi", $appointment_id, $appointment_id, $final_reason, $employee_id);
                        $log_stmt->execute();

                        $message = "Appointment cancelled successfully.";
                    } else {
                        $error = "Failed to cancel appointment. Please try again.";
                    }
                }
                $stmt->close();
            } catch (Exception $e) {
                $error = "An error occurred: " . $e->getMessage();
            }
        }
    }

    if ($action === 'checkin_appointment' && !empty($appointment_id) && is_numeric($appointment_id)) {
        try {
            // Start transaction
            $conn->begin_transaction();

            // Get appointment details
            $apt_stmt = $conn->prepare("SELECT a.*, p.first_name, p.last_name FROM appointments a JOIN patients p ON a.patient_id = p.patient_id WHERE a.appointment_id = ? AND a.status = 'confirmed'");
            $apt_stmt->bind_param("i", $appointment_id);
            $apt_stmt->execute();
            $appointment_result = $apt_stmt->get_result();
            $appointment_data = $appointment_result->fetch_assoc();

            if (!$appointment_data) {
                throw new Exception("Appointment not found or not in confirmed status.");
            }

            // Update appointment status to checked_in
            $update_stmt = $conn->prepare("UPDATE appointments SET status = 'checked_in' WHERE appointment_id = ?");
            $update_stmt->bind_param("i", $appointment_id);
            $update_stmt->execute();

            // Create new visit record
            $visit_stmt = $conn->prepare("INSERT INTO visits (patient_id, facility_id, appointment_id, visit_date, time_in, attending_employee_id, visit_status, attendance_status) VALUES (?, ?, ?, CURDATE(), NOW(), ?, 'ongoing', 'on_time')");
            $visit_stmt->bind_param("iiii", $appointment_data['patient_id'], $appointment_data['facility_id'], $appointment_id, $employee_id);
            $visit_stmt->execute();

            $visit_id = $conn->insert_id;

            // Log the check-in in appointment_logs
            $log_stmt = $conn->prepare("INSERT INTO appointment_logs (appointment_id, patient_id, action, old_status, new_status, reason, created_by_type, created_by_id) VALUES (?, ?, 'updated', 'confirmed', 'checked_in', 'Patient checked in for appointment', 'employee', ?)");
            $log_stmt->bind_param("iii", $appointment_id, $appointment_data['patient_id'], $employee_id);
            $log_stmt->execute();

            $conn->commit();
            $message = "Patient " . htmlspecialchars($appointment_data['first_name'] . ' ' . $appointment_data['last_name']) . " checked in successfully. Visit ID: " . $visit_id;
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to check in patient: " . $e->getMessage();
        }
    }

    if ($action === 'complete_appointment' && !empty($appointment_id) && is_numeric($appointment_id)) {
        try {
            // Start transaction
            $conn->begin_transaction();

            // Get appointment and visit details
            $apt_stmt = $conn->prepare("SELECT a.*, v.visit_id, p.first_name, p.last_name FROM appointments a JOIN patients p ON a.patient_id = p.patient_id LEFT JOIN visits v ON a.appointment_id = v.appointment_id WHERE a.appointment_id = ? AND a.status = 'checked_in'");
            $apt_stmt->bind_param("i", $appointment_id);
            $apt_stmt->execute();
            $appointment_result = $apt_stmt->get_result();
            $appointment_data = $appointment_result->fetch_assoc();

            if (!$appointment_data) {
                throw new Exception("Appointment not found or not in checked-in status.");
            }

            // Update appointment status to completed
            $update_stmt = $conn->prepare("UPDATE appointments SET status = 'completed' WHERE appointment_id = ?");
            $update_stmt->bind_param("i", $appointment_id);
            $update_stmt->execute();

            // Update visit record if exists
            if ($appointment_data['visit_id']) {
                $visit_stmt = $conn->prepare("UPDATE visits SET time_out = NOW(), visit_status = 'completed', attending_employee_id = ? WHERE visit_id = ?");
                $visit_stmt->bind_param("ii", $employee_id, $appointment_data['visit_id']);
                $visit_stmt->execute();
            }

            // Log the completion in appointment_logs
            $log_stmt = $conn->prepare("INSERT INTO appointment_logs (appointment_id, patient_id, action, old_status, new_status, reason, created_by_type, created_by_id) VALUES (?, ?, 'completed', 'checked_in', 'completed', 'Visit completed', 'employee', ?)");
            $log_stmt->bind_param("iii", $appointment_id, $appointment_data['patient_id'], $employee_id);
            $log_stmt->execute();

            $conn->commit();
            $message = "Patient " . htmlspecialchars($appointment_data['first_name'] . ' ' . $appointment_data['last_name']) . " visit completed successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to complete patient visit: " . $e->getMessage();
        }
    }
}

// Get filter parameters  
$date_filter = $_GET['appointment_date'] ?? '';
$barangay_filter = $_GET['barangay'] ?? '';

// Sorting parameters
$sort_column = $_GET['sort'] ?? 'scheduled_date';
$sort_direction = $_GET['dir'] ?? 'DESC';

// Validate sort parameters
$allowed_columns = [
    'patient_name' => 'p.last_name',
    'patient_id' => 'p.username',
    'scheduled_date' => 'a.scheduled_date',
    'scheduled_time' => 'a.scheduled_time',
    'status' => 'a.status',
    'facility_name' => 'f.name',
    'created_at' => 'a.created_at'
];

$sort_column = array_key_exists($sort_column, $allowed_columns) ? $sort_column : 'scheduled_date';
$sort_direction = in_array(strtoupper($sort_direction), ['ASC', 'DESC']) ? strtoupper($sort_direction) : 'ASC';

// Pagination parameters
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = in_array(intval($_GET['per_page'] ?? 10), [10, 25, 50, 100]) ? intval($_GET['per_page'] ?? 10) : 10;
$offset = ($page - 1) * $per_page;

$where_conditions = [];
$params = [];
$param_types = '';

// Role-based appointment restriction
$role = strtolower($employee_role);
$assigned_district = '';
$assigned_facility_id = '';

if ($role === 'dho') {
    // Get district_id from facilities table based on employee's facility_id
    $stmt = $conn->prepare("SELECT f.district_id FROM employees e JOIN facilities f ON e.facility_id = f.facility_id WHERE e.employee_id = ?");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $assigned_district = $row ? $row['district_id'] : '';
    $stmt->close();

    if ($assigned_district) {
        // Only show appointments for facilities in this district
        $where_conditions[] = "f.district_id = ?";
        $params[] = $assigned_district;
        $param_types .= 'i';
    }
}

if ($role === 'bhw') {
    // Get facility_id directly from employee record (BHW is assigned to specific facility)
    $stmt = $conn->prepare("SELECT facility_id FROM employees WHERE employee_id = ?");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $assigned_facility_id = $row ? $row['facility_id'] : '';
    $stmt->close();

    if ($assigned_facility_id) {
        // Only show appointments for this facility
        $where_conditions[] = "a.facility_id = ?";
        $params[] = $assigned_facility_id;
        $param_types .= 'i';
    }
}

// General search filter (patient ID, first name, last name)
$search_filter = $_GET['search'] ?? '';
if (!empty($search_filter)) {
    $where_conditions[] = "(p.username LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)";
    $search_param = '%' . $search_filter . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $param_types .= 'sss';
}

// Status filter
$status_filter = $_GET['status'] ?? '';
if (!empty($status_filter)) {
    $where_conditions[] = "a.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

// Date filter
if (!empty($date_filter)) {
    $where_conditions[] = "DATE(a.scheduled_date) = ?";
    $params[] = $date_filter;
    $param_types .= 's';
} else {
    // Check if any filters are applied to determine if we should show current month only
    $has_active_filters = !empty($search_filter) || !empty($status_filter) || !empty($barangay_filter);
    
    if (!$has_active_filters) {
        // No filters applied - show only current month appointments
        $where_conditions[] = "YEAR(a.scheduled_date) = YEAR(CURDATE()) AND MONTH(a.scheduled_date) = MONTH(CURDATE())";
    }
    // If filters are applied, show all appointments (no date restriction)
}

// Barangay filter (only for nurse, records_officer, dho, admin roles)
if (!empty($barangay_filter) && in_array($role, ['nurse', 'records_officer', 'dho', 'admin'])) {
    $where_conditions[] = "p.barangay_id = ?";
    $params[] = $barangay_filter;
    $param_types .= 'i';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

try {
    // Get total count for pagination
    $count_sql = "
        SELECT COUNT(*) as total
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.patient_id
        LEFT JOIN facilities f ON a.facility_id = f.facility_id
        $where_clause
    ";

    $count_stmt = $conn->prepare($count_sql);
    if (!empty($params) && !empty($param_types)) {
        $count_stmt->bind_param($param_types, ...$params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_records = $count_result->fetch_assoc()['total'];
    $count_stmt->close();

    $total_pages = ceil($total_records / $per_page);

    // Build ORDER BY clause
    $order_by = $allowed_columns[$sort_column] . ' ' . $sort_direction;

    // Add secondary sort for consistency
    if ($sort_column !== 'scheduled_date') {
        $order_by .= ', a.scheduled_date DESC';
    }
    if ($sort_column !== 'scheduled_time' && $sort_column !== 'scheduled_date') {
        $order_by .= ', a.scheduled_time ASC';
    }

    $sql = "
     SELECT a.appointment_id, a.scheduled_date, a.scheduled_time, a.status, a.service_id,
         a.cancellation_reason, a.created_at, a.updated_at, a.referral_id,
         p.patient_id, p.first_name, p.last_name, p.middle_name, p.username as patient_id_display,
         p.contact_number, p.date_of_birth, p.sex,
         f.name as facility_name, f.district as facility_district,
         b.barangay_name,
         s.name as service_name,
         v.vitals_id, v.systolic_bp, v.diastolic_bp, v.heart_rate, v.respiratory_rate,
         v.temperature, v.weight, v.height, v.bmi, v.recorded_at as vitals_recorded_at,
         CONCAT(e.first_name, ' ', e.last_name) as vitals_recorded_by
        FROM appointments a
        LEFT JOIN patients p ON a.patient_id = p.patient_id
        LEFT JOIN facilities f ON a.facility_id = f.facility_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN services s ON a.service_id = s.service_id
        LEFT JOIN visits vis ON a.appointment_id = vis.appointment_id
        LEFT JOIN vitals v ON vis.vitals_id = v.vitals_id
        LEFT JOIN employees e ON v.recorded_by = e.employee_id
        $where_clause
        ORDER BY $order_by
        LIMIT ? OFFSET ?
    ";

    // Add pagination parameters
    $params[] = $per_page;
    $params[] = $offset;
    $param_types .= 'ii';

    $stmt = $conn->prepare($sql);
    if (!empty($params) && !empty($param_types)) {
        $stmt->bind_param($param_types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $appointments = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    $error = "Failed to fetch appointments: " . $e->getMessage();
    $appointments = [];
    $total_records = 0;
    $total_pages = 0;
}

// Get statistics
$stats = [
    'total' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'checked_in' => 0
];

try {
    $stats_where_conditions = [];
    $stats_params = [];
    $stats_types = '';

    // Apply same role-based restrictions to stats
    if ($role === 'dho' && !empty($assigned_district)) {
        $stats_where_conditions[] = "f.district_id = ?";
        $stats_params[] = $assigned_district;
        $stats_types .= 'i';
    }

    if ($role === 'bhw' && !empty($assigned_facility_id)) {
        $stats_where_conditions[] = "a.facility_id = ?";
        $stats_params[] = $assigned_facility_id;
        $stats_types .= 'i';
    }

    // Apply search filter to stats
    if (!empty($search_filter)) {
        $stats_where_conditions[] = "(p.username LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)";
        $search_param = '%' . $search_filter . '%';
        $stats_params[] = $search_param;
        $stats_params[] = $search_param;
        $stats_params[] = $search_param;
        $stats_types .= 'sss';
    }

    if (!empty($status_filter)) {
        $stats_where_conditions[] = "a.status = ?";
        $stats_params[] = $status_filter;
        $stats_types .= 's';
    }

    if (!empty($date_filter)) {
        $stats_where_conditions[] = "DATE(a.scheduled_date) = ?";
        $stats_params[] = $date_filter;
        $stats_types .= 's';
    }

    $stats_where = !empty($stats_where_conditions) ? 'WHERE ' . implode(' AND ', $stats_where_conditions) : '';

    // Add current month filter to stats query
    $current_month_condition = "YEAR(a.scheduled_date) = YEAR(CURDATE()) AND MONTH(a.scheduled_date) = MONTH(CURDATE())";
    if (!empty($stats_where)) {
        $stats_where .= " AND " . $current_month_condition;
    } else {
        $stats_where = "WHERE " . $current_month_condition;
    }

    $stmt = $conn->prepare("SELECT a.status, COUNT(*) as count FROM appointments a LEFT JOIN patients p ON a.patient_id = p.patient_id LEFT JOIN facilities f ON a.facility_id = f.facility_id $stats_where GROUP BY a.status");
    if (!empty($stats_params) && !empty($stats_types)) {
        $stmt->bind_param($stats_types, ...$stats_params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (isset($stats[$row['status']])) {
            $stats[$row['status']] = $row['count'];
        }
        $stats['total'] += $row['count'];
    }
    $stmt->close();
} catch (Exception $e) {
    $error = "Failed to fetch statistics: " . $e->getMessage();
}

// Fetch facilities for dropdown (role-based)
$facilities = [];
try {
    if ($role === 'dho' && !empty($assigned_district)) {
        // DHO can only see facilities in their district
        $stmt = $conn->prepare("SELECT facility_id, name, district_id FROM facilities WHERE status = 'active' AND district_id = ? ORDER BY name ASC");
        $stmt->bind_param("i", $assigned_district);
    } elseif ($role === 'bhw' && !empty($assigned_facility_id)) {
        // BHW can only see their assigned facility
        $stmt = $conn->prepare("SELECT facility_id, name, district_id FROM facilities WHERE status = 'active' AND facility_id = ? ORDER BY name ASC");
        $stmt->bind_param("i", $assigned_facility_id);
    } else {
        // Admin and other roles can see all facilities
        $stmt = $conn->prepare("SELECT facility_id, name, district_id FROM facilities WHERE status = 'active' ORDER BY name ASC");
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $facilities = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    $error = "Failed to fetch facilities: " . $e->getMessage();
}

// Fetch barangays for dropdown (role-based) - only for nurse, records_officer, dho, admin
$barangays = [];
if (in_array($role, ['nurse', 'records_officer', 'dho', 'admin'])) {
    try {
        if ($role === 'dho' && !empty($assigned_district)) {
            // DHO can only see barangays in their district
            $stmt = $conn->prepare("SELECT b.barangay_id, b.barangay_name FROM barangay b WHERE b.district_id = ? ORDER BY b.barangay_name ASC");
            $stmt->bind_param("i", $assigned_district);
        } else {
            // Nurse, Records Officer, and Admin can see all barangays
            $stmt = $conn->prepare("SELECT b.barangay_id, b.barangay_name FROM barangay b ORDER BY b.barangay_name ASC");
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $barangays = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } catch (Exception $e) {
        $error = "Failed to fetch barangays: " . $e->getMessage();
    }
}

// Helper function to generate sort icons
function getSortIcon($column, $current_sort, $current_direction)
{
    if ($column === $current_sort) {
        if ($current_direction === 'ASC') {
            return '<i class="fas fa-sort-up sort-icon active"></i>';
        } else {
            return '<i class="fas fa-sort-down sort-icon active"></i>';
        }
    }
    return '<i class="fas fa-sort sort-icon"></i>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <base href="<?php echo WBHSMS_BASE_URL; ?>/">
    <title><?php echo htmlspecialchars($facility_name); ?> — Appointments</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo WBHSMS_BASE_URL; ?>/assets/css/sidebar.css">
    <!-- CSS Files - loaded by sidebar -->
    <style>
        .content-wrapper {
            padding: 2rem;
            transition: margin-left 0.3s;
        }

        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
                padding: 1rem;
            }
        }

        /* Vitals Button Styling */
        .btn.btn-success.vitals-recorded {
            background: linear-gradient(135deg, #28a745, #20c997);
            border-color: #28a745;
            position: relative;
            overflow: hidden;
        }

        .btn.btn-success.vitals-recorded::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn.btn-success.vitals-recorded:hover::before {
            left: 100%;
        }

        .btn.btn-success.vitals-recorded:hover {
            background: linear-gradient(135deg, #218838, #1cc88a);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }

        .btn.btn-warning.vitals-pending {
            background: linear-gradient(135deg, #ffc107, #fd7e14);
            border-color: #ffc107;
            animation: pulse-warning 2s ease-in-out infinite;
        }

        @keyframes pulse-warning {
            0% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7); }
            70% { box-shadow: 0 0 0 6px rgba(255, 193, 7, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); }
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .page-header h1 {
            color: #0077b6;
            margin: 0;
            font-size: 1.8rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0 15px 0;
            margin-bottom: 15px;
            border-bottom: 1px solid rgba(0, 119, 182, 0.2);
        }

        .card-container {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
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
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card.total {
            border-left: 4px solid #0077b6;
        }

        .stat-card.confirmed {
            border-left: 4px solid #43e97b;
        }

        .stat-card.completed {
            border-left: 4px solid #4facfe;
        }

        .stat-card.cancelled {
            border-left: 4px solid #fa709a;
        }

        .stat-card.no_show {
            border-left: 4px solid #ffba08;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filters-container {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #0077b6;
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select {
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #023e8a, #001d3d);
            transform: translateY(-2px);
        }

        .btn-danger {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-info:hover {
            background: linear-gradient(135deg, #138496 0%, #0f6674 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
        }

        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #1e7e34 0%, #155724 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268 0%, #495057 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            /*border-left: 4px solid #0077b6;*/
            overflow: hidden;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .table th,
        .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .table th {
            background: linear-gradient(135deg, #0077b6, #03045e);
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table th.sortable {
            cursor: pointer;
            user-select: none;
            transition: background-color 0.3s ease;
        }

        .table th.sortable:hover {
            background: linear-gradient(135deg, #005577, #001d3d);
        }

        .sort-icon {
            margin-left: 5px;
            opacity: 0.6;
            font-size: 12px;
            transition: opacity 0.3s ease;
        }

        .sort-icon.active {
            opacity: 1;
            color: #ffd700;
        }

        .table th.sortable:hover .sort-icon {
            opacity: 0.9;
        }

        .table tbody tr:hover {
            background-color: rgba(240, 247, 255, 0.6);
        }

        /* Mobile responsive adjustments */
        @media (max-width: 768px) {
            .table-wrapper {
                font-size: 14px;
            }

            .table th,
            .table td {
                padding: 8px 10px;
            }

            .table th {
                font-size: 12px;
            }

            .btn-sm {
                padding: 4px 8px;
                font-size: 11px;
            }

            .stat-number {
                font-size: 1.5rem;
            }

            .page-header h1 {
                font-size: 1.4rem;
            }
        }

        /* Extra small screens */
        @media (max-width: 480px) {
            .content-wrapper {
                padding: 10px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .stat-card {
                padding: 15px 10px;
            }
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-primary {
            background-color: #0077b6;
            color: white;
        }

        .badge-success {
            background-color: #28a745;
            color: white;
        }

        .badge-info {
            background-color: #17a2b8;
            color: white;
        }

        .badge-warning {
            background-color: #ffc107;
            color: #212529;
        }

        .badge-danger {
            background-color: #dc3545;
            color: white;
        }

        .badge-secondary {
            background-color: #6c757d;
            color: white;
        }

        .actions-group {
            display: flex;
            gap: 5px;
        }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            background: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            z-index: 1050;
        }

        .alert-success {
            border-left-color: #28a745;
            color: #155724;
        }

        .alert-error {
            border-left-color: #dc3545;
            color: #721c24;
        }

        /* Dynamic alerts that appear on top of modals */
        .alert.alert-dynamic {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1100;
            min-width: 300px;
            max-width: 600px;
            margin: 0;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            animation: slideInFromTop 0.3s ease-out;
        }

        @keyframes slideInFromTop {
            from {
                opacity: 0;
                transform: translate(-50%, -20px);
            }

            to {
                opacity: 1;
                transform: translate(-50%, 0);
            }
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(3px);
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
        }

        .modal.show,
        .modal[style*="block"] {
            display: flex !important;
        }

        .modal-content {
            background-color: #ffffff;
            margin: 0;
            padding: 0;
            border: none;
            width: 100%;
            max-width: 500px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            border-left: 4px solid #0077b6;
            overflow: hidden;
            animation: modalSlideIn 0.4s ease-out;
            position: relative;
        }

        .modal-content.cancel-modal {
            max-width: 480px;
            border-left: 4px solid #dc3545;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(-20px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px 30px;
            background: linear-gradient(135deg, #0077b6 0%, #005577 100%);
            color: white;
            margin: 0;
            border-bottom: none;
            position: relative;
        }

        .modal-header.cancel-header {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            padding: 20px 30px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            margin: 0;
            border-radius: 0 0 20px 20px;
        }

        .modal-footer .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 100px;
            justify-content: center;
        }

        .modal-footer .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .modal-footer .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }

        .modal-footer .btn-danger {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
        }

        .modal-footer .btn-danger:hover {
            background: linear-gradient(135deg, #c82333, #bd2130);
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(220, 53, 69, 0.4);
        }

        .modal-footer .btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
        }

        @media (max-width: 600px) {
            .modal-content {
                width: 95%;
                max-width: 95%;
                margin: 0;
            }

            .modal {
                padding: 10px;
            }

            .modal-header,
            .modal-footer {
                padding: 20px;
            }

            .modal-body {
                padding: 20px;
            }
        }

        .modal-body {
            padding: 30px;
            line-height: 1.6;
            color: #444;
        }

        .modal-body .form-group {
            margin-bottom: 25px;
        }

        .modal-body .form-group label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #2c3e50;
            font-size: 0.95rem;
            display: block;
        }

        .modal-body .form-group textarea,
        .modal-body .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e1e8ed;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            box-sizing: border-box;
            font-family: inherit;
            resize: vertical;
        }

        .modal-body .form-group textarea:focus,
        .modal-body .form-group input:focus {
            outline: none;
            border-color: #dc3545;
            box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.1);
            background-color: #fff;
        }

        .modal-body .form-group textarea {
            min-height: 100px;
            max-height: 200px;
        }

        .modal-body .warning-text {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 16px;
            margin-top: 20px;
            color: #856404;
            font-size: 0.9rem;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .modal-body .warning-text i {
            color: #f39c12;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .appointment-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .details-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border-left: 3px solid #0077b6;
        }

        .details-section h4 {
            margin: 0 0 15px 0;
            color: #0077b6;
            font-size: 16px;
            font-weight: 600;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .detail-label {
            font-weight: 500;
            color: #6c757d;
            margin-right: 10px;
        }

        .detail-value {
            color: #333;
            text-align: right;
        }

        .detail-value.highlight {
            background: #e3f2fd;
            color: #0277bd;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
        }

        .close {
            color: rgba(255, 255, 255, 0.8);
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            border: none;
            background: none;
            padding: 5px;
            border-radius: 50%;
            transition: all 0.3s ease;
        }

        .close:hover,
        .close:focus {
            color: white;
            background: rgba(255, 255, 255, 0.1);
            transform: rotate(90deg);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #0077b6;
            opacity: 0.3;
        }

        .empty-state h3 {
            color: #0077b6;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
            font-size: 1rem;
        }

        .breadcrumb {
            background: none;
            padding: 0;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #6c757d;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .breadcrumb a {
            color: #0077b6;
            text-decoration: none;
            font-weight: 500;
        }

        .breadcrumb a:hover {
            color: #023e8a;
        }

        .breadcrumb i {
            color: #6c757d;
            font-size: 0.8rem;
        }

        .profile-img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        /* Pagination Styles */
        .pagination-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            background: white;
            border-top: 1px solid #dee2e6;
        }

        .pagination-info {
            display: flex;
            align-items: center;
            gap: 20px;
            font-size: 14px;
            color: #6c757d;
        }

        .records-info {
            font-weight: 500;
            color: #333;
        }

        .records-info strong {
            color: #0077b6;
        }

        .page-size-selector {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-size-selector label {
            font-weight: 500;
            color: #6c757d;
        }

        .page-size-selector select {
            padding: 5px 10px;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            background: white;
        }

        .page-size-selector select:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 2px rgba(0, 119, 182, 0.2);
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 5px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .pagination-btn {
            padding: 8px 12px;
            border: 1px solid #dee2e6;
            background: white;
            color: #0077b6;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
        }

        .pagination-btn:hover:not(.disabled):not(.active) {
            background: #f8f9fa;
            border-color: #0077b6;
            transform: translateY(-1px);
        }

        .pagination-btn.active {
            background: #0077b6;
            color: white;
            border-color: #0077b6;
        }

        .pagination-btn.disabled {
            color: #6c757d;
            cursor: not-allowed;
            opacity: 0.5;
        }

        .pagination-btn.prev,
        .pagination-btn.next {
            padding: 8px 15px;
        }

        .pagination-ellipsis {
            padding: 8px 4px;
            color: #6c757d;
        }

        /* Mobile responsive pagination */
        @media (max-width: 768px) {
            .pagination-container {
                flex-direction: column;
                gap: 15px;
                padding: 15px;
            }

            .pagination-info {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .pagination-controls {
                flex-wrap: wrap;
                justify-content: center;
            }
        }

        @media (max-width: 480px) {
            .pagination-btn {
                padding: 6px 8px;
                font-size: 12px;
                min-width: 32px;
            }

            .pagination-btn.prev,
            .pagination-btn.next {
                padding: 6px 10px;
            }
        }

        /* Time slot styling */
        .time-slot {
            background: #e3f2fd;
            color: #0277bd;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        /* Loader animation */
        .loader {
            border: 4px solid rgba(0, 119, 182, 0.2);
            border-radius: 50%;
            border-top: 4px solid #0077b6;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
            display: block;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Logout Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
        }

        .modal-overlay .modal-content {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            animation: modalSlideIn 0.3s ease-out;
        }

        .modal-overlay .modal-content h2 {
            margin: 0 0 1rem 0;
            color: #dc3545;
            font-size: 1.5rem;
        }

        .modal-overlay .modal-content p {
            margin: 0 0 2rem 0;
            color: #6c757d;
            font-size: 1rem;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }

        .modal-actions .btn {
            padding: 0.5rem 1.5rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .modal-actions .btn-danger {
            background: #dc3545;
            color: white;
        }

        .modal-actions .btn-danger:hover {
            background: #c82333;
        }

        .modal-actions .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .modal-actions .btn-secondary:hover {
            background: #545b62;
        }

        /* Vitals Form Styling */
        .vitals-section {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 12px;
        }

        .vitals-section .form-group {
            margin-bottom: 10px;
        }

        .vitals-section .form-group:last-child {
            margin-bottom: 0;
        }

        .vitals-section .form-control {
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 6px 10px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .vitals-section .form-control:focus {
            border-color: #0077b6;
            box-shadow: 0 0 0 2px rgba(0, 119, 182, 0.2);
            outline: none;
        }

        .vitals-section label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 4px;
            display: block;
            font-size: 13px;
        }

        #bmiDisplay {
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Success alert styling */
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        /* Button styling for vitals */
        .btn-warning {
            background: #ffc107;
            border-color: #ffc107;
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
            border-color: #d39e00;
        }

        /* Vital Signs Status Indicators */
        .vital-status {
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 11px !important;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .vital-status.normal {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .vital-status.high {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .vital-status.low {
            background: #ffeaa7;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .vital-status.critical {
            background: #dc3545;
            color: white;
            border: 1px solid #dc3545;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                opacity: 1;
            }

            50% {
                opacity: 0.7;
            }

            100% {
                opacity: 1;
            }
        }
    </style>
</head>

<body>
    <div class="homepage">

        <?php
        $activePage = 'appointments';
        // Include appropriate sidebar based on user role
        switch (strtolower($_SESSION['role'])) {
            case 'dho':
                include $root_path . '/includes/sidebar_dho.php';
                break;
            case 'bhw':
                include $root_path . '/includes/sidebar_bhw.php';
                break;
            case 'doctor':
                include $root_path . '/includes/sidebar_doctor.php';
                break;
            case 'nurse':
                include $root_path . '/includes/sidebar_nurse.php';
                break;
            case 'records_officer':
                include $root_path . '/includes/sidebar_records_officer.php';
                break;
            case 'admin':
            default:
                include $root_path . '/includes/sidebar_admin.php';
                break;
        }
        ?>

        <div class="content-wrapper">
            <!-- Breadcrumb Navigation -->
            <div class="breadcrumb" style="margin-top: 50px;">
                <?php
                // Role-specific dashboard link
                $user_role = strtolower($_SESSION['role']);
                switch ($user_role) {
                    case 'dho':
                        $dashboard_path = '../management/dho/dashboard.php';
                        break;
                    case 'bhw':
                        $dashboard_path = '../management/bhw/dashboard.php';
                        break;
                    case 'doctor':
                        $dashboard_path = '../management/doctor/dashboard.php';
                        break;
                    case 'nurse':
                        $dashboard_path = '../management/nurse/dashboard.php';
                        break;
                    case 'records_officer':
                        $dashboard_path = '../management/records_officer/dashboard.php';
                        break;
                    case 'admin':
                        $dashboard_path = '../management/admin/dashboard.php';
                        break;
                    default:
                        $dashboard_path = '../dashboard.php';
                        break;
                }
                ?>
                <a href="<?php echo $dashboard_path; ?>"><i class="fas fa-home"></i> Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <span><?php echo htmlspecialchars($facility_name); ?> Appointments</span>
            </div>

            <div class="page-header">
                <h1><i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($facility_name); ?> Appointments</h1>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Statistics Notice -->
            <div style="background: #e3f2fd; border: 1px solid #1976d2; border-radius: 8px; padding: 12px 16px; margin-bottom: 1.5rem; color: #0d47a1;">
                <i class="fas fa-info-circle" style="margin-right: 8px; color: #1976d2;"></i>
                <strong>Current Month Statistics:</strong> The statistics below show appointment counts for <?php echo date('F Y'); ?> only.
            </div>

            <!-- Statistics -->
            <div class="stats-grid">
                <!--<div class="stat-card total">
                    <div class="stat-number"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Appointments</div>
                </div>-->
                <div class="stat-card confirmed">
                    <div class="stat-number"><?php echo $stats['confirmed']; ?></div>
                    <div class="stat-label">Confirmed</div>
                </div>
                <div class="stat-card completed">
                    <div class="stat-number"><?php echo $stats['completed']; ?></div>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="stat-card cancelled">
                    <div class="stat-number"><?php echo $stats['cancelled']; ?></div>
                    <div class="stat-label">Cancelled</div>
                </div>
                <div class="stat-card no_show">
                    <div class="stat-number"><?php echo $stats['checked_in']; ?></div>
                    <div class="stat-label">Checked In</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filters-container">
                <div class="section-header" style="margin-bottom: 15px;">
                    <h4 style="margin: 0;color: var(--primary-dark);font-size: 18px;font-weight: 600;">
                        <i class="fas fa-filter"></i> Search &amp; Filter Options
                    </h4>
                </div>
                <form method="GET" class="filters-grid">
                    <div class="form-group">
                        <label for="search">Search Patients</label>
                        <input type="text" name="search" id="search"
                            value="<?php echo htmlspecialchars($search_filter); ?>"
                            placeholder="Search by Patient ID, First Name, or Last Name...">
                    </div>

                    <div class="form-group">
                        <label for="status">Status</label>
                        <select name="status" id="status">
                            <option value="">All Statuses</option>
                            <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                            <option value="checked_in" <?php echo $status_filter === 'checked_in' ? 'selected' : ''; ?>>Checked In</option>
                            <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="appointment_date">Appointment Date</label>
                        <input type="date" name="appointment_date" id="appointment_date"
                            value="<?php echo htmlspecialchars($date_filter); ?>"
                            max="<?php echo date('Y-m-d', strtotime('+1 year')); ?>">
                    </div>

                    <?php if (in_array($role, ['nurse', 'records_officer', 'dho', 'admin'])): ?>
                        <div class="form-group">
                            <label for="barangay">Barangay</label>
                            <select name="barangay" id="barangay">
                                <option value="">All Barangays</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo $barangay['barangay_id']; ?>"
                                        <?php echo $barangay_filter == $barangay['barangay_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div style="display: flex; gap: 10px;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Clear Filters
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Appointments Table -->

            <div class="card-container">
                <div class="section-header">
                    <h4 style="margin: 0;color: var(--primary-dark);font-size: 18px;font-weight: 600;">
                        <i class="fas fa-calendar-check"></i> Appointments
                    </h4>

                </div>
                <div class="table-container">
                    <div class="table-wrapper">
                        <?php if (empty($appointments)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <h3>No appointments found</h3>
                                <p>No appointments match your current filters.</p>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th class="sortable" onclick="sortTable('patient_name')">
                                            Patient
                                            <?php echo getSortIcon('patient_name', $sort_column, $sort_direction); ?>
                                        </th>
                                        <th class="sortable" onclick="sortTable('patient_id')">
                                            Patient ID
                                            <?php echo getSortIcon('patient_id', $sort_column, $sort_direction); ?>
                                        </th>
                                        <th class="sortable" onclick="sortTable('scheduled_date')">
                                            Date
                                            <?php echo getSortIcon('scheduled_date', $sort_column, $sort_direction); ?>
                                        </th>
                                        <th class="sortable" onclick="sortTable('scheduled_time')">
                                            Time Slot
                                            <?php echo getSortIcon('scheduled_time', $sort_column, $sort_direction); ?>
                                        </th>
                                        <th class="sortable" onclick="sortTable('status')">
                                            Status
                                            <?php echo getSortIcon('status', $sort_column, $sort_direction); ?>
                                        </th>
                                        <th class="sortable" onclick="sortTable('facility_name')">
                                            Facility
                                            <?php echo getSortIcon('facility_name', $sort_column, $sort_direction); ?>
                                        </th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($appointments as $appointment): ?>
                                        <tr data-appointment-id="<?php echo $appointment['appointment_id']; ?>">
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 10px;">
                                                    <img src="<?php echo $assets_path; ?>/images/user-default.png"
                                                        alt="Profile" class="profile-img">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($appointment['last_name'] . ', ' . $appointment['first_name']); ?></strong>
                                                        <?php if (!empty($appointment['middle_name'])): ?>
                                                            <br><small><?php echo htmlspecialchars($appointment['middle_name']); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo htmlspecialchars($appointment['patient_id_display']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($appointment['scheduled_date'])); ?></td>
                                            <td>
                                                <span class="time-slot"><?php echo date('g:i A', strtotime($appointment['scheduled_time'])); ?></span>
                                            </td>
                                            <td>
                                                <?php
                                                $badge_class = '';
                                                switch ($appointment['status']) {
                                                    case 'confirmed':
                                                        $badge_class = 'badge-success';
                                                        break;
                                                    case 'completed':
                                                        $badge_class = 'badge-primary';
                                                        break;
                                                    case 'cancelled':
                                                        $badge_class = 'badge-danger';
                                                        break;
                                                    case 'checked_in':
                                                        $badge_class = 'badge-warning';
                                                        break;
                                                    default:
                                                        $badge_class = 'badge-info';
                                                        break;
                                                }
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo ucfirst(htmlspecialchars($appointment['status'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($appointment['facility_name']); ?></td>
                                            <td>
                                                <div class="actions-group">
                                                    <button onclick="viewAppointment(<?php echo $appointment['appointment_id']; ?>)"
                                                        class="btn btn-sm btn-primary" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <?php if ($appointment['status'] === 'confirmed'): ?>
                                                        <button onclick="checkInAppointment(<?php echo $appointment['appointment_id']; ?>)"
                                                            class="btn btn-sm btn-success" title="Check In Patient">
                                                            <i class="fas fa-sign-in-alt"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if ($appointment['status'] === 'checked_in'): ?>
                                                        <?php if ($canAddVitals): ?>
                                                            <?php if (!empty($appointment['vitals_id'])): ?>
                                                                <!-- Vitals already recorded - show edit/view button -->
                                                                <button onclick="viewEditVitals(<?php echo $appointment['appointment_id']; ?>, <?php echo $appointment['patient_id']; ?>, <?php echo $appointment['vitals_id']; ?>)"
                                                                    class="btn btn-sm btn-success vitals-recorded" title="View/Edit Vitals (Recorded: <?php echo date('M j, g:i A', strtotime($appointment['vitals_recorded_at'])); ?>)" 
                                                                    data-vitals-id="<?php echo htmlspecialchars($appointment['vitals_id']); ?>">
                                                                    <i class="fas fa-heartbeat"></i> ✓
                                                                </button>
                                                            <?php else: ?>
                                                                <!-- No vitals recorded yet - show add button -->
                                                                <button onclick="addVitals(<?php echo $appointment['appointment_id']; ?>, <?php echo $appointment['patient_id']; ?>)"
                                                                    class="btn btn-sm btn-warning vitals-pending" title="Add Vitals">
                                                                    <i class="fas fa-heartbeat"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                        <button onclick="completeAppointment(<?php echo $appointment['appointment_id']; ?>)"
                                                            class="btn btn-sm btn-info" title="Mark as Complete">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <?php if (in_array($appointment['status'], ['confirmed', 'checked_in']) && $canCancelAppointments): ?>
                                                        <button onclick="cancelAppointment(<?php echo $appointment['appointment_id']; ?>)"
                                                            class="btn btn-sm btn-danger" title="Cancel Appointment">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-container">
                            <div class="pagination-info">
                                <div class="records-info">
                                    Showing <strong><?php echo $offset + 1; ?></strong> to
                                    <strong><?php echo min($offset + $per_page, $total_records); ?></strong>
                                    of <strong><?php echo $total_records; ?></strong> appointments
                                </div>
                                <div class="page-size-selector">
                                    <label>Show:</label>
                                    <select onchange="changePageSize(this.value)">
                                        <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10</option>
                                        <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25</option>
                                        <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                                        <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                                    </select>
                                </div>
                            </div>
                            <div class="pagination-controls">
                                <?php
                                // Preserve all current parameters for pagination links
                                $pagination_params = $_GET;
                                ?>

                                <?php if ($page > 1): ?>
                                    <a href="<?php echo $_SERVER['PHP_SELF'] . '?' . http_build_query(array_merge($pagination_params, ['page' => $page - 1])); ?>" class="pagination-btn prev">
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </a>
                                <?php endif; ?>

                                <?php
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);

                                if ($start > 1): ?>
                                    <a href="<?php echo $_SERVER['PHP_SELF'] . '?' . http_build_query(array_merge($pagination_params, ['page' => 1])); ?>" class="pagination-btn">1</a>
                                    <?php if ($start > 2): ?>
                                        <span class="pagination-ellipsis">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $start; $i <= $end; $i++): ?>
                                    <a href="<?php echo $_SERVER['PHP_SELF'] . '?' . http_build_query(array_merge($pagination_params, ['page' => $i])); ?>"
                                        class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($end < $total_pages): ?>
                                    <?php if ($end < $total_pages - 1): ?>
                                        <span class="pagination-ellipsis">...</span>
                                    <?php endif; ?>
                                    <a href="<?php echo $_SERVER['PHP_SELF'] . '?' . http_build_query(array_merge($pagination_params, ['page' => $total_pages])); ?>" class="pagination-btn"><?php echo $total_pages; ?></a>
                                <?php endif; ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="<?php echo $_SERVER['PHP_SELF'] . '?' . http_build_query(array_merge($pagination_params, ['page' => $page + 1])); ?>" class="pagination-btn next">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- View Appointment Modal -->
        <div id="viewAppointmentModal" class="modal">
            <div class="modal-content" style="max-width: 700px;">
                <div class="modal-header">
                    <h3><i class="fas fa-calendar-check"></i> Appointment Details</h3>
                    <button type="button" class="close" onclick="closeModal('viewAppointmentModal')">&times;</button>
                </div>
                <div class="modal-body" id="appointmentDetailsContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('viewAppointmentModal')">Close</button>
                </div>
            </div>
        </div>

        <!-- Cancel Appointment Modal -->
        <div id="cancelAppointmentModal" class="modal">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h3><i class="fas fa-times-circle"></i> Cancel Appointment</h3>
                    <button type="button" class="close" onclick="closeModal('cancelAppointmentModal')">&times;</button>
                </div>
                <form id="cancelAppointmentForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="cancel_appointment">
                        <input type="hidden" name="appointment_id" id="cancelAppointmentId">

                        <div class="form-group">
                            <label for="cancel_reason">Reason for Cancellation *</label>
                            <select name="cancel_reason" id="cancel_reason" class="form-control" required onchange="toggleOtherReason()">
                                <option value="">Select a reason...</option>
                                <!-- Health Center / Staff-Related Reasons -->
                                <option value="Doctor or attending staff unavailable">Doctor or attending staff unavailable</option>
                                <option value="Health personnel on official leave or duty elsewhere">Health personnel on official leave or duty elsewhere</option>
                                <option value="Clinic schedule changes">Clinic schedule changes</option>
                                <option value="Emergency cases prioritized">Emergency cases prioritized</option>
                                <option value="Power outage or system downtime">Power outage or system downtime</option>
                                <option value="Facility maintenance or disinfection">Facility maintenance or disinfection</option>
                                <option value="Equipment malfunction">Equipment malfunction</option>
                                <option value="Vaccine, medicine, or supply shortage">Vaccine, medicine, or supply shortage</option>

                                <!-- External or Environmental Reasons -->
                                <option value="Severe weather (typhoon, heavy rain, flooding)">Severe weather (typhoon, heavy rain, flooding)</option>
                                <option value="Transportation disruption or road closure">Transportation disruption or road closure</option>
                                <option value="Local holiday or government activity">Local holiday or government activity</option>
                                <option value="Community lockdown or quarantine restrictions">Community lockdown or quarantine restrictions</option>

                                <!-- Administrative or System Reasons -->
                                <option value="Appointment duplication or data entry error">Appointment duplication or data entry error</option>
                                <option value="Incorrect patient information">Incorrect patient information</option>
                                <option value="Appointment rebooked to another date">Appointment rebooked to another date</option>
                                <option value="Cancelled by referring facility">Cancelled by referring facility</option>

                                <option value="Others">Others (please specify)</option>
                            </select>
                        </div>

                        <div class="form-group" id="otherReasonGroup" style="display: none;">
                            <label for="other_reason">Please specify other reason *</label>
                            <input type="text" name="other_reason" id="other_reason" class="form-control"
                                placeholder="Enter specific reason (max 60 characters)" maxlength="60">
                            <small style="color: #6c757d; font-size: 0.8rem; margin-top: 5px; display: block;">
                                <span id="charCount">0</span>/60 characters
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="employee_password">Confirm with Your Password *</label>
                            <input type="password" name="employee_password" id="employee_password" class="form-control"
                                placeholder="Enter your password to confirm" required>
                        </div>

                        <p style="color: #dc3545; font-size: 14px;">
                            <i class="fas fa-exclamation-triangle"></i>
                            This action cannot be undone. The patient will be notified of the cancellation.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('cancelAppointmentModal')">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-times"></i> Cancel Appointment
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Check In Appointment Modal -->
        <div id="checkInModal" class="modal">
            <div class="modal-content" style="max-width: 600px;">
                <div class="modal-header">
                    <h3><i class="fas fa-sign-in-alt"></i> Check In Patient</h3>
                    <button type="button" class="close" onclick="closeModal('checkInModal')">&times;</button>
                </div>
                <form id="checkInForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="checkin_appointment">
                        <input type="hidden" name="appointment_id" id="checkInAppointmentId">

                        <div style="text-align: center; margin-bottom: 20px;">
                            <i class="fas fa-sign-in-alt" style="font-size: 3rem; color: #28a745; margin-bottom: 15px;"></i>
                            <h4 style="color: #28a745; margin-bottom: 10px;">Check In Patient</h4>
                            <p style="color: #6c757d; margin: 0; line-height: 1.5;">
                                This action will check in the patient and create a new visit record.
                                The appointment status will be updated to "Checked In".
                            </p>
                        </div>

                        <div class="warning-text" style="background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460;">
                            <i class="fas fa-info-circle"></i>
                            <div style="text-align: left;">
                                <strong>What happens next:</strong>
                                <ul style="margin: 8px 0 0 0; padding-left: 20px;">
                                    <li>Patient status changes to "Checked In"</li>
                                    <li>New visit record is created</li>
                                    <li>Patient can now be consulted and treated</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('checkInModal')">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-sign-in-alt"></i> Check In Patient
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Complete Appointment Modal -->
        <div id="completeModal" class="modal">
            <div class="modal-content" style="max-width: 600px;">
                <div class="modal-header">
                    <h3><i class="fas fa-check-circle"></i> Complete Appointment</h3>
                    <button type="button" class="close" onclick="closeModal('completeModal')">&times;</button>
                </div>
                <form id="completeForm" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="complete_appointment">
                        <input type="hidden" name="appointment_id" id="completeAppointmentId">

                        <div style="text-align: center; margin-bottom: 20px;">
                            <i class="fas fa-check-circle" style="font-size: 3rem; color: #17a2b8; margin-bottom: 15px;"></i>
                            <h4 style="color: #17a2b8; margin-bottom: 10px;">Complete Patient Visit</h4>
                            <p style="color: #6c757d; margin: 0; line-height: 1.5;">
                                This action will mark the patient visit as complete and end the appointment.
                                The visit record will be finalized with completion time.
                            </p>
                        </div>

                        <div class="warning-text" style="background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460;">
                            <i class="fas fa-info-circle"></i>
                            <div style="text-align: left;">
                                <strong>What happens next:</strong>
                                <ul style="margin: 8px 0 0 0; padding-left: 20px;">
                                    <li>Appointment status changes to "Completed"</li>
                                    <li>Visit record is finalized with end time</li>
                                    <li>Patient visit is officially closed</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('completeModal')">Cancel</button>
                        <button type="submit" class="btn btn-info">
                            <i class="fas fa-check-circle"></i> Complete Visit
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add Vitals Modal -->
        <div id="addVitalsModal" class="modal">
            <div class="modal-content" style="max-width: 1200px;">
                <div class="modal-header">
                    <h3><i class="fas fa-heartbeat"></i> Add Patient Vitals</h3>
                    <button type="button" class="close" onclick="closeModal('addVitalsModal')">&times;</button>
                </div>
                <form id="addVitalsForm">
                    <div class="modal-body">
                        <input type="hidden" id="vitalsPatientId">
                        <input type="hidden" id="vitalsAppointmentId">
                        <input type="hidden" id="vitalsId">

                        <div style="text-align: center; margin-bottom: 15px;">
                            <i class="fas fa-heartbeat" style="font-size: 2.5rem; color: #28a745; margin-bottom: 10px;"></i>
                            <h4 style="color: #28a745; margin-bottom: 8px;">Record Patient Vitals</h4>
                            <p style="color: #6c757d; margin: 0; line-height: 1.3; font-size: 14px;" id="vitalsPatientInfo">
                                Recording vital signs for patient...
                            </p>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                            <!-- Blood Pressure Section -->
                            <div class="vitals-section">
                                <h5 style="color: #0077b6; margin-bottom: 10px; border-bottom: 2px solid #e9ecef; padding-bottom: 3px; font-size: 14px;">
                                    <i class="fas fa-tint"></i> Blood Pressure
                                </h5>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                    <div class="form-group">
                                        <label for="systolic_bp">Systolic (mmHg)</label>
                                        <input type="number" id="systolic_bp" name="systolic_bp"
                                            min="50" max="300" placeholder="120" class="form-control">
                                    </div>
                                    <div class="form-group">
                                        <label for="diastolic_bp">Diastolic (mmHg)</label>
                                        <input type="number" id="diastolic_bp" name="diastolic_bp"
                                            min="30" max="200" placeholder="80" class="form-control">
                                    </div>
                                </div>
                                <span id="bpStatus" class="vital-status" style="margin-left: 10px; font-size: 12px; display: none;"></span>
                            </div>

                            <!-- Heart & Respiratory Section -->
                            <div class="vitals-section">
                                <h5 style="color: #0077b6; margin-bottom: 10px; border-bottom: 2px solid #e9ecef; padding-bottom: 3px; font-size: 14px;">
                                    <i class="fas fa-heart"></i> Heart & Respiratory
                                </h5>
                                <div class="form-group" style="margin-bottom: 8px;">
                                    <label for="heart_rate">Heart Rate (bpm)</label>
                                    <input type="number" id="heart_rate" name="heart_rate"
                                        min="30" max="250" placeholder="72" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="respiratory_rate">Respiratory Rate (breaths/min)</label>
                                    <input type="number" id="respiratory_rate" name="respiratory_rate"
                                        min="5" max="60" placeholder="16" class="form-control">
                                </div>
                                <span id="heartRespStatus" class="vital-status" style="margin-left: 10px; font-size: 12px; display: none;"></span>
                            </div>

                            <!-- Temperature Section -->
                            <div class="vitals-section">
                                <h5 style="color: #0077b6; margin-bottom: 10px; border-bottom: 2px solid #e9ecef; padding-bottom: 3px; font-size: 14px;">
                                    <i class="fas fa-thermometer-half"></i> Temperature
                                </h5>
                                <div class="form-group">
                                    <label for="temperature">Temperature (°C)</label>
                                    <input type="number" id="temperature" name="temperature"
                                        min="30" max="45" step="0.1" placeholder="36.5" class="form-control">
                                </div>
                                <span id="tempStatus" class="vital-status" style="margin-left: 10px; font-size: 12px; display: none;"></span>
                            </div>

                            <!-- Physical Measurements Section -->
                            <div class="vitals-section">
                                <h5 style="color: #0077b6; margin-bottom: 10px; border-bottom: 2px solid #e9ecef; padding-bottom: 3px; font-size: 14px;">
                                    <i class="fas fa-weight"></i> Physical Measurements
                                </h5>
                                <div class="form-group" style="margin-bottom: 8px;">
                                    <label for="weight">Weight (kg)</label>
                                    <input type="number" id="weight" name="weight"
                                        min="1" max="500" step="0.1" placeholder="70.0" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label for="height">Height (cm)</label>
                                    <input type="number" id="height" name="height"
                                        min="30" max="250" placeholder="170" class="form-control">
                                </div>
                                <span id="weightHeightStatus" class="vital-status" style="margin-left: 10px; font-size: 12px; display: none;"></span>
                                <span id="bmiStatus" class="vital-status" style="margin-left: 10px; font-size: 12px; display: none;"></span>
                            </div>

                        </div>
                        <!-- Remarks Section -->
                        <div class="form-group">
                            <label for="vitals_remarks">Additional Notes/Remarks</label>
                            <textarea id="vitals_remarks" name="remarks" rows="2"
                                placeholder="Any additional observations or notes about the patient's condition..."
                                class="form-control"></textarea>
                        </div>

                        <div class="warning-text" style="background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 10px; border-radius: 4px; font-size: 13px;">
                            <i class="fas fa-info-circle"></i>
                            <div style="text-align: left;">
                                <strong>Guidelines:</strong>
                                <ul style="margin: 5px 0 0 0; padding-left: 18px;">
                                    <li>All measurements are optional but recommended</li>
                                    <li>BMI calculated automatically when weight and height provided</li>
                                    <li>Measurements become part of permanent medical record</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addVitalsModal')">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Vitals
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            let currentAppointmentId = null;

            function viewAppointment(appointmentId) {
                currentAppointmentId = appointmentId;

                // Show loading
                document.getElementById('appointmentDetailsContent').innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <div class="loader"></div>
                    <p style="margin-top: 10px; color: #6c757d;">Loading appointment details...</p>
                </div>
            `;
                document.getElementById('viewAppointmentModal').style.display = 'block';

                // Get appointment data
                fetch(`api/get_appointment_details.php?appointment_id=${appointmentId}`, {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'Cache-Control': 'no-cache'
                        }
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                        }
                        // Get the response text first to debug JSON parsing issues
                        return response.text().then(text => {
                            try {
                                return JSON.parse(text);
                            } catch (e) {
                                console.error('JSON Parse Error:', e);
                                console.error('Response Text:', text);
                                throw new Error(`Invalid JSON response: ${text.substring(0, 100)}...`);
                            }
                        });
                    })
                    .then(data => {
                        if (data.success) {
                            displayAppointmentDetails(data.appointment);
                        } else {
                            showErrorInModal('Error loading appointment details: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showErrorInModal('Network error: Unable to load appointment details. Please check your connection and try again.');
                    });
            }

            function showErrorInModal(message) {
                document.getElementById('appointmentDetailsContent').innerHTML = `
                <div style="text-align: center; padding: 30px; color: #dc3545;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.7;"></i>
                    <h4 style="color: #dc3545; margin-bottom: 10px;">Error</h4>
                    <p style="margin: 0; line-height: 1.5;">${message}</p>
                    <button onclick="closeModal('viewAppointmentModal')" class="btn btn-secondary" style="margin-top: 20px;">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            `;
            }

            function displayAppointmentDetails(appointment) {
                const content = `
                <div class="appointment-details-grid">
                    <div class="details-section">
                        <h4><i class="fas fa-user"></i> Patient Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Name:</span>
                            <span class="detail-value">${appointment.patient_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Patient ID:</span>
                            <span class="detail-value">${appointment.patient_id || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Contact:</span>
                            <span class="detail-value">${appointment.contact_number || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Age/Sex:</span>
                            <span class="detail-value">${(appointment.age || 'N/A')}/${(appointment.sex || 'N/A')}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Barangay:</span>
                            <span class="detail-value">${appointment.barangay_name || 'N/A'}</span>
                        </div>
                    </div>
                    
                    <div class="details-section">
                        <h4><i class="fas fa-calendar"></i> Appointment Details</h4>
                        <div class="detail-item">
                            <span class="detail-label">Date:</span>
                            <span class="detail-value">${appointment.appointment_date || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Time Slot:</span>
                            <span class="detail-value highlight">${appointment.time_slot || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status:</span>
                            <span class="detail-value">${appointment.status || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Facility:</span>
                            <span class="detail-value">${appointment.facility_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Service:</span>
                            <span class="detail-value">${appointment.service_name || 'General Consultation'}</span>
                        </div>
                    </div>
                </div>
                <div class="details-section">
                    <h4><i class="fas fa-file-medical"></i> Referral Information</h4>
                        ${appointment.referral_id ? `
                            <div class="detail-item">
                                <span class="detail-label">Referral ID:</span>
                                <span class="detail-value highlight">${appointment.referral_id}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Referral Status:</span>
                                <span class="detail-value" style="color: #28a745; font-weight: 600;">Required</span>
                            </div>
                        ` : `
                            <div class="detail-item">
                                <span class="detail-label">Referral Status:</span>
                                <span class="detail-value" style="color: #6c757d;">This appointment did not need a referral</span>
                            </div>
                        `}
                </div>
                
                ${appointment.cancel_reason ? `
                    <div class="details-section" style="border-left: 4px solid #dc3545; background: #fff5f5; margin-top: 20px;">
                        <h4><i class="fas fa-times-circle" style="color: #dc3545;"></i> Cancellation Details</h4>
                        <div class="detail-item">
                            <span class="detail-label">Reason:</span>
                            <span class="detail-value">${appointment.cancel_reason}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Cancelled At:</span>
                            <span class="detail-value">${appointment.cancelled_at || 'N/A'}</span>
                        </div>
                    </div>
                ` : ''}
            `;

                document.getElementById('appointmentDetailsContent').innerHTML = content;
            }

            function cancelAppointment(appointmentId) {
                currentAppointmentId = appointmentId;
                document.getElementById('cancelAppointmentId').value = appointmentId;
                document.getElementById('cancel_reason').value = '';
                document.getElementById('employee_password').value = '';
                document.getElementById('cancelAppointmentModal').style.display = 'block';
            }

            function checkInAppointment(appointmentId) {
                document.getElementById('checkInAppointmentId').value = appointmentId;
                document.getElementById('checkInModal').style.display = 'block';
            }

            function completeAppointment(appointmentId) {
                document.getElementById('completeAppointmentId').value = appointmentId;
                document.getElementById('completeModal').style.display = 'block';
            }

            function addVitals(appointmentId, patientId) {
                // Set hidden form values
                document.getElementById('vitalsAppointmentId').value = appointmentId;
                document.getElementById('vitalsPatientId').value = patientId;
                document.getElementById('vitalsId').value = ''; // Clear any existing vitals_id

                // Reset form
                document.getElementById('addVitalsForm').reset();
                document.getElementById('vitalsAppointmentId').value = appointmentId;
                document.getElementById('vitalsPatientId').value = patientId;
                document.getElementById('vitalsId').value = ''; // Clear any existing vitals_id

                // Update patient info text - get the display ID from the row
                const row = document.querySelector(`tr[data-appointment-id="${appointmentId}"]`);
                const patientDisplayId = row ? row.cells[1].textContent.trim() : patientId;
                document.getElementById('vitalsPatientInfo').textContent = `Recording vital signs for Patient ID: ${patientDisplayId}`;

                // Update modal title for adding new vitals
                const modalTitle = document.querySelector('#addVitalsModal .modal-header h3');
                modalTitle.innerHTML = '<i class="fas fa-heartbeat"></i> Add Patient Vitals';

                // Hide BMI display if it exists
                const bmiDisplay = document.getElementById('bmiDisplay');
                if (bmiDisplay) {
                    bmiDisplay.style.display = 'none';
                }

                // Clear any existing vital status indicators
                clearVitalStatusIndicators();

                // Show modal
                document.getElementById('addVitalsModal').style.display = 'block';
            }

            function viewEditVitals(appointmentId, patientId, vitalsId) {
                // Set hidden form values
                document.getElementById('vitalsAppointmentId').value = appointmentId;
                document.getElementById('vitalsPatientId').value = patientId;
                document.getElementById('vitalsId').value = vitalsId; // Set vitals_id for update

                // Update patient info text - get the display ID from the row
                const row = document.querySelector(`tr[data-appointment-id="${appointmentId}"]`);
                const patientDisplayId = row ? row.cells[1].textContent.trim() : patientId;
                
                // Update modal title for viewing/editing existing vitals
                const modalTitle = document.querySelector('#addVitalsModal .modal-header h3');
                modalTitle.innerHTML = '<i class="fas fa-heartbeat"></i> View/Edit Patient Vitals';

                // Show loading state
                document.getElementById('vitalsPatientInfo').textContent = `Loading vitals for Patient ID: ${patientDisplayId}...`;

                // Fetch existing vitals data
                fetch(`api/get_vitals.php?vitals_id=${vitalsId}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                        }
                        // Get the response text first to debug JSON parsing issues
                        return response.text().then(text => {
                            try {
                                return JSON.parse(text);
                            } catch (e) {
                                console.error('JSON Parse Error:', e);
                                console.error('Response Text:', text);
                                throw new Error(`Invalid JSON response: ${text.substring(0, 100)}...`);
                            }
                        });
                    })
                    .then(data => {
                        if (data.success && data.vitals) {
                            populateVitalsForm(data.vitals, patientDisplayId);
                        } else {
                            console.error('API Error:', data.message || 'Unknown error');
                            document.getElementById('vitalsPatientInfo').textContent = `Error loading vitals for Patient ID: ${patientDisplayId} - ${data.message || 'Unknown error'}`;
                            // Fallback to empty form
                            document.getElementById('addVitalsForm').reset();
                            document.getElementById('vitalsAppointmentId').value = appointmentId;
                            document.getElementById('vitalsPatientId').value = patientId;
                            document.getElementById('vitalsId').value = vitalsId;
                        }
                    })
                    .catch(error => {
                        console.error('Fetch Error:', error);
                        document.getElementById('vitalsPatientInfo').textContent = `Error loading vitals for Patient ID: ${patientDisplayId} - ${error.message}`;
                        // Fallback to empty form
                        document.getElementById('addVitalsForm').reset();
                        document.getElementById('vitalsAppointmentId').value = appointmentId;
                        document.getElementById('vitalsPatientId').value = patientId;
                        document.getElementById('vitalsId').value = vitalsId;
                    });

                // Show modal
                document.getElementById('addVitalsModal').style.display = 'block';
            }

            function populateVitalsForm(vitals, patientDisplayId) {
                // Update patient info with recorded time
                const recordedDate = new Date(vitals.recorded_at);
                document.getElementById('vitalsPatientInfo').textContent = 
                    `Vitals for Patient ID: ${patientDisplayId} (Last recorded: ${recordedDate.toLocaleDateString()} ${recordedDate.toLocaleTimeString()})`;

                // Populate form fields
                if (vitals.systolic_bp) document.getElementById('systolic_bp').value = vitals.systolic_bp;
                if (vitals.diastolic_bp) document.getElementById('diastolic_bp').value = vitals.diastolic_bp;
                if (vitals.heart_rate) document.getElementById('heart_rate').value = vitals.heart_rate;
                if (vitals.respiratory_rate) document.getElementById('respiratory_rate').value = vitals.respiratory_rate;
                if (vitals.temperature) document.getElementById('temperature').value = vitals.temperature;
                if (vitals.weight) document.getElementById('weight').value = vitals.weight;
                if (vitals.height) document.getElementById('height').value = vitals.height;
                if (vitals.remarks) document.getElementById('vitals_remarks').value = vitals.remarks;

                // Trigger calculations and status checks
                if (vitals.systolic_bp || vitals.diastolic_bp) checkBloodPressure();
                if (vitals.heart_rate || vitals.respiratory_rate) checkHeartRespiratory();
                if (vitals.temperature) checkTemperature();
                if (vitals.weight && vitals.height) {
                    calculateBMI();
                    checkBMIStatus();
                }
            }

            function clearVitalStatusIndicators() {
                const indicators = ['bpStatus', 'heartRespStatus', 'tempStatus', 'bmiStatus'];
                indicators.forEach(id => {
                    const element = document.getElementById(id);
                    if (element) {
                        element.style.display = 'none';
                    }
                });
            }

            function calculateBMI() {
                const weight = parseFloat(document.getElementById('weight').value);
                const height = parseFloat(document.getElementById('height').value);
                const bmiDisplay = document.getElementById('bmiDisplay');
                const bmiValue = document.getElementById('bmiValue');
                const bmiCategory = document.getElementById('bmiCategory');

                if (weight && height && weight > 0 && height > 0) {
                    const heightInMeters = height / 100;
                    const bmi = (weight / (heightInMeters * heightInMeters)).toFixed(1);

                    let category = '';
                    let categoryColor = '';

                    if (bmi < 18.5) {
                        category = 'Underweight';
                        categoryColor = '#007bff';
                    } else if (bmi < 25) {
                        category = 'Normal';
                        categoryColor = '#28a745';
                    } else if (bmi < 30) {
                        category = 'Overweight';
                        categoryColor = '#ffc107';
                    } else {
                        category = 'Obese';
                        categoryColor = '#dc3545';
                    }

                    // Update BMI display elements if they exist
                    if (bmiValue) {
                        bmiValue.textContent = bmi;
                    }
                    if (bmiCategory) {
                        bmiCategory.textContent = category;
                        bmiCategory.style.backgroundColor = categoryColor;
                        bmiCategory.style.color = 'white';
                    }
                    if (bmiDisplay) {
                        bmiDisplay.style.display = 'block';
                    }
                    
                    // Also update BMI status indicator
                    checkBMIStatus(bmi);
                } else {
                    if (bmiDisplay) {
                        bmiDisplay.style.display = 'none';
                    }
                    // Hide BMI status when no BMI can be calculated
                    const bmiStatus = document.getElementById('bmiStatus');
                    if (bmiStatus) {
                        bmiStatus.style.display = 'none';
                    }
                }
            }

            function checkBMIStatus(bmi = null) {
                const weight = parseFloat(document.getElementById('weight').value);
                const height = parseFloat(document.getElementById('height').value);
                const bmiStatus = document.getElementById('bmiStatus');

                // Calculate BMI if not provided
                if (!bmi && weight && height && weight > 0 && height > 0) {
                    const heightInMeters = height / 100;
                    bmi = (weight / (heightInMeters * heightInMeters)).toFixed(1);
                }

                if (!bmi) {
                    bmiStatus.style.display = 'none';
                    return;
                }

                let status = '';
                let className = '';
                let icon = '';

                if (bmi < 18.5) {
                    status = 'UNDERWEIGHT';
                    className = 'low';
                    icon = '⬇️';
                } else if (bmi < 25) {
                    status = 'NORMAL BMI';
                    className = 'normal';
                    icon = '✓';
                } else if (bmi < 30) {
                    status = 'OVERWEIGHT';
                    className = 'high';
                    icon = '⬆️';
                } else if (bmi < 35) {
                    status = 'OBESE I';
                    className = 'high';
                    icon = '⚠️';
                } else if (bmi < 40) {
                    status = 'OBESE II';
                    className = 'critical';
                    icon = '⚠️';
                } else {
                    status = 'OBESE III';
                    className = 'critical';
                    icon = '🚨';
                }

                bmiStatus.className = `vital-status ${className}`;
                bmiStatus.innerHTML = `${icon} ${status}`;
                bmiStatus.style.display = 'inline-flex';
            }

            function checkBloodPressure() {
                const systolic = parseInt(document.getElementById('systolic_bp').value);
                const diastolic = parseInt(document.getElementById('diastolic_bp').value);
                const bpStatus = document.getElementById('bpStatus');

                if (!systolic && !diastolic) {
                    bpStatus.style.display = 'none';
                    return;
                }

                let status = '';
                let className = '';
                let icon = '';

                if (systolic && diastolic) {
                    // Both values present - full assessment
                    if (systolic >= 180 || diastolic >= 120) {
                        status = 'CRITICAL HIGH';
                        className = 'critical';
                        icon = '⚠️';
                    } else if (systolic >= 140 || diastolic >= 90) {
                        status = 'HIGH';
                        className = 'high';
                        icon = '⬆️';
                    } else if (systolic < 90 || diastolic < 60) {
                        status = 'LOW';
                        className = 'low';
                        icon = '⬇️';
                    } else {
                        status = 'NORMAL';
                        className = 'normal';
                        icon = '✓';
                    }
                } else if (systolic) {
                    // Only systolic
                    if (systolic >= 180) {
                        status = 'SYS CRITICAL';
                        className = 'critical';
                        icon = '⚠️';
                    } else if (systolic >= 140) {
                        status = 'SYS HIGH';
                        className = 'high';
                        icon = '⬆️';
                    } else if (systolic < 90) {
                        status = 'SYS LOW';
                        className = 'low';
                        icon = '⬇️';
                    } else {
                        status = 'SYS NORMAL';
                        className = 'normal';
                        icon = '✓';
                    }
                } else if (diastolic) {
                    // Only diastolic
                    if (diastolic >= 120) {
                        status = 'DIA CRITICAL';
                        className = 'critical';
                        icon = '⚠️';
                    } else if (diastolic >= 90) {
                        status = 'DIA HIGH';
                        className = 'high';
                        icon = '⬆️';
                    } else if (diastolic < 60) {
                        status = 'DIA LOW';
                        className = 'low';
                        icon = '⬇️';
                    } else {
                        status = 'DIA NORMAL';
                        className = 'normal';
                        icon = '✓';
                    }
                }

                bpStatus.className = `vital-status ${className}`;
                bpStatus.innerHTML = `${icon} ${status}`;
                bpStatus.style.display = 'inline-flex';
            }

            function checkHeartRespiratory() {
                const heartRate = parseInt(document.getElementById('heart_rate').value);
                const respRate = parseInt(document.getElementById('respiratory_rate').value);
                const hrStatus = document.getElementById('heartRespStatus');

                if (!heartRate && !respRate) {
                    hrStatus.style.display = 'none';
                    return;
                }

                let status = '';
                let className = '';
                let icon = '';

                // Check heart rate (60-100 bpm normal for adults)
                let heartStatus = '';
                if (heartRate) {
                    if (heartRate < 60) {
                        heartStatus = 'HR LOW';
                        className = 'low';
                    } else if (heartRate > 100) {
                        heartStatus = 'HR HIGH';
                        className = className === 'low' ? 'high' : (className || 'high');
                    } else {
                        heartStatus = 'HR NORMAL';
                        className = className || 'normal';
                    }
                }

                // Check respiratory rate (12-20 breaths/min normal for adults)
                let respStatus = '';
                if (respRate) {
                    if (respRate < 12) {
                        respStatus = 'RR LOW';
                        className = className === 'normal' ? 'low' : (className || 'low');
                    } else if (respRate > 20) {
                        respStatus = 'RR HIGH';
                        className = 'high';
                    } else {
                        respStatus = 'RR NORMAL';
                        className = className === 'low' || className === 'high' ? className : 'normal';
                    }
                }

                // Combine statuses
                if (heartStatus && respStatus) {
                    if (className === 'normal') {
                        status = 'NORMAL';
                        icon = '✓';
                    } else if (className === 'high') {
                        status = 'ELEVATED';
                        icon = '⬆️';
                    } else {
                        status = 'LOW';
                        icon = '⬇️';
                    }
                } else {
                    status = heartStatus || respStatus;
                    icon = className === 'normal' ? '✓' : (className === 'high' ? '⬆️' : '⬇️');
                }

                hrStatus.className = `vital-status ${className}`;
                hrStatus.innerHTML = `${icon} ${status}`;
                hrStatus.style.display = 'inline-flex';
            }

            function checkTemperature() {
                const temp = parseFloat(document.getElementById('temperature').value);
                const tempStatus = document.getElementById('tempStatus');

                if (!temp) {
                    tempStatus.style.display = 'none';
                    return;
                }

                let status = '';
                let className = '';
                let icon = '';

                // Normal temperature range: 36.1°C - 37.2°C (97°F - 99°F)
                if (temp >= 39.0) {
                    status = 'HIGH FEVER';
                    className = 'critical';
                    icon = '🔥';
                } else if (temp >= 38.0) {
                    status = 'FEVER';
                    className = 'high';
                    icon = '⬆️';
                } else if (temp >= 37.3) {
                    status = 'ELEVATED';
                    className = 'high';
                    icon = '⬆️';
                } else if (temp >= 36.1) {
                    status = 'NORMAL';
                    className = 'normal';
                    icon = '✓';
                } else if (temp >= 35.0) {
                    status = 'LOW';
                    className = 'low';
                    icon = '⬇️';
                } else {
                    status = 'HYPOTHERMIA';
                    className = 'critical';
                    icon = '❄️';
                }

                tempStatus.className = `vital-status ${className}`;
                tempStatus.innerHTML = `${icon} ${status}`;
                tempStatus.style.display = 'inline-flex';
            }

            function toggleOtherReason() {
                const cancelReason = document.getElementById('cancel_reason');
                const otherReasonGroup = document.getElementById('otherReasonGroup');
                const otherReasonInput = document.getElementById('other_reason');

                if (cancelReason.value === 'Others') {
                    otherReasonGroup.style.display = 'block';
                    otherReasonInput.required = true;
                } else {
                    otherReasonGroup.style.display = 'none';
                    otherReasonInput.required = false;
                    otherReasonInput.value = '';
                    updateCharCount();
                }
            }

            function updateCharCount() {
                const otherReasonInput = document.getElementById('other_reason');
                const charCount = document.getElementById('charCount');
                if (otherReasonInput && charCount) {
                    charCount.textContent = otherReasonInput.value.length;
                }
            }

            function closeModal(modalId) {
                document.getElementById(modalId).style.display = 'none';
                if (modalId === 'cancelAppointmentModal') {
                    document.getElementById('cancelAppointmentForm').reset();
                    // Reset the other reason visibility when closing modal
                    const otherReasonGroup = document.getElementById('otherReasonGroup');
                    if (otherReasonGroup) {
                        otherReasonGroup.style.display = 'none';
                    }
                    updateCharCount();
                } else if (modalId === 'checkInModal') {
                    document.getElementById('checkInForm').reset();
                } else if (modalId === 'completeModal') {
                    document.getElementById('completeForm').reset();
                } else if (modalId === 'addVitalsModal') {
                    document.getElementById('addVitalsForm').reset();
                    const bmiDisplay = document.getElementById('bmiDisplay');
                    if (bmiDisplay) {
                        bmiDisplay.style.display = 'none';
                    }
                }
            }

            // Close modal when clicking outside
            window.onclick = function(event) {
                const modals = ['viewAppointmentModal', 'cancelAppointmentModal', 'checkInModal', 'completeModal', 'addVitalsModal'];
                modals.forEach(modalId => {
                    const modal = document.getElementById(modalId);
                    if (event.target === modal) {
                        closeModal(modalId);
                    }
                });
            }

            // ESC key support for closing modals
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const openModals = ['viewAppointmentModal', 'cancelAppointmentModal', 'checkInModal', 'completeModal', 'addVitalsModal'];
                    openModals.forEach(modalId => {
                        const modal = document.getElementById(modalId);
                        if (modal.style.display === 'block') {
                            closeModal(modalId);
                        }
                    });
                }
            });

            // Pagination function
            function changePageSize(perPage) {
                const url = new URL(window.location);
                url.searchParams.set('per_page', perPage);
                url.searchParams.set('page', '1'); // Reset to first page
                window.location.href = url.toString();
            }

            // Sorting function
            function sortTable(column) {
                const url = new URL(window.location);
                const currentSort = url.searchParams.get('sort') || 'scheduled_date';
                const currentDir = url.searchParams.get('dir') || 'ASC';

                let newDir = 'ASC';

                // If clicking the same column, toggle direction
                if (currentSort === column) {
                    newDir = currentDir === 'ASC' ? 'DESC' : 'ASC';
                }

                // Update URL parameters
                url.searchParams.set('sort', column);
                url.searchParams.set('dir', newDir);
                url.searchParams.set('page', '1'); // Reset to first page

                // Navigate to new URL
                window.location.href = url.toString();
            }

            // Auto-dismiss alerts
            document.addEventListener('DOMContentLoaded', function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    setTimeout(() => {
                        if (alert.parentElement) {
                            alert.style.opacity = '0';
                            alert.style.transform = 'translateY(-10px)';
                            setTimeout(() => alert.remove(), 300);
                        }
                    }, 5000);
                });

                // Add form validation
                const cancelForm = document.getElementById('cancelAppointmentForm');
                if (cancelForm) {
                    cancelForm.addEventListener('submit', function(e) {
                        const reasonSelect = document.getElementById('cancel_reason');
                        const otherReasonInput = document.getElementById('other_reason');
                        const password = document.getElementById('employee_password').value.trim();

                        // Check if a reason is selected
                        if (!reasonSelect.value) {
                            e.preventDefault();
                            showErrorMessage('Please select a reason for cancellation.');
                            return;
                        }

                        // Check if "Others" is selected and other_reason is provided
                        if (reasonSelect.value === 'Others') {
                            const otherReason = otherReasonInput.value.trim();
                            if (!otherReason) {
                                e.preventDefault();
                                showErrorMessage('Please specify the reason for cancellation.');
                                return;
                            }
                            if (otherReason.length < 5) {
                                e.preventDefault();
                                showErrorMessage('Please provide a more detailed reason (at least 5 characters).');
                                return;
                            }
                        }

                        if (!password) {
                            e.preventDefault();
                            showErrorMessage('Please enter your password to confirm cancellation.');
                            return;
                        }
                    });
                }

                // Add character counter for other reason input
                const otherReasonInput = document.getElementById('other_reason');
                if (otherReasonInput) {
                    otherReasonInput.addEventListener('input', updateCharCount);
                    // Initialize character count
                    updateCharCount();
                }

                // Add vitals form submission handler
                const vitalsForm = document.getElementById('addVitalsForm');
                if (vitalsForm) {
                    vitalsForm.addEventListener('submit', function(e) {
                        e.preventDefault();

                        const patientId = document.getElementById('vitalsPatientId').value;
                        const appointmentId = document.getElementById('vitalsAppointmentId').value;
                        const vitalsId = document.getElementById('vitalsId').value;

                        // Collect form data
                        const formData = {
                            patient_id: patientId,
                            appointment_id: appointmentId,
                            systolic_bp: document.getElementById('systolic_bp').value,
                            diastolic_bp: document.getElementById('diastolic_bp').value,
                            heart_rate: document.getElementById('heart_rate').value,
                            respiratory_rate: document.getElementById('respiratory_rate').value,
                            temperature: document.getElementById('temperature').value,
                            weight: document.getElementById('weight').value,
                            height: document.getElementById('height').value,
                            remarks: document.getElementById('vitals_remarks').value
                        };

                        // Add vitals_id if we're updating existing record
                        if (vitalsId) {
                            formData.vitals_id = vitalsId;
                        }

                        // Show loading state
                        const submitBtn = vitalsForm.querySelector('button[type="submit"]');
                        const originalText = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                        submitBtn.disabled = true;

                        // Submit vitals data
                        fetch('api/add_vitals.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify(formData)
                            })
                            .then(response => {
                                if (!response.ok) {
                                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                                }
                                // Get the response text first to debug JSON parsing issues
                                return response.text().then(text => {
                                    try {
                                        return JSON.parse(text);
                                    } catch (e) {
                                        console.error('JSON Parse Error:', e);
                                        console.error('Response Text:', text);
                                        throw new Error(`Invalid JSON response: ${text.substring(0, 100)}...`);
                                    }
                                });
                            })
                            .then(data => {
                                if (data.success) {
                                    const action = data.data && data.data.action === 'updated' ? 'updated' : 'recorded';
                                    showSuccessMessage(`Vitals ${action} successfully!`);
                                    closeModal('addVitalsModal');
                                    // Optionally refresh the page or update the UI
                                    setTimeout(() => {
                                        window.location.reload();
                                    }, 1500);
                                } else {
                                    showErrorMessage('Error saving vitals: ' + data.message);
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                showErrorMessage('Network error: Unable to save vitals. Please try again.');
                            })
                            .finally(() => {
                                // Reset button state
                                submitBtn.innerHTML = originalText;
                                submitBtn.disabled = false;
                            });
                    });
                }

                // Add BMI calculation event listeners
                const weightInput = document.getElementById('weight');
                const heightInput = document.getElementById('height');
                if (weightInput && heightInput) {
                    weightInput.addEventListener('input', calculateBMI);
                    heightInput.addEventListener('input', calculateBMI);
                }

                // Add vital signs status checking event listeners
                const systolicInput = document.getElementById('systolic_bp');
                const diastolicInput = document.getElementById('diastolic_bp');
                const heartRateInput = document.getElementById('heart_rate');
                const respRateInput = document.getElementById('respiratory_rate');
                const tempInput = document.getElementById('temperature');

                if (systolicInput && diastolicInput) {
                    systolicInput.addEventListener('input', checkBloodPressure);
                    diastolicInput.addEventListener('input', checkBloodPressure);
                }

                if (heartRateInput && respRateInput) {
                    heartRateInput.addEventListener('input', checkHeartRespiratory);
                    respRateInput.addEventListener('input', checkHeartRespiratory);
                }

                if (tempInput) {
                    tempInput.addEventListener('input', checkTemperature);
                }
            });

            // Helper function to show success message
            function showSuccessMessage(message) {
                const existingAlert = document.querySelector('.alert-dynamic');
                if (existingAlert) existingAlert.remove();

                const alert = document.createElement('div');
                alert.className = 'alert alert-success alert-dynamic';
                alert.innerHTML = `
                <i class="fas fa-check-circle"></i> 
                ${message}
                <button type="button" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; opacity: 0.7; color: inherit; padding: 0; margin-left: auto;" onclick="this.parentElement.remove();">&times;</button>
            `;

                document.body.appendChild(alert);

                setTimeout(() => {
                    if (alert.parentElement) {
                        alert.style.opacity = '0';
                        alert.style.transform = 'translate(-50%, -20px)';
                        setTimeout(() => alert.remove(), 300);
                    }
                }, 5000);
            }

            // Helper function to show error message
            function showErrorMessage(message) {
                const existingAlert = document.querySelector('.alert-dynamic');
                if (existingAlert) existingAlert.remove();

                const alert = document.createElement('div');
                alert.className = 'alert alert-error alert-dynamic';
                alert.innerHTML = `
                <i class="fas fa-exclamation-triangle"></i> 
                ${message}
                <button type="button" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; opacity: 0.7; color: inherit; padding: 0; margin-left: auto;" onclick="this.parentElement.remove();">&times;</button>
            `;

                document.body.appendChild(alert);

                setTimeout(() => {
                    if (alert.parentElement) {
                        alert.style.opacity = '0';
                        alert.style.transform = 'translate(-50%, -20px)';
                        setTimeout(() => alert.remove(), 300);
                    }
                }, 8000);
            }
        </script>
</body>

</html>
<?php
// Safe output buffer handling
if (ob_get_level()) {
    ob_end_flush(); // End output buffering and send output only if buffer exists
}
?>