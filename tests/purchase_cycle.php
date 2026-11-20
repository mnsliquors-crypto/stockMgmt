<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../classes/DBConnection.php';
require_once __DIR__.'/../classes/Procurement/CycleService.php';
require_once __DIR__.'/../classes/Procurement/OrderService.php';
require_once __DIR__.'/../classes/Accounting/JournalService.php';
if(!str_ends_with(DB_NAME,'_test'))exit("Use a disposable database ending in _test.\n");
$connection=new DBConnection();$conn=$connection->conn;$checks=0;
function pcsql($sql,$values=[]){global $conn;$s=$conn->prepare($sql);$s->execute($values);return $s->get_result();}
function checkpc($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function failpc($work,$part){try{$work();}catch(Throwable $e){checkpc(str_contains(strtolower($e->getMessage()),strtolower($part)),'Expected '.$part.', got '.$e->getMessage());return;}throw new RuntimeException('Expected rejection: '.$part);}
function pckey(){return bin2hex(random_bytes(20));}
$suffix=bin2hex(random_bytes(5));
pcsql("INSERT INTO users(firstname,lastname,username,password,type) VALUES('Cycle','Checker',?,?,1)",['cycle_'.$suffix,password_hash('CycleTest123!',PASSWORD_DEFAULT)]);$checker=(int)$conn->insert_id;
$orders=new App\Procurement\OrderService($conn,1);$approval=new App\Procurement\OrderService($conn,$checker);
$cycle=new App\Procurement\CycleService($conn,1);$gl=new App\Accounting\JournalService($conn,$checker);
$period=$gl->createPeriod('Purchase Cycle 2201','2201-06-01','2201-06-30');
pcsql("INSERT INTO entity_list(entity_type,display_name,status) VALUES('Supplier',?,1)",['Cycle Supplier '.$suffix]);$vendor=(int)$conn->insert_id;
pcsql("INSERT INTO item_list(name,unit,vendor_id,cost,opening_cost) VALUES(?,'Each',?,10,10)",['Cycle Item '.$suffix,$vendor]);$item=(int)$conn->insert_id;
$input=['vendor_id'=>$vendor,'order_date'=>'2201-06-01','tax_rate'=>'13','request_key'=>pckey(),'lines'=>[['item_id'=>$item,'quantity'=>'10','unit_price'=>'10.005']]];
$orderId=$orders->save($input);$o=$orders->order($orderId);$orders->transition($orderId,'submit',$o['version']);$o=$orders->order($orderId);
if($o['status']==='pending')$approval->transition($orderId,'approve',$o['version']);$o=$orders->order($orderId);
$receive=['order_id'=>$orderId,'received_date'=>'2201-06-02','delivery_reference'=>'Delivery '.$suffix,'request_key'=>pckey(),'lines'=>[['order_line_id'=>$o['lines'][0]['id'],'quantity'=>'4']]];
$rid=$cycle->receive($receive);$r=$cycle->receipt($rid);
checkpc($cycle->receive($receive)===$rid,'Receipt request is idempotent');
failpc(fn()=>$cycle->receive(array_replace($receive,['notes'=>'Different'])),'request key');
checkpc($r['subtotal']==='40.02','Partial receipt uses exact approved price');
checkpc(pcsql('SELECT quantity FROM item_list WHERE id=?',[$item])->fetch_assoc()['quantity']==='4.0000','Only item receipt increases stock');
checkpc($orders->order($orderId)['lines'][0]['received']==='4.0000','PO uses unified received quantity');
checkpc(pcsql("SELECT COUNT(*) n FROM transactions WHERE type='purchase' AND entity_id=?",[$vendor])->fetch_assoc()['n']===0,'Receipt creates no bill or payable');
$draft=['order_id'=>$orderId,'bill_date'=>'2201-06-03','invoice_number'=>'CycleInvoice-'.$suffix,'request_key'=>pckey(),'lines'=>[['receipt_line_id'=>$r['lines'][0]['id'],'quantity'=>'2']]];
$bid=$cycle->saveBill($draft);$b=$cycle->bill($bid);
checkpc($cycle->saveBill($draft)===$bid,'Draft bill request is idempotent');
checkpc($b['subtotal']==='20.01'&&$b['tax']==='2.60','Bill and VAT use exact amounts');
failpc(fn()=>$cycle->reverseReceipt($rid,'2201-06-04','Already drafted'),'draft bills');
failpc(fn()=>$cycle->recordBill($bid,999),'changed');
$tx=$cycle->recordBill($bid,$b['version']);checkpc($cycle->recordBill($bid,$b['version'])===$tx,'Recording bill is idempotent');
checkpc(pcsql('SELECT COUNT(*) n FROM transaction_items WHERE transaction_id=?',[$tx])->fetch_assoc()['n']===0,'Bill never duplicates stock lines');
checkpc(pcsql('SELECT quantity FROM item_list WHERE id=?',[$item])->fetch_assoc()['quantity']==='4.0000','Billing does not change stock');
failpc(fn()=>$gl->postSource($tx),'linked item receipts');
$jr=$gl->postSource((int)$r['transaction_id']);$jb=$gl->postSource($tx);
$payment=['bill_id'=>$bid,'account_id'=>pcsql('SELECT id FROM account_list WHERE status=1 LIMIT 1')->fetch_assoc()['id'],'payment_date'=>'2201-06-04','amount'=>'10.00','request_key'=>pckey()];
$pid=$cycle->payBill($payment);checkpc($cycle->payBill($payment)===$pid,'Payment retry is idempotent');$gl->postSource($pid);
checkpc($cycle->bill($bid)['balance']==='12.61','Partial payment leaves exact balance');
failpc(fn()=>$cycle->payBill(array_replace($payment,['amount'=>'12.62','request_key'=>pckey()])),'exceeds');
failpc(fn()=>$cycle->payBill(array_replace($payment,['payment_date'=>'2201-06-02','request_key'=>pckey()])),'precede');
$p2=$cycle->payBill(array_replace($payment,['amount'=>'12.61','request_key'=>pckey()]));$gl->postSource($p2);
checkpc($cycle->bill($bid)['balance']==='0.00','Full settlement closes balance');
failpc(fn()=>$cycle->saveBill(array_replace($draft,['request_key'=>pckey()])),'already been recorded');
failpc(fn()=>$cycle->saveBill(array_replace($draft,['request_key'=>pckey(),'invoice_number'=>'OVER-'.$suffix,'lines'=>[['receipt_line_id'=>$r['lines'][0]['id'],'quantity'=>'3']]])),'exceeds');
failpc(fn()=>pcsql('UPDATE transactions SET total_amount=5 WHERE id=?',[$tx]),'immutable');
failpc(fn()=>pcsql('UPDATE po_vendor_bill_lines SET quantity=9 WHERE bill_id=?',[$bid]),'draft');
failpc(fn()=>pcsql('DELETE FROM po_cycle_payments WHERE transaction_id=?',[$pid]),'immutable');
failpc(fn()=>pcsql('UPDATE erp_line_details SET unit_factor=5 WHERE line_id=?',[$r['lines'][0]['transaction_line_id']]),'immutable');
// Two drafts may compete, but recording must recheck the remaining received quantity.
$d2=array_replace($draft,['request_key'=>pckey(),'invoice_number'=>'Second-'.$suffix]);$b2=$cycle->saveBill($d2);
$d3=array_replace($d2,['request_key'=>pckey(),'invoice_number'=>'Competing-'.$suffix]);$b3=$cycle->saveBill($d3);
$tx2=$cycle->recordBill($b2,$cycle->bill($b2)['version']);$gl->postSource($tx2);
failpc(fn()=>$cycle->recordBill($b3,$cycle->bill($b3)['version']),'exceeds');$cycle->cancelBill($b3,$cycle->bill($b3)['version'],'Competing draft');
$receive2=array_replace($receive,['request_key'=>pckey(),'lines'=>[['order_line_id'=>$o['lines'][0]['id'],'quantity'=>'6']]]);
$rid2=$cycle->receive($receive2);$r2=$cycle->receipt($rid2);$gl->postSource((int)$r2['transaction_id']);
checkpc($orders->order($orderId)['status']==='received','Final receipt completes PO');
failpc(fn()=>$cycle->receive(array_replace($receive,['request_key'=>pckey()])),'approved');
$rev=$cycle->reverseReceipt($rid2,'2201-06-05','Incorrect delivery');$rr=$cycle->receipt($rev);$gl->postSource((int)$rr['transaction_id']);
checkpc($cycle->reverseReceipt($rid2,'2201-06-05','Retry')===$rev,'Receipt reversal is idempotent');
checkpc($orders->order($orderId)['status']==='part_received','Receipt reversal reopens outstanding quantity');
checkpc(pcsql('SELECT quantity FROM item_list WHERE id=?',[$item])->fetch_assoc()['quantity']==='4.0000','Reversal removes only receipt stock');
checkpc((new App\Enterprise\InventoryService($conn,1))->stock(1,$item)[0]['quantity']==='4.0000','Location stock agrees');
checkpc(pcsql('SELECT SUM(movement) quantity FROM erp_operational_lines WHERE item_id=?',[$item])->fetch_assoc()['quantity']==='4.0000','Operational stock excludes bill quantities');
checkpc(bccomp(pcsql('SELECT SUM(purchase_value) amount FROM erp_operational_lines WHERE item_id=?',[$item])->fetch_assoc()['amount'],'40.02',2)===0,'Operational purchasing includes virtual bill lines');
checkpc(pcsql('SELECT SUM(quantity) quantity FROM erp_purchase_bill_items WHERE item_id=?',[$item])->fetch_assoc()['quantity']==='4.0000','Financial bill item read model includes billed quantities');
$grir=pcsql("SELECT COALESCE(SUM(l.credit-l.debit),0) amount FROM gl_journal_lines l JOIN gl_journals j ON j.id=l.journal_id AND j.status='posted' JOIN gl_accounts a ON a.id=l.account_id AND a.system_key='grir' WHERE j.source_transaction_id IN(?,?,?,?,?)",[$r['transaction_id'],$tx,$tx2,$r2['transaction_id'],$rr['transaction_id']])->fetch_assoc()['amount'];
checkpc($grir==='0.00','Fully billed receipts clear GR/IR exactly');
checkpc($gl->report('trial_balance','2201-06-01','2201-06-30')['balanced'],'Full cycle keeps trial balance balanced');
checkpc($orders->order($orderId)['lines'][0]['unbilled']==='0.0000','PO separately tracks unbilled quantity');
// Case quantities remain frozen while base-unit stock and cumulative cents agree.
pcsql("INSERT INTO item_list(name,unit,vendor_id,cost,opening_cost) VALUES(?,'Each',?,1,1)",['Cycle Cases '.$suffix,$vendor]);$caseItem=(int)$conn->insert_id;
(new App\Enterprise\InventoryService($conn,1))->savePackaging(['item_id'=>$caseItem,'version'=>'0','case_name'=>'Case','units_per_case'=>'12','case_cost'=>'0.335','case_price'=>'1']);
$caseInput=array_replace($input,['request_key'=>pckey(),'lines'=>[['item_id'=>$caseItem,'quantity'=>'3','unit_price'=>'0.335','unit_code'=>'case']]]);
$caseOrder=$orders->save($caseInput);$co=$orders->order($caseOrder);$orders->transition($caseOrder,'submit',$co['version']);$co=$orders->order($caseOrder);if($co['status']==='pending')$approval->transition($caseOrder,'approve',$co['version']);
$co=$orders->order($caseOrder);checkpc($co['lines'][0]['unit_factor']==='12.0000','PO freezes case factor');
$cr=['order_id'=>$caseOrder,'received_date'=>'2201-06-10','request_key'=>pckey(),'lines'=>[['order_line_id'=>$co['lines'][0]['id'],'quantity'=>'2']]];
$ci=$cycle->receive($cr);$creceipt=$cycle->receipt($ci);$gl->postSource((int)$creceipt['transaction_id']);
checkpc(pcsql('SELECT quantity FROM item_list WHERE id=?',[$caseItem])->fetch_assoc()['quantity']==='24.0000','Two cases receive 24 base units');
checkpc((new App\Enterprise\InventoryService($conn,1))->stock(1,$caseItem,'2201-06-09')[0]['quantity']==='0.0000','As-of stock excludes future receipts');
$cb=['order_id'=>$caseOrder,'bill_date'=>'2201-06-11','invoice_number'=>'Case-A-'.$suffix,'request_key'=>pckey(),'lines'=>[['receipt_line_id'=>$creceipt['lines'][0]['id'],'quantity'=>'1']]];
$cb1=$cycle->saveBill($cb);$cd=$cycle->bill($cb1);checkpc($cd['subtotal']==='0.34'&&$cd['tax']==='0.04','First fractional case bill rounds cents');$ct1=$cycle->recordBill($cb1,$cd['version']);$gl->postSource($ct1);
$cb2=$cycle->saveBill(array_replace($cb,['invoice_number'=>'Case-B-'.$suffix,'request_key'=>pckey()]));$cd2=$cycle->bill($cb2);checkpc($cd2['subtotal']==='0.33'&&$cd2['tax']==='0.05','Final partial bill clears cumulative inventory and VAT rounding');$ct2=$cycle->recordBill($cb2,$cd2['version']);$gl->postSource($ct2);
checkpc(pcsql('SELECT quantity FROM item_list WHERE id=?',[$caseItem])->fetch_assoc()['quantity']==='24.0000','Case bills do not double inventory');
failpc(fn()=>$cycle->receive(array_replace($cr,['request_key'=>pckey()])),'exceeds');
failpc(fn()=>$orders->receive(['order_id'=>$caseOrder,'received_date'=>'2201-06-12','invoice_number'=>'Legacy-Over-'.$suffix,'request_key'=>pckey(),'lines'=>$cr['lines']]),'exceeds');
$legacy=$orders->receive(['order_id'=>$caseOrder,'received_date'=>'2201-06-12','invoice_number'=>'Legacy-Final-'.$suffix,'request_key'=>pckey(),'lines'=>[['order_line_id'=>$co['lines'][0]['id'],'quantity'=>'1']]]);
checkpc($orders->order($caseOrder)['lines'][0]['received']==='3.0000','Combined and separate receipts share the PO cap');
failpc(fn()=>$cycle->saveBill(array_replace($cb,['invoice_number'=>'Legacy-Final-'.$suffix,'request_key'=>pckey()])),'already been recorded');
// Closed dates, immutable cash and stock allocations, and invalid precision are rejected.
$closed=$gl->createPeriod('Purchase Cycle Closed 2201','2201-07-01','2201-07-31');$gl->setPeriodStatus($closed,true,'Closed test period');
failpc(fn()=>$cycle->saveBill(array_replace($cb,['bill_date'=>'2201-07-01','request_key'=>pckey()])),'open accounting period');
failpc(fn()=>$cycle->payBill(array_replace($payment,['amount'=>'0.001','request_key'=>pckey()])),'decimal places');
failpc(fn()=>pcsql('UPDATE transaction_list SET amount=1 WHERE ref_table=\'transactions\' AND ref_id=?',[$pid]),'immutable');
failpc(fn()=>pcsql('INSERT INTO transaction_items(transaction_id,item_id,quantity,unit_price,total_price) VALUES(?,?,1,1,1)',[$tx,$item]),'immutable');
checkpc($gl->report('trial_balance','2201-06-01','2201-06-30')['balanced'],'Case and mixed workflows remain balanced');
$race=array_replace($payment,['bill_id'=>$cb1,'amount'=>'0.10','payment_date'=>'2201-06-12','request_key'=>pckey()]);
$workers=[];
for($n=0;$n<2;$n++){
    $pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/purchase_cycle_worker.php',json_encode($race,JSON_THROW_ON_ERROR)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($process))throw new RuntimeException('Cannot start payment race worker');fclose($pipes[0]);$workers[]=[$process,$pipes];
}
$results=[];foreach($workers as [$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);checkpc($status===0,'Concurrent payment: '.$error);$results[]=json_decode($output,true,512,JSON_THROW_ON_ERROR)['id'];}
checkpc($results[0]===$results[1],'Concurrent payment retries return one payment');
checkpc($cycle->bill($cb1)['paid']==='0.10'&&$cycle->bill($cb1)['balance']==='0.28','Concurrent retries debit the bank only once');
pcsql("INSERT INTO item_list(name,unit,vendor_id,cost,opening_cost) VALUES(?,'Each',?,1,1)",['Cycle History '.$suffix,$vendor]);$historyItem=(int)$conn->insert_id;
$historyOrder=$orders->save(array_replace($input,['request_key'=>pckey(),'lines'=>[['item_id'=>$historyItem,'quantity'=>'6','unit_price'=>'1']]]));$ho=$orders->order($historyOrder);$orders->transition($historyOrder,'submit',$ho['version']);$ho=$orders->order($historyOrder);if($ho['status']==='pending')$approval->transition($historyOrder,'approve',$ho['version']);$ho=$orders->order($historyOrder);
$hr=$cycle->receive(['order_id'=>$historyOrder,'received_date'=>'2201-06-10','request_key'=>pckey(),'lines'=>[['order_line_id'=>$ho['lines'][0]['id'],'quantity'=>'6']]]);
foreach(['sale'=>'2201-06-12','purchase'=>'2201-06-13']as $type=>$date){pcsql('INSERT INTO transactions(reference_code,type,total_amount,transaction_date,created_by) VALUES(?,?,6,?,1)',['HISTORY-'.$type.'-'.$suffix,$type,$date]);$t=(int)$conn->insert_id;pcsql('INSERT INTO transaction_items(transaction_id,item_id,quantity,unit_price,total_price,cost_price,cost_recorded) VALUES(?,?,6,1,6,1,1)',[$t,$historyItem]);}
(new App\Inventory\StockService($conn))->quantity($historyItem);
failpc(fn()=>$cycle->reverseReceipt($hr,'2201-06-11','Backdated history test'),'later movement date');
checkpc(pcsql('SELECT quantity FROM item_list WHERE id=?',[$historyItem])->fetch_assoc()['quantity']==='6.0000','Rejected backdated reversal rolls back all stock');
$roundingOrder=$orders->save(array_replace($input,['request_key'=>pckey(),'lines'=>[['item_id'=>$historyItem,'quantity'=>'2','unit_price'=>'0.335']]]));$ro=$orders->order($roundingOrder);$orders->transition($roundingOrder,'submit',$ro['version']);$ro=$orders->order($roundingOrder);if($ro['status']==='pending')$approval->transition($roundingOrder,'approve',$ro['version']);$ro=$orders->order($roundingOrder);
$ri=$cycle->receive(['order_id'=>$roundingOrder,'received_date'=>'2201-06-15','request_key'=>pckey(),'lines'=>[['order_line_id'=>$ro['lines'][0]['id'],'quantity'=>'1']]]);$rd=$cycle->receipt($ri);
$rb=$cycle->saveBill(['order_id'=>$roundingOrder,'bill_date'=>'2201-06-16','invoice_number'=>'Mixed-Round-'.$suffix,'request_key'=>pckey(),'lines'=>[['receipt_line_id'=>$rd['lines'][0]['id'],'quantity'=>'1']]]);$cycle->recordBill($rb,$cycle->bill($rb)['version']);
$rl=$orders->receive(['order_id'=>$roundingOrder,'received_date'=>'2201-06-17','invoice_number'=>'Mixed-Legacy-'.$suffix,'request_key'=>pckey(),'lines'=>[['order_line_id'=>$ro['lines'][0]['id'],'quantity'=>'1']]]);
checkpc(pcsql('SELECT tax FROM po_receipts WHERE id=?',[$rl])->fetch_assoc()['tax']==='0.05','Mixed receiving paths share cumulative tax rounding');
checkpc(pcsql('SELECT tax FROM po_invoiced_totals WHERE order_id=?',[$roundingOrder])->fetch_assoc()['tax']==='0.09','Mixed invoice tax equals approved PO tax');
echo "Passed $checks purchase-cycle checks.\n";
