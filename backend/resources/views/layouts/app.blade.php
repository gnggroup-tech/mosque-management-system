<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('app.name', 'GNG Mosque Management') }}</title>
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-slate-50 font-sans text-slate-900 antialiased">
        <div class="min-h-screen" x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">
            @include('layouts.navigation')
            <div class="lg:ps-72">
                <header class="sticky top-0 z-20 border-b border-slate-200/80 bg-white/90 backdrop-blur">
                    <div class="flex h-16 items-center gap-3 px-4 sm:px-6 lg:px-8">
                        <button type="button" @click="sidebarOpen = true" class="inline-flex rounded-lg p-2 text-slate-600 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-emerald-600 lg:hidden" aria-label="{{ __('Open navigation') }}">
                            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
                        </button>
                        <div class="min-w-0 flex-1">@isset($header){{ $header }}@else<h1 class="truncate text-lg font-semibold">{{ __('Dashboard') }}</h1>@endisset</div>
                        <div class="hidden sm:block">@include('partials.locale-switcher')</div>
                    </div>
                </header>
                <main id="main-content" tabindex="-1">
                    @if (session('success'))
                        <div class="mx-auto max-w-7xl px-4 pt-5 sm:px-6 lg:px-8" role="status"><div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('success') }}</div></div>
                    @endif
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
