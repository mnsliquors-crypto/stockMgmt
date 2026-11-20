<?php
if (PHP_SAPI!=='cli') { http_response_code(403); exit; }
require_once __DIR__.'/../initialize.php';
if (!str_ends_with(DB_NAME,'_test')) exit("Use a disposable database ending in _test.\n");
$_SERVER['REMOTE_ADDR']='127.0.0.1';
require_once __DIR__.'/../classes/Master.php';
require_once __DIR__.'/../classes/Accounting/JournalService.php';
$_SESSION['userdata']=['id'=>1,'type'=>1,'login_type'=>1];
$service=new App\Accounting\JournalService($conn,1);
function settlement_action(string $method,array $input): array {
    global $Master; $_POST=$input; $result=json_decode($Master->$method(),true);
    if (($result['status']??'')!=='success') throw new RuntimeException($method.': '.json_encode($result));
    return $result;
}
$tag='ERP settlement '.bin2hex(random_bytes(4));
$vendor=settlement_action('save_vendor',['name'=>$tag,'contact'=>'','address'=>'','cperson'=>'','status'=>1]);
$item=settlement_action('save_item',['name'=>$tag,'description'=>'','cost'=>25,'selling_price'=>30,'status'=>1]);
$purchase=settlement_action('save_purchase',['vendor_id'=>$vendor['id'],'date_po'=>'2199-02-12','item_id'=>[$item['id']],
    'qty'=>[4],'price'=>[25],'total'=>[100],'amount'=>100,'tax_perc'=>13,'remarks'=>'ERP VAT test']);
$service->postSource((int)$purchase['id']);
$return=settlement_action('save_return',['parent_id'=>$purchase['id'],'vendor_id'=>$vendor['id'],'entity_type'=>'vendor','date_created'=>'2199-02-13',
    'item_id'=>[$item['id']],'qty'=>[1],'price'=>[25],'total'=>[25],'amount'=>25,'tax'=>'3.25','remarks'=>'ERP partial return']);
$service->postSource((int)$return['id']);
$outstanding=settlement_action('get_outstanding_bills',['type'=>2,'party_id'=>$vendor['id']]);
if (count($outstanding['data'])!==1 || (float)$outstanding['data'][0]['outstanding']!==84.75) throw new RuntimeException('Outstanding amount does not include VAT and return tax.');
$cash=(int)$conn->query('SELECT id FROM account_list ORDER BY id LIMIT 1')->fetch_assoc()['id'];
$payment=settlement_action('save_bulk_payment',['type'=>2,'party_id'=>$vendor['id'],'date_created'=>'2199-02-14','invoices'=>[$purchase['id']],
    'allocation'=>['84.75'],'account_amounts'=>[$cash=>'84.75'],'remarks'=>'ERP VAT settlement']);
$service->postSource((int)$payment['id']);
$outstanding=settlement_action('get_outstanding_bills',['type'=>2,'party_id'=>$vendor['id']]);
if ($outstanding['data']) throw new RuntimeException('Settled invoice is still outstanding.');
$result=$db->queryPrepared("SELECT SUM(l.credit-l.debit) AS balance FROM gl_journal_lines l JOIN gl_journals j ON j.id=l.journal_id JOIN gl_accounts a ON a.id=l.account_id WHERE a.system_key='payables' AND j.source_transaction_id IN (?,?,?)",'iii',[$purchase['id'],$return['id'],$payment['id']])->fetch_assoc();
if (App\Accounting\Money::compare($result['balance'],'0')!==0) throw new RuntimeException('Supplier general ledger balance does not reconcile.');
echo "Passed VAT, partial return, and payment reconciliation integration checks.\n";
