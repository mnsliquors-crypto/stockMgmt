<?php
namespace App\Enterprise;

final class Access {
    public static function allowed(\mysqli $db, int $user, string $scope, bool $write=false): bool {
        $statement=$db->prepare('SELECT u.type,r.active,r.permissions,ur.role_id FROM users u LEFT JOIN erp_user_roles ur ON ur.user_id=u.id LEFT JOIN erp_access_roles r ON r.id=ur.role_id WHERE u.id=?');
        $statement->execute([$user]);$row=$statement->get_result()->fetch_assoc();
        if (!$row) return false;
        if ((int)$row['type']===1) return true;
        if (!$row['role_id']) return (int)$row['type']===2;
        if (!(int)$row['active']) return false;
        $permissions=json_decode($row['permissions'],true);
        $level=$permissions[$scope]??'none';
        return $write?$level==='write':in_array($level,['read','write'],true);
    }

    public const PAGE_SCOPES=['home'=>'dashboard','legacy_dashboard'=>'dashboard','pos'=>'sales','sales'=>'sales','purchases'=>'purchases','payments'=>'payments','expenses'=>'expenses','returns'=>'sales',
            'reports/'=>'reports','finance/accounting'=>'accounting','finance/cash_counts'=>'payments',
            'transactions/procurement'=>'procurement','transactions/sales'=>'sales','transactions/purchases'=>'purchases','transactions/payments'=>'payments',
            'transactions/expenses'=>'expenses','transactions/returns'=>'sales','transactions/stock_adjustments'=>'inventory','transactions/opening_balances'=>'accounting',
            'master/items'=>'inventory','master/stocks'=>'inventory','master/categories'=>'inventory','master/customers'=>'sales','master/vendors'=>'purchases','master/accounts'=>'payments','master/bank_accounts'=>'payments',
            'enterprise/credits'=>'payments','enterprise/dashboard'=>'dashboard','enterprise/receipts'=>'sales','enterprise/inventory'=>'inventory','enterprise/promotions'=>'promotions','enterprise/activities'=>'activities','enterprise/reports'=>'reports','enterprise/assistant'=>'assistant'];

    public static function pageScope(string $page): ?string {
        foreach (self::PAGE_SCOPES as $prefix=>$scope) if ($page===$prefix||str_starts_with($page,rtrim($prefix,'/').'/')) return $scope;
        return null;
    }

    public static function landingPage(\mysqli $db,int $user): string {
        foreach(['dashboard'=>'home','inventory'=>'enterprise/inventory','sales'=>'sales','purchases'=>'purchases','payments'=>'payments','expenses'=>'expenses','procurement'=>'transactions/procurement','accounting'=>'finance/accounting','promotions'=>'enterprise/promotions','activities'=>'enterprise/activities','reports'=>'enterprise/reports','assistant'=>'enterprise/assistant'] as $scope=>$page) if(self::allowed($db,$user,$scope))return $page;
        return 'system/users/profile';
    }

    public static function enforce(\mysqli $db): void {
        if (PHP_SAPI==='cli'||empty($_SESSION['userdata']['id'])||!$db->query("SHOW TABLES LIKE 'erp_user_roles'")->num_rows) return;
        $user=(int)$_SESSION['userdata']['id'];
        $statement=$db->prepare('SELECT type FROM users WHERE id=?');$statement->execute([$user]);$actual=$statement->get_result()->fetch_assoc();
        if (!$actual||!in_array((int)$actual['type'],[1,2],true)) deny_request(401,'Please sign in with an active account.');
        $_SESSION['userdata']['type']=(int)$actual['type'];
        $script=str_replace('\\','/',$_SERVER['SCRIPT_FILENAME']??'');
        $relative=str_starts_with($script,base_app)?substr($script,strlen(base_app)):$script;
        $requestedPage=is_string($_GET['page']??null)?$_GET['page']:'';
        $profile=$requestedPage==='system/users/profile'||$relative==='admin/modules/system/users/profile.php';
        $adminOnly=in_array($relative,['classes/GitSync.php','classes/SystemSettings.php'],true)||str_starts_with($relative,'admin/modules/system/')
            ||($relative==='admin/index.php'&&str_starts_with($requestedPage,'system/'));
        if ($adminOnly&&!$profile&&(int)$actual['type']!==1) deny_request(403,'Administrator access required.');
        $scope=null;$write=false;
        if ($relative==='admin/index.php') {
            if(!isset($_GET['page'])&&!self::allowed($db,$user,'dashboard')) {
                header('Location: '.base_url.'admin/?page='.self::landingPage($db,$user));exit;
            }
            $page=is_string($_GET['page']??null)?$_GET['page']:'home';
            $scope=self::pageScope($page);
        } elseif ($relative==='classes/Enterprise.php') {
            $action=is_string($_GET['f']??null)?$_GET['f']:'';
            $scope=match (true) {
                str_contains($action,'activity')||$action==='activities'=>'activities',
                str_contains($action,'promotion')=>'promotions',
                in_array($action,['quote','checkout_quote','receipts','receipt'],true)=>'sales',
                in_array($action,['credits','credit_allocations','apply_credit','reverse_credit'],true)=>'payments',
                str_contains($action,'report')=>'reports',
                str_contains($action,'preferences')||$action==='dashboard'=>'dashboard',
                $action==='interpret'=>'assistant',
                default=>'inventory',
            };
            $write=($action!=='save_preferences'&&str_starts_with($action,'save_'))||str_starts_with($action,'reverse_')||in_array($action,['assign_role','apply_credit','close_fiscal_year','reopen_fiscal_year'],true);
            if ($action==='transaction_options') {
                foreach (['inventory','sales','purchases','procurement'] as $candidate) if (self::allowed($db,$user,$candidate)) {$scope=null;break;}
            }
        } elseif ($relative==='classes/Procurement.php') {
            $scope='procurement';$write=!in_array($_GET['f']??'',['order','replenishment','export'],true);
        } elseif ($relative==='classes/Accounting.php') {
            $scope='accounting';$write=!in_array($_GET['f']??'',['journal','report','export'],true);
        } elseif ($relative==='classes/Master.php') {
            $action=is_string($_GET['f']??null)?$_GET['f']:'';
            $scope=match (true) {
                str_contains($action,'purchase')||$action==='delete_po'||str_contains($action,'vendor')=>'purchases',
                str_contains($action,'payment')||$action==='get_outstanding_bills'||str_contains($action,'account')||str_contains($action,'denomination')||str_contains($action,'reconciliation')||str_contains($action,'transfer')=>'payments',
                str_contains($action,'expense')=>'expenses',
                str_contains($action,'sale')||str_contains($action,'pos')||str_contains($action,'customer')||str_contains($action,'return')=>'sales',
                default=>'inventory',
            };
            $write=!str_starts_with($action,'get_')&&!str_starts_with($action,'search_')&&!str_starts_with($action,'check_');
            if ($action==='search_parties') {
                foreach (['sales','purchases','payments'] as $candidate) if (self::allowed($db,$user,$candidate)) {$scope=null;break;}
            }
        } elseif (str_starts_with($relative,'admin/reports/')) $scope='reports';
        elseif (str_contains($relative,'/modules/')) {
            $module=explode('/modules/',$relative,2)[1];
            $scope=self::pageScope(preg_replace('/\.php$/','',$module));
        } elseif (str_contains($relative,'global_search.php')) {
            foreach (['inventory','sales','purchases','payments','reports'] as $required) if (!self::allowed($db,$user,$required)) deny_request(403,'Your role does not allow global record search.');
        } elseif ($relative==='admin/ajax/export_data.php') $scope='reports';
        if ($scope!==null&&!self::allowed($db,$user,$scope,$write)) deny_request(403,'Your role does not allow this '.($write?'change':'view').'.');
    }
}
