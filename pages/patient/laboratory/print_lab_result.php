<?php
// Prevent direct access
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    header('Location: ../auth/patient_login.php');
    exit();
}

// Get the root path
$root_path = dirname(dirname(dirname(__DIR__)));

// Include database connection
require_once $root_path . '/config/db.php';

// Get result ID from request
$result_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$result_id) {
    die('Invalid result ID');
}

$patient_username = $_SESSION['patient_id']; // This is actually the username like "P000007"

// Get the actual numeric patient_id from the username
$patient_id = null;
try {
    $patientStmt = $conn->prepare("SELECT patient_id FROM patients WHERE username = ?");
    $patientStmt->bind_param("s", $patient_username);
    $patientStmt->execute();
    $patientResult = $patientStmt->get_result()->fetch_assoc();
    if (!$patientResult) {
        die('Patient not found');
    }
    $patient_id = $patientResult['patient_id'];
    $patientStmt->close();
} catch (Exception $e) {
    die('Database error');
}

try {
    // Fetch lab result for printing with security check using correct schema
    $stmt = $conn->prepare("
        SELECT 
            lo.lab_order_id,
            lo.order_date,
            lo.status,
            lo.remarks,
            CONCAT(e.first_name, ' ', e.last_name) as doctor_name,
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.date_of_birth,
            p.sex as gender,
            c.consultation_date,
            GROUP_CONCAT(DISTINCT loi.test_type SEPARATOR ', ') as test_types,
            MAX(loi.result_date) as latest_result_date,
            COUNT(loi.item_id) as test_count
        FROM lab_orders lo
        LEFT JOIN consultations c ON lo.consultation_id = c.consultation_id
        LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
        LEFT JOIN patients p ON lo.patient_id = p.patient_id
        LEFT JOIN lab_order_items loi ON lo.lab_order_id = loi.lab_order_id
        WHERE lo.lab_order_id = ? 
        AND lo.patient_id = ?
        AND lo.status = 'completed'
        GROUP BY lo.lab_order_id
    ");
    
    $stmt->bind_param("ii", $result_id, $patient_id);
    $stmt->execute();
    $result_query = $stmt->get_result();
    $result = $result_query->fetch_assoc();
    
    if (!$result) {
        die('Lab result not found');
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Database error in print_lab_result.php: " . $e->getMessage());
    die('Database error occurred');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Result - <?php echo htmlspecialchars($result['test_type']); ?></title>
    <style>
        body {
            font-family: 'Times New Roman', serif;
            margin: 0;
            padding: 20px;
            background: white;
            color: #333;
        }
        
        .print-header {
            text-align: center;
            border-bottom: 2px solid #0984e3;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        
        .print-header h1 {
            color: #0984e3;
            margin: 0;
            font-size: 28px;
        }
        
        .print-header h2 {
            color: #666;
            margin: 5px 0 0 0;
            font-size: 18px;
            font-weight: normal;
        }
        
        .result-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .info-section h3 {
            color: #0984e3;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
        }
        
        .info-label {
            font-weight: bold;
            min-width: 120px;
            color: #555;
        }
        
        .info-value {
            flex: 1;
        }
        
        .result-content {
            background: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .result-content h3 {
            color: #0984e3;
            margin-top: 0;
            margin-bottom: 15px;
        }
        
        .result-text {
            white-space: pre-wrap;
            line-height: 1.6;
            font-size: 14px;
        }
        
        .print-footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 15px;
            }
            
            .no-print {
                display: none !important;
            }
        }
        
        .print-button {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #0984e3;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            z-index: 1000;
        }
        
        .print-button:hover {
            background: #0771c7;
        }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print
    </button>
    
    <div class="print-header">
        <h1>City Health Office - Koronadal</h1>
        <h2>Laboratory Test Result</h2>
    </div>
    
    <div class="result-info">
        <div class="info-section">
            <h3>Patient Information</h3>
            <div class="info-row">
                <span class="info-label">Name:</span>
                <span class="info-value"><?php echo htmlspecialchars($result['patient_name']); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Date of Birth:</span>
                <span class="info-value"><?php echo date('F j, Y', strtotime($result['date_of_birth'])); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Gender:</span>
                <span class="info-value"><?php echo ucfirst($result['gender']); ?></span>
            </div>
        </div>
        
        <div class="info-section">
            <h3>Test Information</h3>
            <div class="info-row">
                <span class="info-label">Test Types:</span>
                <span class="info-value"><?php echo htmlspecialchars($result['test_types'] ?: 'No tests specified'); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Test Count:</span>
                <span class="info-value"><?php echo $result['test_count']; ?> test(s)</span>
            </div>
            <div class="info-row">
                <span class="info-label">Order Date:</span>
                <span class="info-value"><?php echo date('F j, Y', strtotime($result['order_date'])); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Latest Result Date:</span>
                <span class="info-value"><?php echo $result['latest_result_date'] ? date('F j, Y', strtotime($result['latest_result_date'])) : 'Pending'; ?></span>
            </div>
            <?php if (!empty($result['doctor_name'])): ?>
            <div class="info-row">
                <span class="info-label">Ordered by:</span>
                <span class="info-value">Dr. <?php echo htmlspecialchars($result['doctor_name']); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="result-content">
        <h3>Lab Order Summary</h3>
        <div class="result-text">
            <p><strong>Order ID:</strong> <?php echo $result['lab_order_id']; ?></p>
            <p><strong>Status:</strong> <?php echo ucfirst(str_replace('_', ' ', $result['status'])); ?></p>
            <p><strong>Test Types:</strong> <?php echo htmlspecialchars($result['test_types'] ?: 'No tests specified'); ?></p>
            <p>For detailed test results and files, please access the patient portal or contact the laboratory.</p>
        </div>
    </div>
    
    <?php if (!empty($result['remarks'])): ?>
    <div class="result-content">
        <h3>Remarks</h3>
        <div class="result-text">
            <?php echo nl2br(htmlspecialchars($result['remarks'])); ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="print-footer">
        <p>Printed on <?php echo date('F j, Y g:i A'); ?></p>
        <p>City Health Office - Koronadal | Laboratory Services</p>
    </div>
    
    <script>
        // Auto-print when page loads (optional)
        // window.onload = function() { window.print(); };
    </script>
</body>
</html>