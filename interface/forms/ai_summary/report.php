<?php
/**
 * AI Summary form report function for encounter summary display
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Voice Transcription Implementation
 * @copyright Copyright (c) 2024
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Uuid\UuidRegistry;

/**
 * Display AI Summary form data in encounter summary
 *
 * @param int $pid Patient ID
 * @param int $encounter Encounter ID  
 * @param int $cols Number of columns (unused but required by interface)
 * @param int $id Form ID
 * @return void Outputs HTML directly
 */
function ai_summary_report($pid, $encounter, $cols, $id): void
{
    // Fetch the AI summary data with encounter information
    $res = sqlQuery(
        "SELECT ais.*, 
                fe.date as encounter_date,
                fe.reason as encounter_reason,
                fe.uuid as current_encounter_uuid
         FROM form_ai_summary ais
         LEFT JOIN form_encounter fe ON fe.uuid = ais.encounter_uuid
         WHERE ais.id = ? AND ais.pid = ? AND ais.encounter = ?", 
        array($id, $pid, $encounter)
    );
    
    if (!$res) {
        echo "<div class='ai-summary-report'>";
        echo "<p class='text-muted'>" . xlt("No AI summary data found.") . "</p>";
        echo "</div>";
        return;
    }
    
    echo "<div class='ai-summary-report border rounded p-3 mb-3'>";
    echo "<h5 class='text-primary mb-3'>" . xlt("Voice Transcription") . "</h5>";
    
    // Display voice transcription if available
    if (!empty($res['voice_transcription'])) {
        echo "<div class='transcription-content mb-3'>";
        echo "<h6 class='text-secondary'>" . xlt("Transcribed Text:") . "</h6>";
        echo "<div class='border-left border-primary pl-3'>";
        echo "<p class='mb-0'>" . text($res['voice_transcription']) . "</p>";
        echo "</div>";
        echo "</div>";
    }
    
    // Display AI summary if available (for future use)
    if (!empty($res['ai_summary'])) {
        echo "<div class='summary-content mb-3'>";
        echo "<h6 class='text-secondary'>" . xlt("AI Summary:") . "</h6>";
        echo "<div class='border-left border-success pl-3'>";
        echo "<p class='mb-0'>" . text($res['ai_summary']) . "</p>";
        echo "</div>";
        echo "</div>";
    }
    
    // AI Scribe Card with Generate Summary button
    echo "<div class='card mb-3'>";
    echo "  <div class='card-header'>";
    echo "    <h5>" . xlt("AI Scribe") . "</h5>";
    echo "  </div>";
    echo "  <div class='card-body'>";
    echo "    <button id='btn_generate_summary_" . attr($id) . "' class='btn btn-success'>" . xlt("Generate Summary") . "</button>";
    echo "  </div>";
    echo "</div>";
    
    // Display metadata
    echo "<div class='summary-meta mt-3 pt-2 border-top'>";
    echo "<small class='text-muted'>";
    echo xlt("Generated:") . " " . text(oeFormatShortDate($res['date']));
    
    if (!empty($res['ai_model_used'])) {
        echo " | " . xlt("Model:") . " " . text($res['ai_model_used']);
    }
    
    if (!empty($res['processing_status'])) {
        $statusClass = ($res['processing_status'] == 'completed') ? 'success' : 
                      (($res['processing_status'] == 'failed') ? 'danger' : 'warning');
        echo " | <span class='badge badge-" . $statusClass . "'>" . 
             text(ucfirst($res['processing_status'])) . "</span>";
    }
    
    // Display encounter linking status
    if (!empty($res['encounter_uuid'])) {
        $uuidString = UuidRegistry::uuidToString($res['encounter_uuid']);
        echo "<br/>" . xlt("Linked to encounter:") . " ";
        
        if (!empty($res['encounter_date'])) {
            echo text(oeFormatShortDate($res['encounter_date']));
            echo " <span class='text-success'><i class='fa fa-check-circle'></i> " . 
                 xlt("Verified") . "</span>";
        } else {
            echo "<span class='text-warning'><i class='fa fa-exclamation-triangle'></i> " . 
                 xlt("UUID not found") . "</span>";
        }
        
        // Display UUID in debug mode
        if ($GLOBALS['debug_mode'] ?? false) {
            echo "<br/><code class='small'>" . text($uuidString) . "</code>";
        }
    } else {
        echo "<br/><span class='text-muted'><i class='fa fa-unlink'></i> " . 
             xlt("Not linked to encounter UUID") . "</span>";
    }
    
    echo "</small>";
    echo "</div>";
    echo "</div>";
} 