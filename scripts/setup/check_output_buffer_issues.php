<?php
/**
 * Output Buffer Issue Checker
 * 
 * This script scans all PHP files for unsafe ob_end_clean() calls
 * and provides recommendations for fixing them.
 * 
 * Usage: Run from command line or browser
 * php scripts/setup/check_output_buffer_issues.php
 */

// Set script execution time limit
set_time_limit(120);

// Color output for CLI
function colorOutput($text, $color = 'white') {
    if (php_sapi_name() === 'cli') {
        $colors = [
            'red' => "\033[31m",
            'green' => "\033[32m",
            'yellow' => "\033[33m",
            'blue' => "\033[34m",
            'magenta' => "\033[35m",
            'cyan' => "\033[36m",
            'white' => "\033[37m",
            'reset' => "\033[0m"
        ];
        return $colors[$color] . $text . $colors['reset'];
    } else {
        $colorMap = [
            'red' => '#ff0000',
            'green' => '#00aa00',
            'yellow' => '#aa6600',
            'blue' => '#0066aa',
            'magenta' => '#aa00aa',
            'cyan' => '#00aaaa',
            'white' => '#000000'
        ];
        return "<span style='color: {$colorMap[$color]};'>" . htmlspecialchars($text) . "</span>";
    }
}

function scanForOutputBufferIssues($directory) {
    $issues = [];
    $totalFiles = 0;
    $fixedFiles = 0;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $totalFiles++;
            $filePath = $file->getPathname();
            $relativePath = str_replace($directory . DIRECTORY_SEPARATOR, '', $filePath);
            
            $content = file_get_contents($filePath);
            $lines = explode("\n", $content);
            
            $hasIssues = false;
            $hasProperCheck = false;
            
            foreach ($lines as $lineNum => $line) {
                $lineNumber = $lineNum + 1;
                
                // Check for unsafe ob_end_clean() calls
                if (preg_match('/^\s*ob_end_clean\(\)\s*;/', $line)) {
                    // Check if previous lines have proper check
                    $hasSafeCheck = false;
                    for ($i = max(0, $lineNum - 3); $i < $lineNum; $i++) {
                        if (preg_match('/if\s*\(\s*ob_get_level\(\)\s*\)/', $lines[$i])) {
                            $hasSafeCheck = true;
                            break;
                        }
                    }
                    
                    if (!$hasSafeCheck) {
                        $issues[] = [
                            'file' => $relativePath,
                            'line' => $lineNumber,
                            'code' => trim($line),
                            'type' => 'unsafe_ob_end_clean'
                        ];
                        $hasIssues = true;
                    }
                }
                
                // Check for proper pattern
                if (preg_match('/if\s*\(\s*ob_get_level\(\)\s*\)/', $line)) {
                    $hasProperCheck = true;
                }
                
                // Check for safe_ob_clean helper function
                if (preg_match('/function\s+safe_ob_clean/', $line)) {
                    $hasProperCheck = true;
                    $fixedFiles++;
                }
            }
        }
    }
    
    return [
        'issues' => $issues,
        'totalFiles' => $totalFiles,
        'fixedFiles' => $fixedFiles,
        'stats' => [
            'unsafe_calls' => count(array_filter($issues, fn($i) => $i['type'] === 'unsafe_ob_end_clean'))
        ]
    ];
}

// Main execution
$rootPath = dirname(dirname(__DIR__));
echo colorOutput("Scanning for Output Buffer Issues...\n", 'cyan');
echo "Root Path: " . $rootPath . "\n\n";

// Scan pages directory
$results = scanForOutputBufferIssues($rootPath . '/pages');

echo colorOutput("=== SCAN RESULTS ===\n", 'yellow');
echo "Total PHP files scanned: " . $results['totalFiles'] . "\n";
echo "Files with fixes: " . colorOutput($results['fixedFiles'], 'green') . "\n";
echo "Unsafe ob_end_clean() calls found: " . colorOutput($results['stats']['unsafe_calls'], $results['stats']['unsafe_calls'] > 0 ? 'red' : 'green') . "\n\n";

if (!empty($results['issues'])) {
    echo colorOutput("=== ISSUES FOUND ===\n", 'red');
    
    foreach ($results['issues'] as $issue) {
        echo colorOutput("File: ", 'yellow') . $issue['file'] . "\n";
        echo colorOutput("Line: ", 'yellow') . $issue['line'] . "\n";
        echo colorOutput("Code: ", 'yellow') . $issue['code'] . "\n";
        echo colorOutput("Issue: ", 'red') . "ob_end_clean() called without checking if buffer exists\n";
        echo colorOutput("Fix: ", 'green') . "Replace with: if (ob_get_level()) { ob_end_clean(); }\n";
        echo str_repeat('-', 50) . "\n";
    }
    
    echo "\n" . colorOutput("=== RECOMMENDED ACTIONS ===\n", 'magenta');
    echo "1. For each file, add this helper function at the top:\n";
    echo colorOutput("   function safe_ob_clean() {\n       if (ob_get_level()) {\n           ob_end_clean();\n       }\n   }\n", 'green');
    echo "2. Replace all 'ob_end_clean();' calls with 'safe_ob_clean();'\n";
    echo "3. Or replace each call with: if (ob_get_level()) { ob_end_clean(); }\n\n";
    
} else {
    echo colorOutput("✓ No output buffer issues found! All files are clean.\n", 'green');
}

echo colorOutput("\n=== SUMMARY ===\n", 'cyan');
echo "The error 'Failed to delete buffer. No buffer to delete' occurs when\n";
echo "ob_end_clean() is called without an active output buffer.\n";
echo "Always check ob_get_level() before calling ob_end_clean().\n";

if (php_sapi_name() !== 'cli') {
    echo "<br><br><strong>Note:</strong> This script can also be run from command line for better formatting.";
}
?>