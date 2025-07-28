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
// Enable TextUtil usage - this is critical for proper text splitting
require_once($GLOBALS['srcdir'] . "/../src/Common/Utils/TextUtil.php");

use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Utils\TextUtil;

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

    // Use TextUtil for proper splitting
    $transcriptTurns = [];
    $summaryBlocks = [];
    
    if (!empty($transcript)) {
        error_log("AI_SUMMARY_REPORT: Using TextUtil to split transcript", 3, "/tmp/ai_summary.log");
        $transcriptTurns = TextUtil::splitByConversationTurns($transcript);
        error_log("AI_SUMMARY_REPORT: Transcript split into " . count($transcriptTurns) . " turns", 3, "/tmp/ai_summary.log");
    }
    
    if (!empty($summary)) {
        error_log("AI_SUMMARY_REPORT: Using TextUtil to split summary", 3, "/tmp/ai_summary.log");
        $summaryBlocks = TextUtil::splitSummaryIntoBlocks($summary);
        error_log("AI_SUMMARY_REPORT: Summary split into " . count($summaryBlocks) . " blocks", 3, "/tmp/ai_summary.log");
    }

    echo "<div class='ai-summary-report border rounded p-3 mb-3'>";
    // AI Scribe Card with conditional buttons
    echo "<div class='card mb-3 ai-scribe-card'>";
    echo "  <div class='card-header bg-primary text-white'><h5 class='mb-0'>" . xlt("AI Scribe") . "</h5></div>";
    echo "  <div class='card-body'>";
    echo "    <div class='d-flex align-items-start'>";
    echo "      <div class='d-flex align-items-center me-3'>";
    
    // Button for generating summary - always visible so users can regenerate
    echo "        <button id='btn_generate_summary_" . attr($id) . "' class='btn btn-success btn-lg me-2' data-form-id='" . attr($id) . "'>";
    echo "          <i class='fas fa-magic'></i> " . xlt($hasSummary ? "Regenerate Summary" : "Generate Summary");
    echo "        </button>";
    
    // Button for linking evidence - show if there's a summary
    echo "        <button id='btn_link_evidence_" . attr($id) . "' class='btn btn-info btn-lg' data-form-id='" . attr($id) . "' " . ($hasSummary ? "" : "style='display:none;'") . ">";
    echo "          <i class='fas fa-link'></i> " . xlt($hasLinkingMap ? "Relink Evidence" : "Link Evidence");
    echo "        </button>";
    
    echo "      </div>";
    echo "      <div id='summary_status_" . attr($id) . "' class='flex-grow-1'></div>";
    echo "    </div>";
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
            // Check if it's a header (starts and ends with **)
            $isHeader = preg_match('/^\*\*[^*]+\*\*$/', $block);
            $cssClass = $isHeader ? 'summary-block summary-header' : 'summary-block';
            echo "<p class='{$cssClass}' data-summary-index='{$index}'>" . text($block) . "</p>";
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
    
    // Get CSRF token for API calls
    $csrfToken = CsrfUtils::collectCsrfToken();
    
    // JavaScript for button handling and linking functionality
    echo "<script>
    console.log('=== AI SUMMARY JAVASCRIPT LOADING ===');
    console.log('Script loading for form ID: " . json_encode($id) . "');
    console.log('Document ready state:', document.readyState);
    
    document.addEventListener('DOMContentLoaded', function() {
        console.log('=== DOM CONTENT LOADED EVENT FIRED ===');
        console.log('AI_SUMMARY: Initializing for form_id " . json_encode($id) . "');
        
        const formId = " . json_encode($id) . ";
        const csrfToken = " . json_encode($csrfToken) . ";
        
        console.log('AI_SUMMARY: formId:', formId);
        console.log('AI_SUMMARY: csrfToken length:', csrfToken.length);
        
        // Find DOM elements
        const generateBtn = document.getElementById('btn_generate_summary_' + formId);
        const linkBtn = document.getElementById('btn_link_evidence_' + formId);
        const statusDiv = document.getElementById('summary_status_' + formId);
        const summaryContainer = document.getElementById('summary-display-' + formId);
        
        console.log('AI_SUMMARY: Generate button found:', !!generateBtn);
        console.log('AI_SUMMARY: Link button found:', !!linkBtn);
        console.log('AI_SUMMARY: Status div found:', !!statusDiv);
        console.log('AI_SUMMARY: Summary container found:', !!summaryContainer);
        
        // Load linking map data
        let linkingMapData = " . ($linkingMapJson ? $linkingMapJson : 'null') . ";
        let linkingMap = linkingMapData ? linkingMapData.linking_map : null;
        console.log('AI_SUMMARY: Linking map loaded with ' + (linkingMap ? linkingMap.length : 0) + ' entries.');

        // Function to enable click-to-highlight linking
        function enableLinking(map) {
            if (!summaryContainer || !map) {
                console.log('AI_SUMMARY: Cannot enable linking - missing container or map');
                return;
            }
            
            console.log('AI_SUMMARY: Enabling click-to-highlight functionality');
            summaryContainer.addEventListener('click', function(e) {
                if (e.target && e.target.classList.contains('summary-block')) {
                    console.log('AI_SUMMARY: Summary block clicked:', e.target.dataset.summaryIndex);
                    
                    // Clear existing highlights
                    document.querySelectorAll('.summary-block.highlight, .transcript-turn.highlight').forEach(el => {
                        el.classList.remove('highlight');
                    });
                    
                    // Highlight clicked summary block
                    e.target.classList.add('highlight');
                    
                    // Find and highlight linked transcript turns
                    const summaryIndex = parseInt(e.target.dataset.summaryIndex, 10);
                    const linkData = map.find(link => link.summary_index === summaryIndex);
                    
                    if (linkData && linkData.transcript_indices && linkData.transcript_indices.length > 0) {
                        console.log('AI_SUMMARY: Found ' + linkData.transcript_indices.length + ' linked transcript turns:', linkData.transcript_indices);
                        
                        linkData.transcript_indices.forEach((transcriptIndex, i) => {
                            const turnElement = document.getElementById('transcript-turn-' + formId + '-' + transcriptIndex);
                            if (turnElement) {
                                turnElement.classList.add('highlight');
                                // Scroll first linked turn into view
                                if (i === 0) {
                                    turnElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                }
                            }
                        });
                    } else {
                        console.log('AI_SUMMARY: No linked transcript turns found for summary index:', summaryIndex);
                    }
                }
            });
        }

        // Enable linking if map exists
        if (linkingMap) {
            enableLinking(linkingMap);
        }

        // Generic API call handler
        async function handleApiCall(button, url, statusMessage) {
            console.log('AI_SUMMARY: Starting API call to:', url);
            console.log('AI_SUMMARY: FormId:', formId);
            console.log('AI_SUMMARY: Button:', button.id);
            
            // Store original button content
            const originalContent = button.innerHTML;
            
            // Update UI
            button.disabled = true;
            button.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> ' + statusMessage + '...';
            statusDiv.innerHTML = '<div class=\"alert alert-info\"><i class=\"fas fa-info-circle\"></i> ' + statusMessage + ', please wait...</div>';
            
            try {
                // Prepare form data
                const formData = new FormData();
                formData.append('form_id', formId);
                formData.append('csrf_token_form', csrfToken);
                
                console.log('AI_SUMMARY: Sending AJAX request...');
                const startTime = performance.now();
                
                const response = await fetch(url, {
                    method: 'POST',
                    body: formData
                });
                
                const endTime = performance.now();
                console.log('AI_SUMMARY: Response received in ' + Math.round(endTime - startTime) + 'ms');
                console.log('AI_SUMMARY: Response status:', response.status, response.statusText);
                
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                }
                
                const responseText = await response.text();
                console.log('AI_SUMMARY: Raw response:', responseText.substring(0, 200) + '...');
                
                const result = JSON.parse(responseText);
                console.log('AI_SUMMARY: Parsed response:', result);

                if (result.success) {
                    statusDiv.innerHTML = '<div class=\"alert alert-success\"><i class=\"fas fa-check-circle\"></i> ' + result.message + ' Page will refresh shortly.</div>';
                    console.log('AI_SUMMARY: API call successful, refreshing page content...');
                    
                    // Refresh the encounter display after a short delay
                    setTimeout(() => {
                        if (window.parent && typeof window.parent.refreshVisitDisplay === 'function') {
                            window.parent.refreshVisitDisplay();
                        } else {
                            location.reload();
                        }
                    }, 1500);
                } else {
                    throw new Error(result.error || 'Unknown error occurred');
                }
                
            } catch (error) {
                console.error('AI_SUMMARY: API call failed:', error);
                statusDiv.innerHTML = '<div class=\"alert alert-danger\"><i class=\"fas fa-exclamation-triangle\"></i> Error: ' + error.message + '</div>';
                
                // Restore button
                button.disabled = false;
                button.innerHTML = originalContent;
            }
        }

        // Attach event listeners to buttons
        if (generateBtn) {
            console.log('AI_SUMMARY: Attaching click handler to generate button');
            generateBtn.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('AI_SUMMARY: Generate Summary button clicked');
                handleApiCall(
                    generateBtn,
                    '" . $GLOBALS['webroot'] . "/interface/forms/ai_summary/generate_summary.php',
                    '" . xlt("Generating Summary") . "'
                );
            });
        } else {
            console.error('AI_SUMMARY: Generate button not found!');
        }

        if (linkBtn) {
            console.log('AI_SUMMARY: Attaching click handler to link button');
            linkBtn.addEventListener('click', function(e) {
                e.preventDefault();
                console.log('AI_SUMMARY: Link Evidence button clicked');
                handleApiCall(
                    linkBtn,
                    '" . $GLOBALS['webroot'] . "/interface/forms/ai_summary/link_evidence.php',
                    '" . xlt("Linking Evidence") . "'
                );
            });
        } else {
            console.log('AI_SUMMARY: Link button not found (this is normal if no summary exists)');
        }
        
        console.log('AI_SUMMARY: Initialization complete');
    });
    </script>";

    // CSS for highlighting and styling
    echo "<style>
    .summary-block { 
        cursor: pointer; 
        margin-bottom: 0.8rem; 
        padding: 6px; 
        border-radius: 4px; 
        transition: background-color 0.2s; 
    }
    .summary-block:hover { 
        background-color: #e9ecef; 
    }
    .summary-block.highlight { 
        background-color: #cfe2ff; 
        font-weight: bold; 
        border: 2px solid #0d6efd;
    }
    .summary-header {
        font-weight: bold;
        color: #0d6efd;
        border-left: 4px solid #0d6efd;
        padding-left: 8px;
        background-color: #f8f9fa;
    }
    .transcript-turn { 
        padding: 4px 6px; 
        margin-bottom: 0.8rem; 
        transition: background-color 0.2s; 
        border-radius: 4px;
    }
    .transcript-turn.highlight { 
        background-color: #fff3cd; 
        border: 2px solid #ffc107;
        font-weight: bold;
    }
    .ai-scribe-card .btn-lg { 
        font-weight: 600; 
    }
    .summary-container {
        max-height: 400px;
        overflow-y: auto;
    }
    </style>";
    
    // Log that the function completed successfully
    error_log("AI_SUMMARY_REPORT: Function completed successfully for form ID {$id}", 3, "/tmp/ai_summary.log");
}

// Log that the function was defined
error_log("AI_SUMMARY_REPORT_FILE: ai_summary_report function has been defined", 3, "/tmp/ai_summary.log"); 