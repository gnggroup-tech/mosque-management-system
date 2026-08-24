<?php

namespace App\Http\Middleware;

use App\Models\UserInvitation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetInvitationLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->route('token');
        $invitation = UserInvitation::query()
            ->with('user:id,locale')
            ->where('token_hash', hash('sha256', $token))
            ->first();
        $locale = $invitation?->user?->locale;

        if (in_array($locale, config('app.supported_locales', ['fr', 'en', 'ar']), true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
