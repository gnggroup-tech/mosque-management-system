<?php

namespace Tests\Feature\Account;

use App\Enums\MosqueMembershipType;
use App\Exceptions\AccountProvisioningException;
use App\Models\AuditLog;
use App\Models\CouncilMember;
use App\Models\Mosque;
use App\Models\MosqueCouncil;
use App\Models\MosqueMembership;
use App\Models\User;
use App\Services\AccountProvisioningService;
use App\Services\AuditLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AccountProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_permissions_are_exactly_seeded_only_for_superadmin_and_idempotently(): void
    {
        $this->assertSame(45, Permission::query()->count());
        foreach (['users.roles.manage', 'users.mosques.manage'] as $permission) {
            $this->assertTrue(Role::findByName('superadmin')->hasPermissionTo($permission));
            $this->assertFalse(Role::findByName('admin')->hasPermissionTo($permission));
            $this->assertFalse(Role::findByName('user')->hasPermissionTo($permission));
        }

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertSame(45, Permission::query()->count());
        $this->assertSame(1, Permission::query()->where('name', 'users.roles.manage')->count());
        $this->assertSame(1, Permission::query()->where('name', 'users.mosques.manage')->count());
    }

    public function test_policy_rejects_guest_local_admin_direct_permission_self_superadmin_and_inactive_target(): void
    {
        $target = $this->normalUser();
        $localAdmin = $this->normalAdmin();
        $localAdmin->givePermissionTo(['users.roles.manage', 'users.mosques.manage']);
        $superadmin = $this->superadmin();
        $otherSuperadmin = $this->superadmin();
        $inactive = User::factory()->suspended()->create();
        $inactive->assignRole('user');

        $this->get(route('admin.accounts.provisioning.edit', $target))->assertRedirect(route('login'));
        $this->assertFalse(Gate::forUser($localAdmin)->allows('provision', $target));
        $this->assertFalse(Gate::forUser($superadmin)->allows('provision', $superadmin));
        $this->assertFalse(Gate::forUser($superadmin)->allows('provision', $otherSuperadmin));
        $this->assertFalse(Gate::forUser($superadmin)->allows('provision', $inactive));
        $this->actingAs($localAdmin)->get(route('admin.accounts.provisioning.edit', $target))->assertForbidden();
    }

    public function test_combined_operation_requires_both_exact_permissions(): void
    {
        $target = $this->normalUser();
        $actor = $this->superadmin();

        Role::findByName('superadmin')->revokePermissionTo('users.roles.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($actor)->get(route('admin.accounts.provisioning.edit', $target))->assertForbidden();

        Role::findByName('superadmin')->givePermissionTo('users.roles.manage');
        Role::findByName('superadmin')->revokePermissionTo('users.mosques.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($actor->refresh())->get(route('admin.accounts.provisioning.edit', $target))->assertForbidden();
    }

    public function test_promotion_requires_administrator_membership_and_creates_exactly_one_role(): void
    {
        $actor = $this->superadmin();
        $target = $this->normalUser();
        $mosque = $this->mosque();
        $service = app(AccountProvisioningService::class);
        $version = $service->versionFor($target);

        $this->expectException(AccountProvisioningException::class);
        try {
            $service->provision($target, $actor, 'admin', [
                ['mosque_id' => $mosque->id, 'membership_type' => 'member'],
            ], [], [], $version);
        } finally {
            $this->assertTrue($target->fresh()->hasExactRoles('user'));
            $this->assertDatabaseMissing('mosque_user', ['user_id' => $target->id]);
        }
    }

    public function test_account_can_join_multiple_mosques_and_mosque_can_have_multiple_local_admins(): void
    {
        $actor = $this->superadmin();
        $first = $this->normalUser();
        $first->assignRole('admin');
        $second = $this->normalUser();
        $mosqueA = $this->mosque();
        $mosqueB = $this->mosque();
        $service = app(AccountProvisioningService::class);

        $service->provision($first, $actor, 'admin', [
            ['mosque_id' => $mosqueA->id, 'membership_type' => 'administrator'],
            ['mosque_id' => $mosqueB->id, 'membership_type' => 'administrator'],
        ], [], [], $service->versionFor($first));
        $service->provision($second, $actor, 'admin', [
            ['mosque_id' => $mosqueA->id, 'membership_type' => 'administrator'],
        ], [], [], $service->versionFor($second));

        $this->assertCount(2, $first->fresh()->mosqueMemberships);
        $this->assertSame(2, MosqueMembership::query()->where('mosque_id', $mosqueA->id)
            ->where('membership_type', 'administrator')->count());
        $this->assertTrue($first->fresh()->hasExactRoles('admin'));
        $this->assertTrue($second->fresh()->hasExactRoles('admin'));
        $this->assertTrue($first->fresh()->canAdministerMosque($mosqueA));
        $this->assertTrue($second->fresh()->canAdministerMosque($mosqueA));
    }

    public function test_role_without_membership_and_membership_without_role_grant_no_local_scope(): void
    {
        $roleOnly = $this->normalAdmin();
        $membershipOnly = $this->normalUser();
        $mosque = $this->mosque();
        MosqueMembership::query()->create([
            'mosque_id' => $mosque->id,
            'user_id' => $membershipOnly->id,
            'membership_type' => MosqueMembershipType::Administrator,
        ]);

        $this->assertFalse($roleOnly->canAdministerMosque($mosque));
        $this->assertFalse($membershipOnly->canAdministerMosque($mosque));
    }

    public function test_primary_administrator_removal_requires_explicit_valid_replacement(): void
    {
        $actor = $this->superadmin();
        $target = $this->normalAdmin();
        $mosque = $this->mosque(['admin_id' => $target->id]);
        MosqueMembership::query()->create([
            'mosque_id' => $mosque->id,
            'user_id' => $target->id,
            'membership_type' => MosqueMembershipType::Administrator,
        ]);
        $service = app(AccountProvisioningService::class);

        try {
            $service->provision($target, $actor, 'user', [
                ['mosque_id' => $mosque->id, 'membership_type' => 'member'],
            ], [], [], $service->versionFor($target));
            $this->fail('Removing a historical primary without replacement must fail.');
        } catch (AccountProvisioningException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($target->id, $mosque->fresh()->admin_id);
        $this->assertTrue($target->fresh()->hasExactRoles('admin'));
    }

    public function test_primary_replacement_and_explicit_primary_assignment_are_double_written_transactionally(): void
    {
        $actor = $this->superadmin();
        $target = $this->normalAdmin();
        $replacement = $this->normalAdmin();
        $mosque = $this->mosque(['admin_id' => $target->id]);
        foreach ([$target, $replacement] as $admin) {
            MosqueMembership::query()->create([
                'mosque_id' => $mosque->id,
                'user_id' => $admin->id,
                'membership_type' => MosqueMembershipType::Administrator,
            ]);
        }
        $service = app(AccountProvisioningService::class);

        $service->provision($target, $actor, 'user', [
            ['mosque_id' => $mosque->id, 'membership_type' => 'member'],
        ], [], [$mosque->id => $replacement->id], $service->versionFor($target));

        $this->assertSame($replacement->id, $mosque->fresh()->admin_id);
        $this->assertTrue($target->fresh()->hasExactRoles('user'));
        $this->assertDatabaseHas('mosque_user', [
            'mosque_id' => $mosque->id,
            'user_id' => $target->id,
            'membership_type' => 'member',
        ]);

        $newPrimary = $this->normalAdmin();
        $otherMosque = $this->mosque();
        $service->provision($newPrimary, $actor, 'admin', [
            ['mosque_id' => $otherMosque->id, 'membership_type' => 'administrator'],
        ], [$otherMosque->id], [], $service->versionFor($newPrimary));
        $this->assertSame($newPrimary->id, $otherMosque->fresh()->admin_id);
    }

    public function test_stale_double_submission_is_rejected_without_second_change(): void
    {
        $actor = $this->superadmin();
        $target = $this->normalUser();
        $mosque = $this->mosque();
        $service = app(AccountProvisioningService::class);
        $version = $service->versionFor($target);
        $payload = [['mosque_id' => $mosque->id, 'membership_type' => 'administrator']];

        $service->provision($target, $actor, 'admin', $payload, [], [], $version);

        $this->expectException(AccountProvisioningException::class);
        $service->provision($target, $actor, 'admin', $payload, [], [], $version);
    }

    public function test_provisioning_preserves_identity_status_password_and_religious_function_and_audits_minimally(): void
    {
        $actor = $this->superadmin();
        $target = $this->normalUser();
        $mosque = $this->mosque();
        $council = MosqueCouncil::query()->create([
            'mosque_id' => $mosque->id,
            'name' => 'Religious Council',
            'mandate_start' => now()->toDateString(),
            'mandate_end' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $member = CouncilMember::query()->create([
            'mosque_council_id' => $council->id,
            'user_id' => $target->id,
            'function' => 'Imam',
            'started_at' => now()->toDateString(),
            'status' => 'active',
        ]);
        $identity = $target->only(['email', 'password', 'status']);
        $service = app(AccountProvisioningService::class);

        $service->provision($target, $actor, 'admin', [
            ['mosque_id' => $mosque->id, 'membership_type' => 'administrator'],
        ], [], [], $service->versionFor($target));

        $this->assertSame($identity, $target->fresh()->only(['email', 'password', 'status']));
        $this->assertSame('Imam', $member->fresh()->function);
        $provisioningAudits = AuditLog::query()->whereIn('event', [
            'user.mosque.assigned',
            'user.role.changed',
        ])->orderBy('event')->get();
        $this->assertSame(['user.mosque.assigned', 'user.role.changed'], $provisioningAudits->pluck('event')->all());
        foreach ($provisioningAudits as $audit) {
            $this->assertSame($actor->id, $audit->metadata['actor_id']);
            $this->assertSame($target->id, $audit->metadata['target_user_id']);
            foreach (['password', 'email', 'token', 'session', 'permissions'] as $secret) {
                $this->assertArrayNotHasKey($secret, $audit->metadata);
            }
        }
    }

    public function test_audit_failure_rolls_back_role_membership_and_audit(): void
    {
        $actor = $this->superadmin();
        $target = $this->normalUser();
        $mosque = $this->mosque();
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')->once()->andThrow(new RuntimeException('audit unavailable'));
        $service = new AccountProvisioningService($logger);
        $auditCount = AuditLog::query()->count();

        try {
            $service->provision($target, $actor, 'admin', [
                ['mosque_id' => $mosque->id, 'membership_type' => 'administrator'],
            ], [], [], $service->versionFor($target));
            $this->fail('An audit failure must abort provisioning.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        $this->assertTrue($target->fresh()->hasExactRoles('user'));
        $this->assertDatabaseMissing('mosque_user', ['user_id' => $target->id]);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_interface_is_superadmin_only_localized_responsive_rtl_and_uses_safe_patch_form(): void
    {
        $actor = $this->superadmin();
        $target = $this->normalUser();
        $this->mosque();

        $actor->update(['locale' => 'en']);
        $this->actingAs($actor)->get(route('admin.accounts.provisioning.edit', $target))
            ->assertOk()
            ->assertSee('Manage role and mosques')
            ->assertSee('name="_token"', false)
            ->assertSee('name="_method" value="PATCH"', false)
            ->assertSee('name="version"', false)
            ->assertSee('x-bind:disabled="submitting"', false)
            ->assertSee('window.confirm', false)
            ->assertSee('sm:grid-cols-2', false)
            ->assertSee('md:flex-row', false)
            ->assertSee('dir="ltr"', false);

        $actor->update(['locale' => 'fr']);
        $this->actingAs($actor->refresh())->get(route('admin.accounts.provisioning.edit', $target))
            ->assertOk()->assertSee('Rôle applicatif');

        $actor->update(['locale' => 'ar']);
        $this->actingAs($actor->refresh())->get(route('admin.accounts.provisioning.edit', $target))
            ->assertOk()->assertSee('dir="rtl"', false);
    }

    public function test_request_rejects_superadmin_sensitive_fields_and_invalid_membership(): void
    {
        $actor = $this->superadmin();
        $target = $this->normalUser();
        $mosque = $this->mosque();
        $service = app(AccountProvisioningService::class);

        $this->actingAs($actor)->patch(route('admin.accounts.provisioning.update', $target), [
            'role' => 'superadmin',
            'memberships' => [['mosque_id' => $mosque->id, 'membership_type' => 'imam']],
            'primary_mosque_ids' => [],
            'primary_replacements' => [],
            'version' => $service->versionFor($target),
            'status' => 'active',
            'email' => 'changed@example.test',
            'password' => 'secret',
            'permissions' => ['platform.manage'],
        ])->assertSessionHasErrors(['role', 'memberships.0.membership_type', 'status', 'email', 'password', 'permissions']);

        $this->assertTrue($target->fresh()->hasExactRoles('user'));
        $this->assertDatabaseMissing('mosque_user', ['user_id' => $target->id]);
    }

    private function superadmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('superadmin');

        return $user;
    }

    private function normalAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function normalUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        return $user;
    }

    private function mosque(array $attributes = []): Mosque
    {
        static $sequence = 0;
        $sequence++;

        return Mosque::query()->create(array_merge([
            'code' => 'PRV-031-'.$sequence,
            'name' => 'Provisioning Mosque '.$sequence,
            'region' => 'Region',
            'prefecture' => 'Prefecture',
            'commune' => 'Commune',
            'status' => 'active',
        ], $attributes));
    }
}
