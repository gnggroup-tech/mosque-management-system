<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdministrativeAccountInterfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_authorized_superadmin_can_browse_paginated_filtered_interface(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->create([
            'name' => 'Interface Search Target',
            'email' => 'interface-search@example.test',
        ]);
        User::factory()->count(23)->create();

        $response = $this->actingAs($actor)->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertViewIs('admin.accounts.index')
            ->assertSee(__('Account directory'))
            ->assertSee('hidden overflow-x-auto md:block', false)
            ->assertSee('md:hidden', false);

        $this->assertSame(20, $response->viewData('accounts')->perPage());
        $this->assertSame(25, $response->viewData('accounts')->total());

        $this->actingAs($actor)->get(route('admin.accounts.index', [
            'search' => 'Interface Search',
            'sort' => 'name',
            'direction' => 'desc',
            'per_page' => 50,
        ]))
            ->assertOk()
            ->assertSee('Interface Search Target')
            ->assertDontSee('interface-search@example.test')
            ->assertSee('value="name" selected', false)
            ->assertSee('value="desc" selected', false)
            ->assertSee('value="50" selected', false);

        $this->assertNotNull($target->id);
    }

    public function test_guest_admin_and_inactive_actor_are_refused(): void
    {
        $target = User::factory()->create();

        $this->get(route('admin.accounts.index'))->assertRedirect(route('login'));
        $this->get(route('admin.accounts.show', $target))->assertRedirect(route('login'));

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(route('admin.accounts.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.accounts.show', $target))->assertForbidden();

        $inactive = User::factory()->suspended()->create();
        $inactive->givePermissionTo('users.directory.view');
        $this->actingAs($inactive)->get(route('admin.accounts.index'))->assertRedirect(route('login'));
    }

    public function test_superadmin_without_directory_permission_is_refused(): void
    {
        $actor = $this->superadmin();
        Role::findByName('superadmin')->revokePermissionTo('users.directory.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($actor)->get(route('admin.accounts.index'))->assertForbidden();
    }

    public function test_detail_exposes_only_authorized_administrative_fields(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->suspended()->create([
            'name' => 'Protected Detail Account',
            'email' => 'allowed-detail@example.test',
            'password' => 'never-render-this-password',
            'remember_token' => 'never-render-this-token',
            'suspension_reason' => 'never-render-this-sensitive-reason',
            'verification_required_at' => now(),
            'verification_exempt_until' => now()->addDay(),
        ]);

        $this->actingAs($actor)->get(route('admin.accounts.show', $target))
            ->assertOk()
            ->assertViewIs('admin.accounts.show')
            ->assertSee('Protected Detail Account')
            ->assertSee('allowed-detail@example.test')
            ->assertDontSee('never-render-this-password')
            ->assertDontSee('never-render-this-token')
            ->assertDontSee('never-render-this-sensitive-reason')
            ->assertDontSee('verification_required_at')
            ->assertDontSee('verification_exempt_until')
            ->assertDontSee('permissions')
            ->assertDontSee('sessions')
            ->assertDontSee('financial');
    }

    public function test_actions_are_visible_only_for_allowed_statuses_and_never_for_self(): void
    {
        $actor = $this->superadmin();
        $pending = User::factory()->pendingApproval()->create();
        $active = User::factory()->create();
        $suspended = User::factory()->suspended()->create();
        $archived = User::factory()->archived()->create();

        $response = $this->actingAs($actor)->get(route('admin.accounts.index', ['per_page' => 100]))
            ->assertOk()
            ->assertSee("account-action-approve-{$pending->id}", false)
            ->assertDontSee("account-action-suspend-{$pending->id}", false)
            ->assertSee("account-action-suspend-{$active->id}", false)
            ->assertSee("account-action-archive-{$active->id}", false)
            ->assertSee("account-action-reactivate-{$suspended->id}", false)
            ->assertSee("account-action-archive-{$suspended->id}", false)
            ->assertDontSee("account-action-approve-{$archived->id}", false)
            ->assertDontSee("account-action-suspend-{$archived->id}", false)
            ->assertDontSee("account-action-reactivate-{$archived->id}", false)
            ->assertDontSee("account-action-archive-{$archived->id}", false)
            ->assertDontSee("account-action-suspend-{$actor->id}", false)
            ->assertDontSee("account-action-archive-{$actor->id}", false);

        $this->assertStringNotContainsString('account-action-approve-'.$active->id, $response->getContent());
    }

    public function test_interface_uses_existing_approval_and_status_routes(): void
    {
        $actor = $this->superadmin();
        $pending = User::factory()->pendingApproval()->create();
        $active = User::factory()->create();
        $suspended = User::factory()->suspended()->create();
        $archivable = User::factory()->create();

        $this->actingAs($actor)->patchJson(route('admin.accounts.approve', $pending))
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->actingAs($actor)->patchJson(route('admin.accounts.suspend', $active), ['reason' => 'Administrative review'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');
        $this->actingAs($actor)->patchJson(route('admin.accounts.reactivate', $suspended), ['reason' => 'Administrative review'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
        $this->actingAs($actor)->patchJson(route('admin.accounts.archive', $archivable), ['reason' => 'Administrative review'])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');
    }

    public function test_auto_action_and_controlled_error_responses_remain_enforced(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->create();

        $this->actingAs($actor)->patchJson(route('admin.accounts.suspend', $actor), ['reason' => 'Self action'])
            ->assertForbidden();
        $this->actingAs($actor)->getJson('/admin/accounts/999999')->assertNotFound();
        $this->actingAs($actor)->patchJson(route('admin.accounts.suspend', $target), ['reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_forms_and_javascript_provide_csrf_confirmation_and_double_submit_protection(): void
    {
        $actor = $this->superadmin();
        $target = User::factory()->create(['name' => 'Archive Confirmation Account']);

        $this->actingAs($actor)->get(route('admin.accounts.show', $target))
            ->assertOk()
            ->assertSee('meta name="csrf-token"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="_method" value="PATCH"', false)
            ->assertSee('data-requires-confirmation="true"', false)
            ->assertSee('Archive Confirmation Account')
            ->assertSee('x-bind:disabled="submitting"', false);

        $javascript = file_get_contents(resource_path('js/account-directory.js'));
        $this->assertStringContainsString("method: 'PATCH'", $javascript);
        $this->assertStringContainsString("'X-CSRF-TOKEN': csrf", $javascript);
        $this->assertStringContainsString('if (this.submitting', $javascript);
        $this->assertStringContainsString("this.action === 'archive' && this.confirmation !== config.accountName", $javascript);
    }

    public function test_navigation_is_accessible_only_to_directory_authority(): void
    {
        $superadmin = $this->superadmin();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($superadmin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('admin.accounts.index'), false);
        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('admin.accounts.index'), false);
    }

    public function test_interface_is_localized_in_french_english_and_arabic_rtl(): void
    {
        $actor = $this->superadmin();

        $actor->update(['locale' => 'fr']);
        $this->actingAs($actor)->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertSee('Répertoire des comptes')
            ->assertSee('dir="ltr"', false);

        $actor->update(['locale' => 'en']);
        $this->actingAs($actor->refresh())->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertSee('Account directory')
            ->assertSee('dir="ltr"', false);

        $actor->update(['locale' => 'ar']);
        $this->actingAs($actor->refresh())->get(route('admin.accounts.index'))
            ->assertOk()
            ->assertSee('دليل الحسابات')
            ->assertSee('dir="rtl"', false);
    }

    private function superadmin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('superadmin');

        return $actor;
    }
}
