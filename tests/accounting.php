<?php
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../classes/DBConnection.php';
require_once __DIR__.'/../classes/Accounting/JournalService.php';
use App\Accounting\JournalService;
use App\Accounting\Money;
if (!str_ends_with(DB_NAME,'_test')) exit("Use a disposable database ending in _test.\n");
$db=new DBConnection(); $conn=$db->conn; $service=new JournalService($conn,1); $checks=0;
function accounting_check($condition,$message) { global $checks; if (!$condition) throw new RuntimeException($message); $checks++; }
function fails(callable $work,string $message) {
    $error=null; try { $work(); } catch (Throwable $caught) { $error=$caught; }
    accounting_check($error!==null && str_contains(strtolower($error->getMessage()),strtolower($message)), 'Expected rejection: '.$message.($error?' ('.$error->getMessage().')':''));
}
function sql(string $query,array $parameters=[]) {
    global $conn; $stmt=$conn->prepare($query); $stmt->execute($parameters); return $stmt->get_result();
}
function source(string $type,string $amount,array $extra=[]): int {
    global $conn;
    $data=array_merge(['type'=>$type,'total_amount'=>$amount,'reference_code'=>'ERP-'.bin2hex(random_bytes(6)),
        'transaction_date'=>'2199-01-10','created_by'=>1],$extra);
    $columns=array_keys($data);
    sql('INSERT INTO transactions (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')',array_values($data));
    return (int)$conn->insert_id;
}
function itemLine(int $transaction,int $item,string $quantity,string $price,string $cost='10.0000',int $recorded=1): void {
    sql('INSERT INTO transaction_items (transaction_id,item_id,quantity,unit_price,total_price,cost_price,cost_recorded) VALUES (?,?,?,?,?,?,?)',
        [$transaction,$item,$quantity,$price,Money::multiply($quantity,$price),$cost,$recorded]);
}

accounting_check(Money::add('0.10','0.20')==='0.30','Exact decimal addition');
accounting_check(Money::decimal('1.005')==='1.01' && Money::decimal('-1.005')==='-1.01','Symmetric half-up rounding');
accounting_check(Money::multiply('3','0.335')==='1.01','Round line extensions once');
accounting_check(Money::add('999999999999.99','0.01')==='1000000000000.00','Large amounts preserve cents');
fails(fn()=>Money::decimal('NaN'),'valid amount');
$column=sql("SHOW COLUMNS FROM transactions LIKE 'total_amount'")->fetch_assoc();
accounting_check($column['Type']==='decimal(18,2)','Money stored in decimal columns');
$period=$service->createPeriod('ERP tests January 2199','2199-01-01','2199-01-31');
fails(fn()=>$service->createPeriod('Overlap','2199-01-10','2199-02-01'),'overlap');
$accounts=array_column($service->accounts(),'id','system_key');
$asset=(int)$accounts['receivables']; $equity=(int)$accounts['opening_equity'];
$draft=['entry_date'=>'2199-01-10','memo'=>'Exact journal test','request_key'=>bin2hex(random_bytes(20)),
    'lines'=>[['account_id'=>$asset,'debit'=>'0.30','credit'=>'0'],['account_id'=>$equity,'debit'=>'0','credit'=>'0.30']]];
$journal=$service->saveDraft($draft);
accounting_check($service->saveDraft($draft)===$journal,'Duplicate draft submission is idempotent');
$changed=$draft; $changed['memo']='Different payload';
fails(fn()=>$service->saveDraft($changed),'different data');
$service->postJournal($journal);
accounting_check($service->postJournal($journal)===$journal,'Repeated posting is idempotent');
$trial=$service->report('trial_balance','2199-01-01','2199-01-31');
accounting_check($trial['balanced'] && $trial['debit']==='0.30' && $trial['credit']==='0.30','Trial balance includes only posted entries');
fails(fn()=>sql('UPDATE gl_journals SET memo=? WHERE id=?',['Tamper',$journal]),'cannot be edited');
fails(fn()=>sql('DELETE FROM gl_journals WHERE id=?',[$journal]),'cannot be deleted');
fails(fn()=>sql('UPDATE gl_journal_lines SET debit=5 WHERE journal_id=? AND debit>0',[$journal]),'immutable');
fails(fn()=>sql('INSERT INTO gl_journal_lines (journal_id,account_id,debit) VALUES (?,?,1)',[$journal,$asset]),'immutable');

$unbalanced=$draft; $unbalanced['request_key']=bin2hex(random_bytes(20)); $unbalanced['lines'][1]['credit']='0.20';
$bad=$service->saveDraft($unbalanced);
fails(fn()=>$service->postJournal($bad),'balance');
fails(fn()=>sql("UPDATE gl_journals SET status='posted' WHERE id=?",[$bad]),'balance');
fails(fn()=>$service->setPeriodStatus($period,true,'Close test'),'draft');
$service->deleteDraft($bad);
accounting_check(sql('SELECT id FROM gl_journals WHERE id=?',[$bad])->num_rows===0,'Discard draft');
fails(fn()=>sql('INSERT INTO gl_journal_lines (journal_id,account_id,debit,credit) VALUES (?,?,1,1)',[$bad,$asset]),'either');

$supplier=(int)sql("SELECT id FROM entity_list WHERE entity_type='Supplier' LIMIT 1")->fetch_assoc()['id'];
$customer=(int)sql("SELECT id FROM entity_list WHERE entity_type='Customer' LIMIT 1")->fetch_assoc()['id'];
sql("INSERT INTO item_list (name,cost,opening_cost,status) VALUES (?,10,10,1)",['ERP fixture '.bin2hex(random_bytes(4))]);
$item=(int)$conn->insert_id;
$banks=array_column(sql('SELECT id FROM account_list ORDER BY id LIMIT 2')->fetch_all(MYSQLI_ASSOC),'id');
accounting_check(count($banks)===2,'Cash account fixtures available');
$purchase=source('purchase','100.00',['entity_id'=>$supplier,'tax'=>'13.00']); itemLine($purchase,$item,'10','10');
$preview=$service->previewSource($purchase);
accounting_check(count($preview['lines'])===3,'Purchase preview includes inventory, purchase tax, and payable');
$purchase_journal=$service->postSource($purchase);
accounting_check($service->postSource($purchase)===$purchase_journal,'Source cannot post twice');
fails(fn()=>sql('UPDATE transactions SET total_amount=200 WHERE id=?',[$purchase]),'immutable');
fails(fn()=>sql('DELETE FROM transactions WHERE id=?',[$purchase]),'immutable');
fails(fn()=>sql('UPDATE transaction_items SET quantity=20 WHERE transaction_id=?',[$purchase]),'immutable');
fails(fn()=>itemLine($purchase,$item,'1','10'),'immutable');
fails(fn()=>$service->reverseJournal($purchase_journal,'2199-01-11','Wrong correction'),'linked return');
$payment=source('payment','113.00',['entity_id'=>$supplier,'parent_id'=>$purchase,'account_id'=>$banks[0]]);
sql("INSERT INTO transaction_list (account_id,amount,type,trans_code,ref_table,ref_id) VALUES (?,113,2,?,'transactions',?)",[$banks[0],'ERP-PAY-'.$payment,$payment]);
$service->postSource($payment);
accounting_check(sql('SELECT gl_account_id FROM gl_bank_mapping WHERE bank_account_id=?',[$banks[0]])->num_rows===1,'Cash accounts map to general ledger accounts');
fails(fn()=>sql("UPDATE transaction_list SET amount=114 WHERE ref_table='transactions' AND ref_id=?",[$payment]),'immutable');

$sale=source('sale','24.00',['entity_id'=>$customer]); itemLine($sale,$item,'2','12');
$service->postSource($sale);
$sale_journal=sql('SELECT j.id FROM gl_journals j WHERE source_transaction_id=?',[$sale])->fetch_assoc()['id'];
accounting_check(count($service->journal((int)$sale_journal)['lines'])===4,'Sales post revenue and cost of goods sold');
$return=source('return','12.00',['entity_id'=>$customer,'entity_type'=>'Customer','parent_id'=>$sale]); itemLine($return,$item,'1','12');
$service->postSource($return);
$excess=source('return','24.00',['entity_id'=>$customer,'entity_type'=>'Customer','parent_id'=>$sale]); itemLine($excess,$item,'2','12');
fails(fn()=>$service->postSource($excess),'exceeds');
sql('DELETE FROM transaction_items WHERE transaction_id=?',[$excess]); sql('DELETE FROM transactions WHERE id=?',[$excess]);
$legacy=source('sale','5.00',['entity_id'=>$customer]); itemLine($legacy,$item,'1','5','0',0);
fails(fn()=>$service->postSource($legacy),'Historical sale cost');
sql('DELETE FROM transaction_items WHERE transaction_id=?',[$legacy]); sql('DELETE FROM transactions WHERE id=?',[$legacy]);

$expense=source('expense','5.00',['account_id'=>$banks[0],'status'=>1]);
sql("INSERT INTO transaction_list (account_id,amount,type,trans_code,ref_table,ref_id) VALUES (?,5,2,?,'transactions',?)",[$banks[0],'ERP-EXP-'.$expense,$expense]);
$service->postSource($expense);
$transfer=source('transfer','10.00');
foreach ([[$banks[0],2],[$banks[1],1]] as [$bank,$direction]) sql("INSERT INTO transaction_list (account_id,amount,type,trans_code,ref_table,ref_id) VALUES (?,10,?,?,'transactions',?)",[$bank,$direction,'ERP-TR-'.$transfer,$transfer]);
$service->postSource($transfer);
$opening=source('opening_stock','10.00'); itemLine($opening,$item,'1','10'); $service->postSource($opening);
$adjustment=source('adjustment','10.00'); itemLine($adjustment,$item,'-1','10'); $service->postSource($adjustment);
$income=$service->report('income_statement','2199-01-01','2199-01-31');
accounting_check($income['net_profit']==='-13.00','Income statement includes sale, return, COGS, expense, and stock loss');
$balance=$service->report('balance_sheet','2199-01-01','2199-01-31');
accounting_check($balance['balanced'],'Balance sheet balances including accumulated earnings');
accounting_check($service->report('trial_balance','2199-01-01','2199-01-31')['balanced'],'All source journals balance');
$pending=source('sale','1.00');
fails(fn()=>$service->setPeriodStatus($period,true,'Closing with pending source'),'Unposted business');
sql('DELETE FROM transactions WHERE id=?',[$pending]);
$service->setPeriodStatus($period,true,'January reviewed');
fails(fn()=>source('sale','1.00'),'closed');
fails(fn()=>$service->saveDraft(array_replace($draft,['request_key'=>bin2hex(random_bytes(20))])),'reopen');
fails(fn()=>sql('UPDATE transaction_items SET quantity=2 WHERE transaction_id=?',[$opening]),'immutable');
$february=$service->createPeriod('ERP tests February 2199','2199-02-01','2199-02-28');
$reversal=$service->reverseJournal($journal,'2199-02-02','Correct duplicate opening journal');
accounting_check($service->reverseJournal($journal,'2199-02-02','Repeated click')===$reversal,'A journal can be reversed only once');
accounting_check($service->journal($journal)['reversal_id']==$reversal,'Original journal retains link to reversal');
$old_trial=$service->report('trial_balance','2199-01-01','2199-01-31');
accounting_check($old_trial['balanced'],'Later reversal preserves closed-period trial balance');
fails(fn()=>sql('DELETE FROM erp_audit_events WHERE object_id=?',[$journal]),'append-only');
fails(fn()=>sql("UPDATE erp_audit_events SET action='tampered' WHERE object_id=?",[$journal]),'append-only');
$service->setPeriodStatus($period,false,'Authorized correction window');
accounting_check(sql('SELECT status FROM gl_periods WHERE id=?',[$period])->fetch_assoc()['status']==='open','Period reopening records the correction window');
fails(fn()=>$service->setPeriodStatus($period,true,''),'reason');

// Two independent connections attempt to post the same source document.
$concurrent=source('purchase','10.00',['entity_id'=>$supplier,'transaction_date'=>'2199-02-03']); itemLine($concurrent,$item,'1','10');
$workers=[];
for ($i=0;$i<2;$i++) {
    $pipes=[]; $process=proc_open([PHP_BINARY,__DIR__.'/accounting_worker.php',(string)$concurrent],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $workers[]=[$process,$pipes];
}
$ids=[];
foreach ($workers as [$process,$pipes]) {
    $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    accounting_check(proc_close($process)===0,'Concurrent worker succeeds: '.$err); $ids[]=trim($out);
}
accounting_check($ids[0]===$ids[1] && ctype_digit($ids[0]),'Concurrent posting returns the same journal');
accounting_check(sql('SELECT id FROM gl_journals WHERE source_transaction_id=?',[$concurrent])->num_rows===1,'Concurrent posting creates exactly one journal');
echo "Passed $checks accounting checks.\n";
