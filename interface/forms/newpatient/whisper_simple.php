<?php
/**
 * Simple OpenAI Whisper Integration - MVP Version
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Voice Recording MVP
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

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
    
    // Save transcription to AI Summary form
    $formId = null;
    try {
        $formId = saveTranscriptionToAiSummaryForm($transcription);
        error_log("AI Summary form created with ID: $formId");
    } catch (Exception $e) {
        error_log("Failed to save transcription to AI Summary form: " . $e->getMessage());
        // Continue anyway - we still want to return the transcription
    }
    
    // Log success
    if (class_exists('OpenEMR\\Common\\Logging\\SystemLogger')) {
        (new SystemLogger())->info("Voice transcription completed", [
            'user' => $_SESSION['authUser'] ?? 'unknown',
            'file_size' => $audioFile['size'],
            'transcription_length' => strlen($transcription),
            'ai_summary_form_id' => $formId
        ]);
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'transcription' => $transcription,
        'ai_summary_form_id' => $formId
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
 * Save transcription to AI Summary form and register it
 *
 * @param string $transcription The transcription text
 * @return int|false The form ID if successful, false on failure
 */
function saveTranscriptionToAiSummaryForm($transcription) {
    // Validate session data
    if (empty($_SESSION['pid']) || empty($_SESSION['encounter'])) {
        throw new Exception('Missing patient or encounter information in session');
    }
    
    // Insert into form_ai_summary table
    $sql = "INSERT INTO form_ai_summary 
            (pid, encounter, user, groupname, authorized, activity, date, 
             voice_transcription, summary_type, ai_model_used, processing_status, 
             transcription_source, created_date) 
            VALUES (?, ?, ?, ?, 1, 1, NOW(), ?, 'transcription', 'whisper-1', 'completed', 'voice_recording', NOW())";
    
    $formId = sqlInsert($sql, [
        $_SESSION['pid'],
        $_SESSION['encounter'], 
        $_SESSION['authUser'] ?? 'unknown',
        $_SESSION['authProvider'] ?? 'Default',
        $transcription
    ]);
    
    if (!$formId) {
        throw new Exception('Failed to insert transcription into database');
    }
    
    // Register the form in OpenEMR's forms table using standard function
    $addFormResult = addForm(
        $_SESSION["encounter"], 
        "AI Summary", 
        $formId, 
        "ai_summary", 
        $_SESSION["pid"], 
        $_SESSION["authUserID"] ?? 1
    );
    
    if (!$addFormResult) {
        // If form registration failed, clean up the ai_summary record
        sqlStatement("DELETE FROM form_ai_summary WHERE id = ?", [$formId]);
        throw new Exception('Failed to register form in OpenEMR');
    }
    
    return $formId;
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