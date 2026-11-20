<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../classes/DBConnection.php';
require_once __DIR__.'/../classes/Procurement/CycleService.php';
if(!str_ends_with(DB_NAME,'_test'))exit("Use a disposable test database.\n");
try{
    $input=json_decode($argv[1]??'',true,512,JSON_THROW_ON_ERROR);
    $connection=new DBConnection();$service=new App\Procurement\CycleService($connection->conn,1);
    echo json_encode(['id'=>$service->payBill($input)],JSON_THROW_ON_ERROR);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage());exit(1);}
