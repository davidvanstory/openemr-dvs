<?php
/**
 * Simple OpenAI Whisper Integration - MVP Version with UUID-Based Transcription System
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Voice Recording MVP
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Early debug logging using same format as generate_summary.php
$timestamp = date('Y-m-d H:i:s');
error_log("[$timestamp] [whisper_simple.php] === WHISPER ENDPOINT ACCESS ===\n", 3, "/tmp/ai_summary.log");
error_log("[$timestamp] [whisper_simple.php] Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'unknown') . ", IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n", 3, "/tmp/ai_summary.log");
error_log("[$timestamp] [whisper_simple.php] POST data keys: " . implode(', ', array_keys($_POST)) . "\n", 3, "/tmp/ai_summary.log");
error_log("[$timestamp] [whisper_simple.php] FILES uploaded: " . (isset($_FILES['audio']) ? 'YES' : 'NO') . "\n", 3, "/tmp/ai_summary.log");

// Enable session writes for this script
$sessionAllowWrite = true;

try {
    require_once(__DIR__ . "/../../globals.php");
    require_once("$srcdir/forms.inc.php");
    error_log("[$timestamp] [whisper_simple.php] OpenEMR includes loaded successfully\n", 3, "/tmp/ai_summary.log");
} catch (Exception $e) {
    error_log("[$timestamp] [whisper_simple.php] ERROR: Failed to load OpenEMR includes: " . $e->getMessage() . "\n", 3, "/tmp/ai_summary.log");
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to load OpenEMR includes: ' . $e->getMessage()]);
    exit;
}

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\SystemLogger;
use Ramsey\Uuid\Uuid;

header('Content-Type: application/json');

// Custom logging function for whisper transcription debugging (same as generate_summary.php)
function whisper_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] [whisper_simple.php] $message\n", 3, "/tmp/ai_summary.log");
    error_log("WHISPER: $message"); // Also log to default error log
}

whisper_log('Whisper transcription service started - Session ID: ' . session_id() . ', User: ' . ($_SESSION['authUser'] ?? 'unknown') . ', Patient ID: ' . ($_SESSION['pid'] ?? 'none'));

// Log CSRF token debugging info
$submittedToken = $_POST["csrf_token_form"] ?? 'NOT_PROVIDED';
whisper_log('CSRF token received: ' . (empty($submittedToken) ? 'EMPTY' : 'PROVIDED (length: ' . strlen($submittedToken) . ')'));

// Verify CSRF token
if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    whisper_log('CSRF token verification failed');
    http_response_code(403);
    echo json_encode([
        'success' => false, 
        'error' => 'CSRF token verification failed. Please refresh the page and try again.'
    ]);
    exit;
}
whisper_log('CSRF token verification successful');

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
    whisper_log('OpenAI API key not configured');
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
    whisper_log('Audio upload failed: ' . $uploadError);
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
    
    whisper_log('Processing voice recording upload - File: ' . $audioFile['name'] . ', Size: ' . $audioFile['size'] . ' bytes, Type: ' . $audioFile['type']);
    
    // Call OpenAI Whisper API
    $transcription = transcribeWithWhisper($audioFile['tmp_name'], $audioFile['name'], $apiKey);
    
    whisper_log('Whisper transcription completed - Length: ' . strlen($transcription) . ' chars, Preview: ' . substr($transcription, 0, 100) . '...');
    
    // CRITICAL: Generate unique UUID for this transcription to prevent session contamination
    $transcriptionUuid = Uuid::uuid4()->toString();
    
    // Save transcription with UUID-based system
    $sessionResult = saveTranscriptionToSession($transcription, $transcriptionUuid);
    
    // CRITICAL: Write session data to storage immediately
    session_write_close();
    
    whisper_log('Transcription saved to session with UUID: ' . $transcriptionUuid . ', Session ID: ' . session_id());
    
    // Return success response
    echo json_encode([
        'success' => true,
        'transcription' => $transcription,
        'transcription_uuid' => $transcriptionUuid,
        'message' => 'Transcription saved to session for later processing',
        'debug' => [
            'session_id' => session_id(),
            'transcription_uuid' => $transcriptionUuid,
            'pending_count' => count($_SESSION['pending_ai_transcriptions'] ?? [])
        ]
    ]);
    
} catch (Exception $e) {
    whisper_log('Voice transcription failed - Error: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Save transcription with UUID-based system to prevent session contamination
 *
 * @param string $transcription The transcription text
 * @param string $transcriptionUuid Unique UUID for this transcription
 * @return array Session storage result
 */
function saveTranscriptionToSession($transcription, $transcriptionUuid)
{
    if (empty($_SESSION['pid'])) {
        throw new Exception('Missing patient information in session');
    }
    
    $currentPid = $_SESSION['pid'];
    $currentUser = $_SESSION['authUser'] ?? 'unknown';
    
    whisper_log('Starting session transcription storage - UUID: ' . $transcriptionUuid . ', Patient ID: ' . $currentPid . ', User: ' . $currentUser);
    
    // Initialize the pending transcriptions array if it does not exist
    if (!isset($_SESSION['pending_ai_transcriptions'])) {
        $_SESSION['pending_ai_transcriptions'] = [];
        whisper_log('Initialized empty pending transcriptions array');
    }
    
    // Store transcription using UUID as key (completely unique, no collisions possible)
    $transcriptionData = [
        'transcription_uuid' => $transcriptionUuid,
        'transcription' => $transcription,
        'timestamp' => time(),
        'model' => 'whisper-1',
        'pid' => $currentPid,
        'user' => $currentUser,
        'provider' => $_SESSION['authProvider'] ?? 'Default',
        'source' => 'voice_recording',
        'encounter_context' => $_SESSION['encounter'] ?? null,
        'is_new_encounter' => empty($_SESSION['encounter']),
        'session_id' => session_id()
    ];
    
    // Use UUID as the key - this prevents any possibility of key collisions
    $_SESSION['pending_ai_transcriptions'][$transcriptionUuid] = $transcriptionData;
    
    whisper_log('Transcription stored in session with UUID key: ' . $transcriptionUuid . ', Size: ' . strlen($transcription) . ' chars, New encounter: ' . ($transcriptionData['is_new_encounter'] ? 'yes' : 'no'));
    
    return [
        'transcription_uuid' => $transcriptionUuid,
        'storage_key' => $transcriptionUuid,
        'timestamp' => $transcriptionData['timestamp'],
        'session_id' => session_id()
    ];
}

/**
 * Simple function to transcribe audio using OpenAI Whisper API
 */
function transcribeWithWhisper($audioFilePath, $originalFileName, $apiKey) {
    whisper_log('Starting Whisper API call - File: ' . basename($audioFilePath) . ', Original: ' . $originalFileName);
    
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
    
    whisper_log('Whisper API request details - MIME: ' . $mimeType . ', Extension: ' . $extension . ', Model: whisper-1');
    
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
    
    $startTime = microtime(true);
    $response = curl_exec($curl);
    $endTime = microtime(true);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    
    $durationMs = round(($endTime - $startTime) * 1000);
    whisper_log('Whisper API response received - HTTP: ' . $httpCode . ', Duration: ' . $durationMs . 'ms, Length: ' . strlen($response) . ', Error: ' . (!empty($error) ? 'yes' : 'no'));
    
    if ($error) {
        whisper_log('Whisper API network error: ' . $error);
        throw new Exception("Network error: " . $error);
    }
    
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = $errorData['error']['message'] ?? "HTTP $httpCode error";
        whisper_log('Whisper API HTTP error - Code: ' . $httpCode . ', Message: ' . $errorMessage);
        throw new Exception("OpenAI API error: " . $errorMessage);
    }
    
    $transcription = trim($response);
    
    if (empty($transcription)) {
        whisper_log('Empty transcription returned from Whisper');
        throw new Exception("No transcription returned from Whisper");
    }
    
    whisper_log('Whisper transcription successful - Length: ' . strlen($transcription) . ' chars, Words: ' . str_word_count($transcription));
    
    return $transcription;
}
?> 