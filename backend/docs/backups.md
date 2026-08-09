# SGAR data backups

SGAR creates encrypted, application-level backups with one authenticated encrypted payload per database row. Password hashes, remember tokens, caches, sessions, queued jobs, logs, temporary files, `.env`, and public uploads are not included.

Because authentication secrets are deliberately excluded, restored user accounts must go through the password-reset flow before they can authenticate in the recovered environment.

## Configuration

Keep `SGAR_BACKUP_DISK=backups` unless a private Laravel filesystem disk with equivalent access controls has been configured. `SGAR_BACKUP_RETENTION_DAYS` controls automatic retention and defaults to 30 days. The encryption key is the application's `APP_KEY`; never copy that key into the repository or a backup filename.

Run from the Laravel backend:

```shell
php artisan sgar:backup:create
php artisan sgar:backup:create --keep=60
```

The default disk writes beneath `storage/app/private/backups`, which must not be exposed by the web server or by `storage:link`. Copy backups to encrypted off-site storage using infrastructure credentials that are not stored in this repository.

## Protected restoration procedure

There is intentionally no automatic restore command. Restoration must be an approved, supervised operation:

1. provision an isolated recovery environment with no production traffic;
2. restore the exact application release and securely provide the matching historical `APP_KEY`;
3. verify the backup filename, access permissions, checksum from the off-site system, JSONL header, and every encrypted payload;
4. decrypt each payload with Laravel's encrypter and validate its table against `config/backup.php`;
5. import into a new empty database in foreign-key order inside transactions, never into the live database;
6. run integrity, authorization, currency-total, and smoke tests;
7. obtain explicit operator approval before switching any traffic.

A failed decryption, unknown table, duplicate key, missing relationship, or integrity mismatch must abort the recovery. Never print decrypted rows, credentials, keys, or raw exception messages to shared logs.
