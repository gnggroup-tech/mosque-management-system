<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Enums\MosqueMembershipType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Announcement\StoreAnnouncementRequest;
use App\Http\Requests\Announcement\UpdateAnnouncementRequest;
use App\Models\Announcement;
use App\Models\AnnouncementReceipt;
use App\Models\Mosque;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
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

        return response()->json($items);
    }

    public function store(StoreAnnouncementRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->ensureScopeManageable($request->user(), $data['mosque_id'] ?? null);
        $data['created_by'] = $request->user()->id;
        $data['status'] = 'draft';

        return response()->json(Announcement::query()->create($data), 201);
    }

    public function show(Request $request, Announcement $announcement): JsonResponse
    {
        abort_unless($this->visibleTo($request->user())->whereKey($announcement)->exists(), 403);

        return response()->json($announcement->load('mosque:id,code,name'));
    }

    public function update(UpdateAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $this->ensureScopeManageable($request->user(), $announcement->mosque_id);
        abort_unless($announcement->status === 'draft', 422, 'Seule une annonce en brouillon peut être modifiée.');
        $data = $request->validated();
        $this->ensureScopeManageable($request->user(), $data['mosque_id'] ?? $announcement->mosque_id);
        $announcement->update($data);

        return response()->json($announcement->fresh());
    }

    public function publish(Request $request, Announcement $announcement): JsonResponse
    {
        $this->ensureScopeManageable($request->user(), $announcement->mosque_id);
        abort_unless($announcement->status === 'draft', 422, 'Cette annonce a déjà été traitée.');
        DB::transaction(function () use ($announcement): void {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);
            abort_unless($locked->status === 'draft', 422, 'Cette annonce a déjà été traitée.');
            $locked->update(['status' => 'published', 'published_at' => now(), 'visible_from' => $locked->visible_from ?? now()]);
            $this->recipients($locked)->each(fn (User $user) => AnnouncementReceipt::query()->firstOrCreate(
                ['announcement_id' => $locked->id, 'user_id' => $user->id],
                ['delivered_at' => now()]
            ));
        });

        return response()->json($announcement->fresh()->loadCount('receipts'));
    }

    public function markRead(Request $request, Announcement $announcement): JsonResponse
    {
        $receipt = AnnouncementReceipt::query()->whereBelongsTo($announcement)->where('user_id', $request->user()->id)->firstOrFail();
        $receipt->update(['read_at' => $receipt->read_at ?? now()]);

        return response()->json($receipt->fresh());
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

    private function recipients(Announcement $announcement): Collection
    {
        $query = User::query();
        if ($announcement->mosque_id !== null) {
            $mosqueId = $announcement->mosque_id;
            $query->where(fn (Builder $recipients) => $recipients
                ->where(fn (Builder $administrators) => $administrators
                    ->where('status', AccountStatus::Active->value)
                    ->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'admin'))
                    ->whereHas('mosqueMemberships', fn (Builder $memberships) => $memberships
                        ->where('mosque_id', $mosqueId)
                        ->where('membership_type', MosqueMembershipType::Administrator->value)))
                ->orWhereHas('faithfulRecords', fn (Builder $faithful) => $faithful->where('mosque_id', $mosqueId)));
        }
        if ($announcement->audience === 'administrators') {
            $query->role(['superadmin', 'admin']);
        }
        if ($announcement->audience === 'faithful') {
            $query->role('user');
        }

        return $query->get();
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
