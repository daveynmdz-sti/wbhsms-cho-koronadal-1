<?php
// includes/medical_record_model.php
// Data-access layer for medical record printing.
// Provides modular functions to fetch patient medical record sections using PDO.

require_once dirname(__DIR__) . '/config/db.php';

class MedicalRecordModel {
    private $pdo;
    
    public function __construct() {
        global $pdo;
        $this->pdo = $pdo;
    }
    
    /**
     * Get basic patient information
     * @param int $patientId
     * @return array
     */
    public function getPatientBasic($patientId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    p.patient_id,
                    p.first_name,
                    p.middle_name,
                    p.last_name,
                    p.suffix,
                    p.date_of_birth,
                    p.sex as gender,
                    p.contact_number,
                    p.email,
                    p.barangay_id,
                    b.barangay_name as barangay,
                    'Koronadal' as municipality,
                    'South Cotabato' as province,
                    p.philhealth_id_number as philhealth_id,
                    p.qr_code as patient_qr_code,
                    p.status as is_active,
                    p.created_at,
                    p.updated_at
                FROM patients p
                LEFT JOIN barangays b ON p.barangay_id = b.barangay_id
                WHERE p.patient_id = ? AND p.status = 'active'
            ");
            $stmt->execute([$patientId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error fetching patient basic info: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get personal information details
     * @param int $patientId
     * @return array
     */
    public function getPersonalInformation($patientId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    pi.*,
                    p.first_name,
                    p.last_name
                FROM personal_information pi
                JOIN patients p ON pi.patient_id = p.patient_id
                WHERE pi.patient_id = ? AND p.status = 'active'
            ");
            $stmt->execute([$patientId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error fetching personal information: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get emergency contacts
     * @param int $patientId
     * @return array
     */
    public function getEmergencyContacts($patientId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    ec.*
                FROM emergency_contact ec
                JOIN patients p ON ec.patient_id = p.patient_id
                WHERE ec.patient_id = ? AND p.status = 'active'
                ORDER BY ec.created_at DESC
            ");
            $stmt->execute([$patientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching emergency contacts: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get lifestyle information
     * @param int $patientId
     * @return array
     */
    public function getLifestyleInformation($patientId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    li.*
                FROM lifestyle_information li
                JOIN patients p ON li.patient_id = p.patient_id
                WHERE li.patient_id = ? AND p.status = 'active'
            ");
            $stmt->execute([$patientId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error fetching lifestyle information: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get past medical conditions
     * @param int $patientId
     * @return array
     */
    public function getPastMedicalConditions($patientId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    pmc.*
                FROM past_medical_conditions pmc
                JOIN patients p ON pmc.patient_id = p.patient_id
                WHERE pmc.patient_id = ? AND p.status = 'active'
                ORDER BY pmc.date_diagnosed DESC
            ");
            $stmt->execute([$patientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching past medical conditions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get chronic illnesses
     * @param int $patientId
     * @return array
     */
    public function getChronicIllnesses($patientId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    ci.*
                FROM chronic_illnesses ci
                JOIN patients p ON ci.patient_id = p.patient_id
                WHERE ci.patient_id = ? AND p.status = 'active'
                ORDER BY ci.date_diagnosed DESC
            ");
            $stmt->execute([$patientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching chronic illnesses: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get immunizations
     * @param int $patientId
     * @return array
     */
    public function getImmunizations($patientId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    i.*
                FROM immunizations i
                JOIN patients p ON i.patient_id = p.patient_id
                WHERE i.patient_id = ? AND p.status = 'active'
                ORDER BY i.date_administered DESC
            ");
            $stmt->execute([$patientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching immunizations: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get family history
     * @param int $patientId
     * @return array
     */
    public function getFamilyHistory($patientId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    fh.*
                FROM family_history fh
                JOIN patients p ON fh.patient_id = p.patient_id
                WHERE fh.patient_id = ? AND p.status = 'active'
                ORDER BY fh.relationship, fh.condition_name
            ");
            $stmt->execute([$patientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching family history: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get surgical history
     * @param int $patientId
     * @return array
     */
    public function getSurgicalHistory($patientId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    sh.*
                FROM surgical_history sh
                JOIN patients p ON sh.patient_id = p.patient_id
                WHERE sh.patient_id = ? AND p.status = 'active'
                ORDER BY sh.surgery_date DESC
            ");
            $stmt->execute([$patientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching surgical history: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get allergies
     * @param int $patientId
     * @return array
     */
    public function getAllergies($patientId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    a.*
                FROM allergies a
                JOIN patients p ON a.patient_id = p.patient_id
                WHERE a.patient_id = ? AND p.status = 'active'
                ORDER BY a.severity DESC, a.allergen
            ");
            $stmt->execute([$patientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching allergies: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get current medications
     * @param int $patientId
     * @return array
     */
    public function getCurrentMedications($patientId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    cm.*
                FROM current_medications cm
                JOIN patients p ON cm.patient_id = p.patient_id
                WHERE cm.patient_id = ? AND p.status = 'active' AND cm.is_active = 1
                ORDER BY cm.medication_name
            ");
            $stmt->execute([$patientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching current medications: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get consultations with optional date range
     * @param int $patientId
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getConsultations($patientId, $dateFrom = null, $dateTo = null, $limit = 50, $offset = 0) {
        try {
            $sql = "
                SELECT 
                    c.*,
                    e.first_name as doctor_first_name,
                    e.last_name as doctor_last_name,
                    e.role as doctor_role
                FROM consultations c
                JOIN patients p ON c.patient_id = p.patient_id
                LEFT JOIN employees e ON c.employee_id = e.employee_id
                WHERE c.patient_id = ? AND p.is_active = 1
            ";
            
            $params = [$patientId];
            
            if ($dateFrom) {
                $sql .= " AND DATE(c.consultation_date) >= ?";
                $params[] = $dateFrom;
            }
            
            if ($dateTo) {
                $sql .= " AND DATE(c.consultation_date) <= ?";
                $params[] = $dateTo;
            }
            
            $sql .= " ORDER BY c.consultation_date DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching consultations: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get appointments with optional date range
     * @param int $patientId
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getAppointments($patientId, $dateFrom = null, $dateTo = null, $limit = 50, $offset = 0) {
        try {
            $sql = "
                SELECT 
                    a.*,
                    s.service_name,
                    e.first_name as doctor_first_name,
                    e.last_name as doctor_last_name
                FROM appointments a
                JOIN patients p ON a.patient_id = p.patient_id
                LEFT JOIN services s ON a.service_id = s.service_id
                LEFT JOIN employees e ON a.employee_id = e.employee_id
                WHERE a.patient_id = ? AND p.is_active = 1
            ";
            
            $params = [$patientId];
            
            if ($dateFrom) {
                $sql .= " AND DATE(a.appointment_date) >= ?";
                $params[] = $dateFrom;
            }
            
            if ($dateTo) {
                $sql .= " AND DATE(a.appointment_date) <= ?";
                $params[] = $dateTo;
            }
            
            $sql .= " ORDER BY a.appointment_date DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching appointments: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get referrals with optional date range
     * @param int $patientId
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getReferrals($patientId, $dateFrom = null, $dateTo = null, $limit = 50, $offset = 0) {
        try {
            $sql = "
                SELECT 
                    r.*,
                    e.first_name as referring_doctor_first_name,
                    e.last_name as referring_doctor_last_name,
                    hf.facility_name as facility_name
                FROM referrals r
                JOIN patients p ON r.patient_id = p.patient_id
                LEFT JOIN employees e ON r.employee_id = e.employee_id
                LEFT JOIN healthcare_facilities hf ON r.facility_id = hf.facility_id
                WHERE r.patient_id = ? AND p.is_active = 1
            ";
            
            $params = [$patientId];
            
            if ($dateFrom) {
                $sql .= " AND DATE(r.referral_date) >= ?";
                $params[] = $dateFrom;
            }
            
            if ($dateTo) {
                $sql .= " AND DATE(r.referral_date) <= ?";
                $params[] = $dateTo;
            }
            
            $sql .= " ORDER BY r.referral_date DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching referrals: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get prescriptions with medications
     * @param int $patientId
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getPrescriptions($patientId, $dateFrom = null, $dateTo = null, $limit = 50, $offset = 0) {
        try {
            $sql = "
                SELECT 
                    p.*,
                    e.first_name as doctor_first_name,
                    e.last_name as doctor_last_name,
                    c.consultation_date,
                    c.chief_complaint
                FROM prescriptions p
                JOIN patients pt ON p.patient_id = pt.patient_id
                LEFT JOIN employees e ON p.employee_id = e.employee_id
                LEFT JOIN consultations c ON p.consultation_id = c.consultation_id
                WHERE p.patient_id = ? AND pt.is_active = 1
            ";
            
            $params = [$patientId];
            
            if ($dateFrom) {
                $sql .= " AND DATE(p.prescription_date) >= ?";
                $params[] = $dateFrom;
            }
            
            if ($dateTo) {
                $sql .= " AND DATE(p.prescription_date) <= ?";
                $params[] = $dateTo;
            }
            
            $sql .= " ORDER BY p.prescription_date DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->pdo->prepare($sql);
            $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get medications for each prescription
            foreach ($prescriptions as &$prescription) {
                $medStmt = $this->pdo->prepare("
                    SELECT * FROM prescribed_medications 
                    WHERE prescription_id = ? 
                    ORDER BY medication_name
                ");
                $medStmt->execute([$prescription['prescription_id']]);
                $prescription['medications'] = $medStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            return $prescriptions;
        } catch (PDOException $e) {
            error_log("Error fetching prescriptions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get lab orders with items
     * @param int $patientId
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getLabOrders($patientId, $dateFrom = null, $dateTo = null, $limit = 50, $offset = 0) {
        try {
            $sql = "
                SELECT 
                    lo.*,
                    e.first_name as doctor_first_name,
                    e.last_name as doctor_last_name,
                    c.consultation_date,
                    c.chief_complaint
                FROM lab_orders lo
                JOIN patients p ON lo.patient_id = p.patient_id
                LEFT JOIN employees e ON lo.employee_id = e.employee_id
                LEFT JOIN consultations c ON lo.consultation_id = c.consultation_id
                WHERE lo.patient_id = ? AND p.is_active = 1
            ";
            
            $params = [$patientId];
            
            if ($dateFrom) {
                $sql .= " AND DATE(lo.order_date) >= ?";
                $params[] = $dateFrom;
            }
            
            if ($dateTo) {
                $sql .= " AND DATE(lo.order_date) <= ?";
                $params[] = $dateTo;
            }
            
            $sql .= " ORDER BY lo.order_date DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->pdo->prepare($sql);
            $labOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get lab order items for each order
            foreach ($labOrders as &$order) {
                $itemStmt = $this->pdo->prepare("
                    SELECT 
                        loi.*,
                        lt.test_name,
                        lt.test_category,
                        lt.normal_range,
                        lt.unit
                    FROM lab_order_items loi
                    LEFT JOIN lab_tests lt ON loi.test_id = lt.test_id
                    WHERE loi.lab_order_id = ?
                    ORDER BY lt.test_category, lt.test_name
                ");
                $itemStmt->execute([$order['lab_order_id']]);
                $order['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            return $labOrders;
        } catch (PDOException $e) {
            error_log("Error fetching lab orders: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get billing information with payments
     * @param int $patientId
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getBilling($patientId, $dateFrom = null, $dateTo = null, $limit = 50, $offset = 0) {
        try {
            $sql = "
                SELECT 
                    b.*,
                    e.first_name as cashier_first_name,
                    e.last_name as cashier_last_name,
                    c.consultation_date,
                    a.appointment_date
                FROM billing b
                JOIN patients p ON b.patient_id = p.patient_id
                LEFT JOIN employees e ON b.employee_id = e.employee_id
                LEFT JOIN consultations c ON b.consultation_id = c.consultation_id
                LEFT JOIN appointments a ON b.appointment_id = a.appointment_id
                WHERE b.patient_id = ? AND p.is_active = 1
            ";
            
            $params = [$patientId];
            
            if ($dateFrom) {
                $sql .= " AND DATE(b.billing_date) >= ?";
                $params[] = $dateFrom;
            }
            
            if ($dateTo) {
                $sql .= " AND DATE(b.billing_date) <= ?";
                $params[] = $dateTo;
            }
            
            $sql .= " ORDER BY b.billing_date DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $this->pdo->prepare($sql);
            $billings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get payments for each billing record
            foreach ($billings as &$billing) {
                $payStmt = $this->pdo->prepare("
                    SELECT 
                        pay.*,
                        e.first_name as cashier_first_name,
                        e.last_name as cashier_last_name
                    FROM payments pay
                    LEFT JOIN employees e ON pay.employee_id = e.employee_id
                    WHERE pay.billing_id = ?
                    ORDER BY pay.payment_date DESC
                ");
                $payStmt->execute([$billing['billing_id']]);
                $billing['payments'] = $payStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            return $billings;
        } catch (PDOException $e) {
            error_log("Error fetching billing information: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get comprehensive medical record data for a patient
     * @param int $patientId
     * @param array $sections Array of section names to include
     * @param array $filters Optional filters (date_from, date_to, limit, offset)
     * @return array
     */
    public function getComprehensiveMedicalRecord($patientId, $sections = [], $filters = []) {
        $result = [];
        
        // Default filters
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $limit = $filters['limit'] ?? 50;
        $offset = $filters['offset'] ?? 0;
        
        // If no sections specified, include all
        if (empty($sections)) {
            $sections = [
                'basic', 'personal_information', 'emergency_contacts', 'lifestyle_information',
                'past_medical_conditions', 'chronic_illnesses', 'immunizations', 'family_history',
                'surgical_history', 'allergies', 'current_medications', 'consultations',
                'appointments', 'referrals', 'prescriptions', 'lab_orders', 'billing'
            ];
        }
        
        // Fetch each requested section
        foreach ($sections as $section) {
            switch ($section) {
                case 'basic':
                    $result['basic'] = $this->getPatientBasic($patientId);
                    break;
                case 'personal_information':
                    $result['personal_information'] = $this->getPersonalInformation($patientId);
                    break;
                case 'emergency_contacts':
                    $result['emergency_contacts'] = $this->getEmergencyContacts($patientId);
                    break;
                case 'lifestyle_information':
                    $result['lifestyle_information'] = $this->getLifestyleInformation($patientId);
                    break;
                case 'past_medical_conditions':
                    $result['past_medical_conditions'] = $this->getPastMedicalConditions($patientId);
                    break;
                case 'chronic_illnesses':
                    $result['chronic_illnesses'] = $this->getChronicIllnesses($patientId);
                    break;
                case 'immunizations':
                    $result['immunizations'] = $this->getImmunizations($patientId);
                    break;
                case 'family_history':
                    $result['family_history'] = $this->getFamilyHistory($patientId);
                    break;
                case 'surgical_history':
                    $result['surgical_history'] = $this->getSurgicalHistory($patientId);
                    break;
                case 'allergies':
                    $result['allergies'] = $this->getAllergies($patientId);
                    break;
                case 'current_medications':
                    $result['current_medications'] = $this->getCurrentMedications($patientId);
                    break;
                case 'consultations':
                    $result['consultations'] = $this->getConsultations($patientId, $dateFrom, $dateTo, $limit, $offset);
                    break;
                case 'appointments':
                    $result['appointments'] = $this->getAppointments($patientId, $dateFrom, $dateTo, $limit, $offset);
                    break;
                case 'referrals':
                    $result['referrals'] = $this->getReferrals($patientId, $dateFrom, $dateTo, $limit, $offset);
                    break;
                case 'prescriptions':
                    $result['prescriptions'] = $this->getPrescriptions($patientId, $dateFrom, $dateTo, $limit, $offset);
                    break;
                case 'lab_orders':
                    $result['lab_orders'] = $this->getLabOrders($patientId, $dateFrom, $dateTo, $limit, $offset);
                    break;
                case 'billing':
                    $result['billing'] = $this->getBilling($patientId, $dateFrom, $dateTo, $limit, $offset);
                    break;
            }
        }
        
        return $result;
    }
}

// Convenience functions for procedural use
function getMedicalRecordModel() {
    return new MedicalRecordModel();
}

function getPatientBasic($patientId) {
    $model = new MedicalRecordModel();
    return $model->getPatientBasic($patientId);
}

function getPersonalInformation($patientId) {
    $model = new MedicalRecordModel();
    return $model->getPersonalInformation($patientId);
}

function getEmergencyContacts($patientId) {
    $model = new MedicalRecordModel();
    return $model->getEmergencyContacts($patientId);
}

function getLifestyleInformation($patientId) {
    $model = new MedicalRecordModel();
    return $model->getLifestyleInformation($patientId);
}

function getPastMedicalConditions($patientId) {
    $model = new MedicalRecordModel();
    return $model->getPastMedicalConditions($patientId);
}

function getChronicIllnesses($patientId) {
    $model = new MedicalRecordModel();
    return $model->getChronicIllnesses($patientId);
}

function getImmunizations($patientId) {
    $model = new MedicalRecordModel();
    return $model->getImmunizations($patientId);
}

function getFamilyHistory($patientId) {
    $model = new MedicalRecordModel();
    return $model->getFamilyHistory($patientId);
}

function getSurgicalHistory($patientId) {
    $model = new MedicalRecordModel();
    return $model->getSurgicalHistory($patientId);
}

function getAllergies($patientId) {
    $model = new MedicalRecordModel();
    return $model->getAllergies($patientId);
}

function getCurrentMedications($patientId) {
    $model = new MedicalRecordModel();
    return $model->getCurrentMedications($patientId);
}

function getConsultations($patientId, $dateFrom = null, $dateTo = null, $limit = 50, $offset = 0) {
    $model = new MedicalRecordModel();
    return $model->getConsultations($patientId, $dateFrom, $dateTo, $limit, $offset);
}

function getAppointments($patientId, $dateFrom = null, $dateTo = null, $limit = 50, $offset = 0) {
    $model = new MedicalRecordModel();
    return $model->getAppointments($patientId, $dateFrom, $dateTo, $limit, $offset);
}

function getReferrals($patientId, $dateFrom = null, $dateTo = null, $limit = 50, $offset = 0) {
    $model = new MedicalRecordModel();
    return $model->getReferrals($patientId, $dateFrom, $dateTo, $limit, $offset);
}

function getPrescriptions($patientId, $dateFrom = null, $dateTo = null, $limit = 50, $offset = 0) {
    $model = new MedicalRecordModel();
    return $model->getPrescriptions($patientId, $dateFrom, $dateTo, $limit, $offset);
}

function getLabOrders($patientId, $dateFrom = null, $dateTo = null, $limit = 50, $offset = 0) {
    $model = new MedicalRecordModel();
    return $model->getLabOrders($patientId, $dateFrom, $dateTo, $limit, $offset);
}

function getBilling($patientId, $dateFrom = null, $dateTo = null, $limit = 50, $offset = 0) {
    $model = new MedicalRecordModel();
    return $model->getBilling($patientId, $dateFrom, $dateTo, $limit, $offset);
}

function getComprehensiveMedicalRecord($patientId, $sections = [], $filters = []) {
    $model = new MedicalRecordModel();
    return $model->getComprehensiveMedicalRecord($patientId, $sections, $filters);
}

?>