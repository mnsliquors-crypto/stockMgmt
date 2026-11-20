<?php
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/../classes/DBConnection.php';
require_once __DIR__.'/../classes/Enterprise/CompanyPolicy.php';
require_once __DIR__.'/../database/migrations/008_shared_companies.php';
if(!str_ends_with(DB_NAME,'_test'))exit("Use a disposable database ending in _test.\n");
$connection=new DBConnection();$db=$connection->conn;migrate_shared_companies($db);$checks=0;
function companyCheck(bool $ok,string $message):void {global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function companyExec(mysqli $db,string $sql,array $values=[]):mysqli_result|bool {$s=$db->prepare($sql);$s->execute($values);return $s->get_result();}
$tag=bin2hex(random_bytes(6));$db->begin_transaction();
try {
    $type=(int)$db->query("SELECT id FROM platform_company_types WHERE code='retailer'")->fetch_assoc()['id'];
    $companies=[];foreach(['a','b'] as $suffix){companyExec($db,'INSERT INTO platform_companies(company_type_id,code,name,address) VALUES(?,?,?,?)',[$type,'test_'.$suffix.'_'.$tag,'Retail '.$suffix,'Test']);$companies[]=(int)$db->insert_id;}
    $roles=[];foreach($companies as $company){companyExec($db,'INSERT INTO platform_roles(company_id,name) VALUES(?,?)',[$company,'Sales']);$roles[]=(int)$db->insert_id;}
    $users=[];foreach(['admina','usera','adminb'] as $suffix){companyExec($db,"INSERT INTO users(firstname,lastname,username,password,type) VALUES('Policy','Test',?,?,2)",['policy_'.$suffix.'_'.$tag,password_hash($tag,PASSWORD_DEFAULT)]);$users[]=(int)$db->insert_id;}
    companyExec($db,'INSERT INTO platform_memberships(user_id,company_id,is_company_admin) VALUES(?,?,1)',[$users[0],$companies[0]]);
    companyExec($db,'INSERT INTO platform_memberships(user_id,company_id,role_id) VALUES(?,?,?)',[$users[1],$companies[0],$roles[0]]);
    companyExec($db,'INSERT INTO platform_memberships(user_id,company_id,is_company_admin) VALUES(?,?,1)',[$users[2],$companies[1]]);
    foreach(['view','create'] as $action){companyExec($db,'INSERT INTO platform_company_permissions(company_id,feature_key,action,allowed) VALUES(?,?,?,1)',[$companies[0],'sales.invoices',$action]);companyExec($db,'INSERT INTO platform_role_permissions(role_id,feature_key,action,allowed) VALUES(?,?,?,1)',[$roles[0],'sales.invoices',$action]);}
    $admin=new App\Enterprise\CompanyPolicy($db,$users[0]);$user=new App\Enterprise\CompanyPolicy($db,$users[1]);$other=new App\Enterprise\CompanyPolicy($db,$users[2]);
    $superId=(int)$db->query("SELECT id FROM users WHERE username='admin' AND type=1")->fetch_assoc()['id'];$super=new App\Enterprise\CompanyPolicy($db,$superId);
    companyCheck($super->can('sales.invoices','delete')&&$super->canAccessCompany($companies[1]),'Super Admin policy');
    companyCheck($admin->can('sales.invoices','create')&&!$admin->canAccessCompany($companies[1]),'Company admin is limited to own company');
    companyCheck($user->can('sales.invoices','create')&&!$user->can('sales.invoices','delete'),'Role allows only granted actions');
    companyCheck(!$other->can('sales.invoices','view')&&!$other->canAccessCompany($companies[0]),'Missing company override denies access');
    companyCheck($user->can('app.profile','edit')&&$user->can('app.logout','sign_out'),'Profile and logout remain accessible');
    companyExec($db,'INSERT INTO platform_user_blocks(user_id,company_id,feature_key,action) VALUES(?,?,?,?)',[$users[1],$companies[0],'sales.invoices','create']);
    companyCheck(!(new App\Enterprise\CompanyPolicy($db,$users[1]))->can('sales.invoices','create'),'User-level block takes precedence');
    companyExec($db,'UPDATE platform_company_permissions SET allowed=0 WHERE company_id=? AND feature_key=? AND action=?',[$companies[0],'sales.invoices','view']);
    companyCheck(!(new App\Enterprise\CompanyPolicy($db,$users[0]))->can('sales.invoices','view'),'Company-level denial takes precedence');
    companyExec($db,'UPDATE platform_company_permissions SET allowed=1 WHERE company_id=? AND feature_key=? AND action=?',[$companies[0],'sales.invoices','view']);
    companyExec($db,'UPDATE platform_type_permissions SET allowed=0 WHERE company_type_id=? AND feature_key=? AND action=?',[$type,'sales.invoices','view']);
    companyCheck(!(new App\Enterprise\CompanyPolicy($db,$users[0]))->can('sales.invoices','view'),'Type template limits company admin');
    try {companyExec($db,'UPDATE platform_memberships SET role_id=? WHERE user_id=?',[$roles[1],$users[1]]);throw new RuntimeException('Cross-company role assignment was accepted');} catch(mysqli_sql_exception $expected){companyCheck(true,'Cross-company role rejected');}
    companyExec($db,"UPDATE platform_companies SET status='blocked' WHERE id=?",[$companies[0]]);
    companyCheck(!(new App\Enterprise\CompanyPolicy($db,$users[0]))->can('sales.invoices','view'),'Blocked company is denied');
    echo "Passed $checks draft company-policy checks.\n";
} finally {$db->rollback();}
