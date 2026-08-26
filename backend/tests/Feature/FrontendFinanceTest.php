<?php

namespace Tests\Feature;

use App\Enums\MosqueMembershipType;
use App\Models\Donation;
use App\Models\Expense;
use App\Models\Mosque;
use App\Models\MosqueMembership;
use App\Models\Subsidy;
use App\Models\User;
use App\Models\WaqfAsset;
use App\Models\WaqfExpense;
use App\Models\WaqfRevenue;
use App\Models\ZakatBeneficiary;
use App\Models\ZakatCollection;
use App\Models\ZakatDistribution;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendFinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_financial_navigation_is_visible_only_with_exact_permissions(): void
    {
        foreach (['superadmin', 'admin'] as $role) {
            $response = $this->actingAs($this->actor($role))->get(route('dashboard'))->assertOk();
            foreach (['admin.donations.index', 'admin.finances.report', 'admin.zakat.collections.index', 'admin.waqf.assets.index', 'admin.reports.index'] as $route) {
                $response->assertSee(route($route), false);
            }
        }

        $response = $this->actingAs($this->actor('user'))->get(route('dashboard'))->assertOk();
        foreach (['admin.donations.index', 'admin.finances.report', 'admin.zakat.collections.index', 'admin.waqf.assets.index', 'admin.reports.index'] as $route) {
            $response->assertDontSee(route($route), false);
        }
    }

    public function test_secondary_admin_html_pages_are_isolated_to_canonical_mosques(): void
    {
        $admin = $this->actor('admin');
        $local = $this->mosque('FIN-LOCAL');
        $outside = $this->mosque('FIN-OUTSIDE');
        $this->membership($admin, $local);
        $this->donation($local, $admin, 'LOCAL-RECEIPT', '100.00');
        $this->donation($outside, $admin, 'OUTSIDE-RECEIPT', '900.00');
        $this->collection($local, $admin, 'LOCAL-ZAKAT', '75.00');
        $this->collection($outside, $admin, 'OUTSIDE-ZAKAT', '800.00');
        $this->asset($local, $admin, 'LOCAL-WAQF');
        $this->asset($outside, $admin, 'OUTSIDE-WAQF');

        $this->actingAs($admin)->get(route('admin.donations.index'))->assertOk()->assertSee('LOCAL-RECEIPT')->assertDontSee('OUTSIDE-RECEIPT');
        $this->actingAs($admin)->get(route('admin.zakat.collections.index'))->assertOk()->assertSee('LOCAL-ZAKAT')->assertDontSee('OUTSIDE-ZAKAT');
        $this->actingAs($admin)->get(route('admin.waqf.assets.index'))->assertOk()->assertSee('LOCAL-WAQF')->assertDontSee('OUTSIDE-WAQF');
        $this->actingAs($admin)->get(route('admin.finances.report', ['currency' => 'GNF']))->assertOk()->assertSee($local->name)->assertDontSee($outside->name);
    }

    public function test_partial_authorities_do_not_gain_financial_scope_from_the_frontend(): void
    {
        $mosque = $this->mosque('PARTIAL');
        $roleOnly = $this->actor('admin');
        $membershipOnly = $this->actor('user');
        $membershipOnly->givePermissionTo(['contributions.view', 'finances.view', 'zakat.view', 'waqf.view', 'reports.view']);
        $this->membership($membershipOnly, $mosque);
        $this->donation($mosque, $roleOnly, 'HIDDEN-RECEIPT', '100.00');

        $this->actingAs($roleOnly)->get(route('admin.donations.index'))->assertOk()->assertDontSee('HIDDEN-RECEIPT');
        $this->actingAs($membershipOnly)->get(route('admin.donations.index'))->assertOk()->assertDontSee('HIDDEN-RECEIPT');
        $this->actingAs($membershipOnly)->get(route('admin.finances.report', ['mosque_id' => $mosque->id]))->assertForbidden();
        $this->actingAs($membershipOnly)->get(route('admin.reports.index'))->assertForbidden();
    }

    public function test_anonymous_contribution_form_preserves_anonymity_and_uses_text_receipt(): void
    {
        $admin = $this->actor('admin');
        $mosque = $this->mosque('ANON');
        $this->membership($admin, $mosque);

        $this->actingAs($admin)->get(route('admin.donations.create'))
            ->assertOk()->assertSee('name="is_anonymous"', false)->assertDontSee('type="file"', false);

        $this->actingAs($admin)->post(route('admin.donations.store'), [
            'mosque_id' => $mosque->id,
            'contribution_type' => 'donation',
            'amount' => '123.45',
            'currency' => 'USD',
            'payment_method' => 'cash',
            'received_at' => now()->format('Y-m-d H:i:s'),
            'is_anonymous' => '1',
            'donor_name' => 'Must disappear',
        ])->assertRedirect();

        $donation = Donation::query()->firstOrFail();
        $this->assertTrue($donation->is_anonymous);
        $this->assertNull($donation->donor_name);
        $this->actingAs($admin)->get(route('admin.donations.show', $donation))
            ->assertOk()->assertSee(__('Anonymous'))->assertSee($donation->receipt_number)
            ->assertSee(__('This receipt is an application reference, not a stored file.'))->assertDontSee('type="file"', false);
    }

    public function test_finance_summary_keeps_currencies_separate_and_legacy_references_text_only(): void
    {
        $admin = $this->actor('admin');
        $mosque = $this->mosque('CURRENCY');
        $this->membership($admin, $mosque);
        $this->donation($mosque, $admin, 'GNF-DON', '100.00', 'GNF', 'validated');
        $this->donation($mosque, $admin, 'USD-DON', '999.00', 'USD', 'validated');
        $this->subsidy($mosque, $admin, 'SUB-GNF', '50.00', 'GNF');
        $this->expense($mosque, $admin, 'EXP-GNF', '25.00', 'GNF');

        $response = $this->actingAs($admin)->get(route('admin.finances.report', ['currency' => 'GNF']))
            ->assertOk()->assertSee('GNF')->assertSee('INV-LEGACY')->assertSee('DOC-LEGACY')->assertDontSee('type="file"', false);
        $response->assertSee('SUB-GNF')->assertSee('EXP-GNF')->assertDontSee('USD-DON');

        $this->actingAs($admin)->getJson(route('admin.finances.report', ['currency' => 'GNF']))
            ->assertOk()->assertJsonPath('total_resources', 150)->assertJsonPath('total_expenses', 25)->assertJsonPath('balance', 125);
    }

    public function test_zakat_and_waqf_pages_show_workflows_balances_and_text_references(): void
    {
        $admin = $this->actor('admin');
        $mosque = $this->mosque('WORKFLOWS');
        $this->membership($admin, $mosque);
        $collection = $this->collection($mosque, $admin, 'ZAK-UI', '300.00');
        $beneficiary = $this->beneficiary($mosque, $admin, 'BEN-UI');
        $this->distribution($mosque, $beneficiary, $admin, 'DIS-UI', '50.00');
        $asset = $this->asset($mosque, $admin, 'WAQF-UI');
        $this->revenue($asset, $admin, 'REV-UI', '200.00');
        $this->waqfExpense($asset, $admin, 'WEX-UI', '30.00');

        $this->actingAs($admin)->get(route('admin.zakat.collections.index'))
            ->assertOk()->assertSee($collection->receipt_number)->assertSee($beneficiary->beneficiary_number)->assertSee('DIS-UI')
            ->assertSee('USD')->assertDontSee('type="file"', false);
        $this->actingAs($admin)->get(route('admin.waqf.assets.index'))
            ->assertOk()->assertSee('WAQF-UI')->assertSee('REV-UI')->assertSee('WEX-UI')
            ->assertSee(__('Waqf balance'))->assertSee('170')->assertDontSee('type="file"', false);
    }

    public function test_report_page_downloads_real_files_and_rejects_outside_scope(): void
    {
        $admin = $this->actor('admin');
        $local = $this->mosque('EXPORT-LOCAL');
        $outside = $this->mosque('EXPORT-OUTSIDE');
        $this->membership($admin, $local);
        $this->donation($local, $admin, 'EXPORT-REF', '100.00', 'GNF', 'validated');

        $this->actingAs($admin)->get(route('admin.reports.index'))
            ->assertOk()->assertSee(__('Download CSV'))->assertSee(__('Download PDF'))->assertDontSee('type="file"', false);
        $this->actingAs($admin)->get(route('admin.reports.export', [
            'type' => 'donations', 'format' => 'csv', 'mosque_id' => $local->id,
        ]))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->actingAs($admin)->get(route('admin.reports.export', [
            'type' => 'donations', 'format' => 'pdf', 'mosque_id' => $local->id,
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($admin)->get(route('admin.reports.export', [
            'type' => 'donations', 'format' => 'csv', 'mosque_id' => $outside->id,
        ]))->assertForbidden();
    }

    public function test_arabic_financial_views_are_rtl_and_json_contracts_remain_unchanged(): void
    {
        $admin = $this->actor('admin');
        $mosque = $this->mosque('RTL');
        $this->membership($admin, $mosque);
        $donation = $this->donation($mosque, $admin, 'JSON-DON', '10.00');

        $this->actingAs($admin)->withSession(['locale' => 'ar'])->get(route('admin.donations.index'))
            ->assertOk()->assertSee('dir="rtl"', false);
        $this->actingAs($admin)->getJson(route('admin.donations.show', $donation))
            ->assertOk()->assertJsonPath('receipt_number', 'JSON-DON')->assertJsonPath('amount', '10.00');
        $this->actingAs($admin)->getJson(route('admin.waqf.assets.index'))->assertOk()->assertJsonStructure(['data']);
        $this->actingAs($admin)->getJson(route('admin.zakat.collections.index'))->assertOk()->assertJsonStructure(['data']);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function mosque(string $code): Mosque
    {
        return Mosque::query()->create(['code' => $code, 'name' => 'Mosque '.$code, 'region' => 'Conakry', 'prefecture' => 'Conakry', 'commune' => 'Ratoma', 'status' => 'active']);
    }

    private function membership(User $user, Mosque $mosque): void
    {
        MosqueMembership::query()->create(['user_id' => $user->id, 'mosque_id' => $mosque->id, 'membership_type' => MosqueMembershipType::Administrator]);
    }

    private function donation(Mosque $mosque, User $user, string $reference, string $amount, string $currency = 'GNF', string $status = 'pending'): Donation
    {
        return Donation::query()->create(['mosque_id' => $mosque->id, 'receipt_number' => $reference, 'contribution_type' => 'donation', 'amount' => $amount, 'currency' => $currency, 'payment_method' => 'cash', 'received_at' => now(), 'status' => $status, 'is_anonymous' => true, 'created_by' => $user->id]);
    }

    private function subsidy(Mosque $mosque, User $user, string $reference, string $amount, string $currency): Subsidy
    {
        return Subsidy::query()->create(['mosque_id' => $mosque->id, 'reference_number' => $reference, 'source' => 'SGAR', 'amount' => $amount, 'currency' => $currency, 'received_at' => now(), 'supporting_document' => 'LEGACY-SUB', 'status' => 'validated', 'created_by' => $user->id]);
    }

    private function expense(Mosque $mosque, User $user, string $reference, string $amount, string $currency): Expense
    {
        return Expense::query()->create(['mosque_id' => $mosque->id, 'reference_number' => $reference, 'category' => 'maintenance', 'amount' => $amount, 'currency' => $currency, 'spent_at' => now(), 'purpose' => 'Maintenance', 'invoice_number' => 'INV-LEGACY', 'supporting_document' => 'DOC-LEGACY', 'status' => 'validated', 'created_by' => $user->id]);
    }

    private function collection(Mosque $mosque, User $user, string $reference, string $amount): ZakatCollection
    {
        return ZakatCollection::query()->create(['mosque_id' => $mosque->id, 'receipt_number' => $reference, 'category' => 'maal', 'amount' => $amount, 'currency' => 'USD', 'payment_method' => 'cash', 'collected_at' => now(), 'status' => 'validated', 'is_anonymous' => true, 'created_by' => $user->id]);
    }

    private function beneficiary(Mosque $mosque, User $user, string $number): ZakatBeneficiary
    {
        return ZakatBeneficiary::query()->create(['mosque_id' => $mosque->id, 'beneficiary_number' => $number, 'name' => 'Protected beneficiary', 'category' => 'poor', 'eligibility_reason' => 'Verified', 'status' => 'active', 'verified_at' => today(), 'verified_by' => $user->id]);
    }

    private function distribution(Mosque $mosque, ZakatBeneficiary $beneficiary, User $user, string $reference, string $amount): ZakatDistribution
    {
        return ZakatDistribution::query()->create(['mosque_id' => $mosque->id, 'zakat_beneficiary_id' => $beneficiary->id, 'reference_number' => $reference, 'category' => 'maal', 'amount' => $amount, 'currency' => 'USD', 'payment_method' => 'cash', 'distributed_at' => now(), 'status' => 'pending', 'purpose' => 'Assistance', 'supporting_document' => 'ZAK-LEGACY', 'created_by' => $user->id]);
    }

    private function asset(Mosque $mosque, User $user, string $number): WaqfAsset
    {
        return WaqfAsset::query()->create(['mosque_id' => $mosque->id, 'registration_number' => $number, 'name' => $number, 'type' => 'shop', 'estimated_value' => '500.00', 'currency' => 'USD', 'dedicated_at' => today(), 'deed_reference' => 'DEED-TEXT', 'status' => 'active', 'created_by' => $user->id]);
    }

    private function revenue(WaqfAsset $asset, User $user, string $reference, string $amount): WaqfRevenue
    {
        return WaqfRevenue::query()->create(['waqf_asset_id' => $asset->id, 'receipt_number' => $reference, 'source' => 'Rent', 'amount' => $amount, 'currency' => 'USD', 'received_at' => now(), 'payment_method' => 'cash', 'status' => 'validated', 'created_by' => $user->id]);
    }

    private function waqfExpense(WaqfAsset $asset, User $user, string $reference, string $amount): WaqfExpense
    {
        return WaqfExpense::query()->create(['waqf_asset_id' => $asset->id, 'reference_number' => $reference, 'category' => 'maintenance', 'amount' => $amount, 'currency' => 'USD', 'spent_at' => now(), 'purpose' => 'Repair', 'supporting_document' => 'WAQF-LEGACY', 'status' => 'validated', 'created_by' => $user->id]);
    }
}
