@props(['status'])

@php
    $value = $status instanceof App\Enums\AccountStatus ? $status->value : $status;
    $classes = match ($value) {
        'active' => 'bg-emerald-100 text-emerald-800',
        'pending_email', 'pending_approval' => 'bg-amber-100 text-amber-800',
        'suspended' => 'bg-orange-100 text-orange-800',
        'archived' => 'bg-gray-200 text-gray-700',
        default => 'bg-gray-100 text-gray-700',
    };
@endphp

<span {{ $attributes->class(["inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {$classes}"]) }}>
    {{ __($value) }}
</span>
