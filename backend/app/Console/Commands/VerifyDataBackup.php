<?php

namespace App\Console\Commands;

use App\Services\BackupArchiveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class VerifyDataBackup extends Command
{
    protected $signature = 'sgar:backup:verify {path : Path on the configured private backup disk}';

    protected $description = 'Verify the manifest, encryption and integrity of an SGAR data backup';

    public function __construct(private readonly BackupArchiveService $archive)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $stream = null;

        try {
            $diskName = (string) config('backup.disk', 'backups');
            abort_unless(config("filesystems.disks.{$diskName}.visibility") === 'private', 500);
            $stream = Storage::disk($diskName)->readStream((string) $this->argument('path'));
            if ($stream === null || $stream === false) {
                throw new \RuntimeException('The backup archive is unavailable.');
            }
            $this->archive->verify($stream);
            $this->components->info(__('Backup verified successfully.'));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->components->error(__('The backup could not be verified.'));

            return self::FAILURE;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
