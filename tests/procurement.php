<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../classes/DBConnection.php';
require_once __DIR__ . '/../classes/Procurement/OrderService.php';
require_once __DIR__ . '/../classes/Accounting/JournalService.php';
use App\Procurement\OrderService;
use App\Accounting\JournalService;

if (!str_ends_with(DB_NAME, '_test')) exit("Use a fresh disposable database ending in _test.\n");
$db = new DBConnection(); $conn = $db->conn; $checks = 0;
function po_sql(string $sql, array $values = []) { global $conn; $statement = $conn->prepare($sql); $statement->execute($values); return $statement->get_result(); }
function po_check(bool $ok, string $message): void { global $checks; if (!$ok) throw new RuntimeException($message); $checks++; }
function po_fails(callable $work, string $message): void {
    try { $work(); } catch (Throwable $error) {
        po_check(str_contains(strtolower($error->getMessage()), strtolower($message)), 'Expected ' . $message . ', got ' . $error->getMessage()); return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}
function po_key(): string { return bin2hex(random_bytes(20)); }
$suffix = bin2hex(random_bytes(5));
$users = [];
foreach (['maker' => 1,'checker' => 1,'staff' => 2,'other' => 2] as $name => $role) {
    po_sql("INSERT INTO users (firstname,lastname,username,password,type) VALUES ('Procurement','Test',?,?,?)", ['po_' . $name . '_' . $suffix, password_hash('ProcurementTest123!', PASSWORD_DEFAULT), $role]);
    $users[$name] = (int)$conn->insert_id;
}
$maker = new OrderService($conn, $users['maker']);
$checker = new OrderService($conn, $users['checker']);
$staff = new OrderService($conn, $users['staff']);
$other = new OrderService($conn, $users['other']);
po_fails(fn() => new OrderService($conn, 99999999), 'account');
$ledger = new JournalService($conn, $users['checker']);
$period = $ledger->createPeriod('Procurement March 2198', '2198-03-01', '2198-03-31');
$closed = $ledger->createPeriod('Procurement April 2198', '2198-04-01', '2198-04-30');
$ledger->setPeriodStatus($closed, true, 'Test closed receipt period');
po_sql("INSERT INTO entity_list (entity_type,display_name,status) VALUES ('Supplier',?,1)", ['Procurement Supplier ' . $suffix]);
$vendor = (int)$conn->insert_id;
po_sql("INSERT INTO entity_list (entity_type,display_name,status) VALUES ('Customer',?,1)", ['Not A Supplier ' . $suffix]);
$customer = (int)$conn->insert_id;
po_sql("INSERT INTO item_list (name,unit,vendor_id,cost,opening_cost,reorder_level) VALUES (?,'Bottle',?,10,10,10)", ['Procurement Item ' . $suffix, $vendor]);
$item = (int)$conn->insert_id;
po_sql("INSERT INTO item_list (name,unit,vendor_id,cost,opening_cost,reorder_level) VALUES (?,'Bottle',?,5,5,20)", ['Second Procurement Item ' . $suffix, $vendor]);
$item2 = (int)$conn->insert_id;
$draft = ['vendor_id' => $vendor,'order_date' => '2198-03-10','expected_date' => '2198-03-15','notes' => 'Procurement regression order','tax_rate' => '13',
    'request_key' => po_key(),'lines' => [['item_id' => $item,'quantity' => '3','unit_price' => '0.335']]];
$id = $maker->save($draft);
$order = $maker->order($id);
po_check($order['subtotal'] === '1.01' && $order['tax'] === '0.13' && $order['total'] === '1.14', 'Exact line and tax rounding');
po_check($maker->save($draft) === $id, 'Order creation is idempotent');
po_check(po_sql('SELECT quantity FROM item_list WHERE id=?', [$item])->fetch_assoc()['quantity'] === '0.0000', 'Draft orders do not add stock');
po_fails(fn() => $maker->save(array_replace($draft, ['notes' => 'Different data'])), 'request key');
po_fails(fn() => $maker->save(array_replace($draft, ['request_key' => po_key(),'vendor_id' => $customer])), 'supplier');
po_fails(fn() => $maker->save(array_replace($draft, ['request_key' => po_key(),'expected_date' => '2198-03-01'])), 'precede');
po_fails(fn() => $maker->save(array_replace($draft, ['request_key' => po_key(),'order_date' => '2198-02-30'])), 'valid date');
po_fails(fn() => $maker->save(array_replace($draft, ['request_key' => po_key(),'tax_rate' => '101'])), 'tax rate');
po_fails(fn() => $maker->save(array_replace($draft, ['request_key' => po_key(),'lines' => []])), 'order lines');
po_fails(fn() => $maker->save(array_replace($draft, ['request_key' => po_key(),'lines' => [$draft['lines'][0],$draft['lines'][0]]])), 'only once');
po_fails(fn() => $maker->save(array_replace($draft, ['request_key' => po_key(),'lines' => [['item_id' => $item,'quantity' => '0.00001','unit_price' => '1']]])), 'decimal places');
po_fails(fn() => $maker->save(array_replace($draft, ['request_key' => po_key(),'lines' => [['item_id' => $item,'quantity' => '1','unit_price' => '0'],['item_id' => $item2,'quantity' => '1','unit_price' => '10']]])), 'positive quantity and unit price');
po_fails(fn() => $maker->save(array_replace($draft, ['request_key' => po_key(),'lines' => [['item_id' => $item,'quantity' => '0.0001','unit_price' => '0.0001']]])), 'at least 0.01');
$receive = ['order_id' => $id,'received_date' => '2198-03-11','invoice_number' => 'PO-TEST-' . $suffix . '-1','request_key' => po_key(),
    'lines' => [['order_line_id' => $order['lines'][0]['id'],'quantity' => '0.5']]];
po_fails(fn() => $maker->receive($receive), 'approved');
$maker->transition($id, 'submit', $order['version']);
$order = $maker->order($id);
po_check($order['status'] === 'pending' && $order['approval_required'] == 1, 'Default policy requires independent approval');
po_fails(fn() => $maker->transition($id, 'approve', $order['version']), 'own order');
po_fails(fn() => $staff->transition($id, 'approve', $order['version']), 'Administrator');
po_fails(fn() => $maker->save(array_replace($draft, ['id' => $id,'version' => $order['version']])), 'Only draft');
po_fails(fn() => po_sql('UPDATE po_lines SET quantity=4 WHERE order_id=?', [$id]), 'draft');
po_fails(fn() => po_sql('UPDATE po_orders SET total=99 WHERE id=?', [$id]), 'locked');
$checker->transition($id, 'reject', $order['version'], 'Correct expected delivery');
$order = $maker->order($id);
po_check($order['status'] === 'rejected', 'Rejections return an editable order');
$updated = array_replace($draft, ['id' => $id,'version' => $order['version'],'expected_date' => '2198-03-16']);
$maker->save($updated);
po_fails(fn() => $maker->save($updated), 'another window');
$order = $maker->order($id);
$maker->transition($id, 'submit', $order['version']);
$order = $maker->order($id);
$checker->transition($id, 'approve', $order['version']);
$order = $maker->order($id);
po_check($order['status'] === 'approved' && (int)$order['approved_by'] === $users['checker'], 'Independent administrator approves');
$receive['lines'][0]['order_line_id'] = $order['lines'][0]['id'];
po_fails(fn() => $staff->receive(array_replace($receive, ['received_date' => '2198-03-09'])), 'precede');
po_fails(fn() => $staff->receive(array_replace($receive, ['received_date' => '2198-04-01'])), 'open accounting period');
po_fails(fn() => $staff->receive(array_replace($receive, ['lines' => [['order_line_id' => $order['lines'][0]['id'],'quantity' => '4']]])), 'exceeds');
po_fails(fn() => $staff->receive(array_replace($receive, ['lines' => [['order_line_id' => $order['lines'][0]['id'],'quantity' => '0']]])), 'positive-value');
$beforeFailure = (int)po_sql('SELECT COUNT(*) AS count FROM transactions')->fetch_assoc()['count'];
$conn->query("CREATE TRIGGER po_test_receipt_failure BEFORE INSERT ON po_receipt_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated receipt failure'");
try { po_fails(fn() => $staff->receive($receive), 'Simulated receipt failure'); }
finally { $conn->query('DROP TRIGGER po_test_receipt_failure'); }
po_check((int)po_sql('SELECT COUNT(*) AS count FROM transactions')->fetch_assoc()['count'] === $beforeFailure
    && count($maker->order($id)['receipts']) === 0, 'Failure after purchase insertion rolls back the entire receipt and purchase');
po_check(po_sql('SELECT quantity FROM item_list WHERE id=?', [$item])->fetch_assoc()['quantity'] === '0.0000', 'Mid-receipt failures do not change physical stock');
$first = $staff->receive($receive);
po_check($staff->receive($receive) === $first, 'Receipt retries do not duplicate stock or purchases');
po_fails(fn() => $staff->receive(array_replace($receive, ['notes' => 'Changed payload'])), 'request key');
$order = $maker->order($id);
po_check($order['status'] === 'part_received' && $order['lines'][0]['received'] === '0.5000', 'Partial receiving retains the outstanding quantity');
po_check($order['receipts'][0]['subtotal'] === '0.17' && $order['receipts'][0]['tax'] === '0.02', 'Partial receipt rounds line and tax once');
po_check(po_sql('SELECT quantity FROM item_list WHERE id=?', [$item])->fetch_assoc()['quantity'] === '0.5000', 'Receipt updates the existing inventory ledger');
$purchase = (int)$order['receipts'][0]['transaction_id'];
po_check(po_sql('SELECT total_purchases FROM summary_daily_metrics WHERE metric_date=?', ['2198-03-11'])->fetch_assoc()['total_purchases'] === '0.17', 'Receipt updates legacy daily purchase summaries');
po_fails(fn() => po_sql('UPDATE transactions SET total_amount=9 WHERE id=?', [$purchase]), 'goods receipt');
po_fails(fn() => po_sql('DELETE FROM transactions WHERE id=?', [$purchase]), 'goods receipt');
po_fails(fn() => po_sql('UPDATE transaction_items SET quantity=9 WHERE transaction_id=?', [$purchase]), 'immutable');
po_fails(fn() => po_sql('DELETE FROM transaction_items WHERE transaction_id=?', [$purchase]), 'immutable');
po_fails(fn() => po_sql('INSERT INTO transaction_items (transaction_id,item_id,quantity) VALUES (?,?,1)', [$purchase,$item]), 'immutable');
po_fails(fn() => po_sql('DELETE FROM po_receipts WHERE id=?', [$first]), 'immutable');
po_fails(fn() => po_sql("UPDATE po_receipts SET notes='changed' WHERE id=?", [$first]), 'immutable');
po_fails(fn() => po_sql('UPDATE po_receipt_lines SET quantity=1 WHERE receipt_id=?', [$first]), 'immutable');
po_fails(fn() => po_sql('DELETE FROM po_receipt_seals WHERE receipt_id=?', [$first]), 'immutable');
po_fails(fn() => po_sql('INSERT INTO po_receipt_lines (receipt_id,order_line_id,quantity,line_total) VALUES (?,?,1,1)', [$first,$order['lines'][0]['id']]), 'completed receipt');
$ledger->postSource($purchase);
po_check((int)po_sql('SELECT COUNT(*) AS count FROM gl_journals WHERE source_transaction_id=? AND status=?', [$purchase,'posted'])->fetch_assoc()['count'] === 1, 'Received purchase posts to the existing general ledger');
po_fails(fn() => $maker->transition($id, 'cancel', $order['version'], 'Cannot erase receipts'), 'cannot be cancelled');
$secondInput = array_replace($receive, ['request_key' => po_key(),'lines' => [['order_line_id' => $order['lines'][0]['id'],'quantity' => '2.5']]]);
po_fails(fn() => $staff->receive($secondInput), 'invoice');
$secondInput['invoice_number'] .= '-2';
$second = $staff->receive($secondInput);
$order = $maker->order($id);
po_check($order['status'] === 'received' && $order['lines'][0]['outstanding'] === '0.0000', 'Final receipt completes the order');
po_check(bcadd($order['receipts'][0]['subtotal'], $order['receipts'][1]['subtotal'], 2) === '1.01'
    && bcadd($order['receipts'][0]['tax'], $order['receipts'][1]['tax'], 2) === '0.13', 'All partial receipt amounts and taxes reconcile exactly to approved totals');
po_fails(fn() => $staff->receive(array_replace($receive, ['request_key' => po_key(),'invoice_number' => 'EXTRA-' . $suffix])), 'approved');
po_fails(fn() => $maker->transition($id, 'close', $order['version'], 'Already complete'), 'partially');

// Approval policy is evaluated on submission, then retained with the pending order.
$policy = $maker->policy();
po_fails(fn() => $staff->savePolicy(['revision' => $policy['revision'],'approval_threshold' => '100','reason' => 'Invalid staff update']), 'Administrator');
$maker->savePolicy(['revision' => $policy['revision'],'approval_threshold' => '100','reason' => 'Low value orders may be auto-approved']);
po_fails(fn() => $maker->savePolicy(['revision' => $policy['revision'],'approval_threshold' => '0','reason' => 'Stale policy']), 'changed');
$smallDraft = array_replace($draft, ['request_key' => po_key(),'tax_rate' => '0','lines' => [['item_id' => $item2,'quantity' => '5','unit_price' => '1']]]);
$small = $staff->save($smallDraft); $smallOrder = $staff->order($small);
po_fails(fn() => $other->save(array_replace($smallDraft, ['id' => $small,'version' => $smallOrder['version']])), 'creator');
$staff->transition($small, 'submit', $smallOrder['version']);
$smallOrder = $staff->order($small);
po_check($smallOrder['status'] === 'approved' && !$smallOrder['approval_required'] && $smallOrder['approved_by'] === null, 'Below-threshold approval is recorded as policy-based, not a fabricated approver');
$smallReceipt = ['order_id' => $small,'request_key' => po_key(),'received_date' => '2198-03-12','invoice_number' => 'SMALL-' . $suffix,
    'lines' => [['order_line_id' => $smallOrder['lines'][0]['id'],'quantity' => '2']]];
$staff->receive($smallReceipt); $smallOrder = $staff->order($small);
$beforeClose = array_column($staff->replenishment($vendor), null, 'id');
po_check($beforeClose[$item2]['on_order'] === '3.0000' && $beforeClose[$item2]['suggested'] === '15.0000', 'Replenishment includes physical stock plus remaining approved quantities');
$staff->transition($small, 'close', $smallOrder['version'], 'Supplier discontinued the remaining stock');
$afterClose = array_column($staff->replenishment($vendor), null, 'id');
po_check($afterClose[$item2]['on_order'] === '0.0000' && $afterClose[$item2]['suggested'] === '18.0000', 'Closing short releases outstanding inbound quantities without changing received stock');
$replenishInput = ['vendor_id' => $vendor,'request_key' => po_key(),'selected_items' => [$item2]];
$replenishmentOrder = $staff->createReplenishment($replenishInput);
po_check($staff->order($replenishmentOrder)['lines'][0]['quantity'] === '18.0000', 'Replenishment creates a supplier-specific draft for the actual deficit');
po_check($staff->createReplenishment($replenishInput) === $replenishmentOrder, 'Replenishment retries return the same draft');
po_fails(fn() => $staff->createReplenishment(array_replace($replenishInput, ['selected_items' => [$item]])), 'Request key');
po_fails(fn() => $staff->createReplenishment(array_replace($replenishInput, ['request_key' => po_key()])), 'changed');
$afterDraft = array_column($staff->replenishment($vendor), null, 'id');
po_check($afterDraft[$item2]['planned'] === '18.0000' && $afterDraft[$item2]['suggested'] === '0.0000', 'Draft replenishments prevent duplicate suggestions');
$replenishment = $staff->order($replenishmentOrder);
$staff->transition($replenishmentOrder, 'cancel', $replenishment['version'], 'Regression cleanup');
po_check($staff->order($replenishmentOrder)['status'] === 'cancelled', 'Draft cancellation retains its history');
po_fails(fn() => po_sql('DELETE FROM po_orders WHERE id=?', [$replenishmentOrder]), 'history');

$policy = $maker->policy();
$maker->savePolicy(['revision' => $policy['revision'],'approval_threshold' => '0','approver_id' => $users['checker'],'reason' => 'All orders require designated approval']);
$designated = $maker->save(array_replace($smallDraft, ['request_key' => po_key()]));
$maker->transition($designated, 'submit', $maker->order($designated)['version']);
$designatedOrder = $maker->order($designated);
po_check((int)$designatedOrder['required_approver_id'] === $users['checker'], 'Designated approver is snapshotted at submission');
po_fails(fn() => $staff->reassign($designated, $designatedOrder['version'], $users['maker'], 'Not authorized'), 'Administrator');
po_fails(fn() => $maker->reassign($designated, $designatedOrder['version'], $users['maker'], 'Self routing'), 'independent');
$maker->reassign($designated, $designatedOrder['version'], 1, 'Designated approver unavailable');
$designatedOrder = $maker->order($designated);
po_fails(fn() => $checker->transition($designated, 'approve', $designatedOrder['version']), 'different approver');
$maker->reassign($designated, $designatedOrder['version'], $users['checker'], 'Restore checker');
$designatedOrder = $maker->order($designated);
po_check((int)$designatedOrder['required_approver_id'] === $users['checker'], 'Approval reassignment is explicit and retains independent authorization');
$policy = $maker->policy();
$maker->savePolicy(['revision' => $policy['revision'],'approval_threshold' => '999','reason' => 'Policy changed after submission']);
po_fails(fn() => $staff->receive(['order_id' => $designated,'request_key' => po_key(),'received_date' => '2198-03-12','invoice_number' => 'PENDING-' . $suffix,'lines' => [['order_line_id' => $designatedOrder['lines'][0]['id'],'quantity' => '1']]]), 'approved');
po_check($maker->order($designated)['status'] === 'pending', 'Changing policy never silently approves an already-submitted order');
$checker->transition($designated, 'approve', $designatedOrder['version']);

// Independent PHP processes race the same receiving request.
$raceOrder = $maker->save(array_replace($smallDraft, ['request_key' => po_key()]));
$maker->transition($raceOrder, 'submit', $maker->order($raceOrder)['version']);
$race = $maker->order($raceOrder);
$raceInput = ['order_id' => $raceOrder,'request_key' => po_key(),'received_date' => '2198-03-13','invoice_number' => 'RACE-' . $suffix,'lines' => [['order_line_id' => $race['lines'][0]['id'],'quantity' => '5']]];
$workers = [];
for ($i = 0; $i < 2; $i++) {
    $pipes = [];
    $process = proc_open([PHP_BINARY,__DIR__ . '/procurement_worker.php',(string)$users['staff'],base64_encode(json_encode($raceInput))], [0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']], $pipes);
    fclose($pipes[0]); $workers[] = [$process,$pipes];
}
$results = [];
foreach ($workers as [$process,$pipes]) {
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    po_check(proc_close($process) === 0, 'Concurrent receiver completes: ' . $errors);
    $results[] = trim($output);
}
po_check($results[0] === $results[1] && ctype_digit($results[0]), 'Concurrent retries return one receipt');
po_check(count($maker->order($raceOrder)['receipts']) === 1, 'Concurrent receiving records one supplier purchase');
po_check(po_sql('SELECT quantity FROM item_list WHERE id=?', [$item2])->fetch_assoc()['quantity'] === '7.0000', 'Concurrent receiving never doubles stock');
po_check(po_sql('SELECT COUNT(*) AS count FROM erp_audit_events WHERE object_type=? AND object_id=? AND action=?', ['purchase_order',$raceOrder,'receive_order'])->fetch_assoc()['count'] == 1, 'Concurrent retries write one receiving audit event');
$policy = $maker->policy();
$maker->savePolicy(['revision' => $policy['revision'],'approval_threshold' => '0','reason' => 'Restore independent approval default']);
po_check(count($maker->order($id)['events']) >= 6, 'Order history retains creation, rejection, resubmission, approval, and receiving');
echo "Passed $checks procurement checks.\n";
