<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Waqf\StoreWaqfAssetRequest;
use App\Http\Requests\Waqf\StoreWaqfTransactionRequest;
use App\Http\Requests\Waqf\UpdateWaqfExpenseRequest;
use App\Models\Mosque;
use App\Models\User;
use App\Models\WaqfAsset;
use App\Models\WaqfExpense;
use App\Models\WaqfRevenue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WaqfController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $assets = $this->assetsVisibleTo($request->user())
            ->with('mosque:id,code,name')
            ->withSum(['revenues as validated_revenue' => fn (Builder $q) => $q->where('status', 'validated')], 'amount')
            ->withSum(['expenses as validated_expense' => fn (Builder $q) => $q->where('status', 'validated')], 'amount')
            ->when($request->filled('mosque_id'), fn (Builder $q) => $q->where('mosque_id', $request->integer('mosque_id')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->latest()->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json($assets);
    }

    public function storeAsset(StoreWaqfAssetRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->ensureMosqueManageable($request->user(), (int) $data['mosque_id']);
        $data += ['currency' => 'GNF'];
        $data['registration_number'] = $this->uniqueNumber('WAQ', WaqfAsset::class, 'registration_number');
        $data['status'] = 'active';
        $data['created_by'] = $request->user()->id;

        return response()->json(WaqfAsset::query()->create($data), 201);
    }

    public function storeRevenue(StoreWaqfTransactionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $asset = $this->manageableAsset($request->user(), (int) $data['waqf_asset_id']);
        abort_if($asset->status !== 'active', 422, __('The Waqf asset must be active.'));
        $data += ['currency' => $asset->currency];
        $this->ensureTransactionCurrencyMatchesAsset($asset, $data['currency']);
        $data['receipt_number'] = $this->uniqueNumber('WRV', WaqfRevenue::class, 'receipt_number');
        $data['status'] = 'pending';
        $data['created_by'] = $request->user()->id;

        return response()->json(WaqfRevenue::query()->create($data), 201);
    }

    public function storeExpense(StoreWaqfTransactionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $asset = $this->manageableAsset($request->user(), (int) $data['waqf_asset_id']);
        abort_if($asset->status !== 'active', 422, __('The Waqf asset must be active.'));
        $data += ['currency' => $asset->currency];
        $this->ensureTransactionCurrencyMatchesAsset($asset, $data['currency']);
        $data['reference_number'] = $this->uniqueNumber('WEX', WaqfExpense::class, 'reference_number');
        $data['status'] = 'pending';
        $data['created_by'] = $request->user()->id;

        return response()->json(WaqfExpense::query()->create($data), 201);
    }

    public function updateExpense(UpdateWaqfExpenseRequest $request, WaqfExpense $expense): JsonResponse
    {
        $data = $request->validated();
        $expense = DB::transaction(function () use ($request, $expense, $data): WaqfExpense {
            $locked = WaqfExpense::query()->lockForUpdate()->findOrFail($expense->id);
            $asset = $this->manageableAsset($request->user(), $locked->waqf_asset_id);
            abort_if($locked->status !== 'pending', 422, __('Only a pending Waqf expense can be modified.'));
            abort_if($asset->status !== 'active', 422, __('The Waqf asset must be active.'));
            $this->ensureTransactionCurrencyMatchesAsset($asset, $data['currency'] ?? $locked->currency);
            $locked->update($data);

            return $locked->fresh();
        });

        return response()->json($expense);
    }

    public function validateRevenue(Request $request, WaqfRevenue $revenue): JsonResponse
    {
        $this->manageableAsset($request->user(), $revenue->waqf_asset_id);
        DB::transaction(function () use ($request, $revenue): void {
            $locked = WaqfRevenue::query()->lockForUpdate()->findOrFail($revenue->id);
            abort_if($locked->status !== 'pending', 422, 'Ce revenu a déjà été traité.');
            $locked->update(['status' => 'validated', 'validated_by' => $request->user()->id, 'validated_at' => now()]);
        });

        return response()->json($revenue->fresh());
    }

    public function validateExpense(Request $request, WaqfExpense $expense): JsonResponse
    {
        $this->manageableAsset($request->user(), $expense->waqf_asset_id);
        DB::transaction(function () use ($request, $expense): void {
            $locked = WaqfExpense::query()->lockForUpdate()->findOrFail($expense->id);
            abort_if($locked->status !== 'pending', 422, 'Cette dépense a déjà été traitée.');
            $revenues = WaqfRevenue::query()->where('waqf_asset_id', $locked->waqf_asset_id)->where('currency', $locked->currency)->where('status', 'validated')->lockForUpdate()->get(['amount'])->sum('amount');
            $expenses = WaqfExpense::query()->where('waqf_asset_id', $locked->waqf_asset_id)->where('currency', $locked->currency)->where('status', 'validated')->lockForUpdate()->get(['amount'])->sum('amount');
            abort_if((float) $locked->amount > ((float) $revenues - (float) $expenses), 422, 'Solde Waqf insuffisant.');
            $locked->update(['status' => 'validated', 'validated_by' => $request->user()->id, 'validated_at' => now()]);
        });

        return response()->json($expense->fresh());
    }

    private function assetsVisibleTo(User $user): Builder
    {
        return WaqfAsset::query()->when($user->hasRole('admin'), fn (Builder $q) => $q->whereHas('mosque', fn (Builder $m) => $m->where('admin_id', $user->id)));
    }

    private function ensureMosqueManageable(User $user, int $mosqueId): void
    {
        $mosque = Mosque::query()->findOrFail($mosqueId);
        abort_unless($user->hasRole('superadmin') || $mosque->admin_id === $user->id, 403);
    }

    private function manageableAsset(User $user, int $assetId): WaqfAsset
    {
        $asset = WaqfAsset::query()->with('mosque')->findOrFail($assetId);
        abort_unless($user->hasRole('superadmin') || $asset->mosque->admin_id === $user->id, 403);

        return $asset;
    }

    private function ensureTransactionCurrencyMatchesAsset(WaqfAsset $asset, string $currency): void
    {
        abort_if($currency !== $asset->currency, 422, __('The transaction currency must match the Waqf asset currency.'));
    }

    private function uniqueNumber(string $prefix, string $model, string $column): string
    {
        do {
            $number = $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while ($model::query()->where($column, $number)->exists());

        return $number;
    }
}
