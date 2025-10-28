<?php
// sidebar_doctor.php - Doctor sidebar navigation
// Expected (optional) from caller: $activePage, $defaults['name'], $defaults['employee_number'], $employee_id

if (session_status() === PHP_SESSION_NONE) {
    // Include employee session configuration
    require_once __DIR__ . '/../config/session/employee_session.php';
}

// Keep just the variable initialization
$activePage = $activePage ?? '';
$employee_id = $employee_id ?? ($_SESSION['employee_id'] ?? null);

// Initial display values from caller/session; will be refined from DB if needed.
$displayName = $defaults['name'] ?? ($_SESSION['employee_name'] ?? ($_SESSION['employee_first_name'] . ' ' . $_SESSION['employee_last_name']) ?? 'Doctor');
$employeeNo = $defaults['employee_number'] ?? ($_SESSION['employee_number'] ?? '');
$role = $_SESSION['role'] ?? 'Doctor';

// Check if doctor has station assignments for queue management access
$hasStationAssignment = false;
$assignedStationName = '';
$debug_info = []; // For debugging station assignment issues

// If we don't have good display values yet, pull from DB (only if we have an id)
$needsName = empty($displayName) || $displayName === 'Doctor';
$needsNo = empty($employeeNo);

if (($needsName || $needsNo || !isset($hasStationAssignment)) && $employee_id) {
    // Ensure $conn exists; adjust the path if your config lives elsewhere
    if (!isset($conn)) {
        require_once __DIR__ . '/../config/db.php';
    }

    if (isset($conn)) {
        // Get employee details
        $stmt = $conn->prepare("
            SELECT employee_id, first_name, middle_name, last_name, employee_number, role
            FROM employees
            WHERE employee_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if ($needsName) {
                $parts = [];
                if (!empty($row['first_name'])) {
                    $parts[] = $row['first_name'];
                }
                if (!empty($row['middle_name'])) {
                    $parts[] = $row['middle_name'];
                }
                if (!empty($row['last_name'])) {
                    $parts[] = $row['last_name'];
                }
                $full = trim(implode(' ', $parts));
                $displayName = $full ?: 'Doctor';
            }
            if ($needsNo && !empty($row['employee_number'])) {
                $employeeNo = $row['employee_number'];
            }
            if (!empty($row['role'])) {
                $role = $row['role'];
            }
        }
        $stmt->close();

        // Check for active station assignments for doctors
        // Try multiple possible table names to match your schema
        $possible_queries = [
            // Try staff_assignments first
            "SELECT s.station_name, s.station_type, sch.schedule_id
             FROM staff_assignments sch 
             JOIN stations s ON sch.station_id = s.station_id 
             WHERE sch.employee_id = ? 
             AND sch.is_active = 1
             AND (sch.start_date <= CURDATE() AND (sch.end_date IS NULL OR sch.end_date >= CURDATE()))
             AND s.station_type = 'consultation'
             ORDER BY sch.assigned_at DESC LIMIT 1",
            
            // Try assignment_schedules as fallback
            "SELECT s.station_name, s.station_type, sch.assignment_id
             FROM assignment_schedules sch 
             JOIN stations s ON sch.station_id = s.station_id 
             WHERE sch.employee_id = ? 
             AND sch.is_active = 1
             AND (sch.start_date <= CURDATE() AND (sch.end_date IS NULL OR sch.end_date >= CURDATE()))
             AND s.station_type = 'consultation'
             ORDER BY sch.assigned_at DESC LIMIT 1"
        ];
        
        foreach ($possible_queries as $index => $query) {
            $assignment_stmt = $conn->prepare($query);
            if ($assignment_stmt) {
                $assignment_stmt->bind_param("i", $employee_id);
                if ($assignment_stmt->execute()) {
                    $assignment_result = $assignment_stmt->get_result();
                    $debug_info["query_$index"] = "executed successfully";
                    if ($assignment_row = $assignment_result->fetch_assoc()) {
                        $hasStationAssignment = true;
                        $assignedStationName = $assignment_row['station_name'];
                        $debug_info["found"] = "Query $index found: " . $assignment_row['station_name'];
                        $assignment_stmt->close();
                        break; // Found assignment, stop trying other queries
                    } else {
                        $debug_info["query_$index"] .= " - no results";
                    }
                } else {
                    $debug_info["query_$index"] = "execution failed: " . $assignment_stmt->error;
                }
                $assignment_stmt->close();
            } else {
                $debug_info["query_$index"] = "prepare failed: " . $conn->error;
            }
        }
        
        // Temporary debug - remove after fixing
        if (!$hasStationAssignment && isset($_GET['debug_station'])) {
            error_log("Station Debug for Employee ID $employee_id: " . json_encode($debug_info));
        }
        
        // TEMPORARY FIX: Enable queue management for all doctors until we fix the query
        // TODO: Remove this after fixing the station assignment query
        if (!$hasStationAssignment && strtolower($role) === 'doctor') {
            // Try to get ANY station assignment for this doctor
            $fallback_stmt = $conn->prepare("
                SELECT s.station_name 
                FROM staff_assignments sa 
                JOIN stations s ON sa.station_id = s.station_id 
                WHERE sa.employee_id = ? AND sa.is_active = 1 
                ORDER BY sa.assigned_at DESC LIMIT 1
            ");
            if ($fallback_stmt) {
                $fallback_stmt->bind_param("i", $employee_id);
                $fallback_stmt->execute();
                $fallback_result = $fallback_stmt->get_result();
                if ($fallback_row = $fallback_result->fetch_assoc()) {
                    $hasStationAssignment = true;
                    $assignedStationName = $fallback_row['station_name'];
                }
                $fallback_stmt->close();
            }
            
            // If still no assignment found, enable with generic name for doctors
            if (!$hasStationAssignment) {
                $hasStationAssignment = true;
                $assignedStationName = 'Consultation Station';
            }
        }
    }
}
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<?php
// Get the proper base URL by extracting the project folder from the script name
$request_uri = $_SERVER['REQUEST_URI'];
$script_name = $_SERVER['SCRIPT_NAME'];

// Extract the base path (project folder) from the script name
// For example: /wbhsms-cho-koronadal/pages/management/doctor/dashboard.php -> /wbhsms-cho-koronadal/
if (preg_match('#^(/[^/]+)/pages/#', $script_name, $matches)) {
    $base_path = $matches[1] . '/';
} else {
    // Fallback: try to extract from REQUEST_URI - first segment should be project folder
    $uri_parts = explode('/', trim($request_uri, '/'));
    if (count($uri_parts) > 0 && $uri_parts[0] && $uri_parts[0] !== 'pages') {
        $base_path = '/' . $uri_parts[0] . '/';
    } else {
        $base_path = '/';
    }
}

// Create absolute URL for vendor path to fix photo loading
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$vendorPath = $protocol . '://' . $host . $base_path . 'vendor/photo_controller.php';
$nav_base = $base_path . 'pages/';
?>
<!-- CSS is included by the main page, not the sidebar -->

<!-- Mobile topbar -->
<div class="mobile-topbar">
    <a href="<?= $nav_base ?>management/doctor/dashboard.php">
        <img id="topbarLogo" class="logo" src="https://ik.imagekit.io/wbhsmslogo/Nav_Logo.png?updatedAt=1750422462527" alt="City Health Logo" />
    </a>
</div>
<button class="mobile-toggle" onclick="toggleNav()" aria-label="Toggle Menu">
    <i id="menuIcon" class="fas fa-bars"></i>
</button>

<!-- Sidebar -->
<nav class="nav" id="sidebarNav" aria-label="Doctor sidebar">
    <button class="close-btn" type="button" onclick="closeNav()" aria-label="Close navigation">
        <i class="fas fa-times"></i>
    </button>

    <a href="<?= $nav_base ?>management/doctor/dashboard.php">
        <img id="topbarLogo" class="logo" src="https://ik.imagekit.io/wbhsmslogo/Nav_Logo.png?updatedAt=1750422462527" alt="City Health Logo" />
    </a>

    <div class="menu" role="menu">
        <a href="<?= $nav_base ?>management/doctor/dashboard.php"
            class="<?= $activePage === 'dashboard' ? 'active' : '' ?>" role="menuitem">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="<?= $nav_base ?>management/doctor/patient_records_management.php"
            class="<?= $activePage === 'patient_records' ? 'active' : '' ?>" role="menuitem">
            <i class="fas fa-users"></i> Patient Records
        </a>
        <a href="<?= $nav_base ?>referrals/referrals_management.php"
            class="<?= $activePage === 'referrals' ? 'active' : '' ?>" role="menuitem">
            <i class="fas fa-share-square"></i> Referral Management
        </a>
        <a href="<?= $nav_base ?>clinical-encounter-management/index.php"
            class="<?= $activePage === 'clinical_encounters' ? 'active' : '' ?>" role="menuitem">
            <i class="fas fa-stethoscope"></i> Clinical Encounters
        </a>
        <a href="<?= $nav_base ?>laboratory-management/lab_management.php"
            class="<?= $activePage === 'laboratory_management' ? 'active' : '' ?>" role="menuitem">
            <i class="fas fa-flask"></i> Laboratory Management
        </a>
        <a href="<?= $nav_base ?>prescription-management/prescription_management.php"
            class="<?= $activePage === 'prescription_management' ? 'active' : '' ?>" role="menuitem">
            <i class="fas fa-prescription-bottle-alt"></i> Prescription Management
        </a>
        <?php if ($hasStationAssignment): ?>
            <a href="#"
                class="<?= $activePage === 'queueing' || $activePage === 'queue_management' ? 'active' : '' ?>" 
                role="menuitem">
                <i class="fas fa-list-ol"></i> Queue Management
                <?php if ($assignedStationName): ?>
                    <small style="display:block;font-size:11px;color:#b3d9ff;margin-top:2px;">
                        <i class="fas fa-clinic-medical" style="margin-right:3px;"></i><?= htmlspecialchars($assignedStationName) ?>
                    </small>
                <?php endif; ?>
            </a>
        <?php else: ?>
            <a href="#" onclick="showStationRequiredModal(event)" 
                class="disabled" role="menuitem" style="opacity:0.5;cursor:not-allowed;">
                <i class="fas fa-list-ol"></i> Queue Management
                <small style="display:block;font-size:11px;color:#ff6b6b;margin-top:2px;">
                    <i class="fas fa-exclamation-triangle" style="margin-right:3px;"></i>Station assignment required
                </small>
            </a>
        <?php endif; ?>
    </div>

    <a href="<?= $nav_base ?>user/doctor_profile.php"
        class="<?= $activePage === 'profile' ? 'active' : '' ?>" aria-label="View profile">
        <div class="user-profile">
            <div class="user-info">
                <img class="user-profile-photo"
                    src="<?= $employee_id
                                ? $vendorPath . '?employee_id=' . urlencode((string)$employee_id)
                                : 'https://ik.imagekit.io/wbhsmslogo/user.png?updatedAt=1750423429172' ?>"
                    alt="User photo"
                    onerror="this.onerror=null;this.src='https://ik.imagekit.io/wbhsmslogo/user.png?updatedAt=1750423429172';">
                <div class="user-text">
                    <div class="user-name">
                        <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="user-id">
                        <i class="fas fa-id-badge" style="margin-right:5px;color:#90e0ef;"></i>: <span style="font-weight:500;"><?= htmlspecialchars($employeeNo, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="user-role" style="font-size:11px;color:#b3d9ff;margin-top:2px;">
                        <i class="fas fa-user-md" style="margin-right:3px;"></i><?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>
                <span class="tooltip">View Profile</span>
            </div>
        </div>
    </a>

    <div class="user-actions">
        <a href="<?= $nav_base ?>user/doctor_settings.php"><i class="fas fa-cog"></i> Settings</a>
        <a href="#" onclick="showLogoutModal(event)"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</nav>

<?php
// Generate correct logout URL with production-safe calculation
$logoutUrl = '';

// Determine the correct path based on current location
if (strpos($_SERVER['PHP_SELF'], '/pages/management/') !== false) {
    // We're in a management page
    if (strpos($_SERVER['PHP_SELF'], '/pages/management/doctor/') !== false ||
        strpos($_SERVER['PHP_SELF'], '/pages/management/admin/') !== false ||
        strpos($_SERVER['PHP_SELF'], '/pages/management/nurse/') !== false) {
        // From role-specific pages (3 levels deep)
        $logoutUrl = '../auth/employee_logout.php';
    } else {
        // From /pages/management/ directly (2 levels deep)
        $logoutUrl = 'auth/employee_logout.php';
    }
} elseif (strpos($_SERVER['PHP_SELF'], '/pages/referrals/') !== false) {
    // From centralized referrals pages
    $logoutUrl = '../management/auth/employee_logout.php';
} else {
    // Fallback - use absolute path with dynamic base detection
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $request_uri = $_SERVER['REQUEST_URI'];
    
    // Extract base path from REQUEST_URI for production compatibility
    $uri_parts = explode('/', trim($request_uri, '/'));
    $base_path = '';
    
    // Check if we're in a project subfolder (local development)
    if (count($uri_parts) > 0 && $uri_parts[0] && $uri_parts[0] !== 'pages') {
        $base_path = '/' . $uri_parts[0];
    }
    
    $logoutUrl = $base_path . '/pages/management/auth/employee_logout.php';
}
?>

<!-- Hidden logout form with CSRF protection -->
<form id="logoutForm" action="<?= $logoutUrl ?>" method="post" style="display:none;">
    <?php if (isset($_SESSION['csrf_token'])): ?>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <?php endif; ?>
</form>

<!-- Logout Modal -->
<div id="logoutModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
        <h2 id="logoutTitle">Sign Out</h2>
        <p>Are you sure you want to sign out?</p>
        <div class="modal-actions">
            <button type="button" onclick="confirmLogout()" class="btn btn-danger">Sign Out</button>
            <button type="button" onclick="closeLogoutModal()" class="btn btn-secondary">Cancel</button>
        </div>
    </div>
</div>

<!-- Station Assignment Required Modal -->
<div id="stationRequiredModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="stationRequiredTitle">
        <h2 id="stationRequiredTitle">
            <i class="fas fa-exclamation-triangle" style="color:#ff6b6b;margin-right:8px;"></i>
            Station Assignment Required
        </h2>
        <p>Queue Management is not available because you are not currently assigned to any consultation station.</p>
        <p>Please contact your administrator to assign you to a consultation station to access queue management functions.</p>
        <div class="modal-actions">
            <button type="button" onclick="closeStationRequiredModal()" class="btn btn-primary">Understood</button>
        </div>
    </div>
</div>

<!-- Error Modal -->
<div id="errorModal" class="modal-overlay" style="display:none;">
    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="errorTitle">
        <h2 id="errorTitle">
            <i class="fas fa-exclamation-triangle" style="color:#ff6b6b;margin-right:8px;"></i>
            <span id="errorTitleText">Error</span>
        </h2>
        <p id="errorMessage">An error occurred. Please try again.</p>
        <div class="modal-actions">
            <button type="button" onclick="closeErrorModal()" class="btn btn-primary">OK</button>
        </div>
    </div>
</div>

<!-- Optional overlay -->
<div class="overlay" id="overlay" onclick="closeNav()"></div>

<script>
    function toggleNav() {
        const s = document.getElementById('sidebarNav');
        const o = document.getElementById('overlay');
        if (s) s.classList.toggle('open');
        if (o) o.classList.toggle('active');
    }

    function closeNav() {
        const s = document.getElementById('sidebarNav');
        const o = document.getElementById('overlay');
        if (s) s.classList.remove('open');
        if (o) o.classList.remove('active');
    }

    function showLogoutModal(e) {
        if (e) e.preventDefault();
        closeNav();
        const m = document.getElementById('logoutModal');
        if (m) m.style.display = 'flex';
    }

    function closeLogoutModal() {
        const m = document.getElementById('logoutModal');
        if (m) m.style.display = 'none';
    }

    function confirmLogout() {
        const f = document.getElementById('logoutForm');
        if (f) f.submit();
    }

    function showStationRequiredModal(e) {
        if (e) e.preventDefault();
        closeNav(); // Close sidebar on mobile
        const modal = document.getElementById('stationRequiredModal');
        if (modal) modal.style.display = 'flex';
        return false;
    }

    function closeStationRequiredModal() {
        const modal = document.getElementById('stationRequiredModal');
        if (modal) modal.style.display = 'none';
    }

    function showErrorModal(title, message) {
        closeNav();
        const titleElement = document.getElementById('errorTitleText');
        const messageElement = document.getElementById('errorMessage');
        const modal = document.getElementById('errorModal');
        
        if (titleElement) titleElement.textContent = title || 'Error';
        if (messageElement) messageElement.textContent = message || 'An error occurred. Please try again.';
        if (modal) modal.style.display = 'flex';
    }

    function closeErrorModal() {
        const modal = document.getElementById('errorModal');
        if (modal) modal.style.display = 'none';
    }

    // Close modals when clicking outside
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay')) {
            if (e.target.id === 'logoutModal') closeLogoutModal();
            if (e.target.id === 'stationRequiredModal') closeStationRequiredModal();
        }
    });
</script>