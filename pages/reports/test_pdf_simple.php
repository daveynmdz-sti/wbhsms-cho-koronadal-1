<?php
// Simplified PDF export with error debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    echo "Starting PDF export debug...<br>";
    
    // Step 1: Path resolution
    $root_path = dirname(dirname(__DIR__));
    echo "1. Root path: " . htmlspecialchars($root_path) . "<br>";
    
    // Step 2: Include session
    if (file_exists($root_path . '/config/session/employee_session.php')) {
        require_once $root_path . '/config/session/employee_session.php';
        echo "2. ✅ Session config loaded<br>";
    } else {
        throw new Exception("Session config file not found");
    }
    
    // Step 3: Include database
    if (file_exists($root_path . '/config/db.php')) {
        require_once $root_path . '/config/db.php';
        echo "3. ✅ Database config loaded<br>";
    } else {
        throw new Exception("Database config file not found");
    }
    
    // Step 4: Check user login (bypass for testing)
    echo "4. Session check: ";
    if (!isset($_SESSION['employee_id'])) {
        echo "❌ Not logged in - creating test session<br>";
        // Create test session for debugging
        $_SESSION['employee_id'] = 1;
        $_SESSION['role'] = 'admin';
        $_SESSION['first_name'] = 'Test';
        $_SESSION['last_name'] = 'User';
    } else {
        echo "✅ User logged in<br>";
    }
    
    // Step 5: Include vendor autoload
    if (file_exists($root_path . '/vendor/autoload.php')) {
        require_once $root_path . '/vendor/autoload.php';
        echo "5. ✅ Vendor autoload loaded<br>";
    } else {
        throw new Exception("Vendor autoload not found");
    }
    
    // Step 6: Test Dompdf
    $dompdf_class = 'Dompdf\\Dompdf';
    $options_class = 'Dompdf\\Options';
    
    if (class_exists($options_class) && class_exists($dompdf_class)) {
        $options = new $options_class();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', true);
        $dompdf = new $dompdf_class($options);
        echo "6. ✅ Dompdf initialized<br>";
    } else {
        throw new Exception("Dompdf classes not found");
    }
    
    // Step 7: Test basic database query
    $test_query = "SELECT COUNT(*) as total FROM referrals";
    $test_stmt = $pdo->prepare($test_query);
    $test_stmt->execute();
    $test_result = $test_stmt->fetch(PDO::FETCH_ASSOC);
    echo "7. ✅ Database test: " . $test_result['total'] . " referrals found<br>";
    
    // Step 8: Generate simple test PDF
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <title>Test PDF</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; }
            h1 { color: #0077b6; }
        </style>
    </head>
    <body>
        <h1>Referral Summary Test PDF</h1>
        <p>Generated: ' . date('F j, Y \a\t g:i A') . '</p>
        <p>Total referrals in database: ' . $test_result['total'] . '</p>
        <p>Test successful!</p>
    </body>
    </html>';
    
    $dompdf->loadHtml($html);
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    echo "8. ✅ PDF rendered successfully<br>";
    
    // Step 9: Output PDF
    $filename = 'test_referral_summary_' . date('Ymd_His') . '.pdf';
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    echo $dompdf->output();
    echo "9. ✅ PDF output completed<br>";
    
} catch (Exception $e) {
    echo "<h2>❌ Error occurred:</h2>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . "</p>";
    echo "<p><strong>Line:</strong> " . $e->getLine() . "</p>";
    echo "<h3>Stack Trace:</h3>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>