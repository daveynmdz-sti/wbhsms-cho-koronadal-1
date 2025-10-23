# Recent Payments Display Enhancement

## Problem Description
Previously, the Recent Payments panel was displaying the total `amount_paid` field from receipts, which includes change given to customers. This resulted in inaccurate transaction reporting.

**Example Scenario:**
- Bill Amount: ₱500
- Customer Pays: ₱800  
- Change Given: ₱300
- **OLD Display**: ₱800 (misleading - shows total paid including change)
- **NEW Display**: ₱500 (accurate - shows actual amount applied to bill)

## Solution Implemented

### Database Query Changes
Updated the SQL queries to calculate the actual payment applied:
```sql
(r.amount_paid - COALESCE(r.change_amount, 0)) as actual_payment_applied
```

### Display Enhancements
1. **Recent Payments Panel**: Now shows the actual amount applied to the bill
2. **Change Information**: When change was given, it's displayed as additional detail
3. **Statistics**: Daily and monthly collections now reflect actual revenue (excluding change)

### Example Display
```
John Doe
Invoice #123 • Cash
Paid: ₱800 | Change: ₱300     ₱500
                              Oct 22, 2:30 PM
```

### Benefits
1. **Accurate Revenue Reporting**: Collections reflect actual revenue, not cash handled
2. **Transaction Transparency**: Users can see both payment amount and change given
3. **Better Financial Tracking**: Outstanding balances and collection statistics are more accurate
4. **Audit Trail**: Complete payment information is preserved for accounting

### Technical Changes Made
1. Updated `receipts` query to include `change_amount` and calculate `actual_payment_applied`
2. Modified Recent Payments display to show calculated amount
3. Enhanced statistics queries for today's and monthly collections
4. Added conditional display of change information when applicable

This enhancement ensures that the Recent Payments panel accurately reflects the financial impact of each transaction on the healthcare facility's accounts receivable.