<x-app-layout>
    <x-slot name="header"><div><h1 class="text-lg font-bold">{{ __('Finances') }}</h1><p class="text-xs text-slate-500">{{ __('Validated financial flows, always separated by currency.') }}</p></div></x-slot>
    <div class="mx-auto max-w-7xl space-y-7 px-4 py-8 sm:px-6">
        <form method="GET" class="grid gap-3 rounded-2xl border bg-white p-5 shadow-sm sm:grid-cols-2 lg:grid-cols-5">
            <select name="mosque_id" class="rounded-xl border-slate-300"><option value="">{{ __('All authorized mosques') }}</option>@foreach($mosques as $mosque)<option value="{{ $mosque->id }}" @selected(($filters['mosque_id']??'')==$mosque->id)>{{ $mosque->name }}</option>@endforeach</select>
            <select name="currency" class="rounded-xl border-slate-300">@foreach(['GNF','USD','EUR'] as $currency)<option value="{{ $currency }}" @selected(($filters['currency']??'GNF')===$currency)>{{ $currency }}</option>@endforeach</select>
            <input type="date" name="from" value="{{ $filters['from']??'' }}" class="rounded-xl border-slate-300" aria-label="{{ __('From') }}">
            <input type="date" name="to" value="{{ $filters['to']??'' }}" class="rounded-xl border-slate-300" aria-label="{{ __('To') }}">
            <button class="rounded-xl bg-slate-950 px-4 py-2 font-semibold text-white">{{ __('Apply filters') }}</button>
        </form>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach(['donations','subsidies','waqf','zakat'] as $resource)<article class="rounded-2xl border bg-white p-5 shadow-sm"><p class="text-sm text-slate-500">{{ __(Illuminate\Support\Str::headline($resource)) }}</p><p class="mt-2 text-xl font-bold"><x-money :amount="$summary['resources'][$resource]" :currency="$summary['currency']" /></p></article>@endforeach
            <article class="rounded-2xl bg-emerald-950 p-5 text-white sm:col-span-2"><p class="text-sm text-emerald-200">{{ __('Total resources') }}</p><p class="mt-2 text-2xl font-bold"><x-money :amount="$summary['total_resources']" :currency="$summary['currency']" /></p></article>
            <article class="rounded-2xl bg-rose-950 p-5 text-white"><p class="text-sm text-rose-200">{{ __('Validated expenses') }}</p><p class="mt-2 text-2xl font-bold"><x-money :amount="$summary['total_expenses']" :currency="$summary['currency']" /></p></article>
            <article class="rounded-2xl bg-slate-950 p-5 text-white"><p class="text-sm text-slate-300">{{ __('Balance') }}</p><p class="mt-2 text-2xl font-bold"><x-money :amount="$summary['balance']" :currency="$summary['currency']" /></p></article>
        </section>

        @can('finances.manage')
            <section x-data="{ form: 'expense' }" class="rounded-2xl border bg-white p-6 shadow-sm">
                <div class="mb-5 flex gap-2"><button type="button" @click="form='expense'" :class="form==='expense'?'bg-slate-950 text-white':'bg-slate-100'" class="rounded-xl px-4 py-2 text-sm font-semibold">{{ __('Record expense') }}</button><button type="button" @click="form='subsidy'" :class="form==='subsidy'?'bg-slate-950 text-white':'bg-slate-100'" class="rounded-xl px-4 py-2 text-sm font-semibold">{{ __('Record subsidy') }}</button></div>
                <form x-show="form==='expense'" method="POST" action="{{ route('admin.finances.expenses.store') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">@csrf
                    <select required name="mosque_id" class="rounded-xl border-slate-300"><option value="">{{ __('Choose mosque') }}</option>@foreach($mosques as $mosque)<option value="{{ $mosque->id }}">{{ $mosque->name }}</option>@endforeach</select>
                    <select required name="category" class="rounded-xl border-slate-300">@foreach(['utilities','maintenance','salary','education','social','administration','equipment','other'] as $category)<option value="{{ $category }}">{{ __($category) }}</option>@endforeach</select>
                    <input required name="amount" inputmode="decimal" placeholder="{{ __('Amount') }}" class="rounded-xl border-slate-300">
                    <select name="currency" class="rounded-xl border-slate-300">@foreach(['GNF','USD','EUR'] as $currency)<option value="{{ $currency }}">{{ $currency }}</option>@endforeach</select>
                    <input required type="date" name="spent_at" value="{{ today()->toDateString() }}" class="rounded-xl border-slate-300">
                    <input required name="purpose" placeholder="{{ __('Purpose') }}" class="rounded-xl border-slate-300">
                    <input name="invoice_number" placeholder="{{ __('Invoice number (legacy reference)') }}" class="rounded-xl border-slate-300">
                    <input required name="supporting_document" placeholder="{{ __('Supporting document (legacy reference)') }}" class="rounded-xl border-slate-300">
                    <button class="rounded-xl bg-emerald-600 px-4 py-2 font-semibold text-white lg:col-span-4">{{ __('Save') }}</button>
                </form>
                <form x-show="form==='subsidy'" method="POST" action="{{ route('admin.finances.subsidies.store') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">@csrf
                    <select required name="mosque_id" class="rounded-xl border-slate-300"><option value="">{{ __('Choose mosque') }}</option>@foreach($mosques as $mosque)<option value="{{ $mosque->id }}">{{ $mosque->name }}</option>@endforeach</select>
                    <input required name="source" placeholder="{{ __('Source') }}" class="rounded-xl border-slate-300">
                    <input required name="amount" inputmode="decimal" placeholder="{{ __('Amount') }}" class="rounded-xl border-slate-300">
                    <select name="currency" class="rounded-xl border-slate-300">@foreach(['GNF','USD','EUR'] as $currency)<option value="{{ $currency }}">{{ $currency }}</option>@endforeach</select>
                    <input required type="date" name="received_at" value="{{ today()->toDateString() }}" class="rounded-xl border-slate-300">
                    <input name="purpose" placeholder="{{ __('Purpose') }}" class="rounded-xl border-slate-300">
                    <input name="supporting_document" placeholder="{{ __('Supporting document (legacy reference)') }}" class="rounded-xl border-slate-300 lg:col-span-2">
                    <button class="rounded-xl bg-emerald-600 px-4 py-2 font-semibold text-white lg:col-span-4">{{ __('Save') }}</button>
                </form>
            </section>
        @endcan

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="overflow-hidden rounded-2xl border bg-white shadow-sm"><div class="border-b p-5"><h2 class="font-bold">{{ __('Subsidies') }}</h2></div><div class="divide-y">@forelse($subsidies as $subsidy)<article class="space-y-2 p-5"><div class="flex justify-between gap-3"><div><strong>{{ $subsidy->reference_number }}</strong><p class="text-sm text-slate-500">{{ $subsidy->mosque->name }} · {{ $subsidy->source }}</p></div><x-status-badge :status="$subsidy->status" /></div><div class="flex items-center justify-between"><x-money :amount="$subsidy->amount" :currency="$subsidy->currency" /><x-legacy-reference :value="$subsidy->supporting_document" /></div>@if(Auth::user()->can('finances.manage') && $subsidy->status === 'pending')<form method="POST" action="{{ route('admin.finances.subsidies.validate',$subsidy) }}" onsubmit="return confirm('{{ __('Validate this subsidy?') }}')">@csrf<button class="text-sm font-semibold text-emerald-700">{{ __('Validate') }}</button></form>@endif</article>@empty<p class="p-8 text-center text-slate-500">{{ __('No subsidies in this selection.') }}</p>@endforelse</div></section>
            <section class="overflow-hidden rounded-2xl border bg-white shadow-sm"><div class="border-b p-5"><h2 class="font-bold">{{ __('Expenses') }}</h2></div><div class="divide-y">@forelse($expenses as $expense)<article class="space-y-2 p-5"><div class="flex justify-between gap-3"><div><strong>{{ $expense->reference_number }}</strong><p class="text-sm text-slate-500">{{ $expense->mosque->name }} · {{ __($expense->category) }}</p></div><x-status-badge :status="$expense->status" /></div><div class="flex items-center justify-between"><x-money :amount="$expense->amount" :currency="$expense->currency" /><div class="flex gap-2"><x-legacy-reference :value="$expense->invoice_number" /><x-legacy-reference :value="$expense->supporting_document" /></div></div>@if(Auth::user()->can('finances.manage') && $expense->status === 'pending')<form method="POST" action="{{ route('admin.finances.expenses.validate',$expense) }}" onsubmit="return confirm('{{ __('Validate this expense?') }}')">@csrf<button class="text-sm font-semibold text-emerald-700">{{ __('Validate') }}</button></form>@endif</article>@empty<p class="p-8 text-center text-slate-500">{{ __('No expenses in this selection.') }}</p>@endforelse</div></section>
        </div>
    </div>
</x-app-layout>
