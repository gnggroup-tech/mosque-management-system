<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CreateDataBackup extends Command
{
    protected $signature = 'sgar:backup:create {--keep= : Override the configured retention in days}';

    protected $description = 'Create an encrypted backup of SGAR application data on a private disk';

    public function __construct(private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $diskName = (string) config('backup.disk', 'backups');
        $retention = max(1, (int) ($this->option('keep') ?: config('backup.retention_days', 30)));
        $path = 'sgar-data-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.jsonl.enc';
        $stream = tmpfile();

        if ($stream === false) {
            $this->components->error(__('The backup could not be created.'));

            return self::FAILURE;
        }

        try {
            $this->writeJsonLine($stream, [
                'format' => 'sgar-encrypted-jsonl',
                'version' => 1,
                'created_at' => now()->toIso8601String(),
                'encryption' => 'laravel-encrypter-per-record',
            ]);

            foreach ((array) config('backup.tables', []) as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $query = DB::table($table);
                $columns = Schema::getColumnListing($table);
                if (in_array('id', $columns, true)) {
                    $query->orderBy('id');
                } elseif ($columns !== []) {
                    $query->orderBy($columns[0]);
                }

                foreach ($query->cursor() as $row) {
                    $payload = (array) $row;
                    foreach ((array) config("backup.excluded_columns.{$table}", []) as $column) {
                        unset($payload[$column]);
                    }
                    $this->writeJsonLine($stream, [
                        'table' => $table,
                        'payload' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ]);
                }
            }

            rewind($stream);
            $disk = Storage::disk($diskName);
            if (! $disk->put($path, $stream)) {
                throw new \RuntimeException('Backup disk rejected the write.');
            }

            $this->prune($diskName, $retention);
            $this->audit->log('backup.created', metadata: [
                'disk' => $diskName,
                'path' => $path,
                'retention_days' => $retention,
            ]);
            $this->components->info(__('Backup created successfully: :path', ['path' => $path]));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            try {
                $this->audit->log('backup.failed', metadata: [
                    'disk' => $diskName,
                    'exception' => $exception::class,
                ]);
            } catch (Throwable) {
                // The original safe error remains the command result if audit storage is unavailable.
            }
            $this->components->error(__('The backup could not be created.'));

            return self::FAILURE;
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function writeJsonLine($stream, array $value): void
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (fwrite($stream, $encoded."\n") === false) {
            throw new \RuntimeException('Unable to write backup stream.');
        }
    }

    private function prune(string $diskName, int $retentionDays): void
    {
        $disk = Storage::disk($diskName);
        $cutoff = now()->subDays($retentionDays)->getTimestamp();

        foreach ($disk->files() as $file) {
            if (str_starts_with($file, 'sgar-data-') && $disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }
    }
}
