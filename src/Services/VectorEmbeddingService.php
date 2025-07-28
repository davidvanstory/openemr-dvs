<?php

namespace OpenEMR\Services;

use OpenEMR\Common\Logging\SystemLogger;

// Include OpenEMR database functions - use relative path from this file
require_once(__DIR__ . '/../../library/sql.inc.php');

/**
 * Custom logging function for Vector Embedding debugging - matches generate_summary.php style
 */
function vector_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] [VectorEmbeddingService.php] $message\n", 3, "/tmp/ai_summary.log");
    error_log("VECTOR_EMBEDDINGS: $message"); // Also log to default error log
}

/**
 * Vector Embedding Service for semantic similarity using OpenAI embeddings
 * 
 * This service handles:
 * - Generating embeddings from OpenAI API
 * - Caching embeddings to reduce API costs
 * - Calculating cosine similarity between vectors
 * - Managing embedding versions for cache invalidation
 * 
 * @package OpenEMR\Services
 */
class VectorEmbeddingService
{
    private $apiKey;
    private $model;
    private $logger;
    
    // OpenAI embedding models and their dimensions
    const MODELS = [
        'text-embedding-3-small' => 1536,  // $0.02 per 1M tokens
        'text-embedding-3-large' => 3072,  // $0.13 per 1M tokens  
        'text-embedding-ada-002' => 1536   // $0.10 per 1M tokens (legacy)
    ];
    
    public function __construct(string $apiKey = null, string $model = 'text-embedding-3-small')
    {
        vector_log("=== VECTOR EMBEDDING SERVICE INITIALIZATION START ===");
        vector_log("Requested model: $model");
        vector_log("API key provided: " . ($apiKey ? "YES (length: " . strlen($apiKey) . ")" : "NO (will auto-detect)"));
        
        $this->apiKey = $apiKey ?: $this->getApiKey();
        $this->model = $model;
        $this->logger = new SystemLogger();
        
        if (!$this->apiKey) {
            vector_log("ERROR: OpenAI API key not configured for vector embeddings");
            throw new \Exception('OpenAI API key not configured for vector embeddings');
        }
        
        vector_log("OpenAI API key found (length: " . strlen($this->apiKey) . " characters)");
        
        if (!isset(self::MODELS[$model])) {
            vector_log("ERROR: Unsupported embedding model: $model");
            vector_log("Available models: " . implode(', ', array_keys(self::MODELS)));
            throw new \Exception("Unsupported embedding model: $model");
        }
        
        vector_log("Vector Embedding Service initialized successfully");
        vector_log("Model: {$this->model} (dimensions: " . self::MODELS[$this->model] . ")");
        vector_log("Cost per 1M tokens: " . $this->getCostPerMillion() . " USD");
        vector_log("=== VECTOR EMBEDDING SERVICE INITIALIZATION COMPLETE ===");
    }
    
    /**
     * Generate embeddings for an array of text blocks with caching
     * 
     * @param array $textBlocks Array of text strings
     * @param string $contentType 'summary_block' or 'transcript_turn'
     * @return array Array of embedding vectors
     */
    public function generateEmbeddings(array $textBlocks, string $contentType = 'summary_block'): array
    {
        vector_log("=== VECTOR EMBEDDINGS GENERATION START ===");
        vector_log("Input: " . count($textBlocks) . " $contentType blocks");
        vector_log("Model: {$this->model}");
        vector_log("Content type: $contentType");
        
        $embeddings = [];
        $uncachedBlocks = [];
        $uncachedIndices = [];
        
        // Log sample of input text
        if (!empty($textBlocks)) {
            vector_log("Sample text blocks:");
            for ($i = 0; $i < min(3, count($textBlocks)); $i++) {
                vector_log("  Block $i (length: " . strlen($textBlocks[$i]) . "): '" . substr($textBlocks[$i], 0, 100) . "...'");
            }
        }
        
        vector_log("Checking cache for existing embeddings...");
        
        // Check cache first
        foreach ($textBlocks as $index => $text) {
            $hash = hash('sha256', $text);
            $cached = $this->getCachedEmbedding($hash, $contentType);
            
            if ($cached) {
                $embeddings[$index] = $cached;
                vector_log("Cache hit for block $index (hash: " . substr($hash, 0, 16) . "...)");
            } else {
                $uncachedBlocks[] = $text;
                $uncachedIndices[] = $index;
                vector_log("Cache miss for block $index - will need to generate");
            }
        }
        
        // Generate embeddings for uncached blocks
        if (!empty($uncachedBlocks)) {
            vector_log("=== OPENAI EMBEDDINGS API CALL START ===");
            vector_log("Need to generate " . count($uncachedBlocks) . " new embeddings via OpenAI API");
            vector_log("Estimated cost: $" . $this->estimateCost($uncachedBlocks));
            vector_log("Total tokens (estimated): " . $this->estimateTokens($uncachedBlocks));
            
            $startTime = microtime(true);
            $newEmbeddings = $this->callOpenAIEmbeddingsAPI($uncachedBlocks);
            $duration = round((microtime(true) - $startTime) * 1000);
            
            vector_log("OpenAI Embeddings API response received in {$duration}ms");
            vector_log("Generated " . count($newEmbeddings) . " embedding vectors successfully");
            
            // Cache and assign new embeddings
            vector_log("Caching new embeddings and assigning to result array...");
            foreach ($newEmbeddings as $i => $embedding) {
                $originalIndex = $uncachedIndices[$i];
                $embeddings[$originalIndex] = $embedding;
                
                // Cache for future use
                $hash = hash('sha256', $uncachedBlocks[$i]);
                $this->cacheEmbedding($hash, $contentType, $embedding);
            }
        } else {
            vector_log("All embeddings found in cache - no API calls needed");
        }
        
        // Sort by original index
        ksort($embeddings);
        
        vector_log("=== VECTOR EMBEDDINGS GENERATION COMPLETE ===");
        vector_log("Total embeddings returned: " . count($embeddings));
        vector_log("Cache hits: " . (count($textBlocks) - count($uncachedBlocks)));
        vector_log("New API calls: " . count($uncachedBlocks));
        
        return array_values($embeddings);
    }
    
    /**
     * Calculate cosine similarity between two vectors
     * 
     * @param array $vector1 First embedding vector
     * @param array $vector2 Second embedding vector
     * @return float Similarity score between 0 and 1
     */
    public function cosineSimilarity(array $vector1, array $vector2): float
    {
        vector_log("Calculating cosine similarity between vectors");
        vector_log("Vector 1 dimensions: " . count($vector1));
        vector_log("Vector 2 dimensions: " . count($vector2));
        
        if (count($vector1) !== count($vector2)) {
            vector_log("ERROR: Vector dimensions mismatch - " . count($vector1) . " vs " . count($vector2));
            throw new \Exception('Vector dimensions must match for similarity calculation');
        }
        
        $dotProduct = 0;
        $magnitude1 = 0;
        $magnitude2 = 0;
        
        for ($i = 0; $i < count($vector1); $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
            $magnitude1 += $vector1[$i] * $vector1[$i];
            $magnitude2 += $vector2[$i] * $vector2[$i];
        }
        
        $magnitude1 = sqrt($magnitude1);
        $magnitude2 = sqrt($magnitude2);
        
        vector_log("Dot product: " . round($dotProduct, 6));
        vector_log("Magnitude 1: " . round($magnitude1, 6));
        vector_log("Magnitude 2: " . round($magnitude2, 6));
        
        if ($magnitude1 == 0 || $magnitude2 == 0) {
            vector_log("WARNING: Zero magnitude detected - returning similarity 0");
            return 0;
        }
        
        $similarity = $dotProduct / ($magnitude1 * $magnitude2);
        vector_log("Cosine similarity calculated: " . round($similarity, 4));
        
        return $similarity;
    }
    
    /**
     * Find the best matching transcript turns for a summary block using vector similarity
     * 
     * @param array $summaryEmbedding Embedding vector for summary block
     * @param array $transcriptEmbeddings Array of transcript embedding vectors
     * @param float $threshold Minimum similarity threshold (default 0.7)
     * @param int $maxMatches Maximum number of matches to return (default 5)
     * @return array Array of matches with indices and similarity scores
     */
    public function findBestMatches(array $summaryEmbedding, array $transcriptEmbeddings, float $threshold = 0.65, int $maxMatches = 5): array
    {
        $matches = [];
        
        foreach ($transcriptEmbeddings as $index => $transcriptEmbedding) {
            $similarity = $this->cosineSimilarity($summaryEmbedding, $transcriptEmbedding);
            
            if ($similarity >= $threshold) {
                $matches[] = [
                    'transcript_index' => $index,
                    'similarity_score' => $similarity
                ];
            }
        }
        
        // Sort by similarity score (highest first)
        usort($matches, function($a, $b) {
            return $b['similarity_score'] <=> $a['similarity_score'];
        });
        
        // Limit to maxMatches
        return array_slice($matches, 0, $maxMatches);
    }
    
    /**
     * Enhanced linking map generation using vector embeddings
     * 
     * @param array $summaryEmbeddings Embeddings for summary blocks
     * @param array $transcriptEmbeddings Embeddings for transcript turns
     * @param float $threshold Similarity threshold
     * @return array Linking map with confidence scores
     */
    public function generateEnhancedLinkingMap(array $summaryEmbeddings, array $transcriptEmbeddings, float $threshold = 0.7): array
    {
        $linkingMap = [];
        $totalMatches = 0;
        $highConfidenceMatches = 0;
        
        foreach ($summaryEmbeddings as $summaryIndex => $summaryEmbedding) {
            $matches = $this->findBestMatches($summaryEmbedding, $transcriptEmbeddings, $threshold);
            
            $transcriptIndices = array_column($matches, 'transcript_index');
            $confidenceScores = array_column($matches, 'similarity_score');
            
            $linkingMap[] = [
                'summary_index' => $summaryIndex,
                'transcript_indices' => $transcriptIndices,
                'confidence_scores' => $confidenceScores,
                'avg_confidence' => !empty($confidenceScores) ? array_sum($confidenceScores) / count($confidenceScores) : 0.0,
                'max_confidence' => !empty($confidenceScores) ? max($confidenceScores) : 0.0
            ];
            
            $totalMatches += count($matches);
            $highConfidenceMatches += count(array_filter($confidenceScores, fn($score) => $score >= 0.8));
        }
        
        $this->logger->info("VectorEmbeddingService: Generated enhanced linking map with $totalMatches total matches, $highConfidenceMatches high-confidence matches");
        
        return $linkingMap;
    }
    
    /**
     * Call OpenAI Embeddings API
     * 
     * @param array $texts Array of text strings to embed
     * @return array Array of embedding vectors
     */
    private function callOpenAIEmbeddingsAPI(array $texts): array
    {
        $curl = curl_init();
        
        $requestData = [
            'model' => $this->model,
            'input' => $texts,
            'encoding_format' => 'float'
        ];
        
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.openai.com/v1/embeddings",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . $this->apiKey,
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => json_encode($requestData)
        ]);
        
        $startTime = microtime(true);
        $response = curl_exec($curl);
        $endTime = microtime(true);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        
        $duration = round(($endTime - $startTime) * 1000, 2);
        $this->logger->info("VectorEmbeddingService: OpenAI Embeddings API call completed in {$duration}ms");
        
        if ($error) {
            throw new \Exception("OpenAI Embeddings API cURL error: " . $error);
        }
        
        if ($httpCode !== 200) {
            $this->logger->error("VectorEmbeddingService: OpenAI Embeddings API error", ['http_code' => $httpCode, 'response' => $response]);
            throw new \Exception("OpenAI Embeddings API error: HTTP $httpCode");
        }
        
        $responseData = json_decode($response, true);
        
        if (!isset($responseData['data'])) {
            throw new \Exception("Invalid response from OpenAI Embeddings API");
        }
        
        // Extract embeddings in the correct order
        $embeddings = [];
        foreach ($responseData['data'] as $item) {
            $embeddings[] = $item['embedding'];
        }
        
        return $embeddings;
    }
    
    /**
     * Get cached embedding from database
     */
    private function getCachedEmbedding(string $hash, string $contentType): ?array
    {
        $result = sqlQuery(
            "SELECT embedding_vector FROM ai_embedding_cache WHERE content_hash = ? AND content_type = ? AND model_name = ?",
            [$hash, $contentType, $this->model]
        );
        
        if ($result) {
            // Update access tracking
            sqlStatement(
                "UPDATE ai_embedding_cache SET last_accessed = NOW(), access_count = access_count + 1 WHERE content_hash = ? AND content_type = ? AND model_name = ?",
                [$hash, $contentType, $this->model]
            );
            
            return json_decode($result['embedding_vector'], true);
        }
        
        return null;
    }
    
    /**
     * Cache embedding in database
     */
    private function cacheEmbedding(string $hash, string $contentType, array $embedding): void
    {
        $embeddingDimension = count($embedding);
        
        sqlStatement(
            "INSERT INTO ai_embedding_cache (content_hash, content_type, embedding_vector, model_name, embedding_dimension) 
             VALUES (?, ?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE 
                embedding_vector = VALUES(embedding_vector), 
                last_accessed = NOW(), 
                access_count = access_count + 1",
            [$hash, $contentType, json_encode($embedding), $this->model, $embeddingDimension]
        );
    }
    
    /**
     * Get OpenAI API key from environment or globals
     */
    private function getApiKey(): ?string
    {
        return getenv('OPENAI_API_KEY') ?: ($GLOBALS['openai_api_key'] ?? null);
    }
    
    /**
     * Get cost per million tokens for the current model
     * 
     * @return float Cost in USD per 1M tokens
     */
    private function getCostPerMillion(): float
    {
        $costPer1MTokens = [
            'text-embedding-3-small' => 0.02,
            'text-embedding-3-large' => 0.13,
            'text-embedding-ada-002' => 0.10
        ];
        
        return $costPer1MTokens[$this->model] ?? 0.02;
    }

    /**
     * Estimate token count for an array of texts
     * 
     * @param array $texts Array of text strings
     * @return int Estimated token count
     */
    private function estimateTokens(array $texts): int
    {
        $totalTokens = 0;
        foreach ($texts as $text) {
            // Rough estimation: 1 token ≈ 4 characters
            $totalTokens += strlen($text) / 4;
        }
        return round($totalTokens);
    }

    /**
     * Estimate API cost for embeddings
     * 
     * @param array $texts Array of text strings
     * @return array Cost estimation details
     */
    public function estimateCost(array $texts): array
    {
        $totalTokens = 0;
        foreach ($texts as $text) {
            // Rough estimation: 1 token ≈ 4 characters
            $totalTokens += strlen($text) / 4;
        }
        
        $costPer1MTokens = [
            'text-embedding-3-small' => 0.02,
            'text-embedding-3-large' => 0.13,
            'text-embedding-ada-002' => 0.10
        ];
        
        $cost = ($totalTokens / 1000000) * $costPer1MTokens[$this->model];
        
        return [
            'estimated_tokens' => round($totalTokens),
            'estimated_cost_usd' => round($cost, 4),
            'model' => $this->model,
            'text_count' => count($texts)
        ];
    }
} 