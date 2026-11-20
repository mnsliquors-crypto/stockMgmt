# Feature Registry Review

> Phase 1 review draft. These entries describe existing repository features, not implemented multi-company permissions. Feature controls remain a test-only preview and do not enforce access.

The source of truth for the proposed keys, actions and parent relationships is `classes/Enterprise/FeatureRegistry.php`. This inventory includes 219 entries. A parent switch is intended to cover its descendants, but that behavior still requires backend implementation and testing. Sign-in, sign-out and the user's own profile are marked protected. The feature-control recovery entry is protected and administrator-only.

The `Scope` column names the existing coarse module permission; it is not a company or role. `Parent` is the registry relationship, not proof that the direct URL is guarded. Action names include existing operations such as `post`, `approve` and `print`, in addition to the requested view/create/edit/delete/export vocabulary. Dashboard `bar` entries describe existing chart series, not individual data-driven values.

## Source Areas

| Area | Representative existing routes and code |
|---|---|
| Application | `admin/login.php`, `classes/Login.php`, `admin/modules/system/users/profile.php`, `admin/inc/topBarNav.php` |
| Dashboard | `admin/pages/home.php`, `admin/pages/legacy_dashboard.php`, `admin/pages/dashboard_details.php`, `admin/modules/enterprise/dashboard/` |
| Sales and customers | `admin/pos/`, `admin/modules/transactions/sales/`, `admin/modules/transactions/returns/`, `admin/modules/master/customers/` |
| Purchasing and suppliers | `admin/modules/transactions/procurement/`, `item_receipts/`, `vendor_bills/`, `purchases/`, `admin/modules/master/vendors/` |
| Inventory | `admin/modules/enterprise/catalog/`, `inventory/`, `admin/modules/master/items/`, `stocks/`, `categories/` |
| Payments, expenses and accounting | `admin/modules/transactions/payments/`, `expenses/`, `admin/modules/finance/accounting/`, `cash_counts/` |
| Administration | `admin/modules/system/`, `admin/modules/enterprise/roles/`, `feature_controls/`, `health/`, `fiscal_years/` |
| Reports | `admin/modules/enterprise/reports/`, `classes/Enterprise/ReportService.php`, `admin/reports/` |

The feature-control screen and retail-role creation are existing **test-only preview** controls, covered by `admin.features`; they are not active security controls. Proposed company administration, company types, invites and impersonation are not listed because they do not yet exist.

## Inventory

### Application

| Key | Label | Type | Scope | Parent | Actions | Protected |
|---|---|---|---|---|---|---|
| `app.login` | Sign in | page | app | - | view, sign_in | Yes |
| `app.logout` | Sign out | action | app | - | sign_out | Yes |
| `app.profile` | My Profile | page | app | - | view, edit | Yes |
| `app.global_search` | Global Search | component | app | - | search, view | No |
| `app.quick_create` | Global Create Menu | component | app | - | view | No |

### Dashboard

| Key | Label | Type | Scope | Parent | Actions | Protected |
|---|---|---|---|---|---|---|
| `dashboard.overview` | Executive Dashboard | page | dashboard | - | view, filter, refresh | No |
| `dashboard.preferences` | Dashboard Preferences | component | dashboard | `dashboard.overview` | view, edit | No |
| `dashboard.shortcuts` | Dashboard Shortcuts | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.kpi.sales` | Net Sales | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.kpi.purchases` | Purchases | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.kpi.expenses` | Expenses | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.kpi.gross_profit` | Gross Profit | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.kpi.net_profit` | Net Profit | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.kpi.discount` | Invoice Discounts | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.kpi.receivables` | Receivables | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.kpi.payables` | Payables | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.kpi.inventory_value` | Inventory Value | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.kpi.bank_balance` | Cash & Bank | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.kpi.cogs` | Cost of Sales | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.kpi.low_stock` | Low Stock Items | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.accounts` | Cash & Bank Accounts | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.cash` | Cash Reconciliation | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.trend` | Sales & Purchases | chart | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.activities` | Tasks & Reminders | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.inventory` | Low Stock | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.items` | Top Items by Profit | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.parties` | Outstanding Customers & Suppliers | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.tax` | VAT Position | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.monthly` | Monthly Comparison | chart | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.payments` | Customer Payment Mix | chart | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.categories` | Revenue by Category | chart | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.hourly` | POS by Hour | chart | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.gross_profit` | Daily Gross Profit | chart | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.expenses` | Expenses | chart | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.customers` | Customers & Suppliers | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.alerts` | Operational Alerts | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.recent_activity` | Recent Activity | component | dashboard | `dashboard.overview` | view | No |
| `dashboard.widget.trend.sales` | Sales Trend | component | dashboard | `dashboard.widget.trend` | view | No |
| `dashboard.widget.trend.purchases` | Purchase Trend | component | dashboard | `dashboard.widget.trend` | view | No |
| `dashboard.widget.monthly.sales` | Monthly Sales | bar | dashboard | `dashboard.widget.monthly` | view | No |
| `dashboard.widget.monthly.purchases` | Monthly Purchases | bar | dashboard | `dashboard.widget.monthly` | view | No |
| `dashboard.widget.monthly.expenses` | Monthly Expenses | bar | dashboard | `dashboard.widget.monthly` | view | No |
| `dashboard.widget.payments.customer_payments` | Customer Payments | component | dashboard | `dashboard.widget.payments` | view | No |
| `dashboard.widget.categories.revenue` | Category Revenue | bar | dashboard | `dashboard.widget.categories` | view | No |
| `dashboard.widget.hourly.pos_sales` | Hourly POS Sales | bar | dashboard | `dashboard.widget.hourly` | view | No |
| `dashboard.widget.gross_profit.profit` | Daily Gross Profit | component | dashboard | `dashboard.widget.gross_profit` | view | No |
| `dashboard.widget.expenses.expenses` | Daily Expenses | bar | dashboard | `dashboard.widget.expenses` | view | No |
| `dashboard.legacy` | Legacy Dashboard | page | dashboard | `dashboard.overview` | view | No |
| `dashboard.legacy.details` | Dashboard Details | page | dashboard | `dashboard.legacy` | view, filter | No |

### Sales and Customers

| Key | Label | Type | Scope | Parent | Actions | Protected |
|---|---|---|---|---|---|---|
| `sales.pos` | POS Entry | page | sales | - | view, create | No |
| `sales.pos.item_search` | POS Item Search | component | sales | `sales.pos` | search, view | No |
| `sales.pos.checkout` | POS Checkout | action | sales | `sales.pos` | create | No |
| `sales.pos.receipts` | Individual POS Receipts | page | sales | `sales.pos` | view, print | No |
| `sales.invoices` | Sales & Invoices | page | sales | - | view, create, edit, delete, print, export | No |
| `sales.invoices.lines` | Invoice Item Lines | component | sales | `sales.invoices` | view, create, edit | No |
| `sales.returns` | Customer Returns | page | sales | - | view, create, delete, export | No |
| `sales.customers` | Customers | page | sales | - | view, create, edit, delete, export | No |
| `sales.customers.open_sales` | Customer Unpaid Sales | component | sales | `sales.customers` | view | No |
| `sales.customers.usage` | Customer Record Usage | component | sales | `sales.customers` | view, edit | No |
| `sales.promotions` | Promotions | page | promotions | - | view, create, edit | No |
| `sales.promotions.eligibility` | Promotion Items & Locations | component | promotions | `sales.promotions` | view, edit | No |
| `sales.promotions.performance` | Promotion Performance | component | promotions | `sales.promotions` | view, export, print | No |

### Purchasing and Suppliers

| Key | Label | Type | Scope | Parent | Actions | Protected |
|---|---|---|---|---|---|---|
| `purchasing.orders` | Purchase Orders | page | procurement | - | view, create, edit, print | No |
| `purchasing.orders.lines` | Purchase Order Lines | component | procurement | `purchasing.orders` | view, create, edit | No |
| `purchasing.orders.submit` | Submit Order | action | procurement | `purchasing.orders` | submit | No |
| `purchasing.orders.approve` | Approve Order | action | procurement | `purchasing.orders` | approve | No |
| `purchasing.orders.reject` | Reject Order | action | procurement | `purchasing.orders` | reject | No |
| `purchasing.orders.cancel` | Cancel Order | action | procurement | `purchasing.orders` | cancel | No |
| `purchasing.orders.close` | Close Remaining Quantity | action | procurement | `purchasing.orders` | close | No |
| `purchasing.orders.reassign` | Reassign Approver | action | procurement | `purchasing.orders` | reassign | No |
| `purchasing.approvals` | Approvals | tab | procurement | - | view | No |
| `purchasing.receipts.legacy` | Combined Purchase Receipts | tab | procurement | - | view | No |
| `purchasing.replenishment` | Replenishment | tab | procurement | - | view, create, export | No |
| `purchasing.policy` | Approval Policy | tab | procurement | - | view, edit | No |
| `purchasing.item_receipts` | Item Receipts | page | procurement | - | view, create, print | No |
| `purchasing.item_receipts.reverse` | Reverse Item Receipt | action | procurement | `purchasing.item_receipts` | reverse | No |
| `purchasing.item_receipts.accounting` | Receipt Accounting | component | procurement | `purchasing.item_receipts` | view, post | No |
| `purchasing.vendor_bills` | Supplier Bills | page | purchases | - | view, create, edit, print | No |
| `purchasing.vendor_bills.record` | Record Supplier Bill | action | purchases | `purchasing.vendor_bills` | record | No |
| `purchasing.vendor_bills.cancel` | Cancel Draft Bill | action | purchases | `purchasing.vendor_bills` | cancel | No |
| `purchasing.vendor_bills.accounting` | Bill Accounting | component | purchases | `purchasing.vendor_bills` | view, post | No |
| `purchasing.vendor_bills.payment` | Supplier Bill Payments | component | payments | `purchasing.vendor_bills` | view, create, post | No |
| `purchasing.direct_purchases` | Direct Purchases | page | purchases | - | view, create, edit, delete, print, export | No |
| `purchasing.returns` | Supplier Returns | page | purchases | - | view, create, delete, export | No |
| `purchasing.vendors` | Vendors | page | purchases | - | view, create, edit, delete, export | No |
| `purchasing.vendors.usage` | Vendor Record Usage | component | purchases | `purchasing.vendors` | view, edit | No |

### Inventory and Masters

| Key | Label | Type | Scope | Parent | Actions | Protected |
|---|---|---|---|---|---|---|
| `inventory.catalog` | Item & Business Masters | page | inventory | - | view | No |
| `inventory.catalog.items` | Items | tab | inventory | `inventory.catalog` | view, create, edit | No |
| `inventory.catalog.item_image` | Basic Item Details & Image | component | inventory | `inventory.catalog` | view, edit | No |
| `inventory.catalog.matrix` | Matrix Variants | component | inventory | `inventory.catalog` | view, create, edit | No |
| `inventory.catalog.matrix.generate` | Generate Matrix Variants | action | inventory | `inventory.catalog.matrix` | generate | No |
| `inventory.catalog.features` | Features | tab | inventory | `inventory.catalog` | view | No |
| `inventory.catalog.features.matrix` | Matrix Items Switch | action | inventory | `inventory.catalog.features` | enable, disable | No |
| `inventory.catalog.units` | Units | tab | inventory | `inventory.catalog` | view, create, edit | No |
| `inventory.catalog.bins` | Bins | tab | inventory | `inventory.catalog` | view, create, edit | No |
| `inventory.catalog.classes` | Classes | tab | inventory | `inventory.catalog` | view, create, edit | No |
| `inventory.catalog.departments` | Departments | tab | inventory | `inventory.catalog` | view, create, edit | No |
| `inventory.catalog.record_usage` | Record Usage | tab | any | `inventory.catalog` | view, edit | No |
| `inventory.categories` | Categories | page | inventory | - | view, create, edit, delete | No |
| `inventory.stock_registry` | Stock Registry | page | inventory | - | view, export | No |
| `inventory.location_stock` | Location Stock | tab | inventory | - | view, filter, export | No |
| `inventory.locations` | Locations | tab | inventory | - | view, create, edit | No |
| `inventory.transfers` | Stock Transfers | tab | inventory | - | view, create, reverse | No |
| `inventory.packaging` | Packaging & Barcodes | tab | inventory | - | view, edit | No |
| `inventory.adjustments` | Stock Adjustments | page | inventory | - | view, create, edit, delete | No |

### Payments

| Key | Label | Type | Scope | Parent | Actions | Protected |
|---|---|---|---|---|---|---|
| `payments.records` | Payments | page | payments | - | view, create, edit, delete, export | No |
| `payments.credit_allocations` | Customer & Supplier Credits | page | payments | - | view, apply, reverse | No |
| `payments.cash_bank_accounts` | Cash & Bank Accounts | page | payments | - | view, create, edit, delete | No |
| `payments.transfers` | Fund Transfers | page | payments | - | view, create, edit, delete | No |
| `payments.cash_counts` | Cash Counts & Reconciliation | page | payments | - | view, create, edit, delete, finalize | No |

### Expenses

| Key | Label | Type | Scope | Parent | Actions | Protected |
|---|---|---|---|---|---|---|
| `expenses.records` | Business Expenses | page | expenses | - | view, create, edit, delete, export | No |
| `expenses.pay` | Pay Expense | action | expenses | - | pay | No |

### Accounting

| Key | Label | Type | Scope | Parent | Actions | Protected |
|---|---|---|---|---|---|---|
| `accounting.workspace` | Accounting Workspace | page | accounting | - | view | No |
| `accounting.journals` | Journal Entries | tab | accounting | - | view, create, edit, delete, post, reverse | No |
| `accounting.sources` | Source Documents | tab | accounting | - | view, post | No |
| `accounting.chart_of_accounts` | Chart of Accounts | tab | accounting | - | view, create | No |
| `accounting.periods` | Accounting Periods | tab | accounting | - | view, create, close, reopen | No |
| `accounting.financial_reports` | Financial Reports | tab | accounting | - | view, export | No |
| `accounting.audit` | Accounting Audit Trail | tab | accounting | - | view | No |
| `accounting.opening_balances` | Opening Balances | page | accounting | - | view, create, edit, delete | No |
| `accounting.fiscal_years` | Fiscal Years | page | accounting | - | view, create, close, reopen | No |

### Activities

| Key | Label | Type | Scope | Parent | Actions | Protected |
|---|---|---|---|---|---|---|
| `activities.list` | Activities | page | activities | - | view, create, edit | No |
| `activities.calendar` | Activity Calendar | tab | activities | - | view | No |

### Assistant

| Key | Label | Type | Scope | Parent | Actions | Protected |
|---|---|---|---|---|---|---|
| `assistant.drafts` | Draft Assistant | page | assistant | - | view, interpret | No |

### Administration

| Key | Label | Type | Scope | Parent | Actions | Protected |
|---|---|---|---|---|---|---|
| `admin.users` | User Management | page | admin | - | view, create, edit, delete, export | No |
| `admin.roles` | Access Roles | page | admin | - | view, create, edit | No |
| `admin.roles.assign` | Staff Role Assignment | action | admin | `admin.roles` | assign | No |
| `admin.settings` | Company Information | page | admin | - | view, edit | No |
| `admin.reference_codes` | Reference Codes | page | admin | - | view, edit | No |
| `admin.health` | System Integrity | page | admin | - | view | No |
| `admin.audit_logs` | System Audit Logs | page | admin | - | view | No |
| `admin.import` | Bulk Import | page | admin | - | view | No |
| `admin.import.items` | Import Items | component | admin | `admin.import` | import, download | No |
| `admin.import.suppliers` | Import Suppliers | component | admin | `admin.import` | import, download | No |
| `admin.import.customers` | Import Customers | component | admin | `admin.import` | import, download | No |
| `admin.import.categories` | Import Categories | component | admin | `admin.import` | import, download | No |
| `admin.import.accounts` | Import Accounts | component | admin | `admin.import` | import, download | No |
| `admin.import.transactions` | Import Transactions | component | admin | `admin.import` | import, download | No |
| `admin.git_sync` | Git Sync & Database Backup | page | admin | - | view, edit | No |
| `admin.git_sync.backup` | Database Export & Git Push | action | admin | `admin.git_sync` | backup | No |
| `admin.git_sync.pull` | Git Pull | action | admin | `admin.git_sync` | pull | No |
| `admin.git_sync.restore` | Database Restore | action | admin | `admin.git_sync` | restore | No |
| `admin.legacy_clients` | Legacy System Users List | page | admin | - | view, delete, export | No |
| `admin.features` | Feature Controls | page | admin | - | view, edit | Yes |

### Reports

| Key | Label | Type | Scope | Parent | Actions | Protected |
|---|---|---|---|---|---|---|
| `reports.center` | Report Center | page | reports | - | view | No |
| `reports.center.sales_register` | Sales Register | page | reports | `reports.center` | view, export, print | No |
| `reports.center.sales_summary` | Daily Sales Summary | page | reports | `reports.center` | view, export, print | No |
| `reports.center.sales_by_customer` | Sales by Customer | page | reports | `reports.center` | view, export, print | No |
| `reports.center.sales_by_item` | Sales by Item | page | reports | `reports.center` | view, export, print | No |
| `reports.center.top_profit_items` | Top Profit Items | page | reports | `reports.center` | view, export, print | No |
| `reports.center.open_invoices` | Open Invoices | page | reports | `reports.center` | view, export, print | No |
| `reports.center.receivable_aging` | Receivable Aging | page | reports | `reports.center` | view, export, print | No |
| `reports.center.ar_balance_summary` | Receivable Balance Summary | page | reports | `reports.center` | view, export, print | No |
| `reports.center.customer_statement` | Customer Statement | page | reports | `reports.center` | view, export, print | No |
| `reports.center.customer_confirmation` | Balance Confirmation | page | reports | `reports.center` | view, export, print | No |
| `reports.center.ar_register` | Receivable Register | page | reports | `reports.center` | view, export, print | No |
| `reports.center.payments_by_invoice` | Payments by Invoice | page | reports | `reports.center` | view, export, print | No |
| `reports.center.purchase_register` | Purchase Register | page | reports | `reports.center` | view, export, print | No |
| `reports.center.purchases_by_vendor` | Purchases by Supplier | page | reports | `reports.center` | view, export, print | No |
| `reports.center.purchases_by_item` | Purchases by Item | page | reports | `reports.center` | view, export, print | No |
| `reports.center.open_bills` | Open Bills | page | reports | `reports.center` | view, export, print | No |
| `reports.center.payable_aging` | Payable Aging | page | reports | `reports.center` | view, export, print | No |
| `reports.center.ap_balance_summary` | Payable Balance Summary | page | reports | `reports.center` | view, export, print | No |
| `reports.center.vendor_confirmation` | Balance Confirmation | page | reports | `reports.center` | view, export, print | No |
| `reports.center.vendor_statement` | Supplier Statement | page | reports | `reports.center` | view, export, print | No |
| `reports.center.ap_register` | Payable Register | page | reports | `reports.center` | view, export, print | No |
| `reports.center.payments_by_bill` | Payments by Bill | page | reports | `reports.center` | view, export, print | No |
| `reports.center.stock_summary` | Stock Summary | page | reports | `reports.center` | view, export, print | No |
| `reports.center.inventory_valuation` | Inventory Valuation | page | reports | `reports.center` | view, export, print | No |
| `reports.center.low_stock` | Low Stock | page | reports | `reports.center` | view, export, print | No |
| `reports.center.less_stock` | Stock Below Reorder Level | page | reports | `reports.center` | view, export, print | No |
| `reports.center.urgent_buy` | Urgent Replenishment | page | reports | `reports.center` | view, export, print | No |
| `reports.center.stock_movement` | Stock Movements | page | reports | `reports.center` | view, export, print | No |
| `reports.center.stock_ledger` | Item Stock Ledger | page | reports | `reports.center` | view, export, print | No |
| `reports.center.inventory_profitability` | Inventory Profitability | page | reports | `reports.center` | view, export, print | No |
| `reports.center.inventory_revenue` | Inventory Revenue | page | reports | `reports.center` | view, export, print | No |
| `reports.center.slow_moving` | Slow-Moving Items | page | reports | `reports.center` | view, export, print | No |
| `reports.center.trial_balance` | Trial Balance | page | reports | `reports.center` | view, export, print | No |
| `reports.center.income_statement` | Income Statement | page | reports | `reports.center` | view, export, print | No |
| `reports.center.balance_sheet` | Balance Sheet | page | reports | `reports.center` | view, export, print | No |
| `reports.center.general_ledger` | General Ledger | page | reports | `reports.center` | view, export, print | No |
| `reports.center.journal_register` | Journal Register | page | reports | `reports.center` | view, export, print | No |
| `reports.center.comparative_income` | Comparative Income Statement | page | reports | `reports.center` | view, export, print | No |
| `reports.center.comparative_balance_sheet` | Comparative Balance Sheet | page | reports | `reports.center` | view, export, print | No |
| `reports.center.equity_statement` | Statement of Equity | page | reports | `reports.center` | view, export, print | No |
| `reports.center.retained_earnings` | Accumulated Earnings | page | reports | `reports.center` | view, export, print | No |
| `reports.center.cash_flow` | Cash Flow Counterparts | page | reports | `reports.center` | view, export, print | No |
| `reports.center.financial_ratios` | Financial Ratios | page | reports | `reports.center` | view, export, print | No |
| `reports.center.daily_profit` | Daily Operational Profit | page | reports | `reports.center` | view, export, print | No |
| `reports.center.cash_book` | Cash Book | page | reports | `reports.center` | view, export, print | No |
| `reports.center.bank_book` | Bank Book | page | reports | `reports.center` | view, export, print | No |
| `reports.center.payment_register` | Payment Register | page | reports | `reports.center` | view, export, print | No |
| `reports.center.expense_register` | Expense Register | page | reports | `reports.center` | view, export, print | No |
| `reports.center.vat_sales` | Sales VAT Register | page | reports | `reports.center` | view, export, print | No |
| `reports.center.vat_purchase` | Purchase VAT Register | page | reports | `reports.center` | view, export, print | No |
| `reports.center.abbreviated_tax_invoice` | Abbreviated Tax Invoices | page | reports | `reports.center` | view, export, print | No |
| `reports.center.promotions` | Promotion Performance | page | reports | `reports.center` | view, export, print | No |
| `reports.center.pos_summary` | POS Checkout Summary | page | reports | `reports.center` | view, export, print | No |
| `reports.center.break_even_payback` | Break-Even & Simple Payback | page | reports | `reports.center` | view, export, print | No |
| `reports.center.statement_share` | Statement Sharing | component | reports | `reports.center` | view, copy, open_whatsapp | No |
| `reports.legacy.pos_sales` | POS Sales | page | reports | - | view, export | No |
| `reports.legacy.sales` | Sales | page | reports | - | view, export | No |
| `reports.legacy.purchase` | Purchase | page | reports | - | view | No |
| `reports.legacy.daily_purchase` | Daily Purchase | page | reports | - | view, export | No |
| `reports.legacy.daily_expense` | Daily Expense | page | reports | - | view, export | No |
| `reports.legacy.stock` | Detailed Stock | page | reports | - | view | No |
| `reports.legacy.stock_current` | Current Stock | page | reports | - | view, export | No |
| `reports.legacy.stock_low` | Low Stock | page | reports | - | view | No |
| `reports.legacy.item_ledger` | Item Ledger | page | reports | - | view | No |
| `reports.legacy.stock_valuation` | Stock Valuation | page | reports | - | view | No |
| `reports.legacy.customer_statement` | Customer Statement | page | reports | - | view | No |
| `reports.legacy.customer_outstanding` | Customer Outstanding | page | reports | - | view, export | No |
| `reports.legacy.vendor_outstanding` | Vendor Outstanding | page | reports | - | view, export | No |
| `reports.legacy.account_ledger` | Account Ledger | page | reports | - | view | No |
| `reports.legacy.profit` | Profit & Loss | page | reports | - | view | No |
| `reports.legacy.cash_book` | Account Book | page | reports | - | view | No |
| `reports.legacy.vat_sales` | VAT Sales | page | reports | - | view, export | No |
| `reports.legacy.vat_purchase` | VAT Purchase | page | reports | - | view, export | No |
| `reports.legacy.vat_summary` | VAT Summary | page | reports | - | view | No |

## Review Notes

- The existing application is single-business. No current entry implies tenant isolation or a working Super Admin role.
- The earlier separate-database isolation direction conflicts with this prompt's requirement for `company_id` on every business table. The architecture must be agreed before Phase 2; do not activate either unfinished approach yet.
- Shared endpoints, direct PHP routes, exports and embedded dashboard data need explicit authorization mappings and tests in Phase 3.
- Forms, tables, dynamic chart values and line controls are represented by their owning features rather than separate permissions for every row or field.
- Inventory was derived from source; reachability, full route coverage and state-dependent behavior still need verification. Existing role permissions remain coarse-grained.

