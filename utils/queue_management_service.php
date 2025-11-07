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
     * Assign an employee to a station (CORRECTED VERSION)
     * This method now properly updates existing records instead of creating duplicates
     */
    public function assignEmployeeToStation($employee_id, $station_id, $start_date, $assignment_type = 'permanent', $shift_start = '08:00:00', $shift_end = '17:00:00', $assigned_by = null, $end_date = null) {
        try {
            // Ensure end_date is properly NULL if empty
            if (empty($end_date) || $end_date === '0000-00-00') {
                $end_date = null;
            }
            
            $this->pdo->beginTransaction();
            
            // Check if employee is already assigned to any OTHER station
            $overlap_check = "
                SELECT asch.*, s.station_name 
                FROM assignment_schedules asch
                JOIN stations s ON asch.station_id = s.station_id
                WHERE asch.employee_id = ? 
                AND asch.station_id != ?
                AND asch.is_active = 1
            ";
            
            $check_stmt = $this->pdo->prepare($overlap_check);
            $check_stmt->execute([$employee_id, $station_id]);
            $existing_assignment = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_assignment) {
                $this->pdo->rollBack();
                return [
                    'success' => false, 
                    'error' => "Employee is already assigned to {$existing_assignment['station_name']}. Please remove the existing assignment first."
                ];
            }
            
            // Check if this station already has an assignment record
            $station_check = "
                SELECT asch.*, CONCAT(e.first_name, ' ', e.last_name) as employee_name
                FROM assignment_schedules asch
                LEFT JOIN employees e ON asch.employee_id = e.employee_id
                WHERE asch.station_id = ?
                ORDER BY asch.schedule_id DESC
                LIMIT 1
            ";
            
            $station_stmt = $this->pdo->prepare($station_check);
            $station_stmt->execute([$station_id]);
            $existing_station_assignment = $station_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_station_assignment) {
                // UPDATE existing record instead of creating new one
                if ($existing_station_assignment['is_active'] && $existing_station_assignment['employee_id'] && $existing_station_assignment['employee_id'] != $employee_id) {
                    $this->pdo->rollBack();
                    return [
                        'success' => false, 
                        'error' => "Station is already assigned to {$existing_station_assignment['employee_name']}. Please remove the existing assignment first."
                    ];
                }
                
                // Update the existing assignment record
                $update_stmt = $this->pdo->prepare("
                    UPDATE assignment_schedules 
                    SET employee_id = ?, start_date = ?, end_date = ?, assignment_type = ?,
                        shift_start_time = ?, shift_end_time = ?, assigned_by = ?, is_active = 1,
                        assigned_at = NOW()
                    WHERE schedule_id = ?
                ");
                
                $result = $update_stmt->execute([
                    $employee_id, $start_date, $end_date, $assignment_type,
                    $shift_start, $shift_end, $assigned_by, $existing_station_assignment['schedule_id']
                ]);
                
                $schedule_id = $existing_station_assignment['schedule_id'];
                $action_type = $existing_station_assignment['employee_id'] ? 'reassigned' : 'created';
                
            } else {
                // Create new assignment record (only if station has never had an assignment)
                $insert_stmt = $this->pdo->prepare("
                    INSERT INTO assignment_schedules (
                        employee_id, station_id, start_date, end_date, assignment_type,
                        shift_start_time, shift_end_time, assigned_by, is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                
                $result = $insert_stmt->execute([
                    $employee_id, $station_id, $start_date, $end_date, $assignment_type,
                    $shift_start, $shift_end, $assigned_by
                ]);
                
                $schedule_id = $this->pdo->lastInsertId();
                $action_type = 'created';
            }
            
            if ($result) {
                // Log the assignment change
                $log_stmt = $this->pdo->prepare("
                    INSERT INTO assignment_logs (
                        schedule_id, employee_id, station_id, action_type, action_date, performed_by, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $notes = $action_type === 'reassigned' ? 
                    "Reassigned from employee {$existing_station_assignment['employee_id']} to {$employee_id}" :
                    "New assignment created";
                
                $log_stmt->execute([
                    $schedule_id, $employee_id, $station_id, $action_type, $start_date, $assigned_by, $notes
                ]);
                
                $this->pdo->commit();
                
                return [
                    'success' => true, 
                    'message' => 'Employee assigned to station successfully.',
                    'schedule_id' => $schedule_id
                ];
            }
            
            $this->pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to create assignment'];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log('QueueManagementService::assignEmployeeToStation Error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Database error occurred: ' . $e->getMessage()];
        }
    }
    
    /**
     * Remove an employee assignment from a station (CORRECTED VERSION)
     * This method now properly updates the record and logs changes
     */
    public function removeEmployeeAssignment($station_id, $removal_date, $removal_type = 'end_assignment', $performed_by = null) {
        try {
            $this->pdo->beginTransaction();
            
            // Find active assignment for this station
            $find_stmt = $this->pdo->prepare("
                SELECT asch.*, CONCAT(e.first_name, ' ', e.last_name) as employee_name
                FROM assignment_schedules asch
                LEFT JOIN employees e ON asch.employee_id = e.employee_id
                WHERE asch.station_id = ? 
                AND asch.is_active = 1
            ");
            $find_stmt->execute([$station_id]);
            $assignment = $find_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$assignment) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'No active assignment found for this station'];
            }
            
            if ($removal_type === 'end_assignment') {
                // Set end date to the removal date
                $stmt = $this->pdo->prepare("
                    UPDATE assignment_schedules 
                    SET end_date = ?, is_active = 0
                    WHERE schedule_id = ?
                ");
                $result = $stmt->execute([$removal_date, $assignment['schedule_id']]);
                $action_type = 'ended';
                $notes = "Assignment ended on {$removal_date}";
                
            } else { // deactivate - keep assignment but mark inactive
                $stmt = $this->pdo->prepare("
                    UPDATE assignment_schedules 
                    SET is_active = 0
                    WHERE schedule_id = ?
                ");
                $result = $stmt->execute([$assignment['schedule_id']]);
                $action_type = 'deactivated';
                $notes = "Assignment deactivated on {$removal_date}";
            }
            
            if ($result) {
                // Log the removal
                $log_stmt = $this->pdo->prepare("
                    INSERT INTO assignment_logs (
                        schedule_id, employee_id, station_id, action_type, action_date, performed_by, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $log_stmt->execute([
                    $assignment['schedule_id'], $assignment['employee_id'], $station_id, 
                    $action_type, $removal_date, $performed_by, $notes
                ]);
                
                $this->pdo->commit();
                return ['success' => true, 'message' => 'Assignment removed successfully'];
            }
            
            $this->pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to remove assignment'];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log('QueueManagementService::removeEmployeeAssignment Error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Database error occurred: ' . $e->getMessage()];
        }
    }
    
    /**
     * Reassign a station to a different employee (CORRECTED VERSION)
     * This method now properly updates the same record instead of creating new ones
     */
    public function reassignStation($station_id, $new_employee_id, $reassign_date, $assigned_by) {
        try {
            $this->pdo->beginTransaction();
            
            // Find the current assignment for this station
            $find_stmt = $this->pdo->prepare("
                SELECT asch.*, CONCAT(e.first_name, ' ', e.last_name) as current_employee_name
                FROM assignment_schedules asch
                LEFT JOIN employees e ON asch.employee_id = e.employee_id
                WHERE asch.station_id = ? 
                AND asch.is_active = 1
            ");
            $find_stmt->execute([$station_id]);
            $current_assignment = $find_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$current_assignment) {
                $this->pdo->rollBack();
                return ['success' => false, 'error' => 'No active assignment found for this station'];
            }
            
            // Check if new employee is already assigned elsewhere
            $conflict_check = "
                SELECT s.station_name 
                FROM assignment_schedules asch
                JOIN stations s ON asch.station_id = s.station_id
                WHERE asch.employee_id = ? 
                AND asch.station_id != ?
                AND asch.is_active = 1
            ";
            
            $conflict_stmt = $this->pdo->prepare($conflict_check);
            $conflict_stmt->execute([$new_employee_id, $station_id]);
            $conflict = $conflict_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($conflict) {
                $this->pdo->rollBack();
                return [
                    'success' => false, 
                    'error' => "Employee is already assigned to {$conflict['station_name']}. Please remove that assignment first."
                ];
            }
            
            // Update the existing assignment record (reassign to new employee)
            $update_stmt = $this->pdo->prepare("
                UPDATE assignment_schedules 
                SET employee_id = ?, start_date = ?, assigned_by = ?, assigned_at = NOW()
                WHERE schedule_id = ?
            ");
            
            $result = $update_stmt->execute([
                $new_employee_id, $reassign_date, $assigned_by, $current_assignment['schedule_id']
            ]);
            
            if ($result) {
                // Log the reassignment
                $log_stmt = $this->pdo->prepare("
                    INSERT INTO assignment_logs (
                        schedule_id, employee_id, station_id, action_type, action_date, performed_by, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $notes = "Station reassigned from employee {$current_assignment['employee_id']} ({$current_assignment['current_employee_name']}) to employee {$new_employee_id}";
                
                $log_stmt->execute([
                    $current_assignment['schedule_id'], $new_employee_id, $station_id, 
                    'reassigned', $reassign_date, $assigned_by, $notes
                ]);
                
                $this->pdo->commit();
                return ['success' => true, 'message' => 'Station reassigned successfully'];
            }
            
            $this->pdo->rollBack();
            return ['success' => false, 'error' => 'Failed to reassign station'];
            
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
     * Get all stations with their current assignments (IMPROVED VERSION)
     * This method now handles the corrected single-record-per-station approach
     */
    public function getAllStationsWithAssignments($date = null) {
        try {
            if ($date === null) {
                $date = date('Y-m-d');
            }
            
            // Modified query to show ALL stations (active and inactive) for management purposes
            $stmt = $this->pdo->prepare("
                SELECT 
                    s.station_id,
                    s.station_name,
                    s.station_type,
                    COALESCE(s.station_number, 1) as station_number,
                    s.is_active,
                    COALESCE(s.is_open, 1) as is_open,
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
                LEFT JOIN employees e ON asch.employee_id = e.employee_id AND e.status = 'active'
                LEFT JOIN roles r ON e.role_id = r.role_id
                ORDER BY s.is_active DESC, s.station_type, s.station_name, s.station_number
            ");
            
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Enhanced debug logging
            error_log("getAllStationsWithAssignments: Retrieved " . count($results) . " stations for date: $date");
            
            return $results;
            
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