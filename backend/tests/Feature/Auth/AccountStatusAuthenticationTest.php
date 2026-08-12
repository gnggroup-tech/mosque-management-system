<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\Mosque;
use App\Models\User;
use App\Services\AccountStatusTransitionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountStatusAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-12 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_active_account_can_authenticate_and_keep_an_existing_session(): void
    {
        $account = User::factory()->create();

        $this->post('/login', [
            'email' => $account->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($account);
        $this->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($account);
    }

    public function test_non_active_accounts_cannot_authenticate_and_receive_a_generic_response(): void
    {
        foreach ($this->nonActiveStatuses() as $status) {
            $account = $this->accountWithStatus($status);
            $rememberToken = $account->remember_token;

            $response = $this->post('/login', [
                'email' => $account->email,
                'password' => 'password',
                'remember' => true,
            ]);

            $this->assertGuest();
            $response->assertSessionHasErrors([
                'email' => trans('auth.failed'),
            ]);
            $this->assertSame($rememberToken, $account->refresh()->remember_token);
            $this->assertDatabaseMissing('audit_logs', [
                'event' => 'user.authentication.revoked',
                'auditable_id' => $account->id,
            ]);
        }
    }

    public function test_invalid_credentials_and_non_active_account_use_the_same_external_error(): void
    {
        $account = $this->accountWithStatus(AccountStatus::Suspended);

        $inactiveResponse = $this->from('/login')->post('/login', [
            'email' => $account->email,
            'password' => 'password',
        ]);
        $invalidResponse = $this->from('/login')->post('/login', [
            'email' => 'missing@example.com',
            'password' => 'password',
        ]);

        $inactiveResponse->assertRedirect('/login');
        $invalidResponse->assertRedirect('/login');
        $inactiveResponse->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $invalidResponse->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.authentication.revoked']);
    }

    public function test_refused_login_preserves_roles_permissions_and_mosque_relationships(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $account = $this->accountWithStatus(AccountStatus::Suspended);
        $account->assignRole('admin');
        $mosque = Mosque::query()->create([
            'code' => 'TASK-025',
            'name' => 'Authentication Mosque',
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Ratoma',
            'admin_id' => $account->id,
        ]);
        $roles = $account->getRoleNames()->sort()->values()->all();
        $permissions = $account->getAllPermissions()->pluck('name')->sort()->values()->all();

        $this->post('/login', [
            'email' => $account->email,
            'password' => 'password',
        ]);

        $account->refresh();
        $this->assertGuest();
        $this->assertSame($roles, $account->getRoleNames()->sort()->values()->all());
        $this->assertSame($permissions, $account->getAllPermissions()->pluck('name')->sort()->values()->all());
        $this->assertSame($account->id, $mosque->refresh()->admin_id);
    }

    public function test_existing_session_is_revoked_after_suspension(): void
    {
        $account = User::factory()->create();
        $actor = User::factory()->create();
        $this->actingAs($account);

        app(AccountStatusTransitionService::class)->transition(
            $account,
            AccountStatus::Suspended,
            $actor,
            'Policy review',
        );

        $this->assertSessionIsRevoked($account, AccountStatus::Suspended);
    }

    public function test_existing_sessions_for_each_other_non_active_status_are_revoked(): void
    {
        foreach ([AccountStatus::PendingEmail, AccountStatus::PendingApproval, AccountStatus::Archived] as $status) {
            $account = $this->accountWithStatus($status);
            $this->actingAs($account);

            $this->assertSessionIsRevoked($account, $status);
        }
    }

    public function test_revocation_invalidates_session_regenerates_csrf_and_audits_minimal_context(): void
    {
        $account = $this->accountWithStatus(AccountStatus::Suspended);
        $rememberToken = $account->remember_token;
        AuditLog::query()->delete();

        $this
            ->withSession(['_token' => 'original-csrf-token', 'private' => 'session-secret'])
            ->actingAs($account);
        $originalSessionId = session()->getId();

        $response = $this->get('/dashboard');

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $response->assertSessionMissing('private');
        $this->assertNotSame($originalSessionId, session()->getId());
        $this->assertNotSame('original-csrf-token', session()->token());
        $this->assertGuest();
        $this->assertSame($rememberToken, $account->refresh()->remember_token);

        $audit = AuditLog::query()->where('event', 'user.authentication.revoked')->sole();
        $this->assertSame($account->id, $audit->actor_id);
        $this->assertSame($account->id, $audit->auditable_id);
        $this->assertSame([
            'user_id' => $account->id,
            'status' => AccountStatus::Suspended->value,
            'occurred_at' => now()->toIso8601String(),
            'reason' => 'account_not_active',
        ], $audit->metadata);
        $this->assertArrayNotHasKey('password', $audit->metadata);
        $this->assertArrayNotHasKey('remember_token', $audit->metadata);
        $this->assertArrayNotHasKey('session', $audit->metadata);
        $this->assertArrayNotHasKey('token', $audit->metadata);
    }

    public function test_public_route_remains_accessible_to_non_active_account(): void
    {
        $account = $this->accountWithStatus(AccountStatus::Suspended);

        $this->actingAs($account)->get('/')->assertOk();

        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'user.authentication.revoked',
            'auditable_id' => $account->id,
        ]);
    }

    public function test_reactivation_allows_normal_authentication_again_without_changing_transition_matrix(): void
    {
        $account = $this->accountWithStatus(AccountStatus::Suspended);
        $actor = User::factory()->create();

        app(AccountStatusTransitionService::class)->transition($account, AccountStatus::Active, $actor);

        $this->post('/login', [
            'email' => $account->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($account);
        $this->assertFalse(AccountStatus::Archived->canTransitionTo(AccountStatus::Active));
        $this->assertFalse(AccountStatus::PendingEmail->canTransitionTo(AccountStatus::Active));
    }

    private function assertSessionIsRevoked(User $account, AccountStatus $status): void
    {
        $this->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);

        $this->assertGuest();
        $this->assertDatabaseHas('users', [
            'id' => $account->id,
            'status' => $status->value,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.authentication.revoked',
            'actor_id' => $account->id,
            'auditable_id' => $account->id,
        ]);
    }

    private function accountWithStatus(AccountStatus $status): User
    {
        return match ($status) {
            AccountStatus::PendingEmail => User::factory()->pendingEmail()->create(),
            AccountStatus::PendingApproval => User::factory()->pendingApproval()->create(),
            AccountStatus::Suspended => User::factory()->suspended()->create(),
            AccountStatus::Archived => User::factory()->archived()->create(),
            AccountStatus::Active => User::factory()->create(),
        };
    }

    /** @return list<AccountStatus> */
    private function nonActiveStatuses(): array
    {
        return [
            AccountStatus::PendingEmail,
            AccountStatus::PendingApproval,
            AccountStatus::Suspended,
            AccountStatus::Archived,
        ];
    }
}
