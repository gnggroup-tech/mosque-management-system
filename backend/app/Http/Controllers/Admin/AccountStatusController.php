<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccountStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ManageAccountStatusRequest;
use App\Models\User;
use App\Services\AdministrativeAccountStatusService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AccountStatusController extends Controller
{
    public function suspend(
        ManageAccountStatusRequest $request,
        User $account,
        AdministrativeAccountStatusService $statusService,
    ): JsonResponse {
        Gate::authorize('suspend', $account);

        return $this->execute(fn (): User => $statusService->suspend(
            $account,
            $request->user(),
            $request->validated('reason'),
        ));
    }

    public function reactivate(
        ManageAccountStatusRequest $request,
        User $account,
        AdministrativeAccountStatusService $statusService,
    ): JsonResponse {
        Gate::authorize('reactivate', $account);

        return $this->execute(fn (): User => $statusService->reactivate(
            $account,
            $request->user(),
            $request->validated('reason'),
        ));
    }

    public function archive(
        ManageAccountStatusRequest $request,
        User $account,
        AdministrativeAccountStatusService $statusService,
    ): JsonResponse {
        Gate::authorize('archive', $account);

        return $this->execute(fn (): User => $statusService->archive(
            $account,
            $request->user(),
            $request->validated('reason'),
        ));
    }

    private function execute(Closure $operation): JsonResponse
    {
        try {
            $account = $operation();
        } catch (AccountStatusTransitionException) {
            throw ValidationException::withMessages([
                'account' => 'The account status cannot be changed.',
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $account->getKey(),
                'status' => $account->status->value,
            ],
        ]);
    }
}
