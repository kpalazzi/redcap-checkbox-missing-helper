<?php
/**
 * AJAX endpoint — update_formulas.php
 *
 * Called by online_designer.js on page load and after each field save.
 * Re-checks all @CHECKBOXMISSING tagged fields and creates or updates
 * companion calc field formulas as needed.
 *
 * Returns a JSON response describing what was created or updated.
 */

/** @var \HMRI\CheckboxMissing\CheckboxMissing $module */

header('Content-Type: application/json');

$project_id = intval($_POST['project_id'] ?? 0);
if (!$project_id) {
    echo json_encode(['status' => 'error', 'message' => 'No project_id supplied']);
    exit;
}

// Verify the user is either a REDCap administrator or has project design rights.
// Admin privileges supersede project-level user rights.
global $super_user;
if (!$super_user) {
    $rights = REDCap::getUserRights(USERID);
    if (empty($rights[USERID]['design'])) {
        echo json_encode(['status' => 'error', 'message' => 'Insufficient privileges']);
        exit;
    }
}

$result = $module->updateAllFormulas($project_id);
echo json_encode($result);
