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
        const generateBtn = document.getElementById('btn_generate_summary');
        const statusDiv = document.getElementById('summary_status');
        const summaryDisplay = document.getElementById('summary_display');
        const summaryContent = document.getElementById('ai_summary_content');
        
        generateBtn.addEventListener('click', async function() {
            const formId = this.getAttribute('data-form-id');
            
            if (!formId) {
                alert('<?php echo xlt("Error: No form ID available"); ?>');
                return;
            }
            
            // Update button state
            generateBtn.disabled = true;
            generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <?php echo xlt("Generating..."); ?>';
            statusDiv.innerHTML = '<div class="alert alert-info"><?php echo xlt("Generating AI summary, please wait..."); ?></div>';
            
            try {
                const formData = new FormData();
                formData.append('form_id', formId);
                formData.append('csrf_token_form', document.querySelector('input[name="csrf_token_form"]')?.value || '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>');
                
                const response = await fetch('generate_summary.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Display the generated summary
                    summaryContent.textContent = result.summary;
                    summaryDisplay.classList.remove('d-none');
                    
                    statusDiv.innerHTML = '<div class="alert alert-success">' +
                        '<i class="fas fa-check-circle"></i> <?php echo xlt("AI summary generated successfully!"); ?>' +
                        '</div>';
                    
                    // Auto-hide success message after 3 seconds
                    setTimeout(() => {
                        statusDiv.innerHTML = '';
                    }, 3000);
                    
                } else {
                    throw new Error(result.error || 'Unknown error occurred');
                }
                
            } catch (error) {
                console.error('Summary generation error:', error);
                statusDiv.innerHTML = '<div class="alert alert-danger">' +
                    '<i class="fas fa-exclamation-triangle"></i> <?php echo xlt("Error:"); ?> ' + error.message +
                    '</div>';
            } finally {
                // Reset button state
                generateBtn.disabled = false;
                generateBtn.innerHTML = '<i class="fas fa-magic"></i> <?php echo xlt("Generate Summary"); ?>';
            }
        });
    });
    </script>
</body>
</html> 