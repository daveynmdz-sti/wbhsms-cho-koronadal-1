<?php
// access_denied.php - Access Denied Page for Employee Management System
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';

// Include reusable topbar component
require_once $root_path . '/includes/topbar.php';

// Get user information if logged in
$user_role = $_SESSION['role'] ?? 'Unknown';
$user_name = ($_SESSION['employee_first_name'] ?? 'Unknown') . ' ' . ($_SESSION['employee_last_name'] ?? 'User');
$attempted_page = $_SERVER['HTTP_REFERER'] ?? 'Unknown page';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <title>Access Denied | CHO Koronadal</title>
    <link rel="stylesheet" href="../../assets/css/topbar.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
        }

        .access-denied-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            margin-top: 80px; /* Account for topbar */
        }

        .access-denied-card {
            background: white;
            border-radius: 20px;
            padding: 3rem;
            max-width: 600px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
        }

        .access-denied-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 0, 0, 0.05) 0%, transparent 70%);
            z-index: 0;
        }

        .access-denied-content {
            position: relative;
            z-index: 1;
        }

        .access-denied-icon {
            font-size: 5rem;
            color: #dc3545;
            margin-bottom: 1.5rem;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .access-denied-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #dc3545;
            margin-bottom: 1rem;
        }

        .access-denied-message {
            font-size: 1.2rem;
            color: #6c757d;
            margin-bottom: 2rem;
            line-height: 1.6;
        }

        .user-info-box {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 15px;
            padding: 1.5rem;
            margin: 2rem 0;
            text-align: left;
        }

        .user-info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid #dee2e6;
        }

        .user-info-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .user-info-label {
            font-weight: 600;
            color: #495057;
        }

        .user-info-value {
            color: #6c757d;
            text-align: right;
            flex: 1;
            margin-left: 1rem;
            word-break: break-word;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 2rem;
        }

        .btn {
            padding: 0.875rem 2rem;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #0056b3, #004085);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
        }

        .btn-secondary:hover {
            background: #545b62;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
        }

        .btn-outline {
            background: transparent;
            color: #007bff;
            border: 2px solid #007bff;
        }

        .btn-outline:hover {
            background: #007bff;
            color: white;
            transform: translateY(-2px);
        }

        .help-text {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 2rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 4px solid #17a2b8;
        }

        .help-text strong {
            color: #495057;
        }

        @media (max-width: 768px) {
            .access-denied-container {
                padding: 1rem;
            }

            .access-denied-card {
                padding: 2rem;
            }

            .access-denied-title {
                font-size: 2rem;
            }

            .access-denied-message {
                font-size: 1.1rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                padding: 1rem 1.5rem;
            }

            .user-info-item {
                flex-direction: column;
                gap: 0.25rem;
            }

            .user-info-value {
                text-align: left;
                margin-left: 0;
            }
        }
    </style>
</head>

<body>
    <?php
    // Render topbar
    renderTopbar([
        'title' => 'Access Denied',
        'back_url' => null,
        'user_type' => 'employee',
        'vendor_path' => '../../vendor/'
    ]);
    ?>

    <div class="access-denied-container">
        <div class="access-denied-card">
            <div class="access-denied-content">
                <div class="access-denied-icon">
                    <i class="fas fa-shield-alt"></i>
                </div>
                
                <h1 class="access-denied-title">Access Denied</h1>
                
                <p class="access-denied-message">
                    Sorry, you don't have permission to access the requested page. Your current role does not have the necessary privileges for this action.
                </p>

                <div class="user-info-box">
                    <div class="user-info-item">
                        <span class="user-info-label">
                            <i class="fas fa-user"></i> Logged in as:
                        </span>
                        <span class="user-info-value"><?= htmlspecialchars($user_name) ?></span>
                    </div>
                    <div class="user-info-item">
                        <span class="user-info-label">
                            <i class="fas fa-id-badge"></i> Your Role:
                        </span>
                        <span class="user-info-value"><?= htmlspecialchars(ucwords($user_role)) ?></span>
                    </div>
                    <div class="user-info-item">
                        <span class="user-info-label">
                            <i class="fas fa-clock"></i> Time:
                        </span>
                        <span class="user-info-value"><?= date('F j, Y g:i A') ?></span>
                    </div>
                </div>

                <div class="action-buttons">
                    <a href="javascript:history.back()" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Go Back
                    </a>
                    <?php if (is_employee_logged_in()): ?>
                    <a href="<?= strtolower($user_role) ?>/dashboard.php" class="btn btn-primary">
                        <i class="fas fa-tachometer-alt"></i>
                        Go to Dashboard
                    </a>
                    <?php endif; ?>
                    <a href="auth/employee_login.php" class="btn btn-outline">
                        <i class="fas fa-sign-out-alt"></i>
                        Logout & Login as Different User
                    </a>
                </div>

                <div class="help-text">
                    <strong>Need Access?</strong> If you believe you should have access to this page, please contact your system administrator or supervisor. They can review and update your account permissions as needed.
                </div>
            </div>
        </div>
    </div>
</body>

</html>