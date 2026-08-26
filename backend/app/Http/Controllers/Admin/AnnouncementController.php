<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use App\Http\Requests\Announcement\UpdateAnnouncementRequest;
use App\Models\Announcement;
use App\Models\AnnouncementReceipt;
use App\Models\Mosque;
use App\Models\User;
use App\Services\AnnouncementDistributionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse|View
    {
        $items = $this->visibleTo($request->user())
            ->with('mosque:id,code,name')->withCount('receipts')
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->string('search')->toString()).'%';
                $query->where(fn (Builder $q) => $q->where('title', 'like', $search)->orWhere('body', 'like', $search));
            })
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->when($request->filled('priority'), fn (Builder $q) => $q->where('priority', $request->string('priority')))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        if ($request->expectsJson()) {
            return response()->json($items);
        }

        $items->getCollection()->load(['receipts' => fn ($receipts) => $receipts->where('user_id', $request->user()->id)]);

        return view('admin.announcements.index', [
            'announcements' => $items->withQueryString(),
            'filters' => $request->only(['search', 'type', 'priority']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.announcements.create', [
            'mosques' => Mosque::query()->administrableBy($request->user())->select(['id', 'name'])->orderBy('name')->get(),
            'canPublishNationally' => $request->user()->hasRole('superadmin'),
        ]);
    }

    public function store(StoreAnnouncementRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $this->ensureScopeManageable($request->user(), $data['mosque_id'] ?? null);
        $data['created_by'] = $request->user()->id;
        $data['status'] = 'draft';

        $announcement = Announcement::query()->create($data);

        if ($request->expectsJson()) {
            return response()->json($announcement, 201);
        }

        return redirect()->route('admin.announcements.show', $announcement)->with('success', __('Announcement draft created successfully.'));
    }

    public function show(Request $request, Announcement $announcement): JsonResponse|View
    {
        abort_unless($this->visibleTo($request->user())->whereKey($announcement)->exists(), 403);

        $announcement->load('mosque:id,code,name');

        if ($request->expectsJson()) {
            return response()->json($announcement);
        }

        return view('admin.announcements.show', [
            'announcement' => $announcement,
            'receipt' => $announcement->receipts()->where('user_id', $request->user()->id)->first(),
        ]);
    }

    public function edit(Request $request, Announcement $announcement): View
    {
        $this->ensureScopeManageable($request->user(), $announcement->mosque_id);
        abort_unless($announcement->status === 'draft', 422, __('Only draft announcements can be edited.'));

        return view('admin.announcements.edit', [
            'announcement' => $announcement,
            'mosques' => Mosque::query()->administrableBy($request->user())->select(['id', 'name'])->orderBy('name')->get(),
            'canPublishNationally' => $request->user()->hasRole('superadmin'),
        ]);
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): JsonResponse|RedirectResponse
    {
        $this->ensureScopeManageable($request->user(), $announcement->mosque_id);
        abort_unless($announcement->status === 'draft', 422, 'Seule une annonce en brouillon peut être modifiée.');
        $data = $request->validated();
        $this->ensureScopeManageable($request->user(), $data['mosque_id'] ?? $announcement->mosque_id);
        $announcement->update($data);

        if ($request->expectsJson()) {
            return response()->json($announcement->fresh());
        }

        return redirect()->route('admin.announcements.show', $announcement)->with('success', __('Announcement updated successfully.'));
    }

    public function publish(
        Request $request,
        Announcement $announcement,
        AnnouncementDistributionService $distributionService,
    ): JsonResponse|RedirectResponse {
        $this->ensureScopeManageable($request->user(), $announcement->mosque_id);

        $announcement = $distributionService->publish($announcement);

        if ($request->expectsJson()) {
            return response()->json($announcement);
        }

        return redirect()->route('admin.announcements.show', $announcement)->with('success', __('Announcement published internally.'));
    }

    public function markRead(Request $request, Announcement $announcement): JsonResponse|RedirectResponse
    {
        $receipt = AnnouncementReceipt::query()->whereBelongsTo($announcement)->where('user_id', $request->user()->id)->firstOrFail();
        $receipt->update(['read_at' => $receipt->read_at ?? now()]);

        if ($request->expectsJson()) {
            return response()->json($receipt->fresh());
        }

        return redirect()->route('admin.announcements.show', $announcement)->with('success', __('Announcement marked as read.'));
    }

    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        $this->ensureScopeManageable($request->user(), $announcement->mosque_id);
        abort_unless($announcement->status === 'draft', 422);
        $announcement->delete();

        return response()->json(status: 204);
    }

    private function visibleTo(User $user): Builder
    {
        if ($user->hasRole('superadmin')) {
            return Announcement::query();
        }
        if ($user->hasRole('admin')) {
            return Announcement::query()->where(fn (Builder $query) => $query
                ->whereNull('mosque_id')
                ->orWhereHas('mosque', fn (Builder $mosques) => $mosques->administrableBy($user)));
        }

        if ($user->hasRole('user')) {
            return Announcement::query()->whereHas('receipts', fn (Builder $q) => $q->where('user_id', $user->id))
                ->where('status', 'published')->where(fn (Builder $q) => $q->whereNull('visible_from')->orWhere('visible_from', '<=', now()))
                ->where(fn (Builder $q) => $q->whereNull('visible_until')->orWhere('visible_until', '>=', now()));
        }

        return Announcement::query()->whereRaw('1 = 0');
    }

    private function ensureScopeManageable(User $user, ?int $mosqueId): void
    {
        if ($user->hasRole('superadmin')) {
            return;
        }
        abort_if($mosqueId === null, 403, 'Seul le superadministrateur peut diffuser une annonce nationale.');
        $mosque = Mosque::query()->findOrFail($mosqueId);
        abort_unless($user->canAdministerMosque($mosque), 403);
    }
}
