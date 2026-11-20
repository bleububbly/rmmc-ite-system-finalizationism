-- Additive schema only. Keep your original 13 tables and records intact.
-- FIRST: import the user's rmmc_ite_system.sql into the rmmc_ite_system database.
-- SECOND: execute this migration inside that same database.
CREATE TABLE IF NOT EXISTS `event_attendance_slots` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `event_id` BIGINT UNSIGNED NOT NULL,
 `slot` ENUM('morning_in','morning_out','afternoon_in','afternoon_out') NOT NULL,
 `opens_at` DATETIME NOT NULL,
 `closes_at` DATETIME NOT NULL,
 `required` TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY `uq_event_slot` (`event_id`, `slot`),
 CONSTRAINT `fk_slot_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `attendance_scans` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 `event_participant_id` BIGINT UNSIGNED NOT NULL,
 `slot` ENUM('morning_in','morning_out','afternoon_in','afternoon_out') NOT NULL,
 `scanned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `scanned_by` BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY `uq_attendance_checkpoint` (`event_participant_id`,`slot`),
 KEY `idx_scan_at` (`scanned_at`),
 CONSTRAINT `fk_scan_participant` FOREIGN KEY (`event_participant_id`) REFERENCES `event_participants` (`id`) ON UPDATE CASCADE,
 CONSTRAINT `fk_scan_staff` FOREIGN KEY (`scanned_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `staff_profiles` (
 `user_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 `first_name` VARCHAR(100) NOT NULL DEFAULT '',
 `middle_name` VARCHAR(100) DEFAULT NULL,
 `last_name` VARCHAR(100) NOT NULL DEFAULT '',
 `email` VARCHAR(150) DEFAULT NULL,
 `qr_token` CHAR(64) DEFAULT NULL,
 UNIQUE KEY `uq_staff_qr_token` (`qr_token`),
 CONSTRAINT `fk_staff_profile_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
