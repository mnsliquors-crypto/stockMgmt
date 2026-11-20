<?php
namespace App\Enterprise;
require_once __DIR__.'/FeatureService.php';

// Preview controls must never be mistaken for active authorization.
function requireFeaturePreview(\mysqli $db, int $user): FeatureService {
    $allowedDatabase = str_ends_with(DB_NAME, '_test') || getenv('FEATURE_CONTROLS_ALLOW_MAIN_PREVIEW') === '1';
    if (getenv('FEATURE_CONTROLS_PREVIEW') !== '1' || !$allowedDatabase || !FeaturePolicy::available($db)) {
        \deny_request(404, 'Feature controls are not available.');
    }
    $service = new FeatureService($db, $user);
    if (!$service->isAdmin()) \deny_request(403, 'Administrator access required.');
    return $service;
}
