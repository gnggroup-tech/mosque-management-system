<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\AccountDirectoryRequest;
use App\Http\Resources\AccountDirectoryDetailResource;
use App\Http\Resources\AccountDirectoryResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AccountDirectoryController extends Controller
{
    public function index(AccountDirectoryRequest $request): AnonymousResourceCollection|View
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validated();
        $sort = $filters['sort'] ?? 'id';
        $direction = $filters['direction'] ?? 'asc';

        $accounts = User::query()
            ->select(['id', 'name', 'status', 'locale', 'created_at', 'updated_at'])
            ->with([
                'roles:id,name',
                'administeredMosques:id,admin_id,name',
            ])
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(isset($filters['role']), fn (Builder $query) => $query->whereHas(
                'roles',
                fn (Builder $roles) => $roles->where('name', $filters['role']),
            ))
            ->when(isset($filters['search']), fn (Builder $query) => $this->applySearch($query, $filters['search']))
            ->when(isset($filters['created_from']), fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['created_from']))
            ->when(isset($filters['created_to']), fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['created_to']))
            ->orderBy($sort, $direction)
            ->when($sort !== 'id', fn (Builder $query) => $query->orderBy('id'))
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        if ($request->expectsJson()) {
            return AccountDirectoryResource::collection($accounts);
        }

        return view('admin.accounts.index', [
            'accounts' => $accounts,
            'filters' => $filters,
            'statuses' => AccountStatus::cases(),
            'roles' => array_keys(config('permissions.roles')),
        ]);
    }

    public function show(Request $request, User $account): AccountDirectoryDetailResource|View
    {
        Gate::authorize('view', $account);

        $account->loadMissing([
            'roles:id,name',
            'administeredMosques:id,admin_id,name',
        ]);

        if ($request->expectsJson()) {
            return new AccountDirectoryDetailResource($account);
        }

        return view('admin.accounts.show', ['account' => $account]);
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        if (ctype_digit($search)) {
            return $query->whereKey($search);
        }

        $pattern = "%{$search}%";

        return $query->where(fn (Builder $nested) => $nested
            ->where('name', 'like', $pattern)
            ->orWhere('email', 'like', $pattern));
    }
}
