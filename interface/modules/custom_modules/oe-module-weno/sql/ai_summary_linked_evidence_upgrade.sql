--
-- Add linking_map_json column to form_ai_summary table for evidence linking
-- This enables linking summary blocks to specific transcript turns
--

-- Check if the linking_map_json column already exists before adding it
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'form_ai_summary' 
  AND COLUMN_NAME = 'linking_map_json';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `form_ai_summary` 
     ADD COLUMN `linking_map_json` JSON DEFAULT NULL 
     COMMENT ''JSON array mapping summary blocks to transcript turns'' 
     AFTER `ai_summary`',
    'SELECT ''Column linking_map_json already exists''');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index on linking_map_json for faster querying if it doesn't exist
SET @idx_exists = 0;
SELECT COUNT(*) INTO @idx_exists
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'form_ai_summary'
  AND INDEX_NAME = 'linking_map_index';

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `form_ai_summary` 
     ADD KEY `linking_map_index` ((CAST(linking_map_json AS CHAR(255))))',
    'SELECT ''Index linking_map_index already exists''');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;