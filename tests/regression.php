<?php
// Run only against a disposable database imported from database/sms_db.sql.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../initialize.php';
if (!preg_match('/_(test|review)$/', DB_NAME)) exit("Use a database ending in _test or _review.\n");
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once __DIR__ . '/../classes/Master.php';
require_once __DIR__ . '/../classes/Users.php';

$checks = 0;
function check($condition, $message) {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function call_action($object, $method, $post) {
    $_POST = $post;
    $result = json_decode($object->$method(), true);
    check(is_array($result) && ($result['status'] ?? '') === 'success', $method . ': ' . json_encode($result));
    return $result;
}
function stock($id) {
    global $db;
    return (float)$db->queryPrepared('SELECT quantity FROM item_list WHERE id=?', 'i', [$id])->fetch_assoc()['quantity'];
}

$_SESSION['userdata'] = ['id' => 1, 'type' => 1, 'login_type' => 1];
$tag = 'Review ' . bin2hex(random_bytes(4));
$master = $Master;
$users = new Users();
$_POST = ['firstname' => 'Review', 'lastname' => 'User', 'username' => $tag, 'password' => 'ReviewPassword123!', 'type' => 2];
check($users->save_users() === 1, 'Create user');
$user = $db->queryPrepared('SELECT * FROM users WHERE username=?', 's', [$tag])->fetch_assoc();
check($user && password_verify('ReviewPassword123!', $user['password']), 'New user stored in login table with modern hash');
$_POST = ['id' => $user['id'], 'firstname' => 'Review', 'lastname' => 'Changed', 'username' => $tag, 'password' => '',
    'meta' => ['address' => "O'Brien, Kathmandu"]];
check($users->save_users() === 1, 'Edit user without role field');
$updated = $db->queryPrepared('SELECT * FROM users WHERE id=?', 'i', [$user['id']])->fetch_assoc();
check($updated['password'] === $user['password'] && (int)$updated['type'] === 2, 'Blank password and omitted role preserve values');
$meta = $db->queryPrepared('SELECT meta_value FROM user_meta WHERE user_id=?', 'i', [$user['id']])->fetch_assoc();
check($meta['meta_value'] === "O'Brien, Kathmandu", 'Metadata preserves commas and apostrophes');
$_POST['password'] = 'ChangedPassword123!';
check($users->save_users() === 1, 'Change user password');
$updated = $db->queryPrepared('SELECT password FROM users WHERE id=?', 'i', [$user['id']])->fetch_assoc();
check(password_verify('ChangedPassword123!', $updated['password']), 'Password is hashed once');

$customer = call_action($master, 'save_customer', ['name' => "$tag Customer", 'contact' => '', 'email' => '', 'address' => '',
    'status' => 1, 'credit_limit' => 100.75, 'credit_days' => 7, 'opening_balance' => 0]);
$supplier = call_action($master, 'save_vendor', ['name' => "$tag Supplier", 'contact' => '', 'cperson' => '', 'address' => '',
    'status' => 1, 'credit_limit' => 100.75, 'credit_days' => 7, 'opening_balance' => 0]);
$entity = $db->queryPrepared('SELECT credit_limit, credit_days FROM entity_list WHERE id=?', 'i', [$supplier['id']])->fetch_assoc();
check(abs($entity['credit_limit'] - 100.75) < .01 && (int)$entity['credit_days'] === 7, 'Vendor credit limit retains decimals');
$items = [];
foreach (['A', 'B'] as $label) {
    $result = call_action($master, 'save_item', ['name' => "$tag $label", 'description' => '', 'cost' => 10,
        'selling_price' => 20, 'mrp' => 20, 'status' => 1]);
    $items[] = $result['id'];
}
[$a, $b] = $items;
$purchase_post = ['id' => '', 'vendor_id' => $supplier['id'], 'date_po' => '2026-11-01',
    'item_id' => [$a], 'qty' => [10], 'price' => [10], 'total' => [100], 'amount' => 100, 'remarks' => 'Review'];
$purchase = call_action($master, 'save_purchase', $purchase_post);
check(stock($a) === 10.0, 'Purchase form vendor_id accepted and stock increased');
$purchase_code = $db->queryPrepared('SELECT reference_code FROM transactions WHERE id=?', 'i', [$purchase['id']])->fetch_assoc()['reference_code'];
$purchase_post = array_replace($purchase_post, ['id' => $purchase['id'], 'po_code' => $purchase_code, 'item_id' => [$b]]);
call_action($master, 'save_purchase', $purchase_post);
check(stock($a) === 0.0 && stock($b) === 10.0, 'Purchase edit updates removed and added items');

$sale_post = ['customer_id' => $customer['id'], 'date_sale' => '2026-11-02', 'item_id' => [$b],
    'qty' => [2], 'price' => [20], 'total_price' => [40], 'amount' => 40, 'discount' => 0];
$sale = call_action($master, 'save_sale', $sale_post);
check(stock($b) === 8.0, 'Sale reduces stock');
$cogs_before = (float)$db->conn->query("SELECT total_cogs FROM summary_daily_metrics WHERE metric_date='2026-11-02'")->fetch_assoc()['total_cogs'];
$db->queryPrepared('UPDATE item_list SET cost=999 WHERE id=?', 'i', [$b]);
$master->update_summary_metrics('2026-11-02');
$metric = $db->conn->query("SELECT total_cogs FROM summary_daily_metrics WHERE metric_date='2026-11-02'")->fetch_assoc();
check((float)$metric['total_cogs'] === $cogs_before, 'Sale cost stays fixed after current item cost changes');
$db->queryPrepared('UPDATE item_list SET cost=10 WHERE id=?', 'i', [$b]);
$outstanding = call_action($master, 'get_outstanding_bills', ['type' => 1, 'party_id' => $customer['id']]);
check(count($outstanding['data']) === 1 && (float)$outstanding['data'][0]['outstanding'] === 40.0, 'Outstanding invoices work without external stored procedure');
$account_id = $db->conn->query('SELECT id FROM account_list ORDER BY id LIMIT 1')->fetch_assoc()['id'];
$payment_post = ['type' => 1, 'party_id' => $customer['id'], 'account_amounts' => [$account_id => 10],
    'invoices' => [$sale['id']], 'allocation' => [10], 'remarks' => 'Review payment', 'date_created' => '2026-11-02'];
$payment = call_action($master, 'save_bulk_payment', $payment_post);
$_POST = array_replace($payment_post, ['account_amounts' => [$account_id => 40], 'allocation' => [40]]);
check(json_decode($master->save_bulk_payment(), true)['status'] === 'failed', 'Invoice overpayment rejected');
call_action($master, 'delete_payment', ['id' => $payment['id']]);
$_POST = array_replace($sale_post, ['qty' => [], 'amount' => 9999]);
check(json_decode($master->save_sale(), true)['status'] === 'failed', 'Incomplete line arrays rejected');
$sale_code = $db->queryPrepared('SELECT reference_code FROM transactions WHERE id=?', 'i', [$sale['id']])->fetch_assoc()['reference_code'];
$sale_post = array_replace($sale_post, ['id' => $sale['id'], 'sales_code' => $sale_code, 'item_id' => [$a], 'date_sale' => '2026-11-03']);
call_action($master, 'save_sale', $sale_post);
check(stock($b) === 10.0 && stock($a) === -2.0, 'Sale edit restores removed item stock');
$_POST = ['id' => $purchase['id']];
$wrong_type = json_decode($master->delete_sale(), true);
check($wrong_type['status'] === 'failed' && stock($b) === 10.0, 'Wrong-type delete cannot erase another transaction items');
call_action($master, 'delete_sale', ['id' => $sale['id']]);
check(stock($a) === 0.0, 'Sale deletion restores stock');
call_action($master, 'delete_po', ['id' => $purchase['id']]);
check(stock($b) === 0.0, 'Purchase deletion completes and removes stock');

$adjustment = call_action($master, 'save_adjustment', ['item_id' => $a, 'quantity' => 3, 'type' => 1, 'price' => 10, 'amount' => 30, 'remarks' => 'Review']);
$adjustment_id = $db->conn->query("SELECT id FROM transactions WHERE type='adjustment' AND remarks='Review' ORDER BY id DESC LIMIT 1")->fetch_assoc()['id'];
call_action($master, 'save_adjustment', ['id' => $adjustment_id, 'item_id' => $b, 'quantity' => 3, 'type' => 1, 'price' => 10, 'amount' => 30, 'remarks' => 'Review']);
check(stock($a) === 0.0 && stock($b) === 3.0, 'Adjustment edit restores removed item stock');
call_action($master, 'delete_adjustment', ['id' => $adjustment_id]);

$return_post = ['vendor_id' => $supplier['id'], 'entity_type' => 'Supplier', 'date_created' => '2026-11-04',
    'item_id' => [$a], 'qty' => [1], 'price' => [10], 'total' => [10], 'amount' => 10, 'remarks' => 'Review'];
$return = call_action($master, 'save_return', $return_post);
check(stock($a) === -1.0, 'Supplier return reduces stock');
call_action($master, 'save_return', array_replace($return_post, ['id' => $return['id'], 'item_id' => [$b]]));
check(stock($a) === 0.0 && stock($b) === -1.0, 'Return edit restores removed item stock');
call_action($master, 'delete_return', ['id' => $return['id']]);
check(stock($b) === 0.0, 'Return deletion restores stock');

$pos_post = ['customer_id' => $customer['id'], 'pos_date' => '2026-11-05', 'item_id' => [$a], 'qty' => [1],
    'price' => [20], 'total_price' => [9999], 'account_amount' => [$account_id => 20]];
$pos = call_action($master, 'save_pos', $pos_post);
$pos_post['price'] = [30];
$pos_post['account_amount'] = [$account_id => 30];
call_action($master, 'save_pos', $pos_post);
$pos_line = $db->queryPrepared('SELECT quantity, unit_price, total_price FROM transaction_items WHERE transaction_id=?', 'i', [$pos['sale_id']])->fetch_assoc();
check((float)$pos_line['quantity'] === 2.0 && (float)$pos_line['unit_price'] === 25.0
    && (float)$pos_line['total_price'] === 50.0, 'POS computes totals and weighted consolidated price');
$pos_payment = $db->queryPrepared("SELECT id FROM transactions WHERE parent_id=? AND type='payment'", 'i', [$pos['sale_id']])->fetch_assoc()['id'];
call_action($master, 'delete_payment', ['id' => $pos_payment]);
call_action($master, 'delete_sale', ['id' => $pos['sale_id']]);
check(stock($a) === 0.0, 'POS reversal restores stock');

$account_id = $db->conn->query('SELECT id FROM account_list ORDER BY id LIMIT 1')->fetch_assoc()['id'];
$denom_post = ['date' => '2026-11-19', 'counts' => [100 => 1], 'expected_amount' => 100,
    'total_amount' => 100, 'difference' => 0, 'account_balances' => [$account_id => 100]];
$denom = call_action($master, 'save_denomination', $denom_post);
$denom_post['id'] = $denom['id'];
$denom_post['account_balances'][$account_id] = 150;
call_action($master, 'save_denomination', $denom_post);
$snapshots = $db->queryPrepared('SELECT balance FROM daily_account_balances WHERE denomination_id=?', 'i', [$denom['id']]);
check($snapshots->num_rows === 1 && (float)$snapshots->fetch_assoc()['balance'] === 150.0, 'Cash count updates one account snapshot');
call_action($master, 'finalize_reconciliation', ['id' => $denom['id']]);
$_POST = ['id' => $denom['id']];
check(json_decode($master->delete_denomination(), true)['status'] === 'failed', 'Finalized cash counts cannot be deleted');
$_POST = $denom_post;
check(json_decode($master->save_denomination(), true)['status'] === 'failed', 'Finalized cash counts cannot be edited');
$db->queryPrepared('DELETE FROM daily_account_balances WHERE denomination_id=?', 'i', [$denom['id']]);
$db->deleteById('cash_denominations', $denom['id']);

require_once __DIR__ . '/../classes/GitSync.php';
$safe_process = new class extends GitSync {
    public function inspect_arguments($arguments) { return $this->run_command($arguments); }
};
$literal = 'literal $(printf unsafe); argument';
$process_result = $safe_process->inspect_arguments(['/usr/bin/printf', '%s', $literal]);
check($process_result['return'] === 0 && $process_result['output'] === $literal, 'Configured process arguments bypass shell parsing');
$failed_git = new class extends GitSync {
    protected function run_command($arguments, $input = null, $output = null, $environment = []) {
        return ['return' => 1, 'output' => 'Simulated failure'];
    }
};
check(json_decode($failed_git->pull_updates(), true)['status'] === 'failed', 'Git failure is reported as failure without calling a remote');

$_GET = ['q' => "' OR 1=1 --"];
check(is_array(json_decode($master->search_pos_items(), true)['results']), 'POS search treats SQL characters as data');
check(is_array(json_decode($master->search_parties(), true)['results']), 'Party search treats SQL characters as data');
call_action($users, 'delete_users', ['id' => $user['id']]);
foreach ($items as $id) {
    $db->delete_from_index('item_list', $id);
    $db->deleteById('item_list', $id);
}
foreach ([$customer['id'], $supplier['id']] as $id) {
    $db->delete_from_index('entity_list', $id);
    $db->deleteById('entity_list', $id);
}
echo "Passed $checks regression checks.\n";
