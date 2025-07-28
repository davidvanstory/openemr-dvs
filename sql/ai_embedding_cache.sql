-- AI Embedding Cache Table
-- This table stores vector embeddings from OpenAI to reduce API costs
-- by caching previously computed embeddings for text content

CREATE TABLE IF NOT EXISTS `ai_embedding_cache` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `content_hash` varchar(64) NOT NULL COMMENT 'SHA256 hash of the original text content',
  `content_type` enum('summary_block','transcript_turn','general') NOT NULL DEFAULT 'general' COMMENT 'Type of content being embedded',
  `embedding_vector` JSON NOT NULL COMMENT 'Vector embedding as JSON array of floats',
  `model_name` varchar(50) NOT NULL DEFAULT 'text-embedding-3-small' COMMENT 'OpenAI model used for embedding',
  `embedding_dimension` int(11) NOT NULL DEFAULT 1536 COMMENT 'Dimension of the embedding vector',
  `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the embedding was cached',
  `last_accessed` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last time this embedding was retrieved',
  `access_count` int(11) NOT NULL DEFAULT 1 COMMENT 'Number of times this embedding has been accessed',
  PRIMARY KEY (`id`),
  UNIQUE KEY `content_model_type` (`content_hash`, `model_name`, `content_type`),
  KEY `model_type_index` (`model_name`, `content_type`),
  KEY `created_date_index` (`created_date`),
  KEY `content_hash_index` (`content_hash`)
) ENGINE=InnoDB COMMENT='Cache for OpenAI vector embeddings to reduce API costs and improve performance'; 