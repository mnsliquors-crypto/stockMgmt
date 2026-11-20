<?php
namespace App\Enterprise;
require_once __DIR__.'/ReportService.php';
use App\Accounting\Money;

final class DashboardService extends Service {
    public function data(array $input=[]): array {
        $from=$this->date($input['from']??date('Y-m-01'));$to=$this->date($input['to']??date('Y-m-d'));
        if ($from>$to) throw new \InvalidArgumentException('Invalid dashboard date range.');
        $location=empty($input['location_id'])?null:$this->id($input['location_id']);
        $filter=$location?' AND COALESCE(c.location_id,1)=?':'';$values=[$from,$to];if ($location) $values[]=$location;
        $metrics=$this->row("SELECT COALESCE(SUM(IF(t.type='sale',t.total_amount,0)),0) AS sales,
            COALESCE(SUM(IF(t.type='purchase',t.total_amount,0)),0) AS purchases,
            COALESCE(SUM(IF(t.type='expense',t.total_amount,0)),0) AS expenses,
            COALESCE(SUM(IF(t.type='sale',t.discount,0)),0) AS discount,
            COALESCE(SUM(IF(t.type='sale',t.tax,0)),0) AS output_tax,COALESCE(SUM(IF(t.type='purchase',t.tax,0)),0) AS input_tax
            FROM transactions t LEFT JOIN erp_transaction_context c ON c.transaction_id=t.id WHERE t.transaction_date BETWEEN ? AND ?".$filter,$values);
        $lineFilter=$location?' AND location_id=?':'';
        $profit=$this->row('SELECT COALESCE(SUM(revenue),0) AS revenue,COALESCE(SUM(cogs),0) AS cogs FROM erp_operational_lines WHERE transaction_date BETWEEN ? AND ?'.$lineFilter,$values);
        $metrics['sales']=Money::decimal($profit['revenue']);$metrics['cogs']=Money::decimal($profit['cogs']);
        if(CatalogService::available($this->db))$metrics['expenses']=Money::decimal($this->row('SELECT COALESCE(SUM(amount),0) AS amount FROM erp_operational_expenses WHERE transaction_date BETWEEN ? AND ?'.$lineFilter,$values)['amount']);
        $metrics['gross_profit']=Money::subtract($metrics['sales'],$metrics['cogs']);
        $metrics['net_profit']=Money::subtract($metrics['gross_profit'],$metrics['expenses']);
        $reports=new ReportService($this->db,$this->user);
        $ar=array_values(array_filter($reports->openDocuments('sale',$to),static fn($r)=>Money::compare($r['outstanding'],'0')>0));
        $ap=array_values(array_filter($reports->openDocuments('purchase',$to),static fn($r)=>Money::compare($r['outstanding'],'0')>0));
        $metrics['receivables']='0.00';$metrics['payables']='0.00';
        foreach ($ar as $row) $metrics['receivables']=Money::add($metrics['receivables'],$row['outstanding']);
        foreach ($ap as $row) $metrics['payables']=Money::add($metrics['payables'],$row['outstanding']);
        $metrics['inventory_value']='0.00';$metrics['low_stock']=0;$metrics['stock_units']='0.0000';
        $stock=(new InventoryService($this->db,$this->user))->stock($location,null,$to);$low=[];
        foreach ($stock as $row) {
            if (!(int)$row['status']) continue;
            $metrics['inventory_value']=Money::add($metrics['inventory_value'],Money::multiply($row['quantity'],$row['cost']));
            $metrics['stock_units']=bcadd($metrics['stock_units'],$row['quantity'],4);
            if (bccomp($row['quantity'],$row['reorder_level'],4)<=0) {$metrics['low_stock']++;$low[]=$row;}
        }
        usort($low,static fn($a,$b)=>bccomp($a['quantity'],$b['quantity'],4));
        $accounts=$this->rows("SELECT a.id,a.name,a.type,
            COALESCE(SUM(IF(t.transaction_date<?,IF(l.type=1,l.amount,-l.amount),0)),0) AS opening,
            COALESCE(SUM(IF(t.transaction_date BETWEEN ? AND ? AND l.type=1,l.amount,0)),0) AS amount_in,
            COALESCE(SUM(IF(t.transaction_date BETWEEN ? AND ? AND l.type=2,l.amount,0)),0) AS amount_out,
            COALESCE(SUM(IF(t.transaction_date<=?,IF(l.type=1,l.amount,-l.amount),0)),0) AS closing
            FROM account_list a LEFT JOIN transaction_list l ON l.account_id=a.id AND l.ref_table='transactions' LEFT JOIN transactions t ON t.id=l.ref_id
            WHERE a.status=1 GROUP BY a.id,a.name,a.type ORDER BY a.name",[$from,$from,$to,$from,$to,$to]);
        $metrics['bank_balance']='0.00';foreach ($accounts as $row) $metrics['bank_balance']=Money::add($metrics['bank_balance'],$row['closing']);
        $cash=$this->row('SELECT date,total_amount,expected_amount,difference,reconciliation_status,finalized_at FROM cash_denominations WHERE date=? ORDER BY id DESC LIMIT 1',[$to]);
        $trend=$this->rows("SELECT t.transaction_date AS day,SUM(IF(t.type='sale',t.total_amount,0)) AS sales,SUM(IF(t.type='purchase',t.total_amount,0)) AS purchases,SUM(IF(t.type='expense',t.total_amount,0)) AS expenses FROM transactions t LEFT JOIN erp_transaction_context c ON c.transaction_id=t.id WHERE t.transaction_date BETWEEN ? AND ?".$filter.' GROUP BY t.transaction_date ORDER BY t.transaction_date',$values);
        $top=$reports->report('sales_by_item',['from'=>$from,'to'=>$to,'location_id'=>$location])['rows'];
        $recent=$this->rows("SELECT created_at,action,object_type,object_id,JSON_UNQUOTE(JSON_EXTRACT(details,'$.actor_username')) AS username FROM erp_audit_events ORDER BY id DESC LIMIT 12");
        $tasks=$this->rows("SELECT a.id,a.title,a.kind,a.starts_at,a.priority,u.username AS assignee FROM erp_activities a LEFT JOIN users u ON u.id=a.assigned_to WHERE a.status IN ('open','in_progress') ORDER BY a.starts_at LIMIT 10");
        $categories=$this->rows("SELECT COALESCE(cat.name,'Uncategorized') AS name,SUM(v.revenue) AS revenue FROM erp_operational_lines v JOIN item_list i ON i.id=v.item_id LEFT JOIN category_list cat ON cat.id=i.category_id WHERE v.transaction_date BETWEEN ? AND ?".($location?' AND v.location_id=?':'').' GROUP BY cat.id,cat.name ORDER BY revenue DESC LIMIT 10',$values);
        $hourValues=[$from.' 00:00:00',$to.' 23:59:59'];if ($location) $hourValues[]=$location;
        $hourly=$this->rows('SELECT HOUR(created_at) AS hour,SUM(amount) AS amount,COUNT(*) AS checkouts FROM erp_pos_checkouts WHERE created_at BETWEEN ? AND ?'.($location?' AND location_id=?':'').' GROUP BY HOUR(created_at) ORDER BY hour',$hourValues);
        $payments=$this->rows("SELECT a.name,SUM(l.amount) AS amount FROM transaction_list l JOIN transactions t ON t.id=l.ref_id AND l.ref_table='transactions' JOIN account_list a ON a.id=l.account_id WHERE t.type='payment' AND l.type=1 AND t.transaction_date BETWEEN ? AND ? GROUP BY a.id,a.name ORDER BY amount DESC",[$from,$to]);
        $monthValues=[$to,$to];if($location)$monthValues[]=$location;
        $monthly=$this->rows("SELECT DATE_FORMAT(t.transaction_date,'%Y-%m') AS month,SUM(IF(t.type='sale',t.total_amount,0)) AS sales,SUM(IF(t.type='purchase',t.total_amount,0)) AS purchases,SUM(IF(t.type='expense',t.total_amount,0)) AS expenses FROM transactions t LEFT JOIN erp_transaction_context c ON c.transaction_id=t.id WHERE t.transaction_date BETWEEN DATE_SUB(?,INTERVAL 5 MONTH) AND ?".$filter." GROUP BY DATE_FORMAT(t.transaction_date,'%Y-%m') ORDER BY month",$monthValues);
        if(CatalogService::available($this->db)){
            $monthlyExpenses=array_column($this->rows("SELECT DATE_FORMAT(transaction_date,'%Y-%m') AS month,SUM(amount) AS expenses FROM erp_operational_expenses WHERE transaction_date BETWEEN DATE_SUB(?,INTERVAL 5 MONTH) AND ?".($location?' AND location_id=?':'')." GROUP BY DATE_FORMAT(transaction_date,'%Y-%m')",$monthValues),'expenses','month');
            foreach($monthly as &$month)$month['expenses']=$monthlyExpenses[$month['month']]??'0.00';unset($month);
        }
        $parties=$this->rows("SELECT entity_type AS type,COUNT(*) AS total,SUM(status=1) AS active FROM entity_list WHERE entity_type IN ('Customer','Supplier') GROUP BY entity_type");
        return ['from'=>$from,'to'=>$to,'location_id'=>$location,'metrics'=>$metrics,'accounts'=>$accounts,'cash'=>$cash,'trend'=>$trend,
            'low_stock'=>array_slice($low,0,10),'top_items'=>array_slice($top,0,10),
            'receivables'=>array_slice($ar,0,10),'payables'=>array_slice($ap,0,10),'bills_due'=>array_values(array_filter($ap,static fn($row)=>$row['due_date']<=$to)),
            'activities'=>$tasks,'recent'=>$recent,'categories'=>$categories,'hourly'=>$hourly,'payments'=>$payments,'monthly'=>$monthly,'parties'=>$parties,
            'profit_trend'=>$reports->report('daily_profit',['from'=>$from,'to'=>$to,'location_id'=>$location])['rows'],
            'scope_notes'=>['Stock, revenue, and purchase filters apply to the selected location. Shared cash/bank accounts and party balances are company-wide.','Hourly activity covers new individual POS receipts, not historical consolidated sales.']];
    }
}
