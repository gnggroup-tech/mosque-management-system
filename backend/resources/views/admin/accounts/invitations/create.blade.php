<x-app-layout>
    <x-slot name="header">
        <div>
            <a href="{{ route('admin.accounts.index') }}" class="text-sm font-medium text-indigo-700 hover:text-indigo-900">{{ __('Back to accounts') }}</a>
            <h1 class="mt-1 text-xl font-semibold text-gray-800">{{ __('Invite a user') }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ __('Create a restricted account and send a one-time invitation.') }}</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
            @if (session('status'))
                <div role="status" class="mb-6 rounded-md bg-emerald-50 p-4 text-sm font-medium text-emerald-800">{{ session('status') }}</div>
            @endif

            <section class="rounded-lg bg-white p-5 shadow-sm sm:p-6">
                <form method="POST" action="{{ route('admin.accounts.invitations.store') }}" class="space-y-5" x-data="{ submitting: false }" x-on:submit="submitting = true">
                    @csrf

                    <div>
                        <x-input-label for="name" :value="__('Name')" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name')" required autofocus maxlength="255" autocomplete="name" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="email" :value="__('Email')" />
                        <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email')" required maxlength="255" autocomplete="email" />
                        <x-input-error :messages="$errors->get('email')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="locale" :value="__('Language')" />
                        <select id="locale" name="locale" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($locales as $locale)
                                <option value="{{ $locale }}" @selected(old('locale', app()->getLocale()) === $locale)>{{ __(['fr' => 'French', 'en' => 'English', 'ar' => 'Arabic'][$locale]) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('locale')" class="mt-2" />
                    </div>

                    <p class="rounded-md bg-blue-50 p-3 text-sm text-blue-800">{{ __('The invited account stays blocked until email acceptance and administrative approval.') }}</p>

                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <a href="{{ route('admin.accounts.index') }}" class="inline-flex justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">{{ __('Cancel') }}</a>
                        <button type="submit" x-bind:disabled="submitting" class="inline-flex justify-center rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-600 disabled:cursor-wait disabled:opacity-50">
                            <span x-show="! submitting">{{ __('Send invitation') }}</span>
                            <span x-cloak x-show="submitting">{{ __('Sending…') }}</span>
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</x-app-layout>
