<?php
// Quick test for PDF endpoint functionality
$root_path = dirname(dirname(dirname(__DIR__)));
require_once $root_path . '/config/session/patient_session.php';

// Ensure session is properly started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "<h2>PDF Endpoint Test</h2>";

// Check if patient is logged in
if (!isset($_SESSION['patient_id'])) {
    echo "<p style='color: red;'>❌ Patient not logged in. Please log in first.</p>";
    echo "<a href='../auth/patient_login.php'>Login Here</a>";
    exit;
}

echo "<p style='color: green;'>✅ Patient logged in: " . $_SESSION['patient_id'] . "</p>";

// Database connection
require_once $root_path . '/config/db.php';

$patient_id = $_SESSION['patient_id'];

try {
    // Find lab results with files for this patient
    $sql = "SELECT loi.item_id, loi.test_type, loi.result_file, loi.status
            FROM lab_order_items loi
            INNER JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id  
            WHERE lo.patient_id = ? AND loi.result_file IS NOT NULL
            LIMIT 5";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$patient_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($results)) {
        echo "<p style='color: orange;'>⚠️ No lab results with files found for this patient.</p>";
        echo "<p>To test the PDF viewer, you need lab results with uploaded files.</p>";
    } else {
        echo "<h3>Available Lab Results for Testing:</h3>";
        echo "<table border='1' style='border-collapse: collapse; margin: 10px 0;'>";
        echo "<tr><th>Item ID</th><th>Test Type</th><th>Status</th><th>Actions</th></tr>";
        
        foreach ($results as $result) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($result['item_id']) . "</td>";
            echo "<td>" . htmlspecialchars($result['test_type']) . "</td>";
            echo "<td>" . htmlspecialchars($result['status']) . "</td>";
            echo "<td>";
            echo "<a href='get_lab_result.php?item_id=" . $result['item_id'] . "&action=view' target='_blank'>View PDF</a> | ";
            echo "<a href='download_lab_result.php?item_id=" . $result['item_id'] . "'>Download</a>";
            echo "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        echo "<p style='color: green;'>✅ Test the PDF links above to verify functionality.</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><a href='laboratory.php'>← Back to Laboratory Page</a></p>";
?>