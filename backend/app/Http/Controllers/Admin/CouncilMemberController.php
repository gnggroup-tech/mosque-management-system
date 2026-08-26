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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CouncilMemberController extends Controller
{
    public function index(Request $request): JsonResponse|View
    {
        $members = $this->visibleTo($request->user())
            ->with(['user:id,name,email', 'council:id,mosque_id,name,status'])
            ->when($request->filled('council_id'), fn (Builder $q) => $q->where('mosque_council_id', $request->integer('council_id')))
            ->when($request->filled('function'), fn (Builder $q) => $q->where('function', $request->string('function')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->latest('started_at')->paginate(min(max($request->integer('per_page', 20), 1), 100));

        if ($request->expectsJson()) {
            return response()->json($members);
        }

        return view('admin.council-members.index', [
            'members' => $members->withQueryString(),
            'councils' => $this->manageableCouncils($request->user())->select(['id', 'name'])->orderBy('name')->get(),
            'filters' => $request->only(['council_id', 'function', 'status']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.council-members.create', [
            'councils' => $this->manageableCouncils($request->user())->where('status', 'active')->select(['id', 'name'])->orderBy('name')->get(),
            'users' => User::query()->select(['id', 'name'])->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function store(StoreCouncilMemberRequest $request): JsonResponse|RedirectResponse
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

        if ($request->expectsJson()) {
            return response()->json($member->load(['user:id,name,email', 'council:id,mosque_id,name,status']), 201);
        }

        return redirect()->route('admin.council-members.show', $member)->with('success', __('Council member added successfully.'));
    }

    public function show(Request $request, CouncilMember $member): JsonResponse|View
    {
        abort_unless($this->visibleTo($request->user())->whereKey($member)->exists(), 403);

        if ($request->expectsJson()) {
            return response()->json($member->load(['user:id,name,email', 'council:id,mosque_id,name,status']));
        }

        $member->load(['user:id,name,email', 'council.mosque:id,name']);

        return view('admin.council-members.show', ['member' => $member]);
    }

    public function edit(Request $request, CouncilMember $member): View
    {
        $member->loadMissing('council.mosque:id,admin_id');
        $this->ensureManageable($request->user(), $member->council);

        return view('admin.council-members.edit', ['member' => $member]);
    }

    public function update(UpdateCouncilMemberRequest $request, CouncilMember $member): JsonResponse|RedirectResponse
    {
        $member->loadMissing('council.mosque:id,admin_id');
        $this->ensureManageable($request->user(), $member->council);
        $member->update($request->validated());

        $member = $member->fresh()->load(['user:id,name,email', 'council:id,mosque_id,name,status']);

        if ($request->expectsJson()) {
            return response()->json($member);
        }

        return redirect()->route('admin.council-members.show', $member)->with('success', __('Council member updated successfully.'));
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

    private function manageableCouncils(User $user): Builder
    {
        if ($user->hasRole('superadmin')) {
            return MosqueCouncil::query();
        }

        return MosqueCouncil::query()->whereHas('mosque', fn (Builder $mosques) => $mosques->administrableBy($user));
    }

    private function ensureManageable(User $user, MosqueCouncil $council): void
    {
        $council->loadMissing('mosque:id,admin_id');
        abort_unless($user->hasRole('superadmin') || $user->canAdministerMosque($council->mosque), 403);
    }
}
