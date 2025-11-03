<?php
// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Check if user is logged in
if (!isset($_SESSION['employee_id'])) {
    header("Location: ../management/auth/employee_login.php");
    exit();
}

// Define role-based permissions
$allowedRoles = ['admin', 'dho', 'cashier', 'records_officer'];
$userRole = $_SESSION['role'] ?? '';

if (!in_array($userRole, $allowedRoles)) {
    $role = strtolower($userRole);
    header("Location: ../management/$role/dashboard.php");
    exit();
}

// Initialize variables
$total_patients = 0;
$age_distribution = [];
$gender_distribution = [];
$barangay_distribution = [];
$district_distribution = [];
$philhealth_distribution = [];
$philhealth_overall = [];
$pwd_count = 0;
$pwd_percentage = 0;

try {
    // Get total active patients
    $total_query = "SELECT COUNT(*) as total FROM patients WHERE status = 'active'";
    $total_result = $conn->query($total_query);
    $total_patients = $total_result->fetch_assoc()['total'];

    // Age distribution
    $age_query = "
        SELECT 
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 1 THEN 'Infants (0-1)'
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 1 AND 4 THEN 'Toddlers (1-4)'
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 'Children (5-12)'
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 'Teens (13-17)'
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 'Young Adults (18-35)'
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 'Adults (36-59)'
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) >= 60 THEN 'Seniors (60+)'
                ELSE 'Unknown'
            END as age_group,
            COUNT(*) as count
        FROM patients 
        WHERE status = 'active' AND date_of_birth IS NOT NULL
        GROUP BY age_group
        ORDER BY 
            CASE 
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) < 1 THEN 1
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 1 AND 4 THEN 2
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 3
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 4
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 5
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 6
                WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) >= 60 THEN 7
                ELSE 8
            END
    ";
    $age_result = $conn->query($age_query);
    while ($row = $age_result->fetch_assoc()) {
        $age_distribution[] = $row;
    }

    // Gender distribution
    $gender_query = "
        SELECT 
            sex as gender,
            COUNT(*) as count
        FROM patients 
        WHERE status = 'active'
        GROUP BY sex
    ";
    $gender_result = $conn->query($gender_query);
    while ($row = $gender_result->fetch_assoc()) {
        $gender_distribution[] = $row;
    }

    // District distribution
    $district_query = "
        SELECT 
            d.district_name,
            COUNT(p.patient_id) as count
        FROM patients p
        INNER JOIN barangay b ON p.barangay_id = b.barangay_id
        INNER JOIN districts d ON b.district_id = d.district_id
        WHERE p.status = 'active'
        GROUP BY d.district_id, d.district_name
        ORDER BY count DESC, d.district_name ASC
    ";
    $district_result = $conn->query($district_query);
    while ($row = $district_result->fetch_assoc()) {
        $district_distribution[] = $row;
    }

    // Barangay distribution
    $barangay_query = "
        SELECT 
            b.barangay_name,
            COUNT(p.patient_id) as count
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.status = 'active'
        GROUP BY b.barangay_id, b.barangay_name
        ORDER BY count DESC, b.barangay_name ASC
    ";
    $barangay_result = $conn->query($barangay_query);
    while ($row = $barangay_result->fetch_assoc()) {
        $barangay_distribution[] = $row;
    }

    // PhilHealth overall
    $philhealth_overall_query = "
        SELECT 
            CASE 
                WHEN isPhilHealth = 1 THEN 'PhilHealth Member'
                ELSE 'Non-Member'
            END as membership_status,
            COUNT(*) as count
        FROM patients 
        WHERE status = 'active'
        GROUP BY isPhilHealth
    ";
    $philhealth_overall_result = $conn->query($philhealth_overall_query);
    while ($row = $philhealth_overall_result->fetch_assoc()) {
        $philhealth_overall[] = $row;
    }

    // PhilHealth types
    $philhealth_query = "
        SELECT 
            pt.type_name as philhealth_type,
            COUNT(p.patient_id) as count
        FROM patients p
        INNER JOIN philhealth_types pt ON p.philhealth_type_id = pt.id
        WHERE p.status = 'active' AND p.isPhilHealth = 1
        GROUP BY p.philhealth_type_id, pt.type_name
        ORDER BY count DESC
    ";
    $philhealth_result = $conn->query($philhealth_query);
    while ($row = $philhealth_result->fetch_assoc()) {
        $philhealth_distribution[] = $row;
    }

    // PWD count
    $pwd_query = "SELECT COUNT(*) as pwd_count FROM patients WHERE status = 'active' AND isPWD = 1";
    $pwd_result = $conn->query($pwd_query);
    $pwd_count = $pwd_result->fetch_assoc()['pwd_count'];
    $pwd_percentage = $total_patients > 0 ? ($pwd_count / $total_patients) * 100 : 0;

} catch (Exception $e) {
    $error = "Error fetching demographics data: " . $e->getMessage();
}

// Set content type for PDF
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Patient_Demographics_Full_Report_' . date('Y-m-d') . '.pdf"');

// Generate HTML content for PDF
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Demographics Full Report</title>
    <style>
        @page {
            size: 8.5in 13in;
            margin: 0.75in 0.5in;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #000;
            margin: 0;
            padding: 0;
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }
        
        .header h1 {
            font-size: 16px;
            margin: 0 0 5px 0;
            font-weight: bold;
        }
        
        .header h2 {
            font-size: 14px;
            margin: 0 0 5px 0;
            font-weight: bold;
        }
        
        .header p {
            font-size: 10px;
            margin: 0;
        }
        
        .section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        
        .section h2 {
            font-size: 14px;
            font-weight: bold;
            margin: 0 0 10px 0;
            border-bottom: 1px solid #000;
            padding-bottom: 3px;
        }
        
        .section h3 {
            font-size: 12px;
            font-weight: bold;
            margin: 15px 0 5px 0;
        }
        
        .definition {
            margin-bottom: 10px;
            padding: 5px;
            background: #f5f5f5;
            border-left: 3px solid #333;
        }
        
        .definition p {
            margin: 2px 0;
            font-size: 10px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 9px;
        }
        
        table th,
        table td {
            border: 1px solid #000;
            padding: 4px 6px;
            text-align: left;
        }
        
        table th {
            background: #e9e9e9;
            font-weight: bold;
        }
        
        .page-break {
            page-break-before: always;
        }
        
        .footer {
            position: fixed;
            bottom: 0.5in;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8px;
            border-top: 1px solid #ccc;
            padding-top: 5px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>PATIENT DEMOGRAPHICS REPORT</h1>
        <h1>FULL DATA EXPORT</h1>
        <h2>Republic of the Philippines</h2>
        <h2>City Health Office</h2>
        <h2>Koronadal City</h2>
        <p>Generated: <?= date('F j, Y \a\t g:i A') ?></p>
        <p>Generated by: <?= htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) ?> (<?= htmlspecialchars($_SESSION['role']) ?>)</p>
    </div>

    <!-- I. KEY STATISTICS -->
    <div class="section">
        <h2>I. KEY STATISTICS</h2>
        
        <h3>Total Active Patients</h3>
        <div class="definition">
            <p><strong>Definition:</strong> Count of all patients with status = 'active'</p>
            <p><strong>Count:</strong> <?= number_format($total_patients) ?></p>
            <p><strong>Usage:</strong> Main denominator for all percentage calculations</p>
        </div>
        
        <h3>Total PhilHealth Members (Active Patients)</h3>
        <div class="definition">
            <?php
            $philhealth_members = 0;
            foreach ($philhealth_overall as $ph) {
                if ($ph['membership_status'] === 'PhilHealth Member') {
                    $philhealth_members = $ph['count'];
                    break;
                }
            }
            $philhealth_percentage = $total_patients > 0 ? ($philhealth_members / $total_patients) * 100 : 0;
            ?>
            <p><strong>Definition:</strong> Count of all active patients with isPhilHealth = 1</p>
            <p><strong>Count:</strong> <?= number_format($philhealth_members) ?> (<?= number_format($philhealth_percentage, 1) ?>%)</p>
            <p><strong>Usage:</strong> Used to measure PhilHealth coverage rate among active patients</p>
        </div>
        
        <h3>Total PWD Patients (Active Patients)</h3>
        <div class="definition">
            <p><strong>Definition:</strong> Count of all active patients with isPWD = 1</p>
            <p><strong>Count:</strong> <?= number_format($pwd_count) ?> (<?= number_format($pwd_percentage, 1) ?>%)</p>
            <p><strong>Usage:</strong> Indicates proportion of PWDs among active patients</p>
        </div>
    </div>

    <!-- II. GEOGRAPHIC DISTRIBUTION -->
    <div class="section">
        <h2>II. GEOGRAPHIC DISTRIBUTION</h2>
        
        <h3>District Distribution</h3>
        <div class="definition">
            <p><strong>Categories:</strong> All districts (via barangay mapping)</p>
            <p><strong>Metrics:</strong> Patient count and percentage per district</p>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>District Name</th>
                    <th>Patient Count</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $district_total = array_sum(array_column($district_distribution, 'count'));
                foreach ($district_distribution as $district): 
                    $percentage = $district_total > 0 ? ($district['count'] / $district_total) * 100 : 0;
                ?>
                <tr>
                    <td><?= htmlspecialchars($district['district_name']) ?></td>
                    <td><?= number_format($district['count']) ?></td>
                    <td><?= number_format($percentage, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <h3>Barangay Distribution</h3>
        <div class="definition">
            <p><strong>Categories:</strong> All barangays in the system</p>
            <p><strong>Metrics:</strong> Patient count and percentage per barangay</p>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Barangay Name</th>
                    <th>Patient Count</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $barangay_total = array_sum(array_column($barangay_distribution, 'count'));
                foreach ($barangay_distribution as $barangay): 
                    $percentage = $barangay_total > 0 ? ($barangay['count'] / $barangay_total) * 100 : 0;
                ?>
                <tr>
                    <td><?= htmlspecialchars($barangay['barangay_name']) ?></td>
                    <td><?= number_format($barangay['count']) ?></td>
                    <td><?= number_format($percentage, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- III. AGE DISTRIBUTION -->
    <div class="section page-break">
        <h2>III. AGE DISTRIBUTION</h2>
        
        <h3>Age Distribution</h3>
        <div class="definition">
            <p><strong>Categories:</strong> Infants (0–1), Toddlers (1–4), Children (5–12), Teens (13–17), Young Adults (18–35), Adults (36–59), Seniors (60+)</p>
            <p><strong>Metrics:</strong> Count and percentage per age group</p>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Age Group</th>
                    <th>Count</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $age_total = array_sum(array_column($age_distribution, 'count'));
                $all_age_groups = [
                    'Infants (0-1)', 'Toddlers (1-4)', 'Children (5-12)', 'Teens (13-17)',
                    'Young Adults (18-35)', 'Adults (36-59)', 'Seniors (60+)'
                ];
                
                // Create age data map
                $age_data_map = [];
                foreach ($age_distribution as $age) {
                    $age_data_map[$age['age_group']] = $age['count'];
                }
                
                foreach ($all_age_groups as $age_group): 
                    $count = isset($age_data_map[$age_group]) ? $age_data_map[$age_group] : 0;
                    $percentage = $age_total > 0 ? ($count / $age_total) * 100 : 0;
                ?>
                <tr>
                    <td><?= htmlspecialchars($age_group) ?></td>
                    <td><?= number_format($count) ?></td>
                    <td><?= number_format($percentage, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- IV. SEX DISTRIBUTION -->
    <div class="section">
        <h2>IV. SEX DISTRIBUTION</h2>
        
        <h3>Gender Distribution</h3>
        <div class="definition">
            <p><strong>Categories:</strong> Male, Female</p>
            <p><strong>Metrics:</strong> Count, percentage, and gender ratio</p>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Gender</th>
                    <th>Count</th>
                    <th>Percentage</th>
                    <th>Ratio</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $gender_total = array_sum(array_column($gender_distribution, 'count'));
                $male_count = 0;
                $female_count = 0;
                
                foreach ($gender_distribution as $gender) {
                    if ($gender['gender'] === 'Male') $male_count = $gender['count'];
                    if ($gender['gender'] === 'Female') $female_count = $gender['count'];
                }
                
                $ratio = $female_count > 0 ? ($male_count / $female_count) : 0;
                
                foreach ($gender_distribution as $gender): 
                    $percentage = $gender_total > 0 ? ($gender['count'] / $gender_total) * 100 : 0;
                    $gender_ratio = '';
                    if ($gender['gender'] === 'Male') {
                        $gender_ratio = number_format($ratio, 2) . ':1 (M:F)';
                    } elseif ($gender['gender'] === 'Female') {
                        $gender_ratio = '1:' . number_format($ratio, 2) . ' (M:F)';
                    }
                ?>
                <tr>
                    <td><?= htmlspecialchars($gender['gender']) ?></td>
                    <td><?= number_format($gender['count']) ?></td>
                    <td><?= number_format($percentage, 1) ?>%</td>
                    <td><?= $gender_ratio ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- V. PHILHEALTH MEMBER DISTRIBUTION -->
    <div class="section">
        <h2>V. PHILHEALTH MEMBER DISTRIBUTION</h2>
        
        <h3>PhilHealth Membership</h3>
        <div class="definition">
            <p><strong>Categories:</strong> PhilHealth Member (isPhilHealth = 1), Non-Member (isPhilHealth = 0)</p>
            <p><strong>Metrics:</strong> Count and percentage of PhilHealth members</p>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Membership Status</th>
                    <th>Count</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $philhealth_total = array_sum(array_column($philhealth_overall, 'count'));
                foreach ($philhealth_overall as $status): 
                    $percentage = $philhealth_total > 0 ? ($status['count'] / $philhealth_total) * 100 : 0;
                ?>
                <tr>
                    <td><?= htmlspecialchars($status['membership_status']) ?></td>
                    <td><?= number_format($status['count']) ?></td>
                    <td><?= number_format($percentage, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (!empty($philhealth_distribution)): ?>
        <h3>PhilHealth Membership Type Breakdown</h3>
        <div class="definition">
            <p><strong>Categories:</strong> Membership types (e.g., Indigent, Professional, Senior Citizen, PWD, etc.)</p>
            <p><strong>Metrics:</strong> Count and percentage per membership type (only for members)</p>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>PhilHealth Type</th>
                    <th>Count</th>
                    <th>% of Members</th>
                    <th>% of Total</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $member_total = array_sum(array_column($philhealth_distribution, 'count'));
                foreach ($philhealth_distribution as $type): 
                    $member_percentage = $member_total > 0 ? ($type['count'] / $member_total) * 100 : 0;
                    $total_percentage = $philhealth_total > 0 ? ($type['count'] / $philhealth_total) * 100 : 0;
                ?>
                <tr>
                    <td><?= htmlspecialchars($type['philhealth_type']) ?></td>
                    <td><?= number_format($type['count']) ?></td>
                    <td><?= number_format($member_percentage, 1) ?>%</td>
                    <td><?= number_format($total_percentage, 1) ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <div class="footer">
        Patient Demographics Full Report - City Health Office, Koronadal City | Generated: <?= date('F j, Y') ?>
    </div>
</body>
</html>

<?php
// Use ob_get_contents to capture HTML and convert to PDF using a library like wkhtmltopdf or DomPDF
// For now, this will display as HTML which the browser can print to PDF
$html = ob_get_clean();

// If you want to use a PDF library, uncomment and configure:
// require_once $root_path . '/vendor/autoload.php'; // If using Composer
// $dompdf = new Dompdf\Dompdf();
// $dompdf->loadHtml($html);
// $dompdf->setPaper('legal', 'portrait');
// $dompdf->render();
// $dompdf->stream("Patient_Demographics_Full_Report_" . date('Y-m-d') . ".pdf");

// For now, output HTML that can be printed to PDF
echo $html;
?>