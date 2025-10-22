<?php
// Test script to insert some sample lab data for PDF viewer testing
$root_path = __DIR__;
require_once $root_path . '/config/db.php';

echo "Creating test lab data for PDF viewer...\n";

// First, let's check if we have any patients and employees
$patients = $conn->query("SELECT patient_id, first_name, last_name FROM patients LIMIT 1");
$employees = $conn->query("SELECT employee_id, first_name, last_name FROM employees WHERE role_id IN (1,2,3,9) LIMIT 1");

if ($patients->num_rows == 0 || $employees->num_rows == 0) {
    echo "Error: No patients or authorized employees found. Cannot create test data.\n";
    exit;
}

$patient = $patients->fetch_assoc();
$employee = $employees->fetch_assoc();

echo "Using patient: {$patient['first_name']} {$patient['last_name']} (ID: {$patient['patient_id']})\n";
echo "Using employee: {$employee['first_name']} {$employee['last_name']} (ID: {$employee['employee_id']})\n";

// Create a lab order
$conn->query("INSERT INTO lab_orders (patient_id, ordered_by_employee_id, order_date, status) 
              VALUES ({$patient['patient_id']}, {$employee['employee_id']}, NOW(), 'completed')");

$lab_order_id = $conn->insert_id;
echo "Created lab order with ID: $lab_order_id\n";

// Create a simple PDF content (minimal PDF structure)
$pdf_content = "%PDF-1.4
1 0 obj
<<
/Type /Catalog
/Pages 2 0 R
>>
endobj

2 0 obj
<<
/Type /Pages
/Kids [3 0 R]
/Count 1
>>
endobj

3 0 obj
<<
/Type /Page
/Parent 2 0 R
/Resources <<
/Font <<
/F1 4 0 R
>>
>>
/MediaBox [0 0 612 792]
/Contents 5 0 R
>>
endobj

4 0 obj
<<
/Type /Font
/Subtype /Type1
/BaseFont /Helvetica
>>
endobj

5 0 obj
<<
/Length 54
>>
stream
BT
/F1 12 Tf
72 720 Td
(Lab Result: Blood Test - Normal) Tj
ET
endstream
endobj

xref
0 6
0000000000 65535 f 
0000000015 00000 n 
0000000068 00000 n 
0000000125 00000 n 
0000000268 00000 n 
0000000335 00000 n 
trailer
<<
/Size 6
/Root 1 0 R
>>
startxref
440
%%EOF";

// Insert lab order item with PDF content
$stmt = $conn->prepare("INSERT INTO lab_order_items (lab_order_id, test_type, status, result_file, result_date) 
                        VALUES (?, 'Blood Test', 'completed', ?, NOW())");
$stmt->bind_param("ib", $lab_order_id, $pdf_content);
$stmt->send_long_data(1, $pdf_content);
$stmt->execute();

$item_id = $conn->insert_id;
echo "Created lab order item with ID: $item_id\n";

echo "Test data created successfully!\n";
echo "You can now test the PDF viewer with item ID: $item_id\n";
?>