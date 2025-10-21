<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Button Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test-section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .success { background-color: #d4edda; border-color: #c3e6cb; }
        .error { background-color: #f8d7da; border-color: #f5c6cb; }
        .warning { background-color: #fff3cd; border-color: #ffeaa7; }
        button { padding: 10px 20px; margin: 5px; cursor: pointer; }
        #result { margin-top: 20px; padding: 10px; border: 1px solid #ccc; min-height: 100px; }
        iframe { width: 100%; height: 500px; border: 1px solid #ccc; }
    </style>
</head>
<body>
    <h1>Patient Laboratory View Button Test</h1>
    
    <div class="test-section">
        <h3>1. Test Direct API Call</h3>
        <p>This will test the get_lab_result.php endpoint directly:</p>
        <button onclick="testDirectAPI()">Test Direct API</button>
        <div id="api-result"></div>
    </div>

    <div class="test-section">
        <h3>2. Test Lab Orders API</h3>
        <p>This will test if we can fetch lab orders with results:</p>
        <button onclick="testLabOrdersAPI()">Test Lab Orders API</button>
        <div id="orders-result"></div>
    </div>

    <div class="test-section">
        <h3>3. Test PDF Viewer with Sample Data</h3>
        <p>If you have lab result data, enter an item ID to test:</p>
        <input type="number" id="itemId" placeholder="Enter item ID" value="1">
        <button onclick="testPDFViewer()">Test PDF Viewer</button>
        <div id="pdf-result"></div>
    </div>

    <div class="test-section">
        <h3>4. Test Session Status</h3>
        <button onclick="testSession()">Check Session</button>
        <div id="session-result"></div>
    </div>

    <div id="result"></div>

    <script>
        function testDirectAPI() {
            const resultDiv = document.getElementById('api-result');
            resultDiv.innerHTML = 'Testing...';
            
            // Test with a sample item ID
            fetch('get_lab_result.php?item_id=1&action=view')
                .then(response => {
                    resultDiv.innerHTML = `
                        <strong>Response Status:</strong> ${response.status}<br>
                        <strong>Content Type:</strong> ${response.headers.get('content-type')}<br>
                        <strong>Status Text:</strong> ${response.statusText}
                    `;
                    
                    if (response.ok && response.headers.get('content-type')?.includes('application/pdf')) {
                        resultDiv.innerHTML += '<br>✅ PDF response received!';
                        resultDiv.className = 'test-section success';
                    } else {
                        return response.text().then(text => {
                            resultDiv.innerHTML += `<br><strong>Response:</strong> ${text}`;
                            resultDiv.className = 'test-section error';
                        });
                    }
                })
                .catch(error => {
                    resultDiv.innerHTML = `❌ Error: ${error.message}`;
                    resultDiv.className = 'test-section error';
                });
        }

        function testLabOrdersAPI() {
            const resultDiv = document.getElementById('orders-result');
            resultDiv.innerHTML = 'Testing lab orders API...';
            
            fetch('get_lab_orders.php?action=list')
                .then(response => response.text())
                .then(data => {
                    resultDiv.innerHTML = `
                        <strong>Lab Orders API Response:</strong><br>
                        <pre>${data}</pre>
                    `;
                    resultDiv.className = 'test-section success';
                })
                .catch(error => {
                    resultDiv.innerHTML = `❌ Error: ${error.message}`;
                    resultDiv.className = 'test-section error';
                });
        }

        function testPDFViewer() {
            const itemId = document.getElementById('itemId').value;
            const resultDiv = document.getElementById('pdf-result');
            
            if (!itemId) {
                resultDiv.innerHTML = '❌ Please enter an item ID';
                resultDiv.className = 'test-section error';
                return;
            }
            
            resultDiv.innerHTML = 'Loading PDF viewer...';
            
            // Create iframe like the real system does
            const iframe = document.createElement('iframe');
            iframe.src = `get_lab_result.php?item_id=${itemId}&action=view`;
            iframe.style.width = '100%';
            iframe.style.height = '400px';
            iframe.style.border = '1px solid #ccc';
            
            iframe.onload = function() {
                resultDiv.className = 'test-section success';
            };
            
            iframe.onerror = function() {
                resultDiv.innerHTML = '❌ Failed to load PDF';
                resultDiv.className = 'test-section error';
            };
            
            resultDiv.innerHTML = '';
            resultDiv.appendChild(iframe);
        }

        function testSession() {
            const resultDiv = document.getElementById('session-result');
            resultDiv.innerHTML = 'Checking session...';
            
            fetch('diagnostic.php')
                .then(response => response.text())
                .then(html => {
                    // Extract just the session part
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const sessionSection = doc.querySelector('h3');
                    
                    if (sessionSection && sessionSection.textContent.includes('Session')) {
                        let sessionInfo = sessionSection.nextElementSibling.textContent;
                        resultDiv.innerHTML = sessionInfo;
                        
                        if (sessionInfo.includes('Patient session active')) {
                            resultDiv.className = 'test-section success';
                        } else {
                            resultDiv.className = 'test-section warning';
                        }
                    } else {
                        resultDiv.innerHTML = 'Could not determine session status';
                        resultDiv.className = 'test-section warning';
                    }
                })
                .catch(error => {
                    resultDiv.innerHTML = `❌ Error: ${error.message}`;
                    resultDiv.className = 'test-section error';
                });
        }

        // Auto-run session test on page load
        document.addEventListener('DOMContentLoaded', function() {
            testSession();
        });
    </script>
</body>
</html>