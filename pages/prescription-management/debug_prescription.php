<?php
// Simple debug page for prescription functionality
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription Debug</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f5f5f5;
        }
        .debug-container {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            max-width: 800px;
            margin: 0 auto;
        }
        .test-button {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            margin: 5px;
        }
        .test-button:hover {
            background: #0056b3;
        }
        .result {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            padding: 10px;
            border-radius: 4px;
            margin: 10px 0;
            white-space: pre-wrap;
            font-family: monospace;
        }
        .error {
            color: #dc3545;
            background: #f8d7da;
            border-color: #f5c6cb;
        }
        .success {
            color: #155724;
            background: #d4edda;
            border-color: #c3e6cb;
        }
    </style>
</head>
<body>
    <div class="debug-container">
        <h1>Prescription Page Debug Tool</h1>
        <p>Use this tool to test prescription page functionality and identify issues.</p>
        
        <div>
            <h3>Quick Tests:</h3>
            <button class="test-button" onclick="testBasicElements()">Test Basic Elements</button>
            <button class="test-button" onclick="testAddMedication()">Test Add Medication</button>
            <button class="test-button" onclick="testJavaScriptErrors()">Check JavaScript Errors</button>
            <button class="test-button" onclick="clearResults()">Clear Results</button>
        </div>
        
        <div id="results"></div>
        
        <div style="margin-top: 30px;">
            <h3>Manual Debug Instructions:</h3>
            <ol>
                <li>Open the prescription page in another tab</li>
                <li>Open Developer Tools (F12)</li>
                <li>Go to Console tab</li>
                <li>Type: <code>debugPrescriptionPage()</code> and press Enter</li>
                <li>Look for any error messages or missing elements</li>
                <li>Try clicking "Add Medication" and watch console for errors</li>
            </ol>
        </div>
        
        <!-- Simple test elements -->
        <div style="display: none;">
            <div id="test-medications-container">
                <div class="medication-row">
                    <input type="text" name="medications[0][medication_name]" value="Test">
                </div>
            </div>
        </div>
    </div>

    <script>
        let testMedicationCount = 1;
        
        function logResult(message, type = 'info') {
            const results = document.getElementById('results');
            const div = document.createElement('div');
            div.className = 'result ' + type;
            div.textContent = new Date().toLocaleTimeString() + ': ' + message;
            results.appendChild(div);
        }
        
        function testBasicElements() {
            logResult('Testing basic elements...', 'info');
            
            // Test if we can create basic DOM elements
            try {
                const testDiv = document.createElement('div');
                testDiv.innerHTML = '<p>Test HTML</p>';
                logResult('✓ DOM createElement works', 'success');
            } catch (error) {
                logResult('✗ DOM createElement failed: ' + error.message, 'error');
            }
            
            // Test if getElementById works
            try {
                const testContainer = document.getElementById('test-medications-container');
                if (testContainer) {
                    logResult('✓ getElementById works', 'success');
                } else {
                    logResult('✗ getElementById returned null', 'error');
                }
            } catch (error) {
                logResult('✗ getElementById failed: ' + error.message, 'error');
            }
            
            // Test querySelectorAll
            try {
                const inputs = document.querySelectorAll('input[name*="[medication_name]"]');
                logResult('✓ querySelectorAll works, found ' + inputs.length + ' inputs', 'success');
            } catch (error) {
                logResult('✗ querySelectorAll failed: ' + error.message, 'error');
            }
        }
        
        function testAddMedication() {
            logResult('Testing add medication functionality...', 'info');
            
            try {
                const container = document.getElementById('test-medications-container');
                if (!container) {
                    logResult('✗ Test container not found', 'error');
                    return;
                }
                
                const newRow = document.createElement('div');
                newRow.className = 'medication-row';
                newRow.innerHTML = `
                    <div class="form-group">
                        <input type="text" name="medications[${testMedicationCount}][medication_name]" placeholder="Test medication" required>
                    </div>
                `;
                
                container.appendChild(newRow);
                testMedicationCount++;
                
                logResult('✓ Add medication simulation successful', 'success');
                logResult('Container now has ' + container.children.length + ' children', 'info');
            } catch (error) {
                logResult('✗ Add medication failed: ' + error.message, 'error');
            }
        }
        
        function testJavaScriptErrors() {
            logResult('Checking for JavaScript errors...', 'info');
            
            // Check if common functions exist
            const functions = ['document.createElement', 'document.getElementById', 'document.querySelectorAll'];
            functions.forEach(func => {
                try {
                    if (eval('typeof ' + func) === 'function') {
                        logResult('✓ ' + func + ' available', 'success');
                    } else {
                        logResult('✗ ' + func + ' not available', 'error');
                    }
                } catch (error) {
                    logResult('✗ Error checking ' + func + ': ' + error.message, 'error');
                }
            });
            
            logResult('Check browser console for any additional errors', 'info');
        }
        
        function clearResults() {
            document.getElementById('results').innerHTML = '';
        }
        
        // Log page load
        window.addEventListener('load', function() {
            logResult('Debug page loaded successfully', 'success');
        });
    </script>
</body>
</html>