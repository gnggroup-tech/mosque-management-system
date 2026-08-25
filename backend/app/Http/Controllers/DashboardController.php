<?php

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Models\Activity;
use App\Models\Donation;
use App\Models\Expense;
use App\Models\Mosque;
use App\Models\Subsidy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        /** @var User $user */
        $user = request()->user();
        $mosqueIds = $user->can('mosques.view')
            ? Mosque::query()->administrableBy($user)->pluck('id')
            : collect();

        $metrics = [
            'mosques' => $user->can('mosques.view') ? $mosqueIds->count() : null,
            'active_accounts' => $user->can('users.directory.view')
                ? User::query()->where('status', AccountStatus::Active)->count()
                : null,
            'pending_approvals' => $user->can('users.approve')
                ? User::query()->where('status', AccountStatus::PendingApproval)->count()
                : null,
            'upcoming_activities' => $user->can('activities.view')
                ? Activity::query()->whereIn('mosque_id', $mosqueIds)->where('status', 'published')->where('starts_at', '>=', now())->count()
                : null,
        ];

        $upcomingActivities = $user->can('activities.view')
            ? Activity::query()
                ->select(['id', 'mosque_id', 'title', 'type', 'starts_at', 'location'])
                ->with('mosque:id,name')
                ->whereIn('mosque_id', $mosqueIds)
                ->where('status', 'published')
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at')
                ->limit(5)
                ->get()
            : collect();

        return view('dashboard', [
            'metrics' => $metrics,
            'upcomingActivities' => $upcomingActivities,
            'financialOverview' => $user->can('finances.view')
                ? $this->financialOverview($mosqueIds)
                : collect(),
        ]);
    }

    private function financialOverview(Collection $mosqueIds): Collection
    {
        $donations = $this->totalsByCurrency(Donation::query(), $mosqueIds, 'validated');
        $subsidies = $this->totalsByCurrency(Subsidy::query(), $mosqueIds, 'validated');
        $expenses = $this->totalsByCurrency(Expense::query(), $mosqueIds, 'validated');

        return collect(['GNF', 'USD', 'EUR'])->map(fn (string $currency) => [
            'currency' => $currency,
            'resources' => ($donations[$currency] ?? 0) + ($subsidies[$currency] ?? 0),
            'expenses' => $expenses[$currency] ?? 0,
            'balance' => ($donations[$currency] ?? 0) + ($subsidies[$currency] ?? 0) - ($expenses[$currency] ?? 0),
        ]);
    }

    private function totalsByCurrency(Builder $query, Collection $mosqueIds, string $status): Collection
    {
        return $query->whereIn('mosque_id', $mosqueIds)
            ->where('status', $status)
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->map(fn (mixed $amount) => (float) $amount);
    }
}
