<?php
// Include employee session configuration
// Use absolute path resolution - go up 3 levels from records_officer directory
$root_path = realpath(dirname(dirname(dirname(__DIR__))));
require_once $root_path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'session' . DIRECTORY_SEPARATOR . 'employee_session.php';
include $root_path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'db.php';

// Use relative path for assets - more reliable than absolute URLs
$assets_path = '../../../assets';

// Check if user is logged in - use session management function
if (!is_employee_logged_in()) {
    // Only clean output buffer if one exists
    if (ob_get_level()) {
        ob_end_clean();
    }
    error_log('Records Officer Archived Records: No session found, redirecting to login');
    redirect_to_employee_login();
}

// Set active page for sidebar highlighting
$activePage = 'patient_records';

// Define role-based permissions for records_officer
$canEdit = false; // Records Officer can only view
$canView = in_array($_SESSION['role'], ['records_officer']);

if (!$canView) {
    $role = $_SESSION['role'];
    header("Location: ../../../management/$role/dashboard.php");
    exit();
}

// Handle AJAX requests for search and filter
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';
$patientIdFilter = isset($_GET['patient_id']) ? $_GET['patient_id'] : '';
$firstNameFilter = isset($_GET['first_name']) ? $_GET['first_name'] : '';
$lastNameFilter = isset($_GET['last_name']) ? $_GET['last_name'] : '';
$middleNameFilter = isset($_GET['middle_name']) ? $_GET['middle_name'] : '';
$birthdayFilter = isset($_GET['birthday']) ? $_GET['birthday'] : '';
$barangayFilter = isset($_GET['barangay']) ? $_GET['barangay'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$recordsPerPage = 10;
$offset = ($page - 1) * $recordsPerPage;

// Count total records for pagination - ONLY INACTIVE RECORDS
$countSql = "SELECT COUNT(DISTINCT p.patient_id) as total 
             FROM patients p 
             LEFT JOIN barangay b ON p.barangay_id = b.barangay_id 
             WHERE p.status = 'inactive'";

$params = [];
$types = "";

if (!empty($searchQuery)) {
    $countSql .= " AND (p.username LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? 
                  OR b.barangay_name LIKE ? OR p.date_of_birth LIKE ?)";
    $searchParam = "%{$searchQuery}%";
    array_push($params, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    $types .= "sssss";
}

if (!empty($patientIdFilter)) {
    $countSql .= " AND p.username LIKE ?";
    $patientIdParam = "%{$patientIdFilter}%";
    array_push($params, $patientIdParam);
    $types .= "s";
}

if (!empty($firstNameFilter)) {
    $countSql .= " AND p.first_name LIKE ?";
    $firstNameParam = "%{$firstNameFilter}%";
    array_push($params, $firstNameParam);
    $types .= "s";
}

if (!empty($lastNameFilter)) {
    $countSql .= " AND p.last_name LIKE ?";
    $lastNameParam = "%{$lastNameFilter}%";
    array_push($params, $lastNameParam);
    $types .= "s";
}

if (!empty($middleNameFilter)) {
    $countSql .= " AND p.middle_name LIKE ?";
    $middleNameParam = "%{$middleNameFilter}%";
    array_push($params, $middleNameParam);
    $types .= "s";
}

if (!empty($birthdayFilter)) {
    $countSql .= " AND p.date_of_birth LIKE ?";
    $birthdayParam = "%{$birthdayFilter}%";
    array_push($params, $birthdayParam);
    $types .= "s";
}

if (!empty($barangayFilter)) {
    $countSql .= " AND p.barangay_id = ?";
    array_push($params, $barangayFilter);
    $types .= "i";
}

if (!empty($statusFilter)) {
    $countSql .= " AND p.status = ?";
    array_push($params, $statusFilter);
    $types .= "s";
}

$countStmt = $conn->prepare($countSql);
if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalRecords = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Count active patients
$activeCountSql = "SELECT COUNT(DISTINCT p.patient_id) as active_count 
                   FROM patients p 
                   LEFT JOIN barangay b ON p.barangay_id = b.barangay_id 
                   WHERE p.status = 'active'";
$activeParams = [];
$activeTypes = "";

if (!empty($searchQuery)) {
    $activeCountSql .= " AND (p.username LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? 
                      OR b.barangay_name LIKE ? OR p.date_of_birth LIKE ?)";
    $searchParam = "%{$searchQuery}%";
    array_push($activeParams, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    $activeTypes .= "sssss";
}

if (!empty($patientIdFilter)) {
    $activeCountSql .= " AND p.username LIKE ?";
    $patientIdParam = "%{$patientIdFilter}%";
    array_push($activeParams, $patientIdParam);
    $activeTypes .= "s";
}

if (!empty($firstNameFilter)) {
    $activeCountSql .= " AND p.first_name LIKE ?";
    $firstNameParam = "%{$firstNameFilter}%";
    array_push($activeParams, $firstNameParam);
    $activeTypes .= "s";
}

if (!empty($lastNameFilter)) {
    $activeCountSql .= " AND p.last_name LIKE ?";
    $lastNameParam = "%{$lastNameFilter}%";
    array_push($activeParams, $lastNameParam);
    $activeTypes .= "s";
}

if (!empty($middleNameFilter)) {
    $activeCountSql .= " AND p.middle_name LIKE ?";
    $middleNameParam = "%{$middleNameFilter}%";
    array_push($activeParams, $middleNameParam);
    $activeTypes .= "s";
}

if (!empty($birthdayFilter)) {
    $activeCountSql .= " AND p.date_of_birth LIKE ?";
    $birthdayParam = "%{$birthdayFilter}%";
    array_push($activeParams, $birthdayParam);
    $activeTypes .= "s";
}

if (!empty($barangayFilter)) {
    $activeCountSql .= " AND p.barangay_id = ?";
    array_push($activeParams, $barangayFilter);
    $activeTypes .= "i";
}

$activeCountStmt = $conn->prepare($activeCountSql);
if (!empty($activeParams)) {
    $activeCountStmt->bind_param($activeTypes, ...$activeParams);
}
$activeCountStmt->execute();
$activeCountResult = $activeCountStmt->get_result();
$activePatients = $activeCountResult->fetch_assoc()['active_count'];

// Count inactive patients
$inactiveCountSql = "SELECT COUNT(DISTINCT p.patient_id) as inactive_count 
                     FROM patients p 
                     LEFT JOIN barangay b ON p.barangay_id = b.barangay_id 
                     WHERE p.status = 'inactive'";
$inactiveParams = [];
$inactiveTypes = "";

if (!empty($searchQuery)) {
    $inactiveCountSql .= " AND (p.username LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? 
                        OR b.barangay_name LIKE ? OR p.date_of_birth LIKE ?)";
    $searchParam = "%{$searchQuery}%";
    array_push($inactiveParams, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    $inactiveTypes .= "sssss";
}

if (!empty($patientIdFilter)) {
    $inactiveCountSql .= " AND p.username LIKE ?";
    $patientIdParam = "%{$patientIdFilter}%";
    array_push($inactiveParams, $patientIdParam);
    $inactiveTypes .= "s";
}

if (!empty($firstNameFilter)) {
    $inactiveCountSql .= " AND p.first_name LIKE ?";
    $firstNameParam = "%{$firstNameFilter}%";
    array_push($inactiveParams, $firstNameParam);
    $inactiveTypes .= "s";
}

if (!empty($lastNameFilter)) {
    $inactiveCountSql .= " AND p.last_name LIKE ?";
    $lastNameParam = "%{$lastNameFilter}%";
    array_push($inactiveParams, $lastNameParam);
    $inactiveTypes .= "s";
}

if (!empty($middleNameFilter)) {
    $inactiveCountSql .= " AND p.middle_name LIKE ?";
    $middleNameParam = "%{$middleNameFilter}%";
    array_push($inactiveParams, $middleNameParam);
    $inactiveTypes .= "s";
}

if (!empty($birthdayFilter)) {
    $inactiveCountSql .= " AND p.date_of_birth LIKE ?";
    $birthdayParam = "%{$birthdayFilter}%";
    array_push($inactiveParams, $birthdayParam);
    $inactiveTypes .= "s";
}

if (!empty($barangayFilter)) {
    $inactiveCountSql .= " AND p.barangay_id = ?";
    array_push($inactiveParams, $barangayFilter);
    $inactiveTypes .= "i";
}

$inactiveCountStmt = $conn->prepare($inactiveCountSql);
if (!empty($inactiveParams)) {
    $inactiveCountStmt->bind_param($inactiveTypes, ...$inactiveParams);
}
$inactiveCountStmt->execute();
$inactiveCountResult = $inactiveCountStmt->get_result();
$inactivePatients = $inactiveCountResult->fetch_assoc()['inactive_count'];
$totalPages = ceil($totalRecords / $recordsPerPage);

// Get patient records - ONLY INACTIVE RECORDS
$sql = "SELECT p.patient_id, p.username, p.status, 
        p.first_name, p.last_name, p.middle_name, p.date_of_birth, p.sex, p.contact_number, 
        pi.profile_photo,
        b.barangay_name,
        CONCAT(ec.emergency_last_name, ', ', ec.emergency_first_name, ' ', LEFT(ec.emergency_middle_name, 1), '.') as contact_name, 
        ec.emergency_contact_number as emergency_contact
        FROM patients p
        LEFT JOIN personal_information pi ON p.patient_id = pi.patient_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN emergency_contact ec ON p.patient_id = ec.patient_id
        WHERE p.status = 'inactive'";

// Apply the same filters for the main query
$params = [];
$types = "";

if (!empty($searchQuery)) {
    $sql .= " AND (p.username LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? 
              OR b.barangay_name LIKE ? OR p.date_of_birth LIKE ?)";
    $searchParam = "%{$searchQuery}%";
    array_push($params, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    $types .= "sssss";
}

if (!empty($patientIdFilter)) {
    $sql .= " AND p.username LIKE ?";
    $patientIdParam = "%{$patientIdFilter}%";
    array_push($params, $patientIdParam);
    $types .= "s";
}

if (!empty($firstNameFilter)) {
    $sql .= " AND p.first_name LIKE ?";
    $firstNameParam = "%{$firstNameFilter}%";
    array_push($params, $firstNameParam);
    $types .= "s";
}

if (!empty($lastNameFilter)) {
    $sql .= " AND p.last_name LIKE ?";
    $lastNameParam = "%{$lastNameFilter}%";
    array_push($params, $lastNameParam);
    $types .= "s";
}

if (!empty($middleNameFilter)) {
    $sql .= " AND p.middle_name LIKE ?";
    $middleNameParam = "%{$middleNameFilter}%";
    array_push($params, $middleNameParam);
    $types .= "s";
}

if (!empty($birthdayFilter)) {
    $sql .= " AND p.date_of_birth LIKE ?";
    $birthdayParam = "%{$birthdayFilter}%";
    array_push($params, $birthdayParam);
    $types .= "s";
}

if (!empty($barangayFilter)) {
    $sql .= " AND p.barangay_id = ?";
    array_push($params, $barangayFilter);
    $types .= "i";
}

if (!empty($statusFilter)) {
    $sql .= " AND p.status = ?";
    array_push($params, $statusFilter);
    $types .= "s";
}

$sql .= " ORDER BY p.last_name ASC LIMIT ? OFFSET ?";
array_push($params, $recordsPerPage, $offset);
$types .= "ii";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get all barangays for filter dropdown
$barangaySql = "SELECT barangay_id, barangay_name FROM barangay ORDER BY barangay_name";
$barangayResult = $conn->query($barangaySql);

// Handle patient reactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reactivate_patient' && $canEdit) {
    // Prevent any output before JSON response
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Start fresh output buffer
    ob_start();
    
    header('Content-Type: application/json');
    
    try {
        // Check database connection
        if (!$conn || $conn->connect_error) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Database connection failed']);
            exit;
        }
        
        $patient_id = intval($_POST['patient_id']);
        $admin_password = $_POST['admin_password'] ?? '';

        // Validate inputs
        if (empty($patient_id) || $patient_id <= 0) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Invalid patient ID']);
            exit;
        }

        if (empty($admin_password)) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Admin password is required']);
            exit;
        }

        // Verify admin password
        $admin_id = $_SESSION['employee_id'];
        $adminCheckSql = "SELECT e.password FROM employees e 
                         LEFT JOIN roles r ON e.role_id = r.role_id 
                         WHERE e.employee_id = ? AND r.role_name = 'admin'";
        $adminCheckStmt = $conn->prepare($adminCheckSql);
        if (!$adminCheckStmt) {
            error_log("Failed to prepare admin check statement: " . $conn->error);
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Database error occurred']);
            exit;
        }
        
        $adminCheckStmt->bind_param("i", $admin_id);
        $adminCheckStmt->execute();
        $adminResult = $adminCheckStmt->get_result();

        if ($adminResult->num_rows === 0) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Unauthorized access - Admin not found']);
            exit;
        }

        $adminData = $adminResult->fetch_assoc();

        if (!password_verify($admin_password, $adminData['password'])) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Incorrect password']);
            exit;
        }

        // Reactivate the patient
        $updateSql = "UPDATE patients SET status = 'active' WHERE patient_id = ? AND status = 'inactive'";
        $updateStmt = $conn->prepare($updateSql);
        if (!$updateStmt) {
            error_log("Failed to prepare update statement: " . $conn->error);
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Database error occurred']);
            exit;
        }
        
        $updateStmt->bind_param("i", $patient_id);

        if ($updateStmt->execute()) {
            if ($updateStmt->affected_rows > 0) {
                // Log the reactivation (optional - don't fail if this fails)
                try {
                    $logSql = "INSERT INTO activity_logs (employee_id, action, details, timestamp) VALUES (?, 'Patient Reactivated', ?, NOW())";
                    $logStmt = $conn->prepare($logSql);
                    if ($logStmt) {
                        $logDetails = "Reactivated patient ID: $patient_id";
                        $logStmt->bind_param("is", $admin_id, $logDetails);
                        $logStmt->execute();
                    }
                } catch (Exception $logError) {
                    error_log("Failed to log patient reactivation: " . $logError->getMessage());
                    // Continue anyway - don't fail the reactivation because of logging
                }

                ob_clean();
                echo json_encode(['success' => true, 'message' => 'Patient account reactivated successfully']);
            } else {
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Patient not found or already active']);
            }
        } else {
            error_log("Failed to execute update statement: " . $updateStmt->error);
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Failed to reactivate patient account']);
        }
    } catch (Exception $e) {
        error_log("Exception in patient reactivation: " . $e->getMessage());
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'An unexpected error occurred: ' . $e->getMessage()]);
    }
    
    ob_end_flush();
    exit;
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
    <title>Archived Patient Records - Records Officer | CHO Koronadal</title>
    <!-- CSS Files -->
    <link rel="stylesheet" href="../../../assets/css/sidebar.css">
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* Additional styles for patient records management */
        :root {
            --primary: #0077b6;
            --primary-dark: #03045e;
            --secondary: #6c757d;
            --success: #2d6a4f;
            --info: #17a2b8;
            --warning: #ffc107;
            --danger: #d00000;
            --light: #f8f9fa;
            --border: #dee2e6;
            --shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            --shadow-lg: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
            --border-radius: 0.5rem;
            --border-radius-lg: 1rem;
            --transition: all 0.3s ease;
        }

        .loader {
            border: 5px solid rgba(240, 240, 240, 0.5);
            border-radius: 50%;
            border-top: 5px solid var(--primary);
            width: 30px;
            height: 30px;
            animation: spin 1.5s linear infinite;
            margin: 0 auto;
            display: none;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .table-responsive {
            overflow-x: auto;
            border-radius: var(--border-radius);
            margin-top: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            box-shadow: var(--shadow);
        }

        table th {
            background: linear-gradient(135deg, #0077b6, #03045e);
            color: white;
            padding: 12px 15px;
            text-align: left;
            font-weight: 500;
            position: sticky;
            top: 0;
            z-index: 10;
            cursor: pointer;
            user-select: none;
        }

        table th.sortable::after {
            content: '\f0dc';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            margin-left: 5px;
            opacity: 0.5;
            font-size: 14px;
        }

        table th.sort-asc::after {
            content: '\f0de';
            opacity: 1;
        }

        table th.sort-desc::after {
            content: '\f0dd';
            opacity: 1;
        }

        table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        table tr:hover {
            background-color: rgba(240, 247, 255, 0.6);
            transition: background-color 0.2s;
        }

        table tr:last-child td {
            border-bottom: none;
        }

        .action-btn {
            margin-right: 5px;
            padding: 8px 15px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            color: white;
            font-size: 14px;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.15);
        }

        .equal-width {
            width: calc(50% - 5px);
            max-height: fit-content;
            padding: 10px;
            text-align: center;
            font-weight: 500;
            letter-spacing: 0.3px;
            gap: 10px;
        }

        .button-container {
            justify-content: space-between;
            gap: 10px;
        }

        .button-container .dropdown {
            width: 50%;
        }

        .button-container .dropdown button {
            width: 100%;
        }

        .btn-info {
            background: linear-gradient(135deg, #0096c7, #0077b6);
        }

        .btn-primary {
            background: linear-gradient(135deg, #48cae4, #0096c7);
        }

        .btn-warning {
            background: linear-gradient(135deg, #ffba08, #faa307);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #adb5bd, #6c757d);
        }

        .btn-success {
            background: linear-gradient(135deg, #52b788, #2d6a4f);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            color: white;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .bg-success {
            background: linear-gradient(135deg, #52b788, #2d6a4f);
        }

        .bg-danger {
            background: linear-gradient(135deg, #ef476f, #d00000);
        }

        .pagination {
            display: flex;
            list-style: none;
            padding: 0;
            justify-content: center;
            margin-top: 25px;
            gap: 8px;
        }

        .pagination li {
            margin: 0;
        }

        .pagination a {
            padding: 8px 12px;
            border: 1px solid var(--primary);
            color: var(--primary);
            text-decoration: none;
            border-radius: var(--border-radius);
            transition: all 0.2s ease;
            font-weight: 500;
            min-width: 38px;
            text-align: center;
            display: inline-block;
        }

        .pagination a:hover:not(.disabled a) {
            background-color: rgba(0, 119, 182, 0.1);
            transform: translateY(-2px);
        }

        .pagination .active a {
            background: linear-gradient(135deg, #0096c7, #0077b6);
            color: white;
            border-color: transparent;
            box-shadow: 0 2px 5px rgba(0, 119, 182, 0.3);
        }

        .pagination .disabled a {
            color: #ccc;
            border-color: #eee;
            cursor: not-allowed;
            pointer-events: none;
        }

        .profile-img {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }

        tr:hover .profile-img {
            transform: scale(1.05);
        }

        .header {
            background: linear-gradient(135deg, #0077b6, #03045e);
            color: white;
            padding: 12px 15px;
            border-radius: var(--border-radius);
            text-align: center;
            margin-bottom: 15px;
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.1);
        }

        .header h5 {
            margin: 0;
            font-weight: 600;
            letter-spacing: 0.5px;
        }

        .info p {
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .info p:last-child {
            border-bottom: none;
        }

        .info strong {
            color: var(--primary-dark);
            font-weight: 600;
        }

        /* Card content styling */
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .page-title {
            color: var(--primary-dark);
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
        }

        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        .modal.show {
            display: block !important;
        }

        .modal-dialog {
            max-width: 450px;
            margin: 50px auto;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
        }

        .modal.show .modal-dialog {
            transform: translateY(0);
        }

        .modal-content {
            background-color: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow-lg);
        }

        .modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: var(--primary-dark);
            color: white;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: white;
            transition: color 0.2s ease;
        }

        .btn-close:hover {
            color: var(--light);
        }

        /* Radio option pulse animation */
        @keyframes pulseEffect {
            0% {
                transform: scale(1.02);
            }

            50% {
                transform: scale(1.04);
            }

            100% {
                transform: scale(1.02);
            }
        }

        .pulse-animation {
            animation: pulseEffect 0.3s ease;
        }

        /* Form inputs */
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e2e8f0;
            border-radius: var(--border-radius);
            margin-bottom: 12px;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            font-size: 14px;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.2);
            outline: none;
        }

        .form-select {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e2e8f0;
            border-radius: var(--border-radius);
            margin-bottom: 12px;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            appearance: none;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M2 5l6 6 6-6'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px 12px;
            font-size: 14px;
        }

        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.2);
            outline: none;
        }

        .input-group {
            display: flex;
            position: relative;
        }

        .input-group-text {
            padding: 10px 15px;
            background-color: #f8f9fa;
            border: 1px solid #e2e8f0;
            border-right: none;
            border-radius: var(--border-radius) 0 0 var(--border-radius);
            display: flex;
            align-items: center;
            color: #64748b;
        }

        .input-group .form-control {
            border-radius: 0 var(--border-radius) var(--border-radius) 0;
            margin-bottom: 0;
            flex: 1;
        }

        /* Utility classes */
        .d-flex {
            display: flex;
        }

        .me-2 {
            margin-right: 10px;
        }

        .mb-2 {
            margin-bottom: 10px;
        }

        .mt-4 {
            margin-top: 20px;
        }

        .justify-content-center {
            justify-content: center;
        }

        .text-center {
            text-align: center;
        }

        .text-muted {
            color: #6c757d;
            font-style: italic;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        /* Header with badge */
        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .total-count .badge {
            font-size: 14px;
            padding: 6px 12px;
            margin-right: 8px;
        }

        .bg-primary {
            background: linear-gradient(135deg, #48cae4, #0096c7);
        }

        /* Responsive grid */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin-right: -15px;
            margin-left: -15px;
        }

        .col-12 {
            flex: 0 0 100%;
            max-width: 100%;
            padding: 0 15px;
        }

        .col-md-4 {
            flex: 0 0 33.333333%;
            max-width: 33.333333%;
            padding: 0 15px;
        }

        .col-md-3 {
            flex: 0 0 25%;
            max-width: 25%;
            padding: 0 15px;
        }

        .col-md-2 {
            flex: 0 0 16.666667%;
            max-width: 16.666667%;
            padding: 0 15px;
        }

        @media (max-width: 768px) {

            .col-md-4,
            .col-md-3,
            .col-md-2 {
                flex: 0 0 100%;
                max-width: 100%;
            }
        }

        /* Dropdown */
        .dropdown {
            position: relative;
            display: inline-block;
        }

        .dropdown-toggle {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .dropdown-toggle::after {
            display: none;
        }

        .dropdown-arrow {
            margin-left: 8px;
            font-size: 0.8em;
            transition: transform 0.2s ease;
        }

        .dropdown-toggle[aria-expanded="true"] .dropdown-arrow {
            transform: rotate(180deg);
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            top: calc(100% + 5px);
            background-color: #fff;
            min-width: 200px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            border-radius: var(--border-radius);
            padding: 8px 0;
            opacity: 0;
            transform: translateY(10px);
            transition: opacity 0.2s ease, transform 0.2s ease;
            border: 1px solid rgba(0, 0, 0, 0.1);
        }

        .dropdown-menu.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .enhanced-dropdown {
            min-width: 220px;
        }

        .enhanced-dropdown .dropdown-item {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
            padding: 12px 16px;
            clear: both;
            text-decoration: none;
            color: #333;
            transition: all 0.2s ease;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .enhanced-dropdown .dropdown-item:hover {
            background-color: rgba(0, 119, 182, 0.08);
        }

        .enhanced-dropdown .dropdown-item i {
            color: var(--primary);
            margin-right: 8px;
            font-size: 1.1em;
            align-self: flex-start;
        }

        .enhanced-dropdown .dropdown-item span {
            font-weight: 500;
            color: #333;
            display: flex;
            align-items: center;
        }

        .enhanced-dropdown .dropdown-item small {
            color: #666;
            font-size: 0.85em;
            margin-top: 2px;
            line-height: 1.3;
        }

        .enhanced-dropdown .dropdown-item:hover span {
            color: var(--primary);
        }

        .enhanced-dropdown .dropdown-item:hover small {
            color: #555;
        }

        /* Action buttons row styling */
        .action-buttons-row {
            gap: 0.5rem !important;
            align-items: stretch;
        }

        .action-btn-uniform {
            flex: 1;
            min-height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .action-btn-uniform::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.6s ease;
        }

        .action-btn-uniform:hover::before {
            left: 100%;
        }

        /* Responsive button text */
        @media (max-width: 1200px) {
            .action-btn-uniform .btn-text {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .action-buttons-row {
                flex-direction: column;
                gap: 0.5rem !important;
            }

            .action-btn-uniform {
                width: 100%;
                justify-content: center;
            }

            .action-btn-uniform .btn-text {
                display: inline;
            }
        }

        /* Alert styles */
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left-width: 4px;
            border-left-style: solid;
        }

        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border-color: #ffeeba;
            border-left-color: #ffc107;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border-color: #f5c6cb;
            border-left-color: #dc3545;
        }

        .alert i {
            margin-right: 5px;
        }

        /* Form styling for modals */
        .form-label {
            font-weight: 500;
            color: var(--primary-dark);
            margin-bottom: 5px;
            display: block;
        }

        .form-check {
            padding: 8px 12px;
            margin-bottom: 5px;
            border-radius: 5px;
            transition: background-color 0.15s ease;
        }

        .form-check:hover {
            background-color: rgba(0, 119, 182, 0.05);
        }

        .form-check-input {
            margin-top: 0.3em;
        }

        .form-check-label {
            padding-left: 5px;
        }

        .d-none {
            display: none !important;
        }

        /* Spinner for loading states */
        .spinner-border {
            display: inline-block;
            width: 1rem;
            height: 1rem;
            vertical-align: middle;
            border: 0.2em solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            animation: spinner-border .75s linear infinite;
            margin-right: 5px;
        }

        @keyframes spinner-border {
            to {
                transform: rotate(360deg);
            }
        }

        /* Section header styling */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0 15px 0;
            margin-bottom: 15px;
            border-bottom: 1px solid rgba(0, 119, 182, 0.2);
            flex-wrap: wrap;
            gap: 1rem;
        }

        .section-header h4 {
            margin: 0;
            color: var(--primary-dark);
            font-size: 18px;
            font-weight: 600;
        }

        .section-header h4 i {
            color: var(--primary);
            margin-right: 8px;
        }

        .section-header-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .section-header-actions {
                width: 100%;
                justify-content: flex-start;
            }
        }

        /* Breadcrumb styling */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #666;
        }

        .breadcrumb a {
            color: #0077b6;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* Page header styling */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .page-header h1 {
            color: #0077b6;
            margin: 0;
            font-size: 1.8rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-header h1 i {
            color: #0077b6;
        }

        /* Total count badges styling */
        .total-count {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-start;
        }

        .total-count .badge {
            min-width: 150px;
            padding: 8px 16px;
            font-size: 0.9rem;
            font-weight: 600;
            text-align: center;
            white-space: nowrap;
            border-radius: 25px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .total-count .badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        /* Mobile responsive styling */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .total-count {
                width: 100%;
                justify-content: flex-start;
                gap: 0.75rem;
            }

            .total-count .badge {
                min-width: 120px;
                font-size: 0.8rem;
                padding: 6px 12px;
            }
        }

        @media (max-width: 480px) {
            .total-count {
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }

            .total-count .badge {
                width: 100%;
                min-width: auto;
                text-align: center;
            }
        }

        /* Button styles for modals */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        .btn-success {
            background: linear-gradient(135deg, #52b788, #2d6a4f);
            color: white;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #40916c, #1b4332);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(45, 106, 79, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef476f, #d00000);
            color: white;
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #e63946, #9d0208);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(208, 0, 0, 0.3);
        }
    </style>
</head>

<body>
    <!-- Include sidebar -->
    <?php include $root_path . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'sidebar_records_officer.php'; ?>
    <div class="homepage">
        <div class="main-content">
            <!-- Breadcrumb Navigation -->
            <div class="breadcrumb" style="margin-top: 50px;">
                <a href="../dashboard.php"><i class="fas fa-home"></i> Admin Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <a href="patient_records_management.php"><i class="fas fa-users"></i> Patient Records</a>
                <i class="fas fa-chevron-right"></i>
                <span>Archived Records</span>
            </div>

            <div class="page-header">
                <h1><i class="fas fa-archive"></i> Archived Patient Records</h1>
                <div class="total-count">
                    <span class="badge bg-danger"><?php echo $totalRecords; ?> Archived Patients</span>
                </div>
            </div>

            <!-- Information Alert -->
            <div class="alert alert-info" style="background-color: #d1ecf1; color: #0c5460; border-color: #bee5eb; border-left-color: #17a2b8;">
                <i class="fas fa-info-circle"></i> 
                <strong>Info:</strong> This page shows inactive/archived patient records. 
                After reactivating a patient, they will appear in the 
                <a href="patient_records_management.php" style="color: #0c5460; text-decoration: underline; font-weight: bold;">Active Patient Records</a> page.
            </div>

            <!-- Search and Filter Section -->
            <div class="card-container">
                <div class="section-header">
                    <h4><i class="fas fa-filter"></i> Search & Filter Options</h4>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-2">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" id="searchInput" class="form-control" placeholder="General search..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                            <input type="text" id="patientIdInput" class="form-control" placeholder="Patient ID" value="<?php echo htmlspecialchars($patientIdFilter); ?>">
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                            <input type="text" id="firstNameInput" class="form-control" placeholder="First Name" value="<?php echo htmlspecialchars($firstNameFilter); ?>">
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                            <input type="text" id="lastNameInput" class="form-control" placeholder="Last Name" value="<?php echo htmlspecialchars($lastNameFilter); ?>">
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-user"></i></span>
                            <input type="text" id="middleNameInput" class="form-control" placeholder="Middle Name" value="<?php echo htmlspecialchars($middleNameFilter); ?>">
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                            <input type="date" id="birthdayInput" class="form-control" placeholder="Birthday" value="<?php echo htmlspecialchars($birthdayFilter); ?>">
                        </div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <select id="barangayFilter" class="form-select">
                            <option value="">All Barangays</option>
                            <?php
                            // Reset pointer to beginning of result set
                            $barangayResult->data_seek(0);
                            while ($barangay = $barangayResult->fetch_assoc()):
                            ?>
                                <option value="<?php echo $barangay['barangay_id']; ?>" <?php echo ($barangayFilter == $barangay['barangay_id'] ? 'selected' : ''); ?>>
                                    <?php echo htmlspecialchars($barangay['barangay_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex button-container action-buttons-row">
                        <button id="searchBtn" class="action-btn btn-primary action-btn-uniform">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <button id="clearFilters" class="action-btn btn-secondary action-btn-uniform">
                            <i class="fas fa-times-circle"></i> Clear Filters
                        </button>
                    </div>
                </div>
            </div>

            <!-- Loader -->
            <div class="text-center" style="padding: 15px 0;">
                <div id="loader" class="loader"></div>
            </div>

            <!-- Patient Records Table -->
            <div class="card-container">
                <div class="section-header">
                    <h4><i class="fas fa-table"></i> Patient Records</h4>
                    <div class="section-header-actions">
                        <a href="patient_records_management.php" class="action-btn btn-warning action-btn-uniform" style="text-decoration: none;">
                            <i class="fas fa-arrow-left"></i> Back to Active Records
                        </a>
                        <div class="dropdown">
                            <button class="action-btn btn-success dropdown-toggle action-btn-uniform" type="button" id="exportDropdown">
                                <i class="fas fa-file-export"></i> Export Data
                                <i class="fas fa-chevron-down dropdown-arrow"></i>
                            </button>
                            <ul class="dropdown-menu enhanced-dropdown" id="exportMenu">
                                <li><a class="dropdown-item" href="#" id="exportCSV">
                                        <i class="fas fa-file-csv"></i>
                                        <span>Export to CSV</span>
                                        <small>Comma-separated values</small>
                                    </a></li>
                                <li><a class="dropdown-item" href="#" id="exportXLSX">
                                        <i class="fas fa-file-excel"></i>
                                        <span>Export to Excel</span>
                                        <small>Microsoft Excel format</small>
                                    </a></li>
                                <li><a class="dropdown-item" href="#" id="exportPDF">
                                        <i class="fas fa-file-pdf"></i>
                                        <span>Export to PDF</span>
                                        <small>Portable document format</small>
                                    </a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="patientTable">
                        <thead>
                            <tr>
                                <th style="width: 70px;"> </th>
                                <th class="sortable" data-column="username">Patient ID</th>
                                <th class="sortable" data-column="full_name">Full Name</th>
                                <th class="sortable" data-column="dob">DOB</th>
                                <th class="sortable" data-column="sex">Sex</th>
                                <th class="sortable" data-column="barangay">Barangay</th>
                                <th class="sortable" data-column="contact">Contact</th>
                                <th class="sortable" data-column="status">Status</th>
                                <th style="width: 120px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): ?>
                                <?php while ($patient = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($patient['profile_photo'])): ?>
                                                <img src="data:image/jpeg;base64,<?php echo base64_encode($patient['profile_photo']); ?>"
                                                    class="profile-img" alt="Patient Photo">
                                            <?php else: ?>
                                                <img src="<?php echo $assets_path; ?>/images/user-default.png"
                                                    class="profile-img" alt="Patient Photo">
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?php echo htmlspecialchars($patient['username']); ?></strong></td>
                                        <td>
                                            <?php
                                            $fullName = $patient['last_name'] . ', ' . $patient['first_name'];
                                            if (!empty($patient['middle_name'])) {
                                                $fullName .= ' ' . substr($patient['middle_name'], 0, 1) . '.';
                                            }
                                            echo htmlspecialchars($fullName);
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                            if (!empty($patient['date_of_birth'])) {
                                                $dob = new DateTime($patient['date_of_birth']);
                                                echo $dob->format('M d, Y');
                                            } else {
                                                echo '<span class="text-muted">N/A</span>';
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo !empty($patient['sex']) ? htmlspecialchars($patient['sex']) : '<span class="text-muted">N/A</span>'; ?></td>
                                        <td><?php echo !empty($patient['barangay_name']) ? htmlspecialchars($patient['barangay_name']) : '<span class="text-muted">N/A</span>'; ?></td>
                                        <td><?php echo !empty($patient['contact_number']) ? htmlspecialchars($patient['contact_number']) : '<span class="text-muted">N/A</span>'; ?></td>
                                        <td>
                                            <span class="badge <?php echo ($patient['status'] == 'active') ? 'bg-success' : 'bg-danger'; ?>">
                                                <?php echo ucfirst(htmlspecialchars($patient['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <a href="view_patient_profile.php?patient_id=<?php echo $patient['patient_id']; ?>&back_url=archived_records_management.php"
                                                    class="action-btn btn-info" title="View Patient Profile (Admin)">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if ($canEdit): ?>
                                                <button type="button" class="action-btn btn-success reactivate-patient"
                                                    data-patient-id="<?php echo $patient['patient_id']; ?>"
                                                    data-patient-name="<?php echo htmlspecialchars($fullName); ?>"
                                                    title="Reactivate Patient Account">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                                <?php endif; ?>
                                                <button type="button" class="action-btn btn-primary view-contact"
                                                    data-id="<?php echo $patient['patient_id']; ?>"
                                                    data-username="<?php echo htmlspecialchars($patient['username']); ?>"
                                                    data-name="<?php echo htmlspecialchars($fullName); ?>"
                                                    data-dob="<?php echo !empty($patient['date_of_birth']) ? $dob->format('M d, Y') : 'N/A'; ?>"
                                                    data-sex="<?php echo !empty($patient['sex']) ? htmlspecialchars($patient['sex']) : 'N/A'; ?>"
                                                    data-contact="<?php echo !empty($patient['contact_number']) ? htmlspecialchars($patient['contact_number']) : 'N/A'; ?>"
                                                    data-barangay="<?php echo !empty($patient['barangay_name']) ? htmlspecialchars($patient['barangay_name']) : 'N/A'; ?>"
                                                    data-emergency-name="<?php echo !empty($patient['contact_name']) ? htmlspecialchars($patient['contact_name']) : 'N/A'; ?>"
                                                    data-emergency-contact="<?php echo !empty($patient['emergency_contact']) ? htmlspecialchars($patient['emergency_contact']) : 'N/A'; ?>"
                                                    data-photo="<?php echo !empty($patient['profile_photo']) ? 'data:image/jpeg;base64,' . base64_encode($patient['profile_photo']) : $assets_path . '/images/user-default.png'; ?>"
                                                    title="View Contact">
                                                    <i class="fas fa-address-card"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center">
                                        <div style="padding: 30px 0;">
                                            <i class="fas fa-search" style="font-size: 48px; color: #ccc; margin-bottom: 15px;"></i>
                                            <p>No patient records found.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="mt-4">
                        <ul class="pagination">
                            <li class="<?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                <a href="#" data-page="<?php echo $page - 1; ?>">Previous</a>
                            </li>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php if ($i == 1 || $i == $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                    <li class="<?php echo ($i == $page) ? 'active' : ''; ?>">
                                        <a href="#" data-page="<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                    <li class="disabled">
                                        <span>...</span>
                                    </li>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <li class="<?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                <a href="#" data-page="<?php echo $page + 1; ?>">Next</a>
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </div>
    </div>

    <!-- Reactivate Confirmation Modal -->
    <div class="modal fade" id="reactivateModal" tabindex="-1" role="dialog" aria-labelledby="reactivateModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="reactivateModalLabel">
                        <i class="fas fa-undo"></i> Reactivate Patient Account
                    </h5>
                    <button type="button" class="btn-close" id="closeReactivateModal" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        You are about to reactivate the patient account for: <strong id="reactivatePatientName"></strong>
                    </div>
                    <form id="reactivateForm">
                        <input type="hidden" id="reactivatePatientId" value="">
                        <div class="form-group">
                            <label for="adminPassword" class="form-label">
                                <i class="fas fa-lock"></i> Enter your admin password to confirm:
                            </label>
                            <input type="password" id="adminPassword" class="form-control"
                                placeholder="Enter your password" required>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            This action will make the patient account active and accessible again in the main patient records.
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="action-btn btn-secondary" id="cancelReactivate">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="action-btn btn-success" id="confirmReactivate">
                        <i class="fas fa-undo"></i> Reactivate Account
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Contact Modal -->
    <div class="modal fade" id="contactModal" tabindex="-1" role="dialog" aria-labelledby="contactModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="contactModalLabel">Patient Record Summary</h5>
                    <button type="button" class="btn-close" id="closeContactModal" aria-label="Close">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="card-container" style="box-shadow: none; padding: 0;">
                        <div class="header">
                            <h5>CITY HEALTH OFFICE - KORONADAL</h5>
                        </div>
                        <div style="text-align: center; padding: 20px 0;">
                            <img src="<?php echo $assets_path; ?>/images/user-default.png" id="patientPhoto" alt="Patient Photo" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 3px solid #0077b6; box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                            <h4 id="patientName" style="margin-top: 15px; color: var(--primary-dark); font-weight: 600;"></h4>
                            <p style="color: var(--primary); font-weight: 500; letter-spacing: 1px;"><i class="fas fa-id-badge" style="margin-right: 5px;"></i> Patient ID: <span id="patientId"></span></p>
                        </div>
                        <div class="info">
                            <p><strong><i class="fas fa-calendar-alt" style="color: var(--primary);"></i> Date of Birth:</strong> <span id="patientDob"></span></p>
                            <p><strong><i class="fas fa-venus-mars" style="color: var(--primary);"></i> Sex:</strong> <span id="patientSex"></span></p>
                            <p><strong><i class="fas fa-map-marker-alt" style="color: var(--primary);"></i> Barangay:</strong> <span id="patientBarangay"></span></p>
                            <p><strong><i class="fas fa-phone" style="color: var(--primary);"></i> Contact Number:</strong> <span id="patientContact"></span></p>
                        </div>

                        <div class="header" style="margin-top: 20px;">
                            <h5>Emergency Contact</h5>
                        </div>
                        <div class="info">
                            <p><strong><i class="fas fa-user" style="color: var(--primary);"></i> Name:</strong> <span id="emergencyName"></span></p>
                            <p><strong><i class="fas fa-phone" style="color: var(--primary);"></i> Contact Number:</strong> <span id="emergencyContact"></span></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="action-btn btn-secondary" id="closeModalBtn">
                        <i class="fas fa-times"></i> Close
                    </button>
                    <!--<button type="button" class="action-btn btn-primary" id="printIdCard">
                        <i class="fas fa-print"></i> Print ID Card
                    </button>-->
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" role="dialog" aria-labelledby="successModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header" style="background-color: var(--success); color: white;">
                    <h5 class="modal-title" id="successModalLabel">
                        <i class="fas fa-check-circle"></i> Success
                    </h5>
                    <button type="button" class="btn-close" id="closeSuccessModal" style="color: white;">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="text-center">
                        <i class="fas fa-check-circle" style="font-size: 3rem; color: var(--success); margin-bottom: 1rem;"></i>
                        <p id="successMessage" style="font-size: 1.1rem; margin-bottom: 1rem;"></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-success" id="successOkButton">
                        <i class="fas fa-check"></i> OK
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Modal -->
    <div class="modal fade" id="errorModal" tabindex="-1" role="dialog" aria-labelledby="errorModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header" style="background-color: var(--danger); color: white;">
                    <h5 class="modal-title" id="errorModalLabel">
                        <i class="fas fa-exclamation-triangle"></i> Error
                    </h5>
                    <button type="button" class="btn-close" id="closeErrorModal" style="color: white;">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="text-center">
                        <i class="fas fa-exclamation-triangle" style="font-size: 3rem; color: var(--danger); margin-bottom: 1rem;"></i>
                        <p id="errorMessage" style="font-size: 1.1rem; margin-bottom: 1rem;"></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" id="errorOkButton">
                        <i class="fas fa-times"></i> OK
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <script>
        $(document).ready(function() {
            // Debounce function for search input
            function debounce(func, delay) {
                let timeout;
                return function() {
                    const context = this;
                    const args = arguments;
                    clearTimeout(timeout);
                    timeout = setTimeout(() => func.apply(context, args), delay);
                };
            }

            // Modal functions for success and error messages
            function showSuccessModal(message, callback = null) {
                $('#successMessage').text(message);
                $('#successModal').addClass('show');
                
                // Store callback for later use
                if (callback) {
                    $('#successModal').data('callback', callback);
                }
            }

            function showErrorModal(message, callback = null) {
                $('#errorMessage').text(message);
                $('#errorModal').addClass('show');
                
                // Store callback for later use
                if (callback) {
                    $('#errorModal').data('callback', callback);
                }
            }

            // Success modal event handlers
            $(document).on('click', '#closeSuccessModal, #successOkButton', function() {
                $('#successModal').removeClass('show');
                
                // Execute callback if provided
                const callback = $('#successModal').data('callback');
                if (callback && typeof callback === 'function') {
                    callback();
                    $('#successModal').removeData('callback');
                }
            });

            // Error modal event handlers
            $(document).on('click', '#closeErrorModal, #errorOkButton', function() {
                $('#errorModal').removeClass('show');
                
                // Execute callback if provided
                const callback = $('#errorModal').data('callback');
                if (callback && typeof callback === 'function') {
                    callback();
                    $('#errorModal').removeData('callback');
                }
            });

            // Close modals when clicking outside
            $(document).on('click', '.modal', function(e) {
                if (e.target === this) {
                    $(this).removeClass('show');
                }
            });

            // Function to update URL with filters and reload
            function updateFilters() {
                $('#loader').show();
                let searchValue = $('#searchInput').val();
                let patientIdValue = $('#patientIdInput').val();
                let firstNameValue = $('#firstNameInput').val();
                let lastNameValue = $('#lastNameInput').val();
                let middleNameValue = $('#middleNameInput').val();
                let birthdayValue = $('#birthdayInput').val();
                let barangayValue = $('#barangayFilter').val();
                let pageValue = 1; // Reset to first page when filters change

                let url = window.location.pathname + '?';
                let params = [];

                if (searchValue) params.push('search=' + encodeURIComponent(searchValue));
                if (patientIdValue) params.push('patient_id=' + encodeURIComponent(patientIdValue));
                if (firstNameValue) params.push('first_name=' + encodeURIComponent(firstNameValue));
                if (lastNameValue) params.push('last_name=' + encodeURIComponent(lastNameValue));
                if (middleNameValue) params.push('middle_name=' + encodeURIComponent(middleNameValue));
                if (birthdayValue) params.push('birthday=' + encodeURIComponent(birthdayValue));
                if (barangayValue) params.push('barangay=' + encodeURIComponent(barangayValue));
                if (pageValue) params.push('page=' + encodeURIComponent(pageValue));

                url += params.join('&');
                window.location.href = url;
            }

            // Search button event listener
            $('#searchBtn').on('click', updateFilters);

            // Auto-trigger search on Enter key press in input fields
            $('#searchInput, #patientIdInput, #firstNameInput, #lastNameInput, #middleNameInput').on('keypress', function(e) {
                if (e.which === 13) { // Enter key
                    updateFilters();
                }
            });

            // Auto-trigger search on select change
            $('#birthdayInput, #barangayFilter').on('change', updateFilters);

            // Clear filters button
            $('#clearFilters').on('click', function() {
                window.location.href = window.location.pathname;
            });

            // Pagination handling
            $('.pagination a').on('click', function(e) {
                e.preventDefault();
                $('#loader').show();

                let page = $(this).data('page');
                let searchValue = $('#searchInput').val();
                let patientIdValue = $('#patientIdInput').val();
                let firstNameValue = $('#firstNameInput').val();
                let lastNameValue = $('#lastNameInput').val();
                let middleNameValue = $('#middleNameInput').val();
                let birthdayValue = $('#birthdayInput').val();
                let barangayValue = $('#barangayFilter').val();

                let url = window.location.pathname + '?';
                let params = [];

                if (searchValue) params.push('search=' + encodeURIComponent(searchValue));
                if (patientIdValue) params.push('patient_id=' + encodeURIComponent(patientIdValue));
                if (firstNameValue) params.push('first_name=' + encodeURIComponent(firstNameValue));
                if (lastNameValue) params.push('last_name=' + encodeURIComponent(lastNameValue));
                if (middleNameValue) params.push('middle_name=' + encodeURIComponent(middleNameValue));
                if (birthdayValue) params.push('birthday=' + encodeURIComponent(birthdayValue));
                if (barangayValue) params.push('barangay=' + encodeURIComponent(barangayValue));
                params.push('page=' + encodeURIComponent(page));

                url += params.join('&');
                window.location.href = url;
            });

            // Table column sorting
            let sortState = {
                column: null,
                direction: 'asc'
            };

            $('#patientTable th.sortable').on('click', function() {
                const columnIndex = $(this).index();
                const columnType = $(this).data('column');

                // Update sort direction
                if (sortState.column === columnIndex) {
                    // Toggle sort direction if same column clicked again
                    sortState.direction = sortState.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    // Set new column and default to ascending
                    sortState.column = columnIndex;
                    sortState.direction = 'asc';
                }

                // Sort the table rows
                const rows = $('#patientTable tbody tr').get();
                rows.sort(function(a, b) {
                    let aValue = $(a).children('td').eq(columnIndex).text().trim();
                    let bValue = $(b).children('td').eq(columnIndex).text().trim();

                    // Special handling for dates
                    if (columnType === 'dob') {
                        // Try to parse dates (M d, Y format)
                        const aDate = new Date(aValue);
                        const bDate = new Date(bValue);

                        // Check if we have valid dates
                        if (!isNaN(aDate) && !isNaN(bDate)) {
                            if (sortState.direction === 'asc') {
                                return aDate - bDate;
                            } else {
                                return bDate - aDate;
                            }
                        }
                    }

                    // Handle N/A values - N/A should always be at the bottom
                    if (aValue === 'N/A' && bValue !== 'N/A') return 1;
                    if (aValue !== 'N/A' && bValue === 'N/A') return -1;

                    // Use natural sorting for everything else
                    const collator = new Intl.Collator(undefined, {
                        numeric: true,
                        sensitivity: 'base'
                    });
                    const result = collator.compare(aValue, bValue);

                    // Apply sort direction
                    return sortState.direction === 'asc' ? result : -result;
                });

                // Append sorted rows back to table
                $.each(rows, function(index, row) {
                    $('#patientTable tbody').append(row);
                });

                // Update UI to show sort direction
                $('#patientTable th').removeClass('sort-asc sort-desc');
                $(this).addClass('sort-' + sortState.direction);

                // Update row zebra striping after sort
                $('#patientTable tbody tr').removeClass('odd even');
                $('#patientTable tbody tr:odd').addClass('odd');
                $('#patientTable tbody tr:even').addClass('even');
            });

            // Contact modal handling - using event delegation for dynamically added elements
            $(document).on('click', '.view-contact', function() {
                const patientId = $(this).data('id');
                const patientName = $(this).data('name');
                const patientUsername = $(this).data('username');
                const dob = $(this).data('dob');
                const sex = $(this).data('sex');
                const contact = $(this).data('contact');
                const barangay = $(this).data('barangay');
                const emergencyName = $(this).data('emergency-name');
                const emergencyContact = $(this).data('emergency-contact');
                const photoSrc = $(this).data('photo');

                console.log("View contact clicked for:", patientName); // Debug log

                // Update modal with patient data
                $('#patientName').text(patientName);
                $('#patientId').text(patientUsername);
                $('#patientDob').text(dob);
                $('#patientSex').text(sex);
                $('#patientContact').text(contact);
                $('#patientBarangay').text(barangay);
                $('#emergencyName').text(emergencyName);
                $('#emergencyContact').text(emergencyContact);
                $('#patientPhoto').attr('src', photoSrc);

                // Show modal with custom handling
                $('#contactModal').addClass('show');
                // Ensure z-index is set correctly for the modal
                $('#contactModal').css('z-index', '1050');
            });

            // Close modal handlers - using event delegation
            $(document).on('click', '#closeContactModal, #closeModalBtn', function() {
                console.log("Close contact modal clicked"); // Debug log
                // Hide modal with custom handling
                $('#contactModal').removeClass('show');

                // Ensure any modal backdrop is removed
                $('.modal-backdrop').remove();
            });

            // Export dropdown toggle
            $('#exportDropdown').on('click', function(e) {
                e.stopPropagation();
                const menu = $('#exportMenu');
                const isOpen = menu.hasClass('show');
                
                // Close all other dropdowns first
                $('.dropdown-menu').removeClass('show');
                $('.dropdown-toggle').attr('aria-expanded', 'false');
                
                if (!isOpen) {
                    menu.addClass('show');
                    $(this).attr('aria-expanded', 'true');
                } else {
                    menu.removeClass('show');
                    $(this).attr('aria-expanded', 'false');
                }
            });
            
            // Close dropdown when clicking outside
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.dropdown').length) {
                    $('.dropdown-menu').removeClass('show');
                    $('.dropdown-toggle').attr('aria-expanded', 'false');
                }
            });            // Print ID Card functionality
            $('#printIdCard').on('click', function() {
                // Create a hidden iframe to print
                const iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                document.body.appendChild(iframe);

                // Get patient info
                const patientName = $('#patientName').text();
                const patientId = $('#patientId').text();
                const patientDob = $('#patientDob').text();
                const patientSex = $('#patientSex').text();
                const patientBarangay = $('#patientBarangay').text();
                const patientContact = $('#patientContact').text();
                const emergencyName = $('#emergencyName').text();
                const emergencyContact = $('#emergencyContact').text();
                const photoSrc = $('#patientPhoto').attr('src');

                // Write to the iframe document
                const doc = iframe.contentDocument || iframe.contentWindow.document;
                doc.write('<!DOCTYPE html><html><head>');
                doc.write('<title>Patient ID Card - ' + patientName + '</title>');
                doc.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">');
                doc.write('<style>');
                doc.write('@import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap");');
                doc.write('body { font-family: "Inter", sans-serif; padding: 20px; background-color: #f5f5f5; }');
                doc.write('.card-container { width: 380px; margin: 0 auto; border: none; padding: 0; border-radius: 15px; overflow: hidden; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15); background-color: white; }');
                doc.write('.header { background: linear-gradient(135deg, #0077b6, #03045e); color: white; padding: 15px; text-align: center; }');
                doc.write('.header h5 { margin: 0; font-size: 18px; letter-spacing: 1px; }');
                doc.write('.photo-section { text-align: center; padding: 25px 0 15px; background-color: #f8f9fa; }');
                doc.write('img { width: 130px; height: 130px; border-radius: 50%; display: block; margin: 0 auto; border: 4px solid #0077b6; object-fit: cover; box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2); }');
                doc.write('h4 { margin: 15px 0 5px; color: #03045e; font-size: 20px; font-weight: 600; }');
                doc.write('.info { padding: 20px; }');
                doc.write('.info p { margin: 0; padding: 12px 0; font-size: 14px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; }');
                doc.write('.info p:last-child { border-bottom: none; }');
                doc.write('.info strong { color: #03045e; font-weight: 600; display: flex; align-items: center; gap: 8px; }');
                doc.write('.info i { color: #0077b6; font-size: 16px; }');
                doc.write('.section-title { background-color: #e9f3fe; color: #0077b6; padding: 10px 20px; font-weight: 600; margin: 0; font-size: 16px; }');
                doc.write('@media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }');
                doc.write('</style></head><body>');
                doc.write('<div class="card-container">');
                doc.write('<div class="header"><h5>CITY HEALTH OFFICE - KORONADAL</h5></div>');
                doc.write('<div class="photo-section">');
                doc.write('<img src="' + photoSrc + '" alt="Patient Photo">');
                doc.write('<h4>' + patientName + '</h4>');
                doc.write('<p style="margin: 5px 0 0; color: #0077b6; font-weight: 500; letter-spacing: 1px;"><i class="fas fa-id-badge"></i> Patient ID: ' + patientId + '</p>');
                doc.write('</div>');
                doc.write('<div class="info">');
                doc.write('<p><strong><i class="fas fa-calendar-alt"></i> Date of Birth:</strong> ' + patientDob + '</p>');
                doc.write('<p><strong><i class="fas fa-venus-mars"></i> Sex:</strong> ' + patientSex + '</p>');
                doc.write('<p><strong><i class="fas fa-map-marker-alt"></i> Barangay:</strong> ' + patientBarangay + '</p>');
                doc.write('<p><strong><i class="fas fa-phone"></i> Contact Number:</strong> ' + patientContact + '</p>');
                doc.write('</div>');
                doc.write('<h5 class="section-title">Emergency Contact</h5>');
                doc.write('<div class="info">');
                doc.write('<p><strong><i class="fas fa-user"></i> Name:</strong> ' + emergencyName + '</p>');
                doc.write('<p><strong><i class="fas fa-phone"></i> Contact Number:</strong> ' + emergencyContact + '</p>');
                doc.write('</div>');
                doc.write('</div></body></html>');
                doc.close();

                // Print and remove the iframe
                setTimeout(function() {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                    document.body.removeChild(iframe);
                }, 250);
            });

            // Reactivate patient functionality
            $(document).on('click', '.reactivate-patient', function() {
                const patientId = $(this).data('patient-id');
                const patientName = $(this).data('patient-name');

                $('#reactivatePatientId').val(patientId);
                $('#reactivatePatientName').text(patientName);
                $('#adminPassword').val('');

                // Show modal
                $('#reactivateModal').addClass('show');
            });

            // Close reactivate modal handlers
            $(document).on('click', '#closeReactivateModal, #cancelReactivate', function() {
                $('#reactivateModal').removeClass('show');
                $('#adminPassword').val('');
            });

            // Confirm reactivation
            $('#confirmReactivate').on('click', function() {
                const patientId = $('#reactivatePatientId').val();
                const adminPassword = $('#adminPassword').val();

                if (!adminPassword) {
                    showErrorModal('Please enter your admin password');
                    return;
                }

                // Show loading state
                $(this).html('<i class="fas fa-spinner fa-spin"></i> Reactivating...');
                $(this).prop('disabled', true);

                $.ajax({
                    url: window.location.href,
                    method: 'POST',
                    data: {
                        action: 'reactivate_patient',
                        patient_id: patientId,
                        admin_password: adminPassword
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            showSuccessModal('Patient account reactivated successfully! Redirecting to active records...', function() {
                                window.location.href = 'patient_records_management.php?reactivated=1';
                            });
                        } else {
                            showErrorModal('Error: ' + response.message);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error Details:', {
                            status: status,
                            error: error,
                            responseText: xhr.responseText,
                            statusCode: xhr.status
                        });
                        showErrorModal('An error occurred while processing the request. Check console for details.');
                    },
                    complete: function() {
                        $('#confirmReactivate').html('<i class="fas fa-undo"></i> Reactivate Account');
                        $('#confirmReactivate').prop('disabled', false);
                    }
                });
            });

            // Edit Patient Modal functionality removed as per requirements

            // Export button handlers - Placeholder functions
            $('#exportCSV').on('click', function(e) {
                e.preventDefault();
                alert('Export to CSV functionality will be implemented here.');
                // Implementation will go here in future
            });

            $('#exportXLSX').on('click', function(e) {
                e.preventDefault();
                alert('Export to Excel functionality will be implemented here.');
                // Implementation will go here in future
            });

            $('#exportPDF').on('click', function(e) {
                e.preventDefault();
                alert('Export to PDF functionality will be implemented here.');
                // Implementation will go here in future
            });
        });
    </script>
</body>

</html>