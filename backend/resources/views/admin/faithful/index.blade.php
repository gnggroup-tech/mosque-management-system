<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-lg font-bold">{{ __('Faithful') }}</h1>
                <p class="text-xs text-slate-500">{{ __('Community records within your authorized scope.') }}</p>
            </div>
            @can('faithful.manage')
                <a href="{{ route('admin.faithful.create') }}" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">{{ __('Add faithful') }}</a>
            @endcan
        </div>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
        <form method="GET" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
            <input name="search" value="{{ $filters['search'] ?? '' }}" placeholder="{{ __('Search by name or registration number') }}" class="rounded-xl border-slate-300">
            <select name="mosque_id" class="rounded-xl border-slate-300">
                <option value="">{{ __('All authorized mosques') }}</option>
                @foreach ($mosques as $mosque)
                    <option value="{{ $mosque->id }}" @selected(($filters['mosque_id'] ?? '') == $mosque->id)>{{ $mosque->name }}</option>
                @endforeach
            </select>
            <select name="status" class="rounded-xl border-slate-300">
                <option value="">{{ __('All statuses') }}</option>
                @foreach (['active', 'inactive', 'suspended', 'deceased'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ __($status) }}</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <button class="rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white">{{ __('Apply filters') }}</button>
                <a href="{{ route('admin.faithful.index') }}" class="rounded-xl border px-4 py-2 text-sm">{{ __('Reset') }}</a>
            </div>
        </form>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            @if ($records->isEmpty())
                <div class="p-12 text-center text-slate-500">{{ __('No faithful records found.') }}</div>
            @else
                <div class="hidden overflow-x-auto md:block">
                    <table class="min-w-full divide-y">
                        <thead class="bg-slate-50"><tr>
                            @foreach ([__('Registration'), __('Name'), __('Mosque'), __('Status'), __('Actions')] as $heading)
                                <th class="px-5 py-3 text-start text-xs font-semibold uppercase text-slate-500">{{ $heading }}</th>
                            @endforeach
                        </tr></thead>
                        <tbody class="divide-y">
                            @foreach ($records as $record)
                                <tr>
                                    <td class="px-5 py-4 text-sm">{{ $record->registration_number }}</td>
                                    <td class="px-5 py-4 font-semibold">{{ $record->first_name }} {{ $record->last_name }}</td>
                                    <td class="px-5 py-4 text-sm">{{ $record->mosque->name }}</td>
                                    <td class="px-5 py-4"><x-status-badge :status="$record->status" /></td>
                                    <td class="px-5 py-4"><a class="text-sm font-semibold text-emerald-700" href="{{ route('admin.faithful.show', $record) }}">{{ __('View details') }}</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="divide-y md:hidden">
                    @foreach ($records as $record)
                        <article class="p-5">
                            <div class="flex justify-between gap-3">
                                <div>
                                    <a class="font-bold text-emerald-700" href="{{ route('admin.faithful.show', $record) }}">{{ $record->first_name }} {{ $record->last_name }}</a>
                                    <p class="text-xs text-slate-500">{{ $record->registration_number }} &middot; {{ $record->mosque->name }}</p>
                                </div>
                                <x-status-badge :status="$record->status" />
                            </div>
                        </article>
                    @endforeach
                </div>
                @if ($records->hasPages())
                    <div class="border-t p-4">{{ $records->links() }}</div>
                @endif
            @endif
        </section>
    </div>
</x-app-layout>
