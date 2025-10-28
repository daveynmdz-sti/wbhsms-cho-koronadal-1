<?php
// pages/index.php - Redirect to root index.php
// Production-friendly redirect that works in both localhost and production

// Dynamic path resolution for production compatibility
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];

// Extract base path from script name
if (preg_match('#^(/[^/]+)/#', $script_name, $matches)) {
    $base_path = $matches[1] . '/';
} else {
    $base_path = '/';
}

// Construct the root URL
$root_url = $protocol . '://' . $host . $base_path;

// Redirect to root index.php
header('Location: ' . $root_url);
exit;
?>