<?php
// Alternative approach: Generate a simple HTML report that can be saved as PDF by the browser

// Database and session setup
$root_path = dirname(__DIR__, 2);
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Authentication check
if (!is_employee_logged_in()) {
    header('Location: ' . $root_path . '/pages/management/auth/login.php');
    exit();
}

// Role-based access control  
$allowed_roles = ['admin', 'dho', 'cashier', 'records_officer'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    header('Location: ' . $root_path . '/pages/management/dashboard.php');
    exit();
}

// Get filter parameters
$filter_type = $_GET['filter_type'] ?? 'month_range';
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');

// Build date range text
$date_range_text = '';
if ($filter_type === 'month_range') {
    $date_range_text = 'Period: ' . date('F j, Y', strtotime($date_from)) . ' - ' . date('F j, Y', strtotime($date_to));
} else {
    $date_range_text = 'Period: ' . date('F j, Y', strtotime($date_from)) . ' - ' . date('F j, Y', strtotime($date_to));
}

// Fetch metrics data (same as original)
try {
    // Key metrics query
    $metrics_query = "
        SELECT 
            COUNT(*) as total_referrals,
            SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_referrals,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_referrals,
            SUM(CASE WHEN destination_type = 'external' THEN 1 ELSE 0 END) as external_issued,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as active_referrals
        FROM referrals 
        WHERE DATE(referral_date) BETWEEN ? AND ?
    ";
    
    $stmt = $pdo->prepare($metrics_query);
    $stmt->execute([$date_from, $date_to]);
    $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate completion rate
    $completion_rate = $metrics['total_referrals'] > 0 
        ? round(($metrics['accepted_referrals'] / $metrics['total_referrals']) * 100, 1) 
        : 0;

    // Get facility transfers data
    $facility_query = "
        SELECT 
            rf.name as referring_facility,
            COALESCE(tf.name, r.external_facility_name, 'External Facility') as referred_to_facility,
            COUNT(*) as total_referrals,
            SUM(CASE WHEN r.status = 'accepted' THEN 1 ELSE 0 END) as accepted,
            SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN r.status = 'issued' THEN 1 ELSE 0 END) as issued
        FROM referrals r
        LEFT JOIN facilities rf ON r.referring_facility_id = rf.facility_id
        LEFT JOIN facilities tf ON r.referred_to_facility_id = tf.facility_id
        WHERE DATE(r.referral_date) BETWEEN ? AND ?
        GROUP BY r.referring_facility_id, r.referred_to_facility_id, r.external_facility_name
        ORDER BY total_referrals DESC
        LIMIT 10
    ";
    
    $facility_stmt = $pdo->prepare($facility_query);
    $facility_stmt->execute([$date_from, $date_to]);
    $facility_transfers = $facility_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get destination types data
    $destination_query = "
        SELECT 
            destination_type,
            COUNT(*) as count,
            ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM referrals WHERE DATE(referral_date) BETWEEN ? AND ?)), 2) as percentage
        FROM referrals r
        WHERE DATE(r.referral_date) BETWEEN ? AND ?
        GROUP BY destination_type
        ORDER BY count DESC
    ";
    
    $dest_stmt = $pdo->prepare($destination_query);
    $dest_stmt->execute([$date_from, $date_to, $date_from, $date_to]);
    $destination_types = $dest_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set headers for direct PDF download
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Referral_Summary_Report_' . date('Y-m-d_H-i-s') . '.html"');

} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Referral Summary Report</title>
    <style>
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            margin: 20px;
            color: #000;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #000;
            padding-bottom: 15px;
        }
        .header h1 {
            font-size: 18px;
            margin: 0 0 8px 0;
            font-weight: bold;
        }
        .header h2 {
            font-size: 16px;
            margin: 0 0 5px 0;
            font-weight: bold;
        }
        .header p {
            font-size: 11px;
            margin: 3px 0;
        }
        .section {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        .section h2 {
            font-size: 14px;
            font-weight: bold;
            margin: 15px 0 10px 0;
            border-bottom: 2px solid #333;
            padding-bottom: 3px;
        }
        .metrics-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        .metric-box {
            border: 2px solid #000;
            padding: 15px;
            flex: 1;
            min-width: 200px;
            text-align: center;
        }
        .metric-value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            display: block;
        }
        .metric-label {
            font-size: 11px;
            color: #666;
            margin-top: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 10px;
        }
        table th,
        table td {
            border: 1px solid #000;
            padding: 8px 6px;
            text-align: left;
        }
        table th {
            background: #f0f0f0;
            font-weight: bold;
            font-size: 11px;
        }
        .instructions {
            background: #ffffcc;
            border: 1px solid #ccc;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .instructions h3 {
            margin: 0 0 10px 0;
            color: #333;
        }
    </style>
</head>
<body>
    <div class="instructions no-print">
        <h3>🖨️ PDF Generation Instructions</h3>
        <p><strong>To save as PDF:</strong> Press <kbd>Ctrl+P</kbd> (or <kbd>Cmd+P</kbd> on Mac), then select "Save as PDF" as the destination.</p>
        <p><strong>For best results:</strong> Set margins to "Minimum" and enable "Background graphics" in print options.</p>
    </div>

    <div class="header">
        <h1>REFERRAL SUMMARY REPORT</h1>
        <h2>Republic of the Philippines</h2>
        <h2>City Health Office</h2>
        <h2>Koronadal City</h2>
        <p><strong><?= htmlspecialchars($date_range_text) ?></strong></p>
        <p>Generated: <?= date('F j, Y \a\t g:i A') ?></p>
        <p>Generated by: <?= htmlspecialchars(($_SESSION['first_name'] ?? 'Unknown') . ' ' . ($_SESSION['last_name'] ?? 'User')) ?> (<?= htmlspecialchars($_SESSION['role'] ?? 'Unknown') ?>)</p>
    </div>

    <div class="section">
        <h2>I. KEY METRICS SUMMARY</h2>
        <div class="metrics-grid">
            <div class="metric-box">
                <span class="metric-value"><?= number_format($metrics['total_referrals']) ?></span>
                <div class="metric-label">Total Referrals</div>
            </div>
            <div class="metric-box">
                <span class="metric-value"><?= number_format($metrics['accepted_referrals']) ?></span>
                <div class="metric-label">Accepted Referrals</div>
            </div>
            <div class="metric-box">
                <span class="metric-value"><?= number_format($metrics['cancelled_referrals']) ?></span>
                <div class="metric-label">Cancelled Referrals</div>
            </div>
            <div class="metric-box">
                <span class="metric-value"><?= number_format($metrics['external_issued']) ?></span>
                <div class="metric-label">External Issued</div>
            </div>
            <div class="metric-box">
                <span class="metric-value"><?= number_format($metrics['active_referrals']) ?></span>
                <div class="metric-label">Active Referrals</div>
            </div>
            <div class="metric-box">
                <span class="metric-value"><?= $completion_rate ?>%</span>
                <div class="metric-label">Completion Rate</div>
            </div>
        </div>
    </div>

    <?php if (!empty($facility_transfers)): ?>
    <div class="section">
        <h2>II. FACILITY-TO-FACILITY TRANSFERS (TOP 10)</h2>
        <table>
            <tr>
                <th>Referring Facility</th>
                <th>Referred To Facility</th>
                <th>Total</th>
                <th>Accepted</th>
                <th>Cancelled</th>
                <th>Issued</th>
            </tr>
            <?php foreach ($facility_transfers as $transfer): ?>
            <tr>
                <td><?= htmlspecialchars($transfer['referring_facility'] ?: 'Unknown') ?></td>
                <td><?= htmlspecialchars($transfer['referred_to_facility']) ?></td>
                <td><?= number_format($transfer['total_referrals']) ?></td>
                <td><?= number_format($transfer['accepted']) ?></td>
                <td><?= number_format($transfer['cancelled']) ?></td>
                <td><?= number_format($transfer['issued']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($destination_types)): ?>
    <div class="section">
        <h2>III. DESTINATION TYPE DISTRIBUTION</h2>
        <table>
            <tr>
                <th>Destination Type</th>
                <th>Count</th>
                <th>Percentage</th>
            </tr>
            <?php foreach ($destination_types as $dest): ?>
            <tr>
                <td><?= ucwords(str_replace('_', ' ', htmlspecialchars($dest['destination_type']))) ?></td>
                <td><?= number_format($dest['count']) ?></td>
                <td><?= number_format($dest['percentage'], 1) ?>%</td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>

    <div class="section">
        <h2>REPORT SUMMARY</h2>
        <p>This report contains referral data for the selected period. The data shows the performance metrics for the City Health Office referral system.</p>
        <p><strong>Generated on:</strong> <?= date('F j, Y \a\t g:i A') ?> by the City Health Office, Koronadal City.</p>
        <p><strong>For questions about this report:</strong> Please contact the City Health Office.</p>
    </div>

    <script class="no-print">
        // Auto-trigger print dialog after page loads
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 1000);
        };
    </script>
</body>
</html>