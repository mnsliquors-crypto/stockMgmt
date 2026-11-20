<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../classes/DBConnection.php';
require_once __DIR__ . '/../classes/Procurement/OrderService.php';
if (!str_ends_with(DB_NAME, '_test')) exit(1);
$db = new DBConnection();
$service = new App\Procurement\OrderService($db->conn, (int)($argv[1] ?? 0));
$input = json_decode(base64_decode($argv[2] ?? '', true), true, 512, JSON_THROW_ON_ERROR);
echo $service->receive($input);
