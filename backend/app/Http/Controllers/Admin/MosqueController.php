<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mosque\StoreMosqueRequest;
use App\Http\Requests\Mosque\UpdateMosqueRequest;
use App\Models\Mosque;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MosqueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $mosques = $this->visibleTo($request->user())
            ->with('administrator:id,name,email')
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->string('search')->toString()).'%';
                $query->where(fn (Builder $nested) => $nested
                    ->where('name', 'like', $search)
                    ->orWhere('code', 'like', $search));
            })
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when($request->filled('region'), fn (Builder $query) => $query->where('region', $request->string('region')))
            ->orderBy('name')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json($mosques);
    }

    public function store(StoreMosqueRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (! $request->user()->hasRole('superadmin')) {
            $data['admin_id'] = $request->user()->getKey();
        } elseif (isset($data['admin_id'])) {
            $this->ensureAdministrator($data['admin_id']);
        }

        $mosque = Mosque::query()->create($data);

        return response()->json($mosque->load('administrator:id,name,email'), 201);
    }

    public function show(Request $request, Mosque $mosque): JsonResponse
    {
        $this->ensureVisible($request->user(), $mosque);

        return response()->json($mosque->load('administrator:id,name,email'));
    }

    public function update(UpdateMosqueRequest $request, Mosque $mosque): JsonResponse
    {
        $this->ensureVisible($request->user(), $mosque);
        $data = $request->validated();

        if (! $request->user()->hasRole('superadmin')) {
            unset($data['admin_id']);
        } elseif (array_key_exists('admin_id', $data) && $data['admin_id'] !== null) {
            $this->ensureAdministrator($data['admin_id']);
        }

        $mosque->update($data);

        return response()->json($mosque->fresh()->load('administrator:id,name,email'));
    }

    public function destroy(Request $request, Mosque $mosque): JsonResponse
    {
        abort_unless($request->user()->hasRole('superadmin'), 403);
        $mosque->delete();

        return response()->json(status: 204);
    }

    private function visibleTo(User $user): Builder
    {
        return Mosque::query()->when(
            ! $user->hasRole('superadmin'),
            fn (Builder $query) => $query->where('admin_id', $user->getKey()),
        );
    }

    private function ensureVisible(User $user, Mosque $mosque): void
    {
        abort_unless($user->hasRole('superadmin') || $mosque->admin_id === $user->getKey(), 403);
    }

    private function ensureAdministrator(int $adminId): void
    {
        abort_unless(User::query()->findOrFail($adminId)->hasRole('admin'), 422, 'The selected user must have the admin role.');
    }
}
