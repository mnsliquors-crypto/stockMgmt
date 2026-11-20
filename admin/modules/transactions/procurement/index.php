<?php
if (!isset($conn)) require_once __DIR__ . '/../../../../config.php';
require_once base_app . 'classes/Procurement/OrderService.php';
use App\Procurement\OrderService;

if (!$conn->query("SHOW TABLES LIKE 'po_orders'")->num_rows) {
    http_response_code(503);
    echo '<div class="alert alert-warning">Purchasing setup is pending.</div>';
    return;
}
$procurement = new OrderService($conn, (int)$_settings->userdata('id'));
$isAdmin = (int)$_settings->userdata('type') === 1;
$actor = (int)$_settings->userdata('id');
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$number = static fn($value) => str_contains((string)$value, '.') ? (rtrim(rtrim((string)$value, '0'), '.') ?: '0') : (string)$value;
$label = static fn($value) => ucwords(str_replace('_', ' ', $value));
$url = base_url . 'admin/?page=transactions/procurement';
$tabs = ['orders' => 'Purchase Orders', 'approvals' => 'Approvals', 'receipts' => 'Goods Receipts', 'replenishment' => 'Replenishment', 'policy' => 'Approval Policy'];
$view = is_string($_GET['view'] ?? null) && isset($tabs[$_GET['view']]) ? $_GET['view'] : 'orders';
$suppliers = $procurement->suppliers();
$order = null;
if ($view === 'orders' && !empty($_GET['id'])) {
    try { $order = $procurement->order((int)$_GET['id']); }
    catch (RuntimeException $error) { http_response_code(404); echo '<div class="alert alert-warning">' . $escape($error->getMessage()) . '</div>'; return; }
}
$editable = !$order || (in_array($order['status'], ['draft','rejected'], true) && ($isAdmin || (int)$order['created_by'] === $actor));
$canOwn = $order && ($isAdmin || (int)$order['created_by'] === $actor);
$canApprove = $order && $isAdmin && $order['status'] === 'pending' && (int)$order['created_by'] !== $actor && (!$order['required_approver_id'] || (int)$order['required_approver_id'] === $actor);
?>
<link rel="stylesheet" href="<?php echo base_url ?>dist/css/procurement.css?v=1">
<div class="procurement-workspace py-3">
    <div class="po-print-brand"><?php $printLogo = validate_image($_settings->info('logo')); if (!str_contains($printLogo, 'no-image-available')): ?><img src="<?php echo $escape($printLogo) ?>" alt="Company logo"><?php endif ?><div><h3><?php echo $escape($_settings->info('name') ?: 'Company') ?></h3><span>Purchase Order</span></div></div>
    <?php if ($order): ?><section class="po-print-document">
        <h4><?php echo $escape($order['reference']) ?></h4>
        <dl class="row"><dt class="col-2">Supplier</dt><dd class="col-10"><?php echo $escape($order['vendor_name']) ?></dd><dt class="col-2">Order Date</dt><dd class="col-4"><?php echo $order['order_date'] ?></dd><dt class="col-2">Expected</dt><dd class="col-4"><?php echo $escape($order['expected_date'] ?? '') ?></dd></dl>
        <table class="table table-sm table-bordered"><thead><tr><th>Item</th><th class="text-right">Quantity</th><th>Unit</th><th class="text-right">Unit Price</th><th class="text-right">Amount</th></tr></thead><tbody><?php foreach ($order['lines'] as $line): ?><tr><td><?php echo $escape($line['item_name']) ?></td><td class="text-right"><?php echo $number($line['quantity']) ?></td><td><?php echo $escape($line['unit']) ?></td><td class="text-right"><?php echo $number($line['unit_price']) ?></td><td class="text-right"><?php echo $line['line_total'] ?></td></tr><?php endforeach ?></tbody><tfoot><tr><th colspan="4">Subtotal</th><td class="text-right"><?php echo $order['subtotal'] ?></td></tr><tr><th colspan="4">Tax (<?php echo $number($order['tax_rate']) ?>%)</th><td class="text-right"><?php echo $order['tax'] ?></td></tr><tr><th colspan="4">Total (NPR)</th><th class="text-right"><?php echo $order['total'] ?></th></tr></tfoot></table>
        <?php if ($order['notes'] !== ''): ?><h6>Notes</h6><p class="po-print-notes"><?php echo $escape($order['notes']) ?></p><?php endif ?>
    </section><?php endif ?>
    <div class="procurement-heading mb-3">
        <div><h4 class="mb-1">Purchasing</h4><span class="small text-muted">NPR</span></div>
        <a class="btn btn-primary btn-sm procurement-no-print" href="<?php echo $escape($url) ?>&new=1"><i class="fas fa-plus mr-1"></i> New Order</a>
    </div>
    <ul class="nav nav-tabs mb-3 procurement-no-print">
        <?php foreach ($tabs as $key => $name): ?><li class="nav-item"><a class="nav-link <?php echo $view === $key ? 'active' : '' ?>" href="<?php echo $escape($url) ?>&view=<?php echo $key ?>"><?php echo $name ?></a></li><?php endforeach ?>
    </ul>
    <div id="procurement-message" role="alert" aria-live="polite"></div>

    <?php if ($view === 'orders' && ($order || isset($_GET['new']))):
        $items = $procurement->items();
        $lines = $order['lines'] ?? [['item_id' => '', 'quantity' => '1', 'unit_price' => '', 'unit' => '']];
        if ($order && !$editable) {
            $snapshots = array_column($lines, 'item_name', 'item_id');
            foreach ($items as &$item) if (isset($snapshots[$item['id']])) $item['name'] = $snapshots[$item['id']];
            unset($item);
            foreach ($suppliers as &$supplier) if ($supplier['id'] == $order['vendor_id']) $supplier['display_name'] = $order['vendor_name'];
            unset($supplier);
        }
    ?>
    <form id="purchase-order-form" data-editable="<?php echo $editable ? '1' : '0' ?>">
        <input type="hidden" name="id" value="<?php echo $order['id'] ?? '' ?>">
        <input type="hidden" name="version" value="<?php echo $order['version'] ?? '' ?>">
        <input type="hidden" name="request_key" value="<?php echo $escape($order['request_key'] ?? bin2hex(random_bytes(20))) ?>">
        <div class="procurement-heading mb-3">
            <h5 class="mb-0"><?php echo $escape($order['reference'] ?? 'New Purchase Order') ?> <?php if ($order): ?><span class="badge badge-<?php echo in_array($order['status'], ['approved','received'], true) ? 'success' : 'secondary' ?>"><?php echo $label($order['status']) ?></span><?php endif ?></h5>
            <div class="procurement-actions procurement-no-print"><a class="btn btn-link btn-sm" href="<?php echo $escape($url) ?>">All Orders</a><?php if ($order): ?><button class="btn btn-outline-secondary btn-sm" type="button" id="print-purchase-order" title="Print purchase order" aria-label="Print purchase order"><i class="fas fa-print"></i></button><?php endif ?></div>
        </div>
        <fieldset <?php echo $editable ? '' : 'disabled' ?>>
            <div class="row">
                <div class="form-group col-md-6"><label for="po-vendor">Supplier</label><select class="form-control form-control-sm" id="po-vendor" name="vendor_id" required><option value="">Select supplier</option><?php foreach ($suppliers as $supplier): ?><option value="<?php echo $supplier['id'] ?>" <?php echo ($order['vendor_id'] ?? '') == $supplier['id'] ? 'selected' : '' ?>><?php echo $escape($supplier['display_name']) ?></option><?php endforeach ?><?php if ($order && !in_array($order['vendor_id'], array_column($suppliers, 'id'))): ?><option selected value="<?php echo $order['vendor_id'] ?>"><?php echo $escape($order['vendor_name']) ?> (Inactive)</option><?php endif ?></select></div>
                <div class="form-group col-md-3"><label for="po-order-date">Order Date</label><input class="form-control form-control-sm" id="po-order-date" name="order_date" type="date" required value="<?php echo $escape($order['order_date'] ?? date('Y-m-d')) ?>"></div>
                <div class="form-group col-md-3"><label for="po-expected-date">Expected Delivery</label><input class="form-control form-control-sm" id="po-expected-date" name="expected_date" type="date" value="<?php echo $escape($order['expected_date'] ?? '') ?>"></div>
            </div>
            <div class="table-responsive"><table class="table table-sm table-bordered" id="po-lines"><thead class="thead-light"><tr><th class="po-item-column">Item</th><th class="po-quantity-column text-right">Quantity</th><th>Unit</th><th class="po-money-column text-right">Unit Price</th><th class="po-money-column text-right">Amount</th><?php if ($editable): ?><th class="po-action-column"></th><?php endif ?></tr></thead>
                <tbody><?php foreach ($lines as $line): ?><tr data-unit-code="<?php echo bccomp((string)($line['unit_factor'] ?? '1'), '1', 4) > 0 ? 'case' : 'base' ?>">
                    <td><select class="form-control form-control-sm po-item" required><option value="">Select item</option><?php foreach ($items as $item): ?><option value="<?php echo $item['id'] ?>" data-cost="<?php echo $escape($item['cost']) ?>" data-unit="<?php echo $escape($item['unit'] ?? '') ?>" <?php echo $line['item_id'] == $item['id'] ? 'selected' : '' ?>><?php echo $escape($item['name']) ?></option><?php endforeach ?><?php if ($line['item_id'] && !in_array($line['item_id'], array_column($items, 'id'))): ?><option selected value="<?php echo $line['item_id'] ?>"><?php echo $escape($line['item_name']) ?> (Inactive)</option><?php endif ?></select></td>
                    <td><input class="form-control form-control-sm po-quantity text-right" type="number" min="0.0001" step="0.0001" required value="<?php echo $escape($line['quantity']) ?>"></td>
                    <td class="po-unit text-nowrap"><?php echo $escape($line['unit']) ?></td>
                    <td><input class="form-control form-control-sm po-price text-right" type="number" min="0.0001" step="0.0001" required value="<?php echo $escape($line['unit_price']) ?>"></td>
                    <td class="po-line-total text-right text-nowrap"><?php echo $escape($line['line_total'] ?? '0.00') ?></td>
                    <?php if ($editable): ?><td><button class="btn btn-link btn-sm text-danger po-remove-line" type="button" title="Remove item" aria-label="Remove item"><i class="fas fa-trash-alt"></i></button></td><?php endif ?>
                </tr><?php endforeach ?></tbody>
            </table></div>
            <div class="row">
                <div class="col-md-8"><div class="form-group"><label for="po-notes">Notes</label><textarea class="form-control form-control-sm" id="po-notes" name="notes" rows="3" maxlength="2000"><?php echo $escape($order['notes'] ?? '') ?></textarea></div></div>
                <div class="col-md-4"><div class="form-group"><label for="po-tax-rate">Tax Rate (%)</label><input class="form-control form-control-sm" id="po-tax-rate" name="tax_rate" type="number" min="0" max="100" step="0.0001" value="<?php echo $escape($order['tax_rate'] ?? '0') ?>" required></div>
                    <dl class="po-totals"><dt>Subtotal</dt><dd id="po-subtotal"><?php echo $escape($order['subtotal'] ?? '0.00') ?></dd><dt>Tax</dt><dd id="po-tax"><?php echo $escape($order['tax'] ?? '0.00') ?></dd><dt>Total (NPR)</dt><dd id="po-total"><?php echo $escape($order['total'] ?? '0.00') ?></dd></dl>
                </div>
            </div>
        </fieldset>
        <div class="procurement-heading procurement-no-print mb-4">
            <div><?php if ($editable): ?><button class="btn btn-sm btn-outline-secondary" id="po-add-line" type="button"><i class="fas fa-plus mr-1"></i> Add Item</button><?php endif ?></div>
            <div class="procurement-actions">
                <?php if ($editable): ?><button class="btn btn-sm btn-primary" type="submit"><i class="fas fa-save mr-1"></i> Save Draft</button><?php endif ?>
                <?php if ($order && $canOwn && in_array($order['status'], ['draft','rejected'], true)): ?><button class="btn btn-sm btn-success po-transition" type="button" data-action="submit"><i class="fas fa-paper-plane mr-1"></i> Submit</button><?php endif ?>
                <?php if ($canApprove): ?><button class="btn btn-sm btn-success po-transition" type="button" data-action="approve"><i class="fas fa-check mr-1"></i> Approve</button><button class="btn btn-sm btn-outline-danger po-reason" type="button" data-action="reject">Reject</button><?php endif ?>
                <?php if ($order && $canOwn && in_array($order['status'], ['draft','rejected','pending','approved'], true)): ?><button class="btn btn-sm btn-outline-danger po-reason" type="button" data-action="cancel">Cancel Order</button><?php endif ?>
                <?php if ($order && $canOwn && $order['status'] === 'part_received'): ?><button class="btn btn-sm btn-outline-danger po-reason" type="button" data-action="close">Close Remaining</button><?php endif ?>
            </div>
        </div>
    </form>
    <?php if ($order): ?><div class="po-order-operations">
        <dl class="row border-top pt-3 mb-3"><dt class="col-sm-2">Created By</dt><dd class="col-sm-4"><?php echo $escape($order['created_username']) ?></dd><dt class="col-sm-2">Approval</dt><dd class="col-sm-4"><?php echo $escape($order['approved_username'] ?? ($order['approval_required'] ? ($order['required_approver'] ?? 'Independent administrator') : 'Below threshold')) ?></dd></dl>
        <?php if ($isAdmin && $order['status'] === 'pending'): $routing = $procurement->policy(); ?>
            <form id="po-reassign-form" class="row align-items-end border-bottom pb-3 mb-3 procurement-no-print"><input type="hidden" name="id" value="<?php echo $order['id'] ?>"><input type="hidden" name="version" value="<?php echo $order['version'] ?>"><div class="form-group col-md-4 mb-md-0"><label for="po-reassign-approver">Reassign Approver</label><select class="form-control form-control-sm" id="po-reassign-approver" name="approver_id" required><option value="">Select independent administrator</option><?php foreach ($routing['administrators'] as $admin): if ((int)$admin['id'] === (int)$order['created_by']) continue; ?><option value="<?php echo $admin['id'] ?>" <?php echo $admin['id'] == $order['required_approver_id'] ? 'selected' : '' ?>><?php echo $escape($admin['username']) ?></option><?php endforeach ?></select></div><div class="form-group col-md-5 mb-md-0"><label for="po-reassign-reason">Reason</label><input class="form-control form-control-sm" id="po-reassign-reason" name="reason" maxlength="800" required></div><div class="col-md-3"><button class="btn btn-outline-primary btn-sm" type="submit"><i class="fas fa-user-check mr-1"></i> Reassign</button></div></form>
        <?php endif ?>
        <h5 class="mt-4">Receiving</h5>
        <?php $receivable = in_array($order['status'], ['approved','part_received'], true); ?>
        <form id="po-receive-form" class="mb-4" data-separate="<?php echo !empty($purchase_cycle_available)?'1':'0' ?>" data-order-id="<?php echo $order['id'] ?>">
            <input type="hidden" name="order_id" value="<?php echo $order['id'] ?>"><input type="hidden" name="request_key" value="<?php echo bin2hex(random_bytes(20)) ?>">
            <div class="table-responsive"><table class="table table-sm table-striped" id="po-receiving-lines"><thead><tr><th>Item</th><th class="text-right">Ordered</th><th class="text-right">Received</th><th class="text-right">Outstanding</th><?php if(!empty($purchase_cycle_available)): ?><th class="text-right">Billed</th><th class="text-right">Unbilled</th><?php endif ?><?php if ($receivable): ?><th class="po-quantity-column text-right">Receive Now</th><?php endif ?></tr></thead><tbody>
                <?php foreach ($order['lines'] as $line): ?><tr data-line-id="<?php echo $line['id'] ?>"><td><?php echo $escape($line['item_name']) ?></td><td class="text-right"><?php echo $number($line['quantity']) ?></td><td class="text-right"><?php echo $number($line['received']) ?></td><td class="text-right"><?php echo $number($line['outstanding']) ?></td><?php if(!empty($purchase_cycle_available)): ?><td class="text-right"><?php echo $number($line['billed']) ?></td><td class="text-right"><?php echo $number($line['unbilled']) ?></td><?php endif ?><?php if ($receivable): ?><td><input class="form-control form-control-sm text-right po-receive-quantity" aria-label="Receive <?php echo $escape($line['item_name']) ?>" type="number" min="0" max="<?php echo $escape($line['outstanding']) ?>" step="0.0001" value="0" required></td><?php endif ?></tr><?php endforeach ?>
            </tbody></table></div>
            <?php if ($receivable): ?><div class="row procurement-no-print"><div class="form-group col-md-3"><label for="po-received-date">Receipt Date</label><input class="form-control form-control-sm" id="po-received-date" name="received_date" type="date" min="<?php echo $order['order_date'] ?>" required value="<?php echo date('Y-m-d') ?>"></div><div class="form-group col-md-3"><label for="po-invoice"><?php echo !empty($purchase_cycle_available)?'Delivery Reference':'Supplier Invoice Number' ?></label><input class="form-control form-control-sm" id="po-invoice" name="<?php echo !empty($purchase_cycle_available)?'delivery_reference':'invoice_number' ?>" maxlength="100" <?php echo empty($purchase_cycle_available)?'required':'' ?>></div><div class="form-group col-md-6"><label for="po-receipt-notes">Receipt Notes</label><input class="form-control form-control-sm" id="po-receipt-notes" name="notes" maxlength="2000"></div></div>
                <div class="procurement-actions justify-content-end procurement-no-print"><button class="btn btn-sm btn-outline-secondary" type="button" id="po-fill-outstanding" title="Fill outstanding quantities"><i class="fas fa-arrow-down mr-1"></i> Fill Outstanding</button><button class="btn btn-sm btn-success" type="submit"><i class="fas fa-truck-loading mr-1"></i> <?php echo !empty($purchase_cycle_available)?'Receive Items':'Receive &amp; Record Purchase' ?></button></div>
            <?php endif ?>
        </form>
        <?php if(!empty($purchase_cycle_available)):
            require_once base_app.'classes/Procurement/CycleService.php';$linkedCycle=new App\Procurement\CycleService($conn,$actor); ?>
        <div class="procurement-heading mb-2"><h5>Item Receipts &amp; Bills</h5><a class="btn btn-sm btn-outline-primary" href="<?php echo base_url ?>admin/?page=transactions/vendor_bills&amp;new=1&amp;order_id=<?php echo $order['id'] ?>">New Supplier Bill</a></div>
        <div class="table-responsive mb-3"><table class="table table-sm"><thead><tr><th>Document</th><th>Date</th><th>Status</th><th class="text-right">Value (NPR)</th></tr></thead><tbody>
        <?php foreach($linkedCycle->receipts((int)$order['id']) as $r): ?><tr><td><a href="<?php echo base_url ?>admin/?page=transactions/item_receipts&amp;id=<?php echo $r['id'] ?>"><?php echo $escape($r['reference']) ?></a></td><td><?php echo $r['received_date'] ?></td><td><?php echo $r['reversal_of']?'Reversal':($r['reversal_id']?'Reversed':'Received') ?></td><td class="text-right"><?php echo $r['subtotal'] ?></td></tr><?php endforeach ?>
        <?php if(App\Enterprise\Access::allowed($conn,$actor,'purchases'))foreach($linkedCycle->bills((int)$order['id']) as $b): ?><tr><td><a href="<?php echo base_url ?>admin/?page=transactions/vendor_bills&amp;id=<?php echo $b['id'] ?>"><?php echo $escape($b['reference']) ?></a></td><td><?php echo $b['bill_date'] ?></td><td><?php echo $escape(ucfirst($b['status'])) ?></td><td class="text-right"><?php echo $b['total'] ?></td></tr><?php endforeach ?>
        </tbody></table></div><?php endif ?>
        <h5>Combined Purchase Receipts</h5>
        <div class="table-responsive mb-4"><table class="table table-sm table-striped"><thead><tr><th>Receipt</th><th>Date</th><th>Supplier Invoice</th><th>Received By</th><th class="text-right">Subtotal</th><th class="text-right">Tax</th><th class="text-right">Total (NPR)</th></tr></thead><tbody><?php foreach ($order['receipts'] as $receipt): ?><tr><td><a href="<?php echo base_url ?>admin/?page=transactions/purchases/view_purchase&id=<?php echo $receipt['transaction_id'] ?>"><?php echo $escape($receipt['reference']) ?></a></td><td class="text-nowrap"><?php echo $receipt['received_date'] ?></td><td><?php echo $escape($receipt['invoice_number']) ?></td><td><?php echo $escape($receipt['created_username']) ?></td><td class="text-right"><?php echo $receipt['subtotal'] ?></td><td class="text-right"><?php echo $receipt['tax'] ?></td><td class="text-right"><?php echo $receipt['total'] ?></td></tr><?php endforeach ?><?php if (!$order['receipts']): ?><tr><td colspan="7" class="text-center text-muted py-3">No goods received.</td></tr><?php endif ?></tbody></table></div>
        <h5>Order History</h5><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Timestamp</th><th>User</th><th>Action</th><th>Reason</th></tr></thead><tbody><?php foreach ($order['events'] as $event): $details = json_decode($event['details'], true); ?><tr><td class="text-nowrap"><?php echo $event['created_at'] ?></td><td><?php echo $escape($details['actor_username'] ?? '#' . $event['actor_id']) ?></td><td><?php echo $label($event['action']) ?></td><td><?php echo $escape($details['reason'] ?? '') ?></td></tr><?php endforeach ?></tbody></table></div></div>
    <?php endif ?>

    <?php elseif (in_array($view, ['orders','approvals'], true)):
        $status = $view === 'approvals' ? 'pending' : (is_string($_GET['status'] ?? null) ? $_GET['status'] : '');
        $search = is_string($_GET['q'] ?? null) ? mb_substr($_GET['q'], 0, 120) : '';
        try { $listing = $procurement->orders($status, $search, (int)($_GET['p'] ?? 1)); }
        catch (InvalidArgumentException $error) { $status = ''; $listing = $procurement->orders('', $search); }
    ?>
        <form class="row align-items-end mb-3" method="get"><input type="hidden" name="page" value="transactions/procurement"><input type="hidden" name="view" value="<?php echo $view ?>">
            <div class="form-group col-md-5 mb-md-0"><label for="po-search">Order / Supplier</label><input class="form-control form-control-sm" id="po-search" name="q" maxlength="120" value="<?php echo $escape($search) ?>" type="search"></div>
            <?php if ($view === 'orders'): ?><div class="form-group col-md-3 mb-md-0"><label for="po-status">Status</label><select class="form-control form-control-sm" id="po-status" name="status"><option value="">All Statuses</option><?php foreach (['draft','pending','rejected','approved','part_received','received','closed','cancelled'] as $state): ?><option value="<?php echo $state ?>" <?php echo $state === $status ? 'selected' : '' ?>><?php echo $label($state) ?></option><?php endforeach ?></select></div><?php endif ?>
            <div class="col-md-2"><button class="btn btn-sm btn-outline-primary" type="submit"><i class="fas fa-filter mr-1"></i> Apply</button></div>
        </form>
        <div class="table-responsive"><table class="table table-sm table-striped table-hover"><thead><tr><th>Order</th><th>Date</th><th>Expected</th><th>Supplier</th><th>Status</th><th>Created By</th><th class="text-right">Total (NPR)</th></tr></thead><tbody><?php foreach ($listing['rows'] as $row): ?><tr><td class="text-nowrap"><a href="<?php echo $escape($url) ?>&id=<?php echo $row['id'] ?>"><?php echo $escape($row['reference']) ?></a></td><td class="text-nowrap"><?php echo $row['order_date'] ?></td><td class="text-nowrap <?php echo $row['expected_date'] && $row['expected_date'] < date('Y-m-d') && in_array($row['status'], ['approved','part_received']) ? 'text-danger' : '' ?>"><?php echo $escape($row['expected_date'] ?? '') ?></td><td><?php echo $escape($row['vendor_name']) ?></td><td><?php echo $label($row['status']) ?></td><td><?php echo $escape($row['created_username']) ?></td><td class="text-right"><?php echo $row['total'] ?></td></tr><?php endforeach ?><?php if (!$listing['rows']): ?><tr><td colspan="7" class="text-center text-muted py-4"><?php echo $view === 'approvals' ? 'No orders awaiting approval.' : 'No purchase orders.' ?></td></tr><?php endif ?></tbody></table></div>
        <div class="procurement-heading"><span class="text-muted small"><?php echo $listing['count'] ?> orders <span class="mx-1">|</span> Page <?php echo $listing['page'] ?> of <?php echo $listing['pages'] ?></span><div class="procurement-actions"><?php foreach ([-1 => 'chevron-left',1 => 'chevron-right'] as $direction => $icon): $next = $listing['page'] + $direction; if ($next < 1 || $next > $listing['pages']) continue; ?><a class="btn btn-outline-secondary btn-sm" aria-label="<?php echo $direction < 0 ? 'Previous page' : 'Next page' ?>" title="<?php echo $direction < 0 ? 'Previous page' : 'Next page' ?>" href="<?php echo $escape($url . '&' . http_build_query(['view' => $view,'status' => $status,'q' => $search,'p' => $next])) ?>"><i class="fas fa-<?php echo $icon ?>"></i></a><?php endforeach ?></div></div>

    <?php elseif ($view === 'receipts'): $receipts = $procurement->receipts(); ?>
        <div class="procurement-heading mb-3"><h5 class="mb-0">Goods Receipts</h5><span class="text-muted small">Latest 250</span></div>
        <div class="table-responsive"><table class="table table-sm table-striped table-hover"><thead><tr><th>Receipt / Purchase</th><th>Order</th><th>Date</th><th>Supplier</th><th>Invoice</th><th>Received By</th><th class="text-right">Total (NPR)</th></tr></thead><tbody><?php foreach ($receipts as $receipt): ?><tr><td><a href="<?php echo base_url ?>admin/?page=transactions/purchases/view_purchase&id=<?php echo $receipt['transaction_id'] ?>"><?php echo $escape($receipt['reference']) ?></a></td><td><a href="<?php echo $escape($url) ?>&id=<?php echo $receipt['order_id'] ?>"><?php echo $escape($receipt['order_reference']) ?></a></td><td class="text-nowrap"><?php echo $receipt['received_date'] ?></td><td><?php echo $escape($receipt['vendor_name']) ?></td><td><?php echo $escape($receipt['invoice_number']) ?></td><td><?php echo $escape($receipt['created_username']) ?></td><td class="text-right"><?php echo $receipt['total'] ?></td></tr><?php endforeach ?><?php if (!$receipts): ?><tr><td colspan="7" class="text-center text-muted py-4">No goods receipts.</td></tr><?php endif ?></tbody></table></div>

    <?php elseif ($view === 'replenishment'):
        $vendorId = empty($_GET['vendor_id']) ? null : (int)$_GET['vendor_id'];
        $replenishment = $procurement->replenishment($vendorId);
    ?>
        <form class="row align-items-end mb-3" method="get"><input type="hidden" name="page" value="transactions/procurement"><input type="hidden" name="view" value="replenishment"><div class="form-group col-md-5 mb-md-0"><label for="po-replenish-vendor">Supplier</label><select class="form-control form-control-sm" id="po-replenish-vendor" name="vendor_id"><option value="">All suppliers</option><?php foreach ($suppliers as $supplier): ?><option value="<?php echo $supplier['id'] ?>" <?php echo $supplier['id'] == $vendorId ? 'selected' : '' ?>><?php echo $escape($supplier['display_name']) ?></option><?php endforeach ?></select></div><div class="col-md-3 procurement-actions"><button class="btn btn-outline-primary btn-sm" type="submit"><i class="fas fa-filter mr-1"></i> Apply</button><a class="btn btn-outline-secondary btn-sm" title="Download replenishment CSV" aria-label="Download replenishment CSV" href="<?php echo base_url ?>classes/Procurement.php?f=export<?php echo $vendorId ? '&vendor_id=' . $vendorId : '' ?>"><i class="fas fa-download"></i></a></div></form>
        <form id="po-replenishment-form"><input type="hidden" name="vendor_id" value="<?php echo $vendorId ?? '' ?>"><input type="hidden" name="request_key" value="<?php echo bin2hex(random_bytes(20)) ?>">
            <div class="table-responsive"><table class="table table-sm table-striped"><thead><tr><th class="po-action-column"><input type="checkbox" id="po-select-all" aria-label="Select all replenishment items" <?php echo $vendorId ? '' : 'disabled' ?>></th><th>Item</th><th>Supplier</th><th class="text-right">On Hand</th><th class="text-right">Reorder</th><th class="text-right">On Order</th><th class="text-right">Draft / Pending</th><th class="text-right">Projected</th><th class="text-right">Suggested</th></tr></thead><tbody><?php foreach ($replenishment as $item): ?><tr><td><input class="po-replenish-check" type="checkbox" name="selected_items[]" value="<?php echo $item['id'] ?>" aria-label="Replenish <?php echo $escape($item['name']) ?>" <?php echo $vendorId && $item['vendor_name'] && bccomp($item['suggested'], '0', 4) > 0 ? '' : 'disabled' ?>></td><td><?php echo $escape($item['name']) ?><small class="text-muted d-block"><?php echo $escape($item['unit'] ?? '') ?></small></td><td><?php echo $escape($item['vendor_name'] ?? 'Unassigned / Inactive') ?></td><?php foreach (['quantity','reorder_level','on_order','planned','projected','suggested'] as $column): ?><td class="text-right <?php echo $column === 'suggested' && bccomp($item['suggested'], '0', 4) > 0 ? 'font-weight-bold text-danger' : '' ?>"><?php echo $number($item[$column]) ?></td><?php endforeach ?></tr><?php endforeach ?><?php if (!$replenishment): ?><tr><td colspan="9" class="text-center text-muted py-4">No items at or below their reorder level.</td></tr><?php endif ?></tbody></table></div>
            <div class="procurement-actions justify-content-end"><button class="btn btn-primary btn-sm" type="submit" <?php echo $vendorId ? '' : 'disabled' ?>><i class="fas fa-file-medical mr-1"></i> Create Draft Order</button></div>
        </form>

    <?php elseif ($view === 'policy'): $policy = $procurement->policy(); ?>
        <h5 class="mb-3">Purchase Order Approval Policy</h5>
        <form id="po-policy-form"><input type="hidden" name="revision" value="<?php echo $policy['revision'] ?>"><fieldset <?php echo $isAdmin ? '' : 'disabled' ?>>
            <div class="row"><div class="form-group col-md-5"><label for="po-approval-threshold">Independent Approval At or Above (NPR)</label><input class="form-control form-control-sm" id="po-approval-threshold" name="approval_threshold" type="number" min="0" step="0.01" value="<?php echo $policy['approval_threshold'] ?>" required></div><div class="form-group col-md-5"><label for="po-designated-approver">Approver</label><select class="form-control form-control-sm" id="po-designated-approver" name="approver_id"><option value="">Any independent administrator</option><?php foreach ($policy['administrators'] as $admin): ?><option value="<?php echo $admin['id'] ?>" <?php echo $admin['id'] == $policy['approver_id'] ? 'selected' : '' ?>><?php echo $escape($admin['username']) ?></option><?php endforeach ?></select></div></div>
            <dl class="row"><dt class="col-md-5">Below Threshold</dt><dd class="col-md-7">Auto-approved on submission</dd><dt class="col-md-5">Self-Approval</dt><dd class="col-md-7">Not permitted for orders requiring approval</dd><dt class="col-md-5">Policy Revision</dt><dd class="col-md-7"><?php echo $policy['revision'] ?></dd></dl>
            <div class="form-group form-check"><input class="form-check-input" id="po-require-orders" name="require_purchase_orders" type="checkbox" value="1" <?php echo $policy['require_purchase_orders'] ? 'checked' : '' ?>><label class="form-check-label" for="po-require-orders">Require purchase orders for direct purchase entry and edits</label></div>
            <?php if ($isAdmin): ?><div class="form-group"><label for="po-policy-reason">Change Reason</label><textarea class="form-control form-control-sm" id="po-policy-reason" name="reason" maxlength="800" rows="2" required></textarea></div><button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-save mr-1"></i> Save Policy</button><?php endif ?>
        </fieldset></form>
    <?php endif ?>
</div>
<div class="modal fade" id="po-reason-modal" tabindex="-1" role="dialog" aria-labelledby="po-reason-title"><div class="modal-dialog" role="document"><form class="modal-content" id="po-reason-form"><div class="modal-header"><h5 class="modal-title" id="po-reason-title">Order Action</h5><button class="close" type="button" data-dismiss="modal" aria-label="Close">&times;</button></div><div class="modal-body"><input type="hidden" name="action"><label for="po-reason">Reason</label><textarea class="form-control" id="po-reason" name="reason" rows="3" maxlength="800" required></textarea><p class="text-danger small mt-2 mb-0 po-reason-error" role="alert"></p></div><div class="modal-footer"><button class="btn btn-secondary btn-sm" type="button" data-dismiss="modal">Cancel</button><button class="btn btn-primary btn-sm" type="submit">Confirm</button></div></form></div></div>
<script src="<?php echo base_url ?>dist/js/procurement.js?v=1"></script>
