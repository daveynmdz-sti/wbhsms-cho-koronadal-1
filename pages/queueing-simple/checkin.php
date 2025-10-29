<?php

/**
 * Check-In Station Module (CHO Main District)
 * City Health Office of Koronadal
 * 
 * Purpose: Check-In Station for appointment confirmation and queue entry
 * Access: Admin, Records Officer
 * 
 * Implementation based on station-checkin_Version2.md specification
 */

// Include employee session configuration first
require_once '../../config/session/employee_session.php';

// Check if user is logged in and has appropriate role
if (!is_employee_logged_in()) {
    redirect_to_employee_login();
}

// Check if user has permission for check-in operations (Admin, Records Officer)
require_employee_role(['admin', 'records_officer']);

// Include necessary files
require_once '../../config/db.php';
require_once '../../utils/queue_management_service.php';
require_once '../../utils/patient_flow_validator.php';

// Initialize variables and services
$message = '';
$error = '';
$success = '';
$today = date('Y-m-d');
$current_time = date('H:i:s');
$stats = ['total' => 0, 'checked_in' => 0, 'completed' => 0, 'priority' => 0];
$search_results = [];
$barangays = [];
$services = [];

// Initialize Queue Management Service and Patient Flow Validator
try {
    $queueService = new QueueManagementService($pdo);
    $flowValidator = new PatientFlowValidator($pdo);
} catch (Exception $e) {
    error_log("Queue Service initialization error: " . $e->getMessage());
    $error = "System initialization failed. Please contact administrator.";
}

// Get today's statistics
try {
    // Total appointments today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE DATE(scheduled_date) = ? AND facility_id = 1");
    $stmt->execute([$today]);
    $stats['total'] = $stmt->fetchColumn();

    // Checked-in patients today (via visits table)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE DATE(visit_date) = ? AND facility_id = 1");
    $stmt->execute([$today]);
    $stats['checked_in'] = $stmt->fetchColumn();

    // Completed appointments today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE DATE(scheduled_date) = ? AND facility_id = 1 AND status = 'completed'");
    $stmt->execute([$today]);
    $stats['completed'] = $stmt->fetchColumn();

    // Priority patients in queue today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM queue_entries q 
                          JOIN appointments a ON q.appointment_id = a.appointment_id 
                          WHERE DATE(q.created_at) = ? AND q.priority_level IN ('priority', 'emergency') AND a.facility_id = 1");
    $stmt->execute([$today]);
    $stats['priority'] = $stmt->fetchColumn();
} catch (Exception $e) {
    error_log("Statistics error: " . $e->getMessage());
}

// Get barangays for dropdown
try {
    $stmt = $pdo->prepare("SELECT DISTINCT b.barangay_name FROM barangay b 
                          INNER JOIN patients p ON b.barangay_id = p.barangay_id 
                          WHERE b.status = 'active' ORDER BY b.barangay_name");
    $stmt->execute();
    $barangays = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Barangays fetch error: " . $e->getMessage());
}

// Get available services
try {
    $stmt = $pdo->prepare("SELECT service_id, name as service_name FROM services ORDER BY name");
    $stmt->execute();
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Services fetch error: " . $e->getMessage());
}

// Handle AJAX and form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Handle AJAX requests with JSON response
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');

        switch ($action) {
            case 'scan_qr':
                $qr_data = trim($_POST['qr_data'] ?? '');

                if (empty($qr_data)) {
                    echo json_encode(['success' => false, 'message' => 'QR code data is required']);
                    exit;
                }

                // Parse QR data to extract appointment_id and validate
                $appointment_id = null;
                $is_valid_qr = false;

                // Try to parse as JSON first (new QR format)
                $qr_json = json_decode($qr_data, true);
                if ($qr_json && isset($qr_json['type']) && $qr_json['type'] === 'appointment') {
                    $appointment_id = intval($qr_json['appointment_id'] ?? 0);

                    // Validate QR code using verification code
                    if ($appointment_id > 0) {
                        require_once dirname(dirname(__DIR__)) . '/utils/qr_code_generator.php';
                        $is_valid_qr = QRCodeGenerator::validateQRData($qr_data, $appointment_id);
                    }
                } else {
                    // Fallback to legacy formats for backward compatibility
                    if (preg_match('/appointment_id[=:]\s*(\d+)/', $qr_data, $matches)) {
                        $appointment_id = intval($matches[1]);
                        $is_valid_qr = true; // Legacy QR codes are considered valid
                    } elseif (is_numeric($qr_data)) {
                        $appointment_id = intval($qr_data);
                        $is_valid_qr = true; // Legacy QR codes are considered valid
                    }
                }

                if (!$appointment_id) {
                    echo json_encode(['success' => false, 'message' => 'Invalid QR code format']);
                    exit;
                }

                if (!$is_valid_qr) {
                    echo json_encode(['success' => false, 'message' => 'Invalid QR code - verification failed']);
                    exit;
                }

                // Fetch appointment details
                try {
                    $stmt = $pdo->prepare("
                        SELECT a.appointment_id, a.patient_id, a.scheduled_date, a.scheduled_time, a.status, a.service_id,
                               a.referral_id, a.qr_code_path,
                               p.first_name, p.last_name, p.date_of_birth, p.isSenior, p.isPWD, p.philhealth_id_number,
                               b.barangay_name,
                               s.name as service_name,
                               r.referral_reason, r.referred_by
                        FROM appointments a
                        JOIN patients p ON a.patient_id = p.patient_id
                        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
                        LEFT JOIN services s ON a.service_id = s.service_id
                        LEFT JOIN referrals r ON a.referral_id = r.referral_id
                        WHERE a.appointment_id = ? AND a.facility_id = 1
                    ");
                    $stmt->execute([$appointment_id]);
                    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($appointment) {
                        // Check if already checked in
                        $stmt = $pdo->prepare("SELECT visit_id FROM visits WHERE appointment_id = ? AND facility_id = 1");
                        $stmt->execute([$appointment_id]);
                        $existing_visit = $stmt->fetch();

                        $appointment['already_checked_in'] = $existing_visit ? true : false;
                        $appointment['priority_status'] = $appointment['isSenior'] || $appointment['isPWD'] ? 'priority' : 'normal';

                        echo json_encode(['success' => true, 'appointment' => $appointment]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
                    }
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
                }
                exit;

            case 'search_appointments':
                header('Content-Type: application/json');
                // Implement search functionality with enhanced filters
                $appointment_id = trim($_POST['appointment_id'] ?? '');
                $patient_id = trim($_POST['patient_id'] ?? '');
                $first_name = trim($_POST['first_name'] ?? '');
                $last_name = trim($_POST['last_name'] ?? '');
                $barangay = trim($_POST['barangay'] ?? '');
                $scheduled_date = $_POST['scheduled_date'] ?? $today;

                // Build search query
                $query = "
                    SELECT a.appointment_id, a.patient_id, a.scheduled_date, a.scheduled_time, a.status, a.service_id,
                           p.first_name, p.last_name, p.date_of_birth, p.isSenior, p.isPWD,
                           b.barangay_name,
                           s.name as service_name,
                           CASE 
                               WHEN p.isSenior = 1 OR p.isPWD = 1 THEN 'priority'
                               ELSE 'normal'
                           END as priority_status,
                           v.visit_id as already_checked_in
                    FROM appointments a
                    JOIN patients p ON a.patient_id = p.patient_id
                    LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
                    LEFT JOIN services s ON a.service_id = s.service_id
                    LEFT JOIN visits v ON a.appointment_id = v.appointment_id AND v.facility_id = 1
                    WHERE a.facility_id = 1
                ";

                $params = [];

                // Add search conditions
                if (!empty($appointment_id)) {
                    $clean_id = str_replace('APT-', '', $appointment_id);
                    $query .= " AND a.appointment_id = ?";
                    $params[] = $clean_id;
                }

                if (!empty($patient_id)) {
                    $query .= " AND p.patient_id = ?";
                    $params[] = $patient_id;
                }

                if (!empty($first_name)) {
                    $query .= " AND p.first_name LIKE ?";
                    $params[] = '%' . $first_name . '%';
                }

                if (!empty($last_name)) {
                    $query .= " AND p.last_name LIKE ?";
                    $params[] = '%' . $last_name . '%';
                }

                if (!empty($barangay)) {
                    $query .= " AND b.barangay_name = ?";
                    $params[] = $barangay;
                }

                $query .= " AND DATE(a.scheduled_date) = ? ORDER BY a.scheduled_time ASC";
                $params[] = $scheduled_date;

                try {
                    $stmt = $pdo->prepare($query);
                    $stmt->execute($params);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    echo json_encode(['success' => true, 'results' => $results]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Search failed: ' . $e->getMessage()]);
                }
                exit;

            case 'get_appointment_details':
                header('Content-Type: application/json');
                $appointment_id = $_POST['appointment_id'] ?? 0;
                $patient_id = $_POST['patient_id'] ?? 0;

                if (!$appointment_id || !$patient_id) {
                    echo json_encode(['success' => false, 'message' => 'Invalid appointment or patient ID']);
                    exit;
                }

                try {
                    // Get comprehensive appointment details
                    $stmt = $pdo->prepare("
                        SELECT a.*, 
                               p.first_name, p.last_name, p.middle_name, p.date_of_birth, 
                               p.sex as gender, p.isSenior, p.isPWD, p.email, p.contact_number as phone,
                               b.barangay_name,
                               s.name as service_name,
                               'CHO Koronadal' as facility_name,
                               r.referral_reason, r.referred_by, r.referral_num,
                               v.visit_id as already_checked_in,
                               CASE 
                                   WHEN p.isSenior = 1 OR p.isPWD = 1 THEN 'priority'
                                   ELSE 'normal'
                               END as priority_status,
                               a.qr_code_path
                        FROM appointments a
                        JOIN patients p ON a.patient_id = p.patient_id
                        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
                        LEFT JOIN services s ON a.service_id = s.service_id
                        LEFT JOIN referrals r ON a.referral_id = r.referral_id
                        LEFT JOIN visits v ON a.appointment_id = v.appointment_id AND v.facility_id = a.facility_id
                        WHERE a.appointment_id = ? AND a.patient_id = ? AND a.facility_id = 1
                    ");

                    $stmt->execute([$appointment_id, $patient_id]);
                    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$appointment) {
                        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
                        exit;
                    }

                    echo json_encode(['success' => true, 'appointment' => $appointment]);
                } catch (Exception $e) {
                    echo json_encode(['success' => false, 'message' => 'Failed to load appointment details: ' . $e->getMessage()]);
                }
                exit;
        }
    }

    // Handle regular form submissions
    switch ($action) {
        case 'search':
            // Regular search for non-AJAX requests
            $appointment_id = trim($_POST['appointment_id'] ?? '');
            $patient_id = trim($_POST['patient_id'] ?? '');
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $barangay = trim($_POST['barangay'] ?? '');
            $appointment_date = $_POST['appointment_date'] ?? $today;

            // Build search query (same as AJAX but for regular form)
            $query = "
                SELECT a.appointment_id, a.patient_id, a.scheduled_date as appointment_date, a.scheduled_time as appointment_time, 
                       a.status, a.service_id,
                       p.first_name, p.last_name, p.date_of_birth, p.isSenior, p.isPWD, p.philhealth_id_number as philhealth_id,
                       b.barangay_name as barangay,
                       s.name as service_name,
                       CASE 
                           WHEN p.isSenior = 1 OR p.isPWD = 1 THEN 'priority'
                           ELSE 'normal'
                       END as priority_status,
                       v.visit_id as already_checked_in
                FROM appointments a
                JOIN patients p ON a.patient_id = p.patient_id
                LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
                LEFT JOIN services s ON a.service_id = s.service_id
                LEFT JOIN visits v ON a.appointment_id = v.appointment_id AND v.facility_id = 1
                WHERE a.facility_id = 1
            ";

            $params = [];

            // Add search conditions
            if (!empty($appointment_id)) {
                $clean_id = str_replace('APT-', '', $appointment_id);
                $query .= " AND a.appointment_id = ?";
                $params[] = $clean_id;
            }

            if (!empty($patient_id)) {
                $query .= " AND p.patient_id = ?";
                $params[] = $patient_id;
            }

            if (!empty($first_name)) {
                $query .= " AND p.first_name LIKE ?";
                $params[] = '%' . $first_name . '%';
            }

            if (!empty($last_name)) {
                $query .= " AND p.last_name LIKE ?";
                $params[] = '%' . $last_name . '%';
            }

            if (!empty($barangay)) {
                $query .= " AND b.barangay_name = ?";
                $params[] = $barangay;
            }

            $query .= " AND DATE(a.scheduled_date) = ? ORDER BY a.scheduled_time ASC";
            $params[] = $appointment_date;

            try {
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $error = "Search failed: " . $e->getMessage();
            }
            break;

        case 'checkin':
            $appointment_id = $_POST['appointment_id'] ?? 0;
            $patient_id = $_POST['patient_id'] ?? 0;
            $priority_override = $_POST['priority_override'] ?? '';
            $is_philhealth = isset($_POST['is_philhealth']) ? (int)$_POST['is_philhealth'] : null;
            $philhealth_id = trim($_POST['philhealth_id'] ?? '');

            if ($appointment_id && $patient_id && $is_philhealth !== null) {
                // Check if queue service is available
                if (!isset($queueService)) {
                    $error = "Queue service is not available. Please refresh the page or contact administrator.";
                    break;
                }

                try {
                    // Ensure no open transactions before starting
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                        error_log("Warning: Found open transaction before check-in, rolling back");
                    }

                    // Get appointment and patient details first (no transaction yet)
                    $stmt = $pdo->prepare("
                        SELECT a.appointment_id, a.patient_id, a.service_id, a.status, a.scheduled_date, a.scheduled_time,
                               p.first_name, p.last_name, p.isSenior, p.isPWD, p.date_of_birth
                        FROM appointments a
                        JOIN patients p ON a.patient_id = p.patient_id
                        WHERE a.appointment_id = ? AND a.patient_id = ? AND a.facility_id = 1
                    ");
                    $stmt->execute([$appointment_id, $patient_id]);
                    $appointment_data = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$appointment_data) {
                        throw new Exception("Appointment not found or invalid.");
                    }

                    // Check if already checked in
                    $stmt = $pdo->prepare("SELECT visit_id FROM visits WHERE appointment_id = ? AND facility_id = 1");
                    $stmt->execute([$appointment_id]);
                    if ($stmt->fetch()) {
                        throw new Exception("Patient has already been checked in for this appointment.");
                    }

                    // Validate employee permissions
                    $employee_id = $_SESSION['employee_id'] ?? $_SESSION['user_id'];

                    // Determine priority level
                    $priority_level = 'normal';
                    if ($priority_override === 'priority' || $priority_override === 'emergency') {
                        $priority_level = $priority_override;
                    } elseif ($appointment_data['isSenior'] || $appointment_data['isPWD']) {
                        $priority_level = 'priority';
                    }

                    // 1. Update patient PhilHealth status if provided (separate transaction)
                    if ($is_philhealth !== null) {
                        try {
                            $pdo->beginTransaction();
                            $update_params = [$is_philhealth];
                            $philhealth_update_query = "UPDATE patients SET isPhilHealth = ?";

                            if (!empty($philhealth_id) && $is_philhealth == 1) {
                                $philhealth_update_query .= ", philhealth_id_number = ?";
                                $update_params[] = $philhealth_id;
                            }

                            $philhealth_update_query .= " WHERE patient_id = ?";
                            $update_params[] = $patient_id;

                            $stmt = $pdo->prepare($philhealth_update_query);
                            $stmt->execute($update_params);
                            $pdo->commit();
                        } catch (Exception $e) {
                            $pdo->rollBack();
                            throw new Exception("Failed to update PhilHealth status: " . $e->getMessage());
                        }
                    }

                    // 2. Create visit record (separate transaction)
                    try {
                        $pdo->beginTransaction();
                        $stmt = $pdo->prepare("
                            INSERT INTO visits (
                                patient_id, facility_id, appointment_id, visit_date, 
                                visit_status, created_at, updated_at
                            ) VALUES (?, 1, ?, ?, 'ongoing', NOW(), NOW())
                        ");
                        $stmt->execute([$patient_id, $appointment_id, $appointment_data['scheduled_date']]);
                        $visit_id = $pdo->lastInsertId();
                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        throw new Exception("Failed to create visit record: " . $e->getMessage());
                    }

                    // 3. Use QueueManagementService (handles its own transaction)
                    $queue_result = $queueService->createQueueEntry(
                        $appointment_id,
                        $patient_id,
                        $appointment_data['service_id'],
                        'triage',
                        $priority_level,
                        $employee_id
                    );

                    if (!$queue_result['success']) {
                        throw new Exception("Failed to create queue entry: " . $queue_result['message']);
                    }

                    $queue_code = $queue_result['queue_code'];
                    $queue_entry_id = $queue_result['queue_entry_id'];

                    // 4. Update the queue entry with visit_id (separate transaction)
                    try {
                        $pdo->beginTransaction();
                        $stmt = $pdo->prepare("UPDATE queue_entries SET visit_id = ? WHERE queue_entry_id = ?");
                        $stmt->execute([$visit_id, $queue_entry_id]);
                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        throw new Exception("Failed to link visit to queue: " . $e->getMessage());
                    }

                    // 5. Update appointment status (separate transaction)  
                    try {
                        $pdo->beginTransaction();
                        $stmt = $pdo->prepare("UPDATE appointments SET status = 'checked_in' WHERE appointment_id = ?");
                        $stmt->execute([$appointment_id]);
                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        throw new Exception("Failed to update appointment status: " . $e->getMessage());
                    }

                    // ** SYNC TRIGGER: Broadcast queue update after successful check-in **
                    $_SESSION['queue_sync_trigger'] = [
                        'type' => 'patient_checked_in',
                        'queue_entry_id' => $queue_entry_id,
                        'station_type' => 'triage', // Initial station
                        'patient_id' => $patient_id,
                        'appointment_id' => $appointment_id,
                        'timestamp' => time()
                    ];

                    // 6. Log the check-in action (separate transaction)
                    try {
                        $pdo->beginTransaction();
                        $stmt = $pdo->prepare("
                            INSERT INTO appointment_logs (appointment_id, patient_id, action, reason, created_by_type, created_by_id, created_at)
                            VALUES (?, ?, 'updated', ?, 'employee', ?, NOW())
                        ");
                        $stmt->execute([$appointment_id, $patient_id, 'Patient checked in successfully', $employee_id]);
                        $pdo->commit();
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        // Log error but don't fail the check-in
                        error_log("Failed to log check-in action: " . $e->getMessage());
                    }

                    // Success message
                    $queue_code_display = $queue_code ?? 'N/A';
                    $success = "Patient checked in successfully! Queue Code: " . $queue_code_display .
                        " | Priority: " . ucfirst($priority_level) . " | Next Station: Triage" .
                        " | PhilHealth: " . ($is_philhealth ? 'Member' : 'Non-member');

                    // Log success
                    error_log("Check-in successful for appointment {$appointment_id}: Queue Code {$queue_code_display}");
                } catch (Exception $e) {
                    // Clean up any open transactions
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = "Check-in failed: " . $e->getMessage();
                    error_log("Check-in error for appointment {$appointment_id}: " . $e->getMessage());
                }
            } else {
                if ($is_philhealth === null) {
                    $error = "Please specify PhilHealth membership status before check-in.";
                } else {
                    $error = "Invalid appointment or patient information.";
                }
            }
            break;

        case 'flag_patient':
            $appointment_id = $_POST['appointment_id'] ?? 0;
            $patient_id = $_POST['patient_id'] ?? 0;
            $flag_type = $_POST['flag_type'] ?? '';
            $remarks = trim($_POST['remarks'] ?? '');

            if ($appointment_id && $patient_id && $flag_type) {
                try {
                    $pdo->beginTransaction();

                    $employee_id = $_SESSION['employee_id'] ?? $_SESSION['user_id'];

                    // Insert patient flag
                    $stmt = $pdo->prepare("
                        INSERT INTO patient_flags (patient_id, appointment_id, flag_type, remarks, created_by_type, created_by_id, created_at) 
                        VALUES (?, ?, ?, ?, 'employee', ?, NOW())
                    ");
                    $stmt->execute([$patient_id, $appointment_id, $flag_type, $remarks, $employee_id]);

                    // Update appointment status based on flag type
                    $new_status = 'flagged';
                    if ($flag_type === 'no_show' || $flag_type === 'false_patient_booked' || $flag_type === 'duplicate_appointment') {
                        $new_status = 'cancelled';
                    }

                    $stmt = $pdo->prepare("UPDATE appointments SET status = ? WHERE appointment_id = ?");
                    $stmt->execute([$new_status, $appointment_id]);

                    // Log the flagging action
                    $stmt = $pdo->prepare("
                        INSERT INTO appointment_logs (appointment_id, patient_id, action, reason, notes, created_by_type, created_by_id, created_at) 
                        VALUES (?, ?, 'updated', ?, ?, 'employee', ?, NOW())
                    ");
                    $log_reason = "Patient flagged: $flag_type";
                    $log_notes = json_encode([
                        'flag_type' => $flag_type,
                        'remarks' => $remarks,
                        'status_changed_to' => $new_status
                    ]);
                    $stmt->execute([$appointment_id, $patient_id, $log_reason, $log_notes, $employee_id]);

                    // Remove from queue if patient was already in queue
                    $stmt = $pdo->prepare("
                        UPDATE queue_entries 
                        SET status = 'cancelled', cancelled_at = NOW() 
                        WHERE appointment_id = ? AND status IN ('waiting', 'in_progress')
                    ");
                    $stmt->execute([$appointment_id]);

                    $pdo->commit();

                    $success = "Patient flagged successfully as: " . ucwords(str_replace('_', ' ', $flag_type));
                    if ($new_status === 'cancelled') {
                        $success .= " Appointment has been cancelled.";
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollback();
                    }
                    $error = "Flag operation failed: " . $e->getMessage();
                }
            } else {
                $error = "Invalid flag information provided.";
            }
            break;

        case 'cancel_appointment':
            $appointment_id = $_POST['appointment_id'] ?? 0;
            $patient_id = $_POST['patient_id'] ?? 0;
            $cancel_reason = trim($_POST['cancel_reason'] ?? '');

            if ($appointment_id && $patient_id && $cancel_reason) {
                try {
                    $pdo->beginTransaction();

                    $employee_id = $_SESSION['employee_id'] ?? $_SESSION['user_id'];

                    // Update appointment status
                    $stmt = $pdo->prepare("UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ?");
                    $stmt->execute([$appointment_id]);

                    // Log cancellation with detailed information
                    $stmt = $pdo->prepare("
                        INSERT INTO appointment_logs (appointment_id, patient_id, action, reason, notes, created_by_type, created_by_id, created_at) 
                        VALUES (?, ?, 'cancelled', ?, ?, 'employee', ?, NOW())
                    ");
                    $log_notes = json_encode([
                        'cancelled_by_role' => $user_role,
                        'cancellation_time' => date('Y-m-d H:i:s')
                    ]);
                    $stmt->execute([$appointment_id, $patient_id, $cancel_reason, $log_notes, $employee_id]);

                    // Remove from queue if patient was in queue
                    $stmt = $pdo->prepare("
                        UPDATE queue_entries 
                        SET status = 'cancelled', cancelled_at = NOW() 
                        WHERE appointment_id = ? AND status IN ('waiting', 'in_progress')
                    ");
                    $stmt->execute([$appointment_id]);

                    $pdo->commit();
                    $success = "Appointment cancelled successfully. Reason: " . $cancel_reason;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollback();
                    }
                    $error = "Cancellation failed: " . $e->getMessage();
                }
            } else {
                $error = "Invalid cancellation information provided.";
            }
            break;
    }
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
    <title>Patient Check-In - CHO Koronadal</title>

    <!-- CSS Files -->
    <link rel="stylesheet" href="../../assets/css/sidebar.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

</head>

<body>
    <!-- Include Sidebar -->
    <?php
    $sidebar_file = "../../includes/sidebar_" . strtolower(str_replace(' ', '_', $user_role)) . ".php";
    if (file_exists($sidebar_file)) {
        include $sidebar_file;
    } else {
        include "../../includes/sidebar_admin.php";
    }
    ?>

    <main class="homepage">
        <div class="checkin-container">
            <div class="content-area">
                <!-- Breadcrumb Navigation - matching dashboard -->
                <div class="breadcrumb" style="margin-top: 50px;">
                    <a href="../../index.php"><i class="fas fa-home"></i> Home</a>
                    <i class="fas fa-chevron-right breadcrumb-separator"></i>
                    <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Queue Dashboard</a>
                    <i class="fas fa-chevron-right breadcrumb-separator"></i>
                    <span class="breadcrumb-current"><i class="fas fa-user-check"></i> Patient Check-In</span>
                </div>

                <!-- Page Header with Status Badges - matching dashboard -->
                <div class="page-header">
                    <h1><i class="fas fa-user-check"></i> Patient Check-In</h1>
                    <div class="total-count">
                        <span class="badge bg-primary"><?php echo number_format($stats['total']); ?> Total Today</span>
                        <span class="badge bg-success"><?php echo number_format($stats['checked_in']); ?> Checked-In</span>
                        <span class="badge bg-info"><?php echo number_format($stats['completed']); ?> Completed</span>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Quick Instructions -->
                <div class="card-container compact-instructions">
                    <div class="section-header">
                        <h4><i class="fas fa-info-circle"></i> Quick Guide</h4>
                        <button class="toggle-instructions" onclick="toggleInstructions()" title="Toggle detailed instructions">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                    <div class="detailed-instructions" id="detailedInstructions" style="display: none;">
                        <div class="instruction-grid">
                            <div class="step-compact">
                                <span class="step-num">1</span>
                                <span class="step-text"><strong>Verify Identity:</strong> Check patient's valid ID and appointment details</span>
                            </div>
                            <div class="step-compact">
                                <span class="step-num">2</span>
                                <span class="step-text"><strong>Scan/Search:</strong> Use QR code scanner or manual search to find appointment</span>
                            </div>
                            <div class="step-compact">
                                <span class="step-num">3</span>
                                <span class="step-text"><strong>Confirm Details:</strong> Verify appointment date, time, service, and patient information</span>
                            </div>
                            <div class="step-compact">
                                <span class="step-num">4</span>
                                <span class="step-text"><strong>Priority Check:</strong> Mark as priority if patient is Senior Citizen, PWD, or pregnant</span>
                            </div>
                            <div class="step-compact">
                                <span class="step-num">5</span>
                                <span class="step-text"><strong>Check-In:</strong> Accept booking to add patient to triage queue</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Patient Lookup & Check-In Tools -->
                <div class="card-container">
                    <div class="section-header">
                        <h4><i class="fas fa-tools"></i> Check-In Tools & Navigation</h4>
                        <div class="station-status">
                            <span class="status-indicator status-active"></span>
                            <span class="status-text">Check-In Active</span>
                        </div>
                    </div>
                    <div class="section-body">

                        <!-- Navigation Actions -->
                        <div class="action-section">
                            <h5 class="action-section-title"><i class="fas fa-compass"></i> Navigation</h5>
                            <div class="action-row">
                                <a href="dashboard.php" class="modern-btn btn-nav">
                                    <div class="btn-icon">
                                        <i class="fas fa-tachometer-alt"></i>
                                    </div>
                                    <div class="btn-content">
                                        <span class="btn-title">Queue Dashboard</span>
                                        <span class="btn-subtitle">Main queue overview</span>
                                    </div>
                                </a>

                                <a href="station.php" class="modern-btn btn-nav">
                                    <div class="btn-icon">
                                        <i class="fas fa-desktop"></i>
                                    </div>
                                    <div class="btn-content">
                                        <span class="btn-title">Station Management</span>
                                        <span class="btn-subtitle">Multi-station interface</span>
                                    </div>
                                </a>
                            </div>
                        </div>

                        <!-- Patient Lookup Methods -->
                        <div class="action-section">
                            <h5 class="action-section-title"><i class="fas fa-search"></i> Patient Lookup</h5>
                            <div class="action-row">
                                <div class="modern-btn btn-qr-scan" onclick="toggleQRScanner()">
                                    <div class="btn-icon">
                                        <i class="fas fa-qrcode"></i>
                                    </div>
                                    <div class="btn-content">
                                        <span class="btn-title">QR Code Scanner</span>
                                        <span class="btn-subtitle">Scan appointment QR code</span>
                                    </div>
                                </div>

                                <div class="modern-btn btn-manual-search" onclick="toggleManualSearch()">
                                    <div class="btn-icon">
                                        <i class="fas fa-keyboard"></i>
                                    </div>
                                    <div class="btn-content">
                                        <span class="btn-title">Manual Search</span>
                                        <span class="btn-subtitle">Find by name, ID, or details</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Stats -->
                        <div class="action-section">
                            <h5 class="action-section-title"><i class="fas fa-chart-line"></i> Today's Stats</h5>
                            <div class="stats-row">
                                <div class="quick-stat stat-total">
                                    <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
                                    <div class="stat-info">
                                        <span class="stat-number"><?php echo $stats['total']; ?></span>
                                        <span class="stat-label">Appointments</span>
                                    </div>
                                </div>
                                <div class="quick-stat stat-checked">
                                    <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                                    <div class="stat-info">
                                        <span class="stat-number"><?php echo $stats['checked_in']; ?></span>
                                        <span class="stat-label">Checked-In</span>
                                    </div>
                                </div>
                                <div class="quick-stat stat-priority">
                                    <div class="stat-icon"><i class="fas fa-star"></i></div>
                                    <div class="stat-info">
                                        <span class="stat-number"><?php echo $stats['priority']; ?></span>
                                        <span class="stat-label">Priority</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- QR Scanner Section (Initially Hidden) -->
                <div class="card-container" id="qrScannerCard" style="display: none;">
                    <div class="section-header">
                        <h4><i class="fas fa-qrcode"></i> QR Code Scanner</h4>
                        <button class="close-btn" onclick="toggleQRScanner()" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="section-body">
                        <div class="qr-scanner-area">
                            <div class="qr-scanner-box" id="qrScannerBox">
                                <i class="fas fa-camera fa-3x"></i>
                                <p>Position QR code here</p>
                                <small>Scan appointment QR code</small>
                            </div>
                            <div class="qr-actions">
                                <button type="button" class="btn btn-primary" onclick="startQRScan()">
                                    <i class="fas fa-camera"></i> Start Scanner
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="simulateQRScan()">
                                    <i class="fas fa-qrcode"></i> Test Scan
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Manual Search Section (Initially Hidden) -->
                <div class="card-container" id="manualSearchCard" style="display: none;">
                    <div class="section-header">
                        <h4><i class="fas fa-search"></i> Manual Search & Filters</h4>
                        <button class="close-btn" onclick="toggleManualSearch()" style="background: none; border: none; color: white; font-size: 18px; cursor: pointer;">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="section-body">
                        <form method="POST" class="search-form" id="searchForm">
                            <input type="hidden" name="action" value="search">

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Appointment ID</label>
                                    <input type="text" name="appointment_id" id="appointment_id" class="form-control"
                                        placeholder="APT-00000024 or 24" value="<?php echo $_POST['appointment_id'] ?? ''; ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Patient ID</label>
                                    <input type="number" name="patient_id" id="patient_id" class="form-control"
                                        placeholder="Patient ID" value="<?php echo $_POST['patient_id'] ?? ''; ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">First Name</label>
                                    <input type="text" name="first_name" id="first_name" class="form-control"
                                        placeholder="Enter first name" value="<?php echo $_POST['first_name'] ?? ''; ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Last Name</label>
                                    <input type="text" name="last_name" id="last_name" class="form-control"
                                        placeholder="Enter last name" value="<?php echo $_POST['last_name'] ?? ''; ?>">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Barangay</label>
                                    <select name="barangay" id="barangay" class="form-control">
                                        <option value="">All Barangays</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo htmlspecialchars($barangay); ?>"
                                                <?php echo ($_POST['barangay'] ?? '') === $barangay ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($barangay); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Date</label>
                                    <input type="date" name="scheduled_date" id="scheduled_date" class="form-control"
                                        value="<?php echo $_POST['scheduled_date'] ?? $today; ?>">
                                </div>
                            </div>

                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Search
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="clearFilters()">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Results Table -->
            <div class="card-container" id="resultsSection" style="<?php echo empty($search_results) ? 'display: none;' : ''; ?>">
                <div class="section-header">
                    <h4><i class="fas fa-list-alt"></i> Appointment Search Results</h4>
                    <div class="header-actions">
                        <span class="results-count">Found: <strong id="resultsCount"><?php echo count($search_results); ?></strong> appointment(s)</span>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="results-table" id="resultsTable">
                        <thead>
                            <tr>
                                <th>Appointment ID</th>
                                <th>Patient Details</th>
                                <th>Scheduled</th>
                                <th>Service</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="resultsBody">
                            <?php if (!empty($search_results)): ?>
                                <?php foreach ($search_results as $row): ?>
                                    <tr data-appointment-id="<?php echo $row['appointment_id']; ?>" data-patient-id="<?php echo $row['patient_id']; ?>">
                                        <td>
                                            <strong>APT-<?php echo str_pad($row['appointment_id'], 8, '0', STR_PAD_LEFT); ?></strong>
                                            <small class="text-muted d-block">ID: <?php echo $row['appointment_id']; ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($row['last_name'] . ', ' . $row['first_name']); ?></strong>
                                            <small class="text-muted d-block">
                                                Patient ID: <?php echo $row['patient_id']; ?>
                                                <?php if (!empty($row['barangay'])): ?>
                                                    | <?php echo htmlspecialchars($row['barangay']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <strong><?php echo date('M d, Y', strtotime($row['appointment_date'])); ?></strong>
                                            <small class="text-muted d-block"><?php echo date('g:i A', strtotime($row['appointment_time'])); ?></small>
                                        </td>
                                        <td>
                                            <span class="service-badge"><?php echo htmlspecialchars($row['service_name'] ?? 'General'); ?></span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $row['status']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $row['status'])); ?>
                                            </span>
                                            <?php if (!empty($row['already_checked_in'])): ?>
                                                <small class="text-success d-block">
                                                    <i class="fas fa-check-circle"></i> Checked-in
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="priority-indicators">
                                                <?php if ($row['priority_status'] === 'priority' || $row['isSenior'] || $row['isPWD']): ?>
                                                    <?php if ($row['isSenior']): ?>
                                                        <span class="priority-badge priority-senior">
                                                            <i class="fas fa-user"></i> Senior
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($row['isPWD']): ?>
                                                        <span class="priority-badge priority-pwd">
                                                            <i class="fas fa-wheelchair"></i> PWD
                                                        </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="priority-badge">Normal</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="modern-action-buttons">
                                                <button type="button" class="modern-action-btn btn-view"
                                                    onclick="viewAppointment(<?php echo $row['appointment_id']; ?>, <?php echo $row['patient_id']; ?>)"
                                                    title="View Details">
                                                    <div class="btn-icon-mini">
                                                        <i class="fas fa-eye"></i>
                                                    </div>
                                                </button>

                                                <?php if (empty($row['already_checked_in']) && $row['status'] === 'confirmed'): ?>
                                                    <button type="button" class="modern-action-btn btn-checkin"
                                                        onclick="quickCheckin(<?php echo $row['appointment_id']; ?>, <?php echo $row['patient_id']; ?>)"
                                                        title="Quick Check-in">
                                                        <div class="btn-icon-mini">
                                                            <i class="fas fa-user-check"></i>
                                                        </div>
                                                    </button>
                                                <?php endif; ?>

                                                <button type="button" class="modern-action-btn btn-flag"
                                                    onclick="flagPatient(<?php echo $row['appointment_id']; ?>, <?php echo $row['patient_id']; ?>)"
                                                    title="Flag Patient">
                                                    <div class="btn-icon-mini">
                                                        <i class="fas fa-flag"></i>
                                                    </div>
                                                </button>

                                                <?php if (!empty($row['already_checked_in']) || $row['status'] === 'confirmed'): ?>
                                                    <button type="button" class="modern-action-btn btn-cancel"
                                                        onclick="cancelAppointment(<?php echo $row['appointment_id']; ?>, <?php echo $row['patient_id']; ?>)"
                                                        title="Cancel Appointment">
                                                        <div class="btn-icon-mini">
                                                            <i class="fas fa-times-circle"></i>
                                                        </div>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (empty($search_results) && $_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'search'): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        No appointments found matching the search criteria. Please try different filters.
                    </div>
                <?php endif; ?>
            </div>

            <!-- Footer Info -->
            <div class="footer-info">
                <p>Last updated: <?php echo date('F d, Y g:i A'); ?> | Total results displayed: <?php echo count($search_results); ?></p>
            </div>
        </div>
        </div>
    </main>

    <!-- View Appointment Modal -->
    <div id="appointmentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Appointment Details & Check-In</h3>
                <button type="button" class="modal-close" onclick="closeModal('appointmentModal')">&times;</button>
            </div>
            <div class="modal-body" id="appointmentModalBody">
                <!-- Content loaded via JavaScript -->
                <div id="appointmentDetails">
                    <div class="loading-placeholder">
                        <i class="fas fa-spinner fa-spin"></i> Loading appointment details...
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('appointmentModal')">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Check-In Confirmation Modal -->
    <div id="checkinConfirmModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Confirm Patient Check-In</h3>
                <button type="button" class="modal-close" onclick="closeModal('checkinConfirmModal')">&times;</button>
            </div>
            <form method="POST" id="checkinConfirmForm">
                <input type="hidden" name="action" value="checkin">
                <input type="hidden" name="appointment_id" id="confirmAppointmentId">
                <input type="hidden" name="patient_id" id="confirmPatientId">

                <div class="modal-body">
                    <div class="confirmation-details" id="checkinConfirmDetails">
                        <!-- Details populated via JavaScript -->
                    </div>

                    <!-- PhilHealth Verification Section -->
                    <div class="philhealth-verification">
                        <label class="form-label">
                            <i class="fas fa-id-card"></i> PhilHealth Membership Status
                        </label>
                        <div class="philhealth-options">
                            <label class="philhealth-option">
                                <input type="radio" name="is_philhealth" value="1" id="philhealth_yes">
                                <span class="philhealth-label">
                                    <i class="fas fa-check-circle text-success"></i> PhilHealth Member
                                </span>
                                <small>Patient has valid PhilHealth coverage</small>
                            </label>
                            <label class="philhealth-option">
                                <input type="radio" name="is_philhealth" value="0" id="philhealth_no">
                                <span class="philhealth-label">
                                    <i class="fas fa-times-circle text-danger"></i> Non-PhilHealth
                                </span>
                                <small>Patient will be charged for services</small>
                            </label>
                        </div>

                        <div class="philhealth-id-section" id="philhealth_id_section" style="display: none;">
                            <label for="philhealth_id">PhilHealth ID Number (Optional)</label>
                            <input type="text" name="philhealth_id" id="philhealth_id" class="form-control"
                                placeholder="e.g., 12-345678901-2" maxlength="15">
                            <small class="text-muted">For record keeping purposes</small>
                        </div>
                    </div>

                    <div class="priority-selection">
                        <label class="form-label">Priority Level Override</label>
                        <div class="priority-options">
                            <label class="priority-option">
                                <input type="radio" name="priority_override" value="normal" checked>
                                <span class="priority-label">Normal Priority</span>
                                <small>Standard queue processing</small>
                            </label>
                            <label class="priority-option">
                                <input type="radio" name="priority_override" value="priority">
                                <span class="priority-label">Priority Queue</span>
                                <small>For seniors, PWD, pregnant patients</small>
                            </label>
                            <label class="priority-option">
                                <input type="radio" name="priority_override" value="emergency">
                                <span class="priority-label">Emergency</span>
                                <small>Urgent medical attention required</small>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('checkinConfirmModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-user-check"></i> Confirm Check-In
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Flag Patient Modal -->
    <div id="flagModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Flag Patient / Issue Report</h3>
                <button type="button" class="modal-close" onclick="closeModal('flagModal')">&times;</button>
            </div>
            <form method="POST" id="flagForm">
                <input type="hidden" name="action" value="flag_patient">
                <input type="hidden" name="appointment_id" id="flagAppointmentId">
                <input type="hidden" name="patient_id" id="flagPatientId">

                <div class="modal-body">
                    <div class="patient-summary" id="flagPatientSummary">
                        <!-- Patient summary populated via JavaScript -->
                    </div>

                    <div class="form-group">
                        <label class="form-label">Issue/Flag Type</label>
                        <select name="flag_type" class="form-control" required>
                            <option value="">Select issue type...</option>
                            <optgroup label="Identity Issues">
                                <option value="false_senior">False Senior Citizen Claim</option>
                                <option value="false_pwd">False PWD Claim</option>
                                <option value="identity_mismatch">Identity Verification Failed</option>
                            </optgroup>
                            <optgroup label="Appointment Issues">
                                <option value="false_patient_booked">Wrong Patient Booking</option>
                                <option value="duplicate_appointment">Duplicate Appointment</option>
                                <option value="no_show">Patient No-Show</option>
                                <option value="late_arrival">Late Arrival (>30min)</option>
                            </optgroup>
                            <optgroup label="Documentation Issues">
                                <option value="false_philhealth">Invalid PhilHealth Documents</option>
                                <option value="missing_documents">Required Documents Missing</option>
                                <option value="expired_id">Expired Identification</option>
                            </optgroup>
                            <optgroup label="Other">
                                <option value="medical_emergency">Medical Emergency (Redirect)</option>
                                <option value="behavior_issue">Behavioral Concern</option>
                                <option value="other">Other Issue</option>
                            </optgroup>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Detailed Remarks</label>
                        <textarea name="remarks" class="form-control" rows="4"
                            placeholder="Provide detailed explanation of the issue, steps taken, and any recommendations..." required></textarea>
                        <small class="form-text text-muted">
                            Be specific and objective in your description. This will be part of the patient's record.
                        </small>
                    </div>

                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Important:</strong> Flagging a patient will affect their appointment status and may prevent future bookings.
                        Ensure all information is accurate before submitting.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('flagModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-flag"></i> Submit Flag
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Cancel Appointment Modal -->
    <div id="cancelModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Cancel Appointment</h3>
                <button type="button" class="modal-close" onclick="closeModal('cancelModal')">&times;</button>
            </div>
            <form method="POST" id="cancelForm">
                <input type="hidden" name="action" value="cancel_appointment">
                <input type="hidden" name="appointment_id" id="cancelAppointmentId">
                <input type="hidden" name="patient_id" id="cancelPatientId">

                <div class="modal-body">
                    <div class="patient-summary" id="cancelPatientSummary">
                        <!-- Patient summary populated via JavaScript -->
                    </div>

                    <div class="form-group">
                        <label class="form-label">Cancellation Reason</label>
                        <select name="cancel_reason" class="form-control" required>
                            <option value="">Select cancellation reason...</option>
                            <option value="Patient Request">Patient Request</option>
                            <option value="No Show">Patient No-Show</option>
                            <option value="Medical Emergency">Medical Emergency</option>
                            <option value="Facility Issue">Facility/Equipment Issue</option>
                            <option value="Staff Unavailable">Assigned Staff Unavailable</option>
                            <option value="Administrative Error">Administrative Error</option>
                            <option value="Patient Deceased">Patient Deceased</option>
                            <option value="Other">Other Reason</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Additional Details</label>
                        <textarea name="cancel_details" class="form-control" rows="3"
                            placeholder="Provide additional context for the cancellation..."></textarea>
                    </div>

                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <strong>Warning:</strong> This action cannot be undone. The appointment will be permanently cancelled
                        and removed from the queue system.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('cancelModal')">
                        <i class="fas fa-times"></i> Keep Appointment
                    </button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-ban"></i> Cancel Appointment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Success</h3>
                <button type="button" class="modal-close" onclick="closeModal('successModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="success-message" id="successMessage">
                    <!-- Success message populated via JavaScript -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="closeModal('successModal')">
                    <i class="fas fa-check"></i> OK
                </button>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Success</h3>
                <button type="button" class="modal-close" onclick="closeModal('successModal')">&times;</button>
            </div>
            <div class="modal-body" id="successModalBody">
                <!-- Content set via JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="closeModal('successModal')">Done</button>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        function showModal(modalId) {
            document.getElementById(modalId).classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
            document.body.style.overflow = '';
        }

        // Clear all modal content when closing
        function clearModalContent(modalId) {
            const modalBody = document.querySelector(`#${modalId} .modal-body`);
            if (modalBody) {
                // Reset dynamic content
                const dynamicElements = modalBody.querySelectorAll('[id$="Details"], [id$="Summary"]');
                dynamicElements.forEach(el => el.innerHTML = '');
            }
        }

        // Instructions Toggle Function
        function toggleInstructions() {
            const detailedInstructions = document.getElementById('detailedInstructions');
            const toggleBtn = document.querySelector('.toggle-instructions i');

            if (detailedInstructions.style.display === 'none' || !detailedInstructions.style.display) {
                detailedInstructions.style.display = 'block';
                toggleBtn.classList.remove('fa-chevron-down');
                toggleBtn.classList.add('fa-chevron-up');
            } else {
                detailedInstructions.style.display = 'none';
                toggleBtn.classList.remove('fa-chevron-up');
                toggleBtn.classList.add('fa-chevron-down');
            }
        }

        // Modern UI Toggle Functions
        function toggleQRScanner() {
            const qrCard = document.getElementById('qrScannerCard');
            const manualCard = document.getElementById('manualSearchCard');

            if (qrCard.style.display === 'none' || !qrCard.style.display) {
                // Show QR Scanner, hide Manual Search
                qrCard.style.display = 'block';
                manualCard.style.display = 'none';

                // Animate card appearance
                qrCard.style.opacity = '0';
                qrCard.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    qrCard.style.transition = 'all 0.3s ease';
                    qrCard.style.opacity = '1';
                    qrCard.style.transform = 'translateY(0)';
                }, 10);
            } else {
                // Hide QR Scanner
                qrCard.style.transition = 'all 0.3s ease';
                qrCard.style.opacity = '0';
                qrCard.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    qrCard.style.display = 'none';
                }, 300);
            }
        }

        function toggleManualSearch() {
            const qrCard = document.getElementById('qrScannerCard');
            const manualCard = document.getElementById('manualSearchCard');

            if (manualCard.style.display === 'none' || !manualCard.style.display) {
                // Show Manual Search, hide QR Scanner
                manualCard.style.display = 'block';
                qrCard.style.display = 'none';

                // Animate card appearance
                manualCard.style.opacity = '0';
                manualCard.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    manualCard.style.transition = 'all 0.3s ease';
                    manualCard.style.opacity = '1';
                    manualCard.style.transform = 'translateY(0)';
                }, 10);
            } else {
                // Hide Manual Search
                manualCard.style.transition = 'all 0.3s ease';
                manualCard.style.opacity = '0';
                manualCard.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    manualCard.style.display = 'none';
                }, 300);
            }
        }

        // Clear search filters
        function clearFilters() {
            const form = document.getElementById('searchForm');
            if (form) {
                form.reset();
                // Clear results
                const resultsSection = document.getElementById('resultsSection');
                if (resultsSection) {
                    resultsSection.style.display = 'none';
                }
            }
        }

        // QR Scanner Functions
        function startQRScan() {
            const scannerBox = document.getElementById('qrScannerBox');
            scannerBox.classList.add('scanning');
            scannerBox.innerHTML = `
                <i class="fas fa-camera fa-2x"></i>
                <p>Scanning...</p>
                <small>Position QR code in view</small>
            `;

            // Simulate scanner timeout
            setTimeout(() => {
                scannerBox.classList.remove('scanning');
                scannerBox.innerHTML = `
                    <i class="fas fa-camera fa-3x"></i>
                    <p>Position QR code here</p>
                    <small>Scan appointment QR code</small>
                `;
                alert('QR Scanner timeout. Please try manual search or contact IT support for scanner setup.');
            }, 10000);
        }

        function simulateQRScan() {
            // For testing - simulate a successful QR scan
            const testQRData = "appointment_id:24";
            processQRScan(testQRData);
        }

        function processQRScan(qrData) {
            if (!qrData) return;

            // Show loading
            showLoadingOverlay('Processing QR code...');

            // Send AJAX request to process QR scan
            fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax=1&action=scan_qr&qr_data=${encodeURIComponent(qrData)}`
                })
                .then(response => response.json())
                .then(data => {
                    hideLoadingOverlay();
                    if (data.success) {
                        displayAppointmentDetails(data.appointment);
                    } else {
                        showError(data.message || 'QR scan failed');
                    }
                })
                .catch(error => {
                    hideLoadingOverlay();
                    showError('Network error: ' + error.message);
                });
        }

        // AJAX Search Function
        function performSearch() {
            const form = document.getElementById('searchForm');
            const formData = new FormData(form);
            formData.append('ajax', '1');
            formData.append('action', 'search_appointments');

            showLoadingOverlay('Searching appointments...');

            fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    hideLoadingOverlay();
                    if (data.success) {
                        updateResultsTable(data.results);
                    } else {
                        showError(data.message || 'Search failed');
                    }
                })
                .catch(error => {
                    hideLoadingOverlay();
                    showError('Network error: ' + error.message);
                });
        }

        // Update results table with search data
        function updateResultsTable(results) {
            const resultsSection = document.getElementById('resultsSection');
            const resultsBody = document.getElementById('resultsBody');
            const resultsCount = document.getElementById('resultsCount');

            if (!results || results.length === 0) {
                resultsSection.style.display = 'none';
                showInfo('No appointments found matching your search criteria.');
                return;
            }

            resultsCount.textContent = results.length;
            resultsSection.style.display = 'block';

            // Clear existing results
            resultsBody.innerHTML = '';

            // Populate new results
            results.forEach(appointment => {
                const row = createAppointmentRow(appointment);
                resultsBody.appendChild(row);
            });
        }

        // Create table row for appointment
        function createAppointmentRow(appointment) {
            const row = document.createElement('tr');
            row.dataset.appointmentId = appointment.appointment_id;
            row.dataset.patientId = appointment.patient_id;

            // Determine priority status
            const isPriority = appointment.isSenior || appointment.isPWD || appointment.priority_status === 'priority';

            row.innerHTML = `
                <td>
                    <strong>APT-${appointment.appointment_id.toString().padStart(8, '0')}</strong>
                    <small class="text-muted d-block">ID: ${appointment.appointment_id}</small>
                </td>
                <td>
                    <strong>${appointment.last_name}, ${appointment.first_name}</strong>
                    <small class="text-muted d-block">
                        Patient ID: ${appointment.patient_id}
                        ${appointment.barangay_name ? ` | ${appointment.barangay_name}` : ''}
                    </small>
                </td>
                <td>
                    <strong>${formatDate(appointment.scheduled_date)}</strong>
                    <small class="text-muted d-block">${formatTime(appointment.scheduled_time)}</small>
                </td>
                <td>
                    <span class="service-badge">${appointment.service_name || 'General'}</span>
                </td>
                <td>
                    <span class="status-badge status-${appointment.status}">
                        ${capitalizeFirst(appointment.status.replace('_', ' '))}
                    </span>
                    ${appointment.already_checked_in ? '<small class="text-success d-block"><i class="fas fa-check-circle"></i> Checked-in</small>' : ''}
                </td>
                <td>
                    <div class="priority-indicators">
                        ${appointment.isSenior ? '<span class="priority-badge priority-senior"><i class="fas fa-user"></i> Senior</span>' : ''}
                        ${appointment.isPWD ? '<span class="priority-badge priority-pwd"><i class="fas fa-wheelchair"></i> PWD</span>' : ''}
                        ${!isPriority ? '<span class="priority-badge">Normal</span>' : ''}
                    </div>
                </td>
                <td>
                    <div class="action-buttons">
                        <button type="button" class="btn btn-primary btn-sm" 
                                onclick="viewAppointment(${appointment.appointment_id}, ${appointment.patient_id})"
                                title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        ${!appointment.already_checked_in && appointment.status === 'confirmed' ? 
                            `<button type="button" class="btn btn-success btn-sm" 
                                    onclick="quickCheckin(${appointment.appointment_id}, ${appointment.patient_id})"
                                    title="Quick Check-in">
                                <i class="fas fa-user-check"></i>
                            </button>` : ''
                        }
                        <button type="button" class="btn btn-warning btn-sm" 
                                onclick="flagPatient(${appointment.appointment_id}, ${appointment.patient_id})"
                                title="Flag Patient">
                            <i class="fas fa-flag"></i>
                        </button>
                    </div>
                </td>
            `;

            return row;
        }

        // Utility functions
        function formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric',
                year: 'numeric'
            });
        }

        function formatTime(timeStr) {
            const time = new Date(`2000-01-01 ${timeStr}`);
            return time.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
        }

        function capitalizeFirst(str) {
            return str.charAt(0).toUpperCase() + str.slice(1);
        }

        // View appointment details (enhanced version)
        function viewAppointment(appointmentId, patientId) {
            showLoadingOverlay('Loading appointment details...');

            fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax=1&action=get_appointment_details&appointment_id=${appointmentId}&patient_id=${patientId}`
                })
                .then(response => {
                    // Debug: Log response details
                    console.log('Response status:', response.status);
                    console.log('Response headers:', response.headers.get('content-type'));

                    // Check if response is JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        throw new Error('Response is not JSON. Content-Type: ' + contentType);
                    }

                    return response.json();
                })
                .then(data => {
                    hideLoadingOverlay();
                    console.log('Parsed data:', data); // Debug log
                    if (data.success) {
                        displayAppointmentDetails(data.appointment);
                    } else {
                        showError(data.message || 'Failed to load appointment details');
                    }
                })
                .catch(error => {
                    hideLoadingOverlay();
                    showError('Network error: ' + error.message);
                });
        }

        function displayAppointmentDetails(appointment) {
            const modalBody = document.getElementById('appointmentModalBody');
            const isPriority = appointment.isSenior || appointment.isPWD;

            modalBody.innerHTML = `
                <div class="appointment-summary">
                    <div class="row">
                        <div class="col-md-6">
                            <h5><i class="fas fa-calendar-alt"></i> Appointment Information</h5>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Appointment ID</span>
                                    <span class="info-value">APT-${appointment.appointment_id.toString().padStart(8, '0')}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Date & Time</span>
                                    <span class="info-value">${formatDate(appointment.scheduled_date)} at ${formatTime(appointment.scheduled_time)}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Service</span>
                                    <span class="info-value">${appointment.service_name || 'General Consultation'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Status</span>
                                    <span class="status-badge status-${appointment.status}">${capitalizeFirst(appointment.status.replace('_', ' '))}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <h5><i class="fas fa-user"></i> Patient Information</h5>
                            <div class="info-grid">
                                <div class="info-item">
                                    <span class="info-label">Full Name</span>
                                    <span class="info-value">${appointment.first_name} ${appointment.last_name}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Patient ID</span>
                                    <span class="info-value">${appointment.patient_id}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Date of Birth</span>
                                    <span class="info-value">${appointment.date_of_birth || 'Not recorded'}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Barangay</span>
                                    <span class="info-value">${appointment.barangay_name || 'Not specified'}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="priority-section">
                        <h5><i class="fas fa-star"></i> Priority Status</h5>
                        <div class="priority-indicators">
                            ${appointment.isSenior ? '<span class="priority-badge priority-senior"><i class="fas fa-user"></i> Senior Citizen</span>' : ''}
                            ${appointment.isPWD ? '<span class="priority-badge priority-pwd"><i class="fas fa-wheelchair"></i> PWD</span>' : ''}
                            ${!isPriority ? '<span class="priority-badge">Normal Priority</span>' : ''}
                        </div>
                    </div>

                    ${appointment.referral_reason ? `
                    <div class="referral-section">
                        <h5><i class="fas fa-share"></i> Referral Information</h5>
                        <div class="info-item">
                            <span class="info-label">Reason</span>
                            <span class="info-value">${appointment.referral_reason}</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Referred By</span>
                            <span class="info-value">${appointment.referred_by || 'Not specified'}</span>
                        </div>
                    </div>
                    ` : ''}

                    ${appointment.qr_code_path ? `
                    <div class="qr-section">
                        <h5><i class="fas fa-qrcode"></i> QR Code Preview</h5>
                        <div class="qr-preview">
                            <img src="${appointment.qr_code_path}" alt="Appointment QR Code" style="max-width: 150px; height: auto;">
                        </div>
                    </div>
                    ` : ''}

                    <div class="action-section">
                        ${!appointment.already_checked_in && appointment.status === 'confirmed' ? `
                        <button type="button" class="btn btn-success" onclick="showCheckinConfirm(${appointment.appointment_id}, ${appointment.patient_id})">
                            <i class="fas fa-user-check"></i> Accept Booking / Check-In
                        </button>
                        ` : appointment.already_checked_in ? `
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Patient has already been checked in.
                        </div>
                        ` : ''}
                        
                        <button type="button" class="btn btn-warning" onclick="showFlagPatient(${appointment.appointment_id}, ${appointment.patient_id})">
                            <i class="fas fa-flag"></i> Flag Patient
                        </button>
                    </div>
                </div>
            `;

            showModal('appointmentModal');
        }

        // Quick check-in function
        function quickCheckin(appointmentId, patientId) {
            showCheckinConfirm(appointmentId, patientId);
        }

        // Show check-in confirmation
        function showCheckinConfirm(appointmentId, patientId) {
            document.getElementById('confirmAppointmentId').value = appointmentId;
            document.getElementById('confirmPatientId').value = patientId;

            // Populate confirmation details
            const row = document.querySelector(`tr[data-appointment-id="${appointmentId}"]`);
            if (row) {
                const patientName = row.querySelector('td:nth-child(2) strong').textContent;
                const appointmentTime = row.querySelector('td:nth-child(3)').textContent;

                document.getElementById('checkinConfirmDetails').innerHTML = `
                    <div class="confirmation-summary">
                        <h6>Confirm check-in for:</h6>
                        <p><strong>Patient:</strong> ${patientName}</p>
                        <p><strong>Appointment:</strong> APT-${appointmentId.toString().padStart(8, '0')}</p>
                        <p><strong>Scheduled:</strong> ${appointmentTime}</p>
                    </div>
                `;
            }

            closeModal('appointmentModal');
            showModal('checkinConfirmModal');
        }

        // Show flag patient modal
        function showFlagPatient(appointmentId, patientId) {
            document.getElementById('flagAppointmentId').value = appointmentId;
            document.getElementById('flagPatientId').value = patientId;

            // Populate patient summary
            const row = document.querySelector(`tr[data-appointment-id="${appointmentId}"]`);
            if (row) {
                const patientName = row.querySelector('td:nth-child(2) strong').textContent;
                const appointmentTime = row.querySelector('td:nth-child(3)').textContent;

                document.getElementById('flagPatientSummary').innerHTML = `
                    <div class="patient-summary-content">
                        <h6>Patient to flag:</h6>
                        <p><strong>Name:</strong> ${patientName}</p>
                        <p><strong>Appointment:</strong> APT-${appointmentId.toString().padStart(8, '0')}</p>
                        <p><strong>Scheduled:</strong> ${appointmentTime}</p>
                    </div>
                `;
            }

            closeModal('appointmentModal');
            showModal('flagModal');
        }

        // Flag patient function (for action buttons in table)
        function flagPatient(appointmentId, patientId) {
            showFlagPatient(appointmentId, patientId);
        }

        // Loading overlay functions
        function showLoadingOverlay(message = 'Loading...') {
            const overlay = document.createElement('div');
            overlay.id = 'loadingOverlay';
            overlay.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9999;
                color: white;
                font-size: 1.2rem;
            `;
            overlay.innerHTML = `
                <div style="text-align: center;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p style="margin-top: 1rem;">${message}</p>
                </div>
            `;
            document.body.appendChild(overlay);
        }

        function hideLoadingOverlay() {
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) {
                overlay.remove();
            }
        }

        // Alert functions
        function showSuccess(message) {
            showAlert(message, 'success');
        }

        function showError(message) {
            showAlert(message, 'danger');
        }

        function showInfo(message) {
            showAlert(message, 'info');
        }

        function showAlert(message, type = 'info') {
            // Remove existing alerts
            const existingAlerts = document.querySelectorAll('.alert-dynamic');
            existingAlerts.forEach(alert => alert.remove());

            // Create new alert
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dynamic`;
            alert.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 10000;
                max-width: 400px;
                min-width: 250px;
                animation: slideInRight 0.3s ease;
            `;

            const icon = type === 'success' ? 'check-circle' :
                type === 'danger' ? 'exclamation-triangle' :
                'info-circle';

            alert.innerHTML = `
                <i class="fas fa-${icon}"></i> ${message}
                <button type="button" style="background: none; border: none; float: right; font-size: 1.2rem; color: inherit; cursor: pointer;" onclick="this.parentElement.remove();">&times;</button>
            `;

            document.body.appendChild(alert);

            // Auto-remove after 10 seconds
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 10000);
        }

        // Form submission with loading
        function submitFormWithLoading(formId, loadingMessage = 'Processing...') {
            const form = document.getElementById(formId);
            if (!form) return;

            form.addEventListener('submit', function(e) {
                showLoadingOverlay(loadingMessage);
            });
        }

        // PhilHealth handling functions
        function handlePhilHealthSelection() {
            const philhealthYes = document.getElementById('philhealth_yes');
            const philhealthNo = document.getElementById('philhealth_no');
            const philhealthIdSection = document.getElementById('philhealth_id_section');

            if (philhealthYes && philhealthNo && philhealthIdSection) {
                philhealthYes.addEventListener('change', function() {
                    if (this.checked) {
                        philhealthIdSection.style.display = 'block';
                    }
                });

                philhealthNo.addEventListener('change', function() {
                    if (this.checked) {
                        philhealthIdSection.style.display = 'none';
                        document.getElementById('philhealth_id').value = '';
                    }
                });
            }
        }

        function validatePhilHealthSelection() {
            const philhealthYes = document.getElementById('philhealth_yes');
            const philhealthNo = document.getElementById('philhealth_no');

            if (!philhealthYes || !philhealthNo) return true; // Skip validation if elements not found

            if (!philhealthYes.checked && !philhealthNo.checked) {
                showAlert('Please specify the patient\'s PhilHealth membership status before proceeding.', 'warning');
                return false;
            }

            return true;
        }

        // Enhanced check-in form validation
        function validateCheckinForm() {
            return validatePhilHealthSelection();
        }

        // Initialize form handlers
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize PhilHealth handling
            handlePhilHealthSelection();

            // Add enhanced validation to check-in form
            const checkinForm = document.getElementById('checkinConfirmForm');
            if (checkinForm) {
                checkinForm.addEventListener('submit', function(e) {
                    if (!validateCheckinForm()) {
                        e.preventDefault();
                        return false;
                    }
                    showLoadingOverlay('Checking in patient...');
                });
            }

            // Add loading to other form submissions
            submitFormWithLoading('flagForm', 'Flagging patient...');
            submitFormWithLoading('cancelForm', 'Cancelling appointment...');

            // Add AJAX search on form submit
            const searchForm = document.getElementById('searchForm');
            if (searchForm) {
                searchForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    performSearch();
                });
            }

            // Add CSS animation styles
            const style = document.createElement('style');
            style.textContent = `
                @keyframes slideInRight {
                    from { transform: translateX(100%); }
                    to { transform: translateX(0); }
                }
                .info-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                    gap: 1rem;
                    margin-bottom: 1.5rem;
                }
                .info-item {
                    display: flex;
                    flex-direction: column;
                }
                .info-label {
                    font-size: 0.8rem;
                    color: #6c757d;
                    text-transform: uppercase;
                    font-weight: 600;
                    margin-bottom: 0.25rem;
                }
                .info-value {
                    font-size: 1rem;
                    color: #333;
                    font-weight: 500;
                }
                .row {
                    display: flex;
                    flex-wrap: wrap;
                    margin: -0.5rem;
                }
                .col-md-6 {
                    flex: 0 0 50%;
                    padding: 0.5rem;
                }
                @media (max-width: 768px) {
                    .col-md-6 { flex: 0 0 100%; }
                }
                .priority-option {
                    display: block;
                    padding: 0.75rem;
                    margin-bottom: 0.5rem;
                    border: 2px solid #e9ecef;
                    border-radius: 8px;
                    cursor: pointer;
                    transition: all 0.2s ease;
                }
                .priority-option:hover {
                    border-color: #007bff;
                    background-color: #f8f9fa;
                }
                .priority-option input[type="radio"] {
                    margin-right: 0.5rem;
                }
                .priority-label {
                    font-weight: 600;
                    display: block;
                }
                .priority-option small {
                    display: block;
                    color: #6c757d;
                    margin-top: 0.25rem;
                }
                .form-text {
                    font-size: 0.875em;
                    color: #6c757d;
                }
            `;
            document.head.appendChild(style);
        });

        // Cancel Appointment Function
        async function cancelAppointment(appointmentId, patientId) {
            if (!confirm('Are you sure you want to cancel this appointment? This action cannot be undone.')) {
                return;
            }

            try {
                const response = await fetch('../../api/checkin/cancel', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'cancel_appointment',
                        appointment_id: appointmentId,
                        patient_id: patientId
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showAlert('Appointment successfully cancelled.', 'success');

                    // Remove the row from the table or refresh
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    showAlert('Error: ' + result.message, 'error');
                }
            } catch (error) {
                console.error('Error cancelling appointment:', error);
                showAlert('An error occurred while cancelling the appointment.', 'error');
            }
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('show');
            }
        }

        // === UNIVERSAL FRAMEWORK INTEGRATION FOR CHECK-IN ===

        class CheckInQueueSync {
            constructor() {
                this.syncEnabled = true;
                this.initializeSync();
                this.setupSyncTriggers();
                this.checkForSyncTrigger();
            }

            initializeSync() {
                console.log('🔄 Check-In Queue Sync initialized');
            }

            setupSyncTriggers() {
                // Listen for successful check-ins to broadcast updates
                const originalSubmit = HTMLFormElement.prototype.submit;
                const self = this;

                // Override form submissions to detect check-in completion
                HTMLFormElement.prototype.submit = function() {
                    const form = this;

                    // Check if this is a check-in form
                    if (form.id === 'checkinForm' || form.classList.contains('checkin-form')) {
                        console.log('📋 Check-in form submitted - will broadcast on success');

                        // Set a flag to broadcast after successful submission
                        sessionStorage.setItem('pending_checkin_broadcast', 'true');
                    }

                    return originalSubmit.call(this);
                };

                // Listen for page changes that indicate successful check-in
                const observer = new MutationObserver((mutations) => {
                    mutations.forEach((mutation) => {
                        if (mutation.type === 'childList') {
                            // Check for success messages or modal appearances
                            const successElements = document.querySelectorAll('.success, .alert-success, #successModal.show');
                            if (successElements.length > 0) {
                                const pendingBroadcast = sessionStorage.getItem('pending_checkin_broadcast');
                                if (pendingBroadcast === 'true') {
                                    console.log('✅ Check-in success detected - broadcasting update');
                                    this.broadcastQueueUpdate('patient_checked_in');
                                    sessionStorage.removeItem('pending_checkin_broadcast');
                                }
                            }
                        }
                    });
                });

                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }

            checkForSyncTrigger() {
                // Check if there's a sync trigger from PHP session
                <?php if (isset($_SESSION['queue_sync_trigger'])): ?>
                    const syncTrigger = <?php echo json_encode($_SESSION['queue_sync_trigger']); ?>;
                    console.log('📡 PHP Sync Trigger detected:', syncTrigger);
                    this.broadcastQueueUpdate(syncTrigger.type, syncTrigger);

                    // Clear the trigger to prevent duplicate broadcasts
                    <?php unset($_SESSION['queue_sync_trigger']); ?>
                <?php endif; ?>
            }

            broadcastQueueUpdate(eventType, data = {}) {
                if (!this.syncEnabled) return;

                const updateData = {
                    type: 'queue_updated',
                    source: 'checkin',
                    event: eventType,
                    timestamp: Date.now(),
                    station_type: data.station_type || 'triage',
                    ...data
                };

                console.log('📢 Broadcasting queue update:', updateData);

                // Broadcast to parent window (if opened from another window)
                if (window.opener && !window.opener.closed) {
                    window.opener.postMessage(updateData, '*');
                }

                // Broadcast to all open windows in the same origin
                try {
                    localStorage.setItem('queue_sync_broadcast', JSON.stringify(updateData));
                    localStorage.removeItem('queue_sync_broadcast'); // Immediate cleanup
                } catch (e) {
                    console.warn('Failed to broadcast via localStorage:', e);
                }

                // Show user notification
                this.showSyncNotification(eventType);
            }

            showSyncNotification(eventType) {
                let message = 'Queue updated';

                switch (eventType) {
                    case 'patient_checked_in':
                        message = '✅ Patient successfully checked in to queue';
                        break;
                    case 'appointment_cancelled':
                        message = '❌ Appointment cancelled and removed from queue';
                        break;
                    default:
                        message = '🔄 Queue status updated';
                }

                // Show browser notification if permitted
                if ('Notification' in window && Notification.permission === 'granted') {
                    new Notification('CHO Koronadal - Check-In', {
                        body: message,
                        icon: '../../assets/images/Nav_LogoClosed.png',
                        tag: 'checkin-sync',
                        silent: false
                    });
                }

                // Also show in-page notification
                this.showInPageAlert(message, 'info');
            }

            showInPageAlert(message, type = 'info') {
                // Create alert element
                const alert = document.createElement('div');
                alert.className = `sync-alert alert-${type}`;
                alert.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 10000;
                    background: ${type === 'info' ? '#d1ecf1' : '#d4edda'};
                    color: ${type === 'info' ? '#0c5460' : '#155724'};
                    padding: 12px 20px;
                    border-radius: 8px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    font-weight: 500;
                    max-width: 300px;
                    opacity: 0;
                    transform: translateX(100%);
                    transition: all 0.3s ease;
                `;

                alert.innerHTML = `
                    <i class="fas fa-${type === 'info' ? 'info-circle' : 'check-circle'}" style="margin-right: 8px;"></i>
                    ${message}
                `;

                document.body.appendChild(alert);

                // Animate in
                setTimeout(() => {
                    alert.style.opacity = '1';
                    alert.style.transform = 'translateX(0)';
                }, 100);

                // Auto-remove after 4 seconds
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateX(100%)';
                    setTimeout(() => {
                        if (alert.parentNode) {
                            alert.parentNode.removeChild(alert);
                        }
                    }, 300);
                }, 4000);
            }
        }

        // Initialize check-in sync when page loads
        document.addEventListener('DOMContentLoaded', function() {
            window.checkinSync = new CheckInQueueSync();
            console.log('🏥 Check-In module ready with queue synchronization');
        });
    </script>

    <!-- Universal Framework Integration -->
    <script src="../../assets/js/queue-sync.js"></script>
    </div>
</body>

</html>