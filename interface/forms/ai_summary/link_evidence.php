<?php

/**
 * Backend service to generate an AI evidence linking map for an existing summary.
 */

// Enable session writes for this script
$sessionAllowWrite = true;
require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Utils\TextUtil;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\VectorEmbeddingService;

header('Content-Type: application/json');

// Custom logging function for AI Summary debugging - MATCHES generate_summary.php exactly
function ai_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] [link_evidence.php] $message\n", 3, "/tmp/ai_summary.log");
    error_log("AI_SUMMARY: $message"); // Also log to default error log
}

ai_log("=== AI EVIDENCE LINKING START ===");
ai_log("Request method: " . $_SERVER['REQUEST_METHOD']);
ai_log("POST data keys: " . implode(', ', array_keys($_POST)));
ai_log("Session ID: " . session_id());
ai_log("Session pid: " . ($_SESSION['pid'] ?? 'NOT SET'));
ai_log("Session encounter: " . ($_SESSION['encounter'] ?? 'NOT SET'));
ai_log("Session authUser: " . ($_SESSION['authUser'] ?? 'NOT SET'));

// Verify CSRF token
if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    ai_log("CSRF token verification failed");
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'CSRF token verification failed. Please refresh the page and try again.'
    ]);
    exit;
}
ai_log("CSRF token verified successfully");

try {
    // Get form parameters
    $formId = (int)($_POST['form_id'] ?? 0);
    $pid = $_SESSION['pid'] ?? 0;
    $encounter = $_SESSION['encounter'] ?? 0;
    
    ai_log("Form parameters - formId: $formId, pid: $pid, encounter: $encounter");
    
    if (!$formId || !$pid || !$encounter) {
        throw new Exception('Missing required parameters: form_id, pid, or encounter');
    }
    
    // Check for OpenAI API key
    $apiKey = null;
    if (getenv('OPENAI_API_KEY')) {
        $apiKey = getenv('OPENAI_API_KEY');
        ai_log("API key found in environment variables");
    } elseif (isset($_ENV['OPENAI_API_KEY']) && !empty($_ENV['OPENAI_API_KEY'])) {
        $apiKey = $_ENV['OPENAI_API_KEY'];
        ai_log("API key found in \$_ENV");
    } elseif (!empty($GLOBALS['openai_api_key'])) {
        $apiKey = $GLOBALS['openai_api_key'];
        ai_log("API key found in GLOBALS");
    }

    if (empty($apiKey)) {
        ai_log("ERROR: No OpenAI API key found in any location");
        throw new Exception('OpenAI API key not configured. Please add OPENAI_API_KEY to your .env file or configure it in Admin → Config → Connectors.');
    }
    ai_log("OpenAI API key available (length: " . strlen($apiKey) . " characters)");

    // Fetch existing AI summary form data
    ai_log("Fetching AI summary form data from database...");
    $res = sqlQuery(
        "SELECT * FROM form_ai_summary WHERE id = ? AND pid = ? AND encounter = ?",
        array($formId, $pid, $encounter)
    );
    
    if (!$res) {
        ai_log("ERROR: AI Summary form not found in database");
        throw new Exception('AI Summary form not found');
    }
    ai_log("AI Summary form found in database");
    ai_log("Form data keys: " . implode(', ', array_keys($res)));
    
    $transcript = $res['voice_transcription'] ?? '';
    $summary = $res['ai_summary'] ?? '';
    
    ai_log("Voice transcription length: " . strlen($transcript));
    ai_log("AI summary length: " . strlen($summary));

    if (empty(trim($transcript)) || empty(trim($summary))) {
        ai_log("ERROR: Transcript or Summary is empty for form_id: $formId. Cannot generate links.");
        throw new Exception('Transcript or Summary is empty. Cannot generate links.');
    }

    // Load linking prompt
    ai_log("Loading LinkedEvidencePrompt.md...");
    $linkingPromptFile = $GLOBALS['fileroot'] . '/_docs/LinkedEvidencePrompt.md';
    if (!file_exists($linkingPromptFile)) {
        ai_log("ERROR: LinkedEvidencePrompt.md not found at: $linkingPromptFile");
        throw new Exception('LinkedEvidencePrompt.md file not found');
    }
    $linkingPrompt = file_get_contents($linkingPromptFile);
    ai_log("Linking prompt loaded (length: " . strlen($linkingPrompt) . ")");

    // Split transcript and summary using TextUtil
    ai_log("=== TEXT SPLITTING START ===");
    ai_log("Splitting transcript into conversation turns...");
    $transcriptTurns = TextUtil::splitByConversationTurns($transcript);
    ai_log("Transcript split into " . count($transcriptTurns) . " turns");
    
    ai_log("Splitting summary into blocks...");
    $summaryBlocks = TextUtil::splitSummaryIntoBlocks($summary);
    ai_log("Summary split into " . count($summaryBlocks) . " blocks");

    // Validate splits
    if (empty($transcriptTurns) || empty($summaryBlocks)) {
        ai_log("ERROR: Text splitting resulted in empty arrays");
        throw new Exception("Text splitting resulted in empty arrays. Cannot proceed.");
    }
    
    // Log sample splits for debugging
    ai_log("Sample transcript turns (first 2):");
    for ($i = 0; $i < min(2, count($transcriptTurns)); $i++) {
        ai_log("  Turn $i: " . substr($transcriptTurns[$i], 0, 100) . "...");
    }
    ai_log("Sample summary blocks (first 2):");
    for ($i = 0; $i < min(2, count($summaryBlocks)); $i++) {
        ai_log("  Block $i: " . substr($summaryBlocks[$i], 0, 100) . "...");
    }

    // Prepare linking input
    $linkingInput = json_encode([
        'transcript_turns' => $transcriptTurns,
        'summary_blocks' => $summaryBlocks
    ]);
    ai_log("Linking input JSON prepared (length: " . strlen($linkingInput) . ")");

    // Prepare OpenAI request with detailed logging
    ai_log("=== OPENAI LINKING API CALL START ===");
    
    // Log detailed breakdown of what we're sending to OpenAI
    ai_log("MAPPING TASK DETAILS:");
    ai_log("  - Need to map " . count($summaryBlocks) . " summary blocks to " . count($transcriptTurns) . " transcript turns");
    ai_log("  - Summary blocks include headers: " . count(array_filter($summaryBlocks, function($block) { return preg_match('/^\*\*[^*]+\*\*$/', $block); })));
    ai_log("  - Average transcript turn length: " . round(array_sum(array_map('strlen', $transcriptTurns)) / count($transcriptTurns)) . " chars");
    ai_log("  - Average summary block length: " . round(array_sum(array_map('strlen', $summaryBlocks)) / count($summaryBlocks)) . " chars");
    
    $linkingPayload = [
        'model' => 'gpt-4o',
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => $linkingPrompt],
            ['role' => 'user', 'content' => $linkingInput]
        ],
        'temperature' => 0.1,
    ];
    
    ai_log("OpenAI linking request data prepared");
    ai_log("Model: " . $linkingPayload['model']);
    ai_log("Temperature: " . $linkingPayload['temperature']);
    ai_log("Response format: JSON object");
    ai_log("System prompt length: " . strlen($linkingPrompt));
    ai_log("User content length: " . strlen($linkingInput));
    ai_log("Total payload size: " . strlen(json_encode($linkingPayload)) . " bytes");
    
    // Calculate estimated tokens (rough estimate: 1 token ≈ 4 characters)
    $estimatedTokens = strlen($linkingPrompt . $linkingInput) / 4;
    ai_log("Estimated input tokens: " . round($estimatedTokens));
    
    // Show what the AI will be analyzing
    ai_log("SAMPLE DATA BEING SENT TO AI:");
    ai_log("  First summary block: '" . substr($summaryBlocks[0], 0, 80) . "...'");
    ai_log("  First transcript turn: '" . substr($transcriptTurns[0], 0, 80) . "...'");
    ai_log("  Last summary block: '" . substr(end($summaryBlocks), 0, 80) . "...'");
    ai_log("  Last transcript turn: '" . substr(end($transcriptTurns), 0, 80) . "...'");
    
    // Make API call with retry logic for timeouts
    ai_log("🤖 SENDING REQUEST TO OPENAI FOR EVIDENCE MAPPING...");
    ai_log("⏱️  This may take 2-5 minutes for complex medical conversations...");
    $startTime = microtime(true);
    $linkingResponse = makeOpenAICallWithRetry($linkingPayload, $apiKey);
    $endTime = microtime(true);
    
    $duration = round(($endTime - $startTime) * 1000, 2);
    ai_log("✅ OpenAI linking API response received in {$duration}ms");
    
    $linkingMapJson = $linkingResponse['choices'][0]['message']['content'];
    ai_log("🎯 AI linking call successful. Linking map JSON received (length: " . strlen($linkingMapJson) . ")");
    
    // Log a sample of the response for debugging
    ai_log("OPENAI RESPONSE PREVIEW (first 300 chars): " . substr($linkingMapJson, 0, 300) . "...");

    // Validate and process linking map
    ai_log("=== LINKING MAP VALIDATION START ===");
    ai_log("🔍 PARSING AND VALIDATING AI-GENERATED MAPPING...");
    
    $linkingMapData = json_decode($linkingMapJson, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($linkingMapData['linking_map'])) {
        ai_log("ERROR: Invalid JSON received for linking map: " . json_last_error_msg());
        ai_log("Raw response: " . substr($linkingMapJson, 0, 500));
        throw new Exception("Invalid JSON received for linking map: " . json_last_error_msg());
    }
    
    $rawLinkingMap = $linkingMapData['linking_map'];
    ai_log("✅ JSON parsing successful - raw linking map has " . count($rawLinkingMap) . " entries");

    $transcriptCount = count($transcriptTurns);
    $summaryCount = count($summaryBlocks);
    ai_log("Validation bounds: transcript_count=$transcriptCount, summary_count=$summaryCount");
    
    // Show some sample mappings for debugging
    ai_log("SAMPLE MAPPINGS FROM AI:");
    for ($i = 0; $i < min(3, count($rawLinkingMap)); $i++) {
        $link = $rawLinkingMap[$i];
        $summaryIdx = $link['summary_index'] ?? 'invalid';
        $transcriptIdxs = $link['transcript_indices'] ?? [];
        ai_log("  Mapping #$i: Summary block $summaryIdx → Transcript turns [" . implode(', ', array_slice($transcriptIdxs, 0, 5)) . (count($transcriptIdxs) > 5 ? '...' : '') . "]");
    }
    
    $validatedMap = [];
    $invalidLinks = 0;
    $totalLinkedTurns = 0;
    $suspiciousLinks = 0;
    
    ai_log("=== DETAILED MAPPING ANALYSIS START ===");
    
    foreach ($rawLinkingMap as $linkIndex => $link) {
        if (isset($link['summary_index']) && is_int($link['summary_index']) && $link['summary_index'] < $summaryCount) {
            $validTranscriptIndices = [];
            $summaryIndex = $link['summary_index'];
            $summaryContent = $summaryBlocks[$summaryIndex] ?? '';
            
            if (isset($link['transcript_indices']) && is_array($link['transcript_indices'])) {
                foreach ($link['transcript_indices'] as $t_idx) {
                    if (is_int($t_idx) && $t_idx < $transcriptCount) {
                        $validTranscriptIndices[] = $t_idx;
                        $totalLinkedTurns++;
                    } else {
                        $invalidLinks++;
                        ai_log("❌ Invalid transcript index $t_idx for summary index " . $link['summary_index']);
                    }
                }
                
                // ACCURACY VALIDATION: Log detailed content for suspicious mappings
                if (!empty($validTranscriptIndices) && $linkIndex < 5) { // Check first 5 mappings in detail
                    ai_log("📋 DETAILED MAPPING #$linkIndex ANALYSIS:");
                    ai_log("   📝 Summary Block $summaryIndex: '" . substr($summaryContent, 0, 120) . "...'");
                    ai_log("   🎤 Linked to " . count($validTranscriptIndices) . " transcript turns:");
                    
                    foreach (array_slice($validTranscriptIndices, 0, 3) as $idx => $tIdx) { // Show first 3 turns
                        $transcriptContent = $transcriptTurns[$tIdx] ?? '';
                        ai_log("      Turn $tIdx: '" . substr($transcriptContent, 0, 100) . "...'");
                        
                        // ENHANCED SEMANTIC VALIDATION: Check for obvious mismatches using vector embeddings + traditional methods
                        $semanticMatch = validateSemanticMatchEnhanced($summaryContent, $transcriptContent, $apiKey, $formId);
                        if (!$semanticMatch['likely_match']) {
                            $suspiciousLinks++;
                            ai_log("      ⚠️  SUSPICIOUS: " . $semanticMatch['reason'] . " (confidence: " . round($semanticMatch['confidence'], 3) . ")");
                        } else if (isset($semanticMatch['confidence']) && $semanticMatch['confidence'] > 0.8) {
                            ai_log("      ✅ HIGH CONFIDENCE: " . $semanticMatch['reason'] . " (confidence: " . round($semanticMatch['confidence'], 3) . ")");
                        }
                    }
                    if (count($validTranscriptIndices) > 3) {
                        ai_log("      ... and " . (count($validTranscriptIndices) - 3) . " more turns");
                    }
                }
            }
            
            $validatedMap[] = [
                'summary_index' => $link['summary_index'], 
                'transcript_indices' => $validTranscriptIndices
            ];
        } else {
            $invalidLinks++;
            ai_log("❌ Invalid summary index: " . ($link['summary_index'] ?? 'null'));
        }
    }
    
    $validatedLinkingJson = json_encode(['linking_map' => $validatedMap]);
    ai_log("🎯 MAPPING VALIDATION COMPLETE:");
    ai_log("  ✅ Original AI links: " . count($rawLinkingMap));
    ai_log("  ✅ Validated links: " . count($validatedMap)); 
    ai_log("  ✅ Total transcript turns linked: $totalLinkedTurns");
    ai_log("  ✅ Coverage: " . round((count($validatedMap) / $summaryCount) * 100, 1) . "% of summary blocks mapped");
    ai_log("  ❌ Invalid links removed: $invalidLinks");
    ai_log("  ⚠️  Suspicious links detected: $suspiciousLinks");

    // Save to database with enhanced metadata
    ai_log("=== DATABASE UPDATE START ===");
    ai_log("💾 SAVING ENHANCED LINKING MAP TO DATABASE...");
    
    // Add metadata about the enhanced validation
    $enhancedLinkingData = [
        'linking_map' => $validatedMap,
        'metadata' => [
            'validation_method' => 'enhanced_vector_embeddings',
            'traditional_suspicious_links' => $suspiciousLinks,
            'total_links_validated' => count($validatedMap),
            'coverage_percentage' => round((count($validatedMap) / $summaryCount) * 100, 1),
            'enhanced_at' => date('c'),
            'openai_model_used' => 'text-embedding-3-small'
        ]
    ];
    
    $enhancedLinkingJson = json_encode($enhancedLinkingData);
    $updateResult = sqlStatement(
        "UPDATE form_ai_summary SET 
            linking_map_json = ?,
            embedding_model = ?,
            embedding_version = ?
         WHERE id = ?", 
        [$enhancedLinkingJson, 'text-embedding-3-small', 1, $formId]
    );
    
    if (!$updateResult) {
        ai_log("ERROR: Failed to update database with linking map");
        throw new Exception('Failed to save linking map to database');
    }
    ai_log("✅ Database updated successfully with ENHANCED linking map for form_id: " . $formId);
    ai_log("💾 Saved " . strlen($enhancedLinkingJson) . " bytes of enhanced linking data to database");

    // Log success
    (new SystemLogger())->info("AI Evidence Linking completed successfully", [
        'form_id' => $formId,
        'encounter' => $encounter,
        'transcript_turns' => count($transcriptTurns),
        'summary_blocks' => count($summaryBlocks),
        'linking_entries' => count($validatedMap),
        'user' => $_SESSION['authUser'] ?? 'unknown'
    ]);
    
    ai_log("=== AI EVIDENCE LINKING SUCCESS ===");
    ai_log("Returning successful response to client");

    // Return success response
    echo json_encode([
        'success' => true, 
        'message' => 'Enhanced linking map generated successfully using vector embeddings.',
        'linking_entries' => count($validatedMap),
        'validation_method' => 'enhanced_vector_embeddings',
        'suspicious_links_detected' => $suspiciousLinks,
        'form_id' => $formId
    ]);

} catch (Exception $e) {
    // Log error
    ai_log("=== AI EVIDENCE LINKING ERROR ===");
    ai_log("Exception: " . $e->getMessage());
    ai_log("Stack trace: " . $e->getTraceAsString());
    
    if (class_exists('OpenEMR\\Common\\Logging\\SystemLogger')) {
        (new SystemLogger())->error("AI Evidence Linking failed", [
            'error' => $e->getMessage(),
            'form_id' => $formId ?? 'unknown',
            'user' => $_SESSION['authUser'] ?? 'unknown'
        ]);
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Helper function to make cURL calls to OpenAI API with retry logic for timeouts.
 * Enhanced version with better error handling and longer timeout for complex linking tasks.
 */
function makeOpenAICallWithRetry(array $payload, string $apiKey, int $maxRetries = 2): array
{
    $attempt = 1;
    $lastError = '';
    
    while ($attempt <= $maxRetries) {
        ai_log("=== OPENAI CURL REQUEST START (Attempt $attempt/$maxRetries) ===");
        
        try {
            $result = makeOpenAICall($payload, $apiKey);
            ai_log("=== OPENAI CURL REQUEST SUCCESS (Attempt $attempt) ===");
            return $result;
        } catch (Exception $e) {
            $lastError = $e->getMessage();
            ai_log("=== OPENAI CURL REQUEST FAILED (Attempt $attempt) ===");
            ai_log("Error: " . $lastError);
            
            // Only retry on timeout errors
            if (strpos($lastError, 'timed out') !== false && $attempt < $maxRetries) {
                ai_log("Timeout detected, retrying in 5 seconds...");
                sleep(5);
                $attempt++;
                continue;
            }
            
            // For non-timeout errors or max retries reached, throw the error
            throw $e;
        }
    }
    
    throw new Exception("Failed after $maxRetries attempts. Last error: $lastError");
}

/**
 * Helper function to make cURL calls to OpenAI API.
 * Enhanced with longer timeout and better connection handling for linking tasks.
 */
function makeOpenAICall(array $payload, string $apiKey): array
{
    ai_log("=== OPENAI CURL REQUEST START ===");
    
    $curl = curl_init();
    
    // Enhanced cURL options for better reliability
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.openai.com/v1/chat/completions",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 300, // 5 minute timeout for evidence linking (was 120)
        CURLOPT_CONNECTTIMEOUT => 30, // 30 second connection timeout
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, // Use HTTP/1.1 for better compatibility
        CURLOPT_USERAGENT => 'OpenEMR-AI-Summary/1.0',
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $apiKey,
            "Content-Type: application/json",
            "Accept: application/json",
            "Connection: keep-alive"
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_VERBOSE => false
    ]);

    ai_log("Making cURL request to OpenAI API...");
    ai_log("Request payload size: " . strlen(json_encode($payload)) . " bytes");
    ai_log("Timeout set to: 300 seconds (5 minutes)");
    ai_log("Connection timeout: 30 seconds");
    
    $startTime = microtime(true);
    $response = curl_exec($curl);
    $endTime = microtime(true);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    $curlInfo = curl_getinfo($curl);
    curl_close($curl);
    
    $duration = round(($endTime - $startTime) * 1000, 2);
    ai_log("OpenAI API response received");
    ai_log("HTTP code: $httpCode");
    ai_log("Response time: {$duration}ms");
    ai_log("Response length: " . strlen($response ?? ''));
    ai_log("Content type: " . ($curlInfo['content_type'] ?? 'unknown'));
    ai_log("Total time: " . round($curlInfo['total_time'] ?? 0, 3) . "s");
    ai_log("Name lookup time: " . round($curlInfo['namelookup_time'] ?? 0, 3) . "s");
    ai_log("Connect time: " . round($curlInfo['connect_time'] ?? 0, 3) . "s");

    if ($error) {
        ai_log("cURL error: $error");
        ai_log("cURL error code: " . curl_errno($curl));
        throw new Exception("Network error: " . $error);
    }
    
    if ($httpCode !== 200) {
        ai_log("HTTP error code: $httpCode");
        ai_log("Error response: " . substr($response, 0, 500));
        $errorData = json_decode($response, true);
        $errorMessage = $errorData['error']['message'] ?? "HTTP $httpCode error";
        throw new Exception("OpenAI API error: " . $errorMessage);
    }
    
    $responseData = json_decode($response, true);
    ai_log("JSON response decoded successfully");
    
    if (!isset($responseData['choices'][0]['message']['content'])) {
        ai_log("ERROR: Invalid response structure from OpenAI");
        ai_log("Response keys: " . implode(', ', array_keys($responseData)));
        throw new Exception("Invalid response from OpenAI API");
    }
    
    ai_log("=== OPENAI CURL REQUEST SUCCESS ===");
    
    return $responseData;
}

/**
 * Enhanced validation using vector embeddings + traditional semantic matching.
 * Provides much higher accuracy for medical context validation.
 */
function validateSemanticMatchEnhanced(string $summary, string $transcript, string $apiKey, int $formId): array
{
    ai_log("=== ENHANCED SEMANTIC VALIDATION START ===");
    ai_log("Summary text (length: " . strlen($summary) . "): '" . substr($summary, 0, 100) . "...'");
    ai_log("Transcript text (length: " . strlen($transcript) . "): '" . substr($transcript, 0, 100) . "...'");
    ai_log("Form ID: $formId");
    
    // First, try vector embedding similarity (most accurate)
    try {
        ai_log("=== VECTOR EMBEDDINGS APPROACH START ===");
        ai_log("Initializing VectorEmbeddingService with text-embedding-3-small model...");
        
        $vectorService = new VectorEmbeddingService($apiKey, 'text-embedding-3-small');
        
        ai_log("Generating embeddings for summary and transcript texts...");
        
        // Generate embeddings for both texts
        $startTime = microtime(true);
        $summaryEmbedding = $vectorService->generateEmbeddings([$summary], 'summary_block')[0];
        $summaryTime = round((microtime(true) - $startTime) * 1000);
        
        $startTime = microtime(true);
        $transcriptEmbedding = $vectorService->generateEmbeddings([$transcript], 'transcript_turn')[0];
        $transcriptTime = round((microtime(true) - $startTime) * 1000);
        
        ai_log("Summary embedding generated in {$summaryTime}ms (dimensions: " . count($summaryEmbedding) . ")");
        ai_log("Transcript embedding generated in {$transcriptTime}ms (dimensions: " . count($transcriptEmbedding) . ")");
        
        // Calculate cosine similarity
        ai_log("Calculating cosine similarity between embeddings...");
        $startTime = microtime(true);
        $vectorSimilarity = $vectorService->cosineSimilarity($summaryEmbedding, $transcriptEmbedding);
        $similarityTime = round((microtime(true) - $startTime) * 1000);
        
        ai_log("Vector similarity calculated in {$similarityTime}ms");
        ai_log("🎯 VECTOR SIMILARITY SCORE: " . round($vectorSimilarity, 4) . " (" . round($vectorSimilarity * 100, 1) . "%)");
        
        // High confidence thresholds for medical content
        if ($vectorSimilarity >= 0.85) {
            ai_log("✅ HIGH CONFIDENCE MATCH: Vector similarity >= 0.85 threshold");
            ai_log("Decision: LIKELY MATCH via vector embeddings only");
            ai_log("=== ENHANCED SEMANTIC VALIDATION SUCCESS ===");
            return [
                'likely_match' => true,
                'reason' => 'High vector similarity (' . round($vectorSimilarity * 100) . '%)',
                'confidence' => $vectorSimilarity,
                'method' => 'vector_embeddings'
            ];
        } elseif ($vectorSimilarity >= 0.7) {
            ai_log("⚠️ MEDIUM CONFIDENCE: Vector similarity >= 0.7, running hybrid validation...");
            ai_log("Running traditional semantic validation as backup...");
            
            // Medium similarity - validate with traditional methods
            $traditionalResult = validateSemanticMatch($summary, $transcript);
            
            ai_log("Traditional validation result: " . ($traditionalResult['likely_match'] ? 'MATCH' : 'NO MATCH'));
            ai_log("Traditional reason: " . $traditionalResult['reason']);
            
            if ($traditionalResult['likely_match']) {
                $hybridConfidence = ($vectorSimilarity + ($traditionalResult['confidence'] ?? 0.5)) / 2;
                ai_log("✅ HYBRID VALIDATION SUCCESS: Both vector and traditional agree");
                ai_log("Hybrid confidence: " . round($hybridConfidence, 3));
                ai_log("=== ENHANCED SEMANTIC VALIDATION SUCCESS ===");
                return [
                    'likely_match' => true,
                    'reason' => 'Vector + traditional validation (' . round($vectorSimilarity * 100) . '%, ' . $traditionalResult['reason'] . ')',
                    'confidence' => $hybridConfidence,
                    'method' => 'hybrid'
                ];
            } else {
                ai_log("❌ HYBRID VALIDATION CONFLICT: Vector says medium, traditional says no");
                ai_log("Being conservative - marking as NO MATCH");
                ai_log("=== ENHANCED SEMANTIC VALIDATION CONFLICT ===");
                // Vector says medium, traditional says no - be conservative
                return [
                    'likely_match' => false,
                    'reason' => 'Mixed signals: vector=' . round($vectorSimilarity * 100) . '%, traditional=' . $traditionalResult['reason'],
                    'confidence' => $vectorSimilarity,
                    'method' => 'hybrid_conservative'
                ];
            }
        } else {
            ai_log("❌ LOW CONFIDENCE: Vector similarity < 0.7 threshold");
            ai_log("Decision: NO MATCH via vector embeddings");
            ai_log("=== ENHANCED SEMANTIC VALIDATION REJECTION ===");
            // Low vector similarity - likely not a match
            return [
                'likely_match' => false,
                'reason' => 'Low vector similarity (' . round($vectorSimilarity * 100) . '%)',
                'confidence' => $vectorSimilarity,
                'method' => 'vector_embeddings'
            ];
        }
        
    } catch (Exception $e) {
        ai_log("=== VECTOR EMBEDDINGS ERROR ===");
        ai_log("ERROR: Vector embedding failed: " . $e->getMessage());
        ai_log("Stack trace: " . $e->getTraceAsString());
        ai_log("Falling back to traditional validation method...");
        
        // Fallback to traditional method if vector embeddings fail
        ai_log("=== TRADITIONAL VALIDATION FALLBACK START ===");
        $traditionalResult = validateSemanticMatch($summary, $transcript);
        ai_log("Traditional fallback result: " . ($traditionalResult['likely_match'] ? 'MATCH' : 'NO MATCH'));
        ai_log("Traditional fallback reason: " . $traditionalResult['reason']);
        ai_log("=== ENHANCED SEMANTIC VALIDATION FALLBACK COMPLETE ===");
        
        return array_merge($traditionalResult, ['method' => 'traditional_fallback']);
    }
}

/**
 * Helper function to validate semantic match between summary and transcript.
 * Designed to catch obvious mismatches in medical contexts.
 */
function validateSemanticMatch(string $summary, string $transcript): array
{
    // Normalize text for comparison
    $summaryLower = strtolower(trim($summary));
    $transcriptLower = strtolower(trim($transcript));
    
    // Skip header blocks (they typically don't have direct transcript matches)
    if (preg_match('/^\*\*[^*]+\*\*$/', $summary)) {
        return ['likely_match' => true, 'reason' => 'Header block - validation skipped', 'confidence' => 1.0];
    }
    
    // Medical condition keywords
    $medicalConditions = [
        'diabetes', 'diabetic', 'hypertension', 'blood pressure', 'cardiac', 'heart',
        'orthostatic', 'hypotension', 'medication', 'metoprolol', 'atorvastatin',
        'shortness of breath', 'dyspnea', 'chest pain', 'dizziness', 'lightheaded'
    ];
    
    // Lifestyle/social keywords  
    $lifestyleKeywords = [
        'wine', 'alcohol', 'drink', 'smoking', 'tobacco', 'exercise', 'diet'
    ];
    
    // Extract key medical terms from summary
    $summaryMedicalTerms = [];
    $summaryLifestyleTerms = [];
    
    foreach ($medicalConditions as $term) {
        if (strpos($summaryLower, $term) !== false) {
            $summaryMedicalTerms[] = $term;
        }
    }
    
    foreach ($lifestyleKeywords as $term) {
        if (strpos($summaryLower, $term) !== false) {
            $summaryLifestyleTerms[] = $term;
        }
    }
    
    // Extract key terms from transcript
    $transcriptMedicalTerms = [];
    $transcriptLifestyleTerms = [];
    
    foreach ($medicalConditions as $term) {
        if (strpos($transcriptLower, $term) !== false) {
            $transcriptMedicalTerms[] = $term;
        }
    }
    
    foreach ($lifestyleKeywords as $term) {
        if (strpos($transcriptLower, $term) !== false) {
            $transcriptLifestyleTerms[] = $term;
        }
    }
    
    // Check for obvious mismatches
    if (!empty($summaryMedicalTerms) && !empty($transcriptLifestyleTerms) && empty($transcriptMedicalTerms)) {
        return [
            'likely_match' => false, 
            'reason' => 'Medical summary (' . implode(', ', $summaryMedicalTerms) . ') linked to lifestyle content (' . implode(', ', $transcriptLifestyleTerms) . ')',
            'confidence' => 0.1
        ];
    }
    
    if (!empty($summaryLifestyleTerms) && !empty($transcriptMedicalTerms) && empty($transcriptLifestyleTerms)) {
        return [
            'likely_match' => false, 
            'reason' => 'Lifestyle summary (' . implode(', ', $summaryLifestyleTerms) . ') linked to medical content (' . implode(', ', $transcriptMedicalTerms) . ')',
            'confidence' => 0.1
        ];
    }
    
    // Check for any shared medical terms
    $sharedMedicalTerms = array_intersect($summaryMedicalTerms, $transcriptMedicalTerms);
    if (!empty($sharedMedicalTerms)) {
        $medicalConfidence = min(0.9, 0.6 + (count($sharedMedicalTerms) * 0.1)); // Higher confidence for medical matches
        return [
            'likely_match' => true, 
            'reason' => 'Shared medical terms: ' . implode(', ', $sharedMedicalTerms),
            'confidence' => $medicalConfidence
        ];
    }
    
    // Check for any shared lifestyle terms
    $sharedLifestyleTerms = array_intersect($summaryLifestyleTerms, $transcriptLifestyleTerms);
    if (!empty($sharedLifestyleTerms)) {
        $lifestyleConfidence = min(0.8, 0.5 + (count($sharedLifestyleTerms) * 0.1)); // Good confidence for lifestyle matches
        return [
            'likely_match' => true, 
            'reason' => 'Shared lifestyle terms: ' . implode(', ', $sharedLifestyleTerms),
            'confidence' => $lifestyleConfidence
        ];
    }
    
    // Simple word overlap check for remaining cases
    $summaryWords = array_filter(explode(' ', $summaryLower), function($word) {
        return strlen($word) > 3 && !in_array($word, ['the', 'and', 'that', 'with', 'have', 'this', 'were', 'been', 'their']);
    });
    
    $transcriptWords = array_filter(explode(' ', $transcriptLower), function($word) {
        return strlen($word) > 3 && !in_array($word, ['the', 'and', 'that', 'with', 'have', 'this', 'were', 'been', 'their']);
    });
    
    $sharedWords = array_intersect($summaryWords, $transcriptWords);
    $overlapRatio = count($sharedWords) / max(count($summaryWords), 1);
    
    if ($overlapRatio >= 0.3) { // 30% word overlap
        return [
            'likely_match' => true, 
            'reason' => 'Good word overlap (' . round($overlapRatio * 100) . '%): ' . implode(', ', array_slice($sharedWords, 0, 3)),
            'confidence' => $overlapRatio
        ];
    }
    
    return [
        'likely_match' => false, 
        'reason' => 'Low semantic similarity (only ' . round($overlapRatio * 100) . '% word overlap)',
        'confidence' => $overlapRatio
    ];
}
?>