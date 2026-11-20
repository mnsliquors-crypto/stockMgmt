<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../classes/DBConnection.php';
require_once __DIR__.'/../classes/Enterprise/RetailDirectory.php';
require_once __DIR__.'/../database/migrations/007_retail_directory.php';
if (!str_ends_with(DB_NAME, '_test')) exit("Use a disposable database ending in _test.\n");
$connection=new DBConnection();$db=$connection->conn;migrate_retail_directory($db);
$tag=bin2hex(random_bytes(6));$a='retaila_'.$tag;$b='retailb_'.$tag;$inactive='inactive_'.$tag;$bad='bad_'.$tag;$control='control_'.$tag;
$insert=$db->prepare('INSERT INTO platform_retailers(code,name,database_name,active) VALUES(?,?,?,?)');
foreach ([[$a,'Retail A','retail_a_'.$tag,1],[$b,'Retail B','retail_b_'.$tag,1],[$inactive,'Inactive','inactive_'.$tag,0],[$bad,'Invalid','bad/name_'.$tag,1],[$control,'Control',DB_NAME,1]] as $row) $insert->execute($row);
$directory=new App\Enterprise\RetailDirectory($db);
try {
    $first=$directory->resolve(strtoupper($a));$second=$directory->resolve($b);
    if (!$first || !$second || $first['id']===$second['id'] || $first['database_name']===$second['database_name']) throw new RuntimeException('Retailer codes did not resolve independently.');
    foreach ([$inactive,$bad,$control,'../'.$a,'not_present_'.$tag,''] as $code) if ($directory->resolve($code)!==null) throw new RuntimeException('Invalid, inactive or control database accepted.');
    echo "Passed retail directory checks.\n";
} finally {
    $delete=$db->prepare('DELETE FROM platform_retailers WHERE code IN (?,?,?,?,?)');$delete->execute([$a,$b,$inactive,$bad,$control]);
}
