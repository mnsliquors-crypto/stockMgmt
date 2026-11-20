<?php
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../initialize.php';
if (!str_ends_with(DB_NAME,'_test')) exit("Use a disposable database ending in _test.\n");
$_SERVER['REMOTE_ADDR']='127.0.0.1';
require_once __DIR__.'/../classes/Master.php';
require_once __DIR__.'/../classes/Accounting/JournalService.php';
$_SESSION['userdata']=['id'=>1,'type'=>1,'login_type'=>1];
$service=new App\Accounting\JournalService($conn,1);
$tag='ERP POS '.bin2hex(random_bytes(4));
$db->queryPrepared("INSERT INTO entity_list (entity_type,display_name,status) VALUES ('Customer',?,1)",'s',[$tag]);
$customer=(int)$conn->insert_id;
$db->queryPrepared('INSERT INTO item_list (name,cost,opening_cost,selling_price,status) VALUES (?,10,10,20,1)','s',[$tag]);
$item=(int)$conn->insert_id;
$cash=(int)$conn->query('SELECT id FROM account_list ORDER BY id LIMIT 1')->fetch_assoc()['id'];
$input=['customer_id'=>$customer,'pos_date'=>'2199-02-10','item_id'=>[$item],'qty'=>[1],'price'=>[20],'total_price'=>[20],'account_amount'=>[$cash=>20]];
$_POST=$input; $first=json_decode($Master->save_pos(),true);
if (($first['status']??'')!=='success') throw new RuntimeException(json_encode($first));
$service->postSource((int)$first['sale_id']);
$_POST=$input; $second=json_decode($Master->save_pos(),true);
if (($second['status']??'')!=='success' || $first['sale_id']===$second['sale_id']) throw new RuntimeException('POS did not start a new document after posting.');
$original=$db->queryPrepared('SELECT total_amount FROM transactions WHERE id=?','i',[$first['sale_id']])->fetch_assoc();
if ($original['total_amount']!=='20.00') throw new RuntimeException('Posted POS document changed.');
$service->postSource((int)$second['sale_id']);
$payments=$db->queryPrepared("SELECT id FROM transactions WHERE parent_id IN (?,?) AND type='payment'",'ii',[$first['sale_id'],$second['sale_id']]);
while ($row=$payments->fetch_assoc()) $service->postSource((int)$row['id']);
if (!$service->report('trial_balance','2199-02-01','2199-02-28')['balanced']) throw new RuntimeException('POS journals do not balance.');
echo "Passed POS posting integration checks.\n";
