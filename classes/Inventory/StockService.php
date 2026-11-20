<?php
namespace App\Inventory;

require_once __DIR__ . '/../Accounting/Money.php';
use App\Accounting\Money;

final class StockService {
    public function __construct(private \mysqli $db) {}

    private function query(string $sql, array $parameters): \mysqli_result|bool {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        return $statement->get_result();
    }

    public function quantity(int $item): string {
        $this->query('SELECT id FROM item_list WHERE id=? FOR UPDATE', [$item]);
        // Current reads include movements committed by a competing receipt while we waited.
        $row = $this->query("SELECT COALESCE(SUM(CASE
            WHEN t.type IN ('purchase','opening_stock','adjustment') THEN ti.quantity
            WHEN t.type='sale' THEN -ti.quantity
            WHEN t.type='return' THEN CASE WHEN LOWER(t.entity_type) IN ('vendor','supplier') THEN -ti.quantity ELSE ti.quantity END
            ELSE 0 END),0) AS quantity
            FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id
            WHERE ti.item_id=? FOR UPDATE", [$item])->fetch_assoc();
        $quantity = Money::decimal($row['quantity'], 4);
        $this->query('UPDATE item_list SET quantity=? WHERE id=?', [$quantity, $item]);
        if ($this->db->query("SHOW TABLES LIKE 'erp_transaction_context'")->num_rows) {
            $distributed=$this->query('SELECT c.transaction_id FROM erp_transaction_context c JOIN transaction_items i ON i.transaction_id=c.transaction_id WHERE i.item_id=? AND c.location_id<>1 LIMIT 1',[$item])->num_rows
                || $this->query('SELECT id FROM erp_stock_transfer_lines WHERE item_id=? LIMIT 1',[$item])->num_rows;
            if ($distributed) foreach ($this->db->query('SELECT id,name FROM erp_locations')->fetch_all(MYSQLI_ASSOC) as $location) {
                $stock=$this->query("SELECT COALESCE(SUM(CASE WHEN t.type IN ('purchase','opening_stock','adjustment') THEN i.quantity WHEN t.type='sale' THEN -i.quantity WHEN t.type='return' THEN IF(LOWER(t.entity_type) IN ('supplier','vendor'),-i.quantity,i.quantity) ELSE 0 END),0) AS quantity
                    FROM transaction_items i JOIN transactions t ON t.id=i.transaction_id LEFT JOIN erp_transaction_context c ON c.transaction_id=t.id WHERE i.item_id=? AND COALESCE(c.location_id,1)=? FOR UPDATE",[$item,$location['id']])->fetch_assoc()['quantity'];
                $moved=$this->query('SELECT COALESCE(SUM(IF(h.to_location_id=?,l.quantity,-l.quantity)),0) AS quantity FROM erp_stock_transfer_lines l JOIN erp_stock_transfers h ON h.id=l.transfer_id WHERE l.item_id=? AND (h.from_location_id=? OR h.to_location_id=?) FOR UPDATE',[$location['id'],$item,$location['id'],$location['id']])->fetch_assoc()['quantity'];
                if (bccomp(bcadd($stock,$moved,4),'0',4)<0) throw new \RuntimeException('Insufficient stock at '.$location['name'].'.');
            }
        }
        return $quantity;
    }

    public function cost(int $item): string {
        $itemRow = $this->query('SELECT opening_cost FROM item_list WHERE id=? FOR UPDATE', [$item])->fetch_assoc();
        $row = $this->query("SELECT COALESCE(SUM(ti.total_price),0) AS value, COALESCE(SUM(ti.quantity),0) AS quantity
            FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id
            WHERE ti.item_id=? AND ti.unit_price>0 AND
            (t.type IN ('purchase','opening_stock') OR (t.type='adjustment' AND ti.quantity>0)) FOR UPDATE", [$item])->fetch_assoc();
        $cost = bccomp($row['quantity'], '0', 4) > 0
            ? Money::decimal(bcdiv($row['value'], $row['quantity'], 12), 4)
            : Money::decimal($itemRow['opening_cost'] ?? '0', 4);
        $this->query('UPDATE item_list SET cost=?,average_cost=? WHERE id=?', [$cost, $cost, $item]);
        return $cost;
    }
}
