# 🔐 PATIENT PASSWORD DOUBLE-HASHING FIX

**Date:** October 23, 2025  
**Issue:** Patient registration successful but login fails with "invalid password"  
**Root Cause:** Password being hashed twice during registration process  
**Status:** ✅ FIXED

---

## 🚨 Problem Description

Patients who successfully completed registration were unable to login with the password they created. The login form would show "Invalid Patient Number or Password" even when the correct credentials were entered.

### Technical Root Cause

The password was being hashed **twice** during the registration flow:

1. **First Hash** (Correct): In `register_patient.php` line 322
   ```php
   $hashed = password_hash($password, PASSWORD_DEFAULT);
   ```
   
2. **Second Hash** (Incorrect): In `registration_otp.php` line 85
   ```php
   $hashedPassword = password_hash($regData['password'], PASSWORD_DEFAULT);
   ```

The session already contained the hashed password from step 1, but step 2 hashed it again before storing in the database, creating a "hash of a hash."

### Why Login Failed

The `patient_login.php` file correctly uses `password_verify()` to check the plaintext password against the database hash:

```php
if (password_verify($password, $row['password'])) {
    // Login successful
}
```

However, since the database contained a double-hashed password, `password_verify()` could never match the original plaintext password.

---

## ✅ Solution Applied

### File Changed
**`pages/patient/registration/registration_otp.php`**

### Change Made
**Line 85 changed from:**
```php
$hashedPassword = password_hash($regData['password'], PASSWORD_DEFAULT);
```

**To:**
```php
$hashedPassword = $regData['password']; // Already hashed in register_patient.php
```

### Why This Fixes It
- The `$regData['password']` from the session already contains a properly hashed password
- We now store this existing hash directly in the database instead of hashing it again
- Login verification can now properly match plaintext passwords against the single hash

---

## 🔧 Diagnostic Tools Created

### 1. `debug_password_hashing.php`
- Demonstrates the double-hashing problem
- Tests password verification scenarios
- Checks recent patients in database

### 2. `fix_double_hashed_passwords.php`
- Identifies patients affected by double-hashing
- Shows which patients can/cannot login
- Provides options to fix existing affected patients

### 3. `test_password_fix.php`
- Simulates corrected registration flow
- Verifies the fix prevents double-hashing
- Demonstrates before/after scenarios

---

## 📋 Testing Steps

### For New Registrations (After Fix)
1. ✅ Complete patient registration with any password
2. ✅ Verify OTP and complete signup
3. ✅ Login with the same password - should work normally

### For Existing Affected Patients
1. Run `fix_double_hashed_passwords.php` to identify affected patients
2. Options for affected patients:
   - Reset their password to a known temporary value
   - Direct them to use the password reset feature
   - Have them re-register with a new account

---

## 🔍 Verification

### Before Fix
```php
// Registration flow created this:
$plainPassword = "MyPassword123";
$firstHash = password_hash($plainPassword, PASSWORD_DEFAULT);    // Correct
$doubleHash = password_hash($firstHash, PASSWORD_DEFAULT);       // Wrong - stored in DB

// Login verification failed:
password_verify("MyPassword123", $doubleHash) → false ❌
```

### After Fix
```php
// Registration flow now creates this:
$plainPassword = "MyPassword123";
$singleHash = password_hash($plainPassword, PASSWORD_DEFAULT);   // Stored in DB

// Login verification succeeds:
password_verify("MyPassword123", $singleHash) → true ✅
```

---

## 📚 Related Files

### Core Registration Files
- `pages/patient/registration/register_patient.php` - Initial password hashing
- `pages/patient/registration/registration_otp.php` - **FIXED** - No longer double-hashes
- `pages/patient/auth/patient_login.php` - Password verification (unchanged)

### Diagnostic Tools
- `pages/patient/registration/tools/debug_password_hashing.php`
- `pages/patient/registration/tools/fix_double_hashed_passwords.php`
- `pages/patient/registration/tools/test_password_fix.php`

### Documentation
- `pages/patient/registration/tools/README.md` - Updated with fix details

---

## 🚀 Deployment Notes

### Immediate Actions Required
1. ✅ Deploy the fixed `registration_otp.php` file
2. ✅ Test new patient registration and login flow
3. 🔄 Run diagnostic tools to check for existing affected patients
4. 🔄 Contact/reset passwords for affected patients if any

### Prevention
- The fix prevents any future double-hashing
- New patient registrations will work normally
- Password verification flow remains unchanged and secure

---

## 🔒 Security Impact

### Positive Security Outcomes
- ✅ Proper bcrypt password hashing maintained
- ✅ No plaintext passwords stored or logged
- ✅ Password verification works as designed
- ✅ No security vulnerabilities introduced

### No Security Risks
- The fix only removes unnecessary double-hashing
- All security best practices remain in place
- Password strength requirements unchanged
- Rate limiting and CSRF protection unchanged

---

**This fix ensures that patient registration and login works reliably while maintaining all security protections.**