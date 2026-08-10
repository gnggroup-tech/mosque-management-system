<?php

namespace Tests\Feature\Account;

use App\Enums\AccountStatus;
use App\Exceptions\AccountStatusTransitionException;
use App\Models\AuditLog;
use App\Models\Mosque;
use App\Models\User;
use App\Services\AccountStatusTransitionService;
use App\Services\AuditLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AccountStatusTransitionServiceTest extends TestCase
{
    use RefreshDatabase;

    private AccountStatusTransitionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-10 12:00:00');
        $this->service = app(AccountStatusTransitionService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_pending_email_can_become_pending_approval_without_lifecycle_dates(): void
    {
        $account = $this->account(AccountStatus::PendingEmail);

        $result = $this->service->transition($account, AccountStatus::PendingApproval, $this->actor());

        $this->assertTrue($result->isPendingApproval());
        $this->assertDatabaseHas('users', ['id' => $account->id, 'status' => 'pending_approval']);
        $this->assertNull($result->activated_at);
        $this->assertNull($result->suspended_at);
        $this->assertNull($result->archived_at);
    }

    public function test_pending_approval_can_become_active_and_receives_activation_date(): void
    {
        $account = $this->account(AccountStatus::PendingApproval);
        $account->forceFill([
            'activated_at' => now()->subYear(),
            'suspended_at' => now()->subDay(),
            'suspension_reason' => 'Stale state',
        ])->saveQuietly();

        $result = $this->service->transition($account, AccountStatus::Active, $this->actor());

        $this->assertTrue($result->isActive());
        $this->assertTrue($result->activated_at->equalTo(now()));
        $this->assertNull($result->suspended_at);
        $this->assertNull($result->suspension_reason);
        $this->assertDatabaseHas('users', [
            'id' => $account->id,
            'status' => 'active',
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);
    }

    public function test_active_can_be_suspended_with_normalized_reason_and_frozen_time(): void
    {
        $account = $this->account(AccountStatus::Active);
        $activatedAt = $account->activated_at;

        $result = $this->service->transition(
            $account,
            AccountStatus::Suspended,
            $this->actor(),
            "  Repeated\n policy   violation  ",
        );

        $this->assertTrue($result->isSuspended());
        $this->assertSame('Repeated policy violation', $result->suspension_reason);
        $this->assertTrue($result->suspended_at->equalTo(now()));
        $this->assertTrue($result->activated_at->equalTo($activatedAt));
        $this->assertNull($result->archived_at);
        $this->assertDatabaseHas('users', [
            'id' => $account->id,
            'status' => 'suspended',
            'suspension_reason' => 'Repeated policy violation',
        ]);
    }

    public function test_suspension_requires_a_non_blank_reason(): void
    {
        foreach ([null, '', " \t\n "] as $reason) {
            $account = $this->account(AccountStatus::Active);
            $before = $this->lifecycleValues($account);

            try {
                $this->service->transition($account, AccountStatus::Suspended, $this->actor(), $reason);
                $this->fail('A blank suspension reason should have been rejected.');
            } catch (AccountStatusTransitionException $exception) {
                $this->assertSame('A suspension reason is required.', $exception->getMessage());
            }

            $this->assertSame($before, $this->lifecycleValues($account->refresh()));
        }

        $this->assertSame(0, AuditLog::query()->where('event', 'user.status.transitioned')->count());
    }

    public function test_suspension_reason_cannot_exceed_text_column_capacity(): void
    {
        $account = $this->account(AccountStatus::Active);

        $this->expectException(AccountStatusTransitionException::class);
        $this->expectExceptionMessage('The suspension reason exceeds the supported length.');

        $this->service->transition(
            $account,
            AccountStatus::Suspended,
            $this->actor(),
            str_repeat('a', 65536),
        );
    }

    public function test_suspension_reason_limit_is_enforced_in_bytes_for_multibyte_text(): void
    {
        $account = $this->account(AccountStatus::Active);
        $reason = str_repeat('é', 32768);

        $this->assertSame(65536, strlen($reason));
        $this->assertSame(32768, mb_strlen($reason));

        $this->expectException(AccountStatusTransitionException::class);
        $this->expectExceptionMessage('The suspension reason exceeds the supported length.');

        $this->service->transition(
            $account,
            AccountStatus::Suspended,
            $this->actor(),
            $reason,
        );
    }

    public function test_suspended_can_be_reactivated_and_clears_suspension_details(): void
    {
        $account = $this->account(AccountStatus::Suspended);
        $activatedAt = $account->activated_at;

        $result = $this->service->transition($account, AccountStatus::Active, $this->actor());

        $this->assertTrue($result->isActive());
        $this->assertTrue($result->activated_at->equalTo($activatedAt));
        $this->assertNull($result->suspended_at);
        $this->assertNull($result->suspension_reason);
        $this->assertNull($result->archived_at);
        $this->assertDatabaseHas('users', [
            'id' => $account->id,
            'status' => 'active',
            'suspended_at' => null,
            'suspension_reason' => null,
        ]);
    }

    public function test_reactivation_sets_activation_date_when_legacy_value_is_missing(): void
    {
        $account = $this->account(AccountStatus::Suspended);
        $account->forceFill(['activated_at' => null])->saveQuietly();

        $result = $this->service->transition($account, AccountStatus::Active, $this->actor());

        $this->assertTrue($result->activated_at->equalTo(now()));
        $this->assertDatabaseHas('users', ['id' => $account->id, 'status' => 'active']);
    }

    public function test_active_can_be_archived_without_physical_deletion(): void
    {
        $account = $this->account(AccountStatus::Active);
        $activatedAt = $account->activated_at;

        $result = $this->service->transition($account, AccountStatus::Archived, $this->actor());

        $this->assertTrue($result->isArchived());
        $this->assertTrue($result->archived_at->equalTo(now()));
        $this->assertTrue($result->activated_at->equalTo($activatedAt));
        $this->assertDatabaseHas('users', ['id' => $account->id, 'status' => 'archived']);
        $this->assertNotNull(User::query()->find($account->id));
    }

    public function test_suspended_can_be_archived_and_keeps_suspension_history(): void
    {
        $account = $this->account(AccountStatus::Suspended);
        $suspendedAt = $account->suspended_at;
        $reason = $account->suspension_reason;

        $result = $this->service->transition($account, AccountStatus::Archived, $this->actor());

        $this->assertTrue($result->isArchived());
        $this->assertTrue($result->archived_at->equalTo(now()));
        $this->assertTrue($result->suspended_at->equalTo($suspendedAt));
        $this->assertSame($reason, $result->suspension_reason);
        $this->assertDatabaseHas('users', ['id' => $account->id, 'status' => 'archived']);
    }

    public function test_transition_matrix_is_exact_and_archived_is_terminal(): void
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
                $account = $this->account($from);
                $transition = "{$from->value}:{$to->value}";

                try {
                    $result = $this->service->transition(
                        $account,
                        $to,
                        $this->actor(),
                        $to === AccountStatus::Suspended ? 'Policy review' : null,
                    );
                    $this->assertContains($transition, $allowed);
                    $this->assertSame($to, $result->status);
                    $this->assertDatabaseHas('users', ['id' => $account->id, 'status' => $to->value]);
                } catch (AccountStatusTransitionException) {
                    $this->assertNotContains($transition, $allowed);
                    $this->assertSame($from, $account->refresh()->status);
                    $this->assertDatabaseHas('users', ['id' => $account->id, 'status' => $from->value]);
                }
            }
        }
    }

    public function test_rejected_transition_changes_nothing_and_creates_no_success_audit(): void
    {
        $account = $this->account(AccountStatus::PendingEmail);
        $before = $account->refresh()->getRawOriginal();
        AuditLog::query()->delete();

        try {
            $this->service->transition($account, AccountStatus::Active, $this->actor());
            $this->fail('The forbidden transition should have failed.');
        } catch (AccountStatusTransitionException $exception) {
            $this->assertSame(
                'Account status transition from pending_email to active is not allowed.',
                $exception->getMessage(),
            );
        }

        $this->assertSame($before, $account->refresh()->getRawOriginal());
        $this->assertDatabaseHas('users', ['id' => $account->id, 'status' => 'pending_email']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.status.transitioned']);
    }

    public function test_each_successful_transition_has_distinct_business_audit(): void
    {
        $actor = $this->actor();
        $transitions = [
            [AccountStatus::PendingEmail, AccountStatus::PendingApproval, null],
            [AccountStatus::PendingApproval, AccountStatus::Active, null],
            [AccountStatus::Active, AccountStatus::Suspended, 'Policy review'],
            [AccountStatus::Suspended, AccountStatus::Active, null],
            [AccountStatus::Active, AccountStatus::Archived, null],
            [AccountStatus::Suspended, AccountStatus::Archived, null],
        ];
        AuditLog::query()->delete();

        foreach ($transitions as [$from, $to, $reason]) {
            $account = $this->account($from);
            $this->service->transition($account, $to, $actor, $reason);

            $audit = AuditLog::query()
                ->where('event', 'user.status.transitioned')
                ->where('auditable_id', $account->id)
                ->firstOrFail();

            $this->assertSame($actor->id, $audit->actor_id);
            $this->assertSame($account->id, $audit->metadata['target_user_id']);
            $this->assertSame($from->value, $audit->metadata['from_status']);
            $this->assertSame($to->value, $audit->metadata['to_status']);
            $this->assertSame(now()->toIso8601String(), $audit->metadata['occurred_at']);
            if ($to === AccountStatus::Suspended || $from === AccountStatus::Suspended) {
                $this->assertArrayHasKey('suspension_reason', $audit->metadata);
            }
            $this->assertArrayNotHasKey('password', $audit->metadata);
            $this->assertArrayNotHasKey('remember_token', $audit->metadata);
            $this->assertArrayNotHasKey('token', $audit->metadata);
        }

        $this->assertSame(6, AuditLog::query()->where('event', 'user.status.transitioned')->count());
        $this->assertSame(6, AuditLog::query()->where('event', 'user.updated')->count());
    }

    public function test_transition_preserves_authentication_and_verification_data(): void
    {
        $account = $this->account(AccountStatus::Active);
        $account->forceFill([
            'email_verified_at' => now()->subDays(3),
            'verification_required_at' => now()->subDays(2),
            'verification_exempt_until' => now()->addDays(5),
            'remember_token' => 'private-remember-token',
        ])->saveQuietly();
        $columns = [
            'email_verified_at',
            'verification_required_at',
            'verification_exempt_until',
            'password',
            'remember_token',
        ];
        $before = $this->rawValues($account, $columns);

        $this->service->transition($account, AccountStatus::Suspended, $this->actor(), 'Policy review');

        $this->assertSame($before, $this->rawValues($account->refresh(), $columns));
    }

    public function test_transition_preserves_roles_permissions_and_mosque_attachment(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $account = $this->account(AccountStatus::Active);
        $account->assignRole('admin');
        $mosque = Mosque::query()->create([
            'code' => 'TASK-024',
            'name' => 'Transition Mosque',
            'region' => 'Conakry',
            'prefecture' => 'Conakry',
            'commune' => 'Ratoma',
            'admin_id' => $account->id,
        ]);
        $roles = $account->getRoleNames()->sort()->values()->all();
        $permissions = $account->getAllPermissions()->pluck('name')->sort()->values()->all();

        $this->service->transition($account, AccountStatus::Suspended, $this->actor(), 'Policy review');

        $account->refresh();
        $this->assertSame($roles, $account->getRoleNames()->sort()->values()->all());
        $this->assertSame($permissions, $account->getAllPermissions()->pluck('name')->sort()->values()->all());
        $this->assertSame($account->id, $mosque->refresh()->admin_id);
    }

    public function test_transition_is_rolled_back_when_business_audit_fails(): void
    {
        $account = $this->account(AccountStatus::Active);
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('log')->once()->andThrow(new RuntimeException('Audit unavailable.'));
        $service = new AccountStatusTransitionService($audit);
        AuditLog::query()->delete();

        try {
            $service->transition($account, AccountStatus::Suspended, $this->actor(), 'Policy review');
            $this->fail('The audit failure should have rolled back the transition.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable.', $exception->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $account->id, 'status' => 'active']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.updated']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.status.transitioned']);
    }

    public function test_unpersisted_accounts_and_actors_are_rejected(): void
    {
        $persisted = $this->account(AccountStatus::Active);

        foreach (
            [
                [new User, AccountStatus::Suspended, $this->actor(), 'Policy review'],
                [$persisted, AccountStatus::Suspended, new User, 'Policy review'],
            ] as [$account, $target, $actor, $reason]
        ) {
            try {
                $this->service->transition($account, $target, $actor, $reason);
                $this->fail('Unpersisted transition participants should be rejected.');
            } catch (AccountStatusTransitionException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertDatabaseHas('users', ['id' => $persisted->id, 'status' => 'active']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'user.status.transitioned']);
    }

    public function test_can_authenticate_remains_true_only_for_active(): void
    {
        foreach (AccountStatus::cases() as $status) {
            $account = $this->account($status);

            $this->assertSame($status === AccountStatus::Active, $account->refresh()->canAuthenticate());
        }
    }

    private function actor(): User
    {
        return User::factory()->create();
    }

    private function account(AccountStatus $status): User
    {
        return User::factory()->create([
            'status' => $status,
            'activated_at' => in_array($status, [AccountStatus::Active, AccountStatus::Suspended, AccountStatus::Archived], true)
                ? now()->subMonth()
                : null,
            'suspended_at' => $status === AccountStatus::Suspended ? now()->subDay() : null,
            'suspension_reason' => $status === AccountStatus::Suspended ? 'Existing suspension' : null,
            'archived_at' => $status === AccountStatus::Archived ? now()->subHour() : null,
        ]);
    }

    private function lifecycleValues(User $account): array
    {
        return $this->rawValues($account, [
            'status',
            'activated_at',
            'suspended_at',
            'suspension_reason',
            'archived_at',
            'updated_at',
        ]);
    }

    private function rawValues(User $account, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            $values[$column] = $account->getRawOriginal($column);
        }

        return $values;
    }
}
