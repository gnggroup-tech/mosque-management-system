<x-app-layout>
    <x-slot name="header">
        <div>
            <a href="{{ route('admin.accounts.show', $account) }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">{{ __('Back to account') }}</a>
            <h1 class="mt-1 text-xl font-semibold text-gray-800">{{ __('Manage role and mosques') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ __('Provision :name without changing identity or account status.', ['name' => $account->name]) }}</p>
        </div>
    </x-slot>

    <div class="py-8" x-data="{ submitting: false, role: @js(old('role', $account->roles->pluck('name')->intersect(['admin', 'user'])->first() ?? 'user')) }">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <div role="status" class="rounded-md bg-emerald-50 p-4 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div role="alert" class="rounded-md bg-red-50 p-4 text-sm text-red-700">
                    <p class="font-semibold">{{ __('The provisioning request could not be applied.') }}</p>
                    <ul class="mt-2 list-disc space-y-1 ps-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <form method="POST" action="{{ route('admin.accounts.provisioning.update', $account) }}" class="space-y-6" x-on:submit="if (! window.confirm(@js(__('This may demote the account or remove mosque access. Confirm this privileged change?')))) { $event.preventDefault(); return; } submitting = true">
                @csrf
                @method('PATCH')
                <input type="hidden" name="version" value="{{ $version }}">

                <section class="rounded-lg bg-white p-5 shadow-sm sm:p-6">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('Application role') }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ __('A local administrator also requires at least one administrator membership.') }}</p>
                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach (['user', 'admin'] as $role)
                            <label class="flex items-center gap-3 rounded-md border border-gray-200 p-4">
                                <input type="radio" name="role" value="{{ $role }}" x-model="role" required>
                                <span class="font-medium text-gray-800">{{ __($role) }}</span>
                            </label>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-lg bg-white p-5 shadow-sm sm:p-6">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('Canonical mosque memberships') }}</h2>
                    <p class="mt-1 text-sm text-gray-500">{{ __('Religious council functions are managed separately and will not change.') }}</p>
                    <div class="mt-5 space-y-4">
                        @forelse ($mosques as $mosque)
                            @php
                                $membership = $memberships->get($mosque->id);
                                $selected = $errors->any() ? in_array((string) $mosque->id, old('membership_ids', []), true) : $membership !== null;
                                $type = old("membership_types.{$mosque->id}", $membership?->membership_type?->value ?? 'member');
                                $isPrimary = $mosque->admin_id === $account->id;
                                $primarySelected = $errors->any() ? in_array((string) $mosque->id, old('primary_mosque_ids', []), true) : $isPrimary;
                            @endphp
                            <article class="rounded-md border border-gray-200 p-4">
                                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                    <label class="flex items-start gap-3">
                                        <input type="checkbox" name="membership_ids[]" value="{{ $mosque->id }}" @checked($selected) class="mt-1 rounded border-gray-300 text-indigo-600">
                                        <span><strong class="block text-gray-900">{{ $mosque->name }}</strong><span class="text-xs text-gray-500">{{ $mosque->code }} · {{ __($mosque->status) }}</span></span>
                                    </label>
                                    <select name="membership_types[{{ $mosque->id }}]" class="rounded-md border-gray-300 text-sm shadow-sm">
                                        <option value="member" @selected($type === 'member')>{{ __('Member') }}</option>
                                        <option value="administrator" @selected($type === 'administrator')>{{ __('Local administrator') }}</option>
                                    </select>
                                </div>
                                <div class="mt-3 flex flex-col gap-3 border-t border-gray-100 pt-3 sm:flex-row sm:items-center">
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="primary_mosque_ids[]" value="{{ $mosque->id }}" @checked($primarySelected) class="rounded border-gray-300 text-indigo-600">
                                        {{ __('Set as historical primary administrator') }}
                                    </label>
                                    @if ($isPrimary)
                                        <label class="text-sm text-gray-700">
                                            {{ __('Replacement if removed') }}
                                            <select name="primary_replacements[{{ $mosque->id }}]" class="ms-2 rounded-md border-gray-300 text-sm shadow-sm">
                                                <option value="">{{ __('Select an active replacement') }}</option>
                                                @foreach ($replacementCandidates->filter(fn ($candidate) => $candidate->mosqueMemberships->contains('mosque_id', $mosque->id)) as $candidate)
                                                    <option value="{{ $candidate->id }}" @selected((string) old("primary_replacements.{$mosque->id}") === (string) $candidate->id)>{{ $candidate->name }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <p class="text-sm text-gray-500">{{ __('No active mosque is available.') }}</p>
                        @endforelse
                    </div>
                </section>

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <a href="{{ route('admin.accounts.show', $account) }}" class="inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700">{{ __('Cancel') }}</a>
                    <button type="submit" x-bind:disabled="submitting" class="inline-flex justify-center rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white disabled:cursor-wait disabled:opacity-50">
                        <span x-show="! submitting">{{ __('Save provisioning') }}</span>
                        <span x-cloak x-show="submitting">{{ __('Processing…') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
