<?php
namespace App\Accounting;
require_once __DIR__ . '/Money.php';

final class JournalService {
    private \mysqli $db;
    private int $actor;

    public function __construct(\mysqli $db, int $actor) { $this->db = $db; $this->actor = $actor; }

    private function query(string $sql, array $parameters = []) {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($parameters);
        return $stmt->get_result();
    }
    private function row(string $sql, array $parameters = []): ?array { return $this->query($sql, $parameters)->fetch_assoc(); }
    private function rows(string $sql, array $parameters = []): array { return $this->query($sql, $parameters)->fetch_all(MYSQLI_ASSOC); }
    private function atomic(callable $work) {
        $this->db->begin_transaction();
        try { $result = $work(); $this->db->commit(); return $result; }
        catch (\Throwable $error) { $this->db->rollback(); throw $error; }
    }
    private function date(string $value): string {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new \InvalidArgumentException('Enter a valid date.');
        return $value;
    }
    private function audit(string $action, string $type, int $id, array $details = []): void {
        $details['actor_username']=$this->row('SELECT username FROM users WHERE id=?',[$this->actor])['username']??('#'.$this->actor);
        $this->query('INSERT INTO erp_audit_events (actor_id,action,object_type,object_id,details) VALUES (?,?,?,?,?)',
            [$this->actor, $action, $type, $id, json_encode($details, JSON_THROW_ON_ERROR)]);
    }
    private function assertOpen(string $date): void {
        $this->query('SELECT id FROM erp_control WHERE id=1 LOCK IN SHARE MODE');
        $period = $this->row('SELECT * FROM gl_periods WHERE ? BETWEEN start_date AND end_date LOCK IN SHARE MODE', [$this->date($date)]);
        if (!$period || $period['status'] !== 'open') throw new \RuntimeException('Create or reopen an accounting period covering this date.');
    }

    private function validateLines(array $lines): array {
        if (count($lines) < 2 || count($lines) > 200) throw new \InvalidArgumentException('A journal needs between 2 and 200 lines.');
        $clean = [];
        foreach ($lines as $line) {
            if (!is_array($line)) throw new \InvalidArgumentException('Invalid journal line.');
            foreach (['account_id','debit','credit','description'] as $field) {
                if (isset($line[$field]) && !is_scalar($line[$field])) throw new \InvalidArgumentException('Invalid journal line value.');
            }
            $account = $this->row('SELECT id FROM gl_accounts WHERE id=? AND active=1', [(int)($line['account_id'] ?? 0)]);
            if (!$account) throw new \InvalidArgumentException('Choose an active account for every line.');
            $debit = Money::decimal($line['debit'] ?? '0');
            $credit = Money::decimal($line['credit'] ?? '0');
            if (Money::compare($debit, '0') < 0 || Money::compare($credit, '0') < 0
                || (Money::compare($debit, '0') > 0) === (Money::compare($credit, '0') > 0)) {
                throw new \InvalidArgumentException('Each line needs either a positive debit or a positive credit.');
            }
            $description = trim((string)($line['description'] ?? ''));
            if (mb_strlen($description) > 500) throw new \InvalidArgumentException('Line description is too long.');
            $clean[] = ['account_id' => (int)$account['id'], 'description' => $description, 'debit' => $debit, 'credit' => $credit];
        }
        return $clean;
    }

    private function writeDraft(string $date, string $memo, array $lines, string $key, ?int $source = null, ?int $reversal = null): int {
        $this->assertOpen($date);
        $memo = trim($memo);
        if ($memo === '' || mb_strlen($memo) > 1000) throw new \InvalidArgumentException('Enter a memo of at most 1000 characters.');
        if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $key)) throw new \InvalidArgumentException('Invalid request key. Refresh the page.');
        $lines = $this->validateLines($lines);
        $hash = hash('sha256', json_encode([$date,$memo,$lines,$source,$reversal], JSON_THROW_ON_ERROR));
        $existing = $this->row('SELECT id,payload_hash FROM gl_journals WHERE request_key=? FOR UPDATE', [$key]);
        if ($existing) {
            if (!hash_equals($existing['payload_hash'], $hash)) throw new \RuntimeException('This request key was already used for different data.');
            return (int)$existing['id'];
        }
        $this->query('INSERT INTO gl_journals (reference,entry_date,memo,source_transaction_id,reversal_of_id,request_key,payload_hash,created_by) VALUES (?,?,?,?,?,?,?,?)',
            ['NEW-' . bin2hex(random_bytes(8)), $date, $memo, $source, $reversal, $key, $hash, $this->actor]);
        $id = (int)$this->db->insert_id;
        $this->query('UPDATE gl_journals SET reference=? WHERE id=?', ['JE-' . str_pad((string)$id, 8, '0', STR_PAD_LEFT), $id]);
        foreach ($lines as $line) $this->query('INSERT INTO gl_journal_lines (journal_id,account_id,description,debit,credit) VALUES (?,?,?,?,?)',
            [$id,$line['account_id'],$line['description'],$line['debit'],$line['credit']]);
        $this->audit('create_draft', 'journal', $id, ['source_transaction_id' => $source]);
        return $id;
    }

    public function saveDraft(array $input): int {
        return $this->atomic(function () use ($input) {
            $date = $this->date((string)($input['entry_date'] ?? ''));
            $id = (int)($input['id'] ?? 0);
            if (!$id) return $this->writeDraft($date, (string)($input['memo'] ?? ''), $input['lines'] ?? [], (string)($input['request_key'] ?? ''));
            $journal = $this->row('SELECT * FROM gl_journals WHERE id=? FOR UPDATE', [$id]);
            if (!$journal || $journal['status'] !== 'draft' || $journal['source_transaction_id'] || $journal['reversal_of_id']) {
                throw new \RuntimeException('Only manual draft journals can be edited.');
            }
            $this->assertOpen($journal['entry_date']); $this->assertOpen($date);
            $memo = trim((string)($input['memo'] ?? ''));
            if ($memo === '' || mb_strlen($memo) > 1000) throw new \InvalidArgumentException('Enter a memo of at most 1000 characters.');
            $lines = $this->validateLines($input['lines'] ?? []);
            $this->query('DELETE FROM gl_journal_lines WHERE journal_id=?', [$id]);
            foreach ($lines as $line) $this->query('INSERT INTO gl_journal_lines (journal_id,account_id,description,debit,credit) VALUES (?,?,?,?,?)',
                [$id,$line['account_id'],$line['description'],$line['debit'],$line['credit']]);
            $hash = hash('sha256', json_encode([$date,$memo,$lines,null,null], JSON_THROW_ON_ERROR));
            $this->query('UPDATE gl_journals SET entry_date=?,memo=?,payload_hash=? WHERE id=?', [$date,$memo,$hash,$id]);
            $this->audit('edit_draft', 'journal', $id);
            return $id;
        });
    }

    private function postLocked(int $id): void {
        $journal = $this->row('SELECT * FROM gl_journals WHERE id=? FOR UPDATE', [$id]);
        if (!$journal) throw new \RuntimeException('Journal not found.');
        if ($journal['status'] === 'posted') return;
        $this->assertOpen($journal['entry_date']);
        $totals = $this->row('SELECT COUNT(*) AS line_count,COALESCE(SUM(debit),0) AS debit,COALESCE(SUM(credit),0) AS credit FROM gl_journal_lines WHERE journal_id=?', [$id]);
        if ($totals['line_count'] < 2 || Money::compare($totals['debit'], $totals['credit']) !== 0 || Money::compare($totals['debit'], '0') <= 0) {
            throw new \RuntimeException('Debits and credits must balance before posting.');
        }
        $inactive = $this->row('SELECT l.id FROM gl_journal_lines l JOIN gl_accounts a ON a.id=l.account_id WHERE l.journal_id=? AND a.active<>1 LIMIT 1', [$id]);
        if ($inactive) throw new \RuntimeException('A journal account is inactive.');
        $this->query("UPDATE gl_journals SET status='posted',posted_by=?,posted_at=NOW() WHERE id=?", [$this->actor,$id]);
        $this->audit('post', 'journal', $id, ['debit' => $totals['debit'], 'credit' => $totals['credit']]);
    }
    public function postJournal(int $id): int { return $this->atomic(function () use ($id) { $this->postLocked($id); return $id; }); }

    public function deleteDraft(int $id): void {
        $this->atomic(function () use ($id) {
            $journal=$this->row('SELECT * FROM gl_journals WHERE id=? FOR UPDATE',[$id]);
            if (!$journal || $journal['status']!=='draft' || $journal['source_transaction_id']) throw new \RuntimeException('Only manual drafts can be discarded.');
            $this->assertOpen($journal['entry_date']);
            $this->query('DELETE FROM gl_journal_lines WHERE journal_id=?',[$id]);
            $this->query('DELETE FROM gl_journals WHERE id=?',[$id]);
            $this->audit('discard_draft','journal',$id,['reference'=>$journal['reference']]);
        });
    }

    public function reverseJournal(int $id, string $date, string $reason): int {
        return $this->atomic(function () use ($id,$date,$reason) {
            $original = $this->row('SELECT * FROM gl_journals WHERE id=? FOR UPDATE', [$id]);
            if (!$original || $original['status'] !== 'posted' || $original['reversal_of_id']) throw new \RuntimeException('Choose an original posted journal to reverse.');
            if ($original['source_transaction_id']) throw new \RuntimeException('Use a linked return to correct source documents. Manual reversal would leave inventory or payments inconsistent.');
            $existing = $this->row('SELECT id FROM gl_journals WHERE reversal_of_id=?', [$id]);
            if ($existing) return (int)$existing['id'];
            if ($this->date($date) < $original['entry_date']) throw new \InvalidArgumentException('A reversal cannot precede its original journal.');
            if (trim($reason) === '') throw new \InvalidArgumentException('A reversal reason is required.');
            $lines = $this->rows('SELECT account_id,description,credit AS debit,debit AS credit FROM gl_journal_lines WHERE journal_id=? ORDER BY id', [$id]);
            $new = $this->writeDraft($date, 'Reversal of ' . $original['reference'] . ': ' . $reason, $lines, 'reverse-' . bin2hex(random_bytes(16)), null, $id);
            $this->postLocked($new);
            $this->audit('reverse', 'journal', $id, ['reversal_id' => $new, 'reason' => $reason]);
            return $new;
        });
    }

    private function systemAccount(string $key): int {
        $account = $this->row('SELECT id FROM gl_accounts WHERE system_key=? AND active=1', [$key]);
        if (!$account) throw new \RuntimeException('Missing active posting account: ' . $key);
        return (int)$account['id'];
    }
    private function bankAccount(int $id): int {
        $bank = $this->row('SELECT id,name FROM account_list WHERE id=? FOR UPDATE', [$id]);
        if (!$bank) throw new \RuntimeException('The cash or bank account does not exist.');
        $mapping = $this->row('SELECT gl_account_id FROM gl_bank_mapping WHERE bank_account_id=?', [$id]);
        if ($mapping) return (int)$mapping['gl_account_id'];
        $this->query("INSERT INTO gl_accounts (code,name,category) VALUES (?,?,'asset')", ['BANK-' . $id,$bank['name']]);
        $gl = (int)$this->db->insert_id;
        $this->query('INSERT INTO gl_bank_mapping (bank_account_id,gl_account_id) VALUES (?,?)', [$id,$gl]);
        return $gl;
    }
    private function movement(array &$lines, int $account, string $amount, bool $debit, string $description = ''): void {
        if (Money::compare($amount,'0') === 0) return;
        if (Money::compare($amount,'0') < 0) { $amount = Money::subtract('0',$amount); $debit = !$debit; }
        foreach($lines as &$line)if((int)$line['account_id']===$account&&$line['description']===$description&&($debit?Money::compare($line['debit'],'0')>0:Money::compare($line['credit'],'0')>0)){
            $field=$debit?'debit':'credit';$line[$field]=Money::add($line[$field],$amount);return;
        }unset($line);
        $lines[] = ['account_id'=>$account,'debit'=>$debit?$amount:'0.00','credit'=>$debit?'0.00':$amount,'description'=>$description];
    }
    private function itemStock(array $item): bool {return in_array($item['catalog_type']??'inventory',['inventory','assembly'],true);}
    private function itemAccount(array $item,string $field,string $fallback): int {return !empty($item[$field])?(int)$item[$field]:$this->systemAccount($fallback);}
    private function itemValues(array &$lines,array $items,string $amount,bool $debit,bool $revenue=false): void {
        $sum='0.00';foreach($items as $item)$sum=Money::add($sum,$item['total_price']);
        if(Money::compare($sum,'0')===0){if(Money::compare($amount,'0')===0)return;throw new \RuntimeException('Item values do not match the document.');}
        $cumulative='0.00';$allocated='0.00';
        foreach($items as $item){
            $cumulative=Money::add($cumulative,$item['total_price']);$value=Money::decimal(bcdiv(bcmul($amount,$cumulative,12),$sum,12));
            $part=Money::subtract($value,$allocated);$allocated=$value;
            $field=$revenue?'income_account_id':($this->itemStock($item)?'inventory_account_id':'expense_account_id');
            $fallback=$revenue?'sales':($this->itemStock($item)?'inventory':'expenses');
            $this->movement($lines,$this->itemAccount($item,$field,$fallback),$part,$debit);
        }
    }
    private function sourceLines(array $source): array {
        $id = (int)$source['id']; $type = $source['type'];
        $amount = Money::decimal($source['total_amount']); $tax = Money::decimal($source['tax']);
        if (Money::compare($tax,'0')<0 || (in_array($type,['sale','purchase','payment','return','expense'],true) && Money::compare($amount,'0')<0)) throw new \RuntimeException('Document and tax amounts must be nonnegative.');
        $total = Money::add($amount,$tax); $lines = [];
        $catalog=$this->db->query("SHOW TABLES LIKE 'erp_line_catalog'")->num_rows>0;
        $items = $this->rows($catalog?'SELECT i.*,COALESCE(c.item_type,p.item_type) AS catalog_type,c.inventory_account_id,c.income_account_id,c.expense_account_id FROM transaction_items i JOIN item_list p ON p.id=i.item_id LEFT JOIN erp_line_catalog c ON c.line_id=i.id WHERE i.transaction_id=? ORDER BY i.id FOR UPDATE':'SELECT * FROM transaction_items WHERE transaction_id=? ORDER BY id FOR UPDATE', [$id]);
        $opening = str_starts_with((string)$source['remarks'], 'Opening Balance');
        if ($type === 'sale') {
            $this->movement($lines,$this->systemAccount('receivables'),$total,true);
            if($opening)$this->movement($lines,$this->systemAccount('opening_equity'),$amount,false);
            else $this->itemValues($lines,$items,$amount,false,true);
            $this->movement($lines,$this->systemAccount('output_tax'),$tax,false);
            if (!$opening) {
                if (!$items) throw new \RuntimeException('This sale has no item lines.');
                $cost = '0.00';
                foreach ($items as $item) {
                    if(!$this->itemStock($item))continue;
                    if (!(int)$item['cost_recorded']) throw new \RuntimeException('Historical sale cost is missing. Reconcile its original costs before posting.');
                    $cost = Money::add($cost,Money::multiply($item['quantity'],$item['cost_price']));
                    $this->movement($lines,$this->itemAccount($item,'expense_account_id','cogs'),Money::multiply($item['quantity'],$item['cost_price']),true);
                    $this->movement($lines,$this->itemAccount($item,'inventory_account_id','inventory'),Money::multiply($item['quantity'],$item['cost_price']),false);
                }
            }
        } elseif ($type === 'item_receipt') {
            $receipt=$this->row('SELECT r.* FROM po_item_receipts r JOIN po_item_receipt_seals s ON s.receipt_id=r.id WHERE r.transaction_id=?',[$id]);
            if(!$receipt||!$items||Money::compare($tax,'0')!==0)throw new \RuntimeException('Item receipt is incomplete or contains invoice tax.');
            $value='0.00';foreach($items as $item)$value=Money::add($value,$item['total_price']);
            if(Money::compare($value,$amount)!==0)throw new \RuntimeException('Receipt inventory value does not match its document.');
            if($receipt['reversal_of']&&!$this->row("SELECT j.id FROM po_item_receipts r JOIN gl_journals j ON j.source_transaction_id=r.transaction_id AND j.status='posted' WHERE r.id=?",[$receipt['reversal_of']]))throw new \RuntimeException('Post the original item receipt before its reversal.');
            $this->itemValues($lines,$items,$amount,true);
            $this->movement($lines,$this->systemAccount('grir'),$amount,false);
        } elseif ($type === 'purchase') {
            $bill=$this->db->query("SHOW TABLES LIKE 'po_vendor_bills'")->num_rows?$this->row("SELECT id FROM po_vendor_bills WHERE transaction_id=? AND status='recorded'",[$id]):null;
            if($bill){
                $totalLines=$this->row('SELECT COALESCE(SUM(line_total),0) AS amount FROM po_vendor_bill_lines WHERE bill_id=?',[$bill['id']]);
                if($items||Money::compare($totalLines['amount'],$amount)!==0)throw new \RuntimeException('Supplier bill allocation does not match its document.');
                if($this->row("SELECT r.id FROM po_vendor_bill_lines l JOIN po_item_receipt_lines i ON i.id=l.receipt_line_id JOIN po_item_receipts r ON r.id=i.receipt_id LEFT JOIN gl_journals j ON j.source_transaction_id=r.transaction_id AND j.status='posted' WHERE l.bill_id=? AND j.id IS NULL LIMIT 1",[$bill['id']]))throw new \RuntimeException('Post all linked item receipts before posting this supplier bill.');
            } elseif (!$items && !$opening) throw new \RuntimeException('This purchase has no item lines.');
            if($bill||$opening)$this->movement($lines,$this->systemAccount($bill?'grir':'opening_equity'),$amount,true);
            else $this->itemValues($lines,$items,$amount,true);
            $this->movement($lines,$this->systemAccount('input_tax'),$tax,true);
            $this->movement($lines,$this->systemAccount('payables'),$total,false);
        } elseif ($type === 'payment' || $type === 'transfer' || $type === 'opening_balance' || $type === 'expense') {
            $ledger = $this->rows("SELECT account_id,type,amount FROM transaction_list WHERE ref_table='transactions' AND ref_id=? ORDER BY id FOR UPDATE", [$id]);
            if (!$ledger) throw new \RuntimeException('This document has no cash or bank movements.');
            $incoming = '0.00'; $outgoing = '0.00';
            foreach ($ledger as $entry) {
                $value = Money::decimal($entry['amount']); $in = (int)$entry['type'] === 1;
                if ($in) $incoming = Money::add($incoming,$value); else $outgoing = Money::add($outgoing,$value);
                $this->movement($lines,$this->bankAccount((int)$entry['account_id']),$value,$in);
            }
            if ($type === 'payment') {
                $parent = $this->row('SELECT t.type FROM transactions t JOIN gl_journals j ON j.source_transaction_id=t.id AND j.status=\'posted\' WHERE t.id=?', [(int)$source['parent_id']]);
                if (!$parent || !in_array($parent['type'], ['sale','purchase'],true)) throw new \RuntimeException('Post the related sale or purchase before its payment.');
                $sale = $parent['type'] === 'sale';
                if (Money::compare($sale?$incoming:$outgoing,$amount)!==0 || Money::compare($sale?$outgoing:$incoming,'0')!==0) throw new \RuntimeException('Payment movements do not match the document amount.');
                $this->movement($lines,$this->systemAccount($sale?'receivables':'payables'),$amount,!$sale);
            } elseif ($type === 'expense') {
                if ((int)$source['status']!==1 || Money::compare($outgoing,$amount)!==0 || Money::compare($incoming,'0')!==0) throw new \RuntimeException('Only fully paid, reconciled expenses can be posted from this screen.');
                $this->movement($lines,$this->systemAccount('expenses'),$amount,true);
            } elseif ($type === 'opening_balance') {
                $this->movement($lines,$this->systemAccount('opening_equity'),Money::subtract($incoming,$outgoing),false);
            } elseif (Money::compare($incoming,$outgoing)!==0 || Money::compare($incoming,$amount)!==0) throw new \RuntimeException('Transfer movements do not balance.');
        } elseif ($type === 'opening_stock' || $type === 'adjustment') {
            if (!$items) throw new \RuntimeException('This stock document has no item lines.');
            $value = '0.00';
            foreach ($items as $item) $value=Money::add($value,Money::multiply($item['quantity'],$item['unit_price']));
            $this->itemValues($lines,$items,$value,true);
            $this->movement($lines,$this->systemAccount($type==='opening_stock'?'opening_equity':'inventory_adjustments'),$value,false);
        } elseif ($type === 'return') {
            if (!$items) throw new \RuntimeException('This return has no item lines.');
            $parent = $this->row("SELECT t.* FROM transactions t JOIN gl_journals j ON j.source_transaction_id=t.id AND j.status='posted' WHERE t.id=?", [(int)$source['parent_id']]);
            if (!$parent || !in_array($parent['type'],['sale','purchase'],true) || $parent['entity_id'] != $source['entity_id']) throw new \RuntimeException('Link this return to a posted sale or purchase for the same party.');
            $refunds=$this->row("SELECT COALESCE(SUM(total_amount),0) AS amount, COALESCE(SUM(tax),0) AS tax FROM transactions WHERE parent_id=? AND type='return'",[$parent['id']]);
            if (Money::compare($refunds['amount'],$parent['total_amount'])>0 || Money::compare($refunds['tax'],$parent['tax'])>0) throw new \RuntimeException('Return credit exceeds the original document amount or tax.');
            $vendor = $parent['type']==='purchase';
            if ($vendor !== in_array(strtolower((string)$source['entity_type']),['vendor','supplier'],true)) throw new \RuntimeException('Return party type does not match its source document.');
            if (Money::compare($parent['tax'],'0')>0 && Money::compare($tax,'0')===0) throw new \RuntimeException('A taxed return needs its tax credit recorded before posting.');
            $cost = '0.00';
            foreach ($items as &$item) {
                $original = $this->row('SELECT SUM(quantity) AS quantity,MIN(cost_recorded) AS cost_recorded, SUM(quantity*cost_price)/NULLIF(SUM(quantity),0) AS cost_price FROM transaction_items WHERE transaction_id=? AND item_id=?', [$parent['id'],$item['item_id']]);
                if($catalog){$classification=$this->row('SELECT c.item_type AS catalog_type,c.inventory_account_id,c.income_account_id,c.expense_account_id FROM erp_line_catalog c JOIN transaction_items i ON i.id=c.line_id WHERE i.transaction_id=? AND i.item_id=? ORDER BY i.id LIMIT 1',[$parent['id'],$item['item_id']]);if($classification)$item=array_merge($item,$classification);}
                $returned = $this->row("SELECT COALESCE(SUM(i.quantity),0) AS quantity FROM transaction_items i JOIN transactions t ON t.id=i.transaction_id WHERE t.type='return' AND t.parent_id=? AND i.item_id=?", [$parent['id'],$item['item_id']]);
                if (!$original['quantity'] || bccomp($returned['quantity'],$original['quantity'],4)>0 || bccomp($item['quantity'],'0',4)<=0) throw new \RuntimeException('Returned quantity exceeds the original invoice.');
                if (!$vendor&&$this->itemStock($item)) {
                    if (!(int)$original['cost_recorded']) throw new \RuntimeException('Original sale cost is missing.');
                    $cost = Money::add($cost,Money::multiply($item['quantity'],$original['cost_price']));
                    $this->movement($lines,$this->itemAccount($item,'inventory_account_id','inventory'),Money::multiply($item['quantity'],$original['cost_price']),true);
                    $this->movement($lines,$this->itemAccount($item,'expense_account_id','cogs'),Money::multiply($item['quantity'],$original['cost_price']),false);
                }
            }unset($item);
            $this->movement($lines,$this->systemAccount($vendor?'payables':'sales_returns'),$vendor?$total:$amount,true);
            if($vendor)$this->itemValues($lines,$items,$amount,false);
            else $this->movement($lines,$this->systemAccount('receivables'),$total,false);
            $this->movement($lines,$this->systemAccount($vendor?'input_tax':'output_tax'),$tax,!$vendor);
        } else throw new \RuntimeException('This document type is not supported for accounting posting.');
        require_once __DIR__.'/../Enterprise/CatalogService.php';
        if(\App\Enterprise\CatalogService::available($this->db)){
            $purchase=\App\Enterprise\CatalogService::transactionPurchase($this->db,$id);
            if($purchase!==null)foreach($lines as $line)\App\Enterprise\CatalogService::assertRecordUsage($this->db,'account',(int)$line['account_id'],$purchase);
        }
        return $lines;
    }

    public function previewSource(int $id): array {
        $this->db->begin_transaction();
        try {
            $source=$this->row('SELECT * FROM transactions WHERE id=? FOR UPDATE',[$id]);
            if (!$source) throw new \RuntimeException('Document not found.');
            $this->assertOpen((string)$source['transaction_date']);
            $lines=$this->validateLines($this->sourceLines($source));
            foreach ($lines as &$line) $line['account']=$this->row('SELECT code,name FROM gl_accounts WHERE id=?',[$line['account_id']]);
            unset($line);
            return ['document'=>$source,'lines'=>$lines];
        } finally { $this->db->rollback(); }
    }
    public function postSource(int $id): int {
        return $this->atomic(function () use ($id) {
            $this->query('SELECT id FROM erp_control WHERE id=1 LOCK IN SHARE MODE');
            $source=$this->row('SELECT * FROM transactions WHERE id=? FOR UPDATE',[$id]);
            if (!$source) throw new \RuntimeException('Document not found.');
            $existing=$this->row('SELECT id FROM gl_journals WHERE source_transaction_id=? FOR UPDATE',[$id]);
            if ($existing) { $this->postLocked((int)$existing['id']); return (int)$existing['id']; }
            $lines=$this->sourceLines($source);
            $journal=$this->writeDraft((string)$source['transaction_date'],ucfirst($source['type']).' '.$source['reference_code'],$lines,'source-'.$id.'-'.bin2hex(random_bytes(8)),$id);
            $this->postLocked($journal);
            return $journal;
        });
    }

    public function createAccount(array $input): int {
        return $this->atomic(function () use ($input) {
            $code=trim((string)($input['code']??'')); $name=trim((string)($input['name']??'')); $category=(string)($input['category']??'');
            if (!preg_match('/^[A-Za-z0-9-]{1,40}$/D',$code) || $name==='' || mb_strlen($name)>160
                || !in_array($category,['asset','liability','equity','income','expense'],true)) throw new \InvalidArgumentException('Enter a valid account code, name, and category.');
            if (str_starts_with(strtoupper($code),'BANK-')) throw new \InvalidArgumentException('BANK- codes are reserved for cash account mappings.');
            $this->query('INSERT INTO gl_accounts (code,name,category) VALUES (?,?,?)',[$code,$name,$category]);
            $id=(int)$this->db->insert_id; $this->audit('create','account',$id,['code'=>$code,'name'=>$name,'category'=>$category]); return $id;
        });
    }
    public function createPeriod(string $name,string $start,string $end): int {
        return $this->atomic(function () use ($name,$start,$end) {
            $this->query('SELECT id FROM erp_control WHERE id=1 FOR UPDATE');
            $this->date($start); $this->date($end);
            if (trim($name)==='' || mb_strlen($name)>100 || $start>$end) throw new \InvalidArgumentException('Enter a name and valid period dates.');
            if ($this->row('SELECT id FROM gl_periods WHERE start_date<=? AND end_date>=?',[$end,$start])) throw new \RuntimeException('Accounting periods cannot overlap.');
            if ($this->db->query("SHOW TABLES LIKE 'erp_fiscal_years'")->num_rows && $this->row("SELECT id FROM erp_fiscal_years WHERE status='closed' AND start_date<=? AND end_date>=?",[$end,$start])) throw new \RuntimeException('The fiscal year is closed.');
            $this->query('INSERT INTO gl_periods (name,start_date,end_date) VALUES (?,?,?)',[$name,$start,$end]);
            $id=(int)$this->db->insert_id; $this->audit('create','period',$id,['start'=>$start,'end'=>$end]); return $id;
        });
    }
    public function setPeriodStatus(int $id,bool $close,string $reason): void {
        $this->atomic(function () use ($id,$close,$reason) {
            $this->query('SELECT id FROM erp_control WHERE id=1 FOR UPDATE');
            $period=$this->row('SELECT * FROM gl_periods WHERE id=? FOR UPDATE',[$id]);
            if (!$period || trim($reason)==='') throw new \InvalidArgumentException('Choose a period and enter a reason.');
            if (!$close && $this->db->query("SHOW TABLES LIKE 'erp_fiscal_years'")->num_rows && $this->row("SELECT id FROM erp_fiscal_years WHERE status='closed' AND start_date<=? AND end_date>=?",[$period['end_date'],$period['start_date']])) throw new \RuntimeException('Reopen the fiscal year before reopening its periods.');
            if ($close) {
                if ($this->row("SELECT id FROM gl_journals WHERE status='draft' AND entry_date BETWEEN ? AND ? LIMIT 1",[$period['start_date'],$period['end_date']])) throw new \RuntimeException('Post or move all draft journals before closing.');
                if ($this->row("SELECT t.id FROM transactions t LEFT JOIN gl_journals j ON j.source_transaction_id=t.id AND j.status='posted' WHERE t.transaction_date BETWEEN ? AND ? AND j.id IS NULL LIMIT 1",[$period['start_date'],$period['end_date']])) throw new \RuntimeException('Unposted business documents remain in this period. Review and post them before closing.');
            }
            $this->query('UPDATE gl_periods SET status=?,closed_at=?,closed_by=? WHERE id=?',[$close?'closed':'open',$close?date('Y-m-d H:i:s'):null,$close?$this->actor:null,$id]);
            $this->audit($close?'close':'reopen','period',$id,['reason'=>$reason]);
        });
    }

    public function accounts(): array { return $this->rows('SELECT * FROM gl_accounts ORDER BY code'); }
    public function periods(): array { return $this->rows('SELECT * FROM gl_periods ORDER BY start_date DESC'); }
    public function journals(): array {
        return $this->rows('SELECT j.*,r.id AS reversal_id,u.username AS author,(SELECT SUM(debit) FROM gl_journal_lines WHERE journal_id=j.id) AS amount FROM gl_journals j LEFT JOIN gl_journals r ON r.reversal_of_id=j.id LEFT JOIN users u ON u.id=j.created_by ORDER BY j.id DESC LIMIT 200');
    }
    public function journal(int $id): array {
        $journal=$this->row('SELECT j.*,r.id AS reversal_id FROM gl_journals j LEFT JOIN gl_journals r ON r.reversal_of_id=j.id WHERE j.id=?',[$id]);
        if (!$journal) throw new \RuntimeException('Journal not found.');
        $journal['lines']=$this->rows('SELECT l.*,a.code,a.name FROM gl_journal_lines l JOIN gl_accounts a ON a.id=l.account_id WHERE journal_id=? ORDER BY l.id',[$id]);
        return $journal;
    }
    public function unpostedSources(): array {
        return $this->rows("SELECT t.*,e.display_name AS party FROM transactions t LEFT JOIN gl_journals j ON j.source_transaction_id=t.id LEFT JOIN entity_list e ON e.id=t.entity_id WHERE j.id IS NULL ORDER BY t.transaction_date DESC,t.id DESC LIMIT 200");
    }
    public function trialBalance(string $from,string $to): array {
        $this->date($from); $this->date($to);
        if ($from>$to) throw new \InvalidArgumentException('Start date must precede end date.');
        return $this->rows("SELECT a.id,a.code,a.name,a.category,COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.debit ELSE 0 END),0) AS debit,COALESCE(SUM(CASE WHEN j.id IS NOT NULL THEN l.credit ELSE 0 END),0) AS credit
            FROM gl_accounts a LEFT JOIN gl_journal_lines l ON l.account_id=a.id LEFT JOIN gl_journals j ON j.id=l.journal_id AND j.status='posted' AND j.entry_date BETWEEN ? AND ? GROUP BY a.id ORDER BY a.code",[$from,$to]);
    }
    public function auditEvents(): array { return $this->rows('SELECT a.*,u.username FROM erp_audit_events a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 200'); }

    public function report(string $kind,string $from,string $to): array {
        if (!in_array($kind,['trial_balance','income_statement','balance_sheet'],true)) throw new \InvalidArgumentException('Unknown financial report.');
        $this->date($from); $this->date($to);
        if ($from>$to) throw new \InvalidArgumentException('Start date must precede end date.');
        $accounts=$this->trialBalance($kind==='income_statement'?$from:'1000-01-01',$to);
        $rows=[]; $debit='0.00'; $credit='0.00'; $earnings='0.00';
        foreach ($accounts as $account) {
            $net=Money::subtract($account['debit'],$account['credit']);
            if (in_array($account['category'],['income','expense'],true)) $earnings=Money::subtract($earnings,$net);
            if ($kind==='income_statement' && !in_array($account['category'],['income','expense'],true)) continue;
            if ($kind==='balance_sheet' && !in_array($account['category'],['asset','liability','equity'],true)) continue;
            $dr=Money::compare($net,'0')>0?$net:'0.00'; $cr=Money::compare($net,'0')<0?Money::subtract('0',$net):'0.00';
            $rows[]=['code'=>$account['code'],'name'=>$account['name'],'category'=>$account['category'], 'debit'=>$dr,'credit'=>$cr,
                'amount'=>$account['category']==='asset'||$account['category']==='expense'?$net:Money::subtract('0',$net)];
            $debit=Money::add($debit,$dr); $credit=Money::add($credit,$cr);
        }
        if ($kind==='balance_sheet' && Money::compare($earnings,'0')!==0) {
            $dr=Money::compare($earnings,'0')<0?Money::subtract('0',$earnings):'0.00';
            $cr=Money::compare($earnings,'0')>0?$earnings:'0.00';
            $rows[]=['code'=>'','name'=>'Accumulated unclosed earnings','category'=>'equity','debit'=>$dr,'credit'=>$cr,'amount'=>$earnings];
            $debit=Money::add($debit,$dr); $credit=Money::add($credit,$cr);
        }
        return ['kind'=>$kind,'from'=>$kind==='income_statement'?$from:null,'to'=>$to,'rows'=>$rows,
            'debit'=>$debit,'credit'=>$credit,'balanced'=>Money::compare($debit,$credit)===0,'net_profit'=>$earnings];
    }
}
