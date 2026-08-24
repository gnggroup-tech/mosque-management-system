<?php

namespace App\Console\Commands;

use App\Services\BackupArchiveService;
use App\Services\BackupRestorePreparer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class RestoreDataBackup extends Command
{
    protected $signature = 'sgar:backup:restore
        {path : Path on the configured private backup disk}
        {--confirm-isolated : Confirm that the current empty database is isolated from production traffic}';

    protected $description = 'Restore a verified SGAR data backup into an empty isolated database';

    public function __construct(
        private readonly BackupArchiveService $archive,
        private readonly BackupRestorePreparer $preparer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $this->assertIsolatedEnvironment();
            $diskName = (string) config('backup.disk', 'backups');
            abort_unless(config("filesystems.disks.{$diskName}.visibility") === 'private', 500);
            $disk = Storage::disk($diskName);
            $path = (string) $this->argument('path');

            $verificationStream = $disk->readStream($path);
            if ($verificationStream === null || $verificationStream === false) {
                throw new RuntimeException('The backup archive is unavailable.');
            }
            try {
                $manifest = $this->archive->verify($verificationStream);
            } finally {
                fclose($verificationStream);
            }

            DB::transaction(function () use ($disk, $path, $manifest): void {
                $this->assertDatabaseIsEmpty($manifest['tables']);
                $restoreStream = $disk->readStream($path);
                if ($restoreStream === null || $restoreStream === false) {
                    throw new RuntimeException('The backup archive is unavailable.');
                }

                try {
                    $verified = $this->archive->verify(
                        $restoreStream,
                        fn (string $table, array $payload) => DB::table($table)->insert(
                            $this->prepare($table, $payload)
                        ),
                    );
                } finally {
                    fclose($restoreStream);
                }

                if ($verified !== $manifest) {
                    throw new RuntimeException('The backup archive changed during restoration.');
                }

                $this->archive->assertRestoredDatabase($verified);
            }, 1);
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->components->info(__('Backup restored successfully.'));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->components->error(__('The backup could not be restored.'));

            return self::FAILURE;
        }
    }

    private function assertIsolatedEnvironment(): void
    {
        $allowed = (array) config('backup.restore.allowed_environments', ['local', 'testing']);
        if (! $this->option('confirm-isolated') || ! in_array(app()->environment(), $allowed, true)) {
            throw new RuntimeException('The restore environment is not permitted.');
        }
    }

    /** @param list<string> $tables */
    private function assertDatabaseIsEmpty(array $tables): void
    {
        if (! config('backup.restore.require_empty_database', true)) {
            throw new RuntimeException('The empty database safeguard is disabled.');
        }

        foreach ($tables as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('The restore database is not empty.');
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function prepare(string $table, array $payload): array
    {
        return match ($table) {
            'users' => $this->preparer->user($payload),
            'user_invitations' => $this->preparer->invitation($payload),
            default => $payload,
        };
    }
}
