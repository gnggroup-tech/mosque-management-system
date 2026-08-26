@props(['amount', 'currency'])
@php
    $raw = (string) ($amount ?? '0');
    $negative = str_starts_with($raw, '-');
    $raw = ltrim($raw, '-');
    [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
    $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', app()->getLocale() === 'en' ? ',' : ' ', $whole);
    $fraction = $fraction === '' ? '' : rtrim(str_pad(substr($fraction, 0, 2), 2, '0'), '0');
    $separator = app()->getLocale() === 'en' ? '.' : ',';
    $formatted = ($negative ? '-' : '').$whole.($fraction !== '' ? $separator.$fraction : '');
@endphp
<span {{ $attributes->merge(['class' => 'whitespace-nowrap font-mono tabular-nums']) }}>{{ $formatted }} <span class="font-sans text-xs font-semibold">{{ $currency }}</span></span>
