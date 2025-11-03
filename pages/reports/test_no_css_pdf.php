<?php
// Test with Dompdf but completely disable CSS processing

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

// Test with HTML that has NO CSS whatsoever - not even style tags
$html = '<html>
<head>
    <meta charset="UTF-8">
    <title>Test PDF</title>
</head>
<body>
    <h1>TEST PDF REPORT</h1>
    <p>This is a minimal test with no CSS.</p>
    <table border="1" cellpadding="5" cellspacing="0">
        <tr bgcolor="#cccccc">
            <td><b>Test Data 1</b></td>
            <td><b>Test Data 2</b></td>
        </tr>
        <tr>
            <td>Test Data 3</td>
            <td>Test Data 4</td>
        </tr>
    </table>
    <p><b>Generated:</b> ' . date('Y-m-d H:i:s') . '</p>
</body>
</html>';

try {
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', false);
    $options->set('isCssFloatEnabled', false);  // Disable CSS float
    $options->set('isPhpEnabled', false);
    $options->set('isJavascriptEnabled', false);
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    $dompdf->stream('test_no_css.pdf', array('Attachment' => true));
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    echo "<br>File: " . $e->getFile();
    echo "<br>Line: " . $e->getLine();
    echo "<br><br>Stack trace:<br>" . nl2br($e->getTraceAsString());
}
?>