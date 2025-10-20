<?php
// Billing Reports - Financial Analytics and Dashboard

// Start output buffering to prevent header issues
if (!ob_get_level()) {
    ob_start();
}

$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in and has cashier/admin privileges
if (!is_employee_logged_in()) {
    header("Location: ../auth/employee_login.php");
    exit();
}

$employee_role = get_employee_session('role');
if (!in_array($employee_role, ['cashier', 'admin'])) {
    header("Location: ../dashboard.php?error=Access denied");
    exit();
}

$employee_id = get_employee_session('employee_id');
$employee_name = get_employee_session('first_name') . ' ' . get_employee_session('last_name');

$message = isset($_GET['message']) ? htmlspecialchars($_GET['message']) : '';
$error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';

// Get current date ranges
$today = date('Y-m-d');
$week_start = date('Y-m-d', strtotime('monday this week'));
$month_start = date('Y-m-01');
$year_start = date('Y-01-01');

try {
    // Daily collections
    $stmt = $pdo->prepare("
        SELECT 
            DATE(r.payment_date) as payment_date,
            COUNT(*) as transaction_count,
            SUM(r.amount_paid) as total_collected
        FROM receipts r
        WHERE DATE(r.payment_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAYS)
        GROUP BY DATE(r.payment_date)
        ORDER BY payment_date DESC
    ");
    $stmt->execute();
    $daily_collections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary statistics
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN DATE(r.payment_date) = CURDATE() THEN r.amount_paid END), 0) as today_collections,
            COUNT(CASE WHEN DATE(r.payment_date) = CURDATE() THEN 1 END) as today_transactions,
            COALESCE(SUM(CASE WHEN DATE(r.payment_date) >= ? THEN r.amount_paid END), 0) as week_collections,
            COUNT(CASE WHEN DATE(r.payment_date) >= ? THEN 1 END) as week_transactions,
            COALESCE(SUM(CASE WHEN DATE(r.payment_date) >= ? THEN r.amount_paid END), 0) as month_collections,
            COUNT(CASE WHEN DATE(r.payment_date) >= ? THEN 1 END) as month_transactions,
            COALESCE(SUM(CASE WHEN DATE(r.payment_date) >= ? THEN r.amount_paid END), 0) as year_collections,
            COUNT(CASE WHEN DATE(r.payment_date) >= ? THEN 1 END) as year_transactions
        FROM receipts r
    ");
    $stmt->execute([$week_start, $week_start, $month_start, $month_start, $year_start, $year_start]);
    $summary_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Outstanding balances
    $stmt = $pdo->query("
        SELECT 
            COUNT(CASE WHEN payment_status = 'unpaid' THEN 1 END) as unpaid_invoices,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN total_amount END), 0) as total_outstanding,
            COUNT(CASE WHEN payment_status = 'unpaid' AND DATEDIFF(CURDATE(), billing_date) <= 30 THEN 1 END) as current_30,
            COUNT(CASE WHEN payment_status = 'unpaid' AND DATEDIFF(CURDATE(), billing_date) BETWEEN 31 AND 60 THEN 1 END) as aging_31_60,
            COUNT(CASE WHEN payment_status = 'unpaid' AND DATEDIFF(CURDATE(), billing_date) BETWEEN 61 AND 90 THEN 1 END) as aging_61_90,
            COUNT(CASE WHEN payment_status = 'unpaid' AND DATEDIFF(CURDATE(), billing_date) > 90 THEN 1 END) as aging_over_90,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' AND DATEDIFF(CURDATE(), billing_date) <= 30 THEN total_amount END), 0) as amount_current_30,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' AND DATEDIFF(CURDATE(), billing_date) BETWEEN 31 AND 60 THEN total_amount END), 0) as amount_31_60,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' AND DATEDIFF(CURDATE(), billing_date) BETWEEN 61 AND 90 THEN total_amount END), 0) as amount_61_90,
            COALESCE(SUM(CASE WHEN payment_status = 'unpaid' AND DATEDIFF(CURDATE(), billing_date) > 90 THEN total_amount END), 0) as amount_over_90
        FROM billing 
        WHERE payment_status = 'unpaid'
    ");
    $outstanding_stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Revenue by service
    $stmt = $pdo->query("
        SELECT 
            si.item_name,
            si.category,
            COUNT(bi.billing_item_id) as service_count,
            SUM(bi.quantity * bi.unit_price) as total_revenue
        FROM billing_items bi
        JOIN service_items si ON bi.service_item_id = si.service_item_id
        JOIN billing b ON bi.billing_id = b.billing_id
        WHERE b.billing_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAYS)
        GROUP BY bi.service_item_id
        ORDER BY total_revenue DESC
        LIMIT 10
    ");
    $service_revenue = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Payment methods analysis
    $stmt = $pdo->query("
        SELECT 
            payment_method,
            COUNT(*) as transaction_count,
            SUM(amount_paid) as total_amount
        FROM receipts 
        WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAYS)
        GROUP BY payment_method
        ORDER BY total_amount DESC
    ");
    $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Top patients by revenue
    $stmt = $pdo->query("
        SELECT 
            CONCAT(p.first_name, ' ', p.last_name) as patient_name,
            p.patient_id,
            COUNT(b.billing_id) as total_invoices,
            SUM(b.total_amount) as total_billed,
            SUM(COALESCE(r_sum.total_paid, 0)) as total_paid
        FROM patients p
        JOIN billing b ON p.patient_id = b.patient_id
        LEFT JOIN (
            SELECT billing_id, SUM(amount_paid) as total_paid 
            FROM receipts 
            GROUP BY billing_id
        ) r_sum ON b.billing_id = r_sum.billing_id
        WHERE b.billing_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAYS)
        GROUP BY p.patient_id
        ORDER BY total_billed DESC
        LIMIT 10
    ");
    $top_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Billing reports error: " . $e->getMessage());
    $daily_collections = [];
    $summary_stats = [
        'today_collections' => 0, 'today_transactions' => 0,
        'week_collections' => 0, 'week_transactions' => 0,
        'month_collections' => 0, 'month_transactions' => 0,
        'year_collections' => 0, 'year_transactions' => 0
    ];
    $outstanding_stats = [
        'unpaid_invoices' => 0, 'total_outstanding' => 0,
        'current_30' => 0, 'aging_31_60' => 0, 'aging_61_90' => 0, 'aging_over_90' => 0,
        'amount_current_30' => 0, 'amount_31_60' => 0, 'amount_61_90' => 0, 'amount_over_90' => 0
    ];
    $service_revenue = [];
    $payment_methods = [];
    $top_patients = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Reports - CHO Koronadal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../../assets/css/sidebar.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .reports-container {
            margin-left: 300px;
            padding: 2rem;
        }
        
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .summary-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            border-left: 4px solid var(--accent-color, #007bff);
        }
        
        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--accent-color, #007bff);
        }
        
        .summary-card.today { --accent-color: #007bff; }
        .summary-card.week { --accent-color: #28a745; }
        .summary-card.month { --accent-color: #ffc107; }
        .summary-card.year { --accent-color: #dc3545; }
        
        .summary-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--accent-color, #007bff);
        }
        
        .summary-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .summary-sublabel {
            color: #999;
            font-size: 0.8rem;
        }
        
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .report-panel {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }
        
        .panel-header {
            padding: 1.5rem;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .panel-title {
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .panel-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .panel-content {
            padding: 1.5rem;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin: 1rem 0;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        
        .data-table th,
        .data-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        .data-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .data-table tr:hover {
            background: #f8f9fa;
        }
        
        .progress-bar {
            background: #e9ecef;
            border-radius: 4px;
            height: 8px;
            overflow: hidden;
            margin: 0.5rem 0;
        }
        
        .progress-fill {
            height: 100%;
            transition: width 0.3s ease;
        }
        
        .progress-fill.current { background: #28a745; }
        .progress-fill.aging-30 { background: #ffc107; }
        .progress-fill.aging-60 { background: #fd7e14; }
        .progress-fill.aging-90 { background: #dc3545; }
        
        .aging-breakdown {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .aging-item {
            text-align: center;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .aging-value {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .aging-label {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 6px;
            background: #007bff;
            color: white;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
        }
        
        .btn-primary {
            background: #007bff;
        }
        
        @media (max-width: 768px) {
            .reports-container {
                margin-left: 0;
                padding: 1rem;
            }
            
            .reports-grid {
                grid-template-columns: 1fr;
            }
            
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <?php
    $activePage = 'billing';
    // Include appropriate sidebar based on user role
    if ($employee_role === 'admin') {
        include '../../../includes/sidebar_admin.php';
    } else {
        include '../../../includes/sidebar_cashier.php';
    }
    ?>

<div class="homepage">
    <div style="margin-left: 260px; padding: 20px; min-height: 100vh; background-color: #f5f5f5;">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 25px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
            <h1 style="margin: 0; font-size: 2rem; font-weight: 700;">Billing Reports</h1>
            <p style="margin: 5px 0 0 0; opacity: 0.9; font-size: 1rem;">Financial analytics and revenue insights</p>
        </div>

        <!-- Summary Statistics -->
        <div class="summary-grid">
            <div class="summary-card today">
                <div class="summary-value">₱<?php echo number_format($summary_stats['today_collections'], 2); ?></div>
                <div class="summary-label">Today's Collections</div>
                <div class="summary-sublabel"><?php echo $summary_stats['today_transactions']; ?> transactions</div>
            </div>
            
            <div class="summary-card week">
                <div class="summary-value">₱<?php echo number_format($summary_stats['week_collections'], 2); ?></div>
                <div class="summary-label">This Week</div>
                <div class="summary-sublabel"><?php echo $summary_stats['week_transactions']; ?> transactions</div>
            </div>
            
            <div class="summary-card month">
                <div class="summary-value">₱<?php echo number_format($summary_stats['month_collections'], 2); ?></div>
                <div class="summary-label">This Month</div>
                <div class="summary-sublabel"><?php echo $summary_stats['month_transactions']; ?> transactions</div>
            </div>
            
            <div class="summary-card year">
                <div class="summary-value">₱<?php echo number_format($summary_stats['year_collections'], 2); ?></div>
                <div class="summary-label">This Year</div>
                <div class="summary-sublabel"><?php echo $summary_stats['year_transactions']; ?> transactions</div>
            </div>
        </div>

        <!-- Reports Grid -->
        <div class="reports-grid">
            <!-- Daily Collections Chart -->
            <div class="report-panel">
                <div class="panel-header">
                    <h3 class="panel-title">Daily Collections (Last 30 Days)</h3>
                </div>
                <div class="panel-content">
                    <canvas id="dailyCollectionsChart"></canvas>
                </div>
            </div>

            <!-- Outstanding Balances -->
            <div class="report-panel">
                <div class="panel-header">
                    <h3 class="panel-title">Outstanding Balances</h3>
                    <span class="summary-value" style="font-size: 1.2rem;">₱<?php echo number_format($outstanding_stats['total_outstanding'], 2); ?></span>
                </div>
                <div class="panel-content">
                    <div class="aging-breakdown">
                        <div class="aging-item">
                            <div class="aging-value" style="color: #28a745;">₱<?php echo number_format($outstanding_stats['amount_current_30'], 2); ?></div>
                            <div class="aging-label">Current (0-30 days)</div>
                        </div>
                        <div class="aging-item">
                            <div class="aging-value" style="color: #ffc107;">₱<?php echo number_format($outstanding_stats['amount_31_60'], 2); ?></div>
                            <div class="aging-label">31-60 days</div>
                        </div>
                        <div class="aging-item">
                            <div class="aging-value" style="color: #fd7e14;">₱<?php echo number_format($outstanding_stats['amount_61_90'], 2); ?></div>
                            <div class="aging-label">61-90 days</div>
                        </div>
                        <div class="aging-item">
                            <div class="aging-value" style="color: #dc3545;">₱<?php echo number_format($outstanding_stats['amount_over_90'], 2); ?></div>
                            <div class="aging-label">Over 90 days</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Services by Revenue -->
            <div class="report-panel">
                <div class="panel-header">
                    <h3 class="panel-title">Top Services by Revenue</h3>
                </div>
                <div class="panel-content">
                    <canvas id="serviceRevenueChart"></canvas>
                </div>
            </div>

            <!-- Payment Methods -->
            <div class="report-panel">
                <div class="panel-header">
                    <h3 class="panel-title">Payment Methods</h3>
                </div>
                <div class="panel-content">
                    <canvas id="paymentMethodsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Top Patients Table -->
        <div class="report-panel">
            <div class="panel-header">
                <h3 class="panel-title">Top Patients by Revenue (Last 30 Days)</h3>
            </div>
            <div class="panel-content">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Patient Name</th>
                                <th>Total Invoices</th>
                                <th>Total Billed</th>
                                <th>Total Paid</th>
                                <th>Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_patients as $patient): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($patient['patient_name']); ?></td>
                                <td><?php echo $patient['total_invoices']; ?></td>
                                <td>₱<?php echo number_format($patient['total_billed'], 2); ?></td>
                                <td>₱<?php echo number_format($patient['total_paid'], 2); ?></td>
                                <td>₱<?php echo number_format($patient['total_billed'] - $patient['total_paid'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
    
    <script>
        // Daily Collections Chart
        const dailyCtx = document.getElementById('dailyCollectionsChart').getContext('2d');
        const dailyData = <?php echo json_encode($daily_collections); ?>;
        
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: dailyData.map(d => d.payment_date),
                datasets: [{
                    label: 'Collections',
                    data: dailyData.map(d => d.total_collected),
                    borderColor: '#007bff',
                    backgroundColor: 'rgba(0, 123, 255, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Service Revenue Chart
        const serviceCtx = document.getElementById('serviceRevenueChart').getContext('2d');
        const serviceData = <?php echo json_encode($service_revenue); ?>;
        
        new Chart(serviceCtx, {
            type: 'bar',
            data: {
                labels: serviceData.map(s => s.item_name),
                datasets: [{
                    label: 'Revenue',
                    data: serviceData.map(s => s.total_revenue),
                    backgroundColor: '#28a745'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '₱' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });

        // Payment Methods Chart
        const paymentCtx = document.getElementById('paymentMethodsChart').getContext('2d');
        const paymentData = <?php echo json_encode($payment_methods); ?>;
        
        new Chart(paymentCtx, {
            type: 'doughnut',
            data: {
                labels: paymentData.map(p => p.payment_method.charAt(0).toUpperCase() + p.payment_method.slice(1)),
                datasets: [{
                    data: paymentData.map(p => p.total_amount),
                    backgroundColor: ['#007bff', '#28a745', '#ffc107', '#dc3545', '#6f42c1']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    </script>
</body>
</html>