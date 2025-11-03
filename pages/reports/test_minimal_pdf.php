<?php
// Minimal PDF test to isolate the issue

// Create HTML5 stub class first
if (!class_exists('Masterminds\HTML5')) {
    class HTML5 {
        public function loadHTML($html) {
            return $html;
        }
        public function saveHTML($dom) {
            return $dom;
        }
    }
    class_alias('HTML5', 'Masterminds\HTML5');
}

// Auto-load Composer packages
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Test with absolutely minimal HTML - no CSS whatsoever
$html = '<!DOCTYPE html>
<html>
<head>
    <title>Test PDF</title>
</head>
<body>
    <h1>TEST PDF REPORT</h1>
    <p>This is a minimal test.</p>
    <table border="1">
        <tr>
            <td>Test Data 1</td>
            <td>Test Data 2</td>
        </tr>
        <tr>
            <td>Test Data 3</td>
            <td>Test Data 4</td>
        </tr>
    </table>
    <p>Generated: ' . date('Y-m-d H:i:s') . '</p>
</body>
</html>';

try {
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', false);
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    $dompdf->stream('test_minimal.pdf', array('Attachment' => true));
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    echo "<br>File: " . $e->getFile();
    echo "<br>Line: " . $e->getLine();
}
?>