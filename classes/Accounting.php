<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/Accounting/JournalService.php';

use App\Accounting\JournalService;

$service=new JournalService($conn,(int)$_settings->userdata('id'));
$action=is_string($_GET['f']??null)?$_GET['f']:'';
$reads=['journal','report','export'];
foreach (array_merge($_GET,$_POST) as $key=>$value) {
    if ($key!=='lines' && !is_scalar($value)) deny_request(400,'Invalid accounting request value.');
}
if ($action==='save_draft' && !is_array($_POST['lines']??null)) deny_request(422,'Enter journal lines.');
if (!in_array($action,$reads,true)) {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') deny_request(405,'Use POST for accounting changes.');
    if ((int)$_settings->userdata('type')!==1) deny_request(403,'Administrator access required.');
}
try {
    $id=(int)($_POST['id']??($_GET['id']??0));
    switch ($action) {
        case 'save_draft': $data=['id'=>$service->saveDraft($_POST)]; break;
        case 'discard_draft': $service->deleteDraft($id); $data=[]; break;
        case 'post_journal': $data=['id'=>$service->postJournal($id)]; break;
        case 'preview_source': $data=$service->previewSource($id); break;
        case 'post_source': $data=['id'=>$service->postSource($id)]; break;
        case 'reverse_journal': $data=['id'=>$service->reverseJournal($id,(string)($_POST['entry_date']??''),(string)($_POST['reason']??''))]; break;
        case 'create_account': $data=['id'=>$service->createAccount($_POST)]; break;
        case 'create_period': $data=['id'=>$service->createPeriod((string)($_POST['name']??''),(string)($_POST['start_date']??''),(string)($_POST['end_date']??''))]; break;
        case 'close_period': case 'reopen_period': $service->setPeriodStatus($id,$action==='close_period',(string)($_POST['reason']??'')); $data=[]; break;
        case 'journal': $data=$service->journal($id); break;
        case 'report': case 'export':
            $data=$service->report((string)($_GET['report']??'trial_balance'),(string)($_GET['from']??date('Y-m-01')),(string)($_GET['to']??date('Y-m-d')));
            if ($action==='export') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="'.$data['kind'].'_'.$data['to'].'.csv"');
                $output=fopen('php://output','w');
                fputcsv($output,['Account code','Account name','Category','Debit (NPR)','Credit (NPR)']);
                foreach ($data['rows'] as $row) {
                    $safe=static fn($value)=>preg_match('/^[=+@-]/',$value)?"'".$value:$value;
                    fputcsv($output,[$safe($row['code']),$safe($row['name']),$row['category'],$row['debit'],$row['credit']]);
                }
                fputcsv($output,['','Total','',$data['debit'],$data['credit']]);
                fclose($output); exit;
            }
            break;
        default: deny_request(400,'Unknown accounting action.');
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>'success','data'=>$data],JSON_THROW_ON_ERROR);
} catch (mysqli_sql_exception $error) {
    if ($error->getCode()===1062) deny_request(409,'This account, request, or document has already been recorded.');
    if ($error->getCode()===1644) deny_request(422,$error->getMessage());
    error_log('Accounting database error: '.$error->getMessage());
    deny_request(500,'The accounting operation could not be completed.');
} catch (InvalidArgumentException|RuntimeException $error) {
    deny_request(422,$error->getMessage());
} catch (Throwable $error) {
    error_log('Accounting error: '.$error->getMessage());
    deny_request(500,'The accounting operation could not be completed.');
}
