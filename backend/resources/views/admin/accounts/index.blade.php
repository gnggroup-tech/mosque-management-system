<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold leading-tight text-gray-800">{{ __('Account directory') }}</h1>
                <p class="mt-1 text-sm text-gray-500">{{ __('Review and manage authorized account lifecycle actions.') }}</p>
            </div>
            @can('invite', App\Models\User::class)
                <a href="{{ route('admin.accounts.invitations.create') }}" class="inline-flex items-center justify-center rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    {{ __('Invite a user') }}
                </a>
            @endcan
        </div>
    </x-slot>

    <div class="py-8" x-data="accountDirectoryPage()" x-init="init()">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            <div x-cloak x-show="message" x-text="message" role="status" class="rounded-md bg-emerald-50 p-4 text-sm font-medium text-emerald-800"></div>

            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm text-red-700" role="alert">
                    <p class="font-semibold">{{ __('The filters could not be applied.') }}</p>
                    <ul class="mt-2 list-disc space-y-1 ps-5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="rounded-lg bg-white p-4 shadow-sm sm:p-6" aria-labelledby="account-filters-heading">
                <h2 id="account-filters-heading" class="text-base font-semibold text-gray-900">{{ __('Search and filters') }}</h2>
                <form method="GET" action="{{ route('admin.accounts.index') }}" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4" x-on:submit="loading = true">
                    <label class="block sm:col-span-2">
                        <span class="text-sm font-medium text-gray-700">{{ __('Search') }}</span>
                        <input name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="{{ __('Account ID, name or email') }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">{{ __('Status') }}</span>
                        <select name="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">{{ __('All statuses') }}</option>
                            @foreach ($statuses as $status)
                                <option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ __($status->value) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">{{ __('Role') }}</span>
                        <select name="role" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">{{ __('All roles') }}</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role }}" @selected(($filters['role'] ?? '') === $role)>{{ __($role) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">{{ __('Created from') }}</span>
                        <input type="date" name="created_from" value="{{ $filters['created_from'] ?? '' }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">{{ __('Created to') }}</span>
                        <input type="date" name="created_to" value="{{ $filters['created_to'] ?? '' }}" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">{{ __('Sort by') }}</span>
                        <select name="sort" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            @foreach (['id' => __('Account ID'), 'name' => __('Name'), 'status' => __('Status'), 'created_at' => __('Created at'), 'updated_at' => __('Updated at')] as $sort => $label)
                                <option value="{{ $sort }}" @selected(($filters['sort'] ?? 'id') === $sort)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">{{ __('Direction') }}</span>
                        <select name="direction" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            <option value="asc" @selected(($filters['direction'] ?? 'asc') === 'asc')>{{ __('Ascending') }}</option>
                            <option value="desc" @selected(($filters['direction'] ?? 'asc') === 'desc')>{{ __('Descending') }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium text-gray-700">{{ __('Per page') }}</span>
                        <select name="per_page" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                            @foreach ([20, 50, 100] as $size)
                                <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 20) === $size)>{{ $size }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end lg:col-span-3">
                        <button type="submit" x-bind:disabled="loading" class="inline-flex justify-center rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 disabled:cursor-wait disabled:opacity-50">
                            <span x-show="! loading">{{ __('Apply filters') }}</span>
                            <span x-cloak x-show="loading">{{ __('Loading…') }}</span>
                        </button>
                        <a href="{{ route('admin.accounts.index') }}" class="inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Reset') }}</a>
                    </div>
                </form>
            </section>

            <section class="overflow-hidden rounded-lg bg-white shadow-sm" aria-labelledby="account-results-heading">
                <div class="border-b border-gray-200 px-4 py-4 sm:px-6">
                    <h2 id="account-results-heading" class="font-semibold text-gray-900">{{ __('Accounts') }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ trans_choice(':count account|:count accounts', $accounts->total(), ['count' => $accounts->total()]) }}</p>
                </div>

                @if ($accounts->isEmpty())
                    <div class="px-6 py-12 text-center">
                        <p class="font-medium text-gray-700">{{ __('No accounts match these filters.') }}</p>
                        <p class="mt-1 text-sm text-gray-500">{{ __('Adjust or reset the filters to continue.') }}</p>
                    </div>
                @else
                    <div class="hidden overflow-x-auto md:block">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Account') }}</th>
                                    <th class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Status') }}</th>
                                    <th class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Roles') }}</th>
                                    <th class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Created at') }}</th>
                                    <th class="px-6 py-3 text-start text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                @foreach ($accounts as $account)
                                    <tr data-testid="account-row-{{ $account->getKey() }}">
                                        <td class="whitespace-nowrap px-6 py-4">
                                            <a href="{{ route('admin.accounts.show', $account) }}" class="font-medium text-indigo-700 hover:text-indigo-900">{{ $account->name }}</a>
                                            <div class="text-xs text-gray-500">#{{ $account->getKey() }}</div>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4"><x-account-status-badge :status="$account->status" /></td>
                                        <td class="px-6 py-4 text-sm text-gray-700">{{ $account->roles->pluck('name')->map(fn ($role) => __($role))->join(', ') ?: __('No role') }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-600">{{ $account->created_at?->translatedFormat('Y-m-d') }}</td>
                                        <td class="px-6 py-4">@include('admin.accounts.partials.actions', ['account' => $account])</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="divide-y divide-gray-200 md:hidden">
                        @foreach ($accounts as $account)
                            <article class="space-y-3 p-4" data-testid="account-card-{{ $account->getKey() }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <a href="{{ route('admin.accounts.show', $account) }}" class="font-semibold text-indigo-700">{{ $account->name }}</a>
                                        <p class="text-xs text-gray-500">#{{ $account->getKey() }}</p>
                                    </div>
                                    <x-account-status-badge :status="$account->status" />
                                </div>
                                <p class="text-sm text-gray-600">{{ __('Roles') }}: {{ $account->roles->pluck('name')->map(fn ($role) => __($role))->join(', ') ?: __('No role') }}</p>
                                <a href="{{ route('admin.accounts.show', $account) }}" class="inline-flex text-sm font-semibold text-indigo-700">{{ __('View details') }}</a>
                            </article>
                        @endforeach
                    </div>
                @endif

                @if ($accounts->hasPages())
                    <div class="border-t border-gray-200 px-4 py-4 sm:px-6">{{ $accounts->links() }}</div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
