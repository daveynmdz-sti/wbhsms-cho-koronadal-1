<?php
// edit_referral.php - Edit Existing Referral with Pre-filled Form
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
// Use absolute path resolution
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Include referral permissions utility
require_once $root_path . '/utils/referral_permissions.php';

// Validate referral ID and edit permissions
if (!isset($_GET['referral_id']) || empty($_GET['referral_id'])) {
    header('Location: ../../pages/management/admin/referrals/referrals_management.php?error=' . urlencode('No referral ID provided'));
    exit();
}

$referral_id = intval($_GET['referral_id']);

// Get current employee info
$employee_id = get_employee_session('user_id');
$employee_role = get_employee_session('role');

// Check if employee can edit this referral
if (!canEmployeeEditReferral($conn, $employee_id, $referral_id, $employee_role)) {
    header('Location: ../../pages/management/admin/referrals/referrals_management.php?error=' . urlencode('Access denied. You can only edit referrals you created or have administrative privileges.'));
    exit();
}

// Load existing referral data with all related information
$stmt = $conn->prepare("
    SELECT r.*, 
           p.patient_id, p.full_name, p.date_of_birth, p.contact_number, p.address, p.emergency_contact, p.patient_id_manual,
           v.height, v.weight, v.temperature, v.blood_pressure, v.heart_rate, v.respiratory_rate, v.oxygen_saturation,
           r.external_facility_name as facility_name,
           s.service_name
    FROM referrals r
    JOIN patients p ON r.patient_id = p.patient_id
    LEFT JOIN vitals v ON r.vitals_id = v.vitals_id
    LEFT JOIN services s ON r.service_id = s.service_id
    WHERE r.referral_id = ?
");
$stmt->bind_param("i", $referral_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: ../../pages/management/admin/referrals/referrals_management.php?error=' . urlencode('Referral not found'));
    exit();
}

$referral_data = $result->fetch_assoc();
$stmt->close();

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed. Please contact administrator.");
}

// If user is not logged in, bounce to login
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    header('Location: ../management/auth/employee_login.php');
    exit();
}

// Check if role is authorized
$authorized_roles = ['doctor', 'bhw', 'dho', 'records_officer', 'admin'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    header('Location: ../management/' . strtolower($_SESSION['role']) . '/dashboard.php');
    exit();
}

$employee_id = $_SESSION['employee_id'];
// Include reusable topbar component
require_once $root_path . '/includes/topbar.php';

// Get employee's facility information
$employee_facility_id = null;
$employee_facility_name = '';
try {
    $stmt = $conn->prepare("
        SELECT e.facility_id, f.name as facility_name 
        FROM employees e 
        JOIN facilities f ON e.facility_id = f.facility_id 
        WHERE e.employee_id = ?
    ");
    $stmt->bind_param('i', $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $employee_facility_id = $row['facility_id'];
        $employee_facility_name = $row['facility_name'];
    }
} catch (Exception $e) {
    $error_message = "Unable to retrieve employee facility information.";
}

// Get jurisdiction restrictions for patient search (creation permissions)
// For creation, employees can only create referrals for patients in their jurisdiction
$jurisdiction_restriction = '';
$jurisdiction_params = [];
$jurisdiction_param_types = '';
$employee_role = strtolower($_SESSION['role']);

// Use permission utility to get creation restrictions (not hybrid viewing)
if ($employee_role === 'bhw') {
    $employee_barangay = getEmployeeBHWBarangay($conn, $employee_id);
    if ($employee_barangay) {
        $jurisdiction_restriction = " AND p.barangay_id = ?";
        $jurisdiction_params[] = $employee_barangay;
        $jurisdiction_param_types .= 'i';
        error_log("BHW create referrals: Employee ID $employee_id restricted to barangay $employee_barangay");
    } else {
        die('Error: Access denied - No barangay assignment found for BHW.');
    }
} elseif ($employee_role === 'dho') {
    $employee_district = getEmployeeDHODistrict($conn, $employee_id);
    if ($employee_district) {
        $jurisdiction_restriction = " AND b.district_id = ?";
        $jurisdiction_params[] = $employee_district;
        $jurisdiction_param_types .= 'i';
        error_log("DHO create referrals: Employee ID $employee_id restricted to district $employee_district");
    } else {
        die('Error: Access denied - No district assignment found for DHO.');
    }
}
// Admin, Doctor, Nurse, Records Officer can create for any patient

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_referral') {
        try {
            $conn->begin_transaction();

            // Get form data
            $patient_id = (int)($_POST['patient_id'] ?? 0);
            $update_referral_id = (int)($_POST['referral_id'] ?? 0);

            // Validate referral ID
            if (!$update_referral_id) {
                throw new Exception('Invalid referral ID.');
            }

            // Double-check edit permissions
            if (!canEmployeeEditReferral($conn, $employee_id, $update_referral_id, $employee_role)) {
                throw new Exception("Access denied. You can only edit referrals you created or have administrative privileges.");
            }

            $referral_reason = trim($_POST['referral_reason'] ?? '');
            $destination_type = trim($_POST['destination_type'] ?? ''); // barangay_center, district_office, city_office, external
            $referred_to_facility_id = !empty($_POST['referred_to_facility_id']) ? (int)$_POST['referred_to_facility_id'] : null;
            $external_facility_type = trim($_POST['external_facility_type'] ?? '');
            $external_facility_name = trim($_POST['external_facility_name'] ?? '');
            $hospital_name = trim($_POST['hospital_name'] ?? '');
            $other_facility_name = trim($_POST['other_facility_name'] ?? '');
            $service_id = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
            $custom_service_name = trim($_POST['custom_service_name'] ?? '');

            // Determine final external facility name based on type
            if ($destination_type === 'external') {
                if ($external_facility_type === 'hospital' && !empty($hospital_name)) {
                    $external_facility_name = $hospital_name;
                } elseif ($external_facility_type === 'other' && !empty($other_facility_name)) {
                    $external_facility_name = $other_facility_name;
                }
            }

            // Vitals data
            $systolic_bp = !empty($_POST['systolic_bp']) ? (int)$_POST['systolic_bp'] : null;
            $diastolic_bp = !empty($_POST['diastolic_bp']) ? (int)$_POST['diastolic_bp'] : null;
            $heart_rate = !empty($_POST['heart_rate']) ? (int)$_POST['heart_rate'] : null;
            $respiratory_rate = !empty($_POST['respiratory_rate']) ? (int)$_POST['respiratory_rate'] : null;
            $temperature = !empty($_POST['temperature']) ? (float)$_POST['temperature'] : null;
            $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
            $height = !empty($_POST['height']) ? (float)$_POST['height'] : null;
            $vitals_remarks = trim($_POST['vitals_remarks'] ?? '');

            // Calculate BMI if both weight and height are provided
            $bmi = null;
            if ($weight && $height) {
                $height_m = $height / 100; // Convert cm to meters
                $bmi = round($weight / ($height_m * $height_m), 2);
            }

            // Validation
            if (empty($referral_reason)) {
                throw new Exception('Referral reason is required.');
            }
            if (empty($destination_type)) {
                throw new Exception('Please select a referral destination type.');
            }

            // Validate based on destination type
            if (in_array($destination_type, ['district_office', 'city_office']) && !$referred_to_facility_id) {
                throw new Exception('Destination facility could not be determined. Please contact administrator.');
            }

            if ($destination_type === 'external') {
                if (empty($external_facility_type)) {
                    throw new Exception('Please select external facility type.');
                }
                if ($external_facility_type === 'hospital' && empty($hospital_name)) {
                    throw new Exception('Please select a hospital.');
                }
                if ($external_facility_type === 'other' && empty($other_facility_name)) {
                    throw new Exception('Please specify the other facility name.');
                }
                if ($external_facility_type === 'other' && strlen($other_facility_name) < 3) {
                    throw new Exception('Other facility name must be at least 3 characters.');
                }
            }

            // Validate custom service if "others" is selected
            if (isset($_POST['service_id']) && $_POST['service_id'] === 'others') {
                if (empty($custom_service_name)) {
                    throw new Exception('Please specify the other service name.');
                }
                if (strlen($custom_service_name) < 3) {
                    throw new Exception('Other service name must be at least 3 characters.');
                }
            }

            // Handle vitals - update existing or create new
            $vitals_id = $referral_data['vitals_id']; // Keep existing vitals_id
            if ($systolic_bp || $diastolic_bp || $heart_rate || $respiratory_rate || $temperature || $weight || $height) {
                if ($vitals_id) {
                    // Update existing vitals record
                    $stmt = $conn->prepare("
                        UPDATE vitals SET 
                            systolic_bp = ?, diastolic_bp = ?, heart_rate = ?, 
                            respiratory_rate = ?, temperature = ?, weight = ?, height = ?, bmi = ?, 
                            recorded_by = ?, remarks = ?, recorded_at = NOW()
                        WHERE vitals_id = ?
                    ");
                    $stmt->bind_param(
                        'iiiidddissi',
                        $systolic_bp,
                        $diastolic_bp,
                        $heart_rate,
                        $respiratory_rate,
                        $temperature,
                        $weight,
                        $height,
                        $bmi,
                        $employee_id,
                        $vitals_remarks,
                        $vitals_id
                    );
                    $stmt->execute();
                } else {
                    // Create new vitals record
                    $stmt = $conn->prepare("
                        INSERT INTO vitals (
                            patient_id, systolic_bp, diastolic_bp, heart_rate, 
                            respiratory_rate, temperature, weight, height, bmi, 
                            recorded_by, remarks
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param(
                        'iiiiddddiis',
                        $patient_id,
                        $systolic_bp,
                        $diastolic_bp,
                        $heart_rate,
                        $respiratory_rate,
                        $temperature,
                        $weight,
                        $height,
                        $bmi,
                        $employee_id,
                        $vitals_remarks
                    );
                    $stmt->execute();
                    $vitals_id = $conn->insert_id;
                }
            }

            // Handle custom service - if "others" is selected, store custom service name in referral_reason
            $final_service_id = $service_id;
            $final_referral_reason = $referral_reason;

            // If service_id is "others", append custom service to referral reason
            if (isset($_POST['service_id']) && $_POST['service_id'] === 'others' && !empty($custom_service_name)) {
                $final_service_id = null; // Set to null for "others"
                $final_referral_reason .= "\n\nRequested Service: " . $custom_service_name;
            }

            // Determine referral status based on destination
            $referral_status = 'active'; // Default status for internal referrals
            if ($referred_to_facility_id === null && !empty($external_facility_name)) {
                $referral_status = 'issued'; // Status for external referrals
            }

            // Update referral
            $stmt = $conn->prepare("
                UPDATE referrals SET 
                    referred_to_facility_id = ?, external_facility_name = ?, external_facility_type = ?,
                    vitals_id = ?, service_id = ?, referral_reason = ?, 
                    destination_type = ?, status = ?, last_updated = NOW()
                WHERE referral_id = ?
            ");
            $stmt->bind_param(
                'isssisssi',
                $referred_to_facility_id,
                $external_facility_name,
                $external_facility_type,
                $vitals_id,
                $final_service_id,
                $final_referral_reason,
                $destination_type,
                $referral_status,
                $update_referral_id
            );
            $stmt->execute();

            $conn->commit();
            $_SESSION['snackbar_message'] = "Referral updated successfully! Referral #: " . $referral_data['referral_num'];

            // Redirect to referrals management page after successful update
            header('Location: ../../pages/management/admin/referrals/referrals_management.php');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
}

// Get all services for dropdown
$all_services = [];
try {
    $stmt = $conn->prepare("SELECT service_id, name, description FROM services ORDER BY name");
    $stmt->execute();
    $result = $stmt->get_result();
    $all_services = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    // Ignore errors for services
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
    <title>Edit Referral | CHO Koronadal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="../../assets/css/topbar.css" />
    <link rel="stylesheet" href="../../assets/css/profile-edit-responsive.css" />
    <link rel="stylesheet" href="../../assets/css/profile-edit.css" />
    <link rel="stylesheet" href="../../assets/css/edit.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        .search-container {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
            margin-bottom: 1rem;
        }

        .patient-table {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .patient-table table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            min-width: 600px;
            /* Ensure table doesn't get too cramped */
        }

        .patient-table th,
        .patient-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .patient-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #0077b6;
        }

        .patient-table tbody tr:hover {
            background: #f8f9fa;
        }

        .patient-checkbox {
            width: 18px;
            height: 18px;
            margin-right: 0.5rem;
        }

        .referral-form {
            opacity: 0.5;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .referral-form.enabled {
            opacity: 1;
            pointer-events: auto;
        }

        .vitals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #0077b6;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
        }

        .selected-patient {
            background: #d4edda !important;
            border-left: 4px solid #28a745;
        }

        .empty-search {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
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

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #023e8a, #001d3d);
            transform: translateY(-2px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .conditional-field {
            display: none;
            margin-top: 1rem;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .conditional-field.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .auto-populated-field {
            background: #f8f9fa !important;
            border: 2px solid #e9ecef !important;
            cursor: not-allowed;
        }

        .facility-info-box {
            background: #e8f5e8;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #28a745;
            margin: 0.5rem 0;
        }

        .loading-spinner {
            display: inline-block;
            margin-right: 8px;
        }

        .validation-error {
            border: 2px solid #dc3545 !important;
            background: #fff5f5 !important;
        }

        .validation-success {
            border: 2px solid #28a745 !important;
            background: #f8fff8 !important;
        }

        .field-help-text {
            color: #666;
            font-size: 0.85em;
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f1b2b7;
        }

        /* Responsive Patient Table */
        .patient-table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        /* Patient Info Card for Edit Mode */
        .patient-info-card {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .patient-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .patient-detail {
            display: flex;
            flex-direction: column;
        }

        .patient-detail.full-width {
            grid-column: 1 / -1;
        }

        .patient-detail label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.25rem;
            font-size: 0.9rem;
        }

        .patient-detail span {
            color: #212529;
            font-size: 1rem;
        }

        @media (max-width: 768px) {
            .patient-details-grid {
                grid-template-columns: 1fr;
            }
            
            .patient-detail.full-width {
                grid-column: 1;
            }
        }

        .patient-card {
            display: none;
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .patient-card:hover {
            border-color: #0077b6;
            box-shadow: 0 2px 8px rgba(0, 119, 182, 0.1);
        }

        .patient-card.selected {
            border-color: #28a745;
            background: #f8fff8;
        }

        .patient-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            margin-top: 1.5rem;
        }

        .patient-card-name {
            font-weight: 600;
            color: #0077b6;
            font-size: 1.1em;
        }

        .patient-card-id {
            background: #f8f9fa;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.85em;
            color: #6c757d;
        }

        .patient-card-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            font-size: 0.9em;
        }

        .patient-card-detail {
            display: flex;
            flex-direction: column;
        }

        .patient-card-label {
            font-weight: 600;
            color: #6c757d;
            font-size: 0.8em;
            margin-bottom: 0.1rem;
        }

        .patient-card-value {
            color: #333;
        }

        .patient-card-checkbox {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 20px;
            height: 20px;
        }

        @media (max-width: 768px) {
            .search-grid {
                grid-template-columns: 1fr;
            }

            .vitals-grid {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            /* Hide table and show cards on mobile */
            .patient-table table {
                display: none;
            }

            .patient-card {
                display: block;
            }

            .patient-table-container {
                overflow-x: visible;
            }
        }

        @media (max-width: 480px) {
            .patient-card-details {
                grid-template-columns: 1fr;
            }

            .search-container {
                padding: 1rem;
            }

            .patient-table {
                padding: 1rem;
            }
        }

        /* Referral Confirmation Modal Styles */
        .referral-confirmation-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            z-index: 10000;
            animation: fadeIn 0.3s ease;
        }

        .referral-confirmation-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .referral-modal-content {
            background: white;
            border-radius: 20px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideInUp 0.4s ease;
            position: relative;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .referral-modal-header {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 2rem;
            border-radius: 20px 20px 0 0;
            text-align: center;
            position: relative;
            flex-shrink: 0;
        }

        .referral-modal-header h3 {
            margin: 0;
            font-size: 1.5em;
            font-weight: 600;
        }

        .referral-modal-header .icon {
            font-size: 3em;
            margin-bottom: 1rem;
            opacity: 0.9;
        }

        .referral-modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.2em;
            transition: background 0.3s;
        }

        .referral-modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .referral-modal-body {
            padding: 2rem;
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }

        /* Custom scrollbar for modal body */
        .referral-modal-body::-webkit-scrollbar {
            width: 6px;
        }

        .referral-modal-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .referral-modal-body::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        .referral-modal-body::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        .referral-summary-card {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .summary-section {
            margin-bottom: 1.5rem;
        }

        .summary-section:last-child {
            margin-bottom: 0;
        }

        .summary-title {
            font-weight: 700;
            color: #0077b6;
            font-size: 1.1em;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .summary-title i {
            background: #e3f2fd;
            padding: 0.5rem;
            border-radius: 8px;
            color: #0077b6;
            font-size: 0.9em;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .summary-item {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .summary-label {
            font-size: 0.85em;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-value {
            font-size: 1.05em;
            color: #333;
            font-weight: 500;
            word-wrap: break-word;
        }

        .summary-value.highlight {
            color: #0077b6;
            font-weight: 600;
        }

        .summary-value.reason {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #0077b6;
            margin-top: 0.5rem;
            line-height: 1.5;
        }

        .vitals-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.8rem;
            margin-top: 1rem;
        }

        .vital-item {
            background: white;
            padding: 0.8rem;
            border-radius: 8px;
            text-align: center;
            border: 2px solid #e9ecef;
        }

        .vital-value {
            font-size: 1.2em;
            font-weight: 700;
            color: #0077b6;
        }

        .vital-label {
            font-size: 0.8em;
            color: #6c757d;
            margin-top: 0.3rem;
        }

        .referral-modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding: 1.5rem 2rem 2rem;
            flex-shrink: 0;
            background: white;
            border-top: 1px solid #e9ecef;
        }

        .modal-btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1em;
            min-width: 120px;
        }

        .modal-btn-cancel {
            background: #f8f9fa;
            color: #6c757d;
            border: 2px solid #dee2e6;
        }

        .modal-btn-cancel:hover {
            background: #e9ecef;
            color: #5a6268;
        }

        .modal-btn-confirm {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .modal-btn-confirm:hover:not(:disabled) {
            background: linear-gradient(135deg, #218838, #1e7e34);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }

        .modal-btn-confirm:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideInUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @media (max-width: 768px) {
            .referral-modal-content {
                margin: 1rem;
                border-radius: 15px;
            }

            .referral-modal-header {
                padding: 1.5rem;
                border-radius: 15px 15px 0 0;
            }

            .referral-modal-header h3 {
                font-size: 1.3em;
            }

            .referral-modal-header .icon {
                font-size: 2.5em;
            }

            .referral-modal-body {
                padding: 1.5rem;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .vitals-summary {
                grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            }

            .referral-modal-actions {
                flex-direction: column;
                padding: 1rem 1.5rem 1.5rem;
            }

            .modal-btn {
                padding: 0.8rem 1.5rem;
            }
        }
    </style>
</head>

<body>
    <?php
    // Render snackbar notification system
    renderSnackbar();

    // Render topbar
    renderTopbar([
        'title' => 'Edit Referral',
        'back_url' => 'referrals_management.php',
        'user_type' => 'employee',
        'vendor_path' => '../../vendor/'
    ]);
    ?>

    <section class="homepage">
        <?php
        // Render back button with modal
        renderBackButton([
            'back_url' => 'referrals_management.php',
            'button_text' => '← Back / Cancel',
            'modal_title' => 'Cancel Creating Referral?',
            'modal_message' => 'Are you sure you want to go back/cancel? Unsaved changes will be lost.',
            'confirm_text' => 'Yes, Cancel',
            'stay_text' => 'Stay'
        ]);
        ?>

        <div class="profile-wrapper">
            <!-- Reminders Box -->
            <div class="reminders-box">
                <strong>Reminders:</strong>
                <ul>
                    <li>Search and select a patient from the list below before creating a referral.</li>
                    <li>You can search by patient ID, name, or barangay.</li>
                    <li>Patient vitals are optional but recommended for medical referrals.</li>
                    <li>All referral information should be accurate and complete.</li>
                    <li>Fields marked with * are required.</li>
                </ul>
            </div>

            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <!-- Patient Information (Read-Only) -->
            <div class="form-section">
                <div class="patient-info-card">
                    <h3><i class="fas fa-user"></i> Patient Information</h3>
                    <div class="patient-details-grid">
                        <div class="patient-detail">
                            <label>Patient ID:</label>
                            <span><?= htmlspecialchars($referral_data['patient_id_manual'] ?: $referral_data['patient_id']) ?></span>
                        </div>
                        <div class="patient-detail">
                            <label>Full Name:</label>
                            <span><?= htmlspecialchars($referral_data['full_name']) ?></span>
                        </div>
                        <div class="patient-detail">
                            <label>Date of Birth:</label>
                            <span><?= htmlspecialchars($referral_data['date_of_birth']) ?></span>
                        </div>
                        <div class="patient-detail">
                            <label>Contact Number:</label>
                            <span><?= htmlspecialchars($referral_data['contact_number'] ?: 'N/A') ?></span>
                        </div>
                        <div class="patient-detail full-width">
                            <label>Address:</label>
                            <span><?= htmlspecialchars($referral_data['address']) ?></span>
                        </div>
                        <div class="patient-detail full-width">
                            <label>Emergency Contact:</label>
                            <span><?= htmlspecialchars($referral_data['emergency_contact'] ?: 'N/A') ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Referral Form -->
            <div class="form-section">
                <form class="profile-card referral-form" id="referralForm" method="post">
                    <input type="hidden" name="action" value="update_referral">
                    <input type="hidden" name="referral_id" value="<?= $referral_id ?>">
                    <input type="hidden" name="patient_id" value="<?= $referral_data['patient_id'] ?>">
                    <input type="hidden" name="referred_to_facility_id" id="referredToFacilityId" value="<?= $referral_data['referred_to_facility_id'] ?>">

                    <h3><i class="fas fa-edit"></i> Edit Referral</h3>
                    <div class="facility-info" style="background: #e8f4fd; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid #0077b6;">
                        <p style="margin: 0; color: #0077b6; font-weight: 600;">
                            <i class="fas fa-hospital"></i> Referring From: <?= htmlspecialchars($employee_facility_name ?: 'Unknown Facility') ?>
                        </p>
                    </div>

                    <!-- Patient Vitals Section -->
                    <div class="form-section">
                        <h4><i class="fas fa-heartbeat"></i> Patient Vitals (Optional)</h4>
                        <div class="vitals-grid">
                            <div class="form-group">
                                <label for="systolic_bp">Systolic BP</label>
                                <input type="number" id="systolic_bp" name="systolic_bp" min="60" max="300" placeholder="120" 
                                       value="<?php 
                                       if ($referral_data['blood_pressure']) {
                                           $bp_parts = explode('/', $referral_data['blood_pressure']);
                                           echo htmlspecialchars($bp_parts[0] ?? '');
                                       }
                                       ?>">
                            </div>
                            <div class="form-group">
                                <label for="diastolic_bp">Diastolic BP</label>
                                <input type="number" id="diastolic_bp" name="diastolic_bp" min="40" max="200" placeholder="80"
                                       value="<?php 
                                       if ($referral_data['blood_pressure']) {
                                           $bp_parts = explode('/', $referral_data['blood_pressure']);
                                           echo htmlspecialchars($bp_parts[1] ?? '');
                                       }
                                       ?>">
                            </div>
                            <div class="form-group">
                                <label for="heart_rate">Heart Rate</label>
                                <input type="number" id="heart_rate" name="heart_rate" min="30" max="200" placeholder="72" 
                                       value="<?= htmlspecialchars($referral_data['heart_rate'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label for="respiratory_rate">Respiratory Rate</label>
                                <input type="number" id="respiratory_rate" name="respiratory_rate" min="8" max="60" placeholder="18"
                                       value="<?= htmlspecialchars($referral_data['respiratory_rate'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label for="temperature">Temperature (°C)</label>
                                <input type="number" id="temperature" name="temperature" step="0.1" min="30" max="45" placeholder="36.5"
                                       value="<?= htmlspecialchars($referral_data['temperature'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label for="weight">Weight (kg)</label>
                                <input type="number" id="weight" name="weight" step="0.1" min="1" max="500" placeholder="70.0"
                                       value="<?= htmlspecialchars($referral_data['weight'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label for="height">Height (cm)</label>
                                <input type="number" id="height" name="height" step="0.1" min="50" max="250" placeholder="170.0"
                                       value="<?= htmlspecialchars($referral_data['height'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="vitals_remarks">Vitals Remarks</label>
                            <textarea id="vitals_remarks" name="vitals_remarks" rows="3"
                                placeholder="Any additional notes about the patient's vitals..."><?= htmlspecialchars($referral_data['vitals_remarks'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Referral Information -->
                    <div class="form-section">
                        <h4><i class="fas fa-clipboard-list"></i> Referral Information</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="referral_reason">Reason for Referral *</label>
                                <textarea id="referral_reason" name="referral_reason" rows="4" required
                                    placeholder="Describe the medical condition, symptoms, and reason for referral..."><?= htmlspecialchars($referral_data['referral_reason'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <!-- Role-Based Destination Selection -->
                        <div class="form-row">
                            <div class="form-group">
                                <label for="destination_type">Referral Destination Type *</label>
                                <select id="destination_type" name="destination_type" required>
                                    <option value="">Select referral destination...</option>

                                    <?php
                                    $current_role = strtolower($_SESSION['role']);

                                    // BHW can refer to district, city, and external
                                    if ($current_role === 'bhw'): ?>
                                        <option value="district_office" <?= $referral_data['destination_type'] === 'district_office' ? 'selected' : '' ?>>District Health Office</option>
                                        <option value="city_office" <?= $referral_data['destination_type'] === 'city_office' ? 'selected' : '' ?>>City Health Office (Main District)</option>
                                        <option value="external" <?= $referral_data['destination_type'] === 'external' ? 'selected' : '' ?>>External Facility</option>

                                    <?php // DHO can refer to city and external
                                    elseif ($current_role === 'dho'): ?>
                                        <option value="city_office" <?= $referral_data['destination_type'] === 'city_office' ? 'selected' : '' ?>>City Health Office (Main District)</option>
                                        <option value="external" <?= $referral_data['destination_type'] === 'external' ? 'selected' : '' ?>>External Facility</option>

                                    <?php // Admin can refer to all destinations, others can refer to city office and external
                                    elseif ($current_role === 'admin'): ?>
                                        <option value="barangay_center" <?= $referral_data['destination_type'] === 'barangay_center' ? 'selected' : '' ?>>Barangay Health Center</option>
                                        <option value="district_office" <?= $referral_data['destination_type'] === 'district_office' ? 'selected' : '' ?>>District Health Office</option>
                                        <option value="city_office" <?= $referral_data['destination_type'] === 'city_office' ? 'selected' : '' ?>>City Health Office (Main District)</option>
                                        <option value="external" <?= $referral_data['destination_type'] === 'external' ? 'selected' : '' ?>>External Facility</option>

                                    <?php // Doctor, Nurse, Records Officer can refer to city office and external
                                    elseif (in_array($current_role, ['doctor', 'nurse', 'records_officer'])): ?>
                                        <option value="city_office" <?= $referral_data['destination_type'] === 'city_office' ? 'selected' : '' ?>>City Health Office (Main District)</option>
                                        <option value="external" <?= $referral_data['destination_type'] === 'external' ? 'selected' : '' ?>>External Facility</option>
                                    <?php else:
                                        // Fallback for any other roles - show district, city, and external only 
                                    ?>
                                        <option value="district_office" <?= $referral_data['destination_type'] === 'district_office' ? 'selected' : '' ?>>District Health Office</option>
                                        <option value="city_office" <?= $referral_data['destination_type'] === 'city_office' ? 'selected' : '' ?>>City Health Office (Main District)</option>
                                        <option value="external" <?= $referral_data['destination_type'] === 'external' ? 'selected' : '' ?>>External Facility</option>
                                    <?php endif; ?>
                                </select>

                                <?php if ($current_role === 'admin'): ?>
                                    <small style="color: #666; font-size: 0.85em;">
                                        <i class="fas fa-info-circle"></i> As Admin, you can create referrals to all facilities (Barangay Centers, District Offices, City Office, or External) for comprehensive patient follow-up care
                                    </small>
                                <?php elseif (in_array($current_role, ['doctor', 'nurse', 'records_officer'])): ?>
                                    <small style="color: #666; font-size: 0.85em;">
                                        <i class="fas fa-info-circle"></i> As <?= htmlspecialchars(ucfirst($_SESSION['role'])) ?> at City Health Office, you can refer within the facility or to external facilities
                                    </small>
                                <?php elseif ($current_role === 'dho'): ?>
                                    <small style="color: #666; font-size: 0.85em;">
                                        <i class="fas fa-info-circle"></i> As District Health Officer, you can refer to City Health Office or external facilities
                                    </small>
                                <?php elseif ($current_role === 'bhw'): ?>
                                    <small style="color: #666; font-size: 0.85em;">
                                        <i class="fas fa-info-circle"></i> As Barangay Health Worker, you can refer to District Office, City Health Office, or external facilities
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Auto-populated Barangay Health Center -->
                        <div class="form-row conditional-field" id="barangayFacilityField">
                            <div class="form-group">
                                <label for="barangay_facility_display">Facility Name</label>
                                <input type="text" id="barangay_facility_display" readonly
                                    placeholder="Will be auto-populated based on patient's barangay"
                                    style="background: #f8f9fa; border: 2px solid #e9ecef;">

                                <small style="color: #666; font-size: 0.85em;">
                                    <i class="fas fa-info-circle"></i> Auto-selected based on patient's barangay
                                </small>
                            </div>
                        </div>

                        <!-- Auto-populated District Office -->
                        <div class="form-row conditional-field" id="districtOfficeField">
                            <div class="form-group">
                                <label for="district_office_display">District Office</label>
                                <input type="text" id="district_office_display" readonly
                                    placeholder="Will be auto-populated based on patient's district"
                                    style="background: #f8f9fa; border: 2px solid #e9ecef;">

                                <small style="color: #666; font-size: 0.85em;">
                                    <i class="fas fa-info-circle"></i> Auto-selected based on patient's district
                                </small>
                            </div>
                        </div>

                        <!-- City Health Office (no additional fields needed) -->
                        <div class="form-row conditional-field" id="cityOfficeField">
                            <div class="form-group">

                                <div style="background: #e8f5e8; padding: 1rem; border-radius: 8px; border-left: 4px solid #28a745;">
                                    <i class="fas fa-hospital"></i> <strong>City Health Office (Main District)</strong>
                                    <br><small style="color: #666;">Direct referral to main city facility</small>
                                </div>
                            </div>
                        </div>

                        <!-- External Facility Selection -->
                        <div class="form-row conditional-field" id="externalFacilityTypeField">
                            <div class="form-group">
                                <label for="external_facility_type">External Facility Type *</label>
                                <select id="external_facility_type" name="external_facility_type">
                                    <option value="">Select external facility type...</option>
                                    <option value="hospital" <?= $referral_data['external_facility_type'] === 'hospital' ? 'selected' : '' ?>>Hospital</option>
                                    <option value="other" <?= $referral_data['external_facility_type'] === 'other' ? 'selected' : '' ?>>Other Facility</option>
                                </select>
                            </div>
                        </div>

                        <!-- Hospital Selection -->
                        <div class="form-row conditional-field" id="hospitalSelectionField">
                            <div class="form-group">
                                <label for="hospital_name">Hospital Name *</label>
                                <select id="hospital_name" name="hospital_name">
                                    <option value="">Select hospital...</option>
                                    <option value="South Cotabato Provincial Hospital (SCPH)" <?= ($referral_data['external_facility_type'] === 'hospital' && $referral_data['facility_name'] === 'South Cotabato Provincial Hospital (SCPH)') ? 'selected' : '' ?>>South Cotabato Provincial Hospital (SCPH)</option>
                                    <option value="Dr. Arturo P. Pingoy Medical Center" <?= ($referral_data['external_facility_type'] === 'hospital' && $referral_data['facility_name'] === 'Dr. Arturo P. Pingoy Medical Center') ? 'selected' : '' ?>>Dr. Arturo P. Pingoy Medical Center</option>
                                    <option value="Allah Valley Medical Specialists' Center, Inc." <?= ($referral_data['external_facility_type'] === 'hospital' && $referral_data['facility_name'] === "Allah Valley Medical Specialists' Center, Inc.") ? 'selected' : '' ?>>Allah Valley Medical Specialists' Center, Inc.</option>
                                    <option value="Socomedics Medical Center" <?= ($referral_data['external_facility_type'] === 'hospital' && $referral_data['facility_name'] === 'Socomedics Medical Center') ? 'selected' : '' ?>>Socomedics Medical Center</option>
                                </select>
                            </div>
                        </div>

                        <!-- Other Facility Input -->
                        <div class="form-row conditional-field" id="otherFacilityField">
                            <div class="form-group">
                                <label for="other_facility_name">Specify Other Facility *</label>
                                <input type="text" id="other_facility_name" name="other_facility_name"
                                    placeholder="Enter other facility name (minimum 3 characters)"
                                    minlength="3" 
                                    value="<?= ($referral_data['external_facility_type'] === 'other') ? htmlspecialchars($referral_data['facility_name'] ?? '') : '' ?>">
                                <small style="color: #666; font-size: 0.85em;">
                                    Please provide the full name of the facility
                                </small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="service_id">Service Type (Optional)</label>
                                <select id="service_id" name="service_id">
                                    <option value="">Select service (optional)...</option>
                                    <?php foreach ($all_services as $service): ?>
                                        <option value="<?= $service['service_id'] ?>" <?= $referral_data['service_id'] == $service['service_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($service['name']) ?>
                                            <?php if ($service['description']): ?>
                                                - <?= htmlspecialchars(substr($service['description'], 0, 50)) ?>...
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <!-- Others option - will be shown/hidden based on destination type -->
                                    <option value="others" id="othersServiceOption" style="display: none;" <?= $referral_data['service_id'] === 'others' ? 'selected' : '' ?>>Others (Specify below)</option>
                                </select>
                                <small style="color: #666; font-size: 0.85em;">
                                    <span id="serviceAvailabilityNote">Note: Service availability may vary by destination facility</span>
                                </small>
                            </div>
                        </div>

                        <!-- Custom Service Input (only shows when "Others" is selected) -->
                        <div class="form-row conditional-field" id="customServiceField">
                            <div class="form-group">
                                <label for="custom_service_name">Specify Other Service *</label>
                                <input type="text" id="custom_service_name" name="custom_service_name"
                                    placeholder="Enter the specific service name (minimum 3 characters)"
                                    minlength="3" 
                                    value="<?= htmlspecialchars($referral_data['custom_service_name'] ?? '') ?>">
                                <small style="color: #666; font-size: 0.85em;">
                                    Please provide the specific service or treatment needed
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" disabled>
                            <i class="fas fa-save"></i> Update Referral
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // Initialize for edit mode - show correct conditional fields based on existing data
            <?php if (isset($referral_data)): ?>
                // Set destination type and show appropriate fields
                const destinationType = '<?= $referral_data['destination_type'] ?>';
                if (destinationType) {
                    document.getElementById('destination_type').value = destinationType;
                    
                    // Show appropriate conditional fields
                    hideAllConditionalFields();
                    
                    if (destinationType === 'barangay_center') {
                        document.getElementById('barangayFacilityField').style.display = 'block';
                    } else if (destinationType === 'district_office') {
                        document.getElementById('districtOfficeField').style.display = 'block';
                    } else if (destinationType === 'city_office') {
                        document.getElementById('cityOfficeField').style.display = 'block';
                    } else if (destinationType === 'external') {
                        document.getElementById('externalFacilityTypeField').style.display = 'block';
                        
                        // Show hospital or other facility field based on external_facility_type
                        const externalType = '<?= $referral_data['external_facility_type'] ?>';
                        if (externalType === 'hospital') {
                            document.getElementById('hospitalSelectionField').style.display = 'block';
                        } else if (externalType === 'other') {
                            document.getElementById('otherFacilityField').style.display = 'block';
                        }
                    }
                    
                    // Filter services based on destination type
                    filterServicesByDestination(destinationType);
                    
                    // Show custom service field if "others" is selected
                    const serviceSelect = document.getElementById('service_id');
                    if (serviceSelect && serviceSelect.value === 'others') {
                        document.getElementById('customServiceField').style.display = 'block';
                    }
                }
                
                // Enable the submit button since this is edit mode with existing data
                document.querySelector('button[type="submit"]').disabled = false;
            <?php endif; ?>

            // Initialize service filtering on page load
            const destinationTypeSelect = document.getElementById('destination_type');
            if (destinationTypeSelect && destinationTypeSelect.value) {
                filterServicesByDestination(destinationTypeSelect.value);
            }

            // Mobile patient card selection function
            window.selectPatientCard = function(cardElement) {
                const checkbox = cardElement.querySelector('.patient-card-checkbox');
                if (checkbox && !checkbox.checked) {
                    // Uncheck all other checkboxes (both mobile and desktop)
                    document.querySelectorAll('.patient-checkbox').forEach(cb => cb.checked = false);

                    // Remove previous selections from both cards and rows
                    document.querySelectorAll('.patient-card').forEach(card => card.classList.remove('selected'));
                    document.querySelectorAll('.patient-row').forEach(row => row.classList.remove('selected-patient'));

                    // Select current card
                    checkbox.checked = true;
                    cardElement.classList.add('selected');

                    // Trigger the change event to handle form enabling
                    const event = new Event('change', {
                        bubbles: true
                    });
                    checkbox.dispatchEvent(event);
                }
            };

            // Form references for edit mode
            const referralForm = document.getElementById('referralForm');
            const submitBtn = referralForm.querySelector('button[type="submit"]');

            // Intelligent Destination Selection Logic
            const destinationTypeField = document.getElementById('destination_type');
            const barangayFacilityField = document.getElementById('barangayFacilityField');
            const districtOfficeField = document.getElementById('districtOfficeField');
            const cityOfficeField = document.getElementById('cityOfficeField');
            const externalFacilityTypeField = document.getElementById('externalFacilityTypeField');
            const hospitalSelectionField = document.getElementById('hospitalSelectionField');
            const otherFacilityField = document.getElementById('otherFacilityField');

            const externalFacilityType = document.getElementById('external_facility_type');
            const hospitalName = document.getElementById('hospital_name');
            const otherFacilityName = document.getElementById('other_facility_name');

            let currentPatientFacilities = null;

            // Hide all conditional fields initially
            function hideAllConditionalFields() {
                const fields = [barangayFacilityField, districtOfficeField, cityOfficeField,
                    externalFacilityTypeField, hospitalSelectionField, otherFacilityField
                ];
                fields.forEach(field => {
                    if (field) field.classList.remove('show');
                });

                // Clear required attributes
                if (externalFacilityType) externalFacilityType.required = false;
                if (hospitalName) hospitalName.required = false;
                if (otherFacilityName) otherFacilityName.required = false;
            }

            // Handle destination type change
            if (destinationType) {
                destinationTypeField.addEventListener('change', function() {
                    hideAllConditionalFields();

                    const patientId = document.querySelector('input[name="patient_id"]').value;

                    switch (this.value) {
                        case 'barangay_center':
                            if (patientId && currentPatientFacilities) {
                                populateBarangayFacility();
                            }
                            if (barangayFacilityField) barangayFacilityField.classList.add('show');
                            filterServicesByDestination('barangay_center');
                            break;

                        case 'district_office':
                            if (patientId && currentPatientFacilities) {
                                populateDistrictOffice();
                            }
                            if (districtOfficeField) districtOfficeField.classList.add('show');
                            filterServicesByDestination('district_office');
                            break;

                        case 'city_office':
                            // Set city office ID (facility_id = 1)
                            const referredToFacilityId = document.getElementById('referredToFacilityId');
                            if (referredToFacilityId) referredToFacilityId.value = '1';
                            if (cityOfficeField) cityOfficeField.classList.add('show');
                            filterServicesByDestination('city_office');
                            break;

                        case 'external':
                            // External facilities don't use referred_to_facility_id
                            const referredToFacilityIdExt = document.getElementById('referredToFacilityId');
                            if (referredToFacilityIdExt) referredToFacilityIdExt.value = '';
                            if (externalFacilityTypeField) {
                                externalFacilityTypeField.classList.add('show');
                                externalFacilityType.required = true;
                            }
                            filterServicesByDestination('external');
                            break;
                    }
                });
            }

            // Handle external facility type change
            if (externalFacilityType) {
                externalFacilityType.addEventListener('change', function() {
                    // Hide hospital and other facility fields first
                    if (hospitalSelectionField) hospitalSelectionField.classList.remove('show');
                    if (otherFacilityField) otherFacilityField.classList.remove('show');

                    if (hospitalName) hospitalName.required = false;
                    if (otherFacilityName) otherFacilityName.required = false;

                    if (this.value === 'hospital') {
                        if (hospitalSelectionField) {
                            hospitalSelectionField.classList.add('show');
                            hospitalName.required = true;
                        }
                    } else if (this.value === 'other') {
                        if (otherFacilityField) {
                            otherFacilityField.classList.add('show');
                            otherFacilityName.required = true;
                        }
                    }
                });
            }

            // Function to populate barangay facility
            function populateBarangayFacility() {
                const barangayDisplay = document.getElementById('barangay_facility_display');
                const referredToFacilityId = document.getElementById('referredToFacilityId');

                if (currentPatientFacilities && currentPatientFacilities.facilities.barangay_center) {
                    const facility = currentPatientFacilities.facilities.barangay_center;
                    if (barangayDisplay) barangayDisplay.value = facility.name;
                    if (referredToFacilityId) referredToFacilityId.value = facility.facility_id;
                } else {
                    if (barangayDisplay) barangayDisplay.value = 'No barangay health center found';
                    if (referredToFacilityId) referredToFacilityId.value = '';
                }
            }

            // Function to populate district office
            function populateDistrictOffice() {
                const districtDisplay = document.getElementById('district_office_display');
                const referredToFacilityId = document.getElementById('referredToFacilityId');

                if (currentPatientFacilities && currentPatientFacilities.facilities.district_office) {
                    const facility = currentPatientFacilities.facilities.district_office;
                    if (districtDisplay) districtDisplay.value = facility.name;
                    if (referredToFacilityId) referredToFacilityId.value = facility.facility_id;
                } else {
                    if (districtDisplay) districtDisplay.value = 'No district office found';
                    if (referredToFacilityId) referredToFacilityId.value = '';
                }
            }

            // Function to filter services based on destination type
            function filterServicesByDestination(destinationType) {
                const serviceSelect = document.getElementById('service_id');
                const othersOption = document.getElementById('othersServiceOption');
                const serviceNote = document.getElementById('serviceAvailabilityNote');
                const customServiceField = document.getElementById('customServiceField');

                if (!serviceSelect) return;

                // Get all service options (excluding the "others" option)
                const allOptions = Array.from(serviceSelect.options).filter(opt => opt.value !== 'others' && opt.value !== '');

                // Define primary care services (you may need to adjust these based on your services table)
                const primaryCareServices = [
                    'consultation', 'primary care', 'general consultation', 'basic health services',
                    'immunization', 'family planning', 'maternal health', 'child health',
                    'health education', 'preventive care', 'basic medical care'
                ];

                // Clear current selection if it will be filtered out
                const currentValue = serviceSelect.value;

                switch (destinationType) {
                    case 'district_office':
                        // District Office: Only primary care services
                        showPrimaryCareServicesOnly(serviceSelect, allOptions, primaryCareServices);
                        hideOthersOption(othersOption, customServiceField);
                        if (serviceNote) serviceNote.textContent = 'District Health Office: Primary care services only';
                        break;

                    case 'city_office':
                        // City Office: All services except "others"
                        showAllServices(serviceSelect, allOptions);
                        hideOthersOption(othersOption, customServiceField);
                        if (serviceNote) serviceNote.textContent = 'City Health Office: All services available';
                        break;

                    case 'external':
                        // External: All services including "others"
                        showAllServices(serviceSelect, allOptions);
                        showOthersOption(othersOption);
                        if (serviceNote) serviceNote.textContent = 'External Facility: All services available, including custom services';
                        break;

                    default:
                        // Default: Show all services except "others" (for internal facilities)
                        showAllServices(serviceSelect, allOptions);
                        hideOthersOption(othersOption, customServiceField);
                        if (serviceNote) serviceNote.textContent = 'Note: Service availability may vary by destination facility';
                        break;
                }

                // If current selection is no longer available, clear it
                if (currentValue && currentValue !== '' && !Array.from(serviceSelect.options).some(opt => opt.value === currentValue)) {
                    serviceSelect.value = '';
                }
            }

            // Helper functions for service filtering
            function showPrimaryCareServicesOnly(serviceSelect, allOptions, primaryCareServices) {
                // Hide all non-primary care options
                allOptions.forEach(option => {
                    const serviceName = option.textContent.toLowerCase();
                    const isPrimaryCare = primaryCareServices.some(pc => serviceName.includes(pc));
                    option.style.display = isPrimaryCare ? 'block' : 'none';
                });
            }

            function showAllServices(serviceSelect, allOptions) {
                // Show all service options
                allOptions.forEach(option => {
                    option.style.display = 'block';
                });
            }

            function showOthersOption(othersOption) {
                if (othersOption) {
                    othersOption.style.display = 'block';
                    othersOption.disabled = false;
                }
            }

            function hideOthersOption(othersOption, customServiceField) {
                if (othersOption) {
                    othersOption.style.display = 'none';
                    othersOption.disabled = true;
                }
                if (customServiceField) customServiceField.classList.remove('show');

                // Clear custom service input if it was selected
                const serviceSelect = document.getElementById('service_id');
                if (serviceSelect && serviceSelect.value === 'others') {
                    serviceSelect.value = '';
                }

                const customServiceInput = document.getElementById('custom_service_name');
                if (customServiceInput) {
                    customServiceInput.value = '';
                    customServiceInput.required = false;
                }
            }

            // Function to fetch patient facility information
            function fetchPatientFacilities(patientId) {
                if (!patientId) return;

                // Show loading indicator
                showLoadingIndicator();

                fetch(`get_patient_facilities.php?patient_id=${patientId}`)
                    .then(response => response.json())
                    .then(data => {
                        hideLoadingIndicator();

                        if (data.success) {
                            currentPatientFacilities = data;

                            // Update destination type selection based on current selection
                            const currentDestinationType = destinationTypeField.value;
                            if (currentDestinationType === 'barangay_center') {
                                populateBarangayFacility();
                            } else if (currentDestinationType === 'district_office') {
                                populateDistrictOffice();
                            }

                            // Add patient barangay info to selected patient display
                            const barangayInfo = document.createElement('small');
                            barangayInfo.style.display = 'block';
                            barangayInfo.style.color = '#666';
                            barangayInfo.innerHTML = `<i class="fas fa-map-marker-alt"></i> Barangay: ${data.patient.barangay_name}`;
                            selectedPatientInfo.appendChild(barangayInfo);

                        } else {
                            console.error('Error fetching patient facilities:', data.error);
                            alert('Error loading patient facility information: ' + data.error);
                        }
                    })
                    .catch(error => {
                        hideLoadingIndicator();
                        console.error('Network error:', error);
                        alert('Network error while loading patient information.');
                    });
            }

            // Loading indicator functions
            function showLoadingIndicator() {
                // Create or show loading indicator
                let loader = document.getElementById('facilityLoader');
                if (!loader) {
                    loader = document.createElement('div');
                    loader.id = 'facilityLoader';
                    loader.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading facility information...';
                    loader.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #0077b6; color: white; padding: 10px 15px; border-radius: 5px; z-index: 9999; font-size: 14px;';
                    document.body.appendChild(loader);
                }
                loader.style.display = 'block';
            }

            function hideLoadingIndicator() {
                const loader = document.getElementById('facilityLoader');
                if (loader) loader.style.display = 'none';
            }

            // Function to show referral confirmation modal
            function showReferralConfirmation() {
                return new Promise((resolve) => {
                    populateReferralModal();
                    showReferralModal();

                    // Store resolve function globally so modal buttons can access it
                    window.currentReferralResolve = resolve;
                });
            }

            // Function to populate modal with form data for edit mode
            function populateReferralModal() {
                // Patient information (from PHP data for edit mode)
                const patientName = '<?= htmlspecialchars($referral_data['full_name'] ?? '') ?>';
                const patientId = '<?= htmlspecialchars($referral_data['patient_id_manual'] ?: $referral_data['patient_id']) ?>';

                document.getElementById('modalPatientName').textContent = patientName || '-';
                document.getElementById('modalPatientId').textContent = patientId || '-';

                // Referral details
                const referringFrom = <?= json_encode($employee_facility_name ?: "Unknown Facility") ?>;
                document.getElementById('modalReferringFrom').textContent = referringFrom;

                // Destination
                const destinationType = document.getElementById('destination_type').value;
                let destinationName = '';

                switch (destinationType) {
                    case 'barangay_center':
                        const barangayDisplay = document.getElementById('barangay_facility_display');
                        destinationName = barangayDisplay ? barangayDisplay.value : 'Barangay Health Center';
                        break;
                    case 'district_office':
                        const districtDisplay = document.getElementById('district_office_display');
                        destinationName = districtDisplay ? districtDisplay.value : 'District Health Office';
                        break;
                    case 'city_office':
                        destinationName = 'City Health Office (Main District)';
                        break;
                    case 'external':
                        const externalType = document.getElementById('external_facility_type').value;
                        if (externalType === 'hospital') {
                            const hospitalSelect = document.getElementById('hospital_name');
                            destinationName = hospitalSelect ? hospitalSelect.value : 'External Hospital';
                        } else if (externalType === 'other') {
                            const otherFacility = document.getElementById('other_facility_name');
                            destinationName = otherFacility ? otherFacility.value : 'Other External Facility';
                        }
                        break;
                    default:
                        destinationName = 'Not specified';
                }

                document.getElementById('modalDestination').textContent = destinationName;

                // Service (if selected)
                const serviceSelect = document.getElementById('service_id');
                const serviceContainer = document.getElementById('modalServiceContainer');

                if (serviceSelect && serviceSelect.value) {
                    let serviceName = '';
                    if (serviceSelect.value === 'others') {
                        const customServiceName = document.getElementById('custom_service_name').value;
                        serviceName = customServiceName + ' (Custom Service)';
                    } else {
                        const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
                        serviceName = selectedOption.textContent;
                    }
                    document.getElementById('modalService').textContent = serviceName;
                    serviceContainer.style.display = 'block';
                } else {
                    serviceContainer.style.display = 'none';
                }

                // Referral reason
                const referralReason = document.getElementById('referral_reason').value;
                document.getElementById('modalReason').textContent = referralReason || 'No reason specified';

                // Vitals (if provided)
                populateVitalsModal();
            }

            // Function to populate vitals in modal
            function populateVitalsModal() {
                const vitalsCard = document.getElementById('modalVitalsCard');
                const vitalsGrid = document.getElementById('modalVitalsGrid');
                const vitalsRemarksContainer = document.getElementById('modalVitalsRemarksContainer');

                // Clear previous vitals
                vitalsGrid.innerHTML = '';

                const vitalsFields = [{
                        id: 'systolic_bp',
                        label: 'Systolic BP',
                        unit: 'mmHg'
                    },
                    {
                        id: 'diastolic_bp',
                        label: 'Diastolic BP',
                        unit: 'mmHg'
                    },
                    {
                        id: 'heart_rate',
                        label: 'Heart Rate',
                        unit: 'bpm'
                    },
                    {
                        id: 'respiratory_rate',
                        label: 'Respiratory Rate',
                        unit: '/min'
                    },
                    {
                        id: 'temperature',
                        label: 'Temperature',
                        unit: '°C'
                    },
                    {
                        id: 'weight',
                        label: 'Weight',
                        unit: 'kg'
                    },
                    {
                        id: 'height',
                        label: 'Height',
                        unit: 'cm'
                    }
                ];

                let hasVitals = false;

                vitalsFields.forEach(field => {
                    const input = document.getElementById(field.id);
                    if (input && input.value) {
                        hasVitals = true;
                        const vitalItem = document.createElement('div');
                        vitalItem.className = 'vital-item';
                        vitalItem.innerHTML = `
                            <div class="vital-value">${input.value}</div>
                            <div class="vital-label">${field.label} (${field.unit})</div>
                        `;
                        vitalsGrid.appendChild(vitalItem);
                    }
                });

                // Check vitals remarks
                const vitalsRemarks = document.getElementById('vitals_remarks');
                if (vitalsRemarks && vitalsRemarks.value.trim()) {
                    document.getElementById('modalVitalsRemarks').textContent = vitalsRemarks.value;
                    vitalsRemarksContainer.style.display = 'block';
                    hasVitals = true;
                } else {
                    vitalsRemarksContainer.style.display = 'none';
                }

                // Show/hide vitals card based on whether we have any vitals data
                vitalsCard.style.display = hasVitals ? 'block' : 'none';
            }

            // Function to show modal
            function showReferralModal() {
                const modal = document.getElementById('referralConfirmationModal');
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }

            // Function to close modal
            window.closeReferralModal = function() {
                const modal = document.getElementById('referralConfirmationModal');
                modal.classList.remove('show');
                document.body.style.overflow = '';

                // Reset confirm button state if needed
                const confirmBtn = document.getElementById('modalConfirmBtn');
                if (confirmBtn && confirmBtn.disabled) {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<i class="fas fa-save"></i> Update Referral';
                }

                // Resolve with false (user cancelled)
                if (window.currentReferralResolve) {
                    window.currentReferralResolve(false);
                    window.currentReferralResolve = null;
                }
            };

            // Modal confirm button handler (moved outside DOMContentLoaded to avoid nesting)
            function setupModalHandlers() {
                const confirmBtn = document.getElementById('modalConfirmBtn');
                console.log('Setting up modal handlers, confirmBtn:', confirmBtn);
                if (confirmBtn) {
                    confirmBtn.addEventListener('click', function() {
                        // Add loading state to button
                        const originalContent = confirmBtn.innerHTML;
                        confirmBtn.disabled = true;
                        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating Referral...';

                        // Immediately resolve the promise to proceed with form submission
                        if (window.currentReferralResolve) {
                            window.currentReferralResolve(true);
                            window.currentReferralResolve = null;
                        }

                        // Close modal with a short delay for visual feedback
                        setTimeout(() => {
                            closeReferralModal();
                        }, 200);

                        // Reset button state in case of error (longer timeout)
                        setTimeout(() => {
                            confirmBtn.disabled = false;
                            confirmBtn.innerHTML = originalContent;
                        }, 2000);
                    });
                }

                // Close modal when clicking outside
                const modal = document.getElementById('referralConfirmationModal');
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            closeReferralModal();
                        }
                    });
                }

                // Close modal on ESC key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && modal && modal.classList.contains('show')) {
                        closeReferralModal();
                    }
                });
            }



            // Form validation
            if (referralForm) {
                referralForm.addEventListener('submit', function(e) {
                    e.preventDefault(); // Always prevent default submission initially

                    const patientId = document.getElementById('selectedPatientId').value;
                    if (!patientId) {
                        alert('Please select a patient from the search results above.');
                        return false;
                    }

                    const destinationType = document.getElementById('destination_type').value;

                    // Validate based on destination type
                    const referredToFacilityId = document.getElementById('referredToFacilityId').value;

                    if (destinationType === 'district_office') {
                        if (!referredToFacilityId) {
                            e.preventDefault();
                            alert('No district health office available for this patient. Please select a different destination type.');
                            return false;
                        }
                    } else if (destinationType === 'city_office') {
                        if (!referredToFacilityId || referredToFacilityId !== '1') {
                            e.preventDefault();
                            alert('City Health Office facility ID not set properly. Please try again.');
                            return false;
                        }
                    } else if (destinationType === 'external') {
                        const externalType = document.getElementById('external_facility_type').value;

                        if (!externalType) {
                            e.preventDefault();
                            alert('Please select external facility type.');
                            return false;
                        }

                        if (externalType === 'hospital') {
                            const hospitalName = document.getElementById('hospital_name').value;
                            if (!hospitalName) {
                                e.preventDefault();
                                alert('Please select a hospital.');
                                return false;
                            }
                        } else if (externalType === 'other') {
                            const otherFacility = document.getElementById('other_facility_name').value;
                            if (!otherFacility.trim()) {
                                e.preventDefault();
                                alert('Please specify the other facility name.');
                                return false;
                            }
                            if (otherFacility.trim().length < 3) {
                                e.preventDefault();
                                alert('Other facility name must be at least 3 characters.');
                                return false;
                            }
                        }
                    } else if (!destinationType) {
                        e.preventDefault();
                        alert('Please select a referral destination type.');
                        return false;
                    }

                    const referralReason = document.getElementById('referral_reason').value;
                    if (!referralReason.trim()) {
                        e.preventDefault();
                        alert('Please enter the reason for referral.');
                        return false;
                    }

                    // Validate custom service if "Others" is selected
                    const serviceId = document.getElementById('service_id').value;
                    if (serviceId === 'others') {
                        const customServiceName = document.getElementById('custom_service_name').value;
                        if (!customServiceName.trim()) {
                            e.preventDefault();
                            alert('Please specify the other service name.');
                            return false;
                        }
                        if (customServiceName.trim().length < 3) {
                            e.preventDefault();
                            alert('Other service name must be at least 3 characters.');
                            return false;
                        }
                    }

                    // Show confirmation modal with referral details
                    e.preventDefault();
                    showReferralConfirmation().then(confirmed => {
                        if (confirmed) {
                            // User confirmed, submit the form
                            referralForm.submit();
                        }
                        // If not confirmed, do nothing (form won't submit)
                    });
                    return false;
                });
            }

            // Handle service selection change (for "Others" option)
            const serviceSelect = document.getElementById('service_id');
            const customServiceField = document.getElementById('customServiceField');
            const customServiceInput = document.getElementById('custom_service_name');

            if (serviceSelect && customServiceField && customServiceInput) {
                serviceSelect.addEventListener('change', function() {
                    if (this.value === 'others') {
                        customServiceField.classList.add('show');
                        customServiceInput.required = true;
                    } else {
                        customServiceField.classList.remove('show');
                        customServiceInput.required = false;
                        customServiceInput.value = '';
                    }
                });
            }

            // Note: Service filtering is handled through destination type selection
            // No additional facility-based service filtering needed here

            // Setup modal handlers
            setupModalHandlers();
        });
    </script>

    <!-- Referral Confirmation Modal -->
    <div id="referralConfirmationModal" class="referral-confirmation-modal">
        <div class="referral-modal-content">
            <div class="referral-modal-header">
                <button type="button" class="referral-modal-close" onclick="closeReferralModal()">&times;</button>
                <div class="icon">
                    <i class="fas fa-share-square"></i>
                </div>
                <h3>Confirm Referral Details</h3>
                <p style="margin: 0.5rem 0 0; opacity: 0.9;">Please review the information below before creating the referral</p>
            </div>

            <div class="referral-modal-body">
                <!-- Patient Information -->
                <div class="referral-summary-card">
                    <div class="summary-section">
                        <div class="summary-title">
                            <i class="fas fa-user"></i>
                            Patient Information
                        </div>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="summary-label">Patient Name</div>
                                <div class="summary-value highlight" id="modalPatientName">-</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Patient ID</div>
                                <div class="summary-value" id="modalPatientId">-</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Referral Details -->
                <div class="referral-summary-card">
                    <div class="summary-section">
                        <div class="summary-title">
                            <i class="fas fa-hospital"></i>
                            Referral Details
                        </div>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="summary-label">Referring From</div>
                                <div class="summary-value" id="modalReferringFrom">-</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Destination</div>
                                <div class="summary-value highlight" id="modalDestination">-</div>
                            </div>
                            <div class="summary-item" id="modalServiceContainer" style="display: none;">
                                <div class="summary-label">Service Requested</div>
                                <div class="summary-value" id="modalService">-</div>
                            </div>
                        </div>
                        <div class="summary-item" style="margin-top: 1rem;">
                            <div class="summary-label">Reason for Referral</div>
                            <div class="summary-value reason" id="modalReason">-</div>
                        </div>
                    </div>
                </div>

                <!-- Patient Vitals (if provided) -->
                <div class="referral-summary-card" id="modalVitalsCard" style="display: none;">
                    <div class="summary-section">
                        <div class="summary-title">
                            <i class="fas fa-heartbeat"></i>
                            Patient Vitals
                        </div>
                        <div class="vitals-summary" id="modalVitalsGrid">
                            <!-- Vitals will be populated dynamically -->
                        </div>
                        <div class="summary-item" id="modalVitalsRemarksContainer" style="display: none; margin-top: 1rem;">
                            <div class="summary-label">Vitals Remarks</div>
                            <div class="summary-value" id="modalVitalsRemarks">-</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="referral-modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" onclick="closeReferralModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="modal-btn modal-btn-confirm" id="modalConfirmBtn">
                    <i class="fas fa-save"></i> Update Referral
                </button>
            </div>
        </div>
    </div>
</body>

</html>