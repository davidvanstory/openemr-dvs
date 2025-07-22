# Voice Recording MVP - Simple Speech-to-Text

## Overview

This is a minimal viable product (MVP) implementation for voice recording and transcription in OpenEMR. It adds a simple button under the "Reason for Visit" field that:

1. Records audio from the microphone (NO live transcription)
2. Sends the complete recording to OpenAI Whisper API after recording stops
3. Inserts the transcribed text into the "Reason for Visit" textarea

## MVP Features

- ✅ Single "Record & Transcribe" button
- ✅ Browser-based audio recording
- ✅ OpenAI Whisper API integration
- ✅ Text appears ONLY after recording stops (no live transcription)
- ✅ Direct insertion into existing textarea
- ✅ Basic error handling
- ✅ Simple visual feedback

## Files to Modify/Create

### 1. Template Enhancement
**File**: `interface/forms/newpatient/templates/newpatient/partials/common/fields/_reason-for-visit.html.twig`

### 2. Backend API Handler
**File**: `interface/forms/newpatient/whisper_simple.php` (NEW)

### 3. Configuration
**File**: `library/globals.inc.php` (minimal addition)

## Simple Todo List

### Phase 1: Basic Setup
- [ ] Add OpenAI API key configuration to globals
- [ ] Create simple whisper API handler file
- [ ] Test API key and whisper connection

### Phase 2: Frontend Implementation
- [ ] Add recording button to reason-for-visit template
- [ ] Add basic JavaScript for audio recording
- [ ] Add JavaScript for API call to whisper endpoint
- [ ] Add visual feedback (recording status)

### Phase 3: Integration & Testing
- [ ] Test complete workflow: record → transcribe → insert
- [ ] Test error scenarios (no mic, API failure)
- [ ] Test on different browsers
- [ ] Verify CSRF protection

## Code Implementation

### 1. Template Modification

**File**: `interface/forms/newpatient/templates/newpatient/partials/common/fields/_reason-for-visit.html.twig`

```twig
<div class="col-sm">
    <fieldset>
        <legend>{{ "Reason for Visit"|xlt }}</legend>
        <div class="form-row mx-3 h-100">
            <textarea name="reason" id="reason" class="form-control" cols="80" rows="4">{%
                    if viewmode
                        %}{{ encounter.reason|default("")|text }}{%
                    else
                        %}{{ globals.default_chief_complaint|default("")|text }}{%
                    endif
            %}</textarea>
            
            <!-- Simple Voice Recording Button -->
            {% if globals.openai_api_key %}
            <div class="mt-2">
                <button type="button" id="voiceRecordBtn" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-microphone" id="micIcon"></i>
                    <span id="btnText">{{ "Record & Transcribe"|xlt }}</span>
                </button>
                <small id="voiceStatus" class="ml-2 text-muted">{{ "Click to start recording"|xlt }}</small>
            </div>
            {% endif %}
        </div>
    </fieldset>
</div>

<!-- Simple Voice Recording Script -->
{% if globals.openai_api_key %}
<script>
document.addEventListener('DOMContentLoaded', function() {
    let mediaRecorder;
    let audioChunks = [];
    let isRecording = false;
    
    const btn = document.getElementById('voiceRecordBtn');
    const status = document.getElementById('voiceStatus');
    const btnText = document.getElementById('btnText');
    const micIcon = document.getElementById('micIcon');
    const reasonField = document.getElementById('reason');
    
    btn.addEventListener('click', async function() {
        if (!isRecording) {
            await startRecording();
        } else {
            stopRecording();
        }
    });
    
    async function startRecording() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            mediaRecorder = new MediaRecorder(stream);
            audioChunks = [];
            
            mediaRecorder.ondataavailable = function(event) {
                audioChunks.push(event.data);
            };
            
            mediaRecorder.onstop = async function() {
                const audioBlob = new Blob(audioChunks, { type: 'audio/wav' });
                await transcribeAudio(audioBlob);
                stream.getTracks().forEach(track => track.stop());
            };
            
            mediaRecorder.start();
            isRecording = true;
            
            btn.className = 'btn btn-danger btn-sm';
            btnText.textContent = '{{ "Stop Recording"|xlt }}';
            micIcon.className = 'fas fa-stop';
            status.textContent = '{{ "Recording... Click to stop"|xlt }}';
            // NO live transcription happening here - just recording audio
            
        } catch (error) {
            console.error('Error accessing microphone:', error);
            status.textContent = '{{ "Error: Could not access microphone"|xlt }}';
            status.className = 'ml-2 text-danger';
        }
    }
    
    function stopRecording() {
        if (mediaRecorder && isRecording) {
            mediaRecorder.stop();
            isRecording = false;
            
            btn.className = 'btn btn-outline-primary btn-sm';
            btnText.textContent = '{{ "Transcribing..."|xlt }}';
            micIcon.className = 'fas fa-spinner fa-spin';
            status.textContent = '{{ "Processing audio with Whisper..."|xlt }}';
            btn.disabled = true;
        }
    }
    
    async function transcribeAudio(audioBlob) {
        const formData = new FormData();
        formData.append('audio', audioBlob, 'recording.wav');
        formData.append('csrf_token_form', document.querySelector('input[name="csrf_token_form"]').value);
        
        try {
            const response = await fetch('{{ webroot }}/interface/forms/newpatient/whisper_simple.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Insert transcription into reason field ONLY after recording is complete
                if (reasonField.value.trim()) {
                    reasonField.value += '\n\n' + result.transcription;
                } else {
                    reasonField.value = result.transcription;
                }
                status.textContent = '{{ "Transcription completed!"|xlt }}';
                status.className = 'ml-2 text-success';
            } else {
                throw new Error(result.error || 'Unknown error');
            }
            
        } catch (error) {
            console.error('Transcription error:', error);
            status.textContent = '{{ "Transcription failed"|xlt }}';
            status.className = 'ml-2 text-danger';
        } finally {
            // Reset button
            btn.disabled = false;
            btnText.textContent = '{{ "Record & Transcribe"|xlt }}';
            micIcon.className = 'fas fa-microphone';
            btn.className = 'btn btn-outline-primary btn-sm';
        }
    }
});
</script>
{% endif %}
```

### 2. Backend API Handler

**File**: `interface/forms/newpatient/whisper_simple.php` (NEW FILE)

```php
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
require_once("$srcdir/api.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\SystemLogger;

header('Content-Type: application/json');

// Verify CSRF token
if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF token verification failed']);
    exit;
}

// Check if OpenAI API key is configured
if (empty($GLOBALS['openai_api_key'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'OpenAI API key not configured']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No audio file uploaded']);
    exit;
}

try {
    $audioFile = $_FILES['audio'];
    
    // Basic file validation
    if ($audioFile['size'] > 25 * 1024 * 1024) { // 25MB limit
        throw new Exception('Audio file too large (max 25MB)');
    }
    
    // Call OpenAI Whisper API
    $transcription = transcribeWithWhisper($audioFile['tmp_name']);
    
    // Log success
    (new SystemLogger())->info("Voice transcription completed", [
        'user' => $_SESSION['authUser'] ?? 'unknown',
        'file_size' => $audioFile['size'],
        'transcription_length' => strlen($transcription)
    ]);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'transcription' => $transcription
    ]);
    
} catch (Exception $e) {
    // Log error
    (new SystemLogger())->error("Voice transcription failed", [
        'error' => $e->getMessage(),
        'user' => $_SESSION['authUser'] ?? 'unknown'
    ]);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Simple function to transcribe audio using OpenAI Whisper API
 */
function transcribeWithWhisper($audioFilePath) {
    $apiKey = $GLOBALS['openai_api_key'];
    
    $curl = curl_init();
    
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.openai.com/v1/audio/transcriptions",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 60, // 1 minute timeout
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $apiKey,
            "Content-Type: multipart/form-data"
        ],
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($audioFilePath, 'audio/wav', 'recording.wav'),
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
```

### 3. Configuration Addition

**File**: `library/globals.inc.php` (add to appropriate section)

```php
// Voice Transcription - MVP
'openai_api_key' => array(
    xl('OpenAI API Key'),
    'text',  // For MVP, use text. In production, use 'encrypted'
    '',
    xl('API key for OpenAI Whisper voice transcription service')
),
```

## Setup Instructions

### 1. Get OpenAI API Key
1. Go to https://platform.openai.com/
2. Create account or sign in
3. Go to API Keys section
4. Create new API key
5. Copy the key (starts with `sk-`)

### 2. Configure OpenEMR
1. Go to **Administration** → **Globals** → **Features**
2. Find "OpenAI API Key" setting
3. Paste your API key
4. Save changes

### 3. Test the Feature
1. Go to create a new encounter
2. Look for the "Record & Transcribe" button under "Reason for Visit"
3. Click the button to start recording
4. Speak clearly for a few seconds
5. Click "Stop Recording" 
6. Wait for transcription to appear in the text field

## Browser Requirements

- **Chrome**: Version 66+
- **Firefox**: Version 60+
- **Safari**: Version 12+
- **Edge**: Version 79+

## Troubleshooting

### Common Issues:

1. **Button doesn't appear**: Check that OpenAI API key is configured
2. **"Could not access microphone"**: Allow microphone permissions in browser
3. **"Transcription failed"**: Check API key is valid and has credits
4. **No sound recording**: Check microphone is working in other apps

### Testing API Key:
You can test your API key with this curl command:
```bash
curl -X POST "https://api.openai.com/v1/audio/transcriptions" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -F "file=@test_audio.wav" \
  -F "model=whisper-1"
```

## Limitations of MVP

- No recording time limit (stops manually)
- No audio playback before transcription
- No recording indicator beyond button text
- No support for multiple languages
- No file storage (transcription only)
- Basic error handling
- No retry mechanism

## Next Steps for Full Version

After MVP is working, you can enhance with:
- Recording timer and auto-stop
- Audio playback controls
- Better visual feedback
- Multiple language support
- Recording storage and management
- Enhanced error handling and recovery
- Security improvements (API key encryption)

## Cost Considerations

OpenAI Whisper API pricing (as of 2024):
- **$0.006 per minute** of audio
- Example: 5-minute recording = $0.03
- 100 recordings per month = ~$3.00

Monitor usage in OpenAI dashboard to track costs.
