<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../classes/DBConnection.php';
if (!str_ends_with(DB_NAME, '_test')) exit("Use a disposable database ending in _test.\n");
$db = new DBConnection(); $conn = $db->conn; $checks = 0;
$base = rtrim(getenv('APP_BASE_URL') ?: 'http://127.0.0.1:8081/', '/') . '/';
if (parse_url($base, PHP_URL_PORT) !== 8081) exit("Use the disposable app on port 8081.\n");
function ph_sql(string $sql, array $values = []) { global $conn; $statement = $conn->prepare($sql); $statement->execute($values); return $statement->get_result(); }
function ph_request($client, string $path, int $expected = 200, ?array $data = null, ?string $token = null): string {
    global $base, $checks;
    curl_setopt_array($client, [CURLOPT_URL => $base . $path, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $token ? ['X-CSRF-Token: ' . $token] : []]);
    if ($data === null) curl_setopt($client, CURLOPT_HTTPGET, true);
    else { curl_setopt($client, CURLOPT_POST, true); curl_setopt($client, CURLOPT_POSTFIELDS, array_filter($data, static fn($value) => $value instanceof CURLFile) ? $data : http_build_query($data)); }
    $body = curl_exec($client); $status = curl_getinfo($client, CURLINFO_HTTP_CODE);
    if ($body === false || $status !== $expected || preg_match('/Fatal error|Uncaught |<b>Warning<\/b>/', $body)) throw new RuntimeException($path . ' expected ' . $expected . ', got ' . $status . ': ' . substr(strip_tags($body ?: curl_error($client)), -1000));
    $checks++; return $body;
}
function ph_login(string $username, string $password): array {
    $client = curl_init();
    $body = ph_request($client, 'admin/login.php');
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $body, $matches);
    $token = $matches[1];
    $login = json_decode(ph_request($client, 'classes/Login.php?f=login', 200, ['username' => $username,'password' => $password], $token), true);
    if (($login['status'] ?? '') !== 'success') throw new RuntimeException('Login failed');
    return [$client,$token];
}
function ph_api(array $client, string $action, array $data, int $expected = 200): array {
    return json_decode(ph_request($client[0], 'classes/Procurement.php?f=' . $action, $expected, $data, $client[1]), true)['data'] ?? [];
}
function ph_check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
$suffix = bin2hex(random_bytes(5));
$password = 'ProcurementHttp123!'; $users = [];
foreach (['maker' => 1,'checker' => 1,'staff' => 2] as $role => $type) {
    $username = 'po_http_' . $role . '_' . $suffix;
    ph_sql("INSERT INTO users (firstname,lastname,username,password,type) VALUES ('Procurement','HTTP',?,?,?)", [$username,password_hash($password, PASSWORD_DEFAULT),$type]);
    $users[$role] = ['id' => (int)$conn->insert_id,'username' => $username];
}
$anonymous = curl_init();
ph_request($anonymous, 'classes/Procurement.php?f=order&id=1', 401);
ph_request($anonymous, 'classes/Procurement.php?f=receive', 401, []);
$maker = ph_login($users['maker']['username'], $password);
$checker = ph_login($users['checker']['username'], $password);
$staff = ph_login($users['staff']['username'], $password);
foreach (['orders','approvals','receipts','replenishment','policy'] as $view) {
    ph_request($maker[0], 'admin/?page=transactions/procurement&view=' . $view);
    ph_request($staff[0], 'admin/?page=transactions/procurement&view=' . $view);
}
ph_request($maker[0], 'admin/?page=transactions/procurement&new=1');
ph_request($maker[0], 'classes/Procurement.php?f=save_order', 405);
ph_request($maker[0], 'classes/Procurement.php?f=save_order', 403, []);
ph_api($staff, 'approve', ['id' => 1,'version' => 1], 403);
ph_api($staff, 'save_policy', ['revision' => 1,'approval_threshold' => '0','reason' => 'No access'], 403);
ph_api($maker, 'save_order', ['lines' => 'invalid'], 422);
ph_request($maker[0], 'classes/Procurement.php?f=save_order', 400, ['notes' => ['bad']], $maker[1]);
ph_request($maker[0], 'admin/?page=transactions/procurement&id=99999999', 404);
ph_sql("INSERT INTO entity_list (entity_type,display_name,status) VALUES ('Supplier',?,1)", ['Procurement HTTP Supplier ' . $suffix]);
$vendor = (int)$conn->insert_id;
ph_sql("INSERT INTO item_list (name,vendor_id,unit,cost,opening_cost,reorder_level) VALUES (?,?,'Bottle',10,10,20)", ['Procurement HTTP Item ' . $suffix,$vendor]);
$item = (int)$conn->insert_id;
$input = ['request_key' => bin2hex(random_bytes(20)),'vendor_id' => $vendor,'order_date' => date('Y-m-d'),'tax_rate' => '13','created_by' => $users['checker']['id'],
    'lines' => [['item_id' => $item,'quantity' => '10','unit_price' => '10.55']]];
$id = ph_api($maker, 'save_order', $input)['id'];
ph_check(ph_api($maker, 'save_order', $input)['id'] === $id, 'Repeated HTTP creation returns the same order');
$order = json_decode(ph_request($maker[0], 'classes/Procurement.php?f=order&id=' . $id), true)['data'];
ph_check((int)$order['created_by'] === $users['maker']['id'] && $order['total'] === '119.22', 'Actor and totals are computed server-side');
ph_request($maker[0], 'admin/?page=transactions/procurement&id=' . $id);
ph_api($maker, 'submit', ['id' => $id,'version' => $order['version']]);
$order = json_decode(ph_request($maker[0], 'classes/Procurement.php?f=order&id=' . $id), true)['data'];
ph_api($maker, 'approve', ['id' => $id,'version' => $order['version']], 403);
ph_api($checker, 'approve', ['id' => $id,'version' => $order['version']]);
$order = json_decode(ph_request($staff[0], 'classes/Procurement.php?f=order&id=' . $id), true)['data'];
$receive = ['request_key' => bin2hex(random_bytes(20)),'order_id' => $id,'received_date' => date('Y-m-d'),'invoice_number' => 'HTTP-' . $suffix,
    'lines' => [['order_line_id' => $order['lines'][0]['id'],'quantity' => '3']]];
$receipt = ph_api($staff, 'receive', $receive)['receipt_id'];
ph_check(ph_api($staff, 'receive', $receive)['receipt_id'] === $receipt, 'HTTP receiving is idempotent');
ph_api($staff, 'receive', array_replace($receive, ['request_key' => bin2hex(random_bytes(20))]), 409);
ph_api($staff, 'receive', array_replace($receive, ['request_key' => bin2hex(random_bytes(20)),'invoice_number' => 'OVER-' . $suffix,'lines' => [['order_line_id' => $order['lines'][0]['id'],'quantity' => '8']]]), 422);
$order = json_decode(ph_request($staff[0], 'classes/Procurement.php?f=order&id=' . $id), true)['data'];
ph_check($order['status'] === 'part_received' && $order['lines'][0]['received'] === '3.0000', 'Receiving survives a complete HTTP round trip');
$transaction = $order['receipts'][0]['transaction_id'];
ph_request($staff[0], 'admin/?page=transactions/purchases/view_purchase&id=' . $transaction);
$delete = json_decode(ph_request($maker[0], 'classes/Master.php?f=delete_po', 200, ['id' => $transaction], $maker[1]), true);
ph_check(($delete['status'] ?? '') === 'failed', 'Legacy delete endpoint cannot bypass receipt immutability');
ph_check(ph_sql('SELECT quantity FROM item_list WHERE id=?', [$item])->fetch_assoc()['quantity'] === '3.0000', 'Rejected legacy deletion never changes stock');
$invalidReturn = ['parent_id' => $transaction,'vendor_id' => $vendor,'entity_type' => 'Supplier','date_created' => date('Y-m-d'),
    'item_id' => [$item],'qty' => ['4'],'price' => ['10.55'],'tax' => '1.37','remarks' => 'Return control test'];
$rejectedReturn = json_decode(ph_request($staff[0], 'classes/Master.php?f=save_return', 200, $invalidReturn, $staff[1]), true);
ph_check(($rejectedReturn['status'] ?? '') === 'failed' && str_contains($rejectedReturn['msg'] ?? '', 'exceeds'), 'Over-returns are rejected before changing inventory');
ph_check(ph_sql('SELECT quantity FROM item_list WHERE id=?', [$item])->fetch_assoc()['quantity'] === '3.0000', 'Rejected returns leave received stock unchanged');
ph_request($maker[0], 'classes/Accounting.php?f=post_source', 200, ['id' => $transaction], $maker[1]);
$validReturn = array_replace($invalidReturn, ['qty' => ['1']]);
$returned = json_decode(ph_request($staff[0], 'classes/Master.php?f=save_return', 200, $validReturn, $staff[1]), true);
ph_check(($returned['status'] ?? '') === 'success', 'Received purchases can be corrected with linked supplier returns');
ph_request($maker[0], 'classes/Accounting.php?f=post_source', 200, ['id' => $returned['id']], $maker[1]);
ph_check(ph_sql('SELECT quantity FROM item_list WHERE id=?', [$item])->fetch_assoc()['quantity'] === '2.0000', 'Valid returns reduce stock once without rewriting the receipt');
$tableInput = ['draw' => 1,'start' => 0,'length' => 10,'order' => [['column' => 8,'dir' => 'desc; SELECT 1']],'search' => ['value' => $order['receipts'][0]['reference']]];
$table = json_decode(ph_request($staff[0], 'admin/modules/transactions/purchases/dt_purchases.php', 200, $tableInput, $staff[1]), true);
ph_check(count($table['aaData'] ?? []) === 1, 'Receipt purchases appear in the existing purchase list with safe sorting');
ph_check(str_contains($table['aaData'][0]['outstanding'], '23.84'), 'Purchase list deducts both the supplier return value and tax credit');
ph_check(!str_contains($table['aaData'][0]['action'], 'Edit') && !str_contains($table['aaData'][0]['action'], 'Delete'), 'Immutable receipt purchases do not offer edit/delete actions');
$purchaseView = ph_request($staff[0], 'admin/?page=transactions/purchases/view_purchase&id=' . $transaction);
ph_check(str_contains($purchaseView, '23.84') && !str_contains($purchaseView, 'id="delete_purchase"'), 'Purchase detail agrees with the tax-inclusive returned balance and locked state');
$csv = ph_request($staff[0], 'classes/Procurement.php?f=export&vendor_id=' . $vendor);
ph_check(str_contains($csv, 'Suggested') && str_contains($csv, 'Procurement HTTP Item'), 'Replenishment CSV exports actual item records');
ph_request($staff[0], 'classes/Procurement.php?f=replenishment&vendor_id=' . $vendor);
ph_api($maker, 'close', ['id' => $id,'version' => $order['version'],'reason' => 'HTTP close short']);
$revision = ph_sql('SELECT revision FROM po_policy WHERE id=1')->fetch_assoc()['revision'];
ph_api($maker, 'save_policy', ['revision' => $revision,'approval_threshold' => '0','require_purchase_orders' => '1','reason' => 'Require the purchasing approval workflow']);
$blocked = json_decode(ph_request($staff[0], 'classes/Master.php?f=save_purchase', 200, ['vendor_id' => $vendor], $staff[1]), true);
ph_check(($blocked['status'] ?? '') === 'failed' && str_contains($blocked['msg'] ?? '', 'required'), 'Mandatory purchase orders cannot be bypassed with the legacy save endpoint');
$blockedForm = ph_request($staff[0], 'admin/?page=transactions/purchases/manage_purchase');
ph_check(str_contains($blockedForm, 'Purchase orders are required.') && !str_contains($blockedForm, 'id="purchase-form"'), 'Mandatory-PO policy replaces the legacy direct entry form');
$file = tmpfile();
fputcsv($file, ['type','reference_code','entity','account','total_amount','discount_perc','tax_perc','remarks','transaction_date']);
fputcsv($file, ['purchase','POLICY-IMPORT-' . $suffix,$vendor,'',10,0,0,'Policy bypass attempt',date('Y-m-d')]);
fflush($file);
$importId = 'po_policy_' . $suffix;
$import = json_decode(ph_request($maker[0], 'admin/modules/system/import/process_import.php', 200,
    ['type' => 'transactions','import_id' => $importId,'file' => new CURLFile(stream_get_meta_data($file)['uri'], 'text/csv', 'transactions.csv')], $maker[1]), true);
fclose($file);
@unlink(base_app . 'uploads/progress_' . $importId . '.json');
ph_check(($import['imported'] ?? -1) === 0 && str_contains(implode(' ', $import['errors'] ?? []), 'Purchase orders are required'), 'Transaction CSV imports respect the mandatory-PO policy');
$revision = ph_sql('SELECT revision FROM po_policy WHERE id=1')->fetch_assoc()['revision'];
ph_api($maker, 'save_policy', ['revision' => $revision,'approval_threshold' => '0','require_purchase_orders' => '0','reason' => 'Restore default compatibility policy']);
// Browser fixtures are isolated in the disposable database and never touch the business database.
foreach (['procurement_browser_checker' => 1,'procurement_browser_receiver' => 2] as $username => $type) {
    if (!ph_sql('SELECT id FROM users WHERE username=?', [$username])->num_rows) {
        ph_sql("INSERT INTO users (firstname,lastname,username,password,type) VALUES ('Browser','Procurement',?,?,?)", [$username,password_hash('ProcurementBrowser123!', PASSWORD_DEFAULT),$type]);
    }
}
echo "Passed $checks procurement HTTP checks.\n";
