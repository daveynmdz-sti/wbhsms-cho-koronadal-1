# Laboratory Order Status Management Fix

## Problem Identified

The laboratory management system was displaying inconsistent status information where:
- Individual lab order items showed as "completed" (4/4 tests completed)
- Progress bars showed 100% completion 
- But the overall lab order status remained as "pending"

This created confusion for users who could see tests were done but orders appeared incomplete.

## Root Cause

The system lacked automatic status propagation from individual lab order items to their parent lab order. When lab technicians completed individual tests, only the `lab_order_items.status` was updated, but the `lab_orders.overall_status` was never recalculated.

## Solution Implemented

### 1. Created Lab Order Status Management Utility

**File:** `utils/LabOrderStatusManager.php`

This utility provides standardized functions to:
- Calculate overall lab order status based on item completion
- Handle status transitions: pending → in_progress → completed
- Support cancelled status when all items are cancelled
- Provide status summary information

**Status Logic:**
- `pending`: No items started/completed
- `in_progress`: Some items completed or in progress  
- `completed`: All items completed
- `cancelled`: All items cancelled

### 2. Updated Upload Processes

**Files Modified:**
- `pages/laboratory-management/upload_lab_result_modal.php`
- `pages/laboratory-management/upload_lab_result.php` 
- `pages/laboratory-management/api/update_lab_item_status.php`

All lab result upload processes now automatically update the parent lab order status after completing individual items.

### 3. Database Schema Enhancement

Added `overall_status` column to `lab_orders` table if it didn't exist, with proper default values and migration of existing data.

### 4. Legacy Data Fix

**File:** `fix_lab_order_status_web.php`

Created a web-based tool to:
- Add `overall_status` column if missing
- Fix existing lab orders with incorrect status
- Migrate legacy 'partial' status to standardized 'in_progress'
- Update all status inconsistencies in batch

### 5. Status Standardization

Replaced inconsistent status names:
- Old: `partial` → New: `in_progress` 
- Maintained backward compatibility with CSS styles
- Unified status calculation logic across all files

## Key Features

### Automatic Status Updates
When a lab technician uploads results for any test item, the system now:
1. Updates the individual item status to 'completed'
2. Recalculates the overall lab order status
3. Updates the parent lab order automatically
4. Reflects changes immediately in the UI

### Smart Status Detection
The utility function intelligently determines status based on:
- **All items completed** → `completed` 
- **Mix of completed/pending** → `in_progress`
- **All items pending** → `pending`
- **All items cancelled** → `cancelled`

### Error Handling
- Graceful degradation if utility functions fail
- Logging of status update failures
- Fallback to manual status if needed

## Files Changed

### Core Logic
- `utils/LabOrderStatusManager.php` (new)
- `pages/laboratory-management/upload_lab_result_modal.php`
- `pages/laboratory-management/upload_lab_result.php`
- `pages/laboratory-management/api/update_lab_item_status.php`

### Database Tools  
- `fix_lab_order_status.php` (CLI version)
- `fix_lab_order_status_web.php` (Web interface)

### UI Display
- `pages/laboratory-management/lab_management.php` (already had correct display logic)

## Usage Instructions

### For Immediate Fix
1. Run the web tool: `http://localhost/wbhsms-cho-koronadal-1/fix_lab_order_status_web.php`
2. This will fix all existing inconsistent lab orders

### For Ongoing Operation
The system now automatically manages status updates when:
- Lab technicians upload results via the modal
- Lab technicians upload results via the main upload page  
- API calls update individual item status
- Any lab order item status changes

### For Developers
Use the utility functions in new code:
```php
require_once 'utils/LabOrderStatusManager.php';

// Update status after changing an item
updateLabOrderStatusFromItem($item_id, $conn);

// Update status for a specific order
updateLabOrderStatus($lab_order_id, $conn);

// Get status summary
$summary = getLabOrderStatusSummary($lab_order_id, $conn);
```

## Benefits

1. **Consistent UI**: Lab orders now show correct status matching their completion
2. **Real-time Updates**: Status changes immediately when results are uploaded
3. **Better UX**: Users see accurate progress and completion status
4. **Standardized Logic**: All status updates use the same calculation method
5. **Maintainable Code**: Centralized status management utility
6. **Data Integrity**: Automatic fixes for existing inconsistent data

## Status Display Examples

| Progress | Individual Items | Overall Status | Badge Color |
|----------|------------------|----------------|-------------|
| 0/4      | All pending      | pending        | Yellow      |
| 2/4      | Mix completion   | in_progress    | Blue        |
| 4/4      | All completed    | completed      | Green       |
| 0/4      | All cancelled    | cancelled      | Red         |

The system now provides accurate, real-time status information that matches user expectations and improves laboratory workflow management.