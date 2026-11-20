<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../classes/DBConnection.php';
require_once __DIR__.'/../classes/Enterprise/FeatureService.php';
if (!str_ends_with(DB_NAME, '_test')) exit("Use a disposable database ending in _test.\n");
$connection=new DBConnection();$db=$connection->conn;$checks=0;
function retailCheck($value,$message){global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
$admin=(int)$db->query('SELECT id FROM users WHERE type=1 ORDER BY id LIMIT 1')->fetch_assoc()['id'];
$service=new App\Enterprise\FeatureService($db,$admin);
$assignments=$db->query('SELECT * FROM erp_user_roles ORDER BY user_id')->fetch_all(MYSQLI_ASSOC);
$result=$service->createRetailRoles();
retailCheck(count($result['created'])+count($result['skipped'])===5,'All five roles are accounted for');
retailCheck($assignments===$db->query('SELECT * FROM erp_user_roles ORDER BY user_id')->fetch_all(MYSQLI_ASSOC),'No users assigned or changed');
$roles=[];foreach($service->state()['roles'] as $role)$roles[$role['name']]=$role;
foreach(App\Enterprise\RetailRoleTemplates::all() as $name=>$template){
    retailCheck(isset($roles[$name]),$name.' exists');
    $expected=App\Enterprise\RetailRoleTemplates::permissions($template);
    $actual=$service->role((int)$roles[$name]['id'])['permissions'];
    foreach($expected as $feature=>$actions)foreach($actions as $action=>$allowed)retailCheck(!empty($actual[$feature][$action]),$name.' allows '.$feature.':'.$action);
}
$cashier=$service->role((int)$roles['Retail Cashier']['id'])['permissions'];
retailCheck(empty($cashier['accounting.journals']['post']),'Cashier cannot post journals in the template');
retailCheck(empty($cashier['purchasing.orders.approve']['approve']),'Cashier cannot approve purchases in the template');
retailCheck(empty($cashier['sales.invoices']['delete']),'Cashier cannot delete sales in the template');
$manager=$service->role((int)$roles['Retail Store Manager']['id'])['permissions'];
retailCheck(!empty($manager['purchasing.orders.approve']['approve']),'Manager may approve purchase orders in the template');
retailCheck(!empty($manager['payments.cash_counts']['finalize']),'Manager may finalize cash counts in the template');
$before=$db->query('SELECT * FROM erp_access_roles ORDER BY id')->fetch_all(MYSQLI_ASSOC);
$again=$service->createRetailRoles();
retailCheck(!$again['created']&&count($again['skipped'])===5,'Repeated creation skips existing names');
retailCheck($before===$db->query('SELECT * FROM erp_access_roles ORDER BY id')->fetch_all(MYSQLI_ASSOC),'Repeated creation never overwrites roles');
echo "Passed $checks retail role checks.\n";
