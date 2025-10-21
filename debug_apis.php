<?php
// Simple API tester
$url = "http://localhost/wbhsms-cho-koronadal-1/api/get_service_catalog.php";

echo "<h1>API Response Test</h1>";
echo "<h2>Service Catalog API Test</h2>";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<p><strong>HTTP Code:</strong> $http_code</p>";
echo "<p><strong>Full Response:</strong></p>";
echo "<pre>" . htmlspecialchars($response) . "</pre>";

// Test patient search
echo "<hr>";
echo "<h2>Patient Search API Test</h2>";

$url2 = "http://localhost/wbhsms-cho-koronadal-1/api/search_patients_simple.php?query=David";

$ch2 = curl_init();
curl_setopt($ch2, CURLOPT_URL, $url2);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_HEADER, true);
$response2 = curl_exec($ch2);
$http_code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "<p><strong>HTTP Code:</strong> $http_code2</p>";
echo "<p><strong>Full Response:</strong></p>";
echo "<pre>" . htmlspecialchars($response2) . "</pre>";
?>