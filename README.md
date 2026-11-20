# Stock Management

A PHP/MySQL inventory and bookkeeping application for a retail store. It tracks
items, suppliers, customers, purchases, sales, POS receipts, returns, stock
adjustments, payments, expenses, account transfers, opening balances, and daily
cash reconciliation. Reports cover stock, profit, receivables, payables, and VAT.

The UI uses AdminLTE, Bootstrap, jQuery, DataTables, and Select2. `classes/Master.php`
handles business transactions, `classes/Users.php` manages accounts, and
`classes/Login.php` authenticates against the same `users` table. `entity_list`
holds customer and supplier records. Historical `User` entities are retained;
they are not automatically merged into login accounts.

## Local Setup

Requires PHP 8.1+ with mysqli, mysqlnd, BCMath, GD, fileinfo, and cURL, plus MySQL 8 or
MariaDB. XAMPP is supported. The bundled database is a store snapshot, so import
it into a disposable development database first.

```sh
mysql -u root -e 'CREATE DATABASE stockmgmt_dev CHARACTER SET utf8mb4'
mysql -u root stockmgmt_dev < database/sms_db.sql
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_USER=root DB_NAME=stockmgmt_dev
export APP_BASE_URL=http://127.0.0.1:8080/
php database/migrate.php
php -S 127.0.0.1:8080 router.php
```

Open `http://127.0.0.1:8080/admin/`. Set `DB_PASSWORD` when the database needs a
password. `APP_TIMEZONE` defaults to `Asia/Kathmandu`. With XAMPP's `/sms/` path,
the default URL is `http://localhost/sms/`. Environment variables are read by
`initialize.php`; `.env` files are not loaded automatically.
On this Mac, the installed PHP executable is `/opt/homebrew/opt/php@8.3/bin/php`.

Run `php database/migrate.php` once after upgrading an existing installation or
restoring the bundled backup. It adds missing audit, return-unit, and
reconciliation columns without deleting records and is safe to run again.

New passwords use PHP password hashing. Existing MD5 passwords remain usable and
are upgraded on successful login. Change supplied credentials before deployment.

New sales and POS entries preserve their unit cost so later purchase-price changes
do not rewrite their profit. Legacy sales without recorded costs retain the old
current-cost fallback; accurate historical backfilling needs the original costs.

Apache must allow the included `.htaccess` rules and enable `mod_rewrite`.
For other web servers, deny public access to hidden files, `database/`, `temp/`,
`tests/`, configuration files, and SQL dumps. Use `router.php` with PHP's local
server so these files cannot be downloaded.

## Verification

Tests require a disposable database whose name ends in `_test` or `_review`.
They create and change fixtures and must never point at a business database.
Import `database/sms_db.sql`, run the migration, and start the app against that
database before running:

```sh
php tests/regression.php
php tests/http.php
```

Both scripts use the same environment variables as the app. The regression suite
checks user/password persistence, customer/vendor creation, transaction edits and
deletions, stock restoration, input validation, and reconciliation snapshots.
HTTP checks cover login, CSRF, staff permissions, routing, and page/form rendering.

Git backup operations can commit and publish the database snapshot. They require
an administrator and a valid POST token. Configure executable paths in System
Settings. Review the remote repository and data-sharing requirements before use.

## ERP Accounting Release

The Accounting menu adds a separate, posted general ledger. Its reports include
only posted journals and are distinct from the older operational reports.

Available now:

- Chart of accounts, with individual ledger mappings for cash/bank accounts.
- Manual journals with editable drafts, posting, and dated reversal entries.
- Review and posting for sales/POS, purchases, payments, paid expenses, stock
  adjustments, transfers, opening balances, and linked returns.
- Trial balance, income statement, balance sheet, and CSV exports.
- Accounting periods with documented closing and reopening actions.
- Append-only accounting audit events, exact decimal journal arithmetic, and
  `DECIMAL` storage for money, rates, and quantities.
- Database-enforced protection against changing posted documents or their lines
  and against writing business documents into closed periods.
- POS creates a new document after the previous consolidated sale is posted.

Administrators can create/post journals, review source documents, and manage
periods. Staff can read the accounting screens and reports. Posting is explicit:
open **Accounting > Source Documents**, review the proposed entry, then post it.
Source documents are locked after posting; corrections use linked returns.
Manual journals use reversal entries instead of editing posted history.

Existing documents are not automatically posted. Choose an accounting cutover
date and enter verified opening balances before relying on ledger reports for
the business. Older sales without recorded original costs cannot be posted until
those costs have been reconciled. Taxed returns require an explicit tax credit.
Unpaid expenses must be settled before source posting in this release.

The migration creates the current calendar month as an open accounting period.
Additional periods can use custom dates and cannot overlap. Closing requires all
business documents and journals in the period to be posted. Reversals can be
dated in a later open period without altering an earlier closed period.

The Purchasing release below adds purchase orders, receiving, replenishment, and
single-step approval routing. Warehouse/bin stock, reservations, case/bottle
conversions, sales fulfillment, multi-company/currency, bank statement matching,
and external integrations remain subsequent phases.

### Accounting Verification

Use a **fresh disposable database ending in `_test`** for a complete run. The
accounting suite retains posted fixtures because posted records are immutable.
Run the migration, then start the test app on port 8081 using that database and a
separate `APP_CACHE_DIR`. No new dependencies need downloading on this Mac.

```sh
php tests/accounting.php
php tests/regression.php
php tests/accounting_pos.php
php tests/accounting_settlements.php
APP_BASE_URL=http://127.0.0.1:8081/ php tests/http.php
```

The accounting suite checks double-entry balance, decimal rounding, historical
cost requirements, source-document locks, partial returns, closing/reopening,
append-only audit history, and simultaneous attempts to post one document.
The integration suites exercise POS rollover after posting and VAT/return/payment
reconciliation. HTTP checks include administrator/staff permissions and the new
screens and endpoints.

`tests/browser_accounting.mjs` runs against the disposable app on port 8081 and an
existing Chrome debugging endpoint on port 9223. It verifies draft creation,
posting, reversal, financial reports, and desktop/mobile layout without adding
browser packages. Screenshots are written outside the repository.

## ERP Purchasing Release

Open **Purchasing** in the main navigation. This is a separate purchase-order
workflow; existing purchase bills and historical records are preserved.

- Draft orders with supplier, expected delivery, item quantities, prices, and tax.
- Submission with a configurable amount threshold and designated administrator.
  Orders at or above the threshold require approval by someone other than their
  creator. The default threshold is zero, so all new orders require approval.
- Administrators can reject orders with a reason or explicitly reassign a pending
  approval to another independent administrator. Policy changes do not silently
  approve or reroute orders already awaiting approval.
- Partial receiving against approved quantities and prices. Each receipt creates
  one supplier purchase bill and its inventory movements atomically, using the
  approved prices. A receipt requires a supplier invoice number, unique per
  supplier across this receiving workflow, and an open accounting period. Each
  ordered line needs a positive price and a value of at least NPR 0.01;
  zero-value promotional receipts are not supported in this release.
- Duplicate-request protection, over-receipt prevention, and immutable receipts
  and linked purchase bills, including before accounting posting. Corrections use
  linked supplier returns; returning goods does not reopen the original order.
- Cancel unreceived orders or close remaining quantities on partially received
  orders with a reason, retaining the original terms and audit history.
- Replenishment by supplier, including on-hand stock, remaining approved inbound
  quantities, and draft/pending quantities. Selected shortages create an editable
  draft order. Cancelled, rejected, and closed quantities are excluded.
- Printable purchase orders, receipt history, search integration, CSV
  replenishment export, and responsive desktop/mobile screens.

Run `php database/migrate.php` to apply `002_procurement`. It only adds the
purchasing schema and guards; it does not manufacture orders or receiving history
from old purchase bills.

**Approval Policy** also has an optional mandatory purchase-order setting. It is
off initially to preserve existing direct purchase entry. Enable it to block
direct purchase creation/edits through the old forms/API and purchase rows in
transaction CSV imports. Opening-balance setup remains a separate cutover
operation. Policy changes require an administrator, a reason, and are audited.
Use named administrator accounts for independent approvals, not a shared login.

Receiving in this release combines goods receipt and supplier bill entry; it is
not a separate uninvoiced-receipts/three-way-invoice-matching system. Bill payment
and journal posting use the existing Payments and Accounting screens. Receipts
update the existing aggregate stock ledger; after migration 003, they also retain
the order's selected location and frozen case conversions. The current
historical inward-weighted cost method is retained; FIFO, landed cost, and stock
reservations have not been added. Legacy purchase bills do not participate in the
new supplier-invoice uniqueness index.

### Purchasing Verification

Use the same fresh disposable `_test` database and test server described above.
Run `tests/accounting.php` **before** the procurement suites, because its opening
trial-balance assertions require an otherwise empty posted general ledger.

```sh
php tests/accounting.php
php tests/regression.php
php tests/accounting_pos.php
php tests/accounting_settlements.php
php tests/procurement.php
APP_BASE_URL=http://127.0.0.1:8081/ php tests/http.php
APP_BASE_URL=http://127.0.0.1:8081/ php tests/procurement_http.php
```

Procurement checks cover money/quantity precision, permissions, self-approval,
policy snapshots/reassignment, stale edits, receipt rollback, partial-delivery
rounding, supplier invoice uniqueness, immutable records, accounting integration,
replenishment, and two independent processes racing one receipt. HTTP checks also
exercise the old purchase endpoint and CSV import under mandatory-PO policy.

After those suites, `tests/browser_procurement.mjs` can use the installed Chrome
debugging endpoint on port 9223. It creates an order, uses an independent checker,
receives two deliveries as staff, rejects a duplicate invoice, and checks print,
item controls, navigation, and desktop/mobile layout. Its browser users exist only
in the disposable database. No additional packages are required.

## Friend-Version Feature Merge

Migration `003_friend_features` adds an executive dashboard with saved preferences,
location stock and transfers, case packaging/barcodes, scheduled promotions,
individual POS receipts, activities/calendar, custom roles, fiscal-year controls,
return-credit allocation, local draft interpretation, and a searchable 54-report
center. Statements support editable WhatsApp drafts; no messages send automatically.
The existing installed frontend libraries are reused without package downloads.

Legacy stock defaults to Main Store. Existing documents are neither imported from
the archive nor automatically posted/revalued. Posted-ledger financial reports
remain distinct from operational reports. Keep the private pre-upgrade backup.

See [the comparison and delivery notes](docs/FRIEND_VERSION_MERGE.md) for test
commands, exact adaptations, and deliberate exclusions. These include unsafe
archive password resets/SQL restore, unverified payment QR details, provider-based
automatic messaging, standalone non-return credits, and mixed per-line VAT.
