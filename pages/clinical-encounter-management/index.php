<?php
// index.php - Clinical Encounter Management Dashboard

// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed. Please contact administrator.");
}

// If user is not logged in, bounce to login
if (!isset($_SESSION['employee_id']) || !isset($_SESSION['role'])) {
    header('Location: ../auth/employee_login.php');
    exit();
}

// Check if role is authorized for clinical encounters
$authorized_roles = ['doctor', 'nurse', 'admin', 'records_officer', 'bhw', 'dho', 'pharmacist'];
if (!in_array(strtolower($_SESSION['role']), $authorized_roles)) {
    header('Location: ../dashboard.php');
    exit();
}

$employee_id = $_SESSION['employee_id'];
$employee_role = strtolower($_SESSION['role']);

// Get employee details for role-based filtering
$employee_details = null;
try {
    $stmt = $conn->prepare("
        SELECT e.*, r.role_name 
        FROM employees e 
        JOIN roles r ON e.role_id = r.role_id 
        WHERE e.employee_id = ?
    ");
    if ($stmt) {
        $stmt->bind_param('i', $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $employee_details = $result->fetch_assoc();
    }

    // Update session role if needed
    if ($employee_details && isset($employee_details['role_name'])) {
        $_SESSION['role'] = $employee_details['role_name'];
        $employee_role = strtolower($employee_details['role_name']);
    }
} catch (Exception $e) {
    // Continue without employee details
}

// Include sidebar component
$activePage = 'clinical_encounters';

// Pagination and filtering with input validation
$records_per_page = 15;
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, array("options" => array("min_range" => 1, "default" => 1))) ?? 1;
$offset = ($page - 1) * $records_per_page;

// Search and filter parameters with sanitization
$patient_id_filter = htmlspecialchars(trim($_GET['patient_id'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$first_name_filter = htmlspecialchars(trim($_GET['first_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$last_name_filter = htmlspecialchars(trim($_GET['last_name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$status_filter = in_array($_GET['status'] ?? '', ['pending', 'completed', 'cancelled', 'follow_up_required']) ? $_GET['status'] : '';
$date_from = htmlspecialchars(trim($_GET['date_from'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$date_to = htmlspecialchars(trim($_GET['date_to'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$doctor_filter = filter_input(INPUT_GET, 'doctor', FILTER_VALIDATE_INT);

// Validate date formats if provided
if ($date_from && !DateTime::createFromFormat('Y-m-d', $date_from)) {
    $date_from = '';
}
if ($date_to && !DateTime::createFromFormat('Y-m-d', $date_to)) {
    $date_to = '';
}

// Limit search string lengths
$patient_id_filter = substr($patient_id_filter, 0, 50);
$first_name_filter = substr($first_name_filter, 0, 50);
$last_name_filter = substr($last_name_filter, 0, 50);
$barangay_filter = $_GET['barangay'] ?? '';
$district_filter = $_GET['district'] ?? '';

// Build WHERE conditions with role-based access control
$where_conditions = ['1=1'];
$params = [];
$param_types = '';

// Role-based filtering with proper access control
switch ($employee_role) {
    case 'doctor':
    case 'nurse':
        // Doctor/Nurse: Show consultations assigned to them or where they were involved
        if (empty($patient_id_filter) && empty($first_name_filter) && empty($last_name_filter) && empty($doctor_filter)) {
            $where_conditions[] = "(c.consulted_by = ? OR c.attending_employee_id = ? OR EXISTS (
                SELECT 1 FROM vitals vt WHERE vt.vitals_id = c.vitals_id AND vt.recorded_by = ?
            ))";
            $params[] = $employee_id;
            $params[] = $employee_id;
            $params[] = $employee_id;
            $param_types .= 'iii';
        }
        break;

    case 'bhw':
        // BHW: Limited to patients from their assigned barangay
        if ($employee_details && isset($employee_details['assigned_barangay_id'])) {
            $where_conditions[] = "p.barangay_id = ?";
            $params[] = $employee_details['assigned_barangay_id'];
            $param_types .= 'i';
        } else {
            // If no assigned barangay, show no records
            $where_conditions[] = "1=0";
        }
        break;

    case 'dho':
        // DHO: Limited to patients from their assigned district
        if ($employee_details && isset($employee_details['assigned_district_id'])) {
            $where_conditions[] = "b.district_id = ?";
            $params[] = $employee_details['assigned_district_id'];
            $param_types .= 'i';
        } else {
            // If no assigned district, show no records
            $where_conditions[] = "1=0";
        }
        break;

    case 'admin':
        // Admin: Full access to all consultations (no additional filter)
        break;

    case 'records_officer':
        // Records Officer: Read-only access to all consultations (no additional filter)
        break;

    default:
        // Unknown role: No access
        $where_conditions[] = "1=0";
        break;
}

// Apply individual search filters
if (!empty($patient_id_filter)) {
    $where_conditions[] = "p.username LIKE ?";
    $params[] = "%$patient_id_filter%";
    $param_types .= 's';
}

if (!empty($first_name_filter)) {
    $where_conditions[] = "p.first_name LIKE ?";
    $params[] = "%$first_name_filter%";
    $param_types .= 's';
}

if (!empty($last_name_filter)) {
    $where_conditions[] = "p.last_name LIKE ?";
    $params[] = "%$last_name_filter%";
    $param_types .= 's';
}

if (!empty($status_filter)) {
    $where_conditions[] = "c.consultation_status = ?";
    $params[] = $status_filter;
    $param_types .= 's';
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(c.consultation_date) >= ?";
    $params[] = $date_from;
    $param_types .= 's';
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(c.consultation_date) <= ?";
    $params[] = $date_to;
    $param_types .= 's';
}

if (!empty($doctor_filter)) {
    $where_conditions[] = "c.consulted_by = ?";
    $params[] = $doctor_filter;
    $param_types .= 'i';
}

if (!empty($barangay_filter)) {
    $where_conditions[] = "p.barangay_id = ?";
    $params[] = $barangay_filter;
    $param_types .= 'i';
}

if (!empty($district_filter)) {
    $where_conditions[] = "b.district_id = ?";
    $params[] = $district_filter;
    $param_types .= 'i';
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count for pagination - Updated for standalone consultations
$count_sql = "
    SELECT COUNT(*) as total
    FROM consultations c
    JOIN patients p ON c.patient_id = p.patient_id
    LEFT JOIN vitals v ON c.vitals_id = v.vitals_id
    LEFT JOIN employees d ON c.consulted_by = d.employee_id
    LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
    LEFT JOIN districts dist ON b.district_id = dist.district_id
    LEFT JOIN services s ON c.service_id = s.service_id
    WHERE $where_clause
";

$total_records = 0;
if (!empty($params)) {
    $stmt = $conn->prepare($count_sql);
    if ($stmt) {
        $stmt->bind_param($param_types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $total_records = $row ? $row['total'] : 0;
    }
} else {
    $result = $conn->query($count_sql);
    if ($result) {
        $row = $result->fetch_assoc();
        $total_records = $row ? $row['total'] : 0;
    }
}

$total_pages = ceil($total_records / $records_per_page);

// Get clinical encounters with pagination - Updated for standalone consultations
$sql = "
    SELECT c.consultation_id as encounter_id, c.patient_id, c.vitals_id, c.chief_complaint, 
           c.diagnosis, c.consultation_status as status, 
           c.consultation_date, c.created_at, c.updated_at,
           p.first_name, p.last_name, p.username as patient_id_display,
           TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age, p.sex,
           d.first_name as doctor_first_name, d.last_name as doctor_last_name,
           b.barangay_name, dist.district_name,
           s.name as service_name, s.service_id,
           'consultation' as visit_type, 'clinical_consultation' as visit_purpose,
           (SELECT COUNT(*) FROM prescriptions WHERE consultation_id = c.consultation_id) as prescription_count,
           (SELECT COUNT(*) FROM lab_orders WHERE consultation_id = c.consultation_id) as lab_test_count,
           (SELECT COUNT(*) FROM referrals WHERE consultation_id = c.consultation_id) as referral_count,
           -- Get vitals information if linked
           CONCAT(v.systolic_bp, '/', v.diastolic_bp) as blood_pressure, 
           v.heart_rate, v.temperature, v.weight, v.height
    FROM consultations c
    JOIN patients p ON c.patient_id = p.patient_id
    LEFT JOIN vitals v ON c.vitals_id = v.vitals_id
    LEFT JOIN employees d ON c.consulted_by = d.employee_id
    LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
    LEFT JOIN districts dist ON b.district_id = dist.district_id
    LEFT JOIN services s ON c.service_id = s.service_id
    WHERE $where_clause
    ORDER BY c.consultation_date DESC, c.created_at DESC
    LIMIT ? OFFSET ?
";

$encounters = [];
$limit_params = $params;
$limit_params[] = $records_per_page;
$limit_params[] = $offset;
$limit_param_types = $param_types . 'ii';

try {
    if (!empty($limit_params)) {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($limit_param_types, ...$limit_params);
            $stmt->execute();
            $result = $stmt->get_result();
            $encounters = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            $encounters = [];
        }
    } else {
        $result = $conn->query($sql);
        if ($result) {
            $encounters = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            $encounters = [];
        }
    }
} catch (Exception $e) {
    $encounters = [];
    // You can log the error here if needed
}

// Get available doctors for filter
$doctors = [];
try {
    $stmt = $conn->prepare("
        SELECT e.employee_id, e.first_name, e.last_name 
        FROM employees e 
        JOIN roles r ON e.role_id = r.role_id 
        WHERE r.role_name = 'doctor' AND e.status = 'active' 
        ORDER BY e.first_name, e.last_name
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $doctors = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    // Ignore errors for doctors
}

// Get available barangays for filter
$barangays = [];
try {
    $stmt = $conn->prepare("SELECT barangay_id, barangay_name FROM barangay ORDER BY barangay_name");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $barangays = $result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    // Ignore errors for barangays
}

// Get available districts for filter
$districts = [];
try {
    $stmt = $conn->prepare("SELECT district_id, district_name FROM districts ORDER BY district_name");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        $districts = $result->fetch_all(MYSQLI_ASSOC);
    }
} catch (Exception $e) {
    // Ignore errors for districts
}

// Get encounter statistics with role-based filtering
$stats = [
    'total_encounters' => 0,
    'completed_today' => 0,
    'follow_ups_needed' => 0,
    'referred_cases' => 0
];

// Build role-based stats filter
$stats_where = ['consultation_status != \'cancelled\''];
$stats_params = [];
$stats_param_types = '';

switch ($employee_role) {
    case 'doctor':
    case 'nurse':
        $stats_where[] = "c.consulted_by = ?";
        $stats_params[] = $employee_id;
        $stats_param_types .= 'i';
        break;
    case 'bhw':
    case 'dho':
        // Note: BHW/DHO filtering would need employee-barangay/district assignment table
        // For now, they see all statistics (like admin)
        break;
}

$stats_where_clause = implode(' AND ', $stats_where);

try {
    // Total consultations
    $sql = "SELECT COUNT(*) as total FROM consultations c 
            JOIN patients p ON c.patient_id = p.patient_id 
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id 
            WHERE $stats_where_clause";
    if (!empty($stats_params)) {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($stats_param_types, ...$stats_params);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stats['total_encounters'] = $row ? $row['total'] : 0;
        }
    } else {
        $result = $conn->query($sql);
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['total_encounters'] = $row ? $row['total'] : 0;
        }
    }

    // Completed today
    $completed_where = $stats_where;
    $completed_where[] = "consultation_status = 'completed'";
    $completed_where[] = "DATE(c.updated_at) = CURDATE()";
    $completed_where_clause = implode(' AND ', $completed_where);

    $sql = "SELECT COUNT(*) as total FROM consultations c 
            JOIN patients p ON c.patient_id = p.patient_id 
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id 
            WHERE $completed_where_clause";
    if (!empty($stats_params)) {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($stats_param_types, ...$stats_params);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stats['completed_today'] = $row ? $row['total'] : 0;
        }
    } else {
        $result = $conn->query($sql);
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['completed_today'] = $row ? $row['total'] : 0;
        }
    }

    // Follow-ups needed
    $followup_where = $stats_where;
    $followup_where[] = "consultation_status = 'awaiting_followup'";
    $followup_where_clause = implode(' AND ', $followup_where);

    $sql = "SELECT COUNT(*) as total FROM consultations c 
            JOIN patients p ON c.patient_id = p.patient_id 
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id 
            WHERE $followup_where_clause";
    if (!empty($stats_params)) {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($stats_param_types, ...$stats_params);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stats['follow_ups_needed'] = $row ? $row['total'] : 0;
        }
    } else {
        $result = $conn->query($sql);
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['follow_ups_needed'] = $row ? $row['total'] : 0;
        }
    }

    // Referred cases
    $sql = "SELECT COUNT(DISTINCT c.consultation_id) as total FROM consultations c 
            JOIN patients p ON c.patient_id = p.patient_id 
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            JOIN referrals r ON c.consultation_id = r.consultation_id 
            WHERE $stats_where_clause";
    if (!empty($stats_params)) {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($stats_param_types, ...$stats_params);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stats['referred_cases'] = $row ? $row['total'] : 0;
        }
    } else {
        $result = $conn->query($sql);
        if ($result) {
            $row = $result->fetch_assoc();
            $stats['referred_cases'] = $row ? $row['total'] : 0;
        }
    }
} catch (Exception $e) {
    // Keep default values
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <title>Clinical Encounter Management | CHO Koronadal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="../../assets/css/sidebar.css" />
    <link rel="stylesheet" href="../../assets/css/dashboard.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        /* Layout Styles */
        .content-wrapper {
            margin-left: 300px;
            padding: 2rem;
            transition: margin-left 0.3s;
        }

        /* Page Header */
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

        .header-actions {
            display: flex;
            gap: 1rem;
        }

        /* Breadcrumb Styles */
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
            border-left: 4px solid #0077b6;
            overflow: hidden;
        }

        .table-wrapper {
            overflow-x: auto;
            max-height: 70vh;
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

        .table tbody tr:hover {
            background-color: rgba(240, 247, 255, 0.6);
        }

        .card-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
            overflow: hidden;
            margin-bottom: 2rem;
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

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }

            .table-wrapper {
                font-size: 14px;
            }

            .table th,
            .table td {
                padding: 8px 10px;
            }

            .table th {
                font-size: 12px;
            }
        }

        /* Alert Styles */
        .alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            font-size: 0.95rem;
            border-left: 4px solid;
            transition: all 0.3s ease;
            position: relative;
        }

        .alert-warning {
            background: #fff8e1;
            color: #f57c00;
            border-left-color: #ff9800;
        }

        .alert-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.7;
            color: inherit;
            padding: 0;
            margin-left: auto;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .alert-close:hover {
            opacity: 1;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
        }

        .stat-card.total {
            border-left-color: #0077b6;
        }

        .stat-card.completed {
            border-left-color: #28a745;
        }

        .stat-card.follow-up {
            border-left-color: #dc3545;
        }

        .stat-card.referred {
            border-left-color: #6f42c1;
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .stat-icon {
            font-size: 2rem;
            opacity: 0.8;
        }

        .stat-card.total .stat-icon {
            color: #0077b6;
        }

        .stat-card.completed .stat-icon {
            color: #28a745;
        }

        .stat-card.follow-up .stat-icon {
            color: #dc3545;
        }

        .stat-card.referred .stat-icon {
            color: #6f42c1;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #6c757d;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.85rem;
        }

        .filters-container {
            background: white;
            border-radius: 12px;
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

        .encounter-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .table-header {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-new-encounter {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.75rem 1.5rem;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-new-encounter:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.5);
            transform: translateY(-1px);
        }

        .table-responsive {
            overflow-x: auto;
        }

        .encounters-table {
            width: 100%;
            border-collapse: collapse;
        }

        .encounters-table th,
        .encounters-table td {
            padding: 1rem 1.5rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .encounters-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .encounters-table tbody tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-ongoing {
            background: #fff3cd;
            color: #856404;
        }

        .status-awaiting_lab_results {
            background: #e2e3e5;
            color: #383d41;
        }

        .status-awaiting_followup {
            background: #f8d7da;
            color: #721c24;
        }

        .status-cancelled {
            background: #d1ecf1;
            color: #0c5460;
        }

        .patient-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .patient-name {
            font-weight: 600;
            color: #0077b6;
        }

        .patient-details {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .encounter-actions {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            padding: 0.5rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            font-size: 0.875rem;
        }

        .btn-view {
            background: #e3f2fd;
            color: #1976d2;
        }

        .btn-edit {
            background: #fff3e0;
            color: #f57c00;
        }

        .btn-view:hover {
            background: #1976d2;
            color: white;
        }

        .btn-edit:hover {
            background: #f57c00;
            color: white;
        }

        .encounter-stats {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: #6c757d;
        }

        .stat-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .service-type-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.375rem 0.75rem;
            background: linear-gradient(135deg, #e3f2fd, #f0f8ff);
            border: 1px solid #bbdefb;
            border-radius: 8px;
            font-weight: 500;
            color: #0077b6;
            font-size: 0.85rem;
        }

        .service-type-badge i {
            color: #0077b6;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .pagination a,
        .pagination span {
            padding: 0.75rem 1rem;
            border: 1px solid #dee2e6;
            color: #0077b6;
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .pagination a:hover {
            background: #0077b6;
            color: white;
        }

        .pagination .current {
            background: #0077b6;
            color: white;
            border-color: #0077b6;
        }

        /* Enhanced Encounters Table Styles */
        .encounters-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin: 2rem 0;
            overflow: hidden;
            border: 1px solid #e8f0fe;
        }

        .encounters-header {
            background: linear-gradient(135deg, #0077b6 0%, #023e8a 100%);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .encounters-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1;
        }

        .encounters-title i {
            font-size: 1.25rem;
            opacity: 0.9;
        }

        .encounters-title h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
            letter-spacing: -0.02em;
        }

        .encounters-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-left: 0.5rem;
        }

        .btn-new-consultation {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .btn-new-consultation:hover {
            background: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.5);
            transform: translateY(-1px);
            color: white;
        }

        .encounters-table-container {
            overflow: hidden;
        }

        .table-responsive {
            overflow-x: auto;
            background: white;
        }

        .encounters-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .encounters-table thead {
            background: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
        }

        .encounters-table th {
            padding: 1rem 0.75rem;
            text-align: left;
            font-weight: 600;
            color: #334155;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .encounters-table th i {
            margin-right: 0.5rem;
            color: #64748b;
            width: 14px;
        }

        .encounter-row {
            border-bottom: 1px solid #f1f5f9;
            transition: all 0.2s ease;
        }

        .encounter-row:hover {
            background: #f8fafc;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .encounters-table td {
            padding: 1rem 0.75rem;
            vertical-align: top;
            border-bottom: 1px solid #f1f5f9;
        }

        /* Date Cell Styles */
        .date-cell {
            min-width: 140px;
        }

        .date-primary {
            font-weight: 600;
            color: #1e293b;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }

        .date-secondary {
            color: #64748b;
            font-size: 0.8rem;
            margin-bottom: 0.5rem;
        }

        .consultation-id {
            background: #e0f2fe;
            color: #0277bd;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 500;
            display: inline-block;
        }

        /* Patient Cell Styles */
        .patient-cell {
            min-width: 200px;
        }

        .patient-name {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .patient-name i {
            color: #0077b6;
            font-size: 1rem;
        }

        .patient-meta {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 0.5rem;
        }

        .patient-id {
            background: #f1f5f9;
            color: #475569;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .patient-demographics {
            color: #64748b;
            font-size: 0.8rem;
        }

        .patient-location {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            color: #64748b;
            font-size: 0.75rem;
        }

        .patient-location i {
            color: #94a3b8;
        }

        /* Doctor Cell Styles */
        .doctor-cell {
            min-width: 160px;
        }

        .doctor-assigned {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #1e293b;
            font-weight: 500;
        }

        .doctor-assigned i {
            color: #059669;
            font-size: 1rem;
        }

        .doctor-unassigned {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #64748b;
            font-style: italic;
        }

        .doctor-unassigned i {
            color: #94a3b8;
        }

        /* Service Cell Styles */
        .service-cell {
            min-width: 140px;
        }

        .service-badge {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            background: #ecfdf5;
            color: #059669;
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            margin-bottom: 0.5rem;
            border: 1px solid #d1fae5;
        }

        .service-badge i {
            font-size: 0.9rem;
        }

        .service-name {
            font-weight: 500;
            font-size: 0.85rem;
        }

        .service-id {
            color: #64748b;
            font-size: 0.7rem;
        }

        .service-empty {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #94a3b8;
            font-style: italic;
        }

        /* Complaint and Diagnosis Cells */
        .complaint-cell,
        .diagnosis-cell {
            max-width: 180px;
        }

        .complaint-text,
        .diagnosis-text {
            color: #374151;
            line-height: 1.4;
            font-size: 0.85rem;
        }

        .diagnosis-pending {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #f59e0b;
            font-style: italic;
        }

        /* Status Cell Styles */
        .status-cell {
            min-width: 140px;
        }

        .status-wrapper {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .status-badge-new {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
            white-space: nowrap;
        }

        .status-badge-new i {
            font-size: 0.6rem;
        }

        .status-completed {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .status-ongoing {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fed7aa;
        }

        .status-awaiting_lab_results {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .status-awaiting_followup {
            background: #fce7f3;
            color: #be185d;
            border: 1px solid #f9a8d4;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .encounter-indicators {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
        }

        .indicator-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 500;
        }

        .indicator-item.prescription {
            background: #f0f9ff;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        .indicator-item.lab-test {
            background: #fef7ff;
            color: #a21caf;
            border: 1px solid #f5d0fe;
        }

        .indicator-item.referral {
            background: #fff7ed;
            color: #c2410c;
            border: 1px solid #fed7aa;
        }

        /* Vitals Cell Styles */
        .vitals-cell {
            min-width: 160px;
        }

        .vitals-container {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .vitals-id {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #059669;
            font-weight: 500;
            font-size: 0.8rem;
        }

        .vitals-readings {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .vital-item {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            font-size: 0.75rem;
            color: #374151;
        }

        .vital-item.bp i {
            color: #dc2626;
        }

        .vital-item.temp i {
            color: #f59e0b;
        }

        .vital-item.hr i {
            color: #ef4444;
        }

        .vitals-empty {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #94a3b8;
            font-style: italic;
        }

        /* Actions Cell Styles */
        .actions-cell {
            min-width: 120px;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.2s ease;
            border: 1px solid transparent;
            cursor: pointer;
            background: none;
        }

        .view-btn {
            background: #f0f9ff;
            color: #0369a1;
            border-color: #bae6fd;
        }

        .view-btn:hover {
            background: #0369a1;
            color: white;
            transform: translateY(-1px);
        }

        .edit-btn {
            background: #fefce8;
            color: #ca8a04;
            border-color: #fde047;
        }

        .edit-btn:hover {
            background: #ca8a04;
            color: white;
            transform: translateY(-1px);
            text-decoration: none;
        }

        /* Empty State Styles */
        .empty-state-new {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 4rem 2rem;
            text-align: center;
            background: #fafbfc;
            border-radius: 12px;
            margin: 2rem;
        }

        .empty-state-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #e0f2fe, #b3e5fc);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
        }

        .empty-state-icon i {
            font-size: 2rem;
            color: #0277bd;
        }

        .empty-state-content h3 {
            color: #1e293b;
            margin-bottom: 0.75rem;
            font-size: 1.25rem;
        }

        .empty-state-content p {
            color: #64748b;
            margin-bottom: 2rem;
            max-width: 400px;
            line-height: 1.5;
        }

        /* Notifications Container Styling */
        .notifications-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
            pointer-events: none;
        }

        .notifications-container .alert {
            pointer-events: auto;
            margin-bottom: 10px;
            animation: slideInRight 0.3s ease-out;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Responsive Design */
        @media (max-width: 1200px) {

            .encounters-table th,
            .encounters-table td {
                padding: 0.75rem 0.5rem;
            }

            .col-complaint,
            .col-diagnosis {
                display: none;
            }

            .complaint-cell,
            .diagnosis-cell {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .encounters-header {
                flex-direction: column;
                align-items: stretch;
                gap: 1rem;
            }

            .encounters-title {
                justify-content: center;
            }

            .btn-new-consultation {
                justify-content: center;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-btn span {
                display: none;
            }

            .encounters-table {
                font-size: 0.8rem;
            }
        }

        /* Enhanced Filter Styles */
        .filters-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            padding: 1.5rem;
            margin: 1.5rem 0;
            border: 1px solid #e8f0fe;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group input,
        .form-group select {
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            background: white;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
            transform: translateY(-1px);
        }

        .form-group input::placeholder {
            color: #9ca3af;
            font-style: italic;
        }

        /* Patient Search Form Enhancement */
        .form-group:has(input[name="patient_id"]) label::before {
            content: "🆔";
            margin-right: 0.25rem;
        }

        .form-group:has(input[name="first_name"]) label::before {
            content: "👤";
            margin-right: 0.25rem;
        }

        .form-group:has(input[name="last_name"]) label::before {
            content: "👥";
            margin-right: 0.25rem;
        }

        .form-group input[name="patient_id"] {
            background: linear-gradient(135deg, #f8fafc 0%, #e0f2fe 100%);
            border-color: #bae6fd;
        }

        .form-group input[name="first_name"],
        .form-group input[name="last_name"] {
            background: linear-gradient(135deg, #f8fafc 0%, #ecfdf5 100%);
            border-color: #d1fae5;
        }

        .filter-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            justify-content: flex-start;
            margin-top: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            border: 2px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            border-color: #0077b6;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #023e8a, #001d3d);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 119, 182, 0.3);
        }

        .btn-secondary {
            background: #f8f9fa;
            color: #6c757d;
            border-color: #dee2e6;
        }

        .btn-secondary:hover {
            background: #e9ecef;
            color: #495057;
            transform: translateY(-1px);
            text-decoration: none;
        }

        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }

            .filter-actions {
                justify-content: center;
                flex-wrap: wrap;
            }
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .table-header {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch;
            }

            .encounters-table {
                font-size: 0.875rem;
            }

            .encounters-table th,
            .encounters-table td {
                padding: 0.75rem 1rem;
            }

            /* Hide less critical columns on mobile */
            .encounters-table th:nth-child(4),
            /* Service Type */
            .encounters-table td:nth-child(4),
            .encounters-table th:nth-child(7),
            /* Vitals */
            .encounters-table td:nth-child(7) {
                display: none;
            }
        }
    </style>
</head>

<body>
    <?php
    $activePage = 'clinical_encounters';
    include $root_path . '/includes/sidebar_' . $employee_role . '.php';
    ?>

    <section class="content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb" style="margin-top: 50px;">
            <a href="../management/dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <span>Clinical Encounter Management</span>
        </div>

        <div class="page-header">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <h1><i class="fas fa-stethoscope"></i> Clinical Encounter Management</h1>
                <?php if (in_array($employee_role, ['doctor', 'admin', 'nurse', 'pharmacist'])): ?>
                    <div class="header-actions">
                        <a href="new_consultation_standalone.php" class="btn btn-primary" style="background: #28a745; border-color: #28a745;">
                            <i class="fas fa-plus-circle"></i> New Consultation
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Notifications and Messages Container -->
        <div class="notifications-container">
            <?php
            $scope_message = '';
            switch ($employee_role) {
                case 'bhw':
                    $barangay_name = $employee_details['barangay_id'] ?? 'your assigned barangay';
                    $scope_message = "You are viewing consultations for patients in your assigned barangay.";
                    break;
                case 'dho':
                    $district_name = $employee_details['district_id'] ?? 'your assigned district';
                    $scope_message = "You are viewing consultations for patients in your assigned district.";
                    break;
                case 'doctor':
                case 'nurse':
                    $scope_message = "You are viewing consultations assigned to you. Use search to view other consultations.";
                    break;
                case 'admin':
                case 'records_officer':
                    $scope_message = "You have access to view all consultations system-wide.";
                    break;
            }
            ?>
            <?php if ($scope_message): ?>
                <div style="background: #e3f2fd; padding: 0.75rem; border-radius: 6px; margin-top: 1rem; font-size: 0.9rem; color: #1976d2;">
                    <i class="fas fa-info-circle"></i> <?= htmlspecialchars($scope_message) ?>
                </div>
            <?php endif; ?>

            <?php
            // Handle success messages
            if (isset($_GET['success'])) {
                $success_message = '';
                switch ($_GET['success']) {
                    case 'consultation_created':
                        $success_message = 'Consultation created successfully!';
                        break;
                    case 'consultation_updated':
                        $success_message = isset($_GET['message']) ? $_GET['message'] : 'Consultation updated successfully!';
                        break;
                    default:
                        $success_message = 'Action completed successfully!';
                }
            ?>
                <div class="alert alert-success" id="successAlert">
                    <i class="fas fa-check-circle"></i>
                    <span><?= htmlspecialchars($success_message) ?></span>
                    <button type="button" class="alert-close" onclick="this.parentElement.remove();">&times;</button>
                </div>
                <script>
                    // Auto-dismiss success after 5 seconds
                    setTimeout(function() {
                        const successAlert = document.getElementById('successAlert');
                        if (successAlert) {
                            successAlert.style.opacity = '0';
                            successAlert.style.transform = 'translateY(-20px)';
                            setTimeout(() => successAlert.remove(), 300);
                        }
                    }, 5000);
                </script>
            <?php } ?>

            <?php
            // Handle error messages
            if (isset($_GET['error'])) {
                $error_message = '';
                switch ($_GET['error']) {
                    case 'database_error':
                        $error_message = 'Database error occurred. Please try again or contact administrator.';
                        break;
                    case 'invalid_consultation':
                        $error_message = 'Invalid consultation ID. Please select a valid consultation.';
                        break;
                    default:
                        $error_message = 'An error occurred. Please try again.';
                }
            ?>
                <div class="alert alert-warning" id="errorAlert">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?= htmlspecialchars($error_message) ?></span>
                    <button type="button" class="alert-close" onclick="this.parentElement.remove();">&times;</button>
                </div>
                <script>
                    // Auto-dismiss error after 8 seconds
                    setTimeout(function() {
                        const errorAlert = document.getElementById('errorAlert');
                        if (errorAlert) {
                            errorAlert.style.opacity = '0';
                            errorAlert.style.transform = 'translateY(-20px)';
                            setTimeout(() => errorAlert.remove(), 300);
                        }
                    }, 8000);
                </script>
            <?php } ?>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($stats['total_encounters']) ?></div>
                <div class="stat-label">Total Encounters</div>
            </div>

            <div class="stat-card completed">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($stats['completed_today']) ?></div>
                <div class="stat-label">Completed Today</div>
            </div>

            <div class="stat-card follow-up">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($stats['follow_ups_needed']) ?></div>
                <div class="stat-label">Follow-ups Needed</div>
            </div>

            <div class="stat-card referred">
                <div class="stat-header">
                    <div class="stat-icon">
                        <i class="fas fa-share-alt"></i>
                    </div>
                </div>
                <div class="stat-value"><?= number_format($stats['referred_cases']) ?></div>
                <div class="stat-label">Referred Cases</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-container">
            <div class="section-header" style="margin-bottom: 15px;">
                <h4 style="margin: 0;color: var(--primary-dark);font-size: 18px;font-weight: 600;">
                    <i class="fas fa-search"></i> Patient Search & Filter Options
                </h4>
            </div>
            <form method="GET" class="filters-grid">
                <div class="form-group">
                    <label for="patient_id">Patient ID</label>
                    <input type="text" id="patient_id" name="patient_id" value="<?= htmlspecialchars($_GET['patient_id'] ?? '') ?>"
                        placeholder="Enter Patient ID...">
                </div>

                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($_GET['first_name'] ?? '') ?>"
                        placeholder="Enter first name...">
                </div>

                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($_GET['last_name'] ?? '') ?>"
                        placeholder="Enter last name...">
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All Statuses</option>
                        <option value="ongoing" <?= $status_filter === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                        <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="awaiting_lab_results" <?= $status_filter === 'awaiting_lab_results' ? 'selected' : '' ?>>Awaiting Lab Results</option>
                        <option value="awaiting_followup" <?= $status_filter === 'awaiting_followup' ? 'selected' : '' ?>>Awaiting Follow-up</option>
                        <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="date_from">Date From</label>
                    <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>

                <div class="form-group">
                    <label for="date_to">Date To</label>
                    <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>

                <div class="form-group">
                    <label for="doctor">Doctor</label>
                    <select id="doctor" name="doctor">
                        <option value="">All Doctors</option>
                        <?php foreach ($doctors as $doctor): ?>
                            <option value="<?= $doctor['employee_id'] ?>" <?= $doctor_filter == $doctor['employee_id'] ? 'selected' : '' ?>>
                                Dr. <?= htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if (in_array($employee_role, ['admin', 'records_officer', 'dho'])): ?>
                    <div class="form-group">
                        <label for="barangay">Barangay</label>
                        <select id="barangay" name="barangay">
                            <option value="">All Barangays</option>
                            <?php foreach ($barangays as $barangay): ?>
                                <option value="<?= $barangay['barangay_id'] ?>" <?= $barangay_filter == $barangay['barangay_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($barangay['barangay_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php if (in_array($employee_role, ['admin', 'records_officer'])): ?>
                    <div class="form-group">
                        <label for="district">District</label>
                        <select id="district" name="district">
                            <option value="">All Districts</option>
                            <?php foreach ($districts as $district): ?>
                                <option value="<?= $district['district_id'] ?>" <?= $district_filter == $district['district_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($district['district_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label>&nbsp;</label>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search Consultations
                        </button>
                        <a href="?" class="btn btn-secondary">
                            <i class="fas fa-refresh"></i> Reset Filters
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <!-- Encounters Table -->
        <div class="encounters-card">
            <div class="encounters-header">
                <div class="encounters-title">
                    <i class="fas fa-stethoscope"></i>
                    <h3>Clinical Encounters</h3>
                    <span class="encounters-count"><?= count($encounters) ?> records</span>
                </div>
                <a href="new_consultation_standalone.php" class="btn-new-consultation">
                    <i class="fas fa-plus"></i>
                    <span>New Consultation</span>
                </a>
            </div>

            <div class="encounters-table-container">
                <?php if (!empty($encounters)): ?>
                    <div class="table-responsive">
                        <table class="encounters-table">
                            <thead>
                                <tr>
                                    <th class="col-date">
                                        <i class="fas fa-calendar-alt"></i>
                                        Date & Time
                                    </th>
                                    <th class="col-patient">
                                        <i class="fas fa-user"></i>
                                        Patient Information
                                    </th>
                                    <th class="col-doctor">
                                        <i class="fas fa-user-md"></i>
                                        Doctor
                                    </th>
                                    <th class="col-service">
                                        <i class="fas fa-medical-plus"></i>
                                        Service
                                    </th>
                                    <th class="col-complaint">
                                        <i class="fas fa-clipboard-list"></i>
                                        Chief Complaint
                                    </th>
                                    <th class="col-diagnosis">
                                        <i class="fas fa-diagnoses"></i>
                                        Diagnosis
                                    </th>
                                    <th class="col-status">
                                        <i class="fas fa-info-circle"></i>
                                        Status
                                    </th>
                                    <th class="col-vitals">
                                        <i class="fas fa-heartbeat"></i>
                                        Vitals
                                    </th>
                                    <th class="col-actions">
                                        <i class="fas fa-cog"></i>
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($encounters as $encounter): ?>
                                    <tr class="encounter-row">
                                        <td class="date-cell">
                                            <div class="date-primary">
                                                <?= date('M j, Y', strtotime($encounter['consultation_date'])) ?>
                                            </div>
                                            <div class="date-secondary">
                                                <?= date('g:i A', strtotime($encounter['consultation_date'])) ?>
                                            </div>
                                            <div class="consultation-id">
                                                ID: <?= $encounter['encounter_id'] ?>
                                            </div>
                                        </td>

                                        <td class="patient-cell">
                                            <div class="patient-name">
                                                <i class="fas fa-user-circle"></i>
                                                <?= htmlspecialchars($encounter['first_name'] . ' ' . $encounter['last_name']) ?>
                                            </div>
                                            <div class="patient-meta">
                                                <span class="patient-id">ID: <?= htmlspecialchars($encounter['patient_id_display']) ?></span>
                                                <span class="patient-demographics">
                                                    <?= htmlspecialchars($encounter['age']) ?>y/o <?= htmlspecialchars($encounter['sex']) ?>
                                                </span>
                                            </div>
                                            <?php if ($encounter['barangay_name'] && in_array($employee_role, ['admin', 'records_officer', 'dho'])): ?>
                                                <div class="patient-location">
                                                    <i class="fas fa-map-marker-alt"></i>
                                                    <?= htmlspecialchars($encounter['barangay_name']) ?>
                                                    <?php if ($encounter['district_name'] && in_array($employee_role, ['admin', 'records_officer'])): ?>
                                                        <span class="district">, <?= htmlspecialchars($encounter['district_name']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td class="doctor-cell">
                                            <?php if ($encounter['doctor_first_name']): ?>
                                                <div class="doctor-assigned">
                                                    <i class="fas fa-user-md"></i>
                                                    <span>Dr. <?= htmlspecialchars($encounter['doctor_first_name'] . ' ' . $encounter['doctor_last_name']) ?></span>
                                                </div>
                                            <?php else: ?>
                                                <div class="doctor-unassigned">
                                                    <i class="fas fa-user-times"></i>
                                                    <span>Not assigned</span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td class="service-cell">
                                            <?php if ($encounter['service_name']): ?>
                                                <div class="service-badge">
                                                    <i class="fas fa-medical-plus"></i>
                                                    <span class="service-name"><?= htmlspecialchars($encounter['service_name']) ?></span>
                                                </div>
                                                <div class="service-id">ID: <?= $encounter['service_id'] ?></div>
                                            <?php else: ?>
                                                <div class="service-empty">
                                                    <i class="fas fa-question-circle"></i>
                                                    <span>Not specified</span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td class="complaint-cell">
                                            <div class="complaint-text" title="<?= htmlspecialchars($encounter['chief_complaint']) ?>">
                                                <?= htmlspecialchars(substr($encounter['chief_complaint'], 0, 60)) ?>
                                                <?= strlen($encounter['chief_complaint']) > 60 ? '...' : '' ?>
                                            </div>
                                        </td>

                                        <td class="diagnosis-cell">
                                            <div class="diagnosis-text" title="<?= htmlspecialchars($encounter['diagnosis'] ?? 'No assessment yet') ?>">
                                                <?php if ($encounter['diagnosis']): ?>
                                                    <?= htmlspecialchars(substr($encounter['diagnosis'], 0, 50)) ?>
                                                    <?= strlen($encounter['diagnosis']) > 50 ? '...' : '' ?>
                                                <?php else: ?>
                                                    <span class="diagnosis-pending">
                                                        <i class="fas fa-hourglass-half"></i>
                                                        Pending assessment
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <td class="status-cell">
                                            <div class="status-wrapper">
                                                <span class="status-badge-new status-<?= htmlspecialchars($encounter['status']) ?>">
                                                    <i class="fas fa-circle"></i>
                                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $encounter['status']))) ?>
                                                </span>

                                                <?php if ($encounter['prescription_count'] > 0 || $encounter['lab_test_count'] > 0 || $encounter['referral_count'] > 0): ?>
                                                    <div class="encounter-indicators">
                                                        <?php if ($encounter['prescription_count'] > 0): ?>
                                                            <div class="indicator-item prescription" title="<?= $encounter['prescription_count'] ?> prescription(s)">
                                                                <i class="fas fa-pills"></i>
                                                                <span><?= $encounter['prescription_count'] ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($encounter['lab_test_count'] > 0): ?>
                                                            <div class="indicator-item lab-test" title="<?= $encounter['lab_test_count'] ?> lab test(s)">
                                                                <i class="fas fa-vial"></i>
                                                                <span><?= $encounter['lab_test_count'] ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($encounter['referral_count'] > 0): ?>
                                                            <div class="indicator-item referral" title="<?= $encounter['referral_count'] ?> referral(s)">
                                                                <i class="fas fa-share-alt"></i>
                                                                <span><?= $encounter['referral_count'] ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>

                                        <td class="vitals-cell">
                                            <?php if ($encounter['vitals_id']): ?>
                                                <div class="vitals-container">
                                                    <div class="vitals-id">
                                                        <i class="fas fa-heartbeat"></i>
                                                        ID: <?= $encounter['vitals_id'] ?>
                                                    </div>
                                                    <div class="vitals-readings">
                                                        <?php if ($encounter['blood_pressure']): ?>
                                                            <div class="vital-item bp">
                                                                <i class="fas fa-heart"></i>
                                                                <?= htmlspecialchars($encounter['blood_pressure']) ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($encounter['temperature']): ?>
                                                            <div class="vital-item temp">
                                                                <i class="fas fa-thermometer-half"></i>
                                                                <?= htmlspecialchars($encounter['temperature']) ?>°C
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($encounter['heart_rate']): ?>
                                                            <div class="vital-item hr">
                                                                <i class="fas fa-heartbeat"></i>
                                                                <?= htmlspecialchars($encounter['heart_rate']) ?> bpm
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="vitals-empty">
                                                    <i class="fas fa-ban"></i>
                                                    <span>No vitals recorded</span>
                                                </div>
                                            <?php endif; ?>
                                        </td>

                                        <td class="actions-cell">
                                            <div class="action-buttons">
                                                <button onclick="openConsultationModal(<?= $encounter['encounter_id'] ?>)"
                                                    class="action-btn view-btn"
                                                    title="View consultation details">
                                                    <i class="fas fa-eye"></i>
                                                    <span>View</span>
                                                </button>
                                                <?php if (
                                                    in_array($employee_role, ['doctor', 'admin']) ||
                                                    ($encounter['status'] == 'ongoing' && $employee_role == 'nurse')
                                                ): ?>
                                                    <a href="edit_consultation_new.php?id=<?= $encounter['encounter_id'] ?>"
                                                        class="action-btn edit-btn"
                                                        title="Edit consultation">
                                                        <i class="fas fa-edit"></i>
                                                        <span>Edit</span>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state-new">
                        <div class="empty-state-icon">
                            <i class="fas fa-stethoscope"></i>
                        </div>
                        <div class="empty-state-content">
                            <h3>No Clinical Encounters Found</h3>
                            <p>No consultations match your current search criteria. Try adjusting your filters or create a new consultation.</p>
                            <a href="new_consultation_standalone.php" class="btn-new-consultation">
                                <i class="fas fa-plus"></i>
                                <span>Create New Consultation</span>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        </div>
        </div>
    </section>

    <!-- Consultation Details Modal -->
    <div class="consultation-modal-overlay" id="consultationModal">
        <div class="consultation-modal-content">
            <div class="consultation-modal-header">
                <h3><i class="fas fa-stethoscope"></i> Consultation Details</h3>
                <button type="button" class="modal-close-btn" onclick="closeConsultationModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="consultation-modal-body" id="consultationModalBody">
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading consultation details...</p>
                </div>
            </div>
            <div class="consultation-modal-footer">
                <div class="modal-footer-left">
                    <button type="button" id="printConsultationBtn" class="btn btn-secondary" onclick="printConsultationDetails()" style="display: none;">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <button type="button" id="downloadConsultationBtn" class="btn btn-secondary" onclick="downloadConsultationPDF()" style="display: none;">
                        <i class="fas fa-download"></i> Download PDF
                    </button>
                </div>
                <div class="modal-footer-right">
                    <button type="button" class="btn btn-secondary" onclick="closeConsultationModal()">
                        <i class="fas fa-times"></i> Close
                    </button>
                    <button type="button" class="btn btn-primary" id="editConsultationBtn" style="display: none;">
                        <i class="fas fa-edit"></i> Edit Consultation
                    </button>
                </div>
            </div>
        </div>
    </div>

    <style>
        /* Alert Styles */
        .alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
            font-size: 0.95rem;
            border-left: 4px solid;
            transition: all 0.3s ease;
            position: relative;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left-color: #28a745;
        }

        .alert-warning {
            background: #fff8e1;
            color: #f57c00;
            border-left-color: #ff9800;
        }

        .alert-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.7;
            color: inherit;
            padding: 0;
            margin-left: auto;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .alert-close:hover {
            opacity: 1;
        }

        /* Consultation Modal Styles */
        .consultation-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .consultation-modal-overlay.show {
            display: flex;
            opacity: 1;
            visibility: visible;
        }

        .consultation-modal-content {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 90%;
            width: 800px;
            max-height: 90vh;
            overflow: hidden;
            transform: scale(0.7) translateY(-50px);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .consultation-modal-overlay.show .consultation-modal-content {
            transform: scale(1) translateY(0);
        }

        .consultation-modal-header {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 15px 15px 0 0;
        }

        .consultation-modal-header h3 {
            margin: 0;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .modal-close-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .modal-close-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .consultation-modal-body {
            padding: 2rem;
            overflow-y: auto;
            flex: 1;
            max-height: calc(90vh - 200px);
        }

        .consultation-modal-footer {
            padding: 1.5rem 2rem;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8f9fa;
            border-radius: 0 0 15px 15px;
        }

        .modal-footer-left,
        .modal-footer-right {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .loading-spinner {
            text-align: center;
            padding: 3rem 2rem;
            color: #6c757d;
        }

        .loading-spinner i {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: #0077b6;
        }

        .consultation-detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .detail-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            border-left: 4px solid #0077b6;
        }

        .detail-card h4 {
            margin: 0 0 1rem 0;
            color: #0077b6;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e9ecef;
        }

        .detail-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .detail-label {
            font-weight: 600;
            color: #495057;
        }

        .detail-value {
            color: #6c757d;
            text-align: right;
            max-width: 60%;
            word-break: break-word;
        }

        @media (max-width: 768px) {
            .consultation-modal-content {
                width: 95%;
                margin: 1rem;
                max-height: 95vh;
            }

            .consultation-modal-header {
                padding: 1rem 1.5rem;
            }

            .consultation-modal-body {
                padding: 1.5rem;
            }

            .consultation-modal-footer {
                padding: 1rem 1.5rem;
                flex-direction: column;
            }

            .consultation-detail-grid {
                grid-template-columns: 1fr;
            }

            .detail-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .detail-value {
                text-align: left;
                max-width: 100%;
                margin-top: 0.25rem;
            }
        }
    </style>

    <script>
        let currentConsultationId = null;

        function openConsultationModal(consultationId) {
            currentConsultationId = consultationId;
            const modal = document.getElementById('consultationModal');
            const modalBody = document.getElementById('consultationModalBody');
            const editBtn = document.getElementById('editConsultationBtn');
            const printBtn = document.getElementById('printConsultationBtn');
            const downloadBtn = document.getElementById('downloadConsultationBtn');

            // Show modal with loading state
            modal.classList.add('show');
            modalBody.innerHTML = `
                <div class="loading-spinner">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading consultation details...</p>
                </div>
            `;
            editBtn.style.display = 'none';
            printBtn.style.display = 'none';
            downloadBtn.style.display = 'none';

            // Fetch consultation details
            fetch(`get_consultation_details.php?id=${consultationId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderConsultationDetails(data.consultation);

                        // Show action buttons
                        printBtn.style.display = 'inline-flex';
                        downloadBtn.style.display = 'inline-flex';

                        // Show edit button if user has permission (records officers excluded)
                        const userRole = '<?= $employee_role ?>';
                        const authorizedRoles = ['doctor', 'admin'];
                        const isOngoing = data.consultation.status === 'ongoing';
                        const isNurse = userRole === 'nurse';

                        if (authorizedRoles.includes(userRole) || (isOngoing && isNurse)) {
                            editBtn.style.display = 'inline-flex';
                            editBtn.onclick = () => {
                                window.location.href = `edit_consultation_new.php?id=${consultationId}`;
                            };
                        }
                    } else {
                        modalBody.innerHTML = `
                            <div class="loading-spinner">
                                <i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i>
                                <p>Error loading consultation details: ${data.message || 'Unknown error'}</p>
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error fetching consultation details:', error);
                    modalBody.innerHTML = `
                        <div class="loading-spinner">
                            <i class="fas fa-exclamation-triangle" style="color: #dc3545;"></i>
                            <p>Error loading consultation details. Please try again.</p>
                        </div>
                    `;
                });
        }

        function closeConsultationModal() {
            const modal = document.getElementById('consultationModal');
            modal.classList.remove('show');
            currentConsultationId = null;
        }

        function renderConsultationDetails(consultation) {
            const modalBody = document.getElementById('consultationModalBody');

            modalBody.innerHTML = `
                <div class="consultation-detail-grid">
                    <div class="detail-card">
                        <h4><i class="fas fa-user"></i> Patient Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Name:</span>
                            <span class="detail-value">${consultation.patient_name}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Patient ID:</span>
                            <span class="detail-value">${consultation.patient_id}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Age/Sex:</span>
                            <span class="detail-value">${consultation.age}/${consultation.sex}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Barangay:</span>
                            <span class="detail-value">${consultation.barangay || 'Not specified'}</span>
                        </div>
                    </div>

                    <div class="detail-card">
                        <h4><i class="fas fa-stethoscope"></i> Consultation Details</h4>
                        <div class="detail-item">
                            <span class="detail-label">Date:</span>
                            <span class="detail-value">${new Date(consultation.consultation_date).toLocaleDateString('en-US', { 
                                year: 'numeric', month: 'long', day: 'numeric', 
                                hour: '2-digit', minute: '2-digit' 
                            })}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Doctor:</span>
                            <span class="detail-value">${consultation.doctor_name || 'Not assigned'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Service Type:</span>
                            <span class="detail-value">${consultation.service_name || 'Not specified'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Status:</span>
                            <span class="detail-value">
                                <span class="status-badge status-${consultation.status}">
                                    ${consultation.status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}
                                </span>
                            </span>
                        </div>
                    </div>

                    ${consultation.vitals_id ? `
                    <div class="detail-card">
                        <h4><i class="fas fa-heartbeat"></i> Vital Signs</h4>
                        <div class="detail-item">
                            <span class="detail-label">Blood Pressure:</span>
                            <span class="detail-value">${consultation.blood_pressure || 'Not recorded'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Heart Rate:</span>
                            <span class="detail-value">${consultation.heart_rate ? consultation.heart_rate + ' bpm' : 'Not recorded'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Temperature:</span>
                            <span class="detail-value">${consultation.temperature ? consultation.temperature + '°C' : 'Not recorded'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Weight:</span>
                            <span class="detail-value">${consultation.weight ? consultation.weight + ' kg' : 'Not recorded'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Height:</span>
                            <span class="detail-value">${consultation.height ? consultation.height + ' cm' : 'Not recorded'}</span>
                        </div>
                    </div>
                    ` : ''}

                    <div class="detail-card" style="grid-column: 1 / -1;">
                        <h4><i class="fas fa-clipboard-list"></i> Clinical Information</h4>
                        <div class="detail-item">
                            <span class="detail-label">Chief Complaint:</span>
                            <span class="detail-value">${consultation.chief_complaint || 'Not specified'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Diagnosis:</span>
                            <span class="detail-value">${consultation.diagnosis || 'Pending'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Treatment Plan:</span>
                            <span class="detail-value">${consultation.treatment_plan || 'Not specified'}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Remarks:</span>
                            <span class="detail-value">${consultation.remarks || 'None'}</span>
                        </div>
                        ${consultation.follow_up_date ? `
                        <div class="detail-item">
                            <span class="detail-label">Follow-up Date:</span>
                            <span class="detail-value">${new Date(consultation.follow_up_date).toLocaleDateString('en-US', { 
                                year: 'numeric', month: 'long', day: 'numeric' 
                            })}</span>
                        </div>
                        ` : ''}
                    </div>
                </div>
            `;
        }

        function printConsultationDetails() {
            // Create a printable version of the consultation details
            if (!currentConsultationId) {
                alert('No consultation selected for printing.');
                return;
            }

            // Get the modal body content
            const modalBody = document.getElementById('consultationModalBody');

            // Create print content with header using string concatenation
            const printDate = new Date().toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });

            const printContent = '<!DOCTYPE html>' +
                '<html>' +
                '<head>' +
                '<meta charset="UTF-8">' +
                '<title>Consultation Details - CHO Koronadal</title>' +
                '<style>' +
                'body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: white; }' +
                '.header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #0077b6; padding-bottom: 20px; }' +
                '.header h1 { color: #0077b6; margin: 0; font-size: 28px; }' +
                '.header h2 { color: #666; margin: 10px 0; font-size: 20px; }' +
                '.header p { margin: 5px 0; color: #666; font-size: 14px; }' +
                '.consultation-detail-grid { display: block; }' +
                '.detail-card { background: #f8f9fa; border-left: 4px solid #0077b6; padding: 15px; margin-bottom: 20px; border-radius: 5px; page-break-inside: avoid; }' +
                '.detail-card h4 { color: #0077b6; margin: 0 0 15px 0; font-size: 16px; }' +
                '.detail-item { display: flex; justify-content: space-between; margin-bottom: 8px; padding: 5px 0; border-bottom: 1px solid #eee; }' +
                '.detail-item:last-child { border-bottom: none; }' +
                '.detail-label { font-weight: bold; color: #333; flex: 0 0 30%; }' +
                '.detail-value { color: #666; flex: 1; text-align: right; }' +
                '.status-badge { background: #e9ecef; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; }' +
                '@media print { body { margin: 0; padding: 15px; } .detail-card { page-break-inside: avoid; } }' +
                '</style>' +
                '</head>' +
                '<body>' +
                '<div class="header">' +
                '<h1>CHO Koronadal</h1>' +
                '<h2>Consultation Details</h2>' +
                '<p>Consultation ID: ' + currentConsultationId + '</p>' +
                '<p>Printed on: ' + printDate + '</p>' +
                '</div>' +
                modalBody.innerHTML +
                '<script>' +
                'window.onload = function() {' +
                'window.print();' +
                'window.onafterprint = function() { window.close(); }' +
                '}' +
                '<\/script>' +
                '<\/body>' +
                '<\/html>';

            // Open print popup window
            const printWindow = window.open('', '_blank', 'width=800,height=600,scrollbars=yes,resizable=yes');
            printWindow.document.write(printContent);
            printWindow.document.close();
        }

        function downloadConsultationPDF() {
            if (!currentConsultationId) {
                alert('No consultation selected for download.');
                return;
            }

            // Show loading state
            const downloadBtn = document.getElementById('downloadConsultationBtn');
            const originalText = downloadBtn.innerHTML;
            downloadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating PDF...';
            downloadBtn.disabled = true;

            // Create a form to submit to the PDF generation endpoint
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '../../api/generate_consultation_pdf.php';
            form.style.display = 'none';

            const consultationIdInput = document.createElement('input');
            consultationIdInput.type = 'hidden';
            consultationIdInput.name = 'consultation_id';
            consultationIdInput.value = currentConsultationId;

            form.appendChild(consultationIdInput);
            document.body.appendChild(form);

            // Submit form to download PDF
            form.submit();

            // Clean up
            document.body.removeChild(form);

            // Restore button state after a delay
            setTimeout(() => {
                downloadBtn.innerHTML = originalText;
                downloadBtn.disabled = false;
            }, 2000);
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('consultation-modal-overlay')) {
                closeConsultationModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeConsultationModal();
            }
        });
    </script>
</body>

</html>