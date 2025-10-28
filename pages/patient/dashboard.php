<?php
// dashboard_patient.php - moved from dashboard folder to patient folder

// Start output buffering at the very beginning
ob_start();

// Set error reporting for debugging but don't display errors in production
error_reporting(E_ALL);
ini_set('display_errors', '0');  // Never show errors to users in production
ini_set('log_errors', '1');      // Log errors for debugging

// Cache control headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include patient session configuration - Use absolute path resolution
$root_path = dirname(dirname(__DIR__));

// Load configuration first
require_once $root_path . '/config/env.php';

// Then load session management
require_once $root_path . '/config/session/patient_session.php';

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If user is not logged in, bounce to login using session management function
if (!is_patient_logged_in()) {
    ob_clean(); // Clear output buffer before redirect
    redirect_to_patient_login();
}

// DB
require_once $root_path . '/config/db.php'; // adjust relative path if needed
$patient_id = $_SESSION['patient_id'];

// -------------------- Data bootstrap (from patientHomepage.php) --------------------
$defaults = [
    'name' => 'Patient',
    'patient_number' => '-',
    'latest_appointment' => [
        'status' => 'none',
        'date' => '',
        'time' => '',
        'description' => 'No upcoming appointments'
    ],
    'latest_prescription' => [
        'status' => 'none',
        'date' => '',
        'doctor' => '',
        'description' => 'No active prescriptions'
    ]
];

// Load patient info
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.patient_id,
            p.username as patient_number,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.suffix,
            p.date_of_birth,
            p.sex,
            pi.civil_status,
            p.contact_number,
            b.barangay_name as barangay,
            pi.street as address,
            p.email
        FROM 
            patients p
        LEFT JOIN 
            barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN 
            personal_information pi ON p.patient_id = pi.patient_id
        WHERE 
            p.patient_id = ?
    ");

    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($patient) {
        // Build full name
        $nameParts = [];
        if (!empty($patient['first_name'])) $nameParts[] = $patient['first_name'];
        if (!empty($patient['middle_name'])) $nameParts[] = $patient['middle_name'];
        if (!empty($patient['last_name'])) $nameParts[] = $patient['last_name'];
        if (!empty($patient['suffix'])) $nameParts[] = $patient['suffix'];

        $fullName = implode(' ', $nameParts);
        $defaults['name'] = $fullName;
        $defaults['patient_number'] = $patient['patient_number'];

        // Also set session variables to ensure sidebar gets the data
        $_SESSION['patient_name'] = $fullName;
        $_SESSION['patient_number'] = $patient['patient_number'];
    }
} catch (PDOException $e) {
    // Log error but don't expose to user
    error_log('Patient dashboard error: ' . $e->getMessage());
}

// Load latest appointment (regardless of status)
try {
    $stmt = $pdo->prepare("
        SELECT 
            a.appointment_id,
            a.scheduled_date,
            a.scheduled_time,
            a.status,
            a.referral_id,
            r.referral_reason,
            s.name as service_name
        FROM 
            appointments a
        LEFT JOIN 
            referrals r ON a.referral_id = r.referral_id
        LEFT JOIN 
            services s ON a.service_id = s.service_id
        WHERE 
            a.patient_id = ?
        ORDER BY 
            a.scheduled_date DESC, 
            a.scheduled_time DESC
        LIMIT 1
    ");

    $stmt->execute([$patient_id]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($appointment) {
        // Format date
        $appointmentDate = new DateTime($appointment['scheduled_date']);
        $formattedDate = $appointmentDate->format('F j, Y');

        // Format time
        $appointmentTime = new DateTime($appointment['scheduled_time']);
        $formattedTime = $appointmentTime->format('g:i A');

        // Get reason from referral or service
        $reason = 'General consultation';
        if (!empty($appointment['referral_reason'])) {
            $reason = $appointment['referral_reason'];
        } elseif (!empty($appointment['service_name'])) {
            $reason = $appointment['service_name'];
        }

        $defaults['latest_appointment'] = [
            'id' => $appointment['appointment_id'],
            'status' => $appointment['status'],
            'date' => $formattedDate,
            'time' => $formattedTime,
            'doctor' => '', // No doctor info available in this structure
            'reason' => $reason,
            'description' => $reason
        ];
    }
} catch (PDOException $e) {
    error_log('Patient dashboard - appointment error: ' . $e->getMessage());
}

// Load latest prescription (regardless of status)
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.prescription_id,
            p.prescription_date,
            p.status,
            p.remarks,
            CONCAT(e.first_name, ' ', e.last_name) AS doctor_name,
            e.first_name AS doctor_first_name,
            e.last_name AS doctor_last_name
        FROM 
            prescriptions p
        LEFT JOIN 
            employees e ON p.prescribed_by_employee_id = e.employee_id
        WHERE 
            p.patient_id = ?
        ORDER BY 
            p.prescription_date DESC
        LIMIT 1
    ");

    $stmt->execute([$patient_id]);
    $prescription = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($prescription) {
        // Format date
        $prescriptionDate = new DateTime($prescription['prescription_date']);
        $formattedDate = $prescriptionDate->format('F j, Y');

        $doctorName = '';
        if (!empty($prescription['doctor_first_name']) && !empty($prescription['doctor_last_name'])) {
            $doctorName = "Dr. {$prescription['doctor_first_name']} {$prescription['doctor_last_name']}";
        }

        $defaults['latest_prescription'] = [
            'id' => $prescription['prescription_id'],
            'status' => $prescription['status'],
            'date' => $formattedDate,
            'doctor' => $doctorName,
            'notes' => $prescription['remarks'] ?: 'No additional notes',
            'description' => "Prescription: " . ($prescription['remarks'] ?: 'No additional notes')
        ];
    }
} catch (PDOException $e) {
    error_log('Patient dashboard - prescription error: ' . $e->getMessage());
}

// Load patient notifications from various tables
$notifications = [];

try {
    // Get recent appointments from past week
    $stmt = $pdo->prepare("
        SELECT 
            'appointment' as type,
            appointment_id as id,
            scheduled_date as date_field,
            scheduled_time as time_field,
            status,
            CONCAT('Appointment scheduled for ', DATE_FORMAT(scheduled_date, '%M %d, %Y'), ' at ', TIME_FORMAT(scheduled_time, '%h:%i %p')) as description,
            created_at,
            'appointment/appointments.php' as link_url
        FROM appointments 
        WHERE patient_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at DESC
    ");
    $stmt->execute([$patient_id]);
    $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $notifications = array_merge($notifications, $appointments);

    // Get recent referrals from past week
    $stmt = $pdo->prepare("
        SELECT 
            'referral' as type,
            referral_id as id,
            referral_date as date_field,
            NULL as time_field,
            status,
            CONCAT('Referral #', referral_num, ' - ', COALESCE(referral_reason, 'Medical referral')) as description,
            updated_at as created_at,
            'referral/referrals.php' as link_url
        FROM referrals 
        WHERE patient_id = ? AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY updated_at DESC
    ");
    $stmt->execute([$patient_id]);
    $referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $notifications = array_merge($notifications, $referrals);

    // Get recent consultations from past week
    $stmt = $pdo->prepare("
        SELECT 
            'consultation' as type,
            consultation_id as id,
            consultation_date as date_field,
            NULL as time_field,
            consultation_status as status,
            CONCAT('Consultation - ', COALESCE(chief_complaint, 'Medical consultation')) as description,
            created_at,
            'consultation/consultations.php' as link_url
        FROM consultations 
        WHERE patient_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at DESC
    ");
    $stmt->execute([$patient_id]);
    $consultations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $notifications = array_merge($notifications, $consultations);

    // Get recent prescriptions from past week
    $stmt = $pdo->prepare("
        SELECT 
            'prescription' as type,
            prescription_id as id,
            prescription_date as date_field,
            NULL as time_field,
            status,
            CONCAT('Prescription #', prescription_id, ' - ', COALESCE(remarks, 'Medication prescribed')) as description,
            created_at,
            'prescription/prescriptions.php' as link_url
        FROM prescriptions 
        WHERE patient_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at DESC
    ");
    $stmt->execute([$patient_id]);
    $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $notifications = array_merge($notifications, $prescriptions);

    // Get recent lab orders from past week
    $stmt = $pdo->prepare("
        SELECT 
            'lab_order' as type,
            lab_order_id as id,
            order_date as date_field,
            NULL as time_field,
            status,
            CONCAT('Lab Order #', lab_order_id, ' - ', COALESCE(remarks, 'Laboratory test ordered')) as description,
            created_at,
            'laboratory/lab_orders.php' as link_url
        FROM lab_orders 
        WHERE patient_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at DESC
    ");
    $stmt->execute([$patient_id]);
    $lab_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $notifications = array_merge($notifications, $lab_orders);

    // Get recent billing from past week
    $stmt = $pdo->prepare("
        SELECT 
            'billing' as type,
            billing_id as id,
            billing_date as date_field,
            NULL as time_field,
            payment_status as status,
            CONCAT('Bill #', billing_id, ' - Amount: ₱', FORMAT(net_amount, 2)) as description,
            created_at,
            'billing/billing.php' as link_url
        FROM billing 
        WHERE patient_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at DESC
    ");
    $stmt->execute([$patient_id]);
    $billing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $notifications = array_merge($notifications, $billing);

    // Get recent queue entries from past week
    $stmt = $pdo->prepare("
        SELECT 
            'queue' as type,
            queue_entry_id as id,
            time_in as date_field,
            NULL as time_field,
            status,
            CONCAT('Queue #', COALESCE(queue_number, queue_entry_id), ' - ', queue_type) as description,
            created_at,
            'queue/queue_status.php' as link_url
        FROM queue_entries 
        WHERE patient_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY created_at DESC
    ");
    $stmt->execute([$patient_id]);
    $queue_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $notifications = array_merge($notifications, $queue_entries);

    // Sort all notifications by created_at timestamp, most recent first
    usort($notifications, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    // Show all notifications from the past week (no limit)
    // $notifications already contains filtered results from past 7 days

    // Helper function to get notification icon
    function getNotificationIcon($type) {
        $icon_map = [
            'appointment' => 'fa-calendar-check',
            'referral' => 'fa-share-square', 
            'consultation' => 'fa-stethoscope',
            'prescription' => 'fa-prescription-bottle-alt',
            'lab_order' => 'fa-flask',
            'billing' => 'fa-file-invoice-dollar',
            'queue' => 'fa-users'
        ];
        return $icon_map[$type] ?? 'fa-bell';
    }

    // Helper function to format relative time
    function getRelativeTime($datetime) {
        $time = time() - strtotime($datetime);
        if ($time < 60) return 'Just now';
        if ($time < 3600) return floor($time/60) . ' min ago';
        if ($time < 86400) return floor($time/3600) . ' hr ago';
        if ($time < 2592000) return floor($time/86400) . ' days ago';
        return date('M j, Y', strtotime($datetime));
    }

} catch (PDOException $e) {
    error_log('Patient dashboard - notifications error: ' . $e->getMessage());
    $notifications = [];
}

// Set active page for sidebar highlighting
$activePage = 'dashboard';

// Ensure defaults array is properly set for sidebar
if (!isset($defaults)) {
    $defaults = ['name' => 'Patient', 'patient_number' => ''];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Dashboard - CHO Koronadal</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../assets/css/sidebar.css">

    <style>
        .content-wrapper {
            margin-left: 300px;
            padding: 2rem;
            transition: margin-left 0.3s;
        }

        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
            }
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        .dashboard-title {
            font-size: 1.8rem;
            color: #0077b6;
            margin: 0;
        }

        .dashboard-actions {
            display: flex;
            gap: 1rem;
        }

        .info-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            border-left: 4px solid #0077b6;
        }

        .info-card h2 {
            font-size: 1.4rem;
            color: #333;
            margin-top: 0;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-card h2 i {
            color: #0077b6;
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }

        .card-title {
            font-size: 1.2rem;
            color: #0077b6;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-status {
            padding: 0.3rem 0.6rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-confirmed {
            background-color: #d1fae5;
            color: #065f46;
        }

        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-active {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .status-completed {
            background-color: #e5e7eb;
            color: #374151;
        }

        .status-in-progress {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-ready {
            background-color: #d1fae5;
            color: #065f46;
        }

        .status-dispensed {
            background-color: #e5e7eb;
            color: #374151;
        }

        .status-cancelled {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .status-checked-in {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .status-issued {
            background-color: #f3e8ff;
            color: #6b46c1;
        }

        .status-expired {
            background-color: #fef2f2;
            color: #dc2626;
        }

        .card-content {
            margin-bottom: 1rem;
        }

        .card-detail {
            display: flex;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .detail-label {
            font-weight: 600;
            color: #6b7280;
            width: 70px;
            flex-shrink: 0;
        }

        .detail-value {
            color: #1f2937;
        }

        .card-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: #0077b6;
            color: white;
        }

        .btn-primary:hover {
            background-color: #023e8a;
        }

        .btn-secondary {
            background-color: #f3f4f6;
            color: #1f2937;
        }

        .btn-secondary:hover {
            background-color: #e5e7eb;
        }

        .section-divider {
            margin: 2.5rem 0;
            border: none;
            border-top: 1px solid #e5e7eb;
        }

        .quick-actions {
            margin-top: 2rem;
        }

        .actions-title {
            font-size: 1.4rem;
            color: #333;
            margin-bottom: 1.5rem;
        }

        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }

        .action-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
            text-decoration: none;
            color: #333;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 160px;
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }

        .action-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: #0077b6;
            transition: transform 0.2s;
        }

        .action-card:hover .action-icon {
            transform: scale(1.1);
        }

        .action-title {
            font-weight: 600;
            margin-bottom: 0.3rem;
        }

        .action-description {
            font-size: 0.85rem;
            color: #6b7280;
            margin: 0;
        }

        @media (max-width: 768px) {
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                margin-top: 80px;

            }

            .dashboard-actions {
                width: 100%;
                justify-content: space-between;
            }

            .action-card {
                min-height: 140px;
            }
        }

        /* Welcome message animation */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .welcome-message {
            animation: fadeInUp 0.8s ease-out;
        }

        /* Card entry animation */
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .animated-card {
            animation: slideInRight 0.5s ease-out forwards;
            opacity: 0;
        }

        .animated-card:nth-child(1) {
            animation-delay: 0.2s;
        }

        .animated-card:nth-child(2) {
            animation-delay: 0.4s;
        }

        .animated-card:nth-child(3) {
            animation-delay: 0.6s;
        }

        .animated-card:nth-child(4) {
            animation-delay: 0.8s;
        }

        /* Accessibility improvements */
        .visually-hidden {
            border: 0;
            clip: rect(0 0 0 0);
            height: 1px;
            margin: -1px;
            overflow: hidden;
            padding: 0;
            position: absolute;
            width: 1px;
        }

        /* Tooltip */
        .tooltip {
            position: relative;
        }

        /* Notification table styles */
        .notification-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-top: 10px;
            position: relative;
        }

        .notification-table {
            width: 100%;
            border-collapse: collapse;
            position: relative;
        }

        .notification-table th {
            position: sticky;
            top: 0;
            z-index: 10;
            padding: 12px 8px;
            text-align: left;
            background-color: #f8f9fa;
            font-weight: 600;
            color: #374151;
            font-size: 0.9rem;
            border-bottom: 2px solid #e5e7eb;
            box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
        }

        .notification-table td {
            padding: 12px 8px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.85rem;
            background-color: white;
        }

        .notification-table tbody tr:hover {
            background-color: #f9fafb;
        }

        .notification-type {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: capitalize;
        }

        .type-appointment {
            background-color: #dbeafe;
            color: #1e40af;
        }

        .type-referral {
            background-color: #fef3c7;
            color: #92400e;
        }

        .type-consultation {
            background-color: #d1fae5;
            color: #065f46;
        }

        .type-prescription {
            background-color: #e0e7ff;
            color: #3730a3;
        }

        .type-lab_order {
            background-color: #fce7f3;
            color: #be185d;
        }

        .type-billing {
            background-color: #f3f4f6;
            color: #374151;
        }

        .type-queue {
            background-color: #fed7d7;
            color: #c53030;
        }

        .notification-status {
            padding: 2px 6px;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: 500;
            text-transform: capitalize;
        }

        .status-confirmed,
        .status-completed,
        .status-active,
        .status-paid {
            background-color: #d1fae5;
            color: #065f46;
        }

        .status-pending,
        .status-in_progress,
        .status-partial {
            background-color: #fef3c7;
            color: #92400e;
        }

        .status-cancelled,
        .status-no_show {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .status-unpaid {
            background-color: #fef2f2;
            color: #dc2626;
        }

        .notification-link {
            color: #0077b6;
            text-decoration: none;
            font-weight: 500;
        }

        .notification-link:hover {
            text-decoration: underline;
        }

        .notification-date {
            color: #6b7280;
            font-size: 0.8rem;
        }

        .text-muted {
            color: #9ca3af !important;
            font-size: 0.75rem;
        }

        .notification-description {
            color: #374151;
            line-height: 1.4;
        }

        .no-notifications {
            text-align: center;
            color: #6b7280;
            font-style: italic;
            padding: 20px;
        }

        @media (max-width: 768px) {
            .notification-table {
                font-size: 0.8rem;
            }
            
            .notification-table th,
            .notification-table td {
                padding: 8px 4px;
            }
            
            .notification-description {
                max-width: 200px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .notification-container {
                max-height: 300px;
            }
        }

        /* Custom scrollbar styling */
        .notification-container::-webkit-scrollbar {
            width: 8px;
        }

        .notification-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        .notification-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        .notification-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Firefox scrollbar styling */
        .notification-container {
            scrollbar-width: thin;
            scrollbar-color: #c1c1c1 #f1f1f1;
        }
    </style>
</head>

<body>
    <?php include '../../includes/sidebar_patient.php'; ?>

    <main class="content-wrapper">
        <section class="dashboard-header">
            <div class="welcome-message">
                <h1 class="dashboard-title">Welcome, <?php echo htmlspecialchars($defaults['name']); ?></h1>
                <p>Here's what's happening with your health today.</p>
            </div>

            <div class="dashboard-actions">
                <a href="appointment/book_appointment.php" class="btn btn-primary">
                    <i class="fas fa-calendar-plus"></i> Book Appointment
                </a>
                <a href="profile/id_card.php" class="btn btn-secondary">
                    <i class="fas fa-id-card"></i> View ID Card
                </a>
            </div>
        </section>

        <section class="info-card">
            <h2><i class="fas fa-bell"></i> Recent Notifications (Past Week)</h2>
            <div class="notification-list">
                <?php if (!empty($notifications)): ?>
                    <div class="notification-container">
                        <table class="notification-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                <th>Date</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notifications as $notification): ?>
                                <tr>
                                    <td>
                                        <span class="notification-type type-<?php echo htmlspecialchars($notification['type']); ?>">
                                            <i class="fas <?php echo getNotificationIcon($notification['type']); ?>"></i>
                                            <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($notification['type']))); ?>
                                        </span>
                                    </td>
                                    <td class="notification-date">
                                        <?php 
                                        if (!empty($notification['date_field'])) {
                                            $date = new DateTime($notification['date_field']);
                                            echo $date->format('M j, Y');
                                            if (!empty($notification['time_field'])) {
                                                $time = new DateTime($notification['time_field']);
                                                echo '<br><small>' . $time->format('g:i A') . '</small>';
                                            }
                                        } else {
                                            $created = new DateTime($notification['created_at']);
                                            echo $created->format('M j, Y');
                                        }
                                        ?>
                                        <br><small class="text-muted"><?php echo getRelativeTime($notification['created_at']); ?></small>
                                    </td>
                                    <td class="notification-description">
                                        <?php echo htmlspecialchars($notification['description']); ?>
                                    </td>
                                    <td>
                                        <span class="notification-status status-<?php echo htmlspecialchars($notification['status']); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($notification['status']))); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($notification['type'] === 'appointment'): ?>
                                            <a href="appointment/appointments.php?view=<?php echo htmlspecialchars($notification['id']); ?>" class="notification-link">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php elseif ($notification['type'] === 'referral'): ?>
                                            <a href="referrals/referrals.php?view=<?php echo htmlspecialchars($notification['id']); ?>" class="notification-link">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php elseif ($notification['type'] === 'consultation'): ?>
                                            <a href="consultations/consultations.php?view=<?php echo htmlspecialchars($notification['id']); ?>" class="notification-link">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php elseif ($notification['type'] === 'lab_order'): ?>
                                            <a href="laboratory/laboratory.php?view=<?php echo htmlspecialchars($notification['id']); ?>" class="notification-link">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php elseif ($notification['type'] === 'billing'): ?>
                                            <a href="billing/billing.php?view=<?php echo htmlspecialchars($notification['id']); ?>" class="notification-link">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php elseif ($notification['type'] === 'queue'): ?>
                                            <a href="queueing/queue_status.php?view=<?php echo htmlspecialchars($notification['id']); ?>" class="notification-link">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php elseif ($notification['type'] === 'prescription'): ?>
                                            <a href="prescription/prescriptions.php?view=<?php echo htmlspecialchars($notification['id']); ?>" class="notification-link">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php else: ?>
                                            <a href="<?php echo htmlspecialchars($notification['link_url']); ?>" class="notification-link">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php else: ?>
                    <div class="no-notifications">
                        <i class="fas fa-bell-slash" style="font-size: 24px; color: #d1d5db; margin-bottom: 10px;"></i>
                        <p>You have no notifications from the past week.</p>
                        <small>New appointments, referrals, and other activities will appear here.</small>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="card-grid">
            <!-- Appointment Card -->
            <div class="card animated-card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-calendar-check"></i> Latest Appointment
                    </h2>
                    <?php if ($defaults['latest_appointment']['status'] !== 'none'): ?>
                        <span class="card-status status-<?php echo htmlspecialchars($defaults['latest_appointment']['status']); ?>">
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $defaults['latest_appointment']['status']))); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($defaults['latest_appointment']['status'] !== 'none'): ?>
                    <div class="card-content">
                        <div class="card-detail">
                            <span class="detail-label">Date:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($defaults['latest_appointment']['date']); ?></span>
                        </div>
                        <div class="card-detail">
                            <span class="detail-label">Time:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($defaults['latest_appointment']['time']); ?></span>
                        </div>
                        <?php if (!empty($defaults['latest_appointment']['doctor'])): ?>
                            <div class="card-detail">
                                <span class="detail-label">Doctor:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($defaults['latest_appointment']['doctor']); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="card-detail">
                            <span class="detail-label">Status:</span>
                            <span class="detail-value"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $defaults['latest_appointment']['status']))); ?></span>
                        </div>
                        <div class="card-detail">
                            <span class="detail-label">Reason:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($defaults['latest_appointment']['reason']); ?></span>
                        </div>
                        <?php if (!empty($defaults['latest_appointment']['id'])): ?>
                            <div class="card-detail">
                                <span class="detail-label">ID:</span>
                                <span class="detail-value">#<?php echo htmlspecialchars($defaults['latest_appointment']['id']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="card-actions">
                        <a href="appointment/appointments.php" class="btn btn-primary">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    </div>
                <?php else: ?>
                    <div class="card-content">
                        <p>You have no appointments on record.</p>
                    </div>

                    <div class="card-actions">
                        <a href="appointment/book_appointment.php" class="btn btn-primary">
                            <i class="fas fa-calendar-plus"></i> Book Now
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Prescription Card -->
            <div class="card animated-card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-prescription-bottle-alt"></i> Latest Prescription
                    </h2>
                    <?php if ($defaults['latest_prescription']['status'] !== 'none'): ?>
                        <span class="card-status status-<?php echo htmlspecialchars($defaults['latest_prescription']['status']); ?>">
                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $defaults['latest_prescription']['status']))); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($defaults['latest_prescription']['status'] !== 'none'): ?>
                    <div class="card-content">
                        <div class="card-detail">
                            <span class="detail-label">Date:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($defaults['latest_prescription']['date']); ?></span>
                        </div>
                        <?php if (!empty($defaults['latest_prescription']['doctor'])): ?>
                            <div class="card-detail">
                                <span class="detail-label">Doctor:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($defaults['latest_prescription']['doctor']); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="card-detail">
                            <span class="detail-label">Status:</span>
                            <span class="detail-value"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $defaults['latest_prescription']['status']))); ?></span>
                        </div>
                        <?php if (!empty($defaults['latest_prescription']['id'])): ?>
                            <div class="card-detail">
                                <span class="detail-label">ID:</span>
                                <span class="detail-value">#<?php echo htmlspecialchars($defaults['latest_prescription']['id']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($defaults['latest_prescription']['notes'])): ?>
                            <div class="card-detail">
                                <span class="detail-label">Notes:</span>
                                <span class="detail-value"><?php echo htmlspecialchars($defaults['latest_prescription']['notes']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="card-actions">
                        <a href="prescription/prescriptions.php" class="btn btn-primary">
                            <i class="fas fa-eye"></i> View Details
                        </a>
                    </div>
                <?php else: ?>
                    <div class="card-content">
                        <p>You have no prescriptions on record.</p>
                    </div>

                    <div class="card-actions">
                        <a href="prescription/prescriptions.php" class="btn btn-secondary">
                            <i class="fas fa-history"></i> View History
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="quick-actions">
            <h2 class="actions-title">Quick Actions</h2>

            <div class="actions-grid">
                <a href="appointment/appointments.php" class="action-card">
                    <i class="fas fa-calendar-plus action-icon"></i>
                    <h3 class="action-title">Book Appointment</h3>
                    <p class="action-description">Schedule a visit with a healthcare provider</p>
                </a>

                <a href="profile/medical_record_print.php" class="action-card">
                    <i class="fas fa-file-medical action-icon"></i>
                    <h3 class="action-title">Medical Records</h3>
                    <p class="action-description">View and print your medical history</p>
                </a>

                <a href="profile/profile.php" class="action-card">
                    <i class="fas fa-user-edit action-icon"></i>
                    <h3 class="action-title">Update Profile</h3>
                    <p class="action-description">Keep your information up to date</p>
                </a>

                <a href="profile/id_card.php" class="action-card">
                    <i class="fas fa-id-card action-icon"></i>
                    <h3 class="action-title">Patient ID</h3>
                    <p class="action-description">View and print your patient ID card</p>
                </a>
            </div>
        </section>
    </main>

    <script>
        // Simple animation for the cards
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.animated-card');
            cards.forEach(card => {
                card.style.opacity = '1';
            });
        });
    </script>
</body>

</html>