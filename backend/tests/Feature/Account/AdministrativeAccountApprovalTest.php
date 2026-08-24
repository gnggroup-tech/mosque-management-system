<?php

namespace Tests\Feature\Account;

use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\Mosque;
use App\Models\User;
use App\Services\AccountApprovalService;
use App\Services\AccountStatusTransitionService;
use App\Services\AuditLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdministrativeAccountApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-12 15:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_permission_is_seeded_only_for_superadmin_and_remains_idempotent(): void
    {
        $laterTaskPermissions = ['users.suspend', 'users.reactivate', 'users.archive', 'users.directory.view', 'users.invite'];
        $existingPermissions = collect(config('permissions.all'))
            ->reject(fn (string $permission): bool => in_array($permission, $laterTaskPermissions, true))
            ->values()
            ->all();

        $this->assertCount(38, $existingPermissions);
        $this->assertTrue(Permission::findByName('users.approve')->exists);
        $this->assertTrue(Role::findByName('superadmin')->hasPermissionTo('users.approve'));
        $this->assertFalse(Role::findByName('admin')->hasPermissionTo('users.approve'));
        $this->assertFalse(Role::findByName('user')->hasPermissionTo('users.approve'));

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(43, Permission::query()->count());
        $this->assertSame(1, Permission::query()->where('name', 'users.approve')->count());
        $this->assertTrue(Role::findByName('superadmin')->hasPermissionTo('users.approve'));
        $this->assertFalse(Role::findByName('admin')->hasPermissionTo('users.approve'));
        $this->assertFalse(Role::findByName('user')->hasPermissionTo('users.approve'));
    }

    public function test_guest_and_inactive_accounts_cannot_approve(): void
    {
        $target = User::factory()->pendingApproval()->create();

        $this->patchJson(route('admin.accounts.approve', $target))->assertUnauthorized();

        $inactive = User::factory()->suspended()->create();
        $inactive->givePermissionTo('users.approve');

        $this->actingAs($inactive)
            ->patch(route('admin.accounts.approve', $target))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'pending_approval']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.account.approved']);
    }

    public function test_superadmin_without_explicit_permission_cannot_approve(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('superadmin');
        Role::findByName('superadmin')->revokePermissionTo('users.approve');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $target = User::factory()->pendingApproval()->create();

        $this->actingAs($actor)
            ->patchJson(route('admin.accounts.approve', $target))
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'pending_approval']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.account.approved']);
    }

    public function test_admin_with_users_update_and_regular_user_cannot_approve(): void
    {
        $target = User::factory()->pendingApproval()->create();

        foreach (['admin', 'user'] as $role) {
            $actor = User::factory()->create();
            $actor->assignRole($role);

            if ($role === 'admin') {
                $this->assertTrue($actor->can('users.update'));
            }

            $this->actingAs($actor)
                ->patchJson(route('admin.accounts.approve', $target))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'pending_approval']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.account.approved']);
    }

    public function test_explicit_permission_authorizes_policy_but_self_approval_is_refused(): void
    {
        $actor = User::factory()->create();
        $actor->givePermissionTo('users.approve');
        $target = User::factory()->pendingApproval()->create();

        $this->assertTrue(Gate::forUser($actor)->allows('approve', $target));
        $this->assertFalse(Gate::forUser($actor)->allows('approve', $actor));

        $this->actingAs($actor)
            ->patchJson(route('admin.accounts.approve', $actor))
            ->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.account.approved',
            'auditable_id' => $actor->id,
        ]);

        $this->actingAs($actor)
            ->patchJson(route('admin.accounts.approve', $target))
            ->assertOk();
    }

    public function test_authorized_approval_uses_transition_service_and_returns_minimal_data(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->pendingApproval()->create();

        $response = $this->actingAs($actor)
            ->patchJson(route('admin.accounts.approve', $target));

        $response->assertOk()->assertExactJson([
            'data' => [
                'id' => $target->id,
                'status' => AccountStatus::Active->value,
                'activated_at' => now()->toIso8601String(),
            ],
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'status' => AccountStatus::Active->value,
            'activated_at' => now()->format('Y-m-d H:i:s'),
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.status.transitioned',
            'actor_id' => $actor->id,
            'auditable_id' => $target->id,
        ]);
    }

    public function test_ineligible_statuses_are_rejected_without_changes_or_success_audit(): void
    {
        $actor = $this->superadmin();

        foreach ([AccountStatus::PendingEmail, AccountStatus::Active, AccountStatus::Suspended, AccountStatus::Archived] as $status) {
            $target = $this->accountWithStatus($status);
            $before = $target->refresh()->getRawOriginal();

            $this->actingAs($actor)
                ->patchJson(route('admin.accounts.approve', $target))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('account');

            $this->assertSame($before, $target->refresh()->getRawOriginal());
            $this->assertDatabaseMissing('audit_logs', [
                'event' => 'user.account.approved',
                'auditable_id' => $target->id,
            ]);
        }

        $this->assertFalse(AccountStatus::PendingEmail->canTransitionTo(AccountStatus::Active));
        $this->assertFalse(AccountStatus::Archived->canTransitionTo(AccountStatus::Active));
    }

    public function test_deleted_account_returns_not_found_without_success_audit(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->pendingApproval()->create();
        $targetId = $target->id;
        $target->delete();

        $this->actingAs($actor)
            ->patchJson("/admin/accounts/{$targetId}/approve")
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.account.approved',
            'auditable_id' => $targetId,
        ]);
    }

    public function test_double_submission_creates_only_one_successful_approval(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->pendingApproval()->create();

        $this->actingAs($actor)
            ->patchJson(route('admin.accounts.approve', $target))
            ->assertOk();
        $this->actingAs($actor)
            ->patchJson(route('admin.accounts.approve', $target))
            ->assertUnprocessable();

        $this->assertSame(1, AuditLog::query()->where('event', 'user.account.approved')->where('auditable_id', $target->id)->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'user.status.transitioned')->where('auditable_id', $target->id)->count());
        $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'active']);
    }

    public function test_approval_rolls_back_transition_and_audits_when_business_audit_fails(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->pendingApproval()->create();
        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $event): bool => $event === 'user.status.transitioned')
            ->andReturn(new AuditLog);
        $auditLogger->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $event): bool => $event === 'user.account.approved')
            ->andThrow(new RuntimeException('Approval audit unavailable.'));
        $service = new AccountApprovalService(
            new AccountStatusTransitionService($auditLogger),
            $auditLogger,
        );
        AuditLog::query()->delete();

        try {
            $service->approve($target, $actor);
            $this->fail('The failed approval audit should roll back the operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Approval audit unavailable.', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'status' => AccountStatus::PendingApproval->value,
            'activated_at' => null,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.status.transitioned',
            'auditable_id' => $target->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.account.approved',
            'auditable_id' => $target->id,
        ]);
    }

    public function test_approval_audit_is_minimal_and_contains_no_secrets(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->pendingApproval()->create();
        AuditLog::query()->delete();

        $this->actingAs($actor)->patchJson(route('admin.accounts.approve', $target))->assertOk();

        $audit = AuditLog::query()->where('event', 'user.account.approved')->sole();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame($target->id, $audit->auditable_id);
        $this->assertSame([
            'target_user_id' => $target->id,
            'from_status' => AccountStatus::PendingApproval->value,
            'to_status' => AccountStatus::Active->value,
            'occurred_at' => now()->toIso8601String(),
            'reason' => 'administrative_approval',
        ], $audit->metadata);
        $this->assertArrayNotHasKey('password', $audit->metadata);
        $this->assertArrayNotHasKey('remember_token', $audit->metadata);
        $this->assertArrayNotHasKey('token', $audit->metadata);
        $this->assertArrayNotHasKey('email', $audit->metadata);
        $this->assertArrayNotHasKey('name', $audit->metadata);
    }

    public function test_approval_preserves_credentials_roles_permissions_mosque_and_verification(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->pendingApproval()->create();
        $target->assignRole('admin');
        $target->forceFill([
            'email_verified_at' => now()->subDay(),
            'remember_token' => 'preserved-remember-token',
        ])->saveQuietly();
        $password = $target->getRawOriginal('password');
        $roles = $target->getRoleNames()->sort()->values()->all();
        $permissions = $target->getAllPermissions()->pluck('name')->sort()->values()->all();
        $verifiedAt = $target->getRawOriginal('email_verified_at');
        $mosque = Mosque::query()->create([
            'code' => 'TASK-026',
            'name' => 'Approval Mosque',
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Ratoma',
            'admin_id' => $target->id,
        ]);

        $this->actingAs($actor)->patchJson(route('admin.accounts.approve', $target))->assertOk();

        $target->refresh();
        $this->assertSame($password, $target->getRawOriginal('password'));
        $this->assertTrue(Hash::check('password', $target->getRawOriginal('password')));
        $this->assertSame('preserved-remember-token', $target->remember_token);
        $this->assertSame($verifiedAt, $target->getRawOriginal('email_verified_at'));
        $this->assertSame($roles, $target->getRoleNames()->sort()->values()->all());
        $this->assertSame($permissions, $target->getAllPermissions()->pluck('name')->sort()->values()->all());
        $this->assertSame($target->id, $mosque->refresh()->admin_id);
    }

    public function test_route_is_patch_only_and_has_expected_middleware(): void
    {
        $route = app('router')->getRoutes()->getByName('admin.accounts.approve');

        $this->assertSame(['PATCH'], $route->methods());
        $this->assertSame(
            ['web', 'auth', 'account.active', 'permission:users.approve'],
            $route->gatherMiddleware(),
        );

        $target = User::factory()->pendingApproval()->create();
        $this->actingAs($this->superadmin())
            ->get("/admin/accounts/{$target->id}/approve")
            ->assertMethodNotAllowed();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'pending_approval']);
    }

    public function test_approved_account_can_authenticate_afterward(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->pendingApproval()->create();

        $this->actingAs($actor)->patchJson(route('admin.accounts.approve', $target))->assertOk();
        $this->post(route('logout'));

        $this->post('/login', [
            'email' => $target->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($target);
    }

    private function superadmin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('superadmin');

        return $actor;
    }

    private function accountWithStatus(AccountStatus $status): User
    {
        return match ($status) {
            AccountStatus::PendingEmail => User::factory()->pendingEmail()->create(),
            AccountStatus::PendingApproval => User::factory()->pendingApproval()->create(),
            AccountStatus::Active => User::factory()->create(),
            AccountStatus::Suspended => User::factory()->suspended()->create(),
            AccountStatus::Archived => User::factory()->archived()->create(),
        };
    }
}
