<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(config('app.supported_locales', ['fr', 'en', 'ar']))],
        ]);

        $request->session()->put('locale', $validated['locale']);
        $request->user()?->update(['locale' => $validated['locale']]);

        return back();
    }
}
