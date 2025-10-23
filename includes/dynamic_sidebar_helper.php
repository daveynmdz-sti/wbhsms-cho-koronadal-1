<?php
/**
 * Dynamic Sidebar Inclusion Helper
 * This helper function can be used by centralized files to include the correct sidebar
 * based on the user's role instead of hardcoding admin sidebar.
 */

/**
 * Include the appropriate sidebar based on user role
 * @param string $activePage - The page identifier for sidebar highlighting
 * @param string $root_path - Path to the root directory
 */
function includeDynamicSidebar($activePage, $root_path) {
    // Get user role from session - use role_id if role name not available
    $role = null;
    
    // First try to get role name directly
    if (!empty($_SESSION['role'])) {
        $role = strtolower($_SESSION['role']);
    } 
    // If no role name, map from role_id
    elseif (!empty($_SESSION['role_id'])) {
        $roleMap = [
            1 => 'admin',
            2 => 'doctor', 
            3 => 'nurse',
            4 => 'pharmacist',
            5 => 'dho',
            6 => 'bhw',
            7 => 'records_officer',
            8 => 'cashier',
            9 => 'laboratory_tech'
        ];
        $role = $roleMap[$_SESSION['role_id']] ?? 'admin';
    } else {
        $role = 'admin'; // Default fallback
    }
    
    // Map roles to their sidebar files
    $sidebar_file = $root_path . '/includes/sidebar_' . $role . '.php';
    
    // Set the active page for sidebar highlighting
    global $activePage;
    $GLOBALS['activePage'] = $activePage;
    
    // Check if the role-specific sidebar exists
    if (file_exists($sidebar_file)) {
        include $sidebar_file;
    } else {
        // Fallback to admin sidebar if role-specific sidebar doesn't exist
        // This shouldn't happen for valid roles, but provides safety
        include $root_path . '/includes/sidebar_admin.php';
    }
}

/**
 * Get back URL based on user role
 * Useful for topbar back buttons in centralized files
 */
function getRoleDashboardUrl($role = null) {
    if ($role === null) {
        // Get role from session, preferring role name over role_id mapping
        if (!empty($_SESSION['role'])) {
            $role = strtolower($_SESSION['role']);
        } elseif (!empty($_SESSION['role_id'])) {
            $roleMap = [
                1 => 'admin',
                2 => 'doctor', 
                3 => 'nurse',
                4 => 'pharmacist',
                5 => 'dho',
                6 => 'bhw',
                7 => 'records_officer',
                8 => 'cashier',
                9 => 'laboratory_tech'
            ];
            $role = $roleMap[$_SESSION['role_id']] ?? 'admin';
        } else {
            $role = 'admin';
        }
    }
    
    return "../management/{$role}/dashboard.php";
}
?>