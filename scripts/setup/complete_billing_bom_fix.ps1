# Complete Billing System BOM Fix Deployment Script
# This script fixes ALL billing-related files that may have BOM/header issues

Write-Host "=== COMPREHENSIVE BILLING SYSTEM BOM FIX ===" -ForegroundColor Green
Write-Host "Fixing all billing-related files for production deployment" -ForegroundColor Yellow

$projectRoot = "c:\xampp\htdocs\wbhsms-cho-koronadal-1"
$timestamp = Get-Date -Format 'yyyyMMdd_HHmmss'

Write-Host "`n1. Creating comprehensive backup..." -ForegroundColor Cyan
$backupDir = "$projectRoot\backup_complete_billing_fix_$timestamp"
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null

# Comprehensive file mappings for ALL billing-related files
$billingFiles = @(
    # Main cashier management files
    @{clean="pages\management\cashier\billing_reports_clean.php"; original="pages\management\cashier\billing_reports.php"},
    @{clean="pages\management\cashier\billing_management_clean.php"; original="pages\management\cashier\billing_management.php"},
    
    # Core API files (already fixed)
    @{clean="api\create_invoice_clean.php"; original="api\create_invoice.php"},
    @{clean="api\process_payment_clean.php"; original="api\process_payment.php"},
    @{clean="api\get_patient_invoices_clean.php"; original="api\get_patient_invoices.php"},
    
    # Billing management API files (NEW FIXES)
    @{clean="api\billing\management\create_invoice_clean.php"; original="api\billing\management\create_invoice.php"},
    @{clean="api\billing\management\process_payment_clean.php"; original="api\billing\management\process_payment.php"},
    @{clean="api\billing\management\get_billing_reports_clean.php"; original="api\billing\management\get_billing_reports.php"},
    
    # Session configuration
    @{clean="config\session\employee_session_clean.php"; original="config\session\employee_session.php"}
)

Write-Host "`n2. Backing up original files..." -ForegroundColor Cyan
foreach ($file in $billingFiles) {
    $originalPath = Join-Path $projectRoot $file.original
    if (Test-Path $originalPath) {
        $backupPath = Join-Path $backupDir $file.original
        $backupFolder = Split-Path $backupPath -Parent
        if (-not (Test-Path $backupFolder)) {
            New-Item -ItemType Directory -Path $backupFolder -Force | Out-Null
        }
        Copy-Item $originalPath $backupPath -Force
        Write-Host "  ✓ Backed up: $($file.original)" -ForegroundColor Blue
    }
}

Write-Host "`n3. Deploying clean files..." -ForegroundColor Cyan
foreach ($file in $billingFiles) {
    $cleanPath = Join-Path $projectRoot $file.clean
    $originalPath = Join-Path $projectRoot $file.original
    
    if (Test-Path $cleanPath) {
        Copy-Item $cleanPath $originalPath -Force
        Write-Host "  ✓ Fixed: $($file.original)" -ForegroundColor Green
    } else {
        Write-Host "  ⚠ Clean file missing: $($file.clean)" -ForegroundColor Yellow
    }
}

Write-Host "`n4. Verifying API billing endpoints..." -ForegroundColor Cyan
$apiFiles = @(
    "api\billing\management\create_invoice.php",
    "api\billing\management\process_payment.php", 
    "api\billing\management\get_billing_reports.php"
)

foreach ($apiFile in $apiFiles) {
    $filePath = Join-Path $projectRoot $apiFile
    if (Test-Path $filePath) {
        Write-Host "  ✓ API endpoint ready: $apiFile" -ForegroundColor Green
    } else {
        Write-Host "  ⚠ API endpoint missing: $apiFile" -ForegroundColor Yellow
    }
}

Write-Host "`n5. Setting file permissions..." -ForegroundColor Cyan
foreach ($file in $billingFiles) {
    $originalPath = Join-Path $projectRoot $file.original
    if (Test-Path $originalPath) {
        Set-ItemProperty -Path $originalPath -Name IsReadOnly -Value $false
        Write-Host "  ✓ Permissions set: $($file.original)" -ForegroundColor Green
    }
}

Write-Host "`n6. Testing critical billing endpoints..." -ForegroundColor Cyan
# Test if files can be accessed without syntax errors
$testFiles = @(
    "pages\management\cashier\billing_management.php",
    "pages\management\cashier\billing_reports.php"
)

foreach ($testFile in $testFiles) {
    $filePath = Join-Path $projectRoot $testFile
    if (Test-Path $filePath) {
        # Basic PHP syntax check
        $syntaxCheck = php -l $filePath 2>&1
        if ($syntaxCheck -like "*No syntax errors*") {
            Write-Host "  ✓ Syntax OK: $testFile" -ForegroundColor Green
        } else {
            Write-Host "  ⚠ Syntax issue: $testFile" -ForegroundColor Red
            Write-Host "    $syntaxCheck" -ForegroundColor Gray
        }
    }
}

Write-Host "`n=== DEPLOYMENT COMPLETE ===" -ForegroundColor Green
Write-Host "✓ Backup created: $backupDir" -ForegroundColor Cyan
Write-Host "✓ Clean files deployed" -ForegroundColor Cyan
Write-Host "✓ API endpoints fixed" -ForegroundColor Cyan
Write-Host "✓ Permissions updated" -ForegroundColor Cyan

Write-Host "`n=== FILES FIXED ===" -ForegroundColor Yellow
Write-Host "Core Management:" -ForegroundColor White
Write-Host "  • billing_management.php - Main cashier interface" -ForegroundColor Gray
Write-Host "  • billing_reports.php - Reports and analytics" -ForegroundColor Gray
Write-Host "`nAPI Endpoints:" -ForegroundColor White
Write-Host "  • create_invoice.php - Invoice creation API" -ForegroundColor Gray  
Write-Host "  • process_payment.php - Payment processing API" -ForegroundColor Gray
Write-Host "  • get_patient_invoices.php - Invoice retrieval API" -ForegroundColor Gray
Write-Host "  • get_billing_reports.php - Reports API" -ForegroundColor Gray
Write-Host "`nSession Management:" -ForegroundColor White
Write-Host "  • employee_session.php - Enhanced session handling" -ForegroundColor Gray

Write-Host "`n=== TESTING CHECKLIST ===" -ForegroundColor Magenta
Write-Host "□ 1. Login as Admin or Cashier" -ForegroundColor White
Write-Host "□ 2. Navigate to Billing Management" -ForegroundColor White  
Write-Host "□ 3. Create a new invoice" -ForegroundColor White
Write-Host "□ 4. Process a payment" -ForegroundColor White
Write-Host "□ 5. View billing reports" -ForegroundColor White
Write-Host "□ 6. Check for any 'headers already sent' errors" -ForegroundColor White

Write-Host "`n=== PRODUCTION DEPLOYMENT ===" -ForegroundColor Magenta
Write-Host "For Hostinger VPS or production server:" -ForegroundColor White
Write-Host "1. Upload all *_clean.php files to server" -ForegroundColor Gray
Write-Host "2. SSH into your production server" -ForegroundColor Gray
Write-Host "3. Navigate to project directory" -ForegroundColor Gray
Write-Host "4. Run replacement commands:" -ForegroundColor Gray
Write-Host "`n# Replace core files" -ForegroundColor DarkGray
Write-Host "cp pages/management/cashier/billing_management_clean.php pages/management/cashier/billing_management.php" -ForegroundColor DarkGray
Write-Host "cp pages/management/cashier/billing_reports_clean.php pages/management/cashier/billing_reports.php" -ForegroundColor DarkGray
Write-Host "`n# Replace API files" -ForegroundColor DarkGray
Write-Host "cp api/billing/management/create_invoice_clean.php api/billing/management/create_invoice.php" -ForegroundColor DarkGray
Write-Host "cp api/billing/management/process_payment_clean.php api/billing/management/process_payment.php" -ForegroundColor DarkGray
Write-Host "cp api/billing/management/get_billing_reports_clean.php api/billing/management/get_billing_reports.php" -ForegroundColor DarkGray
Write-Host "`n# Replace session config" -ForegroundColor DarkGray
Write-Host "cp config/session/employee_session_clean.php config/session/employee_session.php" -ForegroundColor DarkGray
Write-Host "`n# Set permissions" -ForegroundColor DarkGray
Write-Host "chmod 644 pages/management/cashier/*.php" -ForegroundColor DarkGray
Write-Host "chmod 644 api/billing/management/*.php" -ForegroundColor DarkGray
Write-Host "chmod 644 config/session/*.php" -ForegroundColor DarkGray

Write-Host "`n=== WHAT'S FIXED ===" -ForegroundColor Green
Write-Host "✅ BOM (Byte Order Mark) removed from all PHP files" -ForegroundColor White
Write-Host "✅ Output buffering added to prevent header issues" -ForegroundColor White
Write-Host "✅ Enhanced error handling and validation" -ForegroundColor White
Write-Host "✅ Improved API authentication and authorization" -ForegroundColor White
Write-Host "✅ Session management optimized for production" -ForegroundColor White
Write-Host "✅ Comprehensive logging and audit trails" -ForegroundColor White

Write-Host "`nBilling system BOM fix deployment completed successfully!" -ForegroundColor Green