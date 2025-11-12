<!DOCTYPE html>
<html>
<head>
    <title>Test Referral Appointment Integration</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .info { background: #d1ecf1; color: #0c5460; }
        button { padding: 8px 16px; margin: 5px; cursor: pointer; }
        input[type="number"] { padding: 5px; margin: 5px; width: 100px; }
    </style>
</head>
<body>
    <h1>Referral Appointment Integration Test</h1>
    
    <div class="section">
        <h3>Test API Endpoints</h3>
        
        <h4>1. Test Referral QR Code Generation</h4>
        <input type="number" id="qrReferralId" placeholder="Referral ID" value="1">
        <button onclick="testGenerateQR()">Generate QR Code</button>
        <div id="qrGenerationResult"></div>
        
        <h4>2. Test Get Referral Details with Appointment Info</h4>
        <input type="number" id="referralId" placeholder="Referral ID" value="1">
        <button onclick="testGetReferralDetails()">Test Get Details</button>
        <div id="referralDetailsResult"></div>
        
        <h4>3. Test Check-in API</h4>
        <input type="number" id="checkinReferralId" placeholder="Referral ID" value="1">
        <button onclick="testQuickCheckIn()">Test Quick Check-in</button>
        <button onclick="testQRCheckIn()">Test QR Check-in</button>
        <div id="checkinResult"></div>
    </div>

    <script>
        async function testGenerateQR() {
            const referralId = document.getElementById('qrReferralId').value;
            const resultDiv = document.getElementById('qrGenerationResult');
            
            try {
                const response = await fetch(`../api/generate_referral_qr_code.php?referral_id=${referralId}`);
                const data = await response.json();
                
                if (data.success) {
                    let html = '<div class="success"><h4>QR Code Generated Successfully!</h4>';
                    html += `<p><strong>Verification Code:</strong> ${data.verification_code}</p>`;
                    html += `<p><strong>QR Size:</strong> ${data.qr_size || 'N/A'} bytes</p>`;
                    
                    if (data.referral_info) {
                        html += '<h5>Referral Information:</h5>';
                        html += `<p><strong>Patient:</strong> ${data.referral_info.patient_name}</p>`;
                        html += `<p><strong>Facility:</strong> ${data.referral_info.facility_name}</p>`;
                        if (data.referral_info.scheduled_date) {
                            html += `<p><strong>Appointment:</strong> ${data.referral_info.scheduled_date} ${data.referral_info.scheduled_time || ''}</p>`;
                        }
                    }
                    
                    // Display QR code image
                    if (data.qr_code_url) {
                        html += '<h5>QR Code:</h5>';
                        html += `<img src="${data.qr_code_url}" alt="Referral QR Code" style="border: 1px solid #ddd; max-width: 200px;">`;
                    }
                    
                    html += '</div>';
                    resultDiv.innerHTML = html;
                } else {
                    resultDiv.innerHTML = `<div class="error">QR Generation Failed: ${data.error}</div>`;
                }
            } catch (error) {
                resultDiv.innerHTML = `<div class="error">Network Error: ${error.message}</div>`;
            }
        }

        async function testGetReferralDetails() {
            const referralId = document.getElementById('referralId').value;
            const resultDiv = document.getElementById('referralDetailsResult');
            
            try {
                const response = await fetch(`../api/get_referral_details.php?referral_id=${referralId}`);
                const data = await response.json();
                
                if (data.success) {
                    const referral = data.referral;
                    let html = '<div class="success"><h4>Success! Referral Details Retrieved:</h4>';
                    html += `<p><strong>Patient:</strong> ${referral.patient_name}</p>`;
                    html += `<p><strong>Status:</strong> ${referral.status}</p>`;
                    html += `<p><strong>Destination Type:</strong> ${referral.destination_type}</p>`;
                    
                    if (referral.assigned_doctor_id) {
                        html += '<h5>Appointment Details:</h5>';
                        html += `<p><strong>Assigned Doctor:</strong> ${referral.doctor_name || 'N/A'}</p>`;
                        html += `<p><strong>Appointment Date:</strong> ${referral.scheduled_date || 'N/A'}</p>`;
                        html += `<p><strong>Appointment Time:</strong> ${referral.scheduled_time || 'N/A'}</p>`;
                    }
                    
                    html += '<h5>Full Data:</h5><pre>' + JSON.stringify(referral, null, 2) + '</pre></div>';
                    resultDiv.innerHTML = html;
                } else {
                    resultDiv.innerHTML = `<div class="error">Error: ${data.error || data.message}</div>`;
                }
            } catch (error) {
                resultDiv.innerHTML = `<div class="error">Network Error: ${error.message}</div>`;
            }
        }
        
        async function testQuickCheckIn() {
            const referralId = document.getElementById('checkinReferralId').value;
            const resultDiv = document.getElementById('checkinResult');
            
            try {
                const response = await fetch('../api/referral_checkin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'checkin_appointment',
                        referral_id: referralId,
                        checkin_type: 'quick'
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    let html = '<div class="success"><h4>Quick Check-in Successful!</h4>';
                    html += `<p><strong>Visit ID:</strong> ${data.data.visit_id}</p>`;
                    html += `<p><strong>Queue ID:</strong> ${data.data.queue_id}</p>`;
                    html += `<p><strong>Patient:</strong> ${data.data.patient_name}</p>`;
                    html += `<p><strong>Doctor:</strong> ${data.data.doctor_name}</p>`;
                    html += '</div>';
                    resultDiv.innerHTML = html;
                } else {
                    resultDiv.innerHTML = `<div class="error">Check-in Failed: ${data.message}</div>`;
                }
            } catch (error) {
                resultDiv.innerHTML = `<div class="error">Network Error: ${error.message}</div>`;
            }
        }
        
        async function testQRCheckIn() {
            const referralId = document.getElementById('checkinReferralId').value;
            const patientId = prompt('Enter Patient ID for QR simulation:');
            const resultDiv = document.getElementById('checkinResult');
            
            if (!patientId) {
                resultDiv.innerHTML = '<div class="error">Patient ID is required for QR check-in test</div>';
                return;
            }
            
            try {
                const response = await fetch('../api/referral_checkin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'checkin_appointment',
                        referral_id: referralId,
                        patient_id: patientId,
                        checkin_type: 'qr'
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    let html = '<div class="success"><h4>QR Check-in Successful!</h4>';
                    html += `<p><strong>Visit ID:</strong> ${data.data.visit_id}</p>`;
                    html += `<p><strong>Queue ID:</strong> ${data.data.queue_id}</p>`;
                    html += `<p><strong>Patient:</strong> ${data.data.patient_name}</p>`;
                    html += `<p><strong>Doctor:</strong> ${data.data.doctor_name}</p>`;
                    html += '</div>';
                    resultDiv.innerHTML = html;
                } else {
                    resultDiv.innerHTML = `<div class="error">QR Check-in Failed: ${data.message}</div>`;
                }
            } catch (error) {
                resultDiv.innerHTML = `<div class="error">Network Error: ${error.message}</div>`;
            }
        }
    </script>
</body>
</html>