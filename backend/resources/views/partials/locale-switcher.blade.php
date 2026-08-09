<form method="POST" action="{{ route('locale.update') }}" aria-label="{{ __('Language') }}" class="flex gap-2 p-2 justify-end" dir="ltr">
    @csrf
    @foreach (['fr' => 'FR', 'en' => 'EN', 'ar' => 'AR'] as $locale => $label)
        <button
            type="submit"
            name="locale"
            value="{{ $locale }}"
            lang="{{ $locale }}"
            aria-pressed="{{ app()->getLocale() === $locale ? 'true' : 'false' }}"
            class="rounded px-2 py-1 text-xs {{ app()->getLocale() === $locale ? 'bg-gray-900 text-white' : 'bg-white text-gray-700' }}"
        >{{ $label }}</button>
    @endforeach
</form>
