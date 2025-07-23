<?php

/**
 * AISummaryService
 * 
 * Handles creation and management of AI summaries for encounters
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
     * Create AI summary from pending session data after encounter is saved
     *
     * @param int $encounterId The encounter ID that was just saved
     * @param string $encounterUuid The UUID of the saved encounter
     * @param int $pid Patient ID
     * @return array|false Array with form ID on success, false on failure
     */
    public function createFromSessionData($encounterId, $encounterUuid, $pid)
    {
        $this->logger->info("Checking for pending AI transcriptions for encounter", [
            'encounter_id' => $encounterId,
            'encounter_uuid' => $encounterUuid
        ]);
        
        // Check if we have pending transcriptions for this encounter
        if (empty($_SESSION['pending_ai_transcriptions'][$encounterId])) {
            $this->logger->debug("No pending AI transcriptions found for encounter", [
                'encounter_id' => $encounterId
            ]);
            return false;
        }
        
        $transcriptionData = $_SESSION['pending_ai_transcriptions'][$encounterId];
        
        try {
            // Create AI summary with proper encounter UUID linking
            $summaryData = [
                'pid' => $pid,
                'encounter' => $encounterId,
                'user' => $transcriptionData['user'],
                'groupname' => $transcriptionData['provider'],
                'voice_transcription' => $transcriptionData['transcription'],
                'summary_type' => 'transcription',
                'ai_model_used' => $transcriptionData['model'],
                'processing_status' => 'completed',
                'transcription_source' => $transcriptionData['source']
            ];
            
            // Only add encounter_uuid if it's provided and valid
            if (!empty($encounterUuid)) {
                $summaryData['encounter_uuid'] = UuidRegistry::uuidToBytes($encounterUuid);
                $this->logger->debug("Adding encounter UUID to AI summary", ['uuid' => bin2hex($summaryData['encounter_uuid'])]);
            } else {
                $this->logger->debug("No encounter UUID provided, creating AI summary without UUID link");
            }
            
            $formId = $this->createAISummary($summaryData);
            
            if ($formId) {
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
                    $transcriptionData['user']
                );
                
                if (!$addFormResult) {
                    // If form registration failed, clean up the ai_summary record
                    $this->deleteAISummary($formId);
                    throw new \Exception('Failed to register form in OpenEMR');
                }
                
                // Clear the pending transcription from session
                unset($_SESSION['pending_ai_transcriptions'][$encounterId]);
                
                $this->logger->info("AI Summary created successfully from session data", [
                    'form_id' => $formId,
                    'encounter_id' => $encounterId,
                    'encounter_uuid' => $encounterUuid
                ]);
                
                return ['form_id' => $formId, 'encounter_id' => $encounterId];
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to create AI summary from session", [
                'error' => $e->getMessage(),
                'encounter_id' => $encounterId
            ]);
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
        $sql = "INSERT INTO form_ai_summary 
                (pid, encounter, encounter_uuid, user, groupname, authorized, activity, date, 
                 voice_transcription, summary_type, ai_model_used, processing_status, 
                 transcription_source, created_date) 
                VALUES (?, ?, ?, ?, ?, 1, 1, NOW(), ?, ?, ?, ?, ?, NOW())";
        
        $binds = [
            $data['pid'],
            $data['encounter'],
            $data['encounter_uuid'],
            $data['user'],
            $data['groupname'],
            $data['voice_transcription'],
            $data['summary_type'],
            $data['ai_model_used'],
            $data['processing_status'],
            $data['transcription_source']
        ];
        
        return sqlInsert($sql, $binds);
    }
    
    /**
     * Delete an AI summary record
     *
     * @param int $formId The form ID to delete
     * @return bool Success status
     */
    private function deleteAISummary($formId)
    {
        $sql = "DELETE FROM form_ai_summary WHERE id = ?";
        return sqlStatement($sql, [$formId]);
    }
    
    /**
     * Check if any pending AI transcriptions exist in session
     *
     * @return bool True if pending transcriptions exist
     */
    public function hasPendingTranscriptions()
    {
        return !empty($_SESSION['pending_ai_transcriptions']);
    }
    
    /**
     * Get count of pending transcriptions in session
     *
     * @return int Number of pending transcriptions
     */
    public function getPendingTranscriptionCount()
    {
        if (empty($_SESSION['pending_ai_transcriptions'])) {
            return 0;
        }
        return count($_SESSION['pending_ai_transcriptions']);
    }
} 