<?php

/**
 * AISummaryService with UUID-Based Transcription System
 * 
 * Handles creation and management of AI summaries for encounters using UUID-based transcription storage
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Voice Transcription Implementation
 * @copyright Copyright (c) 2024
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\Uuid\UuidRegistry;

class AISummaryService extends BaseService
{
    /**
     * @var SystemLogger
     */
    private $logger;
    
    /**
     * Table name for AI summaries
     */
    const TABLE_NAME = 'form_ai_summary';
    
    /**
     * Default constructor
     */
    public function __construct()
    {
        parent::__construct(self::TABLE_NAME);
        $this->logger = new SystemLogger();
    }
    
    /**
     * Enhanced logging function for AI Summary service with same style as ai_summary.log
     */
    private function logAISummary($message) {
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] [AISummaryService.php] $message\n", 3, "/tmp/ai_summary.log");
        error_log("AI_SUMMARY_SERVICE: $message"); // Also log to default error log
    }
    
    /**
     * Create AI summary from pending session data using UUID-based system
     * This method now searches for any valid transcription for the current patient instead of exact encounter match
     *
     * @param int $encounterId The encounter ID that was just saved
     * @param string $encounterUuid The UUID of the saved encounter
     * @param int $pid Patient ID
     * @return array|false Array with form ID on success, false on failure
     */
    public function createFromSessionData($encounterId, $encounterUuid, $pid)
    {
        $this->logAISummary('Starting AI summary creation from session data - Encounter: ' . $encounterId . ', UUID: ' . $encounterUuid . ', Patient: ' . $pid . ', Session: ' . session_id());
        
        // Check if we have any pending transcriptions in session
        if (empty($_SESSION['pending_ai_transcriptions'])) {
            $this->logAISummary('No pending AI transcriptions found in session - Encounter: ' . $encounterId . ', Patient: ' . $pid);
            return false;
        }
        
        $this->logAISummary('Found pending transcriptions in session - Count: ' . count($_SESSION['pending_ai_transcriptions']) . ', UUIDs: ' . implode(',', array_keys($_SESSION['pending_ai_transcriptions'])));
        
        // Find valid transcription for current patient using UUID-based system
        $validTranscription = null;
        $validTranscriptionUuid = null;
        
        foreach ($_SESSION['pending_ai_transcriptions'] as $uuid => $transcriptionData) {
            $this->logAISummary('Examining pending transcription - UUID: ' . $uuid . ', Patient: ' . ($transcriptionData['pid'] ?? 'unknown') . ', Current: ' . $pid . ', User: ' . ($transcriptionData['user'] ?? 'unknown') . ', Time: ' . ($transcriptionData['timestamp'] ?? 'unknown'));
            
            // Validate that this transcription belongs to the current patient
            if (isset($transcriptionData['pid']) && $transcriptionData['pid'] == $pid) {
                $validTranscription = $transcriptionData;
                $validTranscriptionUuid = $uuid;
                $this->logAISummary('Found valid transcription for current patient - UUID: ' . $uuid . ', Patient: ' . $pid . ', Encounter: ' . $encounterId . ', Length: ' . strlen($transcriptionData['transcription'] ?? ''));
                break; // Use the first valid transcription found
            } else {
                $this->logAISummary('Transcription belongs to different patient - UUID: ' . $uuid . ', Patient: ' . ($transcriptionData['pid'] ?? 'unknown') . ', Current: ' . $pid);
            }
        }
        
        // If no valid transcription found, return false
        if (!$validTranscription || !$validTranscriptionUuid) {
            $this->logAISummary('No valid transcription found for current patient - Patient: ' . $pid . ', Encounter: ' . $encounterId . ', Available: ' . count($_SESSION['pending_ai_transcriptions']));
            return false;
        }
        
        try {
            $this->logAISummary('Creating AI summary from valid transcription - UUID: ' . $validTranscriptionUuid . ', Encounter: ' . $encounterId . ', Patient: ' . $pid);
            
            // Create AI summary with proper encounter UUID linking
            $summaryData = [
                'pid' => $pid,
                'encounter' => $encounterId,
                'user' => $validTranscription['user'],
                'groupname' => $validTranscription['provider'] ?? 'Default',
                'voice_transcription' => $validTranscription['transcription'],
                'summary_type' => 'transcription',
                'ai_model_used' => $validTranscription['model'] ?? 'whisper-1',
                'processing_status' => 'completed',
                'transcription_source' => $validTranscription['source'] ?? 'voice_recording'
            ];
            
            // Only add encounter_uuid if it's provided and valid
            if (!empty($encounterUuid)) {
                $summaryData['encounter_uuid'] = UuidRegistry::uuidToBytes($encounterUuid);
                $this->logAISummary('Adding encounter UUID to AI summary - UUID: ' . $encounterUuid . ', Bytes: ' . bin2hex($summaryData['encounter_uuid']));
            } else {
                $this->logAISummary('No encounter UUID provided, creating AI summary without UUID link');
            }
            
            $formId = $this->createAISummary($summaryData);
            
            if ($formId) {
                $this->logAISummary('AI summary record created successfully - Form ID: ' . $formId . ', Encounter: ' . $encounterId . ', UUID: ' . $validTranscriptionUuid);
                
                // Register the form in OpenEMR's forms table
                require_once($GLOBALS['srcdir'] . "/forms.inc.php");
                $addFormResult = addForm(
                    $encounterId,
                    "AI Summary",
                    $formId,
                    "ai_summary",
                    $pid,
                    $_SESSION["authUserID"] ?? 1,
                    date("Y-m-d H:i:s"),
                    $validTranscription['user']
                );
                
                if (!$addFormResult) {
                    // If form registration failed, clean up the ai_summary record
                    $this->deleteAISummary($formId);
                    $this->logAISummary('Failed to register form in OpenEMR forms table - Form ID: ' . $formId . ', Encounter: ' . $encounterId);
                    throw new \Exception('Failed to register form in OpenEMR');
                }
                
                $this->logAISummary('Form registered successfully in OpenEMR - Form ID: ' . $formId . ', Encounter: ' . $encounterId . ', Result: ' . $addFormResult);
                
                // Clear the processed transcription from session
                unset($_SESSION['pending_ai_transcriptions'][$validTranscriptionUuid]);
                
                $this->logAISummary('AI Summary created successfully and transcription cleared from session - Form ID: ' . $formId . ', Encounter: ' . $encounterId . ', UUID: ' . $validTranscriptionUuid . ', Remaining: ' . count($_SESSION['pending_ai_transcriptions'] ?? []));
                
                return ['form_id' => $formId, 'encounter_id' => $encounterId, 'transcription_uuid' => $validTranscriptionUuid];
            }
        } catch (\Exception $e) {
            $this->logAISummary('Failed to create AI summary from session - Error: ' . $e->getMessage() . ', Encounter: ' . $encounterId . ', UUID: ' . $validTranscriptionUuid);
            return false;
        }
        
        return false;
    }
    
    /**
     * Create a new AI summary record
     *
     * @param array $data AI summary data
     * @return int|false Form ID on success, false on failure
     */
    private function createAISummary($data)
    {
        $this->logAISummary('Creating AI summary database record - Patient: ' . $data['pid'] . ', Encounter: ' . $data['encounter'] . ', Length: ' . strlen($data['voice_transcription'] ?? '') . ', Model: ' . $data['ai_model_used']);
        
        $sql = "INSERT INTO form_ai_summary 
                (pid, encounter, encounter_uuid, user, groupname, authorized, activity, date, 
                 voice_transcription, summary_type, ai_model_used, processing_status, 
                 transcription_source, created_date) 
                VALUES (?, ?, ?, ?, ?, 1, 1, NOW(), ?, ?, ?, ?, ?, NOW())";
        
        $binds = [
            $data['pid'],
            $data['encounter'],
            $data['encounter_uuid'] ?? null,
            $data['user'],
            $data['groupname'],
            $data['voice_transcription'],
            $data['summary_type'],
            $data['ai_model_used'],
            $data['processing_status'],
            $data['transcription_source']
        ];
        
        $formId = sqlInsert($sql, $binds);
        
        if ($formId) {
            $this->logAISummary('AI summary database record created - Form ID: ' . $formId . ', Patient: ' . $data['pid'] . ', Encounter: ' . $data['encounter']);
        } else {
            $this->logAISummary('Failed to create AI summary database record - Patient: ' . $data['pid'] . ', Encounter: ' . $data['encounter']);
        }
        
        return $formId;
    }
    
    /**
     * Delete an AI summary record
     *
     * @param int $formId The form ID to delete
     * @return bool Success status
     */
    private function deleteAISummary($formId)
    {
        $this->logAISummary('Deleting AI summary record - Form ID: ' . $formId);
        $sql = "DELETE FROM form_ai_summary WHERE id = ?";
        return sqlStatement($sql, [$formId]);
    }
    
    /**
     * Check if any pending AI transcriptions exist in session for current patient
     *
     * @param int $pid Patient ID to check for
     * @return bool True if pending transcriptions exist for this patient
     */
    public function hasPendingTranscriptions($pid = null)
    {
        if (empty($_SESSION['pending_ai_transcriptions'])) {
            return false;
        }
        
        // If no specific patient ID provided, check for any transcriptions
        if ($pid === null) {
            return !empty($_SESSION['pending_ai_transcriptions']);
        }
        
        // Check for transcriptions specific to this patient
        foreach ($_SESSION['pending_ai_transcriptions'] as $uuid => $data) {
            if (isset($data['pid']) && $data['pid'] == $pid) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get count of pending transcriptions in session for specific patient
     *
     * @param int $pid Patient ID to check for
     * @return int Number of pending transcriptions for this patient
     */
    public function getPendingTranscriptionCount($pid = null)
    {
        if (empty($_SESSION['pending_ai_transcriptions'])) {
            return 0;
        }
        
        // If no specific patient ID provided, return total count
        if ($pid === null) {
            return count($_SESSION['pending_ai_transcriptions']);
        }
        
        // Count transcriptions specific to this patient
        $count = 0;
        foreach ($_SESSION['pending_ai_transcriptions'] as $uuid => $data) {
            if (isset($data['pid']) && $data['pid'] == $pid) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Clean up transcriptions that don't belong to specified patient
     *
     * @param int $pid Patient ID to keep transcriptions for
     * @return int Number of transcriptions cleaned up
     */
    public function cleanupTranscriptionsForOtherPatients($pid)
    {
        if (empty($_SESSION['pending_ai_transcriptions'])) {
            return 0;
        }
        
        $cleanupCount = 0;
        foreach ($_SESSION['pending_ai_transcriptions'] as $uuid => $data) {
            if (!isset($data['pid']) || $data['pid'] !== $pid) {
                unset($_SESSION['pending_ai_transcriptions'][$uuid]);
                $cleanupCount++;
                $this->logAISummary('Cleaned up transcription for different patient - UUID: ' . $uuid . ', Patient: ' . ($data['pid'] ?? 'unknown') . ', Current: ' . $pid);
            }
        }
        
        if ($cleanupCount > 0) {
            $this->logAISummary('Transcription cleanup completed - Cleaned: ' . $cleanupCount . ', Remaining: ' . count($_SESSION['pending_ai_transcriptions']) . ', Patient: ' . $pid);
        }
        
        return $cleanupCount;
    }
} 