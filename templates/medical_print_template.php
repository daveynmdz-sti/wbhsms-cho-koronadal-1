<?php
// templates/medical_print_template.php
// Accepts $reportData (array) and $mode ('compact'|'verbose') and prints HTML for preview/printing.
// Implements section rendering and escaping for safety.

// Ensure we have the required data
if (!isset($reportData) || !is_array($reportData)) {
    $reportData = [];
}

// Set default mode if not provided
if (!isset($mode)) {
    $mode = 'verbose';
}

// Validate mode
$mode = in_array($mode, ['compact', 'verbose']) ? $mode : 'verbose';

// Extract patient and metadata
$patient = $reportData['patient'] ?? [];
$sections = $reportData['sections'] ?? [];
$medicalRecord = $reportData['medical_record'] ?? [];
$metadata = $reportData['metadata'] ?? [];
$filters = $reportData['filters'] ?? [];

// Generate patient name
$patientName = trim(
    ($patient['first_name'] ?? '') . ' ' . 
    ($patient['middle_name'] ?? '') . ' ' . 
    ($patient['last_name'] ?? '')
);

// Calculate age if date of birth is available
$age = 'N/A';
if (!empty($medicalRecord['basic']['date_of_birth'])) {
    $birthDate = new DateTime($medicalRecord['basic']['date_of_birth']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
}

// Date formatting
$generatedDate = date('F d, Y h:i A');
$reportDateRange = '';
if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
    $fromDate = !empty($filters['date_from']) ? date('M d, Y', strtotime($filters['date_from'])) : 'Beginning';
    $toDate = !empty($filters['date_to']) ? date('M d, Y', strtotime($filters['date_to'])) : 'Present';
    $reportDateRange = "Report Period: {$fromDate} - {$toDate}";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Record - <?php echo htmlspecialchars($patientName); ?></title>
    <style>
        /* Print-optimized CSS */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            line-height: 1.6;
            color: #333;
            background: white;
        }
        
        .medical-record {
            max-width: 800px;
            margin: 0 auto;
        }
        
        /* Header Styles */
        .record-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 3px solid #2c5aa0;
            padding-bottom: 20px;
        }
        
        .header-logo {
            margin-bottom: 15px;
        }
        
        .header-title {
            color: #2c5aa0;
            font-size: 2rem;
            font-weight: bold;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .header-subtitle {
            color: #666;
            font-size: 1.2rem;
            margin: 5px 0;
            font-weight: normal;
        }
        
        .header-facility {
            color: #888;
            font-size: 1rem;
            margin: 0;
        }
        
        /* Patient Info Banner */
        .patient-banner {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 2px solid #2c5aa0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .patient-name {
            font-size: 1.5rem;
            font-weight: bold;
            color: #2c5aa0;
            margin: 0 0 10px 0;
        }
        
        .patient-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .patient-detail {
            text-align: center;
        }
        
        .detail-label {
            font-size: 0.9rem;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .detail-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }
        
        /* Section Styles */
        .medical-section {
            margin-bottom: 35px;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        
        .section-header {
            background: #2c5aa0;
            color: white;
            padding: 12px 20px;
            margin: 0 0 20px 0;
            border-radius: 8px 8px 0 0;
            position: relative;
        }
        
        .section-title {
            font-size: 1.2rem;
            font-weight: bold;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-icon {
            font-size: 1.1rem;
        }
        
        .section-content {
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 8px 8px;
            padding: 20px;
            background: white;
        }
        
        /* Data Display Styles */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .info-label {
            font-size: 0.9rem;
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            font-size: 1rem;
            color: #333;
            font-weight: normal;
        }
        
        /* Table Styles */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 0.9rem;
        }
        
        .data-table th,
        .data-table td {
            border: 1px solid #ddd;
            padding: 10px 12px;
            text-align: left;
            vertical-align: top;
        }
        
        .data-table th {
            background: #f8f9fa;
            font-weight: bold;
            color: #2c5aa0;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .data-table tr:nth-child(even) {
            background: #f9f9f9;
        }
        
        .data-table tr:hover {
            background: #f0f4f7;
        }
        
        /* List Styles */
        .record-list {
            margin: 15px 0;
        }
        
        .record-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 10px;
        }
        
        .record-item:last-child {
            margin-bottom: 0;
        }
        
        .record-date {
            font-size: 0.9rem;
            color: #666;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .record-content {
            font-size: 0.95rem;
            line-height: 1.5;
        }
        
        .record-content strong {
            color: #2c5aa0;
        }
        
        /* Medication Styles */
        .medication-item {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 8px;
        }
        
        .medication-name {
            font-weight: bold;
            color: #856404;
            font-size: 1rem;
            margin-bottom: 5px;
        }
        
        .medication-details {
            font-size: 0.9rem;
            color: #666;
        }
        
        /* Alert Styles */
        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin: 15px 0;
            font-size: 0.9rem;
        }
        
        .alert-warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }
        
        .alert-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        
        .no-data {
            text-align: center;
            color: #999;
            font-style: italic;
            padding: 30px;
            background: #f8f9fa;
            border-radius: 6px;
            margin: 15px 0;
        }
        
        /* Compact Mode Styles */
        .compact .section-content {
            padding: 15px;
        }
        
        .compact .info-grid {
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        
        .compact .record-item {
            padding: 10px;
            margin-bottom: 8px;
        }
        
        .compact .data-table {
            font-size: 0.85rem;
        }
        
        .compact .data-table th,
        .compact .data-table td {
            padding: 8px 10px;
        }
        
        /* Footer */
        .record-footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #eee;
            text-align: center;
            color: #666;
            font-size: 0.9rem;
        }
        
        .generation-info {
            margin-bottom: 10px;
        }
        
        .facility-info {
            font-size: 0.85rem;
            color: #888;
        }
        
        /* Print Styles */
        @media print {
            body {
                margin: 0;
                padding: 15px;
            }
            
            .medical-section {
                page-break-inside: avoid;
                margin-bottom: 25px;
            }
            
            .section-header {
                background: #2c5aa0 !important;
                color: white !important;
            }
            
            .patient-banner {
                background: #f8f9fa !important;
                border: 2px solid #2c5aa0 !important;
            }
            
            .record-footer {
                position: fixed;
                bottom: 20px;
                width: 100%;
                left: 0;
                padding: 0 20px;
            }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .patient-details {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .data-table {
                font-size: 0.8rem;
            }
            
            .data-table th,
            .data-table td {
                padding: 8px 6px;
            }
        }
    </style>
</head>
<body>
    <div class="medical-record <?php echo $mode; ?>">
        <!-- Header -->
        <div class="record-header">
            <div class="header-logo">
                <!-- Logo placeholder - replace with actual logo -->
                <div style="width: 80px; height: 80px; background: #2c5aa0; border-radius: 50%; margin: 0 auto; display: flex; align-items: center; justify-content: center; color: white; font-size: 2rem; font-weight: bold;">CHO</div>
            </div>
            <h1 class="header-title">Medical Record</h1>
            <h2 class="header-subtitle">Comprehensive Patient Report</h2>
            <p class="header-facility">City Health Office - Koronadal City</p>
        </div>

        <!-- Patient Banner -->
        <div class="patient-banner">
            <h2 class="patient-name"><?php echo htmlspecialchars($patientName); ?></h2>
            <?php if (!empty($reportDateRange)): ?>
                <div class="alert alert-info"><?php echo htmlspecialchars($reportDateRange); ?></div>
            <?php endif; ?>
            
            <div class="patient-details">
                <?php if (!empty($patient['patient_id'])): ?>
                    <div class="patient-detail">
                        <div class="detail-label">Patient ID</div>
                        <div class="detail-value"><?php echo htmlspecialchars($patient['patient_id']); ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($medicalRecord['basic']['date_of_birth'])): ?>
                    <div class="patient-detail">
                        <div class="detail-label">Age</div>
                        <div class="detail-value"><?php echo htmlspecialchars($age); ?> years</div>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($medicalRecord['basic']['gender'])): ?>
                    <div class="patient-detail">
                        <div class="detail-label">Gender</div>
                        <div class="detail-value"><?php echo htmlspecialchars($medicalRecord['basic']['gender']); ?></div>
                    </div>
                <?php endif; ?>
                
                <div class="patient-detail">
                    <div class="detail-label">Generated</div>
                    <div class="detail-value"><?php echo $generatedDate; ?></div>
                </div>
            </div>
        </div>

        <!-- Medical Record Sections -->
        <?php
        // Define section configurations
        $sectionConfig = [
            'basic' => [
                'title' => 'Patient Information',
                'icon' => '👤',
                'function' => 'renderBasicInformation'
            ],
            'personal_information' => [
                'title' => 'Personal Details',
                'icon' => '📋',
                'function' => 'renderPersonalInformation'
            ],
            'emergency_contacts' => [
                'title' => 'Emergency Contacts',
                'icon' => '🚨',
                'function' => 'renderEmergencyContacts'
            ],
            'lifestyle_information' => [
                'title' => 'Lifestyle Information',
                'icon' => '🏃',
                'function' => 'renderLifestyleInformation'
            ],
            'past_medical_conditions' => [
                'title' => 'Past Medical Conditions',
                'icon' => '📖',
                'function' => 'renderPastMedicalConditions'
            ],
            'chronic_illnesses' => [
                'title' => 'Chronic Illnesses',
                'icon' => '⚕️',
                'function' => 'renderChronicIllnesses'
            ],
            'immunizations' => [
                'title' => 'Immunizations',
                'icon' => '💉',
                'function' => 'renderImmunizations'
            ],
            'family_history' => [
                'title' => 'Family History',
                'icon' => '👨‍👩‍👧‍👦',
                'function' => 'renderFamilyHistory'
            ],
            'surgical_history' => [
                'title' => 'Surgical History',
                'icon' => '🏥',
                'function' => 'renderSurgicalHistory'
            ],
            'allergies' => [
                'title' => 'Allergies',
                'icon' => '⚠️',
                'function' => 'renderAllergies'
            ],
            'current_medications' => [
                'title' => 'Current Medications',
                'icon' => '💊',
                'function' => 'renderCurrentMedications'
            ],
            'consultations' => [
                'title' => 'Consultations',
                'icon' => '🩺',
                'function' => 'renderConsultations'
            ],
            'appointments' => [
                'title' => 'Appointments',
                'icon' => '📅',
                'function' => 'renderAppointments'
            ],
            'referrals' => [
                'title' => 'Referrals',
                'icon' => '🔄',
                'function' => 'renderReferrals'
            ],
            'prescriptions' => [
                'title' => 'Prescriptions',
                'icon' => '📄',
                'function' => 'renderPrescriptions'
            ],
            'lab_orders' => [
                'title' => 'Laboratory Orders',
                'icon' => '🧪',
                'function' => 'renderLabOrders'
            ],
            'billing' => [
                'title' => 'Billing & Payments',
                'icon' => '💰',
                'function' => 'renderBilling'
            ]
        ];

        // Render each selected section
        foreach ($sections as $sectionKey) {
            if (!isset($sectionConfig[$sectionKey]) || !isset($medicalRecord[$sectionKey])) {
                continue;
            }
            
            $config = $sectionConfig[$sectionKey];
            $data = $medicalRecord[$sectionKey];
            
            echo '<div class="medical-section">';
            echo '<div class="section-header">';
            echo '<h3 class="section-title">';
            echo '<span class="section-icon">' . $config['icon'] . '</span>';
            echo htmlspecialchars($config['title']);
            echo '</h3>';
            echo '</div>';
            echo '<div class="section-content">';
            
            // Call the appropriate rendering function
            $functionName = $config['function'];
            if (function_exists($functionName)) {
                $functionName($data, $mode);
            } else {
                renderGenericSection($data, $mode);
            }
            
            echo '</div>';
            echo '</div>';
        }
        ?>

        <!-- Footer -->
        <div class="record-footer">
            <div class="generation-info">
                <strong>Document Generated:</strong> <?php echo $generatedDate; ?>
                <?php if (!empty($metadata['generated_by']['role'])): ?>
                    | <strong>Generated by:</strong> <?php echo htmlspecialchars($metadata['generated_by']['role']); ?>
                <?php endif; ?>
            </div>
            <div class="facility-info">
                This document was generated electronically by the Web-Based Healthcare Services Management System<br>
                City Health Office, Koronadal City, South Cotabato
            </div>
        </div>
    </div>
</body>
</html>

<?php
// Rendering Functions

function renderBasicInformation($data, $mode) {
    if (empty($data)) {
        echo '<div class="no-data">No basic information available</div>';
        return;
    }
    
    $age = '';
    if (!empty($data['date_of_birth'])) {
        $birthDate = new DateTime($data['date_of_birth']);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y . ' years';
    }
    
    $dob = !empty($data['date_of_birth']) ? date('F d, Y', strtotime($data['date_of_birth'])) : 'N/A';
    
    echo '<div class="info-grid">';
    
    $fields = [
        'Full Name' => trim(($data['first_name'] ?? '') . ' ' . ($data['middle_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
        'Date of Birth' => $dob,
        'Age' => $age ?: 'N/A',
        'Gender' => $data['gender'] ?? 'N/A',
        'Contact Number' => $data['contact_number'] ?? 'N/A',
        'Email' => $data['email'] ?? 'N/A',
        'PhilHealth ID' => $data['philhealth_id'] ?? 'N/A',
        'Address' => $data['address'] ?? 'N/A',
        'Barangay' => $data['barangay'] ?? 'N/A',
        'Municipality' => $data['municipality'] ?? 'N/A',
        'Province' => $data['province'] ?? 'N/A'
    ];
    
    foreach ($fields as $label => $value) {
        if ($mode === 'compact' && empty($value) || $value === 'N/A') continue;
        
        echo '<div class="info-item">';
        echo '<div class="info-label">' . htmlspecialchars($label) . '</div>';
        echo '<div class="info-value">' . htmlspecialchars($value) . '</div>';
        echo '</div>';
    }
    
    echo '</div>';
}

function renderPersonalInformation($data, $mode) {
    if (empty($data)) {
        echo '<div class="no-data">No personal information available</div>';
        return;
    }
    
    echo '<div class="info-grid">';
    
    $fields = [
        'Civil Status' => $data['civil_status'] ?? 'N/A',
        'Occupation' => $data['occupation'] ?? 'N/A',
        'Educational Attainment' => $data['educational_attainment'] ?? 'N/A',
        'Religion' => $data['religion'] ?? 'N/A',
        'Nationality' => $data['nationality'] ?? 'N/A',
        'Blood Type' => $data['blood_type'] ?? 'N/A'
    ];
    
    foreach ($fields as $label => $value) {
        if ($mode === 'compact' && ($value === 'N/A' || empty($value))) continue;
        
        echo '<div class="info-item">';
        echo '<div class="info-label">' . htmlspecialchars($label) . '</div>';
        echo '<div class="info-value">' . htmlspecialchars($value) . '</div>';
        echo '</div>';
    }
    
    echo '</div>';
}

function renderEmergencyContacts($data, $mode) {
    if (empty($data)) {
        echo '<div class="no-data">No emergency contacts on file</div>';
        return;
    }
    
    if ($mode === 'compact') {
        echo '<div class="record-list">';
        foreach ($data as $contact) {
            echo '<div class="record-item">';
            echo '<strong>' . htmlspecialchars($contact['contact_name'] ?? '') . '</strong> ';
            echo '(' . htmlspecialchars($contact['relationship'] ?? '') . ') - ';
            echo htmlspecialchars($contact['contact_number'] ?? '');
            echo '</div>';
        }
        echo '</div>';
    } else {
        echo '<table class="data-table">';
        echo '<thead><tr><th>Name</th><th>Relationship</th><th>Contact Number</th><th>Address</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($data as $contact) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($contact['contact_name'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($contact['relationship'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($contact['contact_number'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($contact['address'] ?? '') . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
}

function renderAllergies($data, $mode) {
    if (empty($data)) {
        echo '<div class="no-data">No known allergies</div>';
        return;
    }
    
    if ($mode === 'compact') {
        echo '<div class="record-list">';
        foreach ($data as $allergy) {
            echo '<div class="record-item">';
            echo '<strong>' . htmlspecialchars($allergy['allergen'] ?? '') . '</strong> - ';
            echo htmlspecialchars($allergy['reaction'] ?? '') . ' ';
            echo '<span style="color: #dc3545;">(' . htmlspecialchars($allergy['severity'] ?? '') . ')</span>';
            echo '</div>';
        }
        echo '</div>';
    } else {
        echo '<table class="data-table">';
        echo '<thead><tr><th>Allergen</th><th>Reaction</th><th>Severity</th><th>Notes</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($data as $allergy) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($allergy['allergen'] ?? '') . '</td>';
            echo '<td>' . htmlspecialchars($allergy['reaction'] ?? '') . '</td>';
            echo '<td><span style="color: #dc3545; font-weight: bold;">' . htmlspecialchars($allergy['severity'] ?? '') . '</span></td>';
            echo '<td>' . htmlspecialchars($allergy['notes'] ?? '') . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
}

function renderCurrentMedications($data, $mode) {
    if (empty($data)) {
        echo '<div class="no-data">No current medications</div>';
        return;
    }
    
    echo '<div class="record-list">';
    foreach ($data as $medication) {
        echo '<div class="medication-item">';
        echo '<div class="medication-name">' . htmlspecialchars($medication['medication_name'] ?? '') . '</div>';
        echo '<div class="medication-details">';
        echo '<strong>Dosage:</strong> ' . htmlspecialchars($medication['dosage'] ?? '') . ' | ';
        echo '<strong>Frequency:</strong> ' . htmlspecialchars($medication['frequency'] ?? '');
        
        if ($mode === 'verbose' && !empty($medication['notes'])) {
            echo '<br><strong>Notes:</strong> ' . htmlspecialchars($medication['notes']);
        }
        
        if (!empty($medication['start_date'])) {
            echo '<br><small>Started: ' . date('M d, Y', strtotime($medication['start_date'])) . '</small>';
        }
        
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
}

function renderConsultations($data, $mode) {
    if (empty($data)) {
        echo '<div class="no-data">No consultation records</div>';
        return;
    }
    
    $limit = $mode === 'compact' ? 5 : count($data);
    $consultations = array_slice($data, 0, $limit);
    
    echo '<div class="record-list">';
    foreach ($consultations as $consultation) {
        $date = date('F d, Y h:i A', strtotime($consultation['consultation_date']));
        $doctor = trim(($consultation['doctor_first_name'] ?? '') . ' ' . ($consultation['doctor_last_name'] ?? ''));
        
        echo '<div class="record-item">';
        echo '<div class="record-date">' . $date;
        if ($doctor) {
            echo ' - Dr. ' . htmlspecialchars($doctor);
        }
        echo '</div>';
        
        echo '<div class="record-content">';
        echo '<strong>Chief Complaint:</strong> ' . htmlspecialchars($consultation['chief_complaint'] ?? '');
        
        if ($mode === 'verbose') {
            if (!empty($consultation['assessment'])) {
                echo '<br><strong>Assessment:</strong> ' . htmlspecialchars($consultation['assessment']);
            }
            if (!empty($consultation['plan'])) {
                echo '<br><strong>Plan:</strong> ' . htmlspecialchars($consultation['plan']);
            }
        }
        
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
    
    if ($mode === 'compact' && count($data) > $limit) {
        echo '<div class="alert alert-info">Showing ' . $limit . ' of ' . count($data) . ' consultations. Switch to verbose mode for complete list.</div>';
    }
}

function renderPrescriptions($data, $mode) {
    if (empty($data)) {
        echo '<div class="no-data">No prescription records</div>';
        return;
    }
    
    $limit = $mode === 'compact' ? 3 : count($data);
    $prescriptions = array_slice($data, 0, $limit);
    
    echo '<div class="record-list">';
    foreach ($prescriptions as $prescription) {
        $date = date('F d, Y', strtotime($prescription['prescription_date']));
        $doctor = trim(($prescription['doctor_first_name'] ?? '') . ' ' . ($prescription['doctor_last_name'] ?? ''));
        
        echo '<div class="record-item">';
        echo '<div class="record-date">Prescription - ' . $date;
        if ($doctor) {
            echo ' - Dr. ' . htmlspecialchars($doctor);
        }
        echo '</div>';
        
        if (!empty($prescription['medications'])) {
            echo '<div class="record-content">';
            foreach ($prescription['medications'] as $med) {
                echo '<div class="medication-item" style="margin: 5px 0;">';
                echo '<strong>' . htmlspecialchars($med['medication_name'] ?? '') . '</strong><br>';
                echo 'Dosage: ' . htmlspecialchars($med['dosage'] ?? '') . ' | ';
                echo 'Frequency: ' . htmlspecialchars($med['frequency'] ?? '');
                
                if ($mode === 'verbose' && !empty($med['duration'])) {
                    echo ' | Duration: ' . htmlspecialchars($med['duration']);
                }
                
                if ($mode === 'verbose' && !empty($med['instructions'])) {
                    echo '<br>Instructions: ' . htmlspecialchars($med['instructions']);
                }
                
                echo '</div>';
            }
            echo '</div>';
        }
        
        echo '</div>';
    }
    echo '</div>';
    
    if ($mode === 'compact' && count($data) > $limit) {
        echo '<div class="alert alert-info">Showing ' . $limit . ' of ' . count($data) . ' prescriptions. Switch to verbose mode for complete list.</div>';
    }
}

function renderLabOrders($data, $mode) {
    if (empty($data)) {
        echo '<div class="no-data">No laboratory orders</div>';
        return;
    }
    
    $limit = $mode === 'compact' ? 3 : count($data);
    $orders = array_slice($data, 0, $limit);
    
    echo '<div class="record-list">';
    foreach ($orders as $order) {
        $date = date('F d, Y', strtotime($order['order_date']));
        $doctor = trim(($order['doctor_first_name'] ?? '') . ' ' . ($order['doctor_last_name'] ?? ''));
        
        echo '<div class="record-item">';
        echo '<div class="record-date">Lab Order - ' . $date;
        if ($doctor) {
            echo ' - Dr. ' . htmlspecialchars($doctor);
        }
        echo ' | Status: <strong>' . htmlspecialchars($order['status'] ?? '') . '</strong>';
        echo '</div>';
        
        if (!empty($order['items'])) {
            if ($mode === 'compact') {
                echo '<div class="record-content">';
                $testNames = array_column($order['items'], 'test_name');
                echo 'Tests: ' . implode(', ', array_map('htmlspecialchars', $testNames));
                echo '</div>';
            } else {
                echo '<table class="data-table" style="margin-top: 10px;">';
                echo '<thead><tr><th>Test</th><th>Result</th><th>Normal Range</th><th>Status</th></tr></thead>';
                echo '<tbody>';
                
                foreach ($order['items'] as $item) {
                    echo '<tr>';
                    echo '<td>' . htmlspecialchars($item['test_name'] ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars($item['result'] ?? 'Pending') . '</td>';
                    echo '<td>' . htmlspecialchars($item['normal_range'] ?? '') . '</td>';
                    echo '<td>' . htmlspecialchars($item['status'] ?? '') . '</td>';
                    echo '</tr>';
                }
                
                echo '</tbody>';
                echo '</table>';
            }
        }
        
        echo '</div>';
    }
    echo '</div>';
    
    if ($mode === 'compact' && count($data) > $limit) {
        echo '<div class="alert alert-info">Showing ' . $limit . ' of ' . count($data) . ' lab orders. Switch to verbose mode for complete list.</div>';
    }
}

// Generic rendering functions for other sections
function renderLifestyleInformation($data, $mode) {
    renderGenericSection($data, $mode);
}

function renderPastMedicalConditions($data, $mode) {
    renderGenericSection($data, $mode);
}

function renderChronicIllnesses($data, $mode) {
    renderGenericSection($data, $mode);
}

function renderImmunizations($data, $mode) {
    renderGenericSection($data, $mode);
}

function renderFamilyHistory($data, $mode) {
    renderGenericSection($data, $mode);
}

function renderSurgicalHistory($data, $mode) {
    renderGenericSection($data, $mode);
}

function renderAppointments($data, $mode) {
    renderGenericSection($data, $mode);
}

function renderReferrals($data, $mode) {
    renderGenericSection($data, $mode);
}

function renderBilling($data, $mode) {
    renderGenericSection($data, $mode);
}

function renderGenericSection($data, $mode) {
    if (empty($data)) {
        echo '<div class="no-data">No data available</div>';
        return;
    }
    
    if (!is_array($data)) {
        echo '<div class="info-value">' . htmlspecialchars($data) . '</div>';
        return;
    }
    
    // Handle single record vs array of records
    if (isset($data[0]) && is_array($data[0])) {
        // Array of records - render as table
        $limit = $mode === 'compact' ? 5 : count($data);
        $records = array_slice($data, 0, $limit);
        
        if (!empty($records)) {
            echo '<table class="data-table">';
            
            // Table headers
            echo '<thead><tr>';
            foreach (array_keys($records[0]) as $key) {
                if ($key === 'id' || $key === 'patient_id') continue;
                echo '<th>' . htmlspecialchars(ucwords(str_replace('_', ' ', $key))) . '</th>';
            }
            echo '</tr></thead>';
            
            // Table body
            echo '<tbody>';
            foreach ($records as $record) {
                echo '<tr>';
                foreach ($record as $key => $value) {
                    if ($key === 'id' || $key === 'patient_id') continue;
                    
                    // Format dates
                    if (strpos($key, 'date') !== false && $value) {
                        $value = date('M d, Y', strtotime($value));
                    }
                    
                    echo '<td>' . htmlspecialchars($value ?? '') . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            
            if ($mode === 'compact' && count($data) > $limit) {
                echo '<div class="alert alert-info">Showing ' . $limit . ' of ' . count($data) . ' records. Switch to verbose mode for complete list.</div>';
            }
        }
    } else {
        // Single record - render as info grid
        echo '<div class="info-grid">';
        foreach ($data as $key => $value) {
            if ($key === 'id' || $key === 'patient_id') continue;
            if ($mode === 'compact' && empty($value)) continue;
            
            // Format dates
            if (strpos($key, 'date') !== false && $value) {
                $value = date('M d, Y', strtotime($value));
            }
            
            echo '<div class="info-item">';
            echo '<div class="info-label">' . htmlspecialchars(ucwords(str_replace('_', ' ', $key))) . '</div>';
            echo '<div class="info-value">' . htmlspecialchars($value ?? 'N/A') . '</div>';
            echo '</div>';
        }
        echo '</div>';
    }
}
?>