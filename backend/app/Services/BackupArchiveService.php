<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;
use Throwable;

class BackupArchiveService
{
    public const FORMAT = 'sgar-encrypted-jsonl';

    public const VERSION = 2;

    /**
     * Write a deterministic logical snapshot to an already opened stream.
     *
     * @param  resource  $stream
     * @return array<string, mixed>
     */
    public function write($stream): array
    {
        $tables = $this->configuredTables();
        $this->assertRequiredTablesExist($tables);
        $records = tmpfile();

        if ($records === false) {
            throw new RuntimeException('The backup archive could not be created.');
        }

        try {
            $manifest = DB::transaction(function () use ($tables, $records): array {
                $rowCounts = array_fill_keys($tables, 0);
                $hashContexts = array_map(fn () => hash_init('sha256'), $rowCounts);

                foreach ($tables as $table) {
                    $columns = Schema::getColumnListing($table);
                    if ($columns === []) {
                        throw new RuntimeException('The backup archive could not be created.');
                    }

                    $query = DB::table($table);
                    $orderColumns = in_array('id', $columns, true) ? ['id'] : $columns;
                    foreach ($orderColumns as $column) {
                        $query->orderBy($column);
                    }

                    foreach ($query->cursor() as $record) {
                        $payload = (array) $record;
                        foreach ((array) config("backup.excluded_columns.{$table}", []) as $column) {
                            unset($payload[$column]);
                        }

                        $payloadJson = $this->encode($payload);
                        $this->updateHash($hashContexts[$table], $payloadJson);
                        $rowCounts[$table]++;
                        $this->writeJsonLine($records, [
                            'record' => Crypt::encryptString($this->encode([
                                'table' => $table,
                                'payload' => $payloadJson,
                            ])),
                        ]);
                    }
                }

                return [
                    'format' => self::FORMAT,
                    'version' => self::VERSION,
                    'created_at' => now()->toIso8601String(),
                    'application_version' => config('backup.application_version'),
                    'tables' => $tables,
                    'row_counts' => $rowCounts,
                    'table_hashes' => array_map(fn ($context) => hash_final($context), $hashContexts),
                ];
            });

            $this->writeJsonLine($stream, [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'manifest' => Crypt::encryptString($this->encode($manifest)),
            ]);
            rewind($records);
            if (stream_copy_to_stream($records, $stream) === false) {
                throw new RuntimeException('The backup archive could not be created.');
            }

            return $manifest;
        } finally {
            fclose($records);
        }
    }

    /**
     * Verify an archive and optionally consume each verified record.
     *
     * A consumer exception is propagated so an enclosing database transaction
     * can roll back. Archive parsing errors are always replaced by a safe error.
     *
     * @param  resource  $stream
     * @param  (callable(string, array<string, mixed>): void)|null  $consumer
     * @return array<string, mixed>
     */
    public function verify($stream, ?callable $consumer = null): array
    {
        $tables = $this->configuredTables();
        $this->assertRequiredTablesExist($tables);
        rewind($stream);
        $manifest = $this->readManifest($stream, $tables);
        $rowCounts = array_fill_keys($tables, 0);
        $hashContexts = array_map(fn () => hash_init('sha256'), $rowCounts);
        $tablePositions = array_flip($tables);
        $lastPosition = 0;

        while (($line = fgets($stream)) !== false) {
            if (trim($line) === '') {
                $this->invalidArchive();
            }

            [$table, $payload, $payloadJson] = $this->readRecord($line, $tables);
            $position = $tablePositions[$table];
            if ($position < $lastPosition) {
                $this->invalidArchive();
            }
            $lastPosition = $position;
            $rowCounts[$table]++;
            $this->updateHash($hashContexts[$table], $payloadJson);

            if ($consumer !== null) {
                $consumer($table, $payload);
            }
        }

        foreach ($tables as $table) {
            $hash = hash_final($hashContexts[$table]);
            if ($rowCounts[$table] !== $manifest['row_counts'][$table]
                || ! hash_equals($manifest['table_hashes'][$table], $hash)) {
                $this->invalidArchive();
            }
        }

        return $manifest;
    }

    /** @return list<string> */
    public function configuredTables(): array
    {
        $tables = array_values((array) config('backup.tables', []));
        $required = array_values((array) config('backup.required_tables', []));

        if ($tables === [] || $required === [] || count($tables) !== count(array_unique($tables))) {
            throw new RuntimeException('The backup configuration is invalid.');
        }

        foreach ($required as $table) {
            if (! in_array($table, $tables, true)) {
                throw new RuntimeException('The backup configuration is invalid.');
            }
        }

        return $tables;
    }

    /**
     * Recompute the restored database snapshot before its transaction commits.
     *
     * User invitation hashes and expirations are intentionally rotated after
     * restoration, so that table receives semantic checks instead of a hash
     * comparison. Every other table must reproduce the archive digest exactly.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function assertRestoredDatabase(array $manifest): void
    {
        $tables = $this->configuredTables();
        $this->validateManifest($manifest, $tables);

        foreach ($tables as $table) {
            $context = hash_init('sha256');
            $count = 0;
            $columns = Schema::getColumnListing($table);
            $query = DB::table($table);
            foreach (in_array('id', $columns, true) ? ['id'] : $columns as $column) {
                $query->orderBy($column);
            }

            foreach ($query->cursor() as $record) {
                $payload = (array) $record;
                foreach ((array) config("backup.excluded_columns.{$table}", []) as $column) {
                    unset($payload[$column]);
                }
                $this->updateHash($context, $this->encode($payload));
                $count++;
            }

            if ($count !== $manifest['row_counts'][$table]) {
                throw new RuntimeException('The restored database failed verification.');
            }

            $hash = hash_final($context);
            if ($table !== 'user_invitations' && ! hash_equals($manifest['table_hashes'][$table], $hash)) {
                throw new RuntimeException('The restored database failed verification.');
            }
        }

        $expectedPermissions = collect((array) config('permissions.all'))->sort()->values()->all();
        $restoredPermissions = DB::table('permissions')->pluck('name')->sort()->values()->all();
        $expectedRoles = collect(array_keys((array) config('permissions.roles')))->sort()->values()->all();
        $restoredRoles = DB::table('roles')->pluck('name')->sort()->values()->all();
        if ($restoredPermissions !== $expectedPermissions || $restoredRoles !== $expectedRoles) {
            throw new RuntimeException('The restored database failed verification.');
        }

        if (DB::table('users')->whereNotIn('status', array_column(AccountStatus::cases(), 'value'))->exists()
            || DB::table('users')->whereNotNull('remember_token')->exists()
            || DB::table('model_has_roles')->where('model_type', User::class)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('users')->whereColumn('users.id', 'model_has_roles.model_id'))
                ->exists()
            || DB::table('model_has_permissions')->where('model_type', User::class)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('users')->whereColumn('users.id', 'model_has_permissions.model_id'))
                ->exists()) {
            throw new RuntimeException('The restored database failed verification.');
        }

        foreach (DB::table('user_invitations')->whereNull('accepted_at')->get(['token_hash', 'expires_at']) as $invitation) {
            if (! is_string($invitation->token_hash)
                || preg_match('/\A[a-f0-9]{64}\z/', $invitation->token_hash) !== 1
                || new DateTimeImmutable($invitation->expires_at) >= now()->toDateTimeImmutable()) {
                throw new RuntimeException('The restored database failed verification.');
            }
        }
    }

    /** @param list<string> $tables */
    private function assertRequiredTablesExist(array $tables): void
    {
        foreach ((array) config('backup.required_tables', []) as $required) {
            if (! in_array($required, $tables, true) || ! Schema::hasTable($required)) {
                throw new RuntimeException('A required backup table is unavailable.');
            }
        }
    }

    /**
     * @param  resource  $stream
     * @param  list<string>  $tables
     * @return array<string, mixed>
     */
    private function readManifest($stream, array $tables): array
    {
        try {
            $line = fgets($stream);
            if ($line === false) {
                $this->invalidArchive();
            }
            $header = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($header)
                || ($header['format'] ?? null) !== self::FORMAT
                || ($header['version'] ?? null) !== self::VERSION
                || ! is_string($header['manifest'] ?? null)) {
                $this->invalidArchive();
            }

            $manifest = json_decode(Crypt::decryptString($header['manifest']), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($manifest)) {
                $this->invalidArchive();
            }
            $this->validateManifest($manifest, $tables);

            return $manifest;
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && $exception->getMessage() === 'The backup archive is invalid or has been altered.') {
                throw $exception;
            }

            $this->invalidArchive();
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $tables
     */
    private function validateManifest(array $manifest, array $tables): void
    {
        if (($manifest['format'] ?? null) !== self::FORMAT
            || ($manifest['version'] ?? null) !== self::VERSION
            || ($manifest['tables'] ?? null) !== $tables
            || ! is_string($manifest['created_at'] ?? null)
            || ! array_key_exists('application_version', $manifest)
            || ! is_array($manifest['row_counts'] ?? null)
            || ! is_array($manifest['table_hashes'] ?? null)
            || array_keys($manifest['row_counts']) !== $tables
            || array_keys($manifest['table_hashes']) !== $tables) {
            $this->invalidArchive();
        }

        try {
            new DateTimeImmutable($manifest['created_at']);
        } catch (Throwable) {
            $this->invalidArchive();
        }

        if ($manifest['application_version'] !== null && ! is_string($manifest['application_version'])) {
            $this->invalidArchive();
        }

        foreach ($tables as $table) {
            if (! is_int($manifest['row_counts'][$table])
                || $manifest['row_counts'][$table] < 0
                || ! is_string($manifest['table_hashes'][$table])
                || preg_match('/\A[a-f0-9]{64}\z/', $manifest['table_hashes'][$table]) !== 1) {
                $this->invalidArchive();
            }
        }
    }

    /**
     * @param  list<string>  $tables
     * @return array{string, array<string, mixed>, string}
     */
    private function readRecord(string $line, array $tables): array
    {
        try {
            $outer = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($outer) || ! is_string($outer['record'] ?? null)) {
                $this->invalidArchive();
            }
            $record = json_decode(Crypt::decryptString($outer['record']), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($record)
                || ! is_string($record['table'] ?? null)
                || ! in_array($record['table'], $tables, true)
                || ! is_string($record['payload'] ?? null)) {
                $this->invalidArchive();
            }
            $payload = json_decode($record['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                $this->invalidArchive();
            }

            return [$record['table'], $payload, $record['payload']];
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && $exception->getMessage() === 'The backup archive is invalid or has been altered.') {
                throw $exception;
            }

            $this->invalidArchive();
        }
    }

    /** @param resource $context */
    private function updateHash($context, string $payload): void
    {
        hash_update($context, pack('N', strlen($payload)).$payload);
    }

    /** @param resource $stream */
    private function writeJsonLine($stream, array $value): void
    {
        if (fwrite($stream, $this->encode($value)."\n") === false) {
            throw new RuntimeException('The backup archive could not be created.');
        }
    }

    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new RuntimeException('The backup archive could not be created.');
        }
    }

    private function invalidArchive(): never
    {
        throw new RuntimeException('The backup archive is invalid or has been altered.');
    }
}
