-- Create consultation_print_logs table for audit logging
CREATE TABLE IF NOT EXISTS `consultation_print_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `consultation_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `printed_by` enum('patient','employee') NOT NULL DEFAULT 'patient',
  `printed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `session_id` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`log_id`),
  KEY `idx_consultation_print` (`consultation_id`, `patient_id`),
  KEY `idx_print_date` (`printed_at`),
  CONSTRAINT `fk_consultation_print_logs_consultation` 
    FOREIGN KEY (`consultation_id`) REFERENCES `consultations` (`consultation_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_consultation_print_logs_patient` 
    FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) 
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;