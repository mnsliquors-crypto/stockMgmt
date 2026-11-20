<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__.'/../classes/DBConnection.php';
require_once __DIR__.'/../classes/Enterprise/FeatureService.php';
if (!str_ends_with(DB_NAME, '_test')) exit("Use a disposable database ending in _test.\n");

$connection = new DBConnection();
$db = $connection->conn;
$checks = 0;
function featureCheck(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function featureFails(callable $work, string $message): void {
    try { $work(); } catch (Throwable $error) {
        featureCheck(str_contains(strtolower($error->getMessage()), strtolower($message)), 'Unexpected error: '.$error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: '.$message);
}
function featureRows(mysqli $db, string $table): string {
    $rows = $db->query('SELECT * FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC);
    $serialized = array_map(fn($row) => json_encode($row, JSON_THROW_ON_ERROR), $rows);
    sort($serialized);
    return hash('sha256', implode("\n", $serialized));
}

$admin = (int)$db->query('SELECT id FROM users WHERE type=1 ORDER BY id LIMIT 1')->fetch_assoc()['id'];
$name = 'feature_test_'.bin2hex(random_bytes(6));
$statement = $db->prepare("INSERT INTO users(firstname,lastname,username,password,type) VALUES('Feature','Test',?,?,2)");
$statement->execute([$name, password_hash($name, PASSWORD_DEFAULT)]);
$staff = (int)$db->insert_id;
$service = new App\Enterprise\FeatureService($db, $admin);
$state = $service->state();
$role = (int)$state['default_role_id'];
$before = [];
foreach (['transactions','transaction_items','item_list','entity_list'] as $table) $before[$table] = featureRows($db, $table);

$staffPolicy = new App\Enterprise\FeaturePolicy($db, $staff);
featureCheck($staffPolicy->can('app.login', 'sign_in'), 'Staff can sign in');
featureCheck($staffPolicy->can('app.logout', 'sign_out'), 'Staff can sign out');
featureCheck($staffPolicy->can('app.profile', 'edit'), 'Staff can edit their profile');
featureCheck(!$staffPolicy->can('admin.features', 'view'), 'Staff cannot administer feature controls');
featureCheck($staffPolicy->can('sales.invoices', 'view'), 'Unassigned staff retain existing sales access');
featureFails(fn() => $service->saveFlags(['version'=>$state['version'], 'changes'=>['app.profile'=>'0']]), 'protected');
featureFails(fn() => (new App\Enterprise\FeatureService($db, $staff))->state(), 'administrator');

$service->saveFlags(['version'=>$state['version'], 'changes'=>['sales.invoices'=>'0']]);
$disabled = new App\Enterprise\FeaturePolicy($db, $staff);
featureCheck(!$disabled->can('sales.invoices', 'view'), 'Disabled feature is denied by policy');
featureCheck($disabled->can('app.profile', 'edit') && $disabled->can('app.logout', 'sign_out'), 'Account access remains available');
featureFails(fn() => $service->saveFlags(['version'=>$state['version'], 'changes'=>['sales.invoices'=>'1']]), 'changed');
foreach ($before as $table=>$hash) featureCheck(featureRows($db, $table)===$hash, $table.' unchanged while disabled');
$state = $service->state();
$service->saveFlags(['version'=>$state['version'], 'changes'=>['sales.invoices'=>'1']]);
featureCheck((new App\Enterprise\FeaturePolicy($db, $staff))->can('sales.invoices', 'view'), 'Re-enabled feature works again');
foreach ($before as $table=>$hash) featureCheck(featureRows($db, $table)===$hash, $table.' unchanged after re-enabling');

$roleState = $service->role($role);
$change = ['feature'=>'sales.invoices', 'action'=>'view', 'allowed'=>'0'];
$service->saveRolePermissions(['role_id'=>$role, 'version'=>$roleState['role']['version'], 'changes'=>[$change]]);
featureCheck(!(new App\Enterprise\FeaturePolicy($db, $staff))->can('sales.invoices', 'view'), 'Explicit role denial applies');
featureFails(fn() => $service->saveRolePermissions(['role_id'=>$role, 'version'=>$roleState['role']['version'], 'changes'=>[$change]]), 'changed');
$roleState = $service->role($role);
$change['allowed'] = '1';
$service->saveRolePermissions(['role_id'=>$role, 'version'=>$roleState['role']['version'], 'changes'=>[$change]]);
featureCheck((new App\Enterprise\FeaturePolicy($db, $staff))->can('sales.invoices', 'view'), 'Role permission can be restored');
featureCheck((int)$db->query("SELECT COUNT(*) n FROM erp_audit_events WHERE action IN ('change_feature_flags','change_feature_permissions')")->fetch_assoc()['n'] >= 4, 'Feature and role changes are audited');
echo "Passed $checks feature-control checks.\n";
