<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Activity\StoreActivityRequest;
use App\Http\Requests\Activity\UpdateActivityRequest;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\Mosque;
use App\Models\User;
use App\Services\ActivityNotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $activities = $this->visibleTo($request->user())
            ->with(['mosque:id,code,name'])->withCount('registrations')
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->string('search')->toString()).'%';
                $query->where(fn (Builder $nested) => $nested->where('title', 'like', $search)->orWhere('description', 'like', $search));
            })
            ->when($request->filled('mosque_id'), fn (Builder $q) => $q->where('mosque_id', $request->integer('mosque_id')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('starts_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('starts_at', '<=', $request->date('to')))
            ->orderBy('starts_at')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json($activities);
    }

    public function store(StoreActivityRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->ensureMosqueManageable($request->user(), (int) $data['mosque_id']);
        $data['created_by'] = $request->user()->getKey();
        $data['status'] = 'draft';

        return response()->json(Activity::query()->create($data)->load('mosque:id,code,name'), 201);
    }

    public function show(Request $request, Activity $activity): JsonResponse
    {
        abort_unless($this->visibleTo($request->user())->whereKey($activity)->exists(), 403);

        return response()->json($activity->load(['mosque:id,code,name', 'registrations.user:id,name,email']));
    }

    public function update(
        UpdateActivityRequest $request,
        Activity $activity,
        ActivityNotificationService $notificationService,
    ): JsonResponse {
        $this->ensureMosqueManageable($request->user(), $activity->mosque_id);
        $data = $request->validated();
        $mosqueId = (int) ($data['mosque_id'] ?? $activity->mosque_id);
        $this->ensureMosqueManageable($request->user(), $mosqueId);

        return response()->json($notificationService->update($activity, $data)->load('mosque:id,code,name'));
    }

    public function publish(Request $request, Activity $activity): JsonResponse
    {
        $this->ensureMosqueManageable($request->user(), $activity->mosque_id);
        abort_unless($activity->status === 'draft', 422, 'Seule une activité en brouillon peut être publiée.');
        $activity->update(['status' => 'published', 'published_at' => now()]);

        return response()->json($activity->fresh());
    }

    public function cancel(
        Request $request,
        Activity $activity,
        ActivityNotificationService $notificationService,
    ): JsonResponse {
        $this->ensureMosqueManageable($request->user(), $activity->mosque_id);

        return response()->json($notificationService->cancel($activity));
    }

    public function register(Request $request, Activity $activity): JsonResponse
    {
        abort_unless($activity->status === 'published' && $activity->registration_required && $activity->starts_at->isFuture(), 422);
        $registration = DB::transaction(function () use ($request, $activity): ActivityRegistration {
            $locked = Activity::query()->lockForUpdate()->findOrFail($activity->id);
            abort_if($locked->registrations()->where('user_id', $request->user()->id)->exists(), 422, __('You are already registered.'));
            abort_if($locked->capacity !== null && $locked->registrations()->count() >= $locked->capacity, 422, __('The activity is full.'));

            return $locked->registrations()->create(['user_id' => $request->user()->id, 'registered_at' => now()]);
        });

        return response()->json($registration, 201);
    }

    public function unregister(Request $request, Activity $activity): JsonResponse
    {
        abort_if($activity->starts_at->isPast(), 422);
        $deleted = $activity->registrations()->where('user_id', $request->user()->id)->delete();
        abort_if($deleted === 0, 404);

        return response()->json(status: 204);
    }

    public function destroy(Request $request, Activity $activity): JsonResponse
    {
        $this->ensureMosqueManageable($request->user(), $activity->mosque_id);
        abort_if($activity->status === 'completed', 422);
        $activity->delete();

        return response()->json(status: 204);
    }

    private function visibleTo(User $user): Builder
    {
        if ($user->hasRole('superadmin')) {
            return Activity::query();
        }

        if ($user->hasRole('admin')) {
            return Activity::query()->whereHas('mosque', fn (Builder $mosques) => $mosques->administrableBy($user));
        }

        if ($user->hasRole('user')) {
            return Activity::query()->where('status', 'published');
        }

        return Activity::query()->whereRaw('1 = 0');
    }

    private function ensureMosqueManageable(User $user, int $mosqueId): void
    {
        $mosque = Mosque::query()->findOrFail($mosqueId);
        abort_unless($user->hasRole('superadmin') || $user->canAdministerMosque($mosque), 403);
    }
}
