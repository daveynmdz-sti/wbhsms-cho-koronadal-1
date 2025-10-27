<?php
// pages/management/admin/user-management/add_employee.php
// Employee Registration System with auto-generated employee numbers
// Author: GitHub Copilot

// Include employee session configuration - Use absolute path resolution
$root_path = dirname(dirname(dirname(dirname(__DIR__))));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';
require_once $root_path . '/utils/EmployeeEmailService.php';

// Check if user is logged in and has admin permissions
if (!isset($_SESSION['employee_id']) || $_SESSION['role'] !== 'admin') {
    ob_end_clean();
    error_log('Redirecting to employee_login (absolute path) from ' . __FILE__ . ' URI=' . ($_SERVER['REQUEST_URI'] ?? ''));
    header('Location: /pages/management/auth/employee_login.php');
    exit();
}

// Set active page for sidebar highlighting
$activePage = 'user_management';

// Initialize variables
$success_message = '';
$error_message = '';
$validation_errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Comprehensive error handling with logging
    error_log("Add Employee Form Submission - User: " . ($_SESSION['employee_id'] ?? 'unknown'));

    try {
        // Database connection validation with better error checking
        if (!isset($conn)) {
            throw new Exception("Database connection failed. Please try again later.");
        }
        
        if ($conn === false || (is_object($conn) && $conn->connect_error)) {
            throw new Exception("Database connection failed. Please try again later.");
        }

        // Validate input data with sanitization
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contact_num = trim($_POST['contact_num'] ?? '');
        $role_id = intval($_POST['role_id'] ?? 0);
        $facility_id = intval($_POST['facility_id'] ?? 0);
        $license_number = trim($_POST['license_number'] ?? '');
        $birth_date = $_POST['birth_date'] ?? '';
        $gender = $_POST['gender'] ?? '';

        // Log form data for debugging (without sensitive info)
        error_log("Form data - Name: $first_name $last_name, Email: $email, Role ID: $role_id, Facility ID: $facility_id");

        // Enhanced validation with specific error messages
        if (empty($first_name)) $validation_errors[] = 'First name is required and cannot be empty';
        if (strlen($first_name) > 50) $validation_errors[] = 'First name cannot exceed 50 characters';

        if (empty($last_name)) $validation_errors[] = 'Last name is required and cannot be empty';
        if (strlen($last_name) > 50) $validation_errors[] = 'Last name cannot exceed 50 characters';

        if (!empty($middle_name) && strlen($middle_name) > 50) $validation_errors[] = 'Middle name cannot exceed 50 characters';
        if (!empty($license_number) && strlen($license_number) > 50) $validation_errors[] = 'License number cannot exceed 50 characters';

        if (empty($email)) {
            $validation_errors[] = 'Email address is required';
        } else {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validation_errors[] = 'Please enter a valid email address format';
            }
            if (strlen($email) > 100) {
                $validation_errors[] = 'Email address cannot exceed 100 characters';
            }
        }

        if (empty($contact_num)) {
            $validation_errors[] = 'Contact number is required';
        }

        if ($role_id <= 0) {
            $validation_errors[] = 'Please select a valid employee role';
        }

        if ($facility_id <= 0) {
            $validation_errors[] = 'Please select a valid department/facility';
        }

        if (empty($birth_date)) {
            $validation_errors[] = 'Birth date is required';
        }

        if (empty($gender)) {
            $validation_errors[] = 'Gender selection is required';
        } elseif (!in_array($gender, ['male', 'female', 'other'])) {
            $validation_errors[] = 'Please select a valid gender option';
        }

        // Validate contact number format (Philippine mobile number)
        if (!empty($contact_num)) {
            if (!preg_match('/^09\d{9}$/', $contact_num)) {
                $validation_errors[] = 'Contact number must be a valid Philippine mobile number (09XXXXXXXXX)';
            }
        }

        // Validate birth date
        if (!empty($birth_date)) {
            $birth_datetime = DateTime::createFromFormat('Y-m-d', $birth_date);
            if (!$birth_datetime) {
                $validation_errors[] = 'Invalid birth date format. Please use the date picker.';
            } else {
                $today = new DateTime();
                $age = $today->diff($birth_datetime)->y;
                if ($age < 18) {
                    $validation_errors[] = 'Employee must be at least 18 years old';
                } elseif ($age > 100) {
                    $validation_errors[] = 'Please enter a valid birth date (age cannot exceed 100 years)';
                }

                // Check if birth date is in the future
                if ($birth_datetime > $today) {
                    $validation_errors[] = 'Birth date cannot be in the future';
                }
            }
        }

        // Validate role and facility exist in database
        if ($role_id > 0) {
            $role_check = $conn->prepare("SELECT role_id FROM roles WHERE role_id = ?");
            if ($role_check) {
                $role_check->bind_param("i", $role_id);
                $role_check->execute();
                if ($role_check->get_result()->num_rows === 0) {
                    $validation_errors[] = 'Selected role is not valid. Please choose from the available options.';
                }
                $role_check->close();
            } else {
                error_log("Failed to prepare role validation query: " . $conn->error);
                $validation_errors[] = 'Unable to validate role selection. Please try again.';
            }
        }

        if ($facility_id > 0) {
            $facility_check = $conn->prepare("SELECT facility_id FROM facilities WHERE facility_id = ? AND status = 'active'");
            if ($facility_check) {
                $facility_check->bind_param("i", $facility_id);
                $facility_check->execute();
                if ($facility_check->get_result()->num_rows === 0) {
                    $validation_errors[] = 'Selected facility is not valid or inactive. Please choose from the available options.';
                }
                $facility_check->close();
            } else {
                error_log("Failed to prepare facility validation query: " . $conn->error);
                $validation_errors[] = 'Unable to validate facility selection. Please try again.';
            }
        }

        // Check for duplicate email (optional - based on your business rules)
        if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email_check = $conn->prepare("SELECT employee_id FROM employees WHERE email = ?");
            if ($email_check) {
                $email_check->bind_param("s", $email);
                $email_check->execute();
                if ($email_check->get_result()->num_rows > 0) {
                    $validation_errors[] = 'An employee with this email address already exists. Please use a different email.';
                }
                $email_check->close();
            } else {
                error_log("Failed to prepare email validation query: " . $conn->error);
                // Don't add to validation errors as this is not critical
            }
        }

        if (empty($validation_errors)) {
            // Begin transaction with error handling
            if (!$conn->autocommit(FALSE)) {
                throw new Exception("Failed to start database transaction");
            }

            try {
                // Generate unique employee number with collision handling
                $max_attempts = 5;
                $attempt = 0;
                $employee_number = '';

                do {
                    $attempt++;
                    $stmt = $conn->prepare("SELECT employee_number FROM employees ORDER BY employee_id DESC LIMIT 1");
                    if (!$stmt) {
                        throw new Exception("Database query preparation failed: " . $conn->error);
                    }

                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($row = $result->fetch_assoc()) {
                        // Extract number from last employee number (e.g., EMP00092 -> 92)
                        $last_num = intval(substr($row['employee_number'], 3));
                        $new_num = $last_num + 1;
                    } else {
                        $new_num = 1;
                    }

                    $employee_number = 'EMP' . str_pad($new_num, 5, '0', STR_PAD_LEFT);
                    $stmt->close();

                    // Check if employee number already exists (safety check)
                    $check_stmt = $conn->prepare("SELECT employee_id FROM employees WHERE employee_number = ?");
                    if (!$check_stmt) {
                        throw new Exception("Database query preparation failed: " . $conn->error);
                    }

                    $check_stmt->bind_param("s", $employee_number);
                    $check_stmt->execute();
                    $collision_check = $check_stmt->get_result();
                    $check_stmt->close();

                    if ($collision_check->num_rows === 0) {
                        break; // No collision, we can use this number
                    }

                    // Add random component to avoid collision
                    $new_num += rand(1, 100);
                } while ($attempt < $max_attempts);

                if ($attempt >= $max_attempts) {
                    throw new Exception("Unable to generate unique employee number after multiple attempts");
                }

                // Generate secure default password with validation
                try {
                    $random_part = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
                    $default_password = 'CHO' . date('Y') . '@' . $random_part;
                } catch (Exception $e) {
                    error_log("Random password generation failed: " . $e->getMessage());
                    // Fallback password generation
                    $default_password = 'CHO' . date('Y') . '@' . str_pad(mt_rand(1000, 9999), 4, '0', STR_PAD_LEFT);
                }

                $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
                if (!$hashed_password) {
                    throw new Exception("Password hashing failed");
                }

                // Insert new employee with comprehensive error handling
                $insert_stmt = $conn->prepare("
                    INSERT INTO employees (
                        employee_number, first_name, middle_name, last_name, email, 
                        contact_num, role_id, facility_id, password, status, 
                        license_number, birth_date, gender, must_change_password,
                        password_changed_at, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, 1, NOW(), NOW())
                ");

                if (!$insert_stmt) {
                    throw new Exception("Failed to prepare employee insert statement: " . $conn->error);
                }

                $bind_result = $insert_stmt->bind_param(
                    "sssssiiissss",
                    $employee_number,
                    $first_name,
                    $middle_name,
                    $last_name,
                    $email,
                    $contact_num,
                    $role_id,
                    $facility_id,
                    $hashed_password,
                    $license_number,
                    $birth_date,
                    $gender
                );

                if (!$bind_result) {
                    throw new Exception("Failed to bind parameters for employee insert: " . $insert_stmt->error);
                }

                if (!$insert_stmt->execute()) {
                    $sql_error = $insert_stmt->error;
                    $insert_stmt->close();

                    // Check for specific MySQL errors
                    if (strpos($sql_error, 'Duplicate entry') !== false) {
                        if (strpos($sql_error, 'email') !== false) {
                            throw new Exception("An employee with this email address already exists");
                        } elseif (strpos($sql_error, 'employee_number') !== false) {
                            throw new Exception("Employee number conflict. Please try again");
                        } else {
                            throw new Exception("Duplicate data detected. Please check your input");
                        }
                    } elseif (strpos($sql_error, 'foreign key constraint') !== false) {
                        throw new Exception("Invalid role or facility selection. Please refresh the page and try again");
                    } else {
                        error_log("Employee insert SQL error: " . $sql_error);
                        throw new Exception("Failed to create employee record. Please try again");
                    }
                }

                $new_employee_id = $conn->insert_id;
                if (!$new_employee_id) {
                    throw new Exception("Failed to retrieve new employee ID");
                }

                $insert_stmt->close();

                // Log the creation activity with error handling
                try {
                    $log_stmt = $conn->prepare("
                        INSERT INTO user_activity_logs (
                            admin_id, employee_id, action_type, description, 
                            new_values, ip_address, user_agent
                        ) VALUES (?, ?, 'create', ?, ?, ?, ?)
                    ");

                    if ($log_stmt) {
                        $description = "Created new employee: $first_name $last_name ($employee_number)";
                        $new_values = json_encode([
                            'employee_number' => $employee_number,
                            'name' => trim("$first_name $middle_name $last_name"),
                            'email' => $email,
                            'role_id' => $role_id,
                            'facility_id' => $facility_id,
                            'status' => 'active'
                        ]);

                        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

                        $log_stmt->bind_param(
                            "iissss",
                            $_SESSION['employee_id'],
                            $new_employee_id,
                            $description,
                            $new_values,
                            $ip_address,
                            $user_agent
                        );

                        if (!$log_stmt->execute()) {
                            error_log("Failed to log employee creation: " . $log_stmt->error);
                            // Don't throw exception for logging failure
                        }
                        $log_stmt->close();
                    } else {
                        error_log("Failed to prepare activity log statement: " . $conn->error);
                    }
                } catch (Exception $log_e) {
                    error_log("Activity logging error: " . $log_e->getMessage());
                    // Don't throw exception for logging failure
                }

                // Commit transaction
                if (!$conn->commit()) {
                    throw new Exception("Failed to commit database transaction");
                }

                // Reset autocommit
                $conn->autocommit(TRUE);

                // Send welcome email to new employee
                try {
                    // Get role and facility names for email
                    $role_name = 'Staff';
                    $facility_name = 'CHO Koronadal';
                    
                    // Fetch role name
                    $role_stmt = $conn->prepare("SELECT role_name FROM roles WHERE role_id = ?");
                    if ($role_stmt) {
                        $role_stmt->bind_param("i", $role_id);
                        $role_stmt->execute();
                        $role_result = $role_stmt->get_result();
                        if ($role_row = $role_result->fetch_assoc()) {
                            $role_name = $role_row['role_name'];
                        }
                        $role_stmt->close();
                    }
                    
                    // Fetch facility name
                    $facility_stmt = $conn->prepare("SELECT name FROM facilities WHERE facility_id = ?");
                    if ($facility_stmt) {
                        $facility_stmt->bind_param("i", $facility_id);
                        $facility_stmt->execute();
                        $facility_result = $facility_stmt->get_result();
                        if ($facility_row = $facility_result->fetch_assoc()) {
                            $facility_name = $facility_row['name'];
                        }
                        $facility_stmt->close();
                    }

                    // Prepare email data
                    $email_data = [
                        'email' => $email,
                        'first_name' => $first_name,
                        'middle_name' => $middle_name,
                        'last_name' => $last_name,
                        'employee_number' => $employee_number,
                        'default_password' => $default_password,
                        'role_name' => $role_name,
                        'facility_name' => $facility_name,
                        'contact_num' => $contact_num
                    ];

                    // Send welcome email
                    $email_result = EmployeeEmailService::sendWelcomeEmail($email_data);
                    
                    if ($email_result['success']) {
                        error_log("Welcome email sent successfully to: $email for employee: $employee_number");
                        $email_status_message = "<br><small class='text-success'><i class='fas fa-envelope'></i> Welcome email sent successfully to $email</small>";
                    } else {
                        error_log("Failed to send welcome email to: $email - " . $email_result['message']);
                        $email_status_message = "<br><small class='text-warning'><i class='fas fa-exclamation-triangle'></i> Employee created successfully, but welcome email could not be sent. Please contact the employee manually.</small>";
                    }
                } catch (Exception $email_e) {
                    error_log("Email sending exception: " . $email_e->getMessage());
                    $email_status_message = "<br><small class='text-warning'><i class='fas fa-exclamation-triangle'></i> Employee created successfully, but welcome email could not be sent.</small>";
                }

                $success_message = "Employee created successfully!<br><strong>Employee Number:</strong> $employee_number<br><strong>Default Password:</strong> <code>$default_password</code><br><small class='text-warning'><i class='fas fa-exclamation-triangle'></i> Please share this password securely with the employee. They will be required to change it on first login.</small>" . ($email_status_message ?? '');

                // Reset form data to prevent resubmission
                $_POST = [];

                // Log successful creation
                error_log("Successfully created employee: $employee_number ($first_name $last_name) by admin: " . $_SESSION['employee_id']);
            } catch (Exception $e) {
                // Rollback transaction on any error
                $conn->rollback();
                $conn->autocommit(TRUE);

                $error_message = $e->getMessage();
                error_log("Employee creation failed - User: " . ($_SESSION['employee_id'] ?? 'unknown') . " - Error: " . $error_message);

                // Provide user-friendly error messages
                if (strpos($error_message, 'Duplicate') !== false) {
                    $error_message = "This employee information conflicts with existing records. Please check the email address and try again.";
                } elseif (strpos($error_message, 'foreign key') !== false) {
                    $error_message = "Invalid role or facility selection. Please refresh the page and select valid options.";
                } elseif (strpos($error_message, 'connection') !== false) {
                    $error_message = "Database connection issue. Please try again in a few moments.";
                } elseif (strpos($error_message, 'timeout') !== false) {
                    $error_message = "The operation timed out. Please try again.";
                }
            }
        } else {
            // Log validation errors for debugging
            error_log("Employee creation validation failed - User: " . ($_SESSION['employee_id'] ?? 'unknown') . " - Errors: " . implode(', ', $validation_errors));
        }
    } catch (Exception $e) {
        // Top-level exception handling
        $error_message = "A system error occurred while processing your request. Please try again later.";
        error_log("Critical error in add_employee.php - User: " . ($_SESSION['employee_id'] ?? 'unknown') . " - Error: " . $e->getMessage() . " - Trace: " . $e->getTraceAsString());

        // Ensure transaction is rolled back
        if (isset($conn) && !$conn->autocommit(NULL)) {
            $conn->rollback();
            $conn->autocommit(TRUE);
        }
    }
}

// Fetch roles for dropdown with error handling
$roles = [];
try {
    if (isset($conn) && (!property_exists($conn, 'connect_error') || !$conn->connect_error)) {
        $roles_stmt = $conn->prepare("SELECT role_id, role_name, description FROM roles ORDER BY role_name");
        if ($roles_stmt) {
            if ($roles_stmt->execute()) {
                $roles_result = $roles_stmt->get_result();
                if ($roles_result) {
                    $roles = $roles_result->fetch_all(MYSQLI_ASSOC);
                }
            }
            $roles_stmt->close();
        } else {
            error_log("Failed to prepare roles query: " . ($conn->error ?? 'unknown error'));
            $error_message = $error_message ?: "Failed to load role options. Please refresh the page.";
        }
    } else {
        $error_message = $error_message ?: "Database connection issue. Please refresh the page.";
    }
} catch (Exception $e) {
    error_log("Error fetching roles: " . $e->getMessage());
    $error_message = $error_message ?: "Failed to load role options. Please try again.";
}

// Fetch facilities for dropdown with error handling
$facilities = [];
try {
    if (isset($conn) && (!property_exists($conn, 'connect_error') || !$conn->connect_error)) {
        $facilities_stmt = $conn->prepare("SELECT facility_id, name, type FROM facilities WHERE status = 'active' ORDER BY name");
        if ($facilities_stmt) {
            if ($facilities_stmt->execute()) {
                $facilities_result = $facilities_stmt->get_result();
                if ($facilities_result) {
                    $facilities = $facilities_result->fetch_all(MYSQLI_ASSOC);
                }
            }
            $facilities_stmt->close();
        } else {
            error_log("Failed to prepare facilities query: " . ($conn->error ?? 'unknown error'));
            $error_message = $error_message ?: "Failed to load facility options. Please refresh the page.";
        }
    } else {
        $error_message = $error_message ?: "Database connection issue. Please refresh the page.";
    }
} catch (Exception $e) {
    error_log("Error fetching facilities: " . $e->getMessage());
    $error_message = $error_message ?: "Failed to load facility options. Please try again.";
}

// Validate that we have necessary data to render the form
if (empty($roles) && empty($error_message)) {
    $error_message = "No employee roles are available. Please contact the system administrator.";
}

if (empty($facilities) && empty($error_message)) {
    $error_message = "No active facilities are available. Please contact the system administrator.";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Employee - User Management</title>

    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <!-- Custom CSS -->
    <link rel="stylesheet" href="../../../../assets/css/topbar.css">
    <link rel="stylesheet" href="../../../../assets/css/profile-edit.css">
    <link rel="stylesheet" href="../../../../assets/css/edit.css">

    <style>
        :root {
            --primary-color: #4f46e5;
            --primary-hover: #4338ca;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --border-radius: 12px;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        }

        body {
            background-color: var(--gray-50);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--gray-900);
            line-height: 1.6;
        }

        .homepage {
            background: transparent;
            min-height: 100vh;
        }

        .profile-wrapper {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .form-section {
            background: white;
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
            transition: all 0.2s ease;
        }

        .form-section:hover {
            box-shadow: var(--shadow-md);
        }

        .form-section h4 {
            color: var(--gray-900);
            margin-bottom: 1.5rem;
            font-weight: 600;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        .form-section h4 i {
            color: var(--primary-color);
            font-size: 1.1rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 500;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            letter-spacing: 0.025em;
        }

        .required {
            color: var(--danger-color);
            margin-left: 0.25rem;
        }

        .form-control,
        .form-select {
            background: white;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            color: var(--gray-900);
        }

        .form-control:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgb(79 70 229 / 0.1);
        }

        .form-control:hover,
        .form-select:hover {
            border-color: var(--gray-400);
        }

        .form-control::placeholder {
            color: var(--gray-400);
            font-size: 0.875rem;
        }

        .form-text {
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }

        .employee-info {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-hover) 100%);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 1.5rem;
            color: white;
            box-shadow: var(--shadow-md);
        }

        .employee-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 2rem;
        }

        .employee-details {
            flex: 1;
        }

        .employee-badge {
            flex-shrink: 0;
        }

        .employee-info h3 {
            margin: 0 0 0.5rem 0;
            font-weight: 600;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .employee-info p {
            margin: 0.5rem 0;
            opacity: 0.9;
        }

        .employee-info small {
            opacity: 0.75;
            font-size: 0.875rem;
        }

        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 500;
            letter-spacing: 0.05em;
            backdrop-filter: blur(10px);
        }

        .alert {
            border: none;
            border-radius: var(--border-radius);
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            box-shadow: var(--shadow-sm);
        }

        .alert-success {
            background: rgb(16 185 129 / 0.1);
            color: #065f46;
            border-left: 4px solid var(--success-color);
        }

        .alert-danger {
            background: rgb(239 68 68 / 0.1);
            color: #991b1b;
            border-left: 4px solid var(--danger-color);
        }

        .alert-warning {
            background: rgb(245 158 11 / 0.1);
            color: #92400e;
            border-left: 4px solid var(--warning-color);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.875rem;
            letter-spacing: 0.025em;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-lg {
            padding: 1rem 2rem;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .btn-primary:hover:not(:disabled) {
            background: var(--primary-hover);
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: var(--gray-600);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .btn-secondary:hover {
            background: var(--gray-700);
            box-shadow: var(--shadow-md);
            transform: translateY(-1px);
        }

        .btn-outline-secondary {
            background: transparent;
            color: var(--gray-600);
            border: 1px solid var(--gray-300);
        }

        .btn-outline-secondary:hover {
            background: var(--gray-50);
            border-color: var(--gray-400);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: inherit;
            opacity: 0.6;
            cursor: pointer;
            margin-left: auto;
            padding: 0;
            line-height: 1;
        }

        .btn-close:hover {
            opacity: 1;
        }

        .info-note {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 1px solid #0284c7;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-top: 1.5rem;
            color: #0c4a6e;
            position: relative;
            overflow: hidden;
        }

        .info-note::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: #0284c7;
        }

        .info-note i {
            color: #0284c7;
            margin-right: 0.5rem;
        }

        .d-flex {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
        }

        .gap-3 {
            gap: 1rem;
        }

        .text-md-end {
            text-align: right;
        }

        .mt-3 {
            margin-top: 1rem;
        }

        .mb-0 {
            margin-bottom: 0;
        }

        .text-muted {
            color: var(--gray-500) !important;
        }

        /* Loading spinner */
        .fa-spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .profile-wrapper {
                padding: 1rem;
            }

            .form-section {
                padding: 1.5rem;
            }

            .employee-info {
                padding: 1.5rem;
                text-align: center;
            }

            .employee-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }

            .employee-badge {
                align-self: center;
            }

            .form-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .d-flex {
                flex-direction: column;
            }

            .text-md-end {
                text-align: center;
                margin-top: 1rem;
            }

            .btn {
                justify-content: center;
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .form-section h4 {
                font-size: 1.1rem;
            }

            .employee-info h3 {
                font-size: 1.25rem;
            }
        }

        /* Enhanced focus states for accessibility */
        .form-control:focus-visible,
        .form-select:focus-visible,
        .btn:focus-visible {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }

        /* Smooth transitions */
        * {
            transition-duration: 0.2s;
            transition-timing-function: ease;
        }
    </style>
</head>

<body>
    <?php
    require_once $root_path . '/includes/topbar.php';
    renderTopbar([
        'title' => 'Add New Employee',
        'subtitle' => 'User Management System',
        'back_url' => 'employee_list.php',
        'user_type' => 'employee'
    ]);
    ?>

    <section class="homepage">

        <div class="profile-wrapper">

            <!-- Success Message -->
            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?= $success_message ?></div>
                    <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div><?= htmlspecialchars($error_message) ?></div>
                    <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
                </div>
            <?php endif; ?>

            <!-- Validation Errors -->
            <?php if (!empty($validation_errors)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <strong>Please correct the following errors:</strong>
                        <ul style="margin: 0.5rem 0 0 0; padding-left: 1.25rem;">
                            <?php foreach ($validation_errors as $error): ?>
                                <li style="margin-bottom: 0.25rem;"><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
                </div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <!-- Employee Info Header -->
                <div class="employee-info">
                    <div class="employee-header">
                        <div class="employee-details">
                            <h3><i class="fas fa-user-plus"></i> Create New Employee</h3>
                            <p class="mb-0">
                                Add a new employee to the healthcare management system
                            </p>
                            <small>
                                Employee number will be auto-generated • Default password will be provided
                            </small>
                        </div>
                        <div class="employee-badge">
                            <span class="badge">
                                <i class="fas fa-plus-circle"></i> New Employee
                            </span>
                        </div>
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
                                value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="middle_name">Middle Name</label>
                            <input type="text" class="form-control" id="middle_name" name="middle_name"
                                value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="last_name">
                                Last Name <span class="required">*</span>
                            </label>
                            <input type="text" class="form-control" id="last_name" name="last_name"
                                value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="birth_date">
                                Birth Date <span class="required">*</span>
                            </label>
                            <input type="date" class="form-control" id="birth_date" name="birth_date"
                                value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="gender">
                                Gender <span class="required">*</span>
                            </label>
                            <select class="form-select" id="gender" name="gender" required>
                                <option value="">Select Gender</option>
                                <option value="male" <?= (($_POST['gender'] ?? '') === 'male') ? 'selected' : '' ?>>Male</option>
                                <option value="female" <?= (($_POST['gender'] ?? '') === 'female') ? 'selected' : '' ?>>Female</option>
                                <option value="other" <?= (($_POST['gender'] ?? '') === 'other') ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="license_number">License Number</label>
                            <input type="text" class="form-control" id="license_number" name="license_number"
                                value="<?= htmlspecialchars($_POST['license_number'] ?? '') ?>"
                                placeholder="Professional license number (if applicable)">
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
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                            <small class="form-text text-muted">
                                <i class="fas fa-info-circle"></i> Employee will use this email for system login
                            </small>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="contact_num">
                                Contact Number <span class="required">*</span>
                            </label>
                            <input type="tel" class="form-control" id="contact_num" name="contact_num"
                                value="<?= htmlspecialchars($_POST['contact_num'] ?? '') ?>"
                                placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" required>
                            <small class="form-text text-muted">Philippine mobile number format (11 digits)</small>
                        </div>
                    </div>
                </div>

                <!-- Employment Information Section -->
                <div class="form-section">
                    <h4><i class="fas fa-briefcase"></i> Employment Information</h4>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="role_id">
                                Role <span class="required">*</span>
                            </label>
                            <select class="form-select" id="role_id" name="role_id" required
                                <?= empty($roles) ? 'disabled' : '' ?>>
                                <option value="">Select Role</option>
                                <?php if (!empty($roles)): ?>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= $role['role_id'] ?>"
                                            <?= (($_POST['role_id'] ?? '') == $role['role_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($role['role_name']) ?>
                                            - <?= htmlspecialchars($role['description']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">No roles available</option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="facility_id">
                                Department/Facility <span class="required">*</span>
                            </label>
                            <select class="form-select" id="facility_id" name="facility_id" required
                                <?= empty($facilities) ? 'disabled' : '' ?>>
                                <option value="">Select Facility</option>
                                <?php if (!empty($facilities)): ?>
                                    <?php foreach ($facilities as $facility): ?>
                                        <option value="<?= $facility['facility_id'] ?>"
                                            <?= (($_POST['facility_id'] ?? '') == $facility['facility_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($facility['name']) ?>
                                            (<?= htmlspecialchars($facility['type']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">No facilities available</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <div class="info-note">
                        <i class="fas fa-shield-alt"></i>
                        <div>
                            <strong>Security Information:</strong> A secure default password will be automatically generated.
                            The employee will be required to change it on first login for security purposes.
                            <br><br>
                            <strong>Employee Number:</strong> Will be auto-generated in format: EMP#####
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="form-section">
                    <div class="action-buttons">
                        <div class="d-flex gap-3">
                            <a href="employee_list.php" class="btn btn-secondary btn-lg">
                                <i class="fas fa-arrow-left"></i> Back to List
                            </a>
                            <button type="submit" class="btn btn-primary btn-lg"
                                <?= (!empty($error_message) || empty($roles) || empty($facilities)) ? 'disabled' : '' ?>>
                                <i class="fas fa-user-plus"></i> Create Employee
                            </button>
                            <button type="reset" class="btn btn-outline-secondary btn-lg">
                                <i class="fas fa-undo"></i> Reset Form
                            </button>
                        </div>

                        <?php if (!empty($error_message) || empty($roles) || empty($facilities)): ?>
                            <div class="alert alert-warning mt-3">
                                <i class="fas fa-exclamation-triangle"></i>
                                <div>
                                    <strong>Form Disabled:</strong>
                                    <?php if (empty($roles)): ?>
                                        No employee roles available.
                                    <?php elseif (empty($facilities)): ?>
                                        No active facilities available.
                                    <?php else: ?>
                                        System error detected.
                                    <?php endif; ?>
                                    Please refresh the page or contact support if the issue persists.
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </section>

        <script>
            // Enhanced contact number formatting with validation
            document.getElementById('contact_num').addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');

                // Limit to 11 digits
                if (value.length > 11) {
                    value = value.substring(0, 11);
                }

                // Ensure it starts with 09
                if (value.length >= 2 && !value.startsWith('09')) {
                    value = '09' + value.substring(2);
                }

                e.target.value = value;

                // Visual feedback for invalid format
                const isValid = /^09\d{9}$/.test(value) || value.length === 0;
                e.target.style.borderColor = isValid ? '' : '#e74c3c';
            });

            // Form validation before submission
            document.querySelector('form').addEventListener('submit', function(e) {
                const submitBtn = document.querySelector('button[type="submit"]');

                // Prevent double submission
                if (submitBtn.disabled) {
                    e.preventDefault();
                    return false;
                }

                // Basic client-side validation
                const requiredFields = document.querySelectorAll('[required]');
                let hasErrors = false;

                requiredFields.forEach(function(field) {
                    if (!field.value.trim()) {
                        field.style.borderColor = '#e74c3c';
                        hasErrors = true;
                    } else {
                        field.style.borderColor = '';
                    }
                });

                // Validate contact number format
                const contactNum = document.getElementById('contact_num').value;
                if (contactNum && !/^09\d{9}$/.test(contactNum)) {
                    document.getElementById('contact_num').style.borderColor = '#e74c3c';
                    hasErrors = true;
                }

                // Validate email format
                const email = document.getElementById('email').value;
                if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    document.getElementById('email').style.borderColor = '#e74c3c';
                    hasErrors = true;
                }

                if (hasErrors) {
                    e.preventDefault();

                    // Show validation error message
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-danger';
                    alertDiv.innerHTML = `
                        <i class="fas fa-exclamation-triangle"></i> 
                        Please fill in all required fields correctly.
                        <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
                    `;
                    document.querySelector('.profile-wrapper').insertBefore(
                        alertDiv,
                        document.querySelector('.profile-wrapper').firstChild
                    );

                    // Auto dismiss
                    setTimeout(() => {
                        alertDiv.style.opacity = '0';
                        setTimeout(() => alertDiv.remove(), 300);
                    }, 5000);

                    return false;
                }

                // Disable submit button to prevent double submission
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Employee...';

                // Re-enable button after 30 seconds as failsafe
                setTimeout(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-user-plus"></i> Create Employee';
                }, 30000);
            });

            // Auto-dismiss alerts after 8 seconds
            setTimeout(function() {
                document.querySelectorAll('.alert').forEach(function(alert) {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                });
            }, 8000);

            // Enhanced form reset functionality
            document.querySelector('button[type="reset"]').addEventListener('click', function(e) {
                e.preventDefault();

                // Clear all form fields and styles
                document.querySelectorAll('input, select').forEach(function(field) {
                    if (field.type === 'text' || field.type === 'email' || field.type === 'tel' || field.type === 'date') {
                        field.value = '';
                    } else if (field.tagName === 'SELECT') {
                        field.selectedIndex = 0;
                    }
                    field.style.borderColor = '';
                });

                // Remove any validation alerts
                document.querySelectorAll('.alert').forEach(function(alert) {
                    alert.remove();
                });

                // Focus on first field
                document.getElementById('first_name').focus();
            });

            // Real-time field validation
            document.querySelectorAll('input[required], select[required]').forEach(function(field) {
                field.addEventListener('blur', function() {
                    if (this.value.trim()) {
                        this.style.borderColor = '#28a745';
                    } else {
                        this.style.borderColor = '#e74c3c';
                    }
                });

                field.addEventListener('input', function() {
                    if (this.style.borderColor === 'rgb(231, 76, 60)' && this.value.trim()) {
                        this.style.borderColor = '';
                    }
                });
            });
        </script>
</body>

</html>