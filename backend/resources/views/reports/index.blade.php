<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ __('Data exports') }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <h3 class="text-lg font-medium text-gray-900 mb-6">{{ __('Create report') }}</h3>

                @if ($errors->any())
                    <div class="mb-6 rounded bg-red-50 p-4 text-red-700" role="alert">
                        <ul class="list-disc ps-5">
                            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                <form method="GET" action="{{ route('admin.reports.export') }}" class="grid grid-cols-1 gap-5 md:grid-cols-2">
                    <label class="block">
                        <span class="text-sm text-gray-700">{{ __('Report type') }}</span>
                        <select name="type" required class="mt-1 block w-full rounded-md border-gray-300">
                            @foreach ($types as $type)
                                <option value="{{ $type }}">{{ __(Illuminate\Support\Str::headline($type)) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-sm text-gray-700">{{ __('Format') }}</span>
                        <select name="format" required class="mt-1 block w-full rounded-md border-gray-300">
                            <option value="csv">CSV</option>
                            <option value="pdf">PDF</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-sm text-gray-700">{{ __('Mosque') }}</span>
                        <select name="mosque_id" class="mt-1 block w-full rounded-md border-gray-300">
                            <option value="">{{ __('All authorized mosques') }}</option>
                            @foreach ($mosques as $mosque)
                                <option value="{{ $mosque->id }}">{{ $mosque->code }} — {{ $mosque->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-sm text-gray-700">{{ __('Status') }}</span>
                        <select name="status" class="mt-1 block w-full rounded-md border-gray-300">
                            <option value="">{{ __('All statuses') }}</option>
                            @foreach (['pending', 'validated', 'rejected', 'active', 'inactive', 'disposed'] as $status)
                                <option value="{{ $status }}">{{ __($status) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-sm text-gray-700">{{ __('From') }}</span>
                        <input type="date" name="from" class="mt-1 block w-full rounded-md border-gray-300">
                    </label>
                    <label class="block">
                        <span class="text-sm text-gray-700">{{ __('To') }}</span>
                        <input type="date" name="to" class="mt-1 block w-full rounded-md border-gray-300">
                    </label>
                    <label class="block">
                        <span class="text-sm text-gray-700">{{ __('Currency') }}</span>
                        <select name="currency" class="mt-1 block w-full rounded-md border-gray-300">
                            <option value="">{{ __('All currencies') }}</option>
                            @foreach (['GNF', 'USD', 'EUR'] as $currency)<option value="{{ $currency }}">{{ $currency }}</option>@endforeach
                        </select>
                    </label>
                    <div class="flex items-end">
                        <button class="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">
                            {{ __('Download') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
