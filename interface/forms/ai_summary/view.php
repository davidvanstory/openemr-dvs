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
                        <p class="text-muted"><?php echo xlt("No transcription available."); ?></p>
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
        
        <div class="mt-3">
            <a href="<?php echo $GLOBALS['form_exit_url']; ?>" class="btn btn-secondary">
                <?php echo xlt("Return to Encounter"); ?>
            </a>
        </div>
    </div>
</body>
</html> 