<?php
/**
 * Test Dompdf Installation
 */

$root_path = dirname(__DIR__);
require_once $root_path . '/vendor/autoload.php';

echo "<h2>Testing Dompdf Installation</h2>";

// Test if classes can be loaded
$tests = [
    'Dompdf\Dompdf' => 'Dompdf main class',
    'Dompdf\Options' => 'Dompdf options class',
    'PHPMailer\PHPMailer\PHPMailer' => 'PHPMailer class',
    'Psr\Log\LoggerInterface' => 'PSR-3 Logger interface'
];

foreach ($tests as $class => $description) {
    if (class_exists($class) || interface_exists($class)) {
        echo "✅ {$description}: <strong>Available</strong><br>";
    } else {
        echo "❌ {$description}: <strong>Not found</strong><br>";
    }
}

// Try to create a Dompdf instance
echo "<h3>Testing Dompdf Instance Creation</h3>";
try {
    $options = new \Dompdf\Options();
    $dompdf = new \Dompdf\Dompdf($options);
    echo "✅ Dompdf instance created successfully!<br>";
    
    // Test basic HTML rendering
    $html = '<html><body><h1>Test PDF</h1><p>This is a test.</p></body></html>';
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    echo "✅ HTML rendering completed successfully!<br>";
    echo "<strong>PDF generation is working!</strong>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
    echo "❌ PDF generation is not working.";
}
?>