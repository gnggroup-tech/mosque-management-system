<?php

namespace Tests\Feature\Zakat;

use App\Enums\MosqueMembershipType;
use App\Models\AuditLog;
use App\Models\Mosque;
use App\Models\MosqueMembership;
use App\Models\User;
use App\Models\ZakatBeneficiary;
use App\Models\ZakatCollection;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZakatManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_record_and_validate_a_calculated_collection(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $response = $this->actingAs($admin)->postJson(route('admin.zakat.collections.store'), $this->collectionPayload($mosque));
        $response->assertCreated()->assertJsonPath('amount', '25000.00');
        $this->actingAs($admin)->postJson(route('admin.zakat.collections.validate', $response->json('id')))->assertOk()->assertJsonPath('status', 'validated');
    }

    public function test_incorrect_calculated_amount_is_rejected(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $payload = $this->collectionPayload($mosque);
        $payload['amount'] = 30000;
        $this->actingAs($admin)->postJson(route('admin.zakat.collections.store'), $payload)->assertUnprocessable();
    }

    public function test_anonymous_collection_does_not_keep_identity(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $payload = $this->collectionPayload($mosque) + ['is_anonymous' => true];
        $payload['payer_name'] = 'Privé';
        $created = $this->actingAs($admin)->postJson(route('admin.zakat.collections.store'), $payload)->assertCreated();
        $this->assertDatabaseHas('zakat_collections', ['id' => $created->json('id'), 'is_anonymous' => true, 'payer_name' => null, 'faithful_id' => null]);
    }

    public function test_admin_cannot_manage_another_mosques_zakat(): void
    {
        [$admin] = $this->adminAndMosque('main');
        [, $other] = $this->adminAndMosque('other');
        $this->actingAs($admin)->postJson(route('admin.zakat.collections.store'), $this->collectionPayload($other))->assertForbidden();
        $this->actingAs($admin)->postJson(route('admin.zakat.beneficiaries.store'), $this->beneficiaryPayload($other))->assertForbidden();
    }

    public function test_beneficiary_must_be_active_and_belong_to_the_mosque(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        [, $other] = $this->adminAndMosque('other');
        $beneficiary = $this->beneficiary($other);
        $this->actingAs($admin)->postJson(route('admin.zakat.distributions.store'), $this->distributionPayload($mosque, $beneficiary))->assertUnprocessable();
    }

    public function test_distribution_cannot_exceed_validated_category_balance(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $beneficiary = $this->beneficiary($mosque);
        $distribution = $this->actingAs($admin)->postJson(route('admin.zakat.distributions.store'), $this->distributionPayload($mosque, $beneficiary))->assertCreated();
        $this->actingAs($admin)->postJson(route('admin.zakat.distributions.validate', $distribution->json('id')))->assertUnprocessable();
    }

    public function test_validated_funds_can_be_distributed_only_once(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $collection = ZakatCollection::query()->create($this->collectionRecord($mosque, $admin) + ['status' => 'validated', 'validated_by' => $admin->id, 'validated_at' => now()]);
        $beneficiary = $this->beneficiary($mosque);
        $distribution = $this->actingAs($admin)->postJson(route('admin.zakat.distributions.store'), $this->distributionPayload($mosque, $beneficiary))->assertCreated();
        $route = route('admin.zakat.distributions.validate', $distribution->json('id'));
        $this->actingAs($admin)->postJson($route)->assertOk()->assertJsonPath('status', 'validated');
        $this->actingAs($admin)->postJson($route)->assertUnprocessable();
        $this->assertDatabaseHas('zakat_collections', ['id' => $collection->id, 'status' => 'validated']);
    }

    public function test_collections_beneficiaries_and_distributions_are_audited(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $collection = $this->actingAs($admin)->postJson(route('admin.zakat.collections.store'), $this->collectionPayload($mosque))->assertCreated();
        $beneficiary = $this->actingAs($admin)->postJson(route('admin.zakat.beneficiaries.store'), $this->beneficiaryPayload($mosque))->assertCreated();
        $this->actingAs($admin)->postJson(route('admin.zakat.distributions.store'), $this->distributionPayload($mosque, ZakatBeneficiary::findOrFail($beneficiary->json('id'))))->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['event' => 'zakat.collection.created', 'auditable_id' => $collection->json('id')]);
        $this->assertTrue(AuditLog::query()->where('event', 'zakat.beneficiary.created')->exists());
        $this->assertTrue(AuditLog::query()->where('event', 'zakat.distribution.created')->exists());
    }

    private function adminAndMosque(string $suffix = 'main'): array
    {
        $admin = User::factory()->create(['email' => $suffix.'-zakat@example.com']);
        $admin->assignRole('admin');
        $mosque = Mosque::query()->create(['code' => 'ZAK-'.strtoupper($suffix), 'name' => 'Mosquée '.$suffix, 'address' => 'Conakry', 'region' => 'Conakry', 'prefecture' => 'Conakry', 'commune' => 'Ratoma', 'status' => 'active', 'infrastructures' => [], 'admin_id' => $admin->id]);
        MosqueMembership::query()->create(['mosque_id' => $mosque->id, 'user_id' => $admin->id, 'membership_type' => MosqueMembershipType::Administrator]);

        return [$admin, $mosque];
    }

    private function collectionPayload(Mosque $mosque): array
    {
        return ['mosque_id' => $mosque->id, 'category' => 'maal', 'assessable_amount' => 1000000, 'rate' => 2.5, 'amount' => 25000, 'currency' => 'GNF', 'payment_method' => 'cash', 'collected_at' => now()->subMinute()->toDateTimeString(), 'payer_name' => 'Payeur test'];
    }

    private function collectionRecord(Mosque $mosque, User $admin): array
    {
        return ['mosque_id' => $mosque->id, 'receipt_number' => 'ZAK-'.strtoupper(uniqid()), 'category' => 'maal', 'amount' => 100000, 'currency' => 'GNF', 'payment_method' => 'cash', 'collected_at' => now(), 'payer_name' => 'Payeur', 'created_by' => $admin->id];
    }

    private function beneficiaryPayload(Mosque $mosque): array
    {
        return ['mosque_id' => $mosque->id, 'name' => 'Bénéficiaire test', 'category' => 'poor', 'eligibility_reason' => 'Situation vérifiée par le conseil'];
    }

    private function beneficiary(Mosque $mosque): ZakatBeneficiary
    {
        return ZakatBeneficiary::query()->create($this->beneficiaryPayload($mosque) + ['beneficiary_number' => 'BEN-'.strtoupper(uniqid()), 'status' => 'active', 'verified_at' => now(), 'verified_by' => $mosque->admin_id]);
    }

    private function distributionPayload(Mosque $mosque, ZakatBeneficiary $beneficiary): array
    {
        return ['mosque_id' => $mosque->id, 'zakat_beneficiary_id' => $beneficiary->id, 'category' => 'maal', 'amount' => 50000, 'currency' => 'GNF', 'payment_method' => 'cash', 'distributed_at' => now()->toDateTimeString(), 'purpose' => 'Aide alimentaire'];
    }
}
