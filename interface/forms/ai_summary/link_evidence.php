<?php

/**
 * Backend service to generate an AI evidence linking map for an existing summary.
 */

// Enable session writes for this script
$sessionAllowWrite = true;
require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Utils\TextUtil;

header('Content-Type: application/json');

/**
 * Custom logging function for the Linked Evidence feature.
 * Logs to both a dedicated file and the default PHP error log.
 *
 * @param string $message The message to log.
 */
function log_link_evidence($message)
{
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [link_evidence.php] $message\n";
    error_log($log_entry, 3, "/tmp/ai_summary.log");
    error_log("LINK_EVIDENCE: " . $message);
}

log_link_evidence("==== LINK EVIDENCE REQUEST STARTED ====");

// 1. Validate Request
if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    log_link_evidence("Error: CSRF token verification failed.");
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token failed. Please refresh and try again.']);
    exit;
}

$formId = (int)($_POST['form_id'] ?? 0);
if (!$formId) {
    log_link_evidence("Error: Missing form_id in POST request.");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Form ID is required.']);
    exit;
}
log_link_evidence("Processing request for form_id: " . $formId);

// 2. Fetch Data and Configuration
$formData = sqlQuery("SELECT voice_transcription, ai_summary FROM form_ai_summary WHERE id = ? AND pid = ?", [$formId, $_SESSION['pid']]);
if (!$formData) {
    log_link_evidence("Error: Form data not found for id: " . $formId);
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'AI Summary form not found.']);
    exit;
}

$transcript = $formData['voice_transcription'];
$summary = $formData['ai_summary'];

if (empty(trim($transcript)) || empty(trim($summary))) {
    log_link_evidence("Error: Transcript or Summary is empty for form_id: $formId. Cannot generate links.");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Transcript or Summary is empty. Cannot generate links.']);
    exit;
}

$apiKey = getenv('OPENAI_API_KEY') ?: ($GLOBALS['openai_api_key'] ?? null);
if (empty($apiKey)) {
    log_link_evidence("Error: OpenAI API key is not configured.");
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'OpenAI API key is not configured.']);
    exit;
}

// 3. Execute AI Pipeline
try {
    $linkingPrompt = file_get_contents($GLOBALS['fileroot'] . '/_docs/LinkedEvidencePrompt.md');
    $transcriptTurns = TextUtil::splitByConversationTurns($transcript);
    $summaryBlocks = TextUtil::splitSummaryIntoBlocks($summary);

    if (empty($transcriptTurns) || empty($summaryBlocks)) {
        throw new Exception("Text splitting resulted in empty arrays. Cannot proceed.");
    }

    $linkingInput = json_encode([
        'transcript_turns' => $transcriptTurns,
        'summary_blocks' => $summaryBlocks
    ]);

    $linkingPayload = [
        'model' => 'gpt-4-turbo',
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => $linkingPrompt],
            ['role' => 'user', 'content' => $linkingInput]
        ],
        'temperature' => 0.1,
    ];
    log_link_evidence("Making AI call to generate linking map. Turns: " . count($transcriptTurns) . ", Blocks: " . count($summaryBlocks));
    $linkingResponse = makeOpenAICall($linkingPayload, $apiKey);
    $linkingMapJson = $linkingResponse['choices'][0]['message']['content'];
    log_link_evidence("AI call successful. Linking map JSON received.");

    // 4. Validate and Save Linking Map
    $linkingMapData = json_decode($linkingMapJson, true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($linkingMapData['linking_map'])) {
        throw new Exception("Invalid JSON received for linking map: " . json_last_error_msg());
    }

    $transcriptCount = count($transcriptTurns);
    $summaryCount = count($summaryBlocks);
    $validatedMap = [];
    foreach ($linkingMapData['linking_map'] as $link) {
        if (isset($link['summary_index']) && is_int($link['summary_index']) && $link['summary_index'] < $summaryCount) {
            $validTranscriptIndices = [];
            if (isset($link['transcript_indices']) && is_array($link['transcript_indices'])) {
                foreach ($link['transcript_indices'] as $t_idx) {
                    if (is_int($t_idx) && $t_idx < $transcriptCount) {
                        $validTranscriptIndices[] = $t_idx;
                    }
                }
            }
            $validatedMap[] = ['summary_index' => $link['summary_index'], 'transcript_indices' => $validTranscriptIndices];
        }
    }
    $validatedLinkingJson = json_encode(['linking_map' => $validatedMap]);
    log_link_evidence("Linking map validated. Original links: " . count($linkingMapData['linking_map']) . ", Validated links: " . count($validatedMap));

    sqlStatement("UPDATE form_ai_summary SET linking_map_json = ? WHERE id = ?", [$validatedLinkingJson, $formId]);
    log_link_evidence("Saved linking map to form_id: " . $formId);

    // 5. Return Success Response
    echo json_encode(['success' => true, 'message' => 'Linking map generated successfully.']);
} catch (Exception $e) {
    log_link_evidence("FATAL ERROR during linking for form_id $formId: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Helper function to make cURL calls to OpenAI API.
 */
function makeOpenAICall(array $payload, string $apiKey): array
{
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 120,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        throw new Exception("cURL Error: " . $error);
    }
    if ($httpCode >= 400) {
        throw new Exception("OpenAI API Error (HTTP $httpCode): " . $response);
    }
    $responseData = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Failed to decode OpenAI JSON response.");
    }
    return $responseData;
}
?>