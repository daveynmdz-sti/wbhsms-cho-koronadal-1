<?php
/**
 * Queue Management Service
 * Handles all queue-related operations including station assignments, queue entries, and staff management
 */

class QueueManagementService {
    private $pdo;
    
    public function __construct($pdo) {
        if (!$pdo) {
            throw new Exception('Database connection (PDO) is required for QueueManagementService');
        }
        $this->pdo = $pdo;
    }
    
    /**
     * Create a new queue entry for a patient
     */
    public function createQueueEntry($visit_id, $appointment_id, $patient_id, $service_id, $queue_type, $station_id = null, $priority_level = 'normal') {
        try {
            // Generate queue number and code
            $queue_number = $this->generateQueueNumber($queue_type, $station_id);
            $queue_code = $this->generateQueueCode($queue_type, $queue_number);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO queue_entries (
                    visit_id, appointment_id, patient_id, service_id, 
                    queue_type, station_id, queue_number, queue_code, 
                    priority_level, status, time_in
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'waiting', NOW())
            ");
            
            $result = $stmt->execute([
                $visit_id, $appointment_id, $patient_id, $service_id,
                $queue_type, $station_id, $queue_number, $queue_code,
                $priority_level
            ]);
            
            if ($result) {
                $queue_entry_id = $this->pdo->lastInsertId();
                
                // Log the queue entry creation
                $this->logQueueAction($queue_entry_id, 'created', null, 'Queue entry created');
                
                return [
                    'success' => true,
                    'queue_entry_id' => $queue_entry_id,
                    'queue_number' => $queue_number,
                    'queue_code' => $queue_code
                ];
            }
            
            return ['success' => false, 'message' => 'Failed to create queue entry'];
            
        } catch (Exception $e) {
            error_log('QueueManagementService::createQueueEntry Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error occurred'];
        }
    }
    
    /**
     * Assign an employee to a station
     */
    public function assignEmployeeToStation($employee_id, $station_id, $start_date, $assignment_type = 'permanent', $shift_start = '08:00:00', $shift_end = '17:00:00', $assigned_by = null, $end_date = null) {
        try {
            // Check if employee is already assigned to any station for overlapping dates
            $overlap_check = "
                SELECT asch.*, s.station_name 
                FROM assignment_schedules asch
                JOIN stations s ON asch.station_id = s.station_id
                WHERE asch.employee_id = ? 
                AND asch.is_active = 1
                AND asch.start_date <= ?
                AND (asch.end_date IS NULL OR asch.end_date >= ?)
            ";
            
            $check_end_date = $end_date ?: $start_date;
            $check_stmt = $this->pdo->prepare($overlap_check);
            $check_stmt->execute([$employee_id, $check_end_date, $start_date]);
            $existing_assignment = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_assignment) {
                return [
                    'success' => false, 
                    'error' => "Employee is already assigned to {$existing_assignment['station_name']} from {$existing_assignment['start_date']} to " . ($existing_assignment['end_date'] ?: 'ongoing') . ". Please remove or end the existing assignment first."
                ];
            }
            
            // Check if station is already assigned to another employee for overlapping dates
            $station_check = "
                SELECT asch.*, CONCAT(e.first_name, ' ', e.last_name) as employee_name
                FROM assignment_schedules asch
                JOIN employees e ON asch.employee_id = e.employee_id
                WHERE asch.station_id = ? 
                AND asch.is_active = 1
                AND asch.start_date <= ?
                AND (asch.end_date IS NULL OR asch.end_date >= ?)
            ";
            
            $station_stmt = $this->pdo->prepare($station_check);
            $station_stmt->execute([$station_id, $check_end_date, $start_date]);
            $existing_station_assignment = $station_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_station_assignment) {
                return [
                    'success' => false, 
                    'error' => "Station is already assigned to {$existing_station_assignment['employee_name']} from {$existing_station_assignment['start_date']} to " . ($existing_station_assignment['end_date'] ?: 'ongoing') . ". Please remove the existing assignment first."
                ];
            }
            
            // Insert new assignment
            $stmt = $this->pdo->prepare("
                INSERT INTO assignment_schedules (
                    employee_id, station_id, start_date, end_date, assignment_type,
                    shift_start_time, shift_end_time, assigned_by, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            
            $result = $stmt->execute([
                $employee_id, $station_id, $start_date, $end_date, $assignment_type,
                $shift_start, $shift_end, $assigned_by
            ]);
            
            if ($result) {
                return [
                    'success' => true, 
                    'message' => 'Employee assigned to station successfully.',
                    'schedule_id' => $this->pdo->lastInsertId()
                ];
            }
            
            return ['success' => false, 'error' => 'Failed to create assignment'];
            
        } catch (Exception $e) {
            error_log('QueueManagementService::assignEmployeeToStation Error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Database error occurred: ' . $e->getMessage()];
        }
    }
    
    /**
     * Remove an employee assignment from a station
     */
    public function removeEmployeeAssignment($station_id, $removal_date, $removal_type = 'end_assignment', $performed_by = null) {
        try {
            // Find active assignment for this station
            $find_stmt = $this->pdo->prepare("
                SELECT * FROM assignment_schedules 
                WHERE station_id = ? 
                AND is_active = 1 
                AND start_date <= ? 
                AND (end_date IS NULL OR end_date >= ?)
            ");
            $find_stmt->execute([$station_id, $removal_date, $removal_date]);
            $assignment = $find_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$assignment) {
                return ['success' => false, 'error' => 'No active assignment found for this station'];
            }
            
            if ($removal_type === 'end_assignment') {
                // Set end date to the day before removal date
                $end_date = date('Y-m-d', strtotime($removal_date . ' -1 day'));
                $stmt = $this->pdo->prepare("
                    UPDATE assignment_schedules 
                    SET end_date = ?, notes = CONCAT(IFNULL(notes, ''), ' [Ended on ', ?, ']')
                    WHERE schedule_id = ?
                ");
                $result = $stmt->execute([$end_date, $removal_date, $assignment['schedule_id']]);
            } else { // deactivate
                $stmt = $this->pdo->prepare("
                    UPDATE assignment_schedules 
                    SET is_active = 0, notes = CONCAT(IFNULL(notes, ''), ' [Deactivated on ', ?, ']')
                    WHERE schedule_id = ?
                ");
                $result = $stmt->execute([$removal_date, $assignment['schedule_id']]);
            }
            
            if ($result) {
                return ['success' => true, 'message' => 'Assignment removed successfully'];
            }
            
            return ['success' => false, 'error' => 'Failed to remove assignment'];
            
        } catch (Exception $e) {
            error_log('QueueManagementService::removeEmployeeAssignment Error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Database error occurred: ' . $e->getMessage()];
        }
    }
    
    /**
     * Reassign a station to a different employee
     */
    public function reassignStation($station_id, $new_employee_id, $reassign_date, $assigned_by) {
        try {
            $this->pdo->beginTransaction();
            
            // Remove current assignment (end it the day before reassign date)
            $remove_result = $this->removeEmployeeAssignment($station_id, $reassign_date, 'end_assignment', $assigned_by);
            
            if ($remove_result['success']) {
                // Create new assignment starting from reassign date
                $assign_result = $this->assignEmployeeToStation(
                    $new_employee_id, $station_id, $reassign_date, 'permanent', 
                    '08:00:00', '17:00:00', $assigned_by, null
                );
                
                if ($assign_result['success']) {
                    $this->pdo->commit();
                    return ['success' => true, 'message' => 'Station reassigned successfully'];
                } else {
                    $this->pdo->rollBack();
                    return ['success' => false, 'error' => $assign_result['error'] ?? 'Failed to assign new employee'];
                }
            } else {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => $remove_result['error'] ?? 'Failed to remove current assignment'];
            }
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log('QueueManagementService::reassignStation Error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Database error occurred: ' . $e->getMessage()];
        }
    }
    
    /**
     * Toggle station active/inactive status
     */
    public function toggleStationStatus($station_id, $is_active) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE stations 
                SET is_active = ?, updated_at = NOW() 
                WHERE station_id = ?
            ");
            
            $result = $stmt->execute([$is_active, $station_id]);
            
            return $result && $stmt->rowCount() > 0;
            
        } catch (Exception $e) {
            error_log('QueueManagementService::toggleStationStatus Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all stations with their current assignments
     */
    public function getAllStationsWithAssignments($date = null) {
        try {
            if ($date === null) {
                $date = date('Y-m-d');
            }
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    s.station_id,
                    s.station_name,
                    s.station_type,
                    s.station_number,
                    s.is_active,
                    s.is_open,
                    srv.name as service_name,
                    srv.service_id,
                    asch.schedule_id,
                    asch.employee_id,
                    asch.start_date,
                    asch.end_date,
                    asch.assignment_type,
                    asch.shift_start_time,
                    asch.shift_end_time,
                    asch.is_active as assignment_status,
                    e.first_name,
                    e.last_name,
                    e.employee_number,
                    CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                    r.role_name as employee_role
                FROM stations s
                LEFT JOIN services srv ON s.service_id = srv.service_id
                LEFT JOIN assignment_schedules asch ON s.station_id = asch.station_id 
                    AND asch.is_active = 1
                    AND (asch.start_date <= ? AND (asch.end_date IS NULL OR asch.end_date >= ?))
                LEFT JOIN employees e ON asch.employee_id = e.employee_id AND e.status = 'active'
                LEFT JOIN roles r ON e.role_id = r.role_id
                ORDER BY s.station_type, s.station_name, s.station_number
            ");
            
            $stmt->execute([$date, $date]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log('QueueManagementService::getAllStationsWithAssignments Error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get active employees for assignment
     */
    public function getActiveEmployees($facility_id = null) {
        try {
            $sql = "
                SELECT 
                    e.employee_id,
                    e.employee_number,
                    e.first_name,
                    e.last_name,
                    CONCAT(e.first_name, ' ', e.last_name) as full_name,
                    e.position,
                    r.role_name,
                    LOWER(r.role_name) as role
                FROM employees e
                LEFT JOIN roles r ON e.role_id = r.role_id
                WHERE e.status = 'active'
            ";
            
            $params = [];
            if ($facility_id) {
                $sql .= " AND e.facility_id = ?";
                $params[] = $facility_id;
            }
            
            $sql .= " ORDER BY e.first_name, e.last_name";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log('QueueManagementService::getActiveEmployees Error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get patient's current queue status
     */
    public function getPatientQueueStatus($patient_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    qe.*,
                    s.station_name,
                    s.station_type,
                    v.visit_type,
                    a.appointment_date,
                    a.appointment_time
                FROM queue_entries qe
                JOIN stations s ON qe.station_id = s.station_id
                LEFT JOIN visits v ON qe.visit_id = v.visit_id
                LEFT JOIN appointments a ON v.appointment_id = a.appointment_id
                WHERE qe.patient_id = ? 
                    AND qe.status IN ('waiting', 'in_progress')
                    AND DATE(qe.time_in) = CURDATE()
                ORDER BY qe.time_in DESC
                LIMIT 1
            ");
            
            $stmt->execute([$patient_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log('QueueManagementService::getPatientQueueStatus Error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update queue entry status
     */
    public function updateQueueStatus($queue_entry_id, $status, $employee_id = null, $remarks = null) {
        try {
            $time_field = '';
            $time_value = null;
            
            switch ($status) {
                case 'in_progress':
                    $time_field = ', time_started = NOW()';
                    break;
                case 'done':
                case 'completed':
                    $time_field = ', time_completed = NOW()';
                    break;
            }
            
            $stmt = $this->pdo->prepare("
                UPDATE queue_entries 
                SET status = ?, remarks = ?, updated_at = NOW() $time_field
                WHERE queue_entry_id = ?
            ");
            
            $result = $stmt->execute([$status, $remarks, $queue_entry_id]);
            
            if ($result) {
                // Log the status change
                $this->logQueueAction($queue_entry_id, 'status_changed', $employee_id, "Status changed to: $status");
                return ['success' => true];
            }
            
            return ['success' => false, 'message' => 'Failed to update queue status'];
            
        } catch (Exception $e) {
            error_log('QueueManagementService::updateQueueStatus Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error occurred'];
        }
    }
    
    /**
     * Generate queue number for a station/type
     */
    private function generateQueueNumber($queue_type, $station_id = null) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COALESCE(MAX(queue_number), 0) + 1 as next_number
                FROM queue_entries 
                WHERE queue_type = ? 
                    AND DATE(time_in) = CURDATE()
                    " . ($station_id ? "AND station_id = ?" : "")
            );
            
            $params = [$queue_type];
            if ($station_id) {
                $params[] = $station_id;
            }
            
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['next_number'] ?? 1;
            
        } catch (Exception $e) {
            error_log('QueueManagementService::generateQueueNumber Error: ' . $e->getMessage());
            return 1;
        }
    }
    
    /**
     * Generate queue code (e.g., T001, C015, L003)
     */
    private function generateQueueCode($queue_type, $queue_number) {
        $prefix_map = [
            'triage' => 'T',
            'consultation' => 'C',
            'lab' => 'L',
            'prescription' => 'P',
            'billing' => 'B',
            'document' => 'D'
        ];
        
        $prefix = $prefix_map[$queue_type] ?? 'Q';
        return $prefix . str_pad($queue_number, 3, '0', STR_PAD_LEFT);
    }
    
    /**
     * Log queue-related actions for audit trail
     */
    private function logQueueAction($queue_entry_id, $action, $employee_id = null, $details = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO queue_logs (
                    queue_entry_id, action, employee_id, details, timestamp
                ) VALUES (?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([$queue_entry_id, $action, $employee_id, $details]);
            
        } catch (Exception $e) {
            error_log('QueueManagementService::logQueueAction Error: ' . $e->getMessage());
            // Don't throw exception for logging failures
        }
    }
    
    /**
     * Get queue statistics for a date range
     */
    public function getQueueStatistics($start_date = null, $end_date = null) {
        try {
            if ($start_date === null) {
                $start_date = date('Y-m-d');
            }
            if ($end_date === null) {
                $end_date = $start_date;
            }
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    queue_type,
                    COUNT(*) as total_entries,
                    COUNT(CASE WHEN status = 'done' THEN 1 END) as completed,
                    COUNT(CASE WHEN status = 'waiting' THEN 1 END) as waiting,
                    COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
                    AVG(CASE WHEN waiting_time IS NOT NULL THEN waiting_time END) as avg_waiting_time,
                    AVG(CASE WHEN turnaround_time IS NOT NULL THEN turnaround_time END) as avg_turnaround_time
                FROM queue_entries 
                WHERE DATE(time_in) BETWEEN ? AND ?
                GROUP BY queue_type
                ORDER BY queue_type
            ");
            
            $stmt->execute([$start_date, $end_date]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log('QueueManagementService::getQueueStatistics Error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get station assignment for an employee on a specific date
     */
    public function getEmployeeStationAssignment($employee_id, $date = null) {
        try {
            if ($date === null) {
                $date = date('Y-m-d');
            }
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    sa.*,
                    s.station_name,
                    s.station_type,
                    s.description
                FROM station_assignments sa
                JOIN stations s ON sa.station_id = s.station_id
                WHERE sa.employee_id = ? 
                    AND sa.assignment_date = ? 
                    AND sa.status = 'active'
                LIMIT 1
            ");
            
            $stmt->execute([$employee_id, $date]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log('QueueManagementService::getEmployeeStationAssignment Error: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get active station assignment by employee (alias for backward compatibility)
     * This method provides the same functionality as getEmployeeStationAssignment()
     */
    public function getActiveStationByEmployee($employee_id, $date = null) {
        return $this->getEmployeeStationAssignment($employee_id, $date);
    }

    /**
     * Get station details with current assignment information
     */
    public function getStationDetails($station_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    s.station_id,
                    s.station_name,
                    s.station_type,
                    s.station_number,
                    s.is_active,
                    s.is_open,
                    srv.name as service_name,
                    srv.service_id,
                    asch.schedule_id,
                    asch.employee_id,
                    asch.start_date,
                    asch.end_date,
                    asch.assignment_type,
                    asch.shift_start_time,
                    asch.shift_end_time,
                    asch.is_active as assignment_status,
                    e.first_name,
                    e.last_name,
                    e.employee_number,
                    CONCAT(e.first_name, ' ', e.last_name) as employee_name,
                    r.role_name as employee_role
                FROM stations s
                LEFT JOIN services srv ON s.service_id = srv.service_id
                LEFT JOIN assignment_schedules asch ON s.station_id = asch.station_id 
                    AND asch.is_active = 1
                    AND (asch.start_date <= CURDATE() AND (asch.end_date IS NULL OR asch.end_date >= CURDATE()))
                LEFT JOIN employees e ON asch.employee_id = e.employee_id AND e.status = 'active'
                LEFT JOIN roles r ON e.role_id = r.role_id
                WHERE s.station_id = ?
            ");
            
            $stmt->execute([$station_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log('QueueManagementService::getStationDetails Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get current queue for a specific station
     */
    public function getStationQueue($station_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    qe.queue_entry_id,
                    qe.queue_number,
                    qe.queue_code,
                    qe.status,
                    qe.priority_level,
                    qe.created_at,
                    qe.updated_at,
                    p.patient_id,
                    CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                    v.visit_type,
                    v.visit_id,
                    a.appointment_id,
                    TIMESTAMPDIFF(MINUTE, qe.created_at, NOW()) as wait_time_minutes
                FROM queue_entries qe
                INNER JOIN patients p ON qe.patient_id = p.patient_id
                LEFT JOIN visits v ON qe.visit_id = v.visit_id
                LEFT JOIN appointments a ON qe.appointment_id = a.appointment_id
                WHERE qe.station_id = ? 
                    AND qe.status IN ('waiting', 'in_progress')
                    AND DATE(qe.created_at) = CURDATE()
                ORDER BY 
                    CASE qe.priority_level 
                        WHEN 'emergency' THEN 1 
                        WHEN 'urgent' THEN 2 
                        WHEN 'normal' THEN 3 
                        ELSE 4 
                    END,
                    qe.created_at ASC
            ");
            
            $stmt->execute([$station_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log('QueueManagementService::getStationQueue Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get station statistics for a specific date
     */
    public function getStationStatistics($station_id, $date = null) {
        try {
            if ($date === null) {
                $date = date('Y-m-d');
            }
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_served,
                    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_today,
                    COUNT(CASE WHEN status = 'skipped' THEN 1 END) as skipped_today,
                    COUNT(CASE WHEN status IN ('waiting', 'in_progress') THEN 1 END) as current_waiting,
                    AVG(CASE 
                        WHEN status = 'completed' AND updated_at IS NOT NULL 
                        THEN TIMESTAMPDIFF(MINUTE, created_at, updated_at)
                        ELSE NULL 
                    END) as avg_wait_time
                FROM queue_entries 
                WHERE station_id = ? 
                    AND DATE(created_at) = ?
            ");
            
            $stmt->execute([$station_id, $date]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Format average wait time
            if ($result['avg_wait_time']) {
                $result['avg_wait_time'] = round($result['avg_wait_time'], 1);
            } else {
                $result['avg_wait_time'] = 0;
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log('QueueManagementService::getStationStatistics Error: ' . $e->getMessage());
            return [
                'total_served' => 0,
                'completed_today' => 0,
                'skipped_today' => 0,
                'current_waiting' => 0,
                'avg_wait_time' => 0
            ];
        }
    }
}