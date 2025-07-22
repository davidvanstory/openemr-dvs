# OpenEMR Features Summary


///
DO NOT DELETE
Prompt:
Can you summarize the files that you changed to create the new feature, and include a very brief 1 liner on the changes you made in each file. 
Also include a high level summary of what the feature is and how it integrates into the codebase. 
Add this summary to a new .md file in @/_docs called features summary. I want the summary to breif, crisp and succint. I do not want all comprehensive details recorded, only the key details needed to understand what the feature is and how it works inside the codebase. 
///


Feature #1
## Voice Recording & Transcription (MVP)

### Overview
Simple voice recording feature that allows users to record audio in the "Reason for Visit" field and automatically transcribe it using OpenAI Whisper API. Recording happens client-side with automatic format detection, transcription occurs server-side following OpenEMR AJAX patterns.

### Integration
- **Location**: New Patient Encounter Form → Reason for Visit section
- **Trigger**: Button always visible, validates API key when pressed
- **Flow**: Browser audio recording → auto-format detection → server upload → OpenAI Whisper API → text insertion

### Files Modified

| File | Change |
|------|--------|
| `library/globals.inc.php` | Added encrypted OpenAI API key configuration field |
| `interface/forms/newpatient/whisper_simple.php` | Created AJAX endpoint following OpenEMR patterns with proper session handling |
| `interface/forms/newpatient/templates/newpatient/partials/common/fields/_reason-for-visit.html.twig` | Added voice recording UI with adaptive audio format support and error handling |

### Technical Details
- **Audio Formats**: Auto-detects browser capabilities (WebM, WAV, MP3, M4A, OGG)
- **API Key Sources**: .env file, environment variables, or OpenEMR globals config
- **Security**: CSRF protection via `CsrfUtils::csrfNotVerified()`, file size limits (25MB)
- **Error Handling**: Graceful fallbacks with user-friendly messages
- **AJAX Pattern**: Follows standard OpenEMR AJAX endpoint conventions 