<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authenticated = $request->user();
        $account = $authenticated === null
            ? null
            : User::query()->find($authenticated->getAuthIdentifier());

        if ($account?->canAuthenticate()) {
            Auth::guard('web')->setUser($account);

            return $next($request);
        }

        $userId = $authenticated?->getAuthIdentifier();
        $status = $account?->status->value;

        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($account !== null) {
            $this->auditLogger->log(
                'user.authentication.revoked',
                $account,
                [
                    'user_id' => $userId,
                    'status' => $status,
                    'occurred_at' => now()->toIso8601String(),
                    'reason' => 'account_not_active',
                ],
                $userId,
            );
        }

        return $this->authenticationFailedResponse();
    }

    private function authenticationFailedResponse(): RedirectResponse
    {
        return redirect()->route('login')->withErrors([
            'email' => trans('auth.failed'),
        ]);
    }
}
