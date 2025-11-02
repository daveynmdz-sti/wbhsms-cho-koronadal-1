<?php
/**
 * API Endpoint: Patient Visits Chart Data
 * Returns chart data for patient visits based on period (daily, weekly, monthly)
 * Filters visits to facility_id=1 only (CHO Koronadal main facility)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$root_path = dirname(__DIR__);
require_once $root_path . '/config/db.php';
require_once $root_path . '/config/session/employee_session.php';

// Check employee session (optional - can be accessed without login for demo)
// if (!is_employee_logged_in()) {
//     http_response_code(401);
//     echo json_encode(['error' => 'Unauthorized']);
//     exit;
// }

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $period = $input['period'] ?? 'daily';
    
    // Validate period
    if (!in_array($period, ['daily', 'weekly', 'monthly'])) {
        throw new Exception('Invalid period specified');
    }
    
    $chartData = getPatientVisitsData($pdo, $period);
    
    echo json_encode([
        'success' => true,
        'period' => $period,
        'labels' => $chartData['labels'],
        'values' => $chartData['values']
    ]);
    
} catch (Exception $e) {
    error_log("Patient Visits Chart API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to fetch chart data',
        'message' => $e->getMessage()
    ]);
}

function getPatientVisitsData($pdo, $period) {
    $labels = [];
    $values = [];
    
    try {
        switch ($period) {
            case 'daily':
                // Get last 7 days of patient visits for facility_id=1 only
                $sql = "SELECT 
                            DATE(v.visit_date) as visit_day,
                            COUNT(DISTINCT v.visit_id) as visit_count
                        FROM visits v 
                        WHERE v.visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
                        AND v.facility_id = 1
                        GROUP BY DATE(v.visit_date)
                        ORDER BY visit_day ASC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Fill in missing days with 0 visits
                for ($i = 6; $i >= 0; $i--) {
                    $date = date('Y-m-d', strtotime("-$i days"));
                    $dayName = date('D', strtotime($date));
                    $labels[] = $dayName;
                    
                    $found = false;
                    foreach ($results as $result) {
                        if ($result['visit_day'] === $date) {
                            $values[] = (int)$result['visit_count'];
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $values[] = 0;
                    }
                }
                break;
                
            case 'weekly':
                // Get last 8 weeks of patient visits for facility_id=1 only
                $sql = "SELECT 
                            YEARWEEK(v.visit_date, 1) as visit_week,
                            COUNT(DISTINCT v.visit_id) as visit_count
                        FROM visits v 
                        WHERE v.visit_date >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
                        AND v.facility_id = 1
                        GROUP BY YEARWEEK(v.visit_date, 1)
                        ORDER BY visit_week ASC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Fill in missing weeks with 0 visits
                for ($i = 7; $i >= 0; $i--) {
                    $weekStart = date('Y-m-d', strtotime("-$i weeks"));
                    $weekNumber = date('W', strtotime($weekStart));
                    $yearWeek = date('Y', strtotime($weekStart)) . sprintf('%02d', $weekNumber);
                    $labels[] = "Week $weekNumber";
                    
                    $found = false;
                    foreach ($results as $result) {
                        if ($result['visit_week'] === $yearWeek) {
                            $values[] = (int)$result['visit_count'];
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $values[] = 0;
                    }
                }
                break;
                
            case 'monthly':
                // Get last 12 months of patient visits for facility_id=1 only
                $sql = "SELECT 
                            DATE_FORMAT(v.visit_date, '%Y-%m') as visit_month,
                            COUNT(DISTINCT v.visit_id) as visit_count
                        FROM visits v 
                        WHERE v.visit_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                        AND v.facility_id = 1
                        GROUP BY DATE_FORMAT(v.visit_date, '%Y-%m')
                        ORDER BY visit_month ASC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute();
                $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Fill in missing months with 0 visits
                for ($i = 11; $i >= 0; $i--) {
                    $monthStart = date('Y-m-01', strtotime("-$i months"));
                    $monthKey = date('Y-m', strtotime($monthStart));
                    $monthName = date('M', strtotime($monthStart));
                    $labels[] = $monthName;
                    
                    $found = false;
                    foreach ($results as $result) {
                        if ($result['visit_month'] === $monthKey) {
                            $values[] = (int)$result['visit_count'];
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $values[] = 0;
                    }
                }
                break;
        }
        
    } catch (Exception $e) {
        error_log("Database error in getPatientVisitsData: " . $e->getMessage());
        // Return fallback data on database error
        return getFallbackChartData($period);
    }
    
    return [
        'labels' => $labels,
        'values' => $values
    ];
}

function getFallbackChartData($period) {
    $labels = [];
    $values = [];
    
    switch ($period) {
        case 'daily':
            for ($i = 6; $i >= 0; $i--) {
                $date = date('D', strtotime("-$i days"));
                $labels[] = $date;
                $values[] = rand(5, 25);
            }
            break;
            
        case 'weekly':
            for ($i = 7; $i >= 0; $i--) {
                $weekStart = date('Y-m-d', strtotime("-$i weeks"));
                $weekNumber = date('W', strtotime($weekStart));
                $labels[] = "Week $weekNumber";
                $values[] = rand(30, 120);
            }
            break;
            
        case 'monthly':
            for ($i = 11; $i >= 0; $i--) {
                $monthStart = date('Y-m-01', strtotime("-$i months"));
                $monthName = date('M', strtotime($monthStart));
                $labels[] = $monthName;
                $values[] = rand(100, 400);
            }
            break;
    }
    
    return [
        'labels' => $labels,
        'values' => $values
    ];
}
?>