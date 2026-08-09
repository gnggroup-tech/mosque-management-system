<?php

namespace Tests\Feature\Council;

use App\Models\CouncilMember;
use App\Models\Mosque;
use App\Models\MosqueCouncil;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouncilMemberManagementTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); $this->seed(RolesAndPermissionsSeeder::class); }

    public function test_superadmin_can_assign_a_member_with_a_local_function(): void
    {
        [$superadmin, $council] = [$this->user('superadmin'), $this->council(null)];
        $member = $this->user('user');
        $this->actingAs($superadmin)->postJson(route('admin.council-members.store'), [
            'mosque_council_id' => $council->id, 'user_id' => $member->id, 'function' => 'imam',
            'responsibilities' => 'Direction religieuse', 'started_at' => '2026-01-01',
        ])->assertCreated()->assertJsonPath('function', 'imam');
        $this->assertDatabaseHas('audit_logs', ['event' => 'council-member.created']);
    }

    public function test_admin_can_manage_only_members_of_assigned_mosques(): void
    {
        $admin = $this->user('admin'); $other = $this->user('admin');
        $ownCouncil = $this->council($admin); $otherMember = $this->membership($this->council($other), $this->user('user'));
        $this->actingAs($admin)->postJson(route('admin.council-members.store'), [
            'mosque_council_id' => $ownCouncil->id, 'user_id' => $this->user('user')->id,
            'function' => 'secretary', 'started_at' => '2026-01-01',
        ])->assertCreated();
        $this->actingAs($admin)->patchJson(route('admin.council-members.update', $otherMember), ['function' => 'treasurer'])->assertForbidden();
    }

    public function test_same_user_cannot_have_two_active_memberships_in_same_council(): void
    {
        $superadmin = $this->user('superadmin'); $council = $this->council(null); $user = $this->user('user');
        $this->membership($council, $user);
        $this->actingAs($superadmin)->postJson(route('admin.council-members.store'), [
            'mosque_council_id' => $council->id, 'user_id' => $user->id, 'function' => 'advisor', 'started_at' => '2026-02-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_member_cannot_be_added_to_inactive_council(): void
    {
        $superadmin = $this->user('superadmin'); $council = $this->council(null, 'inactive');
        $this->actingAs($superadmin)->postJson(route('admin.council-members.store'), [
            'mosque_council_id' => $council->id, 'user_id' => $this->user('user')->id, 'function' => 'muezzin', 'started_at' => '2026-01-01',
        ])->assertUnprocessable();
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        $superadmin = $this->user('superadmin'); $council = $this->council(null);
        $this->actingAs($superadmin)->postJson(route('admin.council-members.store'), [
            'mosque_council_id' => $council->id, 'user_id' => $this->user('user')->id, 'function' => 'president',
            'started_at' => '2027-01-01', 'ended_at' => '2026-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('ended_at');
    }

    public function test_user_can_view_active_members_but_cannot_modify_them(): void
    {
        $user = $this->user('user'); $membership = $this->membership($this->council(null), $user);
        $this->actingAs($user)->getJson(route('admin.council-members.index'))->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->patchJson(route('admin.council-members.update', $membership), ['function' => 'president'])->assertForbidden();
    }

    public function test_authorized_admin_can_soft_delete_a_membership(): void
    {
        $admin = $this->user('admin'); $membership = $this->membership($this->council($admin), $this->user('user'));
        $this->actingAs($admin)->deleteJson(route('admin.council-members.destroy', $membership))->assertNoContent();
        $this->assertSoftDeleted($membership); $this->assertDatabaseHas('audit_logs', ['event' => 'council-member.deleted']);
    }

    public function test_guest_cannot_access_council_members(): void { $this->getJson(route('admin.council-members.index'))->assertUnauthorized(); }

    private function user(string $role): User { $user = User::factory()->create(); $user->assignRole($role); return $user; }
    private function council(?User $admin, string $status = 'active'): MosqueCouncil
    {
        static $n = 0; $n++;
        $mosque = Mosque::query()->create(['code' => 'MOS-M'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'name' => 'Mosquée '.$n, 'region' => 'Conakry', 'prefecture' => 'Conakry', 'commune' => 'Ratoma', 'status' => 'active', 'admin_id' => $admin?->id]);
        return MosqueCouncil::query()->create(['mosque_id' => $mosque->id, 'name' => 'Conseil '.$n, 'mandate_start' => '2026-01-01', 'mandate_end' => '2030-01-01', 'status' => $status]);
    }
    private function membership(MosqueCouncil $council, User $user): CouncilMember { return CouncilMember::query()->create(['mosque_council_id' => $council->id, 'user_id' => $user->id, 'function' => 'imam', 'started_at' => '2026-01-01', 'status' => 'active']); }
}
