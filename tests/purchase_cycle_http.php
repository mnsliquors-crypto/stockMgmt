<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../classes/DBConnection.php';
require_once __DIR__.'/../classes/Enterprise/Access.php';
if(!str_ends_with(DB_NAME,'_test'))exit("Use a disposable database ending in _test.\n");
$connection=new DBConnection();$conn=$connection->conn;$checks=0;
$base=rtrim(getenv('APP_BASE_URL')?:'http://127.0.0.1:8081/','/').'/';
if(parse_url($base,PHP_URL_PORT)!==8081)exit("Use the test server on port 8081.\n");
function pchsql($sql,$v=[]){global $conn;$s=$conn->prepare($sql);$s->execute($v);return $s->get_result();}
function pch($c,$path,$status=200,$data=null,$token=null){global $base,$checks;curl_setopt_array($c,[CURLOPT_URL=>$base.$path,CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>$token?['X-CSRF-Token: '.$token]:[]]);if($data===null)curl_setopt($c,CURLOPT_HTTPGET,true);else{curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($data));}$body=curl_exec($c);$got=curl_getinfo($c,CURLINFO_HTTP_CODE);if($got!==$status||preg_match('/Fatal error|Uncaught |<b>Warning<\/b>/',$body?:''))throw new RuntimeException($path.' expected '.$status.', got '.$got.': '.substr(strip_tags($body?:curl_error($c)),-1500));$checks++;return $body;}
function pchlogin($name,$password){$c=curl_init();$html=pch($c,'admin/login.php');preg_match('/name="csrf-token" content="([a-f0-9]+)"/',$html,$m);$r=json_decode(pch($c,'classes/Login.php?f=login',200,['username'=>$name,'password'=>$password],$m[1]),true);if(($r['status']??'')!=='success')throw new RuntimeException('Login failed');return[$c,$m[1]];}
$anon=curl_init();pch($anon,'classes/PurchaseCycle.php?f=bills',401);
$admin=pchlogin('admin','admin123');
foreach(['receive_items','save_bill','record_bill','cancel_bill','pay_bill','reverse_receipt']as $action){pch($admin[0],'classes/PurchaseCycle.php?f='.$action,405);pch($admin[0],'classes/PurchaseCycle.php?f='.$action,403,[]);}
pch($admin[0],'classes/PurchaseCycle.php?f=bill&id[]=1',400);
pch($admin[0],'classes/PurchaseCycle.php?f=save_bill',422,['lines'=>'bad'],$admin[1]);
foreach(['receipts','bills']as $action)pch($admin[0],'classes/PurchaseCycle.php?f='.$action);
foreach(['item_receipts','vendor_bills','vendor_bills&new=1']as $page)pch($admin[0],'admin/?page=transactions/'.$page);
$receipt=pchsql('SELECT id,order_id FROM po_item_receipts ORDER BY id LIMIT 1')->fetch_assoc();
if($receipt){pch($admin[0],'admin/?page=transactions/item_receipts&id='.$receipt['id']);pch($admin[0],'admin/?page=transactions/vendor_bills&new=1&order_id='.$receipt['order_id']);}
$order=pchsql("SELECT o.id,l.id AS line_id FROM po_orders o JOIN po_lines l ON l.order_id=o.id JOIN po_received_quantities r ON r.order_line_id=l.id WHERE o.status='part_received' AND l.quantity-r.quantity>=1 LIMIT 1")->fetch_assoc();
if($order){
    $payload=['order_id'=>$order['id'],'received_date'=>'2201-06-20','delivery_reference'=>'HTTP cycle','request_key'=>bin2hex(random_bytes(20)),'lines'=>[['order_line_id'=>$order['line_id'],'quantity'=>'1']]];
    $r=json_decode(pch($admin[0],'classes/PurchaseCycle.php?f=receive_items',200,$payload,$admin[1]),true)['data']['id'];
    $replayed=json_decode(pch($admin[0],'classes/PurchaseCycle.php?f=receive_items',200,$payload,$admin[1]),true)['data']['id'];if($r!==$replayed)throw new RuntimeException('HTTP receipt retry duplicated source');$checks++;
    $details=json_decode(pch($admin[0],'classes/PurchaseCycle.php?f=receipt&id='.$r),true)['data'];
    $draftPayload=['order_id'=>$order['id'],'bill_date'=>'2201-06-20','invoice_number'=>'HTTP-CYCLE-'.bin2hex(random_bytes(5)),'request_key'=>bin2hex(random_bytes(20)),'lines'=>[['receipt_line_id'=>$details['lines'][0]['id'],'quantity'=>'1']]];
    $newDraft=json_decode(pch($admin[0],'classes/PurchaseCycle.php?f=save_bill',200,$draftPayload,$admin[1]),true)['data']['id'];
    pch($admin[0],'admin/?page=transactions/vendor_bills&id='.$newDraft);
    pch($admin[0],'classes/PurchaseCycle.php?f=cancel_bill',200,['id'=>$newDraft,'version'=>'1','reason'=>'HTTP draft cancellation'],$admin[1]);
    pch($admin[0],'classes/PurchaseCycle.php?f=record_bill',422,['id'=>$newDraft,'version'=>'1'],$admin[1]);
}
$bill=pchsql("SELECT id FROM po_vendor_bills WHERE status='recorded' LIMIT 1")->fetch_assoc();if($bill)pch($admin[0],'admin/?page=transactions/vendor_bills&id='.$bill['id']);
$payment=pchsql('SELECT transaction_id FROM po_cycle_payments LIMIT 1')->fetch_assoc();
if($payment){foreach(['view_payment','manage_payment']as $page)pch($admin[0],'admin/?page=transactions/payments/'.$page.'&id='.$payment['transaction_id']);pch($admin[0],'admin/?page=payments');}
$draft=pchsql("SELECT id,version FROM po_vendor_bills WHERE status='draft' LIMIT 1")->fetch_assoc();
if($draft)pch($admin[0],'classes/PurchaseCycle.php?f=cancel_bill',200,['id'=>$draft['id'],'version'=>$draft['version'],'reason'=>'HTTP cancellation verification'],$admin[1]);
$tag=bin2hex(random_bytes(4));$name='cycle_reader_'.$tag;
pchsql("INSERT INTO users(firstname,lastname,username,password,type) VALUES('Cycle','Reader',?,?,2)",[$name,password_hash('CycleReader123!',PASSWORD_DEFAULT)]);$user=(int)$conn->insert_id;
pchsql("INSERT INTO erp_access_roles(name,description,permissions,active) VALUES(?,'Purchase-cycle HTTP test',?,1)",['Cycle reader '.$tag,json_encode(['procurement'=>'read','purchases'=>'read','payments'=>'read'])]);$role=(int)$conn->insert_id;
pchsql('INSERT INTO erp_user_roles(user_id,role_id) VALUES(?,?)',[$user,$role]);$reader=pchlogin($name,'CycleReader123!');
foreach(['receipts','bills']as $action)pch($reader[0],'classes/PurchaseCycle.php?f='.$action);
foreach(['receive_items','save_bill','record_bill','cancel_bill','pay_bill','reverse_receipt']as $action)pch($reader[0],'classes/PurchaseCycle.php?f='.$action,403,[],$reader[1]);
pchsql('UPDATE erp_access_roles SET permissions=? WHERE id=?',[json_encode(['inventory'=>'read']),$role]);
foreach(['receipts','bills']as $action)pch($reader[0],'classes/PurchaseCycle.php?f='.$action,403);
foreach(['item_receipts','vendor_bills']as $page)pch($reader[0],'admin/?page=transactions/'.$page,403);
echo "Passed $checks purchase-cycle HTTP checks.\n";
