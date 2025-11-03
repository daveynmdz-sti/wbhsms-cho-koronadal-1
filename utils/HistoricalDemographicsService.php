<?php
/**
 * Historical Demographics Service
 * Handles snapshot generation, storage, and historical data comparison
 */

class HistoricalDemographicsService
{
    private $conn;
    private $pdo;

    public function __construct($conn, $pdo = null)
    {
        $this->conn = $conn;
        $this->pdo = $pdo;
    }

    /**
     * Generate and save a demographics snapshot
     */
    public function generateSnapshot($type = 'manual', $notes = '', $employeeId = null)
    {
        try {
            // Start transaction
            $this->conn->autocommit(false);

            // Get current demographics data
            $currentData = $this->getCurrentDemographicsData();

            // Insert snapshot metadata
            $snapshotDate = date('Y-m-d');
            $stmt = $this->conn->prepare("
                INSERT INTO report_snapshots (snapshot_date, snapshot_type, generated_by, total_patients, notes)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                generated_by = VALUES(generated_by),
                total_patients = VALUES(total_patients),
                notes = VALUES(notes)
            ");
            $stmt->bind_param("ssiss", $snapshotDate, $type, $employeeId, $currentData['total_patients'], $notes);
            $stmt->execute();

            // Get snapshot ID
            $snapshotId = $this->conn->insert_id;
            if ($snapshotId == 0) {
                // If duplicate key update, get existing snapshot ID
                $stmt = $this->conn->prepare("SELECT snapshot_id FROM report_snapshots WHERE snapshot_date = ? AND snapshot_type = ?");
                $stmt->bind_param("ss", $snapshotDate, $type);
                $stmt->execute();
                $result = $stmt->get_result();
                $snapshotId = $result->fetch_assoc()['snapshot_id'];

                // Clear existing snapshot data for update
                $this->clearSnapshotData($snapshotId);
            }

            // Save age distribution
            $this->saveAgeDistributionSnapshot($snapshotId, $currentData['age_distribution']);

            // Save gender distribution
            $this->saveGenderDistributionSnapshot($snapshotId, $currentData['gender_distribution']);

            // Save district distribution
            $this->saveDistrictDistributionSnapshot($snapshotId, $currentData['district_distribution']);

            // Save barangay distribution
            $this->saveBarangayDistributionSnapshot($snapshotId, $currentData['barangay_distribution']);

            // Save PhilHealth distribution
            $this->savePhilhealthDistributionSnapshot($snapshotId, $currentData['philhealth_distribution']);

            // Save PWD statistics
            $this->savePwdStatisticsSnapshot($snapshotId, $currentData['pwd_statistics']);

            // SAVE NEW COMPREHENSIVE CROSS-TABULATION DATA
            // Save age by district cross-tabulation
            $this->saveAgeByDistrictSnapshot($snapshotId, $currentData['age_by_district']);

            // Save age by barangay cross-tabulation
            $this->saveAgeByBarangaySnapshot($snapshotId, $currentData['age_by_barangay']);

            // Save gender by district cross-tabulation
            $this->saveGenderByDistrictSnapshot($snapshotId, $currentData['gender_by_district']);

            // Save gender by barangay cross-tabulation
            $this->saveGenderByBarangaySnapshot($snapshotId, $currentData['gender_by_barangay']);

            // Save PhilHealth by district cross-tabulation
            $this->savePhilhealthByDistrictSnapshot($snapshotId, $currentData['philhealth_by_district']);

            // Save PhilHealth by barangay cross-tabulation
            $this->savePhilhealthByBarangaySnapshot($snapshotId, $currentData['philhealth_by_barangay']);

            // Commit transaction
            $this->conn->commit();
            $this->conn->autocommit(true);

            return [
                'success' => true,
                'snapshot_id' => $snapshotId,
                'snapshot_date' => $snapshotDate,
                'message' => 'Snapshot generated successfully'
            ];

        } catch (Exception $e) {
            // Rollback transaction
            $this->conn->rollback();
            $this->conn->autocommit(true);

            return [
                'success' => false,
                'error' => 'Failed to generate snapshot: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get current demographics data - COMPREHENSIVE VERSION
     * Matches the data structure from patient_demographics_full_report.php
     */
    private function getCurrentDemographicsData()
    {
        $data = [];

        // Total patients
        $result = $this->conn->query("SELECT COUNT(*) as total FROM patients WHERE status = 'active'");
        $data['total_patients'] = $result->fetch_assoc()['total'];

        // Age distribution (same as full report)
        $ageQuery = "
            SELECT 
                CASE 
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) <= 1 THEN 'Infants (0-1)'
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 2 AND 4 THEN 'Toddlers (1-4)'
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 'Children (5-12)'
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 'Teens (13-17)'
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 'Young Adults (18-35)'
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 'Adults (36-59)'
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) >= 60 THEN 'Seniors (60+)'
                    ELSE 'Unknown'
                END as age_group,
                COUNT(*) as count
            FROM patients 
            WHERE status = 'active' AND date_of_birth IS NOT NULL
            GROUP BY age_group
            ORDER BY 
                CASE 
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) <= 1 THEN 1
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 2 AND 4 THEN 2
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 3
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 4
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 5
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 6
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) >= 60 THEN 7
                    ELSE 8
                END
        ";
        $result = $this->conn->query($ageQuery);
        $data['age_distribution'] = [];
        while ($row = $result->fetch_assoc()) {
            $row['percentage'] = $data['total_patients'] > 0 ? ($row['count'] / $data['total_patients']) * 100 : 0;
            $data['age_distribution'][] = $row;
        }

        // Gender distribution (same as full report)
        $genderQuery = "
            SELECT 
                CASE WHEN sex = 'M' THEN 'Male' WHEN sex = 'F' THEN 'Female' ELSE sex END as gender,
                COUNT(*) as count
            FROM patients 
            WHERE status = 'active'
            GROUP BY sex
        ";
        $result = $this->conn->query($genderQuery);
        $data['gender_distribution'] = [];
        while ($row = $result->fetch_assoc()) {
            $row['percentage'] = $data['total_patients'] > 0 ? ($row['count'] / $data['total_patients']) * 100 : 0;
            $data['gender_distribution'][] = $row;
        }

        // ALL Barangay distribution (with COALESCE for Unknown)
        $barangayQuery = "
            SELECT 
                COALESCE(b.barangay_name, 'Unknown Barangay') as barangay_name,
                COUNT(p.patient_id) as count
            FROM patients p
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            WHERE p.status = 'active'
            GROUP BY b.barangay_id, b.barangay_name
            ORDER BY count DESC, b.barangay_name ASC
        ";
        $result = $this->conn->query($barangayQuery);
        $data['barangay_distribution'] = [];
        while ($row = $result->fetch_assoc()) {
            $row['percentage'] = $data['total_patients'] > 0 ? ($row['count'] / $data['total_patients']) * 100 : 0;
            $data['barangay_distribution'][] = $row;
        }

        // ALL District distribution (with COALESCE for Unknown)
        $districtQuery = "
            SELECT 
                COALESCE(d.district_name, 'Unknown District') as district_name,
                COUNT(p.patient_id) as count
            FROM patients p
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            LEFT JOIN districts d ON b.district_id = d.district_id
            WHERE p.status = 'active'
            GROUP BY d.district_id, d.district_name
            ORDER BY count DESC, d.district_name ASC
        ";
        $result = $this->conn->query($districtQuery);
        $data['district_distribution'] = [];
        while ($row = $result->fetch_assoc()) {
            $row['percentage'] = $data['total_patients'] > 0 ? ($row['count'] / $data['total_patients']) * 100 : 0;
            $data['district_distribution'][] = $row;
        }

        // PhilHealth overall distribution (same as full report)
        $philhealthQuery = "
            SELECT 
                CASE 
                    WHEN isPhilHealth = 1 THEN 'PhilHealth Member'
                    ELSE 'Non-Member'
                END as membership_type,
                COUNT(*) as count
            FROM patients 
            WHERE status = 'active'
            GROUP BY isPhilHealth
        ";
        $result = $this->conn->query($philhealthQuery);
        $data['philhealth_distribution'] = [];
        while ($row = $result->fetch_assoc()) {
            $row['percentage'] = $data['total_patients'] > 0 ? ($row['count'] / $data['total_patients']) * 100 : 0;
            $data['philhealth_distribution'][] = $row;
        }

        // PWD statistics
        $pwdQuery = "SELECT COUNT(*) as pwd_count FROM patients WHERE status = 'active' AND isPWD = 1";
        $result = $this->conn->query($pwdQuery);
        $pwdCount = $result->fetch_assoc()['pwd_count'];
        $data['pwd_statistics'] = [
            'pwd_count' => $pwdCount,
            'pwd_percentage' => $data['total_patients'] > 0 ? ($pwdCount / $data['total_patients']) * 100 : 0
        ];

        // NEW: Age distribution by ALL districts (COMPREHENSIVE CROSS-TABULATION)
        $ageByDistrictQuery = "
            SELECT 
                COALESCE(d.district_name, 'Unknown District') as district_name,
                CASE 
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) <= 1 THEN 'Infants (0-1)'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 2 AND 4 THEN 'Toddlers (1-4)'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 'Children (5-12)'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 'Teens (13-17)'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 'Young Adults (18-35)'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 'Adults (36-59)'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) >= 60 THEN 'Seniors (60+)'
                    ELSE 'Unknown'
                END as age_group,
                COUNT(*) as count
            FROM patients p
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            LEFT JOIN districts d ON b.district_id = d.district_id
            WHERE p.status = 'active' AND p.date_of_birth IS NOT NULL
            GROUP BY d.district_id, d.district_name, age_group
            ORDER BY d.district_name, 
                CASE 
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) <= 1 THEN 1
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 2 AND 4 THEN 2
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 3
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 4
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 5
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 6
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) >= 60 THEN 7
                    ELSE 8
                END
        ";
        $result = $this->conn->query($ageByDistrictQuery);
        $data['age_by_district'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['age_by_district'][] = $row;
        }

        // NEW: Age distribution by ALL barangays (COMPREHENSIVE CROSS-TABULATION)
        $ageByBarangayQuery = "
            SELECT 
                COALESCE(b.barangay_name, 'Unknown Barangay') as barangay_name,
                CASE 
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) <= 1 THEN 'Infants (0-1)'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 2 AND 4 THEN 'Toddlers (1-4)'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 'Children (5-12)'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 'Teens (13-17)'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 'Young Adults (18-35)'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 'Adults (36-59)'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) >= 60 THEN 'Seniors (60+)'
                    ELSE 'Unknown'
                END as age_group,
                COUNT(*) as count
            FROM patients p
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            WHERE p.status = 'active' AND p.date_of_birth IS NOT NULL
            GROUP BY b.barangay_id, b.barangay_name, age_group
            ORDER BY b.barangay_name, 
                CASE 
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) <= 1 THEN 1
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 2 AND 4 THEN 2
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 5 AND 12 THEN 3
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN 4
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 18 AND 35 THEN 5
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 36 AND 59 THEN 6
                    WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) >= 60 THEN 7
                    ELSE 8
                END
        ";
        $result = $this->conn->query($ageByBarangayQuery);
        $data['age_by_barangay'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['age_by_barangay'][] = $row;
        }

        // NEW: Gender distribution by ALL districts (COMPREHENSIVE CROSS-TABULATION)
        $genderByDistrictQuery = "
            SELECT 
                COALESCE(d.district_name, 'Unknown District') as district_name,
                CASE WHEN p.sex = 'M' THEN 'Male' WHEN p.sex = 'F' THEN 'Female' ELSE p.sex END as gender,
                COUNT(*) as count
            FROM patients p
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            LEFT JOIN districts d ON b.district_id = d.district_id
            WHERE p.status = 'active'
            GROUP BY d.district_id, d.district_name, p.sex
            ORDER BY d.district_name, p.sex
        ";
        $result = $this->conn->query($genderByDistrictQuery);
        $data['gender_by_district'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['gender_by_district'][] = $row;
        }

        // NEW: Gender distribution by ALL barangays (COMPREHENSIVE CROSS-TABULATION)
        $genderByBarangayQuery = "
            SELECT 
                COALESCE(b.barangay_name, 'Unknown Barangay') as barangay_name,
                CASE WHEN p.sex = 'M' THEN 'Male' WHEN p.sex = 'F' THEN 'Female' ELSE p.sex END as gender,
                COUNT(*) as count
            FROM patients p
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            WHERE p.status = 'active'
            GROUP BY b.barangay_id, b.barangay_name, p.sex
            ORDER BY b.barangay_name, p.sex
        ";
        $result = $this->conn->query($genderByBarangayQuery);
        $data['gender_by_barangay'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['gender_by_barangay'][] = $row;
        }

        // NEW: PhilHealth distribution by ALL districts (COMPREHENSIVE CROSS-TABULATION)
        $philhealthByDistrictQuery = "
            SELECT 
                COALESCE(d.district_name, 'Unknown District') as district_name,
                CASE WHEN p.isPhilHealth = 1 THEN 'PhilHealth Member' ELSE 'Non-Member' END as philhealth_type,
                COUNT(p.patient_id) as count
            FROM patients p
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            LEFT JOIN districts d ON b.district_id = d.district_id
            WHERE p.status = 'active'
            GROUP BY d.district_id, d.district_name, p.isPhilHealth
            ORDER BY d.district_name, p.isPhilHealth DESC
        ";
        $result = $this->conn->query($philhealthByDistrictQuery);
        $data['philhealth_by_district'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['philhealth_by_district'][] = $row;
        }

        // NEW: PhilHealth distribution by ALL barangays (COMPREHENSIVE CROSS-TABULATION)
        $philhealthByBarangayQuery = "
            SELECT 
                COALESCE(b.barangay_name, 'Unknown Barangay') as barangay_name,
                CASE WHEN p.isPhilHealth = 1 THEN 'PhilHealth Member' ELSE 'Non-Member' END as philhealth_type,
                COUNT(p.patient_id) as count
            FROM patients p
            LEFT JOIN barangay b ON p.barangay_id = b.barangay_id
            WHERE p.status = 'active'
            GROUP BY b.barangay_id, b.barangay_name, p.isPhilHealth
            ORDER BY b.barangay_name, p.isPhilHealth DESC
        ";
        $result = $this->conn->query($philhealthByBarangayQuery);
        $data['philhealth_by_barangay'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['philhealth_by_barangay'][] = $row;
        }

        return $data;
    }

    /**
     * Save age distribution snapshot
     */
    private function saveAgeDistributionSnapshot($snapshotId, $ageData)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO snapshot_age_distribution (snapshot_id, age_group, count, percentage)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($ageData as $item) {
            $stmt->bind_param("isid", $snapshotId, $item['age_group'], $item['count'], $item['percentage']);
            $stmt->execute();
        }
    }

    /**
     * Save gender distribution snapshot
     */
    private function saveGenderDistributionSnapshot($snapshotId, $genderData)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO snapshot_gender_distribution (snapshot_id, gender, count, percentage)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($genderData as $item) {
            $stmt->bind_param("isid", $snapshotId, $item['gender'], $item['count'], $item['percentage']);
            $stmt->execute();
        }
    }

    /**
     * Save district distribution snapshot
     */
    private function saveDistrictDistributionSnapshot($snapshotId, $districtData)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO snapshot_district_distribution (snapshot_id, district_name, count, percentage)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($districtData as $item) {
            $stmt->bind_param("isid", $snapshotId, $item['district_name'], $item['count'], $item['percentage']);
            $stmt->execute();
        }
    }

    /**
     * Save barangay distribution snapshot
     */
    private function saveBarangayDistributionSnapshot($snapshotId, $barangayData)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO snapshot_barangay_distribution (snapshot_id, barangay_name, count, percentage)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($barangayData as $item) {
            $stmt->bind_param("isid", $snapshotId, $item['barangay_name'], $item['count'], $item['percentage']);
            $stmt->execute();
        }
    }

    /**
     * Save PhilHealth distribution snapshot
     */
    private function savePhilhealthDistributionSnapshot($snapshotId, $philhealthData)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO snapshot_philhealth_distribution (snapshot_id, membership_type, count, percentage)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($philhealthData as $item) {
            $stmt->bind_param("isid", $snapshotId, $item['membership_type'], $item['count'], $item['percentage']);
            $stmt->execute();
        }
    }

    /**
     * Save PWD statistics snapshot
     */
    private function savePwdStatisticsSnapshot($snapshotId, $pwdData)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO snapshot_pwd_statistics (snapshot_id, pwd_count, pwd_percentage)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("iid", $snapshotId, $pwdData['pwd_count'], $pwdData['pwd_percentage']);
        $stmt->execute();
    }

    /**
     * Save age by district cross-tabulation snapshot
     */
    private function saveAgeByDistrictSnapshot($snapshotId, $ageByDistrictData)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO snapshot_age_by_district (snapshot_id, district_name, age_group, count)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($ageByDistrictData as $item) {
            $stmt->bind_param("issi", $snapshotId, $item['district_name'], $item['age_group'], $item['count']);
            $stmt->execute();
        }
    }

    /**
     * Save age by barangay cross-tabulation snapshot
     */
    private function saveAgeByBarangaySnapshot($snapshotId, $ageByBarangayData)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO snapshot_age_by_barangay (snapshot_id, barangay_name, age_group, count)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($ageByBarangayData as $item) {
            $stmt->bind_param("issi", $snapshotId, $item['barangay_name'], $item['age_group'], $item['count']);
            $stmt->execute();
        }
    }

    /**
     * Save gender by district cross-tabulation snapshot
     */
    private function saveGenderByDistrictSnapshot($snapshotId, $genderByDistrictData)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO snapshot_gender_by_district (snapshot_id, district_name, gender, count)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($genderByDistrictData as $item) {
            $stmt->bind_param("issi", $snapshotId, $item['district_name'], $item['gender'], $item['count']);
            $stmt->execute();
        }
    }

    /**
     * Save gender by barangay cross-tabulation snapshot
     */
    private function saveGenderByBarangaySnapshot($snapshotId, $genderByBarangayData)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO snapshot_gender_by_barangay (snapshot_id, barangay_name, gender, count)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($genderByBarangayData as $item) {
            $stmt->bind_param("issi", $snapshotId, $item['barangay_name'], $item['gender'], $item['count']);
            $stmt->execute();
        }
    }

    /**
     * Save PhilHealth by district cross-tabulation snapshot
     */
    private function savePhilhealthByDistrictSnapshot($snapshotId, $philhealthByDistrictData)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO snapshot_philhealth_by_district (snapshot_id, district_name, philhealth_type, count)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($philhealthByDistrictData as $item) {
            $stmt->bind_param("issi", $snapshotId, $item['district_name'], $item['philhealth_type'], $item['count']);
            $stmt->execute();
        }
    }

    /**
     * Save PhilHealth by barangay cross-tabulation snapshot
     */
    private function savePhilhealthByBarangaySnapshot($snapshotId, $philhealthByBarangayData)
    {
        $stmt = $this->conn->prepare("
            INSERT INTO snapshot_philhealth_by_barangay (snapshot_id, barangay_name, philhealth_type, count)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($philhealthByBarangayData as $item) {
            $stmt->bind_param("issi", $snapshotId, $item['barangay_name'], $item['philhealth_type'], $item['count']);
            $stmt->execute();
        }
    }

    /**
     * Clear existing snapshot data for update
     */
    private function clearSnapshotData($snapshotId)
    {
        $tables = [
            'snapshot_age_distribution',
            'snapshot_gender_distribution',
            'snapshot_district_distribution',
            'snapshot_barangay_distribution',
            'snapshot_philhealth_distribution',
            'snapshot_pwd_statistics',
            // NEW COMPREHENSIVE CROSS-TABULATION TABLES
            'snapshot_age_by_district',
            'snapshot_age_by_barangay',
            'snapshot_gender_by_district',
            'snapshot_gender_by_barangay',
            'snapshot_philhealth_by_district',
            'snapshot_philhealth_by_barangay'
        ];

        foreach ($tables as $table) {
            $stmt = $this->conn->prepare("DELETE FROM $table WHERE snapshot_id = ?");
            $stmt->bind_param("i", $snapshotId);
            $stmt->execute();
        }
    }

    /**
     * Get list of available snapshots
     */
    public function getSnapshotsList($limit = 50)
    {
        $query = "
            SELECT 
                rs.*,
                e.first_name,
                e.last_name
            FROM report_snapshots rs
            LEFT JOIN employees e ON rs.generated_by = e.employee_id
            ORDER BY rs.snapshot_date DESC, rs.created_at DESC
            LIMIT ?
        ";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $snapshots = [];
        while ($row = $result->fetch_assoc()) {
            $row['generated_by_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
            $snapshots[] = $row;
        }

        return $snapshots;
    }

    /**
     * Get snapshot data by ID
     */
    public function getSnapshotData($snapshotId)
    {
        $data = [];

        // Get snapshot metadata
        $stmt = $this->conn->prepare("
            SELECT rs.*, e.first_name, e.last_name
            FROM report_snapshots rs
            LEFT JOIN employees e ON rs.generated_by = e.employee_id
            WHERE rs.snapshot_id = ?
        ");
        $stmt->bind_param("i", $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $snapshot = $result->fetch_assoc();

        if (!$snapshot) {
            return null;
        }

        $data['metadata'] = $snapshot;
        $data['metadata']['generated_by_name'] = trim($snapshot['first_name'] . ' ' . $snapshot['last_name']);

        // Get age distribution
        $stmt = $this->conn->prepare("SELECT * FROM snapshot_age_distribution WHERE snapshot_id = ? ORDER BY age_group");
        $stmt->bind_param("i", $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data['age_distribution'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['age_distribution'][] = $row;
        }

        // Get gender distribution
        $stmt = $this->conn->prepare("SELECT * FROM snapshot_gender_distribution WHERE snapshot_id = ? ORDER BY gender");
        $stmt->bind_param("i", $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data['gender_distribution'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['gender_distribution'][] = $row;
        }

        // Get district distribution
        $stmt = $this->conn->prepare("SELECT * FROM snapshot_district_distribution WHERE snapshot_id = ? ORDER BY count DESC");
        $stmt->bind_param("i", $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data['district_distribution'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['district_distribution'][] = $row;
        }

        // Get barangay distribution
        $stmt = $this->conn->prepare("SELECT * FROM snapshot_barangay_distribution WHERE snapshot_id = ? ORDER BY count DESC");
        $stmt->bind_param("i", $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data['barangay_distribution'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['barangay_distribution'][] = $row;
        }

        // Get PhilHealth distribution
        $stmt = $this->conn->prepare("SELECT * FROM snapshot_philhealth_distribution WHERE snapshot_id = ? ORDER BY membership_type");
        $stmt->bind_param("i", $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data['philhealth_distribution'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['philhealth_distribution'][] = $row;
        }

        // Get PWD statistics
        $stmt = $this->conn->prepare("SELECT * FROM snapshot_pwd_statistics WHERE snapshot_id = ?");
        $stmt->bind_param("i", $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $pwdData = $result->fetch_assoc();
        $data['pwd_statistics'] = $pwdData ?: ['pwd_count' => 0, 'pwd_percentage' => 0];

        // GET COMPREHENSIVE CROSS-TABULATION DATA

        // Get age by district cross-tabulation
        $stmt = $this->conn->prepare("SELECT * FROM snapshot_age_by_district WHERE snapshot_id = ? ORDER BY district_name, age_group");
        $stmt->bind_param("i", $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data['age_by_district'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['age_by_district'][] = $row;
        }

        // Get age by barangay cross-tabulation
        $stmt = $this->conn->prepare("SELECT * FROM snapshot_age_by_barangay WHERE snapshot_id = ? ORDER BY barangay_name, age_group");
        $stmt->bind_param("i", $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data['age_by_barangay'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['age_by_barangay'][] = $row;
        }

        // Get gender by district cross-tabulation
        $stmt = $this->conn->prepare("SELECT * FROM snapshot_gender_by_district WHERE snapshot_id = ? ORDER BY district_name, gender");
        $stmt->bind_param("i", $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data['gender_by_district'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['gender_by_district'][] = $row;
        }

        // Get gender by barangay cross-tabulation
        $stmt = $this->conn->prepare("SELECT * FROM snapshot_gender_by_barangay WHERE snapshot_id = ? ORDER BY barangay_name, gender");
        $stmt->bind_param("i", $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data['gender_by_barangay'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['gender_by_barangay'][] = $row;
        }

        // Get PhilHealth by district cross-tabulation
        $stmt = $this->conn->prepare("SELECT * FROM snapshot_philhealth_by_district WHERE snapshot_id = ? ORDER BY district_name, philhealth_type");
        $stmt->bind_param("i", $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data['philhealth_by_district'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['philhealth_by_district'][] = $row;
        }

        // Get PhilHealth by barangay cross-tabulation
        $stmt = $this->conn->prepare("SELECT * FROM snapshot_philhealth_by_barangay WHERE snapshot_id = ? ORDER BY barangay_name, philhealth_type");
        $stmt->bind_param("i", $snapshotId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data['philhealth_by_barangay'] = [];
        while ($row = $result->fetch_assoc()) {
            $data['philhealth_by_barangay'][] = $row;
        }

        return $data;
    }

    /**
     * Compare two snapshots
     */
    public function compareSnapshots($snapshot1Id, $snapshot2Id)
    {
        $snapshot1 = $this->getSnapshotData($snapshot1Id);
        $snapshot2 = $this->getSnapshotData($snapshot2Id);

        if (!$snapshot1 || !$snapshot2) {
            return null;
        }

        $comparison = [
            'snapshot1' => $snapshot1,
            'snapshot2' => $snapshot2,
            'comparison' => []
        ];

        // Compare total patients
        $totalChange = $snapshot2['metadata']['total_patients'] - $snapshot1['metadata']['total_patients'];
        $totalChangePercent = $snapshot1['metadata']['total_patients'] > 0 ? 
            ($totalChange / $snapshot1['metadata']['total_patients']) * 100 : 0;

        $comparison['comparison']['total_patients'] = [
            'change' => $totalChange,
            'change_percent' => $totalChangePercent,
            'direction' => $totalChange > 0 ? 'increase' : ($totalChange < 0 ? 'decrease' : 'no_change')
        ];

        // Compare age distributions
        $comparison['comparison']['age_distribution'] = $this->compareDistributions(
            $snapshot1['age_distribution'], 
            $snapshot2['age_distribution'], 
            'age_group'
        );

        // Compare gender distributions
        $comparison['comparison']['gender_distribution'] = $this->compareDistributions(
            $snapshot1['gender_distribution'], 
            $snapshot2['gender_distribution'], 
            'gender'
        );

        // Compare district distributions
        $comparison['comparison']['district_distribution'] = $this->compareDistributions(
            $snapshot1['district_distribution'], 
            $snapshot2['district_distribution'], 
            'district_name'
        );

        // Compare PWD statistics
        $pwdChange = $snapshot2['pwd_statistics']['pwd_count'] - $snapshot1['pwd_statistics']['pwd_count'];
        $pwdChangePercent = $snapshot1['pwd_statistics']['pwd_count'] > 0 ? 
            ($pwdChange / $snapshot1['pwd_statistics']['pwd_count']) * 100 : 0;

        $comparison['comparison']['pwd_statistics'] = [
            'change' => $pwdChange,
            'change_percent' => $pwdChangePercent,
            'direction' => $pwdChange > 0 ? 'increase' : ($pwdChange < 0 ? 'decrease' : 'no_change')
        ];

        return $comparison;
    }

    /**
     * Helper method to compare distributions
     */
    private function compareDistributions($dist1, $dist2, $keyField)
    {
        $comparison = [];

        // Create lookup arrays
        $lookup1 = [];
        foreach ($dist1 as $item) {
            $lookup1[$item[$keyField]] = $item;
        }

        $lookup2 = [];
        foreach ($dist2 as $item) {
            $lookup2[$item[$keyField]] = $item;
        }

        // Get all unique keys
        $allKeys = array_unique(array_merge(array_keys($lookup1), array_keys($lookup2)));

        foreach ($allKeys as $key) {
            $count1 = isset($lookup1[$key]) ? $lookup1[$key]['count'] : 0;
            $count2 = isset($lookup2[$key]) ? $lookup2[$key]['count'] : 0;
            $percent1 = isset($lookup1[$key]) ? $lookup1[$key]['percentage'] : 0;
            $percent2 = isset($lookup2[$key]) ? $lookup2[$key]['percentage'] : 0;

            $countChange = $count2 - $count1;
            $percentChange = $percent2 - $percent1;

            $comparison[$key] = [
                'count_change' => $countChange,
                'percent_change' => $percentChange,
                'direction' => $countChange > 0 ? 'increase' : ($countChange < 0 ? 'decrease' : 'no_change'),
                'snapshot1' => ['count' => $count1, 'percentage' => $percent1],
                'snapshot2' => ['count' => $count2, 'percentage' => $percent2]
            ];
        }

        return $comparison;
    }

    /**
     * Delete a snapshot
     */
    public function deleteSnapshot($snapshotId)
    {
        try {
            $stmt = $this->conn->prepare("DELETE FROM report_snapshots WHERE snapshot_id = ?");
            $stmt->bind_param("i", $snapshotId);
            $stmt->execute();

            return [
                'success' => true,
                'message' => 'Snapshot deleted successfully'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to delete snapshot: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get trend data for specific metric
     */
    public function getTrendData($metric, $period = '12 months')
    {
        $query = "
            SELECT 
                rs.snapshot_date,
                rs.total_patients
            FROM report_snapshots rs
            WHERE rs.snapshot_date >= DATE_SUB(CURDATE(), INTERVAL $period)
            ORDER BY rs.snapshot_date ASC
        ";

        $result = $this->conn->query($query);
        $trends = [];

        while ($row = $result->fetch_assoc()) {
            $trends[] = $row;
        }

        return $trends;
    }
}
?>