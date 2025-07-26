<?php
/**
 * AI Summary Generation Endpoint
 *
 * Generates AI summaries from voice transcriptions using OpenAI GPT-4
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    AI Summary Implementation
 * @copyright Copyright (c) 2024
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\SystemLogger;

header('Content-Type: application/json');

// Custom logging function for AI Summary debugging
function ai_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] [generate_summary.php] $message\n", 3, "/tmp/ai_summary.log");
    error_log("AI_SUMMARY: $message"); // Also log to default error log
}

ai_log("=== AI SUMMARY GENERATION START ===");
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
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'OpenAI API key not configured. Please add OPENAI_API_KEY to your .env file or configure it in Admin → Config → Connectors.'
    ]);
    exit;
}
ai_log("OpenAI API key available (length: " . strlen($apiKey) . " characters)");

try {
    // Get form parameters
    $formId = (int)($_POST['form_id'] ?? 0);
    $pid = $_SESSION['pid'] ?? 0;
    $encounter = $_SESSION['encounter'] ?? 0;
    
    ai_log("Form parameters - formId: $formId, pid: $pid, encounter: $encounter");
    
    if (!$formId || !$pid || !$encounter) {
        throw new Exception('Missing required parameters: form_id, pid, or encounter');
    }
    
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
    ai_log("Voice transcription length: " . strlen($res['voice_transcription'] ?? ''));
    
    // Get transcription text - use existing transcription or DrVisit.md for testing
    $transcriptionText = '';
    
    if (!empty($res['voice_transcription'])) {
        $transcriptionText = $res['voice_transcription'];
        ai_log("Using existing voice transcription for summary generation (length: " . strlen($transcriptionText) . ")");
    } else {
        // For testing: use DrVisit.md content
        $drVisitPath = $GLOBALS['fileroot'] . '/_docs/DrVisit.md';
        ai_log("Checking for DrVisit.md at: $drVisitPath");
        if (file_exists($drVisitPath)) {
            $transcriptionText = file_get_contents($drVisitPath);
            ai_log("Using DrVisit.md for testing summary generation (length: " . strlen($transcriptionText) . ")");
        } else {
            ai_log("ERROR: DrVisit.md not found at expected path");
            throw new Exception('No transcription available and DrVisit.md test file not found');
        }
    }
    
    if (empty($transcriptionText)) {
        ai_log("ERROR: No transcription text available");
        throw new Exception('No transcription text available for summary generation');
    }
    ai_log("Transcription text ready for processing (first 100 chars): " . substr($transcriptionText, 0, 100) . "...");
    
    // Load AI Summary Prompt
    $promptPath = $GLOBALS['fileroot'] . '/_docs/AISummaryPrompt.md';
    ai_log("Loading AI Summary Prompt from: $promptPath");
    if (!file_exists($promptPath)) {
        ai_log("ERROR: AISummaryPrompt.md not found");
        throw new Exception('AISummaryPrompt.md not found');
    }
    
    $summaryPrompt = file_get_contents($promptPath);
    ai_log("AI Summary Prompt loaded (length: " . strlen($summaryPrompt) . " characters)");
    
    // Generate summary using OpenAI GPT-4
    ai_log("Calling OpenAI GPT-4 API for summary generation...");
    $summary = generateAISummary($transcriptionText, $summaryPrompt, $apiKey);
    ai_log("AI Summary generated successfully (length: " . strlen($summary) . " characters)");
    ai_log("Summary preview (first 200 chars): " . substr($summary, 0, 200) . "...");
    
    // Update the AI summary form with the generated summary
    ai_log("Updating database with generated summary...");
    $updateSql = "UPDATE form_ai_summary SET
                    ai_summary = ?,
                    processing_status = 'completed',
                    last_updated = NOW()
                  WHERE id = ? AND pid = ? AND encounter = ?";
    
    $updateResult = sqlStatement($updateSql, [
        $summary,
        $formId,
        $pid,
        $encounter
    ]);
    
    if (!$updateResult) {
        ai_log("ERROR: Failed to update database with AI summary");
        throw new Exception('Failed to save AI summary to database');
    }
    ai_log("Database updated successfully with AI summary");
    
    // Log success
    (new SystemLogger())->info("AI Summary generated successfully", [
        'form_id' => $formId,
        'encounter' => $encounter,
        'summary_length' => strlen($summary),
        'user' => $_SESSION['authUser'] ?? 'unknown'
    ]);
    
    ai_log("=== AI SUMMARY GENERATION SUCCESS ===");
    ai_log("Returning successful response to client");
    
    // Return success response
    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'message' => 'AI summary generated successfully',
        'form_id' => $formId
    ]);
    
} catch (Exception $e) {
    // Log error
    ai_log("=== AI SUMMARY GENERATION ERROR ===");
    ai_log("Exception: " . $e->getMessage());
    ai_log("Stack trace: " . $e->getTraceAsString());
    
    if (class_exists('OpenEMR\\Common\\Logging\\SystemLogger')) {
        (new SystemLogger())->error("AI Summary generation failed", [
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
 * Generate AI summary using OpenAI GPT-4
 *
 * @param string $transcription The voice transcription text
 * @param string $prompt The AI summary prompt
 * @param string $apiKey OpenAI API key
 * @return string Generated summary
 * @throws Exception on API errors
 */
function generateAISummary($transcription, $prompt, $apiKey) {
    ai_log("=== OPENAI API CALL START ===");
    
    $curl = curl_init();
    
    // Prepare the messages for GPT-4
    $messages = [
        [
            'role' => 'system',
            'content' => $prompt
        ],
        [
            'role' => 'user',
            'content' => "Please summarize this medical conversation:\n\n" . $transcription
        ]
    ];
    
    $requestData = [
        'model' => 'gpt-4',
        'messages' => $messages,
        'max_tokens' => 2000,
        'temperature' => 0.3
    ];
    
    ai_log("OpenAI request data prepared");
    ai_log("Model: " . $requestData['model']);
    ai_log("Max tokens: " . $requestData['max_tokens']);
    ai_log("Temperature: " . $requestData['temperature']);
    ai_log("System prompt length: " . strlen($prompt));
    ai_log("User content length: " . strlen($transcription));
    
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.openai.com/v1/chat/completions",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 120, // 2 minute timeout for summary generation
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $apiKey,
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode($requestData)
    ]);
    
    ai_log("Making cURL request to OpenAI API...");
    $startTime = microtime(true);
    $response = curl_exec($curl);
    $endTime = microtime(true);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    
    $duration = round(($endTime - $startTime) * 1000, 2);
    ai_log("OpenAI API response received");
    ai_log("HTTP code: $httpCode");
    ai_log("Response time: {$duration}ms");
    ai_log("Response length: " . strlen($response ?? ''));
    
    if ($error) {
        ai_log("cURL error: $error");
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
    
    $summary = trim($responseData['choices'][0]['message']['content']);
    
    if (empty($summary)) {
        ai_log("ERROR: Empty summary returned from OpenAI");
        throw new Exception("Empty summary returned from OpenAI");
    }
    
    ai_log("=== OPENAI API CALL SUCCESS ===");
    ai_log("Summary generated successfully (length: " . strlen($summary) . ")");
    
    return $summary;
}
?>