--
-- Add encounter_uuid column to form_ai_summary table
-- This ensures AI summaries are permanently linked to their parent encounters
--

-- Check if the column already exists before adding it
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'form_ai_summary' 
  AND COLUMN_NAME = 'encounter_uuid';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `form_ai_summary` 
     ADD COLUMN `encounter_uuid` binary(16) DEFAULT NULL 
     COMMENT ''UUID of parent encounter for permanent linking'' 
     AFTER `encounter`',
    'SELECT ''Column encounter_uuid already exists''');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add unique index on encounter_uuid if it doesn't exist
SET @idx_exists = 0;
SELECT COUNT(*) INTO @idx_exists
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'form_ai_summary'
  AND INDEX_NAME = 'encounter_uuid_unique';

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `form_ai_summary` 
     ADD UNIQUE KEY `encounter_uuid_unique` (`encounter_uuid`)',
    'SELECT ''Unique index encounter_uuid_unique already exists''');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add regular index on encounter_uuid if it doesn't exist
SET @idx_exists = 0;
SELECT COUNT(*) INTO @idx_exists
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'form_ai_summary'
  AND INDEX_NAME = 'encounter_uuid_index';

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `form_ai_summary` 
     ADD KEY `encounter_uuid_index` (`encounter_uuid`)',
    'SELECT ''Index encounter_uuid_index already exists''');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Attempt to populate encounter_uuid for existing records
-- This links existing AI summaries to their encounters via UUID
UPDATE form_ai_summary ais
INNER JOIN form_encounter fe ON ais.encounter = fe.encounter AND ais.pid = fe.pid
SET ais.encounter_uuid = fe.uuid
WHERE ais.encounter_uuid IS NULL
  AND fe.uuid IS NOT NULL; 