<?php

namespace Tests\Feature\Account;

use App\Enums\AccountStatus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccountStatusMigrationTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.account_status_migration', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection('account_status_migration');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('account_status_migration');
        DB::setDefaultConnection($this->originalConnection);

        parent::tearDown();
    }

    public function test_migration_preserves_existing_accounts_as_active_and_rolls_back_cleanly(): void
    {
        DB::table('users')->insert([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => 'existing-hash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_08_10_000000_add_account_status_to_users_table.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumns('users', [
            'status',
            'activated_at',
            'suspended_at',
            'suspension_reason',
            'archived_at',
            'verification_required_at',
            'verification_exempt_until',
        ]));
        $this->assertSame(AccountStatus::Active->value, DB::table('users')->value('status'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('users', 'status'));
        $this->assertFalse(Schema::hasColumn('users', 'activated_at'));
        $this->assertFalse(Schema::hasColumn('users', 'suspended_at'));
        $this->assertFalse(Schema::hasColumn('users', 'suspension_reason'));
        $this->assertFalse(Schema::hasColumn('users', 'archived_at'));
        $this->assertFalse(Schema::hasColumn('users', 'verification_required_at'));
        $this->assertFalse(Schema::hasColumn('users', 'verification_exempt_until'));
        $this->assertSame('existing@example.com', DB::table('users')->value('email'));
    }
}
