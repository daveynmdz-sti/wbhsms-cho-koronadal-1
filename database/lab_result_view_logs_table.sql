-- Create audit table for lab result viewing
CREATE TABLE IF NOT EXISTS `lab_result_view_logs` (
  `log_id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `lab_item_id` int UNSIGNED NOT NULL,
  `employee_id` int UNSIGNED NOT NULL,
  `patient_name` varchar(101) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`log_id`),
  KEY `idx_lab_item_id` (`lab_item_id`),
  KEY `idx_employee_id` (`employee_id`),
  KEY `idx_viewed_at` (`viewed_at`),
  CONSTRAINT `fk_lab_result_view_logs_lab_item` FOREIGN KEY (`lab_item_id`) REFERENCES `lab_order_items` (`item_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lab_result_view_logs_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for lab result file access';