<?php
// pages/user/admin_settings.php
// Universal Employee Settings Page - Password change, email, contact updates
// Author: GitHub Copilot

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Authentication check - use session management function
if (!is_employee_logged_in()) {
    redirect_to_employee_login();
}

// Set active page for sidebar highlighting
$activePage = 'settings';

// Get employee ID - only allow editing own settings
$employee_id = $_SESSION['employee_id'];

// Initialize variables
$success_message = '';
$error_message = '';
$validation_errors = [];
$employee = null;

// Fetch employee data
try {
    $stmt = $conn->prepare("
        SELECT e.*, r.role_name, f.name as facility_name 
        FROM employees e 
        LEFT JOIN roles r ON e.role_id = r.role_id 
        LEFT JOIN facilities f ON e.facility_id = f.facility_id 
        WHERE e.employee_id = ?
    ");
    $stmt->bind_param("i", $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        header('Location: employee_profile.php?error=employee_not_found');
        exit();
    }

    $employee = $result->fetch_assoc();
} catch (Exception $e) {
    header('Location: employee_profile.php?error=fetch_failed');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'update_contact':
                    // Handle email and contact number update
                    $email = trim($_POST['email'] ?? '');
                    $contact_num = trim($_POST['contact_num'] ?? '');
                    
                    // Validation
                    if (empty($email)) $validation_errors[] = 'Email is required';
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $validation_errors[] = 'Invalid email format';
                    if (empty($contact_num)) $validation_errors[] = 'Contact number is required';
                    
                    // Validate contact number format
                    if (!preg_match('/^09\d{9}$/', $contact_num)) {
                        $validation_errors[] = 'Contact number must be a valid Philippine mobile number (09XXXXXXXXX)';
                    }
                    
                    // Check if email is already taken by another employee
                    $email_check_stmt = $conn->prepare("SELECT employee_id FROM employees WHERE email = ? AND employee_id != ?");
                    $email_check_stmt->bind_param("si", $email, $employee_id);
                    $email_check_stmt->execute();
                    if ($email_check_stmt->get_result()->num_rows > 0) {
                        $validation_errors[] = 'Email address is already in use by another employee';
                    }
                    
                    if (empty($validation_errors)) {
                        // Begin transaction
                        $conn->begin_transaction();
                        
                        try {
                            // Store old values for logging
                            $old_values = [
                                'email' => $employee['email'],
                                'contact_num' => $employee['contact_num']
                            ];
                            
                            // Update contact information
                            $update_stmt = $conn->prepare("
                                UPDATE employees SET email = ?, contact_num = ?, updated_at = NOW()
                                WHERE employee_id = ?
                            ");
                            $update_stmt->bind_param("ssi", $email, $contact_num, $employee_id);
                            
                            if (!$update_stmt->execute()) {
                                throw new Exception("Failed to update contact information");
                            }
                            
                            // Log the update activity
                            $new_values = [
                                'email' => $email,
                                'contact_num' => $contact_num
                            ];
                            
                            // Identify what changed
                            $changes = [];
                            foreach ($new_values as $field => $new_value) {
                                if ($old_values[$field] != $new_value) {
                                    $changes[] = "$field: '{$old_values[$field]}' → '$new_value'";
                                }
                            }
                            
                            if (!empty($changes)) {
                                $log_stmt = $conn->prepare("
                                    INSERT INTO user_activity_logs (
                                        admin_id, employee_id, action_type, description
                                    ) VALUES (?, ?, 'update', ?)
                                ");
                                
                                $description = "Self-updated contact information: " . implode(', ', $changes);
                                
                                $log_stmt->bind_param(
                                    "iis",
                                    $employee_id,
                                    $employee_id,
                                    $description
                                );
                                $log_stmt->execute();
                            }
                            
                            // Commit transaction
                            $conn->commit();
                            
                            $success_message = "Contact information updated successfully!";
                            
                            // Refresh employee data
                            $stmt = $conn->prepare("
                                SELECT e.*, r.role_name, f.name as facility_name 
                                FROM employees e 
                                LEFT JOIN roles r ON e.role_id = r.role_id 
                                LEFT JOIN facilities f ON e.facility_id = f.facility_id 
                                WHERE e.employee_id = ?
                            ");
                            $stmt->bind_param("i", $employee_id);
                            $stmt->execute();
                            $employee = $stmt->get_result()->fetch_assoc();
                        } catch (Exception $e) {
                            $conn->rollback();
                            $error_message = "Failed to update contact information: " . $e->getMessage();
                        }
                    }
                    break;
                    
                case 'change_password':
                    // Handle password change
                    $current_password = $_POST['current_password'] ?? '';
                    $new_password = $_POST['new_password'] ?? '';
                    $confirm_password = $_POST['confirm_password'] ?? '';
                    
                    // Validation
                    if (empty($current_password)) $validation_errors[] = 'Current password is required';
                    if (empty($new_password)) $validation_errors[] = 'New password is required';
                    if (empty($confirm_password)) $validation_errors[] = 'Password confirmation is required';
                    
                    if ($new_password !== $confirm_password) {
                        $validation_errors[] = 'New password and confirmation do not match';
                    }
                    
                    if (strlen($new_password) < 8) {
                        $validation_errors[] = 'New password must be at least 8 characters long';
                    }
                    
                    if (empty($validation_errors)) {
                        try {
                            // Verify current password
                            $stmt = $conn->prepare("SELECT password FROM employees WHERE employee_id = ?");
                            $stmt->bind_param("i", $employee_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $user = $result->fetch_assoc();
                            
                            if ($user && password_verify($current_password, $user['password'])) {
                                // Update password
                                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                                $stmt = $conn->prepare("UPDATE employees SET password = ?, updated_at = NOW() WHERE employee_id = ?");
                                $stmt->bind_param("si", $hashed_password, $employee_id);
                                
                                if ($stmt->execute()) {
                                    // Log password change
                                    $log_stmt = $conn->prepare("
                                        INSERT INTO user_activity_logs (
                                            admin_id, employee_id, action_type, description
                                        ) VALUES (?, ?, 'update', ?)
                                    ");
                                    
                                    $description = "Self-changed password";
                                    
                                    $log_stmt->bind_param(
                                        "iis",
                                        $employee_id,
                                        $employee_id,
                                        $description
                                    );
                                    $log_stmt->execute();
                                    
                                    $success_message = "Password changed successfully!";
                                } else {
                                    $error_message = "Failed to change password.";
                                }
                            } else {
                                $validation_errors[] = "Current password is incorrect";
                            }
                        } catch (Exception $e) {
                            $error_message = "Database error: " . $e->getMessage();
                        }
                    }
                    break;
            }
        }
    } catch (Exception $e) {
        $error_message = "System error: " . $e->getMessage();
    }
}

// Determine which sidebar to include based on role
$current_user_role = $_SESSION['role'];
$sidebar_file = match($current_user_role) {
    'admin' => 'sidebar_admin.php',
    'doctor' => 'sidebar_doctor.php',
    'nurse' => 'sidebar_nurse.php',
    'dho' => 'sidebar_dho.php',
    'bhw' => 'sidebar_bhw.php',
    'laboratory_tech' => 'sidebar_laboratory_tech.php',
    'pharmacist' => 'sidebar_pharmacist.php',
    'cashier' => 'sidebar_cashier.php',
    'records_officer' => 'sidebar_records_officer.php',
    default => 'sidebar_admin.php'
};
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></title>

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/css/topbar.css">
    <link rel="stylesheet" href="../../assets/css/profile-edit.css">
    <link rel="stylesheet" href="../../assets/css/edit.css">

    <style>
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(37, 99, 235, 0.08);
            border: 1px solid #e5e7eb;
        }

        .form-section h4 {
            color: #1f2937;
            margin-bottom: 24px;
            font-weight: 600;
            border-bottom: 3px solid #2563eb;
            padding-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-section h4 i {
            color: #2563eb;
        }

        .form-row {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            flex: 1;
        }

        .form-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 8px;
            display: block;
        }

        .required {
            color: #ef4444;
        }

        .form-control, .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus, .form-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        .employee-info {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            color: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(37, 99, 235, 0.15);
        }

        .employee-info h3 {
            margin-bottom: 12px;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .employee-info p {
            margin-bottom: 8px;
            font-size: 1.1rem;
        }

        .employee-info small {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
        }

        .alert-success {
            background: #dcfce7;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .alert-danger {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        .alert-info {
            background: #dbeafe;
            border: 1px solid #93c5fd;
            color: #1d4ed8;
        }

        .btn-close {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.6;
            transition: opacity 0.3s ease;
        }

        .btn-close:hover {
            opacity: 1;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 1rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #2563eb, #1e40af);
            color: white;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e3a8a);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 2px solid #e2e8f0;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
            color: #334155;
        }

        .btn-lg {
            padding: 16px 32px;
            font-size: 1.1rem;
        }

        .d-flex {
            display: flex;
        }

        .gap-3 {
            gap: 1rem;
        }

        /* Navigation Tabs */
        .settings-nav {
            display: flex;
            background: white;
            border-radius: 12px;
            padding: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow-x: auto;
        }

        .nav-tab {
            flex: 1;
            min-width: 160px;
            padding: 12px 20px;
            text-align: center;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            color: #6b7280;
            text-decoration: none;
            border: none;
            background: none;
        }

        .nav-tab:hover {
            background: #f3f4f6;
            color: #374151;
        }

        .nav-tab.active {
            background: linear-gradient(135deg, #2563eb, #1e40af);
            color: white;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .password-requirements {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            margin-top: 12px;
            font-size: 0.875rem;
            color: #64748b;
        }

        .password-requirements h6 {
            color: #475569;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .password-requirements ul {
            margin: 0;
            padding-left: 20px;
        }

        .password-requirements li {
            margin-bottom: 4px;
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 15px;
            }

            .d-flex {
                flex-direction: column;
                align-items: stretch;
            }

            .employee-info {
                padding: 20px;
            }

            .form-section {
                padding: 20px;
            }

            .settings-nav {
                flex-direction: column;
                gap: 8px;
            }

            .nav-tab {
                min-width: auto;
            }
        }
    </style>
</head>

<body>
    <?php
    require_once $root_path . '/includes/topbar.php';
    renderTopbar([
        'title' => 'Account Settings',
        'subtitle' => 'Security & Contact Information',
        'back_url' => 'employee_profile.php',
        'user_type' => 'employee'
    ]);
    ?>

    <section class="homepage">
        <div class="profile-wrapper">

            <!-- Success Message -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= $success_message ?>
                    <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
                    <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Validation Errors -->
            <?php if (!empty($validation_errors)): ?>
                <div class="alert alert-danger">
                    <strong><i class="fas fa-exclamation-triangle"></i> Please correct the following errors:</strong>
                    <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                        <?php foreach ($validation_errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="form-section">
                <div class="d-flex gap-3">
                    <a href="employee_profile.php" class="btn btn-secondary btn-lg">
                        <i class="fas fa-arrow-left"></i> Back to Profile
                    </a>
                    <a href="edit_profile.php" class="btn btn-secondary btn-lg">
                        <i class="fas fa-user-edit"></i> Edit Personal Info
                    </a>
                </div>
            </div>

            <!-- Employee Info Header -->
            <div class="employee-info">
                <h3><i class="fas fa-cog"></i> Account Settings</h3>
                <p>
                    <strong><?= htmlspecialchars($employee['employee_number']) ?></strong> -
                    <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>
                </p>
                <small>
                    Role: <?= htmlspecialchars($employee['role_name']) ?> |
                    Facility: <?= htmlspecialchars($employee['facility_name']) ?>
                </small>
            </div>

            <!-- Navigation Tabs -->
            <div class="settings-nav">
                <button class="nav-tab active" onclick="showTab('contact')">
                    <i class="fas fa-envelope"></i> Contact Information
                </button>
                <button class="nav-tab" onclick="showTab('password')">
                    <i class="fas fa-lock"></i> Change Password
                </button>
            </div>

            <!-- Tab Content: Contact Information -->
            <div id="contact" class="tab-content active">
                <form method="POST" novalidate>
                    <input type="hidden" name="action" value="update_contact">
                    
                    <div class="form-section">
                        <h4><i class="fas fa-envelope"></i> Contact Information</h4>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <div>
                                <strong>Note:</strong> These contact details are used for system notifications, 
                                emergency communications, and login purposes. Keep them up to date.
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="email">
                                Email Address <span class="required">*</span>
                            </label>
                            <input type="email" class="form-control" id="email" name="email"
                                value="<?= htmlspecialchars($employee['email']) ?>" required>
                            <small style="color: #6b7280; font-size: 0.875rem; margin-top: 4px; display: block;">
                                This email will be used for system notifications and login. Make sure it's accessible.
                            </small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="contact_num">
                                Contact Number <span class="required">*</span>
                            </label>
                            <input type="tel" class="form-control" id="contact_num" name="contact_num"
                                value="<?= htmlspecialchars($employee['contact_num'] ?? '') ?>"
                                placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" required>
                            <small style="color: #6b7280; font-size: 0.875rem; margin-top: 4px; display: block;">
                                Philippine mobile number format (11 digits starting with 09). Used for emergency contact and SMS notifications.
                            </small>
                        </div>

                        <div class="d-flex gap-3">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-save"></i> Update Contact Info
                            </button>
                            <button type="reset" class="btn btn-secondary btn-lg">
                                <i class="fas fa-undo"></i> Reset
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Tab Content: Change Password -->
            <div id="password" class="tab-content">
                <form method="POST" novalidate>
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-section">
                        <h4><i class="fas fa-lock"></i> Change Password</h4>

                        <div class="alert alert-info">
                            <i class="fas fa-shield-alt"></i>
                            <div>
                                <strong>Security Tip:</strong> Use a strong password that you don't use anywhere else. 
                                Consider using a password manager to generate and store secure passwords.
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="current_password">
                                Current Password <span class="required">*</span>
                            </label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                            <small style="color: #6b7280; font-size: 0.875rem; margin-top: 4px; display: block;">
                                Enter your current password to verify your identity.
                            </small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="new_password">
                                New Password <span class="required">*</span>
                            </label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="confirm_password">
                                Confirm New Password <span class="required">*</span>
                            </label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>

                        <div class="password-requirements">
                            <h6><i class="fas fa-key"></i> Password Requirements:</h6>
                            <ul>
                                <li>At least 8 characters long</li>
                                <li>Mix of uppercase and lowercase letters recommended</li>
                                <li>Include numbers and special characters for better security</li>
                                <li>Avoid using personal information or common words</li>
                                <li>Don't reuse passwords from other accounts</li>
                            </ul>
                        </div>

                        <div class="d-flex gap-3">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                            <button type="reset" class="btn btn-secondary btn-lg">
                                <i class="fas fa-undo"></i> Reset Form
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <script>
        // Tab Navigation Functionality
        function showTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all nav tabs
            const navTabs = document.querySelectorAll('.nav-tab');
            navTabs.forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            const selectedTab = document.getElementById(tabName);
            if (selectedTab) {
                selectedTab.classList.add('active');
            }
            
            // Mark the clicked nav tab as active
            event.target.classList.add('active');
            
            // Update URL hash for bookmarking
            history.replaceState(null, null, '#' + tabName);
        }

        // Initialize tab from URL hash or default to contact
        document.addEventListener('DOMContentLoaded', function() {
            const hash = window.location.hash.substring(1);
            const validTabs = ['contact', 'password'];
            const initialTab = validTabs.includes(hash) ? hash : 'contact';
            
            // Show the initial tab
            const tabContents = document.querySelectorAll('.tab-content');
            const navTabs = document.querySelectorAll('.nav-tab');
            
            tabContents.forEach(content => content.classList.remove('active'));
            navTabs.forEach(tab => tab.classList.remove('active'));
            
            const initialContent = document.getElementById(initialTab);
            const initialNavTab = document.querySelector(`[onclick="showTab('${initialTab}')"]`);
            
            if (initialContent) initialContent.classList.add('active');
            if (initialNavTab) initialNavTab.classList.add('active');
        });

        // Auto-format contact number
        document.getElementById('contact_num').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 11) value = value.substring(0, 11);
            if (value.length >= 2 && !value.startsWith('09')) {
                value = '09' + value.substring(2);
            }
            e.target.value = value;
        });

        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
                this.style.borderColor = '#ef4444';
            } else {
                this.setCustomValidity('');
                this.style.borderColor = '#22c55e';
            }
        });

        // Real-time password strength indicator
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const requirements = document.querySelector('.password-requirements ul');
            const items = requirements.querySelectorAll('li');
            
            // Reset styles
            items.forEach(item => {
                item.style.color = '#64748b';
                item.style.fontWeight = 'normal';
            });
            
            // Check length
            if (password.length >= 8) {
                items[0].style.color = '#22c55e';
                items[0].style.fontWeight = '600';
            }
            
            // Check mixed case
            if (/[a-z]/.test(password) && /[A-Z]/.test(password)) {
                items[1].style.color = '#22c55e';
                items[1].style.fontWeight = '600';
            }
            
            // Check numbers/special chars
            if (/\d/.test(password) && /[!@#$%^&*(),.?":{}|<>]/.test(password)) {
                items[2].style.color = '#22c55e';
                items[2].style.fontWeight = '600';
            }
        });

        // Auto-dismiss alerts after 8 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                if (!alert.classList.contains('alert-info')) { // Keep info alerts
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }
            });
        }, 8000);

        // Form validation feedback
        document.querySelectorAll('.form-control, .form-select').forEach(function(input) {
            input.addEventListener('blur', function() {
                if (this.hasAttribute('required') && !this.value.trim()) {
                    this.style.borderColor = '#ef4444';
                } else if (this.type === 'email' && this.value && !this.validity.valid) {
                    this.style.borderColor = '#ef4444';
                } else {
                    this.style.borderColor = '#22c55e';
                }
            });

            input.addEventListener('input', function() {
                if (this.style.borderColor === 'rgb(239, 68, 68)') { // red
                    this.style.borderColor = '#e5e7eb'; // reset to normal
                }
            });
        });

        // Smooth form submission with loading state
        document.querySelectorAll('form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                submitBtn.disabled = true;
                
                // Re-enable if form validation fails
                setTimeout(() => {
                    if (submitBtn.disabled) {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }
                }, 5000);
            });
        });

        // Enhanced keyboard navigation
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save current tab
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                const activeTab = document.querySelector('.tab-content.active');
                if (activeTab) {
                    const submitBtn = activeTab.querySelector('button[type="submit"]');
                    if (submitBtn) submitBtn.click();
                }
            }
            
            // Escape to cancel/go back
            if (e.key === 'Escape') {
                window.location.href = 'employee_profile.php';
            }

            // Tab navigation with Ctrl+1, Ctrl+2
            if (e.ctrlKey || e.metaKey) {
                if (e.key === '1') {
                    e.preventDefault();
                    document.querySelector(`[onclick="showTab('contact')"]`).click();
                } else if (e.key === '2') {
                    e.preventDefault();
                    document.querySelector(`[onclick="showTab('password')"]`).click();
                }
            }
        });

        // Show unsaved changes warning
        let formChanged = false;
        document.querySelectorAll('input, select, textarea').forEach(function(input) {
            input.addEventListener('change', function() {
                formChanged = true;
            });
        });

        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });

        // Clear the warning when form is submitted
        document.querySelectorAll('form').forEach(function(form) {
            form.addEventListener('submit', function() {
                formChanged = false;
            });
        });
    </script>
</body>

</html>