<?php
namespace App\Procurement;

require_once __DIR__.'/../Enterprise/Service.php';
require_once __DIR__.'/../Enterprise/Access.php';
require_once __DIR__.'/../Enterprise/InventoryService.php';
require_once __DIR__.'/../Enterprise/LineService.php';
require_once __DIR__.'/../Inventory/StockService.php';
use App\Accounting\Money;
use App\Enterprise\Access;
use App\Enterprise\InventoryService;
use App\Enterprise\LineService;
use App\Inventory\StockService;

final class CycleService extends \App\Enterprise\Service {
    protected function atomic(callable $work) {
        for($attempt=0;;$attempt++) {
            $this->db->begin_transaction();
            try {
                $this->query('SELECT id FROM po_policy WHERE id=1 FOR UPDATE');
                $this->query('SELECT id FROM erp_control WHERE id=1 FOR UPDATE');
                $result=$work();$this->db->commit();
                require_once __DIR__.'/../Cache.php';
                try{(new \Cache())->clear();}catch(\Throwable $cacheError){error_log('Purchase cycle cache refresh: '.$cacheError->getMessage());}
                return $result;
            } catch(\Throwable $error) {
                $this->db->rollback();
                if($error instanceof \mysqli_sql_exception&&in_array($error->getCode(),[1205,1213],true)&&$attempt<2){usleep(50000*($attempt+1));continue;}
                throw $error;
            }
        }
    }
    private function permission(string $scope,bool $write=false): void {
        if(!Access::allowed($this->db,$this->user,$scope,$write))throw new \DomainException('Your role does not allow this purchase-cycle action.');
    }
    private function quantities($lines,string $field): array {
        if(!is_array($lines)||!$lines||count($lines)>100)throw new \InvalidArgumentException('Enter between 1 and 100 document lines.');
        $result=[];
        foreach($lines as $line){
            if(!is_array($line))throw new \InvalidArgumentException('Invalid document line.');
            $id=$this->id($line[$field]??'');if(isset($result[$id]))throw new \InvalidArgumentException('An item line can appear only once.');
            $result[$id]=$this->decimal($line['quantity']??'',4);
        }
        ksort($result);return $result;
    }
    private function order(int $id): array {
        $order=$this->row('SELECT * FROM po_orders WHERE id=? FOR UPDATE',[$id]);
        if(!$order)throw new \RuntimeException('Purchase order not found.');return $order;
    }
    private function orderState(int $id): void {
        $order=$this->order($id);if($order['status']==='closed')return;
        $state=$this->row('SELECT COUNT(*) AS count,COALESCE(SUM(COALESCE(r.quantity,0)),0) AS received,SUM(l.quantity>COALESCE(r.quantity,0)) AS incomplete FROM po_lines l LEFT JOIN po_received_quantities r ON r.order_line_id=l.id WHERE l.order_id=?',[$id]);
        $status=bccomp($state['received'],'0',4)===0?'approved':((int)$state['incomplete']?'part_received':'received');
        $this->query('UPDATE po_orders SET status=?,version=version+1 WHERE id=?',[$status,$id]);
    }
    private function index(int $transaction,string $type,string $reference,string $date,string $amount,string $party,string $url): void {
        $this->query("INSERT INTO search_index(source_table,source_id,type,title,subtitle,search_data,amount,transaction_date,view_url,edit_url) VALUES('transactions',?,?,?,?,?,?,?,?,?)",
            [$transaction,$type,$reference,$party,$reference.' '.$party,$amount,$date,$url,$url]);
    }
    public function receive(array $input): int {
        $this->permission('procurement',true);
        return $this->atomic(function()use($input){
            $orderId=$this->id($input['order_id']??'');$date=$this->date($input['received_date']??'');
            $delivery=$this->text($input['delivery_reference']??'',100);$notes=$this->text($input['notes']??'',2000);
            $key=$this->key($input['request_key']??'');$quantities=$this->quantities($input['lines']??null,'order_line_id');
            $hash=hash('sha256',json_encode([$orderId,$date,$delivery,$notes,$quantities],JSON_THROW_ON_ERROR));
            $old=$this->row('SELECT * FROM po_item_receipts WHERE request_key=? FOR UPDATE',[$key]);
            if($old){if(!hash_equals($old['payload_hash'],$hash))throw new \UnexpectedValueException('Request key already used for another receipt.');return(int)$old['id'];}
            $order=$this->order($orderId);
            if(!in_array($order['status'],['approved','part_received'],true))throw new \RuntimeException('Receive items only against an approved, open purchase order.');
            if($date<$order['order_date'])throw new \InvalidArgumentException('Receipt date cannot precede the order.');$this->openDate($date);
            $lines=$this->rows('SELECT l.*,COALESCE(q.quantity,0) AS received,COALESCE(q.amount,0) AS received_amount,COALESCE(u.unit_factor,1) AS factor,i.unit AS base_unit,i.status AS active FROM po_lines l JOIN item_list i ON i.id=l.item_id LEFT JOIN po_received_quantities q ON q.order_line_id=l.id LEFT JOIN erp_po_line_units u ON u.order_line_id=l.id WHERE l.order_id=? ORDER BY l.item_id FOR UPDATE',[$orderId]);
            $known=array_column($lines,null,'id');foreach($quantities as $line=>$quantity)if(!isset($known[$line]))throw new \InvalidArgumentException('Receipt line does not belong to this order.');
            $received=[];$subtotal='0.00';
            foreach($lines as $line){
                $qty=$quantities[$line['id']]??'0';if(bccomp($qty,'0',4)===0)continue;
                if(!(int)$line['active'])throw new \RuntimeException('Received items must still be active.');
                $cumulative=bcadd($line['received'],$qty,4);if(bccomp($cumulative,$line['quantity'],4)>0)throw new \RuntimeException('Received quantity exceeds the outstanding order quantity.');
                $amount=Money::subtract(Money::multiply($cumulative,$line['unit_price']),$line['received_amount']);
                if(Money::compare($amount,'0')<=0)throw new \InvalidArgumentException('Each receipt line must have a value of at least NPR 0.01.');
                $received[]=['line'=>$line,'quantity'=>$qty,'amount'=>$amount];$subtotal=Money::add($subtotal,$amount);
            }
            if(!$received)throw new \InvalidArgumentException('Receive at least one positive quantity.');
            $id=$this->writeReceipt($order,$date,$delivery,$notes,$subtotal,$received,$key,$hash);
            $this->orderState($orderId);return $id;
        });
    }
    private function writeReceipt(array $order,string $date,string $delivery,string $notes,string $subtotal,array $received,string $key,string $hash,?array $original=null): int {
        $this->query("INSERT INTO transactions(reference_code,type,entity_type,entity_id,parent_id,total_amount,tax_perc,tax,remarks,transaction_date,created_by) VALUES(?,'item_receipt','Supplier',?,?,?,0,0,?,?,?)",
            ['IR-TEMP-'.bin2hex(random_bytes(8)),$order['vendor_id'],$original['transaction_id']??null,$subtotal,$order['reference'].' / '.$delivery.($notes!==''?"\n".$notes:''),$date,$this->user]);
        $transaction=(int)$this->db->insert_id;$reference='IR-'.str_pad((string)$transaction,8,'0',STR_PAD_LEFT);
        $this->query('UPDATE transactions SET reference_code=? WHERE id=?',[$reference,$transaction]);
        $location=(int)($this->row('SELECT location_id FROM erp_po_context WHERE order_id=?',[$order['id']])['location_id']??1);
        InventoryService::context($this->db,$transaction,$location);$written=[];
        foreach($received as $entry){
            $line=$entry['line'];$qty=Money::decimal(bcmul($entry['quantity'],$line['factor'],8),4);$price=Money::decimal(bcdiv($line['unit_price'],$line['factor'],12),4);
            $this->query('INSERT INTO transaction_items(transaction_id,item_id,quantity,unit_price,total_price,unit,cost_price,cost_recorded) VALUES(?,?,?,?,?,?,?,1)',[$transaction,$line['item_id'],$qty,$price,$entry['amount'],$line['base_unit'],$price]);
            $lineId=(int)$this->db->insert_id;
            LineService::snapshot($this->db,$lineId,['entered_quantity'=>$entry['quantity'],'entered_price'=>$line['unit_price'],'unit_name'=>$line['unit'],'unit_factor'=>$line['factor'],'promotion_id'=>null,'normal_price'=>null,'promotion_saving'=>'0.00']);
            $written[]=[$line['id'],$lineId,$entry['quantity'],$entry['amount']];
        }
        $this->query('INSERT INTO po_item_receipts(reference,order_id,transaction_id,vendor_id,received_date,delivery_reference,notes,subtotal,reversal_of,request_key,payload_hash,created_by,created_username) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$reference,$order['id'],$transaction,$order['vendor_id'],$date,$delivery,$notes,$subtotal,$original['id']??null,$key,$hash,$this->user,$this->actor['username']]);
        $id=(int)$this->db->insert_id;
        foreach($written as $line)$this->query('INSERT INTO po_item_receipt_lines(receipt_id,order_line_id,transaction_line_id,quantity,line_total) VALUES(?,?,?,?,?)',array_merge([$id],$line));
        $this->query('INSERT INTO po_item_receipt_seals(receipt_id) VALUES(?)',[$id]);
        $stock=new StockService($this->db);foreach($received as $entry){$stock->quantity((int)$entry['line']['item_id']);$stock->cost((int)$entry['line']['item_id']);}
        $this->index($transaction,'item_receipt',$reference,$date,$subtotal,$order['vendor_name'],'transactions/item_receipts&id='.$id);
        $this->audit($original?'reverse_item_receipt':'receive_items','purchase_order',(int)$order['id'],['receipt_id'=>$id,'transaction_id'=>$transaction,'reversal_of'=>$original['id']??null,'value'=>$subtotal]);
        return $id;
    }
    public function reverseReceipt(int $id,string $date,string $reason): int {
        $this->permission('procurement',true);$this->admin();
        return $this->atomic(function()use($id,$date,$reason){
            $original=$this->row('SELECT * FROM po_item_receipts WHERE id=? FOR UPDATE',[$id]);
            if(!$original||$original['reversal_of'])throw new \RuntimeException('Choose an original item receipt.');
            $old=$this->row('SELECT id FROM po_item_receipts WHERE reversal_of=?',[$id]);if($old)return(int)$old['id'];
            $date=$this->date($date);$reason=$this->text($reason,2000,true);$this->openDate($date);
            if($date<$original['received_date'])throw new \InvalidArgumentException('Reversal cannot precede the receipt.');
            if($this->row("SELECT b.id FROM po_vendor_bills b JOIN po_vendor_bill_lines l ON l.bill_id=b.id JOIN po_item_receipt_lines r ON r.id=l.receipt_line_id WHERE r.receipt_id=? AND b.status IN ('draft','recorded') LIMIT 1",[$id]))throw new \RuntimeException('This receipt is used by a bill. Cancel its draft bills first; recorded bills require the purchase-return workflow.');
            $order=$this->order((int)$original['order_id']);$location=(int)($this->row('SELECT location_id FROM erp_po_context WHERE order_id=?',[$order['id']])['location_id']??1);
            $entries=[];$inventory=new InventoryService($this->db,$this->user);
            foreach($this->rows('SELECT r.*,l.item_id,l.unit_price,l.unit,d.unit_factor AS factor,i.unit AS base_unit FROM po_item_receipt_lines r JOIN po_lines l ON l.id=r.order_line_id JOIN erp_line_details d ON d.line_id=r.transaction_line_id JOIN item_list i ON i.id=l.item_id WHERE r.receipt_id=? ORDER BY l.item_id',[$id])as $line){
                $base=bcmul($line['quantity'],$line['factor'],4);
                if(\App\Enterprise\CatalogService::tracked($this->db,(int)$line['item_id'])){
                if(bccomp($inventory->stock($location,(int)$line['item_id'])[0]['quantity'],$base,4)<0||bccomp($inventory->stock($location,(int)$line['item_id'],$date)[0]['quantity'],$base,4)<0)throw new \RuntimeException('Insufficient location stock to reverse this receipt.');
                $movement=InventoryService::MOVEMENT;
                $minimum=$this->row("SELECT MIN(quantity) AS quantity FROM (
                    SELECT movement_date,SUM(SUM(delta)) OVER(ORDER BY movement_date) AS quantity FROM (
                        SELECT t.transaction_date AS movement_date,SUM($movement) AS delta
                        FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id
                        LEFT JOIN erp_transaction_context c ON c.transaction_id=t.id
                        WHERE ti.item_id=? AND COALESCE(c.location_id,1)=? GROUP BY t.transaction_date
                        UNION ALL SELECT h.transfer_date,SUM(IF(h.to_location_id=?,l.quantity,-l.quantity))
                        FROM erp_stock_transfer_lines l JOIN erp_stock_transfers h ON h.id=l.transfer_id
                        WHERE l.item_id=? AND (h.from_location_id=? OR h.to_location_id=?) GROUP BY h.transfer_date
                    ) movements GROUP BY movement_date
                ) balances WHERE movement_date>=?",[$line['item_id'],$location,$location,$line['item_id'],$location,$location,$date]);
                if($minimum['quantity']!==null&&bccomp($minimum['quantity'],$base,4)<0)throw new \RuntimeException('Backdated reversal would leave insufficient stock on a later movement date.');
                }
                $line['id']=$line['order_line_id'];$entries[]=['line'=>$line,'quantity'=>bcsub('0',$line['quantity'],4),'amount'=>Money::subtract('0',$line['line_total'])];
            }
            $key='reverse-receipt-'.$id;$new=$this->writeReceipt($order,$date,'Reversal of '.$original['reference'],$reason,Money::subtract('0',$original['subtotal']),$entries,$key,hash('sha256',$key),$original);
            $this->orderState((int)$order['id']);return $new;
        });
    }
    public function receipt(int $id): array {
        $this->permission('procurement');
        $row=$this->row('SELECT r.*,o.reference AS order_reference,o.vendor_name,x.id AS reversal_id,j.id AS journal_id,j.reference AS journal_reference FROM po_item_receipts r JOIN po_orders o ON o.id=r.order_id LEFT JOIN po_item_receipts x ON x.reversal_of=r.id LEFT JOIN gl_journals j ON j.source_transaction_id=r.transaction_id AND j.status=\'posted\' WHERE r.id=?',[$id]);
        if(!$row)throw new \RuntimeException('Item receipt not found.');
        $row['lines']=$this->rows('SELECT r.*,l.item_name,l.unit,l.unit_price,d.unit_factor FROM po_item_receipt_lines r JOIN po_lines l ON l.id=r.order_line_id JOIN erp_line_details d ON d.line_id=r.transaction_line_id WHERE r.receipt_id=? ORDER BY r.id',[$id]);
        $row['bills']=$this->rows("SELECT DISTINCT b.id,b.reference,b.status,b.transaction_id,b.total FROM po_vendor_bills b JOIN po_vendor_bill_lines l ON l.bill_id=b.id JOIN po_item_receipt_lines r ON r.id=l.receipt_line_id WHERE r.receipt_id=? ORDER BY b.id",[$id]);return $row;
    }
    public function receipts(?int $order=null): array {
        $this->permission('procurement');
        return $this->rows('SELECT r.*,o.reference AS order_reference,o.vendor_name,x.id AS reversal_id,j.id AS journal_id FROM po_item_receipts r JOIN po_orders o ON o.id=r.order_id LEFT JOIN po_item_receipts x ON x.reversal_of=r.id LEFT JOIN gl_journals j ON j.source_transaction_id=r.transaction_id AND j.status=\'posted\''.($order?' WHERE r.order_id=?':'').' ORDER BY r.id DESC LIMIT 250',$order?[$order]:[]);
    }
    public function billable(int $order): array {
        $this->permission('purchases');
        return $this->rows("SELECT r.id AS receipt_line_id,h.id AS receipt_id,h.reference AS receipt_reference,h.received_date,
            o.id AS order_line_id,o.item_id,o.item_name,o.unit,o.unit_price,d.unit_factor,
            r.quantity AS received_quantity,r.line_total AS received_amount,COALESCE(b.quantity,0) AS billed_quantity,COALESCE(b.amount,0) AS billed_amount,
            r.quantity-COALESCE(b.quantity,0) AS unbilled
            FROM po_item_receipt_lines r JOIN po_item_receipts h ON h.id=r.receipt_id AND h.reversal_of IS NULL
            JOIN po_lines o ON o.id=r.order_line_id JOIN erp_line_details d ON d.line_id=r.transaction_line_id
            LEFT JOIN po_item_receipts reversed ON reversed.reversal_of=h.id
            LEFT JOIN (SELECT l.receipt_line_id,SUM(l.quantity) AS quantity,SUM(l.line_total) AS amount FROM po_vendor_bill_lines l JOIN po_vendor_bills b ON b.id=l.bill_id AND b.status='recorded' GROUP BY l.receipt_line_id)b ON b.receipt_line_id=r.id
            WHERE h.order_id=? AND reversed.id IS NULL ORDER BY h.id,r.id",[$order]);
    }
    private function billAmounts(int $order,string $date,array $quantities): array {
        $known=array_column($this->billable($order),null,'receipt_line_id');$lines=[];$subtotal='0.00';
        foreach($quantities as $id=>$qty){
            if(!isset($known[$id]))throw new \InvalidArgumentException('Bill line must reference an unreversed item receipt for this order.');
            if(bccomp($qty,'0',4)===0)continue;$line=$known[$id];
            if($date<$line['received_date'])throw new \InvalidArgumentException('Bill date cannot precede any selected item receipt.');
            if(bccomp($qty,$line['unbilled'],4)>0)throw new \RuntimeException('Billed quantity exceeds the received, unbilled quantity.');
            $cumulative=bcadd($line['billed_quantity'],$qty,4);
            $value=Money::decimal(bcdiv(bcmul($line['received_amount'],$cumulative,12),$line['received_quantity'],12));
            $amount=Money::subtract($value,$line['billed_amount']);
            if(Money::compare($amount,'0')<=0)throw new \InvalidArgumentException('Each bill line must have a value of at least NPR 0.01.');
            $subtotal=Money::add($subtotal,$amount);$lines[]=['receipt_line_id'=>$id,'quantity'=>$qty,'line_total'=>$amount];
        }
        if(!$lines)throw new \InvalidArgumentException('Bill at least one received quantity.');
        $rate=$this->row('SELECT tax_rate FROM po_orders WHERE id=?',[$order])['tax_rate'];
        $prior=$this->row('SELECT COALESCE(SUM(subtotal),0) AS subtotal,COALESCE(SUM(tax),0) AS tax FROM po_invoiced_totals WHERE order_id=?',[$order]);
        $tax=Money::subtract(Money::decimal(bcdiv(bcmul(Money::add($prior['subtotal'],$subtotal),$rate,8),'100',8)),$prior['tax']);
        return ['lines'=>$lines,'subtotal'=>$subtotal,'tax'=>$tax,'total'=>Money::add($subtotal,$tax)];
    }
    public function saveBill(array $input): int {
        $this->permission('purchases',true);
        return $this->atomic(function()use($input){
            $orderId=$this->id($input['order_id']??'');$order=$this->order($orderId);$id=empty($input['id'])?0:$this->id($input['id']);
            $date=$this->date($input['bill_date']??'');$invoice=$this->text($input['invoice_number']??'',100,true);$notes=$this->text($input['notes']??'',2000);
            $quantities=$this->quantities($input['lines']??null,'receipt_line_id');$this->openDate($date);
            $hash=hash('sha256',json_encode([$orderId,$date,$invoice,$notes,$quantities],JSON_THROW_ON_ERROR));
            if($id){
                $old=$this->row('SELECT * FROM po_vendor_bills WHERE id=? FOR UPDATE',[$id]);if(!$old||$old['status']!=='draft')throw new \RuntimeException('Only draft bills can be edited.');
                if(!$this->isAdmin()&&(int)$old['created_by']!==$this->user)throw new \DomainException('Only the creator or an administrator can edit this draft.');
                $this->version($old,$input['version']??'');if((int)$old['order_id']!==$orderId)throw new \InvalidArgumentException('A bill cannot change purchase order.');
            } else {
                $key=$this->key($input['request_key']??'');$old=$this->row('SELECT * FROM po_vendor_bills WHERE request_key=? FOR UPDATE',[$key]);
                if($old){if(!hash_equals($old['payload_hash'],$hash))throw new \UnexpectedValueException('Request key already used for another bill.');return(int)$old['id'];}
            }
            if($this->row('SELECT transaction_id FROM po_supplier_invoices WHERE vendor_id=? AND invoice_number=?',[$order['vendor_id'],$invoice]))throw new \UnexpectedValueException('This supplier invoice has already been recorded.');
            $data=$this->billAmounts($orderId,$date,$quantities);
            if($id){
                $this->query('UPDATE po_vendor_bills SET invoice_number=?,bill_date=?,notes=?,subtotal=?,tax=?,total=?,payload_hash=?,version=version+1 WHERE id=?',[$invoice,$date,$notes,$data['subtotal'],$data['tax'],$data['total'],$hash,$id]);
                $this->query('DELETE FROM po_vendor_bill_lines WHERE bill_id=?',[$id]);
            } else {
                $this->query('INSERT INTO po_vendor_bills(reference,order_id,vendor_id,invoice_number,bill_date,notes,subtotal,tax,total,request_key,payload_hash,created_by,created_username) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',['VB-TEMP-'.bin2hex(random_bytes(8)),$orderId,$order['vendor_id'],$invoice,$date,$notes,$data['subtotal'],$data['tax'],$data['total'],$key,$hash,$this->user,$this->actor['username']]);
                $id=(int)$this->db->insert_id;$this->query('UPDATE po_vendor_bills SET reference=? WHERE id=?',['VB-'.str_pad((string)$id,8,'0',STR_PAD_LEFT),$id]);
            }
            foreach($data['lines']as $line)$this->query('INSERT INTO po_vendor_bill_lines(bill_id,receipt_line_id,quantity,line_total) VALUES(?,?,?,?)',[$id,$line['receipt_line_id'],$line['quantity'],$line['line_total']]);
            $this->audit('save_vendor_bill','vendor_bill',$id,['order_id'=>$orderId,'total'=>$data['total']]);return $id;
        });
    }
    public function recordBill(int $id,$version): int {
        $this->permission('purchases',true);
        return $this->atomic(function()use($id,$version){
            $bill=$this->row('SELECT * FROM po_vendor_bills WHERE id=? FOR UPDATE',[$id]);if(!$bill)throw new \RuntimeException('Bill not found.');
            if($bill['status']==='recorded')return(int)$bill['transaction_id'];
            if($bill['status']!=='draft')throw new \RuntimeException('Only draft bills can be recorded.');$this->version($bill,$version);
            if(!$this->isAdmin()&&(int)$bill['created_by']!==$this->user)throw new \DomainException('Only the creator or an administrator can record this bill.');
            $order=$this->order((int)$bill['order_id']);$this->openDate($bill['bill_date']);
            $quantities=array_column($this->rows('SELECT receipt_line_id,quantity FROM po_vendor_bill_lines WHERE bill_id=? ORDER BY receipt_line_id',[$id]),'quantity','receipt_line_id');
            $data=$this->billAmounts((int)$bill['order_id'],$bill['bill_date'],$quantities);
            foreach(['subtotal','tax','total']as $field)if(Money::compare($data[$field],$bill[$field])!==0)throw new \UnexpectedValueException('Billing quantities or rounding changed. Reopen and save this draft before recording.');
            $stored=$this->rows('SELECT receipt_line_id,quantity,line_total FROM po_vendor_bill_lines WHERE bill_id=? ORDER BY receipt_line_id',[$id]);
            foreach($data['lines']as $index=>$line)if(Money::compare($line['line_total'],$stored[$index]['line_total'])!==0)throw new \UnexpectedValueException('Bill allocations changed. Save the draft again.');
            if($this->row('SELECT transaction_id FROM po_supplier_invoices WHERE vendor_id=? AND invoice_number=?',[$bill['vendor_id'],$bill['invoice_number']]))throw new \UnexpectedValueException('This supplier invoice has already been recorded.');
            $this->query("INSERT INTO transactions(reference_code,type,entity_type,entity_id,total_amount,tax_perc,tax,remarks,transaction_date,created_by) VALUES(?,'purchase','Supplier',?,?,?,?,?,?,?)",[$bill['reference'],$bill['vendor_id'],$bill['subtotal'],$order['tax_rate'],$bill['tax'],$order['reference'].' / Supplier invoice: '.$bill['invoice_number'].($bill['notes']!==''?"\n".$bill['notes']:''),$bill['bill_date'],$this->user]);
            $transaction=(int)$this->db->insert_id;$location=(int)($this->row('SELECT location_id FROM erp_po_context WHERE order_id=?',[$order['id']])['location_id']??1);
            InventoryService::context($this->db,$transaction,$location);
            $this->query('INSERT INTO po_supplier_invoices(vendor_id,invoice_number,transaction_id) VALUES(?,?,?)',[$bill['vendor_id'],$bill['invoice_number'],$transaction]);
            $this->query("UPDATE po_vendor_bills SET status='recorded',transaction_id=?,version=version+1,recorded_at=NOW() WHERE id=?",[$transaction,$id]);
            require_once __DIR__.'/OrderService.php';
            (new OrderService($this->db,$this->user))->purchaseMetrics($bill['bill_date']);
            $this->index($transaction,'purchase',$bill['reference'],$bill['bill_date'],$bill['total'],$order['vendor_name'],'transactions/vendor_bills&id='.$id);
            $this->audit('record_vendor_bill','vendor_bill',$id,['transaction_id'=>$transaction,'total'=>$bill['total']]);return $transaction;
        });
    }
    public function cancelBill(int $id,$version,string $reason): void {
        $this->permission('purchases',true);
        $this->atomic(function()use($id,$version,$reason){
            $bill=$this->row('SELECT * FROM po_vendor_bills WHERE id=? FOR UPDATE',[$id]);if(!$bill||$bill['status']!=='draft')throw new \RuntimeException('Only draft bills can be cancelled.');
            if(!$this->isAdmin()&&(int)$bill['created_by']!==$this->user)throw new \DomainException('Only the creator or an administrator can cancel this bill.');
            $this->version($bill,$version);$reason=$this->text($reason,800,true);
            $this->query("UPDATE po_vendor_bills SET status='cancelled',version=version+1 WHERE id=?",[$id]);$this->audit('cancel_vendor_bill','vendor_bill',$id,['reason'=>$reason]);
        });
    }
    public function bill(int $id): array {
        $this->permission('purchases');
        $bill=$this->row('SELECT b.*,o.reference AS order_reference,o.vendor_name,o.tax_rate,j.id AS journal_id,j.reference AS journal_reference FROM po_vendor_bills b JOIN po_orders o ON o.id=b.order_id LEFT JOIN gl_journals j ON j.source_transaction_id=b.transaction_id AND j.status=\'posted\' WHERE b.id=?',[$id]);
        if(!$bill)throw new \RuntimeException('Supplier bill not found.');
        $bill['lines']=$this->rows('SELECT l.*,h.reference AS receipt_reference,h.id AS receipt_id,o.item_name,o.item_id,o.unit,o.unit_price FROM po_vendor_bill_lines l JOIN po_item_receipt_lines r ON r.id=l.receipt_line_id JOIN po_item_receipts h ON h.id=r.receipt_id JOIN po_lines o ON o.id=r.order_line_id WHERE l.bill_id=? ORDER BY l.receipt_line_id',[$id]);
        $bill['payments']=$bill['transaction_id']?$this->rows("SELECT t.id,t.reference_code,t.transaction_date,t.total_amount,a.name AS account_name,j.id AS journal_id FROM transactions t LEFT JOIN account_list a ON a.id=t.account_id LEFT JOIN gl_journals j ON j.source_transaction_id=t.id AND j.status='posted' WHERE t.type='payment' AND t.parent_id=? ORDER BY t.transaction_date,t.id",[$bill['transaction_id']]):[];
        require_once __DIR__.'/../Enterprise/Settlement.php';
        $paid='0.00';foreach($bill['payments']as $payment)$paid=Money::add($paid,$payment['total_amount']);
        $bill['credit_applied']=$bill['transaction_id']?Money::subtract('0',\App\Enterprise\Settlement::adjustment($this->db,(int)$bill['transaction_id'])):'0.00';
        $bill['paid']=$paid;$bill['balance']=Money::subtract(Money::subtract($bill['total'],$paid),$bill['credit_applied']);return $bill;
    }
    public function bills(?int $order=null): array {
        $this->permission('purchases');
        return $this->rows('SELECT b.*,o.reference AS order_reference,o.vendor_name,j.id AS journal_id FROM po_vendor_bills b JOIN po_orders o ON o.id=b.order_id LEFT JOIN gl_journals j ON j.source_transaction_id=b.transaction_id AND j.status=\'posted\''.($order?' WHERE b.order_id=?':'').' ORDER BY b.id DESC LIMIT 250',$order?[$order]:[]);
    }
    public function payBill(array $input): int {
        $this->permission('payments',true);$this->permission('purchases');
        return $this->atomic(function()use($input){
            $id=$this->id($input['bill_id']??'');$account=$this->id($input['account_id']??'');
            $date=$this->date($input['payment_date']??'');$amount=$this->decimal($input['amount']??'',2,true);
            $notes=$this->text($input['notes']??'',1000);$key=$this->key($input['request_key']??'');
            $hash=hash('sha256',json_encode([$id,$account,$date,$amount,$notes],JSON_THROW_ON_ERROR));
            $old=$this->row('SELECT * FROM po_cycle_payments WHERE request_key=?',[$key]);
            if($old){if(!hash_equals($old['payload_hash'],$hash))throw new \UnexpectedValueException('Request key already used for another payment.');return(int)$old['transaction_id'];}
            $bill=$this->bill($id);
            if($bill['status']!=='recorded'||!$bill['journal_id'])throw new \RuntimeException('Post the supplier bill to accounting before payment.');
            if($date<$bill['bill_date'])throw new \InvalidArgumentException('Payment cannot precede the bill.');$this->openDate($date);
            if(Money::compare($amount,$bill['balance'])>0)throw new \RuntimeException('Payment exceeds the outstanding bill balance.');
            if(!$this->row('SELECT id FROM account_list WHERE id=? AND status=1 FOR UPDATE',[$account]))throw new \InvalidArgumentException('Choose an active payment account.');
            $this->query("INSERT INTO transactions(reference_code,type,parent_id,entity_id,account_id,total_amount,tax,remarks,transaction_date,created_by) VALUES(?,'payment',?,?,?,?,0,?,?,?)",['BP-TEMP-'.bin2hex(random_bytes(8)),$bill['transaction_id'],$bill['vendor_id'],$account,$amount,$notes,$date,$this->user]);
            $transaction=(int)$this->db->insert_id;$reference='BP-'.str_pad((string)$transaction,8,'0',STR_PAD_LEFT);
            if($this->row('SELECT id FROM transactions WHERE reference_code=? LIMIT 1',[$reference]))$reference.='-'.bin2hex(random_bytes(4));
            $this->query('UPDATE transactions SET reference_code=? WHERE id=?',[$reference,$transaction]);
            $this->query("INSERT INTO transaction_list(trans_code,account_id,type,amount,remarks,ref_id,ref_table,date_created) VALUES(?,?,2,?,?,?,'transactions',?)",[$reference,$account,$amount,$notes,$transaction,$date.' 12:00:00']);
            $this->query('UPDATE account_list SET balance=(SELECT COALESCE(SUM(IF(type=1,amount,-amount)),0) FROM transaction_list WHERE account_id=?) WHERE id=?',[$account,$account]);
            $this->query('INSERT INTO po_cycle_payments(transaction_id,bill_id,request_key,payload_hash) VALUES(?,?,?,?)',[$transaction,$id,$key,$hash]);
            $this->index($transaction,'payment',$reference,$date,$amount,$bill['vendor_name'],'transactions/vendor_bills&id='.$id);
            $this->audit('pay_vendor_bill','vendor_bill',$id,['transaction_id'=>$transaction,'amount'=>$amount,'account_id'=>$account]);return $transaction;
        });
    }
}
