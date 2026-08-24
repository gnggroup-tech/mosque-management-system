<?php

namespace Tests\Feature\Account;

use App\Models\AuditLog;
use App\Models\Mosque;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdministrativeAccountDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_directory_permission_is_superadmin_only_and_seeding_is_idempotent(): void
    {
        $historical = collect(config('permissions.all'))->reject(
            fn (string $permission): bool => in_array($permission, ['users.directory.view', 'users.invite', 'users.roles.manage', 'users.mosques.manage'], true),
        );

        $this->assertCount(41, $historical);
        $this->assertSame(45, Permission::query()->count());
        $this->assertTrue(Role::findByName('superadmin')->hasPermissionTo('users.directory.view'));
        $this->assertFalse(Role::findByName('admin')->hasPermissionTo('users.directory.view'));
        $this->assertFalse(Role::findByName('user')->hasPermissionTo('users.directory.view'));
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('users.view'));

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(45, Permission::query()->count());
        $this->assertSame(1, Permission::query()->where('name', 'users.directory.view')->count());
    }

    public function test_guest_and_inactive_accounts_cannot_access_directory(): void
    {
        $account = User::factory()->create();

        $this->getJson(route('admin.accounts.index'))->assertUnauthorized();
        $this->getJson(route('admin.accounts.show', $account))->assertUnauthorized();

        $inactive = User::factory()->suspended()->create();
        $inactive->givePermissionTo('users.directory.view');

        $this->actingAs($inactive)->get(route('admin.accounts.index'))->assertRedirect(route('login'));
        $this->actingAs($inactive)->get(route('admin.accounts.show', $account))->assertRedirect(route('login'));
    }

    public function test_policy_uses_dedicated_permission_as_a_capability(): void
    {
        $target = User::factory()->create();
        $directlyAuthorized = User::factory()->create();
        $directlyAuthorized->givePermissionTo('users.directory.view');

        $this->assertTrue(Gate::forUser($directlyAuthorized)->allows('viewAny', User::class));
        $this->assertTrue(Gate::forUser($directlyAuthorized)->allows('view', $target));
        $this->actingAs($directlyAuthorized)->getJson(route('admin.accounts.index'))->assertOk();
        $this->actingAs($directlyAuthorized)->getJson(route('admin.accounts.show', $target))->assertOk();
    }

    public function test_other_account_permissions_never_authorize_the_directory(): void
    {
        $target = User::factory()->create();
        $actors = [
            tap(User::factory()->create(), fn (User $user) => $user->assignRole('admin')),
            tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('users.view')),
            tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo('users.update')),
            tap(User::factory()->create(), fn (User $user) => $user->assignRole('user')),
        ];

        foreach ($actors as $actor) {
            $this->actingAs($actor)->getJson(route('admin.accounts.index'))->assertForbidden();
            $this->actingAs($actor)->getJson(route('admin.accounts.show', $target))->assertForbidden();
            $this->assertFalse(Gate::forUser($actor)->allows('viewAny', User::class));
            $this->assertFalse(Gate::forUser($actor)->allows('view', $target));
        }
    }

    public function test_superadmin_without_directory_permission_is_refused(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->create();
        Role::findByName('superadmin')->revokePermissionTo('users.directory.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($actor)->getJson(route('admin.accounts.index'))->assertForbidden();
        $this->actingAs($actor)->getJson(route('admin.accounts.show', $target))->assertForbidden();
    }

    public function test_index_is_paginated_by_twenty_with_stable_id_order_and_maximum_one_hundred(): void
    {
        User::factory()->count(24)->create();
        $actor = $this->superadmin();

        $response = $this->actingAs($actor)->getJson(route('admin.accounts.index'))
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.per_page', 20);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame($ids, collect($ids)->sort()->values()->all());

        $this->actingAs($actor)->getJson(route('admin.accounts.index', ['per_page' => 100]))
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
        $this->actingAs($actor)->getJson(route('admin.accounts.index', ['per_page' => 101]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
        $this->actingAs($actor)->getJson(route('admin.accounts.index', ['per_page' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_status_and_role_filters_are_allowlisted(): void
    {
        $actor = $this->superadmin();
        $activeAdmin = User::factory()->create(['name' => 'Active Admin']);
        $activeAdmin->assignRole('admin');
        User::factory()->suspended()->create(['name' => 'Suspended Account']);

        $this->actingAs($actor)->getJson(route('admin.accounts.index', ['status' => 'suspended']))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Suspended Account');
        $this->actingAs($actor)->getJson(route('admin.accounts.index', ['role' => 'admin']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $activeAdmin->id);
        $this->actingAs($actor)->getJson(route('admin.accounts.index', ['status' => 'unknown']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->actingAs($actor)->getJson(route('admin.accounts.index', ['role' => 'owner']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_search_is_normalized_limited_and_supports_exact_numeric_ids(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->create([
            'name' => 'Directory Target',
            'email' => 'directory.target@example.test',
        ]);
        User::factory()->create(['name' => "Account {$target->id} Copy"]);

        $this->actingAs($actor)->getJson(route('admin.accounts.index', ['search' => (string) $target->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id);
        $this->actingAs($actor)->getJson(route('admin.accounts.index', ['search' => '  Directory   Target  ']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $target->id);
        $this->actingAs($actor)->getJson(route('admin.accounts.index', ['search' => 'target@example']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $target->id);
        $this->actingAs($actor)->getJson(route('admin.accounts.index', ['search' => 'x']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
        $this->actingAs($actor)->getJson(route('admin.accounts.index', ['search' => str_repeat('x', 101)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
    }

    public function test_search_wildcards_are_rejected_and_cannot_expand_the_query(): void
    {
        $actor = $this->superadmin();
        User::factory()->create(['name' => 'Ordinary Account']);
        User::factory()->create(['name' => 'Literal %_ Account']);

        $this->actingAs($actor)->getJson(route('admin.accounts.index', ['search' => '%_']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
    }

    public function test_date_range_sort_and_direction_are_strictly_validated(): void
    {
        $actor = $this->superadmin();
        User::factory()->create(['name' => 'Zulu']);
        User::factory()->create(['name' => 'Alpha']);

        $response = $this->actingAs($actor)->getJson(route('admin.accounts.index', [
            'sort' => 'name',
            'direction' => 'asc',
        ]))->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame($names, collect($names)->sort()->values()->all());

        foreach ([
            ['sort' => 'password'],
            ['sort' => 'id desc; drop table users'],
            ['direction' => 'sideways'],
        ] as $query) {
            $this->actingAs($actor)->getJson(route('admin.accounts.index', $query))->assertUnprocessable();
        }

        $this->actingAs($actor)->getJson(route('admin.accounts.index', [
            'created_from' => '2026-08-14',
            'created_to' => '2026-08-13',
        ]))->assertUnprocessable()->assertJsonValidationErrors('created_to');
    }

    public function test_unknown_parameters_do_not_affect_results(): void
    {
        $actor = $this->superadmin();
        User::factory()->count(3)->create();

        $normal = $this->actingAs($actor)->getJson(route('admin.accounts.index'))->json('data');
        $unknown = $this->actingAs($actor)->getJson(route('admin.accounts.index', [
            'column' => 'password',
            'include' => 'permissions,audits',
        ]))->assertOk()->json('data');

        $this->assertSame($normal, $unknown);
    }

    public function test_list_and_detail_use_strict_distinct_allowlists(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->suspended()->create([
            'email' => 'private@example.test',
            'suspension_reason' => 'Sensitive free-form reason',
            'verification_required_at' => now(),
            'verification_exempt_until' => now()->addDay(),
        ]);
        $target->assignRole('admin');
        $this->mosqueFor($target, 'Minimal Mosque');

        $list = $this->actingAs($actor)->getJson(route('admin.accounts.index', ['search' => (string) $target->id]))
            ->assertOk()
            ->assertJsonPath('data.0.roles.0', 'admin')
            ->assertJsonPath('data.0.administered_mosques.0.id', fn ($id) => is_int($id))
            ->assertJsonPath('data.0.administered_mosques.0.name', 'Minimal Mosque')
            ->json('data.0');
        $detail = $this->actingAs($actor)->getJson(route('admin.accounts.show', $target))
            ->assertOk()
            ->assertJsonPath('data.email', 'private@example.test')
            ->json('data');

        $this->assertSame([
            'id', 'name', 'status', 'locale', 'created_at', 'updated_at', 'roles', 'administered_mosques',
        ], array_keys($list));
        $this->assertSame([
            'id', 'name', 'status', 'locale', 'created_at', 'updated_at', 'roles', 'administered_mosques',
            'email', 'email_verified_at', 'activated_at', 'suspended_at', 'archived_at',
        ], array_keys($detail));

        foreach (['password', 'remember_token', 'permissions', 'tokens', 'sessions', 'suspension_reason', 'verification_required_at', 'verification_exempt_until', 'audits', 'finances', 'contributions'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $list);
            $this->assertArrayNotHasKey($forbidden, $detail);
        }
        $this->assertArrayNotHasKey('email', $list);
        $this->assertSame(['id', 'name'], array_keys($detail['administered_mosques'][0]));
    }

    public function test_deleted_account_is_not_found_and_unauthorized_actor_gets_no_data(): void
    {
        $target = User::factory()->create();
        $id = $target->id;
        $target->delete();

        $this->actingAs($this->superadmin())->getJson("/admin/accounts/{$id}")->assertNotFound();

        $existing = User::factory()->create(['email' => 'hidden@example.test']);
        $response = $this->actingAs(User::factory()->create())
            ->getJson(route('admin.accounts.show', $existing))
            ->assertForbidden();
        $this->assertStringNotContainsString('hidden@example.test', $response->getContent());
    }

    public function test_reads_do_not_mutate_accounts_or_create_business_audits(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->create();
        $before = User::query()->findOrFail($target->id)->getRawOriginal();
        $auditCount = AuditLog::query()->count();

        $this->actingAs($actor)->getJson(route('admin.accounts.index'))->assertOk();
        $this->actingAs($actor)->getJson(route('admin.accounts.show', $target))->assertOk();

        $this->assertSame($before, User::query()->findOrFail($target->id)->getRawOriginal());
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_directory_eager_loads_minimal_relations_without_account_permissions_or_audits(): void
    {
        $actor = $this->superadmin();
        foreach (User::factory()->count(3)->create() as $account) {
            $account->assignRole('user');
            $this->mosqueFor($account, "Mosque {$account->id}");
        }
        $actor->can('users.directory.view');
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($actor)->getJson(route('admin.accounts.index'))->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query');
        $this->assertLessThanOrEqual(2, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "roles"'))->count());
        $this->assertSame(1, $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "mosques"'))->count());
        $this->assertSame(
            1,
            $queries->filter(fn (string $sql): bool => str_contains($sql, 'from "permissions"'))->count(),
            'Only the permission middleware may load permissions for the authenticated actor.',
        );
        $this->assertFalse(
            $queries->contains(fn (string $sql): bool => str_contains($sql, 'from "audit_logs"')),
            $queries->implode("\n"),
        );
    }

    public function test_routes_are_get_only_and_have_expected_middleware(): void
    {
        foreach (['index', 'show'] as $action) {
            $route = app('router')->getRoutes()->getByName("admin.accounts.{$action}");

            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $this->assertSame(
                ['web', 'auth', 'account.active', 'permission:users.directory.view'],
                $route->gatherMiddleware(),
            );
        }
    }

    private function superadmin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('superadmin');

        return $actor;
    }

    private function mosqueFor(User $administrator, string $name): Mosque
    {
        return Mosque::query()->create([
            'code' => 'DIR-'.$administrator->id,
            'name' => $name,
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Ratoma',
            'admin_id' => $administrator->id,
        ]);
    }
}
