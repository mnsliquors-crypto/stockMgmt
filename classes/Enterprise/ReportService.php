<?php
namespace App\Enterprise;
require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/InventoryService.php';
require_once __DIR__ . '/../Accounting/JournalService.php';
use App\Accounting\Money;
use App\Accounting\JournalService;

final class ReportService extends Service {
    public const CATALOG=[
        'sales_register'=>['Sales','Sales Register'],'sales_summary'=>['Sales','Daily Sales Summary'],
        'sales_by_customer'=>['Sales','Sales by Customer'],'sales_by_item'=>['Sales','Sales by Item'],
        'top_profit_items'=>['Sales','Top Profit Items'],'open_invoices'=>['Customers','Open Invoices'],
        'receivable_aging'=>['Customers','Receivable Aging'],'ar_balance_summary'=>['Customers','Receivable Balance Summary'],
        'customer_statement'=>['Customers','Customer Statement'],'customer_confirmation'=>['Customers','Balance Confirmation'],
        'ar_register'=>['Customers','Receivable Register'],'payments_by_invoice'=>['Customers','Payments by Invoice'],
        'purchase_register'=>['Purchases','Purchase Register'],'purchases_by_vendor'=>['Purchases','Purchases by Supplier'],
        'purchases_by_item'=>['Purchases','Purchases by Item'],'open_bills'=>['Suppliers','Open Bills'],
        'payable_aging'=>['Suppliers','Payable Aging'],'ap_balance_summary'=>['Suppliers','Payable Balance Summary'],
        'vendor_confirmation'=>['Suppliers','Balance Confirmation'],'vendor_statement'=>['Suppliers','Supplier Statement'],
        'ap_register'=>['Suppliers','Payable Register'],'payments_by_bill'=>['Suppliers','Payments by Bill'],
        'stock_summary'=>['Inventory','Stock Summary'],'inventory_valuation'=>['Inventory','Inventory Valuation'],
        'low_stock'=>['Inventory','Low Stock'],'less_stock'=>['Inventory','Stock Below Reorder Level'],
        'urgent_buy'=>['Inventory','Urgent Replenishment'],'stock_movement'=>['Inventory','Stock Movements'],
        'stock_ledger'=>['Inventory','Item Stock Ledger'],'inventory_profitability'=>['Inventory','Inventory Profitability'],
        'inventory_revenue'=>['Inventory','Inventory Revenue'],'slow_moving'=>['Inventory','Slow-Moving Items'],
        'trial_balance'=>['Financial','Trial Balance'],'income_statement'=>['Financial','Income Statement'],
        'balance_sheet'=>['Financial','Balance Sheet'],'general_ledger'=>['Financial','General Ledger'],
        'journal_register'=>['Financial','Journal Register'],'comparative_income'=>['Financial','Comparative Income Statement'],
        'comparative_balance_sheet'=>['Financial','Comparative Balance Sheet'],'equity_statement'=>['Financial','Statement of Equity'],
        'retained_earnings'=>['Financial','Accumulated Earnings'],'cash_flow'=>['Financial','Cash Flow Counterparts'],
        'financial_ratios'=>['Financial','Financial Ratios'],'daily_profit'=>['Financial','Daily Operational Profit'],
        'cash_book'=>['Financial','Cash Book'],'bank_book'=>['Financial','Bank Book'],
        'payment_register'=>['Financial','Payment Register'],'expense_register'=>['Financial','Expense Register'],
        'vat_sales'=>['VAT','Sales VAT Register'],'vat_purchase'=>['VAT','Purchase VAT Register'],
        'abbreviated_tax_invoice'=>['VAT','Abbreviated Tax Invoices'],'promotions'=>['Sales','Promotion Performance'],
        'pos_summary'=>['Sales','POS Checkout Summary'],'break_even_payback'=>['Financial','Break-Even & Simple Payback'],
    ];

    public function openDocuments(string $type,string $asOf,?int $party=null): array {
        $asOf=$this->date($asOf);
        $values=[$asOf,$asOf,$asOf,$type,$asOf];
        $filter='';
        if ($party!==null) { $filter=' AND t.entity_id=?';$values[]=$party; }
        return $this->rows("SELECT t.id,t.reference_code,t.transaction_date,e.display_name AS party,e.id AS party_id,
            DATE_ADD(t.transaction_date,INTERVAL COALESCE(e.credit_days,0) DAY) AS due_date,
            t.total_amount+t.tax AS invoice_total,COALESCE(p.paid,0) AS paid,COALESCE(r.credited,0) AS credited,
            t.total_amount+t.tax-COALESCE(p.paid,0)-COALESCE(r.credited,0)+COALESCE(a.adjustment,0) AS outstanding
            FROM transactions t JOIN entity_list e ON e.id=t.entity_id
            LEFT JOIN (SELECT parent_id,SUM(total_amount) AS paid FROM transactions WHERE type='payment' AND transaction_date<=? GROUP BY parent_id) p ON p.parent_id=t.id
            LEFT JOIN (SELECT parent_id,SUM(total_amount+tax) AS credited FROM transactions WHERE type='return' AND transaction_date<=? GROUP BY parent_id) r ON r.parent_id=t.id
            LEFT JOIN (SELECT invoice_id,SUM(adjustment) AS adjustment FROM erp_credit_adjustment_events WHERE allocation_date<=? GROUP BY invoice_id) a ON a.invoice_id=t.id
            WHERE t.type=? AND t.transaction_date<=?".$filter.' ORDER BY t.transaction_date,t.id',$values);
    }

    public function report(string $kind,array $input=[]): array {
        if (!isset(self::CATALOG[$kind])) throw new \InvalidArgumentException('Unknown report.');
        $from=$this->date($input['from']??date('Y-m-01'));$to=$this->date($input['to']??date('Y-m-d'));
        if ($from>$to) throw new \InvalidArgumentException('Report start cannot follow its end.');
        $party=empty($input['party_id'])?null:$this->id($input['party_id']);
        $item=empty($input['item_id'])?null:$this->id($input['item_id']);
        $location=empty($input['location_id'])?null:$this->id($input['location_id']);
        $rows=[];$basis='Operational documents; legacy costs without snapshots use the current item cost.';
        if (in_array($kind,['open_invoices','open_bills','ar_balance_summary','ap_balance_summary','receivable_aging','payable_aging','customer_confirmation','vendor_confirmation'],true)) {
            $vendor=in_array($kind,['open_bills','ap_balance_summary','payable_aging','vendor_confirmation'],true);
            $documents=$this->openDocuments($vendor?'purchase':'sale',$to,$party);
            if (in_array($kind,['open_invoices','open_bills'],true)) $rows=array_values(array_filter($documents,static fn($row)=>Money::compare($row['outstanding'],'0')>0));
            else {
                $groups=[];$aging=str_contains($kind,'aging');
                foreach ($documents as $doc) {
                    $key=$doc['party_id'];
                    if (!isset($groups[$key])) $groups[$key]=['Party'=>$doc['party'],'Current'=>'0.00','1-30 Days'=>'0.00','31-60 Days'=>'0.00','61-90 Days'=>'0.00','90+ Days'=>'0.00','Balance'=>'0.00'];
                    $amount=$doc['outstanding'];
                    if ($aging&&Money::compare($amount,'0')<=0) continue;
                    $days=(int)(new \DateTimeImmutable($doc['due_date']))->diff(new \DateTimeImmutable($to))->format('%r%a');
                    $bucket=$days<=0?'Current':($days<=30?'1-30 Days':($days<=60?'31-60 Days':($days<=90?'61-90 Days':'90+ Days')));
                    $groups[$key][$bucket]=Money::add($groups[$key][$bucket],$amount);$groups[$key]['Balance']=Money::add($groups[$key]['Balance'],$amount);
                }
                $rows=array_values($groups);
                if (!$aging) $rows=array_map(static fn($row)=>['Party'=>$row['Party'],'Balance'=>$row['Balance']],$rows);
            }
            $basis='As of '.$to.'. Invoice balance includes tax, linked payments, and return tax credits. Aging uses party credit days.';
        } elseif (in_array($kind,['customer_statement','vendor_statement','ar_register','ap_register'],true)) {
            $vendor=in_array($kind,['vendor_statement','ap_register'],true);$type=$vendor?'purchase':'sale';
            if (str_contains($kind,'statement')&&!$party) throw new \InvalidArgumentException('Choose a party for its statement.');
            $values=[$type,$to];$filter='';
            if ($party) { $filter=' AND t.entity_id=?';$values[]=$party; }
            $data=$this->rows("SELECT t.transaction_date AS Date,t.reference_code AS Document,t.type AS Type,e.display_name AS Party,e.id AS Party_ID,
                IF(t.type=?,t.total_amount+t.tax,-(t.total_amount+t.tax)) AS Movement
                FROM transactions t JOIN entity_list e ON e.id=t.entity_id
                WHERE t.transaction_date<=? AND (t.type IN ('payment','return') OR t.type='".$type."')
                AND e.entity_type='".($vendor?'Supplier':'Customer')."'".$filter.' ORDER BY t.transaction_date,t.id',$values);
            $balances=[];
            if (str_contains($kind,'statement')) {
                $opening='0.00';foreach ($data as $row) if ($row['Date']<$from) $opening=Money::add($opening,$row['Movement']);
                $name=$this->row('SELECT display_name FROM entity_list WHERE id=?',[$party])['display_name']??'';
                $rows[]=['Date'=>$from,'Document'=>'Opening balance','Type'=>'opening','Party'=>$name,'Movement'=>'0.00','Balance'=>$opening];
            }
            foreach ($data as $row) {
                $key=$row['Party_ID'];$balances[$key]=Money::add($balances[$key]??'0.00',$row['Movement']);
                if ($row['Date']<$from) continue;
                unset($row['Party_ID']);$row['Balance']=$balances[$key];$rows[]=$row;
            }
            $basis='Operational party movements with a separate running balance per party, including movements before '.$from.'.';
        } elseif (in_array($kind,['stock_summary','inventory_valuation','low_stock','less_stock','urgent_buy'],true)) {
            foreach ((new InventoryService($this->db,$this->user))->stock($location,$item,$to) as $row) {
                if (in_array($kind,['low_stock','less_stock'],true)&&bccomp($row['quantity'],$row['reorder_level'],4)>0) continue;
                if ($kind==='urgent_buy'&&bccomp($row['quantity'],'0',4)>0) continue;
                $rows[]=['Item'=>$row['name'],'Unit'=>$row['unit'],'Quantity'=>$row['quantity'],'Reorder Level'=>$row['reorder_level'],'Unit Cost'=>$row['cost'],'Value'=>Money::multiply($row['quantity'],$row['cost'])];
            }
            $basis='Physical stock as of '.$to.'; valuation uses the existing item cost, not a reconstructed historical FIFO valuation.';
        } elseif (in_array($kind,['stock_movement','stock_ledger'],true)) {
            if ($kind==='stock_ledger'&&!$item) throw new \InvalidArgumentException('Choose an item for its ledger.');
            $values=[$to];$filter='';
            if ($item) {$filter.=' AND v.item_id=?';$values[]=$item;}
            if ($location) {$filter.=' AND v.location_id=?';$values[]=$location;}
            $rows=$this->rows('SELECT v.transaction_date AS Date,t.reference_code AS Document,i.name AS Item,l.name AS Location,v.movement AS Quantity
                FROM erp_operational_lines v JOIN transactions t ON t.id=v.transaction_id JOIN item_list i ON i.id=v.item_id JOIN erp_locations l ON l.id=v.location_id
                WHERE v.transaction_date<=?'.$filter.' ORDER BY v.transaction_date,v.line_id',$values);
            $values=[$to];$filter='';
            if ($item) {$filter.=' AND d.item_id=?';$values[]=$item;}
            if ($location) {$filter.=' AND (h.from_location_id=? OR h.to_location_id=?)';$values[]=$location;$values[]=$location;}
            $transfers=$this->rows('SELECT h.transfer_date AS Date,h.reference AS Document,i.name AS Item,a.name AS Source,b.name AS Destination,h.from_location_id,h.to_location_id,d.quantity AS Quantity
                FROM erp_stock_transfer_lines d JOIN erp_stock_transfers h ON h.id=d.transfer_id JOIN item_list i ON i.id=d.item_id JOIN erp_locations a ON a.id=h.from_location_id JOIN erp_locations b ON b.id=h.to_location_id
                WHERE h.transfer_date<=?'.$filter,$values);
            foreach ($transfers as $transfer) {
                foreach ([[-1,'from_location_id','Source'],[1,'to_location_id','Destination']] as [$sign,$field,$name]) {
                    if ($location&&$location!=(int)$transfer[$field]) continue;
                    $rows[]=['Date'=>$transfer['Date'],'Document'=>$transfer['Document'],'Item'=>$transfer['Item'],'Location'=>$transfer[$name],'Quantity'=>bcmul($transfer['Quantity'],(string)$sign,4)];
                }
            }
            usort($rows,static fn($a,$b)=>[$a['Date'],$a['Document']]<=>[$b['Date'],$b['Document']]);
            $balance='0.0000';$filtered=[];
            foreach ($rows as $row) { $balance=bcadd($balance,$row['Quantity'],4);if ($row['Date']<$from) continue;if ($item) $row['Balance']=$balance;$filtered[]=$row; }
            $rows=$filtered;$basis='Base-unit movements including internal transfers. Opening quantities are included in the running item balance.';
        } elseif (in_array($kind,['sales_by_item','top_profit_items','inventory_profitability','inventory_revenue','purchases_by_item','slow_moving'],true)) {
            $values=[$from,$to];$filter='';
            if ($location) {$filter.=' AND v.location_id=?';$values[]=$location;}
            if ($item) {$filter.=' AND v.item_id=?';$values[]=$item;}
            $rows=$this->rows("SELECT i.name AS Item,i.unit AS Unit,SUM(IF(v.type='sale',v.quantity,0)) AS Sold,
                ROUND(SUM(v.revenue),2) AS Revenue,ROUND(SUM(v.cogs),2) AS COGS,
                ROUND(SUM(v.revenue-v.cogs),2) AS Profit,ROUND(SUM(v.purchase_value),2) AS Purchases
                FROM erp_operational_lines v JOIN item_list i ON i.id=v.item_id WHERE v.transaction_date BETWEEN ? AND ?".$filter.' GROUP BY i.id,i.name,i.unit ORDER BY '.($kind==='slow_moving'?'Sold ASC':($kind==='purchases_by_item'?'Purchases DESC':'Profit DESC')),$values);
            if ($kind==='purchases_by_item') $rows=array_map(static fn($row)=>['Item'=>$row['Item'],'Unit'=>$row['Unit'],'Purchases'=>$row['Purchases']],$rows);
        } elseif (in_array($kind,['sales_by_customer','purchases_by_vendor'],true)) {
            $type=$kind==='sales_by_customer'?'sale':'purchase';
            $values=[$type,$from,$to];$filter='';if ($party) {$filter=' AND e.id=?';$values[]=$party;}
            $rows=$this->rows('SELECT e.display_name AS Party,COUNT(*) AS Documents,SUM(t.total_amount) AS Subtotal,SUM(t.tax) AS Tax,SUM(t.total_amount+t.tax) AS Total FROM transactions t JOIN entity_list e ON e.id=t.entity_id WHERE t.type=? AND t.transaction_date BETWEEN ? AND ?'.$filter.' GROUP BY e.id,e.display_name ORDER BY Total DESC',$values);
        } elseif (in_array($kind,['trial_balance','income_statement','balance_sheet','comparative_income','comparative_balance_sheet','retained_earnings','financial_ratios'],true)) {
            $ledger=new JournalService($this->db,$this->user);$basis='Posted general ledger only. Unposted operational documents and unreconciled opening balances are excluded.';
            $reportKind=match($kind){'comparative_income','financial_ratios','retained_earnings'=>'income_statement','comparative_balance_sheet'=>'balance_sheet',default=>$kind};
            $current=$ledger->report($reportKind,$kind==='retained_earnings'?'1000-01-01':$from,$to);
            if (str_starts_with($kind,'comparative_')) {
                $priorFrom=$this->date($input['compare_from']??(new \DateTimeImmutable($from))->modify('-1 year')->format('Y-m-d'));
                $priorTo=$this->date($input['compare_to']??(new \DateTimeImmutable($to))->modify('-1 year')->format('Y-m-d'));
                $prior=$ledger->report($reportKind,$priorFrom,$priorTo);$merged=[];
                foreach (['Current'=>$current,'Previous'=>$prior] as $column=>$report) foreach ($report['rows'] as $row) {
                    $key=$row['code'].'|'.$row['name'];
                    if (!isset($merged[$key])) $merged[$key]=['Account'=>$row['code'].' '.$row['name'],'Category'=>$row['category'],'Current'=>'0.00','Previous'=>'0.00'];
                    $merged[$key][$column]=in_array($row['category'],['income','liability','equity'],true)?Money::subtract($row['credit'],$row['debit']):Money::subtract($row['debit'],$row['credit']);
                }
                foreach ($merged as $row) {$row['Change']=Money::subtract($row['Current'],$row['Previous']);$rows[]=$row;}
                $basis.=' Comparison '.$priorFrom.' to '.$priorTo.'.';
            } elseif ($kind==='financial_ratios') {
                $balance=$ledger->report('balance_sheet',$from,$to);$assets='0';$debt='0';$equity='0';$sales='0';$cogs='0';
                foreach ($balance['rows'] as $row) {
                    if ($row['category']==='asset') $assets=Money::add($assets,Money::subtract($row['debit'],$row['credit']));
                    if ($row['category']==='liability') $debt=Money::add($debt,Money::subtract($row['credit'],$row['debit']));
                    if ($row['category']==='equity') $equity=Money::add($equity,Money::subtract($row['credit'],$row['debit']));
                }
                foreach ($current['rows'] as $row) {if ($row['category']==='income') $sales=Money::add($sales,Money::subtract($row['credit'],$row['debit']));if ($row['code']==='5000') $cogs=Money::subtract($row['debit'],$row['credit']);}
                $ratio=static fn($a,$b)=>bccomp($b,'0',2)===0?'N/A':bcdiv($a,$b,4);
                $rows=[['Metric'=>'Debt / Assets','Value'=>$ratio($debt,$assets)],['Metric'=>'Equity / Assets','Value'=>$ratio($equity,$assets)],['Metric'=>'Net Profit Margin','Value'=>$ratio($current['net_profit'],$sales)],['Metric'=>'Gross Margin','Value'=>$ratio(Money::subtract($sales,$cogs),$sales)]];
            } elseif ($kind==='retained_earnings') $rows=[['As of'=>$to,'Accumulated Profit / Loss'=>$current['net_profit']]];
            else $rows=array_map(static fn($row)=>['Code'=>$row['code'],'Account'=>$row['name'],'Category'=>$row['category'],'Debit'=>$row['debit'],'Credit'=>$row['credit']],$current['rows']);
        } elseif (in_array($kind,['general_ledger','journal_register','cash_flow','equity_statement'],true)) {
            $basis='Posted general ledger only.';
            if ($kind==='cash_flow') {
                $rows=$this->rows("SELECT a.code AS Code,a.name AS Counterpart,a.category AS Category,SUM(l.credit-l.debit) AS Cash_Change FROM gl_journal_lines l JOIN gl_journals j ON j.id=l.journal_id JOIN gl_accounts a ON a.id=l.account_id
                    WHERE j.status='posted' AND j.entry_date BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM gl_bank_mapping b WHERE b.gl_account_id=l.account_id)
                    AND EXISTS (SELECT 1 FROM gl_journal_lines cl JOIN gl_bank_mapping cb ON cb.gl_account_id=cl.account_id WHERE cl.journal_id=j.id)
                    GROUP BY a.id,a.code,a.name,a.category ORDER BY a.code",[$from,$to]);
                $basis.=' Cash/bank counterparts, not an automatically classified statutory cash flow statement. Internal bank transfers net to zero.';
            } elseif ($kind==='equity_statement') $rows=$this->rows("SELECT a.code AS Code,a.name AS Account,SUM(IF(j.entry_date<?,l.credit-l.debit,0)) AS Opening,SUM(IF(j.entry_date>=?,l.credit-l.debit,0)) AS Movement,SUM(l.credit-l.debit) AS Closing
                FROM gl_journal_lines l JOIN gl_journals j ON j.id=l.journal_id JOIN gl_accounts a ON a.id=l.account_id WHERE j.status='posted' AND a.category='equity' AND j.entry_date<=? GROUP BY a.id,a.code,a.name",[$from,$from,$to]);
            else {
                $values=[$from,$to];$filter='';if (!empty($input['gl_account_id'])) {$filter=' AND a.id=?';$values[]=$this->id($input['gl_account_id']);}
                $rows=$this->rows("SELECT j.entry_date AS Date,j.reference AS Journal,a.code AS Code,a.name AS Account,j.memo AS Memo,l.debit AS Debit,l.credit AS Credit
                    FROM gl_journal_lines l JOIN gl_journals j ON j.id=l.journal_id JOIN gl_accounts a ON a.id=l.account_id WHERE j.status='posted' AND j.entry_date BETWEEN ? AND ?".$filter.' ORDER BY j.entry_date,j.id,l.id',$values);
            }
        } elseif ($kind==='pos_summary') {
            $values=[$from,$to];$filter='';if($location){$filter=' AND p.location_id=?';$values[]=$location;}
            $rows=$this->rows("SELECT t.transaction_date AS Date,l.name AS Location,u.username AS Cashier,COUNT(*) AS Checkouts,SUM(p.amount) AS Revenue,ROUND(AVG(p.amount),2) AS Average_Checkout FROM erp_pos_checkouts p JOIN transactions t ON t.id=p.sale_id JOIN erp_locations l ON l.id=p.location_id LEFT JOIN users u ON u.id=p.cashier_id WHERE t.transaction_date BETWEEN ? AND ?".$filter.' GROUP BY t.transaction_date,l.id,l.name,u.id,u.username ORDER BY t.transaction_date,l.name,u.username',$values);
            $basis='Individual counter receipts captured after the upgrade, grouped by business date, location, and cashier. Historical consolidated POS sales are not reconstructed.';
        } elseif ($kind==='break_even_payback') {
            $ledger=new JournalService($this->db,$this->user);$pnl=$ledger->report('income_statement',$from,$to);$sales='0.00';$cogs='0.00';$expenses='0.00';
            $cogsId=(int)($this->row("SELECT id FROM gl_accounts WHERE system_key='cogs'")['id']??0);
            $cogsCode=$cogsId?($this->row('SELECT code FROM gl_accounts WHERE id=?',[$cogsId])['code']??''):'';
            foreach($pnl['rows'] as $row){if($row['category']==='income')$sales=Money::add($sales,Money::subtract($row['credit'],$row['debit']));elseif($row['code']===$cogsCode)$cogs=Money::subtract($row['debit'],$row['credit']);elseif($row['category']==='expense')$expenses=Money::add($expenses,Money::subtract($row['debit'],$row['credit']));}
            $fixed=isset($input['fixed_costs'])&&$input['fixed_costs']!==''?$this->decimal($input['fixed_costs'],2):$expenses;
            $investment=$this->decimal(($input['investment']??'')===''?'0':$input['investment'],2);$cashFlow=$this->decimal(($input['period_cash_flow']??'')===''?'0':$input['period_cash_flow'],2);
            if(Money::compare($fixed,'0')<0||Money::compare($investment,'0')<0)throw new \InvalidArgumentException('Fixed costs and investment cannot be negative.');
            $contribution=Money::subtract($sales,$cogs);$margin=Money::compare($sales,'0')>0?bcdiv($contribution,$sales,8):'0';
            $breakEven=bccomp($margin,'0',8)>0?Money::decimal(bcdiv($fixed,$margin,8)):'N/A';
            $rows=[['Metric'=>'Posted Net Sales','Value'=>$sales],['Metric'=>'Posted Cost of Goods Sold','Value'=>$cogs],['Metric'=>'Fixed Costs Assumption','Value'=>$fixed],['Metric'=>'Contribution Margin','Value'=>Money::multiply($margin,'100').'%'],['Metric'=>'Break-Even Sales','Value'=>$breakEven],['Metric'=>'Margin of Safety','Value'=>$breakEven==='N/A'?'N/A':Money::subtract($sales,$breakEven)],['Metric'=>'Investment Assumption','Value'=>$investment],['Metric'=>'Net Cash Flow per Period Assumption','Value'=>$cashFlow],['Metric'=>'Simple Payback in Cash Flow Periods','Value'=>Money::compare($investment,'0')>0&&Money::compare($cashFlow,'0')>0?bcdiv($investment,$cashFlow,2):'N/A']];
            $basis='Company-wide posted GL revenue and COGS. Other posted expenses default to fixed costs; override this assumption if needed. Simple payback uses the supplied investment and constant net cash flow, without discounting; it does not treat profit as cash flow. Non-positive contribution margins have no calculable break-even.';
        } elseif (in_array($kind,['cash_book','bank_book'],true)) {
            $values=[$from,$to];$filter=$kind==='cash_book'?' AND a.type=1':' AND a.type<>1';if (!empty($input['account_id'])) {$filter.=' AND a.id=?';$values[]=$this->id($input['account_id']);}
            $rows=$this->rows("SELECT t.transaction_date AS Date,a.name AS Account,t.reference_code AS Document,IF(l.type=1,l.amount,0) AS Debit,IF(l.type=2,l.amount,0) AS Credit FROM transaction_list l JOIN transactions t ON t.id=l.ref_id AND l.ref_table='transactions' JOIN account_list a ON a.id=l.account_id WHERE t.transaction_date BETWEEN ? AND ?".$filter.' ORDER BY t.transaction_date,t.id,l.id',$values);
            $basis='Operational cash/bank movements. Select a cash or bank account to restrict the book.';
        } elseif ($kind==='daily_profit') {
            $profitValues=[$from,$to];$profitFilter='';
            if($location){$profitFilter=' WHERE location_id=?';$profitValues[]=$location;}
            $expenseFilter='';if($location){$expenseFilter=' AND COALESCE(c.location_id,1)=?';$profitValues[]=$location;}
            $rows=$this->rows("SELECT d.day AS Date,COALESCE(l.revenue,0) AS Revenue,COALESCE(l.cogs,0) AS COGS,COALESCE(e.expenses,0) AS Expenses,COALESCE(l.revenue,0)-COALESCE(l.cogs,0)-COALESCE(e.expenses,0) AS Net_Profit
                FROM (SELECT DISTINCT transaction_date AS day FROM transactions WHERE transaction_date BETWEEN ? AND ?) d
                LEFT JOIN (SELECT transaction_date,SUM(revenue) revenue,SUM(cogs) cogs FROM erp_operational_lines".$profitFilter." GROUP BY transaction_date) l ON l.transaction_date=d.day
                LEFT JOIN (SELECT t.transaction_date,SUM(t.total_amount) expenses FROM transactions t LEFT JOIN erp_transaction_context c ON c.transaction_id=t.id WHERE t.type='expense'".$expenseFilter." GROUP BY t.transaction_date) e ON e.transaction_date=d.day ORDER BY d.day",$profitValues);
        } elseif ($kind==='promotions') {
            $rows=$this->rows("SELECT p.code AS Code,p.name AS Promotion,i.name AS Item,SUM(ti.quantity) AS Base_Units,SUM(d.promotion_saving) AS Savings,SUM(ti.total_price) AS Revenue FROM erp_line_details d JOIN transaction_items ti ON ti.id=d.line_id JOIN transactions t ON t.id=ti.transaction_id JOIN erp_promotions p ON p.id=d.promotion_id JOIN item_list i ON i.id=ti.item_id WHERE t.type='sale' AND t.transaction_date BETWEEN ? AND ? GROUP BY p.id,p.code,p.name,i.id,i.name ORDER BY Revenue DESC",[$from,$to]);
            $basis='Promotion snapshots captured at checkout; later promotion changes do not rewrite past sales.';
        } elseif (in_array($kind,['payments_by_invoice','payments_by_bill'],true)) {
            $rows=$this->rows("SELECT p.transaction_date AS Date,p.reference_code AS Payment,t.reference_code AS Invoice,e.display_name AS Party,p.total_amount AS Amount FROM transactions p JOIN transactions t ON t.id=p.parent_id JOIN entity_list e ON e.id=t.entity_id WHERE p.type='payment' AND t.type=? AND p.transaction_date BETWEEN ? AND ? ORDER BY p.transaction_date,p.id",[$kind==='payments_by_invoice'?'sale':'purchase',$from,$to]);
        } else {
            $type=match($kind){'purchase_register','vat_purchase'=>'purchase','expense_register'=>'expense','payment_register'=>'payment',default=>'sale'};
            $values=[$type,$from,$to];$filter='';if ($party) {$filter=' AND t.entity_id=?';$values[]=$party;}
            if ($location) {$filter.=' AND COALESCE(c.location_id,1)=?';$values[]=$location;}
            if ($kind==='sales_summary') $rows=$this->rows('SELECT t.transaction_date AS Date,COUNT(*) AS Documents,SUM(t.total_amount) AS Subtotal,SUM(t.discount) AS Discount,SUM(t.tax) AS Tax,SUM(t.total_amount+t.tax) AS Total FROM transactions t LEFT JOIN erp_transaction_context c ON c.transaction_id=t.id WHERE t.type=? AND t.transaction_date BETWEEN ? AND ?'.$filter.' GROUP BY t.transaction_date ORDER BY t.transaction_date',$values);
            else $rows=$this->rows('SELECT t.transaction_date AS Date,t.reference_code AS Document,COALESCE(e.display_name,\'Walk-in\') AS Party,t.total_amount AS Subtotal,t.discount AS Discount,t.tax AS Tax,t.total_amount+t.tax AS Total FROM transactions t LEFT JOIN entity_list e ON e.id=t.entity_id LEFT JOIN erp_transaction_context c ON c.transaction_id=t.id WHERE t.type=? AND t.transaction_date BETWEEN ? AND ?'.$filter.' ORDER BY t.transaction_date,t.id',$values);
        }
        $columns=$rows?array_keys($rows[0]):match(true) {
            in_array($kind,['open_invoices','open_bills'],true)=>['id','reference_code','transaction_date','party','party_id','due_date','invoice_total','paid','credited','outstanding'],
            in_array($kind,['receivable_aging','payable_aging'],true)=>['Party','Current','1-30 Days','31-60 Days','61-90 Days','90+ Days','Balance'],
            in_array($kind,['ar_balance_summary','ap_balance_summary','customer_confirmation','vendor_confirmation'],true)=>['Party','Balance'],
            in_array($kind,['customer_statement','vendor_statement','ar_register','ap_register'],true)=>['Date','Document','Type','Party','Movement','Balance'],
            in_array($kind,['stock_summary','inventory_valuation','low_stock','less_stock','urgent_buy'],true)=>['Item','Unit','Quantity','Reorder Level','Unit Cost','Value'],
            $kind==='stock_ledger'=>['Date','Document','Item','Location','Quantity','Balance'],
            $kind==='stock_movement'=>['Date','Document','Item','Location','Quantity'],
            $kind==='purchases_by_item'=>['Item','Unit','Purchases'],
            in_array($kind,['sales_by_item','top_profit_items','inventory_profitability','inventory_revenue','slow_moving'],true)=>['Item','Unit','Sold','Revenue','COGS','Profit','Purchases'],
            in_array($kind,['sales_by_customer','purchases_by_vendor'],true)=>['Party','Documents','Subtotal','Tax','Total'],
            str_starts_with($kind,'comparative_')=>['Account','Category','Current','Previous','Change'],
            in_array($kind,['general_ledger','journal_register'],true)=>['Date','Journal','Code','Account','Memo','Debit','Credit'],
            $kind==='cash_flow'=>['Code','Counterpart','Category','Cash_Change'],
            $kind==='equity_statement'=>['Code','Account','Opening','Movement','Closing'],
            in_array($kind,['cash_book','bank_book'],true)=>['Date','Account','Document','Debit','Credit'],
            $kind==='daily_profit'=>['Date','Revenue','COGS','Expenses','Net_Profit'],
            $kind==='promotions'=>['Code','Promotion','Item','Base_Units','Savings','Revenue'],
            in_array($kind,['payments_by_invoice','payments_by_bill'],true)=>['Date','Payment','Invoice','Party','Amount'],
            $kind==='pos_summary'=>['Date','Location','Cashier','Checkouts','Revenue','Average_Checkout'],
            $kind==='sales_summary'=>['Date','Documents','Subtotal','Discount','Tax','Total'],
            default=>['Date','Document','Party','Subtotal','Discount','Tax','Total'],
        };
        return ['kind'=>$kind,'title'=>self::CATALOG[$kind][1],'from'=>$from,'to'=>$to,'basis'=>$basis,'rows'=>$rows,'columns'=>$columns];
    }
}
