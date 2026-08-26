@props(['value'])
@if (filled($value))
    <span {{ $attributes->merge(['class' => 'break-all rounded-lg bg-slate-100 px-2 py-1 font-mono text-xs text-slate-700']) }}>{{ $value }}</span>
@else
    <span class="text-sm text-slate-400">{{ __('Not specified') }}</span>
@endif
