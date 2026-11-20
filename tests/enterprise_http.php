<?php
if (PHP_SAPI!=='cli') {http_response_code(403);exit;}
require_once __DIR__.'/../classes/DBConnection.php';
require_once __DIR__.'/../classes/Enterprise/ReportService.php';
if (!str_ends_with(DB_NAME,'_test')) exit("Use a disposable database ending in _test.\n");
$connection=new DBConnection();$conn=$connection->conn;$checks=0;
$base=rtrim(getenv('APP_BASE_URL')?:'http://127.0.0.1:8081/','/').'/';
if (parse_url($base,PHP_URL_PORT)!==8081) exit("Use the disposable app on port 8081.\n");
function eh_sql($sql,$values=[]) {global $conn;$s=$conn->prepare($sql);$s->execute($values);return $s->get_result();}
function eh_check($ok,$label) {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function eh_request($client,$path,$expected=200,$data=null,$token=null) {
    global $base,$checks;
    curl_setopt_array($client,[CURLOPT_URL=>$base.$path,CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$token?['X-CSRF-Token: '.$token]:[]]);
    if($data===null)curl_setopt($client,CURLOPT_HTTPGET,true);
    else {curl_setopt($client,CURLOPT_POST,true);curl_setopt($client,CURLOPT_POSTFIELDS,http_build_query($data));}
    $body=curl_exec($client);$status=curl_getinfo($client,CURLINFO_HTTP_CODE);
    if($body===false||$status!==$expected||preg_match('/Fatal error|Uncaught |<b>Warning<\/b>/',$body))throw new RuntimeException($path.' expected '.$expected.', got '.$status.': '.substr(strip_tags($body?:curl_error($client)),-1300));
    $checks++;return $body;
}
function eh_login($name,$password) {
    $client=curl_init();$body=eh_request($client,'admin/login.php');preg_match('/name="csrf-token" content="([a-f0-9]+)"/',$body,$m);
    $result=json_decode(eh_request($client,'classes/Login.php?f=login',200,['username'=>$name,'password'=>$password],$m[1]),true);
    eh_check(($result['status']??'')==='success','Login');return [$client,$m[1]];
}
function eh_api($client,$action,$data,$status=200) {return json_decode(eh_request($client[0],'classes/Enterprise.php?f='.$action,$status,$data,$client[1]),true)['data']??[];}
$tag=bin2hex(random_bytes(5));$password='EnterpriseHttp123!';
foreach(['admin'=>1,'staff'=>2,'reader'=>2] as $role=>$type) {
    $names[$role]='enterprise_http_'.$role.'_'.$tag;
    eh_sql("INSERT INTO users(firstname,lastname,username,password,type) VALUES('Enterprise','HTTP',?,?,?)",[$names[$role],password_hash($password,PASSWORD_DEFAULT),$type]);$ids[$role]=(int)$conn->insert_id;
}
$anonymous=curl_init();eh_request($anonymous,'classes/Enterprise.php?f=dashboard',401);
$admin=eh_login($names['admin'],$password);$staff=eh_login($names['staff'],$password);$reader=eh_login($names['reader'],$password);
eh_request($admin[0],'classes/Enterprise.php?f=save_location',405);
eh_request($admin[0],'classes/Enterprise.php?f=save_location',403,[]);
eh_request($admin[0],'classes/Enterprise.php?f=stock&location_id[]=1',400);
eh_request($admin[0],'admin/?page[]=home',404);
foreach(['save_location','save_packaging','save_promotion','save_role','save_fiscal_year'] as $action)eh_api($staff,$action,[],403);
foreach(['health','roles','assignments','fiscal_years'] as $action)eh_request($staff[0],'classes/Enterprise.php?f='.$action,403);
foreach(['home','enterprise/inventory','enterprise/inventory&view=locations','enterprise/inventory&view=packaging','enterprise/inventory&view=transfers','enterprise/promotions','enterprise/promotions&new=1','enterprise/activities','enterprise/activities&new=1','enterprise/activities&view=calendar','enterprise/reports','enterprise/roles','enterprise/health','enterprise/assistant','enterprise/receipts','enterprise/fiscal_years','enterprise/credits'] as $page)eh_request($admin[0],'admin/?page='.$page);
foreach(App\Enterprise\ReportService::CATALOG as $report=>$definition) {
    $result=json_decode(eh_request($admin[0],'classes/Enterprise.php?f=report&report='.$report.'&from=2000-01-01&to=2200-12-31&party_id=1&item_id=1'),true);
    eh_check(($result['status']??'')==='success'&&isset($result['data']['rows']),'Report '.$report);
}
$csv=eh_request($admin[0],'classes/Enterprise.php?f=report_export&report=sales_register&from=2000-01-01&to=2200-12-31');eh_check(str_contains($csv,'Document'),'Report CSV');
$location=eh_api($admin,'save_location',['code'=>'HTTP-'.$tag,'name'=>'HTTP Branch '.$tag,'active'=>'1'])['id'];
$task=['request_key'=>bin2hex(random_bytes(20)),'kind'=>'task','title'=>'HTTP task '.$tag,'starts_at'=>date('Y-m-d').'T10:00','assigned_to'=>$ids['staff'],'status'=>'open','priority'=>'high'];
$activity=eh_api($admin,'save_activity',$task)['id'];eh_check(eh_api($admin,'save_activity',$task)['id']===$activity,'Idempotent activity');
$current=json_decode(eh_request($staff[0],'classes/Enterprise.php?f=activity&id='.$activity),true)['data'];
eh_api($staff,'save_activity',array_replace($current,['status'=>'completed']));eh_api($staff,'save_activity',array_replace($current,['status'=>'cancelled']),409);
$role=eh_api($admin,'save_role',['name'=>'HTTP Reader '.$tag,'active'=>'1','permissions'=>['inventory'=>'read','payments'=>'read','sales'=>'read','reports'=>'read']])['id'];
eh_api($admin,'assign_role',['user_id'=>$ids['reader'],'role_id'=>$role]);
eh_request($reader[0],'admin/',302);eh_check(str_ends_with(curl_getinfo($reader[0],CURLINFO_REDIRECT_URL),'page=enterprise/inventory'),'Restricted staff land on their first permitted screen');
eh_request($reader[0],'classes/Enterprise.php?f=stock');
eh_request($reader[0],'admin/?page=enterprise/inventory');
eh_request($reader[0],'classes/Enterprise.php?f=transaction_options');
eh_request($reader[0],'classes/Master.php?f=search_parties&search=HTTP');
eh_api($reader,'save_transfer',[],403);
eh_request($reader[0],'classes/Master.php?f=save_sale',403,[],$reader[1]);
eh_request($reader[0],'classes/Procurement.php?f=save_order',403,[],$reader[1]);
eh_request($reader[0],'classes/Accounting.php?f=post_source',403,[],$reader[1]);
eh_request($reader[0],'classes/GitSync.php?f=status',403,[],$reader[1]);
eh_request($reader[0],'admin/?page=system/settings',403);
eh_request($reader[0],'admin/modules/system/import/process_import.php',403,[],$reader[1]);
eh_request($reader[0],'classes/Enterprise.php?f=dashboard',403);
eh_sql('UPDATE erp_access_roles SET active=0 WHERE id=?',[$role]);eh_request($reader[0],'classes/Enterprise.php?f=stock',403);
eh_request($reader[0],'admin/',302);eh_check(str_ends_with(curl_getinfo($reader[0],CURLINFO_REDIRECT_URL),'page=system/users/profile'),'An inactive role retains profile access without data access');
eh_sql('UPDATE users SET type=2 WHERE id=?',[$ids['admin']]);eh_request($admin[0],'classes/GitSync.php?f=status',403,[],$admin[1]);eh_api($admin,'save_location',[],403);
// Stable browser fixtures exist only in this disposable database.
eh_sql("INSERT INTO erp_access_roles(name,description,permissions,active) VALUES('Enterprise Browser Stock Reader','Browser role fixture',?,1) ON DUPLICATE KEY UPDATE permissions=VALUES(permissions),active=1",[json_encode(['inventory'=>'read'])]);
$browserRole=(int)eh_sql("SELECT id FROM erp_access_roles WHERE name='Enterprise Browser Stock Reader'")->fetch_assoc()['id'];
if(!eh_sql("SELECT id FROM users WHERE username='enterprise_browser_reader'")->num_rows)eh_sql("INSERT INTO users(firstname,lastname,username,password,type) VALUES('Browser','Stock Reader','enterprise_browser_reader',?,2)",[password_hash('EnterpriseBrowser123!',PASSWORD_DEFAULT)]);
$browserReader=(int)eh_sql("SELECT id FROM users WHERE username='enterprise_browser_reader'")->fetch_assoc()['id'];
eh_sql('INSERT INTO erp_user_roles(user_id,role_id) VALUES(?,?) ON DUPLICATE KEY UPDATE role_id=VALUES(role_id)',[$browserReader,$browserRole]);
foreach(['Enterprise Browser Item','Enterprise Browser Supplier'] as $name) {
    if($name==='Enterprise Browser Supplier')continue;
    if(!eh_sql('SELECT id FROM item_list WHERE name=?',[$name])->num_rows) {
        eh_sql("INSERT INTO entity_list(entity_type,display_name,status) VALUES('Supplier','Enterprise Browser Supplier',1)");$vendor=(int)$conn->insert_id;
        eh_sql("INSERT INTO item_list(name,unit,cost,opening_cost,selling_price,mrp,reorder_level,vendor_id,status) VALUES(?,'Bottle',10,10,15,20,10,?,1)",[$name,$vendor]);$item=(int)$conn->insert_id;
        require_once __DIR__.'/../classes/Master.php';$_SESSION['userdata']=['id'=>1,'type'=>1,'login_type'=>1];
        $_POST=['vendor_id'=>$vendor,'date_po'=>date('Y-m-d'),'item_id'=>[$item],'qty'=>['1000'],'price'=>['10'],'remarks'=>'Disposable browser fixture'];
        eh_check((json_decode($Master->save_purchase(),true)['status']??'')==='success','Browser stock fixture');
        require_once __DIR__.'/../classes/Enterprise/InventoryService.php';
        (new App\Enterprise\InventoryService($conn,1))->savePackaging(['item_id'=>$item,'version'=>'0','case_name'=>'Case','units_per_case'=>'12','case_cost'=>'110','case_price'=>'150','barcode'=>'ERPBROWSERBASE','case_barcode'=>'ERPBROWSERCASE']);
    }
}
echo "Passed $checks enterprise HTTP checks.\n";
