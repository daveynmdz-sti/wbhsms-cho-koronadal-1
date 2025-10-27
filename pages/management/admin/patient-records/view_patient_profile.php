<?php
// view_patient_profile.php - Admin-only Patient Profile View
ob_start(); // Start output buffering to prevent any accidental output

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
    error_log("Admin Patient Profile View Error [$severity]: $message in $file on line $line");
    return true; // Don't execute PHP's internal error handler
});

// Set exception handler for uncaught exceptions
set_exception_handler(function($exception) {
    error_log("Admin Patient Profile View Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    
    // Clean any output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('HTTP/1.1 500 Internal Server Error');
    die('An unexpected error occurred. Please try again later.');
});

// Path setup
$root_path = realpath(dirname(dirname(dirname(dirname(__DIR__)))));

// Load configuration first
require_once $root_path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'env.php';

// Use employee session for admin users
require_once $root_path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'session' . DIRECTORY_SEPARATOR . 'employee_session.php';

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once $root_path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'db.php';

// Get patient ID from URL parameter
$patient_id = $_GET['patient_id'] ?? null;

// Validate patient ID
if (!$patient_id) {
    error_log("Admin patient profile view error: No patient ID provided");
    header('HTTP/1.1 400 Bad Request');
    die('Error: Patient ID is required');
}

// Sanitize patient ID (should be numeric)
if (!is_numeric($patient_id) || $patient_id <= 0) {
    error_log("Admin patient profile view error: Invalid patient ID format: " . $patient_id);
    header('HTTP/1.1 400 Bad Request');
    die('Error: Invalid patient ID');
}

$patient_id = (int) $patient_id;

// Determine the correct back URL based on referrer or URL parameter
$back_url = 'patient_records_management.php'; // Default back URL

// Check if back_url is provided as URL parameter
if (isset($_GET['back_url'])) {
    $back_url_param = $_GET['back_url'];
    // Validate that it's one of our allowed back URLs for security
    if (in_array($back_url_param, ['patient_records_management.php', 'archived_records_management.php'])) {
        $back_url = $back_url_param;
    }
} else {
    // Check HTTP referrer to determine where user came from
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    if (!empty($referrer)) {
        // Extract the filename from the referrer URL
        $referrer_path = parse_url($referrer, PHP_URL_PATH);
        $referrer_filename = basename($referrer_path);
        
        // If user came from archived records, set back URL accordingly
        if ($referrer_filename === 'archived_records_management.php') {
            $back_url = 'archived_records_management.php';
        }
        // If user came from patient records management, keep default
        // No need to explicitly check since default is already patient_records_management.php
    }
}

// Verify admin is logged in - use session system
require_employee_login();

// Check if user has permission to view patient records
$authorized_roles = ['admin', 'doctor', 'nurse', 'records_officer', 'dho', 'bhw'];
require_employee_role($authorized_roles);

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
                $last_error = "Failed to prepare query #" . ($index + 1);
                continue;
            }
            
            $result = $stmt->execute([$patient_id]);
            if (!$result) {
                $last_error = "Failed to execute query #" . ($index + 1);
                continue;
            }
            
            $patient_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($patient_row) {
                break; // Found the patient data
            }
        } catch (PDOException $e) {
            $last_error = "Database error on query #" . ($index + 1) . ": " . $e->getMessage();
            error_log("Admin patient profile view query error: " . $last_error);
            continue;
        }
    }

    if (!$patient_row) {
        error_log("Admin patient profile view error: Patient ID $patient_id not found. Last error: " . ($last_error ?? 'No errors recorded'));
        header('HTTP/1.1 404 Not Found');
        die('Error: Patient record not found.');
    }

} catch (Exception $e) {
    error_log("Error fetching patient data for admin view: " . $e->getMessage());
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
    error_log("Error fetching personal information for patient $patient_id in admin view: " . $e->getMessage());
}

try {
    // Fetch emergency_contact
    $stmt = $pdo->prepare("SELECT * FROM emergency_contact WHERE patient_id = ?");
    if ($stmt && $stmt->execute([$patient_id])) {
        $emergency_contact = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
} catch (PDOException $e) {
    error_log("Error fetching emergency contact for patient $patient_id in admin view: " . $e->getMessage());
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
    error_log("Error fetching lifestyle info for patient $patient_id in admin view: " . $e->getMessage());
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

// Calculate profile completion percentage
$completion_score = 0;
$total_fields = 0;

// Basic information
$basic_fields = ['full_name', 'age', 'sex', 'dob', 'contact', 'email', 'barangay'];
$basic_completed = 0;
foreach ($basic_fields as $field) {
    $total_fields++;
    if (!empty($patient[$field])) {
        $completion_score++;
        $basic_completed++;
    }
}

// Personal details
$personal_fields = ['blood_type', 'civil_status', 'religion', 'occupation'];
$personal_completed = 0;
foreach ($personal_fields as $field) {
    $total_fields++;
    if (!empty($patient[$field])) {
        $completion_score++;
        $personal_completed++;
    }
}

// Emergency contact
$emergency_completed = 0;
$emergency_fields = ['name', 'relationship', 'contact'];
foreach ($emergency_fields as $field) {
    $total_fields++;
    if (!empty($patient['emergency'][$field])) {
        $completion_score++;
        $emergency_completed++;
    }
}

// Lifestyle info
$lifestyle_completed = 0;
$lifestyle_fields = ['smoking', 'alcohol', 'activity', 'diet'];
foreach ($lifestyle_fields as $field) {
    $total_fields++;
    if (!empty($patient['lifestyle'][$field])) {
        $completion_score++;
        $lifestyle_completed++;
    }
}

// Fetch latest vitals for this patient
$latest_vitals = null;
try {
    if (!empty($patient['patient_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM vitals WHERE patient_id = ? ORDER BY recorded_at DESC LIMIT 1");
        $stmt->execute([$patient['patient_id']]);
        $latest_vitals = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error fetching vitals in admin view: " . $e->getMessage());
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
    error_log("Error fetching appointments in admin view: " . $e->getMessage());
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
    error_log("Error fetching prescriptions in admin view: " . $e->getMessage());
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
} catch (Exception $e) {
    error_log("Error fetching lab results in admin view: " . $e->getMessage());
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
    error_log("Error fetching medical history in admin view: " . $e->getMessage());
}

// Calculate medical history completion
$medical_sections = ['allergies', 'current_medications', 'past_conditions', 'chronic_illnesses', 'immunizations', 'surgical_history', 'family_history'];
$medical_completed = 0;
foreach ($medical_sections as $section) {
    $total_fields++;
    $section_data = $medical_history[$section] ?? [];
    $is_completed = false;

    if (!empty($section_data) && is_array($section_data)) {
        foreach ($section_data as $record) {
            $has_na = false;
            $has_actual_data = false;

            foreach ($record as $field => $value) {
                if (!empty($value) && stripos($value, 'not applicable') !== false) {
                    $has_na = true;
                } elseif (!empty($value) && stripos($value, 'not applicable') === false) {
                    $has_actual_data = true;
                }
            }

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
$completion_message = 'Patient profile needs completion';
$completion_class = 'alert-warning';

if ($completion_percentage >= 90) {
    $completion_status = 'excellent';
    $completion_message = 'Patient profile is complete and up-to-date';
    $completion_class = 'alert-success';
} elseif ($completion_percentage >= 70) {
    $completion_status = 'good';
    $completion_message = 'Good profile completion - minor gaps remain';
    $completion_class = 'alert-info';
} elseif ($completion_percentage >= 50) {
    $completion_status = 'fair';
    $completion_message = 'Patient profile needs additional information';
    $completion_class = 'alert-warning';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Admin View - Patient Profile - <?= htmlspecialchars($patient['full_name']) ?> - WBHSMS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../../../assets/css/sidebar.css">
    <link rel="stylesheet" href="../../../../assets/css/patient_profile.css" />
    <style>
        .homepage{
            margin-left: 300px;
        }
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

        /* View More Button Styles */
        .view-more-btn {
            background: linear-gradient(135deg, #17a2b8, #20c997);
            color: white;
            border: none;
            padding: 0.6em 1.2em;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5em;
            font-size: 0.9em;
            transition: all 0.3s ease;
        }

        .view-more-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
            color: white;
            text-decoration: none;
        }

        /* Section Header Styles */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1em;
            flex-wrap: wrap;
            gap: 1em;
        }

        .section-header h2 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5em;
            font-size: 1.3em;
            color: #2c3e50;
        }

        /* Table Responsive Styles */
        .table-responsive {
            overflow-x: auto;
            margin: 0 -1em;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .summary-table th,
        .summary-table td {
            padding: 0.8em;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.9em;
        }

        .summary-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
            font-size: 0.85em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-table tbody tr:hover {
            background: #f8f9fa;
        }

        /* Medical Grid Layout */
        .medical-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 1.5em;
            margin-top: 1em;
        }

        .medical-card {
            background: #fff;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1.2em;
        }

        .medical-card h4 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5em;
            font-size: 1.1em;
            color: #495057;
        }

        .scroll-table {
            max-height: 300px;
            overflow-y: auto;
            margin-top: 1em;
        }

        .scroll-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .scroll-table th,
        .scroll-table td {
            padding: 0.6em;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.85em;
        }

        .scroll-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
            position: sticky;
            top: 0;
        }

        /* Vitals Grid */
        .vitals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1em;
            margin: 1em 0;
        }

        .vital-card {
            text-align: center;
            padding: 1em;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .vital-card i {
            font-size: 1.5em;
            margin-bottom: 0.5em;
            color: #007bff;
        }

        .vital-card .label {
            font-size: 0.75em;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Admin View Specific Styles - Hide editing elements */
        .edit-btn,
        .utility-btn[onclick*="edit"],
        .completion-prompt {
            display: none !important;
        }
    </style>
</head>

<body>
    <?php
    $activePage = 'patient_records';
    include $root_path . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'sidebar_admin.php';
    ?>

    <!-- Main Content -->
    <section class="homepage">
        <div class="profile-heading-bar"
            style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1em;margin-bottom:1.5em;">
            <h1 style="margin:0;font-size:2.2em;letter-spacing:1px;">
                <i class="fas fa-user-shield"></i> PATIENT PROFILE <span style="font-size: 0.65em; background: rgba(0,0,0,0.2); padding: 4px 10px; border-radius: 20px; vertical-align: middle;">ADMIN VIEW</span>
            </h1>
            <div class="utility-btn-group" style="display:flex;gap:0.7em;flex-wrap:wrap;">
                <!-- Admin view buttons -->
                <a href="<?= htmlspecialchars($back_url) ?>" class="utility-btn" title="Back to Patient Records"
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
            </div>
        </div>

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
                                    ? '../../../../vendor/photo_controller.php?patient_id=' . urlencode((string)$patient_id)
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
                    <div class="info-section">
                        <?php
                        $personal_field_labels = [
                            'age' => 'AGE',
                            'sex' => 'SEX', 
                            'dob' => 'DATE OF BIRTH',
                            'contact' => 'CONTACT NUMBER',
                            'email' => 'EMAIL',
                            'philhealth_type' => 'PHILHEALTH TYPE',
                            'philhealth_id_number' => 'PHILHEALTH ID NUMBER',
                            'is_pwd' => 'PWD STATUS',
                            'pwd_id_number' => 'PWD ID NUMBER',
                            'blood_type' => 'BLOOD TYPE',
                            'civil_status' => 'CIVIL STATUS',
                            'religion' => 'RELIGION',
                            'occupation' => 'OCCUPATION',
                            'address' => 'HOUSE NO. & STREET',
                            'barangay' => 'BARANGAY'
                        ];

                        foreach ($personal_field_labels as $field => $label):
                            $value = $patient[$field] ?? '';

                            if ($field === 'is_pwd') {
                                if (strtolower($value) === 'yes' || $value === '1' || strtolower($value) === 'true') {
                                    $value = '<span class="status-badge" style="background: #cce5ff; color: #004085; max-width: fit-content; word-wrap: normal;">PWD</span>';
                                } else {
                                    continue;
                                }
                            } elseif ($field === 'pwd_id_number') {
                                if (
                                    empty($patient['is_pwd']) ||
                                    !(strtolower($patient['is_pwd']) === 'yes' || $patient['is_pwd'] === '1' || strtolower($patient['is_pwd']) === 'true')
                                ) {
                                    continue;
                                }
                                if (empty($value)) {
                                    $value = '<span style="color: #6c757d; font-style: italic;">PWD ID not provided</span>';
                                }
                            }

                            $is_missing = false;
                            if ($field === 'is_pwd' || $field === 'pwd_id_number') {
                                $is_missing = false;
                            } else {
                                $original_value = $patient[$field] ?? '';
                                $is_missing = empty($original_value);
                            }
                        ?>
                            <div class="info-row <?= $is_missing ? 'incomplete' : '' ?>">
                                <span><?= $label ?>:</span>
                                <span>
                                    <?php if ($is_missing): ?>
                                        <span class="completion-badge badge-missing" style="max-width: fit-content; word-wrap: normal;">
                                            <i class="fas fa-exclamation-triangle"></i> Not provided
                                        </span>
                                    <?php else: ?>
                                        <?= $value ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
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

                        foreach ($emergency_fields as $field => $label):
                            $value = $patient['emergency'][$field] ?? '';
                            $is_missing = empty($value);
                        ?>
                            <div class="info-row <?= $is_missing ? 'incomplete' : '' ?>">
                                <span><?= $label ?>:</span>
                                <span>
                                    <?php if ($is_missing): ?>
                                        <span class="completion-badge badge-missing" style="max-width: fit-content; word-wrap: normal;">
                                            <i class="fas fa-exclamation-triangle"></i> Not provided
                                        </span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($value) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
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

                        foreach ($lifestyle_fields as $field => $label):
                            $value = $patient['lifestyle'][$field] ?? '';
                            $is_missing = empty($value);
                        ?>
                            <div class="info-row <?= $is_missing ? 'incomplete' : '' ?>">
                                <span><?= $label ?>:</span>
                                <span>
                                    <?php if ($is_missing): ?>
                                        <span class="completion-badge badge-missing" style="max-width: fit-content; word-wrap: normal;">
                                            <i class="fas fa-exclamation-triangle"></i> Not provided
                                        </span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($value) ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
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
                            <?php if (!empty($latest_vitals['oxygen_saturation'])): ?>
                                <div class="vital-card metric-card">
                                    <i class="fas fa-lungs"></i>
                                    <div>
                                        <span class="label">OXYGEN SATURATION</span><br>
                                        <strong class="data-highlight"><?= htmlspecialchars($latest_vitals['oxygen_saturation']) ?></strong> %
                                    </div>
                                </div>
                            <?php endif; ?>
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
                            <p><strong>Patient needs checkup</strong> to record vital signs. Regular monitoring helps track health status and detect any changes early.</p>
                            
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
                            <p>Patient doesn't have any upcoming or recent appointments.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Enhanced Prescriptions Section -->
                <div class="summary-card enhanced-card prescription-section">
                    <div class="section-header">
                        <h2><i class="fas fa-prescription-bottle-alt"></i> Recent Prescriptions</h2>
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
                                                        Available
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: #ffc107; font-style: italic;">
                                                        <i class="fas fa-clock" style="margin-right: 0.5em;"></i>
                                                        Pending
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button style="
                                                       background: linear-gradient(135deg, #007bff, #0056b3);
                                                       color: white;
                                                       border: none;
                                                       padding: 6px 12px;
                                                       border-radius: 6px;
                                                       font-size: 0.8em;
                                                       cursor: pointer;
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
                                                </button>
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
                            <p>Patient doesn't have any lab test results yet. Lab tests may be ordered during consultations or checkups.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Enhanced Medical History Section -->
                <div class="summary-card enhanced-card medical-history-section">
                    <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 1.5em; flex-wrap: wrap; gap: 0.5em;">
                        <h2><i class="fas fa-notes-medical"></i> Medical History</h2>
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
                                                        <p>Patient has not recorded any past medical conditions.</p>
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
                                                        <p>Patient has not recorded any chronic illnesses.</p>
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
                                                        <p><strong>Note:</strong> Patient has not recorded any allergies.</p>
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
                                                        <p>Patient has not recorded any current medications.</p>
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
                                                        <p>Patient has not recorded any family medical history.</p>
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
                                                        <p>Patient has not recorded any surgical history.</p>
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
                                                        <p>Patient has not recorded any immunizations.</p>
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
            alert('PDF download functionality would be implemented here');
        }

        function printPatientFile() {
            window.print();
        }

        // Enhanced print functionality for admin view
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide empty state messages when printing
            const printStyles = document.createElement('style');
            printStyles.innerHTML = `
                @media print {
                    .utility-btn-group { display: none !important; }
                    .profile-heading-bar { background: #667eea !important; }
                    .homepage { margin-left: 0 !important; padding: 1rem !important; }
                }
            `;
            document.head.appendChild(printStyles);
        });
    </script>
</body>

</html>