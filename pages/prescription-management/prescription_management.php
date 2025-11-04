<?php
// Ensure output buffering is active (but don't create unnecessary nested buffers)
if (ob_get_level() === 0) {
    ob_start();
}

// Include employee session configuration
// Use absolute path resolution
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
include $root_path . '/config/db.php';

// Use relative path for assets - more reliable than absolute URLs
$assets_path = '../../assets';

// Check if user is logged in - use the session system for proper redirect
require_employee_login();

// Set active page for sidebar highlighting
$activePage = 'prescription_management';

// Define role-based permissions for prescription management using role_id
$canViewPrescriptions = in_array($_SESSION['role_id'], [1, 2, 3, 4, 7]); // admin, doctor, nurse, pharmacist, records_officer
$canDispensePrescriptions = in_array($_SESSION['role_id'], [1, 4]); // admin, pharmacist
$canCreatePrescriptions = in_array($_SESSION['role_id'], [1, 2]); // admin, doctor ONLY - pharmacists cannot create prescriptions
$canUpdateMedications = in_array($_SESSION['role_id'], [4]); // pharmacist only

if (!$canViewPrescriptions) {
    $role_id = $_SESSION['role_id'];
    // Map role_id to role name for redirect
    $roleMap = [
        1 => 'admin',
        2 => 'doctor',
        3 => 'nurse',
        4 => 'pharmacist',
        5 => 'dho',
        6 => 'bhw',
        7 => 'records_officer',
        8 => 'cashier',
        9 => 'laboratory_tech'
    ];
    $role = $roleMap[$role_id] ?? 'employee';
    header("Location: ../management/$role/dashboard.php");
    exit();
}

// Handle AJAX requests for search and filter - Left Panel (All Prescriptions)
$searchQuery = isset($_GET['search']) ? $_GET['search'] : '';
$barangayFilter = isset($_GET['barangay']) ? $_GET['barangay'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'issued';  // Default to issued prescriptions
$dateFilter = isset($_GET['date']) ? $_GET['date'] : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$recordsPerPage = 5; // Reduced to 5 entries per page
$offset = ($page - 1) * $recordsPerPage;

// Handle AJAX requests for search and filter - Right Panel (Recently Dispensed)
$recentSearchQuery = isset($_GET['recent_search']) ? $_GET['recent_search'] : '';
$recentDateFilter = isset($_GET['recent_date']) ? $_GET['recent_date'] : '';
$recentPage = isset($_GET['recent_page']) ? intval($_GET['recent_page']) : 1;
$recentRecordsPerPage = 5; // 5 entries per page
$recentOffset = ($recentPage - 1) * $recentRecordsPerPage;

// Check if overall_status column exists, if not use regular status
$checkColumnSql = "SHOW COLUMNS FROM prescriptions LIKE 'overall_status'";
$columnResult = $conn->query($checkColumnSql);
$hasOverallStatus = $columnResult->num_rows > 0;

// Fetch prescriptions with patient information and medication counts (LEFT PANEL - Active/Pending)
// Only show prescriptions that have at least one pending medication
$statusColumn = $hasOverallStatus ? 'p.overall_status' : 'p.status';
$prescriptionsSql = "SELECT p.prescription_id, p.patient_id, 
                     p.prescription_date, 
                     p.status, 
                     $statusColumn as overall_status,
                     p.prescribed_by_employee_id, 
                     p.remarks,
                     pt.first_name, pt.last_name, pt.middle_name, 
                     COALESCE(pt.username, pt.patient_id) as patient_id_display, 
                     b.barangay_name as barangay,
                     e.first_name as prescribed_by_first_name, e.last_name as prescribed_by_last_name,
                     COUNT(pm.prescribed_medication_id) as total_medications,
                     SUM(CASE WHEN pm.status = 'dispensed' THEN 1 ELSE 0 END) as dispensed_medications,
                     SUM(CASE WHEN pm.status = 'unavailable' THEN 1 ELSE 0 END) as unavailable_medications,
                     SUM(CASE WHEN pm.status = 'pending' THEN 1 ELSE 0 END) as pending_medications
              FROM prescriptions p
              LEFT JOIN patients pt ON p.patient_id = pt.patient_id
              LEFT JOIN barangay b ON pt.barangay_id = b.barangay_id
              LEFT JOIN employees e ON p.prescribed_by_employee_id = e.employee_id
              LEFT JOIN prescribed_medications pm ON p.prescription_id = pm.prescription_id
              WHERE pt.status = 'active' 
              AND EXISTS (
                  -- Only show prescriptions that have at least one pending medication
                  SELECT 1 FROM prescribed_medications pm_pending 
                  WHERE pm_pending.prescription_id = p.prescription_id 
                  AND pm_pending.status = 'pending'
              )";

$params = [];
$types = "";

if (!empty($searchQuery)) {
    $prescriptionsSql .= " AND (
        pt.first_name LIKE ? OR 
        pt.last_name LIKE ? OR 
        pt.middle_name LIKE ? OR 
        pt.username LIKE ? OR 
        pt.patient_id LIKE ? OR 
        CONCAT(pt.first_name, ' ', pt.last_name) LIKE ? OR 
        CONCAT(pt.first_name, ' ', pt.middle_name, ' ', pt.last_name) LIKE ?
    )";
    $searchParam = "%{$searchQuery}%";
    array_push($params, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    $types .= "sssssss";
}

if (!empty($barangayFilter)) {
    $prescriptionsSql .= " AND b.barangay_name = ?";
    array_push($params, $barangayFilter);
    $types .= "s";
}

if (!empty($statusFilter)) {
    $prescriptionsSql .= " AND $statusColumn = ?";
    array_push($params, $statusFilter);
    $types .= "s";
}

if (!empty($dateFilter)) {
    $prescriptionsSql .= " AND DATE(p.prescription_date) = ?";
    array_push($params, $dateFilter);
    $types .= "s";
}

$prescriptionsSql .= " GROUP BY p.prescription_id 
                       ORDER BY p.prescription_date DESC 
                       LIMIT ? OFFSET ?";
array_push($params, $recordsPerPage, $offset);
$types .= "ii";

// Get total count for pagination (LEFT PANEL - Active/Pending)
// Only count prescriptions that have at least one pending medication
$countSql = "SELECT COUNT(DISTINCT p.prescription_id) as total
              FROM prescriptions p
              LEFT JOIN patients pt ON p.patient_id = pt.patient_id
              LEFT JOIN barangay b ON pt.barangay_id = b.barangay_id
              LEFT JOIN employees e ON p.prescribed_by_employee_id = e.employee_id
              LEFT JOIN prescribed_medications pm ON p.prescription_id = pm.prescription_id
              WHERE pt.status = 'active' 
              AND EXISTS (
                  -- Only count prescriptions that have at least one pending medication
                  SELECT 1 FROM prescribed_medications pm_pending 
                  WHERE pm_pending.prescription_id = p.prescription_id 
                  AND pm_pending.status = 'pending'
              )";

$countParams = [];
$countTypes = "";

if (!empty($searchQuery)) {
    $countSql .= " AND (
        pt.first_name LIKE ? OR 
        pt.last_name LIKE ? OR 
        pt.middle_name LIKE ? OR 
        pt.username LIKE ? OR 
        pt.patient_id LIKE ? OR 
        CONCAT(pt.first_name, ' ', pt.last_name) LIKE ? OR 
        CONCAT(pt.first_name, ' ', pt.middle_name, ' ', pt.last_name) LIKE ?
    )";
    $searchParam = "%{$searchQuery}%";
    array_push($countParams, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam);
    $countTypes .= "sssssss";
}

if (!empty($barangayFilter)) {
    $countSql .= " AND b.barangay_name = ?";
    array_push($countParams, $barangayFilter);
    $countTypes .= "s";
}

if (!empty($statusFilter)) {
    $countSql .= " AND $statusColumn = ?";
    array_push($countParams, $statusFilter);
    $countTypes .= "s";
}

if (!empty($dateFilter)) {
    $countSql .= " AND DATE(p.prescription_date) = ?";
    array_push($countParams, $dateFilter);
    $countTypes .= "s";
}

// Handle case where prescriptions table might not exist yet
$prescriptionsResult = null;
$totalPrescriptions = 0;
try {
    // Get total count first
    $countStmt = $conn->prepare($countSql);
    if ($countStmt !== false) {
        if (!empty($countTypes)) {
            $countStmt->bind_param($countTypes, ...$countParams);
        }
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        if ($countRow = $countResult->fetch_assoc()) {
            $totalPrescriptions = intval($countRow['total']);
        }
    }

    // Get prescriptions
    $prescriptionsStmt = $conn->prepare($prescriptionsSql);
    if ($prescriptionsStmt === false) {
        // Query preparation failed - likely table doesn't exist
        throw new Exception("Failed to prepare prescriptions query: " . $conn->error);
    }

    if (!empty($types)) {
        $prescriptionsStmt->bind_param($types, ...$params);
    }
    $prescriptionsStmt->execute();
    $prescriptionsResult = $prescriptionsStmt->get_result();
} catch (Exception $e) {
    // Table doesn't exist yet or query failed - we'll show empty results
    error_log("Prescription Management Error: " . $e->getMessage());
    $prescriptionsResult = null;
    $totalPrescriptions = 0;
}

// Recently dispensed prescriptions query - shows prescriptions where ALL medications are processed
// (all medications either dispensed OR unavailable - no pending medications remaining)
$recentDispensedSql = "SELECT p.prescription_id, 
                       p.prescription_date,
                       p.updated_at as dispensed_date,
                       pt.first_name, pt.last_name, pt.middle_name, 
                       COALESCE(pt.username, pt.patient_id) as patient_id_display,
                       e.first_name as doctor_first_name, e.last_name as doctor_last_name,
                       COALESCE(pharm.first_name, 'System') as pharmacist_first_name, 
                       COALESCE(pharm.last_name, '') as pharmacist_last_name
                       FROM prescriptions p 
                       LEFT JOIN patients pt ON p.patient_id = pt.patient_id
                       LEFT JOIN employees e ON p.prescribed_by_employee_id = e.employee_id
                       LEFT JOIN (
                           SELECT pl.prescription_id, emp.first_name, emp.last_name
                           FROM prescription_logs pl
                           JOIN employees emp ON pl.changed_by_employee_id = emp.employee_id
                           WHERE pl.action_type = 'medication_updated'
                           GROUP BY pl.prescription_id
                           ORDER BY pl.created_at DESC
                       ) pharm ON p.prescription_id = pharm.prescription_id
                       WHERE pt.status = 'active'
                       AND NOT EXISTS (
                           -- Exclude prescriptions that have any pending medications
                           SELECT 1 FROM prescribed_medications pm_pending 
                           WHERE pm_pending.prescription_id = p.prescription_id 
                           AND pm_pending.status = 'pending'
                       )
                       AND EXISTS (
                           -- Only include prescriptions that have at least one medication
                           SELECT 1 FROM prescribed_medications pm_exists 
                           WHERE pm_exists.prescription_id = p.prescription_id
                       )";

$recentParams = [];
$recentTypes = "";

if (!empty($recentSearchQuery)) {
    $recentDispensedSql .= " AND (
        pt.first_name LIKE ? OR 
        pt.last_name LIKE ? OR 
        pt.middle_name LIKE ? OR 
        pt.username LIKE ? OR 
        pt.patient_id LIKE ? OR 
        CONCAT(pt.first_name, ' ', pt.last_name) LIKE ? OR 
        CONCAT(pt.first_name, ' ', pt.middle_name, ' ', pt.last_name) LIKE ?
    )";
    $recentSearchParam = "%{$recentSearchQuery}%";
    array_push($recentParams, $recentSearchParam, $recentSearchParam, $recentSearchParam, $recentSearchParam, $recentSearchParam, $recentSearchParam, $recentSearchParam);
    $recentTypes .= "sssssss";
}

if (!empty($recentDateFilter)) {
    $recentDispensedSql .= " AND DATE(p.updated_at) = ?";
    array_push($recentParams, $recentDateFilter);
    $recentTypes .= "s";
}

$recentDispensedSql .= " ORDER BY p.updated_at DESC LIMIT ? OFFSET ?";
array_push($recentParams, $recentRecordsPerPage, $recentOffset);
$recentTypes .= "ii";

// Get total count for recently dispensed pagination
$recentCountSql = "SELECT COUNT(*) as total
                   FROM prescriptions p 
                   LEFT JOIN patients pt ON p.patient_id = pt.patient_id
                   LEFT JOIN employees e ON p.prescribed_by_employee_id = e.employee_id
                   WHERE pt.status = 'active'
                   AND NOT EXISTS (
                       -- Exclude prescriptions that have any pending medications
                       SELECT 1 FROM prescribed_medications pm_pending 
                       WHERE pm_pending.prescription_id = p.prescription_id 
                       AND pm_pending.status = 'pending'
                   )
                   AND EXISTS (
                       -- Only include prescriptions that have at least one medication
                       SELECT 1 FROM prescribed_medications pm_exists 
                       WHERE pm_exists.prescription_id = p.prescription_id
                   )";

$recentCountParams = [];
$recentCountTypes = "";

if (!empty($recentSearchQuery)) {
    $recentCountSql .= " AND (
        pt.first_name LIKE ? OR 
        pt.last_name LIKE ? OR 
        pt.middle_name LIKE ? OR 
        pt.username LIKE ? OR 
        pt.patient_id LIKE ? OR 
        CONCAT(pt.first_name, ' ', pt.last_name) LIKE ? OR 
        CONCAT(pt.first_name, ' ', pt.middle_name, ' ', pt.last_name) LIKE ?
    )";
    $recentSearchParam = "%{$recentSearchQuery}%";
    array_push($recentCountParams, $recentSearchParam, $recentSearchParam, $recentSearchParam, $recentSearchParam, $recentSearchParam, $recentSearchParam, $recentSearchParam);
    $recentCountTypes .= "sssssss";
}

if (!empty($recentDateFilter)) {
    $recentCountSql .= " AND DATE(p.updated_at) = ?";
    array_push($recentCountParams, $recentDateFilter);
    $recentCountTypes .= "s";
}

$recentDispensedResult = null;
$totalRecentDispensed = 0;
try {
    // Get total count first
    $recentCountStmt = $conn->prepare($recentCountSql);
    if ($recentCountStmt !== false) {
        if (!empty($recentCountTypes)) {
            $recentCountStmt->bind_param($recentCountTypes, ...$recentCountParams);
        }
        $recentCountStmt->execute();
        $recentCountResult = $recentCountStmt->get_result();
        if ($recentCountRow = $recentCountResult->fetch_assoc()) {
            $totalRecentDispensed = intval($recentCountRow['total']);
        }
    }

    // Get recently dispensed prescriptions
    $recentDispensedStmt = $conn->prepare($recentDispensedSql);
    if ($recentDispensedStmt === false) {
        // Query preparation failed - likely table doesn't exist
        throw new Exception("Failed to prepare recent dispensed query: " . $conn->error);
    }

    if (!empty($recentTypes)) {
        $recentDispensedStmt->bind_param($recentTypes, ...$recentParams);
    }
    $recentDispensedStmt->execute();
    $recentDispensedResult = $recentDispensedStmt->get_result();
} catch (Exception $e) {
    // Table doesn't exist yet or query failed - we'll show empty results
    error_log("Recent Dispensed Query Error: " . $e->getMessage());
    $recentDispensedResult = null;
    $totalRecentDispensed = 0;
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
    <title>Prescription Management - WBHSMS</title>
    <link rel="stylesheet" href="<?= $assets_path ?>/css/sidebar.css">
    <link rel="stylesheet" href="<?= $assets_path ?>/css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Content wrapper and page layout */
        .content-wrapper {
            margin-left: 300px;
            padding: 20px;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
                padding: 10px;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card.total {
            border-left: 5px solid #6c757d;
        }

        .stat-card.pending {
            border-left: 5px solid #ffc107;
        }

        .stat-card.active {
            border-left: 5px solid #17a2b8;
        }

        .stat-card.completed {
            border-left: 5px solid #28a745;
        }

        .stat-card.voided {
            border-left: 5px solid #dc3545;
        }

        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            color: #03045e;
        }

        .stat-label {
            font-size: 0.9em;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #666;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.375rem;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: #0077b6;
            color: white;
        }

        .btn-primary:hover {
            background-color: #005577;
        }

        .btn-outline {
            background-color: transparent;
            color: #0077b6;
            border: 2px solid #0077b6;
        }

        .btn-outline:hover {
            background-color: #0077b6;
            color: white;
        }

        /* Section Header Styles */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 2rem 0;
            padding: 1.5rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .section-title i {
            color: #0077b6;
            font-size: 1.5rem;
        }

        .section-title h2 {
            margin: 0;
            color: #2c3e50;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .section-actions {
            display: flex;
            gap: 0.5rem;
        }

        /* Workflow Guide Styles */
        .workflow-guide {
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 2rem 0;
            padding: 1.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .workflow-step {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-radius: 8px;
            background: #f8f9fa;
            min-width: 200px;
        }

        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #0077b6;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.1rem;
        }

        .step-content h4 {
            margin: 0 0 0.25rem 0;
            color: #2c3e50;
            font-size: 1rem;
        }

        .step-content p {
            margin: 0;
            color: #6c757d;
            font-size: 0.875rem;
        }

        .workflow-arrow {
            color: #0077b6;
            font-size: 1.5rem;
            margin: 0 1rem;
        }

        @media (max-width: 768px) {
            .workflow-guide {
                flex-direction: column;
                gap: 1rem;
            }

            .workflow-arrow {
                transform: rotate(90deg);
                margin: 0.5rem 0;
            }

            .section-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
        }

        /* Enhanced Prescription Management Styles */
        .prescription-management-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }

        .prescription-panel {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .prescription-panel:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        .active-panel {
            border-top: 4px solid #ffc107;
        }

        .completed-panel {
            border-top: 4px solid #28a745;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 1px solid #dee2e6;
        }

        .panel-title-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .panel-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .panel-icon.pending {
            background: #fff3cd;
            color: #856404;
        }

        .panel-icon.completed {
            background: #d4edda;
            color: #155724;
        }

        .panel-title-content h3 {
            margin: 0;
            color: #2c3e50;
            font-size: 1.25rem;
            font-weight: 600;
        }

        .panel-subtitle {
            color: #6c757d;
            font-size: 0.875rem;
        }

        .panel-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .panel-badge.pending {
            background: #fff3cd;
            color: #856404;
        }

        .panel-badge.completed {
            background: #d4edda;
            color: #155724;
        }

        /* Panel Info Cards */
        .panel-info-card {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            margin: 0;
            border-radius: 0;
            border-bottom: 1px solid #dee2e6;
            font-size: 0.875rem;
        }

        .panel-info-card.warning {
            background: #fffbf0;
            color: #856404;
            border-left: 4px solid #ffc107;
        }

        .panel-info-card.success {
            background: #f0f9f0;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .panel-info-card i {
            margin-top: 0.125rem;
            font-size: 1rem;
        }

        .info-content {
            flex: 1;
            line-height: 1.4;
        }

        .info-content small {
            opacity: 0.8;
        }

        /* Enhanced Prescription Management Styles */
        .prescription-management-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            margin-top: 2rem;
        }

        .prescription-panel {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .prescription-panel:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        .active-panel {
            border-top: 4px solid #ffc107;
        }

        .completed-panel {
            border-top: 4px solid #28a745;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-bottom: 1px solid #dee2e6;
        }

        .panel-title-section {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .panel-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .panel-icon.pending {
            background: #fff3cd;
            color: #856404;
        }

        .panel-icon.completed {
            background: #d4edda;
            color: #155724;
        }

        .panel-title-content h3 {
            margin: 0;
            color: #2c3e50;
            font-size: 1.25rem;
            font-weight: 600;
        }

        .panel-subtitle {
            color: #6c757d;
            font-size: 0.875rem;
        }

        .panel-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .panel-badge.pending {
            background: #fff3cd;
            color: #856404;
        }

        .panel-badge.completed {
            background: #d4edda;
            color: #155724;
        }

        /* Panel Info Cards */
        .panel-info-card {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            margin: 0;
            border-radius: 0;
            border-bottom: 1px solid #dee2e6;
            font-size: 0.875rem;
        }

        .panel-info-card.warning {
            background: #fffbf0;
            color: #856404;
            border-left: 4px solid #ffc107;
        }

        .panel-info-card.success {
            background: #f0f9f0;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .panel-info-card i {
            margin-top: 0.125rem;
            font-size: 1rem;
        }

        .info-content {
            flex: 1;
            line-height: 1.4;
        }

        .info-content small {
            opacity: 0.8;
        }

        /* Enhanced Search and Filter Styles */
        .search-filters {
            display: flex;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            flex-wrap: wrap;
            align-items: stretch;
        }

        .filter-input {
            padding: 0.75rem 1rem;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.875rem;
            flex: 1;
            min-width: 200px;
            transition: all 0.3s ease;
            background: white;
        }

        .filter-input:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
        }

        /* Enhanced search input styling */
        .filter-input[id="searchPrescriptions"],
        .filter-input[id="recentSearchPrescriptions"] {
            border: 2px solid #0077b6;
            background: white;
            font-weight: 500;
        }

        .filter-input[id="searchPrescriptions"]:focus,
        .filter-input[id="recentSearchPrescriptions"]:focus {
            border-color: #005577;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.2);
        }

        .filter-btn {
            padding: 0.75rem 1.25rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.875rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            white-space: nowrap;
        }

        .search-btn {
            background: linear-gradient(135deg, #0077b6 0%, #005577 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(0, 119, 182, 0.3);
        }

        .search-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 119, 182, 0.4);
        }

        .clear-btn {
            background: #6c757d;
            color: white;
        }

        .clear-btn:hover {
            background: #545b62;
            transform: translateY(-1px);
        }

        /* Enhanced Table Styles */
        .prescription-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .prescription-table th {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #dee2e6;
        }

        .prescription-table td {
            padding: 1rem;
            border-bottom: 1px solid #f1f3f4;
        }

        .prescription-table tr:hover {
            background-color: #f8f9fa;
        }

        /* Enhanced Action Buttons */
        .action-btn {
            padding: 0.5rem 1rem;
            margin: 0.125rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .btn-view {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            box-shadow: 0 2px 6px rgba(0, 123, 255, 0.3);
        }

        .btn-view:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0, 123, 255, 0.4);
        }

        .btn-dispense {
            background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
            color: white;
            box-shadow: 0 2px 6px rgba(40, 167, 69, 0.3);
        }

        .btn-dispense:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(40, 167, 69, 0.4);
        }

        .btn-print {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            box-shadow: 0 2px 6px rgba(23, 162, 184, 0.3);
        }

        .btn-print:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(23, 162, 184, 0.4);
        }

        /* Enhanced Status Badges */
        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .status-pending {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            color: #856404;
        }

        .status-dispensing {
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%);
            color: #0c5460;
        }

        .status-dispensed {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
        }

        .status-cancelled {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
        }

        .status-partial {
            background: linear-gradient(135deg, #e2e3e5 0%, #d6d8db 100%);
            color: #383d41;
        }

        /* Empty State Improvements */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            color: #dee2e6;
        }

        .empty-state h3 {
            margin-bottom: 1rem;
            color: #495057;
            font-size: 1.25rem;
        }

        .empty-state p {
            margin-bottom: 0;
            font-size: 1rem;
            line-height: 1.5;
        }

        /* Progress Bar Enhancements */
        .progress-bar {
            width: 80px;
            height: 10px;
            background-color: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #28a745 0%, #20c997 100%);
            transition: width 0.5s ease;
            border-radius: 10px;
        }

        /* Enhanced Mobile Responsiveness */
        @media (max-width: 992px) {
            .prescription-management-container {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }

            .section-header {
                padding: 1rem;
            }

            .section-title h2 {
                font-size: 1.25rem;
            }

            .workflow-guide {
                padding: 1rem;
            }

            .workflow-step {
                min-width: auto;
                flex: 1;
            }
        }

        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
                padding: 1rem;
            }

            .prescription-management-container {
                gap: 1rem;
            }

            .panel-header {
                padding: 1rem;
            }

            .panel-title-section {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .panel-icon {
                width: 40px;
                height: 40px;
                font-size: 1.25rem;
            }

            .search-filters {
                flex-direction: column;
                padding: 1rem;
            }

            .filter-input {
                min-width: 100%;
                margin-bottom: 0.5rem;
            }

            .filter-btn {
                width: 100%;
                justify-content: center;
            }

            .prescription-table {
                font-size: 0.8rem;
            }

            .prescription-table th,
            .prescription-table td {
                padding: 0.75rem 0.5rem;
            }

            .action-btn {
                padding: 0.375rem 0.75rem;
                font-size: 0.75rem;
                margin: 0.125rem;
            }
        }

        /* Accessibility Improvements */
        .btn:focus,
        .filter-btn:focus,
        .action-btn:focus {
            outline: 2px solid #0077b6;
            outline-offset: 2px;
        }

        .prescription-table tr:focus-within {
            background-color: #e8f4f8;
            outline: 2px solid #0077b6;
        }

        /* Loading State Styles */
        .loading-shimmer {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% {
                background-position: -200% 0;
            }

            100% {
                background-position: 200% 0;
            }
        }

        /* Search Help Tip */
        .search-help-tip {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: #e8f4f8;
            color: #0066cc;
            font-size: 0.875rem;
            border-bottom: 1px solid #bee5eb;
        }

        .search-help-tip i {
            color: #17a2b8;
        }

        .progress-bar {
            width: 60px;
            height: 8px;
            background-color: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background-color: #007bff;
            transition: width 0.3s;
        }

        /* Modal Styles */
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

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 10px;
            max-width: 75%;
            max-height: 80%;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }

        .modal-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .download-btn {
            background: #007cba;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9em;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: background 0.3s ease;
        }

        .download-btn:hover {
            background: #005a87;
        }

        .close-btn {
            font-size: 1.5em;
            cursor: pointer;
            color: #aaa;
            border: none;
            background: none;
        }

        .close-btn:hover {
            color: #000;
        }

        /* Empty state styles */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3em;
            margin-bottom: 15px;
            color: #dee2e6;
        }

        .empty-state h3 {
            margin-bottom: 10px;
            color: #495057;
        }

        .empty-state p {
            margin-bottom: 0;
        }

        /* Medication Selection Table Enhancements */
        #medicationsTable tbody tr[onclick] {
            transition: all 0.2s ease;
        }

        #medicationsTable tbody tr[onclick]:hover {
            background-color: #e8f4f8 !important;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        #medicationsTable tbody tr[onclick]:active {
            transform: translateY(0);
            background-color: #d1ecf1 !important;
        }

        @media (max-width: 768px) {
            .prescription-management-container {
                grid-template-columns: 1fr;
            }

            .search-filters {
                flex-direction: column;
            }

            .filter-input {
                min-width: 100%;
            }
        }

        /* Alert Styles */
        .alert {
            padding: 12px 16px;
            margin-bottom: 20px;
            border-radius: 5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.2em;
            cursor: pointer;
            color: inherit;
            opacity: 0.7;
        }

        .btn-close:hover {
            opacity: 1;
        }

        /* Print-specific styles */
        @media print {
            .no-print {
                display: none !important;
            }

            .modal {
                display: block !important;
                position: static !important;
                background: white !important;
            }

            .modal-content.print-modal {
                box-shadow: none !important;
                border: none !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .print-prescription {
                font-family: 'Times New Roman', serif;
                color: black !important;
                background: white !important;
                page-break-inside: avoid;
            }

            .print-header {
                text-align: center;
                margin-bottom: 30px;
                border-bottom: 2px solid #000;
                padding-bottom: 20px;
            }

            .print-logo {
                width: 80px;
                height: auto;
                margin-bottom: 10px;
            }

            .prescription-medications-print {
                width: 100%;
                border-collapse: collapse;
                margin-top: 20px;
            }

            .prescription-medications-print th,
            .prescription-medications-print td {
                border: 1px solid #000;
                padding: 8px;
                text-align: left;
                font-size: 12px;
            }

            .prescription-medications-print th {
                background-color: #f0f0f0;
                font-weight: bold;
            }

            .signature-section {
                margin-top: 40px;
                display: flex;
                justify-content: space-between;
            }

            .signature-box {
                width: 45%;
                text-align: center;
                border-top: 1px solid #000;
                padding-top: 10px;
                margin-top: 40px;
            }
        }

        /* Dispensed Modal Enhancements */
        .dispensed-modal-content {
            max-width: 60%;
            display: flex;
            flex-direction: column;
            border-radius: 12px;
            overflow: hidden;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            animation: modalSlideIn 0.3s ease-out;
        }

        .dispensed-modal-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: none;
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.2);
        }

        .dispensed-modal-header .modal-title-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .dispensed-modal-header .modal-icon-badge {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .dispensed-modal-header .modal-icon-badge i {
            font-size: 22px;
            color: white;
        }

        .dispensed-modal-header .modal-title-content h3 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .dispensed-modal-header .modal-subtitle {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 400;
            margin-top: 2px;
            display: block;
        }

        .dispensed-modal-header .modal-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .dispensed-modal-header .action-btn {
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            text-decoration: none;
        }

        .dispensed-modal-header .download-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .dispensed-modal-header .download-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .dispensed-modal-header .close-btn {
            background: rgba(220, 53, 69, 0.8);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .dispensed-modal-header .close-btn:hover {
            background: rgba(220, 53, 69, 1);
            transform: rotate(90deg);
        }

        .dispensed-modal-body {
            flex: 1;
            overflow-y: auto;
            background: white;
            max-height: calc(95vh - 120px);
        }

        /* Content Section Improvements */
        .dispensed-modal-body .patient-summary {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border: none;
            border-radius: 12px;
            margin: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #2196f3;
            box-shadow: 0 3px 10px rgba(33, 150, 243, 0.1);
        }

        .dispensed-modal-body .consultation-info {
            background: linear-gradient(135deg, #e8f5e8 0%, #f0f8ff 100%);
            border: none;
            border-radius: 12px;
            margin: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #17a2b8;
            box-shadow: 0 3px 10px rgba(23, 162, 184, 0.1);
        }

        .dispensed-modal-body .prescription-summary {
            background: linear-gradient(135deg, #e8f5e8 0%, #f0fff0 100%);
            border: none;
            border-radius: 12px;
            margin: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #28a745;
            box-shadow: 0 3px 10px rgba(40, 167, 69, 0.1);
        }

        .dispensed-modal-body h4 {
            color: #2c3e50;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .dispensed-modal-body h4 i {
            font-size: 20px;
            color: #3498db;
        }

        .dispensed-modal-body .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px;
        }

        .dispensed-modal-body .info-item {
            background: rgba(255, 255, 255, 0.7);
            padding: 12px;
            border-radius: 8px;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .dispensed-modal-body .info-label {
            font-weight: 600;
            color: #495057;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .dispensed-modal-body .info-value {
            color: #212529;
            font-size: 14px;
            font-weight: 500;
        }

        .dispensed-modal-body .medications-table {
            width: calc(100% - 40px);
            margin: 0 20px 20px 20px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }

        .dispensed-modal-body .medications-table th {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 12px;
            padding: 15px 12px;
        }

        .dispensed-modal-body .medications-table td {
            padding: 15px 12px;
            border-bottom: 1px solid #ecf0f1;
            background: white;
            transition: background-color 0.2s ease;
        }

        .dispensed-modal-body .medications-table tr:hover td {
            background: #f8f9fa;
        }

        .dispensed-modal-body .status-dispensed {
            color: #28a745;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .dispensed-modal-body .status-unavailable {
            color: #dc3545;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .dispensed-modal-body .dispensed-badge {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }

        /* Mobile Responsiveness for Dispensed Modal */
        @media (max-width: 768px) {
            .dispensed-modal-content {
                max-width: 98%;
                max-height: 98%;
                margin: 1%;
            }

            .dispensed-modal-header {
                padding: 15px 20px;
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .dispensed-modal-header .modal-title-section {
                flex-direction: column;
                gap: 10px;
            }

            .dispensed-modal-header .modal-actions {
                width: 100%;
                justify-content: center;
            }

            .dispensed-modal-body .info-grid {
                grid-template-columns: 1fr;
            }

            .dispensed-modal-body .medications-table {
                width: calc(100% - 20px);
                margin: 0 10px 20px 10px;
                font-size: 12px;
            }

            .dispensed-modal-body .patient-summary,
            .dispensed-modal-body .consultation-info,
            .dispensed-modal-body .prescription-summary {
                margin: 10px;
            }
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: scale(0.8) translateY(-50px);
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        /* Update Modal Enhancements */
        .update-modal-content {
            max-width: 90%;
            max-height: 95%;
            display: flex;
            flex-direction: column;
            border-radius: 12px;
            overflow: hidden;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
            animation: modalSlideIn 0.3s ease-out;
        }

        .update-modal-header {
            background: linear-gradient(135deg, #6f42c1 0%, #5a67d8 100%);
            color: white;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: none;
            box-shadow: 0 4px 12px rgba(111, 66, 193, 0.2);
        }

        .update-modal-header .modal-title-section {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .update-modal-header .modal-icon-badge {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .update-modal-header .modal-icon-badge i {
            font-size: 22px;
            color: white;
        }

        .update-modal-header .modal-title-content h3 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .update-modal-header .modal-subtitle {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 400;
            margin-top: 2px;
            display: block;
        }

        .update-modal-header .modal-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .update-modal-header .action-btn {
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            text-decoration: none;
        }

        .update-modal-header .download-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .update-modal-header .download-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .update-modal-header .close-btn {
            background: rgba(220, 53, 69, 0.8);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .update-modal-header .close-btn:hover {
            background: rgba(220, 53, 69, 1);
            transform: rotate(90deg);
        }

        .update-modal-body {
            flex: 1;
            overflow-y: auto;
            background: white;
            max-height: calc(95vh - 120px);
        }

        /* Update Modal Content Styling */
        .update-modal-body .patient-summary {
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border: none;
            border-radius: 12px;
            margin: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #2196f3;
            box-shadow: 0 3px 10px rgba(33, 150, 243, 0.1);
        }

        .update-modal-body .consultation-info {
            background: linear-gradient(135deg, #e8f5e8 0%, #f0f8ff 100%);
            border: none;
            border-radius: 12px;
            margin: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #17a2b8;
            box-shadow: 0 3px 10px rgba(23, 162, 184, 0.1);
        }

        .update-modal-body .medications-section {
            background: linear-gradient(135deg, #fff3cd 0%, #fef9e7 100%);
            border: none;
            border-radius: 12px;
            margin: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #ffc107;
            box-shadow: 0 3px 10px rgba(255, 193, 7, 0.1);
            padding: 20px;
        }

        .update-modal-body h4 {
            color: #2c3e50;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .update-modal-body h4 i {
            font-size: 20px;
            color: #6f42c1;
        }

        .update-modal-body .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .update-modal-body .info-item {
            background: rgba(255, 255, 255, 0.7);
            padding: 12px;
            border-radius: 8px;
            border: 1px solid rgba(0, 0, 0, 0.05);
            transition: all 0.2s ease;
        }

        .update-modal-body .info-item:hover {
            background: rgba(255, 255, 255, 0.9);
            transform: translateY(-1px);
        }

        .update-modal-body .info-label {
            font-weight: 600;
            color: #495057;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .update-modal-body .info-value {
            color: #212529;
            font-size: 14px;
            font-weight: 500;
        }

        .update-modal-body .medications-table {
            width: 100%;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            margin-top: 15px;
        }

        .update-modal-body .medications-table th {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 12px;
            padding: 15px 12px;
            border: none;
        }

        .update-modal-body .medications-table td {
            padding: 15px 12px;
            border: none;
            border-bottom: 1px solid #ecf0f1;
            background: white;
            transition: background-color 0.2s ease;
            font-size: 14px;
        }

        .update-modal-body .medications-table tr:hover td {
            background: #f8f9fa;
        }

        .update-modal-body .medications-table tr:last-child td {
            border-bottom: none;
        }

        .update-modal-body .status-dispensed {
            color: #28a745;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .update-modal-body .status-unavailable {
            color: #dc3545;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .update-modal-body .status-pending {
            color: #ffc107;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .update-modal-body .update-actions {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            border-top: 1px solid #dee2e6;
            margin: 0 20px 20px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .update-modal-body .update-actions .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            margin: 0 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .update-modal-body .update-actions .btn-primary {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            box-shadow: 0 3px 10px rgba(40, 167, 69, 0.3);
        }

        .update-modal-body .update-actions .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
        }

        .update-modal-body .update-actions .btn-secondary {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
            box-shadow: 0 3px 10px rgba(108, 117, 125, 0.3);
        }

        .update-modal-body .update-actions .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.4);
        }

        /* Toggle Switch Improvements */
        .update-modal-body .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 30px;
        }

        .update-modal-body .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .update-modal-body .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 30px;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .update-modal-body .slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .update-modal-body input:checked+.slider {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }

        .update-modal-body input:checked+.slider:before {
            transform: translateX(30px);
        }

        .update-modal-body input:disabled+.slider {
            background-color: #e9ecef;
            cursor: not-allowed;
            opacity: 0.6;
        }

        .update-modal-body .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }

        .update-modal-body .empty-state i {
            font-size: 48px;
            color: #dee2e6;
            margin-bottom: 15px;
        }

        .update-modal-body .empty-state h3 {
            color: #495057;
            margin-bottom: 10px;
        }

        /* Mobile Responsiveness for Update Modal */
        @media (max-width: 768px) {
            .update-modal-content {
                max-width: 98%;
                max-height: 98%;
                margin: 1%;
            }

            .update-modal-header {
                padding: 15px 20px;
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .update-modal-header .modal-title-section {
                flex-direction: column;
                gap: 10px;
            }

            .update-modal-header .modal-actions {
                width: 100%;
                justify-content: center;
            }

            .update-modal-body .info-grid {
                grid-template-columns: 1fr;
            }

            .update-modal-body .medications-table {
                font-size: 12px;
            }

            .update-modal-body .patient-summary,
            .update-modal-body .consultation-info,
            .update-modal-body .medications-section {
                margin: 10px;
            }

            .update-modal-body .update-actions {
                margin: 0 10px 10px 10px;
            }

            .update-modal-body .update-actions .btn {
                display: block;
                width: 100%;
                margin: 5px 0;
            }
        }
    </style>
</head>

<body>
    <!-- Set active page for sidebar highlighting -->
    <?php $activePage = 'prescription_management'; ?>

    <!-- Include role-based sidebar -->
    <?php
    require_once $root_path . '/includes/dynamic_sidebar_helper.php';
    includeDynamicSidebar($activePage, $root_path);
    ?>

    <section class="content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb" style="margin-top: 50px;">
            <a href="<?= getRoleDashboardUrl() ?>"><i class="fas fa-home"></i> Dashboard</a>
            <i class="fas fa-chevron-right"></i>
            <span>Prescription Management</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-pills"></i> Prescription Management</h1>
            <div class="page-header-actions">
                <?php if ($canCreatePrescriptions): ?>
                    <a href="create_prescription_standalone.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create Prescription
                    </a>
                <?php endif; ?>
                <button type="button" class="btn btn-primary" onclick="openMedicationsModal()" style="margin-left: 12px;">
                    <i class="fas fa-pills"></i> Available Medications
                </button>
                <button type="button" class="btn btn-secondary" onclick="window.location.reload()" style="margin-left: 12px;">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <div id="alertContainer"></div>

        <!-- Display server-side messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
                <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
                <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Prescription Statistics -->
        <div class="stats-grid">
            <?php
            // Get prescription and medication statistics - using default values since table may not exist yet
            $prescription_stats = [
                'total_prescriptions' => 0,
                'total_issued' => 0,
                'total_medications_dispensed' => 0,
                'total_medications_unavailable' => 0
            ];

            try {
                // Get total number of prescriptions ordered (30 days)
                $total_prescriptions_sql = "SELECT COUNT(*) as total FROM prescriptions WHERE DATE(prescription_date) >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)";
                $total_prescriptions_result = $conn->query($total_prescriptions_sql);
                if ($total_prescriptions_result && $row = $total_prescriptions_result->fetch_assoc()) {
                    $prescription_stats['total_prescriptions'] = intval($row['total'] ?? 0);
                }

                // Get total issued prescriptions (30 days)
                $statsColumn = $hasOverallStatus ? 'overall_status' : 'status';
                $total_issued_sql = "SELECT COUNT(*) as total FROM prescriptions WHERE COALESCE($statsColumn, 'issued') = 'issued' AND DATE(prescription_date) >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)";
                $total_issued_result = $conn->query($total_issued_sql);
                if ($total_issued_result && $row = $total_issued_result->fetch_assoc()) {
                    $prescription_stats['total_issued'] = intval($row['total'] ?? 0);
                }

                // Get total medications dispensed (30 days) - from prescribed_medications table
                $total_dispensed_medications_sql = "SELECT COUNT(*) as total 
                                                  FROM prescribed_medications pm 
                                                  INNER JOIN prescriptions p ON pm.prescription_id = p.prescription_id 
                                                  WHERE pm.status = 'dispensed' 
                                                  AND DATE(p.prescription_date) >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)";
                $total_dispensed_result = $conn->query($total_dispensed_medications_sql);
                if ($total_dispensed_result && $row = $total_dispensed_result->fetch_assoc()) {
                    $prescription_stats['total_medications_dispensed'] = intval($row['total'] ?? 0);
                }

                // Get total medications unavailable (30 days) - from prescribed_medications table
                $total_unavailable_medications_sql = "SELECT COUNT(*) as total 
                                                    FROM prescribed_medications pm 
                                                    INNER JOIN prescriptions p ON pm.prescription_id = p.prescription_id 
                                                    WHERE pm.status = 'unavailable' 
                                                    AND DATE(p.prescription_date) >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)";
                $total_unavailable_result = $conn->query($total_unavailable_medications_sql);
                if ($total_unavailable_result && $row = $total_unavailable_result->fetch_assoc()) {
                    $prescription_stats['total_medications_unavailable'] = intval($row['total'] ?? 0);
                }
            } catch (Exception $e) {
                // Use default values if query fails (table doesn't exist)
                error_log("Prescription Stats Error: " . $e->getMessage());
            }
            ?>

            <div class="stat-card total">
                <div class="stat-number"><?= number_format($prescription_stats['total_prescriptions']) ?></div>
                <div class="stat-label">Total Ordered Prescriptions (30 days)</div>
            </div>

            <div class="stat-card pending">
                <div class="stat-number"><?= number_format($prescription_stats['total_issued']) ?></div>
                <div class="stat-label">Total Issued</div>
            </div>

            <div class="stat-card completed">
                <div class="stat-number"><?= number_format($prescription_stats['total_medications_dispensed']) ?></div>
                <div class="stat-label">Total Medications Dispensed</div>
            </div>

            <div class="stat-card voided">
                <div class="stat-number"><?= number_format($prescription_stats['total_medications_unavailable']) ?></div>
                <div class="stat-label">Total Medications Unavailable</div>
            </div>
        </div>

        <!-- Workflow Guide -->
        <div class="workflow-guide">
            <div class="workflow-step">
                <div class="step-number">1</div>
                <div class="step-content">
                    <h4>Active Prescriptions</h4>
                    <p>Review and process pending medications</p>
                </div>
            </div>
            <div class="workflow-arrow">
                <i class="fas fa-arrow-right"></i>
            </div>
            <div class="workflow-step">
                <div class="step-number">2</div>
                <div class="step-content">
                    <h4>Mark Status</h4>
                    <p>Set as dispensed or unavailable</p>
                </div>
            </div>
            <div class="workflow-arrow">
                <i class="fas fa-arrow-right"></i>
            </div>
            <div class="workflow-step">
                <div class="step-number">3</div>
                <div class="step-content">
                    <h4>Completed</h4>
                    <p>Ready for printing and patient pickup</p>
                </div>
            </div>
        </div>

        <div class="prescription-management-container">
            <!-- Left Panel: Active Prescriptions -->
            <div class="prescription-panel active-panel">
                <div class="panel-header">
                    <div class="panel-title-section">
                        <div class="panel-icon pending">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="panel-title-content">
                            <h3>Active Prescriptions</h3>
                            <span class="panel-subtitle">Medications pending processing</span>
                        </div>
                    </div>
                    <div class="panel-badge pending">
                        <?= $totalPrescriptions ?> Active
                    </div>
                </div>

                <!-- Panel Description -->
                <div class="panel-info-card warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div class="info-content">
                        <strong>Action Required:</strong> These prescriptions have medications that need to be processed.
                        <br><small>Click "View/Update" to mark medications as dispensed or unavailable.</small>
                    </div>
                </div>

                <!-- Search and Filter Controls -->
                <div class="search-filters">
                    <input type="text" class="filter-input" id="searchPrescriptions" placeholder="Search by Patient ID, First Name, Last Name..." value="<?= htmlspecialchars($searchQuery) ?>" style="flex: 2;">
                    <input type="date" class="filter-input" id="dateFilter" placeholder="Date Prescribed" value="<?= htmlspecialchars($dateFilter) ?>" title="Filter by Date Prescribed">
                    <select class="filter-input" id="barangayFilter">
                        <option value="">All Barangays</option>
                        <?php
                        // Get unique barangays from barangay table
                        try {
                            $barangayQuery = "SELECT DISTINCT barangay_name FROM barangay WHERE status = 'active' ORDER BY barangay_name";
                            $barangayResult = $conn->query($barangayQuery);
                            if ($barangayResult) {
                                while ($barangay = $barangayResult->fetch_assoc()) {
                                    $selected = $barangayFilter === $barangay['barangay_name'] ? 'selected' : '';
                                    echo "<option value='" . htmlspecialchars($barangay['barangay_name']) . "' $selected>" . htmlspecialchars($barangay['barangay_name']) . "</option>";
                                }
                            }
                        } catch (Exception $e) {
                            // If barangay table doesn't exist, show default options
                            echo "<option value='Brgy. Assumption'>Brgy. Assumption</option>";
                            echo "<option value='Brgy. Carpenter Hill'>Brgy. Carpenter Hill</option>";
                            echo "<option value='Brgy. Concepcion'>Brgy. Concepcion</option>";
                        }
                        ?>
                    </select>
                    <!--<select class="filter-input" id="statusFilter">
                        <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Prescriptions</option>
                        <option value="issued" <?= $statusFilter === 'issued' ? 'selected' : '' ?>>Issued (Ready for Print)</option>
                        <option value="cancelled" <?= $statusFilter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>-->
                </div>
                <div class="search-filters" style="justify-content: flex-end; margin-top: -10px;">
                    <button type="button" class="filter-btn search-btn" id="searchBtn" onclick="applyFilters()">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <button type="button" class="filter-btn clear-btn" id="clearBtn" onclick="clearFilters()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>

                <!-- Prescriptions Table -->
                <?php if ($prescriptionsResult && $prescriptionsResult->num_rows > 0): ?>
                    <table class="prescription-table">
                        <thead>
                            <tr>
                                <th>Prescription ID</th>
                                <th>Patient Name</th>
                                <th>Date Prescribed</th>
                                <th>Medications</th>
                                <th>Progress</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($prescription = $prescriptionsResult->fetch_assoc()): ?>
                                <?php
                                $patientName = trim($prescription['first_name'] . ' ' . $prescription['middle_name'] . ' ' . $prescription['last_name']);
                                $doctorName = trim($prescription['prescribed_by_first_name'] . ' ' . $prescription['prescribed_by_last_name']);
                                $progressPercent = $prescription['total_medications'] > 0 ? round(($prescription['dispensed_medications'] / $prescription['total_medications']) * 100) : 0;
                                ?>
                                <tr data-prescription-id="<?= $prescription['prescription_id'] ?>" data-dispensed="<?= $prescription['dispensed_medications'] ?>" data-total="<?= $prescription['total_medications'] ?>" data-current-status="<?= $prescription['overall_status'] ?>">
                                    <td>
                                        <strong>RX-<?= sprintf('%06d', $prescription['prescription_id']) ?></strong>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($patientName) ?></strong><br>
                                        <small>ID: <?= htmlspecialchars($prescription['patient_id_display']) ?></small>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($prescription['prescription_date'])) ?></td>
                                    <td><?= $prescription['total_medications'] ?> medication(s)</td>
                                    <td>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?= $progressPercent ?>%"></div>
                                        </div>
                                        <small class="progress-text"><?= $prescription['dispensed_medications'] ?>/<?= $prescription['total_medications'] ?></small>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?= $prescription['overall_status'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $prescription['overall_status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="action-btn btn-view" onclick="viewUpdatePrescription(<?= $prescription['prescription_id'] ?>)">
                                            <?php if ($canUpdateMedications): ?>
                                                <i class="fas fa-edit"></i> View / Update
                                            <?php else: ?>
                                                <i class="fas fa-eye"></i> View Details
                                            <?php endif; ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>

                    <!-- Pagination for All Prescriptions -->
                    <?php
                    $totalPages = ceil($totalPrescriptions / $recordsPerPage);
                    if ($totalPages > 1):
                    ?>
                        <div class="pagination-container" style="margin: 24px; display: flex; justify-content: center; align-items: center; gap: 10px;">
                            <span style="font-size: 0.9em; color: #666;">
                                Showing <?= ($offset + 1) ?> to <?= min($offset + $recordsPerPage, $totalPrescriptions) ?> of <?= $totalPrescriptions ?> entries
                            </span>
                            <div class="pagination-buttons" style="display: flex; gap: 5px;">
                                <?php if ($page > 1): ?>
                                    <button onclick="goToPage(<?= $page - 1 ?>)" class="pagination-btn" style="padding: 5px 10px; border: 1px solid #ddd; background: white; cursor: pointer; border-radius: 3px;">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                    <button onclick="goToPage(<?= $i ?>)" class="pagination-btn <?= $i === $page ? 'active' : '' ?>"
                                        style="padding: 5px 10px; border: 1px solid #ddd; background: <?= $i === $page ? '#0077b6' : 'white' ?>; 
                                               color: <?= $i === $page ? 'white' : 'black' ?>; cursor: pointer; border-radius: 3px;">
                                        <?= $i ?>
                                    </button>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <button onclick="goToPage(<?= $page + 1 ?>)" class="pagination-btn" style="padding: 5px 10px; border: 1px solid #ddd; background: white; cursor: pointer; border-radius: 3px;">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-prescription-bottle-alt"></i>
                        <h3>No Prescriptions Found</h3>
                        <p>No prescriptions match your current filters or none have been created yet.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Panel: Completed Prescriptions -->
            <div class="prescription-panel completed-panel">
                <div class="panel-header">
                    <div class="panel-title-section">
                        <div class="panel-icon completed">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="panel-title-content">
                            <h3>Completed Prescriptions</h3>
                            <span class="panel-subtitle">Ready for printing and pickup</span>
                        </div>
                    </div>
                    <div class="panel-badge completed">
                        <?= $totalRecentDispensed ?> Completed
                    </div>
                </div>

                <!-- Panel Description -->
                <div class="panel-info-card success">
                    <i class="fas fa-check-circle"></i>
                    <div class="info-content">
                        <strong>All Set:</strong> All medications have been processed for these prescriptions.
                        <br><small>Click "View" to see details or print prescription copies.</small>
                    </div>
                </div>

                <!-- Search and Filter Controls for Recently Dispensed -->
                <div class="search-filters">
                    <input type="text" class="filter-input" id="recentSearchPrescriptions" placeholder="Search by Patient ID, First Name, Last Name..." value="<?= htmlspecialchars($recentSearchQuery) ?>" style="flex: 2;">
                    <input type="date" class="filter-input" id="recentDateFilter" placeholder="Dispensed Date" value="<?= htmlspecialchars($recentDateFilter) ?>" title="Filter by Date Dispensed">
                </div>
                <div class="search-filters" style="justify-content: flex-end; margin-top: -10px;">
                    <button type="button" class="filter-btn search-btn" onclick="applyRecentFilters()">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <button type="button" class="filter-btn clear-btn" onclick="clearRecentFilters()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>

                <!-- Recently Dispensed Prescriptions Table -->
                <?php if ($recentDispensedResult && $recentDispensedResult->num_rows > 0): ?>
                    <table class="prescription-table">
                        <thead>
                            <tr>
                                <th>Prescription ID</th>
                                <th>Patient Name</th>
                                <th>Date Dispensed</th>
                                <th>Pharmacist Name</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($dispensed = $recentDispensedResult->fetch_assoc()): ?>
                                <?php
                                $patientName = trim($dispensed['first_name'] . ' ' . $dispensed['middle_name'] . ' ' . $dispensed['last_name']);
                                $pharmacistName = trim($dispensed['pharmacist_first_name'] . ' ' . $dispensed['pharmacist_last_name']);
                                ?>
                                <tr>
                                    <td>
                                        <strong>RX-<?= sprintf('%06d', $dispensed['prescription_id']) ?></strong>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($patientName) ?></strong><br>
                                        <small>ID: <?= htmlspecialchars($dispensed['patient_id_display']) ?></small>
                                    </td>
                                    <td><?= date('M d, Y', strtotime($dispensed['dispensed_date'])) ?></td>
                                    <td><?= htmlspecialchars($pharmacistName ?: 'System') ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick="viewDispensedPrescription(<?= $dispensed['prescription_id'] ?>)">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>

                    <!-- Pagination for Recently Dispensed -->
                    <?php
                    $recentTotalPages = ceil($totalRecentDispensed / $recentRecordsPerPage);
                    if ($recentTotalPages > 1):
                    ?>
                        <div class="pagination-container" style="margin: 24px; display: flex; justify-content: center; align-items: center; gap: 10px;">
                            <span style="font-size: 0.9em; color: #666;">
                                Showing <?= ($recentOffset + 1) ?> to <?= min($recentOffset + $recentRecordsPerPage, $totalRecentDispensed) ?> of <?= $totalRecentDispensed ?> entries
                            </span>
                            <div class="pagination-buttons" style="display: flex; gap: 5px;">
                                <?php if ($recentPage > 1): ?>
                                    <button onclick="goToRecentPage(<?= $recentPage - 1 ?>)" class="pagination-btn" style="padding: 5px 10px; border: 1px solid #ddd; background: white; cursor: pointer; border-radius: 3px;">
                                        <i class="fas fa-chevron-left"></i>
                                    </button>
                                <?php endif; ?>

                                <?php for ($i = max(1, $recentPage - 2); $i <= min($recentTotalPages, $recentPage + 2); $i++): ?>
                                    <button onclick="goToRecentPage(<?= $i ?>)" class="pagination-btn <?= $i === $recentPage ? 'active' : '' ?>"
                                        style="padding: 5px 10px; border: 1px solid #ddd; background: <?= $i === $recentPage ? '#0077b6' : 'white' ?>; 
                                               color: <?= $i === $recentPage ? 'white' : 'black' ?>; cursor: pointer; border-radius: 3px;">
                                        <?= $i ?>
                                    </button>
                                <?php endfor; ?>

                                <?php if ($recentPage < $recentTotalPages): ?>
                                    <button onclick="goToRecentPage(<?= $recentPage + 1 ?>)" class="pagination-btn" style="padding: 5px 10px; border: 1px solid #ddd; background: white; cursor: pointer; border-radius: 3px;">
                                        <i class="fas fa-chevron-right"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-prescription-bottle"></i>
                        <h3>No Recently Dispensed Prescriptions</h3>
                        <p>No prescriptions have been dispensed recently.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Available Medications Modal -->
    <div id="availableMedicationsModal" class="modal">
        <div class="modal-content" style="max-width: 55%; max-height: 90%; overflow-y: hidden;">
            <div class="modal-header">
                <h3><i class="fas fa-pills"></i> PhilHealth GAMOT 2025 - Available Medications</h3>
                <button class="close-btn" onclick="closeModal('availableMedicationsModal')">&times;</button>
            </div>
            <div style="background: #e7f3ff; border: 1px solid #b3d9ff; padding: 10px; margin-bottom: 15px; border-radius: 5px;">
                <p style="margin: 0; font-size: 14px; color: #0066cc;">
                    <i class="fas fa-info-circle"></i> <strong>Instructions:</strong> Click on any medication row to copy its details for easy prescription creation.
                </p>
            </div>
            <div style="margin-bottom: 15px;">
                <input type="text" id="medicationSearch" placeholder="Search medications..."
                    style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"
                    onkeyup="filterMedications()">
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <table class="prescription-table" id="medicationsTable">
                    <thead>
                        <tr>
                            <th>Drug Name</th>
                            <th>Dosage Strength</th>
                            <th>Formulation</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- PhilHealth GAMOT 2025 Medications List -->
                        <tr onclick="selectMedication('Paracetamol', '500mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Paracetamol</td>
                            <td>500mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Ibuprofen', '400mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Ibuprofen</td>
                            <td>400mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Amoxicillin', '500mg', 'Capsule')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Amoxicillin</td>
                            <td>500mg</td>
                            <td>Capsule</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Cetirizine', '10mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Cetirizine</td>
                            <td>10mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Losartan', '50mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Losartan</td>
                            <td>50mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Amlodipine', '5mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Amlodipine</td>
                            <td>5mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Metformin', '500mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Metformin</td>
                            <td>500mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Simvastatin', '20mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Simvastatin</td>
                            <td>20mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Omeprazole', '20mg', 'Capsule')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Omeprazole</td>
                            <td>20mg</td>
                            <td>Capsule</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Salbutamol', '2mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Salbutamol</td>
                            <td>2mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Ferrous Sulfate', '325mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Ferrous Sulfate</td>
                            <td>325mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Mefenamic Acid', '250mg', 'Capsule')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Mefenamic Acid</td>
                            <td>250mg</td>
                            <td>Capsule</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Co-trimoxazole', '400mg/80mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Co-trimoxazole</td>
                            <td>400mg/80mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Dextromethorphan', '15mg', 'Syrup')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Dextromethorphan</td>
                            <td>15mg</td>
                            <td>Syrup</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Multivitamins', 'Various', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Multivitamins</td>
                            <td>Various</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Oral Rehydration Salt', '21.0g', 'Powder')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Oral Rehydration Salt</td>
                            <td>21.0g</td>
                            <td>Powder</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Zinc Sulfate', '20mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Zinc Sulfate</td>
                            <td>20mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Calcium Carbonate', '500mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Calcium Carbonate</td>
                            <td>500mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Aspirin', '80mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Aspirin</td>
                            <td>80mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Atenolol', '50mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Atenolol</td>
                            <td>50mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Furosemide', '40mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Furosemide</td>
                            <td>40mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Captopril', '25mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Captopril</td>
                            <td>25mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Gliclazide', '80mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Gliclazide</td>
                            <td>80mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Insulin (NPH)', '100 IU/ml', 'Vial')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Insulin (NPH)</td>
                            <td>100 IU/ml</td>
                            <td>Vial</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Insulin (Regular)', '100 IU/ml', 'Vial')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Insulin (Regular)</td>
                            <td>100 IU/ml</td>
                            <td>Vial</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Ranitidine', '150mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Ranitidine</td>
                            <td>150mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Diclofenac', '50mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Diclofenac</td>
                            <td>50mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Prednisolone', '5mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Prednisolone</td>
                            <td>5mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Dexamethasone', '0.5mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Dexamethasone</td>
                            <td>0.5mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                        <tr onclick="selectMedication('Hydrochlorothiazide', '25mg', 'Tablet')" style="cursor: pointer;" title="Click to select this medication">
                            <td>Hydrochlorothiazide</td>
                            <td>25mg</td>
                            <td>Tablet</td>
                            <td><button class="btn btn-sm" style="background: #28a745; color: white; font-size: 12px; padding: 4px 8px;">Select</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- View/Update Prescription Modal -->
    <div id="viewUpdatePrescriptionModal" class="modal">
        <div class="modal-content dispensed-modal-content">
            <div class="modal-header dispensed-modal-header" style="background: linear-gradient(135deg, #ff9500 0%, #ff6b35 100%);">
                <div class="modal-title-section">
                    <div class="modal-icon-badge pending">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div class="modal-title-content" style="text-align: left;">
                        <h3><?php if ($canUpdateMedications): ?>Update Prescription<?php else: ?>View Prescription<?php endif; ?></h3>
                        <span class="modal-subtitle"><?php if ($canUpdateMedications): ?>Mark medications as dispensed or unavailable<?php else: ?>View prescription details<?php endif; ?></span>
                    </div>
                </div>
                <div class="modal-actions">
                    <button class="action-btn download-btn" onclick="downloadPrescriptionPDF()" title="Download PDF">
                        <i class="fas fa-download"></i>
                        <span>Download PDF</span>
                    </button>
                    <button class="action-btn close-btn" onclick="closeModal('viewUpdatePrescriptionModal')" title="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="modal-body dispensed-modal-body">
                <div id="viewUpdatePrescriptionBody">
                    <!-- Content will be loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <!-- View Dispensed Prescription Modal -->
    <div id="viewDispensedModal" class="modal">
        <div class="modal-content dispensed-modal-content">
            <div class="modal-header dispensed-modal-header">
                <div class="modal-title-section">
                    <div class="modal-icon-badge completed">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="modal-title-content">
                        <h3>Completed Prescription</h3>
                        <span class="modal-subtitle">All medications have been processed</span>
                    </div>
                </div>
                <div class="modal-actions">
                    <button class="action-btn download-btn" onclick="downloadPrescriptionPDF()" title="Download PDF">
                        <i class="fas fa-download"></i>
                        <span>Download PDF</span>
                    </button>
                    <button class="action-btn close-btn" onclick="closeModal('viewDispensedModal')" title="Close">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="modal-body dispensed-modal-body">
                <div id="viewDispensedBody">
                    <!-- Content will be loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <!-- Print Prescription Modal -->
    <div id="printPrescriptionModal" class="modal">
        <div class="modal-content print-modal" style="max-width: 75%; max-height: 95%;">
            <div class="modal-header no-print">
                <h3><i class="fas fa-print"></i> Print Prescription</h3>
                <button class="close-btn" onclick="closeModal('printPrescriptionModal')">&times;</button>
            </div>
            <div id="printPrescriptionBody">
                <!-- Content will be loaded via AJAX -->
            </div>
            <div class="modal-footer no-print">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Print Prescription
                </button>
                <button class="btn btn-secondary" onclick="closeModal('printPrescriptionModal')">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Prescription Details Modal -->
    <div id="prescriptionDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Prescription Details</h3>
                <button class="close-btn" onclick="closeModal('prescriptionDetailsModal')">&times;</button>
            </div>
            <div id="modalBody">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Create Prescription Modal -->
    <div id="createPrescriptionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Prescription</h3>
                <!-- View Available Medications Button -->
                <div style="margin-bottom: 15px;">
                    <button type="button" class="btn btn-primary" onclick="openMedicationsModal()">
                        <i class="fas fa-pills"></i> View Available Medications
                    </button>
                </div>
                <button class="close-btn" onclick="closeModal('createPrescriptionModal')">&times;</button>
            </div>
            <div id="createPrescriptionBody">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Dispense Prescription Modal -->
    <div id="dispensePrescriptionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Dispense Prescription</h3>
                <button class="close-btn" onclick="closeModal('dispensePrescriptionModal')">&times;</button>
            </div>
            <div id="dispensePrescriptionBody">
                <!-- Content loaded via AJAX -->
            </div>
        </div>
    </div>

    <script>
        // Prescription Management JavaScript Functions

        // Permission variables from PHP
        const canUpdateMedications = <?= json_encode($canUpdateMedications) ?>;

        // Initialize event listeners when DOM is ready
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM Content Loaded - Prescription Management');

            // Check if all modals exist
            const modalIds = ['viewUpdatePrescriptionModal', 'viewDispensedModal', 'createPrescriptionModal', 'availableMedicationsModal'];
            modalIds.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (modal) {
                    console.log(`Modal found: ${modalId}`);
                } else {
                    console.error(`Modal missing: ${modalId}`);
                }
            });

            // Search and filter functionality - with null checks
            const statusFilter = document.getElementById('statusFilter');
            const barangayFilter = document.getElementById('barangayFilter');
            const dateFilter = document.getElementById('dateFilter');
            const searchPrescriptions = document.getElementById('searchPrescriptions');
            const recentSearchPrescriptions = document.getElementById('recentSearchPrescriptions');

            if (statusFilter) statusFilter.addEventListener('change', applyFilters);
            if (barangayFilter) barangayFilter.addEventListener('change', applyFilters);
            if (dateFilter) dateFilter.addEventListener('change', applyFilters);

            // Allow Enter key to trigger search
            if (searchPrescriptions) {
                searchPrescriptions.addEventListener('keypress', function(event) {
                    if (event.key === 'Enter') {
                        applyFilters();
                    }
                });
            }

            // Allow Enter key to trigger recent search
            if (recentSearchPrescriptions) {
                recentSearchPrescriptions.addEventListener('keypress', function(event) {
                    if (event.key === 'Enter') {
                        applyRecentFilters();
                    }
                });
            }
        });

        function applyFilters() {
            const searchElement = document.getElementById('searchPrescriptions');
            const barangayElement = document.getElementById('barangayFilter');
            const statusElement = document.getElementById('statusFilter');
            const dateElement = document.getElementById('dateFilter');

            const search = searchElement ? searchElement.value.trim() : '';
            const barangay = barangayElement ? barangayElement.value : '';
            const status = statusElement ? statusElement.value : '';
            const date = dateElement ? dateElement.value : '';

            const params = new URLSearchParams();
            if (search) params.set('search', search);
            if (barangay) params.set('barangay', barangay);
            if (status) params.set('status', status);
            if (date) params.set('date', date);

            window.location.href = '?' + params.toString();
        }

        function clearFilters() {
            const searchElement = document.getElementById('searchPrescriptions');
            const barangayElement = document.getElementById('barangayFilter');
            const statusElement = document.getElementById('statusFilter');
            const dateElement = document.getElementById('dateFilter');

            if (searchElement) searchElement.value = '';
            if (barangayElement) barangayElement.value = '';
            if (statusElement) statusElement.value = 'issued'; // Default to issued
            if (dateElement) dateElement.value = '';

            // Redirect to page without any filters
            window.location.href = window.location.pathname;
        }

        // Pagination functions for All Prescriptions
        function goToPage(page) {
            const searchElement = document.getElementById('searchPrescriptions');
            const barangayElement = document.getElementById('barangayFilter');
            const statusElement = document.getElementById('statusFilter');
            const dateElement = document.getElementById('dateFilter');

            const search = searchElement ? searchElement.value.trim() : '';
            const barangay = barangayElement ? barangayElement.value : '';
            const status = statusElement ? statusElement.value : '';
            const date = dateElement ? dateElement.value : '';

            const params = new URLSearchParams();
            if (search) params.set('search', search);
            if (barangay) params.set('barangay', barangay);
            if (status) params.set('status', status);
            if (date) params.set('date', date);
            params.set('page', page);

            window.location.href = '?' + params.toString();
        }

        // Recently Dispensed search and filter functions
        function applyRecentFilters() {
            const recentSearchElement = document.getElementById('recentSearchPrescriptions');
            const recentDateElement = document.getElementById('recentDateFilter');

            const recentSearch = recentSearchElement ? recentSearchElement.value.trim() : '';
            const recentDate = recentDateElement ? recentDateElement.value : '';

            // Get current main filters to preserve them
            const searchElement = document.getElementById('searchPrescriptions');
            const barangayElement = document.getElementById('barangayFilter');
            const statusElement = document.getElementById('statusFilter');
            const dateElement = document.getElementById('dateFilter');

            const search = searchElement ? searchElement.value.trim() : '';
            const barangay = barangayElement ? barangayElement.value : '';
            const status = statusElement ? statusElement.value : '';
            const date = dateElement ? dateElement.value : '';
            const page = <?= $page ?>;

            const params = new URLSearchParams();
            // Preserve main filters
            if (search) params.set('search', search);
            if (barangay) params.set('barangay', barangay);
            if (status) params.set('status', status);
            if (date) params.set('date', date);
            if (page) params.set('page', page);

            // Add recent filters
            if (recentSearch) params.set('recent_search', recentSearch);
            if (recentDate) params.set('recent_date', recentDate);

            window.location.href = '?' + params.toString();
        }

        function clearRecentFilters() {
            const recentSearchElement = document.getElementById('recentSearchPrescriptions');
            const recentDateElement = document.getElementById('recentDateFilter');

            if (recentSearchElement) recentSearchElement.value = '';
            if (recentDateElement) recentDateElement.value = '';

            // Get current main filters to preserve them
            const searchElement = document.getElementById('searchPrescriptions');
            const barangayElement = document.getElementById('barangayFilter');
            const statusElement = document.getElementById('statusFilter');
            const dateElement = document.getElementById('dateFilter');

            const search = searchElement ? searchElement.value.trim() : '';
            const barangay = barangayElement ? barangayElement.value : '';
            const status = statusElement ? statusElement.value : '';
            const date = dateElement ? dateElement.value : '';
            const page = <?= $page ?>;

            const params = new URLSearchParams();
            // Preserve main filters
            if (search) params.set('search', search);
            if (barangay) params.set('barangay', barangay);
            if (status) params.set('status', status);
            if (date) params.set('date', date);
            if (page) params.set('page', page);

            window.location.href = '?' + params.toString();
        }

        // Pagination functions for Recently Dispensed
        function goToRecentPage(page) {
            const recentSearchElement = document.getElementById('recentSearchPrescriptions');
            const recentDateElement = document.getElementById('recentDateFilter');

            const recentSearch = recentSearchElement ? recentSearchElement.value.trim() : '';
            const recentDate = recentDateElement ? recentDateElement.value : '';

            // Get current main filters to preserve them
            const searchElement = document.getElementById('searchPrescriptions');
            const barangayElement = document.getElementById('barangayFilter');
            const statusElement = document.getElementById('statusFilter');
            const dateElement = document.getElementById('dateFilter');

            const search = searchElement ? searchElement.value.trim() : '';
            const barangay = barangayElement ? barangayElement.value : '';
            const status = statusElement ? statusElement.value : '';
            const date = dateElement ? dateElement.value : '';
            const mainPage = <?= $page ?>;

            const params = new URLSearchParams();
            // Preserve main filters
            if (search) params.set('search', search);
            if (barangay) params.set('barangay', barangay);
            if (status) params.set('status', status);
            if (date) params.set('date', date);
            if (mainPage) params.set('page', mainPage);

            // Add recent filters and pagination
            if (recentSearch) params.set('recent_search', recentSearch);
            if (recentDate) params.set('recent_date', recentDate);
            params.set('recent_page', page);

            window.location.href = '?' + params.toString();
        }

        // Modal functions
        function closeModal(modalId) {
            console.log('closeModal called for:', modalId);
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                console.log('Modal closed successfully:', modalId);
            } else {
                console.error('Modal not found:', modalId);
            }
        }

        function viewPrescriptionDetails(prescriptionId) {
            document.getElementById('prescriptionDetailsModal').style.display = 'block';
            document.getElementById('modalBody').innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

            fetch(`api/get_prescription_details.php?prescription_id=${prescriptionId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('modalBody').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('modalBody').innerHTML = '<div class="alert alert-error">Error loading prescription details.</div>';
                });
        }

        function openCreatePrescriptionModal() {
            <?php if ($canCreatePrescriptions): ?>
                document.getElementById('createPrescriptionModal').style.display = 'block';
                document.getElementById('createPrescriptionBody').innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

                fetch('create_prescription.php')
                    .then(response => response.text())
                    .then(html => {
                        document.getElementById('createPrescriptionBody').innerHTML = html;

                        // Execute any scripts that were loaded with the content
                        const scripts = document.getElementById('createPrescriptionBody').getElementsByTagName('script');
                        const scriptArray = Array.from(scripts); // Convert to array to avoid live collection issues

                        scriptArray.forEach(script => {
                            try {
                                if (script.src) {
                                    // External script - create and append to document head
                                    const newScript = document.createElement('script');
                                    newScript.src = script.src;
                                    newScript.onload = function() {
                                        console.log('External script loaded:', script.src);
                                    };
                                    document.head.appendChild(newScript);
                                } else {
                                    // Inline script - execute in global context immediately
                                    const scriptContent = script.textContent;

                                    // Use eval to execute immediately in global scope
                                    window.eval(scriptContent);
                                    console.log('Inline script executed successfully in global scope');
                                }

                                // Remove the original script tag
                                script.parentNode.removeChild(script);
                            } catch (e) {
                                console.error('Error handling script:', e);
                            }
                        });

                        console.log('Create prescription modal content loaded and scripts executed');
                    })
                    .catch(error => {
                        console.error('Error loading create prescription form:', error);
                        document.getElementById('createPrescriptionBody').innerHTML = '<div class="alert alert-error">Error loading create prescription form.</div>';
                    });
            <?php else: ?>
                showAlert('You are not authorized to create prescriptions.', 'error');
            <?php endif; ?>
        }

        function closeCreatePrescriptionModal() {
            document.getElementById('createPrescriptionModal').style.display = 'none';
            document.getElementById('createPrescriptionBody').innerHTML = '';
        }

        function loadPrescriptions() {
            // Refresh the prescription list by reloading the page
            // In a more sophisticated implementation, this could use AJAX to reload just the prescription data
            window.location.reload();
        }

        // Make functions available globally for modal content
        window.closeCreatePrescriptionModal = closeCreatePrescriptionModal;
        window.loadPrescriptions = loadPrescriptions;
        window.showAlert = showAlert;

        function dispensePrescription(prescriptionId) {
            <?php if ($canDispensePrescriptions): ?>
                document.getElementById('dispensePrescriptionModal').style.display = 'block';
                document.getElementById('dispensePrescriptionBody').innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

                fetch(`dispense_prescription.php?prescription_id=${prescriptionId}`)
                    .then(response => response.text())
                    .then(html => {
                        document.getElementById('dispensePrescriptionBody').innerHTML = html;
                    })
                    .catch(error => {
                        document.getElementById('dispensePrescriptionBody').innerHTML = '<div class="alert alert-error">Error loading dispensing form.</div>';
                    });
            <?php else: ?>
                showAlert('You are not authorized to dispense prescriptions.', 'error');
            <?php endif; ?>
        }

        // New functions for prescription management
        function openMedicationsModal() {
            // Set higher z-index to appear above other modals
            const medicationsModal = document.getElementById('availableMedicationsModal');
            medicationsModal.style.display = 'block';
            medicationsModal.style.zIndex = '1100'; // Higher than default modal z-index

            // Focus on the search input for better UX
            setTimeout(() => {
                const searchInput = document.getElementById('medicationSearch');
                if (searchInput) {
                    searchInput.focus();
                }
            }, 100);
        }

        // View dispensed prescription function
        function viewDispensedPrescription(prescriptionId) {
            console.log('viewDispensedPrescription called with ID:', prescriptionId);

            // Set current prescription ID for PDF download
            updateCurrentPrescriptionId(prescriptionId);

            const modal = document.getElementById('viewDispensedModal');
            if (!modal) {
                console.error('Modal viewDispensedModal not found');
                return;
            }

            console.log('Showing viewDispensedModal');
            modal.style.display = 'block';
            modal.style.zIndex = '1000';

            const modalBody = document.getElementById('viewDispensedBody');
            if (modalBody) {
                modalBody.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            }

            fetch(`api/get_dispensed_prescription_view.php?prescription_id=${prescriptionId}`)
                .then(response => response.text())
                .then(html => {
                    const modalBody = document.getElementById('viewDispensedBody');
                    if (modalBody) {
                        modalBody.innerHTML = html;
                    }
                })
                .catch(error => {
                    console.error('Error loading prescription details:', error);
                    const modalBody = document.getElementById('viewDispensedBody');
                    if (modalBody) {
                        modalBody.innerHTML = '<div class="alert alert-error">Error loading prescription details.</div>';
                    }
                });
        }

        // Print prescription function
        function printPrescription(prescriptionId) {
            document.getElementById('printPrescriptionModal').style.display = 'block';
            document.getElementById('printPrescriptionBody').innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

            fetch(`api/get_printable_prescription.php?prescription_id=${prescriptionId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('printPrescriptionBody').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('printPrescriptionBody').innerHTML = '<div class="alert alert-error">Error loading prescription for printing.</div>';
                });
        }

        function filterMedications() {
            const searchTerm = document.getElementById('medicationSearch').value.toLowerCase();
            const table = document.getElementById('medicationsTable');
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let match = false;

                for (let j = 0; j < cells.length; j++) {
                    if (cells[j].textContent.toLowerCase().includes(searchTerm)) {
                        match = true;
                        break;
                    }
                }

                rows[i].style.display = match ? '' : 'none';
            }
        }

        function viewUpdatePrescription(prescriptionId) {
            console.log('viewUpdatePrescription called with ID:', prescriptionId);

            // Set current prescription ID for PDF download
            updateCurrentPrescriptionId(prescriptionId);

            const modal = document.getElementById('viewUpdatePrescriptionModal');
            if (!modal) {
                console.error('Modal viewUpdatePrescriptionModal not found');
                return;
            }

            console.log('Showing viewUpdatePrescriptionModal');
            modal.style.display = 'block';
            modal.style.zIndex = '1000';

            const modalBody = document.getElementById('viewUpdatePrescriptionBody');
            if (modalBody) {
                modalBody.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
            }

            // Load prescription details with update form
            fetch(`api/get_prescription_update_form.php?prescription_id=${prescriptionId}`)
                .then(response => response.text())
                .then(html => {
                    const modalBody = document.getElementById('viewUpdatePrescriptionBody');
                    if (modalBody) {
                        modalBody.innerHTML = html;
                    }

                    // Apply permission restrictions to the loaded content
                    if (!canUpdateMedications) {
                        // Add restriction notice
                        const restrictionNotice = `
                            <div class="alert alert-info" style="margin-bottom: 15px;">
                                <i class="fas fa-info-circle"></i> 
                                <strong>View Only Access:</strong> You can view prescription details but cannot update medication statuses. Only pharmacists can modify medication dispensing records.
                            </div>
                        `;
                        if (modalBody) {
                            modalBody.innerHTML = restrictionNotice + html;
                        }

                        // Disable all medication status checkboxes
                        const checkboxes = document.querySelectorAll('#viewUpdatePrescriptionBody input[type="checkbox"]');
                        checkboxes.forEach(checkbox => {
                            checkbox.disabled = true;
                            checkbox.style.opacity = '0.5';
                            checkbox.style.cursor = 'not-allowed';
                        });

                        // Disable submit buttons
                        const submitButtons = document.querySelectorAll('#viewUpdatePrescriptionBody button[type="submit"], #viewUpdatePrescriptionBody .btn-primary, #viewUpdatePrescriptionBody .btn-success');
                        submitButtons.forEach(button => {
                            button.disabled = true;
                            button.style.opacity = '0.5';
                            button.style.cursor = 'not-allowed';
                            button.title = 'Only pharmacists can update medication statuses';
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading prescription details:', error);
                    const modalBody = document.getElementById('viewUpdatePrescriptionBody');
                    if (modalBody) {
                        modalBody.innerHTML = '<div class="alert alert-error">Error loading prescription details.</div>';
                    }
                });
        }

        function showAlert(message, type = 'success') {
            const alertContainer = document.getElementById('alertContainer');
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.innerHTML = `
                <span><i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}</span>
                <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
            `;
            alertContainer.appendChild(alertDiv);

            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                if (alertDiv.parentElement) {
                    alertDiv.remove();
                }
            }, 5000);
        }

        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const modals = ['prescriptionDetailsModal', 'createPrescriptionModal', 'dispensePrescriptionModal', 'availableMedicationsModal', 'viewUpdatePrescriptionModal', 'viewDispensedModal', 'printPrescriptionModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    closeModal(modalId);
                }
            });
        });

        // Medication Status Update Functions (Global Scope)
        window.updateMedicationStatus = function(medicationId, statusType, isChecked) {
            console.log('updateMedicationStatus called:', medicationId, statusType, isChecked);

            // Check permission before allowing any updates
            if (!canUpdateMedications) {
                showAlert('You are not authorized to update medication statuses. Only pharmacists can perform this action.', 'error');
                // Revert the checkbox change
                const checkbox = document.getElementById(statusType + '_' + medicationId);
                if (checkbox) {
                    checkbox.checked = !isChecked;
                }
                return;
            }

            // Check if the checkbox is disabled (medication already processed)
            const checkbox = document.getElementById(statusType + '_' + medicationId);
            if (checkbox && checkbox.disabled) {
                showAlert('This medication has already been processed and cannot be changed. Medication statuses can only be set once for audit integrity.', 'error');
                // Revert the checkbox change
                checkbox.checked = !isChecked;
                return;
            }

            // Additional check: See if medication is already processed by checking the other status
            const otherType = statusType === 'dispensed' ? 'unavailable' : 'dispensed';
            const otherCheckbox = document.getElementById(otherType + '_' + medicationId);
            const statusElement = document.getElementById('status_' + medicationId);

            // If the status element shows the medication is already processed, prevent changes
            if (statusElement) {
                const currentStatus = statusElement.textContent.toLowerCase();
                if ((currentStatus === 'dispensed' || currentStatus === 'unavailable') && !isChecked) {
                    // Only allow unchecking if we're reverting before submission
                    // But if it's already saved to database, prevent any changes
                    console.log('Medication appears to be already processed:', currentStatus);
                }
            }

            try {
                // Prevent both dispensed and unavailable from being checked at the same time
                if (isChecked) {
                    if (otherCheckbox && otherCheckbox.checked && !otherCheckbox.disabled) {
                        otherCheckbox.checked = false;
                    }
                }

                // Update the status display
                if (statusElement) {
                    if (isChecked) {
                        statusElement.textContent = statusType === 'dispensed' ? 'Dispensed' : 'Unavailable';
                        statusElement.className = 'status-' + statusType;
                    } else {
                        // Check if the other status is checked
                        if (otherCheckbox && otherCheckbox.checked) {
                            statusElement.textContent = otherType === 'dispensed' ? 'Dispensed' : 'Unavailable';
                            statusElement.className = 'status-' + otherType;
                        } else {
                            statusElement.textContent = 'Pending';
                            statusElement.className = 'status-pending';
                        }
                    }
                }
            } catch (error) {
                console.error('Error in updateMedicationStatus:', error);
                showAlert('Error updating medication status: ' + error.message, 'error');
            }
        };

        window.updateMedicationStatuses = function(event) {
            console.log('updateMedicationStatuses called');

            // Check permission before allowing any updates
            if (!canUpdateMedications) {
                showAlert('You are not authorized to update medication statuses. Only pharmacists can perform this action.', 'error');
                event.preventDefault();
                return;
            }

            try {
                event.preventDefault();

                const formData = new FormData(event.target);
                const prescriptionId = formData.get('prescription_id');

                if (!prescriptionId) {
                    showAlert('Error: Prescription ID not found', 'error');
                    return;
                }

                // Collect all medication statuses
                const medicationStatuses = [];
                const checkboxes = document.querySelectorAll('input[type="checkbox"]');

                // Also check for any disabled checkboxes (already processed medications)
                let hasProcessedMedications = false;
                const processedMedications = [];

                checkboxes.forEach(checkbox => {
                    const parts = checkbox.id.split('_');
                    const statusType = parts[0];
                    const prescribedMedicationId = parts[1];

                    // Check if this medication is already processed (disabled checkbox)
                    if (checkbox.disabled && checkbox.checked) {
                        hasProcessedMedications = true;
                        processedMedications.push(`Medication ID ${prescribedMedicationId} (${statusType})`);
                    }

                    if (checkbox.checked && (statusType === 'dispensed' || statusType === 'unavailable') && !checkbox.disabled) {
                        medicationStatuses.push({
                            prescribed_medication_id: prescribedMedicationId,
                            status: statusType
                        });
                    }
                });

                // If there are processed medications, show a warning but allow the update for pending ones
                if (hasProcessedMedications) {
                    console.log('Found processed medications:', processedMedications);
                    showAlert('Note: Some medications have already been processed and will not be updated. Only pending medications will be updated.', 'info');
                }

                console.log('Sending medication statuses:', medicationStatuses);

                // Send update request
                fetch('../../api/update_prescription_medications.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            prescription_id: prescriptionId,
                            medication_statuses: medicationStatuses
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log('API response:', data);

                        if (data.success) {
                            showAlert(data.message, 'success');

                            // Debug: Log prescription status update info
                            console.log('Prescription status updated:', data.prescription_status_updated);
                            console.log('New status:', data.new_status);
                            console.log('Details:', data.details);

                            // If prescription status was updated (completed), refresh the page
                            if (data.prescription_status_updated && data.new_status === 'issued') {
                                showAlert('Prescription completed! Moving to recently dispensed...', 'info');

                                // Close modal and refresh immediately
                                setTimeout(() => {
                                    closeModal('viewUpdatePrescriptionModal');
                                    window.location.reload();
                                }, 1500);
                            } else if (data.new_status === 'in_progress') {
                                // Prescription is still in progress - just close modal, no page refresh needed
                                showAlert('Prescription status updated. Some medications still pending.', 'info');
                                setTimeout(() => {
                                    closeModal('viewUpdatePrescriptionModal');
                                }, 1500);
                            } else {
                                // Fallback: Always refresh after any medication update to ensure UI is current
                                setTimeout(() => {
                                    closeModal('viewUpdatePrescriptionModal');
                                    window.location.reload();
                                }, 1500);
                            }
                        } else {
                            showAlert('Error updating medication statuses: ' + data.message, 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Fetch error:', error);
                        showAlert('Error updating medication statuses: ' + error.message, 'error');
                    });

            } catch (error) {
                console.error('Error in updateMedicationStatuses:', error);
                showAlert('Error updating medication statuses: ' + error.message, 'error');
            }
        };

        // PDF Download Functionality
        let currentPrescriptionId = null;

        // Update the current prescription ID when viewing a prescription
        function updateCurrentPrescriptionId(prescriptionId) {
            currentPrescriptionId = prescriptionId;
        }

        window.downloadPrescriptionPDF = function() {
            if (!currentPrescriptionId) {
                showAlert('No prescription selected for download', 'error');
                return;
            }

            try {
                // Open the prescription in a new window optimized for printing/PDF saving
                const printWindow = window.open(
                    `api/get_prescription_pdf.php?prescription_id=${currentPrescriptionId}`,
                    '_blank',
                    'width=900,height=700,scrollbars=yes,resizable=yes,toolbar=no,menubar=no'
                );

                if (printWindow) {
                    // Focus the new window
                    printWindow.focus();
                    showAlert('Prescription opened in new window. Use Ctrl+P to print or save as PDF.', 'success');
                } else {
                    showAlert('Please allow popups to download prescription PDF', 'error');
                }

            } catch (error) {
                showAlert('Error opening prescription PDF: ' + error.message, 'error');
            }
        };

        // Medication Selection Function
        window.selectMedication = function(drugName, dosage, formulation) {
            // Create medication selection data
            const medicationData = {
                name: drugName,
                dosage: dosage,
                formulation: formulation
            };

            // Show confirmation and copy to clipboard
            const medicationText = `${drugName} ${dosage} ${formulation}`;

            // Try to copy to clipboard
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(medicationText).then(() => {
                    showAlert(`Selected: ${medicationText} (copied to clipboard)`, 'success');
                }).catch(() => {
                    showAlert(`Selected: ${medicationText}`, 'success');
                });
            } else {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = medicationText;
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    showAlert(`Selected: ${medicationText} (copied to clipboard)`, 'success');
                } catch (err) {
                    showAlert(`Selected: ${medicationText}`, 'success');
                }
                document.body.removeChild(textArea);
            }

            // Try to auto-fill prescription form if it exists
            try {
                // Look for medication name input field in the create prescription form
                const medicationNameField = document.querySelector('#createPrescriptionBody input[name*="medication_name"], #createPrescriptionBody input[placeholder*="medication"], #createPrescriptionBody input[placeholder*="drug"]');
                const dosageField = document.querySelector('#createPrescriptionBody input[name*="dosage"], #createPrescriptionBody input[placeholder*="dosage"]');

                if (medicationNameField) {
                    medicationNameField.value = medicationData.name;
                    medicationNameField.dispatchEvent(new Event('input', {
                        bubbles: true
                    }));
                    medicationNameField.dispatchEvent(new Event('change', {
                        bubbles: true
                    }));
                }

                if (dosageField) {
                    dosageField.value = medicationData.dosage;
                    dosageField.dispatchEvent(new Event('input', {
                        bubbles: true
                    }));
                    dosageField.dispatchEvent(new Event('change', {
                        bubbles: true
                    }));
                }

                // If fields were auto-filled, show additional message
                if (medicationNameField || dosageField) {
                    setTimeout(() => {
                        showAlert('Medication details auto-filled in prescription form!', 'info');
                    }, 1000);
                }
            } catch (error) {
                console.log('Could not auto-fill form fields:', error);
            }

            // Close the medications modal after selection
            setTimeout(() => {
                closeModal('availableMedicationsModal');
            }, 1500);
        };

        // Enhanced close modal function to handle z-index reset
        const originalCloseModal = closeModal; // Reference the function, not window.closeModal
        window.closeModal = function(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
                // Reset z-index for medications modal
                if (modalId === 'availableMedicationsModal') {
                    modal.style.zIndex = '';
                }
            }
        };

        // Automatic Status Update Functions for Prescriptions
        function checkAndUpdatePrescriptionStatuses() {
            console.log('=== Starting Prescription Status Check ===');

            // Get all prescription rows with data attributes
            const prescriptionRows = document.querySelectorAll('tbody tr[data-prescription-id]');
            console.log(`Found ${prescriptionRows.length} prescription rows`);

            prescriptionRows.forEach((row, index) => {
                const prescriptionId = row.getAttribute('data-prescription-id');
                const dispensed = parseInt(row.getAttribute('data-dispensed'));
                const total = parseInt(row.getAttribute('data-total'));
                const currentStatus = row.getAttribute('data-current-status');

                console.log(`Row ${index + 1}:`, {
                    prescriptionId,
                    dispensed,
                    total,
                    currentStatus,
                    dataAttributes: {
                        'data-prescription-id': row.getAttribute('data-prescription-id'),
                        'data-dispensed': row.getAttribute('data-dispensed'),
                        'data-total': row.getAttribute('data-total'),
                        'data-current-status': row.getAttribute('data-current-status')
                    }
                });

                if (!prescriptionId || isNaN(dispensed) || isNaN(total)) {
                    console.log(`Skipping row ${index + 1} - missing or invalid data`);
                    return;
                }

                let shouldUpdateTo = null;

                // Core logic: Only update to dispensed when ALL medications are dispensed
                if (dispensed === total && total > 0 && currentStatus !== 'dispensed') {
                    shouldUpdateTo = 'dispensed';
                    console.log(`Row ${index + 1}: Should update to dispensed (${dispensed}/${total} dispensed, current: ${currentStatus})`);
                }
                // If some dispensed but not all, update to issued only from active
                else if (dispensed > 0 && dispensed < total && currentStatus === 'active') {
                    shouldUpdateTo = 'issued';
                    console.log(`Row ${index + 1}: Should update to issued (${dispensed}/${total} dispensed, current: ${currentStatus})`);
                }
                // If no medications dispensed, should remain active (no update needed)
                else {
                    console.log(`Row ${index + 1}: No update needed (${dispensed}/${total} dispensed, current: ${currentStatus})`);
                }

                // Update status if needed
                if (shouldUpdateTo) {
                    console.log(`Auto-updating prescription ${prescriptionId} from ${currentStatus} to ${shouldUpdateTo}`);
                    updatePrescriptionStatusAutomatically(prescriptionId, shouldUpdateTo, row);
                } else {
                    console.log(`No update required for prescription ${prescriptionId}`);
                }
            });

            console.log('=== Prescription Status Check Complete ===');
        }

        // Function to automatically update prescription status
        function updatePrescriptionStatusAutomatically(prescriptionId, newStatus, rowElement) {
            // Use relative path from prescription-management directory
            const apiPath = '../../api/update_prescription_status.php';

            console.log(`Calling API: ${apiPath} for prescription ${prescriptionId} -> ${newStatus}`);

            fetch(apiPath, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        prescription_id: prescriptionId,
                        overall_status: newStatus,
                        remarks: 'Auto-updated based on medication dispensing status',
                        auto_update: true
                    })
                })
                .then(response => {
                    console.log(`Response status: ${response.status}, Content-Type: ${response.headers.get('content-type')}`);

                    // Check if response is actually JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        // Response is not JSON, probably an error page
                        return response.text().then(text => {
                            console.error('Server returned non-JSON response:', text.substring(0, 500));
                            throw new Error(`Server error: Expected JSON but received ${contentType || 'unknown content type'}`);
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('API Response:', data);

                    if (data.success) {
                        // Update the status badge in the UI immediately
                        const statusBadge = rowElement.querySelector('.status-badge');
                        if (statusBadge) {
                            statusBadge.className = `status-badge status-${newStatus}`;
                            statusBadge.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1).replace('_', ' ');
                        }

                        // Update data attribute
                        rowElement.setAttribute('data-current-status', newStatus);

                        // Show success notification
                        console.log(`✅ Prescription #${prescriptionId} status automatically updated to ${newStatus}`);

                        // Optionally show a subtle notification
                        showAlert(`Prescription #${prescriptionId} status automatically updated to ${newStatus.replace('_', ' ')}`, 'success');

                    } else {
                        console.error('❌ Failed to update prescription status:', data.message);
                    }
                })
                .catch(error => {
                    console.error('❌ Error updating prescription status:', error.message);
                    // Don't show error alerts for automatic updates to avoid spam
                    // But log the full error for debugging
                    console.error('Full error details:', error);
                });
        }

        // Run automatic status check on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Run after a short delay to ensure page is fully loaded
            setTimeout(checkAndUpdatePrescriptionStatuses, 1000);
        });

        // Re-run status check after modal operations (dispensing, etc.)
        function refreshStatusChecks() {
            setTimeout(checkAndUpdatePrescriptionStatuses, 500);
        }

        // Handle server-side messages
        <?php if (isset($_SESSION['prescription_message'])): ?>
            showAlert('<?= addslashes($_SESSION['prescription_message']) ?>', '<?= $_SESSION['prescription_message_type'] ?? 'success' ?>');
        <?php
            unset($_SESSION['prescription_message']);
            unset($_SESSION['prescription_message_type']);
        endif; ?>
    </script>
</body>

</html>