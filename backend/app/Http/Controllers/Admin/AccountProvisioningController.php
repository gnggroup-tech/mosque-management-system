<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AccountStatus;
use App\Enums\MosqueMembershipType;
use App\Exceptions\AccountProvisioningException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ProvisionAccountRequest;
use App\Models\Mosque;
use App\Models\User;
use App\Services\AccountProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountProvisioningController extends Controller
{
    public function edit(
        User $account,
        AccountProvisioningService $provisioningService,
    ): View {
        Gate::authorize('provision', $account);

        $account->load(['roles:id,name', 'mosqueMemberships.mosque:id,name,code', 'administeredMosques:id,name,admin_id']);
        $mosques = Mosque::query()->orderBy('name')->get(['id', 'code', 'name', 'status', 'admin_id']);
        $replacementCandidates = User::query()
            ->where('status', AccountStatus::Active->value)
            ->whereKeyNot($account->getKey())
            ->role('admin')
            ->whereHas('mosqueMemberships', fn ($query) => $query->where(
                'membership_type',
                MosqueMembershipType::Administrator->value,
            ))
            ->with(['mosqueMemberships' => fn ($query) => $query->where(
                'membership_type',
                MosqueMembershipType::Administrator->value,
            )])
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('admin.accounts.provisioning.edit', [
            'account' => $account,
            'mosques' => $mosques,
            'memberships' => $account->mosqueMemberships->keyBy('mosque_id'),
            'replacementCandidates' => $replacementCandidates,
            'version' => $provisioningService->versionFor($account),
        ]);
    }

    public function update(
        ProvisionAccountRequest $request,
        User $account,
        AccountProvisioningService $provisioningService,
    ): RedirectResponse {
        Gate::authorize('provision', $account);
        $data = $request->validated();

        try {
            $provisioningService->provision(
                $account,
                $request->user(),
                $data['role'],
                $data['memberships'],
                array_map('intval', $data['primary_mosque_ids']),
                $data['primary_replacements'],
                $data['version'],
            );
        } catch (AccountProvisioningException) {
            throw ValidationException::withMessages([
                'provisioning' => __('The provisioning request is invalid or the account changed. Refresh and try again.'),
            ]);
        }

        return redirect()->route('admin.accounts.provisioning.edit', $account)
            ->with('status', __('The account role and mosque memberships were updated.'));
    }
}
