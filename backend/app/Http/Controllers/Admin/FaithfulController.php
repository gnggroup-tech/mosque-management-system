<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Faithful\StoreFaithfulRequest;
use App\Http\Requests\Faithful\UpdateFaithfulRequest;
use App\Models\Faithful;
use App\Models\Mosque;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FaithfulController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $records = $this->visibleTo($request->user())
            ->with('mosque:id,code,name')
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->string('search')->toString()).'%';
                $query->where(fn (Builder $nested) => $nested->where('first_name', 'like', $search)
                    ->orWhere('last_name', 'like', $search)->orWhere('registration_number', 'like', $search)
                    ->orWhere('phone', 'like', $search));
            })
            ->when($request->filled('mosque_id'), fn (Builder $q) => $q->where('mosque_id', $request->integer('mosque_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->orderBy('last_name')->orderBy('first_name')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));
        return response()->json($records);
    }

    public function store(StoreFaithfulRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->ensureMosqueManageable($request->user(), (int) $data['mosque_id']);
        $data['created_by'] = $request->user()->getKey();
        $faithful = Faithful::query()->create($data);
        return response()->json($faithful->load('mosque:id,code,name'), 201);
    }

    public function show(Request $request, Faithful $faithful): JsonResponse
    {
        abort_unless($this->visibleTo($request->user())->whereKey($faithful)->exists(), 403);
        return response()->json($faithful->load('mosque:id,code,name'));
    }

    public function update(UpdateFaithfulRequest $request, Faithful $faithful): JsonResponse
    {
        $this->ensureMosqueManageable($request->user(), $faithful->mosque_id);
        $data = $request->validated();
        if (isset($data['mosque_id'])) { $this->ensureMosqueManageable($request->user(), (int) $data['mosque_id']); }
        $faithful->update($data);
        return response()->json($faithful->fresh()->load('mosque:id,code,name'));
    }

    public function destroy(Request $request, Faithful $faithful): JsonResponse
    {
        abort_unless($request->user()->can('faithful.manage'), 403);
        $this->ensureMosqueManageable($request->user(), $faithful->mosque_id);
        $faithful->delete();
        return response()->json(status: 204);
    }

    private function visibleTo(User $user): Builder
    {
        return Faithful::query()
            ->when($user->hasRole('admin'), fn (Builder $q) => $q->whereHas('mosque', fn (Builder $m) => $m->where('admin_id', $user->id)))
            ->when($user->hasRole('user'), fn (Builder $q) => $q->where('user_id', $user->id));
    }

    private function ensureMosqueManageable(User $user, int $mosqueId): void
    {
        $mosque = Mosque::query()->findOrFail($mosqueId);
        abort_unless($user->hasRole('superadmin') || $mosque->admin_id === $user->id, 403);
    }
}
