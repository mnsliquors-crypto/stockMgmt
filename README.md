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

The rest of the ERP roadmap is not implemented by this release: warehouse/bin
stock, reservations, case/bottle conversions, purchase-order/receiving and sales
fulfillment workflows, configurable approval routing, multi-company/currency,
bank statement matching, and external integrations remain subsequent phases.

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
