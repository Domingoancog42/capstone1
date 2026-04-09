-- HRIS Database
-- File: database/hris.sql
-- Purpose: schema + admin login seed for the HRIS capstone project

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- Create database
CREATE DATABASE IF NOT EXISTS `hris` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `hris`;

-- Roles table (normalized roles)
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed roles
INSERT INTO `roles` (`name`) VALUES
  ('Admin'),
  ('HR Head'),
  ('HR Staff'),
  ('Employee'),
  ('Chief'),
  ('Regional Director')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Status table
CREATE TABLE IF NOT EXISTS `status` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_status_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed statuses
INSERT INTO `status` (`name`) VALUES
  ('Pending'),
  ('Active'),
  ('Inactive')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Divisions table
CREATE TABLE IF NOT EXISTS `divisions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_divisions_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed divisions
INSERT INTO `divisions` (`name`) VALUES
  ('Finance & Administrative Management'),
  ('Geosciences'),
  ('Mine Management Division'),
  ('Mine Safety, Environment and Social Development Division'),
  ('Office of the Regional Director')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Designations table (linked to divisions)
CREATE TABLE IF NOT EXISTS `designations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `division_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_designations_division_name` (`division_id`, `name`),
  KEY `idx_designations_division_id` (`division_id`),
  CONSTRAINT `fk_designations_division_id` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed designations by division
INSERT INTO `designations` (`division_id`, `name`)
SELECT `seed`.`division_id`, `seed`.`name`
FROM (
  SELECT `id` AS `division_id`, 'accountant' AS `name`
  FROM `divisions`
  WHERE `name` = 'Finance & Administrative Management'
  UNION ALL
  SELECT `id`, 'Accounting Clerk'
  FROM `divisions`
  WHERE `name` = 'Finance & Administrative Management'
  UNION ALL
  SELECT `id`, 'accounting supervisor'
  FROM `divisions`
  WHERE `name` = 'Finance & Administrative Management'
  UNION ALL
  SELECT `id`, 'administrative aide'
  FROM `divisions`
  WHERE `name` = 'Finance & Administrative Management'
  UNION ALL
  SELECT `id`, 'administrative assistant'
  FROM `divisions`
  WHERE `name` = 'Finance & Administrative Management'
  UNION ALL
  SELECT `id`, 'Administrative Assistant I'
  FROM `divisions`
  WHERE `name` = 'Finance & Administrative Management'
  UNION ALL
  SELECT `id`, 'Administrative Assistant II'
  FROM `divisions`
  WHERE `name` = 'Finance & Administrative Management'
  UNION ALL
  SELECT `id`, 'Administrative Assistant III'
  FROM `divisions`
  WHERE `name` = 'Finance & Administrative Management'
  UNION ALL
  SELECT `id`, 'chief administrative officer'
  FROM `divisions`
  WHERE `name` = 'Finance & Administrative Management'
  UNION ALL
  SELECT `id`, 'HR Officer'
  FROM `divisions`
  WHERE `name` = 'Finance & Administrative Management'
  UNION ALL
  SELECT `id`, 'hr staff'
  FROM `divisions`
  WHERE `name` = 'Finance & Administrative Management'
  UNION ALL
  SELECT `id`, 'support staff'
  FROM `divisions`
  WHERE `name` = 'Finance & Administrative Management'
  UNION ALL
  SELECT `id`, 'Science Research Specialist II'
  FROM `divisions`
  WHERE `name` = 'Geosciences'
  UNION ALL
  SELECT `id`, 'Cartographer II'
  FROM `divisions`
  WHERE `name` = 'Geosciences'
  UNION ALL
  SELECT `id`, 'Engineer'
  FROM `divisions`
  WHERE `name` = 'Geosciences'
  UNION ALL
  SELECT `id`, 'field staff'
  FROM `divisions`
  WHERE `name` = 'Geosciences'
  UNION ALL
  SELECT `id`, 'unit head'
  FROM `divisions`
  WHERE `name` = 'Geosciences'
  UNION ALL
  SELECT `id`, 'division chief'
  FROM `divisions`
  WHERE `name` = 'Geosciences'
  UNION ALL
  SELECT `id`, 'Administrative Assistant I'
  FROM `divisions`
  WHERE `name` = 'Geosciences'
  UNION ALL
  SELECT `id`, 'Engineer'
  FROM `divisions`
  WHERE `name` = 'Mine Management Division'
  UNION ALL
  SELECT `id`, 'Engineer V'
  FROM `divisions`
  WHERE `name` = 'Mine Management Division'
  UNION ALL
  SELECT `id`, 'field staff'
  FROM `divisions`
  WHERE `name` = 'Mine Management Division'
  UNION ALL
  SELECT `id`, 'unit head'
  FROM `divisions`
  WHERE `name` = 'Mine Management Division'
  UNION ALL
  SELECT `id`, 'division chief'
  FROM `divisions`
  WHERE `name` = 'Mine Management Division'
  UNION ALL
  SELECT `id`, 'Administrative Assistant I'
  FROM `divisions`
  WHERE `name` = 'Mine Management Division'
  UNION ALL
  SELECT `id`, 'Engineer'
  FROM `divisions`
  WHERE `name` = 'Mine Safety, Environment and Social Development Division'
  UNION ALL
  SELECT `id`, 'Engineer V'
  FROM `divisions`
  WHERE `name` = 'Mine Safety, Environment and Social Development Division'
  UNION ALL
  SELECT `id`, 'field staff'
  FROM `divisions`
  WHERE `name` = 'Mine Safety, Environment and Social Development Division'
  UNION ALL
  SELECT `id`, 'unit head'
  FROM `divisions`
  WHERE `name` = 'Mine Safety, Environment and Social Development Division'
  UNION ALL
  SELECT `id`, 'division chief'
  FROM `divisions`
  WHERE `name` = 'Mine Safety, Environment and Social Development Division'
  UNION ALL
  SELECT `id`, 'Administrative Assistant I'
  FROM `divisions`
  WHERE `name` = 'Mine Safety, Environment and Social Development Division'
  UNION ALL
  SELECT `id`, 'regional director'
  FROM `divisions`
  WHERE `name` = 'Office of the Regional Director'
  UNION ALL
  SELECT `id`, 'assistant regional director'
  FROM `divisions`
  WHERE `name` = 'Office of the Regional Director'
  UNION ALL
  SELECT `id`, 'OIC Regional Director'
  FROM `divisions`
  WHERE `name` = 'Office of the Regional Director'
  UNION ALL
  SELECT `id`, 'administrative assistant'
  FROM `divisions`
  WHERE `name` = 'Office of the Regional Director'
  UNION ALL
  SELECT `id`, 'Administrative Assistant I'
  FROM `divisions`
  WHERE `name` = 'Office of the Regional Director'
  UNION ALL
  SELECT `id`, 'support staff'
  FROM `divisions`
  WHERE `name` = 'Office of the Regional Director'
) AS `seed`
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Employees table
CREATE TABLE IF NOT EXISTS `employees` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` VARCHAR(50) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `middle_name` VARCHAR(100) DEFAULT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `date_of_birth` DATE DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `city` VARCHAR(100) DEFAULT NULL,
  `province` VARCHAR(100) DEFAULT NULL,
  `zip_code` VARCHAR(20) DEFAULT NULL,
  `gender` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `profile_image` VARCHAR(255) DEFAULT NULL,
  `e_signature` LONGTEXT DEFAULT NULL,
  `division_id` INT UNSIGNED NOT NULL,
  `designation_id` INT UNSIGNED NOT NULL,
  `basic_salary` DECIMAL(12,2) DEFAULT NULL,
  `salary_rate` VARCHAR(50) DEFAULT NULL,
  `date_hired` DATE DEFAULT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'Active',
  `employment_status` VARCHAR(50) DEFAULT NULL,
  `pwd` TINYINT(1) NOT NULL DEFAULT 0,
  `civil_status` VARCHAR(50) DEFAULT NULL,
  `height` DECIMAL(5,2) DEFAULT NULL,
  `weight` DECIMAL(5,2) DEFAULT NULL,
  `blood_type` VARCHAR(20) DEFAULT NULL,
  `emp_gsis_id_no` VARCHAR(50) DEFAULT NULL,
  `emp_pagibig_id_no` VARCHAR(50) DEFAULT NULL,
  `emp_philhealth_id_no` VARCHAR(50) DEFAULT NULL,
  `tin_no` VARCHAR(50) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_employees_employee_id` (`employee_id`),
  KEY `idx_employees_division_id` (`division_id`),
  KEY `idx_employees_designation_id` (`designation_id`),
  CONSTRAINT `fk_employees_division_id` FOREIGN KEY (`division_id`) REFERENCES `divisions` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT `fk_employees_designation_id` FOREIGN KEY (`designation_id`) REFERENCES `designations` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DTR table
CREATE TABLE IF NOT EXISTS `dtr` (
  `attendance_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` VARCHAR(50) NOT NULL,
  `attendance_date` DATE NOT NULL,
  `time_in` TIME DEFAULT NULL,
  `time_out` TIME DEFAULT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'Present',
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attendance_id`),
  UNIQUE KEY `uniq_dtr_employee_date` (`employee_id`, `attendance_date`),
  KEY `idx_dtr_attendance_date` (`attendance_date`),
  CONSTRAINT `fk_dtr_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Leave types table
CREATE TABLE IF NOT EXISTS `leave_types` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 1,
  `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_leave_types_name` (`name`),
  KEY `idx_leave_types_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed leave types
INSERT INTO `leave_types` (`name`, `description`, `sort_order`)
SELECT `seed`.`name`, `seed`.`description`, `seed`.`sort_order`
FROM (
  SELECT 'Vacation' AS `name`, 'Leave for rest, recreation, or personal reasons.' AS `description`, 1 AS `sort_order`
  UNION ALL
  SELECT 'Mandatory/Forced Leave', 'Leave required after using the maximum number of vacation leave credits, as required by policy.', 2
  UNION ALL
  SELECT 'Sick', 'Leave for illness, injury, or medical treatment of the employee.', 3
  UNION ALL
  SELECT 'Maternity', 'Leave granted to a female employee for childbirth and recovery.', 4
  UNION ALL
  SELECT 'Paternity', 'Leave granted to a male employee to support his spouse during childbirth.', 5
  UNION ALL
  SELECT 'Special Privilege', 'Leave for personal matters or important family events.', 6
  UNION ALL
  SELECT 'Solo Parent', 'Leave granted to employees who are certified solo parents.', 7
  UNION ALL
  SELECT 'Study', 'Leave granted for examination or completion of studies.', 8
  UNION ALL
  SELECT '10-Day VAWC', 'Leave granted to victims of violence against women and their children.', 9
  UNION ALL
  SELECT 'Rehabilitation Privilege', 'Leave for medical rehabilitation or therapy.', 10
  UNION ALL
  SELECT 'Special Leave Benefits for Women', 'Leave granted to women who undergo surgery due to gynecological disorders.', 11
  UNION ALL
  SELECT 'Special Emergency(Calamity)', 'Leave granted during natural disasters or calamities.', 12
  UNION ALL
  SELECT 'Adoption', NULL, 13
  UNION ALL
  SELECT 'Others', NULL, 14
) AS `seed`
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `description` = VALUES(`description`),
  `sort_order` = VALUES(`sort_order`);

-- Users table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  `status_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_username` (`username`),
  UNIQUE KEY `uniq_users_email` (`email`),
  KEY `idx_users_role_id` (`role_id`),
  KEY `idx_users_status_id` (`status_id`),
  CONSTRAINT `fk_users_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT,
  CONSTRAINT `fk_users_status_id` FOREIGN KEY (`status_id`) REFERENCES `status` (`id`)
    ON UPDATE CASCADE
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Application settings table
CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `app_settings` (`setting_key`, `setting_value`) VALUES
  ('password_min_length', '8'),
  ('password_min_category_count', '3'),
  ('session_timeout_minutes', '30'),
  ('max_login_attempts', '5'),
  ('lockout_duration_minutes', '15'),
  ('password_reset_expiry_minutes', '15')
ON DUPLICATE KEY UPDATE
  `setting_value` = VALUES(`setting_value`);

-- User security state table
CREATE TABLE IF NOT EXISTS `user_security_state` (
  `user_id` INT UNSIGNED NOT NULL,
  `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until` DATETIME DEFAULT NULL,
  `last_failed_at` DATETIME DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_security_state_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Audit log table
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id` INT UNSIGNED DEFAULT NULL,
  `actor_name` VARCHAR(150) DEFAULT NULL,
  `actor_role` VARCHAR(100) DEFAULT NULL,
  `category` VARCHAR(50) NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(100) DEFAULT NULL,
  `entity_id` VARCHAR(100) DEFAULT NULL,
  `summary` VARCHAR(255) NOT NULL,
  `details_json` LONGTEXT DEFAULT NULL,
  `ip_address` VARCHAR(64) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_category` (`category`),
  KEY `idx_audit_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backup history table
CREATE TABLE IF NOT EXISTS `system_backups` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action_type` VARCHAR(20) NOT NULL,
  `backup_name` VARCHAR(255) NOT NULL,
  `table_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `record_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `performed_by` VARCHAR(150) DEFAULT NULL,
  `performed_role` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_system_backups_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Password reset verification codes
CREATE TABLE IF NOT EXISTS `password_reset_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_password_reset_codes_user_id` (`user_id`),
  KEY `idx_password_reset_codes_expires_at` (`expires_at`),
  CONSTRAINT `fk_password_reset_codes_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Leave request table
CREATE TABLE IF NOT EXISTS `leave_request` (
  `leave_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `leave_type` VARCHAR(100) NOT NULL,
  `status` INT NOT NULL DEFAULT 1,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `rejected_by` INT UNSIGNED DEFAULT NULL,
  `rejected_at` DATETIME DEFAULT NULL,
  `approval_pay_type` VARCHAR(20) DEFAULT NULL,
  `approved_days_with_pay` DECIMAL(5,2) DEFAULT NULL,
  `approved_days_without_pay` DECIMAL(5,2) DEFAULT NULL,
  `decision_note` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`leave_id`),
  KEY `idx_leave_request_employee_id` (`employee_id`),
  KEY `idx_leave_request_status` (`status`),
  KEY `idx_leave_request_approved_by` (`approved_by`),
  KEY `idx_leave_request_rejected_by` (`rejected_by`),
  CONSTRAINT `fk_leave_request_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_leave_request_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_leave_request_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Travel orders table
CREATE TABLE IF NOT EXISTS `tbltravel_orders` (
  `to_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `destination` VARCHAR(255) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `purpose` TEXT DEFAULT NULL,
  `assistance_or_labor_allowed` VARCHAR(255) DEFAULT NULL,
  `appropriations` VARCHAR(255) DEFAULT NULL,
  `remarks` TEXT DEFAULT NULL,
  `status` INT NOT NULL DEFAULT 1,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `rejected_by` INT UNSIGNED DEFAULT NULL,
  `rejected_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`to_id`),
  KEY `idx_tbltravel_orders_employee_id` (`employee_id`),
  KEY `idx_tbltravel_orders_status` (`status`),
  KEY `idx_tbltravel_orders_approved_by` (`approved_by`),
  KEY `idx_tbltravel_orders_rejected_by` (`rejected_by`),
  CONSTRAINT `fk_tbltravel_orders_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_tbltravel_orders_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_tbltravel_orders_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compensatory time off table
CREATE TABLE IF NOT EXISTS `tblcompensatory` (
  `cto_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `hours_applied` DECIMAL(5,2) NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE NOT NULL,
  `status` INT NOT NULL DEFAULT 1,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `rejected_by` INT UNSIGNED DEFAULT NULL,
  `rejected_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`cto_id`),
  KEY `idx_tblcompensatory_employee_id` (`employee_id`),
  KEY `idx_tblcompensatory_status` (`status`),
  KEY `idx_tblcompensatory_approved_by` (`approved_by`),
  KEY `idx_tblcompensatory_rejected_by` (`rejected_by`),
  CONSTRAINT `fk_tblcompensatory_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_tblcompensatory_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_tblcompensatory_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pass slips table
CREATE TABLE IF NOT EXISTS `tblpass_slips` (
  `ps_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `employee_id` INT UNSIGNED NOT NULL,
  `pass_date` DATE NOT NULL,
  `departure_time` TIME NOT NULL,
  `time_returned` TIME NOT NULL,
  `destination` VARCHAR(255) NOT NULL,
  `purpose` TEXT DEFAULT NULL,
  `status` INT NOT NULL DEFAULT 1,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `rejected_by` INT UNSIGNED DEFAULT NULL,
  `rejected_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ps_id`),
  KEY `idx_tblpass_slips_employee_id` (`employee_id`),
  KEY `idx_tblpass_slips_status` (`status`),
  KEY `idx_tblpass_slips_approved_by` (`approved_by`),
  KEY `idx_tblpass_slips_rejected_by` (`rejected_by`),
  CONSTRAINT `fk_tblpass_slips_employee_id` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`)
    ON UPDATE CASCADE
    ON DELETE CASCADE,
  CONSTRAINT `fk_tblpass_slips_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL,
  CONSTRAINT `fk_tblpass_slips_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`)
    ON UPDATE CASCADE
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed admin user
-- Password: admin123
INSERT INTO `users` (`username`, `email`, `password_hash`, `role_id`, `status_id`)
SELECT
  'admin' AS username,
  'admin@hris.local' AS email,
  '$2y$10$Yuv7HYKJp2tI4rR86d.D1.1dVEg4mJXez25q.Ax/ixp/49yyWvPK2' AS password_hash,
  r.id AS role_id,
  s.id AS status_id
FROM roles r
INNER JOIN `status` s ON s.name = 'Active'
WHERE r.name = 'Admin'
ON DUPLICATE KEY UPDATE
  `username` = VALUES(`username`),
  `email` = VALUES(`email`),
  `password_hash` = VALUES(`password_hash`),
  `role_id` = VALUES(`role_id`),
  `status_id` = VALUES(`status_id`);
