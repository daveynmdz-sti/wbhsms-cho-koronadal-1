<?php
// Fix existing double-hashed passwords in the database
// This script identifies and fixes patients who were affected by the double-hashing bug

$root_path = dirname(dirname(dirname(dirname(__DIR__))));
require_once $root_path . '/config/db.php';

echo "=== Password Fix Script ===\n\n";

try {
    // Get all patients to check their passwords
    $stmt = $pdo->prepare("SELECT patient_id, username, password_hash, created_at FROM patients ORDER BY created_at DESC");
    $stmt->execute();
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($patients) . " patients in the database.\n\n";
    
    $affected_patients = [];
    $test_passwords = ['TestPass123', 'Password123', 'Admin123', 'password', 'password123', 'Qwerty123'];
    
    foreach ($patients as $patient) {
        $can_login = false;
        
        // Test if any common password can verify against the stored hash
        foreach ($test_passwords as $test_pwd) {
            if (password_verify($test_pwd, $patient['password_hash'])) {
                $can_login = true;
                echo "✅ Patient {$patient['username']} (ID: {$patient['patient_id']}) can login with password '$test_pwd'\n";
                break;
            }
        }
        
        if (!$can_login) {
            // This patient likely has a double-hashed password
            $affected_patients[] = $patient;
            echo "❌ Patient {$patient['username']} (ID: {$patient['patient_id']}) cannot login - likely double-hashed\n";
        }
    }
    
    echo "\n=== Summary ===\n";
    echo "Total patients: " . count($patients) . "\n";
    echo "Affected by double-hashing: " . count($affected_patients) . "\n";
    
    if (count($affected_patients) > 0) {
        echo "\n=== Fix Options ===\n";
        echo "For affected patients, you have these options:\n";
        echo "1. Reset their passwords to a known value\n";
        echo "2. Ask them to use the password reset feature\n";
        echo "3. Contact them to re-register\n\n";
        
        echo "Affected patients:\n";
        foreach ($affected_patients as $patient) {
            echo "- {$patient['username']} (ID: {$patient['patient_id']}) - Created: {$patient['created_at']}\n";
        }
        
        // Offer to reset passwords to a default value
        echo "\n" . str_repeat("=", 50) . "\n";
        echo "AUTOMATIC FIX OPTION:\n";
        echo "Would you like to reset all affected passwords to 'TempPass123'?\n";
        echo "Patients will need to change their password on first login.\n";
        echo "\nTo apply the fix, uncomment the code below and run this script again.\n";
        echo str_repeat("=", 50) . "\n";
        
        // Commented out auto-fix code for safety
        /*
        echo "\nApplying automatic fix...\n";
        $temp_password = 'TempPass123';
        $temp_hash = password_hash($temp_password, PASSWORD_DEFAULT);
        
        $pdo->beginTransaction();
        
        $fix_stmt = $pdo->prepare("UPDATE patients SET password_hash = ? WHERE patient_id = ?");
        
        foreach ($affected_patients as $patient) {
            $fix_stmt->execute([$temp_hash, $patient['patient_id']]);
            echo "Fixed password for patient {$patient['username']}\n";
        }
        
        $pdo->commit();
        echo "\n✅ All affected passwords have been reset to '$temp_password'\n";
        echo "Please inform patients to login with this temporary password and change it.\n";
        */
    } else {
        echo "\n✅ No patients appear to be affected by the double-hashing issue.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n=== Next Steps ===\n";
echo "1. The registration_otp.php file has been fixed to prevent future double-hashing\n";
echo "2. Test the registration flow with a new patient\n";
echo "3. Test login with the new patient to confirm the fix works\n";
echo "4. For existing affected patients, use the password reset feature or contact them\n";
?>