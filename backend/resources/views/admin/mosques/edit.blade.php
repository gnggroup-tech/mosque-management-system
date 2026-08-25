<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('admin.mosques.show', $mosque) }}" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="{{ __('Back to mosque') }}">←</a>
            <div class="min-w-0"><h1 class="truncate text-lg font-bold">{{ __('Edit mosque') }}</h1><p class="truncate text-xs text-slate-500">{{ $mosque->name }} · {{ $mosque->code }}</p></div>
        </div>
    </x-slot>

    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('admin.mosques.update', $mosque) }}" class="space-y-6" x-data="{ submitting: false, infrastructures: @js(old('infrastructures', $mosque->infrastructures ?: [''])) }" @submit="submitting = true">
            @csrf
            @method('PATCH')

            @if ($errors->any())
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert">
                    <p class="font-semibold">{{ __('Please correct the highlighted fields.') }}</p>
                    <ul class="mt-2 list-disc space-y-1 ps-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="edit-identity-heading">
                <h2 id="edit-identity-heading" class="text-lg font-bold">{{ __('Identity and location') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('Required information is marked with an asterisk.') }}</p>
                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    @foreach ([['code', __('Code'), true, 30], ['name', __('Name'), true, 255], ['region', __('Region'), true, 100], ['prefecture', __('Prefecture'), true, 100], ['commune', __('Commune'), true, 100]] as [$name, $label, $required, $max])
                        <label @class(['block', 'sm:col-span-2' => $name === 'name'])>
                            <span class="text-sm font-semibold text-slate-700">{{ $label }} @if ($required)<span aria-hidden="true" class="text-red-600">*</span>@endif</span>
                            <input name="{{ $name }}" value="{{ old($name, $mosque->{$name}) }}" maxlength="{{ $max }}" @required($required) @class(['mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500', 'border-red-400' => $errors->has($name)]) aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}">
                            @error($name)<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
                        </label>
                    @endforeach
                    <label class="block sm:col-span-2">
                        <span class="text-sm font-semibold text-slate-700">{{ __('Address') }}</span>
                        <textarea name="address" maxlength="500" rows="3" @class(['mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500', 'border-red-400' => $errors->has('address')])>{{ old('address', $mosque->address) }}</textarea>
                        @error('address')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror
                    </label>
                    <label><span class="text-sm font-semibold text-slate-700">{{ __('Latitude') }}</span><input type="number" step="0.0000001" name="latitude" value="{{ old('latitude', $mosque->latitude) }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500">@error('latitude')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror</label>
                    <label><span class="text-sm font-semibold text-slate-700">{{ __('Longitude') }}</span><input type="number" step="0.0000001" name="longitude" value="{{ old('longitude', $mosque->longitude) }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500">@error('longitude')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror</label>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="edit-contact-heading">
                <h2 id="edit-contact-heading" class="text-lg font-bold">{{ __('Contact and administration') }}</h2>
                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    <label><span class="text-sm font-semibold text-slate-700">{{ __('Phone') }}</span><input name="phone" value="{{ old('phone', $mosque->phone) }}" maxlength="30" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500">@error('phone')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror</label>
                    <label><span class="text-sm font-semibold text-slate-700">{{ __('Email') }}</span><input type="email" name="email" value="{{ old('email', $mosque->email) }}" maxlength="255" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500">@error('email')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror</label>
                    <label><span class="text-sm font-semibold text-slate-700">{{ __('Status') }}</span><select name="status" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"><option value="active" @selected(old('status', $mosque->status) === 'active')>{{ __('active') }}</option><option value="inactive" @selected(old('status', $mosque->status) === 'inactive')>{{ __('inactive') }}</option></select>@error('status')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror</label>
                    @if (Auth::user()->hasRole('superadmin'))
                        <label><span class="text-sm font-semibold text-slate-700">{{ __('Primary administrator') }}</span><select name="admin_id" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"><option value="">{{ __('Not assigned') }}</option>@foreach ($administrators as $administrator)<option value="{{ $administrator->id }}" @selected((string) old('admin_id', $mosque->admin_id) === (string) $administrator->id)>{{ $administrator->name }}</option>@endforeach</select>@error('admin_id')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror</label>
                    @endif
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="edit-infrastructure-heading">
                <div class="flex items-center justify-between gap-4"><div><h2 id="edit-infrastructure-heading" class="text-lg font-bold">{{ __('Infrastructures') }}</h2><p class="mt-1 text-sm text-slate-500">{{ __('Record only existing facilities.') }}</p></div><button type="button" @click="infrastructures.push('')" class="shrink-0 rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('Add facility') }}</button></div>
                <div class="mt-5 space-y-3">
                    <template x-for="(infrastructure, index) in infrastructures" :key="index">
                        <div class="flex gap-2"><label class="min-w-0 flex-1"><span class="sr-only">{{ __('Infrastructure') }}</span><input name="infrastructures[]" x-model="infrastructures[index]" maxlength="255" class="block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"></label><button type="button" @click="infrastructures.splice(index, 1)" class="rounded-xl border border-red-200 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50" aria-label="{{ __('Remove facility') }}">{{ __('Remove') }}</button></div>
                    </template>
                    <p x-cloak x-show="infrastructures.length === 0" class="text-sm text-slate-500">{{ __('No infrastructure recorded.') }}</p>
                    @error('infrastructures')<span class="block text-sm text-red-600">{{ $message }}</span>@enderror
                    @error('infrastructures.*')<span class="block text-sm text-red-600">{{ $message }}</span>@enderror
                </div>
            </section>

            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><a href="{{ route('admin.mosques.show', $mosque) }}" class="rounded-xl border border-slate-300 px-5 py-3 text-center text-sm font-semibold text-slate-700 hover:bg-white">{{ __('Cancel') }}</a><button type="submit" :disabled="submitting" class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 disabled:cursor-wait disabled:opacity-60"><span x-show="!submitting">{{ __('Save changes') }}</span><span x-cloak x-show="submitting">{{ __('Saving…') }}</span></button></div>
        </form>
    </div>
</x-app-layout>
