<?php
namespace App\Procurement;

require_once __DIR__ . '/../Accounting/Money.php';
require_once __DIR__ . '/../Inventory/StockService.php';
require_once __DIR__ . '/../Enterprise/InventoryService.php';
use App\Accounting\Money;
use App\Inventory\StockService;

final class PermissionException extends \RuntimeException {}
final class ConflictException extends \RuntimeException {}

final class OrderService {
    private array $user;
    private bool $enterprise;

    public function __construct(private \mysqli $db, private int $actor) {
        $this->user = $this->row('SELECT id,username,type FROM users WHERE id=?', [$actor]) ?? [];
        if (!in_array((int)($this->user['type'] ?? 0), [1,2], true)) throw new PermissionException('An active administrator or staff account is required.');
        $this->enterprise=$this->db->query("SHOW TABLES LIKE 'erp_po_context'")->num_rows>0;
    }

    private function query(string $sql, array $parameters = []): \mysqli_result|bool {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        return $statement->get_result();
    }
    private function row(string $sql, array $parameters = []): ?array { return $this->query($sql, $parameters)->fetch_assoc(); }
    private function rows(string $sql, array $parameters = []): array { return $this->query($sql, $parameters)->fetch_all(MYSQLI_ASSOC); }

    private function atomic(callable $work) {
        for ($attempt = 0; ; $attempt++) {
            $this->db->begin_transaction();
            try {
                // One procurement mutex serializes receipt retries and replenishment calculations.
                $this->query('SELECT id FROM po_policy WHERE id=1 FOR UPDATE');
                $result = $work();
                $this->db->commit();
                return $result;
            } catch (\Throwable $error) {
                $this->db->rollback();
                if ($error instanceof \mysqli_sql_exception && in_array($error->getCode(), [1205,1213], true) && $attempt < 2) {
                    usleep(50000 * ($attempt + 1));
                    continue;
                }
                throw $error;
            }
        }
    }

    private function text($value, int $length, string $label, bool $required = false): string {
        if (!is_scalar($value)) throw new \InvalidArgumentException('Invalid ' . $label . '.');
        $value = trim((string)$value);
        if (($required && $value === '') || mb_strlen($value) > $length) throw new \InvalidArgumentException('Enter ' . $label . ' (up to ' . $length . ' characters).');
        return $value;
    }
    private function date($value): string {
        $value = $this->text($value, 10, 'date', true);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Enter a valid date.');
        return $value;
    }
    private function id($value): int {
        if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value < 1 || strlen((string)$value) > 15) throw new \InvalidArgumentException('Invalid record ID.');
        return (int)$value;
    }
    private function amount($value, int $scale = 2): string {
        if (!is_scalar($value)) throw new \InvalidArgumentException('Enter a valid numeric amount.');
        $result = Money::decimal($value, $scale);
        if (bccomp((string)$value, $result, 24) !== 0) throw new \InvalidArgumentException('Use at most ' . $scale . ' decimal places.');
        return $result;
    }
    private function key($value): string {
        $key = $this->text($value, 80, 'request key', true);
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $key)) throw new \InvalidArgumentException('Invalid request key. Refresh the page.');
        return $key;
    }
    private function admin(): void {
        if ((int)$this->user['type'] !== 1) throw new PermissionException('Administrator access required.');
    }
    private function owner(array $order): void {
        if ((int)$order['created_by'] !== $this->actor && (int)$this->user['type'] !== 1) throw new PermissionException('Only the creator or an administrator can change this order.');
    }
    private function locked(int $id): array {
        $order = $this->row('SELECT * FROM po_orders WHERE id=? FOR UPDATE', [$id]);
        if (!$order) throw new \RuntimeException('Purchase order not found.');
        return $order;
    }
    private function version(array $order, $version): void {
        if ((string)$order['version'] !== (string)$version) throw new ConflictException('This order changed in another window. Refresh before continuing.');
    }
    private function audit(string $action, string $type, int $id, array $details = []): void {
        $details['actor_username'] = $this->user['username'];
        $this->query('INSERT INTO erp_audit_events (actor_id,action,object_type,object_id,details) VALUES (?,?,?,?,?)',
            [$this->actor, $action, $type, $id, json_encode($details, JSON_THROW_ON_ERROR)]);
    }

    public function policy(): array {
        $policy = $this->row('SELECT * FROM po_policy WHERE id=1');
        $policy['administrators'] = $this->rows('SELECT id,username FROM users WHERE type=1 ORDER BY username');
        return $policy;
    }
    public function savePolicy(array $input): void {
        $this->admin();
        $this->atomic(function () use ($input) {
            $policy = $this->row('SELECT * FROM po_policy WHERE id=1');
            if ((string)$policy['revision'] !== (string)($input['revision'] ?? '')) throw new ConflictException('The approval policy changed. Refresh before saving.');
            $threshold = $this->amount($input['approval_threshold'] ?? '');
            if (Money::compare($threshold, '0') < 0) throw new \InvalidArgumentException('The approval threshold cannot be negative.');
            $approver = empty($input['approver_id']) ? null : $this->id($input['approver_id']);
            if ($approver && !$this->row('SELECT id FROM users WHERE id=? AND type=1', [$approver])) throw new \InvalidArgumentException('Choose an administrator as approver.');
            $reason = $this->text($input['reason'] ?? '', 800, 'policy change reason', true);
            $requireOrders = $this->text($input['require_purchase_orders'] ?? '0', 1, 'purchase-order requirement');
            if (!in_array($requireOrders, ['0','1'], true)) throw new \InvalidArgumentException('Invalid purchase-order requirement.');
            $this->query('UPDATE po_policy SET approval_threshold=?,approver_id=?,require_purchase_orders=?,revision=revision+1 WHERE id=1', [$threshold, $approver, $requireOrders]);
            $this->audit('change_approval_policy', 'purchase_policy', 1, ['before' => $policy, 'threshold' => $threshold, 'approver_id' => $approver, 'require_purchase_orders' => $requireOrders, 'reason' => $reason]);
        });
    }

    private function saveLocked(array $input): int {
        $id=empty($input['id'])?0:$this->id($input['id']);
        $previousLocation=$id&&$this->enterprise?($this->row('SELECT location_id FROM erp_po_context WHERE order_id=?',[$id])['location_id']??1):1;
        $location=empty($input['location_id'])?(int)$previousLocation:$this->id($input['location_id']);
        if ($this->enterprise&&!$this->row('SELECT id FROM erp_locations WHERE id=? AND active=1',[$location])) throw new \InvalidArgumentException('Choose an active location.');
        $vendor = $this->row("SELECT id,display_name FROM entity_list WHERE id=? AND entity_type='Supplier' AND status=1 LOCK IN SHARE MODE", [$this->id($input['vendor_id'] ?? '')]);
        if (!$vendor) throw new \InvalidArgumentException('Choose an active supplier.');
        $date = $this->date($input['order_date'] ?? '');
        $expected = empty($input['expected_date']) ? null : $this->date($input['expected_date']);
        if ($expected && $expected < $date) throw new \InvalidArgumentException('Expected delivery cannot precede the order date.');
        $notes = $this->text($input['notes'] ?? '', 2000, 'notes');
        $rate = $this->amount($input['tax_rate'] ?? '0', 4);
        if (bccomp($rate, '0', 4) < 0 || bccomp($rate, '100', 4) > 0) throw new \InvalidArgumentException('Tax rate must be between 0 and 100.');
        $lines = $input['lines'] ?? null;
        if (!is_array($lines) || count($lines) < 1 || count($lines) > 100) throw new \InvalidArgumentException('Enter between 1 and 100 order lines.');
        $clean = []; $subtotal = '0.00';
        foreach ($lines as $line) {
            if (!is_array($line)) throw new \InvalidArgumentException('Invalid order line.');
            $item = $this->row('SELECT id,name,unit FROM item_list WHERE id=? AND status=1 LOCK IN SHARE MODE', [$this->id($line['item_id'] ?? '')]);
            if (!$item) throw new \InvalidArgumentException('Choose an active item on each line.');
            if (isset($clean[$item['id']])) throw new \InvalidArgumentException('An item can appear only once per order.');
            $quantity = $this->amount($line['quantity'] ?? '', 4);
            $price = $this->amount($line['unit_price'] ?? '', 4);
            if (bccomp($quantity, '0', 4) <= 0 || bccomp($price, '0', 4) <= 0) throw new \InvalidArgumentException('Every order line requires a positive quantity and unit price.');
            $total = Money::multiply($quantity, $price);
            if (Money::compare($total, '0') <= 0) throw new \InvalidArgumentException('Each order line must have a value of at least 0.01.');
            $subtotal = Money::add($subtotal, $total);
            $unit=$this->enterprise?(new \App\Enterprise\InventoryService($this->db,$this->actor))->unit((int)$item['id'],$this->text($line['unit_code']??'base',10,'unit'),true):['name'=>$item['unit']??'','factor'=>'1.0000'];
            $clean[$item['id']] = ['item_id' => (int)$item['id'], 'item_name' => $item['name'], 'unit' => $unit['name'], 'unit_factor'=>$unit['factor'], 'quantity' => $quantity, 'unit_price' => $price, 'line_total' => $total];
        }
        if (Money::compare($subtotal, '0') <= 0) throw new \InvalidArgumentException('Order subtotal must be greater than zero.');
        $tax = Money::decimal(bcdiv(bcmul($subtotal, $rate, 8), '100', 8));
        $total = Money::decimal(Money::add($subtotal, $tax));
        ksort($clean);
        $hash = hash('sha256', json_encode([$vendor['id'], $date, $expected, $notes, $rate, $clean, $location], JSON_THROW_ON_ERROR));
        if (!$id) {
            $key = $this->key($input['request_key'] ?? '');
            $existing = $this->row('SELECT * FROM po_orders WHERE request_key=? FOR UPDATE', [$key]);
            if ($existing) {
                $this->owner($existing);
                if (!hash_equals($existing['payload_hash'], $hash)) throw new ConflictException('This request key was already used with different order details.');
                return (int)$existing['id'];
            }
            $this->query('INSERT INTO po_orders (reference,vendor_id,vendor_name,order_date,expected_date,notes,tax_rate,subtotal,tax,total,created_by,created_username,request_key,payload_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                ['NEW-' . bin2hex(random_bytes(10)), $vendor['id'], $vendor['display_name'], $date, $expected, $notes, $rate, $subtotal, $tax, $total, $this->actor, $this->user['username'], $key, $hash]);
            $id = (int)$this->db->insert_id;
            $this->query('UPDATE po_orders SET reference=? WHERE id=?', ['PO-' . str_pad((string)$id, 8, '0', STR_PAD_LEFT), $id]);
        } else {
            $order = $this->locked($id); $this->owner($order); $this->version($order, $input['version'] ?? '');
            if (!in_array($order['status'], ['draft','rejected'], true)) throw new \RuntimeException('Only draft or rejected orders can be edited.');
            $this->query('UPDATE po_orders SET vendor_id=?,vendor_name=?,order_date=?,expected_date=?,notes=?,tax_rate=?,subtotal=?,tax=?,total=?,payload_hash=?,version=version+1 WHERE id=?',
                [$vendor['id'], $vendor['display_name'], $date, $expected, $notes, $rate, $subtotal, $tax, $total, $hash, $id]);
            $this->query('DELETE FROM po_lines WHERE order_id=?', [$id]);
        }
        if ($this->enterprise) $this->query('INSERT INTO erp_po_context(order_id,location_id) VALUES (?,?) ON DUPLICATE KEY UPDATE location_id=VALUES(location_id)',[$id,$location]);
        foreach ($clean as $line) {
            $this->query('INSERT INTO po_lines (order_id,item_id,item_name,unit,quantity,unit_price,line_total) VALUES (?,?,?,?,?,?,?)',
                [$id, $line['item_id'], $line['item_name'], $line['unit'], $line['quantity'], $line['unit_price'], $line['line_total']]);
            if ($this->enterprise) $this->query('INSERT INTO erp_po_line_units(order_line_id,unit_factor) VALUES (?,?)',[(int)$this->db->insert_id,$line['unit_factor']]);
        }
        $this->index($id);
        $this->audit('save_order', 'purchase_order', $id, ['total' => $total, 'lines' => array_values($clean)]);
        return $id;
    }
    public function save(array $input): int { return $this->atomic(fn() => $this->saveLocked($input)); }

    public function transition(int $id, string $action, $version, string $reason = ''): void {
        if (in_array($action, ['approve','reject'], true)) $this->admin();
        $this->atomic(function () use ($id, $action, $version, $reason) {
            $order = $this->locked($id); $this->version($order, $version);
            $next = ''; $details = ['before' => $order['status']];
            if ($action === 'submit') {
                $this->owner($order);
                if (!in_array($order['status'], ['draft','rejected'], true)) throw new \RuntimeException('Only draft or rejected orders can be submitted.');
                $vendor = $this->row("SELECT id FROM entity_list WHERE id=? AND status=1 AND entity_type='Supplier'", [$order['vendor_id']]);
                $inactive = $this->row('SELECT l.id FROM po_lines l JOIN item_list i ON i.id=l.item_id WHERE l.order_id=? AND i.status<>1 LIMIT 1', [$id]);
                if (!$vendor || $inactive) throw new \RuntimeException('The supplier and all items must still be active.');
                $policy = $this->row('SELECT * FROM po_policy WHERE id=1');
                $required = Money::compare($order['total'], $policy['approval_threshold']) >= 0;
                if ($required && $policy['approver_id'] && (int)$policy['approver_id'] === (int)$order['created_by']) throw new \RuntimeException('The designated approver is this order creator. An administrator must select an independent approver in the policy.');
                $next = $required ? 'pending' : 'approved';
                $this->query('UPDATE po_orders SET approval_required=?,required_approver_id=?,approved_by=?,approved_at=? WHERE id=?',
                    [$required ? 1 : 0, $required ? $policy['approver_id'] : null, null, $required ? null : date('Y-m-d H:i:s'), $id]);
                $details['policy_revision'] = $policy['revision']; $details['approval_required'] = $required;
            } elseif (in_array($action, ['approve','reject'], true)) {
                $this->admin();
                if ($order['status'] !== 'pending') throw new \RuntimeException('Only orders pending approval can be approved or rejected.');
                if ((int)$order['created_by'] === $this->actor) throw new PermissionException('You cannot approve or reject your own order. Another administrator is required.');
                if ($order['required_approver_id'] && (int)$order['required_approver_id'] !== $this->actor) throw new PermissionException('This order is assigned to a different approver.');
                $next = $action === 'approve' ? 'approved' : 'rejected';
                if ($action === 'reject') $details['reason'] = $this->text($reason, 800, 'rejection reason', true);
                else $this->query('UPDATE po_orders SET approved_by=?,approved_at=NOW() WHERE id=?', [$this->actor, $id]);
            } elseif (in_array($action, ['cancel','close'], true)) {
                $this->owner($order);
                $allowed = $action === 'cancel' ? ['draft','rejected','pending','approved'] : ['part_received'];
                if (!in_array($order['status'], $allowed, true)) throw new \RuntimeException($action === 'cancel' ? 'Received orders cannot be cancelled. Close the remaining quantity instead.' : 'Only partially received orders can be closed short.');
                $details['reason'] = $this->text($reason, 800, $action . ' reason', true);
                $next = $action === 'cancel' ? 'cancelled' : 'closed';
            } else throw new \InvalidArgumentException('Unknown order action.');
            $this->query('UPDATE po_orders SET status=?,version=version+1 WHERE id=?', [$next, $id]);
            $details['after'] = $next;
            $this->audit($action . '_order', 'purchase_order', $id, $details);
            $this->index($id);
        });
    }

    public function receive(array $input): int {
        $receipt = $this->atomic(function () use ($input) {
            $id = $this->id($input['order_id'] ?? '');
            $key = $this->key($input['request_key'] ?? '');
            $date = $this->date($input['received_date'] ?? '');
            $invoice = $this->text($input['invoice_number'] ?? '', 100, 'supplier invoice number', true);
            $notes = $this->text($input['notes'] ?? '', 2000, 'receipt notes');
            $lines = $input['lines'] ?? null;
            if (!is_array($lines) || !$lines || count($lines) > 100) throw new \InvalidArgumentException('Enter the quantities received.');
            $quantities = [];
            foreach ($lines as $line) {
                if (!is_array($line)) throw new \InvalidArgumentException('Invalid receiving line.');
                $lineId = $this->id($line['order_line_id'] ?? '');
                $quantity = $this->amount($line['quantity'] ?? '', 4);
                if (isset($quantities[$lineId]) || bccomp($quantity, '0', 4) < 0) throw new \InvalidArgumentException('Receiving lines must be unique with nonnegative quantities.');
                $quantities[$lineId] = $quantity;
            }
            ksort($quantities);
            $hash = hash('sha256', json_encode([$id, $date, $invoice, $notes, $quantities], JSON_THROW_ON_ERROR));
            $existing = $this->row('SELECT * FROM po_receipts WHERE request_key=? FOR UPDATE', [$key]);
            if ($existing) {
                if (!hash_equals($existing['payload_hash'], $hash)) throw new ConflictException('This request key was already used with different receipt details.');
                return (int)$existing['id'];
            }
            $order = $this->locked($id);
            if (!in_array($order['status'], ['approved','part_received'], true)) throw new \RuntimeException('Receive goods only against an approved, open order.');
            if ($date < $order['order_date']) throw new \InvalidArgumentException('Receipt date cannot precede the order date.');
            $this->query('SELECT id FROM erp_control WHERE id=1 LOCK IN SHARE MODE');
            if (!$this->row("SELECT id FROM gl_periods WHERE status='open' AND ? BETWEEN start_date AND end_date LOCK IN SHARE MODE", [$date])) throw new \RuntimeException('Receiving requires an open accounting period covering the receipt date.');
            if ($this->row('SELECT id FROM po_receipts WHERE vendor_id=? AND invoice_number=?', [$order['vendor_id'], $invoice])) throw new ConflictException('This supplier invoice has already been received.');
            $orderLines = array_column($this->rows('SELECT * FROM po_lines WHERE order_id=? ORDER BY item_id FOR UPDATE', [$id]), null, 'id');
            foreach ($quantities as $lineId => $quantity) if (!isset($orderLines[$lineId])) throw new \InvalidArgumentException('A receiving line does not belong to this order.');
            $prior = array_column($this->rows('SELECT r.order_line_id,SUM(r.quantity) AS quantity,SUM(r.line_total) AS amount FROM po_receipt_lines r JOIN po_receipts h ON h.id=r.receipt_id WHERE h.order_id=? GROUP BY r.order_line_id', [$id]), null, 'order_line_id');
            $received = []; $subtotal = '0.00'; $complete = true;
            foreach ($orderLines as $lineId => $line) {
                $quantity = $quantities[$lineId] ?? '0.0000';
                $cumulative = bcadd($prior[$lineId]['quantity'] ?? '0', $quantity, 4);
                if (bccomp($cumulative, $line['quantity'], 4) > 0) throw new \RuntimeException('Received quantity exceeds the outstanding quantity for ' . $line['item_name'] . '.');
                if (bccomp($cumulative, $line['quantity'], 4) < 0) $complete = false;
                if (bccomp($quantity, '0', 4) === 0) continue;
                if (!$this->row('SELECT id FROM item_list WHERE id=? AND status=1 FOR UPDATE', [$line['item_id']])) throw new \RuntimeException('Received items must still be active.');
                // Cumulative rounding ensures partial receipts add back to the approved order exactly.
                $amount = Money::subtract(Money::multiply($cumulative, $line['unit_price']), $prior[$lineId]['amount'] ?? '0');
                $received[] = ['line' => $line, 'quantity' => $quantity, 'amount' => $amount];
                $subtotal = Money::add($subtotal, $amount);
            }
            if (!$received || Money::compare($subtotal, '0') <= 0) throw new \InvalidArgumentException('Receive at least one positive-value quantity.');
            $previous = $this->row('SELECT COALESCE(SUM(subtotal),0) AS subtotal,COALESCE(SUM(tax),0) AS tax FROM po_receipts WHERE order_id=?', [$id]);
            $tax = Money::subtract(Money::decimal(bcdiv(bcmul(Money::add($previous['subtotal'], $subtotal), $order['tax_rate'], 8), '100', 8)), $previous['tax']);
            $total = Money::add($subtotal, $tax);
            $sequence = (int)$this->row('SELECT COUNT(*) AS count FROM po_receipts WHERE order_id=?', [$id])['count'] + 1;
            $reference = 'GR-' . str_pad((string)$id, 8, '0', STR_PAD_LEFT) . '-' . str_pad((string)$sequence, 3, '0', STR_PAD_LEFT);
            $remarks = $order['reference'] . ' / Invoice: ' . $invoice . ($notes === '' ? '' : "\n" . $notes);
            $this->query("INSERT INTO transactions (reference_code,type,entity_type,entity_id,total_amount,tax_perc,tax,remarks,transaction_date,created_by) VALUES (?,'purchase','Supplier',?,?,?,?,?,?,?)",
                [$reference, $order['vendor_id'], $subtotal, $order['tax_rate'], $tax, $remarks, $date, $this->actor]);
            $transaction = (int)$this->db->insert_id;
            if ($this->enterprise) {
                $location=(int)($this->row('SELECT location_id FROM erp_po_context WHERE order_id=?',[$id])['location_id']??1);
                \App\Enterprise\InventoryService::context($this->db,$transaction,$location);
            }
            foreach ($received as $entry) {
                $factor=$this->enterprise?($this->row('SELECT unit_factor FROM erp_po_line_units WHERE order_line_id=?',[$entry['line']['id']])['unit_factor']??'1.0000'):'1.0000';
                $baseQuantity=Money::decimal(bcmul($entry['quantity'],$factor,8),4);
                $basePrice=Money::decimal(bcdiv($entry['line']['unit_price'],$factor,12),4);
                $baseUnit=$this->row('SELECT unit FROM item_list WHERE id=?',[$entry['line']['item_id']])['unit']??'';
                $this->query('INSERT INTO transaction_items (transaction_id,item_id,quantity,unit_price,total_price,unit,cost_price,cost_recorded) VALUES (?,?,?,?,?,?,?,1)',
                    [$transaction, $entry['line']['item_id'], $baseQuantity, $basePrice, $entry['amount'], $baseUnit, $basePrice]);
                if ($this->enterprise) {
                    require_once __DIR__.'/../Enterprise/LineService.php';
                    \App\Enterprise\LineService::snapshot($this->db,(int)$this->db->insert_id,['entered_quantity'=>$entry['quantity'],'entered_price'=>$entry['line']['unit_price'],'unit_name'=>$entry['line']['unit'],'unit_factor'=>$factor,'promotion_id'=>null,'normal_price'=>null,'promotion_saving'=>'0.00']);
                }
            }
            $this->query('INSERT INTO po_receipts (reference,order_id,transaction_id,vendor_id,invoice_number,received_date,notes,subtotal,tax,total,request_key,payload_hash,created_by,created_username) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$reference, $id, $transaction, $order['vendor_id'], $invoice, $date, $notes, $subtotal, $tax, $total, $key, $hash, $this->actor, $this->user['username']]);
            $receipt = (int)$this->db->insert_id;
            foreach ($received as $entry) $this->query('INSERT INTO po_receipt_lines (receipt_id,order_line_id,quantity,line_total) VALUES (?,?,?,?)', [$receipt, $entry['line']['id'], $entry['quantity'], $entry['amount']]);
            $this->query('INSERT INTO po_receipt_seals (receipt_id) VALUES (?)', [$receipt]);
            $this->query('UPDATE po_orders SET status=?,version=version+1 WHERE id=?', [$complete ? 'received' : 'part_received', $id]);
            $stock = new StockService($this->db);
            foreach ($received as $entry) { $stock->quantity((int)$entry['line']['item_id']); $stock->cost((int)$entry['line']['item_id']); }
            $this->query("INSERT INTO search_index (source_table,source_id,type,title,subtitle,search_data,amount,transaction_date,view_url,edit_url) VALUES ('transactions',?,'purchase',?,?,?,?,?,?,?)",
                [$transaction, $reference, $order['vendor_name'], $remarks . ' ' . $order['vendor_name'], $subtotal, $date, 'transactions/purchases/view_purchase&id=' . $transaction, 'transactions/procurement&id=' . $id]);
            $this->purchaseMetrics($date);
            $this->index($id);
            $this->audit('receive_order', 'purchase_order', $id, ['receipt_id' => $receipt, 'transaction_id' => $transaction, 'invoice' => $invoice, 'total' => $total, 'lines' => $received]);
            return $receipt;
        });
        require_once __DIR__ . '/../Cache.php';
        (new \Cache())->clear();
        return $receipt;
    }

    public function reassign(int $id, $version, int $approver, string $reason): void {
        $this->admin();
        $this->atomic(function () use ($id, $version, $approver, $reason) {
            $order = $this->locked($id); $this->version($order, $version);
            if ($order['status'] !== 'pending') throw new \RuntimeException('Only pending approvals can be reassigned.');
            if ($approver === (int)$order['created_by'] || !$this->row('SELECT id FROM users WHERE id=? AND type=1', [$approver])) throw new \InvalidArgumentException('Choose an independent administrator.');
            $reason = $this->text($reason, 800, 'reassignment reason', true);
            $this->query('UPDATE po_orders SET required_approver_id=?,version=version+1 WHERE id=?', [$approver, $id]);
            $this->audit('reassign_approval', 'purchase_order', $id, ['previous_approver' => $order['required_approver_id'], 'approver_id' => $approver, 'reason' => $reason]);
        });
    }

    private function purchaseMetrics(string $date): void {
        $this->query("INSERT INTO summary_daily_metrics (metric_date,total_sales,total_purchases,total_expenses,total_discount,total_cogs,net_profit)
            SELECT ?,s.sales,s.purchases,s.expenses,s.discount,s.cogs,s.sales-s.cogs-s.expenses FROM (
                SELECT COALESCE(SUM(IF(type='sale',total_amount,0)),0) sales,
                COALESCE(SUM(IF(type='purchase',total_amount,0)),0) purchases,
                COALESCE(SUM(IF(type='expense',total_amount,0)),0) expenses,
                COALESCE(SUM(IF(type='sale',discount,0)),0) discount,
                (SELECT COALESCE(SUM(ti.quantity*IF(ti.cost_recorded=1,ti.cost_price,i.cost)),0)
                    FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id JOIN item_list i ON i.id=ti.item_id
                    WHERE t.type='sale' AND t.transaction_date=?) cogs
                FROM transactions WHERE transaction_date=?
            ) s ON DUPLICATE KEY UPDATE total_purchases=VALUES(total_purchases)", [$date, $date, $date]);
        $year = (int)substr($date, 0, 4); $month = (int)substr($date, 5, 2);
        $this->query('INSERT INTO summary_monthly_metrics (metric_year,metric_month,total_sales,total_purchases,total_expenses,total_discount,total_cogs,net_profit)
            SELECT ?,?,SUM(total_sales),SUM(total_purchases),SUM(total_expenses),SUM(total_discount),SUM(total_cogs),SUM(net_profit)
            FROM summary_daily_metrics WHERE YEAR(metric_date)=? AND MONTH(metric_date)=?
            ON DUPLICATE KEY UPDATE total_purchases=VALUES(total_purchases)', [$year, $month, $year, $month]);
    }

    private function index(int $id): void {
        $order = $this->row('SELECT * FROM po_orders WHERE id=?', [$id]);
        $url = 'transactions/procurement&id=' . $id;
        $this->query("INSERT INTO search_index (source_table,source_id,type,title,subtitle,search_data,amount,transaction_date,view_url,edit_url)
            VALUES ('po_orders',?,'purchase_order',?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),subtitle=VALUES(subtitle),search_data=VALUES(search_data),amount=VALUES(amount),transaction_date=VALUES(transaction_date)",
            [$id, $order['reference'], $order['vendor_name'], implode(' ', [$order['reference'], $order['vendor_name'], $order['notes'], $order['status']]), $order['total'], $order['order_date'], $url, $url]);
    }

    public function order(int $id): array {
        $order = $this->row('SELECT o.*,u.username AS approved_username,a.username AS required_approver FROM po_orders o LEFT JOIN users u ON u.id=o.approved_by LEFT JOIN users a ON a.id=o.required_approver_id WHERE o.id=?', [$id]);
        if (!$order) throw new \RuntimeException('Purchase order not found.');
        $order['location_id']=$this->enterprise?(int)($this->row('SELECT location_id FROM erp_po_context WHERE order_id=?',[$id])['location_id']??1):1;
        $order['lines'] = $this->rows('SELECT l.*,COALESCE(r.received,0) AS received,l.quantity-COALESCE(r.received,0) AS outstanding
            FROM po_lines l LEFT JOIN (SELECT order_line_id,SUM(quantity) AS received FROM po_receipt_lines GROUP BY order_line_id) r ON r.order_line_id=l.id WHERE l.order_id=? ORDER BY l.id', [$id]);
        $order['receipts'] = $this->rows('SELECT * FROM po_receipts WHERE order_id=? ORDER BY id', [$id]);
        if ($this->enterprise) foreach ($order['lines'] as &$line) {
            $line['unit_factor']=$this->row('SELECT unit_factor FROM erp_po_line_units WHERE order_line_id=?',[$line['id']])['unit_factor']??'1.0000';
        }
        $order['events'] = $this->rows("SELECT * FROM erp_audit_events WHERE object_type='purchase_order' AND object_id=? ORDER BY id DESC", [$id]);
        return $order;
    }

    public function orders(string $status = '', string $search = '', int $page = 1): array {
        $where = []; $values = [];
        if ($status !== '') {
            if (!in_array($status, ['draft','pending','rejected','approved','part_received','received','closed','cancelled'], true)) throw new \InvalidArgumentException('Invalid order status.');
            $where[] = 'status=?'; $values[] = $status;
        }
        $search = $this->text($search, 120, 'search');
        if ($search !== '') { $where[] = '(reference LIKE ? OR vendor_name LIKE ?)'; $values[] = '%' . $search . '%'; $values[] = '%' . $search . '%'; }
        $filter = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $count = (int)$this->row('SELECT COUNT(*) AS count FROM po_orders' . $filter, $values)['count'];
        $page = max(1, min($page, max(1, (int)ceil($count / 50))));
        $rows = $this->rows('SELECT * FROM po_orders' . $filter . ' ORDER BY id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50), $values);
        return ['rows' => $rows, 'count' => $count, 'page' => $page, 'pages' => max(1, (int)ceil($count / 50))];
    }
    public function receipts(): array { return $this->rows('SELECT r.*,o.reference AS order_reference,o.vendor_name FROM po_receipts r JOIN po_orders o ON o.id=r.order_id ORDER BY r.id DESC LIMIT 250'); }
    public function suppliers(): array { return $this->rows("SELECT id,display_name FROM entity_list WHERE entity_type='Supplier' AND status=1 ORDER BY display_name"); }
    public function items(): array { return $this->rows('SELECT id,name,unit,cost,quantity,vendor_id FROM item_list WHERE status=1 ORDER BY name'); }

    public function replenishment(?int $vendor = null): array {
        $unitFactor=$this->enterprise?'COALESCE(u.unit_factor,1)':'1';
        $unitJoin=$this->enterprise?' LEFT JOIN erp_po_line_units u ON u.order_line_id=l.id ':'';
        $rows = $this->rows("SELECT i.id,i.name,i.unit,i.vendor_id,i.cost,i.quantity,i.reorder_level,e.display_name AS vendor_name,
            COALESCE(p.on_order,0) AS on_order,COALESCE(p.planned,0) AS planned,
            i.quantity+COALESCE(p.on_order,0)+COALESCE(p.planned,0) AS projected,
            GREATEST(i.reorder_level-i.quantity-COALESCE(p.on_order,0)-COALESCE(p.planned,0),0) AS suggested
            FROM item_list i LEFT JOIN entity_list e ON e.id=i.vendor_id AND e.entity_type='Supplier' AND e.status=1
            LEFT JOIN (
                SELECT l.item_id,CAST(SUM(IF(o.status IN ('approved','part_received'),(l.quantity-COALESCE(r.received,0))*$unitFactor,0)) AS DECIMAL(18,4)) AS on_order,
                    CAST(SUM(IF(o.status IN ('draft','pending'),l.quantity*$unitFactor,0)) AS DECIMAL(18,4)) AS planned
                FROM po_lines l JOIN po_orders o ON o.id=l.order_id $unitJoin
                LEFT JOIN (SELECT order_line_id,SUM(quantity) AS received FROM po_receipt_lines GROUP BY order_line_id) r ON r.order_line_id=l.id
                WHERE o.status IN ('draft','pending','approved','part_received') GROUP BY l.item_id
            ) p ON p.item_id=i.id WHERE i.status=1 AND i.quantity<=i.reorder_level" . ($vendor ? ' AND i.vendor_id=?' : '') . ' ORDER BY suggested DESC,i.name', $vendor ? [$vendor] : []);
        return $rows;
    }

    public function createReplenishment(array $input): int {
        return $this->atomic(function () use ($input) {
            $vendor = $this->id($input['vendor_id'] ?? '');
            $key = $this->key($input['request_key'] ?? '');
            $selected = $input['selected_items'] ?? [];
            if (!is_array($selected) || !$selected || count($selected) > 100) throw new \InvalidArgumentException('Select up to 100 items for one supplier.');
            $selected = array_map(fn($id) => $this->id($id), $selected);
            sort($selected); $selected = array_values(array_unique($selected));
            $existing = $this->row('SELECT * FROM po_orders WHERE request_key=?', [$key]);
            $signature = hash('sha256', json_encode([$vendor, $selected], JSON_THROW_ON_ERROR));
            if ($existing) {
                $this->owner($existing);
                $event = $this->row("SELECT details FROM erp_audit_events WHERE object_type='purchase_order' AND object_id=? AND action='replenish_order' ORDER BY id DESC LIMIT 1", [$existing['id']]);
                if (!$event || (json_decode($event['details'], true)['selection_hash'] ?? '') !== $signature) throw new ConflictException('Request key already used for another selection.');
                return (int)$existing['id'];
            }
            $rows = array_column($this->replenishment($vendor), null, 'id');
            $lines = [];
            foreach ($selected as $item) {
                $row = $rows[$item] ?? null;
                if (!$row || bccomp($row['suggested'], '0', 4) <= 0) throw new ConflictException('Stock or outstanding orders changed. Refresh the replenishment list.');
                if (bccomp($row['cost'], '0', 4) <= 0) throw new \InvalidArgumentException('Set a positive item cost before creating its replenishment order.');
                $lines[] = ['item_id' => $item, 'quantity' => $row['suggested'], 'unit_price' => $row['cost']];
            }
            $id = $this->saveLocked(['vendor_id' => $vendor, 'order_date' => date('Y-m-d'), 'notes' => 'Inventory replenishment', 'tax_rate' => '0', 'lines' => $lines, 'request_key' => $key]);
            $this->audit('replenish_order', 'purchase_order', $id, ['selection_hash' => $signature]);
            return $id;
        });
    }
}
