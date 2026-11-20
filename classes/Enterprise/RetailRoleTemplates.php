<?php
namespace App\Enterprise;
require_once __DIR__.'/FeatureRegistry.php';

// Suggested starting points. Access is not enforced until the feature rollout is complete.
final class RetailRoleTemplates {
    public static function all(): array {
        $catalog = ['inventory.catalog'=>'view','inventory.catalog.items'=>'view','inventory.categories'=>'view','inventory.stock_registry'=>'view','inventory.location_stock'=>'view'];
        $pos = ['sales.pos'=>'view,create','sales.pos.item_search'=>'view,search','sales.pos.checkout'=>'create','sales.pos.receipts'=>'view,print',
            'sales.invoices'=>'view,create,print','sales.invoices.lines'=>'view,create','sales.customers'=>'view,create,edit',
            'payments.records'=>'view,create','payments.cash_counts'=>'view,create,edit'];
        $inventory = $catalog + ['inventory.catalog.item_image'=>'view,edit','inventory.catalog.units'=>'view','inventory.catalog.bins'=>'view',
            'inventory.locations'=>'view','inventory.transfers'=>'view,create','inventory.packaging'=>'view,edit','inventory.adjustments'=>'view,create',
            'purchasing.item_receipts'=>'view,create,print','purchasing.orders'=>'view,print','purchasing.orders.lines'=>'view'];
        $purchasing = ['purchasing.orders'=>'view,create,edit,print','purchasing.orders.lines'=>'view,create,edit','purchasing.orders.submit'=>'submit',
            'purchasing.approvals'=>'view','purchasing.replenishment'=>'view,create,export','purchasing.item_receipts'=>'view,create,print',
            'purchasing.vendor_bills'=>'view,create,edit,print','purchasing.vendor_bills.record'=>'record',
            'purchasing.direct_purchases'=>'view,create,edit,print','purchasing.returns'=>'view,create',
            'purchasing.vendors'=>'view,create,edit','inventory.catalog'=>'view','inventory.catalog.items'=>'view','inventory.stock_registry'=>'view'];
        $accountant = ['accounting.workspace'=>'view','accounting.journals'=>'view,create,edit,post','accounting.sources'=>'view,post',
            'accounting.chart_of_accounts'=>'view,create','accounting.periods'=>'view','accounting.financial_reports'=>'view,export','accounting.audit'=>'view',
            'payments.records'=>'view,create,edit,export','payments.cash_bank_accounts'=>'view','payments.transfers'=>'view,create',
            'payments.credit_allocations'=>'view,apply','payments.cash_counts'=>'view','expenses.records'=>'view,create,edit,export',
            'purchasing.vendor_bills'=>'view,print','purchasing.vendor_bills.accounting'=>'view,post','purchasing.vendor_bills.payment'=>'view,create,post',
            'sales.invoices'=>'view,print,export','sales.customers'=>'view','purchasing.vendors'=>'view','reports.center'=>'view'];
        foreach (['trial_balance','income_statement','balance_sheet','general_ledger','journal_register','cash_flow','cash_book','bank_book','payment_register','expense_register','vat_sales','vat_purchase','receivable_aging','payable_aging'] as $report) $accountant['reports.center.'.$report] = 'view,export';
        $manager = array_replace($pos, $inventory, $purchasing, ['dashboard.overview'=>'view,filter,refresh','dashboard.kpi.sales'=>'view',
            'dashboard.kpi.low_stock'=>'view','sales.returns'=>'view,create','sales.promotions'=>'view,create,edit',
            'purchasing.orders.approve'=>'approve','purchasing.orders.reject'=>'reject','purchasing.orders.cancel'=>'cancel',
            'purchasing.orders.close'=>'close','purchasing.returns'=>'view,create','payments.cash_counts'=>'view,create,edit,finalize',
            'expenses.records'=>'view,create,edit','reports.center'=>'view']);
        foreach (['sales_register','sales_summary','sales_by_item','purchase_register','stock_summary','low_stock','stock_movement','pos_summary'] as $report) $manager['reports.center.'.$report] = 'view,export';
        return [
            'Retail Cashier'=>['description'=>'Point of sale, customer service and cash counts.','scopes'=>['sales'=>'write','payments'=>'write','inventory'=>'read'],'grants'=>$pos+$catalog],
            'Retail Inventory Clerk'=>['description'=>'Stock, transfers and receiving.','scopes'=>['inventory'=>'write','procurement'=>'write'],'grants'=>$inventory],
            'Retail Purchasing Clerk'=>['description'=>'Supplier, order, receiving and bill preparation.','scopes'=>['inventory'=>'read','procurement'=>'write','purchases'=>'write'],'grants'=>$purchasing],
            'Retail Accountant'=>['description'=>'Accounting, payments and financial reporting.','scopes'=>['accounting'=>'write','payments'=>'write','expenses'=>'write','purchases'=>'write','sales'=>'read','reports'=>'read'],'grants'=>$accountant],
            'Retail Store Manager'=>['description'=>'Retail operations, purchase approvals and operational reports.','scopes'=>['dashboard'=>'read','inventory'=>'write','sales'=>'write','purchases'=>'write','payments'=>'write','expenses'=>'write','procurement'=>'write','promotions'=>'write','reports'=>'read'],'grants'=>$manager]
        ];
    }
    public static function permissions(array $template): array {
        $registry = FeatureRegistry::all(); $allowed = [];
        foreach ($template['grants'] as $key=>$actions) {
            if (!isset($registry[$key]) || $registry[$key]['protected']) throw new \LogicException('Unknown retail feature: '.$key);
            foreach (explode(',', $actions) as $action) {
                if (!in_array($action, $registry[$key]['actions'], true)) throw new \LogicException('Unknown retail action: '.$key.':'.$action);
                $allowed[$key][$action] = true;
            }
            $parent = $registry[$key]['parent'];
            while ($parent) {
                if (in_array('view', $registry[$parent]['actions'], true)) $allowed[$parent]['view'] = true;
                $parent = $registry[$parent]['parent'];
            }
        }
        return $allowed;
    }
}
