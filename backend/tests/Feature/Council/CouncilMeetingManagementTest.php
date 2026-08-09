<?php

namespace Tests\Feature\Council;

use App\Models\CouncilMeeting;
use App\Models\CouncilMember;
use App\Models\Mosque;
use App\Models\MosqueCouncil;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouncilMeetingManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_admin_can_create_meeting_for_assigned_mosque(): void
    {
        [$admin, $council, $members] = $this->context();
        $this->actingAs($admin)->postJson(route('admin.council-meetings.store'), $this->payload($council, $members))
            ->assertCreated()->assertJsonPath('status', 'draft')->assertJsonCount(2, 'participants');
        $this->assertDatabaseHas('audit_logs', ['event' => 'council-meeting.created']);
    }

    public function test_admin_cannot_manage_another_mosques_meeting(): void
    {
        [$admin] = $this->context();
        [, $council, $members] = $this->context();
        $this->actingAs($admin)->postJson(route('admin.council-meetings.store'), $this->payload($council, $members))->assertForbidden();
    }

    public function test_participants_must_be_active_members_of_same_council(): void
    {
        [$admin, $council, $members] = $this->context();
        [, , $otherMembers] = $this->context();
        $payload = $this->payload($council, [$members[0], $otherMembers[0]]);
        $this->actingAs($admin)->postJson(route('admin.council-meetings.store'), $payload)->assertUnprocessable();
    }

    public function test_quorum_cannot_exceed_invited_members(): void
    {
        [$admin, $council, $members] = $this->context();
        $payload = $this->payload($council, $members);
        $payload['quorum_required'] = 3;
        $this->actingAs($admin)->postJson(route('admin.council-meetings.store'), $payload)->assertUnprocessable();
    }

    public function test_notice_can_be_sent_only_once(): void
    {
        [$admin, $council, $members] = $this->context();
        $meeting = $this->meeting($admin, $council, $members);
        $this->actingAs($admin)->postJson(route('admin.council-meetings.send-notice', $meeting))->assertOk()->assertJsonPath('status', 'convened');
        $this->actingAs($admin)->postJson(route('admin.council-meetings.send-notice', $meeting))->assertUnprocessable();
    }

    public function test_meeting_cannot_close_without_quorum(): void
    {
        [$admin, $council, $members] = $this->context();
        $meeting = $this->meeting($admin, $council, $members);
        $meeting->update(['status' => 'convened']);
        $participant = $meeting->participants()->first();
        $this->actingAs($admin)->postJson(route('admin.council-meetings.attendance', $meeting), ['participants' => [['id' => $participant->id, 'status' => 'present']]])->assertOk();
        $this->actingAs($admin)->postJson(route('admin.council-meetings.close', $meeting), ['minutes' => 'Compte rendu officiel.'])->assertUnprocessable();
    }

    public function test_completed_meeting_accepts_audited_decision_with_valid_votes(): void
    {
        [$admin, $council, $members] = $this->context();
        $meeting = $this->meeting($admin, $council, $members);
        $meeting->update(['status' => 'completed', 'minutes' => 'PV', 'held_at' => now()]);
        $this->actingAs($admin)->postJson(route('admin.council-meetings.decisions.store', $meeting), [
            'title' => 'Rénover la toiture', 'description' => 'Travaux validés.', 'outcome' => 'approved',
            'votes_for' => 0, 'votes_against' => 0, 'abstentions' => 0,
        ])->assertCreated()->assertJsonPath('outcome', 'approved');
        $this->assertDatabaseHas('audit_logs', ['event' => 'council-decision.created']);
    }

    public function test_member_sees_only_meetings_where_they_are_invited(): void
    {
        [$admin, $council, $members] = $this->context();
        $meeting = $this->meeting($admin, $council, $members);
        [, $otherCouncil, $otherMembers] = $this->context();
        $this->meeting($otherCouncil->mosque->administrator, $otherCouncil, $otherMembers);
        $this->actingAs($members[0]->user)->getJson(route('admin.council-meetings.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $meeting->id);
    }

    private function context(): array
    {
        static $n = 0;
        $n++;
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $mosque = Mosque::query()->create(['code' => 'MOS-M'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'name' => 'Mosquée '.$n, 'region' => 'Conakry', 'prefecture' => 'Conakry', 'commune' => 'Ratoma', 'status' => 'active', 'admin_id' => $admin->id]);
        $council = MosqueCouncil::query()->create(['mosque_id' => $mosque->id, 'name' => 'Conseil '.$n, 'mandate_start' => '2026-01-01', 'mandate_end' => '2030-01-01', 'status' => 'active']);
        $members = collect([1, 2])->map(function (int $i) use ($council): CouncilMember {
            $user = User::factory()->create();
            $user->assignRole('user');

            return CouncilMember::query()->create(['mosque_council_id' => $council->id, 'user_id' => $user->id, 'function' => $i === 1 ? 'imam' : 'secretary', 'responsibilities' => 'Conseil', 'started_at' => '2026-01-01', 'status' => 'active']);
        })->all();

        return [$admin, $council->load('mosque.administrator'), $members];
    }

    private function payload(MosqueCouncil $council, array $members): array
    {
        return ['mosque_council_id' => $council->id, 'title' => 'Réunion ordinaire', 'agenda' => 'Finances et activités', 'scheduled_at' => now()->addDay()->toDateTimeString(), 'quorum_required' => 2, 'participant_ids' => array_map(fn ($m) => $m->id, $members)];
    }

    private function meeting(User $admin, MosqueCouncil $council, array $members): CouncilMeeting
    {
        $response = $this->actingAs($admin)->postJson(route('admin.council-meetings.store'), $this->payload($council, $members))->assertCreated();

        return CouncilMeeting::query()->findOrFail($response->json('id'));
    }
}
