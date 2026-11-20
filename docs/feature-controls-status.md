# Feature controls: implementation status

This is an unfinished, single-business implementation. Do not enable migration
`006_feature_controls` on a live installation yet. Feature flags and permissions
are not currently enforced by application routes or user interfaces.

The feature registry, database migration, policy evaluator, administrative
service, and admin-only preview screen are available for isolated testing. The
preview requires the server environment variable `FEATURE_CONTROLS_PREVIEW=1`.
For a database not ending in `_test` it also requires explicit opt-in with
`FEATURE_CONTROLS_ALLOW_MAIN_PREVIEW=1`. The screen warns that its settings do not
enforce access. The migration is intentionally not included in
`database/migrate.php`; it has been applied manually to the branch's `stockmgmt`
database for development. Existing role scopes remain
the upper limit for staff permissions. Sign-in, sign-out, and the user's own
profile are protected registry entries; the feature-control recovery entry is
reserved for administrators.

The preview can create suggested cashier, inventory clerk, purchasing clerk,
accountant, and store manager roles. Creation does not assign users or overwrite
existing roles. Disabled features are omitted from the new roles and must be
granted explicitly after re-enabling them. These are editable starting points, not
enforced permissions. The intended future Super Admin is the existing `admin`
account; a distinct Super Admin authority has not been implemented.

Before activation:

- Map and enforce every page, direct PHP entry point, API, export and report,
  including shared endpoints and dashboard data returned as JSON.
- Complete administrator controls and add a current-user permissions endpoint; hide
  unavailable navigation and actions, and refresh permissions in active sessions.
- Seed permissions when new roles are created, wire the migration into the normal
  deployment path, and verify existing roles retain their intended access.
- Test denial and account access through HTTP, direct URLs and the browser; test
  role changes, disabled features, reports, exports and dashboard data leakage.
- Take a fresh backup and verify business data before and after migration.

The design does not implement business types, separate organizations, or a super
administrator. Those are deferred because this installation is single-business.
