# SGAR production deployment and rollback runbook

This runbook is intentionally independent of a hosting provider. Adapt service management, release switching, TLS, database connectivity and filesystem ownership to the approved infrastructure without storing credentials in the repository or command history.

TASK-034B secures release preparation and RBAC seeding. It does not configure SMTP, queue workers, the scheduler, automated or off-site backups, monitoring, alerting, invoices, or continuous deployment.

## Mandatory release prerequisites

Before approving a production release, verify all of the following:

- the exact release commit has a successful protected-branch CI result;
- PHP 8.3 is installed with `ctype`, `dom`, `fileinfo`, `filter`, `iconv`, `mbstring`, `openssl`, PDO plus the selected database driver, `session`, `tokenizer`, `xml`, and `xmlwriter`;
- Composer 2 can install from `composer.lock` without modifying it;
- Node.js 22 and npm can build from `package-lock.json` on the build host;
- the selected relational database version and transaction behavior have been validated in staging;
- the web server document root is `backend/public`, TLS is enforced, and configuration files and private storage are not web-accessible;
- the runtime identity can write only to `backend/storage` and `backend/bootstrap/cache` as required, while application code remains read-only;
- `storage/app/private/backups` or its configured equivalent is persistent, private and outside `storage:link`;
- production configuration is injected through the approved secret store and is never printed, copied into the release, or committed;
- `APP_ENV` is production, debug mode is disabled, the canonical URL is correct, and the existing `APP_KEY` is available without displaying it;
- `APP_VERSION` is set to the immutable release commit or release identifier so version 2 backup manifests identify the running release;
- a previously approved active superadmin exists when account administration is required. No seeder creates or bootstraps a superadmin.

Do not copy the development values from `.env.example` unchanged into production.

## Seeder policy

`Database\Seeders\DatabaseSeeder` is safe to repeat and creates no user in any environment. It calls only `RolesAndPermissionsSeeder`.

Production deployment must nevertheless use the explicit RBAC command:

```shell
php artisan db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force --no-interaction
```

The command initializes the 45 configured permissions and the three roles `superadmin`, `admin`, and `user`, then synchronizes their configured permission assignments. It does not create accounts, assign a role to an account, or silently delete an unexpected permission row. The post-deployment exact-count check must stop the release if the database contains a divergent permission set.

Factories are test fixtures, not production seed data. Never run a factory, create a demonstration account, use `test@example.com`, use a known password, or place a password in a versioned command. Account creation and privilege assignment must use the approved invitation, approval and provisioning workflows.

## Prepare an immutable release

Perform these steps in a new release directory, separate from the live release:

1. check out the approved commit and record its full SHA in the deployment record;
2. confirm that `git status --short` is empty and lockfiles match the approved commit;
3. inject production configuration through the infrastructure mechanism without displaying it;
4. install PHP runtime dependencies reproducibly:

   ```shell
   composer install --no-dev --classmap-authoritative --no-interaction --prefer-dist --no-progress
   ```

5. build frontend assets from the locked Node dependency graph:

   ```shell
   npm ci --ignore-scripts
   npm run build
   ```

6. verify that `public/build/manifest.json` exists and belongs to this release;
7. ensure application code is immutable and grant the runtime identity only the required write access to `storage` and `bootstrap/cache`;
8. do not expose the backup disk through a public filesystem link;
9. run non-destructive command discovery and configuration checks before the maintenance window:

   ```shell
   php artisan list
   php artisan migrate:status
   ```

Stop if Composer or npm changes a lockfile, the build manifest is missing, configuration cannot be loaded safely, or any command reports an unexpected environment or database.

## Mandatory pre-migration backup

Run the backup with the currently deployed TASK-034A-compatible release before applying migrations:

```shell
php artisan sgar:backup:create
php artisan sgar:backup:verify <returned-v2-archive-path>
```

Record the archive path, format version, verification result, release SHA, time and operator in the protected deployment evidence. Do not record decrypted content, hashes of passwords or tokens, the `APP_KEY`, cookies, sessions or credentials.

Stop the deployment if creation or verification fails, if the archive is not version 2, if its private storage cannot be confirmed, or if the matching historical `APP_KEY` cannot be recovered through the approved secret-management process.

## Maintenance window and database migration

Only after the new release and verified backup are ready:

1. prevent new writes and enter Laravel maintenance mode:

   ```shell
   php artisan down --retry=60
   ```

2. confirm active requests and writes have drained according to infrastructure procedures;
3. inspect the pending migrations:

   ```shell
   php artisan migrate:status
   ```

4. apply the reviewed migrations exactly once:

   ```shell
   php artisan migrate --force --no-interaction
   ```

5. synchronize RBAC explicitly:

   ```shell
   php artisan db:seed --class='Database\Seeders\RolesAndPermissionsSeeder' --force --no-interaction
   ```

6. clear stale caches, then rebuild only deterministic Laravel caches:

   ```shell
   php artisan optimize:clear
   php artisan config:cache
   php artisan event:cache
   php artisan view:cache
   ```

7. build a route cache only if that exact command succeeded in staging for the same release. A route-cache failure is a stop condition; do not conceal it:

   ```shell
   php artisan route:cache
   ```

8. activate the new immutable release using the infrastructure's atomic release-switch mechanism.

Do not run `DatabaseSeeder` as a shortcut, and never run a demo or factory seeder in production.

## Validation before reopening traffic

While traffic remains controlled, verify:

- `php artisan migrate:status` reports every expected production migration as applied;
- the database contains exactly 45 permission names and the three expected role names;
- role permission assignments match `config/permissions.php`;
- no account was created by deployment or seeding;
- the new release SHA matches `APP_VERSION` in operational metadata without exposing other configuration;
- storage and cache directories are writable by the runtime identity, while the application and private backup remain inaccessible from HTTP;
- the compiled frontend manifest and critical static assets load;
- an internal request to `/up` returns HTTP 200. This endpoint proves process liveness only; it is not a database, mail, queue or storage readiness check;
- login, logout and CSRF behavior work with an existing approved account;
- a superadmin can reach the account directory and an authorized local admin remains limited to canonical mosque memberships;
- a read-only financial report remains scoped to the authorized mosque and preserves currency separation;
- application and web-server logs contain no new critical error or secret.

If all checks pass, leave maintenance mode:

```shell
php artisan up
```

Repeat the external `/up`, login-page, asset and authorization smoke checks after traffic is restored.

## Stop criteria

Keep or return the application to maintenance mode and stop immediately when any of these occurs:

- the release SHA, CI result, branch protection or lockfiles do not match the approval;
- the worktree is dirty or contains an unexpected file;
- the version 2 backup or verification fails;
- the historical `APP_KEY` is unavailable or its continuity is uncertain;
- Composer installation, frontend build, cache generation, migration or RBAC synchronization fails;
- a migration was not rehearsed against representative staging data;
- the database, private storage or directory permissions point to an unexpected target;
- the migration list, 45 permissions, three roles or role assignments differ from expectations;
- `/up`, critical assets, authentication, canonical authorization or financial scope checks fail;
- logs show a new critical exception, data-integrity error or possible secret disclosure;
- an open production blocker below has not been explicitly accepted by the release authority.

Never continue merely because maintenance time is limited. Preserve command output and timestamps after removing secrets.

## Safe code rollback

A code rollback is permitted only after assessing database compatibility:

1. enter or keep maintenance mode and stop writes;
2. preserve application, web-server, database and deployment logs plus the failed release SHA and timeline;
3. determine whether the previous release can safely run against the current schema;
4. if compatible, atomically reactivate the previous immutable release;
5. using that previous release, clear and rebuild its deterministic caches;
6. repeat health, authentication, authorization and financial-scope checks before `php artisan up`.

Do not automatically execute `php artisan migrate:rollback` against production. A down migration can destroy data, may not reverse data transformations, and can make both releases unusable. Any schema reversal requires a separately reviewed database change plan.

## Database recovery rollback

If the previous code cannot safely use the migrated database, do not restore directly over production:

1. provision a new empty database in an isolated recovery environment with no production traffic;
2. deploy the exact application release required by the verified pre-migration version 2 archive;
3. provide the matching historical `APP_KEY` through the approved secret store without displaying or copying it into logs;
4. verify the archive again:

   ```shell
   php artisan sgar:backup:verify <verified-v2-archive-path>
   ```

5. restore only in the isolated `local` or `testing` recovery environment:

   ```shell
   php artisan sgar:backup:restore <verified-v2-archive-path> --confirm-isolated
   ```

6. validate users, account states, roles, all 45 permissions, canonical memberships, historical `admin_id`, invitation invalidation, financial amounts and currencies, audits, row counts and application smoke tests;
7. obtain explicit technical and business approval before switching production connectivity to the validated recovered database;
8. retain the failed database and all incident evidence according to the incident-retention policy until investigation is complete.

Never restore a version 1 archive with the version 2 command, reuse an old invitation URL, expose the `APP_KEY`, or suppress an integrity failure.

## Pre-deployment checklist

- [ ] Approved immutable SHA and successful CI recorded.
- [ ] Worktree and lockfiles clean and unchanged.
- [ ] Production configuration injected without disclosure.
- [ ] Existing `APP_KEY` continuity confirmed securely.
- [ ] `APP_VERSION` identifies the approved release.
- [ ] Composer production install and npm build succeeded reproducibly.
- [ ] Frontend manifest exists.
- [ ] Runtime and private-storage permissions validated.
- [ ] Version 2 backup created and successfully verified.
- [ ] Backup evidence and recovery owner recorded.
- [ ] Migration and rollback compatibility reviewed in staging.
- [ ] Existing approved administrative account available; no automatic account creation planned.
- [ ] Open blockers accepted or deployment stopped.

## Post-deployment checklist

- [ ] Expected migrations applied with `--force`.
- [ ] Explicit RBAC seeder completed.
- [ ] Exactly 45 permissions and three roles confirmed.
- [ ] No user created by deployment or seeding.
- [ ] Laravel caches rebuilt successfully.
- [ ] Runtime directories writable and private data inaccessible over HTTP.
- [ ] `/up` returns HTTP 200 before and after reopening traffic.
- [ ] Login, logout, CSRF and account-status enforcement smoke-tested.
- [ ] Superadmin and canonical local-admin scopes verified.
- [ ] Financial currency and mosque scope smoke-tested.
- [ ] Logs reviewed for critical errors and secret exposure.
- [ ] Maintenance mode disabled only after approval.
- [ ] Release SHA, timestamps, operators and evidence archived.

## Open production blockers outside TASK-034B

The following remain unresolved and are not configured by this task:

- real SMTP delivery and delivery verification;
- queue workers, restart policy and failed-job operations;
- scheduler execution;
- scheduled backups and encrypted off-site replication;
- monitoring, readiness probes, metrics, alerting and incident notification.

Production readiness must not be claimed until each blocker has an implemented, tested and approved operational solution or a formally accepted risk treatment.
