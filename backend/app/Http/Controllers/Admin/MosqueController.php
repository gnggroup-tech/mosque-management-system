<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mosque\StoreMosqueRequest;
use App\Http\Requests\Mosque\UpdateMosqueRequest;
use App\Models\Mosque;
use App\Models\User;
use App\Services\MosquePrimaryAdministratorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MosqueController extends Controller
{
    public function index(Request $request): JsonResponse|View
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

        if ($request->expectsJson()) {
            return response()->json($mosques);
        }

        return view('admin.mosques.index', [
            'mosques' => $mosques->withQueryString(),
            'filters' => $request->only(['search', 'status', 'region']),
            'regions' => $this->visibleTo($request->user())->whereNotNull('region')->distinct()->orderBy('region')->pluck('region'),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->isActive() && $request->user()->hasRole('superadmin'), 403);

        return view('admin.mosques.create', [
            'administrators' => User::query()
                ->select(['id', 'name'])
                ->where('status', 'active')
                ->role('admin')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(
        StoreMosqueRequest $request,
        MosquePrimaryAdministratorService $administratorService,
    ): JsonResponse|RedirectResponse {
        abort_unless($request->user()->isActive() && $request->user()->hasRole('superadmin'), 403);
        $data = $request->validated();

        $mosque = $administratorService->create($data, $request->user());

        if ($request->expectsJson()) {
            return response()->json($mosque->load('administrator:id,name,email'), 201);
        }

        return redirect()->route('admin.mosques.show', $mosque)->with('success', __('Mosque created successfully.'));
    }

    public function show(Request $request, Mosque $mosque): JsonResponse|View
    {
        $this->ensureVisible($request->user(), $mosque);

        $mosque->load('administrator:id,name,email');

        if ($request->expectsJson()) {
            return response()->json($mosque);
        }

        return view('admin.mosques.show', ['mosque' => $mosque]);
    }

    public function edit(Request $request, Mosque $mosque): View
    {
        $this->ensureVisible($request->user(), $mosque);

        return view('admin.mosques.edit', [
            'mosque' => $mosque,
            'administrators' => $request->user()->hasRole('superadmin')
                ? User::query()
                    ->select(['id', 'name'])
                    ->where('status', 'active')
                    ->role('admin')
                    ->orderBy('name')
                    ->get()
                : collect(),
        ]);
    }

    public function update(
        UpdateMosqueRequest $request,
        Mosque $mosque,
        MosquePrimaryAdministratorService $administratorService,
    ): JsonResponse|RedirectResponse {
        $this->ensureVisible($request->user(), $mosque);
        $data = $request->validated();

        if (! $request->user()->hasRole('superadmin')) {
            unset($data['admin_id']);
        }

        $mosque = $administratorService->update($mosque, $data, $request->user());

        $mosque = $mosque->fresh()->load('administrator:id,name,email');

        if ($request->expectsJson()) {
            return response()->json($mosque);
        }

        return redirect()->route('admin.mosques.show', $mosque)->with('success', __('Mosque updated successfully.'));
    }

    public function destroy(Request $request, Mosque $mosque): JsonResponse
    {
        abort_unless($request->user()->hasRole('superadmin'), 403);
        $mosque->delete();

        return response()->json(status: 204);
    }

    private function visibleTo(User $user): Builder
    {
        return Mosque::query()->administrableBy($user);
    }

    private function ensureVisible(User $user, Mosque $mosque): void
    {
        abort_unless($user->hasRole('superadmin') || $user->canAdministerMosque($mosque), 403);
    }
}
