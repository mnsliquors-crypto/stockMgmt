# Friend Version Merge

Source: `/Users/bdahal/Downloads/sms-main.zip`, archive revision
`9f669fa329e464a80735eea3c232054098f893b4`.

The archive is a separate PDO/header-line application, not a drop-in upgrade of
this mysqli application. Its README and scripts are reference material, not
instructions to run. Its SQL dumps, credentials, scratch repair scripts, automatic
schema bootstrapping, and account-reset code must not replace this installation.
The current accounting journals, purchasing approvals, and business data remain
authoritative. The existing installed dependencies are used; no CDN packages or
new software are required.

## Comparison And Delivery

| Capability in the archive | Existing application | Merge status |
| --- | --- | --- |
| Executive dashboard, preferences, theme, shortcuts, account/cash drilldowns | Basic dashboard | Adapted; real metrics, seven charts, per-user settings, responsive layout |
| Per-location inventory and internal stock transfers | Aggregate inventory | Added location stock/as-of balances, immutable transfers and reversals |
| Bottle/case packaging and barcodes | Single item unit | Added conversions and barcode search; historical factors retained in line snapshots |
| Scheduled/quantity/location promotions and performance | Manual selling prices | Added server-side POS pricing, priorities, scopes, overrides, and captured savings |
| Tasks, events, calls, calendar, assignments | Not present | Added versioned activities, assignment permissions, and local FullCalendar |
| Expanded report center, aging, operational analyses | Stock/VAT/basic operational reports | Added searchable 54-report center, filters, CSV, and print |
| Comparative financials, cash flow, equity, ratios | Posted trial balance, income, balance sheet | Added posted-ledger comparisons, equity, ratios, cash counterparts, and explicit break-even/payback assumptions |
| Custom roles and permissions | Administrator/staff | Added per-module none/read/write roles, enforced on pages and direct endpoints |
| Fiscal-year closing and balance snapshots | Locked accounting periods | Adapted to full period coverage and posted balances; reopening preserves previously closed periods |
| Credit memo/vendor credit application across invoices | Linked customer/supplier returns | Added allocation/reversal of available posted return credits to another invoice of the same party and type |
| Individual POS history and summary | Consolidated daily sales | Added immutable checkout receipts, printable receipt detail, summary, and idempotent checkout |
| Accounting/inventory integrity checks | Regression suites and posting guards | Added honest health checks, location-stock guards, and posted/submitted metadata protection |
| Local natural-language draft interpretation | Not present | Added rule-based item/party/quantity recognition; review-only, never automatically posts |
| WhatsApp statements | Not configured | Added editable, review-first WhatsApp drafts and clipboard copying; no automatic messages or provider credentials |
| Dynamic eSewa QR | No configured merchant payload | Requires verified merchant payload; no payment details imported |
| Backup/import/export tools | Existing protected import/export/Git tools | Preserved existing admin tools; migration and backup performed separately, not through archive restore scripts |
| Forgotten-password OTP | Administrator password management | Unsafe demo OTP/token disclosure excluded |

Do not infer functional coverage from the presence of a screen in the archive.
For example, its health engine queries table/column names inconsistent with its
main schema and hard-codes a healthy overall status. Its password-reset endpoint
returns the OTP and reset token to an unauthenticated caller. Those behaviors
must not be copied.

## Data And Accounting Decisions

- `003_friend_features` creates sidecar tables, views, guards, and one Main Store
  location. It does not import archive records or rewrite existing transactions.
  Legacy inventory is treated as Main Store unless explicitly assigned later.
- Existing inward-weighted item costing remains authoritative. Historical costs
  without snapshots remain estimates; the upgrade does not invent FIFO layers.
- Purchase-order locations and unit factors flow through receiving. Submitted
  order metadata and posted document metadata are protected by database triggers.
- Receipts after the upgrade are individual immutable snapshots even when their
  unposted source sale consolidates. Posted source sales start a new source sale.
- Financial statements use posted journals, while operational reports include
  unposted documents and say so. Cash flow is a counterpart analysis, not a fully
  classified statutory cash flow statement. Break-even uses stated fixed-cost
  assumptions; payback requires supplied cash flow, not profit.
- Fiscal-year closing does not add duplicate opening/closing journals. Balances
  carry through the posted ledger; reopening is explicit and audited.
- Credits reallocate an existing posted return credit and do not duplicate cash,
  inventory, tax, or general-ledger entries. Standalone non-return credit memo
  authoring is not part of this merge; use reviewed accounting journals where
  appropriate rather than fabricating returned stock.
- Existing document-level VAT is retained. Mixed per-line VAT rates from the
  archive have not been grafted onto the legacy tax model. The report center does
  not imply regulatory certification of invoice formats.
- WhatsApp opens only after an explicit action on the reviewed draft. Automatic
  provider delivery requires a separately configured provider and consent policy.
  eSewa QR remains disabled until a verified merchant payload is supplied; the
  friend's payment QR and third-party QR image service are not copied.
- Existing import/export and administrator backup tools remain in place. The
  archive's arbitrary SQL restore and demo password-reset behavior are excluded.

## Verification

Use a fresh disposable database ending in `_test`, with its server on port 8081.
Run accounting first, because its opening trial-balance assertions require an
otherwise empty posted ledger. Set `APP_CACHE_DIR` to a disposable directory.

```sh
php tests/accounting.php
php tests/regression.php
php tests/accounting_pos.php
php tests/accounting_settlements.php
php tests/procurement.php
php tests/enterprise.php
php tests/http.php
php tests/procurement_http.php
php tests/enterprise_http.php
node tests/browser_accounting.mjs
node tests/browser_procurement.mjs
node tests/browser_enterprise.mjs
```

Browser checks use the existing Chrome debugging endpoint on port 9223 and native
Node APIs, without installing packages. They inspect desktop/mobile layouts,
rendered chart pixels, preferences, activities, reports, case barcode checkout,
receipts, and purchasing/accounting workflows. All fixtures stay in the disposable
database. Business-table fingerprints are compared before and after migration.

Verified locally on 2026-11-20: the fresh database run passed 661 service/HTTP
checks plus POS/VAT integrations. Subsequent role-landing and navigation checks
also passed. The expanded enterprise browser suite passed 127 checks, including
case-order editing, location-specific promotions, statement drafts, safe search
history, and restricted staff login. The accounting and purchasing browser suites
passed as well. All PHP files passed syntax checks and the diff passed whitespace
validation.

Migration 003 was then applied to the existing `stockmgmt` database. Fingerprints
of nine business tables remained identical: 137 transactions, totaling NPR
1,432,929.00, with no imported sample activity, transfers, or counter receipts.
Read-only live checks covered 24 pages/assets and all 54 reports, followed by
desktop/mobile browser checks. Private code/database backups and migration
fingerprints are retained outside the web root.
