<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../classes/DBConnection.php';
if(!str_ends_with(DB_NAME,'_test'))exit("Use a disposable database ending in _test.\n");
$connection=new DBConnection();$conn=$connection->conn;$checks=0;$base=rtrim(getenv('APP_BASE_URL')?:'http://127.0.0.1:8081/','/').'/';
if(parse_url($base,PHP_URL_PORT)!==8081)exit("Use the disposable app on port 8081.\n");
function chq($sql,$v=[]){global $conn;$s=$conn->prepare($sql);$s->execute($v);return $s->get_result();}
function chrq($c,$path,$status=200,$data=null,$token=null){global $base,$checks;curl_setopt_array($c,[CURLOPT_URL=>$base.$path,CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$token?['X-CSRF-Token: '.$token]:[]]);if($data===null)curl_setopt($c,CURLOPT_HTTPGET,true);else{curl_setopt($c,CURLOPT_POST,true);curl_setopt($c,CURLOPT_POSTFIELDS,http_build_query($data));}$body=curl_exec($c);$got=curl_getinfo($c,CURLINFO_HTTP_CODE);if($got!==$status||preg_match('/Fatal error|Uncaught |<b>Warning<\/b>/',$body?:''))throw new RuntimeException($path.' expected '.$status.', got '.$got.': '.substr(strip_tags($body?:curl_error($c)),-1500));$checks++;return $body;}
function chlogin($name,$password){$c=curl_init();$html=chrq($c,'admin/login.php');preg_match('/name="csrf-token" content="([a-f0-9]+)"/',$html,$m);$r=json_decode(chrq($c,'classes/Login.php?f=login',200,['username'=>$name,'password'=>$password],$m[1]),true);if(($r['status']??'')!=='success')throw new RuntimeException('Login failed');return[$c,$m[1]];}
$anon=curl_init();chrq($anon,'classes/Catalog.php?f=items',401);$admin=chlogin('admin','admin123');
foreach(['save_item','save_lookup','configure','generate_variants']as $action){chrq($admin[0],'classes/Catalog.php?f='.$action,405);chrq($admin[0],'classes/Catalog.php?f='.$action,403,[]);}
chrq($admin[0],'classes/Catalog.php?f=items&q[]=bad',400);chrq($admin[0],'admin/?page=enterprise/catalog&q[]=bad',400);
foreach(['items','units','bins','class','department','features']as $view)chrq($admin[0],'admin/?page=enterprise/catalog&view='.$view);
chrq($admin[0],'admin/?page=enterprise/catalog&new=1');
$item=chq("SELECT id FROM item_list WHERE is_matrix_parent=1 LIMIT 1")->fetch_assoc();if($item)chrq($admin[0],'admin/?page=enterprise/catalog&id='.$item['id']);
$tag=bin2hex(random_bytes(5));$payload=['name'=>'HTTP Service '.$tag,'sku'=>'HTTP-'.$tag,'item_type'=>'service','item_usage'=>'resale','status'=>'1','tax_type'=>'1','cost'=>'10','selling_price'=>'20'];
$response=json_decode(chrq($admin[0],'classes/Catalog.php?f=save_item',200,$payload,$admin[1]),true);$id=$response['data']['id'];
chrq($admin[0],'classes/Catalog.php?f=item&id='.$id);chrq($admin[0],'admin/?page=enterprise/catalog&id='.$id);
chrq($admin[0],'classes/Catalog.php?f=save_item',409,$payload,$admin[1]);
chrq($admin[0],'classes/Catalog.php?f=generate_variants',422,['id'=>$id,'catalog_version'=>'1','axes'=>'bad'],$admin[1]);
$result=json_decode(chrq($admin[0],'classes/Master.php?f=search_pos_items&q='.$payload['sku']),true);if(!in_array($id,array_column($result['results']??[],'id')))throw new RuntimeException('POS SKU search did not return the new service');$checks++;
$csv=chrq($admin[0],'admin/reports/report_api.php?action=export_excel&report=stock_current');if(str_contains($csv,$payload['name']))throw new RuntimeException('Stock export included a service item');$checks++;
$used=chq('SELECT i.* FROM item_list i WHERE EXISTS(SELECT 1 FROM transaction_items l WHERE l.item_id=i.id) LIMIT 1')->fetch_assoc();
if($used){$used['unit']='Changed-Unit';$result=json_decode(chrq($admin[0],'classes/Master.php?f=save_item',200,$used,$admin[1]),true);if(($result['status']??'')!=='failed'||!str_contains($result['msg']??'','Base unit'))throw new RuntimeException('Basic item editor did not safely reject a used base-unit change');$checks++;}
foreach(['units','bins','class','department']as $kind)chrq($admin[0],'classes/Catalog.php?f=lookups&kind='.$kind);
foreach(['account','unit','bin','customer','vendor','location','class','department']as $kind){
    $rows=json_decode(chrq($admin[0],'classes/Catalog.php?f=usages&record_type='.$kind),true)['data'];
    chrq($admin[0],'admin/?page=enterprise/catalog&view=usage&record_type='.$kind);
    if(!$rows)throw new RuntimeException('Missing master usage fixture '.$kind);$row=$rows[0];
    $request=['record_type'=>$kind,'record_id'=>$row['id'],'item_usage'=>'purchase','version'=>$row['version']];
    chrq($admin[0],'classes/Catalog.php?f=save_usage',200,$request,$admin[1]);
    chrq($admin[0],'classes/Catalog.php?f=save_usage',409,$request,$admin[1]);
    chrq($admin[0],'classes/Catalog.php?f=save_usage',200,array_replace($request,['item_usage'=>$row['item_usage'],'version'=>(int)$row['version']+1]),$admin[1]);
}
chq("INSERT INTO users(firstname,lastname,username,password,type) VALUES('Catalog','Reader',?,?,2)",['catalog_reader_'.$tag,password_hash('CatalogReader123!',PASSWORD_DEFAULT)]);$user=(int)$conn->insert_id;
chq("INSERT INTO erp_access_roles(name,description,permissions,active) VALUES(?,'Catalog HTTP fixture',?,1)",['Catalog Reader '.$tag,json_encode(['inventory'=>'read'])]);$role=(int)$conn->insert_id;chq('INSERT INTO erp_user_roles(user_id,role_id) VALUES(?,?)',[$user,$role]);$reader=chlogin('catalog_reader_'.$tag,'CatalogReader123!');
chrq($reader[0],'classes/Catalog.php?f=items');chrq($reader[0],'admin/?page=enterprise/catalog');
chrq($reader[0],'admin/?page=enterprise/catalog&view=usage&record_type=unit');
chrq($reader[0],'classes/Catalog.php?f=save_usage',403,['record_type'=>'unit','record_id'=>'1','item_usage'=>'purchase','version'=>'0'],$reader[1]);
foreach(['save_item','save_lookup','configure','generate_variants']as $action)chrq($reader[0],'classes/Catalog.php?f='.$action,403,[],$reader[1]);
chq('UPDATE erp_access_roles SET permissions=? WHERE id=?',[json_encode(['sales'=>'read']),$role]);chrq($reader[0],'classes/Catalog.php?f=items',403);chrq($reader[0],'admin/?page=enterprise/catalog',403);
chrq($reader[0],'admin/?page=enterprise/catalog&view=usage&record_type=customer');chrq($reader[0],'classes/Catalog.php?f=usages&record_type=account',403);
echo "Passed $checks catalog HTTP checks.\n";
