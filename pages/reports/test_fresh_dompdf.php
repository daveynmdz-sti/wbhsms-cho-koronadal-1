<?php
// Test the fresh Dompdf installation
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Create simple HTML
$html = '<!DOCTYPE html>
<html>
<head>
    <title>Test PDF</title>
    <style>
        body { font-family: Arial; font-size: 14px; margin: 20px; }
        h1 { color: #333; }
    </style>
</head>
<body>
    <h1>Fresh Dompdf Installation Test</h1>
    <p>This is a test to verify that the fresh Dompdf installation works correctly.</p>
    <p>Generated: ' . date('Y-m-d H:i:s') . '</p>
    <table border="1" style="width: 100%; border-collapse: collapse;">
        <tr>
            <th style="padding: 8px;">Test Column 1</th>
            <th style="padding: 8px;">Test Column 2</th>
        </tr>
        <tr>
            <td style="padding: 8px;">Test Data 1</td>
            <td style="padding: 8px;">Test Data 2</td>
        </tr>
    </table>
</body>
</html>';

try {
    echo "<h2>Testing Fresh Dompdf Installation...</h2>";
    
    $options = new Options();
    $options->set('isHtml5ParserEnabled', false);
    $options->set('isRemoteEnabled', false);
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    echo "<p style='color: green;'><strong>SUCCESS!</strong> Dompdf loaded and rendered without errors.</p>";
    echo "<p>Click button below to download test PDF:</p>";
    echo '<form method="post">
            <button type="submit" name="download" style="padding: 10px 20px; background: #007cba; color: white; border: none; cursor: pointer;">
                Download Test PDF
            </button>
          </form>';
    
    if (isset($_POST['download'])) {
        $dompdf->stream('test_fresh_install.pdf', array('Attachment' => true));
        exit;
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>ERROR:</strong> " . $e->getMessage() . "</p>";
    echo "<p><strong>File:</strong> " . $e->getFile() . "</p>";
    echo "<p><strong>Line:</strong> " . $e->getLine() . "</p>";
}
?>