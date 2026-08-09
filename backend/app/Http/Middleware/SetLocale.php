<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('app.supported_locales', ['fr', 'en', 'ar']);
        $locale = $request->user()?->locale ?? $request->session()->get('locale', config('app.locale', 'fr'));

        if (! in_array($locale, $supported, true)) {
            $locale = config('app.fallback_locale', 'fr');
        }

        App::setLocale($locale);

        return $next($request);
    }
}
