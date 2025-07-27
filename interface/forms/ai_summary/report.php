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

// Log that the report.php file is being included
error_log("AI_SUMMARY_REPORT_FILE: report.php is being included", 3, "/tmp/ai_summary.log");

require_once(__DIR__ . "/../../globals.php");
// Temporarily commenting out TextUtil to debug loading issue
// require_once($GLOBALS['srcdir'] . "/Common/Utils/TextUtil.php");

use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Common\Csrf\CsrfUtils;
// use OpenEMR\Common\Utils\TextUtil;

// Log after includes are loaded
error_log("AI_SUMMARY_REPORT_FILE: All includes loaded, defining ai_summary_report function", 3, "/tmp/ai_summary.log");

/**
 * Display AI Summary form data in encounter summary, now with interactive linking.
 *
 * @param int $pid Patient ID
 * @param int $encounter Encounter ID  
 * @param int $cols Number of columns (unused but required by interface)
 * @param int $id Form ID
 * @return void Outputs HTML directly
 */
function ai_summary_report($pid, $encounter, $cols, $id): void
{
    // Debug: Log that the function is being called
    error_log("AI_SUMMARY_REPORT: Function called with pid=$pid, encounter=$encounter, id=$id", 3, "/tmp/ai_summary.log");
    
    // Fetch all necessary data in one query
    $res = sqlQuery(
        "SELECT * FROM form_ai_summary WHERE id = ? AND pid = ? AND encounter = ?",
        array($id, $pid, $encounter)
    );
    
    error_log("AI_SUMMARY_REPORT: Query result: " . ($res ? "Found data" : "No data found"), 3, "/tmp/ai_summary.log");

    if (!$res) {
        echo "<div class='ai-summary-report'><p class='text-muted'>" . xlt("No AI summary data found.") . "</p></div>";
        return;
    }

    $transcript = $res['voice_transcription'] ?? '';
    $summary = $res['ai_summary'] ?? '';
    $linkingMapJson = $res['linking_map_json'] ?? null;
    
    // More robust checking for summary and linking state
    $hasSummary = !empty(trim($summary));
    $hasLinkingMap = false;
    
    if ($linkingMapJson) {
        $linkingData = json_decode($linkingMapJson, true);
        $hasLinkingMap = !empty($linkingData['linking_map'] ?? []);
    }
    
    // Log the current state for debugging
    error_log("AI_SUMMARY_DEBUG: Form ID {$id}, hasSummary: " . ($hasSummary ? 'YES' : 'NO') . ", hasLinkingMap: " . ($hasLinkingMap ? 'YES' : 'NO'), 3, "/tmp/ai_summary.log");

    // Prepare data for rendering using simple string functions (temporarily)
    $transcriptTurns = !empty($transcript) ? explode("\n", $transcript) : [];
    $summaryBlocks = !empty($summary) ? explode("\n\n", $summary) : [];

    echo "<div class='ai-summary-report border rounded p-3 mb-3'>";

    // AI Scribe Card with conditional buttons
    echo "<div class='card mb-3 ai-scribe-card'>";
    echo "  <div class='card-header bg-primary text-white'><h5 class='mb-0'><i class='fas fa-robot'></i> " . xlt("AI Scribe") . "</h5></div>";
    echo "  <div class='card-body d-flex align-items-center'>";
    // Button for generating summary - always visible so users can regenerate
    echo "    <button id='btn_generate_summary_" . attr($id) . "' class='btn btn-success btn-lg me-2' data-form-id='" . attr($id) . "'>";
    echo "      <i class='fas fa-magic'></i> " . xlt($hasSummary ? "Regenerate Summary" : "Generate Summary");
    echo "    </button>";
    // Button for linking evidence - show if there's a summary
    echo "    <button id='btn_link_evidence_" . attr($id) . "' class='btn btn-info btn-lg' data-form-id='" . attr($id) . "' " . ($hasSummary ? "" : "style='display:none;'") . ">";
    echo "      <i class='fas fa-link'></i> " . xlt($hasLinkingMap ? "Relink Evidence" : "Link Evidence");
    echo "    </button>";
    echo "    <div id='summary_status_" . attr($id) . "' class='ms-3 flex-grow-1'></div>";
    echo "  </div>";
    echo "</div>";

    // Main content area with side-by-side linked view
    echo "<div class='row mt-3'>";
    // --- Summary Column ---
    echo "<div class='col-md-6'>";
    echo "  <h5 class='text-primary mb-2'>" . xlt("AI Generated Summary") . ($hasLinkingMap ? " <small class='text-muted'>(" . xlt("Click a line to see source") . ")</small>" : "") . "</h5>";
    echo "  <div id='summary-display-{$id}' class='summary-container p-2 border rounded bg-light' style='font-size: 0.9em; min-height: 300px;'>";
    if (!empty($summaryBlocks)) {
        foreach ($summaryBlocks as $index => $block) {
            echo "<p class='summary-block' data-summary-index='{$index}'>" . text($block) . "</p>";
        }
    } else {
        echo "<p class='text-muted'>" . xlt("No summary has been generated yet. Click the button above.") . "</p>";
    }
    echo "  </div>";
    echo "</div>";

    // --- Transcript Column ---
    echo "<div class='col-md-6'>";
    echo "  <h5 class='text-primary mb-2'>" . xlt("Original Transcript") . "</h5>";
    echo "  <div id='transcript-display-{$id}' class='transcript-container p-2 border rounded' style='max-height: 400px; overflow-y: auto; font-size: 0.9em;'>";
    if (!empty($transcriptTurns)) {
        foreach ($transcriptTurns as $index => $turn) {
            echo "<p class='transcript-turn' id='transcript-turn-{$id}-{$index}'>" . text($turn) . "</p>";
        }
    } else {
        echo "<p class='text-muted'>" . xlt("No transcript available.") . "</p>";
    }
    echo "  </div>";
    echo "</div>";
    echo "</div>"; // end .row

    echo "</div>"; // end .ai-summary-report

    // --- JavaScript for Linking & Generation ---
    $csrfToken = CsrfUtils::collectCsrfToken();
    echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Linked_Evidence: Initializing for form_id " . json_encode($id) . "');
        const formId = " . json_encode($id) . ";
        const generateBtn = document.getElementById('btn_generate_summary_' + formId);
        const linkBtn = document.getElementById('btn_link_evidence_' + formId);
        const statusDiv = document.getElementById('summary_status_' + formId);
        const summaryContainer = document.getElementById('summary-display-' + formId);
        
        let linkingMapData = " . ($linkingMapJson ? $linkingMapJson : 'null') . ";
        let linkingMap = linkingMapData ? linkingMapData.linking_map : null;
        console.log('Linked_Evidence: Linking map loaded with ' + (linkingMap ? linkingMap.length : 0) + ' entries.');

        function enableLinking(map) {
            if (!summaryContainer || !map) return;
            console.log('Linked_Evidence: Enabling click-to-highlight functionality.');
            summaryContainer.addEventListener('click', function(e) {
                if (e.target && e.target.classList.contains('summary-block')) {
                    console.log('Linked_Evidence: Summary block clicked.', { summaryIndex: e.target.dataset.summaryIndex });
                    document.querySelectorAll('.summary-block.highlight, .transcript-turn.highlight').forEach(el => el.classList.remove('highlight'));
                    
                    e.target.classList.add('highlight');
                    
                    const summaryIndex = parseInt(e.target.dataset.summaryIndex, 10);
                    const linkData = map.find(link => link.summary_index === summaryIndex);
                    
                    if (linkData && linkData.transcript_indices && linkData.transcript_indices.length > 0) {
                        console.log('Linked_Evidence: Found ' + linkData.transcript_indices.length + ' linked transcript turns.', { indices: linkData.transcript_indices });
                        linkData.transcript_indices.forEach((transcriptIndex, i) => {
                            const turnElement = document.getElementById(`transcript-turn-${formId}-${transcriptIndex}`);
                            if (turnElement) {
                                turnElement.classList.add('highlight');
                                if (i === 0) {
                                    turnElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                }
                            }
                        });
                    }
                }
            });
        }

        if (linkingMap) {
            enableLinking(linkingMap);
        }

        async function handleApiCall(button, url, statusMessage) {
            console.log(`Linked_Evidence: Button #${button.id} clicked.`);
            button.disabled = true;
            button.innerHTML = `<i class=\"fas fa-spinner fa-spin\"></i> ${statusMessage}`;
            statusDiv.innerHTML = `<div class=\"alert alert-info\">${statusMessage}...</div>`;
            
            try {
                const formData = new FormData();
                formData.append('form_id', formId);
                formData.append('csrf_token_form', " . json_encode($csrfToken) . ");
                console.log('Linked_Evidence: Sending AJAX request to ' + url, { formId: formId });
                
                const response = await fetch(url, { method: 'POST', body: formData });
                console.log('Linked_Evidence: Received response.', { status: response.status, ok: response.ok });
                
                if (!response.ok) throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                
                const result = await response.json();
                console.log('Linked_Evidence: Parsed JSON response.', result);

                if (result.success) {
                    statusDiv.innerHTML = `<div class=\"alert alert-success\"><i class=\"fas fa-check-circle\"></i> ${result.message} Page will refresh.</div>`;
                    console.log('Linked_Evidence: API call successful, refreshing page content.');
                    setTimeout(() => window.parent.refreshVisitDisplay(), 1500);
                } else {
                    throw new Error(result.error || 'Unknown error occurred');
                }
            } catch (error) {
                console.error('Linked_Evidence: API call failed.', { message: error.message });
                statusDiv.innerHTML = `<div class=\"alert alert-danger\"><i class=\"fas fa-exclamation-triangle\"></i> " . xlt("Error:") . " ' + error.message + '</div>';
                button.disabled = false;
                button.innerHTML = button.dataset.originalHtml; // Restore original button text
            }
        }

        if (generateBtn) {
            generateBtn.dataset.originalHtml = generateBtn.innerHTML;
            generateBtn.addEventListener('click', () => handleApiCall(
                generateBtn,
                '" . $GLOBALS['webroot'] . "/interface/forms/ai_summary/generate_summary.php',
                '" . xlt("Generating Summary") . "'
            ));
        }

        if (linkBtn) {
            linkBtn.dataset.originalHtml = linkBtn.innerHTML;
            linkBtn.addEventListener('click', () => handleApiCall(
                linkBtn,
                '" . $GLOBALS['webroot'] . "/interface/forms/ai_summary/link_evidence.php',
                '" . xlt("Linking Evidence") . "'
            ));
        }
    });
    </script>";

    // --- CSS for Highlighting ---
    echo "<style>
    .summary-block { cursor: pointer; margin-bottom: 0.8rem; padding: 6px; border-radius: 4px; transition: background-color 0.2s; }
    .summary-block:hover { background-color: #e9ecef; }
    .summary-block.highlight { background-color: #cfe2ff; font-weight: bold; }
    .transcript-turn { padding: 2px 4px; margin-bottom: 0.8rem; transition: background-color 0.2s; }
    .transcript-turn.highlight { background-color: #fff3cd; border-radius: 4px; }
    .ai-scribe-card .btn-lg { font-weight: 600; }
    </style>";
    
    // Log that the function completed successfully
    error_log("AI_SUMMARY_REPORT: Function completed successfully for form ID {$id}", 3, "/tmp/ai_summary.log");
}

// Log that the function was defined
error_log("AI_SUMMARY_REPORT_FILE: ai_summary_report function has been defined", 3, "/tmp/ai_summary.log"); 