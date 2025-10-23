<?php
// Debug password hashing issue
// This script helps identify and fix the double-hashing problem

$root_path = dirname(dirname(dirname(dirname(__DIR__))));
require_once $root_path . '/config/db.php';

echo "=== Password Hashing Debug Tool ===\n\n";

// Test the password hashing flow
$test_password = "TestPass123";
echo "1. Original password: $test_password\n";

// First hash (what happens in register_patient.php)
$first_hash = password_hash($test_password, PASSWORD_DEFAULT);
echo "2. First hash (register_patient.php): $first_hash\n";

// Second hash (what happens in registration_otp.php - WRONG!)
$second_hash = password_hash($first_hash, PASSWORD_DEFAULT);
echo "3. Second hash (registration_otp.php - DOUBLE HASHED): $second_hash\n\n";

// Test verification
echo "=== Verification Tests ===\n";
echo "Verify original password against first hash: " . (password_verify($test_password, $first_hash) ? "✅ PASS" : "❌ FAIL") . "\n";
echo "Verify original password against second hash: " . (password_verify($test_password, $second_hash) ? "✅ PASS" : "❌ FAIL") . "\n";
echo "Verify first hash against second hash: " . (password_verify($first_hash, $second_hash) ? "✅ PASS" : "❌ FAIL") . "\n\n";

// Check actual database for recently registered patients
echo "=== Database Check ===\n";
try {
    $stmt = $pdo->prepare("
        SELECT patient_id, username, password_hash, created_at 
        FROM patients 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($recent_patients) {
        echo "Recent patients (last 24 hours):\n";
        foreach ($recent_patients as $patient) {
            echo "- Patient ID: {$patient['patient_id']}, Username: {$patient['username']}\n";
            echo "  Password hash: {$patient['password_hash']}\n";
            echo "  Created: {$patient['created_at']}\n";
            
            // Try to detect if it's double-hashed by checking if it looks like a bcrypt hash of a bcrypt hash
            $hash = $patient['password_hash'];
            if (strlen($hash) === 60 && preg_match('/^\$2[aby]\$/', $hash)) {
                echo "  Analysis: Appears to be bcrypt hash ✅\n";
                
                // Let's see if this could be a double hash by checking if it could verify against a common password
                $common_passwords = ['TestPass123', 'Password123', 'Admin123'];
                $verified = false;
                foreach ($common_passwords as $test_pwd) {
                    if (password_verify($test_pwd, $hash)) {
                        echo "  Test verification with '$test_pwd': ✅ PASS\n";
                        $verified = true;
                        break;
                    }
                }
                if (!$verified) {
                    echo "  Test verification: ❌ FAIL (likely double-hashed)\n";
                }
            } else {
                echo "  Analysis: Invalid hash format ❌\n";
            }
            echo "\n";
        }
    } else {
        echo "No patients registered in the last 24 hours.\n";
    }
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}

echo "\n=== Recommendation ===\n";
echo "The issue is in registration_otp.php line 85.\n";
echo "Change:\n";
echo "  \$hashedPassword = password_hash(\$regData['password'], PASSWORD_DEFAULT);\n";
echo "To:\n";
echo "  \$hashedPassword = \$regData['password']; // Already hashed in register_patient.php\n";
?>