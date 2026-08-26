<x-app-layout>
    <x-slot name="header"><div><h1 class="text-lg font-bold">{{ __('Waqf assets') }}</h1><p class="text-xs text-slate-500">{{ __('Assets and transactions remain balanced in the asset currency.') }}</p></div></x-slot>
    <div class="mx-auto max-w-7xl space-y-7 px-4 py-8 sm:px-6">
        <form method="GET" class="grid gap-3 rounded-2xl border bg-white p-5 shadow-sm sm:grid-cols-4">
            <select name="mosque_id" class="rounded-xl border-slate-300"><option value="">{{ __('All authorized mosques') }}</option>@foreach($mosques as $mosque)<option value="{{ $mosque->id }}" @selected(($filters['mosque_id']??'')==$mosque->id)>{{ $mosque->name }}</option>@endforeach</select>
            <select name="type" class="rounded-xl border-slate-300"><option value="">{{ __('All types') }}</option>@foreach(['land','building','shop','farm','cash','equipment','other'] as $type)<option value="{{ $type }}" @selected(($filters['type']??'')===$type)>{{ __($type) }}</option>@endforeach</select>
            <select name="status" class="rounded-xl border-slate-300"><option value="">{{ __('All statuses') }}</option>@foreach(['active','inactive','disposed'] as $status)<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ __($status) }}</option>@endforeach</select>
            <button class="rounded-xl bg-slate-950 px-4 py-2 font-semibold text-white">{{ __('Apply filters') }}</button>
        </form>

        @can('waqf.manage')
            <details class="rounded-2xl border bg-white p-6 shadow-sm"><summary class="cursor-pointer font-bold">{{ __('Record Waqf asset') }}</summary><form method="POST" action="{{ route('admin.waqf.assets.store') }}" class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">@csrf
                <select required name="mosque_id" class="rounded-xl border-slate-300"><option value="">{{ __('Choose mosque') }}</option>@foreach($mosques as $mosque)<option value="{{ $mosque->id }}">{{ $mosque->name }}</option>@endforeach</select>
                <input required name="name" placeholder="{{ __('Asset name') }}" class="rounded-xl border-slate-300">
                <select required name="type" class="rounded-xl border-slate-300">@foreach(['land','building','shop','farm','cash','equipment','other'] as $type)<option value="{{ $type }}">{{ __($type) }}</option>@endforeach</select>
                <div class="flex gap-2"><input name="estimated_value" inputmode="decimal" placeholder="{{ __('Estimated value') }}" class="min-w-0 flex-1 rounded-xl border-slate-300"><select name="currency" class="rounded-xl border-slate-300">@foreach(['GNF','USD','EUR'] as $currency)<option value="{{ $currency }}">{{ $currency }}</option>@endforeach</select></div>
                <input required type="date" name="dedicated_at" value="{{ today()->toDateString() }}" class="rounded-xl border-slate-300">
                <input name="deed_reference" placeholder="{{ __('Deed reference (text only)') }}" class="rounded-xl border-slate-300">
                <input name="address" placeholder="{{ __('Address') }}" class="rounded-xl border-slate-300">
                <input name="description" placeholder="{{ __('Description') }}" class="rounded-xl border-slate-300">
                <button class="rounded-xl bg-emerald-600 px-4 py-2 font-semibold text-white lg:col-span-4">{{ __('Save') }}</button>
            </form></details>
        @endcan

        <section class="space-y-5">
            @forelse($assets as $asset)
                <article class="overflow-hidden rounded-2xl border bg-white shadow-sm">
                    <div class="grid gap-5 p-6 lg:grid-cols-4">
                        <div class="lg:col-span-2"><div class="flex items-start justify-between gap-3"><div><h2 class="text-lg font-bold">{{ $asset->name }}</h2><p class="text-sm text-slate-500">{{ $asset->registration_number }} · {{ $asset->mosque->name }} · {{ __($asset->type) }}</p></div><x-status-badge :status="$asset->status" /></div><div class="mt-4 flex flex-wrap gap-3"><span>{{ __('Estimated value') }}: <x-money :amount="$asset->estimated_value ?? '0'" :currency="$asset->currency" /></span><span>{{ __('Deed reference') }}: <x-legacy-reference :value="$asset->deed_reference" /></span></div></div>
                        <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm text-emerald-800">{{ __('Validated revenues') }}</p><p class="mt-2 text-lg font-bold"><x-money :amount="$asset->validated_revenue ?? '0'" :currency="$asset->currency" /></p></div>
                        <div class="rounded-xl bg-slate-950 p-4 text-white"><p class="text-sm text-slate-300">{{ __('Waqf balance') }}</p><p class="mt-2 text-lg font-bold"><x-money :amount="$asset->validated_balance" :currency="$asset->currency" /></p></div>
                    </div>
                    @can('waqf.manage')
                        <div x-data="{ form: null }" class="border-t bg-slate-50 p-5"><div class="flex gap-2"><button type="button" @click="form=form==='revenue'?null:'revenue'" class="rounded-xl border bg-white px-4 py-2 text-sm font-semibold">{{ __('Record revenue') }}</button><button type="button" @click="form=form==='expense'?null:'expense'" class="rounded-xl border bg-white px-4 py-2 text-sm font-semibold">{{ __('Record expense') }}</button></div>
                            <form x-show="form==='revenue'" method="POST" action="{{ route('admin.waqf.revenues.store') }}" class="mt-4 grid gap-3 sm:grid-cols-4">@csrf<input type="hidden" name="waqf_asset_id" value="{{ $asset->id }}"><input required name="source" placeholder="{{ __('Source') }}" class="rounded-xl border-slate-300"><input required name="amount" inputmode="decimal" placeholder="{{ __('Amount') }}" class="rounded-xl border-slate-300"><input required type="date" name="received_at" value="{{ today()->toDateString() }}" class="rounded-xl border-slate-300"><select required name="payment_method" class="rounded-xl border-slate-300">@foreach(['cash','bank_transfer','mobile_money','cheque','card','other'] as $method)<option value="{{ $method }}">{{ __($method) }}</option>@endforeach</select><button class="rounded-xl bg-emerald-600 px-4 py-2 font-semibold text-white sm:col-span-4">{{ __('Save') }}</button></form>
                            <form x-show="form==='expense'" method="POST" action="{{ route('admin.waqf.expenses.store') }}" class="mt-4 grid gap-3 sm:grid-cols-4">@csrf<input type="hidden" name="waqf_asset_id" value="{{ $asset->id }}"><select required name="category" class="rounded-xl border-slate-300">@foreach(['maintenance','repair','tax','management','beneficiary_support','other'] as $category)<option value="{{ $category }}">{{ __($category) }}</option>@endforeach</select><input required name="amount" inputmode="decimal" placeholder="{{ __('Amount') }}" class="rounded-xl border-slate-300"><input required type="date" name="spent_at" value="{{ today()->toDateString() }}" class="rounded-xl border-slate-300"><input required name="purpose" placeholder="{{ __('Purpose') }}" class="rounded-xl border-slate-300"><input name="supporting_document" placeholder="{{ __('Supporting document (legacy reference)') }}" class="rounded-xl border-slate-300 sm:col-span-4"><button class="rounded-xl bg-emerald-600 px-4 py-2 font-semibold text-white sm:col-span-4">{{ __('Save') }}</button></form>
                        </div>
                    @endcan
                    <div class="grid border-t lg:grid-cols-2"><div class="divide-y border-e"><h3 class="p-4 font-bold">{{ __('Revenues') }}</h3>@forelse($asset->revenues as $revenue)<div class="flex items-center justify-between gap-3 p-4"><div><strong>{{ $revenue->receipt_number }}</strong><p class="text-sm text-slate-500">{{ $revenue->source }}</p></div><div class="text-end"><x-money :amount="$revenue->amount" :currency="$revenue->currency" /><x-status-badge :status="$revenue->status" />@if(Auth::user()->can('waqf.manage') && $revenue->status === 'pending')<form method="POST" action="{{ route('admin.waqf.revenues.validate',$revenue) }}">@csrf<button class="text-xs font-semibold text-emerald-700">{{ __('Validate') }}</button></form>@endif</div></div>@empty<p class="p-5 text-sm text-slate-500">{{ __('No revenues.') }}</p>@endforelse</div>
                    <div class="divide-y"><h3 class="p-4 font-bold">{{ __('Expenses') }}</h3>@forelse($asset->expenses as $expense)<div class="space-y-2 p-4"><div class="flex items-center justify-between gap-3"><div><strong>{{ $expense->reference_number }}</strong><p class="text-sm text-slate-500">{{ __($expense->category) }}</p></div><div class="text-end"><x-money :amount="$expense->amount" :currency="$expense->currency" /><x-status-badge :status="$expense->status" /></div></div><x-legacy-reference :value="$expense->supporting_document" />@if(Auth::user()->can('waqf.manage') && $expense->status === 'pending')<form method="POST" action="{{ route('admin.waqf.expenses.validate',$expense) }}" onsubmit="return confirm('{{ __('Validate this Waqf expense?') }}')">@csrf<button class="text-xs font-semibold text-emerald-700">{{ __('Validate') }}</button></form>@endif</div>@empty<p class="p-5 text-sm text-slate-500">{{ __('No expenses.') }}</p>@endforelse</div></div>
                </article>
            @empty
                <div class="rounded-2xl border bg-white p-12 text-center text-slate-500">{{ __('No Waqf assets found.') }}</div>
            @endforelse
        </section>
        @if($assets->hasPages())<div>{{ $assets->links() }}</div>@endif
    </div>
</x-app-layout>
