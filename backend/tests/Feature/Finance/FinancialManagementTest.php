<?php

namespace Tests\Feature\Finance;

use App\Enums\MosqueMembershipType;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\Expense;
use App\Models\Mosque;
use App\Models\MosqueMembership;
use App\Models\Subsidy;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_register_and_validate_a_subsidy(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $response = $this->actingAs($admin)->postJson(route('admin.finances.subsidies.store'), $this->subsidyPayload($mosque))->assertCreated();
        $this->actingAs($admin)->postJson(route('admin.finances.subsidies.validate', $response->json('id')))->assertOk()->assertJsonPath('status', 'validated');
    }

    public function test_expense_requires_a_supporting_document(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $payload = $this->expensePayload($mosque);
        unset($payload['supporting_document']);
        $this->actingAs($admin)->postJson(route('admin.finances.expenses.store'), $payload)->assertUnprocessable();
    }

    public function test_admin_cannot_manage_another_mosques_finances(): void
    {
        [$admin] = $this->adminAndMosque('one');
        [, $otherMosque] = $this->adminAndMosque('two');
        $this->actingAs($admin)->postJson(route('admin.finances.subsidies.store'), $this->subsidyPayload($otherMosque))->assertForbidden();
    }

    public function test_expense_cannot_exceed_available_funds(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $expense = $this->actingAs($admin)->postJson(route('admin.finances.expenses.store'), $this->expensePayload($mosque))->assertCreated();
        $this->actingAs($admin)->postJson(route('admin.finances.expenses.validate', $expense->json('id')))->assertUnprocessable();
    }

    public function test_validated_income_funds_an_expense_only_once(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        Donation::query()->create(['mosque_id' => $mosque->id, 'receipt_number' => 'DON-1', 'contribution_type' => 'donation', 'amount' => 200000, 'currency' => 'GNF', 'payment_method' => 'cash', 'received_at' => now(), 'status' => 'validated', 'is_anonymous' => true, 'created_by' => $admin->id, 'validated_by' => $admin->id, 'validated_at' => now()]);
        $expense = $this->actingAs($admin)->postJson(route('admin.finances.expenses.store'), $this->expensePayload($mosque))->assertCreated();
        $route = route('admin.finances.expenses.validate', $expense->json('id'));
        $this->actingAs($admin)->postJson($route)->assertOk();
        $this->actingAs($admin)->postJson($route)->assertUnprocessable();
    }

    public function test_report_aggregates_validated_resources_and_expenses(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        Subsidy::query()->create(['mosque_id' => $mosque->id, 'reference_number' => 'SUB-1', 'source' => 'SGAR', 'amount' => 300000, 'currency' => 'GNF', 'received_at' => now(), 'status' => 'validated', 'created_by' => $admin->id, 'validated_by' => $admin->id, 'validated_at' => now()]);
        Expense::query()->create($this->expensePayload($mosque) + ['reference_number' => 'EXP-1', 'status' => 'validated', 'created_by' => $admin->id, 'validated_by' => $admin->id, 'validated_at' => now()]);
        $this->actingAs($admin)->getJson(route('admin.finances.report', ['mosque_id' => $mosque->id]))->assertOk()->assertJsonPath('total_resources', 300000)->assertJsonPath('total_expenses', 100000)->assertJsonPath('balance', 200000);
    }

    public function test_financial_records_are_audited_without_sensitive_payloads(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $this->actingAs($admin)->postJson(route('admin.finances.subsidies.store'), $this->subsidyPayload($mosque))->assertCreated();
        $this->actingAs($admin)->postJson(route('admin.finances.expenses.store'), $this->expensePayload($mosque))->assertCreated();
        $this->assertTrue(AuditLog::query()->where('event', 'finance.subsidy.created')->exists());
        $this->assertTrue(AuditLog::query()->where('event', 'finance.expense.created')->exists());
    }

    public function test_user_without_finance_permission_is_forbidden(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');
        $this->actingAs($user)->getJson(route('admin.finances.report'))->assertForbidden();
    }

    private function adminAndMosque(string $suffix = 'main'): array
    {
        $admin = User::factory()->create(['email' => $suffix.'-finance@example.com']);
        $admin->assignRole('admin');
        $mosque = Mosque::query()->create(['code' => 'FIN-'.strtoupper($suffix), 'name' => 'Mosquée '.$suffix, 'address' => 'Conakry', 'region' => 'Conakry', 'prefecture' => 'Conakry', 'commune' => 'Ratoma', 'status' => 'active', 'infrastructures' => [], 'admin_id' => $admin->id]);
        MosqueMembership::query()->create(['mosque_id' => $mosque->id, 'user_id' => $admin->id, 'membership_type' => MosqueMembershipType::Administrator]);

        return [$admin, $mosque];
    }

    private function subsidyPayload(Mosque $mosque): array
    {
        return ['mosque_id' => $mosque->id, 'source' => 'SGAR', 'amount' => 300000, 'currency' => 'GNF', 'received_at' => now()->subMinute()->toDateTimeString(), 'purpose' => 'Appui annuel'];
    }

    private function expensePayload(Mosque $mosque): array
    {
        return ['mosque_id' => $mosque->id, 'category' => 'maintenance', 'amount' => 100000, 'currency' => 'GNF', 'spent_at' => now()->subMinute()->toDateTimeString(), 'purpose' => 'Entretien', 'supporting_document' => 'facture.pdf'];
    }
}
