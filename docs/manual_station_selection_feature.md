# Manual Triage Station Selection Feature

## Overview
The check-in system now includes a **Manual Override** feature that allows check-in staff to manually assign patients to specific triage stations, providing flexibility beyond the automatic load balancing system.

## 🎯 **Key Features Implemented**

### 1. **Station Selection Interface**
- **Visual Station Cards**: Each triage station displayed with current queue information
- **Auto Assignment Option**: Default option using load balancing algorithm
- **Real-time Queue Counts**: Live display of waiting patients at each station
- **Color-coded Status**: Visual indicators for queue load (No Wait, Short Wait, Moderate Wait, Long Wait)

### 2. **Station Assignment Options**

| Option | Description | Use Case |
|--------|-------------|----------|
| **Auto Assignment** | System selects station with shortest queue | Normal operations, balanced load |
| **Triage Station 1** | Primary triage assessment | Specific workflows, staff preferences |
| **Triage Station 2** | Secondary triage assessment | Specialty cases, routing preferences |
| **Triage Station 3** | Tertiary triage assessment | Emergency overflow, special protocols |

### 3. **Real-time Queue Information**

Each station card displays:
- **Queue Count**: Number of patients currently waiting
- **Status Indicator**: Visual representation of wait time
- **Color Coding**: 
  - 🟢 Green (Optimal): No wait or very short queue
  - 🟡 Yellow (Medium): Moderate wait time
  - 🔴 Red (High): Long wait, may want to avoid

## 🔧 **Technical Implementation**

### Backend Changes
1. **Enhanced Check-in Logic** (`checkin_dashboard.php`)
   - Added `triage_station` parameter handling
   - Manual station validation (stations 1-3 only)
   - Fallback to auto assignment for invalid selections

2. **New API Endpoint** (`api/get_queue_counts.php`)
   - Real-time queue count retrieval
   - Station load statistics
   - Optimal station recommendations

3. **Assignment Method Tracking**
   - `manual`: Staff explicitly selected a station
   - `auto`: System used load balancing
   - `auto_fallback`: Invalid manual selection, fell back to auto

### Frontend Enhancements
1. **Station Selection UI**
   - Radio button interface with visual cards
   - Queue information display per station
   - Responsive design for mobile devices

2. **JavaScript Functions**
   - `loadQueueInformation()`: Fetches current queue counts
   - `updateQueueDisplay()`: Updates UI with queue data
   - Enhanced `confirmCheckIn()`: Includes station selection

## 🚀 **Usage Workflow**

### Standard Check-in Process
1. **Scan QR Code** or **Select Appointment**
2. **Verify Patient Information**
3. **Select Priority Level** (Emergency, Urgent, Standard, Low)
4. **Choose Station Assignment**:
   - **Auto Assignment** (Recommended): System selects optimal station
   - **Manual Selection**: Choose specific triage station
5. **Review Queue Information** for selected station
6. **Confirm Check-in**

### Station Selection Decision Matrix

```
Scenario                    Recommended Selection
────────────────────────────────────────────────────
Normal Operation           → Auto Assignment
Staff Request              → Specific Station
Emergency Cases            → Station 1 (if faster)
Specialty Requirements     → Designated Station
Load Balancing Needed      → Auto Assignment
Equipment Issues          → Available Stations Only
```

## 📊 **Queue Information Display**

### Status Indicators
- **No Wait** (0 patients): 🟢 Optimal choice
- **Short Wait** (1-2 patients): 🟢 Good choice  
- **Moderate Wait** (3-5 patients): 🟡 Consider alternatives
- **Long Wait** (6+ patients): 🔴 Avoid if possible

### Auto Assignment Recommendations
The system automatically suggests the optimal station:
> *"Will assign to Station 2 (1 waiting)"*

## ⚡ **Benefits of Manual Override**

### For Check-in Staff
- **Flexibility**: Route patients based on specific needs
- **Staff Preferences**: Honor nurse/doctor station preferences
- **Emergency Routing**: Quickly direct urgent cases to available stations
- **Load Management**: Manual intervention when auto-balancing isn't optimal

### For Patients
- **Reduced Wait Times**: Strategic routing to less busy stations
- **Specialty Care**: Direct routing to appropriate specialist stations
- **Consistency**: Same station for follow-up visits if needed

### For Healthcare Facility
- **Efficiency**: Better resource utilization across stations
- **Quality**: Maintain service levels during peak times
- **Adaptability**: Handle equipment failures or staff shortages
- **Analytics**: Track manual vs. auto assignment patterns

## 🔒 **Validation & Safety Features**

### Input Validation
- **Station Range Check**: Only stations 1-3 accepted
- **Auto Fallback**: Invalid selections default to auto assignment
- **Session Verification**: Employee authentication required

### Error Handling
- **API Failures**: Graceful degradation to "Unable to load" status
- **Network Issues**: Local fallback without breaking check-in
- **Invalid Stations**: Automatic fallback with logging

### Audit Trail
- **Assignment Method Logging**: Track manual vs. auto assignments
- **Staff Attribution**: Log which employee made manual selections
- **Decision Tracking**: Monitor override patterns for optimization

## 🎮 **Example Usage Scenarios**

### Scenario 1: Emergency Case
```
Patient: Emergency chest pain
Staff Action: Select "Triage Station 1" (fastest/most equipped)
Result: "Patient checked in with Emergency priority! 
         Assigned to Triage Station 1 (Manually Selected) - 
         Queue Number: 3 (Code: T1-E3)"
```

### Scenario 2: Normal Case
```
Patient: Routine consultation
Staff Action: Keep "Auto Assignment" selected
Result: "Patient checked in with Standard priority! 
         Assigned to Triage Station 2 (Auto-Assigned) - 
         Queue Number: 5 (Code: T2-N5)"
```

### Scenario 3: Staff Preference
```
Patient: Pediatric case
Staff Action: Select "Triage Station 3" (pediatric specialist on duty)
Result: "Patient checked in with Standard priority! 
         Assigned to Triage Station 3 (Manually Selected) - 
         Queue Number: 2 (Code: T3-N2)"
```

## 📈 **Future Enhancement Opportunities**

### Potential Improvements
1. **Station Specialization**: Configure stations for specific care types
2. **Staff Availability**: Show which stations have staff currently
3. **Equipment Status**: Display station capabilities (X-ray, etc.)
4. **Historical Analytics**: Track manual override effectiveness
5. **Smart Suggestions**: AI-powered station recommendations
6. **Patient Preferences**: Remember preferred stations for return visits

### Integration Possibilities
- **Staff Scheduling System**: Show staff assignments per station
- **Equipment Management**: Real-time equipment availability
- **Patient History**: Route based on previous care providers
- **Performance Metrics**: Track station efficiency and satisfaction

This manual override feature provides the perfect balance between automated efficiency and human decision-making, ensuring optimal patient routing while maintaining operational flexibility.