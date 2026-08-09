<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_successful_login_is_logged_without_sensitive_data(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $log = AuditLog::query()->where('event', 'auth.login')->firstOrFail();

        $this->assertSame($user->id, $log->actor_id);
        $this->assertArrayNotHasKey('password', $log->metadata ?? []);
    }

    public function test_user_changes_are_logged_without_password_values(): void
    {
        $user = User::factory()->create();
        $user->update([
            'name' => 'Updated Name',
            'password' => 'new-secret-password',
        ]);

        $log = AuditLog::query()->where('event', 'user.updated')->firstOrFail();

        $this->assertSame('Updated Name', $log->metadata['changes']['name']);
        $this->assertArrayNotHasKey('password', $log->metadata['changes']);
    }

    public function test_superadmin_can_view_audit_log(): void
    {
        $superadmin = User::factory()->create();
        $superadmin->assignRole('superadmin');

        $this->actingAs($superadmin)
            ->getJson(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'last_page']);
    }

    public function test_admin_and_user_cannot_view_audit_log(): void
    {
        foreach (['admin', 'user'] as $role) {
            $account = User::factory()->create();
            $account->assignRole($role);

            $this->actingAs($account)
                ->getJson(route('admin.audit-logs.index'))
                ->assertForbidden();
        }
    }

    public function test_guest_cannot_view_audit_log(): void
    {
        $this->getJson(route('admin.audit-logs.index'))->assertUnauthorized();
    }
}
