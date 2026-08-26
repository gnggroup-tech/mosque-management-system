@props(['status'])
@php
    $value = $status instanceof BackedEnum ? $status->value : (string) $status;
    $tone = match ($value) {
        'active', 'published', 'completed', 'present', 'sent', 'approved' => 'bg-emerald-100 text-emerald-800',
        'draft', 'pending', 'scheduled', 'normal' => 'bg-slate-100 text-slate-700',
        'convened', 'queued', 'important', 'excused' => 'bg-sky-100 text-sky-800',
        'urgent', 'failed', 'cancelled', 'suspended', 'rejected', 'absent' => 'bg-rose-100 text-rose-800',
        default => 'bg-amber-100 text-amber-800',
    };
@endphp
<span {{ $attributes->class("inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {$tone}") }}>{{ __($value) }}</span>
