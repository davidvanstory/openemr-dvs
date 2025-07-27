<?php

/**
 * This class is used to render the report for the encounter forms. It takes into account any module
 * forms and will render the report for the form.
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2025 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Common\Forms;

use OpenEMR\Common\Logging\SystemLogger;

class FormReportRenderer
{
    private SystemLogger $logger;
    private FormLocator $locator;
    public function __construct(?FormLocator $locator = null, ?SystemLogger $logger = null)
    {
        $this->locator = $locator ?? new FormLocator();
        $this->logger = $logger ?? new SystemLogger();
    }

    public function renderReport(string $formDir, string $page, $attendant_id, $encounter, $columns, $formId, $noWrap = true)
    {
        global $srcdir; // make sure we provide any form globals that are used for path references here.
        global $webroot;
        global $rootdir;
        
        // Debug: Log start of form rendering
        error_log("FORM_REPORT_RENDERER: Starting renderReport for formDir='$formDir', encounter='$encounter', formId='$formId'", 3, "/tmp/ai_summary.log");
        
        $isLBF = str_starts_with($formDir, 'LBF');
        $formLocator = new FormLocator();
        $formPath = $formLocator->findFile($formDir, 'report.php', $page);
        
        // Debug: Log the path that was found
        error_log("FORM_REPORT_RENDERER: Found form path: '$formPath'", 3, "/tmp/ai_summary.log");
        
        include_once $formPath;
        
        // Debug: Log after include
        error_log("FORM_REPORT_RENDERER: Included report.php for formDir='$formDir'", 3, "/tmp/ai_summary.log");
        
        if ($isLBF) {
            error_log("FORM_REPORT_RENDERER: Calling lbf_report for LBF form '$formDir'", 3, "/tmp/ai_summary.log");
            lbf_report($attendant_id, $encounter, $columns, $formId, $formDir, $noWrap);
        } else {
            $functionName = $formDir . "_report";
            error_log("FORM_REPORT_RENDERER: Looking for function '$functionName'", 3, "/tmp/ai_summary.log");
            
            if (function_exists($functionName)) {
                error_log("FORM_REPORT_RENDERER: Calling function '$functionName' with params: attendant_id='$attendant_id', encounter='$encounter', columns='$columns', formId='$formId'", 3, "/tmp/ai_summary.log");
                call_user_func($functionName, $attendant_id, $encounter, $columns, $formId);
                error_log("FORM_REPORT_RENDERER: Successfully called function '$functionName'", 3, "/tmp/ai_summary.log");
            } else {
                error_log("FORM_REPORT_RENDERER: ERROR - Function '$functionName' does not exist", 3, "/tmp/ai_summary.log");
                $this->logger->errorLogCaller("form is missing report function", ['formdir' => $formDir, 'formId' => $formId]);
            }
        }
        
        // Debug: Log completion
        error_log("FORM_REPORT_RENDERER: Completed renderReport for formDir='$formDir'", 3, "/tmp/ai_summary.log");
    }
}
