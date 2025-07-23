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
    // Fetch the AI summary data from database
    $res = sqlQuery(
        "SELECT * FROM form_ai_summary WHERE id = ? AND pid = ? AND encounter = ?", 
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
    
    // Display metadata
    echo "<div class='summary-meta mt-3 pt-2 border-top'>";
    echo "<small class='text-muted'>";
    echo xlt("Generated:") . " " . text(oeFormatShortDate($res['date']));
    if (!empty($res['ai_model_used'])) {
        echo " | " . xlt("Model:") . " " . text($res['ai_model_used']);
    }
    if (!empty($res['user'])) {
        echo " | " . xlt("By:") . " " . text($res['user']);
    }
    echo "</small>";
    echo "</div>";
    
    echo "</div>";
} 