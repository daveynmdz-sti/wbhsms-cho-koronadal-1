<?php
/**
 * Historical Demographics API
 * Handles snapshot generation, retrieval, and comparison
 */

// Include session and database configuration
$root_path = dirname(__DIR__);
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';
require_once $root_path . '/utils/HistoricalDemographicsService.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Check user permissions
$allowedRoles = ['admin', 'dho', 'records_officer'];
$userRole = $_SESSION['role'] ?? '';

if (!in_array($userRole, $allowedRoles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient permissions']);
    exit();
}

// Initialize service
$service = new HistoricalDemographicsService($conn, $pdo);

// Handle different actions
$action = $_GET['action'] ?? $_POST['action'] ?? '';

header('Content-Type: application/json');

switch ($action) {
    case 'generate_snapshot':
        $type = $_POST['type'] ?? 'manual';
        $notes = $_POST['notes'] ?? '';
        $employeeId = $_SESSION['employee_id'];
        
        $result = $service->generateSnapshot($type, $notes, $employeeId);
        echo json_encode($result);
        break;

    case 'list_snapshots':
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $snapshots = $service->getSnapshotsList($limit);
        echo json_encode(['success' => true, 'snapshots' => $snapshots]);
        break;

    case 'get_snapshot':
        $snapshotId = $_GET['snapshot_id'] ?? '';
        if (empty($snapshotId)) {
            echo json_encode(['error' => 'Snapshot ID is required']);
            break;
        }
        
        $snapshot = $service->getSnapshotData($snapshotId);
        if ($snapshot) {
            echo json_encode(['success' => true, 'snapshot' => $snapshot]);
        } else {
            echo json_encode(['error' => 'Snapshot not found']);
        }
        break;

    case 'compare_snapshots':
        $snapshot1Id = $_GET['snapshot1_id'] ?? '';
        $snapshot2Id = $_GET['snapshot2_id'] ?? '';
        
        if (empty($snapshot1Id) || empty($snapshot2Id)) {
            echo json_encode(['error' => 'Both snapshot IDs are required']);
            break;
        }
        
        $comparison = $service->compareSnapshots($snapshot1Id, $snapshot2Id);
        if ($comparison) {
            echo json_encode(['success' => true, 'comparison' => $comparison]);
        } else {
            echo json_encode(['error' => 'One or both snapshots not found']);
        }
        break;

    case 'delete_snapshot':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['error' => 'POST method required']);
            break;
        }
        
        $snapshotId = $_POST['snapshot_id'] ?? '';
        if (empty($snapshotId)) {
            echo json_encode(['error' => 'Snapshot ID is required']);
            break;
        }
        
        // Only admins can delete snapshots
        if ($userRole !== 'admin') {
            echo json_encode(['error' => 'Only administrators can delete snapshots']);
            break;
        }
        
        $result = $service->deleteSnapshot($snapshotId);
        echo json_encode($result);
        break;

    case 'get_trends':
        $metric = $_GET['metric'] ?? 'total_patients';
        $period = $_GET['period'] ?? '12 months';
        
        $trends = $service->getTrendData($metric, $period);
        echo json_encode(['success' => true, 'trends' => $trends]);
        break;

    case 'auto_generate_quarterly':
        // Check if quarterly snapshot already exists for current quarter
        $currentQuarter = ceil(date('n') / 3);
        $currentYear = date('Y');
        $quarterStart = date('Y-m-d', mktime(0, 0, 0, ($currentQuarter - 1) * 3 + 1, 1, $currentYear));
        
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM report_snapshots 
            WHERE snapshot_type = 'quarterly' 
            AND snapshot_date >= ?
        ");
        $stmt->bind_param("s", $quarterStart);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->fetch_assoc()['count'] > 0;
        
        if ($exists) {
            echo json_encode(['error' => 'Quarterly snapshot already exists for this quarter']);
        } else {
            $notes = "Automated quarterly snapshot - Q$currentQuarter $currentYear";
            $result = $service->generateSnapshot('quarterly', $notes, $_SESSION['employee_id']);
            echo json_encode($result);
        }
        break;

    case 'auto_generate_annual':
        // Check if annual snapshot already exists for current year
        $currentYear = date('Y');
        $yearStart = date('Y-01-01');
        
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM report_snapshots 
            WHERE snapshot_type = 'annual' 
            AND snapshot_date >= ?
        ");
        $stmt->bind_param("s", $yearStart);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->fetch_assoc()['count'] > 0;
        
        if ($exists) {
            echo json_encode(['error' => 'Annual snapshot already exists for this year']);
        } else {
            $notes = "Automated annual snapshot - $currentYear";
            $result = $service->generateSnapshot('annual', $notes, $_SESSION['employee_id']);
            echo json_encode($result);
        }
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}
?>