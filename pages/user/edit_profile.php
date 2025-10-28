<?php
// pages/user/edit_profile.php
// Self-Service Employee Profile Edit - Employees can edit their own profiles
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
$activePage = 'profile';

// Get employee ID - only allow editing own profile
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate input data - Employees can only edit personal information
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contact_num = trim($_POST['contact_num'] ?? '');
        $license_number = trim($_POST['license_number'] ?? '');
        $birth_date = $_POST['birth_date'] ?? '';
        $gender = $_POST['gender'] ?? '';

        // Validation
        if (empty($first_name)) $validation_errors[] = 'First name is required';
        if (empty($last_name)) $validation_errors[] = 'Last name is required';
        if (empty($email)) $validation_errors[] = 'Email is required';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $validation_errors[] = 'Invalid email format';
        if (empty($contact_num)) $validation_errors[] = 'Contact number is required';
        if (empty($birth_date)) $validation_errors[] = 'Birth date is required';
        if (empty($gender)) $validation_errors[] = 'Gender is required';

        // Validate contact number format
        if (!preg_match('/^09\d{9}$/', $contact_num)) {
            $validation_errors[] = 'Contact number must be a valid Philippine mobile number (09XXXXXXXXX)';
        }

        // Validate birth date
        $birth_datetime = DateTime::createFromFormat('Y-m-d', $birth_date);
        if (!$birth_datetime) {
            $validation_errors[] = 'Invalid birth date format';
        } else {
            $today = new DateTime();
            $age = $today->diff($birth_datetime)->y;
            if ($age < 18 || $age > 100) {
                $validation_errors[] = 'Age must be between 18 and 100 years old';
            }
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
                    'first_name' => $employee['first_name'],
                    'middle_name' => $employee['middle_name'],
                    'last_name' => $employee['last_name'],
                    'email' => $employee['email'],
                    'contact_num' => $employee['contact_num'],
                    'license_number' => $employee['license_number'],
                    'birth_date' => $employee['birth_date'],
                    'gender' => $employee['gender']
                ];

                // Update employee - only personal information fields
                $update_stmt = $conn->prepare("
                    UPDATE employees SET 
                        first_name = ?, middle_name = ?, last_name = ?, email = ?, 
                        contact_num = ?, license_number = ?, birth_date = ?, gender = ?, 
                        updated_at = NOW()
                    WHERE employee_id = ?
                ");

                $update_stmt->bind_param(
                    "ssssssssi",
                    $first_name,
                    $middle_name,
                    $last_name,
                    $email,
                    $contact_num,
                    $license_number,
                    $birth_date,
                    $gender,
                    $employee_id
                );

                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update profile");
                }

                // Log the update activity
                $new_values = [
                    'first_name' => $first_name,
                    'middle_name' => $middle_name,
                    'last_name' => $last_name,
                    'email' => $email,
                    'contact_num' => $contact_num,
                    'license_number' => $license_number,
                    'birth_date' => $birth_date,
                    'gender' => $gender
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

                    $description = "Self-updated profile: $first_name $last_name (" . implode(', ', $changes) . ")";

                    // For self-updates, admin_id is the same as employee_id
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

                $success_message = "Your profile has been updated successfully!";

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

                // Update session data if name changed
                $_SESSION['employee_name'] = $first_name . ' ' . $last_name;
            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Failed to update profile: " . $e->getMessage();
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit My Profile - <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?></title>

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

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

        .employee-header {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .employee-photo {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid rgba(255, 255, 255, 0.3);
            flex-shrink: 0;
            position: relative;
            background: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .employee-photo .profile-photo-display {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .employee-photo .profile-photo-fallback {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: rgba(255, 255, 255, 0.7);
        }

        .employee-details {
            flex: 1;
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

        .readonly-info {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .readonly-info h5 {
            color: #475569;
            margin-bottom: 12px;
            font-weight: 600;
        }

        .readonly-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .readonly-item:last-child {
            border-bottom: none;
        }

        .readonly-label {
            font-weight: 500;
            color: #64748b;
        }

        .readonly-value {
            font-weight: 600;
            color: #1e293b;
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

            .employee-header {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }

            .employee-photo {
                width: 60px;
                height: 60px;
            }

            .employee-photo .profile-photo-fallback {
                font-size: 1.5rem;
            }

            .form-section {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
    <?php
    require_once $root_path . '/includes/topbar.php';
    renderTopbar([
        'title' => 'Edit My Profile',
        'subtitle' => 'Personal Information Management',
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

            <form method="POST" novalidate>
                <!-- Employee Info Header -->
                <div class="employee-info">
                    <div class="employee-header">
                        <div class="employee-photo">
                            <img src="employee_photo.php?id=<?= urlencode($employee['employee_id']) ?>" 
                                 alt="<?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?> Profile Photo" 
                                 class="profile-photo-display"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="profile-photo-fallback" style="display: none;">
                                <i class="fas fa-user"></i>
                            </div>
                        </div>
                        <div class="employee-details">
                            <h3><i class="fas fa-user-edit"></i> Edit My Profile</h3>
                            <p>
                                <strong><?= htmlspecialchars($employee['employee_number']) ?></strong> -
                                <?= htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']) ?>
                            </p>
                            <small>
                                Role: <?= htmlspecialchars($employee['role_name']) ?> |
                                Facility: <?= htmlspecialchars($employee['facility_name']) ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Information Notice -->
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <div>
                        <strong>Note:</strong> You can only edit your personal and contact information. 
                        Employment details (role, facility, status) can only be changed by system administrators.
                    </div>
                </div>

                <!-- Read-Only Employment Information -->
                <div class="readonly-info">
                    <h5><i class="fas fa-briefcase"></i> Employment Information (Read-Only)</h5>
                    <div class="readonly-item">
                        <span class="readonly-label">Employee Number:</span>
                        <span class="readonly-value"><?= htmlspecialchars($employee['employee_number']) ?></span>
                    </div>
                    <div class="readonly-item">
                        <span class="readonly-label">Role:</span>
                        <span class="readonly-value"><?= htmlspecialchars($employee['role_name']) ?></span>
                    </div>
                    <div class="readonly-item">
                        <span class="readonly-label">Department/Facility:</span>
                        <span class="readonly-value"><?= htmlspecialchars($employee['facility_name']) ?></span>
                    </div>
                    <div class="readonly-item">
                        <span class="readonly-label">Employment Status:</span>
                        <span class="readonly-value">
                            <span style="color: <?= $employee['status'] === 'active' ? '#22c55e' : '#f59e0b' ?>;">
                                <?= ucfirst(str_replace('_', ' ', $employee['status'])) ?>
                            </span>
                        </span>
                    </div>
                    <div class="readonly-item">
                        <span class="readonly-label">Date Hired:</span>
                        <span class="readonly-value"><?= date('F d, Y', strtotime($employee['created_at'])) ?></span>
                    </div>
                </div>

                <!-- Personal Information Section -->
                <div class="form-section">
                    <h4><i class="fas fa-user"></i> Personal Information</h4>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="first_name">
                                First Name <span class="required">*</span>
                            </label>
                            <input type="text" class="form-control" id="first_name" name="first_name"
                                value="<?= htmlspecialchars($employee['first_name']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="middle_name">Middle Name</label>
                            <input type="text" class="form-control" id="middle_name" name="middle_name"
                                value="<?= htmlspecialchars($employee['middle_name'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="last_name">
                                Last Name <span class="required">*</span>
                            </label>
                            <input type="text" class="form-control" id="last_name" name="last_name"
                                value="<?= htmlspecialchars($employee['last_name']) ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="birth_date">
                                Birth Date <span class="required">*</span>
                            </label>
                            <input type="date" class="form-control" id="birth_date" name="birth_date"
                                value="<?= htmlspecialchars($employee['birth_date']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="gender">
                                Gender <span class="required">*</span>
                            </label>
                            <select class="form-select" id="gender" name="gender" required>
                                <option value="">Select Gender</option>
                                <option value="male" <?= ($employee['gender'] === 'male') ? 'selected' : '' ?>>Male</option>
                                <option value="female" <?= ($employee['gender'] === 'female') ? 'selected' : '' ?>>Female</option>
                                <option value="other" <?= ($employee['gender'] === 'other') ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="license_number">
                                Professional License Number
                            </label>
                            <input type="text" class="form-control" id="license_number" name="license_number"
                                value="<?= htmlspecialchars($employee['license_number'] ?? '') ?>"
                                placeholder="Enter professional license number (if applicable)">
                        </div>
                    </div>
                </div>

                <!-- Contact Information Section -->
                <div class="form-section">
                    <h4><i class="fas fa-envelope"></i> Contact Information</h4>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="email">
                                Email Address <span class="required">*</span>
                            </label>
                            <input type="email" class="form-control" id="email" name="email"
                                value="<?= htmlspecialchars($employee['email']) ?>" required>
                            <small style="color: #6b7280; font-size: 0.875rem; margin-top: 4px; display: block;">
                                This email will be used for system notifications and login.
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
                                Philippine mobile number format (11 digits starting with 09).
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Submit Section -->
                <div class="form-section">
                    <div class="d-flex gap-3">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <a href="employee_profile.php" class="btn btn-secondary btn-lg">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </section>

    <script>
        // Auto-format contact number
        document.getElementById('contact_num').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 11) value = value.substring(0, 11);
            if (value.length >= 2 && !value.startsWith('09')) {
                value = '09' + value.substring(2);
            }
            e.target.value = value;
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
        document.querySelector('form').addEventListener('submit', function(e) {
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

        // Enhanced keyboard navigation
        document.addEventListener('keydown', function(e) {
            // Ctrl+S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                document.querySelector('button[type="submit"]').click();
            }
            
            // Escape to cancel
            if (e.key === 'Escape') {
                window.location.href = 'employee_profile.php';
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
        document.querySelector('form').addEventListener('submit', function() {
            formChanged = false;
        });
    </script>
</body>

</html>