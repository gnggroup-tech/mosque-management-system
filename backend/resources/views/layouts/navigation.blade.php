@php
    $unreadAnnouncements = Auth::user()->can('announcements.view')
        ? App\Models\AnnouncementReceipt::query()->where('user_id', Auth::id())->whereNull('read_at')->count()
        : 0;
    $navigation = [
        ['label' => __('Dashboard'), 'route' => 'dashboard', 'active' => 'dashboard', 'permission' => null, 'icon' => 'home'],
        ['label' => __('Mosques'), 'route' => 'admin.mosques.index', 'active' => 'admin.mosques.*', 'permission' => 'mosques.view', 'icon' => 'mosque'],
        ['label' => __('Faithful'), 'route' => 'admin.faithful.index', 'active' => 'admin.faithful.*', 'permission' => 'faithful.view', 'icon' => 'faithful'],
        ['label' => __('Councils'), 'route' => 'admin.councils.index', 'active' => ['admin.councils.*', 'admin.council-members.*', 'admin.council-meetings.*'], 'permission' => 'councils.view', 'icon' => 'council'],
        ['label' => __('Activities'), 'route' => 'admin.activities.index', 'active' => 'admin.activities.*', 'permission' => 'activities.view', 'icon' => 'calendar'],
        ['label' => __('Announcements'), 'route' => 'admin.announcements.index', 'active' => 'admin.announcements.*', 'permission' => 'announcements.view', 'icon' => 'announcement', 'badge' => $unreadAnnouncements],
        ['label' => __('Accounts'), 'route' => 'admin.accounts.index', 'active' => 'admin.accounts.*', 'permission' => 'users.directory.view', 'icon' => 'users'],
        ['label' => __('Data exports'), 'route' => 'admin.reports.index', 'active' => 'admin.reports.*', 'permission' => 'reports.view', 'icon' => 'report'],
    ];
@endphp
<div x-cloak x-show="sidebarOpen" class="fixed inset-0 z-40 bg-slate-950/50 lg:hidden" @click="sidebarOpen = false" aria-hidden="true"></div>
<aside :class="sidebarOpen ? 'translate-x-0' : '{{ app()->getLocale() === 'ar' ? 'translate-x-full' : '-translate-x-full' }}'" class="fixed inset-y-0 start-0 z-50 flex w-72 flex-col bg-slate-950 text-white transition-transform duration-200 lg:translate-x-0" aria-label="{{ __('Main navigation') }}">
    <div class="flex h-20 shrink-0 items-center justify-between border-b border-white/10 px-6">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-emerald-400"><span class="grid h-10 w-10 place-items-center rounded-xl bg-emerald-500 text-lg font-bold text-slate-950">G</span><span><span class="block text-sm font-bold tracking-[0.18em]">GNG GROUP</span><span class="block text-xs text-slate-400">{{ __('Mosque Management') }}</span></span></a>
        <button type="button" @click="sidebarOpen = false" class="rounded-lg p-2 text-slate-300 hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-emerald-400 lg:hidden" aria-label="{{ __('Close navigation') }}"><svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg></button>
    </div>
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-24 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:text-slate-900">{{ __('Skip to content') }}</a>
    <nav class="min-h-0 flex-1 space-y-1 overflow-y-auto px-4 py-6">
        @foreach ($navigation as $item)
            @if ($item['permission'] === null || Auth::user()->can($item['permission']))
                <a href="{{ route($item['route']) }}" @class(['group flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium transition focus:outline-none focus:ring-2 focus:ring-emerald-400','bg-emerald-500 text-slate-950 shadow-lg shadow-emerald-950/20' => request()->routeIs(...(array) $item['active']),'text-slate-300 hover:bg-white/10 hover:text-white' => ! request()->routeIs(...(array) $item['active'])]) aria-current="{{ request()->routeIs(...(array) $item['active']) ? 'page' : 'false' }}">
                    @if ($item['icon'] === 'home')<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l9-9 9 9M5 10v10h14V10M9 20v-6h6v6" /></svg>
                    @elseif ($item['icon'] === 'mosque')<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 20h16M6 20V9h12v11M9 9V6a3 3 0 016 0v3M3 9h18M9 14h6" /></svg>
                    @elseif (in_array($item['icon'], ['users', 'faithful', 'council'], true))<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8M22 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" /></svg>
                    @elseif ($item['icon'] === 'calendar')<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 2v4m8-4v4M3 10h18M5 4h14a2 2 0 012 2v14H3V6a2 2 0 012-2z" /></svg>
                    @elseif ($item['icon'] === 'announcement')<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5L6 9H3v6h3l5 4V5zm4 4a4 4 0 010 6m3-9a8 8 0 010 12" /></svg>
                    @else<svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-6m4 6V7m4 10v-3M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>@endif
                    {{ $item['label'] }}
                    @if (($item['badge'] ?? 0) > 0)<span class="ms-auto rounded-full bg-rose-500 px-2 py-0.5 text-xs font-bold text-white">{{ min($item['badge'], 99) }}</span>@endif
                </a>
            @endif
        @endforeach
    </nav>
    <div class="shrink-0 border-t border-white/10 p-4">
        <div class="mb-3 flex items-center gap-3 px-2"><span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-white/10 text-sm font-bold">{{ mb_strtoupper(mb_substr(Auth::user()->name, 0, 1)) }}</span><div class="min-w-0"><p class="truncate text-sm font-semibold">{{ Auth::user()->name }}</p><p class="truncate text-xs text-slate-400">{{ Auth::user()->roles->pluck('name')->map(fn ($role) => __($role))->join(', ') }}</p></div></div>
        <div class="grid grid-cols-2 gap-2"><a href="{{ route('profile.edit') }}" class="rounded-lg bg-white/5 px-3 py-2 text-center text-xs font-medium text-slate-300 hover:bg-white/10">{{ __('Profile') }}</a><form method="POST" action="{{ route('logout') }}">@csrf<button class="w-full rounded-lg bg-white/5 px-3 py-2 text-xs font-medium text-slate-300 hover:bg-white/10">{{ __('Log Out') }}</button></form></div>
        <div class="mt-3 sm:hidden">@include('partials.locale-switcher')</div>
    </div>
</aside>
