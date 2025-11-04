# Prescription Panel Movement Test Guide

## Test Scenario: Verify prescriptions automatically move between panels based on medication status

### Prerequisites:
1. Login as a Pharmacist or Admin
2. Have a prescription with multiple medications in "pending" status

### Panel Logic (Updated Implementation):

#### **Left Panel: "Active Prescriptions (Pending Medications)"**
- **Shows**: Prescriptions that have at least one medication with "pending" status
- **Purpose**: For ongoing dispensing work
- **Actions**: "View/Update" button → Opens editable form

#### **Right Panel: "Completed Prescriptions"** 
- **Shows**: Prescriptions where ALL medications are processed (dispensed OR unavailable)
- **Purpose**: Archive of completed work, ready for printing
- **Actions**: "View" button → Opens read-only view

### Test Steps:

#### Step 1: Initial State (Before Processing)
1. Go to Prescription Management
2. Find a prescription with 3+ pending medications
3. **Expected**: Prescription appears in **LEFT panel** only
4. **Expected**: Prescription does NOT appear in right panel

#### Step 2: Partial Processing
1. Open the prescription from left panel
2. Mark 1-2 medications as "Dispensed" or "Unavailable" 
3. Leave at least 1 medication as "Pending"
4. Submit the form
5. **Expected Results**:
   - Prescription REMAINS in **LEFT panel** (still has pending medications)
   - Prescription does NOT appear in right panel yet
   - Success message shown

#### Step 3: Complete Processing (Critical Test)
1. Open the same prescription again from left panel
2. Mark ALL remaining medications as "Dispensed" or "Unavailable"
3. Ensure NO medications remain "Pending"
4. Submit the form
5. **Expected Results**:
   - Prescription DISAPPEARS from **LEFT panel** immediately
   - Prescription APPEARS in **RIGHT panel** 
   - Page should refresh automatically
   - Success message about completion

#### Step 4: Verification Tests
1. Search for the prescription in LEFT panel - should return no results
2. Search for the prescription in RIGHT panel - should find it
3. Click "View" in right panel - should open read-only view
4. Verify no edit controls are available in right panel view

### Visual Indicators:

#### Left Panel:
- Title: "Active Prescriptions (Pending Medications)"
- Info Banner: "Shows prescriptions with at least one pending medication"
- Button: "View/Update" (blue)

#### Right Panel:
- Title: "Completed Prescriptions" 
- Info Banner: "Shows prescriptions where all medications have been processed"
- Button: "View" (primary blue)

### Database Logic (Behind the Scenes):

#### Left Panel Query:
```sql
-- Only show prescriptions that have at least one pending medication
WHERE EXISTS (
    SELECT 1 FROM prescribed_medications pm_pending 
    WHERE pm_pending.prescription_id = p.prescription_id 
    AND pm_pending.status = 'pending'
)
```

#### Right Panel Query:
```sql
-- Only show prescriptions with NO pending medications
WHERE NOT EXISTS (
    SELECT 1 FROM prescribed_medications pm_pending 
    WHERE pm_pending.prescription_id = p.prescription_id 
    AND pm_pending.status = 'pending'
)
AND EXISTS (
    SELECT 1 FROM prescribed_medications pm_exists 
    WHERE pm_exists.prescription_id = p.prescription_id
)
```

### Expected Behavior Summary:
1. **New prescriptions**: Start in LEFT panel
2. **Partially processed**: Stay in LEFT panel  
3. **Fully processed**: Move to RIGHT panel automatically
4. **No double appearances**: Prescription appears in only one panel at a time
5. **Real-time updates**: Panel changes happen immediately after status update

## Benefits of This Implementation:
- ✅ **Clear workflow separation**: Active vs Completed
- ✅ **No manual status management**: Automatic panel movement
- ✅ **Audit integrity**: Completed prescriptions become read-only
- ✅ **Improved efficiency**: Focus on pending work in left panel
- ✅ **Better organization**: Easy to find completed work in right panel