<?php

namespace Tests\Feature\Reports;

use App\Enums\MosqueMembershipType;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\Mosque;
use App\Models\MosqueMembership;
use App\Models\User;
use App\Services\BackupRestorePreparer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DataExportBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_exports_only_their_own_mosque_data(): void
    {
        [$admin, $mosque] = $this->adminAndMosque('own');
        [, $otherMosque] = $this->adminAndMosque('other');
        $this->donation($mosque, $admin, 'OWN-REF');
        $this->donation($otherMosque, User::query()->findOrFail($otherMosque->admin_id), 'OTHER-REF');

        $content = $this->actingAs($admin)->get(route('admin.reports.export', [
            'type' => 'donations', 'format' => 'csv',
        ]))->assertOk()->streamedContent();

        $this->assertStringContainsString('OWN-REF', $content);
        $this->assertStringNotContainsString('OTHER-REF', $content);
    }

    public function test_admin_cannot_export_another_mosque(): void
    {
        [$admin] = $this->adminAndMosque('one');
        [, $otherMosque] = $this->adminAndMosque('two');

        $this->actingAs($admin)->get(route('admin.reports.export', [
            'type' => 'donations', 'format' => 'csv', 'mosque_id' => $otherMosque->id,
        ]))->assertForbidden();
    }

    public function test_user_without_report_permission_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $this->actingAs($user)->get(route('admin.reports.export', [
            'type' => 'donations', 'format' => 'csv',
        ]))->assertForbidden();
    }

    public function test_period_filters_are_applied(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $this->donation($mosque, $admin, 'IN-PERIOD', '2026-08-09 10:00:00');
        $this->donation($mosque, $admin, 'OUT-PERIOD', '2026-07-01 10:00:00');

        $content = $this->actingAs($admin)->get(route('admin.reports.export', [
            'type' => 'donations', 'format' => 'csv', 'from' => '2026-08-01', 'to' => '2026-08-31',
        ]))->assertOk()->streamedContent();

        $this->assertStringContainsString('IN-PERIOD', $content);
        $this->assertStringNotContainsString('OUT-PERIOD', $content);
    }

    public function test_consolidated_totals_keep_currencies_separate(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $this->donation($mosque, $admin, 'GNF-REF', currency: 'GNF', amount: 100000);
        $this->donation($mosque, $admin, 'USD-REF', currency: 'USD', amount: 20);

        $content = $this->actingAs($admin)->get(route('admin.reports.export', [
            'type' => 'consolidated', 'format' => 'csv',
        ]))->assertOk()->streamedContent();

        $this->assertMatchesRegularExpression('/Dons;Recettes;GNF;100000(?:\.0+)?/', $content);
        $this->assertMatchesRegularExpression('/Dons;Recettes;USD;20(?:\.0+)?/', $content);
    }

    public function test_csv_has_localized_headers_and_neutralizes_formula_cells(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $this->donation($mosque, $admin, 'SAFE-REF', contributionType: '=HYPERLINK("https://invalid")');

        $response = $this->actingAs($admin)->get(route('admin.reports.export', [
            'type' => 'donations', 'format' => 'csv',
        ]))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Mosquée;Référence;Catégorie;Date;Statut;Devise;Montant', $content);
        $this->assertStringContainsString("'=HYPERLINK", $content);
    }

    public function test_pdf_is_generated_with_the_expected_content_type(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $this->donation($mosque, $admin, 'PDF-REF');

        $response = $this->actingAs($admin)->get(route('admin.reports.export', [
            'type' => 'donations', 'format' => 'pdf',
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_export_request_is_audited(): void
    {
        [$admin] = $this->adminAndMosque();

        $this->actingAs($admin)->get(route('admin.reports.export', [
            'type' => 'expenses', 'format' => 'csv', 'currency' => 'GNF',
        ]))->assertOk()->streamedContent();

        $log = AuditLog::query()->where('event', 'report.export.requested')->firstOrFail();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('GNF', $log->metadata['filters']['currency']);
    }

    public function test_backup_uses_private_storage_and_excludes_credentials(): void
    {
        Storage::fake('backups');
        User::factory()->create(['email' => 'backup@example.com', 'password' => 'secret-password']);

        $backupRoot = str_replace('\\', '/', config('filesystems.disks.backups.root'));
        $this->assertStringContainsString('storage/app/private', $backupRoot);
        $this->assertStringNotContainsString('public', $backupRoot);

        $this->assertSame(Command::SUCCESS, Artisan::call('sgar:backup:create'));

        $files = Storage::disk('backups')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('sgar-data-', $files[0]);
        $contents = Storage::disk('backups')->get($files[0]);
        $this->assertStringNotContainsString('secret-password', $contents);
        $this->assertStringNotContainsString('APP_KEY', $contents);
        $this->assertDatabaseHas('audit_logs', ['event' => 'backup.created']);

        $encryptedUser = collect(explode("\n", trim($contents)))
            ->map(fn (string $line) => json_decode($line, true))
            ->firstWhere('table', 'users');
        $userPayload = json_decode(Crypt::decryptString($encryptedUser['payload']), true);
        $this->assertArrayNotHasKey('password', $userPayload);
        $this->assertArrayNotHasKey('remember_token', $userPayload);
    }

    public function test_backup_error_is_safe_and_audited(): void
    {
        $disk = Mockery::mock();
        $disk->shouldReceive('put')->once()->andThrow(new RuntimeException('secret database password'));
        Storage::shouldReceive('disk')->with('backups')->once()->andReturn($disk);

        $this->assertSame(Command::FAILURE, Artisan::call('sgar:backup:create'));
        $this->assertStringNotContainsString('secret database password', Artisan::output());
        $this->assertDatabaseHas('audit_logs', ['event' => 'backup.failed']);
        $this->assertStringNotContainsString('secret database password', AuditLog::query()->latest('id')->first()->toJson());
    }

    public function test_backed_up_user_can_be_prepared_for_valid_empty_database_restore(): void
    {
        Storage::fake('backups');
        $user = User::factory()->create([
            'email' => 'restore@example.com',
            'password' => 'original-password',
        ]);

        $this->assertSame(Command::SUCCESS, Artisan::call('sgar:backup:create'));
        $path = Storage::disk('backups')->allFiles()[0];
        $encryptedUser = collect(explode("\n", trim(Storage::disk('backups')->get($path))))
            ->map(fn (string $line) => json_decode($line, true))
            ->firstWhere('table', 'users');
        $payload = json_decode(Crypt::decryptString($encryptedUser['payload']), true);

        $this->assertArrayNotHasKey('password', $payload);
        $prepared = app(BackupRestorePreparer::class)->user($payload);
        $this->assertNotEmpty($prepared['password']);
        $this->assertFalse(Hash::check('original-password', $prepared['password']));
        $this->assertNull($prepared['remember_token']);
        $this->assertNotSame(
            $prepared['password'],
            app(BackupRestorePreparer::class)->user($payload)['password'],
        );

        DB::table('users')->where('id', $user->id)->delete();
        DB::table('users')->insert($prepared);

        $restored = DB::table('users')->where('email', 'restore@example.com')->first();
        $this->assertNotNull($restored);
        $this->assertNotNull($restored->password);
        $this->assertFalse(Hash::check('original-password', $restored->password));
    }

    private function adminAndMosque(string $suffix = 'main'): array
    {
        $admin = User::factory()->create(['email' => $suffix.'-reports@example.com']);
        $admin->assignRole('admin');
        $mosque = Mosque::query()->create([
            'code' => 'REP-'.strtoupper($suffix),
            'name' => 'Mosquée '.$suffix,
            'address' => 'Conakry',
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Ratoma',
            'status' => 'active',
            'infrastructures' => [],
            'admin_id' => $admin->id,
        ]);
        MosqueMembership::query()->create([
            'mosque_id' => $mosque->id,
            'user_id' => $admin->id,
            'membership_type' => MosqueMembershipType::Administrator,
        ]);

        return [$admin, $mosque];
    }

    private function donation(
        Mosque $mosque,
        User $creator,
        string $reference,
        string $receivedAt = '2026-08-09 10:00:00',
        string $currency = 'GNF',
        float $amount = 100000,
        string $contributionType = 'donation',
    ): Donation {
        return Donation::query()->create([
            'mosque_id' => $mosque->id,
            'receipt_number' => $reference,
            'contribution_type' => $contributionType,
            'amount' => $amount,
            'currency' => $currency,
            'payment_method' => 'cash',
            'received_at' => $receivedAt,
            'status' => 'validated',
            'is_anonymous' => true,
            'created_by' => $creator->id,
            'validated_by' => $creator->id,
            'validated_at' => $receivedAt,
        ]);
    }
}
