<?php
// medical_records.php - Medical Records Management
ob_start(); // Start output buffering to prevent any accidental output

// Load environment configuration for production-friendly error handling
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/env.php';
require_once $root_path . '/config/production_security.php';

// Set error reporting based on environment
if (getenv('APP_DEBUG') === '1') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

// Security headers
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration - Use absolute path resolution
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/auth_helpers.php';

// Check authentication and authorization
$authorized_roles = ['admin', 'dho', 'bhw', 'doctor', 'nurse', 'records_officer'];
require_employee_auth($authorized_roles);

// Database connection
require_once $root_path . '/config/db.php';
// Use dynamic path resolution for assets
require_once $root_path . '/config/paths.php';
$assets_path = defined('WBHSMS_BASE_URL') ? WBHSMS_BASE_URL . '/assets' : 'assets';

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed. Please contact administrator.");
}

$employee_id = $_SESSION['employee_id'];
$employee_role = $_SESSION['role'];

// Handle AJAX patient search requests
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    error_log("AJAX request detected for medical records search");
    if (isset($_GET['action']) && $_GET['action'] === 'search_patients') {
        error_log("Search patients action triggered with search term: " . ($_GET['search'] ?? 'empty'));
        header('Content-Type: application/json');
        
        $search_term = $_GET['search'] ?? '';
        $barangay_filter = $_GET['barangay'] ?? '';
        
        error_log("Search term: '$search_term', Barangay filter: '$barangay_filter'");
        
        try {
            // Get employee's assigned facility for filtering
            $stmt = $conn->prepare("SELECT facility_id FROM employees WHERE employee_id = ?");
            $stmt->bind_param("i", $employee_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $employee_facility = $result->fetch_assoc();
            $stmt->close();
            
            error_log("Employee facility: " . ($employee_facility['facility_id'] ?? 'none'));
            
            // Build the search query - load all patients if no search term
            $search_sql = "
                SELECT DISTINCT 
                    p.patient_id,
                    CONCAT(p.first_name, ' ', p.last_name) as full_name,
                    p.date_of_birth,
                    p.contact_number,
                    b.barangay_name
                FROM patients p
                LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
                WHERE 1=1
            ";
            
            $search_params = [];
            $param_types = "";
            
            // Add search filter only if search term is provided
            if (!empty(trim($search_term))) {
                $search_sql .= " AND (
                    p.first_name LIKE ? OR 
                    p.last_name LIKE ? OR 
                    CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR
                    p.patient_id LIKE ?
                )";
                $search_params = [
                    "%{$search_term}%",
                    "%{$search_term}%", 
                    "%{$search_term}%",
                    "%{$search_term}%"
                ];
                $param_types = "ssss";
                error_log("Added search filter for: $search_term");
            }
            
            // Add barangay filter if specified
            if (!empty($barangay_filter)) {
                $search_sql .= " AND p.barangay_id = ?";
                $search_params[] = $barangay_filter;
                $param_types .= "i";
                error_log("Added barangay filter: $barangay_filter");
            }
            
            // Get total count first - execute the exact same query without LIMIT to get actual result count
            $search_sql_copy = $search_sql . " ORDER BY p.last_name, p.first_name";
            $count_stmt = $conn->prepare($search_sql_copy);
            if (!empty($search_params)) {
                $count_stmt->bind_param($param_types, ...$search_params);
            }
            $count_stmt->execute();
            $count_result = $count_stmt->get_result();
            
            // Count actual rows returned by the exact same query
            $total_patients = 0;
            while ($count_result->fetch_assoc()) {
                $total_patients++;
            }
            $count_stmt->close();
            
            // Add pagination for initial browse (6 patients per page)
            $page = max(1, intval($_GET['page'] ?? 1));
            $per_page = 6; // Show 6 patients per page
            $offset = ($page - 1) * $per_page;
            $total_pages = ceil($total_patients / $per_page);
            
            $search_sql .= " ORDER BY p.last_name, p.first_name LIMIT ? OFFSET ?";
            $search_params[] = $per_page;
            $search_params[] = $offset;
            $param_types .= "ii";
            
            error_log("Final SQL: " . $search_sql);
            error_log("Search params: " . print_r($search_params, true));
            error_log("Pagination: Page $page of $total_pages, Total patients: $total_patients");
            
            $stmt = $conn->prepare($search_sql);
            if (!empty($search_params)) {
                $stmt->bind_param($param_types, ...$search_params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            $patients = [];
            while ($row = $result->fetch_assoc()) {
                $patients[] = $row;
            }
            $stmt->close();
            
            error_log("Found " . count($patients) . " patients on page $page");
            
            echo json_encode([
                'success' => true,
                'patients' => $patients,
                'count' => count($patients),
                'total' => $total_patients,
                'page' => $page,
                'total_pages' => $total_pages,
                'per_page' => $per_page
            ]);
            
        } catch (Exception $e) {
            error_log("Search error: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Search error: ' . $e->getMessage()
            ]);
        }
        
        exit();
    }
} else {
    // Log when AJAX header is missing
    if (isset($_GET['action']) && $_GET['action'] === 'search_patients') {
        error_log("Search action detected but missing AJAX header. Headers: " . print_r($_SERVER, true));
    }
}

// Get barangay name for the logged-in employee based on facility's barangay
$facility_name = 'CHO Koronadal'; // Default fallback
try {
    $stmt = $conn->prepare("
        SELECT b.barangay_name, f.name as facility_name 
        FROM employees e 
        JOIN facilities f ON e.facility_id = f.facility_id 
        LEFT JOIN barangay b ON f.barangay_id = b.barangay_id 
        WHERE e.employee_id = ?
    ");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if ($row) {
        if (!empty($row['barangay_name'])) {
            $facility_name = $row['barangay_name'];
        } elseif (!empty($row['facility_name'])) {
            $facility_name = $row['facility_name'];
        }
    }
    $stmt->close();
} catch (Exception $e) {
    // Keep default facility name if query fails
    error_log("Failed to get barangay name for employee ID {$employee_id}: " . $e->getMessage());
}

// Define role-based permissions for medical records management
$canViewRecords = true; // All authorized roles can view
$canEditRecords = in_array(strtolower($employee_role), ['admin', 'doctor', 'nurse', 'records_officer']);
$canDeleteRecords = in_array(strtolower($employee_role), ['admin']); // Only admin can delete

// Handle status updates and actions
$message = '';
$error = '';

// TODO: Add medical records specific functionality here

// Get filter parameters  
$patient_filter = $_GET['patient'] ?? '';

// Sorting parameters
$sort_column = $_GET['sort'] ?? 'visit_date';
$sort_direction = $_GET['dir'] ?? 'DESC';

// Validate sort parameters
$allowed_columns = [
    'patient_name' => 'p.last_name',
    'patient_id' => 'p.username',
    'visit_date' => 'v.visit_date',
    'visit_status' => 'v.visit_status',
    'facility_name' => 'f.name',
    'created_at' => 'v.created_at'
];

$sort_column = array_key_exists($sort_column, $allowed_columns) ? $sort_column : 'visit_date';
$sort_direction = in_array(strtoupper($sort_direction), ['ASC', 'DESC']) ? strtoupper($sort_direction) : 'DESC';

// Pagination parameters
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = in_array(intval($_GET['per_page'] ?? 10), [10, 25, 50, 100]) ? intval($_GET['per_page'] ?? 10) : 10;
$offset = ($page - 1) * $per_page;

$where_conditions = [];
$params = [];
$param_types = '';

// Role-based medical records restriction - All employees should only see records for their assigned facility
$role = strtolower($employee_role);
$assigned_facility_id = '';

// Get employee's facility_id for all roles
$stmt = $conn->prepare("SELECT facility_id FROM employees WHERE employee_id = ?");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$assigned_facility_id = $row ? $row['facility_id'] : '';
$stmt->close();

// All roles only see medical records for their assigned facility
if ($assigned_facility_id) {
    $where_conditions[] = "v.facility_id = ?";
    $params[] = $assigned_facility_id;
    $param_types .= 'i';
}

// TODO: Add more filter conditions for medical records

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get barangay filter parameter
$barangay_filter = $_GET['barangay'] ?? '';

// Fetch barangays based on user role
$barangays = [];
$user_barangay_id = '';
$user_district_id = '';

try {
    if ($role === 'bhw') {
        // BHW: Get only their facility's barangay (unchangeable)
        $stmt = $conn->prepare("
            SELECT b.barangay_id, b.barangay_name, f.district_id
            FROM employees e 
            JOIN facilities f ON e.facility_id = f.facility_id 
            JOIN barangay b ON f.barangay_id = b.barangay_id 
            WHERE e.employee_id = ?
        ");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $user_barangay_id = $row['barangay_id'];
            $user_district_id = $row['district_id'];
            $barangays[] = $row;
        }
        $stmt->close();
    } elseif ($role === 'dho') {
        // DHO: Get barangays in their district
        $stmt = $conn->prepare("
            SELECT DISTINCT d.district_id
            FROM employees e 
            JOIN facilities f ON e.facility_id = f.facility_id 
            JOIN districts d ON f.district_id = d.district_id 
            WHERE e.employee_id = ?
        ");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $user_district_id = $row['district_id'];
            
            // Get all barangays in this district
            $barangay_stmt = $conn->prepare("
                SELECT barangay_id, barangay_name 
                FROM barangay 
                WHERE district_id = ? 
                ORDER BY barangay_name ASC
            ");
            $barangay_stmt->bind_param("i", $user_district_id);
            $barangay_stmt->execute();
            $barangay_result = $barangay_stmt->get_result();
            $barangays = $barangay_result->fetch_all(MYSQLI_ASSOC);
            $barangay_stmt->close();
        }
        $stmt->close();
    } else {
        // Admin, Doctor, Nurse, Records Officer: Get all barangays in their facility's district
        $stmt = $conn->prepare("
            SELECT f.district_id
            FROM employees e 
            JOIN facilities f ON e.facility_id = f.facility_id 
            WHERE e.employee_id = ?
        ");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $user_district_id = $row['district_id'];
            
            // Get all barangays in this district
            $barangay_stmt = $conn->prepare("
                SELECT barangay_id, barangay_name 
                FROM barangay 
                WHERE district_id = ? 
                ORDER BY barangay_name ASC
            ");
            $barangay_stmt->bind_param("i", $user_district_id);
            $barangay_stmt->execute();
            $barangay_result = $barangay_stmt->get_result();
            $barangays = $barangay_result->fetch_all(MYSQLI_ASSOC);
            $barangay_stmt->close();
        }
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Failed to fetch barangays for employee ID {$employee_id}: " . $e->getMessage());
}

// Statistics placeholder
$stats = [
    'total' => 0,
    'completed' => 0,
    'ongoing' => 0,
    'cancelled' => 0
];

// TODO: Implement statistics query for medical records

// Medical records data placeholder - will be implemented later
$medical_records = [];
$total_records = 0;
$total_pages = 0;

// Helper function for sorting icons
function getSortIcon($column, $current_sort, $current_direction)
{
    if ($column === $current_sort) {
        if ($current_direction === 'ASC') {
            return '<i class="fas fa-sort-up sort-icon active"></i>';
        } else {
            return '<i class="fas fa-sort-down sort-icon active"></i>';
        }
    }
    return '<i class="fas fa-sort sort-icon"></i>';
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <base href="<?php echo WBHSMS_BASE_URL; ?>/">
    <title><?php echo htmlspecialchars($facility_name); ?> — Medical Records</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo WBHSMS_BASE_URL; ?>/assets/css/sidebar.css">
    <!-- CSS Files - loaded by sidebar -->
    <style>
        .content-wrapper {
            padding: 2rem;
            transition: margin-left 0.3s;
        }

        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
                padding: 1rem;
            }
        }

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
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0 15px 0;
            margin-bottom: 15px;
            border-bottom: 1px solid rgba(0, 119, 182, 0.2);
        }

        .card-container {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .filters-container {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #0077b6;
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select {
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
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

        .btn-primary:hover {
            background: linear-gradient(135deg, #023e8a, #001d3d);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268 0%, #495057 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }

        .table th,
        .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .table th {
            background: linear-gradient(135deg, #0077b6, #03045e);
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table th.sortable {
            cursor: pointer;
            user-select: none;
            transition: background-color 0.3s ease;
        }

        .table th.sortable:hover {
            background: linear-gradient(135deg, #005577, #001d3d);
        }

        .sort-icon {
            margin-left: 5px;
            opacity: 0.6;
            font-size: 12px;
            transition: opacity 0.3s ease;
        }

        .sort-icon.active {
            opacity: 1;
            color: #ffd700;
        }

        .table th.sortable:hover .sort-icon {
            opacity: 0.9;
        }

        .table tbody tr:hover {
            background-color: rgba(240, 247, 255, 0.6);
        }

        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: #0077b6;
            opacity: 0.3;
        }

        .empty-state h3 {
            color: #0077b6;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            margin-bottom: 1.5rem;
            font-size: 1rem;
        }

        .breadcrumb {
            background: none;
            padding: 0;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #6c757d;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .breadcrumb a {
            color: #0077b6;
            text-decoration: none;
            font-weight: 500;
        }

        .breadcrumb a:hover {
            color: #023e8a;
        }

        .breadcrumb i {
            color: #6c757d;
            font-size: 0.8rem;
        }

        .alert {
            padding: 1rem 1.25rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            border-left: 4px solid;
            background: white;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            position: relative;
            z-index: 1050;
        }

        .alert-success {
            border-left-color: #28a745;
            color: #155724;
        }

        .alert-error {
            border-left-color: #dc3545;
            color: #721c24;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-primary {
            background-color: #0077b6;
            color: white;
        }

        .badge-success {
            background-color: #28a745;
            color: white;
        }

        .badge-info {
            background-color: #17a2b8;
            color: white;
        }

        .badge-warning {
            background-color: #ffc107;
            color: #212529;
        }

        .badge-danger {
            background-color: #dc3545;
            color: white;
        }

        .badge-secondary {
            background-color: #6c757d;
            color: white;
        }

        .profile-img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .actions-group {
            display: flex;
            gap: 5px;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        /* Navigation Tabs for Medical History */
        .settings-nav {
            display: flex;
            background: white;
            border-radius: 12px;
            padding: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow-x: auto;
            border: 1px solid #e9ecef;
        }

        .nav-tab {
            flex: 1;
            min-width: 160px;
            padding: 12px 20px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: #6b7280;
            text-decoration: none;
            border: none;
            background: none;
            font-size: 0.9rem;
        }

        .nav-tab:hover {
            background: #f3f4f6;
            color: #374151;
        }

        .nav-tab.active {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            box-shadow: 0 2px 8px rgba(0, 119, 182, 0.3);
        }

        .nav-tab i {
            margin-right: 8px;
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Patient Selection Table */
        .patient-row {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .patient-row:hover {
            background-color: rgba(0, 119, 182, 0.1) !important;
        }

        .patient-row.selected {
            background-color: rgba(0, 119, 182, 0.15) !important;
        }

        .patient-radio {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        /* Pagination Styles */
        .pagination-wrapper {
            background: #f8f9fa;
        }

        .pagination-buttons .btn {
            min-width: 35px;
            height: 35px;
            padding: 6px 12px;
            font-size: 0.85rem;
        }

        .page-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 35px;
            height: 35px;
            padding: 6px 12px;
            margin: 0 2px;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            background: white;
            color: #6c757d;
            text-decoration: none;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .page-number:hover {
            background: #e9ecef;
            color: #495057;
        }

        .page-number.active {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            border-color: #0077b6;
            font-weight: 600;
        }

        .btn-selected-mode {
            background: linear-gradient(135deg, #28a745, #155724);
            color: white;
            border: none;
        }

        .btn-selected-mode:hover {
            background: linear-gradient(135deg, #155724, #0f4419);
        }

        /* Responsive tabs */
        @media (max-width: 768px) {
            .settings-nav {
                flex-wrap: wrap;
                padding: 6px;
            }
            
            .nav-tab {
                min-width: calc(50% - 4px);
                margin: 2px;
                padding: 10px 8px;
                font-size: 0.8rem;
            }
            
            .nav-tab i {
                margin-right: 4px;
            }
        }

        @media (max-width: 480px) {
            .nav-tab {
                min-width: 100%;
                margin: 2px 0;
            }
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(2px);
        }

        /* Loader Animation */
        .loader {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #0077b6;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .modal-content {
            background-color: #fefefe;
            margin: 2% auto;
            padding: 0;
            border: none;
            border-radius: 12px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
        }

        .modal-content-large {
            max-width: 1000px;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-30px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 20px 25px;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-body {
            padding: 25px;
            max-height: calc(90vh - 120px);
            overflow-y: auto;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.3s ease;
            line-height: 1;
            padding: 5px;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .close:hover {
            opacity: 1;
            background: rgba(255, 255, 255, 0.1);
        }

        /* Appointment Details Styles */
        .appointment-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .details-section {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .details-section h4 {
            margin: 0 0 15px 0;
            color: #0077b6;
            font-size: 1.1rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 8px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
            gap: 15px;
            text-align: left;
        }

        .detail-label {
            font-weight: 600;
            color: #6c757d;
            font-size: 0.9rem;
            min-width: 120px;
            flex-shrink: 0;
        }

        .detail-value {
            color: #495057;
            font-size: 0.9rem;
            text-align: right;
            flex-grow: 1;
            word-break: break-word;
        }

        .detail-value.highlight {
            background: #e3f2fd;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            color: #1565c0;
        }

        .qr-code-container {
            text-align: center;
            margin: 15px 0;
        }

        .qr-code-container img {
            max-width: 150px;
            max-height: 150px;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .qr-code-description {
            margin-top: 8px;
            font-size: 0.85rem;
            color: #6c757d;
        }

        .vitals-group {
            background: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 10px;
        }

        .vitals-group h6 {
            margin: 0 0 8px 0;
            font-weight: 600;
            color: #0077b6;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.9rem;
        }

        .vital-status {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 2px;
            margin-left: 8px;
        }

        .vital-status.normal {
            background-color: #d4edda;
            color: #155724;
        }

        .vital-status.high {
            background-color: #fff3cd;
            color: #856404;
        }

        .vital-status.low {
            background-color: #cce8f4;
            color: #055160;
        }

        .vital-status.critical {
            background-color: #f8d7da;
            color: #721c24;
        }

        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
            
            .appointment-details-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .modal-header {
                padding: 15px 20px;
            }
            
            .modal-body {
                padding: 20px;
            }
            
            .detail-item {
                flex-direction: column;
                gap: 5px;
            }
            
            .detail-label {
                min-width: auto;
                font-size: 0.8rem;
            }
            
            .detail-value {
                text-align: left;
                font-size: 0.85rem;
            }
        }

        /* Referral Modal Styles - matching referrals_management.php */
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

        /* Medication item styling for prescriptions */
        .medication-item {
            display: block !important;
            margin-bottom: 1.2rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #0077b6;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .medication-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.7rem;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .medication-name {
            margin: 0;
            color: #0077b6;
            font-weight: 600;
            font-size: 1.1rem;
            flex: 1;
            min-width: 200px;
            text-align: left;
        }

        .medication-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.7rem;
            margin-bottom: 0.7rem;
        }

        .medication-detail {
            background: white;
            padding: 0.5rem;
            border-radius: 4px;
            border: 1px solid #e9ecef;
        }

        .medication-detail strong {
            color: #495057;
            font-size: 0.9rem;
        }

        .medication-instructions {
            background: #e3f2fd;
            padding: 0.7rem;
            border-radius: 4px;
            border-left: 3px solid #2196f3;
            margin-top: 0.5rem;
        }

        .medication-instructions strong {
            color: #1976d2;
        }

        @media (max-width: 768px) {
            .medication-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .medication-details {
                grid-template-columns: 1fr;
                gap: 0.5rem;
            }
            
            .medication-name {
                min-width: auto;
                margin-bottom: 0.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="homepage">

        <?php
        $activePage = 'medical_records';
        // Include appropriate sidebar based on user role
        switch (strtolower($_SESSION['role'])) {
            case 'dho':
                include $root_path . '/includes/sidebar_dho.php';
                break;
            case 'bhw':
                include $root_path . '/includes/sidebar_bhw.php';
                break;
            case 'doctor':
                include $root_path . '/includes/sidebar_doctor.php';
                break;
            case 'nurse':
                include $root_path . '/includes/sidebar_nurse.php';
                break;
            case 'records_officer':
                include $root_path . '/includes/sidebar_records_officer.php';
                break;
            case 'admin':
            default:
                include $root_path . '/includes/sidebar_admin.php';
                break;
        }
        ?>

        <div class="content-wrapper">
            <!-- Breadcrumb Navigation -->
            <div class="breadcrumb" style="margin-top: 50px;">
                <?php
                // Use production-safe dashboard URLs
                $user_role = strtolower($_SESSION['role']);
                $dashboard_path = get_role_dashboard_url($user_role);
                ?>
                <a href="<?php echo htmlspecialchars($dashboard_path); ?>"><i class="fas fa-home"></i> Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <span> Medical History</span>
            </div>

            <div class="page-header">
                <h1><i class="fas fa-file-medical"></i> Medical History</h1>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="filters-container">
                <div class="section-header" style="margin-bottom: 15px;">
                    <h4 style="margin: 0;color: var(--primary-dark);font-size: 18px;font-weight: 600;">
                        <i class="fas fa-filter"></i> Search &amp; Filter Options
                    </h4>
                </div>
                <div class="filters-grid">
                    <div class="form-group">
                        <label for="patient">Search Patient</label>
                        <input type="text" name="patient" id="patient"
                            value="<?php echo htmlspecialchars($patient_filter); ?>"
                            placeholder="Search by Patient ID, Name...">
                    </div>

                    <div class="form-group">
                        <label for="barangay">Barangay</label>
                        <?php if ($role === 'bhw' && !empty($barangays)): ?>
                            <!-- BHW: Show only their barangay, unchangeable -->
                            <input type="text" value="<?php echo htmlspecialchars($barangays[0]['barangay_name']); ?>" 
                                   class="form-control" readonly style="background-color: #f8f9fa; cursor: not-allowed;">
                            <input type="hidden" name="barangay" id="barangay" value="<?php echo $barangays[0]['barangay_id']; ?>">
                        <?php else: ?>
                            <!-- DHO and other roles: Dropdown -->
                            <select name="barangay" id="barangay">
                                <option value="">All Barangays</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo $barangay['barangay_id']; ?>"
                                        <?php echo $barangay_filter == $barangay['barangay_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay['barangay_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div style="display: flex; gap: 10px;">
                            <button type="button" class="btn btn-primary" onclick="performPatientSearch()">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="clearSearch()">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Patient Search Results -->
            <div id="patientSearchSection" class="card-container" style="display: none;">
                <div class="section-header">
                    <h4 style="margin: 0;color: var(--primary-dark);font-size: 18px;font-weight: 600;">
                        <i class="fas fa-users"></i> Select Patient
                        <span id="patientCount" style="font-size: 0.8em; color: #6c757d; font-weight: normal;"></span>
                    </h4>
                    <div id="browseControls" style="display: none;">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="showAllPatients()">
                            <i class="fas fa-list"></i> Browse All Patients
                        </button>
                    </div>
                </div>
                <div class="table-container">
                    <div class="table-wrapper">
                        <table class="table" id="patientTable">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">Select</th>
                                    <th class="sortable" onclick="sortPatientTable('patient_id')">
                                        Patient ID
                                        <i class="fas fa-sort sort-icon" id="sort-patient_id"></i>
                                    </th>
                                    <th class="sortable" onclick="sortPatientTable('full_name')">
                                        Patient Name
                                        <i class="fas fa-sort sort-icon" id="sort-full_name"></i>
                                    </th>
                                    <th class="sortable" onclick="sortPatientTable('date_of_birth')">
                                        Date of Birth
                                        <i class="fas fa-sort sort-icon" id="sort-date_of_birth"></i>
                                    </th>
                                    <th class="sortable" onclick="sortPatientTable('contact_number')">
                                        Contact Number
                                        <i class="fas fa-sort sort-icon" id="sort-contact_number"></i>
                                    </th>
                                    <th class="sortable" onclick="sortPatientTable('barangay_name')">
                                        Barangay
                                        <i class="fas fa-sort sort-icon" id="sort-barangay_name"></i>
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="patientTableBody">
                                <!-- Patient search results will be populated here -->
                            </tbody>
                        </table>
                        
                        <!-- Pagination Controls -->
                        <div id="paginationControls" class="pagination-wrapper" style="display: none; padding: 15px; text-align: center; border-top: 1px solid #f0f0f0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                                <div class="pagination-info" style="color: #6c757d; font-size: 0.9rem;">
                                    <span id="paginationInfo">Showing 1-6 of 10 patients</span>
                                </div>
                                <div class="pagination-buttons" style="display: flex; gap: 5px;">
                                    <button type="button" class="btn btn-sm btn-secondary" id="prevPage" onclick="changePage(-1)" disabled>
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </button>
                                    <span id="pageNumbers" style="display: flex; gap: 3px; margin: 0 10px;"></span>
                                    <button type="button" class="btn btn-sm btn-secondary" id="nextPage" onclick="changePage(1)">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div id="patientSearchEmpty" class="empty-state" style="display: none;">
                            <i class="fas fa-search"></i>
                            <h3>Search for patients</h3>
                            <p>Enter a patient name or ID above to find medical records.</p>
                        </div>
                        <div id="patientSearchNoResults" class="empty-state" style="display: none;">
                            <i class="fas fa-user-slash"></i>
                            <h3>No patients found</h3>
                            <p>No patients match your search criteria. Try different keywords.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Medical History Navigation Tabs -->
            <div id="medicalHistorySection" class="card-container" style="display: none;">
                <div class="section-header">
                    <h4 style="margin: 0;color: var(--primary-dark);font-size: 18px;font-weight: 600;">
                        <i class="fas fa-file-medical-alt"></i> Medical History - <span id="selectedPatientName">Patient Name</span>
                    </h4>
                </div>
                
                <!-- Navigation Tabs -->
                <div class="settings-nav">
                    <button class="nav-tab active" onclick="showHistoryTab('appointments', this)">
                        <i class="fas fa-calendar-alt"></i> Appointments
                    </button>
                    <button class="nav-tab" onclick="showHistoryTab('referrals', this)">
                        <i class="fas fa-share"></i> Referrals
                    </button>
                    <button class="nav-tab" onclick="showHistoryTab('consultations', this)">
                        <i class="fas fa-stethoscope"></i> Consultations
                    </button>
                    <button class="nav-tab" onclick="showHistoryTab('prescriptions', this)">
                        <i class="fas fa-prescription-bottle"></i> Prescriptions
                    </button>
                    <button class="nav-tab" onclick="showHistoryTab('laboratory', this)">
                        <i class="fas fa-flask"></i> Laboratory
                    </button>
                    <button class="nav-tab" onclick="showHistoryTab('billing', this)">
                        <i class="fas fa-file-invoice-dollar"></i> Billing
                    </button>
                </div>

                <!-- Tab Content: Appointment History -->
                <div id="appointments" class="tab-content active">
                    <div class="table-container">
                        <div class="table-wrapper">
                            <div id="appointmentsLoading" class="empty-state" style="display: none;">
                                <i class="fas fa-spinner fa-spin"></i>
                                <h3>Loading Appointments...</h3>
                                <p>Please wait while we load the appointment history.</p>
                            </div>
                            <div id="appointmentsEmpty" class="empty-state" style="display: none;">
                                <i class="fas fa-calendar-times"></i>
                                <h3>No Appointments Found</h3>
                                <p>No appointment history available for this patient.</p>
                            </div>
                            <div id="appointmentsTable" style="display: none;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th class="sortable" onclick="sortAppointmentsTable('appointment_id')">
                                                Appointment ID
                                                <i class="fas fa-sort sort-icon" id="sort-appointment_id"></i>
                                            </th>
                                            <th class="sortable" onclick="sortAppointmentsTable('facility_name')">
                                                Facility
                                                <i class="fas fa-sort sort-icon" id="sort-facility_name"></i>
                                            </th>
                                            <th class="sortable" onclick="sortAppointmentsTable('scheduled_date')">
                                                Date
                                                <i class="fas fa-sort sort-icon" id="sort-scheduled_date"></i>
                                            </th>
                                            <th class="sortable" onclick="sortAppointmentsTable('scheduled_time')">
                                                Time
                                                <i class="fas fa-sort sort-icon" id="sort-scheduled_time"></i>
                                            </th>
                                            <th class="sortable" onclick="sortAppointmentsTable('status')">
                                                Status
                                                <i class="fas fa-sort sort-icon" id="sort-status"></i>
                                            </th>
                                            <th style="width: 100px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="appointmentsTableBody">
                                        <!-- Appointment data will be populated here -->
                                    </tbody>
                                </table>
                                
                                <!-- Appointments Pagination Controls -->
                                <div id="appointmentsPaginationControls" class="pagination-wrapper" style="display: none; padding: 15px; text-align: center; border-top: 1px solid #f0f0f0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                                        <div class="pagination-info" style="color: #6c757d; font-size: 0.9rem;">
                                            <span id="appointmentsPaginationInfo">Showing appointments</span>
                                        </div>
                                        <div class="pagination-buttons" style="display: flex; gap: 5px;">
                                            <button type="button" class="btn btn-sm btn-secondary" id="appointmentsPrevPage" onclick="changeAppointmentsPage(-1)" disabled>
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </button>
                                            <span id="appointmentsPageNumbers" style="display: flex; gap: 3px; margin: 0 10px;"></span>
                                            <button type="button" class="btn btn-sm btn-secondary" id="appointmentsNextPage" onclick="changeAppointmentsPage(1)">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Referral History -->
                <div id="referrals" class="tab-content">
                    <div class="table-container">
                        <div class="table-wrapper">
                            <div id="referralsLoading" class="empty-state" style="display: none;">
                                <i class="fas fa-spinner fa-spin"></i>
                                <h3>Loading Referrals...</h3>
                                <p>Please wait while we load the referral history.</p>
                            </div>
                            <div id="referralsEmpty" class="empty-state" style="display: none;">
                                <i class="fas fa-share-alt"></i>
                                <h3>No Referrals Found</h3>
                                <p>No referral history available for this patient.</p>
                            </div>
                            <div id="referralsTable" style="display: none;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th class="sortable" onclick="sortReferralsTable('referral_num')">
                                                Referral #
                                                <i class="fas fa-sort sort-icon" id="sort-referral_num"></i>
                                            </th>
                                            <th class="sortable" onclick="sortReferralsTable('referring_facility_name')">
                                                From Facility
                                                <i class="fas fa-sort sort-icon" id="sort-referring_facility_name"></i>
                                            </th>
                                            <th class="sortable" onclick="sortReferralsTable('referred_to_facility_name')">
                                                To Facility
                                                <i class="fas fa-sort sort-icon" id="sort-referred_to_facility_name"></i>
                                            </th>
                                            <th class="sortable" onclick="sortReferralsTable('service_name')">
                                                Service
                                                <i class="fas fa-sort sort-icon" id="sort-service_name"></i>
                                            </th>
                                            <th class="sortable" onclick="sortReferralsTable('referral_date')">
                                                Date
                                                <i class="fas fa-sort sort-icon" id="sort-referral_date"></i>
                                            </th>
                                            <th class="sortable" onclick="sortReferralsTable('status')">
                                                Status
                                                <i class="fas fa-sort sort-icon" id="sort-status"></i>
                                            </th>
                                            <th style="width: 120px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="referralsTableBody">
                                        <!-- Referral data will be populated here -->
                                    </tbody>
                                </table>
                                
                                <!-- Referrals Pagination Controls -->
                                <div id="referralsPaginationControls" class="pagination-wrapper" style="display: none; padding: 15px; text-align: center; border-top: 1px solid #f0f0f0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                                        <div class="pagination-info" style="color: #6c757d; font-size: 0.9rem;">
                                            <span id="referralsPaginationInfo">Showing referrals</span>
                                        </div>
                                        <div class="pagination-buttons" style="display: flex; gap: 5px;">
                                            <button type="button" class="btn btn-sm btn-secondary" id="referralsPrevPage" onclick="changeReferralsPage(-1)" disabled>
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </button>
                                            <span id="referralsPageNumbers" style="display: flex; gap: 3px; margin: 0 10px;"></span>
                                            <button type="button" class="btn btn-sm btn-secondary" id="referralsNextPage" onclick="changeReferralsPage(1)">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Consultation History -->
                <div id="consultations" class="tab-content">
                    <div class="table-container">
                        <div class="table-wrapper">
                            <div id="consultationsLoading" class="empty-state" style="display: none;">
                                <i class="fas fa-spinner fa-spin"></i>
                                <h3>Loading Consultations...</h3>
                                <p>Please wait while we load the consultation history.</p>
                            </div>
                            <div id="consultationsEmpty" class="empty-state" style="display: none;">
                                <i class="fas fa-stethoscope"></i>
                                <h3>No Consultations Found</h3>
                                <p>No consultation records found for this patient.</p>
                            </div>
                            <div id="consultationsTable" style="display: none;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th class="sortable" onclick="sortConsultationsTable('consultation_date')">
                                                Date
                                                <i class="fas fa-sort sort-icon" id="sort-consultation_date"></i>
                                            </th>
                                            <th class="sortable" onclick="sortConsultationsTable('doctor_name')">
                                                Doctor
                                                <i class="fas fa-sort sort-icon" id="sort-doctor_name"></i>
                                            </th>
                                            <th class="sortable" onclick="sortConsultationsTable('service_name')">
                                                Service
                                                <i class="fas fa-sort sort-icon" id="sort-service_name"></i>
                                            </th>
                                            <th class="sortable" onclick="sortConsultationsTable('chief_complaint')">
                                                Chief Complaint
                                                <i class="fas fa-sort sort-icon" id="sort-chief_complaint"></i>
                                            </th>
                                            <th class="sortable" onclick="sortConsultationsTable('diagnosis')">
                                                Diagnosis
                                                <i class="fas fa-sort sort-icon" id="sort-diagnosis"></i>
                                            </th>
                                            <th class="sortable" onclick="sortConsultationsTable('status')">
                                                Status
                                                <i class="fas fa-sort sort-icon" id="sort-status"></i>
                                            </th>
                                            <th style="width: 100px;">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="consultationsTableBody">
                                        <!-- Consultation data will be populated here -->
                                    </tbody>
                                </table>
                                
                                <!-- Consultations Pagination Controls -->
                                <div id="consultationsPaginationControls" class="pagination-wrapper" style="display: none; padding: 15px; text-align: center; border-top: 1px solid #f0f0f0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                                        <div class="pagination-info" style="color: #6c757d; font-size: 0.9rem;">
                                            <span id="consultationsPaginationInfo">Showing consultations</span>
                                        </div>
                                        <div class="pagination-buttons" style="display: flex; gap: 5px;">
                                            <button type="button" class="btn btn-sm btn-secondary" id="consultationsPrevPage" onclick="changeConsultationsPage(-1)" disabled>
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </button>
                                            <span id="consultationsPageNumbers" style="display: flex; gap: 3px; margin: 0 10px;"></span>
                                            <button type="button" class="btn btn-sm btn-secondary" id="consultationsNextPage" onclick="changeConsultationsPage(1)">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Prescription History -->
                <div id="prescriptions" class="tab-content">
                    <div class="table-container">
                        <div class="table-wrapper">
                            <div id="prescriptionsLoading" class="empty-state" style="display: none;">
                                <i class="fas fa-spinner fa-spin"></i>
                                <h3>Loading Prescriptions...</h3>
                                <p>Please wait while we load the prescription history.</p>
                            </div>
                            <div id="prescriptionsEmpty" class="empty-state" style="display: none;">
                                <i class="fas fa-pills"></i>
                                <h3>No Prescriptions Found</h3>
                                <p>No prescription history available for this patient.</p>
                            </div>
                            <div id="prescriptionsTable" style="display: none;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th style="width: 120px;" class="sortable" onclick="sortPrescriptionsTable('prescription_id')">
                                                Prescription ID
                                                <i class="fas fa-sort sort-icon" id="sort-prescription_id"></i>
                                            </th>
                                            <th style="width: 130px;" class="sortable" onclick="sortPrescriptionsTable('prescription_date')">
                                                Date Prescribed
                                                <i class="fas fa-sort sort-icon" id="sort-prescription_date"></i>
                                            </th>
                                            <th style="width: 150px;" class="sortable" onclick="sortPrescriptionsTable('prescribed_by_doctor')">
                                                Prescribed By
                                                <i class="fas fa-sort sort-icon" id="sort-prescribed_by_doctor"></i>
                                            </th>
                                            <th style="width: 120px;">
                                                Medications
                                            </th>
                                            <th style="width: 100px;" class="sortable" onclick="sortPrescriptionsTable('status')">
                                                Status
                                                <i class="fas fa-sort sort-icon" id="sort-status"></i>
                                            </th>
                                            <th style="width: 100px;">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody id="prescriptionsTableBody">
                                        <!-- Prescription data will be populated here -->
                                    </tbody>
                                </table>
                                
                                <!-- Prescriptions Pagination Controls -->
                                <div id="prescriptionsPaginationControls" class="pagination-wrapper" style="display: none; padding: 15px; text-align: center; border-top: 1px solid #f0f0f0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                                        <div class="pagination-info" style="color: #6c757d; font-size: 0.9rem;">
                                            <span id="prescriptionsPaginationInfo">Showing 1-10 of 20 prescriptions</span>
                                        </div>
                                        <div class="pagination-buttons" style="display: flex; gap: 5px;">
                                            <button type="button" class="btn btn-sm btn-secondary" id="prescriptionsPrevPage" onclick="changePrescriptionsPage(-1)" disabled>
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </button>
                                            <span id="prescriptionsPageNumbers" style="display: flex; gap: 3px; margin: 0 10px;"></span>
                                            <button type="button" class="btn btn-sm btn-secondary" id="prescriptionsNextPage" onclick="changePrescriptionsPage(1)">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Laboratory History -->
                <div id="laboratory" class="tab-content">
                    <div class="table-container">
                        <div class="table-wrapper">
                            <div id="laboratoryLoading" class="empty-state" style="display: none;">
                                <i class="fas fa-spinner fa-spin"></i>
                                <h3>Loading Laboratory Tests...</h3>
                                <p>Please wait while we load the laboratory test history.</p>
                            </div>
                            <div id="laboratoryEmpty" class="empty-state" style="display: none;">
                                <i class="fas fa-flask"></i>
                                <h3>No Laboratory Tests Found</h3>
                                <p>No laboratory test history available for this patient.</p>
                            </div>
                            <div id="laboratoryTable" style="display: none;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th style="width: 120px;" class="sortable" onclick="sortLaboratoryTable('lab_order_id')">
                                                Order ID
                                                <i class="fas fa-sort sort-icon" id="sort-lab_order_id"></i>
                                            </th>
                                            <th style="width: 130px;" class="sortable" onclick="sortLaboratoryTable('order_date')">
                                                Order Date
                                                <i class="fas fa-sort sort-icon" id="sort-order_date"></i>
                                            </th>
                                            <th style="width: 150px;" class="sortable" onclick="sortLaboratoryTable('ordered_by_name')">
                                                Ordered By
                                                <i class="fas fa-sort sort-icon" id="sort-ordered_by_name"></i>
                                            </th>
                                            <th style="width: 120px;">
                                                Test Count
                                            </th>
                                            <th style="width: 120px;" class="sortable" onclick="sortLaboratoryTable('overall_status')">
                                                Status
                                                <i class="fas fa-sort sort-icon" id="sort-overall_status"></i>
                                            </th>
                                            <th style="width: 140px;">
                                                Progress
                                            </th>
                                            <th style="width: 100px;">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody id="laboratoryTableBody">
                                        <!-- Laboratory data will be populated here -->
                                    </tbody>
                                </table>
                                
                                <!-- Laboratory Pagination Controls -->
                                <div id="laboratoryPaginationControls" class="pagination-wrapper" style="display: none; padding: 15px; text-align: center; border-top: 1px solid #f0f0f0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                                        <div class="pagination-info" style="color: #6c757d; font-size: 0.9rem;">
                                            <span id="laboratoryPaginationInfo">Showing laboratory orders</span>
                                        </div>
                                        <div class="pagination-buttons" style="display: flex; gap: 5px;">
                                            <button type="button" class="btn btn-sm btn-secondary" id="laboratoryPrevPage" onclick="changeLaboratoryPage(-1)" disabled>
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </button>
                                            <span id="laboratoryPageNumbers" style="display: flex; gap: 3px; margin: 0 10px;"></span>
                                            <button type="button" class="btn btn-sm btn-secondary" id="laboratoryNextPage" onclick="changeLaboratoryPage(1)">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Billing History -->
                <div id="billing" class="tab-content">
                    <div class="table-container">
                        <div class="table-wrapper">
                            <div id="billingLoading" class="empty-state" style="display: none;">
                                <i class="fas fa-spinner fa-spin"></i>
                                <h3>Loading Billing History...</h3>
                                <p>Please wait while we load the billing records.</p>
                            </div>
                            <div id="billingEmpty" class="empty-state" style="display: none;">
                                <i class="fas fa-receipt"></i>
                                <h3>No Billing Records Found</h3>
                                <p>No billing history available for this patient.</p>
                            </div>
                            <div id="billingTable" style="display: none;">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th style="width: 120px;" class="sortable" onclick="sortBillingTable('billing_id')">
                                                Billing ID
                                                <i class="fas fa-sort sort-icon" id="sort-billing_id"></i>
                                            </th>
                                            <th style="width: 130px;" class="sortable" onclick="sortBillingTable('billing_date')">
                                                Billing Date
                                                <i class="fas fa-sort sort-icon" id="sort-billing_date"></i>
                                            </th>
                                            <th style="width: 120px;" class="sortable" onclick="sortBillingTable('total_amount')">
                                                Total Amount
                                                <i class="fas fa-sort sort-icon" id="sort-total_amount"></i>
                                            </th>
                                            <th style="width: 120px;" class="sortable" onclick="sortBillingTable('net_amount')">
                                                Net Amount
                                                <i class="fas fa-sort sort-icon" id="sort-net_amount"></i>
                                            </th>
                                            <th style="width: 120px;" class="sortable" onclick="sortBillingTable('paid_amount')">
                                                Paid Amount
                                                <i class="fas fa-sort sort-icon" id="sort-paid_amount"></i>
                                            </th>
                                            <th style="width: 100px;" class="sortable" onclick="sortBillingTable('payment_status')">
                                                Status
                                                <i class="fas fa-sort sort-icon" id="sort-payment_status"></i>
                                            </th>
                                            <th style="width: 100px;">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody id="billingTableBody">
                                        <!-- Billing data will be populated here -->
                                    </tbody>
                                </table>
                                
                                <!-- Billing Pagination Controls -->
                                <div id="billingPaginationControls" class="pagination-wrapper" style="display: none; padding: 15px; text-align: center; border-top: 1px solid #f0f0f0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                                        <div class="pagination-info" style="color: #6c757d; font-size: 0.9rem;">
                                            <span id="billingPaginationInfo">Showing billing records</span>
                                        </div>
                                        <div class="pagination-buttons" style="display: flex; gap: 5px;">
                                            <button type="button" class="btn btn-sm btn-secondary" id="billingPrevPage" onclick="changeBillingPage(-1)" disabled>
                                                <i class="fas fa-chevron-left"></i> Previous
                                            </button>
                                            <span id="billingPageNumbers" style="display: flex; gap: 3px; margin: 0 10px;"></span>
                                            <button type="button" class="btn btn-sm btn-secondary" id="billingNextPage" onclick="changeBillingPage(1)">
                                                Next <i class="fas fa-chevron-right"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Appointment Modal -->
    <div id="viewAppointmentModal" class="modal" style="display: none;">
        <div class="modal-content modal-content-large">
            <div class="modal-header">
                <h3><i class="fas fa-calendar-check"></i> Appointment Details</h3>
                <div class="modal-actions">
                    <button onclick="printAppointmentDetails()" class="btn btn-secondary btn-sm" title="Print Details">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <span class="close" onclick="closeModal('viewAppointmentModal')">&times;</span>
                </div>
            </div>
            <div class="modal-body">
                <div id="appointmentDetailsContent">
                    <!-- Appointment details will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- View Referral Modal -->
    <div id="viewReferralModal" class="modal" style="display: none;">
        <div class="modal-content modal-content-large">
            <div class="modal-header">
                <h3><i class="fas fa-share"></i> Referral Details</h3>
                <div class="modal-actions">
                    <button onclick="printReferralDetails()" class="btn btn-secondary btn-sm" title="Print Details">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <span class="close" onclick="closeModal('viewReferralModal')">&times;</span>
                </div>
            </div>
            <div class="modal-body">
                <div id="referralDetailsContent">
                    <!-- Referral details will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Consultation Details Modal -->
    <div id="viewConsultationModal" class="modal" style="display: none;">
        <div class="modal-content modal-content-large">
            <div class="modal-header">
                <h3><i class="fas fa-stethoscope"></i> Consultation Details</h3>
                <div class="modal-actions">
                    <button onclick="printConsultationDetails()" class="btn btn-secondary btn-sm" title="Print Details">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <span class="close" onclick="closeModal('viewConsultationModal')">&times;</span>
                </div>
            </div>
            <div class="modal-body">
                <div id="consultationDetailsContent">
                    <!-- Consultation details will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Prescription Details Modal -->
    <div id="viewPrescriptionModal" class="modal" style="display: none;">
        <div class="modal-content modal-content-large">
            <div class="modal-header">
                <h3><i class="fas fa-pills"></i> Prescription Details</h3>
                <div class="modal-actions">
                    <button onclick="printPrescriptionDetails()" class="btn btn-secondary btn-sm" title="Print Details">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <span class="close" onclick="closeModal('viewPrescriptionModal')">&times;</span>
                </div>
            </div>
            <div class="modal-body">
                <div id="prescriptionDetailsContent">
                    <!-- Prescription details will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Billing Details Modal -->
    <div id="viewBillingModal" class="modal" style="display: none;">
        <div class="modal-content modal-content-large">
            <div class="modal-header">
                <h3><i class="fas fa-file-invoice-dollar"></i> Billing Details</h3>
                <div class="modal-actions">
                    <button onclick="printBillingDetails()" class="btn btn-secondary btn-sm" title="Print Invoice">
                        <i class="fas fa-print"></i> Print Invoice
                    </button>
                    <span class="close" onclick="closeModal('viewBillingModal')">&times;</span>
                </div>
            </div>
            <div class="modal-body">
                <div id="billingDetailsContent">
                    <!-- Billing details will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <!-- Laboratory Details Modal -->
    <div id="viewLaboratoryModal" class="modal" style="display: none;">
        <div class="modal-content modal-content-large">
            <div class="modal-header">
                <h3><i class="fas fa-flask"></i> Laboratory Test Details</h3>
                <div class="modal-actions">
                    <button onclick="printLaboratoryDetails()" class="btn btn-secondary btn-sm" title="Print Lab Report">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                    <span class="close" onclick="closeModal('viewLaboratoryModal')">&times;</span>
                </div>
            </div>
            <div class="modal-body">
                <div id="laboratoryDetailsContent">
                    <!-- Laboratory details will be loaded here -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Dynamic configuration - works in any environment
        const APP_BASE_PATH = '<?php 
            $uri = $_SERVER["REQUEST_URI"];
            // Remove the query string if present
            $uri = strtok($uri, "?");
            // Get the base path by removing /pages/medical-records/medical_records.php
            $basePath = substr($uri, 0, strpos($uri, "/pages/"));
            echo $basePath;
        ?>';
        const APP_CONFIG = {
            basePath: APP_BASE_PATH,
            apiPath: function(endpoint) {
                return this.basePath + '/api/' + endpoint;
            }
        };
        
        let selectedPatientId = null;
        let selectedPatientName = '';
        let currentPage = 1;
        let totalPages = 1;
        let totalPatients = 0;
        let isPatientSelected = false;
        let currentSearchTerm = '';
        let currentBarangayFilter = '';
        let currentSortColumn = '';
        let currentSortDirection = 'ASC';
        let currentPatients = []; // Store current patient data for sorting
        
        // Appointments pagination and sorting variables
        let currentAppointments = [];
        let currentAppointmentsPage = 1;
        let appointmentsPerPage = 10;
        let totalAppointments = 0;
        let totalAppointmentsPages = 1;
        let appointmentsSortColumn = 'scheduled_date';
        let appointmentsSortDirection = 'DESC';
        
        // Referrals pagination and sorting variables
        let currentReferrals = [];
        let currentReferralsPage = 1;
        let referralsPerPage = 10;
        let totalReferrals = 0;
        let totalReferralsPages = 1;
        let referralsSortColumn = 'referral_date';
        let referralsSortDirection = 'DESC';
        
        // Consultations pagination and sorting variables
        let currentConsultations = [];
        let currentConsultationsPage = 1;
        let consultationsPerPage = 10;
        let totalConsultations = 0;
        let totalConsultationsPages = 1;
        let consultationsSortColumn = 'consultation_date';
        let consultationsSortDirection = 'DESC';
        
        // Prescriptions pagination and sorting variables
        let currentPrescriptions = [];
        let currentPrescriptionsPage = 1;
        let prescriptionsPerPage = 10;
        let totalPrescriptions = 0;
        let totalPrescriptionsPages = 1;
        let prescriptionsSortColumn = 'prescription_date';
        let prescriptionsSortDirection = 'DESC';
        
        // Billing pagination and sorting variables
        let currentBilling = [];
        let currentBillingPage = 1;
        let billingPerPage = 10;
        let totalBilling = 0;
        let totalBillingPages = 1;
        let billingSortColumn = 'billing_date';
        let billingSortDirection = 'DESC';
        
        // Laboratory pagination and sorting variables
        let currentLaboratory = [];
        let currentLaboratoryPage = 1;
        let laboratoryPerPage = 10;
        let totalLaboratory = 0;
        let totalLaboratoryPages = 1;
        let laboratorySortColumn = 'order_date';
        let laboratorySortDirection = 'DESC';
        
        // Current data for print functions (signatory information)
        let currentAppointmentData = null;
        let currentReferralData = null;
        let currentConsultationData = null;
        let currentPrescriptionData = null;
        let currentBillingData = null;
        let currentLaboratoryData = null;
        
        // Tab functionality for medical history
        function showHistoryTab(tabName, clickedElement = null) {
            console.log('showHistoryTab called with:', tabName, 'selectedPatientId:', selectedPatientId);
            
            // Hide all tab content
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Remove active class from all tabs
            const navTabs = document.querySelectorAll('.nav-tab');
            navTabs.forEach(tab => tab.classList.remove('active'));
            
            // Show selected tab content
            const selectedContent = document.getElementById(tabName);
            if (selectedContent) {
                selectedContent.classList.add('active');
            }
            
            // Add active class to clicked tab (if provided) or find the tab by onclick attribute
            if (clickedElement) {
                clickedElement.classList.add('active');
            } else {
                // Find the tab button that should be active
                const targetTab = document.querySelector(`[onclick*="showHistoryTab('${tabName}')"]`);
                if (targetTab) {
                    targetTab.classList.add('active');
                }
            }
            
            // Load data for the selected tab
            if (selectedPatientId) {
                loadHistoryData(tabName, selectedPatientId);
            } else {
                console.warn('No patient selected, cannot load history data');
            }
        }
        
        // Search functionality - now supports empty search to load all patients
        function performPatientSearch(page = 1) {
            const searchTerm = document.getElementById('patient').value.trim();
            const barangayFilter = document.getElementById('barangay') ? document.getElementById('barangay').value : '';
            
            // Store current search parameters
            currentSearchTerm = searchTerm;
            currentBarangayFilter = barangayFilter;
            currentPage = page;
            
            console.log('Search triggered with term:', searchTerm, 'barangay:', barangayFilter, 'page:', page);
            
            // Show loading state
            showPatientSearchState('loading');
            
            // Prepare search parameters
            const params = new URLSearchParams();
            params.append('action', 'search_patients');
            params.append('page', page);
            
            // Add search term only if provided
            if (searchTerm.length > 0) {
                params.append('search', searchTerm);
            }
            
            if (barangayFilter) {
                params.append('barangay', barangayFilter);
            }
            
            const searchUrl = window.location.href.split('?')[0] + '?' + params.toString();
            console.log('Making AJAX request to:', searchUrl);
            
            // Perform AJAX search
            fetch(searchUrl, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                console.log('Response received:', response.status, response.headers.get('content-type'));
                return response.text().then(text => {
                    console.log('Raw response:', text);
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        throw new Error('Invalid JSON response: ' + text.substring(0, 200));
                    }
                });
            })
            .then(data => {
                console.log('Parsed response data:', data);
                if (data.success && data.patients && data.patients.length > 0) {
                    totalPages = data.total_pages || 1;
                    totalPatients = data.total || 0;
                    currentPage = data.page || 1;
                    displayPatientResults(data.patients, !isPatientSelected);
                } else {
                    console.log('No patients found or search failed');
                    showPatientSearchState('no-results');
                }
            })
            .catch(error => {
                console.error('Search error:', error);
                showPatientSearchState('error');
            });
        }
        
        // Display patient search results
        function displayPatientResults(patients, showPagination = true) {
            // Store current patients for sorting
            currentPatients = [...patients];
            
            const tbody = document.getElementById('patientTableBody');
            tbody.innerHTML = '';
            
            patients.forEach(patient => {
                const row = document.createElement('tr');
                row.className = 'patient-row';
                row.onclick = () => selectPatient(patient.patient_id, patient.full_name, row);
                
                row.innerHTML = `
                    <td>
                        <input type="radio" name="selected_patient" value="${patient.patient_id}" 
                               class="patient-radio" onchange="selectPatientFromRadio(this, '${patient.patient_id}', '${patient.full_name}')">
                    </td>
                    <td>${patient.patient_id}</td>
                    <td>${patient.full_name}</td>
                    <td>${patient.date_of_birth}</td>
                    <td>${patient.contact_number || 'N/A'}</td>
                    <td>${patient.barangay_name || 'N/A'}</td>
                `;
                
                tbody.appendChild(row);
            });
            
            // Update patient count
            updatePatientCount();
            
            // Show/hide pagination based on selection mode
            updatePaginationControls(showPagination);
            
            showPatientSearchState('results');
        }
        
        // Sort patient table function
        function sortPatientTable(column) {
            // Don't sort if in single patient mode
            if (isPatientSelected) {
                return;
            }
            
            // Toggle sort direction if same column, otherwise use ASC
            if (currentSortColumn === column) {
                currentSortDirection = currentSortDirection === 'ASC' ? 'DESC' : 'ASC';
            } else {
                currentSortColumn = column;
                currentSortDirection = 'ASC';
            }
            
            // Update sort icons
            updateSortIcons(column, currentSortDirection);
            
            // Sort the current patients array
            const sortedPatients = [...currentPatients].sort((a, b) => {
                let valueA = a[column] || '';
                let valueB = b[column] || '';
                
                // Handle different data types
                if (column === 'patient_id') {
                    valueA = parseInt(valueA) || 0;
                    valueB = parseInt(valueB) || 0;
                } else if (column === 'date_of_birth') {
                    valueA = new Date(valueA);
                    valueB = new Date(valueB);
                } else {
                    // String comparison (case-insensitive)
                    valueA = valueA.toString().toLowerCase();
                    valueB = valueB.toString().toLowerCase();
                }
                
                if (currentSortDirection === 'ASC') {
                    return valueA < valueB ? -1 : valueA > valueB ? 1 : 0;
                } else {
                    return valueA > valueB ? -1 : valueA < valueB ? 1 : 0;
                }
            });
            
            // Re-display the sorted results
            displayPatientResults(sortedPatients, true);
        }
        
        // Update sort icons
        function updateSortIcons(activeColumn, direction) {
            // Reset all sort icons
            const sortIcons = document.querySelectorAll('.sort-icon');
            sortIcons.forEach(icon => {
                icon.className = 'fas fa-sort sort-icon';
            });
            
            // Update active column icon
            const activeIcon = document.getElementById(`sort-${activeColumn}`);
            if (activeIcon) {
                if (direction === 'ASC') {
                    activeIcon.className = 'fas fa-sort-up sort-icon active';
                } else {
                    activeIcon.className = 'fas fa-sort-down sort-icon active';
                }
            }
        }
        
        // Update patient count display
        function updatePatientCount() {
            const countElement = document.getElementById('patientCount');
            const browseControls = document.getElementById('browseControls');
            
            if (isPatientSelected) {
                countElement.textContent = '(1 selected patient)';
                countElement.style.color = '#28a745';
                browseControls.style.display = 'block';
            } else {
                const start = (currentPage - 1) * 6 + 1;
                const end = Math.min(currentPage * 6, totalPatients);
                countElement.textContent = `(${start}-${end} of ${totalPatients} patients)`;
                countElement.style.color = '#6c757d';
                browseControls.style.display = 'none';
            }
        }
        
        // Update pagination controls
        function updatePaginationControls(show = true) {
            const paginationControls = document.getElementById('paginationControls');
            const paginationInfo = document.getElementById('paginationInfo');
            
            if (!show || isPatientSelected || totalPages <= 1) {
                paginationControls.style.display = 'none';
                return;
            }
            
            paginationControls.style.display = 'block';
            
            // Update pagination info
            const start = (currentPage - 1) * 6 + 1;
            const end = Math.min(currentPage * 6, totalPatients);
            paginationInfo.textContent = `Showing ${start}-${end} of ${totalPatients} patients`;
            
            // Update buttons
            document.getElementById('prevPage').disabled = currentPage <= 1;
            document.getElementById('nextPage').disabled = currentPage >= totalPages;
            
            // Update page numbers
            const pageNumbers = document.getElementById('pageNumbers');
            pageNumbers.innerHTML = '';
            
            // Show max 5 page numbers
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, startPage + 4);
            
            // Adjust if we're near the end
            if (endPage - startPage < 4) {
                startPage = Math.max(1, endPage - 4);
            }
            
            for (let i = startPage; i <= endPage; i++) {
                const pageBtn = document.createElement('span');
                pageBtn.className = `page-number ${i === currentPage ? 'active' : ''}`;
                pageBtn.textContent = i;
                pageBtn.onclick = () => changePage(i - currentPage);
                pageNumbers.appendChild(pageBtn);
            }
        }
        
        // Change page
        function changePage(direction) {
            let newPage;
            if (typeof direction === 'number' && direction <= totalPages) {
                newPage = Math.abs(direction) <= totalPages ? currentPage + direction : direction;
            } else {
                newPage = direction;
            }
            
            newPage = Math.max(1, Math.min(totalPages, newPage));
            
            if (newPage !== currentPage && !isPatientSelected) {
                performPatientSearch(newPage);
            }
        }
        
        // Select patient - Switch to single patient mode
        function selectPatient(patientId, patientName, row) {
            // Ensure patientId is a number
            const numericPatientId = parseInt(patientId);
            console.log('selectPatient called with:', { 
                originalPatientId: patientId, 
                numericPatientId: numericPatientId,
                patientName: patientName 
            });
            
            if (isNaN(numericPatientId) || numericPatientId <= 0) {
                console.error('Invalid patient ID provided to selectPatient:', patientId);
                alert('Invalid patient ID. Please try again.');
                return;
            }
            
            // Update selected patient
            selectedPatientId = numericPatientId;
            selectedPatientName = patientName;
            isPatientSelected = true;
            
            // Clear all previous selections
            const allRadios = document.querySelectorAll('.patient-radio');
            const allRows = document.querySelectorAll('.patient-row');
            allRadios.forEach(radio => radio.checked = false);
            allRows.forEach(r => r.classList.remove('selected'));
            
            // Update radio button and row selection
            const radio = row.querySelector('.patient-radio');
            if (radio) {
                radio.checked = true;
            }
            row.classList.add('selected');
            
            // Filter to show only selected patient
            const selectedPatients = [{
                patient_id: patientId,
                full_name: patientName,
                date_of_birth: row.cells[3].textContent,
                contact_number: row.cells[4].textContent,
                barangay_name: row.cells[5].textContent
            }];
            
            // Update display to show only selected patient
            displayPatientResults(selectedPatients, false);
            
            // Update patient name in history section
            document.getElementById('selectedPatientName').textContent = patientName;
            
            // Show medical history section
            document.getElementById('medicalHistorySection').style.display = 'block';
            
            // Load default tab data (appointments)
            console.log('About to load appointments for patient ID:', numericPatientId);
            loadHistoryData('appointments', numericPatientId);
        }
        
        // Handle radio button selection
        function selectPatientFromRadio(radioElement, patientId, patientName) {
            const row = radioElement.closest('tr');
            selectPatient(patientId, patientName, row);
        }
        
        // Show all patients - Switch back to browse mode
        function showAllPatients() {
            isPatientSelected = false;
            selectedPatientId = null;
            selectedPatientName = '';
            
            // Hide medical history section
            document.getElementById('medicalHistorySection').style.display = 'none';
            
            // Trigger search to show all patients again
            performPatientSearch(currentPage);
        }
        
        // Show different patient search states
        function showPatientSearchState(state) {
            const section = document.getElementById('patientSearchSection');
            const table = document.getElementById('patientTable');
            const emptyState = document.getElementById('patientSearchEmpty');
            const noResultsState = document.getElementById('patientSearchNoResults');
            
            // Hide history section when searching
            if (state !== 'results') {
                document.getElementById('medicalHistorySection').style.display = 'none';
            }
            
            section.style.display = 'block';
            
            switch (state) {
                case 'empty':
                    table.style.display = 'none';
                    emptyState.style.display = 'block';
                    noResultsState.style.display = 'none';
                    break;
                case 'loading':
                    table.style.display = 'table';
                    emptyState.style.display = 'none';
                    noResultsState.style.display = 'none';
                    document.getElementById('patientTableBody').innerHTML = '<tr><td colspan="6" class="text-center">Searching...</td></tr>';
                    break;
                case 'results':
                    table.style.display = 'table';
                    emptyState.style.display = 'none';
                    noResultsState.style.display = 'none';
                    break;
                case 'no-results':
                    table.style.display = 'none';
                    emptyState.style.display = 'none';
                    noResultsState.style.display = 'block';
                    break;
                case 'error':
                    table.style.display = 'none';
                    emptyState.style.display = 'none';
                    noResultsState.style.display = 'block';
                    noResultsState.querySelector('h3').textContent = 'Search Error';
                    noResultsState.querySelector('p').textContent = 'An error occurred while searching. Please try again.';
                    break;
            }
        }
        
        // Load history data for selected tab
        function loadHistoryData(historyType, patientId) {
            if (historyType === 'appointments') {
                loadAppointmentsHistory(patientId);
                return;
            }
            
            if (historyType === 'referrals') {
                loadReferralsHistory(patientId);
                return;
            }
            
            if (historyType === 'consultations') {
                loadConsultationsHistory(patientId);
                return;
            }
            
            if (historyType === 'prescriptions') {
                loadPrescriptionsHistory(patientId);
                return;
            }
            
            if (historyType === 'billing') {
                loadBillingHistory(patientId);
                return;
            }
            
            if (historyType === 'laboratory') {
                loadLaboratoryHistory(patientId);
                return;
            }
            
            const tabContent = document.getElementById(historyType);
            const tableWrapper = tabContent.querySelector('.table-wrapper');
            
            // Show loading state
            tableWrapper.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin"></i><h3>Loading...</h3><p>Loading ' + historyType + ' history...</p></div>';
            
            // TODO: Implement AJAX calls to load specific history data
            // This will be implemented in the next phase
            setTimeout(() => {
                tableWrapper.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-info-circle"></i>
                        <h3>${historyType.charAt(0).toUpperCase() + historyType.slice(1)} History</h3>
                        <p>History loading will be implemented in the next phase for patient ID: ${patientId}</p>
                    </div>
                `;
            }, 1000);
        }
        
        // Load appointments history for selected patient
        // Check if session is still valid before making API calls
        function checkSessionAndExecute(callback) {
            // Quick session check by making a lightweight request
            fetch(window.location.href, {
                method: 'HEAD',
                credentials: 'same-origin'
            })
            .then(response => {
                if (response.url.includes('login') || response.status === 401) {
                    // Session expired, show message
                    const emptyDiv = document.getElementById('appointmentsEmpty');
                    const loadingDiv = document.getElementById('appointmentsLoading');
                    const tableDiv = document.getElementById('appointmentsTable');
                    
                    loadingDiv.style.display = 'none';
                    tableDiv.style.display = 'none';
                    emptyDiv.style.display = 'block';
                    emptyDiv.querySelector('h3').textContent = 'Session Expired';
                    emptyDiv.querySelector('p').innerHTML = 'Your session has expired. Please <a href="javascript:window.location.reload();" style="color: #0077b6; text-decoration: underline;">refresh the page</a> and log in again.';
                    return false;
                } else {
                    // Session is valid, execute callback
                    callback();
                    return true;
                }
            })
            .catch(() => {
                // If session check fails, try the callback anyway
                callback();
            });
        }

        function loadAppointmentsHistory(patientId) {
            console.log('loadAppointmentsHistory called with patientId:', patientId, 'type:', typeof patientId);
            
            // Ensure patientId is a valid number
            const numericPatientId = parseInt(patientId);
            if (isNaN(numericPatientId) || numericPatientId <= 0) {
                console.error('Invalid patient ID:', patientId);
                const emptyDiv = document.getElementById('appointmentsEmpty');
                emptyDiv.style.display = 'block';
                emptyDiv.querySelector('h3').textContent = 'Invalid Patient ID';
                emptyDiv.querySelector('p').textContent = 'The patient ID is not valid.';
                return;
            }
            
            const loadingDiv = document.getElementById('appointmentsLoading');
            const emptyDiv = document.getElementById('appointmentsEmpty');
            const tableDiv = document.getElementById('appointmentsTable');
            
            // Show loading state
            loadingDiv.style.display = 'block';
            emptyDiv.style.display = 'none';
            tableDiv.style.display = 'none';
            
            const apiUrl = APP_CONFIG.apiPath(`get_patient_appointments.php?patient_id=${numericPatientId}`);
            console.log('Making API request to:', apiUrl);
            
            // Fetch appointments data directly
            fetch(apiUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(response => {
                    console.log('API response status:', response.status, response.statusText);
                    if (!response.ok) {
                        // Check if it's an authentication error
                        if (response.status === 401) {
                            throw new Error('Session expired. Please log in again.');
                        }
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.text().then(text => {
                        console.log('Raw API response:', text.substring(0, 500));
                        // Check if response is HTML (indicates redirect to login page)
                        if (text.trim().startsWith('<!DOCTYPE') || text.trim().startsWith('<html')) {
                            throw new Error('Session expired. Please refresh the page and log in again.');
                        }
                        
                        try {
                            const jsonData = JSON.parse(text);
                            console.log('Parsed JSON response:', jsonData);
                            return jsonData;
                        } catch (e) {
                            console.error('JSON Parse Error:', e);
                            console.error('Response content:', text.substring(0, 200));
                            throw new Error(`Invalid response format. Expected JSON but got: ${text.substring(0, 100)}...`);
                        }
                    });
                })
                .then(data => {
                    console.log('API response received:', data);
                    loadingDiv.style.display = 'none';
                    
                    if (data.success && data.appointments && data.appointments.length > 0) {
                        console.log('Found', data.appointments.length, 'appointments');
                        
                        // Store appointments data for pagination and sorting
                        currentAppointments = data.appointments;
                        totalAppointments = data.appointments.length;
                        totalAppointmentsPages = Math.ceil(totalAppointments / appointmentsPerPage);
                        currentAppointmentsPage = 1;
                        
                        // Sort appointments by default (scheduled_date DESC)
                        sortAppointmentsByColumn(appointmentsSortColumn, appointmentsSortDirection);
                        
                        displayAppointmentsTable();
                        tableDiv.style.display = 'block';
                    } else {
                        console.log('No appointments found or API error:', data);
                        emptyDiv.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error loading appointments:', error);
                    loadingDiv.style.display = 'none';
                    emptyDiv.style.display = 'block';
                    
                    // Show user-friendly error messages
                    if (error.message.includes('Session expired') || error.message.includes('Session may have expired')) {
                        emptyDiv.querySelector('h3').textContent = 'Session Expired';
                        emptyDiv.querySelector('p').innerHTML = 'Your session has expired. Please <a href="javascript:window.location.reload();" style="color: #0077b6; text-decoration: underline;">refresh the page</a> and log in again.';
                    } else {
                        emptyDiv.querySelector('h3').textContent = 'Error Loading Appointments';
                        emptyDiv.querySelector('p').textContent = 'Unable to load appointment history. Please try again.';
                    }
                });
        }
        
        // Display appointments in table with pagination
        function displayAppointmentsTable() {
            const tbody = document.getElementById('appointmentsTableBody');
            tbody.innerHTML = '';
            
            // Calculate pagination
            const startIndex = (currentAppointmentsPage - 1) * appointmentsPerPage;
            const endIndex = Math.min(startIndex + appointmentsPerPage, totalAppointments);
            const pageAppointments = currentAppointments.slice(startIndex, endIndex);
            
            pageAppointments.forEach(appointment => {
                const row = document.createElement('tr');
                
                // Format appointment ID
                const appointmentId = `APT-${String(appointment.appointment_id).padStart(8, '0')}`;
                
                // Format date
                const appointmentDate = new Date(appointment.scheduled_date).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
                
                // Format time
                const appointmentTime = appointment.scheduled_time || 'N/A';
                
                // Status badge
                let statusClass = 'badge-secondary';
                switch (appointment.status?.toLowerCase()) {
                    case 'confirmed':
                        statusClass = 'badge-primary';
                        break;
                    case 'completed':
                        statusClass = 'badge-success';
                        break;
                    case 'cancelled':
                        statusClass = 'badge-danger';
                        break;
                    case 'pending':
                        statusClass = 'badge-warning';
                        break;
                }
                
                row.innerHTML = `
                    <td>${appointmentId}</td>
                    <td>${appointment.facility_name || 'N/A'}</td>
                    <td>${appointmentDate}</td>
                    <td>${appointmentTime}</td>
                    <td><span class="badge ${statusClass}">${appointment.status || 'Unknown'}</span></td>
                    <td>
                        <div class="actions-group">
                            <button onclick="viewAppointment(${appointment.appointment_id})" class="btn btn-primary btn-sm" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </td>
                `;
                
                tbody.appendChild(row);
            });
            
            // Update pagination controls
            updateAppointmentsPagination();
        }
        
        // Appointments sorting function
        function sortAppointmentsTable(column) {
            console.log('Sorting appointments by:', column);
            
            // Toggle sort direction if same column, otherwise use default direction
            if (appointmentsSortColumn === column) {
                appointmentsSortDirection = appointmentsSortDirection === 'ASC' ? 'DESC' : 'ASC';
            } else {
                appointmentsSortColumn = column;
                // Set default direction based on column type
                if (column === 'scheduled_date' || column === 'appointment_id') {
                    appointmentsSortDirection = 'DESC'; // Latest first
                } else {
                    appointmentsSortDirection = 'ASC';
                }
            }
            
            // Update sort icons
            updateAppointmentsSortIcons(column, appointmentsSortDirection);
            
            // Sort and redisplay
            sortAppointmentsByColumn(column, appointmentsSortDirection);
            currentAppointmentsPage = 1; // Reset to first page after sorting
            displayAppointmentsTable();
        }
        
        // Function to sort appointments array
        function sortAppointmentsByColumn(column, direction) {
            currentAppointments.sort((a, b) => {
                let valueA = a[column] || '';
                let valueB = b[column] || '';
                
                // Handle different data types
                if (column === 'appointment_id') {
                    valueA = parseInt(valueA) || 0;
                    valueB = parseInt(valueB) || 0;
                } else if (column === 'scheduled_date') {
                    valueA = new Date(valueA);
                    valueB = new Date(valueB);
                } else if (column === 'scheduled_time') {
                    valueA = valueA.toString();
                    valueB = valueB.toString();
                } else {
                    valueA = valueA.toString().toLowerCase();
                    valueB = valueB.toString().toLowerCase();
                }
                
                if (direction === 'ASC') {
                    return valueA < valueB ? -1 : valueA > valueB ? 1 : 0;
                } else {
                    return valueA > valueB ? -1 : valueA < valueB ? 1 : 0;
                }
            });
        }
        
        // Update sort icons for appointments
        function updateAppointmentsSortIcons(activeColumn, direction) {
            // Reset all sort icons
            const sortIcons = document.querySelectorAll('#appointmentsTable .sort-icon');
            sortIcons.forEach(icon => {
                icon.className = 'fas fa-sort sort-icon';
            });
            
            // Update active column icon
            const activeIcon = document.getElementById(`sort-${activeColumn}`);
            if (activeIcon) {
                if (direction === 'ASC') {
                    activeIcon.className = 'fas fa-sort-up sort-icon active';
                } else {
                    activeIcon.className = 'fas fa-sort-down sort-icon active';
                }
            }
        }
        
        // Update appointments pagination controls
        function updateAppointmentsPagination() {
            const paginationControls = document.getElementById('appointmentsPaginationControls');
            const paginationInfo = document.getElementById('appointmentsPaginationInfo');
            
            if (totalAppointmentsPages <= 1) {
                paginationControls.style.display = 'none';
                return;
            }
            
            paginationControls.style.display = 'block';
            
            // Update pagination info
            const start = (currentAppointmentsPage - 1) * appointmentsPerPage + 1;
            const end = Math.min(currentAppointmentsPage * appointmentsPerPage, totalAppointments);
            paginationInfo.textContent = `Showing ${start}-${end} of ${totalAppointments} appointments`;
            
            // Update buttons
            document.getElementById('appointmentsPrevPage').disabled = currentAppointmentsPage <= 1;
            document.getElementById('appointmentsNextPage').disabled = currentAppointmentsPage >= totalAppointmentsPages;
            
            // Update page numbers
            const pageNumbers = document.getElementById('appointmentsPageNumbers');
            pageNumbers.innerHTML = '';
            
            // Show max 5 page numbers
            let startPage = Math.max(1, currentAppointmentsPage - 2);
            let endPage = Math.min(totalAppointmentsPages, startPage + 4);
            
            // Adjust if we're near the end
            if (endPage - startPage < 4) {
                startPage = Math.max(1, endPage - 4);
            }
            
            for (let i = startPage; i <= endPage; i++) {
                const pageBtn = document.createElement('span');
                pageBtn.className = `page-number ${i === currentAppointmentsPage ? 'active' : ''}`;
                pageBtn.textContent = i;
                pageBtn.onclick = () => changeAppointmentsPage(i, true);
                pageNumbers.appendChild(pageBtn);
            }
        }
        
        // Change appointments page
        function changeAppointmentsPage(direction, isAbsolute = false) {
            let newPage;
            if (isAbsolute) {
                newPage = direction;
            } else {
                newPage = currentAppointmentsPage + direction;
            }
            
            newPage = Math.max(1, Math.min(totalAppointmentsPages, newPage));
            
            if (newPage !== currentAppointmentsPage) {
                currentAppointmentsPage = newPage;
                displayAppointmentsTable();
            }
        }
        
        // ==================== REFERRALS FUNCTIONS ====================
        
        // Load referrals history for selected patient
        function loadReferralsHistory(patientId) {
            console.log('loadReferralsHistory called with patientId:', patientId, 'type:', typeof patientId);
            
            // Ensure patientId is a valid number
            const numericPatientId = parseInt(patientId);
            if (isNaN(numericPatientId) || numericPatientId <= 0) {
                console.error('Invalid patient ID:', patientId);
                const emptyDiv = document.getElementById('referralsEmpty');
                emptyDiv.style.display = 'block';
                emptyDiv.querySelector('h3').textContent = 'Invalid Patient ID';
                emptyDiv.querySelector('p').textContent = 'The patient ID is not valid.';
                return;
            }
            
            const loadingDiv = document.getElementById('referralsLoading');
            const emptyDiv = document.getElementById('referralsEmpty');
            const tableDiv = document.getElementById('referralsTable');
            
            // Show loading state
            loadingDiv.style.display = 'block';
            emptyDiv.style.display = 'none';
            tableDiv.style.display = 'none';
            
            const apiUrl = APP_CONFIG.apiPath(`get_patient_referrals.php?patient_id=${numericPatientId}`);
            console.log('Making API request to:', apiUrl);
            
            // Fetch referrals data
            fetch(apiUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                console.log('API response status:', response.status, response.statusText);
                if (!response.ok) {
                    if (response.status === 401) {
                        throw new Error('Session expired. Please log in again.');
                    }
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.text().then(text => {
                    console.log('Raw API response:', text.substring(0, 500));
                    if (text.trim().startsWith('<!DOCTYPE') || text.trim().startsWith('<html')) {
                        throw new Error('Session expired. Please refresh the page and log in again.');
                    }
                    
                    try {
                        const jsonData = JSON.parse(text);
                        console.log('Parsed JSON response:', jsonData);
                        return jsonData;
                    } catch (e) {
                        console.error('JSON Parse Error:', e);
                        console.error('Response content:', text.substring(0, 200));
                        throw new Error(`Invalid response format. Expected JSON but got: ${text.substring(0, 100)}...`);
                    }
                });
            })
            .then(data => {
                console.log('API response received:', data);
                loadingDiv.style.display = 'none';
                
                if (data.success && data.referrals && data.referrals.length > 0) {
                    console.log('Found', data.referrals.length, 'referrals');
                    
                    // Store referrals data for pagination and sorting
                    currentReferrals = data.referrals;
                    totalReferrals = data.referrals.length;
                    totalReferralsPages = Math.ceil(totalReferrals / referralsPerPage);
                    currentReferralsPage = 1;
                    
                    // Sort referrals by default (referral_date DESC)
                    sortReferralsByColumn(referralsSortColumn, referralsSortDirection);
                    
                    displayReferralsTable();
                    tableDiv.style.display = 'block';
                } else {
                    console.log('No referrals found or API error:', data);
                    emptyDiv.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error loading referrals:', error);
                loadingDiv.style.display = 'none';
                emptyDiv.style.display = 'block';
                
                // Show user-friendly error messages
                if (error.message.includes('Session expired') || error.message.includes('Session may have expired')) {
                    emptyDiv.querySelector('h3').textContent = 'Session Expired';
                    emptyDiv.querySelector('p').innerHTML = 'Your session has expired. Please <a href="javascript:window.location.reload();" style="color: #0077b6; text-decoration: underline;">refresh the page</a> and log in again.';
                } else {
                    emptyDiv.querySelector('h3').textContent = 'Error Loading Referrals';
                    emptyDiv.querySelector('p').textContent = 'Unable to load referral history. Please try again.';
                }
            });
        }
        
        // Display referrals in table with pagination
        function displayReferralsTable() {
            const tbody = document.getElementById('referralsTableBody');
            tbody.innerHTML = '';
            
            // Calculate pagination
            const startIndex = (currentReferralsPage - 1) * referralsPerPage;
            const endIndex = Math.min(startIndex + referralsPerPage, totalReferrals);
            const pageReferrals = currentReferrals.slice(startIndex, endIndex);
            
            pageReferrals.forEach(referral => {
                const row = document.createElement('tr');
                
                // Format referral date
                const referralDate = new Date(referral.referral_date).toLocaleDateString('en-US');
                
                // Status badge
                let statusClass = 'badge-secondary';
                switch (referral.status?.toLowerCase()) {
                    case 'pending':
                        statusClass = 'badge-warning';
                        break;
                    case 'accepted':
                        statusClass = 'badge-primary';
                        break;
                    case 'completed':
                        statusClass = 'badge-success';
                        break;
                    case 'rejected':
                        statusClass = 'badge-danger';
                        break;
                    case 'cancelled':
                        statusClass = 'badge-secondary';
                        break;
                }
                
                // Determine facility display
                const toFacility = referral.referred_to_facility_name || referral.external_facility_name || 'N/A';
                
                row.innerHTML = `
                    <td><strong>${referral.referral_num || 'N/A'}</strong></td>
                    <td>${referral.referring_facility_name || 'N/A'}</td>
                    <td>${toFacility}</td>
                    <td>${referral.service_name || 'N/A'}</td>
                    <td>${referralDate}</td>
                    <td><span class="badge ${statusClass}">${referral.status || 'Unknown'}</span></td>
                    <td>
                        <div class="actions-group">
                            <button onclick="viewReferral(${referral.referral_id})" class="btn btn-primary btn-sm" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button onclick="printReferral(${referral.referral_id})" class="btn btn-secondary btn-sm" title="Print Referral">
                                <i class="fas fa-print"></i>
                            </button>
                        </div>
                    </td>
                `;
                
                tbody.appendChild(row);
            });
            
            // Update pagination controls
            updateReferralsPagination();
        }
        
        // Referrals sorting function
        function sortReferralsTable(column) {
            console.log('Sorting referrals by:', column);
            
            // Toggle sort direction if same column, otherwise use default direction
            if (referralsSortColumn === column) {
                referralsSortDirection = referralsSortDirection === 'ASC' ? 'DESC' : 'ASC';
            } else {
                referralsSortColumn = column;
                // Set default direction based on column type
                if (column === 'referral_date' || column === 'referral_num') {
                    referralsSortDirection = 'DESC'; // Latest first
                } else {
                    referralsSortDirection = 'ASC';
                }
            }
            
            // Update sort icons
            updateReferralsSortIcons(column, referralsSortDirection);
            
            // Sort and redisplay
            sortReferralsByColumn(column, referralsSortDirection);
            currentReferralsPage = 1; // Reset to first page after sorting
            displayReferralsTable();
        }
        
        // Function to sort referrals array
        function sortReferralsByColumn(column, direction) {
            currentReferrals.sort((a, b) => {
                let valueA = a[column] || '';
                let valueB = b[column] || '';
                
                // Handle different data types
                if (column === 'referral_num') {
                    valueA = parseInt(valueA) || 0;
                    valueB = parseInt(valueB) || 0;
                } else if (column === 'referral_date') {
                    valueA = new Date(valueA);
                    valueB = new Date(valueB);
                } else {
                    valueA = valueA.toString().toLowerCase();
                    valueB = valueB.toString().toLowerCase();
                }
                
                if (direction === 'ASC') {
                    return valueA < valueB ? -1 : valueA > valueB ? 1 : 0;
                } else {
                    return valueA > valueB ? -1 : valueA < valueB ? 1 : 0;
                }
            });
        }
        
        // Update sort icons for referrals
        function updateReferralsSortIcons(activeColumn, direction) {
            // Reset all sort icons
            const sortIcons = document.querySelectorAll('#referralsTable .sort-icon');
            sortIcons.forEach(icon => {
                icon.className = 'fas fa-sort sort-icon';
            });
            
            // Update active column icon
            const activeIcon = document.getElementById(`sort-${activeColumn}`);
            if (activeIcon) {
                if (direction === 'ASC') {
                    activeIcon.className = 'fas fa-sort-up sort-icon active';
                } else {
                    activeIcon.className = 'fas fa-sort-down sort-icon active';
                }
            }
        }
        
        // Update referrals pagination controls
        function updateReferralsPagination() {
            const paginationControls = document.getElementById('referralsPaginationControls');
            const paginationInfo = document.getElementById('referralsPaginationInfo');
            
            if (totalReferralsPages <= 1) {
                paginationControls.style.display = 'none';
                return;
            }
            
            paginationControls.style.display = 'block';
            
            // Update pagination info
            const start = (currentReferralsPage - 1) * referralsPerPage + 1;
            const end = Math.min(currentReferralsPage * referralsPerPage, totalReferrals);
            paginationInfo.textContent = `Showing ${start}-${end} of ${totalReferrals} referrals`;
            
            // Update buttons
            document.getElementById('referralsPrevPage').disabled = currentReferralsPage <= 1;
            document.getElementById('referralsNextPage').disabled = currentReferralsPage >= totalReferralsPages;
            
            // Update page numbers
            const pageNumbers = document.getElementById('referralsPageNumbers');
            pageNumbers.innerHTML = '';
            
            // Show max 5 page numbers
            let startPage = Math.max(1, currentReferralsPage - 2);
            let endPage = Math.min(totalReferralsPages, startPage + 4);
            
            // Adjust if we're near the end
            if (endPage - startPage < 4) {
                startPage = Math.max(1, endPage - 4);
            }
            
            for (let i = startPage; i <= endPage; i++) {
                const pageBtn = document.createElement('span');
                pageBtn.className = `page-number ${i === currentReferralsPage ? 'active' : ''}`;
                pageBtn.textContent = i;
                pageBtn.onclick = () => changeReferralsPage(i, true);
                pageNumbers.appendChild(pageBtn);
            }
        }
        
        // Change referrals page
        function changeReferralsPage(direction, isAbsolute = false) {
            let newPage;
            if (isAbsolute) {
                newPage = direction;
            } else {
                newPage = currentReferralsPage + direction;
            }
            
            newPage = Math.max(1, Math.min(totalReferralsPages, newPage));
            
            if (newPage !== currentReferralsPage) {
                currentReferralsPage = newPage;
                displayReferralsTable();
            }
        }
        
        // ==================== REFERRAL MODAL FUNCTIONS ====================
        
        // View referral details in modal
        function viewReferral(referralId) {
            console.log('viewReferral called with ID:', referralId);
            
            // Show loading
            document.getElementById('referralDetailsContent').innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <div class="loader"></div>
                    <p style="margin-top: 10px; color: #6c757d;">Loading referral details...</p>
                </div>
            `;
            document.getElementById('viewReferralModal').style.display = 'block';

            // Get referral data
            fetch(`api/get_referral_details.php?referral_id=${referralId}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Cache-Control': 'no-cache'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.text().then(text => {
                    console.log('Raw API Response:', text);
                    
                    if (!text || text.trim() === '') {
                        throw new Error('Empty response from server');
                    }
                    
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON Parse Error:', e);
                        console.error('Response Text:', text);
                        throw new Error(`Invalid JSON response: ${text.substring(0, 200)}...`);
                    }
                });
            })
            .then(data => {
                if (data.success) {
                    displayReferralDetails(data.referral);
                } else {
                    showErrorInReferralModal('Error loading referral details: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error loading referral details:', error);
                showErrorInReferralModal('Network error: Unable to load referral details. Please check your connection and try again.');
            });
        }
        
        // Show error in referral modal
        function showErrorInReferralModal(message) {
            document.getElementById('referralDetailsContent').innerHTML = `
                <div style="text-align: center; padding: 30px; color: #dc3545;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i>
                    <h4>Error Loading Referral</h4>
                    <p>${message}</p>
                    <button onclick="closeModal('viewReferralModal')" class="btn btn-secondary" style="margin-top: 15px;">
                        Close
                    </button>
                </div>
            `;
        }
        
        // Display referral details in modal
        function displayReferralDetails(referral) {
            console.log('Displaying referral details:', referral);

            // Store referral data globally for print function
            currentReferralData = referral;

            // Format date function
            function formatDate(dateString) {
                if (!dateString) return 'N/A';
                const date = new Date(dateString);
                return date.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
            }

            // Build the modal content using the same structure as appointment modal
            const modalContent = `
                <div class="appointment-details-grid">
                    <div class="details-section">
                        <h4><i class="fas fa-user"></i> Patient Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Full Name:</span>
                            <span class="detail-value highlight">${referral.patient_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Patient ID:</span>
                            <span class="detail-value">${referral.patient_number || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Age:</span>
                            <span class="detail-value">${referral.age || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Gender:</span>
                            <span class="detail-value">${referral.gender || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Barangay:</span>
                            <span class="detail-value">${referral.barangay || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Contact Number:</span>
                            <span class="detail-value">${referral.contact_number || 'N/A'}</span>
                        </div>
                    </div>

                    <div class="details-section">
                        <h4><i class="fas fa-share"></i> Referral Details</h4>
                        <div class="detail-item">
                            <span class="detail-label">Referral Number:</span>
                            <span class="detail-value highlight">${referral.referral_num || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status:</span>
                            <span class="detail-value">${referral.status || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Referring Facility:</span>
                            <span class="detail-value">${referral.referring_facility_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Referred To:</span>
                            <span class="detail-value">${referral.facility_name || referral.external_facility_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Service:</span>
                            <span class="detail-value">${referral.service_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Date Issued:</span>
                            <span class="detail-value">${formatDate(referral.referral_date)}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Issued By:</span>
                            <span class="detail-value">${referral.issuer_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Issuer Role:</span>
                            <span class="detail-value">${referral.issuer_position || 'N/A'}</span>
                        </div>
                    </div>

                    <div class="details-section" style="grid-column: 1 / -1;">
                        <h4><i class="fas fa-clipboard-list"></i> Referral Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Reason for Referral:</span>
                            <span class="detail-value">${referral.referral_reason || 'No reason provided'}</span>
                        </div>
                    </div>

                    ${referral.vitals && Object.keys(referral.vitals).length > 0 ? `
                    <div class="details-section" style="grid-column: 1 / -1;">
                        <h4><i class="fas fa-heartbeat"></i> Vital Signs</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            ${referral.vitals.blood_pressure ? `
                            <div class="detail-item">
                                <span class="detail-label">Blood Pressure:</span>
                                <span class="detail-value">${referral.vitals.blood_pressure}</span>
                            </div>
                            ` : ''}
                            ${referral.vitals.temperature ? `
                            <div class="detail-item">
                                <span class="detail-label">Temperature:</span>
                                <span class="detail-value">${referral.vitals.temperature}°C</span>
                            </div>
                            ` : ''}
                            ${referral.vitals.heart_rate ? `
                            <div class="detail-item">
                                <span class="detail-label">Heart Rate:</span>
                                <span class="detail-value">${referral.vitals.heart_rate} bpm</span>
                            </div>
                            ` : ''}
                            ${referral.vitals.respiratory_rate ? `
                            <div class="detail-item">
                                <span class="detail-label">Respiratory Rate:</span>
                                <span class="detail-value">${referral.vitals.respiratory_rate}/min</span>
                            </div>
                            ` : ''}
                            ${referral.vitals.weight ? `
                            <div class="detail-item">
                                <span class="detail-label">Weight:</span>
                                <span class="detail-value">${referral.vitals.weight} kg</span>
                            </div>
                            ` : ''}
                            ${referral.vitals.height ? `
                            <div class="detail-item">
                                <span class="detail-label">Height:</span>
                                <span class="detail-value">${referral.vitals.height} cm</span>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                    ` : ''}
                </div>
            `;

            document.getElementById('referralDetailsContent').innerHTML = modalContent;
        }
        
        // Print referral details
        function printReferralDetails() {
            // Get the referral details content
            const modalContent = document.getElementById('referralDetailsContent').innerHTML;
            const facilityName = "<?php echo htmlspecialchars($facility_name); ?>";
            const currentDate = new Date().toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            const currentTime = new Date().toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit'
            });

            // Create a new window for printing
            const printWindow = window.open('', '_blank', 'width=800,height=600');

            // Write the print content
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Referral Details - ${facilityName}</title>
                    <style>
                        body {
                            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                            margin: 0;
                            padding: 15px;
                            color: #333;
                            line-height: 1.4;
                            font-size: 12px;
                        }
                        .print-header {
                            text-align: center;
                            border-bottom: 2px solid #0077b6;
                            padding-bottom: 10px;
                            margin-bottom: 15px;
                        }
                        .print-header h1 {
                            color: #0077b6;
                            margin: 0 0 5px 0;
                            font-size: 22px;
                            font-weight: 600;
                        }
                        .print-header h2 {
                            color: #666;
                            margin: 0 0 3px 0;
                            font-size: 16px;
                            font-weight: 400;
                        }
                        .print-date {
                            color: #888;
                            font-size: 11px;
                            margin-top: 5px;
                        }
                        .appointment-details-grid {
                            display: grid;
                            grid-template-columns: 1fr 1fr;
                            gap: 10px;
                            margin-bottom: 10px;
                        }
                        .details-section {
                            background: #f8f9fa;
                            border: 1px solid #e9ecef;
                            border-radius: 4px;
                            padding: 8px;
                            margin-bottom: 8px;
                            break-inside: avoid;
                        }
                        .details-section h4 {
                            margin: 0 0 8px 0;
                            color: #0077b6;
                            font-size: 13px;
                            font-weight: 600;
                            border-bottom: 1px solid #e9ecef;
                            padding-bottom: 4px;
                            display: flex;
                            align-items: center;
                            gap: 4px;
                        }
                        .detail-item {
                            display: flex;
                            justify-content: space-between;
                            align-items: flex-start;
                            margin-bottom: 4px;
                            gap: 8px;
                        }
                        .detail-label {
                            font-weight: 600;
                            color: #6c757d;
                            font-size: 11px;
                            min-width: 80px;
                            flex-shrink: 0;
                        }
                        .detail-value {
                            color: #495057;
                            font-size: 11px;
                            text-align: right;
                            flex-grow: 1;
                            word-break: break-word;
                        }
                        .detail-value.highlight {
                            background: #e3f2fd;
                            padding: 2px 4px;
                            border-radius: 3px;
                            font-weight: 600;
                            color: #1565c0;
                        }
                        .vitals-group {
                            background: #ffffff;
                            border: 1px solid #e9ecef;
                            border-radius: 4px;
                            padding: 6px;
                            margin-bottom: 6px;
                        }
                        .vitals-group h6 {
                            margin: 0 0 4px 0;
                            font-weight: 600;
                            color: #0077b6;
                            display: flex;
                            align-items: center;
                            gap: 4px;
                            font-size: 11px;
                        }
                        .print-footer {
                            margin-top: 15px;
                            padding-top: 8px;
                            border-top: 1px solid #ddd;
                            text-align: center;
                            color: #888;
                            font-size: 9px;
                        }
                        
                        /* QR Code specific styles */
                        .qr-code-container {
                            text-align: center;
                            margin: 10px 0;
                        }
                        
                        .qr-code-container img {
                            max-width: 120px;
                            max-height: 120px;
                            border: 1px solid #ddd;
                            border-radius: 4px;
                            display: block;
                            margin: 0 auto;
                        }
                        
                        .qr-code-description {
                            margin-top: 5px;
                            font-size: 10px;
                            color: #6c757d;
                            text-align: center;
                        }
                        
                        /* Print-specific styles for single page */
                        @media print {
                            @page {
                                size: A4;
                                margin: 0.5in;
                            }
                            body {
                                padding: 0;
                                font-size: 10px;
                                line-height: 1.2;
                            }
                            .print-header {
                                margin-bottom: 10px;
                                padding-bottom: 8px;
                            }
                            .print-header h1 {
                                font-size: 18px;
                                margin-bottom: 3px;
                            }
                            .print-header h2 {
                                font-size: 14px;
                                margin-bottom: 2px;
                            }
                            .print-date {
                                font-size: 9px;
                                margin-top: 3px;
                            }
                            .appointment-details-grid {
                                grid-template-columns: 1fr 1fr;
                                gap: 8px;
                                margin-bottom: 8px;
                            }
                            .details-section {
                                padding: 6px;
                                margin-bottom: 6px;
                            }
                            .details-section h4 {
                                font-size: 11px;
                                margin-bottom: 6px;
                                padding-bottom: 3px;
                            }
                            .detail-item {
                                margin-bottom: 3px;
                                gap: 6px;
                            }
                            .detail-label, .detail-value {
                                font-size: 9px;
                            }
                            .detail-label {
                                min-width: 70px;
                            }
                            .vitals-group {
                                padding: 4px;
                                margin-bottom: 4px;
                            }
                            .vitals-group h6 {
                                font-size: 9px;
                                margin-bottom: 3px;
                            }
                            .print-footer {
                                margin-top: 10px;
                                padding-top: 6px;
                                font-size: 8px;
                            }
                            .print-footer p {
                                margin: 2px 0;
                            }
                            
                            /* Ensure vitals grid is compact */
                            .details-section[style*="grid"] > div {
                                display: grid !important;
                                grid-template-columns: 1fr 1fr !important;
                                gap: 4px !important;
                            }
                            
                            /* QR Code print styles */
                            .qr-code-container {
                                margin: 8px 0;
                            }
                            
                            .qr-code-container img {
                                max-width: 100px;
                                max-height: 100px;
                            }
                            
                            .qr-code-description {
                                font-size: 8px;
                                margin-top: 3px;
                            }
                        }
                        
                        /* Hide icons in print for cleaner look */
                        @media print {
                            .fas, .fa {
                                display: none !important;
                            }
                        }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h1>${facilityName}</h1>
                        <h2>Referral Details Report</h2>
                        <div class="print-date">
                            Generated on: ${currentDate} at ${currentTime}
                        </div>
                    </div>
                    
                    <div class="print-content">
                        ${modalContent}
                    </div>
                    
                    <div class="signatory-section">
                        <div class="signatory-info">
                            <div class="signature-line">
                                <span class="signature-text">Prepared by:</span>
                            </div>
                            <div class="signatory-details" style="
    margin-top: 60px;
    border-top-style: ridge;
    width: 250px;
">
                                <strong>${currentReferralData.doctor_name || 'N/A'}</strong><br>
                                <span class="signatory-role">${currentReferralData.doctor_position || 'Doctor'}</span><br>
                                <span class="signatory-facility">${currentReferralData.facility_name || 'N/A'}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="print-footer">
                        <p>This document was generated from the ${facilityName} Medical Records System</p>
                        <p>For official use only - Contains confidential patient information</p>
                    </div>
                </body>
                </html>
            `);

            printWindow.document.close();

            // Wait for content to load, then print
            printWindow.onload = function() {
                setTimeout(() => {
                    printWindow.print();
                    printWindow.close();
                }, 300);
            };
        }
        
        function printConsultationDetails() {
            // Get the consultation details content
            const modalContent = document.getElementById('consultationDetailsContent').innerHTML;
            const facilityName = "<?php echo htmlspecialchars($facility_name); ?>";
            const currentDate = new Date().toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            const currentTime = new Date().toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit'
            });

            // Create a new window for printing
            const printWindow = window.open('', '_blank', 'width=800,height=600');

            // Write the print content
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Consultation Details - ${facilityName}</title>
                    <style>
                        body {
                            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                            margin: 0;
                            padding: 15px;
                            color: #333;
                            line-height: 1.4;
                            font-size: 12px;
                        }
                        .print-header {
                            text-align: center;
                            border-bottom: 2px solid #0077b6;
                            padding-bottom: 10px;
                            margin-bottom: 15px;
                        }
                        .print-header h1 {
                            color: #0077b6;
                            margin: 0 0 5px 0;
                            font-size: 22px;
                            font-weight: 600;
                        }
                        .print-header h2 {
                            color: #666;
                            margin: 0 0 3px 0;
                            font-size: 16px;
                            font-weight: 400;
                        }
                        .print-date {
                            color: #888;
                            font-size: 11px;
                            margin-top: 5px;
                        }
                        .appointment-details-grid {
                            display: grid;
                            grid-template-columns: 1fr 1fr;
                            gap: 10px;
                            margin-bottom: 10px;
                        }
                        .details-section {
                            background: #f8f9fa;
                            border: 1px solid #e9ecef;
                            border-radius: 4px;
                            padding: 8px;
                            margin-bottom: 8px;
                            break-inside: avoid;
                        }
                        .details-section h4 {
                            margin: 0 0 8px 0;
                            color: #0077b6;
                            font-size: 13px;
                            font-weight: 600;
                            border-bottom: 1px solid #e9ecef;
                            padding-bottom: 4px;
                            display: flex;
                            align-items: center;
                            gap: 4px;
                        }
                        .detail-item {
                            display: flex;
                            justify-content: space-between;
                            align-items: flex-start;
                            margin-bottom: 4px;
                            gap: 8px;
                        }
                        .detail-label {
                            font-weight: 600;
                            color: #6c757d;
                            font-size: 11px;
                            min-width: 80px;
                            flex-shrink: 0;
                        }
                        .detail-value {
                            color: #495057;
                            font-size: 11px;
                            text-align: right;
                            flex-grow: 1;
                            word-break: break-word;
                        }
                        .detail-value.highlight {
                            background: #e3f2fd;
                            padding: 2px 4px;
                            border-radius: 3px;
                            font-weight: 600;
                            color: #1565c0;
                        }
                        .vitals-group {
                            background: #ffffff;
                            border: 1px solid #e9ecef;
                            border-radius: 4px;
                            padding: 6px;
                            margin-bottom: 6px;
                        }
                        .vitals-group h6 {
                            margin: 0 0 4px 0;
                            font-weight: 600;
                            color: #0077b6;
                            display: flex;
                            align-items: center;
                            gap: 4px;
                            font-size: 11px;
                        }
                        .print-footer {
                            margin-top: 15px;
                            padding-top: 8px;
                            border-top: 1px solid #ddd;
                            text-align: center;
                            color: #888;
                            font-size: 9px;
                        }
                        
                        /* QR Code specific styles */
                        .qr-code-container {
                            text-align: center;
                            margin: 10px 0;
                        }
                        
                        .qr-code-container img {
                            max-width: 120px;
                            max-height: 120px;
                            border: 1px solid #ddd;
                            border-radius: 4px;
                            display: block;
                            margin: 0 auto;
                        }
                        
                        .qr-code-description {
                            margin-top: 5px;
                            font-size: 10px;
                            color: #6c757d;
                            text-align: center;
                        }
                        
                        /* Print-specific styles for single page */
                        @media print {
                            @page {
                                size: A4;
                                margin: 0.5in;
                            }
                            body {
                                padding: 0;
                                font-size: 10px;
                                line-height: 1.2;
                            }
                            .print-header {
                                margin-bottom: 10px;
                                padding-bottom: 8px;
                            }
                            .print-header h1 {
                                font-size: 18px;
                                margin-bottom: 3px;
                            }
                            .print-header h2 {
                                font-size: 14px;
                                margin-bottom: 2px;
                            }
                            .print-date {
                                font-size: 9px;
                                margin-top: 3px;
                            }
                            .appointment-details-grid {
                                grid-template-columns: 1fr 1fr;
                                gap: 8px;
                                margin-bottom: 8px;
                            }
                            .details-section {
                                padding: 6px;
                                margin-bottom: 6px;
                            }
                            .details-section h4 {
                                font-size: 11px;
                                margin-bottom: 6px;
                                padding-bottom: 3px;
                            }
                            .detail-item {
                                margin-bottom: 3px;
                                gap: 6px;
                            }
                            .detail-label, .detail-value {
                                font-size: 9px;
                            }
                            .detail-label {
                                min-width: 70px;
                            }
                            .vitals-group {
                                padding: 4px;
                                margin-bottom: 4px;
                            }
                            .vitals-group h6 {
                                font-size: 9px;
                                margin-bottom: 3px;
                            }
                            .print-footer {
                                margin-top: 10px;
                                padding-top: 6px;
                                font-size: 8px;
                            }
                            .print-footer p {
                                margin: 2px 0;
                            }
                            
                            /* Ensure vitals grid is compact */
                            .details-section[style*="grid"] > div {
                                display: grid !important;
                                grid-template-columns: 1fr 1fr !important;
                                gap: 4px !important;
                            }
                            
                            /* QR Code print styles */
                            .qr-code-container {
                                margin: 8px 0;
                            }
                            
                            .qr-code-container img {
                                max-width: 100px;
                                max-height: 100px;
                            }
                            
                            .qr-code-description {
                                font-size: 8px;
                                margin-top: 3px;
                            }
                        }
                        
                        /* Hide icons in print for cleaner look */
                        @media print {
                            .fas, .fa {
                                display: none !important;
                            }
                        }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h1>${facilityName}</h1>
                        <h2>Consultation Details Report</h2>
                        <div class="print-date">
                            Generated on: ${currentDate} at ${currentTime}
                        </div>
                    </div>
                    
                    <div class="print-content">
                        ${modalContent}
                    </div>
                    
                    <div class="signatory-section">
                        <div class="signatory-info">
                            <div class="signature-line">
                                <span class="signature-text">Prepared by:</span>
                            </div>
                            <div class="signatory-details" style="
    margin-top: 60px;
    border-top-style: ridge;
    width: 250px;
">
                                <strong>${currentConsultationData.doctor_name || 'N/A'}</strong><br>
                                <span class="signatory-role">${currentConsultationData.doctor_position || 'Doctor'}</span><br>
                                <span class="signatory-facility">${currentConsultationData.facility_name || 'N/A'}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="print-footer">
                        <p>This document was generated from the ${facilityName} Medical Records System</p>
                        <p>For official use only - Contains confidential patient information</p>
                    </div>
                </body>
                </html>
            `);

            printWindow.document.close();

            // Wait for content to load, then print
            printWindow.onload = function() {
                setTimeout(() => {
                    printWindow.print();
                    printWindow.close();
                }, 300);
            };
        }
        
        function printPrescriptionDetails() {
            // Get the prescription details content
            const modalContent = document.getElementById('prescriptionDetailsContent').innerHTML;
            const facilityName = "<?php echo htmlspecialchars($facility_name); ?>";
            const currentDate = new Date().toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            const currentTime = new Date().toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit'
            });

            // Create a new window for printing
            const printWindow = window.open('', '_blank', 'width=800,height=600');

            // Write the print content
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Prescription Details - ${facilityName}</title>
                    <style>
                        body {
                            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                            margin: 0;
                            padding: 15px;
                            color: #333;
                            line-height: 1.4;
                            font-size: 12px;
                        }
                        .print-header {
                            text-align: center;
                            border-bottom: 2px solid #0077b6;
                            padding-bottom: 10px;
                            margin-bottom: 15px;
                        }
                        .print-header h1 {
                            color: #0077b6;
                            margin: 0 0 5px 0;
                            font-size: 22px;
                            font-weight: 600;
                        }
                        .print-header h2 {
                            color: #666;
                            margin: 0 0 3px 0;
                            font-size: 16px;
                            font-weight: 400;
                        }
                        .print-date {
                            color: #888;
                            font-size: 11px;
                            margin-top: 5px;
                        }
                        .appointment-details-grid {
                            display: grid;
                            grid-template-columns: 1fr 1fr;
                            gap: 10px;
                            margin-bottom: 10px;
                        }
                        .details-section {
                            background: #f8f9fa;
                            border: 1px solid #e9ecef;
                            border-radius: 4px;
                            padding: 8px;
                            margin-bottom: 8px;
                            break-inside: avoid;
                        }
                        .details-section h4 {
                            margin: 0 0 8px 0;
                            color: #0077b6;
                            font-size: 13px;
                            font-weight: 600;
                            border-bottom: 1px solid #e9ecef;
                            padding-bottom: 4px;
                            display: flex;
                            align-items: center;
                            gap: 4px;
                        }
                        .detail-item {
                            display: flex;
                            justify-content: space-between;
                            align-items: flex-start;
                            margin-bottom: 4px;
                            gap: 8px;
                        }
                        .detail-label {
                            font-weight: 600;
                            color: #6c757d;
                            font-size: 11px;
                            min-width: 80px;
                            flex-shrink: 0;
                        }
                        .detail-value {
                            color: #495057;
                            font-size: 11px;
                            text-align: right;
                            flex-grow: 1;
                            word-break: break-word;
                        }
                        .detail-value.highlight {
                            background: #e3f2fd;
                            padding: 2px 4px;
                            border-radius: 3px;
                            font-weight: 600;
                            color: #1565c0;
                        }
                        .vitals-group {
                            background: #ffffff;
                            border: 1px solid #e9ecef;
                            border-radius: 4px;
                            padding: 6px;
                            margin-bottom: 6px;
                        }
                        .vitals-group h6 {
                            margin: 0 0 4px 0;
                            font-weight: 600;
                            color: #0077b6;
                            display: flex;
                            align-items: center;
                            gap: 4px;
                            font-size: 11px;
                        }
                        .print-footer {
                            margin-top: 15px;
                            padding-top: 8px;
                            border-top: 1px solid #ddd;
                            text-align: center;
                            color: #888;
                            font-size: 9px;
                        }
                        
                        /* QR Code specific styles */
                        .qr-code-container {
                            text-align: center;
                            margin: 10px 0;
                        }
                        
                        .qr-code-container img {
                            max-width: 120px;
                            max-height: 120px;
                            border: 1px solid #ddd;
                            border-radius: 4px;
                            display: block;
                            margin: 0 auto;
                        }
                        
                        .qr-code-description {
                            margin-top: 5px;
                            font-size: 10px;
                            color: #6c757d;
                            text-align: center;
                        }
                        
                        /* Print-specific styles for single page */
                        @media print {
                            @page {
                                size: A4;
                                margin: 0.5in;
                            }
                            body {
                                padding: 0;
                                font-size: 10px;
                                line-height: 1.2;
                            }
                            .print-header {
                                margin-bottom: 10px;
                                padding-bottom: 8px;
                            }
                            .print-header h1 {
                                font-size: 18px;
                                margin-bottom: 3px;
                            }
                            .print-header h2 {
                                font-size: 14px;
                                margin-bottom: 2px;
                            }
                            .print-date {
                                font-size: 9px;
                                margin-top: 3px;
                            }
                            .appointment-details-grid {
                                grid-template-columns: 1fr 1fr;
                                gap: 8px;
                                margin-bottom: 8px;
                            }
                            .details-section {
                                padding: 6px;
                                margin-bottom: 6px;
                            }
                            .details-section h4 {
                                font-size: 11px;
                                margin-bottom: 6px;
                                padding-bottom: 3px;
                            }
                            .detail-item {
                                margin-bottom: 3px;
                                gap: 6px;
                            }
                            .detail-label, .detail-value {
                                font-size: 9px;
                            }
                            .detail-label {
                                min-width: 70px;
                            }
                            .vitals-group {
                                padding: 4px;
                                margin-bottom: 4px;
                            }
                            .vitals-group h6 {
                                font-size: 9px;
                                margin-bottom: 3px;
                            }
                            .print-footer {
                                margin-top: 10px;
                                padding-top: 6px;
                                font-size: 8px;
                            }
                            .print-footer p {
                                margin: 2px 0;
                            }
                            
                            /* Ensure vitals grid is compact */
                            .details-section[style*="grid"] > div {
                                display: grid !important;
                                grid-template-columns: 1fr 1fr !important;
                                gap: 4px !important;
                            }
                            
                            /* QR Code print styles */
                            .qr-code-container {
                                margin: 8px 0;
                            }
                            
                            .qr-code-container img {
                                max-width: 100px;
                                max-height: 100px;
                            }
                            
                            .qr-code-description {
                                font-size: 8px;
                                margin-top: 3px;
                            }
                        }
                        
                        /* Hide icons in print for cleaner look */
                        @media print {
                            .fas, .fa {
                                display: none !important;
                            }
                        }
                        
                        .signatory-section {
                            margin: 30px 0 20px 0;
                            padding: 15px;
                            border-top: 1px solid #ddd;
                        }
                        .signatory-info {
                            text-align: right;
                            max-width: 300px;
                            margin-left: auto;
                        }
                        .signature-line {
                            margin-bottom: 40px;
                            border-bottom: 1px solid #333;
                            padding-bottom: 2px;
                        }
                        .signature-text {
                            font-size: 11px;
                            color: #666;
                        }
                        .signatory-details {
                            text-align: center;
                            font-size: 11px;
                            line-height: 1.3;
                        }
                        .signatory-details strong {
                            font-size: 12px;
                            color: #333;
                            text-transform: uppercase;
                        }
                        .signatory-role {
                            color: #666;
                            text-transform: capitalize;
                            font-style: italic;
                        }
                        .signatory-facility {
                            color: #0077b6;
                            font-weight: 500;
                        }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h1>${facilityName}</h1>
                        <h2>Prescription Details Report</h2>
                        <div class="print-date">
                            Generated on: ${currentDate} at ${currentTime}
                        </div>
                    </div>
                    
                    <div class="print-content">
                        ${modalContent}
                    </div>
                    
                    <div class="signatory-section">
                        <div class="signatory-info">
                            <div class="signature-line">
                                <span class="signature-text">Prepared by:</span>
                            </div>
                            <div class="signatory-details" style="
    margin-top: 60px;
    border-top-style: ridge;
    width: 250px;
">
                                <strong>${currentPrescriptionData.prescribed_by_doctor || 'N/A'}</strong><br>
                                <span class="signatory-role">${currentPrescriptionData.doctor_position || 'N/A'}</span><br>
                                <span class="signatory-facility">${currentPrescriptionData.facility_name || 'N/A'}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="print-footer">
                        <p>This document was generated from the ${facilityName} Medical Records System</p>
                        <p>For official use only - Contains confidential patient information</p>
                    </div>
                </body>
                </html>
            `);

            printWindow.document.close();

            // Wait for content to load, then print
            printWindow.onload = function() {
                setTimeout(() => {
                    printWindow.print();
                    printWindow.close();
                }, 300);
            };
        }
        
        // Print referral (direct print without modal)
        function printReferral(referralId) {
            console.log('printReferral called with ID:', referralId);
            
            // Find the referral data from current referrals
            const referral = currentReferrals.find(r => r.referral_id === referralId);
            if (referral) {
                displayReferralDetails(referral);
                setTimeout(() => printReferralDetails(), 100);
            } else {
                console.error('Referral not found in current data');
            }
        }
        
        // Modal and appointment viewing functions (from appointments_management.php)
        let currentAppointmentId = null;

        function viewAppointment(appointmentId) {
            currentAppointmentId = appointmentId;

            // Show loading
            document.getElementById('appointmentDetailsContent').innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <div class="loader"></div>
                    <p style="margin-top: 10px; color: #6c757d;">Loading appointment details...</p>
                </div>
            `;
            document.getElementById('viewAppointmentModal').style.display = 'block';

            // Get appointment data
            fetch(`api/get_appointment_details.php?appointment_id=${appointmentId}`, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Cache-Control': 'no-cache'
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    // Get the response text first to debug JSON parsing issues
                    return response.text().then(text => {
                        // Debug: Log raw response for troubleshooting
                        console.log('Raw API Response:', text);
                        console.log('Response length:', text.length);
                        
                        if (!text || text.trim() === '') {
                            throw new Error('Empty response from server');
                        }
                        
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('JSON Parse Error:', e);
                            console.error('Response Text:', text);
                            throw new Error(`Invalid JSON response: ${text.substring(0, 200)}...`);
                        }
                    });
                })
                .then(data => {
                    if (data.success) {
                        displayAppointmentDetails(data.appointment);
                    } else {
                        showErrorInModal('Error loading appointment details: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showErrorInModal('Network error: Unable to load appointment details. Please check your connection and try again.');
                });
        }

        function showErrorInModal(message) {
            document.getElementById('appointmentDetailsContent').innerHTML = `
                <div style="text-align: center; padding: 30px; color: #dc3545;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.7;"></i>
                    <h4 style="color: #dc3545; margin-bottom: 10px;">Error</h4>
                    <p style="margin: 0; line-height: 1.5;">${message}</p>
                    <button onclick="closeModal('viewAppointmentModal')" class="btn btn-secondary" style="margin-top: 20px;">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            `;
        }

        function formatAttendanceStatus(status) {
            if (!status) return null;

            const statusMap = {
                'early': 'Early Arrival',
                'on_time': 'On Time',
                'late': 'Late Arrival',
                'no_show': 'No Show',
                'left_early': 'Left Early'
            };

            return statusMap[status] || status.charAt(0).toUpperCase() + status.slice(1);
        }

        // Helper functions to generate vital status indicators for appointment details
        function getBloodPressureStatus(systolic, diastolic) {
            if (!systolic && !diastolic) return '';

            let status = '';
            let className = '';
            let icon = '';

            if (systolic && diastolic) {
                // Both values present - full assessment
                if (systolic >= 180 || diastolic >= 120) {
                    status = 'CRITICAL HIGH';
                    className = 'critical';
                    icon = '⚠️';
                } else if (systolic >= 140 || diastolic >= 90) {
                    status = 'HIGH';
                    className = 'high';
                    icon = '⬆️';
                } else if (systolic < 90 || diastolic < 60) {
                    status = 'LOW';
                    className = 'low';
                    icon = '⬇️';
                } else {
                    status = 'NORMAL';
                    className = 'normal';
                    icon = '✓';
                }
            } else if (systolic) {
                // Only systolic
                if (systolic >= 180) {
                    status = 'SYS CRITICAL';
                    className = 'critical';
                    icon = '⚠️';
                } else if (systolic >= 140) {
                    status = 'SYS HIGH';
                    className = 'high';
                    icon = '⬆️';
                } else if (systolic < 90) {
                    status = 'SYS LOW';
                    className = 'low';
                    icon = '⬇️';
                } else {
                    status = 'SYS NORMAL';
                    className = 'normal';
                    icon = '✓';
                }
            } else if (diastolic) {
                // Only diastolic
                if (diastolic >= 120) {
                    status = 'DIA CRITICAL';
                    className = 'critical';
                    icon = '⚠️';
                } else if (diastolic >= 90) {
                    status = 'DIA HIGH';
                    className = 'high';
                    icon = '⬆️';
                } else if (diastolic < 60) {
                    status = 'DIA LOW';
                    className = 'low';
                    icon = '⬇️';
                } else {
                    status = 'DIA NORMAL';
                    className = 'normal';
                    icon = '✓';
                }
            }

            return `<span class="vital-status ${className}" style="margin-left: 8px; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 2px;">${icon} ${status}</span>`;
        }

        function getHeartRespiratoryStatus(heartRate, respRate) {
            if (!heartRate && !respRate) return '';

            let status = '';
            let className = '';
            let icon = '';

            // Check heart rate (60-100 bpm normal for adults)
            let heartStatus = '';
            if (heartRate) {
                if (heartRate < 60) {
                    heartStatus = 'HR LOW';
                    className = 'low';
                } else if (heartRate > 100) {
                    heartStatus = 'HR HIGH';
                    className = className === 'low' ? 'high' : (className || 'high');
                } else {
                    heartStatus = 'HR NORMAL';
                    className = className || 'normal';
                }
            }

            // Check respiratory rate (12-20 breaths/min normal for adults)
            let respStatus = '';
            if (respRate) {
                if (respRate < 12) {
                    respStatus = 'RR LOW';
                    className = className === 'normal' ? 'low' : (className || 'low');
                } else if (respRate > 20) {
                    respStatus = 'RR HIGH';
                    className = 'high';
                } else {
                    respStatus = 'RR NORMAL';
                    className = className === 'low' || className === 'high' ? className : 'normal';
                }
            }

            // Combine statuses
            if (heartStatus && respStatus) {
                if (className === 'normal') {
                    status = 'NORMAL';
                    icon = '✓';
                } else if (className === 'high') {
                    status = 'ELEVATED';
                    icon = '⬆️';
                } else {
                    status = 'LOW';
                    icon = '⬇️';
                }
            } else {
                status = heartStatus || respStatus;
                icon = className === 'normal' ? '✓' : (className === 'high' ? '⬆️' : '⬇️');
            }

            return `<span class="vital-status ${className}" style="margin-left: 8px; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 2px;">${icon} ${status}</span>`;
        }

        function getTemperatureStatus(temp) {
            if (!temp) return '';

            let status = '';
            let className = '';
            let icon = '';

            // Normal temperature range: 36.1°C - 37.2°C (97°F - 99°F)
            if (temp >= 39.0) {
                status = 'HIGH FEVER';
                className = 'critical';
                icon = '🔥';
            } else if (temp >= 38.0) {
                status = 'FEVER';
                className = 'high';
                icon = '⬆️';
            } else if (temp >= 37.3) {
                status = 'ELEVATED';
                className = 'high';
                icon = '⬆️';
            } else if (temp >= 36.1) {
                status = 'NORMAL';
                className = 'normal';
                icon = '✓';
            } else if (temp >= 35.0) {
                status = 'LOW';
                className = 'low';
                icon = '⬇️';
            } else {
                status = 'HYPOTHERMIA';
                className = 'critical';
                icon = '❄️';
            }

            return `<span class="vital-status ${className}" style="margin-left: 8px; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 2px;">${icon} ${status}</span>`;
        }

        function getBMIStatus(weight, height) {
            if (!weight || !height || weight <= 0 || height <= 0) return '';

            const heightInMeters = height / 100;
            const bmi = (weight / (heightInMeters * heightInMeters)).toFixed(1);

            let status = '';
            let className = '';
            let icon = '';

            if (bmi < 18.5) {
                status = 'UNDERWEIGHT';
                className = 'low';
                icon = '⬇️';
            } else if (bmi < 25) {
                status = 'NORMAL BMI';
                className = 'normal';
                icon = '✓';
            } else if (bmi < 30) {
                status = 'OVERWEIGHT';
                className = 'high';
                icon = '⬆️';
            } else if (bmi < 35) {
                status = 'OBESE I';
                className = 'high';
                icon = '⚠️';
            } else if (bmi < 40) {
                status = 'OBESE II';
                className = 'critical';
                icon = '⚠️';
            } else {
                status = 'OBESE III';
                className = 'critical';
                icon = '🚨';
            }

            return `<span class="vital-status ${className}" style="margin-left: 8px; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; display: inline-flex; align-items: center; gap: 2px;">${icon} ${status}</span>`;
        }

        function displayAppointmentDetails(appointment) {
            // Debug: Log the appointment data to see what we're receiving
            console.log('Appointment data received:', appointment);
            console.log('Visit details:', appointment.visit_details);
            console.log('Vitals data:', appointment.vitals);

            // Store appointment data globally for print function
            currentAppointmentData = appointment;

            const content = `
                <div class="appointment-details-grid">
                    <div class="details-section">
                        <h4><i class="fas fa-user"></i> Patient Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Name:</span>
                            <span class="detail-value">${appointment.patient_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Patient ID:</span>
                            <span class="detail-value">${appointment.patient_id || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Contact:</span>
                            <span class="detail-value">${appointment.contact_number || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Age/Sex:</span>
                            <span class="detail-value">${(appointment.age || 'N/A')}/${(appointment.sex || 'N/A')}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Barangay:</span>
                            <span class="detail-value">${appointment.barangay_name || 'N/A'}</span>
                        </div>
                    </div>
                    
                    <div class="details-section">
                        <h4><i class="fas fa-calendar"></i> Appointment Details</h4>
                        <div class="detail-item">
                            <span class="detail-label">Appointment ID:</span>
                            <span class="detail-value highlight">APT-${appointment.appointment_id ? String(appointment.appointment_id).padStart(8, '0') : 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Date:</span>
                            <span class="detail-value">${appointment.appointment_date || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Time Slot:</span>
                            <span class="detail-value highlight">${appointment.time_slot || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status:</span>
                            <span class="detail-value">${appointment.status || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Facility:</span>
                            <span class="detail-value">${appointment.facility_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Service:</span>
                            <span class="detail-value">${appointment.service_name || 'General Consultation'}</span>
                        </div>
                    </div>
                </div>
                
                <div class="details-section">
                    <h4><i class="fas fa-file-medical"></i> Referral Information</h4>
                        ${appointment.referral_id ? `
                            <div class="detail-item">
                                <span class="detail-label">Referral ID:</span>
                                <span class="detail-value highlight">${appointment.referral_id}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Referral Status:</span>
                                <span class="detail-value" style="color: #28a745; font-weight: 600;">Required</span>
                            </div>
                        ` : `
                            <div class="detail-item">
                                <span class="detail-label">Referral Status:</span>
                                <span class="detail-value" style="color: #6c757d;">This appointment did not need a referral</span>
                            </div>
                        `}
                </div>

                ${((appointment.status || '').toLowerCase() === 'completed' || (appointment.status || '').toLowerCase() === 'cancelled') && appointment.visit_details ? `
                    <div class="details-section" style="border-left: 4px solid #17a2b8; background: #f8f9fa; margin-top: 20px;">
                        <h4><i class="fas fa-clipboard-check" style="color: #17a2b8;"></i> Visit Details</h4>
                        <div class="detail-item">
                            <span class="detail-label">Check-in Time:</span>
                            <span class="detail-value">${appointment.visit_details.time_in || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Check-out Time:</span>
                            <span class="detail-value">${appointment.visit_details.time_out || 'Not completed'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Attending Employee:</span>
                            <span class="detail-value">${appointment.visit_details.attending_employee_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Attendance Status:</span>
                            <span class="detail-value">${formatAttendanceStatus(appointment.visit_details.attendance_status) || 'N/A'}</span>
                        </div>
                        ${appointment.visit_details.remarks ? `
                            <div class="detail-item">
                                <span class="detail-label">Visit Remarks:</span>
                                <span class="detail-value">${appointment.visit_details.remarks}</span>
                            </div>
                        ` : ''}
                    </div>
                ` : ''}

                ${((appointment.status || '').toLowerCase() === 'completed' || (appointment.status || '').toLowerCase() === 'cancelled') && appointment.vitals ? `
                    <div class="details-section" style="border-left: 4px solid #28a745; background: #f8fff8; margin-top: 20px;">
                        <h4><i class="fas fa-heartbeat" style="color: #28a745;"></i> Recorded Vitals</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                            ${appointment.vitals.systolic_bp || appointment.vitals.diastolic_bp ? `
                                <div class="vitals-group">
                                    <h6 style="color: #0077b6; margin-bottom: 8px; font-size: 14px;">
                                        <i class="fas fa-heart" style="color: #dc3545;"></i> Blood Pressure
                                    </h6>
                                    <div class="detail-item">
                                        <span class="detail-label">Systolic:</span>
                                        <span class="detail-value">${appointment.vitals.systolic_bp || 'N/A'} mmHg</span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">Diastolic:</span>
                                        <span class="detail-value">${appointment.vitals.diastolic_bp || 'N/A'} mmHg ${getBloodPressureStatus(appointment.vitals.systolic_bp, appointment.vitals.diastolic_bp)}</span>
                                    </div>
                                </div>
                            ` : ''}
                            
                            ${appointment.vitals.heart_rate || appointment.vitals.respiratory_rate ? `
                                <div class="vitals-group">
                                    <h6 style="color: #0077b6; margin-bottom: 8px; font-size: 14px;">
                                        <i class="fas fa-heartbeat" style="color: #e74c3c;"></i> Heart & Respiratory
                                    </h6>
                                    ${appointment.vitals.heart_rate ? `
                                        <div class="detail-item">
                                            <span class="detail-label">Heart Rate:</span>
                                            <span class="detail-value">${appointment.vitals.heart_rate} bpm</span>
                                        </div>
                                    ` : ''}
                                    ${appointment.vitals.respiratory_rate ? `
                                        <div class="detail-item">
                                            <span class="detail-label">Respiratory Rate:</span>
                                            <span class="detail-value">${appointment.vitals.respiratory_rate} breaths/min ${getHeartRespiratoryStatus(appointment.vitals.heart_rate, appointment.vitals.respiratory_rate)}</span>
                                        </div>
                                    ` : ''}
                                </div>
                            ` : ''}
                            
                            ${appointment.vitals.temperature ? `
                                <div class="vitals-group">
                                    <h6 style="color: #0077b6; margin-bottom: 8px; font-size: 14px;">
                                        <i class="fas fa-thermometer-half" style="color: #f39c12;"></i> Temperature
                                    </h6>
                                    <div class="detail-item">
                                        <span class="detail-label">Temperature:</span>
                                        <span class="detail-value">${appointment.vitals.temperature}°C ${getTemperatureStatus(appointment.vitals.temperature)}</span>
                                    </div>
                                </div>
                            ` : ''}
                            
                            ${appointment.vitals.weight || appointment.vitals.height ? `
                                <div class="vitals-group">
                                    <h6 style="color: #0077b6; margin-bottom: 8px; font-size: 14px;">
                                        <i class="fas fa-weight" style="color: #9b59b6;"></i> Physical Measurements
                                    </h6>
                                    ${appointment.vitals.weight ? `
                                        <div class="detail-item">
                                            <span class="detail-label">Weight:</span>
                                            <span class="detail-value">${appointment.vitals.weight} kg</span>
                                        </div>
                                    ` : ''}
                                    ${appointment.vitals.height ? `
                                        <div class="detail-item">
                                            <span class="detail-label">Height:</span>
                                            <span class="detail-value">${appointment.vitals.height} cm</span>
                                        </div>
                                    ` : ''}
                                    ${appointment.vitals.weight && appointment.vitals.height ? `
                                        <div class="detail-item">
                                            <span class="detail-label">BMI:</span>
                                            <span class="detail-value">${((appointment.vitals.weight / Math.pow(appointment.vitals.height/100, 2)).toFixed(1))} ${getBMIStatus(appointment.vitals.weight, appointment.vitals.height)}</span>
                                        </div>
                                    ` : ''}
                                </div>
                            ` : ''}
                        </div>
                        
                        ${appointment.vitals.remarks ? `
                            <div class="detail-item" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e9ecef;">
                                <span class="detail-label">Vitals Remarks:</span>
                                <span class="detail-value">${appointment.vitals.remarks}</span>
                            </div>
                        ` : ''}
                        
                        <div class="detail-item" style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #e9ecef;">
                            <span class="detail-label">Recorded At:</span>
                            <span class="detail-value" style="color: #6c757d; font-size: 0.9em;">${appointment.vitals.recorded_at || 'N/A'}</span>
                        </div>
                    </div>
                ` : (((appointment.status || '').toLowerCase() === 'completed' || (appointment.status || '').toLowerCase() === 'cancelled')) ? `
                    <div class="details-section" style="border-left: 4px solid #ffc107; background: #fffef7; margin-top: 20px;">
                        <h4><i class="fas fa-exclamation-triangle" style="color: #ffc107;"></i> Vitals Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Vitals Status:</span>
                            <span class="detail-value" style="color: #856404;">No vitals were recorded for this visit</span>
                        </div>
                    </div>
                ` : ''}
                
                ${appointment.cancellation_reason ? `
                    <div class="details-section" style="border-left: 4px solid #dc3545; background: #fff5f5; margin-top: 20px;">
                        <h4><i class="fas fa-times-circle" style="color: #dc3545;"></i> Cancellation Details</h4>
                        <div class="detail-item">
                            <span class="detail-label">Reason:</span>
                            <span class="detail-value">${appointment.cancellation_reason}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Cancelled At:</span>
                            <span class="detail-value">${appointment.updated_at || 'N/A'}</span>
                        </div>
                    </div>
                ` : ''}
            `;

            document.getElementById('appointmentDetailsContent').innerHTML = content;
        }

        function printAppointmentDetails() {
            // Get the appointment details content
            const modalContent = document.getElementById('appointmentDetailsContent').innerHTML;
            const facilityName = "<?php echo htmlspecialchars($facility_name); ?>";
            const currentDate = new Date().toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            const currentTime = new Date().toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit'
            });

            // Create a new window for printing
            const printWindow = window.open('', '_blank', 'width=800,height=600');

            // Write the print content
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Appointment Details - ${facilityName}</title>
                    <style>
                        body {
                            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                            margin: 0;
                            padding: 15px;
                            color: #333;
                            line-height: 1.4;
                            font-size: 12px;
                        }
                        .print-header {
                            text-align: center;
                            border-bottom: 2px solid #0077b6;
                            padding-bottom: 10px;
                            margin-bottom: 15px;
                        }
                        .print-header h1 {
                            color: #0077b6;
                            margin: 0 0 5px 0;
                            font-size: 22px;
                            font-weight: 600;
                        }
                        .print-header h2 {
                            color: #666;
                            margin: 0 0 3px 0;
                            font-size: 16px;
                            font-weight: 400;
                        }
                        .print-date {
                            color: #888;
                            font-size: 11px;
                            margin-top: 5px;
                        }
                        .appointment-details-grid {
                            display: grid;
                            grid-template-columns: 1fr 1fr;
                            gap: 10px;
                            margin-bottom: 10px;
                        }
                        .details-section {
                            background: #f8f9fa;
                            border: 1px solid #e9ecef;
                            border-radius: 4px;
                            padding: 8px;
                            margin-bottom: 8px;
                            break-inside: avoid;
                        }
                        .details-section h4 {
                            margin: 0 0 8px 0;
                            color: #0077b6;
                            font-size: 13px;
                            font-weight: 600;
                            border-bottom: 1px solid #e9ecef;
                            padding-bottom: 4px;
                            display: flex;
                            align-items: center;
                            gap: 4px;
                        }
                        .detail-item {
                            display: flex;
                            justify-content: space-between;
                            align-items: flex-start;
                            margin-bottom: 4px;
                            gap: 8px;
                        }
                        .detail-label {
                            font-weight: 600;
                            color: #6c757d;
                            font-size: 11px;
                            min-width: 80px;
                            flex-shrink: 0;
                        }
                        .detail-value {
                            color: #495057;
                            font-size: 11px;
                            text-align: right;
                            flex-grow: 1;
                            word-break: break-word;
                        }
                        .detail-value.highlight {
                            background: #e3f2fd;
                            padding: 2px 4px;
                            border-radius: 3px;
                            font-weight: 600;
                            color: #1565c0;
                        }
                        .vitals-group {
                            background: #ffffff;
                            border: 1px solid #e9ecef;
                            border-radius: 4px;
                            padding: 6px;
                            margin-bottom: 6px;
                        }
                        .vitals-group h6 {
                            margin: 0 0 4px 0;
                            font-weight: 600;
                            color: #0077b6;
                            display: flex;
                            align-items: center;
                            gap: 4px;
                            font-size: 11px;
                        }
                        .print-footer {
                            margin-top: 15px;
                            padding-top: 8px;
                            border-top: 1px solid #ddd;
                            text-align: center;
                            color: #888;
                            font-size: 9px;
                        }
                        
                        /* QR Code specific styles */
                        .qr-code-container {
                            text-align: center;
                            margin: 10px 0;
                        }
                        
                        .qr-code-container img {
                            max-width: 120px;
                            max-height: 120px;
                            border: 1px solid #ddd;
                            border-radius: 4px;
                            display: block;
                            margin: 0 auto;
                        }
                        
                        .qr-code-description {
                            margin-top: 5px;
                            font-size: 10px;
                            color: #6c757d;
                            text-align: center;
                        }
                        
                        /* Print-specific styles for single page */
                        @media print {
                            @page {
                                size: A4;
                                margin: 0.5in;
                            }
                            body {
                                padding: 0;
                                font-size: 10px;
                                line-height: 1.2;
                            }
                            .print-header {
                                margin-bottom: 10px;
                                padding-bottom: 8px;
                            }
                            .print-header h1 {
                                font-size: 18px;
                                margin-bottom: 3px;
                            }
                            .print-header h2 {
                                font-size: 14px;
                                margin-bottom: 2px;
                            }
                            .print-date {
                                font-size: 9px;
                                margin-top: 3px;
                            }
                            .appointment-details-grid {
                                grid-template-columns: 1fr 1fr;
                                gap: 8px;
                                margin-bottom: 8px;
                            }
                            .details-section {
                                padding: 6px;
                                margin-bottom: 6px;
                            }
                            .details-section h4 {
                                font-size: 11px;
                                margin-bottom: 6px;
                                padding-bottom: 3px;
                            }
                            .detail-item {
                                margin-bottom: 3px;
                                gap: 6px;
                            }
                            .detail-label, .detail-value {
                                font-size: 9px;
                            }
                            .detail-label {
                                min-width: 70px;
                            }
                            .vitals-group {
                                padding: 4px;
                                margin-bottom: 4px;
                            }
                            .vitals-group h6 {
                                font-size: 9px;
                                margin-bottom: 3px;
                            }
                            .print-footer {
                                margin-top: 10px;
                                padding-top: 6px;
                                font-size: 8px;
                            }
                            .print-footer p {
                                margin: 2px 0;
                            }
                            
                            /* Ensure vitals grid is compact */
                            .details-section[style*="grid"] > div {
                                display: grid !important;
                                grid-template-columns: 1fr 1fr !important;
                                gap: 4px !important;
                            }
                            
                            /* QR Code print styles */
                            .qr-code-container {
                                margin: 8px 0;
                            }
                            
                            .qr-code-container img {
                                max-width: 100px;
                                max-height: 100px;
                            }
                            
                            .qr-code-description {
                                font-size: 8px;
                                margin-top: 3px;
                            }
                        }
                        
                        /* Hide icons in print for cleaner look */
                        @media print {
                            .fas, .fa {
                                display: none !important;
                            }
                        }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h1>${facilityName}</h1>
                        <h2>Appointment Details Report</h2>
                        <div class="print-date">
                            Generated on: ${currentDate} at ${currentTime}
                        </div>
                    </div>
                    
                    <div class="print-content">
                        ${modalContent}
                    </div>
                    
                    <!-- Signatory section commented out - pending decision
                    <div class="signatory-section">
                        <div class="signatory-info">
                            <div class="signature-line">
                                <span class="signature-text">Prepared by:</span>
                            </div>
                            <div class="signatory-details" style="
    margin-top: 60px;
    border-top-style: ridge;
    width: 250px;
">
                                <strong>${currentAppointmentData.attending_staff || 'N/A'}</strong><br>
                                <span class="signatory-role">${currentAppointmentData.staff_position || 'Staff'}</span><br>
                                <span class="signatory-facility">${currentAppointmentData.facility_name || 'N/A'}</span>
                            </div>
                        </div>
                    </div>
                    -->
                    
                    <div class="print-footer">
                        <p>This document was generated from the ${facilityName} Medical Records System</p>
                        <p>For official use only - Contains confidential patient information</p>
                    </div>
                </body>
                </html>
            `);

            printWindow.document.close();

            // Wait for content to load, then print
            printWindow.onload = function() {
                setTimeout(() => {
                    printWindow.print();
                    // Close the print window after printing (optional)
                    printWindow.onafterprint = function() {
                        printWindow.close();
                    };
                }, 250);
            };
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('viewAppointmentModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
        
        // ESC key support for closing modals
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modal = document.getElementById('viewAppointmentModal');
                if (modal.style.display === 'block') {
                    modal.style.display = 'none';
                }
            }
        });
        
        // Clear search function
        function clearSearch() {
            document.getElementById('patient').value = '';
            const barangaySelect = document.getElementById('barangay');
            if (barangaySelect && barangaySelect.tagName === 'SELECT') {
                barangaySelect.value = '';
            }
            
            // Reset all state variables
            isPatientSelected = false;
            selectedPatientId = null;
            selectedPatientName = '';
            currentPage = 1;
            totalPages = 1;
            totalPatients = 0;
            currentSearchTerm = '';
            currentBarangayFilter = '';
            currentSortColumn = '';
            currentSortDirection = 'ASC';
            currentPatients = [];
            
            // Reset sort icons
            updateSortIcons('', 'ASC');
            
            // Hide medical history section
            document.getElementById('medicalHistorySection').style.display = 'none';
            
            // Reload all patients
            performPatientSearch(1);
        }
        
        // Original sort function
        function sortTable(column) {
            const currentUrl = new URL(window.location);
            const currentSort = currentUrl.searchParams.get('sort');
            const currentDir = currentUrl.searchParams.get('dir');
            
            let newDir = 'ASC';
            if (currentSort === column && currentDir === 'ASC') {
                newDir = 'DESC';
            }
            
            currentUrl.searchParams.set('sort', column);
            currentUrl.searchParams.set('dir', newDir);
            
            window.location.href = currentUrl.toString();
        }

        // ============== CONSULTATIONS FUNCTIONALITY ==============

        function loadConsultationsHistory(patientId) {
            console.log('loadConsultationsHistory called with patientId:', patientId, 'type:', typeof patientId);
            
            // Ensure patientId is a valid number
            const numericPatientId = parseInt(patientId);
            if (isNaN(numericPatientId) || numericPatientId <= 0) {
                console.error('Invalid patient ID:', patientId);
                const emptyDiv = document.getElementById('consultationsEmpty');
                emptyDiv.style.display = 'block';
                emptyDiv.querySelector('h3').textContent = 'Invalid Patient ID';
                emptyDiv.querySelector('p').textContent = 'The patient ID is not valid.';
                return;
            }
            
            const loadingDiv = document.getElementById('consultationsLoading');
            const emptyDiv = document.getElementById('consultationsEmpty');
            const tableDiv = document.getElementById('consultationsTable');
            
            // Show loading state
            loadingDiv.style.display = 'block';
            emptyDiv.style.display = 'none';
            tableDiv.style.display = 'none';
            
            const apiUrl = APP_CONFIG.apiPath(`get_patient_consultations.php?patient_id=${numericPatientId}`);
            console.log('Making API request to:', apiUrl);
            
            // Fetch consultations data
            fetch(apiUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                console.log('API response status:', response.status, response.statusText);
                if (!response.ok) {
                    if (response.status === 401) {
                        throw new Error('Session expired. Please log in again.');
                    }
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.text().then(text => {
                    console.log('Raw API response:', text.substring(0, 500));
                    if (text.trim().startsWith('<!DOCTYPE') || text.trim().startsWith('<html')) {
                        throw new Error('Session expired. Please refresh the page and log in again.');
                    }
                    
                    try {
                        const jsonData = JSON.parse(text);
                        console.log('Parsed JSON response:', jsonData);
                        return jsonData;
                    } catch (e) {
                        console.error('JSON Parse Error:', e);
                        console.error('Response content:', text.substring(0, 200));
                        throw new Error(`Invalid response format. Expected JSON but got: ${text.substring(0, 100)}...`);
                    }
                });
            })
            .then(data => {
                console.log('API response received:', data);
                loadingDiv.style.display = 'none';
                
                if (data.success && data.consultations && data.consultations.length > 0) {
                    console.log('Found', data.consultations.length, 'consultations');
                    
                    // Store consultations data for pagination and sorting
                    currentConsultations = data.consultations;
                    totalConsultations = data.consultations.length;
                    totalConsultationsPages = Math.ceil(totalConsultations / consultationsPerPage);
                    currentConsultationsPage = 1;
                    
                    // Sort consultations by default (consultation_date DESC)
                    sortConsultationsByColumn(consultationsSortColumn, consultationsSortDirection);
                    
                    displayConsultationsTable();
                    tableDiv.style.display = 'block';
                } else {
                    console.log('No consultations found or API error:', data);
                    emptyDiv.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error loading consultations:', error);
                loadingDiv.style.display = 'none';
                emptyDiv.style.display = 'block';
                
                // Show user-friendly error messages
                if (error.message.includes('Session expired') || error.message.includes('Session may have expired')) {
                    emptyDiv.querySelector('h3').textContent = 'Session Expired';
                    emptyDiv.querySelector('p').innerHTML = 'Your session has expired. Please <a href="javascript:window.location.reload();" style="color: #0077b6; text-decoration: underline;">refresh the page</a> and log in again.';
                } else {
                    emptyDiv.querySelector('h3').textContent = 'Error Loading Consultations';
                    emptyDiv.querySelector('p').textContent = 'Unable to load consultation history. Please try again.';
                }
            });
        }
        
        // Display consultations in table with pagination
        function displayConsultationsTable() {
            const tbody = document.getElementById('consultationsTableBody');
            tbody.innerHTML = '';
            
            // Calculate pagination
            const startIndex = (currentConsultationsPage - 1) * consultationsPerPage;
            const endIndex = Math.min(startIndex + consultationsPerPage, totalConsultations);
            const pageConsultations = currentConsultations.slice(startIndex, endIndex);
            
            pageConsultations.forEach(consultation => {
                const row = document.createElement('tr');
                
                // Format consultation date and time
                const consultationDate = new Date(consultation.consultation_date).toLocaleDateString('en-US');
                const consultationTime = new Date(consultation.consultation_date).toLocaleTimeString('en-US', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                // Status badge
                let statusClass = 'badge-secondary';
                switch (consultation.status?.toLowerCase()) {
                    case 'completed':
                        statusClass = 'badge-success';
                        break;
                    case 'ongoing':
                        statusClass = 'badge-primary';
                        break;
                    case 'pending':
                        statusClass = 'badge-warning';
                        break;
                    case 'cancelled':
                        statusClass = 'badge-danger';
                        break;
                }
                
                // Truncate long text for display
                const truncateText = (text, maxLength = 30) => {
                    if (!text || text === 'N/A') return text || 'N/A';
                    return text.length > maxLength ? text.substring(0, maxLength) + '...' : text;
                };
                
                row.innerHTML = `
                    <td>
                        <div style="font-weight: 500;">${consultationDate}</div>
                        <div style="font-size: 0.85em; color: #6c757d;">${consultationTime}</div>
                    </td>
                    <td>
                        <div style="font-weight: 500;">${consultation.doctor_name || 'N/A'}</div>
                        <div style="font-size: 0.85em; color: #6c757d;">${consultation.facility_name || 'N/A'}</div>
                    </td>
                    <td>${consultation.service_name || 'N/A'}</td>
                    <td title="${consultation.chief_complaint || 'N/A'}">
                        ${truncateText(consultation.chief_complaint)}
                    </td>
                    <td title="${consultation.diagnosis || 'N/A'}">
                        ${truncateText(consultation.diagnosis)}
                    </td>
                    <td><span class="badge ${statusClass}">${consultation.status || 'Unknown'}</span></td>
                    <td>
                        <div class="actions-group">
                            <button onclick="viewConsultationDetails(${consultation.encounter_id})" class="btn btn-primary btn-sm" title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </td>
                `;
                tbody.appendChild(row);
            });
            
            // Update pagination info and controls
            updateConsultationsPagination();
        }
        
        // Consultations sorting function
        function sortConsultationsTable(column) {
            console.log('Sorting consultations by:', column);
            
            // Toggle sort direction if same column, otherwise use default direction
            if (consultationsSortColumn === column) {
                consultationsSortDirection = consultationsSortDirection === 'ASC' ? 'DESC' : 'ASC';
            } else {
                consultationsSortColumn = column;
                // Set default direction based on column type
                if (column === 'consultation_date') {
                    consultationsSortDirection = 'DESC'; // Latest first
                } else {
                    consultationsSortDirection = 'ASC';
                }
            }
            
            // Update sort icons
            updateConsultationsSortIcons(column, consultationsSortDirection);
            
            // Sort and redisplay
            sortConsultationsByColumn(column, consultationsSortDirection);
            currentConsultationsPage = 1; // Reset to first page after sorting
            displayConsultationsTable();
        }
        
        // Function to sort consultations array
        function sortConsultationsByColumn(column, direction) {
            currentConsultations.sort((a, b) => {
                let valueA = a[column] || '';
                let valueB = b[column] || '';
                
                // Handle different data types
                if (column === 'consultation_date') {
                    valueA = new Date(valueA);
                    valueB = new Date(valueB);
                } else if (typeof valueA === 'string' && typeof valueB === 'string') {
                    valueA = valueA.toLowerCase();
                    valueB = valueB.toLowerCase();
                }
                
                if (valueA < valueB) return direction === 'ASC' ? -1 : 1;
                if (valueA > valueB) return direction === 'ASC' ? 1 : -1;
                return 0;
            });
        }
        
        // Update consultations sort icons
        function updateConsultationsSortIcons(activeColumn, direction) {
            // Reset all sort icons
            document.querySelectorAll('#consultationsTable th .sort-icon').forEach(icon => {
                icon.className = 'sort-icon fas fa-sort';
            });
            
            // Update active column icon
            const activeIcon = document.querySelector(`#consultationsTable th[data-sort="${activeColumn}"] .sort-icon`);
            if (activeIcon) {
                activeIcon.className = `sort-icon fas ${direction === 'ASC' ? 'fa-sort-up' : 'fa-sort-down'}`;
            }
        }
        
        // Update consultations pagination controls
        function updateConsultationsPagination() {
            const paginationControls = document.getElementById('consultationsPaginationControls');
            const paginationInfo = document.getElementById('consultationsPaginationInfo');
            totalConsultationsPages = Math.ceil(totalConsultations / consultationsPerPage);
            
            if (totalConsultationsPages <= 1) {
                paginationControls.style.display = 'none';
                return;
            }
            
            paginationControls.style.display = 'block';
            
            // Update pagination info
            const start = (currentConsultationsPage - 1) * consultationsPerPage + 1;
            const end = Math.min(currentConsultationsPage * consultationsPerPage, totalConsultations);
            paginationInfo.textContent = `Showing ${start}-${end} of ${totalConsultations} consultations`;
            
            // Update buttons
            document.getElementById('consultationsPrevPage').disabled = currentConsultationsPage <= 1;
            document.getElementById('consultationsNextPage').disabled = currentConsultationsPage >= totalConsultationsPages;
            
            // Update page numbers
            const pageNumbers = document.getElementById('consultationsPageNumbers');
            pageNumbers.innerHTML = '';
            
            // Show max 5 page numbers
            let startPage = Math.max(1, currentConsultationsPage - 2);
            let endPage = Math.min(totalConsultationsPages, startPage + 4);
            
            // Adjust if we're near the end
            if (endPage - startPage < 4) {
                startPage = Math.max(1, endPage - 4);
            }
            
            for (let i = startPage; i <= endPage; i++) {
                const pageBtn = document.createElement('span');
                pageBtn.className = `page-number ${i === currentConsultationsPage ? 'active' : ''}`;
                pageBtn.textContent = i;
                pageBtn.onclick = () => changeConsultationsPage(i, true);
                pageNumbers.appendChild(pageBtn);
            }
        }
        
        // Change consultations page
        function changeConsultationsPage(direction, isAbsolute = false) {
            let newPage;
            if (isAbsolute) {
                newPage = direction;
            } else {
                newPage = currentConsultationsPage + direction;
            }
            
            newPage = Math.max(1, Math.min(totalConsultationsPages, newPage));
            
            if (newPage !== currentConsultationsPage) {
                currentConsultationsPage = newPage;
                displayConsultationsTable();
            }
        }

        // ==================== PRESCRIPTIONS FUNCTIONS ====================
        
        // Load prescriptions history for selected patient
        function loadPrescriptionsHistory(patientId) {
            console.log('loadPrescriptionsHistory called with patientId:', patientId, 'type:', typeof patientId);
            
            // Ensure patientId is a valid number
            const numericPatientId = parseInt(patientId);
            if (isNaN(numericPatientId) || numericPatientId <= 0) {
                console.error('Invalid patient ID:', patientId);
                const emptyDiv = document.getElementById('prescriptionsEmpty');
                emptyDiv.style.display = 'block';
                emptyDiv.querySelector('h3').textContent = 'Invalid Patient ID';
                emptyDiv.querySelector('p').textContent = 'The patient ID is not valid.';
                return;
            }
            
            const loadingDiv = document.getElementById('prescriptionsLoading');
            const emptyDiv = document.getElementById('prescriptionsEmpty');
            const tableDiv = document.getElementById('prescriptionsTable');
            
            // Show loading state
            loadingDiv.style.display = 'block';
            emptyDiv.style.display = 'none';
            tableDiv.style.display = 'none';
            
            const apiUrl = APP_CONFIG.apiPath(`get_patient_prescriptions.php?patient_id=${numericPatientId}`);
            console.log('Making API request to:', apiUrl);
            
            // Fetch prescriptions data
            fetch(apiUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                console.log('API response status:', response.status, response.statusText);
                if (!response.ok) {
                    if (response.status === 401) {
                        throw new Error('Session expired. Please log in again.');
                    }
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.text().then(text => {
                    console.log('Raw API response:', text.substring(0, 500));
                    if (text.trim().startsWith('<!DOCTYPE') || text.trim().startsWith('<html')) {
                        throw new Error('Session expired. Please refresh the page and log in again.');
                    }
                    
                    try {
                        const jsonData = JSON.parse(text);
                        console.log('Parsed JSON response:', jsonData);
                        return jsonData;
                    } catch (e) {
                        console.error('JSON Parse Error:', e);
                        throw new Error('Invalid JSON response: ' + text.substring(0, 200));
                    }
                });
            })
            .then(data => {
                console.log('API response received:', data);
                loadingDiv.style.display = 'none';
                
                if (data.success && data.data && data.data.length > 0) {
                    console.log('Found', data.data.length, 'prescriptions');
                    
                    // Store prescriptions data for pagination and sorting
                    currentPrescriptions = data.data;
                    totalPrescriptions = data.data.length;
                    totalPrescriptionsPages = Math.ceil(totalPrescriptions / prescriptionsPerPage);
                    currentPrescriptionsPage = 1;
                    
                    // Sort prescriptions by default (prescription_date DESC)
                    sortPrescriptionsByColumn(prescriptionsSortColumn, prescriptionsSortDirection);
                    displayPrescriptionsTable();
                    tableDiv.style.display = 'block';
                } else {
                    emptyDiv.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error loading prescriptions:', error);
                loadingDiv.style.display = 'none';
                emptyDiv.style.display = 'block';
                
                // Show user-friendly error messages
                if (error.message.includes('Session expired') || error.message.includes('Session may have expired')) {
                    emptyDiv.querySelector('h3').textContent = 'Session Expired';
                    emptyDiv.querySelector('p').innerHTML = 'Your session has expired. Please <a href="javascript:window.location.reload();" style="color: #0077b6; text-decoration: underline;">refresh the page</a> and log in again.';
                } else {
                    emptyDiv.querySelector('h3').textContent = 'Error Loading Prescriptions';
                    emptyDiv.querySelector('p').textContent = 'An error occurred while loading prescriptions. Please try again.';
                }
            });
        }
        
        // Display prescriptions in table with pagination
        function displayPrescriptionsTable() {
            const tbody = document.getElementById('prescriptionsTableBody');
            tbody.innerHTML = '';
            
            // Calculate pagination
            const startIndex = (currentPrescriptionsPage - 1) * prescriptionsPerPage;
            const endIndex = Math.min(startIndex + prescriptionsPerPage, totalPrescriptions);
            const pagePrescriptions = currentPrescriptions.slice(startIndex, endIndex);
            
            pagePrescriptions.forEach(prescription => {
                const row = document.createElement('tr');
                
                // Format prescription ID
                const prescriptionId = `PRX-${String(prescription.prescription_id).padStart(8, '0')}`;
                
                // Format date
                let prescriptionDate = 'N/A';
                if (prescription.prescription_date) {
                    const date = new Date(prescription.prescription_date);
                    prescriptionDate = date.toLocaleDateString('en-US', {
                        year: 'numeric',
                        month: 'short',
                        day: '2-digit'
                    });
                }
                
                // Prescribed by doctor
                const prescribedBy = prescription.prescribed_by_doctor || 'N/A';
                
                // Medication count
                const medicationCount = prescription.medication_count || 0;
                const medicationsText = medicationCount === 1 ? '1 medication' : `${medicationCount} medications`;
                
                // Status badge
                let statusClass = 'badge-secondary';
                switch (prescription.overall_status?.toLowerCase()) {
                    case 'issued':
                        statusClass = 'badge-warning';
                        break;
                    case 'dispensed':
                        statusClass = 'badge-success';
                        break;
                    case 'cancelled':
                        statusClass = 'badge-danger';
                        break;
                    case 'active':
                        statusClass = 'badge-primary';
                        break;
                }
                
                row.innerHTML = `
                    <td>${prescriptionId}</td>
                    <td>${prescriptionDate}</td>
                    <td>${prescribedBy}</td>
                    <td><span class="badge badge-info">${medicationsText}</span></td>
                    <td><span class="badge ${statusClass}">${prescription.overall_status || 'Unknown'}</span></td>
                    <td>
                        <button onclick="viewPrescription(${prescription.prescription_id})" class="btn btn-sm btn-outline-primary" title="View Details">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </td>
                `;
                
                tbody.appendChild(row);
            });
            
            // Update pagination controls
            updatePrescriptionsPagination();
        }
        
        // Prescriptions sorting function
        function sortPrescriptionsTable(column) {
            console.log('Sorting prescriptions by:', column);
            
            // Toggle sort direction if same column, otherwise use default direction
            if (prescriptionsSortColumn === column) {
                prescriptionsSortDirection = prescriptionsSortDirection === 'ASC' ? 'DESC' : 'ASC';
            } else {
                prescriptionsSortColumn = column;
                // Set default direction based on column type
                if (column === 'prescription_date' || column === 'prescription_id') {
                    prescriptionsSortDirection = 'DESC'; // Latest first
                } else {
                    prescriptionsSortDirection = 'ASC';
                }
            }
            
            // Update sort icons
            updatePrescriptionsSortIcons(column, prescriptionsSortDirection);
            
            // Sort and redisplay
            sortPrescriptionsByColumn(column, prescriptionsSortDirection);
            currentPrescriptionsPage = 1; // Reset to first page after sorting
            displayPrescriptionsTable();
        }
        
        // Function to sort prescriptions array
        function sortPrescriptionsByColumn(column, direction) {
            currentPrescriptions.sort((a, b) => {
                let valueA = a[column] || '';
                let valueB = b[column] || '';
                
                // Handle different data types
                if (column === 'prescription_id') {
                    valueA = parseInt(valueA) || 0;
                    valueB = parseInt(valueB) || 0;
                } else if (column === 'prescription_date') {
                    valueA = new Date(valueA);
                    valueB = new Date(valueB);
                } else {
                    valueA = valueA.toString().toLowerCase();
                    valueB = valueB.toString().toLowerCase();
                }
                
                if (direction === 'ASC') {
                    return valueA < valueB ? -1 : valueA > valueB ? 1 : 0;
                } else {
                    return valueA > valueB ? -1 : valueA < valueB ? 1 : 0;
                }
            });
        }
        
        // Update sort icons for prescriptions
        function updatePrescriptionsSortIcons(activeColumn, direction) {
            // Reset all sort icons
            const sortIcons = document.querySelectorAll('#prescriptionsTable .sort-icon');
            sortIcons.forEach(icon => {
                icon.className = 'fas fa-sort sort-icon';
            });
            
            // Update active column icon
            const activeIcon = document.getElementById(`sort-${activeColumn}`);
            if (activeIcon) {
                if (direction === 'ASC') {
                    activeIcon.className = 'fas fa-sort-up sort-icon active';
                } else {
                    activeIcon.className = 'fas fa-sort-down sort-icon active';
                }
            }
        }
        
        // Update prescriptions pagination controls
        function updatePrescriptionsPagination() {
            const paginationControls = document.getElementById('prescriptionsPaginationControls');
            const paginationInfo = document.getElementById('prescriptionsPaginationInfo');
            
            if (totalPrescriptionsPages <= 1) {
                paginationControls.style.display = 'none';
                return;
            }
            
            paginationControls.style.display = 'block';
            
            // Update pagination info
            const start = (currentPrescriptionsPage - 1) * prescriptionsPerPage + 1;
            const end = Math.min(currentPrescriptionsPage * prescriptionsPerPage, totalPrescriptions);
            paginationInfo.textContent = `Showing ${start}-${end} of ${totalPrescriptions} prescriptions`;
            
            // Update buttons
            document.getElementById('prescriptionsPrevPage').disabled = currentPrescriptionsPage <= 1;
            document.getElementById('prescriptionsNextPage').disabled = currentPrescriptionsPage >= totalPrescriptionsPages;
            
            // Update page numbers
            const pageNumbers = document.getElementById('prescriptionsPageNumbers');
            pageNumbers.innerHTML = '';
            
            // Show max 5 page numbers
            let startPage = Math.max(1, currentPrescriptionsPage - 2);
            let endPage = Math.min(totalPrescriptionsPages, startPage + 4);
            
            // Adjust if we're near the end
            if (endPage - startPage < 4) {
                startPage = Math.max(1, endPage - 4);
            }
            
            for (let i = startPage; i <= endPage; i++) {
                const pageBtn = document.createElement('span');
                pageBtn.className = `page-number ${i === currentPrescriptionsPage ? 'active' : ''}`;
                pageBtn.textContent = i;
                pageBtn.onclick = () => changePrescriptionsPage(i, true);
                pageNumbers.appendChild(pageBtn);
            }
        }
        
        // Change prescriptions page
        function changePrescriptionsPage(direction, isAbsolute = false) {
            let newPage;
            if (isAbsolute) {
                newPage = direction;
            } else {
                newPage = currentPrescriptionsPage + direction;
            }
            
            newPage = Math.max(1, Math.min(totalPrescriptionsPages, newPage));
            
            if (newPage !== currentPrescriptionsPage) {
                currentPrescriptionsPage = newPage;
                displayPrescriptionsTable();
            }
        }
        
        // ==================== BILLING FUNCTIONS ====================
        
        // Load billing history for selected patient
        function loadBillingHistory(patientId) {
            console.log('loadBillingHistory called with patientId:', patientId, 'type:', typeof patientId);
            
            // Ensure patientId is a valid number
            const numericPatientId = parseInt(patientId);
            if (isNaN(numericPatientId) || numericPatientId <= 0) {
                console.error('Invalid patient ID:', patientId);
                document.getElementById('billingLoading').style.display = 'none';
                document.getElementById('billingEmpty').style.display = 'block';
                document.getElementById('billingTable').style.display = 'none';
                return;
            }
            
            const loadingDiv = document.getElementById('billingLoading');
            const emptyDiv = document.getElementById('billingEmpty');
            const tableDiv = document.getElementById('billingTable');
            
            // Show loading state
            loadingDiv.style.display = 'block';
            emptyDiv.style.display = 'none';
            tableDiv.style.display = 'none';
            
            const apiUrl = APP_CONFIG.apiPath(`get_patient_billing.php?patient_id=${numericPatientId}`);
            console.log('Making API request to:', apiUrl);
            
            // Fetch billing data
            fetch(apiUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                }
            })
            .then(response => {
                console.log('Billing API response status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Billing API response data:', data);
                
                loadingDiv.style.display = 'none';
                
                if (data.success && data.data && data.data.length > 0) {
                    // Store billing data
                    currentBilling = data.data;
                    totalBilling = data.data.length;
                    totalBillingPages = Math.ceil(totalBilling / billingPerPage);
                    currentBillingPage = 1;
                    
                    // Sort by default column
                    sortBillingByColumn(billingSortColumn, billingSortDirection);
                    
                    // Display billing table
                    displayBillingTable();
                    
                    // Show table
                    tableDiv.style.display = 'block';
                    emptyDiv.style.display = 'none';
                } else {
                    console.log('No billing records found or API call failed');
                    emptyDiv.style.display = 'block';
                    tableDiv.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error loading billing history:', error);
                loadingDiv.style.display = 'none';
                emptyDiv.style.display = 'block';
                tableDiv.style.display = 'none';
                
                // Show error message in empty state
                const emptyMsg = emptyDiv.querySelector('p');
                if (emptyMsg) {
                    emptyMsg.textContent = 'Error loading billing history. Please try again.';
                }
            });
        }
        
        // Display billing in table with pagination
        function displayBillingTable() {
            const tbody = document.getElementById('billingTableBody');
            tbody.innerHTML = '';
            
            // Calculate pagination
            const startIndex = (currentBillingPage - 1) * billingPerPage;
            const endIndex = Math.min(startIndex + billingPerPage, totalBilling);
            const pageBilling = currentBilling.slice(startIndex, endIndex);
            
            pageBilling.forEach(billing => {
                const row = document.createElement('tr');
                
                // Determine status badge class
                let statusClass = 'badge-secondary';
                switch (billing.payment_status) {
                    case 'paid':
                        statusClass = 'badge-success';
                        break;
                    case 'partial':
                        statusClass = 'badge-warning';
                        break;
                    case 'unpaid':
                        statusClass = 'badge-danger';
                        break;
                    case 'exempted':
                        statusClass = 'badge-info';
                        break;
                    case 'cancelled':
                        statusClass = 'badge-secondary';
                        break;
                }
                
                row.innerHTML = `
                    <td>${billing.billing_id}</td>
                    <td>${billing.formatted_billing_date}</td>
                    <td>${billing.formatted_total_amount}</td>
                    <td>${billing.formatted_net_amount}</td>
                    <td>${billing.formatted_paid_amount}</td>
                    <td><span class="badge ${statusClass}">${billing.formatted_payment_status}</span></td>
                    <td>
                        <div class="actions-group">
                            <button type="button" class="btn btn-sm btn-primary" 
                                    onclick="viewBilling(${billing.billing_id})" 
                                    title="View Details">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </td>
                `;
                
                tbody.appendChild(row);
            });
            
            // Update pagination controls
            updateBillingPagination();
        }
        
        // Billing sorting function
        function sortBillingTable(column) {
            console.log('Sorting billing by:', column);
            
            // Toggle sort direction if same column, otherwise use default direction
            if (billingSortColumn === column) {
                billingSortDirection = billingSortDirection === 'ASC' ? 'DESC' : 'ASC';
            } else {
                billingSortColumn = column;
                billingSortDirection = column === 'billing_date' ? 'DESC' : 'ASC';
            }
            
            // Update sort icons
            updateBillingSortIcons(column, billingSortDirection);
            
            // Sort and redisplay
            sortBillingByColumn(column, billingSortDirection);
            displayBillingTable();
        }
        
        // Function to sort billing array
        function sortBillingByColumn(column, direction) {
            currentBilling.sort((a, b) => {
                let valueA = a[column];
                let valueB = b[column];
                
                // Handle different data types
                if (column.includes('amount') || column === 'billing_id') {
                    valueA = parseFloat(valueA) || 0;
                    valueB = parseFloat(valueB) || 0;
                } else if (column.includes('date')) {
                    valueA = new Date(valueA);
                    valueB = new Date(valueB);
                } else {
                    valueA = (valueA || '').toString().toLowerCase();
                    valueB = (valueB || '').toString().toLowerCase();
                }
                
                if (direction === 'ASC') {
                    return valueA < valueB ? -1 : valueA > valueB ? 1 : 0;
                } else {
                    return valueA > valueB ? -1 : valueA < valueB ? 1 : 0;
                }
            });
        }
        
        // Update sort icons for billing
        function updateBillingSortIcons(activeColumn, direction) {
            // Reset all billing sort icons
            const sortIcons = document.querySelectorAll('#billing .sort-icon');
            sortIcons.forEach(icon => {
                icon.className = 'fas fa-sort sort-icon';
            });
            
            // Update active column icon
            const activeIcon = document.getElementById(`sort-${activeColumn}`);
            if (activeIcon) {
                if (direction === 'ASC') {
                    activeIcon.className = 'fas fa-sort-up sort-icon active';
                } else {
                    activeIcon.className = 'fas fa-sort-down sort-icon active';
                }
            }
        }
        
        // Update billing pagination controls
        function updateBillingPagination() {
            const paginationControls = document.getElementById('billingPaginationControls');
            const paginationInfo = document.getElementById('billingPaginationInfo');
            
            if (totalBillingPages <= 1) {
                paginationControls.style.display = 'none';
                return;
            }
            
            paginationControls.style.display = 'block';
            
            // Update pagination info
            const start = (currentBillingPage - 1) * billingPerPage + 1;
            const end = Math.min(currentBillingPage * billingPerPage, totalBilling);
            paginationInfo.textContent = `Showing ${start}-${end} of ${totalBilling} billing records`;
            
            // Update buttons
            document.getElementById('billingPrevPage').disabled = currentBillingPage <= 1;
            document.getElementById('billingNextPage').disabled = currentBillingPage >= totalBillingPages;
            
            // Update page numbers
            const pageNumbers = document.getElementById('billingPageNumbers');
            pageNumbers.innerHTML = '';
            
            // Show max 5 page numbers
            let startPage = Math.max(1, currentBillingPage - 2);
            let endPage = Math.min(totalBillingPages, startPage + 4);
            
            if (endPage - startPage < 4) {
                startPage = Math.max(1, endPage - 4);
            }
            
            for (let i = startPage; i <= endPage; i++) {
                const pageBtn = document.createElement('span');
                pageBtn.className = `page-number ${i === currentBillingPage ? 'active' : ''}`;
                pageBtn.textContent = i;
                pageBtn.onclick = () => changeBillingPage(i, true);
                pageNumbers.appendChild(pageBtn);
            }
        }
        
        // Change billing page
        function changeBillingPage(direction, isAbsolute = false) {
            let newPage;
            if (isAbsolute) {
                newPage = direction;
            } else {
                newPage = currentBillingPage + direction;
            }
            
            newPage = Math.max(1, Math.min(totalBillingPages, newPage));
            
            if (newPage !== currentBillingPage) {
                currentBillingPage = newPage;
                displayBillingTable();
            }
        }
        
        // View billing details function (placeholder for future implementation)
        // ==================== BILLING MODAL FUNCTIONS ====================
        
        // View billing details in modal
        function viewBilling(billingId) {
            console.log('viewBilling called with ID:', billingId);
            
            // Find the billing record in the current billing data
            const billing = currentBilling.find(b => b.billing_id == billingId);
            
            if (!billing) {
                console.error('Billing record not found with ID:', billingId);
                alert('Billing record not found. Please try again.');
                return;
            }
            
            // Store billing data globally for print function
            currentBillingData = billing;
            
            // Display billing details
            displayBillingDetails(billing);
            
            // Show modal
            openModal('viewBillingModal');
        }
        
        // Display billing details in modal
        function displayBillingDetails(billing) {
            const modalContent = document.getElementById('billingDetailsContent');
            
            // Format billing date
            const billingDate = new Date(billing.billing_date);
            const formattedDate = billingDate.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            const formattedTime = billingDate.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit'
            });
            
            // Format visit date
            const visitDate = billing.visit_date ? new Date(billing.visit_date).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            }) : 'N/A';
            
            // Parse service items
            let serviceItemsHtml = '';
            if (billing.service_items && billing.service_items !== 'No items recorded') {
                const items = billing.service_items.split('; ');
                items.forEach(item => {
                    if (item.trim()) {
                        serviceItemsHtml += `
                            <div class="service-item">
                                <span class="service-name">${item}</span>
                            </div>
                        `;
                    }
                });
            } else {
                serviceItemsHtml = '<div class="service-item"><span class="service-name">No service items recorded</span></div>';
            }
            
            // Parse payment details
            let paymentDetailsHtml = '';
            if (billing.payment_details && billing.payment_details !== 'No payments recorded') {
                const payments = billing.payment_details.split('; ');
                payments.forEach(payment => {
                    if (payment.trim()) {
                        paymentDetailsHtml += `
                            <div class="payment-item">
                                <span class="payment-info">${payment}</span>
                            </div>
                        `;
                    }
                });
            } else {
                paymentDetailsHtml = '<div class="payment-item"><span class="payment-info">No payments recorded</span></div>';
            }
            
            // Receipt numbers
            const receiptNumbers = billing.receipt_numbers || 'No receipts generated';
            
            modalContent.innerHTML = `
                <div class="appointment-details-grid">
                    <div class="details-section">
                        <h4><i class="fas fa-user"></i> Patient Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Patient Name:</span>
                            <span class="detail-value">${billing.patient_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Patient ID:</span>
                            <span class="detail-value">${billing.patient_id_display || 'N/A'}</span>
                        </div>
                    </div>
                    
                    <div class="details-section">
                        <h4><i class="fas fa-calendar-alt"></i> Billing Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Billing ID:</span>
                            <span class="detail-value">BILL-${String(billing.billing_id).padStart(8, '0')}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Billing Date:</span>
                            <span class="detail-value">${formattedDate} at ${formattedTime}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Visit Date:</span>
                            <span class="detail-value">${visitDate}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Facility:</span>
                            <span class="detail-value">${billing.facility_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Created By:</span>
                            <span class="detail-value">${billing.created_by_name || 'N/A'} (${billing.created_by_role || 'N/A'})</span>
                        </div>
                    </div>
                    
                    <div class="details-section">
                        <h4><i class="fas fa-list"></i> Service Items</h4>
                        <div class="service-items-container">
                            ${serviceItemsHtml}
                        </div>
                    </div>
                    
                    <div class="details-section">
                        <h4><i class="fas fa-calculator"></i> Amount Details</h4>
                        <div class="detail-item">
                            <span class="detail-label">Total Amount:</span>
                            <span class="detail-value highlight">${billing.formatted_total_amount}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Discount (${billing.formatted_discount_type}):</span>
                            <span class="detail-value">${billing.formatted_discount_amount}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Net Amount:</span>
                            <span class="detail-value highlight">${billing.formatted_net_amount}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Paid Amount:</span>
                            <span class="detail-value">${billing.formatted_paid_amount}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Balance:</span>
                            <span class="detail-value ${billing.balance_amount > 0 ? 'text-danger' : 'text-success'}">${billing.formatted_balance}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Payment Status:</span>
                            <span class="detail-value">
                                <span class="badge ${getStatusBadgeClass(billing.payment_status)}">${billing.formatted_payment_status}</span>
                            </span>
                        </div>
                    </div>
                    
                    <div class="details-section">
                        <h4><i class="fas fa-credit-card"></i> Payment History</h4>
                        <div class="payment-history-container">
                            ${paymentDetailsHtml}
                        </div>
                    </div>
                    
                    <div class="details-section">
                        <h4><i class="fas fa-receipt"></i> Receipt Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Receipt Numbers:</span>
                            <span class="detail-value">${receiptNumbers}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Cashier:</span>
                            <span class="detail-value">${billing.cashier_names || 'N/A'}</span>
                        </div>
                    </div>
                    
                    ${billing.billing_notes ? `
                        <div class="details-section">
                            <h4><i class="fas fa-sticky-note"></i> Notes</h4>
                            <div class="detail-item">
                                <span class="detail-value">${billing.billing_notes}</span>
                            </div>
                        </div>
                    ` : ''}
                </div>
            `;
        }
        
        // Helper function to get status badge class
        function getStatusBadgeClass(status) {
            switch (status) {
                case 'paid':
                    return 'badge-success';
                case 'partial':
                    return 'badge-warning';
                case 'unpaid':
                    return 'badge-danger';
                case 'exempted':
                    return 'badge-info';
                case 'cancelled':
                    return 'badge-secondary';
                default:
                    return 'badge-secondary';
            }
        }
        
        // Print billing details (invoice)
        function printBillingDetails() {
            if (!currentBillingData) {
                alert('No billing data available to print.');
                return;
            }
            
            const billing = currentBillingData;
            const modalContent = document.getElementById('billingDetailsContent').innerHTML;
            
            // Format billing date
            const billingDate = new Date(billing.billing_date);
            const formattedDate = billingDate.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            
            // Get current date and time for print
            const currentDate = new Date().toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            const currentTime = new Date().toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit'
            });
            
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Invoice - BILL-${String(billing.billing_id).padStart(8, '0')}</title>
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            line-height: 1.6;
                            color: #333;
                            max-width: 800px;
                            margin: 0 auto;
                            padding: 20px;
                        }
                        .print-header {
                            text-align: center;
                            border-bottom: 3px solid #0077b6;
                            padding-bottom: 20px;
                            margin-bottom: 30px;
                        }
                        .print-header h1 {
                            color: #0077b6;
                            margin: 0;
                            font-size: 28px;
                        }
                        .print-header h2 {
                            color: #666;
                            margin: 5px 0 0 0;
                            font-size: 18px;
                            font-weight: normal;
                        }
                        .print-info {
                            display: flex;
                            justify-content: space-between;
                            margin-bottom: 30px;
                            font-size: 14px;
                        }
                        .appointment-details-grid {
                            display: grid;
                            gap: 25px;
                        }
                        .details-section {
                            border: 1px solid #ddd;
                            border-radius: 8px;
                            padding: 20px;
                            background: #f9f9f9;
                        }
                        .details-section h4 {
                            color: #0077b6;
                            margin: 0 0 15px 0;
                            font-size: 16px;
                            border-bottom: 2px solid #0077b6;
                            padding-bottom: 5px;
                        }
                        .detail-item {
                            display: flex;
                            justify-content: space-between;
                            margin-bottom: 8px;
                            padding: 5px 0;
                            border-bottom: 1px solid #eee;
                        }
                        .detail-label {
                            font-weight: 600;
                            color: #555;
                            flex: 1;
                        }
                        .detail-value {
                            flex: 2;
                            text-align: right;
                            color: #333;
                        }
                        .detail-value.highlight {
                            font-weight: bold;
                            color: #0077b6;
                            font-size: 1.1em;
                        }
                        .badge {
                            padding: 4px 8px;
                            border-radius: 4px;
                            font-size: 12px;
                            font-weight: bold;
                        }
                        .badge-success { background: #d4edda; color: #155724; }
                        .badge-warning { background: #fff3cd; color: #856404; }
                        .badge-danger { background: #f8d7da; color: #721c24; }
                        .badge-info { background: #d1ecf1; color: #0c5460; }
                        .badge-secondary { background: #e2e3e5; color: #383d41; }
                        .service-item, .payment-item {
                            background: white;
                            padding: 10px;
                            margin: 5px 0;
                            border-radius: 4px;
                            border-left: 4px solid #0077b6;
                        }
                        .text-danger { color: #dc3545; }
                        .text-success { color: #28a745; }
                        .signatory-section {
                            margin-top: 40px;
                            text-align: center;
                        }
                        .signatory-info {
                            display: inline-block;
                            text-align: center;
                        }
                        .signature-line {
                            margin-bottom: 10px;
                            font-weight: bold;
                            color: #666;
                        }
                        .signatory-details {
                            margin-top: 60px;
                            border-top-style: ridge;
                            width: 250px;
                            text-align: center;
                            margin-left: auto;
                            margin-right: auto;
                        }
                        .signatory-details strong {
                            display: block;
                            margin-bottom: 5px;
                            color: #0077b6;
                        }
                        .signatory-role, .signatory-facility {
                            display: block;
                            font-size: 0.9em;
                            color: #666;
                            margin-bottom: 3px;
                        }
                        .print-footer {
                            margin-top: 30px;
                            text-align: center;
                            font-size: 12px;
                            color: #888;
                            border-top: 1px solid #ddd;
                            padding-top: 10px;
                        }
                        @media print {
                            body { margin: 0; padding: 15px; }
                            .print-info { font-size: 12px; }
                        }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h1>BILLING INVOICE</h1>
                        <h2>Medical Billing Statement</h2>
                    </div>
                    
                    <div class="print-info">
                        <div><strong>Invoice #:</strong> BILL-${String(billing.billing_id).padStart(8, '0')}</div>
                        <div><strong>Date:</strong> ${formattedDate}</div>
                        <div><strong>Patient:</strong> ${billing.patient_name}</div>
                    </div>
                    
                    <div class="print-content">
                        ${modalContent}
                    </div>
                    
                    <div class="signatory-section">
                        <div class="signatory-info">
                            <div class="signature-line">
                                <span class="signature-text">Prepared by:</span>
                            </div>
                            <div class="signatory-details" style="
    margin-top: 60px;
    border-top-style: ridge;
    width: 250px;
">
                                <strong>${billing.created_by_name || 'N/A'}</strong><br>
                                <span class="signatory-role">${billing.created_by_role || 'N/A'}</span><br>
                                <span class="signatory-facility">${billing.facility_name || 'N/A'}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="print-footer">
                        <p>This is a computer-generated invoice.</p>
                        <p>Generated on: ${currentDate} at ${currentTime}</p>
                    </div>
                </body>
                </html>
            `);

            printWindow.document.close();

            // Wait for content to load, then print
            printWindow.onload = function() {
                printWindow.focus();
                printWindow.print();
                printWindow.close();
            };
        }
        
        // ==================== LABORATORY FUNCTIONS ====================
        
        // Load laboratory history for selected patient
        function loadLaboratoryHistory(patientId) {
            console.log('loadLaboratoryHistory called with patientId:', patientId, 'type:', typeof patientId);
            
            // Ensure patientId is a valid number
            const numericPatientId = parseInt(patientId);
            if (isNaN(numericPatientId) || numericPatientId <= 0) {
                console.error('Invalid patient ID:', patientId);
                const emptyDiv = document.getElementById('laboratoryEmpty');
                emptyDiv.style.display = 'block';
                emptyDiv.querySelector('h3').textContent = 'Invalid Patient ID';
                emptyDiv.querySelector('p').textContent = 'The patient ID is not valid.';
                return;
            }
            
            const loadingDiv = document.getElementById('laboratoryLoading');
            const emptyDiv = document.getElementById('laboratoryEmpty');
            const tableDiv = document.getElementById('laboratoryTable');
            
            // Show loading state
            loadingDiv.style.display = 'block';
            emptyDiv.style.display = 'none';
            tableDiv.style.display = 'none';
            
            const apiUrl = APP_CONFIG.apiPath(`get_patient_laboratory.php?patient_id=${numericPatientId}`);
            console.log('Making API request to:', apiUrl);
            
            // Fetch laboratory data
            fetch(apiUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                console.log('API response status:', response.status, response.statusText);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.text().then(text => {
                    console.log('Raw response text:', text);
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON parse error:', e);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                console.log('API response received:', data);
                loadingDiv.style.display = 'none';
                
                if (data.success && data.laboratory_orders && data.laboratory_orders.length > 0) {
                    // Store laboratory data
                    currentLaboratory = data.laboratory_orders;
                    totalLaboratory = data.laboratory_orders.length;
                    totalLaboratoryPages = Math.ceil(totalLaboratory / laboratoryPerPage);
                    currentLaboratoryPage = 1;
                    
                    // Sort by default (order_date DESC)
                    sortLaboratoryByColumn(laboratorySortColumn, laboratorySortDirection);
                    
                    // Display table
                    displayLaboratoryTable();
                    tableDiv.style.display = 'block';
                } else {
                    emptyDiv.style.display = 'block';
                    emptyDiv.querySelector('h3').textContent = 'No Laboratory Tests Found';
                    emptyDiv.querySelector('p').textContent = 'No laboratory test history available for this patient.';
                }
            })
            .catch(error => {
                console.error('Error loading laboratory data:', error);
                loadingDiv.style.display = 'none';
                emptyDiv.style.display = 'block';
                
                // Show user-friendly error messages
                if (error.message.includes('401') || error.message.includes('Authentication')) {
                    emptyDiv.querySelector('h3').textContent = 'Session Expired';
                    emptyDiv.querySelector('p').innerHTML = 'Your session has expired. Please <a href="javascript:window.location.reload();" style="color: #0077b6; text-decoration: underline;">refresh the page</a> and log in again.';
                } else {
                    emptyDiv.querySelector('h3').textContent = 'Error Loading Laboratory Data';
                    emptyDiv.querySelector('p').textContent = 'Unable to load laboratory test history. Please try again later.';
                }
            });
        }
        
        // Display laboratory data in table with pagination
        function displayLaboratoryTable() {
            const tbody = document.getElementById('laboratoryTableBody');
            tbody.innerHTML = '';
            
            // Calculate pagination
            const startIndex = (currentLaboratoryPage - 1) * laboratoryPerPage;
            const endIndex = Math.min(startIndex + laboratoryPerPage, totalLaboratory);
            const pageLaboratory = currentLaboratory.slice(startIndex, endIndex);
            
            pageLaboratory.forEach(labOrder => {
                const row = document.createElement('tr');
                
                // Format lab order ID
                const orderID = `LAB-${String(labOrder.lab_order_id).padStart(8, '0')}`;
                
                // Progress bar HTML
                const progressHtml = `
                    <div class="progress-container" style="width: 100%; background: #f0f0f0; border-radius: 10px; overflow: hidden;">
                        <div class="progress-bar" style="width: ${labOrder.progress_percentage}%; background: ${labOrder.progress_class === 'badge-success' ? '#28a745' : labOrder.progress_class === 'badge-warning' ? '#ffc107' : '#6c757d'}; height: 20px; transition: width 0.3s ease;"></div>
                    </div>
                    <small class="progress-text" style="display: block; margin-top: 2px; font-size: 0.8em; color: #666;">
                        ${labOrder.completed_items}/${labOrder.total_items} completed (${labOrder.progress_percentage}%)
                    </small>
                `;
                
                row.innerHTML = `
                    <td>${orderID}</td>
                    <td>
                        <div>${labOrder.formatted_order_date}</div>
                        <small class="text-muted">${labOrder.formatted_order_time}</small>
                    </td>
                    <td>
                        <div>${labOrder.ordered_by_name}</div>
                        <small class="text-muted">${labOrder.ordered_by_role}</small>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-info">${labOrder.total_items} test${labOrder.total_items !== 1 ? 's' : ''}</span>
                    </td>
                    <td>
                        <span class="badge ${labOrder.status_class}">${labOrder.formatted_overall_status}</span>
                    </td>
                    <td>
                        ${progressHtml}
                    </td>
                    <td>
                        <button class="btn btn-sm btn-primary" onclick="viewLaboratory(${labOrder.lab_order_id})" title="View Details">
                            <i class="fas fa-eye"></i>
                        </button>
                        <button class="btn btn-sm btn-secondary ml-1" onclick="printLaboratory(${labOrder.lab_order_id})" title="Print Order">
                            <i class="fas fa-print"></i>
                        </button>
                    </td>
                `;
                
                tbody.appendChild(row);
            });
            
            // Update pagination controls
            updateLaboratoryPagination();
        }
        
        // Laboratory sorting function
        function sortLaboratoryTable(column) {
            console.log('Sorting laboratory by:', column);
            
            // Toggle sort direction if same column, otherwise use default direction
            if (laboratorySortColumn === column) {
                laboratorySortDirection = laboratorySortDirection === 'ASC' ? 'DESC' : 'ASC';
            } else {
                laboratorySortColumn = column;
                // Set default direction based on column type
                if (column === 'order_date' || column === 'lab_order_id') {
                    laboratorySortDirection = 'DESC'; // Latest first
                } else {
                    laboratorySortDirection = 'ASC'; // Alphabetical first
                }
            }
            
            // Update sort icons
            updateLaboratorySortIcons(column, laboratorySortDirection);
            
            // Sort and redisplay
            sortLaboratoryByColumn(column, laboratorySortDirection);
            currentLaboratoryPage = 1; // Reset to first page after sorting
            displayLaboratoryTable();
        }
        
        // Function to sort laboratory array
        function sortLaboratoryByColumn(column, direction) {
            currentLaboratory.sort((a, b) => {
                let valueA = a[column] || '';
                let valueB = b[column] || '';
                
                // Handle different data types
                if (column === 'lab_order_id') {
                    valueA = parseInt(valueA) || 0;
                    valueB = parseInt(valueB) || 0;
                } else if (column === 'order_date') {
                    valueA = new Date(valueA);
                    valueB = new Date(valueB);
                } else {
                    // String comparison (case-insensitive)
                    valueA = valueA.toString().toLowerCase();
                    valueB = valueB.toString().toLowerCase();
                }
                
                if (direction === 'ASC') {
                    return valueA < valueB ? -1 : valueA > valueB ? 1 : 0;
                } else {
                    return valueA > valueB ? -1 : valueA < valueB ? 1 : 0;
                }
            });
        }
        
        // Update sort icons for laboratory
        function updateLaboratorySortIcons(activeColumn, direction) {
            // Reset all sort icons
            const sortIcons = document.querySelectorAll('#laboratoryTable .sort-icon');
            sortIcons.forEach(icon => {
                icon.className = 'fas fa-sort sort-icon';
            });
            
            // Update active column icon
            const activeIcon = document.getElementById(`sort-${activeColumn}`);
            if (activeIcon) {
                if (direction === 'ASC') {
                    activeIcon.className = 'fas fa-sort-up sort-icon active';
                } else {
                    activeIcon.className = 'fas fa-sort-down sort-icon active';
                }
            }
        }
        
        // Update laboratory pagination controls
        function updateLaboratoryPagination() {
            const paginationControls = document.getElementById('laboratoryPaginationControls');
            const paginationInfo = document.getElementById('laboratoryPaginationInfo');
            
            if (totalLaboratoryPages <= 1) {
                paginationControls.style.display = 'none';
                return;
            }
            
            paginationControls.style.display = 'block';
            
            // Update pagination info
            const start = (currentLaboratoryPage - 1) * laboratoryPerPage + 1;
            const end = Math.min(currentLaboratoryPage * laboratoryPerPage, totalLaboratory);
            paginationInfo.textContent = `Showing ${start}-${end} of ${totalLaboratory} laboratory orders`;
            
            // Update buttons
            document.getElementById('laboratoryPrevPage').disabled = currentLaboratoryPage <= 1;
            document.getElementById('laboratoryNextPage').disabled = currentLaboratoryPage >= totalLaboratoryPages;
            
            // Update page numbers
            const pageNumbers = document.getElementById('laboratoryPageNumbers');
            pageNumbers.innerHTML = '';
            
            // Show max 5 page numbers
            let startPage = Math.max(1, currentLaboratoryPage - 2);
            let endPage = Math.min(totalLaboratoryPages, startPage + 4);
            
            // Adjust if we're near the end
            if (endPage - startPage < 4) {
                startPage = Math.max(1, endPage - 4);
            }
            
            for (let i = startPage; i <= endPage; i++) {
                const pageBtn = document.createElement('span');
                pageBtn.className = `page-number ${i === currentLaboratoryPage ? 'active' : ''}`;
                pageBtn.textContent = i;
                pageBtn.onclick = () => changeLaboratoryPage(i, true);
                pageNumbers.appendChild(pageBtn);
            }
        }
        
        // Change laboratory page
        function changeLaboratoryPage(direction, isAbsolute = false) {
            let newPage;
            if (isAbsolute) {
                newPage = direction;
            } else {
                newPage = currentLaboratoryPage + direction;
            }
            
            newPage = Math.max(1, Math.min(totalLaboratoryPages, newPage));
            
            if (newPage !== currentLaboratoryPage) {
                currentLaboratoryPage = newPage;
                displayLaboratoryTable();
            }
        }
        
        // ==================== LABORATORY MODAL FUNCTIONS ====================
        
        // View laboratory details in modal
        function viewLaboratory(labOrderId) {
            console.log('viewLaboratory called with ID:', labOrderId);
            
            // Show loading
            document.getElementById('laboratoryDetailsContent').innerHTML = `
                <div style="text-align: center; padding: 20px;">
                    <div class="loader"></div>
                    <p style="margin-top: 10px; color: #6c757d;">Loading laboratory details...</p>
                </div>
            `;
            document.getElementById('viewLaboratoryModal').style.display = 'block';

            // Get laboratory data
            fetch(`/wbhsms-cho-koronadal-1/api/get_laboratory_details.php?lab_order_id=${labOrderId}`, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Cache-Control': 'no-cache'
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.text().then(text => {
                        console.log('Raw Laboratory API Response:', text);
                        console.log('Response length:', text.length);
                        
                        try {
                            return JSON.parse(text);
                        } catch (parseError) {
                            console.error('JSON Parse Error:', parseError);
                            console.error('Response text:', text.substring(0, 500));
                            throw new Error('Invalid JSON response from server');
                        }
                    });
                })
                .then(data => {
                    console.log('Laboratory API Response:', data);
                    
                    if (data.success && data.laboratory_order) {
                        currentLaboratoryData = data.laboratory_order;
                        displayLaboratoryDetails(data.laboratory_order);
                    } else {
                        throw new Error(data.error || 'Failed to load laboratory data');
                    }
                })
                .catch(error => {
                    console.error('Error loading laboratory data:', error);
                    document.getElementById('laboratoryDetailsContent').innerHTML = `
                        <div style="text-align: center; padding: 20px; color: #dc3545;">
                            <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i>
                            <h3>Error Loading Laboratory Data</h3>
                            <p>Unable to load laboratory details: ${error.message}</p>
                            <button onclick="closeModal('viewLaboratoryModal')" class="btn btn-secondary" style="margin-top: 20px;">
                                Close
                            </button>
                        </div>
                    `;
                });
        }
        
        // Display laboratory details in modal
        function displayLaboratoryDetails(labOrder) {
            const contentDiv = document.getElementById('laboratoryDetailsContent');
            
            // Build lab items HTML
            let labItemsHtml = '';
            if (labOrder.lab_items && labOrder.lab_items.length > 0) {
                labOrder.lab_items.forEach((item, index) => {
                    // Result file button (if available)
                    const resultFileButton = item.has_result_file 
                        ? `<button class="btn btn-sm btn-info ml-2" onclick="viewResultFile(${item.item_id})" title="View Result File">
                             <i class="fas fa-file-pdf"></i> View Result
                           </button>`
                        : '<span class="text-muted"><i class="fas fa-file"></i> No result file</span>';
                    
                    labItemsHtml += `
                        <div class="card mb-3" style="border-left: 4px solid ${item.status === 'completed' ? '#28a745' : item.status === 'in_progress' ? '#ffc107' : '#6c757d'};">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <h5 class="card-title">${item.test_type}</h5>
                                        <p class="card-text">${item.test_description || 'No description provided'}</p>
                                        <div class="row">
                                            <div class="col-sm-6">
                                                <strong>Specimen:</strong> ${item.specimen_type || 'Not specified'}<br>
                                                <strong>Status:</strong> <span class="badge ${item.status_class}">${item.status.toUpperCase()}</span><br>
                                                ${item.normal_range ? `<strong>Normal Range:</strong> ${item.normal_range}<br>` : ''}
                                                ${item.result_value ? `<strong>Result:</strong> ${item.result_value}<br>` : ''}
                                            </div>
                                            <div class="col-sm-6">
                                                ${item.formatted_requested_date ? `<strong>Requested:</strong> ${item.formatted_requested_date}<br>` : ''}
                                                ${item.formatted_collected_date ? `<strong>Collected:</strong> ${item.formatted_collected_date}<br>` : ''}
                                                ${item.formatted_completed_at ? `<strong>Completed:</strong> ${item.formatted_completed_at}<br>` : ''}
                                                ${item.technician_name ? `<strong>Technician:</strong> ${item.technician_name}<br>` : ''}
                                            </div>
                                        </div>
                                        ${item.result_interpretation ? `<div class="mt-2"><strong>Interpretation:</strong><br><em>${item.result_interpretation}</em></div>` : ''}
                                        ${item.technician_notes ? `<div class="mt-2"><strong>Technician Notes:</strong><br><em>${item.technician_notes}</em></div>` : ''}
                                        ${item.remarks ? `<div class="mt-2"><strong>Remarks:</strong><br><em>${item.remarks}</em></div>` : ''}
                                    </div>
                                    <div class="col-md-4 text-right">
                                        ${resultFileButton}
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                });
            } else {
                labItemsHtml = '<p class="text-muted">No laboratory tests found for this order.</p>';
            }
            
            contentDiv.innerHTML = `
                <div class="laboratory-details">
                    <!-- Order Header -->
                    <div class="card mb-4" style="border-left: 4px solid #0077b6;">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <h4><i class="fas fa-flask"></i> ${labOrder.order_id_display}</h4>
                                    <h5 class="text-primary">${labOrder.patient_name}</h5>
                                    <p class="mb-1"><strong>Patient ID:</strong> ${labOrder.patient_id_display}</p>
                                    <p class="mb-1"><strong>Age:</strong> ${labOrder.patient_age} years old (${labOrder.patient_gender})</p>
                                    <p class="mb-1"><strong>Contact:</strong> ${labOrder.patient_contact || 'Not provided'}</p>
                                    <p class="mb-1"><strong>Barangay:</strong> ${labOrder.patient_barangay || 'Not provided'}</p>
                                </div>
                                <div class="col-md-4 text-right">
                                    <div class="summary-value">
                                        <strong>Order Date</strong><br>
                                        ${labOrder.formatted_order_datetime}
                                    </div>
                                    <div class="summary-value mt-3">
                                        <strong>Overall Status</strong><br>
                                        <span class="badge badge-info">${labOrder.overall_status.toUpperCase()}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Progress Summary -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-3">
                                    <div class="summary-value">
                                        <strong>${labOrder.total_items}</strong><br>
                                        <small class="text-muted">Total Tests</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-value">
                                        <strong style="color: #28a745;">${labOrder.completed_items}</strong><br>
                                        <small class="text-muted">Completed</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-value">
                                        <strong style="color: #ffc107;">${labOrder.in_progress_items}</strong><br>
                                        <small class="text-muted">In Progress</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="summary-value">
                                        <strong style="color: #6c757d;">${labOrder.pending_items}</strong><br>
                                        <small class="text-muted">Pending</small>
                                    </div>
                                </div>
                            </div>
                            <div class="progress mt-3" style="height: 25px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: ${labOrder.progress_percentage}%">
                                    ${labOrder.progress_percentage}% Complete
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Ordering Information -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <h5><i class="fas fa-user-md"></i> Ordering Information</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>Ordered By:</strong> ${labOrder.ordered_by_name || 'Not specified'}</p>
                                    <p><strong>Role:</strong> ${labOrder.ordered_by_role || 'Not specified'}</p>
                                    ${labOrder.physician_license ? `<p><strong>License Number:</strong> ${labOrder.physician_license}</p>` : ''}
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Facility:</strong> ${labOrder.facility_name || 'Not specified'}</p>
                                    <p><strong>Type:</strong> ${labOrder.facility_type || 'Not specified'}</p>
                                    <p><strong>District:</strong> ${labOrder.facility_district || 'Not specified'}</p>
                                </div>
                            </div>
                            ${labOrder.order_remarks ? `<div class="mt-3"><strong>Order Remarks:</strong><br><em>${labOrder.order_remarks}</em></div>` : ''}
                        </div>
                    </div>
                    
                    <!-- Laboratory Tests -->
                    <div class="card">
                        <div class="card-body">
                            <h5><i class="fas fa-vials"></i> Laboratory Tests (${labOrder.total_items})</h5>
                            ${labItemsHtml}
                        </div>
                    </div>
                </div>
            `;
        }
        
        // Print laboratory details
        function printLaboratory(labOrderId) {
            console.log('printLaboratory called with ID:', labOrderId);
            
            // If we have current laboratory data for this order, use it
            if (currentLaboratoryData && currentLaboratoryData.lab_order_id == labOrderId) {
                printLaboratoryDetails();
                return;
            }
            
            // Otherwise, fetch the data first
            fetch(`/wbhsms-cho-koronadal-1/api/get_laboratory_details.php?lab_order_id=${labOrderId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.laboratory_order) {
                        currentLaboratoryData = data.laboratory_order;
                        printLaboratoryDetails();
                    } else {
                        alert('Error loading laboratory data for printing: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error fetching laboratory data for print:', error);
                    alert('Error loading laboratory data for printing: ' + error.message);
                });
        }
        
        // Print current laboratory details
        function printLaboratoryDetails() {
            if (!currentLaboratoryData) {
                alert('No laboratory data available for printing');
                return;
            }
            
            const labOrder = currentLaboratoryData;
            
            // Build lab items for print
            let labItemsHtml = '';
            if (labOrder.lab_items && labOrder.lab_items.length > 0) {
                labOrder.lab_items.forEach((item, index) => {
                    labItemsHtml += `
                        <tr style="border-bottom: 1px solid #ddd;">
                            <td style="padding: 8px; vertical-align: top; width: 25%;">
                                <strong>${item.test_type}</strong><br>
                                <small style="color: #666;">${item.test_description || ''}</small>
                            </td>
                            <td style="padding: 8px; vertical-align: top; width: 15%;">
                                ${item.specimen_type || 'N/A'}
                            </td>
                            <td style="padding: 8px; vertical-align: top; width: 15%;">
                                <span style="background: ${item.status === 'completed' ? '#28a745' : item.status === 'in_progress' ? '#ffc107' : '#6c757d'}; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px;">
                                    ${item.status.toUpperCase()}
                                </span>
                            </td>
                            <td style="padding: 8px; vertical-align: top; width: 20%;">
                                ${item.normal_range || 'N/A'}
                            </td>
                            <td style="padding: 8px; vertical-align: top; width: 25%;">
                                ${item.result_value || 'Pending'}
                                ${item.result_interpretation ? `<br><small style="color: #666; font-style: italic;">${item.result_interpretation}</small>` : ''}
                            </td>
                        </tr>
                    `;
                });
            } else {
                labItemsHtml = '<tr><td colspan="5" style="text-align: center; padding: 20px; color: #666;">No laboratory tests found</td></tr>';
            }
            
            // Create print content
            const printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Laboratory Report - ${labOrder.order_id_display}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; color: #333; }
                        .header { text-align: center; border-bottom: 2px solid #0077b6; padding-bottom: 20px; margin-bottom: 30px; }
                        .logo { font-size: 24px; font-weight: bold; color: #0077b6; margin-bottom: 5px; }
                        .facility-info { font-size: 14px; color: #666; }
                        .report-title { font-size: 20px; margin: 20px 0; text-align: center; color: #333; }
                        .patient-info { background: #f8f9fa; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
                        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; }
                        .info-item { margin-bottom: 8px; }
                        .info-label { font-weight: bold; color: #555; }
                        .progress-summary { background: #e3f2fd; padding: 15px; border-radius: 5px; margin-bottom: 20px; text-align: center; }
                        .progress-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 15px; }
                        .progress-item { text-align: center; }
                        .progress-number { font-size: 24px; font-weight: bold; }
                        .progress-label { font-size: 12px; color: #666; }
                        .tests-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        .tests-table th { background: #0077b6; color: white; padding: 10px; text-align: left; }
                        .tests-table td { padding: 8px; border-bottom: 1px solid #ddd; vertical-align: top; }
                        .order-info { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; }
                        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ddd; text-align: center; font-size: 12px; color: #666; }
                        @media print { 
                            body { margin: 0; } 
                            .no-print { display: none; }
                        }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <div class="logo">${labOrder.facility_name || 'Healthcare Facility'}</div>
                        <div class="facility-info">
                            ${labOrder.facility_type || 'Medical Facility'} - ${labOrder.facility_district || 'District'}<br>
                            Laboratory Report
                        </div>
                    </div>
                    
                    <div class="report-title">LABORATORY TEST REPORT</div>
                    
                    <div class="patient-info">
                        <div class="info-grid">
                            <div>
                                <div class="info-item"><span class="info-label">Patient Name:</span> ${labOrder.patient_name}</div>
                                <div class="info-item"><span class="info-label">Patient ID:</span> ${labOrder.patient_id_display}</div>
                                <div class="info-item"><span class="info-label">Age:</span> ${labOrder.patient_age} years old</div>
                                <div class="info-item"><span class="info-label">Gender:</span> ${labOrder.patient_gender}</div>
                                <div class="info-item"><span class="info-label">Contact:</span> ${labOrder.patient_contact || 'Not provided'}</div>
                            </div>
                            <div>
                                <div class="info-item"><span class="info-label">Lab Order ID:</span> ${labOrder.order_id_display}</div>
                                <div class="info-item"><span class="info-label">Order Date:</span> ${labOrder.formatted_order_datetime}</div>
                                <div class="info-item"><span class="info-label">Ordered By:</span> ${labOrder.ordered_by_name || 'Not specified'}</div>
                                <div class="info-item"><span class="info-label">Physician Role:</span> ${labOrder.ordered_by_role || 'Not specified'}</div>
                                <div class="info-item"><span class="info-label">Status:</span> ${labOrder.overall_status.toUpperCase()}</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="progress-summary">
                        <h3 style="margin-top: 0; color: #0077b6;">Test Progress Summary</h3>
                        <div class="progress-grid">
                            <div class="progress-item">
                                <div class="progress-number" style="color: #333;">${labOrder.total_items}</div>
                                <div class="progress-label">Total Tests</div>
                            </div>
                            <div class="progress-item">
                                <div class="progress-number" style="color: #28a745;">${labOrder.completed_items}</div>
                                <div class="progress-label">Completed</div>
                            </div>
                            <div class="progress-item">
                                <div class="progress-number" style="color: #ffc107;">${labOrder.in_progress_items}</div>
                                <div class="progress-label">In Progress</div>
                            </div>
                            <div class="progress-item">
                                <div class="progress-number" style="color: #6c757d;">${labOrder.pending_items}</div>
                                <div class="progress-label">Pending</div>
                            </div>
                        </div>
                        <div style="background: #ddd; height: 20px; border-radius: 10px; overflow: hidden;">
                            <div style="background: #28a745; height: 100%; width: ${labOrder.progress_percentage}%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 12px;">
                                ${labOrder.progress_percentage}% Complete
                            </div>
                        </div>
                    </div>
                    
                    <table class="tests-table">
                        <thead>
                            <tr>
                                <th>Test Type</th>
                                <th>Specimen</th>
                                <th>Status</th>
                                <th>Normal Range</th>
                                <th>Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${labItemsHtml}
                        </tbody>
                    </table>
                    
                    ${labOrder.order_remarks ? `
                        <div class="order-info">
                            <h3 style="color: #0077b6;">Order Remarks</h3>
                            <p style="font-style: italic; background: #f8f9fa; padding: 10px; border-radius: 5px;">${labOrder.order_remarks}</p>
                        </div>
                    ` : ''}
                    
                    <div class="footer">
                        <p>Report generated on ${new Date().toLocaleDateString('en-US', { 
                            year: 'numeric', month: 'long', day: 'numeric', 
                            hour: '2-digit', minute: '2-digit' 
                        })}</p>
                        <p>This is a computer-generated laboratory report from the Web-Based Healthcare Services Management System</p>
                    </div>
                </body>
                </html>
            `;
            
            // Open print window
            const printWindow = window.open('', '_blank');
            printWindow.document.write(printContent);
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
        }
        
        // View individual test result file
        function viewResultFile(itemId) {
            console.log('viewResultFile called with item ID:', itemId);
            
            // Open result file in new window
            const resultUrl = `/wbhsms-cho-koronadal-1/api/get_lab_result_file.php?item_id=${itemId}`;
            window.open(resultUrl, '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
        }
        
        // ==================== PRESCRIPTION MODAL FUNCTIONS ====================
        
        // View prescription details in modal
        function viewPrescription(prescriptionId) {
            console.log('viewPrescription called with ID:', prescriptionId);
            
            // Find prescription in current data
            const prescription = currentPrescriptions.find(p => p.prescription_id == prescriptionId);
            if (!prescription) {
                console.error('Prescription not found in current data');
                return;
            }
            
            // Display prescription details
            displayPrescriptionDetails(prescription);
            
            // Show modal
            document.getElementById('viewPrescriptionModal').style.display = 'block';
        }
        
        // Display prescription details in modal
        function displayPrescriptionDetails(prescription) {
            const contentDiv = document.getElementById('prescriptionDetailsContent');
            
            // Store prescription data globally for print function
            currentPrescriptionData = prescription;
            
            // Format prescription date
            const prescriptionDate = prescription.formatted_date || new Date(prescription.prescription_date).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
            
            // Build medications list
            let medicationsHtml = '';
            if (prescription.medications && prescription.medications.length > 0) {
                prescription.medications.forEach((med, index) => {
                    const medStatusClass = med.status === 'dispensed' ? 'badge-success' : med.status === 'pending' ? 'badge-warning' : 'badge-secondary';
                    
                    medicationsHtml += `
                        <div class="medication-item">
                            <div class="medication-header">
                                <h6 class="medication-name">${med.medication_name}</h6>
                                <span class="badge ${medStatusClass}">${med.status}</span>
                            </div>
                            <div class="medication-details">
                                <div class="medication-detail">
                                    <strong>Dosage:</strong><br>
                                    ${med.dosage}
                                </div>
                                <div class="medication-detail">
                                    <strong>Frequency:</strong><br>
                                    ${med.frequency || 'As needed'}
                                </div>
                                <div class="medication-detail">
                                    <strong>Duration:</strong><br>
                                    ${med.duration || 'As prescribed'}
                                </div>
                            </div>
                            ${med.instructions ? `
                                <div class="medication-instructions">
                                    <strong>Special Instructions:</strong><br>
                                    ${med.instructions}
                                </div>
                            ` : ''}
                        </div>
                    `;
                });
            } else {
                medicationsHtml = '<div class="detail-item"><div class="detail-value">No medications recorded</div></div>';
            }
            
            contentDiv.innerHTML = `
                <div class="appointment-details-grid">
                    <div class="details-section">
                        <h4><i class="fas fa-user"></i> Patient Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Patient Name</span>
                            <span class="detail-value highlight">${prescription.patient_name}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Patient ID</span>
                            <span class="detail-value">${prescription.patient_id}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Date of Birth</span>
                            <span class="detail-value">${prescription.date_of_birth || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Contact</span>
                            <span class="detail-value">${prescription.contact_number || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Barangay</span>
                            <span class="detail-value">${prescription.barangay_name || 'N/A'}</span>
                        </div>
                    </div>
                    
                    <div class="details-section">
                        <h4><i class="fas fa-pills"></i> Prescription Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Prescription ID</span>
                            <span class="detail-value highlight">PRX-${String(prescription.prescription_id).padStart(8, '0')}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Date Prescribed</span>
                            <span class="detail-value">${prescriptionDate}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Prescribed By</span>
                            <span class="detail-value">${prescription.prescribed_by_doctor}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Doctor Position</span>
                            <span class="detail-value">${prescription.doctor_position || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Facility</span>
                            <span class="detail-value">${prescription.facility_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status</span>
                            <span class="detail-value highlight">${prescription.overall_status}</span>
                        </div>
                    </div>
                </div>
                
                <div class="details-section" style="grid-column: 1 / -1;">
                    <h4><i class="fas fa-pills"></i> Prescribed Medications</h4>
                    ${medicationsHtml}
                </div>
                
                ${prescription.remarks ? `
                <div class="details-section" style="grid-column: 1 / -1;">
                    <h4><i class="fas fa-comment"></i> Remarks</h4>
                    <div class="detail-item">
                        <div class="detail-value" style="background: white; padding: 1rem; border-radius: 8px; border-left: 4px solid #0077b6; line-height: 1.5;">${prescription.remarks}</div>
                    </div>
                </div>
                ` : ''}
            `;
        }
        
        // ==================== CONSULTATION MODAL FUNCTIONS ====================
        
        // View consultation details in modal
        function viewConsultationDetails(consultationId) {
            console.log('viewConsultationDetails called with ID:', consultationId);
            
            if (!consultationId) {
                console.error('No consultation ID provided');
                return;
            }
            
            // Find the consultation in our current data
            const consultation = currentConsultations.find(c => c.encounter_id == consultationId);
            
            if (!consultation) {
                console.error('Consultation not found in current data');
                alert('Consultation details not available. Please refresh the page and try again.');
                return;
            }
            
            const modal = document.getElementById('viewConsultationModal');
            const modalContent = document.getElementById('consultationDetailsContent');
            
            if (!modal || !modalContent) {
                console.error('Consultation modal elements not found');
                return;
            }
            
            // Display consultation details using available data
            displayConsultationDetails(consultation);
            
            // Show modal
            openModal('viewConsultationModal');
        }
        
        // Display consultation details in modal
        function displayConsultationDetails(consultation) {
            const modalContent = document.getElementById('consultationDetailsContent');
            
            // Store consultation data globally for print function
            currentConsultationData = consultation;
            
            const consultationDate = new Date(consultation.consultation_date);
            const formattedDate = consultationDate.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            const formattedTime = consultationDate.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit'
            });
            
            let statusClass = 'badge-secondary';
            switch (consultation.status?.toLowerCase()) {
                case 'completed': statusClass = 'badge-success'; break;
                case 'ongoing': statusClass = 'badge-primary'; break;
                case 'pending': statusClass = 'badge-warning'; break;
                case 'cancelled': statusClass = 'badge-danger'; break;
            }
            
            modalContent.innerHTML = `
                <div class="appointment-details-grid">
                    <div class="details-section">
                        <h4><i class="fas fa-info-circle"></i> Basic Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Consultation Date:</span>
                            <span class="detail-value">${formattedDate} at ${formattedTime}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Doctor:</span>
                            <span class="detail-value">${consultation.doctor_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Facility:</span>
                            <span class="detail-value">${consultation.facility_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Service:</span>
                            <span class="detail-value">${consultation.service_name || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status:</span>
                            <span class="detail-value"><span class="badge ${statusClass}">${consultation.status || 'Unknown'}</span></span>
                        </div>
                    </div>
                    
                    <div class="details-section">
                        <h4><i class="fas fa-stethoscope"></i> Clinical Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Chief Complaint:</span>
                            <span class="detail-value">${consultation.chief_complaint || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Diagnosis:</span>
                            <span class="detail-value">${consultation.diagnosis || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Treatment Plan:</span>
                            <span class="detail-value">${consultation.treatment_plan || 'N/A'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Remarks:</span>
                            <span class="detail-value">${consultation.remarks || 'N/A'}</span>
                        </div>
                        ${consultation.follow_up_date ? `
                        <div class="detail-item">
                            <span class="detail-label">Follow-up Date:</span>
                            <span class="detail-value highlight">${new Date(consultation.follow_up_date).toLocaleDateString('en-US', {
                                year: 'numeric',
                                month: 'long', 
                                day: 'numeric'
                            })}</span>
                        </div>
                        ` : ''}
                    </div>
                    
                    ${consultation.blood_pressure || consultation.heart_rate || consultation.temperature || consultation.weight ? `
                    <div class="details-section" style="grid-column: 1 / -1;">
                        <h4><i class="fas fa-heartbeat"></i> Vital Signs</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                            ${consultation.blood_pressure ? `
                            <div class="detail-item">
                                <span class="detail-label">Blood Pressure:</span>
                                <span class="detail-value">${consultation.blood_pressure}</span>
                            </div>
                            ` : ''}
                            ${consultation.heart_rate ? `
                            <div class="detail-item">
                                <span class="detail-label">Heart Rate:</span>
                                <span class="detail-value">${consultation.heart_rate} bpm</span>
                            </div>
                            ` : ''}
                            ${consultation.temperature ? `
                            <div class="detail-item">
                                <span class="detail-label">Temperature:</span>
                                <span class="detail-value">${consultation.temperature}°C</span>
                            </div>
                            ` : ''}
                            ${consultation.weight ? `
                            <div class="detail-item">
                                <span class="detail-label">Weight:</span>
                                <span class="detail-value">${consultation.weight} kg</span>
                            </div>
                            ` : ''}
                            ${consultation.height ? `
                            <div class="detail-item">
                                <span class="detail-label">Height:</span>
                                <span class="detail-value">${consultation.height} cm</span>
                            </div>
                            ` : ''}
                            ${consultation.bmi ? `
                            <div class="detail-item">
                                <span class="detail-label">BMI:</span>
                                <span class="detail-value">${consultation.bmi}</span>
                            </div>
                            ` : ''}
                        </div>
                    </div>
                    ` : ''}
                </div>
            `;
        }
        
        // Print consultation details
        // ==================== PAGE INITIALIZATION ====================
        
        // Initialize page when DOM loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM fully loaded');
            
            // Add event listeners for search
            const patientInput = document.getElementById('patient');
            const barangaySelect = document.getElementById('barangay');
            
            if (patientInput) {
                patientInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        performPatientSearch();
                    }
                });
            }
            
            if (barangaySelect) {
                barangaySelect.addEventListener('change', function() {
                    if (currentSearchTerm) {
                        performPatientSearch();
                    }
                });
            }
            
            // Auto-perform search after short delay to allow for any URL parameter processing
            setTimeout(function() {
                performPatientSearch(1);
            }, 100);
            
            // Check if there are URL parameters from a previous search (page reload)
            const urlParams = new URLSearchParams(window.location.search);
            const patientParam = urlParams.get('patient');
            const pageParam = parseInt(urlParams.get('page')) || 1;
            
            if (patientParam) {
                console.log('Found patient parameter in URL, will search for:', patientParam, 'page:', pageParam);
                // The automatic load above will handle this
            }
        });
    </script>
</body>

</html>