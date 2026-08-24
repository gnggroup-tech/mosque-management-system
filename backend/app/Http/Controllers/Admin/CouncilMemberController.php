<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Council\StoreCouncilMemberRequest;
use App\Http\Requests\Council\UpdateCouncilMemberRequest;
use App\Models\CouncilMember;
use App\Models\MosqueCouncil;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CouncilMemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $members = $this->visibleTo($request->user())
            ->with(['user:id,name,email', 'council:id,mosque_id,name,status'])
            ->when($request->filled('council_id'), fn (Builder $q) => $q->where('mosque_council_id', $request->integer('council_id')))
            ->when($request->filled('function'), fn (Builder $q) => $q->where('function', $request->string('function')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->latest('started_at')->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json($members);
    }

    public function store(StoreCouncilMemberRequest $request): JsonResponse
    {
        $data = $request->validated();
        $council = MosqueCouncil::query()->with('mosque:id,admin_id')->findOrFail($data['mosque_council_id']);
        $this->ensureManageable($request->user(), $council);
        abort_if($council->status !== 'active', 422, 'Les membres ne peuvent être ajoutés qu’à un conseil actif.');
        $duplicate = CouncilMember::query()->where('mosque_council_id', $council->id)->where('user_id', $data['user_id'])->where('status', 'active')->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['user_id' => 'Cet utilisateur est déjà membre actif de ce conseil.']);
        }
        $data['created_by'] = $request->user()->getKey();
        $member = CouncilMember::query()->create($data);

        return response()->json($member->load(['user:id,name,email', 'council:id,mosque_id,name,status']), 201);
    }

    public function show(Request $request, CouncilMember $member): JsonResponse
    {
        abort_unless($this->visibleTo($request->user())->whereKey($member)->exists(), 403);

        return response()->json($member->load(['user:id,name,email', 'council:id,mosque_id,name,status']));
    }

    public function update(UpdateCouncilMemberRequest $request, CouncilMember $member): JsonResponse
    {
        $member->loadMissing('council.mosque:id,admin_id');
        $this->ensureManageable($request->user(), $member->council);
        $member->update($request->validated());

        return response()->json($member->fresh()->load(['user:id,name,email', 'council:id,mosque_id,name,status']));
    }

    public function destroy(Request $request, CouncilMember $member): JsonResponse
    {
        abort_unless($request->user()->can('council-members.delete'), 403);
        $member->loadMissing('council.mosque:id,admin_id');
        $this->ensureManageable($request->user(), $member->council);
        $member->delete();

        return response()->json(status: 204);
    }

    private function visibleTo(User $user): Builder
    {
        if ($user->hasRole('superadmin')) {
            return CouncilMember::query();
        }

        if ($user->hasRole('admin')) {
            return CouncilMember::query()->whereHas('council.mosque', fn (Builder $mosques) => $mosques->administrableBy($user));
        }

        if ($user->hasRole('user')) {
            return CouncilMember::query()->where('status', 'active')
                ->whereHas('council', fn (Builder $councils) => $councils->where('status', 'active'));
        }

        return CouncilMember::query()->whereRaw('1 = 0');
    }

    private function ensureManageable(User $user, MosqueCouncil $council): void
    {
        $council->loadMissing('mosque:id,admin_id');
        abort_unless($user->hasRole('superadmin') || $user->canAdministerMosque($council->mosque), 403);
    }
}
