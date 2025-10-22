<?php
/**
 * Production Directory Structure Checker
 * Helps identify the correct file paths for API endpoints in production
 */

header('Content-Type: application/json');

$response = [
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'server_info' => [],
    'directory_info' => [],
    'api_paths' => [],
    'errors' => []
];

try {
    // Server information
    $response['server_info'] = [
        'server_name' => $_SERVER['SERVER_NAME'] ?? 'Unknown',
        'http_host' => $_SERVER['HTTP_HOST'] ?? 'Unknown',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
        'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'Unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'Unknown',
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'php_version' => PHP_VERSION,
        'current_working_directory' => getcwd(),
        'script_filename' => __FILE__
    ];

    // Directory structure analysis
    $currentDir = dirname(__FILE__);
    $response['directory_info']['current_dir'] = $currentDir;
    $response['directory_info']['parent_dirs'] = [];
    
    // Check parent directories
    $dir = $currentDir;
    for ($i = 0; $i < 5; $i++) {
        $parentDir = dirname($dir);
        if ($parentDir === $dir) break; // Reached root
        
        $response['directory_info']['parent_dirs'][] = [
            'level' => $i + 1,
            'path' => $parentDir,
            'exists' => is_dir($parentDir),
            'readable' => is_readable($parentDir),
            'contents' => is_readable($parentDir) ? array_slice(scandir($parentDir), 0, 10) : 'Not readable'
        ];
        
        $dir = $parentDir;
    }

    // Check for API directory in various locations
    $possibleApiPaths = [
        $currentDir . '/api',
        dirname($currentDir) . '/api',
        dirname(dirname($currentDir)) . '/api',
        dirname(dirname(dirname($currentDir))) . '/api',
        dirname(dirname(dirname(dirname($currentDir)))) . '/api',
        $_SERVER['DOCUMENT_ROOT'] . '/api',
        $_SERVER['DOCUMENT_ROOT'] . '/wbhsms-cho-koronadal-1/api'
    ];

    foreach ($possibleApiPaths as $apiPath) {
        $billingPath = $apiPath . '/billing/management';
        $invoiceApiPath = $billingPath . '/get_invoice_details.php';
        $printApiPath = $billingPath . '/print_invoice.php';
        
        $response['api_paths'][] = [
            'api_base' => $apiPath,
            'billing_management' => $billingPath,
            'invoice_api' => $invoiceApiPath,
            'print_api' => $printApiPath,
            'api_dir_exists' => is_dir($apiPath),
            'billing_dir_exists' => is_dir($billingPath),
            'invoice_file_exists' => file_exists($invoiceApiPath),
            'print_file_exists' => file_exists($printApiPath),
            'relative_to_current' => str_replace($currentDir, '.', $apiPath)
        ];
    }

    // Check specific file paths relative to current script
    $relativeChecks = [
        './api/billing/management/get_invoice_details.php',
        '../api/billing/management/get_invoice_details.php',
        '../../api/billing/management/get_invoice_details.php',
        '../../../api/billing/management/get_invoice_details.php',
        '../../../../api/billing/management/get_invoice_details.php'
    ];

    $response['relative_checks'] = [];
    foreach ($relativeChecks as $relativePath) {
        $absolutePath = realpath(dirname(__FILE__) . '/' . $relativePath);
        $response['relative_checks'][] = [
            'relative_path' => $relativePath,
            'absolute_path' => $absolutePath,
            'file_exists' => $absolutePath ? file_exists($absolutePath) : false,
            'is_readable' => $absolutePath ? is_readable($absolutePath) : false
        ];
    }

    // Try to include and test the API files
    $testResults = [];
    foreach ($response['api_paths'] as $apiInfo) {
        if ($apiInfo['invoice_file_exists']) {
            $testResults[] = [
                'file' => $apiInfo['invoice_api'],
                'test' => 'File exists and is readable',
                'accessible' => is_readable($apiInfo['invoice_api'])
            ];
        }
    }
    $response['test_results'] = $testResults;

} catch (Exception $e) {
    $response['success'] = false;
    $response['errors'][] = $e->getMessage();
}

// Pretty print JSON for debugging
if (isset($_GET['debug'])) {
    echo '<pre>' . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . '</pre>';
} else {
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
?>