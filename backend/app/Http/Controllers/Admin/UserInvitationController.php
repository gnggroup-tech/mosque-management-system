<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InvitationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\StoreUserInvitationRequest;
use App\Models\User;
use App\Notifications\UserInvitationNotification;
use App\Services\UserInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserInvitationController extends Controller
{
    public function create(): View
    {
        Gate::authorize('invite', User::class);

        return view('admin.accounts.invitations.create', [
            'locales' => config('app.supported_locales', ['fr', 'en', 'ar']),
        ]);
    }

    public function store(
        StoreUserInvitationRequest $request,
        UserInvitationService $invitationService,
    ): RedirectResponse {
        Gate::authorize('invite', User::class);

        $result = $invitationService->invite($request->validated(), $request->user());
        $account = $result['invitation']->user()->firstOrFail();
        Notification::locale($account->locale)->send(
            $account,
            new UserInvitationNotification($result['token'], $result['invitation']->expires_at),
        );

        return redirect()->route('admin.accounts.invitations.create')
            ->with('status', __('The invitation was sent successfully.'));
    }

    public function resend(
        User $account,
        UserInvitationService $invitationService,
    ): RedirectResponse {
        Gate::authorize('invite', User::class);

        try {
            $result = $invitationService->resend($account, request()->user());
        } catch (InvitationException) {
            throw ValidationException::withMessages([
                'invitation' => __('The invitation cannot be resent.'),
            ]);
        }

        Notification::locale($account->locale)->send(
            $account,
            new UserInvitationNotification($result['token'], $result['invitation']->expires_at),
        );

        return back()->with('status', __('The invitation was resent successfully.'));
    }
}
