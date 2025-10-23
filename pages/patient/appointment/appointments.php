<?php
// Start output buffering at the very beginning
ob_start();

// Set error reporting for debugging but don't display errors in production
error_reporting(E_ALL);
ini_set('display_errors', '0');  // Never show errors to users in production
ini_set('log_errors', '1');      // Log errors for debugging

// Security headers
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Cache control headers to ensure fresh content
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include patient session configuration FIRST - before any output
$root_path = dirname(dirname(dirname(__DIR__)));

// Load configuration first
require_once $root_path . '/config/env.php';

// Then load session management
require_once $root_path . '/config/session/patient_session.php';

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If user is not logged in, redirect to login
if (!isset($_SESSION['patient_id'])) {
    ob_clean(); // Clear output buffer before redirect
    header('Location: ../auth/patient_login.php');
    exit();
}

// Database connection
require_once $root_path . '/config/db.php';

// Include automatic status updater
require_once $root_path . '/utils/automatic_status_updater.php';

// Include queue management service
require_once $root_path . '/utils/queue_management_service.php';

$patient_id = $_SESSION['patient_id'];
$message = '';
$error = '';

// Run automatic status updates when page loads
try {
    $status_updater = new AutomaticStatusUpdater($conn);
    $update_result = $status_updater->runAllUpdates();

    // Optional: Show update message to user (you can remove this if you don't want to show it)
    if ($update_result['success'] && $update_result['total_updates'] > 0) {
        $message = "Status updates applied: " . $update_result['total_updates'] . " records updated automatically.";
    }
} catch (Exception $e) {
    // Log error but don't show to user to avoid confusion
    error_log("Failed to run automatic status updates: " . $e->getMessage());
}

// Fetch patient information
$patient_info = null;
try {
    $stmt = $conn->prepare("
        SELECT p.*, b.barangay_name
        FROM patients p
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        WHERE p.patient_id = ?
    ");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $patient_info = $result->fetch_assoc();

    // Calculate priority level
    if ($patient_info) {
        $patient_info['priority_level'] = ($patient_info['isPWD'] || $patient_info['isSenior']) ? 1 : 2;
        $patient_info['priority_description'] = ($patient_info['priority_level'] == 1) ? 'Priority Patient' : 'Regular Patient';
    }

    $stmt->close();
} catch (Exception $e) {
    $error = "Failed to fetch patient information: " . $e->getMessage();
}

// Fetch appointments with queue information (limit to recent 20 for performance)
$appointments = [];
try {
    $stmt = $conn->prepare("
        SELECT a.*, 
               COALESCE(a.status, 'confirmed') as status,
               f.name as facility_name, f.type as facility_type,
               s.name as service_name,
               r.referral_num, r.referral_reason,
               qe.queue_number, qe.queue_type, qe.priority_level as queue_priority, qe.status as queue_status,
               qe.time_in, qe.time_started, qe.time_completed
        FROM appointments a
        LEFT JOIN facilities f ON a.facility_id = f.facility_id
        LEFT JOIN services s ON a.service_id = s.service_id
        LEFT JOIN referrals r ON a.referral_id = r.referral_id
        LEFT JOIN queue_entries qe ON a.appointment_id = qe.appointment_id
        WHERE a.patient_id = ?
        ORDER BY a.scheduled_date DESC, a.scheduled_time DESC
        LIMIT 20
    ");
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $appointments = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (Exception $e) {
    $error = "Failed to fetch appointments: " . $e->getMessage();
}


?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>My Appointments - CHO Koronadal</title>
    <!-- Cache busting: <?php echo date('Y-m-d H:i:s'); ?> -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../../../assets/css/dashboard.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="../../../assets/css/sidebar.css?v=<?php echo time(); ?>">
    <style>
        /* Layout */
        .content-wrapper {
            margin-left: 300px;
            padding: 2rem;
            transition: margin-left 0.3s;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e9ecef;
        }

        .page-header h1 {
            margin: 0;
            font-size: 2.2rem;
            color: #0077b6;
            font-weight: 700;
            letter-spacing: 1px;
        }

        .breadcrumb {
            background: none;
            padding: 0;
            margin: 0 0 1rem 0;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .breadcrumb a {
            color: #6c757d;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            color: #0077b6;
        }

        /* Buttons */
        .btn {
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background: #007BFF;
            color: #fff;
            font-size: 16px;
            padding: 12px 28px;
            text-align: center;
            display: inline-block;
        }

        .btn-primary:hover,
        .btn-secondary:hover,
        .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.15);
        }

        .btn-primary:hover { background: #0056b3; }
        .btn-primary i { margin-right: 8px; font-size: 18px; }

        .btn-secondary {
            background: linear-gradient(135deg, #16a085, #0f6b5c);
            color: white;
        }

        .btn-secondary:hover { background: linear-gradient(135deg, #0f6b5c, #0a4f44); }

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            border-radius: 6px;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid;
        }

        .btn-outline-primary {
            border-color: #0077b6;
            color: #0077b6;
        }

        .btn-outline-primary:hover {
            background: #0077b6;
            color: white;
        }

        .btn-outline-danger {
            border-color: #dc3545;
            color: #dc3545;
        }

        .btn-outline-danger:hover {
            background: #dc3545;
            color: white;
        }

        .action-buttons {
            display: flex;
            gap: 0.7rem;
            flex-wrap: wrap;
        }

        /* Sections */
        .section-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f8f9fa;
        }

        .section-icon {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .section-title {
            margin: 0;
            font-size: 1.5rem;
            color: #0077b6;
            font-weight: 600;
        }

        /* Filters */
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 0.5rem 1rem;
            border: 2px solid #e9ecef;
            background: #f8f9fa;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .filter-tab.active {
            background: #0077b6;
            color: white;
            border-color: #023e8a;
        }

        .filter-tab:hover {
            background: #e3f2fd;
            border-color: #0077b6;
        }

        .filter-tab.active:hover {
            background: #023e8a;
        }

        .search-filters {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e9ecef;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr) 2fr;
            gap: 1.25rem;
            align-items: end;
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 12px;
            border: 1px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .filter-group label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.6rem;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-group label i {
            color: #0077b6;
            font-size: 0.8rem;
        }

        .filter-group input,
        .filter-group select {
            padding: 0.75rem 0.875rem;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            background: white;
            font-weight: 500;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
            transform: translateY(-1px);
        }

        .filter-group input::placeholder {
            color: #adb5bd;
            font-weight: 400;
        }

        .filter-actions-group {
            justify-self: end;
            align-self: end;
        }

        .filter-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .btn-filter {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            min-width: 120px;
            justify-content: center;
        }

        .btn-filter-primary {
            background: linear-gradient(135deg, #0077b6, #005577);
            color: white;
            box-shadow: 0 3px 12px rgba(0, 119, 182, 0.3);
        }

        .btn-filter-primary:hover {
            background: linear-gradient(135deg, #005577, #003d5c);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 119, 182, 0.4);
        }

        .btn-filter-secondary {
            background: #ffffff;
            color: #6c757d;
            border: 2px solid #e9ecef;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-filter-secondary:hover {
            background: #f8f9fa;
            border-color: #6c757d;
            color: #495057;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        /* Cards */
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(420px, 1fr));
            gap: 1.5rem;
        }

        .appointment-card {
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 16px;
            padding: 0;
            transition: all 0.3s ease;
            position: relative;
            min-height: 280px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .appointment-card:hover {
            border-color: #0077b6;
            transform: translateY(-4px);
            box-shadow: 0 16px 40px rgba(0, 119, 182, 0.15);
        }

        /* Card Header */
        .card-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid #dee2e6;
        }

        .appointment-id .card-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: #0077b6;
            margin: 0 0 0.25rem 0;
            line-height: 1.2;
        }

        .appointment-id .booking-date {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 500;
        }

        /* Main Card Content */
        .card-main-info {
            padding: 1.5rem;
            flex-grow: 1;
        }

        /* Date and Time Section */
        .date-time-section {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: linear-gradient(135deg, #e3f2fd 0%, #f3e5f5 100%);
            border-radius: 12px;
            border-left: 4px solid #0077b6;
        }

        .date-display, .time-display {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .date-display i, .time-display i {
            color: #0077b6;
            font-size: 1.2rem;
            width: 20px;
            text-align: center;
        }

        .date-info {
            display: flex;
            flex-direction: column;
        }

        .date-info .date {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
            line-height: 1.2;
        }

        .date-info .day {
            font-size: 0.85rem;
            color: #666;
            font-weight: 500;
        }

        .time-display .time {
            font-size: 1.1rem;
            font-weight: 700;
            color: #333;
            padding: 0.4rem 0.8rem;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 20px;
            border: 1px solid #0077b6;
        }

        /* Facility and Service Section */
        .facility-service-section {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .info-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.75rem;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 3px solid #28a745;
        }

        .info-item i {
            color: #28a745;
            font-size: 1rem;
            width: 20px;
            margin-top: 0.1rem;
            flex-shrink: 0;
        }

        .info-content {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
            flex-grow: 1;
        }

        .info-content .label {
            font-size: 0.8rem;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-content .value {
            font-size: 0.95rem;
            color: #333;
            font-weight: 600;
        }

        /* Card Actions */
        .card-actions {
            padding: 1rem 1.5rem;
            background: #f8f9fa;
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            border-top: 1px solid #dee2e6;
        }

        .btn-action {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 0.5rem;
            border: 1px solid;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            min-width: 0;
        }

        .btn-action i {
            font-size: 1.1rem;
        }

        .btn-action span {
            font-size: 0.8rem;
            text-align: center;
        }

        .btn-view {
            color: #0077b6;
            border-color: #0077b6;
        }

        .btn-view:hover {
            background: #0077b6;
            color: white;
            transform: translateY(-2px);
        }

        .btn-qr {
            color: #28a745;
            border-color: #28a745;
        }

        .btn-qr:hover {
            background: #28a745;
            color: white;
            transform: translateY(-2px);
        }

        .btn-cancel {
            color: #dc3545;
            border-color: #dc3545;
        }

        .btn-cancel:hover {
            background: #dc3545;
            color: white;
            transform: translateY(-2px);
        }

        /* Status Badges - Consolidated */
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-confirmed, .status-active, .status-approved, .status-done {
            background: #d4edda;
            color: #155724;
        }

        .status-pending, .status-waiting {
            background: #fff3cd;
            color: #856404;
        }

        .status-cancelled, .status-expired, .status-skipped, .status-no_show {
            background: #f8d7da;
            color: #721c24;
        }

        .status-completed, .status-in_progress, .status-scheduled {
            background: #cce7ff;
            color: #004085;
        }

        /* Default status style */
        .status-badge:not([class*="status-"]) {
            background: #e9ecef;
            color: #495057;
        }

        /* Modals - Simplified & Clean */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        #notificationModal { z-index: 9999 !important; }

        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-width: 500px;
        }

        .modal-header {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.3s;
        }

        .close:hover { background: rgba(255, 255, 255, 0.2); }

        .modal-body {
            padding: 1.5rem;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e9ecef;
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }

        .modal-footer .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 6px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .modal-footer .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .modal-footer .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-1px);
        }

        .modal-footer .btn-danger {
            background: #dc3545;
            color: white;
        }

        .modal-footer .btn-danger:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        .modal-footer .btn-primary {
            background: #0077b6;
            color: white;
        }

        .modal-footer .btn-primary:hover {
            background: #005577;
            transform: translateY(-1px);
        }

        /* Cancel Modal Specific */
        .cancellation-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            gap: 0.75rem;
        }

        .warning-icon {
            color: #856404;
            font-size: 1.2rem;
        }

        .warning-content h4 {
            margin: 0 0 0.5rem 0;
            color: #856404;
            font-size: 1rem;
        }

        .warning-content p {
            margin: 0;
            color: #856404;
            font-size: 0.9rem;
        }

        .cancel-form-group {
            margin-bottom: 1rem;
        }

        .cancel-form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }

        .cancel-form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .cancel-form-control:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 2px rgba(0, 119, 182, 0.2);
        }

        .appointment-info {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .appointment-info h4 {
            margin: 0 0 0.75rem 0;
            color: #0077b6;
            font-size: 0.95rem;
        }

        .appointment-info .info-text {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .appointment-info .info-text strong {
            color: #333;
        }

        /* View Modal Specific */
        #viewModal .modal-content {
            max-width: 700px;
        }

        #viewModal .header-actions {
            display: flex;
            gap: 0.5rem;
        }

        #viewModal .btn-icon {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            padding: 0.5rem;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.3s;
        }

        #viewModal .btn-icon:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .details-header {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .clinic-info h2 {
            color: #0077b6;
            font-size: 1.4rem;
            margin: 0 0 0.5rem 0;
        }

        .clinic-info p {
            color: #6c757d;
            margin: 0;
        }

        .appointment-id-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #dee2e6;
        }

        .appointment-id {
            background: #0077b6;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 600;
        }

        .info-section {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }

        .info-section h3 {
            color: #0077b6;
            font-size: 1.1rem;
            margin: 0 0 1rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .info-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 1rem;
        }

        .info-item label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #6c757d;
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }

        .info-item span {
            font-weight: 600;
            color: #333;
        }

        .details-footer {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 1rem;
            text-align: center;
            margin-top: 1rem;
        }

        .details-footer p {
            margin: 0;
            color: #6c757d;
            font-size: 0.85rem;
        }

        /* Alerts */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background-color: #d1f2eb;
            color: #0d5e3d;
            border: 1px solid #7fb069;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f1b2b7;
        }

        .alert i {
            font-size: 1.1rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Priority Indicator */
        .priority-indicator {
            position: absolute;
            top: -5px;
            right: -5px;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }

        /* Notification Animations */
        #notificationModal .modal-content {
            animation: slideInDown 0.3s ease-out;
        }

        @keyframes slideInDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        #notification-message {
            min-height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .notification-success #notification-header {
            background: linear-gradient(135deg, #28a745, #20c997) !important;
        }

        .notification-error #notification-header {
            background: linear-gradient(135deg, #dc3545, #c82333) !important;
        }

        .notification-warning #notification-header {
            background: linear-gradient(135deg, #ffc107, #e0a800) !important;
        }

        .notification-info #notification-header {
            background: linear-gradient(135deg, #0077b6, #023e8a) !important;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .content-wrapper {
                margin-left: 0;
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: stretch;
            }

            .action-buttons {
                justify-content: center;
            }

            .card-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .appointment-card {
                min-height: 260px;
            }

            .card-header {
                padding: 1rem;
            }

            .card-main-info {
                padding: 1rem;
            }

            .date-time-section {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }

            .date-display, .time-display {
                align-self: stretch;
                justify-content: center;
            }

            .card-actions {
                padding: 0.75rem 1rem;
                gap: 0.5rem;
            }

            .btn-action {
                padding: 0.6rem 0.4rem;
            }

            .btn-action span {
                font-size: 0.75rem;
            }

            .btn .hide-on-mobile {
                display: none;
            }

            .filters-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
                padding: 1rem;
            }

            .filter-actions-group {
                justify-self: stretch;
            }

            .filter-actions {
                justify-content: stretch;
                flex-direction: column;
                gap: 0.5rem;
            }

            .btn-filter {
                flex: 1;
                min-width: auto;
            }

            .modal-content {
                width: 95%;
                margin: 5% auto;
                max-width: none;
            }

            .modal-header {
                padding: 0.8rem 1rem;
            }

            .modal-header h3 {
                font-size: 1rem;
            }

            .modal-footer {
                flex-direction: column;
                gap: 0.5rem;
            }
        }

        @media (max-width: 480px) {
            .card-grid {
                grid-template-columns: 1fr;
            }

            .appointment-card {
                border-radius: 12px;
                min-height: 240px;
            }

            .date-time-section {
                padding: 0.75rem;
            }

            .appointment-id .card-title {
                font-size: 1.1rem;
            }

            .date-info .date {
                font-size: 1rem;
            }

            .time-display .time {
                font-size: 1rem;
                padding: 0.3rem 0.6rem;
            }

            .info-item {
                padding: 0.6rem;
            }

            .card-actions {
                flex-direction: column;
                gap: 0.5rem;
            }

            .btn-action {
                flex-direction: row;
                justify-content: center;
                gap: 0.5rem;
                padding: 0.75rem;
            }
        }
    </style>

</head>

<body>
    <?php
    // Tell the sidebar which menu item to highlight
    $activePage = 'appointments';
    include '../../../includes/sidebar_patient.php';
    ?>

    <section class="content-wrapper">
        <!-- Breadcrumb Navigation -->
        <div class="breadcrumb" style="margin-top: 50px;">
            <a href="../dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <span> > </span>
            <span style="color: #0077b6; font-weight: 600;">My Appointments</span>
        </div>

        <div class="page-header">
            <h1><i class="fas fa-calendar-check" style="margin-right: 0.5rem;"></i>My Appointments</h1>
            <div class="action-buttons">
                <a href="book_appointment.php" class="btn btn-primary">
                    <i class="fas fa-calendar-plus"></i>
                    <span class="hide-on-mobile">Book New Appointment</span>
                </a>
                <button class="btn btn-secondary" onclick="downloadAppointmentHistory()">
                    <i class="fas fa-download"></i>
                    <span class="hide-on-mobile">Download History</span>
                </button>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Appointments Section -->
        <div class="section-container">
            <div class="section-header">
                <div class="section-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <h2 class="section-title">Appointment History</h2>
                <div style="margin-left: auto; color: #6c757d; font-size: 0.9rem;">
                    <i class="fas fa-info-circle"></i> Showing recent 20 appointments
                </div>
            </div>

            <!-- Search and Filter Section -->
            <div class="search-filters">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="appointment-id-search"><i class="fas fa-hashtag"></i> Appointment ID</label>
                        <input type="text" id="appointment-id-search" placeholder="APT-00000001" 
                            maxlength="12" onkeypress="handleSearchKeyPress(event, 'appointment')"
                            oninput="formatAppointmentId(this)">
                    </div>
                    <div class="filter-group">
                        <label for="facility-filter"><i class="fas fa-hospital-alt"></i> Healthcare Facility</label>
                        <select id="facility-filter">
                            <option value="">All Facilities</option>
                            <!-- Options will be loaded dynamically based on patient's barangay -->
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="appointment-date-from"><i class="fas fa-calendar-alt"></i> Date From</label>
                        <input type="date" id="appointment-date-from">
                    </div>
                    <div class="filter-group">
                        <label for="appointment-date-to"><i class="fas fa-calendar-alt"></i> Date To</label>
                        <input type="date" id="appointment-date-to">
                    </div>
                    <div class="filter-group">
                        <label for="appointment-status-filter"><i class="fas fa-info-circle"></i> Status</label>
                        <select id="appointment-status-filter">
                            <option value="">All Statuses</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>
                    <div class="filter-group filter-actions-group">
                        <div class="filter-actions">
                            <button type="button" class="btn-filter btn-filter-primary" onclick="filterAppointmentsBySearch()">
                                <i class="fas fa-search"></i> Apply Filters
                            </button>
                            <button type="button" class="btn-filter btn-filter-secondary" onclick="clearAppointmentFilters()">
                                <i class="fas fa-times"></i> Clear All
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <div class="filter-tab active" onclick="filterAppointments('all', this)">
                    <i class="fas fa-list"></i> All Appointments
                </div>
                <div class="filter-tab" onclick="filterAppointments('confirmed', this)">
                    <i class="fas fa-check-circle"></i> Confirmed
                </div>
                <div class="filter-tab" onclick="filterAppointments('completed', this)">
                    <i class="fas fa-calendar-check"></i> Completed
                </div>
                <div class="filter-tab" onclick="filterAppointments('cancelled', this)">
                    <i class="fas fa-times-circle"></i> Cancelled
                </div>
            </div>

            <!-- Appointments Grid -->
            <div class="card-grid" id="appointments-grid">
                <?php if (empty($appointments)): ?>
                    <div class="empty-state" style="grid-column: 1 / -1;">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Appointments Found</h3>
                        <p>You haven't booked any appointments yet. Click "Create Appointment" to schedule your first appointment.</p>
                        <a href="book_appointment.php" class="btn btn-primary" style="display:inline-flex; margin-top: 1rem;">
                            <i class="fas fa-calendar-plus"></i> Book Your First Appointment
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($appointments as $appointment): ?>
                        <?php
                        $appointment_date = new DateTime($appointment['scheduled_date']);
                        $appointment_time = new DateTime($appointment['scheduled_time']);
                        $created_date = new DateTime($appointment['created_at']);
                        $is_priority = $patient_info['priority_level'] == 1;
                        $appointment_id = 'APT-' . str_pad($appointment['appointment_id'], 8, '0', STR_PAD_LEFT);
                        
                        // Ensure status always has a value for display
                        $display_status = $appointment['status'] ?? 'confirmed';
                        if (empty($display_status) || is_null($display_status)) {
                            $display_status = 'confirmed';
                        }
                        ?>
                        <div class="appointment-card" data-status="<?php echo $display_status; ?>" data-appointment-date="<?php echo $appointment['scheduled_date']; ?>" data-facility-id="<?php echo $appointment['facility_id']; ?>">
                            <?php if ($is_priority): ?>
                                <div class="priority-indicator" title="Priority Patient">
                                    <i class="fas fa-star"></i>
                                </div>
                            <?php endif; ?>

                            <!-- Card Header with ID and Status -->
                            <div class="card-header">
                                <div class="appointment-id">
                                    <h3 class="card-title"><?php echo $appointment_id; ?></h3>
                                    <span class="booking-date">Booked: <?php echo $created_date->format('M j, Y'); ?></span>
                                </div>
                                <span class="status-badge status-<?php echo strtolower($display_status); ?>">
                                    <?php echo ucfirst($display_status); ?>
                                </span>
                            </div>

                            <!-- Main Appointment Details -->
                            <div class="card-main-info">
                                <div class="date-time-section">
                                    <div class="date-display">
                                        <i class="fas fa-calendar-day"></i>
                                        <div class="date-info">
                                            <span class="date"><?php echo $appointment_date->format('M j, Y'); ?></span>
                                            <span class="day"><?php echo $appointment_date->format('l'); ?></span>
                                        </div>
                                    </div>
                                    <div class="time-display">
                                        <i class="fas fa-clock"></i>
                                        <span class="time"><?php echo $appointment_time->format('g:i A'); ?></span>
                                    </div>
                                </div>

                                <div class="facility-service-section">
                                    <div class="info-item">
                                        <i class="fas fa-hospital-alt"></i>
                                        <div class="info-content">
                                            <span class="label">Facility</span>
                                            <span class="value"><?php echo htmlspecialchars($appointment['facility_name']); ?></span>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($appointment['service_name'])): ?>
                                    <div class="info-item">
                                        <i class="fas fa-user-md"></i>
                                        <div class="info-content">
                                            <span class="label">Service</span>
                                            <span class="value"><?php echo htmlspecialchars($appointment['service_name']); ?></span>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($appointment['referral_num'])): ?>
                                    <div class="info-item">
                                        <i class="fas fa-file-medical-alt"></i>
                                        <div class="info-content">
                                            <span class="label">Referral</span>
                                            <span class="value">#<?php echo htmlspecialchars($appointment['referral_num']); ?></span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Card Actions -->
                            <div class="card-actions">
                                <button class="btn btn-action btn-view" onclick="viewAppointmentDetails(<?php echo $appointment['appointment_id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                    <span>View Details</span>
                                </button>
                                
                                <button class="btn btn-action btn-qr" onclick="showQRCode(<?php echo $appointment['appointment_id']; ?>)">
                                    <i class="fas fa-qrcode"></i>
                                    <span>QR Code</span>
                                </button>
                                
                                <?php
                                // Show cancel button for appointments that can still be cancelled
                                $current_status = $appointment['status'];
                                $is_cancelled = ($current_status && in_array(strtolower(trim($current_status)), ['cancelled', 'completed', 'no-show']));

                                if (!$is_cancelled):
                                ?>
                                <button class="btn btn-action btn-cancel" onclick="showCancelModal(<?php echo $appointment['appointment_id']; ?>, '<?php echo $appointment_id; ?>')">
                                    <i class="fas fa-times"></i>
                                    <span>Cancel</span>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>


    </section>

    <!-- Notification Modal -->
    <div id="notificationModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 450px; margin: 15% auto;">
            <div class="modal-header" id="notification-header">
                <h3 id="notification-title">
                    <i id="notification-icon" class="fas fa-info-circle"></i>
                    <span id="notification-title-text">Notification</span>
                </h3>
                <button class="close" onclick="closeNotificationModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="notification-message" style="font-size: 1rem; line-height: 1.5; color: #495057; text-align: center; padding: 1rem 0;"></div>
            </div>
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn btn-primary" onclick="closeNotificationModal()" style="min-width: 100px;">
                    <i class="fas fa-check"></i> OK
                </button>
            </div>
        </div>
    </div>

    <!-- Cancel Appointment Modal -->
    <div id="cancelModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Cancel Appointment</h3>
                <button type="button" class="close" onclick="closeCancelModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="cancellation-warning">
                    <div class="warning-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="warning-content">
                        <h4>Are you sure you want to cancel this appointment?</h4>
                        <p>This action cannot be undone. Please provide a reason for cancellation.</p>
                    </div>
                </div>

                <div class="cancel-form-group">
                    <label for="cancellation-reason" class="cancel-form-label">Reason for Cancellation <span class="required">*</span></label>
                    <select id="cancellation-reason" class="cancel-form-control" required>
                        <option value="">Select a reason...</option>
                        <option value="Personal Emergency">Personal Emergency</option>
                        <option value="Schedule Conflict">Schedule Conflict</option>
                        <option value="Feeling Better">Feeling Better / No Longer Needed</option>
                        <option value="Transportation Issues">Transportation Issues</option>
                        <option value="Financial Concerns">Financial Concerns</option>
                        <option value="Found Alternative Care">Found Alternative Care</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="cancel-form-group" id="other-reason-group" style="display: none;">
                    <label for="other-reason" class="cancel-form-label">Please specify:</label>
                    <textarea id="other-reason" class="cancel-form-control" rows="3" placeholder="Please provide details..."></textarea>
                </div>

                <div class="appointment-info" id="cancel-appointment-info">
                    <!-- Appointment details will be populated here -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCancelModal()">
                    <i class="fas fa-arrow-left"></i> Keep Appointment
                </button>
                <button type="button" class="btn btn-danger" id="confirm-cancel-btn" onclick="confirmCancellation()">
                    <i class="fas fa-times"></i> Cancel Appointment
                </button>
            </div>
        </div>
    </div>

    <!-- View Appointment Modal -->
    <div id="viewModal" class="modal" style="display: none;">
        <div class="modal-content view-modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-eye"></i> Appointment Details</h3>
                <div class="header-actions">
                    <button type="button" class="btn-icon" onclick="printAppointment()" title="Print Appointment">
                        <i class="fas fa-print"></i>
                    </button>
                    <button type="button" class="close" onclick="closeViewModal()">&times;</button>
                </div>
            </div>
            <div class="modal-body" id="appointment-details-content">
                <!-- Appointment details will be populated here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeViewModal()">
                    <i class="fas fa-times"></i> Close
                </button>
                <button type="button" class="btn btn-primary" onclick="printAppointment()">
                    <i class="fas fa-print"></i> Print Details
                </button>
            </div>
        </div>
    </div>

    <!-- QR Code Modal -->
    <div id="qrModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 450px; margin: 10% auto;">
            <div class="modal-header">
                <h3>
                    <i class="fas fa-qrcode"></i>
                    <span>QR Code for Check-in</span>
                </h3>
                <button class="close" onclick="closeQRModal()">&times;</button>
            </div>
            <div class="modal-body" style="text-align: center; padding: 2rem;">
                <div id="qr-code-container">
                    <!-- QR code will be loaded here -->
                </div>
                <div id="qr-instructions" style="margin-top: 1rem; color: #6c757d; font-size: 0.9rem;">
                    <p><i class="fas fa-info-circle"></i> Show this QR code at the check-in station for instant verification</p>
                    <p><strong>Appointment ID:</strong> <span id="qr-appointment-id"></span></p>
                </div>
                <div id="qr-loading" style="display: none;">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>Loading QR code...</p>
                </div>
                <div id="qr-error" style="display: none; color: #dc3545;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Failed to load QR code. Please try again.</p>
                </div>
            </div>
            <div class="modal-footer" style="justify-content: center;">
                <button type="button" class="btn btn-secondary" onclick="closeQRModal()">
                    <i class="fas fa-times"></i> Close
                </button>
                <button type="button" class="btn btn-primary" onclick="downloadQR()" id="download-qr-btn" style="display: none;">
                    <i class="fas fa-download"></i> Download QR
                </button>
            </div>
        </div>
    </div>

    <script>
        // Global variables for modal management
        let currentAppointmentData = null;
        let currentQRAppointmentId = null;

        // Modal Management Functions
        function showNotificationModal(type, title, message) {
            const modal = document.getElementById('notificationModal');
            const header = document.getElementById('notification-header');
            const titleText = document.getElementById('notification-title-text');
            const icon = document.getElementById('notification-icon');
            const messageEl = document.getElementById('notification-message');
            
            // Remove existing notification classes
            modal.className = 'modal';
            modal.classList.add(`notification-${type}`);
            
            // Set content
            titleText.textContent = title;
            messageEl.textContent = message;
            
            // Set appropriate icon
            switch(type) {
                case 'success':
                    icon.className = 'fas fa-check-circle';
                    break;
                case 'error':
                    icon.className = 'fas fa-exclamation-triangle';
                    break;
                case 'warning':
                    icon.className = 'fas fa-exclamation-triangle';
                    break;
                default:
                    icon.className = 'fas fa-info-circle';
            }
            
            modal.style.display = 'block';
            
            // Auto-close after 5 seconds for success messages
            if (type === 'success') {
                setTimeout(() => {
                    closeNotificationModal();
                }, 5000);
            }
        }

        function closeNotificationModal() {
            document.getElementById('notificationModal').style.display = 'none';
        }

        function showCancelModal(appointmentId, appointmentNumber) {
            // Fetch appointment details and populate modal
            fetch(`/wbhsms-cho-koronadal-1/api/patient_appointment_details.php?appointment_id=${appointmentId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        currentAppointmentData = data.appointment;
                        populateCancelModal(data.appointment, appointmentNumber);
                        document.getElementById('cancelModal').style.display = 'block';
                    } else {
                        showNotificationModal('error', 'Error', data.message || 'Failed to load appointment details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotificationModal('error', 'Error', 'Failed to load appointment details. Please try again.');
                });
        }

        function populateCancelModal(appointment, appointmentNumber) {
            const infoContainer = document.getElementById('cancel-appointment-info');
            const appointmentDate = new Date(appointment.scheduled_date);
            const appointmentTime = new Date(`1970-01-01T${appointment.scheduled_time}`);
            
            infoContainer.innerHTML = `
                <h4><i class="fas fa-calendar-alt"></i> Appointment Information</h4>
                <div class="info-text"><strong>Appointment ID:</strong> ${appointmentNumber}</div>
                <div class="info-text"><strong>Facility:</strong> ${appointment.facility_name}</div>
                <div class="info-text"><strong>Service:</strong> ${appointment.service_name}</div>
                <div class="info-text"><strong>Date:</strong> ${appointmentDate.toLocaleDateString('en-US', { 
                    year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' 
                })}</div>
                <div class="info-text"><strong>Time:</strong> ${appointmentTime.toLocaleTimeString('en-US', { 
                    hour: 'numeric', minute: '2-digit', hour12: true 
                })}</div>
            `;
        }

        function closeCancelModal() {
            document.getElementById('cancelModal').style.display = 'none';
            // Reset form
            document.getElementById('cancellation-reason').value = '';
            document.getElementById('other-reason').value = '';
            document.getElementById('other-reason-group').style.display = 'none';
            currentAppointmentData = null;
        }

        function confirmCancellation() {
            const reason = document.getElementById('cancellation-reason').value;
            const otherReason = document.getElementById('other-reason').value;
            
            if (!reason) {
                showNotificationModal('warning', 'Missing Information', 'Please select a reason for cancellation');
                return;
            }
            
            if (reason === 'Other' && !otherReason.trim()) {
                showNotificationModal('warning', 'Missing Information', 'Please specify the reason for cancellation');
                return;
            }
            
            const finalReason = reason === 'Other' ? otherReason : reason;
            
            // Show loading state
            const confirmBtn = document.getElementById('confirm-cancel-btn');
            const originalText = confirmBtn.innerHTML;
            confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cancelling...';
            confirmBtn.disabled = true;
            
            // Submit cancellation
            const formData = new FormData();
            formData.append('appointment_id', currentAppointmentData.appointment_id);
            formData.append('cancellation_reason', finalReason);
            
            fetch('/wbhsms-cho-koronadal-1/api/cancel_appointment.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeCancelModal();
                    showNotificationModal('success', 'Success', 'Appointment cancelled successfully');
                    
                    // Update the appointment card status
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showNotificationModal('error', 'Cancellation Failed', data.message || 'Failed to cancel appointment');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotificationModal('error', 'Error', 'An error occurred while cancelling the appointment');
            })
            .finally(() => {
                // Reset button state
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
            });
        }

        function viewAppointmentDetails(appointmentId) {
            fetch(`/wbhsms-cho-koronadal-1/api/patient_appointment_details.php?appointment_id=${appointmentId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        populateViewModal(data.appointment);
                        document.getElementById('viewModal').style.display = 'block';
                    } else {
                        showNotificationModal('error', 'Error', data.message || 'Failed to load appointment details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotificationModal('error', 'Error', 'Failed to load appointment details. Please try again.');
                });
        }

        function populateViewModal(appointment) {
            const container = document.getElementById('appointment-details-content');
            const appointmentDate = new Date(appointment.scheduled_date);
            const appointmentTime = new Date(`1970-01-01T${appointment.scheduled_time}`);
            const createdDate = new Date(appointment.created_at);
            
            let queueInfo = '';
            if (appointment.queue_number) {
                queueInfo = `
                    <div class="info-item">
                        <label>Queue Number</label>
                        <span>${appointment.queue_number} (${appointment.queue_type})</span>
                    </div>
                    <div class="info-item">
                        <label>Queue Status</label>
                        <span class="status-badge status-${appointment.queue_status}">
                            ${appointment.queue_status.replace('_', ' ').toUpperCase()}
                        </span>
                    </div>
                `;
            }
            
            let referralInfo = '';
            if (appointment.referral_num) {
                referralInfo = `
                    <div class="info-item full-width">
                        <label>Referral Number</label>
                        <span>#${appointment.referral_num}</span>
                    </div>
                `;
            }
            
            container.innerHTML = `
                <div class="details-header">
                    <div class="clinic-info">
                        <h2><i class="fas fa-hospital"></i> CHO Koronadal Health Services</h2>
                        <p>City Health Office - Koronadal City</p>
                    </div>
                    <div class="appointment-id-section">
                        <div class="appointment-id">APT-${String(appointment.appointment_id).padStart(8, '0')}</div>
                        <span class="status-badge status-${appointment.status}">${appointment.status.toUpperCase()}</span>
                    </div>
                </div>
                
                <div class="info-section">
                    <h3><i class="fas fa-user"></i> Patient Information</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Patient Name</label>
                            <span>${appointment.patient_name || 'N/A'}</span>
                        </div>
                        <div class="info-item">
                            <label>Contact Number</label>
                            <span>${appointment.contact_number || 'N/A'}</span>
                        </div>
                    </div>
                </div>
                
                <div class="info-section">
                    <h3><i class="fas fa-calendar-check"></i> Appointment Details</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <label>Facility</label>
                            <span>${appointment.facility_name}</span>
                        </div>
                        <div class="info-item">
                            <label>Service Type</label>
                            <span>${appointment.service_name}</span>
                        </div>
                        <div class="info-item">
                            <label>Scheduled Date</label>
                            <span>${appointmentDate.toLocaleDateString('en-US', { 
                                year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' 
                            })}</span>
                        </div>
                        <div class="info-item">
                            <label>Scheduled Time</label>
                            <span>${appointmentTime.toLocaleTimeString('en-US', { 
                                hour: 'numeric', minute: '2-digit', hour12: true 
                            })}</span>
                        </div>
                        <div class="info-item">
                            <label>Booking Date</label>
                            <span>${createdDate.toLocaleDateString('en-US', { 
                                year: 'numeric', month: 'short', day: 'numeric' 
                            })}</span>
                        </div>
                        <div class="info-item">
                            <label>Status</label>
                            <span class="status-badge status-${appointment.status}">${appointment.status.toUpperCase()}</span>
                        </div>
                        ${queueInfo}
                        ${referralInfo}
                    </div>
                </div>
                
                <div class="details-footer">
                    <p>This appointment confirmation was generated on ${new Date().toLocaleDateString('en-US', { 
                        year: 'numeric', month: 'long', day: 'numeric' 
                    })}</p>
                </div>
            `;
        }

        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }

        function showQRCode(appointmentId) {
            currentQRAppointmentId = appointmentId;
            const modal = document.getElementById('qrModal');
            const container = document.getElementById('qr-code-container');
            const loading = document.getElementById('qr-loading');
            const error = document.getElementById('qr-error');
            const appointmentIdSpan = document.getElementById('qr-appointment-id');
            const downloadBtn = document.getElementById('download-qr-btn');
            
            // Reset modal state
            container.innerHTML = '';
            loading.style.display = 'block';
            error.style.display = 'none';
            downloadBtn.style.display = 'none';
            appointmentIdSpan.textContent = `APT-${String(appointmentId).padStart(8, '0')}`;
            
            modal.style.display = 'block';
            
            // Generate QR code
            fetch(`/wbhsms-cho-koronadal-1/api/generate_qr_code.php?appointment_id=${appointmentId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    loading.style.display = 'none';
                    
                    if (data.success) {
                        container.innerHTML = `<img src="${data.qr_code_url}" alt="QR Code" style="max-width: 200px; height: auto; border: 1px solid #ddd; border-radius: 8px;">`;
                        downloadBtn.style.display = 'inline-block';
                    } else {
                        error.style.display = 'block';
                        document.getElementById('qr-error').innerHTML = `
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>${data.message || 'Failed to load QR code. Please try again.'}</p>
                        `;
                    }
                })
                .catch(err => {
                    console.error('Error generating QR code:', err);
                    loading.style.display = 'none';
                    error.style.display = 'block';
                    document.getElementById('qr-error').innerHTML = `
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>Failed to load QR code. Please try again later.</p>
                    `;
                });
        }

        function closeQRModal() {
            document.getElementById('qrModal').style.display = 'none';
            currentQRAppointmentId = null;
        }

        function downloadQR() {
            if (currentQRAppointmentId) {
                window.open(`../../../api/download_qr_code.php?appointment_id=${currentQRAppointmentId}`, '_blank');
            }
        }

        function printAppointment() {
            const content = document.getElementById('appointment-details-content').innerHTML;
            const printWindow = window.open('', '_blank');
            
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Appointment Details</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .details-header { border-bottom: 2px solid #0077b6; padding-bottom: 1rem; margin-bottom: 1.5rem; }
                        .info-section { margin-bottom: 1.5rem; background: #f8f9fa; padding: 1rem; border-radius: 8px; }
                        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
                        .info-item { margin-bottom: 0.5rem; }
                        .info-item label { font-weight: bold; display: block; }
                        .status-badge { background: #e9ecef; padding: 0.2rem 0.5rem; border-radius: 4px; }
                        .details-footer { text-align: center; font-size: 0.8rem; color: #666; margin-top: 2rem; }
                        h2, h3 { color: #0077b6; }
                        @media print { body { margin: 0; } }
                    </style>
                </head>
                <body>
                    ${content}
                </body>
                </html>
            `);
            
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
        }

        // Filter and Search Functions
        function filterAppointments(status, element) {
            // Remove active class from all tabs
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Add active class to clicked tab
            element.classList.add('active');
            
            // Filter appointment cards
            const cards = document.querySelectorAll('.appointment-card');
            cards.forEach(card => {
                const cardStatus = card.getAttribute('data-status');
                if (status === 'all' || cardStatus === status) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Check if any cards are visible
            checkEmptyResults();
        }

        function filterAppointmentsBySearch() {
            const appointmentIdSearch = document.getElementById('appointment-id-search').value.trim();
            const facilityFilter = document.getElementById('facility-filter').value;
            const dateFrom = document.getElementById('appointment-date-from').value;
            const dateTo = document.getElementById('appointment-date-to').value;
            const statusFilter = document.getElementById('appointment-status-filter').value;
            
            const cards = document.querySelectorAll('.appointment-card');
            
            cards.forEach(card => {
                let show = true;
                
                // Appointment ID search
                if (appointmentIdSearch) {
                    const cardTitle = card.querySelector('.card-title');
                    const appointmentId = cardTitle ? cardTitle.textContent.trim() : '';
                    // Support both with and without APT prefix
                    const searchValue = appointmentIdSearch.toUpperCase().startsWith('APT-') 
                        ? appointmentIdSearch.toUpperCase() 
                        : 'APT-' + appointmentIdSearch.padStart(8, '0');
                    
                    if (!appointmentId.includes(searchValue)) {
                        show = false;
                    }
                }
                
                // Facility filter - match by facility ID for precise filtering
                if (facilityFilter) {
                    const cardFacilityId = card.getAttribute('data-facility-id');
                    
                    if (cardFacilityId != facilityFilter) {
                        show = false;
                    }
                }
                
                // Date range filter
                const cardDate = card.getAttribute('data-appointment-date');
                if (dateFrom && cardDate < dateFrom) {
                    show = false;
                }
                if (dateTo && cardDate > dateTo) {
                    show = false;
                }
                
                // Status filter
                if (statusFilter) {
                    const cardStatus = card.getAttribute('data-status');
                    if (cardStatus !== statusFilter) {
                        show = false;
                    }
                }
                
                card.style.display = show ? 'block' : 'none';
            });
            
            checkEmptyResults();
        }

        function clearAppointmentFilters() {
            document.getElementById('appointment-id-search').value = '';
            document.getElementById('facility-filter').selectedIndex = 0; // Reset to "All Facilities"
            document.getElementById('appointment-date-from').value = '';
            document.getElementById('appointment-date-to').value = '';
            document.getElementById('appointment-status-filter').selectedIndex = 0; // Reset to "All Statuses"
            
            // Show all cards
            document.querySelectorAll('.appointment-card').forEach(card => {
                card.style.display = 'block';
            });
            
            // Reset active tab to "All"
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelector('.filter-tab').classList.add('active');
            
            checkEmptyResults();
        }

        function checkEmptyResults() {
            const visibleCards = Array.from(document.querySelectorAll('.appointment-card')).filter(card => 
                card.style.display !== 'none'
            );
            
            let emptyMessage = document.querySelector('.no-results-message');
            
            if (visibleCards.length === 0) {
                if (!emptyMessage) {
                    emptyMessage = document.createElement('div');
                    emptyMessage.className = 'no-results-message';
                    emptyMessage.style.gridColumn = '1 / -1';
                    emptyMessage.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-search"></i>
                            <h3>No Appointments Found</h3>
                            <p>No appointments match your current search criteria.</p>
                            <button class="btn btn-outline-secondary" onclick="clearAppointmentFilters()">
                                <i class="fas fa-times"></i> Clear Filters
                            </button>
                        </div>
                    `;
                    document.getElementById('appointments-grid').appendChild(emptyMessage);
                }
                emptyMessage.style.display = 'block';
            } else if (emptyMessage) {
                emptyMessage.style.display = 'none';
            }
        }

        function handleSearchKeyPress(event, type) {
            if (event.key === 'Enter') {
                if (type === 'appointment') {
                    filterAppointmentsBySearch();
                }
            }
        }

        function formatAppointmentId(input) {
            let value = input.value.toUpperCase();
            
            // Remove any non-alphanumeric characters except hyphen
            value = value.replace(/[^A-Z0-9-]/g, '');
            
            // If user types numbers without APT prefix, add it
            if (/^\d/.test(value)) {
                value = 'APT-' + value;
            }
            
            // If user types APT without hyphen, add it
            if (value.startsWith('APT') && !value.startsWith('APT-')) {
                value = 'APT-' + value.substring(3);
            }
            
            // Limit to APT- + 8 digits
            if (value.startsWith('APT-')) {
                const numbers = value.substring(4).replace(/\D/g, '');
                value = 'APT-' + numbers.substring(0, 8);
            }
            
            input.value = value;
        }

        function downloadAppointmentHistory() {
            showNotificationModal('info', 'Download Started', 'Your appointment history is being prepared...');
            
            // Create download link
            const link = document.createElement('a');
            link.href = '../../../api/download_appointment_history.php';
            link.download = 'appointment_history.pdf';
            link.click();
        }

        // Load patient facilities for filtering
        function loadPatientFacilities() {
            fetch('/wbhsms-cho-koronadal-1/api/get_patient_facilities_for_appointments.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.patientFacilities = data.facilities;
                        populateFacilityFilter(data.facilities);
                    } else {
                        console.error('Failed to load patient facilities:', data.error);
                    }
                })
                .catch(error => {
                    console.error('Error loading patient facilities:', error);
                });
        }

        function populateFacilityFilter(facilities) {
            const facilitySelect = document.getElementById('facility-filter');
            
            // Clear existing options except "All Facilities"
            while (facilitySelect.children.length > 1) {
                facilitySelect.removeChild(facilitySelect.lastChild);
            }
            
            // Add patient's facilities
            facilities.forEach(facility => {
                const option = document.createElement('option');
                option.value = facility.facility_id;
                option.textContent = facility.name;
                facilitySelect.appendChild(option);
            });
        }

        // Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Load patient facilities on page load
            loadPatientFacilities();
            
            // Handle cancellation reason change
            const reasonSelect = document.getElementById('cancellation-reason');
            const otherReasonGroup = document.getElementById('other-reason-group');
            
            if (reasonSelect && otherReasonGroup) {
                reasonSelect.addEventListener('change', function() {
                    if (this.value === 'Other') {
                        otherReasonGroup.style.display = 'block';
                    } else {
                        otherReasonGroup.style.display = 'none';
                    }
                });
            }
            
            // Close modals when clicking outside
            window.addEventListener('click', function(event) {
                const modals = ['notificationModal', 'cancelModal', 'viewModal', 'qrModal'];
                
                modals.forEach(modalId => {
                    const modal = document.getElementById(modalId);
                    if (event.target === modal) {
                        modal.style.display = 'none';
                        
                        // Reset specific modal states
                        if (modalId === 'cancelModal') {
                            closeCancelModal();
                        } else if (modalId === 'qrModal') {
                            closeQRModal();
                        }
                    }
                });
            });
            
            // Handle escape key to close modals
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    const openModals = document.querySelectorAll('.modal[style*="block"]');
                    openModals.forEach(modal => {
                        modal.style.display = 'none';
                        
                        // Reset specific modal states
                        if (modal.id === 'cancelModal') {
                            closeCancelModal();
                        } else if (modal.id === 'qrModal') {
                            closeQRModal();
                        }
                    });
                }
            });
        });
    </script>

</body>

</html>