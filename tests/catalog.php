<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../classes/DBConnection.php';
require_once __DIR__.'/../classes/Enterprise/CatalogService.php';
require_once __DIR__.'/../classes/Enterprise/LineService.php';
require_once __DIR__.'/../classes/Procurement/OrderService.php';
require_once __DIR__.'/../classes/Procurement/CycleService.php';
require_once __DIR__.'/../classes/Accounting/JournalService.php';
require_once __DIR__.'/../classes/Enterprise/DashboardService.php';
if(!str_ends_with(DB_NAME,'_test'))exit("Use a disposable database ending in _test.\n");
$connection=new DBConnection();$conn=$connection->conn;$checks=0;$tag=bin2hex(random_bytes(5));
function cq($sql,$values=[]){global $conn;$s=$conn->prepare($sql);$s->execute($values);return $s->get_result();}
function cc($ok,$label){global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function cf($work,$message){try{$work();}catch(Throwable $e){cc(str_contains(strtolower($e->getMessage()),strtolower($message)),'Expected '.$message.', got '.$e->getMessage());return;}throw new RuntimeException('Expected failure: '.$message);}
function ck(){return bin2hex(random_bytes(20));}
$catalog=new App\Enterprise\CatalogService($conn,1);$gl=new App\Accounting\JournalService($conn,1);$orders=new App\Procurement\OrderService($conn,1);$cycle=new App\Procurement\CycleService($conn,1);
$period=$gl->createPeriod('Catalog 2203','2203-01-01','2203-01-31');
$unit=$catalog->saveLookup(['kind'=>'units','code'=>'H-'.$tag,'name'=>'Hours '.$tag,'active'=>'1']);
$class=$catalog->saveLookup(['kind'=>'class','code'=>'C-'.$tag,'name'=>'Consulting '.$tag,'active'=>'1']);
$department=$catalog->saveLookup(['kind'=>'department','code'=>'D-'.$tag,'name'=>'Operations '.$tag,'active'=>'1']);
$bin=$catalog->saveLookup(['kind'=>'bins','code'=>'B-'.$tag,'name'=>'Rack '.$tag,'location_id'=>'1','active'=>'1']);
$expense=$gl->createAccount(['code'=>'CE-'.$tag,'name'=>'Catalog Purchase Expense','category'=>'expense']);
$income=$gl->createAccount(['code'=>'CI-'.$tag,'name'=>'Catalog Service Income','category'=>'income']);
$inventory=$gl->createAccount(['code'=>'CA-'.$tag,'name'=>'Catalog Inventory','category'=>'asset']);
cq("INSERT INTO entity_list(entity_type,display_name,status) VALUES('Supplier',?,1)",['Catalog Vendor '.$tag]);$vendor=(int)$conn->insert_id;
cq("INSERT INTO entity_list(entity_type,display_name,status) VALUES('Customer',?,1)",['Catalog Customer '.$tag]);$customer=(int)$conn->insert_id;
$base=['status'=>'1','tax_type'=>'1','unit_id'=>$unit,'location_id'=>'1','bin_id'=>$bin,'class_id'=>$class,'department_id'=>$department,'inventory_account_id'=>$inventory,'income_account_id'=>$income,'expense_account_id'=>$expense,'cost'=>'10','selling_price'=>'25','mrp'=>'30','vendor_id'=>$vendor];
$ids=[];
foreach(App\Enterprise\CatalogService::TYPES as $type=>$label)foreach(App\Enterprise\CatalogService::USAGES as $usage=>$unused){
    $id=$catalog->saveItem($base+['name'=>$label.' '.$usage.' '.$tag,'sku'=>'CAT-'.$type.'-'.$usage.'-'.$tag,'item_type'=>$type,'item_usage'=>$usage]);$ids[$type][$usage]=$id;$i=$catalog->item($id);
    cc($i['item_type']===$type&&$i['item_usage']===$usage,'All four item types support all usage modes');
    cc(App\Enterprise\CatalogService::tracked($conn,$id)===in_array($type,['inventory','assembly']),'Only physical item types track stock');
    if($usage==='sale')cf(fn()=>App\Enterprise\CatalogService::assertUsage($conn,$id,true),'purchasable');else App\Enterprise\CatalogService::assertUsage($conn,$id,true);
    if($usage==='purchase')cf(fn()=>App\Enterprise\CatalogService::assertUsage($conn,$id,false),'sellable');else App\Enterprise\CatalogService::assertUsage($conn,$id,false);
}
cf(fn()=>$catalog->saveItem(array_replace($base,['name'=>'Bad account '.$tag,'expense_account_id'=>$income])),'expense account');
cf(fn()=>$catalog->saveItem(array_replace($base,['name'=>'Wrong dimension '.$tag,'class_id'=>$department])),'active class');
cf(fn()=>$catalog->saveItem($base+['name'=>'Disabled matrix '.$tag,'is_matrix_parent'=>'1']),'Enable matrix');
$settings=$catalog->settings();$catalog->configure(['version'=>$settings['version'],'matrix_enabled'=>'1']);
foreach(App\Enterprise\CatalogService::TYPES as $type=>$label){
    $parent=$catalog->saveItem($base+['name'=>'Matrix '.$label.' '.$tag,'sku'=>'M-'.$type.'-'.$tag,'item_type'=>$type,'item_usage'=>'resale','is_matrix_parent'=>'1']);$p=$catalog->item($parent);
    $request=['id'=>$parent,'catalog_version'=>$p['catalog_version'],'axes'=>[['name'=>'Color','values'=>['Red','Blue']],['name'=>'Size','values'=>['S','L']]]];
    $created=$catalog->generate($request);cc(count($created['created'])===4,'Matrix generation for '.$type);
    $p=$catalog->item($parent);cc(count($p['variants'])===4,'Each combination has its own item record');
    foreach($p['variants']as $v)cc($v['item_type']===$type&&$v['item_usage']==='resale'&&$v['sku']!==$p['sku'],'Variants inherit type and usage with unique SKUs');
    cc(!$catalog->generate(array_replace($request,['catalog_version'=>$p['catalog_version']]))['created'],'Generating existing combinations is retry-safe');
    cf(fn()=>App\Enterprise\CatalogService::assertUsage($conn,$parent,true),'variant');
    $p=$catalog->item($parent);$tooMany=['id'=>$parent,'catalog_version'=>$p['catalog_version'],'axes'=>[['name'=>'A','values'=>range(1,11)],['name'=>'B','values'=>range(1,10)]]];cf(fn()=>$catalog->generate($tooMany),'100 combinations');
}
cf(fn()=>$catalog->configure(['version'=>$catalog->settings()['version'],'matrix_enabled'=>'0']),'Deactivate matrix');
$service=$ids['service']['resale'];
$order=$orders->save(['vendor_id'=>$vendor,'order_date'=>'2203-01-02','tax_rate'=>'0','request_key'=>ck(),'lines'=>[['item_id'=>$service,'quantity'=>'3','unit_price'=>'10']]]);$o=$orders->order($order);$orders->transition($order,'submit',$o['version']);$o=$orders->order($order);
if($o['status']==='pending'){
    cq("INSERT INTO users(firstname,lastname,username,password,type) VALUES('Catalog','Checker',?,?,1)",['catalog_checker_'.$tag,password_hash(ck(),PASSWORD_DEFAULT)]);$checker=new App\Procurement\OrderService($conn,(int)$conn->insert_id);$checker->transition($order,'approve',$o['version']);
}
$o=$orders->order($order);$rid=$cycle->receive(['order_id'=>$order,'received_date'=>'2203-01-03','request_key'=>ck(),'lines'=>[['order_line_id'=>$o['lines'][0]['id'],'quantity'=>'3']]]);$r=$cycle->receipt($rid);
cc(cq('SELECT quantity FROM item_list WHERE id=?',[$service])->fetch_assoc()['quantity']==='0.0000','Service acceptance never creates stock');
$receiptJournal=$gl->postSource((int)$r['transaction_id']);
cc(cq('SELECT debit FROM gl_journal_lines WHERE journal_id=? AND account_id=?',[$receiptJournal,$expense])->fetch_assoc()['debit']==='30.00','Service acceptance debits its expense account, not inventory');
$bill=$cycle->saveBill(['order_id'=>$order,'bill_date'=>'2203-01-04','invoice_number'=>'SERVICE-'.$tag,'request_key'=>ck(),'lines'=>[['receipt_line_id'=>$r['lines'][0]['id'],'quantity'=>'2']]]);$billTx=$cycle->recordBill($bill,$cycle->bill($bill)['version']);$gl->postSource($billTx);
cc(cq('SELECT quantity FROM item_list WHERE id=?',[$service])->fetch_assoc()['quantity']==='0.0000','Service bill does not manufacture stock');
function ct($type,$item,$amount,$entity,$entityType){global $conn;$ref='CATDOC-'.ck();cq('INSERT INTO transactions(reference_code,type,total_amount,transaction_date,entity_id,entity_type,created_by) VALUES(?,?,?,\'2203-01-05\',?,?,1)',[$ref,$type,$amount,$entity,$entityType]);$tx=(int)$conn->insert_id;cq('INSERT INTO transaction_items(transaction_id,item_id,quantity,unit_price,total_price,cost_price,cost_recorded) VALUES(?,?,2,25,?,10,1)',[$tx,$item,$amount]);return $tx;}
$sale=ct('sale',$service,'50',$customer,'Customer');$saleLine=(int)cq('SELECT id FROM transaction_items WHERE transaction_id=?',[$sale])->fetch_assoc()['id'];
cc(cq('SELECT cost_price FROM transaction_items WHERE id=?',[$saleLine])->fetch_assoc()['cost_price']==='0.0000','Service sales have no fictitious inventory COGS');
$snap=cq('SELECT * FROM erp_line_catalog WHERE line_id=?',[$saleLine])->fetch_assoc();cc((int)$snap['class_id']===$class&&(int)$snap['department_id']===$department&&(int)$snap['bin_id']===$bin,'Classification and bin defaults snapshot onto document lines');
$alternate=$gl->createAccount(['code'=>'CI2-'.$tag,'name'=>'Future Service Income','category'=>'income']);$current=$catalog->item($service);$catalog->saveItem(array_replace($base,$current,['income_account_id'=>$alternate]));
$salesJournal=$gl->postSource($sale);cc(cq('SELECT credit FROM gl_journal_lines WHERE journal_id=? AND account_id=?',[$salesJournal,$income])->fetch_assoc()['credit']==='50.00','Posting uses frozen item COA, not later defaults');
cf(fn()=>cq('UPDATE erp_line_catalog SET class_id=NULL WHERE line_id=?',[$saleLine]),'immutable');
cf(fn()=>$catalog->saveItem(array_replace($base,$catalog->item($service),['item_type'=>'inventory'])),'cannot change');
$purchase=ct('purchase',$ids['non_inventory']['purchase'],'50',$vendor,'Supplier');$pj=$gl->postSource($purchase);cc(cq('SELECT debit FROM gl_journal_lines WHERE journal_id=? AND account_id=?',[$pj,$expense])->fetch_assoc()['debit']==='50.00','Direct non-inventory purchase posts to expense');
cc((new App\Inventory\StockService($conn))->quantity($ids['non_inventory']['purchase'])==='0.0000','Non-inventory direct purchase does not create stock');
cf(fn()=>ct('sale',$ids['service']['purchase'],'50',$customer,'Customer'),'purchase only');
cf(fn()=>ct('purchase',$ids['inventory']['sale'],'50',$vendor,'Supplier'),'sale only');
cf(fn()=>ct('adjustment',$ids['service']['resale'],'50',$vendor,'Supplier'),'Non-stock');
$otherLocation=(new App\Enterprise\InventoryService($conn,1))->saveLocation(['code'=>'CAT-'.$tag,'name'=>'Catalog Transfer Branch','active'=>'1']);
cf(fn()=>(new App\Enterprise\InventoryService($conn,1))->saveTransfer(['from_location_id'=>'1','to_location_id'=>$otherLocation,'transfer_date'=>'2203-01-05','notes'=>'Invalid non-stock transfer','request_key'=>ck(),'lines'=>[['item_id'=>$service,'quantity'=>'1']]]),'inventory or assembly');
cc(bccomp(cq('SELECT COALESCE(SUM(movement),0) qty FROM erp_operational_lines WHERE item_id=?',[$service])->fetch_assoc()['qty'],'0',4)===0,'Operational reports exclude service stock movement');
cc(bccomp(cq('SELECT COALESCE(SUM(cogs),0) cost FROM erp_operational_lines WHERE item_id=?',[$service])->fetch_assoc()['cost'],'0',4)===0,'Service revenue has no inventory COGS in reports');
cc($gl->report('trial_balance','2203-01-01','2203-01-31')['balanced'],'Item type accounting remains balanced');
foreach(['account'=>$income,'unit'=>$unit,'bin'=>$bin,'customer'=>$customer,'vendor'=>$vendor,'location'=>$otherLocation,'class'=>$class,'department'=>$department]as $kind=>$record){
    $version=0;
    foreach(['purchase','sale','resale']as $usage){
        $catalog->saveUsage(['record_type'=>$kind,'record_id'=>$record,'item_usage'=>$usage,'version'=>$version]);$version++;
        $rows=array_column($catalog->usages($kind),null,'id');cc($rows[$record]['item_usage']===$usage,'Usage applies to '.$kind.' masters');
        if($usage!=='resale')cf(fn()=>App\Enterprise\CatalogService::assertRecordUsage($conn,$kind,$record,$usage==='sale'),'cannot be used');
    }
    cf(fn()=>$catalog->saveUsage(['record_type'=>$kind,'record_id'=>$record,'item_usage'=>'purchase','version'=>0]),'changed');
}
$catalog->saveUsage(['record_type'=>'customer','record_id'=>$customer,'item_usage'=>'purchase','version'=>3]);cf(fn()=>ct('sale',$service,'50',$customer,'Customer'),'Party usage');$catalog->saveUsage(['record_type'=>'customer','record_id'=>$customer,'item_usage'=>'resale','version'=>4]);
$catalog->saveUsage(['record_type'=>'vendor','record_id'=>$vendor,'item_usage'=>'sale','version'=>3]);cf(fn()=>ct('purchase',$service,'50',$vendor,'Supplier'),'Party usage');$catalog->saveUsage(['record_type'=>'vendor','record_id'=>$vendor,'item_usage'=>'resale','version'=>4]);
$catalog->saveUsage(['record_type'=>'unit','record_id'=>$unit,'item_usage'=>'sale','version'=>3]);cf(fn()=>(new App\Enterprise\LineService($conn,1))->prepare(['item_id'=>[$service],'qty'=>['1'],'price'=>['10']],'purchase'),'cannot be used');$catalog->saveUsage(['record_type'=>'unit','record_id'=>$unit,'item_usage'=>'resale','version'=>4]);
$catalog->saveUsage(['record_type'=>'account','record_id'=>$alternate,'item_usage'=>'purchase','version'=>0]);$restrictedSale=ct('sale',$service,'50',$customer,'Customer');cf(fn()=>$gl->postSource($restrictedSale),'cannot be used');$catalog->saveUsage(['record_type'=>'account','record_id'=>$alternate,'item_usage'=>'resale','version'=>1]);$gl->postSource($restrictedSale);
$dashboard=(new App\Enterprise\DashboardService($conn,1))->data(['from'=>'2203-01-01','to'=>'2203-01-31']);
cc($dashboard['metrics']['expenses']==='80.00','Operational expenses include service acceptance and direct non-stock purchases once');
cc($dashboard['metrics']['net_profit']==='20.00','Operational net profit includes non-stock purchase expense');
cc(bccomp($dashboard['monthly'][0]['expenses'],'80',2)===0,'Monthly expense chart includes non-stock purchase expenses');
echo "Passed $checks catalog checks.\n";
