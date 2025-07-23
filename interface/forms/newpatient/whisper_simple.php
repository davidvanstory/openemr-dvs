<?php
/**
 * Simple OpenAI Whisper Integration - MVP Version
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Voice Recording MVP
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Enable session writes for this script
$sessionAllowWrite = true;

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/forms.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\SystemLogger;

header('Content-Type: application/json');

// Verify CSRF token
if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    CsrfUtils::csrfNotVerified();
}

// Check for OpenAI API key in multiple locations
$apiKey = null;

// First check .env style environment variables (most common)
if (getenv('OPENAI_API_KEY')) {
    $apiKey = getenv('OPENAI_API_KEY');
} elseif (isset($_ENV['OPENAI_API_KEY']) && !empty($_ENV['OPENAI_API_KEY'])) {
    $apiKey = $_ENV['OPENAI_API_KEY'];
} elseif (!empty($GLOBALS['openai_api_key'])) {
    // Check globals configuration
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

// Check if file was uploaded
if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    $uploadError = $_FILES['audio']['error'] ?? 'No file uploaded';
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Audio upload failed: $uploadError"]);
    exit;
}

try {
    $audioFile = $_FILES['audio'];
    
    // Basic file validation
    if ($audioFile['size'] > 25 * 1024 * 1024) { // 25MB limit
        throw new Exception('Audio file too large (max 25MB)');
    }
    
    // Log for debugging
    error_log("Voice recording upload - File: {$audioFile['name']}, Size: {$audioFile['size']}");
    
    // Call OpenAI Whisper API
    $transcription = transcribeWithWhisper($audioFile['tmp_name'], $audioFile['name'], $apiKey);
    
    // Save transcription temporarily in session instead of creating AI summary immediately
    // This ensures the encounter exists before we create the AI summary
    saveTranscriptionToSession($transcription);
    
    // CRITICAL: Write session data to storage immediately
    // This ensures the data is available for the next request
    session_write_close();
    
    // Log for debugging
    error_log("=== WHISPER_SIMPLE DEBUG ===");
    error_log("Transcription saved to session");
    error_log("Session ID: " . session_id());
    error_log("Encounter key used: " . ($encounterKey ?? 'unknown'));
    error_log("Session was written and closed to ensure persistence");
    
    // Log success
    if (class_exists('OpenEMR\\Common\\Logging\\SystemLogger')) {
        (new SystemLogger())->info("Voice transcription completed and stored in session", [
            'user' => $_SESSION['authUser'] ?? 'unknown',
            'file_size' => $audioFile['size'],
            'transcription_length' => strlen($transcription),
            'session_encounter' => $_SESSION['encounter'] ?? 'none'
        ]);
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'transcription' => $transcription,
        'message' => 'Transcription saved to session for later processing',
        'debug' => [
            'session_id' => session_id(),
            'encounter_key' => $encounterKey ?? 'unknown',
            'pending_count' => count($_SESSION['pending_ai_transcriptions'] ?? [])
        ]
    ]);
    
} catch (Exception $e) {
    // Log error
    error_log("Voice transcription error: " . $e->getMessage());
    if (class_exists('OpenEMR\\Common\\Logging\\SystemLogger')) {
        (new SystemLogger())->error("Voice transcription failed", [
            'error' => $e->getMessage(),
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
 * Save transcription temporarily to session for later processing
 * This ensures encounter exists before creating AI summary
 *
 * @param string $transcription The transcription text
 * @return void
 */
function saveTranscriptionToSession($transcription) {
    // For new encounters, we may not have an encounter ID yet
    // We'll use a temporary key and update it when the encounter is saved
    
    if (empty($_SESSION['pid'])) {
        throw new Exception('Missing patient information in session');
    }
    
    // Store transcription data in session
    // We use an array to support multiple transcriptions per encounter if needed
    if (!isset($_SESSION['pending_ai_transcriptions'])) {
        $_SESSION['pending_ai_transcriptions'] = [];
    }
    
    // For new encounters, use a special key that will be processed on save
    $encounterKey = !empty($_SESSION['encounter']) ? $_SESSION['encounter'] : 'pending_new_encounter';
    
    // Store with encounter ID as key (or 'pending_new_encounter' for new encounters)
    $_SESSION['pending_ai_transcriptions'][$encounterKey] = [
        'transcription' => $transcription,
        'timestamp' => time(),
        'model' => 'whisper-1',
        'pid' => $_SESSION['pid'],
        'user' => $_SESSION['authUser'] ?? 'unknown',
        'provider' => $_SESSION['authProvider'] ?? 'Default',
        'source' => 'voice_recording',
        'is_new_encounter' => empty($_SESSION['encounter'])
    ];
    
    error_log("Transcription stored in session for encounter key: " . $encounterKey);
}

/**
 * Simple function to transcribe audio using OpenAI Whisper API
 */
function transcribeWithWhisper($audioFilePath, $originalFileName, $apiKey) {
    $curl = curl_init();
    
    // Determine the best MIME type for the file
    $mimeType = 'audio/wav'; // Default
    $extension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
    
    switch($extension) {
        case 'webm':
            $mimeType = 'audio/webm';
            break;
        case 'mp3':
            $mimeType = 'audio/mpeg';
            break;
        case 'm4a':
            $mimeType = 'audio/m4a';
            break;
        case 'ogg':
            $mimeType = 'audio/ogg';
            break;
        case 'flac':
            $mimeType = 'audio/flac';
            break;
    }
    
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.openai.com/v1/audio/transcriptions",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 60, // 1 minute timeout
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $apiKey,
        ],
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($audioFilePath, $mimeType, $originalFileName),
            'model' => 'whisper-1',
            'response_format' => 'text'
        ]
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
    
    $transcription = trim($response);
    
    if (empty($transcription)) {
        throw new Exception("No transcription returned from Whisper");
    }
    
    return $transcription;
}
?> 