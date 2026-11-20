<?php
require_once __DIR__ . '/../../../../config.php';
if ((int)$_settings->userdata('type') !== 1) deny_request(403, 'Administrator access required.');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') deny_request(405, 'Use POST for migrations.');

$migrations = [
    "ALTER TABLE `cash_denominations` ADD COLUMN IF NOT EXISTS `reconciliation_status` TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE `cash_denominations` ADD COLUMN IF NOT EXISTS `finalized_by` INT NULL DEFAULT NULL",
    "ALTER TABLE `cash_denominations` ADD COLUMN IF NOT EXISTS `finalized_at` DATETIME NULL DEFAULT NULL",
];

$results = [];
foreach($migrations as $sql){
    if($conn->query($sql)){
        $results[] = ['ok', $sql];
    } else {
        $results[] = ['err', $sql . ' | ' . $conn->error];
    }
}

header('Content-Type: application/json');
echo json_encode($results);
?>
