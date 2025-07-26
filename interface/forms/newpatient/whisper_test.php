<?php
/**
 * Simple whisper test endpoint - no OpenEMR dependencies
 */

// DEBUGGING: Log that the file is being accessed
$timestamp = date('Y-m-d H:i:s');
error_log("[$timestamp] [WHISPER_TEST] whisper_test.php ACCESSED - Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'unknown') . "\n", 3, "/tmp/ai_summary.log");
error_log("[$timestamp] [WHISPER_TEST] POST data: " . print_r($_POST, true) . "\n", 3, "/tmp/ai_summary.log");
error_log("[$timestamp] [WHISPER_TEST] FILES data: " . print_r($_FILES, true) . "\n", 3, "/tmp/ai_summary.log");

header('Content-Type: application/json');

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("[$timestamp] [WHISPER_TEST] ERROR: Not a POST request\n", 3, "/tmp/ai_summary.log");
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Check if audio file was uploaded
if (!isset($_FILES['audio'])) {
    error_log("[$timestamp] [WHISPER_TEST] ERROR: No audio file in request\n", 3, "/tmp/ai_summary.log");
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No audio file uploaded']);
    exit;
}

$audioFile = $_FILES['audio'];
error_log("[$timestamp] [WHISPER_TEST] Audio file received - Name: " . $audioFile['name'] . ", Size: " . $audioFile['size'] . ", Type: " . $audioFile['type'] . "\n", 3, "/tmp/ai_summary.log");

// For testing: return a fake transcription
$fakeTranscription = "This is a test transcription generated at " . date('Y-m-d H:i:s') . ". Your recording was received successfully!";

error_log("[$timestamp] [WHISPER_TEST] SUCCESS: Returning fake transcription\n", 3, "/tmp/ai_summary.log");

echo json_encode([
    'success' => true,
    'transcription' => $fakeTranscription,
    'message' => 'Test transcription completed successfully',
    'debug' => [
        'filename' => $audioFile['name'],
        'size' => $audioFile['size'],
        'type' => $audioFile['type'],
        'timestamp' => date('Y-m-d H:i:s')
    ]
]);
?> 