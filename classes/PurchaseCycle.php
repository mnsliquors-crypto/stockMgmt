<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/Procurement/CycleService.php';
if(empty($purchase_cycle_available))deny_request(503,'Purchase cycle setup is pending.');
$action=is_string($_GET['f']??null)?$_GET['f']:'';
foreach(array_merge($_GET,$_POST)as $key=>$value)if($key!=='lines'&&!is_scalar($value))deny_request(400,'Invalid purchase cycle request.');
if(!in_array($action,['receipt','receipts','bill','bills','billable'],true)&&($_SERVER['REQUEST_METHOD']??'')!=='POST')deny_request(405,'Use POST for purchase cycle changes.');
try {
    $service=new App\Procurement\CycleService($conn,(int)$_settings->userdata('id'));
    $id=(int)($_POST['id']??$_GET['id']??0);
    $data=match($action){
        'receive_items'=>['id'=>$service->receive($_POST)],
        'reverse_receipt'=>['id'=>$service->reverseReceipt($id,(string)($_POST['date']??''),(string)($_POST['reason']??''))],
        'save_bill'=>['id'=>$service->saveBill($_POST)],
        'record_bill'=>['transaction_id'=>$service->recordBill($id,$_POST['version']??'')],
        'cancel_bill'=>$service->cancelBill($id,$_POST['version']??'',(string)($_POST['reason']??'')),
        'pay_bill'=>['id'=>$service->payBill($_POST)],
        'receipt'=>$service->receipt($id), 'receipts'=>$service->receipts(),
        'bill'=>$service->bill($id), 'bills'=>$service->bills(),
        'billable'=>$service->billable((int)($_GET['order_id']??0)),
        default=>throw new InvalidArgumentException('Unknown purchase cycle action.')
    };
    header('Content-Type: application/json; charset=utf-8');echo json_encode(['status'=>'success','data'=>$data],JSON_THROW_ON_ERROR);
}catch(DomainException $error){deny_request(403,$error->getMessage());
}catch(UnexpectedValueException $error){deny_request(409,$error->getMessage());
}catch(mysqli_sql_exception $error){
    if($error->getCode()===1062)deny_request(409,'This invoice or request has already been recorded.');
    if($error->getCode()===1644)deny_request(422,$error->getMessage());
    error_log('Purchase cycle: '.$error->getMessage());deny_request(500,'The purchase cycle operation could not be completed.');
}catch(InvalidArgumentException|RuntimeException $error){deny_request(422,$error->getMessage());
}catch(Throwable $error){error_log('Purchase cycle: '.$error->getMessage());deny_request(500,'The purchase cycle operation could not be completed.');}
