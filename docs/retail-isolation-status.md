# Retailer isolation: not implemented

The application currently has one shared business context. Administrator type 1
can see and manage all users; there is no distinct Super Admin, retailer table,
or user-to-retailer association. Do not use this installation for mutually
untrusted retailers until tenant isolation has been implemented and verified.

Filtering the User Management screen is insufficient. User detail and edit
routes, user save/delete, role assignments, search indexes, activity assignees,
and shared option endpoints also expose or accept user IDs. Inventory, parties,
transactions, accounting, reports, exports, caches, and files likewise need
review if business data is isolated per retailer.

Each retailer is intended to sign in with a business code, resolved on the
server and bound to the authenticated session. The requested architecture is now
a shared database with company scoping. The earlier separate-database directory
draft is not used and must not be activated. The shared-company draft migration
has been applied manually to the branch's `stockmgmt` database; the permission
evaluator and company administration service are deliberately not connected to
live sign-in, request handling or the normal migration runner.
Opt-in cache namespaces are also not wired into requests. None of these drafts
isolate a retailer today.

The draft shared model has company types, companies, feature allowances,
company-scoped roles, user blocks, audit entries and impersonation session
records. Isolated tests cover policy intersections, metadata-only flag changes,
blocking a company and rejecting a cross-company role assignment. The schema
does not yet add company IDs to business tables, and no existing business query
is tenant-filtered. Full tenant isolation, user/role management, impersonation,
and browser/API enforcement remain unfinished.

The intended platform Super Admin is the existing `admin` account. A future
implementation must distinguish that account from retailer administrators,
derive retailer membership server-side, audit access changes, and test direct
URLs and APIs with at least two retailers. Existing data needs a deliberate
migration and backup; do not infer or silently rewrite its ownership.
