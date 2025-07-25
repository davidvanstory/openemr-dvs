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

// Verify CSRF token
if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    CsrfUtils::csrfNotVerified();
}

// Check for OpenAI API key
$apiKey = null;
if (getenv('OPENAI_API_KEY')) {
    $apiKey = getenv('OPENAI_API_KEY');
} elseif (isset($_ENV['OPENAI_API_KEY']) && !empty($_ENV['OPENAI_API_KEY'])) {
    $apiKey = $_ENV['OPENAI_API_KEY'];
} elseif (!empty($GLOBALS['openai_api_key'])) {
    $apiKey = $GLOBALS['openai_api_key'];
}

if (empty($apiKey)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'OpenAI API key not configured. Please add OPENAI_API_KEY to your .env file or configure it in Admin → Config → Connectors.'
    ]);
    exit;
}

try {
    // Get form parameters
    $formId = (int)($_POST['form_id'] ?? 0);
    $pid = $_SESSION['pid'] ?? 0;
    $encounter = $_SESSION['encounter'] ?? 0;
    
    if (!$formId || !$pid || !$encounter) {
        throw new Exception('Missing required parameters: form_id, pid, or encounter');
    }
    
    // Fetch existing AI summary form data
    $res = sqlQuery(
        "SELECT * FROM form_ai_summary WHERE id = ? AND pid = ? AND encounter = ?",
        array($formId, $pid, $encounter)
    );
    
    if (!$res) {
        throw new Exception('AI Summary form not found');
    }
    
    // Get transcription text - use existing transcription or DrVisit.md for testing
    $transcriptionText = '';
    
    if (!empty($res['voice_transcription'])) {
        $transcriptionText = $res['voice_transcription'];
        error_log("Using existing voice transcription for summary generation");
    } else {
        // For testing: use DrVisit.md content
        $drVisitPath = $GLOBALS['fileroot'] . '/_docs/DrVisit.md';
        if (file_exists($drVisitPath)) {
            $transcriptionText = file_get_contents($drVisitPath);
            error_log("Using DrVisit.md for testing summary generation");
        } else {
            throw new Exception('No transcription available and DrVisit.md test file not found');
        }
    }
    
    if (empty($transcriptionText)) {
        throw new Exception('No transcription text available for summary generation');
    }
    
    // Load AI Summary Prompt
    $promptPath = $GLOBALS['fileroot'] . '/_docs/AISummaryPrompt.md';
    if (!file_exists($promptPath)) {
        throw new Exception('AISummaryPrompt.md not found');
    }
    
    $summaryPrompt = file_get_contents($promptPath);
    
    // Generate summary using OpenAI GPT-4
    $summary = generateAISummary($transcriptionText, $summaryPrompt, $apiKey);
    
    // Update the AI summary form with the generated summary
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
        throw new Exception('Failed to save AI summary to database');
    }
    
    // Log success
    (new SystemLogger())->info("AI Summary generated successfully", [
        'form_id' => $formId,
        'encounter' => $encounter,
        'summary_length' => strlen($summary),
        'user' => $_SESSION['authUser'] ?? 'unknown'
    ]);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'message' => 'AI summary generated successfully',
        'form_id' => $formId
    ]);
    
} catch (Exception $e) {
    // Log error
    error_log("AI Summary generation error: " . $e->getMessage());
    (new SystemLogger())->error("AI Summary generation failed", [
        'error' => $e->getMessage(),
        'form_id' => $formId ?? 'unknown',
        'user' => $_SESSION['authUser'] ?? 'unknown'
    ]);
    
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
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    
    if ($error) {
        throw new Exception("Network error: " . $error);
    }
    
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = $errorData['error']['message'] ?? "HTTP $httpCode error";
        throw new Exception("OpenAI API error: " . $errorMessage);
    }
    
    $responseData = json_decode($response, true);
    
    if (!isset($responseData['choices'][0]['message']['content'])) {
        throw new Exception("Invalid response from OpenAI API");
    }
    
    $summary = trim($responseData['choices'][0]['message']['content']);
    
    if (empty($summary)) {
        throw new Exception("Empty summary returned from OpenAI");
    }
    
    return $summary;
}
?>