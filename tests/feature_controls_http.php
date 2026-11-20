<?php
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__.'/../classes/DBConnection.php';
if (!str_ends_with(DB_NAME, '_test')) exit("Use a disposable database ending in _test.\n");
$base = rtrim(getenv('APP_BASE_URL') ?: 'http://127.0.0.1:8081/', '/').'/';
if (parse_url($base, PHP_URL_PORT) !== 8081) exit("Use the disposable server on port 8081.\n");
$connection = new DBConnection(); $db = $connection->conn; $checks = 0;
function fhr($client, $path, $status, $data=null, $token=null) {
    global $base, $checks;
    curl_setopt_array($client, [CURLOPT_URL=>$base.$path, CURLOPT_RETURNTRANSFER=>true, CURLOPT_COOKIEFILE=>'', CURLOPT_TIMEOUT=>20, CURLOPT_HTTPHEADER=>$token ? ['X-CSRF-Token: '.$token] : []]);
    if ($data === null) curl_setopt($client, CURLOPT_HTTPGET, true);
    else { curl_setopt($client, CURLOPT_POST, true); curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $body = curl_exec($client); $got = curl_getinfo($client, CURLINFO_HTTP_CODE);
    if ($got !== $status || preg_match('/Fatal error|Uncaught |<b>Warning<\/b>/', $body ?: '')) throw new RuntimeException("$path expected $status, got $got: ".substr(strip_tags($body ?: curl_error($client)), -1000));
    $checks++; return $body;
}
function fhlogin($username, $password) {
    $client = curl_init(); $html = fhr($client, 'admin/login.php', 200);
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $html, $match);
    $result = json_decode(fhr($client, 'classes/Login.php?f=login', 200, ['username'=>$username,'password'=>$password], $match[1]), true);
    if (($result['status'] ?? '') !== 'success') throw new RuntimeException('Login failed.');
    return [$client, $match[1]];
}
$tag = bin2hex(random_bytes(6)); $password = 'PreviewTest123!';
$statement = $db->prepare('INSERT INTO users(firstname,lastname,username,password,type) VALUES(?,?,?,?,?)');
foreach ([1,2] as $type) $statement->execute(['Preview', 'Test', 'preview_'.$type.'_'.$tag, password_hash($password, PASSWORD_DEFAULT), $type]);
fhr(curl_init(), 'classes/FeatureControls.php?f=state', 401);
$admin = fhlogin('preview_1_'.$tag, $password); $staff = fhlogin('preview_2_'.$tag, $password);
$page = fhr($admin[0], 'admin/?page=enterprise/feature_controls', 200);
if (!str_contains($page, 'Preview:') || !str_contains($page, 'do not yet restrict')) throw new RuntimeException('Preview warning missing.');
if (!str_contains($page, 'profile-setup-link') || !str_contains($page, 'class="setup-sidebar"') || !str_contains($page, 'Feature Controls (Preview)')) throw new RuntimeException('Administrator setup navigation missing.');
$staffPage = fhr($staff[0], 'admin/?page=home', 200);
if (str_contains($staffPage, 'profile-setup-link') || str_contains($staffPage, 'class="setup-sidebar"') || str_contains($staffPage, 'Feature Controls (Preview)')) throw new RuntimeException('Staff can see the setup menu.');
if (!str_contains($staffPage, 'My Profile') || !str_contains($staffPage, 'Log Out')) throw new RuntimeException('Staff account links missing.');
fhr($staff[0], 'admin/?page=system/settings', 403);
fhr($staff[0], 'admin/?page=enterprise/roles', 403);
fhr($staff[0], 'admin/?page=enterprise/feature_controls', 403);
fhr($staff[0], 'classes/FeatureControls.php?f=state', 403);
fhr($staff[0], 'classes/FeatureControls.php?f=create_retail_roles', 403, [], $staff[1]);
fhr($admin[0], 'classes/FeatureControls.php?f=create_retail_roles', 405);
fhr($admin[0], 'classes/FeatureControls.php?f=create_retail_roles', 403, []);
$retail = json_decode(fhr($admin[0], 'classes/FeatureControls.php?f=create_retail_roles', 200, [], $admin[1]), true)['data']['result'];
if (count($retail['created']) + count($retail['skipped']) !== 5) throw new RuntimeException('Retail roles are incomplete.');
$retailAgain = json_decode(fhr($admin[0], 'classes/FeatureControls.php?f=create_retail_roles', 200, [], $admin[1]), true)['data']['result'];
if ($retailAgain['created'] || count($retailAgain['skipped']) !== 5) throw new RuntimeException('Retail role creation is not repeatable.');
$state = json_decode(fhr($admin[0], 'classes/FeatureControls.php?f=state', 200), true)['data'];
$role = json_decode(fhr($admin[0], 'classes/FeatureControls.php?f=role&role_id='.$state['default_role_id'], 200), true)['data'];
fhr($admin[0], 'classes/FeatureControls.php?f=save_flags', 405);
fhr($admin[0], 'classes/FeatureControls.php?f=save_flags', 403, ['version'=>$state['version'],'changes'=>['sales.invoices'=>'0']]);
fhr($admin[0], 'classes/FeatureControls.php?f=save_flags', 422, ['version'=>$state['version'],'changes'=>['app.profile'=>'0']], $admin[1]);
fhr($admin[0], 'classes/FeatureControls.php?f=save_flags', 200, ['version'=>$state['version'],'changes'=>['sales.invoices'=>'0']], $admin[1]);
fhr($admin[0], 'classes/FeatureControls.php?f=save_flags', 409, ['version'=>$state['version'],'changes'=>['sales.invoices'=>'1']], $admin[1]);
fhr($admin[0], 'classes/FeatureControls.php?f=save_flags', 200, ['version'=>$state['version']+1,'changes'=>['sales.invoices'=>'1']], $admin[1]);
$changes = [['feature'=>'sales.invoices','action'=>'view','allowed'=>'0']];
fhr($admin[0], 'classes/FeatureControls.php?f=save_role_permissions', 200, ['role_id'=>$role['role']['id'],'version'=>$role['role']['version'],'changes'=>$changes], $admin[1]);
fhr($admin[0], 'classes/FeatureControls.php?f=save_role_permissions', 409, ['role_id'=>$role['role']['id'],'version'=>$role['role']['version'],'changes'=>$changes], $admin[1]);
$changes[0]['allowed'] = '1';
fhr($admin[0], 'classes/FeatureControls.php?f=save_role_permissions', 200, ['role_id'=>$role['role']['id'],'version'=>$role['role']['version']+1,'changes'=>$changes], $admin[1]);
echo "Passed $checks feature-control HTTP checks.\n";
