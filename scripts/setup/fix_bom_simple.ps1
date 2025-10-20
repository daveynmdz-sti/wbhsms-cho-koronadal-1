# Production BOM Fix Deployment Script
# Run this script to fix header/session issues caused by BOM (Byte Order Mark) in PHP files

Write-Host "=== WBHSMS Production BOM Fix Deployment ===" -ForegroundColor Green
Write-Host "This script will fix 'headers already sent' issues in production" -ForegroundColor Yellow

$projectRoot = "c:\xampp\htdocs\wbhsms-cho-koronadal-1"

Write-Host "`n1. Creating backup directory..." -ForegroundColor Cyan
$backupDir = "$projectRoot\backup_bom_fix_$(Get-Date -Format 'yyyyMMdd_HHmmss')"
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
Write-Host "  ✓ Backup directory created: $backupDir" -ForegroundColor Green

Write-Host "`n2. Deploying clean files..." -ForegroundColor Cyan

# File mappings
$files = @(
    @{clean="pages\management\cashier\billing_reports_clean.php"; original="pages\management\cashier\billing_reports.php"},
    @{clean="pages\management\cashier\billing_management_clean.php"; original="pages\management\cashier\billing_management.php"},
    @{clean="api\create_invoice_clean.php"; original="api\create_invoice.php"},
    @{clean="api\process_payment_clean.php"; original="api\process_payment.php"},
    @{clean="api\get_patient_invoices_clean.php"; original="api\get_patient_invoices.php"},
    @{clean="config\session\employee_session_clean.php"; original="config\session\employee_session.php"}
)

foreach ($file in $files) {
    $cleanPath = Join-Path $projectRoot $file.clean
    $originalPath = Join-Path $projectRoot $file.original
    
    # Backup original if exists
    if (Test-Path $originalPath) {
        $backupPath = Join-Path $backupDir $file.original
        $backupFolder = Split-Path $backupPath -Parent
        if (-not (Test-Path $backupFolder)) {
            New-Item -ItemType Directory -Path $backupFolder -Force | Out-Null
        }
        Copy-Item $originalPath $backupPath -Force
        Write-Host "  ✓ Backed up: $($file.original)" -ForegroundColor Blue
    }
    
    # Deploy clean version
    if (Test-Path $cleanPath) {
        Copy-Item $cleanPath $originalPath -Force
        Write-Host "  ✓ Deployed: $($file.original)" -ForegroundColor Green
    } else {
        Write-Host "  ⚠ Clean file not found: $($file.clean)" -ForegroundColor Yellow
    }
}

Write-Host "`n3. Setting file permissions..." -ForegroundColor Cyan
foreach ($file in $files) {
    $originalPath = Join-Path $projectRoot $file.original
    if (Test-Path $originalPath) {
        Set-ItemProperty -Path $originalPath -Name IsReadOnly -Value $false
        Write-Host "  ✓ Permissions set: $($file.original)" -ForegroundColor Green
    }
}

Write-Host "`n=== Deployment Complete ===" -ForegroundColor Green
Write-Host "✓ Files backed up to: $backupDir" -ForegroundColor Cyan
Write-Host "✓ Clean versions deployed" -ForegroundColor Cyan
Write-Host "✓ Permissions updated" -ForegroundColor Cyan

Write-Host "`n=== Test Your System ===" -ForegroundColor Yellow
Write-Host "1. Open: http://localhost/wbhsms-cho-koronadal-1/pages/management/login.php" -ForegroundColor White
Write-Host "2. Login as Admin/Cashier" -ForegroundColor White
Write-Host "3. Navigate to Billing Management" -ForegroundColor White
Write-Host "4. Check for any 'headers already sent' errors" -ForegroundColor White

Write-Host "`n=== For Production Server ===" -ForegroundColor Magenta
Write-Host "Upload these clean files to your production server and replace the originals:" -ForegroundColor White
foreach ($file in $files) {
    Write-Host "  • $($file.clean) → $($file.original)" -ForegroundColor Gray
}

Write-Host "`nDeployment script completed successfully!" -ForegroundColor Green