<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/Enterprise/WorkspaceService.php';
require_once __DIR__.'/Enterprise/InventoryService.php';
require_once __DIR__.'/Enterprise/PromotionService.php';
require_once __DIR__.'/Enterprise/LineService.php';
require_once __DIR__.'/Enterprise/DashboardService.php';
require_once __DIR__.'/Enterprise/FiscalService.php';
require_once __DIR__.'/Enterprise/CreditService.php';

use App\Enterprise\WorkspaceService;
use App\Enterprise\InventoryService;
use App\Enterprise\PromotionService;
use App\Enterprise\LineService;
use App\Enterprise\DashboardService;
use App\Enterprise\ReportService;

$action=is_string($_GET['f']??null)?$_GET['f']:'';
$reads=['options','transaction_options','preferences','activities','activity','locations','stock','packaging','transfers','transfer','promotions','promotion','checkout_quote','dashboard','report','report_export','health','roles','assignments','receipts','receipt','fiscal_years','credits','credit_allocations'];
if (!in_array($action,$reads,true)&&($_SERVER['REQUEST_METHOD']??'')!=='POST') deny_request(405,'Use POST for changes.');
foreach (array_merge($_GET,$_POST) as $key=>$value) if (!in_array($key,['lines','items','locations','widgets','permissions'],true)&&!is_scalar($value)) deny_request(400,'Invalid request value.');
try {
    $user=(int)$_settings->userdata('id');$id=(int)($_POST['id']??$_GET['id']??0);
    $workspace=new WorkspaceService($conn,$user);
    switch ($action) {
        case 'options':$data=$workspace->options();break;
        case 'transaction_options':
            $inventory=new InventoryService($conn,$user);$data=['locations'=>$inventory->locations(true),'packaging'=>$inventory->packaging(),'location_id'=>1];
            if ($id) {$s=$conn->prepare('SELECT location_id FROM erp_transaction_context WHERE transaction_id=?');$s->execute([$id]);$data['location_id']=(int)($s->get_result()->fetch_assoc()['location_id']??1);}
            if (!empty($_GET['order_id'])) {$s=$conn->prepare('SELECT location_id FROM erp_po_context WHERE order_id=?');$s->execute([(int)$_GET['order_id']]);$data['location_id']=(int)($s->get_result()->fetch_assoc()['location_id']??1);}
            break;
        case 'preferences':$data=$workspace->preferences();break;
        case 'save_preferences':$workspace->savePreferences($_POST);$data=$workspace->preferences();break;
        case 'activities':$data=$workspace->activities($_GET['from']??null,$_GET['to']??null);break;
        case 'activity':$data=$workspace->activity($id);break;
        case 'save_activity':$data=['id'=>$workspace->saveActivity($_POST)];break;
        case 'roles':$data=$workspace->roles();break;
        case 'assignments':$data=$workspace->assignments();break;
        case 'save_role':$data=['id'=>$workspace->saveRole($_POST)];break;
        case 'assign_role':$workspace->assignRole((int)($_POST['user_id']??0),empty($_POST['role_id'])?null:(int)$_POST['role_id']);$data=[];break;
        case 'interpret':$data=$workspace->interpret((string)($_POST['prompt']??''));break;
        case 'health':$data=$workspace->health();break;
        case 'credits':$data=(new App\Enterprise\CreditService($conn,$user))->credits();break;
        case 'credit_allocations':$data=(new App\Enterprise\CreditService($conn,$user))->allocations();break;
        case 'apply_credit':$data=['id'=>(new App\Enterprise\CreditService($conn,$user))->apply($_POST)];break;
        case 'reverse_credit':$data=['id'=>(new App\Enterprise\CreditService($conn,$user))->reverse($id,(string)($_POST['allocation_date']??''),(string)($_POST['reason']??''))];break;
        case 'fiscal_years':$data=(new App\Enterprise\FiscalService($conn,$user))->years();break;
        case 'save_fiscal_year':$data=['id'=>(new App\Enterprise\FiscalService($conn,$user))->create($_POST)];break;
        case 'close_fiscal_year':case 'reopen_fiscal_year':(new App\Enterprise\FiscalService($conn,$user))->status($id,$_POST['version']??'',$action==='close_fiscal_year',(string)($_POST['reason']??''));$data=[];break;
        case 'locations':$data=(new InventoryService($conn,$user))->locations();break;
        case 'save_location':$data=['id'=>(new InventoryService($conn,$user))->saveLocation($_POST)];break;
        case 'packaging':$data=(new InventoryService($conn,$user))->packaging();break;
        case 'save_packaging':(new InventoryService($conn,$user))->savePackaging($_POST);$data=[];break;
        case 'stock':$data=(new InventoryService($conn,$user))->stock(empty($_GET['location_id'])?null:(int)$_GET['location_id'],empty($_GET['item_id'])?null:(int)$_GET['item_id'],$_GET['to']??null);break;
        case 'transfers':$data=(new InventoryService($conn,$user))->transfers();break;
        case 'transfer':$data=(new InventoryService($conn,$user))->transfer($id);break;
        case 'save_transfer':$data=['id'=>(new InventoryService($conn,$user))->saveTransfer($_POST)];break;
        case 'reverse_transfer':$data=['id'=>(new InventoryService($conn,$user))->reverseTransfer($id,(string)($_POST['transfer_date']??''),(string)($_POST['reason']??''))];break;
        case 'promotions':$data=(new PromotionService($conn,$user))->promotions();break;
        case 'promotion':$data=(new PromotionService($conn,$user))->promotion($id);break;
        case 'save_promotion':$data=['id'=>(new PromotionService($conn,$user))->save($_POST)];break;
        case 'checkout_quote':
            $inventory=new InventoryService($conn,$user);$item=(int)($_GET['item_id']??0);$code=is_string($_GET['unit_code']??null)?$_GET['unit_code']:'base';
            $unit=$inventory->unit($item,$code);
            $prepared=(new LineService($conn,$user))->prepare(['item_id'=>[$item],'qty'=>[$_GET['quantity']??'1'],'price'=>[$unit['price']],
                'unit_code'=>[$code],'checkout_request_key'=>'quote','location_id'=>$_GET['location_id']??1],'pos');
            $data=array_merge($prepared['snapshots'][0],['quantity'=>$prepared['input']['qty'][0],'price'=>$prepared['snapshots'][0]['entered_price'],'amount'=>$prepared['totals'][0]]);break;
        case 'dashboard':$data=(new DashboardService($conn,$user))->data($_GET);break;
        case 'report':case 'report_export':
            $data=(new ReportService($conn,$user))->report((string)($_GET['report']??'sales_register'),$_GET);
            if ($action==='report_export') {
                header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$data['kind'].'_'.$data['to'].'.csv"');
                $output=fopen('php://output','w');fputcsv($output,$data['columns']);
                foreach ($data['rows'] as $row) fputcsv($output,array_map(static fn($value)=>!is_numeric($value)&&preg_match('/^\s*[=+@-]/u',(string)$value)?"'".$value:$value,array_values($row)));
                fclose($output);exit;
            }
            break;
        case 'receipts':case 'receipt':
            $statement=$conn->prepare($action==='receipt'?'SELECT p.*,u.username,l.name AS location FROM erp_pos_checkouts p LEFT JOIN users u ON u.id=p.cashier_id JOIN erp_locations l ON l.id=p.location_id WHERE p.request_key=?':'SELECT p.id,p.request_key,p.sale_id,p.amount,p.created_at,u.username,l.name AS location FROM erp_pos_checkouts p LEFT JOIN users u ON u.id=p.cashier_id JOIN erp_locations l ON l.id=p.location_id ORDER BY p.id DESC LIMIT 250');
            $statement->execute($action==='receipt'?[(string)($_GET['key']??'')]:[]);
            $data=$action==='receipt'?$statement->get_result()->fetch_assoc():$statement->get_result()->fetch_all(MYSQLI_ASSOC);
            if ($action==='receipt'&&!$data) throw new RuntimeException('Receipt not found.');
            break;
        default:deny_request(400,'Unknown workspace action.');
    }
    header('Content-Type: application/json; charset=utf-8');echo json_encode(['status'=>'success','data'=>$data],JSON_THROW_ON_ERROR);
} catch (DomainException $error) { deny_request(403,$error->getMessage()); }
catch (UnexpectedValueException $error) { deny_request(409,$error->getMessage()); }
catch (mysqli_sql_exception $error) {
    if ($error->getCode()===1062) deny_request(409,'A record with this code, barcode, or request key already exists.');
    if ($error->getCode()===1644) deny_request(422,$error->getMessage());
    error_log('Enterprise database error: '.$error->getMessage());deny_request(500,'The workspace operation could not be completed.');
} catch (InvalidArgumentException|RuntimeException $error) { deny_request(422,$error->getMessage()); }
catch (Throwable $error) { error_log('Enterprise error: '.$error->getMessage());deny_request(500,'The workspace operation could not be completed.'); }
