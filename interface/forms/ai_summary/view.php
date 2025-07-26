<?php
/**
 * AI Summary form view.php - Views existing AI summary forms
 *
 * @package   OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc.php");

use OpenEMR\Core\Header;

$returnurl = 'encounter_top.php';
$formid = (int)($_GET['id'] ?? 0);

// Fetch form data
$res = null;
if ($formid) {
    $res = sqlQuery(
        "SELECT * FROM form_ai_summary WHERE id = ? AND pid = ? AND encounter = ?",
        array($formid, $_SESSION['pid'], $_SESSION['encounter'])
    );
}

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt("AI Summary - View"); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body class="body_top">
    <div class="container mt-3">
        <h2><?php echo xlt("AI Summary - View"); ?></h2>
        
        <?php if ($res): ?>
            <div class="card">
                <div class="card-header">
                    <h5><?php echo xlt("Voice Transcription"); ?></h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($res['voice_transcription'])): ?>
                        <h6><?php echo xlt("Transcribed Text:"); ?></h6>
                        <div class="border-left border-primary pl-3">
                            <p style="white-space: pre-wrap;"><?php echo text($res['voice_transcription']); ?></p>
                        </div>
                    <?php else: ?>
                        <p class="text-muted"><?php echo xlt("No transcription available. Using DrVisit.md for testing."); ?></p>
                    <?php endif; ?>
                    
                    <hr>
                    <div class="text-muted">
                        <small>
                            <?php echo xlt("Generated:"); ?> <?php echo text(oeFormatShortDate($res['date'])); ?>
                            <?php if (!empty($res['user'])): ?>
                                | <?php echo xlt("By:"); ?> <?php echo text($res['user']); ?>
                            <?php endif; ?>
                        </small>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <?php echo xlt("AI Summary not found."); ?>
            </div>
        <?php endif; ?>
        
        <!-- AI Scribe Card -->
        <div class="card mt-3">
            <div class="card-header">
                <h5><?php echo xlt("AI Scribe"); ?></h5>
            </div>
            <div class="card-body">
                <button id="btn_generate_summary" class="btn btn-success" data-form-id="<?php echo attr($formid); ?>">
                    <i class="fas fa-magic"></i> <?php echo xlt("Generate Summary"); ?>
                </button>
                <div id="summary_status" class="mt-2"></div>
            </div>
        </div>
        
        <!-- AI Summary Display -->
        <?php if ($res && !empty($res['ai_summary'])): ?>
        <div class="card mt-3">
            <div class="card-header">
                <h5><?php echo xlt("AI Generated Summary"); ?></h5>
            </div>
            <div class="card-body">
                <div id="ai_summary_content" style="white-space: pre-wrap;"><?php echo text($res['ai_summary']); ?></div>
            </div>
        </div>
        <?php else: ?>
        <div id="summary_display" class="card mt-3 d-none">
            <div class="card-header">
                <h5><?php echo xlt("AI Generated Summary"); ?></h5>
            </div>
            <div class="card-body">
                <div id="ai_summary_content" style="white-space: pre-wrap;"></div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="mt-3">
            <a href="<?php echo $GLOBALS['form_exit_url']; ?>" class="btn btn-secondary">
                <?php echo xlt("Return to Encounter"); ?>
            </a>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('=== AI SUMMARY VIEW.PHP JAVASCRIPT LOADED ===');
        console.log('Page loaded at:', new Date().toISOString());
        
        const generateBtn = document.getElementById('btn_generate_summary');
        const statusDiv = document.getElementById('summary_status');
        const summaryDisplay = document.getElementById('summary_display');
        const summaryContent = document.getElementById('ai_summary_content');
        
        console.log('DOM elements found:', {
            generateBtn: !!generateBtn,
            statusDiv: !!statusDiv,
            summaryDisplay: !!summaryDisplay,
            summaryContent: !!summaryContent
        });
        
        if (generateBtn) {
            const formId = generateBtn.getAttribute('data-form-id');
            console.log('Generate Summary button found with form ID:', formId);
            
            generateBtn.addEventListener('click', async function() {
                console.log('=== GENERATE SUMMARY BUTTON CLICKED (VIEW.PHP) ===');
                console.log('Timestamp:', new Date().toISOString());
                alert('Button clicked in view.php! Check terminal for logs.');
                
                if (!formId) {
                    console.error('ERROR: No form ID available');
                    alert('<?php echo xlt("Error: No form ID available"); ?>');
                    return;
                }
                
                // Update button state
                console.log('Updating UI for processing state...');
                generateBtn.disabled = true;
                generateBtn.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> <?php echo xlt("Generating..."); ?>';
                statusDiv.innerHTML = '<div class=\"alert alert-info\"><?php echo xlt("Generating AI summary, please wait..."); ?></div>';
                
                try {
                    console.log('Preparing FormData...');
                    const formData = new FormData();
                    formData.append('form_id', formId);
                    
                    const csrfToken = document.querySelector('input[name=\"csrf_token_form\"]')?.value || '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>';
                    formData.append('csrf_token_form', csrfToken);
                    
                    console.log('Request data prepared:', {
                        form_id: formId,
                        csrf_token_form: csrfToken ? 'present' : 'missing'
                    });
                    
                    const requestUrl = 'generate_summary.php';
                    console.log('Making request to:', requestUrl);
                    
                    const startTime = performance.now();
                    const response = await fetch(requestUrl, {
                        method: 'POST',
                        body: formData
                    });
                    const endTime = performance.now();
                    
                    console.log('Response received:', {
                        status: response.status,
                        statusText: response.statusText,
                        ok: response.ok,
                        duration: Math.round(endTime - startTime) + 'ms',
                        headers: Object.fromEntries(response.headers.entries())
                    });
                    
                    if (!response.ok) {
                        const errorText = await response.text();
                        console.error('HTTP error response body:', errorText);
                        throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                    }
                    
                    console.log('Parsing JSON response...');
                    const result = await response.json();
                    console.log('Parsed response:', {
                        success: result.success,
                        message: result.message,
                        summaryLength: result.summary ? result.summary.length : 0,
                        formId: result.form_id,
                        error: result.error || 'none'
                    });
                    
                    if (result.success) {
                        console.log('=== SUCCESS: AI SUMMARY GENERATED ===');
                        console.log('Summary preview:', result.summary.substring(0, 150) + '...');
                        
                        // Display the generated summary
                        summaryContent.textContent = result.summary;
                        summaryDisplay.classList.remove('d-none');
                        
                        statusDiv.innerHTML = '<div class=\"alert alert-success\">' +
                            '<i class=\"fas fa-check-circle\"></i> <?php echo xlt("AI summary generated successfully!"); ?>' +
                            '</div>';
                        
                        console.log('Success UI updated, setting auto-hide timer');
                        // Auto-hide success message after 3 seconds
                        setTimeout(() => {
                            console.log('Auto-hiding success message');
                            statusDiv.innerHTML = '';
                        }, 3000);
                        
                    } else {
                        console.error('=== API ERROR ===');
                        console.error('Server returned error:', result.error);
                        throw new Error(result.error || 'Unknown error occurred');
                    }
                    
                } catch (error) {
                    console.error('=== SUMMARY GENERATION FAILED ===');
                    console.error('Error details:', {
                        name: error.name,
                        message: error.message,
                        stack: error.stack
                    });
                    
                    statusDiv.innerHTML = '<div class=\"alert alert-danger\">' +
                        '<i class=\"fas fa-exclamation-triangle\"></i> <?php echo xlt("Error:"); ?> ' + error.message +
                        '</div>';
                } finally {
                    console.log('Resetting button state');
                    // Reset button state
                    generateBtn.disabled = false;
                    generateBtn.innerHTML = '<i class=\"fas fa-magic\"></i> <?php echo xlt("Generate Summary"); ?>';
                    console.log('=== GENERATE SUMMARY PROCESS COMPLETE ===');
                }
            });
        } else {
            console.error('ERROR: Generate Summary button not found in DOM');
        }
    });
    </script>
</body>
</html> 