<x-app-layout>
    <x-slot name="header"><div><h1 class="text-lg font-bold text-slate-900">{{ __('Dashboard') }}</h1><p class="text-xs text-slate-500">{{ __('A secure overview of your authorized scope.') }}</p></div></x-slot>
    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <section aria-labelledby="overview-heading">
            <div class="mb-4 flex items-center justify-between"><div><p class="text-sm font-semibold uppercase tracking-wider text-emerald-700">{{ __('Overview') }}</p><h2 id="overview-heading" class="text-2xl font-bold tracking-tight">{{ __('Welcome back, :name', ['name' => Auth::user()->name]) }}</h2></div><span class="hidden rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800 sm:inline">{{ __('Canonical scope') }}</span></div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([['key' => 'mosques', 'value' => $metrics['mosques'], 'label' => __('Authorized mosques'), 'tone' => 'bg-emerald-100'],['key' => 'active-accounts', 'value' => $metrics['active_accounts'], 'label' => __('Active accounts'), 'tone' => 'bg-sky-100'],['key' => 'pending-approvals', 'value' => $metrics['pending_approvals'], 'label' => __('Pending approvals'), 'tone' => 'bg-amber-100'],['key' => 'upcoming-activities', 'value' => $metrics['upcoming_activities'], 'label' => __('Upcoming activities'), 'tone' => 'bg-violet-100']] as $card)
                    @if ($card['value'] !== null)
                        <article data-testid="metric-{{ $card['key'] }}" data-value="{{ $card['value'] }}" class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="absolute -end-6 -top-6 h-20 w-20 rounded-full {{ $card['tone'] }}"></div><p class="relative text-sm font-medium text-slate-500">{{ $card['label'] }}</p><p class="relative mt-3 text-3xl font-bold text-slate-950">{{ number_format($card['value']) }}</p></article>
                    @endif
                @endforeach
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-3">
            @can('activities.view')
                <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm xl:col-span-2" aria-labelledby="activities-heading">
                    <div class="border-b border-slate-100 px-5 py-4"><h2 id="activities-heading" class="font-bold">{{ __('Next activities') }}</h2><p class="mt-1 text-sm text-slate-500">{{ __('Published events in your authorized mosques.') }}</p></div>
                    @forelse ($upcomingActivities as $activity)
                        <article class="flex flex-col gap-2 border-b border-slate-100 px-5 py-4 last:border-0 sm:flex-row sm:items-center sm:justify-between"><div><p class="font-semibold">{{ $activity->title }}</p><p class="text-sm text-slate-500">{{ $activity->mosque->name }} · {{ $activity->location ?: __('Location not specified') }}</p></div><time class="shrink-0 rounded-lg bg-slate-100 px-3 py-2 text-sm font-medium" datetime="{{ $activity->starts_at->toIso8601String() }}">{{ $activity->starts_at->translatedFormat('d M Y · H:i') }}</time></article>
                    @empty
                        <div class="px-5 py-12 text-center"><p class="font-semibold text-slate-700">{{ __('No upcoming activity') }}</p><p class="mt-1 text-sm text-slate-500">{{ __('Published activities will appear here.') }}</p></div>
                    @endforelse
                </section>
            @endcan

            @can('finances.view')
                <section class="rounded-2xl bg-slate-950 p-5 text-white shadow-sm" aria-labelledby="finance-heading">
                    <h2 id="finance-heading" class="font-bold">{{ __('Financial overview') }}</h2><p class="mt-1 text-sm text-slate-400">{{ __('Validated flows in your authorized scope.') }}</p>
                    <div class="mt-5 space-y-4">
                        @foreach ($financialOverview as $row)
                            <div class="rounded-xl bg-white/5 p-4"><div class="flex items-center justify-between"><span class="text-sm font-semibold text-emerald-300">{{ $row['currency'] }}</span><span class="text-xs text-slate-400">{{ __('Balance') }}</span></div><p class="mt-2 text-xl font-bold">{{ number_format($row['balance'], 2, '.', ' ') }}</p><div class="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-400"><span>{{ __('Resources') }}: {{ number_format($row['resources'], 2, '.', ' ') }}</span><span>{{ __('Expenses') }}: {{ number_format($row['expenses'], 2, '.', ' ') }}</span></div></div>
                        @endforeach
                    </div>
                </section>
            @endcan
        </div>

        <section aria-labelledby="financial-actions-heading">
            <h2 id="financial-actions-heading" class="mb-4 font-bold">{{ __('Financial actions') }}</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                @can('contributions.view')<a href="{{ route('admin.donations.index') }}" class="rounded-xl border bg-white p-4 font-semibold text-emerald-700 shadow-sm">{{ __('Donations') }}</a>@endcan
                @can('finances.view')<a href="{{ route('admin.finances.report') }}" class="rounded-xl border bg-white p-4 font-semibold text-emerald-700 shadow-sm">{{ __('Finances') }}</a>@endcan
                @can('zakat.view')<a href="{{ route('admin.zakat.collections.index') }}" class="rounded-xl border bg-white p-4 font-semibold text-emerald-700 shadow-sm">{{ __('Zakat') }}</a>@endcan
                @can('waqf.view')<a href="{{ route('admin.waqf.assets.index') }}" class="rounded-xl border bg-white p-4 font-semibold text-emerald-700 shadow-sm">{{ __('Waqf') }}</a>@endcan
                @can('reports.view')<a href="{{ route('admin.reports.index') }}" class="rounded-xl border bg-white p-4 font-semibold text-emerald-700 shadow-sm">{{ __('Reports') }}</a>@endcan
            </div>
        </section>
    </div>
</x-app-layout>
