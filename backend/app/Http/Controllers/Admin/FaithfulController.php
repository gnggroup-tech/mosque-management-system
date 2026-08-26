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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FaithfulController extends Controller
{
    public function index(Request $request): JsonResponse|View
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

        if ($request->expectsJson()) {
            return response()->json($records);
        }

        return view('admin.faithful.index', [
            'records' => $records->withQueryString(),
            'mosques' => $this->mosquesVisibleTo($request->user())->select(['id', 'name'])->orderBy('name')->get(),
            'filters' => $request->only(['search', 'mosque_id', 'status']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.faithful.create', [
            'mosques' => Mosque::query()->administrableBy($request->user())->select(['id', 'name'])->orderBy('name')->get(),
        ]);
    }

    public function store(StoreFaithfulRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $this->ensureMosqueManageable($request->user(), (int) $data['mosque_id']);
        $data['created_by'] = $request->user()->getKey();
        $faithful = Faithful::query()->create($data);

        if ($request->expectsJson()) {
            return response()->json($faithful->load('mosque:id,code,name'), 201);
        }

        return redirect()->route('admin.faithful.show', $faithful)->with('success', __('Faithful record created successfully.'));
    }

    public function show(Request $request, Faithful $faithful): JsonResponse|View
    {
        abort_unless($this->visibleTo($request->user())->whereKey($faithful)->exists(), 403);

        $faithful->load('mosque:id,code,name');

        if ($request->expectsJson()) {
            return response()->json($faithful);
        }

        return view('admin.faithful.show', ['faithful' => $faithful]);
    }

    public function edit(Request $request, Faithful $faithful): View
    {
        $this->ensureMosqueManageable($request->user(), $faithful->mosque_id);

        return view('admin.faithful.edit', [
            'faithful' => $faithful,
            'mosques' => Mosque::query()->administrableBy($request->user())->select(['id', 'name'])->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateFaithfulRequest $request, Faithful $faithful): JsonResponse|RedirectResponse
    {
        $this->ensureMosqueManageable($request->user(), $faithful->mosque_id);
        $data = $request->validated();
        if (isset($data['mosque_id'])) {
            $this->ensureMosqueManageable($request->user(), (int) $data['mosque_id']);
        }
        $faithful->update($data);

        $faithful = $faithful->fresh()->load('mosque:id,code,name');

        if ($request->expectsJson()) {
            return response()->json($faithful);
        }

        return redirect()->route('admin.faithful.show', $faithful)->with('success', __('Faithful record updated successfully.'));
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
        if ($user->hasRole('superadmin')) {
            return Faithful::query();
        }

        if ($user->hasRole('admin')) {
            return Faithful::query()->whereHas('mosque', fn (Builder $mosques) => $mosques->administrableBy($user));
        }

        if ($user->hasRole('user')) {
            return Faithful::query()->where('user_id', $user->id);
        }

        return Faithful::query()->whereRaw('1 = 0');
    }

    private function mosquesVisibleTo(User $user): Builder
    {
        if ($user->hasRole('user')) {
            return Mosque::query()->whereHas('faithful', fn (Builder $faithful) => $faithful->where('user_id', $user->id));
        }

        return Mosque::query()->administrableBy($user);
    }

    private function ensureMosqueManageable(User $user, int $mosqueId): void
    {
        $mosque = Mosque::query()->findOrFail($mosqueId);
        abort_unless($user->hasRole('superadmin') || $user->canAdministerMosque($mosque), 403);
    }
}
