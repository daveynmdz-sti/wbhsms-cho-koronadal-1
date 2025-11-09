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
require_once $root_path . '/config/auth_helpers.php';

// Check authentication and authorization
$authorized_roles = ['admin', 'dho', 'bhw', 'doctor', 'nurse', 'records_officer'];
require_employee_auth($authorized_roles);

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
    if ($row && isset($row['name']) && !empty($row['name'])) {
        $facility_name = $row['name'];
    }
    $stmt->close();
} catch (Exception $e) {
    // Keep default facility name if query fails
    error_log("Failed to get facility name for employee ID {$employee_id}: " . $e->getMessage());
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
    
    // Debug all POST data
    error_log("POST DEBUG: Action: '$action', Appointment ID: '$appointment_id'");
    error_log("POST DEBUG: All POST data: " . print_r($_POST, true));

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

            // Determine attendance status based on check-in time vs scheduled time
            $scheduled_datetime = $appointment_data['scheduled_date'] . ' ' . $appointment_data['scheduled_time'];
            $scheduled_timestamp = strtotime($scheduled_datetime);
            $current_timestamp = time();

            $attendance_status = 'on_time'; // default

            // Check if early (before scheduled time)
            if ($current_timestamp < $scheduled_timestamp) {
                $attendance_status = 'early';
            }
            // Check if late (after the scheduled hour)
            else if ($current_timestamp > ($scheduled_timestamp + 3600)) { // 3600 seconds = 1 hour
                $attendance_status = 'late';
            }
            // Otherwise it's on_time (within the scheduled hour)

            // Update appointment status to checked_in
            $update_stmt = $conn->prepare("UPDATE appointments SET status = 'checked_in' WHERE appointment_id = ?");
            $update_stmt->bind_param("i", $appointment_id);
            $update_stmt->execute();

            // Create new visit record with smart attendance status
            $visit_stmt = $conn->prepare("INSERT INTO visits (patient_id, facility_id, appointment_id, visit_date, time_in, attending_employee_id, visit_status, attendance_status) VALUES (?, ?, ?, CURDATE(), NOW(), ?, 'ongoing', ?)");
            $visit_stmt->bind_param("iiiis", $appointment_data['patient_id'], $appointment_data['facility_id'], $appointment_id, $employee_id, $attendance_status);
            $visit_stmt->execute();

            $visit_id = $conn->insert_id;

            // Add patient to station_1_queue for triage processing
            $queue_stmt = $conn->prepare("
                INSERT INTO station_1_queue (
                    patient_id, username, visit_id, appointment_id, service_id,
                    queue_type, station_id, priority_level, status, time_in
                ) VALUES (?, ?, ?, ?, ?, 'triage', 1, 'normal', 'waiting', NOW())
            ");
            $username = $appointment_data['first_name'] . ' ' . $appointment_data['last_name'];
            $service_id = $appointment_data['service_id'] ?? 1; // Default to service 1 if not set
            $queue_stmt->bind_param("isiii", 
                $appointment_data['patient_id'], 
                $username,
                $visit_id, 
                $appointment_id, 
                $service_id
            );
            $queue_stmt->execute();

            $queue_entry_id = $conn->insert_id;

            // Log the check-in in appointment_logs
            $log_stmt = $conn->prepare("INSERT INTO appointment_logs (appointment_id, patient_id, action, old_status, new_status, reason, created_by_type, created_by_id) VALUES (?, ?, 'updated', 'confirmed', 'checked_in', ?, 'employee', ?)");
            $log_reason = "Patient checked in for appointment - Attendance: " . ucfirst($attendance_status);
            $log_stmt->bind_param("iisi", $appointment_id, $appointment_data['patient_id'], $log_reason, $employee_id);
            $log_stmt->execute();

            $conn->commit();
            $message = "Patient " . htmlspecialchars($appointment_data['first_name'] . ' ' . $appointment_data['last_name']) . " checked in successfully. Visit ID: " . $visit_id . ", Added to Station 1 Queue (Entry ID: " . $queue_entry_id . ") - Attendance: " . ucfirst($attendance_status);
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to check in patient: " . $e->getMessage();
        }
    }

    if ($action === 'checkin_appointment_with_priority' && !empty($appointment_id) && is_numeric($appointment_id)) {
        error_log("QR CHECK-IN DEBUG: Starting check-in for appointment ID: $appointment_id");
        
        try {
            // Start transaction
            $conn->begin_transaction();
            error_log("QR CHECK-IN DEBUG: Transaction started");

            // Get priority and special categories from QR scanner
            $priority = $_POST['priority'] ?? 'standard';
            $special_categories = $_POST['special_categories'] ?? '';
            $notes = $_POST['notes'] ?? '';
            
            error_log("QR CHECK-IN DEBUG: Priority: $priority, Categories: $special_categories");

            // Get appointment details
            $apt_stmt = $conn->prepare("SELECT a.*, p.first_name, p.last_name FROM appointments a JOIN patients p ON a.patient_id = p.patient_id WHERE a.appointment_id = ? AND a.status = 'confirmed'");
            $apt_stmt->bind_param("i", $appointment_id);
            $apt_stmt->execute();
            $appointment_result = $apt_stmt->get_result();
            $appointment_data = $appointment_result->fetch_assoc();

            if (!$appointment_data) {
                error_log("QR CHECK-IN DEBUG: No appointment found or not confirmed");
                throw new Exception("Appointment not found or not in confirmed status.");
            }
            
            error_log("QR CHECK-IN DEBUG: Found appointment for patient: " . $appointment_data['first_name'] . " " . $appointment_data['last_name']);

            // Determine attendance status based on check-in time vs scheduled time
            $scheduled_datetime = $appointment_data['scheduled_date'] . ' ' . $appointment_data['scheduled_time'];
            $scheduled_timestamp = strtotime($scheduled_datetime);
            $current_timestamp = time();

            $attendance_status = 'on_time'; // default

            // Check if early (before scheduled time)
            if ($current_timestamp < $scheduled_timestamp) {
                $attendance_status = 'early';
            }
            // Check if late (after the scheduled hour)
            else if ($current_timestamp > ($scheduled_timestamp + 3600)) { // 3600 seconds = 1 hour
                $attendance_status = 'late';
            }

            // Update appointment status to checked_in 
            $update_stmt = $conn->prepare("UPDATE appointments SET status = 'checked_in' WHERE appointment_id = ?");
            $update_stmt->bind_param("i", $appointment_id);
            if (!$update_stmt->execute()) {
                error_log("QR CHECK-IN DEBUG: Failed to update appointment status: " . $conn->error);
                throw new Exception("Failed to update appointment status: " . $conn->error);
            }
            
            $affected_rows = $conn->affected_rows;
            error_log("QR CHECK-IN DEBUG: Updated appointment status, affected rows: $affected_rows");

            // Create enhanced remarks with QR verification info
            $remarks = "QR-verified check-in - Priority: " . ucfirst($priority);
            if (!empty($special_categories)) {
                $remarks .= ", Special Categories: " . $special_categories;
            }
            if (!empty($notes)) {
                $remarks .= ", Notes: " . $notes;
            }

            // Create new visit record with enhanced data from QR check-in
            $visit_stmt = $conn->prepare("INSERT INTO visits (patient_id, facility_id, appointment_id, visit_date, time_in, attending_employee_id, visit_status, attendance_status, remarks) VALUES (?, ?, ?, CURDATE(), NOW(), ?, 'ongoing', ?, ?)");
            $visit_stmt->bind_param("iiiiss", $appointment_data['patient_id'], $appointment_data['facility_id'], $appointment_id, $employee_id, $attendance_status, $remarks);
            if (!$visit_stmt->execute()) {
                throw new Exception("Failed to create visit record: " . $conn->error);
            }

            $visit_id = $conn->insert_id;

            // Map priority to queue priority level
            $queue_priority = 'normal';
            switch($priority) {
                case 'emergency':
                    $queue_priority = 'emergency';
                    break;
                case 'urgent':
                    $queue_priority = 'urgent';
                    break;
                case 'low':
                    $queue_priority = 'low';
                    break;
                default:
                    $queue_priority = 'normal';
                    break;
            }

            // Add patient to station_1_queue for triage processing with priority
            $queue_stmt = $conn->prepare("
                INSERT INTO station_1_queue (
                    patient_id, username, visit_id, appointment_id, service_id,
                    queue_type, station_id, priority_level, status, time_in, remarks
                ) VALUES (?, ?, ?, ?, ?, 'triage', 1, ?, 'waiting', NOW(), ?)
            ");
            
            // Create queue remarks
            $queue_remarks = "QR Check-in - Priority: " . ucfirst($priority);
            if (!empty($special_categories)) {
                $queue_remarks .= ", Special Categories: " . $special_categories;
            }
            if (!empty($notes)) {
                $queue_remarks .= ", Notes: " . $notes;
            }
            
            $queue_stmt->bind_param("isiisss", 
                $appointment_data['patient_id'], 
                $appointment_data['first_name'] . ' ' . $appointment_data['last_name'],
                $visit_id, 
                $appointment_id, 
                $appointment_data['service_id'] ?? 1, // Default to service 1 if NULL
                $queue_priority,
                $queue_remarks
            );
            
            if (!$queue_stmt->execute()) {
                throw new Exception("Failed to add patient to queue: " . $conn->error);
            }

            $queue_entry_id = $conn->insert_id;

            // Log the QR-verified check-in
            $log_stmt = $conn->prepare("INSERT INTO appointment_logs (appointment_id, patient_id, action, old_status, new_status, reason, created_by_type, created_by_id) VALUES (?, ?, 'checked_in', 'confirmed', 'checked_in', ?, 'employee', ?)");
            $log_reason = "QR-verified check-in - Priority: " . ucfirst($priority) . ", Attendance: " . ucfirst($attendance_status);
            if (!empty($special_categories)) {
                $log_reason .= ", Special Categories: " . $special_categories;
            }
            $log_stmt->bind_param("iisi", $appointment_id, $appointment_data['patient_id'], $log_reason, $employee_id);
            if (!$log_stmt->execute()) {
                error_log("Warning: Failed to log QR check-in: " . $conn->error);
                // Don't throw exception for logging failure - continue with check-in
            }

            $conn->commit();
            error_log("QR CHECK-IN DEBUG: Transaction committed successfully");

            // Generate queue number for display
            $queue_number = 'T-' . str_pad($queue_entry_id, 3, '0', STR_PAD_LEFT);
            
            $message = "Patient " . htmlspecialchars($appointment_data['first_name'] . ' ' . $appointment_data['last_name']) . " successfully checked in with QR verification. Queue Number: " . $queue_number . " - Priority: " . ucfirst($priority) . ", Attendance: " . ucfirst($attendance_status);
            
            error_log("QR CHECK-IN DEBUG: Success message set: $message");
            
        } catch (Exception $e) {
            $conn->rollback();
            error_log("QR CHECK-IN DEBUG: Exception caught: " . $e->getMessage());
            $error = "Error during check-in: " . $e->getMessage();
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

    // Handle automatic cancellation for appointments after 5 PM
    if ($action === 'auto_cancel_late_appointments') {
        try {
            $conn->begin_transaction();
            $current_time = date('H:i:s');
            $current_date = date('Y-m-d');
            $cancelled_count = 0;

            // Only run if it's after 5 PM (17:00)
            if ($current_time >= '17:00:00') {
                // Handle confirmed appointments (no show)
                $confirmed_stmt = $conn->prepare("
                    SELECT a.appointment_id, a.patient_id, a.facility_id, a.scheduled_date, a.scheduled_time,
                           p.first_name, p.last_name
                    FROM appointments a 
                    JOIN patients p ON a.patient_id = p.patient_id
                    WHERE a.status = 'confirmed' 
                    AND DATE(a.scheduled_date) = ?
                ");
                $confirmed_stmt->bind_param("s", $current_date);
                $confirmed_stmt->execute();
                $confirmed_result = $confirmed_stmt->get_result();

                while ($apt = $confirmed_result->fetch_assoc()) {
                    // Update appointment to cancelled
                    $cancel_reason = "No show - Patient did not arrive by closing time";
                    $update_apt = $conn->prepare("UPDATE appointments SET status = 'cancelled', cancellation_reason = ? WHERE appointment_id = ?");
                    $update_apt->bind_param("si", $cancel_reason, $apt['appointment_id']);
                    $update_apt->execute();

                    // Create visit record for no show
                    $visit_stmt = $conn->prepare("INSERT INTO visits (patient_id, facility_id, appointment_id, visit_date, time_in, attending_employee_id, visit_status, attendance_status, remarks) VALUES (?, ?, ?, ?, NOW(), ?, 'cancelled', 'no_show', ?)");
                    $visit_stmt->bind_param("iiisis", $apt['patient_id'], $apt['facility_id'], $apt['appointment_id'], $current_date, $employee_id, $cancel_reason);
                    $visit_stmt->execute();

                    // Log the cancellation
                    $log_stmt = $conn->prepare("INSERT INTO appointment_logs (appointment_id, patient_id, action, old_status, new_status, reason, created_by_type, created_by_id) VALUES (?, ?, 'cancelled', 'confirmed', 'cancelled', ?, 'system', ?)");
                    $log_stmt->bind_param("iisi", $apt['appointment_id'], $apt['patient_id'], $cancel_reason, $employee_id);
                    $log_stmt->execute();

                    $cancelled_count++;
                }

                // Handle checked_in appointments (left early)
                $checkedin_stmt = $conn->prepare("
                    SELECT a.appointment_id, a.patient_id, a.facility_id, a.scheduled_date, a.scheduled_time,
                           p.first_name, p.last_name, v.visit_id
                    FROM appointments a 
                    JOIN patients p ON a.patient_id = p.patient_id
                    LEFT JOIN visits v ON a.appointment_id = v.appointment_id
                    WHERE a.status = 'checked_in' 
                    AND DATE(a.scheduled_date) = ?
                ");
                $checkedin_stmt->bind_param("s", $current_date);
                $checkedin_stmt->execute();
                $checkedin_result = $checkedin_stmt->get_result();

                while ($apt = $checkedin_result->fetch_assoc()) {
                    // Update appointment to cancelled
                    $cancel_reason = "Left early - Patient left before completing visit";
                    $update_apt = $conn->prepare("UPDATE appointments SET status = 'cancelled', cancellation_reason = ? WHERE appointment_id = ?");
                    $update_apt->bind_param("si", $cancel_reason, $apt['appointment_id']);
                    $update_apt->execute();

                    if ($apt['visit_id']) {
                        // Update existing visit record
                        $update_visit = $conn->prepare("UPDATE visits SET time_out = NOW(), visit_status = 'cancelled', attendance_status = 'left_early', remarks = ? WHERE visit_id = ?");
                        $update_visit->bind_param("si", $cancel_reason, $apt['visit_id']);
                        $update_visit->execute();
                    } else {
                        // Create visit record if doesn't exist
                        $visit_stmt = $conn->prepare("INSERT INTO visits (patient_id, facility_id, appointment_id, visit_date, time_in, time_out, attending_employee_id, visit_status, attendance_status, remarks) VALUES (?, ?, ?, ?, NOW(), NOW(), ?, 'cancelled', 'left_early', ?)");
                        $visit_stmt->bind_param("iiisis", $apt['patient_id'], $apt['facility_id'], $apt['appointment_id'], $current_date, $employee_id, $cancel_reason);
                        $visit_stmt->execute();
                    }

                    // Log the cancellation
                    $log_stmt = $conn->prepare("INSERT INTO appointment_logs (appointment_id, patient_id, action, old_status, new_status, reason, created_by_type, created_by_id) VALUES (?, ?, 'cancelled', 'checked_in', 'cancelled', ?, 'system', ?)");
                    $log_stmt->bind_param("iisi", $apt['appointment_id'], $apt['patient_id'], $cancel_reason, $employee_id);
                    $log_stmt->execute();

                    $cancelled_count++;
                }
            }

            $conn->commit();
            if ($cancelled_count > 0) {
                $message = "Automatically cancelled {$cancelled_count} late appointment(s) after closing time.";
            } else {
                $message = "No late appointments found to cancel.";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Failed to auto-cancel late appointments: " . $e->getMessage();
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

// Role-based appointment restriction - All employees should only see appointments for their assigned facility
$role = strtolower($employee_role);
$assigned_facility_id = '';

// Get employee's facility_id for all roles (including DHO)
$stmt = $conn->prepare("SELECT facility_id FROM employees WHERE employee_id = ?");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$assigned_facility_id = $row ? $row['facility_id'] : '';
$stmt->close();

// All roles (admin, doctor, nurse, dho, bhw, records_officer) only see appointments for their assigned facility
if ($assigned_facility_id) {
    $where_conditions[] = "a.facility_id = ?";
    $params[] = $assigned_facility_id;
    $param_types .= 'i';
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
    $count_row = $count_result->fetch_assoc();
    $total_records = ($count_row && isset($count_row['total'])) ? $count_row['total'] : 0;
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

    // Apply same facility-based restrictions to stats for all roles
    if (!empty($assigned_facility_id)) {
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
    // All roles can only see their assigned facility
    if (!empty($assigned_facility_id)) {
        $stmt = $conn->prepare("SELECT facility_id, name, district_id FROM facilities WHERE status = 'active' AND facility_id = ? ORDER BY name ASC");
        $stmt->bind_param("i", $assigned_facility_id);
    } else {
        // Fallback - should not happen if employee has valid facility assignment
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
        // Get the district_id for the employee's facility to show relevant barangays
        if (!empty($assigned_facility_id)) {
            $stmt = $conn->prepare("SELECT f.district_id FROM facilities f WHERE f.facility_id = ?");
            $stmt->bind_param("i", $assigned_facility_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $facility_district_id = $row ? $row['district_id'] : '';
            $stmt->close();

            if ($facility_district_id) {
                // Show barangays in the same district as the employee's facility
                $stmt = $conn->prepare("SELECT b.barangay_id, b.barangay_name FROM barangay b WHERE b.district_id = ? ORDER BY b.barangay_name ASC");
                $stmt->bind_param("i", $facility_district_id);
            } else {
                // Fallback - show all barangays if district not found
                $stmt = $conn->prepare("SELECT b.barangay_id, b.barangay_name FROM barangay b ORDER BY b.barangay_name ASC");
            }
        } else {
            // Fallback - show all barangays if facility not assigned
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

// Auto-check for late appointments if it's after 5 PM
$current_hour = (int)date('H');
$should_auto_cancel = false;
$auto_cancel_count = 0;

if ($current_hour >= 17) { // After 5 PM
    try {
        $current_date = date('Y-m-d');

        // Count appointments that need auto-cancellation
        $count_stmt = $conn->prepare("
            SELECT COUNT(*) as total
            FROM appointments a 
            WHERE (a.status = 'confirmed' OR a.status = 'checked_in') 
            AND DATE(a.scheduled_date) = ?
        ");
        $count_stmt->bind_param("s", $current_date);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $count_row = $count_result->fetch_assoc();
        $auto_cancel_count = ($count_row && isset($count_row['total'])) ? $count_row['total'] : 0;
        $count_stmt->close();

        if ($auto_cancel_count > 0) {
            $should_auto_cancel = true;
        }
    } catch (Exception $e) {
        // Silent fail for auto-check
    }
}
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
    <!-- QR Code Scanner Library with fallbacks -->
    <script>
        // Try to load from multiple CDNs
        function loadQRLibrary() {
            return new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.onload = resolve;
                script.onerror = () => {
                    // Fallback to second CDN
                    const fallbackScript = document.createElement('script');
                    fallbackScript.onload = resolve;
                    fallbackScript.onerror = reject;
                    fallbackScript.src = 'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.4/html5-qrcode.min.js';
                    document.head.appendChild(fallbackScript);
                };
                script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js';
                document.head.appendChild(script);
            });
        }
        
        // Load library when page loads
        document.addEventListener('DOMContentLoaded', () => {
            loadQRLibrary().catch(() => {
                console.warn('QR Scanner library failed to load from CDN');
                // Enable manual entry only
                document.addEventListener('DOMContentLoaded', () => {
                    const startBtn = document.getElementById('startScanBtn');
                    if (startBtn) {
                        startBtn.style.display = 'none';
                        const manualSection = document.querySelector('#qrScannerModal .manual-entry-section');
                        if (manualSection) {
                            manualSection.innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Camera scanner unavailable. Please use manual entry.</div>' + manualSection.innerHTML;
                        }
                    }
                });
            });
        });
    </script>
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
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
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
            0% {
                box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7);
            }

            70% {
                box-shadow: 0 0 0 6px rgba(255, 193, 7, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(255, 193, 7, 0);
            }
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

        /* Scrollable modal body for appointment details */
        #viewAppointmentModal .modal-body {
            max-height: 70vh;
            overflow-y: auto;
            padding: 30px;
            line-height: 1.6;
            color: #444;
        }

        /* Custom scrollbar for appointment modal */
        #viewAppointmentModal .modal-body::-webkit-scrollbar {
            width: 8px;
        }

        #viewAppointmentModal .modal-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        #viewAppointmentModal .modal-body::-webkit-scrollbar-thumb {
            background: #0077b6;
            border-radius: 4px;
        }

        #viewAppointmentModal .modal-body::-webkit-scrollbar-thumb:hover {
            background: #005a87;
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

        /* Modal appointment details styling */
        .appointment-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .details-section {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .details-section h4 {
            margin: 0 0 15px 0;
            color: #495057;
            font-size: 16px;
            font-weight: 600;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 8px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
            gap: 10px;
            text-align: left;
        }

        .detail-label {
            font-weight: 600;
            color: #6c757d;
            font-size: 14px;
            min-width: 120px;
            flex-shrink: 0;
        }

        .detail-value {
            color: #495057;
            font-size: 14px;
            text-align: right;
            flex-grow: 1;
            word-break: break-word;
        }

        .detail-value.highlight {
            background: linear-gradient(45deg, #e3f2fd, #bbdefb);
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            color: #1565c0;
        }

        .vitals-group {
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 12px;
        }

        .vitals-group h6 {
            margin: 0 0 8px 0;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .appointment-details-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .detail-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }

            .detail-label {
                min-width: auto;
            }

            .detail-value {
                text-align: left;
            }
        }
        
        /* QR Scanner Modal Styles */
        .scanner-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .scanner-camera-section,
        .scanner-manual-section {
            min-height: 280px;
        }

        .special-category-option:hover {
            background: #e3f2fd !important;
            border-color: #0077b6 !important;
        }

        .special-category-option input[type="checkbox"]:checked + i + span {
            color: #0077b6;
            font-weight: 600;
        }

        .priority-option {
            cursor: pointer;
            display: block;
            margin: 0;
        }

        .priority-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }

        .priority-card {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 15px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            background: white;
            transition: all 0.2s ease;
            cursor: pointer;
            min-height: 80px;
        }

        .priority-card:hover {
            border-color: #0077b6;
            background: #f8f9fa;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 119, 182, 0.1);
        }

        .priority-option input[type="radio"]:checked + .priority-card {
            border-color: #0077b6;
            background: #e3f2fd;
            box-shadow: 0 2px 8px rgba(0, 119, 182, 0.2);
        }

        .priority-card i {
            font-size: 24px;
            min-width: 30px;
            margin-top: 2px;
        }

        .priority-card div {
            flex: 1;
        }

        .priority-card strong {
            display: block;
            font-size: 1.1rem;
            margin-bottom: 4px;
            line-height: 1.2;
        }

        .priority-card span {
            font-size: 0.85rem;
            color: #666;
            line-height: 1.3;
            display: block;
        }

        .priority-card.emergency {
            border-left: 4px solid #dc3545;
        }

        .priority-card.emergency i {
            color: #dc3545;
        }

        .priority-option input[type="radio"]:checked + .priority-card.emergency {
            background: #fff5f5;
            border-color: #dc3545;
        }

        .priority-card.urgent {
            border-left: 4px solid #ffc107;
        }

        .priority-card.urgent i {
            color: #ffc107;
        }

        .priority-option input[type="radio"]:checked + .priority-card.urgent {
            background: #fffef7;
            border-color: #ffc107;
        }

        .priority-card.standard {
            border-left: 4px solid #28a745;
        }

        .priority-card.standard i {
            color: #28a745;
        }

        .priority-option input[type="radio"]:checked + .priority-card.standard {
            background: #f8fff8;
            border-color: #28a745;
        }

        .priority-card.low {
            border-left: 4px solid #6c757d;
        }

        .priority-card.low i {
            color: #6c757d;
        }

        .priority-option input[type="radio"]:checked + .priority-card.low {
            background: #f8f9fa;
            border-color: #6c757d;
        }

        /* Scanner specific styles */
        #qrVideo {
            background: #000;
            object-fit: cover;
            display: block;
            margin: 0 auto;
        }

        /* QR Scanner container */
        #cameraContainer {
            position: relative;
            text-align: center;
            margin-bottom: 15px;
            background: #f8f9fa;
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 10px;
            min-height: 245px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #cameraContainer.active {
            border-color: #0077b6;
            background: #fff;
        }

        .scanner-status {
            padding: 15px;
            border-radius: 8px;
            margin: 10px 0;
            text-align: center;
        }

        .scanner-status.scanning {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .scanner-status.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .scanner-status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Mobile responsive for QR scanner modal */
        @media (max-width: 768px) {
            .scanner-grid {
                grid-template-columns: 1fr !important;
                gap: 15px;
            }

            .scanner-camera-section,
            .scanner-manual-section {
                min-height: auto;
            }

            #qr-reader {
                max-width: 350px !important;
                height: 350px !important;
            }

            .priority-card {
                min-height: auto;
                padding: 12px;
            }

            .priority-card i {
                font-size: 20px;
                min-width: 24px;
            }

            .priority-card strong {
                font-size: 1rem;
            }

            .priority-card span {
                font-size: 0.8rem;
            }

            #prioritySection .priority-grid {
                grid-template-columns: 1fr !important;
                gap: 12px;
            }

            .modal-content {
                max-width: 95% !important;
                margin: 10px auto;
            }

            .modal-body {
                padding: 10px !important;
                max-height: calc(95vh - 120px) !important;
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
                // Use production-safe dashboard URLs
                $user_role = strtolower($_SESSION['role']);
                $dashboard_path = get_role_dashboard_url($user_role);
                ?>
                <a href="<?php echo htmlspecialchars($dashboard_path); ?>"><i class="fas fa-home"></i> Dashboard</a>
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
                <strong>Current Month Statistics:</strong> The statistics and table below show appointments made for <?php echo htmlspecialchars($facility_name); ?> on <?php echo date('F Y'); ?> only.
            </div>

            <?php if ($should_auto_cancel): ?>
                <!-- Auto-Cancellation Notice -->
                <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 12px 16px; margin-bottom: 1.5rem; color: #856404; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <i class="fas fa-exclamation-triangle" style="margin-right: 8px; color: #ffc107;"></i>
                        <strong>Late Appointments Detected:</strong> Found <?php echo $auto_cancel_count; ?> appointment(s) from today that may need cancellation after closing time (5 PM).
                    </div>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="action" value="auto_cancel_late_appointments">
                        <button type="submit" class="btn btn-warning btn-sm" style="padding: 8px 16px; font-size: 12px;">
                            <i class="fas fa-clock"></i> Auto-Cancel Late Appointments
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card total">
                    <div class="stat-number"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Appointments</div>
                </div>
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
                                        <?php echo htmlspecialchars($barangay['barangay_name']); ?>
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
                                        <th class="sortable" onclick="sortTable('appointment_id')">
                                            Appointment ID
                                            <?php echo getSortIcon('appointment_id', $sort_column, $sort_direction); ?>
                                        </th>
                                        <th class="sortable" onclick="sortTable('patient_id')">
                                            Patient ID
                                            <?php echo getSortIcon('patient_id', $sort_column, $sort_direction); ?>
                                        </th>
                                        <th class="sortable" onclick="sortTable('patient_name')">
                                            Patient Name
                                            <?php echo getSortIcon('patient_name', $sort_column, $sort_direction); ?>
                                        </th>
                                        <th class="sortable" onclick="sortTable('scheduled_date')">
                                            Scheduled Date
                                            <?php echo getSortIcon('scheduled_date', $sort_column, $sort_direction); ?>
                                        </th>
                                        <th class="sortable" onclick="sortTable('scheduled_time')">
                                            Scheduled Time
                                            <?php echo getSortIcon('scheduled_time', $sort_column, $sort_direction); ?>
                                        </th>
                                        <th class="sortable" onclick="sortTable('status')">
                                            Status
                                            <?php echo getSortIcon('status', $sort_column, $sort_direction); ?>
                                        </th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($appointments as $appointment): ?>
                                        <tr data-appointment-id="<?php echo $appointment['appointment_id']; ?>">
                                            <td><strong><?php echo 'APT-' . str_pad($appointment['appointment_id'], 8, '0', STR_PAD_LEFT); ?></strong></td>
                                            <td><?php echo htmlspecialchars($appointment['patient_id_display']); ?></td>
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
            <div class="modal-content" style="max-width: 900px;">
                <div class="modal-header">
                    <h3><i class="fas fa-calendar-check"></i> Appointment Details</h3>
                    <button type="button" class="close" onclick="closeModal('viewAppointmentModal')">&times;</button>
                </div>
                <div class="modal-body" id="appointmentDetailsContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" onclick="printAppointmentDetails()">
                        <i class="fas fa-print"></i> Print Details
                    </button>
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

        <!-- QR Scanner Check In Modal -->
        <div id="qrScannerModal" class="modal">
            <div class="modal-content" style="max-width: 900px; max-height: 95vh;">
                <div class="modal-header">
                    <h3><i class="fas fa-qrcode"></i> Scan Appointment QR Code</h3>
                    <button type="button" class="close" onclick="closeQRScannerModal()">&times;</button>
                </div>
                <div class="modal-body" style="max-height: calc(95vh - 140px); overflow-y: auto; padding: 15px;">
                    <!-- Scanner Section -->
                    <div id="scannerSection">
                        <div style="text-align: center; margin-bottom: 20px;">
                            <h4 style="color: #0077b6; margin-bottom: 8px; font-size: 1.1rem;">
                                <i class="fas fa-camera" style="margin-right: 8px;"></i>
                                Scan Patient's Appointment QR Code
                            </h4>
                            <p style="color: #666; margin: 0; font-size: 0.9rem;">Position the QR code within the camera frame to verify and check in the patient</p>
                        </div>
                        
                        <!-- Camera View Container -->
                        <div class="scanner-grid">
                            <!-- Camera Section -->
                            <div class="scanner-camera-section">
                                <h5 style="margin-bottom: 12px; color: #0077b6; text-align: center; font-size: 1rem;">
                                    <i class="fas fa-video"></i> Camera Scanner
                                </h5>
                                <div id="cameraContainer" style="position: relative; text-align: center; margin-bottom: 12px;">
                                    <div id="qr-reader" style="width: 400px; height: 400px; margin: 0 auto; border: 2px solid #ddd; border-radius: 8px; background: #f8f9fa;"></div>
                                </div>
                                
                                <!-- Scanner Controls -->
                                <div style="text-align: center;">
                                    <button type="button" id="startScanBtn" class="btn btn-primary btn-sm" onclick="startQRScanner()">
                                        <i class="fas fa-camera"></i> Start Camera
                                    </button>
                                    <button type="button" id="stopScanBtn" class="btn btn-secondary btn-sm" onclick="stopQRScanner()" style="display: none;">
                                        <i class="fas fa-stop"></i> Stop Camera
                                    </button>
                                    <br>
                                    <button type="button" class="btn btn-info btn-sm" onclick="debugCamera()" style="margin-top: 8px; font-size: 0.75rem; padding: 4px 8px;">
                                        <i class="fas fa-bug"></i> Debug
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Manual Input Section -->
                            <div class="scanner-manual-section">
                                <h5 style="margin-bottom: 12px; color: #0077b6; text-align: center; font-size: 1rem;">
                                    <i class="fas fa-keyboard"></i> Manual Entry
                                </h5>
                                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; height: 240px; display: flex; flex-direction: column; justify-content: center;">
                                    <p style="color: #666; font-size: 0.8rem; margin-bottom: 12px; text-align: center;">
                                        If QR code is not available or camera access is denied
                                    </p>
                                    <div style="margin-bottom: 12px;">
                                        <label for="manualAppointmentId" style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.85rem;">
                                            Appointment ID:
                                        </label>
                                        <input type="text" id="manualAppointmentId" class="search-input" 
                                               placeholder="Enter appointment ID (e.g., APT-00000123)" 
                                               style="width: 100%; font-size: 0.85rem; padding: 8px 12px;">
                                    </div>
                                    <button type="button" class="btn btn-info btn-sm" onclick="verifyManualEntry()" style="width: 100%;">
                                        <i class="fas fa-check"></i> Verify Appointment
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Scan Result -->
                        <div id="scanResult" style="display: none; margin-bottom: 15px;"></div>
                    </div>
                    
                    <!-- Priority Selection Section (hidden initially) -->
                    <div id="prioritySection" style="display: none;">
                        <div style="text-align: center; margin-bottom: 15px; padding: 15px; background: #e8f5e9; border-radius: 8px; border-left: 4px solid #28a745;">
                            <h4 style="margin: 0 0 8px 0; color: #155724; font-size: 1.1rem;">
                                <i class="fas fa-check-circle" style="margin-right: 8px;"></i>Appointment Verified Successfully!
                            </h4>
                            <div id="verifiedPatientInfo" style="color: #155724; font-weight: 500; font-size: 0.9rem;"></div>
                        </div>
                        
                        <!-- Priority Selection -->
                        <div style="background: #f8f9fa; padding: 25px; border-radius: 8px; margin-bottom: 20px;">
                            <h5 style="margin-bottom: 10px; color: #0077b6; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-list-ol"></i> Select Queue Priority
                            </h5>
                            <p style="color: #666; font-size: 0.9rem; margin-bottom: 20px;">
                                Choose the appropriate priority level and special category for this patient
                            </p>
                            
                            <!-- Standard Priority Levels -->
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                                <label class="priority-option">
                                    <input type="radio" name="queuePriority" value="emergency">
                                    <div class="priority-card emergency">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <div>
                                            <strong>Emergency</strong>
                                            <span>Life-threatening conditions, immediate attention required</span>
                                        </div>
                                    </div>
                                </label>
                                
                                <label class="priority-option">
                                    <input type="radio" name="queuePriority" value="urgent">
                                    <div class="priority-card urgent">
                                        <i class="fas fa-clock"></i>
                                        <div>
                                            <strong>Urgent</strong>
                                            <span>Serious conditions, should be seen within 30 minutes</span>
                                        </div>
                                    </div>
                                </label>
                                
                                <label class="priority-option">
                                    <input type="radio" name="queuePriority" value="standard" checked>
                                    <div class="priority-card standard">
                                        <i class="fas fa-user"></i>
                                        <div>
                                            <strong>Standard</strong>
                                            <span>Regular consultation, normal queue order</span>
                                        </div>
                                    </div>
                                </label>
                                
                                <label class="priority-option">
                                    <input type="radio" name="queuePriority" value="low">
                                    <div class="priority-card low">
                                        <i class="fas fa-calendar-check"></i>
                                        <div>
                                            <strong>Low Priority</strong>
                                            <span>Follow-up visits, non-urgent consultations</span>
                                        </div>
                                    </div>
                                </label>
                            </div>
                            
                            <!-- Special Categories -->
                            <div style="margin-bottom: 20px;">
                                <h6 style="margin-bottom: 15px; color: #0077b6; font-size: 1rem; border-bottom: 1px solid #dee2e6; padding-bottom: 8px;">
                                    <i class="fas fa-heart"></i> Special Categories (Optional)
                                </h6>
                                <div class="special-categories" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px;">
                                    <label class="special-category-option" style="display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: 6px; background: white; border: 1px solid #e9ecef; transition: all 0.2s ease; cursor: pointer;">
                                        <input type="checkbox" name="specialCategory" value="senior" style="margin: 0;">
                                        <i class="fas fa-user-clock" style="color: #6c757d; font-size: 1.1rem;"></i>
                                        <span style="font-size: 0.9rem; font-weight: 500;">Senior Citizen</span>
                                    </label>
                                    
                                    <label class="special-category-option" style="display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: 6px; background: white; border: 1px solid #e9ecef; transition: all 0.2s ease; cursor: pointer;">
                                        <input type="checkbox" name="specialCategory" value="pwd" style="margin: 0;">
                                        <i class="fas fa-wheelchair" style="color: #6c757d; font-size: 1.1rem;"></i>
                                        <span style="font-size: 0.9rem; font-weight: 500;">PWD</span>
                                    </label>
                                    
                                    <label class="special-category-option" style="display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: 6px; background: white; border: 1px solid #e9ecef; transition: all 0.2s ease; cursor: pointer;">
                                        <input type="checkbox" name="specialCategory" value="pregnant" style="margin: 0;">
                                        <i class="fas fa-baby" style="color: #6c757d; font-size: 1.1rem;"></i>
                                        <span style="font-size: 0.9rem; font-weight: 500;">Pregnant</span>
                                    </label>
                                    
                                    <label class="special-category-option" style="display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: 6px; background: white; border: 1px solid #e9ecef; transition: all 0.2s ease; cursor: pointer;">
                                        <input type="checkbox" name="specialCategory" value="injured" style="margin: 0;">
                                        <i class="fas fa-band-aid" style="color: #6c757d; font-size: 1.1rem;"></i>
                                        <span style="font-size: 0.9rem; font-weight: 500;">Injured</span>
                                    </label>
                                    
                                    <label class="special-category-option" style="display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: 6px; background: white; border: 1px solid #e9ecef; transition: all 0.2s ease; cursor: pointer;">
                                        <input type="checkbox" name="specialCategory" value="special_needs" style="margin: 0;">
                                        <i class="fas fa-hands-helping" style="color: #6c757d; font-size: 1.1rem;"></i>
                                        <span style="font-size: 0.9rem; font-weight: 500;">Special Needs</span>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Notes Section -->
                            <div style="margin-bottom: 15px;">
                                <label for="checkInNotes" style="display: block; margin-bottom: 8px; font-weight: 600; color: #0077b6;">
                                    <i class="fas fa-sticky-note"></i> Additional Notes (Optional)
                                </label>
                                <textarea id="checkInNotes" rows="3" placeholder="Any additional notes or special instructions for this check-in..." 
                                         style="width: 100%; padding: 10px; border: 2px solid #e9ecef; border-radius: 6px; resize: vertical;"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeQRScannerModal()">Cancel</button>
                    <button type="button" id="confirmCheckInBtn" class="btn btn-success" onclick="confirmCheckIn()" style="display: none;">
                        <i class="fas fa-sign-in-alt"></i> Confirm Check In
                    </button>
                </div>
            </div>
        </div>

        <!-- Success Modal -->
        <div id="successModal" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header" style="background: linear-gradient(135deg, #28a745, #20c997); color: white;">
                    <h3><i class="fas fa-check-circle"></i> Check-in Successful</h3>
                </div>
                <div class="modal-body" style="text-align: center; padding: 30px;" id="successMessage">
                    <!-- Success message will be inserted here -->
                </div>
                <div class="modal-footer" style="justify-content: center;">
                    <button type="button" class="btn btn-success" onclick="closeSuccessModal()">
                        <i class="fas fa-check"></i> Continue
                    </button>
                </div>
            </div>
        </div>

        <!-- Error Modal -->
        <div id="errorModal" class="modal" style="display: none;">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header" style="background: linear-gradient(135deg, #dc3545, #c82333); color: white;">
                    <h3><i class="fas fa-exclamation-triangle"></i> Check-in Failed</h3>
                </div>
                <div class="modal-body" style="text-align: center; padding: 30px;">
                    <i class="fas fa-exclamation-circle" style="font-size: 48px; color: #dc3545; margin-bottom: 15px;"></i>
                    <div id="errorMessage" style="color: #666; font-size: 1rem;">
                        <!-- Error message will be inserted here -->
                    </div>
                </div>
                <div class="modal-footer" style="justify-content: center;">
                    <button type="button" class="btn btn-danger" onclick="closeErrorModal()">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            </div>
        </div>

        <script>
            let currentAppointmentId = null;
            let html5QrCode = null;
            let verifiedAppointmentData = null;

            // QR Scanner Modal Functions
            function openQRScannerModal(appointmentId) {
                currentAppointmentId = appointmentId;
                document.getElementById('qrScannerModal').style.display = 'block';
                
                // Reset modal state
                document.getElementById('scannerSection').style.display = 'block';
                document.getElementById('prioritySection').style.display = 'none';
                document.getElementById('confirmCheckInBtn').style.display = 'none';
                document.getElementById('scanResult').style.display = 'none';
                document.getElementById('manualAppointmentId').value = '';
                
                // Reset priority selection to standard
                document.querySelector('input[name="queuePriority"][value="standard"]').checked = true;
                
                // Clear notes and special categories
                document.getElementById('checkInNotes').value = '';
                document.querySelectorAll('input[name="specialCategory"]').forEach(cb => cb.checked = false);
            }

            function closeQRScannerModal() {
                if (html5QrCode && html5QrCode.isScanning) {
                    html5QrCode.stop().then(() => {
                        document.getElementById('qrScannerModal').style.display = 'none';
                    }).catch(err => {
                        console.error('Error stopping scanner:', err);
                        document.getElementById('qrScannerModal').style.display = 'none';
                    });
                } else {
                    document.getElementById('qrScannerModal').style.display = 'none';
                }
                
                // Reset scanner state
                document.getElementById('startScanBtn').style.display = 'inline-block';
                document.getElementById('stopScanBtn').style.display = 'none';
                verifiedAppointmentData = null;
            }

            function startQRScanner() {
                console.log('Starting QR Scanner...');
                
                // Check if Html5Qrcode is available
                if (typeof Html5Qrcode === 'undefined') {
                    console.error('Html5Qrcode library not loaded');
                    showScannerError('QR Scanner library not loaded. Please use manual entry.');
                    return;
                }

                // Get the QR reader container element
                const qrReaderElement = document.getElementById('qr-reader');
                if (!qrReaderElement) {
                    console.error('QR reader container not found');
                    showScannerError('QR reader container not found.');
                    return;
                }

                // Show loading state
                const scanResult = document.getElementById('scanResult');
                scanResult.innerHTML = `
                    <div class="scanner-status scanning">
                        <i class="fas fa-spinner fa-spin"></i>
                        <strong>Initializing Camera...</strong>
                        <p style="margin: 5px 0;">Please allow camera access when prompted</p>
                    </div>
                `;
                scanResult.style.display = 'block';

                // Create new instance if needed
                if (!html5QrCode) {
                    try {
                        html5QrCode = new Html5Qrcode("qr-reader");
                        console.log('Html5Qrcode instance created');
                    } catch (error) {
                        console.error('Error creating Html5Qrcode instance:', error);
                        showScannerError('Failed to initialize scanner.');
                        return;
                    }
                }

                // Camera configuration
                const config = {
                    fps: 10,
                    qrbox: { width: 200, height: 200 },
                    aspectRatio: 1.0,
                    showTorchButtonIfSupported: true,
                    videoConstraints: {
                        facingMode: "environment"
                    }
                };

                // Get available cameras first
                Html5Qrcode.getCameras().then(cameras => {
                    console.log('Available cameras:', cameras);
                    
                    if (cameras && cameras.length > 0) {
                        // Use back camera if available, otherwise use first camera
                        const cameraId = cameras.length > 1 ? cameras[1].id : cameras[0].id;
                        
                        console.log('Using camera:', cameraId);
                        
                        // Start scanning
                        html5QrCode.start(
                            cameraId,
                            config,
                            (decodedText, decodedResult) => {
                                console.log('QR Code scanned:', decodedText);
                                handleQRScanResult(decodedText);
                            },
                            (errorMessage) => {
                                // Scanning errors are frequent and normal, don't log them
                            }
                        ).then(() => {
                            console.log('Scanner started successfully');
                            document.getElementById('startScanBtn').style.display = 'none';
                            document.getElementById('stopScanBtn').style.display = 'inline-block';
                            
                            // Add active class to camera container
                            document.getElementById('cameraContainer').classList.add('active');
                            
                            // Show active status
                            scanResult.innerHTML = `
                                <div class="scanner-status scanning">
                                    <i class="fas fa-camera"></i>
                                    <strong>Camera Active</strong>
                                    <p style="margin: 5px 0;">Position the QR code within the frame to scan</p>
                                </div>
                            `;
                        }).catch(err => {
                            console.error('Error starting camera:', err);
                            showScannerError(`Camera error: ${err.message || 'Unable to access camera. Please check permissions.'}`);
                        });
                    } else {
                        console.warn('No cameras found');
                        showScannerError('No cameras found on this device.');
                    }
                }).catch(err => {
                    console.error('Error getting cameras:', err);
                    showScannerError('Unable to access camera. Please check permissions and try again.');
                });
            }

            function showScannerError(message) {
                const scanResult = document.getElementById('scanResult');
                scanResult.innerHTML = `
                    <div class="scanner-status error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Camera Error</strong>
                        <p style="margin: 5px 0;">${message}</p>
                        <button type="button" class="btn btn-sm btn-info" onclick="debugCamera()" style="margin-top: 10px;">
                            <i class="fas fa-bug"></i> Debug Info
                        </button>
                    </div>
                `;
                scanResult.style.display = 'block';
                
                // Hide error after 10 seconds
                setTimeout(() => {
                    if (scanResult.querySelector('.scanner-status.error')) {
                        scanResult.style.display = 'none';
                    }
                }, 10000);
            }

            function debugCamera() {
                console.log('=== Camera Debug Info ===');
                console.log('Html5Qrcode available:', typeof Html5Qrcode !== 'undefined');
                console.log('Navigator mediaDevices:', !!navigator.mediaDevices);
                console.log('getUserMedia available:', !!navigator.mediaDevices?.getUserMedia);
                console.log('HTTPS:', location.protocol === 'https:');
                console.log('Localhost:', location.hostname === 'localhost' || location.hostname === '127.0.0.1');
                
                // Check camera permissions
                if (navigator.permissions) {
                    navigator.permissions.query({ name: 'camera' }).then(result => {
                        console.log('Camera permission:', result.state);
                    }).catch(err => {
                        console.log('Camera permission check failed:', err);
                    });
                }
                
                // Try to get cameras
                if (typeof Html5Qrcode !== 'undefined') {
                    Html5Qrcode.getCameras().then(cameras => {
                        console.log('Available cameras:', cameras);
                    }).catch(err => {
                        console.log('Error getting cameras:', err);
                    });
                }
                
                alert('Debug info logged to console. Press F12 and check the Console tab.');
            }

            function stopQRScanner() {
                if (html5QrCode && html5QrCode.isScanning) {
                    html5QrCode.stop().then(() => {
                        document.getElementById('startScanBtn').style.display = 'inline-block';
                        document.getElementById('stopScanBtn').style.display = 'none';
                        document.getElementById('scanResult').style.display = 'none';
                        
                        // Remove active class from camera container
                        document.getElementById('cameraContainer').classList.remove('active');
                    }).catch(err => {
                        console.error('Error stopping scanner:', err);
                    });
                }
            }

            function handleQRScanResult(qrData) {
                console.log('QR Code scanned:', qrData);
                
                // Stop the scanner
                stopQRScanner();
                
                // Parse QR data
                let appointmentData;
                try {
                    // Try to parse as JSON first (proper QR codes)
                    appointmentData = JSON.parse(qrData);
                    console.log('Parsed QR data:', appointmentData);
                    
                    // Ensure we have the required fields for QR verification
                    if (appointmentData.appointment_id && (appointmentData.qr_token || appointmentData.verification_code)) {
                        console.log('Valid QR code with token detected');
                        // Standardize the token field name
                        if (appointmentData.verification_code && !appointmentData.qr_token) {
                            appointmentData.qr_token = appointmentData.verification_code;
                        }
                    } else {
                        console.log('QR code missing required verification token');
                        appointmentData.qr_token = null; // Mark as invalid QR
                    }
                } catch (e) {
                    // If not JSON, treat as plain appointment ID (legacy or manual)
                    console.log('QR data is not JSON, treating as basic appointment ID');
                    const cleanId = qrData.replace(/^APT-/i, '');
                    appointmentData = { 
                        appointment_id: cleanId,
                        qr_token: null // No token = requires additional verification
                    };
                }
                
                // Display scan result
                const scanResult = document.getElementById('scanResult');
                if (appointmentData.qr_token) {
                    scanResult.innerHTML = `
                        <div class="alert alert-info">
                            <i class="fas fa-qrcode"></i> <strong>QR Code Detected</strong><br>
                            <small>Verifying authenticity...</small>
                        </div>
                    `;
                } else {
                    scanResult.innerHTML = `
                        <div class="alert alert-warning">
                            <i class="fas fa-qrcode"></i> <strong>Basic QR Code Detected</strong><br>
                            <small>Additional verification required for security...</small>
                        </div>
                    `;
                }
                scanResult.style.display = 'block';
                
                // Verify the scanned appointment
                verifyScannedAppointment(appointmentData);
            }

            function verifyScannedAppointment(appointmentData) {
                console.log('Verifying appointment:', appointmentData);
                console.log('Current appointment ID:', currentAppointmentId);
                
                // Check if this is from QR scan (has qr_token) or manual entry
                const isQRScan = appointmentData.qr_token || appointmentData.verification_code;
                const scannedAppointmentId = parseInt(appointmentData.appointment_id);
                
                console.log('Scanned appointment ID (parsed):', scannedAppointmentId);
                console.log('Is QR scan:', isQRScan);
                console.log('Comparison result:', scannedAppointmentId === currentAppointmentId);
                
                if (scannedAppointmentId === currentAppointmentId) {
                    if (isQRScan) {
                        // QR scan - verify the token against database
                        verifyQRToken(appointmentData);
                    } else {
                        // Manual entry - require additional verification
                        requireAdditionalVerification(appointmentData);
                    }
                } else {
                    // No match - show error
                    console.log('Appointment mismatch detected!');
                    showAppointmentMismatch(appointmentData, scannedAppointmentId);
                }
            }

            function verifyQRToken(appointmentData) {
                console.log('Verifying QR token for appointment:', appointmentData);
                
                // Show loading state
                const scanResult = document.getElementById('scanResult');
                scanResult.innerHTML = `
                    <div class="scanner-status scanning">
                        <i class="fas fa-spinner fa-spin"></i>
                        <strong>Verifying QR Code...</strong>
                        <p>Checking authenticity against database</p>
                    </div>
                `;
                
                // Verify token with the server
                const formData = new FormData();
                formData.append('action', 'verify_qr_token');
                formData.append('appointment_id', currentAppointmentId);
                formData.append('qr_token', appointmentData.qr_token || appointmentData.verification_code);
                
                fetch('api/verify_appointment_qr.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('QR token verified successfully');
                        showAppointmentMatch(appointmentData);
                    } else {
                        console.log('QR token verification failed:', data.message);
                        showQRVerificationError(data.message || 'Invalid QR code - this may be forged or expired');
                    }
                })
                .catch(error => {
                    console.error('QR verification error:', error);
                    showQRVerificationError('Unable to verify QR code. Please try manual entry.');
                });
            }

            function requireAdditionalVerification(appointmentData) {
                console.log('Requiring verification code for manual entry');
                
                const scanResult = document.getElementById('scanResult');
                scanResult.innerHTML = `
                    <div class="verification-required">
                        <div class="alert alert-warning">
                            <i class="fas fa-shield-alt"></i> <strong>Verification Required</strong><br>
                            <small>Please enter the verification code from your appointment confirmation</small>
                        </div>
                        
                        <div class="verification-form">
                            <div class="form-group" style="margin: 15px 0;">
                                <label for="verificationCodeInput" style="display: block; margin-bottom: 5px; font-weight: 600;">
                                    <i class="fas fa-key"></i> Verification Code:
                                </label>
                                <input type="text" 
                                       id="verificationCodeInput" 
                                       placeholder="Enter 8-character code (e.g., A1B2C3D4)"
                                       style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; font-size: 16px; text-transform: uppercase; letter-spacing: 2px; text-align: center; font-family: monospace;"
                                       maxlength="8"
                                       pattern="[A-Z0-9]{8}">
                                <small style="display: block; margin-top: 5px; color: #666;">
                                    <i class="fas fa-info-circle"></i> Find this code in your appointment confirmation or QR code
                                </small>
                            </div>
                            
                            <div style="background: #e3f2fd; padding: 12px; border-radius: 6px; margin: 15px 0; font-size: 14px;">
                                <i class="fas fa-lightbulb" style="color: #1976d2;"></i> 
                                <strong>Where to find your verification code:</strong>
                                <ul style="margin: 8px 0 0 20px; padding: 0;">
                                    <li>In your appointment confirmation email/SMS</li>
                                    <li>On your printed appointment slip</li>
                                    <li>Ask staff if you can't locate it</li>
                                </ul>
                            </div>
                            
                            <div style="text-align: center; margin-top: 20px;">
                                <button type="button" 
                                        class="btn btn-primary" 
                                        onclick="processVerificationCode()"
                                        style="padding: 12px 25px; font-size: 16px;">
                                    <i class="fas fa-check"></i> Verify Code
                                </button>
                                <button type="button" 
                                        class="btn btn-secondary" 
                                        onclick="cancelVerification()"
                                        style="padding: 12px 25px; margin-left: 10px; font-size: 16px;">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </div>
                            
                            <div style="text-align: center; margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;">
                                <button type="button" 
                                        class="btn btn-link" 
                                        onclick="requestStaffHelp()"
                                        style="color: #007bff; text-decoration: none; font-size: 14px;">
                                    <i class="fas fa-hands-helping"></i> Need staff assistance?
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                
                scanResult.style.display = 'block';
                
                // Auto-format and validate input
                document.getElementById('verificationCodeInput').addEventListener('input', function(e) {
                    let value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
                    e.target.value = value;
                    
                    // Enable/disable verify button based on length
                    const verifyBtn = document.querySelector('button[onclick="processVerificationCode()"]');
                    if (value.length === 8) {
                        verifyBtn.style.background = '#28a745';
                        verifyBtn.disabled = false;
                    } else {
                        verifyBtn.style.background = '#6c757d';
                        verifyBtn.disabled = false; // Keep enabled for partial validation
                    }
                });
                
                // Allow Enter key to submit
                document.getElementById('verificationCodeInput').addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        processVerificationCode();
                    }
                });
                
                // Focus the input
                setTimeout(() => {
                    document.getElementById('verificationCodeInput').focus();
                }, 100);
            }

            function processVerificationCode() {
                const verificationCode = document.getElementById('verificationCodeInput').value.trim().toUpperCase();
                
                if (!verificationCode) {
                    showVerificationError('Please enter a verification code');
                    return;
                }
                
                if (verificationCode.length !== 8) {
                    showVerificationError('Verification code must be exactly 8 characters');
                    return;
                }
                
                // Show loading state
                const scanResult = document.getElementById('scanResult');
                scanResult.innerHTML = `
                    <div class="scanner-status scanning">
                        <i class="fas fa-spinner fa-spin"></i>
                        <strong>Verifying Code...</strong>
                        <p>Checking verification code against appointment records</p>
                    </div>
                `;
                
                // Verify code with the server
                const formData = new FormData();
                formData.append('action', 'verify_verification_code');
                formData.append('appointment_id', currentAppointmentId);
                formData.append('verification_code', verificationCode);
                
                fetch('api/verify_appointment_qr.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Verification code verified successfully');
                        showAppointmentMatch({ appointment_id: currentAppointmentId, verification_method: 'verification_code' });
                    } else {
                        console.log('Verification code verification failed:', data.message);
                        showCodeVerificationError(data.message || 'Invalid verification code');
                    }
                })
                .catch(error => {
                    console.error('Verification code error:', error);
                    showCodeVerificationError('Unable to verify code. Please try again or contact staff.');
                });
            }

            function showCodeVerificationError(message) {
                const scanResult = document.getElementById('scanResult');
                scanResult.innerHTML = `
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-triangle"></i> <strong>❌ Invalid Verification Code!</strong><br>
                        <small>${message}</small>
                        <div style="margin-top: 15px;">
                            <button type="button" 
                                    class="btn btn-info btn-sm" 
                                    onclick="requireAdditionalVerification({ appointment_id: currentAppointmentId })"
                                    style="padding: 8px 15px;">
                                <i class="fas fa-redo"></i> Try Again
                            </button>
                            <button type="button" 
                                    class="btn btn-warning btn-sm" 
                                    onclick="requestStaffHelp()"
                                    style="padding: 8px 15px; margin-left: 10px;">
                                <i class="fas fa-hands-helping"></i> Get Help
                            </button>
                        </div>
                    </div>
                `;
                
                scanResult.style.display = 'block';
            }

            function requestStaffHelp() {
                const scanResult = document.getElementById('scanResult');
                scanResult.innerHTML = `
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <strong>Staff Assistance Required</strong><br>
                        <p>Please ask a staff member to help locate your verification code.</p>
                        <div style="background: #e3f2fd; padding: 12px; border-radius: 4px; margin: 10px 0;">
                            <strong>For Staff:</strong> The verification code can be found in the appointment database 
                            or can be regenerated if necessary. Use admin verification if the patient cannot locate their code.
                        </div>
                        <div style="margin-top: 15px;">
                            <button type="button" 
                                    class="btn btn-secondary" 
                                    onclick="document.getElementById('scanResult').style.display = 'none'"
                                    style="padding: 8px 15px;">
                                <i class="fas fa-times"></i> Close
                            </button>
                        </div>
                    </div>
                `;
            }

            function cancelVerification() {
                document.getElementById('scanResult').style.display = 'none';
            }

            function showQRVerificationError(message) {
                const scanResult = document.getElementById('scanResult');
                scanResult.innerHTML = `
                    <div class="alert alert-error" style="display: block; background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 8px; margin: 10px 0;">
                        <i class="fas fa-exclamation-triangle"></i> <strong>❌ Invalid QR Code!</strong><br>
                        <small>${message}</small>
                        <div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 4px; border: 1px solid #ffeaa7; color: #856404;">
                            <strong>Security Notice:</strong> Only scan QR codes directly from the appointment confirmation.
                            For manual entry, additional verification will be required.
                        </div>
                    </div>
                `;
                
                // Make sure the scan result is visible
                scanResult.style.display = 'block';
                
                // Allow user to try again or use manual entry
                setTimeout(() => {
                    const currentContent = scanResult.innerHTML;
                    if (currentContent.includes('Invalid QR Code')) {
                        scanResult.innerHTML = currentContent + `
                            <div style="text-align: center; margin-top: 15px;">
                                <button type="button" 
                                        class="btn btn-info" 
                                        onclick="document.getElementById('manualAppointmentId').focus()"
                                        style="padding: 10px 20px; margin-right: 10px;">
                                    <i class="fas fa-keyboard"></i> Use Manual Entry
                                </button>
                                <button type="button" 
                                        class="btn btn-secondary" 
                                        onclick="document.getElementById('scanResult').style.display = 'none'"
                                        style="padding: 10px 20px;">
                                    <i class="fas fa-times"></i> Close
                                </button>
                            </div>
                        `;
                    }
                }, 2000);
            }

            function showAppointmentMatch(appointmentData) {
                // Update scan result to show success
                const scanResult = document.getElementById('scanResult');
                scanResult.innerHTML = `
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <strong>✅ Appointment Verified!</strong><br>
                        <small>QR code matches the selected appointment (ID: ${appointmentData.appointment_id})</small>
                    </div>
                `;
                
                // Get appointment details for display
                fetch(`api/get_appointment_details.php?appointment_id=${currentAppointmentId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            verifiedAppointmentData = data.appointment;
                            showEnhancedPrioritySelection();
                        } else {
                            showVerificationError('Failed to load appointment details');
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching appointment details:', error);
                        showVerificationError('Error loading appointment details');
                    });
            }

            function showAppointmentMismatch(appointmentData, scannedId) {
                console.log('Showing appointment mismatch error');
                
                // Show mismatch error
                const scanResult = document.getElementById('scanResult');
                scanResult.innerHTML = `
                    <div class="alert alert-error" style="display: block; background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 8px; margin: 10px 0;">
                        <i class="fas fa-exclamation-triangle"></i> <strong>❌ Appointment Mismatch!</strong><br>
                        <small>Expected: Appointment ${currentAppointmentId}<br>
                        Scanned: Appointment ${scannedId}<br>
                        Please scan the correct QR code or use manual entry.</small>
                        
                        <div style="text-align: center; margin-top: 15px;">
                            <button type="button" 
                                    class="btn btn-info" 
                                    onclick="document.getElementById('manualAppointmentId').focus(); document.getElementById('scanResult').style.display = 'none';"
                                    style="padding: 8px 15px; margin-right: 10px; background: #17a2b8; color: white; border: none; border-radius: 4px;">
                                <i class="fas fa-keyboard"></i> Use Manual Entry
                            </button>
                            <button type="button" 
                                    class="btn btn-secondary" 
                                    onclick="document.getElementById('scanResult').style.display = 'none'"
                                    style="padding: 8px 15px; background: #6c757d; color: white; border: none; border-radius: 4px;">
                                <i class="fas fa-times"></i> Close
                            </button>
                        </div>
                    </div>
                `;
                
                // Make sure the scan result is visible
                scanResult.style.display = 'block';
                
                // Auto-hide after 15 seconds
                setTimeout(() => {
                    if (document.getElementById('scanResult').innerHTML.includes('Appointment Mismatch')) {
                        console.log('Auto-hiding mismatch error message');
                        document.getElementById('scanResult').style.display = 'none';
                    }
                }, 15000);
            }

            function showEnhancedPrioritySelection() {
                // Hide scanner section
                document.getElementById('scannerSection').style.display = 'none';
                
                // Show patient info
                const patientInfo = `
                    <strong>${verifiedAppointmentData.patient_name}</strong><br>
                    Appointment ID: ${verifiedAppointmentData.appointment_id ? 'APT-' + String(verifiedAppointmentData.appointment_id).padStart(8, '0') : 'N/A'}<br>
                    Date: ${verifiedAppointmentData.appointment_date} at ${verifiedAppointmentData.time_slot}
                `;
                document.getElementById('verifiedPatientInfo').innerHTML = patientInfo;
                
                // Show priority section
                document.getElementById('prioritySection').style.display = 'block';
                document.getElementById('confirmCheckInBtn').style.display = 'inline-block';
            }

            function verifyManualEntry() {
                const appointmentId = document.getElementById('manualAppointmentId').value.trim();
                if (!appointmentId) {
                    showVerificationError('Please enter an appointment ID');
                    return;
                }
                
                // Remove APT- prefix if present and extract number
                const cleanId = appointmentId.replace(/^APT-/i, '');
                const appointmentData = { 
                    appointment_id: cleanId,
                    qr_token: null // No QR token = requires additional verification
                };
                
                // Verify the manually entered appointment
                verifyScannedAppointment(appointmentData);
            }

            function showVerificationError(message) {
                const scanResult = document.getElementById('scanResult');
                scanResult.innerHTML = `
                    <div class="scanner-status error">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Verification Failed</strong>
                        <p>${message}</p>
                    </div>
                `;
                scanResult.style.display = 'block';
                
                // Allow user to try again
                setTimeout(() => {
                    document.getElementById('scanResult').style.display = 'none';
                }, 5000);
            }

            function confirmCheckIn() {
                const selectedPriority = document.querySelector('input[name="queuePriority"]:checked').value;
                const notes = document.getElementById('checkInNotes').value.trim();
                
                // Get selected special categories
                const specialCategories = Array.from(document.querySelectorAll('input[name="specialCategory"]:checked'))
                    .map(checkbox => checkbox.value);
                
                // Create enhanced notes with special categories
                let enhancedNotes = notes;
                if (specialCategories.length > 0) {
                    const categoriesText = specialCategories.map(cat => {
                        switch(cat) {
                            case 'senior': return 'Senior Citizen';
                            case 'pwd': return 'PWD';
                            case 'pregnant': return 'Pregnant';
                            case 'injured': return 'Injured';
                            case 'special_needs': return 'Special Needs';
                            default: return cat;
                        }
                    }).join(', ');
                    
                    enhancedNotes = `Special Categories: ${categoriesText}${notes ? '\n\nAdditional Notes: ' + notes : ''}`;
                }
                
                // Prepare check-in data
                const checkInData = {
                    action: 'checkin_appointment_with_priority',
                    appointment_id: currentAppointmentId,
                    priority: selectedPriority,
                    special_categories: specialCategories.join(','),
                    notes: enhancedNotes
                };
                
                // Show loading state
                const confirmBtn = document.getElementById('confirmCheckInBtn');
                const originalText = confirmBtn.innerHTML;
                confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                confirmBtn.disabled = true;
                
                // Submit check-in using fetch to handle response properly
                fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams(checkInData)
                })
                .then(response => {
                    // Parse the response text to check for success/error messages
                    return response.text();
                })
                .then(responseText => {
                    // Debug: Log the response to see what we received
                    console.log('QR Check-in Response:', responseText);
                    
                    // Check if the response contains success or error indicators
                    if (responseText.includes('Patient successfully checked in') || 
                        responseText.includes('alert-success') ||
                        (responseText.includes('Queue Number:') && !responseText.includes('alert-error'))) {
                        
                        // Success - extract queue number if possible
                        let queueNumber = 'Unknown';
                        const queueMatch = responseText.match(/Queue Number:\s*([A-Z0-9-]+)/i);
                        if (queueMatch) {
                            queueNumber = queueMatch[1];
                        }
                        
                        // Close modal and show success
                        closeQRScannerModal();
                        
                        // Determine the station based on priority
                        let queueStation = 'Triage Station'; // Default station
                        
                        // Enhanced station assignment logic
                        if (selectedPriority === 'emergency') {
                            queueStation = 'Emergency Station';
                        } else if (specialCategories.includes('injured')) {
                            queueStation = 'Trauma/Injury Station';
                        } else if (specialCategories.includes('pregnant')) {
                            queueStation = 'Maternal Care Station';
                        } else if (specialCategories.includes('senior') || specialCategories.includes('pwd')) {
                            queueStation = 'Priority Care Station';
                        } else {
                            queueStation = 'General Triage Station';
                        }
                        
                        // Show success modal with queue number
                        showSuccessModalWithQueue(selectedPriority, specialCategories, queueStation, queueNumber);
                        
                    } else if (responseText.includes('alert-error') || 
                              responseText.includes('Error during check-in') ||
                              responseText.includes('Failed to')) {
                        
                        // Extract error message from response
                        let errorMessage = 'Unknown error occurred during check-in';
                        
                        // Try to extract specific error message
                        const errorMatch = responseText.match(/Error during check-in:\s*([^<]+)/i);
                        if (errorMatch) {
                            errorMessage = errorMatch[1].trim();
                        } else {
                            // Look for other error patterns
                            const alertMatch = responseText.match(/<div[^>]*class="[^"]*alert-error[^"]*"[^>]*>.*?<\/div>/is);
                            if (alertMatch) {
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = alertMatch[0];
                                errorMessage = tempDiv.textContent.trim().replace(/^\s*⚠️?\s*/, '');
                            }
                        }
                        
                        // Show error modal
                        showErrorModal(errorMessage);
                        
                    } else {
                        // Unclear response - assume success but warn user
                        closeQRScannerModal();
                        showSuccessModalWithQueue(selectedPriority, specialCategories, 'General Station', 'Please check with staff');
                    }
                    
                    // Reset button state
                    confirmBtn.innerHTML = originalText;
                    confirmBtn.disabled = false;
                    
                })
                .catch(error => {
                    console.error('Check-in fetch error:', error);
                    
                    // Show error in modal
                    showErrorModal('Network error occurred during check-in. Please try again.');
                    
                    // Reset button state
                    confirmBtn.innerHTML = originalText;
                    confirmBtn.disabled = false;
                });
            }

            function showSuccessModalWithQueue(priority, specialCategories, station = 'Triage', queueNumber = 'Unknown') {
                const categoryText = specialCategories.length > 0 ? 
                    specialCategories.map(cat => {
                        switch(cat) {
                            case 'senior': return 'Senior Citizen';
                            case 'pwd': return 'PWD';
                            case 'pregnant': return 'Pregnant';
                            case 'injured': return 'Injured';
                            case 'special_needs': return 'Special Needs';
                            default: return cat;
                        }
                    }).join(', ') : '';

                const specialCategoryDisplay = categoryText ? `<br><strong>Special Categories:</strong> ${categoryText}` : '';
                
                const successMessage = `
                    <h4 style="color: #28a745; margin-bottom: 15px;">Patient Successfully Checked In!</h4>
                    <div style="background: #f8fff8; border: 1px solid #c3e6cb; border-radius: 8px; padding: 15px; margin: 15px 0;">
                        <strong>Priority Level:</strong> ${priority.charAt(0).toUpperCase() + priority.slice(1)}${specialCategoryDisplay}
                        <br><strong>Queue Station:</strong> ${station}
                        <br><strong>Queue Number:</strong> <span style="background: #0077b6; color: white; padding: 4px 8px; border-radius: 12px; font-weight: bold;">${queueNumber}</span>
                    </div>
                    <div style="background: #e3f2fd; border: 1px solid #90caf9; border-radius: 8px; padding: 12px; margin: 15px 0; text-align: center;">
                        <i class="fas fa-info-circle" style="color: #1976d2; margin-right: 8px;"></i>
                        <strong style="color: #1976d2;">Please keep the queue number for reference</strong>
                    </div>
                    <p style="color: #666; font-size: 0.9rem; margin-top: 15px;">
                        The patient has been added to the queue and will be called when it's their turn.
                    </p>
                `;
                
                document.getElementById('successMessage').innerHTML = successMessage;
                document.getElementById('successModal').style.display = 'block';
            }

            function closeSuccessModal() {
                document.getElementById('successModal').style.display = 'none';
                location.reload(); // Refresh the page to show updated appointment list
            }

            function showErrorModal(message) {
                document.getElementById('errorMessage').innerHTML = message;
                document.getElementById('errorModal').style.display = 'block';
            }

            function closeErrorModal() {
                document.getElementById('errorModal').style.display = 'none';
            }

            // Enhanced check-in function (original function for backward compatibility)
            function checkInAppointment(appointmentId) {
                // Use QR scanner modal instead of simple check-in
                openQRScannerModal(appointmentId);
            }

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
                            // Debug: Log raw response for troubleshooting
                            console.log('Raw API Response:', text);
                            console.log('Response length:', text.length);
                            
                            if (!text || text.trim() === '') {
                                throw new Error('Empty response from server');
                            }
                            
                            try {
                                return JSON.parse(text);
                            } catch (e) {
                                console.error('JSON Parse Error:', e);
                                console.error('Response Text:', text);
                                throw new Error(`Invalid JSON response: ${text.substring(0, 200)}...`);
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

            function formatAttendanceStatus(status) {
                if (!status) return null;

                const statusMap = {
                    'early': 'Early Arrival',
                    'on_time': 'On Time',
                    'late': 'Late Arrival',
                    'no_show': 'No Show',
                    'left_early': 'Left Early'
                };

                return statusMap[status] || status.charAt(0).toUpperCase() + status.slice(1);
            }

            // Helper functions to generate vital status indicators for appointment details
            function getBloodPressureStatus(systolic, diastolic) {
                if (!systolic && !diastolic) return '';

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

                return `<span class="vital-status ${className}" style="margin-left: 8px; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 2px;">${icon} ${status}</span>`;
            }

            function getHeartRespiratoryStatus(heartRate, respRate) {
                if (!heartRate && !respRate) return '';

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

                return `<span class="vital-status ${className}" style="margin-left: 8px; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 2px;">${icon} ${status}</span>`;
            }

            function getTemperatureStatus(temp) {
                if (!temp) return '';

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

                return `<span class="vital-status ${className}" style="margin-left: 8px; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 2px;">${icon} ${status}</span>`;
            }

            function getBMIStatus(weight, height) {
                if (!weight || !height || weight <= 0 || height <= 0) return '';

                const heightInMeters = height / 100;
                const bmi = (weight / (heightInMeters * heightInMeters)).toFixed(1);

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

                return `<span class="vital-status ${className}" style="margin-left: 8px; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 2px;">${icon} ${status}</span>`;
            }

            function displayAppointmentDetails(appointment) {
                // Debug: Log the appointment data to see what we're receiving
                console.log('Appointment data received:', appointment);
                console.log('Visit details:', appointment.visit_details);
                console.log('Vitals data:', appointment.vitals);

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
                            <span class="detail-label">Appointment ID:</span>
                            <span class="detail-value highlight">APT-${appointment.appointment_id ? String(appointment.appointment_id).padStart(8, '0') : 'N/A'}</span>
                        </div>
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
                
                <div class="details-section">
                    <h4><i class="fas fa-qrcode"></i> QR Code Information</h4>
                        ${appointment.qr_code_path ? `
                            <div class="detail-item">
                                <span class="detail-label">QR Code:</span>
                                <span class="detail-value" style="color: #28a745; font-weight: 600;">Available</span>
                            </div>
                            <div class="detail-item" style="margin-top: 10px;justify-content: center;">
                                <div class="qr-code-container" style="text-align: center;">
                                    <img src="data:image/png;base64,${appointment.qr_code_path}" 
                                         alt="Appointment QR Code">
                                    <p class="qr-code-description">Scan this QR code for quick appointment access</p>
                                </div>
                            </div>
                        ` : `
                            <div class="detail-item">
                                <span class="detail-label">QR Code:</span>
                                <span class="detail-value" style="color: #6c757d;">No QR code generated for this appointment</span>
                            </div>
                        `}
                </div>

                ${((appointment.status || '').toLowerCase() === 'completed' || (appointment.status || '').toLowerCase() === 'cancelled') && appointment.visit_details ? `
                    <div class="details-section" style="border-left: 4px solid #17a2b8; background: #f8f9fa; margin-top: 20px;">
                        <h4><i class="fas fa-clipboard-check" style="color: #17a2b8;"></i> Visit Details</h4>
                        <div class="detail-item">
                            <span class="detail-label">Check-in Time:</span>
                            <span class="detail-value">${appointment.visit_details.time_in || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Check-out Time:</span>
                            <span class="detail-value">${appointment.visit_details.time_out || 'Not completed'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Attending Employee:</span>
                            <span class="detail-value">${appointment.visit_details.attending_employee_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Attendance Status:</span>
                            <span class="detail-value">${formatAttendanceStatus(appointment.visit_details.attendance_status) || 'N/A'}</span>
                        </div>
                        ${appointment.visit_details.remarks ? `
                            <div class="detail-item">
                                <span class="detail-label">Visit Remarks:</span>
                                <span class="detail-value">${appointment.visit_details.remarks}</span>
                            </div>
                        ` : ''}
                    </div>
                ` : ''}

                ${((appointment.status || '').toLowerCase() === 'completed' || (appointment.status || '').toLowerCase() === 'cancelled') && appointment.vitals ? `
                    <div class="details-section" style="border-left: 4px solid #28a745; background: #f8fff8; margin-top: 20px;">
                        <h4><i class="fas fa-heartbeat" style="color: #28a745;"></i> Recorded Vitals</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                            ${appointment.vitals.systolic_bp || appointment.vitals.diastolic_bp ? `
                                <div class="vitals-group">
                                    <h6 style="color: #0077b6; margin-bottom: 8px; font-size: 14px;">
                                        <i class="fas fa-tint"></i> Blood Pressure
                                        ${getBloodPressureStatus(appointment.vitals.systolic_bp, appointment.vitals.diastolic_bp)}
                                    </h6>
                                    <div class="detail-item">
                                        <span class="detail-label">Systolic:</span>
                                        <span class="detail-value">${appointment.vitals.systolic_bp || 'N/A'} mmHg</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Diastolic:</span>
                                        <span class="detail-value">${appointment.vitals.diastolic_bp || 'N/A'} mmHg</span>
                                    </div>
                                </div>
                            ` : ''}
                            
                            ${appointment.vitals.heart_rate || appointment.vitals.respiratory_rate ? `
                                <div class="vitals-group">
                                    <h6 style="color: #0077b6; margin-bottom: 8px; font-size: 14px;">
                                        <i class="fas fa-heart"></i> Heart & Respiratory
                                        ${getHeartRespiratoryStatus(appointment.vitals.heart_rate, appointment.vitals.respiratory_rate)}
                                    </h6>
                                    ${appointment.vitals.heart_rate ? `
                                        <div class="detail-item">
                                            <span class="detail-label">Heart Rate:</span>
                                            <span class="detail-value">${appointment.vitals.heart_rate} bpm</span>
                                        </div>
                                    ` : ''}
                                    ${appointment.vitals.respiratory_rate ? `
                                        <div class="detail-item">
                                            <span class="detail-label">Respiratory Rate:</span>
                                            <span class="detail-value">${appointment.vitals.respiratory_rate} breaths/min</span>
                                        </div>
                                    ` : ''}
                                </div>
                            ` : ''}
                            
                            ${appointment.vitals.temperature ? `
                                <div class="vitals-group">
                                    <h6 style="color: #0077b6; margin-bottom: 8px; font-size: 14px;">
                                        <i class="fas fa-thermometer-half"></i> Temperature
                                        ${getTemperatureStatus(appointment.vitals.temperature)}
                                    </h6>
                                    <div class="detail-item">
                                        <span class="detail-label">Temperature:</span>
                                        <span class="detail-value">${appointment.vitals.temperature}°C</span>
                                    </div>
                                </div>
                            ` : ''}
                            
                            ${appointment.vitals.weight || appointment.vitals.height ? `
                                <div class="vitals-group">
                                    <h6 style="color: #0077b6; margin-bottom: 8px; font-size: 14px;">
                                        <i class="fas fa-weight"></i> Physical Measurements
                                        ${appointment.vitals.weight && appointment.vitals.height ? getBMIStatus(appointment.vitals.weight, appointment.vitals.height) : ''}
                                    </h6>
                                    ${appointment.vitals.weight ? `
                                        <div class="detail-item">
                                            <span class="detail-label">Weight:</span>
                                            <span class="detail-value">${appointment.vitals.weight} kg</span>
                                        </div>
                                    ` : ''}
                                    ${appointment.vitals.height ? `
                                        <div class="detail-item">
                                            <span class="detail-label">Height:</span>
                                            <span class="detail-value">${appointment.vitals.height} cm</span>
                                        </div>
                                    ` : ''}
                                    ${appointment.vitals.weight && appointment.vitals.height ? `
                                        <div class="detail-item">
                                            <span class="detail-label">BMI:</span>
                                            <span class="detail-value">${((appointment.vitals.weight / Math.pow(appointment.vitals.height / 100, 2)).toFixed(1))} kg/m²</span>
                                        </div>
                                    ` : ''}
                                </div>
                            ` : ''}
                        </div>
                        
                        ${appointment.vitals.remarks ? `
                            <div class="detail-item" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e9ecef;">
                                <span class="detail-label">Vitals Remarks:</span>
                                <span class="detail-value">${appointment.vitals.remarks}</span>
                            </div>
                        ` : ''}
                        
                        <div class="detail-item" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #e9ecef;">
                            <span class="detail-label">Recorded At:</span>
                            <span class="detail-value" style="color: #6c757d; font-size: 0.9em;">${appointment.vitals.recorded_at || 'N/A'}</span>
                        </div>
                    </div>
                ` : (((appointment.status || '').toLowerCase() === 'completed' || (appointment.status || '').toLowerCase() === 'cancelled')) ? `
                    <div class="details-section" style="border-left: 4px solid #ffc107; background: #fffef7; margin-top: 20px;">
                        <h4><i class="fas fa-exclamation-triangle" style="color: #ffc107;"></i> Vitals Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Vitals Status:</span>
                            <span class="detail-value" style="color: #856404;">No vitals were recorded for this visit</span>
                        </div>
                    </div>
                ` : ''}
                
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

            function printAppointmentDetails() {
                // Get the appointment details content
                const modalContent = document.getElementById('appointmentDetailsContent').innerHTML;
                const facilityName = "<?php echo htmlspecialchars($facility_name); ?>";
                const currentDate = new Date().toLocaleDateString('en-US', {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                const currentTime = new Date().toLocaleTimeString('en-US', {
                    hour: '2-digit',
                    minute: '2-digit'
                });

                // Create a new window for printing
                const printWindow = window.open('', '_blank', 'width=800,height=600');

                // Write the print content
                printWindow.document.write(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Appointment Details - ${facilityName}</title>
                        <style>
                            body {
                                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                                margin: 0;
                                padding: 15px;
                                color: #333;
                                line-height: 1.4;
                                font-size: 12px;
                            }
                            .print-header {
                                text-align: center;
                                border-bottom: 2px solid #0077b6;
                                padding-bottom: 10px;
                                margin-bottom: 15px;
                            }
                            .print-header h1 {
                                color: #0077b6;
                                margin: 0 0 5px 0;
                                font-size: 22px;
                                font-weight: 600;
                            }
                            .print-header h2 {
                                color: #666;
                                margin: 0 0 3px 0;
                                font-size: 16px;
                                font-weight: 400;
                            }
                            .print-date {
                                color: #888;
                                font-size: 11px;
                                margin-top: 5px;
                            }
                            .appointment-details-grid {
                                display: grid;
                                grid-template-columns: 1fr 1fr;
                                gap: 10px;
                                margin-bottom: 10px;
                            }
                            .details-section {
                                background: #f8f9fa;
                                border: 1px solid #e9ecef;
                                border-radius: 4px;
                                padding: 8px;
                                margin-bottom: 8px;
                                break-inside: avoid;
                            }
                            .details-section h4 {
                                margin: 0 0 8px 0;
                                color: #0077b6;
                                font-size: 13px;
                                font-weight: 600;
                                border-bottom: 1px solid #e9ecef;
                                padding-bottom: 4px;
                                display: flex;
                                align-items: center;
                                gap: 4px;
                            }
                            .detail-item {
                                display: flex;
                                justify-content: space-between;
                                align-items: flex-start;
                                margin-bottom: 4px;
                                gap: 8px;
                            }
                            .detail-label {
                                font-weight: 600;
                                color: #6c757d;
                                font-size: 11px;
                                min-width: 80px;
                                flex-shrink: 0;
                            }
                            .detail-value {
                                color: #495057;
                                font-size: 11px;
                                text-align: right;
                                flex-grow: 1;
                                word-break: break-word;
                            }
                            .detail-value.highlight {
                                background: #e3f2fd;
                                padding: 2px 4px;
                                border-radius: 3px;
                                font-weight: 600;
                                color: #1565c0;
                            }
                            .vitals-group {
                                background: #ffffff;
                                border: 1px solid #e9ecef;
                                border-radius: 4px;
                                padding: 6px;
                                margin-bottom: 6px;
                            }
                            .vitals-group h6 {
                                margin: 0 0 4px 0;
                                font-weight: 600;
                                color: #0077b6;
                                display: flex;
                                align-items: center;
                                gap: 4px;
                                font-size: 11px;
                            }
                            .print-footer {
                                margin-top: 15px;
                                padding-top: 8px;
                                border-top: 1px solid #ddd;
                                text-align: center;
                                color: #888;
                                font-size: 9px;
                            }
                            
                            /* QR Code specific styles */
                            .qr-code-container {
                                text-align: center;
                                margin: 10px 0;
                            }
                            
                            .qr-code-container img {
                                max-width: 120px;
                                max-height: 120px;
                                border: 1px solid #ddd;
                                border-radius: 4px;
                                display: block;
                                margin: 0 auto;
                            }
                            
                            .qr-code-description {
                                margin-top: 5px;
                                font-size: 10px;
                                color: #6c757d;
                                text-align: center;
                            }
                            
                            /* Print-specific styles for single page */
                            @media print {
                                @page {
                                    size: A4;
                                    margin: 0.5in;
                                }
                                body {
                                    padding: 0;
                                    font-size: 10px;
                                    line-height: 1.2;
                                }
                                .print-header {
                                    margin-bottom: 10px;
                                    padding-bottom: 8px;
                                }
                                .print-header h1 {
                                    font-size: 18px;
                                    margin-bottom: 3px;
                                }
                                .print-header h2 {
                                    font-size: 14px;
                                    margin-bottom: 2px;
                                }
                                .print-date {
                                    font-size: 9px;
                                    margin-top: 3px;
                                }
                                .appointment-details-grid {
                                    grid-template-columns: 1fr 1fr;
                                    gap: 8px;
                                    margin-bottom: 8px;
                                }
                                .details-section {
                                    padding: 6px;
                                    margin-bottom: 6px;
                                }
                                .details-section h4 {
                                    font-size: 11px;
                                    margin-bottom: 6px;
                                    padding-bottom: 3px;
                                }
                                .detail-item {
                                    margin-bottom: 3px;
                                    gap: 6px;
                                }
                                .detail-label, .detail-value {
                                    font-size: 9px;
                                }
                                .detail-label {
                                    min-width: 70px;
                                }
                                .vitals-group {
                                    padding: 4px;
                                    margin-bottom: 4px;
                                }
                                .vitals-group h6 {
                                    font-size: 9px;
                                    margin-bottom: 3px;
                                }
                                .print-footer {
                                    margin-top: 10px;
                                    padding-top: 6px;
                                    font-size: 8px;
                                }
                                .print-footer p {
                                    margin: 2px 0;
                                }
                                
                                /* Ensure vitals grid is compact */
                                .details-section[style*="grid"] > div {
                                    display: grid !important;
                                    grid-template-columns: 1fr 1fr !important;
                                    gap: 4px !important;
                                }
                                
                                /* QR Code print styles */
                                .qr-code-container {
                                    margin: 8px 0;
                                }
                                
                                .qr-code-container img {
                                    max-width: 100px;
                                    max-height: 100px;
                                }
                                
                                .qr-code-description {
                                    font-size: 8px;
                                    margin-top: 3px;
                                }
                            }
                            
                            /* Hide icons in print for cleaner look */
                            @media print {
                                .fas, .fa {
                                    display: none !important;
                                }
                            }
                        </style>
                    </head>
                    <body>
                        <div class="print-header">
                            <h1>${facilityName}</h1>
                            <h2>Appointment Details Report</h2>
                            <div class="print-date">
                                Generated on: ${currentDate} at ${currentTime}
                            </div>
                        </div>
                        
                        <div class="print-content">
                            ${modalContent}
                        </div>
                        
                        <div class="print-footer">
                            <p>This document was generated from the ${facilityName} Appointment Management System</p>
                            <p>For official use only - Contains confidential patient information</p>
                        </div>
                    </body>
                    </html>
                `);

                printWindow.document.close();

                // Wait for content to load, then print
                printWindow.onload = function() {
                    setTimeout(() => {
                        printWindow.print();
                        // Close the print window after printing (optional)
                        printWindow.onafterprint = function() {
                            printWindow.close();
                        };
                    }, 250);
                };
            }

            function cancelAppointment(appointmentId) {
                currentAppointmentId = appointmentId;
                document.getElementById('cancelAppointmentId').value = appointmentId;
                document.getElementById('cancel_reason').value = '';
                document.getElementById('employee_password').value = '';
                document.getElementById('cancelAppointmentModal').style.display = 'block';
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

            // Function to check for late appointments and show notification
            function checkForLateAppointments() {
                // Only check if it's after 5 PM and not already showing the notice
                const currentHour = new Date().getHours();
                if (currentHour >= 17 && !document.querySelector('.late-appointments-notice')) {
                    // Check if there are any confirmed or checked_in appointments for today
                    const todayRows = document.querySelectorAll('table tbody tr');
                    let lateCount = 0;

                    todayRows.forEach(row => {
                        const statusCell = row.cells[5]; // Assuming status is in column 6 (index 5)
                        const dateCell = row.cells[2]; // Assuming date is in column 3 (index 2)

                        if (statusCell && dateCell) {
                            const status = statusCell.textContent.trim().toLowerCase();
                            const appointmentDate = dateCell.textContent.trim();
                            const today = new Date().toLocaleDateString('en-US', {
                                month: 'long',
                                day: 'numeric',
                                year: 'numeric'
                            });

                            if ((status === 'confirmed' || status === 'checked in') && appointmentDate.includes(today.split(',')[0])) {
                                lateCount++;
                            }
                        }
                    });

                    if (lateCount > 0) {
                        showLateAppointmentNotification(lateCount);
                    }
                }
            }

            // Function to show late appointment notification
            function showLateAppointmentNotification(count) {
                // Only show if not already displayed
                if (document.querySelector('.late-appointments-notice')) return;

                const notice = document.createElement('div');
                notice.className = 'alert alert-warning late-appointments-notice';
                notice.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 1000; max-width: 400px; margin: 0; box-shadow: 0 8px 25px rgba(0,0,0,0.3);';
                notice.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <i class="fas fa-clock" style="color: #856404; margin-right: 8px;"></i>
                            <strong>Late Appointments:</strong> ${count} appointment(s) may need attention after closing time.
                        </div>
                        <button onclick="this.parentElement.parentElement.remove()" style="background: none; border: none; font-size: 1.2rem; cursor: pointer; opacity: 0.7; color: inherit; padding: 0; margin-left: 10px;">&times;</button>
                    </div>
                    <div style="margin-top: 10px;">
                        <button onclick="triggerAutoCancellation()" class="btn btn-warning btn-sm" style="font-size: 12px; padding: 6px 12px;">
                            <i class="fas fa-exclamation-triangle"></i> Review & Cancel Late Appointments
                        </button>
                    </div>
                `;

                document.body.appendChild(notice);

                // Auto-hide after 15 seconds
                setTimeout(() => {
                    if (notice.parentElement) {
                        notice.style.opacity = '0';
                        notice.style.transform = 'translateX(100%)';
                        setTimeout(() => notice.remove(), 300);
                    }
                }, 15000);
            }

            // Function to trigger auto-cancellation via AJAX
            function triggerAutoCancellation() {
                if (confirm('This will automatically cancel all confirmed appointments (as no-show) and checked-in appointments (as left early) from today after 5 PM. Continue?')) {
                    const formData = new FormData();
                    formData.append('action', 'auto_cancel_late_appointments');

                    fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.text())
                        .then(() => {
                            showSuccessMessage('Late appointments have been automatically cancelled.');
                            // Remove the notification
                            const notice = document.querySelector('.late-appointments-notice');
                            if (notice) notice.remove();
                            // Reload page after a short delay to show updated data
                            setTimeout(() => window.location.reload(), 2000);
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showErrorMessage('Failed to auto-cancel appointments. Please try again.');
                        });
                }
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
                // Auto-check for late appointments if after 5 PM
                const currentHour = new Date().getHours();
                if (currentHour >= 17) { // After 5 PM
                    checkForLateAppointments();
                }

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