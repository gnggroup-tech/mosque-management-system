<x-app-layout>
    <x-slot name="header"><div><h1 class="text-lg font-bold">{{ __('Reports and exports') }}</h1><p class="text-xs text-slate-500">{{ __('Download authorized data as CSV or PDF without exposing server paths.') }}</p></div></x-slot>
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6">
        <div class="flex flex-col justify-between gap-4 rounded-2xl bg-slate-950 p-6 text-white sm:flex-row sm:items-center"><div><h2 class="font-bold">{{ __('Financial summary') }}</h2><p class="mt-1 text-sm text-slate-300">{{ __('Review balances by mosque and currency before exporting.') }}</p></div>@can('finances.view')<a href="{{ route('admin.finances.report') }}" class="rounded-xl bg-emerald-500 px-4 py-2 text-center font-semibold text-slate-950">{{ __('Open financial summary') }}</a>@endcan</div>
        <section class="rounded-2xl border bg-white p-6 shadow-sm">
            @if($errors->any())<div class="mb-6 rounded-xl bg-rose-50 p-4 text-rose-800" role="alert"><ul class="list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <form method="GET" action="{{ route('admin.reports.export') }}" class="grid gap-5 md:grid-cols-2">
                <label><span class="text-sm font-medium">{{ __('Report type') }}</span><select name="type" required class="mt-1 w-full rounded-xl border-slate-300">@foreach($types as $type)<option value="{{ $type }}">{{ __(Illuminate\Support\Str::headline($type)) }}</option>@endforeach</select></label>
                <label><span class="text-sm font-medium">{{ __('Mosque') }}</span><select name="mosque_id" class="mt-1 w-full rounded-xl border-slate-300"><option value="">{{ __('All authorized mosques') }}</option>@foreach($mosques as $mosque)<option value="{{ $mosque->id }}">{{ $mosque->code }} — {{ $mosque->name }}</option>@endforeach</select></label>
                <label><span class="text-sm font-medium">{{ __('Status') }}</span><select name="status" class="mt-1 w-full rounded-xl border-slate-300"><option value="">{{ __('All statuses') }}</option>@foreach(['pending','validated','rejected','active','inactive','disposed'] as $status)<option value="{{ $status }}">{{ __($status) }}</option>@endforeach</select></label>
                <label><span class="text-sm font-medium">{{ __('Currency') }}</span><select name="currency" class="mt-1 w-full rounded-xl border-slate-300"><option value="">{{ __('All currencies') }}</option>@foreach(['GNF','USD','EUR'] as $currency)<option value="{{ $currency }}">{{ $currency }}</option>@endforeach</select></label>
                <label><span class="text-sm font-medium">{{ __('From') }}</span><input type="date" name="from" class="mt-1 w-full rounded-xl border-slate-300"></label>
                <label><span class="text-sm font-medium">{{ __('To') }}</span><input type="date" name="to" class="mt-1 w-full rounded-xl border-slate-300"></label>
                <div class="flex flex-col gap-3 md:col-span-2 sm:flex-row"><button name="format" value="csv" class="rounded-xl bg-emerald-600 px-5 py-3 font-semibold text-white">{{ __('Download CSV') }}</button><button name="format" value="pdf" class="rounded-xl bg-rose-600 px-5 py-3 font-semibold text-white">{{ __('Download PDF') }}</button></div>
            </form>
            <p class="mt-5 text-sm text-slate-500">{{ __('Exports use the selected scope and never reveal a storage or server path.') }}</p>
        </section>
    </div>
</x-app-layout>
