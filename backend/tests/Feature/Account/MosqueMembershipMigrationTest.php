<?php

namespace Tests\Feature\Account;

use App\Enums\MosqueMembershipType;
use App\Models\CouncilMember;
use App\Models\Faithful;
use App\Models\Mosque;
use App\Models\MosqueCouncil;
use App\Models\MosqueMembership;
use App\Models\User;
use App\Services\MosqueMembershipBackfillService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MosqueMembershipMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_table_has_required_columns_unique_pair_and_strict_type(): void
    {
        $this->assertTrue(Schema::hasColumns('mosque_user', [
            'mosque_id', 'user_id', 'membership_type', 'assigned_by', 'created_at', 'updated_at',
        ]));

        $user = User::factory()->create();
        $mosque = $this->mosque();
        MosqueMembership::query()->create([
            'mosque_id' => $mosque->id,
            'user_id' => $user->id,
            'membership_type' => MosqueMembershipType::Member,
        ]);

        try {
            DB::table('mosque_user')->insert([
                'mosque_id' => $mosque->id,
                'user_id' => $user->id,
                'membership_type' => 'member',
            ]);
            $this->fail('The unique mosque/user pair must be enforced.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('mosque_user')->insert([
                'mosque_id' => $this->mosque()->id,
                'user_id' => $user->id,
                'membership_type' => 'imam',
            ]);
            $this->fail('The membership type enum must reject religious functions.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_historical_sources_are_backfilled_with_priority_deduplication_and_idempotence(): void
    {
        $primary = User::factory()->create();
        $faithfulUser = User::factory()->create();
        $councilUser = User::factory()->create();
        $inactiveCouncilUser = User::factory()->create();
        $mosque = $this->mosque(['admin_id' => $primary->id]);

        Faithful::query()->create([
            'mosque_id' => $mosque->id,
            'user_id' => $faithfulUser->id,
            'registration_number' => 'REG-031',
            'first_name' => 'Faithful',
            'last_name' => 'Member',
            'joined_at' => now()->toDateString(),
            'status' => 'active',
            'consent_at' => now(),
        ]);
        Faithful::query()->create([
            'mosque_id' => $mosque->id,
            'registration_number' => 'REG-NULL-031',
            'first_name' => 'No',
            'last_name' => 'Account',
            'joined_at' => now()->toDateString(),
            'status' => 'active',
            'consent_at' => now(),
        ]);
        $council = MosqueCouncil::query()->create([
            'mosque_id' => $mosque->id,
            'name' => 'Council',
            'mandate_start' => now()->toDateString(),
            'mandate_end' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        CouncilMember::query()->create([
            'mosque_council_id' => $council->id,
            'user_id' => $councilUser->id,
            'function' => 'Imam',
            'started_at' => now()->toDateString(),
            'status' => 'active',
        ]);
        CouncilMember::query()->create([
            'mosque_council_id' => $council->id,
            'user_id' => $primary->id,
            'function' => 'Secretary',
            'started_at' => now()->toDateString(),
            'status' => 'active',
        ]);
        CouncilMember::query()->create([
            'mosque_council_id' => $council->id,
            'user_id' => $inactiveCouncilUser->id,
            'function' => 'Muezzin',
            'started_at' => now()->toDateString(),
            'status' => 'inactive',
        ]);

        $service = app(MosqueMembershipBackfillService::class);
        $service->run();
        $firstSnapshot = MosqueMembership::query()->orderBy('user_id')->get()->toArray();
        $service->run();

        $this->assertCount(3, $firstSnapshot);
        $this->assertSame($firstSnapshot, MosqueMembership::query()->orderBy('user_id')->get()->toArray());
        $this->assertDatabaseHas('mosque_user', [
            'mosque_id' => $mosque->id,
            'user_id' => $primary->id,
            'membership_type' => 'administrator',
            'assigned_by' => null,
        ]);
        $this->assertDatabaseHas('mosque_user', [
            'mosque_id' => $mosque->id,
            'user_id' => $faithfulUser->id,
            'membership_type' => 'member',
        ]);
        $this->assertDatabaseHas('mosque_user', [
            'mosque_id' => $mosque->id,
            'user_id' => $councilUser->id,
            'membership_type' => 'member',
        ]);
        $this->assertDatabaseMissing('mosque_user', ['user_id' => $inactiveCouncilUser->id]);
        $this->assertFalse($primary->roles()->exists());
        $this->assertSame('Imam', CouncilMember::query()->where('user_id', $councilUser->id)->value('function'));
    }

    private function mosque(array $attributes = []): Mosque
    {
        static $sequence = 0;
        $sequence++;

        return Mosque::query()->create(array_merge([
            'code' => 'MIG-031-'.$sequence,
            'name' => 'Migration Mosque '.$sequence,
            'region' => 'Region',
            'prefecture' => 'Prefecture',
            'commune' => 'Commune',
            'status' => 'active',
        ], $attributes));
    }
}
