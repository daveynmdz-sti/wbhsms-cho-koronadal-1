<?php
// lib/pdf_generator.php
// Wrapper for Dompdf to convert HTML to PDF.

/**
 * PDF Generator Class
 * Provides a unified interface for generating PDFs from HTML content
 * Supports multiple PDF libraries with fallback options
 */
class PDFGenerator {
    
    private $defaultOptions = [
        'paper_size' => 'A4',
        'orientation' => 'portrait',
        'margin_top' => 20,
        'margin_bottom' => 20,
        'margin_left' => 15,
        'margin_right' => 15,
        'enable_php' => false,
        'enable_javascript' => false,
        'enable_remote' => true,
        'default_font' => 'Arial',
        'encoding' => 'UTF-8'
    ];
    
    /**
     * Generate PDF from HTML content
     * @param string $html HTML content to convert
     * @param array $options PDF generation options
     * @return string PDF binary content
     * @throws Exception If no PDF library is available or generation fails
     */
    public function generatePdfFromHtml($html, $options = []) {
        // Merge options with defaults
        $config = array_merge($this->defaultOptions, $options);
        
        // Add CSS for better PDF rendering
        $html = $this->addPdfStyles($html);
        
        // Try Dompdf first (recommended)
        if (class_exists('Dompdf\Dompdf')) {
            return $this->generateWithDompdf($html, $config);
        }
        
        // Fallback to mPDF
        if (class_exists('Mpdf\Mpdf')) {
            return $this->generateWithMpdf($html, $config);
        }
        
        // Fallback to TCPDF
        if (class_exists('TCPDF')) {
            return $this->generateWithTcpdf($html, $config);
        }
        
        throw new Exception('No PDF generation library available. Please install Dompdf, mPDF, or TCPDF.');
    }
    
    /**
     * Generate PDF using Dompdf
     * @param string $html
     * @param array $config
     * @return string
     */
    private function generateWithDompdf($html, $config) {
        try {
            // Configure Dompdf options
            $options = new \Dompdf\Options();
            $options->set('isPhpEnabled', $config['enable_php']);
            $options->set('isJavascriptEnabled', $config['enable_javascript']);
            $options->set('isRemoteEnabled', $config['enable_remote']);
            $options->set('defaultFont', $config['default_font']);
            $options->set('fontHeightRatio', 1.1);
            $options->set('dpi', 96);
            
            // Create Dompdf instance
            $dompdf = new \Dompdf\Dompdf($options);
            
            // Load HTML
            $dompdf->loadHtml($html, $config['encoding']);
            
            // Set paper size and orientation
            $dompdf->setPaper($config['paper_size'], $config['orientation']);
            
            // Render PDF
            $dompdf->render();
            
            // Return PDF content
            return $dompdf->output();
            
        } catch (Exception $e) {
            throw new Exception('Dompdf generation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate PDF using mPDF
     * @param string $html
     * @param array $config
     * @return string
     */
    private function generateWithMpdf($html, $config) {
        try {
            $mpdfClass = '\Mpdf\Mpdf';
            $mpdf = new $mpdfClass([
                'mode' => $config['encoding'],
                'format' => $config['paper_size'],
                'orientation' => substr($config['orientation'], 0, 1), // 'P' or 'L'
                'margin_left' => $config['margin_left'],
                'margin_right' => $config['margin_right'],
                'margin_top' => $config['margin_top'],
                'margin_bottom' => $config['margin_bottom'],
                'margin_header' => 10,
                'margin_footer' => 10,
                'default_font' => strtolower($config['default_font'])
            ]);
            
            $mpdf->WriteHTML($html);
            return $mpdf->Output('', 'S');
            
        } catch (Exception $e) {
            throw new Exception('mPDF generation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate PDF using TCPDF
     * @param string $html
     * @param array $config
     * @return string
     */
    private function generateWithTcpdf($html, $config) {
        try {
            $orientation = strtoupper(substr($config['orientation'], 0, 1)); // 'P' or 'L'
            
            $tcpdfClass = 'TCPDF';
            $pdf = new $tcpdfClass($orientation, 'mm', $config['paper_size'], true, $config['encoding']);
            
            // Set document information
            $pdf->SetCreator('CHO Koronadal WBHSMS');
            $pdf->SetAuthor('CHO Koronadal');
            $pdf->SetTitle('Medical Record');
            $pdf->SetSubject('Medical Record');
            $pdf->SetKeywords('Medical, Record, Healthcare');
            
            // Set header and footer
            $pdf->setHeaderData('', 0, 'Medical Record', 'CHO Koronadal City');
            $pdf->setHeaderFont([$config['default_font'], '', 10]);
            $pdf->setFooterFont([$config['default_font'], '', 8]);
            
            // Set margins
            $pdf->SetMargins($config['margin_left'], $config['margin_top'] + 10, $config['margin_right']);
            $pdf->SetHeaderMargin(5);
            $pdf->SetFooterMargin(10);
            $pdf->SetAutoPageBreak(TRUE, $config['margin_bottom']);
            
            // Add page and write HTML
            $pdf->AddPage();
            $pdf->writeHTML($html, true, false, true, false, '');
            
            return $pdf->Output('', 'S');
            
        } catch (Exception $e) {
            throw new Exception('TCPDF generation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Add PDF-optimized CSS styles to HTML
     * @param string $html
     * @return string
     */
    private function addPdfStyles($html) {
        $pdfStyles = '
        <style>
            @page {
                margin: 2cm;
                size: A4;
            }
            
            body {
                font-family: Arial, sans-serif;
                font-size: 12px;
                line-height: 1.4;
                color: #333;
                margin: 0;
                padding: 0;
            }
            
            .medical-record-container {
                max-width: 100%;
                margin: 0;
                padding: 0;
            }
            
            .section {
                margin-bottom: 20px;
                page-break-inside: avoid;
            }
            
            .section h3 {
                background-color: #f8f9fa;
                color: #2c5aa0;
                padding: 8px 12px;
                margin: 0 0 15px 0;
                border-left: 4px solid #2c5aa0;
                font-size: 14px;
                font-weight: bold;
                page-break-after: avoid;
            }
            
            .info-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
                margin-bottom: 15px;
            }
            
            .info-item {
                padding: 5px 0;
                border-bottom: 1px solid #eee;
            }
            
            .info-item strong {
                color: #555;
                display: inline-block;
                min-width: 120px;
            }
            
            .table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 15px;
                font-size: 11px;
            }
            
            .table th,
            .table td {
                border: 1px solid #ddd;
                padding: 6px;
                text-align: left;
                vertical-align: top;
            }
            
            .table th {
                background-color: #f8f9fa;
                font-weight: bold;
                color: #2c5aa0;
            }
            
            .table tr:nth-child(even) {
                background-color: #f9f9f9;
            }
            
            .no-data {
                color: #666;
                font-style: italic;
                padding: 10px;
                text-align: center;
                background-color: #f8f9fa;
                border-radius: 4px;
            }
            
            .medication-item {
                border: 1px solid #e0e0e0;
                padding: 10px;
                margin-bottom: 10px;
                border-radius: 4px;
                background-color: #fafafa;
                page-break-inside: avoid;
            }
            
            .consultation-item,
            .prescription-item,
            .lab-order-item {
                border: 1px solid #ddd;
                margin: 15px 0;
                padding: 15px;
                border-radius: 5px;
                page-break-inside: avoid;
            }
            
            .consultation-item h4,
            .prescription-item h4,
            .lab-order-item h4 {
                margin: 0 0 10px 0;
                color: #2c5aa0;
                font-size: 13px;
            }
            
            .header-info {
                text-align: center;
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 2px solid #2c5aa0;
            }
            
            .header-info h1 {
                color: #2c5aa0;
                margin: 0 0 5px 0;
                font-size: 18px;
            }
            
            .header-info h2 {
                color: #666;
                margin: 0 0 10px 0;
                font-size: 14px;
                font-weight: normal;
            }
            
            .generated-info {
                font-size: 10px;
                color: #888;
                text-align: right;
                margin-top: 20px;
                padding-top: 10px;
                border-top: 1px solid #eee;
            }
            
            .page-break {
                page-break-before: always;
            }
            
            .no-print {
                display: none;
            }
            
            table {
                page-break-inside: auto;
            }
            
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            td {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            thead {
                display: table-header-group;
            }
            
            tfoot {
                display: table-footer-group;
            }
        </style>';
        
        // Insert styles after <head> tag or at the beginning if no head tag
        if (strpos($html, '<head>') !== false) {
            $html = str_replace('<head>', '<head>' . $pdfStyles, $html);
        } else {
            $html = $pdfStyles . $html;
        }
        
        return $html;
    }
    
    /**
     * Generate PDF and save to file
     * @param string $html HTML content
     * @param string $filepath Path to save the PDF file
     * @param array $options PDF options
     * @return bool Success status
     */
    public function generatePdfToFile($html, $filepath, $options = []) {
        try {
            $pdfContent = $this->generatePdfFromHtml($html, $options);
            return file_put_contents($filepath, $pdfContent) !== false;
        } catch (Exception $e) {
            error_log("PDF file generation failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get available PDF libraries
     * @return array
     */
    public function getAvailableLibraries() {
        $libraries = [];
        
        if (class_exists('Dompdf\Dompdf')) {
            $libraries[] = 'Dompdf';
        }
        
        if (class_exists('Mpdf\Mpdf')) {
            $libraries[] = 'mPDF';
        }
        
        if (class_exists('TCPDF')) {
            $libraries[] = 'TCPDF';
        }
        
        return $libraries;
    }
    
    /**
     * Check if any PDF library is available
     * @return bool
     */
    public function isAvailable() {
        return !empty($this->getAvailableLibraries());
    }
}

/**
 * Convenience function to generate PDF from HTML
 * @param string $html HTML content
 * @param array $options PDF options
 * @return string PDF binary content
 */
function generatePdfFromHtml($html, $options = []) {
    $generator = new PDFGenerator();
    return $generator->generatePdfFromHtml($html, $options);
}

/**
 * Convenience function to generate PDF file
 * @param string $html HTML content
 * @param string $filepath File path to save
 * @param array $options PDF options
 * @return bool Success status
 */
function generatePdfToFile($html, $filepath, $options = []) {
    $generator = new PDFGenerator();
    return $generator->generatePdfToFile($html, $filepath, $options);
}
?>