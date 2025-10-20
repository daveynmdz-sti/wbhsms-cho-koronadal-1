# Production BOM Fix Deployment Script
# Run this script to fix header/session issues caused by BOM (Byte Order Mark) in PHP files

Write-Host "=== WBHSMS Production BOM Fix Deployment ===" -ForegroundColor Green
Write-Host "This script will fix 'headers already sent' issues in production" -ForegroundColor Yellow

# Define file mappings (clean version -> production version)
$filesToFix = @{
    "pages/management/cashier/billing_reports_clean.php" = "pages/management/cashier/billing_reports.php"
    "pages/management/cashier/billing_management_clean.php" = "pages/management/cashier/billing_management.php"
    "api/create_invoice_clean.php" = "api/create_invoice.php"
    "api/process_payment_clean.php" = "api/process_payment.php"
    "api/get_patient_invoices_clean.php" = "api/get_patient_invoices.php"
    "config/session/employee_session_clean.php" = "config/session/employee_session.php"
}

$projectRoot = "c:\xampp\htdocs\wbhsms-cho-koronadal-1"

Write-Host "`n1. Backing up original files..." -ForegroundColor Cyan

# Create backup directory
$backupDir = "$projectRoot\backup_bom_fix_$(Get-Date -Format 'yyyyMMdd_HHmmss')"
New-Item -ItemType Directory -Path $backupDir -Force | Out-Null

# Backup original files
foreach ($mapping in $filesToFix.GetEnumerator()) {
    $originalFile = Join-Path $projectRoot $mapping.Value
    if (Test-Path $originalFile) {
        $backupFile = Join-Path $backupDir $mapping.Value
        $backupPath = Split-Path $backupFile -Parent
        New-Item -ItemType Directory -Path $backupPath -Force | Out-Null
        Copy-Item $originalFile $backupFile -Force
        Write-Host "  ✓ Backed up: $($mapping.Value)" -ForegroundColor Green
    }
}

Write-Host "`n2. Deploying clean files..." -ForegroundColor Cyan

# Replace with clean versions
foreach ($mapping in $filesToFix.GetEnumerator()) {
    $cleanFile = Join-Path $projectRoot $mapping.Key
    $originalFile = Join-Path $projectRoot $mapping.Value
    
    if (Test-Path $cleanFile) {
        Copy-Item $cleanFile $originalFile -Force
        Write-Host "  ✓ Fixed: $($mapping.Value)" -ForegroundColor Green
    } else {
        Write-Host "  ⚠ Clean file not found: $($mapping.Key)" -ForegroundColor Yellow
    }
}

Write-Host "`n3. Setting proper file permissions..." -ForegroundColor Cyan

# Set file permissions (Windows equivalent)
foreach ($mapping in $filesToFix.GetEnumerator()) {
    $file = Join-Path $projectRoot $mapping.Value
    if (Test-Path $file) {
        # Remove read-only attribute if present
        Set-ItemProperty -Path $file -Name IsReadOnly -Value $false
        Write-Host "  ✓ Set permissions: $($mapping.Value)" -ForegroundColor Green
    }
}

Write-Host "`n4. Verifying file encoding..." -ForegroundColor Cyan

# Check for BOM in files
foreach ($mapping in $filesToFix.GetEnumerator()) {
    $file = Join-Path $projectRoot $mapping.Value
    if (Test-Path $file) {
        $bytes = Get-Content $file -Encoding Byte -TotalCount 3
        $hasBOM = ($bytes.Count -ge 3) -and ($bytes[0] -eq 0xEF) -and ($bytes[1] -eq 0xBB) -and ($bytes[2] -eq 0xBF)
        
        if ($hasBOM) {
            Write-Host "  ⚠ BOM detected in: $($mapping.Value)" -ForegroundColor Red
        } else {
            Write-Host "  ✓ No BOM in: $($mapping.Value)" -ForegroundColor Green
        }
    }
}

Write-Host "`n5. Testing web access..." -ForegroundColor Cyan

# Test key URLs
$testUrls = @(
    "http://localhost/wbhsms-cho-koronadal-1/pages/management/cashier/billing_management.php",
    "http://localhost/wbhsms-cho-koronadal-1/pages/management/cashier/billing_reports.php"
)

foreach ($url in $testUrls) {
    try {
        $response = Invoke-WebRequest -Uri $url -Method GET -TimeoutSec 10 -ErrorAction Stop
        if ($response.StatusCode -eq 200) {
            Write-Host "  ✓ Accessible: $url" -ForegroundColor Green
        } else {
            Write-Host "  ⚠ Status $($response.StatusCode): $url" -ForegroundColor Yellow
        }
    } catch {
        Write-Host "  ⚠ Error accessing: $url" -ForegroundColor Yellow
        Write-Host "    $($_.Exception.Message)" -ForegroundColor Gray
    }
}

Write-Host "`n=== Deployment Summary ===" -ForegroundColor Green
Write-Host "✓ Original files backed up to: $backupDir" -ForegroundColor Cyan
Write-Host "✓ Clean files deployed" -ForegroundColor Cyan
Write-Host "✓ File permissions updated" -ForegroundColor Cyan

Write-Host "`n=== Next Steps ===" -ForegroundColor Yellow
Write-Host "1. Test the billing system in your browser" -ForegroundColor White
Write-Host "2. Check for any remaining 'headers already sent' errors" -ForegroundColor White
Write-Host "3. If issues persist, check the backup files for differences" -ForegroundColor White
Write-Host "4. For production deployment, repeat this process on the server" -ForegroundColor White

Write-Host "`n=== Production Server Instructions ===" -ForegroundColor Magenta
Write-Host "For your Hostinger VPS or production server:" -ForegroundColor White
Write-Host "1. Upload the *_clean.php files to your server" -ForegroundColor Gray
Write-Host "2. SSH into your server" -ForegroundColor Gray
Write-Host "3. Navigate to your project directory" -ForegroundColor Gray
Write-Host "4. Run these commands:" -ForegroundColor Gray
Write-Host "   cp pages/management/cashier/billing_management_clean.php pages/management/cashier/billing_management.php" -ForegroundColor DarkGray
Write-Host "   cp pages/management/cashier/billing_reports_clean.php pages/management/cashier/billing_reports.php" -ForegroundColor DarkGray
Write-Host "   cp api/create_invoice_clean.php api/create_invoice.php" -ForegroundColor DarkGray
Write-Host "   cp config/session/employee_session_clean.php config/session/employee_session.php" -ForegroundColor DarkGray
Write-Host "5. Set proper permissions: chmod 644 *.php" -ForegroundColor Gray

Write-Host "`nDeployment completed!" -ForegroundColor Green