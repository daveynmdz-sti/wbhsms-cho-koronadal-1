<?php
// create_referrals.php - Admin Side Referral Creation with Patient Search
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Include employee session configuration
// Use absolute path resolution
$root_path = dirname(dirname(__DIR__));
require_once $root_path . '/config/session/employee_session.php';
require_once $root_path . '/config/db.php';

// Include referral permissions utility
require_once $root_path . '/utils/referral_permissions.php';

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed. Please contact administrator.");
}

// If user is not logged in, bounce to login - use session management function
if (!is_employee_logged_in()) {
    redirect_to_employee_login();
}

// Check if role is authorized
$authorized_roles = ['doctor', 'nurse', 'bhw', 'dho', 'records_officer', 'admin'];
require_employee_role($authorized_roles);

$employee_id = $_SESSION['employee_id'];
// Include reusable topbar component
require_once $root_path . '/includes/topbar.php';

// Get employee's facility information
$employee_facility_id = null;
$employee_facility_name = '';
try {
    $stmt = $conn->prepare("
        SELECT e.facility_id, f.name as facility_name 
        FROM employees e 
        JOIN facilities f ON e.facility_id = f.facility_id 
        WHERE e.employee_id = ?
    ");
    $stmt->bind_param('i', $employee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $employee_facility_id = $row['facility_id'];
        $employee_facility_name = $row['facility_name'];
    }
} catch (Exception $e) {
    $error_message = "Unable to retrieve employee facility information.";
}

// Get jurisdiction restrictions for patient search (creation permissions)
// For creation, employees can only create referrals for patients in their jurisdiction
$jurisdiction_restriction = '';
$jurisdiction_params = [];
$jurisdiction_param_types = '';
$employee_role = strtolower($_SESSION['role']);

// Use permission utility to get creation restrictions (not hybrid viewing)
if ($employee_role === 'bhw') {
    $employee_barangay = getEmployeeBHWBarangay($conn, $employee_id);
    if ($employee_barangay) {
        $jurisdiction_restriction = " AND p.barangay_id = ?";
        $jurisdiction_params[] = $employee_barangay;
        $jurisdiction_param_types .= 'i';
        error_log("BHW create referrals: Employee ID $employee_id restricted to barangay $employee_barangay");
    } else {
        die('Error: Access denied - No barangay assignment found for BHW.');
    }
} elseif ($employee_role === 'dho') {
    $employee_district = getEmployeeDHODistrict($conn, $employee_id);
    if ($employee_district) {
        $jurisdiction_restriction = " AND b.district_id = ?";
        $jurisdiction_params[] = $employee_district;
        $jurisdiction_param_types .= 'i';
        error_log("DHO create referrals: Employee ID $employee_id restricted to district $employee_district");
    } else {
        die('Error: Access denied - No district assignment found for DHO.');
    }
}
// Admin, Doctor, Nurse, Records Officer can create for any patient

// Handle form submissions
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_vitals') {
        try {
            $conn->begin_transaction();

            // Get form data
            $patient_id = (int)($_POST['patient_id'] ?? 0);

            // Validate jurisdiction permission for this patient
            if (!canEmployeeCreateReferralForPatient($conn, $employee_id, $patient_id, $_SESSION['role'])) {
                throw new Exception("Access denied. You can only record vitals for patients within your jurisdiction.");
            }

            // Vitals data
            $systolic_bp = !empty($_POST['systolic_bp']) ? (int)$_POST['systolic_bp'] : null;
            $diastolic_bp = !empty($_POST['diastolic_bp']) ? (int)$_POST['diastolic_bp'] : null;
            $heart_rate = !empty($_POST['heart_rate']) ? (int)$_POST['heart_rate'] : null;
            $respiratory_rate = !empty($_POST['respiratory_rate']) ? (int)$_POST['respiratory_rate'] : null;
            $temperature = !empty($_POST['temperature']) ? (float)$_POST['temperature'] : null;
            $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
            $height = !empty($_POST['height']) ? (float)$_POST['height'] : null;
            $vitals_remarks = trim($_POST['vitals_remarks'] ?? '');

            // Calculate BMI if both weight and height are provided
            $bmi = null;
            if ($weight && $height) {
                $height_m = $height / 100; // Convert cm to meters
                $bmi = round($weight / ($height_m * $height_m), 2);
            }

            // Validation
            if (!$patient_id) {
                throw new Exception('Please select a patient first.');
            }

            // Check if at least one vital sign is provided
            if (!$systolic_bp && !$diastolic_bp && !$heart_rate && !$respiratory_rate && !$temperature && !$weight && !$height && empty($vitals_remarks)) {
                throw new Exception('Please provide at least one vital sign measurement or remark.');
            }

            // Insert vitals (always create new record for historical tracking)
            $stmt = $conn->prepare("
                INSERT INTO vitals (
                    patient_id, systolic_bp, diastolic_bp, heart_rate, 
                    respiratory_rate, temperature, weight, height, bmi, 
                    recorded_by, remarks, recorded_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param(
                'iiiiddddiis',
                $patient_id,
                $systolic_bp,
                $diastolic_bp,
                $heart_rate,
                $respiratory_rate,
                $temperature,
                $weight,
                $height,
                $bmi,
                $employee_id,
                $vitals_remarks
            );
            $stmt->execute();
            $vitals_id = $conn->insert_id;

            $conn->commit();
            $_SESSION['snackbar_message'] = "Patient vitals recorded successfully! Patient remains selected for your convenience.";
            $_SESSION['keep_patient_selected'] = $patient_id;

            // Redirect to same page to refresh and show new vitals
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    } elseif ($action === 'create_referral') {
        try {
            $conn->begin_transaction();

            // Get form data
            $patient_id = (int)($_POST['patient_id'] ?? 0);

            // Validate jurisdiction permission for this patient
            if (!canEmployeeCreateReferralForPatient($conn, $employee_id, $patient_id, $_SESSION['role'])) {
                throw new Exception("Access denied. You can only create referrals for patients within your jurisdiction.");
            }

            $referral_reason = trim($_POST['referral_reason'] ?? '');
            $destination_type = trim($_POST['destination_type'] ?? ''); // district_office, city_office, external
            $referred_to_facility_id = !empty($_POST['referred_to_facility_id']) ? (int)$_POST['referred_to_facility_id'] : null;
            $external_facility_type = trim($_POST['external_facility_type'] ?? '');
            $external_facility_name = trim($_POST['external_facility_name'] ?? '');
            $hospital_name = trim($_POST['hospital_name'] ?? '');
            $other_facility_name = trim($_POST['other_facility_name'] ?? '');
            $service_id = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
            $custom_service_name = trim($_POST['custom_service_name'] ?? '');

            // Process appointment fields based on destination type
            $assigned_doctor_id = null;
            $schedule_id = null;
            $appointment_date = null;
            $appointment_time = null;

            if ($destination_type === 'city_office') {
                // For City Health Office - require doctor assignment and time slot
                $assigned_doctor_id = !empty($_POST['assigned_doctor_id']) ? (int)$_POST['assigned_doctor_id'] : null;
                $schedule_slot_id = !empty($_POST['schedule_slot_id']) ? (int)$_POST['schedule_slot_id'] : null;
                $appointment_date = !empty($_POST['appointment_date']) ? $_POST['appointment_date'] : null;

                // Debug: Log the submitted appointment_date value
                error_log("Submitted appointment_date value: " . var_export($appointment_date, true));

                if (!$assigned_doctor_id) {
                    throw new Exception('Please select a doctor for City Health Office referrals.');
                }
                if (!$appointment_date) {
                    throw new Exception('Please select an appointment date.');
                }
                if (!$schedule_slot_id) {
                    throw new Exception('Please select an appointment time slot.');
                }

                // Validate appointment date is not in the past
                if (!empty($appointment_date) && strtotime($appointment_date) < strtotime(date('Y-m-d'))) {
                    throw new Exception('Appointment date cannot be in the past.');
                }

                // Get the appointment time from the selected slot and find the schedule_id
                $stmt = $conn->prepare("
                    SELECT dss.slot_time, ds.doctor_id, ds.schedule_id
                    FROM doctor_schedule_slots dss
                    INNER JOIN doctor_schedule ds ON dss.schedule_id = ds.schedule_id
                    WHERE dss.slot_id = ? AND ds.doctor_id = ? AND ds.is_active = 1 AND dss.is_booked = 0
                ");
                $stmt->bind_param("ii", $schedule_slot_id, $assigned_doctor_id);
                $stmt->execute();
                $slot_result = $stmt->get_result();

                if (!$slot_result->num_rows) {
                    throw new Exception('Selected time slot is no longer available.');
                }

                $slot_data = $slot_result->fetch_assoc();
                $appointment_time = $slot_data['slot_time'];
                $schedule_id = $slot_data['schedule_id'];

                // Check if slot is already booked by checking if referral_id is set
                $stmt = $conn->prepare("
                    SELECT referral_id 
                    FROM doctor_schedule_slots 
                    WHERE slot_id = ? AND referral_id IS NOT NULL
                ");
                $stmt->bind_param("i", $schedule_slot_id);
                $stmt->execute();
                $booking_result = $stmt->get_result();

                if ($booking_result->num_rows > 0) {
                    throw new Exception('Selected time slot is already booked. Please choose another time.');
                }
            } else {
                // For other destinations - use simple date/time scheduling
                $scheduled_date = !empty($_POST['simple_scheduled_date']) ? $_POST['simple_scheduled_date'] : null;
                $scheduled_time = !empty($_POST['simple_scheduled_time']) ? $_POST['simple_scheduled_time'] : null;

                // Debug: Log the submitted scheduled_date value
                error_log("Submitted simple_scheduled_date value: " . var_export($scheduled_date, true));
                error_log("Submitted simple_scheduled_time value: " . var_export($scheduled_time, true));

                // Only require scheduling for district_office, not for external facilities
                if ($destination_type === 'district_office') {
                    if (!$scheduled_date) {
                        throw new Exception('Please select a scheduled date for district office referrals.');
                    }
                    if (!$scheduled_time) {
                        throw new Exception('Please select a scheduled time for district office referrals.');
                    }

                    // Validate scheduled date is not in the past
                    if (!empty($scheduled_date) && strtotime($scheduled_date) < strtotime(date('Y-m-d'))) {
                        throw new Exception('Scheduled date cannot be in the past.');
                    }
                }

                // Use the simple scheduling fields (can be null for external)
                $appointment_date = $scheduled_date;
                $appointment_time = $scheduled_time;
            }

            // Determine final external facility name based on type
            if ($destination_type === 'external') {
                if ($external_facility_type === 'hospital' && !empty($hospital_name)) {
                    $external_facility_name = $hospital_name;
                } elseif ($external_facility_type === 'other' && !empty($other_facility_name)) {
                    $external_facility_name = $other_facility_name;
                }
            }

            // Validation
            if (!$patient_id) {
                throw new Exception('Please select a patient from the list above.');
            }
            if (empty($referral_reason)) {
                throw new Exception('Referral reason is required.');
            }
            if (empty($destination_type)) {
                throw new Exception('Please select a referral destination type.');
            }

            // Validate based on destination type
            if (in_array($destination_type, ['district_office', 'city_office']) && !$referred_to_facility_id) {
                throw new Exception('Destination facility could not be determined. Please contact administrator.');
            }

            if ($destination_type === 'external') {
                if (empty($external_facility_type)) {
                    throw new Exception('Please select external facility type.');
                }
                if ($external_facility_type === 'hospital' && empty($hospital_name)) {
                    throw new Exception('Please select a hospital.');
                }
                if ($external_facility_type === 'other' && empty($other_facility_name)) {
                    throw new Exception('Please specify the other facility name.');
                }
                if ($external_facility_type === 'other' && strlen($other_facility_name) < 3) {
                    throw new Exception('Other facility name must be at least 3 characters.');
                }
            }

            if (!$employee_facility_id) {
                throw new Exception('Employee facility information not found. Please contact administrator.');
            }

            // Validate custom service if "others" is selected
            if (isset($_POST['service_id']) && $_POST['service_id'] === 'others') {
                if (empty($custom_service_name)) {
                    throw new Exception('Please specify the other service name.');
                }
                if (strlen($custom_service_name) < 3) {
                    throw new Exception('Other service name must be at least 3 characters.');
                }
            }

            // Get latest vitals for this patient
            $vitals_id = null;
            $stmt = $conn->prepare("
                SELECT vitals_id 
                FROM vitals 
                WHERE patient_id = ? 
                ORDER BY recorded_at DESC, vitals_id DESC 
                LIMIT 1
            ");
            $stmt->bind_param('i', $patient_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $vitals_id = $row['vitals_id'];
            }

            // Generate referral number
            $date_prefix = date('Ymd');
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM referrals WHERE DATE(referral_date) = CURDATE()");
            $stmt->execute();
            $result = $stmt->get_result();
            $count = $result->fetch_assoc()['count'];
            $referral_num = 'REF-' . $date_prefix . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);

            // Handle custom service - if "others" is selected, store custom service name in referral_reason or notes
            $final_service_id = $service_id;
            $final_referral_reason = $referral_reason;

            // If service_id is "others", append custom service to referral reason
            if (isset($_POST['service_id']) && $_POST['service_id'] === 'others' && !empty($custom_service_name)) {
                $final_service_id = null; // Set to null for "others"
                $final_referral_reason .= "\n\nRequested Service: " . $custom_service_name;
            }

            // Determine referral status based on destination
            $referral_status = 'active'; // Default status for internal referrals
            if ($referred_to_facility_id === null && !empty($external_facility_name)) {
                $referral_status = 'issued'; // Status for external referrals
            }

            // Ensure proper date format for MySQL
            $mysql_appointment_date = null;
            if (!empty($appointment_date) && $appointment_date !== '0000-00-00') {
                // Clean up the date input - remove any extra characters
                $appointment_date = trim($appointment_date);
                
                // Validate and format the date
                $date_obj = DateTime::createFromFormat('Y-m-d', $appointment_date);
                if ($date_obj && $date_obj->format('Y-m-d') === $appointment_date) {
                    $mysql_appointment_date = $appointment_date;
                    error_log("Valid appointment date: $mysql_appointment_date");
                } else {
                    // Try to parse other possible formats
                    $alt_formats = ['m/d/Y', 'd/m/Y', 'Y/m/d'];
                    foreach ($alt_formats as $format) {
                        $date_obj = DateTime::createFromFormat($format, $appointment_date);
                        if ($date_obj) {
                            $mysql_appointment_date = $date_obj->format('Y-m-d');
                            error_log("Converted appointment date from $appointment_date to $mysql_appointment_date");
                            break;
                        }
                    }
                    
                    if (!$mysql_appointment_date) {
                        error_log("Invalid appointment date format: $appointment_date");
                        throw new Exception("Invalid appointment date format: $appointment_date. Expected format: YYYY-MM-DD");
                    }
                }
            }

            // Ensure proper time format for MySQL
            $mysql_appointment_time = null;
            if (!empty($appointment_time)) {
                // Validate time format (HH:MM:SS or HH:MM)
                if (preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $appointment_time)) {
                    $mysql_appointment_time = $appointment_time;
                    // Ensure HH:MM:SS format
                    if (strlen($mysql_appointment_time) === 5) {
                        $mysql_appointment_time .= ':00';
                    }
                } else {
                    throw new Exception("Invalid appointment time format: $appointment_time");
                }
            }

            // Insert referral
            $stmt = $conn->prepare("
                INSERT INTO referrals (
                    referral_num, patient_id, referring_facility_id, referred_to_facility_id, 
                    external_facility_name, vitals_id, service_id, referral_reason, 
                    destination_type, referred_by, referral_date, status,
                    assigned_doctor_id, scheduled_date, scheduled_time
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                'siiisisssissss',
                $referral_num,
                $patient_id,
                $employee_facility_id,
                $referred_to_facility_id,
                $external_facility_name,
                $vitals_id,
                $final_service_id,
                $final_referral_reason,
                $destination_type,
                $employee_id,
                $referral_status,
                $assigned_doctor_id,
                $mysql_appointment_date,
                $mysql_appointment_time
            );
            $stmt->execute();

            // Get the inserted referral ID
            $referral_id = $conn->insert_id;

            // Generate QR code for the referral
            require_once $root_path . '/utils/qr_code_generator.php';

            $qr_data = [
                'patient_id' => $patient_id,
                'destination_type' => $destination_type,
                'scheduled_date' => $mysql_appointment_date,
                'scheduled_time' => $mysql_appointment_time,
                'assigned_doctor_id' => $assigned_doctor_id,
                'referred_to_facility_id' => $referred_to_facility_id
            ];

            $qr_result = QRCodeGenerator::generateAndSaveReferralQR($referral_id, $qr_data, $conn);

            if (!$qr_result['success']) {
                // Log QR generation failure but don't fail the entire transaction
                error_log("Failed to generate QR code for referral $referral_id: " . $qr_result['error']);
            } else {
                error_log("QR code generated successfully for referral $referral_id");
            }

            // Send referral confirmation email
            try {
                // Get patient information including email
                $stmt = $conn->prepare("
                    SELECT p.*, b.barangay_name, 
                           CONCAT(p.first_name, ' ', COALESCE(p.middle_name, ''), ' ', p.last_name) as full_name
                    FROM patients p 
                    LEFT JOIN barangay b ON p.barangay_id = b.barangay_id 
                    WHERE p.patient_id = ?
                ");
                $stmt->bind_param('i', $patient_id);
                $stmt->execute();
                $patient_result = $stmt->get_result();
                $patient_email_info = $patient_result->fetch_assoc();

                if ($patient_email_info && !empty($patient_email_info['email'])) {
                    // Get destination facility name
                    $destination_facility_name = $external_facility_name;
                    if ($referred_to_facility_id) {
                        $stmt = $conn->prepare("SELECT name FROM facilities WHERE facility_id = ?");
                        $stmt->bind_param('i', $referred_to_facility_id);
                        $stmt->execute();
                        $facility_result = $stmt->get_result();
                        $facility_data = $facility_result->fetch_assoc();
                        if ($facility_data) {
                            $destination_facility_name = $facility_data['name'];
                        }
                    }

                    // Get doctor name if assigned
                    $doctor_name = '';
                    if ($assigned_doctor_id) {
                        $stmt = $conn->prepare("
                            SELECT CONCAT(first_name, ' ', last_name) as doctor_name 
                            FROM employees 
                            WHERE employee_id = ?
                        ");
                        $stmt->bind_param('i', $assigned_doctor_id);
                        $stmt->execute();
                        $doctor_result = $stmt->get_result();
                        $doctor_data = $doctor_result->fetch_assoc();
                        if ($doctor_data) {
                            $doctor_name = $doctor_data['doctor_name'];
                        }
                    }

                    // Get service name
                    $service_name = '';
                    if ($final_service_id) {
                        $stmt = $conn->prepare("SELECT name FROM services WHERE service_id = ?");
                        $stmt->bind_param('i', $final_service_id);
                        $stmt->execute();
                        $service_result = $stmt->get_result();
                        $service_data = $service_result->fetch_assoc();
                        if ($service_data) {
                            $service_name = $service_data['name'];
                        }
                    } elseif (!empty($custom_service_name)) {
                        $service_name = $custom_service_name;
                    }

                    // Prepare referral details for email
                    $referral_details = [
                        'referral_reason' => $final_referral_reason,
                        'facility_name' => $destination_facility_name,
                        'external_facility_name' => $external_facility_name,
                        'scheduled_date' => $mysql_appointment_date,
                        'scheduled_time' => $mysql_appointment_time,
                        'doctor_name' => $doctor_name,
                        'service_name' => $service_name,
                        'referring_facility' => $employee_facility_name,
                        'destination_type' => $destination_type
                    ];

                    // Include email utility
                    require_once $root_path . '/utils/referral_email.php';

                    // Send the email
                    $email_result = sendReferralConfirmationEmail(
                        $patient_email_info,
                        $referral_num,
                        $referral_details,
                        $qr_result
                    );

                    if ($email_result['success']) {
                        error_log("Referral confirmation email sent successfully for referral $referral_num to {$patient_email_info['email']}");
                    } else {
                        error_log("Failed to send referral confirmation email for referral $referral_num: " . $email_result['message']);
                        // Don't fail the transaction for email issues
                    }
                } else {
                    error_log("No email address available for patient $patient_id - referral email not sent");
                }
            } catch (Exception $email_exception) {
                error_log("Exception while sending referral confirmation email: " . $email_exception->getMessage());
                // Don't fail the transaction for email issues
            }

            // If this is a city office referral with a time slot, mark the slot as booked
            if ($destination_type === 'city_office' && isset($schedule_slot_id) && $schedule_slot_id) {
                $stmt = $conn->prepare("
                    UPDATE doctor_schedule_slots 
                    SET is_booked = 1, referral_id = ? 
                    WHERE slot_id = ?
                ");
                $stmt->bind_param("ii", $referral_id, $schedule_slot_id);
                $stmt->execute();
            }

            $conn->commit();

            // Prepare success message
            $success_msg = "Referral created successfully! Referral #: $referral_num";

            // Add email notification to success message if patient has email
            if (!empty($patient_email_info['email'])) {
                if (isset($email_result) && $email_result['success']) {
                    $success_msg .= " A confirmation email has been sent to " . $patient_email_info['email'] . ".";
                } else {
                    $success_msg .= " Note: Confirmation email could not be sent to " . $patient_email_info['email'] . ".";
                }
            }

            $_SESSION['snackbar_message'] = $success_msg;

            // Redirect to referrals management page after successful creation
            header('Location: referrals_management.php');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
}

// Patient search functionality
$search_query = $_GET['search'] ?? '';
$first_name = $_GET['first_name'] ?? '';
$last_name = $_GET['last_name'] ?? '';
$barangay_filter = $_GET['barangay'] ?? '';

// Check if we need to keep a patient selected after vitals save
$keep_patient_selected = $_SESSION['keep_patient_selected'] ?? null;
if ($keep_patient_selected) {
    unset($_SESSION['keep_patient_selected']);
}

$patients = [];
// If we're keeping a patient selected (after vitals save), automatically search for that patient
if ($keep_patient_selected) {
    // Search for the specific patient to display
    $sql = "
        SELECT p.patient_id, p.username, p.first_name, p.middle_name, p.last_name, 
               p.date_of_birth, p.sex, p.contact_number, b.barangay_name,
               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
        FROM patients p 
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id 
        WHERE p.patient_id = ? AND p.status = 'active'
        $jurisdiction_restriction
    ";

    $stmt = $conn->prepare($sql);
    // Bind patient ID first, then jurisdiction parameters
    $all_params = array_merge([$keep_patient_selected], $jurisdiction_params);
    $all_param_types = 'i' . $jurisdiction_param_types;
    $stmt->bind_param($all_param_types, ...$all_params);
    $stmt->execute();
    $result = $stmt->get_result();
    $patients = $result->fetch_all(MYSQLI_ASSOC);
}
// Normal search functionality
elseif ($search_query || $first_name || $last_name || $barangay_filter) {
    $where_conditions = [];
    $params = [];
    $param_types = '';

    if (!empty($search_query)) {
        $where_conditions[] = "(p.username LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)";
        $search_term = "%$search_query%";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
        $param_types .= 'ssss';
    }

    if (!empty($first_name)) {
        $where_conditions[] = "p.first_name LIKE ?";
        $params[] = "%$first_name%";
        $param_types .= 's';
    }

    if (!empty($last_name)) {
        $where_conditions[] = "p.last_name LIKE ?";
        $params[] = "%$last_name%";
        $param_types .= 's';
    }

    if (!empty($barangay_filter)) {
        $where_conditions[] = "b.barangay_name LIKE ?";
        $params[] = "%$barangay_filter%";
        $param_types .= 's';
    }

    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

    $sql = "
        SELECT p.patient_id, p.username, p.first_name, p.middle_name, p.last_name, 
               p.date_of_birth, p.sex, p.contact_number, b.barangay_name,
               TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) as age
        FROM patients p 
        LEFT JOIN barangay b ON p.barangay_id = b.barangay_id 
        $where_clause
        AND p.status = 'active'
        $jurisdiction_restriction
        ORDER BY p.last_name, p.first_name
        LIMIT 5
    ";

    if (!empty($params) || !empty($jurisdiction_params)) {
        // Combine search parameters with jurisdiction parameters
        $all_params = array_merge($params, $jurisdiction_params);
        $all_param_types = $param_types . $jurisdiction_param_types;

        $stmt = $conn->prepare($sql);
        if (!empty($all_params)) {
            $stmt->bind_param($all_param_types, ...$all_params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $patients = $result->fetch_all(MYSQLI_ASSOC);
    }
}

// Get barangays for filter dropdown - restricted by jurisdiction
$barangays = [];
try {
    if ($employee_role === 'dho' && !empty($jurisdiction_params)) {
        // DHO can only see barangays in their district
        $stmt = $conn->prepare("SELECT barangay_name FROM barangay WHERE status = 'active' AND district_id = ? ORDER BY barangay_name ASC");
        $stmt->bind_param('i', $jurisdiction_params[0]); // district_id
    } elseif ($employee_role === 'bhw' && !empty($jurisdiction_params)) {
        // BHW can only see their own barangay
        $stmt = $conn->prepare("SELECT barangay_name FROM barangay WHERE status = 'active' AND barangay_id = ? ORDER BY barangay_name ASC");
        $stmt->bind_param('i', $jurisdiction_params[0]); // barangay_id
    } else {
        // Other roles can see all active barangays
        $stmt = $conn->prepare("SELECT barangay_name FROM barangay WHERE status = 'active' ORDER BY barangay_name ASC");
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $barangays = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    // Ignore errors for barangays
}

// Get available facilities for referral (excluding current employee's facility)
$available_facilities = [];
try {
    $stmt = $conn->prepare("
        SELECT f.facility_id, f.name, f.type, b.barangay_name 
        FROM facilities f 
        JOIN barangay b ON f.barangay_id = b.barangay_id 
        WHERE f.status = 'active' AND f.facility_id != ?
        ORDER BY f.type, f.name
    ");
    $stmt->bind_param('i', $employee_facility_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $available_facilities = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    // Ignore errors for facilities
}

// Get all services for dropdown
$all_services = [];
try {
    $stmt = $conn->prepare("SELECT service_id, name, description FROM services ORDER BY name");
    $stmt->execute();
    $result = $stmt->get_result();
    $all_services = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    // Ignore errors for services
}

// Function to get latest vitals for a patient
function getLatestVitals($conn, $patient_id)
{
    try {
        $stmt = $conn->prepare("
            SELECT v.*, CONCAT(e.first_name, ' ', e.last_name) as recorded_by_name
            FROM vitals v 
            LEFT JOIN employees e ON v.recorded_by = e.employee_id
            WHERE v.patient_id = ? 
            ORDER BY v.recorded_at DESC, v.vitals_id DESC 
            LIMIT 1
        ");
        $stmt->bind_param('i', $patient_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    } catch (Exception $e) {
        return null;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="shortcut icon" type="image/png" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <link rel="apple-touch-icon-precomposed" href="https://ik.imagekit.io/wbhsmslogo/Nav_LogoClosed.png?updatedAt=1751197276128">
    <title>Create Referral | CHO Koronadal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="stylesheet" href="../../assets/css/topbar.css" />
    <link rel="stylesheet" href="../../assets/css/profile-edit-responsive.css" />
    <link rel="stylesheet" href="../../assets/css/profile-edit.css" />
    <link rel="stylesheet" href="../../assets/css/edit.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        .search-container {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .search-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
            margin-bottom: 1rem;
        }

        .patient-table {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0077b6;
        }

        .patient-table table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            min-width: 600px;
            /* Ensure table doesn't get too cramped */
        }

        .patient-table th,
        .patient-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }

        .patient-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #0077b6;
        }

        .patient-table tbody tr:hover {
            background: #f8f9fa;
        }

        .patient-checkbox {
            width: 18px;
            height: 18px;
            margin-right: 0.5rem;
        }

        .referral-form {
            opacity: 0.5;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .referral-form.enabled {
            opacity: 1;
            pointer-events: auto;
        }

        .vitals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .vitals-form {
            margin-bottom: 2rem;
        }

        .vitals-info {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 5px;
            padding: 0.75rem;
            margin-top: 0.5rem;
            font-size: 0.9em;
            color: #666;
        }

        .vitals-info.last-vitals {
            background: #f8f9fa;
            border-left: 4px solid #17a2b8;
        }

        .vitals-info.current-vitals {
            background: #e8f5e8;
            border-left: 4px solid #28a745;
            color: #155724;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #0077b6;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.75rem;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.9rem;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #0077b6;
            box-shadow: 0 0 0 3px rgba(0, 119, 182, 0.1);
        }

        .selected-patient {
            background: #d4edda !important;
            border-left: 4px solid #28a745;
        }

        .empty-search {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            background: linear-gradient(135deg, #023e8a, #001d3d);
            transform: translateY(-2px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .conditional-field {
            display: none;
            margin-top: 1rem;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .conditional-field.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .auto-populated-field {
            background: #f8f9fa !important;
            border: 2px solid #e9ecef !important;
            cursor: not-allowed;
        }

        .facility-info-box {
            background: #e8f5e8;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #28a745;
            margin: 0.5rem 0;
        }

        .loading-spinner {
            display: inline-block;
            margin-right: 8px;
        }

        .validation-error {
            border: 2px solid #dc3545 !important;
            background: #fff5f5 !important;
        }

        .validation-success {
            border: 2px solid #28a745 !important;
            background: #f8fff8 !important;
        }

        .field-help-text {
            color: #666;
            font-size: 0.85em;
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f1b2b7;
        }

        /* Responsive Patient Table */
        .patient-table-container {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .patient-card {
            display: none;
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .patient-card:hover {
            border-color: #0077b6;
            box-shadow: 0 2px 8px rgba(0, 119, 182, 0.1);
        }

        .patient-card.selected {
            border-color: #28a745;
            background: #f8fff8;
        }

        .patient-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            margin-top: 1.5rem;
        }

        .patient-card-name {
            font-weight: 600;
            color: #0077b6;
            font-size: 1.1em;
        }

        .patient-card-id {
            background: #f8f9fa;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.85em;
            color: #6c757d;
        }

        .patient-card-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            font-size: 0.9em;
        }

        .patient-card-detail {
            display: flex;
            flex-direction: column;
        }

        .patient-card-label {
            font-weight: 600;
            color: #6c757d;
            font-size: 0.8em;
            margin-bottom: 0.1rem;
        }

        .patient-card-value {
            color: #333;
        }

        .patient-card-checkbox {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 20px;
            height: 20px;
        }

        @media (max-width: 768px) {
            .search-grid {
                grid-template-columns: 1fr;
            }

            .vitals-grid {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            /* Hide table and show cards on mobile */
            .patient-table table {
                display: none;
            }

            .patient-card {
                display: block;
            }

            .patient-table-container {
                overflow-x: visible;
            }
        }

        @media (max-width: 480px) {
            .patient-card-details {
                grid-template-columns: 1fr;
            }

            .search-container {
                padding: 1rem;
            }

            .patient-table {
                padding: 1rem;
            }
        }

        /* Referral Confirmation Modal Styles */
        .referral-confirmation-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
            z-index: 10000;
            animation: fadeIn 0.3s ease;
        }

        .referral-confirmation-modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 99999;

        }

        .referral-modal-content {
            background: white;
            border-radius: 20px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideInUp 0.4s ease;
            position: relative;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .referral-modal-header {
            background: linear-gradient(135deg, #0077b6, #023e8a);
            color: white;
            padding: 2rem;
            border-radius: 20px 20px 0 0;
            text-align: center;
            position: relative;
            flex-shrink: 0;
        }

        .referral-modal-header h3 {
            margin: 0;
            font-size: 1.5em;
            font-weight: 600;
        }

        .referral-modal-header .icon {
            font-size: 3em;
            margin-bottom: 1rem;
            opacity: 0.9;
        }

        .referral-modal-close {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.2em;
            transition: background 0.3s;
        }

        .referral-modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .referral-modal-body {
            padding: 2rem;
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }

        /* Custom scrollbar for modal body */
        .referral-modal-body::-webkit-scrollbar {
            width: 6px;
        }

        .referral-modal-body::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }

        .referral-modal-body::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 3px;
        }

        .referral-modal-body::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        .referral-summary-card {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .summary-section {
            margin-bottom: 1.5rem;
        }

        .summary-section:last-child {
            margin-bottom: 0;
        }

        .summary-title {
            font-weight: 700;
            color: #0077b6;
            font-size: 1.1em;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .summary-title i {
            background: #e3f2fd;
            padding: 0.5rem;
            border-radius: 8px;
            color: #0077b6;
            font-size: 0.9em;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .summary-item {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .summary-label {
            font-size: 0.85em;
            color: #6c757d;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-value {
            font-size: 1.05em;
            color: #333;
            font-weight: 500;
            word-wrap: break-word;
        }

        .summary-value.highlight {
            color: #0077b6;
            font-weight: 600;
        }

        .summary-value.reason {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            border-left: 4px solid #0077b6;
            margin-top: 0.5rem;
            line-height: 1.5;
        }

        .vitals-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 0.8rem;
            margin-top: 1rem;
        }

        .vital-item {
            background: white;
            padding: 0.8rem;
            border-radius: 8px;
            text-align: center;
            border: 2px solid #e9ecef;
        }

        .vital-value {
            font-size: 1.2em;
            font-weight: 700;
            color: #0077b6;
        }

        .vital-label {
            font-size: 0.8em;
            color: #6c757d;
            margin-top: 0.3rem;
        }

        .referral-modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            padding: 1.5rem 2rem 2rem;
            flex-shrink: 0;
            background: white;
            border-top: 1px solid #e9ecef;
        }

        .modal-btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1em;
            min-width: 120px;
        }

        .modal-btn-cancel {
            background: #f8f9fa;
            color: #6c757d;
            border: 2px solid #dee2e6;
        }

        .modal-btn-cancel:hover {
            background: #e9ecef;
            color: #5a6268;
        }

        .modal-btn-confirm {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }

        .modal-btn-confirm:hover:not(:disabled) {
            background: linear-gradient(135deg, #218838, #1e7e34);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }

        .modal-btn-confirm:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideInUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @media (max-width: 768px) {
            .referral-modal-content {
                margin: 1rem;
                border-radius: 15px;
            }

            .referral-modal-header {
                padding: 1.5rem;
                border-radius: 15px 15px 0 0;
            }

            .referral-modal-header h3 {
                font-size: 1.3em;
            }

            .referral-modal-header .icon {
                font-size: 2.5em;
            }

            .referral-modal-body {
                padding: 1.5rem;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .vitals-summary {
                grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            }

            .referral-modal-actions {
                flex-direction: column;
                padding: 1rem 1.5rem 1.5rem;
            }

            .modal-btn {
                padding: 0.8rem 1.5rem;
            }
        }

        /* Appointment Scheduling Section Styles */
        .appointment-section,
        .simple-schedule-section {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #28a745;
            /* Green border for appointment sections */
        }

        .appointment-section .section-title h3,
        .simple-schedule-section .section-title h3 {
            color: #28a745;
            font-size: 1.2rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .appointment-section .form-row,
        .simple-schedule-section .form-row {
            margin-bottom: 1rem;
        }

        .appointment-section .form-group label,
        .simple-schedule-section .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }

        .appointment-section .form-group select,
        .appointment-section .form-group input,
        .simple-schedule-section .form-group select,
        .simple-schedule-section .form-group input {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e1e5e9;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .appointment-section .form-group select:focus,
        .appointment-section .form-group input:focus,
        .simple-schedule-section .form-group select:focus,
        .simple-schedule-section .form-group input:focus {
            outline: none;
            border-color: #28a745;
            box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
        }

        .appointment-section small,
        .simple-schedule-section small {
            display: block;
            margin-top: 0.5rem;
            color: #666;
            font-size: 0.85em;
        }

        #noSlotsMessage {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 5px;
            padding: 0.75rem;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {

            .appointment-section,
            .simple-schedule-section {
                padding: 1rem;
                margin-bottom: 1rem;
            }

            .appointment-section .section-title h3,
            .simple-schedule-section .section-title h3 {
                font-size: 1.1rem;
            }

            /* Stack doctor select and badge vertically on mobile */
            .form-group div[style*="display: flex"] {
                flex-direction: column !important;
                align-items: flex-start !important;
            }

            .slots-badge {
                margin-top: 8px;
                align-self: flex-start;
            }
        }

        /* Slots Available Badge Styles */
        .slots-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
            white-space: nowrap;
            animation: fadeIn 0.3s ease;
        }

        .slots-badge i {
            font-size: 0.9em;
        }

        .slots-badge.warning {
            background: linear-gradient(135deg, #ffc107, #ff8c00);
            color: #212529;
        }

        .slots-badge.danger {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-5px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>

<body>
    <?php
    // Render snackbar notification system
    renderSnackbar();

    // Render topbar
    renderTopbar([
        'title' => 'Create New Referral',
        'back_url' => 'referrals_management.php',
        'user_type' => 'employee',
        'vendor_path' => '../../vendor/'
    ]);
    ?>

    <section class="homepage">
        <?php
        // Render back button with modal
        renderBackButton([
            'back_url' => 'referrals_management.php',
            'button_text' => '← Back / Cancel',
            'modal_title' => 'Cancel Creating Referral?',
            'modal_message' => 'Are you sure you want to go back/cancel? Unsaved changes will be lost.',
            'confirm_text' => 'Yes, Cancel',
            'stay_text' => 'Stay'
        ]);
        ?>

        <div class="profile-wrapper">
            <!-- Reminders Box -->
            <div class="reminders-box">
                <strong>Reminders:</strong>
                <ul>
                    <li>Search and select a patient from the list below before creating a referral.</li>
                    <li>You can search by patient ID, name, or barangay.</li>
                    <li>Patient vitals are optional but recommended for medical referrals.</li>
                    <li><strong>Email notifications:</strong> If the patient has an email address on file, they will automatically receive a referral confirmation email with all details and QR code.</li>
                    <li>All referral information should be accurate and complete.</li>
                    <li>Fields marked with * are required.</li>
                </ul>
            </div>

            <?php if (!empty($success_message)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                </div>
            <?php endif; ?>

            <!-- Patient Search Section -->
            <div class="form-section">
                <div class="search-container">
                    <h3><i class="fas fa-search"></i> Search Patient</h3>
                    <form method="GET" class="search-grid">
                        <div class="form-group">
                            <label for="search">General Search</label>
                            <input type="text" id="search" name="search" value="<?= htmlspecialchars($search_query) ?>"
                                placeholder="Patient ID, Name...">
                        </div>
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($first_name) ?>"
                                placeholder="Enter first name...">
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($last_name) ?>"
                                placeholder="Enter last name...">
                        </div>
                        <div class="form-group">
                            <label for="barangay">Barangay</label>
                            <select id="barangay" name="barangay">
                                <option value="">All Barangays</option>
                                <?php foreach ($barangays as $brgy): ?>
                                    <option value="<?= htmlspecialchars($brgy['barangay_name']) ?>"
                                        <?= $barangay_filter === $brgy['barangay_name'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($brgy['barangay_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Patient Results Table -->
            <div class="form-section">
                <div class="patient-table">
                    <h3><i class="fas fa-users"></i> Patient Search Results</h3>
                    <?php if (empty($patients) && ($search_query || $first_name || $last_name || $barangay_filter)): ?>
                        <div class="empty-search">
                            <i class="fas fa-user-times fa-2x"></i>
                            <p>No patients found matching your search criteria.</p>
                            <p>Try adjusting your search terms or check the spelling.</p>
                        </div>
                    <?php elseif (!empty($patients)): ?>
                        <p>Found <?= count($patients) ?> patient(s). Select one to create a referral:</p>

                        <!-- Desktop Table View -->
                        <div class="patient-table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Select</th>
                                        <th>Patient ID</th>
                                        <th>Name</th>
                                        <th>Age/Sex</th>
                                        <th>Contact</th>
                                        <th>Barangay</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($patients as $patient): ?>
                                        <tr class="patient-row" data-patient-id="<?= $patient['patient_id'] ?>">
                                            <td>
                                                <input type="radio" name="selected_patient" value="<?= $patient['patient_id'] ?>"
                                                    class="patient-checkbox" data-patient-name="<?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?>">
                                            </td>
                                            <td><?= htmlspecialchars($patient['username']) ?></td>
                                            <td>
                                                <?= htmlspecialchars($patient['first_name'] . ' ' .
                                                    ($patient['middle_name'] ? $patient['middle_name'] . ' ' : '') .
                                                    $patient['last_name']) ?>
                                            </td>
                                            <td><?= htmlspecialchars($patient['age']) ?> / <?= htmlspecialchars($patient['sex']) ?></td>
                                            <td><?= htmlspecialchars($patient['contact_number'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars($patient['barangay_name'] ?? 'N/A') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Mobile Card View -->
                        <?php foreach ($patients as $patient): ?>
                            <div class="patient-card" data-patient-id="<?= $patient['patient_id'] ?>" onclick="selectPatientCard(this)">
                                <input type="radio" name="selected_patient_mobile" value="<?= $patient['patient_id'] ?>"
                                    class="patient-card-checkbox patient-checkbox" data-patient-name="<?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?>">

                                <div class="patient-card-header">
                                    <div class="patient-card-name">
                                        <?= htmlspecialchars($patient['first_name'] . ' ' .
                                            ($patient['middle_name'] ? $patient['middle_name'] . ' ' : '') .
                                            $patient['last_name']) ?>
                                    </div>
                                    <div class="patient-card-id"><?= htmlspecialchars($patient['username']) ?></div>
                                </div>

                                <div class="patient-card-details">
                                    <div class="patient-card-detail">
                                        <div class="patient-card-label">Age / Sex</div>
                                        <div class="patient-card-value"><?= htmlspecialchars($patient['age']) ?> / <?= htmlspecialchars($patient['sex']) ?></div>
                                    </div>
                                    <div class="patient-card-detail">
                                        <div class="patient-card-label">Contact</div>
                                        <div class="patient-card-value"><?= htmlspecialchars($patient['contact_number'] ?? 'N/A') ?></div>
                                    </div>
                                    <div class="patient-card-detail">
                                        <div class="patient-card-label">Barangay</div>
                                        <div class="patient-card-value"><?= htmlspecialchars($patient['barangay_name'] ?? 'N/A') ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-search">
                            <i class="fas fa-search fa-2x"></i>
                            <p>Use the search form above to find patients.</p>
                            <p>Search results will appear here (maximum 5 results).</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Patient Vitals Section - Separate Form -->
            <div class="form-section">
                <form class="profile-card referral-form enabled" id="vitalsForm" method="post">
                    <input type="hidden" name="action" value="save_vitals">
                    <input type="hidden" name="patient_id" id="vitalsPatientId">

                    <h3><i class="fas fa-heartbeat"></i> Patient Vitals</h3>
                    <div id="vitalsPatientInfo" class="selected-patient-info" style="display:none;">
                        <p><strong>Patient:</strong> <span id="vitalsPatientName"></span></p>
                        <div id="lastVitalsInfo" style="margin-top: 0.5rem; padding: 0.75rem; background: #f8f9fa; border-radius: 5px; font-size: 0.9em; color: #666; display: none;">
                            <i class="fas fa-info-circle"></i> <span id="lastVitalsText"></span>
                        </div>
                        <div id="noVitalsInfo" style="margin-top: 0.5rem; padding: 0.75rem; background: #fff3cd; border-radius: 5px; font-size: 0.9em; color: #856404; border-left: 4px solid #ffc107; display: none;">
                            <i class="fas fa-exclamation-triangle"></i> <span id="noVitalsText">No previous vitals recorded for this patient. You can record new vitals below.</span>
                        </div>
                        <div id="autoSelectedInfo" style="margin-top: 0.5rem; padding: 0.75rem; background: #d1ecf1; border-radius: 5px; font-size: 0.9em; color: #0c5460; border-left: 4px solid #17a2b8; display: none;">
                            <i class="fas fa-check-circle"></i> <span id="autoSelectedText">Patient automatically selected after saving vitals. New vitals displayed below.</span>
                        </div>
                    </div>

                    <div class="vitals-grid">
                        <div class="form-group">
                            <label for="systolic_bp">Systolic BP</label>
                            <input type="number" id="systolic_bp" name="systolic_bp" min="60" max="300" placeholder="120">
                        </div>
                        <div class="form-group">
                            <label for="diastolic_bp">Diastolic BP</label>
                            <input type="number" id="diastolic_bp" name="diastolic_bp" min="40" max="200" placeholder="80">
                        </div>
                        <div class="form-group">
                            <label for="heart_rate">Heart Rate</label>
                            <input type="number" id="heart_rate" name="heart_rate" min="30" max="200" placeholder="72">
                        </div>
                        <div class="form-group">
                            <label for="respiratory_rate">Respiratory Rate</label>
                            <input type="number" id="respiratory_rate" name="respiratory_rate" min="8" max="60" placeholder="18">
                        </div>
                        <div class="form-group">
                            <label for="temperature">Temperature (°C)</label>
                            <input type="number" id="temperature" name="temperature" step="0.1" min="30" max="45" placeholder="36.5">
                        </div>
                        <div class="form-group">
                            <label for="weight">Weight (kg)</label>
                            <input type="number" id="weight" name="weight" step="0.1" min="1" max="500" placeholder="70.0">
                        </div>
                        <div class="form-group">
                            <label for="height">Height (cm)</label>
                            <input type="number" id="height" name="height" step="0.1" min="50" max="250" placeholder="170.0">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="vitals_remarks">Vitals Remarks</label>
                        <textarea id="vitals_remarks" name="vitals_remarks" rows="3"
                            placeholder="Any additional notes about the patient's vitals..."></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" disabled id="saveVitalsBtn">
                            <i class="fas fa-save"></i> Save Vitals
                        </button>
                    </div>
                </form>
            </div>

            <!-- Referral Form -->
            <div class="form-section">
                <form class="profile-card referral-form" id="referralForm" method="post">
                    <input type="hidden" name="action" value="create_referral">
                    <input type="hidden" name="patient_id" id="referralPatientId">
                    <input type="hidden" name="referred_to_facility_id" id="referredToFacilityId">

                    <h3><i class="fas fa-share"></i> Create Referral</h3>
                    <div class="facility-info" style="background: #e8f4fd; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; border-left: 4px solid #0077b6;">
                        <p style="margin: 0; color: #0077b6; font-weight: 600;">
                            <i class="fas fa-hospital"></i> Referring From: <?= htmlspecialchars($employee_facility_name ?: 'Unknown Facility') ?>
                        </p>
                    </div>
                    <div id="referralPatientInfo" class="selected-patient-info" style="display:none;">
                        <p><strong>Selected Patient:</strong> <span id="referralPatientName"></span></p>
                        <div id="currentVitalsInfo" style="margin-top: 0.5rem; padding: 0.75rem; background: #e8f5e8; border-radius: 5px; font-size: 0.9em; color: #155724; border-left: 4px solid #28a745; display: none;">
                            <i class="fas fa-heartbeat"></i> <span id="currentVitalsText"></span>
                        </div>
                    </div>

                    <!-- Referral Information -->
                    <div class="form-section">
                        <h4><i class="fas fa-clipboard-list"></i> Referral Information</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="referral_reason">Reason for Referral *</label>
                                <textarea id="referral_reason" name="referral_reason" rows="4" required
                                    placeholder="Describe the medical condition, symptoms, and reason for referral..."></textarea>
                            </div>
                        </div>

                        <!-- Role-Based Destination Selection -->
                        <div class="form-row">
                            <div class="form-group">
                                <label for="destination_type">Referral Destination Type *</label>
                                <select id="destination_type" name="destination_type" required>
                                    <option value="">Select referral destination...</option>

                                    <?php
                                    $current_role = strtolower($_SESSION['role']);

                                    // BHW can refer to district, city, and external
                                    if ($current_role === 'bhw'): ?>
                                        <!-- District Health Office option will be dynamically shown/hidden based on patient's district -->
                                        <option value="district_office" id="districtOfficeOption">District Health Office</option>
                                        <option value="city_office">City Health Office (Main District)</option>
                                        <option value="external">External Facility</option>

                                    <?php // DHO can refer to city and external
                                    elseif ($current_role === 'dho'): ?>
                                        <option value="city_office">City Health Office (Main District)</option>
                                        <option value="external">External Facility</option>

                                    <?php // Admin can refer to all destinations, others can refer to city office and external
                                    elseif ($current_role === 'admin'): ?>
                                        <!-- District Health Office option will be dynamically shown/hidden based on patient's district -->
                                        <option value="district_office" id="districtOfficeOptionAdmin">District Health Office</option>
                                        <option value="city_office">City Health Office (Main District)</option>
                                        <option value="external">External Facility</option>

                                    <?php // Doctor, Nurse, Records Officer can refer to city office and external
                                    elseif (in_array($current_role, ['doctor', 'nurse', 'records_officer'])): ?>
                                        <option value="city_office">City Health Office (Main District)</option>
                                        <option value="external">External Facility</option>
                                    <?php else:
                                        // Fallback for any other roles - show district, city, and external only 
                                    ?>
                                        <!-- District Health Office option will be dynamically shown/hidden based on patient's district -->
                                        <option value="district_office" id="districtOfficeOptionOther">District Health Office</option>
                                        <option value="city_office">City Health Office (Main District)</option>
                                        <option value="external">External Facility</option>
                                    <?php endif; ?>
                                </select>

                                <?php if ($current_role === 'admin'): ?>
                                    <small style="color: #666; font-size: 0.85em;">
                                        <i class="fas fa-info-circle"></i> As Admin, you can create referrals to District Offices, City Office, or External facilities for comprehensive patient follow-up care
                                    </small>
                                <?php elseif (in_array($current_role, ['doctor', 'nurse', 'records_officer'])): ?>
                                    <small style="color: #666; font-size: 0.85em;">
                                        <i class="fas fa-info-circle"></i> As <?= htmlspecialchars(ucfirst($_SESSION['role'])) ?> at City Health Office, you can refer within the facility or to external facilities
                                    </small>
                                <?php elseif ($current_role === 'dho'): ?>
                                    <small style="color: #666; font-size: 0.85em;">
                                        <i class="fas fa-info-circle"></i> As District Health Officer, you can refer to City Health Office or external facilities
                                    </small>
                                <?php elseif ($current_role === 'bhw'): ?>
                                    <small style="color: #666; font-size: 0.85em;">
                                        <i class="fas fa-info-circle"></i> As Barangay Health Worker, you can refer to District Office (for non-Main District patients), City Health Office, or external facilities
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Auto-populated District Office -->
                        <div class="form-row conditional-field" id="districtOfficeField">
                            <div class="form-group">
                                <label for="district_office_display">District Office</label>
                                <input type="text" id="district_office_display" readonly
                                    placeholder="Will be auto-populated based on patient's district"
                                    style="background: #f8f9fa; border: 2px solid #e9ecef;">

                                <small style="color: #666; font-size: 0.85em;">
                                    <i class="fas fa-info-circle"></i> Auto-selected based on patient's district
                                </small>
                            </div>
                        </div>

                        <!-- City Health Office (no additional fields needed) -->
                        <div class="form-row conditional-field" id="cityOfficeField">
                            <div class="form-group">

                                <div style="background: #e8f5e8; padding: 1rem; border-radius: 8px; border-left: 4px solid #28a745;">
                                    <i class="fas fa-hospital"></i> <strong>City Health Office (Main District)</strong>
                                    <br><small style="color: #666;">Direct referral to main city facility</small>
                                </div>
                            </div>
                        </div>

                        <!-- External Facility Selection -->
                        <div class="form-row conditional-field" id="externalFacilityTypeField">
                            <div class="form-group">
                                <label for="external_facility_type">External Facility Type *</label>
                                <select id="external_facility_type" name="external_facility_type">
                                    <option value="">Select external facility type...</option>
                                    <option value="hospital">Hospital</option>
                                    <option value="other">Other Facility</option>
                                </select>
                            </div>
                        </div>

                        <!-- Hospital Selection -->
                        <div class="form-row conditional-field" id="hospitalSelectionField">
                            <div class="form-group">
                                <label for="hospital_name">Hospital Name *</label>
                                <select id="hospital_name" name="hospital_name">
                                    <option value="">Select hospital...</option>
                                    <option value="South Cotabato Provincial Hospital (SCPH)">South Cotabato Provincial Hospital (SCPH)</option>
                                    <option value="Dr. Arturo P. Pingoy Medical Center">Dr. Arturo P. Pingoy Medical Center</option>
                                    <option value="Allah Valley Medical Specialists' Center, Inc.">Allah Valley Medical Specialists' Center, Inc.</option>
                                    <option value="Socomedics Medical Center">Socomedics Medical Center</option>
                                </select>
                            </div>
                        </div>

                        <!-- Other Facility Input -->
                        <div class="form-row conditional-field" id="otherFacilityField">
                            <div class="form-group">
                                <label for="other_facility_name">Specify Other Facility *</label>
                                <input type="text" id="other_facility_name" name="other_facility_name"
                                    placeholder="Enter other facility name (minimum 3 characters)"
                                    minlength="3">
                                <small style="color: #666; font-size: 0.85em;">
                                    Please provide the full name of the facility
                                </small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="service_id">Service Type (Optional)</label>
                                <select id="service_id" name="service_id">
                                    <option value="">Select service (optional)...</option>
                                    <?php foreach ($all_services as $service): ?>
                                        <option value="<?= $service['service_id'] ?>">
                                            <?= htmlspecialchars($service['name']) ?>
                                            <?php if ($service['description']): ?>
                                                - <?= htmlspecialchars(substr($service['description'], 0, 50)) ?>...
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <!-- Others option - will be shown/hidden based on destination type -->
                                    <option value="others" id="othersServiceOption" style="display: none;">Others (Specify below)</option>
                                </select>
                                <small style="color: #666; font-size: 0.85em;">
                                    <span id="serviceAvailabilityNote">Note: Service availability may vary by destination facility</span>
                                </small>
                            </div>
                        </div>

                        <!-- Custom Service Input (only shows when "Others" is selected) -->
                        <div class="form-row conditional-field" id="customServiceField">
                            <div class="form-group">
                                <label for="custom_service_name">Specify Other Service *</label>
                                <input type="text" id="custom_service_name" name="custom_service_name"
                                    placeholder="Enter the specific service name (minimum 3 characters)"
                                    minlength="3">
                                <small style="color: #666; font-size: 0.85em;">
                                    Please provide the specific service or treatment needed
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Doctor Selection and Appointment Scheduling Section -->
                    <div class="appointment-section" id="appointmentSection" style="display: none;">
                        <div class="section-title">
                            <h3><i class="fas fa-user-md"></i> Doctor Assignment & Appointment Scheduling</h3>
                        </div>

                        <!-- Date Selection (First Step) -->
                        <div class="form-row" id="dateSelectionField">
                            <div class="form-group">
                                <label for="appointment_date">1. Appointment Date *</label>
                                <input type="date" id="appointment_date" name="appointment_date"
                                    min="<?= date('Y-m-d') ?>"
                                    max="<?= date('Y-m-d', strtotime('+60 days')) ?>">
                                <small style="color: #666; font-size: 0.85em;">
                                    <i class="fas fa-calendar-alt"></i> Step 1: Select a date within the next 60 days
                                </small>
                            </div>
                        </div>

                        <!-- Doctor Selection (Second Step - depends on date) -->
                        <div class="form-row" id="doctorSelectionField">
                            <div class="form-group">
                                <label for="assigned_doctor_id">2. Assign Doctor *</label>
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <select id="assigned_doctor_id" name="assigned_doctor_id" style="flex: 1;">
                                        <option value="">First select appointment date...</option>
                                    </select>
                                    <div id="slotsAvailableBadge" style="display: none;">
                                        <span class="slots-badge">
                                            <i class="fas fa-calendar-check"></i>
                                            <span id="slotCount">0</span> slots available
                                        </span>
                                    </div>
                                </div>
                                <small class="field-help-text">
                                    <i class="fas fa-user-md"></i>
                                    Step 2: Only doctors with available slots for the selected date will be shown
                                </small>
                            </div>
                        </div>

                        <!-- Time Slot Selection (Third Step - depends on doctor and date) -->
                        <div class="form-row" id="timeSlotField">
                            <div class="form-group">
                                <label for="schedule_slot_id">3. Available Time Slots *</label>
                                <select id="schedule_slot_id" name="schedule_slot_id">
                                    <option value="">First select date and doctor...</option>
                                </select>
                                <small style="color: #666; font-size: 0.85em;">
                                    <i class="fas fa-clock"></i> Step 3: Available slots will appear after selecting date and doctor
                                </small>
                                <div id="noSlotsMessage" style="display: none; color: #dc3545; margin-top: 8px;">
                                    <i class="fas fa-exclamation-triangle"></i> <span id="noSlotsText">No available time slots for selected date. Please choose another date.</span>
                                </div>
                                <div id="weekendMessage" style="display: none; color: #f39c12; margin-top: 8px; background: #fef9e7; padding: 8px; border-radius: 4px; border-left: 3px solid #f39c12;">
                                    <i class="fas fa-calendar-times"></i> <strong>Weekend Selected:</strong> The City Health Office is closed on weekends (Saturday & Sunday) and public holidays. Please select a weekday.
                                </div>
                                <div id="noScheduleMessage" style="display: none; color: #6c757d; margin-top: 8px; background: #f8f9fa; padding: 8px; border-radius: 4px; border-left: 3px solid #6c757d;">
                                    <i class="fas fa-calendar-plus"></i> <strong>No Schedule Available:</strong> The selected doctor doesn't have a schedule set for this date yet. Please try another date or contact the administrator to set up the doctor's schedule.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Simple Date/Time Selection (for non-City Health Office destinations) -->
                    <div class="simple-schedule-section" id="simpleScheduleSection" style="display: none;">
                        <div class="section-title">
                            <h3><i class="fas fa-calendar-check"></i> Schedule Appointment</h3>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="simple_scheduled_date">Scheduled Date *</label>
                                <input type="date" id="simple_scheduled_date" name="simple_scheduled_date"
                                    min="<?= date('Y-m-d') ?>"
                                    max="<?= date('Y-m-d', strtotime('+90 days')) ?>">
                                <small style="color: #666; font-size: 0.85em;">
                                    <i class="fas fa-calendar-alt"></i> Select appointment date
                                </small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="simple_scheduled_time">Scheduled Time *</label>
                                <input type="time" id="simple_scheduled_time" name="simple_scheduled_time"
                                    min="08:00" max="17:00">
                                <small style="color: #666; font-size: 0.85em;">
                                    <i class="fas fa-clock"></i> Business hours: 8:00 AM - 5:00 PM
                                </small>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" disabled>
                            <i class="fas fa-share"></i> Create Referral
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // Initialize service filtering on page load
            const destinationTypeSelect = document.getElementById('destination_type');
            if (destinationTypeSelect && destinationTypeSelect.value) {
                filterServicesByDestination(destinationTypeSelect.value);
            }

            // Mobile patient card selection function
            window.selectPatientCard = function(cardElement) {
                const checkbox = cardElement.querySelector('.patient-card-checkbox');
                if (checkbox && !checkbox.checked) {
                    // Uncheck all other checkboxes (both mobile and desktop)
                    document.querySelectorAll('.patient-checkbox').forEach(cb => cb.checked = false);

                    // Remove previous selections from both cards and rows
                    document.querySelectorAll('.patient-card').forEach(card => card.classList.remove('selected'));
                    document.querySelectorAll('.patient-row').forEach(row => row.classList.remove('selected-patient'));

                    // Select current card
                    checkbox.checked = true;
                    cardElement.classList.add('selected');

                    // Trigger the change event to handle form enabling
                    const event = new Event('change', {
                        bubbles: true
                    });
                    checkbox.dispatchEvent(event);
                }
            };

            // Patient selection logic
            const patientCheckboxes = document.querySelectorAll('.patient-checkbox');
            const referralForm = document.getElementById('referralForm');
            const vitalsForm = document.getElementById('vitalsForm');
            const submitBtn = referralForm ? referralForm.querySelector('button[type="submit"]') : null;
            const saveVitalsBtn = document.getElementById('saveVitalsBtn');
            const referralPatientId = document.getElementById('referralPatientId');
            const vitalsPatientId = document.getElementById('vitalsPatientId');
            const referralPatientInfo = document.getElementById('referralPatientInfo');
            const vitalsPatientInfo = document.getElementById('vitalsPatientInfo');
            const referralPatientName = document.getElementById('referralPatientName');
            const vitalsPatientName = document.getElementById('vitalsPatientName');

            patientCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    if (this.checked) {
                        // Uncheck other checkboxes
                        patientCheckboxes.forEach(cb => {
                            if (cb !== this) cb.checked = false;
                        });

                        // Remove previous selections from both desktop and mobile
                        document.querySelectorAll('.patient-row').forEach(row => {
                            row.classList.remove('selected-patient');
                        });
                        document.querySelectorAll('.patient-card').forEach(card => {
                            card.classList.remove('selected');
                        });

                        // Highlight selected row or card
                        const parentRow = this.closest('.patient-row');
                        const parentCard = this.closest('.patient-card');

                        if (parentRow) {
                            parentRow.classList.add('selected-patient');
                        } else if (parentCard) {
                            parentCard.classList.add('selected');
                        }

                        // Enable both forms
                        if (referralForm) referralForm.classList.add('enabled');
                        if (vitalsForm) vitalsForm.classList.add('enabled');
                        if (submitBtn) submitBtn.disabled = false;
                        if (saveVitalsBtn) saveVitalsBtn.disabled = false;

                        // Set patient IDs for both forms
                        referralPatientId.value = this.value;
                        vitalsPatientId.value = this.value;

                        // Set patient names for both forms
                        referralPatientName.textContent = this.dataset.patientName;
                        vitalsPatientName.textContent = this.dataset.patientName;

                        // Show patient info for both forms
                        referralPatientInfo.style.display = 'block';
                        vitalsPatientInfo.style.display = 'block';

                        // Fetch patient facilities and latest vitals
                        fetchPatientFacilities(this.value);
                        fetchAndPopulateLatestVitals(this.value);
                    } else {
                        // Disable both forms if no patient selected
                        if (referralForm) referralForm.classList.remove('enabled');
                        if (vitalsForm) vitalsForm.classList.remove('enabled');
                        if (submitBtn) submitBtn.disabled = true;
                        if (saveVitalsBtn) saveVitalsBtn.disabled = true;
                        if (referralPatientId) referralPatientId.value = '';
                        if (vitalsPatientId) vitalsPatientId.value = '';
                        if (referralPatientInfo) referralPatientInfo.style.display = 'none';
                        if (vitalsPatientInfo) vitalsPatientInfo.style.display = 'none';

                        // Remove selection from both desktop and mobile
                        const parentRow = this.closest('.patient-row');
                        const parentCard = this.closest('.patient-card');

                        if (parentRow) {
                            parentRow.classList.remove('selected-patient');
                        } else if (parentCard) {
                            parentCard.classList.remove('selected');
                        }

                        // Clear facility information and vitals
                        currentPatientFacilities = null;
                        hideAllConditionalFields();
                        clearVitalsForm();
                        hideLastVitalsInfo();
                        hideNoVitalsInfo();
                        hideAutoSelectedInfo();

                        // Reset district-specific options (show all district office options)
                        resetDistrictSpecificOptions();

                        // Clear all form inputs
                        document.getElementById('destination_type').value = '';
                        document.getElementById('barangay_facility_display').value = '';
                        document.getElementById('district_office_display').value = '';
                        document.getElementById('external_facility_type').value = '';
                        document.getElementById('hospital_name').value = '';
                        document.getElementById('other_facility_name').value = '';
                    }
                });
            });

            // Intelligent Destination Selection Logic
            const destinationType = document.getElementById('destination_type');
            const barangayFacilityField = document.getElementById('barangayFacilityField');
            const districtOfficeField = document.getElementById('districtOfficeField');
            const cityOfficeField = document.getElementById('cityOfficeField');
            const externalFacilityTypeField = document.getElementById('externalFacilityTypeField');
            const hospitalSelectionField = document.getElementById('hospitalSelectionField');
            const otherFacilityField = document.getElementById('otherFacilityField');

            const externalFacilityType = document.getElementById('external_facility_type');
            const hospitalName = document.getElementById('hospital_name');
            const otherFacilityName = document.getElementById('other_facility_name');

            let currentPatientFacilities = null;

            // Hide all conditional fields initially
            function hideAllConditionalFields() {
                const fields = [barangayFacilityField, districtOfficeField, cityOfficeField,
                    externalFacilityTypeField, hospitalSelectionField, otherFacilityField
                ];
                fields.forEach(field => {
                    if (field) field.classList.remove('show');
                });

                // Hide appointment sections
                const appointmentSection = document.getElementById('appointmentSection');
                const simpleScheduleSection = document.getElementById('simpleScheduleSection');
                if (appointmentSection) appointmentSection.style.display = 'none';
                if (simpleScheduleSection) simpleScheduleSection.style.display = 'none';

                // Clear required attributes
                if (externalFacilityType) externalFacilityType.required = false;
                if (hospitalName) hospitalName.required = false;
                if (otherFacilityName) otherFacilityName.required = false;

                // Clear appointment required attributes
                const appointmentFields = ['assigned_doctor_id', 'appointment_date', 'schedule_slot_id', 'simple_scheduled_date', 'simple_scheduled_time'];
                appointmentFields.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) field.required = false;
                });
            }

            // Handle destination type change
            if (destinationType) {
                destinationType.addEventListener('change', function() {
                    hideAllConditionalFields();

                    const selectedPatientId = document.getElementById('referralPatientId') ? document.getElementById('referralPatientId').value : null;

                    switch (this.value) {
                        case 'district_office':
                            if (selectedPatientId && currentPatientFacilities) {
                                populateDistrictOffice();
                            }
                            if (districtOfficeField) districtOfficeField.classList.add('show');

                            // Show simple scheduling for non-city office destinations
                            showSimpleScheduling();

                            filterServicesByDestination('district_office');
                            break;

                        case 'city_office':
                            // Set city office ID (facility_id = 1)
                            const referredToFacilityId = document.getElementById('referredToFacilityId');
                            if (referredToFacilityId) referredToFacilityId.value = '1';
                            if (cityOfficeField) cityOfficeField.classList.add('show');

                            // Show appointment section for doctor assignment
                            const appointmentSection = document.getElementById('appointmentSection');
                            const simpleScheduleSection = document.getElementById('simpleScheduleSection');
                            if (appointmentSection) {
                                appointmentSection.style.display = 'block';
                                // Make required fields required
                                document.getElementById('assigned_doctor_id').required = true;
                                document.getElementById('appointment_date').required = true;
                                document.getElementById('schedule_slot_id').required = true;
                            }
                            if (simpleScheduleSection) {
                                simpleScheduleSection.style.display = 'none';
                                // Remove required from simple scheduling fields
                                document.getElementById('simple_scheduled_date').required = false;
                                document.getElementById('simple_scheduled_time').required = false;
                            }

                            filterServicesByDestination('city_office');
                            break;

                        case 'external':
                            // External facilities don't use referred_to_facility_id
                            const referredToFacilityIdExt = document.getElementById('referredToFacilityId');
                            if (referredToFacilityIdExt) referredToFacilityIdExt.value = '';
                            if (externalFacilityTypeField) {
                                externalFacilityTypeField.classList.add('show');
                                if (externalFacilityType) externalFacilityType.required = true;
                            }

                            // Hide appointment section for external facilities - no doctor assignment needed
                            const appointmentSectionExt = document.getElementById('appointmentSection');
                            const simpleScheduleSectionExt = document.getElementById('simpleScheduleSection');
                            if (appointmentSectionExt) {
                                appointmentSectionExt.style.display = 'none';
                                // Remove required from appointment fields
                                document.getElementById('assigned_doctor_id').required = false;
                                document.getElementById('appointment_date').required = false;
                                document.getElementById('schedule_slot_id').required = false;
                            }
                            if (simpleScheduleSectionExt) {
                                simpleScheduleSectionExt.style.display = 'none';
                                // Remove required from simple scheduling fields
                                document.getElementById('simple_scheduled_date').required = false;
                                document.getElementById('simple_scheduled_time').required = false;
                            }

                            filterServicesByDestination('external');
                            break;
                    }
                });
            }

            // Handle external facility type change
            if (externalFacilityType) {
                externalFacilityType.addEventListener('change', function() {
                    // Hide hospital and other facility fields first
                    if (hospitalSelectionField) hospitalSelectionField.classList.remove('show');
                    if (otherFacilityField) otherFacilityField.classList.remove('show');

                    if (hospitalName) hospitalName.required = false;
                    if (otherFacilityName) otherFacilityName.required = false;

                    if (this.value === 'hospital') {
                        if (hospitalSelectionField) {
                            hospitalSelectionField.classList.add('show');
                            if (hospitalName) hospitalName.required = true;
                        }
                    } else if (this.value === 'other') {
                        if (otherFacilityField) {
                            otherFacilityField.classList.add('show');
                            if (otherFacilityName) otherFacilityName.required = true;
                        }
                    }
                });
            }

            // Function to populate district office
            function populateDistrictOffice() {
                const districtDisplay = document.getElementById('district_office_display');
                const referredToFacilityId = document.getElementById('referredToFacilityId');

                if (currentPatientFacilities && currentPatientFacilities.facilities.district_office) {
                    const facility = currentPatientFacilities.facilities.district_office;
                    if (districtDisplay) districtDisplay.value = facility.name;
                    if (referredToFacilityId) referredToFacilityId.value = facility.facility_id;
                } else {
                    if (districtDisplay) districtDisplay.value = 'No district office found';
                    if (referredToFacilityId) referredToFacilityId.value = '';
                }
            }

            // Function to filter services based on destination type
            function filterServicesByDestination(destinationType) {
                const serviceSelect = document.getElementById('service_id');
                const othersOption = document.getElementById('othersServiceOption');
                const serviceNote = document.getElementById('serviceAvailabilityNote');
                const customServiceField = document.getElementById('customServiceField');

                if (!serviceSelect) return;

                // Get all service options (excluding the "others" option)
                const allOptions = Array.from(serviceSelect.options).filter(opt => opt.value !== 'others' && opt.value !== '');

                // Define primary care services (you may need to adjust these based on your services table)
                const primaryCareServices = [
                    'consultation', 'primary care', 'general consultation', 'basic health services',
                    'immunization', 'family planning', 'maternal health', 'child health',
                    'health education', 'preventive care', 'basic medical care'
                ];

                // Clear current selection if it will be filtered out
                const currentValue = serviceSelect.value;

                switch (destinationType) {
                    case 'district_office':
                        // District Office: Only primary care services
                        showPrimaryCareServicesOnly(serviceSelect, allOptions, primaryCareServices);
                        hideOthersOption(othersOption, customServiceField);
                        if (serviceNote) serviceNote.textContent = 'District Health Office: Primary care services only';
                        break;

                    case 'city_office':
                        // City Office: All services except "others"
                        showAllServices(serviceSelect, allOptions);
                        hideOthersOption(othersOption, customServiceField);
                        if (serviceNote) serviceNote.textContent = 'City Health Office: All services available';
                        break;

                    case 'external':
                        // External: All services including "others"
                        showAllServices(serviceSelect, allOptions);
                        showOthersOption(othersOption);
                        if (serviceNote) serviceNote.textContent = 'External Facility: All services available, including custom services';
                        break;

                    default:
                        // Default: Show all services except "others" (for internal facilities)
                        showAllServices(serviceSelect, allOptions);
                        hideOthersOption(othersOption, customServiceField);
                        if (serviceNote) serviceNote.textContent = 'Note: Service availability may vary by destination facility';
                        break;
                }

                // If current selection is no longer available, clear it
                if (currentValue && currentValue !== '' && !Array.from(serviceSelect.options).some(opt => opt.value === currentValue)) {
                    serviceSelect.value = '';
                }
            }

            // Helper functions for service filtering
            function showPrimaryCareServicesOnly(serviceSelect, allOptions, primaryCareServices) {
                // Hide all non-primary care options
                allOptions.forEach(option => {
                    const serviceName = option.textContent.toLowerCase();
                    const isPrimaryCare = primaryCareServices.some(pc => serviceName.includes(pc));
                    option.style.display = isPrimaryCare ? 'block' : 'none';
                });
            }

            function showAllServices(serviceSelect, allOptions) {
                // Show all service options
                allOptions.forEach(option => {
                    option.style.display = 'block';
                });
            }

            function showOthersOption(othersOption) {
                if (othersOption) {
                    othersOption.style.display = 'block';
                    othersOption.disabled = false;
                }
            }

            function hideOthersOption(othersOption, customServiceField) {
                if (othersOption) {
                    othersOption.style.display = 'none';
                    othersOption.disabled = true;
                }
                if (customServiceField) customServiceField.classList.remove('show');

                // Clear custom service input if it was selected
                const serviceSelect = document.getElementById('service_id');
                if (serviceSelect && serviceSelect.value === 'others') {
                    serviceSelect.value = '';
                }

                const customServiceInput = document.getElementById('custom_service_name');
                if (customServiceInput) {
                    customServiceInput.value = '';
                    customServiceInput.required = false;
                }
            }

            // Function to fetch patient facility information
            function fetchPatientFacilities(patientId) {
                if (!patientId) return;

                // Show loading indicator
                showLoadingIndicator();

                fetch(`get_patient_facilities.php?patient_id=${patientId}`)
                    .then(response => response.json())
                    .then(data => {
                        hideLoadingIndicator();

                        if (data.success) {
                            currentPatientFacilities = data;

                            // Handle district-specific destination options
                            handleDistrictSpecificOptions(data.patient.district_id);

                            // Update destination type selection based on current selection
                            const currentDestinationType = destinationType.value;
                            if (currentDestinationType === 'district_office') {
                                populateDistrictOffice();
                            }

                            // Add patient barangay info to selected patient display
                            const barangayInfo = document.createElement('small');
                            barangayInfo.style.display = 'block';
                            barangayInfo.style.color = '#666';
                            barangayInfo.innerHTML = `<i class="fas fa-map-marker-alt"></i> Barangay: ${data.patient.barangay_name}`;
                            referralPatientInfo.appendChild(barangayInfo);

                        } else {
                            console.error('Error fetching patient facilities:', data.error);
                            alert('Error loading patient facility information: ' + data.error);
                        }
                    })
                    .catch(error => {
                        hideLoadingIndicator();
                        console.error('Network error:', error);
                        alert('Network error while loading patient information.');
                    });
            }

            // Function to handle district-specific destination options
            function handleDistrictSpecificOptions(districtId) {
                const districtOfficeOptions = [
                    document.getElementById('districtOfficeOption'),
                    document.getElementById('districtOfficeOptionAdmin'),
                    document.getElementById('districtOfficeOptionOther')
                ];

                // Hide/show district office option based on patient's district
                districtOfficeOptions.forEach(option => {
                    if (option) {
                        if (districtId == 1) {
                            // For Main District (district_id=1), hide District Health Office option
                            // since it's the same as City Health Office
                            option.style.display = 'none';
                            option.disabled = true;
                        } else {
                            // For other districts, show District Health Office option
                            option.style.display = 'block';
                            option.disabled = false;
                        }
                    }
                });

                // If current selection is district_office but patient is from Main District,
                // automatically switch to city_office
                const destinationSelect = document.getElementById('destination_type');
                if (destinationSelect && destinationSelect.value === 'district_office' && districtId == 1) {
                    destinationSelect.value = 'city_office';
                    // Trigger change event to update the UI
                    const event = new Event('change', {
                        bubbles: true
                    });
                    destinationSelect.dispatchEvent(event);
                }
            }

            // Function to reset district-specific options (show all options)
            function resetDistrictSpecificOptions() {
                const districtOfficeOptions = [
                    document.getElementById('districtOfficeOption'),
                    document.getElementById('districtOfficeOptionAdmin'),
                    document.getElementById('districtOfficeOptionOther')
                ];

                // Show all district office options when no patient is selected
                districtOfficeOptions.forEach(option => {
                    if (option) {
                        option.style.display = 'block';
                        option.disabled = false;
                    }
                });
            }

            // Function to fetch and populate latest vitals for a patient
            function fetchAndPopulateLatestVitals(patientId) {
                console.log('Fetching vitals for patient:', patientId);
                if (!patientId) return;

                fetch(`get_latest_vitals.php?patient_id=${patientId}`)
                    .then(response => response.json())
                    .then(data => {
                        console.log('Vitals response:', data);
                        if (data.success && data.vitals) {
                            console.log('Populating vitals form with:', data.vitals);
                            populateVitalsForm(data.vitals);
                            showLastVitalsInfo(data.vitals);
                            showCurrentVitalsInfo(data.vitals);
                        } else {
                            console.log('No vitals found or error:', data.message || data.error);
                            clearVitalsForm();
                            hideLastVitalsInfo();
                            showNoVitalsInfo();
                            hideCurrentVitalsInfo();
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching vitals:', error);
                        clearVitalsForm();
                        hideLastVitalsInfo();
                        showNoVitalsInfo();
                        hideCurrentVitalsInfo();
                    });
            }

            // Function to populate vitals form with latest data
            function populateVitalsForm(vitals) {
                console.log('populateVitalsForm called with:', vitals);
                document.getElementById('systolic_bp').value = vitals.systolic_bp || '';
                document.getElementById('diastolic_bp').value = vitals.diastolic_bp || '';
                document.getElementById('heart_rate').value = vitals.heart_rate || '';
                document.getElementById('respiratory_rate').value = vitals.respiratory_rate || '';
                document.getElementById('temperature').value = vitals.temperature || '';
                document.getElementById('weight').value = vitals.weight || '';
                document.getElementById('height').value = vitals.height || '';
                document.getElementById('vitals_remarks').value = vitals.remarks || '';
                console.log('Vitals form populated successfully');
            }

            // Function to clear vitals form
            function clearVitalsForm() {
                document.getElementById('systolic_bp').value = '';
                document.getElementById('diastolic_bp').value = '';
                document.getElementById('heart_rate').value = '';
                document.getElementById('respiratory_rate').value = '';
                document.getElementById('temperature').value = '';
                document.getElementById('weight').value = '';
                document.getElementById('height').value = '';
                document.getElementById('vitals_remarks').value = '';
            }

            // Function to show last vitals info
            function showLastVitalsInfo(vitals) {
                console.log('showLastVitalsInfo called with:', vitals);
                const lastVitalsInfo = document.getElementById('lastVitalsInfo');
                const lastVitalsText = document.getElementById('lastVitalsText');

                console.log('lastVitalsInfo element:', lastVitalsInfo);
                console.log('lastVitalsText element:', lastVitalsText);

                if (vitals && vitals.recorded_at) {
                    const recordedDate = new Date(vitals.recorded_at).toLocaleDateString();
                    const recordedBy = vitals.recorded_by_name || 'Unknown';
                    const message = `Last vitals recorded on ${recordedDate} by ${recordedBy}`;
                    console.log('Setting last vitals message:', message);
                    if (lastVitalsText) lastVitalsText.textContent = message;
                    if (lastVitalsInfo) lastVitalsInfo.style.display = 'block';
                    hideNoVitalsInfo(); // Hide no vitals notice when vitals exist
                } else {
                    console.log('No vitals to show, hiding info');
                    if (lastVitalsInfo) lastVitalsInfo.style.display = 'none';
                }
            }

            // Function to hide last vitals info
            function hideLastVitalsInfo() {
                const lastVitalsInfo = document.getElementById('lastVitalsInfo');
                if (lastVitalsInfo) lastVitalsInfo.style.display = 'none';
            }

            // Function to show no vitals info
            function showNoVitalsInfo() {
                const noVitalsInfo = document.getElementById('noVitalsInfo');
                if (noVitalsInfo) noVitalsInfo.style.display = 'block';
                hideLastVitalsInfo(); // Hide last vitals info when showing no vitals notice
            }

            // Function to hide no vitals info
            function hideNoVitalsInfo() {
                const noVitalsInfo = document.getElementById('noVitalsInfo');
                if (noVitalsInfo) noVitalsInfo.style.display = 'none';
            }

            // Function to show auto-selected info
            function showAutoSelectedInfo() {
                const autoSelectedInfo = document.getElementById('autoSelectedInfo');
                if (autoSelectedInfo) {
                    autoSelectedInfo.style.display = 'block';
                    // Auto-hide after 5 seconds
                    setTimeout(() => {
                        autoSelectedInfo.style.display = 'none';
                    }, 5000);
                }
                hideLastVitalsInfo();
                hideNoVitalsInfo();
            }

            // Function to hide auto-selected info
            function hideAutoSelectedInfo() {
                const autoSelectedInfo = document.getElementById('autoSelectedInfo');
                if (autoSelectedInfo) autoSelectedInfo.style.display = 'none';
            }

            // Function to show current vitals info in referral form
            function showCurrentVitalsInfo(vitals) {
                const currentVitalsInfo = document.getElementById('currentVitalsInfo');
                const currentVitalsText = document.getElementById('currentVitalsText');

                if (vitals && vitals.recorded_at) {
                    const recordedDate = new Date(vitals.recorded_at).toLocaleDateString();
                    currentVitalsText.textContent = `Latest vitals available from ${recordedDate} (will be attached to referral)`;
                    currentVitalsInfo.style.display = 'block';
                } else {
                    currentVitalsText.textContent = 'No vitals recorded for this patient';
                    currentVitalsInfo.style.display = 'block';
                }
            }

            // Function to hide current vitals info
            function hideCurrentVitalsInfo() {
                document.getElementById('currentVitalsInfo').style.display = 'none';
            }

            // Loading indicator functions
            function showLoadingIndicator() {
                // Create or show loading indicator
                let loader = document.getElementById('facilityLoader');
                if (!loader) {
                    loader = document.createElement('div');
                    loader.id = 'facilityLoader';
                    loader.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading facility information...';
                    loader.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #0077b6; color: white; padding: 10px 15px; border-radius: 5px; z-index: 9999; font-size: 14px;';
                    document.body.appendChild(loader);
                }
                loader.style.display = 'block';
            }

            function hideLoadingIndicator() {
                const loader = document.getElementById('facilityLoader');
                if (loader) loader.style.display = 'none';
            }

            // Function to show referral confirmation modal
            function showReferralConfirmation() {
                return new Promise((resolve) => {
                    populateReferralModal();
                    showReferralModal();

                    // Store resolve function globally so modal buttons can access it
                    window.currentReferralResolve = resolve;
                });
            }

            // Function to populate modal with form data
            function populateReferralModal() {
                // Patient information
                const patientName = document.getElementById('referralPatientName').textContent;
                const selectedCheckbox = document.querySelector('.patient-checkbox:checked');
                let patientId = '-';

                if (selectedCheckbox) {
                    // Try to get patient ID from table row
                    const tableRow = selectedCheckbox.closest('tr');
                    if (tableRow) {
                        const idCell = tableRow.querySelector('td:nth-child(2)');
                        patientId = idCell ? idCell.textContent : '-';
                    } else {
                        // Try to get patient ID from mobile card
                        const card = selectedCheckbox.closest('.patient-card');
                        if (card) {
                            const idElement = card.querySelector('.patient-card-id');
                            patientId = idElement ? idElement.textContent : '-';
                        }
                    }
                }

                document.getElementById('modalPatientName').textContent = patientName || '-';
                document.getElementById('modalPatientId').textContent = patientId || '-';

                // Referral details
                const referringFrom = <?= json_encode($employee_facility_name ?: "Unknown Facility") ?>;
                document.getElementById('modalReferringFrom').textContent = referringFrom;

                // Destination
                const destinationType = document.getElementById('destination_type').value;
                let destinationName = '';

                switch (destinationType) {
                    case 'district_office':
                        const districtDisplay = document.getElementById('district_office_display');
                        destinationName = districtDisplay ? districtDisplay.value : 'District Health Office';
                        break;
                    case 'city_office':
                        destinationName = 'City Health Office (Main District)';
                        break;
                    case 'external':
                        const externalType = document.getElementById('external_facility_type').value;
                        if (externalType === 'hospital') {
                            const hospitalSelect = document.getElementById('hospital_name');
                            destinationName = hospitalSelect ? hospitalSelect.value : 'External Hospital';
                        } else if (externalType === 'other') {
                            const otherFacility = document.getElementById('other_facility_name');
                            destinationName = otherFacility ? otherFacility.value : 'Other External Facility';
                        }
                        break;
                    default:
                        destinationName = 'Not specified';
                }

                document.getElementById('modalDestination').textContent = destinationName;

                // Service (if selected)
                const serviceSelect = document.getElementById('service_id');
                const serviceContainer = document.getElementById('modalServiceContainer');

                if (serviceSelect && serviceSelect.value) {
                    let serviceName = '';
                    if (serviceSelect.value === 'others') {
                        const customServiceName = document.getElementById('custom_service_name').value;
                        serviceName = customServiceName + ' (Custom Service)';
                    } else {
                        const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
                        serviceName = selectedOption.textContent;
                    }
                    document.getElementById('modalService').textContent = serviceName;
                    serviceContainer.style.display = 'block';
                } else {
                    serviceContainer.style.display = 'none';
                }

                // Referral reason
                const referralReason = document.getElementById('referral_reason').value;
                document.getElementById('modalReason').textContent = referralReason || 'No reason specified';

                // Appointment Details (for City Health Office)
                populateAppointmentModal(destinationType);

                // Vitals (if provided)
                populateVitalsModal();
            }

            // Function to populate vitals in modal
            function populateVitalsModal() {
                const vitalsCard = document.getElementById('modalVitalsCard');
                const vitalsGrid = document.getElementById('modalVitalsGrid');
                const vitalsRemarksContainer = document.getElementById('modalVitalsRemarksContainer');

                // Clear previous vitals
                vitalsGrid.innerHTML = '';

                const vitalsFields = [{
                        id: 'systolic_bp',
                        label: 'Systolic BP',
                        unit: 'mmHg'
                    },
                    {
                        id: 'diastolic_bp',
                        label: 'Diastolic BP',
                        unit: 'mmHg'
                    },
                    {
                        id: 'heart_rate',
                        label: 'Heart Rate',
                        unit: 'bpm'
                    },
                    {
                        id: 'respiratory_rate',
                        label: 'Respiratory Rate',
                        unit: '/min'
                    },
                    {
                        id: 'temperature',
                        label: 'Temperature',
                        unit: '°C'
                    },
                    {
                        id: 'weight',
                        label: 'Weight',
                        unit: 'kg'
                    },
                    {
                        id: 'height',
                        label: 'Height',
                        unit: 'cm'
                    }
                ];

                let hasVitals = false;

                vitalsFields.forEach(field => {
                    const input = document.getElementById(field.id);
                    if (input && input.value) {
                        hasVitals = true;
                        const vitalItem = document.createElement('div');
                        vitalItem.className = 'vital-item';
                        vitalItem.innerHTML = `
                            <div class="vital-value">${input.value}</div>
                            <div class="vital-label">${field.label} (${field.unit})</div>
                        `;
                        vitalsGrid.appendChild(vitalItem);
                    }
                });

                // Check vitals remarks
                const vitalsRemarks = document.getElementById('vitals_remarks');
                if (vitalsRemarks && vitalsRemarks.value.trim()) {
                    document.getElementById('modalVitalsRemarks').textContent = vitalsRemarks.value;
                    vitalsRemarksContainer.style.display = 'block';
                    hasVitals = true;
                } else {
                    vitalsRemarksContainer.style.display = 'none';
                }

                // Show/hide vitals card based on whether we have any vitals data
                vitalsCard.style.display = hasVitals ? 'block' : 'none';
            }

            // Function to populate appointment details in modal
            function populateAppointmentModal(destinationType) {
                const appointmentCard = document.getElementById('modalAppointmentCard');

                if (destinationType === 'city_office') {
                    // Get appointment details for City Health Office referrals
                    const doctorSelect = document.getElementById('assigned_doctor_id');
                    const appointmentDate = document.getElementById('appointment_date');
                    const timeSlotSelect = document.getElementById('schedule_slot_id');

                    // Populate doctor name
                    let doctorName = '-';
                    if (doctorSelect && doctorSelect.value) {
                        const selectedOption = doctorSelect.options[doctorSelect.selectedIndex];
                        // Use data-doctor-name attribute to get clean doctor name without slot count
                        doctorName = selectedOption.getAttribute('data-doctor-name') || selectedOption.textContent;
                    }
                    document.getElementById('modalAssignedDoctor').textContent = doctorName;

                    // Populate appointment date
                    let formattedDate = '-';
                    if (appointmentDate && appointmentDate.value) {
                        const dateObj = new Date(appointmentDate.value);
                        formattedDate = dateObj.toLocaleDateString('en-US', {
                            weekday: 'long',
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        });
                    }
                    document.getElementById('modalAppointmentDate').textContent = formattedDate;

                    // Populate appointment time
                    let appointmentTime = '-';
                    if (timeSlotSelect && timeSlotSelect.value) {
                        const selectedOption = timeSlotSelect.options[timeSlotSelect.selectedIndex];
                        appointmentTime = selectedOption.textContent;
                    }
                    document.getElementById('modalAppointmentTime').textContent = appointmentTime;

                    // Show appointment card
                    appointmentCard.style.display = 'block';
                } else {
                    // Hide appointment card for non-city office referrals
                    appointmentCard.style.display = 'none';
                }
            }

            // Function to show modal
            function showReferralModal() {
                const modal = document.getElementById('referralConfirmationModal');
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }

            // Function to close modal
            window.closeReferralModal = function() {
                const modal = document.getElementById('referralConfirmationModal');
                modal.classList.remove('show');
                document.body.style.overflow = '';

                // Reset confirm button state if needed
                const confirmBtn = document.getElementById('modalConfirmBtn');
                if (confirmBtn && confirmBtn.disabled) {
                    confirmBtn.disabled = false;
                    confirmBtn.innerHTML = '<i class="fas fa-check"></i> Create Referral';
                }

                // Resolve with false (user cancelled)
                if (window.currentReferralResolve) {
                    window.currentReferralResolve(false);
                    window.currentReferralResolve = null;
                }
            };

            // Modal confirm button handler (moved outside DOMContentLoaded to avoid nesting)
            function setupModalHandlers() {
                const confirmBtn = document.getElementById('modalConfirmBtn');
                console.log('Setting up modal handlers, confirmBtn:', confirmBtn);
                if (confirmBtn) {
                    confirmBtn.addEventListener('click', function() {
                        // Add loading state to button
                        const originalContent = confirmBtn.innerHTML;
                        confirmBtn.disabled = true;
                        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Referral...';

                        // Immediately resolve the promise to proceed with form submission
                        if (window.currentReferralResolve) {
                            window.currentReferralResolve(true);
                            window.currentReferralResolve = null;
                        }

                        // Close modal with a short delay for visual feedback
                        setTimeout(() => {
                            closeReferralModal();
                        }, 200);

                        // Reset button state in case of error (longer timeout)
                        setTimeout(() => {
                            confirmBtn.disabled = false;
                            confirmBtn.innerHTML = originalContent;
                        }, 2000);
                    });
                }

                // Close modal when clicking outside
                const modal = document.getElementById('referralConfirmationModal');
                if (modal) {
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            closeReferralModal();
                        }
                    });
                }

                // Close modal on ESC key
                document.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape' && modal && modal.classList.contains('show')) {
                        closeReferralModal();
                    }
                });
            }

            // Form validation
            if (referralForm) {
                referralForm.addEventListener('submit', function(e) {
                    e.preventDefault(); // Always prevent default submission initially

                    const patientId = document.getElementById('referralPatientId').value;
                    if (!patientId) {
                        alert('Please select a patient from the search results above.');
                        return false;
                    }

                    const destinationType = document.getElementById('destination_type').value;

                    // Validate based on destination type
                    const referredToFacilityId = document.getElementById('referredToFacilityId').value;

                    if (destinationType === 'district_office') {
                        if (!referredToFacilityId) {
                            e.preventDefault();
                            alert('No district health office available for this patient. Please select a different destination type.');
                            return false;
                        }
                    } else if (destinationType === 'city_office') {
                        if (!referredToFacilityId || referredToFacilityId !== '1') {
                            e.preventDefault();
                            alert('City Health Office facility ID not set properly. Please try again.');
                            return false;
                        }
                    } else if (destinationType === 'external') {
                        const externalType = document.getElementById('external_facility_type').value;

                        if (!externalType) {
                            e.preventDefault();
                            alert('Please select external facility type.');
                            return false;
                        }

                        if (externalType === 'hospital') {
                            const hospitalName = document.getElementById('hospital_name').value;
                            if (!hospitalName) {
                                e.preventDefault();
                                alert('Please select a hospital.');
                                return false;
                            }
                        } else if (externalType === 'other') {
                            const otherFacility = document.getElementById('other_facility_name').value;
                            if (!otherFacility.trim()) {
                                e.preventDefault();
                                alert('Please specify the other facility name.');
                                return false;
                            }
                            if (otherFacility.trim().length < 3) {
                                e.preventDefault();
                                alert('Other facility name must be at least 3 characters.');
                                return false;
                            }
                        }
                    } else if (!destinationType) {
                        e.preventDefault();
                        alert('Please select a referral destination type.');
                        return false;
                    }

                    const referralReason = document.getElementById('referral_reason').value;
                    if (!referralReason.trim()) {
                        e.preventDefault();
                        alert('Please enter the reason for referral.');
                        return false;
                    }

                    // Validate custom service if "Others" is selected
                    const serviceId = document.getElementById('service_id').value;
                    if (serviceId === 'others') {
                        const customServiceName = document.getElementById('custom_service_name').value;
                        if (!customServiceName.trim()) {
                            e.preventDefault();
                            alert('Please specify the other service name.');
                            return false;
                        }
                        if (customServiceName.trim().length < 3) {
                            e.preventDefault();
                            alert('Other service name must be at least 3 characters.');
                            return false;
                        }
                    }

                    // Show confirmation modal with referral details
                    e.preventDefault();
                    showReferralConfirmation().then(confirmed => {
                        if (confirmed) {
                            // User confirmed, submit the form
                            referralForm.submit();
                        }
                        // If not confirmed, do nothing (form won't submit)
                    });
                    return false;
                });
            }

            // Handle service selection change (for "Others" option)
            const serviceSelect = document.getElementById('service_id');
            const customServiceField = document.getElementById('customServiceField');
            const customServiceInput = document.getElementById('custom_service_name');

            if (serviceSelect && customServiceField && customServiceInput) {
                serviceSelect.addEventListener('change', function() {
                    if (this.value === 'others') {
                        customServiceField.classList.add('show');
                        customServiceInput.required = true;
                    } else {
                        customServiceField.classList.remove('show');
                        customServiceInput.required = false;
                        customServiceInput.value = '';
                    }
                });
            }

            // Form validation for vitals form
            if (vitalsForm) {
                vitalsForm.addEventListener('submit', function(e) {
                    const patientId = document.getElementById('vitalsPatientId').value;
                    if (!patientId) {
                        e.preventDefault();
                        alert('Please select a patient first.');
                        return false;
                    }

                    // Check if at least one field is filled
                    const vitalsFields = ['systolic_bp', 'diastolic_bp', 'heart_rate', 'respiratory_rate', 'temperature', 'weight', 'height'];
                    const vitalsRemarks = document.getElementById('vitals_remarks').value.trim();

                    const hasVitalData = vitalsFields.some(field => {
                        const value = document.getElementById(field).value;
                        return value && value.trim() !== '';
                    }) || vitalsRemarks !== '';

                    if (!hasVitalData) {
                        e.preventDefault();
                        alert('Please provide at least one vital sign measurement or remark.');
                        return false;
                    }

                    // Allow form submission
                    return true;
                });
            }

            // Note: Service filtering is handled through destination type selection
            // No additional facility-based service filtering needed here

            // Setup modal handlers
            setupModalHandlers();

            // Auto-select patient if specified (after vitals save)
            const keepPatientSelected = <?= json_encode($keep_patient_selected) ?>;
            console.log('Keep patient selected:', keepPatientSelected);
            if (keepPatientSelected) {
                // Add a small delay to ensure DOM is fully rendered
                setTimeout(() => {
                    // Find and check the corresponding patient checkbox
                    const patientCheckbox = document.querySelector('.patient-checkbox[value="' + keepPatientSelected + '"]');
                    console.log('Found patient checkbox:', patientCheckbox);
                    if (patientCheckbox) {
                        patientCheckbox.checked = true;
                        const event = new Event('change', {
                            bubbles: true
                        });
                        patientCheckbox.dispatchEvent(event);
                        console.log('Auto-selected patient and triggered change event');

                        // Show auto-selected info notice
                        setTimeout(() => {
                            showAutoSelectedInfo();
                        }, 500); // Small delay to let vitals load first

                        // Scroll to the patient to make it visible
                        const parentRow = patientCheckbox.closest('.patient-row');
                        const parentCard = patientCheckbox.closest('.patient-card');
                        if (parentRow) {
                            parentRow.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center'
                            });
                        } else if (parentCard) {
                            parentCard.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center'
                            });
                        }
                    } else {
                        console.error('Could not find patient checkbox for ID:', keepPatientSelected);
                    }
                }, 100);
            }

            // Function to show simple scheduling section for non-city office destinations
            function showSimpleScheduling() {
                const appointmentSection = document.getElementById('appointmentSection');
                const simpleScheduleSection = document.getElementById('simpleScheduleSection');

                if (appointmentSection) appointmentSection.style.display = 'none';
                if (simpleScheduleSection) {
                    simpleScheduleSection.style.display = 'block';
                    // Make simple scheduling fields required
                    document.getElementById('simple_scheduled_date').required = true;
                    document.getElementById('simple_scheduled_time').required = true;
                }

                // Remove required from appointment fields
                const appointmentFields = ['assigned_doctor_id', 'appointment_date', 'schedule_slot_id'];
                appointmentFields.forEach(fieldId => {
                    const field = document.getElementById(fieldId);
                    if (field) field.required = false;
                });
            }

            // Function to load available doctors for selected date
            function loadAvailableDoctors() {
                const appointmentDate = document.getElementById('appointment_date').value;
                const doctorSelect = document.getElementById('assigned_doctor_id');

                if (!appointmentDate) {
                    doctorSelect.innerHTML = '<option value="">First select appointment date...</option>';
                    // Hide badge when no date selected
                    const slotsAvailableBadge = document.getElementById('slotsAvailableBadge');
                    if (slotsAvailableBadge) {
                        slotsAvailableBadge.style.display = 'none';
                    }
                    return;
                }

                // Check if selected date is weekend (Saturday = 6, Sunday = 0)
                const selectedDate = new Date(appointmentDate);
                const dayOfWeek = selectedDate.getDay();

                if (dayOfWeek === 0 || dayOfWeek === 6) { // Sunday or Saturday
                    doctorSelect.innerHTML = '<option value="">Weekend - No doctors available</option>';
                    return;
                }

                // Show loading
                doctorSelect.innerHTML = '<option value="">Loading available doctors...</option>';

                // Fetch available doctors for this date
                console.log('Fetching available doctors for date:', appointmentDate);
                fetch(`get_available_doctors.php?date=${appointmentDate}`)
                    .then(response => {
                        console.log('Response status:', response.status);
                        return response.json();
                    })
                    .then(data => {
                        console.log('Available doctors API response:', data);
                        if (data.success && data.doctors && data.doctors.length > 0) {
                            console.log('Found', data.doctors.length, 'doctors with available slots');
                            doctorSelect.innerHTML = '<option value="">Select Doctor...</option>';
                            data.doctors.forEach(doctor => {
                                const option = document.createElement('option');
                                option.value = doctor.employee_id;
                                option.setAttribute('data-doctor-name', `Dr. ${doctor.full_name}`);
                                option.setAttribute('data-slot-count', doctor.available_slots_count);
                                option.textContent = `Dr. ${doctor.full_name}`;
                                doctorSelect.appendChild(option);
                            });
                        } else {
                            console.log('No doctors with available slots found');
                            doctorSelect.innerHTML = '<option value="">No doctors with available slots for this date</option>';
                            // Hide badge when no doctors available
                            const slotsAvailableBadge = document.getElementById('slotsAvailableBadge');
                            if (slotsAvailableBadge) {
                                slotsAvailableBadge.style.display = 'none';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error loading available doctors:', error);
                        doctorSelect.innerHTML = '<option value="">Error loading doctors</option>';
                        // Hide badge on error
                        const slotsAvailableBadge = document.getElementById('slotsAvailableBadge');
                        if (slotsAvailableBadge) {
                            slotsAvailableBadge.style.display = 'none';
                        }
                    });
            }

            // Function to update the slots available badge
            function updateSlotsBadge() {
                const doctorSelect = document.getElementById('assigned_doctor_id');
                const slotsAvailableBadge = document.getElementById('slotsAvailableBadge');
                const slotCountElement = document.getElementById('slotCount');

                if (!doctorSelect.value) {
                    // No doctor selected, hide badge
                    if (slotsAvailableBadge) {
                        slotsAvailableBadge.style.display = 'none';
                    }
                    return;
                }

                const selectedOption = doctorSelect.options[doctorSelect.selectedIndex];
                const slotCount = selectedOption.getAttribute('data-slot-count');

                if (slotCount && slotCountElement && slotsAvailableBadge) {
                    slotCountElement.textContent = slotCount;
                    slotsAvailableBadge.style.display = 'block';

                    // Update badge styling based on slot availability
                    const slotsBadge = slotsAvailableBadge.querySelector('.slots-badge');
                    if (slotsBadge) {
                        slotsBadge.className = 'slots-badge'; // Reset classes
                        
                        const count = parseInt(slotCount);
                        if (count <= 2) {
                            slotsBadge.classList.add('danger');
                        } else if (count <= 5) {
                            slotsBadge.classList.add('warning');
                        }
                        // Green (default) for count > 5
                    }
                }
            }

            // Function to load available time slots for selected doctor and date
            function loadTimeSlots() {
                const doctorSelect = document.getElementById('assigned_doctor_id');
                const doctorId = doctorSelect.value;
                const appointmentDate = document.getElementById('appointment_date').value;
                const slotSelect = document.getElementById('schedule_slot_id');
                const noSlotsMessage = document.getElementById('noSlotsMessage');
                const weekendMessage = document.getElementById('weekendMessage');
                const noScheduleMessage = document.getElementById('noScheduleMessage');

                // Update slots badge
                updateSlotsBadge();

                // Hide all messages initially
                if (noSlotsMessage) noSlotsMessage.style.display = 'none';
                if (weekendMessage) weekendMessage.style.display = 'none';
                if (noScheduleMessage) noScheduleMessage.style.display = 'none';

                if (!doctorId || !appointmentDate) {
                    slotSelect.innerHTML = '<option value="">First select date and doctor...</option>';
                    return;
                }

                // Check if selected date is weekend (Saturday = 6, Sunday = 0)
                const selectedDate = new Date(appointmentDate);
                const dayOfWeek = selectedDate.getDay();

                if (dayOfWeek === 0 || dayOfWeek === 6) { // Sunday or Saturday
                    slotSelect.innerHTML = '<option value="">Weekend - No appointments available</option>';
                    if (weekendMessage) weekendMessage.style.display = 'block';
                    return;
                }

                // Show loading
                slotSelect.innerHTML = '<option value="">Loading available slots...</option>';

                // Fetch available time slots
                console.log('Fetching slots for doctor:', doctorId, 'date:', appointmentDate);
                fetch(`get_available_time_slots.php?doctor_id=${doctorId}&date=${appointmentDate}`)
                    .then(response => {
                        console.log('Response status:', response.status);
                        return response.json();
                    })
                    .then(data => {
                        console.log('API response:', data);
                        if (data.success && data.slots && data.slots.length > 0) {
                            console.log('Found', data.slots.length, 'slots');
                            slotSelect.innerHTML = '<option value="">Select time slot...</option>';
                            data.slots.forEach(slot => {
                                const option = document.createElement('option');
                                option.value = slot.slot_id;
                                option.textContent = slot.start_time; // Use start_time only since we don't have end_time
                                slotSelect.appendChild(option);
                            });
                        } else {
                            console.log('No slots available or API error:', data);
                            console.log('Debug data:', data.debug);
                            console.log('Total slots on date:', data.debug ? data.debug.total_slots_on_date : 'undefined');
                            slotSelect.innerHTML = '<option value="">No slots available</option>';

                            // Check if API was successful but returned no slots
                            if (data.success && data.slots && data.slots.length === 0) {
                                // API successful but no slots found
                                // Check if there are any slots at all for this doctor on this date
                                console.log('Checking total_slots_on_date:', data.debug ? data.debug.total_slots_on_date : 'no debug data');

                                if (data.debug && data.debug.total_slots_on_date === 0) {
                                    // No slots found for this doctor on this date (doctor has no schedule)
                                    console.log('Showing no schedule message');
                                    if (noScheduleMessage) noScheduleMessage.style.display = 'block';
                                } else if (data.debug && data.debug.total_slots_on_date > 0) {
                                    // There are slots for this date, but all are booked
                                    console.log('Showing no available slots message');
                                    if (noSlotsMessage) {
                                        document.getElementById('noSlotsText').textContent = 'Selected doctor has no available slots to book. Please choose another date or doctor.';
                                        noSlotsMessage.style.display = 'block';
                                    }
                                } else {
                                    // No debug info available, default to no schedule message
                                    console.log('No debug info, defaulting to no schedule message');
                                    if (noScheduleMessage) noScheduleMessage.style.display = 'block';
                                }
                            } else {
                                // API error or other issue
                                console.log('API error or other issue');
                                if (noSlotsMessage) {
                                    document.getElementById('noSlotsText').textContent = 'Error loading time slots. Please try again.';
                                    noSlotsMessage.style.display = 'block';
                                }
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error loading time slots:', error);
                        slotSelect.innerHTML = '<option value="">Error loading slots</option>';
                        if (noSlotsMessage) {
                            document.getElementById('noSlotsText').textContent = 'Error loading time slots. Please try again.';
                            noSlotsMessage.style.display = 'block';
                        }
                    });
            }

            // Event listeners for appointment scheduling
            const doctorSelect = document.getElementById('assigned_doctor_id');
            const dateSelect = document.getElementById('appointment_date');

            if (dateSelect) {
                dateSelect.addEventListener('change', function() {
                    // Clear doctor and time slot selections when date changes
                    const doctorSelect = document.getElementById('assigned_doctor_id');
                    const slotSelect = document.getElementById('schedule_slot_id');

                    // Load available doctors first
                    loadAvailableDoctors();

                    // Clear time slots since doctor selection will change
                    if (slotSelect) {
                        slotSelect.innerHTML = '<option value="">First select date and doctor...</option>';
                    }
                });
            }
            if (doctorSelect) {
                doctorSelect.addEventListener('change', loadTimeSlots);
            }
        });
    </script>

    <!-- Referral Confirmation Modal -->
    <div id="referralConfirmationModal" class="referral-confirmation-modal">
        <div class="referral-modal-content">
            <div class="referral-modal-header">
                <button type="button" class="referral-modal-close" onclick="closeReferralModal()">&times;</button>
                <div class="icon">
                    <i class="fas fa-share-square"></i>
                </div>
                <h3>Confirm Referral Details</h3>
                <p style="margin: 0.5rem 0 0; opacity: 0.9;">Please review the information below before creating the referral</p>
            </div>

            <div class="referral-modal-body">
                <!-- Patient Information -->
                <div class="referral-summary-card">
                    <div class="summary-section">
                        <div class="summary-title">
                            <i class="fas fa-user"></i>
                            Patient Information
                        </div>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="summary-label">Patient Name</div>
                                <div class="summary-value highlight" id="modalPatientName">-</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Patient ID</div>
                                <div class="summary-value" id="modalPatientId">-</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Referral Details -->
                <div class="referral-summary-card">
                    <div class="summary-section">
                        <div class="summary-title">
                            <i class="fas fa-hospital"></i>
                            Referral Details
                        </div>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="summary-label">Referring From</div>
                                <div class="summary-value" id="modalReferringFrom">-</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Destination</div>
                                <div class="summary-value highlight" id="modalDestination">-</div>
                            </div>
                            <div class="summary-item" id="modalServiceContainer" style="display: none;">
                                <div class="summary-label">Service Requested</div>
                                <div class="summary-value" id="modalService">-</div>
                            </div>
                        </div>
                        <div class="summary-item" style="margin-top: 1rem;">
                            <div class="summary-label">Reason for Referral</div>
                            <div class="summary-value reason" id="modalReason">-</div>
                        </div>
                    </div>
                </div>

                <!-- Appointment Details (for City Health Office referrals) -->
                <div class="referral-summary-card" id="modalAppointmentCard" style="display: none;">
                    <div class="summary-section">
                        <div class="summary-title">
                            <i class="fas fa-calendar-check"></i>
                            Appointment Details
                        </div>
                        <div class="summary-grid">
                            <div class="summary-item">
                                <div class="summary-label">Assigned Doctor</div>
                                <div class="summary-value highlight" id="modalAssignedDoctor">-</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Appointment Date</div>
                                <div class="summary-value" id="modalAppointmentDate">-</div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Appointment Time</div>
                                <div class="summary-value" id="modalAppointmentTime">-</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Patient Vitals (if provided) -->
                <div class="referral-summary-card" id="modalVitalsCard" style="display: none;">
                    <div class="summary-section">
                        <div class="summary-title">
                            <i class="fas fa-heartbeat"></i>
                            Patient Vitals
                        </div>
                        <div class="vitals-summary" id="modalVitalsGrid">
                            <!-- Vitals will be populated dynamically -->
                        </div>
                        <div class="summary-item" id="modalVitalsRemarksContainer" style="display: none; margin-top: 1rem;">
                            <div class="summary-label">Vitals Remarks</div>
                            <div class="summary-value" id="modalVitalsRemarks">-</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="referral-modal-actions">
                <button type="button" class="modal-btn modal-btn-cancel" onclick="closeReferralModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="button" class="modal-btn modal-btn-confirm" id="modalConfirmBtn">
                    <i class="fas fa-check"></i> Create Referral
                </button>
            </div>
        </div>
    </div>
</body>

</html>