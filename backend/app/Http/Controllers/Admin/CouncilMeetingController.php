<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Council\StoreCouncilDecisionRequest;
use App\Http\Requests\Council\StoreCouncilMeetingRequest;
use App\Models\CouncilMeeting;
use App\Models\CouncilMember;
use App\Models\MosqueCouncil;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CouncilMeetingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->visibleTo($request->user())
            ->with('council.mosque:id,code,name')->withCount(['participants', 'decisions'])
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('council_id'), fn (Builder $q) => $q->where('mosque_council_id', $request->integer('council_id')))
            ->orderByDesc('scheduled_at')->paginate(min(max($request->integer('per_page', 20), 1), 100)));
    }

    public function store(StoreCouncilMeetingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $participantIds = $data['participant_ids'];
        unset($data['participant_ids']);
        $council = MosqueCouncil::query()->with('mosque')->findOrFail($data['mosque_council_id']);
        $this->ensureManageable($request->user(), $council);
        abort_unless($council->status === 'active', 422, 'Le conseil doit être actif.');
        abort_if($data['quorum_required'] > count($participantIds), 422, 'Le quorum dépasse le nombre de participants.');
        $valid = CouncilMember::query()->where('mosque_council_id', $council->id)->where('status', 'active')->whereIn('id', $participantIds)->count();
        abort_if($valid !== count($participantIds), 422, 'Tous les participants doivent être membres actifs du conseil.');

        $meeting = DB::transaction(function () use ($data, $participantIds, $request): CouncilMeeting {
            $meeting = CouncilMeeting::query()->create($data + ['status' => 'draft', 'created_by' => $request->user()->id]);
            $meeting->participants()->createMany(array_map(fn (int $id) => ['council_member_id' => $id], $participantIds));

            return $meeting;
        });

        return response()->json($meeting->load('participants.member.user:id,name,email'), 201);
    }

    public function show(Request $request, CouncilMeeting $meeting): JsonResponse
    {
        abort_unless($this->visibleTo($request->user())->whereKey($meeting)->exists(), 403);

        return response()->json($meeting->load(['council.mosque:id,code,name', 'participants.member.user:id,name,email', 'decisions.responsible:id,name,email']));
    }

    public function sendNotice(Request $request, CouncilMeeting $meeting): JsonResponse
    {
        $this->ensureMeetingManageable($request->user(), $meeting);
        abort_unless($meeting->status === 'draft', 422, 'La convocation a déjà été envoyée.');
        $meeting->update(['status' => 'convened', 'notice_sent_at' => now()]);

        return response()->json($meeting->fresh());
    }

    public function recordAttendance(Request $request, CouncilMeeting $meeting): JsonResponse
    {
        $this->ensureMeetingManageable($request->user(), $meeting);
        $data = $request->validate(['participants' => ['required', 'array'], 'participants.*.id' => ['required', 'integer'], 'participants.*.status' => ['required', 'in:present,absent,excused']]);
        foreach ($data['participants'] as $participant) {
            $updated = $meeting->participants()->whereKey($participant['id'])->update(['attendance_status' => $participant['status'], 'responded_at' => now()]);
            abort_if($updated === 0, 422, 'Participant invalide.');
        }

        return response()->json($meeting->fresh()->load('participants'));
    }

    public function close(Request $request, CouncilMeeting $meeting): JsonResponse
    {
        $this->ensureMeetingManageable($request->user(), $meeting);
        $data = $request->validate(['minutes' => ['required', 'string', 'max:30000']]);
        abort_unless($meeting->status === 'convened', 422, 'La réunion doit avoir été convoquée.');
        abort_if($meeting->participants()->where('attendance_status', 'present')->count() < $meeting->quorum_required, 422, 'Le quorum n’est pas atteint.');
        $meeting->update(['status' => 'completed', 'minutes' => $data['minutes'], 'held_at' => now()]);

        return response()->json($meeting->fresh());
    }

    public function addDecision(StoreCouncilDecisionRequest $request, CouncilMeeting $meeting): JsonResponse
    {
        $this->ensureMeetingManageable($request->user(), $meeting);
        abort_unless($meeting->status === 'completed', 422, 'Le procès-verbal doit être clôturé avant les décisions.');
        $data = $request->validated();
        $present = $meeting->participants()->where('attendance_status', 'present')->count();
        abort_if($present < $data['votes_for'] + $data['votes_against'] + $data['abstentions'], 422, 'Le nombre de votes dépasse les présences.');
        $data['reference'] = 'DEC-'.$meeting->id.'-'.Str::upper(Str::random(8));

        return response()->json($meeting->decisions()->create($data), 201);
    }

    private function visibleTo(User $user): Builder
    {
        if ($user->hasRole('superadmin')) {
            return CouncilMeeting::query();
        }

        if ($user->hasRole('admin')) {
            return CouncilMeeting::query()->whereHas('council.mosque', fn (Builder $mosques) => $mosques->administrableBy($user));
        }

        if ($user->hasRole('user')) {
            return CouncilMeeting::query()->whereHas('participants.member', fn (Builder $members) => $members->where('user_id', $user->id));
        }

        return CouncilMeeting::query()->whereRaw('1 = 0');
    }

    private function ensureMeetingManageable(User $user, CouncilMeeting $meeting): void
    {
        $this->ensureManageable($user, $meeting->council()->with('mosque')->firstOrFail());
    }

    private function ensureManageable(User $user, MosqueCouncil $council): void
    {
        abort_unless($user->hasRole('superadmin') || $user->canAdministerMosque($council->mosque), 403);
    }
}
