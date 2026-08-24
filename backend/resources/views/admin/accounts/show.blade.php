<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <a href="{{ route('admin.accounts.index') }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">{{ __('Back to accounts') }}</a>
                <h1 class="mt-1 text-xl font-semibold text-gray-800">{{ $account->name }}</h1>
            </div>
            <x-account-status-badge :status="$account->status" />
        </div>
    </x-slot>

    <div class="py-8" x-data="accountDirectoryPage()" x-init="init()">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            <div x-cloak x-show="message" x-text="message" role="status" class="rounded-md bg-emerald-50 p-4 text-sm font-medium text-emerald-800"></div>

            <section class="rounded-lg bg-white p-5 shadow-sm sm:p-6" aria-labelledby="account-details-heading">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 id="account-details-heading" class="text-lg font-semibold text-gray-900">{{ __('Account details') }}</h2>
                        <p class="mt-1 text-sm text-gray-500">{{ __('Administrative identity and lifecycle information.') }}</p>
                    </div>
                    @include('admin.accounts.partials.actions', ['account' => $account])
                </div>

                <dl class="mt-6 grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Account ID') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ $account->getKey() }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Name') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ $account->name }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Email') }}</dt><dd class="mt-1 break-all text-sm text-gray-900">{{ $account->email }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Locale') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ strtoupper($account->locale) }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Roles') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ $account->roles->pluck('name')->map(fn ($role) => __($role))->join(', ') ?: __('No role') }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Created at') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ $account->created_at?->translatedFormat('Y-m-d H:i') ?: __('Not available') }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Updated at') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ $account->updated_at?->translatedFormat('Y-m-d H:i') ?: __('Not available') }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Email verified at') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ $account->email_verified_at?->translatedFormat('Y-m-d H:i') ?: __('Not available') }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Activated at') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ $account->activated_at?->translatedFormat('Y-m-d H:i') ?: __('Not available') }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Suspended at') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ $account->suspended_at?->translatedFormat('Y-m-d H:i') ?: __('Not available') }}</dd></div>
                    <div><dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Archived at') }}</dt><dd class="mt-1 text-sm text-gray-900">{{ $account->archived_at?->translatedFormat('Y-m-d H:i') ?: __('Not available') }}</dd></div>
                </dl>
            </section>

            <section class="rounded-lg bg-white p-5 shadow-sm sm:p-6" aria-labelledby="account-mosques-heading">
                <h2 id="account-mosques-heading" class="text-lg font-semibold text-gray-900">{{ __('Administered mosques') }}</h2>
                @if ($account->administeredMosques->isEmpty())
                    <p class="mt-3 text-sm text-gray-500">{{ __('No administered mosque.') }}</p>
                @else
                    <ul class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($account->administeredMosques as $mosque)
                            <li class="rounded-md border border-gray-200 px-4 py-3 text-sm text-gray-800">{{ $mosque->name }} <span class="text-gray-500">#{{ $mosque->getKey() }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
