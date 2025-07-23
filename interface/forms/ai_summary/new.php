<?php
/**
 * AI Summary form new.php - Creates new AI summary forms
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc.php");
require_once("$srcdir/forms.inc.php");

use OpenEMR\Core\Header;

$returnurl = 'encounter_top.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt("AI Summary"); ?></title>
    <?php Header::setupHeader(); ?>
</head>
<body class="body_top">
    <div class="container mt-3">
        <h2><?php echo xlt("AI Summary"); ?></h2>
        <div class="alert alert-info">
            <?php echo xlt("AI Summary forms are automatically created when voice transcriptions are completed."); ?>
        </div>
        <a href="<?php echo $GLOBALS['form_exit_url']; ?>" class="btn btn-secondary">
            <?php echo xlt("Return to Encounter"); ?>
        </a>
    </div>
</body>
</html> 