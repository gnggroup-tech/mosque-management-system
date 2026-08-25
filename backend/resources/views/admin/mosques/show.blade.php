<x-app-layout>
    <x-slot name="header"><div class="flex items-center justify-between gap-4"><div class="flex min-w-0 items-center gap-3"><a href="{{ route('admin.mosques.index') }}" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="{{ __('Back to mosques') }}">←</a><div class="min-w-0"><h1 class="truncate text-lg font-bold">{{ $mosque->name }}</h1><p class="text-xs text-slate-500">{{ $mosque->code }}</p></div></div>@if(Auth::user()->can('mosques.update') && (Auth::user()->hasRole('superadmin') || Auth::user()->canAdministerMosque($mosque)))<a href="{{ route('admin.mosques.edit', $mosque) }}" class="shrink-0 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-600 focus:ring-offset-2">{{ __('Edit') }}</a>@endif</div></x-slot>
    <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="grid gap-6 lg:grid-cols-3">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2" aria-labelledby="details-heading">
                <div class="flex items-center justify-between"><h2 id="details-heading" class="text-lg font-bold">{{ __('Mosque details') }}</h2><x-mosque-status-badge :status="$mosque->status" /></div>
                <dl class="mt-6 grid gap-5 sm:grid-cols-2">
                    @foreach ([[__('Region'), $mosque->region],[__('Prefecture'), $mosque->prefecture],[__('Commune'), $mosque->commune],[__('Address'), $mosque->address ?: __('Not specified')],[__('Phone'), $mosque->phone ?: __('Not specified')],[__('Email'), $mosque->email ?: __('Not specified')]] as [$label, $value])
                        <div><dt class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $label }}</dt><dd class="mt-1 break-words text-sm font-medium text-slate-800">{{ $value }}</dd></div>
                    @endforeach
                </dl>
                <div class="mt-6 border-t border-slate-100 pt-6"><h3 class="text-sm font-bold">{{ __('Infrastructures') }}</h3>@if(filled($mosque->infrastructures))<ul class="mt-3 flex flex-wrap gap-2">@foreach($mosque->infrastructures as $infrastructure)<li class="rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700">{{ $infrastructure }}</li>@endforeach</ul>@else<p class="mt-2 text-sm text-slate-500">{{ __('No infrastructure recorded.') }}</p>@endif</div>
            </section>
            <aside class="space-y-6">
                <section class="rounded-2xl bg-slate-950 p-6 text-white"><p class="text-xs font-semibold uppercase tracking-wider text-emerald-300">{{ __('Primary administrator') }}</p><p class="mt-3 text-lg font-bold">{{ $mosque->administrator?->name ?? __('Not assigned') }}</p>@if($mosque->administrator)<p class="mt-1 break-all text-sm text-slate-400">{{ $mosque->administrator->email }}</p>@endif</section>
                <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="font-bold">{{ __('Coordinates') }}</h2>@if($mosque->latitude !== null && $mosque->longitude !== null)<p class="mt-3 text-sm text-slate-600">{{ $mosque->latitude }}, {{ $mosque->longitude }}</p>@else<p class="mt-3 text-sm text-slate-500">{{ __('Not specified') }}</p>@endif</section>
            </aside>
        </div>
    </div>
</x-app-layout>
