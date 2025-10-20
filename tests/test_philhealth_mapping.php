<?php
/**
 * Test script for patient registration PhilHealth type mapping
 * Tests the mapping function and database compatibility
 */

// Include the mapping function
require_once __DIR__ . '/../registration_otp.php';

echo "Testing Patient Registration PhilHealth Type Mapping...\n\n";

// Test mapping function
$test_values = [
    'Employees' => 'Employed Private',
    'Kasambahay' => 'Employed Private', 
    'Self-earning' => 'Individual Paying',
    'OFW' => 'OFW',
    'Filipinos_abroad' => 'Individual Paying',
    'Lifetime' => 'Lifetime Member',
    'Indigents' => 'Indigent',
    '4Ps' => 'Sponsored',
    'Senior_citizens' => 'Senior Citizen',
    'PWD' => 'PWD',
    'SK_officials' => 'Employed Government',
    'LGU_sponsored' => 'Sponsored',
    'No_capacity' => 'Indigent',
    'Solo_parent' => 'Sponsored'
];

// Database ENUM values for validation
$valid_enum_values = [
    'Indigent', 'Sponsored', 'Lifetime Member', 'Senior Citizen', 'PWD',
    'Employed Private', 'Employed Government', 'Individual Paying', 'OFW'
];

echo "✓ Testing PhilHealth type mapping:\n";
$all_pass = true;

foreach ($test_values as $form_value => $expected_db_value) {
    $mapped_value = mapPhilhealthType($form_value);
    $is_valid = in_array($mapped_value, $valid_enum_values);
    $status = ($mapped_value === $expected_db_value && $is_valid) ? "✅ PASS" : "❌ FAIL";
    
    echo "  $form_value → $mapped_value ($status)\n";
    
    if ($mapped_value !== $expected_db_value || !$is_valid) {
        $all_pass = false;
    }
}

// Test null/empty values
$null_result = mapPhilhealthType('');
$invalid_result = mapPhilhealthType('InvalidValue');

echo "\n✓ Edge case tests:\n";
echo "  Empty string → " . ($null_result === null ? "null (✅ PASS)" : "$null_result (❌ FAIL)") . "\n";
echo "  Invalid value → " . ($invalid_result === null ? "null (✅ PASS)" : "$invalid_result (❌ FAIL)") . "\n";

echo "\n=== SUMMARY ===\n";
if ($all_pass && $null_result === null && $invalid_result === null) {
    echo "✅ ALL TESTS PASSED\n";
    echo "The PhilHealth type mapping should resolve the database truncation error.\n";
    echo "Form values are now properly mapped to database ENUM values.\n";
} else {
    echo "❌ SOME TESTS FAILED\n";
    echo "Please review the mapping function.\n";
}

echo "\n✓ Database ENUM values validation:\n";
foreach ($valid_enum_values as $enum_value) {
    echo "  - '$enum_value'\n";
}

echo "\nNote: The 'SQLSTATE[01000]: Warning: 1265 Data truncated' error should now be resolved.\n";
?>