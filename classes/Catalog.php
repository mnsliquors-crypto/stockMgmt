<?php
require_once __DIR__.'/../config.php';
if(empty($item_catalog_available))deny_request(503,'Item master setup is pending.');
$action=is_string($_GET['f']??null)?$_GET['f']:'';
foreach(array_merge($_GET,$_POST)as $key=>$value)if($key!=='axes'&&!is_scalar($value))deny_request(400,'Invalid catalog request.');
if(!in_array($action,['items','item','lookups','settings','usages'],true)&&($_SERVER['REQUEST_METHOD']??'')!=='POST')deny_request(405,'Use POST for catalog changes.');
try{
    $service=new App\Enterprise\CatalogService($conn,(int)$_settings->userdata('id'));
    $data=match($action){
        'items'=>$service->items($_GET),'item'=>$service->item((int)($_GET['id']??0)),
        'lookups'=>$service->lookups((string)($_GET['kind']??'')),'settings'=>$service->settings(),
        'usages'=>$service->usages((string)($_GET['record_type']??'')),'save_usage'=>$service->saveUsage($_POST),
        'save_item'=>['id'=>$service->saveItem($_POST)],'save_lookup'=>['id'=>$service->saveLookup($_POST)],
        'configure'=>$service->configure($_POST),'generate_variants'=>$service->generate($_POST),
        default=>throw new InvalidArgumentException('Unknown catalog action.')
    };
    header('Content-Type: application/json; charset=utf-8');echo json_encode(['status'=>'success','data'=>$data],JSON_THROW_ON_ERROR);
}catch(DomainException $e){deny_request(403,$e->getMessage());
}catch(UnexpectedValueException $e){deny_request(409,$e->getMessage());
}catch(mysqli_sql_exception $e){
    if($e->getCode()===1062)deny_request(409,'This SKU, master code, or matrix combination already exists.');
    if($e->getCode()===1644)deny_request(422,$e->getMessage());error_log('Catalog: '.$e->getMessage());deny_request(500,'The catalog operation could not be completed.');
}catch(InvalidArgumentException|RuntimeException $e){deny_request(422,$e->getMessage());
}catch(Throwable $e){error_log('Catalog: '.$e->getMessage());deny_request(500,'The catalog operation could not be completed.');}
