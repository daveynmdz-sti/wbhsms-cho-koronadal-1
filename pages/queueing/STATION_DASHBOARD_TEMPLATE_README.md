# Station Dashboard Template - WBHSMS CHO Koronadal

## Overview

This template provides a comprehensive, customizable foundation for individual station dashboards with complete queue management functionality. It integrates with the WBHSMS queue management system and provides real-time patient flow control.

## Features

✅ **Complete Queue Management**
- Real-time queue display with patient information
- Call next patient functionality
- Skip/Complete patient actions
- Transfer patients between stations
- Recall skipped patients

✅ **Station Flow Control**
- Normal patient flow: Triage → Consultation → Prescription/Billing → End
- Flexible routing based on station configuration
- Complete visit capability for final stations (Pharmacy)

✅ **Role-Based Access Control**
- Different access levels for Admin, Doctor, Nurse, etc.
- Station-specific permissions
- Secure authentication integration

✅ **Modern UI/UX**
- Responsive design for all devices
- Real-time auto-refresh (30 seconds)
- Keyboard shortcuts for quick actions
- Color-coded status indicators
- Professional medical interface design

## How to Use This Template

### Step 1: Create Station-Specific Dashboard

1. **Copy the template:**
   ```bash
   cp station_dashboard_template.php triage_dashboard.php
   ```

2. **Update the STATION_CONFIGURATION section:**
   ```php
   $STATION_CONFIG = [
       'station_id' => 1,                    // Triage Station 1
       'station_name' => 'Triage Station 1',
       'station_type' => 'triage',           
       'icon' => 'fa-stethoscope',           
       'color_scheme' => [
           'primary' => '#2563eb',           // Blue for Triage
           'secondary' => '#1e40af',         
           'accent' => '#60a5fa'             
       ],
       'allowed_roles' => ['admin', 'nurse', 'doctor'],
       'next_stations' => [                  // Where patients can go next
           'consultation' => ['station_id' => 5, 'name' => 'Primary Care 1', 'icon' => 'fa-user-md'],
           'lab' => ['station_id' => 13, 'name' => 'Laboratory', 'icon' => 'fa-flask'],
           'billing' => ['station_id' => 4, 'name' => 'Billing', 'icon' => 'fa-money-bill']
       ],
       'can_complete_visit' => false,        // Triage cannot complete visits
       'special_functions' => [              
           'record_vitals' => true,          // Triage records vitals
           'view_history' => true,
           'priority_override' => true
       ]
   ];
   ```

### Step 2: Station-Specific Examples

#### **Triage Station Configuration**
```php
$STATION_CONFIG = [
    'station_id' => 1,
    'station_name' => 'Triage Station 1',
    'station_type' => 'triage',
    'icon' => 'fa-stethoscope',
    'color_scheme' => ['primary' => '#2563eb', 'secondary' => '#1e40af', 'accent' => '#60a5fa'],
    'allowed_roles' => ['admin', 'nurse'],
    'next_stations' => [
        'consultation' => ['station_id' => 5, 'name' => 'Primary Care 1', 'icon' => 'fa-user-md']
    ],
    'can_complete_visit' => false,
    'special_functions' => ['record_vitals' => true, 'view_history' => true]
];
```

#### **Primary Care/Consultation Configuration**
```php
$STATION_CONFIG = [
    'station_id' => 5,
    'station_name' => 'Primary Care 1',
    'station_type' => 'consultation',
    'icon' => 'fa-user-md',
    'color_scheme' => ['primary' => '#059669', 'secondary' => '#047857', 'accent' => '#34d399'],
    'allowed_roles' => ['admin', 'doctor'],
    'next_stations' => [
        'lab' => ['station_id' => 13, 'name' => 'Laboratory', 'icon' => 'fa-flask'],
        'pharmacy' => ['station_id' => 14, 'name' => 'Dispensing 1', 'icon' => 'fa-pills'],
        'billing' => ['station_id' => 4, 'name' => 'Billing', 'icon' => 'fa-money-bill']
    ],
    'can_complete_visit' => false,
    'special_functions' => ['view_history' => true, 'clinical_notes' => true]
];
```

#### **Pharmacy Station Configuration**
```php
$STATION_CONFIG = [
    'station_id' => 14,
    'station_name' => 'Dispensing 1',
    'station_type' => 'pharmacy',
    'icon' => 'fa-pills',
    'color_scheme' => ['primary' => '#dc2626', 'secondary' => '#b91c1c', 'accent' => '#f87171'],
    'allowed_roles' => ['admin', 'pharmacist'],
    'next_stations' => [],  // Pharmacy is typically end station
    'can_complete_visit' => true,  // Pharmacy can complete visits
    'special_functions' => ['dispense_medication' => true, 'view_history' => true]
];
```

### Step 3: Patient Flow Examples

#### **Normal Patient Flow:**
```
Check-in → Triage (Station 1) → Consultation (Station 5) → Pharmacy (Station 14) → [Visit Complete]
```

#### **Complex Flow with Lab:**
```
Check-in → Triage (Station 1) → Consultation (Station 5) → Laboratory (Station 13) → Consultation (Station 5) → Pharmacy (Station 14) → [Visit Complete]
```

## File Structure

Create these files for each station:

```
pages/queueing/
├── station_dashboard_template.php     # This template file
├── triage_dashboard.php              # Station 1 (Triage)
├── billing_dashboard.php             # Station 4 (Billing)
├── primary_care_1_dashboard.php      # Station 5 (Primary Care 1)
├── primary_care_2_dashboard.php      # Station 6 (Primary Care 2)
├── dental_dashboard.php              # Station 7 (Dental)
├── laboratory_dashboard.php          # Station 13 (Laboratory)
├── dispensing_1_dashboard.php        # Station 14 (Dispensing 1)
└── dispensing_2_dashboard.php        # Station 15 (Dispensing 2)
```

## Action Buttons Available

### **Core Queue Actions**
- **Call Next Patient** - Moves first waiting patient to "in_progress"
- **Complete Current** - Marks current patient as "completed" 
- **Skip Patient** - Moves current patient to "skipped" status
- **Recall Skipped** - Returns skipped patient to "waiting"

### **Transfer Actions**
- **Transfer to Next Station** - Modal with next station options
- **Complete Visit** - Ends patient visit and appointment (final stations only)

### **Station-Specific Actions**
- **Record Vitals** - Triage stations
- **View History** - All stations
- **Clinical Notes** - Consultation stations
- **Dispense Medication** - Pharmacy stations

## Keyboard Shortcuts

- **Alt + N** - Call Next Patient
- **Alt + C** - Complete Current Patient  
- **Alt + S** - Skip Current Patient
- **Alt + T** - Transfer Patient (opens modal)
- **Escape** - Close modal

## Database Integration

The template uses existing WBHSMS methods:
- `QueueManagementService::updateQueueStatus()` - For status changes
- `QueueManagementService::createQueueEntry()` - For transfers
- `QueueManagementService::getStationQueue()` - For queue display
- `QueueManagementService::getStationStatistics()` - For metrics

## Queue Status Workflow

```
waiting → in_progress → completed
   ↓           ↓
skipped    (transfer to next station)
   ↓
waiting (via recall)
```

## Deployment Steps

1. **Copy template for each station**
2. **Update STATION_CONFIG for each file**
3. **Test with actual patient data**
4. **Deploy to respective station computers**
5. **Train staff on interface**

## URL Access Patterns

```
# Direct station access
/pages/queueing/triage_dashboard.php

# With station parameter (uses template)  
/pages/queueing/station_dashboard_template.php?station_id=1

# From staff assignments
/pages/management/admin/staff-management/staff_assignments.php → [Station Dashboard] link
```

## Color Schemes by Station Type

- **Triage**: Blue (`#2563eb`) - Calming, medical
- **Consultation**: Green (`#059669`) - Growth, health  
- **Laboratory**: Purple (`#7c3aed`) - Science, analysis
- **Pharmacy**: Red (`#dc2626`) - Attention, medication
- **Billing**: Orange (`#ea580c`) - Financial, business
- **Document**: Gray (`#4b5563`) - Administrative

## Security Features

- **Session validation** - Employee login required
- **Role-based access** - Only assigned roles can access
- **SQL injection prevention** - Prepared statements
- **XSS protection** - Input sanitization
- **CSRF protection** - Form tokens (can be added)

## Support & Maintenance

- **Auto-refresh**: 30-second intervals (disabled during modal use)
- **Error handling**: Comprehensive try-catch blocks
- **Logging**: All actions logged via QueueManagementService
- **Mobile responsive**: Works on tablets and phones
- **Cross-browser**: Compatible with modern browsers

## Implementation Checklist

- [ ] Copy template for each needed station
- [ ] Configure station-specific settings
- [ ] Test patient flow between stations  
- [ ] Verify role-based access control
- [ ] Train staff on keyboard shortcuts
- [ ] Set up auto-refresh monitoring
- [ ] Configure backup procedures
- [ ] Document station-specific workflows

This template provides everything needed to create professional, functional station dashboards that integrate seamlessly with your existing WBHSMS queue management system.