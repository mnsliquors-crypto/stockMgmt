<?php
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../classes/DBConnection.php';
require_once __DIR__.'/../classes/Accounting/JournalService.php';
if (!str_ends_with(DB_NAME,'_test') || !ctype_digit($argv[1]??'')) exit(1);
$db=new DBConnection();
echo (new App\Accounting\JournalService($db->conn,1))->postSource((int)$argv[1]);
