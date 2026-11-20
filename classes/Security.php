<?php

function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function deny_request($status, $message) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'failed', 'msg' => $message]);
    exit;
}

function enforce_request_security() {
    if (PHP_SAPI === 'cli') return;

    $script_path = $_SERVER['SCRIPT_FILENAME'] ?? '';
    $script = str_replace('\\', '/', realpath($script_path) ?: $script_path);
    $relative = substr($script, strlen(base_app));
    $is_login = $relative === 'admin/login.php' || $relative === 'classes/Login.php';
    $protected = strpos($relative, 'admin/') === 0 || strpos($relative, 'classes/') === 0;
    $logged_in = !empty($_SESSION['userdata']['id']) && ($_SESSION['userdata']['login_type'] ?? null) == 1;

    if ($protected && !$is_login && !$logged_in) {
        if ($relative === 'admin/index.php') {
            header('Location: ' . base_url . 'admin/login.php');
            exit;
        }
        deny_request(401, 'Please sign in.');
    }

    foreach (['get'=>$_GET, 'post'=>$_POST] as $source=>$input) {
        foreach (['id', 'item_id', 'vendor_id', 'customer_id', 'entity_id', 'account_id', 'parent_id',
            'from_sale_id','from_purchase_id','party_id','transaction_id','from_account_id','to_account_id','order_id','receipt_id','approver_id','location_id','from_location_id','to_location_id','assigned_to','user_id','role_id','gl_account_id'] as $key) {
            if ($key === 'id' && $relative === 'admin/modules/system/import/get_progress.php') continue;
            if (!isset($input[$key]) || $input[$key] === '') continue;
            $values = is_array($input[$key]) && $key === 'item_id' && $source==='post' ? $input[$key] : [$input[$key]];
            foreach ($values as $value) {
                if (!is_scalar($value) || !ctype_digit((string)$value)) deny_request(400, 'Invalid record ID.');
            }
        }
    }
    foreach (['from', 'to'] as $key) {
        if (!isset($_GET[$key]) || $_GET[$key] === '') continue;
        $value = $_GET[$key];
        if (!is_string($value)) deny_request(400, 'Invalid report date.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) deny_request(400, 'Invalid report date.');
    }

    $page = is_string($_GET['page'] ?? null) ? $_GET['page'] : '';
    if (isset($_GET['page'])&&!is_string($_GET['page'])) deny_request(404,'Invalid page.');
    $admin_only = in_array($relative, ['classes/GitSync.php', 'classes/SystemSettings.php'], true)
        || ($relative === 'classes/Master.php' && in_array($_GET['f'] ?? '', ['save_ref_code_setting', 'save_bulk_ref_codes'], true))
        || strpos($relative, 'admin/modules/system/') === 0
        || ($relative === 'admin/index.php' && strpos($page, 'system/') === 0);
    // Staff can edit their own profile; Users.php checks the target user and role.
    if ($page === 'system/users/profile' || $relative === 'admin/modules/system/users/profile.php') {
        $admin_only = false;
    }
    if ($admin_only && (!$logged_in || ($_SESSION['userdata']['type'] ?? 0) != 1)) {
        deny_request(403, 'Administrator access required.');
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
        if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
            deny_request(403, 'Invalid request token. Refresh the page and try again.');
        }
        unset($_POST['_csrf']);
    }
    if ($relative === 'classes/Master.php') {
        $read_actions = ['search_pos_items', 'search_parties', 'search_customers', 'get_customer_details',
            'get_outstanding_bills', 'get_next_ref_code', 'get_daily_balances', 'get_balances_by_date',
            'check_reconciliation_status'];
        if (!in_array($_GET['f'] ?? '', $read_actions, true) && $method !== 'POST') {
            deny_request(405, 'Use POST for changes.');
        }
    }
    if (in_array($relative, ['classes/Users.php', 'classes/GitSync.php', 'classes/SystemSettings.php'], true)
        && isset($_GET['f']) && $method !== 'POST') {
        deny_request(405, 'Use POST for changes.');
    }
}
