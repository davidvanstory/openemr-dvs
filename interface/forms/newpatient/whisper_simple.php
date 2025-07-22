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
    
    // Log success
    if (class_exists('OpenEMR\\Common\\Logging\\SystemLogger')) {
        (new SystemLogger())->info("Voice transcription completed", [
            'user' => $_SESSION['authUser'] ?? 'unknown',
            'file_size' => $audioFile['size'],
            'transcription_length' => strlen($transcription)
        ]);
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'transcription' => $transcription
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