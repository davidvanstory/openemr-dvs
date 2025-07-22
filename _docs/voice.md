# Voice Recording & Transcription Implementation Plan for OpenEMR

## Overview

This document outlines the complete implementation plan for adding voice recording and OpenAI Whisper-powered transcription to OpenEMR's encounter forms. The goal is to allow doctors to record conversations with patients and automatically transcribe them into structured encounter notes.

## Target Location

**Primary Implementation**: Encounter Form - Reason for Visit Section
- **File**: `interface/forms/newpatient/templates/newpatient/partials/common/fields/_reason-for-visit.html.twig`
- **Rationale**: This is the central hub for patient-doctor interactions and already contains the main textarea for visit notes

## Implementation Steps

### Step 1: Database Schema Enhancement

**File**: Create migration script `sql/voice_transcription_migration.sql`

```sql
-- Add voice transcription fields to form_encounter table
ALTER TABLE form_encounter 
ADD COLUMN transcription_data LONGTEXT COMMENT 'Stored transcription text from voice recording',
ADD COLUMN recording_file_path VARCHAR(255) COMMENT 'Path to stored audio recording file',
ADD COLUMN transcription_status ENUM('none', 'recording', 'processing', 'completed', 'error') DEFAULT 'none' COMMENT 'Current status of transcription process';

-- Create voice_recordings table for detailed tracking
CREATE TABLE IF NOT EXISTS `voice_recordings` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `encounter_id` bigint(20) NOT NULL,
  `pid` bigint(20) NOT NULL,
  `user_id` varchar(255) NOT NULL,
  `recording_path` varchar(500) NOT NULL,
  `transcription_text` LONGTEXT,
  `recording_duration` int(11) DEFAULT NULL COMMENT 'Duration in seconds',
  `file_size` bigint(20) DEFAULT NULL COMMENT 'File size in bytes',
  `transcription_status` ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
  `created_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `transcribed_date` datetime DEFAULT NULL,
  `openai_request_id` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_encounter` (`encounter_id`),
  KEY `idx_patient` (`pid`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status` (`transcription_status`)
) ENGINE=InnoDB COMMENT='Voice recordings and transcriptions for encounters';
```

### Step 2: Configuration Setup

**File**: `library/globals.inc.php` (add to appropriate section)

Add new configuration options:

```php
// Voice Transcription Settings
'enable_voice_transcription' => array(
    xl('Enable Voice Transcription'),
    'bool',
    '0',
    xl('Enable voice recording and transcription functionality in encounter forms')
),

'openai_api_key' => array(
    xl('OpenAI API Key'),
    'encrypted',  // Use encrypted storage for API keys
    '',
    xl('API key for OpenAI Whisper voice transcription service. Required for voice transcription.')
),

'voice_recording_max_duration' => array(
    xl('Max Recording Duration (minutes)'),
    'num',
    '10',
    xl('Maximum duration for voice recordings in minutes')
),

'voice_transcription_language' => array(
    xl('Transcription Language'),
    'select',
    'en',
    xl('Primary language for voice transcription'),
    array(
        'en' => xl('English'),
        'es' => xl('Spanish'),
        'fr' => xl('French'),
        'de' => xl('German'),
        'auto' => xl('Auto-detect')
    )
),
```

### Step 3: Frontend Template Enhancement

**File**: `interface/forms/newpatient/templates/newpatient/partials/common/fields/_reason-for-visit.html.twig`

Replace existing template with enhanced version including voice controls:

```twig
<div class="col-sm">
    <fieldset>
        <legend>{{ "Reason for Visit"|xlt }}</legend>
        
        {% if globals.enable_voice_transcription == '1' %}
        <!-- Voice Recording Controls -->
        <div class="voice-recording-section border rounded p-3 mb-3 bg-light">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h6 class="mb-0">{{ "Voice Recording & Transcription"|xlt }}</h6>
                <small class="text-muted">{{ "Powered by OpenAI Whisper"|xlt }}</small>
            </div>
            
            <div class="voice-recording-controls mb-2">
                <div class="btn-group" role="group">
                    <button type="button" id="startRecording" class="btn btn-primary btn-sm">
                        <i class="fas fa-microphone"></i> {{ "Start Recording"|xlt }}
                    </button>
                    <button type="button" id="stopRecording" class="btn btn-danger btn-sm" disabled>
                        <i class="fas fa-stop"></i> {{ "Stop"|xlt }}
                    </button>
                    <button type="button" id="transcribeRecording" class="btn btn-success btn-sm" disabled>
                        <i class="fas fa-file-text"></i> {{ "Transcribe"|xlt }}
                    </button>
                    <button type="button" id="clearRecording" class="btn btn-outline-secondary btn-sm" disabled>
                        <i class="fas fa-trash"></i> {{ "Clear"|xlt }}
                    </button>
                </div>
                <div class="mt-2">
                    <span id="recordingStatus" class="badge badge-secondary">{{ "Ready to record"|xlt }}</span>
                    <span id="recordingTimer" class="ml-2 text-muted" style="display: none;">00:00</span>
                </div>
            </div>
            
            <!-- Audio Playback -->
            <audio id="audioPlayback" controls class="w-100 mb-2" style="display: none;"></audio>
            
            <!-- Transcription Display -->
            <div id="transcriptionResult" class="mt-2" style="display: none;">
                <label class="font-weight-bold">{{ "Transcription Result"|xlt }}:</label>
                <div class="border rounded p-3 bg-white" id="transcriptionText" style="max-height: 200px; overflow-y: auto;"></div>
                <div class="mt-2">
                    <button type="button" id="insertTranscription" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-arrow-down"></i> {{ "Insert into Reason for Visit"|xlt }}
                    </button>
                    <button type="button" id="appendTranscription" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-plus"></i> {{ "Append to Existing"|xlt }}
                    </button>
                </div>
            </div>
        </div>
        {% endif %}
        
        <div class="form-row mx-3 h-100">
            <textarea name="reason" id="reason" class="form-control" cols="80" rows="4" 
                placeholder="{{ 'Enter reason for visit or use voice recording above'|xlt }}">{%
                    if viewmode
                        %}{{ encounter.reason|default("")|text }}{%
                    else
                        %}{{ globals.default_chief_complaint|default("")|text }}{%
                    endif
            %}</textarea>
        </div>
    </fieldset>
</div>
```

### Step 4: JavaScript Implementation

**File**: `interface/forms/newpatient/js/voice-recording.js`

Create comprehensive JavaScript module:

```javascript
/**
 * Voice Recording and Transcription Module for OpenEMR
 * Integrates with OpenAI Whisper for medical transcription
 */
class VoiceRecordingTranscription {
    constructor() {
        this.mediaRecorder = null;
        this.audioChunks = [];
        this.isRecording = false;
        this.audioBlob = null;
        this.recordingStartTime = null;
        this.timerInterval = null;
        this.maxDuration = (window.voiceConfig?.maxDuration || 10) * 60 * 1000; // Convert to ms
        
        this.initializeEventListeners();
        this.checkBrowserSupport();
    }
    
    checkBrowserSupport() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            this.showError('Your browser does not support voice recording. Please use Chrome, Firefox, or Safari.');
            this.disableRecording();
            return false;
        }
        return true;
    }
    
    async initializeEventListeners() {
        const elements = {
            start: document.getElementById('startRecording'),
            stop: document.getElementById('stopRecording'),
            transcribe: document.getElementById('transcribeRecording'),
            clear: document.getElementById('clearRecording'),
            insert: document.getElementById('insertTranscription'),
            append: document.getElementById('appendTranscription')
        };
        
        // Add null checks for all elements
        Object.entries(elements).forEach(([key, element]) => {
            if (!element) {
                console.warn(`Voice recording element not found: ${key}`);
                return;
            }
        });
        
        elements.start?.addEventListener('click', () => this.startRecording());
        elements.stop?.addEventListener('click', () => this.stopRecording());
        elements.transcribe?.addEventListener('click', () => this.transcribeAudio());
        elements.clear?.addEventListener('click', () => this.clearRecording());
        elements.insert?.addEventListener('click', () => this.insertTranscription());
        elements.append?.addEventListener('click', () => this.appendTranscription());
    }
    
    async startRecording() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ 
                audio: {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true
                }
            });
            
            this.mediaRecorder = new MediaRecorder(stream, {
                mimeType: 'audio/webm;codecs=opus'
            });
            
            this.audioChunks = [];
            this.recordingStartTime = Date.now();
            
            this.mediaRecorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    this.audioChunks.push(event.data);
                }
            };
            
            this.mediaRecorder.onstop = () => {
                this.audioBlob = new Blob(this.audioChunks, { type: 'audio/webm' });
                this.displayAudioPlayback();
                this.enableTranscribeButton();
                this.stopTimer();
            };
            
            // Auto-stop after max duration
            setTimeout(() => {
                if (this.isRecording) {
                    this.stopRecording();
                    this.showWarning(`Recording automatically stopped after ${this.maxDuration/60000} minutes`);
                }
            }, this.maxDuration);
            
            this.mediaRecorder.start(1000); // Collect data every second
            this.updateUI('recording');
            this.updateStatus('Recording...', 'badge-danger');
            this.startTimer();
            
        } catch (error) {
            console.error('Error starting recording:', error);
            this.showError('Could not access microphone. Please check permissions.');
            this.updateStatus('Error accessing microphone', 'badge-danger');
        }
    }
    
    stopRecording() {
        if (this.mediaRecorder && this.isRecording) {
            this.mediaRecorder.stop();
            this.mediaRecorder.stream.getTracks().forEach(track => track.stop());
            this.updateUI('stopped');
            this.updateStatus('Recording stopped. Ready to transcribe.', 'badge-success');
        }
    }
    
    startTimer() {
        this.timerInterval = setInterval(() => {
            const elapsed = Date.now() - this.recordingStartTime;
            const seconds = Math.floor(elapsed / 1000);
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = seconds % 60;
            
            document.getElementById('recordingTimer').textContent = 
                `${minutes.toString().padStart(2, '0')}:${remainingSeconds.toString().padStart(2, '0')}`;
        }, 1000);
        
        document.getElementById('recordingTimer').style.display = 'inline';
    }
    
    stopTimer() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
        document.getElementById('recordingTimer').style.display = 'none';
    }
    
    displayAudioPlayback() {
        const audioPlayback = document.getElementById('audioPlayback');
        const audioUrl = URL.createObjectURL(this.audioBlob);
        audioPlayback.src = audioUrl;
        audioPlayback.style.display = 'block';
    }
    
    async transcribeAudio() {
        if (!this.audioBlob) {
            this.showError('No recording to transcribe');
            return;
        }
        
        this.updateStatus('Transcribing with OpenAI Whisper...', 'badge-warning');
        this.disableButton('transcribeRecording');
        
        const formData = new FormData();
        formData.append('audio', this.audioBlob, 'recording.webm');
        formData.append('csrf_token_form', document.querySelector('input[name="csrf_token_form"]').value);
        formData.append('encounter_id', window.encounterConfig?.encounter || '');
        formData.append('pid', window.encounterConfig?.pid || '');
        
        try {
            const response = await fetch('/interface/forms/newpatient/whisper_transcribe.php', {
                method: 'POST',
                body: formData
            });
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const result = await response.json();
            
            if (result.success) {
                this.displayTranscription(result.transcription);
                this.updateStatus('Transcription completed', 'badge-success');
                this.showSuccess('Transcription completed successfully!');
            } else {
                throw new Error(result.error || 'Unknown transcription error');
            }
        } catch (error) {
            console.error('Transcription error:', error);
            this.updateStatus('Transcription failed', 'badge-danger');
            this.showError('Transcription failed: ' + error.message);
        } finally {
            this.enableButton('transcribeRecording');
        }
    }
    
    displayTranscription(text) {
        document.getElementById('transcriptionText').textContent = text;
        document.getElementById('transcriptionResult').style.display = 'block';
    }
    
    insertTranscription() {
        const transcriptionText = document.getElementById('transcriptionText').textContent;
        const reasonTextarea = document.getElementById('reason');
        reasonTextarea.value = transcriptionText;
        this.showSuccess('Transcription inserted into reason for visit');
    }
    
    appendTranscription() {
        const transcriptionText = document.getElementById('transcriptionText').textContent;
        const reasonTextarea = document.getElementById('reason');
        
        if (reasonTextarea.value.trim()) {
            reasonTextarea.value += '\n\n' + transcriptionText;
        } else {
            reasonTextarea.value = transcriptionText;
        }
        
        this.showSuccess('Transcription appended to existing text');
    }
    
    clearRecording() {
        this.audioBlob = null;
        this.audioChunks = [];
        document.getElementById('audioPlayback').style.display = 'none';
        document.getElementById('transcriptionResult').style.display = 'none';
        this.updateUI('ready');
        this.updateStatus('Ready to record', 'badge-secondary');
    }
    
    updateUI(state) {
        const buttons = {
            start: document.getElementById('startRecording'),
            stop: document.getElementById('stopRecording'),
            transcribe: document.getElementById('transcribeRecording'),
            clear: document.getElementById('clearRecording')
        };
        
        switch (state) {
            case 'recording':
                buttons.start.disabled = true;
                buttons.stop.disabled = false;
                buttons.transcribe.disabled = true;
                buttons.clear.disabled = true;
                this.isRecording = true;
                break;
            case 'stopped':
                buttons.start.disabled = false;
                buttons.stop.disabled = true;
                buttons.transcribe.disabled = false;
                buttons.clear.disabled = false;
                this.isRecording = false;
                break;
            case 'ready':
                buttons.start.disabled = false;
                buttons.stop.disabled = true;
                buttons.transcribe.disabled = true;
                buttons.clear.disabled = true;
                this.isRecording = false;
                break;
        }
    }
    
    updateStatus(message, badgeClass = 'badge-secondary') {
        const statusElement = document.getElementById('recordingStatus');
        statusElement.textContent = message;
        statusElement.className = `badge ${badgeClass}`;
    }
    
    disableButton(id) {
        const button = document.getElementById(id);
        if (button) button.disabled = true;
    }
    
    enableButton(id) {
        const button = document.getElementById(id);
        if (button) button.disabled = false;
    }
    
    disableRecording() {
        this.disableButton('startRecording');
    }
    
    showError(message) {
        this.showAlert(message, 'danger');
    }
    
    showSuccess(message) {
        this.showAlert(message, 'success');
    }
    
    showWarning(message) {
        this.showAlert(message, 'warning');
    }
    
    showAlert(message, type) {
        // Use OpenEMR's existing alert system if available
        if (window.bsAlert) {
            window.bsAlert(message, type);
        } else {
            alert(message); // Fallback
        }
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('startRecording')) {
        window.voiceRecorder = new VoiceRecordingTranscription();
    }
});
```

### Step 5: Backend PHP Implementation

**File**: `interface/forms/newpatient/whisper_transcribe.php`

```php
<?php
/**
 * OpenAI Whisper Integration for Voice Transcription
 * 
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    [Your Name]
 * @copyright Copyright (c) 2024
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\DocumentService;

header('Content-Type: application/json');

// Verify CSRF token
if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token verification failed']);
    exit;
}

// Check if voice transcription is enabled
if ($GLOBALS['enable_voice_transcription'] != '1') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Voice transcription is not enabled']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No audio file uploaded or upload failed']);
    exit;
}

try {
    $audioFile = $_FILES['audio'];
    $encounterId = $_POST['encounter_id'] ?? null;
    $pid = $_POST['pid'] ?? null;
    
    // Validate encounter and patient
    if (!$encounterId || !$pid) {
        throw new Exception('Missing encounter or patient ID');
    }
    
    // Validate file size (max 25MB as per OpenAI limits)
    $maxSize = 25 * 1024 * 1024; // 25MB
    if ($audioFile['size'] > $maxSize) {
        throw new Exception('Audio file too large. Maximum size is 25MB.');
    }
    
    // Validate file type
    $allowedTypes = ['audio/webm', 'audio/wav', 'audio/mp3', 'audio/m4a'];
    $fileType = mime_content_type($audioFile['tmp_name']);
    if (!in_array($fileType, $allowedTypes)) {
        throw new Exception('Invalid audio file type. Allowed: WebM, WAV, MP3, M4A');
    }
    
    // Convert WebM to WAV for better compatibility
    $processedAudioPath = convertAudioForWhisper($audioFile['tmp_name'], $audioFile['type']);
    
    // Store recording metadata in database
    $recordingId = storeRecordingMetadata($encounterId, $pid, $audioFile);
    
    // Call OpenAI Whisper API
    $transcription = transcribeWithWhisper($processedAudioPath);
    
    // Update database with transcription result
    updateTranscriptionResult($recordingId, $transcription);
    
    // Store audio file permanently (optional)
    $storedPath = storeAudioFile($audioFile, $encounterId, $pid);
    
    // Log the transcription event
    (new SystemLogger())->info("Voice transcription completed", [
        'recording_id' => $recordingId,
        'encounter_id' => $encounterId,
        'pid' => $pid,
        'user' => $_SESSION['authUser'] ?? null,
        'transcription_length' => strlen($transcription),
        'audio_duration' => getAudioDuration($processedAudioPath)
    ]);
    
    // Clean up temporary files
    if (file_exists($processedAudioPath) && $processedAudioPath !== $audioFile['tmp_name']) {
        unlink($processedAudioPath);
    }
    
    // Return successful response
    echo json_encode([
        'success' => true,
        'transcription' => $transcription,
        'recording_id' => $recordingId,
        'metadata' => [
            'duration' => getAudioDuration($audioFile['tmp_name']),
            'file_size' => $audioFile['size'],
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ]);
    
} catch (Exception $e) {
    (new SystemLogger())->error("Voice transcription failed", [
        'error' => $e->getMessage(),
        'encounter_id' => $encounterId ?? null,
        'pid' => $pid ?? null,
        'user' => $_SESSION['authUser'] ?? null,
        'file_info' => [
            'name' => $_FILES['audio']['name'] ?? null,
            'size' => $_FILES['audio']['size'] ?? null,
            'type' => $_FILES['audio']['type'] ?? null
        ]
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Store recording metadata in database
 */
function storeRecordingMetadata($encounterId, $pid, $audioFile) {
    $sql = "INSERT INTO voice_recordings 
            (encounter_id, pid, user_id, recording_path, file_size, transcription_status, created_date) 
            VALUES (?, ?, ?, ?, ?, 'processing', NOW())";
    
    $recordingId = sqlInsert($sql, [
        $encounterId,
        $pid,
        $_SESSION['authUser'],
        '', // Will be updated after file storage
        $audioFile['size']
    ]);
    
    return $recordingId;
}

/**
 * Update transcription result in database
 */
function updateTranscriptionResult($recordingId, $transcription) {
    $sql = "UPDATE voice_recordings 
            SET transcription_text = ?, transcription_status = 'completed', transcribed_date = NOW() 
            WHERE id = ?";
    
    sqlStatement($sql, [$transcription, $recordingId]);
}

/**
 * Convert audio file for Whisper compatibility
 */
function convertAudioForWhisper($inputPath, $mimeType) {
    // If already in a good format, return as-is
    if (in_array($mimeType, ['audio/wav', 'audio/mp3', 'audio/m4a'])) {
        return $inputPath;
    }
    
    // For WebM, we might need to convert to WAV
    // This would require FFmpeg or similar tool
    // For now, return original path and let Whisper handle it
    return $inputPath;
}

/**
 * Store audio file permanently in OpenEMR document system
 */
function storeAudioFile($audioFile, $encounterId, $pid) {
    // Create documents directory if not exists
    $documentsDir = $GLOBALS['OE_SITE_DIR'] . '/documents/voice_recordings';
    if (!is_dir($documentsDir)) {
        mkdir($documentsDir, 0755, true);
    }
    
    // Generate unique filename
    $extension = pathinfo($audioFile['name'], PATHINFO_EXTENSION);
    $filename = "voice_recording_{$encounterId}_{$pid}_" . date('YmdHis') . ".{$extension}";
    $targetPath = $documentsDir . '/' . $filename;
    
    // Move uploaded file to permanent location
    if (move_uploaded_file($audioFile['tmp_name'], $targetPath)) {
        // Optional: Add to OpenEMR document system
        // This would require integrating with DocumentService
        return $targetPath;
    }
    
    throw new Exception('Failed to store audio file');
}

/**
 * Get audio duration (requires additional tools like FFmpeg)
 */
function getAudioDuration($filePath) {
    // Placeholder - would need FFmpeg or similar to get actual duration
    // For now, estimate based on file size (very rough)
    $fileSize = filesize($filePath);
    $estimatedDuration = round($fileSize / 16000); // Rough estimate for compressed audio
    return $estimatedDuration;
}

/**
 * Transcribe audio using OpenAI Whisper API
 */
function transcribeWithWhisper($audioFilePath) {
    $apiKey = $GLOBALS['openai_api_key'] ?? null;
    
    if (!$apiKey) {
        throw new Exception('OpenAI API key not configured. Please set up API key in Administration > Globals.');
    }
    
    $language = $GLOBALS['voice_transcription_language'] ?? 'en';
    if ($language === 'auto') {
        $language = null; // Let Whisper auto-detect
    }
    
    $curl = curl_init();
    
    $postFields = [
        'file' => new CURLFile($audioFilePath, mime_content_type($audioFilePath), basename($audioFilePath)),
        'model' => 'whisper-1',
        'response_format' => 'text',
        'temperature' => '0' // Lower temperature for more consistent results
    ];
    
    if ($language) {
        $postFields['language'] = $language;
    }
    
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.openai.com/v1/audio/transcriptions",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 120, // 2 minute timeout
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $apiKey,
            "Content-Type: multipart/form-data"
        ],
        CURLOPT_POSTFIELDS => $postFields
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
        $errorMessage = $errorData['error']['message'] ?? "HTTP $httpCode: $response";
        throw new Exception("OpenAI API error: " . $errorMessage);
    }
    
    $transcription = trim($response);
    
    if (empty($transcription)) {
        throw new Exception("No transcription returned from OpenAI Whisper");
    }
    
    return $transcription;
}
?>
```

### Step 6: Include JavaScript in Template

**File**: `interface/forms/newpatient/templates/newpatient/partials/common/_body-scripts.html.twig`

Add voice recording script inclusion:

```twig
{# Existing scripts... #}

{% if globals.enable_voice_transcription == '1' %}
<script src="{{ webroot }}/interface/forms/newpatient/js/voice-recording.js"></script>
<script>
    // Configuration for voice recording
    window.voiceConfig = {
        maxDuration: {{ globals.voice_recording_max_duration|default(10) }},
        language: {{ globals.voice_transcription_language|default('en')|js_escape }}
    };
    
    // Encounter configuration for API calls
    window.encounterConfig = {
        encounter: {{ encounter.encounter|default('')|js_escape }},
        pid: {{ pid|js_escape }}
    };
</script>
{% endif %}
```

### Step 7: CSS Styling

**File**: `interface/forms/newpatient/css/voice-recording.css`

```css
/* Voice Recording Component Styles */
.voice-recording-section {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: 1px solid #dee2e6;
    transition: all 0.3s ease;
}

.voice-recording-section:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.voice-recording-controls .btn {
    transition: all 0.2s ease;
    margin-right: 5px;
}

.voice-recording-controls .btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

#recordingTimer {
    font-family: 'Courier New', monospace;
    font-weight: bold;
}

#transcriptionText {
    font-family: inherit;
    line-height: 1.6;
    white-space: pre-wrap;
}

.recording-pulse {
    animation: pulse 1.5s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .voice-recording-controls .btn {
        margin-bottom: 5px;
    }
    
    .voice-recording-controls .btn-group {
        flex-direction: column;
        width: 100%;
    }
    
    .voice-recording-controls .btn {
        margin-right: 0;
    }
}
```

## Implementation Todo List

### Phase 1: Foundation Setup
- [ ] **Database Migration**
  - [ ] Create `sql/voice_transcription_migration.sql`
  - [ ] Add fields to `form_encounter` table
  - [ ] Create `voice_recordings` table
  - [ ] Test migration on development database
  - [ ] Add rollback script

- [ ] **Configuration Setup**
  - [ ] Add voice transcription settings to `library/globals.inc.php`
  - [ ] Create admin interface for OpenAI API key management
  - [ ] Add validation for API key format
  - [ ] Set up encrypted storage for API key
  - [ ] Create default configuration values

### Phase 2: Backend Implementation
- [ ] **PHP Backend Development**
  - [ ] Create `interface/forms/newpatient/whisper_transcribe.php`
  - [ ] Implement file upload validation
  - [ ] Add audio format conversion support
  - [ ] Implement OpenAI Whisper API integration
  - [ ] Add error handling and logging
  - [ ] Create database storage functions
  - [ ] Add audio file management system
  - [ ] Implement CSRF protection
  - [ ] Add rate limiting for API calls

- [ ] **Security Implementation**
  - [ ] Validate file types and sizes
  - [ ] Implement proper file storage permissions
  - [ ] Add user authorization checks
  - [ ] Sanitize all inputs
  - [ ] Add audit logging
  - [ ] Implement API key encryption

### Phase 3: Frontend Development
- [ ] **Template Enhancement**
  - [ ] Modify `_reason-for-visit.html.twig`
  - [ ] Add voice recording controls
  - [ ] Implement responsive design
  - [ ] Add loading states and feedback
  - [ ] Create conditional display logic

- [ ] **JavaScript Implementation**
  - [ ] Create `voice-recording.js` module
  - [ ] Implement MediaRecorder API integration
  - [ ] Add browser compatibility checks
  - [ ] Implement recording timer
  - [ ] Add audio playback functionality
  - [ ] Create transcription display logic
  - [ ] Add error handling and user feedback
  - [ ] Implement file size validation

- [ ] **CSS Styling**
  - [ ] Create `voice-recording.css`
  - [ ] Design voice recording interface
  - [ ] Add responsive breakpoints
  - [ ] Implement accessibility features
  - [ ] Add loading animations
  - [ ] Create error state styling

### Phase 4: Integration & Testing
- [ ] **Script Integration**
  - [ ] Update `_body-scripts.html.twig`
  - [ ] Add configuration variables
  - [ ] Test script loading order
  - [ ] Implement fallbacks for missing dependencies

- [ ] **Testing & Validation**
  - [ ] Test cross-browser compatibility
  - [ ] Validate mobile device support
  - [ ] Test different audio formats
  - [ ] Verify API rate limiting
  - [ ] Test error scenarios
  - [ ] Validate security measures
  - [ ] Performance testing with large files
  - [ ] Test transcription accuracy

### Phase 5: Advanced Features
- [ ] **Enhanced Functionality**
  - [ ] Add support for multiple languages
  - [ ] Implement real-time transcription
  - [ ] Add speaker identification
  - [ ] Create custom medical vocabulary
  - [ ] Add audio file compression
  - [ ] Implement batch transcription

- [ ] **User Experience Improvements**
  - [ ] Add keyboard shortcuts
  - [ ] Implement drag-and-drop file upload
  - [ ] Add transcription editing capabilities
  - [ ] Create transcription history
  - [ ] Add export functionality
  - [ ] Implement search within transcriptions

### Phase 6: Extension & Optimization
- [ ] **Multi-Form Integration**
  - [ ] Extend to SOAP forms
  - [ ] Add to Clinical Notes
  - [ ] Enhance Dictation form
  - [ ] Integration with Progress Notes
  - [ ] Add to custom forms

- [ ] **Performance Optimization**
  - [ ] Implement audio compression
  - [ ] Add caching for repeated requests
  - [ ] Optimize database queries
  - [ ] Add background processing
  - [ ] Implement cleanup routines
  - [ ] Add monitoring and metrics

### Phase 7: Documentation & Deployment
- [ ] **Documentation**
  - [ ] Create user guide
  - [ ] Write administrator setup guide
  - [ ] Document API integration
  - [ ] Create troubleshooting guide
  - [ ] Add security best practices
  - [ ] Create training materials

- [ ] **Deployment Preparation**
  - [ ] Create installation scripts
  - [ ] Add upgrade procedures
  - [ ] Test on different environments
  - [ ] Create backup procedures
  - [ ] Add monitoring setup
  - [ ] Create rollback procedures

## Prerequisites

### System Requirements
- **PHP**: 7.4+ with cURL extension
- **Database**: MySQL/MariaDB with ALTER privileges
- **Web Server**: Apache/Nginx with file upload support
- **Browser**: Chrome 66+, Firefox 60+, Safari 12+, Edge 79+
- **OpenAI Account**: Valid API key with Whisper access
- **Storage**: Adequate space for audio file storage

### Dependencies
- **OpenEMR**: Version 7.0.0+
- **JavaScript**: ES6+ support
- **CSS**: Bootstrap 4+ (existing in OpenEMR)
- **Audio API**: MediaRecorder API support
- **File API**: File upload capabilities

## Security Considerations

1. **API Key Management**: Store OpenAI API keys encrypted
2. **File Validation**: Strict validation of uploaded audio files
3. **Access Control**: Verify user permissions for recordings
4. **Data Privacy**: Ensure HIPAA compliance for audio storage
5. **Audit Logging**: Log all transcription activities
6. **Rate Limiting**: Prevent API abuse
7. **File Storage**: Secure storage with proper permissions

## Testing Strategy

1. **Unit Tests**: Test individual components
2. **Integration Tests**: Test API integrations
3. **Browser Tests**: Cross-browser compatibility
4. **Security Tests**: Validate security measures
5. **Performance Tests**: Test with various file sizes
6. **User Acceptance Tests**: Validate workflow with medical professionals

## Monitoring & Maintenance

1. **API Usage Monitoring**: Track OpenAI API calls and costs
2. **Error Monitoring**: Log and alert on transcription failures
3. **Performance Monitoring**: Track response times and success rates
4. **Storage Monitoring**: Monitor audio file storage usage
5. **Security Monitoring**: Track unauthorized access attempts

## Future Enhancements

1. **Real-time Transcription**: Stream audio for live transcription
2. **Medical Vocabulary**: Custom medical terminology training
3. **Multi-speaker Support**: Identify different speakers
4. **Integration with EHR**: Auto-populate other form fields
5. **Mobile App Support**: Native mobile recording capabilities
6. **Voice Commands**: Voice-controlled form navigation
