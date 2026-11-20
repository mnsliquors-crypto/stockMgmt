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

Requires PHP 8.1+ with mysqli, mysqlnd, GD, fileinfo, and cURL, plus MySQL 8 or
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
