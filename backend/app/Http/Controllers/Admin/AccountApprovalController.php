<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AccountStatusTransitionException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AccountApprovalController extends Controller
{
    public function __invoke(
        Request $request,
        User $account,
        AccountApprovalService $approvalService,
    ): JsonResponse {
        Gate::authorize('approve', $account);

        try {
            $approved = $approvalService->approve($account, $request->user());
        } catch (AccountStatusTransitionException) {
            throw ValidationException::withMessages([
                'account' => 'The account cannot be approved.',
            ]);
        }

        return response()->json([
            'data' => [
                'id' => $approved->getKey(),
                'status' => $approved->status->value,
                'activated_at' => $approved->activated_at->toIso8601String(),
            ],
        ]);
    }
}
