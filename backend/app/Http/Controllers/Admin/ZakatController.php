<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Zakat\StoreZakatCollectionRequest;
use App\Http\Requests\Zakat\StoreZakatDistributionRequest;
use App\Models\Faithful;
use App\Models\Mosque;
use App\Models\User;
use App\Models\ZakatBeneficiary;
use App\Models\ZakatCollection;
use App\Models\ZakatDistribution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ZakatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $collections = $this->collectionsVisibleTo($request->user())
            ->with('mosque:id,code,name')
            ->when($request->filled('mosque_id'), fn (Builder $q) => $q->where('mosque_id', $request->integer('mosque_id')))
            ->when($request->filled('category'), fn (Builder $q) => $q->where('category', $request->string('category')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->latest('collected_at')->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return response()->json($collections);
    }

    public function storeCollection(StoreZakatCollectionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->ensureMosqueManageable($request->user(), (int) $data['mosque_id']);
        $this->ensureFaithfulBelongsToMosque($data['faithful_id'] ?? null, (int) $data['mosque_id']);
        if (($data['assessable_amount'] ?? null) !== null && ($data['rate'] ?? null) !== null) {
            $calculated = round(((float) $data['assessable_amount'] * (float) $data['rate']) / 100, 2);
            abort_if(abs($calculated - (float) $data['amount']) > 0.01, 422, 'Le montant ne correspond pas au calcul de la Zakat.');
        }
        $data += ['currency' => 'GNF', 'is_anonymous' => false];
        if ($data['is_anonymous']) {
            $data['faithful_id'] = null;
            $data['payer_name'] = null;
        }
        $data['receipt_number'] = $this->uniqueNumber('ZAK', ZakatCollection::class, 'receipt_number');
        $data['status'] = 'pending';
        $data['created_by'] = $request->user()->getKey();

        return response()->json(ZakatCollection::query()->create($data), 201);
    }

    public function validateCollection(Request $request, ZakatCollection $collection): JsonResponse
    {
        $this->ensureMosqueManageable($request->user(), $collection->mosque_id);
        DB::transaction(function () use ($request, $collection): void {
            $locked = ZakatCollection::query()->lockForUpdate()->findOrFail($collection->id);
            abort_if($locked->status !== 'pending', 422, 'Cette collecte a déjà été traitée.');
            $locked->update(['status' => 'validated', 'validated_by' => $request->user()->id, 'validated_at' => now()]);
        });

        return response()->json($collection->fresh());
    }

    public function storeBeneficiary(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('zakat.manage'), 403);
        $data = $request->validate([
            'mosque_id' => ['required', 'integer', 'exists:mosques,id'],
            'faithful_id' => ['nullable', 'integer', 'exists:faithful,id'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'category' => ['required', Rule::in(['poor', 'needy', 'administrators', 'reconciliation', 'freeing_captives', 'debtors', 'cause_of_allah', 'travelers'])],
            'eligibility_reason' => ['required', 'string', 'max:2000'],
        ]);
        $this->ensureMosqueManageable($request->user(), (int) $data['mosque_id']);
        $this->ensureFaithfulBelongsToMosque($data['faithful_id'] ?? null, (int) $data['mosque_id']);
        $data['beneficiary_number'] = $this->uniqueNumber('BEN', ZakatBeneficiary::class, 'beneficiary_number');
        $data['status'] = 'active';
        $data['verified_at'] = now()->toDateString();
        $data['verified_by'] = $request->user()->id;

        return response()->json(ZakatBeneficiary::query()->create($data), 201);
    }

    public function storeDistribution(StoreZakatDistributionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->ensureMosqueManageable($request->user(), (int) $data['mosque_id']);
        $beneficiary = ZakatBeneficiary::query()->whereKey($data['zakat_beneficiary_id'])->where('mosque_id', $data['mosque_id'])->where('status', 'active')->first();
        abort_unless($beneficiary, 422, 'Le bénéficiaire doit être actif et rattaché à la mosquée.');
        $data += ['currency' => 'GNF'];
        $data['reference_number'] = $this->uniqueNumber('DIS', ZakatDistribution::class, 'reference_number');
        $data['status'] = 'pending';
        $data['created_by'] = $request->user()->id;

        return response()->json(ZakatDistribution::query()->create($data), 201);
    }

    public function validateDistribution(Request $request, ZakatDistribution $distribution): JsonResponse
    {
        $this->ensureMosqueManageable($request->user(), $distribution->mosque_id);
        DB::transaction(function () use ($request, $distribution): void {
            $locked = ZakatDistribution::query()->lockForUpdate()->findOrFail($distribution->id);
            abort_if($locked->status !== 'pending', 422, 'Cette distribution a déjà été traitée.');
            $collected = ZakatCollection::query()->where('mosque_id', $locked->mosque_id)->where('category', $locked->category)->where('currency', $locked->currency)->where('status', 'validated')->lockForUpdate()->get(['amount'])->sum('amount');
            $distributed = ZakatDistribution::query()->where('mosque_id', $locked->mosque_id)->where('category', $locked->category)->where('currency', $locked->currency)->where('status', 'validated')->lockForUpdate()->get(['amount'])->sum('amount');
            abort_if((float) $locked->amount > ((float) $collected - (float) $distributed), 422, 'Solde de Zakat insuffisant pour cette distribution.');
            $locked->update(['status' => 'validated', 'validated_by' => $request->user()->id, 'validated_at' => now()]);
        });

        return response()->json($distribution->fresh());
    }

    private function collectionsVisibleTo(User $user): Builder
    {
        return ZakatCollection::query()->when($user->hasRole('admin'), fn (Builder $q) => $q->whereHas('mosque', fn (Builder $m) => $m->where('admin_id', $user->id)));
    }

    private function ensureMosqueManageable(User $user, int $mosqueId): void
    {
        $mosque = Mosque::query()->findOrFail($mosqueId);
        abort_unless($user->hasRole('superadmin') || $mosque->admin_id === $user->id, 403);
    }

    private function ensureFaithfulBelongsToMosque(?int $faithfulId, int $mosqueId): void
    {
        if ($faithfulId !== null) {
            abort_unless(Faithful::query()->whereKey($faithfulId)->where('mosque_id', $mosqueId)->exists(), 422);
        }
    }

    private function uniqueNumber(string $prefix, string $model, string $column): string
    {
        do {
            $number = $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while ($model::query()->where($column, $number)->exists());

        return $number;
    }
}
