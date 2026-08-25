<?php

namespace Tests\Feature\Mosque;

use App\Enums\AccountStatus;
use App\Enums\MosqueMembershipType;
use App\Models\Activity;
use App\Models\Mosque;
use App\Models\MosqueMembership;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendCoreMosqueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_superadmin_dashboard_shows_global_authorized_metrics_without_sensitive_data(): void
    {
        $superadmin = $this->actor('superadmin');
        $mosque = $this->mosque('GLOBAL-035');
        User::factory()->create();
        User::factory()->create(['status' => AccountStatus::PendingApproval]);
        Activity::query()->create([
            'mosque_id' => $mosque->id,
            'title' => 'Cours du soir',
            'type' => 'course',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'status' => 'published',
            'created_by' => $superadmin->id,
        ]);

        $this->actingAs($superadmin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-testid="metric-mosques"', false)
            ->assertSee('data-testid="metric-active-accounts"', false)
            ->assertSee('data-testid="metric-pending-approvals"', false)
            ->assertSee('Cours du soir')
            ->assertDontSee('password');
    }

    public function test_local_admin_dashboard_and_mosque_list_use_only_canonical_memberships(): void
    {
        $admin = $this->actor('admin');
        $visible = $this->mosque('VISIBLE-035');
        $hidden = $this->mosque('HIDDEN-035');
        $this->membership($admin, $visible);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-testid="metric-mosques" data-value="1"', false)
            ->assertDontSee('HIDDEN-035');

        $this->actingAs($admin)->get(route('admin.mosques.index'))
            ->assertOk()
            ->assertSee('VISIBLE-035')
            ->assertDontSee('HIDDEN-035');
    }

    public function test_mosque_html_list_supports_search_status_region_and_pagination(): void
    {
        $superadmin = $this->actor('superadmin');
        $this->mosque('MATCH-035', ['name' => 'Mosquée Kaloum', 'region' => 'Conakry']);
        $this->mosque('OTHER-035', ['name' => 'Mosquée Labé', 'region' => 'Labé', 'status' => 'inactive']);

        $this->actingAs($superadmin)->get(route('admin.mosques.index', [
            'search' => 'Kaloum',
            'status' => 'active',
            'region' => 'Conakry',
            'per_page' => 1,
        ]))->assertOk()->assertSee('MATCH-035')->assertDontSee('OTHER-035');
    }

    public function test_existing_mosque_json_contract_is_preserved(): void
    {
        $superadmin = $this->actor('superadmin');
        $mosque = $this->mosque('JSON-035');

        $this->actingAs($superadmin)->getJson(route('admin.mosques.index'))
            ->assertOk()->assertJsonPath('data.0.code', 'JSON-035');
        $this->actingAs($superadmin)->getJson(route('admin.mosques.show', $mosque))
            ->assertOk()->assertJsonPath('code', 'JSON-035');
    }

    public function test_superadmin_can_open_create_form_and_create_a_mosque_from_html(): void
    {
        $superadmin = $this->actor('superadmin');
        $admin = $this->actor('admin');

        $this->actingAs($superadmin)->get(route('admin.mosques.create'))
            ->assertOk()->assertSee(__('Create mosque'))->assertSee($admin->name);

        $response = $this->actingAs($superadmin)->post(route('admin.mosques.store'), [
            'code' => 'CREATE-035',
            'name' => 'Mosquée créée',
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Kaloum',
            'status' => 'active',
            'admin_id' => $admin->id,
        ]);

        $mosque = Mosque::query()->where('code', 'CREATE-035')->firstOrFail();
        $response->assertRedirect(route('admin.mosques.show', $mosque));
        $this->assertDatabaseHas('mosque_user', [
            'mosque_id' => $mosque->id,
            'user_id' => $admin->id,
            'membership_type' => MosqueMembershipType::Administrator->value,
        ]);
    }

    public function test_local_admin_cannot_open_create_form_or_view_outside_mosque(): void
    {
        $admin = $this->actor('admin');
        $outside = $this->mosque('OUTSIDE-035');

        $this->actingAs($admin)->get(route('admin.mosques.create'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.mosques.show', $outside))->assertForbidden();
    }

    public function test_superadmin_can_open_prefilled_edit_form_and_update_from_html(): void
    {
        $superadmin = $this->actor('superadmin');
        $administrator = $this->actor('admin');
        $mosque = $this->mosque('EDIT-035', [
            'name' => 'Mosquée initiale',
            'address' => 'Ancienne adresse',
            'infrastructures' => ['Bibliothèque'],
        ]);

        $this->actingAs($superadmin)->get(route('admin.mosques.show', $mosque))
            ->assertOk()->assertSee(route('admin.mosques.edit', $mosque), false);
        $this->actingAs($superadmin)->get(route('admin.mosques.edit', $mosque))
            ->assertOk()
            ->assertSee('value="Mosquée initiale"', false)
            ->assertSee('Ancienne adresse')
            ->assertSee('Bibliothèque')
            ->assertSee($administrator->name);

        $response = $this->actingAs($superadmin)->patch(route('admin.mosques.update', $mosque), [
            'code' => 'EDIT-035',
            'name' => 'Mosquée modernisée',
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Kaloum',
            'address' => 'Nouvelle adresse',
            'latitude' => 9.5092,
            'longitude' => -13.7122,
            'phone' => '+224 600 00 00 00',
            'email' => 'mosquee@example.test',
            'status' => 'active',
            'infrastructures' => ['Bibliothèque', 'Salle de cours'],
            'admin_id' => $administrator->id,
        ]);

        $response->assertRedirect(route('admin.mosques.show', $mosque))
            ->assertSessionHas('success', __('Mosque updated successfully.'));
        $this->assertDatabaseHas('mosques', [
            'id' => $mosque->id,
            'name' => 'Mosquée modernisée',
            'admin_id' => $administrator->id,
        ]);
    }

    public function test_canonical_admin_can_edit_only_within_scope_and_cannot_change_primary_admin(): void
    {
        $admin = $this->actor('admin');
        $replacement = $this->actor('admin');
        $visible = $this->mosque('LOCAL-EDIT-035');
        $outside = $this->mosque('OUTSIDE-EDIT-035');
        $this->membership($admin, $visible);

        $this->actingAs($admin)->get(route('admin.mosques.edit', $visible))
            ->assertOk()
            ->assertDontSee('name="admin_id"', false);

        $this->actingAs($admin)->patch(route('admin.mosques.update', $visible), [
            'name' => 'Mise à jour locale',
            'admin_id' => $replacement->id,
        ])->assertRedirect(route('admin.mosques.show', $visible));

        $this->assertSame('Mise à jour locale', $visible->fresh()->name);
        $this->assertNull($visible->fresh()->admin_id);
        $this->actingAs($admin)->get(route('admin.mosques.edit', $outside))->assertForbidden();
        $this->actingAs($admin)->patch(route('admin.mosques.update', $outside), ['name' => 'Interdit'])->assertForbidden();
    }

    public function test_html_edit_validation_is_localized_and_json_update_contract_is_preserved(): void
    {
        $superadmin = $this->actor('superadmin');
        $mosque = $this->mosque('VALIDATE-035');

        foreach (['fr', 'en', 'ar'] as $locale) {
            $expectedMessage = __('This field is required.', [], $locale);
            $this->actingAs($superadmin)
                ->withSession(['locale' => $locale])
                ->from(route('admin.mosques.edit', $mosque))
                ->patch(route('admin.mosques.update', $mosque), ['name' => ''])
                ->assertRedirect(route('admin.mosques.edit', $mosque))
                ->assertSessionHasErrors(['name' => $expectedMessage]);
        }

        $this->actingAs($superadmin)->patchJson(route('admin.mosques.update', $mosque), [
            'name' => 'Updated through JSON',
        ])->assertOk()->assertJsonPath('name', 'Updated through JSON');
    }

    public function test_arabic_shell_uses_rtl_and_permission_aware_navigation(): void
    {
        $user = User::factory()->create(['locale' => 'ar']);
        $user->assignRole('user');

        $this->actingAs($user)->withSession(['locale' => 'ar'])->get(route('dashboard'))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee(route('admin.mosques.index'), false)
            ->assertDontSee(route('admin.accounts.index'), false);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function mosque(string $code, array $overrides = []): Mosque
    {
        return Mosque::query()->create(array_merge([
            'code' => $code,
            'name' => 'Mosquée '.$code,
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Ratoma',
            'status' => 'active',
        ], $overrides));
    }

    private function membership(User $user, Mosque $mosque): void
    {
        MosqueMembership::query()->create([
            'mosque_id' => $mosque->id,
            'user_id' => $user->id,
            'membership_type' => MosqueMembershipType::Administrator,
        ]);
    }
}
