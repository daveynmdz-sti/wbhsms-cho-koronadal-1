<?php
// referrals_management.php - Admin Side
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Add cache busting for data freshness
if (isset($_POST['action'])) {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Expires: 0');
    header('Pragma: no-cache');
}

// Include employee session configuration - Use absolute path resolution
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';

// If user is not logged in, bounce to login - use session management function
if (!is_employee_logged_in()) {
    redirect_to_employee_login();
}

// Check if role is authorized
$authorized_roles = ['doctor', 'nurse', 'bhw', 'dho', 'records_officer', 'admin'];
require_employee_role($authorized_roles);

// Database connection
require_once $root_path . '/config/db.php';

// Include referral permissions utility
require_once $root_path . '/utils/referral_permissions.php';

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed. Please contact administrator.");
}

$employee_id = $_SESSION['employee_id'] ?? '';
$employee_role = $_SESSION['role'] ?? '';

// Define role-based permissions for referrals management  
$canCreateReferrals = true; // All authorized roles can create referrals
// Note: Edit functionality has been removed. Users should cancel and create new referrals for modifications.
$canViewReferrals = true; // All authorized roles can view

// Get jurisdiction restrictions using new permission system
$jurisdiction_data = getEmployeeJurisdictionRestriction($conn, $employee_id, $employee_role);
$jurisdiction_restriction = $jurisdiction_data['restriction'];
$jurisdiction_params = $jurisdiction_data['params'];

// Build parameter types string for prepared statements
$jurisdiction_param_types = '';
foreach ($jurisdiction_params as $param) {
    $jurisdiction_param_types .= is_int($param) ? 'i' : 's';
}

// Log jurisdiction access for audit
error_log("Referrals access: Employee ID $employee_id (Role: $employee_role) with restriction: " .
    ($jurisdiction_restriction ?: 'none'));

// Debug output for jurisdiction restrictions (only if debug parameter is set)
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    echo "<div style='background: #f0f0f0; padding: 10px; margin: 10px; border: 1px solid #ccc;'>";
    echo "<h4>Debug Information:</h4>";
    echo "Employee ID: $employee_id<br>";
    echo "Employee Role: $employee_role<br>";
    echo "Jurisdiction Restriction: " . ($jurisdiction_restriction ?: 'none') . "<br>";
    echo "Jurisdiction Params: " . implode(', ', $jurisdiction_params) . "<br>";

    // Quick diagnosis
    echo "<br><strong>Issue Diagnosis:</strong><br>";

    // Check if getEmployeeBHWBarangay returns something
    $test_barangay = getEmployeeBHWBarangay($conn, $employee_id);
    echo "BHW Barangay ID from function: " . ($test_barangay ?: 'NULL') . "<br>";

    // Check employee record with EMP format
    $emp_formatted = 'EMP' . str_pad($employee_id, 5, '0', STR_PAD_LEFT);
    echo "Formatted Employee ID: $emp_formatted<br>";

    $test_stmt = $conn->prepare("SELECT e.employee_id, r.role_name, e.facility_id FROM employees e LEFT JOIN roles r ON e.role_id = r.role_id WHERE e.employee_id = ?");
    $test_stmt->bind_param('s', $emp_formatted);
    $test_stmt->execute();
    $test_result = $test_stmt->get_result()->fetch_assoc();

    if ($test_result) {
        echo "✓ Employee found: " . json_encode($test_result) . "<br>";

        if ($test_result['facility_id']) {
            $facility_stmt = $conn->prepare("SELECT facility_id, name, barangay_id FROM facilities WHERE facility_id = ?");
            $facility_stmt->bind_param('i', $test_result['facility_id']);
            $facility_stmt->execute();
            $facility_result = $facility_stmt->get_result()->fetch_assoc();

            if ($facility_result) {
                echo "✓ Facility found: " . json_encode($facility_result) . "<br>";

                if ($facility_result['barangay_id']) {
                    echo "✓ Barangay ID found: " . $facility_result['barangay_id'] . "<br>";
                    echo "<strong>EXPECTED RESTRICTION:</strong> AND (r.referred_by = ? OR p.barangay_id = ?)<br>";
                    echo "<strong>EXPECTED PARAMS:</strong> $employee_id, " . $facility_result['barangay_id'] . "<br>";
                } else {
                    echo "✗ Facility has no barangay_id assigned<br>";
                }
            } else {
                echo "✗ Facility not found for facility_id: " . $test_result['facility_id'] . "<br>";
            }
        } else {
            echo "✗ Employee has no facility_id assigned<br>";
        }
    } else {
        echo "✗ Employee not found with ID: $emp_formatted<br>";
    }

    echo "</div>";
}

// Get employee's jurisdiction name for display
$jurisdiction_name = getEmployeeJurisdictionName($conn, $employee_id, $employee_role);

// Handle status updates and actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug entry point - Log exactly what POST data is received
    error_log("DEBUG ENTRY >>> Action: " . ($_POST['action'] ?? 'none') .
        " | Referral ID: " . ($_POST['referral_id'] ?? 'none') .
        " | Employee ID: " . ($_SESSION['employee_id'] ?? 'none'));

    $action = $_POST['action'] ?? '';
    $referral_id = $_POST['referral_id'] ?? '';

    if (!empty($referral_id) && is_numeric($referral_id)) {
        try {
            // Check permission before performing any action
            if (!canEmployeeEditReferral($conn, $employee_id, $referral_id, $employee_role)) {
                $error = "Access denied. You can only modify referrals you created.";
            } else {
                switch ($action) {
                    case 'complete':
                        $stmt = $conn->prepare("UPDATE referrals SET status = 'issued' WHERE referral_id = ?");
                        $stmt->bind_param("i", $referral_id);
                        $stmt->execute();
                        $message = "Referral marked as completed successfully.";
                        $stmt->close();

                        // Log the action
                        logReferralAccess($conn, $employee_id, $referral_id, 'complete', 'Referral marked as completed');
                        break;

                    case 'void':
                        $void_reason = trim($_POST['void_reason'] ?? '');
                        if (empty($void_reason)) {
                            $error = "Void reason is required.";
                        } else {
                            $stmt = $conn->prepare("UPDATE referrals SET status = 'cancelled' WHERE referral_id = ?");
                            $stmt->bind_param("i", $referral_id);
                            $stmt->execute();
                            $message = "Referral voided successfully.";
                            $stmt->close();

                            // Log the action
                            logReferralAccess($conn, $employee_id, $referral_id, 'void', 'Referral voided: ' . $void_reason);
                        }
                        break;

                    case 'reactivate':
                        error_log("DEBUG: Starting reactivate case for referral ID: $referral_id");

                        // Simple direct update without transaction complications
                        $stmt = $conn->prepare("UPDATE referrals SET status = 'active', updated_at = NOW() WHERE referral_id = ?");
                        $stmt->bind_param("i", $referral_id);

                        if ($stmt->execute()) {
                            $affected_rows = $stmt->affected_rows;
                            error_log("DEBUG: Direct UPDATE executed. Affected rows: $affected_rows");

                            if ($affected_rows > 0) {
                                $stmt->close();

                                // Verify the update worked
                                $verify = $conn->prepare("SELECT status FROM referrals WHERE referral_id = ?");
                                $verify->bind_param("i", $referral_id);
                                $verify->execute();
                                $result = $verify->get_result();
                                $new_status = $result->fetch_assoc()['status'] ?? 'unknown';
                                $verify->close();

                                error_log("DEBUG: Verification check - new status: $new_status");

                                // FINAL CHECK: Query the database one more time to see what's really there
                                $final_check = $conn->prepare("SELECT status, updated_at FROM referrals WHERE referral_id = ?");
                                $final_check->bind_param("i", $referral_id);
                                $final_check->execute();
                                $final_result = $final_check->get_result();
                                $final_data = $final_result->fetch_assoc();
                                $final_check->close();

                                error_log("DEBUG: FINAL DATABASE CHECK - Status: " . ($final_data['status'] ?? 'NULL') . ", Updated: " . ($final_data['updated_at'] ?? 'NULL'));

                                // Try to log access (but don't let it break the main operation)
                                try {
                                    logReferralAccess($conn, $employee_id, $referral_id, 'reactivate', "Referral reactivated to active status");
                                } catch (Exception $log_error) {
                                    error_log("DEBUG: Logging failed: " . $log_error->getMessage());
                                }

                                $message = "Referral reactivated successfully. New status: $new_status";
                                header("Location: referrals_management.php?reactivated=1&id=" . $referral_id);
                                exit;
                            } else {
                                $stmt->close();
                                $error = "No rows updated. Referral may already be active.";
                                error_log("DEBUG: Zero affected rows");
                            }
                        } else {
                            $error = "Database error: " . $stmt->error;
                            error_log("DEBUG: UPDATE failed: " . $stmt->error);
                            $stmt->close();
                        }
                        break;
                }
            }
        } catch (Exception $e) {
            $error = "Failed to update referral: " . $e->getMessage();
        }
    } else {
        // Handle invalid or missing referral_id
        if (!empty($action)) {
            $error = "Invalid referral ID provided. Action '$action' could not be performed.";
            error_log("DEBUG: Invalid referral_id received - Action: $action, Referral ID: '$referral_id'");
        }
    }
}

// Fetch referrals with patient information
$patient_search = $_GET['patient_search'] ?? '';
$barangay = $_GET['barangay'] ?? '';
$status_filter = $_GET['status'] ?? '';
$notice = $_GET['notice'] ?? '';

// Pagination parameters
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = in_array(intval($_GET['per_page'] ?? 25), [10, 25, 50, 100]) ? intval($_GET['per_page'] ?? 25) : 25;
$offset = ($page - 1) * $per_page;

$where_conditions = [];
$params = [];
$param_types = '';

// Employee-based restriction: Only show referrals created by the logged-in employee
$where_conditions[] = "r.referred_by = ?";
$params[] = $employee_id;
$param_types .= 's';

// Current month restriction: Only show referrals from current month
$where_conditions[] = "YEAR(r.referral_date) = YEAR(CURDATE()) AND MONTH(r.referral_date) = MONTH(CURDATE())";

if (!empty($patient_search)) {
    $where_conditions[] = "(p.username LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)";
    $search_term = "%$patient_search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $param_types .= 'sss';
}

if (!empty($barangay)) {
    $where_conditions[] = "b.barangay_name LIKE ?";
    $barangay_term = "%$barangay%";
    $params[] = $barangay_term;
    $param_types .= 's';
}

if (!empty($status_filter)) {
    $where_conditions[] = "r.status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

try {
    // Auto-update expired referrals (over 48 hours old and still active/pending)
    $expire_stmt = $conn->prepare("
        UPDATE referrals 
        SET status = 'cancelled' 
        WHERE status IN ('active', 'pending') 
        AND TIMESTAMPDIFF(HOUR, referral_date, NOW()) > 48
    ");
    $expire_stmt->execute();
    $expire_stmt->close();

    // Get total count for pagination
    $count_sql = "
        SELECT COUNT(*) as total
        FROM referrals r
        LEFT JOIN patients p ON r.patient_id = p.patient_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN employees e ON r.referred_by = e.employee_id
        LEFT JOIN facilities f ON r.referred_to_facility_id = f.facility_id
        WHERE p.status = 'active'
        " . (!empty($where_clause) ? str_replace('WHERE', 'AND', $where_clause) : '') . "
    ";

    $count_stmt = $conn->prepare($count_sql);

    // Use only the employee-based filter parameters for count query
    if (!empty($params)) {
        $count_stmt->bind_param($param_types, ...$params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_records = $count_result->fetch_assoc()['total'];
    $count_stmt->close();

    $total_pages = ceil($total_records / $per_page);

    $sql = "
        SELECT r.referral_id, r.referral_num, r.patient_id, r.referral_reason, r.destination_type, 
               r.referred_to_facility_id, r.external_facility_name, r.referral_date, r.status, r.referred_by,
               p.first_name, p.middle_name, p.last_name, p.username as patient_number, 
               b.barangay_name as barangay,
               e.first_name as issuer_first_name, e.last_name as issuer_last_name,
               f.name as referred_facility_name
        FROM referrals r
        LEFT JOIN patients p ON r.patient_id = p.patient_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN employees e ON r.referred_by = e.employee_id
        LEFT JOIN facilities f ON r.referred_to_facility_id = f.facility_id
        WHERE p.status = 'active'
        " . (!empty($where_clause) ? str_replace('WHERE', 'AND', $where_clause) : '') . "
        ORDER BY r.referral_date DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);

    // Use only the employee-based filter parameters for main query
    $all_params = $params;
    $all_param_types = $param_types;

    // Add pagination parameters
    $all_params[] = $per_page;
    $all_params[] = $offset;
    $all_param_types .= 'ii';

    if (!empty($all_params)) {
        $stmt->bind_param($all_param_types, ...$all_params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $referrals = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    $error = "Failed to fetch referrals: " . $e->getMessage();
    $referrals = [];
    $total_records = 0;
    $total_pages = 0;
}

// Get statistics
$stats = [
    'total' => 0,
    'active' => 0,
    'issued' => 0,  // completed referrals
    'accepted' => 0,
    'cancelled' => 0  // includes both void and cancelled
];

try {
    // Statistics query with employee-based restriction and current month filter
    $stats_sql = "
        SELECT r.status, COUNT(*) as count 
        FROM referrals r
        LEFT JOIN patients p ON r.patient_id = p.patient_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE r.referred_by = ? 
        AND YEAR(r.referral_date) = YEAR(CURDATE()) 
        AND MONTH(r.referral_date) = MONTH(CURDATE())
        GROUP BY r.status
    ";

    $stmt = $conn->prepare($stats_sql);
    $stmt->bind_param('s', $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (isset($stats[$row['status']])) {
            $stats[$row['status']] = $row['count'];
        }
        $stats['total'] += $row['count'];
    }
    $stmt->close();
} catch (Exception $e) {
    // Ignore errors for stats
}

// Fetch barangays for dropdown
$barangays = [];
try {
    $stmt = $conn->prepare("SELECT barangay_id, barangay_name FROM barangay WHERE status = 'active' ORDER BY barangay_name ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    $barangays = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    // Ignore errors for barangays
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
    <title>CHO Koronadal — Referrals Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/sidebar.css">
    <style>
        /* ===== PART 1: CORE LAYOUT & COMPONENTS ===== */

        /* Layout */
        .content-wrapper {
            margin-left: 300px;
            padding: 2rem;
            transition: margin-left 0.3s;
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

        /* Card Components */
        .card-container,
        .filters-container {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        /* Statistics */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-card.total {
            border-left: 4px solid #0077b6;
        }

        .stat-card.active {
            border-left: 4px solid #43e97b;
        }

        .stat-card.completed {
            border-left: 4px solid #4facfe;
        }

        .stat-card.accepted {
            border-left: 4px solid #28a745;
        }

        .stat-card.canceled {
            border-left: 4px solid #fa709a;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Forms & Filters */
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
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

        /* Buttons */
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

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
        }

        /* ===== PART 2: TABLE & DATA DISPLAY ===== */

        /* Table Container */
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .table-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .table th,
        .table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
            white-space: normal;
        }

        .table th {
            background: linear-gradient(135deg, #0077b6, #03045e);
            color: white;
            font-weight: 500;
            position: sticky;
            top: 0;
            z-index: 10;
            cursor: pointer;
            user-select: none;
        }

        .table tbody tr:hover {
            background: #f8f9fa;
        }

        /* Scrollbar */
        .table-wrapper::-webkit-scrollbar {
            height: 8px;
        }

        .table-wrapper::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .table-wrapper::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        .table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Badges & Status */
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .badge-primary {
            background: #007bff;
            color: white;
        }

        .badge-success {
            background: #28a745;
            color: white;
        }

        .badge-info {
            background: #17a2b8;
            color: white;
        }

        .badge-warning {
            background: #ffc107;
            color: #212529;
        }

        .badge-danger {
            background: #dc3545;
            color: white;
        }

        .badge-secondary {
            background: #6c757d;
            color: white;
        }

        .actions-group {
            display: flex;
            gap: 0.25rem;
            flex-wrap: wrap;
        }

        /* Alerts */
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

        /* Mobile Cards */
        .mobile-cards {
            display: none;
            padding: 0;
        }

        .mobile-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 1rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .mobile-card-header {
            background: #f8f9fa;
            padding: 0.75rem;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
        }

        .mobile-card-body {
            padding: 0.75rem;
            font-size: 0.85rem;
            line-height: 1.4;
        }

        .mobile-card-field {
            margin-bottom: 0.5rem;
        }

        .mobile-card-field:last-child {
            margin-bottom: 0;
        }

        .mobile-card-label {
            font-weight: 600;
            color: #495057;
            display: inline-block;
            min-width: 80px;
        }

        /* Utility */
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

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* ===== PART 3: MODALS & RESPONSIVE DESIGN ===== */

        /* Standard Modals */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }

        #cancelReferralModal {
            z-index: 11000;
            align-items: anchor-center;
        }

        #passwordVerificationModal {
            z-index: 12000;
        }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 12px;
            max-width: 500px;
            position: relative;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e9ecef;
        }

        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #e9ecef;
            flex-wrap: wrap;
        }

        .close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6c757d;
        }

        /* Details Sections */
        .referral-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .details-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
        }

        .details-section h4 {
            color: #03045e;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
            font-size: 1.1rem;
        }

        .detail-item {
            display: flex;
            margin-bottom: 0.75rem;
            align-items: flex-start;
        }

        .detail-label {
            font-weight: 600;
            color: #495057;
            min-width: 120px;
            margin-right: 0.5rem;
        }

        .detail-value {
            flex: 1;
            word-wrap: break-word;
        }

        .vitals-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.5rem;
        }

        .vitals-table th,
        .vitals-table td {
            padding: 0.5rem;
            text-align: left;
            border: 1px solid #dee2e6;
        }

        .vitals-table th {
            background: #e9ecef;
            font-weight: 600;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
                padding: 1rem;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                justify-content: center;
            }

            .table th,
            .table td {
                padding: 0.5rem 0.25rem;
                font-size: 0.85rem;
            }

            .table th:nth-child(3),
            .table td:nth-child(3),
            .table th:nth-child(8),
            .table td:nth-child(8) {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .table {
                min-width: 600px;
            }

            .table th,
            .table td {
                padding: 0.4rem 0.2rem;
                font-size: 0.8rem;
            }

            .table th:nth-child(4),
            .table td:nth-child(4),
            .table th:nth-child(5),
            .table td:nth-child(5) {
                display: none;
            }
        }

        @media (max-width: 400px) {
            .table-wrapper {
                display: none;
            }

            .mobile-cards {
                display: block;
            }

            .badge {
                font-size: 0.7rem;
                padding: 0.2rem 0.4rem;
            }

            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }

            .actions-group .btn {
                margin: 0.125rem;
            }
        }

        /* Referral Details Modal Styles (matching create_referrals.php) */
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
            gap: 0.75rem;
            justify-content: center;
            padding: 1rem 1.5rem 1.5rem;
            flex-shrink: 0;
            background: white;
            border-top: 1px solid #e9ecef;
            flex-wrap: wrap;
        }

        .modal-btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9em;
            min-width: 90px;
            flex: 1;
            max-width: 120px;
            gap: 20px;
            align-items: center;
        }

        .modal-btn-secondary {
            background: #f8f9fa;
            color: #6c757d;
            border: 2px solid #dee2e6;
        }

        .modal-btn-secondary:hover {
            background: #e9ecef;
            color: #5a6268;
        }

        .modal-btn-primary {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            box-shadow: 0 4px 15px rgba(0, 119, 182, 0.3);
        }

        .modal-btn-primary:hover {
            background: linear-gradient(135deg, #023e8a, #001d3d);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 119, 182, 0.4);
        }

        .modal-btn-success {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .modal-btn-success:hover {
            background: linear-gradient(135deg, #218838, #1e7e34);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }

        .modal-btn-danger {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }

        .modal-btn-danger:hover {
            background: linear-gradient(135deg, #c82333, #bd2130);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
        }

        .modal-btn-warning {
            background: linear-gradient(135deg, #ffc107, #e0a800);
            color: #212529;
            box-shadow: 0 4px 15px rgba(255, 193, 7, 0.3);
        }

        .modal-btn-warning:hover {
            background: linear-gradient(135deg, #e0a800, #d39e00);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 193, 7, 0.4);
        }

        /* Mobile responsive design for modal */
        @media (max-width: 768px) {
            .referral-modal-actions {
                gap: 0.5rem;
                padding: 1rem;
                justify-content: center;
                flex-wrap: wrap;
            }

            .modal-btn {
                padding: 0.5rem 0.8rem;
                font-size: 0.8em;
                min-width: 80px;
                max-width: 120px;
                flex: 1 1 auto;
            }

            .referral-confirmation-modal .modal-content {
                margin: 0.5rem;
                max-height: 95vh;
            }

            .modal-header h3 {
                font-size: 1.2em;
            }

            .modal-body {
                font-size: 0.9em;
                max-height: calc(95vh - 200px);
            }
        }

        @media (max-width: 480px) {
            .referral-modal-actions {
                flex-direction: column;
                gap: 0.5rem;
                align-items: stretch;
            }

            .modal-btn {
                max-width: 100%;
                padding: 0.75rem 1rem;
                font-size: 0.85em;
                flex: none;
                width: 100%;
                gap: 20px;
                align-items: center;
            }
        }

        @media (max-width: 360px) {
            .referral-modal-actions {
                padding: 0.75rem;
            }

            .modal-btn {
                padding: 0.6rem 0.8rem;
                font-size: 0.8em;
            }
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

        /* Snackbar animations */
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100%);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideOutRight {
            from {
                opacity: 1;
                transform: translateX(0);
            }

            to {
                opacity: 0;
                transform: translateX(100%);
            }
        }

        /* ===== PART 4: UTILITY CLASSES & INLINE STYLE REPLACEMENTS ===== */

        /* Spacing utilities */
        .mt-50 {
            margin-top: 50px !important;
        }

        .mt-05 {
            margin-top: 0.5rem !important;
        }

        .mt-075 {
            margin-top: 0.75rem !important;
        }

        .mb-0 {
            margin-bottom: 0 !important;
        }

        .mb-05 {
            margin-bottom: 0.5rem !important;
        }

        .mb-1 {
            margin-bottom: 1rem !important;
        }

        .mb-15 {
            margin-bottom: 1.5rem !important;
        }

        /* Section headers */
        .section-header {
            padding: 0 0 15px 0;
            margin-bottom: 15px;
            border-bottom: 1px solid rgba(0, 119, 182, 0.2);
        }

        .section-header h4 {
            margin: 0;
            color: #03045e;
            font-size: 18px;
            font-weight: 600;
        }

        /* Table cell content styling */
        .patient-info-cell {
            max-width: 150px;
        }

        .patient-name {
            font-weight: 600;
        }

        .patient-id {
            color: #6c757d;
        }

        .reason-cell {
            max-width: 200px;
            white-space: normal;
            word-wrap: break-word;
        }

        .facility-cell {
            max-width: 150px;
            white-space: normal;
            word-wrap: break-word;
        }

        .date-cell {
            font-size: 0.85rem;
        }

        .inline-form {
            display: inline;
        }

        .mobile-cards-hidden {
            display: none;
        }

        /* Modal specific styles */
        .modal-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
        }

        .modal-flex-end {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        .modal-description {
            margin: 0.5rem 0 0;
            opacity: 0.9;
        }

        .modal-loading {
            text-align: center;
            padding: 2rem;
        }

        .edit-btn-hidden {
            display: none;
        }

        /* Confirmation modal styles */
        .confirmation-section {
            background: #e8f5e8;
            border: 1px solid #c3e6c3;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .confirmation-title {
            color: #155724;
            margin: 0 0 0.5rem 0;
            font-size: 1rem;
        }

        .confirmation-text {
            margin: 0;
            color: #155724;
            line-height: 1.5;
        }

        .action-list-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .action-list-title {
            color: #0077b6;
            margin: 0 0 0.75rem 0;
            font-size: 0.95rem;
        }

        .action-list {
            margin: 0;
            padding-left: 1.2rem;
            color: #555;
        }

        .action-list li {
            margin-bottom: 0.5rem;
        }

        .action-list li:last-child {
            margin: 0;
        }

        .warning-section {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 1rem;
        }

        .warning-text {
            margin: 0;
            color: #856404;
            font-size: 0.9rem;
        }

        .warning-icon {
            color: #f39c12;
        }

        /* Cancel form specific styles */
        .cancel-warning {
            color: #856404;
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            margin-bottom: 1rem;
        }

        .form-textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            resize: vertical;
            min-height: 100px;
        }

        .form-input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
        }

        .form-help {
            color: #666;
            font-size: 0.85em;
        }

        .button-group {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            flex-wrap: wrap;
        }

        .btn-min-width {
            min-width: 100px;
        }

        .btn-min-width-140 {
            min-width: 140px;
        }

        /* Large modal content */
        .modal-content-large {
            max-width: 500px;
            max-height: fit-content;
        }

        .modal-content-small {
            max-width: 400px;
        }

        /* Text alignment */
        .text-left {
            text-align: left;
        }

        /* Notification styles */
        .notification-snackbar {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 9999;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            min-width: 300px;
            max-width: 500px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            animation: slideInRight 0.3s ease;
        }

        .notification-success {
            background: #28a745;
        }

        .notification-error {
            background: #dc3545;
        }

        .notification-warning {
            background: #ffc107;
        }

        .notification-info {
            background: #17a2b8;
        }

        .notification-close {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            padding: 0.25rem;
            margin-left: auto;
        }

        .notification-close:hover {
            opacity: 0.8;
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
                width: 100%;
                margin-bottom: 0.5rem;
            }

            .modal-btn:last-child {
                margin-bottom: 0;
            }
        }

        /* Pagination Styles */
        .pagination-container {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-top: 1rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .pagination-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .records-info {
            color: #666;
            font-size: 0.9rem;
        }

        .records-info strong {
            color: #0077b6;
        }

        .page-size-selector {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-size-selector label {
            font-size: 0.9rem;
            color: #666;
            font-weight: 500;
        }

        .page-size-selector select {
            padding: 0.5rem;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 0.9rem;
            background: white;
            cursor: pointer;
        }

        .page-size-selector select:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
        }

        .pagination-controls {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .pagination-btn {
            padding: 0.5rem 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            background: white;
            color: #666;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            min-width: 40px;
            justify-content: center;
        }

        .pagination-btn:hover:not(.disabled):not(.active) {
            border-color: #0077b6;
            color: #0077b6;
            background: #f8f9fa;
        }

        .pagination-btn.active {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            border-color: #0077b6;
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            color: #ccc;
        }

        .pagination-btn.prev,
        .pagination-btn.next {
            padding: 0.5rem 1rem;
        }

        .pagination-ellipsis {
            padding: 0.5rem;
            color: #666;
        }

        /* Mobile responsive pagination */
        @media (max-width: 768px) {
            .pagination-info {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .pagination-controls {
                gap: 0.25rem;
            }

            .pagination-btn {
                padding: 0.4rem 0.6rem;
                font-size: 0.8rem;
                min-width: 35px;
            }

            .pagination-btn.prev,
            .pagination-btn.next {
                padding: 0.4rem 0.8rem;
            }
        }

        @media (max-width: 480px) {
            .pagination-container {
                padding: 1rem;
            }

            .pagination-controls {
                justify-content: center;
            }

            .pagination-btn {
                padding: 0.35rem 0.5rem;
                font-size: 0.75rem;
                min-width: 32px;
            }

            /* Hide some page numbers on very small screens */
            .pagination-btn.page-num:not(.active):nth-child(n+6) {
                display: none;
            }
        }
    </style>
</head>

<body>
    <?php
    // Include dynamic sidebar helper
    require_once $root_path . '/includes/dynamic_sidebar_helper.php';

    // Include the correct sidebar based on user role
    includeDynamicSidebar('referrals', $root_path);
    ?>

    <section class="content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb mt-50">
            <a href="<?php echo getRoleDashboardUrl(); ?>"><i class="fas fa-home"></i> Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <span>Referrals Management</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-share"></i> Referrals Management</h1>
            <?php if ($canCreateReferrals): ?>
                <a href="create_referrals.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create Referral
                </a>
            <?php endif; ?>
        </div>

        <!-- Informational Notice about Edit Policy -->
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

        <?php if ($notice === 'edit_deprecated'): ?>
            <div class="alert alert-warning" style="border-left: 4px solid #ff9800;">
                <i class="fas fa-exclamation-triangle"></i> <strong>Edit Feature Unavailable:</strong>
                Referral editing has been disabled. To modify a referral, please <strong>cancel the existing referral</strong> and <strong>create a new one</strong> with the correct information.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['reactivated']) && isset($_GET['id'])): ?>
            <div class="alert alert-success" style="border-left: 4px solid #4caf50;">
                <i class="fas fa-check-circle"></i> <strong>Success:</strong>
                Referral #<?php echo htmlspecialchars($_GET['id']); ?> has been successfully reactivated and is now active.
                <button type="button" class="btn-close" onclick="this.parentElement.remove();" style="float: right; background: none; border: none; font-size: 18px; cursor: pointer;">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Statistics Notice -->
        <div style="background: #e3f2fd; border: 1px solid #1976d2; border-radius: 8px; padding: 12px 16px; margin-bottom: 1.5rem; color: #0d47a1;">
            <i class="fas fa-info-circle" style="margin-right: 8px; color: #1976d2;"></i>
            <strong>Current Month Filter:</strong> The statistics and table below show only referrals you issued in <?php echo date('F Y'); ?>.
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                <div class="stat-label">Total Referrals</div>
            </div>
            <div class="stat-card active">
                <div class="stat-number"><?php echo number_format($stats['active'] ?? 0); ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card completed">
                <div class="stat-number"><?php echo number_format($stats['issued'] ?? 0); ?></div>
                <div class="stat-label">Issued</div>
            </div>
            <div class="stat-card accepted">
                <div class="stat-number"><?php echo number_format($stats['accepted'] ?? 0); ?></div>
                <div class="stat-label">Accepted</div>
            </div>
            <div class="stat-card canceled">
                <div class="stat-number"><?php echo number_format($stats['cancelled'] ?? 0); ?></div>
                <div class="stat-label">Cancelled</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-container">
            <div class="section-header">
                <h4>
                    <i class="fas fa-filter"></i> Search & Filter Options
                </h4>
            </div>
            <form method="GET" class="filters-grid">
                <div class="form-group">
                    <label for="patient_search">Patient Search</label>
                    <input type="text" id="patient_search" name="patient_search" value="<?php echo htmlspecialchars($patient_search); ?>"
                        placeholder="Search by Patient ID, First Name, or Last Name...">
                </div>
                <div class="form-group">
                    <label for="barangay">Barangay</label>
                    <select id="barangay" name="barangay">
                        <option value="">All Barangays</option>
                        <?php foreach ($barangays as $brgy): ?>
                            <option value="<?php echo htmlspecialchars($brgy['barangay_name']); ?>"
                                <?php echo $barangay === $brgy['barangay_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($brgy['barangay_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All Statuses</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="accepted" <?php echo $status_filter === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                        <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="issued" <?php echo $status_filter === 'issued' ? 'selected' : ''; ?>>Issued</option>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                </div>
                <div class="form-group">
                    <a href="?" class="btn btn-secondary mt-05">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Referrals Table -->
        <div class="card-container">
            <div class="section-header">
                <h4>
                    <i class="fas fa-table"></i> Referrals Issued
                </h4>
            </div>
            <div class="table-container">
                <?php if (empty($referrals)): ?>
                    <div class="empty-state">
                        <i class="fas fa-share"></i>
                        <h3>No Referrals Found</h3>
                        <p>No referrals match your current search criteria.</p>
                        <?php if ($canCreateReferrals): ?>
                            <a href="create_referrals.php" class="btn btn-primary" style="margin-top: 20px;">Create First Referral</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- Desktop/Tablet Table View -->
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Referral #</th>
                                    <th>Patient</th>
                                    <th>Barangay</th>
                                    <th>Reason for Referral</th>
                                    <th>Referred Facility</th>
                                    <th>Status</th>
                                    <th>Issued Date</th>
                                    <th>Issued By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($referrals as $referral):
                                    $patient_name = trim($referral['first_name'] . ' ' . ($referral['middle_name'] ? $referral['middle_name'] . ' ' : '') . $referral['last_name']);
                                    $issuer_name = trim($referral['issuer_first_name'] . ' ' . $referral['issuer_last_name']);

                                    // Determine destination based on destination_type
                                    if ($referral['destination_type'] === 'external') {
                                        $destination = $referral['external_facility_name'] ?: 'External Facility';
                                    } else {
                                        $destination = $referral['referred_facility_name'] ?: 'Internal Facility';
                                    }

                                    // Determine badge class based on status
                                    $badge_class = 'badge-secondary';
                                    switch ($referral['status']) {
                                        case 'active':
                                            $badge_class = 'badge-success';
                                            break;
                                        case 'accepted':
                                            $badge_class = 'badge-info';
                                            break;
                                        case 'completed':
                                            $badge_class = 'badge-primary';
                                            break;
                                        case 'cancelled':
                                            $badge_class = 'badge-danger';
                                            break;
                                        default:
                                            $badge_class = 'badge-secondary';
                                            break;
                                    }
                                ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($referral['referral_num']); ?></strong></td>
                                        <td>
                                            <div class="patient-info-cell">
                                                <div class="patient-name"><?php echo htmlspecialchars($patient_name); ?></div>
                                                <small class="patient-id"><?php echo htmlspecialchars($referral['patient_number']); ?></small>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($referral['barangay'] ?? 'N/A'); ?></td>
                                        <td>
                                            <div class="reason-cell">
                                                <?php echo htmlspecialchars($referral['referral_reason']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="facility-cell">
                                                <?php echo htmlspecialchars($destination); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo ucfirst($referral['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="date-cell">
                                                <?php echo date('M j, Y', strtotime($referral['referral_date'])); ?>
                                                <br><small><?php echo date('g:i A', strtotime($referral['referral_date'])); ?></small>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($issuer_name); ?></td>
                                        <td>
                                            <div class="actions-group">
                                                <button type="button" class="btn btn-primary btn-sm" onclick="viewReferral(<?php echo $referral['referral_id']; ?>)" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($referral['status'] === 'voided'): ?>
                                                    <form method="POST" class="inline-form">
                                                        <input type="hidden" name="action" value="reactivate">
                                                        <input type="hidden" name="referral_id" value="<?php echo $referral['referral_id']; ?>">
                                                        <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Reactivate this referral?')" title="Reactivate">
                                                            <i class="fas fa-redo"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Mobile Card View (for very small screens) -->
                    <div class="mobile-cards mobile-cards-hidden">
                        <?php foreach ($referrals as $referral):
                            $patient_name = trim($referral['first_name'] . ' ' . ($referral['middle_name'] ? $referral['middle_name'] . ' ' : '') . $referral['last_name']);
                            $issuer_name = trim($referral['issuer_first_name'] . ' ' . $referral['issuer_last_name']);

                            // Determine destination based on destination_type
                            if ($referral['destination_type'] === 'external') {
                                $destination = $referral['external_facility_name'] ?: 'External Facility';
                            } else {
                                $destination = $referral['referred_facility_name'] ?: 'Internal Facility';
                            }

                            // Determine badge class based on status
                            $badge_class = 'badge-secondary';
                            switch ($referral['status']) {
                                case 'active':
                                    $badge_class = 'badge-success';
                                    break;
                                case 'accepted':
                                    $badge_class = 'badge-info';
                                    break;
                                case 'completed':
                                    $badge_class = 'badge-primary';
                                    break;
                                case 'cancelled':
                                    $badge_class = 'badge-danger';
                                    break;
                                default:
                                    $badge_class = 'badge-secondary';
                                    break;
                            }
                        ?>
                            <div class="mobile-card">
                                <div class="mobile-card-header">
                                    <strong><?php echo htmlspecialchars($referral['referral_num']); ?></strong>
                                    <span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst($referral['status']); ?></span>
                                </div>
                                <div class="mobile-card-body">
                                    <div class="mobile-card-field">
                                        <span class="mobile-card-label">Patient:</span>
                                        <?php echo htmlspecialchars($patient_name); ?> (<?php echo htmlspecialchars($referral['patient_number']); ?>)
                                    </div>
                                    <div class="mobile-card-field">
                                        <span class="mobile-card-label">Barangay:</span>
                                        <?php echo htmlspecialchars($referral['barangay'] ?? 'N/A'); ?>
                                    </div>
                                    <div class="mobile-card-field">
                                        <span class="mobile-card-label">Referral Reason:</span>
                                        <?php echo htmlspecialchars($referral['referral_reason']); ?>
                                    </div>
                                    <div class="mobile-card-field">
                                        <span class="mobile-card-label">Destination:</span>
                                        <?php echo htmlspecialchars($destination); ?>
                                    </div>
                                    <div class="mobile-card-field">
                                        <span class="mobile-card-label">Date:</span>
                                        <?php echo date('M j, Y g:i A', strtotime($referral['referral_date'])); ?>
                                    </div>
                                    <div class="mobile-card-field">
                                        <span class="mobile-card-label">Issued By:</span>
                                        <?php echo htmlspecialchars($issuer_name); ?>
                                    </div>
                                    <div class="actions-group mt-075">
                                        <button type="button" class="btn btn-primary btn-sm" onclick="viewReferral(<?php echo $referral['referral_id']; ?>)">
                                            <i class="fas fa-eye"></i> View Details
                                        </button>
                                        <?php if ($referral['status'] === 'cancelled'): ?>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="action" value="reactivate">
                                                <input type="hidden" name="referral_id" value="<?php echo $referral['referral_id']; ?>">
                                                <button type="submit" class="btn btn-warning btn-sm" onclick="return confirm('Reactivate this referral?')">
                                                    <i class="fas fa-redo"></i> Reactivate
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Pagination Container -->
                <?php if ($total_records > 0): ?>
                    <div class="pagination-container">
                        <div class="pagination-info">
                            <div class="records-info">
                                Showing <strong><?php echo (($page - 1) * $per_page) + 1; ?></strong> to
                                <strong><?php echo min($page * $per_page, $total_records); ?></strong> of
                                <strong><?php echo $total_records; ?></strong> referrals
                            </div>

                            <div class="page-size-selector">
                                <label for="perPageSelect">Show:</label>
                                <select id="perPageSelect" onchange="changePageSize(this.value)">
                                    <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10</option>
                                    <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25</option>
                                    <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                                    <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                                </select>
                            </div>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <div class="pagination-controls">
                                <!-- Previous button -->
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"
                                        class="pagination-btn prev">
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </a>
                                <?php else: ?>
                                    <span class="pagination-btn prev disabled">
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </span>
                                <?php endif; ?>

                                <?php
                                // Calculate page numbers to show
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);

                                // Show first page if not in range
                                if ($start_page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>"
                                        class="pagination-btn page-num">1</a>
                                    <?php if ($start_page > 2): ?>
                                        <span class="pagination-ellipsis">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <!-- Page numbers -->
                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <?php if ($i == $page): ?>
                                        <span class="pagination-btn active page-num"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"
                                            class="pagination-btn page-num"><?php echo $i; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>

                                <!-- Show last page if not in range -->
                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <span class="pagination-ellipsis">...</span>
                                    <?php endif; ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>"
                                        class="pagination-btn page-num"><?php echo $total_pages; ?></a>
                                <?php endif; ?>

                                <!-- Next button -->
                                <?php if ($page < $total_pages): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>"
                                        class="pagination-btn next">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="pagination-btn next disabled">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>


    </section>

    <!-- Void Referral Modal -->
    <div id="voidModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Void Referral</h3>
                <button type="button" class="close" onclick="closeModal('voidModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="void">
                <input type="hidden" name="referral_id" id="void_referral_id">

                <div class="form-group">
                    <label for="void_reason">Reason for Voiding *</label>
                    <textarea id="void_reason" name="void_reason" rows="3" required
                        placeholder="Please explain why this referral is being voided..."
                        class="modal-textarea"></textarea>
                </div>

                <div class="modal-flex-end">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('voidModal')">Cancel</button>
                    <button type="submit" class="btn btn-danger">Void Referral</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Referral Modal -->
    <div id="viewReferralModal" class="referral-confirmation-modal">
        <div class="referral-modal-content">
            <div class="referral-modal-header">
                <button type="button" class="referral-modal-close" onclick="closeReferralModal()">&times;</button>
                <div class="icon">
                    <i class="fas fa-eye"></i>
                </div>
                <h3>Referral Details</h3>
                <p class="modal-description">Complete information about this referral</p>
            </div>

            <div class="referral-modal-body">
                <div id="referralDetailsContent">
                    <!-- Content will be loaded via JavaScript -->
                    <div class="modal-loading">
                        <i class="fas fa-spinner fa-spin fa-2x"></i>
                        <p>Loading referral details...</p>
                    </div>
                </div>
            </div>
            <div>
                <div class="alert alert-info" style="margin: 25px; border-left: 4px solid #2196f3;background-color: honeydew;">
                    <i class="fas fa-info-circle"></i> <strong>Referral Modification Policy:</strong>
                    To modify a referral, please <strong>cancel the existing referral</strong> and <strong>create a new one</strong> with the updated information.
                    This ensures accuracy and maintains a clear audit trail.
                </div>
            </div>

            <div class="referral-modal-actions">
                <!-- Cancel Button - Show for Active status -->
                <button type="button" class="modal-btn modal-btn-danger edit-btn-hidden" onclick="cancelReferral(currentReferralId)" id="cancelReferralBtn">
                    <i class="fas fa-times-circle"></i> Cancel Referral
                </button>

                <!-- Print Button - Always available -->
                <button type="button" class="modal-btn modal-btn-primary" onclick="printReferral(currentReferralId)" id="printReferralBtn">
                    <i class="fas fa-print"></i> Print
                </button>

                <!-- Download Button - Always available -->
                <button type="button" class="modal-btn modal-btn-success" onclick="downloadReferral(currentReferralId)" id="downloadReferralBtn">
                    <i class="fas fa-download"></i> Download PDF
                </button>
            </div>
        </div>
    </div>

    <!-- Cancel Referral Modal -->
    <div id="cancelReferralModal" class="modal">
        <div class="modal-content modal-content-large">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle text-danger"></i> Cancel Referral</h3>
                <button type="button" class="close" onclick="closeModal('cancelReferralModal')">&times;</button>
            </div>
            <form id="cancelReferralForm">
                <div class="alert cancel-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Warning:</strong> This action will cancel the referral and notify all involved parties. To modify the referral details, create a new referral instead.
                </div>

                <div class="form-group mb-1">
                    <label for="cancel_reason"><strong>Reason for Cancellation *</strong></label>
                    <textarea id="cancel_reason" name="cancel_reason" rows="4" required
                        placeholder="Please provide a detailed reason for cancelling this referral (minimum 10 characters)..."
                        class="form-textarea"></textarea>
                    <small class="form-help">This reason will be logged and visible in the referral history.</small>
                </div>

                <div class="form-group mb-15">
                    <label for="cancel_employee_password"><strong>Your Password *</strong></label>
                    <input type="password" id="cancel_employee_password" name="employee_password" required
                        placeholder="Enter your employee password to confirm cancellation"
                        class="form-input">
                    <small class="form-help">Password verification is required for security purposes.</small>
                </div>

                <div class="button-group">
                    <button type="button" class="btn btn-secondary btn-min-width" onclick="closeModal('cancelReferralModal')">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-danger btn-min-width-140">
                        <i class="fas fa-times-circle"></i> Cancel Referral
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Employee Password Verification Modal -->
    <div id="passwordVerificationModal" class="modal">
        <div class="modal-content modal-content-small">
            <div class="modal-header">
                <h3><i class="fas fa-lock"></i> Employee Verification</h3>
                <button type="button" class="close" onclick="closeModal('passwordVerificationModal')">&times;</button>
            </div>
            <form id="passwordVerificationForm">
                <div class="form-group">
                    <label for="employee_password">Enter your password to proceed:</label>
                    <input type="password" id="employee_password" name="employee_password" required
                        placeholder="Your employee password"
                        class="form-input">
                </div>
                <div class="modal-flex-end">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('passwordVerificationModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary">Verify & Proceed</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ===== REFERRAL MANAGEMENT JAVASCRIPT =====

        let currentReferralId = null;
        let currentAction = null;

        // Employee permission variables for JavaScript
        const currentEmployeeId = <?= $employee_id ?>;
        const currentEmployeeRole = '<?= strtolower($employee_role) ?>';
        const isAdmin = currentEmployeeRole === 'admin';

        // Modal Management Functions
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';

                // Add show class for referral confirmation modal
                if (modalId === 'viewReferralModal') {
                    modal.classList.add('show');
                }
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';

                // Remove show class for referral confirmation modal
                if (modalId === 'viewReferralModal') {
                    modal.classList.remove('show');
                }

                // Reset form if exists
                const form = modal.querySelector('form');
                if (form) form.reset();
            }
        }

        // Alias for referral modal close
        function closeReferralModal() {
            closeModal('viewReferralModal');
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal, .referral-confirmation-modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    const modalId = modal.id;
                    closeModal(modalId);
                }
            });
        }

        // View Referral Function with Enhanced Debugging
        async function viewReferral(referralId) {
            console.log('=== VIEW REFERRAL DEBUG START ===');
            console.log('Referral ID:', referralId);

            try {
                const response = await fetch(`../../api/get_referral_details.php?referral_id=${referralId}`);
                console.log('Response status:', response.status);
                console.log('Response headers:', Object.fromEntries(response.headers.entries()));

                const text = await response.text();
                console.log('Raw response:', text);

                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    console.error('JSON parse error:', parseError);
                    showNotification('Invalid response format from server', 'error');
                    return;
                }

                console.log('Parsed data:', data);

                if (data.success && data.referral) {
                    console.log('Success! Referral data received:', data.referral);
                    currentReferralId = referralId; // Set current referral ID for modal actions
                    populateReferralModal(data.referral);
                    openModal('viewReferralModal');
                } else {
                    console.error('API Error:', data.error || 'Unknown error');
                    showNotification(data.error || 'Failed to load referral details', 'error');
                }
            } catch (error) {
                console.error('Fetch error:', error);
                showNotification('Network error: ' + error.message, 'error');
            }

            console.log('=== VIEW REFERRAL DEBUG END ===');
        }

        // Populate Referral Modal
        function populateReferralModal(referral) {
            console.log('Populating modal with referral:', referral);

            const modalBody = document.getElementById('referralDetailsContent');
            if (!modalBody) {
                console.error('Modal body not found');
                return;
            }

            let vitalsSection = '';
            if (referral.vitals && Object.keys(referral.vitals).length > 0) {
                const vitals = referral.vitals;
                vitalsSection = `
                    <div class="summary-section">
                        <div class="summary-title">
                            <i class="fas fa-heartbeat"></i>
                            Vital Signs
                        </div>
                        <div class="vitals-summary">
                            ${vitals.blood_pressure ? `<div class="vital-item"><div class="vital-value">${vitals.blood_pressure}</div><div class="vital-label">Blood Pressure</div></div>` : ''}
                            ${vitals.temperature ? `<div class="vital-item"><div class="vital-value">${vitals.temperature}°C</div><div class="vital-label">Temperature</div></div>` : ''}
                            ${vitals.heart_rate ? `<div class="vital-item"><div class="vital-value">${vitals.heart_rate} bpm</div><div class="vital-label">Heart Rate</div></div>` : ''}
                            ${vitals.respiratory_rate ? `<div class="vital-item"><div class="vital-value">${vitals.respiratory_rate}/min</div><div class="vital-label">Respiratory Rate</div></div>` : ''}
                            ${vitals.weight ? `<div class="vital-item"><div class="vital-value">${vitals.weight} kg</div><div class="vital-label">Weight</div></div>` : ''}
                            ${vitals.height ? `<div class="vital-item"><div class="vital-value">${vitals.height} cm</div><div class="vital-label">Height</div></div>` : ''}
                        </div>
                    </div>
                `;
            }

            const modalContent = `
                <div class="referral-summary-card">
                    <div class="summary-section">
                        <div class="summary-title">
                            <i class="fas fa-user"></i>
                            Patient Information
                        </div>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="summary-label">Full Name</div>
                                <div class="summary-value highlight">${referral.patient_name || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Patient ID</div>
                                <div class="summary-value">${referral.patient_number || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Age</div>
                                <div class="summary-value">${referral.age || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Gender</div>
                                <div class="summary-value">${referral.gender || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Barangay</div>
                                <div class="summary-value">${referral.barangay || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Contact Number</div>
                                <div class="summary-value">${referral.contact_number || 'N/A'}</div>
                            </div>
                        </div>
                    </div>

                    <div class="summary-section">
                        <div class="summary-title">
                            <i class="fas fa-share"></i>
                            Referral Details
                        </div>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="summary-label">Referral Number</div>
                                <div class="summary-value highlight">${referral.referral_num || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Status</div>
                                <div class="summary-value">${referral.status || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Referred To</div>
                                <div class="summary-value">${referral.facility_name || referral.external_facility_name || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Date Issued</div>
                                <div class="summary-value">${formatDate(referral.referral_date) || 'N/A'}</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Issued By</div>
                                <div class="summary-value">${referral.issuer_name || 'N/A'}</div>
                            </div>
                        </div>
                        <div class="summary-item" style="margin-top: 1rem;">
                            <div class="summary-label">Reason for Referral</div>
                            <div class="summary-value reason">${referral.referral_reason || 'N/A'}</div>
                        </div>
                    </div>

                    ${vitalsSection}
                </div>
            `;

            modalBody.innerHTML = modalContent;

            // Update button visibility based on status and creator
            updateModalButtons(referral.status, referral.referred_by);
        }

        // Update Modal Button Visibility with Creator-Based Permissions
        function updateModalButtons(status, referredBy = null) {
            const cancelBtn = document.getElementById('cancelReferralBtn');

            // Hide cancel button first
            if (cancelBtn) cancelBtn.style.display = 'none';

            // Check if current employee can modify this referral
            const canModify = isAdmin || (referredBy && referredBy == currentEmployeeId);

            if (!canModify) {
                // Show info message about permissions
                const buttonContainer = document.querySelector('.referral-modal-actions');
                if (buttonContainer) {
                    let permissionInfo = buttonContainer.querySelector('.permission-info');
                    if (!permissionInfo) {
                        permissionInfo = document.createElement('div');
                        permissionInfo.className = 'permission-info';
                        permissionInfo.style.cssText = 'margin: 1rem 0; padding: 0.75rem; background: #e3f2fd; border-left: 4px solid #2196f3; border-radius: 4px; font-size: 0.9rem; color: #1976d2;';
                        buttonContainer.appendChild(permissionInfo);
                    }

                    if (isAdmin) {
                        permissionInfo.innerHTML = '<i class="fas fa-crown"></i> <strong>Admin Access:</strong> You can cancel any referral.';
                    } else if (referredBy == currentEmployeeId) {
                        permissionInfo.innerHTML = '<i class="fas fa-user-check"></i> <strong>Creator Access:</strong> You can cancel this referral because you created it.';
                    } else {
                        permissionInfo.innerHTML = '<i class="fas fa-info-circle"></i> <strong>View Only:</strong> You can only cancel referrals you created. <br><small><strong>Note:</strong> To modify this referral, cancel it and create a new one with the updated information.</small>';
                    }
                }
                return; // Don't show action buttons
            }

            // Show buttons based on status (only if user has modify permissions)
            if (status === 'active') {
                if (cancelBtn) cancelBtn.style.display = 'inline-flex';
            }
            // Note: Cancelled/voided referrals can no longer be reinstated. 
            // Create a new referral if needed.
        }

        // Download Referral Function - Simple PDF Preview Popup
        function downloadReferral(referralId) {
            console.log('Download referral:', referralId);

            if (!referralId) {
                showNotification('Invalid referral ID', 'error');
                return;
            }

            // Open PDF in popup window for preview and download
            const pdfUrl = `../../api/generate_referral_pdf.php?referral_id=${referralId}&display=inline`;
            const popup = window.open(
                pdfUrl,
                'referralDownload',
                'width=800,height=600,scrollbars=yes,resizable=yes'
            );

            if (popup) {
                popup.focus();
                showNotification('PDF opened in popup. Use browser controls to download.', 'success');
            } else {
                showNotification('Please allow popups to view PDF', 'error');
            }
        }

        // Print Referral Function - Simple PDF Preview Popup
        function printReferral(referralId) {
            console.log('Print referral:', referralId);

            if (!referralId) {
                showNotification('Invalid referral ID', 'error');
                return;
            }

            // Open PDF in popup window for preview and printing
            const pdfUrl = `../../api/generate_referral_pdf.php?referral_id=${referralId}&display=inline`;
            const popup = window.open(
                pdfUrl,
                'referralPrint',
                'width=800,height=600,scrollbars=yes,resizable=yes'
            );

            if (popup) {
                popup.focus();
                showNotification('PDF opened in popup. Use Ctrl+P to print.', 'success');
            } else {
                showNotification('Please allow popups to view PDF', 'error');
            }
        }

        // Action Functions
        function voidReferral(referralId) {
            currentReferralId = referralId;
            document.getElementById('void_referral_id').value = referralId;
            openModal('voidModal');
        }

        function cancelReferral(referralId) {
            currentReferralId = referralId;
            openModal('cancelReferralModal');
        }

        // Utility Functions
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            return date.toLocaleString('en-PH', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // Custom Notification System (No Alerts)
        function showNotification(message, type = 'info') {
            // Remove existing notifications
            const existingNotifications = document.querySelectorAll('.notification-snackbar');
            existingNotifications.forEach(notification => notification.remove());

            const notification = document.createElement('div');
            notification.className = `notification-snackbar notification-${type}`;

            const icon = type === 'success' ? 'fas fa-check-circle' :
                type === 'error' ? 'fas fa-exclamation-circle' :
                type === 'warning' ? 'fas fa-exclamation-triangle' : 'fas fa-info-circle';

            notification.innerHTML = `
                <i class="${icon}"></i>
                <span>${message}</span>
                <button type="button" class="notification-close" onclick="this.parentElement.remove();">
                    <i class="fas fa-times"></i>
                </button>
            `;

            // Style the notification
            Object.assign(notification.style, {
                position: 'fixed',
                top: '20px',
                right: '20px',
                padding: '1rem 1.5rem',
                borderRadius: '8px',
                color: 'white',
                fontWeight: '500',
                zIndex: '9999',
                display: 'flex',
                alignItems: 'center',
                gap: '0.75rem',
                minWidth: '300px',
                maxWidth: '500px',
                boxShadow: '0 4px 12px rgba(0, 0, 0, 0.2)',
                animation: 'slideInRight 0.3s ease'
            });

            // Set background color based on type
            const colors = {
                success: '#28a745',
                error: '#dc3545',
                warning: '#ffc107',
                info: '#17a2b8'
            };

            notification.style.background = colors[type] || colors.info;

            document.body.appendChild(notification);

            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.style.animation = 'slideOutRight 0.3s ease';
                    setTimeout(() => notification.remove(), 300);
                }
            }, 5000);
        }

        // Form Submission Handlers
        document.addEventListener('DOMContentLoaded', function() {
            // Cancel referral form handler
            const cancelForm = document.getElementById('cancelReferralForm');
            if (cancelForm) {
                cancelForm.addEventListener('submit', async function(e) {
                    e.preventDefault();

                    const reason = document.getElementById('cancel_reason').value.trim();
                    const password = document.getElementById('cancel_employee_password').value;

                    if (reason.length < 10) {
                        showNotification('Cancellation reason must be at least 10 characters long', 'error');
                        return;
                    }

                    if (!password) {
                        showNotification('Password is required for verification', 'error');
                        return;
                    }

                    const formData = new FormData();
                    formData.append('action', 'cancel');
                    formData.append('referral_id', currentReferralId);
                    formData.append('cancel_reason', reason);
                    formData.append('employee_password', password);

                    try {
                        const response = await fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        });

                        if (response.ok) {
                            window.location.reload();
                        } else {
                            showNotification('Failed to cancel referral', 'error');
                        }
                    } catch (error) {
                        showNotification('Network error: ' + error.message, 'error');
                    }
                });
            }
        });

        // Pagination handling
        function changePageSize(perPage) {
            const url = new URL(window.location);
            url.searchParams.set('per_page', perPage);
            url.searchParams.set('page', '1'); // Reset to first page
            window.location.href = url.toString();
        }
    </script>
</body>

</html>