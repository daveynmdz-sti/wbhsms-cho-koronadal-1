<?php
/**
 * Referral Permissions Utility
 * Centralizes permission logic for referral management across the system
 * Implements hybrid viewing rights and creator-based action permissions
 */

/**
 * Check if an employee can view a specific referral
 * BHW: Can view if they created it OR patient is from their barangay
 * DHO: Can view if they created it OR patient is from their district
 * Others: Can view all referrals system-wide
 */
function canEmployeeViewReferral($conn, $employee_id, $referral_id, $employee_role) {
    try {
        // Admin, Doctor, Nurse, Records Officer can view all referrals
        if (in_array(strtolower($employee_role), ['admin', 'doctor', 'nurse', 'records_officer'])) {
            return true;
        }
        
        // Get referral details with patient info
        $stmt = $conn->prepare("
            SELECT r.referral_id, r.referred_by, p.barangay_id, b.district_id
            FROM referrals r
            JOIN patients p ON r.patient_id = p.patient_id
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            WHERE r.referral_id = ?
        ");
        $stmt->bind_param('i', $referral_id);
        $stmt->execute();
        $referral = $stmt->get_result()->fetch_assoc();
        
        if (!$referral) {
            return false; // Referral doesn't exist
        }
        
        // Check if employee created this referral
        if ($referral['referred_by'] == $employee_id) {
            return true;
        }
        
        // BHW: Can view if patient is from their barangay
        if (strtolower($employee_role) === 'bhw') {
            $employee_barangay = getEmployeeBHWBarangay($conn, $employee_id);
            return $employee_barangay && $referral['barangay_id'] == $employee_barangay;
        }
        
        // DHO: Can view if patient is from their district
        if (strtolower($employee_role) === 'dho') {
            $employee_district = getEmployeeDHODistrict($conn, $employee_id);
            return $employee_district && $referral['district_id'] == $employee_district;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error checking referral view permission: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if an employee can edit/cancel/reinstate a specific referral
 * Only creator or admin can modify referrals
 */
function canEmployeeEditReferral($conn, $employee_id, $referral_id, $employee_role) {
    try {
        // Admin can edit all referrals
        if (strtolower($employee_role) === 'admin') {
            return true;
        }
        
        // Check if employee created this referral
        $stmt = $conn->prepare("SELECT referred_by FROM referrals WHERE referral_id = ?");
        $stmt->bind_param('i', $referral_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result && $result['referred_by'] == $employee_id;
        
    } catch (Exception $e) {
        error_log("Error checking referral edit permission: " . $e->getMessage());
        return false;
    }
}

/**
 * Get jurisdiction restriction SQL for referral queries
 * Returns array with restriction SQL and parameters
 */
function getEmployeeJurisdictionRestriction($conn, $employee_id, $employee_role) {
    $restriction = '';
    $params = [];
    
    try {
        // No restrictions for admin, doctor, nurse, records_officer
        if (in_array(strtolower($employee_role), ['admin', 'doctor', 'nurse', 'records_officer'])) {
            return ['restriction' => '', 'params' => []];
        }
        
        // BHW: Can view referrals they created OR for patients from their barangay
        if (strtolower($employee_role) === 'bhw') {
            $employee_barangay = getEmployeeBHWBarangay($conn, $employee_id);
            if ($employee_barangay) {
                $restriction = " AND (r.referred_by = ? OR p.barangay_id = ?)";
                $params = [$employee_id, $employee_barangay];
            } else {
                // If no barangay found, only show referrals they created
                $restriction = " AND r.referred_by = ?";
                $params = [$employee_id];
            }
        }
        
        // DHO: Can view referrals they created OR for patients from their district
        elseif (strtolower($employee_role) === 'dho') {
            $employee_district = getEmployeeDHODistrict($conn, $employee_id);
            if ($employee_district) {
                $restriction = " AND (r.referred_by = ? OR b.district_id = ?)";
                $params = [$employee_id, $employee_district];
            } else {
                // If no district found, only show referrals they created
                $restriction = " AND r.referred_by = ?";
                $params = [$employee_id];
            }
        }
        
        return ['restriction' => $restriction, 'params' => $params];
        
    } catch (Exception $e) {
        error_log("Error getting jurisdiction restriction: " . $e->getMessage());
        // On error, restrict to only referrals they created
        return ['restriction' => " AND r.referred_by = ?", 'params' => [$employee_id]];
    }
}

/**
 * Get BHW's assigned barangay ID
 */
function getEmployeeBHWBarangay($conn, $employee_id) {
    try {
        // Try with the employee_id as is first
        $stmt = $conn->prepare("
            SELECT f.barangay_id, e.employee_id, r.role_name, f.name as facility_name
            FROM employees e
            JOIN facilities f ON e.facility_id = f.facility_id
            LEFT JOIN roles r ON e.role_id = r.role_id
            WHERE e.employee_id = ? AND LOWER(r.role_name) = 'bhw'
        ");
        
        $stmt->bind_param('s', $employee_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        // If no result and employee_id is numeric, try with EMP prefix
        if (!$result && is_numeric($employee_id)) {
            $emp_id_with_prefix = 'EMP' . str_pad($employee_id, 5, '0', STR_PAD_LEFT);
            $stmt = $conn->prepare("
                SELECT f.barangay_id, e.employee_id, r.role_name, f.name as facility_name
                FROM employees e
                JOIN facilities f ON e.facility_id = f.facility_id
                LEFT JOIN roles r ON e.role_id = r.role_id
                WHERE e.employee_id = ? AND LOWER(r.role_name) = 'bhw'
            ");
            $stmt->bind_param('s', $emp_id_with_prefix);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
        }
        
        // Debug logging
        error_log("BHW Barangay Query - Employee ID: $employee_id, Result: " . print_r($result, true));
        
        return $result ? $result['barangay_id'] : null;
        
    } catch (Exception $e) {
        error_log("Error getting BHW barangay: " . $e->getMessage());
        return null;
    }
}

/**
 * Get DHO's assigned district ID
 */
function getEmployeeDHODistrict($conn, $employee_id) {
    try {
        // Try with the employee_id as is first
        $stmt = $conn->prepare("
            SELECT f.district_id, e.employee_id, r.role_name
            FROM employees e
            JOIN facilities f ON e.facility_id = f.facility_id
            LEFT JOIN roles r ON e.role_id = r.role_id
            WHERE e.employee_id = ? AND LOWER(r.role_name) = 'dho'
        ");
        $stmt->bind_param('s', $employee_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        // If no result and employee_id is numeric, try with EMP prefix
        if (!$result && is_numeric($employee_id)) {
            $emp_id_with_prefix = 'EMP' . str_pad($employee_id, 5, '0', STR_PAD_LEFT);
            $stmt = $conn->prepare("
                SELECT f.district_id, e.employee_id, r.role_name
                FROM employees e
                JOIN facilities f ON e.facility_id = f.facility_id
                LEFT JOIN roles r ON e.role_id = r.role_id
                WHERE e.employee_id = ? AND LOWER(r.role_name) = 'dho'
            ");
            $stmt->bind_param('s', $emp_id_with_prefix);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
        }
        
        return $result ? $result['district_id'] : null;
        
    } catch (Exception $e) {
        error_log("Error getting DHO district: " . $e->getMessage());
        return null;
    }
}

/**
 * Filter patients array based on employee jurisdiction
 * Used for patient search results in create referrals
 */
function filterPatientsByJurisdiction($conn, $patients, $employee_id, $employee_role) {
    try {
        // No filtering for admin, doctor, nurse, records_officer
        if (in_array(strtolower($employee_role), ['admin', 'doctor', 'nurse', 'records_officer'])) {
            return $patients;
        }
        
        // BHW: Can only create referrals for patients from their barangay
        if (strtolower($employee_role) === 'bhw') {
            $employee_barangay = getEmployeeBHWBarangay($conn, $employee_id);
            if (!$employee_barangay) {
                return []; // No patients if no barangay assigned
            }
            
            return array_filter($patients, function($patient) use ($employee_barangay) {
                return isset($patient['barangay_id']) && $patient['barangay_id'] == $employee_barangay;
            });
        }
        
        // DHO: Can only create referrals for patients from their district
        elseif (strtolower($employee_role) === 'dho') {
            $employee_district = getEmployeeDHODistrict($conn, $employee_id);
            if (!$employee_district) {
                return []; // No patients if no district assigned
            }
            
            return array_filter($patients, function($patient) use ($employee_district) {
                return isset($patient['district_id']) && $patient['district_id'] == $employee_district;
            });
        }
        
        return $patients;
        
    } catch (Exception $e) {
        error_log("Error filtering patients by jurisdiction: " . $e->getMessage());
        return [];
    }
}

/**
 * Validate if employee can create referral for specific patient
 */
function canEmployeeCreateReferralForPatient($conn, $employee_id, $patient_id, $employee_role) {
    try {
        // Admin, doctor, nurse, records_officer can create for any patient
        if (in_array(strtolower($employee_role), ['admin', 'doctor', 'nurse', 'records_officer'])) {
            return true;
        }
        
        // Get patient details
        $stmt = $conn->prepare("
            SELECT p.barangay_id, b.district_id
            FROM patients p
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            WHERE p.patient_id = ?
        ");
        $stmt->bind_param('i', $patient_id);
        $stmt->execute();
        $patient = $stmt->get_result()->fetch_assoc();
        
        if (!$patient) {
            return false; // Patient doesn't exist
        }
        
        // BHW: Can only create for patients from their barangay
        if (strtolower($employee_role) === 'bhw') {
            $employee_barangay = getEmployeeBHWBarangay($conn, $employee_id);
            return $employee_barangay && $patient['barangay_id'] == $employee_barangay;
        }
        
        // DHO: Can only create for patients from their district
        if (strtolower($employee_role) === 'dho') {
            $employee_district = getEmployeeDHODistrict($conn, $employee_id);
            return $employee_district && $patient['district_id'] == $employee_district;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error checking patient referral creation permission: " . $e->getMessage());
        return false;
    }
}

/**
 * Log referral access for audit trail
 */
function logReferralAccess($conn, $employee_id, $referral_id, $action, $details = '') {
    try {
        $stmt = $conn->prepare("
            INSERT INTO referral_access_logs (employee_id, referral_id, action, details, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('iiss', $employee_id, $referral_id, $action, $details);
        $stmt->execute();
        
    } catch (Exception $e) {
        error_log("Error logging referral access: " . $e->getMessage());
        // Don't throw exception - logging should not break main functionality
    }
}

/**
 * Get employee's jurisdiction display name for UI
 */
function getEmployeeJurisdictionName($conn, $employee_id, $employee_role) {
    try {
        if (strtolower($employee_role) === 'bhw') {
            $stmt = $conn->prepare("
                SELECT b.barangay_name
                FROM employees e
                JOIN facilities f ON e.facility_id = f.facility_id
                JOIN barangay b ON f.barangay_id = b.barangay_id
                WHERE e.employee_id = ?
            ");
            $stmt->bind_param('i', $employee_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            return $result ? "Barangay " . $result['barangay_name'] : "Unknown Barangay";
        }
        
        if (strtolower($employee_role) === 'dho') {
            $stmt = $conn->prepare("
                SELECT d.district_name
                FROM employees e
                JOIN facilities f ON e.facility_id = f.facility_id
                JOIN districts d ON f.district_id = d.district_id
                WHERE e.employee_id = ?
            ");
            $stmt->bind_param('i', $employee_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            
            return $result ? "District " . $result['district_name'] : "Unknown District";
        }
        
        return "System-wide";
        
    } catch (Exception $e) {
        error_log("Error getting jurisdiction name: " . $e->getMessage());
        return "Unknown";
    }
}

/**
 * Check if employee can access a facility based on their jurisdiction
 */
function canEmployeeAccessFacility($conn, $employee_id, $facility_id, $employee_role) {
    try {
        // Admin can access all facilities
        if (strtolower($employee_role) === 'admin') {
            return true;
        }
        
        // Doctor and Records Officer can access all facilities
        if (in_array(strtolower($employee_role), ['doctor', 'records_officer'])) {
            return true;
        }
        
        // Get facility information
        $stmt = $conn->prepare("
            SELECT f.facility_id, f.barangay_id, b.district_id, f.type
            FROM facilities f
            LEFT JOIN barangay b ON f.barangay_id = b.barangay_id
            WHERE f.facility_id = ?
        ");
        $stmt->bind_param('i', $facility_id);
        $stmt->execute();
        $facility = $stmt->get_result()->fetch_assoc();
        
        if (!$facility) {
            return false;
        }
        
        // BHW: Can only access their own barangay health center
        if (strtolower($employee_role) === 'bhw') {
            $employee_barangay = getEmployeeBHWBarangay($conn, $employee_id);
            return $employee_barangay && $facility['barangay_id'] == $employee_barangay;
        }
        
        // DHO: Can access facilities in their district
        if (strtolower($employee_role) === 'dho') {
            $employee_district = getEmployeeDHODistrict($conn, $employee_id);
            return $employee_district && $facility['district_id'] == $employee_district;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error checking facility access: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if employee can view a patient based on their jurisdiction
 */
function canEmployeeViewPatient($conn, $employee_id, $patient_id, $employee_role) {
    try {
        // Admin can view all patients
        if (strtolower($employee_role) === 'admin') {
            return true;
        }
        
        // Doctor and Records Officer can view all patients
        if (in_array(strtolower($employee_role), ['doctor', 'records_officer'])) {
            return true;
        }
        
        // Get patient information
        $stmt = $conn->prepare("
            SELECT p.patient_id, p.barangay_id, b.district_id
            FROM patients p
            JOIN barangay b ON p.barangay_id = b.barangay_id
            WHERE p.patient_id = ? AND p.status = 'active'
        ");
        $stmt->bind_param('i', $patient_id);
        $stmt->execute();
        $patient = $stmt->get_result()->fetch_assoc();
        
        if (!$patient) {
            return false;
        }
        
        // BHW: Can only view patients from their barangay
        if (strtolower($employee_role) === 'bhw') {
            $employee_barangay = getEmployeeBHWBarangay($conn, $employee_id);
            return $employee_barangay && $patient['barangay_id'] == $employee_barangay;
        }
        
        // DHO: Can only view patients from their district
        if (strtolower($employee_role) === 'dho') {
            $employee_district = getEmployeeDHODistrict($conn, $employee_id);
            return $employee_district && $patient['district_id'] == $employee_district;
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error checking patient view permission: " . $e->getMessage());
        return false;
    }
}

/**
 * Audit log for referral-related actions
 */
function auditReferralAction($conn, $employee_id, $action, $details = '') {
    try {
        $stmt = $conn->prepare("
            INSERT INTO referral_access_logs (employee_id, referral_id, action, details, created_at)
            VALUES (?, NULL, ?, ?, NOW())
        ");
        $stmt->bind_param('iss', $employee_id, $action, $details);
        $stmt->execute();
        
    } catch (Exception $e) {
        error_log("Error logging referral action: " . $e->getMessage());
        // Don't throw exception - logging should not break main functionality
    }
}