<?php
// Start output buffering at the very beginning
ob_start();

// Security headers for production
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Production-ready error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');  // Never show errors to users in production
ini_set('log_errors', '1');      // Log errors for debugging

// Set error handler for production
set_error_handler(function ($severity, $message, $file, $line) {
    error_log("Patient ID Card Error [$severity]: $message in $file on line $line");
    return true; // Don't execute PHP's internal error handler
});

// Set exception handler for uncaught exceptions
set_exception_handler(function ($exception) {
    error_log("Patient ID Card Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());

    // Clean any output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }

    header('HTTP/1.1 500 Internal Server Error');
    die('An unexpected error occurred. Please try again later.');
});

// Path setup
$root_path = dirname(dirname(dirname(__DIR__)));

// Load configuration first
require_once $root_path . '/config/env.php';

// Use patient session 
require_once $root_path . '/config/session/patient_session.php';

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if patient is logged in
if (!is_patient_logged_in()) {
    header('Location: ../auth/patient_login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit();
}

// Database connection
require_once $root_path . '/config/db.php';

// Get patient ID from session
$patient_id = $_SESSION['patient_id'];

// Set active page for sidebar highlighting
$activePage = 'id_card';

// Fetch patient data including personal information and emergency contact
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.patient_id,
            p.username,
            p.first_name,
            p.middle_name,
            p.last_name,
            p.suffix,
            p.date_of_birth,
            p.sex,
            p.contact_number,
            p.email,
            p.status,
            p.qr_code,
            pi.profile_photo,
            pi.street,
            b.barangay_name,
            ec.emergency_first_name,
            ec.emergency_middle_name,
            ec.emergency_last_name,
            ec.emergency_contact_number,
            ec.emergency_relationship
        FROM patients p
        LEFT JOIN personal_information pi ON p.patient_id = pi.patient_id
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
        LEFT JOIN emergency_contact ec ON p.patient_id = ec.patient_id
        WHERE p.patient_id = ? AND p.status = 'active'
    ");

    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        error_log("Patient ID Card: Patient not found or inactive for ID: " . $patient_id);
        header('Location: ../dashboard.php?error=patient_not_found');
        exit();
    }

    // Build full name
    $nameParts = [];
    if (!empty($patient['first_name'])) $nameParts[] = $patient['first_name'];
    if (!empty($patient['middle_name'])) $nameParts[] = $patient['middle_name'];
    if (!empty($patient['last_name'])) $nameParts[] = $patient['last_name'];
    if (!empty($patient['suffix'])) $nameParts[] = $patient['suffix'];
    $fullName = implode(' ', $nameParts);

    // Build emergency contact name
    $emergencyNameParts = [];
    if (!empty($patient['emergency_last_name'])) $emergencyNameParts[] = $patient['emergency_last_name'];
    if (!empty($patient['emergency_first_name'])) $emergencyNameParts[] = $patient['emergency_first_name'];
    if (!empty($patient['emergency_middle_name'])) $emergencyNameParts[] = substr($patient['emergency_middle_name'], 0, 1) . '.';
    $emergencyName = !empty($emergencyNameParts) ? implode(', ', [$emergencyNameParts[0], implode(' ', array_slice($emergencyNameParts, 1))]) : 'N/A';

    // Set defaults for missing data
    $defaults = [
        'name' => $fullName,
        'patient_number' => $patient['username'] ?? 'N/A'
    ];

    // Also set session variables for sidebar
    $_SESSION['patient_name'] = $fullName;
    $_SESSION['patient_number'] = $patient['username'];
} catch (Exception $e) {
    error_log("Patient ID Card Database Error: " . $e->getMessage());
    header('Location: ../dashboard.php?error=database_error');
    exit();
}

// Use relative path for assets
$assets_path = '../../../assets';

// Logo URL with fallback
function getLogoUrl($assets_path) {
    $local_logo = $assets_path . '/images/Nav_LogoClosed.png';
    $fallback_logo = 'https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128';
    
    // Check if local logo exists
    $logo_path = dirname(dirname(dirname(__DIR__))) . '/assets/images/Nav_LogoClosed.png';
    if (file_exists($logo_path)) {
        return $local_logo;
    } else {
        return $fallback_logo;
    }
}

$logo_url = getLogoUrl($assets_path);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My ID Card - Patient Portal | CHO Koronadal</title>

    <!-- CSS Files -->
    <link rel="stylesheet" href="../../../assets/css/sidebar.css">
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        /* Page-specific styles */
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-header h1 i {
            color: #0077b6;
        }

        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: #666;
        }

        .breadcrumb a {
            color: #0077b6;
            text-decoration: none;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* ID Card Container - Standard ID Card Size */
        .id-card-container {
            /* Standard ID card size: 85.60 × 53.98 mm (3.375 × 2.125 inches) */
            width: 540px;
            /* 3.375 inches at 160 DPI for screen display */
            height: 340px;
            /* 2.125 inches at 160 DPI for screen display */
            margin: 2rem auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            position: relative;
            display: flex;
            flex-direction: column;
        }

        /* Cards Display Container */
        .cards-container {
            display: flex;
            gap: 40px;
            justify-content: center;
            align-items: flex-start;
            flex-wrap: wrap;
            margin: 2rem auto;
            max-width: 1200px;
        }

        .card-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .card-front,
        .card-back {
            width: 540px;
            height: 340px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            position: relative;
            border: 2px solid #e8f4f8;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card-front:hover, .card-back:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        }

        .card-title {
            text-align: center;
            margin-bottom: 15px;
            color: #0077b6;
            font-size: 18px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #f0f8ff, #e8f4f8);
            border-radius: 10px;
            border: 2px solid #0077b6;
            box-shadow: 0 4px 15px rgba(0, 119, 182, 0.2);
            position: relative;
        }

        .card-title::before {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 8px solid transparent;
            border-right: 8px solid transparent;
            border-top: 8px solid #0077b6;
        }

        .card-header {
            background: linear-gradient(135deg, #0077b6, #03045e);
            color: white;
            padding: 10px 15px;
            text-align: center;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border-bottom: 3px solid #ffd60a;
        }

        .card-header .logo {
            width: 28px;
            height: 28px;
            object-fit: contain;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
        }

        .card-header h2 {
            margin: 0;
            font-size: 16px;
            letter-spacing: 0.8px;
            font-weight: 700;
            line-height: 1.2;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }

        /* Front Card Specific Styles */
        .card-front .card-body {
            display: flex;
            flex: 1;
            padding: 18px;
            gap: 18px;
            background: linear-gradient(145deg, #ffffff, #f8fbff);
        }

        .card-front .photo-section {
            flex-shrink: 0;
            width: 130px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 5px;
        }

        .card-front .patient-photo {
            width: 110px;
            height: 130px;
            border-radius: 12px;
            object-fit: cover;
            border: 4px solid #0077b6;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
            margin-bottom: 10px;
        }

        .card-front .patient-id-display {
            color: white;
            font-weight: 700;
            font-size: 11px;
            text-align: center;
            line-height: 1.3;
            background: linear-gradient(135deg, #0077b6, #023e8a);
            padding: 6px 10px;
            border-radius: 8px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15);
            border: 1px solid #0096c7;
        }

        .card-front .patient-id-display i {
            margin-right: 3px;
            font-size: 10px;
        }

        .card-front .info-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            padding: 5px 0;
        }

        .card-front .patient-name {
            margin: 0 0 18px 0;
            color: #03045e;
            font-size: 20px;
            font-weight: 700;
            line-height: 1.2;
            text-transform: uppercase;
            text-align: center;
            border-bottom: 3px solid #0077b6;
            padding-bottom: 10px;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        /* Back Card Specific Styles */
        .card-back .card-body {
            display: flex;
            flex: 1;
            padding: 20px;
            gap: 22px;
            background: linear-gradient(145deg, #ffffff, #f8fbff);
        }

        .card-back .qr-section {
            flex-shrink: 0;
            width: 150px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 5px;
        }

        .card-back .qr-code {
            width: 130px;
            height: 130px;
            object-fit: contain;
            border: 3px solid #0077b6;
            border-radius: 12px;
            background: white;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }

        .card-back .qr-placeholder {
            width: 130px;
            height: 130px;
            background: linear-gradient(135deg, #f0f8ff, #e8f4f8);
            border: 3px solid #0077b6;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            color: #0077b6;
            font-size: 14px;
            font-weight: 700;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }

        .card-back .qr-text {
            margin-top: 10px;
            color: #0077b6;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .card-back .emergency-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            padding: 5px 0;
        }

        .card-back .section-title {
            background: linear-gradient(135deg, #dc2626, #991b1b);
            color: white;
            padding: 10px 15px;
            font-weight: 700;
            margin: 0 0 18px 0;
            font-size: 16px;
            text-transform: uppercase;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
            border: 1px solid #fca5a5;
        }

        .info-item {
            margin-bottom: 10px;
            font-size: 12px;
            line-height: 1.4;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 4px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .info-item:last-child {
            margin-bottom: 0;
            border-bottom: none;
        }

        .info-label {
            color: #0077b6;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 10px;
            min-width: 90px;
            flex-shrink: 0;
            letter-spacing: 0.5px;
        }

        .info-label i {
            color: #0077b6;
            font-size: 11px;
            width: 14px;
            margin-right: 4px;
        }

        .info-value {
            color: #333;
            font-weight: 600;
            font-size: 12px;
            flex: 1;
            word-wrap: break-word;
        }

        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            padding: 25px;
            background-color: #ffffffff;
            border-top: 1px solid #eee;
            border-radius: 12px;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            min-width: 140px;
            justify-content: center;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0077b6, #0096c7);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #005f8a, #007baa);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 119, 182, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: white;
        }

        .btn-secondary:hover {
            background: linear-gradient(135deg, #5a6268, #495057);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.3);
        }

        /* Alert styling */
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left-width: 4px;
            border-left-style: solid;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border-color: #bee5eb;
            border-left-color: #17a2b8;
        }

        .alert i {
            margin-right: 5px;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .cards-container {
                flex-direction: column;
                align-items: center;
                gap: 30px;
            }

            .card-wrapper {
                width: 100%;
                display: flex;
                flex-direction: column;
                align-items: center;
            }

            .card-title {
                font-size: 16px;
                padding: 8px 16px;
                margin-bottom: 12px;
            }

            .card-front,
            .card-back {
                width: 95%;
                max-width: 400px;
                height: auto;
                margin: 0;
            }

            .card-front .card-body {
                flex-direction: column;
                gap: 10px;
            }

            .card-front .photo-section {
                width: 100%;
                flex-direction: row;
                justify-content: center;
                align-items: center;
                gap: 15px;
            }

            .card-front .patient-photo {
                width: 80px;
                height: 100px;
            }

            .card-back .card-body {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }

            .card-back .qr-section {
                width: 100%;
            }

            .card-back .qr-code,
            .card-back .qr-placeholder {
                width: 100px;
                height: 100px;
                margin: 0 auto;
            }

            .action-buttons {
                flex-direction: column;
                align-items: center;
            }

            .action-btn {
                width: 100%;
                max-width: 250px;
            }
        }

        /* Print styles - Clean ID Cards Only */
        @media print {
            @page {
                size: A4 landscape;
                margin: 0;
            }

            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                box-sizing: border-box !important;
            }

            html, body {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                height: 100% !important;
                background: white !important;
                font-family: Arial, sans-serif !important;
                overflow: hidden !important;
            }

            /* Hide ALL UI elements except cards */
            .sidebar,
            .mobile-topbar,
            .nav,
            .navbar,
            .footer,
            .page-header,
            .breadcrumb,
            .action-buttons,
            .alert,
            .navigation,
            .menu,
            header,
            nav,
            .dashboard-nav,
            .user-info,
            .logout,
            .settings,
            .patient-sidebar,
            .sidebar-content,
            .sidebar-wrapper,
            .main-nav,
            .top-nav {
                display: none !important;
                visibility: hidden !important;
            }

            /* Hide all main content except cards */
            .main-content > *:not(.cards-container) {
                display: none !important;
            }

            .homepage {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                height: 100% !important;
                background: white !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }

            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                height: 100% !important;
                background: white !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }

            /* Cards container - clean centered layout */
            .cards-container {
                display: flex !important;
                flex-direction: row !important;
                gap: 20px !important;
                justify-content: center !important;
                align-items: center !important;
                flex-wrap: nowrap !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                height: 100% !important;
                background: white !important;
                page-break-inside: avoid !important;
            }

            .card-wrapper {
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                margin: 0 !important;
                padding: 0 !important;
                page-break-inside: avoid !important;
                flex-shrink: 0 !important;
            }

            /* Keep card titles for identification */
            .card-title {
                display: block !important;
                text-align: center !important;
                margin-bottom: 8px !important;
                color: #0077b6 !important;
                font-size: 12px !important;
                font-weight: 700 !important;
                text-transform: uppercase !important;
                letter-spacing: 1px !important;
                padding: 4px 8px !important;
                background: linear-gradient(135deg, #f0f8ff, #e8f4f8) !important;
                border-radius: 6px !important;
                border: 2px solid #0077b6 !important;
                box-shadow: 0 2px 6px rgba(0, 119, 182, 0.2) !important;
                page-break-inside: avoid !important;
            }

            /* Cards - professional print size */
            .card-front,
            .card-back {
                width: 350px !important;
                height: 220px !important;
                background: white !important;
                border-radius: 10px !important;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
                overflow: hidden !important;
                display: flex !important;
                flex-direction: column !important;
                position: relative !important;
                border: 2px solid #0077b6 !important;
                margin: 0 !important;
                padding: 0 !important;
                page-break-inside: avoid !important;
                transform: none !important;
                transition: none !important;
            }

            /* Header - clean print style */
            .card-header {
                background: linear-gradient(135deg, #0077b6, #03045e) !important;
                color: white !important;
                padding: 6px 10px !important;
                text-align: center !important;
                flex-shrink: 0 !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 6px !important;
                border-bottom: 2px solid #ffd60a !important;
            }

            .card-header .logo {
                width: 18px !important;
                height: 18px !important;
                object-fit: contain !important;
                filter: drop-shadow(0 1px 2px rgba(0,0,0,0.3)) !important;
            }

            .card-header h2 {
                margin: 0 !important;
                font-size: 10px !important;
                letter-spacing: 0.4px !important;
                font-weight: 700 !important;
                line-height: 1.2 !important;
                text-shadow: 0 1px 2px rgba(0,0,0,0.2) !important;
                color: white !important;
            }

            /* Front Card - optimized for print */
            .card-front .card-body {
                display: flex !important;
                flex: 1 !important;
                padding: 10px !important;
                gap: 10px !important;
                background: linear-gradient(145deg, #ffffff, #f8fbff) !important;
            }

            .card-front .photo-section {
                flex-shrink: 0 !important;
                width: 80px !important;
                text-align: center !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: flex-start !important;
                padding: 2px !important;
            }

            .card-front .patient-photo {
                width: 65px !important;
                height: 80px !important;
                border-radius: 6px !important;
                object-fit: cover !important;
                border: 2px solid #0077b6 !important;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15) !important;
                margin-bottom: 4px !important;
            }

            .card-front .patient-id-display {
                color: white !important;
                font-weight: 700 !important;
                font-size: 7px !important;
                text-align: center !important;
                line-height: 1.2 !important;
                background: linear-gradient(135deg, #0077b6, #023e8a) !important;
                padding: 3px 5px !important;
                border-radius: 4px !important;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15) !important;
                border: 1px solid #0096c7 !important;
            }

            .card-front .patient-id-display i {
                margin-right: 1px !important;
                font-size: 6px !important;
            }

            .card-front .info-section {
                flex: 1 !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: flex-start !important;
                padding: 2px 0 !important;
            }

            .card-front .patient-name {
                margin: 0 0 10px 0 !important;
                color: #03045e !important;
                font-size: 12px !important;
                font-weight: 700 !important;
                line-height: 1.1 !important;
                text-transform: uppercase !important;
                text-align: center !important;
                border-bottom: 2px solid #0077b6 !important;
                padding-bottom: 4px !important;
                text-shadow: 0 1px 2px rgba(0,0,0,0.1) !important;
            }

            .card-front .info-item {
                margin-bottom: 4px !important;
                font-size: 8px !important;
                line-height: 1.2 !important;
                display: flex !important;
                align-items: flex-start !important;
                gap: 4px !important;
                padding: 1px 0 !important;
                border-bottom: 1px solid #f0f0f0 !important;
            }

            .card-front .info-item:last-child {
                margin-bottom: 0 !important;
                border-bottom: none !important;
            }

            .card-front .info-label {
                color: #0077b6 !important;
                font-weight: 700 !important;
                text-transform: uppercase !important;
                font-size: 7px !important;
                min-width: 45px !important;
                flex-shrink: 0 !important;
                letter-spacing: 0.2px !important;
            }

            .card-front .info-label i {
                color: #0077b6 !important;
                font-size: 7px !important;
                width: 8px !important;
                margin-right: 1px !important;
            }

            .card-front .info-value {
                color: #333 !important;
                font-weight: 600 !important;
                font-size: 8px !important;
                flex: 1 !important;
                word-wrap: break-word !important;
            }

            /* Back Card - optimized for print */
            .card-back .card-body {
                display: flex !important;
                flex: 1 !important;
                padding: 10px !important;
                gap: 12px !important;
                background: linear-gradient(145deg, #ffffff, #f8fbff) !important;
            }

            .card-back .qr-section {
                flex-shrink: 0 !important;
                width: 95px !important;
                text-align: center !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 2px !important;
            }

            .card-back .qr-code {
                width: 80px !important;
                height: 80px !important;
                object-fit: contain !important;
                border: 2px solid #0077b6 !important;
                border-radius: 6px !important;
                background: white !important;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15) !important;
            }

            .card-back .qr-placeholder {
                width: 80px !important;
                height: 80px !important;
                background: linear-gradient(135deg, #f0f8ff, #e8f4f8) !important;
                border: 2px solid #0077b6 !important;
                border-radius: 6px !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                flex-direction: column !important;
                color: #0077b6 !important;
                font-size: 9px !important;
                font-weight: 700 !important;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15) !important;
            }

            .card-back .qr-text {
                margin-top: 4px !important;
                color: #0077b6 !important;
                font-size: 7px !important;
                font-weight: 700 !important;
                text-align: center !important;
                text-transform: uppercase !important;
                letter-spacing: 0.2px !important;
            }

            .card-back .emergency-section {
                flex: 1 !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: flex-start !important;
                padding: 2px 0 !important;
            }

            .card-back .section-title {
                background: linear-gradient(135deg, #dc2626, #991b1b) !important;
                color: white !important;
                padding: 5px 8px !important;
                font-weight: 700 !important;
                margin: 0 0 10px 0 !important;
                font-size: 9px !important;
                text-transform: uppercase !important;
                border-radius: 4px !important;
                text-align: center !important;
                box-shadow: 0 2px 6px rgba(220, 38, 38, 0.3) !important;
                border: 1px solid #fca5a5 !important;
            }

            .card-back .info-item {
                margin-bottom: 4px !important;
                font-size: 8px !important;
                line-height: 1.2 !important;
                display: flex !important;
                align-items: flex-start !important;
                gap: 4px !important;
                padding: 1px 0 !important;
                border-bottom: 1px solid #f0f0f0 !important;
            }

            .card-back .info-item:last-child {
                margin-bottom: 0 !important;
                border-bottom: none !important;
            }

            .card-back .info-label {
                color: #0077b6 !important;
                font-weight: 700 !important;
                text-transform: uppercase !important;
                font-size: 7px !important;
                min-width: 45px !important;
                flex-shrink: 0 !important;
                letter-spacing: 0.2px !important;
            }

            .card-back .info-label i {
                color: #0077b6 !important;
                font-size: 7px !important;
                width: 8px !important;
                margin-right: 1px !important;
            }

            .card-back .info-value {
                color: #333 !important;
                font-weight: 600 !important;
                font-size: 8px !important;
                flex: 1 !important;
                word-wrap: break-word !important;
            }

            /* Footer text - clean for print */
            .card-back .emergency-section div[style*="margin-top: 20px"] {
                margin-top: 8px !important;
                padding-top: 6px !important;
                font-size: 6px !important;
                border-top: 1px solid #e0e0e0 !important;
                color: #666 !important;
                text-align: center !important;
                line-height: 1.1 !important;
            }
        }
        
        
    </style>
</head>

<body>
    <!-- Include patient sidebar -->
    <?php include $root_path . '/includes/sidebar_patient.php'; ?>

    <div class="homepage" style="margin-left:300px;">
        <div class="main-content">
            <!-- Breadcrumb Navigation -->
            <div class="breadcrumb" style="margin-top: 50px;">
                <a href="../dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
                <i class="fas fa-chevron-right"></i>
                <a href="profile.php"><i class="fas fa-user"></i> Profile</a>
                <i class="fas fa-chevron-right"></i>
                <span>My ID Card</span>
            </div>

            <!-- Page Header -->
            <div class="page-header">
                <h1><i class="fas fa-id-card"></i> My ID Card</h1>
            </div>

            <!-- Info Alert -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                This is your official CHO Koronadal patient ID card. You can print this card for your records or show it during appointments.
            </div>

            <!-- ID Cards - Front and Back -->
            <div class="cards-container">
                <!-- Front Side Wrapper -->
                <div class="card-wrapper">
                    <div class="card-title">FRONT SIDE</div>
                    <div class="card-front" id="cardFront">
                        <!-- Header -->
                        <div class="card-header">
                            <img src="<?php echo $logo_url; ?>" alt="CHO Logo" class="logo" onerror="this.src='https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128'">
                            <h2>CITY HEALTH OFFICE - KORONADAL</h2>
                        </div>

                        <!-- Card Body -->
                        <div class="card-body">
                            <!-- Photo Section -->
                            <div class="photo-section">
                                <?php if (!empty($patient['profile_photo'])): ?>
                                    <img src="data:image/jpeg;base64,<?php echo base64_encode($patient['profile_photo']); ?>"
                                        class="patient-photo" alt="Patient Photo">
                                <?php else: ?>
                                    <img src="<?php echo $assets_path; ?>/images/user-default.png"
                                        class="patient-photo" alt="Patient Photo">
                                <?php endif; ?>

                                <div class="patient-id-display">
                                    <i class="fas fa-id-badge"></i>
                                    ID: <?php echo htmlspecialchars($patient['username']); ?>
                                </div>
                            </div>

                            <!-- Patient Information -->
                            <div class="info-section">
                                <h3 class="patient-name"><?php echo htmlspecialchars($fullName); ?></h3>

                                <div class="info-item">
                                    <span class="info-label">
                                        <i class="fas fa-calendar-alt"></i> DOB:
                                    </span>
                                    <span class="info-value">
                                        <?php 
                                        if (!empty($patient['date_of_birth'])) {
                                            $dob = new DateTime($patient['date_of_birth']);
                                            echo $dob->format('M j, Y');
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </span>
                                </div>

                                <div class="info-item">
                                    <span class="info-label">
                                        <i class="fas fa-venus-mars"></i> SEX:
                                    </span>
                                    <span class="info-value">
                                        <?php echo !empty($patient['sex']) ? htmlspecialchars(strtoupper($patient['sex'])) : 'N/A'; ?>
                                    </span>
                                </div>

                                <div class="info-item">
                                    <span class="info-label">
                                        <i class="fas fa-map-marker-alt"></i> ADDRESS:
                                    </span>
                                    <span class="info-value">
                                        <?php 
                                        $address = [];
                                        if (!empty($patient['street'])) $address[] = $patient['street'];
                                        if (!empty($patient['barangay_name'])) $address[] = $patient['barangay_name'];
                                        echo !empty($address) ? htmlspecialchars(implode(', ', $address)) : 'N/A';
                                        ?>
                                    </span>
                                </div>

                                <div class="info-item">
                                    <span class="info-label">
                                        <i class="fas fa-phone"></i> CONTACT:
                                    </span>
                                    <span class="info-value">
                                        <?php echo !empty($patient['contact_number']) ? htmlspecialchars($patient['contact_number']) : 'N/A'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Back Side Wrapper -->
                <div class="card-wrapper">
                    <div class="card-title">BACK SIDE</div>
                    <div class="card-back" id="cardBack">
                        <!-- Header -->
                        <div class="card-header">
                            <img src="<?php echo $logo_url; ?>" alt="CHO Logo" class="logo" onerror="this.src='https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128'">
                            <h2>CITY HEALTH OFFICE - KORONADAL</h2>
                        </div>

                        <!-- Card Body -->
                        <div class="card-body">
                            <!-- QR Code Section -->
                            <div class="qr-section">
                                <?php if (!empty($patient['qr_code'])): ?>
                                    <img src="data:image/png;base64,<?php echo base64_encode($patient['qr_code']); ?>" 
                                         class="qr-code" alt="Patient QR Code">
                                <?php else: ?>
                                    <div class="qr-placeholder">
                                        <i class="fas fa-qrcode" style="font-size: 24px; margin-bottom: 5px;"></i>
                                        <div>QR CODE</div>
                                    </div>
                                <?php endif; ?>
                                <div class="qr-text">SCAN FOR VERIFICATION</div>
                            </div>

                            <!-- Emergency Contact Section -->
                            <div class="emergency-section">
                                <div class="section-title">
                                    <i class="fas fa-exclamation-triangle"></i> Emergency Contact
                                </div>

                                <div class="info-item">
                                    <span class="info-label">
                                        <i class="fas fa-user"></i> NAME:
                                    </span>
                                    <span class="info-value">
                                        <?php echo htmlspecialchars($emergencyName); ?>
                                    </span>
                                </div>

                                <div class="info-item">
                                    <span class="info-label">
                                        <i class="fas fa-phone"></i> PHONE:
                                    </span>
                                    <span class="info-value">
                                        <?php echo !empty($patient['emergency_contact_number']) ? htmlspecialchars($patient['emergency_contact_number']) : 'N/A'; ?>
                                    </span>
                                </div>

                                <?php if (!empty($patient['emergency_relationship'])): ?>
                                <div class="info-item">
                                    <span class="info-label">
                                        <i class="fas fa-heart"></i> RELATION:
                                    </span>
                                    <span class="info-value">
                                        <?php echo htmlspecialchars(strtoupper($patient['emergency_relationship'])); ?>
                                    </span>
                                </div>
                                <?php endif; ?>

                                <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #e0e0e0; font-size: 10px; color: #666; text-align: center;">
                                    This card is property of CHO Koronadal.<br>
                                    Please return if found.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>            <!-- Action Buttons -->
            <div class="action-buttons">
                <button type="button" class="action-btn btn-primary" onclick="printIdCard()">
                    <i class="fas fa-print"></i> Print ID Card
                </button>
                <a href="profile.php" class="action-btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Profile
                </a>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        function printIdCard() {
            // Use the browser's built-in print functionality
            window.print();
        }

        // Handle any URL parameters for notifications
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);

            if (urlParams.has('success')) {
                // Could add success notification here if needed
            }

            if (urlParams.has('error')) {
                const error = urlParams.get('error');
                let message = 'An error occurred.';

                switch (error) {
                    case 'patient_not_found':
                        message = 'Patient information not found.';
                        break;
                    case 'database_error':
                        message = 'Database connection error. Please try again.';
                        break;
                }

                // Show error message (you could implement a proper notification system)
                console.error('ID Card Error:', message);
            }
        });
    </script>
</body>

</html>