<?php
// pages/user/user_settings.php
// Universal Employee Settings Page - Password change, email, contact updates
// Author: GitHub Copilot

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Include activity logger for user settings tracking
require_once $root_path . '/utils/UserActivityLogger.php';

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
                    $confirm_contact_password = $_POST['confirm_contact_password'] ?? '';

                    // Validation
                    if (empty($email)) $validation_errors[] = 'Email is required';
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $validation_errors[] = 'Invalid email format';
                    if (empty($contact_num)) $validation_errors[] = 'Contact number is required';
                    if (empty($confirm_contact_password)) $validation_errors[] = 'Password confirmation is required';

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

                    // Verify current password before making changes
                    if (empty($validation_errors)) {
                        $stmt = $conn->prepare("SELECT password FROM employees WHERE employee_id = ?");
                        $stmt->bind_param("i", $employee_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $user = $result->fetch_assoc();

                        if (!$user || !password_verify($confirm_contact_password, $user['password'])) {
                            $validation_errors[] = 'Current password is incorrect';
                        }
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

                case 'update_profile_photo':
                    // Handle profile photo upload
                    if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
                        $validation_errors[] = 'Please select a valid image file';
                        break;
                    }

                    $file = $_FILES['profile_photo'];
                    $maxSize = 10 * 1024 * 1024; // 10 MB

                    // Validate file size
                    if ($file['size'] > $maxSize) {
                        $validation_errors[] = 'File is too large. Maximum size allowed is 10 MB';
                        break;
                    }

                    // Validate file type
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                    if (!in_array($ext, $allowed)) {
                        $validation_errors[] = 'Invalid file type. Only JPG, JPEG, PNG, and GIF files are allowed';
                        break;
                    }

                    // Validate image content
                    $imageInfo = getimagesize($file['tmp_name']);
                    if ($imageInfo === false) {
                        $validation_errors[] = 'Invalid image file';
                        break;
                    }

                    if (empty($validation_errors)) {
                        try {
                            $imgData = file_get_contents($file['tmp_name']);

                            // Update employee profile photo
                            $update_stmt = $conn->prepare("
                                UPDATE employees SET profile_photo = ?, updated_at = NOW()
                                WHERE employee_id = ?
                            ");
                            $update_stmt->bind_param("si", $imgData, $employee_id);

                            if ($update_stmt->execute()) {
                                // Log the update activity
                                $log_stmt = $conn->prepare("
                                    INSERT INTO user_activity_logs (
                                        admin_id, employee_id, action_type, description
                                    ) VALUES (?, ?, 'update', ?)
                                ");

                                $description = "Self-updated profile photo";

                                $log_stmt->bind_param(
                                    "iis",
                                    $employee_id,
                                    $employee_id,
                                    $description
                                );
                                $log_stmt->execute();

                                $success_message = "Profile photo updated successfully!";
                            } else {
                                $error_message = "Failed to update profile photo";
                            }
                        } catch (Exception $e) {
                            $error_message = "Database error: " . $e->getMessage();
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

                    // Check for at least one uppercase letter
                    if (!preg_match('/[A-Z]/', $new_password)) {
                        $validation_errors[] = 'Password must contain at least one uppercase letter';
                    }

                    // Check for at least one lowercase letter
                    if (!preg_match('/[a-z]/', $new_password)) {
                        $validation_errors[] = 'Password must contain at least one lowercase letter';
                    }

                    // Check for alphanumeric only (no special characters)
                    if (!preg_match('/^[a-zA-Z0-9]+$/', $new_password)) {
                        $validation_errors[] = 'Password must contain only letters and numbers (no special characters)';
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
                                    // Log password change using new activity logger
                                    $role = $_SESSION['role'] ?? 'employee';
                                    $user_type = ($role === 'admin') ? 'admin' : 'employee';
                                    
                                    // Legacy logging for backward compatibility
                                    $log_stmt = $conn->prepare("
                                        INSERT INTO user_activity_logs (
                                            admin_id, employee_id, action_type, description
                                        ) VALUES (?, ?, 'update', ?)
                                    ");
                                    $description = "Self-changed password";
                                    $log_stmt->bind_param("iis", $employee_id, $employee_id, $description);
                                    $log_stmt->execute();
                                    
                                    // New comprehensive logging
                                    activity_logger()->logPasswordChange($employee_id, $user_type);

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
$sidebar_file = match ($current_user_role) {
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

    <!-- Cropper.js CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../assets/css/topbar.css">
    <link rel="stylesheet" href="../../assets/css/profile-edit.css">
    <link rel="stylesheet" href="../../assets/css/edit.css">

    <style>
        .profile-wrapper {
            max-width: 900px;
        }

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

        .form-control,
        .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-control:focus,
        .form-select:focus {
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
            padding: 20px 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            position: relative;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            border: 1px solid transparent;
            animation: slideInDown 0.3s ease-out;
        }

        .alert-content {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            flex: 1;
        }

        .alert-icon {
            font-size: 1.25rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .alert-text {
            flex: 1;
            line-height: 1.5;
        }

        .alert-text strong {
            display: block;
            margin-bottom: 4px;
            font-weight: 600;
        }

        .error-list {
            margin: 8px 0 0 0;
            padding-left: 20px;
            list-style-type: disc;
        }

        .error-list li {
            margin-bottom: 4px;
            color: inherit;
        }

        .alert-success {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            border-color: #22c55e;
            color: #166534;
        }

        .alert-success .alert-icon {
            color: #22c55e;
        }

        .alert-danger {
            background: linear-gradient(135deg, #fef2f2 0%, #fecaca 100%);
            border-color: #ef4444;
            color: #dc2626;
        }

        .alert-danger .alert-icon {
            color: #ef4444;
        }

        .alert-info {
            background: linear-gradient(135deg, #dbeafe 0%, #93c5fd 100%);
            border-color: #3b82f6;
            color: #1d4ed8;
        }

        .alert-info .alert-icon {
            color: #3b82f6;
        }

        .btn-close {
            position: absolute;
            right: 16px;
            top: 16px;
            background: rgba(0, 0, 0, 0.1);
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0.6;
            transition: all 0.3s ease;
            font-size: 0.875rem;
        }

        .btn-close:hover {
            opacity: 1;
            background: rgba(0, 0, 0, 0.2);
            transform: scale(1.1);
        }

        .btn-close i {
            color: inherit;
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeOut {
            from {
                opacity: 1;
                transform: translateY(0);
            }

            to {
                opacity: 0;
                transform: translateY(-10px);
            }
        }

        .alert.fade-out {
            animation: fadeOut 0.3s ease-out forwards;
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

        .justify-content-between {
            justify-content: space-between;
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

        /* Photo Upload Styles */
        .photo-upload-container {
            display: flex;
            gap: 30px;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .photo-preview {
            flex-shrink: 0;
        }

        .profile-photo-img {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #e5e7eb;
            transition: border-color 0.3s ease;
        }

        .profile-photo-img:hover {
            border-color: #2563eb;
        }

        .photo-upload-controls {
            flex: 1;
        }

        .file-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px dashed #e5e7eb;
            border-radius: 8px;
            background: #f9fafb;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 15px;
        }

        .file-input:hover {
            border-color: #2563eb;
            background: #f0f9ff;
        }

        .photo-requirements {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            font-size: 0.875rem;
            color: #64748b;
        }

        .photo-requirements h6,
        .photo-requirements strong {
            color: #475569;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .photo-requirements ul {
            margin: 8px 0 0 0;
            padding-left: 20px;
        }

        .photo-requirements li {
            margin-bottom: 4px;
        }

        /* Cropper Modal Styles */
        .cropper-modal-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            backdrop-filter: blur(5px);
        }

        .cropper-modal-content {
            background: white;
            border-radius: 12px;
            max-width: 90vw;
            max-height: 90vh;
            width: 800px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .cropper-modal-header {
            padding: 20px 30px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .cropper-modal-header h3 {
            margin: 0;
            color: #1f2937;
            font-size: 1.25rem;
            font-weight: 600;
        }

        .cropper-modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #6b7280;
            cursor: pointer;
            padding: 5px;
            border-radius: 4px;
            transition: all 0.3s ease;
        }

        .cropper-modal-close:hover {
            background: #f3f4f6;
            color: #374151;
        }

        .cropper-container {
            padding: 20px;
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 400px;
            max-height: 60vh;
            overflow: hidden;
        }

        .cropper-container img {
            max-width: 100%;
            max-height: 100%;
            display: block;
        }

        .cropper-modal-actions {
            padding: 20px 30px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        .cropper-modal-actions .btn {
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .cropper-modal-actions .btn:not(.btn-cancel) {
            background: linear-gradient(135deg, #2563eb, #1e40af);
            color: white;
        }

        .cropper-modal-actions .btn:not(.btn-cancel):hover {
            background: linear-gradient(135deg, #1d4ed8, #1e3a8a);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
        }

        .cropper-modal-actions .btn-cancel {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .cropper-modal-actions .btn-cancel:hover {
            background: #e2e8f0;
            color: #334155;
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

            .photo-upload-container {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }

            .cropper-modal-content {
                width: 95vw;
                height: 95vh;
            }

            .cropper-modal-header {
                padding: 15px 20px;
            }

            .cropper-container {
                padding: 15px;
                min-height: 300px;
            }

            .cropper-modal-actions {
                padding: 15px 20px;
                flex-direction: column;
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
                <button class="nav-tab" onclick="showTab('photo')">
                    <i class="fas fa-camera"></i> Profile Photo
                </button>
                <button class="nav-tab active" onclick="showTab('contact')">
                    <i class="fas fa-envelope"></i> Contact Information
                </button>
                <button class="nav-tab" onclick="showTab('password')">
                    <i class="fas fa-lock"></i> Change Password
                </button>
            </div>


            <!-- Success Message -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success" id="successAlert">
                    <div class="alert-content">
                        <i class="fas fa-check-circle alert-icon"></i>
                        <div class="alert-text">
                            <strong>Success!</strong> <?= htmlspecialchars($success_message) ?>
                        </div>
                    </div>
                    <button type="button" class="btn-close" onclick="dismissAlert('successAlert')" title="Close message">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger" id="errorAlert">
                    <div class="alert-content">
                        <i class="fas fa-exclamation-triangle alert-icon"></i>
                        <div class="alert-text">
                            <strong>Error!</strong> <?= htmlspecialchars($error_message) ?>
                        </div>
                    </div>
                    <button type="button" class="btn-close" onclick="dismissAlert('errorAlert')" title="Close message">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

            <!-- Validation Errors -->
            <?php if (!empty($validation_errors)): ?>
                <div class="alert alert-danger" id="validationAlert">
                    <div class="alert-content">
                        <i class="fas fa-exclamation-triangle alert-icon"></i>
                        <div class="alert-text">
                            <strong>Please correct the following errors:</strong>
                            <ul class="error-list">
                                <?php foreach ($validation_errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <button type="button" class="btn-close" onclick="dismissAlert('validationAlert')" title="Close message">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            <?php endif; ?>

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

                        <div class="form-group">
                            <label class="form-label" for="confirm_contact_password">
                                Current Password <span class="required">*</span>
                            </label>
                            <input type="password" class="form-control" id="confirm_contact_password" name="confirm_contact_password" required>
                            <small style="color: #6b7280; font-size: 0.875rem; margin-top: 4px; display: block;">
                                Enter your current password to confirm these changes to your contact information.
                            </small>
                        </div>

                        <div class="d-flex justify-content-between" style="margin-top: 30px;">
                            <a href="employee_profile.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Profile
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Contact Info
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
                            <input type="password" class="form-control" id="new_password" name="new_password"
                                pattern="^(?=.*[a-z])(?=.*[A-Z])[a-zA-Z0-9]{8,}$"
                                title="Must be at least 8 characters with 1 uppercase, 1 lowercase, alphanumeric only" required>
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
                                <li id="length-req">At least 8 characters long</li>
                                <li id="uppercase-req">At least one uppercase letter (A-Z)</li>
                                <li id="lowercase-req">At least one lowercase letter (a-z)</li>
                                <li id="alphanumeric-req">Letters and numbers only (no special characters)</li>
                            </ul>
                        </div>

                        <div class="d-flex justify-content-between" style="margin-top: 30px;">
                            <a href="employee_profile.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Profile
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Tab Content: Profile Photo -->
            <div id="photo" class="tab-content">
                <form method="POST" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="action" value="update_profile_photo">

                    <div class="form-section">
                        <h4><i class="fas fa-camera"></i> Profile Photo</h4>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <div>
                                <strong>Note:</strong> Your profile photo helps colleagues identify you in the system.
                                Please use a clear, professional photo that represents you well.
                            </div>
                        </div>

                        <div class="photo-upload-container">
                            <div class="photo-preview">
                                <img src="employee_photo.php?id=<?= urlencode($employee_id) ?>"
                                    alt="Profile Photo" id="profilePhotoPreview" class="profile-photo-img"
                                    onerror="this.onerror=null;this.src='https://ik.imagekit.io/wbhsmslogo/user.png?updatedAt=1750423429172';" />
                            </div>
                            <div class="photo-upload-controls">
                                <input type="file" name="profile_photo" id="profilePhotoInput" accept="image/*" class="file-input" />
                                <div class="photo-requirements">
                                    <strong>Requirements:</strong>
                                    <ul>
                                        <li>Professional 2x2-sized photo</li>
                                        <li>Under 10 MB only</li>
                                        <li>JPG, PNG, or GIF format</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between" style="margin-top: 30px;">
                            <a href="employee_profile.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Profile
                            </a>
                            <button type="submit" class="btn btn-primary" id="savePhotoBtn" disabled>
                                <i class="fas fa-save"></i> Save Photo
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <script>
        // Alert management functions
        function dismissAlert(alertId) {
            const alert = document.getElementById(alertId);
            if (alert) {
                alert.classList.add('fade-out');
                setTimeout(() => {
                    alert.remove();
                }, 300);
            }
        }

        function showNotification(message, type = 'success', duration = 5000) {
            // Remove existing notifications of the same type
            const existingAlerts = document.querySelectorAll(`.alert-${type}`);
            existingAlerts.forEach(alert => dismissAlert(alert.id));

            // Create new notification
            const alertId = `${type}Alert_${Date.now()}`;
            const iconClass = type === 'success' ? 'fa-check-circle' :
                type === 'danger' ? 'fa-exclamation-triangle' : 'fa-info-circle';
            const title = type === 'success' ? 'Success!' :
                type === 'danger' ? 'Error!' : 'Info';

            const alertHTML = `
                <div class="alert alert-${type}" id="${alertId}">
                    <div class="alert-content">
                        <i class="fas ${iconClass} alert-icon"></i>
                        <div class="alert-text">
                            <strong>${title}</strong> ${message}
                        </div>
                    </div>
                    <button type="button" class="btn-close" onclick="dismissAlert('${alertId}')" title="Close message">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;

            // Insert after navigation tabs
            const navTabs = document.querySelector('.settings-nav');
            if (navTabs) {
                navTabs.insertAdjacentHTML('afterend', alertHTML);
            }

            // Auto-dismiss after duration
            if (duration > 0) {
                setTimeout(() => dismissAlert(alertId), duration);
            }
        }

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
            const validTabs = ['contact', 'password', 'photo'];
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

            // Check length (at least 8 characters)
            if (password.length >= 8) {
                items[0].style.color = '#22c55e';
                items[0].style.fontWeight = '600';
            }

            // Check uppercase letter
            if (/[A-Z]/.test(password)) {
                items[1].style.color = '#22c55e';
                items[1].style.fontWeight = '600';
            }

            // Check lowercase letter
            if (/[a-z]/.test(password)) {
                items[2].style.color = '#22c55e';
                items[2].style.fontWeight = '600';
            }

            // Check alphanumeric only (no special characters)
            if (/^[a-zA-Z0-9]+$/.test(password) && password.length > 0) {
                items[3].style.color = '#22c55e';
                items[3].style.fontWeight = '600';
            } else if (password.length > 0 && !/^[a-zA-Z0-9]+$/.test(password)) {
                items[3].style.color = '#ef4444';
                items[3].style.fontWeight = '600';
            }
        });

        // Auto-dismiss alerts after 8 seconds with improved animation
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                // Don't auto-dismiss info alerts (they contain helpful information)
                if (!alert.classList.contains('alert-info')) {
                    dismissAlert(alert.id);
                }
            });
        }, 8000);

        // Initialize alert auto-dismissal on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Add IDs to existing alerts if they don't have them
            document.querySelectorAll('.alert').forEach(function(alert, index) {
                if (!alert.id) {
                    alert.id = `alert_${index}_${Date.now()}`;
                }
            });
        });

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

        // Smooth form submission with loading state and better feedback
        document.querySelectorAll('form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                const formAction = this.querySelector('input[name="action"]').value;

                // Show loading state
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
                submitBtn.disabled = true;

                // Clear previous alerts
                document.querySelectorAll('.alert:not(.alert-info)').forEach(alert => {
                    dismissAlert(alert.id);
                });

                // Show processing notification
                let processingMessage = formAction === 'change_password' ?
                    'Updating your password...' : 'Updating contact information...';

                showNotification(processingMessage, 'info', 0);

                // Re-enable if form validation fails (backup safety)
                setTimeout(() => {
                    if (submitBtn.disabled) {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                        // Remove processing notification
                        document.querySelectorAll('.alert-info').forEach(alert => {
                            if (alert.textContent.includes('Updating')) {
                                dismissAlert(alert.id);
                            }
                        });
                    }
                }, 10000); // 10 second timeout
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

            // Tab navigation with Ctrl+1, Ctrl+2, Ctrl+3
            if (e.ctrlKey || e.metaKey) {
                if (e.key === '1') {
                    e.preventDefault();
                    document.querySelector(`[onclick="showTab('contact')"]`).click();
                } else if (e.key === '2') {
                    e.preventDefault();
                    document.querySelector(`[onclick="showTab('password')"]`).click();
                } else if (e.key === '3') {
                    e.preventDefault();
                    document.querySelector(`[onclick="showTab('photo')"]`).click();
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

    <!-- Cropper.js Library -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>

    <!-- Photo Upload & Cropper Functionality -->
    <script>
        let cropper = null;
        let cropperModal = null;

        // User message display function
        function showUserMessage(message, type = 'info') {
            showNotification(message, type === 'error' ? 'danger' : type, 5000);
        }

        function showCropperModal(imageSrc) {
            console.log('Creating cropper modal');

            // Remove existing modal if any
            if (cropperModal) {
                console.log('Removing existing modal');
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
                cropperModal.remove();
                cropperModal = null;
            }

            cropperModal = document.createElement('div');
            cropperModal.className = 'cropper-modal-bg';
            cropperModal.innerHTML = `
                <div class="cropper-modal-content">
                    <div class="cropper-modal-header">
                        <h3>Crop Your Profile Photo</h3>
                        <button class="cropper-modal-close" type="button" onclick="document.getElementById('cancelCropBtn').click()">&times;</button>
                    </div>
                    <div class="cropper-container">
                        <img id="cropperImage" src="${imageSrc}" alt="Crop Image" />
                    </div>
                    <div class="cropper-modal-actions">
                        <button id="cropBtn" class="btn" type="button"><i class="fas fa-crop"></i> Crop Image</button>
                        <button id="cancelCropBtn" class="btn btn-cancel" type="button"><i class="fas fa-times"></i> Cancel</button>
                    </div>
                </div>
            `;
            document.body.appendChild(cropperModal);

            // Prevent body scrolling when modal is open
            document.body.style.overflow = 'hidden';

            // Add click outside to close
            cropperModal.addEventListener('click', function(e) {
                if (e.target === cropperModal) {
                    document.getElementById('cancelCropBtn').click();
                }
            });

            const image = document.getElementById('cropperImage');

            // Wait for image to load before initializing cropper
            image.addEventListener('load', function() {
                console.log('Image loaded, initializing cropper');

                if (typeof window.Cropper === 'undefined') {
                    showUserMessage('Cropper.js library not loaded. Please refresh the page and try again.', 'error');
                    cropperModal.remove();
                    cropperModal = null;
                    return;
                }

                try {
                    cropper = new window.Cropper(image, {
                        aspectRatio: 1,
                        viewMode: 2,
                        autoCropArea: 0.9,
                        movable: true,
                        zoomable: true,
                        scalable: true,
                        rotatable: true,
                        responsive: true,
                        background: true,
                        checkOrientation: false,
                        modal: true,
                        guides: true,
                        center: true,
                        highlight: true,
                        cropBoxMovable: true,
                        cropBoxResizable: true,
                        dragMode: 'crop',
                        minContainerWidth: 300,
                        minContainerHeight: 300
                    });
                    console.log('Cropper initialized successfully');
                } catch (error) {
                    console.error('Error initializing cropper:', error);
                    showUserMessage('Error initializing image cropper. Please try again.', 'error');
                    cropperModal.remove();
                    cropperModal = null;
                }
            });

            image.addEventListener('error', function() {
                console.error('Error loading image for cropper');
                showUserMessage('Error loading image. Please try a different image.', 'error');
                cropperModal.remove();
                cropperModal = null;
            });

            document.getElementById('cropBtn').onclick = function() {
                if (!cropper) {
                    showUserMessage('Cropper not initialized. Please try again.', 'error');
                    return;
                }

                console.log('Cropping image...');

                try {
                    const canvas = cropper.getCroppedCanvas({
                        width: 400,
                        height: 400,
                        imageSmoothingQuality: 'high',
                        fillColor: '#fff'
                    });

                    if (!canvas) {
                        throw new Error('Failed to create cropped canvas');
                    }

                    canvas.toBlob(function(blob) {
                        if (!blob) {
                            showUserMessage('Error processing cropped image. Please try again.', 'error');
                            return;
                        }

                        console.log('Cropped image created:', blob.size, 'bytes');

                        try {
                            // Set the blob as the file input for upload
                            const fileInput = document.getElementById('profilePhotoInput');
                            if (fileInput) {
                                const dataTransfer = new DataTransfer();
                                const croppedFile = new File([blob], 'cropped_profile.png', {
                                    type: 'image/png'
                                });
                                dataTransfer.items.add(croppedFile);
                                fileInput.files = dataTransfer.files;
                                console.log('File input updated with cropped image');
                            }

                            // Update preview
                            const previewImg = document.getElementById('profilePhotoPreview');
                            if (previewImg) {
                                const previewUrl = canvas.toDataURL('image/png');
                                previewImg.src = previewUrl;
                                console.log('Preview updated');
                            }

                            // Enable save button and show success message
                            const saveBtn = document.getElementById('savePhotoBtn');
                            if (saveBtn) {
                                saveBtn.disabled = false;
                                saveBtn.style.backgroundColor = '#28a745';
                                saveBtn.style.borderColor = '#28a745';
                                console.log('Save button enabled and highlighted');
                            }

                            // Remove modal and restore body scroll
                            cropper.destroy();
                            cropperModal.remove();
                            document.body.style.overflow = 'auto';
                            cropperModal = null;
                            cropper = null;

                            console.log('Cropping completed successfully');

                            // Show success message to user
                            showUserMessage('Image cropped successfully! Click Save to update your profile.', 'success');

                        } catch (error) {
                            console.error('Error updating UI after crop:', error);
                            showUserMessage('Error updating preview. Please try again.', 'error');
                        }

                    }, 'image/png', 0.9);

                } catch (error) {
                    console.error('Error during cropping:', error);
                    showUserMessage('Error cropping image. Please try again.', 'error');
                }
            };

            document.getElementById('cancelCropBtn').onclick = function() {
                console.log('Canceling crop operation');
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
                if (cropperModal) {
                    cropperModal.remove();
                    cropperModal = null;
                }
                document.body.style.overflow = 'auto';

                // Clear the file input
                const fileInput = document.getElementById('profilePhotoInput');
                if (fileInput) {
                    fileInput.value = '';
                }

                // Reset save button
                const saveBtn = document.getElementById('savePhotoBtn');
                if (saveBtn) {
                    saveBtn.disabled = true;
                    saveBtn.style.backgroundColor = '#6c757d';
                    saveBtn.style.borderColor = '#6c757d';
                }

                showUserMessage('Cropping canceled', 'info');
            };
        }

        // Photo upload functionality
        document.addEventListener('DOMContentLoaded', function() {
            const fileInput = document.getElementById('profilePhotoInput');
            if (!fileInput) {
                console.log('Profile photo input not found');
                return;
            }

            console.log('Profile photo cropper initialized');

            fileInput.addEventListener('change', function(event) {
                const file = event.target.files[0];
                if (!file) return;

                // Validate file type
                const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                if (!validTypes.includes(file.type)) {
                    showUserMessage('Please select a valid image file (JPEG, PNG, or GIF).', 'error');
                    event.target.value = '';
                    return;
                }

                // Validate file size
                if (file.size > 10 * 1024 * 1024) {
                    showUserMessage('File is too large. Maximum size allowed is 10 MB.', 'error');
                    event.target.value = '';
                    return;
                }

                console.log('File selected:', file.name, 'Size:', file.size, 'Type:', file.type);

                const reader = new FileReader();
                reader.onload = function(e) {
                    console.log('File loaded, showing cropper modal');
                    showCropperModal(e.target.result);
                };
                reader.onerror = function() {
                    showUserMessage('Error reading file. Please try again.', 'error');
                    event.target.value = '';
                };
                reader.readAsDataURL(file);
            });
        });
    </script>
</body>

</html>