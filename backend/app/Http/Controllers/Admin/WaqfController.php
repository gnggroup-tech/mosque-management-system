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
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WaqfController extends Controller
{
    public function index(Request $request): JsonResponse|View
    {
        $assets = $this->assetsVisibleTo($request->user())
            ->with('mosque:id,code,name')
            ->withSum(['revenues as validated_revenue' => fn (Builder $q) => $q->where('status', 'validated')], 'amount')
            ->withSum(['expenses as validated_expense' => fn (Builder $q) => $q->where('status', 'validated')], 'amount')
            ->when($request->filled('mosque_id'), fn (Builder $q) => $q->where('mosque_id', $request->integer('mosque_id')))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->latest()->paginate(min(max($request->integer('per_page', 20), 1), 100));

        if ($request->expectsJson()) {
            return response()->json($assets);
        }

        $assets->getCollection()->load([
            'revenues' => fn ($query) => $query->latest('received_at'),
            'expenses' => fn ($query) => $query->latest('spent_at'),
        ]);
        $assets->getCollection()->each(fn (WaqfAsset $asset) => $asset->setAttribute(
            'validated_balance',
            $this->subtractDecimalAmounts((string) ($asset->validated_revenue ?? '0'), (string) ($asset->validated_expense ?? '0')),
        ));

        return view('admin.waqf.index', [
            'assets' => $assets->withQueryString(),
            'mosques' => Mosque::query()->administrableBy($request->user())->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['mosque_id', 'type', 'status']),
        ]);
    }

    public function storeAsset(StoreWaqfAssetRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $this->ensureMosqueManageable($request->user(), (int) $data['mosque_id']);
        $data += ['currency' => 'GNF'];
        $data['registration_number'] = $this->uniqueNumber('WAQ', WaqfAsset::class, 'registration_number');
        $data['status'] = 'active';
        $data['created_by'] = $request->user()->id;

        $asset = WaqfAsset::query()->create($data);

        return $request->expectsJson()
            ? response()->json($asset, 201)
            : redirect()->route('admin.waqf.assets.index')->with('success', __('Waqf asset recorded.'));
    }

    public function storeRevenue(StoreWaqfTransactionRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $asset = $this->manageableAsset($request->user(), (int) $data['waqf_asset_id']);
        abort_if($asset->status !== 'active', 422, __('The Waqf asset must be active.'));
        $data += ['currency' => $asset->currency];
        $this->ensureTransactionCurrencyMatchesAsset($asset, $data['currency']);
        $data['receipt_number'] = $this->uniqueNumber('WRV', WaqfRevenue::class, 'receipt_number');
        $data['status'] = 'pending';
        $data['created_by'] = $request->user()->id;

        $revenue = WaqfRevenue::query()->create($data);

        return $request->expectsJson()
            ? response()->json($revenue, 201)
            : redirect()->route('admin.waqf.assets.index')->with('success', __('Waqf revenue recorded.'));
    }

    public function storeExpense(StoreWaqfTransactionRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $asset = $this->manageableAsset($request->user(), (int) $data['waqf_asset_id']);
        abort_if($asset->status !== 'active', 422, __('The Waqf asset must be active.'));
        $data += ['currency' => $asset->currency];
        $this->ensureTransactionCurrencyMatchesAsset($asset, $data['currency']);
        $data['reference_number'] = $this->uniqueNumber('WEX', WaqfExpense::class, 'reference_number');
        $data['status'] = 'pending';
        $data['created_by'] = $request->user()->id;

        $expense = WaqfExpense::query()->create($data);

        return $request->expectsJson()
            ? response()->json($expense, 201)
            : redirect()->route('admin.waqf.assets.index')->with('success', __('Waqf expense recorded.'));
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

    public function validateRevenue(Request $request, WaqfRevenue $revenue): JsonResponse|RedirectResponse
    {
        $this->manageableAsset($request->user(), $revenue->waqf_asset_id);
        DB::transaction(function () use ($request, $revenue): void {
            $locked = WaqfRevenue::query()->lockForUpdate()->findOrFail($revenue->id);
            abort_if($locked->status !== 'pending', 422, 'Ce revenu a déjà été traité.');
            $locked->update(['status' => 'validated', 'validated_by' => $request->user()->id, 'validated_at' => now()]);
        });

        return $request->expectsJson()
            ? response()->json($revenue->fresh())
            : redirect()->route('admin.waqf.assets.index')->with('success', __('Waqf revenue validated.'));
    }

    public function validateExpense(Request $request, WaqfExpense $expense): JsonResponse|RedirectResponse
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

        return $request->expectsJson()
            ? response()->json($expense->fresh())
            : redirect()->route('admin.waqf.assets.index')->with('success', __('Waqf expense validated.'));
    }

    private function assetsVisibleTo(User $user): Builder
    {
        if ($user->hasRole('superadmin')) {
            return WaqfAsset::query();
        }

        if ($user->hasRole('admin')) {
            return WaqfAsset::query()->whereHas('mosque', fn (Builder $mosques) => $mosques->administrableBy($user));
        }

        return WaqfAsset::query()->whereRaw('1 = 0');
    }

    private function ensureMosqueManageable(User $user, int $mosqueId): void
    {
        $mosque = Mosque::query()->findOrFail($mosqueId);
        abort_unless($user->hasRole('superadmin') || $user->canAdministerMosque($mosque), 403);
    }

    private function manageableAsset(User $user, int $assetId): WaqfAsset
    {
        $asset = WaqfAsset::query()->with('mosque')->findOrFail($assetId);
        abort_unless($user->hasRole('superadmin') || $user->canAdministerMosque($asset->mosque), 403);

        return $asset;
    }

    private function ensureTransactionCurrencyMatchesAsset(WaqfAsset $asset, string $currency): void
    {
        abort_if($currency !== $asset->currency, 422, __('The transaction currency must match the Waqf asset currency.'));
    }

    private function subtractDecimalAmounts(string $left, string $right): string
    {
        $minor = static function (string $value): string {
            [$whole, $fraction] = array_pad(explode('.', ltrim($value, '+'), 2), 2, '');

            return ltrim(($whole === '' ? '0' : $whole).str_pad(substr($fraction, 0, 2), 2, '0'), '0') ?: '0';
        };
        $leftMinor = $minor($left);
        $rightMinor = $minor($right);
        $negative = strlen($leftMinor) < strlen($rightMinor)
            || (strlen($leftMinor) === strlen($rightMinor) && strcmp($leftMinor, $rightMinor) < 0);
        [$larger, $smaller] = $negative ? [$rightMinor, $leftMinor] : [$leftMinor, $rightMinor];
        $smaller = str_pad($smaller, strlen($larger), '0', STR_PAD_LEFT);
        $borrow = 0;
        $result = '';

        for ($index = strlen($larger) - 1; $index >= 0; $index--) {
            $digit = (int) $larger[$index] - (int) $smaller[$index] - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $result = (string) ($digit < 0 ? $digit + 10 : $digit).$result;
        }

        $result = str_pad(ltrim($result, '0') ?: '0', 3, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').substr($result, 0, -2).'.'.substr($result, -2);
    }

    private function uniqueNumber(string $prefix, string $model, string $column): string
    {
        do {
            $number = $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while ($model::query()->where($column, $number)->exists());

        return $number;
    }
}
