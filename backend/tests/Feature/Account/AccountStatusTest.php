<?php

namespace Tests\Feature\Account;

use App\Enums\AccountStatus;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_and_factory_defaults_create_active_accounts(): void
    {
        $modelDefault = User::query()->create([
            'name' => 'Database Default',
            'email' => 'database-default@example.com',
            'password' => 'password',
        ]);
        $factoryDefault = User::factory()->create();

        $this->assertSame(AccountStatus::Active, $modelDefault->status);
        $this->assertTrue($modelDefault->canAuthenticate());
        $this->assertSame(AccountStatus::Active, $factoryDefault->status);
        $this->assertNotNull($factoryDefault->activated_at);
    }

    public function test_status_is_cast_and_read_helpers_are_exclusive(): void
    {
        $user = User::factory()->make();
        $checks = [
            [AccountStatus::PendingEmail, 'isPendingEmail'],
            [AccountStatus::PendingApproval, 'isPendingApproval'],
            [AccountStatus::Active, 'isActive'],
            [AccountStatus::Suspended, 'isSuspended'],
            [AccountStatus::Archived, 'isArchived'],
        ];

        foreach ($checks as [$status, $expectedMethod]) {
            $user->status = $status->value;

            $this->assertSame($status, $user->status);

            foreach ($checks as [, $method]) {
                $this->assertSame($method === $expectedMethod, $user->{$method}());
            }

            $this->assertSame($status === AccountStatus::Active, $user->canAuthenticate());
        }
    }

    public function test_factory_can_create_each_non_active_account_state(): void
    {
        $pendingEmail = User::factory()->pendingEmail()->create();
        $pendingApproval = User::factory()->pendingApproval()->create();
        $suspended = User::factory()->suspended('Policy review')->create();
        $archived = User::factory()->archived()->create();

        $this->assertTrue($pendingEmail->isPendingEmail());
        $this->assertNull($pendingEmail->email_verified_at);
        $this->assertNull($pendingEmail->activated_at);
        $this->assertTrue($pendingApproval->isPendingApproval());
        $this->assertNull($pendingApproval->activated_at);
        $this->assertTrue($suspended->isSuspended());
        $this->assertNotNull($suspended->suspended_at);
        $this->assertSame('Policy review', $suspended->suspension_reason);
        $this->assertTrue($archived->isArchived());
        $this->assertNotNull($archived->archived_at);
    }

    public function test_account_state_fields_are_not_mass_assignable(): void
    {
        $user = new User;

        foreach ([
            'status',
            'activated_at',
            'suspended_at',
            'suspension_reason',
            'archived_at',
            'verification_required_at',
            'verification_exempt_until',
        ] as $attribute) {
            $this->assertFalse($user->isFillable($attribute), "{$attribute} must not be mass assignable.");
        }
    }

    public function test_password_cast_and_hidden_attributes_remain_secure(): void
    {
        $user = User::factory()->create([
            'password' => 'new-plain-text-password',
            'remember_token' => 'hidden-token',
        ]);

        $this->assertNotSame('new-plain-text-password', $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('new-plain-text-password', $user->getRawOriginal('password')));
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
    }

    public function test_status_changes_are_audited_without_authentication_secrets(): void
    {
        $user = User::factory()->create();

        $user->status = AccountStatus::Suspended;
        $user->suspended_at = now();
        $user->suspension_reason = 'Policy review';
        $user->save();

        $changes = AuditLog::query()
            ->where('event', 'user.updated')
            ->latest('id')
            ->firstOrFail()
            ->metadata['changes'];

        $this->assertSame(AccountStatus::Suspended->value, $changes['status']);
        $this->assertArrayNotHasKey('password', $changes);
        $this->assertArrayNotHasKey('remember_token', $changes);
        $this->assertArrayNotHasKey('token', $changes);
    }
}
