<x-guest-layout>
    <div x-data="{ submitting: false }">
        <h1 class="text-xl font-semibold text-gray-900">{{ __('Accept your invitation') }}</h1>
        <p class="mt-2 text-sm text-gray-600">{{ __('Choose a secure password to verify your email address. Your account will still require administrative approval.') }}</p>

        @if ($errors->has('invitation'))
            <p role="alert" class="mt-4 rounded-md bg-red-50 p-3 text-sm text-red-700">{{ $errors->first('invitation') }}</p>
        @endif

        <form method="POST" action="{{ route('invitations.update', $token) }}" class="mt-6 space-y-5" x-on:submit="submitting = true">
            @csrf
            @method('PATCH')

            <div>
                <x-input-label for="password" :value="__('Password')" />
                <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="password_confirmation" :value="__('Confirm Password')" />
                <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" required autocomplete="new-password" />
            </div>

            <button type="submit" x-bind:disabled="submitting" class="inline-flex w-full justify-center rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-600 disabled:cursor-wait disabled:opacity-50">
                <span x-show="! submitting">{{ __('Accept invitation') }}</span>
                <span x-cloak x-show="submitting">{{ __('Processing…') }}</span>
            </button>
        </form>
    </div>
</x-guest-layout>
