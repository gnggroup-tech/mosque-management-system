<?php

namespace Tests\Feature\Account;

use App\Enums\AccountStatus;
use App\Exceptions\AccountStatusTransitionException;
use App\Models\AuditLog;
use App\Models\Mosque;
use App\Models\User;
use App\Services\AccountStatusTransitionService;
use App\Services\AdministrativeAccountStatusService;
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

class AdministrativeAccountStatusManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSIONS = [
        'suspend' => 'users.suspend',
        'reactivate' => 'users.reactivate',
        'archive' => 'users.archive',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-13 12:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_status_permissions_are_seeded_only_for_superadmin_and_idempotently(): void
    {
        $historical = collect(config('permissions.all'))
            ->reject(fn (string $permission): bool => in_array($permission, self::PERMISSIONS, true))
            ->values()
            ->all();

        $this->assertCount(38, $historical);
        $this->assertSame(41, Permission::query()->count());

        foreach (self::PERMISSIONS as $permission) {
            $this->assertTrue(Permission::findByName($permission)->exists);
            $this->assertTrue(Role::findByName('superadmin')->hasPermissionTo($permission));
            $this->assertFalse(Role::findByName('admin')->hasPermissionTo($permission));
            $this->assertFalse(Role::findByName('user')->hasPermissionTo($permission));
        }

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(41, Permission::query()->count());
        foreach (self::PERMISSIONS as $permission) {
            $this->assertSame(1, Permission::query()->where('name', $permission)->count());
        }
    }

    public function test_guest_and_inactive_actor_cannot_manage_status(): void
    {
        $target = User::factory()->create();

        $this->patchJson(route('admin.accounts.suspend', $target), ['reason' => 'Policy violation'])
            ->assertUnauthorized();

        $inactive = User::factory()->suspended()->create();
        $inactive->givePermissionTo('users.suspend');

        $this->actingAs($inactive)
            ->patch(route('admin.accounts.suspend', $target), ['reason' => 'Policy violation'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'active']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.account.suspended']);
    }

    public function test_each_permission_authorizes_only_its_own_action(): void
    {
        foreach (self::PERMISSIONS as $allowedAction => $permission) {
            $actor = User::factory()->create();
            $actor->givePermissionTo($permission);

            foreach (array_keys(self::PERMISSIONS) as $action) {
                $target = $this->eligibleAccount($action);
                $response = $this->actingAs($actor)->patchJson(
                    route("admin.accounts.{$action}", $target),
                    ['reason' => 'Administrative review'],
                );

                if ($action === $allowedAction) {
                    $response->assertOk();
                } else {
                    $response->assertForbidden();
                }
            }
        }
    }

    public function test_users_approve_and_admin_users_update_do_not_authorize_any_action(): void
    {
        $approveActor = User::factory()->create();
        $approveActor->givePermissionTo('users.approve');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->assertTrue($admin->can('users.update'));

        foreach ([$approveActor, $admin] as $actor) {
            foreach (array_keys(self::PERMISSIONS) as $action) {
                $target = $this->eligibleAccount($action);

                $this->actingAs($actor)
                    ->patchJson(route("admin.accounts.{$action}", $target), ['reason' => 'Administrative review'])
                    ->assertForbidden();
            }
        }
    }

    public function test_superadmin_without_required_permission_is_refused_for_each_action(): void
    {
        foreach (self::PERMISSIONS as $action => $permission) {
            $actor = $this->superadmin();
            Role::findByName('superadmin')->revokePermissionTo($permission);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $target = $this->eligibleAccount($action);

            $this->actingAs($actor)
                ->patchJson(route("admin.accounts.{$action}", $target), ['reason' => 'Administrative review'])
                ->assertForbidden();

            $this->seed(RolesAndPermissionsSeeder::class);
        }
    }

    public function test_policy_forbids_each_self_action_even_with_permission(): void
    {
        foreach (self::PERMISSIONS as $action => $permission) {
            $actor = User::factory()->create();
            $actor->givePermissionTo($permission);

            $this->assertFalse(Gate::forUser($actor)->allows($action, $actor));
            $this->actingAs($actor)
                ->patchJson(route("admin.accounts.{$action}", $actor), ['reason' => 'Administrative review'])
                ->assertForbidden();
        }
    }

    public function test_active_account_can_be_suspended_and_is_blocked_on_next_protected_request(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->create();

        $this->actingAs($actor)
            ->patchJson(route('admin.accounts.suspend', $target), ['reason' => "  Policy\n violation  "])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'status' => 'suspended',
            'suspension_reason' => 'Policy violation',
        ]);
        $this->actingAs($target)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_suspended_account_can_be_reactivated_but_pending_approval_cannot(): void
    {
        $actor = $this->superadmin();
        $suspended = User::factory()->suspended()->create();

        $this->actingAs($actor)
            ->patchJson(route('admin.accounts.reactivate', $suspended), ['reason' => 'Review completed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('users', [
            'id' => $suspended->id,
            'status' => 'active',
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);

        $pending = User::factory()->pendingApproval()->create();
        $this->actingAs($actor)
            ->patchJson(route('admin.accounts.reactivate', $pending), ['reason' => 'Review completed'])
            ->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $pending->id, 'status' => 'pending_approval']);
    }

    public function test_active_and_suspended_accounts_can_be_archived_without_deletion(): void
    {
        $actor = $this->superadmin();

        foreach ([User::factory()->create(), User::factory()->suspended()->create()] as $target) {
            $this->actingAs($actor)
                ->patchJson(route('admin.accounts.archive', $target), ['reason' => 'Account retention policy'])
                ->assertOk()
                ->assertJsonPath('data.status', 'archived');

            $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'archived']);
            $this->assertNotNull(User::query()->find($target->id));
        }
    }

    public function test_incompatible_statuses_and_repeated_submissions_are_controlled(): void
    {
        $actor = $this->superadmin();
        $cases = [
            ['suspend', User::factory()->pendingEmail()->create()],
            ['suspend', User::factory()->pendingApproval()->create()],
            ['suspend', User::factory()->suspended()->create()],
            ['reactivate', User::factory()->create()],
            ['reactivate', User::factory()->pendingApproval()->create()],
            ['reactivate', User::factory()->archived()->create()],
            ['archive', User::factory()->pendingEmail()->create()],
            ['archive', User::factory()->pendingApproval()->create()],
            ['archive', User::factory()->archived()->create()],
        ];

        foreach ($cases as [$action, $target]) {
            $before = $target->refresh()->getRawOriginal();
            $this->actingAs($actor)
                ->patchJson(route("admin.accounts.{$action}", $target), ['reason' => 'Administrative review'])
                ->assertUnprocessable();
            $this->assertSame($before, $target->refresh()->getRawOriginal());
        }

        $target = User::factory()->create();
        $this->actingAs($actor)
            ->patchJson(route('admin.accounts.suspend', $target), ['reason' => 'Administrative review'])
            ->assertOk();
        $this->actingAs($actor)
            ->patchJson(route('admin.accounts.suspend', $target), ['reason' => 'Administrative review'])
            ->assertUnprocessable();
        $this->assertSame(1, AuditLog::query()->where('event', 'user.account.suspended')->where('auditable_id', $target->id)->count());
    }

    public function test_reason_is_required_normalized_and_limited_for_every_action(): void
    {
        foreach (array_keys(self::PERMISSIONS) as $action) {
            foreach ([null, '', '  ', 'ab', str_repeat('a', 501)] as $reason) {
                $target = $this->eligibleAccount($action);
                $this->actingAs($this->superadmin())
                    ->patchJson(route("admin.accounts.{$action}", $target), ['reason' => $reason])
                    ->assertUnprocessable()
                    ->assertJsonValidationErrors('reason');
                $this->assertDatabaseMissing('audit_logs', [
                    'event' => "user.account.{$this->eventSuffix($action)}",
                    'auditable_id' => $target->id,
                ]);
            }
        }
    }

    public function test_deleted_account_returns_not_found_and_get_routes_cannot_mutate(): void
    {
        $actor = $this->superadmin();

        foreach (array_keys(self::PERMISSIONS) as $action) {
            $target = $this->eligibleAccount($action);
            $targetId = $target->id;
            $target->delete();

            $this->actingAs($actor)
                ->patchJson("/admin/accounts/{$targetId}/{$action}", ['reason' => 'Administrative review'])
                ->assertNotFound();

            $fresh = $this->eligibleAccount($action);
            $this->actingAs($actor)
                ->get("/admin/accounts/{$fresh->id}/{$action}")
                ->assertMethodNotAllowed();
        }
    }

    public function test_routes_are_patch_only_with_action_specific_middleware(): void
    {
        foreach (self::PERMISSIONS as $action => $permission) {
            $route = app('router')->getRoutes()->getByName("admin.accounts.{$action}");

            $this->assertSame(['PATCH'], $route->methods());
            $this->assertSame(
                ['web', 'auth', 'account.active', "permission:{$permission}"],
                $route->gatherMiddleware(),
            );
        }
    }

    public function test_business_audits_are_minimal_and_action_specific(): void
    {
        $actor = $this->superadmin();
        AuditLog::query()->delete();

        foreach (array_keys(self::PERMISSIONS) as $action) {
            $target = $this->eligibleAccount($action);
            $from = $target->status;
            $reason = 'Administrative review';

            $this->actingAs($actor)
                ->patchJson(route("admin.accounts.{$action}", $target), ['reason' => $reason])
                ->assertOk();

            $audit = AuditLog::query()
                ->where('event', "user.account.{$this->eventSuffix($action)}")
                ->where('auditable_id', $target->id)
                ->sole();
            $this->assertSame($actor->id, $audit->actor_id);
            $this->assertSame([
                'target_user_id' => $target->id,
                'from_status' => $from->value,
                'to_status' => $target->refresh()->status->value,
                'occurred_at' => now()->toIso8601String(),
                'reason' => $reason,
            ], $audit->metadata);
            foreach (['password', 'remember_token', 'token', 'email', 'name', 'session'] as $secret) {
                $this->assertArrayNotHasKey($secret, $audit->metadata);
            }
        }
    }

    public function test_business_audit_failure_rolls_back_transition_and_all_audits(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->create();
        $auditLogger = Mockery::mock(AuditLogger::class);
        $auditLogger->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $event): bool => $event === 'user.status.transitioned')
            ->andReturn(new AuditLog);
        $auditLogger->shouldReceive('log')
            ->once()
            ->withArgs(fn (string $event): bool => $event === 'user.account.suspended')
            ->andThrow(new RuntimeException('Status audit unavailable.'));
        $service = new AdministrativeAccountStatusService(
            new AccountStatusTransitionService($auditLogger),
            $auditLogger,
        );
        AuditLog::query()->delete();

        try {
            $service->suspend($target, $actor, 'Administrative review');
            $this->fail('The failed business audit should roll back the operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Status audit unavailable.', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'active']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.status.transitioned']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.account.suspended']);
    }

    public function test_service_uses_current_database_status_instead_of_stale_model(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->create();
        User::query()->whereKey($target->id)->update([
            'status' => AccountStatus::Suspended->value,
            'suspended_at' => now(),
            'suspension_reason' => 'Concurrent action',
        ]);

        try {
            app(AdministrativeAccountStatusService::class)
                ->suspend($target, $actor, 'Administrative review');
            $this->fail('The stale source status should not be trusted.');
        } catch (AccountStatusTransitionException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'suspended']);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.account.suspended',
            'auditable_id' => $target->id,
        ]);
    }

    public function test_status_actions_preserve_credentials_verification_roles_permissions_and_mosque(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->create();
        $target->assignRole('admin');
        $target->givePermissionTo('audit.view');
        $target->forceFill([
            'email_verified_at' => now()->subDay(),
            'remember_token' => 'preserved-token',
        ])->saveQuietly();
        $raw = $target->refresh()->getRawOriginal();
        $roles = $target->getRoleNames()->sort()->values()->all();
        $permissions = $target->getAllPermissions()->pluck('name')->sort()->values()->all();
        $mosque = Mosque::query()->create([
            'code' => 'TASK-027',
            'name' => 'Status Mosque',
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Ratoma',
            'admin_id' => $target->id,
        ]);

        $this->actingAs($actor)
            ->patchJson(route('admin.accounts.suspend', $target), ['reason' => 'Administrative review'])
            ->assertOk();

        $target->refresh();
        $this->assertSame($raw['password'], $target->getRawOriginal('password'));
        $this->assertTrue(Hash::check('password', $target->getRawOriginal('password')));
        $this->assertSame($raw['remember_token'], $target->getRawOriginal('remember_token'));
        $this->assertSame($raw['email_verified_at'], $target->getRawOriginal('email_verified_at'));
        $this->assertSame($roles, $target->getRoleNames()->sort()->values()->all());
        $this->assertSame($permissions, $target->getAllPermissions()->pluck('name')->sort()->values()->all());
        $this->assertSame($target->id, $mosque->refresh()->admin_id);
    }

    public function test_task_024_matrix_remains_unchanged_and_archived_terminal(): void
    {
        $allowed = [
            'pending_email:pending_approval',
            'pending_approval:active',
            'active:suspended',
            'suspended:active',
            'active:archived',
            'suspended:archived',
        ];

        foreach (AccountStatus::cases() as $from) {
            foreach (AccountStatus::cases() as $to) {
                $this->assertSame(
                    in_array("{$from->value}:{$to->value}", $allowed, true),
                    $from->canTransitionTo($to),
                );
            }
        }
    }

    private function superadmin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('superadmin');

        return $actor;
    }

    private function eligibleAccount(string $action): User
    {
        return $action === 'reactivate'
            ? User::factory()->suspended()->create()
            : User::factory()->create();
    }

    private function eventSuffix(string $action): string
    {
        return match ($action) {
            'suspend' => 'suspended',
            'reactivate' => 'reactivated',
            'archive' => 'archived',
        };
    }
}
