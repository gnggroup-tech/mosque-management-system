<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\InvitationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptUserInvitationRequest;
use App\Services\UserInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvitationAcceptanceController extends Controller
{
    public function show(string $token, UserInvitationService $invitationService): View
    {
        try {
            $invitationService->validInvitation($token);
        } catch (InvitationException) {
            abort(404);
        }

        return view('auth.invitations.accept', ['token' => $token]);
    }

    public function update(
        AcceptUserInvitationRequest $request,
        string $token,
        UserInvitationService $invitationService,
    ): RedirectResponse {
        try {
            $invitationService->accept($token, $request->validated('password'));
        } catch (InvitationException) {
            throw ValidationException::withMessages([
                'invitation' => __('The invitation is invalid or has expired.'),
            ]);
        }

        return redirect()->route('login')->with('status', __('Your invitation was accepted. An administrator must approve your account before you can sign in.'));
    }
}
