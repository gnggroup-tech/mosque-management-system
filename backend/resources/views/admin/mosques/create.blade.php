<x-app-layout>
    <x-slot name="header"><div class="flex items-center gap-3"><a href="{{ route('admin.mosques.index') }}" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" aria-label="{{ __('Back to mosques') }}">←</a><div><h1 class="text-lg font-bold">{{ __('Add mosque') }}</h1><p class="text-xs text-slate-500">{{ __('Create a mosque and optionally assign its primary administrator.') }}</p></div></div></x-slot>
    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('admin.mosques.store') }}" class="space-y-6" x-data="{ submitting: false }" @submit="submitting = true">
            @csrf
            @if($errors->any())<div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700" role="alert"><p class="font-semibold">{{ __('Please correct the highlighted fields.') }}</p><ul class="mt-2 list-disc ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="identity-heading">
                <h2 id="identity-heading" class="text-lg font-bold">{{ __('Identity and location') }}</h2><p class="mt-1 text-sm text-slate-500">{{ __('Required information is marked with an asterisk.') }}</p>
                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    @foreach ([['code', __('Code'), true, 30],['name', __('Name'), true, 255],['region', __('Region'), true, 100],['prefecture', __('Prefecture'), true, 100],['commune', __('Commune'), true, 100],['address', __('Address'), false, 500]] as [$name, $label, $required, $max])
                        <label @class(['block','sm:col-span-2' => in_array($name, ['name','address'])])><span class="text-sm font-semibold text-slate-700">{{ $label }} @if($required)<span aria-hidden="true" class="text-red-600">*</span>@endif</span><input name="{{ $name }}" value="{{ old($name) }}" maxlength="{{ $max }}" @required($required) @class(['mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500','border-red-400' => $errors->has($name)]) aria-invalid="{{ $errors->has($name) ? 'true' : 'false' }}">@error($name)<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror</label>
                    @endforeach
                </div>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="contact-heading">
                <h2 id="contact-heading" class="text-lg font-bold">{{ __('Contact and administration') }}</h2>
                <div class="mt-6 grid gap-5 sm:grid-cols-2">
                    <label><span class="text-sm font-semibold text-slate-700">{{ __('Phone') }}</span><input name="phone" value="{{ old('phone') }}" maxlength="30" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500">@error('phone')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror</label>
                    <label><span class="text-sm font-semibold text-slate-700">{{ __('Email') }}</span><input type="email" name="email" value="{{ old('email') }}" maxlength="255" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500">@error('email')<span class="mt-1 block text-sm text-red-600">{{ $message }}</span>@enderror</label>
                    <label><span class="text-sm font-semibold text-slate-700">{{ __('Status') }}</span><select name="status" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"><option value="active" @selected(old('status','active') === 'active')>{{ __('active') }}</option><option value="inactive" @selected(old('status') === 'inactive')>{{ __('inactive') }}</option></select></label>
                    <label><span class="text-sm font-semibold text-slate-700">{{ __('Primary administrator') }}</span><select name="admin_id" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-500 focus:ring-emerald-500"><option value="">{{ __('Assign later') }}</option>@foreach($administrators as $administrator)<option value="{{ $administrator->id }}" @selected((string) old('admin_id') === (string) $administrator->id)>{{ $administrator->name }}</option>@endforeach</select></label>
                </div>
            </section>
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"><a href="{{ route('admin.mosques.index') }}" class="rounded-xl border border-slate-300 px-5 py-3 text-center text-sm font-semibold text-slate-700 hover:bg-white">{{ __('Cancel') }}</a><button type="submit" :disabled="submitting" class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-500 disabled:cursor-wait disabled:opacity-60"><span x-show="!submitting">{{ __('Create mosque') }}</span><span x-cloak x-show="submitting">{{ __('Creating…') }}</span></button></div>
        </form>
    </div>
</x-app-layout>
