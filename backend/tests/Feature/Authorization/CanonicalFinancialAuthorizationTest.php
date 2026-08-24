<?php

namespace Tests\Feature\Authorization;

use App\Enums\MosqueMembershipType;
use App\Models\Donation;
use App\Models\Expense;
use App\Models\Faithful;
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
use App\Services\ReportExportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CanonicalFinancialAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_secondary_canonical_administrator_can_list_validate_aggregate_and_export_local_finances(): void
    {
        $primary = $this->actor('admin');
        $secondary = $this->actor('admin');
        $other = $this->actor('admin');
        $localMosque = $this->mosque('LOCAL', $primary);
        $otherMosque = $this->mosque('OTHER', $other);
        $this->membership($secondary, $localMosque, MosqueMembershipType::Administrator);
        $localDonation = $this->donation($localMosque, $primary, 'LOCAL-DON', 100);
        $outsideDonation = $this->donation($otherMosque, $other, 'OTHER-DON', 900);
        $localSubsidy = $this->subsidy($localMosque, $primary, 'LOCAL-SUB', 300);
        $localAsset = $this->asset($localMosque, $primary, 'LOCAL-WAQF');
        $outsideAsset = $this->asset($otherMosque, $other, 'OTHER-WAQF');
        $localRevenue = $this->revenue($localAsset, $primary, 'LOCAL-WRV', 200);
        $localCollection = $this->collection($localMosque, $primary, 'LOCAL-ZAK', 75);
        $outsideCollection = $this->collection($otherMosque, $other, 'OTHER-ZAK', 800);

        $this->actingAs($secondary)->getJson(route('admin.donations.index'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $localDonation->id)
            ->assertJsonMissing(['id' => $outsideDonation->id]);
        $this->actingAs($secondary)->getJson(route('admin.waqf.assets.index'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $localAsset->id)
            ->assertJsonMissing(['id' => $outsideAsset->id]);
        $this->actingAs($secondary)->getJson(route('admin.zakat.collections.index'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $localCollection->id)
            ->assertJsonMissing(['id' => $outsideCollection->id]);

        $this->actingAs($secondary)->postJson(route('admin.donations.validate', $localDonation))->assertOk();
        $this->actingAs($secondary)->postJson(route('admin.finances.subsidies.validate', $localSubsidy))->assertOk();
        $this->actingAs($secondary)->postJson(route('admin.waqf.revenues.validate', $localRevenue))->assertOk();
        $this->actingAs($secondary)->postJson(route('admin.zakat.collections.validate', $localCollection))->assertOk();

        $this->actingAs($secondary)->getJson(route('admin.finances.report', ['mosque_id' => $localMosque->id]))
            ->assertOk()
            ->assertJsonPath('resources.donations', 100)
            ->assertJsonPath('resources.subsidies', 300)
            ->assertJsonPath('resources.waqf', 200)
            ->assertJsonPath('resources.zakat', 75)
            ->assertJsonPath('total_resources', 675);

        $csv = $this->actingAs($secondary)->get(route('admin.reports.export', [
            'type' => 'donations', 'format' => 'csv', 'mosque_id' => $localMosque->id,
        ]))->assertOk()->streamedContent();
        $this->assertStringContainsString('LOCAL-DON', $csv);
        $this->assertStringNotContainsString('OTHER-DON', $csv);
    }

    public function test_superadmin_retains_global_financial_and_export_scope(): void
    {
        $superadmin = $this->actor('superadmin');
        $first = $this->mosque('GLOBAL-A');
        $second = $this->mosque('GLOBAL-B');
        $this->donation($first, $superadmin, 'GLOBAL-A-DON', 100, 'validated');
        $this->donation($second, $superadmin, 'GLOBAL-B-DON', 200, 'validated');
        $this->asset($first, $superadmin, 'GLOBAL-A-WAQF');
        $this->asset($second, $superadmin, 'GLOBAL-B-WAQF');
        $this->collection($first, $superadmin, 'GLOBAL-A-ZAK', 50);
        $this->collection($second, $superadmin, 'GLOBAL-B-ZAK', 60);

        $this->actingAs($superadmin)->getJson(route('admin.donations.index'))->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($superadmin)->getJson(route('admin.waqf.assets.index'))->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($superadmin)->getJson(route('admin.zakat.collections.index'))->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($superadmin)->getJson(route('admin.finances.report'))
            ->assertOk()->assertJsonPath('resources.donations', 300);

        $csv = $this->actingAs($superadmin)->get(route('admin.reports.export', [
            'type' => 'donations', 'format' => 'csv',
        ]))->assertOk()->streamedContent();
        $this->assertStringContainsString('GLOBAL-A-DON', $csv);
        $this->assertStringContainsString('GLOBAL-B-DON', $csv);
    }

    public function test_partial_and_legacy_authorities_cannot_access_a_requested_mosque_or_its_records(): void
    {
        $legacyPrimary = $this->actor('admin');
        $roleOnly = $this->actor('admin');
        $member = $this->actor('admin');
        $membershipWithoutRole = $this->actor('user');
        $directPermissionOnly = User::factory()->create();
        $permissions = ['contributions.view', 'finances.view', 'waqf.view', 'zakat.view', 'reports.view'];
        $membershipWithoutRole->givePermissionTo($permissions);
        $directPermissionOnly->givePermissionTo($permissions);
        $mosque = $this->mosque('DENIED');
        $mosque->forceFill(['admin_id' => $legacyPrimary->id])->save();
        $this->membership($member, $mosque, MosqueMembershipType::Member);
        $this->membership($membershipWithoutRole, $mosque, MosqueMembershipType::Administrator);
        $donation = $this->donation($mosque, $legacyPrimary, 'DENIED-DON', 100);
        $this->asset($mosque, $legacyPrimary, 'DENIED-WAQF');
        $this->collection($mosque, $legacyPrimary, 'DENIED-ZAK', 50);

        foreach ([$legacyPrimary, $roleOnly, $member, $membershipWithoutRole, $directPermissionOnly] as $actor) {
            $this->assertFalse($actor->canAdministerMosque($mosque));
            $this->actingAs($actor)->getJson(route('admin.donations.index'))->assertOk()->assertJsonCount(0, 'data');
            $this->actingAs($actor)->getJson(route('admin.donations.show', $donation))->assertForbidden();
            $this->actingAs($actor)->getJson(route('admin.waqf.assets.index'))->assertOk()->assertJsonCount(0, 'data');
            $this->actingAs($actor)->getJson(route('admin.zakat.collections.index'))->assertOk()->assertJsonCount(0, 'data');
            $this->actingAs($actor)->getJson(route('admin.finances.report', ['mosque_id' => $mosque->id]))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.reports.export', [
                'type' => 'donations', 'format' => 'csv', 'mosque_id' => $mosque->id,
            ]))->assertForbidden();
        }
    }

    public function test_suspended_and_archived_administrators_are_removed_from_financial_scope(): void
    {
        $mosque = $this->mosque('INACTIVE');

        foreach ([User::factory()->suspended()->create(), User::factory()->archived()->create()] as $admin) {
            $admin->assignRole('admin');
            $this->membership($admin, $mosque, MosqueMembershipType::Administrator);

            $this->assertFalse($admin->canAdministerMosque($mosque));
            $this->assertFalse(Mosque::query()->administrableBy($admin)->whereKey($mosque)->exists());
            $this->actingAs($admin)->get(route('admin.donations.index'))->assertRedirect(route('login'));
        }
    }

    public function test_actual_nested_resource_mosques_and_submitted_relations_are_enforced(): void
    {
        $admin = $this->actor('admin');
        $other = $this->actor('admin');
        $localMosque = $this->mosque('NESTED-LOCAL', $admin);
        $outsideMosque = $this->mosque('NESTED-OTHER', $other);
        $outsideDonation = $this->donation($outsideMosque, $other, 'NESTED-DON', 100);
        $outsideSubsidy = $this->subsidy($outsideMosque, $other, 'NESTED-SUB', 100);
        $outsideAsset = $this->asset($outsideMosque, $other, 'NESTED-WAQF');
        $outsideRevenue = $this->revenue($outsideAsset, $other, 'NESTED-WRV', 100);
        $outsideBeneficiary = $this->beneficiary($outsideMosque, $other, 'NESTED-BEN');
        $outsideDistribution = $this->distribution($outsideMosque, $outsideBeneficiary, $other, 'NESTED-DIS', 50);
        $outsideFaithful = $this->faithful($outsideMosque, $other, 'NESTED-FID');

        $this->actingAs($admin)->postJson(route('admin.donations.validate', $outsideDonation))->assertForbidden();
        $this->actingAs($admin)->postJson(route('admin.finances.subsidies.validate', $outsideSubsidy))->assertForbidden();
        $this->actingAs($admin)->postJson(route('admin.waqf.revenues.validate', $outsideRevenue))->assertForbidden();
        $this->actingAs($admin)->postJson(route('admin.zakat.distributions.validate', $outsideDistribution))->assertForbidden();
        $this->actingAs($admin)->postJson(route('admin.donations.store'), $this->donationPayload($localMosque) + [
            'faithful_id' => $outsideFaithful->id,
        ])->assertUnprocessable();
        $this->actingAs($admin)->postJson(route('admin.zakat.distributions.store'), $this->distributionPayload($localMosque, $outsideBeneficiary))
            ->assertUnprocessable();
    }

    public function test_aggregates_csv_pdf_and_service_totals_exclude_outside_mosques(): void
    {
        $primary = $this->actor('admin');
        $secondary = $this->actor('admin');
        $other = $this->actor('admin');
        $localMosque = $this->mosque('SCOPED', $primary);
        $outsideMosque = $this->mosque('LEAK', $other);
        $this->membership($secondary, $localMosque, MosqueMembershipType::Administrator);
        $this->donation($localMosque, $primary, 'SCOPED-REF', 125, 'validated');
        $this->donation($outsideMosque, $other, 'LEAK-REF', 999999, 'validated');
        $this->expense($localMosque, $primary, 'SCOPED-EXP', 25, 'validated');
        $this->expense($outsideMosque, $other, 'LEAK-EXP', 888888, 'validated');
        $localSubsidy = $this->subsidy($localMosque, $primary, 'SCOPED-SUB', 15);
        $outsideSubsidy = $this->subsidy($outsideMosque, $other, 'LEAK-SUB', 777777);
        $localAsset = $this->asset($localMosque, $primary, 'SCOPED-WAQF');
        $outsideAsset = $this->asset($outsideMosque, $other, 'LEAK-WAQF');
        $localRevenue = $this->revenue($localAsset, $primary, 'SCOPED-WRV', 35);
        $outsideRevenue = $this->revenue($outsideAsset, $other, 'LEAK-WRV', 666666);
        $localWaqfExpense = $this->waqfExpense($localAsset, $primary, 'SCOPED-WEX', 5);
        $outsideWaqfExpense = $this->waqfExpense($outsideAsset, $other, 'LEAK-WEX', 555555);
        $localCollection = $this->collection($localMosque, $primary, 'SCOPED-ZAK', 45);
        $outsideCollection = $this->collection($outsideMosque, $other, 'LEAK-ZAK', 444444);
        $localBeneficiary = $this->beneficiary($localMosque, $primary, 'SCOPED-BEN');
        $outsideBeneficiary = $this->beneficiary($outsideMosque, $other, 'LEAK-BEN');
        $localDistribution = $this->distribution($localMosque, $localBeneficiary, $primary, 'SCOPED-DIS', 10);
        $outsideDistribution = $this->distribution($outsideMosque, $outsideBeneficiary, $other, 'LEAK-DIS', 333333);
        $reports = app(ReportExportService::class);

        $this->actingAs($secondary)->getJson(route('admin.finances.report'))
            ->assertOk()
            ->assertJsonPath('total_resources', 125)
            ->assertJsonPath('total_expenses', 25)
            ->assertJsonPath('balance', 100);

        $rows = collect($reports->rows('donations', [], $secondary));
        $this->assertSame(['SCOPED-REF'], $rows->pluck('reference')->all());
        $this->assertSame(['GNF' => 125.0], $reports->totals('donations', [], $secondary)->all());

        $scopedReferences = [
            'zakat_collections' => [$localCollection->receipt_number, $outsideCollection->receipt_number],
            'zakat_distributions' => [$localDistribution->reference_number, $outsideDistribution->reference_number],
            'waqf_assets' => [$localAsset->registration_number, $outsideAsset->registration_number],
            'waqf_revenues' => [$localRevenue->receipt_number, $outsideRevenue->receipt_number],
            'waqf_expenses' => [$localWaqfExpense->reference_number, $outsideWaqfExpense->reference_number],
            'subsidies' => [$localSubsidy->reference_number, $outsideSubsidy->reference_number],
            'expenses' => ['SCOPED-EXP', 'LEAK-EXP'],
        ];
        foreach ($scopedReferences as $type => [$localReference, $outsideReference]) {
            $references = collect($reports->rows($type, [], $secondary))->pluck('reference');
            $this->assertSame([$localReference], $references->all());
            $this->assertNotContains($outsideReference, $references);
        }

        $csv = $this->actingAs($secondary)->get(route('admin.reports.export', [
            'type' => 'donations', 'format' => 'csv',
        ]))->assertOk()->streamedContent();
        $this->assertStringContainsString('SCOPED-REF', $csv);
        $this->assertStringNotContainsString('LEAK-REF', $csv);
        $this->actingAs($secondary)->get(route('admin.reports.export', [
            'type' => 'donations', 'format' => 'pdf',
        ]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_financial_list_scopes_execute_in_sql_without_per_record_authorization_queries(): void
    {
        $admin = $this->actor('admin');
        $localMosque = $this->mosque('QUERY-LOCAL');
        $this->membership($admin, $localMosque, MosqueMembershipType::Administrator);
        $localDonation = $this->donation($localMosque, $admin, 'QUERY-LOCAL-DON', 100);
        $localAsset = $this->asset($localMosque, $admin, 'QUERY-LOCAL-WAQF');
        $localCollection = $this->collection($localMosque, $admin, 'QUERY-LOCAL-ZAK', 50);
        foreach (range(1, 12) as $sequence) {
            $mosque = $this->mosque('QUERY-OUT-'.$sequence);
            $this->donation($mosque, $admin, 'QUERY-OUT-DON-'.$sequence, 100);
            $this->asset($mosque, $admin, 'QUERY-OUT-WAQF-'.$sequence);
            $this->collection($mosque, $admin, 'QUERY-OUT-ZAK-'.$sequence, 50);
        }
        $admin->load('roles');

        $queries = [
            fn () => Donation::query()->whereHas('mosque', fn (Builder $mosques) => $mosques->administrableBy($admin))->pluck('id'),
            fn () => WaqfAsset::query()->whereHas('mosque', fn (Builder $mosques) => $mosques->administrableBy($admin))->pluck('id'),
            fn () => ZakatCollection::query()->whereHas('mosque', fn (Builder $mosques) => $mosques->administrableBy($admin))->pluck('id'),
        ];

        foreach ($queries as $index => $query) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $ids = $query();
            $queryCount = count(DB::getQueryLog());
            DB::disableQueryLog();

            $expected = [$localDonation->id, $localAsset->id, $localCollection->id][$index];
            $this->assertSame([$expected], $ids->all());
            $this->assertSame(1, $queryCount);
        }

        $this->assertSame(45, Permission::query()->count());
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function mosque(string $code, ?User $primary = null): Mosque
    {
        $mosque = Mosque::query()->create([
            'code' => $code, 'name' => 'Mosque '.$code, 'region' => 'Conakry',
            'prefecture' => 'Conakry', 'commune' => 'Ratoma', 'status' => 'active',
            'admin_id' => $primary?->id,
        ]);
        if ($primary !== null && $primary->hasRole('admin')) {
            $this->membership($primary, $mosque, MosqueMembershipType::Administrator);
        }

        return $mosque;
    }

    private function membership(User $user, Mosque $mosque, MosqueMembershipType $type): MosqueMembership
    {
        return MosqueMembership::query()->create([
            'mosque_id' => $mosque->id, 'user_id' => $user->id, 'membership_type' => $type,
        ]);
    }

    private function donation(Mosque $mosque, User $creator, string $reference, float $amount, string $status = 'pending'): Donation
    {
        return Donation::query()->create([
            'mosque_id' => $mosque->id, 'receipt_number' => $reference, 'contribution_type' => 'donation',
            'amount' => $amount, 'currency' => 'GNF', 'payment_method' => 'cash', 'received_at' => now(),
            'status' => $status, 'is_anonymous' => true, 'created_by' => $creator->id,
            'validated_by' => $status === 'validated' ? $creator->id : null,
            'validated_at' => $status === 'validated' ? now() : null,
        ]);
    }

    private function donationPayload(Mosque $mosque): array
    {
        return [
            'mosque_id' => $mosque->id, 'contribution_type' => 'donation', 'amount' => 100,
            'currency' => 'GNF', 'payment_method' => 'cash', 'received_at' => now()->subMinute()->toDateTimeString(),
        ];
    }

    private function subsidy(Mosque $mosque, User $creator, string $reference, float $amount): Subsidy
    {
        return Subsidy::query()->create([
            'mosque_id' => $mosque->id, 'reference_number' => $reference, 'source' => 'SGAR',
            'amount' => $amount, 'currency' => 'GNF', 'received_at' => now(), 'status' => 'pending',
            'created_by' => $creator->id,
        ]);
    }

    private function expense(Mosque $mosque, User $creator, string $reference, float $amount, string $status): Expense
    {
        return Expense::query()->create([
            'mosque_id' => $mosque->id, 'reference_number' => $reference, 'category' => 'maintenance',
            'amount' => $amount, 'currency' => 'GNF', 'spent_at' => now(), 'purpose' => 'Maintenance',
            'supporting_document' => 'invoice.pdf', 'status' => $status, 'created_by' => $creator->id,
            'validated_by' => $status === 'validated' ? $creator->id : null,
            'validated_at' => $status === 'validated' ? now() : null,
        ]);
    }

    private function asset(Mosque $mosque, User $creator, string $reference): WaqfAsset
    {
        return WaqfAsset::query()->create([
            'mosque_id' => $mosque->id, 'registration_number' => $reference, 'name' => 'Waqf '.$reference,
            'type' => 'shop', 'estimated_value' => 1000, 'currency' => 'GNF',
            'dedicated_at' => now()->subDay(), 'status' => 'active', 'created_by' => $creator->id,
        ]);
    }

    private function revenue(WaqfAsset $asset, User $creator, string $reference, float $amount): WaqfRevenue
    {
        return WaqfRevenue::query()->create([
            'waqf_asset_id' => $asset->id, 'receipt_number' => $reference, 'source' => 'Rent',
            'amount' => $amount, 'currency' => 'GNF', 'payment_method' => 'cash',
            'received_at' => now(), 'status' => 'pending',
            'created_by' => $creator->id,
        ]);
    }

    private function waqfExpense(WaqfAsset $asset, User $creator, string $reference, float $amount): WaqfExpense
    {
        return WaqfExpense::query()->create([
            'waqf_asset_id' => $asset->id, 'reference_number' => $reference, 'category' => 'maintenance',
            'amount' => $amount, 'currency' => 'GNF', 'spent_at' => now(), 'purpose' => 'Maintenance',
            'status' => 'pending', 'created_by' => $creator->id,
        ]);
    }

    private function collection(Mosque $mosque, User $creator, string $reference, float $amount): ZakatCollection
    {
        return ZakatCollection::query()->create([
            'mosque_id' => $mosque->id, 'receipt_number' => $reference, 'category' => 'maal',
            'amount' => $amount, 'currency' => 'GNF', 'payment_method' => 'cash', 'collected_at' => now(),
            'status' => 'pending', 'created_by' => $creator->id,
        ]);
    }

    private function beneficiary(Mosque $mosque, User $creator, string $reference): ZakatBeneficiary
    {
        return ZakatBeneficiary::query()->create([
            'mosque_id' => $mosque->id, 'beneficiary_number' => $reference, 'name' => 'Beneficiary',
            'category' => 'poor', 'eligibility_reason' => 'Verified', 'status' => 'active',
            'verified_at' => now(), 'verified_by' => $creator->id,
        ]);
    }

    private function distribution(Mosque $mosque, ZakatBeneficiary $beneficiary, User $creator, string $reference, float $amount): ZakatDistribution
    {
        return ZakatDistribution::query()->create($this->distributionPayload($mosque, $beneficiary) + [
            'reference_number' => $reference, 'status' => 'pending', 'created_by' => $creator->id,
        ]);
    }

    private function distributionPayload(Mosque $mosque, ZakatBeneficiary $beneficiary): array
    {
        return [
            'mosque_id' => $mosque->id, 'zakat_beneficiary_id' => $beneficiary->id, 'category' => 'maal',
            'amount' => 50, 'currency' => 'GNF', 'payment_method' => 'cash',
            'distributed_at' => now(), 'purpose' => 'Support',
        ];
    }

    private function faithful(Mosque $mosque, User $creator, string $reference): Faithful
    {
        return Faithful::query()->create([
            'mosque_id' => $mosque->id, 'registration_number' => $reference,
            'first_name' => 'Faithful', 'last_name' => 'Outside', 'joined_at' => now(),
            'status' => 'active', 'consent_at' => now(), 'created_by' => $creator->id,
        ]);
    }
}
