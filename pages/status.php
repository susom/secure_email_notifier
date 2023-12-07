<?php
namespace Stanford\SecureEmailNotifier;

/** @var SecureEmailNotifier $module */

// An example project header
include_once APP_PATH_DOCROOT . "ControlCenter/header.php";

// Replace this with your module code
echo "Hello from $module->PREFIX";

$module->checkToRun();

echo "<br/><h4>DONE</h4>";
