-- Additive, repeatable migration for MariaDB 10.4 / XAMPP.
-- Apply to the EXISTING rmmc_ite_system database after 002_ui_attendance_upgrade.sql.
-- This does NOT delete or change historical users, attendance, fines, payments, or clearances.
ALTER TABLE `staff_profiles`
  ADD COLUMN IF NOT EXISTS `organization_name` VARCHAR(150) NULL AFTER `last_name`,
  ADD COLUMN IF NOT EXISTS `position_title` VARCHAR(100) NULL AFTER `organization_name`;
