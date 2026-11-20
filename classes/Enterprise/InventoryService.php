<?php
namespace App\Enterprise;
require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/CatalogService.php';
use App\Accounting\Money;

final class InventoryService extends Service {
    public const MOVEMENT = "CASE WHEN t.type IN ('purchase','item_receipt','opening_stock','adjustment') THEN ti.quantity
        WHEN t.type='sale' THEN -ti.quantity WHEN t.type='return' THEN
        IF(LOWER(t.entity_type) IN ('vendor','supplier'),-ti.quantity,ti.quantity) ELSE 0 END";

    public function locations(bool $activeOnly = false): array {
        return $this->rows('SELECT * FROM erp_locations' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY id');
    }
    public function saveLocation(array $input): int {
        $this->admin();
        return $this->atomic(function () use ($input) {
            $id = empty($input['id']) ? 0 : $this->id($input['id']);
            $code = strtoupper($this->text($input['code'] ?? '', 30, true));
            if (!preg_match('/^[A-Z0-9_-]+$/D', $code)) throw new \InvalidArgumentException('Use letters, digits, underscores, or hyphens for the location code.');
            $name = $this->text($input['name'] ?? '', 120, true);
            $address = $this->text($input['address'] ?? '', 500);
            $phone = $this->text($input['phone'] ?? '', 50);
            $active = ($input['active'] ?? '0') === '1' ? 1 : 0;
            if ($id) {
                $old = $this->row('SELECT * FROM erp_locations WHERE id=? FOR UPDATE', [$id]);
                if (!$old) throw new \RuntimeException('Location not found.');
                $this->version($old, $input['version'] ?? '');
                if ($id === 1 && !$active) throw new \RuntimeException('The main location cannot be deactivated.');
                if (!$active) foreach ($this->stock($id) as $item) if (bccomp($item['quantity'], '0', 4) !== 0) throw new \RuntimeException('A location with stock cannot be deactivated.');
                $this->query('UPDATE erp_locations SET code=?,name=?,address=?,phone=?,active=?,version=version+1 WHERE id=?', [$code,$name,$address,$phone,$active,$id]);
            } else {
                $this->query('INSERT INTO erp_locations(code,name,address,phone,active) VALUES (?,?,?,?,?)', [$code,$name,$address,$phone,$active]);
                $id = (int)$this->db->insert_id;
            }
            $this->audit('save_location', 'location', $id, ['code'=>$code,'name'=>$name,'active'=>$active]);
            return $id;
        });
    }

    public function stock(?int $location = null, ?int $item = null, ?string $asOf = null): array {
        $source=CatalogService::stockSource($this->db);
        $values = []; $filter = '';
        if ($location !== null) { $filter .= ' AND COALESCE(c.location_id,1)=?'; $values[] = $location; }
        if ($asOf !== null) { $filter .= ' AND t.transaction_date<=?'; $values[] = $this->date($asOf); }
        $transfers = ''; $join = '';
        if ($location !== null) {
            $dateFilter = $asOf === null ? '' : ' AND h.transfer_date<=?';
            $transfers = ' LEFT JOIN (SELECT l.item_id,SUM(IF(h.to_location_id=?,l.quantity,-l.quantity)) AS quantity
                FROM erp_stock_transfer_lines l JOIN erp_stock_transfers h ON h.id=l.transfer_id
                WHERE (h.from_location_id=? OR h.to_location_id=?)' . $dateFilter . ' GROUP BY l.item_id) x ON x.item_id=i.id';
            array_push($values, $location, $location, $location);
            if ($asOf !== null) $values[] = $asOf;
            $join = '+COALESCE(x.quantity,0)';
        }
        $sql = 'SELECT i.id,i.name,i.unit,i.cost,i.selling_price,i.mrp,i.reorder_level,i.vendor_id,i.status,
            COALESCE(m.quantity,0)' . $join . ' AS quantity
            FROM item_list i LEFT JOIN (SELECT ti.item_id,SUM(' . self::MOVEMENT . ') AS quantity
                FROM '.$source.' ti JOIN transactions t ON t.id=ti.transaction_id
                LEFT JOIN erp_transaction_context c ON c.transaction_id=t.id WHERE 1=1' . $filter . ' GROUP BY ti.item_id) m ON m.item_id=i.id' . $transfers;
        $sql.=' WHERE 1=1';
        if(CatalogService::available($this->db))$sql.=" AND i.item_type IN ('inventory','assembly') AND i.is_matrix_parent=0";
        if ($item !== null) { $sql .= ' AND i.id=?'; $values[] = $item; }
        return $this->rows($sql . ' ORDER BY i.name', $values);
    }

    public static function context(\mysqli $db, int $transaction, $requested = null): int {
        $query = static function ($sql, $values = []) use ($db) { $s=$db->prepare($sql);$s->execute($values);return $s->get_result(); };
        $old = $query('SELECT location_id FROM erp_transaction_context WHERE transaction_id=?', [$transaction])->fetch_assoc();
        $location = $requested === null || $requested === '' ? (int)($old['location_id'] ?? 1) : filter_var($requested, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (!$location || !$query('SELECT id FROM erp_locations WHERE id=? AND active=1', [$location])->num_rows) throw new \InvalidArgumentException('Choose an active location.');
        if(CatalogService::available($db)){$purchase=CatalogService::transactionPurchase($db,$transaction);if($purchase!==null)CatalogService::assertRecordUsage($db,'location',(int)$location,$purchase);}
        if ($old && (int)$old['location_id'] === $location) return $location;
        if ($query("SELECT id FROM gl_journals WHERE source_transaction_id=? AND status='posted'", [$transaction])->num_rows) throw new \RuntimeException('The location of a posted document cannot be changed.');
        if ($location !== 1 || $old) $query('INSERT INTO erp_transaction_context(transaction_id,location_id) VALUES (?,?) ON DUPLICATE KEY UPDATE location_id=VALUES(location_id)', [$transaction,$location]);
        return $location;
    }

    public function packaging(): array {
        return $this->rows("SELECT i.id AS item_id,i.name,i.unit,i.cost,i.selling_price,i.mrp,p.barcode,p.case_barcode,
            COALESCE(p.case_name,'Case') AS case_name,COALESCE(p.units_per_case,1) AS units_per_case,
            p.case_cost,p.case_price,COALESCE(p.version,0) AS version
            FROM item_list i LEFT JOIN erp_item_packaging p ON p.item_id=i.id WHERE i.status=1 ORDER BY i.name");
    }
    public function savePackaging(array $input): void {
        $this->admin();
        $this->atomic(function () use ($input) {
            $item = $this->id($input['item_id'] ?? '');
            if (!$this->row('SELECT id FROM item_list WHERE id=? FOR UPDATE', [$item])) throw new \InvalidArgumentException('Item not found.');
            $old = $this->row('SELECT * FROM erp_item_packaging WHERE item_id=?', [$item]);
            $this->version($old ?? ['version'=>0], $input['version'] ?? '0');
            $name = $this->text($input['case_name'] ?? 'Case', 50, true);
            $factor = $this->decimal($input['units_per_case'] ?? '', 4, true);
            if (bccomp($factor, '1', 4) < 0) throw new \InvalidArgumentException('A case must contain at least one base unit.');
            $barcode = $this->text($input['barcode'] ?? '', 100) ?: null;
            $caseBarcode = $this->text($input['case_barcode'] ?? '', 100) ?: null;
            if ($barcode && $barcode === $caseBarcode) throw new \InvalidArgumentException('Base and case barcodes must differ.');
            foreach (array_filter([$barcode,$caseBarcode]) as $code) {
                if ($this->row('SELECT item_id FROM erp_item_packaging WHERE item_id<>? AND (barcode=? OR case_barcode=?)', [$item,$code,$code])) throw new \InvalidArgumentException('A barcode is already assigned to another item.');
            }
            $cost = ($input['case_cost'] ?? '') === '' ? null : $this->decimal($input['case_cost']);
            $price = ($input['case_price'] ?? '') === '' ? null : $this->decimal($input['case_price']);
            $this->query('INSERT INTO erp_item_packaging(item_id,barcode,case_barcode,case_name,units_per_case,case_cost,case_price) VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE barcode=VALUES(barcode),case_barcode=VALUES(case_barcode),case_name=VALUES(case_name),units_per_case=VALUES(units_per_case),case_cost=VALUES(case_cost),case_price=VALUES(case_price),version=version+1', [$item,$barcode,$caseBarcode,$name,$factor,$cost,$price]);
            $this->audit('save_packaging','item',$item,['factor'=>$factor,'case_name'=>$name]);
        });
    }
    public function unit(int $item, string $code, bool $purchase = false): array {
        $row = $this->row("SELECT i.unit,i.cost,i.selling_price,p.* FROM item_list i LEFT JOIN erp_item_packaging p ON p.item_id=i.id WHERE i.id=? AND i.status=1", [$item]);
        if (!$row) throw new \InvalidArgumentException('Choose an active item.');
        $case = strtolower($code) === 'case';
        if (!$case && $code !== 'base') throw new \InvalidArgumentException('Invalid packaging unit.');
        if ($case && !$row['item_id']) throw new \InvalidArgumentException('Configure item packaging before selling or purchasing cases.');
        $factor = $case ? $row['units_per_case'] : '1.0000';
        $base = $purchase ? $row['cost'] : $row['selling_price'];
        $override = $row[$purchase?'case_cost':'case_price'];
        $price = $case ? ($override ?? Money::decimal(bcmul($base,$factor,8),4)) : $base;
        return ['name'=>$case?$row['case_name']:($row['unit'] ?: 'Unit'),'factor'=>$factor,'price'=>$price];
    }

    public function transfers(): array {
        return $this->rows('SELECT h.*,a.name AS source,b.name AS destination,r.id AS reversal_id,
            (SELECT COUNT(*) FROM erp_stock_transfer_lines l WHERE l.transfer_id=h.id) AS item_count
            FROM erp_stock_transfers h JOIN erp_locations a ON a.id=h.from_location_id JOIN erp_locations b ON b.id=h.to_location_id
            LEFT JOIN erp_stock_transfers r ON r.reversal_of=h.id ORDER BY h.id DESC LIMIT 250');
    }
    public function transfer(int $id): array {
        $header = $this->row('SELECT * FROM erp_stock_transfers WHERE id=?', [$id]);
        if (!$header) throw new \RuntimeException('Transfer not found.');
        $header['lines'] = $this->rows('SELECT l.*,i.name,i.unit FROM erp_stock_transfer_lines l JOIN item_list i ON i.id=l.item_id WHERE l.transfer_id=? ORDER BY l.item_id', [$id]);
        return $header;
    }
    private function writeTransfer(array $input, ?int $reversal = null): int {
        $date = $this->date($input['transfer_date'] ?? ''); $this->openDate($date);
        $from = $this->id($input['from_location_id'] ?? ''); $to = $this->id($input['to_location_id'] ?? '');
        if ($from === $to) throw new \InvalidArgumentException('Choose two different locations.');
        foreach ([$from,$to] as $location) if (!$this->row('SELECT id FROM erp_locations WHERE id=? AND active=1', [$location])) throw new \InvalidArgumentException('Choose active locations.');
        $notes = $this->text($input['notes'] ?? '', 1000, true);
        $key = $this->key($input['request_key'] ?? '');
        $raw = $input['lines'] ?? null;
        if (!is_array($raw) || !$raw || count($raw)>100) throw new \InvalidArgumentException('Enter 1 to 100 transfer lines.');
        $lines = [];
        foreach ($raw as $line) {
            if (!is_array($line)) throw new \InvalidArgumentException('Invalid transfer line.');
            $item=$this->id($line['item_id']??'');
            if(!CatalogService::tracked($this->db,$item))throw new \InvalidArgumentException('Stock transfers require an inventory or assembly variant.');
            if (isset($lines[$item])) throw new \InvalidArgumentException('Duplicate item in transfer.');
            $quantity=$this->decimal($line['quantity']??'',4,true);
            $unit=$this->unit($item, $this->text($line['unit_code']??'base',10));
            $lines[$item]=['quantity'=>Money::decimal(bcmul($quantity,$unit['factor'],8),4),'item_id'=>$item];
        }
        ksort($lines);
        $hash=hash('sha256',json_encode([$date,$from,$to,$notes,$lines,$reversal],JSON_THROW_ON_ERROR));
        $existing=$this->row('SELECT id,payload_hash FROM erp_stock_transfers WHERE request_key=?',[$key]);
        if ($existing) {
            if (!hash_equals($existing['payload_hash'],$hash)) throw new \UnexpectedValueException('Request key already used for different transfer details.');
            return (int)$existing['id'];
        }
        foreach ($lines as $item=>$line) {
            $this->query('SELECT id FROM item_list WHERE id=? FOR UPDATE',[$item]);
            $stock=$this->stock($from,$item)[0]??null;
            if (!$stock || bccomp($stock['quantity'],$line['quantity'],4)<0) throw new \RuntimeException('Insufficient source stock for '.($stock['name']??'item').'.');
            $lines[$item]['cost']=$stock['cost'];
        }
        $this->query('INSERT INTO erp_stock_transfers(reference,transfer_date,from_location_id,to_location_id,notes,request_key,payload_hash,created_by,reversal_of) VALUES (?,?,?,?,?,?,?,?,?)',
            ['TR-'.bin2hex(random_bytes(10)),$date,$from,$to,$notes,$key,$hash,$this->user,$reversal]);
        $id=(int)$this->db->insert_id;
        foreach ($lines as $line) $this->query('INSERT INTO erp_stock_transfer_lines(transfer_id,item_id,quantity,unit_cost) VALUES (?,?,?,?)',[$id,$line['item_id'],$line['quantity'],$line['cost']]);
        $this->audit($reversal?'reverse_stock_transfer':'stock_transfer','stock_transfer',$id,['from'=>$from,'to'=>$to,'lines'=>array_values($lines),'reversal_of'=>$reversal]);
        return $id;
    }
    public function saveTransfer(array $input): int { return $this->atomic(fn()=>$this->writeTransfer($input)); }
    public function reverseTransfer(int $id, string $date, string $reason): int {
        $this->admin();
        return $this->atomic(function () use ($id,$date,$reason) {
            $existing=$this->row('SELECT id FROM erp_stock_transfers WHERE reversal_of=?',[$id]);
            if ($existing) return (int)$existing['id'];
            $old=$this->transfer($id);
            if ($old['reversal_of'] || $this->date($date)<$old['transfer_date']) throw new \InvalidArgumentException('Reverse an original transfer on or after its date.');
            $lines=array_map(static fn($line)=>['item_id'=>$line['item_id'],'quantity'=>$line['quantity'],'unit_code'=>'base'],$old['lines']);
            return $this->writeTransfer(['transfer_date'=>$date,'from_location_id'=>$old['to_location_id'],'to_location_id'=>$old['from_location_id'],
                'notes'=>'Reversal of '.$old['reference'].': '.$this->text($reason,800,true),'request_key'=>'reverse-'.bin2hex(random_bytes(16)),'lines'=>$lines],$id);
        });
    }
}
