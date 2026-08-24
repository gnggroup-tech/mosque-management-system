# SGAR data backups

SGAR format version 2 creates an encrypted, application-level snapshot with one authenticated encrypted payload per database row. The encrypted manifest records:

- the format version and creation date;
- the exact application version or commit when `APP_VERSION` is available;
- the exact ordered table list;
- the row count for every table;
- a deterministic SHA-256 content digest for every table.

The manifest and every record are independently encrypted and authenticated with Laravel's encrypter. Any missing, added, reordered or modified record, any altered manifest, and any decryption failure make the archive invalid.

Password hashes, remember tokens, password-reset tokens, sessions, queued jobs, caches, logs, temporary files, `.env`, and public uploads are not included. The `mosque_user` table is included because it is the canonical source of local mosque authority. `user_invitations` is included to preserve invitation history.

## Private storage and encryption

Keep `SGAR_BACKUP_DISK=backups` unless an equivalent private Laravel filesystem disk has been configured with `visibility=private`. The default disk writes beneath `storage/app/private/backups`; it must never be exposed by the web server or by `storage:link`.

`SGAR_BACKUP_RETENTION_DAYS` controls retention and defaults to 30 days. The archive encryption key is the application's `APP_KEY`. Never put that key in the repository, filename, command output, audit metadata, or the same storage location as the backup.

Copy verified archives to encrypted off-site storage using infrastructure credentials that are not stored in this repository. TASK-034A does not schedule backups; creation remains an explicit operator action.

## Creation

From the Laravel backend:

```shell
php artisan sgar:backup:create
php artisan sgar:backup:create --keep=60
```

Creation fails before publishing an archive if any required table configured in `config/backup.php` is missing. Reads occur in a database transaction so counts, hashes and encrypted records describe the same logical snapshot.

## Verification

Verify an archive on the configured private disk before copying or restoring it:

```shell
php artisan sgar:backup:verify sgar-data-YYYYMMDD-HHMMSS-random.jsonl.enc
```

Verification authenticates and decrypts the manifest and every record, enforces the configured table order, then recomputes every count and digest. It does not write to the database. A generic failure is returned without printing decrypted data, hashes, tokens, keys, cookies, sessions or raw exception messages.

## Foreign-key-safe restoration order

The order in `config/backup.php` is part of the authenticated manifest and is also the only accepted insertion order:

1. `users`;
2. `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`;
3. `mosques`, `mosque_user`, `user_invitations`;
4. `mosque_councils`, `council_members`, `faithful`;
5. `donations`, Zakat tables, Waqf tables, `subsidies`, `expenses`;
6. `activities`, `activity_registrations`;
7. `announcements`, `announcement_receipts`;
8. council meetings, participants and decisions;
9. `audit_logs`.

Changing this list requires a new format or a deliberately compatible migration plan. An archive whose table list differs from the running release is rejected.

## Protected restoration

Restoration is available only when all of these safeguards are satisfied:

- the application environment is `local` or `testing`;
- the operator supplies `--confirm-isolated`;
- the current database has the fully migrated schema;
- every restored table is empty;
- the database is isolated from production traffic;
- the archive passes a complete verification before the first insert.

Run only from an approved isolated recovery environment:

```shell
php artisan sgar:backup:restore sgar-data-YYYYMMDD-HHMMSS-random.jsonl.enc --confirm-isolated
```

The archive is verified a second time while records are inserted. All inserts and post-insert count checks run inside one database transaction. An invalid relationship, duplicate key, altered archive, count mismatch or any insertion error rolls back every inserted table. Never point this command at a live or populated database.

### Restored users

The initial users migration defines `password` as `NOT NULL`, while authentication secrets are intentionally excluded from backups. Immediately before inserting each user, `App\Services\BackupRestorePreparer::user()` generates and hashes a different cryptographically random value, discards it, and clears `remember_token`.

Account status, activation, suspension, archive and verification state are preserved. No operator or restored user knows the generated password, so every account must complete Laravel's password-reset flow before authenticating. Never replace this mechanism with a shared, hard-coded, logged or operator-known password.

### Restored invitations

Accepted invitations are retained as history. Every unaccepted invitation is restored with a new unknowable token hash and an expiration in the past. Consequently, a pre-backup invitation URL cannot become valid again. A superadmin may use the existing secure resend workflow, which rotates the token and generates the only usable replacement URL.

## Post-restore verification

The command checks the restored record count for every table before commit. The recovery operator must additionally verify, without exposing sensitive values:

1. users and account statuses;
2. the 45 permissions, roles and their pivots;
3. `mosque_user`, `membership_type` and canonical local administration;
4. historical `mosques.admin_id` compatibility;
5. invitation history and expiration of pending invitations;
6. financial amounts, currencies and aggregate totals;
7. audits and key business relationships;
8. password-reset and authorization smoke tests.

Obtain explicit operator approval before switching traffic. Regularly rehearse the complete procedure against a newly migrated isolated database and retain the verification evidence outside the archive.

## Failures, rollback and confidentiality

Do not retry against a partially populated database. Investigate the source archive and schema, recreate a new empty isolated database, then verify again. Never disable foreign keys to force an import.

Never print decrypted rows, password or token hashes, raw tokens, credentials, cookies, session identifiers, encryption keys or raw exception messages to shared logs. A failed verification or restoration intentionally reports only a generic message.

## Compatibility with version 1

Version 1 archives are deliberately rejected by the version 2 verifier and restore command. They lack the authenticated manifest, deterministic table counts and hashes, `mosque_user`, and `user_invitations`, so they cannot satisfy canonical or atomic restoration guarantees.

If a legacy archive must be recovered, use the exact historical application release in an isolated forensic environment under an approved manual procedure. Validate the recovered data, recreate canonical memberships, invalidate pending invitations, and immediately create and verify a new version 2 archive. Never import a version 1 archive directly with the version 2 command.
