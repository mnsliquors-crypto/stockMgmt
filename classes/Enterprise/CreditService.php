<?php
namespace App\Enterprise;
require_once __DIR__.'/ReportService.php';
use App\Accounting\Money;

final class CreditService extends Service {
    public function credits(): array {
        $rows=$this->rows("SELECT r.id,r.reference_code,r.transaction_date,r.parent_id,r.entity_id,r.total_amount+r.tax AS credit,
            p.reference_code AS source_invoice,p.type AS invoice_type,e.display_name AS party,
            COALESCE(a.allocated,0) AS allocated
            FROM transactions r JOIN transactions p ON p.id=r.parent_id JOIN entity_list e ON e.id=r.entity_id
            JOIN gl_journals j ON j.source_transaction_id=r.id AND j.status='posted'
            LEFT JOIN (SELECT return_id,SUM(IF(reversal_of IS NULL,amount,-amount)) AS allocated FROM erp_credit_allocations GROUP BY return_id) a ON a.return_id=r.id
            WHERE r.type='return' AND p.type IN ('sale','purchase') ORDER BY r.transaction_date,r.id");
        $reports=new ReportService($this->db,$this->user);$documents=[];
        foreach (['sale','purchase'] as $type) foreach ($reports->openDocuments($type,'9999-12-31') as $doc) $documents[$doc['id']]=$doc;
        $budgets=[];
        foreach ($rows as &$row) {
            $source=(int)$row['parent_id'];
            if (!isset($budgets[$source])) {
                $balance=$documents[$source]['outstanding']??'0';
                $budgets[$source]=Money::compare($balance,'0')<0?Money::subtract('0',$balance):'0.00';
            }
            $remaining=Money::subtract($row['credit'],$row['allocated']);
            $row['available']=Money::compare($remaining,$budgets[$source])<0?$remaining:$budgets[$source];
            if (Money::compare($row['available'],'0')<0) $row['available']='0.00';
            $budgets[$source]=Money::subtract($budgets[$source],$row['available']);
        }
        unset($row);return $rows;
    }
    public function allocations(): array {
        return $this->rows("SELECT a.*,r.reference_code AS credit,t.reference_code AS invoice,e.display_name AS party,x.id AS reversal_id
            FROM erp_credit_allocations a JOIN transactions r ON r.id=a.return_id JOIN transactions t ON t.id=a.invoice_id
            JOIN entity_list e ON e.id=r.entity_id LEFT JOIN erp_credit_allocations x ON x.reversal_of=a.id ORDER BY a.id DESC LIMIT 250");
    }
    public function apply(array $input): int {
        return $this->atomic(function () use ($input) {
            $credit=$this->id($input['return_id']??'');$invoice=$this->id($input['invoice_id']??'');
            $amount=$this->decimal($input['amount']??'',2,true);$date=$this->date($input['allocation_date']??'');$this->openDate($date);
            $reason=$this->text($input['reason']??'',800,true);$key=$this->key($input['request_key']??'');
            $hash=hash('sha256',json_encode([$credit,$invoice,$amount,$date,$reason],JSON_THROW_ON_ERROR));
            $old=$this->row('SELECT * FROM erp_credit_allocations WHERE request_key=?',[$key]);
            if ($old) {if(!hash_equals($old['payload_hash'],$hash))throw new \UnexpectedValueException('Request key already used for different credit details.');return (int)$old['id'];}
            $returned=$this->row("SELECT r.*,p.type AS source_type FROM transactions r JOIN transactions p ON p.id=r.parent_id WHERE r.id=? AND r.type='return' FOR UPDATE",[$credit]);
            $target=$this->row('SELECT * FROM transactions WHERE id=? FOR UPDATE',[$invoice]);
            if (!$returned||!$target||!in_array($returned['source_type'],['sale','purchase'],true)||$target['type']!==$returned['source_type']||!$returned['entity_id']||$target['entity_id']!=$returned['entity_id']||$target['id']==$returned['parent_id']) throw new \InvalidArgumentException('Choose a different invoice for the same party and document type.');
            if ($date<$returned['transaction_date']||$date<$target['transaction_date']) throw new \InvalidArgumentException('Credit application cannot precede the return or target invoice.');
            foreach ([$credit,$invoice,(int)$returned['parent_id']] as $document) if (!$this->row("SELECT id FROM gl_journals WHERE source_transaction_id=? AND status='posted'",[$document])) throw new \RuntimeException('Post the source invoice, return, and target invoice before applying credit.');
            $reports=new ReportService($this->db,$this->user);
            $all=array_column($reports->openDocuments($target['type'],'9999-12-31',(int)$returned['entity_id']),null,'id');
            $asOf=array_column($reports->openDocuments($target['type'],$date,(int)$returned['entity_id']),null,'id');
            $source=$returned['parent_id'];
            $available=Money::subtract('0',$all[$source]['outstanding']??'0');
            $historical=Money::subtract('0',$asOf[$source]['outstanding']??'0');
            if (Money::compare($historical,$available)<0)$available=$historical;
            $allocated=$this->row('SELECT COALESCE(SUM(IF(reversal_of IS NULL,amount,-amount)),0) AS amount FROM erp_credit_allocations WHERE return_id=?',[$credit])['amount'];
            $remaining=Money::subtract(Money::add($returned['total_amount'],$returned['tax']),$allocated);
            if (Money::compare($amount,$available)>0||Money::compare($amount,$remaining)>0) throw new \RuntimeException('Credit exceeds the unapplied, paid-invoice return balance.');
            if (Money::compare($amount,$all[$invoice]['outstanding']??'0')>0||Money::compare($amount,$asOf[$invoice]['outstanding']??'0')>0) throw new \RuntimeException('Credit exceeds the target invoice outstanding balance.');
            $this->query('INSERT INTO erp_credit_allocations(return_id,invoice_id,allocation_date,amount,reason,request_key,payload_hash,created_by) VALUES (?,?,?,?,?,?,?,?)',[$credit,$invoice,$date,$amount,$reason,$key,$hash,$this->user]);
            $id=(int)$this->db->insert_id;$this->audit('apply_return_credit','credit_allocation',$id,['return_id'=>$credit,'invoice_id'=>$invoice,'amount'=>$amount,'reason'=>$reason]);return $id;
        });
    }
    public function reverse(int $id,string $date,string $reason): int {
        $this->admin();
        return $this->atomic(function () use ($id,$date,$reason) {
            $old=$this->row('SELECT * FROM erp_credit_allocations WHERE id=? FOR UPDATE',[$id]);
            if (!$old||$old['reversal_of']) throw new \InvalidArgumentException('Choose an original credit application.');
            $existing=$this->row('SELECT id FROM erp_credit_allocations WHERE reversal_of=?',[$id]);if($existing)return(int)$existing['id'];
            $date=$this->date($date);$this->openDate($date);$reason=$this->text($reason,800,true);
            if($date<$old['allocation_date'])throw new \InvalidArgumentException('Reversal cannot precede the original credit application.');
            $key='reverse-'.bin2hex(random_bytes(16));
            $this->query('INSERT INTO erp_credit_allocations(return_id,invoice_id,allocation_date,amount,reason,request_key,payload_hash,created_by,reversal_of) VALUES (?,?,?,?,?,?,?,?,?)',[$old['return_id'],$old['invoice_id'],$date,$old['amount'],$reason,$key,hash('sha256',$key),$this->user,$id]);
            $reversal=(int)$this->db->insert_id;$this->audit('reverse_credit_application','credit_allocation',$reversal,['original_id'=>$id,'reason'=>$reason]);return $reversal;
        });
    }
}
