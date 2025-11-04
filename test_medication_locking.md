# Medication Status Locking Test Guide

## Test Scenario: Verify medication status locking after first update

### Prerequisites:
1. Login as a Pharmacist or Admin
2. Have a prescription with multiple medications in "pending" status

### Test Steps:

#### Step 1: Initial Status Update
1. Go to Prescription Management
2. Open a prescription with pending medications
3. Mark some medications as "Dispensed" and others as "Unavailable"
4. Submit the form
5. **Expected Result**: Update should succeed

#### Step 2: Attempt Second Update (Should Fail)
1. Reopen the same prescription
2. Try to change the status of previously processed medications
3. **Expected Results**:
   - Checkboxes for processed medications should be **disabled**
   - Lock icons should appear next to processed medications
   - Attempting to click disabled checkboxes should show error message
   - Only pending medications should remain editable

#### Step 3: API Level Protection Test
1. If somehow a request is made to update processed medications via API
2. **Expected Result**: API should return error message about audit integrity

### Visual Indicators:
- ✅ **Pending medications**: Enabled checkboxes, no lock icon
- 🔒 **Processed medications**: Disabled checkboxes, lock icon, grayed out
- ⚠️ **Information banner**: Shows warning about one-time update policy

### Error Messages Expected:
- Frontend: "This medication has already been processed and cannot be changed. Medication statuses can only be set once for audit integrity."
- API: "Cannot update medication statuses. The following medications have already been processed and cannot be changed: [medication list]. Medication statuses can only be set once for audit integrity."

## Security Benefits:
1. **Audit Integrity**: Prevents tampering with dispensing records
2. **Compliance**: Maintains proper medication tracking
3. **Accountability**: Ensures first decision is final
4. **Data Quality**: Prevents confusion from status changes

## Implementation Summary:
- ✅ Backend API validation
- ✅ Frontend UI restrictions  
- ✅ Visual feedback with lock icons
- ✅ Clear error messaging
- ✅ Informational guidance for users