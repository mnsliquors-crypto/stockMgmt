<?php
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/../classes/DBConnection.php';
require_once __DIR__.'/../classes/Enterprise/CompanyAdministration.php';
require_once __DIR__.'/../database/migrations/008_shared_companies.php';
if(!str_ends_with(DB_NAME,'_test'))exit("Use a disposable database ending in _test.\n");
$connection=new DBConnection();$db=$connection->conn;migrate_shared_companies($db);$checks=0;
function caCheck(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function caFail(callable $work,string $part):void {try{$work();}catch(Throwable $e){caCheck(str_contains(strtolower($e->getMessage()),strtolower($part)),'Unexpected error: '.$e->getMessage());return;}throw new RuntimeException('Expected error: '.$part);}
function caHash(mysqli $db,string $table):string {$rows=$db->query('SELECT * FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC);$rows=array_map(fn($r)=>json_encode($r,JSON_THROW_ON_ERROR),$rows);sort($rows);return hash('sha256',implode("\n",$rows));}
$before=[];foreach(['transactions','transaction_items','item_list','entity_list'] as $table)$before[$table]=caHash($db,$table);
$admin=(int)$db->query("SELECT id FROM users WHERE username='admin' AND type=1")->fetch_assoc()['id'];$service=new App\Enterprise\CompanyAdministration($db,$admin);
$retail=(int)$db->query("SELECT id FROM platform_company_types WHERE code='retailer'")->fetch_assoc()['id'];$tag=bin2hex(random_bytes(6));
$id=$service->create(['code'=>'test_'.$tag,'name'=>'Test '.$tag,'company_type_id'=>$retail,'email'=>'example@example.test']);
caCheck((bool)array_filter($service->companies(),fn($c)=>(int)$c['id']===$id),'Created company listed');
caFail(fn()=>$service->setPermission($id,'admin.git_sync','view',true),'company type');
caFail(fn()=>$service->setPermission($id,'app.profile','view',false),'protected');
$service->setPermission($id,'sales.invoices','view',false);
foreach($before as $table=>$hash)caCheck(caHash($db,$table)===$hash,$table.' unchanged by permission change');
$service->setPermission($id,'sales.invoices','view',true);
foreach($before as $table=>$hash)caCheck(caHash($db,$table)===$hash,$table.' unchanged after permission restore');
$service->setStatus($id,'blocked');$service->setStatus($id,'active');
$audit=$db->query('SELECT COUNT(*) n FROM platform_audit_events WHERE company_id='.(int)$id)->fetch_assoc();caCheck((int)$audit['n']===5,'Changes audited');
$other=$db->query("SELECT id FROM users WHERE id<>".$admin." LIMIT 1")->fetch_assoc();
if($other)caFail(fn()=>(new App\Enterprise\CompanyAdministration($db,(int)$other['id']))->companies(),'super admin');
echo "Passed $checks draft company administration checks.\n";
