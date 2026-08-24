<?php

namespace Tests\Feature\Reports;

use App\Enums\AccountStatus;
use App\Enums\MosqueMembershipType;
use App\Exceptions\InvitationException;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\Mosque;
use App\Models\MosqueMembership;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\BackupArchiveService;
use App\Services\UserInvitationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class VerifiableCanonicalBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'backup.application_version' => 'test-commit-034a',
        ]);
        Storage::fake('backups');
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manifest_is_complete_deterministic_and_contains_canonical_tables(): void
    {
        Carbon::setTestNow('2026-08-25 12:00:00');
        $fixture = $this->fixture();

        $firstPath = $this->createBackup();
        $firstManifest = $this->manifest($firstPath);
        $firstContents = Storage::disk('backups')->get($firstPath);
        AuditLog::query()->where('event', 'backup.created')->delete();
        $secondPath = $this->createBackup();
        $secondManifest = $this->manifest($secondPath);

        $this->assertSame(BackupArchiveService::FORMAT, $firstManifest['format']);
        $this->assertSame(BackupArchiveService::VERSION, $firstManifest['version']);
        $this->assertSame('2026-08-25T12:00:00+00:00', $firstManifest['created_at']);
        $this->assertSame('test-commit-034a', $firstManifest['application_version']);
        $this->assertSame(config('backup.tables'), $firstManifest['tables']);
        $this->assertSame(config('backup.required_tables'), $firstManifest['tables']);
        $this->assertSame(1, $firstManifest['row_counts']['mosque_user']);
        $this->assertSame(1, $firstManifest['row_counts']['user_invitations']);
        $this->assertSame($firstManifest, $secondManifest);
        foreach ($firstManifest['table_hashes'] as $hash) {
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $hash);
        }

        $this->assertStringNotContainsString('mosque_user', $firstContents);
        $this->assertStringNotContainsString('user_invitations', $firstContents);
        $this->assertStringNotContainsString($fixture['old_token'], $firstContents);
        $this->assertStringNotContainsString($fixture['original_password_hash'], $firstContents);
    }

    public function test_backup_fails_safely_when_a_required_table_is_missing(): void
    {
        Schema::drop('mosque_user');

        $this->assertSame(Command::FAILURE, Artisan::call('sgar:backup:create'));
        $this->assertSame([], Storage::disk('backups')->allFiles());
        $this->assertStringNotContainsString('mosque_user', Artisan::output());
    }

    public function test_archive_missing_a_required_table_is_rejected(): void
    {
        $this->fixture();
        $path = $this->createBackup();
        $lines = $this->lines($path);
        $header = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $manifest = json_decode(Crypt::decryptString($header['manifest']), true, 512, JSON_THROW_ON_ERROR);
        $manifest['tables'] = array_values(array_filter($manifest['tables'], fn (string $table) => $table !== 'mosque_user'));
        unset($manifest['row_counts']['mosque_user'], $manifest['table_hashes']['mosque_user']);
        $header['manifest'] = Crypt::encryptString(json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $lines[0] = json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        Storage::disk('backups')->put('missing-table.enc', implode("\n", $lines)."\n");

        $this->assertSame(Command::FAILURE, Artisan::call('sgar:backup:verify', ['path' => 'missing-table.enc']));
        $this->assertStringNotContainsString('mosque_user', Artisan::output());
    }

    public function test_manifest_and_record_tampering_are_detected_before_restore(): void
    {
        $this->fixture();
        $path = $this->createBackup();
        $lines = $this->lines($path);

        $header = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        $header['manifest'] = $this->mutateCiphertext($header['manifest']);
        $manifestLines = $lines;
        $manifestLines[0] = json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        Storage::disk('backups')->put('tampered-manifest.enc', implode("\n", $manifestLines)."\n");

        $record = json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR);
        $record['record'] = $this->mutateCiphertext($record['record']);
        $recordLines = $lines;
        $recordLines[1] = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        Storage::disk('backups')->put('tampered-record.enc', implode("\n", $recordLines)."\n");

        foreach (['tampered-manifest.enc', 'tampered-record.enc'] as $tampered) {
            $before = User::query()->count();
            $this->assertSame(Command::FAILURE, Artisan::call('sgar:backup:restore', [
                'path' => $tampered,
                '--confirm-isolated' => true,
            ]));
            $this->assertSame($before, User::query()->count());
            $this->assertStringContainsString('could not be restored', Artisan::output());
        }
    }

    public function test_complete_restore_preserves_canonical_and_financial_data_and_invalidates_pending_invitations(): void
    {
        $fixture = $this->fixture();
        $path = $this->createBackup();
        $manifest = $this->manifest($path);
        $this->clearRestoreTables();

        $this->assertSame(Command::SUCCESS, Artisan::call('sgar:backup:restore', [
            'path' => $path,
            '--confirm-isolated' => true,
        ]));

        $restoredAdmin = User::query()->findOrFail($fixture['admin_id']);
        $restoredPending = User::query()->findOrFail($fixture['pending_id']);
        $restoredMosque = Mosque::query()->findOrFail($fixture['mosque_id']);
        $invitation = UserInvitation::query()->where('user_id', $restoredPending->id)->firstOrFail();

        $this->assertSame(45, DB::table('permissions')->count());
        $this->assertSame(3, DB::table('roles')->count());
        $this->assertTrue($restoredAdmin->hasRole('admin'));
        $this->assertSame($restoredAdmin->id, $restoredMosque->admin_id);
        $this->assertDatabaseHas('mosque_user', [
            'mosque_id' => $restoredMosque->id,
            'user_id' => $restoredAdmin->id,
            'membership_type' => MosqueMembershipType::Administrator->value,
        ]);
        $this->assertTrue($restoredAdmin->canAdministerMosque($restoredMosque));
        $this->assertSame(AccountStatus::PendingEmail, $restoredPending->status);
        $this->assertFalse(Hash::check('original-password', $restoredPending->password));
        $this->assertNull($restoredPending->remember_token);
        $this->assertNotSame($fixture['old_token_hash'], $invitation->token_hash);
        $this->assertNull($invitation->accepted_at);
        $this->assertTrue($invitation->expires_at->isPast());
        $this->assertDatabaseHas('donations', [
            'id' => $fixture['donation_id'],
            'currency' => 'USD',
            'amount' => 125.75,
        ]);
        $this->assertSame($manifest['row_counts']['audit_logs'], AuditLog::query()->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'fixture.audit']);

        try {
            app(UserInvitationService::class)->validInvitation($fixture['old_token']);
            $this->fail('A restored pre-backup invitation link remained valid.');
        } catch (InvitationException) {
            $this->addToAssertionCount(1);
        }

        $result = app(UserInvitationService::class)->resend(
            $restoredPending,
            User::query()->findOrFail($fixture['superadmin_id']),
        );
        $this->assertNotSame($fixture['old_token'], $result['token']);
        $this->assertSame(hash('sha256', $result['token']), $result['invitation']->fresh()->token_hash);
        $this->assertTrue($result['invitation']->fresh()->expires_at->isFuture());
    }

    public function test_restore_is_atomic_and_rolls_back_every_table_on_insert_error(): void
    {
        $this->fixture();
        $path = $this->createBackup();
        $this->clearRestoreTables();
        DB::unprepared("CREATE TRIGGER fail_donation_restore BEFORE INSERT ON donations BEGIN SELECT RAISE(ABORT, 'forced restore failure'); END");

        try {
            $this->assertSame(Command::FAILURE, Artisan::call('sgar:backup:restore', [
                'path' => $path,
                '--confirm-isolated' => true,
            ]));
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_donation_restore');
        }

        foreach (config('backup.tables') as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Table {$table} was only partially rolled back.");
        }
        $this->assertStringNotContainsString('forced restore failure', Artisan::output());
    }

    public function test_legacy_v1_archives_and_secret_bearing_errors_are_rejected_safely(): void
    {
        Storage::disk('backups')->put('legacy-v1.enc', json_encode([
            'format' => BackupArchiveService::FORMAT,
            'version' => 1,
            'created_at' => now()->toIso8601String(),
            'password' => 'must-not-be-displayed',
        ], JSON_THROW_ON_ERROR)."\n");

        $this->assertSame(Command::FAILURE, Artisan::call('sgar:backup:verify', ['path' => 'legacy-v1.enc']));
        $this->assertStringNotContainsString('must-not-be-displayed', Artisan::output());
    }

    /** @return array<string, int|string> */
    private function fixture(): array
    {
        $superadmin = User::factory()->create(['email' => 'backup-superadmin@example.com']);
        $superadmin->assignRole('superadmin');
        $admin = User::factory()->create(['email' => 'backup-admin@example.com']);
        $admin->assignRole('admin');
        $pending = User::factory()->pendingEmail()->create([
            'email' => 'pending-restore@example.com',
            'password' => 'original-password',
        ]);
        $originalPasswordHash = $pending->getRawOriginal('password');
        $mosque = Mosque::query()->create([
            'code' => 'BACKUP-034A',
            'name' => 'Canonical backup mosque',
            'address' => 'Conakry',
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Ratoma',
            'status' => 'active',
            'infrastructures' => ['classrooms' => 2],
            'admin_id' => $admin->id,
        ]);
        MosqueMembership::query()->create([
            'mosque_id' => $mosque->id,
            'user_id' => $admin->id,
            'membership_type' => MosqueMembershipType::Administrator,
            'assigned_by' => $superadmin->id,
        ]);
        $oldToken = 'restored-invitation-token-that-must-expire';
        $invitation = UserInvitation::query()->create([
            'user_id' => $pending->id,
            'invited_by' => $superadmin->id,
            'token_hash' => hash('sha256', $oldToken),
            'expires_at' => now()->addDay(),
        ]);
        $donation = Donation::query()->create([
            'mosque_id' => $mosque->id,
            'receipt_number' => 'BACKUP-USD-034A',
            'contribution_type' => 'donation',
            'amount' => 125.75,
            'currency' => 'USD',
            'payment_method' => 'bank_transfer',
            'received_at' => now(),
            'status' => 'validated',
            'is_anonymous' => true,
            'created_by' => $admin->id,
            'validated_by' => $admin->id,
            'validated_at' => now(),
        ]);
        AuditLog::query()->create([
            'actor_id' => $superadmin->id,
            'event' => 'fixture.audit',
            'metadata' => ['currency' => 'USD', 'amount' => 125.75],
        ]);

        return [
            'superadmin_id' => $superadmin->id,
            'admin_id' => $admin->id,
            'pending_id' => $pending->id,
            'mosque_id' => $mosque->id,
            'donation_id' => $donation->id,
            'old_token' => $oldToken,
            'old_token_hash' => $invitation->token_hash,
            'original_password_hash' => $originalPasswordHash,
        ];
    }

    private function createBackup(): string
    {
        $before = Storage::disk('backups')->allFiles();
        $this->assertSame(Command::SUCCESS, Artisan::call('sgar:backup:create'));
        $created = array_values(array_diff(Storage::disk('backups')->allFiles(), $before));
        $this->assertCount(1, $created);

        return $created[0];
    }

    /** @return array<string, mixed> */
    private function manifest(string $path): array
    {
        $header = json_decode($this->lines($path)[0], true, 512, JSON_THROW_ON_ERROR);

        return json_decode(Crypt::decryptString($header['manifest']), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function lines(string $path): array
    {
        return explode("\n", trim(Storage::disk('backups')->get($path)));
    }

    private function mutateCiphertext(string $ciphertext): string
    {
        $position = intdiv(strlen($ciphertext), 2);
        $ciphertext[$position] = $ciphertext[$position] === 'A' ? 'B' : 'A';

        return $ciphertext;
    }

    private function clearRestoreTables(): void
    {
        foreach (array_reverse(config('backup.tables')) as $table) {
            DB::table($table)->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
