<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Council\StoreMosqueCouncilRequest;
use App\Http\Requests\Council\UpdateMosqueCouncilRequest;
use App\Models\Mosque;
use App\Models\MosqueCouncil;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MosqueCouncilController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $councils = $this->visibleTo($request->user())
            ->with(['mosque:id,code,name,admin_id', 'creator:id,name,email'])
            ->when($request->filled('mosque_id'), fn (Builder $query) => $query->where('mosque_id', $request->integer('mosque_id')))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->string('search')->toString()).'%';
                $query->where('name', 'like', $search);
            })
            ->orderByDesc('mandate_start')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json($councils);
    }

    public function store(StoreMosqueCouncilRequest $request): JsonResponse
    {
        $data = $request->validated();
        $mosque = Mosque::query()->findOrFail($data['mosque_id']);
        $this->ensureMosqueManageable($request->user(), $mosque);
        $data['created_by'] = $request->user()->getKey();

        $council = DB::transaction(function () use ($data): MosqueCouncil {
            $this->ensureNoOtherActiveCouncil((int) $data['mosque_id'], $data['status'] ?? 'active');

            return MosqueCouncil::query()->create($data);
        });

        return response()->json($council->load(['mosque:id,code,name,admin_id', 'creator:id,name,email']), 201);
    }

    public function show(Request $request, MosqueCouncil $council): JsonResponse
    {
        $this->ensureVisible($request->user(), $council);

        return response()->json($council->load(['mosque:id,code,name,admin_id', 'creator:id,name,email']));
    }

    public function update(UpdateMosqueCouncilRequest $request, MosqueCouncil $council): JsonResponse
    {
        $this->ensureManageable($request->user(), $council);
        $data = $request->validated();

        DB::transaction(function () use ($council, $data): void {
            $this->ensureNoOtherActiveCouncil(
                $council->mosque_id,
                $data['status'] ?? $council->status,
                $council->getKey(),
            );
            $council->update($data);
        });

        return response()->json($council->fresh()->load(['mosque:id,code,name,admin_id', 'creator:id,name,email']));
    }

    public function destroy(Request $request, MosqueCouncil $council): JsonResponse
    {
        abort_unless($request->user()->can('councils.delete'), 403);
        $this->ensureManageable($request->user(), $council);
        $council->delete();

        return response()->json(status: 204);
    }

    private function visibleTo(User $user): Builder
    {
        if ($user->hasRole('superadmin')) {
            return MosqueCouncil::query();
        }

        if ($user->hasRole('admin')) {
            return MosqueCouncil::query()->whereHas('mosque', fn (Builder $mosques) => $mosques->administrableBy($user));
        }

        if ($user->hasRole('user')) {
            return MosqueCouncil::query()->where('status', 'active');
        }

        return MosqueCouncil::query()->whereRaw('1 = 0');
    }

    private function ensureVisible(User $user, MosqueCouncil $council): void
    {
        if ($user->hasRole('superadmin')) {
            return;
        }

        if ($user->hasRole('admin')) {
            $this->ensureManageable($user, $council);

            return;
        }

        abort_unless($user->hasRole('user') && $council->status === 'active', 403);
    }

    private function ensureManageable(User $user, MosqueCouncil $council): void
    {
        $council->loadMissing('mosque:id,admin_id');
        $this->ensureMosqueManageable($user, $council->mosque);
    }

    private function ensureMosqueManageable(User $user, Mosque $mosque): void
    {
        abort_unless($user->hasRole('superadmin') || $user->canAdministerMosque($mosque), 403);
    }

    private function ensureNoOtherActiveCouncil(int $mosqueId, string $status, ?int $exceptId = null): void
    {
        if ($status !== 'active') {
            return;
        }

        $exists = MosqueCouncil::query()
            ->where('mosque_id', $mosqueId)
            ->where('status', 'active')
            ->when($exceptId, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->lockForUpdate()
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'status' => 'Cette mosquée possède déjà un conseil actif.',
            ]);
        }
    }
}
