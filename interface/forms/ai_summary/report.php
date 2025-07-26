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
use OpenEMR\Common\Csrf\CsrfUtils;

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
    } else {
        echo "<div class='alert alert-info mb-3'>";
        echo "<i class='fas fa-info-circle'></i> " . xlt("No transcription available. Generate Summary will use DrVisit.md for testing.");
        echo "</div>";
    }
    
    // AI Scribe Card with Generate Summary button - UPDATED
    echo "<div class='card mb-3 ai-scribe-card'>";
    echo "  <div class='card-header bg-success text-white'>";
    echo "    <h5 class='mb-0'><i class='fas fa-robot'></i> " . xlt("AI Scribe") . "</h5>";
    echo "  </div>";
    echo "  <div class='card-body'>";
    echo "    <button id='btn_generate_summary_" . attr($id) . "' class='btn btn-success btn-lg' data-form-id='" . attr($id) . "'>";
    echo "      <i class='fas fa-magic'></i> " . xlt("Generate Summary");
    echo "    </button>";
    echo "    <div id='summary_status_" . attr($id) . "' class='mt-2'></div>";
    echo "  </div>";
    echo "</div>";
    
    // Display AI summary if available
    if (!empty($res['ai_summary'])) {
        echo "<div class='summary-content mb-3'>";
        echo "<h6 class='text-secondary'>" . xlt("AI Generated Summary:") . "</h6>";
        echo "<div class='card'>";
        echo "  <div class='card-body'>";
        echo "    <div id='ai_summary_content_" . attr($id) . "' style='white-space: pre-wrap;'>" . text($res['ai_summary']) . "</div>";
        echo "  </div>";
        echo "</div>";
        echo "</div>";
    } else {
        // Hidden summary display that will be shown after generation
        echo "<div id='summary_display_" . attr($id) . "' class='summary-content mb-3 d-none'>";
        echo "<h6 class='text-secondary'>" . xlt("AI Generated Summary:") . "</h6>";
        echo "<div class='card'>";
        echo "  <div class='card-body'>";
        echo "    <div id='ai_summary_content_" . attr($id) . "' style='white-space: pre-wrap;'></div>";
        echo "  </div>";
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
    } else {
        echo "<br/><span class='text-muted'><i class='fa fa-unlink'></i> " . 
             xlt("Not linked to encounter UUID") . "</span>";
    }
    
    echo "</small>";
    echo "</div>";
    echo "</div>";
    
    // Add JavaScript for Generate Summary functionality
    $csrfToken = CsrfUtils::collectCsrfToken();
    echo "<script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log('=== AI SUMMARY REPORT.PHP JAVASCRIPT LOADED ===');
        console.log('Form ID: " . attr($id) . "');
        console.log('CSRF Token: " . attr($csrfToken) . "');
        
        const generateBtn = document.getElementById('btn_generate_summary_" . attr($id) . "');
        const statusDiv = document.getElementById('summary_status_" . attr($id) . "');
        const summaryDisplay = document.getElementById('summary_display_" . attr($id) . "');
        const summaryContent = document.getElementById('ai_summary_content_" . attr($id) . "');
        
        console.log('DOM elements found:', {
            generateBtn: !!generateBtn,
            statusDiv: !!statusDiv,
            summaryDisplay: !!summaryDisplay,
            summaryContent: !!summaryContent
        });
        
        if (generateBtn) {
            console.log('Adding click event listener to Generate Summary button');
            generateBtn.addEventListener('click', async function() {
                console.log('=== GENERATE SUMMARY BUTTON CLICKED ===');
                alert('Button clicked! Check terminal for logs.');
                const formId = this.getAttribute('data-form-id');
                console.log('Form ID from button:', formId);
                
                // Update button state
                console.log('Updating button state to processing...');
                generateBtn.disabled = true;
                generateBtn.innerHTML = '<i class=\"fas fa-spinner fa-spin\"></i> " . xlt("Generating...") . "';
                statusDiv.innerHTML = '<div class=\"alert alert-info\">" . xlt("Generating AI summary from transcription, please wait...") . "</div>';
                
                try {
                    console.log('Preparing FormData for API request...');
                    const formData = new FormData();
                    formData.append('form_id', formId);
                    formData.append('csrf_token_form', '" . attr($csrfToken) . "');
                    
                    console.log('FormData prepared:', {
                        form_id: formId,
                        csrf_token_form: '" . attr($csrfToken) . "'
                    });
                    
                    const requestUrl = '" . $GLOBALS['webroot'] . "/interface/forms/ai_summary/generate_summary.php';
                    console.log('Making fetch request to:', requestUrl);
                    
                    const startTime = performance.now();
                    const response = await fetch(requestUrl, {
                        method: 'POST',
                        body: formData
                    });
                    const endTime = performance.now();
                    
                    console.log('Fetch response received:', {
                        status: response.status,
                        statusText: response.statusText,
                        ok: response.ok,
                        duration: Math.round(endTime - startTime) + 'ms'
                    });
                    
                    if (!response.ok) {
                        console.error('HTTP error response:', response.status, response.statusText);
                        throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                    }
                    
                    console.log('Parsing JSON response...');
                    const result = await response.json();
                    console.log('JSON response parsed:', {
                        success: result.success,
                        message: result.message,
                        summaryLength: result.summary ? result.summary.length : 0,
                        error: result.error || 'none'
                    });
                    
                    if (result.success) {
                        console.log('=== AI SUMMARY GENERATION SUCCESS ===');
                        console.log('Summary preview (first 100 chars):', result.summary.substring(0, 100) + '...');
                        
                        // Display the generated summary
                        summaryContent.textContent = result.summary;
                        if (summaryDisplay) {
                            console.log('Showing summary display element');
                            summaryDisplay.classList.remove('d-none');
                        }
                        
                        statusDiv.innerHTML = '<div class=\"alert alert-success\">' +
                            '<i class=\"fas fa-check-circle\"></i> " . xlt("AI summary generated successfully!") . "' +
                            '</div>';
                        
                        console.log('Success message displayed, setting auto-hide timer');
                        // Auto-hide success message after 3 seconds
                        setTimeout(() => {
                            console.log('Hiding success message');
                            statusDiv.innerHTML = '';
                        }, 3000);
                        
                    } else {
                        console.error('=== API RETURNED ERROR ===');
                        console.error('Error message:', result.error);
                        throw new Error(result.error || 'Unknown error occurred');
                    }
                    
                } catch (error) {
                    console.error('=== GENERATE SUMMARY ERROR ===');
                    console.error('Error type:', error.constructor.name);
                    console.error('Error message:', error.message);
                    console.error('Full error:', error);
                    
                    statusDiv.innerHTML = '<div class=\"alert alert-danger\">' +
                        '<i class=\"fas fa-exclamation-triangle\"></i> " . xlt("Error:") . " ' + error.message +
                        '</div>';
                } finally {
                    console.log('Resetting button state');
                    // Reset button state
                    generateBtn.disabled = false;
                    generateBtn.innerHTML = '<i class=\"fas fa-magic\"></i> " . xlt("Generate Summary") . "';
                    console.log('=== GENERATE SUMMARY PROCESS COMPLETE ===');
                }
            });
        } else {
            console.error('Generate Summary button not found in DOM');
        }
    });
    </script>";
    
    // Add CSS for better styling
    echo "<style>
    .ai-scribe-card {
        border: 2px solid #28a745;
        box-shadow: 0 4px 8px rgba(40, 167, 69, 0.1);
    }
    
    .ai-scribe-card .btn-success {
        font-size: 1.1em;
        font-weight: 600;
        padding: 12px 24px;
        border-radius: 8px;
        box-shadow: 0 3px 6px rgba(40, 167, 69, 0.3);
        transition: all 0.2s ease;
    }
    
    .ai-scribe-card .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 10px rgba(40, 167, 69, 0.4);
    }
    
    .ai-scribe-card .btn-success:disabled {
        transform: none;
        box-shadow: 0 2px 4px rgba(40, 167, 69, 0.2);
    }
    </style>";
} 