<?php
/**
 * Test script to verify standalone consultation system database readiness
 */
require_once __DIR__ . '/../config/db.php';

echo "=== Standalone Consultation System - Database Verification ===\n\n";

// Test 1: Check consultations table structure
echo "1. Checking consultations table structure...\n";
$result = mysqli_query($conn, "DESCRIBE consultations");
if ($result) {
    $columns = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $columns[] = $row['Field'];
        echo "   - {$row['Field']} ({$row['Type']})\n";
    }
    
    // Check required columns
    $required_columns = ['consultation_id', 'patient_id', 'vitals_id', 'chief_complaint', 'history_present_illness', 'physical_examination', 'assessment_diagnosis', 'consultation_notes', 'consulted_by'];
    $missing_columns = array_diff($required_columns, $columns);
    
    if (empty($missing_columns)) {
        echo "   ✅ All required columns present\n";
    } else {
        echo "   ❌ Missing columns: " . implode(', ', $missing_columns) . "\n";
        echo "   📝 Run the consultation_system_updates.sql script\n";
    }
} else {
    echo "   ❌ Error checking consultations table: " . mysqli_error($conn) . "\n";
}

echo "\n";

// Test 2: Check vitals table structure
echo "2. Checking vitals table structure...\n";
$result = mysqli_query($conn, "DESCRIBE vitals");
if ($result) {
    $columns = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $columns[] = $row['Field'];
        echo "   - {$row['Field']} ({$row['Type']})\n";
    }
    
    if (in_array('consultation_id', $columns)) {
        echo "   ✅ Vitals table has consultation_id for bidirectional linking\n";
    } else {
        echo "   ⚠️  Vitals table missing consultation_id (optional feature)\n";
    }
} else {
    echo "   ❌ Error checking vitals table: " . mysqli_error($conn) . "\n";
}

echo "\n";

// Test 3: Check patients table for search functionality
echo "3. Checking patients table for search functionality...\n";
$result = mysqli_query($conn, "DESCRIBE patients");
if ($result) {
    $columns = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $columns[] = $row['Field'];
    }
    
    $search_columns = ['username', 'first_name', 'last_name', 'barangay_id'];
    $missing_search = array_diff($search_columns, $columns);
    
    if (empty($missing_search)) {
        echo "   ✅ All search columns available: " . implode(', ', $search_columns) . "\n";
    } else {
        echo "   ❌ Missing search columns: " . implode(', ', $missing_search) . "\n";
    }
}

echo "\n";

// Test 4: Check barangay table linkage
echo "4. Checking barangay table...\n";
$result = mysqli_query($conn, "DESCRIBE barangay");
if ($result) {
    $has_name = false;
    while ($row = mysqli_fetch_assoc($result)) {
        if ($row['Field'] === 'barangay_name') {
            $has_name = true;
            break;
        }
    }
    
    if ($has_name) {
        echo "   ✅ Barangay table has barangay_name column\n";
    } else {
        echo "   ❌ Barangay table missing barangay_name column\n";
    }
} else {
    echo "   ❌ Barangay table not found\n";
}

echo "\n";

// Test 5: Test patient search functionality
echo "5. Testing patient search query...\n";
try {
    $search_sql = "SELECT DISTINCT
                    p.patient_id,
                    p.username as patient_code,
                    p.first_name,
                    p.last_name,
                    p.middle_name,
                    COALESCE(b.barangay_name, 'Not Specified') as barangay
                FROM patients p
                LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
                WHERE p.status = 'active'
                LIMIT 5";
    
    $result = mysqli_query($conn, $search_sql);
    if ($result) {
        $count = mysqli_num_rows($result);
        echo "   ✅ Patient search query works - found {$count} active patients\n";
        
        if ($count > 0) {
            $sample = mysqli_fetch_assoc($result);
            echo "   📋 Sample: {$sample['first_name']} {$sample['last_name']} ({$sample['patient_code']}) - {$sample['barangay']}\n";
        }
    } else {
        echo "   ❌ Patient search query failed: " . mysqli_error($conn) . "\n";
    }
} catch (Exception $e) {
    echo "   ❌ Exception in patient search: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 6: Test consultation creation
echo "6. Testing consultation creation (dry run)...\n";
try {
    $consultation_sql = "SELECT 
                        c.consultation_id,
                        c.patient_id,
                        c.consultation_date,
                        c.chief_complaint,
                        c.consultation_status,
                        p.username as patient_code,
                        CONCAT(p.first_name, ' ', p.last_name) as patient_name
                    FROM consultations c
                    LEFT JOIN patients p ON c.patient_id = p.patient_id
                    ORDER BY c.created_at DESC
                    LIMIT 3";
    
    $result = mysqli_query($conn, $consultation_sql);
    if ($result) {
        $count = mysqli_num_rows($result);
        echo "   ✅ Consultation query works - found {$count} existing consultations\n";
        
        while ($row = mysqli_fetch_assoc($result)) {
            echo "   📋 ID: {$row['consultation_id']} | {$row['patient_name']} ({$row['patient_code']}) | Status: {$row['consultation_status']}\n";
        }
    } else {
        echo "   ❌ Consultation query failed: " . mysqli_error($conn) . "\n";
    }
} catch (Exception $e) {
    echo "   ❌ Exception in consultation query: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 7: Check for required indexes
echo "7. Checking database indexes...\n";
$index_checks = [
    "SHOW INDEX FROM consultations WHERE Key_name LIKE '%patient%'" => "consultations patient index",
    "SHOW INDEX FROM vitals WHERE Key_name LIKE '%patient%'" => "vitals patient index",
    "SHOW INDEX FROM patients WHERE Key_name LIKE '%username%'" => "patients username index"
];

foreach ($index_checks as $query => $description) {
    $result = mysqli_query($conn, $query);
    if ($result && mysqli_num_rows($result) > 0) {
        echo "   ✅ {$description} exists\n";
    } else {
        echo "   ⚠️  {$description} missing (will impact performance)\n";
    }
}

echo "\n";

// Summary
echo "=== SUMMARY ===\n";
echo "Database structure check completed.\n";
echo "If you see any ❌ errors above, please run the consultation_system_updates.sql script.\n";
echo "⚠️  warnings indicate optional optimizations.\n";
echo "\nNext steps:\n";
echo "1. Run the SQL updates if needed\n";
echo "2. Test the new_consultation_standalone.php file\n";
echo "3. Use the updated index.php for consultation management\n";
?>