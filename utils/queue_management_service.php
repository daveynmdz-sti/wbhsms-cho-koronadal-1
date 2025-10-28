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
    public function assignEmployeeToStation($station_id, $employee_id, $assignment_date, $assigned_by) {
        try {
            // Check if assignment already exists for this date
            $check_stmt = $this->pdo->prepare("
                SELECT assignment_id FROM station_assignments 
                WHERE station_id = ? AND assignment_date = ? AND status = 'active'
            ");
            $check_stmt->execute([$station_id, $assignment_date]);
            
            if ($check_stmt->fetch()) {
                return ['success' => false, 'message' => 'Station already has an active assignment for this date'];
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO station_assignments (
                    station_id, employee_id, assignment_date, assigned_by, status, created_at
                ) VALUES (?, ?, ?, ?, 'active', NOW())
            ");
            
            $result = $stmt->execute([$station_id, $employee_id, $assignment_date, $assigned_by]);
            
            if ($result) {
                return ['success' => true, 'assignment_id' => $this->pdo->lastInsertId()];
            }
            
            return ['success' => false, 'message' => 'Failed to create station assignment'];
            
        } catch (Exception $e) {
            error_log('QueueManagementService::assignEmployeeToStation Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error occurred'];
        }
    }
    
    /**
     * Remove an employee assignment from a station
     */
    public function removeEmployeeAssignment($station_id, $removal_date, $removal_type = 'manual', $performed_by = null) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE station_assignments 
                SET status = 'inactive', 
                    removed_at = NOW(),
                    removal_type = ?,
                    removed_by = ?
                WHERE station_id = ? AND assignment_date = ? AND status = 'active'
            ");
            
            $result = $stmt->execute([$removal_type, $performed_by, $station_id, $removal_date]);
            
            if ($result && $stmt->rowCount() > 0) {
                return ['success' => true, 'message' => 'Assignment removed successfully'];
            }
            
            return ['success' => false, 'message' => 'No active assignment found to remove'];
            
        } catch (Exception $e) {
            error_log('QueueManagementService::removeEmployeeAssignment Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error occurred'];
        }
    }
    
    /**
     * Reassign a station to a different employee
     */
    public function reassignStation($station_id, $new_employee_id, $reassign_date, $assigned_by) {
        try {
            $this->pdo->beginTransaction();
            
            // Remove current assignment
            $remove_result = $this->removeEmployeeAssignment($station_id, $reassign_date, 'reassignment', $assigned_by);
            
            if ($remove_result['success']) {
                // Create new assignment
                $assign_result = $this->assignEmployeeToStation($station_id, $new_employee_id, $reassign_date, $assigned_by);
                
                if ($assign_result['success']) {
                    $this->pdo->commit();
                    return ['success' => true, 'message' => 'Station reassigned successfully'];
                }
            }
            
            $this->pdo->rollBack();
            return ['success' => false, 'message' => 'Failed to reassign station'];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log('QueueManagementService::reassignStation Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error occurred'];
        }
    }
    
    /**
     * Toggle station active/inactive status
     */
    public function toggleStationStatus($station_id, $is_active) {
        try {
            $status = $is_active ? 'active' : 'inactive';
            
            $stmt = $this->pdo->prepare("
                UPDATE stations 
                SET status = ?, updated_at = NOW() 
                WHERE station_id = ?
            ");
            
            $result = $stmt->execute([$status, $station_id]);
            
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
                    s.status as station_status,
                    s.description,
                    sa.assignment_id,
                    sa.employee_id,
                    sa.assignment_date,
                    sa.status as assignment_status,
                    e.first_name,
                    e.last_name,
                    e.employee_id as emp_id
                FROM stations s
                LEFT JOIN station_assignments sa ON s.station_id = sa.station_id 
                    AND sa.assignment_date = ? 
                    AND sa.status = 'active'
                LEFT JOIN employees e ON sa.employee_id = e.employee_id
                ORDER BY s.station_name
            ");
            
            $stmt->execute([$date]);
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
                    employee_id,
                    first_name,
                    last_name,
                    position,
                    role
                FROM employees 
                WHERE status = 'active'
            ";
            
            $params = [];
            if ($facility_id) {
                $sql .= " AND facility_id = ?";
                $params[] = $facility_id;
            }
            
            $sql .= " ORDER BY first_name, last_name";
            
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
}