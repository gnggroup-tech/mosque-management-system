<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Donation\StoreDonationRequest;
use App\Http\Requests\Donation\UpdateDonationRequest;
use App\Models\Donation;
use App\Models\Faithful;
use App\Models\Mosque;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DonationController extends Controller
{
    public function index(Request $request): JsonResponse|View
    {
        $donations = $this->visibleTo($request->user())
            ->with(['mosque:id,code,name', 'faithful:id,registration_number,first_name,last_name'])
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $request->string('search')->toString()).'%';
                $query->where(fn (Builder $nested) => $nested->where('receipt_number', 'like', $search)
                    ->orWhere('donor_name', 'like', $search)->orWhere('payment_reference', 'like', $search));
            })
            ->when($request->filled('mosque_id'), fn (Builder $q) => $q->where('mosque_id', $request->integer('mosque_id')))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->when($request->filled('contribution_type'), fn (Builder $q) => $q->where('contribution_type', $request->string('contribution_type')))
            ->when($request->filled('from'), fn (Builder $q) => $q->whereDate('received_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->whereDate('received_at', '<=', $request->date('to')))
            ->latest('received_at')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        if ($request->expectsJson()) {
            return response()->json($donations);
        }

        return view('admin.donations.index', [
            'donations' => $donations->withQueryString(),
            'mosques' => $this->manageableMosques($request->user())->get(['id', 'name']),
            'filters' => $request->only(['search', 'mosque_id', 'status', 'contribution_type', 'from', 'to']),
        ]);
    }

    public function create(Request $request): View
    {
        $mosques = $this->manageableMosques($request->user())->get(['id', 'name']);

        return view('admin.donations.create', [
            'mosques' => $mosques,
            'faithful' => Faithful::query()->whereIn('mosque_id', $mosques->pluck('id'))
                ->where('status', 'active')->orderBy('first_name')->get(['id', 'mosque_id', 'registration_number', 'first_name', 'last_name']),
        ]);
    }

    public function store(StoreDonationRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $this->ensureMosqueManageable($request->user(), (int) $data['mosque_id']);
        $this->ensureFaithfulBelongsToMosque($data['faithful_id'] ?? null, (int) $data['mosque_id']);
        $data['created_by'] = $request->user()->getKey();
        $data['receipt_number'] = $this->newReceiptNumber();
        $data['status'] = 'pending';
        $data['is_anonymous'] = $request->boolean('is_anonymous');

        if ($data['is_anonymous']) {
            $data['faithful_id'] = null;
            $data['donor_name'] = null;
            $data['donor_phone'] = null;
            $data['donor_email'] = null;
        }

        $donation = Donation::query()->create($data);

        if ($request->expectsJson()) {
            return response()->json($donation->load('mosque:id,code,name'), 201);
        }

        return redirect()->route('admin.donations.show', $donation)
            ->with('success', __('Contribution recorded. Receipt reference: :number', ['number' => $donation->receipt_number]));
    }

    public function show(Request $request, Donation $donation): JsonResponse|View
    {
        abort_unless($this->visibleTo($request->user())->whereKey($donation)->exists(), 403);

        $donation->load(['mosque:id,code,name', 'faithful:id,registration_number,first_name,last_name']);

        if ($request->expectsJson()) {
            return response()->json($donation);
        }

        return view('admin.donations.show', ['donation' => $donation]);
    }

    public function update(UpdateDonationRequest $request, Donation $donation): JsonResponse
    {
        $this->ensureMosqueManageable($request->user(), $donation->mosque_id);
        abort_if($donation->status !== 'pending', 422, 'Une contribution validée ou rejetée ne peut plus être modifiée.');

        $data = $request->validated();
        if (array_key_exists('is_anonymous', $data)) {
            $data['is_anonymous'] = $request->boolean('is_anonymous');
        }
        $mosqueId = (int) ($data['mosque_id'] ?? $donation->mosque_id);
        $this->ensureMosqueManageable($request->user(), $mosqueId);
        $this->ensureFaithfulBelongsToMosque($data['faithful_id'] ?? $donation->faithful_id, $mosqueId);

        if (($data['is_anonymous'] ?? $donation->is_anonymous) === true) {
            $data['faithful_id'] = null;
            $data['donor_name'] = null;
            $data['donor_phone'] = null;
            $data['donor_email'] = null;
        }

        $donation->update($data);

        return response()->json($donation->fresh()->load('mosque:id,code,name'));
    }

    public function validateDonation(Request $request, Donation $donation): JsonResponse|RedirectResponse
    {
        $this->ensureMosqueManageable($request->user(), $donation->mosque_id);
        abort_if($donation->status !== 'pending', 422, 'Cette contribution a déjà été traitée.');

        DB::transaction(function () use ($request, $donation): void {
            $locked = Donation::query()->lockForUpdate()->findOrFail($donation->getKey());
            abort_if($locked->status !== 'pending', 422, 'Cette contribution a déjà été traitée.');
            $locked->update([
                'status' => 'validated',
                'validated_by' => $request->user()->getKey(),
                'validated_at' => now(),
            ]);
        });

        if ($request->expectsJson()) {
            return response()->json($donation->fresh());
        }

        return redirect()->route('admin.donations.show', $donation)->with('success', __('Contribution validated.'));
    }

    public function reject(Request $request, Donation $donation): JsonResponse|RedirectResponse
    {
        $this->ensureMosqueManageable($request->user(), $donation->mosque_id);
        abort_if($donation->status !== 'pending', 422, 'Cette contribution a déjà été traitée.');
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        DB::transaction(function () use ($request, $donation, $validated): void {
            $locked = Donation::query()->lockForUpdate()->findOrFail($donation->getKey());
            abort_if($locked->status !== 'pending', 422, 'Cette contribution a déjà été traitée.');
            $locked->update([
                'status' => 'rejected',
                'validated_by' => $request->user()->getKey(),
                'validated_at' => now(),
                'notes' => trim(($locked->notes ? $locked->notes."\n" : '').'Rejet: '.$validated['reason']),
            ]);
        });

        if ($request->expectsJson()) {
            return response()->json($donation->fresh());
        }

        return redirect()->route('admin.donations.show', $donation)->with('success', __('Contribution rejected.'));
    }

    public function destroy(Request $request, Donation $donation): JsonResponse
    {
        $this->ensureMosqueManageable($request->user(), $donation->mosque_id);
        abort_if($donation->status === 'validated', 422, 'Une contribution validée ne peut pas être supprimée.');
        $donation->delete();

        return response()->json(status: 204);
    }

    private function visibleTo(User $user): Builder
    {
        if ($user->hasRole('superadmin')) {
            return Donation::query();
        }

        if ($user->hasRole('admin')) {
            return Donation::query()->whereHas('mosque', fn (Builder $mosques) => $mosques->administrableBy($user));
        }

        return Donation::query()->whereRaw('1 = 0');
    }

    private function manageableMosques(User $user): Builder
    {
        return Mosque::query()->administrableBy($user)->orderBy('name');
    }

    private function ensureMosqueManageable(User $user, int $mosqueId): void
    {
        $mosque = Mosque::query()->findOrFail($mosqueId);
        abort_unless($user->hasRole('superadmin') || $user->canAdministerMosque($mosque), 403);
    }

    private function ensureFaithfulBelongsToMosque(?int $faithfulId, int $mosqueId): void
    {
        if ($faithfulId !== null) {
            abort_unless(Faithful::query()->whereKey($faithfulId)->where('mosque_id', $mosqueId)->exists(), 422);
        }
    }

    private function newReceiptNumber(): string
    {
        do {
            $number = 'DON-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
        } while (Donation::query()->where('receipt_number', $number)->exists());

        return $number;
    }
}
