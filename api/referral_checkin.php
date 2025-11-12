<?php
require_once '../config/db.php';
require_once '../config/session/employee_session.php';

// Ensure employee is logged in
if (!is_employee_logged_in()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required'
    ]);
    exit;
}

// Get employee session data
$employee_id = get_employee_session('employee_id');
$employee_role = get_employee_session('role');

// Set JSON header
header('Content-Type: application/json');

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    
    if ($action === 'checkin_appointment') {
        $referral_id = $input['referral_id'] ?? null;
        $qr_data = $input['qr_data'] ?? null;
        $referral_data = $input['referral_data'] ?? null; // Parsed QR data
        $checkin_type = $input['checkin_type'] ?? 'quick'; // 'quick' or 'qr'
        
        if (!$referral_id) {
            throw new Exception('Referral ID is required');
        }
        
        // For QR check-in, validate the QR code
        if ($checkin_type === 'qr' && $qr_data) {
            require_once '../utils/qr_code_generator.php';
            
            // Validate QR data against referral
            if (!QRCodeGenerator::validateReferralQRData($qr_data, $referral_id)) {
                throw new Exception('Invalid or expired QR code for this referral');
            }
        }
        
        // Verify referral exists and has appointment
        $stmt = $conn->prepare("
            SELECT r.*, p.patient_id, p.first_name, p.last_name, p.patient_number,
                   e.first_name as doctor_first_name, e.last_name as doctor_last_name
            FROM referrals r 
            LEFT JOIN patients p ON r.patient_id = p.patient_id
            LEFT JOIN employees e ON r.assigned_doctor_id = e.employee_id
            WHERE r.referral_id = ? AND r.status = 'active' 
            AND r.destination_type = 'city_office' AND r.assigned_doctor_id IS NOT NULL
        ");
        $stmt->bind_param("i", $referral_id);
        $stmt->execute();
        $referral = $stmt->get_result()->fetch_assoc();
        
        if (!$referral) {
            throw new Exception('Referral not found or not eligible for check-in');
        }
        
        // For QR check-in, verify patient ID matches if available in QR
        if ($checkin_type === 'qr' && $referral_data && isset($referral_data['patient_id'])) {
            $qr_patient_id = $referral_data['patient_id'];
            if ($qr_patient_id && $qr_patient_id != $referral['patient_number'] && $qr_patient_id != $referral['patient_id']) {
                throw new Exception('QR code patient does not match the referral patient');
            }
        }
        
        // Check if appointment is today
        $appointment_date = new DateTime($referral['scheduled_date']);
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        
        if ($appointment_date < $today) {
            throw new Exception('Cannot check in for past appointments');
        }
        
        if ($appointment_date > $today) {
            $days_diff = $today->diff($appointment_date)->days;
            throw new Exception("Appointment is scheduled for " . $appointment_date->format('F j, Y') . " ($days_diff days from today)");
        }
        
        // Check if already checked in (visit record exists for today)
        $stmt = $conn->prepare("
            SELECT visit_id FROM visits 
            WHERE patient_id = ? AND visit_date = CURDATE() 
            AND visit_type = 'appointment'
            ORDER BY visit_id DESC LIMIT 1
        ");
        $stmt->bind_param("i", $referral['patient_id']);
        $stmt->execute();
        $existing_visit = $stmt->get_result()->fetch_assoc();
        
        if ($existing_visit) {
            throw new Exception('Patient has already checked in today (Visit ID: ' . $existing_visit['visit_id'] . ')');
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Create visit record
            $stmt = $conn->prepare("
                INSERT INTO visits (patient_id, visit_date, visit_time, visit_type, employee_id, 
                                  appointment_id, referral_id, status, remarks) 
                VALUES (?, CURDATE(), NOW(), 'appointment', ?, NULL, ?, 'active', ?)
            ");
            
            $remarks = "Check-in via referral appointment ($checkin_type check-in by " . get_employee_session('full_name') . ")";
            $stmt->bind_param("iiis", 
                $referral['patient_id'], 
                $employee_id, 
                $referral_id, 
                $remarks
            );
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to create visit record');
            }
            
            $visit_id = $conn->insert_id;
            
            // Update referral status to indicate check-in and record QR verification if applicable
            if ($checkin_type === 'qr') {
                $stmt = $conn->prepare("
                    UPDATE referrals 
                    SET remarks = CONCAT(COALESCE(remarks, ''), '\nPatient checked in on ', NOW(), ' via QR code verification'),
                        last_qr_verification = NOW()
                    WHERE referral_id = ?
                ");
                $stmt->bind_param("i", $referral_id);
            } else {
                $stmt = $conn->prepare("
                    UPDATE referrals 
                    SET remarks = CONCAT(COALESCE(remarks, ''), '\nPatient checked in on ', NOW(), ' via manual check-in'),
                        last_manual_verification = NOW(),
                        manual_verification_by = ?
                    WHERE referral_id = ?
                ");
                $stmt->bind_param("ii", $employee_id, $referral_id);
            }
            $stmt->execute();
            
            // Add patient to queue (triage station first)
            $stmt = $conn->prepare("
                INSERT INTO queue_entries (patient_id, visit_id, station_type, queue_number, 
                                         status, priority_level, created_at, notes) 
                VALUES (?, ?, 'triage', 
                       (SELECT COALESCE(MAX(queue_number), 0) + 1 FROM queue_entries q2 
                        WHERE q2.station_type = 'triage' AND DATE(q2.created_at) = CURDATE()), 
                       'waiting', 'normal', NOW(), ?)
            ");
            $queue_notes = "Referral appointment check-in - Doctor: " . $referral['doctor_first_name'] . ' ' . $referral['doctor_last_name'];
            $stmt->bind_param("iis", $referral['patient_id'], $visit_id, $queue_notes);
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to add patient to queue');
            }
            
            $queue_id = $conn->insert_id;
            
            // Log the action
            $stmt = $conn->prepare("
                INSERT INTO queue_logs (queue_id, employee_id, action, timestamp, remarks) 
                VALUES (?, ?, 'checked_in', NOW(), ?)
            ");
            $log_remarks = "Patient checked in via referral appointment ($checkin_type)";
            $stmt->bind_param("iis", $queue_id, $employee_id, $log_remarks);
            $stmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            // Success response
            echo json_encode([
                'success' => true,
                'message' => 'Patient checked in successfully',
                'data' => [
                    'visit_id' => $visit_id,
                    'queue_id' => $queue_id,
                    'patient_name' => $referral['first_name'] . ' ' . $referral['last_name'],
                    'doctor_name' => $referral['doctor_first_name'] . ' ' . $referral['doctor_last_name'],
                    'appointment_time' => $referral['scheduled_time'],
                    'checkin_type' => $checkin_type
                ]
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
    } else {
        throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    error_log("Referral Check-in API Error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>