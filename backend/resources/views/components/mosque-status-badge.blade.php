@props(['status'])
<span @class(['inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold','bg-emerald-100 text-emerald-800' => $status === 'active','bg-slate-200 text-slate-700' => $status !== 'active'])>{{ __($status) }}</span>
