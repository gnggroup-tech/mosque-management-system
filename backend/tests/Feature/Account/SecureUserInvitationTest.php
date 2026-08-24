<?php

namespace Tests\Feature\Account;

use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use App\Services\AuditLogger;
use App\Services\UserInvitationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SecureUserInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-24 12:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_invitation_permission_is_superadmin_only_and_seeded_idempotently(): void
    {
        $this->assertSame(45, Permission::query()->count());
        $this->assertTrue(Role::findByName('superadmin')->hasPermissionTo('users.invite'));
        $this->assertFalse(Role::findByName('admin')->hasPermissionTo('users.invite'));
        $this->assertFalse(Role::findByName('user')->hasPermissionTo('users.invite'));

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(45, Permission::query()->count());
        $this->assertSame(1, Permission::query()->where('name', 'users.invite')->count());
    }

    public function test_users_create_does_not_authorize_invitations_and_guest_or_inactive_actor_is_blocked(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->assertTrue($admin->can('users.create'));
        $this->assertFalse($admin->can('users.invite'));

        $payload = $this->validPayload();
        $this->post(route('admin.accounts.invitations.store'), $payload)->assertRedirect(route('login'));
        $this->actingAs($admin)->post(route('admin.accounts.invitations.store'), $payload)->assertForbidden();

        $inactive = User::factory()->suspended()->create();
        $inactive->givePermissionTo('users.invite');
        $this->actingAs($inactive)->post(route('admin.accounts.invitations.store'), $payload)
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
    }

    public function test_authorized_invitation_creates_only_a_restricted_account_and_stores_no_raw_secret(): void
    {
        $actor = $this->superadmin();
        $this->actingAs($actor)->post(route('admin.accounts.invitations.store'), [
            'name' => '  Invited   Person  ',
            'email' => '  INVITED@EXAMPLE.TEST ',
            'locale' => 'fr',
        ])->assertRedirect(route('admin.accounts.invitations.create'));

        $account = User::query()->where('email', 'invited@example.test')->sole();
        $invitation = $account->invitation()->sole();
        $token = $this->sentToken($account);

        $this->assertSame('Invited Person', $account->name);
        $this->assertSame(AccountStatus::PendingEmail, $account->status);
        $this->assertNull($account->email_verified_at);
        $this->assertNull($account->remember_token);
        $this->assertTrue($account->roles->isEmpty());
        $this->assertTrue($account->administeredMosques->isEmpty());
        $this->assertFalse(Hash::check($token, $account->password));
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame($actor->id, $invitation->invited_by);
        $this->assertSame(now()->addDay()->toIso8601String(), $invitation->expires_at->toIso8601String());

        $created = AuditLog::query()
            ->where('event', 'user.created')
            ->where('auditable_id', $account->id)
            ->sole();
        $this->assertSame([], $created->metadata);
        $audit = AuditLog::query()->where('event', 'user.invitation.created')->sole();
        $this->assertSame([
            'invitation_id' => $invitation->id,
            'target_user_id' => $account->id,
            'actor_id' => $actor->id,
            'occurred_at' => now()->toIso8601String(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ], $audit->metadata);
        $serializedAudits = AuditLog::query()->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsStringIgnoringCase('invited@example.test', $serializedAudits);
        $this->assertStringNotContainsString($token, $serializedAudits);
        $this->assertStringNotContainsStringIgnoringCase('password', $serializedAudits);
    }

    public function test_invitation_input_is_normalized_validated_and_cannot_assign_privileges(): void
    {
        $actor = $this->superadmin();
        $existing = User::factory()->create(['email' => 'existing@example.test']);

        $this->actingAs($actor)->post(route('admin.accounts.invitations.store'), [
            'name' => 'Invalid',
            'email' => 'EXISTING@EXAMPLE.TEST',
            'locale' => 'de',
            'status' => 'active',
            'role' => 'superadmin',
            'permissions' => ['users.invite'],
            'mosque_id' => 123,
        ])->assertSessionHasErrors(['email', 'locale', 'status', 'role', 'permissions', 'mosque_id']);

        $this->assertSame(AccountStatus::Active, $existing->refresh()->status);
        $this->assertSame(2, User::query()->count());
        $this->assertSame(0, UserInvitation::query()->count());
    }

    public function test_valid_acceptance_verifies_email_sets_password_and_moves_only_to_pending_approval(): void
    {
        [$account, $token] = $this->inviteAccount('ar');

        $this->get(route('invitations.show', $token))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="_method" value="PATCH"', false)
            ->assertDontSee($account->email)
            ->assertSee('x-bind:disabled="submitting"', false);

        $this->patch(route('invitations.update', $token), [
            'password' => 'Secure-password-30!',
            'password_confirmation' => 'Secure-password-30!',
        ])->assertRedirect(route('login'));

        $account->refresh();
        $this->assertSame(AccountStatus::PendingApproval, $account->status);
        $this->assertNotNull($account->email_verified_at);
        $this->assertTrue(Hash::check('Secure-password-30!', $account->password));
        $this->assertNotNull($account->invitation->accepted_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'user.status.transitioned',
            'auditable_id' => $account->id,
        ]);
        $acceptedAudit = AuditLog::query()->where('event', 'user.invitation.accepted')->sole();
        $this->assertNull($acceptedAudit->actor_id);
        $this->assertSame([
            'invitation_id' => $account->invitation->id,
            'target_user_id' => $account->id,
            'occurred_at' => now()->toIso8601String(),
        ], $acceptedAudit->metadata);

        $this->post('/login', ['email' => $account->email, 'password' => 'Secure-password-30!'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();

        $approver = $this->superadmin();
        $this->actingAs($approver)->patchJson(route('admin.accounts.approve', $account))->assertOk();
        auth()->logout();
        $this->post('/login', ['email' => $account->email, 'password' => 'Secure-password-30!'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($account);
    }

    public function test_expired_or_already_used_tokens_are_rejected_without_success_audit(): void
    {
        [$account, $token] = $this->inviteAccount();
        $account->invitation()->update(['expires_at' => now()->subMinute()]);
        AuditLog::query()->where('event', 'user.invitation.created')->delete();

        $this->get(route('invitations.show', $token))->assertNotFound();
        $this->patch(route('invitations.update', $token), [
            'password' => 'Secure-password-30!',
            'password_confirmation' => 'Secure-password-30!',
        ])->assertSessionHasErrors('invitation');
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.invitation.expired']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.invitation.accepted']);

        $account->invitation()->update(['expires_at' => now()->addHour()]);
        $this->patch(route('invitations.update', $token), [
            'password' => 'Secure-password-30!',
            'password_confirmation' => 'Secure-password-30!',
        ])->assertRedirect(route('login'));
        $this->patch(route('invitations.update', $token), [
            'password' => 'Another-password-30!',
            'password_confirmation' => 'Another-password-30!',
        ])->assertSessionHasErrors('invitation');
        $this->assertSame(1, AuditLog::query()->where('event', 'user.invitation.accepted')->count());
        $this->assertTrue(Hash::check('Secure-password-30!', $account->refresh()->password));
    }

    public function test_resend_rotates_token_and_is_refused_outside_pending_email(): void
    {
        [$account, $oldToken] = $this->inviteAccount();
        $oldHash = $account->invitation->token_hash;
        Notification::fake();

        $actor = $this->superadmin();
        $this->actingAs($actor)->post(route('admin.accounts.invitations.resend', $account))->assertRedirect();
        $newToken = $this->sentToken($account);
        auth()->logout();

        $this->assertNotSame($oldToken, $newToken);
        $this->assertNotSame($oldHash, $account->invitation->refresh()->token_hash);
        $this->get(route('invitations.show', $oldToken))->assertNotFound();
        $this->get(route('invitations.show', $newToken))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.invitation.resent']);

        foreach ([AccountStatus::Suspended, AccountStatus::Archived] as $status) {
            $account->forceFill(['status' => $status])->save();
            $this->actingAs($actor)->post(route('admin.accounts.invitations.resend', $account))
                ->assertSessionHasErrors('invitation');
        }
    }

    public function test_acceptance_rejects_identity_changes_and_enforces_password_confirmation(): void
    {
        [$account, $token] = $this->inviteAccount();

        $this->patch(route('invitations.update', $token), [
            'name' => 'Changed name',
            'email' => 'changed@example.test',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['name', 'email', 'password']);

        $account->refresh();
        $this->assertNotSame('Changed name', $account->name);
        $this->assertNotSame('changed@example.test', $account->email);
        $this->assertSame(AccountStatus::PendingEmail, $account->status);
        $this->assertNull($account->invitation->accepted_at);
    }

    public function test_invitation_operations_roll_back_when_audit_logging_fails(): void
    {
        $actor = $this->superadmin();
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(AuditLogger::class, $logger);

        try {
            app(UserInvitationService::class)->invite($this->validPayload(), $actor);
            $this->fail('The audit failure should have been propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'invited@example.test']);
        $this->assertSame(0, UserInvitation::query()->count());
    }

    public function test_acceptance_rolls_back_every_account_change_when_the_business_audit_fails(): void
    {
        [$account, $token] = $this->inviteAccount();
        $originalPassword = $account->password;
        $logger = Mockery::mock(AuditLogger::class);
        $logger->shouldReceive('log')->andReturnUsing(
            function (string $event): AuditLog {
                if ($event === 'user.invitation.accepted') {
                    throw new RuntimeException('acceptance audit unavailable');
                }

                return new AuditLog;
            },
        );
        $this->app->instance(AuditLogger::class, $logger);

        try {
            app(UserInvitationService::class)->accept($token, 'Secure-password-30!');
            $this->fail('The acceptance audit failure should have been propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('acceptance audit unavailable', $exception->getMessage());
        }

        $account->refresh();
        $this->assertSame(AccountStatus::PendingEmail, $account->status);
        $this->assertNull($account->email_verified_at);
        $this->assertSame($originalPassword, $account->password);
        $this->assertNull($account->invitation->accepted_at);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.invitation.accepted']);
    }

    public function test_public_invitation_routes_are_guest_throttled_localized_and_csrf_protected(): void
    {
        [$account, $token] = $this->inviteAccount('fr');

        foreach (['invitations.show', 'invitations.update'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('web', $middleware);
            $this->assertContains('guest', $middleware);
            $this->assertContains('throttle:6,1', $middleware);
            $this->assertContains('invitation.locale', $middleware);
        }

        $this->get(route('invitations.show', $token))
            ->assertOk()
            ->assertSee('Accepter votre invitation')
            ->assertSee('dir="ltr"', false);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->get(route('invitations.show', $token))->assertOk();
        }
        $this->get(route('invitations.show', $token))->assertTooManyRequests();
        $this->assertSame(AccountStatus::PendingEmail, $account->refresh()->status);
    }

    /** @return array{User, string} */
    private function inviteAccount(string $locale = 'en'): array
    {
        $actor = $this->superadmin();
        $this->actingAs($actor)->post(route('admin.accounts.invitations.store'), [
            ...$this->validPayload(),
            'locale' => $locale,
        ])->assertRedirect(route('admin.accounts.invitations.create'));
        auth()->logout();

        $account = User::query()->where('email', 'invited@example.test')->sole();

        return [$account, $this->sentToken($account)];
    }

    private function sentToken(User $account): string
    {
        $token = null;
        Notification::assertSentTo(
            $account,
            UserInvitationNotification::class,
            function (UserInvitationNotification $notification) use (&$token): bool {
                $token = $notification->token;

                return true;
            },
        );

        $this->assertIsString($token);

        return $token;
    }

    private function superadmin(): User
    {
        $actor = User::factory()->create();
        $actor->assignRole('superadmin');

        return $actor;
    }

    private function validPayload(): array
    {
        return [
            'name' => 'Invited Person',
            'email' => 'invited@example.test',
            'locale' => 'en',
        ];
    }
}
