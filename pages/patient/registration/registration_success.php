<?php
session_start();

// Use absolute path resolution
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/env.php'; // Load environment variables
require_once $root_path . '/utils/StandardEmailTemplate.php';

// Load PHPMailer classes
if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
    $vendorAutoload = $root_path . '/vendor/autoload.php';
    if (file_exists($vendorAutoload)) {
        require_once $vendorAutoload;
    } else {
        require_once $root_path . '/vendor/phpmailer/phpmailer/src/PHPMailer.php';
        require_once $root_path . '/vendor/phpmailer/phpmailer/src/SMTP.php';
        require_once $root_path . '/vendor/phpmailer/phpmailer/src/Exception.php';
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Get registration data from session (set during OTP verification)
$username = isset($_SESSION['registration_username']) ? htmlspecialchars($_SESSION['registration_username']) : null;
$email_sent = false;

// Send welcome email with Patient ID if username exists
if ($username && isset($_SESSION['registration_email']) && !empty($_SESSION['registration_email'])) {
    $patient_email = $_SESSION['registration_email'];
    $patient_name = trim(($_SESSION['registration_first_name'] ?? '') . ' ' . ($_SESSION['registration_last_name'] ?? ''));
    
    // Check if email bypass is enabled for development
    $bypassEmail = empty($_ENV['SMTP_PASS']) || $_ENV['SMTP_PASS'] === 'disabled';
    
    if (!$bypassEmail) {
        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            
            // SMTP Configuration
            $mail->isSMTP();
            $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['SMTP_USER'] ?? '';
            $mail->Password = $_ENV['SMTP_PASS'] ?? '';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $_ENV['SMTP_PORT'] ?? 587;
            
            $fromEmail = $_ENV['SMTP_FROM'] ?? 'cityhealthofficeofkoronadal@gmail.com';
            $fromName = $_ENV['SMTP_FROM_NAME'] ?? 'City Health Office of Koronadal';

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($patient_email, $patient_name);

            $mail->isHTML(true);
            $mail->Subject = 'Welcome to CHO Koronadal - Your Patient ID';
            
            // Generate patient welcome email content
            $welcome_content = StandardEmailTemplate::generatePatientWelcomeContent([
                'patient_name' => $patient_name,
                'patient_id' => $username,
                'login_url' => 'https://cityhealthofficeofkoronadal.site/pages/patient/auth/patient_login.php',
                'contact_phone' => $_ENV['CONTACT_PHONE'] ?? '(083) 228-8042',
                'contact_email' => $_ENV['CONTACT_EMAIL'] ?? 'info@chokoronadal.gov.ph'
            ]);
            
            $mail->Body = StandardEmailTemplate::generateTemplate([
                'title' => 'Welcome to CHO Koronadal Healthcare',
                'content' => $welcome_content,
                'type' => 'welcome'
            ]);
            
            $mail->AltBody = "Welcome to CHO Koronadal! Your Patient ID is: {$username}. Please log in and complete your profile.";

            $mail->send();
            $email_sent = true;
            
        } catch (Exception $e) {
            // Log error but don't prevent success page display
            error_log('Patient welcome email error: ' . $e->getMessage());
        }
    } else {
        // Development mode - assume email sent
        $email_sent = true;
        if (getenv('APP_DEBUG') === '1') {
            error_log("DEVELOPMENT MODE: Patient welcome email for {$patient_email} with Patient ID: {$username}");
        }
    }
}

// Clear the session data since registration is complete
if (isset($_SESSION['registration_username'])) {
    unset($_SESSION['registration_username']);
}
if (isset($_SESSION['registration_email'])) {
    unset($_SESSION['registration_email']);
}
if (isset($_SESSION['registration_first_name'])) {
    unset($_SESSION['registration_first_name']);
}
if (isset($_SESSION['registration_last_name'])) {
    unset($_SESSION['registration_last_name']);
}
if (isset($_SESSION['registration_otp'])) {
    unset($_SESSION['registration_otp']);
}
if (isset($_SESSION['registration_data'])) {
    unset($_SESSION['registration_data']);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
        <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <title>Registration Success</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        :root {
            --brand: #007bff;
            --brand-600: #0056b3;
            --text: #03045e;
            --muted: #6c757d;
            --border: #ced4da;
            --surface: #ffffff;
            --shadow: 0 8px 20px rgba(0, 0, 0, .15);
            --focus-ring: 0 0 0 3px rgba(0, 123, 255, .25);
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            background: #f8fafc;
            color: #222;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background-image: url('https://ik.imagekit.io/wbhsmslogo/Blue%20Minimalist%20Background.png?updatedAt=1752410073954');
            background-size: cover;
            background-attachment: fixed;
            background-position: center;
            background-repeat: no-repeat;
        }

        header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000
        }

        .logo-container {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 14px 0
        }

        .logo {
            width: 100px;
            height: auto
        }

        .homepage {
            min-height: 100vh;
            display: grid;
            place-items: start center;
            padding: 160px 16px 40px
        }

        .container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .12);
            padding: 36px 32px 28px;
            max-width: 480px;
            width: 100%;
            text-align: center;
        }

        .success {
            color: #006400;
        }

        .icon {
            font-size: 3rem;
            margin-bottom: 10px;
            color: #006400;
        }

        .btn {
            display: inline-block;
            margin-top: 18px;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            background: #007bff;
            color: #fff;
            font-weight: 700;
            font-size: 1.1rem;
            cursor: pointer;
            transition: background 0.13s;
            text-decoration: none;
        }

        .btn:hover {
            background: #0056b3;
        }

        .credential-box {
            margin: 18px 0;
            padding: 14px;
            border: 2px dashed #007bff;
            border-radius: 8px;
            background: #f0f9ff;
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e3a8a;
        }

        .countdown {
            font-size: 1.05em;
            color: #1e40af;
            margin-top: 12px;
            font-weight: 600;
        }

        .email-notice {
            margin: 20px 0;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            background: #d1fae5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }

        .email-notice.warning {
            background: #fef3c7;
            border-color: #fcd34d;
            color: #92400e;
        }

        .email-notice i {
            font-size: 1.2em;
            margin-right: 8px;
        }

        .email-notice span {
            font-size: 0.9em;
            opacity: 0.8;
        }
    </style>
</head>

<body>
    <header>
        <div class="logo-container">
            <img class="logo" src="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128" alt="CHO Koronadal Logo" />
        </div>
    </header>

    <section class="homepage">
        <div class="container">
            <?php if ($username): ?>
                <div class="icon"><i class="fa-solid fa-circle-check"></i></div>
                <h2 class="success">Registration Successful!</h2>
                <p>Your account has been created successfully. Your Patient ID is:</p>
                <div class="credential-box">
                    <i class="fa-solid fa-user"></i> <?= $username ?>
                </div>
                
                <?php if ($email_sent): ?>
                    <div class="email-notice">
                        <i class="fa-solid fa-envelope"></i>
                        <strong>Patient ID sent to your email!</strong><br>
                        <span>Check your inbox for login instructions and profile completion guide.</span>
                    </div>
                <?php else: ?>
                    <div class="email-notice warning">
                        <i class="fa-solid fa-exclamation-triangle"></i>
                        <strong>Email delivery issue</strong><br>
                        <span>Please save your Patient ID above for login.</span>
                    </div>
                <?php endif; ?>
                
                <div class="countdown">
                    Redirecting to login page in <span id="timer">10</span> seconds...
                </div>
                <a href="../auth/patient_login.php" class="btn" id="backBtn">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i> Back to Login
                </a>
                <script>
                    let seconds = 10;
                    const timerSpan = document.getElementById('timer');
                    const loginUrl = '../auth/patient_login.php';
                    const countdown = setInterval(() => {
                        seconds--;
                        timerSpan.textContent = seconds;
                        if (seconds <= 0) {
                            clearInterval(countdown);
                            window.location.href = loginUrl;
                        }
                    }, 1000);
                    document.getElementById('backBtn').addEventListener('click', () => {
                        window.location.href = loginUrl;
                    });
                </script>
            <?php else: ?>
                <div class="icon" style="color:#b91c1c"><i class="fa-solid fa-circle-xmark"></i></div>
                <h2 style="color:#b91c1c">Registration Failed</h2>
                <p>We could not retrieve your username. Please try registering again.</p>
                <a href="patient_registration.php" class="btn">
                    <i class="fa-solid fa-user-plus"></i> Try Again
                </a>
            <?php endif; ?>
        </div>
    </section>
</body>

</html>