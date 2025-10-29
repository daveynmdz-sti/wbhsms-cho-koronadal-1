<?php
// Start output buffering at the very beginning
ob_start();

// Security headers for production
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Production-ready error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');  // Never show errors to users in production
ini_set('log_errors', '1');      // Log errors for debugging

// Set error handler for production
set_error_handler(function($severity, $message, $file, $line) {
    error_log("Patient Profile Error [$severity]: $message in $file on line $line");
    return true; // Don't execute PHP's internal error handler
});

// Set exception handler for uncaught exceptions
set_exception_handler(function($exception) {
    error_log("Patient Profile Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    
    // Clean any output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('HTTP/1.1 500 Internal Server Error');
    die('An unexpected error occurred. Please try again later.');
});

// Path setup
$root_path = dirname(dirname(dirname(__DIR__)));

// Load configuration first
require_once $root_path . '/config/env.php';

// Determine session type based on view_mode parameter
$view_mode = $_GET['view_mode'] ?? null;
if ($view_mode === 'admin' || $view_mode === 'bhw' || $view_mode === 'dho' || $view_mode === 'doctor' || $view_mode === 'nurse') {
    // Use employee session for management users
    require_once $root_path . '/config/session/employee_session.php';
} else {
    // Use patient session for regular patient view
    require_once $root_path . '/config/session/patient_session.php';
}

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token for form security
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once $root_path . '/config/db.php';

// Get patient ID from different sources (session or URL parameter for admin/bhw view)
$view_mode = $_GET['view_mode'] ?? null;
$patient_id = null;

// If in employee view mode, get patient_id from URL parameter
if ($view_mode === 'admin' || $view_mode === 'bhw' || $view_mode === 'dho' || $view_mode === 'doctor' || $view_mode === 'nurse' || $view_mode === 'records_officer' || $view_mode === 'cashier' || $view_mode === 'laboratory_tech' || $view_mode === 'pharmacist') {
    $patient_id = $_GET['patient_id'] ?? null;
    
    // Validate patient ID
    if (!$patient_id) {
        error_log("Patient profile access error: No patient ID provided");
        header('HTTP/1.1 400 Bad Request');
        die('Error: Patient ID is required');
    }
    
    // Sanitize patient ID (should be numeric)
    if (!is_numeric($patient_id) || $patient_id <= 0) {
        error_log("Patient profile access error: Invalid patient ID format: " . $patient_id);
        header('HTTP/1.1 400 Bad Request');
        die('Error: Invalid patient ID');
    }
    
    $patient_id = (int) $patient_id;

    // Verify employee is logged in and has correct role
    $employee_logged_in = isset($_SESSION['employee_id']) && !empty($_SESSION['employee_id']);
    $employee_role = $_SESSION['role'] ?? '';
    
    if (!$employee_logged_in) {
        error_log("Unauthorized access attempt to patient profile - no employee session");
        header('Location: ../../management/auth/employee_login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit();
    }
    
    // Role-specific validation
    if ($view_mode === 'admin' && strtolower($employee_role) !== 'admin') {
        error_log("Access denied: Employee " . $_SESSION['employee_id'] . " with role '$employee_role' attempted admin patient view");
        header('Location: ../../management/auth/employee_login.php?error=access_denied');
        exit();
    } elseif ($view_mode === 'bhw' && strtolower($employee_role) !== 'bhw') {
        error_log("Access denied: Employee " . $_SESSION['employee_id'] . " with role '$employee_role' attempted BHW patient view");
        header('Location: ../../management/auth/employee_login.php?error=access_denied');
        exit();
    } elseif ($view_mode === 'dho' && strtolower($employee_role) !== 'dho') {
        error_log("Access denied: Employee " . $_SESSION['employee_id'] . " with role '$employee_role' attempted DHO patient view");
        header('Location: ../../management/auth/employee_login.php?error=access_denied');
        exit();
    } elseif ($view_mode === 'doctor' && strtolower($employee_role) !== 'doctor') {
        error_log("Access denied: Employee " . $_SESSION['employee_id'] . " with role '$employee_role' attempted doctor patient view");
        header('Location: ../../management/auth/employee_login.php?error=access_denied');
        exit();
    } elseif ($view_mode === 'nurse' && strtolower($employee_role) !== 'nurse') {
        error_log("Access denied: Employee " . $_SESSION['employee_id'] . " with role '$employee_role' attempted nurse patient view");
        header('Location: ../../management/auth/employee_login.php?error=access_denied');
        exit();
    } elseif ($view_mode === 'records_officer' && strtolower($employee_role) !== 'records_officer') {
        error_log("Access denied: Employee " . $_SESSION['employee_id'] . " with role '$employee_role' attempted records officer patient view");
        header('Location: ../../management/auth/employee_login.php?error=access_denied');
        exit();
    } elseif ($view_mode === 'cashier' && strtolower($employee_role) !== 'cashier') {
        error_log("Access denied: Employee " . $_SESSION['employee_id'] . " with role '$employee_role' attempted cashier patient view");
        header('Location: ../../management/auth/employee_login.php?error=access_denied');
        exit();
    } elseif ($view_mode === 'laboratory_tech' && strtolower($employee_role) !== 'laboratory_tech') {
        error_log("Access denied: Employee " . $_SESSION['employee_id'] . " with role '$employee_role' attempted laboratory tech patient view");
        header('Location: ../../management/auth/employee_login.php?error=access_denied');
        exit();
    } elseif ($view_mode === 'pharmacist' && strtolower($employee_role) !== 'pharmacist') {
        error_log("Access denied: Employee " . $_SESSION['employee_id'] . " with role '$employee_role' attempted pharmacist patient view");
        header('Location: ../../management/auth/employee_login.php?error=access_denied');
        exit();
    }
} else {
    // Regular patient view - get ID from session
    $patient_id = $_SESSION['patient_id'] ?? null;
    if (!$patient_id) {
        header('Location: ../auth/patient_login.php');
        exit();
    }
}

// Fetch patient info using PDO with proper error handling
try {
    // First determine the correct ID column by checking the table structure
    $patient_row = null;
    $last_error = null;
    
    // Try the most likely queries first
    $queries_to_try = [
        "SELECT p.*, b.barangay_name as barangay FROM patients p LEFT JOIN barangay b ON p.barangay_id = b.barangay_id WHERE p.patient_id = ?",
        "SELECT p.*, b.barangay_name as barangay FROM patients p LEFT JOIN barangay b ON p.barangay_id = b.barangay_id WHERE p.id = ?",
        // Fallback without join in case barangay table doesn't exist
        "SELECT * FROM patients WHERE patient_id = ?",
        "SELECT * FROM patients WHERE id = ?"
    ];

    foreach ($queries_to_try as $index => $query) {
        try {
            $stmt = $pdo->prepare($query);
            if (!$stmt) {
                $last_error = "Failed to prepare query: " . implode(", ", $pdo->errorInfo());
                continue;
            }
            
            $result = $stmt->execute([$patient_id]);
            if (!$result) {
                $last_error = "Failed to execute query: " . implode(", ", $stmt->errorInfo());
                continue;
            }
            
            $patient_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($patient_row) {
                error_log("Patient profile: Successfully retrieved patient data using query #" . ($index + 1));
                break;
            }
        } catch (PDOException $e) {
            $last_error = "Database error on query #" . ($index + 1) . ": " . $e->getMessage();
            error_log("Patient profile query error: " . $last_error);
            continue;
        }
    }

    if (!$patient_row) {
        error_log("Patient profile error: Patient ID $patient_id not found. Last error: " . ($last_error ?? 'No errors recorded'));
        header('HTTP/1.1 404 Not Found');
        die('Error: Patient record not found.');
    }

    // DHO access control: verify patient is within DHO's assigned district
    if ($view_mode === 'dho' && isset($_SESSION['employee_id'])) {
        try {
            $dho_district_check = $pdo->prepare("
                SELECT COUNT(*) as can_access 
                FROM patients p
                LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
                LEFT JOIN facilities f ON b.district_id = f.district_id
                LEFT JOIN employees e ON e.facility_id = f.facility_id
                WHERE e.employee_id = ? AND (p.id = ? OR p.patient_id = ?)
            ");
            
            if (!$dho_district_check) {
                error_log("DHO access check: Failed to prepare query");
                // Allow access if we can't verify (graceful degradation)
            } else {
                $result = $dho_district_check->execute([$_SESSION['employee_id'], $patient_id, $patient_id]);
                if (!$result) {
                    error_log("DHO access check: Failed to execute query - " . implode(", ", $dho_district_check->errorInfo()));
                    // Allow access if we can't verify (graceful degradation)
                } else {
                    $access_result = $dho_district_check->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$access_result || $access_result['can_access'] == 0) {
                        error_log("DHO access denied: Employee " . $_SESSION['employee_id'] . " attempted to access patient $patient_id outside their district");
                        header('HTTP/1.1 403 Forbidden');
                        die('Access denied: This patient is not within your assigned district.');
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("DHO access check error: " . $e->getMessage());
            // For production security, you might want to deny access on error instead
            // For now, allowing graceful degradation
        }
    }
} catch (Exception $e) {
    error_log("Error fetching patient data: " . $e->getMessage());
    die('Error loading patient profile. Please try again.');
}

// Fetch all related patient information with proper error handling
$personal_information = [];
$emergency_contact = [];
$lifestyle_info = [];

try {
    // Fetch personal_information
    $stmt = $pdo->prepare("SELECT * FROM personal_information WHERE patient_id = ?");
    if ($stmt && $stmt->execute([$patient_id])) {
        $personal_information = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (PDOException $e) {
    error_log("Error fetching personal information for patient $patient_id: " . $e->getMessage());
}

try {
    // Fetch emergency_contact
    $stmt = $pdo->prepare("SELECT * FROM emergency_contact WHERE patient_id = ?");
    if ($stmt && $stmt->execute([$patient_id])) {
        $emergency_contact = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (PDOException $e) {
    error_log("Error fetching emergency contact for patient $patient_id: " . $e->getMessage());
}

try {
    // Fetch lifestyle_info (try new table name first)
    $stmt = $pdo->prepare("SELECT * FROM lifestyle_information WHERE patient_id = ?");
    if ($stmt && $stmt->execute([$patient_id])) {
        $lifestyle_info = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Fallback to old table name if new one doesn't exist or has no data
    if (!$lifestyle_info) {
        $stmt = $pdo->prepare("SELECT * FROM lifestyle_info WHERE patient_id = ?");
        if ($stmt && $stmt->execute([$patient_id])) {
            $lifestyle_info = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching lifestyle info for patient $patient_id: " . $e->getMessage());
    $lifestyle_info = [];
}

// Calculate age properly and determine age category
$age = '';
$age_category = '';
if (!empty($patient_row['dob']) || !empty($patient_row['date_of_birth'])) {
    $dob_field = $patient_row['dob'] ?? $patient_row['date_of_birth'];
    $dob = new DateTime($dob_field);
    $today = new DateTime();
    $age = $today->diff($dob)->y;

    // Determine age category
    if ($age < 18) {
        $age_category = 'Minor';
    } elseif ($age >= 60) {
        $age_category = 'Senior Citizen';
    } else {
        $age_category = 'Adult';
    }
}

// Organize patient data with flexible column name support
$patient = [
    "full_name" => trim(
        ($patient_row['first_name'] ?? $patient_row['fname'] ?? '') . ' ' .
            ($patient_row['middle_name'] ?? $patient_row['mname'] ?? '') . ' ' .
            ($patient_row['last_name'] ?? $patient_row['lname'] ?? '') . ' ' .
            ($patient_row['suffix'] ?? '')
    ),
    "patient_id" => $patient_row['id'] ?? $patient_row['patient_id'] ?? '',
    "username" => $patient_row['username'] ?? '',
    "age" => $age,
    "age_category" => $age_category,
    "sex" => $patient_row['sex'] ?? $patient_row['gender'] ?? '',
    "dob" => ($patient_row['dob'] ?? $patient_row['date_of_birth']) ? date('F j, Y', strtotime($patient_row['dob'] ?? $patient_row['date_of_birth'])) : '',
    "contact" => $patient_row['contact_num'] ?? $patient_row['contact_number'] ?? $patient_row['phone'] ?? $patient_row['contact'] ?? '',
    "email" => $patient_row['email'] ?? $patient_row['email_address'] ?? '',
    "barangay" => $patient_row['barangay'] ?? '',
    "philhealth_type" => $patient_row['philhealth_type'] ?? '',
    "philhealth_id_number" => $patient_row['philhealth_id_number'] ?? $patient_row['philhealth_id'] ?? '',
    "is_pwd" => $patient_row['is_pwd'] ?? $patient_row['pwd'] ?? $patient_row['pwd_status'] ?? '',
    "pwd_id_number" => $patient_row['pwd_id_number'] ?? '',
    "blood_type" => $personal_information['blood_type'] ?? '',
    "civil_status" => $personal_information['civil_status'] ?? '',
    "religion" => $personal_information['religion'] ?? '',
    "occupation" => $personal_information['occupation'] ?? '',
    "philhealth_id" => $personal_information['philhealth_id'] ?? '',
    "address" => $personal_information['street'] ?? '',
    "emergency" => [
        "name" => trim(($emergency_contact['emergency_first_name'] ?? '') . ' ' .
            ($emergency_contact['emergency_middle_name'] ?? '') . ' ' .
            ($emergency_contact['emergency_last_name'] ?? '')),
        "relationship" => $emergency_contact['emergency_relationship'] ?? '',
        "contact" => $emergency_contact['emergency_contact_number'] ?? ''
    ],
    "lifestyle" => [
        "smoking" => $lifestyle_info['smoking_status'] ?? '',
        "alcohol" => $lifestyle_info['alcohol_intake'] ?? '',
        "activity" => $lifestyle_info['physical_act'] ?? $lifestyle_info['physical_activity'] ?? '',
        "diet" => $lifestyle_info['diet_habit'] ?? $lifestyle_info['dietary_habits'] ?? ''
    ],
];

// Set session variables for sidebar display
$_SESSION['patient_name'] = $patient['full_name'];
$_SESSION['patient_number'] = $patient['username'];

// Also prepare defaults array for sidebar
$defaults = [
    'name' => $patient['full_name'],
    'patient_number' => $patient['username']
];

// Calculate profile completion percentage
$completion_score = 0;
$total_fields = 0;

// Basic information (weight: 30%)
$basic_fields = ['full_name', 'age', 'sex', 'dob', 'contact', 'email', 'barangay'];
$basic_completed = 0;
foreach ($basic_fields as $field) {
    $total_fields++;
    if (!empty($patient[$field])) {
        $completion_score++;
        $basic_completed++;
    }
}

// Personal details (weight: 20%)
$personal_fields = ['blood_type', 'civil_status', 'religion', 'occupation'];
$personal_completed = 0;
foreach ($personal_fields as $field) {
    $total_fields++;
    if (!empty($patient[$field])) {
        $completion_score++;
        $personal_completed++;
    }
}

// Emergency contact (weight: 15%)
$emergency_completed = 0;
$emergency_fields = ['name', 'relationship', 'contact'];
foreach ($emergency_fields as $field) {
    $total_fields++;
    if (!empty($patient['emergency'][$field])) {
        $completion_score++;
        $emergency_completed++;
    }
}

// Lifestyle info (weight: 15%)
$lifestyle_completed = 0;
$lifestyle_fields = ['smoking', 'alcohol', 'activity', 'diet'];
foreach ($lifestyle_fields as $field) {
    $total_fields++;
    if (!empty($patient['lifestyle'][$field])) {
        $completion_score++;
        $lifestyle_completed++;
    }
}

// Medical history will be calculated after fetching the data
$medical_completed = 0;

// Fetch latest vitals for this patient
$latest_vitals = null;
try {
    if (!empty($patient['patient_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM vitals WHERE patient_id = ? ORDER BY recorded_at DESC LIMIT 1");
        $stmt->execute([$patient['patient_id']]);
        $latest_vitals = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error fetching vitals: " . $e->getMessage());
}

// Fetch latest appointments with service and facility information
$latest_appointments = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            a.appointment_id,
            a.patient_id,
            a.scheduled_date as appointment_date,
            a.scheduled_time as appointment_time,
            a.status,
            a.cancellation_reason,
            a.created_at,
            a.updated_at,
            s.name as service_type,
            s.description as service_description,
            f.name as facility_name,
            f.type as facility_type
        FROM appointments a
        LEFT JOIN services s ON a.service_id = s.service_id
        LEFT JOIN facilities f ON a.facility_id = f.facility_id
        WHERE a.patient_id = ? 
        ORDER BY a.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$patient_id]);
    $latest_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching appointments: " . $e->getMessage());
}

// Fetch latest prescriptions with prescribed medications
$latest_prescriptions = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            p.prescription_id,
            p.patient_id,
            p.created_at as date_prescribed,
            p.status as prescription_status,
            p.visit_id,
            p.prescribed_by_employee_id,
            e.first_name as doctor_first_name,
            e.last_name as doctor_last_name,
            COUNT(pm.prescribed_medication_id) as total_medications,
            GROUP_CONCAT(
                CONCAT(pm.medication_name, ' (', pm.dosage, ', ', pm.frequency, ')') 
                ORDER BY pm.created_at ASC 
                SEPARATOR '; '
            ) as medications_summary,
            GROUP_CONCAT(pm.status ORDER BY pm.created_at ASC SEPARATOR ', ') as medication_statuses
        FROM prescriptions p
        LEFT JOIN prescribed_medications pm ON p.prescription_id = pm.prescription_id
        LEFT JOIN employees e ON p.prescribed_by_employee_id = e.employee_id
        WHERE p.patient_id = ? 
        GROUP BY p.prescription_id, p.patient_id, p.created_at, p.status, p.visit_id, p.prescribed_by_employee_id, e.first_name, e.last_name
        ORDER BY p.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$patient_id]);
    $latest_prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching prescriptions: " . $e->getMessage());
}

// Fetch lab results from lab_order_items with lab_orders information
$lab_results = [];
try {
    // Simplified query for lab results display
    $stmt = $pdo->prepare("
        SELECT 
            loi.item_id as lab_order_item_id,
            loi.test_type as test_name,
            loi.result_file,
            loi.result_date,
            loi.created_at,
            lo.order_date
        FROM lab_order_items loi
        INNER JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
        WHERE lo.patient_id = ? 
        ORDER BY COALESCE(loi.result_date, loi.created_at) DESC 
        LIMIT 4
    ");
    $stmt->execute([$patient_id]);
    $lab_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug logging for development
    if (empty($lab_results)) {
        error_log("No lab results found for patient ID: $patient_id");
        
        // Check if patient has any lab orders
        $order_check = $pdo->prepare("SELECT COUNT(*) FROM lab_orders WHERE patient_id = ?");
        $order_check->execute([$patient_id]);
        $order_count = $order_check->fetchColumn();
        error_log("Lab orders count for patient $patient_id: $order_count");
        
        // If no lab orders exist, create some test data for development (local only)
        if ($order_count == 0 && ($_SERVER['SERVER_NAME'] === 'localhost' || strpos($_SERVER['HTTP_HOST'], 'localhost') !== false)) {
            try {
                $pdo->beginTransaction();
                
                // Create a lab order using correct field names
                $insert_order = $pdo->prepare("
                    INSERT INTO lab_orders 
                    (patient_id, order_date, status, overall_status, ordered_by_employee_id, created_at) 
                    VALUES (?, NOW(), 'completed', 'completed', 1, NOW())
                ");
                $insert_order->execute([$patient_id]);
                $new_order_id = $pdo->lastInsertId();
                
                // Create simplified test lab order items
                $test_items = [
                    ['Complete Blood Count (CBC)', 'cbc_result_20251023.pdf'],
                    ['Fasting Blood Sugar', 'fbs_result_20251023.pdf'],
                    ['Urinalysis', null], // Pending result
                    ['Total Cholesterol', null] // Pending result
                ];
                
                foreach ($test_items as $item) {
                    $insert_item = $pdo->prepare("
                        INSERT INTO lab_order_items 
                        (lab_order_id, test_type, result_file, result_date, created_at, updated_at) 
                        VALUES (?, ?, ?, NOW(), NOW(), NOW())
                    ");
                    $insert_item->execute([$new_order_id, $item[0], $item[1]]);
                }
                
                $pdo->commit();
                error_log("Created test lab data for patient $patient_id");
                
                // Re-fetch the results
                $stmt->execute([$patient_id]);
                $lab_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
            } catch (Exception $create_error) {
                $pdo->rollback();
                error_log("Failed to create test lab data: " . $create_error->getMessage());
            }
        }
    }
    
} catch (Exception $e) {
    error_log("Error fetching lab results for patient $patient_id: " . $e->getMessage());
    $lab_results = [];
}

// Fetch medical history using PDO from separate tables with improved error handling
$medical_history = [
    'past_conditions' => [],
    'chronic_illnesses' => [],
    'family_history' => [],
    'surgical_history' => [],
    'allergies' => [],
    'current_medications' => [],
    'immunizations' => []
];

try {
    // Past Medical Conditions
    $stmt = $pdo->prepare("SELECT `condition`, year_diagnosed, status FROM past_medical_conditions WHERE patient_id = ? ORDER BY year_diagnosed DESC");
    $stmt->execute([$patient_id]);
    $medical_history['past_conditions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Chronic Illnesses
    $stmt = $pdo->prepare("SELECT illness, year_diagnosed, management FROM chronic_illnesses WHERE patient_id = ? ORDER BY year_diagnosed DESC");
    $stmt->execute([$patient_id]);
    $medical_history['chronic_illnesses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Family History
    $stmt = $pdo->prepare("SELECT family_member, `condition`, age_diagnosed, current_status FROM family_history WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $medical_history['family_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Surgical History
    $stmt = $pdo->prepare("SELECT surgery, year, hospital FROM surgical_history WHERE patient_id = ? ORDER BY year DESC");
    $stmt->execute([$patient_id]);
    $medical_history['surgical_history'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Current Medications
    $stmt = $pdo->prepare("SELECT medication, dosage, frequency, prescribed_by FROM current_medications WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $medical_history['current_medications'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Allergies
    $stmt = $pdo->prepare("SELECT allergen, reaction, severity FROM allergies WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $medical_history['allergies'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Immunizations
    $stmt = $pdo->prepare('SELECT vaccine, year_received, doses_completed, status FROM immunizations WHERE patient_id = ? ORDER BY year_received DESC');
    $stmt->execute([$patient_id]);
    $medical_history['immunizations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching medical history: " . $e->getMessage());
}

// Now calculate medical history completion (after fetching the data)
$medical_sections = ['allergies', 'current_medications', 'past_conditions', 'chronic_illnesses', 'immunizations', 'surgical_history', 'family_history'];
foreach ($medical_sections as $section) {
    $total_fields++;

    // Check if section has records (array with data)
    $section_data = $medical_history[$section] ?? [];
    $is_completed = false;

    if (!empty($section_data) && is_array($section_data)) {
        // Check if any record in the section indicates N/A or has actual data
        foreach ($section_data as $record) {
            // For N/A records, check if any field contains "Not Applicable"
            $has_na = false;
            $has_actual_data = false;

            foreach ($record as $field => $value) {
                if (stripos($value, 'Not Applicable') !== false || stripos($value, 'N/A') !== false) {
                    $has_na = true;
                    break;
                } elseif (!empty($value) && $value !== 'N/A' && stripos($value, 'Not Applicable') === false) {
                    $has_actual_data = true;
                }
            }

            // Section is complete if it has N/A records OR actual data
            if ($has_na || $has_actual_data) {
                $is_completed = true;
                break;
            }
        }
    }

    if ($is_completed) {
        $completion_score++;
        $medical_completed++;
    }
}

// Calculate final completion percentage
$completion_percentage = $total_fields > 0 ? round(($completion_score / $total_fields) * 100) : 0;

// Determine completion status and recommendations
$completion_status = 'incomplete';
$completion_message = 'Complete your profile for better healthcare service';
$completion_class = 'alert-warning';

if ($completion_percentage >= 90) {
    $completion_status = 'excellent';
    $completion_message = 'Your profile is complete and up-to-date';
    $completion_class = 'alert-success';
} elseif ($completion_percentage >= 70) {
    $completion_status = 'good';
    $completion_message = 'Good progress! Complete remaining fields for optimal care';
    $completion_class = 'alert-info';
} elseif ($completion_percentage >= 50) {
    $completion_status = 'fair';
    $completion_message = 'Please complete more information for better healthcare';
    $completion_class = 'alert-warning';
}

// Handle logout
if (isset($_GET['logout'])) {
    // Use patient session logout helper
    clear_patient_session();
    header('Location: ../auth/patient_login.php');
    exit();
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
    <title><?= 
        $view_mode === 'admin' ? "Admin View - Patient Profile" : 
        ($view_mode === 'bhw' ? "BHW View - Patient Profile" : 
        ($view_mode === 'dho' ? "DHO View - Patient Profile" : 
        ($view_mode === 'doctor' ? "Doctor View - Patient Profile" : 
        ($view_mode === 'nurse' ? "Nurse View - Patient Profile" : 
        ($view_mode === 'records_officer' ? "Records Officer View - Patient Profile" : 
        ($view_mode === 'cashier' ? "Cashier View - Patient Profile" : 
        ($view_mode === 'laboratory_tech' ? "Lab Tech View - Patient Profile" : 
        ($view_mode === 'pharmacist' ? "Pharmacist View - Patient Profile" : "Patient Profile")))))))) 
    ?> - WBHSMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/patient_profile.css" />
    <style>
        /* Enhanced Modal Styles */
        .custom-modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(0, 0, 0, 0.35);
            align-items: center;
            justify-content: center;
        }

        .custom-modal.active {
            display: flex !important;
        }

        .custom-modal .modal-content {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            padding: 2em 2.5em;
            max-width: 600px;
            width: 95vw;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Enhanced Utility Button Group */
        .profile-heading-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1.5em;
            margin-bottom: 2em;
            padding: 1.5em;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 16px;
            color: white;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.25);
        }

        .profile-heading-bar h1 {
            margin: 0;
            font-size: 2.5em;
            font-weight: 700;
            letter-spacing: 1px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .utility-btn-group {
            display: flex;
            gap: 0.8em;
            flex-wrap: wrap;
        }

        .utility-btn {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            padding: 0.7em 1.3em;
            border-radius: 10px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.6em;
            backdrop-filter: blur(10px);
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.95em;
        }

        .utility-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.5);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Enhanced Status Badge */
        .status-badge {
            display: inline-block;
            padding: 0.3em 0.8em;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            max-width: fit-content;
            word-wrap: normal;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .status-partial {
            background: #fff3cd;
            color: #856404;
        }

        .status-confirmed {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-checked_in,
        .status-checked-in {
            background: #d4edda;
            color: #155724;
        }

        /* Enhanced Alert Badges */
        .alert-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4em;
            padding: 0.4em 0.8em;
            border-radius: 8px;
            font-size: 0.85em;
            font-weight: 600;
            margin-bottom: 0.5em;
        }

        .alert-critical {
            background: #fee;
            color: #c53030;
            border-left: 4px solid #c53030;
        }

        .alert-warning {
            background: #fffbeb;
            color: #d69e2e;
            border-left: 4px solid #d69e2e;
        }

        .alert-info {
            background: #ebf8ff;
            color: #3182ce;
            border-left: 4px solid #3182ce;
        }

        /* Enhanced Cards */
        .enhanced-card {
            background: #fff;
            border-radius: 12px;
            padding: 1.5em;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #f0f0f0;
            transition: all 0.3s ease;
        }

        .enhanced-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        /* Responsive Design Improvements */
        @media (max-width: 768px) {
            .profile-heading-bar {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 1em;
            }

            .profile-heading-bar h1 {
                font-size: 2em;
            }

            .utility-btn-group {
                gap: 0.5em;
                justify-content: center;
            }

            .utility-btn {
                padding: 0.6em 1em;
                font-size: 0.9em;
            }

            .hide-on-mobile {
                display: none;
            }
        }

        /* Data Visualization Enhancements */
        .data-highlight {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 700;
        }

        .metric-card {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            border-radius: 12px;
            padding: 1.2em;
            text-align: center;
            border: none;
        }

        /* Loading Animation */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Profile Completion Styles */
        .completion-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 16px;
            padding: 1.5em;
            margin-bottom: 1.5em;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.25);
        }

        .completion-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1em;
        }

        .completion-percentage {
            font-size: 2em;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin-left: 20px;
        }

        .progress-bar {
            width: 60%;
            height: 8px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            overflow: hidden;
            margin: 1em 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4CAF50, #8BC34A);
            border-radius: 10px;
            transition: width 1s ease-in-out;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .completion-suggestions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 0.8em;
            margin-top: 1em;
        }

        .suggestion-item {
            background: rgba(255, 255, 255, 0.15);
            padding: 0.8em;
            border-radius: 8px;
            font-size: 0.9em;
            backdrop-filter: blur(10px);
        }

        /* Empty State Styling */
        .empty-state {
            text-align: center;
            padding: 2em;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 2px dashed #dee2e6;
            border-radius: 12px;
            color: #6c757d;
            margin: 1em 0;
        }

        .empty-state-icon {
            font-size: 3em;
            margin-bottom: 0.5em;
            opacity: 0.6;
            color: #007bff;
        }

        .empty-state h4 {
            margin: 0.5em 0;
            color: #495057;
            font-weight: 600;
        }

        .empty-state p {
            margin-bottom: 1em;
            font-size: 0.95em;
            line-height: 1.5;
        }

        .completion-prompt {
            background: linear-gradient(135deg, #17a2b8 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 0.8em 1.5em;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5em;
        }

        .completion-prompt:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            color: white;
            text-decoration: none;
        }

        /* Missing Info Alerts */
        .missing-info-alert {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border-left: 4px solid #ffc107;
            padding: 1em;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 0.8em;
        }

        .missing-info-alert .icon {
            color: #856404;
            font-size: 1.2em;
        }

        .missing-info-alert .content {
            flex: 1;
        }

        .missing-info-alert .title {
            font-weight: 600;
            color: #856404;
            margin-bottom: 0.3em;
        }

        .missing-info-alert .description {
            color: #664d03;
            font-size: 0.9em;
            line-height: 1.4;
        }

        /* Success States */
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        /* Info row completion indicators */
        .info-row.incomplete {
            background: rgba(255, 193, 7, 0.1);
            border-left: 3px solid #ffc107;
            padding-left: 0.8em;
        }

        .completion-badge {
            display: inline-block;
            padding: 0.2em 0.6em;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge-missing {
            background: #fff3cd;
            color: #856404;
        }

        .badge-complete {
            background: #d4edda;
            color: #155724;
        }

        @media (max-width: 900px) {
            .completion-row {
                flex-direction: column !important;
                gap: 1.2em !important;
                align-items: stretch !important;
            }

            .completion-card,
            .missing-info-alert {
                max-width: 100% !important;
                min-width: 0 !important;
                width: 100% !important;
                margin-bottom: 0.8em !important;
            }

            .completion-content {
                flex-direction: column !important;
                gap: 1em !important;
            }

            .completion-suggestions {
                flex-direction: row !important;
                flex-wrap: wrap !important;
                gap: 0.6em !important;
                min-width: 0 !important;
                width: 100% !important;
            }
        }
    </style>
</head>

<body>
    <?php
    if ($view_mode === 'admin') {
        $activePage = 'patient_records';
        include '../../../includes/sidebar_admin.php';
    } elseif ($view_mode === 'bhw') {
        $activePage = 'patients';
        include '../../../includes/sidebar_bhw.php';
    } elseif ($view_mode === 'dho') {
        $activePage = 'patient_records';
        include '../../../includes/sidebar_dho.php';
    } elseif ($view_mode === 'doctor') {
        $activePage = 'patients';
        include '../../../includes/sidebar_doctor.php';
    } elseif ($view_mode === 'nurse') {
        $activePage = 'patients';
        include '../../../includes/sidebar_nurse.php';
    } elseif ($view_mode === 'records_officer') {
        $activePage = 'patient_records';
        include '../../../includes/sidebar_records_officer.php';
    } elseif ($view_mode === 'cashier') {
        $activePage = 'patients';
        include '../../../includes/sidebar_cashier.php';
    } elseif ($view_mode === 'laboratory_tech') {
        $activePage = 'patients';
        include '../../../includes/sidebar_laboratory_tech.php';
    } elseif ($view_mode === 'pharmacist') {
        $activePage = 'patients';
        include '../../../includes/sidebar_pharmacist.php';
    } else {
        $activePage = 'profile';
        include '../../../includes/sidebar_patient.php';
    }
    ?>

    <!-- Main Content -->
    <section class="homepage">
        <div class="profile-heading-bar"
            style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1em;margin-bottom:1.5em;">
            <h1 style="margin:0;font-size:2.2em;letter-spacing:1px;">
                <?php if ($view_mode === 'admin'): ?>
                    <i class="fas fa-user-shield"></i> PATIENT PROFILE <span style="font-size: 0.65em; background: rgba(0,0,0,0.2); padding: 4px 10px; border-radius: 20px; vertical-align: middle;">ADMIN VIEW</span>
                <?php elseif ($view_mode === 'bhw'): ?>
                    <i class="fas fa-user-nurse"></i> PATIENT PROFILE <span style="font-size: 0.65em; background: rgba(0,0,0,0.2); padding: 4px 10px; border-radius: 20px; vertical-align: middle;">BHW VIEW</span>
                <?php elseif ($view_mode === 'dho'): ?>
                    <i class="fas fa-user-md"></i> PATIENT PROFILE <span style="font-size: 0.65em; background: rgba(0,0,0,0.2); padding: 4px 10px; border-radius: 20px; vertical-align: middle;">DHO VIEW</span>
                <?php elseif ($view_mode === 'doctor'): ?>
                    <i class="fas fa-stethoscope"></i> PATIENT PROFILE <span style="font-size: 0.65em; background: rgba(0,0,0,0.2); padding: 4px 10px; border-radius: 20px; vertical-align: middle;">DOCTOR VIEW</span>
                <?php elseif ($view_mode === 'nurse'): ?>
                    <i class="fas fa-user-nurse"></i> PATIENT PROFILE <span style="font-size: 0.65em; background: rgba(0,0,0,0.2); padding: 4px 10px; border-radius: 20px; vertical-align: middle;">NURSE VIEW</span>
                <?php elseif ($view_mode === 'records_officer'): ?>
                    <i class="fas fa-folder-open"></i> PATIENT PROFILE <span style="font-size: 0.65em; background: rgba(0,0,0,0.2); padding: 4px 10px; border-radius: 20px; vertical-align: middle;">RECORDS OFFICER VIEW</span>
                <?php elseif ($view_mode === 'cashier'): ?>
                    <i class="fas fa-cash-register"></i> PATIENT PROFILE <span style="font-size: 0.65em; background: rgba(0,0,0,0.2); padding: 4px 10px; border-radius: 20px; vertical-align: middle;">CASHIER VIEW</span>
                <?php elseif ($view_mode === 'laboratory_tech'): ?>
                    <i class="fas fa-flask"></i> PATIENT PROFILE <span style="font-size: 0.65em; background: rgba(0,0,0,0.2); padding: 4px 10px; border-radius: 20px; vertical-align: middle;">LAB TECH VIEW</span>
                <?php elseif ($view_mode === 'pharmacist'): ?>
                    <i class="fas fa-pills"></i> PATIENT PROFILE <span style="font-size: 0.65em; background: rgba(0,0,0,0.2); padding: 4px 10px; border-radius: 20px; vertical-align: middle;">PHARMACIST VIEW</span>
                <?php else: ?>
                    <i class="fas fa-user"></i> PATIENT PROFILE
                <?php endif; ?>
            </h1>
            <div class="utility-btn-group" style="display:flex;gap:0.7em;flex-wrap:wrap;">
                <?php if ($view_mode === 'admin'): ?>
                    <!-- Admin view buttons -->
                    <a href="../../management/admin/patient-records/patient_records_management.php" class="utility-btn" title="Back to Patient Records"
                        style="background:#f39c12;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(243,156,18,0.08);cursor:pointer;transition:background 0.18s;text-decoration:none;">
                        <i class="fas fa-arrow-left"></i> <span class="hide-on-mobile">Back to Records</span>
                    </a>
                    <button class="utility-btn" onclick="downloadPatientFile()" title="Download Patient File"
                        style="background:#2980b9;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(41,128,185,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-file-download"></i> <span class="hide-on-mobile">Export Medical Record</span>
                    </button>
                    <button class="utility-btn" onclick="printPatientFile()" title="Print Patient File"
                        style="background:#16a085;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(22,160,133,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-print"></i> <span class="hide-on-mobile">Print Medical Record</span>
                    </button>
                <?php elseif ($view_mode === 'bhw'): ?>
                    <!-- BHW view buttons -->
                    <a href="../../management/bhw/patient_records_management.php" class="utility-btn" title="Back to Patient Records"
                        style="background:#f39c12;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(243,156,18,0.08);cursor:pointer;transition:background 0.18s;text-decoration:none;">
                        <i class="fas fa-arrow-left"></i> <span class="hide-on-mobile">Back to Records</span>
                    </a>
                    <button class="utility-btn" onclick="printPatientFile()" title="Print Patient File"
                        style="background:#16a085;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(22,160,133,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-print"></i> <span class="hide-on-mobile">Print Patient Info</span>
                    </button>
                <?php elseif ($view_mode === 'doctor'): ?>
                    <!-- Doctor view buttons -->
                    <a href="../../management/doctor/patient_records_management.php" class="utility-btn" title="Back to Patient Records"
                        style="background:#f39c12;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(243,156,18,0.08);cursor:pointer;transition:background 0.18s;text-decoration:none;">
                        <i class="fas fa-arrow-left"></i> <span class="hide-on-mobile">Back to Records</span>
                    </a>
                    <button class="utility-btn" onclick="downloadPatientFile()" title="Download Patient File"
                        style="background:#2980b9;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(41,128,185,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-file-download"></i> <span class="hide-on-mobile">Export Medical Record</span>
                    </button>
                    <button class="utility-btn" onclick="printPatientFile()" title="Print Patient File"
                        style="background:#16a085;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(22,160,133,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-print"></i> <span class="hide-on-mobile">Print Medical Record</span>
                    </button>
                <?php elseif ($view_mode === 'dho'): ?>
                    <!-- DHO view buttons -->
                    <a href="../../management/dho/patient_records_management.php" class="utility-btn" title="Back to Patient Records"
                        style="background:#f39c12;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(243,156,18,0.08);cursor:pointer;transition:background 0.18s;text-decoration:none;">
                        <i class="fas fa-arrow-left"></i> <span class="hide-on-mobile">Back to Records</span>
                    </a>
                    <button class="utility-btn" onclick="downloadPatientFile()" title="Download Patient File"
                        style="background:#2980b9;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(41,128,185,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-file-download"></i> <span class="hide-on-mobile">Export Medical Record</span>
                    </button>
                    <button class="utility-btn" onclick="printPatientFile()" title="Print Patient File"
                        style="background:#16a085;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(22,160,133,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-print"></i> <span class="hide-on-mobile">Print Medical Record</span>
                    </button>
                <?php elseif ($view_mode === 'nurse'): ?>
                    <!-- Nurse view buttons -->
                    <a href="../../management/nurse/patient_records_management.php" class="utility-btn" title="Back to Patient Records"
                        style="background:#f39c12;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(243,156,18,0.08);cursor:pointer;transition:background 0.18s;text-decoration:none;">
                        <i class="fas fa-arrow-left"></i> <span class="hide-on-mobile">Back to Records</span>
                    </a>
                    <button class="utility-btn" onclick="printPatientFile()" title="Print Patient File"
                        style="background:#16a085;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(22,160,133,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-print"></i> <span class="hide-on-mobile">Print Patient Info</span>
                    </button>
                <?php elseif ($view_mode === 'records_officer'): ?>
                    <!-- Records Officer view buttons -->
                    <a href="../../management/records_officer/patient_records_management.php" class="utility-btn" title="Back to Patient Records"
                        style="background:#f39c12;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(243,156,18,0.08);cursor:pointer;transition:background 0.18s;text-decoration:none;">
                        <i class="fas fa-arrow-left"></i> <span class="hide-on-mobile">Back to Records</span>
                    </a>
                    <button class="utility-btn" onclick="downloadPatientFile()" title="Download Patient File"
                        style="background:#2980b9;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(41,128,185,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-file-download"></i> <span class="hide-on-mobile">Export Medical Record</span>
                    </button>
                    <button class="utility-btn" onclick="printPatientFile()" title="Print Patient File"
                        style="background:#16a085;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(22,160,133,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-print"></i> <span class="hide-on-mobile">Print Medical Record</span>
                    </button>
                <?php elseif ($view_mode === 'cashier'): ?>
                    <!-- Cashier view buttons -->
                    <a href="../../management/cashier/dashboard.php" class="utility-btn" title="Back to Dashboard"
                        style="background:#f39c12;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(243,156,18,0.08);cursor:pointer;transition:background 0.18s;text-decoration:none;">
                        <i class="fas fa-arrow-left"></i> <span class="hide-on-mobile">Back to Dashboard</span>
                    </a>
                    <button class="utility-btn" onclick="printPatientFile()" title="Print Patient File"
                        style="background:#16a085;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(22,160,133,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-print"></i> <span class="hide-on-mobile">Print Patient Info</span>
                    </button>
                <?php elseif ($view_mode === 'laboratory_tech'): ?>
                    <!-- Laboratory Tech view buttons -->
                    <a href="../../management/laboratory_tech/dashboard.php" class="utility-btn" title="Back to Dashboard"
                        style="background:#f39c12;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(243,156,18,0.08);cursor:pointer;transition:background 0.18s;text-decoration:none;">
                        <i class="fas fa-arrow-left"></i> <span class="hide-on-mobile">Back to Dashboard</span>
                    </a>
                    <button class="utility-btn" onclick="printPatientFile()" title="Print Patient File"
                        style="background:#16a085;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(22,160,133,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-print"></i> <span class="hide-on-mobile">Print Patient Info</span>
                    </button>
                <?php elseif ($view_mode === 'pharmacist'): ?>
                    <!-- Pharmacist view buttons -->
                    <a href="../../management/pharmacist/dashboard.php" class="utility-btn" title="Back to Dashboard"
                        style="background:#f39c12;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(243,156,18,0.08);cursor:pointer;transition:background 0.18s;text-decoration:none;">
                        <i class="fas fa-arrow-left"></i> <span class="hide-on-mobile">Back to Dashboard</span>
                    </a>
                    <button class="utility-btn" onclick="printPatientFile()" title="Print Patient File"
                        style="background:#16a085;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(22,160,133,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-print"></i> <span class="hide-on-mobile">Print Patient Info</span>
                    </button>
                <?php else: ?>
                    <!-- Regular patient view buttons -->
                    <button class="utility-btn" onclick="downloadPatientFile()" title="Download Patient File"
                        style="background:#2980b9;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(41,128,185,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-file-download"></i> <span class="hide-on-mobile">Download Patient File</span>
                    </button>
                    <a href="id_card.php" class="utility-btn" title="View My ID Card"
                        style="background:#16a085;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(22,160,133,0.08);cursor:pointer;transition:background 0.18s;text-decoration:none;">
                        <i class="fas fa-id-card"></i> <span class="hide-on-mobile">View My ID Card</span>
                    </a>
                    <button class="utility-btn" onclick="openUserSettings()" title="User Settings"
                        style="background:#f39c12;color:#fff;border:none;padding:0.6em 1.2em;border-radius:6px;font-weight:600;display:flex;align-items:center;gap:0.5em;box-shadow:0 2px 8px rgba(243,156,18,0.08);cursor:pointer;transition:background 0.18s;">
                        <i class="fas fa-cog"></i> <span class="hide-on-mobile">User Settings</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!in_array($view_mode, ['admin', 'doctor', 'nurse', 'records_officer', 'dho', 'bhw', 'cashier', 'laboratory_tech', 'pharmacist'])): ?>
            <div class="completion-row" style="display: flex; align-items: stretch; gap: 2em; width: 100%; flex-wrap: wrap;">
                <!-- Profile Completion Card -->
                <div class="completion-card" style="flex: 1; display: flex; flex-direction: column; justify-content: stretch; min-width: 350px;">
                    <div class="completion-header">
                        <div>
                            <h3 style="margin: 0; font-size: 1.3em;">
                                <i class="fas fa-chart-pie"></i> Profile Completion
                            </h3>
                            <p style="margin: 0.5em 0 0 0; opacity: 0.9;"><?= htmlspecialchars($completion_message) ?></p>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= $completion_percentage ?>%;"></div>
                        </div>

                        <div class="completion-percentage"><?= $completion_percentage ?>%</div>
                    </div>

                    <?php if ($completion_percentage < 90): ?>
                        <div class="completion-content" style="display: flex; align-items: stretch; justify-content: space-between; gap: 1.5em; flex-wrap: wrap; width: 100%;">
                            <div class="completion-suggestions" style="display: flex; flex-wrap: wrap; gap: 0.75em; flex: 1; align-items: stretch; min-width: 180px; width: 100%;">
                                <?php if ($basic_completed < count($basic_fields)): ?>
                                    <div class="suggestion-item" style="flex: 1; min-width: 180px;">
                                        <i class="fas fa-user"></i> Complete basic information
                                    </div>
                                <?php endif; ?>
                                <?php if ($personal_completed < count($personal_fields)): ?>
                                    <div class="suggestion-item" style="flex: 1; min-width: 180px;">
                                        <i class="fas fa-id-card"></i> Add personal details
                                    </div>
                                <?php endif; ?>
                                <?php if ($emergency_completed < count($emergency_fields)): ?>
                                    <div class="suggestion-item" style="flex: 1; min-width: 180px;">
                                        <i class="fas fa-phone"></i> Add emergency contact
                                    </div>
                                <?php endif; ?>
                                <?php if ($lifestyle_completed < count($lifestyle_fields)): ?>
                                    <div class="suggestion-item" style="flex: 1; min-width: 180px;">
                                        <i class="fas fa-heart"></i> Update lifestyle info
                                    </div>
                                <?php endif; ?>
                                <?php if ($medical_completed < count($medical_sections)): ?>
                                    <div class="suggestion-item" style="flex: 1; min-width: 180px;">
                                        <i class="fas fa-notes-medical"></i> Complete medical history
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Helpful Information Card -->
                <?php if ($completion_percentage < 80): ?>
                    <div class="missing-info-alert" style="flex: 0.5; margin-bottom: 1.5em; min-width: 350px; display: flex; flex-direction: row; height: 100%;">
                        <div class="icon">
                            <i class="fas fa-lightbulb"></i>
                        </div>
                        <div class="content" style="flex: 1;">
                            <div class="title">Why Complete Your Medical Profile?</div>
                            <div class="description">
                                <strong>Complete medical records help healthcare providers:</strong>
                                <ul style="text-align: left; margin: 0.5em 0; padding-left: 1.2em;">
                                    <li>Provide more accurate diagnoses and treatments</li>
                                    <li>Avoid dangerous medication interactions and allergic reactions</li>
                                    <li>Understand your health risks and family history</li>
                                    <li>Coordinate care between different specialists</li>
                                    <li>Respond effectively in medical emergencies</li>
                                </ul>
                            </div>
                        </div>
                        <a href="medical_history_edit.php" class="completion-prompt">
                            <i class="fas fa-heartbeat"></i> Start Now
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($view_mode === 'admin'): ?>
            <!-- Admin View: Patient Summary Header -->
            <div class="enhanced-card" style="margin-bottom: 1.5em; padding: 1.5em;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1em; flex-wrap: wrap; gap: 1em;">
                    <h3 style="margin: 0;"><i class="fas fa-user-circle"></i> Patient Summary</h3>
                    <div style="display: flex; gap: 0.5em; flex-wrap: wrap;">
                        <!-- Patient Category Badges -->
                        <?php if (!empty($patient['age_category'])): ?>
                            <?php if ($patient['age_category'] === 'Minor'): ?>
                                <span class="status-badge" style="background: #fff3cd; color: #856404;">
                                    <i class="fas fa-child"></i> Minor (<?= $patient['age'] ?> y.o.)
                                </span>
                            <?php elseif ($patient['age_category'] === 'Senior Citizen'): ?>
                                <span class="status-badge" style="background: #d4edda; color: #155724;">
                                    <i class="fas fa-user-friends"></i> Senior Citizen (<?= $patient['age'] ?> y.o.)
                                </span>
                            <?php elseif ($patient['age_category'] === 'Adult'): ?>
                                <span class="status-badge" style="background: #e3f2fd; color: #1565c0;">
                                    <i class="fas fa-user"></i> Adult (<?= $patient['age'] ?> y.o.)
                                </span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- PWD Status Badge -->
                        <?php if (!empty($patient['is_pwd']) && (strtolower($patient['is_pwd']) === 'yes' || $patient['is_pwd'] === '1' || strtolower($patient['is_pwd']) === 'true')): ?>
                            <span class="status-badge" style="background: #cce5ff; color: #004085;">
                                <i class="fas fa-wheelchair"></i> PWD
                                <?php if (!empty($patient['pwd_id_number'])): ?>
                                    (ID: <?= htmlspecialchars($patient['pwd_id_number']) ?>)
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>

                        <!-- PhilHealth Member Badge -->
                        <?php if (!empty($patient['philhealth_id_number']) || !empty($patient['philhealth_type'])): ?>
                            <span class="status-badge" style="background: #d1ecf1; color: #0c5460;">
                                <i class="fas fa-id-card-alt"></i> PhilHealth Member
                                <?php if (!empty($patient['philhealth_type'])): ?>
                                    (<?= htmlspecialchars($patient['philhealth_type']) ?>)
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="info-section" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1em;">
                    <div class="info-row">
                        <span>PATIENT ID:</span>
                        <span><strong><?= htmlspecialchars($patient['username']) ?></strong></span>
                    </div>
                    <div class="info-row">
                        <span>FULL NAME:</span>
                        <span><strong><?= htmlspecialchars($patient['full_name']) ?></strong></span>
                    </div>
                    <div class="info-row">
                        <span>AGE/SEX:</span>
                        <span><strong><?= htmlspecialchars($patient['age'] . ' years, ' . $patient['sex']) ?></strong></span>
                    </div>
                    <div class="info-row">
                        <span>CONTACT:</span>
                        <span><strong><?= htmlspecialchars($patient['contact'] ?: 'Not provided') ?></strong></span>
                    </div>
                    <div class="info-row">
                        <span>BARANGAY:</span>
                        <span><strong><?= htmlspecialchars($patient['barangay'] ?: 'Not provided') ?></strong></span>
                    </div>
                    <?php if (!empty($patient['philhealth_id_number'])): ?>
                        <div class="info-row">
                            <span>PHILHEALTH ID:</span>
                            <span><strong><?= htmlspecialchars($patient['philhealth_id_number']) ?></strong></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="profile-layout" style="max-width: none;">
            <!-- LEFT SIDE -->
            <div class="profile-wrapper">
                <!-- Top Header Card -->
                <div class="profile-header" style="
                    background: white;
                    border-radius: 16px;
                    padding: 2rem;
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    text-align: center;
                    border: 1px solid rgba(0, 0, 0, 0.05);
                    margin-bottom: 1.5rem;
                    position: relative;
                    overflow: hidden;
                ">
                    <!-- Decorative elements -->
                    <div style="position: absolute; top: -20px; right: -20px; width: 80px; height: 80px; background: linear-gradient(135deg, #667eea, #764ba2); opacity: 0.1; border-radius: 50%;"></div>
                    <div style="position: absolute; bottom: -30px; left: -30px; width: 100px; height: 100px; background: linear-gradient(135deg, #667eea, #764ba2); opacity: 0.08; border-radius: 50%;"></div>

                    <img class="profile-photo" style="
                        width: 180px;
                        height: 180px;
                        border-radius: 50%;
                        border: 4px solid #e9ecef;
                        object-fit: cover;
                        margin: 10px 0;
                        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
                        position: relative;
                        z-index: 2;
                    "
                        src="<?= $patient_id
                                    ? '../../../vendor/photo_controller.php?patient_id=' . urlencode((string)$patient_id)
                                    : 'https://ik.imagekit.io/wbhsmslogo/user.png?updatedAt=1750423429172' ?>"
                        alt="User photo"
                        onerror="this.onerror=null;this.src='https://ik.imagekit.io/wbhsmslogo/user.png?updatedAt=1750423429172';">

                    <div class="profile-info" style="z-index: 2; position: relative; width: 100%;align-items: anchor-center;">
                        <div class="profile-name-number">
                            <h2 style="
                                margin: 0 0 0.5rem 0; 
                                font-size: 1.8rem; 
                                font-weight: 700; 
                                color: #2c3e50;
                                letter-spacing: 0.5px;
                            "><?= htmlspecialchars($patient['full_name']) ?></h2>
                            <p style="
                                margin: 0 0 1.5rem 0; 
                                color: #6c757d; 
                                font-size: 1rem;
                                font-weight: 500;
                            ">Patient Number: <span style="color: #495057; font-weight: 600;"><?= htmlspecialchars($patient['username']) ?></span></p>

                            <!-- Age and Special Status Indicators -->
                            <div style="
                                display: flex; 
                                gap: 0.8rem; 
                                flex-wrap: wrap; 
                                justify-content: center;
                                margin-bottom: 1.5rem;
                            ">
                                <?php if (!empty($patient['age'])): ?>
                                    <span style="
                                        background: #f8f9fa;
                                        color: #495057;
                                        padding: 0.5rem 1rem;
                                        border-radius: 25px;
                                        font-size: 0.85rem;
                                        font-weight: 600;
                                        display: inline-flex;
                                        align-items: center;
                                        gap: 0.4rem;
                                        border: 2px solid #e9ecef;
                                        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                                    ">
                                        <i class="fas fa-birthday-cake" style="color: #6c757d;"></i> <?= $patient['age'] ?> YEARS OLD
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($patient['age_category'])): ?>
                                    <?php if ($patient['age_category'] === 'Minor'): ?>
                                        <span style="
                                            background: linear-gradient(135deg, #ffc107, #ffb300);
                                            color: white;
                                            padding: 0.5rem 1rem;
                                            border-radius: 25px;
                                            font-size: 0.85rem;
                                            font-weight: 600;
                                            display: inline-flex;
                                            align-items: center;
                                            gap: 0.4rem;
                                            box-shadow: 0 4px 12px rgba(255, 193, 7, 0.3);
                                            text-transform: uppercase;
                                            letter-spacing: 0.5px;
                                        ">
                                            <i class="fas fa-child"></i> MINOR
                                        </span>
                                    <?php elseif ($patient['age_category'] === 'Adult'): ?>
                                        <span style="
                                            background: linear-gradient(135deg, #007bff, #0056b3);
                                            color: white;
                                            padding: 0.5rem 1rem;
                                            border-radius: 25px;
                                            font-size: 0.85rem;
                                            font-weight: 600;
                                            display: inline-flex;
                                            align-items: center;
                                            gap: 0.4rem;
                                            box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
                                            text-transform: uppercase;
                                            letter-spacing: 0.5px;
                                        ">
                                            <i class="fas fa-user"></i> ADULT
                                        </span>
                                    <?php elseif ($patient['age_category'] === 'Senior Citizen'): ?>
                                        <span style="
                                            background: linear-gradient(135deg, #28a745, #1e7e34);
                                            color: white;
                                            padding: 0.5rem 1rem;
                                            border-radius: 25px;
                                            font-size: 0.85rem;
                                            font-weight: 600;
                                            display: inline-flex;
                                            align-items: center;
                                            gap: 0.4rem;
                                            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
                                            text-transform: uppercase;
                                            letter-spacing: 0.5px;
                                        ">
                                            <i class="fas fa-user-friends"></i> SENIOR CITIZEN
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if (!empty($patient['is_pwd']) && (strtolower($patient['is_pwd']) === 'yes' || $patient['is_pwd'] === '1' || strtolower($patient['is_pwd']) === 'true')): ?>
                                    <span style="
                                        background: linear-gradient(135deg, #6f42c1, #5a2d91);
                                        color: white;
                                        padding: 0.5rem 1rem;
                                        border-radius: 25px;
                                        font-size: 0.85rem;
                                        font-weight: 600;
                                        display: inline-flex;
                                        align-items: center;
                                        gap: 0.4rem;
                                        box-shadow: 0 4px 12px rgba(111, 66, 193, 0.3);
                                        text-transform: uppercase;
                                        letter-spacing: 0.5px;
                                    ">
                                        <i class="fas fa-wheelchair"></i> PWD
                                        <?php if (!empty($patient['pwd_id_number'])): ?>
                                            - ID: <?= htmlspecialchars($patient['pwd_id_number']) ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>

                                <?php if (!empty($patient['philhealth_id_number']) || !empty($patient['philhealth_type'])): ?>
                                    <span style="
                                        background: linear-gradient(135deg, #17a2b8, #117a8b);
                                        color: white;
                                        padding: 0.5rem 1rem;
                                        border-radius: 25px;
                                        font-size: 0.85rem;
                                        font-weight: 600;
                                        display: inline-flex;
                                        align-items: center;
                                        gap: 0.4rem;
                                        box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
                                        text-transform: uppercase;
                                        letter-spacing: 0.5px;
                                    ">
                                        <i class="fas fa-id-card-alt"></i> PHILHEALTH <?php if (!empty($patient['philhealth_type'])): ?>(<?= strtoupper(htmlspecialchars($patient['philhealth_type'])) ?>)<?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Profile Button - Only shown in patient view -->
                    <?php if (!in_array($view_mode, ['admin', 'doctor', 'nurse', 'records_officer', 'dho', 'bhw', 'cashier', 'laboratory_tech', 'pharmacist'])): ?>
                        <div class="profile-actions" style="z-index: 2; position: relative;">
                            <a href="profile_edit.php" style="
                                background: linear-gradient(135deg, #007bff, #0056b3);
                                color: white;
                                padding: 0.8rem 2rem;
                                border-radius: 25px;
                                text-decoration: none;
                                font-weight: 600;
                                display: inline-flex;
                                align-items: center;
                                gap: 0.6rem;
                                transition: all 0.3s ease;
                                font-size: 0.95rem;
                                box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
                                text-transform: uppercase;
                                letter-spacing: 0.5px;
                            " onmouseover="
                                this.style.transform='translateY(-2px)'; 
                                this.style.boxShadow='0 8px 25px rgba(0, 123, 255, 0.4)';
                            " onmouseout="
                                this.style.transform='translateY(0)'; 
                                this.style.boxShadow='0 4px 15px rgba(0, 123, 255, 0.3)';
                            ">
                                <i class="fas fa-edit"></i>
                                Edit Profile
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Personal Information -->
                <div class="profile-card" style="
                    background: white;
                    border-radius: 16px;
                    padding: 2rem;
                    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
                    border: 1px solid rgba(0, 0, 0, 0.05);
                    margin-bottom: 1.5rem;
                ">
                    <div class="section-header" style="
                        border-bottom: 2px solid #f8f9fa;
                        padding-bottom: 1rem;
                        margin-bottom: 1.5rem;
                    ">
                        <h3 style="
                            margin: 0;
                            color: #2c3e50;
                            font-size: 1.4rem;
                            font-weight: 700;
                            display: flex;
                            align-items: center;
                            gap: 0.8rem;
                        ">
                            <i class="fas fa-user-circle" style="color: #007bff;"></i>
                            Personal Information
                        </h3>
                    </div>
                    <div class="info-section"">
                        <?php
                        $missing_personal_fields = [];
                        // Organize fields: patients table first, then personal_information table
                        $personal_field_labels = [
                            // From patients table
                            'age' => 'AGE',
                            'sex' => 'SEX',
                            'dob' => 'DATE OF BIRTH',
                            'contact' => 'CONTACT NUMBER',
                            'email' => 'EMAIL',
                            'philhealth_type' => 'PHILHEALTH TYPE',
                            'philhealth_id_number' => 'PHILHEALTH ID NUMBER',
                            'is_pwd' => 'PWD STATUS',
                            'pwd_id_number' => 'PWD ID NUMBER',
                            // From personal_information table
                            'blood_type' => 'BLOOD TYPE',
                            'civil_status' => 'CIVIL STATUS',
                            'religion' => 'RELIGION',
                            'occupation' => 'OCCUPATION',
                            'address' => 'HOUSE NO. & STREET',
                            // From patients table
                            'barangay' => 'BARANGAY'
                        ];

                        foreach ($personal_field_labels as $field => $label):
                            $value = $patient[$field] ?? '';

                            // Special handling for specific fields
                            if ($field === 'age_category' && !empty($value)) {
                                // Add badge styling for age category
                                if ($value === 'Minor') {
                                    $value = '<span class="status-badge" style="background: #fff3cd; color: #856404; max-width: fit-content; word-wrap: normal;">' . $value . '</span>';
                                } elseif ($value === 'Senior Citizen') {
                                    $value = '<span class="status-badge" style="background: #d4edda; color: #155724; max-width: fit-content; word-wrap: normal;">' . $value . '</span>';
                                } else {
                                    $value = '<span class="status-badge" style="background: #e2e3e5; color: #495057; max-width: fit-content; word-wrap: normal;">' . $value . '</span>';
                                }
                            } elseif ($field === 'is_pwd') {
                                // Handle PWD status - only show if TRUE
                                if (strtolower($value) === 'yes' || $value === '1' || strtolower($value) === 'true') {
                                    $value = '<span class="status-badge" style="background: #cce5ff; color: #004085; max-width: fit-content; word-wrap: normal;">PWD</span>';
                                } else {
                                    // Skip this field if not PWD - don't count as missing
                                    continue;
                                }
                            } elseif ($field === 'pwd_id_number') {
                                // Only show PWD ID if patient is PWD
                                if (
                                    empty($patient['is_pwd']) ||
                                    !(strtolower($patient['is_pwd']) === 'yes' || $patient['is_pwd'] === '1' || strtolower($patient['is_pwd']) === 'true')
                                ) {
                                    continue; // Skip if not PWD - don't count as missing
                                }
                                if (empty($value)) {
                                    $value = '<span style="color: #6c757d; font-style: italic;">PWD ID not provided</span>';
                                }
                            }

                            // Check if field is actually missing (more reliable check)
                            $is_missing = false;
                            if ($field === 'age_category') {
                                // For age category, check the original value
                                $is_missing = empty($patient[$field]);
                            } elseif ($field === 'is_pwd' || $field === 'pwd_id_number') {
                                // For PWD fields, if we reach here they are not missing
                                $is_missing = false;
                            } else {
                                // For other fields, check original value
                                $original_value = $patient[$field] ?? '';
                                $is_missing = empty($original_value);
                            }

                            if ($is_missing) $missing_personal_fields[] = $label;
                        ?>
                            <div class=" info-row <?= $is_missing ? 'incomplete' : '' ?>">
                        <span><?= $label ?>:</span>
                        <span>
                            <?php if ($is_missing): ?>
                                <span class="completion-badge badge-missing" style="max-width: fit-content; word-wrap: normal;">
                                    <i class="fas fa-exclamation-triangle"></i> Missing
                                </span>
                            <?php else: ?>
                                <?= $value ?>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($missing_personal_fields) && !in_array($view_mode, ['admin', 'doctor', 'nurse', 'records_officer', 'dho', 'bhw'])): ?>
                    <div class="missing-info-alert">
                        <div class="icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div class="content">
                            <div class="title">Complete Your Personal Information</div>
                            <div class="description">
                                Missing: <?= implode(', ', array_slice($missing_personal_fields, 0, 3)) ?>
                                <?= count($missing_personal_fields) > 3 ? ' and ' . (count($missing_personal_fields) - 3) . ' more' : '' ?>
                            </div>
                        </div>
                        <a href="profile_edit.php" class="completion-prompt">
                            <i class="fas fa-edit"></i> Complete Now
                        </a>
                    </div>
                <?php endif; ?>
                </div>
            </div>
            <!-- Emergency Contact -->
            <div class="profile-card">
                <h3>Emergency Contact</h3>
                <div class="info-section">
                    <?php
                    $emergency_fields = [
                        'name' => 'NAME',
                        'relationship' => 'RELATIONSHIP',
                        'contact' => 'CONTACT NO.'
                    ];
                    $missing_emergency = [];

                    foreach ($emergency_fields as $field => $label):
                        $value = $patient['emergency'][$field] ?? '';
                        $is_missing = empty($value);
                        if ($is_missing) $missing_emergency[] = $label;
                    ?>
                        <div class="info-row <?= $is_missing ? 'incomplete' : '' ?>">
                            <span><?= $label ?>:</span>
                            <span>
                                <?php if ($is_missing): ?>
                                    <span class="completion-badge badge-missing" style="max-width: fit-content; word-wrap: normal;">
                                        <i class="fas fa-exclamation-triangle"></i> Missing
                                    </span>
                                <?php else: ?>
                                    <?= htmlspecialchars($value) ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($missing_emergency) && !in_array($view_mode, ['admin', 'doctor', 'nurse', 'records_officer', 'dho', 'bhw'])): ?>
                        <div class="missing-info-alert">
                            <div class="icon">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div class="content">
                                <div class="title">Emergency Contact Required</div>
                                <div class="description">
                                    Please add your emergency contact information for safety purposes.
                                    Missing: <?= implode(', ', $missing_emergency) ?>
                                </div>
                            </div>
                            <a href="profile_edit.php#emergency-contact" class="completion-prompt">
                                <i class="fas fa-plus"></i> Add Contact
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Lifestyle Information -->
            <div class="profile-card">
                <h3>Lifestyle Information</h3>
                <div class="info-section">
                    <?php
                    $lifestyle_fields = [
                        'smoking' => 'SMOKING STATUS',
                        'alcohol' => 'ALCOHOL INTAKE',
                        'activity' => 'PHYSICAL ACTIVITY',
                        'diet' => 'DIETARY HABIT'
                    ];
                    $missing_lifestyle = [];

                    foreach ($lifestyle_fields as $field => $label):
                        $value = $patient['lifestyle'][$field] ?? '';
                        $is_missing = empty($value);
                        if ($is_missing) $missing_lifestyle[] = $label;
                    ?>
                        <div class="info-row <?= $is_missing ? 'incomplete' : '' ?>">
                            <span><?= $label ?>:</span>
                            <span>
                                <?php if ($is_missing): ?>
                                    <span class="completion-badge badge-missing " style="max-width: fit-content; word-wrap: normal;">
                                        <i class="fas fa-exclamation-triangle"></i> Missing
                                    </span>
                                <?php else: ?>
                                    <?= htmlspecialchars($value) ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>

                    <?php if (!empty($missing_lifestyle) && !in_array($view_mode, ['admin', 'doctor', 'nurse', 'records_officer', 'dho', 'bhw'])): ?>
                        <div class="missing-info-alert">
                            <div class="icon">
                                <i class="fas fa-heart"></i>
                            </div>
                            <div class="content">
                                <div class="title">Complete Your Lifestyle Information</div>
                                <div class="description">
                                    Lifestyle information helps healthcare providers give you personalized care.
                                    Missing: <?= implode(', ', $missing_lifestyle) ?>
                                </div>
                            </div>
                            <a href="profile_edit.php#lifestyle-info" class="completion-prompt">
                                <i class="fas fa-edit"></i> Update Lifestyle
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- RIGHT SIDE -->
        <div class="patient-summary-section">
            <!-- 1. Enhanced Latest Vitals -->
            <div class="summary-card enhanced-card vitals-section">
                <div class="section-header">
                    <h2><i class="fas fa-heartbeat"></i> Latest Vitals</h2>
                    <small><i>
                            <?php
                            if ($latest_vitals && !empty($latest_vitals['recorded_at'])) {
                                echo "as of " . date("F d, Y h:i A", strtotime($latest_vitals['recorded_at']));
                            } else {
                                echo "No vitals recorded.";
                            }
                            ?>
                        </i></small>
                </div>

                <?php if ($latest_vitals): ?>
                    <div class="vitals-grid">
                        <div class="vital-card metric-card">
                            <i class="fas fa-ruler-vertical"></i>
                            <div>
                                <span class="label">HEIGHT</span><br>
                                <strong class="data-highlight"><?= htmlspecialchars($latest_vitals['height'] ?? '-') ?></strong>
                                <?= !empty($latest_vitals['height']) ? ' cm' : '' ?>
                            </div>
                        </div>
                        <div class="vital-card metric-card">
                            <i class="fas fa-weight"></i>
                            <div>
                                <span class="label">WEIGHT</span><br>
                                <strong class="data-highlight"><?= htmlspecialchars($latest_vitals['weight'] ?? '-') ?></strong>
                                <?= !empty($latest_vitals['weight']) ? ' kg' : '' ?>
                            </div>
                        </div>
                        <div class="vital-card metric-card">
                            <i class="fas fa-tachometer-alt"></i>
                            <div>
                                <span class="label">BLOOD PRESSURE</span><br>
                                <strong class="data-highlight">
                                    <?php 
                                    if (!empty($latest_vitals['systolic_bp']) && !empty($latest_vitals['diastolic_bp'])) {
                                        echo htmlspecialchars($latest_vitals['systolic_bp'] . '/' . $latest_vitals['diastolic_bp']);
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </strong>
                                <?= (!empty($latest_vitals['systolic_bp']) && !empty($latest_vitals['diastolic_bp'])) ? ' mmHg' : '' ?>
                            </div>
                        </div>
                        <div class="vital-card metric-card">
                            <i class="fas fa-heartbeat"></i>
                            <div>
                                <span class="label">HEART RATE</span><br>
                                <strong class="data-highlight"><?= htmlspecialchars($latest_vitals['heart_rate'] ?? '-') ?></strong>
                                <?= !empty($latest_vitals['heart_rate']) ? ' bpm' : '' ?>
                            </div>
                        </div>
                        <div class="vital-card metric-card">
                            <i class="fas fa-thermometer-half"></i>
                            <div>
                                <span class="label">TEMPERATURE</span><br>
                                <strong class="data-highlight"><?= htmlspecialchars($latest_vitals['temperature'] ?? '-') ?></strong>
                                <?= !empty($latest_vitals['temperature']) ? ' °C' : '' ?>
                            </div>
                        </div>
                        <div class="vital-card metric-card">
                            <i class="fas fa-lungs"></i>
                            <div>
                                <span class="label">RESPIRATORY RATE</span><br>
                                <strong class="data-highlight"><?= htmlspecialchars($latest_vitals['respiratory_rate'] ?? '-') ?></strong>
                                <?= !empty($latest_vitals['respiratory_rate']) ? ' bpm' : '' ?>
                            </div>
                        </div>
                        <?php if (!empty($latest_vitals['bmi'])): ?>
                            <div class="vital-card metric-card">
                                <i class="fas fa-calculator"></i>
                                <div>
                                    <span class="label">BMI</span><br>
                                    <strong class="data-highlight"><?= htmlspecialchars($latest_vitals['bmi']) ?></strong>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Vital Signs Alerts -->
                    <?php
                    $alerts = [];
                    $systolic_bp = intval($latest_vitals['systolic_bp'] ?? 0);
                    $diastolic_bp = intval($latest_vitals['diastolic_bp'] ?? 0);
                    $temp = floatval($latest_vitals['temperature'] ?? 0);
                    $hr = intval($latest_vitals['heart_rate'] ?? 0);

                    // Temperature alerts
                    if ($temp > 38.0) {
                        $alerts[] = ['type' => 'warning', 'message' => 'Elevated temperature detected'];
                    } elseif ($temp < 35.0 && $temp > 0) {
                        $alerts[] = ['type' => 'warning', 'message' => 'Low temperature detected'];
                    }

                    // Heart rate alerts
                    if ($hr > 100) {
                        $alerts[] = ['type' => 'warning', 'message' => 'Heart rate above normal range'];
                    } elseif ($hr < 60 && $hr > 0) {
                        $alerts[] = ['type' => 'info', 'message' => 'Heart rate below normal range'];
                    }

                    // Blood pressure alerts
                    if ($systolic_bp >= 140 || $diastolic_bp >= 90) {
                        $alerts[] = ['type' => 'warning', 'message' => 'High blood pressure detected'];
                    } elseif ($systolic_bp < 90 || $diastolic_bp < 60) {
                        $alerts[] = ['type' => 'info', 'message' => 'Low blood pressure detected'];
                    }

                    if (!empty($alerts)): ?>
                        <div style="margin-top: 1em;">
                            <?php foreach ($alerts as $alert): ?>
                                <div class="alert-badge alert-<?= $alert['type'] ?>">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <?= htmlspecialchars($alert['message']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-heartbeat"></i>
                        </div>
                        <h4>No Vital Signs Recorded</h4>
                        <p><strong>Schedule a checkup</strong> to record your vital signs. Regular monitoring helps track your health status and detect any changes early.</p>
                        <div style="display: flex; gap: 1em; justify-content: center; flex-wrap: wrap; margin-top: 1em;">
                            <a href="../appointment/appointments.php" class="completion-prompt">
                                <i class="fas fa-calendar-plus"></i> Schedule Checkup
                            </a>
                            <a href="../queueing/queue.php" class="completion-prompt">
                                <i class="fas fa-users"></i> Join Queue
                            </a>
                        </div>

                        <!-- Health Tips for Missing Vitals -->
                        <div style="margin-top: 1.5em; padding: 1em; background: rgba(23, 162, 184, 0.1); border-radius: 8px; font-size: 0.9em;">
                            <strong><i class="fas fa-info-circle"></i> Why Regular Vitals Matter:</strong>
                            <ul style="text-align: left; margin: 0.5em 0; padding-left: 1.2em;">
                                <li>Early detection of health issues</li>
                                <li>Monitor chronic conditions</li>
                                <li>Establish baseline health metrics</li>
                                <li>Track treatment effectiveness</li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Enhanced Appointments Section -->
            <div class="summary-card enhanced-card appointment-section">
                <div class="section-header">
                    <h2><i class="fas fa-calendar-alt"></i> Recent Appointments</h2>
                    <a href="../appointment/appointments.php" class="view-more-btn">
                        <i class="fas fa-chevron-right"></i> View All
                    </a>
                </div>

                <?php if (!empty($latest_appointments)): ?>
                    <div class="table-responsive">
                        <table class="summary-table">
                            <thead>
                                <tr>
                                    <th>Date Scheduled</th>
                                    <th>Time</th>
                                    <th>Service Type</th>
                                    <th>Facility</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($latest_appointments, 0, 4) as $appointment): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($appointment['appointment_date'])): ?>
                                                <?= htmlspecialchars(date('M j, Y', strtotime($appointment['appointment_date']))) ?>
                                            <?php else: ?>
                                                <span style="color: #666; font-style: italic;">Not scheduled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($appointment['appointment_time'])): ?>
                                                <?= htmlspecialchars(date('g:i A', strtotime($appointment['appointment_time']))) ?>
                                            <?php else: ?>
                                                <span style="color: #666; font-style: italic;">Not scheduled</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($appointment['service_type'])): ?>
                                                <div style="font-weight: 600;"><?= htmlspecialchars($appointment['service_type']) ?></div>
                                                <?php if (!empty($appointment['service_description'])): ?>
                                                    <small style="color: #666; font-size: 0.85em;"><?= htmlspecialchars($appointment['service_description']) ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #666; font-style: italic;">Service not specified</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($appointment['facility_name'])): ?>
                                                <div style="font-weight: 500;"><?= htmlspecialchars($appointment['facility_name']) ?></div>
                                                <?php if (!empty($appointment['facility_type'])): ?>
                                                    <small style="color: #666; font-size: 0.85em; text-transform: capitalize;"><?= htmlspecialchars($appointment['facility_type']) ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #666; font-style: italic;">Facility not specified</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status = $appointment['status'] ?? 'pending';
                                            $statusClass = 'status-' . strtolower($status);
                                            
                                            // Format status display
                                            $displayStatus = ucfirst(str_replace('_', ' ', $status));
                                            ?>
                                            <span class="status-badge <?= $statusClass ?>">
                                                <?= htmlspecialchars($displayStatus) ?>
                                            </span>
                                            <?php if ($status === 'cancelled' && !empty($appointment['cancellation_reason'])): ?>
                                                <small style="display: block; color: #dc3545; font-size: 0.8em; margin-top: 0.25em;">
                                                    <?= htmlspecialchars($appointment['cancellation_reason']) ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 2em; color: #666;">
                        <i class="fas fa-calendar-times" style="font-size: 2em; margin-bottom: 0.5em; opacity: 0.5;"></i>
                        <h4>No Appointments Scheduled</h4>
                        <p>You don't have any upcoming or recent appointments. Schedule one today to get the care you need.</p>
                        <div style="margin-top: 1em;">
                            <a href="../appointment/appointments.php" class="view-more-btn">
                                <i class="fas fa-calendar-plus"></i> Schedule Appointment
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Enhanced Prescriptions Section -->
            <div class="summary-card enhanced-card prescription-section">
                <div class="section-header">
                    <h2><i class="fas fa-prescription-bottle-alt"></i> Recent Prescriptions</h2>
                    <a href="../prescription/prescriptions.php" class="view-more-btn">
                        <i class="fas fa-chevron-right"></i> View All
                    </a>
                </div>

                <?php if (!empty($latest_prescriptions)): ?>
                    <div class="table-responsive">
                        <table class="summary-table">
                            <thead>
                                <tr>
                                    <th>Date Prescribed</th>
                                    <th>Prescribed By</th>
                                    <th>Medications</th>
                                    <th>Total Items</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($latest_prescriptions, 0, 4) as $prescription): ?>
                                    <tr>
                                        <td><?= htmlspecialchars(date('M j, Y', strtotime($prescription['date_prescribed'] ?? ''))) ?></td>
                                        <td>
                                            <?php if (!empty($prescription['doctor_first_name']) && !empty($prescription['doctor_last_name'])): ?>
                                                Dr. <?= htmlspecialchars($prescription['doctor_first_name'] . ' ' . $prescription['doctor_last_name']) ?>
                                            <?php else: ?>
                                                <span style="color: #666; font-style: italic;">Not specified</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($prescription['medications_summary'])): ?>
                                                <div style="max-width: 300px; font-size: 0.9em; line-height: 1.3;">
                                                    <?= htmlspecialchars($prescription['medications_summary']) ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #666; font-style: italic;">No medications listed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="data-highlight"><?= htmlspecialchars($prescription['total_medications'] ?? '0') ?></span>
                                            <?= ($prescription['total_medications'] ?? 0) == 1 ? ' item' : ' items' ?>
                                        </td>
                                        <td>
                                            <?php
                                            $status = $prescription['prescription_status'] ?? 'pending';
                                            $statusClass = 'status-' . strtolower($status);
                                            
                                            // Determine overall status based on medication statuses
                                            $medicationStatuses = explode(', ', $prescription['medication_statuses'] ?? '');
                                            $allDispensed = !empty($medicationStatuses) && !in_array('pending', $medicationStatuses) && !in_array('', $medicationStatuses);
                                            $hasUnavailable = in_array('unavailable', $medicationStatuses);
                                            
                                            if ($allDispensed && !$hasUnavailable) {
                                                $displayStatus = 'Completed';
                                                $statusClass = 'status-completed';
                                            } elseif ($hasUnavailable) {
                                                $displayStatus = 'Partial';
                                                $statusClass = 'status-partial';
                                            } else {
                                                $displayStatus = ucfirst($status);
                                            }
                                            ?>
                                            <span class="status-badge <?= $statusClass ?>">
                                                <?= htmlspecialchars($displayStatus) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 2em; color: #666;">
                        <i class="fas fa-pills" style="font-size: 2em; margin-bottom: 0.5em; opacity: 0.5;"></i>
                        <p>No prescriptions found</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Enhanced Lab Results Section -->
            <div class="summary-card enhanced-card lab-results-section">
                <div class="section-header">
                    <h2><i class="fas fa-flask"></i> Recent Lab Results</h2>
                    <a href="../laboratory/laboratory.php" class="view-more-btn">
                        <i class="fas fa-chevron-right"></i> View All
                    </a>
                </div>

                <?php if (!empty($lab_results)): ?>
                    <div class="table-responsive">
                        <table class="summary-table">
                            <thead>
                                <tr>
                                    <th>Test Date</th>
                                    <th>Test Name</th>
                                    <th>Result</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($lab_results, 0, 4) as $result): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($result['result_date'])): ?>
                                                <?= htmlspecialchars(date('M j, Y', strtotime($result['result_date']))) ?>
                                                <small style="display: block; color: #666; font-size: 0.8em;">
                                                    <?= htmlspecialchars(date('g:i A', strtotime($result['result_date']))) ?>
                                                </small>
                                            <?php elseif (!empty($result['order_date'])): ?>
                                                <?= htmlspecialchars(date('M j, Y', strtotime($result['order_date']))) ?>
                                                <small style="display: block; color: #666; font-size: 0.8em;">Ordered</small>
                                            <?php else: ?>
                                                <span style="color: #666; font-style: italic;">Date not available</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="font-weight: 600;"><?= htmlspecialchars($result['test_name'] ?? 'Unknown Test') ?></div>
                                        </td>
                                        <td>
                                            <?php if (!empty($result['result_file'])): ?>
                                                <span style="color: #28a745; font-weight: 500;">
                                                    <i class="fas fa-check-circle" style="margin-right: 0.5em;"></i>
                                                    Uploaded
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #ffc107; font-style: italic;">
                                                    <i class="fas fa-clock" style="margin-right: 0.5em;"></i>
                                                    Pending
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="../laboratory/laboratory.php" 
                                               style="
                                                   background: linear-gradient(135deg, #007bff, #0056b3);
                                                   color: white;
                                                   border: none;
                                                   padding: 6px 12px;
                                                   border-radius: 6px;
                                                   font-size: 0.8em;
                                                   cursor: pointer;
                                                   text-decoration: none;
                                                   display: inline-flex;
                                                   align-items: center;
                                                   gap: 4px;
                                                   transition: all 0.3s ease;
                                               "
                                               onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 8px rgba(0, 123, 255, 0.3)';"
                                               onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';"
                                            >
                                                <i class="fas fa-eye"></i>
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 2em; color: #666;">
                        <i class="fas fa-vials" style="font-size: 2em; margin-bottom: 0.5em; opacity: 0.5;"></i>
                        <h4>No Lab Results Available</h4>
                        <p>You don't have any lab test results yet. Lab tests may be ordered during consultations or checkups.</p>
                        <div style="margin-top: 1em;">
                            <a href="../laboratory/laboratory.php" class="view-more-btn">
                                <i class="fas fa-flask"></i> View Lab Orders
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Enhanced Medical History Section -->
            <div class="summary-card enhanced-card medical-history-section">
                <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 1.5em; flex-wrap: wrap; gap: 0.5em;">
                    <h2><i class="fas fa-notes-medical"></i> Medical History</h2>
                    <?php if (!in_array($view_mode, ['admin', 'doctor', 'nurse', 'records_officer', 'dho', 'bhw', 'cashier', 'laboratory_tech', 'pharmacist'])): ?>
                        <a href="medical_history_edit.php" style="
                                background: linear-gradient(135deg, #007bff, #0056b3);
                                color: white;
                                padding: 0.8rem 2rem;
                                border-radius: 25px;
                                text-decoration: none;
                                font-weight: 600;
                                display: inline-flex;
                                align-items: center;
                                gap: 0.6rem;
                                transition: all 0.3s ease;
                                font-size: 0.95rem;
                                box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
                                text-transform: uppercase;
                                letter-spacing: 0.5px;
                            " onmouseover="
                                this.style.transform='translateY(-2px)'; 
                                this.style.boxShadow='0 8px 25px rgba(0, 123, 255, 0.4)';
                            " onmouseout="
                                this.style.transform='translateY(0)'; 
                                this.style.boxShadow='0 4px 15px rgba(0, 123, 255, 0.3)';
                            ">
                                <i class="fas fa-edit"></i>
                            Edit Medical History
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Medical History Grid -->
                <div class="medical-grid">
                    <!-- Past Medical Conditions-->
                    <div class="medical-card enhanced-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1em;">
                            <h4><i class="fas fa-history"></i> Past Medical Conditions</h4>
                            <span class="status-badge"><?= count($medical_history['past_conditions']) ?> records</span>
                        </div>
                        <div class="scroll-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Condition</th>
                                        <th>Year Diagnosed</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($medical_history['past_conditions'])): ?>
                                        <?php foreach (array_slice($medical_history['past_conditions'], 0, 5) as $condition): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($condition['condition'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($condition['year_diagnosed'] ?? '') ?></td>
                                                <td>
                                                    <span class="status-badge status-<?= strtolower($condition['status'] ?? 'resolved') ?>">
                                                        <?= htmlspecialchars($condition['status'] ?? 'Resolved') ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon">
                                                        <i class="fas fa-history"></i>
                                                    </div>
                                                    <h4>No Past Medical Conditions Recorded</h4>
                                                    <?php if ($view_mode === 'admin'): ?>
                                                        <p>Patient has not recorded any past medical conditions.</p>
                                                    <?php else: ?>
                                                        <p>Adding your medical history helps healthcare providers understand your health better and provide more accurate care.</p>
                                                        <a href="medical_history_edit.php#past-conditions" class="completion-prompt">
                                                            <i class="fas fa-plus"></i> Add Medical History
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Chronic Illnesses -->
                    <div class="medical-card enhanced-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1em;">
                            <h4><i class="fas fa-exclamation-triangle"></i> Chronic Illnesses</h4>
                            <span class="status-badge"><?= count($medical_history['chronic_illnesses']) ?> records</span>
                        </div>
                        <div class="scroll-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Illness</th>
                                        <th>Year Diagnosed</th>
                                        <th>Management</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($medical_history['chronic_illnesses'])): ?>
                                        <?php foreach (array_slice($medical_history['chronic_illnesses'], 0, 5) as $illness): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($illness['illness'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($illness['year_diagnosed'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($illness['management'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon">
                                                        <i class="fas fa-exclamation-triangle"></i>
                                                    </div>
                                                    <h4>No Chronic Illnesses Recorded</h4>
                                                    <?php if ($view_mode === 'admin'): ?>
                                                        <p>Patient has not recorded any chronic illnesses.</p>
                                                    <?php else: ?>
                                                        <p>If you have any ongoing health conditions, please add them to help your healthcare team provide better care.</p>
                                                        <a href="medical_history_edit.php#chronic-illnesses" class="completion-prompt">
                                                            <i class="fas fa-plus"></i> Add Conditions
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Allergies -->
                    <div class="medical-card enhanced-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1em;">
                            <h4><i class="fas fa-allergies"></i> Allergies</h4>
                            <span class="status-badge"><?= count($medical_history['allergies']) ?> records</span>
                        </div>
                        <div class="scroll-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Allergen</th>
                                        <th>Reaction</th>
                                        <th>Severity</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($medical_history['allergies'])): ?>
                                        <?php foreach (array_slice($medical_history['allergies'], 0, 5) as $allergy): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($allergy['allergen'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($allergy['reaction'] ?? '') ?></td>
                                                <td>
                                                    <?php
                                                    $severity = strtolower($allergy['severity'] ?? 'mild');
                                                    $severityClass = 'alert-info';
                                                    if ($severity === 'moderate') $severityClass = 'alert-warning';
                                                    if ($severity === 'severe' || $severity === 'critical') $severityClass = 'alert-critical';
                                                    ?>
                                                    <span class="alert-badge <?= $severityClass ?>">
                                                        <?= htmlspecialchars(ucfirst($allergy['severity'] ?? 'Mild')) ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon">
                                                        <i class="fas fa-allergies"></i>
                                                    </div>
                                                    <h4>No Allergies Recorded</h4>
                                                    <?php if ($view_mode === 'admin'): ?>
                                                        <p><strong>Note:</strong> Patient has not recorded any allergies.</p>
                                                    <?php else: ?>
                                                        <p><strong>Important:</strong> Recording your allergies helps prevent dangerous medication reactions and ensures safe treatment.</p>
                                                        <a href="medical_history_edit.php#allergies" class="completion-prompt">
                                                            <i class="fas fa-plus"></i> Add Allergies
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Current Medications -->
                    <div class="medical-card enhanced-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1em;">
                            <h4><i class="fas fa-pills"></i> Current Medications</h4>
                            <span class="status-badge"><?= count($medical_history['current_medications']) ?> records</span>
                        </div>
                        <div class="scroll-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Medication</th>
                                        <th>Dosage</th>
                                        <th>Frequency</th>
                                        <th>Prescribed By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($medical_history['current_medications'])): ?>
                                        <?php foreach (array_slice($medical_history['current_medications'], 0, 5) as $med): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($med['medication'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($med['dosage'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($med['frequency'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($med['prescribed_by'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon">
                                                        <i class="fas fa-pills"></i>
                                                    </div>
                                                    <h4>No Current Medications</h4>
                                                    <?php if ($view_mode === 'admin'): ?>
                                                        <p>Patient has not recorded any current medications.</p>
                                                    <?php else: ?>
                                                        <p>Keep track of all medications you're currently taking to avoid harmful interactions and ensure coordinated care.</p>
                                                        <a href="medical_history_edit.php#current-medications" class="completion-prompt">
                                                            <i class="fas fa-plus"></i> Add Medications
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Family History -->
                    <div class="medical-card enhanced-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1em;">
                            <h4><i class="fas fa-users"></i> Family History</h4>
                            <span class="status-badge"><?= count($medical_history['family_history']) ?> records</span>
                        </div>
                        <div class="scroll-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Family Member</th>
                                        <th>Condition</th>
                                        <th>Age Diagnosed</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($medical_history['family_history'])): ?>
                                        <?php foreach (array_slice($medical_history['family_history'], 0, 5) as $fh): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($fh['family_member'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($fh['condition'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($fh['age_diagnosed'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($fh['current_status'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon">
                                                        <i class="fas fa-users"></i>
                                                    </div>
                                                    <h4>No Family History Recorded</h4>
                                                    <?php if ($view_mode === 'admin'): ?>
                                                        <p>Patient has not recorded any family medical history.</p>
                                                    <?php else: ?>
                                                        <p>Family medical history helps identify hereditary conditions and assess your risk for certain diseases.</p>
                                                        <a href="medical_history_edit.php#family-history" class="completion-prompt">
                                                            <i class="fas fa-plus"></i> Add Family History
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Surgical History -->
                    <div class="medical-card enhanced-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1em;">
                            <h4><i class="fas fa-cut"></i> Surgical History</h4>
                            <span class="status-badge"><?= count($medical_history['surgical_history']) ?> records</span>
                        </div>
                        <div class="scroll-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Surgery</th>
                                        <th>Year</th>
                                        <th>Hospital</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($medical_history['surgical_history'])): ?>
                                        <?php foreach (array_slice($medical_history['surgical_history'], 0, 5) as $surgery): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($surgery['surgery'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($surgery['year'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($surgery['hospital'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon">
                                                        <i class="fas fa-cut"></i>
                                                    </div>
                                                    <h4>No Surgical History</h4>
                                                    <?php if ($view_mode === 'admin'): ?>
                                                        <p>Patient has not recorded any surgical history.</p>
                                                    <?php else: ?>
                                                        <p>Record any surgeries or procedures you've had to help healthcare providers understand your medical background.</p>
                                                        <a href="medical_history_edit.php#surgical-history" class="completion-prompt">
                                                            <i class="fas fa-plus"></i> Add Surgery History
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Immunizations -->
                    <div class="medical-card enhanced-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1em;">
                            <h4><i class="fas fa-syringe"></i> Immunizations</h4>
                            <span class="status-badge"><?= count($medical_history['immunizations']) ?> records</span>
                        </div>
                        <div class="scroll-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Vaccine</th>
                                        <th>Year Received</th>
                                        <th>Doses</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($medical_history['immunizations'])): ?>
                                        <?php foreach (array_slice($medical_history['immunizations'], 0, 5) as $imm): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($imm['vaccine'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($imm['year_received'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($imm['doses_completed'] ?? '') ?></td>
                                                <td>
                                                    <span class="status-badge status-<?= strtolower($imm['status'] ?? 'completed') ?>">
                                                        <?= htmlspecialchars($imm['status'] ?? 'Completed') ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4">
                                                <div class="empty-state">
                                                    <div class="empty-state-icon">
                                                        <i class="fas fa-syringe"></i>
                                                    </div>
                                                    <h4>No Immunization Records</h4>
                                                    <?php if ($view_mode === 'admin'): ?>
                                                        <p>Patient has not recorded any immunizations.</p>
                                                    <?php else: ?>
                                                        <p>Keep track of your vaccinations to stay protected and meet healthcare requirements for travel or work.</p>
                                                        <a href="medical_history_edit.php#immunizations" class="completion-prompt">
                                                            <i class="fas fa-plus"></i> Add Vaccinations
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </section>
    <script>
        // Enhanced utility functions
        function downloadPatientFile() {
            // Show loading spinner
            const btn = event.target.closest('.utility-btn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<div class="loading-spinner"></div> <span>Generating...</span>';
            btn.disabled = true;

            // Simulate file generation
            setTimeout(() => {
                // TODO: Implement actual file download logic
                alert('Patient medical record file downloaded successfully!');
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }, 2000);
        }

        function printPatientFile() {
            // Show loading spinner
            const btn = event.target.closest('.utility-btn');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<div class="loading-spinner"></div> <span>Preparing...</span>';
            btn.disabled = true;

            // Redirect to print version of medical record
            setTimeout(() => {
                window.open('medical_record_print.php?patient_id=<?= (int)$patient_id ?>', '_blank');
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }, 1000);
        }

        function downloadPatientID() {
            // Redirect to ID card generation page
            window.open('id_card.php', '_blank');
        }

        function openUserSettings() {
            // Create and show user settings modal
            showUserSettingsModal();
        }

        function showUserSettingsModal() {
            const modal = document.createElement('div');
            modal.className = 'custom-modal active';
            modal.innerHTML = `
                <div class="modal-content">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5em;">
                        <h3><i class="fas fa-cog"></i> User Settings</h3>
                        <button onclick="closeModal(this)" style="background: none; border: none; font-size: 1.5em; cursor: pointer;">&times;</button>
                    </div>
                    <div style="display: grid; gap: 1em;">
                        <button onclick="changePassword()" class="utility-btn" style="width: 100%; justify-content: center;">
                            <i class="fas fa-key"></i> Change Password
                        </button>
                        <button onclick="updateProfile()" class="utility-btn" style="width: 100%; justify-content: center;">
                            <i class="fas fa-user-edit"></i> Update Profile
                        </button>
                        <button onclick="manageNotifications()" class="utility-btn" style="width: 100%; justify-content: center;">
                            <i class="fas fa-bell"></i> Notification Settings
                        </button>
                        <button onclick="viewActivityLog()" class="utility-btn" style="width: 100%; justify-content: center;">
                            <i class="fas fa-history"></i> Activity Log
                        </button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        function closeModal(element) {
            const modal = element.closest('.custom-modal');
            modal.remove();
        }

        function changePassword() {
            alert('Change password functionality coming soon.');
        }

        function updateProfile() {
            window.location.href = 'profile_edit.php';
        }

        function manageNotifications() {
            alert('Notification settings coming soon.');
        }

        function viewActivityLog() {
            alert('Activity log functionality coming soon.');
        }

        // Enhanced logout functionality
        document.addEventListener('DOMContentLoaded', function() {
            var logoutBtn = document.getElementById('logoutBtn');
            var logoutModal = document.getElementById('logoutModal');
            var logoutConfirm = document.getElementById('logoutConfirm');
            var logoutCancel = document.getElementById('logoutCancel');

            if (logoutBtn && logoutModal) {
                logoutBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    logoutModal.style.display = 'flex';
                });
            }

            if (logoutCancel) {
                logoutCancel.addEventListener('click', function() {
                    logoutModal.style.display = 'none';
                });
            }

            if (logoutConfirm) {
                logoutConfirm.addEventListener('click', function() {
                    window.location.href = '?logout=1';
                });
            }

            // Close modal on outside click
            if (logoutModal) {
                logoutModal.addEventListener('click', function(e) {
                    if (e.target === logoutModal) logoutModal.style.display = 'none';
                });
            }

            // Auto-refresh vitals if they're older than 24 hours
            const vitalsSection = document.querySelector('.vitals-section');
            if (vitalsSection) {
                const lastUpdate = vitalsSection.querySelector('small i').textContent;
                if (lastUpdate.includes('No vitals recorded')) {
                    // Add notification for missing vitals
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert-badge alert-info';
                    alertDiv.innerHTML = '<i class="fas fa-info-circle"></i> Schedule a checkup to update your vital signs';
                    vitalsSection.querySelector('.section-header').appendChild(alertDiv);
                }
            }

            // Add smooth scroll to tables
            const tables = document.querySelectorAll('.scroll-table');
            tables.forEach(table => {
                table.addEventListener('wheel', function(e) {
                    if (e.deltaY !== 0) {
                        e.preventDefault();
                        this.scrollTop += e.deltaY;
                    }
                });
            });
        });

        // Utility functions for modal management
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            if (typeof id === 'string') {
                document.getElementById(id).classList.remove('active');
            } else {
                // If called from button click, id is the element
                const modal = id.closest('.custom-modal');
                modal.remove();
            }
        }

        // Add keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const activeModal = document.querySelector('.custom-modal.active');
                if (activeModal) {
                    activeModal.classList.remove('active');
                }
            }
        });

        // Lab Results View Function
        function viewLabResult(labOrderItemId) {
            // Show loading state
            const button = event.target.closest('button');
            const originalText = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
            button.disabled = true;

            // Create and show modal with lab result details
            fetch(`../api/get_lab_result_details.php?lab_order_item_id=${labOrderItemId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showLabResultModal(data.result);
                    } else {
                        showAlert('Error loading lab result: ' + (data.message || 'Unknown error'), 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('Failed to load lab result details. Please try again.', 'error');
                })
                .finally(() => {
                    // Restore button state
                    button.innerHTML = originalText;
                    button.disabled = false;
                });
        }

        function showLabResultModal(result) {
            const modal = document.createElement('div');
            modal.className = 'custom-modal active';
            modal.innerHTML = `
                <div class="modal-content" style="max-width: 600px; max-height: 80vh; overflow-y: auto;">
                    <div class="modal-header">
                        <h3><i class="fas fa-flask"></i> Lab Result Details</h3>
                        <button class="modal-close" onclick="closeModal(this)">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="lab-result-details">
                            <div class="result-header" style="background: #f8f9fa; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                                <h4 style="margin: 0; color: #495057;">${result.test_name || 'Lab Test'}</h4>
                                <p style="margin: 0.5rem 0 0 0; color: #6c757d;">${result.test_type || ''}</p>
                                <small style="color: #6c757d;">Sample: ${result.sample_type || 'Not specified'}</small>
                            </div>
                            
                            <div class="result-info" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                                <div>
                                    <strong>Result Value:</strong><br>
                                    <span class="data-highlight" style="font-size: 1.2em;">${result.result_value || 'Pending'}</span>
                                    ${result.result_unit ? ' ' + result.result_unit : ''}
                                </div>
                                <div>
                                    <strong>Normal Range:</strong><br>
                                    ${result.normal_range || 'Not specified'}
                                </div>
                                <div>
                                    <strong>Result Date:</strong><br>
                                    ${result.result_date ? new Date(result.result_date).toLocaleDateString('en-US', {
                                        year: 'numeric',
                                        month: 'long',
                                        day: 'numeric',
                                        hour: '2-digit',
                                        minute: '2-digit'
                                    }) : 'Not available'}
                                </div>
                                <div>
                                    <strong>Status:</strong><br>
                                    <span class="alert-badge ${getStatusClass(result.result_status)}">
                                        ${result.result_status ? result.result_status.charAt(0).toUpperCase() + result.result_status.slice(1) : 'Pending'}
                                    </span>
                                </div>
                            </div>
                            
                            ${result.remarks ? `
                                <div class="result-remarks" style="background: #fff3cd; padding: 1rem; border-radius: 8px; border-left: 4px solid #ffc107;">
                                    <strong>Remarks:</strong><br>
                                    ${result.remarks}
                                </div>
                            ` : ''}
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn-secondary" onclick="closeModal(this)">Close</button>
                        <button class="btn-primary" onclick="printLabResult(${result.lab_order_item_id})">
                            <i class="fas fa-print"></i> Print Result
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
        }

        function getStatusClass(status) {
            if (!status) return 'alert-info';
            const statusLower = status.toLowerCase();
            if (statusLower.includes('normal')) return 'alert-info';
            if (statusLower.includes('abnormal') || statusLower.includes('high') || statusLower.includes('low')) return 'alert-warning';
            if (statusLower.includes('critical') || statusLower.includes('urgent')) return 'alert-critical';
            return 'alert-info';
        }

        function printLabResult(labOrderItemId) {
            // Open print view in new window/tab
            window.open(`../laboratory/print_lab_result.php?lab_order_item_id=${labOrderItemId}`, '_blank');
        }

        function showAlert(message, type = 'info') {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type}`;
            alert.innerHTML = `
                <i class="fas fa-${type === 'error' ? 'exclamation-triangle' : 'info-circle'}"></i>
                ${message}
                <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
            `;
            alert.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 10000;
                max-width: 400px;
                padding: 1rem;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.2);
                background: ${type === 'error' ? '#f8d7da' : '#d1ecf1'};
                color: ${type === 'error' ? '#721c24' : '#0c5460'};
                border: 1px solid ${type === 'error' ? '#f5c6cb' : '#bee5eb'};
            `;
            
            document.body.appendChild(alert);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 5000);
        }
    </script>
</body>

</html>