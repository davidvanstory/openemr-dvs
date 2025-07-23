<?php
/**
 * AI Summary form save.php - Processes form submissions
 *
 * @package   OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc.php");
require_once("$srcdir/forms.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Services\EncounterService;
use OpenEMR\Common\Uuid\UuidRegistry;

// Verify CSRF token
if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    CsrfUtils::csrfNotVerified();
}

$returnurl = 'encounter_top.php';

// Get form data
$id = $_POST['id'] ?? '';
$pid = $_SESSION['pid'];
$encounter = $_SESSION['encounter'];
$voice_transcription = $_POST['voice_transcription'] ?? '';

// Get encounter UUID for proper linking
$encounterUuid = null;
if ($encounter) {
    $encounterUuid = EncounterService::getUuidById($encounter, 'form_encounter', 'encounter');
    if (!$encounterUuid) {
        error_log("Warning: Could not find UUID for encounter $encounter");
    }
}

// Process the form
if ($id) {
    // Update existing form
    $sql = "UPDATE form_ai_summary SET 
            voice_transcription = ?, 
            last_updated = NOW()
            WHERE id = ? AND pid = ? AND encounter = ?";
    
    sqlStatement($sql, [
        $voice_transcription,
        $id,
        $pid,
        $encounter
    ]);
} else {
    // Create new form with encounter UUID
    $sql = "INSERT INTO form_ai_summary 
            (pid, encounter, encounter_uuid, user, groupname, authorized, activity, date,
             voice_transcription, summary_type, processing_status, created_date) 
            VALUES (?, ?, ?, ?, ?, 1, 1, NOW(), ?, 'transcription', 'completed', NOW())";
    
    $formId = sqlInsert($sql, [
        $pid,
        $encounter,
        $encounterUuid,
        $_SESSION['authUser'],
        $_SESSION['authProvider'],
        $voice_transcription
    ]);
    
    if ($formId) {
        // Register the form
        addForm($encounter, "AI Summary", $formId, "ai_summary", $pid, $_SESSION["authUserID"]);
        
        // Log the creation with UUID info
        (new SystemLogger())->info("AI Summary form created manually", [
            'form_id' => $formId,
            'encounter' => $encounter,
            'encounter_uuid' => $encounterUuid ? UuidRegistry::uuidToString($encounterUuid) : 'none'
        ]);
    }
}

// Redirect back to encounter
formHeader("Redirecting....");
formJump($returnurl);
formFooter(); 