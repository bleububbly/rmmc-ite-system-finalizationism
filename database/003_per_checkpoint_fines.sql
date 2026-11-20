-- RMMC ITE: ₱20 fine per missed required In/Out checkpoint.
-- Run ONCE in phpMyAdmin -> database rmmc_ite_system -> SQL.
-- ADDS columns/enum values; no DELETE, TRUNCATE or rewriting of old fines.
-- Existing event-level fines will retain attendance_slot=NULL and will not be retroactively split.

ALTER TABLE `fines`
  ADD COLUMN IF NOT EXISTS `attendance_slot`
  ENUM('morning_in','morning_out','afternoon_in','afternoon_out') NULL
  AFTER `event_participant_id`;

-- The partial status represents an event where some, but not all, required scans were recorded.
ALTER TABLE `event_participants`
  MODIFY `attendance_status`
  ENUM('pending','present','late','partial','absent','excused') NOT NULL DEFAULT 'pending';

ALTER TABLE `attendance`
  MODIFY `status`
  ENUM('pending','present','late','partial','absent','excused') NOT NULL DEFAULT 'pending';

-- Add a unique index idempotently (MariaDB compatible).
SET @slot_index_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema=DATABASE() AND table_name='fines'
    AND index_name='uq_fines_participant_checkpoint'
);
SET @fine_index_sql = IF(@slot_index_exists = 0,
  'ALTER TABLE `fines` ADD UNIQUE KEY `uq_fines_participant_checkpoint` (`event_participant_id`,`attendance_slot`)',
  'SELECT 1');
PREPARE stmt_fine_slot FROM @fine_index_sql;
EXECUTE stmt_fine_slot;
DEALLOCATE PREPARE stmt_fine_slot;
