<?php

namespace Tests\Feature\Waqf;

use App\Models\AuditLog;
use App\Models\Mosque;
use App\Models\User;
use App\Models\WaqfAsset;
use App\Models\WaqfExpense;
use App\Models\WaqfRevenue;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaqfManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_register_a_waqf_asset(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $response = $this->actingAs($admin)->postJson(route('admin.waqf.assets.store'), $this->assetPayload($mosque));

        $response->assertCreated()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('currency', 'GNF');
        $this->assertStringStartsWith('WAQ-', $response->json('registration_number'));
    }

    public function test_invalid_waqf_amounts_are_rejected(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $asset = $this->asset($mosque, $admin);
        $payload = $this->revenuePayload($asset);
        $payload['amount'] = 0;

        $this->actingAs($admin)->postJson(route('admin.waqf.revenues.store'), $payload)->assertUnprocessable();
    }

    public function test_admin_cannot_manage_another_mosques_waqf(): void
    {
        [$admin] = $this->adminAndMosque('main');
        [$otherAdmin, $otherMosque] = $this->adminAndMosque('other');
        $otherAsset = $this->asset($otherMosque, $otherAdmin);

        $this->actingAs($admin)->postJson(route('admin.waqf.assets.store'), $this->assetPayload($otherMosque))->assertForbidden();
        $this->actingAs($admin)->postJson(route('admin.waqf.revenues.store'), $this->revenuePayload($otherAsset))->assertForbidden();
    }

    public function test_inactive_asset_cannot_receive_transactions(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $asset = $this->asset($mosque, $admin, 'inactive');

        $this->actingAs($admin)->postJson(route('admin.waqf.revenues.store'), $this->revenuePayload($asset))->assertUnprocessable();
        $this->actingAs($admin)->postJson(route('admin.waqf.expenses.store'), $this->expensePayload($asset))->assertUnprocessable();
    }

    public function test_expense_cannot_exceed_validated_revenue(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $asset = $this->asset($mosque, $admin);
        $expense = $this->actingAs($admin)->postJson(route('admin.waqf.expenses.store'), $this->expensePayload($asset))->assertCreated();

        $this->actingAs($admin)->postJson(route('admin.waqf.expenses.validate', $expense->json('id')))->assertUnprocessable();
    }

    public function test_revenue_in_another_currency_cannot_fund_expense(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $asset = $this->asset($mosque, $admin);
        WaqfRevenue::query()->create($this->revenueRecord($asset, $admin) + [
            'currency' => 'USD',
            'status' => 'validated',
            'validated_by' => $admin->id,
            'validated_at' => now(),
        ]);
        $expense = $this->actingAs($admin)->postJson(route('admin.waqf.expenses.store'), $this->expensePayload($asset))->assertCreated();

        $this->actingAs($admin)->postJson(route('admin.waqf.expenses.validate', $expense->json('id')))->assertUnprocessable();
    }

    public function test_validated_revenue_can_fund_an_expense_only_once(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $asset = $this->asset($mosque, $admin);
        WaqfRevenue::query()->create($this->revenueRecord($asset, $admin) + [
            'status' => 'validated',
            'validated_by' => $admin->id,
            'validated_at' => now(),
        ]);
        $expense = $this->actingAs($admin)->postJson(route('admin.waqf.expenses.store'), $this->expensePayload($asset))->assertCreated();
        $route = route('admin.waqf.expenses.validate', $expense->json('id'));

        $this->actingAs($admin)->postJson($route)->assertOk()->assertJsonPath('status', 'validated');
        $this->actingAs($admin)->postJson($route)->assertUnprocessable();
    }

    public function test_assets_revenues_and_expenses_are_audited(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $assetResponse = $this->actingAs($admin)->postJson(route('admin.waqf.assets.store'), $this->assetPayload($mosque))->assertCreated();
        $asset = WaqfAsset::query()->findOrFail($assetResponse->json('id'));
        $this->actingAs($admin)->postJson(route('admin.waqf.revenues.store'), $this->revenuePayload($asset))->assertCreated();
        $this->actingAs($admin)->postJson(route('admin.waqf.expenses.store'), $this->expensePayload($asset))->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['event' => 'waqf.asset.created', 'auditable_id' => $asset->id]);
        $this->assertTrue(AuditLog::query()->where('event', 'waqf.revenue.created')->exists());
        $this->assertTrue(AuditLog::query()->where('event', 'waqf.expense.created')->exists());
    }

    private function adminAndMosque(string $suffix = 'main'): array
    {
        $admin = User::factory()->create(['email' => $suffix.'-waqf@example.com']);
        $admin->assignRole('admin');
        $mosque = Mosque::query()->create([
            'code' => 'WQF-'.strtoupper($suffix),
            'name' => 'Mosquée '.$suffix,
            'address' => 'Conakry',
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Ratoma',
            'status' => 'active',
            'infrastructures' => [],
            'admin_id' => $admin->id,
        ]);

        return [$admin, $mosque];
    }

    private function assetPayload(Mosque $mosque): array
    {
        return [
            'mosque_id' => $mosque->id,
            'name' => 'Boutique Waqf',
            'type' => 'shop',
            'address' => 'Conakry',
            'estimated_value' => 50000000,
            'currency' => 'GNF',
            'dedicated_at' => now()->subDay()->toDateString(),
            'deed_reference' => 'ACTE-001',
        ];
    }

    private function asset(Mosque $mosque, User $admin, string $status = 'active'): WaqfAsset
    {
        return WaqfAsset::query()->create($this->assetPayload($mosque) + [
            'registration_number' => 'WAQ-'.strtoupper(uniqid()),
            'status' => $status,
            'created_by' => $admin->id,
        ]);
    }

    private function revenuePayload(WaqfAsset $asset): array
    {
        return [
            'waqf_asset_id' => $asset->id,
            'source' => 'Loyer mensuel',
            'amount' => 100000,
            'currency' => 'GNF',
            'received_at' => now()->subMinute()->toDateTimeString(),
            'payment_method' => 'cash',
        ];
    }

    private function revenueRecord(WaqfAsset $asset, User $admin): array
    {
        return $this->revenuePayload($asset) + [
            'receipt_number' => 'WRV-'.strtoupper(uniqid()),
            'created_by' => $admin->id,
        ];
    }

    private function expensePayload(WaqfAsset $asset): array
    {
        return [
            'waqf_asset_id' => $asset->id,
            'category' => 'maintenance',
            'amount' => 50000,
            'currency' => 'GNF',
            'spent_at' => now()->toDateTimeString(),
            'purpose' => 'Réparation de la toiture',
            'supporting_document' => 'facture-001.pdf',
        ];
    }
}
