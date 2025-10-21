<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laboratory System - Navigation Test</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 2rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: white;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.95);
            padding: 2rem;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            color: #333;
        }

        .header {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 3px solid #007BFF;
        }

        .header h1 {
            color: #007BFF;
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }

        .test-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            border-left: 5px solid #28a745;
        }

        .test-section h3 {
            color: #28a745;
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .file-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin: 1rem 0;
        }

        .file-item {
            background: white;
            padding: 1rem;
            border-radius: 8px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .file-item:hover {
            border-color: #007BFF;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,123,255,0.2);
        }

        .file-item.exists {
            border-color: #28a745;
            background: #f8fff9;
        }

        .file-item.missing {
            border-color: #dc3545;
            background: #fff8f8;
        }

        .file-name {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.5rem;
        }

        .file-status {
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .status-icon {
            width: 16px;
            height: 16px;
        }

        .exists .status-icon {
            color: #28a745;
        }

        .missing .status-icon {
            color: #dc3545;
        }

        .navigation-test {
            background: #e3f2fd;
            padding: 1.5rem;
            border-radius: 10px;
            border-left: 5px solid #2196f3;
            margin-top: 2rem;
        }

        .nav-button {
            display: inline-block;
            background: #007BFF;
            color: white;
            padding: 0.8rem 1.5rem;
            text-decoration: none;
            border-radius: 8px;
            margin: 0.5rem;
            transition: all 0.3s ease;
            font-weight: 600;
        }

        .nav-button:hover {
            background: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 123, 255, 0.4);
        }

        .summary {
            text-align: center;
            padding: 2rem;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border-radius: 10px;
            margin-top: 2rem;
        }

        .summary h2 {
            margin-top: 0;
        }

        .connection-map {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin: 1rem 0;
        }

        .connection-map pre {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 6px;
            overflow-x: auto;
            font-size: 0.9rem;
            color: #495057;
        }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-flask"></i> Laboratory System</h1>
            <p>Navigation & File Connection Test</p>
        </div>

        <div class="test-section">
            <h3><i class="fas fa-file-code"></i> Essential Files Status</h3>
            
            <div class="file-list">
                <?php
                $files = [
                    'laboratory.php' => 'Main Laboratory Interface',
                    'get_lab_orders.php' => 'Lab Orders API Endpoint', 
                    'get_lab_result.php' => 'PDF Result Viewer',
                    'download_lab_result.php' => 'Download Handler',
                    'print_lab_result.php' => 'Print Interface'
                ];

                foreach ($files as $file => $description) {
                    $exists = file_exists($file);
                    $class = $exists ? 'exists' : 'missing';
                    $icon = $exists ? 'fa-check-circle' : 'fa-times-circle';
                    $status = $exists ? 'File Ready' : 'File Missing';
                    
                    echo "
                    <div class='file-item $class'>
                        <div class='file-name'>$file</div>
                        <div class='file-status'>
                            <i class='fas $icon status-icon'></i>
                            $status
                        </div>
                        <small>$description</small>
                    </div>";
                }
                ?>
            </div>
        </div>

        <div class="test-section">
            <h3><i class="fas fa-sitemap"></i> Connection Flow Map</h3>
            
            <div class="connection-map">
                <pre>
Patient Sidebar (sidebar_patient.php)
    │
    └── <strong>Laboratory Link:</strong> /pages/patient/laboratory/laboratory.php
            │
            ├── AJAX Calls to get_lab_orders.php
            │   ├── ?action=list (fetch lab orders)
            │   ├── ?action=details&lab_order_id=X (order details)
            │   └── ?action=results&lab_order_id=X (order results)
            │
            ├── PDF Viewer: get_lab_result.php?item_id=X&action=view
            │
            ├── Download: download_lab_result.php?item_id=X
            │
            └── Print: print_lab_result.php?item_id=X
                </pre>
            </div>
        </div>

        <div class="navigation-test">
            <h3><i class="fas fa-compass"></i> Navigation Test Links</h3>
            <p>Test these links to verify navigation is working properly:</p>
            
            <a href="laboratory.php" class="nav-button">
                <i class="fas fa-flask"></i> Main Laboratory Interface
            </a>
            
            <a href="get_lab_orders.php?action=list" class="nav-button">
                <i class="fas fa-list"></i> Test API (List Orders)
            </a>
            
            <a href="../dashboard.php" class="nav-button">
                <i class="fas fa-tachometer-alt"></i> Back to Patient Dashboard
            </a>
            
            <a href="../../index.php" class="nav-button">
                <i class="fas fa-home"></i> System Home
            </a>
        </div>

        <?php
        // Count existing files
        $total_files = count($files);
        $existing_files = 0;
        foreach ($files as $file => $desc) {
            if (file_exists($file)) $existing_files++;
        }
        $completion_percent = ($existing_files / $total_files) * 100;
        ?>

        <div class="summary">
            <h2><i class="fas fa-chart-pie"></i> System Status</h2>
            <p><strong><?php echo $existing_files; ?> of <?php echo $total_files; ?> files ready</strong></p>
            <p><strong><?php echo round($completion_percent); ?>% Complete</strong></p>
            
            <?php if ($completion_percent == 100): ?>
                <div style="margin-top: 1rem; padding: 1rem; background: rgba(255,255,255,0.2); border-radius: 8px;">
                    <i class="fas fa-check-circle" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                    <h3>🎉 All Systems Ready!</h3>
                    <p>The Patient Laboratory Management System is fully functional and ready for testing.</p>
                </div>
            <?php else: ?>
                <div style="margin-top: 1rem; padding: 1rem; background: rgba(255,193,7,0.2); border-radius: 8px;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                    <h3>⚠️ Missing Files Detected</h3>
                    <p>Please ensure all essential files are present before testing.</p>
                </div>
            <?php endif; ?>
        </div>

        <div style="text-align: center; margin-top: 2rem; color: #6c757d;">
            <p><strong>Test Date:</strong> <?php echo date('F j, Y g:i A'); ?></p>
            <p><strong>Location:</strong> /pages/patient/laboratory/</p>
        </div>
    </div>
</body>
</html>