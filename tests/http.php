<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../classes/DBConnection.php';
if (!preg_match('/_(test|review)$/', DB_NAME)) exit("Use a database ending in _test or _review.\n");
$db = new DBConnection();
$base = rtrim(getenv('APP_BASE_URL') ?: 'http://127.0.0.1:8080/', '/') . '/';
$checks = 0;
$http = curl_init();
curl_setopt_array($http, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15]);
function request($path, $data = null, $token = null) {
    global $http, $base;
    curl_setopt_array($http, [CURLOPT_URL => $base . $path, CURLOPT_POST => $data !== null,
        CURLOPT_HTTPHEADER => $token === null ? [] : ['X-CSRF-Token: ' . $token]]);
    if ($data !== null) curl_setopt($http, CURLOPT_POSTFIELDS, http_build_query($data));
    else curl_setopt($http, CURLOPT_HTTPGET, true);
    $body = curl_exec($http);
    if ($body === false) throw new RuntimeException(curl_error($http));
    return [curl_getinfo($http, CURLINFO_HTTP_CODE), $body];
}
function expect_status($path, $status, $data = null, $token = null) {
    global $checks;
    [$actual, $body] = request($path, $data, $token);
    $has_error = preg_match('/Fatal error|Uncaught |<b>Warning<\/b>/', $body, $error, PREG_OFFSET_CAPTURE);
    if ($actual !== $status || $has_error) {
        $excerpt = $has_error ? substr($body, max(0, $error[0][1] - 20), 1600) : substr($body, -2400);
        throw new RuntimeException("$path: expected $status, got $actual\n" . strip_tags($excerpt));
    }
    $checks++;
    return $body;
}
function login_as($username, $password) {
    $body = expect_status('admin/login.php', 200);
    if (!preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $body, $match)) throw new RuntimeException('Missing CSRF token');
    $body = expect_status('classes/Login.php?f=login', 200, ['username' => $username, 'password' => $password], $match[1]);
    if ((json_decode($body, true)['status'] ?? '') !== 'success') throw new RuntimeException('Login failed');
    return $match[1];
}

$name = 'review_http_' . bin2hex(random_bytes(4));
$password = 'ReviewHttpPassword123!';
$hash = md5($password);
$db->queryPrepared("INSERT INTO users (firstname, lastname, username, password, type) VALUES ('Review', 'Admin', ?, ?, 1)", 'ss', [$name, $hash]);
$admin_id = $db->conn->insert_id;
try {
    expect_status('database/sms_db.sql', 404);
    expect_status('.git/config', 404);
    expect_status('temp/cache/example.cache', 404);
    expect_status('tests/regression.php', 404);
    expect_status('admin/', 302);
    expect_status('classes/Master.php?f=search_pos_items', 401);
    expect_status('admin/reports/report_api.php?action=daily_sales', 401);
    expect_status('classes/GitSync.php?f=restore', 401);
    expect_status('classes/Users.php?f=delete', 401, ['id' => 2]);
    expect_status('classes/Login.php?f=login', 403, ['username' => $name, 'password' => $password]);
    $token = login_as($name, $password);
    $stored = $db->queryPrepared('SELECT password FROM users WHERE id=?', 'i', [$admin_id])->fetch_assoc()['password'];
    if (!password_verify($password, $stored)) throw new RuntimeException('Legacy password was not upgraded');
    $checks++;
    expect_status('classes/Master.php?f=delete_sale', 405);
    expect_status('classes/Users.php?f=delete', 403, ['id' => 2]);
    expect_status('classes/GitSync.php?f=restore', 405);
    expect_status('admin/?page=../../config', 404);
    expect_status('admin/modules/master/items/view.php?id=1%27', 400);
    expect_status('admin/?page=reports/profit&from=invalid', 400);
    $pages = ['home', 'pos', 'master/items', 'master/items/manage', 'master/vendors', 'master/customers',
        'master/categories', 'master/accounts', 'master/bank_accounts', 'master/stocks', 'sales', 'purchases',
        'payments', 'expenses', 'returns', 'transactions/stock_adjustments', 'transactions/opening_balances',
        'finance/cash_counts', 'system/users', 'system/users/profile', 'system/settings', 'system/audit_logs',
        'system/import', 'reports/profit', 'reports/stock', 'reports/customer_outstanding', 'reports/vendor_outstanding',
        'transactions/sales/manage_sale', 'transactions/purchases/manage_purchase', 'transactions/returns/manage_return',
        'transactions/expenses/manage_expense', 'transactions/payments/manage_payment', 'finance/cash_counts/manage',
        'master/accounts/manage_transfer', 'master/vendors/manage', 'master/customers/manage', 'system/users/manage_user',
        'reports/stock_current', 'reports/stock_low', 'reports/stock_valuation', 'reports/item_ledger', 'reports/cash_book',
        'reports/account_ledger', 'reports/vat_sales', 'reports/vat_purchase', 'reports/vat_summary', 'reports/customer_statement',
        'reports/daily_purchase', 'reports/daily_expense', 'reports/pos_sales', 'reports/sales', 'reports/purchase'];
    foreach ($pages as $page) expect_status('admin/?page=' . $page, 200);
    expect_status('admin/modules/system/import/get_progress.php?id=review_hex123', 200);
    expect_status('admin/reports/report_api.php?action=daily_sales', 200);
    expect_status('classes/Master.php?f=search_pos_items&q=%27', 200);
    $staff = $name . '_staff';
    $body = expect_status('classes/Users.php?f=save', 200, ['firstname' => 'Review', 'lastname' => 'Staff',
        'username' => $staff, 'password' => $password, 'type' => 2], $token);
    if (trim($body) !== '1') throw new RuntimeException('HTTP user creation failed: ' . $body);
    $staff_id = $db->queryPrepared('SELECT id FROM users WHERE username=?', 's', [$staff])->fetch_assoc()['id'];
    expect_status('classes/Login.php?f=logout', 302);
    $token = login_as($staff, $password);
    expect_status('admin/', 200);
    expect_status('admin/?page=system/settings', 403);
    expect_status('admin/?page=settings', 403);
    expect_status('classes/GitSync.php?f=restore', 403, [], $token);
    expect_status('classes/Master.php?f=save_bulk_ref_codes', 403, [], $token);
    expect_status('classes/Users.php?f=save', 403, ['id' => $admin_id], $token);
    expect_status('classes/Users.php?f=delete', 403, ['id' => $admin_id], $token);
    $body = expect_status('classes/Users.php?f=save', 200, ['id' => $staff_id, 'firstname' => 'Review', 'lastname' => 'Staff',
        'username' => $staff, 'password' => '', 'type' => 1, 'login_type' => 99], $token);
    if (trim($body) !== '1') throw new RuntimeException('Staff profile update failed: ' . $body);
    expect_status('admin/?page=system/users/profile', 200);
    expect_status('admin/?page=system/settings', 403);
    echo "Passed $checks HTTP checks.\n";
} finally {
    foreach (array_filter([$admin_id, $staff_id ?? null]) as $id) {
        $db->delete_from_index('users', $id);
        $db->deleteById('users', $id);
    }
    curl_close($http);
}
