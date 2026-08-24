<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DatabaseSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_no_user_and_initializes_canonical_rbac(): void
    {
        $this->assertSame(Command::SUCCESS, Artisan::call('db:seed', [
            '--class' => DatabaseSeeder::class,
            '--force' => true,
        ]));

        $this->assertSame(0, User::query()->count());
        $this->assertSame($this->expectedRoles(), Role::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame($this->expectedPermissions(), Permission::query()->orderBy('name')->pluck('name')->all());
        $this->assertCount(3, $this->expectedRoles());
        $this->assertCount(45, $this->expectedPermissions());
    }

    public function test_database_seeder_creates_no_user_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->assertSame(Command::SUCCESS, Artisan::call('db:seed', [
            '--class' => DatabaseSeeder::class,
            '--force' => true,
        ]));

        $this->assertTrue(app()->environment('production'));
        $this->assertSame(0, User::query()->count());
        $this->assertSame(3, Role::query()->count());
        $this->assertSame(45, Permission::query()->count());
    }

    public function test_database_seeder_is_idempotent_and_preserves_exact_role_assignments(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::query()->count());
        $this->assertSame(3, Role::query()->count());
        $this->assertSame(45, Permission::query()->count());

        foreach (config('permissions.roles') as $roleName => $permissions) {
            $actual = Role::findByName($roleName)->permissions()->pluck('name')->sort()->values()->all();
            $expected = collect($permissions)->sort()->values()->all();

            $this->assertSame($expected, $actual, "Unexpected permissions for role {$roleName}");
        }
    }

    /** @return list<string> */
    private function expectedRoles(): array
    {
        return collect(array_keys(config('permissions.roles')))->sort()->values()->all();
    }

    /** @return list<string> */
    private function expectedPermissions(): array
    {
        return collect(config('permissions.all'))->sort()->values()->all();
    }
}
