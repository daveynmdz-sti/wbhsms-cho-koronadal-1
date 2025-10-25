<?php
// referrals_management.php - Admin Side
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration - Use absolute path resolution
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';

// If user is not logged in, bounce to login
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    header('Location: ../management/auth/employee_login.php');
    exit();
}

// Check if role is authorized
$authorized_roles = ['doctor', 'nurse', 'bhw', 'dho', 'records_officer', 'admin'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    header('Location: ../management/' . strtolower($_SESSION['role']) . '/dashboard.php');
    exit();
}

// Database connection
require_once $root_path . '/config/db.php';

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed. Please contact administrator.");
}

$employee_id = $_SESSION['employee_id'];
$employee_role = $_SESSION['role'];

// Define role-based permissions for referrals management
$canCreateReferrals = true; // All authorized roles can create referrals
$canEditReferrals = !in_array(strtolower($employee_role), ['records_officer']); // Records officers cannot edit existing referrals
$canViewReferrals = true; // All authorized roles can view

// DHO and BHW Access Control - Get jurisdiction restrictions
$jurisdiction_restriction = '';
$jurisdiction_params = [];
$jurisdiction_param_types = '';

if (strtolower($employee_role) === 'dho') {
    try {
        // Get DHO's district_id from their facility assignment
        $dho_district_sql = "SELECT f.district_id 
                             FROM employees e 
                             JOIN facilities f ON e.facility_id = f.facility_id 
                             WHERE e.employee_id = ? AND e.role_id = 5";
        $dho_district_stmt = $conn->prepare($dho_district_sql);
        $dho_district_stmt->bind_param("i", $employee_id);
        $dho_district_stmt->execute();
        $dho_district_result = $dho_district_stmt->get_result();

        if ($dho_district_result->num_rows === 0) {
            die('Error: Access denied - No facility assignment found for DHO.');
        }

        $dho_district = $dho_district_result->fetch_assoc()['district_id'];
        $dho_district_stmt->close();

        // Add district restriction to queries
        $jurisdiction_restriction = " AND b.district_id = ?";
        $jurisdiction_params[] = $dho_district;
        $jurisdiction_param_types .= 'i';

        error_log("DHO referrals access: Employee ID $employee_id restricted to district $dho_district");

    } catch (Exception $e) {
        error_log("DHO referrals access control error: " . $e->getMessage());
        die('Error: Access validation failed.');
    }
} elseif (strtolower($employee_role) === 'bhw') {
    try {
        // Get BHW's barangay_id from their facility assignment
        $bhw_barangay_sql = "SELECT f.barangay_id 
                             FROM employees e 
                             JOIN facilities f ON e.facility_id = f.facility_id 
                             WHERE e.employee_id = ? AND e.role_id = 6";
        $bhw_barangay_stmt = $conn->prepare($bhw_barangay_sql);
        $bhw_barangay_stmt->bind_param("i", $employee_id);
        $bhw_barangay_stmt->execute();
        $bhw_barangay_result = $bhw_barangay_stmt->get_result();

        if ($bhw_barangay_result->num_rows === 0) {
            die('Error: Access denied - No facility assignment found for BHW.');
        }

        $bhw_barangay = $bhw_barangay_result->fetch_assoc()['barangay_id'];
        $bhw_barangay_stmt->close();

        // Add barangay restriction to queries
        $jurisdiction_restriction = " AND p.barangay_id = ?";
        $jurisdiction_params[] = $bhw_barangay;
        $jurisdiction_param_types .= 'i';

        error_log("BHW referrals access: Employee ID $employee_id restricted to barangay $bhw_barangay");

    } catch (Exception $e) {
        error_log("BHW referrals access control error: " . $e->getMessage());
        die('Error: Access validation failed.');
    }
}

// Handle status updates and actions
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $referral_id = $_POST['referral_id'] ?? '';

    if (!empty($referral_id) && is_numeric($referral_id)) {
        try {
            switch ($action) {
                case 'complete':
                    $stmt = $conn->prepare("UPDATE referrals SET status = 'completed' WHERE referral_id = ?");
                    $stmt->bind_param("i", $referral_id);
                    $stmt->execute();
                    $message = "Referral marked as completed successfully.";
                    $stmt->close();
                    break;

                case 'void':
                    $void_reason = trim($_POST['void_reason'] ?? '');
                    if (empty($void_reason)) {
                        $error = "Void reason is required.";
                    } else {
                        $stmt = $conn->prepare("UPDATE referrals SET status = 'voided' WHERE referral_id = ?");
                        $stmt->bind_param("i", $referral_id);
                        $stmt->execute();
                        $message = "Referral voided successfully.";
                        $stmt->close();
                    }
                    break;

                case 'reactivate':
                    $stmt = $conn->prepare("UPDATE referrals SET status = 'active' WHERE referral_id = ?");
                    $stmt->bind_param("i", $referral_id);
                    $stmt->execute();
                    $message = "Referral reactivated successfully.";
                    $stmt->close();
                    break;
            }
        } catch (Exception $e) {
            $error = "Failed to update referral: " . $e->getMessage();
        }
    }
}

// Fetch referrals with patient information
$patient_id = $_GET['patient_id'] ?? '';
$first_name = $_GET['first_name'] ?? '';
$last_name = $_GET['last_name'] ?? '';
$barangay = $_GET['barangay'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Pagination parameters
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = in_array(intval($_GET['per_page'] ?? 25), [10, 25, 50, 100]) ? intval($_GET['per_page'] ?? 25) : 25;
$offset = ($page - 1) * $per_page;

$where_conditions = [];
$params = [];
$param_types = '';

if (!empty($patient_id)) {
    $where_conditions[] = "p.username LIKE ?";
    $patient_id_term = "%$patient_id%";
    $params[] = $patient_id_term;
    $param_types .= 's';
}

if (!empty($first_name)) {
    $where_conditions[] = "p.first_name LIKE ?";
    $first_name_term = "%$first_name%";
    $params[] = $first_name_term;
    $param_types .= 's';
}

if (!empty($last_name)) {
    $where_conditions[] = "p.last_name LIKE ?";
    $last_name_term = "%$last_name%";
    $params[] = $last_name_term;
    $param_types .= 's';
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
        $where_clause
        $jurisdiction_restriction
    ";

    $count_stmt = $conn->prepare($count_sql);
    
    // Combine all parameters for count query
    $count_params = array_merge($params, $jurisdiction_params);
    $count_param_types = $param_types . $jurisdiction_param_types;
    
    if (!empty($count_params)) {
        $count_stmt->bind_param($count_param_types, ...$count_params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_records = $count_result->fetch_assoc()['total'];
    $count_stmt->close();

    $total_pages = ceil($total_records / $per_page);

    $sql = "
        SELECT r.referral_id, r.referral_num, r.patient_id, r.referral_reason, r.destination_type, 
               r.referred_to_facility_id, r.external_facility_name, r.referral_date, r.status,
               p.first_name, p.middle_name, p.last_name, p.username as patient_number, 
               b.barangay_name as barangay,
               e.first_name as issuer_first_name, e.last_name as issuer_last_name,
               f.name as referred_facility_name
        FROM referrals r
        LEFT JOIN patients p ON r.patient_id = p.patient_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN employees e ON r.referred_by = e.employee_id
        LEFT JOIN facilities f ON r.referred_to_facility_id = f.facility_id
        $where_clause
        $jurisdiction_restriction
        ORDER BY r.referral_date DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);

    // Combine all parameters for main query
    $all_params = array_merge($params, $jurisdiction_params);
    $all_param_types = $param_types . $jurisdiction_param_types;

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
    'completed' => 0,
    'pending' => 0,
    'voided' => 0
];

try {
    // Statistics query with jurisdiction restrictions
    $stats_sql = "
        SELECT r.status, COUNT(*) as count 
        FROM referrals r
        LEFT JOIN patients p ON r.patient_id = p.patient_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE 1=1
        $jurisdiction_restriction
        GROUP BY r.status
    ";
    
    $stmt = $conn->prepare($stats_sql);
    if (!empty($jurisdiction_params)) {
        $stmt->bind_param($jurisdiction_param_types, ...$jurisdiction_params);
    }
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

        .stat-card.pending {
            border-left: 4px solid #f093fb;
        }

        .stat-card.voided {
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

        #cancelReferralModal,
        #reinstateReferralModal {
            z-index: 11000;
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
                <div class="stat-number"><?php echo number_format($stats['completed'] ?? 0); ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card canceled">
                <div class="stat-number"><?php echo number_format($stats['canceled'] ?? 0); ?></div>
                <div class="stat-label">Canceled</div>
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
                    <label for="patient_id">Patient ID</label>
                    <input type="text" id="patient_id" name="patient_id" value="<?php echo htmlspecialchars($patient_id); ?>"
                        placeholder="Enter patient ID...">
                </div>
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($first_name); ?>"
                        placeholder="Enter first name...">
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($last_name); ?>"
                        placeholder="Enter last name...">
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
                        <a href="create_referrals.php" class="btn btn-primary">Create First Referral</a>
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

            <div class="referral-modal-actions">
                <?php if ($canEditReferrals): ?>
                <!-- Edit Button - Show for Active status -->
                <button type="button" class="modal-btn modal-btn-warning edit-btn-hidden" onclick="editReferral()" id="editReferralBtn">
                    <i class="fas fa-edit"></i> Edit
                </button>

                <!-- Cancel Button - Show for Active status -->
                <button type="button" class="modal-btn modal-btn-danger edit-btn-hidden" onclick="cancelReferral(currentReferralId)" id="cancelReferralBtn">
                    <i class="fas fa-times-circle"></i> Cancel Referral
                </button>

                <!-- Reinstate Button - Show for Cancelled/Expired status -->
                <button type="button" class="modal-btn modal-btn-success edit-btn-hidden" onclick="reinstateReferral(currentReferralId)" id="reinstateReferralBtn">
                    <i class="fas fa-redo"></i> Reinstate
                </button>
                <?php endif; ?>

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
                    <strong>Warning:</strong> This action will cancel the referral and notify all involved parties. This action can be undone later using the "Reinstate" option.
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

    <!-- Reinstate Referral Confirmation Modal -->
    <div id="reinstateReferralModal" class="modal">
        <div class="modal-content modal-content-large text-left">
            <div class="modal-header">
                <h3><i class="fas fa-undo-alt" style="color: #28a745;"></i> Reinstate Referral</h3>
                <button type="button" class="close" onclick="closeModal('reinstateReferralModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="confirmation-section">
                    <h4 class="confirmation-title">
                        <i class="fas fa-info-circle"></i> Confirmation Required
                    </h4>
                    <p class="confirmation-text">Are you sure you want to reinstate this referral?</p>
                </div>

                <div class="action-list-section">
                    <h5 class="action-list-title">
                        <i class="fas fa-list-ul"></i> This action will:
                    </h5>
                    <ul class="action-list">
                        <li>Reactivate the referral status to <strong>"Active"</strong></li>
                        <li>Make it available for processing again</li>
                        <li>Log the reinstatement action for audit purposes</li>
                        <li>Send notification to relevant healthcare providers</li>
                    </ul>
                </div>

                <div class="warning-section">
                    <p class="warning-text">
                        <i class="fas fa-exclamation-triangle warning-icon"></i>
                        <strong>Note:</strong> Once reinstated, this referral will become active and ready for patient processing.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('reinstateReferralModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="btn btn-success" onclick="confirmReinstatement()" id="confirmReinstateBtn">
                    <i class="fas fa-undo-alt"></i> Yes, Reinstate Referral
                </button>
            </div>
        </div>
    </div>

    <script>
        // ===== REFERRAL MANAGEMENT JAVASCRIPT =====

        let currentReferralId = null;
        let currentAction = null;

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

            // Update button visibility based on status
            updateModalButtons(referral.status);
        }

        // Update Modal Button Visibility
        function updateModalButtons(status) {
            const editBtn = document.getElementById('editReferralBtn');
            const cancelBtn = document.getElementById('cancelReferralBtn');
            const reinstateBtn = document.getElementById('reinstateReferralBtn');

            // Hide all buttons first
            if (editBtn) editBtn.style.display = 'none';
            if (cancelBtn) cancelBtn.style.display = 'none';
            if (reinstateBtn) reinstateBtn.style.display = 'none';

            // Show buttons based on status
            if (status === 'active') {
                if (editBtn) editBtn.style.display = 'inline-flex';
                if (cancelBtn) cancelBtn.style.display = 'inline-flex';
            } else if (status === 'cancelled' || status === 'voided') {
                if (reinstateBtn) reinstateBtn.style.display = 'inline-flex';
            }
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

        function reinstateReferral(referralId) {
            currentReferralId = referralId;
            openModal('reinstateReferralModal');
        }

        function confirmReinstatement() {
            if (currentReferralId) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="reactivate">
                    <input type="hidden" name="referral_id" value="${currentReferralId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function editReferral() {
            if (currentReferralId) {
                // Navigate to edit page
                window.location.href = `edit_referral.php?id=${currentReferralId}`;
            }
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