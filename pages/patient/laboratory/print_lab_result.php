<?php
// Include patient session configuration
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    http_response_code(401);
    exit('Unauthorized: Please log in to print lab results.');
}

// Database connection
require_once $root_path . '/config/db.php';

$patient_id = $_SESSION['patient_id'];
$item_id = $_GET['item_id'] ?? null;

if (!$item_id || !is_numeric($item_id)) {
    http_response_code(400);
    exit('Valid lab result item ID is required');
}

if (!$patient_id || !is_numeric($patient_id)) {
    http_response_code(401);
    exit('Invalid patient session');
}

try {
    // Use PDO for better error handling and consistency
    if (!isset($pdo)) {
        throw new Exception("Database connection not available");
    }
    
    // Fetch the lab result with patient verification and additional details
    $sql = "SELECT loi.item_id, loi.test_type, loi.result_date, loi.remarks,
                   p.first_name, p.last_name, p.username as patient_id_display,
                   p.date_of_birth, p.sex, p.contact_number,
                   b.barangay_name,
                   lo.lab_order_id, lo.order_date,
                   e.first_name as doctor_first_name, e.last_name as doctor_last_name,
                   e.license_number as doctor_license,
                   f.name as facility_name
            FROM lab_order_items loi
            INNER JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id  
            INNER JOIN patients p ON lo.patient_id = p.patient_id
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            LEFT JOIN employees e ON lo.ordered_by_employee_id = e.employee_id
            LEFT JOIN facilities f ON e.facility_id = f.facility_id
            WHERE loi.item_id = :item_id AND lo.patient_id = :patient_id AND loi.result_file IS NOT NULL";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':item_id', $item_id, PDO::PARAM_INT);
    $stmt->bindParam(':patient_id', $patient_id, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$data) {
        http_response_code(404);
        exit('Lab result not found or access denied');
    }
    
    // Log the print action for audit trail
    try {
        // Create audit table if it doesn't exist (patient-specific)
        $create_audit_sql = "CREATE TABLE IF NOT EXISTS `lab_result_patient_view_logs` (
            `log_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
            `lab_item_id` int UNSIGNED NOT NULL,
            `patient_id` int UNSIGNED NOT NULL,
            `patient_name` varchar(255) DEFAULT NULL,
            `viewed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text DEFAULT NULL,
            PRIMARY KEY (`log_id`),
            UNIQUE KEY `unique_view_log` (`lab_item_id`, `patient_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($create_audit_sql);
        
        // Insert audit log
        $audit_sql = "INSERT INTO lab_result_patient_view_logs (lab_item_id, patient_id, viewed_at, patient_name, ip_address, user_agent) 
                      VALUES (:item_id, :patient_id, NOW(), :patient_name, :ip_address, :user_agent)
                      ON DUPLICATE KEY UPDATE viewed_at = NOW()";
        $audit_stmt = $pdo->prepare($audit_sql);
        $patient_name = trim($data['first_name'] . ' ' . $data['last_name']);
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $audit_stmt->bindParam(':item_id', $item_id, PDO::PARAM_INT);
        $audit_stmt->bindParam(':patient_id', $patient_id, PDO::PARAM_INT);
        $audit_stmt->bindParam(':patient_name', $patient_name, PDO::PARAM_STR);
        $audit_stmt->bindParam(':ip_address', $ip_address, PDO::PARAM_STR);
        $audit_stmt->bindParam(':user_agent', $user_agent, PDO::PARAM_STR);
        $audit_stmt->execute();
    } catch (Exception $audit_error) {
        error_log("Print audit logging failed: " . $audit_error->getMessage());
    }
    
} catch (Exception $e) {
    error_log("Patient print preparation error: " . $e->getMessage());
    http_response_code(500);
    exit('Error preparing lab result for printing');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laboratory Result - <?php echo htmlspecialchars($data['test_type']); ?></title>
    <style>
        /* Print-friendly styles */
        @media print {
            body { margin: 0; font-size: 12pt; }
            .no-print { display: none !important; }
            .page-break { page-break-after: always; }
        }

        /* General styles */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: white;
        }

        .print-header {
            text-align: center;
            border-bottom: 3px solid #0077b6;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .print-header h1 {
            color: #0077b6;
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .print-header .facility-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 5px;
        }

        .print-header .print-date {
            color: #6c757d;
            font-size: 0.9rem;
        }

        .result-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .result-info h2 {
            color: #0077b6;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-item label {
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
            margin-bottom: 3px;
        }

        .info-item span {
            color: #212529;
            padding: 5px 0;
            border-bottom: 1px dotted #dee2e6;
        }

        .result-section {
            border: 2px solid #0077b6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
            background: #f8faff;
        }

        .result-section h3 {
            color: #0077b6;
            text-align: center;
            font-size: 1.3rem;
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .result-content {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 20px;
            min-height: 200px;
            text-align: center;
        }

        .result-placeholder {
            color: #6c757d;
            font-style: italic;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 200px;
        }

        .result-placeholder i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #dee2e6;
        }

        .signature-section {
            margin-top: 50px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 50px;
        }

        .signature-box {
            text-align: center;
            border-top: 2px solid #333;
            padding-top: 10px;
        }

        .signature-box .label {
            font-weight: 600;
            color: #495057;
        }

        .signature-box .name {
            color: #0077b6;
            font-weight: 700;
        }

        .print-controls {
            text-align: center;
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        .print-btn {
            background: #0077b6;
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            margin: 0 10px;
            transition: all 0.3s ease;
        }

        .print-btn:hover {
            background: #005a8b;
            transform: translateY(-2px);
        }

        .print-btn.secondary {
            background: #6c757d;
        }

        .print-btn.secondary:hover {
            background: #545b62;
        }

        .disclaimer {
            font-size: 0.8rem;
            color: #6c757d;
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
            font-style: italic;
        }

        @media (max-width: 600px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .signature-section {
                grid-template-columns: 1fr;
                gap: 30px;
            }
            
            body {
                padding: 10px;
            }
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Print Controls -->
    <div class="print-controls no-print">
        <button onclick="window.print()" class="print-btn">
            <i class="fas fa-print"></i> Print Result
        </button>
        <button onclick="window.close()" class="print-btn secondary">
            <i class="fas fa-times"></i> Close
        </button>
    </div>

    <!-- Print Header -->
    <div class="print-header">
        <h1>Laboratory Test Result</h1>
        <div class="facility-name">
            <?php echo htmlspecialchars($data['facility_name'] ?? 'WBHSMS - City Health Office'); ?>
        </div>
        <div class="print-date">
            Printed on: <?php echo date('F j, Y g:i A'); ?>
        </div>
    </div>

    <!-- Patient Information -->
    <div class="result-info">
        <h2><i class="fas fa-user"></i> Patient Information</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Patient Name:</label>
                <span><?php echo htmlspecialchars($data['first_name'] . ' ' . $data['last_name']); ?></span>
            </div>
            <div class="info-item">
                <label>Patient ID:</label>
                <span><?php echo htmlspecialchars($data['patient_id_display']); ?></span>
            </div>
            <div class="info-item">
                <label>Birth Date:</label>
                <span>
                    <?php 
                    if ($data['date_of_birth']) {
                        $age = date_diff(date_create($data['date_of_birth']), date_create('now'))->y;
                        echo date('F j, Y', strtotime($data['date_of_birth'])) . " (Age: {$age})";
                    } else {
                        echo 'Not specified';
                    }
                    ?>
                </span>
            </div>
            <div class="info-item">
                <label>Gender:</label>
                <span><?php echo htmlspecialchars(ucfirst($data['sex'] ?? 'Not specified')); ?></span>
            </div>
            <div class="info-item">
                <label>Address:</label>
                <span><?php echo htmlspecialchars($data['barangay_name'] ?? 'Not specified'); ?></span>
            </div>
            <div class="info-item">
                <label>Contact:</label>
                <span><?php echo htmlspecialchars($data['contact_number'] ?? 'Not specified'); ?></span>
            </div>
        </div>
    </div>

    <!-- Test Information -->
    <div class="result-info">
        <h2><i class="fas fa-flask"></i> Test Information</h2>
        <div class="info-grid">
            <div class="info-item">
                <label>Test Type:</label>
                <span><?php echo htmlspecialchars($data['test_type']); ?></span>
            </div>
            <div class="info-item">
                <label>Order Date:</label>
                <span><?php echo date('F j, Y g:i A', strtotime($data['order_date'])); ?></span>
            </div>
            <div class="info-item">
                <label>Result Date:</label>
                <span><?php echo date('F j, Y g:i A', strtotime($data['result_date'])); ?></span>
            </div>
            <div class="info-item">
                <label>Ordered By:</label>
                <span>
                    <?php if (!empty($data['doctor_first_name'])): ?>
                        Dr. <?php echo htmlspecialchars($data['doctor_first_name'] . ' ' . $data['doctor_last_name']); ?>
                        <?php if (!empty($data['doctor_license'])): ?>
                            <br><small>License: <?php echo htmlspecialchars($data['doctor_license']); ?></small>
                        <?php endif; ?>
                    <?php else: ?>
                        System Generated
                    <?php endif; ?>
                </span>
            </div>
            <?php if (!empty($data['remarks'])): ?>
                <div class="info-item" style="grid-column: 1 / -1;">
                    <label>Remarks:</label>
                    <span><?php echo htmlspecialchars($data['remarks']); ?></span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Result Section -->
    <div class="result-section">
        <h3>Laboratory Result</h3>
        <div class="result-content">
            <div class="result-placeholder">
                <i class="fas fa-file-pdf"></i>
                <h4>Digital Result Available</h4>
                <p>The complete digital result file is available for viewing and download through the patient portal.</p>
                <p><strong>Result ID:</strong> <?php echo htmlspecialchars($data['item_id']); ?></p>
                <p><strong>Test:</strong> <?php echo htmlspecialchars($data['test_type']); ?></p>
            </div>
        </div>
    </div>

    <!-- Signature Section -->
    <div class="signature-section">
        <div class="signature-box">
            <div class="label">Laboratory Technologist</div>
            <div class="name">_________________________</div>
            <small>Signature over Printed Name</small>
        </div>
        <div class="signature-box">
            <div class="label">Pathologist/Doctor</div>
            <div class="name">
                <?php if (!empty($data['doctor_first_name'])): ?>
                    Dr. <?php echo htmlspecialchars($data['doctor_first_name'] . ' ' . $data['doctor_last_name']); ?>
                <?php else: ?>
                    _________________________
                <?php endif; ?>
            </div>
            <small>Signature over Printed Name</small>
        </div>
    </div>

    <!-- Disclaimer -->
    <div class="disclaimer">
        This is a computer-generated document. For complete digital results, please access the patient portal or contact the laboratory directly.
        <br>Document generated on <?php echo date('F j, Y g:i A'); ?> for patient verification purposes.
    </div>

    <script>
        // Auto-print on load if requested
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('auto_print') === '1') {
            setTimeout(() => {
                window.print();
            }, 1000);
        }
    </script>
</body>
</html>