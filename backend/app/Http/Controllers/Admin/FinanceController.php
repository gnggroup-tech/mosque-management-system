<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreExpenseRequest;
use App\Http\Requests\Finance\StoreSubsidyRequest;
use App\Models\Donation;
use App\Models\Expense;
use App\Models\Mosque;
use App\Models\Subsidy;
use App\Models\User;
use App\Models\WaqfRevenue;
use App\Models\ZakatCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceController extends Controller
{
    public function report(Request $request): JsonResponse
    {
        $request->validate([
            'mosque_id' => ['nullable', 'integer', 'exists:mosques,id'],
            'currency' => ['nullable', 'in:GNF,USD,EUR'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        if ($request->filled('mosque_id')) {
            $this->ensureManageable($request->user(), $request->integer('mosque_id'));
        }
        $mosqueIds = $this->visibleMosques($request->user())
            ->when($request->filled('mosque_id'), fn (Builder $q) => $q->whereKey($request->integer('mosque_id')))
            ->pluck('id');
        $currency = $request->input('currency', 'GNF');
        $from = $request->date('from');
        $to = $request->date('to');

        $sum = fn (string $model, string $date, string $mosqueColumn = 'mosque_id') => (float) $model::query()
            ->whereIn($mosqueColumn, $mosqueIds)->where('currency', $currency)->where('status', 'validated')
            ->when($from, fn (Builder $q) => $q->whereDate($date, '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate($date, '<=', $to))
            ->sum('amount');

        $resources = [
            'donations' => $sum(Donation::class, 'received_at'),
            'zakat' => $sum(ZakatCollection::class, 'collected_at'),
            'waqf' => 0.0,
            'subsidies' => $sum(Subsidy::class, 'received_at'),
        ];
        // Waqf belongs to a mosque through its asset, so calculate it separately.
        $resources['waqf'] = (float) WaqfRevenue::query()
            ->whereHas('asset', fn (Builder $q) => $q->whereIn('mosque_id', $mosqueIds))
            ->where('currency', $currency)->where('status', 'validated')
            ->when($from, fn (Builder $q) => $q->whereDate('received_at', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('received_at', '<=', $to))->sum('amount');
        $expenses = $sum(Expense::class, 'spent_at');

        return response()->json([
            'currency' => $currency,
            'resources' => $resources,
            'total_resources' => array_sum($resources),
            'total_expenses' => $expenses,
            'balance' => array_sum($resources) - $expenses,
        ]);
    }

    public function storeSubsidy(StoreSubsidyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->ensureManageable($request->user(), (int) $data['mosque_id']);
        $data += ['currency' => 'GNF'];
        $data += ['reference_number' => $this->number('SUB'), 'status' => 'pending', 'created_by' => $request->user()->id];

        return response()->json(Subsidy::query()->create($data), 201);
    }

    public function validateSubsidy(Request $request, Subsidy $subsidy): JsonResponse
    {
        $this->ensureManageable($request->user(), $subsidy->mosque_id);
        DB::transaction(function () use ($request, $subsidy): void {
            $locked = Subsidy::query()->lockForUpdate()->findOrFail($subsidy->id);
            abort_if($locked->status !== 'pending', 422, 'Cette subvention a déjà été traitée.');
            $locked->update(['status' => 'validated', 'validated_by' => $request->user()->id, 'validated_at' => now()]);
        });

        return response()->json($subsidy->fresh());
    }

    public function storeExpense(StoreExpenseRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->ensureManageable($request->user(), (int) $data['mosque_id']);
        $data += ['currency' => 'GNF'];
        $data += ['reference_number' => $this->number('EXP'), 'status' => 'pending', 'created_by' => $request->user()->id];

        return response()->json(Expense::query()->create($data), 201);
    }

    public function validateExpense(Request $request, Expense $expense): JsonResponse
    {
        $this->ensureManageable($request->user(), $expense->mosque_id);
        DB::transaction(function () use ($request, $expense): void {
            $locked = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            abort_if($locked->status !== 'pending', 422, 'Cette dépense a déjà été traitée.');
            $available = $this->availableFunds($locked->mosque_id, $locked->currency);
            abort_if((float) $locked->amount > $available, 422, 'Fonds disponibles insuffisants.');
            $locked->update(['status' => 'validated', 'validated_by' => $request->user()->id, 'validated_at' => now()]);
        });

        return response()->json($expense->fresh());
    }

    private function availableFunds(int $mosqueId, string $currency): float
    {
        $donations = Donation::query()->where('mosque_id', $mosqueId)->where('currency', $currency)->where('status', 'validated')->lockForUpdate()->sum('amount');
        // Zakat and Waqf remain restricted to their dedicated distribution circuits.
        $subsidies = Subsidy::query()->where('mosque_id', $mosqueId)->where('currency', $currency)->where('status', 'validated')->lockForUpdate()->sum('amount');
        $expenses = Expense::query()->where('mosque_id', $mosqueId)->where('currency', $currency)->where('status', 'validated')->lockForUpdate()->sum('amount');

        return (float) $donations + (float) $subsidies - (float) $expenses;
    }

    private function visibleMosques(User $user): Builder
    {
        return Mosque::query()->administrableBy($user);
    }

    private function ensureManageable(User $user, int $mosqueId): void
    {
        $mosque = Mosque::query()->findOrFail($mosqueId);
        abort_unless($user->hasRole('superadmin') || $user->canAdministerMosque($mosque), 403);
    }

    private function number(string $prefix): string
    {
        return $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }
}
