<?php

namespace Tests\Feature;

use App\Enums\MosqueMembershipType;
use App\Models\AuditLog;
use App\Models\Donation;
use App\Models\Faithful;
use App\Models\Mosque;
use App\Models\MosqueMembership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_record_a_pending_donation_for_assigned_mosque(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();

        $response = $this->actingAs($admin)->postJson(route('admin.donations.store'), $this->payload($mosque));

        $response->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('currency', 'GNF');
        $this->assertStringStartsWith('DON-', $response->json('receipt_number'));
        $this->assertDatabaseHas('donations', ['mosque_id' => $mosque->id, 'amount' => 250000, 'status' => 'pending']);
    }

    public function test_anonymous_donation_does_not_retain_donor_identity(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $payload = $this->payload($mosque) + [
            'is_anonymous' => true,
            'donor_phone' => '620000000',
            'donor_email' => 'private@example.com',
        ];
        $payload['donor_name'] = 'Identité privée';

        $response = $this->actingAs($admin)->postJson(route('admin.donations.store'), $payload);

        $response->assertCreated();
        $this->assertDatabaseHas('donations', [
            'id' => $response->json('id'),
            'is_anonymous' => true,
            'faithful_id' => null,
            'donor_name' => null,
            'donor_phone' => null,
            'donor_email' => null,
        ]);
    }

    public function test_admin_cannot_view_or_manage_another_mosques_donations(): void
    {
        [$admin] = $this->adminAndMosque();
        [, $otherMosque] = $this->adminAndMosque('other');
        $donation = $this->donation($otherMosque);

        $this->actingAs($admin)->getJson(route('admin.donations.show', $donation))->assertForbidden();
        $this->actingAs($admin)->postJson(route('admin.donations.store'), $this->payload($otherMosque))->assertForbidden();
    }

    public function test_amount_must_be_strictly_positive(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $payload = $this->payload($mosque);
        $payload['amount'] = 0;

        $this->actingAs($admin)->postJson(route('admin.donations.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    public function test_faithful_must_belong_to_selected_mosque(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        [, $otherMosque] = $this->adminAndMosque('other');
        $faithful = Faithful::query()->create([
            'mosque_id' => $otherMosque->id,
            'registration_number' => 'FID-OTHER',
            'first_name' => 'Mamadou',
            'last_name' => 'Diallo',
            'joined_at' => now()->toDateString(),
            'status' => 'active',
            'consent_at' => now(),
            'created_by' => $otherMosque->admin_id,
        ]);
        $payload = $this->payload($mosque);
        $payload['faithful_id'] = $faithful->id;

        $this->actingAs($admin)->postJson(route('admin.donations.store'), $payload)->assertUnprocessable();
    }

    public function test_pending_donation_can_be_validated_only_once_and_then_is_locked(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $donation = $this->donation($mosque, $admin);

        $this->actingAs($admin)->postJson(route('admin.donations.validate', $donation))
            ->assertOk()
            ->assertJsonPath('status', 'validated');

        $this->assertDatabaseHas('donations', ['id' => $donation->id, 'validated_by' => $admin->id]);
        $this->actingAs($admin)->patchJson(route('admin.donations.update', $donation), ['amount' => 300000])->assertUnprocessable();
        $this->actingAs($admin)->deleteJson(route('admin.donations.destroy', $donation))->assertUnprocessable();
        $this->actingAs($admin)->postJson(route('admin.donations.validate', $donation))->assertUnprocessable();
    }

    public function test_rejection_requires_a_reason(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();
        $donation = $this->donation($mosque, $admin);

        $this->actingAs($admin)->postJson(route('admin.donations.reject', $donation), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->actingAs($admin)->postJson(route('admin.donations.reject', $donation), ['reason' => 'Référence non vérifiable'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');
    }

    public function test_creation_and_validation_are_audited_without_donor_values(): void
    {
        [$admin, $mosque] = $this->adminAndMosque();

        $created = $this->actingAs($admin)->postJson(route('admin.donations.store'), $this->payload($mosque))->assertCreated();
        $donation = Donation::query()->findOrFail($created->json('id'));
        $this->actingAs($admin)->postJson(route('admin.donations.validate', $donation))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['event' => 'donation.created', 'auditable_id' => $donation->id]);
        $logs = AuditLog::query()->where('auditable_id', $donation->id)->get();
        $this->assertTrue($logs->contains(fn (AuditLog $log) => $log->event === 'donation.updated'));
        $this->assertStringNotContainsString('620000000', $logs->toJson());
    }

    private function adminAndMosque(string $suffix = 'main'): array
    {
        $admin = User::factory()->create(['email' => $suffix.'-admin@example.com']);
        $admin->assignRole('admin');

        $mosque = Mosque::query()->create([
            'code' => 'MOS-'.strtoupper($suffix),
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

    private function payload(Mosque $mosque): array
    {
        return [
            'mosque_id' => $mosque->id,
            'contribution_type' => 'donation',
            'amount' => 250000,
            'currency' => 'GNF',
            'payment_method' => 'mobile_money',
            'payment_reference' => 'PAY-'.uniqid(),
            'received_at' => now()->subMinute()->toDateTimeString(),
            'donor_name' => 'Donateur test',
            'donor_phone' => '620000000',
        ];
    }

    private function donation(Mosque $mosque, ?User $creator = null): Donation
    {
        $creator ??= User::query()->findOrFail($mosque->admin_id);

        return Donation::query()->create([
            'mosque_id' => $mosque->id,
            'receipt_number' => 'DON-'.strtoupper(uniqid()),
            'contribution_type' => 'donation',
            'amount' => 100000,
            'currency' => 'GNF',
            'payment_method' => 'cash',
            'received_at' => now(),
            'status' => 'pending',
            'donor_name' => 'Donateur test',
            'created_by' => $creator->id,
        ]);
    }
}
